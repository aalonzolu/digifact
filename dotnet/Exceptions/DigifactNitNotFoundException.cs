namespace Digifact.Fel;

/// <summary>The requested NIT was not found in SAT's registry.</summary>
public class DigifactNitNotFoundException : DigifactException
{
    /// <summary>Create a new <see cref="DigifactNitNotFoundException"/>.</summary>
    /// <param name="message">Human-readable error message.</param>
    /// <param name="apiCode">Optional Digifact API error code.</param>
    /// <param name="raw">Optional raw response body for debugging.</param>
    public DigifactNitNotFoundException(string message, int? apiCode = null, string? raw = null)
        : base(message, apiCode, raw) { }

    /// <summary>Create a new <see cref="DigifactNitNotFoundException"/>.</summary>
    /// <param name="message">Human-readable error message.</param>
    /// <param name="apiCode">Digifact API error code, if available.</param>
    /// <param name="raw">Raw response body for debugging, if available.</param>
    /// <param name="retryable">Whether repeating the same request may succeed.</param>
    public DigifactNitNotFoundException(string message, int? apiCode, string? raw, bool retryable)
        : base(message, apiCode, raw, retryable) { }
}
