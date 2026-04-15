# Digifact FEL Guatemala — JavaScript SDK

JavaScript (Node 18+) SDK for the [Digifact](https://www.digifact.com.gt/) FEL Guatemala e-invoicing API.

No runtime dependencies — uses native `fetch` (Node 18+).

## Quick start

```javascript
import { DigifactClient } from './src/index.js';

const client = new DigifactClient({
  taxid: '12345678',
  username: 'FELUSER',
  password: 'secret',
  environment: 'test',  // or 'production'
});

// FACT CF
const result = await client.invoice('CF', [
  { description: 'Consultoría', qty: 1, price: 100.00 }
]);
console.log(result.authNumber);

// FACT NIT (buyer name fetched automatically from SAT)
const result2 = await client.invoice('12345678', [
  { description: 'Laptop', qty: 1, price: 5000.00, type: 'Bien' },
  { description: 'Soporte', qty: 1, price: 500.00 },
]);

// FACT CUI buyer
const result3 = await client.invoice(
  { taxid: '3730617490101', type: 'CUI', name: 'Juan Pérez' },
  [{ description: 'Producto', qty: 2, price: 50.00 }]
);

// Full NIT buyer with explicit details (no auto-lookup)
const result3b = await client.invoice(
  {
    taxid:    '12345678',
    name:     'EMPRESA EJEMPLO S.A.',
    address:  '6 AV 6-48 ZONA 9',
    city:     '01009',
    district: 'GUATEMALA',
    state:    'GUATEMALA',
    country:  'GT',
    email:    'facturacion@empresa.com',  // optional
  },
  [{ description: 'Producto', qty: 1, price: 100.00 }]
);

// FCAM (Factura Cambiaria)
const result4 = await client.invoice('12345678', [
  { description: 'Servicio', qty: 1, price: 500.00 }
], {
  doc_type: 'FCAM',
  payment_terms: [{ date: '2026-04-18', amount: 500.00 }],
});

// Credit note (NCRE)
const ncre = await client.creditNote('12345678', [
  { description: 'Devolución', qty: 1, price: 100.00 }
], {
  auth_number: 'XXXXXXXX-...',
  date: '2026-03-18',
  series: 'XXXXXXXX',
  number: '123456',
}, 'Producto defectuoso');

// Debit note (NDEB)
const ndeb = await client.debitNote('12345678', [...], origin, 'Cargo extra');

// Cancel
const cancel = await client.cancel('XXXXXXXX-...', 'CF', '2026-03-18 21:40:14', 'Error en monto');

// NIT lookup
const info = await client.lookupNit('12345678');
console.log(info.name);

// Get DTE
const doc = await client.getDte('XXXXXXXX-...');

// FACT Combustible — rates set once at init (recommended for gas stations)
const stationClient = new DigifactClient({
  taxid: '12345678', username: 'FELUSER', password: 'secret',
  petroleo_rates: { '1': 4.70, '2': 4.60, '4': 1.30 }, // SUPER / REGULAR / DIESEL
});
// Only petroleo_code needed — petroleo_amount filled in automatically
const fuel = await stationClient.fuelInvoice('CF', [
  { description: 'GASOLINA SUPER',    qty: 30, price: 35.00, petroleo_code: '1', type: 'Bien' },
  { description: 'GASOLINA REGULAR',  qty: 20, price: 34.00, petroleo_code: '2', type: 'Bien' },
  { description: 'GASOLINA DIESEL',   qty: 50, price: 32.00, petroleo_code: '4', type: 'Bien' },
  // Regular items (no petroleo_code): IVA only, can coexist
  { description: 'FILTRO DE ACEITE',    qty: 1, price: 45.00, type: 'Bien' },
  { description: 'SET DE CANDELAS NGK', qty: 1, price: 400.00, type: 'Bien' },
]);
console.log(fuel.authNumber);

// Alternative: explicit petroleo_amount per item (no petroleo_rates needed)
const fuel2 = await client.fuelInvoice('CF', [
  { description: 'GASOLINA SUPER', qty: 1, price: 35.00, petroleo_amount: 4.70, petroleo_code: '1', type: 'Bien' },
]);
```

## Fuel invoice item keys

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `description` | `string` | required | Line description |
| `price` | `number` | required | Full consumer price per unit (PETROLEO + IVA-inclusive) |
| `qty` | `number` | `1` | Quantity |
| `type` | `string` | `'Servicio'` | `'Bien'` or `'Servicio'` |
| `unitOfMeasure` | `string` | `'UNI'` | SAT unit code |
| `petroleo_amount` | `number` | — | Per-unit PETROLEO tax (omit for regular IVA-only items) |
| `petroleo_code` | `string` | `'1'` | `'1'`=SUPER, `'2'`=REGULAR, `'4'`=DIESEL. When set without `petroleo_amount`, the code must resolve to a rate in `petroleo_rates` or a `DigifactValidationError` is thrown. |

## Running tests

```bash
# Unit tests (no credentials)
node --test --test-name-pattern='Unit' tests/integration.test.js

# All tests including integration
export DIGIFACT_TAXID=12345678
export DIGIFACT_USERNAME=FELUSER
export DIGIFACT_PASSWORD=your_password
npm test
```

## Requirements

- Node.js 18+ (uses native `fetch` and `BigInt`)
- No npm dependencies required
