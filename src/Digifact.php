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
    public $Factura;
    public $sandbox=false;
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
        // formatear el nit con 4 ceros al principio
        $this->NIT =str_pad($NIT, 12, '0', STR_PAD_LEFT);
        $this->username = $username;
        $this->password = $password;
        $tools = new Tools();
        if($this->sandbox){
            $this->endpointUrl = 'https://felgttestaws.digifact.com.gt/felapiv2/api/';
        }else{
            $this->endpointUrl = 'https://felgtaws.digifact.com.gt/gt.com.fel.api.v2/api/';
        }
        /**
         * send credentials to digifact and get token
         */
        $responseApi = $tools->CallAPI("POST", $this->endpointUrl."login/get_token",[
            'username'=>$this->username,
            'password'=>$this->password
        ]);
        if(isset($responseApi->Token)){
            // token success
            $this->token=$responseApi->Token;
        }else{
            //trow error
            if(isset($responseApi->description)){
                throw new \Error("FEL AUTH: ".$responseApi->description);
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
        // echo $factura->toXML();exit;
        // echo json_encode($responseApi);exit;
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
            if (isset($responseApi->ResponseDATA1)) {
                throw new \Error("FEL DTE2S: \n".$responseApi->ResponseDATA1);
            }
            else if(isset($responseApi->Mensaje)){
                throw new \Error("FEL DTE2S: ".$responseApi->Mensaje);
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
            if (isset($responseApi->ResponseDATA1)) {
                throw new \Error("FEL DTE: ".$responseApi->ResponseDATA1);
            }
            else if(isset($responseApi->Mensaje)){
                throw new \Error("FEL DTE: ".$responseApi->Mensaje);
            }else{
                throw new \Error("Ha ocurrido un error inesperado conectandose a Digifact");
            } 
        }
    }

    public function Anular($Motivo, $TipoAnulacion="ANULAR_FEL_TOSIGN"){
        if(!in_array($TipoAnulacion,["ANULAR_FEL_TOSIGN","ANULAR_FEL"])){
            throw new \Error("TipoAnulacion solo puede ser ANULAR_FEL_TOSIGN o ANULAR_FEL");
        }
        return $this->AnularOtro($this->Autorizacion, $this->NIT,$this->Factura->Receptor->IDReceptor,$this->Factura->datosGenerales->FechaHoraEmision,$Motivo, $TipoAnulacion);
    }

    public function AnularOtro($NumeroDocumento, $NITEmisor,$IDReceptor,$FechaHoraEmision,$Motivo,$TipoAnulacion="ANULAR_FEL_TOSIGN",$FechaHoraAnulacion=false){

        $DatosAnulacion =new DatosAnulacion($NumeroDocumento, $NITEmisor,$IDReceptor,$FechaHoraEmision,$Motivo,$FechaHoraAnulacion);
        $tools = new Tools();
        $responseApi = $tools->CallAPI(
            "POST", 
            $this->endpointUrl."FelRequest?NIT={$this->NIT}&TIPO={$TipoAnulacion}&FORMAT=XML",
            $DatosAnulacion->toXML(),
            [
                "Content-Type: application/json",
                "Authorization: {$this->token}"
            ]
        );
        return $responseApi;
    }
}
