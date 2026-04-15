"""DTE payload builders for all supported Digifact FEL document types.

Key intentional API typos preserved from the SAT/Digifact spec:
  - Seller.AdditionlInfo  (missing 'a' in Additional)
  - AdditionalDocumentInfo → AdditionalInfo[].AditionalData  (missing 'd')
  - AdditionalDocumentInfo → AdditionalInfo[].AditionalInfo  (missing 'd')
"""
from __future__ import annotations

from decimal import Decimal
from typing import Any

from .tax import LineCalc, FuelLineCalc, InvoiceTotals, fmt, gt_now

# ── No-IVA document types ─────────────────────────────────────────────────────
NO_IVA_TYPES = {"FPEQ", "NABN", "RDON", "RECI"}

# ── ADENDA codes ──────────────────────────────────────────────────────────────
ADENDA_CODE_STD = "FRONT-263C-444B-89BA-6F87EC1330C0"
ADENDA_CODE_ALT = "FRONT-67C1-4545-BA1E-AA3C115E18D6"

# Types that use the alt ADENDA code
_ALT_ADENDA_TYPES = {"NABN", "RDON", "RECI"}


def _adenda_code(doc_type: str) -> str:
    return ADENDA_CODE_ALT if doc_type in _ALT_ADENDA_TYPES else ADENDA_CODE_STD


# ─────────────────────────────────────────────────────────────────────────────
# Internal helpers
# ─────────────────────────────────────────────────────────────────────────────

def _build_seller(
    taxid: str,
    name: str,
    address: str,
    *,
    afiliacion: str = "GEN",
    tipo_frase: str | None = "1",
    escenario: str | None = "1",
    branch_code: str = "1",
    branch_name: str = "ESTABLECIMIENTO PRINCIPAL",
    city: str = "01010",
    district: str = "Guatemala",
    state: str = "Guatemala",
    country: str = "GT",
    email: str | None = None,
) -> dict:
    seller: dict[str, Any] = {
        "TaxID": taxid,
        "TaxIDAdditionalInfo": [
            {"Name": "AfiliacionIVA", "Data": None, "Value": afiliacion}
        ],
        "Name": name,
        "BranchInfo": {
            "Code": branch_code,
            "Name": branch_name,
            "AddressInfo": {
                "Address": address,
                "City": city,
                "District": district,
                "State": state,
                "Country": country,
            },
        },
    }
    if email:
        seller["Contact"] = {"EmailList": {"Email": [email]}}
    # AdditionlInfo — intentional typo in API spec
    if tipo_frase is not None and escenario is not None:
        seller["AdditionlInfo"] = [
            {"Name": "TipoFrase", "Data": "1", "Value": tipo_frase},
            {"Name": "Escenario", "Data": "1", "Value": escenario},
        ]
    return seller


def _build_buyer_cf() -> dict:
    return {
        "TaxID": "CF",
        "Name": "CONSUMIDOR FINAL",
        "AddressInfo": {
            "Address": "CIUDAD",
            "City": "01010",
            "District": "GUATEMALA",
            "State": "GUATEMALA",
            "Country": "GT",
        },
    }


def _build_buyer_nit(
    nit: str,
    name: str,
    address: str = "CIUDAD",
    city: str = "01010",
    district: str = "GUATEMALA",
    state: str = "GUATEMALA",
    country: str = "GT",
    email: str | None = None,
) -> dict:
    buyer: dict[str, Any] = {
        "TaxID": nit,
        "Name": name,
        "AddressInfo": {
            "Address": address,
            "City": city,
            "District": district,
            "State": state,
            "Country": country,
        },
    }
    if email:
        buyer["Contact"] = {"EmailList": {"Email": [email]}}
    return buyer


def _build_buyer_cui(taxid: str, name: str) -> dict:
    return {
        "TaxID": taxid,
        "TaxIDType": "CUI",
        "Name": name,
        "AddressInfo": {
            "Address": "CIUDAD",
            "City": "01010",
            "District": "GUATEMALA",
            "State": "GUATEMALA",
            "Country": "GT",
        },
    }


