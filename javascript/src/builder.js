/**
 * DTE payload builders for all supported Digifact FEL document types.
 *
 * Key intentional API typos preserved from the SAT/Digifact spec:
 *   - Seller.AdditionlInfo  (missing 'a' in Additional)
 *   - AditionalData         (missing 'd')
 *   - AditionalInfo         (missing 'd')
 */

import { gtNow, calcLine, calcFuelLine, fmt } from './tax.js';

const NO_IVA_TYPES = new Set(['FPEQ', 'NABN', 'RDON', 'RECI']);
const ADENDA_CODE_STD = 'FRONT-263C-444B-89BA-6F87EC1330C0';
const ADENDA_CODE_ALT = 'FRONT-67C1-4545-BA1E-AA3C115E18D6';
const ALT_ADENDA_TYPES = new Set(['NABN', 'RDON', 'RECI']);

function adendaCode(docType) {
  return ALT_ADENDA_TYPES.has(docType) ? ADENDA_CODE_ALT : ADENDA_CODE_STD;
}

/**
 * Default (TipoFrase, CodigoEscenario) for a (docType, afiliacion) combo.
 * Returns null when the DTE type does not carry frases (e.g. FESP).
 * Callers may override either component.
 *
 * @param {string} docType
 * @param {string} [afiliacion='GEN']
 * @returns {[string, string] | null}
 */
export function defaultFrase(docType, afiliacion = 'GEN') {
  const afi = String(afiliacion || 'GEN').toUpperCase();
  if (docType === 'FESP') return null;
  if (docType === 'FPEQ') return ['2', '1'];
  if (docType === 'RDON') return ['4', '4'];
  if (docType === 'RECI') return ['4', '5'];
  if (docType === 'NABN') return ['1', '1'];
  // FACT, NCRE, NDEB, FCAM, FACT+CCA, FACT+combustible
  if (afi === 'PEQ') return ['2', '1'];
  if (afi === 'EXE') return ['4', '1'];
  // GEN — default ISR régimen sobre utilidades trimestrales (UTI) -> '1'.
  // Override to '2' for ISR régimen opcional simplificado (OPC).
  return ['1', '1'];
}

function resolveFrase(docType, afiliacion, tipoFrase, escenario) {
  const def = defaultFrase(docType, afiliacion);
  const [defTf, defEs] = def || [null, null];
  return [
    tipoFrase !== undefined && tipoFrase !== null ? tipoFrase : defTf,
    escenario !== undefined && escenario !== null ? escenario : defEs,
  ];
}

// ── Fuel subsidy frases (Tipo 9, Escenarios 18 / 19) ─────────────────────────

export const FUEL_SUBSIDY_START = new Date('2026-04-27T00:00:00');
export const FUEL_SUBSIDY_END   = new Date('2026-07-27T00:00:00'); // exclusive

export function uniqFrases(frases) {
  const seen = new Set();
  const out = [];
  for (const f of frases) {
    const key = `${f.tipo_frase}:${f.escenario}`;
    if (!seen.has(key)) { seen.add(key); out.push(f); }
  }
  return out;
}

function buildAdditionlInfoFromFrases(frases) {
  const out = [];
  for (const f of frases) {
    out.push({ Name: 'TipoFrase', Data: '1', Value: String(f.tipo_frase) });
    out.push({ Name: 'Escenario', Data: '1', Value: String(f.escenario) });
  }
  return out;
}

export function withinSubsidyWindow(issueDtIso) {
  try {
    const d = new Date(issueDtIso.slice(0, 10) + 'T00:00:00');
    return d >= FUEL_SUBSIDY_START && d < FUEL_SUBSIDY_END;
  } catch {
    return false;
  }
}

/**
 * Return the final deduplicated frases list for a fuel invoice.
 * Called only after mutual-exclusivity validation.
 *
 * @param {Array|null} frases  Explicit list or null.
 * @param {string|null} tipoFrase  Legacy base TipoFrase or null.
 * @param {string|null} escenario  Legacy base Escenario or null.
 * @param {string} issueDtIso  ISO datetime string of the invoice.
 * @param {boolean} [autoEnabled=true]
 * @returns {Array<{tipo_frase:string, escenario:string}>}
 */
