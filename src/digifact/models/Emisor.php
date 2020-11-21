<?php
namespace Digifact\models;

class Emisor{
    public $NITEmisor;
    public $NombreEmisor;
    public $CodigoEstablecimiento;
    public $NombreComercial;
    public $AfiliacionIVA;
    public $Direccion;

     /**
     * De acuerdo al Régimen que tenga registrado el contribuyente, se refiere a que puede ser General, 
     * Pequeño Contribuyente, Pequeño Contribuyente Electronico, Agropecuario, Agropecuario Electrónico. 
     * (EXE queda por compatibilidad para DTE hasta 29/feb/2020)
     * @var array Arreglo con los tipos de afiliacion aceptadas por la SAT
     */
    private $tipo_afiliacion_iva = [
        "GEN", "EXE", "PEQ",
        "PEE", "AGR", "AGE"
    ];

    function __construct($NITEmisor, $nombreEmisor, $NombreComercial, Direccion $Direccion, $CodigoEstablecimiento='1',$AfiliacionIVA='GEN' )
    {
        if(!empty($NITEmisor)){
            $this->NITEmisor = $NITEmisor;
        }else{
            throw new \Exception('Se requiere NIT del emisor');
        }

        if(!empty($nombreEmisor)){
            $this->NombreEmisor = $nombreEmisor;
        }else{
            throw new \Exception('Se requiere nombre del emisor');
        }

        if(is_numeric($CodigoEstablecimiento)){
            $this->CodigoEstablecimiento = $CodigoEstablecimiento;
        }else{
            throw new \Exception('Se requiere codigo de establecimiento');
        }

        if(!empty($NombreComercial)){
            $this->NombreComercial = $NombreComercial;
        }else{
            throw new \Exception('Se requiere nombre Comercial');
        }

        if(in_array($AfiliacionIVA,$this->tipo_afiliacion_iva)){
            $this->AfiliacionIVA = $AfiliacionIVA;
        }else{
            throw new \Exception('Tipo de afiliacion IVA no valido');
        }
        if($Direccion instanceof Direccion){
            $this->Direccion = $Direccion;
        }else{
            throw new \Exception('Tipo de direccion no valida');
        }
        
    }
}