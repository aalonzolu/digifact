namespace Digifact.Fel;

/// <summary>A CCA (Cobro por Cuenta Ajena) collection entry.</summary>
public sealed class CcaCobro
{
    /// <summary>NIT of the third party on whose behalf the cobro is made.</summary>
    public string NitTercero { get; init; } = "";
    /// <summary>Document number of the underlying transaction.</summary>
    public string NumeroDocumento { get; init; } = "";
    /// <summary>Document date (<c>"YYYY-MM-DD"</c>).</summary>
    public string FechaDocumento { get; init; } = "";
    /// <summary>Description of the cobro.</summary>
    public string Descripcion { get; init; } = "";
    /// <summary>Taxable base amount (pre-IVA).</summary>
    public decimal BaseImponible { get; init; }
    /// <summary>IVA amount for this cobro.</summary>
    public decimal MontoCobroIva { get; init; }
    /// <summary>DAI (import duty) amount, if applicable.</summary>
    public decimal MontoCobroDai { get; init; }
    /// <summary>Other taxes / charges included in the cobro.</summary>
    public decimal MontoCobroOtros { get; init; }
    /// <summary>Total amount of the cobro.</summary>
    public decimal MontoCobroTotal { get; init; }
}
