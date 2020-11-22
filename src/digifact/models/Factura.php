<?php

namespace Digifact\models;

class Factura{
    public $datosGenerales;
    public $Emisor;
    public $Receptor;
    public $Frases;
    public $Items;    
    private $GranTotal;
    private $TotalImpuestos;
    function __construct(DatosGenerales $datosGenerales, Emisor $emisor, Receptor $receptor, $frases=[], $items=[]){

        $this->datosGenerales = $datosGenerales;
        $this->Emisor = $emisor;
        $this->Receptor = $receptor;


        if(!empty($frases)){
            foreach($frases as $frase){
                if(!$frase instanceof Frase){
                    throw new \Exception('Objeto Frase no valido');
                }
            }
            $this->Frases = $frases;
        }else{
            throw new \Exception('Frases no puede estar vacia');
        }

        if(!empty($items)){
            foreach($items as $item){
                if(!$item instanceof Producto){
                    throw new \Exception('Objeto Producto no valido');
                }
            }
            $this->Items = $items;
        }else{
            throw new \Exception('items no puede estar vacio');
        }
    }

    /**
     * Calcular el Grandtotal y el total de los tipos de impuestos
     *
     * @return void
     */
    private function calcTotals(){
        $this->GranTotal = 0;
        $this->TotalImpuestos =[];
        foreach ($this->Items as  $item) {
            $this->GranTotal += $item->Precio;
            foreach ($item->Impuestos as $impuesto) {
                if(!isset($this->TotalImpuestos[$impuesto->NombreCorto])){
                    $this->TotalImpuestos[$impuesto->NombreCorto] = 0;
                }
                //sumar tipo de impuesto
                $this->TotalImpuestos[$impuesto->NombreCorto] += $impuesto->MontoImpuesto;
                // $this->GranTotal += $impuesto->MontoImpuesto;
            }
        }
        $this->GranTotal = $this->GranTotal;
    }


