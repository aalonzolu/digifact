namespace Digifact.Fel;

/// <summary>Base exception for all Digifact SDK errors.</summary>
public class DigifactException : Exception
{
    /// <summary>API error code returned by Digifact, if available.</summary>
    public int? ApiCode { get; }

    /// <summary>Raw response body that triggered the exception, if available.</summary>
    public string? Raw { get; }

    public DigifactException(string message, int? apiCode = null, string? raw = null)
        : base(message)
    {
        ApiCode = apiCode;
        Raw = raw;
    }
}
