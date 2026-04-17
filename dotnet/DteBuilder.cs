using System.Globalization;
using System.Text.Json.Nodes;

namespace Digifact.Fel;

/// <summary>
/// Builds JSON payloads for all supported Digifact FEL document types.
///
/// Key intentional API typos preserved from the SAT/Digifact spec:
///   - Seller.AdditionlInfo  (missing 'a' in Additional)
///   - AditionalData         (missing 'd')
///   - AditionalInfo         (missing 'd')
/// </summary>
internal static class DteBuilder
{
    private static readonly HashSet<string> NoIvaTypes =
        new(StringComparer.OrdinalIgnoreCase) { "FPEQ", "NABN", "RDON", "RECI" };

    private static readonly HashSet<string> AltAdendaTypes =
        new(StringComparer.OrdinalIgnoreCase) { "NABN", "RDON", "RECI" };

    private const string AdendaCodeStd = "FRONT-263C-444B-89BA-6F87EC1330C0";
    private const string AdendaCodeAlt = "FRONT-67C1-4545-BA1E-AA3C115E18D6";

    private static string GetAdendaCode(string docType) =>
        AltAdendaTypes.Contains(docType) ? AdendaCodeAlt : AdendaCodeStd;

    // ── Frase defaults (TipoFrase / CodigoEscenario) ──────────────────────────

    /// <summary>
    /// Default (TipoFrase, CodigoEscenario) for a (docType, afiliacion) combo.
    /// Returns null when the DTE must not carry an AdditionlInfo block (e.g. FESP).
    /// </summary>
    /// <remarks>
    /// GEN defaults assume ISR régimen opcional (CodigoEscenario "2"). For ISR
    /// régimen sobre utilidades trimestrales, override Escenario to "1".
    /// </remarks>
    public static (string TipoFrase, string Escenario)? DefaultFrase(string docType, string afiliacion = "GEN")
    {
        var dt = (docType ?? "").ToUpperInvariant();
        var af = (afiliacion ?? "GEN").ToUpperInvariant();
        return dt switch
        {
            "FESP" => null,
            "FPEQ" => ("2", "1"),
            "RDON" => ("4", "4"),
            "RECI" => ("4", "5"),
            "NABN" => ("1", "1"),
            _ => af switch
            {
                "PEQ" => ("2", "1"),
                "EXE" => ("4", "1"),
                _     => ("1", "2"), // GEN — ISR opcional (most common). Override to "1" for ISR trimestral.
            },
        };
    }

    private static (string? Tf, string? Es) ResolveFrase(
        string docType, string afiliacion, string? tipoFrase, string? escenario)
    {
        var def = DefaultFrase(docType, afiliacion);
        string? defTf = def?.TipoFrase;
        string? defEs = def?.Escenario;
        return (tipoFrase ?? defTf, escenario ?? defEs);
    }

    // ── Buyer helpers ─────────────────────────────────────────────────────────

    internal static JsonObject BuyerCf() => new()
    {
        ["TaxID"] = "CF",
        ["Name"] = "CONSUMIDOR FINAL",
        ["AddressInfo"] = new JsonObject
        {
            ["Address"] = "CIUDAD",
            ["City"] = "01010",
            ["District"] = "GUATEMALA",
            ["State"] = "GUATEMALA",
            ["Country"] = "GT",
        },
    };

    internal static JsonObject BuyerNit(
        string nit, string name,
        string address = "CIUDAD", string city = "01010",
        string district = "GUATEMALA", string state = "GUATEMALA",
        string country = "GT", string? email = null)
    {
        var buyer = new JsonObject
        {
            ["TaxID"] = nit,
            ["Name"] = name,
            ["AddressInfo"] = new JsonObject
            {
                ["Address"] = address,
                ["City"] = city,
                ["District"] = district,
                ["State"] = state,
                ["Country"] = country,
            },
        };
        if (email is not null)
            buyer["Contact"] = new JsonObject
            {
                ["EmailList"] = new JsonObject { ["Email"] = new JsonArray { (JsonNode)email } },
            };
        return buyer;
    }

    internal static JsonObject BuyerCui(string taxid, string name) => new()
    {
        ["TaxID"] = taxid,
        ["TaxIDType"] = "CUI",
        ["Name"] = name,
        ["AddressInfo"] = new JsonObject
        {
            ["Address"] = "CIUDAD",
            ["City"] = "01010",
            ["District"] = "GUATEMALA",
            ["State"] = "GUATEMALA",
            ["Country"] = "GT",
        },
    };

    // ── Seller builder ────────────────────────────────────────────────────────

