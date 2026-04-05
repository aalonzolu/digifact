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
/// <c>"CF"</c> → consumer final, any digit string → NIT with auto-lookup.
/// </para>
/// </summary>
public sealed class BuyerDetails
{
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

    /// <summary>Buyer identified by CUI (Código Único de Identificación).</summary>
    public static BuyerDetails FromCui(string cui, string name) =>
        new() { Nit = cui, Name = name, IsCui = true };

    /// <summary>
    /// Implicit conversion from a string: <c>"CF"</c> → consumer final,
    /// otherwise treated as a NIT that will be auto-looked up.
    /// </summary>
    public static implicit operator BuyerDetails(string nitOrCf) =>
        string.Equals(nitOrCf, "CF", StringComparison.OrdinalIgnoreCase)
            ? Cf()
            : FromNit(nitOrCf);

    internal bool IsConsumidorFinal { get; private init; }
    internal bool IsCui { get; private init; }
    internal bool NeedsLookup => !IsConsumidorFinal && !IsCui && Name is null;

    public string? Nit { get; private init; }
    public string? Name { get; private init; }
    public string? Address { get; private init; } = "CIUDAD";
    public string? City { get; private init; } = "01010";
    public string? District { get; private init; } = "GUATEMALA";
    public string? State { get; private init; } = "GUATEMALA";
    public string Country { get; private init; } = "GT";
    public string? Email { get; private init; }
}
