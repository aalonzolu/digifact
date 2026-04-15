# Digifact FEL Guatemala — PHP SDK

PHP SDK for the [Digifact](https://www.digifact.com.gt/) FEL Guatemala e-invoicing API.

## Requirements

- PHP 8.1+
- Extensions: `curl`, `bcmath`, `json`

## Installation

```bash
composer require aalonzolu/digifact
```

Or from source:

```bash
composer install
```

## Quick start

```php
use Digifact\Fel\DigifactClient;

$client = new DigifactClient([
    'taxid'       => '12345678',
    'username'    => 'FELUSER',
    'password'    => 'secret',
    'environment' => 'test',  // or 'production'
]);

// FACT CF
$result = $client->invoice('CF', [
    ['description' => 'Consultoría', 'qty' => 1, 'price' => 100.00]
]);
echo $result->authNumber;

// FACT to NIT (buyer name fetched automatically)
$result = $client->invoice('12345678', [
    ['description' => 'Laptop', 'qty' => 1, 'price' => 5000.00, 'type' => 'Bien'],
    ['description' => 'Soporte', 'qty' => 1, 'price' => 500.00],
]);

// FACT to CUI buyer
$result = $client->invoice(
    ['taxid' => '3730617490101', 'type' => 'CUI', 'name' => 'Juan Pérez'],
    [['description' => 'Producto', 'qty' => 2, 'price' => 50.00]]
);

// FCAM (Factura Cambiaria)
$result = $client->invoice('12345678', [
    ['description' => 'Servicio', 'qty' => 1, 'price' => 500.00]
], [
    'doc_type'      => 'FCAM',
    'payment_terms' => [['date' => '2026-04-18', 'amount' => 500.00]],
]);

// Credit note (NCRE)
$result = $client->creditNote('12345678', [
    ['description' => 'Devolución', 'qty' => 1, 'price' => 100.00]
], [
    'auth_number' => 'XXXXXXXX-...',
    'date'        => '2026-03-18',
    'series'      => 'XXXXXXXX',
    'number'      => '123456',
], 'Producto defectuoso');

// Debit note (NDEB)
$result = $client->debitNote('12345678', [...], $origin, 'Cargo extra');

// Cancel
$result = $client->cancel('XXXXXXXX-...', 'CF', '2026-03-18 21:40:14', 'Error en monto');

// NIT lookup
$info = $client->lookupNit('12345678');
echo $info['name'];

// Get DTE
$doc = $client->getDte('XXXXXXXX-...');

// FACT Combustible — rates set once at init (recommended for gas stations)
$stationClient = new DigifactClient([
    'taxid' => '12345678', 'username' => 'FELUSER', 'password' => 'secret',
    'petroleo_rates' => ['1' => 4.70, '2' => 4.60, '4' => 1.30], // SUPER / REGULAR / DIESEL
]);
// Only petroleo_code needed — petroleo_amount is filled in automatically
$result = $stationClient->fuelInvoice('CF', [
    ['description' => 'GASOLINA SUPER',    'qty' => 30, 'price' => 30.30, 'petroleo_code' => '1', 'type' => 'Bien'],
    ['description' => 'GASOLINA REGULAR',  'qty' => 20, 'price' => 29.40, 'petroleo_code' => '2', 'type' => 'Bien'],
    ['description' => 'GASOLINA DIESEL',   'qty' => 50, 'price' => 30.70, 'petroleo_code' => '4', 'type' => 'Bien'],
    // Regular items (no petroleo_code): IVA only, can coexist
    ['description' => 'FILTRO DE ACEITE',    'qty' => 1, 'price' => 45.00, 'type' => 'Bien'],
    ['description' => 'SET DE CANDELAS NGK', 'qty' => 1, 'price' => 400.00, 'type' => 'Bien'],
]);
echo $result->authNumber;

// Alternative: explicit petroleo_amount per item
$result2 = $client->fuelInvoice('CF', [
    ['description' => 'GASOLINA SUPER', 'qty' => 1, 'price' => 30.30, 'petroleo_amount' => 4.70, 'petroleo_code' => '1', 'type' => 'Bien'],
]);
```

## Fuel invoice item keys

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `description` | `string` | required | Line description |
| `price` | `float` | required | Unit price, IVA-inclusive |
| `qty` | `float` | `1` | Quantity |
| `type` | `string` | `'Servicio'` | `'Bien'` or `'Servicio'` |
| `unit_of_measure` | `string` | `'UNI'` | SAT unit code |
| `petroleo_amount` | `float` | — | Per-unit PETROLEO tax (omit for regular IVA-only items) |
| `petroleo_code` | `string` | `'1'` | `'1'`=SUPER, `'2'`=REGULAR, `'4'`=DIESEL. When set without `petroleo_amount`, the code must resolve to a rate in `petroleo_rates` or a `DigifactValidationException` is thrown. |

## Running tests

```bash
# Unit tests (no credentials)
./vendor/bin/phpunit tests/

# Integration tests
export DIGIFACT_TAXID=12345678
export DIGIFACT_USERNAME=FELUSER
export DIGIFACT_PASSWORD=your_password
./vendor/bin/phpunit tests/
```
