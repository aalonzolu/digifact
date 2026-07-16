<?php

declare(strict_types=1);

namespace Digifact\Fel;

/**
 * DTE payload builders for all supported Digifact FEL document types.
 *
 * Key intentional API typos preserved from the SAT/Digifact spec:
 *   - Seller.AdditionlInfo  (missing 'a' in Additional)
 *   - AditionalData         (missing 'd')
 *   - AditionalInfo         (missing 'd')
 */
class DteBuilder
{
    private const NO_IVA_TYPES = ['FPEQ', 'NABN', 'RDON', 'RECI'];
    private const ADENDA_CODE_STD = 'FRONT-263C-444B-89BA-6F87EC1330C0';
    private const ADENDA_CODE_ALT = 'FRONT-67C1-4545-BA1E-AA3C115E18D6';
    private const ALT_ADENDA_TYPES = ['NABN', 'RDON', 'RECI'];

    // ── Frases ───────────────────────────────────────────────────────────────

    private static function uniqFrases(array $frases): array
    {
        $seen = [];
        $out  = [];
        foreach ($frases as $f) {
            $key = $f['tipo_frase'] . ':' . $f['escenario'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $f;
            }
        }
        return $out;
    }


    /**
     * Return the final deduplicated frases list for a fuel invoice.
     * Called only after mutual-exclusivity validation.
     *
     * @param array|null  $frases       Explicit list or null.
     * @param string|null $tipoFrase    Base TipoFrase or null.
     * @param string|null $escenario    Base Escenario or null.
     * @return array<array{tipo_frase:string, escenario:string}>
     */
    public static function resolveFuelFrases(
        ?array $frases,
        ?string $tipoFrase,
        ?string $escenario
    ): array {
        if ($frases !== null) {
            if (count($frases) === 0) {
                throw new DigifactValidationException('frases must contain at least one tipo_frase/escenario pair');
            }
            return self::uniqFrases($frases);
        }
        $result = [];
        if ($tipoFrase !== null && $escenario !== null) {
            $result[] = ['tipo_frase' => $tipoFrase, 'escenario' => $escenario];
        }
        return self::uniqFrases($result);
    }

    private static function adendaCode(string $docType): string
    {
        return in_array($docType, self::ALT_ADENDA_TYPES, true)
            ? self::ADENDA_CODE_ALT
            : self::ADENDA_CODE_STD;
    }

    /**
     * Default (TipoFrase, CodigoEscenario) pair for a (docType, afiliacion) combo.
     *
     * Returns null when the DTE type does not include frases (e.g. FESP).
     * Callers can override any default via explicit parameters.
     *
     * @return array{0:string,1:string}|null
     */
    public static function defaultFrase(string $docType, string $afiliacion = 'GEN'): ?array
    {
        $afi = strtoupper($afiliacion);
        switch ($docType) {
            case 'FESP':
                return null; // FESP does not carry frases
            case 'FPEQ':
                return ['2', '1']; // Pequeño Contribuyente
            case 'RDON':
                return ['4', '4'];
            case 'RECI':
                return ['4', '5'];
            case 'NABN':
                return ['1', '1'];
            default:
                // FACT, NCRE, NDEB, FCAM, FACT+CCA, FACT+combustible
                if ($afi === 'PEQ') {
                    return ['2', '1'];
                }
                if ($afi === 'EXE') {
                    return ['4', '1'];
                }
                // GEN (IVA General). Por defecto asumimos ISR régimen sobre
                // utilidades trimestrales (UTI) -> CodigoEscenario '1', que es
                // el régimen ISR por defecto en SAT. Para ISR régimen opcional
                // simplificado (OPC) se debe sobreescribir escenario a '2'.
                return ['1', '1'];
        }
    }

    // ── Buyer helpers ─────────────────────────────────────────────────────────

    public static function buyerCf(): array
    {
        return [
            'TaxID' => 'CF',
            'Name'  => 'CONSUMIDOR FINAL',
            'AddressInfo' => [
                'Address'  => 'CIUDAD',
                'City'     => '01010',
                'District' => 'GUATEMALA',
                'State'    => 'GUATEMALA',
                'Country'  => 'GT',
            ],
        ];
    }

    public static function buyerNit(
        string $nit,
        string $name,
        string $address = 'CIUDAD',
        string $city = '01010',
        string $district = 'GUATEMALA',
        string $state = 'GUATEMALA',
        string $country = 'GT',
        ?string $email = null
    ): array {
        $buyer = [
            'TaxID' => $nit,
            'Name'  => $name,
            'AddressInfo' => [
                'Address'  => $address,
                'City'     => $city,
                'District' => $district,
                'State'    => $state,
                'Country'  => $country,
            ],
        ];
        if ($email !== null) {
            $buyer['Contact'] = ['EmailList' => ['Email' => [$email]]];
        }
        return $buyer;
    }

