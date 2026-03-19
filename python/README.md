# Digifact FEL Guatemala — Python SDK

Python SDK for the [Digifact](https://www.digifact.com.gt/) FEL (Factura Electrónica en Línea) Guatemala e-invoicing API.

## Installation

```bash
pip install digifact-fel
```

Or from source:

```bash
pip install -e sdk/python/
```

## Quick start

```python
from digifact_fel import DigifactClient

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
from digifact_fel import (
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