    public function toXML(){
        $this->calcTotals();
        $XMLS_STRING = "<?xml version='1.0' encoding='UTF-8'?>
        <dte:GTDocumento xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"
            xmlns:dte=\"http://www.sat.gob.gt/dte/fel/0.2.0\" Version=\"0.1\">
            <dte:SAT ClaseDocumento=\"dte\">
                <dte:DTE ID=\"DatosCertificados\">
                    <dte:DatosEmision ID=\"DatosEmision\">
                        <dte:DatosGenerales Tipo=\"{$this->datosGenerales->tipo}\" FechaHoraEmision=\"{$this->datosGenerales->FechaHoraEmision}\"
                            CodigoMoneda=\"{$this->datosGenerales->moneda}\" />
                        <dte:Emisor NITEmisor=\"{$this->Emisor->NITEmisor}\" NombreEmisor=\"{$this->Emisor->NombreEmisor}\" CodigoEstablecimiento=\"{$this->Emisor->CodigoEstablecimiento}\"
                            NombreComercial=\"{$this->Emisor->NombreComercial}\" AfiliacionIVA=\"{$this->Emisor->AfiliacionIVA}\">
                            <dte:DireccionEmisor>
                                <dte:Direccion>{$this->Emisor->Direccion->Direccion}</dte:Direccion>
                                <dte:CodigoPostal>{$this->Emisor->Direccion->CodigoPostal}</dte:CodigoPostal>
                                <dte:Municipio>{$this->Emisor->Direccion->Municipio}</dte:Municipio>
                                <dte:Departamento>{$this->Emisor->Direccion->Departamento}</dte:Departamento>
                                <dte:Pais>{$this->Emisor->Direccion->Pais}</dte:Pais>
                            </dte:DireccionEmisor>
                        </dte:Emisor>
                        <dte:Receptor NombreReceptor=\"{$this->Receptor->NombreReceptor}\" IDReceptor=\"{$this->Receptor->IDReceptor}\">
                            <dte:DireccionReceptor>
                            <dte:Direccion>{$this->Receptor->Direccion->Direccion}</dte:Direccion>
                            <dte:CodigoPostal>{$this->Receptor->Direccion->CodigoPostal}</dte:CodigoPostal>
                            <dte:Municipio>{$this->Receptor->Direccion->Municipio}</dte:Municipio>
                            <dte:Departamento>{$this->Receptor->Direccion->Departamento}</dte:Departamento>
                            <dte:Pais>{$this->Receptor->Direccion->Pais}</dte:Pais>
                            </dte:DireccionReceptor>
                        </dte:Receptor>
                        <dte:Frases>
                            ";
                            foreach($this->Frases as $frase){
                                $XMLS_STRING .= "<dte:Frase TipoFrase=\"{$frase->TipoFrase}\" CodigoEscenario=\"{$frase->CodigoEscenario}\"/>";
                            }
                            $XMLS_STRING .= "
                        </dte:Frases>
                        <dte:Items>
                            ";
                            foreach($this->Items as $linea=> $item){
                                $numero_linea = $linea+1;
                                $XMLS_STRING .= "<dte:Item NumeroLinea=\"{$numero_linea}\" BienOServicio=\"{$item->BienOServicio}\">
                                <dte:Cantidad>{$item->Cantidad}</dte:Cantidad>
                                <dte:UnidadMedida>{$item->UnidadMedida}</dte:UnidadMedida>
                                <dte:Descripcion>{$item->Descripcion}</dte:Descripcion>
                                <dte:PrecioUnitario>{$item->PrecioUnitario}</dte:PrecioUnitario>
                                <dte:Precio>{$item->Precio}</dte:Precio>
                                <dte:Descuento>{$item->Descuento}</dte:Descuento>
                                <dte:Impuestos>";
                                foreach($item->Impuestos as $impuesto){
                                    $XMLS_STRING .= "
                                    <dte:Impuesto>
                                        <dte:NombreCorto>{$impuesto->NombreCorto}</dte:NombreCorto>
                                        <dte:CodigoUnidadGravable>{$impuesto->CodigoUnidadGravable}</dte:CodigoUnidadGravable>
                                        <dte:MontoGravable>{$impuesto->MontoGravable}</dte:MontoGravable>
                                        <dte:MontoImpuesto>{$impuesto->MontoImpuesto}</dte:MontoImpuesto>
                                    </dte:Impuesto>";
                                }
                                $XMLS_STRING .= "</dte:Impuestos>
                                <dte:Total>".($item->Precio-$item->Descuento)."</dte:Total>
                            </dte:Item>";
                            }
                            $XMLS_STRING .= "
                        </dte:Items>
                        <dte:Totales>
                            <dte:TotalImpuestos>";
                            foreach($this->TotalImpuestos as $NombreCorto => $TotalMontoImpuesto){
                                $XMLS_STRING .= "<dte:TotalImpuesto NombreCorto=\"{$NombreCorto}\" TotalMontoImpuesto=\"".number_format($TotalMontoImpuesto,4)."\"/>";
                            }
            $XMLS_STRING .= "</dte:TotalImpuestos>
                            <dte:GranTotal>".number_format($this->GranTotal,4)."</dte:GranTotal>
                        </dte:Totales>
                    </dte:DatosEmision>
                </dte:DTE>
                <dte:Adenda>
                 <dtecomm:Informacion_COMERCIAL xmlns:dtecomm=\"https://www.digifact.com.gt/dtecomm\" xsi:schemaLocation=\"https://www.digifact.com.gt/dtecomm\">
                   <dtecomm:InformacionAdicional Version=\"7.1234654163\">
                       <dtecomm:REFERENCIA_INTERNA>{$this->datosGenerales->ReferenciaInterna}</dtecomm:REFERENCIA_INTERNA>
                       <dtecomm:FECHA_REFERENCIA>{$this->datosGenerales->FechaHoraEmision}</dtecomm:FECHA_REFERENCIA>
                       <dtecomm:VALIDAR_REFERENCIA_INTERNA>VALIDAR</dtecomm:VALIDAR_REFERENCIA_INTERNA>
                    </dtecomm:InformacionAdicional>
                    </dtecomm:Informacion_COMERCIAL>
                 </dte:Adenda>   
            </dte:SAT>
        </dte:GTDocumento>";
        return $XMLS_STRING;
    }
}