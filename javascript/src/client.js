/**
 * DigifactClient — main entry point for the Digifact FEL Guatemala JavaScript SDK.
 * Uses native fetch (Node 18+), no runtime dependencies.
 */

import {
  DigifactError,
  DigifactAuthError,
  DigifactApiError,
  DigifactValidationError,
  DigifactNitNotFoundError,
  classifyError,
} from './errors.js';

import { gtNow, padTaxid, fmt } from './tax.js';

import {
  buyerCf,
  buyerNit,
  buyerCui,
  buildFact,
  buildFcam,
  buildNdeb,
  buildNcre,
  buildFesp,
  buildRdon,
  buildFpeq,
  buildReci,
  buildCca,
  buildFactCombustible,
  defaultFrase,
} from './builder.js';

const BASE_URLS = {
  test: 'https://testnucgt.digifact.com/api',
  production: 'https://nucgt.digifact.com/gt.com.apinuc/api',
};

// ── DteResult ─────────────────────────────────────────────────────────────────

export class DteResult {
  constructor(authNumber, series, number, issueDateTime, raw = {}) {
    this.authNumber = authNumber;
    this.series = series;
    this.number = number;
    this.issueDateTime = issueDateTime;
    this.raw = raw;
  }

  static fromResponse(data) {
    const auth = data.authNumber ?? data.Autorizacion ?? '';
    const series = data.batch ?? data.Serie ?? '';
    const number = String(data.serial ?? data.Numero ?? '');
    const tsRaw = data.issuedTimeStamp ?? data.FechaEmision ?? '';
    const issueDateTime = tsRaw.includes('T') ? tsRaw.replace('T', ' ') : tsRaw;
    return new DteResult(auth, series, number, issueDateTime, data);
  }
}

// ── DigifactClient ────────────────────────────────────────────────────────────

export class DigifactClient {
  /**
   * @param {object} config
   * @param {string} config.taxid
   * @param {string} config.username
   * @param {string} [config.password]
   * @param {string} [config.environment] "test" | "production"
   * @param {string} [config.token]  Pre-obtained bearer token
   * @param {string} [config.seller_name]
   * @param {string} [config.seller_address]
   * @param {string} [config.afiliacion_iva]  "GEN" | "PEQ" | "EXE"
   * @param {string} [config.tipo_personeria]
   * @param {string} [config.tipo_frase]  Global override for TipoFrase. If null, uses defaults table.
   * @param {string} [config.escenario]   Global override for CodigoEscenario. If null, uses defaults table.
   * @param {number} [config.timeout]  ms, default 120000
   * @param {Object<string,number>} [config.petroleo_rates]  Code→amount map, e.g. {"1":4.70,"2":4.60,"4":1.30}
   */
  constructor({
    taxid,
    username,
    password = '',
    environment = 'test',
    token = '',
    seller_name: sellerName = '',
    seller_address: sellerAddress = '',
    afiliacion_iva: afiliacionIva = 'GEN',
    tipo_personeria: tipoPersoneria = '1',
    tipo_frase: tipoFrase = null,
    escenario = null,
    timeout = 120_000,
    petroleo_rates: petroleoRates = {},
  }) {
    this.taxid = taxid.replace(/\D/g, '');
    this.paddedTaxid = padTaxid(taxid);
    this.username = username;
    this.password = password;
    this.afiliacionIva = afiliacionIva;
    this.tipoPersoneria = tipoPersoneria;
    this.tipoFrase = tipoFrase;
    this.escenario = escenario;
    this.timeout = timeout;
    this.petroleoRates = Object.assign({}, petroleoRates);
    if (!this.taxid) throw new TypeError('taxid must contain at least one digit');
    if (!username) throw new TypeError('username is required');
    if (!token && !password) throw new TypeError('password or token is required');

    this._token = token;
    this._sellerName = sellerName;
    this._sellerAddress = sellerAddress;
    this._nitCache = new Map();

    const base = BASE_URLS[environment];
    if (!base) throw new Error(`environment must be 'test' or 'production', got '${environment}'`);
    this.baseUrl = base.replace(/\/$/, '');
  }

  toString() {
    return `DigifactClient(taxid=${this.taxid}, username=${this.username}, environment=${this.baseUrl.includes('test') ? 'test' : 'production'})`;
  }

