"""DigifactClient — main entry point for the Digifact FEL Guatemala SDK."""
from __future__ import annotations

import re
from dataclasses import dataclass, field
from decimal import Decimal
from typing import Any

import requests

from .builder import (
    build_cca,
    build_fact,
    build_fcam,
    build_fesp,
    build_fpeq,
    build_nabn,
    build_ncre,
    build_ndeb,
    build_rdon,
    build_reci,
    _build_buyer_cf,
    _build_buyer_nit,
    _build_buyer_cui,
)
from .exceptions import (
    DigifactApiError,
    DigifactAuthError,
    DigifactError,
    DigifactNitNotFoundError,
    DigifactValidationError,
    classify_error,
)
from .tax import gt_now, pad_taxid

_BASE_URLS = {
    "test": "https://testnucgt.digifact.com/api",
    "production": "https://nucgt.digifact.com/api",
}


@dataclass
class DteResult:
    """Result of a DTE emission."""

    auth_number: str
    series: str
    number: str
    issue_datetime: str
    raw: dict = field(default_factory=dict)

    @property
    def auth_number_upper(self) -> str:
        return self.auth_number.upper()


class DigifactClient:
    """High-level client for the Digifact FEL Guatemala API.

    Parameters
    ----------
    taxid:
        Fiscal ID of the issuer (digits only; will be padded to 12 chars).
    username:
        Short username, e.g. "FELUSER".
    password:
        Account password. Either password or token must be provided.
    environment:
        "test" (default) or "production".
    token:
        Pre-obtained bearer token. If provided, login is skipped.
    seller_name:
        Override the seller name (default: fetched from SAT NIT lookup).
    seller_address:
        Override the seller address.
    afiliacion_iva:
        IVA affiliation code ("GEN", "PEQ", "EXE"). Default "GEN".
    tipo_personeria:
        Required for RDON; e.g. "719".
    timeout:
        HTTP request timeout in seconds (default 120).
    session:
        Optional ``requests.Session`` to use (useful for testing / mocking).
    """

    def __init__(
        self,
        taxid: str,
        username: str,
        password: str = "",
        *,
        environment: str = "test",
        token: str = "",
        seller_name: str = "",
        seller_address: str = "",
        afiliacion_iva: str = "GEN",
        tipo_personeria: str = "1",
        timeout: int = 120,
        session: requests.Session | None = None,
    ) -> None:
        self.taxid = re.sub(r"\D", "", taxid)  # digits only
        if not self.taxid:
            raise ValueError("taxid must contain at least one digit")
        if not username:
            raise ValueError("username is required")
        if not token and not password:
            raise ValueError("password or token is required")

        self.padded_taxid = pad_taxid(taxid)
        self.username = username
        self.password = password
        self.environment = environment
        self.afiliacion_iva = afiliacion_iva
        self.tipo_personeria = tipo_personeria
        self.timeout = timeout

        base = _BASE_URLS.get(environment)
        if base is None:
            raise ValueError(f"environment must be 'test' or 'production', got {environment!r}")
        self.base_url = base.rstrip("/")

        self._full_username = f"GT.{self.padded_taxid}.{username}"
        self._token: str = token
        self._seller_name: str = seller_name
        self._seller_address: str = seller_address
        self._nit_cache: dict[str, dict] = {}

        self._session = session or requests.Session()

    def __repr__(self) -> str:
        env = self.environment
        return f"DigifactClient(taxid={self.taxid!r}, username={self.username!r}, environment={env!r})"

    # ── Authentication ────────────────────────────────────────────────────────

    def _login(self) -> str:
        if self._token:
            return self._token
        if not self.password:
            raise DigifactAuthError("password or token is required")
        resp = self._session.post(
            f"{self.base_url}/login/get_token",
            json={"Username": self._full_username, "Password": self.password},
            timeout=self.timeout,
        )
        try:
            resp.raise_for_status()
        except requests.HTTPError as exc:
            raise DigifactAuthError(f"Login HTTP error: {exc}", raw=_try_json(resp)) from exc
        data = resp.json()
        tok = data.get("Token") or data.get("token")
        if not tok:
            raise DigifactAuthError("Login succeeded but response contained no token", raw=data)
        self._token = tok
        return self._token

    def _headers(self) -> dict:
        return {"Authorization": self._login(), "Content-Type": "application/json"}

    # ── Seller info ───────────────────────────────────────────────────────────

    def _get_seller_info(self) -> tuple[str, str]:
        """Fetch seller name and address, with cache. Returns (name, address)."""
        if self._seller_name and self._seller_address:
            return self._seller_name, self._seller_address
        try:
            info = self.lookup_nit(self.taxid)
            name = info.get("name") or info.get("NOMBRE") or "EMISOR"
            address = info.get("address") or info.get("Direccion") or "CIUDAD"
            if not self._seller_name:
                self._seller_name = name
            if not self._seller_address:
                self._seller_address = address
        except (DigifactError, requests.RequestException):
            # NIT lookup failed — use safe fallbacks so the invoice can still proceed
            if not self._seller_name:
                self._seller_name = f"EMISOR {self.taxid}"
            if not self._seller_address:
                self._seller_address = "CIUDAD"
        return self._seller_name, self._seller_address

    # ── Buyer resolution ──────────────────────────────────────────────────────

    def _resolve_buyer(self, buyer: str | dict) -> dict:
        """Resolve a buyer spec to a buyer dict.

        - "CF"           → Consumidor Final
        - "77454820"     → NIT lookup → buyer dict
        - {"taxid": ..., "type": "CUI", "name": ...} → CUI buyer
        - {"taxid": ..., "name": ..., ...}            → explicit buyer dict
        """
        if isinstance(buyer, str):
            if buyer.upper() == "CF":
                return _build_buyer_cf()
            # Numeric string → NIT
            digits = re.sub(r"\D", "", buyer)
            if digits:
                info = self.lookup_nit(digits)
                return _build_buyer_nit(
                    nit=digits,
                    name=info.get("name") or info.get("NOMBRE") or digits,
                    address=info.get("address") or info.get("Direccion") or "CIUDAD",
                    city=info.get("city") or "01010",
                    district=info.get("district") or "GUATEMALA",
                    state=info.get("state") or "GUATEMALA",
                    country="GT",
                )
            raise DigifactError(f"Cannot resolve buyer: {buyer!r}")

        if isinstance(buyer, dict):
            buyer_type = buyer.get("type", "").upper()
            if buyer_type == "CUI":
                return _build_buyer_cui(
                    taxid=str(buyer["taxid"]),
                    name=buyer["name"],
                )
            # Explicit dict — build NIT buyer
            return _build_buyer_nit(
                nit=str(buyer["taxid"]),
                name=buyer["name"],
                address=buyer.get("address", "CIUDAD"),
                city=buyer.get("city", "01010"),
                district=buyer.get("district", "GUATEMALA"),
                state=buyer.get("state", "GUATEMALA"),
                country=buyer.get("country", "GT"),
                email=buyer.get("email"),
            )

        raise DigifactError(f"buyer must be a string or dict, got {type(buyer)}")

    # ── Raw API calls ─────────────────────────────────────────────────────────

    def _certify(self, payload: dict) -> dict:
        resp = self._session.post(
            f"{self.base_url}/v2/transform/nuc_json",
            params={
                "TAXID": self.padded_taxid,
                "FORMAT": "XML|HTML|PDF",
                "USERNAME": self.username,
            },
            headers=self._headers(),
            json=payload,
            timeout=self.timeout,
        )
        try:
            resp.raise_for_status()
        except requests.HTTPError as exc:
            raise DigifactApiError(f"Certify HTTP error: {exc}", raw=_try_json(resp)) from exc
        data = resp.json()
        return _check_response(data)

    def _parse_result(self, data: dict) -> DteResult:
        auth = data.get("authNumber") or data.get("Autorizacion") or ""
        series = data.get("batch") or data.get("Serie") or ""
        number = str(data.get("serial") or data.get("Numero") or "")
        ts_raw = data.get("issuedTimeStamp") or data.get("FechaEmision") or ""
        issue_dt = ts_raw.replace("T", " ") if "T" in ts_raw else ts_raw
        return DteResult(
            auth_number=auth,
            series=series,
            number=number,
            issue_datetime=issue_dt,
            raw=data,
        )

    # ── Public DTE methods ────────────────────────────────────────────────────

    def invoice(
        self,
        buyer: str | dict,
        items: list[dict],
        *,
        doc_type: str = "FACT",
        payment_terms: list[dict] | None = None,
        amount_str: str = "",
        observaciones: str = "-",
        tipo_personeria: str | None = None,
    ) -> DteResult:
        """Emit a DTE invoice (FACT, FCAM, NABN, FESP, RDON, FPEQ, RECI, CCA).

        Parameters
        ----------
        buyer:
            "CF", NIT string, CUI dict, or full buyer dict.
        items:
            List of item dicts with keys: description, qty, price, type, unit_of_measure.
        doc_type:
            DTE type. Default "FACT".
        payment_terms:
            For FCAM: list of {"date": "YYYY-MM-DD", "amount": float}.
        amount_str:
            Human-readable total amount string for ADENDA (e.g. "CIEN QUETZALES CON 00/100").
        observaciones:
            Observations text for ADENDA.
        tipo_personeria:
            Required for RDON.
        """
        seller_name, seller_address = self._get_seller_info()
        buyer_dict = self._resolve_buyer(buyer)

        if doc_type == "FCAM":
            if not payment_terms:
                raise DigifactValidationError("payment_terms is required for FCAM")
            payload = build_fcam(
                self.taxid,
                seller_name,
                seller_address,
                buyer_dict,
                items,
                payment_terms,
                afiliacion=self.afiliacion_iva,
                amount_str=amount_str,
                observaciones=observaciones,
            )
        elif doc_type == "FESP":
            payload = build_fesp(
                self.taxid,
                seller_name,
                seller_address,
                buyer_dict,
                items,
                afiliacion=self.afiliacion_iva,
            )
        elif doc_type == "NABN":
            payload = build_nabn(
                self.taxid,
                seller_name,
                seller_address,
                buyer_dict,
                items,
                afiliacion=self.afiliacion_iva,
                amount_str=amount_str,
                observaciones=observaciones,
            )
        elif doc_type == "RDON":
            tp = tipo_personeria or self.tipo_personeria
            payload = build_rdon(
                self.taxid,
                seller_name,
                seller_address,
                buyer_dict,
                items,
                tp,
                afiliacion=self.afiliacion_iva,
                amount_str=amount_str,
                observaciones=observaciones,
            )
        elif doc_type == "FPEQ":
            payload = build_fpeq(
                self.taxid,
                seller_name,
                seller_address,
                buyer_dict,
                items,
                amount_str=amount_str,
                observaciones=observaciones,
            )
        elif doc_type == "RECI":
            payload = build_reci(
                self.taxid,
                seller_name,
                seller_address,
                buyer_dict,
                items,
                afiliacion=self.afiliacion_iva,
                amount_str=amount_str,
                observaciones=observaciones,
            )
        else:
            # FACT (default), FACT CUI
            payload = build_fact(
                self.taxid,
                seller_name,
                seller_address,
                buyer_dict,
                items,
                doc_type=doc_type,
                afiliacion=self.afiliacion_iva,
                amount_str=amount_str,
                observaciones=observaciones,
            )

        data = self._certify(payload)
        return self._parse_result(data)

    def cca_invoice(
        self,
        buyer: str | dict,
        items: list[dict],
        cobros: list[dict],
    ) -> DteResult:
        """Emit a CCA (Cobro por Cuenta Ajena) FACT+CCA complemento."""
        seller_name, seller_address = self._get_seller_info()
        buyer_dict = self._resolve_buyer(buyer)
        payload = build_cca(
            self.taxid,
            seller_name,
            seller_address,
            buyer_dict,
            items,
            cobros,
            afiliacion=self.afiliacion_iva,
        )
        data = self._certify(payload)
        return self._parse_result(data)

    def credit_note(
        self,
        buyer: str | dict,
        items: list[dict],
        origin: dict,
        reason: str,
    ) -> DteResult:
        """Emit a NCRE (Nota de Crédito).

        Parameters
        ----------
        origin:
            {"auth_number": str, "date": "YYYY-MM-DD", "series": str, "number": str|int}
        reason:
            Reason for the credit note.
        """
        seller_name, seller_address = self._get_seller_info()
        buyer_dict = self._resolve_buyer(buyer)
        payload = build_ncre(
            self.taxid,
            seller_name,
            seller_address,
            buyer_dict,
            items,
            origin,
            reason,
            afiliacion=self.afiliacion_iva,
        )
        data = self._certify(payload)
        return self._parse_result(data)

    def debit_note(
        self,
        buyer: str | dict,
        items: list[dict],
        origin: dict,
        reason: str,
    ) -> DteResult:
        """Emit a NDEB (Nota de Débito).

        Parameters
        ----------
        origin:
            {"auth_number": str, "date": "YYYY-MM-DD", "series": str, "number": str|int}
        reason:
            Reason for the debit note.
        """
        seller_name, seller_address = self._get_seller_info()
        buyer_dict = self._resolve_buyer(buyer)
        payload = build_ndeb(
            self.taxid,
            seller_name,
            seller_address,
            buyer_dict,
            items,
            origin,
            reason,
            afiliacion=self.afiliacion_iva,
        )
        data = self._certify(payload)
        return self._parse_result(data)

    def cancel(
        self,
        auth_number: str,
        receiver_id: str,
        issue_datetime: str,
        reason: str = "Anulación",
    ) -> dict:
        """Cancel a DTE.

        Parameters
        ----------
        issue_datetime:
            Format "YYYY-MM-DD HH:MM:SS"
        """
        resp = self._session.post(
            f"{self.base_url}/CancelFelGT",
            headers=self._headers(),
            json={
                "Taxid": self.taxid,
                "Autorizacion": auth_number,
                "IdReceptor": receiver_id,
                "FechaEmisionDocumentoAnular": issue_datetime,
                "MotivoAnulacion": reason,
                "Username": self.username,
            },
            timeout=self.timeout,
        )
        try:
            resp.raise_for_status()
        except requests.HTTPError as exc:
            raise DigifactApiError(f"Cancel HTTP error: {exc}", raw=_try_json(resp)) from exc
        return resp.json()

    def credit_note_total(
        self,
        auth_number: str,
        issue_datetime: str,
        reason: str = "Nota de crédito total",
        reference: str = "",
    ) -> dict:
        """Create a total credit note via /cert/ncredtotal.

        Parameters
        ----------
        issue_datetime:
            Format "YYYY-MM-DD HH:MM:SS"
        """
        resp = self._session.post(
            f"{self.base_url}/cert/ncredtotal",
            headers=self._headers(),
            json={
                "Staxid": self.taxid,
                "Authnumber": auth_number,
                "FechaEmision": issue_datetime,
                "MotivoAjuste": reason,
                "ReferenciaInterna": reference,
                "Formatos": "xml|html|pdf",
                "Username": self.username,
            },
            timeout=self.timeout,
        )
        try:
            resp.raise_for_status()
        except requests.HTTPError as exc:
            raise DigifactApiError(
                f"NcredTotal HTTP error: {exc}", raw=_try_json(resp)
            ) from exc
        return resp.json()

    # ── Query methods ─────────────────────────────────────────────────────────

    def lookup_nit(self, nit: str) -> dict:
        """Look up a NIT via SHARED_GETINFONITcom.

        Returns a normalized dict with keys: name, address, city, district, state.
        Result is cached per NIT.
        """
        digits = re.sub(r"\D", "", nit)
        if digits in self._nit_cache:
            return self._nit_cache[digits]

        resp = self._session.get(
            f"{self.base_url}/Shared",
            params={
                "COUNTRY": "GT",
                "TAXID": self.padded_taxid,
                "DATA1": "SHARED_GETINFONITcom",
                "DATA2": f"NIT|{digits}",
                "USERNAME": self.username,
            },
            headers={"Authorization": self._login()},
            timeout=self.timeout,
        )
        try:
            resp.raise_for_status()
        except requests.HTTPError as exc:
            raise DigifactApiError(
                f"NIT lookup HTTP error: {exc}", raw=_try_json(resp)
            ) from exc

        data = resp.json()
        normalized = _parse_nit_response(digits, data)
        if not normalized.get("name"):
            raise DigifactNitNotFoundError(f"NIT {nit!r} not found or returned empty name")
        self._nit_cache[digits] = normalized
        return normalized

    def get_dte_info(self, auth_number: str) -> dict:
        """Get DTE info via SHARED_GETDTEINFO."""
        resp = self._session.get(
            f"{self.base_url}/Shared",
            params={
                "COUNTRY": "GT",
                "TAXID": self.padded_taxid,
                "DATA1": "SHARED_GETDTEINFO",
                "DATA2": f"AUTHNUMBER|{auth_number}",
                "USERNAME": self.username,
            },
            headers={"Authorization": self._login()},
            timeout=self.timeout,
        )
        try:
            resp.raise_for_status()
        except requests.HTTPError as exc:
            raise DigifactApiError(
                f"GetDteInfo HTTP error: {exc}", raw=_try_json(resp)
            ) from exc
        return resp.json()

    def get_dte(self, auth_number: str, fmt: str = "JSON") -> dict:
        """Retrieve a DTE document via GET /GetDocument."""
        resp = self._session.get(
            f"{self.base_url}/GetDocument",
            params={
                "AUTHNUMBER": auth_number,
                "TAXID": self.padded_taxid,
                "FORMAT": fmt,
                "USERNAME": self.username,
            },
            headers={"Authorization": self._login()},
            timeout=self.timeout,
        )
        try:
            resp.raise_for_status()
        except requests.HTTPError as exc:
            raise DigifactApiError(
                f"GetDocument HTTP error: {exc}", raw=_try_json(resp)
            ) from exc
        return resp.json()


