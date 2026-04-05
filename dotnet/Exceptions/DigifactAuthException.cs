namespace Digifact.Fel;

/// <summary>Authentication failed (bad credentials or expired token).</summary>
public class DigifactAuthException : DigifactException
{
    public DigifactAuthException(string message, int? apiCode = null, string? raw = null)
        : base(message, apiCode, raw) { }
}
