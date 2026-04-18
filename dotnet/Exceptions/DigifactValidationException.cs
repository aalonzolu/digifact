namespace Digifact.Fel;

/// <summary>The DTE payload was rejected by Digifact validation (code=0).</summary>
public class DigifactValidationException : DigifactException
{
    /// <summary>Create a new <see cref="DigifactValidationException"/>.</summary>
    /// <param name="message">Human-readable error message.</param>
    /// <param name="apiCode">Optional Digifact API error code.</param>
    /// <param name="raw">Optional raw response body for debugging.</param>
    public DigifactValidationException(string message, int? apiCode = null, string? raw = null)
        : base(message, apiCode, raw) { }
}
