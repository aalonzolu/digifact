namespace Digifact.Fel;

/// <summary>Result of a CUI (DPI) lookup via SAT's registry.</summary>
public sealed record CuiInfo(
    string Cui,
    string Name,
    string Status
);
