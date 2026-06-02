"""Integration tests for the Digifact FEL SDK.

These tests require live credentials set via environment variables:
    DIGIFACT_TAXID       e.g. "12345678"
    DIGIFACT_USERNAME    e.g. "FELUSER"
    DIGIFACT_PASSWORD    e.g. "..."

If any of these are missing, all integration tests are skipped gracefully.

TOKEN REUSE: a single DigifactClient is shared across ALL integration test
classes. It logs in once and reuses the token for every request, keeping the
total number of authentication calls to 1 per test run.
"""
from __future__ import annotations

import os
import unittest
from decimal import Decimal

# Load .env if available (dev convenience)
try:
    from dotenv import load_dotenv
    load_dotenv()
except ImportError:
    pass

TAXID    = os.environ.get("DIGIFACT_TAXID", "")
USERNAME = os.environ.get("DIGIFACT_USERNAME", "")
PASSWORD = os.environ.get("DIGIFACT_PASSWORD", "")

SKIP_REASON = "Set DIGIFACT_TAXID, DIGIFACT_USERNAME, DIGIFACT_PASSWORD to run integration tests"
SKIP = not (TAXID and USERNAME and PASSWORD)

# ── Shared client singleton — ONE login for the whole test session ────────────
if not SKIP:
    from digifact_sdk import DigifactClient, DigifactError
    from digifact_sdk.tax import gt_now, calc_iva, fmt, pad_taxid

    _CLIENT = DigifactClient(
        taxid=TAXID,
        username=USERNAME,
        password=PASSWORD,
        environment="test",
    )
else:
    _CLIENT = None  # type: ignore[assignment]


def _client() -> "DigifactClient":
    """Return the shared session client."""
    return _CLIENT  # type: ignore[return-value]


# ── Unit tests (no credentials required) ─────────────────────────────────────

class TestTaxCalcUnit(unittest.TestCase):
    def test_calc_iva(self):
        from digifact_sdk.tax import calc_iva, fmt
        taxable, iva = calc_iva("112")
        self.assertEqual(fmt(taxable), "100.000000")
        self.assertEqual(fmt(iva), "12.000000")

    def test_calc_iva_100(self):
        from digifact_sdk.tax import calc_iva
        taxable, iva = calc_iva("100")
        total = Decimal(taxable) + Decimal(iva)
        self.assertEqual(total, Decimal("100.000000"))

    def test_fmt(self):
        from digifact_sdk.tax import fmt
        self.assertEqual(fmt("1.5"), "1.500000")
        self.assertEqual(fmt(100), "100.000000")

    def test_pad_taxid(self):
        from digifact_sdk.tax import pad_taxid
        self.assertEqual(pad_taxid("12345678"), "000012345678")
        self.assertEqual(pad_taxid("000012345678"), "000012345678")
        self.assertEqual(pad_taxid("GT.000012345678"), "000012345678")


class TestFuelLineCalcUnit(unittest.TestCase):
    def test_fuel_line_math(self):
        from digifact_sdk.tax import FuelLineCalc
        fc = FuelLineCalc(Decimal("1"), Decimal("35.00"), Decimal("4.70"))
        self.assertEqual(fc.f_qty(),        "1.000000")
        self.assertEqual(fc.f_price(),      "35.000000")
        self.assertEqual(fc.f_line_total(), "35.000000")
        self.assertEqual(fc.f_taxable(),    "27.053571")
        self.assertEqual(fc.f_iva(),        "3.246429")
        self.assertEqual(fc.f_petrol(),     "4.700000")

    def test_taxable_plus_iva_equals_gross(self):
        from digifact_sdk.tax import FuelLineCalc
        fc = FuelLineCalc(Decimal("2"), Decimal("53.0"), Decimal("3.0"))
        total = Decimal(fc.f_taxable()) + Decimal(fc.f_iva())
        # taxable + iva must equal qty × net (= 100.00)
        self.assertEqual(total, Decimal("100.000000"))
        # lineTotal = qty × price = 2 × 53.00
        self.assertEqual(fc.f_line_total(), "106.000000")