export function resolveFuelFrases(frases, tipoFrase, escenario, issueDtIso, autoEnabled = true) {
  if (frases != null) return uniqFrases(frases);
  const result = [];
  if (tipoFrase != null && escenario != null) {
    result.push({ tipo_frase: tipoFrase, escenario });
  }
  if (autoEnabled && withinSubsidyWindow(issueDtIso)) {
    result.push({ tipo_frase: '9', escenario: '18' });
    result.push({ tipo_frase: '9', escenario: '19' });
  }
  return uniqFrases(result);
}

// ── Buyer helpers ─────────────────────────────────────────────────────────────

export function buyerCf() {
  return {
    TaxID: 'CF',
    Name: 'CONSUMIDOR FINAL',
    AddressInfo: {
      Address: 'CIUDAD', City: '01010', District: 'GUATEMALA', State: 'GUATEMALA', Country: 'GT',
    },
  };
}

export function buyerNit(nit, name, address = 'CIUDAD', city = '01010', district = 'GUATEMALA', state = 'GUATEMALA', country = 'GT', email = null) {
  const buyer = {
    TaxID: nit,
    Name: name,
    AddressInfo: { Address: address, City: city, District: district, State: state, Country: country },
  };
  if (email) buyer.Contact = { EmailList: { Email: [email] } };
  return buyer;
}

export function buyerCui(taxid, name) {
  return {
    TaxID: taxid,
    TaxIDType: 'CUI',
    Name: name,
    AddressInfo: { Address: 'CIUDAD', City: '01010', District: 'GUATEMALA', State: 'GUATEMALA', Country: 'GT' },
  };
}

// ── Seller helper ─────────────────────────────────────────────────────────────

function buildSeller(taxid, name, address, {
  afiliacion = 'GEN',
  tipoFrase = '1',
  escenario = '1',
  frases = null,
  branchCode = '1',
  branchName = 'ESTABLECIMIENTO PRINCIPAL',
  city = '01010',
  district = 'Guatemala',
  state = 'Guatemala',
  country = 'GT',
  email = null,
} = {}) {
  const seller = {
    TaxID: taxid,
    TaxIDAdditionalInfo: [{ Name: 'AfiliacionIVA', Data: null, Value: afiliacion }],
    Name: name,
    BranchInfo: {
      Code: branchCode,
      Name: branchName,
      AddressInfo: { Address: address, City: city, District: district, State: state, Country: country },
    },
  };
  if (email) seller.Contact = { EmailList: { Email: [email] } };
  // AdditionlInfo — intentional typo per SAT/Digifact spec
  if (frases != null) {
    if (frases.length > 0) seller.AdditionlInfo = buildAdditionlInfoFromFrases(frases);
  } else if (tipoFrase !== null && escenario !== null) {
    seller.AdditionlInfo = [
      { Name: 'TipoFrase', Data: '1', Value: tipoFrase },
      { Name: 'Escenario', Data: '1', Value: escenario },
    ];
  }
  return seller;
}

// ── Items builder ─────────────────────────────────────────────────────────────

function buildItems(items, taxable = true) {
  const lineItems = [];
  let grandTotal = 0n;
  let totalIva = 0n;

  // Import BigInt scaling factor
  const SCALE_FACTOR = 10_000_000_000n;

  for (let i = 0; i < items.length; i++) {
    const item = items[i];
    const qty = item.qty ?? 1;
    const price = item.price;
    const itemType = item.type ?? 'Servicio';
    const uom = item.unit_of_measure ?? 'UNI';
    const desc = item.description;
    const discount = item.discount ?? 0;

    const calc = calcLine(qty, price, taxable, discount);

    const built = {
      Number: String(i + 1),
      Codes: null,
      Type: itemType,
      Description: desc,
      Qty: calc.qty,
      UnitOfMeasure: uom,
      Price: calc.price,
    };

    if (Number(discount) > 0) {
      built.Discounts = { Discount: [{ Amount: calc.discount }] };
    } else {
      built.Discounts = null;
    }

    if (taxable) {
      built.Taxes = {
        Tax: [{
          Code: '1',
          Description: 'IVA',
          TaxableAmount: calc.taxable,
          Amount: calc.iva,
        }],
      };
    } else {
      built.Taxes = null;
    }

    built.Totals = { TotalItem: calc.lineTotal };
    lineItems.push(built);

    // Accumulate totals using string addition to avoid float issues
    grandTotal = addDecimalStrings(grandTotal === 0n ? '0' : null, calc.lineTotal, grandTotal);
    if (taxable) totalIva = addDecimalStrings(null, calc.iva, totalIva);
  }

  return { lineItems, grandTotal: fmtAcc(grandTotal), totalIva: fmtAcc(totalIva) };
}

