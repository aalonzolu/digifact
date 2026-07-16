/**
 * Basic usage example for the Digifact FEL C# SDK.
 *
 * Set environment variables before running:
 *   export DIGIFACT_TAXID=12345678
 *   export DIGIFACT_USERNAME=FELUSER
 *   export DIGIFACT_PASSWORD=your_password
 *
 * Then:
 *   dotnet run --project examples/Basic
 */

using Digifact.Fel;

var taxid    = Environment.GetEnvironmentVariable("DIGIFACT_TAXID")    ?? "";
var username = Environment.GetEnvironmentVariable("DIGIFACT_USERNAME") ?? "";
var password = Environment.GetEnvironmentVariable("DIGIFACT_PASSWORD") ?? "";

if (string.IsNullOrEmpty(taxid) || string.IsNullOrEmpty(username) || string.IsNullOrEmpty(password))
{
    Console.WriteLine("Skipping example: set DIGIFACT_TAXID, DIGIFACT_USERNAME, DIGIFACT_PASSWORD");
    return;
}

using var client = new DigifactClient(new DigifactOptions
{
    Taxid       = taxid,
    Username    = username,
    Password    = password,
    Environment = "test",
    // PETROLEO rates (Q/gallon) — auto-filled in FuelInvoiceAsync when PetroleoCode is set
    PetroleoRates = new Dictionary<string, decimal>
    {
        ["1"] = 4.70m,  // SUPER
        ["2"] = 4.60m,  // REGULAR
        ["4"] = 1.30m,  // DIESEL
    },
});

// ── FACT CF ──────────────────────────────────────────────────────────────────
Console.WriteLine("Emitting FACT CF...");
try
{
    var result = await client.InvoiceAsync("CF", new[]
    {
        new LineItem { Description = "Consultoría C# SDK", Qty = 1, Price = 100.00m },
    });
    Console.WriteLine($"  auth_number  : {result.AuthNumber}");
    Console.WriteLine($"  series       : {result.Series}");
    Console.WriteLine($"  number       : {result.Number}");
    Console.WriteLine($"  issued_at    : {result.IssueDateTime}");
}
catch (DigifactException ex)
{
    Console.Error.WriteLine($"  ERROR: {ex.Message}");
    Environment.Exit(1);
}

// ── FACT NIT ─────────────────────────────────────────────────────────────────
Console.WriteLine("\nEmitting FACT to NIT 77454820...");
try
{
    var result2 = await client.InvoiceAsync("77454820", new[]
    {
        new LineItem { Description = "Laptop",       Qty = 1, Price = 5000.00m, Type = "Bien" },
        new LineItem { Description = "Soporte anual", Qty = 1, Price = 500.00m },
    });
    Console.WriteLine($"  auth_number  : {result2.AuthNumber}");
}
catch (DigifactException ex)
{
    Console.Error.WriteLine($"  ERROR: {ex.Message}");
}

// ── NIT Lookup ────────────────────────────────────────────────────────────────
Console.WriteLine("\nLooking up NIT 77454820...");
try
{
    var info = await client.LookupNitAsync("77454820");
    Console.WriteLine($"  name    : {info.Name}");
    Console.WriteLine($"  address : {info.Address}");
}
catch (DigifactException ex)
{
    Console.Error.WriteLine($"  ERROR: {ex.Message}");
}

// ── CUI Lookup ────────────────────────────────────────────────────────────────
Console.WriteLine("\nLooking up CUI 1234567890123...");
try
{
    var cuiInfo = await client.LookupCuiAsync("1234567890123");
    Console.WriteLine($"  name   : {cuiInfo.Name}");
    Console.WriteLine($"  status : {cuiInfo.Status}");
}
catch (DigifactException ex)
{
    Console.Error.WriteLine($"  ERROR: {ex.Message}");
}

// ── FACT to a CUI (DPI) buyer — name resolved automatically ───────────────────
Console.WriteLine("\nEmitting FACT to a CUI buyer...");
try
{
    var cuiResult = await client.InvoiceAsync(
        BuyerDetails.FromCui("1234567890123"),
        new[] { new LineItem { Description = "Producto", Qty = 1, Price = 75.00m, Type = "Bien" } }
    );
    Console.WriteLine($"  auth: {cuiResult.AuthNumber}");
}
catch (DigifactException ex)
{
    Console.Error.WriteLine($"  ERROR: {ex.Message}");
}

// ── FACT Combustible ──────────────────────────────────────────────────────────
// For gas stations, set PetroleoRates at init so you don't repeat PetroleoAmount on each item.
Console.WriteLine("\nEmitting FACT Combustible...");
try
{
    var resultFuel = await client.FuelInvoiceAsync(
        "CF",
        new[]
        {
            // Only PetroleoCode needed when PetroleoRates was set in DigifactOptions.
            // PetroleoCode: "1"=SUPER, "2"=REGULAR, "4"=DIESEL
            new FuelLineItem { Description = "GASOLINA SUPER",    Qty = 1m, Price = 35.00m, PetroleoCode = "1" },
            new FuelLineItem { Description = "GASOLINA REGULAR",  Qty = 1m, Price = 34.00m, PetroleoCode = "2" },
            new FuelLineItem { Description = "GASOLINA DIESEL",   Qty = 1m, Price = 32.00m, PetroleoCode = "4" },
            // Regular items: PetroleoAmount = 0 (IVA only)
            new FuelLineItem { Description = "FILTRO DE ACEITE",    Qty = 1m, Price = 45.00m },
            new FuelLineItem { Description = "SET DE CANDELAS NGK", Qty = 1m, Price = 400.00m },
        });
    Console.WriteLine($"  auth_number  : {resultFuel.AuthNumber}");
    Console.WriteLine($"  series       : {resultFuel.Series}");
    Console.WriteLine($"  number       : {resultFuel.Number}");
}
catch (DigifactException ex)
{
    Console.Error.WriteLine($"  ERROR: {ex.Message}");
}

Console.WriteLine("\nAll examples completed successfully.");
