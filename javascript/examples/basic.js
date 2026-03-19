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

console.log('\nAll examples completed successfully.');