// Accumulator helpers using simple string addition via scaled integers
let _accScaleFactor = 1_000_000_000_000n; // 12 decimals for accumulation

function addDecimalStrings(ignored, str, acc) {
  const [intP, fracP = ''] = str.split('.');
  const frac = fracP.padEnd(12, '0').slice(0, 12);
  const val = BigInt(intP) * _accScaleFactor + BigInt(frac);
  return acc + val;
}

function fmtAcc(acc) {
  const factor = _accScaleFactor / 1_000_000n; // down to 6 decimals
  const half = factor / 2n;
  const rounded = (acc + half) / factor;
  const ip = rounded / 1_000_000n;
  const fp = rounded % 1_000_000n;
  return `${ip}.${String(fp).padStart(6, '0')}`;
}

function buildTotals(grandTotal, totalIva, taxable) {
  const totals = {};
  if (taxable) {
    totals.TotalTaxes = { TotalTax: [{ Description: 'IVA', Amount: totalIva }] };
  }
  totals.GrandTotal = { InvoiceTotal: grandTotal };
  return totals;
}

function buildAdenda(docType, amountStr, numItems, observaciones = '-', extraAditionalInfo = []) {
  const code = adendaCode(docType);
  const data = [
    {
      Name: 'INFORMACION_ADICIONAL',
      Info: [
        { Name: 'OBSERVACIONES', Data: null, Value: observaciones },
        { Name: 'CANTIDAD_LETRAS', Data: null, Value: amountStr },
      ],
    },
  ];
  for (let i = 1; i <= numItems; i++) {
    data.push({
      Name: 'DetallesAux_Detalle',
      Info: [
        { Name: 'NumeroLinea', Data: null, Value: String(i) },
        { Name: 'Descripcion_Adicional', Data: null, Value: '-' },
        { Name: 'CodigoEAN', Data: null, Value: '00001' },
        { Name: 'CategoriaAdicional', Data: null, Value: '-' },
      ],
    });
  }

  const aditionalInfo = [
    { Name: 'VALIDAR_REFERENCIA_INTERNA', Data: null, Value: 'NO_VALIDAR' },
    ...extraAditionalInfo,
  ];

  return {
    AdditionalInfo: [{
      Code: code,
      Type: 'ADENDA',
      AditionalData: { Data: data },
      AditionalInfo: aditionalInfo,
    }],
  };
}

// ── Public builders ───────────────────────────────────────────────────────────

export function buildFact(taxid, sellerName, sellerAddress, buyer, items, {
  docType = 'FACT',
  afiliacion = 'GEN',
  tipoFrase = null,
  escenario = null,
  amountStr = '',
  observaciones = '-',
  extraHeader = null,
  sellerEmail = null,
} = {}) {
  const [isoNow] = gtNow();
  const taxable = !NO_IVA_TYPES.has(docType);
  const { lineItems, grandTotal, totalIva } = buildItems(items, taxable);
  const [tf, es] = resolveFrase(docType, afiliacion, tipoFrase, escenario);
  const seller = buildSeller(taxid, sellerName, sellerAddress, { afiliacion, tipoFrase: tf, escenario: es, email: sellerEmail });
  const header = { DocType: docType, IssuedDateTime: isoNow, Currency: 'GTQ', ...(extraHeader || {}) };
  const amt = amountStr || grandTotal;
  const adenda = buildAdenda(docType, amt, items.length, observaciones);

  return {
    Version: '1.00', CountryCode: 'GT', Header: header,
    Seller: seller, Buyer: buyer, ThirdParties: null,
    Items: lineItems, Totals: buildTotals(grandTotal, totalIva, taxable),
    AdditionalDocumentInfo: adenda,
  };
}

