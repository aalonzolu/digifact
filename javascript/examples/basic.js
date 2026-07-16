#!/usr/bin/env node
/**
 * Basic usage example for the Digifact FEL JavaScript SDK.
 *
 * Set environment variables before running:
 *   export DIGIFACT_TAXID=12345678
 *   export DIGIFACT_USERNAME=FELUSER
 *   export DIGIFACT_PASSWORD=your_password
 *
 * Then:
 *   node examples/basic.js
 */

import { DigifactClient, DigifactError } from '../src/index.js';

const TAXID = process.env.DIGIFACT_TAXID || '';
const USERNAME = process.env.DIGIFACT_USERNAME || '';
const PASSWORD = process.env.DIGIFACT_PASSWORD || '';

if (!TAXID || !USERNAME || !PASSWORD) {
  console.log('Skipping example: set DIGIFACT_TAXID, DIGIFACT_USERNAME, DIGIFACT_PASSWORD');
  process.exit(0);
}

const client = new DigifactClient({
  taxid: TAXID,
  username: USERNAME,
  password: PASSWORD,
  environment: 'test',
  // PETROLEO rates (Q/gallon) — auto-filled in fuelInvoice when petroleo_code is set
  petroleo_rates: { '1': 4.70, '2': 4.60, '4': 1.30 }, // SUPER / REGULAR / DIESEL
});

// ── FACT CF ──
console.log('Emitting FACT CF...');
try {
  const result = await client.invoice('CF', [
    { description: 'Consultoría JS SDK', qty: 1, price: 100 },
  ]);
  console.log(`  auth_number : ${result.authNumber}`);
  console.log(`  series      : ${result.series}`);
  console.log(`  number      : ${result.number}`);
  console.log(`  issued_at   : ${result.issueDateTime}`);
} catch (err) {
  console.error(`  ERROR: ${err.message}`);
  process.exit(1);
}

// ── FACT NIT ──
console.log('\nEmitting FACT to NIT 77454820...');
try {
  const result2 = await client.invoice('77454820', [
    { description: 'Laptop', qty: 1, price: 5000, type: 'Bien' },
    { description: 'Soporte anual', qty: 1, price: 500 },
  ]);
  console.log(`  auth_number : ${result2.authNumber}`);
} catch (err) {
  console.error(`  ERROR: ${err.message}`);
}

// ── NIT Lookup ──
console.log('\nLooking up NIT 77454820...');
try {
  const info = await client.lookupNit('77454820');
  console.log(`  name    : ${info.name}`);
  console.log(`  address : ${info.address}`);
} catch (err) {
  console.error(`  ERROR: ${err.message}`);
}

// ── CUI Lookup ──
console.log('\nLooking up CUI 1234567890123...');
try {
  const info = await client.lookupCui('1234567890123');
  console.log(`  name   : ${info.name}`);
  console.log(`  status : ${info.status}`);
} catch (err) {
  console.error(`  ERROR: ${err.message}`);
}

// ── FACT to a CUI (DPI) buyer — name resolved automatically ──
console.log('\nEmitting FACT to a CUI buyer...');
try {
  const result = await client.invoice(
    { taxid: '1234567890123', type: 'CUI' },
    [{ description: 'Producto', qty: 1, price: 75.00, type: 'Bien' }],
  );
  console.log(`  auth: ${result.authNumber}`);
} catch (err) {
  console.error(`  ERROR: ${err.message}`);
}

// ── FACT Combustible ──
// For gas stations, set petroleo_rates at init so you don't repeat petroleo_amount on each item.
console.log('\nEmitting FACT Combustible...');
try {
  const resultFuel = await client.fuelInvoice('CF', [
    // Only petroleo_code needed when petroleo_rates was set at client init.
    // petroleo_code: '1'=SUPER, '2'=REGULAR, '4'=DIESEL
    { description: 'GASOLINA SUPER',    qty: 1, price: 35.00, petroleo_code: '1', type: 'Bien' },
    { description: 'GASOLINA REGULAR',  qty: 1, price: 34.00, petroleo_code: '2', type: 'Bien' },
    { description: 'GASOLINA DIESEL',   qty: 1, price: 32.00, petroleo_code: '4', type: 'Bien' },
    // Regular items (no petroleo_code): IVA only
    { description: 'FILTRO DE ACEITE',   qty: 1, price: 45.00,  type: 'Bien' },
    { description: 'SET DE CANDELAS NGK', qty: 1, price: 400.00, type: 'Bien' },
  ]);
  console.log(`  auth_number : ${resultFuel.authNumber}`);
  console.log(`  series      : ${resultFuel.series}`);
  console.log(`  number      : ${resultFuel.number}`);
} catch (err) {
  console.error(`  ERROR: ${err.message}`);
}

console.log('\nAll examples completed successfully.');