def _build_items(
    items: list[dict],
    taxable: bool = True,
) -> tuple[list[dict], InvoiceTotals]:
    """Build Items list and InvoiceTotals from a list of item dicts.

    Each item dict supports:
        description: str
        qty: float | Decimal   (default 1)
        price: float | Decimal  (unit price, IVA-inclusive)
        type: str               (default "Servicio")
        unit_of_measure: str    (default "UNI")
        discount: float | None  (line-level discount amount)
    """
    line_items = []
    lines: list[LineCalc] = []

    for i, item in enumerate(items, start=1):
        qty = Decimal(str(item.get("qty", 1)))
        price = Decimal(str(item["price"]))
        item_type = item.get("type", "Servicio")
        uom = item.get("unit_of_measure", "UNI")
        desc = item["description"]
        discount_val = item.get("discount")
        discount = Decimal(str(discount_val)) if discount_val is not None else None

        lc = LineCalc(qty, price, taxable=taxable, discount=discount)
        lines.append(lc)

        built: dict[str, Any] = {
            "Number": str(i),
            "Codes": None,
            "Type": item_type,
            "Description": desc,
            "Qty": lc.f_qty(),
            "UnitOfMeasure": uom,
            "Price": lc.f_price(),
        }

        if discount is not None and discount > 0:
            built["Discounts"] = {"Discount": [{"Amount": lc.f_discount()}]}
        else:
            built["Discounts"] = None

        if taxable:
            built["Taxes"] = {
                "Tax": [
                    {
                        "Code": "1",
                        "Description": "IVA",
                        "TaxableAmount": lc.f_taxable(),
                        "Amount": lc.f_iva(),
                    }
                ]
            }
        else:
            built["Taxes"] = None

        built["Totals"] = {"TotalItem": lc.f_line_total()}
        line_items.append(built)

    totals = InvoiceTotals(lines)
    return line_items, totals


def _build_totals(totals: InvoiceTotals, taxable: bool = True) -> dict:
    result: dict[str, Any] = {}
    if taxable:
        result["TotalTaxes"] = {
            "TotalTax": [{"Description": "IVA", "Amount": totals.f_total_iva()}]
        }
    result["GrandTotal"] = {"InvoiceTotal": totals.f_grand_total()}
    return result


def _adenda_entry(
    index: int,
    ean_code: str = "00001",
) -> dict:
    """Build a DetallesAux_Detalle ADENDA entry for a single line item."""
    return {
        "Name": "DetallesAux_Detalle",
        "Info": [
            {"Name": "NumeroLinea", "Data": None, "Value": str(index)},
            {"Name": "Descripcion_Adicional", "Data": None, "Value": "-"},
            {"Name": "CodigoEAN", "Data": None, "Value": ean_code},
            {"Name": "CategoriaAdicional", "Data": None, "Value": "-"},
        ],
    }


def _build_adenda(
    doc_type: str,
    amount_str: str,
    num_items: int,
    observaciones: str = "-",
    extra_aditional_info: list[dict] | None = None,
) -> dict:
    """Build the standard ADENDA AdditionalDocumentInfo block."""
    code = _adenda_code(doc_type)

    data = [
        {
            "Name": "INFORMACION_ADICIONAL",
            "Info": [
                {"Name": "OBSERVACIONES", "Data": None, "Value": observaciones},
                {"Name": "CANTIDAD_LETRAS", "Data": None, "Value": amount_str},
            ],
        }
    ]
    for i in range(1, num_items + 1):
        data.append(_adenda_entry(i))

    aditional_info = [
        {"Name": "VALIDAR_REFERENCIA_INTERNA", "Data": None, "Value": "NO_VALIDAR"}
    ]
    if extra_aditional_info:
        aditional_info.extend(extra_aditional_info)

    return {
        "AdditionalInfo": [
            {
                "Code": code,
                "Type": "ADENDA",
                "AditionalData": {"Data": data},
                "AditionalInfo": aditional_info,
            }
        ]
    }


