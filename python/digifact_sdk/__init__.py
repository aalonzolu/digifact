"""Digifact FEL Guatemala SDK — Python package."""
from .builder import (
    FUEL_EXEMPT_DIESEL,
    FUEL_EXEMPT_REGULAR_ETHANOL,
    FUEL_EXEMPT_REGULAR_IMPORTER,
    FUEL_EXEMPT_SUPER,
    FuelExemption,
    fuel_exemption,
)
from .client import DigifactClient, DteResult
from .exceptions import (
    DigifactApiError,
    DigifactAuthError,
    DigifactDuplicateReferenceError,
    DigifactError,
    DigifactNitNotFoundError,
    DigifactValidationError,
)

__version__ = "3.2.0"
__all__ = [
    "DigifactClient",
    "DteResult",
    "DigifactError",
    "DigifactAuthError",
    "DigifactApiError",
    "DigifactDuplicateReferenceError",
    "DigifactValidationError",
    "DigifactNitNotFoundError",
    "FuelExemption",
    "fuel_exemption",
    "FUEL_EXEMPT_SUPER",
    "FUEL_EXEMPT_REGULAR_IMPORTER",
    "FUEL_EXEMPT_DIESEL",
    "FUEL_EXEMPT_REGULAR_ETHANOL",
]