    public static function buyerCui(string $taxid, string $name): array
    {
        return [
            'TaxID'     => $taxid,
            'TaxIDType' => 'CUI',
            'Name'      => $name,
            'AddressInfo' => [
                'Address'  => 'CIUDAD',
                'City'     => '01010',
                'District' => 'GUATEMALA',
                'State'    => 'GUATEMALA',
                'Country'  => 'GT',
            ],
        ];
    }

    // ── Seller builder ────────────────────────────────────────────────────────

    private static function buildSeller(
        string $taxid,
        string $name,
        string $address,
        string $afiliacion = 'GEN',
        ?string $tipoFrase = '1',
        ?string $escenario = '1',
        string $branchCode = '1',
        string $branchName = 'ESTABLECIMIENTO PRINCIPAL',
        string $city = '01010',
        string $district = 'Guatemala',
        string $state = 'Guatemala',
        string $country = 'GT',
        ?string $email = null,
        ?array $frases = null
    ): array {
        $seller = [
            'TaxID' => $taxid,
            'TaxIDAdditionalInfo' => [
                ['Name' => 'AfiliacionIVA', 'Data' => null, 'Value' => $afiliacion],
            ],
            'Name' => $name,
            'BranchInfo' => [
                'Code' => $branchCode,
                'Name' => $branchName,
                'AddressInfo' => [
                    'Address'  => $address,
                    'City'     => $city,
                    'District' => $district,
                    'State'    => $state,
                    'Country'  => $country,
                ],
            ],
        ];
        if ($email !== null) {
            $seller['Contact'] = ['EmailList' => ['Email' => [$email]]];
        }
        // AdditionlInfo — intentional typo per SAT/Digifact spec.
        // The API generates <dte:Frases> in the certified XML exclusively from this field.
        // Multiple frases are encoded as repeated TipoFrase/Escenario pairs in the same array.
        if ($frases !== null && count($frases) > 0) {
            // Data acts as the frase group index (1-based); all entries with Data='1' collapse to one frase.
            $ai = [];
            foreach ($frases as $i => $f) {
                $idx = (string)($i + 1);
                $ai[] = ['Name' => 'TipoFrase', 'Data' => $idx, 'Value' => (string)$f['tipo_frase']];
                $ai[] = ['Name' => 'Escenario',  'Data' => $idx, 'Value' => (string)$f['escenario']];
            }
            $seller['AdditionlInfo'] = $ai;
        } elseif ($tipoFrase !== null && $escenario !== null) {
            $seller['AdditionlInfo'] = [
                ['Name' => 'TipoFrase', 'Data' => '1', 'Value' => $tipoFrase],
                ['Name' => 'Escenario',  'Data' => '1', 'Value' => $escenario],
            ];
        }
        return $seller;
    }

    // ── Items builder ─────────────────────────────────────────────────────────

    /**
     * Build Items array and return [items, grandTotal, totalIva].
     *
     * @param list<array> $items Each item: description, qty, price, type, unit_of_measure, discount
     * @param bool $taxable
     * @return array{list<array>, string, string}
     */
    private static function buildItems(array $items, bool $taxable = true): array
    {
        $builtItems = [];
        $grandTotal = '0';
        $totalIva   = '0';

        foreach ($items as $i => $item) {
            $qty      = (string)($item['qty'] ?? 1);
            $price    = (string)$item['price'];
            $itemType = $item['type'] ?? 'Servicio';
            $uom      = $item['unit_of_measure'] ?? 'UNI';
            $desc     = $item['description'];
            $discount = isset($item['discount']) ? (string)$item['discount'] : '0';

            $calc = TaxHelper::calcLine($qty, $price, $taxable, $discount);

            $built = [
                'Number'        => (string)($i + 1),
                'Codes'         => null,
                'Type'          => $itemType,
                'Description'   => $desc,
                'Qty'           => $calc['qty'],
                'UnitOfMeasure' => $uom,
                'Price'         => $calc['price'],
            ];

            if ((float)$discount > 0) {
                $built['Discounts'] = ['Discount' => [['Amount' => $calc['discount']]]];
            } else {
                $built['Discounts'] = null;
            }

            if ($taxable) {
                $built['Taxes'] = [
                    'Tax' => [[
                        'Code'          => '1',
                        'Description'   => 'IVA',
                        'TaxableAmount' => $calc['taxable'],
                        'Amount'        => $calc['iva'],
                    ]],
                ];
            } else {
                $built['Taxes'] = null;
            }

            $built['Totals'] = ['TotalItem' => $calc['lineTotal']];

            $builtItems[] = $built;
            $grandTotal = \bcadd($grandTotal, $calc['lineTotal'], 10);
            $totalIva   = \bcadd($totalIva, $calc['iva'], 10);
        }

        return [
            $builtItems,
            TaxHelper::fmt($grandTotal),
            TaxHelper::fmt($totalIva),
        ];
    }

