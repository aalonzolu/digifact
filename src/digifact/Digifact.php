<?php 

namespace Digifact;

use Digifact\models\DatosAnulacion;
use Digifact\models\Factura;
class Digifact
{
    private $username;
    private $password;
    private $NIT;
    private $token;
    private $endpointUrl;
    private $Factura;
    public $sandbox=true;
    public $Autorizacion;
    public $Serie;
    public $NUMERO;
    public $pdf =false;
    public $xml =false;
    public $html =false;


    const DATE_FORMAT = '%y-%m-%dT%';
    const NIT_REGEX = "/(([1-9])+([0-9])*([0-9]|K))$/";
    const EMAIL_REGEX = "/((\w[-+._\w]+@\w[-.\w]+\.\w[-.\w]+)(;?))*/";
    
    /**
     * Crear la conexion inicial a digifact
     *
     * @param [type] $NIT
     * @param [type] $username
     * @param [type] $password
     * @param boolean $debug
     */
    public function __construct($NIT,$username, $password, $debug=false)
    {
        if($debug){
            ini_set('display_errors', 1);
            ini_set('display_startup_errors', 1);
            error_reporting(E_ALL);
        }
        $this->NIT =str_pad($NIT, 10, '0', STR_PAD_LEFT); ;
        $this->username = $username;
        $this->password = $password;
        $tools = new Tools();
        if($this->sandbox){
            $this->endpointUrl = 'https://felgttestaws.digifact.com.gt/felapiv2/api/';
        }else{
            $this->endpointUrl = 'https://felgttestaws.digifact.com.gt/felapiv2/api/';
        }
        /**
         * send credentials to digifact and get token
         */
        $responseApi = $tools->CallAPI("POST", $this->endpointUrl."login/get_token",[
            'Username'=>$this->username,
            'Password'=>$this->password
        ]);
        if(isset($responseApi->Token)){
            // token success
            $this->token=$responseApi->Token;
        }else{
            //trow error
            if(isset($responseApi->description)){
                throw new \Error($responseApi->description);
            }else{
                throw new \Error("Ha ocurrido un error inesperado conectandose a Digifact");
            } 
        }
    }

    public function CertificateDTEToSign(Factura $factura){
        $tools = new Tools();
        $this->Factura = $factura;
        $responseApi = $tools->CallAPI(
            "POST", 
            $this->endpointUrl."FelRequest?NIT={$this->NIT}&TIPO=CERTIFICATE_DTE_XML_TOSIGN&FORMAT=PDF_HTML_XML",
            $factura->toXML(),
            [
                "Content-Type: application/json",
                "Authorization: {$this->token}"
            ]
        );
        if(isset($responseApi->Codigo) and $responseApi->Codigo==1){
            $this->xml = $responseApi->ResponseDATA1;
            $this->html = $responseApi->ResponseDATA2;
            $this->pdf = $responseApi->ResponseDATA3;
            $this->Autorizacion = $responseApi->Autorizacion;
            $this->Serie = $responseApi->Serie;
            $this->NUMERO = $responseApi->NUMERO;
            // var_dump($responseApi);exit;
            return true;
        }
        else{
            //trow error
            if(isset($responseApi->Mensaje)){
                throw new \Error($responseApi->Mensaje);
            }else{
                throw new \Error("Ha ocurrido un error inesperado conectandose a Digifact");
            } 
        }
    }
    public function CertificateDTE(Factura $factura){
        $tools = new Tools();
        $responseApi = $tools->CallAPI(
            "POST", 
            $this->endpointUrl."FelRequest?NIT={$this->NIT}&TIPO=CERTIFICATE_DTE_XML_TOSIGN&FORMAT=PDF_HTML_XML",
            $factura->toXML(),
            [
                "Content-Type: application/json",
                "Authorization: {$this->token}"
            ]
        );
        if(isset($responseApi->Codigo) and $responseApi->Codigo==1){
            $this->xml = $responseApi->ResponseDATA1;
            $this->html = $responseApi->ResponseDATA2;
            $this->pdf = $responseApi->ResponseDATA3;
            $this->Autorizacion = $responseApi->Autorizacion;
            $this->Serie = $responseApi->Serie;
            $this->NUMERO = $responseApi->NUMERO;
            // var_dump($responseApi);exit;
            return true;
        }
        else{
            //trow error
            if(isset($responseApi->Mensaje)){
                throw new \Error($responseApi->Mensaje);
            }else{
                throw new \Error("Ha ocurrido un error inesperado conectandose a Digifact");
            } 
        }
    }

    public function Anular($Motivo, $TipoAnulacion="ANULAR_FEL"){
        if(!in_array($TipoAnulacion,["ANULAR_FEL_TOSIGN","ANULAR_FEL"])){
            throw new \Error("TipoAnulacion solo puede ser ANULAR_FEL_TOSIGN o ANULAR_FEL");
        }
        return $this->AnularOtro($this->Autorizacion, $this->NIT,$this->Factura->Receptor->IDReceptor,$this->Factura->datosGenerales->FechaHoraEmision,$Motivo, $TipoAnulacion);
    }

    private function AnularOtro($NumeroDocumento, $NITEmisor,$IDReceptor,$FechaHoraEmision,$Motivo,$TipoAnulacion="ANULAR_FEL",$FechaHoraAnulacion=false){

        $DatosAnulacion =new DatosAnulacion($NumeroDocumento, $NITEmisor,$IDReceptor,$FechaHoraEmision,$Motivo,$FechaHoraAnulacion);
        $tools = new Tools();
        echo $DatosAnulacion->toXML();exit;
        $responseApi = $tools->CallAPI(
            "POST", 
            $this->endpointUrl."FelRequest?NIT={$this->NIT}&TIPO={$TipoAnulacion}&FORMAT=PDF_HTML_XML",
            $DatosAnulacion->toXML(),
            [
                "Content-Type: application/json",
                "Authorization: {$this->token}"
            ]
        );
        return $responseApi;
    }
}
