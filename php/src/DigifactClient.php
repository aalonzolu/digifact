<?php

declare(strict_types=1);

namespace Digifact\Fel;

/**
 * DigifactClient — main entry point for the Digifact FEL Guatemala PHP SDK.
 */
class DigifactClient
{
    private string $taxid;
    private string $paddedTaxid;
    private string $username;
    private string $password;
    private string $baseUrl;
    private string $token = '';
    private string $sellerName;
    private string $sellerAddress;
    private string $afiliacionIva;
    private string $tipoPersoneria;
    private ?string $tipoFrase;
    private ?string $escenario;
    /** @var array<array{tipo_frase:string,escenario:string}>|null */
    private ?array $frases;
    private string $branchCode;
    private string $branchName;
    private int $timeout;
    private array $nitCache = [];
    private array $cuiCache = [];
    /** @var array<string,float> PETROLEO code → per-unit amount */
    private array $petroleoRates = [];

    private const BASE_URLS = [
        'test'       => 'https://testnucgt.digifact.com/api',
        'production' => 'https://nucgt.digifact.com/gt.com.apinuc/api',
    ];

    /** Digits in a Guatemalan CUI (DPI); a NIT never reaches this length. */
    private const CUI_LENGTH = 13;

    /**
     * @param array $config Configuration keys (all except taxid/username/password are optional):
     *
     *   Authentication
     *   --------------
     *   - taxid (string, required): Fiscal ID / NIT of the issuer. Digits only or
     *       with separators (e.g. "12345678" or "1234567-8"); non-digits are stripped.
     *   - username (string, required): Short Digifact username (the part after the
     *       "GT.<NIT>." prefix, e.g. "FELUSER").
     *   - password (string): Account password. Required unless `token` is supplied.
     *   - token (string): Pre-obtained Bearer token. When provided, login is skipped
     *       and `password` is not needed.
     *   - environment (string): "test" (default) or "production".
     *
     *   Seller / RTU information
     *   ------------------------
     *   - seller_name (string): Overrides the issuer display name. When empty, the
     *       SDK resolves it from SAT via `lookupNit($taxid)`.
     *   - seller_address (string): Overrides the issuer address. When empty, the
     *       SDK resolves it from SAT via `lookupNit($taxid)`.
     *   - afiliacion_iva (string): IVA affiliation recorded in SAT RTU.
     *       One of "GEN" (default), "PEQ" (Pequeño Contribuyente) or "EXE" (Exento).
     *   - tipo_personeria (string): TipoPersoneria code from RTU (used by RDON).
     *       Default "1".
     *   - branch_code (string): Código del establecimiento (SAT RTU). Each NIT may
     *       have several establecimientos (1, 2, 3, …); "1" is usually the principal.
     *       Default "1".
     *   - branch_name (string): Nombre comercial del establecimiento.
     *       Default "ESTABLECIMIENTO PRINCIPAL".
     *
     *   Frase / escenario (SAT AdditionlInfo)
     *   -------------------------------------
     *   - tipo_frase (string|null): Global override for TipoFrase. When null
     *       (default), the SDK uses {@see DteBuilder::defaultFrase()} based on
     *       DocType + afiliacion_iva. Can be overridden per-call.
     *   - escenario (string|null): Global override for CodigoEscenario. When null
     *       (default), uses the defaults table. For GEN, common values are
     *       "1" (ISR régimen sobre utilidades, default) or "2" (ISR opcional
     *       simplificado sobre ingresos).
     *
     *   Misc
     *   ----
     *   - timeout (int): HTTP request timeout in seconds. Default 120.
     *   - petroleo_rates (array<string,float>): Map of PETROLEO code → per-unit tax
     *       amount, e.g. ["1" => 4.70, "2" => 4.60, "4" => 1.30]
     *       (SUPER / REGULAR / DIESEL). Used by {@see fuelInvoice()} when items
     *       provide `petroleo_code` but omit `petroleo_amount`.
     */
    public function __construct(array $config)
    {
        if (!extension_loaded('bcmath')) {
            throw new \RuntimeException(
                "The bcmath PHP extension is required by digifact-sdk but is not installed. " .
                "Install it with: apt-get install php-bcmath  OR  pecl install bcmath  " .
                "and enable it in your php.ini (extension=bcmath)."
            );
        }

        $taxid = $config['taxid'] ?? '';
        $this->taxid = preg_replace('/\D/', '', $taxid);
        $this->paddedTaxid = TaxHelper::padTaxid($taxid);
        $this->username = $config['username'] ?? '';
        $this->password = $config['password'] ?? '';
        $this->token = $config['token'] ?? '';
        $this->sellerName = $config['seller_name'] ?? '';
        $this->sellerAddress = $config['seller_address'] ?? '';
        $this->afiliacionIva = $config['afiliacion_iva'] ?? 'GEN';
        $this->tipoPersoneria = $config['tipo_personeria'] ?? '1';
        $this->tipoFrase = isset($config['tipo_frase']) ? (string)$config['tipo_frase'] : null;
        $this->escenario = isset($config['escenario']) ? (string)$config['escenario'] : null;
        $this->frases = $config['frases'] ?? null;
        $this->branchCode = (string)($config['branch_code'] ?? '1');
        $this->branchName = (string)($config['branch_name'] ?? 'ESTABLECIMIENTO PRINCIPAL');
        $this->timeout = (int)($config['timeout'] ?? 120);
        $this->petroleoRates = $config['petroleo_rates'] ?? [];

        if (!$this->taxid) {
            throw new \InvalidArgumentException("taxid must contain at least one digit");
        }
        if (!$this->username) {
            throw new \InvalidArgumentException("username is required");
        }
        if (!$this->token && !$this->password) {
            throw new \InvalidArgumentException("password or token is required");
        }
        if ($this->frases !== null && ($this->tipoFrase !== null || $this->escenario !== null)) {
            throw new \InvalidArgumentException(
                "frases and tipo_frase/escenario are mutually exclusive; use one or the other"
            );
        }

        $env = $config['environment'] ?? 'test';
        if (!isset(self::BASE_URLS[$env])) {
            throw new \InvalidArgumentException("environment must be 'test' or 'production', got '$env'");
        }
        $this->baseUrl = rtrim(self::BASE_URLS[$env], '/');
    }

