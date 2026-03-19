<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Digifact\Fel\DigifactClient;
use Digifact\Fel\DigifactException;
use Digifact\Fel\DteResult;
use Digifact\Fel\TaxHelper;

/**
 * Integration tests for the Digifact FEL PHP SDK.
 *
 * Requires environment variables:
 *   DIGIFACT_TAXID, DIGIFACT_USERNAME, DIGIFACT_PASSWORD
 *
 * If not set, all integration tests are skipped.
 */
class IntegrationTest extends TestCase
{
    private static ?DigifactClient $client = null;
    private static bool $skip = false;
    private static string $skipReason = '';

    public static function setUpBeforeClass(): void
    {
        $taxid    = getenv('DIGIFACT_TAXID')    ?: '';
        $username = getenv('DIGIFACT_USERNAME') ?: '';
        $password = getenv('DIGIFACT_PASSWORD') ?: '';

        if (!$taxid || !$username || !$password) {
            self::$skip = true;
            self::$skipReason = 'Set DIGIFACT_TAXID, DIGIFACT_USERNAME, DIGIFACT_PASSWORD to run integration tests';
            return;
        }

        self::$client = new DigifactClient([
            'taxid'       => $taxid,
            'username'    => $username,
            'password'    => $password,
            'environment' => 'test',
        ]);
    }

    private function requireClient(): DigifactClient
    {
        if (self::$skip) {
            $this->markTestSkipped(self::$skipReason);
        }
        return self::$client;
    }

    // ── Unit tests (no credentials required) ─────────────────────────────────

    public function testPadTaxid(): void
    {
        $this->assertSame('000012345678', TaxHelper::padTaxid('12345678'));
        $this->assertSame('000012345678', TaxHelper::padTaxid('000012345678'));
        $this->assertSame('000012345678', TaxHelper::padTaxid('GT.000012345678'));
    }

    public function testCalcIva(): void
    {
        [$taxable, $iva] = TaxHelper::calcIva('112');
        $this->assertSame('100.000000', $taxable);
        $this->assertSame('12.000000', $iva);
    }

    public function testCalcIvaPartial(): void
    {
        [$taxable, $iva] = TaxHelper::calcIva('100');
        // taxable + iva should equal 100
        $sum = bcadd($taxable, $iva, 6);
        $this->assertSame('100.000000', $sum);
    }

    public function testFmt(): void
    {
        $this->assertSame('1.000000', TaxHelper::fmt('1'));
        $this->assertSame('100.123457', TaxHelper::fmt('100.1234567'));
    }

    public function testDteResultFromArray(): void
    {
        $result = DteResult::fromArray([
            'authNumber'     => 'AAA-BBB',
            'batch'          => 'SERIE1',
            'serial'         => '12345',
            'issuedTimeStamp' => '2026-03-18T21:40:14',
        ]);
        $this->assertSame('AAA-BBB', $result->authNumber);
        $this->assertSame('SERIE1', $result->series);
        $this->assertSame('12345', $result->number);
        $this->assertSame('2026-03-18 21:40:14', $result->issueDateTime);
    }

    // ── Integration tests ─────────────────────────────────────────────────────

    public function testLogin(): void
    {
        $client = $this->requireClient();
        $method = new \ReflectionMethod($client, 'login');
        $method->setAccessible(true);
        $token = $method->invoke($client);
        $this->assertNotEmpty($token);
    }

    public function testLookupNit(): void
    {
        $client = $this->requireClient();
        $info = $client->lookupNit('12345678');
        $this->assertNotEmpty($info['name']);
        echo "\n  NIT name: " . $info['name'];
    }

    public function testFactCf(): void
    {
        $client = $this->requireClient();
        $result = $client->invoice('CF', [
            ['description' => 'Consultoría PHP SDK', 'qty' => 1, 'price' => 100.00],
        ]);
        $this->assertNotEmpty($result->authNumber);
        echo "\n  FACT CF auth: " . $result->authNumber;
    }

    public function testFactNit(): void
    {
        $client = $this->requireClient();
        $result = $client->invoice('12345678', [
            ['description' => 'Laptop', 'qty' => 1, 'price' => 5000.00, 'type' => 'Bien'],
            ['description' => 'Soporte', 'qty' => 1, 'price' => 500.00],
        ]);
        $this->assertNotEmpty($result->authNumber);
        echo "\n  FACT NIT auth: " . $result->authNumber;
    }

    public function testFactCui(): void
    {
        $client = $this->requireClient();
        $result = $client->invoice(
            ['taxid' => '3730617490101', 'type' => 'CUI', 'name' => 'Julio Cifuentes'],
            [['description' => 'Producto', 'qty' => 2, 'price' => 50.00, 'type' => 'Bien']]
        );
        $this->assertNotEmpty($result->authNumber);
        echo "\n  FACT CUI auth: " . $result->authNumber;
    }