class TestPetroleoRatesAutoFill(unittest.TestCase):
    """Unit tests for DigifactClient._apply_petroleo_rates."""

    def _make_client(self, rates=None):
        from digifact_sdk.client import DigifactClient
        return DigifactClient(
            taxid="12345678",
            username="U",
            password="P",
            petroleo_rates=rates,
        )

    def test_fills_amount_from_rates_when_code_set(self):
        client = self._make_client({"1": 4.70, "4": 1.30})
        resolved = client._apply_petroleo_rates([
            {"description": "SUPER",  "price": 30.30, "petroleo_code": "1"},
            {"description": "DIESEL", "price": 30.70, "petroleo_code": "4"},
            {"description": "FILTRO", "price": 45.00},  # no code → untouched
        ])
        self.assertEqual(resolved[0]["petroleo_amount"], 4.70)
        self.assertEqual(resolved[1]["petroleo_amount"], 1.30)
        self.assertNotIn("petroleo_amount", resolved[2])

    def test_explicit_amount_never_overwritten(self):
        client = self._make_client({"1": 4.70})
        resolved = client._apply_petroleo_rates([
            {"description": "SUPER", "price": 30.30, "petroleo_code": "1", "petroleo_amount": 9.99},
        ])
        self.assertEqual(resolved[0]["petroleo_amount"], 9.99)

    def test_missing_rate_raises_error(self):
        from digifact_sdk.exceptions import DigifactValidationError
        client = self._make_client()  # no rates
        with self.assertRaises(DigifactValidationError):
            client._apply_petroleo_rates([
                {"description": "SUPER", "price": 30.30, "petroleo_code": "1"},
            ])

    def test_code_not_in_rates_raises_error(self):
        from digifact_sdk.exceptions import DigifactValidationError
        client = self._make_client({"1": 4.70})  # only SUPER configured
        with self.assertRaises(DigifactValidationError):
            client._apply_petroleo_rates([
                {"description": "DIESEL", "price": 30.70, "petroleo_code": "4"},
            ])