    private static JsonObject BuildSeller(
        string taxid, string name, string address,
        string afiliacion = "GEN",
        string? tipoFrase = "1", string? escenario = "1",
        string branchCode = "1", string branchName = "ESTABLECIMIENTO PRINCIPAL",
        string city = "01010", string district = "Guatemala", string state = "Guatemala",
        string country = "GT", string? email = null)
    {
        var seller = new JsonObject
        {
            ["TaxID"] = taxid,
            ["TaxIDAdditionalInfo"] = new JsonArray
            {
                new JsonObject
                {
                    ["Name"] = "AfiliacionIVA",
                    ["Data"] = (JsonNode?)null,
                    ["Value"] = afiliacion,
                },
            },
            ["Name"] = name,
            ["BranchInfo"] = new JsonObject
            {
                ["Code"] = branchCode,
                ["Name"] = branchName,
                ["AddressInfo"] = new JsonObject
                {
                    ["Address"] = address,
                    ["City"] = city,
                    ["District"] = district,
                    ["State"] = state,
                    ["Country"] = country,
                },
            },
        };

        if (email is not null)
            seller["Contact"] = new JsonObject
            {
                ["EmailList"] = new JsonObject { ["Email"] = new JsonArray { (JsonNode)email } },
            };

        // AdditionlInfo — intentional typo per SAT/Digifact spec
        if (tipoFrase is not null && escenario is not null)
            seller["AdditionlInfo"] = new JsonArray
            {
                new JsonObject { ["Name"] = "TipoFrase", ["Data"] = "1", ["Value"] = tipoFrase },
                new JsonObject { ["Name"] = "Escenario",  ["Data"] = "1", ["Value"] = escenario },
            };

        return seller;
    }

    // ── Items builder ─────────────────────────────────────────────────────────

    private static (JsonArray Items, string GrandTotal, string TotalIva) BuildItems(
        IReadOnlyList<LineItem> items, bool taxable)
    {
        var arr = new JsonArray();
        decimal grandTotal = 0m;
        decimal totalIva = 0m;

        for (int i = 0; i < items.Count; i++)
        {
            var item = items[i];
            var calc = TaxHelper.CalcLine(item.Qty, item.Price, taxable, item.Discount);

            var built = new JsonObject
            {
                ["Number"] = (i + 1).ToString(),
                ["Codes"] = (JsonNode?)null,
                ["Type"] = item.Type,
                ["Description"] = item.Description,
                ["Qty"] = calc.Qty,
                ["UnitOfMeasure"] = item.UnitOfMeasure,
                ["Price"] = calc.Price,
            };

            built["Discounts"] = item.Discount > 0m
                ? new JsonObject
                {
                    ["Discount"] = new JsonArray
                    {
                        new JsonObject { ["Amount"] = calc.Discount },
                    },
                }
                : (JsonNode?)null;

            built["Taxes"] = taxable
                ? new JsonObject
                {
                    ["Tax"] = new JsonArray
                    {
                        new JsonObject
                        {
                            ["Code"] = "1",
                            ["Description"] = "IVA",
                            ["TaxableAmount"] = calc.Taxable,
                            ["Amount"] = calc.Iva,
                        },
                    },
                }
                : (JsonNode?)null;

            built["Totals"] = new JsonObject { ["TotalItem"] = calc.LineTotal };

            arr.Add(built);
            grandTotal += decimal.Parse(calc.LineTotal, CultureInfo.InvariantCulture);
            totalIva += decimal.Parse(calc.Iva, CultureInfo.InvariantCulture);
        }

        return (arr, TaxHelper.Fmt(grandTotal), TaxHelper.Fmt(totalIva));
    }

    private static JsonObject BuildTotals(string grandTotal, string totalIva, bool taxable)
    {
        var totals = new JsonObject();
        if (taxable)
            totals["TotalTaxes"] = new JsonObject
            {
                ["TotalTax"] = new JsonArray
                {
                    new JsonObject { ["Description"] = "IVA", ["Amount"] = totalIva },
                },
            };
        totals["GrandTotal"] = new JsonObject { ["InvoiceTotal"] = grandTotal };
        return totals;
    }

