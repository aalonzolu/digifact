namespace Digifact.Fel;

/// <summary>Reference to the original DTE for credit/debit notes.</summary>
public sealed record OriginDoc(
    /// <summary>Authorization UUID of the original document.</summary>
    string AuthNumber,
    /// <summary>Issue date of the original document in "YYYY-MM-DD" format.</summary>
    string Date,
    /// <summary>Series (batch) of the original document.</summary>
    string Series,
    /// <summary>Number (serial) of the original document.</summary>
    string Number
);