    private static function buildTotals(string $grandTotal, string $totalIva, bool $taxable): array
    {
        $totals = [];
        if ($taxable) {
            $totals['TotalTaxes'] = [
                'TotalTax' => [['Description' => 'IVA', 'Amount' => $totalIva]],
            ];
        }
        $totals['GrandTotal'] = ['InvoiceTotal' => $grandTotal];
        return $totals;
    }

    private static function buildAdenda(
        string $docType,
        string $amountStr,
        int $numItems,
        string $observaciones = '-',
        array $extraAditionalInfo = []
    ): array {
        $code = self::adendaCode($docType);

        $data = [
            [
                'Name' => 'INFORMACION_ADICIONAL',
                'Info' => [
                    ['Name' => 'OBSERVACIONES',   'Data' => null, 'Value' => $observaciones],
                    ['Name' => 'CANTIDAD_LETRAS',  'Data' => null, 'Value' => $amountStr],
                ],
            ],
        ];
        for ($i = 1; $i <= $numItems; $i++) {
            $data[] = [
                'Name' => 'DetallesAux_Detalle',
                'Info' => [
                    ['Name' => 'NumeroLinea',          'Data' => null, 'Value' => (string)$i],
                    ['Name' => 'Descripcion_Adicional', 'Data' => null, 'Value' => '-'],
                    ['Name' => 'CodigoEAN',             'Data' => null, 'Value' => '00001'],
                    ['Name' => 'CategoriaAdicional',    'Data' => null, 'Value' => '-'],
                ],
            ];
        }

        $aditionalInfo = [
            ['Name' => 'VALIDAR_REFERENCIA_INTERNA', 'Data' => null, 'Value' => 'NO_VALIDAR'],
        ];
        foreach ($extraAditionalInfo as $entry) {
            $aditionalInfo[] = $entry;
        }

        return [
            'AdditionalInfo' => [[
                'Code'         => $code,
                'Type'         => 'ADENDA',
                'AditionalData' => ['Data' => $data],
                'AditionalInfo' => $aditionalInfo,
            ]],
        ];
    }

    // ── Public builders ───────────────────────────────────────────────────────

    public static function buildFact(
        string $taxid,
        string $sellerName,
        string $sellerAddress,
        array $buyer,
        array $items,
        string $docType = 'FACT',
        string $afiliacion = 'GEN',
        ?string $tipoFrase = null,
        ?string $escenario = null,
        string $amountStr = '',
        string $observaciones = '-',
        ?array $extraHeader = null,
        ?string $sellerEmail = null,
        ?array $frases = null
    ): array {
        [$isoNow] = TaxHelper::gtNow();
        $taxable = !in_array($docType, self::NO_IVA_TYPES, true);

        [$lineItems, $grandTotal, $totalIva] = self::buildItems($items, $taxable);

        [$defTf, $defEs] = self::defaultFrase($docType, $afiliacion);
        $tf = $tipoFrase ?? $defTf;
        $es = $escenario ?? $defEs;

        $seller = self::buildSeller(
            $taxid, $sellerName, $sellerAddress,
            $afiliacion, $tf, $es,
            email: $sellerEmail, frases: $frases
        );

        $header = ['DocType' => $docType, 'IssuedDateTime' => $isoNow, 'Currency' => 'GTQ'];
        if ($extraHeader !== null) {
            $header = array_merge($header, $extraHeader);
        }

        $amt = $amountStr ?: $grandTotal;
        $adenda = self::buildAdenda($docType, $amt, count($items), $observaciones);

        return [
            'Version'     => '1.00',
            'CountryCode' => 'GT',
            'Header'      => $header,
            'Seller'      => $seller,
            'Buyer'       => $buyer,
            'ThirdParties' => null,
            'Items'       => $lineItems,
            'Totals'      => self::buildTotals($grandTotal, $totalIva, $taxable),
            'AdditionalDocumentInfo' => $adenda,
        ];
    }