export function buildFcam(taxid, sellerName, sellerAddress, buyer, items, paymentTerms, {
  afiliacion = 'GEN', sellerEmail = null, tipoFrase = null, escenario = null,
} = {}) {
  const [isoNow] = gtNow();
  const { lineItems, grandTotal, totalIva } = buildItems(items, true);
  const [tf, es] = resolveFrase('FCAM', afiliacion, tipoFrase, escenario);
  const seller = buildSeller(taxid, sellerName, sellerAddress, { afiliacion, tipoFrase: tf, escenario: es, email: sellerEmail });

  const fcamData = paymentTerms.map((pt, idx) => ({
    Info: [
      { Name: 'NumeroAbono', Data: null, Value: String(idx + 1) },
      { Name: 'FechaVencimiento', Data: null, Value: pt.date },
      { Name: 'MontoAbono', Data: null, Value: fmt(pt.amount, 2) },
    ],
  }));

  return {
    Version: '1.00', CountryCode: 'GT',
    Header: { DocType: 'FCAM', IssuedDateTime: isoNow, Currency: 'GTQ' },
    Seller: seller, Buyer: buyer, ThirdParties: null,
    Items: lineItems, Totals: buildTotals(grandTotal, totalIva, true),
    AdditionalDocumentInfo: {
      AdditionalInfo: [{
        Code: 'FCAMB', Type: 'COMPLEMENTO',
        AditionalData: { Data: fcamData },
      }],
    },
  };
}

export function buildNdeb(taxid, sellerName, sellerAddress, buyer, items, origin, reason, {
  afiliacion = 'GEN', sellerEmail = null, tipoFrase = null, escenario = null,
} = {}) {
  const [isoNow] = gtNow();
  const { lineItems, grandTotal, totalIva } = buildItems(items, true);
  const [tf, es] = resolveFrase('NDEB', afiliacion, tipoFrase, escenario);
  const seller = buildSeller(taxid, sellerName, sellerAddress, { afiliacion, tipoFrase: tf, escenario: es, email: sellerEmail });

  return {
    Version: '1.00', CountryCode: 'GT',
    Header: { DocType: 'NDEB', IssuedDateTime: isoNow, Currency: 'GTQ' },
    Seller: seller, Buyer: buyer, ThirdParties: null,
    Items: lineItems, Totals: buildTotals(grandTotal, totalIva, true),
    AdditionalDocumentInfo: {
      AdditionalInfo: [{
        Code: 'NDEB', Type: 'COMPLEMENTO',
        // NDEB uses AditionalInfo (not AditionalData)
        AditionalInfo: [
          { Name: 'NumeroAutorizacionDocumentoOrigen', Data: null, Value: origin.auth_number },
          { Name: 'FechaEmisionDocumentoOrigen', Data: null, Value: origin.date },
          { Name: 'MotivoAjuste', Data: null, Value: reason },
          { Name: 'SerieDocumentoOrigen', Data: null, Value: origin.series },
          { Name: 'NumeroDocumentoOrigen', Data: null, Value: String(origin.number) },
        ],
      }],
    },
  };
}

export function buildNcre(taxid, sellerName, sellerAddress, buyer, items, origin, reason, {
  afiliacion = 'GEN', sellerEmail = null, tipoFrase = null, escenario = null,
} = {}) {
  const [isoNow] = gtNow();
  const { lineItems, grandTotal, totalIva } = buildItems(items, true);
  const [tf, es] = resolveFrase('NCRE', afiliacion, tipoFrase, escenario);
  const seller = buildSeller(taxid, sellerName, sellerAddress, { afiliacion, tipoFrase: tf, escenario: es, email: sellerEmail });

  return {
    Version: '1.00', CountryCode: 'GT',
    Header: { DocType: 'NCRE', IssuedDateTime: isoNow, Currency: 'GTQ' },
    Seller: seller, Buyer: buyer, ThirdParties: null,
    Items: lineItems, Totals: buildTotals(grandTotal, totalIva, true),
    AdditionalDocumentInfo: {
      AdditionalInfo: [{
        Code: 'NCRE', Type: 'COMPLEMENTO',
        // NCRE uses AditionalInfo (not AditionalData)
        AditionalInfo: [
          { Name: 'NumeroAutorizacionDocumentoOrigen', Data: null, Value: origin.auth_number },
          { Name: 'FechaEmisionDocumentoOrigen', Data: null, Value: origin.date },
          { Name: 'MotivoAjuste', Data: null, Value: reason },
          { Name: 'NumeroDocumentoOrigen', Data: null, Value: String(origin.number) },
          { Name: 'SerieDocumentoOrigen', Data: null, Value: origin.series },
        ],
      }],
    },
  };
}

