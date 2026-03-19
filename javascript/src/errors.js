/**
 * Exceptions for the Digifact FEL JavaScript SDK.
 */

export class DigifactError extends Error {
  constructor(message, code = null, raw = {}) {
    super(message);
    this.name = 'DigifactError';
    this.code = code;
    this.raw = raw;
  }
}

export class DigifactAuthError extends DigifactError {
  constructor(message, code = null, raw = {}) {
    super(message, code, raw);
    this.name = 'DigifactAuthError';
  }
}

export class DigifactApiError extends DigifactError {
  constructor(message, code = null, raw = {}) {
    super(message, code, raw);
    this.name = 'DigifactApiError';
  }
}

export class DigifactValidationError extends DigifactError {
  constructor(message, code = null, raw = {}) {
    super(message, code, raw);
    this.name = 'DigifactValidationError';
  }
}

export class DigifactNitNotFoundError extends DigifactError {
  constructor(message) {
    super(message);
    this.name = 'DigifactNitNotFoundError';
  }
}

// ── SAT hint classifier ───────────────────────────────────────────────────────

const HINTS = [
  {
    keywords: ['AfiliacionIVA', 'MINIRTU', 'RTU'],
    hint: 'El NIT del emisor no tiene la afiliación de IVA indicada en su RTU (AfiliacionIVA). ' +
          'Verifica que AfiliacionIVA sea GEN, PEQ o EXE según el RTU del emisor.',
  },
  {
    keywords: ['TipoPersoneria'],
    hint: 'El valor de TipoPersoneria debe coincidir exactamente con el código registrado en el ' +
          'RTU del emisor. Consulta el RTU en portal.sat.gob.gt.',
  },
  {
    keywords: ['SECCION 3.5', '9015', 'ID Receptor'],
    hint: 'NCRE/NDEB: el receptor del documento origen debe coincidir con el receptor del ' +
          'documento de nota. Asegúrate de referenciar una factura cuyo comprador sea el mismo que el de la nota.',
  },
  {
    keywords: ['NAMESPACE_UNKNOWN', '5035'],
    hint: 'El complemento CCA requiere que el emisor tenga habilitado el namespace CCA en Digifact. ' +
          'Contacta a soporte@digifact.com.gt.',
  },
  {
    keywords: ['FRASES'],
    hint: 'Este tipo de DTE no permite las frases indicadas. Para FESP no incluyas TipoFrase/Escenario en la sección Seller.',
  },
  {
    keywords: ['FechaEmision', 'fecha'],
    hint: "Verifica el formato de fecha: debe ser 'YYYY-MM-DD HH:MM:SS' para cancelaciones y ncredtotal, " +
          "o 'YYYY-MM-DDTHH:MM:SS-06:00' para IssuedDateTime.",
  },
];

/**
 * Return a developer hint for a known SAT/Digifact error pattern, or null.
 * @param {string} message
 * @returns {string|null}
 */
export function classifyError(message) {
  const upper = message.toUpperCase();
  for (const { keywords, hint } of HINTS) {
    if (keywords.some(kw => upper.includes(kw.toUpperCase()))) {
      return hint;
    }
  }
  return null;
}
