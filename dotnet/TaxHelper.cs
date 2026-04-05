using System.Globalization;

namespace Digifact.Fel;

/// <summary>
/// IVA calculations, money formatting, and Guatemala time helpers.
/// Uses <see cref="decimal"/> for arbitrary-precision arithmetic.
/// All money values are returned as strings with 6 decimal places.
/// </summary>
internal static class TaxHelper
{
    private static readonly TimeSpan GtOffset = TimeSpan.FromHours(-6); // UTC-6, no DST

    /// <summary>
    /// Returns current Guatemala time (UTC-6) as three strings:
    /// (isoWithOffset, spaceDatetime, dateOnly).
    /// </summary>
    internal static (string IsoWithOffset, string SpaceDatetime, string DateOnly) GtNow()
    {
        var now = DateTimeOffset.UtcNow.ToOffset(GtOffset);
        string iso = now.ToString("yyyy-MM-dd'T'HH:mm:ss", CultureInfo.InvariantCulture) + "-06:00";
        string space = now.ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture);
        string date = now.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
        return (iso, space, date);
    }

    /// <summary>Strip non-digits and left-pad to 12 characters with zeros.</summary>
    internal static string PadTaxid(string taxid)
    {
        var digits = new string(taxid.Where(char.IsDigit).ToArray());
        return digits.PadLeft(12, '0');
    }

    /// <summary>Strip non-digits from a taxid string.</summary>
    internal static string StripTaxid(string taxid) =>
        new(taxid.Where(char.IsDigit).ToArray());

    /// <summary>Format a decimal value as a string with <paramref name="decimals"/> places.</summary>
    internal static string Fmt(decimal value, int decimals = 6) =>
        value.ToString("F" + decimals, CultureInfo.InvariantCulture);

    /// <summary>
    /// Calculate IVA from an IVA-inclusive line total.
    /// Returns (taxableAmount, ivaAmount) as formatted strings (6 decimal places).
    /// </summary>
    internal static (string Taxable, string Iva) CalcIva(decimal lineTotal)
    {
        // taxable = lineTotal * 100 / 112
        decimal taxable = lineTotal * 100m / 112m;
        decimal iva = lineTotal - taxable;
        return (
            RoundHalfUp(taxable, 6).ToString("F6", CultureInfo.InvariantCulture),
            RoundHalfUp(iva, 6).ToString("F6", CultureInfo.InvariantCulture)
        );
    }

    /// <summary>Rounds <paramref name="value"/> half-up to <paramref name="decimals"/> places.</summary>
    internal static decimal RoundHalfUp(decimal value, int decimals) =>
        Math.Round(value, decimals, MidpointRounding.AwayFromZero);

    /// <summary>Build all line calculations for one item.</summary>
    internal static LineCalc CalcLine(decimal qty, decimal price, bool taxable, decimal discount)
    {
        decimal gross = qty * price;
        decimal lineTotal = gross - discount;

        string taxableStr, ivaStr;
        if (taxable)
            (taxableStr, ivaStr) = CalcIva(lineTotal);
        else
            (taxableStr, ivaStr) = ("0.000000", "0.000000");

        return new LineCalc(
            Qty: Fmt(qty),
            Price: Fmt(price),
            LineTotal: Fmt(lineTotal),
            Taxable: taxableStr,
            Iva: ivaStr,
            Discount: Fmt(discount, 2)
        );
    }
}

internal sealed record LineCalc(
    string Qty,
    string Price,
    string LineTotal,
    string Taxable,
    string Iva,
    string Discount
);
