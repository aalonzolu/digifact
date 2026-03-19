"""Exceptions for the Digifact FEL SDK."""
from __future__ import annotations


class DigifactError(Exception):
    """Base exception for all Digifact SDK errors."""

    def __init__(self, message: str, code: int | None = None, raw: dict | None = None) -> None:
        super().__init__(message)
        self.code = code
        self.raw = raw or {}

    def __repr__(self) -> str:
        return f"DigifactError(message={str(self)!r}, code={self.code!r})"


class DigifactAuthError(DigifactError):
    """Authentication / token error."""


class DigifactApiError(DigifactError):
    """API returned a non-success response."""


class DigifactValidationError(DigifactError):
    """Payload validation error (SAT or Digifact rejection)."""


class DigifactNitNotFoundError(DigifactError):
    """NIT lookup returned no result."""


# ── SAT hint classifier ──────────────────────────────────────────────────────

_HINTS: list[tuple[list[str], str]] = [
    (
        ["AfiliacionIVA", "MINIRTU", "RTU"],
        "El NIT del emisor no tiene la afiliación de IVA indicada en su RTU (AfiliacionIVA). "
        "Verifica que AfiliacionIVA sea GEN, PEQ o EXE según el RTU del emisor.",
    ),
    (
        ["TipoPersoneria"],
        "El valor de TipoPersoneria debe coincidir exactamente con el código registrado en el "
        "RTU del emisor. Consulta el RTU en portal.sat.gob.gt.",
    ),
    (
        ["SECCION 3.5", "9015", "ID Receptor"],
        "NCRE/NDEB: el receptor del documento origen debe coincidir con el receptor del "
        "documento de nota. Asegúrate de referenciar una factura cuyo comprador sea el mismo "
        "que el de la nota.",
    ),
    (
        ["NAMESPACE_UNKNOWN", "5035"],
        "El complemento CCA requiere que el emisor tenga habilitado el namespace CCA en "
        "Digifact. Contacta a soporte@digifact.com.gt.",
    ),
    (
        ["FRASES"],
        "Este tipo de DTE no permite las frases indicadas. Para FESP no incluyas "
        "TipoFrase/Escenario en la sección Seller.",
    ),
    (
        ["FechaEmision", "fecha"],
        "Verifica el formato de fecha: debe ser 'YYYY-MM-DD HH:MM:SS' para cancelaciones y "
        "ncredtotal, o 'YYYY-MM-DDTHH:MM:SS-06:00' para IssuedDateTime.",
    ),
]


def classify_error(message: str) -> str | None:
    """Return a developer hint for a known SAT/Digifact error pattern, or None."""
    upper = message.upper()
    for keywords, hint in _HINTS:
        if any(kw.upper() in upper for kw in keywords):
            return hint
    return None
