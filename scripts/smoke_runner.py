#!/usr/bin/env python3
import os
import json
import sys
from datetime import datetime, timezone, timedelta
import requests

BASE_URL = os.getenv("DIGIFACT_BASE_URL", "https://testnucgt.digifact.com/api").rstrip("/")
TAXID = os.getenv("DIGIFACT_TAXID", "").strip()
USERNAME = os.getenv("DIGIFACT_USERNAME", "").strip()
PASSWORD = os.getenv("DIGIFACT_PASSWORD", "").strip()
TOKEN = os.getenv("DIGIFACT_TOKEN", "").strip()

if not TAXID or not USERNAME:
    print("Faltan DIGIFACT_TAXID y/o DIGIFACT_USERNAME")
    sys.exit(2)

PADDED = "".join(ch for ch in TAXID if ch.isdigit()).rjust(12, "0")
FULL_USERNAME = f"GT.{PADDED}.{USERNAME}"

# Buyer NIT for non-CF DTEs (datos de prueba ficticios)
BUYER_NIT = "77454820"
BUYER_NIT_NAME = "EMPRESA EJEMPLO SOCIEDAD ANONIMA"
BUYER_NIT_ADDRESS = "1 AVENIDA 1-00 ZONA 1"
BUYER_NIT_CITY = "01001"
BUYER_NIT_DISTRICT = "GUATEMALA"
BUYER_NIT_STATE = "GUATEMALA"
BUYER_NIT_COUNTRY = "GT"

# Buyer CUI
BUYER_CUI = "1234567890101"
BUYER_CUI_NAME = "COMPRADOR EJEMPLO"

# Buyer FESP
BUYER_FESP = "11111111"
BUYER_FESP_NAME = "PERSONA EJEMPLO SOCIEDAD ANONIMA"

# NDEB/NCRE origin document references (UUIDs ficticios de ejemplo)
ORIGIN_AUTH_NDEB = "AAAAAAAA-0000-4000-A000-000000000001"
ORIGIN_DATE_NDEB = "2024-01-15"
ORIGIN_SERIES_NDEB = "AAAAAAAA"
ORIGIN_NUMBER_NDEB = "10000001"

ORIGIN_AUTH_NCRE = "BBBBBBBB-0000-4000-B000-000000000002"
ORIGIN_DATE_NCRE = "2024-01-15"
ORIGIN_SERIES_NCRE = "BBBBBBBB"
ORIGIN_NUMBER_NCRE = "20000002"


def gt_now():
    """Return (datetime_with_offset, datetime_space, date_only) in Guatemala time (UTC-6)."""
    gt = datetime.now(timezone(timedelta(hours=-6)))
    dt_offset = gt.strftime("%Y-%m-%dT%H:%M:%S-06:00")
    dt_space = gt.strftime("%Y-%m-%d %H:%M:%S")
    date_only = gt.strftime("%Y-%m-%d")
    return dt_offset, dt_space, date_only


def login():
    global TOKEN
    if TOKEN:
        return TOKEN
    if not PASSWORD:
        raise RuntimeError("Falta DIGIFACT_PASSWORD o DIGIFACT_TOKEN")
    r = requests.post(
        f"{BASE_URL}/login/get_token",
        json={"Username": FULL_USERNAME, "Password": PASSWORD},
        timeout=60,
    )
    r.raise_for_status()
    data = r.json()
    TOKEN = data.get("Token") or data.get("token")
    if not TOKEN:
        raise RuntimeError(f"No se obtuvo token: {data}")
    return TOKEN


def headers():
    return {"Authorization": login(), "Content-Type": "application/json"}


def certify(payload):
    r = requests.post(
        f"{BASE_URL}/v2/transform/nuc_json",
        params={"TAXID": PADDED, "FORMAT": "XML|HTML|PDF", "USERNAME": USERNAME},
        headers=headers(),
        json=payload,
        timeout=120,
    )
    r.raise_for_status()
    return r.json()


# ──────────────────────────────────────────────
# DTE Payload builders
# ──────────────────────────────────────────────

