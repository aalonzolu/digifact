namespace Digifact.Fel;

/// <summary>The DTE payload was rejected by Digifact validation (code=0).</summary>
public class DigifactValidationException : DigifactException
{
    public DigifactValidationException(string message, int? apiCode = null, string? raw = null)
        : base(message, apiCode, raw) { }
}
