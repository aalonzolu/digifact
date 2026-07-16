namespace Digifact.Fel;

/// <summary>
/// Identifies the buyer for a DTE document.
/// <para>
/// Use <see cref="Cf"/> for anonymous consumer final, <see cref="FromNit"/> for a
/// registered NIT (auto-lookup when only NIT is provided), or <see cref="FromCui"/>
/// for CUI-identified buyers.
/// </para>
/// <para>
/// Implicit conversion from <see langword="string"/> is supported:
/// <c>"CF"</c> → consumer final, a 13-digit string → CUI with auto-lookup,
/// any other digit string → NIT with auto-lookup.
/// </para>
/// </summary>
public sealed class BuyerDetails
{
    /// <summary>Digits in a Guatemalan CUI (DPI); a NIT never reaches this length.</summary>
    private const int CuiLength = 13;

    private BuyerDetails() { }

    /// <summary>Consumidor Final (anonymous buyer).</summary>
    public static BuyerDetails Cf() => new() { IsConsumidorFinal = true };

    /// <summary>
    /// Buyer identified by NIT. When <paramref name="name"/> is <see langword="null"/>
    /// the client will call <c>LookupNitAsync</c> automatically to resolve the buyer info.
    /// </summary>
    public static BuyerDetails FromNit(
        string nit,
        string? name = null,
        string address = "CIUDAD",
        string city = "01010",
        string district = "GUATEMALA",
        string state = "GUATEMALA",
        string country = "GT",
        string? email = null) => new()
    {
        Nit = nit,
        Name = name,
        Address = address,
        City = city,
        District = district,
        State = state,
        Country = country,
        Email = email,
    };

    /// <summary>
    /// Buyer identified by CUI (Código Único de Identificación). When
    /// <paramref name="name"/> is <see langword="null"/> the client will call
    /// <c>LookupCuiAsync</c> automatically to resolve the buyer's name.
    /// </summary>
    public static BuyerDetails FromCui(string cui, string? name = null) =>
        new() { Nit = cui, Name = name, IsCui = true };

    /// <summary>
    /// Implicit conversion from a string: <c>"CF"</c> → consumer final, a 13-digit
    /// string → CUI, anything else → NIT. Both identifier forms are auto-looked up.
    /// </summary>
    public static implicit operator BuyerDetails(string nitCuiOrCf)
    {
        if (string.Equals(nitCuiOrCf, "CF", StringComparison.OrdinalIgnoreCase))
            return Cf();
        return TaxHelper.StripTaxid(nitCuiOrCf).Length == CuiLength
            ? FromCui(nitCuiOrCf)
            : FromNit(nitCuiOrCf);
    }

    internal bool IsConsumidorFinal { get; private init; }
    internal bool IsCui { get; private init; }
    internal bool NeedsLookup => !IsConsumidorFinal && Name is null;

    /// <summary>NIT or CUI of the buyer (<see langword="null"/> for consumidor final).</summary>
    public string? Nit { get; private init; }
    /// <summary>Buyer display name. <see langword="null"/> triggers auto-lookup on NIT and CUI buyers.</summary>
    public string? Name { get; private init; }
    /// <summary>Street address. Defaults to <c>"CIUDAD"</c>.</summary>
    public string? Address { get; private init; } = "CIUDAD";
    /// <summary>SAT city code. Defaults to <c>"01010"</c>.</summary>
    public string? City { get; private init; } = "01010";
    /// <summary>Municipio. Defaults to <c>"GUATEMALA"</c>.</summary>
    public string? District { get; private init; } = "GUATEMALA";
    /// <summary>Departamento. Defaults to <c>"GUATEMALA"</c>.</summary>
    public string? State { get; private init; } = "GUATEMALA";
    /// <summary>ISO country code. Defaults to <c>"GT"</c>.</summary>
    public string Country { get; private init; } = "GT";
    /// <summary>Optional buyer email address (added to <c>Contact.EmailList</c>).</summary>
    public string? Email { get; private init; }
}
