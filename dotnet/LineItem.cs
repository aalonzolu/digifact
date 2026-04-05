namespace Digifact.Fel;

/// <summary>A single line item for a DTE invoice.</summary>
public sealed class LineItem
{
    /// <summary>Item description (required).</summary>
    public string Description { get; init; } = "";

    /// <summary>Quantity. Defaults to 1.</summary>
    public decimal Qty { get; init; } = 1m;

    /// <summary>Unit price, IVA-inclusive (required).</summary>
    public decimal Price { get; init; }

    /// <summary>Item type: "Servicio" (default) or "Bien".</summary>
    public string Type { get; init; } = "Servicio";

    /// <summary>Unit of measure. Defaults to "UNI".</summary>
    public string UnitOfMeasure { get; init; } = "UNI";

    /// <summary>Line discount amount. Defaults to 0.</summary>
    public decimal Discount { get; init; } = 0m;
}
