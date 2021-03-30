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
    function __construct($NombreCorto, $CodigoUnidadGravable, $MontoTotal)
    {
        if(in_array($NombreCorto,$this->tiposImpuesto)){
            $this->NombreCorto = $NombreCorto;
        }else{
            throw new \Exception('nombreCorto de impuesto no vaido');
        }
        if(is_numeric($CodigoUnidadGravable)){
            $this->CodigoUnidadGravable = $CodigoUnidadGravable;
        }else{
            throw new \Exception('Se requiere CodigoUnidadGravable');
        }
        
        if(is_numeric($MontoTotal)){
            $this->MontoGravable = round($MontoTotal/1.12,4);
        }else{
            throw new \Exception('Se requiere MontoTotal');
        }

        switch($CodigoUnidadGravable){
            case 1:
                $this->MontoImpuesto = $this->MontoGravable*0.12;
            break;
            default:
                $this->MontoImpuesto = $this->MontoGravable*0.12;
            break;
        }
        
    }
}