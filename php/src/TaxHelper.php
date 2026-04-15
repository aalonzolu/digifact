<?php

declare(strict_types=1);

namespace Digifact\Fel;

/**
 * IVA calculations, money formatting, and Guatemala time helpers.
 *
 * Uses bcmath for arbitrary-precision arithmetic.
 * All money values are returned as strings with 6 decimal places.
 */
class TaxHelper
{
    private const SCALE = 10; // working precision
    private const OUTPUT_SCALE = 6;

    /**
     * Return current Guatemala time (UTC-6, no DST) as three strings:
     *   [iso_with_offset, space_datetime, date_only]
     *
     * @return array{string, string, string}
     */
    public static function gtNow(): array
    {
        $tz = new \DateTimeZone('America/Guatemala');
        $dt = new \DateTime('now', $tz);
        $isoOffset = $dt->format('Y-m-d\TH:i:s') . '-06:00';
        $space = $dt->format('Y-m-d H:i:s');
        $date = $dt->format('Y-m-d');
        return [$isoOffset, $space, $date];
    }

    /**
     * Strip non-digits and left-pad to 12 characters with zeros.
     */
    public static function padTaxid(string $taxid): string
    {
        $digits = preg_replace('/\D/', '', $taxid);
        return str_pad($digits, 12, '0', STR_PAD_LEFT);
    }

    /**
     * Format a numeric value as a string with 6 decimal places (rounded half-up).
     */
    public static function fmt(string $value, int $decimals = 6): string
    {
        return number_format((float)$value, $decimals, '.', '');
    }

    /**
     * Calculate IVA from an IVA-inclusive line total.
     *
     * @return array{string, string}  [taxable_amount, iva_amount]
     */
    public static function calcIva(string $lineTotal): array
    {
        // taxable = lineTotal * 100 / 112
        $taxable = bcdiv(bcmul($lineTotal, '100', self::SCALE), '112', self::SCALE);
        $iva = bcsub($lineTotal, $taxable, self::SCALE);

        return [
            self::roundHalfUp($taxable, self::OUTPUT_SCALE),
            self::roundHalfUp($iva, self::OUTPUT_SCALE),
        ];
    }

    /**
     * Round a bcmath string value half-up to the given scale.
     */
    public static function roundHalfUp(string $value, int $scale): string
    {
        // Standard bcmath half-up: add 0.5 at the target scale then truncate via bcmath.
        // Avoids float conversion, which can corrupt values like 100.0000005.
        $half = '0.' . str_repeat('0', $scale) . '5';
        if (bccomp($value, '0', $scale + 1) < 0) {
            return bcsub($value, $half, $scale);
        }
        return bcadd($value, $half, $scale);
    }

    /**
     * Build all line calculations for an item.
     *
     * @param string $qty         Quantity as string
     * @param string $unitPrice   Unit price (IVA-inclusive) as string
     * @param bool   $taxable     Whether to calculate IVA
     * @param string $discount    Line discount amount as string (default "0")
     * @return array{qty:string, price:string, lineTotal:string, taxable:string, iva:string, discount:string}
     */
    public static function calcLine(
        string $qty,
        string $unitPrice,
        bool $taxable = true,
        string $discount = '0'
    ): array {
        $gross = bcmul($qty, $unitPrice, self::SCALE);
        $lineTotal = bcsub($gross, $discount, self::SCALE);

        if ($taxable) {
            [$taxableAmt, $ivaAmt] = self::calcIva($lineTotal);
        } else {
            $taxableAmt = '0.000000';
            $ivaAmt = '0.000000';
        }

        return [
            'qty'       => self::fmt($qty),
            'price'     => self::fmt($unitPrice),
            'lineTotal' => self::fmt($lineTotal),
            'taxable'   => $taxableAmt,
            'iva'       => $ivaAmt,
            'discount'  => self::fmt($discount, 2),
        ];
    }

    /**
     * Build all line calculations for a fuel (combustible) item.
     *
     * The PETROLEO tax is a fixed pass-through amount supplied by the caller.
     * IVA is calculated only on the IVA-inclusive unit price (not on PETROLEO).
     * TotalItem = (qty × unitPrice) + (qty × petrolAmountPerUnit).
     *
     * @param string $qty                 Quantity as string
     * @param string $unitPrice           Unit price (IVA-inclusive, excluding PETROLEO) as string
     * @param string $petrolAmountPerUnit Per-unit PETROLEO tax amount as string
     * @return array{qty:string, price:string, lineTotal:string, taxable:string, iva:string, petrol:string}
     */
    public static function calcFuelLine(
        string $qty,
        string $unitPrice,
        string $petrolAmountPerUnit
    ): array {
        $gross       = bcmul($qty, $unitPrice, self::SCALE);
        $petrolTotal = bcmul($qty, $petrolAmountPerUnit, self::SCALE);
        $lineTotal   = bcadd($gross, $petrolTotal, self::SCALE);

        [$taxableAmt, $ivaAmt] = self::calcIva($gross);

        return [
            'qty'      => self::fmt($qty),
            'price'    => self::fmt($unitPrice),
            'lineTotal'=> self::fmt($lineTotal),
            'taxable'  => $taxableAmt,
            'iva'      => $ivaAmt,
            'petrol'   => self::fmt($petrolTotal),
        ];
    }
}
