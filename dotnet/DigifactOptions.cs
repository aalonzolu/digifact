namespace Digifact.Fel;

/// <summary>Configuration for <see cref="DigifactClient"/>.</summary>
public sealed class DigifactOptions
{
    /// <summary>Issuer NIT (digits only or with separators, e.g. "12345678").</summary>
    public string Taxid { get; init; } = "";

    /// <summary>Digifact username (the part after the NIT prefix, e.g. "FELUSER").</summary>
    public string Username { get; init; } = "";

    /// <summary>Digifact password. Either Password or Token is required.</summary>
    public string Password { get; init; } = "";

    /// <summary>
    /// Pre-obtained Bearer token. When provided, login is skipped.
    /// Either Token or Password is required.
    /// </summary>
    public string Token { get; init; } = "";

    /// <summary>
    /// Target environment: <c>"test"</c> (default) or <c>"production"</c>.
    /// </summary>
    public string Environment { get; init; } = "test";

    /// <summary>Issuer display name. Auto-fetched via NIT lookup if not provided.</summary>
    public string SellerName { get; init; } = "";

    /// <summary>Issuer address. Auto-fetched via NIT lookup if not provided.</summary>
    public string SellerAddress { get; init; } = "";

    /// <summary>IVA affiliation code: <c>"GEN"</c> (default), <c>"PEQ"</c>, or <c>"EXE"</c>.</summary>
    public string AfiliacionIva { get; init; } = "GEN";

    /// <summary>TipoPersoneria code registered in SAT RTU. Defaults to <c>"1"</c>.</summary>
    public string TipoPersoneria { get; init; } = "1";

    /// <summary>
    /// Código del establecimiento (SAT RTU). Each NIT may have several
    /// establecimientos (1, 2, 3, …); <c>"1"</c> is usually the principal.
    /// Written to <c>Seller.BranchInfo.Code</c> on every DTE. Default <c>"1"</c>.
    /// </summary>
    public string BranchCode { get; init; } = "1";

    /// <summary>
    /// Nombre comercial del establecimiento. Written to
    /// <c>Seller.BranchInfo.Name</c> on every DTE.
    /// Default <c>"ESTABLECIMIENTO PRINCIPAL"</c>.
    /// </summary>
    public string BranchName { get; init; } = "ESTABLECIMIENTO PRINCIPAL";

    /// <summary>
    /// Global override for TipoFrase (SAT <c>AdditionlInfo</c>). When <c>null</c> (default),
    /// the SDK uses <see cref="DteBuilder.DefaultFrase"/> based on DocType + AfiliacionIva.
    /// Can be overridden per-call via <see cref="InvoiceOptions.TipoFrase"/>.
    /// Mutually exclusive with <see cref="Frases"/>.
    /// </summary>
    public string? TipoFrase { get; init; }

    /// <summary>
    /// Global override for CodigoEscenario (SAT <c>AdditionlInfo</c>). When <c>null</c> (default),
    /// the SDK uses <see cref="DteBuilder.DefaultFrase"/> based on DocType + AfiliacionIva.
    /// Common values for GEN: <c>"2"</c> = ISR régimen opcional, <c>"1"</c> = ISR trimestral.
    /// Mutually exclusive with <see cref="Frases"/>.
    /// </summary>
    public string? Escenario { get; init; }

    /// <summary>
    /// Global list of frases (TipoFrase + Escenario pairs) to use instead of the
    /// legacy <see cref="TipoFrase"/>/<see cref="Escenario"/> single-pair API.
    /// Mutually exclusive with <see cref="TipoFrase"/>/<see cref="Escenario"/>.
    /// </summary>
    public IReadOnlyList<FraseItem>? Frases { get; init; }

    /// <summary>HTTP request timeout. Defaults to 120 seconds.</summary>
    public TimeSpan Timeout { get; init; } = TimeSpan.FromSeconds(120);

    /// <summary>
    /// Optional PETROLEO code → per-unit tax amount map, e.g.
    /// <c>new Dictionary&lt;string,decimal&gt; {{"1",4.70m},{"2",4.60m},{"4",1.30m}}</c>.
    /// When set, <see cref="DigifactClient.FuelInvoiceAsync"/> fills in
    /// <see cref="FuelLineItem.PetroleoAmount"/> automatically for items that
    /// provide <see cref="FuelLineItem.PetroleoCode"/> but leave <c>PetroleoAmount</c>
    /// as zero.
    /// </summary>
    public IReadOnlyDictionary<string, decimal>? PetroleoRates { get; init; }
}
