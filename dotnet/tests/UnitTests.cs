using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Text.Json;
using Digifact.Fel;
using Xunit;

namespace Digifact.Fel.Tests;

/// <summary>Unit tests — no credentials required.</summary>
public class UnitTests
{
    // ── TaxHelper.PadTaxid ────────────────────────────────────────────────────

    [Theory]
    [InlineData("12345678",       "000012345678")]
    [InlineData("000012345678",   "000012345678")]
    [InlineData("GT.000012345678","000012345678")]
    public void PadTaxid_ReturnsExpected(string input, string expected) =>
        Assert.Equal(expected, TaxHelper.PadTaxid(input));

    // ── TaxHelper.CalcIva ─────────────────────────────────────────────────────

    [Fact]
    public void CalcIva_ExactAmount()
    {
        var (taxable, iva) = TaxHelper.CalcIva(112m);
        Assert.Equal("100.000000", taxable);
        Assert.Equal("12.000000", iva);
    }

    [Fact]
    public void CalcIva_SumsToLineTotal()
    {
        var (taxable, iva) = TaxHelper.CalcIva(100m);
        decimal sum = decimal.Parse(taxable, CultureInfo.InvariantCulture)
                    + decimal.Parse(iva, CultureInfo.InvariantCulture);
        Assert.Equal(100m, sum);
    }

    // ── TaxHelper.Fmt ─────────────────────────────────────────────────────────

    [Theory]
    [InlineData(1.0,           6, "1.000000")]
    [InlineData(100.1234567,   6, "100.123457")]
    [InlineData(0.0,           6, "0.000000")]
    [InlineData(50.0,          2, "50.00")]
    public void Fmt_ReturnsExpected(double value, int decimals, string expected) =>
        Assert.Equal(expected, TaxHelper.Fmt((decimal)value, decimals));

    // ── TaxHelper.CalcLine ────────────────────────────────────────────────────

    [Fact]
    public void CalcLine_Taxable_CorrectTotals()
    {
        var calc = TaxHelper.CalcLine(qty: 2m, price: 100m, taxable: true, discount: 0m);
        Assert.Equal("2.000000", calc.Qty);
        Assert.Equal("100.000000", calc.Price);
        Assert.Equal("200.000000", calc.LineTotal);
        // taxable + iva should equal lineTotal
        decimal taxable = decimal.Parse(calc.Taxable, CultureInfo.InvariantCulture);
        decimal iva = decimal.Parse(calc.Iva, CultureInfo.InvariantCulture);
        Assert.Equal(200m, taxable + iva);
    }

    [Fact]
    public void CalcLine_NotTaxable_ZeroIva()
    {
        var calc = TaxHelper.CalcLine(qty: 1m, price: 50m, taxable: false, discount: 0m);
        Assert.Equal("0.000000", calc.Iva);
        Assert.Equal("0.000000", calc.Taxable);
        Assert.Equal("50.000000", calc.LineTotal);
    }

    [Fact]
    public void CalcLine_WithDiscount()
    {
        var calc = TaxHelper.CalcLine(qty: 1m, price: 100m, taxable: true, discount: 10m);
        Assert.Equal("90.000000", calc.LineTotal);
        Assert.Equal("10.00", calc.Discount);
    }

    // ── TaxHelper.CalcFuelLine ────────────────────────────────────────────────

    [Fact]
    public void CalcFuelLine_CorrectValues()
    {
        var c = TaxHelper.CalcFuelLine(qty: 1m, price: 35.00m, petrolPerUnit: 4.70m);
        Assert.Equal("1.000000",  c.Qty);
        Assert.Equal("35.000000", c.Price);
        Assert.Equal("35.000000", c.LineTotal);
        Assert.Equal("27.053571", c.Taxable);
        Assert.Equal("3.246429",  c.Iva);
        Assert.Equal("4.700000",  c.Petrol);
    }

