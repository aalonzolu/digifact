<?php 

namespace Digifact;
class Digifact
{
    private $username;
    private $password;
    private $token;
    private $endpointUrl;
    public $sandbox=true;
    public function __construct($username, $password)
    {
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
}
