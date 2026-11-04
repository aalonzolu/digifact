using System;
using System.Collections.Generic;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Threading;
using System.Threading.Tasks;
using System.Web;
using Digifact.Fel;
using Xunit;

namespace Digifact.Fel.Tests;

internal sealed record SentRequest(HttpMethod Method, Uri Uri, string? Authorization, string? Body)
{
    public JsonObject Json => JsonNode.Parse(Body!)!.AsObject();

    public string Query(string name) => HttpUtility.ParseQueryString(Uri.Query)[name]!;
}

internal sealed class FakeDigifact : HttpMessageHandler
{
    private readonly List<(HttpMethod Method, string Path, Queue<(int Status, string Body)> Responses)> _routes = new();

    public List<SentRequest> Calls { get; } = new();

    public FakeDigifact On(HttpMethod method, string path, params (int Status, object Body)[] responses)
    {
        var queue = new Queue<(int, string)>(responses.Select(r => (r.Status, JsonSerializer.Serialize(r.Body))));
        _routes.Add((method, path, queue));
        return this;
    }

    public List<SentRequest> CallsTo(HttpMethod method, string path) =>
        Calls.Where(call => call.Method == method && call.Uri.AbsolutePath.EndsWith(path)).ToList();

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
    {
        var body = request.Content is null ? null : await request.Content.ReadAsStringAsync(ct);
        Calls.Add(new SentRequest(request.Method, request.RequestUri!, request.Headers.Authorization?.ToString(), body));

        foreach (var (method, path, responses) in _routes)
        {
            if (method != request.Method || !request.RequestUri!.AbsolutePath.EndsWith(path))
                continue;
            var (status, json) = responses.Count > 1 ? responses.Dequeue() : responses.Peek();
            return new HttpResponseMessage((HttpStatusCode)status)
            {
                Content = new StringContent(json, Encoding.UTF8, "application/json"),
            };
        }
        throw new InvalidOperationException($"Unexpected {request.Method} {request.RequestUri}");
    }
}

internal static class Resilience
{
    public const string Reference = "0F8FAD5B-D9CB-469F-A165-70867728950E";
    public const string Certify = "/v2/transform/nuc_json";
    public const string Login = "/login/get_token";

    public static readonly LineItem[] Items = { new() { Description = "Servicio", Qty = 1, Price = 100m } };

    public static readonly OriginDoc Origin = new("AAAABBBB-1111-2222-3333-444455556666", "2026-10-01", "AAAABBBB", "1");

    public static readonly object Certified = new Dictionary<string, object>
    {
        ["code"] = 1,
        ["authNumber"] = "CCCCDDDD-1111-2222-3333-444455556666",
        ["batch"] = "CCCCDDDD",
        ["serial"] = 123,
        ["issuedTimeStamp"] = "2026-11-03T10:00:00",
        ["enrolledTimeStamp"] = "2026-11-03T10:00:02",
        ["additionalInfo"] = new Dictionary<string, string> { ["acuseReciboSAT"] = "ACEPTADO" },
    };

    public static DigifactClient AClient(FakeDigifact fake, string password = "secreto", string token = "token-vigente") =>
        new(new DigifactOptions
        {
            Taxid = "12345678",
            Username = "FELUSER",
            Password = password,
            Token = token,
            SellerName = "EMPRESA DEMO, SOCIEDAD ANONIMA",
            SellerAddress = "CIUDAD",
        }, new HttpClient(fake));

    public static List<JsonObject> AdendaOf(JsonObject payload) =>
        Entries(payload).Where(entry => (string?)entry["Type"] == "ADENDA").ToList();

    public static List<JsonObject> Entries(JsonObject payload) =>
        payload["AdditionalDocumentInfo"]!["AdditionalInfo"]!.AsArray().Select(entry => entry!.AsObject()).ToList();

    public static List<string?> ReferenceFlags(JsonObject adenda) =>
        adenda["AditionalInfo"]!.AsArray()
            .Where(info => (string?)info!["Name"] == "VALIDAR_REFERENCIA_INTERNA")
            .Select(info => (string?)info!["Value"])
            .ToList();
}

public class InternalReferenceTests
{
    private readonly FakeDigifact _fake = new FakeDigifact().On(HttpMethod.Post, Resilience.Certify, (200, Resilience.Certified));

    private JsonObject SentPayload() => _fake.CallsTo(HttpMethod.Post, Resilience.Certify).Last().Json;

