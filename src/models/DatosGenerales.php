<?php
namespace aalonzolu\Digifact\models;

class DatosGenerales {
    

    const DOCUMENTO_FACTURA = 'FACT';
    const DOCUMENTO_FACTURA_CAMBIARIA = 'FCAM';
    const DOCUMENTO_FACTURA_PEQUENO_CONTRIBUYENTE = 'FPEQ';
    const DOCUMENTO_FACTURA_CAMBIARIA_PEQUENO_CONTRIBUYENTE = 'FCAP';
    const DOCUMENTO_FACTURA_ESPECIAL = 'FESP';
    const DOCUMENTO_NOTA_ABONO = 'NABN';
    const DOCUMENTO_REDENCION = 'RDON';
    const DOCUMENTO_RECIBO = 'RECI';
    const DOCUMENTO_NOTA_DEBITO = 'NDEB';
    const DOCUMENTO_NOTA_CREDITO = 'NCRE';
    const SIGNATURE_SHA256_URL = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    const DIGEST_SHA256_URL = 'http://www.w3.org/2001/04/xmlenc#sha256';

    /**
     * Tipos de DTE
     */
    private $tiposDte = [
        self::DOCUMENTO_FACTURA,
        self::DOCUMENTO_FACTURA_CAMBIARIA,
        self::DOCUMENTO_FACTURA_PEQUENO_CONTRIBUYENTE,
        self::DOCUMENTO_FACTURA_CAMBIARIA_PEQUENO_CONTRIBUYENTE,
        self::DOCUMENTO_FACTURA_ESPECIAL,
        self::DOCUMENTO_NOTA_ABONO,
        self::DOCUMENTO_REDENCION,
        self::DOCUMENTO_RECIBO,
        self::DOCUMENTO_NOTA_DEBITO,
        self::DOCUMENTO_NOTA_CREDITO,
    ];

    /**
     * SEGUN ISO 4217
     * @var array Arreglo con las monedas aceptadas por la SAT
     */
    private $monedas = [
        "GTQ", "USD", "VES", "CRC", "SVC",
        "NIO", "DKK", "NOK", "SEK", "CAD", "HKD", "TWD",
        "PTE", "EUR", "CHF", "HNL", "GBP", "ARS", "DOP",
        "COP", "MXN", "BRL", "MYR", "INR", "PKR", "KPW", "JPY"
    ];

    public $moneda;
    public $FechaHoraEmision;
    public $tipo;
    public $ReferenciaInterna;

    private $dateFormat = 'Y-m-d\TH:i:s';

    function __construct($ReferenciaInterna,$tipo='FACT', $moneda='GTQ', $FechaHoraEmision=null )
    {
        if(in_array($tipo,$this->tiposDte)){
            $this->tipo = $tipo;
        }else{
            throw new \Exception('Tipo DTE no valido no valida');
        }
        if(in_array($moneda,$this->monedas)){
            $this->moneda = $moneda;
        }else{
            throw new \Exception('Moneda no valida');
        }

        if (!empty($ReferenciaInterna)) {
            $this->ReferenciaInterna = $ReferenciaInterna;
        }else{
            throw new \Exception('Se requiere el parametro ReferenciaInterna"');
        }
        if(!empty($FechaHoraEmision)){
            // validar formato de fecha Y-M-dTH:i:s
            if (\DateTime::createFromFormat($this->dateFormat, $FechaHoraEmision) !== FALSE) {
                $this->FechaHoraEmision = $FechaHoraEmision;
              }
              else{
                throw new \Exception('Formato de fecha no valido, se requiere "Y-m-d\TH:i:s"');
              }
            
        }else{
            /**
             * Por deefcto fecha de generaicon sera ahora si no se pasa el parametro
             */
            $this->FechaHoraEmision = date($this->dateFormat);
        }
    
    }
}