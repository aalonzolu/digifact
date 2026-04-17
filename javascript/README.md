# Digifact FEL Guatemala — SDK para JavaScript

SDK en JavaScript (Node 18+) para la API de facturación electrónica en línea (FEL) de Guatemala de [Digifact](https://www.digifact.com.gt/).

Sin dependencias en tiempo de ejecución — usa `fetch` nativo (Node 18+).

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

## Configuración de frases (TipoFrase / CodigoEscenario)

Todo DTE (excepto FESP) debe llevar un par `TipoFrase` + `CodigoEscenario`. El
SDK elige valores por defecto adecuados, por lo que **no** hace falta
configurar nada en el caso común.

**Orden de precedencia:** `opts` por llamada → globales del constructor (`tipo_frase` / `escenario`) → tabla de valores por defecto.

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
independiente — por llamada (dentro del objeto `opts`) o globalmente al
construir el cliente. Cuando se omiten, cada uno cae al global del constructor
y luego a la tabla de valores por defecto.

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
