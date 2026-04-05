namespace Digifact.Fel;

/// <summary>The Digifact API returned a non-success response.</summary>
public class DigifactApiException : DigifactException
{
    public DigifactApiException(string message, int? apiCode = null, string? raw = null)
        : base(message, apiCode, raw) { }
}