  [Symbol.for('nodejs.util.inspect.custom')]() {
    return this.toString();
  }

  // ── Authentication ──────────────────────────────────────────────────────────

  async _login() {
    if (this._token) return this._token;
    if (!this.password) throw new DigifactAuthError('password or token is required');

    const fullUsername = `GT.${this.paddedTaxid}.${this.username}`;
    const data = await this._post('/login/get_token', { Username: fullUsername, Password: this.password }, false);

    const tok = data.Token ?? data.token;
    if (!tok) throw new DigifactAuthError('Login succeeded but response contained no token', 0, data);
    this._token = tok;
    return this._token;
  }

  async _authHeaders() {
    const token = await this._login();
    return { Authorization: token, 'Content-Type': 'application/json' };
  }

  // ── HTTP helpers ─────────────────────────────────────────────────────────────

  async _post(path, body, withAuth = true, queryParams = {}) {
    const url = new URL(this.baseUrl + path);
    for (const [k, v] of Object.entries(queryParams)) url.searchParams.set(k, v);

    const headers = { 'Content-Type': 'application/json' };
    if (withAuth) headers.Authorization = await this._login();

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeout);

    let resp;
    try {
      resp = await fetch(url.toString(), {
        method: 'POST',
        headers,
        body: JSON.stringify(body),
        signal: controller.signal,
      });
    } finally {
      clearTimeout(timer);
    }

    const text = await resp.text();
    let data;
    try { data = JSON.parse(text); } catch { data = { _text: text }; }