# ─────────────────────────────────────────────────────────────────────────────
# Public builder functions
# ─────────────────────────────────────────────────────────────────────────────

def build_fact(
    taxid: str,
    seller_name: str,
    seller_address: str,
    buyer: dict,
    items: list[dict],
    *,
    doc_type: str = "FACT",
    afiliacion: str = "GEN",
    tipo_frase: str = "1",
    escenario: str = "1",
    amount_str: str = "",
    observaciones: str = "-",
    extra_header: dict | None = None,
    seller_email: str | None = None,
) -> dict:
    """Build a FACT (or FACT-like) DTE payload."""
    issue_dt, _, _ = gt_now()

    taxable = doc_type not in NO_IVA_TYPES
    line_items, totals = _build_items(items, taxable=taxable)

    seller = _build_seller(
        taxid,
        seller_name,
        seller_address,
        afiliacion=afiliacion,
        tipo_frase=tipo_frase,
        escenario=escenario,
        email=seller_email,
    )

    header: dict[str, Any] = {
        "DocType": doc_type,
        "IssuedDateTime": issue_dt,
        "Currency": "GTQ",
    }
    if extra_header:
        header.update(extra_header)

    amt = amount_str or totals.f_grand_total()
    adenda = _build_adenda(doc_type, amt, len(items), observaciones=observaciones)

    payload: dict[str, Any] = {
        "Version": "1.00",
        "CountryCode": "GT",
        "Header": header,
        "Seller": seller,
        "Buyer": buyer,
        "ThirdParties": None,
        "Items": line_items,
        "Totals": _build_totals(totals, taxable=taxable),
        "AdditionalDocumentInfo": adenda,
    }
    return payload


