# Digifact FEL Guatemala — C# / .NET SDK

C# SDK for the [Digifact](https://www.digifact.com.gt/) FEL Guatemala e-invoicing API.

## Requirements

- .NET 8+
- No external dependencies (uses `System.Net.Http` and `System.Text.Json`)

## Installation

```bash
dotnet add package Digifact.Fel
```

## Quick start

```csharp
using Digifact.Fel;

using var client = new DigifactClient(new DigifactOptions
{
    Taxid       = "12345678",
    Username    = "FELUSER",
    Password    = "secret",
    Environment = "test",  // or "production"
});

// FACT CF
var result = await client.InvoiceAsync("CF", new[]
{
    new LineItem { Description = "Consultoría", Qty = 1, Price = 100.00m },
});
Console.WriteLine(result.AuthNumber);

// FACT to NIT (buyer name fetched automatically)
var result2 = await client.InvoiceAsync("12345678", new[]
{
    new LineItem { Description = "Laptop",   Qty = 1, Price = 5000.00m, Type = "Bien" },
    new LineItem { Description = "Soporte",  Qty = 1, Price = 500.00m },
});

// FACT to CUI buyer
var result3 = await client.InvoiceAsync(
    BuyerDetails.FromCui("3730617490101", "Juan Pérez"),
    new[] { new LineItem { Description = "Producto", Qty = 2, Price = 50.00m } }
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

// Credit note (NCRE)
var result5 = await client.CreditNoteAsync("12345678", new[]
{
    new LineItem { Description = "Devolución", Qty = 1, Price = 100.00m },
}, new OriginDoc(
    AuthNumber: "XXXXXXXX-...",
    Date:       "2026-03-18",
    Series:     "XXXXXXXX",
    Number:     "123456"
), reason: "Producto defectuoso");

// Debit note (NDEB)
var result6 = await client.DebitNoteAsync("12345678", items, origin, "Cargo extra");

// Cancel
var cancel = await client.CancelAsync("XXXXXXXX-...", "CF", "2026-03-18 21:40:14", "Error en monto");

// NIT lookup
var info = await client.LookupNitAsync("12345678");
Console.WriteLine(info.Name);

// Get DTE
var doc = await client.GetDteAsync("XXXXXXXX-...");

// FACT Combustible — rates set once at init (recommended for gas stations)
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
// PetroleoAmount filled in automatically from PetroleoRates
var fuel = await stationClient.FuelInvoiceAsync(
    new BuyerDetails("CF"),
    new[]
    {
        new FuelLineItem { Description = "GASOLINA SUPER",    Qty = 30m, Price = 30.30m, PetroleoCode = "1" },
        new FuelLineItem { Description = "GASOLINA REGULAR",  Qty = 20m, Price = 29.40m, PetroleoCode = "2" },
        new FuelLineItem { Description = "GASOLINA DIESEL",   Qty = 50m, Price = 30.70m, PetroleoCode = "4" },
        // Regular items: PetroleoAmount = 0 (IVA only)
        new FuelLineItem { Description = "FILTRO DE ACEITE",    Qty = 1m, Price = 45.00m },
        new FuelLineItem { Description = "SET DE CANDELAS NGK", Qty = 1m, Price = 400.00m },
    });
Console.WriteLine(fuel.AuthNumber);

// Alternative: explicit PetroleoAmount per item (no PetroleoRates needed)
var fuel2 = await client.FuelInvoiceAsync(
    new BuyerDetails("CF"),
    new[] { new FuelLineItem { Description = "GASOLINA SUPER", Qty = 1m, Price = 30.30m, PetroleoAmount = 4.70m, PetroleoCode = "1" } });
```

## FuelLineItem properties

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `Description` | `string` | required | Line description |
| `Price` | `decimal` | required | Unit price, IVA-inclusive |
| `Qty` | `decimal` | `1` | Quantity |
| `Type` | `string` | `"Bien"` | `"Bien"` or `"Servicio"` |
| `UnitOfMeasure` | `string` | `"UNI"` | SAT unit code |
| `PetroleoAmount` | `decimal` | `0` | Per-unit PETROLEO tax; `0` = IVA-only item |
| `PetroleoCode` | `string` | `""` | `"1"`=SUPER, `"2"`=REGULAR, `"4"`=DIESEL. Leave empty for non-fuel items. When non-empty and `PetroleoAmount` is 0, the code must resolve to a rate in `PetroleoRates` or a `DigifactValidationException` is thrown. |

## Configuration

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `Taxid` | `string` | required | Issuer NIT |
| `Username` | `string` | required | Digifact username |
| `Password` | `string` | `""` | Password (required if no Token) |
| `Token` | `string` | `""` | Pre-obtained Bearer token (skips login) |
| `Environment` | `string` | `"test"` | `"test"` or `"production"` |
| `SellerName` | `string` | `""` | Issuer name (auto-fetched via NIT lookup if blank) |
| `SellerAddress` | `string` | `""` | Issuer address (auto-fetched if blank) |
| `AfiliacionIva` | `string` | `"GEN"` | `"GEN"`, `"PEQ"`, or `"EXE"` |
| `TipoPersoneria` | `string` | `"1"` | SAT RTU personería code |
| `Timeout` | `TimeSpan` | 120s | HTTP request timeout |
| `PetroleoRates` | `IDictionary<string,decimal>?` | `null` | PETROLEO code→amount map for `FuelInvoiceAsync` auto-fill |

## Running tests

```bash
# Unit tests (no credentials)
dotnet test tests/

# Integration tests (example)
export DIGIFACT_TAXID=12345678
export DIGIFACT_USERNAME=FELUSER
export DIGIFACT_PASSWORD=your_password
dotnet run --project examples/Basic
```
