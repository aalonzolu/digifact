#!/usr/bin/env python3
"""Basic usage example for the Digifact FEL SDK.

Set environment variables before running:
    export DIGIFACT_TAXID=12345678
    export DIGIFACT_USERNAME=FELUSER
    export DIGIFACT_PASSWORD=your_password

Then:
    python examples/basic.py
"""
import os
import sys

# Allow running from the sdk/python directory without installing
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from digifact_sdk import DigifactClient, DigifactError

TAXID = os.environ.get("DIGIFACT_TAXID", "")
USERNAME = os.environ.get("DIGIFACT_USERNAME", "")
PASSWORD = os.environ.get("DIGIFACT_PASSWORD", "")

if not (TAXID and USERNAME and PASSWORD):
    print("Skipping example: set DIGIFACT_TAXID, DIGIFACT_USERNAME, DIGIFACT_PASSWORD")
    sys.exit(0)

client = DigifactClient(
    taxid=TAXID,
    username=USERNAME,
    password=PASSWORD,
    environment="test",
    # PETROLEO rates (Q/gallon) — auto-filled in fuel_invoice when petroleo_code is set
    petroleo_rates={"1": 4.70, "2": 4.60, "4": 1.30},  # SUPER / REGULAR / DIESEL
)

# ── FACT CF ──
print("Emitting FACT CF...")
try:
    result = client.invoice(
        buyer="CF",
        items=[{"description": "Consultoría SDK", "qty": 1, "price": 100.00}],
    )
    print(f"  auth_number : {result.auth_number}")
    print(f"  series      : {result.series}")
    print(f"  number      : {result.number}")
    print(f"  issued_at   : {result.issue_datetime}")
except DigifactError as exc:
    print(f"  ERROR: {exc}")
    sys.exit(1)

# ── FACT NIT ──
print("\nEmitting FACT to NIT 77454820...")
try:
    result2 = client.invoice(
        buyer="77454820",
        items=[
            {"description": "Laptop", "qty": 1, "price": 5000.00, "type": "Bien"},
            {"description": "Soporte anual", "qty": 1, "price": 500.00},
        ],
    )
    print(f"  auth_number : {result2.auth_number}")
except DigifactError as exc:
    print(f"  ERROR: {exc}")

# ── Lookup NIT ──
print("\nLooking up NIT 77454820...")
try:
    info = client.lookup_nit("77454820")
    print(f"  name    : {info['name']}")
    print(f"  address : {info['address']}")
except DigifactError as exc:
    print(f"  ERROR: {exc}")

# ── FACT Combustible ──
# For gas stations, set petroleo_rates at init so you don't repeat petroleo_amount on each item.
print("\nEmitting FACT Combustible...")
try:
    result_fuel = client.fuel_invoice(
        buyer="CF",
        items=[
            # Only petroleo_code needed when petroleo_rates was set at client init.
            # petroleo_code: "1"=SUPER, "2"=REGULAR, "4"=DIESEL
            {"description": "GASOLINA SUPER",    "qty": 1, "price": 35.00, "petroleo_code": "1", "type": "Bien"},
            {"description": "GASOLINA REGULAR",  "qty": 1, "price": 34.00, "petroleo_code": "2", "type": "Bien"},
            {"description": "GASOLINA DIESEL",   "qty": 1, "price": 32.00, "petroleo_code": "4", "type": "Bien"},
            # Regular items (no petroleo_code): IVA only
            {"description": "FILTRO DE ACEITE",    "qty": 1, "price": 45.00,  "type": "Bien"},
            {"description": "SET DE CANDELAS NGK", "qty": 1, "price": 400.00, "type": "Bien"},
        ],
    )
    print(f"  auth_number : {result_fuel.auth_number}")
    print(f"  series      : {result_fuel.series}")
    print(f"  number      : {result_fuel.number}")
except DigifactError as exc:
    print(f"  ERROR: {exc}")

print("\nAll examples completed successfully.")