    [Fact]
    public async Task Invoice_CarriesTheReferenceWithValidation()
    {
        using var client = Resilience.AClient(_fake);

        await client.InvoiceAsync("CF", Resilience.Items, new InvoiceOptions
        {
            InternalReference = Resilience.Reference,
            ValidateInternalReference = true,
        });

        var adenda = Assert.Single(Resilience.AdendaOf(SentPayload()));
        Assert.Equal(Resilience.Reference, (string?)adenda["Code"]);
        Assert.Equal(new[] { "VALIDAR" }, Resilience.ReferenceFlags(adenda));
        Assert.Equal("VALIDAR_REFERENCIA_INTERNA", (string?)adenda["AditionalInfo"]![0]!["Name"]);
        Assert.True(adenda.ContainsKey("AditionalData"));
    }

    [Fact]
    public async Task ReferenceWithoutValidation_IsOnlyStored()
    {
        using var client = Resilience.AClient(_fake);

        await client.InvoiceAsync("CF", Resilience.Items, new InvoiceOptions { InternalReference = Resilience.Reference });

        var adenda = Assert.Single(Resilience.AdendaOf(SentPayload()));
        Assert.Equal(Resilience.Reference, (string?)adenda["Code"]);
        Assert.Equal(new[] { "NO_VALIDAR" }, Resilience.ReferenceFlags(adenda));
    }

    [Fact]
    public async Task WithoutReference_ThePayloadIsUnchanged()
    {
        using var client = Resilience.AClient(_fake);

        await client.InvoiceAsync("CF", Resilience.Items);

        var adenda = Assert.Single(Resilience.AdendaOf(SentPayload()));
        Assert.Equal(DteBuilder.AdendaCodeStd, (string?)adenda["Code"]);
        Assert.Equal(new[] { "NO_VALIDAR" }, Resilience.ReferenceFlags(adenda));
    }

    [Fact]
    public async Task CreditNote_GetsAnAdendaNextToItsComplement()
    {
        using var client = Resilience.AClient(_fake);

        await client.CreditNoteAsync("CF", Resilience.Items, Resilience.Origin, "Devolución",
            new InternalReferenceOptions { InternalReference = Resilience.Reference, ValidateInternalReference = true });

        var entries = Resilience.Entries(SentPayload());
        Assert.Equal(new[] { "COMPLEMENTO", "ADENDA" }, entries.Select(entry => (string?)entry["Type"]));
        Assert.Equal(Resilience.Reference, (string?)entries[1]["Code"]);
        Assert.Equal(new[] { "VALIDAR" }, Resilience.ReferenceFlags(entries[1]));
    }

    [Fact]
    public async Task CreditNote_WithoutReference_KeepsOnlyItsComplement()
    {
        using var client = Resilience.AClient(_fake);

        await client.CreditNoteAsync("CF", Resilience.Items, Resilience.Origin, "Devolución", "1", "1", CancellationToken.None);

        var entry = Assert.Single(Resilience.Entries(SentPayload()));
        Assert.Equal("COMPLEMENTO", (string?)entry["Type"]);
    }

    [Fact]
    public async Task OtherEmissionMethods_CarryTheReference()
    {
        using var client = Resilience.AClient(_fake);
        var reference = new InternalReferenceOptions { InternalReference = Resilience.Reference };
        var cobro = new CcaCobro { NitTercero = "12345678", NumeroDocumento = "1", FechaDocumento = "2026-11-03", Descripcion = "DAI" };
        var fuel = new FuelLineItem { Description = "SUPER", Qty = 1m, Price = 35m, PetroleoAmount = 4.70m, PetroleoCode = "1" };

        await client.CcaInvoiceAsync("CF", Resilience.Items, new[] { cobro }, reference);
        await client.FuelInvoiceAsync("CF", new[] { fuel }, reference);
        await client.DebitNoteAsync("CF", Resilience.Items, Resilience.Origin, "Cargo", reference);

        var codes = _fake.CallsTo(HttpMethod.Post, Resilience.Certify)
            .Select(call => (string?)Assert.Single(Resilience.AdendaOf(call.Json))["Code"]);
        Assert.Equal(new[] { Resilience.Reference, Resilience.Reference, Resilience.Reference }, codes);
    }

    [Fact]
    public async Task Validation_NeedsAReference()
    {
        using var client = Resilience.AClient(_fake);

        await Assert.ThrowsAsync<ArgumentException>(() =>
            client.InvoiceAsync("CF", Resilience.Items, new InvoiceOptions { ValidateInternalReference = true }));
        Assert.Empty(_fake.Calls);
    }

