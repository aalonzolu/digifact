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
    'taxid'       => $taxid,
    'username'    => $username,
    'password'    => $password,
    'environment' => 'test',
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
echo "\nEmitting FACT to NIT 12345678...\n";
try {
    $result2 = $client->invoice('12345678', [
        ['description' => 'Laptop', 'qty' => 1, 'price' => 5000.00, 'type' => 'Bien'],
        ['description' => 'Soporte anual', 'qty' => 1, 'price' => 500.00],
    ]);
    echo "  auth_number  : {$result2->authNumber}\n";
} catch (DigifactException $e) {
    echo "  ERROR: {$e->getMessage()}\n";
}

// ── NIT Lookup ──
echo "\nLooking up NIT 12345678...\n";
try {
    $info = $client->lookupNit('12345678');
    echo "  name    : {$info['name']}\n";
    echo "  address : {$info['address']}\n";
} catch (DigifactException $e) {
    echo "  ERROR: {$e->getMessage()}\n";
}

echo "\nAll examples completed successfully.\n";
