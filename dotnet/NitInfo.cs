namespace Digifact.Fel;

/// <summary>Result of a NIT lookup via SAT's registry.</summary>
public sealed record NitInfo(
    string Nit,
    string Name,
    string Address,
    string City,
    string District,
    string State
);
