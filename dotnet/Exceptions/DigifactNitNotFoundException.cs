namespace Digifact.Fel;

/// <summary>The requested NIT was not found in SAT's registry.</summary>
public class DigifactNitNotFoundException : DigifactException
{
    public DigifactNitNotFoundException(string message, int? apiCode = null, string? raw = null)
        : base(message, apiCode, raw) { }
}
