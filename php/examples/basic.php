<?php

declare(strict_types=1);

/**
 * Basic usage example for the Digifact FEL PHP SDK.
 *
 * Set environment variables before running:
 *   export DIGIFACT_TAXID=12345678
 *   export DIGIFACT_USERNAME=FELUSER
 *   export DIGIFACT_PASSWORD=your_password
 *
 * Then:
 *   php examples/basic.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Digifact\Fel\DigifactClient;
use Digifact\Fel\DigifactException;

$taxid    = getenv('DIGIFACT_TAXID')    ?: '';
$username = getenv('DIGIFACT_USERNAME') ?: '';
$password = getenv('DIGIFACT_PASSWORD') ?: '';

if (!$taxid || !$username || !$password) {
    echo "Skipping example: set DIGIFACT_TAXID, DIGIFACT_USERNAME, DIGIFACT_PASSWORD\n";
    exit(0);
}

$client = new DigifactClient([
    'taxid'           => $taxid,
    'username'        => $username,
    'password'        => $password,
    'environment'     => 'test',
    // PETROLEO rates (Q/gallon) — auto-filled in fuelInvoice when petroleo_code is set
    'petroleo_rates'  => ['1' => 4.70, '2' => 4.60, '4' => 1.30], // SUPER / REGULAR / DIESEL
]);

// ── FACT CF ──
echo "Emitting FACT CF...\n";
try {
    $result = $client->invoice('CF', [
        ['description' => 'Consultoría PHP SDK', 'qty' => 1, 'price' => 100.00],
    ]);
    echo "  auth_number  : {$result->authNumber}\n";
    echo "  series       : {$result->series}\n";
    echo "  number       : {$result->number}\n";
    echo "  issued_at    : {$result->issueDateTime}\n";
} catch (DigifactException $e) {
    echo "  ERROR: {$e->getMessage()}\n";
    exit(1);
}

// ── FACT NIT ──
echo "\nEmitting FACT to NIT 77454820...\n";
try {
    $result2 = $client->invoice('77454820', [
        ['description' => 'Laptop', 'qty' => 1, 'price' => 5000.00, 'type' => 'Bien'],
        ['description' => 'Soporte anual', 'qty' => 1, 'price' => 500.00],
    ]);
    echo "  auth_number  : {$result2->authNumber}\n";
} catch (DigifactException $e) {
    echo "  ERROR: {$e->getMessage()}\n";
}

// ── NIT Lookup ──
echo "\nLooking up NIT 77454820...\n";
try {
    $info = $client->lookupNit('77454820');
    echo "  name    : {$info['name']}\n";
    echo "  address : {$info['address']}\n";
} catch (DigifactException $e) {
    echo "  ERROR: {$e->getMessage()}\n";
}

// ── FACT Combustible ──
// For gas stations, set petroleo_rates at init so you don't repeat petroleo_amount on each item.
echo "\nEmitting FACT Combustible...\n";
try {
    $resultFuel = $client->fuelInvoice('CF', [
        // Only petroleo_code needed when petroleo_rates was set at client init.
        // petroleo_code: '1'=SUPER, '2'=REGULAR, '4'=DIESEL
        ['description' => 'GASOLINA SUPER',   'qty' => 1, 'price' => 30.30, 'petroleo_code' => '1', 'type' => 'Bien'],
        ['description' => 'GASOLINA REGULAR', 'qty' => 1, 'price' => 29.40, 'petroleo_code' => '2', 'type' => 'Bien'],
        ['description' => 'GASOLINA DIESEL',  'qty' => 1, 'price' => 30.70, 'petroleo_code' => '4', 'type' => 'Bien'],
        // Regular items (no petroleo_code): IVA only
        ['description' => 'FILTRO DE ACEITE',   'qty' => 1, 'price' => 45.00,  'type' => 'Bien'],
        ['description' => 'SET DE CANDELAS NGK', 'qty' => 1, 'price' => 400.00, 'type' => 'Bien'],
    ]);
    echo "  auth_number  : {$resultFuel->authNumber}\n";
    echo "  series       : {$resultFuel->series}\n";
    echo "  number       : {$resultFuel->number}\n";
} catch (DigifactException $e) {
    echo "  ERROR: {$e->getMessage()}\n";
}

echo "\nAll examples completed successfully.\n";