    [Fact]
    public async Task Result_ExposesEnrollmentAndAdditionalInfo()
    {
        using var client = Resilience.AClient(_fake);

        var result = await client.InvoiceAsync("CF", Resilience.Items);

        Assert.Equal("2026-11-03T10:00:02", result.EnrolledTimestamp);
        Assert.Equal("ACEPTADO", result.AdditionalInfo.GetProperty("acuseReciboSAT").GetString());
    }

    [Fact]
    public void Result_DefaultsWhenSatFieldsAreMissing()
    {
        var result = DteResult.FromJson(JsonDocument.Parse("""{"authNumber":"UUID-1"}""").RootElement);

        Assert.Equal("", result.EnrolledTimestamp);
        Assert.Equal(JsonValueKind.Object, result.AdditionalInfo.ValueKind);
        Assert.Empty(result.AdditionalInfo.EnumerateObject());
    }
}

public class DuplicateReferenceTests
{
    private static async Task CertifyAnswering(object body)
    {
        var fake = new FakeDigifact().On(HttpMethod.Post, Resilience.Certify, (200, body));
        using var client = Resilience.AClient(fake);
        await client.InvoiceAsync("CF", Resilience.Items, new InvoiceOptions
        {
            InternalReference = Resilience.Reference,
            ValidateInternalReference = true,
        });
    }

    [Fact]
    public async Task Code9022_IsADuplicateReference()
    {
        var error = await Assert.ThrowsAsync<DigifactDuplicateReferenceException>(() =>
            CertifyAnswering(new { code = 9022, description = "Referencia interna duplicada" }));
        Assert.False(error.Retryable);
        Assert.Equal(9022, error.ApiCode);
    }

    [Fact]
    public async Task Code9022AsString_IsADuplicateReference()
    {
        await Assert.ThrowsAsync<DigifactDuplicateReferenceException>(() =>
            CertifyAnswering(new { code = "9022", description = "Referencia interna duplicada" }));
    }

    [Fact]
    public async Task RejectionMessageAboutAnAlreadySentReference_IsADuplicate()
    {
        await Assert.ThrowsAsync<DigifactDuplicateReferenceException>(() =>
            CertifyAnswering(new { code = 0, description = "La referencia interna ya ha sido enviada a certificar" }));
    }

    [Fact]
    public async Task GlobalErrorCodeBus003_IsADuplicate()
    {
        await Assert.ThrowsAsync<DigifactDuplicateReferenceException>(() =>
            CertifyAnswering(new { code = 0, codeError = "BUS-003", description = "Rechazado" }));
    }

    [Fact]
    public async Task TheResponseDigifactActuallySends_IsADuplicate()
    {
        await Assert.ThrowsAsync<DigifactDuplicateReferenceException>(() =>
            CertifyAnswering(new
            {
                code = 3000,
                message = "La referencia INTERNA, ya ha sido enviada a certificar",
                description = "[9022] - El documento bajo la referencia 0F8FAD5B-D9CB-469F-A165-70867728950E ya ha sido registrado bajo el UUID CCCCDDDD-1111-2222-3333-444455556666",
            }));
    }

    [Fact]
    public async Task NonNumericCode_IsAnApiError()
    {
        await Assert.ThrowsAsync<DigifactApiException>(() =>
            CertifyAnswering(new { code = "Otro", description = "?" }));
    }
}

public class TokenRenewalTests
{
    [Fact]
    public async Task ExpiredToken_LogsInAgainAndRetriesOnce()
    {
        var fake = new FakeDigifact()
            .On(HttpMethod.Post, Resilience.Certify, (401, new { }), (200, Resilience.Certified))
            .On(HttpMethod.Post, Resilience.Login, (200, new { Token = "token-nuevo" }));
        using var client = Resilience.AClient(fake, token: "token-vencido");

        var result = await client.InvoiceAsync("CF", Resilience.Items);

        var certifyCalls = fake.CallsTo(HttpMethod.Post, Resilience.Certify);
        Assert.Equal("CCCCDDDD-1111-2222-3333-444455556666", result.AuthNumber);
        Assert.Equal(new[] { "token-vencido", "token-nuevo" }, certifyCalls.Select(call => call.Authorization));
        Assert.Equal(certifyCalls[0].Body, certifyCalls[1].Body);
        Assert.Equal("token-nuevo", client.Token);
    }