    [Fact]
    public void CalcFuelLine_TaxablePlusIvaEqualsGross()
    {
        var c = TaxHelper.CalcFuelLine(qty: 2m, price: 53m, petrolPerUnit: 3m);
        decimal taxable = decimal.Parse(c.Taxable, CultureInfo.InvariantCulture);
        decimal iva     = decimal.Parse(c.Iva,     CultureInfo.InvariantCulture);
        // taxable + iva must equal qty × net (= 100.00)
        Assert.Equal(100m, taxable + iva);
        // lineTotal = qty × price = 2 × 53.00
        Assert.Equal("106.000000", c.LineTotal);
    }

    // ── PetroleoRates auto-fill ───────────────────────────────────────────────

    [Fact]
    public void PetroleoRates_FillsAmountWhenCodeSetButAmountIsZero()
    {
        var opts = new DigifactOptions
        {
            Taxid = "12345678", Username = "U", Password = "P",
            PetroleoRates = new Dictionary<string, decimal> { ["1"] = 4.70m, ["4"] = 1.30m },
        };
        using var client = new DigifactClient(opts, new HttpClient());
        var items = new[]
        {
            new FuelLineItem { Description = "SUPER",  Price = 30.30m, PetroleoCode = "1" },
            new FuelLineItem { Description = "DIESEL", Price = 30.70m, PetroleoCode = "4" },
            new FuelLineItem { Description = "FILTRO", Price = 45.00m },  // no code, amount stays 0
        };
        // Access via reflection since ApplyPetroleoRates is private
        var method = typeof(DigifactClient).GetMethod("ApplyPetroleoRates",
            System.Reflection.BindingFlags.NonPublic | System.Reflection.BindingFlags.Instance)!;
        var resolved = (System.Collections.Generic.IReadOnlyList<FuelLineItem>)method.Invoke(client, new object[] { items })!;
        Assert.Equal(4.70m, resolved[0].PetroleoAmount);
        Assert.Equal(1.30m, resolved[1].PetroleoAmount);
        Assert.Equal(0m,    resolved[2].PetroleoAmount);
    }

    [Fact]
    public void PetroleoRates_ExplicitAmountNotOverwritten()
    {
        var opts = new DigifactOptions
        {
            Taxid = "12345678", Username = "U", Password = "P",
            PetroleoRates = new Dictionary<string, decimal> { ["1"] = 4.70m },
        };
        using var client = new DigifactClient(opts, new HttpClient());
        var items = new[]
        {
            new FuelLineItem { Description = "SUPER", Price = 30.30m, PetroleoCode = "1", PetroleoAmount = 9.99m },
        };
        var method = typeof(DigifactClient).GetMethod("ApplyPetroleoRates",
            System.Reflection.BindingFlags.NonPublic | System.Reflection.BindingFlags.Instance)!;
        var resolved = (System.Collections.Generic.IReadOnlyList<FuelLineItem>)method.Invoke(client, new object[] { items })!;
        Assert.Equal(9.99m, resolved[0].PetroleoAmount);
    }

    [Fact]
    public void PetroleoRates_MissingRatesThrows()
    {
        var opts = new DigifactOptions
        {
            Taxid = "12345678", Username = "U", Password = "P",
            // no PetroleoRates configured
        };
        using var client = new DigifactClient(opts, new HttpClient());
        var items = new[] { new FuelLineItem { Description = "SUPER", Price = 30.30m, PetroleoCode = "1" } };
        var method = typeof(DigifactClient).GetMethod("ApplyPetroleoRates",
            System.Reflection.BindingFlags.NonPublic | System.Reflection.BindingFlags.Instance)!;
        var ex = Assert.Throws<System.Reflection.TargetInvocationException>(
            () => method.Invoke(client, new object[] { items }));
        Assert.IsType<DigifactValidationException>(ex.InnerException);
    }

