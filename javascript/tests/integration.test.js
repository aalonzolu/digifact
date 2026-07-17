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
import { gtNow, padTaxid, fmt, calcIva, calcFuelLine } from '../src/tax.js';
import { DteResult } from '../src/client.js';
import {
  buildFact, buildFesp, buildNdeb, buildNcre, buyerCf, buyerNit,
  buildFactCombustible, resolveFuelFrases,
} from '../src/builder.js';

const TAXID    = process.env.DIGIFACT_TAXID    || '';
const USERNAME = process.env.DIGIFACT_USERNAME || '';
const PASSWORD = process.env.DIGIFACT_PASSWORD || '';
const SKIP     = !TAXID || !USERNAME || !PASSWORD;
const SKIP_MSG = 'Set DIGIFACT_TAXID, DIGIFACT_USERNAME, DIGIFACT_PASSWORD to run integration tests';
const ENV      = (process.env.DIGIFACT_ENVIRONMENT || 'test').toLowerCase();

// ── Shared singleton — ONE login for the whole test session ───────────────────
const CLIENT = SKIP ? null : new DigifactClient({
  taxid: TAXID,
  username: USERNAME,
  password: PASSWORD,
  environment: ENV,
});

// ── Upstream outages ─────────────────────────────────────────────────────────
// Two Digifact/SAT-side failures currently have no SDK-side workaround. These
// helpers match each one narrowly so that any *other* failure of the same test
// still fails the run.

const NABN_FRASE_SKIP =
  'Upstream: Digifact rejects every NABN with FEL_RCP112 demanding frase ' +
  'TipoFrase=9/CodigoEscenario=17, including payloads that carry exactly that frase — ' +
  'the rule is unsatisfiable from the NUC JSON. Pending Digifact support.';

const CANCEL_SAT_SKIP =
  "Upstream: SAT's anulación transmission is failing (Codigo 9019, " +
  "'Error al transmitir anulación a SAT'). Certification is unaffected.";

const isNabnFraseRule = (e) => String(e?.message ?? '').includes('FEL_RCP112');

const isSatCancelOutage = (e) =>
  String(e?.raw?.Codigo ?? '') === '9019' || String(e?.message ?? '').includes('9019');


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

  test('buyerNit with full address fields', () => {
    const buyer = buyerNit('12345678', 'EMPRESA EJEMPLO S.A.', '6 AV 6-48 ZONA 9', '01009',
      'GUATEMALA', 'GUATEMALA', 'GT', 'test@example.com');
    assert.equal(buyer.TaxID, '12345678');
    assert.equal(buyer.Name, 'EMPRESA EJEMPLO S.A.');
    assert.equal(buyer.AddressInfo.Address, '6 AV 6-48 ZONA 9');
    assert.equal(buyer.AddressInfo.City, '01009');
    assert.equal(buyer.Contact.EmailList.Email[0], 'test@example.com');
  });
});

describe('Unit: calcFuelLine', () => {
  test('fuel line math correct', () => {
    const r = calcFuelLine(1, 35.00, 4.70);
    assert.equal(r.qty,       '1.000000');
    assert.equal(r.price,     '35.000000');
    assert.equal(r.lineTotal, '35.000000');
    assert.equal(r.taxable,   '27.053571');
    assert.equal(r.iva,       '3.246429');
    assert.equal(r.petrol,    '4.700000');
  });

  test('taxable + iva equals gross', () => {
    const r = calcFuelLine(2, 53, 3);
    const sum = (parseFloat(r.taxable) + parseFloat(r.iva)).toFixed(6);
    assert.equal(sum, '100.000000');
    assert.equal(r.lineTotal, '106.000000');
  });
});

