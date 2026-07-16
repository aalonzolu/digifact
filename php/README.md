# Digifact FEL Guatemala — SDK para PHP

SDK en PHP para la API de facturación electrónica en línea (FEL) de Guatemala de [Digifact](https://www.digifact.com.gt/).

## Requisitos

- PHP 8.1+
- Extensiones: `curl`, `bcmath`, `json`

## Instalación

```bash
composer require aalonzolu/digifact
```

O desde el código fuente:

```bash
composer install
```

## Configuración del cliente (`new DigifactClient([...])`)

Ordenados de más usados a menos usados.

| Clave | Tipo | Por defecto | Descripción |
|-------|------|-------------|-------------|
| `taxid` | `string` | **requerido** | NIT del emisor. Acepta dígitos o con separadores (`"12345678"`, `"1234567-8"`); los no-dígitos se eliminan. Se rellena internamente a 12 caracteres. |
| `username` | `string` | **requerido** | Usuario corto de Digifact (la parte después de `GT.<NIT>.`, p. ej. `"FELUSER"`). |
| `password` | `string` | `""` | Contraseña de la cuenta. **Requerido** si no se provee `token`. |
| `token` | `string` | `""` | Bearer token preobtenido. Si se provee, se omite el login y `password` no es necesario. |
| `environment` | `string` | `"test"` | `"test"` o `"production"`. |
| `seller_name` | `string` | `""` | Nombre del emisor. Para NIT individual es el nombre de la persona; para S.A. / S.E. es la razón social de la entidad. Si está vacío, se consulta en SAT vía `lookupNit($taxid)`. |
| `seller_address` | `string` | `""` | Dirección del emisor. Si está vacía, se consulta en SAT. |
| `branch_code` | `string` | `"1"` | **Código del establecimiento** del RTU. Un NIT puede tener varios establecimientos (1, 2, 3…); `"1"` suele ser el principal. Se escribe en `Seller.BranchInfo.Code`. |
| `branch_name` | `string` | `"ESTABLECIMIENTO PRINCIPAL"` | **Nombre comercial** de la sucursal — el mismo que aparece en la patente de comercio. Se escribe en `Seller.BranchInfo.Name`. |
| `afiliacion_iva` | `string` | `"GEN"` | Afiliación IVA del RTU: `"GEN"`, `"PEQ"` (pequeño contribuyente) o `"EXE"` (exento). |
| `tipo_frase` | `?string` | `null` | Sobreescritura global de `TipoFrase` (legacy). **Mutuamente exclusivo con `frases`**. Ver [Configuración de frases](#configuración-de-frases-tipofrase--codigoescenario). |
| `escenario` | `?string` | `null` | Sobreescritura global de `CodigoEscenario` (legacy). **Mutuamente exclusivo con `frases`**. |
| `frases` | `?array` | `null` | Lista de frases `[['tipo_frase'=>..., 'escenario'=>...]]`. Reemplaza a `tipo_frase`/`escenario`. **Mutuamente exclusivo** con ellos. Ver [Configuración de frases](#configuración-de-frases-tipofrase--codigoescenario). |
| `petroleo_rates` | `array<string,float>` | `[]` | Mapa código PETROLEO → tarifa por unidad (SUPER/REGULAR/DIESEL). Usado sólo por `fuelInvoice()` (gasolineras). |
| `timeout` | `int` | `120` | Timeout HTTP en segundos. |
| `tipo_personeria` | `string` | `"1"` | Código de `TipoPersoneria` del RTU. **Sólo aplica a RDON** (Recibo por Donación); ignóralo en los demás documentos. |

## Inicio rápido

```php
use Digifact\Fel\DigifactClient;

$client = new DigifactClient([
    'taxid'       => '12345678',
    'username'    => 'FELUSER',
    'password'    => 'secret',
    'environment' => 'test',  // o 'production'
]);

// FACT CF
$result = $client->invoice('CF', [
    ['description' => 'Consultoría', 'qty' => 1, 'price' => 100.00]
]);
echo $result->authNumber;

// FACT a NIT (el nombre del receptor se consulta automáticamente)
$result = $client->invoice('12345678', [
    ['description' => 'Laptop', 'qty' => 1, 'price' => 5000.00, 'type' => 'Bien'],
    ['description' => 'Soporte', 'qty' => 1, 'price' => 500.00],
]);

// FACT a receptor con CUI (DPI) — el nombre se consulta en SAT automáticamente
$result = $client->invoice(
    ['taxid' => '1234567890123', 'type' => 'CUI'],
    [['description' => 'Producto', 'qty' => 2, 'price' => 50.00]]
);

// Equivalente: un string de 13 dígitos se resuelve como CUI
$result = $client->invoice('1234567890123', [
    ['description' => 'Producto', 'qty' => 2, 'price' => 50.00],
]);

// Con nombre explícito no se consulta SAT
$result = $client->invoice(
    ['taxid' => '3730617490101', 'type' => 'CUI', 'name' => 'Juan Pérez'],
    [['description' => 'Producto', 'qty' => 2, 'price' => 50.00]]
);

// Receptor NIT con datos explícitos (sin consulta automática)
$result = $client->invoice(
    [
        'taxid'    => '12345678',
        'name'     => 'EMPRESA EJEMPLO S.A.',
        'address'  => '6 AV 6-48 ZONA 9',
        'city'     => '01009',
        'district' => 'GUATEMALA',
        'state'    => 'GUATEMALA',
        'country'  => 'GT',
        'email'    => 'facturacion@empresa.com',  // opcional
    ],
    [['description' => 'Producto', 'qty' => 1, 'price' => 100.00]]
);

// FCAM (Factura Cambiaria)
$result = $client->invoice('12345678', [
    ['description' => 'Servicio', 'qty' => 1, 'price' => 500.00]
], [
    'doc_type'      => 'FCAM',
    'payment_terms' => [['date' => '2026-04-18', 'amount' => 500.00]],
]);

// Nota de crédito (NCRE)
$result = $client->creditNote('12345678', [
    ['description' => 'Devolución', 'qty' => 1, 'price' => 100.00]
], [
    'auth_number' => 'XXXXXXXX-...',
    'date'        => '2026-03-18',
    'series'      => 'XXXXXXXX',
    'number'      => '123456',
], 'Producto defectuoso');

// Nota de débito (NDEB)
$result = $client->debitNote('12345678', [...], $origin, 'Cargo extra');

// Anulación
$result = $client->cancel('XXXXXXXX-...', 'CF', '2026-03-18 21:40:14', 'Error en monto');

// Consulta de NIT
$info = $client->lookupNit('12345678');
echo $info['name'];

// Consulta de CUI (DPI) — SAT devuelve el nombre como "APELLIDOS, NOMBRES"
$info = $client->lookupCui('1234567890123');
echo $info['name'];   // "PEREZ LOPEZ, JUAN CARLOS"

// Obtener DTE
$doc = $client->getDte('XXXXXXXX-...');

// FACT Combustible — tarifas fijadas al inicializar (recomendado para gasolineras)
$stationClient = new DigifactClient([
    'taxid' => '12345678', 'username' => 'FELUSER', 'password' => 'secret',
    'petroleo_rates' => ['1' => 4.70, '2' => 4.60, '4' => 1.30], // SUPER / REGULAR / DIESEL
]);
// Sólo hace falta petroleo_code — petroleo_amount se completa automáticamente
$result = $stationClient->fuelInvoice('CF', [
    ['description' => 'GASOLINA SUPER',    'qty' => 30, 'price' => 35.00, 'petroleo_code' => '1', 'type' => 'Bien'],
    ['description' => 'GASOLINA REGULAR',  'qty' => 20, 'price' => 34.00, 'petroleo_code' => '2', 'type' => 'Bien'],
    ['description' => 'GASOLINA DIESEL',   'qty' => 50, 'price' => 32.00, 'petroleo_code' => '4', 'type' => 'Bien'],
    // Ítems regulares (sin petroleo_code): sólo IVA, pueden coexistir
    ['description' => 'FILTRO DE ACEITE',    'qty' => 1, 'price' => 45.00, 'type' => 'Bien'],
    ['description' => 'SET DE CANDELAS NGK', 'qty' => 1, 'price' => 400.00, 'type' => 'Bien'],
]);
echo $result->authNumber;

// Alternativa: petroleo_amount explícito por ítem
$result2 = $client->fuelInvoice('CF', [
    ['description' => 'GASOLINA SUPER', 'qty' => 1, 'price' => 35.00, 'petroleo_amount' => 4.70, 'petroleo_code' => '1', 'type' => 'Bien'],
]);
```

## Campos del ítem de combustible

| Campo | Tipo | Por defecto | Descripción |
|-----|------|---------|-------------|
| `description` | `string` | requerido | Descripción de la línea |
| `price` | `float` | requerido | Precio unitario completo al consumidor (incluye PETROLEO + IVA). Es lo que paga el cliente en la bomba. Si la factura del proveedor muestra un precio unitario *sin* PETROLEO/IDP (p. ej. `37.99`), suma la tarifa IDP por unidad: `price = 37.99 + 4.70 = 42.69`. |
| `qty` | `float` | `1` | Cantidad |
| `type` | `string` | `'Servicio'` | `'Bien'` o `'Servicio'` |
| `unit_of_measure` | `string` | `'UNI'` | Código de unidad de SAT |
| `petroleo_amount` | `float` | — | Impuesto PETROLEO por unidad (omitir para ítems sólo-IVA) |
| `petroleo_code` | `string` | `'1'` | `'1'`=SUPER, `'2'`=REGULAR, `'4'`=DIESEL. Si se usa sin `petroleo_amount`, el código debe estar en `petroleo_rates` o se lanza `DigifactValidationException`. |

### Subsidio combustible {#subsidio-combustible}

El subsidio a la gasolina y al diésel **finalizó el jueves 2 de julio de 2026 a las 24:00**, antes de lo previsto: el presupuesto de Q2 mil millones (Decreto 11-2026, reglamentado por el Acuerdo Gubernativo 64-2026) se agotó por la demanda. El SDK **nunca** envía frases de subsidio por su cuenta — no hay fecha de corte que valga para todos: una factura de combustible lleva únicamente la frase base que corresponde a la afiliación del emisor.

Las estaciones con inventario adquirido bajo el subsidio deben mantener el precio rebajado hasta agotar ese producto, sujeto a verificación. Si es tu caso, pasa las frases explícitamente con `frases` mientras te quede ese inventario, y deja de mandarlas al agotarlo:

```php
$result = $client->fuelInvoice('CF', $items, [
    'frases' => [
        ['tipo_frase' => '1', 'escenario' => '1'],
        ['tipo_frase' => '9', 'escenario' => '18'],
        ['tipo_frase' => '9', 'escenario' => '19'],
    ],
]);
```

> **Nota:** `frases` y `tipo_frase`/`escenario` son **mutuamente exclusivos** — pasarlos juntos lanza `DigifactValidationException`.

## Configuración de frases (TipoFrase / CodigoEscenario)

Todo DTE (excepto FESP) debe llevar un par `TipoFrase` + `CodigoEscenario`
dentro de `Seller.AdditionlInfo`. Los pares válidos dependen de la **afiliación
IVA** y el **régimen ISR** registrados en el RTU del emisor. El SDK elige
valores por defecto adecuados, por lo que **no** hace falta configurar nada
en el caso común.

**Orden de precedencia:** `$opts` por llamada → globales del constructor → tabla de valores por defecto.

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

Usa `frases` cuando necesitas enviar **más de un par**. Es **mutuamente exclusivo** con `tipo_frase`/`escenario`.

```php
// Lista explícita (reemplaza a tipo_frase/escenario)
$client->fuelInvoice('CF', $items, [
    'frases' => [['tipo_frase' => '1', 'escenario' => '1']],
]);

// Globalmente en el constructor
$client = new DigifactClient([
    'taxid' => '12345678', 'username' => 'FELUSER', 'password' => '...',
    'frases' => [['tipo_frase' => '1', 'escenario' => '2']],  // ISR régimen opcional
]);
```

### API legacy: `tipo_frase` / `escenario`

Siguen funcionando exactamente como antes para integraciones existentes.

```php
// Sobreescritura por llamada (uno o ambos)
$client->invoice('CF', $items, ['escenario' => '1']);
$client->invoice('CF', $items, ['tipo_frase' => '2', 'escenario' => '1']);

// Funciona igual en los demás métodos de DTE
$client->creditNote('12345678', $items, $origin, '...', ['tipo_frase' => '2', 'escenario' => '1']);
$client->fuelInvoice('CF', $items, ['tipo_frase' => '2', 'escenario' => '1']);

// O globalmente al construir el cliente (p. ej. GEN + ISR régimen opcional simplificado)
$client = new DigifactClient([
    'taxid' => '12345678', 'username' => 'FELUSER', 'password' => '...',
    'afiliacion_iva' => 'GEN',
    'tipo_frase'     => '1', // opcional — la tabla ya devuelve '1' para GEN
    'escenario'      => '2', // ISR régimen opcional simplificado (sobreescribe el '1' por defecto)
]);
```

Para descubrir el par correcto en un caso particular, revisa las afiliaciones
del RTU en el portal de SAT.

## Referencia de métodos

Todos los métodos devuelven `DteResult` (con `$result->authNumber`, `series`, `number`, `issueDateTime`, `raw`) salvo indicación contraria.

| Método | Firma | Retorna | Descripción |
|--------|-------|---------|-------------|
| `invoice()` | `invoice(string\|array $buyer, array $items, array $opts = [])` | `DteResult` | Emite FACT, FCAM, FESP, FPEQ, NABN, RDON, RECI o FACT+CUI según `$opts['doc_type']`. |
| `ccaInvoice()` | `ccaInvoice(string\|array $buyer, array $items, array $cobros, array $opts = [])` | `DteResult` | FACT con complemento CCA (cobro por cuenta ajena). |
| `fuelInvoice()` | `fuelInvoice(string\|array $buyer, array $items, array $opts = [])` | `DteResult` | FACT con complemento combustible (IVA + PETROLEO). `$opts['frases']`, `$opts['tipo_frase']`, `$opts['escenario']`. El SDK no envía frases de subsidio automáticamente — ver [Subsidio combustible](#subsidio-combustible). |
| `creditNote()` | `creditNote(string\|array $buyer, array $items, array $origin, string $reason, array $opts = [])` | `DteResult` | Nota de crédito (NCRE) — ajuste parcial del documento origen. |
| `debitNote()` | `debitNote(string\|array $buyer, array $items, array $origin, string $reason, array $opts = [])` | `DteResult` | Nota de débito (NDEB). |
| `creditNoteTotal()` | `creditNoteTotal(string $authNumber, string $issueDateTime, string $reason = '...', string $reference = '')` | `array` | Nota de crédito total sobre un DTE previo. |
| `cancel()` | `cancel(string $authNumber, string $receiverId, string $issueDateTime, string $reason = 'Anulación')` | `array` | Anula un DTE emitido. |
| `lookupNit()` | `lookupNit(string $nit)` | `array` | Consulta el nombre y dirección de un NIT en SAT. Devuelve `['nit','name','address','city','district','state']`. |
| `lookupCui()` | `lookupCui(string $cui)` | `array` | Consulta el nombre de un CUI (DPI) en SAT. Devuelve `['cui','name','status']`. El nombre viene como `"APELLIDOS, NOMBRES"`, tal como lo registra SAT. |
| `getDte()` | `getDte(string $authNumber, string $format = 'JSON')` | `array` | Recupera el DTE en el formato indicado (`'JSON'`, `'XML'`, `'HTML'`, `'PDF'`). |
| `getDteInfo()` | `getDteInfo(string $authNumber)` | `array` | Metadatos de un DTE emitido. |

### Parámetros comunes

- **`$buyer`**: puede ser
  - `'CF'` → consumidor final,
  - un NIT como string (`'12345678'`) → se consulta el nombre automáticamente,
  - un CUI como string de 13 dígitos (`'1234567890123'`) → se consulta el nombre automáticamente,
  - un array `['type' => 'CUI', 'taxid' => ...]` → se consulta el nombre; con `'name'` explícito no se consulta,
  - un array NIT explícito (`['taxid','name','address','city','district','state','country','email']`).
- **`$items`**: lista de arreglos con `description` (string, req), `price` (float, req), `qty` (float, 1), `type` (`'Bien'`/`'Servicio'`), `unit_of_measure` (`'UNI'`), `discount` (opcional).
- **`$opts`**: `doc_type` (`'FACT'`/`'FCAM'`/`'FESP'`/`'FPEQ'`/`'NABN'`/`'RDON'`/`'RECI'`), `payment_terms` (req. para FCAM), `amount_str`, `observaciones`, `tipo_personeria`, `tipo_frase`, `escenario`.
- **`$origin`** (NCRE/NDEB): `['auth_number' => ..., 'date' => 'YYYY-MM-DD', 'series' => ..., 'number' => ...]`.

## Establecimiento (sucursal)

Cada NIT puede tener varios establecimientos registrados en el RTU. Configúralos al crear el cliente:

```php
$client = new DigifactClient([
    'taxid'       => '12345678',
    'username'    => 'FELUSER',
    'password'    => 'secret',
    'branch_code' => '2',
    'branch_name' => 'SUCURSAL ZONA 10',
]);
```

Aplican a todos los DTE emitidos por ese cliente. Si no los especificas, se usan los defaults `"1"` / `"ESTABLECIMIENTO PRINCIPAL"`.

## Manejo de errores

```php
use Digifact\Fel\{
    DigifactException,            // base
    DigifactAuthException,        // fallo de autenticación
    DigifactApiException,         // error HTTP / de API
    DigifactValidationException,  // rechazo de SAT
    DigifactNitNotFoundException, // NIT no encontrado
};

try {
    $result = $client->invoice('CF', $items);
} catch (DigifactValidationException $e) {
    echo "SAT rechazó (code={$e->getCode()}): {$e->getMessage()}\n";
    print_r($e->raw);
} catch (DigifactException $e) {
    echo "Error del SDK: {$e->getMessage()}\n";
}
```

## Ejecutar las pruebas

```bash
# Pruebas unitarias (sin credenciales)
./vendor/bin/phpunit tests/

# Pruebas de integración
export DIGIFACT_TAXID=12345678
export DIGIFACT_USERNAME=FELUSER
export DIGIFACT_PASSWORD=tu_contraseña
./vendor/bin/phpunit tests/
```