    [Fact]
    public void PetroleoRates_CodeNotInRatesThrows()
    {
        var opts = new DigifactOptions
        {
            Taxid = "12345678", Username = "U", Password = "P",
            PetroleoRates = new Dictionary<string, decimal> { ["1"] = 4.70m }, // DIESEL not configured
        };
        using var client = new DigifactClient(opts, new HttpClient());
        var items = new[] { new FuelLineItem { Description = "DIESEL", Price = 30.70m, PetroleoCode = "4" } };
        var method = typeof(DigifactClient).GetMethod("ApplyPetroleoRates",
            System.Reflection.BindingFlags.NonPublic | System.Reflection.BindingFlags.Instance)!;
        var ex = Assert.Throws<System.Reflection.TargetInvocationException>(
            () => method.Invoke(client, new object[] { items }));
        Assert.IsType<DigifactValidationException>(ex.InnerException);
    }



    [Fact]
    public void DteResult_ParsesCamelCaseResponse()
    {
        var json = """{"authNumber":"UUID-1","batch":"A","serial":"123","issuedTimeStamp":"2024-01-01T10:00:00-06:00"}""";
        var el = JsonDocument.Parse(json).RootElement;
        var result = DteResult.FromJson(el);
        Assert.Equal("UUID-1", result.AuthNumber);
        Assert.Equal("A", result.Series);
        Assert.Equal("123", result.Number);
        Assert.Equal("2024-01-01 10:00:00-06:00", result.IssueDateTime);
    }

    [Fact]
    public void DteResult_ParsesPascalCaseResponse()
    {
        var json = """{"Autorizacion":"UUID-2","Serie":"B","Numero":"456","FechaEmision":"2024-06-01 08:30:00"}""";
        var el = JsonDocument.Parse(json).RootElement;
        var result = DteResult.FromJson(el);
        Assert.Equal("UUID-2", result.AuthNumber);
        Assert.Equal("B", result.Series);
        Assert.Equal("456", result.Number);
        Assert.Equal("2024-06-01 08:30:00", result.IssueDateTime);
    }

    // ── BuyerDetails ──────────────────────────────────────────────────────────

    [Fact]
    public void BuyerDetails_CfString()
    {
        BuyerDetails buyer = "CF";
        Assert.True(buyer.IsConsumidorFinal);
    }

    [Fact]
    public void BuyerDetails_CfStringLowerCase()
    {
        BuyerDetails buyer = "cf";
        Assert.True(buyer.IsConsumidorFinal);
    }

    [Fact]
    public void BuyerDetails_NitStringNeedsLookup()
    {
        BuyerDetails buyer = "12345678";
        Assert.False(buyer.IsConsumidorFinal);
        Assert.True(buyer.NeedsLookup);
        Assert.Equal("12345678", buyer.Nit);
    }

    [Fact]
    public void BuyerDetails_FromNitWithName_NoLookup()
    {
        var buyer = BuyerDetails.FromNit("12345678", "EMPRESA SA");
        Assert.False(buyer.NeedsLookup);
        Assert.Equal("EMPRESA SA", buyer.Name);
    }

    [Fact]
    public void BuyerDetails_FromNit_FullParams()
    {
        var buyer = BuyerDetails.FromNit(
            "12345678", "EMPRESA SA",
            address: "6 AV 6-48 ZONA 9",
            city: "01009",
            email: "test@example.com");
        Assert.False(buyer.NeedsLookup);
        Assert.Equal("6 AV 6-48 ZONA 9", buyer.Address);
        Assert.Equal("01009", buyer.City);
        Assert.Equal("test@example.com", buyer.Email);
    }

    [Fact]
    public void BuyerDetails_FromCui()
    {
        var buyer = BuyerDetails.FromCui("3456789012345", "NOMBRE PERSONA");
        Assert.True(buyer.IsCui);
        Assert.Equal("3456789012345", buyer.Nit);
    }

    // ── DigifactClient constructor validation ─────────────────────────────────

    [Fact]
    public void Client_ThrowsOnMissingTaxid()
    {
        var ex = Assert.Throws<ArgumentException>(() => new DigifactClient(new DigifactOptions
        {
            Taxid = "", Username = "user", Password = "pass",
        }));
        Assert.Contains("Taxid", ex.Message);
    }

    [Fact]
    public void Client_ThrowsOnMissingCredentials()
    {
        var ex = Assert.Throws<ArgumentException>(() => new DigifactClient(new DigifactOptions
        {
            Taxid = "12345678", Username = "user", Password = "", Token = "",
        }));
        Assert.Contains("Password", ex.Message);
    }