def _seller(tipo_frase="1", escenario="1", afiliacion="GEN"):
    return {
        "TaxID": TAXID,
        "TaxIDAdditionalInfo": [{"Name": "AfiliacionIVA", "Data": None, "Value": afiliacion}],
        "Name": "FEL TEST",
        "Contact": {"EmailList": {"Email": ["fel@example.com"]}},
        "AdditionlInfo": [
            {"Name": "TipoFrase", "Data": "1", "Value": tipo_frase},
            {"Name": "Escenario", "Data": "1", "Value": escenario},
        ],
        "BranchInfo": {
            "Code": "1",
            "Name": "ESTABLECIMIENTO DE PRUEBA",
            "AddressInfo": {
                "Address": "1 AVENIDA 1-00 ZONA 1 EDIFICIO EJEMPLO",
                "City": "01010",
                "District": "Guatemala",
                "State": "Guatemala",
                "Country": "GT",
            },
        },
    }


def _buyer_cf():
    return {
        "TaxID": "CF",
        "Name": "CONSUMIDOR FINAL",
        "AddressInfo": {
            "Address": "CIUDAD", "City": "01010",
            "District": "GUATEMALA", "State": "GUATEMALA", "Country": "GT",
        },
    }


def _buyer_nit():
    return {
        "TaxID": BUYER_NIT,
        "Name": BUYER_NIT_NAME,
        "AddressInfo": {
            "Address": BUYER_NIT_ADDRESS, "City": BUYER_NIT_CITY,
            "District": BUYER_NIT_DISTRICT, "State": BUYER_NIT_STATE,
            "Country": BUYER_NIT_COUNTRY,
        },
    }


def _adenda_std(amount_str, observaciones="-"):
    return {
        "AdditionalInfo": [{
            "Code": "FRONT-263C-444B-89BA-6F87EC1330C0",
            "Type": "ADENDA",
            "AditionalData": {
                "Data": [
                    {
                        "Name": "INFORMACION_ADICIONAL",
                        "Info": [
                            {"Name": "OBSERVACIONES", "Data": None, "Value": observaciones},
                            {"Name": "CANTIDAD_LETRAS", "Data": None, "Value": amount_str},
                        ],
                    },
                    {
                        "Name": "DetallesAux_Detalle",
                        "Info": [
                            {"Name": "NumeroLinea", "Data": None, "Value": "1"},
                            {"Name": "Descripcion_Adicional", "Data": None, "Value": "-"},
                            {"Name": "CodigoEAN", "Data": None, "Value": "00011"},
                            {"Name": "CategoriaAdicional", "Data": None, "Value": "-"},
                        ],
                    },
                ]
            },
            "AditionalInfo": [{"Name": "VALIDAR_REFERENCIA_INTERNA", "Data": None, "Value": "NO_VALIDAR"}],
        }]
    }