    [Fact]
    public async Task TokenOnlyClient_ReportsTheExpiredToken()
    {
        var fake = new FakeDigifact().On(HttpMethod.Get, "/Shared", (401, new { }));
        using var client = Resilience.AClient(fake, password: "", token: "token-vencido");

        var error = await Assert.ThrowsAsync<DigifactAuthException>(() =>
            client.GetDteInfoAsync("CCCCDDDD-1111-2222-3333-444455556666"));
        Assert.Equal(401, error.ApiCode);
        Assert.Empty(fake.CallsTo(HttpMethod.Post, Resilience.Login));
    }

    [Fact]
    public async Task StillUnauthorizedAfterLogin_IsAnAuthError()
    {
        var fake = new FakeDigifact()
            .On(HttpMethod.Post, Resilience.Certify, (401, new { }))
            .On(HttpMethod.Post, Resilience.Login, (200, new { Token = "token-nuevo" }));
        using var client = Resilience.AClient(fake);

        await Assert.ThrowsAsync<DigifactAuthException>(() => client.InvoiceAsync("CF", Resilience.Items));
        Assert.Equal(2, fake.CallsTo(HttpMethod.Post, Resilience.Certify).Count);
        Assert.Single(fake.CallsTo(HttpMethod.Post, Resilience.Login));
    }

    [Fact]
    public void Token_IsEmptyBeforeTheFirstLogin()
    {
        using var client = Resilience.AClient(new FakeDigifact(), token: "");

        Assert.Equal("", client.Token);
    }
}

public class ErrorClassificationTests
{
    [Fact]
    public void Exceptions_AreNotRetryableUnlessSaidSo()
    {
        Assert.False(new DigifactApiException("error", 400, "{}").Retryable);
        Assert.True(new DigifactApiException("error", 503, "{}", retryable: true).Retryable);
    }

    private static async Task<DigifactApiException> CertifyWithStatus(int status)
    {
        var fake = new FakeDigifact().On(HttpMethod.Post, Resilience.Certify, (status, new { message = "error" }));
        using var client = Resilience.AClient(fake);
        return await Assert.ThrowsAsync<DigifactApiException>(() => client.InvoiceAsync("CF", Resilience.Items));
    }

    [Fact]
    public async Task ServerErrors_AreRetryable()
    {
        var error = await CertifyWithStatus(503);

        Assert.True(error.Retryable);
        Assert.Equal(503, error.ApiCode);
    }

    [Theory]
    [InlineData(400)]
    [InlineData(406)]
    [InlineData(415)]
    public async Task InvalidDocuments_AreNotRetryable(int status)
    {
        var error = await CertifyWithStatus(status);

        Assert.False(error.Retryable);
        Assert.Equal(status, error.ApiCode);
    }
}

public class FindByInternalReferenceTests
{
    [Fact]
    public async Task QueriesSharedByInternalReferenceAndDay()
    {
        var rows = new[] { new { GUID = "CCCCDDDD-1111-2222-3333-444455556666", SERIE = "CCCCDDDD", NUMERO = "123" } };
        var fake = new FakeDigifact().On(HttpMethod.Get, "/Shared", (200, new { RESPONSE = rows }));
        using var client = Resilience.AClient(fake);

        var found = await client.FindByInternalReferenceAsync(Resilience.Reference, new DateOnly(2026, 11, 3));

        var call = fake.CallsTo(HttpMethod.Get, "/Shared").Last();
        var row = Assert.Single(found);
        Assert.Equal("CCCCDDDD-1111-2222-3333-444455556666", row.GetProperty("GUID").GetString());
        Assert.Equal("123", row.GetProperty("NUMERO").GetString());
        Assert.Equal("GT", call.Query("COUNTRY"));
        Assert.Equal("000012345678", call.Query("TAXID"));
        Assert.Equal("FELUSER", call.Query("USERNAME"));
        Assert.Equal("SHARED_GETDTEINFO_BY_INTERNALID", call.Query("DATA1"));
        Assert.Equal($"REFERENCIA_INTERNA|{Resilience.Reference}|ISSUEDDATE|2026-11-03", call.Query("DATA2"));
    }

    [Fact]
    public async Task NoDteWithThatReference_IsAnEmptyList()
    {
        var fake = new FakeDigifact().On(HttpMethod.Get, "/Shared", (200, new { Respuesta = 0, RESPONSE = Array.Empty<object>() }));
        using var client = Resilience.AClient(fake);

        Assert.Empty(await client.FindByInternalReferenceAsync(Resilience.Reference, "2026-11-03"));
    }
}