    public function __debugInfo(): array
    {
        return [
            'taxid'       => $this->taxid,
            'username'    => $this->username,
            'environment' => array_search($this->baseUrl, self::BASE_URLS) ?: 'custom',
            'password'    => '***',
        ];
    }

    // ── Authentication ────────────────────────────────────────────────────────

    private function login(): string
    {
        if ($this->token) {
            return $this->token;
        }
        if (!$this->password) {
            throw new DigifactAuthException('password or token is required');
        }
        $fullUsername = "GT.{$this->paddedTaxid}.{$this->username}";
        $data = $this->post('/login/get_token', [
            'Username' => $fullUsername,
            'Password' => $this->password,
        ], false);

        $tok = $data['Token'] ?? $data['token'] ?? null;
        if (!$tok) {
            throw new DigifactAuthException('Login succeeded but response contained no token', 0, $data);
        }
        $this->token = $tok;
        return $this->token;
    }

    private function authHeaders(): array
    {
        return ['Authorization: ' . $this->login(), 'Content-Type: application/json'];
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    /**
     * Perform a POST request.
     *
     * @param bool $withAuth Whether to include the Authorization header
     */
    private function post(string $path, array $body, bool $withAuth = true, array $queryParams = []): array
    {
        $url = $this->baseUrl . $path;
        if ($queryParams) {
            $url .= '?' . http_build_query($queryParams);
        }
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        $headers = ['Content-Type: application/json'];
        if ($withAuth) {
            $headers[] = 'Authorization: ' . $this->login();
        }
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $json,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => $this->timeout,
            CURLOPT_CONNECTTIMEOUT  => min($this->timeout, 30),
            CURLOPT_SSL_VERIFYPEER  => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new DigifactApiException("cURL error: $error");
        }
        if ($httpCode >= 400) {
            $preview = mb_strlen($response) > 300 ? mb_substr($response, 0, 300) . '…' : $response;
            throw new DigifactApiException("HTTP $httpCode: $preview", $httpCode);
        }

        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }

