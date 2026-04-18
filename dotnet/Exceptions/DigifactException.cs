namespace Digifact.Fel;

/// <summary>Base exception for all Digifact SDK errors.</summary>
public class DigifactException : Exception
{
    /// <summary>API error code returned by Digifact, if available.</summary>
    public int? ApiCode { get; }

    /// <summary>Raw response body that triggered the exception, if available.</summary>
    public string? Raw { get; }

    /// <summary>Create a new <see cref="DigifactException"/>.</summary>
    /// <param name="message">Human-readable error message.</param>
    /// <param name="apiCode">Optional Digifact API error code.</param>
    /// <param name="raw">Optional raw response body for debugging.</param>
    public DigifactException(string message, int? apiCode = null, string? raw = null)
        : base(message)
    {
        ApiCode = apiCode;
        Raw = raw;
    }
}
