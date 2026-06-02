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
| `fuelInvoice()` | FACT+Combustible | Factura con IVA + impuesto PETROLEO |
| `ccaInvoice()` | FACT+CCA | Cobro por cuenta ajena |
| `lookupNit()` | — | Consulta nombre/dirección de un NIT en SAT |
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
| `auto_fuel_subsidy_frases` / `AutoFuelSubsidyFrases` | | **Nuevo.** Controla la auto-inyección de frases 9/18 y 9/19 en `fuelInvoice()`. Default `true` (o `null`). |
| `petroleo_rates` / `PetroleoRates` | | Mapa código→tarifa PETROLEO para `fuelInvoice()` (sólo gasolineras). |
| `timeout` / `Timeout` | | Timeout HTTP. Default 120s (JS: 120000 ms). |
| `tipo_personeria` / `TipoPersoneria` | | Código de personería del RTU. Sólo aplica a RDON. Default `"1"`. |

Ver detalles y ejemplos por lenguaje en los READMEs respectivos.

## Subsidio combustibles — frases automáticas (9/18 y 9/19)

Durante el **periodo de subsidio** (2026-04-27 (incl.) a 2026-07-27 (excl.)), SAT exige incluir frases especiales `TipoFrase=9, Escenario=18` y `TipoFrase=9, Escenario=19` en facturas de combustible. **El SDK las agrega automáticamente** — las integraciones existentes no necesitan cambios.

Para deshabilitar la auto-inyección (ej. presupuesto agotado):
- Por ENV VAR (sin deploy): `DIGIFACT_DISABLE_AUTO_FUEL_SUBSIDY_FRASES=1`
- Por código: `auto_fuel_subsidy_frases=false` al inicializar el cliente o en cada llamada.

Para control total (frases propias): usa `frases=[...]` en lugar de `tipo_frase`/`escenario`.

## Variables de entorno

```bash
DIGIFACT_TAXID=12345678
DIGIFACT_USERNAME=FELUSER
DIGIFACT_PASSWORD=...
# Deshabilita auto-inyección de frases de subsidio sin tocar código:
# DIGIFACT_DISABLE_AUTO_FUEL_SUBSIDY_FRASES=1
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
