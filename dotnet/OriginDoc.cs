namespace Digifact.Fel;

/// <summary>Reference to the original DTE for credit/debit notes.</summary>
/// <param name="AuthNumber">Authorization UUID of the original document.</param>
/// <param name="Date">Issue date of the original document in <c>"YYYY-MM-DD"</c> format.</param>
/// <param name="Series">Series (batch) of the original document.</param>
/// <param name="Number">Number (serial) of the original document.</param>
public sealed record OriginDoc(
    string AuthNumber,
    string Date,
    string Series,
    string Number
);
