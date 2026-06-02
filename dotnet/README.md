# Digifact FEL Guatemala — SDK para C# / .NET

SDK en C# para la API de facturación electrónica en línea (FEL) de Guatemala de [Digifact](https://www.digifact.com.gt/).

## Requisitos

- .NET 8+
- Sin dependencias externas (usa `System.Net.Http` y `System.Text.Json`)

## Instalación

```bash
dotnet add package Digifact.Fel
```

## Inicio rápido

```csharp
using Digifact.Fel;

using var client = new DigifactClient(new DigifactOptions
{
    Taxid       = "12345678",
    Username    = "FELUSER",
    Password    = "secret",
    Environment = "test",  // o "production"
});

// FACT CF
var result = await client.InvoiceAsync("CF", new[]
{
    new LineItem { Description = "Consultoría", Qty = 1, Price = 100.00m },
});
Console.WriteLine(result.AuthNumber);

// FACT a NIT (el nombre del receptor se consulta automáticamente)
var result2 = await client.InvoiceAsync("12345678", new[]
{
    new LineItem { Description = "Laptop",   Qty = 1, Price = 5000.00m, Type = "Bien" },
    new LineItem { Description = "Soporte",  Qty = 1, Price = 500.00m },
});

// FACT a receptor con CUI
var result3 = await client.InvoiceAsync(
    BuyerDetails.FromCui("3730617490101", "Juan Pérez"),
    new[] { new LineItem { Description = "Producto", Qty = 2, Price = 50.00m } }
);

// FACT a NIT con datos completos del receptor (sin consulta automática)
var result3b = await client.InvoiceAsync(
    BuyerDetails.FromNit(
        nit:     "12345678",
        name:    "EMPRESA EJEMPLO S.A.",
        address: "6 AV 6-48 ZONA 9",
        city:    "01009",
        email:   "facturacion@empresa.com"  // opcional
    ),
    new[] { new LineItem { Description = "Producto", Qty = 1, Price = 100.00m } }
);

// FCAM (Factura Cambiaria)
var result4 = await client.InvoiceAsync("12345678", new[]
{
    new LineItem { Description = "Servicio", Qty = 1, Price = 500.00m },
}, new InvoiceOptions
{
    DocType      = "FCAM",
    PaymentTerms = new[] { new PaymentTerm("2026-04-18", 500.00m) },
});

// Nota de crédito (NCRE)
var result5 = await client.CreditNoteAsync("12345678", new[]
{
    new LineItem { Description = "Devolución", Qty = 1, Price = 100.00m },
}, new OriginDoc(
    AuthNumber: "XXXXXXXX-...",
    Date:       "2026-03-18",
    Series:     "XXXXXXXX",
    Number:     "123456"
), reason: "Producto defectuoso");

// Nota de débito (NDEB)
var result6 = await client.DebitNoteAsync("12345678", items, origin, "Cargo extra");

// Anulación
var cancel = await client.CancelAsync("XXXXXXXX-...", "CF", "2026-03-18 21:40:14", "Error en monto");

// Consulta de NIT
var info = await client.LookupNitAsync("12345678");
Console.WriteLine(info.Name);

// Obtener DTE
var doc = await client.GetDteAsync("XXXXXXXX-...");

// FACT Combustible — tarifas fijadas al inicializar (recomendado para gasolineras)
// PetroleoCode: "1"=SUPER, "2"=REGULAR, "4"=DIESEL
var stationClient = new DigifactClient(new DigifactOptions
{
    Taxid = "12345678", Username = "FELUSER", Password = "secret",
    PetroleoRates = new Dictionary<string, decimal>
    {
        ["1"] = 4.70m,  // SUPER
        ["2"] = 4.60m,  // REGULAR
        ["4"] = 1.30m,  // DIESEL
    },
});
// PetroleoAmount se completa automáticamente a partir de PetroleoRates
var fuel = await stationClient.FuelInvoiceAsync(
    "CF",
    new[]
    {
        new FuelLineItem { Description = "GASOLINA SUPER",    Qty = 30m, Price = 35.00m, PetroleoCode = "1" },
        new FuelLineItem { Description = "GASOLINA REGULAR",  Qty = 20m, Price = 34.00m, PetroleoCode = "2" },
        new FuelLineItem { Description = "GASOLINA DIESEL",   Qty = 50m, Price = 32.00m, PetroleoCode = "4" },
        // Ítems regulares: PetroleoAmount = 0 (sólo IVA)
        new FuelLineItem { Description = "FILTRO DE ACEITE",    Qty = 1m, Price = 45.00m },
        new FuelLineItem { Description = "SET DE CANDELAS NGK", Qty = 1m, Price = 400.00m },
    });
Console.WriteLine(fuel.AuthNumber);

// Alternativa: PetroleoAmount explícito por ítem (no se necesita PetroleoRates)
var fuel2 = await client.FuelInvoiceAsync(
    "CF",
    new[] { new FuelLineItem { Description = "GASOLINA SUPER", Qty = 1m, Price = 35.00m, PetroleoAmount = 4.70m, PetroleoCode = "1" } });
```

