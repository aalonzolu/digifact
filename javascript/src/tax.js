/**
 * IVA calculations, money formatting, and Guatemala time helpers.
 *
 * Uses BigInt arithmetic for precision, formatted at 6 decimal places.
 * All monetary values are treated as integers scaled by 10^10 internally.
 */

const SCALE = 10n;
const SCALE_FACTOR = 10_000_000_000n; // 10^10
const IVA_DIVISOR = 112n;
const OUTPUT_DECIMALS = 6;

/**
 * Return current Guatemala time (UTC-6, no DST).
 * @returns {[string, string, string]} [iso_with_offset, space_datetime, date_only]
 */
export function gtNow() {
  // Guatemala is UTC-6 year-round (no DST)
  const now = new Date();
  const gtOffset = -6 * 60; // minutes
  const gtMs = now.getTime() + (now.getTimezoneOffset() + gtOffset) * 60_000;
  const gt = new Date(gtMs);

  const pad = (n, w = 2) => String(n).padStart(w, '0');
  const Y = gt.getUTCFullYear();
  const M = pad(gt.getUTCMonth() + 1);
  const D = pad(gt.getUTCDate());
  const h = pad(gt.getUTCHours());
  const m = pad(gt.getUTCMinutes());
  const s = pad(gt.getUTCSeconds());

  const isoOffset = `${Y}-${M}-${D}T${h}:${m}:${s}-06:00`;
  const space = `${Y}-${M}-${D} ${h}:${m}:${s}`;
  const dateOnly = `${Y}-${M}-${D}`;

  return [isoOffset, space, dateOnly];
}

/**
 * Strip non-digits and left-pad to 12 characters with zeros.
 * @param {string} taxid
 * @returns {string}
 */
export function padTaxid(taxid) {
  const digits = taxid.replace(/\D/g, '');
  return digits.padStart(12, '0');
}

/**
 * Parse a decimal string to a BigInt scaled by SCALE_FACTOR (10^10).
 * Handles up to 10 decimal places.
 * @param {string|number} value
 * @returns {bigint}
 */
function toScaled(value) {
  const str = String(value);
  const [intPart, fracPart = ''] = str.split('.');
  const frac = fracPart.padEnd(Number(SCALE), '0').slice(0, Number(SCALE));
  return BigInt(intPart) * SCALE_FACTOR + BigInt(frac);
}

/**
 * Convert a scaled BigInt back to a decimal string with `decimals` places.
 * Rounds half-up.
 * @param {bigint} scaled
 * @param {number} decimals
 * @returns {string}
 */
function fromScaled(scaled, decimals = OUTPUT_DECIMALS) {
  const factor = 10n ** BigInt(Number(SCALE) - decimals);
  const half = factor / 2n;
  const rounded = (scaled + half) / factor;
  const integerPart = rounded / (10n ** BigInt(decimals));
  const fracPart = rounded % (10n ** BigInt(decimals));
  return `${integerPart}.${String(fracPart).padStart(decimals, '0')}`;
}

/**
 * Format a value (number/string) as a fixed-decimal string.
 * @param {number|string} value
 * @param {number} decimals
 * @returns {string}
 */
export function fmt(value, decimals = OUTPUT_DECIMALS) {
  const scaled = toScaled(value);
  return fromScaled(scaled, decimals);
}

/**
 * Calculate IVA from an IVA-inclusive line total.
 * @param {string} lineTotal  As decimal string
 * @returns {[string, string]} [taxable_amount, iva_amount]
 */
export function calcIva(lineTotal) {
  const total = toScaled(lineTotal);
  // taxable = total * 100 / 112
  const taxableScaled = (total * 100n) / IVA_DIVISOR;
  const ivaScaled = total - taxableScaled;
  return [fromScaled(taxableScaled), fromScaled(ivaScaled)];
}

/**
 * Build all calculated values for a single invoice line item.
 * @param {string|number} qty
 * @param {string|number} unitPrice   IVA-inclusive price
 * @param {boolean} taxable
 * @param {string|number} discount    Line discount amount (default 0)
 * @returns {{qty:string, price:string, lineTotal:string, taxable:string, iva:string, discount:string}}
 */
export function calcLine(qty, unitPrice, taxable = true, discount = 0) {
  const qtyS = toScaled(qty);
  const priceS = toScaled(unitPrice);
  const discountS = toScaled(discount);

  // gross = qty * price / SCALE_FACTOR  (because both are scaled)
  const grossScaled = (qtyS * priceS) / SCALE_FACTOR;
  const lineTotalScaled = grossScaled - discountS;

  let taxableAmt, ivaAmt;
  if (taxable) {
    const lt = fromScaled(lineTotalScaled);
    [taxableAmt, ivaAmt] = calcIva(lt);
  } else {
    taxableAmt = '0.000000';
    ivaAmt = '0.000000';
  }

  return {
    qty: fromScaled(qtyS),
    price: fromScaled(priceS),
    lineTotal: fromScaled(lineTotalScaled),
    taxable: taxableAmt,
    iva: ivaAmt,
    discount: fromScaled(discountS, 2),
  };
}

/**
 * Build all calculated values for a fuel (combustible) invoice line item.
 *
 * The PETROLEO tax is a fixed pass-through amount supplied by the caller.
 * ``unitPrice`` is the full consumer price per unit (PETROLEO + IVA-inclusive).
 * IVA is calculated on qty × (unitPrice − petroleo).
 * TotalItem = qty × unitPrice.
 *
 * @param {string|number} qty
 * @param {string|number} unitPrice   Full consumer price per unit (PETROLEO + IVA-inclusive)
 * @param {string|number} petroleo    Per-unit PETROLEO tax amount
 * @returns {{qty:string, price:string, lineTotal:string, taxable:string, iva:string, petrol:string}}
 */
export function calcFuelLine(qty, unitPrice, petroleo) {
  const qtyS      = toScaled(qty);
  const priceS    = toScaled(unitPrice);
  const petrolS   = toScaled(petroleo);

  const netS            = priceS - petrolS;
  const grossScaled     = (qtyS * netS)   / SCALE_FACTOR;
  const petrolScaled    = (qtyS * petrolS) / SCALE_FACTOR;
  const lineTotalScaled = (qtyS * priceS)  / SCALE_FACTOR;

  const grossStr = fromScaled(grossScaled);
  const [taxableAmt, ivaAmt] = calcIva(grossStr);

  return {
    qty:       fromScaled(qtyS),
    price:     fromScaled(priceS),
    lineTotal: fromScaled(lineTotalScaled),
    taxable:   taxableAmt,
    iva:       ivaAmt,
    petrol:    fromScaled(petrolScaled),
  };
}