class TestBuilderUnit(unittest.TestCase):
    def setUp(self):
        from digifact_sdk import builder
        self.builder = builder

    def _make_seller(self, doc_type="FACT"):
        from digifact_sdk.builder import _build_seller
        tipo_frase = None if doc_type == "FESP" else "1"
        escenario = None if doc_type == "FESP" else "1"
        return _build_seller(
            "12345678", "TEST", "CALLE",
            afiliacion="GEN",
            tipo_frase=tipo_frase,
            escenario=escenario,
            branch_code="1",
            branch_name="SUCURSAL",
        )

    def test_fact_cf_structure(self):
        from digifact_sdk.builder import build_fact
        buyer = {"TaxID": "CF", "Name": "CONSUMIDOR FINAL",
                 "AddressInfo": {"Address": "CIUDAD", "City": "01010",
                                 "District": "GUATEMALA", "State": "GUATEMALA", "Country": "GT"}}
        payload = build_fact(
            "12345678", "TEST", "CALLE",
            buyer=buyer,
            items=[{"description": "Servicio", "qty": 1, "price": 100.0}],
        )
        self.assertEqual(payload["Header"]["DocType"], "FACT")
        self.assertEqual(payload["Seller"]["TaxID"], "12345678")
        self.assertIn("Items", payload)
        self.assertIsNotNone(payload["Items"][0]["Taxes"])

    def test_fesp_no_additionlinfo(self):
        seller = self._make_seller(doc_type="FESP")
        self.assertNotIn("AdditionlInfo", seller,
                         "FESP Seller must NOT include AdditionlInfo (frases not allowed)")

    def test_nabn_no_taxes(self):
        from digifact_sdk.builder import _build_items
        items_list, _ = _build_items([{"description": "X", "qty": 1, "price": 100.0}], taxable=False)
        self.assertIsNone(items_list[0]["Taxes"])

    def test_ncre_complemento_keys(self):
        from digifact_sdk.builder import build_ncre
        buyer = {"TaxID": "12345678", "Name": "X",
                 "AddressInfo": {"Address": "C", "City": "01010",
                                 "District": "G", "State": "G", "Country": "GT"}}
        payload = build_ncre(
            "12345678", "TEST", "C",
            buyer=buyer,
            items=[{"description": "D", "qty": 1, "price": 10.0}],
            origin={"auth_number": "X-Y-Z", "date": "2026-01-01", "series": "S1", "number": "1"},
            reason="Test",
        )
        complemento = payload["AdditionalDocumentInfo"]["AdditionalInfo"][0]
        self.assertEqual(complemento["Code"], "NCRE")
        # NCRE uses AditionalInfo (not AditionalData)
        self.assertIn("AditionalInfo", complemento)
        self.assertNotIn("AditionalData", complemento)

    def test_ndeb_complemento_keys(self):
        from digifact_sdk.builder import build_ndeb
        buyer = {"TaxID": "12345678", "Name": "X",
                 "AddressInfo": {"Address": "C", "City": "01010",
                                 "District": "G", "State": "G", "Country": "GT"}}
        payload = build_ndeb(
            "12345678", "TEST", "C",
            buyer=buyer,
            items=[{"description": "D", "qty": 1, "price": 10.0}],
            origin={"auth_number": "X-Y-Z", "date": "2026-01-01", "series": "S1", "number": "1"},
            reason="Test",
        )
        complemento = payload["AdditionalDocumentInfo"]["AdditionalInfo"][0]
        self.assertEqual(complemento["Code"], "NDEB")
        self.assertIn("AditionalInfo", complemento)
        self.assertNotIn("AditionalData", complemento)

    def test_rdon_has_tipopersoneria(self):
        from digifact_sdk.builder import build_rdon
        buyer = {"TaxID": "12345678", "Name": "X",
                 "AddressInfo": {"Address": "C", "City": "01010",
                                 "District": "G", "State": "G", "Country": "GT"}}
        payload = build_rdon(
            "12345678", "TEST", "C",
            buyer=buyer,
            items=[{"description": "D", "qty": 1, "price": 10.0}],
            tipo_personeria="719",
        )
        doc_info = payload["Header"].get("AdditionalIssueDocInfo", [])
        names = [d["Name"] for d in doc_info]
        self.assertIn("TipoPersoneria", names)

    def test_buyer_nit_full_details(self):
        from digifact_sdk.builder import _build_buyer_nit
        buyer = _build_buyer_nit(
            "12345678", "EMPRESA EJEMPLO S.A.",
            address="6 AV 6-48 ZONA 9",
            city="01009",
            district="GUATEMALA",
            state="GUATEMALA",
            country="GT",
            email="test@example.com",
        )
        self.assertEqual(buyer["TaxID"], "12345678")
        self.assertEqual(buyer["Name"], "EMPRESA EJEMPLO S.A.")
        self.assertEqual(buyer["AddressInfo"]["Address"], "6 AV 6-48 ZONA 9")
        self.assertEqual(buyer["AddressInfo"]["City"], "01009")
        self.assertEqual(buyer["Contact"]["EmailList"]["Email"][0], "test@example.com")

    def test_build_fact_combustible_structure(self):
        from digifact_sdk.builder import build_fact_combustible
        buyer = {"TaxID": "CF", "Name": "CONSUMIDOR FINAL",
                 "AddressInfo": {"Address": "CIUDAD", "City": "01010",
                                 "District": "GUATEMALA", "State": "GUATEMALA", "Country": "GT"}}
        payload = build_fact_combustible(
            "12345678", "TEST", "CALLE",
            buyer=buyer,
            items=[
                {"description": "SUPER",  "qty": 1, "price": 35.00,
                 "petroleo_amount": 4.70, "petroleo_code": "1", "type": "Bien"},
                {"description": "FILTRO", "qty": 1, "price": 45.00, "type": "Bien"},
            ],
        )
        self.assertEqual(payload["Header"]["DocType"], "FACT")
        # Fuel item: 2 taxes (IVA + PETROLEO)
        fuel_taxes = payload["Items"][0]["Taxes"]["Tax"]
        self.assertEqual(len(fuel_taxes), 2)
        self.assertEqual(fuel_taxes[1]["Description"], "PETROLEO")
        self.assertEqual(fuel_taxes[1]["Code"], "1")
        # Regular item: 1 tax (IVA only)
        reg_taxes = payload["Items"][1]["Taxes"]["Tax"]
        self.assertEqual(len(reg_taxes), 1)
        self.assertEqual(reg_taxes[0]["Description"], "IVA")
        # Adenda code
        adenda_code = payload["AdditionalDocumentInfo"]["AdditionalInfo"][0]["Code"]
        self.assertEqual(adenda_code, "00000013")
        # PETROLEO total appears in TotalTaxes
        total_descs = [t["Description"] for t in payload["Totals"]["TotalTaxes"]["TotalTax"]]
        self.assertIn("PETROLEO", total_descs)


