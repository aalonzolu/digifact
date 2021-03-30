# Digifact PHP SDK

### Instalacion
`composer require aalonzolu/digifact`

### Uso
```
require_once  './vendor/autoload.php';
use \aalonzolu\Digifact\models\DatosGenerales;
use \aalonzolu\Digifact\models\Direccion;
use \aalonzolu\Digifact\models\Emisor;
use \aalonzolu\Digifact\models\Factura;
use \aalonzolu\Digifact\models\Frase;
use \aalonzolu\Digifact\models\Impuesto;
use \aalonzolu\Digifact\models\Producto;
use \aalonzolu\Digifact\models\Receptor;
```

Crear instancia de la clase
```
$digifact = new \aalonzolu\Digifact\Digifact(DIGIFACT_NIT,DIGIFACT_USERNAME,DIGIFACT_PASSWORD,TRUE);
```

Crear datos generales de la factura
```
$referenciaInterna = "FAC_".time();
$datosGenerales = new DatosGenerales($referenciaInterna);
```

Crear Emisor con su Direccion
```
$direccionEmisor = new Direccion("Zona 1",1301,"Huehuetenango","Huehuetenango","GT");
$emisor = new Emisor(44653948,"Allan Bonilla","PEST.CONTROL", $direccionEmisor);
```

Crear Receptor con su direcicon
```
$direccionReceptor = new Direccion("GUATEMALA",01010,"GUATEMALA","GUATEMALA","GT");
$receptor = new Receptor("CYBERESPACIO",77454820, $direccionReceptor);
```

Fases de la factura
```
$frases = [ new Frase()];
```
Agregar Productos
```
$productos = [];
$impuestos = [new Impuesto("IVA",1,10)];
$producto = new Producto(1, "CA","Producto X",10,0,"S",$impuestos);
array_push($productos, $producto);
$producto = new Producto(1, "CA","Producto Y",10,0,"S",$impuestos);
array_push($productos, $producto);
```
Crear la factura
```
$factura = new Factura($datosGenerales, $emisor, $receptor, $frases, $productos);
$digifact->CertificateDTEToSign($factura);
```
En este punto se puede acceder a los datos de la factura o su contenido XML, HTML o PDF
```
$digifact->xml; // contenido de la factura en xml/base64
$digifact->html; // contenido de la factura en html/base64
$digifact->pdf; // contenido de la factura en pdf/base64;
$digifact->Autorizacion; 
$digifact->Serie;
$digifact->NUMERO;
```
Anular la factura si todavia no hemos borrado $digifact de la memoria del programa
```
$response_anular = $digifact->Anular("Solo son pruebas","ANULAR_FEL_TOSIGN");
var_dump($response_anular);
```

Para anular una factura creada en otro lado o cuando ya hemos recargado la pantalla
```
AnularOtro($NumeroDocumento, $NITEmisor,$IDReceptor,$FechaHoraEmision,$Motivo,$TipoAnulacion="ANULAR_FEL")
```