    private static JsonObject BuildAdenda(
        string docType, string amountStr, int numItems,
        string observaciones = "-",
        IReadOnlyList<JsonObject>? extraAditionalInfo = null)
    {
        var code = GetAdendaCode(docType);

        var data = new JsonArray
        {
            new JsonObject
            {
                ["Name"] = "INFORMACION_ADICIONAL",
                ["Info"] = new JsonArray
                {
                    new JsonObject { ["Name"] = "OBSERVACIONES",  ["Data"] = (JsonNode?)null, ["Value"] = observaciones },
                    new JsonObject { ["Name"] = "CANTIDAD_LETRAS", ["Data"] = (JsonNode?)null, ["Value"] = amountStr },
                },
            },
        };

        for (int i = 1; i <= numItems; i++)
            data.Add(new JsonObject
            {
                ["Name"] = "DetallesAux_Detalle",
                ["Info"] = new JsonArray
                {
                    new JsonObject { ["Name"] = "NumeroLinea",          ["Data"] = (JsonNode?)null, ["Value"] = i.ToString() },
                    new JsonObject { ["Name"] = "Descripcion_Adicional", ["Data"] = (JsonNode?)null, ["Value"] = "-" },
                    new JsonObject { ["Name"] = "CodigoEAN",             ["Data"] = (JsonNode?)null, ["Value"] = "00001" },
                    new JsonObject { ["Name"] = "CategoriaAdicional",    ["Data"] = (JsonNode?)null, ["Value"] = "-" },
                },
            });

        var aditionalInfo = new JsonArray
        {
            new JsonObject { ["Name"] = "VALIDAR_REFERENCIA_INTERNA", ["Data"] = (JsonNode?)null, ["Value"] = "NO_VALIDAR" },
        };

        if (extraAditionalInfo is not null)
            foreach (var entry in extraAditionalInfo)
                aditionalInfo.Add(JsonNode.Parse(entry.ToJsonString()));

        return new JsonObject
        {
            ["AdditionalInfo"] = new JsonArray
            {
                new JsonObject
                {
                    ["Code"] = code,
                    ["Type"] = "ADENDA",
                    ["AditionalData"] = new JsonObject { ["Data"] = data },
                    ["AditionalInfo"] = aditionalInfo,
                },
            },
        };
    }

    // ── Public builders ───────────────────────────────────────────────────────

    internal static JsonObject BuildFact(
        string taxid, string sellerName, string sellerAddress, JsonObject buyer,
        IReadOnlyList<LineItem> items, string docType = "FACT",
        string afiliacion = "GEN", string? tipoFrase = null, string? escenario = null,
        string amountStr = "", string observaciones = "-", string? sellerEmail = null)
    {
        var (isoNow, _, _) = TaxHelper.GtNow();
        bool taxable = !NoIvaTypes.Contains(docType);
        var (lineItems, grandTotal, totalIva) = BuildItems(items, taxable);
        var (tf, es) = ResolveFrase(docType, afiliacion, tipoFrase, escenario);
        var seller = BuildSeller(taxid, sellerName, sellerAddress, afiliacion, tf, es, email: sellerEmail);
        var amt = string.IsNullOrEmpty(amountStr) ? grandTotal : amountStr;

        return new JsonObject
        {
            ["Version"] = "1.00",
            ["CountryCode"] = "GT",
            ["Header"] = new JsonObject
            {
                ["DocType"] = docType,
                ["IssuedDateTime"] = isoNow,
                ["Currency"] = "GTQ",
            },
            ["Seller"] = seller,
            ["Buyer"] = buyer,
            ["ThirdParties"] = (JsonNode?)null,
            ["Items"] = lineItems,
            ["Totals"] = BuildTotals(grandTotal, totalIva, taxable),
            ["AdditionalDocumentInfo"] = BuildAdenda(docType, amt, items.Count, observaciones),
        };
    }

    internal static JsonObject BuildFcam(
        string taxid, string sellerName, string sellerAddress, JsonObject buyer,
        IReadOnlyList<LineItem> items, IReadOnlyList<PaymentTerm> paymentTerms,
        string afiliacion = "GEN", string? sellerEmail = null,
        string? tipoFrase = null, string? escenario = null)
    {
        var (isoNow, _, _) = TaxHelper.GtNow();
        var (lineItems, grandTotal, totalIva) = BuildItems(items, true);
        var (tf, es) = ResolveFrase("FCAM", afiliacion, tipoFrase, escenario);
        var seller = BuildSeller(taxid, sellerName, sellerAddress, afiliacion, tf, es, email: sellerEmail);

        var fcamData = new JsonArray();
        for (int idx = 0; idx < paymentTerms.Count; idx++)
        {
            var pt = paymentTerms[idx];
            fcamData.Add(new JsonObject
            {
                ["Info"] = new JsonArray
                {
                    new JsonObject { ["Name"] = "NumeroAbono",      ["Data"] = (JsonNode?)null, ["Value"] = (idx + 1).ToString() },
                    new JsonObject { ["Name"] = "FechaVencimiento", ["Data"] = (JsonNode?)null, ["Value"] = pt.Date },
                    new JsonObject { ["Name"] = "MontoAbono",       ["Data"] = (JsonNode?)null, ["Value"] = TaxHelper.Fmt(pt.Amount, 2) },
                },
            });
        }

        return new JsonObject
        {
            ["Version"] = "1.00",
            ["CountryCode"] = "GT",
            ["Header"] = new JsonObject { ["DocType"] = "FCAM", ["IssuedDateTime"] = isoNow, ["Currency"] = "GTQ" },
            ["Seller"] = seller,
            ["Buyer"] = buyer,
            ["ThirdParties"] = (JsonNode?)null,
            ["Items"] = lineItems,
            ["Totals"] = BuildTotals(grandTotal, totalIva, true),
            ["AdditionalDocumentInfo"] = new JsonObject
            {
                ["AdditionalInfo"] = new JsonArray
                {
                    new JsonObject
                    {
                        ["Code"] = "FCAMB",
                        ["Type"] = "COMPLEMENTO",
                        ["AditionalData"] = new JsonObject { ["Data"] = fcamData },
                    },
                },
            },
        };
    }

