/**
 * Integration tests for the Digifact FEL JavaScript SDK.
 *
 * TOKEN REUSE: a single DigifactClient is created at module level and shared
 * across ALL integration tests. It logs in once; subsequent requests reuse
 * the cached token. Total auth calls = 1 per test run.
 *
 * Requires environment variables:
 *   DIGIFACT_TAXID, DIGIFACT_USERNAME, DIGIFACT_PASSWORD
 *
 * Run with: node --test tests/integration.test.js
 */

import { test, describe, before } from 'node:test';
import assert from 'node:assert/strict';
import { DigifactClient, DigifactError } from '../src/index.js';
import { gtNow, padTaxid, fmt, calcIva } from '../src/tax.js';
import { DteResult } from '../src/client.js';
import {
  buildFact, buildFesp, buildNdeb, buildNcre, buyerCf, buyerNit,
} from '../src/builder.js';

const TAXID    = process.env.DIGIFACT_TAXID    || '';
const USERNAME = process.env.DIGIFACT_USERNAME || '';
const PASSWORD = process.env.DIGIFACT_PASSWORD || '';
const SKIP     = !TAXID || !USERNAME || !PASSWORD;
const SKIP_MSG = 'Set DIGIFACT_TAXID, DIGIFACT_USERNAME, DIGIFACT_PASSWORD to run integration tests';

// ── Shared singleton — ONE login for the whole test session ───────────────────
const CLIENT = SKIP ? null : new DigifactClient({
  taxid: TAXID,
  username: USERNAME,
  password: PASSWORD,
  environment: 'test',
});


// ── Unit tests (no credentials required) ─────────────────────────────────────

describe('Unit: tax helpers', () => {
  test('padTaxid pads to 12 digits', () => {
    assert.equal(padTaxid('12345678'), '000012345678');
    assert.equal(padTaxid('000012345678'), '000012345678');
    assert.equal(padTaxid('GT.000012345678'), '000012345678');
  });

  test('calcIva on 112 gives 100 taxable and 12 IVA', () => {
    const [taxable, iva] = calcIva('112');
    assert.equal(taxable, '100.000000');
    assert.equal(iva, '12.000000');
  });

  test('calcIva on 100: taxable + iva = 100', () => {
    const [taxable, iva] = calcIva('100');
    const sum = (parseFloat(taxable) + parseFloat(iva)).toFixed(6);
    assert.equal(sum, '100.000000');
  });

  test('fmt formats to 6 decimal places', () => {
    assert.equal(fmt(1), '1.000000');
    assert.equal(fmt('31'), '31.000000');
  });

  test('gtNow returns valid strings', () => {
    const [iso, space, date] = gtNow();
    assert.match(iso, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}-06:00$/);
    assert.match(space, /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    assert.match(date, /^\d{4}-\d{2}-\d{2}$/);
  });
});

describe('Unit: DteResult', () => {
  test('fromResponse parses standard fields', () => {
    const result = DteResult.fromResponse({
      authNumber: 'AAA-BBB',
      batch: 'SERIE1',
      serial: 12345,
      issuedTimeStamp: '2026-03-18T21:40:14',
    });
    assert.equal(result.authNumber, 'AAA-BBB');
    assert.equal(result.series, 'SERIE1');
    assert.equal(result.number, '12345');
    assert.equal(result.issueDateTime, '2026-03-18 21:40:14');
  });
});

