<?php

use aalonzolu\Digifact\Digifact;
use aalonzolu\Digifact\models\DatosGenerales;
use aalonzolu\Digifact\models\Direccion;
use aalonzolu\Digifact\models\Emisor;
use aalonzolu\Digifact\models\Factura;
use aalonzolu\Digifact\models\Frase;
use aalonzolu\Digifact\models\Impuesto;
use aalonzolu\Digifact\models\Producto;
use aalonzolu\Digifact\models\Receptor;

require('config.php');
require_once  '../vendor/autoload.php';
$digifact = new Digifact(DIGIFACT_NIT,DIGIFACT_USERNAME,DIGIFACT_PASSWORD,TRUE,TRUE);

$digifact->sandbox = true;

$referenciaInterna = "FAC_".time();
$datosGenerales = new DatosGenerales($referenciaInterna,"RECI");


$emisorData = $digifact->NITInfo("2264501");
// echo json_encode($emisorData);exit;
$direccionEmisor = new Direccion($emisorData->Direccion,1301,$emisorData->DEPARTAMENTO, $emisorData->MUNICIPIO, $emisorData->PAIS);
$emisor = new Emisor($emisorData->NIT,$emisorData->NOMBRE,"PEST.CONTROL", $direccionEmisor);


$receptorData = $digifact->NITInfo("2264501");
$direccionReceptor = new Direccion($receptorData->Direccion,01010,$receptorData->DEPARTAMENTO, $receptorData->MUNICIPIO, $receptorData->PAIS);
$receptor = new Receptor("CYBERESPACIO","CF", $direccionReceptor);


if($datosGenerales->tipo=="FPEQ"){
    $frases = [ new Frase(3,1)];
    $impuestos = [];
}
elseif($datosGenerales->tipo=="RECI"){
    $frases = [ new Frase(4,6)];
    $impuestos = [];
}
else{
    $frases = [ new Frase(1,1)];
    $impuestos = [new Impuesto("IVA",1,10)];
}
// $impuestos = [];
// $frases = [ new Frase(2,1)];

$productos = [];

$producto = new Producto(1, "CA","Producto X",10,0,"S",$impuestos);
array_push($productos, $producto);
// $producto = new Producto(1, "CA","Producto Y",10,0,"S",$impuestos);
// array_push($productos, $producto);

$factura = new Factura($datosGenerales, $emisor, $receptor, $frases, $productos);
if(isset($_GET['xml'])){
    echo $factura->toXML();exit;
}

// $digifact->CertificateDTEToSign($factura);
// $response_anular = $digifact->Anular("Solo son pruebas","ANULAR_FEL_TOSIGN");
// var_dump($response_anular);
// if($digifact->CertificateDTEToSign($factura)){
//     // echo "Wiii";
//     $data = base64_decode($digifact->pdf);
//     header('Content-Type: application/pdf');
//     echo $data;


//     // $response_anular = $digifact->Anular("Solo son pruebas");
//     // var_dump($response_anular);
// }