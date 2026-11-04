namespace Digifact.Fel;

/// <summary>Base exception for all Digifact SDK errors.</summary>
public class DigifactException : Exception
{
    /// <summary>API error code returned by Digifact, if available.</summary>
    public int? ApiCode { get; }

    /// <summary>Raw response body that triggered the exception, if available.</summary>
    public string? Raw { get; }

    /// <summary>
    /// Whether repeating the same request may succeed: <c>true</c> for HTTP 5xx,
    /// <c>false</c> for rejected documents and 4xx errors.
    /// </summary>
    public bool Retryable { get; }

    /// <summary>Create a new <see cref="DigifactException"/>.</summary>
    /// <param name="message">Human-readable error message.</param>
    /// <param name="apiCode">Optional Digifact API error code.</param>
    /// <param name="raw">Optional raw response body for debugging.</param>
    public DigifactException(string message, int? apiCode = null, string? raw = null)
        : this(message, apiCode, raw, retryable: false) { }

    /// <summary>Create a new <see cref="DigifactException"/>.</summary>
    /// <param name="message">Human-readable error message.</param>
    /// <param name="apiCode">Digifact API error code, if available.</param>
    /// <param name="raw">Raw response body for debugging, if available.</param>
    /// <param name="retryable">Whether repeating the same request may succeed.</param>
    public DigifactException(string message, int? apiCode, string? raw, bool retryable)
        : base(message)
    {
        ApiCode = apiCode;
        Raw = raw;
        Retryable = retryable;
    }
}