class TestFuelFrases(unittest.TestCase):
    """Unit tests for frases[] / auto-fuel-subsidy-injection logic."""

    def _buyer(self):
        return {"TaxID": "CF", "Name": "CONSUMIDOR FINAL",
                "AddressInfo": {"Address": "CIUDAD", "City": "01010",
                                "District": "GUATEMALA", "State": "GUATEMALA", "Country": "GT"}}

    def _items(self):
        return [{"description": "SUPER", "qty": 1, "price": 35.00,
                 "petroleo_amount": 4.70, "petroleo_code": "1", "type": "Bien"}]

    def _additionl_frases(self, payload):
        ai = payload["Seller"].get("AdditionlInfo", [])
        frases = []
        i = 0
        while i + 1 < len(ai):
            frases.append((ai[i]["Value"], ai[i + 1]["Value"]))
            i += 2
        return frases

    def test_non_fuel_invoice_no_subsidy_frases(self):
        from digifact_sdk.builder import build_fact
        buyer = self._buyer()
        payload = build_fact("12345678", "TEST", "CALLE", buyer=buyer,
                             items=[{"description": "X", "qty": 1, "price": 100.0}])
        frases = self._additionl_frases(payload)
        self.assertEqual(len(frases), 1, "Regular invoice must have only the base frase")
        self.assertNotIn(("9", "18"), frases)
        self.assertNotIn(("9", "19"), frases)

    def test_fuel_within_window_auto_inject(self):
        from digifact_sdk.builder import build_fact_combustible, resolve_fuel_frases
        # Use resolve_fuel_frases directly with a date known to be in window
        result = resolve_fuel_frases(None, "1", "1", "2026-06-01T10:00:00", auto_enabled=True)
        tf_es = [(f["tipo_frase"], f["escenario"]) for f in result]
        self.assertIn(("1", "1"), tf_es, "Base frase must be present")
        self.assertIn(("9", "18"), tf_es, "Escenario 18 must be auto-injected")
        self.assertIn(("9", "19"), tf_es, "Escenario 19 must be auto-injected")
        self.assertEqual(len(result), 3)

    def test_fuel_custom_frases_no_auto_inject(self):
        from digifact_sdk.builder import resolve_fuel_frases
        custom = [{"tipo_frase": "2", "escenario": "1"}]
        result = resolve_fuel_frases(custom, None, None, "2026-06-01T10:00:00", auto_enabled=True)
        self.assertEqual(len(result), 1)
        self.assertEqual(result[0]["tipo_frase"], "2")
        self.assertNotIn(("9", "18"), [(f["tipo_frase"], f["escenario"]) for f in result])

    def test_fuel_auto_disabled_no_inject(self):
        from digifact_sdk.builder import resolve_fuel_frases
        result = resolve_fuel_frases(None, "1", "1", "2026-06-01T10:00:00", auto_enabled=False)
        tf_es = [(f["tipo_frase"], f["escenario"]) for f in result]
        self.assertEqual(len(result), 1)
        self.assertNotIn(("9", "18"), tf_es)
        self.assertNotIn(("9", "19"), tf_es)

    def test_deduplication(self):
        from digifact_sdk.builder import resolve_fuel_frases
        dupes = [{"tipo_frase": "9", "escenario": "18"},
                 {"tipo_frase": "9", "escenario": "18"},
                 {"tipo_frase": "9", "escenario": "19"}]
        result = resolve_fuel_frases(dupes, None, None, "2026-06-01T10:00:00", auto_enabled=True)
        self.assertEqual(len(result), 2)

    def test_outside_window_no_inject(self):
        from digifact_sdk.builder import resolve_fuel_frases
        # Date before window
        result_before = resolve_fuel_frases(None, "1", "1", "2026-04-26T10:00:00", auto_enabled=True)
        self.assertEqual(len(result_before), 1)
        # Date after window
        result_after = resolve_fuel_frases(None, "1", "1", "2026-07-27T10:00:00", auto_enabled=True)
        self.assertEqual(len(result_after), 1)

    def test_mutual_exclusivity_raises(self):
        from digifact_sdk.builder import build_fact_combustible
        from digifact_sdk.exceptions import DigifactValidationError
        with self.assertRaises(DigifactValidationError):
            build_fact_combustible(
                "12345678", "TEST", "CALLE",
                buyer=self._buyer(), items=self._items(),
                frases=[{"tipo_frase": "1", "escenario": "1"}],
                tipo_frase="1",
            )


