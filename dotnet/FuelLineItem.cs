namespace Digifact.Fel;

/// <summary>
/// A single line item for a combustible (fuel) FACT invoice.
///
/// Items with <see cref="PetroleoAmount"/> > 0 are treated as fuel items and receive
/// two Tax entries (IVA + PETROLEO). Items with <see cref="PetroleoAmount"/> == 0
/// are treated as regular IVA-only items and may coexist in the same invoice.
/// </summary>
public sealed record FuelLineItem
{
    /// <summary>Item description (required).</summary>
    public string Description { get; init; } = "";

    /// <summary>Quantity. Defaults to 1.</summary>
    public decimal Qty { get; init; } = 1m;

    /// <summary>Unit price, IVA-inclusive, excluding PETROLEO tax (required).</summary>
    public decimal Price { get; init; }

    /// <summary>Item type: "Bien" (default for fuel) or "Servicio".</summary>
    public string Type { get; init; } = "Bien";

    /// <summary>Unit of measure. Defaults to "UNI".</summary>
    public string UnitOfMeasure { get; init; } = "UNI";

    /// <summary>
    /// Per-unit PETROLEO tax amount. When > 0 the item is treated as a fuel item.
    /// Defaults to 0 (regular IVA-only item).
    /// </summary>
    public decimal PetroleoAmount { get; init; } = 0m;

    /// <summary>
    /// SAT PETROLEO tax code. Common values: "1" = SUPER, "2" = REGULAR, "4" = DIESEL.
    /// Leave empty (default) for non-fuel items. When non-empty and <see cref="PetroleoAmount"/> is 0,
    /// the client will attempt to fill the amount from <see cref="DigifactOptions.PetroleoRates"/>.
    /// The builder normalises an empty code to "1" when writing the PETROLEO tax node.
    /// </summary>
    public string PetroleoCode { get; init; } = "";
}
