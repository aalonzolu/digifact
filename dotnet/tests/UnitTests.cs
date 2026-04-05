using System;
using System.Globalization;
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

    // ── DteResult.FromJson ────────────────────────────────────────────────────

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
