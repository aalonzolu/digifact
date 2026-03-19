# Digifact FEL SDK

SDKs oficiales para la API Digifact FEL NUC GT — facturación electrónica SAT Guatemala.

| SDK | Paquete | Versión mínima |
|-----|---------|---------------|
| [Python](./python/) | [`digifact-fel`](https://pypi.org/p/digifact-fel) (PyPI) | Python 3.10+ |
| [JavaScript](./javascript/) | [`digifact-fel`](https://www.npmjs.com/package/digifact-fel) (npm) | Node 18+ |
| [PHP](./php/) | [`aalonzolu/digifact`](https://packagist.org/packages/aalonzolu/digifact) (Packagist) | PHP 8.1+ |

## Instalación rápida

```bash
# Python
pip install digifact-fel

# JavaScript
npm install digifact-fel

# PHP
composer require aalonzolu/digifact
```

## Uso básico (los 3 SDKs)

```python
# Python
from digifact_fel import DigifactClient

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
import { DigifactClient } from 'digifact-fel';

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

## Variables de entorno

```bash
DIGIFACT_TAXID=12345678
DIGIFACT_USERNAME=FELUSER
DIGIFACT_PASSWORD=...
```

## Estructura del repositorio

```
digifact-fel/
├── python/          SDK Python — pyproject.toml, digifact_fel/
├── javascript/      SDK JavaScript — package.json, src/
├── php/             SDK PHP — composer.json, src/
├── docs/            Documentación y colección Postman
│   └── postman/     Colección y ambiente para Postman
├── scripts/         Herramientas de validación y smoke tests
└── .github/
    └── workflows/
        ├── ci.yml       Tests en cada push/PR
        └── publish.yml  Publicación a PyPI/npm/Packagist al hacer tag
```

## Publicar una release

```bash
# Actualizar versiones en pyproject.toml y package.json, luego:
git tag v1.2.3
git push origin v1.2.3
```

El workflow `publish.yml` se activa automáticamente y publica los tres paquetes.

**Secrets requeridos en GitHub:**

| Secret | Descripción |
|--------|-------------|
| `DIGIFACT_TAXID` | NIT del emisor de pruebas |
| `DIGIFACT_USERNAME` | Usuario de pruebas |
| `DIGIFACT_PASSWORD` | Contraseña |
| `NPM_TOKEN` | Token de npm (`npm token create`) |
| `PACKAGIST_USERNAME` | Usuario de packagist.org |
| `PACKAGIST_API_TOKEN` | Token de packagist.org/profile |

Para PyPI se usa **Trusted Publishing** (sin token) — configura el proyecto en pypi.org → Publishing → Add publisher con `owner/repo` y environment `pypi`.

## Documentación adicional

- [Python SDK](./python/README.md)
- [JavaScript SDK](./javascript/README.md)
- [PHP SDK](./php/README.md)
- [Documentación SAT](./docs/documentacion_sat.md)
- [Colección Postman](./docs/postman/)