# ── Integration tests (shared client, 1 login total) ─────────────────────────

@unittest.skipIf(SKIP, SKIP_REASON)
class TestLogin(unittest.TestCase):
    def test_login_returns_token(self):
        token = _client()._login()
        self.assertTrue(token, "Expected a non-empty token")


@unittest.skipIf(SKIP, SKIP_REASON)
class TestLookupNit(unittest.TestCase):
    def test_lookup_nit_known(self):
        info = _client().lookup_nit("77454820")
        self.assertIn("name", info)
        self.assertTrue(info["name"], "Expected non-empty name")

    def test_lookup_nit_caches(self):
        c = _client()
        info1 = c.lookup_nit("77454820")
        info2 = c.lookup_nit("77454820")
        self.assertEqual(info1, info2)


@unittest.skipIf(SKIP, SKIP_REASON)
class TestFactCF(unittest.TestCase):
    def test_fact_cf_basic(self):
        result = _client().invoice(
            "CF",
            [{"description": "Consultoría de prueba", "qty": 1, "price": 100.00}],
        )
        self.assertTrue(result.auth_number, "Expected auth_number")
        print(f"\n  FACT CF auth: {result.auth_number}")

    def test_fact_cf_multi_item(self):
        result = _client().invoice(
            "CF",
            [
                {"description": "Servicio", "qty": 1, "price": 31.00},
                {"description": "Jeans", "qty": 1, "price": 600.00, "type": "Bien"},
            ],
        )
        self.assertTrue(result.auth_number)


@unittest.skipIf(SKIP, SKIP_REASON)
class TestFactNit(unittest.TestCase):
    def test_fact_nit(self):
        result = _client().invoice(
            "77454820",
            [
                {"description": "Laptop", "qty": 1, "price": 5000.00, "type": "Bien"},
                {"description": "Soporte anual", "qty": 1, "price": 500.00},
            ],
        )
        self.assertTrue(result.auth_number)
        print(f"\n  FACT NIT auth: {result.auth_number}")


@unittest.skipIf(SKIP, SKIP_REASON)
class TestFactCUI(unittest.TestCase):
    def test_fact_cui(self):
        result = _client().invoice(
            {"taxid": "3730617490101", "type": "CUI", "name": "Julio Cifuentes"},
            [{"description": "Producto", "qty": 2, "price": 50.00, "type": "Bien"}],
        )
        self.assertTrue(result.auth_number)
        print(f"\n  FACT CUI auth: {result.auth_number}")


