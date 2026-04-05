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

Console.WriteLine("\nAll examples completed successfully.");