    internal static JsonObject BuildNdeb(
        string taxid, string sellerName, string sellerAddress, JsonObject buyer,
        IReadOnlyList<LineItem> items, OriginDoc origin, string reason,
        string afiliacion = "GEN", string? sellerEmail = null,
        string? tipoFrase = null, string? escenario = null)
    {
        var (isoNow, _, _) = TaxHelper.GtNow();
        var (lineItems, grandTotal, totalIva) = BuildItems(items, true);
        var (tf, es) = ResolveFrase("NDEB", afiliacion, tipoFrase, escenario);
        var seller = BuildSeller(taxid, sellerName, sellerAddress, afiliacion, tf, es, email: sellerEmail);

        return new JsonObject
        {
            ["Version"] = "1.00",
            ["CountryCode"] = "GT",
            ["Header"] = new JsonObject { ["DocType"] = "NDEB", ["IssuedDateTime"] = isoNow, ["Currency"] = "GTQ" },
            ["Seller"] = seller,
            ["Buyer"] = buyer,
            ["ThirdParties"] = (JsonNode?)null,
            ["Items"] = lineItems,
            ["Totals"] = BuildTotals(grandTotal, totalIva, true),
            ["AdditionalDocumentInfo"] = new JsonObject
            {
                ["AdditionalInfo"] = new JsonArray
                {
                    new JsonObject
                    {
                        ["Code"] = "NDEB",
                        ["Type"] = "COMPLEMENTO",
                        ["AditionalInfo"] = new JsonArray
                        {
                            new JsonObject { ["Name"] = "NumeroAutorizacionDocumentoOrigen", ["Data"] = (JsonNode?)null, ["Value"] = origin.AuthNumber },
                            new JsonObject { ["Name"] = "FechaEmisionDocumentoOrigen",       ["Data"] = (JsonNode?)null, ["Value"] = origin.Date },
                            new JsonObject { ["Name"] = "MotivoAjuste",                      ["Data"] = (JsonNode?)null, ["Value"] = reason },
                            new JsonObject { ["Name"] = "SerieDocumentoOrigen",              ["Data"] = (JsonNode?)null, ["Value"] = origin.Series },
                            new JsonObject { ["Name"] = "NumeroDocumentoOrigen",             ["Data"] = (JsonNode?)null, ["Value"] = origin.Number },
                        },
                    },
                },
            },
        };
    }