    public static function buildFcam(
        string $taxid,
        string $sellerName,
        string $sellerAddress,
        array $buyer,
        array $items,
        array $paymentTerms,
        string $afiliacion = 'GEN',
        ?string $sellerEmail = null,
        ?string $tipoFrase = null,
        ?string $escenario = null,
        ?array $frases = null
    ): array {
        [$isoNow] = TaxHelper::gtNow();
        [$lineItems, $grandTotal, $totalIva] = self::buildItems($items, true);

        [$defTf, $defEs] = self::defaultFrase('FCAM', $afiliacion);
        $tf = $tipoFrase ?? $defTf;
        $es = $escenario ?? $defEs;

        $seller = self::buildSeller(
            $taxid, $sellerName, $sellerAddress,
            $afiliacion, $tf, $es,
            email: $sellerEmail, frases: $frases
        );

        $fcamData = [];
        foreach ($paymentTerms as $idx => $pt) {
            $amount = TaxHelper::fmt((string)$pt['amount'], 2);
            $fcamData[] = [
                'Info' => [
                    ['Name' => 'NumeroAbono',       'Data' => null, 'Value' => (string)($idx + 1)],
                    ['Name' => 'FechaVencimiento',  'Data' => null, 'Value' => $pt['date']],
                    ['Name' => 'MontoAbono',         'Data' => null, 'Value' => $amount],
                ],
            ];
        }

        return [
            'Version'     => '1.00',
            'CountryCode' => 'GT',
            'Header'      => ['DocType' => 'FCAM', 'IssuedDateTime' => $isoNow, 'Currency' => 'GTQ'],
            'Seller'      => $seller,
            'Buyer'       => $buyer,
            'ThirdParties' => null,
            'Items'       => $lineItems,
            'Totals'      => self::buildTotals($grandTotal, $totalIva, true),
            'AdditionalDocumentInfo' => [
                'AdditionalInfo' => [[
                    'Code'          => 'FCAMB',
                    'Type'          => 'COMPLEMENTO',
                    'AditionalData' => ['Data' => $fcamData],
                ]],
            ],
        ];
    }

    public static function buildNdeb(
        string $taxid,
        string $sellerName,
        string $sellerAddress,
        array $buyer,
        array $items,
        array $origin,
        string $reason,
        string $afiliacion = 'GEN',
        ?string $sellerEmail = null,
        ?string $tipoFrase = null,
        ?string $escenario = null,
        ?array $frases = null
    ): array {
        [$isoNow] = TaxHelper::gtNow();
        [$lineItems, $grandTotal, $totalIva] = self::buildItems($items, true);

        [$defTf, $defEs] = self::defaultFrase('NDEB', $afiliacion);
        $tf = $tipoFrase ?? $defTf;
        $es = $escenario ?? $defEs;

        $seller = self::buildSeller(
            $taxid, $sellerName, $sellerAddress,
            $afiliacion, $tf, $es,
            email: $sellerEmail, frases: $frases
        );

        return [
            'Version'     => '1.00',
            'CountryCode' => 'GT',
            'Header'      => ['DocType' => 'NDEB', 'IssuedDateTime' => $isoNow, 'Currency' => 'GTQ'],
            'Seller'      => $seller,
            'Buyer'       => $buyer,
            'ThirdParties' => null,
            'Items'       => $lineItems,
            'Totals'      => self::buildTotals($grandTotal, $totalIva, true),
            'AdditionalDocumentInfo' => [
                'AdditionalInfo' => [[
                    'Code' => 'NDEB',
                    'Type' => 'COMPLEMENTO',
                    // NDEB uses AditionalInfo (not AditionalData)
                    'AditionalInfo' => [
                        ['Name' => 'NumeroAutorizacionDocumentoOrigen', 'Data' => null, 'Value' => $origin['auth_number']],
                        ['Name' => 'FechaEmisionDocumentoOrigen',       'Data' => null, 'Value' => $origin['date']],
                        ['Name' => 'MotivoAjuste',                      'Data' => null, 'Value' => $reason],
                        ['Name' => 'SerieDocumentoOrigen',              'Data' => null, 'Value' => $origin['series']],
                        ['Name' => 'NumeroDocumentoOrigen',             'Data' => null, 'Value' => (string)$origin['number']],
                    ],
                ]],
            ],
        ];
    }

