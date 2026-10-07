using System.Globalization;

namespace Digifact.Fel;

/// <summary>
/// SAT PETROLEO unit codes for fuel sold under the Decreto 22-2026 temporary exemption.
/// </summary>
public static class FuelExemptCodes
{
    /// <summary>Gasolina superior.</summary>
    public const string Super = "18";

    /// <summary>Gasolina regular without ethanol. SAT only accepts it from importers.</summary>
    public const string RegularImporter = "19";

    /// <summary>Diésel.</summary>
    public const string Diesel = "20";

    /// <summary>Gasolina regular with ethanol, the one service stations sell.</summary>
    public const string RegularEthanol = "21";

    internal static readonly IReadOnlyDictionary<string, decimal> IdpRates = new Dictionary<string, decimal>
    {
        [Super]           = 4.70m,
        [RegularImporter] = 4.60m,
        [Diesel]          = 1.30m,
        [RegularEthanol]  = 4.14m,
    };
}

/// <summary>
/// Taxes a fuel sale would have paid without the Decreto 22-2026 exemption.
/// </summary>
/// <param name="Iva">Exempted IVA.</param>
/// <param name="Idp">Exempted IDP.</param>
public sealed record FuelExemption(decimal Iva, decimal Idp)
{
    private const decimal IvaRate = 0.12m;

    /// <summary>
    /// Mandatory legends for the printed invoice. Empty when nothing was exempted.
    /// </summary>
    public IReadOnlyList<string> Leyendas => Iva == 0m && Idp == 0m
        ? Array.Empty<string>()
        : new[]
        {
            $"Monto de exención temporal de IDP aplicada: Q {Money(Idp)}, según Decreto Número 22-2026",
            $"Monto de exención temporal de IVA aplicada: Q {Money(Iva)}, según Decreto Número 22-2026",
        };

    /// <summary>
    /// Compute the exemption for the exempt fuel items in <paramref name="items"/>.
    /// <see cref="FuelLineItem.Qty"/> must be in gallons: the IDP rates are per gallon.
    /// </summary>
    public static FuelExemption From(IEnumerable<FuelLineItem> items)
    {
        decimal exemptTotal = 0m;
        decimal idp = 0m;
        foreach (var item in items.Where(i => i.IsExempt))
        {
            exemptTotal += item.Qty * item.Price;
            idp += item.Qty * FuelExemptCodes.IdpRates[item.PetroleoCode];
        }
        return new FuelExemption(Round(exemptTotal * IvaRate), Round(idp));
    }

    private static decimal Round(decimal value) => Math.Round(value, 2, MidpointRounding.AwayFromZero);

    private static string Money(decimal value) => value.ToString("0.00", CultureInfo.InvariantCulture);
}