    internal static JsonObject BuildNcre(
        string taxid, string sellerName, string sellerAddress, JsonObject buyer,
        IReadOnlyList<LineItem> items, OriginDoc origin, string reason,
        string afiliacion = "GEN", string? sellerEmail = null,
        string? tipoFrase = null, string? escenario = null)
    {
        var (isoNow, _, _) = TaxHelper.GtNow();
        var (lineItems, grandTotal, totalIva) = BuildItems(items, true);
        var (tf, es) = ResolveFrase("NCRE", afiliacion, tipoFrase, escenario);
        var seller = BuildSeller(taxid, sellerName, sellerAddress, afiliacion, tf, es, email: sellerEmail);

        return new JsonObject
        {
            ["Version"] = "1.00",
            ["CountryCode"] = "GT",
            ["Header"] = new JsonObject { ["DocType"] = "NCRE", ["IssuedDateTime"] = isoNow, ["Currency"] = "GTQ" },
            ["Seller"] = seller,
            ["Buyer"] = buyer,
            ["ThirdParties"] = (JsonNode?)null,
            ["Items"] = lineItems,
            ["Totals"] = BuildTotals(grandTotal, totalIva, true),
            ["AdditionalDocumentInfo"] = new JsonObject
            {
                ["AdditionalInfo"] = new JsonArray
                {
                    new JsonObject
                    {
                        ["Code"] = "NCRE",
                        ["Type"] = "COMPLEMENTO",
                        ["AditionalInfo"] = new JsonArray
                        {
                            new JsonObject { ["Name"] = "NumeroAutorizacionDocumentoOrigen", ["Data"] = (JsonNode?)null, ["Value"] = origin.AuthNumber },
                            new JsonObject { ["Name"] = "FechaEmisionDocumentoOrigen",       ["Data"] = (JsonNode?)null, ["Value"] = origin.Date },
                            new JsonObject { ["Name"] = "MotivoAjuste",                      ["Data"] = (JsonNode?)null, ["Value"] = reason },
                            new JsonObject { ["Name"] = "NumeroDocumentoOrigen",             ["Data"] = (JsonNode?)null, ["Value"] = origin.Number },
                            new JsonObject { ["Name"] = "SerieDocumentoOrigen",              ["Data"] = (JsonNode?)null, ["Value"] = origin.Series },
                        },
                    },
                },
            },
        };
    }

    internal static JsonObject BuildFesp(
        string taxid, string sellerName, string sellerAddress, JsonObject buyer,
        IReadOnlyList<LineItem> items, string afiliacion = "GEN", string? sellerEmail = null)
    {
        var (isoNow, _, _) = TaxHelper.GtNow();
        var (lineItems, grandTotal, totalIva) = BuildItems(items, true);
        // FESP: no AdditionlInfo in seller
        var seller = BuildSeller(taxid, sellerName, sellerAddress, afiliacion, null, null, email: sellerEmail);

        // Calculate retenciones
        decimal totalTaxable = 0m;
        foreach (var item in lineItems)
        {
            var taxableStr = item?["Taxes"]?["Tax"]?[0]?["TaxableAmount"]?.GetValue<string>() ?? "0";
            totalTaxable += decimal.Parse(taxableStr, CultureInfo.InvariantCulture);
        }

        string retencionIsr = TaxHelper.Fmt(TaxHelper.RoundHalfUp(totalTaxable * 0.05m, 6));
        string retencionIva = totalIva;
        decimal gt = decimal.Parse(grandTotal, CultureInfo.InvariantCulture);
        decimal rIsr = decimal.Parse(retencionIsr, CultureInfo.InvariantCulture);
        decimal rIva = decimal.Parse(retencionIva, CultureInfo.InvariantCulture);
        string totalMenos = TaxHelper.Fmt(gt - rIsr - rIva);

        return new JsonObject
        {
            ["Version"] = "1.00",
            ["CountryCode"] = "GT",
            ["Header"] = new JsonObject { ["DocType"] = "FESP", ["IssuedDateTime"] = isoNow, ["Currency"] = "GTQ" },
            ["Seller"] = seller,
            ["Buyer"] = buyer,
            ["ThirdParties"] = (JsonNode?)null,
            ["Items"] = lineItems,
            ["Totals"] = BuildTotals(grandTotal, totalIva, true),
            ["AdditionalDocumentInfo"] = new JsonObject
            {
                ["AdditionalInfo"] = new JsonArray
                {
                    new JsonObject
                    {
                        ["Code"] = "FESP",
                        ["Type"] = "COMPLEMENTO",
                        ["AditionalInfo"] = new JsonArray
                        {
                            new JsonObject { ["Name"] = "RetencionISR",          ["Data"] = (JsonNode?)null, ["Value"] = retencionIsr },
                            new JsonObject { ["Name"] = "RetencionIVA",          ["Data"] = (JsonNode?)null, ["Value"] = retencionIva },
                            new JsonObject { ["Name"] = "TotalMenosRetenciones", ["Data"] = (JsonNode?)null, ["Value"] = totalMenos },
                        },
                    },
                },
            },
        };
    }