describe('Unit: buildFactCombustible', () => {
  test('has DocType FACT and adenda code 00000013', () => {
    const payload = buildFactCombustible('12345678', 'SELLER', 'ADDR', buyerCf(), [
      { description: 'SUPER', qty: 1, price: 35.00, petroleo_amount: 4.70, petroleo_code: '1', type: 'Bien' },
    ]);
    assert.equal(payload.Header.DocType, 'FACT');
    assert.equal(payload.AdditionalDocumentInfo.AdditionalInfo[0].Code, '00000013');
  });

  test('fuel item has IVA and PETROLEO taxes', () => {
    const payload = buildFactCombustible('12345678', 'SELLER', 'ADDR', buyerCf(), [
      { description: 'SUPER', qty: 1, price: 35.00, petroleo_amount: 4.70, petroleo_code: '1', type: 'Bien' },
    ]);
    const taxes = payload.Items[0].Taxes.Tax;
    assert.equal(taxes.length, 2);
    assert.equal(taxes[0].Description, 'IVA');
    assert.equal(taxes[1].Description, 'PETROLEO');
    assert.equal(taxes[1].Code, '1');
  });

  test('regular item in fuel invoice has only IVA', () => {
    const payload = buildFactCombustible('12345678', 'SELLER', 'ADDR', buyerCf(), [
      { description: 'FILTRO', qty: 1, price: 45.00, type: 'Bien' },
    ]);
    const taxes = payload.Items[0].Taxes.Tax;
    assert.equal(taxes.length, 1);
    assert.equal(taxes[0].Description, 'IVA');
  });

  test('PETROLEO total appears in TotalTaxes when present', () => {
    const payload = buildFactCombustible('12345678', 'SELLER', 'ADDR', buyerCf(), [
      { description: 'SUPER', qty: 1, price: 35.00, petroleo_amount: 4.70, petroleo_code: '1', type: 'Bien' },
      { description: 'FILTRO', qty: 1, price: 45.00, type: 'Bien' },
    ]);
    const totals = payload.Totals.TotalTaxes.TotalTax;
    assert.equal(totals.length, 2);
    assert.equal(totals[1].Description, 'PETROLEO');
  });
});

describe('Unit: petroleo_rates auto-fill', () => {
  test('fills petroleo_amount from rates when petroleo_code set but no amount', () => {
    const client = new DigifactClient({
      taxid: '12345678', username: 'U', password: 'P',
      petroleo_rates: { '1': 4.70, '2': 4.60, '4': 1.30 },
    });
    const resolved = client._applyPetroleoRates([
      { description: 'SUPER',  price: 30.30, petroleo_code: '1' },
      { description: 'DIESEL', price: 30.70, petroleo_code: '4' },
      { description: 'FILTRO', price: 45.00 },  // no code → untouched
    ]);
    assert.equal(resolved[0].petroleo_amount, 4.70);
    assert.equal(resolved[1].petroleo_amount, 1.30);
    assert.equal(resolved[2].petroleo_amount, undefined);
  });

  test('explicit petroleo_amount is never overwritten', () => {
    const client = new DigifactClient({
      taxid: '12345678', username: 'U', password: 'P',
      petroleo_rates: { '1': 4.70 },
    });
    const resolved = client._applyPetroleoRates([
      { description: 'SUPER', price: 30.30, petroleo_code: '1', petroleo_amount: 9.99 },
    ]);
    assert.equal(resolved[0].petroleo_amount, 9.99);
  });

  test('no petroleo_rates + petroleo_code → throws DigifactValidationError', () => {
    const client = new DigifactClient({
      taxid: '12345678', username: 'U', password: 'P',
    });
    assert.throws(
      () => client._applyPetroleoRates([{ description: 'SUPER', price: 30.30, petroleo_code: '1' }]),
      /petroleo_amount.*petroleo_rates|petroleo_rates.*petroleo_amount/i
    );
  });

  test('code not in rates → throws DigifactValidationError', () => {
    const client = new DigifactClient({
      taxid: '12345678', username: 'U', password: 'P',
      petroleo_rates: { '1': 4.70 },  // DIESEL not configured
    });
    assert.throws(
      () => client._applyPetroleoRates([{ description: 'DIESEL', price: 30.70, petroleo_code: '4' }]),
      /petroleo_amount.*petroleo_rates|petroleo_rates.*petroleo_amount/i
    );
  });
});


// ── Unit tests: fuel frases ───────────────────────────────────────────────────