    if (!resp.ok) {
      const preview = text.length > 300 ? text.slice(0, 300) + '…' : text;
      throw new DigifactApiError(`HTTP ${resp.status}: ${preview}`, resp.status, data);
    }
    return data;
  }

  async _get(path, queryParams = {}) {
    const url = new URL(this.baseUrl + path);
    for (const [k, v] of Object.entries(queryParams)) url.searchParams.set(k, v);

    const headers = await this._authHeaders();
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeout);

    let resp;
    try {
      resp = await fetch(url.toString(), { headers, signal: controller.signal });
    } finally {
      clearTimeout(timer);
    }

    const text = await resp.text();
    let data;
    try { data = JSON.parse(text); } catch { data = { _text: text }; }

    if (!resp.ok) {
      const preview = text.length > 300 ? text.slice(0, 300) + '…' : text;
      throw new DigifactApiError(`HTTP ${resp.status}: ${preview}`, resp.status, data);
    }
    return data;
  }

  // ── Seller info ─────────────────────────────────────────────────────────────

  async _getSellerInfo() {
    if (this._sellerName && this._sellerAddress) {
      return [this._sellerName, this._sellerAddress];
    }
    try {
      const info = await this.lookupNit(this.taxid);
      if (!this._sellerName) this._sellerName = info.name || `EMISOR ${this.taxid}`;
      if (!this._sellerAddress) this._sellerAddress = info.address || 'CIUDAD';
    } catch {
      if (!this._sellerName) this._sellerName = `EMISOR ${this.taxid}`;
      if (!this._sellerAddress) this._sellerAddress = 'CIUDAD';
    }
    return [this._sellerName, this._sellerAddress];
  }

  // ── Buyer resolution ────────────────────────────────────────────────────────

  async _resolveBuyer(buyer) {
    if (typeof buyer === 'string') {
      if (buyer.toUpperCase() === 'CF') return buyerCf();
      const digits = buyer.replace(/\D/g, '');
      if (digits) {
        const info = await this.lookupNit(digits);
        return buyerNit(digits, info.name || digits, info.address || 'CIUDAD',
          info.city || '01010', info.district || 'GUATEMALA', info.state || 'GUATEMALA');
      }
      throw new DigifactError(`Cannot resolve buyer: ${buyer}`);
    }
    if (buyer !== null && typeof buyer === 'object') {
      const type = (buyer.type || '').toUpperCase();
      if (type === 'CUI') return buyerCui(String(buyer.taxid), buyer.name);
      return buyerNit(
        String(buyer.taxid), buyer.name,
        buyer.address || 'CIUDAD', buyer.city || '01010',
        buyer.district || 'GUATEMALA', buyer.state || 'GUATEMALA',
        buyer.country || 'GT', buyer.email || null
      );
    }
    throw new DigifactError(`buyer must be a string or object, got ${typeof buyer}`);
  }

  // ── Certify ─────────────────────────────────────────────────────────────────

  async _certify(payload) {
    const data = await this._post('/v2/transform/nuc_json', payload, true, {
      TAXID: this.paddedTaxid,
      FORMAT: 'XML|HTML|PDF',
      USERNAME: this.username,
    });
    return this._checkResponse(data);
  }

  _checkResponse(data) {
    const code = data.code;
    if (code == null) {
      const auth = data.authNumber ?? data.Autorizacion;
      if (auth) return data;
      return data;
    }
    const codeInt = parseInt(code, 10);
    if (codeInt === 1) return data;

    const msg = data.description ?? data.message ?? JSON.stringify(data);
    const hint = classifyError(msg);
    let fullMsg = `DTE rejected (code=${codeInt}): ${msg}`;
    if (hint) fullMsg += `\n\nHint: ${hint}`;

    if (codeInt === 0) throw new DigifactValidationError(fullMsg, codeInt, data);
    throw new DigifactApiError(fullMsg, codeInt, data);
  }

  // ── Public DTE methods ──────────────────────────────────────────────────────

  /**
   * Resolve effective (TipoFrase, CodigoEscenario) for a DTE.
   * Precedence: opts (per-call) → constructor globals → defaults table.
   * @private
   */
  _resolveFrase(docType, opts = {}) {
    const def = defaultFrase(docType, this.afiliacionIva) || [null, null];
    const tf = opts.tipo_frase ?? this.tipoFrase ?? def[0];
    const es = opts.escenario ?? this.escenario ?? def[1];
    return [tf, es];
  }

  /**
   * Emit a DTE invoice.
   *
   * @param {string|object} buyer  "CF", NIT string, CUI object, or buyer object
   * @param {Array<object>} items  [{description, qty, price, type, unit_of_measure}]
   * @param {object} [opts]
   * @param {string} [opts.doc_type]        Default "FACT"
   * @param {Array}  [opts.payment_terms]   Required for FCAM: [{date, amount}]
   * @param {string} [opts.amount_str]      Human-readable total for ADENDA
   * @param {string} [opts.observaciones]
   * @param {string} [opts.tipo_personeria] Required for RDON
   * @param {string} [opts.tipo_frase]      Override TipoFrase for this call
   * @param {string} [opts.escenario]       Override CodigoEscenario for this call
   * @returns {Promise<DteResult>}
   */
  async invoice(buyer, items, opts = {}) {
    const [sellerName, sellerAddress] = await this._getSellerInfo();
    const buyerObj = await this._resolveBuyer(buyer);
    const docType = opts.doc_type || 'FACT';
    const amountStr = opts.amount_str || '';
    const observaciones = opts.observaciones || '-';
    const [tipoFrase, escenario] = this._resolveFrase(docType, opts);

    let payload;

    switch (docType) {
      case 'FCAM': {
        const pt = opts.payment_terms;
        if (!pt || !pt.length) throw new DigifactValidationError('payment_terms is required for FCAM');
        payload = buildFcam(this.taxid, sellerName, sellerAddress, buyerObj, items, pt, { afiliacion: this.afiliacionIva, tipoFrase, escenario });
        break;
      }
      case 'FESP':
        payload = buildFesp(this.taxid, sellerName, sellerAddress, buyerObj, items, { afiliacion: this.afiliacionIva });
        break;
      case 'RDON': {
        const tp = opts.tipo_personeria || this.tipoPersoneria;
        payload = buildRdon(this.taxid, sellerName, sellerAddress, buyerObj, items, tp, { afiliacion: this.afiliacionIva, amountStr, observaciones });
        break;
      }
      case 'FPEQ':
        payload = buildFpeq(this.taxid, sellerName, sellerAddress, buyerObj, items, { amountStr, observaciones, tipoFrase, escenario });
        break;
      case 'RECI':
        payload = buildReci(this.taxid, sellerName, sellerAddress, buyerObj, items, { afiliacion: this.afiliacionIva, amountStr, observaciones });
        break;
      default:
        // FACT (default), NABN, or any standard type
        payload = buildFact(this.taxid, sellerName, sellerAddress, buyerObj, items, {
          docType, afiliacion: this.afiliacionIva, amountStr, observaciones, tipoFrase, escenario,
        });
        break;
    }

    const data = await this._certify(payload);
    return DteResult.fromResponse(data);
  }

  /**
   * Emit a CCA (Cobro por Cuenta Ajena) FACT+CCA complemento.
   * @returns {Promise<DteResult>}
   */
  async ccaInvoice(buyer, items, cobros, opts = {}) {
    const [sellerName, sellerAddress] = await this._getSellerInfo();
    const buyerObj = await this._resolveBuyer(buyer);
    const [tipoFrase, escenario] = this._resolveFrase('FACT', opts);
    const payload = buildCca(this.taxid, sellerName, sellerAddress, buyerObj, items, cobros, { afiliacion: this.afiliacionIva, tipoFrase, escenario });
    const data = await this._certify(payload);
    return DteResult.fromResponse(data);
  }

  /**
   * Emit a combustible (fuel) FACT invoice.
   *
   * Fuel items must include `petroleo_amount` (per-unit PETROLEO tax) and optionally
   * `petroleo_code` ("1"=SUPER, "2"=REGULAR, "4"=DIESEL; default "1").
   * Items without `petroleo_amount` are treated as regular IVA-only items.
   *
   * @param {string|object} buyer
   * @param {Array<object>} items
   * @returns {Promise<DteResult>}
   *
   * @example
   * await client.fuelInvoice('CF', [
   *   { description: 'GASOLINA SUPER', qty: 1, price: 30.30, petroleo_amount: 4.70, petroleo_code: '1', type: 'Bien' },
   *   { description: 'FILTRO DE ACEITE', qty: 1, price: 45.00, type: 'Bien' },
   * ]);
   */
  async fuelInvoice(buyer, items, opts = {}) {
    const [sellerName, sellerAddress] = await this._getSellerInfo();
    const buyerObj = await this._resolveBuyer(buyer);
    const resolved = this._applyPetroleoRates(items);
    const [tipoFrase, escenario] = this._resolveFrase('FACT', opts);
    const payload = buildFactCombustible(this.taxid, sellerName, sellerAddress, buyerObj, resolved, { afiliacion: this.afiliacionIva, tipoFrase, escenario });
    const data = await this._certify(payload);
    return DteResult.fromResponse(data);
  }

  /** @private */
  _applyPetroleoRates(items) {
    return items.map(item => {
      const code = item.petroleo_code;
      if (code != null && item.petroleo_amount == null) {
        const rate = this.petroleoRates[String(code)];
        if (rate == null) {
          throw new DigifactValidationError(
            `Item '${item.description ?? ''}' has petroleo_code='${code}' ` +
            'but no petroleo_amount and no matching rate in petroleo_rates.'
          );
        }
        return { ...item, petroleo_amount: rate };
      }
      return item;
    });
  }

  /**
   * Emit a NCRE (Nota de Crédito).
   * @param {object} origin  {auth_number, date, series, number}
   * @returns {Promise<DteResult>}
   */
  async creditNote(buyer, items, origin, reason, opts = {}) {
    const [sellerName, sellerAddress] = await this._getSellerInfo();
    const buyerObj = await this._resolveBuyer(buyer);
    const [tipoFrase, escenario] = this._resolveFrase('NCRE', opts);
    const payload = buildNcre(this.taxid, sellerName, sellerAddress, buyerObj, items, origin, reason, { afiliacion: this.afiliacionIva, tipoFrase, escenario });
    const data = await this._certify(payload);
    return DteResult.fromResponse(data);
  }

  /**
   * Emit a NDEB (Nota de Débito).
   * @param {object} origin  {auth_number, date, series, number}
   * @returns {Promise<DteResult>}
   */
  async debitNote(buyer, items, origin, reason, opts = {}) {
    const [sellerName, sellerAddress] = await this._getSellerInfo();
    const buyerObj = await this._resolveBuyer(buyer);
    const [tipoFrase, escenario] = this._resolveFrase('NDEB', opts);
    const payload = buildNdeb(this.taxid, sellerName, sellerAddress, buyerObj, items, origin, reason, { afiliacion: this.afiliacionIva, tipoFrase, escenario });
    const data = await this._certify(payload);
    return DteResult.fromResponse(data);
  }

  /**
   * Cancel a DTE.
   * @param {string} issueDateTime  "YYYY-MM-DD HH:MM:SS"
   * @returns {Promise<object>}
   */
  async cancel(authNumber, receiverId, issueDateTime, reason = 'Anulación') {
    return this._post('/CancelFelGT', {
      Taxid: this.taxid,
      Autorizacion: authNumber,
      IdReceptor: receiverId,
      FechaEmisionDocumentoAnular: issueDateTime,
      MotivoAnulacion: reason,
      Username: this.username,
    });
  }

  /**
   * Create a total credit note via /cert/ncredtotal.
   * @param {string} issueDateTime  "YYYY-MM-DD HH:MM:SS"
   * @returns {Promise<object>}
   */
  async creditNoteTotal(authNumber, issueDateTime, reason = 'Nota de crédito total', reference = '') {
    return this._post('/cert/ncredtotal', {
      Staxid: this.taxid,
      Authnumber: authNumber,
      FechaEmision: issueDateTime,
      MotivoAjuste: reason,
      ReferenciaInterna: reference,
      Formatos: 'xml|html|pdf',
      Username: this.username,
    });
  }

  /**
   * Look up a NIT via SHARED_GETINFONITcom.
   * @param {string} nit
   * @returns {Promise<{nit:string, name:string, address:string, city:string, district:string, state:string}>}
   */
  async lookupNit(nit) {
    const digits = nit.replace(/\D/g, '');
    if (this._nitCache.has(digits)) return this._nitCache.get(digits);

    const data = await this._get('/Shared', {
      COUNTRY: 'GT',
      TAXID: this.paddedTaxid,
      DATA1: 'SHARED_GETINFONITcom',
      DATA2: `NIT|${digits}`,
      USERNAME: this.username,
    });

    const normalized = this._parseNitResponse(digits, data);
    if (!normalized.name) throw new DigifactNitNotFoundError(`NIT '${nit}' not found or returned empty name`);
    this._nitCache.set(digits, normalized);
    return normalized;
  }

  _parseNitResponse(nit, data) {
    const empty = { nit, name: '', address: 'CIUDAD', city: '01010', district: 'GUATEMALA', state: 'GUATEMALA' };
    if (!data || typeof data !== 'object') return empty;
    // Unwrap envelope: {"REQUEST_DATA": [...], "RESPONSE": [...]}
    if ('RESPONSE' in data) {
      const list = data.RESPONSE;
      if (Array.isArray(list) && list.length > 0) return this._parseNitResponse(nit, list[0]);
      return empty;
    }
    if (Array.isArray(data) && data.length > 0) return this._parseNitResponse(nit, data[0]);
    // Row dict — MUNICIPIO and DEPARTAMENTO are uppercase in the real API response
    return {
      nit,
      name: (data.NOMBRE ?? data.nombre ?? data.Name ?? data.name ?? '').trim(),
      address: (data.Direccion ?? data.direccion ?? data.Address ?? data.address ?? 'CIUDAD').trim(),
      city: '01010',
      district: (data.MUNICIPIO ?? data.Municipio ?? data.municipio ?? data.district ?? 'GUATEMALA').toString().trim(),
      state: (data.DEPARTAMENTO ?? data.Departamento ?? data.departamento ?? data.state ?? 'GUATEMALA').toString().trim(),
    };
  }

  /**
   * Get DTE info via SHARED_GETDTEINFO.
   * @returns {Promise<object>}
   */
  async getDteInfo(authNumber) {
    return this._get('/Shared', {
      COUNTRY: 'GT',
      TAXID: this.paddedTaxid,
      DATA1: 'SHARED_GETDTEINFO',
      DATA2: `AUTHNUMBER|${authNumber}`,
      USERNAME: this.username,
    });
  }

  /**
   * Retrieve a DTE document via GET /GetDocument.
   * @returns {Promise<object>}
   */
  async getDte(authNumber, format = 'JSON') {
    return this._get('/GetDocument', {
      AUTHNUMBER: authNumber,
      TAXID: this.paddedTaxid,
      FORMAT: format,
      USERNAME: this.username,
    });
  }
}
