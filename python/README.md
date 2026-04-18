# Digifact FEL Guatemala — SDK para Python

SDK en Python para la API de facturación electrónica en línea (FEL) de Guatemala de [Digifact](https://www.digifact.com.gt/).

## Instalación

```bash
pip install digifact-sdk
```

O desde el código fuente:

```bash
pip install -e sdk/python/
```

## Configuración del cliente (`DigifactClient(...)`)

| Parámetro | Tipo | Por defecto | Descripción |
|-----------|------|-------------|-------------|
| `taxid` | `str` | **requerido** | NIT del emisor. Acepta dígitos o con separadores (`"12345678"`, `"1234567-8"`); los no-dígitos se eliminan. Se rellena internamente a 12 caracteres. |
| `username` | `str` | **requerido** | Usuario corto de Digifact (la parte después de `GT.<NIT>.`, p. ej. `"FELUSER"`). |
| `password` | `str` | `""` | Contraseña de la cuenta. **Requerido** si no se provee `token`. |
| `environment` | `str` | `"test"` | `"test"` o `"production"`. |
| `token` | `str` | `""` | Bearer token preobtenido. Si se provee, se omite el login. |
| `seller_name` | `str` | `""` | Nombre comercial del emisor. Si está vacío, se consulta en SAT vía `lookup_nit()`. |
| `seller_address` | `str` | `""` | Dirección del emisor. Si está vacía, se consulta en SAT. |
| `afiliacion_iva` | `str` | `"GEN"` | Afiliación IVA del RTU: `"GEN"`, `"PEQ"` o `"EXE"`. |
| `tipo_personeria` | `str` | `"1"` | Código de `TipoPersoneria` del RTU (usado en RDON). |
| `branch_code` | `str` | `"1"` | **Código del establecimiento** del RTU. Un NIT puede tener varios establecimientos (1, 2, 3…). Se escribe en `Seller.BranchInfo.Code`. |
| `branch_name` | `str` | `"ESTABLECIMIENTO PRINCIPAL"` | Nombre comercial del establecimiento. Se escribe en `Seller.BranchInfo.Name`. |
| `tipo_frase` | `str \| None` | `None` | Sobreescritura global de `TipoFrase`. Ver [frases](#configuración-de-frases-tipofrase--codigoescenario). |
| `escenario` | `str \| None` | `None` | Sobreescritura global de `CodigoEscenario`. |
| `timeout` | `int` | `120` | Timeout HTTP en segundos. |
| `session` | `requests.Session \| None` | `None` | Sesión HTTP personalizada (útil para tests). |
| `petroleo_rates` | `dict[str, float] \| None` | `None` | Mapa código PETROLEO → tarifa por unidad. Usado por `fuel_invoice()`. |

## Inicio rápido

```python
from digifact_sdk import DigifactClient

client = DigifactClient(
    taxid="12345678",
    username="FELUSER",
    password="secret",
    environment="test",  # o "production"
)

# FACT CF — consumidor final, el IVA se calcula automáticamente
result = client.invoice(
    buyer="CF",
    items=[{"description": "Consultoría", "qty": 1, "price": 100.00}]
)
print(result.auth_number)

# FACT a NIT — el nombre del receptor se consulta automáticamente en SAT
result = client.invoice(
    buyer="12345678",
    items=[
        {"description": "Laptop", "qty": 1, "price": 5000.00, "type": "Bien"},
        {"description": "Soporte anual", "qty": 1, "price": 500.00},
    ]
)

# FACT a receptor con CUI
result = client.invoice(
    buyer={"taxid": "3730617490101", "type": "CUI", "name": "Juan Pérez"},
    items=[{"description": "Producto", "qty": 2, "price": 50.00}]
)

# Receptor NIT con datos explícitos (sin consulta automática)
result = client.invoice(
    buyer={
        "taxid":    "12345678",
        "name":     "EMPRESA EJEMPLO S.A.",
        "address":  "6 AV 6-48 ZONA 9",
        "city":     "01009",
        "district": "GUATEMALA",
        "state":    "GUATEMALA",
        "country":  "GT",
        "email":    "facturacion@empresa.com",  # opcional
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

# Nota de crédito (NCRE)
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

# Nota de débito (NDEB)
result = client.debit_note(
    buyer="12345678",
    items=[{"description": "Cargo adicional", "qty": 1, "price": 50.00}],
    origin={...},
    reason="Cargo por entrega express"
)

# Anular un DTE
result = client.cancel(
    auth_number="XXXXXXXX-...",
    receiver_id="CF",
    issue_datetime="2026-03-18 21:40:14",
    reason="Error en monto"
)

# Nota de crédito total
result = client.credit_note_total(
    auth_number="XXXXXXXX-...",
    issue_datetime="2026-03-18 21:40:14",
    reason="Nota de crédito total"
)

# Consulta de NIT
info = client.lookup_nit("12345678")
print(info["name"])

# Obtener DTE
doc = client.get_dte("XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX")
```

## Tipos de documento

| Tipo | Descripción | IVA |
|------|-------------|-----|
| `FACT` | Factura estándar | Sí |
| `FCAM` | Factura Cambiaria (con abonos) | Sí |
| `NDEB` | Nota de débito | Sí |
| `NCRE` | Nota de crédito | Sí |
| `NABN` | Nota de abono | No |
| `FESP` | Factura especial | Sí |
| `RDON` | Recibo por donación | No |
| `FPEQ` | Factura pequeño contribuyente | No |
| `RECI` | Recibo universitario | No |
| `CCA` | Cobro por cuenta ajena | Sí |

## Cálculo del IVA

Los precios incluyen IVA (es lo que paga el cliente):

```
total_linea    = qty × price
base_imponible = total_linea / 1.12
monto_iva      = total_linea − base_imponible
```

Todos los montos se formatean como cadenas con 6 decimales.

## Campos del ítem

```python
{
    "description": str,          # requerido
    "price": float | Decimal,    # requerido — precio unitario, incluye IVA
    "qty": float | Decimal,      # opcional, por defecto 1
    "type": str,                  # opcional: "Servicio" (por defecto) | "Bien"
    "unit_of_measure": str,       # opcional, por defecto "UNI"
    "discount": float | None,     # opcional — descuento de la línea
}
```

## Facturas de combustible (FACT Combustible)

Las facturas de combustible emiten IVA **y** un impuesto PETROLEO según la
especificación de SAT. Los ítems sin `petroleo_amount` se tratan como ítems
regulares (sólo IVA) y pueden coexistir en la misma factura.

### Opción A — tarifas fijadas al inicializar el cliente (recomendada para gasolineras)

```python
# Fijar tarifas PETROLEO una sola vez por tipo de combustible (Q/galón, según MEM o factura del proveedor)
client = DigifactClient(
    taxid="12345678",
    username="FELUSER",
    password="secret",
    petroleo_rates={"1": 4.70, "2": 4.60, "4": 1.30},  # SUPER / REGULAR / DIESEL
)

# Sólo hace falta petroleo_code — petroleo_amount se completa automáticamente
result = client.fuel_invoice(
    buyer="CF",
    items=[
        {"description": "GASOLINA SUPER",    "qty": 30, "price": 35.00, "petroleo_code": "1", "type": "Bien"},
        {"description": "GASOLINA REGULAR",  "qty": 20, "price": 34.00, "petroleo_code": "2", "type": "Bien"},
        {"description": "GASOLINA DIESEL",   "qty": 50, "price": 32.00, "petroleo_code": "4", "type": "Bien"},
        # Ítems regulares (sin petroleo_code): sólo IVA, pueden coexistir
        {"description": "FILTRO DE ACEITE",    "qty": 1, "price": 45.00,  "type": "Bien"},
        {"description": "SET DE CANDELAS NGK", "qty": 1, "price": 400.00, "type": "Bien"},
    ],
)
print(result.auth_number)
```

### Opción B — monto explícito por ítem

```python
result = client.fuel_invoice(
    buyer="CF",
    items=[
        {"description": "GASOLINA SUPER",   "qty": 1, "price": 35.00, "petroleo_amount": 4.70, "petroleo_code": "1", "type": "Bien"},
        {"description": "GASOLINA DIESEL",  "qty": 1, "price": 32.00, "petroleo_amount": 1.30, "petroleo_code": "4", "type": "Bien"},
    ],
)
```

### Campos del ítem de combustible

| Campo | Tipo | Por defecto | Descripción |
|-----|------|---------|-------------|
| `description` | `str` | requerido | Descripción de la línea |
| `price` | `float\|Decimal` | requerido | Precio unitario completo al consumidor (incluye PETROLEO + IVA). Es lo que paga el cliente en la bomba. Si la factura del proveedor muestra un precio unitario *sin* PETROLEO/IDP (p. ej. `37.99`), suma la tarifa IDP por unidad: `price = 37.99 + 4.70 = 42.69`. |
| `qty` | `float\|Decimal` | `1` | Cantidad |
| `type` | `str` | `"Servicio"` | `"Bien"` o `"Servicio"` |
| `unit_of_measure` | `str` | `"UNI"` | Código de unidad de SAT |
| `petroleo_amount` | `float\|Decimal` | — | Impuesto PETROLEO por unidad; omitir para ítems sólo-IVA |
| `petroleo_code` | `str` | `"1"` | `"1"`=SUPER, `"2"`=REGULAR, `"4"`=DIESEL. Obligatorio cuando se omite `petroleo_amount` y `petroleo_rates` está configurado; lanza `DigifactValidationError` si el código no está en el diccionario de tarifas. |

## Configuración de frases (TipoFrase / CodigoEscenario)

Todo DTE (excepto FESP) debe llevar un par `TipoFrase` + `CodigoEscenario`. El
SDK elige valores por defecto adecuados, por lo que **no** hace falta
configurar nada en el caso común.

**Orden de precedencia:** argumentos por llamada → globales del constructor (`tipo_frase` / `escenario`) → tabla de valores por defecto.

**Tabla de valores por defecto:**

| DTE         | Afiliación | TipoFrase | CodigoEscenario | Notas |
|-------------|-----------:|:---------:|:---------------:|-------|
| FESP        | —          | —         | —               | Sin bloque `AdditionlInfo` |
| FPEQ        | PEQ        | `2`       | `1`             | Pequeño contribuyente |
| RDON        | cualquiera | `4`       | `4`             | Donaciones |
| RECI        | cualquiera | `4`       | `5`             | Recibos (universidades) |
| NABN        | cualquiera | `1`       | `1`             | Abonos |
| FACT / FCAM / NCRE / NDEB | **GEN** | `1` | `1` | Por defecto: ISR **régimen sobre utilidades trimestrales** |
| FACT / FCAM / NCRE / NDEB | PEQ | `2` | `1` | |
| FACT / FCAM / NCRE / NDEB | EXE | `4` | `1` | Exento |

Tanto `tipo_frase` como `escenario` se pueden sobreescribir de forma
independiente — por llamada (como argumentos con nombre) o globalmente al
construir el cliente. Cuando se omiten, cada uno cae al global del
constructor y luego a la tabla de valores por defecto.

```python
# Sobreescritura por llamada (uno o ambos)
client.invoice("CF", items, escenario="1")
client.invoice("CF", items, tipo_frase="2", escenario="1")

# Funciona igual en los demás métodos de DTE
client.credit_note("12345678", items, origin, "...", tipo_frase="2", escenario="1")
client.fuel_invoice("CF", items, tipo_frase="2", escenario="1")

# O globalmente al construir el cliente (p. ej. GEN + ISR régimen opcional simplificado)
client = DigifactClient(
    taxid="12345678", username="FELUSER", password="...",
    afiliacion_iva="GEN",
    tipo_frase="1",   # opcional — la tabla ya devuelve "1" para GEN
    escenario="2",    # ISR régimen opcional simplificado (sobreescribe el "1" por defecto)
)
```

Para descubrir el par correcto en un caso particular, revisa las afiliaciones
del RTU en el portal de SAT.

## Referencia de métodos

Todos los métodos de emisión devuelven `DteResult` con `result.auth_number`, `series`, `number`, `issue_datetime`, `raw`.

| Método | Firma | Descripción |
|--------|-------|-------------|
| `invoice()` | `invoice(buyer, items, *, doc_type="FACT", payment_terms=None, amount_str="", observaciones="-", tipo_personeria=None, tipo_frase=None, escenario=None)` | Emite FACT, FCAM, FESP, FPEQ, NABN, RDON o RECI según `doc_type`. |
| `cca_invoice()` | `cca_invoice(buyer, items, cobros, *, tipo_frase=None, escenario=None)` | FACT con complemento CCA. |
| `fuel_invoice()` | `fuel_invoice(buyer, items, *, tipo_frase=None, escenario=None)` | FACT con complemento combustible (IVA + PETROLEO). |
| `credit_note()` | `credit_note(buyer, items, origin, reason, *, tipo_frase=None, escenario=None)` | Nota de crédito (NCRE). |
| `debit_note()` | `debit_note(buyer, items, origin, reason, *, tipo_frase=None, escenario=None)` | Nota de débito (NDEB). |
| `credit_note_total()` | `credit_note_total(auth_number, issue_datetime, reason="...", reference="")` | Nota de crédito total. Devuelve `dict`. |
| `cancel()` | `cancel(auth_number, receiver_id, issue_datetime, reason="Anulación")` | Anula un DTE. Devuelve `dict`. |
| `lookup_nit()` | `lookup_nit(nit)` | Consulta SAT. Devuelve `{"nit","name","address","city","district","state"}`. |
| `get_dte()` | `get_dte(auth_number, fmt="JSON")` | Recupera el DTE (`"JSON"`, `"XML"`, `"HTML"`, `"PDF"`). |
| `get_dte_info()` | `get_dte_info(auth_number)` | Metadatos del DTE. |

### Parámetros comunes

- **`buyer`**: `"CF"` (consumidor final), un NIT string (`"12345678"` — se consulta el nombre), un dict CUI (`{"type":"CUI","taxid":...,"name":...}`) o un dict NIT explícito (`{"taxid","name","address","city","district","state","country","email"}`).
- **`items`**: lista de dicts con `description` (str, req), `price` (float/Decimal, req), `qty` (1), `type` (`"Servicio"`/`"Bien"`), `unit_of_measure` (`"UNI"`), `discount` (opcional).
- **`origin`** (NCRE/NDEB): `{"auth_number": ..., "date": "YYYY-MM-DD", "series": ..., "number": ...}`.

## Establecimiento (sucursal)

Cada NIT puede tener varios establecimientos registrados en el RTU. Configúralos al crear el cliente:

```python
client = DigifactClient(
    taxid="12345678",
    username="FELUSER",
    password="secret",
    branch_code="2",
    branch_name="SUCURSAL ZONA 10",
)
```

Aplican a todos los DTE emitidos por ese cliente. Si se omiten, se usan los defaults `"1"` / `"ESTABLECIMIENTO PRINCIPAL"`.

## Ejecutar las pruebas

```bash
# Pruebas unitarias (no requieren credenciales)
python -m pytest tests/ -v

# Pruebas de integración
export DIGIFACT_TAXID=12345678
export DIGIFACT_USERNAME=FELUSER
export DIGIFACT_PASSWORD=tu_contraseña
python -m pytest tests/ -v
```

## Variables de entorno

| Variable | Descripción |
|----------|-------------|
| `DIGIFACT_TAXID` | NIT del emisor (p. ej. `12345678`) |
| `DIGIFACT_USERNAME` | Usuario (p. ej. `FELUSER`) |
| `DIGIFACT_PASSWORD` | Contraseña de la cuenta |

## Manejo de errores

```python
from digifact_sdk import (
    DigifactError,          # base
    DigifactAuthError,      # fallo de autenticación
    DigifactApiError,       # error HTTP / de API
    DigifactValidationError, # rechazo de SAT
    DigifactNitNotFoundError, # NIT no encontrado
)

try:
    result = client.invoice("CF", [...])
except DigifactValidationError as exc:
    print(f"SAT rechazó: {exc}")
    print(f"Código: {exc.code}")
    print(f"Respuesta: {exc.raw}")
except DigifactError as exc:
    print(f"Error del SDK: {exc}")
```