    internal static JsonObject BuildRdon(
        string taxid, string sellerName, string sellerAddress, JsonObject buyer,
        IReadOnlyList<LineItem> items, string tipoPersoneria,
        string afiliacion = "GEN", string amountStr = "", string observaciones = "-",
        string? sellerEmail = null)
    {
        var (isoNow, _, _) = TaxHelper.GtNow();
        var (lineItems, grandTotal, _) = BuildItems(items, false);
        var seller = BuildSeller(taxid, sellerName, sellerAddress, afiliacion, "4", "4", email: sellerEmail);
        var amt = string.IsNullOrEmpty(amountStr) ? grandTotal : amountStr;

        return new JsonObject
        {
            ["Version"] = "1.00",
            ["CountryCode"] = "GT",
            ["Header"] = new JsonObject
            {
                ["DocType"] = "RDON",
                ["IssuedDateTime"] = isoNow,
                ["Currency"] = "GTQ",
                ["AdditionalIssueDocInfo"] = new JsonArray
                {
                    new JsonObject { ["Name"] = "TipoPersoneria", ["Data"] = (JsonNode?)null, ["Value"] = tipoPersoneria },
                },
            },
            ["Seller"] = seller,
            ["Buyer"] = buyer,
            ["ThirdParties"] = (JsonNode?)null,
            ["Items"] = lineItems,
            ["Totals"] = BuildTotals(grandTotal, "0.000000", false),
            ["AdditionalDocumentInfo"] = BuildAdenda("RDON", amt, items.Count, observaciones),
        };
    }

    internal static JsonObject BuildFpeq(
        string taxid, string sellerName, string sellerAddress, JsonObject buyer,
        IReadOnlyList<LineItem> items, string amountStr = "", string observaciones = "-",
        string? sellerEmail = null, string? tipoFrase = null, string? escenario = null)
    {
        var (isoNow, _, _) = TaxHelper.GtNow();
        var (lineItems, grandTotal, _) = BuildItems(items, false);
        var (tf, es) = ResolveFrase("FPEQ", "PEQ", tipoFrase, escenario);
        var seller = BuildSeller(taxid, sellerName, sellerAddress, "PEQ", tf, es, email: sellerEmail);
        var amt = string.IsNullOrEmpty(amountStr) ? grandTotal : amountStr;

        return new JsonObject
        {
            ["Version"] = "1.00",
            ["CountryCode"] = "GT",
            ["Header"] = new JsonObject { ["DocType"] = "FPEQ", ["IssuedDateTime"] = isoNow, ["Currency"] = "GTQ" },
            ["Seller"] = seller,
            ["Buyer"] = buyer,
            ["ThirdParties"] = (JsonNode?)null,
            ["Items"] = lineItems,
            ["Totals"] = BuildTotals(grandTotal, "0.000000", false),
            ["AdditionalDocumentInfo"] = BuildAdenda("FPEQ", amt, items.Count, observaciones),
        };
    }

    internal static JsonObject BuildReci(
        string taxid, string sellerName, string sellerAddress, JsonObject buyer,
        IReadOnlyList<LineItem> items, string afiliacion = "GEN",
        string amountStr = "", string observaciones = "-",
        string studentName = "Estudiante", string studentId = "000000000",
        string academicUnit = "01", string? sellerEmail = null)
    {
        var (isoNow, _, _) = TaxHelper.GtNow();
        var (lineItems, grandTotal, _) = BuildItems(items, false);
        var seller = BuildSeller(taxid, sellerName, sellerAddress, afiliacion, "4", "5", email: sellerEmail);
        var amt = string.IsNullOrEmpty(amountStr) ? grandTotal : amountStr;

        var extraInfo = new List<JsonObject>
        {
            new() { ["Name"] = "Tipo",            ["Data"] = (JsonNode?)null, ["Value"] = "Universidad" },
            new() { ["Name"] = "NombreAlumno",    ["Data"] = (JsonNode?)null, ["Value"] = studentName },
            new() { ["Name"] = "Carne",           ["Data"] = (JsonNode?)null, ["Value"] = studentId },
            new() { ["Name"] = "UnidadAcademica", ["Data"] = (JsonNode?)null, ["Value"] = academicUnit },
        };

        return new JsonObject
        {
            ["Version"] = "1.00",
            ["CountryCode"] = "GT",
            ["Header"] = new JsonObject { ["DocType"] = "RECI", ["IssuedDateTime"] = isoNow, ["Currency"] = "GTQ" },
            ["Seller"] = seller,
            ["Buyer"] = buyer,
            ["ThirdParties"] = (JsonNode?)null,
            ["Items"] = lineItems,
            ["Totals"] = BuildTotals(grandTotal, "0.000000", false),
            ["AdditionalDocumentInfo"] = BuildAdenda("RECI", amt, items.Count, observaciones, extraInfo),
        };
    }