def build_fcam(
    taxid: str,
    seller_name: str,
    seller_address: str,
    buyer: dict,
    items: list[dict],
    payment_terms: list[dict],
    *,
    afiliacion: str = "GEN",
    amount_str: str = "",
    observaciones: str = "-",
    seller_email: str | None = None,
) -> dict:
    """Build a FCAM (Factura Cambiaria) DTE payload.

    payment_terms: list of {"date": "YYYY-MM-DD", "amount": float}
    """
    issue_dt, _, _ = gt_now()

    line_items, totals = _build_items(items, taxable=True)

    seller = _build_seller(
        taxid,
        seller_name,
        seller_address,
        afiliacion=afiliacion,
        tipo_frase="1",
        escenario="1",
        email=seller_email,
    )

    # FCAM complemento
    fcam_data = []
    for idx, pt in enumerate(payment_terms, start=1):
        amount_val = Decimal(str(pt["amount"]))
        fcam_data.append(
            {
                "Info": [
                    {"Name": "NumeroAbono", "Data": None, "Value": str(idx)},
                    {"Name": "FechaVencimiento", "Data": None, "Value": pt["date"]},
                    {"Name": "MontoAbono", "Data": None, "Value": fmt(amount_val, decimals=2)},
                ]
            }
        )

    payload: dict[str, Any] = {
        "Version": "1.00",
        "CountryCode": "GT",
        "Header": {"DocType": "FCAM", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": seller,
        "Buyer": buyer,
        "ThirdParties": None,
        "Items": line_items,
        "Totals": _build_totals(totals, taxable=True),
        "AdditionalDocumentInfo": {
            "AdditionalInfo": [
                {
                    "Code": "FCAMB",
                    "Type": "COMPLEMENTO",
                    "AditionalData": {"Data": fcam_data},
                }
            ]
        },
    }
    return payload


def build_ndeb(
    taxid: str,
    seller_name: str,
    seller_address: str,
    buyer: dict,
    items: list[dict],
    origin: dict,
    reason: str,
    *,
    afiliacion: str = "GEN",
    seller_email: str | None = None,
) -> dict:
    """Build a NDEB (Nota de Débito) DTE payload.

    origin: {"auth_number": str, "date": "YYYY-MM-DD", "series": str, "number": str}
    """
    issue_dt, _, _ = gt_now()
    line_items, totals = _build_items(items, taxable=True)

    seller = _build_seller(
        taxid,
        seller_name,
        seller_address,
        afiliacion=afiliacion,
        tipo_frase="1",
        escenario="1",
        email=seller_email,
    )

    payload: dict[str, Any] = {
        "Version": "1.00",
        "CountryCode": "GT",
        "Header": {"DocType": "NDEB", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": seller,
        "Buyer": buyer,
        "ThirdParties": None,
        "Items": line_items,
        "Totals": _build_totals(totals, taxable=True),
        "AdditionalDocumentInfo": {
            "AdditionalInfo": [
                {
                    "Code": "NDEB",
                    "Type": "COMPLEMENTO",
                    # NDEB uses AditionalInfo (not AditionalData) — intentional API naming
                    "AditionalInfo": [
                        {
                            "Name": "NumeroAutorizacionDocumentoOrigen",
                            "Data": None,
                            "Value": origin["auth_number"],
                        },
                        {
                            "Name": "FechaEmisionDocumentoOrigen",
                            "Data": None,
                            "Value": origin["date"],
                        },
                        {
                            "Name": "MotivoAjuste",
                            "Data": None,
                            "Value": reason,
                        },
                        {
                            "Name": "SerieDocumentoOrigen",
                            "Data": None,
                            "Value": origin["series"],
                        },
                        {
                            "Name": "NumeroDocumentoOrigen",
                            "Data": None,
                            "Value": str(origin["number"]),
                        },
                    ],
                }
            ]
        },
    }
    return payload


def build_ncre(
    taxid: str,
    seller_name: str,
    seller_address: str,
    buyer: dict,
    items: list[dict],
    origin: dict,
    reason: str,
    *,
    afiliacion: str = "GEN",
    seller_email: str | None = None,
) -> dict:
    """Build a NCRE (Nota de Crédito) DTE payload.

    origin: {"auth_number": str, "date": "YYYY-MM-DD", "series": str, "number": str}
    """
    issue_dt, _, _ = gt_now()
    line_items, totals = _build_items(items, taxable=True)

    seller = _build_seller(
        taxid,
        seller_name,
        seller_address,
        afiliacion=afiliacion,
        tipo_frase="1",
        escenario="1",
        email=seller_email,
    )

    payload: dict[str, Any] = {
        "Version": "1.00",
        "CountryCode": "GT",
        "Header": {"DocType": "NCRE", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": seller,
        "Buyer": buyer,
        "ThirdParties": None,
        "Items": line_items,
        "Totals": _build_totals(totals, taxable=True),
        "AdditionalDocumentInfo": {
            "AdditionalInfo": [
                {
                    "Code": "NCRE",
                    "Type": "COMPLEMENTO",
                    # NCRE uses AditionalInfo (not AditionalData) — intentional API naming
                    "AditionalInfo": [
                        {
                            "Name": "NumeroAutorizacionDocumentoOrigen",
                            "Data": None,
                            "Value": origin["auth_number"],
                        },
                        {
                            "Name": "FechaEmisionDocumentoOrigen",
                            "Data": None,
                            "Value": origin["date"],
                        },
                        {
                            "Name": "MotivoAjuste",
                            "Data": None,
                            "Value": reason,
                        },
                        {
                            "Name": "NumeroDocumentoOrigen",
                            "Data": None,
                            "Value": str(origin["number"]),
                        },
                        {
                            "Name": "SerieDocumentoOrigen",
                            "Data": None,
                            "Value": origin["series"],
                        },
                    ],
                }
            ]
        },
    }
    return payload


def build_nabn(
    taxid: str,
    seller_name: str,
    seller_address: str,
    buyer: dict,
    items: list[dict],
    *,
    afiliacion: str = "GEN",
    amount_str: str = "",
    observaciones: str = "-",
    seller_email: str | None = None,
) -> dict:
    """Build a NABN (Nota de Abono) DTE payload — no IVA."""
    issue_dt, _, _ = gt_now()
    line_items, totals = _build_items(items, taxable=False)

    # NABN: uses TipoFrase=1, Escenario=1 in seller (matches smoke runner)
    seller = _build_seller(
        taxid,
        seller_name,
        seller_address,
        afiliacion=afiliacion,
        tipo_frase="1",
        escenario="1",
        email=seller_email,
    )

    amt = amount_str or totals.f_grand_total()
    adenda = _build_adenda("NABN", amt, len(items), observaciones=observaciones)

    payload: dict[str, Any] = {
        "Version": "1.00",
        "CountryCode": "GT",
        "Header": {"DocType": "NABN", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": seller,
        "Buyer": buyer,
        "ThirdParties": None,
        "Items": line_items,
        "Totals": _build_totals(totals, taxable=False),
        "AdditionalDocumentInfo": adenda,
    }
    return payload


def build_fesp(
    taxid: str,
    seller_name: str,
    seller_address: str,
    buyer: dict,
    items: list[dict],
    *,
    afiliacion: str = "GEN",
    seller_email: str | None = None,
) -> dict:
    """Build a FESP (Factura Especial) DTE payload.

    FESP has no AdditionlInfo in Seller (no TipoFrase/Escenario),
    and includes FESP complemento with retenciones.
    """
    issue_dt, _, _ = gt_now()
    line_items, totals = _build_items(items, taxable=True)

    # FESP: explicitly no AdditionlInfo
    seller = _build_seller(
        taxid,
        seller_name,
        seller_address,
        afiliacion=afiliacion,
        tipo_frase=None,
        escenario=None,
        email=seller_email,
    )

    # FESP retenciones: RetencionISR = net_price * 0.05, RetencionIVA = total_iva
    retencion_isr = (totals.total_taxable * Decimal("0.05")).quantize(
        Decimal("0.000001"), rounding=__import__("decimal").ROUND_HALF_UP
    )
    retencion_iva = totals.total_iva
    total_menos = (totals.grand_total - retencion_isr - retencion_iva).quantize(
        Decimal("0.000001"), rounding=__import__("decimal").ROUND_HALF_UP
    )

    payload: dict[str, Any] = {
        "Version": "1.00",
        "CountryCode": "GT",
        "Header": {"DocType": "FESP", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": seller,
        "Buyer": buyer,
        "ThirdParties": None,
        "Items": line_items,
        "Totals": _build_totals(totals, taxable=True),
        "AdditionalDocumentInfo": {
            "AdditionalInfo": [
                {
                    "Code": "FESP",
                    "Type": "COMPLEMENTO",
                    "AditionalInfo": [
                        {"Name": "RetencionISR", "Data": None, "Value": fmt(retencion_isr)},
                        {"Name": "RetencionIVA", "Data": None, "Value": fmt(retencion_iva)},
                        {"Name": "TotalMenosRetenciones", "Data": None, "Value": fmt(total_menos)},
                    ],
                }
            ]
        },
    }
    return payload


def build_rdon(
    taxid: str,
    seller_name: str,
    seller_address: str,
    buyer: dict,
    items: list[dict],
    tipo_personeria: str,
    *,
    afiliacion: str = "GEN",
    amount_str: str = "",
    observaciones: str = "-",
    seller_email: str | None = None,
) -> dict:
    """Build a RDON (Recibo por Donación) DTE payload — no IVA."""
    issue_dt, _, _ = gt_now()
    line_items, totals = _build_items(items, taxable=False)

    seller = _build_seller(
        taxid,
        seller_name,
        seller_address,
        afiliacion=afiliacion,
        tipo_frase="4",
        escenario="4",
        email=seller_email,
    )

    amt = amount_str or totals.f_grand_total()
    adenda = _build_adenda("RDON", amt, len(items), observaciones=observaciones)

    payload: dict[str, Any] = {
        "Version": "1.00",
        "CountryCode": "GT",
        "Header": {
            "DocType": "RDON",
            "IssuedDateTime": issue_dt,
            "Currency": "GTQ",
            "AdditionalIssueDocInfo": [
                {"Name": "TipoPersoneria", "Data": None, "Value": tipo_personeria}
            ],
        },
        "Seller": seller,
        "Buyer": buyer,
        "ThirdParties": None,
        "Items": line_items,
        "Totals": _build_totals(totals, taxable=False),
        "AdditionalDocumentInfo": adenda,
    }
    return payload


def build_fpeq(
    taxid: str,
    seller_name: str,
    seller_address: str,
    buyer: dict,
    items: list[dict],
    *,
    amount_str: str = "",
    observaciones: str = "-",
    seller_email: str | None = None,
) -> dict:
    """Build a FPEQ (Factura Pequeño Contribuyente) DTE payload — no IVA, AfiliacionIVA=PEQ."""
    issue_dt, _, _ = gt_now()
    line_items, totals = _build_items(items, taxable=False)

    seller = _build_seller(
        taxid,
        seller_name,
        seller_address,
        afiliacion="PEQ",
        tipo_frase="3",
        escenario="1",
        email=seller_email,
    )

    amt = amount_str or totals.f_grand_total()
    adenda = _build_adenda("FPEQ", amt, len(items), observaciones=observaciones)

    payload: dict[str, Any] = {
        "Version": "1.00",
        "CountryCode": "GT",
        "Header": {"DocType": "FPEQ", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": seller,
        "Buyer": buyer,
        "ThirdParties": None,
        "Items": line_items,
        "Totals": _build_totals(totals, taxable=False),
        "AdditionalDocumentInfo": adenda,
    }
    return payload


def build_reci(
    taxid: str,
    seller_name: str,
    seller_address: str,
    buyer: dict,
    items: list[dict],
    *,
    afiliacion: str = "GEN",
    amount_str: str = "",
    observaciones: str = "-",
    student_name: str = "Estudiante",
    student_id: str = "000000000",
    academic_unit: str = "01",
    seller_email: str | None = None,
) -> dict:
    """Build a RECI (Recibo) DTE payload — no IVA, universidad ADENDA."""
    issue_dt, _, _ = gt_now()
    line_items, totals = _build_items(items, taxable=False)

    seller = _build_seller(
        taxid,
        seller_name,
        seller_address,
        afiliacion=afiliacion,
        tipo_frase="4",
        escenario="5",
        email=seller_email,
    )

    amt = amount_str or totals.f_grand_total()

    # RECI: extra AditionalInfo entries for university data
    extra_info = [
        {"Name": "Tipo", "Data": None, "Value": "Universidad"},
        {"Name": "NombreAlumno", "Data": None, "Value": student_name},
        {"Name": "Carne", "Data": None, "Value": student_id},
        {"Name": "UnidadAcademica", "Data": None, "Value": academic_unit},
    ]
    adenda = _build_adenda(
        "RECI", amt, len(items), observaciones=observaciones, extra_aditional_info=extra_info
    )

    payload: dict[str, Any] = {
        "Version": "1.00",
        "CountryCode": "GT",
        "Header": {"DocType": "RECI", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": seller,
        "Buyer": buyer,
        "ThirdParties": None,
        "Items": line_items,
        "Totals": _build_totals(totals, taxable=False),
        "AdditionalDocumentInfo": adenda,
    }
    return payload


def build_cca(
    taxid: str,
    seller_name: str,
    seller_address: str,
    buyer: dict,
    items: list[dict],
    cobros: list[dict],
    *,
    afiliacion: str = "GEN",
    seller_email: str | None = None,
) -> dict:
    """Build a CCA (Cobro por Cuenta Ajena) FACT+CCA complemento payload.

    cobros: list of {
        "nit_tercero": str,
        "numero_documento": str | int,
        "fecha_documento": "YYYY-MM-DD",
        "descripcion": str,
        "base_imponible": float,
        "monto_cobro_dai": float = 0,
        "monto_cobro_iva": float,
        "monto_cobro_otros": float = 0,
        "monto_cobro_total": float,
    }
    """
    issue_dt, _, _ = gt_now()
    line_items, totals = _build_items(items, taxable=True)

    seller = _build_seller(
        taxid,
        seller_name,
        seller_address,
        afiliacion=afiliacion,
        tipo_frase="1",
        escenario="1",
        email=seller_email,
    )

    cca_data = []
    for cobro in cobros:
        cca_data.append(
            {
                "Info": [
                    {"Name": "NITtercero", "Data": None, "Value": str(cobro["nit_tercero"])},
                    {
                        "Name": "NumeroDocumento",
                        "Data": None,
                        "Value": str(cobro["numero_documento"]),
                    },
                    {
                        "Name": "FechaDocumento",
                        "Data": None,
                        "Value": cobro["fecha_documento"],
                    },
                    {"Name": "Descripcion", "Data": None, "Value": cobro["descripcion"]},
                    {
                        "Name": "BaseImponible",
                        "Data": None,
                        "Value": fmt(Decimal(str(cobro["base_imponible"])), decimals=2),
                    },
                    {
                        "Name": "MontoCobroDAI",
                        "Data": None,
                        "Value": fmt(Decimal(str(cobro.get("monto_cobro_dai", 0))), decimals=2),
                    },
                    {
                        "Name": "MontoCobroIVA",
                        "Data": None,
                        "Value": fmt(Decimal(str(cobro["monto_cobro_iva"])), decimals=2),
                    },
                    {
                        "Name": "MontoCobroOtros",
                        "Data": None,
                        "Value": fmt(
                            Decimal(str(cobro.get("monto_cobro_otros", 0))), decimals=2
                        ),
                    },
                    {
                        "Name": "MontoCobroTotal",
                        "Data": None,
                        "Value": fmt(Decimal(str(cobro["monto_cobro_total"])), decimals=2),
                    },
                ]
            }
        )

    payload: dict[str, Any] = {
        "Version": "1.00",
        "CountryCode": "GT",
        "Header": {"DocType": "FACT", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": seller,
        "Buyer": buyer,
        "ThirdParties": None,
        "Items": line_items,
        "Totals": _build_totals(totals, taxable=True),
        "AdditionalDocumentInfo": {
            "AdditionalInfo": [
                {
                    "Code": "CCA",
                    "Type": "COMPLEMENTO",
                    "AditionalData": {"Data": cca_data},
                }
            ]
        },
    }
    return payload


# ───────────────────────────────────────────────────────────────────────────────
# Combustible (fuel) builder
# ───────────────────────────────────────────────────────────────────────────────


def _build_fuel_items(
    items: list[dict],
) -> tuple[list[dict], str, str, str]:
    """Build Items list for a combustible (fuel) invoice.

    Items with 'petroleo_amount' are treated as fuel items and receive two
    Tax entries (IVA + PETROLEO). Items without 'petroleo_amount' are treated
    as regular IVA-only items. Both types may coexist in the same invoice.

    Returns (line_items, grand_total_str, total_iva_str, total_petroleo_str).
    """
    line_items: list[dict] = []
    grand_total    = Decimal("0")
    total_iva      = Decimal("0")
    total_petroleo = Decimal("0")

    for i, item in enumerate(items, start=1):
        qty   = Decimal(str(item.get("qty", 1)))
        price = Decimal(str(item["price"]))
        item_type = item.get("type", "Bien")
        uom       = item.get("unit_of_measure", "UNI")
        desc      = item["description"]

        built: dict[str, Any] = {
            "Number":        str(i),
            "Codes":         None,
            "Type":          item_type,
            "Description":   desc,
            "UnitOfMeasure": uom,
            "Discounts":     None,
        }

        if "petroleo_amount" in item:
            petrol_per_unit = Decimal(str(item["petroleo_amount"]))
            petrol_code     = str(item.get("petroleo_code", "1"))

            fc = FuelLineCalc(qty, price, petrol_per_unit)

            built["Qty"]   = fc.f_qty()
            built["Price"] = fc.f_price()
            built["Taxes"] = {
                "Tax": [
                    {
                        "Code":          "1",
                        "Description":   "IVA",
                        "TaxableAmount": fc.f_taxable(),
                        "Amount":        fc.f_iva(),
                    },
                    {
                        "Code":          petrol_code,
                        "Description":   "PETROLEO",
                        "TaxableAmount": fc.f_qty(),
                        "Amount":        fc.f_petrol(),
                    },
                ]
            }
            built["Totals"] = {"TotalItem": fc.f_line_total()}

            grand_total    += fc.line_total
            total_iva      += fc.iva_amount
            total_petroleo += fc.petrol_total
        else:
            lc = LineCalc(qty, price, taxable=True)

            built["Qty"]   = lc.f_qty()
            built["Price"] = lc.f_price()
            built["Taxes"] = {
                "Tax": [
                    {
                        "Code":          "1",
                        "Description":   "IVA",
                        "TaxableAmount": lc.f_taxable(),
                        "Amount":        lc.f_iva(),
                    }
                ]
            }
            built["Totals"] = {"TotalItem": lc.f_line_total()}

            grand_total += lc.line_total
            total_iva   += lc.iva_amount

        line_items.append(built)

    return (
        line_items,
        fmt(grand_total),
        fmt(total_iva),
        fmt(total_petroleo),
    )


def _build_fuel_adenda() -> dict:
    return {
        "AdditionalInfo": [
            {
                "Code":  "00000013",
                "Type":  "ADENDA",
                "AditionalInfo": [
                    {"Name": "VALIDAR_REFERENCIA_INTERNA", "Data": None, "Value": "NO_VALIDAR"},
                ],
            }
        ]
    }


def build_fact_combustible(
    taxid: str,
    seller_name: str,
    seller_address: str,
    buyer: dict,
    items: list[dict],
    *,
    afiliacion: str = "GEN",
    tipo_frase: str = "1",
    escenario: str = "1",
    seller_email: str | None = None,
) -> dict:
    """Build a FACT payload for combustible (fuel) invoices.

    Fuel items must include ``petroleo_amount`` (per-unit PETROLEO tax amount)
    and optionally ``petroleo_code`` (SAT code: "1"=SUPER, "2"=REGULAR,
    "4"=DIESEL; default "1"). Items without ``petroleo_amount`` are treated as
    regular IVA-only items and may coexist in the same invoice.

    Example fuel item::

        {"description": "GASOLINA SUPER", "qty": 1, "price": 30.30,
         "petroleo_amount": 4.70, "petroleo_code": "1", "type": "Bien"}

    Example regular item in the same invoice::

        {"description": "FILTRO DE ACEITE", "qty": 1, "price": 45.00, "type": "Bien"}
    """
    issue_dt, _, _ = gt_now()

    line_items, grand_total, total_iva, total_petroleo = _build_fuel_items(items)

    seller = _build_seller(
        taxid,
        seller_name,
        seller_address,
        afiliacion=afiliacion,
        tipo_frase=tipo_frase,
        escenario=escenario,
        email=seller_email,
    )

    total_tax: list[dict] = [{"Description": "IVA", "Amount": total_iva}]
    if float(total_petroleo) > 0.0:
        total_tax.append({"Description": "PETROLEO", "Amount": total_petroleo})

    return {
        "Version": "1.00",
        "CountryCode": "GT",
        "Header": {"DocType": "FACT", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": seller,
        "Buyer": buyer,
        "ThirdParties": None,
        "Items": line_items,
        "Totals": {
            "TotalTaxes": {"TotalTax": total_tax},
            "GrandTotal": {"InvoiceTotal": grand_total},
        },
        "AdditionalDocumentInfo": _build_fuel_adenda(),
    }
