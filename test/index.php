<?php

use Digifact\models\DatosGenerales;
use Digifact\models\Direccion;
use Digifact\models\Emisor;
use Digifact\models\Factura;
use Digifact\models\Frase;
use Digifact\models\Impuesto;
use Digifact\models\Producto;
use Digifact\models\Receptor;

require('config.php');
require_once  '../vendor/autoload.php';
$digifact = new \Digifact\Digifact(DIGIFACT_NIT,DIGIFACT_USERNAME,DIGIFACT_PASSWORD,TRUE);

$referenciaInterna = "FAC_".time();
$datosGenerales = new DatosGenerales($referenciaInterna);

$direccionEmisor = new Direccion("RN9N",1301,"Huehuetenango","Huehuetenango","GT");
$emisor = new Emisor(44653948,"Allan Bonilla","PEST.CONTROL", $direccionEmisor);

$direccionReceptor = new Direccion("GUATEMALA",01010,"GUATEMALA","GUATEMALA","GT");
$receptor = new Receptor("CYBERESPACIO",77454820, $direccionReceptor);

$frases = [ new Frase()];

$productos = [];
$impuestos = [new Impuesto("IVA",1,10)];
$producto = new Producto(1, "CA","Producto X",10,0,"S",$impuestos);
array_push($productos, $producto);
$producto = new Producto(1, "CA","Producto Y",10,0,"S",$impuestos);
array_push($productos, $producto);

$factura = new Factura($datosGenerales, $emisor, $receptor, $frases, $productos);
$digifact->CertificateDTEToSign($factura);
$response_anular = $digifact->Anular("Solo son pruebas","ANULAR_FEL_TOSIGN");
var_dump($response_anular);
// if($digifact->CertificateDTE($factura)){
//     // echo "Wiii";
//     // $data = base64_decode($digifact->pdf);
//     // header('Content-Type: application/pdf');
//     // echo $data;


//     $response_anular = $digifact->Anular("Solo son pruebas");
//     var_dump($response_anular);
// }