<?php 

namespace aalonzolu\Digifact;

use aalonzolu\Digifact\models\DatosAnulacion;
use aalonzolu\Digifact\models\Factura;
class Digifact
{
    private $username;
    private $password;
    private $NIT;
    private $token;
    private $endpointUrl;
    private $endpointUrlv3;
    public $Factura;
    public $sandbox=false;
    public $Autorizacion;
    public $Serie;
    public $NUMERO;
    public $pdf =false;
    public $xml =false;
    public $html =false;
    
    private $tools;


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
    public function __construct($NIT,$username, $password, $sandbox = true, $debug=false)
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
        $this->tools = new Tools();
        $this->sandbox = $sandbox;
        if($this->sandbox){
            $this->endpointUrl = 'https://felgttestaws.digifact.com.gt/felapiv2/api/';
            $this->endpointUrlv3 = 'https://felgttestaws.digifact.com.gt/gt.com.fel.api.v3/api/';
        }else{
            $this->endpointUrl = 'https://felgtaws.digifact.com.gt/gt.com.fel.api.v2/api/';
            $this->endpointUrlv3 = 'https://felgtaws.digifact.com.gt/gt.com.fel.api.v3/api/';
        }
        /**
         * send credentials to digifact and get token
         */
        $responseApi = $this->tools->CallAPI("POST", $this->endpointUrl."login/get_token",[
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

    /**
     * Consultar información de NIT
     * Permite la obtención de la información del cliente, solo con el Número de Identificación Tributaria (NIT). 
     * Este va ligado a la información directa que nos envía SAT a nuestra base de datos
     *
     * @param [type] $NITConsultar
     * @return object
     */
    public function NITInfo($NITConsultar){
        // $this->NIT = "000044653948";
        // $this->sandbox = true;
        $url = $this->endpointUrlv3."SHAREDINFO?NIT={$this->NIT}&DATA1=SHARED_GETINFONITcom=DATA2=NIT|{$NITConsultar}&USERNAME={$this->username}";
        $url = "https://felgttestaws.digifact.com.gt/gt.com.fel.api.v3/api/SHAREDINFO?NIT=000044653948&DATA1=SHARED_GETINFONITcom&DATA2=NIT|{$NITConsultar}&USERNAME=La_Lechita";
        $responseApi = $this->tools->CallAPI(
            "GET", 
            $url,
            [],
            [
                "Content-Type: application/json",
                "Authorization: {$this->token}"
            ]
        );
        if(count($responseApi->RESPONSE) == 0){
            if(isset($responseApi->REQUEST[0]) and isset($responseApi->REQUEST[0]->Mensaje)){
                // echo json_encode($responseApi->REQUEST[0]);exit;
                throw new \Error("FEL INFONIT: ".$responseApi->REQUEST[0]->Mensaje);
            }else{
                throw new \Error("FEL INFONIT: Error desconocido");
            }
        }
        return $responseApi->RESPONSE[0];
    }

    public function DTEInfo($Autorizacion){
        $responseApi = $this->tools->CallAPI(
            "GET", 
            $this->endpointUrl."SHAREDINFO?NIT={$this->NIT}&DATA1=SHARED_GETDTEINFO=DATA2=AUTHNUMBER{$Autorizacion}&USERNAME={$this->username}",
            [],
            [
                "Content-Type: application/json",
                "Authorization: {$this->token}"
            ]
        );
        return $responseApi;
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
