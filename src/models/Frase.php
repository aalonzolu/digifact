<?php
namespace Digifact\models;

class Frase{
    public $TipoFrase;
    public $CodigoEscenario;

    /**
     * Agrupa las frases de un documento.
     * En esta sección deberá indicarse los regímenes y textos especiales que son requeridos en los DTE, de acuerdo a la afiliación del contribuyente y tipo de operación. 
     *
     * @param string $TipoFrase
     * @param string $CodigoEscenario
     */
    function __construct($TipoFrase='1', $CodigoEscenario='1')
    {
        if(!empty($TipoFrase)){
            $this->TipoFrase = $TipoFrase;
        }else{
            throw new \Exception('Se requiere TipoFrase');
        }

        if(!empty($CodigoEscenario)){
            $this->CodigoEscenario = $CodigoEscenario;
        }else{
            throw new \Exception('Se requiere CodigoEscenario');
        }
    }
}