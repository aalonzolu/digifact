namespace Digifact.Fel;

/// <summary>A CCA (Cobro por Cuenta Ajena) collection entry.</summary>
public sealed class CcaCobro
{
    public string NitTercero { get; init; } = "";
    public string NumeroDocumento { get; init; } = "";
    public string FechaDocumento { get; init; } = "";
    public string Descripcion { get; init; } = "";
    public decimal BaseImponible { get; init; }
    public decimal MontoCobroIva { get; init; }
    public decimal MontoCobroDai { get; init; }
    public decimal MontoCobroOtros { get; init; }
    public decimal MontoCobroTotal { get; init; }
}
