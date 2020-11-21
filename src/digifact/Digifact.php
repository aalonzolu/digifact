<?php 

namespace Digifact;
use Digifact\models\Factura;
class Digifact
{
    private $username;
    private $password;
    private $NIT;
    private $token;
    private $endpointUrl;
    public $sandbox=true;
    private $xsd_documento ='https://cat.desa.sat.gob.gt/xsd/alfa/GT_Documento-0.1.0.xsd';


    const DATE_FORMAT = '%y-%m-%dT%';
    const NIT_REGEX = "/(([1-9])+([0-9])*([0-9]|K))$/";
    const EMAIL_REGEX = "/((\w[-+._\w]+@\w[-.\w]+\.\w[-.\w]+)(;?))*/";
    
    public function __construct($NIT,$username, $password)
    {
        $this->NIT = $NIT;
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

    public function CertificateDTEXMLToSign(Factura $factura){
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
    }
    public function CertificateDTEXML(Factura $factura){
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
        var_dump($responseApi);exit;
    }
}