@unittest.skipIf(SKIP, SKIP_REASON)
class TestFCAM(unittest.TestCase):
    def test_fcam(self):
        _, _, date_only = gt_now()
        result = _client().invoice(
            "77454820",
            [{"description": "Servicio de prueba", "qty": 1, "price": 30.00}],
            doc_type="FCAM",
            payment_terms=[{"date": date_only, "amount": 30.00}],
        )
        self.assertTrue(result.auth_number)
        print(f"\n  FCAM auth: {result.auth_number}")


@unittest.skipIf(SKIP, SKIP_REASON)
class TestNDEBandNCRE(unittest.TestCase):
    """NDEB and NCRE against a freshly-minted FCAM. Uses 1 shared client."""

    # Class-level state: FCAM emitted once, reused by both NDEB and NCRE tests.
    _fcam = None
    _origin_date = None

    @classmethod
    def setUpClass(cls):
        _, _, cls._origin_date = gt_now()
        try:
            cls._fcam = _client().invoice(
                "77454820",
                [{"description": "Servicio origen", "qty": 1, "price": 50.00}],
                doc_type="FCAM",
                payment_terms=[{"date": cls._origin_date, "amount": 50.00}],
            )
        except Exception as exc:
            cls._fcam = None
            cls._skip_reason = f"FCAM failed: {exc}"

    def _origin(self):
        return {
            "auth_number": self._fcam.auth_number,
            "date": self._origin_date,
            "series": self._fcam.series,
            "number": self._fcam.number,
        }

    def test_ndeb(self):
        if not self._fcam:
            self.skipTest(getattr(self, "_skip_reason", "FCAM not available"))
        result = _client().debit_note(
            "77454820",
            [{"description": "Cargo adicional", "qty": 1, "price": 19.00, "type": "Bien"}],
            self._origin(),
            reason="Cargo de prueba",
        )
        self.assertTrue(result.auth_number)
        print(f"\n  NDEB auth: {result.auth_number}")

    def test_ncre(self):
        if not self._fcam:
            self.skipTest(getattr(self, "_skip_reason", "FCAM not available"))
        result = _client().credit_note(
            "77454820",
            [{"description": "Devolución", "qty": 1, "price": 10.00, "type": "Bien"}],
            self._origin(),
            reason="Producto defectuoso",
        )
        self.assertTrue(result.auth_number)
        print(f"\n  NCRE auth: {result.auth_number}")


@unittest.skipIf(SKIP, SKIP_REASON)
class TestNABN(unittest.TestCase):
    def test_nabn(self):
        result = _client().invoice(
            "77454820",
            [{"description": "RETENEDOR BLANCO", "qty": 1, "price": 100.00, "type": "Bien"}],
            doc_type="NABN",
        )
        self.assertTrue(result.auth_number)
        print(f"\n  NABN auth: {result.auth_number}")


@unittest.skipIf(SKIP, SKIP_REASON)
class TestFESP(unittest.TestCase):
    def test_fesp(self):
        result = _client().invoice(
            "10001794",
            [{"description": "ALQUILER DE EQUIPO", "qty": 1, "price": 2500.00, "type": "Bien"}],
            doc_type="FESP",
        )
        self.assertTrue(result.auth_number)
        print(f"\n  FESP auth: {result.auth_number}")


@unittest.skipIf(SKIP, SKIP_REASON)
class TestRDON(unittest.TestCase):
    def test_rdon(self):
        result = _client().invoice(
            "77454820",
            [{"description": "Donación", "qty": 1, "price": 50.00, "type": "Bien"}],
            doc_type="RDON",
            tipo_personeria="719",
        )
        self.assertTrue(result.auth_number)
        print(f"\n  RDON auth: {result.auth_number}")


@unittest.skipIf(SKIP, SKIP_REASON)
class TestRECI(unittest.TestCase):
    def test_reci(self):
        result = _client().invoice(
            "77454820",
            [{"description": "Pago universitario", "qty": 1, "price": 250.00, "type": "Bien"}],
            doc_type="RECI",
        )
        self.assertTrue(result.auth_number)
        print(f"\n  RECI auth: {result.auth_number}")


