"""Unit tests (no credentials) for internal references, token renewal and error classification."""
from __future__ import annotations

import json
import unittest
from collections import defaultdict, deque

import requests

from digifact_sdk import DigifactApiError, DigifactAuthError, DigifactClient, DigifactDuplicateReferenceError
from digifact_sdk.builder import ADENDA_CODE_STD

REFERENCE = "0F8FAD5B-D9CB-469F-A165-70867728950E"
ITEMS = [{"description": "Servicio", "qty": 1, "price": 100}]
ORIGIN = {"auth_number": "AAAABBBB-1111-2222-3333-444455556666", "date": "2026-10-01", "series": "AAAABBBB", "number": "1"}
CERTIFIED = {
    "code": 1,
    "authNumber": "CCCCDDDD-1111-2222-3333-444455556666",
    "batch": "CCCCDDDD",
    "serial": 123,
    "issuedTimeStamp": "2026-11-03T10:00:00",
    "enrolledTimeStamp": "2026-11-03T10:00:02",
    "additionalInfo": {"acuseReciboSAT": "ACEPTADO"},
}


def response(status: int, body: dict | str) -> requests.Response:
    resp = requests.Response()
    resp.status_code = status
    resp._content = (body if isinstance(body, str) else json.dumps(body)).encode()
    resp.headers["Content-Type"] = "application/json"
    resp.url = "https://testnucgt.digifact.com/api"
    return resp


class FakeSession:
    def __init__(self) -> None:
        self.routes: dict[tuple[str, str], deque[requests.Response]] = defaultdict(deque)
        self.calls: list[tuple[str, str, dict]] = []

    def on(self, verb: str, path: str, *responses: requests.Response) -> FakeSession:
        self.routes[(verb, path)].extend(responses)
        return self

    def calls_to(self, verb: str, path: str) -> list[dict]:
        return [kwargs for called_verb, url, kwargs in self.calls if called_verb == verb and url.endswith(path)]

    def post(self, url: str, **kwargs) -> requests.Response:
        return self._respond("post", url, kwargs)

    def get(self, url: str, **kwargs) -> requests.Response:
        return self._respond("get", url, kwargs)

    def _respond(self, verb: str, url: str, kwargs: dict) -> requests.Response:
        self.calls.append((verb, url, kwargs))
        for (route_verb, path), queue in self.routes.items():
            if route_verb == verb and url.endswith(path):
                return queue.popleft() if len(queue) > 1 else queue[0]
        raise AssertionError(f"Unexpected {verb.upper()} {url}")


def a_client(session: FakeSession, *, password: str = "secreto", token: str = "token-vigente") -> DigifactClient:
    return DigifactClient(
        taxid="12345678",
        username="FELUSER",
        password=password,
        token=token,
        seller_name="EMPRESA DEMO, SOCIEDAD ANONIMA",
        seller_address="CIUDAD",
        session=session,
    )


def adenda_of(payload: dict) -> list[dict]:
    return [entry for entry in payload["AdditionalDocumentInfo"]["AdditionalInfo"] if entry["Type"] == "ADENDA"]


def reference_flag(adenda: dict) -> str:
    return next(info["Value"] for info in adenda["AditionalInfo"] if info["Name"] == "VALIDAR_REFERENCIA_INTERNA")


class TestInternalReferenceUnit(unittest.TestCase):
    def setUp(self) -> None:
        self.session = FakeSession().on("post", "/v2/transform/nuc_json", response(200, CERTIFIED))
        self.client = a_client(self.session)

    def sent_payload(self) -> dict:
        return self.session.calls_to("post", "/v2/transform/nuc_json")[-1]["json"]

    def test_invoice_carries_the_reference_with_validation(self):
        self.client.invoice("CF", ITEMS, internal_reference=REFERENCE, validate_internal_reference=True)

        [adenda] = adenda_of(self.sent_payload())
        self.assertEqual(adenda["Code"], REFERENCE)
        self.assertEqual(reference_flag(adenda), "VALIDAR")
        self.assertIn("AditionalData", adenda)

    def test_reference_without_validation_is_only_stored(self):
        self.client.invoice("CF", ITEMS, internal_reference=REFERENCE)

        [adenda] = adenda_of(self.sent_payload())
        self.assertEqual(adenda["Code"], REFERENCE)
        self.assertEqual(reference_flag(adenda), "NO_VALIDAR")

    def test_without_reference_the_payload_is_unchanged(self):
        self.client.invoice("CF", ITEMS)

        [adenda] = adenda_of(self.sent_payload())
        self.assertEqual(adenda["Code"], ADENDA_CODE_STD)
        self.assertEqual(reference_flag(adenda), "NO_VALIDAR")

    def test_credit_note_gets_an_adenda_next_to_its_complement(self):
        self.client.credit_note("CF", ITEMS, ORIGIN, "Devolución", internal_reference=REFERENCE,
                                validate_internal_reference=True)

        entries = self.sent_payload()["AdditionalDocumentInfo"]["AdditionalInfo"]
        self.assertEqual([entry["Type"] for entry in entries], ["COMPLEMENTO", "ADENDA"])
        self.assertEqual(entries[1]["Code"], REFERENCE)
        self.assertEqual(reference_flag(entries[1]), "VALIDAR")

    def test_validation_needs_a_reference(self):
        with self.assertRaises(ValueError):
            self.client.invoice("CF", ITEMS, validate_internal_reference=True)

    def test_result_exposes_enrollment_and_additional_info(self):
        result = self.client.invoice("CF", ITEMS)

        self.assertEqual(result.enrolled_timestamp, "2026-11-03T10:00:02")
        self.assertEqual(result.additional_info, {"acuseReciboSAT": "ACEPTADO"})


