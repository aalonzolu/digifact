<?php
namespace Digifact\models;
class Producto {
    public $Cantidad;
    public $UnidadMedida;
    public $Descripcion;
    public $PrecioUnitario;
    public $Precio;
    public $Descuento;
    public $BienOServicio;
    public $Impuestos;
    function __construct($Cantidad, $UnidadMedida, $Descripcion, $PrecioUnitario, $Descuento,$BienOServicio, $Impuestos=[])
    {
        if(!empty($Cantidad)){
            $this->Cantidad = $Cantidad;
        }else{
            throw new \Exception('Se requiere Cantidad');
        }
        if(!empty($UnidadMedida)){
            $this->UnidadMedida = $UnidadMedida;
        }else{
            throw new \Exception('Se requiere UnidadMedida');
        }
        if(!empty($Descripcion)){
            $this->Descripcion = $Descripcion;
        }else{
            throw new \Exception('Se requiere Descripcion');
        }
        
        if(is_numeric($Descuento)){
            $this->Descuento = $Descuento;
        }else{
            throw new \Exception('Se requiere Descuento');
        }
        
        if(is_numeric($PrecioUnitario)){
            $this->PrecioUnitario = $PrecioUnitario;
            $this->Precio = ($PrecioUnitario*$this->Cantidad)-$this->Descuento;
        }else{
            throw new \Exception('Se requiere Precio');
        }

        if(in_array($BienOServicio, ['B','S'])){
            $this->BienOServicio = $BienOServicio;
        }else{
            throw new \Exception('BienOServicio debe ser B o S');
        }
        if(is_array($Impuestos)){
            $this->Impuestos = $Impuestos;
        }else{
            throw new \Exception('Se requiere Impuestos');
        }
    }
}