export function buildFesp(taxid, sellerName, sellerAddress, buyer, items, {
  afiliacion = 'GEN', sellerEmail = null,
} = {}) {
  const [isoNow] = gtNow();
  const { lineItems, grandTotal, totalIva } = buildItems(items, true);

  // FESP: no AdditionlInfo (tipoFrase=null)
  const seller = buildSeller(taxid, sellerName, sellerAddress, {
    afiliacion, tipoFrase: null, escenario: null, email: sellerEmail,
  });

  // Compute total taxable from line items
  let totalTaxable = 0n;
  const accFactor = 1_000_000_000_000n;
  for (const li of lineItems) {
    const taxableStr = li.Taxes?.Tax[0]?.TaxableAmount ?? '0';
    const [ip, fp = ''] = taxableStr.split('.');
    const frac = fp.padEnd(12, '0').slice(0, 12);
    totalTaxable += BigInt(ip) * accFactor + BigInt(frac);
  }

  function accToStr(acc) {
    const sf6 = accFactor / 1_000_000n;
    const half = sf6 / 2n;
    const rounded = (acc + half) / sf6;
    const ip = rounded / 1_000_000n;
    const fp = rounded % 1_000_000n;
    return `${ip}.${String(fp).padStart(6, '0')}`;
  }

  const taxableStr = accToStr(totalTaxable);
  const retencionIsr = fmt(Number(taxableStr) * 0.05);
  const retencionIva = totalIva;
  const totalMenos = fmt(Number(grandTotal) - Number(retencionIsr) - Number(retencionIva));

  return {
    Version: '1.00', CountryCode: 'GT',
    Header: { DocType: 'FESP', IssuedDateTime: isoNow, Currency: 'GTQ' },
    Seller: seller, Buyer: buyer, ThirdParties: null,
    Items: lineItems, Totals: buildTotals(grandTotal, totalIva, true),
    AdditionalDocumentInfo: {
      AdditionalInfo: [{
        Code: 'FESP', Type: 'COMPLEMENTO',
        AditionalInfo: [
          { Name: 'RetencionISR', Data: null, Value: retencionIsr },
          { Name: 'RetencionIVA', Data: null, Value: retencionIva },
          { Name: 'TotalMenosRetenciones', Data: null, Value: totalMenos },
        ],
      }],
    },
  };
}

export function buildRdon(taxid, sellerName, sellerAddress, buyer, items, tipoPersoneria, {
  afiliacion = 'GEN', amountStr = '', observaciones = '-', sellerEmail = null,
} = {}) {
  const [isoNow] = gtNow();
  const { lineItems, grandTotal } = buildItems(items, false);
  const seller = buildSeller(taxid, sellerName, sellerAddress, {
    afiliacion, tipoFrase: '4', escenario: '4', email: sellerEmail,
  });
  const amt = amountStr || grandTotal;
  const adenda = buildAdenda('RDON', amt, items.length, observaciones);

  return {
    Version: '1.00', CountryCode: 'GT',
    Header: {
      DocType: 'RDON', IssuedDateTime: isoNow, Currency: 'GTQ',
      AdditionalIssueDocInfo: [{ Name: 'TipoPersoneria', Data: null, Value: tipoPersoneria }],
    },
    Seller: seller, Buyer: buyer, ThirdParties: null,
    Items: lineItems,
    Totals: { GrandTotal: { InvoiceTotal: grandTotal } },
    AdditionalDocumentInfo: adenda,
  };
}

export function buildFpeq(taxid, sellerName, sellerAddress, buyer, items, {
  amountStr = '', observaciones = '-', sellerEmail = null,
  tipoFrase = null, escenario = null,
} = {}) {
  const [isoNow] = gtNow();
  const { lineItems, grandTotal } = buildItems(items, false);
  const [tf, es] = resolveFrase('FPEQ', 'PEQ', tipoFrase, escenario);
  const seller = buildSeller(taxid, sellerName, sellerAddress, {
    afiliacion: 'PEQ', tipoFrase: tf, escenario: es, email: sellerEmail,
  });
  const amt = amountStr || grandTotal;
  const adenda = buildAdenda('FPEQ', amt, items.length, observaciones);

  return {
    Version: '1.00', CountryCode: 'GT',
    Header: { DocType: 'FPEQ', IssuedDateTime: isoNow, Currency: 'GTQ' },
    Seller: seller, Buyer: buyer, ThirdParties: null,
    Items: lineItems,
    Totals: { GrandTotal: { InvoiceTotal: grandTotal } },
    AdditionalDocumentInfo: adenda,
  };
}