class TestDuplicateReferenceUnit(unittest.TestCase):
    def certify_answering(self, body: dict) -> None:
        session = FakeSession().on("post", "/v2/transform/nuc_json", response(200, body))
        a_client(session).invoice("CF", ITEMS, internal_reference=REFERENCE, validate_internal_reference=True)

    def test_code_9022_is_a_duplicate_reference(self):
        with self.assertRaises(DigifactDuplicateReferenceError) as raised:
            self.certify_answering({"code": 9022, "description": "Referencia interna duplicada"})
        self.assertFalse(raised.exception.retryable)

    def test_rejection_message_about_an_already_sent_reference_is_a_duplicate(self):
        with self.assertRaises(DigifactDuplicateReferenceError):
            self.certify_answering({"code": 0, "description": "La referencia interna ya ha sido enviada a certificar"})

    def test_global_error_code_bus_003_is_a_duplicate(self):
        with self.assertRaises(DigifactDuplicateReferenceError):
            self.certify_answering({"code": 0, "codeError": "BUS-003", "description": "Rechazado"})

    def test_the_response_digifact_actually_sends_is_a_duplicate(self):
        with self.assertRaises(DigifactDuplicateReferenceError):
            self.certify_answering({"code": 3000, "message": "La referencia INTERNA, ya ha sido enviada a certificar",
                                    "description": "[9022] - El documento bajo la referencia 0F8FAD5B-D9CB-469F-A165-70867728950E ya ha sido registrado bajo el UUID CCCCDDDD-1111-2222-3333-444455556666"})

    def test_non_numeric_code_is_an_api_error(self):
        with self.assertRaises(DigifactApiError):
            self.certify_answering({"code": "Otro", "description": "?"})


class TestTokenRenewalUnit(unittest.TestCase):
    def test_expired_token_logs_in_again_and_retries_once(self):
        session = (
            FakeSession()
            .on("post", "/v2/transform/nuc_json", response(401, {}), response(200, CERTIFIED))
            .on("post", "/login/get_token", response(200, {"Token": "token-nuevo"}))
        )
        client = a_client(session, token="token-vencido")

        result = client.invoice("CF", ITEMS)

        certify_calls = session.calls_to("post", "/v2/transform/nuc_json")
        self.assertEqual(result.auth_number, CERTIFIED["authNumber"])
        self.assertEqual([call["headers"]["Authorization"] for call in certify_calls], ["token-vencido", "token-nuevo"])
        self.assertEqual(client.token, "token-nuevo")

    def test_token_only_client_reports_the_expired_token(self):
        session = FakeSession().on("get", "/Shared", response(401, {}))
        client = a_client(session, password="", token="token-vencido")

        with self.assertRaises(DigifactAuthError):
            client.get_dte_info("CCCCDDDD-1111-2222-3333-444455556666")
        self.assertEqual(session.calls_to("post", "/login/get_token"), [])

    def test_still_unauthorized_after_login_is_an_auth_error(self):
        session = (
            FakeSession()
            .on("post", "/v2/transform/nuc_json", response(401, {}))
            .on("post", "/login/get_token", response(200, {"Token": "token-nuevo"}))
        )

        with self.assertRaises(DigifactAuthError):
            a_client(session).invoice("CF", ITEMS)
        self.assertEqual(len(session.calls_to("post", "/v2/transform/nuc_json")), 2)


class TestErrorClassificationUnit(unittest.TestCase):
    def certify_with_status(self, status: int) -> DigifactApiError:
        session = FakeSession().on("post", "/v2/transform/nuc_json", response(status, {"message": "error"}))
        with self.assertRaises(DigifactApiError) as raised:
            a_client(session).invoice("CF", ITEMS)
        return raised.exception

    def test_server_errors_are_retryable(self):
        error = self.certify_with_status(503)

        self.assertTrue(error.retryable)
        self.assertEqual(error.code, 503)

    def test_invalid_documents_are_not_retryable(self):
        for status in (400, 406, 415):
            with self.subTest(status=status):
                self.assertFalse(self.certify_with_status(status).retryable)


class TestFindByInternalReferenceUnit(unittest.TestCase):
    def test_queries_shared_by_internal_reference_and_day(self):
        rows = [{"GUID": "CCCCDDDD-1111-2222-3333-444455556666", "SERIE": "CCCCDDDD", "NUMERO": "123"}]
        session = FakeSession().on("get", "/Shared", response(200, {"RESPONSE": rows}))

        found = a_client(session).find_by_internal_reference(REFERENCE, "2026-11-03")

        params = session.calls_to("get", "/Shared")[-1]["params"]
        self.assertEqual(found, rows)
        self.assertEqual(params["DATA1"], "SHARED_GETDTEINFO_BY_INTERNALID")
        self.assertEqual(params["DATA2"], f"REFERENCIA_INTERNA|{REFERENCE}|ISSUEDDATE|2026-11-03")

    def test_no_dte_with_that_reference_is_an_empty_list(self):
        session = FakeSession().on("get", "/Shared", response(200, {"Respuesta": 0, "RESPONSE": []}))

        self.assertEqual(a_client(session).find_by_internal_reference(REFERENCE, "2026-11-03"), [])

    def test_a_reply_that_is_not_json_never_looks_like_an_absent_dte(self):
        session = FakeSession().on("get", "/Shared", response(200, "<html>Mantenimiento</html>"))

        with self.assertRaises(DigifactApiError) as raised:
            a_client(session).find_by_internal_reference(REFERENCE, "2026-11-03")
        self.assertTrue(raised.exception.retryable)


if __name__ == "__main__":
    unittest.main()
