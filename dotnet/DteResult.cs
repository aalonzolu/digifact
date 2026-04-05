using System.Text.Json;

namespace Digifact.Fel;

/// <summary>Result of a successful DTE emission.</summary>
public sealed class DteResult
{
    /// <summary>SAT authorization UUID.</summary>
    public string AuthNumber { get; }

    /// <summary>Document series (batch).</summary>
    public string Series { get; }

    /// <summary>Document number (serial).</summary>
    public string Number { get; }

    /// <summary>Issue date/time as returned by the API (space-separated format).</summary>
    public string IssueDateTime { get; }

    /// <summary>Raw API response as a <see cref="JsonElement"/>.</summary>
    public JsonElement Raw { get; }

    private DteResult(string authNumber, string series, string number, string issueDateTime, JsonElement raw)
    {
        AuthNumber = authNumber;
        Series = series;
        Number = number;
        IssueDateTime = issueDateTime;
        Raw = raw;
    }

    internal static DteResult FromJson(JsonElement data)
    {
        string auth = GetString(data, "authNumber", "Autorizacion");
        string series = GetString(data, "batch", "Serie");
        string number = GetString(data, "serial", "Numero");
        string tsRaw = GetString(data, "issuedTimeStamp", "FechaEmision");
        string issueDateTime = tsRaw.Contains('T') ? tsRaw.Replace('T', ' ') : tsRaw;
        return new DteResult(auth, series, number, issueDateTime, data.Clone());
    }

    private static string GetString(JsonElement el, params string[] keys)
    {
        foreach (var key in keys)
        {
            if (el.TryGetProperty(key, out var prop))
                return prop.ValueKind == JsonValueKind.String ? (prop.GetString() ?? "") : prop.ToString();
        }
        return "";
    }
}
