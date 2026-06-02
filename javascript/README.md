# Digifact FEL Guatemala — SDK para JavaScript

SDK en JavaScript (Node 18+) para la API de facturación electrónica en línea (FEL) de Guatemala de [Digifact](https://www.digifact.com.gt/).

Sin dependencias en tiempo de ejecución — usa `fetch` nativo (Node 18+).

## Configuración del cliente (`new DigifactClient({...})`)

Ordenados de más usados a menos usados.

| Propiedad | Tipo | Por defecto | Descripción |
|-----------|------|-------------|-------------|
| `taxid` | `string` | **requerido** | NIT del emisor. Acepta dígitos o con separadores (`"12345678"`, `"1234567-8"`). |
| `username` | `string` | **requerido** | Usuario corto de Digifact (p. ej. `"FELUSER"`). |
| `password` | `string` | `""` | Contraseña de la cuenta. **Requerido** si no se provee `token`. |
| `token` | `string` | `""` | Bearer token preobtenido. Si se provee, se omite el login. |
| `environment` | `string` | `"test"` | `"test"` o `"production"`. |
| `seller_name` | `string` | `""` | Nombre del emisor. Para NIT individual es el nombre de la persona; para S.A. / S.E. es la razón social de la entidad. Si está vacío, se consulta en SAT vía `lookupNit()`. |
| `seller_address` | `string` | `""` | Dirección del emisor. Si está vacía, se consulta en SAT. |
| `branch_code` | `string` | `"1"` | **Código del establecimiento** del RTU. Se escribe en `Seller.BranchInfo.Code`. |
| `branch_name` | `string` | `"ESTABLECIMIENTO PRINCIPAL"` | **Nombre comercial** de la sucursal — el mismo que aparece en la patente de comercio. Se escribe en `Seller.BranchInfo.Name`. |
| `afiliacion_iva` | `string` | `"GEN"` | Afiliación IVA del RTU: `"GEN"`, `"PEQ"` o `"EXE"`. |
| `tipo_frase` | `string \| null` | `null` | Sobreescritura global de `TipoFrase` (legacy). **Mutuamente exclusivo con `frases`**. Ver [frases](#configuración-de-frases-tipofrase--codigoescenario). |
| `escenario` | `string \| null` | `null` | Sobreescritura global de `CodigoEscenario` (legacy). **Mutuamente exclusivo con `frases`**. |
| `frases` | `Array<{tipo_frase, escenario}> \| null` | `null` | Lista de frases. Reemplaza a `tipo_frase`/`escenario`. **Mutuamente exclusivo** con ellos. Ver [frases](#configuración-de-frases-tipofrase--codigoescenario). |
| `auto_fuel_subsidy_frases` | `boolean \| null` | `null` | Controla la auto-inyección de frases 9/18 y 9/19 en combustible durante el subsidio. `null` = usar default (`true`). Ver [subsidio combustible](#subsidio-combustible-frases-automáticas). |
| `petroleo_rates` | `Object<string,number>` | `{}` | Mapa código PETROLEO → tarifa por unidad. Usado sólo por `fuelInvoice()` (gasolineras). |
| `timeout` | `number` | `120000` | Timeout HTTP en **ms**. |
| `tipo_personeria` | `string` | `"1"` | Código de `TipoPersoneria` del RTU. **Sólo aplica a RDON** (Recibo por Donación); ignóralo en los demás documentos. |

## Inicio rápido

```javascript
import { DigifactClient } from './src/index.js';

const client = new DigifactClient({
  taxid: '12345678',
  username: 'FELUSER',
  password: 'secret',
  environment: 'test',  // o 'production'
});

// FACT CF
const result = await client.invoice('CF', [
  { description: 'Consultoría', qty: 1, price: 100.00 }
]);
console.log(result.authNumber);

// FACT a NIT (el nombre del receptor se consulta automáticamente en SAT)
const result2 = await client.invoice('12345678', [
  { description: 'Laptop', qty: 1, price: 5000.00, type: 'Bien' },
  { description: 'Soporte', qty: 1, price: 500.00 },
]);

// FACT a receptor con CUI
const result3 = await client.invoice(
  { taxid: '3730617490101', type: 'CUI', name: 'Juan Pérez' },
  [{ description: 'Producto', qty: 2, price: 50.00 }]
);

// Receptor NIT con datos explícitos (sin consulta automática)
const result3b = await client.invoice(
  {
    taxid:    '12345678',
    name:     'EMPRESA EJEMPLO S.A.',
    address:  '6 AV 6-48 ZONA 9',
    city:     '01009',
    district: 'GUATEMALA',
    state:    'GUATEMALA',
    country:  'GT',
    email:    'facturacion@empresa.com',  // opcional
  },
  [{ description: 'Producto', qty: 1, price: 100.00 }]
);

// FCAM (Factura Cambiaria)
const result4 = await client.invoice('12345678', [
  { description: 'Servicio', qty: 1, price: 500.00 }
], {
  doc_type: 'FCAM',
  payment_terms: [{ date: '2026-04-18', amount: 500.00 }],
});

// Nota de crédito (NCRE)
const ncre = await client.creditNote('12345678', [
  { description: 'Devolución', qty: 1, price: 100.00 }
], {
  auth_number: 'XXXXXXXX-...',
  date: '2026-03-18',
  series: 'XXXXXXXX',
  number: '123456',
}, 'Producto defectuoso');

// Nota de débito (NDEB)
const ndeb = await client.debitNote('12345678', [...], origin, 'Cargo extra');

// Anulación
const cancel = await client.cancel('XXXXXXXX-...', 'CF', '2026-03-18 21:40:14', 'Error en monto');

// Consulta de NIT
const info = await client.lookupNit('12345678');
console.log(info.name);

// Obtener DTE
const doc = await client.getDte('XXXXXXXX-...');

// FACT Combustible — tarifas fijadas al inicializar (recomendado para gasolineras)
const stationClient = new DigifactClient({
  taxid: '12345678', username: 'FELUSER', password: 'secret',
  petroleo_rates: { '1': 4.70, '2': 4.60, '4': 1.30 }, // SUPER / REGULAR / DIESEL
});
// Sólo hace falta petroleo_code — petroleo_amount se completa automáticamente
const fuel = await stationClient.fuelInvoice('CF', [
  { description: 'GASOLINA SUPER',    qty: 30, price: 35.00, petroleo_code: '1', type: 'Bien' },
  { description: 'GASOLINA REGULAR',  qty: 20, price: 34.00, petroleo_code: '2', type: 'Bien' },
  { description: 'GASOLINA DIESEL',   qty: 50, price: 32.00, petroleo_code: '4', type: 'Bien' },
  // Ítems regulares (sin petroleo_code): sólo IVA, pueden coexistir
  { description: 'FILTRO DE ACEITE',    qty: 1, price: 45.00, type: 'Bien' },
  { description: 'SET DE CANDELAS NGK', qty: 1, price: 400.00, type: 'Bien' },
]);
console.log(fuel.authNumber);

// Alternativa: petroleo_amount explícito por ítem (no se necesita petroleo_rates)
const fuel2 = await client.fuelInvoice('CF', [
  { description: 'GASOLINA SUPER', qty: 1, price: 35.00, petroleo_amount: 4.70, petroleo_code: '1', type: 'Bien' },
]);
```

## Campos del ítem de combustible

| Campo | Tipo | Por defecto | Descripción |
|-----|------|---------|-------------|
| `description` | `string` | requerido | Descripción de la línea |
| `price` | `number` | requerido | Precio unitario completo al consumidor (incluye PETROLEO + IVA). Es lo que paga el cliente en la bomba. Si la factura del proveedor muestra un precio unitario *sin* PETROLEO/IDP (p. ej. `37.99`), suma la tarifa IDP por unidad: `price = 37.99 + 4.70 = 42.69`. |
| `qty` | `number` | `1` | Cantidad |
| `type` | `string` | `'Servicio'` | `'Bien'` o `'Servicio'` |
| `unitOfMeasure` | `string` | `'UNI'` | Código de unidad de SAT |
| `petroleo_amount` | `number` | — | Impuesto PETROLEO por unidad (omitir para ítems sólo-IVA) |
| `petroleo_code` | `string` | `'1'` | `'1'`=SUPER, `'2'`=REGULAR, `'4'`=DIESEL. Si se usa sin `petroleo_amount`, el código debe estar en `petroleo_rates` o se lanza `DigifactValidationError`. |

### Subsidio combustible — frases automáticas

Durante el **periodo de subsidio de combustibles** (2026-04-27 (incl.) a 2026-07-27 (excl.)), SAT exige incluir frases especiales `TipoFrase=9, Escenario=18` y `TipoFrase=9, Escenario=19`. **El SDK las agrega automáticamente** — no se necesita cambiar ningún código existente.

```js
// Sin cambios — el SDK agrega 9/18 y 9/19 automáticamente durante el subsidio
const result = await client.fuelInvoice('CF', items);

// Deshabilitarlo por llamada
await client.fuelInvoice('CF', items, { auto_fuel_subsidy_frases: false });

// Deshabilitarlo globalmente
const client = new DigifactClient({ ..., auto_fuel_subsidy_frases: false });

// Variable de entorno (sin tocar código): DIGIFACT_DISABLE_AUTO_FUEL_SUBSIDY_FRASES=1

// Frases completamente personalizadas (deshabilita auto-inyección)
await client.fuelInvoice('CF', items, { frases: [{ tipo_frase: '1', escenario: '1' }] });
```

> **Nota:** `frases` y `tipo_frase`/`escenario` son **mutuamente exclusivos** — pasarlos juntos lanza un error.

## Configuración de frases (TipoFrase / CodigoEscenario)

Todo DTE (excepto FESP) debe llevar un par `TipoFrase` + `CodigoEscenario`. El
SDK elige valores por defecto adecuados, por lo que **no** hace falta
configurar nada en el caso común.

**Orden de precedencia:** `opts` por llamada → globales del constructor → tabla de valores por defecto.

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

### Nueva API: `frases[]` (múltiples frases)

Usa `frases` cuando necesitas enviar **más de un par** TipoFrase/Escenario. Es **mutuamente exclusivo** con `tipo_frase`/`escenario`.

```js
// Lista explícita de frases (deshabilita auto-inyección)
await client.fuelInvoice('CF', items, {
  frases: [{ tipo_frase: '1', escenario: '1' }],
});

// Globalmente en el constructor
const client = new DigifactClient({
  taxid: '12345678', username: 'FELUSER', password: '...',
  frases: [{ tipo_frase: '1', escenario: '2' }],  // ISR régimen opcional simplificado
});
```

### API legacy: `tipo_frase` / `escenario`

Siguen funcionando exactamente como antes para integraciones existentes.

```js
// Sobreescritura por llamada (uno o ambos)
await client.invoice('CF', items, { escenario: '1' });
await client.invoice('CF', items, { tipo_frase: '2', escenario: '1' });

// Funciona igual en los demás métodos de DTE
await client.creditNote('12345678', items, origin, '...', { tipo_frase: '2', escenario: '1' });
await client.fuelInvoice('CF', items, { tipo_frase: '2', escenario: '1' });

// O globalmente al construir el cliente (p. ej. GEN + ISR régimen opcional simplificado)
const client = new DigifactClient({
  taxid: '12345678', username: 'FELUSER', password: '...',
  afiliacion_iva: 'GEN',
  tipo_frase: '1',  // opcional — la tabla ya devuelve '1' para GEN
  escenario: '2',   // ISR régimen opcional simplificado (sobreescribe el '1' por defecto)
});
```

## Referencia de métodos

Todos los métodos son **asíncronos**. Los de emisión devuelven `DteResult` con `result.authNumber`, `series`, `number`, `issueDateTime`, `raw`.

| Método | Firma | Descripción |
|--------|-------|-------------|
| `invoice()` | `invoice(buyer, items, opts = {})` | Emite FACT, FCAM, FESP, FPEQ, NABN, RDON o RECI según `opts.doc_type`. |
| `ccaInvoice()` | `ccaInvoice(buyer, items, cobros, opts = {})` | FACT con complemento CCA. |
| `fuelInvoice()` | `fuelInvoice(buyer, items, opts = {})` | FACT con complemento combustible (IVA + PETROLEO). `opts.frases`, `opts.auto_fuel_subsidy_frases`. Auto-inyecta 9/18 y 9/19 durante el subsidio. |
| `creditNote()` | `creditNote(buyer, items, origin, reason, opts = {})` | Nota de crédito (NCRE). |
| `debitNote()` | `debitNote(buyer, items, origin, reason, opts = {})` | Nota de débito (NDEB). |
| `creditNoteTotal()` | `creditNoteTotal(authNumber, issueDateTime, reason = '...', reference = '')` | Nota de crédito total. Devuelve `object`. |
| `cancel()` | `cancel(authNumber, receiverId, issueDateTime, reason = 'Anulación')` | Anula un DTE. Devuelve `object`. |
| `lookupNit()` | `lookupNit(nit)` | Consulta SAT. Devuelve `{ nit, name, address, city, district, state }`. |
| `getDte()` | `getDte(authNumber, format = 'JSON')` | Recupera el DTE (`'JSON'`, `'XML'`, `'HTML'`, `'PDF'`). |
| `getDteInfo()` | `getDteInfo(authNumber)` | Metadatos del DTE. |

### Parámetros comunes

- **`buyer`**: `'CF'` (consumidor final), un NIT string (`'12345678'` — se consulta el nombre), un objeto CUI (`{ type: 'CUI', taxid, name }`) o un objeto NIT explícito (`{ taxid, name, address, city, district, state, country, email }`).
- **`items`**: array de objetos con `description` (req), `price` (req), `qty` (1), `type` (`'Servicio'`/`'Bien'`), `unit_of_measure` (`'UNI'`), `discount` (opcional).
- **`opts`**: `doc_type`, `payment_terms` (req. para FCAM), `amount_str`, `observaciones`, `tipo_personeria`, `tipo_frase`, `escenario`.
- **`origin`** (NCRE/NDEB): `{ auth_number, date: 'YYYY-MM-DD', series, number }`.

## Establecimiento (sucursal)

Cada NIT puede tener varios establecimientos registrados en el RTU. Configúralos al crear el cliente:

```js
const client = new DigifactClient({
  taxid: '12345678',
  username: 'FELUSER',
  password: 'secret',
  branch_code: '2',
  branch_name: 'SUCURSAL ZONA 10',
});
```

Aplican a todos los DTE emitidos por ese cliente. Si se omiten, se usan los defaults `'1'` / `'ESTABLECIMIENTO PRINCIPAL'`.

## Manejo de errores

```js
import {
  DigifactError,            // base
  DigifactAuthError,        // fallo de autenticación
  DigifactApiError,         // error HTTP / de API
  DigifactValidationError,  // rechazo de SAT
  DigifactNitNotFoundError, // NIT no encontrado
} from 'digifact-sdk';

try {
  const r = await client.invoice('CF', items);
} catch (e) {
  if (e instanceof DigifactValidationError) {
    console.error('SAT rechazó:', e.message, e.raw);
  } else if (e instanceof DigifactError) {
    console.error('Error del SDK:', e.message);
  }
}
```

## Ejecutar las pruebas

```bash
# Pruebas unitarias (sin credenciales)
node --test --test-name-pattern='Unit' tests/integration.test.js

# Todas las pruebas incluyendo integración
export DIGIFACT_TAXID=12345678
export DIGIFACT_USERNAME=FELUSER
export DIGIFACT_PASSWORD=tu_contraseña
npm test
```

## Requisitos

- Node.js 18+ (usa `fetch` y `BigInt` nativos)
- No requiere dependencias npm
