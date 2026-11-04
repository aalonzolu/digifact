namespace Digifact.Fel;

/// <summary>
/// The internal reference was already sent to certify (Digifact 9022 / BUS-003).
/// Raised only when the DTE was sent with <c>ValidateInternalReference</c>.
/// The original DTE may already be certified: look it up with
/// <see cref="DigifactClient.FindByInternalReferenceAsync(string, string, CancellationToken)"/>
/// instead of issuing again.
/// </summary>
public class DigifactDuplicateReferenceException : DigifactApiException
{
    /// <summary>Create a new <see cref="DigifactDuplicateReferenceException"/>.</summary>
    /// <param name="message">Human-readable error message.</param>
    /// <param name="apiCode">Optional Digifact API error code.</param>
    /// <param name="raw">Optional raw response body for debugging.</param>
    public DigifactDuplicateReferenceException(string message, int? apiCode = null, string? raw = null)
        : base(message, apiCode, raw) { }

    /// <summary>Create a new <see cref="DigifactDuplicateReferenceException"/>.</summary>
    /// <param name="message">Human-readable error message.</param>
    /// <param name="apiCode">Digifact API error code, if available.</param>
    /// <param name="raw">Raw response body for debugging, if available.</param>
    /// <param name="retryable">Whether repeating the same request may succeed.</param>
    public DigifactDuplicateReferenceException(string message, int? apiCode, string? raw, bool retryable)
        : base(message, apiCode, raw, retryable) { }
}