    public static function buildNcre(
        string $taxid,
        string $sellerName,
        string $sellerAddress,
        array $buyer,
        array $items,
        array $origin,
        string $reason,
        string $afiliacion = 'GEN',
        ?string $sellerEmail = null,
        ?string $tipoFrase = null,
        ?string $escenario = null,
        ?array $frases = null
    ): array {
        [$isoNow] = TaxHelper::gtNow();
        [$lineItems, $grandTotal, $totalIva] = self::buildItems($items, true);

        [$defTf, $defEs] = self::defaultFrase('NCRE', $afiliacion);
        $tf = $tipoFrase ?? $defTf;
        $es = $escenario ?? $defEs;

        $seller = self::buildSeller(
            $taxid, $sellerName, $sellerAddress,
            $afiliacion, $tf, $es,
            email: $sellerEmail, frases: $frases
        );

        return [
            'Version'     => '1.00',
            'CountryCode' => 'GT',
            'Header'      => ['DocType' => 'NCRE', 'IssuedDateTime' => $isoNow, 'Currency' => 'GTQ'],
            'Seller'      => $seller,
            'Buyer'       => $buyer,
            'ThirdParties' => null,
            'Items'       => $lineItems,
            'Totals'      => self::buildTotals($grandTotal, $totalIva, true),
            'AdditionalDocumentInfo' => [
                'AdditionalInfo' => [[
                    'Code' => 'NCRE',
                    'Type' => 'COMPLEMENTO',
                    // NCRE uses AditionalInfo (not AditionalData)
                    'AditionalInfo' => [
                        ['Name' => 'NumeroAutorizacionDocumentoOrigen', 'Data' => null, 'Value' => $origin['auth_number']],
                        ['Name' => 'FechaEmisionDocumentoOrigen',       'Data' => null, 'Value' => $origin['date']],
                        ['Name' => 'MotivoAjuste',                      'Data' => null, 'Value' => $reason],
                        ['Name' => 'NumeroDocumentoOrigen',             'Data' => null, 'Value' => (string)$origin['number']],
                        ['Name' => 'SerieDocumentoOrigen',              'Data' => null, 'Value' => $origin['series']],
                    ],
                ]],
            ],
        ];
    }

    public static function buildFesp(
        string $taxid,
        string $sellerName,
        string $sellerAddress,
        array $buyer,
        array $items,
        string $afiliacion = 'GEN',
        ?string $sellerEmail = null
    ): array {
        [$isoNow] = TaxHelper::gtNow();
        [$lineItems, $grandTotal, $totalIva] = self::buildItems($items, true);

        // FESP: no AdditionlInfo in seller
        $seller = self::buildSeller(
            $taxid, $sellerName, $sellerAddress,
            $afiliacion, null, null,
            email: $sellerEmail
        );

        // Calculate retenciones
        // taxable_total = grand_total / 1.12 * 1.12 — use actual total_taxable
        // Compute totalTaxable from items
        $totalTaxable = '0';
        foreach ($lineItems as $item) {
            $taxableAmt = $item['Taxes']['Tax'][0]['TaxableAmount'] ?? '0';
            $totalTaxable = \bcadd($totalTaxable, $taxableAmt, 10);
        }
        $retencionIsr = TaxHelper::fmt(\bcmul($totalTaxable, '0.05', 10));
        $retencionIva = $totalIva;
        $totalMenos = TaxHelper::fmt(\bcsub(\bcsub($grandTotal, $retencionIsr, 10), $retencionIva, 10));

        return [
            'Version'     => '1.00',
            'CountryCode' => 'GT',
            'Header'      => ['DocType' => 'FESP', 'IssuedDateTime' => $isoNow, 'Currency' => 'GTQ'],
            'Seller'      => $seller,
            'Buyer'       => $buyer,
            'ThirdParties' => null,
            'Items'       => $lineItems,
            'Totals'      => self::buildTotals($grandTotal, $totalIva, true),
            'AdditionalDocumentInfo' => [
                'AdditionalInfo' => [[
                    'Code' => 'FESP',
                    'Type' => 'COMPLEMENTO',
                    'AditionalInfo' => [
                        ['Name' => 'RetencionISR',            'Data' => null, 'Value' => $retencionIsr],
                        ['Name' => 'RetencionIVA',            'Data' => null, 'Value' => $retencionIva],
                        ['Name' => 'TotalMenosRetenciones',   'Data' => null, 'Value' => $totalMenos],
                    ],
                ]],
            ],
        ];
    }