    internal static JsonObject BuildCca(
        string taxid, string sellerName, string sellerAddress, JsonObject buyer,
        IReadOnlyList<LineItem> items, IReadOnlyList<CcaCobro> cobros,
        string afiliacion = "GEN", string? sellerEmail = null,
        string? tipoFrase = null, string? escenario = null)
    {
        var (isoNow, _, _) = TaxHelper.GtNow();
        var (lineItems, grandTotal, totalIva) = BuildItems(items, true);
        var (tf, es) = ResolveFrase("FACT", afiliacion, tipoFrase, escenario);
        var seller = BuildSeller(taxid, sellerName, sellerAddress, afiliacion, tf, es, email: sellerEmail);

        var ccaData = new JsonArray();
        foreach (var cobro in cobros)
            ccaData.Add(new JsonObject
            {
                ["Info"] = new JsonArray
                {
                    new JsonObject { ["Name"] = "NITtercero",     ["Data"] = (JsonNode?)null, ["Value"] = cobro.NitTercero },
                    new JsonObject { ["Name"] = "NumeroDocumento", ["Data"] = (JsonNode?)null, ["Value"] = cobro.NumeroDocumento },
                    new JsonObject { ["Name"] = "FechaDocumento",  ["Data"] = (JsonNode?)null, ["Value"] = cobro.FechaDocumento },
                    new JsonObject { ["Name"] = "Descripcion",     ["Data"] = (JsonNode?)null, ["Value"] = cobro.Descripcion },
                    new JsonObject { ["Name"] = "BaseImponible",   ["Data"] = (JsonNode?)null, ["Value"] = TaxHelper.Fmt(cobro.BaseImponible, 2) },
                    new JsonObject { ["Name"] = "MontoCobroDAI",   ["Data"] = (JsonNode?)null, ["Value"] = TaxHelper.Fmt(cobro.MontoCobroDai, 2) },
                    new JsonObject { ["Name"] = "MontoCobroIVA",   ["Data"] = (JsonNode?)null, ["Value"] = TaxHelper.Fmt(cobro.MontoCobroIva, 2) },
                    new JsonObject { ["Name"] = "MontoCobroOtros", ["Data"] = (JsonNode?)null, ["Value"] = TaxHelper.Fmt(cobro.MontoCobroOtros, 2) },
                    new JsonObject { ["Name"] = "MontoCobroTotal", ["Data"] = (JsonNode?)null, ["Value"] = TaxHelper.Fmt(cobro.MontoCobroTotal, 2) },
                },
            });

        return new JsonObject
        {
            ["Version"] = "1.00",
            ["CountryCode"] = "GT",
            ["Header"] = new JsonObject { ["DocType"] = "FACT", ["IssuedDateTime"] = isoNow, ["Currency"] = "GTQ" },
            ["Seller"] = seller,
            ["Buyer"] = buyer,
            ["ThirdParties"] = (JsonNode?)null,
            ["Items"] = lineItems,
            ["Totals"] = BuildTotals(grandTotal, totalIva, true),
            ["AdditionalDocumentInfo"] = new JsonObject
            {
                ["AdditionalInfo"] = new JsonArray
                {
                    new JsonObject
                    {
                        ["Code"] = "CCA",
                        ["Type"] = "COMPLEMENTO",
                        ["AditionalData"] = new JsonObject { ["Data"] = ccaData },
                    },
                },
            },
        };
    }

    // ── Combustible (fuel) builder ────────────────────────────────────────────