def fact_cf_payload():
    issue_dt, _, _ = gt_now()
    return {
        "Version": "1.00", "CountryCode": "GT",
        "Header": {"DocType": "FACT", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": _seller(),
        "Buyer": _buyer_cf(),
        "ThirdParties": None,
        "Items": [
            {
                "Number": "1", "Codes": None, "Type": "Servicio", "Description": "prueba",
                "Qty": "1.000000", "UnitOfMeasure": "SER", "Price": "31.000000", "Discounts": None,
                "Taxes": {"Tax": [{"Code": "1", "Description": "IVA", "TaxableAmount": "27.678571", "Amount": "3.321428"}]},
                "Totals": {"TotalItem": "31.000000"},
            },
            {
                "Number": "2", "Codes": None, "Type": "Bien", "Description": "Jeans",
                "Qty": "1.000000", "UnitOfMeasure": "UNI", "Price": "600.000000", "Discounts": None,
                "Taxes": {"Tax": [{"Code": "1", "Description": "IVA", "TaxableAmount": "537.714286", "Amount": "64.285714"}]},
                "Totals": {"TotalItem": "600.000000"},
            },
        ],
        "Totals": {
            "TotalTaxes": {"TotalTax": [{"Description": "IVA", "Amount": "67.607143"}]},
            "GrandTotal": {"InvoiceTotal": "631.000000"},
        },
        "AdditionalDocumentInfo": _adenda_std("SEISCIENTOS TREINTA Y UN QUETZALES CON 00/100"),
    }


def fact_cui_payload():
    issue_dt, _, _ = gt_now()
    return {
        "Version": "1.00", "CountryCode": "GT",
        "Header": {"DocType": "FACT", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": _seller(),
        "Buyer": {
            "TaxID": BUYER_CUI,
            "TaxIDType": "CUI",
            "Name": BUYER_CUI_NAME,
            "AddressInfo": {
                "Address": "CIUDAD", "City": "01010",
                "District": "GUATEMALA", "State": "GUATEMALA", "Country": "GT",
            },
        },
        "Items": [{
            "Number": "1", "Codes": None, "Type": "Bien",
            "Description": "LLANTAS 90/90-17 TL DOBLE PROPOSITO",
            "Qty": "1.000000", "UnitOfMeasure": "UNO", "Price": "190.000000", "Discounts": None,
            "Taxes": {"Tax": [{"Code": "1", "Description": "IVA", "TaxableAmount": "169.642857", "Amount": "20.357143"}]},
            "Totals": {"TotalItem": "190.000000"},
        }],
        "Totals": {
            "TotalTaxes": {"TotalTax": [{"Description": "IVA", "Amount": "20.357143"}]},
            "GrandTotal": {"InvoiceTotal": "190.000000"},
        },
        "AdditionalDocumentInfo": _adenda_std("CIENTO NOVENTA QUETZALES CON 00/100"),
    }


def fcam_payload():
    issue_dt, _, date_only = gt_now()
    return {
        "Version": "1.00", "CountryCode": "GT",
        "Header": {"DocType": "FCAM", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": _seller(),
        "Buyer": {**_buyer_nit(), "Contact": {"EmailList": {"Email": ["comprador@example.com"]}}},
        "Items": [{
            "Number": "1", "Codes": None, "Type": "Servicio", "Description": "Servicio de prueba",
            "Qty": "1.000000", "UnitOfMeasure": "SER", "Price": "30.000000",
            "Discounts": {"Discount": [{"Amount": "0.00"}]},
            "Taxes": {"Tax": [{"Code": "1", "Description": "IVA", "TaxableAmount": "26.7857", "Amount": "3.2143"}]},
            "Totals": {"TotalItem": "30.000000"},
        }],
        "Totals": {
            "TotalTaxes": {"TotalTax": [{"Description": "IVA", "Amount": "3.2143"}]},
            "GrandTotal": {"InvoiceTotal": "30.000000"},
        },
        "AdditionalDocumentInfo": {
            "AdditionalInfo": [{
                "Code": "FCAMB",
                "Type": "COMPLEMENTO",
                "AditionalData": {
                    "Data": [{
                        "Info": [
                            {"Name": "NumeroAbono", "Data": None, "Value": "1"},
                            {"Name": "FechaVencimiento", "Data": None, "Value": date_only},
                            {"Name": "MontoAbono", "Data": None, "Value": "30.00"},
                        ]
                    }]
                },
            }]
        },
    }


def ndeb_payload(origin_auth=ORIGIN_AUTH_NDEB, origin_date=ORIGIN_DATE_NDEB,
                 origin_series=ORIGIN_SERIES_NDEB, origin_number=ORIGIN_NUMBER_NDEB):
    issue_dt, _, _ = gt_now()
    return {
        "Version": "1.00", "CountryCode": "GT",
        "Header": {"DocType": "NDEB", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": _seller(),
        "Buyer": _buyer_nit(),
        "Items": [{
            "Number": "1", "Codes": None, "Type": "Bien",
            "Description": "LLANTAS 90/90-17 TL DOBLE PROPOSITO",
            "Qty": "1.000", "UnitOfMeasure": "UNO", "Price": "19.000", "Discounts": None,
            "Taxes": {"Tax": [{"Code": "1", "Description": "IVA", "TaxableAmount": "16.964286", "Amount": "2.035714"}]},
            "Totals": {"TotalItem": "19.000000"},
        }],
        "Totals": {
            "TotalTaxes": {"TotalTax": [{"Description": "IVA", "Amount": "2.035714"}]},
            "GrandTotal": {"InvoiceTotal": "19.000000"},
        },
        "AdditionalDocumentInfo": {
            "AdditionalInfo": [{
                "Code": "NDEB",
                "Type": "COMPLEMENTO",
                "AditionalInfo": [
                    {"Name": "NumeroAutorizacionDocumentoOrigen", "Data": None, "Value": origin_auth},
                    {"Name": "FechaEmisionDocumentoOrigen", "Data": None, "Value": origin_date},
                    {"Name": "MotivoAjuste", "Data": None, "Value": "Agregado nuevo item en la misma factura"},
                    {"Name": "SerieDocumentoOrigen", "Data": None, "Value": origin_series},
                    {"Name": "NumeroDocumentoOrigen", "Data": None, "Value": origin_number},
                ],
            }]
        },
    }


def ncre_payload(origin_auth=ORIGIN_AUTH_NCRE, origin_date=ORIGIN_DATE_NCRE,
                 origin_series=ORIGIN_SERIES_NCRE, origin_number=ORIGIN_NUMBER_NCRE):
    issue_dt, _, _ = gt_now()
    return {
        "Version": "1.00", "CountryCode": "GT",
        "Header": {"DocType": "NCRE", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": _seller(),
        "Buyer": _buyer_nit(),
        "Items": [{
            "Number": "1", "Codes": None, "Type": "Bien", "Description": "NARANJA MECANICA",
            "Qty": "1.0000", "UnitOfMeasure": "CA", "Price": "10.000000", "Discounts": None,
            "Taxes": {"Tax": [{"Code": "1", "Description": "IVA", "TaxableAmount": "8.928571", "Amount": "1.071429"}]},
            "Totals": {"TotalItem": "10.000000"},
        }],
        "Totals": {
            "TotalTaxes": {"TotalTax": [{"Description": "IVA", "Amount": "1.071429"}]},
            "GrandTotal": {"InvoiceTotal": "10.000000"},
        },
        "AdditionalDocumentInfo": {
            "AdditionalInfo": [{
                "Code": "NCRE",
                "Type": "COMPLEMENTO",
                "AditionalInfo": [
                    {"Name": "NumeroAutorizacionDocumentoOrigen", "Data": None, "Value": origin_auth},
                    {"Name": "FechaEmisionDocumentoOrigen", "Data": None, "Value": origin_date},
                    {"Name": "MotivoAjuste", "Data": None, "Value": "Devolución porque el producto no salió bueno"},
                    {"Name": "NumeroDocumentoOrigen", "Data": None, "Value": origin_number},
                    {"Name": "SerieDocumentoOrigen", "Data": None, "Value": origin_series},
                ],
            }]
        },
    }


def nabn_payload():
    issue_dt, _, _ = gt_now()
    return {
        "Version": "1.00", "CountryCode": "GT",
        "Header": {"DocType": "NABN", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": _seller(),
        "Buyer": {**_buyer_nit(), "Contact": {"EmailList": {"Email": ["comprador@example.com"]}}},
        "Items": [{
            "Number": "1", "Codes": None, "Type": "Bien", "Description": "RETENEDOR BLANCO",
            "Qty": "1.000000", "UnitOfMeasure": "UNI", "Price": "100.000000", "Discounts": None,
            "Taxes": None,
            "Totals": {"TotalItem": "100.000000"},
        }],
        "Totals": {"GrandTotal": {"InvoiceTotal": "100.000000"}},
        "AdditionalDocumentInfo": {
            "AdditionalInfo": [{
                "Code": "FRONT-67C1-4545-BA1E-AA3C115E18D6",
                "Type": "ADENDA",
                "AditionalData": {
                    "Data": [
                        {
                            "Name": "INFORMACION_ADICIONAL",
                            "Info": [
                                {"Name": "OBSERVACIONES", "Data": None, "Value": "-"},
                                {"Name": "CANTIDAD_LETRAS", "Data": None, "Value": "CIEN QUETZALES CON 00/100"},
                            ],
                        },
                        {
                            "Name": "DetallesAux_Detalle",
                            "Info": [
                                {"Name": "NumeroLinea", "Data": None, "Value": "1"},
                                {"Name": "Descripcion_Adicional", "Data": None, "Value": "-"},
                                {"Name": "CodigoEAN", "Data": None, "Value": "1000"},
                                {"Name": "CategoriaAdicional", "Data": None, "Value": "-"},
                            ],
                        },
                    ]
                },
                "AditionalInfo": [{"Name": "VALIDAR_REFERENCIA_INTERNA", "Data": None, "Value": "NO_VALIDAR"}],
            }]
        },
    }


def fesp_payload():
    issue_dt, _, _ = gt_now()
    # FESP: no debe incluir frases 1,2,3,6,7,8 → no AdditionlInfo en Seller
    seller = _seller()
    seller.pop("AdditionlInfo", None)
    return {
        "Version": "1.00", "CountryCode": "GT",
        "Header": {"DocType": "FESP", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": seller,
        "Buyer": {
            "TaxID": BUYER_FESP,
            "Name": BUYER_FESP_NAME,
            "AddressInfo": {
                "Address": "CIUDAD", "City": "01010",
                "District": "GUATEMALA", "State": "GUATEMALA", "Country": "GT",
            },
        },
        "Items": [{
            "Number": "1", "Codes": None, "Type": "Bien",
            "Description": "ALQUILER DE EQUIPO DE LIGASURE",
            "Qty": "1.000000", "UnitOfMeasure": "UNI", "Price": "2500.000000", "Discounts": None,
            "Taxes": {"Tax": [{"Code": "1", "Description": "IVA", "TaxableAmount": "2232.142857", "Amount": "267.857143"}]},
            "Totals": {"TotalItem": "2500.000000"},
        }],
        "Totals": {
            "TotalTaxes": {"TotalTax": [{"Description": "IVA", "Amount": "267.857143"}]},
            "GrandTotal": {"InvoiceTotal": "2500.000000"},
        },
        "AdditionalDocumentInfo": {
            "AdditionalInfo": [{
                "Code": "FESP",
                "Type": "COMPLEMENTO",
                "AditionalInfo": [
                    {"Name": "RetencionISR", "Data": None, "Value": "111.607143"},
                    {"Name": "RetencionIVA", "Data": None, "Value": "267.857143"},
                    {"Name": "TotalMenosRetenciones", "Data": None, "Value": "2120.535714"},
                ],
            }]
        },
    }


def rdon_payload():
    issue_dt, _, _ = gt_now()
    return {
        "Version": "1.00", "CountryCode": "GT",
        "Header": {
            "DocType": "RDON", "IssuedDateTime": issue_dt, "Currency": "GTQ",
            # TipoPersoneria debe coincidir con el RTU del emisor (valor de ejemplo: 719)
            "AdditionalIssueDocInfo": [{"Name": "TipoPersoneria", "Data": None, "Value": "719"}],
        },
        "Seller": _seller(tipo_frase="4", escenario="4"),
        "Buyer": _buyer_nit(),
        "Items": [{
            "Number": "1", "Codes": None, "Type": "Bien", "Description": "Prueba 5",
            "Qty": "1.000000", "UnitOfMeasure": "UNI", "Price": "50.000000", "Discounts": None,
            "Taxes": None,
            "Totals": {"TotalItem": "50.000000"},
        }],
        "Totals": {"GrandTotal": {"InvoiceTotal": "50.000000"}},
        "AdditionalDocumentInfo": {
            "AdditionalInfo": [{
                "Code": "FRONT-67C1-4545-BA1E-AA3C115E18D6",
                "Type": "ADENDA",
                "AditionalData": {
                    "Data": [
                        {
                            "Name": "INFORMACION_ADICIONAL",
                            "Info": [
                                {"Name": "OBSERVACIONES", "Data": None, "Value": "-"},
                                {"Name": "CANTIDAD_LETRAS", "Data": None, "Value": "CINCUENTA QUETZALES CON 00/100"},
                            ],
                        },
                        {
                            "Name": "DetallesAux_Detalle",
                            "Info": [
                                {"Name": "NumeroLinea", "Data": None, "Value": "1"},
                                {"Name": "Descripcion_Adicional", "Data": None, "Value": "-"},
                                {"Name": "CodigoEAN", "Data": None, "Value": "00010"},
                                {"Name": "CategoriaAdicional", "Data": None, "Value": "-"},
                            ],
                        },
                    ]
                },
                "AditionalInfo": [{"Name": "VALIDAR_REFERENCIA_INTERNA", "Data": None, "Value": "NO_VALIDAR"}],
            }]
        },
    }


def cca_payload():
    issue_dt, _, date_only = gt_now()
    return {
        "Version": "1.00", "CountryCode": "GT",
        "Header": {"DocType": "FACT", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": _seller(),
        "Buyer": {**_buyer_nit(), "Contact": {"EmailList": {"Email": ["comprador@example.com"]}}},
        "Items": [{
            "Number": "1", "Codes": None, "Type": "Bien",
            "Description": "LECHE DOS PINOS ENTERA LIQUIDA LITRO (VERDE)",
            "Qty": "2.000000", "UnitOfMeasure": "UNI", "Price": "50.000000",
            "Discounts": {"Discount": [{"Amount": "10.00"}]},
            "Taxes": {"Tax": [{"Code": "1", "Description": "IVA", "TaxableAmount": "80.357143", "Amount": "9.642857"}]},
            "Totals": {"TotalItem": "90.000000"},
        }],
        "Totals": {
            "TotalTaxes": {"TotalTax": [{"Description": "IVA", "Amount": "9.642857"}]},
            "GrandTotal": {"InvoiceTotal": "90.000000"},
        },
        "AdditionalDocumentInfo": {
            "AdditionalInfo": [{
                "Code": "CCA",
                "Type": "COMPLEMENTO",
                "AditionalData": {
                    "Data": [
                        {
                            "Info": [
                                {"Name": "NITtercero", "Data": None, "Value": "22222222"},
                                {"Name": "NumeroDocumento", "Data": None, "Value": "1"},
                                {"Name": "FechaDocumento", "Data": None, "Value": date_only},
                                {"Name": "Descripcion", "Data": None, "Value": "Cobro por cuenta ajena 1"},
                                {"Name": "BaseImponible", "Data": None, "Value": "100.00"},
                                {"Name": "MontoCobroDAI", "Data": None, "Value": "0"},
                                {"Name": "MontoCobroIVA", "Data": None, "Value": "12.00"},
                                {"Name": "MontoCobroOtros", "Data": None, "Value": "0"},
                                {"Name": "MontoCobroTotal", "Data": None, "Value": "12.00"},
                            ]
                        },
                        {
                            "Info": [
                                {"Name": "NITtercero", "Data": None, "Value": "22222222"},
                                {"Name": "NumeroDocumento", "Data": None, "Value": "2"},
                                {"Name": "FechaDocumento", "Data": None, "Value": date_only},
                                {"Name": "Descripcion", "Data": None, "Value": "Cobro por cuenta ajena 2"},
                                {"Name": "BaseImponible", "Data": None, "Value": "120.00"},
                                {"Name": "MontoCobroDAI", "Data": None, "Value": "0"},
                                {"Name": "MontoCobroIVA", "Data": None, "Value": "14.40"},
                                {"Name": "MontoCobroOtros", "Data": None, "Value": "0"},
                                {"Name": "MontoCobroTotal", "Data": None, "Value": "14.40"},
                            ]
                        },
                    ]
                },
            }]
        },
    }


def fpeq_payload():
    """FPEQ - Factura Pequeño Contribuyente (AfiliacionIVA: PEQ, TipoFrase: 3, sin impuestos).
    NOTA: Si el emisor no está registrado como PEQ el API retornará error de régimen.
    El payload es estructuralmente correcto según documentación SAT.
    """
    issue_dt, _, _ = gt_now()
    return {
        "Version": "1.00", "CountryCode": "GT",
        "Header": {"DocType": "FPEQ", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": _seller(tipo_frase="3", escenario="1", afiliacion="PEQ"),
        "Buyer": _buyer_cf(),
        "Items": [{
            "Number": "1", "Codes": None, "Type": "Bien", "Description": "TEST",
            "Qty": "5.000000", "UnitOfMeasure": "EA", "Price": "25.000000", "Discounts": None,
            "Taxes": None,
            "Totals": {"TotalItem": "125.000000"},
        }],
        "Totals": {"GrandTotal": {"InvoiceTotal": "125.000000"}},
        "AdditionalDocumentInfo": _adenda_std("CIENTO VEINTICINCO QUETZALES CON 00/100"),
    }


def reci_payload():
    issue_dt, _, _ = gt_now()
    return {
        "Version": "1.00", "CountryCode": "GT",
        "Header": {"DocType": "RECI", "IssuedDateTime": issue_dt, "Currency": "GTQ"},
        "Seller": _seller(tipo_frase="4", escenario="5"),
        "Buyer": _buyer_nit(),
        "Items": [{
            "Number": "1", "Codes": None, "Type": "Bien", "Description": "Jalapeño",
            "Qty": "1.000000", "UnitOfMeasure": "UNI", "Price": "250.000000", "Discounts": None,
            "Taxes": None,
            "Totals": {"TotalItem": "250.000000"},
        }],
        "Totals": {"GrandTotal": {"InvoiceTotal": "250.000000"}},
        "AdditionalDocumentInfo": {
            "AdditionalInfo": [{
                "Code": "FRONT-67C1-4545-BA1E-AA3C115E18D6",
                "Type": "ADENDA",
                "AditionalData": {
                    "Data": [
                        {
                            "Name": "INFORMACION_ADICIONAL",
                            "Info": [
                                {"Name": "OBSERVACIONES", "Data": None, "Value": "-"},
                                {"Name": "CANTIDAD_LETRAS", "Data": None, "Value": "DOSCIENTOS CINCUENTA QUETZALES CON 00/100"},
                            ],
                        },
                        {
                            "Name": "DetallesAux_Detalle",
                            "Info": [
                                {"Name": "NumeroLinea", "Data": None, "Value": "1"},
                                {"Name": "Descripcion_Adicional", "Data": None, "Value": "-"},
                                {"Name": "CodigoEAN", "Data": None, "Value": "00025"},
                                {"Name": "CategoriaAdicional", "Data": None, "Value": "-"},
                            ],
                        },
                    ]
                },
                "AditionalInfo": [
                    {"Name": "VALIDAR_REFERENCIA_INTERNA", "Data": None, "Value": "NO_VALIDAR"},
                    {"Name": "Tipo", "Data": None, "Value": "Universidad"},
                    {"Name": "NombreAlumno", "Data": None, "Value": "ALUMNO EJEMPLO"},
                    {"Name": "Carne", "Data": None, "Value": "000000000"},
                    {"Name": "UnidadAcademica", "Data": None, "Value": "08"},
                ],
            }]
        },
    }


# ──────────────────────────────────────────────
# Query / ancillary endpoints
# ──────────────────────────────────────────────

def shared_getdteinfo(authnumber):
    r = requests.get(
        f"{BASE_URL}/Shared",
        params={
            "COUNTRY": "GT", "TAXID": PADDED, "DATA1": "SHARED_GETDTEINFO",
            "DATA2": f"AUTHNUMBER|{authnumber}", "USERNAME": USERNAME,
        },
        headers={"Authorization": login()},
        timeout=60,
    )
    r.raise_for_status()
    return r.json()


def shared_getinfonitcom(nit):
    r = requests.get(
        f"{BASE_URL}/Shared",
        params={
            "COUNTRY": "GT", "TAXID": PADDED, "DATA1": "SHARED_GETINFONITcom",
            "DATA2": f"NIT|{nit}", "USERNAME": USERNAME,
        },
        headers={"Authorization": login()},
        timeout=60,
    )
    r.raise_for_status()
    return r.json()


def get_document_json(authnumber):
    r = requests.get(
        f"{BASE_URL}/GetDocument",
        params={"AUTHNUMBER": authnumber, "TAXID": PADDED, "FORMAT": "JSON", "USERNAME": USERNAME},
        headers={"Authorization": login()},
        timeout=60,
    )
    r.raise_for_status()
    return r.json()


def cancel(authnumber, receiver_id, issue_datetime):
    r = requests.post(
        f"{BASE_URL}/CancelFelGT",
        headers=headers(),
        json={
            "Taxid": TAXID,
            "Autorizacion": authnumber,
            "IdReceptor": receiver_id,
            "FechaEmisionDocumentoAnular": issue_datetime,
            "MotivoAnulacion": "Anulación de prueba automática",
            "Username": USERNAME,
        },
        timeout=120,
    )
    r.raise_for_status()
    return r.json()


def certify_ncredtotal(authnumber, issue_datetime_space, reason="Nota de crédito total de prueba"):
    r = requests.post(
        f"{BASE_URL}/cert/ncredtotal",
        headers=headers(),
        json={
            "Staxid": TAXID,
            "Authnumber": authnumber,
            "FechaEmision": issue_datetime_space,
            "MotivoAjuste": reason,
            "ReferenciaInterna": "",
            "Formatos": "xml|html|pdf",
            "Username": USERNAME,
        },
        timeout=120,
    )
    r.raise_for_status()
    return r.json()


# ──────────────────────────────────────────────
# Main runner
# ──────────────────────────────────────────────

def run(name, fn, *args, **kwargs):
    """Call fn(*args, **kwargs) and record result, printing status."""
    try:
        result = fn(*args, **kwargs)
        code = result.get("code") if isinstance(result, dict) else None
        auth = (result.get("authNumber") or result.get("Autorizacion")
                or result.get("Codigo")) if isinstance(result, dict) else None
        # code=1 = success; code=0 = hard fail; code=3000 = API validation error
        if code is None or auth:
            status = "OK"
        elif int(code) == 1:
            status = "OK"
        elif int(code) == 0:
            status = f"FAIL(code={code})"
        else:
            status = f"WARN(code={code})"
        print(f"  [{status:30s}] {name}")
        return result
    except Exception as exc:
        print(f"  [{'ERROR':30s}] {name}: {exc}")
        return {"_error": str(exc)}


def main():
    out = {}
    _, dt_space_now, _ = gt_now()

    print(f"Base URL : {BASE_URL}")
    print(f"Username : {FULL_USERNAME}")
    print()

    # ── Login ──
    try:
        tok = login()
        out["login"] = {"ok": bool(tok), "full_username": FULL_USERNAME}
        print(f"  [{'OK':30s}] login → {FULL_USERNAME}")
    except Exception as exc:
        out["login"] = {"ok": False, "error": str(exc)}
        print(f"  [{'ERROR':30s}] login: {exc}")
        print(json.dumps(out, indent=2, ensure_ascii=False))
        sys.exit(1)

    print()
    print("── Certificación DTE ──")

    # FACT CF — emit first to use as origin for NDEB/NCRE if needed
    out["fact_cf"] = run("FACT CF", certify, fact_cf_payload())
    fact_cf_auth = out["fact_cf"].get("authNumber") or out["fact_cf"].get("Autorizacion")
    # issuedTimeStamp viene como "YYYY-MM-DDTHH:MM:SS", ncredtotal necesita "YYYY-MM-DD HH:MM:SS"
    _cf_ts_raw = out["fact_cf"].get("issuedTimeStamp") or dt_space_now
    fact_cf_ts_space = _cf_ts_raw.replace("T", " ")

    out["fact_cui"] = run("FACT CUI", certify, fact_cui_payload())
    out["fcam"] = run("FCAM", certify, fcam_payload())

    # Para NDEB/NCRE usar FCAM como origen: Buyer=12345678 coincide con receptor de NDEB/NCRE
    fcam_auth = out["fcam"].get("authNumber") or out["fcam"].get("Autorizacion")
    fcam_series = out["fcam"].get("batch") or out["fcam"].get("Serie") or ""
    fcam_number = str(out["fcam"].get("serial") or out["fcam"].get("Numero") or "")
    _, _, date_now = gt_now()

    if fcam_auth and fcam_series and fcam_number:
        out["ndeb"] = run("NDEB (origin=fcam)", certify,
                          ndeb_payload(fcam_auth, date_now, fcam_series, fcam_number))
        out["ncre"] = run("NCRE (origin=fcam)", certify,
                          ncre_payload(fcam_auth, date_now, fcam_series, fcam_number))
    else:
        out["ndeb"] = run("NDEB (origin=hardcoded)", certify, ndeb_payload())
        out["ncre"] = run("NCRE (origin=hardcoded)", certify, ncre_payload())

    out["nabn"] = run("NABN", certify, nabn_payload())
    out["fesp"] = run("FESP", certify, fesp_payload())
    out["rdon"] = run("RDON", certify, rdon_payload())
    out["cca"] = run("FACT CCA", certify, cca_payload())
    out["fpeq"] = run("FPEQ (puede fallar si emisor no es PEQ)", certify, fpeq_payload())
    out["reci"] = run("RECI", certify, reci_payload())

    # Pick last successful auth for queries
    last_auth = None
    last_ts = dt_space_now
    for key in ["reci", "cca", "rdon", "fesp", "nabn", "ncre", "ndeb", "fcam", "fact_cui", "fact_cf"]:
        r = out.get(key, {})
        a = r.get("authNumber") or r.get("Autorizacion")
        if a:
            last_auth = a
            last_ts = r.get("issuedTimeStamp") or dt_space_now
            break

    print()
    print("── Consultas ──")

    if last_auth:
        out["shared_getdteinfo"] = run("SHARED_GETDTEINFO", shared_getdteinfo, last_auth)
        out["get_document_json"] = run("GET /GetDocument (JSON)", get_document_json, last_auth)
    else:
        print("  [SKIP] consultas — no hay authNumber disponible")

    out["shared_getinfonitcom"] = run("SHARED_GETINFONITcom", shared_getinfonitcom, TAXID)

    print()
    print("── Anulación / Nota de crédito total ──")

    if fact_cf_auth and fact_cf_ts_space:
        out["ncredtotal"] = run("POST /cert/ncredtotal", certify_ncredtotal, fact_cf_auth, fact_cf_ts_space)
        out["cancel"] = run("POST /CancelFelGT", cancel, fact_cf_auth, "CF", fact_cf_ts_space)
    else:
        print("  [SKIP] ncredtotal y cancel — no hay authNumber de FACT CF")

    print()
    print(json.dumps(out, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