export function buildReci(taxid, sellerName, sellerAddress, buyer, items, {
  afiliacion = 'GEN', amountStr = '', observaciones = '-',
  studentName = 'Estudiante', studentId = '000000000', academicUnit = '01',
  sellerEmail = null,
} = {}) {
  const [isoNow] = gtNow();
  const { lineItems, grandTotal } = buildItems(items, false);
  const seller = buildSeller(taxid, sellerName, sellerAddress, {
    afiliacion, tipoFrase: '4', escenario: '5', email: sellerEmail,
  });
  const amt = amountStr || grandTotal;
  const extraInfo = [
    { Name: 'Tipo', Data: null, Value: 'Universidad' },
    { Name: 'NombreAlumno', Data: null, Value: studentName },
    { Name: 'Carne', Data: null, Value: studentId },
    { Name: 'UnidadAcademica', Data: null, Value: academicUnit },
  ];
  const adenda = buildAdenda('RECI', amt, items.length, observaciones, extraInfo);

  return {
    Version: '1.00', CountryCode: 'GT',
    Header: { DocType: 'RECI', IssuedDateTime: isoNow, Currency: 'GTQ' },
    Seller: seller, Buyer: buyer, ThirdParties: null,
    Items: lineItems,
    Totals: { GrandTotal: { InvoiceTotal: grandTotal } },
    AdditionalDocumentInfo: adenda,
  };
}

export function buildCca(taxid, sellerName, sellerAddress, buyer, items, cobros, {
  afiliacion = 'GEN', sellerEmail = null, tipoFrase = null, escenario = null,
} = {}) {
  const [isoNow] = gtNow();
  const { lineItems, grandTotal, totalIva } = buildItems(items, true);
  const [tf, es] = resolveFrase('FACT', afiliacion, tipoFrase, escenario);
  const seller = buildSeller(taxid, sellerName, sellerAddress, { afiliacion, tipoFrase: tf, escenario: es, email: sellerEmail });

  const ccaData = cobros.map(cobro => ({
    Info: [
      { Name: 'NITtercero', Data: null, Value: String(cobro.nit_tercero) },
      { Name: 'NumeroDocumento', Data: null, Value: String(cobro.numero_documento) },
      { Name: 'FechaDocumento', Data: null, Value: cobro.fecha_documento },
      { Name: 'Descripcion', Data: null, Value: cobro.descripcion },
      { Name: 'BaseImponible', Data: null, Value: fmt(cobro.base_imponible, 2) },
      { Name: 'MontoCobroDAI', Data: null, Value: fmt(cobro.monto_cobro_dai ?? 0, 2) },
      { Name: 'MontoCobroIVA', Data: null, Value: fmt(cobro.monto_cobro_iva, 2) },
      { Name: 'MontoCobroOtros', Data: null, Value: fmt(cobro.monto_cobro_otros ?? 0, 2) },
      { Name: 'MontoCobroTotal', Data: null, Value: fmt(cobro.monto_cobro_total, 2) },
    ],
  }));

  return {
    Version: '1.00', CountryCode: 'GT',
    Header: { DocType: 'FACT', IssuedDateTime: isoNow, Currency: 'GTQ' },
    Seller: seller, Buyer: buyer, ThirdParties: null,
    Items: lineItems, Totals: buildTotals(grandTotal, totalIva, true),
    AdditionalDocumentInfo: {
      AdditionalInfo: [{
        Code: 'CCA', Type: 'COMPLEMENTO',
        AditionalData: { Data: ccaData },
      }],
    },
  };
}

// ── Combustible (fuel) builder ───────────────────────────────────────────────────────────

