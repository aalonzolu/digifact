namespace Digifact.Fel;

/// <summary>Optional parameters for <see cref="DigifactClient.InvoiceAsync"/>.</summary>
public sealed class InvoiceOptions
{
    /// <summary>
    /// DTE document type. Defaults to <c>"FACT"</c>.
    /// Supported: FACT, FCAM, NABN, FESP, RDON, FPEQ, RECI.
    /// </summary>
    public string DocType { get; init; } = "FACT";

    /// <summary>Payment terms for FCAM documents (required when DocType = "FCAM").</summary>
    public IReadOnlyList<PaymentTerm>? PaymentTerms { get; init; }

    /// <summary>Grand total in words (e.g. "CIEN QUETZALES EXACTOS"). Defaults to formatted number.</summary>
    public string AmountStr { get; init; } = "";

    /// <summary>Adenda observations. Defaults to <c>"-"</c>.</summary>
    public string Observaciones { get; init; } = "-";

    /// <summary>Override the client-level TipoPersoneria (used for RDON).</summary>
    public string? TipoPersoneria { get; init; }

    /// <summary>Override TipoFrase for this call. Falls back to client-level override, then to defaults table.</summary>
    public string? TipoFrase { get; init; }

    /// <summary>Override CodigoEscenario for this call. Falls back to client-level override, then to defaults table.</summary>
    public string? Escenario { get; init; }
}

/// <summary>A payment instalment for FCAM invoices.</summary>
/// <param name="Date">Due date in <c>"YYYY-MM-DD"</c> format.</param>
/// <param name="Amount">Instalment amount.</param>
public sealed record PaymentTerm(
    string Date,
    decimal Amount
);

/// <summary>
/// A single TipoFrase / CodigoEscenario pair for the <c>Seller.AdditionlInfo</c> block.
/// Use the <c>Frases</c> option on <see cref="DigifactOptions"/> or <c>FuelInvoiceAsync</c>
/// to supply a list instead of the legacy <c>TipoFrase</c>/<c>Escenario</c> string parameters.
/// Mutually exclusive with <c>TipoFrase</c>/<c>Escenario</c>.
/// </summary>
/// <param name="TipoFrase">SAT TipoFrase code, e.g. "1" or "9".</param>
/// <param name="Escenario">SAT CodigoEscenario code, e.g. "1", "18", "19".</param>
public sealed record FraseItem(string TipoFrase, string Escenario);
