using System.Collections.Concurrent;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace Digifact.Fel;

/// <summary>
/// Main entry point for the Digifact FEL Guatemala C# SDK.
/// </summary>
public sealed class DigifactClient : IDisposable
{
    private readonly string _taxid;
    private readonly string _paddedTaxid;
    private readonly string _username;
    private readonly string _password;
    private readonly string _baseUrl;
    private string _token;
    private string _sellerName;
    private string _sellerAddress;
    private readonly string _afiliacionIva;
    private readonly string _tipoPersoneria;
    private readonly string? _tipoFrase;
    private readonly string? _escenario;
    private readonly IReadOnlyList<FraseItem>? _frases;
    private readonly bool? _autoFuelSubsidyFrases;
    private readonly string _branchCode;
    private readonly string _branchName;
    private readonly IReadOnlyDictionary<string, decimal> _petroleoRates;
    private readonly HttpClient _http;
    private readonly bool _ownsHttpClient;
    private readonly ConcurrentDictionary<string, NitInfo> _nitCache = new();

    private static readonly Dictionary<string, string> BaseUrls = new(StringComparer.OrdinalIgnoreCase)
    {
        ["test"]       = "https://testnucgt.digifact.com/api",
        ["production"] = "https://nucgt.digifact.com/gt.com.apinuc/api",
    };

    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
    };

    /// <summary>
    /// Create a new <see cref="DigifactClient"/>.
    /// </summary>
    /// <param name="options">Configuration options.</param>
    /// <param name="httpClient">
    /// Optional <see cref="HttpClient"/> to use. When omitted, one is created internally.
    /// The internal client is disposed when this instance is disposed.
    /// </param>
    public DigifactClient(DigifactOptions options, HttpClient? httpClient = null)
    {
        if (string.IsNullOrWhiteSpace(options.Taxid))
            throw new ArgumentException("Taxid must contain at least one digit.", nameof(options));
        if (string.IsNullOrWhiteSpace(options.Username))
            throw new ArgumentException("Username is required.", nameof(options));
        if (string.IsNullOrWhiteSpace(options.Token) && string.IsNullOrWhiteSpace(options.Password))
            throw new ArgumentException("Either Password or Token is required.", nameof(options));
        if (!BaseUrls.TryGetValue(options.Environment, out var baseUrl))
            throw new ArgumentException($"Environment must be 'test' or 'production', got '{options.Environment}'.", nameof(options));

        _taxid = TaxHelper.StripTaxid(options.Taxid);
        _paddedTaxid = TaxHelper.PadTaxid(options.Taxid);
        _username = options.Username;
        _password = options.Password;
        _token = options.Token;
        _sellerName = options.SellerName;
        _sellerAddress = options.SellerAddress;
        _afiliacionIva = options.AfiliacionIva;
        _tipoPersoneria = options.TipoPersoneria;
        _tipoFrase = options.TipoFrase;
        _escenario = options.Escenario;
        if (options.Frases is not null && (options.TipoFrase is not null || options.Escenario is not null))
            throw new DigifactValidationException(
                "Frases and TipoFrase/Escenario are mutually exclusive; use one or the other.");
        _frases = options.Frases;
        _autoFuelSubsidyFrases = options.AutoFuelSubsidyFrases;
        _branchCode = string.IsNullOrEmpty(options.BranchCode) ? "1" : options.BranchCode;
        _branchName = string.IsNullOrEmpty(options.BranchName) ? "ESTABLECIMIENTO PRINCIPAL" : options.BranchName;
        _petroleoRates = options.PetroleoRates
            ?? (IReadOnlyDictionary<string, decimal>)new Dictionary<string, decimal>();
        _baseUrl = baseUrl.TrimEnd('/');

        _ownsHttpClient = httpClient is null;
        _http = httpClient ?? new HttpClient();
        _http.Timeout = options.Timeout;
    }

    // ── Authentication ─────────────────────────────────────────────────────────

    private async Task<string> LoginAsync(CancellationToken ct)
    {
        if (!string.IsNullOrEmpty(_token))
            return _token;

        if (string.IsNullOrEmpty(_password))
            throw new DigifactAuthException("Password or Token is required.");

        string fullUsername = $"GT.{_paddedTaxid}.{_username}";
        var data = await PostAsync("/login/get_token", new JsonObject
        {
            ["Username"] = fullUsername,
            ["Password"] = _password,
        }, withAuth: false, ct: ct);

        string? tok = null;
        if (data.TryGetProperty("Token", out var t1) && t1.ValueKind == JsonValueKind.String)
            tok = t1.GetString();
        else if (data.TryGetProperty("token", out var t2) && t2.ValueKind == JsonValueKind.String)
            tok = t2.GetString();

        if (string.IsNullOrEmpty(tok))
            throw new DigifactAuthException("Login succeeded but response contained no token.", raw: data.GetRawText());

        _token = tok;
        return _token;
    }

    // ── HTTP helpers ───────────────────────────────────────────────────────────

    private async Task<JsonElement> PostAsync(
        string path, JsonObject body,
        bool withAuth = true, Dictionary<string, string>? queryParams = null,
        CancellationToken ct = default)
    {
        var url = BuildUrl(path, queryParams);
        var json = body.ToJsonString();
        using var request = new HttpRequestMessage(HttpMethod.Post, url)
        {
            Content = new StringContent(json, Encoding.UTF8, "application/json"),
        };

        if (withAuth)
            request.Headers.Authorization = new AuthenticationHeaderValue(await LoginAsync(ct));

        using var response = await _http.SendAsync(request, ct);
        var responseText = await response.Content.ReadAsStringAsync(ct);

        if (!response.IsSuccessStatusCode)
        {
            var preview = responseText.Length > 300 ? responseText[..300] + "…" : responseText;
            throw new DigifactApiException($"HTTP {(int)response.StatusCode}: {preview}", (int)response.StatusCode);
        }

        return JsonDocument.Parse(responseText).RootElement.Clone();
    }

    private async Task<JsonElement> GetAsync(
        string path, Dictionary<string, string>? queryParams = null,
        CancellationToken ct = default)
    {
        var url = BuildUrl(path, queryParams);
        using var request = new HttpRequestMessage(HttpMethod.Get, url);
        request.Headers.Authorization = new AuthenticationHeaderValue(await LoginAsync(ct));

        using var response = await _http.SendAsync(request, ct);
        var responseText = await response.Content.ReadAsStringAsync(ct);

        if (!response.IsSuccessStatusCode)
        {
            var preview = responseText.Length > 300 ? responseText[..300] + "…" : responseText;
            throw new DigifactApiException($"HTTP {(int)response.StatusCode}: {preview}", (int)response.StatusCode);
        }

        return JsonDocument.Parse(responseText).RootElement.Clone();
    }

    private string BuildUrl(string path, Dictionary<string, string>? queryParams)
    {
        var url = _baseUrl + path;
        if (queryParams is { Count: > 0 })
        {
            var qs = string.Join("&", queryParams.Select(kv =>
                Uri.EscapeDataString(kv.Key) + "=" + Uri.EscapeDataString(kv.Value)));
            url += "?" + qs;
        }
        return url;
    }

    // ── Seller info ────────────────────────────────────────────────────────────

    private async Task<(string Name, string Address)> GetSellerInfoAsync(CancellationToken ct)
    {
        if (!string.IsNullOrEmpty(_sellerName) && !string.IsNullOrEmpty(_sellerAddress))
            return (_sellerName, _sellerAddress);

        try
        {
            var info = await LookupNitAsync(_taxid, ct);
            if (string.IsNullOrEmpty(_sellerName))
                _sellerName = !string.IsNullOrEmpty(info.Name) ? info.Name : $"EMISOR {_taxid}";
            if (string.IsNullOrEmpty(_sellerAddress))
                _sellerAddress = !string.IsNullOrEmpty(info.Address) ? info.Address : "CIUDAD";
        }
        catch (DigifactException)
        {
            if (string.IsNullOrEmpty(_sellerName))
                _sellerName = $"EMISOR {_taxid}";
            if (string.IsNullOrEmpty(_sellerAddress))
                _sellerAddress = "CIUDAD";
        }

        return (_sellerName, _sellerAddress);
    }

    // ── Buyer resolution ───────────────────────────────────────────────────────

    private async Task<JsonObject> ResolveBuyerAsync(BuyerDetails buyer, CancellationToken ct)
    {
        if (buyer.IsConsumidorFinal)
            return DteBuilder.BuyerCf();

        if (buyer.IsCui)
            return DteBuilder.BuyerCui(buyer.Nit!, buyer.Name!);

        // NIT with explicit details
        if (!buyer.NeedsLookup)
            return DteBuilder.BuyerNit(
                buyer.Nit!, buyer.Name!,
                buyer.Address ?? "CIUDAD",
                buyer.City ?? "01010",
                buyer.District ?? "GUATEMALA",
                buyer.State ?? "GUATEMALA",
                buyer.Country, buyer.Email);

        // NIT auto-lookup
        var digits = TaxHelper.StripTaxid(buyer.Nit!);
        var info = await LookupNitAsync(digits, ct);
        return DteBuilder.BuyerNit(
            digits,
            !string.IsNullOrEmpty(info.Name) ? info.Name : digits,
            !string.IsNullOrEmpty(info.Address) ? info.Address : "CIUDAD",
            !string.IsNullOrEmpty(info.City) ? info.City : "01010",
            !string.IsNullOrEmpty(info.District) ? info.District : "GUATEMALA",
            !string.IsNullOrEmpty(info.State) ? info.State : "GUATEMALA");
    }

    // ── Certify ────────────────────────────────────────────────────────────────

    /// <summary>
    /// Overlay configured <see cref="DigifactOptions.BranchCode"/> /
    /// <see cref="DigifactOptions.BranchName"/> onto <c>Seller.BranchInfo</c> so
    /// callers don't have to plumb branch info through every builder.
    /// </summary>
    private void ApplyBranchInfo(JsonObject payload)
    {
        if (payload["Seller"] is JsonObject seller &&
            seller["BranchInfo"] is JsonObject branch)
        {
            branch["Code"] = _branchCode;
            branch["Name"] = _branchName;
        }
    }

    private async Task<JsonElement> CertifyAsync(JsonObject payload, CancellationToken ct)
    {
        ApplyBranchInfo(payload);
        var data = await PostAsync("/v2/transform/nuc_json", payload, withAuth: true,
            queryParams: new Dictionary<string, string>
            {
                ["TAXID"]    = _paddedTaxid,
                ["FORMAT"]   = "XML|HTML|PDF",
                ["USERNAME"] = _username,
            }, ct: ct);
        return CheckResponse(data);
    }

    private JsonElement CheckResponse(JsonElement data)
    {
        if (!data.TryGetProperty("code", out var codeProp))
            return data;

        int code = codeProp.ValueKind == JsonValueKind.String
            ? int.Parse(codeProp.GetString() ?? "0")
            : codeProp.GetInt32();

        if (code == 1)
            return data;

        string msg = "";
        if (data.TryGetProperty("description", out var d))
            msg = d.GetString() ?? "";
        else if (data.TryGetProperty("message", out var m))
            msg = m.GetString() ?? "";
        else
            msg = data.GetRawText();

        string fullMsg = $"DTE rejected (code={code}): {msg}";
        if (ClassifyError(msg) is string hint)
            fullMsg += $"\n\nHint: {hint}";

        string raw = data.GetRawText();
        if (code == 0)
            throw new DigifactValidationException(fullMsg, code, raw);
        throw new DigifactApiException(fullMsg, code, raw);
    }

    private static string? ClassifyError(string message)
    {
        string upper = message.ToUpperInvariant();
        var hints = new[]
        {
            (new[] { "AFILIACIONIVA", "MINIRTU", "RTU" },
             "El NIT del emisor no tiene la afiliación de IVA indicada en su RTU (AfiliacionIVA). " +
             "Verifica que AfiliacionIVA sea GEN, PEQ o EXE según el RTU del emisor."),
            (new[] { "TIPOPERSONERIA" },
             "El valor de TipoPersoneria debe coincidir exactamente con el código registrado en el " +
             "RTU del emisor. Consulta el RTU en portal.sat.gob.gt."),
            (new[] { "SECCION 3.5", "9015", "ID RECEPTOR" },
             "NCRE/NDEB: el receptor del documento origen debe coincidir con el receptor del documento de nota."),
            (new[] { "NAMESPACE_UNKNOWN", "5035" },
             "El complemento CCA requiere que el emisor tenga habilitado el namespace CCA en Digifact. " +
             "Contacta a soporte@digifact.com.gt."),
            (new[] { "FRASES" },
             "Este tipo de DTE no permite las frases indicadas. Para FESP no incluyas TipoFrase/Escenario."),
            (new[] { "FECHAEMISION", "FECHA" },
             "Verifica el formato de fecha: 'YYYY-MM-DD HH:MM:SS' para cancelaciones, " +
             "o 'YYYY-MM-DDTHH:MM:SS-06:00' para IssuedDateTime."),
        };

        foreach (var (keywords, hint) in hints)
            foreach (var kw in keywords)
                if (upper.Contains(kw))
                    return hint;

        return null;
    }

    // ── Public DTE methods ─────────────────────────────────────────────────────

    /// <summary>
    /// Resolve effective (TipoFrase, CodigoEscenario) for a DTE.
    /// Precedence: per-call override → client global → defaults table.
    /// </summary>
    private (string? Tf, string? Es) ResolveFrase(string docType, string? callTf = null, string? callEs = null)
    {
        var def = DteBuilder.DefaultFrase(docType, _afiliacionIva);
        string? defTf = def?.TipoFrase;
        string? defEs = def?.Escenario;
        return (callTf ?? _tipoFrase ?? defTf, callEs ?? _escenario ?? defEs);
    }

    /// <summary>Emit a DTE invoice (FACT, FCAM, NABN, FESP, RDON, FPEQ, RECI).</summary>
    /// <param name="buyer">
    /// Buyer identifier. Use <c>"CF"</c>, a NIT string (auto-lookup), or a
    /// <see cref="BuyerDetails"/> instance for explicit buyer data.
    /// </param>
    /// <param name="items">Line items.</param>
    /// <param name="opts">Optional document type and other parameters.</param>
    /// <param name="ct">Cancellation token.</param>
    public async Task<DteResult> InvoiceAsync(
        BuyerDetails buyer, IReadOnlyList<LineItem> items,
        InvoiceOptions? opts = null, CancellationToken ct = default)
    {
        opts ??= new InvoiceOptions();
        var (sellerName, sellerAddress) = await GetSellerInfoAsync(ct);
        var buyerNode = await ResolveBuyerAsync(buyer, ct);
        string docType = opts.DocType.ToUpperInvariant();

        IReadOnlyList<FraseItem>? effFrases;
        string? tf, es;
        if (_frases is not null && opts.TipoFrase is null && opts.Escenario is null)
        {
            effFrases = _frases; tf = null; es = null;
        }
        else
        {
            effFrases = null;
            (tf, es) = ResolveFrase(docType, opts.TipoFrase, opts.Escenario);
        }

        JsonObject payload = docType switch
        {
            "FCAM" => BuildFcam(sellerName, sellerAddress, buyerNode, items, opts, tf, es, effFrases),
            "FESP" => DteBuilder.BuildFesp(_taxid, sellerName, sellerAddress, buyerNode, items, _afiliacionIva),
            "FPEQ" => DteBuilder.BuildFpeq(_taxid, sellerName, sellerAddress, buyerNode, items,
                opts.AmountStr, opts.Observaciones, tipoFrase: tf, escenario: es, frases: effFrases),
            _ => DteBuilder.BuildFact(_taxid, sellerName, sellerAddress, buyerNode, items,
                docType, _afiliacionIva, tipoFrase: tf, escenario: es,
                amountStr: opts.AmountStr, observaciones: opts.Observaciones, frases: effFrases),
        };

        // RDON needs tipoPersoneria
        if (docType == "RDON")
        {
            payload = DteBuilder.BuildRdon(_taxid, sellerName, sellerAddress, buyerNode, items,
                opts.TipoPersoneria ?? _tipoPersoneria, _afiliacionIva,
                opts.AmountStr, opts.Observaciones, frases: effFrases);
        }

        var data = await CertifyAsync(payload, ct);
        return DteResult.FromJson(data);
    }

    private JsonObject BuildFcam(
        string sellerName, string sellerAddress, JsonObject buyerNode,
        IReadOnlyList<LineItem> items, InvoiceOptions opts, string? tf, string? es,
        IReadOnlyList<FraseItem>? frases = null)
    {
        if (opts.PaymentTerms is null or { Count: 0 })
            throw new DigifactValidationException("PaymentTerms is required for FCAM.");
        return DteBuilder.BuildFcam(_taxid, sellerName, sellerAddress, buyerNode, items,
            opts.PaymentTerms, _afiliacionIva, tipoFrase: tf, escenario: es, frases: frases);
    }

    /// <summary>Emit a CCA (Cobro por Cuenta Ajena) FACT + CCA complemento.</summary>
    public async Task<DteResult> CcaInvoiceAsync(
        BuyerDetails buyer, IReadOnlyList<LineItem> items, IReadOnlyList<CcaCobro> cobros,
        string? tipoFrase = null, string? escenario = null,
        CancellationToken ct = default)
    {
        var (sellerName, sellerAddress) = await GetSellerInfoAsync(ct);
        var buyerNode = await ResolveBuyerAsync(buyer, ct);
        IReadOnlyList<FraseItem>? effFrases;
        string? tf, es;
        if (_frases is not null && tipoFrase is null && escenario is null)
        {
            effFrases = _frases; tf = null; es = null;
        }
        else
        {
            effFrases = null;
            (tf, es) = ResolveFrase("FACT", tipoFrase, escenario);
        }
        var payload = DteBuilder.BuildCca(_taxid, sellerName, sellerAddress, buyerNode, items, cobros,
            _afiliacionIva, tipoFrase: tf, escenario: es, frases: effFrases);
        var data = await CertifyAsync(payload, ct);
        return DteResult.FromJson(data);
    }

    /// <summary>
    /// Emit a combustible (fuel) FACT invoice.
    /// <para>
    /// <see cref="FuelLineItem"/>s with <c>PetroleoAmount &gt; 0</c> receive two Tax entries
    /// (IVA + PETROLEO). Items with <c>PetroleoAmount == 0</c> are regular IVA-only items.
    /// Common <c>PetroleoCode</c> values: "1" = SUPER, "2" = REGULAR, "4" = DIESEL.
    /// </para>
    /// </summary>
    public async Task<DteResult> FuelInvoiceAsync(
        BuyerDetails buyer, IReadOnlyList<FuelLineItem> items,
        string? tipoFrase = null, string? escenario = null,
        IReadOnlyList<FraseItem>? frases = null,
        bool? autoFuelSubsidyFrases = null,
        CancellationToken ct = default)
    {
        if (frases is not null && (tipoFrase is not null || escenario is not null))
            throw new DigifactValidationException(
                "Frases and TipoFrase/Escenario are mutually exclusive; use one or the other.");

        // Resolve effective frases: per-call → constructor → legacy TipoFrase/Escenario
        IReadOnlyList<FraseItem>? effFrases;
        string? effTf, effEs;
        if (frases is not null) { effFrases = frases; effTf = null; effEs = null; }
        else if (_frases is not null) { effFrases = _frases; effTf = null; effEs = null; }
        else { effFrases = null; (effTf, effEs) = ResolveFrase("FACT", tipoFrase, escenario); }

        // Resolve auto_enabled
        bool autoEnabled;
        if (autoFuelSubsidyFrases.HasValue) autoEnabled = autoFuelSubsidyFrases.Value;
        else if (_autoFuelSubsidyFrases.HasValue) autoEnabled = _autoFuelSubsidyFrases.Value;
        else autoEnabled = true;
        if (Environment.GetEnvironmentVariable("DIGIFACT_DISABLE_AUTO_FUEL_SUBSIDY_FRASES") == "1")
            autoEnabled = false;

        var (sellerName, sellerAddress) = await GetSellerInfoAsync(ct);
        var buyerNode = await ResolveBuyerAsync(buyer, ct);
        var resolved = ApplyPetroleoRates(items);
        var payload = DteBuilder.BuildFactCombustible(_taxid, sellerName, sellerAddress, buyerNode, resolved,
            _afiliacionIva, tipoFrase: effTf, escenario: effEs,
            frases: effFrases, autoFuelSubsidyFrases: autoEnabled);
        var data = await CertifyAsync(payload, ct);
        return DteResult.FromJson(data);
    }

    private IReadOnlyList<FuelLineItem> ApplyPetroleoRates(IReadOnlyList<FuelLineItem> items)
    {
        var result = new List<FuelLineItem>(items.Count);
        foreach (var item in items)
        {
            if (!string.IsNullOrEmpty(item.PetroleoCode) && item.PetroleoAmount == 0m)
            {
                if (!_petroleoRates.TryGetValue(item.PetroleoCode, out var rate))
                    throw new DigifactValidationException(
                        $"Item '{item.Description}' has PetroleoCode='{item.PetroleoCode}' "
                        + "but no PetroleoAmount and no matching rate in PetroleoRates.");
                result.Add(item with { PetroleoAmount = rate });
            }
            else
            {
                result.Add(item);
            }
        }
        return result;
    }

    /// <summary>Emit a NCRE (Nota de Crédito).</summary>
    public async Task<DteResult> CreditNoteAsync(
        BuyerDetails buyer, IReadOnlyList<LineItem> items, OriginDoc origin, string reason,
        string? tipoFrase = null, string? escenario = null,
        CancellationToken ct = default)
    {
        var (sellerName, sellerAddress) = await GetSellerInfoAsync(ct);
        var buyerNode = await ResolveBuyerAsync(buyer, ct);
        IReadOnlyList<FraseItem>? effFrases;
        string? tf, es;
        if (_frases is not null && tipoFrase is null && escenario is null)
        {
            effFrases = _frases; tf = null; es = null;
        }
        else
        {
            effFrases = null;
            (tf, es) = ResolveFrase("NCRE", tipoFrase, escenario);
        }
        var payload = DteBuilder.BuildNcre(_taxid, sellerName, sellerAddress, buyerNode, items,
            origin, reason, _afiliacionIva, tipoFrase: tf, escenario: es, frases: effFrases);
        var data = await CertifyAsync(payload, ct);
        return DteResult.FromJson(data);
    }

    /// <summary>Emit a NDEB (Nota de Débito).</summary>
    public async Task<DteResult> DebitNoteAsync(
        BuyerDetails buyer, IReadOnlyList<LineItem> items, OriginDoc origin, string reason,
        string? tipoFrase = null, string? escenario = null,
        CancellationToken ct = default)
    {
        var (sellerName, sellerAddress) = await GetSellerInfoAsync(ct);
        var buyerNode = await ResolveBuyerAsync(buyer, ct);
        IReadOnlyList<FraseItem>? effFrases;
        string? tf, es;
        if (_frases is not null && tipoFrase is null && escenario is null)
        {
            effFrases = _frases; tf = null; es = null;
        }
        else
        {
            effFrases = null;
            (tf, es) = ResolveFrase("NDEB", tipoFrase, escenario);
        }
        var payload = DteBuilder.BuildNdeb(_taxid, sellerName, sellerAddress, buyerNode, items,
            origin, reason, _afiliacionIva, tipoFrase: tf, escenario: es, frases: effFrases);
        var data = await CertifyAsync(payload, ct);
        return DteResult.FromJson(data);
    }

    /// <summary>
    /// Cancel a DTE.
    /// </summary>
    /// <param name="authNumber">Authorization UUID of the DTE to cancel.</param>
    /// <param name="receiverId">Receiver ID (NIT/CUI or <c>"CF"</c>) of the original DTE.</param>
    /// <param name="issueDateTime">Format "YYYY-MM-DD HH:MM:SS".</param>
    /// <param name="reason">Reason for the cancellation.</param>
    /// <param name="ct">Cancellation token.</param>
    public async Task<JsonElement> CancelAsync(
        string authNumber, string receiverId, string issueDateTime,
        string reason = "Anulación", CancellationToken ct = default) =>
        await PostAsync("/CancelFelGT", new JsonObject
        {
            ["Taxid"]                       = _taxid,
            ["Autorizacion"]                = authNumber,
            ["IdReceptor"]                  = receiverId,
            ["FechaEmisionDocumentoAnular"] = issueDateTime,
            ["MotivoAnulacion"]             = reason,
            ["Username"]                    = _username,
        }, ct: ct);

    /// <summary>
    /// Create a total credit note (anulación by NCRE total).
    /// </summary>
    /// <param name="authNumber">Authorization UUID of the original DTE.</param>
    /// <param name="issueDateTime">Format "YYYY-MM-DD HH:MM:SS".</param>
    /// <param name="reason">Reason for the credit note.</param>
    /// <param name="reference">Optional internal reference.</param>
    /// <param name="ct">Cancellation token.</param>
    public async Task<JsonElement> CreditNoteTotalAsync(
        string authNumber, string issueDateTime,
        string reason = "Nota de crédito total", string reference = "",
        CancellationToken ct = default) =>
        await PostAsync("/cert/ncredtotal", new JsonObject
        {
            ["Staxid"]            = _taxid,
            ["Authnumber"]        = authNumber,
            ["FechaEmision"]      = issueDateTime,
            ["MotivoAjuste"]      = reason,
            ["ReferenciaInterna"] = reference,
            ["Formatos"]          = "xml|html|pdf",
            ["Username"]          = _username,
        }, ct: ct);

    // ── Query methods ──────────────────────────────────────────────────────────

    /// <summary>Look up a NIT via SAT's SHARED_GETINFONITcom service.</summary>
    public async Task<NitInfo> LookupNitAsync(string nit, CancellationToken ct = default)
    {
        var digits = TaxHelper.StripTaxid(nit);
        if (_nitCache.TryGetValue(digits, out var cached))
            return cached;

        var data = await GetAsync("/Shared", new Dictionary<string, string>
        {
            ["COUNTRY"]  = "GT",
            ["TAXID"]    = _paddedTaxid,
            ["DATA1"]    = "SHARED_GETINFONITcom",
            ["DATA2"]    = $"NIT|{digits}",
            ["USERNAME"] = _username,
        }, ct);

        var info = ParseNitResponse(digits, data);
        if (string.IsNullOrEmpty(info.Name))
            throw new DigifactNitNotFoundException($"NIT '{nit}' not found or returned empty name.");

        _nitCache[digits] = info;
        return info;
    }

    private static NitInfo ParseNitResponse(string nit, JsonElement data)
    {
        var empty = new NitInfo(nit, "", "CIUDAD", "01010", "GUATEMALA", "GUATEMALA");

        if (data.ValueKind == JsonValueKind.Null || data.ValueKind == JsonValueKind.Undefined)
            return empty;

        // Unwrap envelope: {"RESPONSE": [...]}
        if (data.TryGetProperty("RESPONSE", out var responseList))
        {
            if (responseList.ValueKind == JsonValueKind.Array && responseList.GetArrayLength() > 0)
                return ParseNitResponse(nit, responseList[0]);
            return empty;
        }

        // Array → recurse into first element
        if (data.ValueKind == JsonValueKind.Array)
        {
            if (data.GetArrayLength() > 0)
                return ParseNitResponse(nit, data[0]);
            return empty;
        }

        // Object row — try various casing conventions
        return new NitInfo(
            Nit: nit,
            Name: GetStr(data, "", "NOMBRE", "nombre", "Name", "name"),
            Address: GetStr(data, "CIUDAD", "Direccion", "direccion", "Address", "address"),
            City: "01010",
            District: GetStr(data, "GUATEMALA", "MUNICIPIO", "Municipio", "municipio", "district"),
            State: GetStr(data, "GUATEMALA", "DEPARTAMENTO", "Departamento", "departamento", "state")
        );
    }

    private static string GetStr(JsonElement el, params string[] keys)
        => GetStr(el, "", keys);

    private static string GetStr(JsonElement el, string fallback, params string[] keys)
    {
        foreach (var key in keys)
            if (el.TryGetProperty(key, out var v) && v.ValueKind == JsonValueKind.String)
            {
                var s = v.GetString()?.Trim() ?? "";
                if (!string.IsNullOrEmpty(s))
                    return s;
            }
        return fallback;
    }

    /// <summary>Get DTE info via SHARED_GETDTEINFO.</summary>
    public Task<JsonElement> GetDteInfoAsync(string authNumber, CancellationToken ct = default) =>
        GetAsync("/Shared", new Dictionary<string, string>
        {
            ["COUNTRY"]  = "GT",
            ["TAXID"]    = _paddedTaxid,
            ["DATA1"]    = "SHARED_GETDTEINFO",
            ["DATA2"]    = $"AUTHNUMBER|{authNumber}",
            ["USERNAME"] = _username,
        }, ct);

    /// <summary>Retrieve a DTE document.</summary>
    public Task<JsonElement> GetDteAsync(string authNumber, string format = "JSON", CancellationToken ct = default) =>
        GetAsync("/GetDocument", new Dictionary<string, string>
        {
            ["AUTHNUMBER"] = authNumber,
            ["TAXID"]      = _paddedTaxid,
            ["FORMAT"]     = format,
            ["USERNAME"]   = _username,
        }, ct);

    // ── IDisposable ────────────────────────────────────────────────────────────

    /// <inheritdoc/>
    public void Dispose()
    {
        if (_ownsHttpClient)
            _http.Dispose();
    }
}
