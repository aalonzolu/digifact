# Digifact FEL SDK

SDKs para la API Digifact FEL NUC GT — facturación electrónica SAT Guatemala.

| SDK | Paquete | Versión mínima |
|-----|---------|---------------|
| [Python](./python/) | [`digifact-sdk`](https://pypi.org/p/digifact-sdk) (PyPI) | Python 3.10+ |
| [JavaScript](./javascript/) | [`digifact-sdk`](https://www.npmjs.com/package/digifact-sdk) (npm) | Node 18+ |
| [PHP](./php/) | [`aalonzolu/digifact`](https://packagist.org/packages/aalonzolu/digifact) (Packagist) | PHP 8.1+ |
| [C# / .NET](./dotnet/) | [`Digifact.Fel`](https://www.nuget.org/packages/Digifact.Fel) (NuGet) | .NET 8+ |

## Instalación rápida

```bash
# Python
pip install digifact-sdk

# JavaScript
npm install digifact-sdk

# PHP
composer require aalonzolu/digifact

# C# / .NET
dotnet add package Digifact.Fel
```

## Uso básico (los 4 SDKs)

```python
# Python
from digifact_sdk import DigifactClient

client = DigifactClient(
    taxid="12345678",
    username="FELUSER",
    password="...",
    environment="test",   # o "production"
)
result = client.invoice("CF", [
    {"description": "Servicio", "qty": 1, "price": 100},
])
print(result.auth_number)
```

```js
// JavaScript
import { DigifactClient } from 'digifact-sdk';

const client = new DigifactClient({
  taxid: '12345678', username: 'FELUSER', password: '...', environment: 'test',
});
const result = await client.invoice('CF', [
  { description: 'Servicio', qty: 1, price: 100 },
]);
console.log(result.authNumber);
```

```php
// PHP
use Digifact\Fel\DigifactClient;

$client = new DigifactClient([
  'taxid' => '12345678', 'username' => 'FELUSER',
  'password' => '...', 'environment' => 'test',
]);
$result = $client->invoice('CF', [
  ['description' => 'Servicio', 'qty' => 1, 'price' => 100],
]);
echo $result->authNumber;
```

```csharp
// C# / .NET
using Digifact.Fel;

using var client = new DigifactClient(new DigifactOptions {
  Taxid = "12345678", Username = "FELUSER",
  Password = "...", Environment = "test",
});
var result = await client.InvoiceAsync("CF", new[] {
  new LineItem { Description = "Servicio", Qty = 1, Price = 100 },
});
Console.WriteLine(result.AuthNumber);
```

## Tipos de DTE soportados

| Método | DTE | Descripción |
|--------|-----|-------------|
| `invoice()` | FACT | Factura de consumidor final o NIT |
| `invoice()` | FCAM | Factura cambiaria con cuotas |
| `invoice()` | NABN | Nota de abono |
| `invoice()` | FESP | Factura especial (retención) |
| `invoice()` | RDON | Recibo por donación |
| `invoice()` | RECI | Recibo de colegiatura |
| `invoice()` | FPEQ | Factura pequeño contribuyente |
| `debitNote()` | NDEB | Nota de débito |
| `creditNote()` | NCRE | Nota de crédito parcial |
| `creditNoteTotal()` | — | Nota de crédito total (anulación) |
| `cancel()` | — | Anulación de DTE |
| `fuelInvoice()` | FACT+Combustible | Factura con IVA + impuesto PETROLEO, o exenta por el Decreto 22-2026 |
| `ccaInvoice()` | FACT+CCA | Cobro por cuenta ajena |
| `lookupNit()` | — | Consulta nombre/dirección de un NIT en SAT |
| `lookupCui()` | — | Consulta el nombre de un CUI (DPI) en SAT |
| `getDte()` | — | Descarga un DTE ya emitido |

## Configuración del cliente (común a los 4 SDKs)

Ordenados de más usados a menos usados.

| Parámetro | Requerido | Descripción |
|-----------|:---------:|-------------|
| `taxid` / `Taxid` | ✔ | NIT del emisor. |
| `username` / `Username` | ✔ | Usuario Digifact (la parte después de `GT.<NIT>.`). |
| `password` / `Password` | ✔* | Contraseña. *O bien `token`. |
| `token` / `Token` | ✔* | Bearer token preobtenido. *O bien `password`. |
| `environment` / `Environment` | | `"test"` (default) o `"production"`. |
| `seller_name` / `SellerName` | | Nombre del emisor. Para NIT individual es el nombre de la persona; para S.A. / S.E. es la razón social. Auto-consulta en SAT si se omite. |
| `seller_address` / `SellerAddress` | | Dirección del emisor. Auto-consulta en SAT si se omite. |
| `branch_code` / `BranchCode` | | Código del establecimiento (RTU). Default `"1"`. |
| `branch_name` / `BranchName` | | Nombre comercial de la sucursal, el mismo que aparece en la patente de comercio. Default `"ESTABLECIMIENTO PRINCIPAL"`. |
| `afiliacion_iva` / `AfiliacionIva` | | `"GEN"` (default), `"PEQ"` o `"EXE"`. |
| `tipo_frase` / `TipoFrase` | | Override global de `TipoFrase` (legacy). **Mutuamente exclusivo con `frases`**. |
| `escenario` / `Escenario` | | Override global de `CodigoEscenario` (legacy). **Mutuamente exclusivo con `frases`**. |
| `frases` / `Frases` | | **Nuevo.** Lista de frases `{tipo_frase, escenario}`. Reemplaza a `tipo_frase`/`escenario`. Mutuamente exclusivo con ellos. |
| `petroleo_rates` / `PetroleoRates` | | Mapa código→tarifa PETROLEO para `fuelInvoice()` (sólo gasolineras). |
| `timeout` / `Timeout` | | Timeout HTTP. Default 120s (JS: 120000 ms). |
| `tipo_personeria` / `TipoPersoneria` | | Código de personería del RTU. Sólo aplica a RDON. Default `"1"`. |

Ver detalles y ejemplos por lenguaje en los READMEs respectivos.

## Exención temporal de combustibles (Decreto 22-2026)

Del **1 de octubre al 31 de diciembre de 2026** la gasolina superior, la regular y el diésel están exentos
de IVA e IDP. El combustible comprado exento se sigue vendiendo sin impuestos hasta agotarlo, aunque sea
después del 31 de diciembre, así que el corte depende del inventario de cada estación. Por eso los SDKs no
aplican la exención por fecha: la decide el código de unidad gravable de cada ítem.

| `petroleo_code` | Combustible exento | IDP exonerado (Q/galón) |
|:---:|---|---:|
| `18` | Gasolina superior | 4.70 |
| `21` | Gasolina regular con etanol (la que despachan las estaciones) | 4.14 |
| `20` | Diésel | 1.30 |
| `19` | Gasolina regular sin etanol. SAT sólo lo acepta a importadores | 4.60 |

Con cualquiera de esos códigos el SDK envía el IVA como exento (unidad gravable `2`, monto 0), el PETROLEO
en 0 y agrega las frases `9/23` y `4/38` a las que ya lleve la factura. No hace falta `petroleo_amount` ni
`petroleo_rates`. `price` es el precio de bomba, ya sin IVA ni IDP, y `qty` va en galones:

```python
client.fuel_invoice("CF", [
    {"description": "GASOLINA SUPER",   "qty": 10, "price": 30.00, "petroleo_code": "18", "unit_of_measure": "GAL"},
    {"description": "GASOLINA REGULAR", "qty": 5,  "price": 28.00, "petroleo_code": "21", "unit_of_measure": "GAL"},
    {"description": "DIESEL",           "qty": 20, "price": 27.00, "petroleo_code": "20", "unit_of_measure": "GAL"},
])
```

La representación gráfica debe mostrar cuánto se habría pagado de cada impuesto. La de Digifact ya lo
imprime sola; si imprimes tu propio ticket, pide los montos y las leyendas al SDK:

```python
from digifact_sdk import fuel_exemption

exencion = fuel_exemption(items)
exencion.idp        # Decimal("93.70")
exencion.iva        # Decimal("117.60")
exencion.leyendas   # ["Monto de exención temporal de IDP aplicada: Q 93.70, según Decreto Número 22-2026", ...]
```

A tener en cuenta:

- Mientras dura la exención, SAT rechaza el IDP con monto mayor a cero en los códigos gravados.
- Al agotar el inventario exento, vuelve a los códigos gravados con su `petroleo_amount`.
- En facturas que mezclan combustible exento con ítems gravados, la representación de Digifact calcula la
  leyenda del IVA sobre el total de la factura; `fuel_exemption` la calcula sólo sobre el combustible exento.

## Subsidio combustibles

El subsidio a la gasolina y al diésel **finalizó el jueves 2 de julio de 2026 a las 24:00**, antes de lo
previsto: el presupuesto de Q2 mil millones (Decreto 11-2026, reglamentado por el Acuerdo Gubernativo
64-2026) se agotó por la demanda. Por eso los SDKs **nunca** envían las frases `TipoFrase=9, Escenario=18`
ni `TipoFrase=9, Escenario=19` por su cuenta — no hay fecha de corte que valga para todos.

Las estaciones con inventario adquirido bajo el subsidio deben mantener el precio rebajado hasta agotar ese
producto, sujeto a verificación. Mientras te quede ese inventario, manda las frases explícitamente con
`frases`; al agotarlo, deja de mandarlas:

```python
client.fuel_invoice("CF", items, frases=[
    {"tipo_frase": "1", "escenario": "1"},   # frase base
    {"tipo_frase": "9", "escenario": "18"},
    {"tipo_frase": "9", "escenario": "19"},
])
```

La leyenda del subsidio en la representación gráfica la genera Digifact a partir del XML certificado, no
estos SDKs: al dejar de mandar las frases, deja de imprimirse sola.

## Variables de entorno

```bash
DIGIFACT_TAXID=12345678
DIGIFACT_USERNAME=FELUSER
DIGIFACT_PASSWORD=...
```

## Estructura del repositorio

```
digifact-sdk/
├── python/          SDK Python — pyproject.toml, digifact_sdk/
├── javascript/      SDK JavaScript — package.json, src/
├── php/             SDK PHP — composer.json, src/
├── dotnet/          SDK C#/.NET — Digifact.Fel.csproj, *.cs
├── docs/            Documentación y colección Postman
│   └── postman/     Colección y ambiente para Postman
├── scripts/         Herramientas de validación y smoke tests
└── .github/
    └── workflows/
        ├── ci.yml       Tests en cada push/PR
        └── publish.yml  Publicación a PyPI/npm/Packagist/NuGet al hacer tag
```

## Publicar una release

```bash
# Actualizar versiones en pyproject.toml y package.json, luego:
git tag v1.2.3
git push origin v1.2.3
```

El workflow `publish.yml` se activa automáticamente y publica los cuatro paquetes.


## Documentación adicional

- [Python SDK](./python/README.md)
- [JavaScript SDK](./javascript/README.md)
- [PHP SDK](./php/README.md)
- [C# / .NET SDK](./dotnet/README.md)
- [Documentación SAT](./docs/documentacion_sat.md)
- [Colección Postman](./docs/postman/)
