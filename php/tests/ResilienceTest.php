<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Digifact\Fel\DigifactApiException;
use Digifact\Fel\DigifactAuthException;
use Digifact\Fel\DigifactClient;
use Digifact\Fel\DigifactDuplicateReferenceException;
use Digifact\Fel\DigifactException;
use Digifact\Fel\DteBuilder;

/**
 * Unit tests (no credentials) for internal references, token renewal and error classification.
 */
class ResilienceTest extends TestCase
{
    private const REFERENCE = '0F8FAD5B-D9CB-469F-A165-70867728950E';
    private const SELLER_NAME = 'EMPRESA DEMO, SOCIEDAD ANONIMA';
    private const SELLER_ADDRESS = 'CIUDAD';
    private const ITEMS = [['description' => 'Servicio', 'qty' => 1, 'price' => 100]];
    private const ORIGIN = [
        'auth_number' => 'AAAABBBB-1111-2222-3333-444455556666',
        'date'        => '2026-10-01',
        'series'      => 'AAAABBBB',
        'number'      => '1',
    ];
    private const CERTIFIED = [
        'code'              => 1,
        'authNumber'        => 'CCCCDDDD-1111-2222-3333-444455556666',
        'batch'             => 'CCCCDDDD',
        'serial'            => 123,
        'issuedTimeStamp'   => '2026-11-03T10:00:00',
        'enrolledTimeStamp' => '2026-11-03T10:00:02',
        'additionalInfo'    => ['acuseReciboSAT' => 'ACEPTADO'],
    ];
    private const CERTIFY = '/v2/transform/nuc_json';
    private const LOGIN = '/login/get_token';

    private static function response(int $status, array $body): array
    {
        return [$status, json_encode($body)];
    }

    private function aClient(
        FakeTransport $transport,
        string $password = 'secreto',
        string $token = 'token-vigente'
    ): DigifactClient {
        return new DigifactClient([
            'taxid'          => '12345678',
            'username'       => 'FELUSER',
            'password'       => $password,
            'token'          => $token,
            'seller_name'    => self::SELLER_NAME,
            'seller_address' => self::SELLER_ADDRESS,
            'transport'      => $transport,
        ]);
    }

    private function certifying(array $body = self::CERTIFIED, int $status = 200): FakeTransport
    {
        return (new FakeTransport())->on('POST', self::CERTIFY, self::response($status, $body));
    }