    private function get(string $path, array $queryParams = []): array
    {
        $url = $this->baseUrl . $path;
        if ($queryParams) {
            $url .= '?' . http_build_query($queryParams);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER      => $this->authHeaders(),
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => $this->timeout,
            CURLOPT_CONNECTTIMEOUT  => min($this->timeout, 30),
            CURLOPT_SSL_VERIFYPEER  => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new DigifactApiException("cURL error: $error");
        }
        if ($httpCode >= 400) {
            $preview = mb_strlen($response) > 300 ? mb_substr($response, 0, 300) . '…' : $response;
            throw new DigifactApiException("HTTP $httpCode: $preview", $httpCode);
        }

        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    // ── Seller info ───────────────────────────────────────────────────────────

    private function getSellerInfo(): array
    {
        if ($this->sellerName && $this->sellerAddress) {
            return [$this->sellerName, $this->sellerAddress];
        }
        try {
            $info = $this->lookupNit($this->taxid);
            if (!$this->sellerName) {
                $this->sellerName = $info['name'] ?: "EMISOR {$this->taxid}";
            }
            if (!$this->sellerAddress) {
                $this->sellerAddress = $info['address'] ?: 'CIUDAD';
            }
        } catch (DigifactException $e) {
            if (!$this->sellerName) {
                $this->sellerName = "EMISOR {$this->taxid}";
            }
            if (!$this->sellerAddress) {
                $this->sellerAddress = 'CIUDAD';
            }
        }
        return [$this->sellerName, $this->sellerAddress];
    }

    // ── Buyer resolution ──────────────────────────────────────────────────────

    private function resolveBuyer(string|array $buyer): array
    {
        if (is_string($buyer)) {
            if (strtoupper($buyer) === 'CF') {
                return DteBuilder::buyerCf();
            }
            $digits = preg_replace('/\D/', '', $buyer);
            if ($digits !== '') {
                // A CUI is always 13 digits; a NIT never reaches that length.
                if (strlen($digits) === self::CUI_LENGTH) {
                    $info = $this->lookupCui($digits);
                    return DteBuilder::buyerCui($digits, $info['name'] ?: $digits);
                }
                $info = $this->lookupNit($digits);
                return DteBuilder::buyerNit(
                    $digits,
                    $info['name'] ?: $digits,
                    $info['address'] ?? 'CIUDAD',
                    $info['city'] ?? '01010',
                    $info['district'] ?? 'GUATEMALA',
                    $info['state'] ?? 'GUATEMALA',
                );
            }
            throw new DigifactException("Cannot resolve buyer: $buyer");
        }

        $type = strtoupper($buyer['type'] ?? '');
        if ($type === 'CUI') {
            $cui  = (string)$buyer['taxid'];
            $name = $buyer['name'] ?? '';
            if ($name === '') {
                $digits = preg_replace('/\D/', '', $cui);
                $name = $this->lookupCui($digits)['name'] ?: $digits;
            }
            return DteBuilder::buyerCui($cui, $name);
        }
        return DteBuilder::buyerNit(
            (string)$buyer['taxid'],
            $buyer['name'],
            $buyer['address'] ?? 'CIUDAD',
            $buyer['city'] ?? '01010',
            $buyer['district'] ?? 'GUATEMALA',
            $buyer['state'] ?? 'GUATEMALA',
            $buyer['country'] ?? 'GT',
            $buyer['email'] ?? null,
        );
    }

    // ── Certify ───────────────────────────────────────────────────────────────

    /**
     * Apply configured branch_code / branch_name onto Seller.BranchInfo.
     * Called on every payload just before certification so that callers do not
     * need to plumb branch info through every builder.
     */
    private function applyBranchInfo(array $payload): array
    {
        if (isset($payload['Seller']['BranchInfo'])) {
            $payload['Seller']['BranchInfo']['Code'] = $this->branchCode;
            $payload['Seller']['BranchInfo']['Name'] = $this->branchName;
        }
        return $payload;
    }

    private function certify(array $payload): array
    {
        $payload = $this->applyBranchInfo($payload);
        $data = $this->post('/v2/transform/nuc_json', $payload, true, [
            'TAXID'    => $this->paddedTaxid,
            'FORMAT'   => 'XML|HTML|PDF',
            'USERNAME' => $this->username,
        ]);
        return $this->checkResponse($data);
    }

    private function checkResponse(array $data): array
    {
        $code = $data['code'] ?? null;
        if ($code === null) {
            $auth = $data['authNumber'] ?? $data['Autorizacion'] ?? null;
            if ($auth !== null) {
                return $data;
            }
            return $data;
        }

        $codeInt = (int)$code;
        if ($codeInt === 1) {
            return $data;
        }

        $msg = $data['description'] ?? $data['message'] ?? json_encode($data);
        $hint = $this->classifyError($msg);
        $fullMsg = "DTE rejected (code=$codeInt): $msg";
        if ($hint) {
            $fullMsg .= "\n\nHint: $hint";
        }
        if ($codeInt === 0) {
            throw new DigifactValidationException($fullMsg, $codeInt, $data);
        }
        throw new DigifactApiException($fullMsg, $codeInt, $data);
    }

    private function classifyError(string $message): ?string
    {
        $upper = strtoupper($message);
        $hints = [
            [
                'keys' => ['AfiliacionIVA', 'MINIRTU', 'RTU'],
                'hint' => 'El NIT del emisor no tiene la afiliación de IVA indicada en su RTU (AfiliacionIVA). '
                    . 'Verifica que AfiliacionIVA sea GEN, PEQ o EXE según el RTU del emisor.',
            ],
            [
                'keys' => ['TipoPersoneria'],
                'hint' => 'El valor de TipoPersoneria debe coincidir exactamente con el código registrado en el '
                    . 'RTU del emisor. Consulta el RTU en portal.sat.gob.gt.',
            ],
            [
                'keys' => ['SECCION 3.5', '9015', 'ID Receptor'],
                'hint' => 'NCRE/NDEB: el receptor del documento origen debe coincidir con el receptor del '
                    . 'documento de nota.',
            ],
            [
                'keys' => ['NAMESPACE_UNKNOWN', '5035'],
                'hint' => 'El complemento CCA requiere que el emisor tenga habilitado el namespace CCA en Digifact. '
                    . 'Contacta a soporte@digifact.com.gt.',
            ],
            [
                'keys' => ['FRASES'],
                'hint' => 'Este tipo de DTE no permite las frases indicadas. Para FESP no incluyas TipoFrase/Escenario.',
            ],
            [
                'keys' => ['FechaEmision', 'FECHA'],
                'hint' => "Verifica el formato de fecha: 'YYYY-MM-DD HH:MM:SS' para cancelaciones, "
                    . "o 'YYYY-MM-DDTHH:MM:SS-06:00' para IssuedDateTime.",
            ],
        ];

        foreach ($hints as ['keys' => $keywords, 'hint' => $hint]) {
            foreach ($keywords as $kw) {
                if (str_contains($upper, strtoupper($kw))) {
                    return $hint;
                }
            }
        }
        return null;
    }

    // ── Public DTE methods ────────────────────────────────────────────────────

    /**
     * Resolve (TipoFrase, CodigoEscenario) with precedence:
     *   1) $opts['tipo_frase'] / $opts['escenario'] (per-call override)
     *   2) constructor globals (tipo_frase / escenario)
     *   3) DteBuilder::defaultFrase($docType, afiliacion) table
     *
     * Returns [?string, ?string]. Either component can be null when the builder
     * should omit frases entirely (e.g. FESP).
     *
     * @return array{0:?string,1:?string}
     */
    private function resolveFrase(string $docType, array $opts): array
    {
        $default = DteBuilder::defaultFrase($docType, $this->afiliacionIva);
        [$defTf, $defEs] = $default ?? [null, null];

        $tf = $opts['tipo_frase'] ?? $this->tipoFrase ?? $defTf;
        $es = $opts['escenario']  ?? $this->escenario  ?? $defEs;

        return [
            $tf !== null ? (string)$tf : null,
            $es !== null ? (string)$es : null,
        ];
    }

    /**
     * Emit a DTE invoice.
     *
     * @param string|array $buyer  "CF", NIT string, CUI array, or buyer array
     * @param list<array>  $items  Item arrays with keys: description, qty, price, type
     * @param array        $opts   Options: doc_type, payment_terms, amount_str,
     *                              observaciones, tipo_personeria, tipo_frase, escenario
     */
    public function invoice(string|array $buyer, array $items, array $opts = []): DteResult
    {
        [$sellerName, $sellerAddress] = $this->getSellerInfo();
        $buyerArr = $this->resolveBuyer($buyer);
        $docType = $opts['doc_type'] ?? 'FACT';
        $amountStr = $opts['amount_str'] ?? '';
        $observaciones = $opts['observaciones'] ?? '-';

        $hasPerCallFrase = isset($opts['tipo_frase']) || isset($opts['escenario']);
        if ($this->frases !== null && !$hasPerCallFrase) {
            $effFrases = $this->frases;
            $tipoFrase = $escenario = null;
        } else {
            $effFrases = null;
            [$tipoFrase, $escenario] = $this->resolveFrase($docType, $opts);
        }

        switch ($docType) {
            case 'FCAM':
                $pt = $opts['payment_terms'] ?? [];
                if (!$pt) {
                    throw new DigifactValidationException('payment_terms is required for FCAM');
                }
                $payload = DteBuilder::buildFcam(
                    $this->taxid, $sellerName, $sellerAddress, $buyerArr, $items, $pt,
                    $this->afiliacionIva, null, $tipoFrase, $escenario, $effFrases
                );
                break;

            case 'FESP':
                $payload = DteBuilder::buildFesp(
                    $this->taxid, $sellerName, $sellerAddress, $buyerArr, $items, $this->afiliacionIva
                );
                break;

            case 'NABN':
                $payload = DteBuilder::buildFact(
                    $this->taxid, $sellerName, $sellerAddress, $buyerArr, $items,
                    'NABN', $this->afiliacionIva, $tipoFrase, $escenario, $amountStr, $observaciones,
                    null, null, $effFrases
                );
                break;

            case 'RDON':
                $tp = $opts['tipo_personeria'] ?? $this->tipoPersoneria;
                $payload = DteBuilder::buildRdon(
                    $this->taxid, $sellerName, $sellerAddress, $buyerArr, $items,
                    $tp, $this->afiliacionIva, $amountStr, $observaciones, null, $effFrases
                );
                break;

            case 'FPEQ':
                $payload = DteBuilder::buildFpeq(
                    $this->taxid, $sellerName, $sellerAddress, $buyerArr, $items,
                    $amountStr, $observaciones, null, $tipoFrase, $escenario, $effFrases
                );
                break;

            case 'RECI':
                $payload = DteBuilder::buildReci(
                    $this->taxid, $sellerName, $sellerAddress, $buyerArr, $items,
                    $this->afiliacionIva, $amountStr, $observaciones,
                    'Estudiante', '000000000', '01', null, $effFrases
                );
                break;

            default:
                // FACT (default), handles CUI automatically via buyer type
                $payload = DteBuilder::buildFact(
                    $this->taxid, $sellerName, $sellerAddress, $buyerArr, $items,
                    $docType, $this->afiliacionIva, $tipoFrase, $escenario, $amountStr, $observaciones,
                    null, null, $effFrases
                );
                break;
        }

        $data = $this->certify($payload);
        return DteResult::fromArray($data);
    }

    /**
     * Emit a CCA (Cobro por Cuenta Ajena) FACT+CCA complemento.
     *
     * @param array $opts Options: tipo_frase, escenario
     */
    public function ccaInvoice(string|array $buyer, array $items, array $cobros, array $opts = []): DteResult
    {
        [$sellerName, $sellerAddress] = $this->getSellerInfo();
        $buyerArr = $this->resolveBuyer($buyer);
        $hasPerCallFrase = isset($opts['tipo_frase']) || isset($opts['escenario']);
        if ($this->frases !== null && !$hasPerCallFrase) {
            $effFrases = $this->frases;
            $tipoFrase = $escenario = null;
        } else {
            $effFrases = null;
            [$tipoFrase, $escenario] = $this->resolveFrase('FACT', $opts);
        }
        $payload = DteBuilder::buildCca(
            $this->taxid, $sellerName, $sellerAddress, $buyerArr, $items, $cobros, $this->afiliacionIva,
            null, $tipoFrase, $escenario, $effFrases
        );
        $data = $this->certify($payload);
        return DteResult::fromArray($data);
    }

    /**
     * Emit a combustible (fuel) FACT invoice.
     *
     * Fuel items must include 'petroleo_amount' (per-unit PETROLEO tax) and optionally
     * 'petroleo_code' ("1"=SUPER, "2"=REGULAR, "4"=DIESEL; default "1").
     * Items without 'petroleo_amount' are treated as regular IVA-only items.
     *
     * @param array $items Each fuel item: description, qty, price, petroleo_amount, petroleo_code, type, unit_of_measure
     * @param array $opts  Options: tipo_frase, escenario, frases
     *
     * $opts['frases'] is an explicit list of ['tipo_frase' => ..., 'escenario' => ...] pairs.
     * Mutually exclusive with tipo_frase/escenario.
     *
     * The SAT fuel subsidy has ended, so nothing subsidy-related is ever sent on its own.
     * If you are still dispatching inventory bought under the subsidy scheme, pass the
     * frases yourself:
     *
     *   $opts['frases'] = [
     *       ['tipo_frase' => '1', 'escenario' => '1'],
     *       ['tipo_frase' => '9', 'escenario' => '18'],
     *       ['tipo_frase' => '9', 'escenario' => '19'],
     *   ];
     */
    public function fuelInvoice(string|array $buyer, array $items, array $opts = []): DteResult
    {
        $callFrases    = $opts['frases']    ?? null;
        $callTipoFrase = $opts['tipo_frase'] ?? null;
        $callEscenario = $opts['escenario']  ?? null;

        if ($callFrases !== null && ($callTipoFrase !== null || $callEscenario !== null)) {
            throw new DigifactValidationException(
                'frases and tipo_frase/escenario are mutually exclusive; use one or the other'
            );
        }

        // Resolve effective frases: per-call → constructor → legacy tipo_frase/escenario
        if ($callFrases !== null) {
            $effFrases    = $callFrases;
            $effTipoFrase = null;
            $effEscenario = null;
        } elseif ($this->frases !== null) {
            $effFrases    = $this->frases;
            $effTipoFrase = null;
            $effEscenario = null;
        } else {
            $effFrases = null;
            [$effTipoFrase, $effEscenario] = $this->resolveFrase('FACT', $opts);
        }

        [$sellerName, $sellerAddress] = $this->getSellerInfo();
        $buyerArr = $this->resolveBuyer($buyer);
        $resolved = $this->applyPetroleoRates($items);
        $payload = DteBuilder::buildFactCombustible(
            $this->taxid, $sellerName, $sellerAddress, $buyerArr, $resolved, $this->afiliacionIva,
            $effTipoFrase, $effEscenario, null, $effFrases
        );
        $data = $this->certify($payload);
        return DteResult::fromArray($data);
    }

    /** @param array[] $items */
    private function applyPetroleoRates(array $items): array
    {
        return array_map(function (array $item): array {
            $code = $item['petroleo_code'] ?? null;
            if ($code !== null && !isset($item['petroleo_amount'])) {
                $rate = $this->petroleoRates[(string)$code] ?? null;
                if ($rate === null) {
                    throw new DigifactValidationException(
                        sprintf(
                            "Item '%s' has petroleo_code='%s' but no petroleo_amount "
                                . 'and no matching rate in petroleo_rates.',
                            $item['description'] ?? '',
                            $code
                        )
                    );
                }
                $item['petroleo_amount'] = $rate;
            }
            return $item;
        }, $items);
    }

    /**
     * Emit a NCRE (Nota de Crédito).
     *
     * @param array $origin ["auth_number" => ..., "date" => "YYYY-MM-DD", "series" => ..., "number" => ...]
     * @param array $opts   Options: tipo_frase, escenario
     */
    public function creditNote(string|array $buyer, array $items, array $origin, string $reason, array $opts = []): DteResult
    {
        [$sellerName, $sellerAddress] = $this->getSellerInfo();
        $buyerArr = $this->resolveBuyer($buyer);
        $hasPerCallFrase = isset($opts['tipo_frase']) || isset($opts['escenario']);
        if ($this->frases !== null && !$hasPerCallFrase) {
            $effFrases = $this->frases;
            $tipoFrase = $escenario = null;
        } else {
            $effFrases = null;
            [$tipoFrase, $escenario] = $this->resolveFrase('NCRE', $opts);
        }
        $payload = DteBuilder::buildNcre(
            $this->taxid, $sellerName, $sellerAddress, $buyerArr, $items,
            $origin, $reason, $this->afiliacionIva, null, $tipoFrase, $escenario, $effFrases
        );
        $data = $this->certify($payload);
        return DteResult::fromArray($data);
    }

    /**
     * Emit a NDEB (Nota de Débito).
     *
     * @param array $origin ["auth_number" => ..., "date" => "YYYY-MM-DD", "series" => ..., "number" => ...]
     * @param array $opts   Options: tipo_frase, escenario
     */
    public function debitNote(string|array $buyer, array $items, array $origin, string $reason, array $opts = []): DteResult
    {
        [$sellerName, $sellerAddress] = $this->getSellerInfo();
        $buyerArr = $this->resolveBuyer($buyer);
        $hasPerCallFrase = isset($opts['tipo_frase']) || isset($opts['escenario']);
        if ($this->frases !== null && !$hasPerCallFrase) {
            $effFrases = $this->frases;
            $tipoFrase = $escenario = null;
        } else {
            $effFrases = null;
            [$tipoFrase, $escenario] = $this->resolveFrase('NDEB', $opts);
        }
        $payload = DteBuilder::buildNdeb(
            $this->taxid, $sellerName, $sellerAddress, $buyerArr, $items,
            $origin, $reason, $this->afiliacionIva, null, $tipoFrase, $escenario, $effFrases
        );
        $data = $this->certify($payload);
        return DteResult::fromArray($data);
    }

    /**
     * Cancel a DTE.
     *
     * @param string $issueDateTime Format "YYYY-MM-DD HH:MM:SS"
     */
    public function cancel(string $authNumber, string $receiverId, string $issueDateTime, string $reason = 'Anulación'): array
    {
        return $this->post('/CancelFelGT', [
            'Taxid'                        => $this->taxid,
            'Autorizacion'                 => $authNumber,
            'IdReceptor'                   => $receiverId,
            'FechaEmisionDocumentoAnular'  => $issueDateTime,
            'MotivoAnulacion'              => $reason,
            'Username'                     => $this->username,
        ]);
    }

    /**
     * Create a total credit note via /cert/ncredtotal.
     *
     * @param string $issueDateTime Format "YYYY-MM-DD HH:MM:SS"
     */
    public function creditNoteTotal(string $authNumber, string $issueDateTime, string $reason = 'Nota de crédito total', string $reference = ''): array
    {
        return $this->post('/cert/ncredtotal', [
            'Staxid'             => $this->taxid,
            'Authnumber'         => $authNumber,
            'FechaEmision'       => $issueDateTime,
            'MotivoAjuste'       => $reason,
            'ReferenciaInterna'  => $reference,
            'Formatos'           => 'xml|html|pdf',
            'Username'           => $this->username,
        ]);
    }

    /**
     * Look up a NIT via SHARED_GETINFONITcom.
     *
     * @return array{nit: string, name: string, address: string, city: string, district: string, state: string}
     */
    public function lookupNit(string $nit): array
    {
        $digits = preg_replace('/\D/', '', $nit);
        if (isset($this->nitCache[$digits])) {
            return $this->nitCache[$digits];
        }

        $data = $this->get('/Shared', [
            'COUNTRY'  => 'GT',
            'TAXID'    => $this->paddedTaxid,
            'DATA1'    => 'SHARED_GETINFONITcom',
            'DATA2'    => "NIT|$digits",
            'USERNAME' => $this->username,
        ]);

        $normalized = $this->parseNitResponse($digits, $data);
        if (empty($normalized['name'])) {
            throw new DigifactNitNotFoundException("NIT '$nit' not found or returned empty name");
        }
        $this->nitCache[$digits] = $normalized;
        return $normalized;
    }

    private function parseNitResponse(string $nit, mixed $data): array
    {
        if (!is_array($data)) {
            return ['nit' => $nit, 'name' => '', 'address' => 'CIUDAD', 'city' => '01010', 'district' => 'GUATEMALA', 'state' => 'GUATEMALA'];
        }
        // Unwrap envelope: {"REQUEST_DATA": [...], "RESPONSE": [...]}
        if (isset($data['RESPONSE'])) {
            $responseList = $data['RESPONSE'];
            if (is_array($responseList) && count($responseList) > 0) {
                return $this->parseNitResponse($nit, $responseList[0]);
            }
            return ['nit' => $nit, 'name' => '', 'address' => 'CIUDAD', 'city' => '01010', 'district' => 'GUATEMALA', 'state' => 'GUATEMALA'];
        }
        // List → recurse into first element
        if (array_is_list($data) && count($data) > 0) {
            return $this->parseNitResponse($nit, $data[0]);
        }
        // Row dict — MUNICIPIO and DEPARTAMENTO are uppercase in the real API response
        return [
            'nit'      => $nit,
            'name'     => trim($data['NOMBRE'] ?? $data['nombre'] ?? $data['Name'] ?? $data['name'] ?? ''),
            'address'  => trim($data['Direccion'] ?? $data['direccion'] ?? $data['Address'] ?? $data['address'] ?? 'CIUDAD'),
            'city'     => '01010',
            'district' => trim($data['MUNICIPIO'] ?? $data['Municipio'] ?? $data['municipio'] ?? $data['district'] ?? 'GUATEMALA'),
            'state'    => trim($data['DEPARTAMENTO'] ?? $data['Departamento'] ?? $data['departamento'] ?? $data['state'] ?? 'GUATEMALA'),
        ];
    }

    /**
     * Look up a CUI (DPI) via SHARED_GETINFOCUI.
     *
     * @return array{cui: string, name: string, status: string}
     */
    public function lookupCui(string $cui): array
    {
        $digits = preg_replace('/\D/', '', $cui);
        if (isset($this->cuiCache[$digits])) {
            return $this->cuiCache[$digits];
        }

        $data = $this->get('/Shared', [
            'COUNTRY'  => 'GT',
            'TAXID'    => $this->paddedTaxid,
            'DATA1'    => 'SHARED_GETINFOCUI',
            'DATA2'    => "CUI|$digits",
            'USERNAME' => $this->username,
        ]);

        $normalized = $this->parseCuiResponse($digits, $data);
        if (empty($normalized['name'])) {
            throw new DigifactNitNotFoundException("CUI '$cui' not found or returned empty name");
        }
        $this->cuiCache[$digits] = $normalized;
        return $normalized;
    }

    private function parseCuiResponse(string $cui, mixed $data): array
    {
        if (!is_array($data)) {
            return ['cui' => $cui, 'name' => '', 'status' => ''];
        }
        // Unwrap envelope: {"REQUEST_DATA": [...], "RESPONSE": [...]}
        if (isset($data['RESPONSE'])) {
            $responseList = $data['RESPONSE'];
            if (is_array($responseList) && count($responseList) > 0) {
                return $this->parseCuiResponse($cui, $responseList[0]);
            }
            return ['cui' => $cui, 'name' => '', 'status' => ''];
        }
        // List → recurse into first element
        if (array_is_list($data) && count($data) > 0) {
            return $this->parseCuiResponse($cui, $data[0]);
        }
        // Row dict — NOMBRE arrives as "NOMBRES , APELLIDOS" (RENAP format)
        $rawName = (string)($data['NOMBRE'] ?? $data['nombre'] ?? $data['Name'] ?? $data['name'] ?? '');
        return [
            'cui'    => $cui,
            'name'   => self::normalizeCuiName($rawName),
            'status' => trim((string)($data['STATUS'] ?? $data['status'] ?? '')),
        ];
    }

    /**
     * Tidy spacing only — SAT's "APELLIDOS, NOMBRES" string is kept verbatim otherwise.
     *
     * The comma is deliberately preserved: it is how SAT delimits surnames from given
     * names, and commas already reach the DTE unharmed via NIT names such as
     * "A3 CLOUD TECHNOLOGIES, SOCIEDAD DE EMPRENDIMIENTO". Reordering is not attempted
     * either — at least one real production record arrives with its two sides
     * swapped at the source, so no rule could straighten every case.
     */
    private static function normalizeCuiName(string $name): string
    {
        $name = preg_replace('/\s*,\s*/', ', ', $name);
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    /**
     * Get DTE info via SHARED_GETDTEINFO.
     */
    public function getDteInfo(string $authNumber): array
    {
        return $this->get('/Shared', [
            'COUNTRY'  => 'GT',
            'TAXID'    => $this->paddedTaxid,
            'DATA1'    => 'SHARED_GETDTEINFO',
            'DATA2'    => "AUTHNUMBER|$authNumber",
            'USERNAME' => $this->username,
        ]);
    }

    /**
     * Retrieve a DTE document via GET /GetDocument.
     */
    public function getDte(string $authNumber, string $format = 'JSON'): array
    {
        return $this->get('/GetDocument', [
            'AUTHNUMBER' => $authNumber,
            'TAXID'      => $this->paddedTaxid,
            'FORMAT'     => $format,
            'USERNAME'   => $this->username,
        ]);
    }
}