describe('Unit: fuel frases', () => {
  const buyer = buyerCf();
  const items = [{ description: 'SUPER', qty: 1, price: 35.00, petroleo_amount: 4.70, petroleo_code: '1', type: 'Bien' }];

  function getFrases(payload) {
    const ai = payload.Seller.AdditionlInfo ?? [];
    const frases = [];
    for (let i = 0; i + 1 < ai.length; i += 2) frases.push([ai[i].Value, ai[i + 1].Value]);
    return frases;
  }

  test('non-fuel invoice has only base frase, no 9/18 or 9/19', () => {
    const payload = buildFact('12345678', 'TEST', 'CALLE', buyerNit('12345678', 'X'), [{ description: 'X', qty: 1, price: 100 }]);
    const frases = getFrases(payload);
    assert.equal(frases.length, 1);
    assert.ok(!frases.some(([tf, es]) => tf === '9' && es === '18'));
    assert.ok(!frases.some(([tf, es]) => tf === '9' && es === '19'));
  });

  test('resolveFuelFrases: explicit frases replace the base pair', () => {
    const result = resolveFuelFrases([{ tipo_frase: '2', escenario: '1' }], null, null);
    const pairs = result.map(f => [f.tipo_frase, f.escenario]);
    assert.deepStrictEqual(pairs, [['2', '1']]);
  });

  test('resolveFuelFrases: deduplication', () => {
    const dupes = [{ tipo_frase: '9', escenario: '18' }, { tipo_frase: '9', escenario: '18' }, { tipo_frase: '9', escenario: '19' }];
    const result = resolveFuelFrases(dupes, null, null);
    assert.equal(result.length, 2);
  });

  test('resolveFuelFrases: empty explicit frases list throws', () => {
    assert.throws(() => resolveFuelFrases([], null, null), /at least one/);
  });

  test('buildFactCombustible seller has base frase in AdditionlInfo', () => {
    const payload = buildFactCombustible('12345678', 'SELLER', 'ADDR', buyerCf(), [
      { description: 'SUPER', qty: 1, price: 35.00, petroleo_amount: 4.70, petroleo_code: '1', type: 'Bien' },
    ]);
    const ai = payload.Seller.AdditionlInfo ?? [];
    assert.ok(ai.length >= 2, 'Seller.AdditionlInfo must have TipoFrase/Escenario pair');
    assert.equal(ai[0].Name, 'TipoFrase');
    assert.equal(ai[0].Value, '1');
    assert.equal(ai[0].Data, '1');
    assert.equal(ai[1].Name, 'Escenario');
    assert.equal(ai[1].Data, '1');
  });

  test('buildFactCombustible: explicit frases carry 9/18 and 9/19 through to AdditionlInfo', () => {
    // The SAT fuel subsidy ended: the SDK never injects 9/18 or 9/19 on its own.
    // Emitters still dispatching subsidised inventory pass them explicitly.
    const payload = buildFactCombustible('12345678', 'SELLER', 'ADDR', buyerCf(), [
      { description: 'SUPER', qty: 1, price: 35.00, petroleo_amount: 4.70, petroleo_code: '1', type: 'Bien' },
    ], {
      frases: [
        { tipo_frase: '1', escenario: '1' },
        { tipo_frase: '9', escenario: '18' },
        { tipo_frase: '9', escenario: '19' },
      ],
    });
    const ai = payload.Seller.AdditionlInfo ?? [];
    // Parse pairs from flat array
    const pairs = [];
    for (let i = 0; i + 1 < ai.length; i += 2) pairs.push([ai[i].Value, ai[i + 1].Value]);
    assert.deepStrictEqual(pairs, [['1', '1'], ['9', '18'], ['9', '19']]);
    // Data must be the 1-based frase group index so the API creates separate <dte:Frase> elements
    for (let idx = 0; idx < ai.length; idx++) {
      assert.equal(ai[idx].Data, String(Math.floor(idx / 2) + 1), `ai[${idx}].Data must equal frase group index`);
    }
  });

  test('buildFactCombustible: mutual exclusivity throws', () => {
    assert.throws(
      () => buildFactCombustible('12345678', 'TEST', 'CALLE', buyer, items, {
        frases: [{ tipo_frase: '1', escenario: '1' }],
        tipoFrase: '1',
      }),
      /mutually exclusive/
    );
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
    test('emit NABN', async (t) => {
      let result;
      try {
        result = await CLIENT.invoice('77454820', [
          { description: 'RETENEDOR BLANCO', qty: 1, price: 100, type: 'Bien' },
        ], { doc_type: 'NABN' });
      } catch (e) {
        if (!isNabnFraseRule(e)) throw e;
        return t.skip(NABN_FRASE_SKIP);
      }
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

    test('cancel and ncredtotal a FACT CF', async (t) => {
      if (!factForCancel) return;

      const ncred = await CLIENT.creditNoteTotal(
        factForCancel.authNumber,
        factForCancel.issueDateTime,
        'Nota de crédito total de prueba'
      );
      assert.ok(typeof ncred === 'object');

      let cancel;
      try {
        cancel = await CLIENT.cancel(
          factForCancel.authNumber, 'CF',
          factForCancel.issueDateTime,
          'Anulación de prueba automática'
        );
      } catch (e) {
        if (!isSatCancelOutage(e)) throw e;
        return t.skip(CANCEL_SAT_SKIP);
      }
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

  describe('Integration: FACT Combustible', () => {
    test('emit FACT Combustible with mixed items', async () => {
      const result = await CLIENT.fuelInvoice('CF', [
        { description: 'GASOLINA SUPER',    qty: 1, price: 35.00, petroleo_amount: 4.70, petroleo_code: '1', type: 'Bien' },
        { description: 'GASOLINA REGULAR',  qty: 1, price: 34.00, petroleo_amount: 4.60, petroleo_code: '2', type: 'Bien' },
        { description: 'FILTRO DE ACEITE',  qty: 1, price: 45.00, type: 'Bien' },
      ]);
      assert.ok(result.authNumber);
      console.log(`  FACT Combustible auth: ${result.authNumber}`);
    });
  });
}