## Propiedades de FuelLineItem

| Propiedad | Tipo | Por defecto | Descripción |
|----------|------|---------|-------------|
| `Description` | `string` | requerido | Descripción de la línea |
| `Price` | `decimal` | requerido | Precio unitario completo al consumidor (incluye PETROLEO + IVA). Es lo que paga el cliente en la bomba. Si la factura del proveedor muestra un precio unitario *sin* PETROLEO/IDP (p. ej. `37.99m`), suma la tarifa IDP por unidad: `Price = 37.99m + 4.70m = 42.69m`. |
| `Qty` | `decimal` | `1` | Cantidad |
| `Type` | `string` | `"Bien"` | `"Bien"` o `"Servicio"` |
| `UnitOfMeasure` | `string` | `"UNI"` | Código de unidad de SAT |
| `PetroleoAmount` | `decimal` | `0` | Impuesto PETROLEO por unidad; `0` = ítem sólo-IVA |
| `PetroleoCode` | `string` | `""` | `"1"`=SUPER, `"2"`=REGULAR, `"4"`=DIESEL. Dejar vacío para ítems no-combustible. Si no está vacío y `PetroleoAmount` es 0, el código debe estar en `PetroleoRates` o se lanza `DigifactValidationException`. |

## Configuración

Ordenadas de más usadas a menos usadas.