    public static function buildRdon(
        string $taxid,
        string $sellerName,
        string $sellerAddress,
        array $buyer,
        array $items,
        string $tipoPersoneria,
        string $afiliacion = 'GEN',
        string $amountStr = '',
        string $observaciones = '-',
        ?string $sellerEmail = null,
        ?array $frases = null
    ): array {
        [$isoNow] = TaxHelper::gtNow();
        [$lineItems, $grandTotal] = self::buildItems($items, false);

        $seller = self::buildSeller(
            $taxid, $sellerName, $sellerAddress,
            $afiliacion, '4', '4',
            email: $sellerEmail, frases: $frases
        );

        $amt = $amountStr ?: $grandTotal;
        $adenda = self::buildAdenda('RDON', $amt, count($items), $observaciones);

        return [
            'Version'     => '1.00',
            'CountryCode' => 'GT',
            'Header'      => [
                'DocType'          => 'RDON',
                'IssuedDateTime'   => $isoNow,
                'Currency'         => 'GTQ',
                'AdditionalIssueDocInfo' => [
                    ['Name' => 'TipoPersoneria', 'Data' => null, 'Value' => $tipoPersoneria],
                ],
            ],
            'Seller'      => $seller,
            'Buyer'       => $buyer,
            'ThirdParties' => null,
            'Items'       => $lineItems,
            'Totals'      => self::buildTotals($grandTotal, '0.000000', false),
            'AdditionalDocumentInfo' => $adenda,
        ];
    }

    public static function buildFpeq(
        string $taxid,
        string $sellerName,
        string $sellerAddress,
        array $buyer,
        array $items,
        string $amountStr = '',
        string $observaciones = '-',
        ?string $sellerEmail = null,
        ?string $tipoFrase = null,
        ?string $escenario = null,
        ?array $frases = null
    ): array {
        [$isoNow] = TaxHelper::gtNow();
        [$lineItems, $grandTotal] = self::buildItems($items, false);

        [$defTf, $defEs] = self::defaultFrase('FPEQ', 'PEQ');
        $tf = $tipoFrase ?? $defTf;
        $es = $escenario ?? $defEs;

        $seller = self::buildSeller(
            $taxid, $sellerName, $sellerAddress,
            'PEQ', $tf, $es,
            email: $sellerEmail, frases: $frases
        );

        $amt = $amountStr ?: $grandTotal;
        $adenda = self::buildAdenda('FPEQ', $amt, count($items), $observaciones);

        return [
            'Version'     => '1.00',
            'CountryCode' => 'GT',
            'Header'      => ['DocType' => 'FPEQ', 'IssuedDateTime' => $isoNow, 'Currency' => 'GTQ'],
            'Seller'      => $seller,
            'Buyer'       => $buyer,
            'ThirdParties' => null,
            'Items'       => $lineItems,
            'Totals'      => self::buildTotals($grandTotal, '0.000000', false),
            'AdditionalDocumentInfo' => $adenda,
        ];
    }

    public static function buildReci(
        string $taxid,
        string $sellerName,
        string $sellerAddress,
        array $buyer,
        array $items,
        string $afiliacion = 'GEN',
        string $amountStr = '',
        string $observaciones = '-',
        string $studentName = 'Estudiante',
        string $studentId = '000000000',
        string $academicUnit = '01',
        ?string $sellerEmail = null,
        ?array $frases = null
    ): array {
        [$isoNow] = TaxHelper::gtNow();
        [$lineItems, $grandTotal] = self::buildItems($items, false);

        $seller = self::buildSeller(
            $taxid, $sellerName, $sellerAddress,
            $afiliacion, '4', '5',
            email: $sellerEmail, frases: $frases
        );

        $amt = $amountStr ?: $grandTotal;
        $extraInfo = [
            ['Name' => 'Tipo',             'Data' => null, 'Value' => 'Universidad'],
            ['Name' => 'NombreAlumno',     'Data' => null, 'Value' => $studentName],
            ['Name' => 'Carne',            'Data' => null, 'Value' => $studentId],
            ['Name' => 'UnidadAcademica',  'Data' => null, 'Value' => $academicUnit],
        ];
        $adenda = self::buildAdenda('RECI', $amt, count($items), $observaciones, $extraInfo);

        return [
            'Version'     => '1.00',
            'CountryCode' => 'GT',
            'Header'      => ['DocType' => 'RECI', 'IssuedDateTime' => $isoNow, 'Currency' => 'GTQ'],
            'Seller'      => $seller,
            'Buyer'       => $buyer,
            'ThirdParties' => null,
            'Items'       => $lineItems,
            'Totals'      => self::buildTotals($grandTotal, '0.000000', false),
            'AdditionalDocumentInfo' => $adenda,
        ];
    }