function buildFuelItems(items) {
  const lineItems = [];
  let grandTotal    = 0n;
  let totalIva      = 0n;
  let totalPetroleo = 0n;

  for (let i = 0; i < items.length; i++) {
    const item     = items[i];
    const qty      = item.qty ?? 1;
    const price    = item.price;
    const itemType = item.type ?? 'Bien';
    const uom      = item.unit_of_measure ?? 'UNI';
    const desc     = item.description;

    const built = {
      Number: String(i + 1),
      Codes: null,
      Type: itemType,
      Description: desc,
      UnitOfMeasure: uom,
      Discounts: null,
    };

    if (item.petroleo_amount !== undefined && item.petroleo_amount !== null) {
      const petrolCode = String(item.petroleo_code ?? '1');
      const calc = calcFuelLine(qty, price, item.petroleo_amount);

      built.Qty   = calc.qty;
      built.Price = calc.price;
      built.Taxes = {
        Tax: [
          { Code: '1',        Description: 'IVA',      TaxableAmount: calc.taxable, Amount: calc.iva },
          { Code: petrolCode, Description: 'PETROLEO', TaxableAmount: calc.qty,     Amount: calc.petrol },
        ],
      };
      built.Totals = { TotalItem: calc.lineTotal };

      grandTotal    = addDecimalStrings(null, calc.lineTotal, grandTotal);
      totalIva      = addDecimalStrings(null, calc.iva, totalIva);
      totalPetroleo = addDecimalStrings(null, calc.petrol, totalPetroleo);
    } else {
      const calc = calcLine(qty, price, true);

      built.Qty   = calc.qty;
      built.Price = calc.price;
      built.Taxes = {
        Tax: [{ Code: '1', Description: 'IVA', TaxableAmount: calc.taxable, Amount: calc.iva }],
      };
      built.Totals = { TotalItem: calc.lineTotal };

      grandTotal = addDecimalStrings(null, calc.lineTotal, grandTotal);
      totalIva   = addDecimalStrings(null, calc.iva, totalIva);
    }

    lineItems.push(built);
  }

  return {
    lineItems,
    grandTotal:    fmtAcc(grandTotal),
    totalIva:      fmtAcc(totalIva),
    totalPetroleo: fmtAcc(totalPetroleo),
  };
}

function buildFuelAdenda() {
  return {
    AdditionalInfo: [{
      Code: '00000013',
      Type: 'ADENDA',
      AditionalInfo: [
        { Name: 'VALIDAR_REFERENCIA_INTERNA', Data: null, Value: 'NO_VALIDAR' },
      ],
    }],
  };
}

/**
 * Build a FACT payload for combustible (fuel) invoices.
 *
 * Fuel items must include `petroleo_amount` (per-unit PETROLEO tax) and
 * optionally `petroleo_code` ("1"=SUPER, "2"=REGULAR, "4"=DIESEL; default "1").
 * Items without `petroleo_amount` are treated as regular IVA-only items.
 *
 * @example
 * // Fuel item:
 * { description: 'GASOLINA SUPER', qty: 1, price: 30.30, petroleo_amount: 4.70, petroleo_code: '1', type: 'Bien' }
 * // Regular item in the same invoice:
 * { description: 'FILTRO DE ACEITE', qty: 1, price: 45.00, type: 'Bien' }
 */
export function buildFactCombustible(taxid, sellerName, sellerAddress, buyer, items, {
  afiliacion = 'GEN',
  tipoFrase = null,
  escenario = null,
  frases = null,
  autoFuelSubsidyFrases = true,
  sellerEmail = null,
} = {}) {
  if (frases != null && (tipoFrase != null || escenario != null)) {
    throw new Error('frases and tipoFrase/escenario are mutually exclusive; use one or the other');
  }
  const [isoNow] = gtNow();
  const { lineItems, grandTotal, totalIva, totalPetroleo } = buildFuelItems(items);
  const [tf, es] = resolveFrase('FACT', afiliacion, tipoFrase, escenario);
  const resolvedFrases = resolveFuelFrases(frases, tf, es, isoNow, autoFuelSubsidyFrases);
  const seller = buildSeller(taxid, sellerName, sellerAddress, { afiliacion, frases: resolvedFrases, email: sellerEmail });

  const totalTax = [{ Description: 'IVA', Amount: totalIva }];
  if (parseFloat(totalPetroleo) > 0) {
    totalTax.push({ Description: 'PETROLEO', Amount: totalPetroleo });
  }

  return {
    Version: '1.00', CountryCode: 'GT',
    Header: { DocType: 'FACT', IssuedDateTime: isoNow, Currency: 'GTQ' },
    Seller: seller, Buyer: buyer, ThirdParties: null,
    Items: lineItems,
    Totals: {
      TotalTaxes: { TotalTax: totalTax },
      GrandTotal: { InvoiceTotal: grandTotal },
    },
    AdditionalDocumentInfo: buildFuelAdenda(),
  };
}