    private static function sentPayload(FakeTransport $transport): array
    {
        $calls = $transport->callsTo('POST', self::CERTIFY);
        return json_decode(end($calls)['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    private static function adendaOf(array $payload): array
    {
        return array_values(array_filter(
            $payload['AdditionalDocumentInfo']['AdditionalInfo'],
            fn (array $entry): bool => $entry['Type'] === 'ADENDA'
        ));
    }

    private static function referenceFlags(array $adenda): array
    {
        $flags = array_filter(
            $adenda['AditionalInfo'],
            fn (array $info): bool => $info['Name'] === 'VALIDAR_REFERENCIA_INTERNA'
        );
        return array_column($flags, 'Value');
    }

    private function rejectionOf(FakeTransport $transport, array $opts = []): DigifactException
    {
        try {
            $this->aClient($transport)->invoice('CF', self::ITEMS, $opts);
        } catch (DigifactException $e) {
            return $e;
        }
        $this->fail('The certification was expected to fail');
    }

    // ── Internal reference ───────────────────────────────────────────────────

    public function testInvoiceCarriesTheReferenceWithValidation(): void
    {
        $transport = $this->certifying();

        $this->aClient($transport)->invoice('CF', self::ITEMS, [
            'internal_reference'          => self::REFERENCE,
            'validate_internal_reference' => true,
        ]);

        [$adenda] = self::adendaOf(self::sentPayload($transport));
        $this->assertSame(self::REFERENCE, $adenda['Code']);
        $this->assertSame(['VALIDAR'], self::referenceFlags($adenda));
        $this->assertSame('VALIDAR', $adenda['AditionalInfo'][0]['Value']);
        $this->assertArrayHasKey('AditionalData', $adenda);
    }

    public function testReferenceWithoutValidationIsOnlyStored(): void
    {
        $transport = $this->certifying();

        $this->aClient($transport)->invoice('CF', self::ITEMS, ['internal_reference' => self::REFERENCE]);

        [$adenda] = self::adendaOf(self::sentPayload($transport));
        $this->assertSame(self::REFERENCE, $adenda['Code']);
        $this->assertSame(['NO_VALIDAR'], self::referenceFlags($adenda));
    }

    public function testWithoutReferenceThePayloadIsUnchanged(): void
    {
        $transport = $this->certifying();

        $this->aClient($transport)->invoice('CF', self::ITEMS);

        $expected = DteBuilder::buildFact(
            '12345678', self::SELLER_NAME, self::SELLER_ADDRESS, DteBuilder::buyerCf(), self::ITEMS,
            'FACT', 'GEN', '1', '1'
        );
        $sent = self::sentPayload($transport);
        unset($expected['Header']['IssuedDateTime'], $sent['Header']['IssuedDateTime']);
        $this->assertSame(json_decode(json_encode($expected), true), $sent);

        [$adenda] = self::adendaOf($sent);
        $this->assertSame('FRONT-263C-444B-89BA-6F87EC1330C0', $adenda['Code']);
        $this->assertSame(['NO_VALIDAR'], self::referenceFlags($adenda));
    }

    public function testCreditNoteGetsAnAdendaNextToItsComplement(): void
    {
        $transport = $this->certifying();

        $this->aClient($transport)->creditNote('CF', self::ITEMS, self::ORIGIN, 'Devolución', [
            'internal_reference'          => self::REFERENCE,
            'validate_internal_reference' => true,
        ]);

        $entries = self::sentPayload($transport)['AdditionalDocumentInfo']['AdditionalInfo'];
        $this->assertSame(['COMPLEMENTO', 'ADENDA'], array_column($entries, 'Type'));
        $this->assertSame(self::REFERENCE, $entries[1]['Code']);
        $this->assertSame(['VALIDAR'], self::referenceFlags($entries[1]));
    }

    public function testExistingFlagIsReplacedAndGoesFirst(): void
    {
        $payload = ['AdditionalDocumentInfo' => ['AdditionalInfo' => [[
            'Code'          => 'ANTERIOR',
            'Type'          => 'ADENDA',
            'AditionalInfo' => [
                ['Name' => 'OTRO', 'Data' => null, 'Value' => 'X'],
                ['Name' => 'VALIDAR_REFERENCIA_INTERNA', 'Data' => null, 'Value' => 'NO_VALIDAR'],
            ],
        ]]]];

        [$adenda] = self::adendaOf(DteBuilder::applyInternalReference($payload, self::REFERENCE, true));

        $this->assertSame(self::REFERENCE, $adenda['Code']);
        $this->assertSame([
            ['Name' => 'VALIDAR_REFERENCIA_INTERNA', 'Data' => null, 'Value' => DteBuilder::VALIDATE_REFERENCE],
            ['Name' => 'OTRO', 'Data' => null, 'Value' => 'X'],
        ], $adenda['AditionalInfo']);
    }

    public function testValidationNeedsAReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->aClient($this->certifying())->invoice('CF', self::ITEMS, ['validate_internal_reference' => true]);
    }

    public function testResultExposesEnrollmentAndAdditionalInfo(): void
    {
        $result = $this->aClient($this->certifying())->invoice('CF', self::ITEMS);

        $this->assertSame('2026-11-03T10:00:02', $result->enrolledTimestamp);
        $this->assertSame(['acuseReciboSAT' => 'ACEPTADO'], $result->additionalInfo);
    }

    // ── Duplicate reference ──────────────────────────────────────────────────

    private function certifyAnswering(array $body): DigifactException
    {
        return $this->rejectionOf($this->certifying($body), [
            'internal_reference'          => self::REFERENCE,
            'validate_internal_reference' => true,
        ]);
    }

    public function testCode9022IsADuplicateReference(): void
    {
        foreach ([9022, '9022'] as $code) {
            $error = $this->certifyAnswering(['code' => $code, 'description' => 'Referencia interna duplicada']);

            $this->assertInstanceOf(DigifactDuplicateReferenceException::class, $error);
            $this->assertSame(9022, $error->getApiCode());
            $this->assertFalse($error->isRetryable());
        }
    }

    public function testRejectionMessageAboutAnAlreadySentReferenceIsADuplicate(): void
    {
        $error = $this->certifyAnswering([
            'code'        => 0,
            'description' => 'La referencia interna ya ha sido enviada a certificar',
        ]);

        $this->assertInstanceOf(DigifactDuplicateReferenceException::class, $error);
    }

    public function testGlobalErrorCodeBus003IsADuplicate(): void
    {
        $error = $this->certifyAnswering(['code' => 0, 'codeError' => 'BUS-003', 'description' => 'Rechazado']);

        $this->assertInstanceOf(DigifactDuplicateReferenceException::class, $error);
    }

    public function testTheResponseDigifactActuallySendsIsADuplicate(): void
    {
        $error = $this->certifyAnswering([
            'code' => 3000,
            'message' => 'La referencia INTERNA, ya ha sido enviada a certificar',
            'description' => '[9022] - El documento bajo la referencia 0F8FAD5B-D9CB-469F-A165-70867728950E ya ha sido registrado bajo el UUID CCCCDDDD-1111-2222-3333-444455556666',
        ]);

        $this->assertInstanceOf(DigifactDuplicateReferenceException::class, $error);
    }

    public function testNonNumericCodeIsAnApiError(): void
    {
        $error = $this->certifyAnswering(['code' => 'Otro', 'description' => '?']);

        $this->assertSame(DigifactApiException::class, get_class($error));
    }

    // ── Token renewal ────────────────────────────────────────────────────────

    public function testExpiredTokenLogsInAgainAndRetriesOnce(): void
    {
        $transport = (new FakeTransport())
            ->on('POST', self::CERTIFY, self::response(401, []), self::response(200, self::CERTIFIED))
            ->on('POST', self::LOGIN, self::response(200, ['Token' => 'token-nuevo']));
        $client = $this->aClient($transport, token: 'token-vencido');

        $result = $client->invoice('CF', self::ITEMS);

        $certifyCalls = $transport->callsTo('POST', self::CERTIFY);
        $this->assertSame(self::CERTIFIED['authNumber'], $result->authNumber);
        $this->assertSame(
            ['token-vencido', 'token-nuevo'],
            array_map([FakeTransport::class, 'authorizationOf'], $certifyCalls)
        );
        $this->assertSame('token-nuevo', $client->getToken());
    }

    public function testTokenOnlyClientReportsTheExpiredToken(): void
    {
        $transport = (new FakeTransport())->on('GET', '/Shared', self::response(401, []));
        $client = $this->aClient($transport, password: '', token: 'token-vencido');

        try {
            $client->getDteInfo('CCCCDDDD-1111-2222-3333-444455556666');
            $this->fail('A 401 was expected to raise DigifactAuthException');
        } catch (DigifactAuthException $e) {
            $this->assertSame(401, $e->getApiCode());
        }
        $this->assertSame([], $transport->callsTo('POST', self::LOGIN));
    }

    public function testStillUnauthorizedAfterLoginIsAnAuthError(): void
    {
        $transport = (new FakeTransport())
            ->on('POST', self::CERTIFY, self::response(401, []))
            ->on('POST', self::LOGIN, self::response(200, ['Token' => 'token-nuevo']));

        $error = $this->rejectionOf($transport);

        $this->assertInstanceOf(DigifactAuthException::class, $error);
        $this->assertCount(2, $transport->callsTo('POST', self::CERTIFY));
        $this->assertCount(1, $transport->callsTo('POST', self::LOGIN));
    }

    // ── Error classification ─────────────────────────────────────────────────

    private function certifyWithStatus(int $status): DigifactException
    {
        $error = $this->rejectionOf($this->certifying(['message' => 'error'], $status));
        $this->assertInstanceOf(DigifactApiException::class, $error);
        return $error;
    }

    public function testServerErrorsAreRetryable(): void
    {
        $error = $this->certifyWithStatus(503);

        $this->assertTrue($error->isRetryable());
        $this->assertSame(503, $error->getApiCode());
        $this->assertSame(['message' => 'error'], $error->getRaw());
    }

    public function testInvalidDocumentsAreNotRetryable(): void
    {
        foreach ([400, 406, 415] as $status) {
            $error = $this->certifyWithStatus($status);

            $this->assertFalse($error->isRetryable(), "HTTP $status");
            $this->assertSame($status, $error->getApiCode());
        }
    }

    // ── findByInternalReference ──────────────────────────────────────────────

    public function testQueriesSharedByInternalReferenceAndDay(): void
    {
        $rows = [['GUID' => 'CCCCDDDD-1111-2222-3333-444455556666', 'SERIE' => 'CCCCDDDD', 'NUMERO' => '123']];
        $transport = (new FakeTransport())->on('GET', '/Shared', self::response(200, ['RESPONSE' => $rows]));

        $found = $this->aClient($transport)->findByInternalReference(self::REFERENCE, '2026-11-03');

        $calls = $transport->callsTo('GET', '/Shared');
        $this->assertSame($rows, $found);
        $this->assertSame([
            'COUNTRY'  => 'GT',
            'TAXID'    => '000012345678',
            'DATA1'    => 'SHARED_GETDTEINFO_BY_INTERNALID',
            'DATA2'    => 'REFERENCIA_INTERNA|' . self::REFERENCE . '|ISSUEDDATE|2026-11-03',
            'USERNAME' => 'FELUSER',
        ], FakeTransport::queryOf(end($calls)));
    }

    public function testIssuedOnAcceptsADate(): void
    {
        $transport = (new FakeTransport())->on('GET', '/Shared', self::response(200, ['RESPONSE' => []]));

        $this->aClient($transport)->findByInternalReference(self::REFERENCE, new \DateTimeImmutable('2026-11-03 18:30'));

        $calls = $transport->callsTo('GET', '/Shared');
        $this->assertStringEndsWith('|ISSUEDDATE|2026-11-03', FakeTransport::queryOf(end($calls))['DATA2']);
    }

    public function testNoDteWithThatReferenceIsAnEmptyList(): void
    {
        $transport = (new FakeTransport())
            ->on('GET', '/Shared', self::response(200, ['Respuesta' => 0, 'RESPONSE' => []]));

        $this->assertSame([], $this->aClient($transport)->findByInternalReference(self::REFERENCE, '2026-11-03'));
    }
}

final class FakeTransport
{
    /** @var array<string, list<array{0:int,1:string}>> */
    private array $routes = [];
    /** @var list<array{method:string, url:string, headers:list<string>, body:?string}> */
    private array $calls = [];

    public function on(string $method, string $path, array ...$responses): self
    {
        $this->routes["$method $path"] = [...($this->routes["$method $path"] ?? []), ...$responses];
        return $this;
    }

    public function callsTo(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->calls,
            fn (array $call): bool => $call['method'] === $method && str_ends_with(self::pathOf($call['url']), $path)
        ));
    }

    public function __invoke(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        foreach ($this->routes as $route => $queue) {
            [$routeMethod, $routePath] = explode(' ', $route, 2);
            if ($routeMethod === $method && str_ends_with(self::pathOf($url), $routePath)) {
                return count($queue) > 1 ? array_shift($this->routes[$route]) : $queue[0];
            }
        }
        throw new \LogicException("Unexpected $method $url");
    }

    public static function authorizationOf(array $call): string
    {
        foreach ($call['headers'] as $header) {
            if (str_starts_with($header, 'Authorization: ')) {
                return substr($header, strlen('Authorization: '));
            }
        }
        return '';
    }

    public static function queryOf(array $call): array
    {
        parse_str((string)parse_url($call['url'], PHP_URL_QUERY), $query);
        return $query;
    }

    private static function pathOf(string $url): string
    {
        return (string)parse_url($url, PHP_URL_PATH);
    }
}