    private static (JsonArray Items, string GrandTotal, string TotalIva, string TotalPetroleo)
        BuildFuelItems(IReadOnlyList<FuelLineItem> items)
    {
        var arr = new JsonArray();
        decimal grandTotal    = 0m;
        decimal totalIva      = 0m;
        decimal totalPetroleo = 0m;

        for (int i = 0; i < items.Count; i++)
        {
            var item = items[i];
            var built = new JsonObject
            {
                ["Number"]        = (i + 1).ToString(),
                ["Codes"]         = (JsonNode?)null,
                ["Type"]          = item.Type,
                ["Description"]   = item.Description,
                ["UnitOfMeasure"] = item.UnitOfMeasure,
                ["Discounts"]     = (JsonNode?)null,
            };

            if (item.PetroleoAmount > 0m)
            {
                var calc = TaxHelper.CalcFuelLine(item.Qty, item.Price, item.PetroleoAmount);
                built["Qty"]   = calc.Qty;
                built["Price"] = calc.Price;
                built["Taxes"] = new JsonObject
                {
                    ["Tax"] = new JsonArray
                    {
                        new JsonObject
                        {
                            ["Code"]          = "1",
                            ["Description"]   = "IVA",
                            ["TaxableAmount"] = calc.Taxable,
                            ["Amount"]        = calc.Iva,
                        },
                        new JsonObject
                        {
                            ["Code"]          = string.IsNullOrEmpty(item.PetroleoCode) ? "1" : item.PetroleoCode,
                            ["Description"]   = "PETROLEO",
                            ["TaxableAmount"] = calc.Qty,
                            ["Amount"]        = calc.Petrol,
                        },
                    },
                };
                built["Totals"] = new JsonObject { ["TotalItem"] = calc.LineTotal };

                grandTotal    += decimal.Parse(calc.LineTotal, CultureInfo.InvariantCulture);
                totalIva      += decimal.Parse(calc.Iva, CultureInfo.InvariantCulture);
                totalPetroleo += decimal.Parse(calc.Petrol, CultureInfo.InvariantCulture);
            }
            else
            {
                var calc = TaxHelper.CalcLine(item.Qty, item.Price, true, 0m);
                built["Qty"]   = calc.Qty;
                built["Price"] = calc.Price;
                built["Taxes"] = new JsonObject
                {
                    ["Tax"] = new JsonArray
                    {
                        new JsonObject
                        {
                            ["Code"]          = "1",
                            ["Description"]   = "IVA",
                            ["TaxableAmount"] = calc.Taxable,
                            ["Amount"]        = calc.Iva,
                        },
                    },
                };
                built["Totals"] = new JsonObject { ["TotalItem"] = calc.LineTotal };

                grandTotal += decimal.Parse(calc.LineTotal, CultureInfo.InvariantCulture);
                totalIva   += decimal.Parse(calc.Iva, CultureInfo.InvariantCulture);
            }

            arr.Add(built);
        }

        return (arr, TaxHelper.Fmt(grandTotal), TaxHelper.Fmt(totalIva), TaxHelper.Fmt(totalPetroleo));
    }

    private static JsonObject BuildFuelAdenda() => new()
    {
        ["AdditionalInfo"] = new JsonArray
        {
            new JsonObject
            {
                ["Code"] = "00000013",
                ["Type"] = "ADENDA",
                ["AditionalInfo"] = new JsonArray
                {
                    new JsonObject
                    {
                        ["Name"]  = "VALIDAR_REFERENCIA_INTERNA",
                        ["Data"]  = (JsonNode?)null,
                        ["Value"] = "NO_VALIDAR",
                    },
                },
            },
        },
    };

    /// <summary>
    /// Build a FACT payload for combustible (fuel) invoices.
    ///
    /// <para>
    /// <see cref="FuelLineItem"/>s with <c>PetroleoAmount &gt; 0</c> are treated as fuel items
    /// and receive two Tax entries (IVA + PETROLEO). Items with <c>PetroleoAmount == 0</c>
    /// are treated as regular IVA-only items. Both types may coexist in the same invoice.
    /// </para>
    ///
    /// <para>Common <c>PetroleoCode</c> values: "1" = SUPER, "2" = REGULAR, "4" = DIESEL.</para>
    /// </summary>
    internal static JsonObject BuildFactCombustible(
        string taxid, string sellerName, string sellerAddress,
        JsonObject buyer, IReadOnlyList<FuelLineItem> items,
        string afiliacion = "GEN",
        string? tipoFrase = null,
        string? escenario = null,
        string? sellerEmail = null)
    {
        var (isoNow, _, _) = TaxHelper.GtNow();
        var (lineItems, grandTotal, totalIva, totalPetroleo) = BuildFuelItems(items);
        var (tf, es) = ResolveFrase("FACT", afiliacion, tipoFrase, escenario);
        var seller = BuildSeller(taxid, sellerName, sellerAddress, afiliacion, tf, es, email: sellerEmail);

        var totalTaxArray = new JsonArray
        {
            new JsonObject { ["Description"] = "IVA", ["Amount"] = totalIva },
        };
        if (decimal.Parse(totalPetroleo, CultureInfo.InvariantCulture) > 0m)
            totalTaxArray.Add(new JsonObject { ["Description"] = "PETROLEO", ["Amount"] = totalPetroleo });

        return new JsonObject
        {
            ["Version"]     = "1.00",
            ["CountryCode"] = "GT",
            ["Header"]      = new JsonObject { ["DocType"] = "FACT", ["IssuedDateTime"] = isoNow, ["Currency"] = "GTQ" },
            ["Seller"]      = seller,
            ["Buyer"]       = buyer,
            ["ThirdParties"] = (JsonNode?)null,
            ["Items"]       = lineItems,
            ["Totals"] = new JsonObject
            {
                ["TotalTaxes"] = new JsonObject { ["TotalTax"] = totalTaxArray },
                ["GrandTotal"] = new JsonObject { ["InvoiceTotal"] = grandTotal },
            },
            ["AdditionalDocumentInfo"] = BuildFuelAdenda(),
        };
    }
}