    public static function buildCca(
        string $taxid,
        string $sellerName,
        string $sellerAddress,
        array $buyer,
        array $items,
        array $cobros,
        string $afiliacion = 'GEN',
        ?string $sellerEmail = null,
        ?string $tipoFrase = null,
        ?string $escenario = null,
        ?array $frases = null
    ): array {
        [$isoNow] = TaxHelper::gtNow();
        [$lineItems, $grandTotal, $totalIva] = self::buildItems($items, true);

        [$defTf, $defEs] = self::defaultFrase('FACT', $afiliacion);
        $tf = $tipoFrase ?? $defTf;
        $es = $escenario ?? $defEs;

        $seller = self::buildSeller(
            $taxid, $sellerName, $sellerAddress,
            $afiliacion, $tf, $es,
            email: $sellerEmail, frases: $frases
        );

        $ccaData = [];
        foreach ($cobros as $cobro) {
            $ccaData[] = [
                'Info' => [
                    ['Name' => 'NITtercero',       'Data' => null, 'Value' => (string)$cobro['nit_tercero']],
                    ['Name' => 'NumeroDocumento',   'Data' => null, 'Value' => (string)$cobro['numero_documento']],
                    ['Name' => 'FechaDocumento',    'Data' => null, 'Value' => $cobro['fecha_documento']],
                    ['Name' => 'Descripcion',       'Data' => null, 'Value' => $cobro['descripcion']],
                    ['Name' => 'BaseImponible',     'Data' => null, 'Value' => TaxHelper::fmt((string)$cobro['base_imponible'], 2)],
                    ['Name' => 'MontoCobroDAI',     'Data' => null, 'Value' => TaxHelper::fmt((string)($cobro['monto_cobro_dai'] ?? 0), 2)],
                    ['Name' => 'MontoCobroIVA',     'Data' => null, 'Value' => TaxHelper::fmt((string)$cobro['monto_cobro_iva'], 2)],
                    ['Name' => 'MontoCobroOtros',   'Data' => null, 'Value' => TaxHelper::fmt((string)($cobro['monto_cobro_otros'] ?? 0), 2)],
                    ['Name' => 'MontoCobroTotal',   'Data' => null, 'Value' => TaxHelper::fmt((string)$cobro['monto_cobro_total'], 2)],
                ],
            ];
        }

        return [
            'Version'     => '1.00',
            'CountryCode' => 'GT',
            'Header'      => ['DocType' => 'FACT', 'IssuedDateTime' => $isoNow, 'Currency' => 'GTQ'],
            'Seller'      => $seller,
            'Buyer'       => $buyer,
            'ThirdParties' => null,
            'Items'       => $lineItems,
            'Totals'      => self::buildTotals($grandTotal, $totalIva, true),
            'AdditionalDocumentInfo' => [
                'AdditionalInfo' => [[
                    'Code'          => 'CCA',
                    'Type'          => 'COMPLEMENTO',
                    'AditionalData' => ['Data' => $ccaData],
                ]],
            ],
        ];
    }

    // ── Combustible (fuel) builders ───────────────────────────────────────────

    /**
     * Build Items array for a combustible (fuel) invoice.
     *
     * Items with a 'petroleo_amount' key are treated as fuel items and receive two
     * Tax entries (IVA + PETROLEO). Items without 'petroleo_amount' are treated as
     * regular IVA-only items. Both types may coexist in the same invoice.
     *
     * @param list<array> $items
     * @return array{list<array>, string $grandTotal, string $totalIva, string $totalPetroleo}
     */
    private static function buildFuelItems(array $items): array
    {
        $builtItems    = [];
        $grandTotal    = '0';
        $totalIva      = '0';
        $totalPetroleo = '0';

        foreach ($items as $i => $item) {
            $qty      = (string)($item['qty'] ?? 1);
            $price    = (string)$item['price'];
            $itemType = $item['type'] ?? 'Bien';
            $uom      = $item['unit_of_measure'] ?? 'UNI';
            $desc     = $item['description'];

            $built = [
                'Number'        => (string)($i + 1),
                'Codes'         => null,
                'Type'          => $itemType,
                'Description'   => $desc,
                'UnitOfMeasure' => $uom,
                'Discounts'     => null,
            ];

            if (isset($item['petroleo_amount'])) {
                $petrolPerUnit = (string)$item['petroleo_amount'];
                $petrolCode    = (string)($item['petroleo_code'] ?? '1');

                $calc = TaxHelper::calcFuelLine($qty, $price, $petrolPerUnit);

                $built['Qty']   = $calc['qty'];
                $built['Price'] = $calc['price'];
                $built['Taxes'] = [
                    'Tax' => [
                        [
                            'Code'          => '1',
                            'Description'   => 'IVA',
                            'TaxableAmount' => $calc['taxable'],
                            'Amount'        => $calc['iva'],
                        ],
                        [
                            'Code'          => $petrolCode,
                            'Description'   => 'PETROLEO',
                            'TaxableAmount' => $calc['qty'],
                            'Amount'        => $calc['petrol'],
                        ],
                    ],
                ];
                $built['Totals'] = ['TotalItem' => $calc['lineTotal']];

                $grandTotal    = \bcadd($grandTotal, $calc['lineTotal'], 10);
                $totalIva      = \bcadd($totalIva, $calc['iva'], 10);
                $totalPetroleo = \bcadd($totalPetroleo, $calc['petrol'], 10);
            } else {
                $calc = TaxHelper::calcLine($qty, $price, true);

                $built['Qty']   = $calc['qty'];
                $built['Price'] = $calc['price'];
                $built['Taxes'] = [
                    'Tax' => [[
                        'Code'          => '1',
                        'Description'   => 'IVA',
                        'TaxableAmount' => $calc['taxable'],
                        'Amount'        => $calc['iva'],
                    ]],
                ];
                $built['Totals'] = ['TotalItem' => $calc['lineTotal']];

                $grandTotal = \bcadd($grandTotal, $calc['lineTotal'], 10);
                $totalIva   = \bcadd($totalIva, $calc['iva'], 10);
            }

            $builtItems[] = $built;
        }

        return [
            $builtItems,
            TaxHelper::fmt($grandTotal),
            TaxHelper::fmt($totalIva),
            TaxHelper::fmt($totalPetroleo),
        ];
    }

