"""IVA calculations, money formatting, and Guatemala time helpers."""
from __future__ import annotations

import re
from datetime import datetime, timezone, timedelta
from decimal import Decimal, ROUND_HALF_UP, getcontext

# Use 28-digit precision to avoid rounding drift
getcontext().prec = 28

_GT_TZ = timezone(timedelta(hours=-6))
_IVA_RATE = Decimal("12")
_IVA_DIVISOR = Decimal("112")


# ── Time helpers ─────────────────────────────────────────────────────────────

def gt_now() -> tuple[str, str, str]:
    """Return (iso_with_offset, space_datetime, date_only) in Guatemala time (UTC-6)."""
    gt = datetime.now(_GT_TZ)
    dt_offset = gt.strftime("%Y-%m-%dT%H:%M:%S-06:00")
    dt_space = gt.strftime("%Y-%m-%d %H:%M:%S")
    date_only = gt.strftime("%Y-%m-%d")
    return dt_offset, dt_space, date_only


def gt_now_dt() -> datetime:
    """Return current datetime in Guatemala timezone."""
    return datetime.now(_GT_TZ)


# ── Tax ID helpers ────────────────────────────────────────────────────────────

def pad_taxid(taxid: str) -> str:
    """Strip non-digits and left-pad to 12 characters with zeros.

    Example: '44653948' → '000044653948'
    """
    digits = re.sub(r"\D", "", taxid)
    return digits.rjust(12, "0")


# ── Money helpers ─────────────────────────────────────────────────────────────

def fmt(value: Decimal | float | str, decimals: int = 6) -> str:
    """Format a Decimal (or float/str) as a string with *decimals* decimal places."""
    d = Decimal(str(value))
    quantize_str = Decimal("1." + "0" * decimals)
    return str(d.quantize(quantize_str, rounding=ROUND_HALF_UP))


def calc_iva(line_total: "Decimal | float | str") -> tuple[Decimal, Decimal]:
    """Return (taxable_amount, iva_amount) for an IVA-inclusive line_total.

    taxable_amount = line_total / 1.12
    iva_amount     = line_total - taxable_amount
    """
    line_total = Decimal(str(line_total))
    taxable = (line_total * 100 / _IVA_DIVISOR).quantize(
        Decimal("0.000001"), rounding=ROUND_HALF_UP
    )
    iva = (line_total - taxable).quantize(
        Decimal("0.000001"), rounding=ROUND_HALF_UP
    )
    return taxable, iva


class LineCalc:
    """Holds all calculated values for a single invoice line item."""

    def __init__(
        self,
        qty: Decimal,
        unit_price: Decimal,
        *,
        taxable: bool = True,
        discount: Decimal | None = None,
    ) -> None:
        self.qty = qty
        self.unit_price = unit_price
        self.gross = (qty * unit_price).quantize(Decimal("0.000001"), rounding=ROUND_HALF_UP)
        self.discount = (discount or Decimal("0")).quantize(
            Decimal("0.000001"), rounding=ROUND_HALF_UP
        )
        self.line_total = (self.gross - self.discount).quantize(
            Decimal("0.000001"), rounding=ROUND_HALF_UP
        )
        if taxable:
            self.taxable_amount, self.iva_amount = calc_iva(self.line_total)
        else:
            self.taxable_amount = Decimal("0")
            self.iva_amount = Decimal("0")

    # Convenience string formatters
    def f_qty(self) -> str:
        return fmt(self.qty)

    def f_price(self) -> str:
        return fmt(self.unit_price)

    def f_line_total(self) -> str:
        return fmt(self.line_total)

    def f_taxable(self) -> str:
        return fmt(self.taxable_amount)

    def f_iva(self) -> str:
        return fmt(self.iva_amount)

    def f_discount(self) -> str:
        return fmt(self.discount, decimals=2)


class InvoiceTotals:
    """Aggregate totals for a full invoice."""

    def __init__(self, lines: list[LineCalc]) -> None:
        self.lines = lines
        self.grand_total = sum(ln.line_total for ln in lines).quantize(
            Decimal("0.000001"), rounding=ROUND_HALF_UP
        )
        self.total_iva = sum(ln.iva_amount for ln in lines).quantize(
            Decimal("0.000001"), rounding=ROUND_HALF_UP
        )
        self.total_taxable = sum(ln.taxable_amount for ln in lines).quantize(
            Decimal("0.000001"), rounding=ROUND_HALF_UP
        )

    def f_grand_total(self) -> str:
        return fmt(self.grand_total)

    def f_total_iva(self) -> str:
        return fmt(self.total_iva)


class FuelLineCalc:
    """Holds all calculated values for a fuel (combustible) invoice line item.

    The PETROLEO tax is a fixed pass-through amount supplied by the caller.
    ``unit_price`` is the full consumer price per unit (PETROLEO + IVA-inclusive).
    IVA is calculated on qty × (unit_price − petrol_amount_per_unit).
    TotalItem = qty × unit_price.
    """

    def __init__(
        self,
        qty: Decimal,
        unit_price: Decimal,
        petrol_amount_per_unit: Decimal,
    ) -> None:
        self.qty = qty
        self.unit_price = unit_price
        net_per_unit = unit_price - petrol_amount_per_unit
        self.gross = (qty * net_per_unit).quantize(Decimal("0.000001"), rounding=ROUND_HALF_UP)
        self.petrol_total = (qty * petrol_amount_per_unit).quantize(
            Decimal("0.000001"), rounding=ROUND_HALF_UP
        )
        self.line_total = (qty * unit_price).quantize(
            Decimal("0.000001"), rounding=ROUND_HALF_UP
        )
        self.taxable_amount, self.iva_amount = calc_iva(self.gross)

    def f_qty(self) -> str:
        return fmt(self.qty)

    def f_price(self) -> str:
        return fmt(self.unit_price)

    def f_line_total(self) -> str:
        return fmt(self.line_total)

    def f_taxable(self) -> str:
        return fmt(self.taxable_amount)

    def f_iva(self) -> str:
        return fmt(self.iva_amount)

    def f_petrol(self) -> str:
        return fmt(self.petrol_total)