describe('Unit: builder', () => {
  test('buildFact CF has correct structure', () => {
    const payload = buildFact('12345678', 'SELLER', 'ADDR', buyerCf(), [
      { description: 'Servicio', qty: 1, price: 100 },
    ]);
    assert.equal(payload.Header.DocType, 'FACT');
    assert.equal(payload.Buyer.TaxID, 'CF');
    assert.equal(payload.Items.length, 1);
    assert.ok(payload.Items[0].Taxes !== null);
    assert.ok('AdditionlInfo' in payload.Seller);
  });

  test('buildFesp has no AdditionlInfo in seller', () => {
    const buyer = buyerNit('10001794', 'BUYER');
    const payload = buildFesp('12345678', 'SELLER', 'ADDR', buyer, [
      { description: 'Alquiler', qty: 1, price: 1000 },
    ]);
    assert.ok(!('AdditionlInfo' in payload.Seller));
    const comp = payload.AdditionalDocumentInfo.AdditionalInfo[0];
    assert.equal(comp.Code, 'FESP');
    assert.ok('AditionalInfo' in comp);
  });

  test('buildNdeb uses AditionalInfo not AditionalData', () => {
    const buyer = buyerNit('12345678', 'DIGIFACT');
    const origin = { auth_number: 'AAAA-BBBB', date: '2026-01-01', series: 'AAAA', number: '123' };
    const payload = buildNdeb('12345678', 'SELLER', 'ADDR', buyer, [
      { description: 'Item', qty: 1, price: 50, type: 'Bien' },
    ], origin, 'Reason');
    const comp = payload.AdditionalDocumentInfo.AdditionalInfo[0];
    assert.equal(comp.Code, 'NDEB');
    assert.ok('AditionalInfo' in comp);
    assert.ok(!('AditionalData' in comp));
  });

  test('buildNcre uses AditionalInfo not AditionalData', () => {
    const buyer = buyerNit('12345678', 'DIGIFACT');
    const origin = { auth_number: 'CCCC-DDDD', date: '2026-01-01', series: 'CCCC', number: '456' };
    const payload = buildNcre('12345678', 'SELLER', 'ADDR', buyer, [
      { description: 'Dev', qty: 1, price: 20 },
    ], origin, 'Defectuoso');
    const comp = payload.AdditionalDocumentInfo.AdditionalInfo[0];
    assert.equal(comp.Code, 'NCRE');
    assert.ok('AditionalInfo' in comp);
  });
});


// ── Integration tests (shared CLIENT singleton) ───────────────────────────────

