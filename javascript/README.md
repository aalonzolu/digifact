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
```

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
