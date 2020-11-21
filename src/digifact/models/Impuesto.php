<?php
namespace Digifact\models;
class Impuesto{
    public $NombreCorto;
    public $CodigoUnidadGravable;
    public $MontoGravable;
    public $MontoImpuesto;

    /**
     * Tipos de Impuestos aceptados por la SAT 
     */
    private $tiposImpuesto = [
        "IVA", "PETROLEO", "TURISMO HOSPEDAJE",
        "TURISMO PASAJES", "TIMBRE DE PRENSA", "BOMBEROS",
        "TASA MUNICIPAL", "BEBIDAS ALCOHOLICAS", "TABACO",
        "CEMENTO", "BEBIDAS NO ALCOHOLICAS", "TARIFA PORTUARIA",
    ];
    
    /**
     * Agrupa los impuestos aplicados al ítem o producto
     *
     * @param String $nombreCorto
     * @param String $CodigoUnidadGravable
     * @param Number $MontoGravable Monto sobre el cual se aplica el impuesto.
     * @param Number $MontoImpuesto
     */
    function __construct($NombreCorto, $CodigoUnidadGravable, $MontoGravable, $MontoImpuesto)
    {
        if(in_array($NombreCorto,$this->tiposImpuesto)){
            $this->NombreCorto = $NombreCorto;
        }else{
            throw new \Exception('nombreCorto de impuesto no vaido');
        }
        if(!empty($CodigoUnidadGravable)){
            $this->CodigoUnidadGravable = $CodigoUnidadGravable;
        }else{
            throw new \Exception('Se requiere CodigoUnidadGravable');
        }
        if(is_numeric($MontoGravable)){
            $this->MontoGravable = $MontoGravable;
        }else{
            throw new \Exception('Se requiere MontoGravable');
        }
        if(is_numeric($MontoImpuesto)){
            $this->MontoImpuesto = $MontoImpuesto;
        }else{
            throw new \Exception('Se requiere MontoImpuesto');
        }
        
    }
}