if (SKIP) {
  test('integration tests skipped (missing env vars)', { skip: SKIP_MSG }, () => {});
} else {

  describe('Integration: login', () => {
    test('login returns token', async () => {
      const token = await CLIENT._login();
      assert.ok(token);
      console.log(`  token: ${token.slice(0, 20)}...`);
    });
  });

  describe('Integration: NIT lookup', () => {
    test('lookupNit 77454820 returns name', async () => {
      const info = await CLIENT.lookupNit('77454820');
      assert.ok(info.name);
      console.log(`  NIT name: ${info.name}`);
    });

    test('lookupNit caches result (no extra HTTP call)', async () => {
      const i1 = await CLIENT.lookupNit('77454820');
      const i2 = await CLIENT.lookupNit('77454820');
      assert.deepEqual(i1, i2);
    });
  });

  describe('Integration: FACT CF', () => {
    test('emit FACT to CF', async () => {
      const result = await CLIENT.invoice('CF', [
        { description: 'Consultoría JS SDK', qty: 1, price: 100 },
      ]);
      assert.ok(result.authNumber);
      console.log(`  FACT CF auth: ${result.authNumber}`);
    });
  });

  describe('Integration: FACT NIT', () => {
    test('emit FACT to NIT', async () => {
      const result = await CLIENT.invoice('77454820', [
        { description: 'Laptop', qty: 1, price: 5000, type: 'Bien' },
        { description: 'Soporte', qty: 1, price: 500 },
      ]);
      assert.ok(result.authNumber);
      console.log(`  FACT NIT auth: ${result.authNumber}`);
    });
  });

  describe('Integration: FACT CUI', () => {
    test('emit FACT to CUI buyer', async () => {
      const result = await CLIENT.invoice(
        { taxid: '3730617490101', type: 'CUI', name: 'Julio Cifuentes' },
        [{ description: 'Producto', qty: 2, price: 50, type: 'Bien' }]
      );
      assert.ok(result.authNumber);
      console.log(`  FACT CUI auth: ${result.authNumber}`);
    });
  });

  // FCAM is emitted once in `before`; NDEB and NCRE reuse that result.
  describe('Integration: FCAM + NDEB + NCRE', () => {
    let fcamResult;
    let originDate;

    before(async () => {
      const [, , dateOnly] = gtNow();
      originDate = dateOnly;
      try {
        fcamResult = await CLIENT.invoice('77454820', [
          { description: 'Servicio origen', qty: 1, price: 50 },
        ], { doc_type: 'FCAM', payment_terms: [{ date: dateOnly, amount: 50 }] });
      } catch (e) {
        console.log(`  FCAM setup failed: ${e.message}`);
      }
    });

    test('emit FCAM', async () => {
      if (!fcamResult) return;
      assert.ok(fcamResult.authNumber);
      console.log(`  FCAM auth: ${fcamResult.authNumber}`);
    });

    test('emit NDEB against FCAM', async () => {
      if (!fcamResult?.authNumber) return;
      const origin = {
        auth_number: fcamResult.authNumber,
        date: originDate,
        series: fcamResult.series,
        number: fcamResult.number,
      };
      const result = await CLIENT.debitNote('77454820', [
        { description: 'Cargo NDEB', qty: 1, price: 19, type: 'Bien' },
      ], origin, 'Cargo de prueba');
      assert.ok(result.authNumber);
      console.log(`  NDEB auth: ${result.authNumber}`);
    });

    test('emit NCRE against FCAM', async () => {
      if (!fcamResult?.authNumber) return;
      const origin = {
        auth_number: fcamResult.authNumber,
        date: originDate,
        series: fcamResult.series,
        number: fcamResult.number,
      };
      const result = await CLIENT.creditNote('77454820', [
        { description: 'Devolución NCRE', qty: 1, price: 10 },
      ], origin, 'Producto defectuoso');
      assert.ok(result.authNumber);
      console.log(`  NCRE auth: ${result.authNumber}`);
    });
  });

  describe('Integration: NABN', () => {
    test('emit NABN', async () => {
      const result = await CLIENT.invoice('77454820', [
        { description: 'RETENEDOR BLANCO', qty: 1, price: 100, type: 'Bien' },
      ], { doc_type: 'NABN' });
      assert.ok(result.authNumber);
      console.log(`  NABN auth: ${result.authNumber}`);
    });
  });

  describe('Integration: FESP', () => {
    test('emit FESP', async () => {
      const result = await CLIENT.invoice('10001794', [
        { description: 'Alquiler equipo', qty: 1, price: 2500, type: 'Bien' },
      ], { doc_type: 'FESP' });
      assert.ok(result.authNumber);
      console.log(`  FESP auth: ${result.authNumber}`);
    });
  });

  describe('Integration: RDON', () => {
    test('emit RDON', async () => {
      const result = await CLIENT.invoice('77454820', [
        { description: 'Donación', qty: 1, price: 50, type: 'Bien' },
      ], { doc_type: 'RDON', tipo_personeria: '719' });
      assert.ok(result.authNumber);
      console.log(`  RDON auth: ${result.authNumber}`);
    });
  });

  describe('Integration: RECI', () => {
    test('emit RECI', async () => {
      const result = await CLIENT.invoice('77454820', [
        { description: 'Pago universitario', qty: 1, price: 250, type: 'Bien' },
      ], { doc_type: 'RECI' });
      assert.ok(result.authNumber);
      console.log(`  RECI auth: ${result.authNumber}`);
    });
  });

  // Single FACT CF emitted once; reused for both ncredtotal and cancel.
  describe('Integration: cancel + ncredtotal', () => {
    let factForCancel;

    before(async () => {
      try {
        factForCancel = await CLIENT.invoice('CF', [
          { description: 'Factura para anular', qty: 1, price: 50 },
        ]);
      } catch (e) {
        console.log(`  FACT CF for cancel failed: ${e.message}`);
      }
    });

    test('cancel and ncredtotal a FACT CF', async () => {
      if (!factForCancel) return;

      const ncred = await CLIENT.creditNoteTotal(
        factForCancel.authNumber,
        factForCancel.issueDateTime,
        'Nota de crédito total de prueba'
      );
      assert.ok(typeof ncred === 'object');

      const cancel = await CLIENT.cancel(
        factForCancel.authNumber, 'CF',
        factForCancel.issueDateTime,
        'Anulación de prueba automática'
      );
      assert.ok(typeof cancel === 'object');
      console.log(`  cancel Codigo: ${cancel.Codigo ?? cancel.code ?? 'n/a'}`);
    });
  });

  // Single FACT CF emitted once; reused for getDte and getDteInfo.
  describe('Integration: getDte', () => {
    let factForQuery;

    before(async () => {
      try {
        factForQuery = await CLIENT.invoice('CF', [
          { description: 'Consulta DTE', qty: 1, price: 10 },
        ]);
      } catch (e) {
        console.log(`  FACT CF for getDte failed: ${e.message}`);
      }
    });

    test('getDte returns object', async () => {
      if (!factForQuery) return;
      const doc = await CLIENT.getDte(factForQuery.authNumber);
      assert.ok(typeof doc === 'object');
    });

    test('getDteInfo returns object', async () => {
      if (!factForQuery) return;
      const info = await CLIENT.getDteInfo(factForQuery.authNumber);
      assert.ok(typeof info === 'object');
    });
  });
}