    private static function buildFuelAdenda(): array
    {
        return [
            'AdditionalInfo' => [[
                'Code'          => '00000013',
                'Type'          => 'ADENDA',
                'AditionalInfo' => [
                    ['Name' => 'VALIDAR_REFERENCIA_INTERNA', 'Data' => null, 'Value' => 'NO_VALIDAR'],
                ],
            ]],
        ];
    }

    /**
     * Build a FACT payload for combustible (fuel) invoices.
     *
     * Fuel items must include 'petroleo_amount' (per-unit PETROLEO tax amount) and
     * optionally 'petroleo_code' (SAT code: "1"=SUPER, "2"=REGULAR, "4"=DIESEL; default "1").
     * Items without 'petroleo_amount' are treated as regular IVA-only items.
     *
     * $frases and $tipoFrase/$escenario are mutually exclusive.
     *
     * Example fuel item:
     *   ['description' => 'GASOLINA SUPER', 'qty' => 1, 'price' => 30.30,
     *    'petroleo_amount' => 4.70, 'petroleo_code' => '1', 'type' => 'Bien']
     *
     * Example regular item in the same invoice:
     *   ['description' => 'FILTRO DE ACEITE', 'qty' => 1, 'price' => 45.00, 'type' => 'Bien']
     */
    public static function buildFactCombustible(
        string $taxid,
        string $sellerName,
        string $sellerAddress,
        array $buyer,
        array $items,
        string $afiliacion = 'GEN',
        ?string $tipoFrase = null,
        ?string $escenario = null,
        ?string $sellerEmail = null,
        ?array $frases = null
    ): array {
        if ($frases !== null && ($tipoFrase !== null || $escenario !== null)) {
            throw new DigifactValidationException(
                'frases and tipoFrase/escenario are mutually exclusive; use one or the other'
            );
        }

        [$isoNow] = TaxHelper::gtNow();

        [$lineItems, $grandTotal, $totalIva, $totalPetroleo] = self::buildFuelItems($items);

        [$defTf, $defEs] = self::defaultFrase('FACT', $afiliacion);
        $tf = $tipoFrase ?? $defTf;
        $es = $escenario ?? $defEs;

        $resolvedFrases = self::resolveFuelFrases($frases, $tf, $es);

        // All frases go into Seller.AdditionlInfo as repeated pairs —
        // that is the field the API reads to generate <dte:Frases> in the certified XML.
        $seller = self::buildSeller(
            $taxid, $sellerName, $sellerAddress,
            $afiliacion, null, null,
            email: $sellerEmail, frases: $resolvedFrases
        );

        $totalTax = [['Description' => 'IVA', 'Amount' => $totalIva]];
        if ((float)$totalPetroleo > 0.0) {
            $totalTax[] = ['Description' => 'PETROLEO', 'Amount' => $totalPetroleo];
        }

        return [
            'Version'     => '1.00',
            'CountryCode' => 'GT',
            'Header'      => ['DocType' => 'FACT', 'IssuedDateTime' => $isoNow, 'Currency' => 'GTQ'],
            'Seller'      => $seller,
            'Buyer'       => $buyer,
            'ThirdParties' => null,
            'Items'       => $lineItems,
            'Totals'      => [
                'TotalTaxes' => ['TotalTax' => $totalTax],
                'GrandTotal' => ['InvoiceTotal' => $grandTotal],
            ],
            'AdditionalDocumentInfo' => self::buildFuelAdenda(),
        ];
    }
}
