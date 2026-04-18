namespace Digifact.Fel;

/// <summary>The Digifact API returned a non-success response.</summary>
public class DigifactApiException : DigifactException
{
    /// <summary>Create a new <see cref="DigifactApiException"/>.</summary>
    /// <param name="message">Human-readable error message.</param>
    /// <param name="apiCode">Optional Digifact API error code.</param>
    /// <param name="raw">Optional raw response body for debugging.</param>
    public DigifactApiException(string message, int? apiCode = null, string? raw = null)
        : base(message, apiCode, raw) { }
}