# ── Internal helpers ──────────────────────────────────────────────────────────

def _try_json(resp: requests.Response) -> dict:
    try:
        return resp.json()
    except Exception:
        return {"_text": resp.text}


def _check_response(data: dict) -> dict:
    """Raise DigifactValidationError if the API response indicates failure."""
    code = data.get("code")
    if code is None:
        # No code field — check for auth_number / authNumber
        auth = data.get("authNumber") or data.get("Autorizacion")
        if auth:
            return data
        # Unknown format — return as-is (let caller decide)
        return data

    code_int = int(code)
    if code_int == 1:
        return data
    if code_int == 0:
        msg = data.get("description") or data.get("message") or str(data)
        hint = classify_error(msg)
        full_msg = f"DTE rejected (code=0): {msg}"
        if hint:
            full_msg += f"\n\nHint: {hint}"
        raise DigifactValidationError(full_msg, code=code_int, raw=data)

    # code == 3000 or other warn codes — still raise so caller is aware
    msg = data.get("description") or data.get("message") or str(data)
    hint = classify_error(msg)
    full_msg = f"API warning (code={code_int}): {msg}"
    if hint:
        full_msg += f"\n\nHint: {hint}"
    raise DigifactApiError(full_msg, code=code_int, raw=data)


def _parse_nit_response(nit: str, data: Any) -> dict:
    """Normalize NIT lookup response to a standard dict.

    The API returns: {"REQUEST_DATA": [...], "RESPONSE": [{"NOMBRE": ..., "Direccion": ...,
    "MUNICIPIO": ..., "DEPARTAMENTO": ...}]}
    """
    # Unwrap envelope: {"RESPONSE": [...]} or {"REQUEST_DATA": [...], "RESPONSE": [...]}
    if isinstance(data, dict) and "RESPONSE" in data:
        response_list = data["RESPONSE"]
        if isinstance(response_list, list) and response_list:
            return _parse_nit_response(nit, response_list[0])
        return {"nit": nit, "name": "", "address": "CIUDAD", "city": "01010",
                "district": "GUATEMALA", "state": "GUATEMALA"}

    if isinstance(data, dict):
        # Direct row dict with API keys
        name = (
            data.get("NOMBRE") or data.get("nombre")
            or data.get("Name") or data.get("name") or ""
        )
        address = (
            data.get("Direccion") or data.get("direccion")
            or data.get("Address") or data.get("address") or "CIUDAD"
        )
        # MUNICIPIO and DEPARTAMENTO are uppercase in the real API response
        district = (
            data.get("MUNICIPIO") or data.get("Municipio")
            or data.get("municipio") or data.get("district") or "GUATEMALA"
        )
        state = (
            data.get("DEPARTAMENTO") or data.get("Departamento")
            or data.get("departamento") or data.get("state") or "GUATEMALA"
        )
        return {
            "nit": nit,
            "name": name.strip(),
            "address": address.strip(),
            "city": "01010",
            "district": district.strip(),
            "state": state.strip(),
        }
    if isinstance(data, list) and data:
        return _parse_nit_response(nit, data[0])
    return {"nit": nit, "name": "", "address": "CIUDAD", "city": "01010",
            "district": "GUATEMALA", "state": "GUATEMALA"}
