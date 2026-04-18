namespace Digifact.Fel;

/// <summary>Authentication failed (bad credentials or expired token).</summary>
public class DigifactAuthException : DigifactException
{
    /// <summary>Create a new <see cref="DigifactAuthException"/>.</summary>
    /// <param name="message">Human-readable error message.</param>
    /// <param name="apiCode">Optional Digifact API error code.</param>
    /// <param name="raw">Optional raw response body for debugging.</param>
    public DigifactAuthException(string message, int? apiCode = null, string? raw = null)
        : base(message, apiCode, raw) { }
}
