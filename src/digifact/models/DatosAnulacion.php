<?php
namespace Digifact\models;

class DatosAnulacion {
    public $NumeroDocumentoAAnular;
    public $NITEmisor;
    public $IDReceptor;
    public $FechaEmisionDocumentoAnular;
    public $FechaHoraAnulacion;
    public $MotivoAnulacion;

    private $NitRegex = "/(([1-9])+([0-9])*([0-9]|K))$/";
    private $dateFormat = 'Y-m-d\TH:i:s';

    function __construct($NumeroDocumentoAAnular, $NITEmisor, $IDReceptor, $FechaEmisionDocumentoAnular,$MotivoAnulacion, $FechaHoraAnulacion=false)
    {
        if(!empty($NumeroDocumentoAAnular)){
            $this->NumeroDocumentoAAnular = $NumeroDocumentoAAnular;
        }else{
            throw new \Exception('Se requiere NumeroDocumentoAAnular');
        }
        if(!empty($MotivoAnulacion)){
            $this->MotivoAnulacion = $MotivoAnulacion;
        }else{
            throw new \Exception('Se requiere MotivoAnulacion');
        }

        if(is_numeric($NITEmisor)){
            $this->NITEmisor = intval($NITEmisor);
        }else{
            throw new \Exception('Se requiere NITEmisor');
        }

        if(is_numeric($IDReceptor) || $IDReceptor=='CF'){
            $this->IDReceptor = $IDReceptor;
        }else{
            throw new \Exception('Se requiere IDReceptor');
        }

        if(!empty($FechaEmisionDocumentoAnular)){
            // validar formato de fecha Y-M-dTH:i:s
            if (\DateTime::createFromFormat($this->dateFormat, $FechaEmisionDocumentoAnular) !== FALSE) {
                $this->FechaEmisionDocumentoAnular = $FechaEmisionDocumentoAnular;
              }
              else{
                throw new \Exception('Formato de fecha de documento no valido, se requiere "Y-m-d\TH:i:s"');
              }
            
        }else{
            throw new \Exception('Se requiere fecha de documento a anular"');
        }

        if(!empty($FechaHoraAnulacion)){
            // validar formato de fecha Y-M-dTH:i:s
            if (\DateTime::createFromFormat($this->dateFormat, $FechaHoraAnulacion) !== FALSE) {
                $this->FechaHoraAnulacion = $FechaHoraAnulacion;
              }
              else{
                throw new \Exception('Formato de fecha de anulación no valido, se requiere "Y-m-d\TH:i:s"');
              }
            
        }else{
            /**
             * Por deefcto fecha de generaicon sera ahora si no se pasa el parametro
             */
            $this->FechaHoraAnulacion = date($this->dateFormat);
        }
    }

    public function toXML(){
        return "<?xml version='1.0' encoding='utf-8'?>
<dte:GTAnulacionDocumento xmlns:dte=\"http://www.sat.gob.gt/dte/fel/0.1.0\"
    xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"
    Version=\"0.1\">
    <dte:SAT>
        <dte:AnulacionDTE ID=\"DatosCertificados\">
            <dte:DatosGenerales ID=\"DatosAnulacion\" NumeroDocumentoAAnular=\"{$this->NumeroDocumentoAAnular}\" NITEmisor=\"{$this->NITEmisor}\"
                IDReceptor=\"{$this->IDReceptor}\" FechaEmisionDocumentoAnular=\"{$this->FechaEmisionDocumentoAnular}\"
                FechaHoraAnulacion=\"{$this->FechaHoraAnulacion}\" MotivoAnulacion=\"{$this->MotivoAnulacion}\"/>
    </dte:AnulacionDTE>
    </dte:SAT>
</dte:GTAnulacionDocumento>";
    }
}