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

    /// <summary>HTTP request timeout. Defaults to 120 seconds.</summary>
    public TimeSpan Timeout { get; init; } = TimeSpan.FromSeconds(120);
}