| Opción | Tipo | Por defecto | Descripción |
|--------|------|---------|-------------|
| `Taxid` | `string` | requerido | NIT del emisor |
| `Username` | `string` | requerido | Usuario de Digifact |
| `Password` | `string` | `""` | Contraseña (requerida si no hay `Token`) |
| `Token` | `string` | `""` | Bearer token preobtenido (omite el login) |
| `Environment` | `string` | `"test"` | `"test"` o `"production"` |
| `SellerName` | `string` | `""` | Nombre del emisor. Para NIT individual es el nombre de la persona; para S.A. / S.E. es la razón social de la entidad. Se consulta vía NIT si está vacío. |
| `SellerAddress` | `string` | `""` | Dirección del emisor (se consulta si está vacía) |
| `BranchCode` | `string` | `"1"` | **Código del establecimiento** del RTU. Se escribe en `Seller.BranchInfo.Code`. |
| `BranchName` | `string` | `"ESTABLECIMIENTO PRINCIPAL"` | **Nombre comercial** de la sucursal — el mismo que aparece en la patente de comercio. Se escribe en `Seller.BranchInfo.Name`. |
| `AfiliacionIva` | `string` | `"GEN"` | `"GEN"`, `"PEQ"` o `"EXE"` |
| `TipoFrase` | `string?` | `null` | Sobreescritura global de `TipoFrase` (legacy). **Mutuamente exclusivo con `Frases`**. Ver abajo. |
| `Escenario` | `string?` | `null` | Sobreescritura global de `CodigoEscenario` (legacy). **Mutuamente exclusivo con `Frases`**. Ver abajo. |
| `Frases` | `IReadOnlyList<FraseItem>?` | `null` | Lista de frases `new FraseItem(TipoFrase, Escenario)`. Reemplaza a `TipoFrase`/`Escenario`. **Mutuamente exclusivo** con ellos. Ver abajo. |
| `AutoFuelSubsidyFrases` | `bool?` | `null` | Controla la auto-inyección de frases 9/18 y 9/19 en combustible durante el subsidio. `null` = usar default (`true`). Ver [Subsidio combustible](#subsidio-combustible-frases-automáticas). |
| `PetroleoRates` | `IDictionary<string,decimal>?` | `null` | Mapa código PETROLEO→monto para autocompletado en `FuelInvoiceAsync` (sólo gasolineras) |
| `Timeout` | `TimeSpan` | 120s | Timeout de la solicitud HTTP |
| `TipoPersoneria` | `string` | `"1"` | Código de personería del RTU de SAT. **Sólo aplica a RDON** (Recibo por Donación); ignóralo en los demás documentos. |

## Configuración de frases (TipoFrase / CodigoEscenario)

Todo DTE (excepto FESP) debe llevar un par `TipoFrase` + `CodigoEscenario`. El
SDK elige valores por defecto adecuados, por lo que **no** hace falta
configurar nada en el caso común.

**Orden de precedencia:** sobreescritura por llamada → globales de `DigifactOptions` → tabla de valores por defecto.

**Tabla de valores por defecto** (expuesta como `DteBuilder.DefaultFrase`):

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

### Nueva API: `Frases` (múltiples frases)

Usa `Frases` cuando necesitas enviar **más de un par**. Es **mutuamente exclusivo** con `TipoFrase`/`Escenario`.

```csharp
// Lista explícita por llamada (deshabilita auto-inyección)
await client.FuelInvoiceAsync(buyer, items, frases: new[] {
    new FraseItem("1", "1"),
});

// Globalmente en DigifactOptions
var client = new DigifactClient(new DigifactOptions {
    Taxid = "12345678", Username = "FELUSER", Password = "...",
    Frases = new[] { new FraseItem("1", "2") },  // ISR régimen opcional
});
```

### Subsidio combustible — frases automáticas

Durante el **periodo de subsidio de combustibles** (2026-04-27 (incl.) a 2026-07-27 (excl.)), el SDK agrega automáticamente `TipoFrase=9, Escenario=18` y `TipoFrase=9, Escenario=19` en facturas de combustible.

```csharp
// Sin cambios — el SDK agrega 9/18 y 9/19 automáticamente durante el subsidio
var result = await client.FuelInvoiceAsync(buyer, items);

// Deshabilitarlo por llamada
var result = await client.FuelInvoiceAsync(buyer, items, autoFuelSubsidyFrases: false);

// Deshabilitarlo globalmente
var client = new DigifactClient(new DigifactOptions { ..., AutoFuelSubsidyFrases = false });

// Variable de entorno (sin tocar código): DIGIFACT_DISABLE_AUTO_FUEL_SUBSIDY_FRASES=1
```

> **Nota:** `Frases` y `TipoFrase`/`Escenario` son **mutuamente exclusivos** — combinarlos lanza `DigifactValidationException`.

### API legacy: `TipoFrase` / `Escenario`

Siguen funcionando exactamente como antes para integraciones existentes.

```csharp
// Sobreescritura por llamada (uno o ambos)
await client.InvoiceAsync(buyer, items, new InvoiceOptions { Escenario = "1" });
await client.InvoiceAsync(buyer, items, new InvoiceOptions {
    TipoFrase = "2", Escenario = "1",
});

// Funciona igual en los demás métodos de DTE
await client.CreditNoteAsync(buyer, items, origin, "...", tipoFrase: "2", escenario: "1");
await client.FuelInvoiceAsync(buyer, items, tipoFrase: "2", escenario: "1");

// O globalmente al construir el cliente (p. ej. GEN + ISR régimen opcional simplificado)
var client = new DigifactClient(new DigifactOptions {
    Taxid = "12345678", Username = "FELUSER", Password = "...",
    AfiliacionIva = "GEN",
    TipoFrase = "1", // opcional — la tabla ya devuelve "1" para GEN
    Escenario = "2", // ISR régimen opcional simplificado (sobreescribe el "1" por defecto)
});
```

## Referencia de métodos

Todos los métodos de emisión son `async`. Devuelven `DteResult` con `.AuthNumber`, `.Series`, `.Number`, `.IssueDateTime`, `.Raw`.

| Método | Firma | Descripción |
|--------|-------|-------------|
| `InvoiceAsync()` | `InvoiceAsync(BuyerDetails buyer, IEnumerable<LineItem> items, InvoiceOptions? opts = null, CancellationToken ct = default)` | Emite FACT, FCAM, FESP, FPEQ, NABN, RDON o RECI según `opts.DocType`. |
| `CcaInvoiceAsync()` | `CcaInvoiceAsync(BuyerDetails buyer, IEnumerable<LineItem> items, IEnumerable<CcaCobro> cobros, string? tipoFrase = null, string? escenario = null, ...)` | FACT con complemento CCA. |
| `FuelInvoiceAsync()` | `FuelInvoiceAsync(BuyerDetails buyer, IEnumerable<FuelLineItem> items, string? tipoFrase = null, string? escenario = null, IReadOnlyList<FraseItem>? frases = null, bool? autoFuelSubsidyFrases = null, ...)` | FACT con complemento combustible. Auto-inyecta 9/18 y 9/19 durante el subsidio. |
| `CreditNoteAsync()` | `CreditNoteAsync(BuyerDetails buyer, IEnumerable<LineItem> items, OriginDoc origin, string reason, string? tipoFrase = null, string? escenario = null, ...)` | Nota de crédito (NCRE). |
| `DebitNoteAsync()` | `DebitNoteAsync(BuyerDetails buyer, IEnumerable<LineItem> items, OriginDoc origin, string reason, string? tipoFrase = null, string? escenario = null, ...)` | Nota de débito (NDEB). |
| `CreditNoteTotalAsync()` | `CreditNoteTotalAsync(string authNumber, string issueDateTime, string reason = "...", string reference = "", ...)` | Nota de crédito total. Devuelve `JsonElement`. |
| `CancelAsync()` | `CancelAsync(string authNumber, string receiverId, string issueDateTime, string reason = "Anulación", ...)` | Anula un DTE. Devuelve `JsonElement`. |
| `LookupNitAsync()` | `LookupNitAsync(string nit, CancellationToken ct = default)` | Consulta SAT. Devuelve `NitInfo`. |
| `GetDteAsync()` | `GetDteAsync(string authNumber, string format = "JSON", ...)` | Recupera el DTE. |
| `GetDteInfoAsync()` | `GetDteInfoAsync(string authNumber, ...)` | Metadatos del DTE. |

### Parámetros comunes

- **`BuyerDetails`**: construye con `BuyerDetails.Cf()`, `BuyerDetails.FromNit(nit, name, address, city, ...)`, o `BuyerDetails.FromCui(cui, name)`. Si pasas sólo `"CF"` o un string de NIT a `InvoiceAsync`, hay sobrecargas para evitar construir el objeto manualmente.
- **`LineItem`**: `Description` (req), `Price` (req), `Qty` (1), `Type` (`"Servicio"`/`"Bien"`), `UnitOfMeasure` (`"UNI"`), `Discount` (opcional).
- **`InvoiceOptions`**: `DocType`, `PaymentTerms` (req. para FCAM), `AmountStr`, `Observaciones`, `TipoPersoneria`, `TipoFrase`, `Escenario`.
- **`OriginDoc`** (NCRE/NDEB): `record (string AuthNumber, string Date, string Series, string Number)`.

## Establecimiento (sucursal)

Cada NIT puede tener varios establecimientos registrados en el RTU. Configúralos en `DigifactOptions`:

```csharp
using var client = new DigifactClient(new DigifactOptions
{
    Taxid      = "12345678",
    Username   = "FELUSER",
    Password   = "secret",
    BranchCode = "2",
    BranchName = "SUCURSAL ZONA 10",
});
```

Aplican a todos los DTE emitidos por ese cliente. Si se omiten, se usan los defaults `"1"` / `"ESTABLECIMIENTO PRINCIPAL"`.

## Manejo de errores

```csharp
using Digifact.Fel;

try
{
    var result = await client.InvoiceAsync("CF", items);
}
catch (DigifactValidationException ex)   // rechazo de SAT
{
    Console.WriteLine($"SAT rechazó (code={ex.Code}): {ex.Message}");
    Console.WriteLine(ex.Raw);
}
catch (DigifactAuthException ex)          // fallo de autenticación
{
    Console.WriteLine($"Auth: {ex.Message}");
}
catch (DigifactNitNotFoundException)      // NIT no encontrado
{
    // ...
}
catch (DigifactApiException ex)           // error HTTP / de API
{
    Console.WriteLine($"API: {ex.Message}");
}
catch (DigifactException ex)              // base
{
    Console.WriteLine($"Error del SDK: {ex.Message}");
}
```

## Ejecutar las pruebas

```bash
# Pruebas unitarias (sin credenciales)
dotnet test tests/

# Pruebas de integración (ejemplo)
export DIGIFACT_TAXID=12345678
export DIGIFACT_USERNAME=FELUSER
export DIGIFACT_PASSWORD=tu_contraseña
dotnet run --project examples/Basic
```
