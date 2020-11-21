<?php
namespace Digifact\models;

class Receptor{
    public $NombreReceptor;
    public $IDReceptor;
    public $Direccion;

    function __construct($NombreReceptor, $IDReceptor, Direccion $Direccion)
    {
        if(!empty($NombreReceptor)){
            $this->NombreReceptor = $NombreReceptor;
        }else{
            throw new \Exception('Se requiere NombreReceptor');
        }

        if(is_numeric($IDReceptor)){
            $this->IDReceptor = $IDReceptor;
        }else{
            throw new \Exception('Se requiere IDReceptor');
        }

        if(!empty($Direccion)){
            $this->Direccion = $Direccion;
        }else{
            throw new \Exception('Se requiere DireccionReceptor');
        }
    }
}