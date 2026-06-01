<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Digifact\Fel\DigifactClient;
use Digifact\Fel\DigifactException;
use Digifact\Fel\DigifactValidationException;
use Digifact\Fel\DteBuilder;
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

    public function testCalcFuelLine(): void
    {
        $r = TaxHelper::calcFuelLine('1', '35.00', '4.70');
        $this->assertSame('1.000000',  $r['qty']);
        $this->assertSame('35.000000', $r['price']);
        $this->assertSame('35.000000', $r['lineTotal']);
        $this->assertSame('27.053571', $r['taxable']);
        $this->assertSame('3.246429',  $r['iva']);
        $this->assertSame('4.700000',  $r['petrol']);
    }

    public function testCalcFuelLineTaxablePlusIvaEqualsGross(): void
    {
        $r   = TaxHelper::calcFuelLine('2', '53.00', '3.00');
        $sum = bcadd($r['taxable'], $r['iva'], 6);
        // taxable + iva must equal qty × net (= 100.00)
        $this->assertSame('100.000000', $sum);
        // lineTotal = qty × price = 2 × 53.00
        $this->assertSame('106.000000', $r['lineTotal']);
    }

    public function testBuyerNitFullDetails(): void
    {
        $buyer = DteBuilder::buyerNit(
            '12345678',
            'EMPRESA EJEMPLO S.A.',
            '6 AV 6-48 ZONA 9',
            '01009',
            'GUATEMALA',
            'GUATEMALA',
            'GT',
            'test@example.com'
        );
        $this->assertSame('12345678', $buyer['TaxID']);
        $this->assertSame('EMPRESA EJEMPLO S.A.', $buyer['Name']);
        $this->assertSame('6 AV 6-48 ZONA 9', $buyer['AddressInfo']['Address']);
        $this->assertSame('01009', $buyer['AddressInfo']['City']);
        $this->assertSame('test@example.com', $buyer['Contact']['EmailList']['Email'][0]);
    }

    // ── Unit: petroleo_rates auto-fill / validation ───────────────────────────

    private function invokeApplyPetroleoRates(DigifactClient $client, array $items): array
    {
        $m = new \ReflectionMethod($client, 'applyPetroleoRates');
        $m->setAccessible(true);
        return $m->invoke($client, $items);
    }

    public function testPetroleoRatesFillsAmountFromRates(): void
    {
        $client = new DigifactClient([
            'taxid' => '12345678', 'username' => 'U', 'password' => 'P',
            'petroleo_rates' => ['1' => 4.70, '4' => 1.30],
        ]);
        $resolved = $this->invokeApplyPetroleoRates($client, [
            ['description' => 'SUPER',  'price' => 30.30, 'petroleo_code' => '1', 'type' => 'Bien'],
            ['description' => 'DIESEL', 'price' => 30.70, 'petroleo_code' => '4', 'type' => 'Bien'],
            ['description' => 'FILTRO', 'price' => 45.00, 'type' => 'Bien'],  // no code → untouched
        ]);
        $this->assertSame(4.70, $resolved[0]['petroleo_amount']);
        $this->assertSame(1.30, $resolved[1]['petroleo_amount']);
        $this->assertArrayNotHasKey('petroleo_amount', $resolved[2]);
    }

    public function testPetroleoRatesExplicitAmountNotOverwritten(): void
    {
        $client = new DigifactClient([
            'taxid' => '12345678', 'username' => 'U', 'password' => 'P',
            'petroleo_rates' => ['1' => 4.70],
        ]);
        $resolved = $this->invokeApplyPetroleoRates($client, [
            ['description' => 'SUPER', 'price' => 30.30, 'petroleo_code' => '1', 'petroleo_amount' => 9.99, 'type' => 'Bien'],
        ]);
        $this->assertSame(9.99, $resolved[0]['petroleo_amount']);
    }

    public function testPetroleoRatesMissingRaisesException(): void
    {
        $this->expectException(\Digifact\Fel\DigifactValidationException::class);
        $client = new DigifactClient([
            'taxid' => '12345678', 'username' => 'U', 'password' => 'P',
            // no rates configured
        ]);
        $this->invokeApplyPetroleoRates($client, [
            ['description' => 'SUPER', 'price' => 30.30, 'petroleo_code' => '1', 'type' => 'Bien'],
        ]);
    }

    public function testPetroleoRatesCodeNotInRatesRaisesException(): void
    {
        $this->expectException(\Digifact\Fel\DigifactValidationException::class);
        $client = new DigifactClient([
            'taxid' => '12345678', 'username' => 'U', 'password' => 'P',
            'petroleo_rates' => ['1' => 4.70],  // DIESEL not configured
        ]);
        $this->invokeApplyPetroleoRates($client, [
            ['description' => 'DIESEL', 'price' => 30.70, 'petroleo_code' => '4', 'type' => 'Bien'],
        ]);
    }



    // ── Unit tests: fuel frases ───────────────────────────────────────────────

    private function getBuyer(): array
    {
        return DteBuilder::buyerCf();
    }

    private function getFuelItems(): array
    {
        return [['description' => 'SUPER', 'qty' => 1, 'price' => 35.00,
                 'petroleo_amount' => 4.70, 'petroleo_code' => '1', 'type' => 'Bien']];
    }

    private function getFrasesFromPayload(array $payload): array
    {
        $ai = $payload['Seller']['AdditionlInfo'] ?? [];
        $frases = [];
        for ($i = 0; $i + 1 < count($ai); $i += 2) {
            $frases[] = [$ai[$i]['Value'], $ai[$i + 1]['Value']];
        }
        return $frases;
    }

    public function testFuelFrasesNonFuelNoSubsidy(): void
    {
        $buyer = DteBuilder::buyerNit('12345678', 'TEST');
        $payload = DteBuilder::buildFact('12345678', 'TEST', 'CALLE', $buyer,
            [['description' => 'X', 'qty' => 1, 'price' => 100.0]]);
        $frases = $this->getFrasesFromPayload($payload);
        $this->assertCount(1, $frases, 'Regular invoice must have only the base frase');
        $this->assertNotContains(['9', '18'], $frases);
        $this->assertNotContains(['9', '19'], $frases);
    }

    public function testFuelFrasesWithinWindowAutoInject(): void
    {
        $result = DteBuilder::resolveFuelFrases(null, '1', '1', '2026-06-01T10:00:00', true);
        $pairs = array_map(fn($f) => [$f['tipo_frase'], $f['escenario']], $result);
        $this->assertContains(['1', '1'], $pairs, 'Base frase must be present');
        $this->assertContains(['9', '18'], $pairs, 'Escenario 18 must be auto-injected');
        $this->assertContains(['9', '19'], $pairs, 'Escenario 19 must be auto-injected');
        $this->assertCount(3, $result);
    }

    public function testFuelFrasesCustomNoAutoInject(): void
    {
        $custom = [['tipo_frase' => '2', 'escenario' => '1']];
        $result = DteBuilder::resolveFuelFrases($custom, null, null, '2026-06-01T10:00:00', true);
        $this->assertCount(1, $result);
        $this->assertSame('2', $result[0]['tipo_frase']);
        $pairs = array_map(fn($f) => [$f['tipo_frase'], $f['escenario']], $result);
        $this->assertNotContains(['9', '18'], $pairs);
    }

    public function testFuelFrasesAutoDisabled(): void
    {
        $result = DteBuilder::resolveFuelFrases(null, '1', '1', '2026-06-01T10:00:00', false);
        $this->assertCount(1, $result);
        $pairs = array_map(fn($f) => [$f['tipo_frase'], $f['escenario']], $result);
        $this->assertNotContains(['9', '18'], $pairs);
        $this->assertNotContains(['9', '19'], $pairs);
    }

    public function testFuelFrasesDeduplication(): void
    {
        $dupes = [
            ['tipo_frase' => '9', 'escenario' => '18'],
            ['tipo_frase' => '9', 'escenario' => '18'],
            ['tipo_frase' => '9', 'escenario' => '19'],
        ];
        $result = DteBuilder::resolveFuelFrases($dupes, null, null, '2026-06-01T10:00:00', true);
        $this->assertCount(2, $result);
    }

    public function testFuelFrasesOutsideWindow(): void
    {
        $before = DteBuilder::resolveFuelFrases(null, '1', '1', '2026-04-26T10:00:00', true);
        $this->assertCount(1, $before);
        $after = DteBuilder::resolveFuelFrases(null, '1', '1', '2026-07-27T10:00:00', true);
        $this->assertCount(1, $after);
    }

    public function testFuelFrasesMutualExclusivityRaises(): void
    {
        $this->expectException(DigifactValidationException::class);
        DteBuilder::buildFactCombustible(
            '12345678', 'TEST', 'CALLE',
            $this->getBuyer(), $this->getFuelItems(),
            'GEN', '1', null, null,
            [['tipo_frase' => '1', 'escenario' => '1']]
        );
    }


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
        $info = $client->lookupNit('77454820');
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
        $result = $client->invoice('77454820', [
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
        $result = $client->invoice('77454820', [
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
            $fcam = $client->invoice('77454820', [
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
        $ndeb = $client->debitNote('77454820', [
            ['description' => 'Cargo NDEB', 'qty' => 1, 'price' => 19.00, 'type' => 'Bien'],
        ], $origin, 'Cargo de prueba');
        $this->assertNotEmpty($ndeb->authNumber);
        echo "\n  NDEB auth: " . $ndeb->authNumber;

        // NCRE
        $ncre = $client->creditNote('77454820', [
            ['description' => 'Devolución NCRE', 'qty' => 1, 'price' => 10.00],
        ], $origin, 'Producto defectuoso');
        $this->assertNotEmpty($ncre->authNumber);
        echo "\n  NCRE auth: " . $ncre->authNumber;
    }

    public function testNabn(): void
    {
        $client = $this->requireClient();
        $result = $client->invoice('77454820', [
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
        $result = $client->invoice('77454820', [
            ['description' => 'Donación', 'qty' => 1, 'price' => 50.00, 'type' => 'Bien'],
        ], ['doc_type' => 'RDON', 'tipo_personeria' => '719']);
        $this->assertNotEmpty($result->authNumber);
        echo "\n  RDON auth: " . $result->authNumber;
    }

    public function testReci(): void
    {
        $client = $this->requireClient();
        $result = $client->invoice('77454820', [
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

    public function testFuelInvoice(): void
    {
        $client = $this->requireClient();
        $result = $client->fuelInvoice('CF', [
            ['description' => 'GASOLINA SUPER',    'qty' => 1, 'price' => 30.30, 'petroleo_amount' => 4.70, 'petroleo_code' => '1', 'type' => 'Bien'],
            ['description' => 'GASOLINA REGULAR',  'qty' => 1, 'price' => 29.40, 'petroleo_amount' => 4.60, 'petroleo_code' => '2', 'type' => 'Bien'],
            ['description' => 'FILTRO DE ACEITE',  'qty' => 1, 'price' => 45.00, 'type' => 'Bien'],
        ]);
        $this->assertNotEmpty($result->authNumber);
        echo "\n  FACT Combustible auth: " . $result->authNumber;
    }
}