    [Fact]
    public void Client_ThrowsOnBadEnvironment()
    {
        var ex = Assert.Throws<ArgumentException>(() => new DigifactClient(new DigifactOptions
        {
            Taxid = "12345678", Username = "user", Password = "pass", Environment = "staging",
        }));
        Assert.Contains("Environment", ex.Message);
    }

    [Fact]
    public void Client_AcceptsTokenWithoutPassword()
    {
        using var client = new DigifactClient(new DigifactOptions
        {
            Taxid = "12345678", Username = "user", Token = "eyJhbGc...",
        });
        Assert.NotNull(client);
    }
}

// ── Fuel frases unit tests ────────────────────────────────────────────────────

public class FuelFrasesTests
{
    private static (string, string)[] PairsFromFrases(IReadOnlyList<FraseItem> frases) =>
        frases.Select(f => (f.TipoFrase, f.Escenario)).ToArray();

    [Fact]
    public void ResolveFuelFrases_WithinWindow_AutoInjects18And19()
    {
        var result = DteBuilder.ResolveFuelFrases(null, "1", "1", "2026-06-01T10:00:00", autoEnabled: true);
        var pairs = PairsFromFrases(result);
        Assert.Contains(("1", "1"), pairs);
        Assert.Contains(("9", "18"), pairs);
        Assert.Contains(("9", "19"), pairs);
        Assert.Equal(3, result.Count);
    }

    [Fact]
    public void ResolveFuelFrases_CustomFrases_SuppressesAutoInject()
    {
        var custom = new[] { new FraseItem("2", "1") };
        var result = DteBuilder.ResolveFuelFrases(custom, null, null, "2026-06-01T10:00:00", autoEnabled: true);
        Assert.Single(result);
        Assert.Equal("2", result[0].TipoFrase);
        Assert.DoesNotContain(result, f => f.TipoFrase == "9");
    }

    [Fact]
    public void ResolveFuelFrases_AutoDisabled_NoInject()
    {
        var result = DteBuilder.ResolveFuelFrases(null, "1", "1", "2026-06-01T10:00:00", autoEnabled: false);
        Assert.Single(result);
        Assert.DoesNotContain(result, f => f.TipoFrase == "9");
    }

    [Fact]
    public void ResolveFuelFrases_Deduplication()
    {
        var dupes = new[] { new FraseItem("9", "18"), new FraseItem("9", "18"), new FraseItem("9", "19") };
        var result = DteBuilder.ResolveFuelFrases(dupes, null, null, "2026-06-01T10:00:00", autoEnabled: true);
        Assert.Equal(2, result.Count);
    }

    [Fact]
    public void ResolveFuelFrases_OutsideWindow_NoInject()
    {
        var before = DteBuilder.ResolveFuelFrases(null, "1", "1", "2026-04-26T10:00:00", autoEnabled: true);
        Assert.Single(before);
        var after = DteBuilder.ResolveFuelFrases(null, "1", "1", "2026-07-27T10:00:00", autoEnabled: true);
        Assert.Single(after);
    }

    [Fact]
    public void BuildFactCombustible_MutualExclusivity_Throws()
    {
        var buyer = DteBuilder.BuyerCf();
        var items = new[] { new FuelLineItem { Description = "SUPER", Qty = 1, Price = 35m, PetroleoAmount = 4.70m } };
        Assert.Throws<DigifactValidationException>(() =>
            DteBuilder.BuildFactCombustible(
                "12345678", "TEST", "CALLE", buyer, items,
                tipoFrase: "1",
                frases: new[] { new FraseItem("1", "1") }));
    }

    [Fact]
    public void DigifactOptions_MutualExclusivity_Throws()
    {
        Assert.Throws<DigifactValidationException>(() =>
            new DigifactClient(new DigifactOptions
            {
                Taxid = "12345678", Username = "user", Token = "tok",
                TipoFrase = "1",
                Frases = new[] { new FraseItem("1", "1") },
            }));
    }
}