    public function testFcam(): void
    {
        $client = $this->requireClient();
        [, , $dateOnly] = TaxHelper::gtNow();
        $result = $client->invoice('12345678', [
            ['description' => 'Servicio FCAM', 'qty' => 1, 'price' => 30.00],
        ], [
            'doc_type'      => 'FCAM',
            'payment_terms' => [['date' => $dateOnly, 'amount' => 30.00]],
        ]);
        $this->assertNotEmpty($result->authNumber);
        echo "\n  FCAM auth: " . $result->authNumber;
    }

    public function testNdebAndNcre(): void
    {
        $client = $this->requireClient();
        [, , $dateOnly] = TaxHelper::gtNow();

        // First emit FCAM as origin
        try {
            $fcam = $client->invoice('12345678', [
                ['description' => 'Origen FCAM', 'qty' => 1, 'price' => 50.00],
            ], [
                'doc_type'      => 'FCAM',
                'payment_terms' => [['date' => $dateOnly, 'amount' => 50.00]],
            ]);
        } catch (DigifactException $e) {
            $this->markTestSkipped("FCAM failed: {$e->getMessage()}");
        }

        $origin = [
            'auth_number' => $fcam->authNumber,
            'date'        => $dateOnly,
            'series'      => $fcam->series,
            'number'      => $fcam->number,
        ];

        // NDEB
        $ndeb = $client->debitNote('12345678', [
            ['description' => 'Cargo NDEB', 'qty' => 1, 'price' => 19.00, 'type' => 'Bien'],
        ], $origin, 'Cargo de prueba');
        $this->assertNotEmpty($ndeb->authNumber);
        echo "\n  NDEB auth: " . $ndeb->authNumber;

        // NCRE
        $ncre = $client->creditNote('12345678', [
            ['description' => 'Devolución NCRE', 'qty' => 1, 'price' => 10.00],
        ], $origin, 'Producto defectuoso');
        $this->assertNotEmpty($ncre->authNumber);
        echo "\n  NCRE auth: " . $ncre->authNumber;
    }

    public function testNabn(): void
    {
        $client = $this->requireClient();
        $result = $client->invoice('12345678', [
            ['description' => 'RETENEDOR BLANCO', 'qty' => 1, 'price' => 100.00, 'type' => 'Bien'],
        ], ['doc_type' => 'NABN']);
        $this->assertNotEmpty($result->authNumber);
        echo "\n  NABN auth: " . $result->authNumber;
    }

    public function testFesp(): void
    {
        $client = $this->requireClient();
        $result = $client->invoice('10001794', [
            ['description' => 'Alquiler equipo', 'qty' => 1, 'price' => 2500.00, 'type' => 'Bien'],
        ], ['doc_type' => 'FESP']);
        $this->assertNotEmpty($result->authNumber);
        echo "\n  FESP auth: " . $result->authNumber;
    }

    public function testRdon(): void
    {
        $client = $this->requireClient();
        $result = $client->invoice('12345678', [
            ['description' => 'Donación', 'qty' => 1, 'price' => 50.00, 'type' => 'Bien'],
        ], ['doc_type' => 'RDON', 'tipo_personeria' => '719']);
        $this->assertNotEmpty($result->authNumber);
        echo "\n  RDON auth: " . $result->authNumber;
    }

    public function testReci(): void
    {
        $client = $this->requireClient();
        $result = $client->invoice('12345678', [
            ['description' => 'Pago universitario', 'qty' => 1, 'price' => 250.00, 'type' => 'Bien'],
        ], ['doc_type' => 'RECI']);
        $this->assertNotEmpty($result->authNumber);
        echo "\n  RECI auth: " . $result->authNumber;
    }

    public function testCancelAndNcredTotal(): void
    {
        $client = $this->requireClient();

        try {
            $fact = $client->invoice('CF', [
                ['description' => 'Factura para anular', 'qty' => 1, 'price' => 50.00],
            ]);
        } catch (DigifactException $e) {
            $this->markTestSkipped("FACT CF failed: {$e->getMessage()}");
        }

        $issueDateTime = $fact->issueDateTime;

        $ncredResult = $client->creditNoteTotal($fact->authNumber, $issueDateTime, 'Nota de crédito total de prueba');
        $this->assertIsArray($ncredResult);

        $cancelResult = $client->cancel($fact->authNumber, 'CF', $issueDateTime, 'Anulación de prueba automática');
        $this->assertIsArray($cancelResult);

        echo "\n  cancel result code: " . ($cancelResult['code'] ?? 'n/a');
    }

    public function testGetDte(): void
    {
        $client = $this->requireClient();

        try {
            $fact = $client->invoice('CF', [
                ['description' => 'Consulta DTE', 'qty' => 1, 'price' => 10.00],
            ]);
        } catch (DigifactException $e) {
            $this->markTestSkipped("FACT CF failed: {$e->getMessage()}");
        }

        $doc = $client->getDte($fact->authNumber);
        $this->assertIsArray($doc);

        $info = $client->getDteInfo($fact->authNumber);
        $this->assertIsArray($info);
    }
}