@unittest.skipIf(SKIP, SKIP_REASON)
class TestCancelAndNcredTotal(unittest.TestCase):
    """Cancel and ncredtotal against a single fresh FACT CF."""

    _fact_cf = None

    @classmethod
    def setUpClass(cls):
        try:
            cls._fact_cf = _client().invoice(
                "CF",
                [{"description": "Factura para anular", "qty": 1, "price": 50.00}],
            )
        except Exception as exc:
            cls._fact_cf = None
            cls._skip_reason = f"FACT CF failed: {exc}"

    def test_ncredtotal(self):
        if not self._fact_cf:
            self.skipTest(getattr(self, "_skip_reason", "FACT CF not available"))
        result = _client().credit_note_total(
            self._fact_cf.auth_number,
            self._fact_cf.issue_datetime,
            reason="Nota de crédito total de prueba",
        )
        self.assertIsInstance(result, dict)
        print(f"\n  ncredtotal: {result.get('authNumber') or result.get('code')}")

    def test_cancel(self):
        if not self._fact_cf:
            self.skipTest(getattr(self, "_skip_reason", "FACT CF not available"))
        result = _client().cancel(
            self._fact_cf.auth_number,
            receiver_id="CF",
            issue_datetime=self._fact_cf.issue_datetime,
            reason="Anulación de prueba automática",
        )
        self.assertIsInstance(result, dict)
        print(f"\n  cancel Codigo: {result.get('Codigo')}")


@unittest.skipIf(SKIP, SKIP_REASON)
class TestGetDte(unittest.TestCase):
    _fact = None

    @classmethod
    def setUpClass(cls):
        try:
            cls._fact = _client().invoice(
                "CF",
                [{"description": "Consulta DTE", "qty": 1, "price": 10.00}],
            )
        except Exception as exc:
            cls._fact = None
            cls._skip_reason = f"FACT CF failed: {exc}"

    def test_get_dte(self):
        if not self._fact:
            self.skipTest(getattr(self, "_skip_reason", "FACT CF not available"))
        doc = _client().get_dte(self._fact.auth_number)
        self.assertIsInstance(doc, dict)

    def test_get_dte_info(self):
        if not self._fact:
            self.skipTest(getattr(self, "_skip_reason", "FACT CF not available"))
        info = _client().get_dte_info(self._fact.auth_number)
        self.assertIsInstance(info, dict)


@unittest.skipIf(SKIP, SKIP_REASON)
class TestFuelInvoice(unittest.TestCase):
    def test_fuel_invoice_cf_mixed_items(self):
        # TODO: verify correct Digifact NUC JSON format for multiple frases with Digifact support.
        # The official documentation (documentacion_sat.md §4.2, §6.5) says frases belong in
        # Seller.AdditionlInfo, but sending multiple pairs there caused XSLT_TRANSFORM_FAILED.
        # We now send a top-level "Frases" key (matching the SAT XML structure from ESQUEMA DE
        # SUBSIDIO.xml). Disable auto-injection until the NUC JSON format is confirmed.
        result = _client().fuel_invoice(
            "CF",
            [
                {"description": "GASOLINA SUPER",   "qty": 1, "price": 35.00,
                 "petroleo_amount": 4.70, "petroleo_code": "1", "type": "Bien"},
                {"description": "GASOLINA REGULAR",  "qty": 1, "price": 34.00,
                 "petroleo_amount": 4.60, "petroleo_code": "2", "type": "Bien"},
                {"description": "FILTRO DE ACEITE",  "qty": 1, "price": 45.00, "type": "Bien"},
            ],
            auto_fuel_subsidy_frases=False,
        )
        self.assertTrue(result.auth_number, "Expected auth_number")
        print(f"\n  FACT Combustible auth: {result.auth_number}")


if __name__ == "__main__":
    unittest.main()
