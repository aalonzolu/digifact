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

from digifact_fel import DigifactClient, DigifactError

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

print("\nAll examples completed successfully.")
