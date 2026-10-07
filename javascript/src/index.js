/**
 * Digifact FEL Guatemala SDK — JavaScript (Node 18+)
 * Entry point: re-exports all public symbols.
 */

export { DigifactClient, DteResult } from './client.js';
export {
  DigifactError,
  DigifactAuthError,
  DigifactApiError,
  DigifactValidationError,
  DigifactNitNotFoundError,
} from './errors.js';
export { gtNow, padTaxid, fmt, calcIva } from './tax.js';
export {
  fuelExemption,
  FUEL_EXEMPT_SUPER,
  FUEL_EXEMPT_REGULAR_IMPORTER,
  FUEL_EXEMPT_DIESEL,
  FUEL_EXEMPT_REGULAR_ETHANOL,
} from './builder.js';
