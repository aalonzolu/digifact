# Digifact FEL Guatemala — Python SDK

Python SDK for the [Digifact](https://www.digifact.com.gt/) FEL (Factura Electrónica en Línea) Guatemala e-invoicing API.

## Installation

```bash
pip install digifact-sdk
```

Or from source:

```bash
pip install -e sdk/python/
```

## Quick start

```python
from digifact_sdk import DigifactClient

client = DigifactClient(
    taxid="12345678",
    username="FELUSER",
    password="secret",
    environment="test",  # or "production"
)

# FACT CF — consumer final, IVA calculated automatically
result = client.invoice(
    buyer="CF",
    items=[{"description": "Consultoría", "qty": 1, "price": 100.00}]
)
print(result.auth_number)

# FACT to NIT — buyer name fetched from SAT automatically
result = client.invoice(
    buyer="12345678",
    items=[
        {"description": "Laptop", "qty": 1, "price": 5000.00, "type": "Bien"},
        {"description": "Soporte anual", "qty": 1, "price": 500.00},
    ]
)

# FACT to CUI buyer
result = client.invoice(
    buyer={"taxid": "3730617490101", "type": "CUI", "name": "Juan Pérez"},
    items=[{"description": "Producto", "qty": 2, "price": 50.00}]
)

# Full NIT buyer with explicit details (no auto-lookup)
result = client.invoice(
    buyer={
        "taxid":    "12345678",
        "name":     "EMPRESA EJEMPLO S.A.",
        "address":  "6 AV 6-48 ZONA 9",
        "city":     "01009",
        "district": "GUATEMALA",
        "state":    "GUATEMALA",
        "country":  "GT",
        "email":    "facturacion@empresa.com",  # optional
    },
    items=[{"description": "Producto", "qty": 1, "price": 100.00}]
)

# FCAM (Factura Cambiaria)
result = client.invoice(
    buyer="12345678",
    items=[{"description": "Servicio", "qty": 1, "price": 500.00}],
    doc_type="FCAM",
    payment_terms=[{"date": "2026-04-18", "amount": 500.00}]
)

# Credit note (NCRE)
result = client.credit_note(
    buyer="12345678",
    items=[{"description": "Devolución", "qty": 1, "price": 100.00}],
    origin={
        "auth_number": "XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX",
        "date": "2026-03-18",
        "series": "XXXXXXXX",
        "number": "123456",
    },
    reason="Producto defectuoso"
)

# Debit note (NDEB)
result = client.debit_note(
    buyer="12345678",
    items=[{"description": "Cargo adicional", "qty": 1, "price": 50.00}],
    origin={...},
    reason="Cargo por entrega express"
)

# Cancel a DTE
result = client.cancel(
    auth_number="XXXXXXXX-...",
    receiver_id="CF",
    issue_datetime="2026-03-18 21:40:14",
    reason="Error en monto"
)

# Total credit note
result = client.credit_note_total(
    auth_number="XXXXXXXX-...",
    issue_datetime="2026-03-18 21:40:14",
    reason="Nota de crédito total"
)

# NIT lookup
info = client.lookup_nit("12345678")
print(info["name"])

# Retrieve DTE
doc = client.get_dte("XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX")
```

## Document types

| Type | Description | IVA |
|------|-------------|-----|
| `FACT` | Standard invoice | Yes |
| `FCAM` | Factura Cambiaria (installments) | Yes |
| `NDEB` | Debit note | Yes |
| `NCRE` | Credit note | Yes |
| `NABN` | Nota de Abono | No |
| `FESP` | Factura Especial | Yes |
| `RDON` | Recibo por Donación | No |
| `FPEQ` | Factura Pequeño Contribuyente | No |
| `RECI` | Recibo universitario | No |
| `CCA` | Cobro por Cuenta Ajena | Yes |

## IVA calculation

Prices are IVA-inclusive (what the customer pays):

```
line_total     = qty × price
taxable_amount = line_total / 1.12
iva_amount     = line_total − taxable_amount
```

All money values are formatted as strings with 6 decimal places.

## Item dict keys

```python
{
    "description": str,          # required
    "price": float | Decimal,    # required — unit price, IVA-inclusive
    "qty": float | Decimal,      # optional, default 1
    "type": str,                  # optional: "Servicio" (default) | "Bien"
    "unit_of_measure": str,       # optional, default "UNI"
    "discount": float | None,     # optional — line discount amount
}
```

## Fuel invoices (FACT Combustible)

Fuel invoices emit IVA **and** a PETROLEO pass-through tax per SAT spec.
Items without `petroleo_amount` are treated as regular IVA-only items and
can coexist in the same invoice.

### Option A — rates set once at client init (recommended for gas stations)

```python
# Set PETROLEO rates once per fuel type (Q/gallon, from MEM or supplier invoice)
client = DigifactClient(
    taxid="12345678",
    username="FELUSER",
    password="secret",
    petroleo_rates={"1": 4.70, "2": 4.60, "4": 1.30},  # SUPER / REGULAR / DIESEL
)

# Only petroleo_code is needed — petroleo_amount is filled in automatically
result = client.fuel_invoice(
    buyer="CF",
    items=[
        {"description": "GASOLINA SUPER",    "qty": 30, "price": 35.00, "petroleo_code": "1", "type": "Bien"},
        {"description": "GASOLINA REGULAR",  "qty": 20, "price": 34.00, "petroleo_code": "2", "type": "Bien"},
        {"description": "GASOLINA DIESEL",   "qty": 50, "price": 32.00, "petroleo_code": "4", "type": "Bien"},
        # Regular items (no petroleo_code): IVA only, can coexist
        {"description": "FILTRO DE ACEITE",    "qty": 1, "price": 45.00,  "type": "Bien"},
        {"description": "SET DE CANDELAS NGK", "qty": 1, "price": 400.00, "type": "Bien"},
    ],
)
print(result.auth_number)
```

### Option B — explicit per-item amount

```python
result = client.fuel_invoice(
    buyer="CF",
    items=[
        {"description": "GASOLINA SUPER",   "qty": 1, "price": 35.00, "petroleo_amount": 4.70, "petroleo_code": "1", "type": "Bien"},
        {"description": "GASOLINA DIESEL",  "qty": 1, "price": 32.00, "petroleo_amount": 1.30, "petroleo_code": "4", "type": "Bien"},
    ],
)
```

### Fuel item dict keys

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `description` | `str` | required | Line description |
| `price` | `float\|Decimal` | required | Full consumer price per unit (PETROLEO + IVA-inclusive) |
| `qty` | `float\|Decimal` | `1` | Quantity |
| `type` | `str` | `"Servicio"` | `"Bien"` or `"Servicio"` |
| `unit_of_measure` | `str` | `"UNI"` | SAT unit code |
| `petroleo_amount` | `float\|Decimal` | — | Per-unit PETROLEO tax; omit for IVA-only items |
| `petroleo_code` | `str` | `"1"` | `"1"`=SUPER, `"2"`=REGULAR, `"4"`=DIESEL. Required when `petroleo_amount` is omitted and `petroleo_rates` is set; raises `DigifactValidationError` if the code is not found in the rates dict. |

## Running tests

```bash
# Unit tests (no credentials needed)
python -m pytest tests/ -v

# Integration tests
export DIGIFACT_TAXID=12345678
export DIGIFACT_USERNAME=FELUSER
export DIGIFACT_PASSWORD=your_password
python -m pytest tests/ -v
```

## Environment variables

| Variable | Description |
|----------|-------------|
| `DIGIFACT_TAXID` | Fiscal ID (e.g. `12345678`) |
| `DIGIFACT_USERNAME` | Username (e.g. `FELUSER`) |
| `DIGIFACT_PASSWORD` | Account password |

## Error handling

```python
from digifact_sdk import (
    DigifactError,          # base
    DigifactAuthError,      # auth failure
    DigifactApiError,       # HTTP / API error
    DigifactValidationError, # SAT rejection
    DigifactNitNotFoundError, # NIT not found
)

try:
    result = client.invoice("CF", [...])
except DigifactValidationError as exc:
    print(f"SAT rejected: {exc}")
    print(f"Code: {exc.code}")
    print(f"Raw: {exc.raw}")
except DigifactError as exc:
    print(f"SDK error: {exc}")
```
