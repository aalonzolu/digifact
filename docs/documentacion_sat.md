# Digifact GT FEL - documentación reorganizada en Markdown (JSON-only + reglas SAT)

> Documento consolidado a partir de los archivos proporcionados: API NUC Digifact GT v2.0.5, Reglas y Validaciones FEL v2.0, GT-Documento 0.1.0, complementos SAT, ejemplos NUC JSON/XML, SHARED TOOL API y Adenda Comercial.

> **Enfoque:** operaciones en formato JSON o con respuesta JSON; se excluye deliberadamente el endpoint XML salvo donde sea necesario para explicar el mapeo contra SAT.

> **Importante:** donde la documentación de Digifact y la documentación SAT no coinciden o dejan huecos, este documento lo marca explícitamente como `observación`, `inconsistencia` o `inferencia`.

## Tabla de contenido

- 1. [Fuentes, alcance y advertencias](#1-fuentes-alcance-y-advertencias)
- 2. [Resumen rápido de la API](#2-resumen-rápido-de-la-api)
- 3. [Ambientes y autenticación](#3-ambientes-y-autenticación)
- 4. [Convenciones del wrapper JSON NUC de Digifact](#4-convenciones-del-wrapper-json-nuc-de-digifact)
- 5. [Operaciones REST disponibles en este alcance JSON](#5-operaciones-rest-disponibles-en-este-alcance-json)
- 6. [Modelo SAT vs wrapper JSON NUC: cómo se mapean](#6-modelo-sat-vs-wrapper-json-nuc-cómo-se-mapean)
- 7. [Catálogo de tipos de DTE SAT y cobertura observada en Digifact](#7-catálogo-de-tipos-de-dte-sat-y-cobertura-observada-en-digifact)
- 8. [Reglas SAT consolidadas para implementación](#8-reglas-sat-consolidadas-para-implementación)
- 9. [Complementos y adendas en JSON](#9-complementos-y-adendas-en-json)
- 10. [Guía por tipo de documento JSON](#10-guía-por-tipo-de-documento-json)
- 11. [Consultas, recuperación y seguimiento de documentos](#11-consultas-recuperación-y-seguimiento-de-documentos)
- 12. [Errores frecuentes y diagnóstico](#12-errores-frecuentes-y-diagnóstico)
- 13. [Inconsistencias detectadas en la documentación](#13-inconsistencias-detectadas-en-la-documentación)
- 14. [Recomendaciones de implementación](#14-recomendaciones-de-implementación)
- 15. [Apéndices con ejemplos oficiales](#15-apéndices-con-ejemplos-oficiales)

## 1. Fuentes, alcance y advertencias

### 1.1 Fuentes consolidadas

- **Digifact API NUC v2.0.5**: autenticación, certificación, anulación, consultas GET, GET DOCUMENT y NCRED total.
- **SAT Reglas y Validaciones FEL v2.0**: catálogo actual de DTE, reglas aritméticas, frases, impuestos, anulación, QR, contingencia, tolerancias, NIT/CUI y complementos.
- **GT-Documento 0.1.0**: definición del XSD SAT base para el documento FEL clásico.
- **Complementos SAT**: Cambiaria, Factura Especial, Referencia de Nota.
- **SHARED TOOL API v1.0.0.5**: operaciones GET ampliadas no descritas completamente en el PDF NUC 2.0.5.
- **Adenda Comercial Digifact**: referencia interna, validación de duplicidad y estructura comercial.
- **Ejemplos NUC JSON/XML**: FACT CF, FACT CUI, FCAM, NDEB, NCRE, NABN, FESP, RDON, FACT + CCA, FPEQ y RECI en XML.

### 1.2 Alcance exacto de este documento

Este manual cubre:
- autenticación;
- certificación usando **`/v2/transform/nuc_json`**;
- anulación;
- consultas `/Shared`;
- recuperación `/GetDocument`;
- nota de crédito total `/cert/ncredtotal`;
- estructura JSON NUC observada en los ejemplos;
- reglas SAT relevantes para construir payloads correctos;
- complementos y adendas más relevantes;
- catálogo SAT actualizado de DTE con separación entre lo que sí aparece en la documentación NUC recibida y lo que solo aparece en SAT.

### 1.3 Advertencias críticas

1. **Digifact usa nombres de propiedades con typos en los ejemplos NUC**. Ejemplos reales: `AdditionlInfo`, `AditionalData`, `AditionalInfo`. No corrijas esos nombres “por intuición” sin validarlo contra el ambiente de pruebas.
2. **El wrapper JSON NUC no es el XSD SAT**. Es una capa Digifact que luego se transforma a XML SAT.
3. **Hay contradicciones documentales**. Por ejemplo, `CancelFelGT` aparece con “Formato XML” pero el mismo documento exige `Content-Type: application/json` y body JSON.
4. **La documentación SAT está más actualizada que `GT-Documento-0.1.0.pdf`**. SAT v2.0 incluye más tipos de DTE que el XSD legado mostrado en GT-Documento 0.1.0.
5. **Los ejemplos oficiales NUC cargan casi siempre `AdditionalDocumentInfo`**. En pruebas reales, la ausencia de ese nodo puede romper la transformación NUC -> XML.

## 2. Resumen rápido de la API

| Operación | Método | Endpoint test | Formato de entrada | Respuesta | Notas |
|---|---|---|---|---|---|
| Obtener token | POST | `/login/get_token` | JSON | JSON | Autenticación base de todo consumo. |
| Certificar DTE NUC JSON | POST | `/v2/transform/nuc_json` | JSON | JSON | Operación principal para emitir DTE desde NUC JSON. |
| Anular DTE | POST | `/CancelFelGT` | JSON | JSON | Documento ya certificado. |
| Shared | GET | `/Shared` | querystring | JSON | Consulta info de NIT, DTE, referencia interna, contingencia, etc. |
| Obtener documento | GET | `/GetDocument` | querystring | JSON | Devuelve XML/HTML/PDF y la doc menciona también JSON. |
| Nota de crédito total | POST | `/cert/ncredtotal` | JSON | JSON | Genera NCRE total a partir de un documento previo. |

### 2.1 Base URLs publicadas

```text
TEST       https://testnucgt.digifact.com/api/
PRODUCCIÓN https://nucgt.digifact.com/gt.com.apinuc/api/
```

### 2.2 Qué quedó obsoleto

- `CERTIFICATE_FE_XML_TOSIGN V1` está marcado como **obsoleto**.
- Para JSON, la ruta actual documentada es **`/v2/transform/nuc_json`**.

## 3. Ambientes y autenticación

### 3.1 Endpoint de login

```http
POST /login/get_token
Content-Type: application/json
```

### 3.2 Body del login

```json
{
  "Username": "GT.<NIT a 12 dígitos>.<usuario>",
  "Password": "<password>"
}
```

### 3.3 Regla de construcción del `Username`

La documentación Digifact exige concatenar:
```text
GT + "." + <NIT complementado con ceros hasta 12> + "." + <nombre de usuario>
```

Ejemplo:
```text
GT.000000123456.USER_TEST
```

### 3.4 Reglas operativas del token

- El token expira; cuando vence, la API responde `401` o `Unauthorized`.
- Debe enviarse en el header `Authorization`.
- El documento SHARED legado muestra una respuesta con campos `Token`, `expira_en` y `Otorgado_a`; el PDF NUC 2.0.5 no detalla el schema del login, pero sí confirma que el token tiene expiración y que debe enviarse en `Authorization`.

### 3.5 Ejemplo de login

```json
{
  "Username": "GT.000012345678.FELUSER",
  "Password": "********"
}
```

### 3.6 Uso del token

```http
Authorization: <JWT vigente>
```

## 4. Convenciones del wrapper JSON NUC de Digifact

### 4.1 Estructura general observada

```json
{
  "Version": "1.00",
  "CountryCode": "GT",
  "Header": { ... },
  "Seller": { ... },
  "Buyer": { ... },
  "ThirdParties": null,
  "Items": [ ... ],
  "Totals": { ... },
  "AdditionalDocumentInfo": { ... }
}
```

### 4.2 Convenciones observadas en los ejemplos

- Los montos casi siempre viajan como **string** con 3, 4 o 6 decimales.
- Las cantidades también viajan como **string**.
- Los arreglos anidados suelen usar nodos del tipo `Tax`, `Discount`, `TotalTax`, `AdditionalInfo`, `Info`, `Data`.
- `Items[].Type` usa los textos `Bien` o `Servicio`, aunque el XML SAT conceptual habla de `B` o `S`.
- `Buyer.TaxIDType = "CUI"` se usa en NUC para mapear el concepto SAT de receptor con CUI.
- `Header.AdditionalIssueDocInfo` se usa para datos especiales del encabezado como personería en RDON.
- `Seller.TaxIDAdditionalInfo` se usa para `AfiliacionIVA`.
- `Seller.AdditionlInfo` representa frases.
- `AdditionalDocumentInfo` concentra adendas y complementos Digifact.

### 4.3 Skeleton recomendado para un DTE base

```json
{
  "Version": "1.00",
  "CountryCode": "GT",
  "Header": {
    "DocType": "FACT",
    "IssuedDateTime": "2025-03-18T10:30:00-06:00",
    "Currency": "GTQ"
  },
  "Seller": {
    "TaxID": "123456",
    "TaxIDAdditionalInfo": [
      {
        "Name": "AfiliacionIVA",
        "Data": null,
        "Value": "GEN"
      }
    ],
    "Name": "RAZON SOCIAL EMISOR",
    "AdditionlInfo": [
      {
        "Name": "TipoFrase",
        "Data": "1",
        "Value": "1"
      },
      {
        "Name": "Escenario",
        "Data": "1",
        "Value": "1"
      }
    ],
    "BranchInfo": {
      "Code": "1",
      "Name": "NOMBRE COMERCIAL",
      "AddressInfo": {
        "Address": "DIRECCION",
        "City": "01010",
        "District": "GUATEMALA",
        "State": "GUATEMALA",
        "Country": "GT"
      }
    }
  },
  "Buyer": {
    "TaxID": "CF",
    "Name": "CONSUMIDOR FINAL",
    "AddressInfo": {
      "Address": "CIUDAD",
      "City": "01010",
      "District": "GUATEMALA",
      "State": "GUATEMALA",
      "Country": "GT"
    }
  },
  "Items": [
    {
      "Number": "1",
      "Codes": null,
      "Type": "Bien",
      "Description": "DESCRIPCION",
      "Qty": "1.000000",
      "UnitOfMeasure": "UNI",
      "Price": "100.000000",
      "Discounts": null,
      "Taxes": {
        "Tax": [
          {
            "Code": "1",
            "Description": "IVA",
            "TaxableAmount": "89.285714",
            "Amount": "10.714286"
          }
        ]
      },
      "Totals": {
        "TotalItem": "100.000000"
      }
    }
  ],
  "Totals": {
    "TotalTaxes": {
      "TotalTax": [
        {
          "Description": "IVA",
          "Amount": "10.714286"
        }
      ]
    },
    "GrandTotal": {
      "InvoiceTotal": "100.000000"
    }
  },
  "AdditionalDocumentInfo": {
    "AdditionalInfo": []
  }
}
```

### 4.4 Nodo `AdditionalDocumentInfo`

Este nodo aparece como crítico en la práctica de Digifact NUC. Puede contener:
- complementos (`Type = "COMPLEMENTO"`);
- adendas (`Type = "ADENDA"`).

En los ejemplos oficiales se usa esta estructura base:
```json
{
  "AdditionalDocumentInfo": {
    "AdditionalInfo": [
      {
        "Code": "<identificador>",
        "Type": "COMPLEMENTO o ADENDA",
        "AditionalData": { "Data": [ ... ] },
        "AditionalInfo": [ ... ]
      }
    ]
  }
}
```

### 4.5 Errores de nomenclatura que debes respetar

| Propiedad observada | Comentario |
|---|---|
| `AdditionlInfo` | aparece así en `Seller`; le falta una `a`. |
| `AditionalData` | aparece así en `AdditionalDocumentInfo`; le falta una `d`. |
| `AditionalInfo` | también aparece con typo. |
| `AdditionalIssueDocInfo` | aparece en RDON para personería. |
| `TaxIDType` | se usa para el caso CUI. |

## 5. Operaciones REST disponibles en este alcance JSON

### 5.1 POST `/login/get_token`

**Objetivo:** obtener JWT para consumir el resto de operaciones.

**Headers**
```http
Content-Type: application/json
```

**Body**
```json
{
  "Username": "GT.000000123456.USUARIO",
  "Password": "PASSWORD"
}
```

**Respuesta esperada**
- El PDF SHARED legado muestra una respuesta con `Token`, `expira_en` y `Otorgado_a`.
- El PDF NUC 2.0.5 no fija el schema exacto, pero sí exige el uso posterior del token en `Authorization`.

### 5.2 POST `/v2/transform/nuc_json`

**Objetivo:** certificar un DTE FEL enviando NUC JSON.

**Headers**
```http
Content-Type: application/json
Authorization: <JWT>
```

**Query params**
| Param | Requerido | Valor |
|---|---|---|
| `TAXID` | sí | NIT del usuario emisor, usualmente padded a 12 en los ejemplos de endpoint. |
| `FORMAT` | sí | `XML`, `PDF`, `HTML`, separados por `|`, ej. `XML|PDF|HTML`. |
| `USERNAME` | sí | usuario Digifact asignado. |

**Body**
- JSON NUC del documento.

**Respuesta JSON**
| Campo | Significado documentado |
|---|---|
| `code` | código de respuesta. |
| `message` | mensaje informativo. |
| `description` | detalle adicional. |
| `responseData1` | XML en base64. |
| `responseData2` | HTML en base64. |
| `responseData3` | PDF en base64. |
| `authNumber` | UUID / número de autorización del DTE. |
| `url` | sin uso documentado por ahora. |
| `infoDetails` | sin uso documentado por ahora. |
| `suggestedFileName`, `suggestedFileName2` | sin uso documentado por ahora. |
| `batch` | serie. |
| `serial` | correlativo. |
| `issuedTimeStamp` | fecha de emisión. |
| `taxID`, `name` | emisor. |
| `branchCode`, `branchName` | sucursal/nombre comercial; pueden venir vacíos. |
| `receiverTaxID`, `receiverName` | receptor. |
| `discounts`, `taxes`, `subtotal`, `totalAmount` | algunos ejemplos de doc los reportan como sin valor por el momento. |
| `enrolledTimeStamp` | fecha/hora de certificación. |
| `backprocessor` | servidor/procesador. |
| `additionalInfo` | puede incluir `acuseReciboSAT` y `codigosSAT`. |

**Observaciones**
- Aunque el endpoint se llama `transform`, funcionalmente es la operación de certificación para JSON.
- La documentación NUC aclara que esta operación aplica las validaciones normadas por SAT.

### 5.3 POST `/CancelFelGT`

**Objetivo:** anular un DTE ya certificado.

**Endpoint test**
```text
https://testnucgt.digifact.com/api/CancelFelGT
```

**Headers**
```http
Authorization: <JWT>
Content-Type: application/json
```

**Body documentado**
```json
{
  "Taxid": "123456",
  "Autorizacion": "UUID_DEL_DOCUMENTO",
  "IdReceptor": "CF",
  "FechaEmisionDocumentoAnular": "2022-10-04T10:25:09",
  "MotivoAnulacion": "Texto libre",
  "Username": "USUARIO"
}
```

**Respuesta documentada**
| Campo | Significado |
|---|---|
| `Codigo` | código de respuesta. |
| `Mensaje` | mensaje adicional. |
| `AcuseReciboSAT` | acuse SAT. |
| `CodigosSAT` | códigos SAT. |
| `ResponseDATA1` | XML base64. |
| `ResponseDATA2` | HTML base64. |
| `ResponseDATA3` | PDF base64. |
| `Autorizacion` | UUID del documento. |
| `Serie`, `Numero` | serie y número. |
| `Fecha_DTE` | fecha de emisión. |
| `NIT_EFACE`, `NOMBRE_EFACE` | emisor. |
| `NIT_COMPRADOR`, `NOMBRE_COMPRADOR` | receptor. |
| `BACKPROCESSOR` | procesador. |
| `Fecha_de_certificacion` | fecha de certificación. |

**Inconsistencia documentada**
- La tabla del PDF dice `Formato XML`, pero los headers y el body documentado son inequívocamente JSON.

### 5.4 GET `/Shared`

**Objetivo:** operación de consulta multipropósito.

**Endpoint test**
```text
https://testnucgt.digifact.com/api/Shared
```

**Headers**
```http
Authorization: <JWT>
```

**Query params base**
| Param | Descripción |
|---|---|
| `COUNTRY` | país; para Guatemala, `GT`. |
| `TAXID` | NIT del usuario emisor. |
| `DATA1` | operación a ejecutar. |
| `DATA2` | parámetros de la operación, separados por `|`. |
| `USERNAME` | usuario Digifact. |

**Formato general de respuesta**
```json
{
  "REQUEST_DATA": [
    {
      "Respuesta": "...",
      "Codigo": "...",
      "Procesador": "...",
      "Mensaje": "...",
      "Descripcion": "...",
      "Fecha": "..."
    }
  ],
  "RESPONSE": [ ... ]
}
```

#### 5.4.1 `SHARED_GETDTEINFO`

Obtiene la ficha del DTE por UUID.

**DATA2**
```text
AUTHNUMBER|<UUID>
```

**Campos relevantes en `RESPONSE`**
- `NIT_EMISOR`, `TIPO_DTE`, `GUID`, `SERIE`, `NUMERO`, `ESTATUS`, `FECHA_DE_EMISION`, `FECHA_DE_CERTIFICACION`, `NIT_COMPRADOR`, `NOMBRE_COMPRADOR`, `SUBTOTAL_SIN_DESCUENTO`, `DESCUENTO`, `SUBTOTAL_CON_DESCUENTO`, `IVA`, `TOTAL`, `ITEMS`, `DTE`, `ACUSE_RECIBO_SAT_DTE`, `ACUSE_RECIBO_ANULACION`, `ReferenciaInterna`.

#### 5.4.2 `SHARED_GETINFONITcom`

Obtiene información fiscal básica de un NIT.

**DATA2**
```text
NIT|<nit_a_consultar>
```

**Campos observados en `RESPONSE`**
- `PAIS`, `NIT`, `NOMBRE`, `Direccion` (opcional / removida por confidencialidad en versiones posteriores), `DEPARTAMENTO`, `MUNICIPIO`.

#### 5.4.3 `SHARED_GETDTEINFO_BY_INTERNALID`

Consulta por referencia interna. Documentado en SHARED TOOL API, no en el PDF NUC 2.0.5.

**DATA2**
```text
REFERENCIA_INTERNA|<valor>|ISSUEDDATE|<yyyy-mm-dd>
```

**Resultado**
- Puede devolver una o varias filas.
- Repite la mayoría de campos de `SHARED_GETDTEINFO`.

#### 5.4.4 `SHARED_GETDTEINFO_BY_INTERNALID_LESS` / `..._BASIC`

Versión reducida para búsquedas rápidas. En el PDF SHARED aparece el título `SHARED_GETDTEINFO_BY_INTERNALID_BASIC` pero en el cuerpo se documenta `SHARED_GETDTEINFO_BY_INTERNALID_LESS`; trátalo como otra inconsistencia de naming.

**DATA2**
```text
REFERENCIA_INTERNA|<valor>|ISSUEDDATE|<yyyy-mm-dd>
```

**Resultado**
- Devuelve menos información que la versión completa y omite campos pesados como acuses o XML firmado.

#### 5.4.5 `SHARED_GETREPORTDAILYFEL_BASIC`

Consulta diaria por fecha, establecimiento, tipo o UUID.

**DATA2**
Se concatena con pipelines. La documentación antigua lo expresa como un paquete de parámetros, típicamente:
```text
FECHA|<yyyy-mm-dd>|ESTABLECIMIENTO|<n>|AUTHNUMBER|<uuid opcional>|TIPO|<catálogo opcional>
```

**Catálogo de tipo documentado en ese PDF**
1 FACT, 2 FCAM, 3 FPEQ, 4 FCAP, 5 FESP, 6 NABN, 7 RDON, 8 RECI, 9 NDEB, 10 NCRE.

#### 5.4.6 `SHARED_GETDTEINFO_BY_CONTINGENCIA`

Consulta documentos a partir del número de contingencia.

**DATA2**
```text
CONTINGENCIA|<numero>|FECHA_EMISION|<yyyy-mm-dd>
```

### 5.5 GET `/GetDocument`

**Objetivo:** recuperar el documento por número de autorización.

**Query params**
| Param | Descripción |
|---|---|
| `AUTHNUMBER` | UUID del DTE. |
| `TAXID` | NIT del emisor. |
| `FORMAT` | `XML`, `HTML`, `PDF` o `JSON`, separados por `|`. |
| `USERNAME` | usuario Digifact. |

**Respuesta**
La respuesta es JSON con `REQUEST_DATA` y `RESPONSE`.
La tabla documenta `ResponseData1`, `ResponseData2`, `ResponseData3` y los relaciona con XML/HTML/PDF. No explica cómo se refleja el formato `JSON` si se solicita, por lo que ese punto queda **ambiguo** en la documentación recibida.

### 5.6 POST `/cert/ncredtotal`

**Objetivo:** emitir una nota de crédito total a partir de un documento ya certificado.

**Headers**
```http
Authorization: <JWT>
Content-Type: application/json
```

**Body**
```json
{
  "Staxid": "123456",
  "Authnumber": "UUID_DOCUMENTO_ORIGEN",
  "FechaEmision": "2025-08-21 13:24:00",
  "MotivoAjuste": "Texto del ajuste",
  "ReferenciaInterna": "opcional",
  "Formatos": "xml|html|pdf",
  "Username": "USUARIO"
}
```

**Notas**
- La documentación usa el campo **`Staxid`** exactamente así.
- `ReferenciaInterna` es opcional y fue agregada en una revisión reciente del PDF NUC.
- La respuesta se indica como equivalente a la de `CERTIFICATE_FE_XML_TOSIGN V2`.

## 6. Modelo SAT vs wrapper JSON NUC: cómo se mapean

### 6.1 Mapeo conceptual

| Wrapper NUC | SAT / XSD conceptual | Comentario |
|---|---|---|
| `Header.DocType` | `DatosGenerales@Tipo` | tipo de DTE. |
| `Header.IssuedDateTime` | `DatosGenerales@FechaHoraEmision` | fecha/hora con zona. |
| `Header.Currency` | `DatosGenerales@CodigoMoneda` | moneda. |
| `Header.AdditionalIssueType` | dato adicional de emisión | aparece en algunos ejemplos, no siempre. |
| `Header.ExchangeRate` | dato adicional de emisión | aparece en algunos ejemplos. |
| `Seller.TaxID` | `Emisor@NITEmisor` | sin guión. |
| `Seller.Name` | `Emisor@NombreEmisor` | razón social. |
| `Seller.BranchInfo.Code` | `Emisor@CodigoEstablecimiento` | establecimiento SAT. |
| `Seller.BranchInfo.Name` | `Emisor@NombreComercial` | nombre comercial. |
| `Seller.TaxIDAdditionalInfo[AfiliacionIVA]` | `Emisor@AfiliacionIVA` | `GEN`, `EXE`, `PEQ` u otros según contexto SAT. |
| `Buyer.TaxID` | `Receptor@IDReceptor` | NIT, CUI o `CF`. |
| `Buyer.TaxIDType = CUI` | `Receptor@TipoEspecial = CUI` | caso CUI. |
| `Items[].Type` | `Item@BienOServicio` | wrapper usa `Bien/Servicio`; SAT conceptualmente `B/S`. |
| `Items[].Price` | `PrecioUnitario` / `Precio` según wrapper | Digifact colapsa parte del modelo SAT en un esquema más práctico. |
| `Items[].Taxes.Tax[]` | `Impuestos/Impuesto` | mapeo de impuestos por ítem. |
| `Totals.TotalTaxes` | `Totales/TotalImpuestos` | suma vertical por impuesto. |
| `Totals.GrandTotal.InvoiceTotal` | `Totales/GranTotal` | total del documento. |
| `AdditionalDocumentInfo` | `Complementos` + `Adenda` | wrapper propietario de Digifact. |

### 6.2 Nodo `DatosGenerales` en SAT según GT-Documento 0.1.0

El GT-Documento legado describe estas piezas clave:
- `Tipo`.
- `Exp` para exportación (`SI`).
- `FechaHoraEmision`.
- `CodigoMoneda`.
- `NumeroAcceso` aleatorio entre `100000000` y `999999999`.

### 6.3 Emisor según GT-Documento 0.1.0

- `NITEmisor`.
- `NombreEmisor`.
- `CodigoEstablecimiento`.
- `NombreComercial`.
- `CorreoEmisor` opcional.
- `AfiliacionIVA` (`GEN`, `EXE`, `PEQ` en esa versión del XSD).

### 6.4 Receptor según GT-Documento 0.1.0

- `IDReceptor`.
- `TipoEspecial = CUI` cuando aplica.
- `NombreReceptor`.
- `CorreoReceptor` opcional.
- dirección opcional.

### 6.5 Frases según XSD SAT

En SAT las frases van como pares `TipoFrase` + `CodigoEscenario`. En NUC Digifact se modelan como elementos `AdditionlInfo` en el vendedor.

## 7. Catálogo de tipos de DTE SAT y cobertura observada en Digifact

### 7.1 Catálogo SAT v2.0

| Código | Nombre | Observación de cobertura en archivos recibidos |
|---|---|---|
| `FACT` | Factura | hay ejemplo NUC provisto |
| `FCAM` | Factura Cambiaria | hay ejemplo NUC provisto |
| `FPEQ` | Factura Pequeño Contribuyente | hay ejemplo NUC provisto |
| `FCAP` | Factura Cambiaria Pequeño Contribuyente | aparece en XSD legado, pero no se adjuntó ejemplo |
| `FESP` | Factura Especial | hay ejemplo NUC provisto |
| `NABN` | Nota de Abono | hay ejemplo NUC provisto |
| `RDON` | Recibo por Donación | hay ejemplo NUC provisto |
| `RECI` | Recibo | hay ejemplo XML, no JSON oficial provisto |
| `FEPE` | Factura Específica | aparece en SAT v2.0, no en los ejemplos NUC recibidos |
| `NDEB` | Nota de Débito | hay ejemplo NUC provisto |
| `NCRE` | Nota de Crédito | hay ejemplo NUC provisto |
| `FACA` | Factura Contribuyente Agropecuario | aparece en SAT v2.0, no en los ejemplos NUC recibidos |
| `FCCA` | Factura Cambiaria Contribuyente Agropecuario | aparece en SAT v2.0, no en los ejemplos NUC recibidos |
| `FAPE` | Factura Pequeño Contribuyente Régimen Electrónico | aparece en SAT v2.0, no en los ejemplos NUC recibidos |
| `FCPE` | Factura Cambiaria Pequeño Contribuyente Régimen Electrónico | aparece en SAT v2.0, no en los ejemplos NUC recibidos |
| `FAAE` | Factura Contribuyente Agropecuario Régimen Electrónico Especial | aparece en SAT v2.0, no en los ejemplos NUC recibidos |
| `FCAE` | Factura Cambiaria Contribuyente Agropecuario Régimen Electrónico Especial | aparece en SAT v2.0, no en los ejemplos NUC recibidos |
| `FARP` | Factura Contribuyente Régimen Primario | aparece en SAT v2.0, no en los ejemplos NUC recibidos |
| `FCRP` | Factura Cambiaria Contribuyente Régimen Primario | aparece en SAT v2.0, no en los ejemplos NUC recibidos |
| `FPEC` | Factura Contribuyente Régimen Pecuario | aparece en SAT v2.0, no en los ejemplos NUC recibidos |
| `FCPC` | Factura Cambiaria Contribuyente Régimen Pecuario | aparece en SAT v2.0, no en los ejemplos NUC recibidos |
| `CIVA` | Constancia de Exención de IVA | aparece en SAT v2.0, no en los ejemplos NUC recibidos |
| `CAIS` | Constancia de Adquisición de Insumos y Servicios | aparece en SAT v2.0, no en los ejemplos NUC recibidos |
| `NEV` | Nota de Envío | aparece en SAT v2.0, no en los ejemplos NUC recibidos |
| `RANT` | Recibo de Anticipos | aparece en SAT v2.0, no en los ejemplos NUC recibidos |
| `FACP` | Factura Provisional | aparece en SAT v2.0, no en los ejemplos NUC recibidos |

### 7.2 Divergencia importante entre SAT y GT-Documento 0.1.0

El PDF `GT-Documento-0.1.0` enumera solo estos tipos en `DatosGenerales@Tipo`:
`FACT`, `FCAM`, `FPEQ`, `FCAP`, `FESP`, `NABN`, `RDON`, `RECI`, `NDEB`, `NCRE`.

SAT v2.0 amplía el catálogo. Por eso, cuando se implementen DTE nuevos como `FEPE`, `FARP`, `FPEC`, `NEV`, `RANT` o `FACP`, no basta con mirar el XSD viejo: hay que seguir la matriz SAT actual y validar con Digifact en test.

## 8. Reglas SAT consolidadas para implementación

### 8.1 Validaciones generales de esquema y datos

- El documento debe cumplir el esquema XSD aplicable.
- `FechaHoraEmision` debe ser válida y con formato de fecha/hora SAT.
- `NITEmisor` debe corresponder al emisor autorizado.
- `CodigoEstablecimiento` debe corresponder a un establecimiento válido para el tipo de documento y personería.
- `IDReceptor` puede ser NIT, CUI o `CF` según el tipo de operación.
- Si `IDReceptor = CF`, SAT impone restricciones por tipo de DTE y monto.
- La marca `Exp = SI` activa reglas especiales de exportación.
- La marca de espectáculo público solo aplica a tipos específicos y exige el complemento correspondiente.
- En ciertos tipos de DTE, la moneda debe coincidir con la del documento origen.

### 8.2 Regla relevante para consumidor final (`CF`)

SAT establece limitaciones para documentos emitidos a `CF` cuando el total en quetzales es igual o superior a Q 2,500.00, tanto para moneda GTQ como para otras monedas convertidas. Esta regla afecta varios tipos de factura.

### 8.3 Reglas de ítems

- La sección de ítems debe existir.
- `Precio = Cantidad * PrecioUnitario`.
- `Descuento` no puede ser mayor que `Precio`.
- `OtrosDescuento` no puede exceder `Precio - Descuento` ni hacer que el descuento total supere el precio.
- `BienOServicio` depende del tipo de DTE. Ejemplos: ciertos regímenes agropecuarios solo permiten bienes; espectáculos públicos solo servicio; `NEV` solo bienes; `RANT` solo servicios; `FEPE` solo bienes.
- En espectáculos públicos, solo puede existir un ítem.

### 8.4 Códigos de producto

- Son opcionales.
- Si se envían, deben existir en el catálogo correspondiente.
- Algunos códigos están restringidos a padrón/autorización del Ministerio de Energía y Minas.
- En SAT, solo ciertos tipos de DTE admiten `CodigoProducto`.

### 8.5 Impuestos: matriz de compatibilidad

SAT define, por tipo de DTE, qué impuestos pueden incluirse. Resumen práctico:
- `FACT` y `FCAM`: admiten IVA y varios impuestos específicos sectoriales.
- `FPEQ` y `FCAP`: no llevan IVA, pero sí pueden llevar ciertos impuestos específicos como petróleo, turismo, timbre de prensa, bomberos y tasa municipal.
- `FESP`: sí puede llevar IVA; el caso clásico es IVA más complemento de retenciones.
- `NABN`, `RDON`, `RECI`: en general no llevan impuestos en el esquema clásico adjunto.
- `NDEB` y `NCRE`: admiten un subconjunto condicionado por el documento origen.

### 8.6 Impuestos específicos documentados por SAT

SAT dedica secciones completas a:
- IVA
- Petróleo
- Turismo Hospedaje
- Turismo Pasajes
- Timbre de Prensa
- Bomberos
- Tasa Municipal
- Bebidas Alcohólicas
- Tabaco
- Cemento
- Bebidas No Alcohólicas
- Tarifa Portuaria

### 8.7 Regla de cálculo del IVA

Para operaciones gravadas con IVA:
- el IVA ya va incluido en `PrecioUnitario` y `Precio`;
- el monto gravable se obtiene a partir de `Precio - descuentos - otros descuentos`, dividido entre `1.12` cuando la unidad gravable es tasa 12%;
- el impuesto es `MontoGravable * 12%`.

Para operaciones exentas o no afectas:
- el monto gravable es `Precio - descuentos - otros descuentos`;
- el IVA es `0`;
- la frase de exento/no afecto debe corresponder al escenario SAT aplicable.

Para regímenes simplificados (por ejemplo FPEQ y algunos regímenes especiales):
- no se determina IVA en el precio;
- el monto gravable corresponde al neto;
- el nodo de IVA no se incluye.

### 8.8 Afiliación IVA y tipo de DTE

SAT publica una matriz por afiliación:
- `GEN` habilita FACT, FCAM, FESP, NABN, RDON, RECI, NDEB, NCRE y otros según régimen/personería.
- `PEQ` habilita FPEQ, FCAP y además FESP/NABN en ciertos contextos.
- regímenes electrónicos y agropecuarios habilitan sus propios tipos de DTE.
- cuando no exista afiliación para un caso exento, SAT indica consignar `EXE`.

### 8.9 Personería

Reglas importantes:
- `RDON` debe incluir personería.
- `RECI` puede requerirla u omitirla dependiendo del caso y del tipo/código de establecimiento.
- `RANT` y `FESP` también tienen restricciones por personería.
- SAT publica una lista detallada de códigos de personería válidos (cooperativa, fundación, universidad, ONG, partido político, iglesia, etc.).

### 8.10 Frases

SAT define tipos de frase y escenarios. Los más relevantes para implementar son:
- tipo 1: retención ISR
- tipo 2: agente de retención IVA
- tipo 3: no genera derecho a crédito fiscal del IVA
- tipo 4: exento o no afecto al IVA
- tipo 5: frases de factura especial
- tipo 6: contribuyente agropecuario
- tipo 7: regímenes electrónicos
- tipo 8: exento de ISR
- tipo 9: frases especiales
- tipo 10: régimen primario
- tipo 11: régimen pecuario
- tipo 12: factura específica

Reglas clave:
- si una frase es requerida para el tipo de DTE, debe estar presente;
- si una frase no aplica al tipo de DTE, no debe enviarse;
- el `CodigoEscenario` debe existir y corresponder a la afiliación/personería/operación;
- exportación, items sin IVA, agente retenedor o regímenes especiales disparan frases concretas.

### 8.11 Complementos SAT

SAT documenta, entre otros:
- Exportación
- Exportación Provisional
- Retenciones de Factura Especial
- Abonos Factura Cambiaria
- Referencias Nota de Crédito y Débito
- Cobros por Cuenta Ajena
- Espectáculos Públicos
- Referencias de Constancia
- Medios de Pago
- Decreto 31-2022
- Organizaciones Políticas (LEPP)
- Traslado de Mercancías

### 8.12 Anulación

SAT valida en la transacción de anulación, como mínimo:
- número de autorización del documento a anular;
- NIT emisor del documento a anular;
- ID receptor del documento a anular;
- NIT certificador del documento a anular;
- fecha de emisión del documento a anular;
- fecha de anulación.

### 8.13 Anexos técnicos útiles de SAT

SAT también documenta:
- depuración y validación de NIT y CUI;
- tolerancias de cálculo en ítems y totales;
- generación de número de autorización, serie y número;
- generación del número de acceso;
- transacción de anulación;
- generación del código QR.

## 9. Complementos y adendas en JSON

### 9.1 Complemento de factura cambiaria (`FCAMB`)

Se usa en FCAM para registrar abonos.

**Campos del abono SAT**
- `NumeroAbono`
- `FechaVencimiento`
- `MontoAbono`

**Ejemplo NUC**
```json
{
  "Code": "FCAMB",
  "Type": "COMPLEMENTO",
  "AditionalData": {
    "Data": [
      {
        "Info": [
          { "Name": "NumeroAbono", "Data": null, "Value": "1" },
          { "Name": "FechaVencimiento", "Data": null, "Value": "2022-07-10" },
          { "Name": "MontoAbono", "Data": null, "Value": "30.00" }
        ]
      }
    ]
  }
}
```

### 9.2 Complemento de referencias para NCRE/NDEB

Campos requeridos observados:
- `NumeroAutorizacionDocumentoOrigen`
- `FechaEmisionDocumentoOrigen`
- `MotivoAjuste`
- `SerieDocumentoOrigen`
- `NumeroDocumentoOrigen`

### 9.3 Complemento de Factura Especial (`FESP`)

Campos:
- `RetencionISR`
- `RetencionIVA`
- `TotalMenosRetenciones`

### 9.4 Complemento Cobros por Cuenta Ajena (`CCA`)

Campos observados por fila:
- `NITtercero`
- `NumeroDocumento`
- `FechaDocumento`
- `Descripcion`
- `BaseImponible`
- `MontoCobroDAI`
- `MontoCobroIVA`
- `MontoCobroOtros`
- `MontoCobroTotal`

### 9.5 Adenda comercial Digifact

La adenda comercial es una estructura Digifact, no trasladada a SAT. Se usa para información comercial o de control interno.

**Reglas publicadas por Digifact**
- No aceptan adendas no autorizadas por Digifact.
- La referencia interna admite longitud mínima 2 y máxima 500.
- Solo se aceptan letras, números y algunos símbolos específicos (`.`, `_`, `$`, `/`, `#`, `(`, `)`, `@`).
- `VALIDAR_REFERENCIA_INTERNA = VALIDAR` hace control de duplicidad por referencia interna.
- `NO_VALIDAR` solo almacena la referencia.
- Con validación, el tiempo de certificación puede aumentar entre 0.45 y 1 segundo.
- Si una referencia interna ya existe y se intenta certificar de nuevo con validación activa, la documentación muestra error `9022`.

**Estructura típica observada**
```json
{
  "Code": "FRONT-... o identificador interno",
  "Type": "ADENDA",
  "AditionalData": {
    "Data": [
      {
        "Name": "INFORMACION_ADICIONAL",
        "Info": [
          { "Name": "OBSERVACIONES", "Value": "-" },
          { "Name": "CANTIDAD_LETRAS", "Value": "..." }
        ]
      },
      {
        "Name": "DetallesAux_Detalle",
        "Info": [
          { "Name": "NumeroLinea", "Value": "1" },
          { "Name": "Descripcion_Adicional", "Value": "-" },
          { "Name": "CodigoEAN", "Value": "00011" },
          { "Name": "CategoriaAdicional", "Value": "-" }
        ]
      }
    ]
  },
  "AditionalInfo": [
    { "Name": "VALIDAR_REFERENCIA_INTERNA", "Value": "NO_VALIDAR" }
  ]
}
```

## 10. Guía por tipo de documento JSON

### 10.1 `FACT` - Factura

Documento general de venta/servicio. Para CF y CUI hay ejemplos distintos. Normalmente lleva IVA y adenda.

**Casos observados**
- FACT CF: `Buyer.TaxID = CF`.
- FACT CUI: `Buyer.TaxIDType = CUI`.
- FACT + CCA: misma base FACT pero con complemento CCA.

### 10.2 `FCAM` - Factura Cambiaria

Igual que factura, pero con complemento de abonos.

**Complemento requerido**: `FCAMB` con uno o más abonos.

### 10.3 `FPEQ` - Factura Pequeño Contribuyente

Régimen pequeño contribuyente. Ojo: el ejemplo oficial adjunto viene con `DocType = FACT`, lo cual contradice SAT.

**Advertencia fuerte**: SAT define el tipo como `FPEQ`, pero el archivo oficial adjunto viene con `Header.DocType = FACT`. Para integración real conviene tratar eso como inconsistencia documental y validar en test con `FPEQ`.

### 10.4 `FESP` - Factura Especial

Lleva complemento de retenciones.

**Complemento requerido**: retenciones (`RetencionISR`, `RetencionIVA`, `TotalMenosRetenciones`).

### 10.5 `NABN` - Nota de Abono

Sin impuestos en el ejemplo adjunto; usa adenda.


### 10.6 `RDON` - Recibo por Donación

Usa `Header.AdditionalIssueDocInfo` para `TipoPersoneria`.

**Dato especial**: `Header.AdditionalIssueDocInfo = [{"Name":"TipoPersoneria", ...}]`.

### 10.7 `RECI` - Recibo

Solo se adjuntó XML oficial; aquí se documenta equivalencia JSON inferida.

**Dato especial**: en el XML ejemplo, la adenda incluye datos académicos (`Tipo`, `NombreAlumno`, `Carne`, `UnidadAcademica`).

### 10.8 `NDEB` - Nota de Débito

Debe referenciar documento origen mediante complemento.

**Complemento requerido**: referencia al documento origen.

### 10.9 `NCRE` - Nota de Crédito

Debe referenciar documento origen mediante complemento.

**Complemento requerido**: referencia al documento origen.

### 10.10 `FACT + CCA` - Factura con Cobros por Cuenta Ajena

Factura normal con complemento CCA.


## 11. Consultas, recuperación y seguimiento de documentos

### 11.1 Consultar un DTE por UUID

Usa `/Shared` con `DATA1=SHARED_GETDTEINFO`.

### 11.2 Consultar un NIT

Usa `/Shared` con `DATA1=SHARED_GETINFONITcom`.

### 11.3 Consultar por referencia interna

Usa `/Shared` con `DATA1=SHARED_GETDTEINFO_BY_INTERNALID` o su variante reducida.

### 11.4 Consultar por contingencia

Usa `/Shared` con `DATA1=SHARED_GETDTEINFO_BY_CONTINGENCIA`.

### 11.5 Recuperar XML / HTML / PDF / JSON del DTE

Usa `/GetDocument` con `AUTHNUMBER`, `TAXID`, `FORMAT`, `USERNAME`.

## 12. Errores frecuentes y diagnóstico

### 12.1 `Ocurrio un error al convertir el NUC al xml de GT`

Este mensaje indica que el wrapper JSON no pudo transformarse al XML interno SAT. Suele deberse a:
- falta de nodos esperados por Digifact, por ejemplo `AdditionalDocumentInfo`;
- complemento mal formado;
- typo en nombres de propiedades del wrapper;
- inconsistencia entre el tipo de DTE y la estructura enviada.

### 12.2 `No se encuentra el elemento AdditionalDocumentInfo`

En la práctica de Digifact, aunque el XSD SAT permita ausencia de adenda/complementos, el transformador NUC puede esperar el nodo `AdditionalDocumentInfo` si su plantilla JSON lo da por hecho.

### 12.3 `401 Unauthorized`

- token vencido;
- token mal enviado;
- ambiente o credenciales incorrectas.

### 12.4 Problemas de referencia interna

- si `VALIDAR_REFERENCIA_INTERNA = VALIDAR`, la referencia se vuelve control de duplicidad;
- la documentación de Digifact ilustra error `9022` cuando la referencia ya fue usada.

### 12.5 Errores por CUI

- usa `Buyer.TaxIDType = "CUI"`;
- SAT además exige validación del CUI/dígito verificador según sus anexos.

### 12.6 Errores por notas de crédito/débito

- moneda distinta a la del documento origen;
- referencia incompleta del documento origen;
- impuestos mayores a los del documento origen, en particular IVA.

## 13. Inconsistencias detectadas en la documentación

| Tema | Documento | Observación | Recomendación práctica |
|---|---|---|---|
| `CancelFelGT` dice formato XML | API NUC 2.0.5 | la misma tabla exige `Content-Type: application/json` y body JSON | tratarlo como endpoint JSON |
| `GetDocument` admite `FORMAT=JSON` | API NUC 2.0.5 | la tabla de respuesta solo describe XML/HTML/PDF | validar en test y no asumir naming adicional |
| `GT-Documento 0.1.0` enumera 10 DTE | GT-Documento 0.1.0 | SAT v2.0 ya enumera más tipos | usar SAT como fuente actual para catálogo |
| `FPEQ` ejemplo con `DocType = FACT` | `NUC - FPEQ.json` | contradicción directa con SAT | preferir `FPEQ` y validar en test |
| naming `AdditionlInfo`, `AditionalData`, `AditionalInfo` | ejemplos NUC | typos en claves JSON | respetar los nombres tal como los consume Digifact |
| `SHARED_GETDTEINFO_BY_INTERNALID_BASIC` vs `..._LESS` | SHARED TOOL API | naming inconsistente en el mismo PDF | probar ambos identificadores si Digifact no aclara |

## 14. Recomendaciones de implementación

1. Construye una capa propia que genere el JSON NUC a partir de un modelo interno limpio; no mezcles reglas SAT con typos del wrapper Digifact en todo tu código.
2. Maneja `AdditionalDocumentInfo` como un nodo siempre presente, aunque vaya vacío o con la adenda mínima permitida en tu cuenta.
3. Separa claramente tres niveles de validación:
   - validación de negocio propia;
   - validación SAT;
   - validación específica del wrapper Digifact.
4. Para `NCRE`, `NDEB` y `NCRED total`, conserva siempre UUID, serie, número, fecha, moneda, receptor y referencia interna del documento origen.
5. Para `CF`, valida localmente el umbral de Q 2,500.00.
6. No infieras que una propiedad opcional en XSD también es opcional en NUC Digifact.
7. Documenta internamente qué adenda comercial tienes autorizada en Digifact; no asumas que cualquier `Code` de adenda será aceptado.
8. Para nuevos DTE de SAT no presentes en los ejemplos NUC recibidos, construye primero el XML/XSD conceptual y luego negocia el wrapper JSON exacto con Digifact.

## 15. Apéndices con ejemplos oficiales

### 15.1 Ejemplo oficial - FACT CF

<details><summary>Ver JSON oficial adjunto</summary>

```json
{
  "Version": "1.00",
  "CountryCode": "GT",
  "Header": {
    "DocType": "FACT",
    "IssuedDateTime": "2022-09-05T11:28:00-06:00",
    "Currency": "GTQ"
  },
  "Seller": {
    "TaxID": "123456",
    "TaxIDAdditionalInfo": [
      {
        "Name": "AfiliacionIVA",
        "Data": null,
        "Value": "GEN"
      }
    ],
    "Name": "FEL TEST",
    "Contact": {
      "EmailList": {
        "Email": [
          "fel@example.com"
        ]
      }
    },
    "AdditionlInfo": [
      {
        "Name": "TipoFrase",
        "Data": "1",
        "Value": "1"
      },
      {
        "Name": "Escenario",
        "Data": "1",
        "Value": "1"
      }
    ],
    "BranchInfo": {
      "Code": "1",
      "Name": "ESTABLECIMIENTO DE PRUEBA",
      "AddressInfo": {
        "Address": "4 AVENIDA 15-70 ZONA 10 LOCAL 3 EDIFICIO PALADIUM",
        "City": "01010",
        "District": "Guatemala",
        "State": "Guatemala",
        "Country": "GT"
      }
    }
  },
  "Buyer": {
    "TaxID": "CF",
    "Name": "CONSUMIDOR FINAL",
    "AddressInfo": {
      "Address": "CIUDAD",
      "City": "01010",
      "District": "GUATEMALA",
      "State": "GUATEMALA",
      "Country": "GT"
    }
  },
  "ThirdParties": null,
  "Items": [
    {
      "Number": "1",
      "Codes": null,
      "Type": "Servicio",
      "Description": "prueba",
      "Qty": "1.000000",
      "UnitOfMeasure": "SER",
      "Price": "31.000000",
      "Discounts": null,
      "Taxes": {
        "Tax": [
          {
            "Code": "1",
            "Description": "IVA",
            "TaxableAmount": "27.678571",
            "Amount": "3.321428"
          }
        ]
      },
      "Totals": {
        "TotalItem": "31.000000"
      }
    },
    {
      "Number": "2",
      "Codes": null,
      "Type": "Bien",
      "Description": "Jeans",
      "Qty": "1.000000",
      "UnitOfMeasure": "UNI",
      "Price": "600.000000",
      "Discounts": null,
      "Taxes": {
        "Tax": [
          {
            "Code": "1",
            "Description": "IVA",
            "TaxableAmount": "537.714286",
            "Amount": "64.285714"
          }
        ]
      },
      "Totals": {
        "TotalItem": "600.000000"
      }
    }
  ],
  "Totals": {
    "TotalTaxes": {
      "TotalTax": [
        {
          "Description": "IVA",
          "Amount": "67.607143"
        }
      ]
    },
    "GrandTotal": {
      "InvoiceTotal": "631.000000"
    }
  },
  "AdditionalDocumentInfo": {
    "AdditionalInfo": [
      {
        "Code": "FRONT-263C-444B-89BA-6F87EC1330C0",
        "Type": "ADENDA",
        "AditionalData": {
          "Data": [
            {
              "Info": [
                {
                  "Name": "OBSERVACIONES",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CANTIDAD_LETRAS",
                  "Data": null,
                  "Value": "SEISCIENTOS TREINTA Y UN QUETZALES CON 00/100"
                }
              ],
              "Name": "INFORMACION_ADICIONAL"
            },
            {
              "Info": [
                {
                  "Name": "NumeroLinea",
                  "Data": null,
                  "Value": "1"
                },
                {
                  "Name": "Descripcion_Adicional",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CodigoEAN",
                  "Data": null,
                  "Value": "00011"
                },
                {
                  "Name": "CategoriaAdicional",
                  "Data": null,
                  "Value": "-"
                }
              ],
              "Name": "DetallesAux_Detalle"
            },
            {
              "Info": [
                {
                  "Name": "NumeroLinea",
                  "Data": null,
                  "Value": "2"
                },
                {
                  "Name": "Descripcion_Adicional",
                  "Data": null,
                  "Value": "---"
                },
                {
                  "Name": "CodigoEAN",
                  "Data": null,
                  "Value": "000113"
                },
                {
                  "Name": "CategoriaAdicional",
                  "Data": null,
                  "Value": "11"
                }
              ],
              "Name": "DetallesAux_Detalle"
            }
          ]
        },
        "AditionalInfo": [
          {
            "Name": "VALIDAR_REFERENCIA_INTERNA",
            "Data": null,
            "Value": "NO_VALIDAR"
          }
        ]
      }
    ]
  }
}
```

</details>

### 15.2 Ejemplo oficial - FACT CUI

<details><summary>Ver JSON oficial adjunto</summary>

```json
{
  "Version": "1.00",
  "CountryCode": "GT",
  "Header": {
    "DocType": "FACT",
    "IssuedDateTime": "2024-05-29T10:00:00-06:00",
    "Currency": "GTQ"
  },
  "Seller": {
    "TaxID": "123456",
    "TaxIDAdditionalInfo": [
      {
        "Name": "AfiliacionIVA",
        "Data": null,
        "Value": "GEN"
      }
    ],
    "Name": "FEL TEST",
    "AdditionlInfo": [
      {
        "Name": "TipoFrase",
        "Data": "1",
        "Value": "1"
      },
      {
        "Name": "Escenario",
        "Data": "1",
        "Value": "1"
      }
    ],
    "BranchInfo": {
      "Code": "1",
      "Name": "ESTABLECIMIENTO DE PRUEBA",
      "AddressInfo": {
        "Address": "4 AVENIDA 15-70 ZONA 10 LOCAL 3 EDIFICIO PALADIUM",
        "City": "01010",
        "District": "Guatemala",
        "State": "Guatemala",
        "Country": "GT"
      }
    }
  },
  "Buyer": {
    "TaxID": "3730617490101",
    "TaxIDType": "CUI",
    "Name": "Julio Cifuentes",
    "AddressInfo": {
      "Address": "CIUDAD",
      "City": "01010",
      "District": "GUATEMALA",
      "State": "GUATEMALA",
      "Country": "GT"
    }
  },
  "Items": [
    {
      "Number": "1",
      "Codes": null,
      "Type": "Bien",
      "Description": "LLANTAS 90/90-17 TL DOBLE PROPOSITO",
      "Qty": "1.000000",
      "UnitOfMeasure": "UNO",
      "Price": "190.000000",
      "Discounts": null,
      "Taxes": {
        "Tax": [
          {
            "Code": "1",
            "Description": "IVA",
            "TaxableAmount": "169.642857",
            "Amount": "20.357143"
          }
        ]
      },
      "Totals": {
        "TotalItem": "190.000000"
      }
    }
  ],
  "Totals": {
    "TotalTaxes": {
      "TotalTax": [
        {
          "Description": "IVA",
          "Amount": "20.357143"
        }
      ]
    },
    "GrandTotal": {
      "InvoiceTotal": "190.000000"
    }
  },
  "AdditionalDocumentInfo": {
    "AdditionalInfo": [
      {
        "Code": "FRONT-263C-444B-89BA-6F87EC1330C0",
        "Type": "ADENDA",
        "AditionalData": {
          "Data": [
            {
              "Info": [
                {
                  "Name": "OBSERVACIONES",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CANTIDAD_LETRAS",
                  "Data": null,
                  "Value": "CIENTO NOVENTA QUETZALES CON 00/100"
                }
              ],
              "Name": "INFORMACION_ADICIONAL"
            },
            {
              "Info": [
                {
                  "Name": "NumeroLinea",
                  "Data": null,
                  "Value": "1"
                },
                {
                  "Name": "Descripcion_Adicional",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CodigoEAN",
                  "Data": null,
                  "Value": "00015"
                },
                {
                  "Name": "CategoriaAdicional",
                  "Data": null,
                  "Value": "-"
                }
              ],
              "Name": "DetallesAux_Detalle"
            }
          ]
        },
        "AditionalInfo": [
          {
            "Name": "VALIDAR_REFERENCIA_INTERNA",
            "Data": null,
            "Value": "NO_VALIDAR"
          }
        ]
      }
    ]
  }
}
```

</details>

### 15.3 Ejemplo oficial - FCAM

<details><summary>Ver JSON oficial adjunto</summary>

```json
{
  "Version": "1.00",
  "CountryCode": "GT",
  "Header": {
    "DocType": "FCAM",
    "IssuedDateTime": "2022-07-01T14:46:00-06:00",
    "Currency": "GTQ"
  },
  "Seller": {
    "TaxID": "123456",
    "TaxIDAdditionalInfo": [
      {
        "Name": "AfiliacionIVA",
        "Data": null,
        "Value": "GEN"
      }
    ],
    "Name": "FEL TEST",
    "AdditionlInfo": [
      {
        "Name": "TipoFrase",
        "Data": "1",
        "Value": "1"
      },
      {
        "Name": "Escenario",
        "Data": "1",
        "Value": "1"
      }
    ],
    "BranchInfo": {
      "Code": "1",
      "Name": "ESTABLECIMIENTO DE PRUEBA",
      "AddressInfo": {
        "Address": "4 AVENIDA 15-70 ZONA 10 LOCAL 3 EDIFICIO PALADIUM",
        "City": "01001",
        "District": "Guatemala",
        "State": "Guatemala",
        "Country": "GT"
      }
    }
  },
  "Buyer": {
    "TaxID": "12345678",
    "Name": "DIGIFACT SERVICIOS SOCIEDAD ANONIMA",
    "Contact": {
      "EmailList": {
        "Email": [
          "comprador@example.com"
        ]
      }
    },
    "AddressInfo": {
      "Address": "4ta avenida 15-70 zona 10",
      "City": "01010",
      "District": "GUATEMALA",
      "State": "GUATEMALA",
      "Country": "GT"
    }
  },
  "Items": [
    {
      "Number": "1",
      "Codes": null,
      "Type": "Servicio",
      "Description": "prueba",
      "Qty": "1.000000",
      "UnitOfMeasure": "SER",
      "Price": "30.000000",
      "Discounts": {
        "Discount": [
          {
            "Amount": "0.00"
          }
        ]
      },
      "Taxes": {
        "Tax": [
          {
            "Code": "1",
            "Description": "IVA",
            "TaxableAmount": "26.7857",
            "Amount": "3.2143"
          }
        ]
      },
      "Totals": {
        "TotalItem": "30.000000"
      }
    }
  ],
  "Totals": {
    "TotalTaxes": {
      "TotalTax": [
        {
          "Description": "IVA",
          "Amount": "3.2143"
        }
      ]
    },
    "GrandTotal": {
      "InvoiceTotal": "30.000000"
    }
  },
  "AdditionalDocumentInfo": {
    "AdditionalInfo": [
      {
        "Code": "FCAMB",
        "Type": "COMPLEMENTO",
        "AditionalData": {
          "Data": [
            {
              "Info": [
                {
                  "Name": "NumeroAbono",
                  "Data": null,
                  "Value": "1"
                },
                {
                  "Name": "FechaVencimiento",
                  "Data": null,
                  "Value": "2022-07-10"
                },
                {
                  "Name": "MontoAbono",
                  "Data": null,
                  "Value": "30.00"
                }
              ]
            }
          ]
        }
      },
      {
        "Code": "FRONT-7219-4675-9726-F98B4B7472D62",
        "Type": "ADENDA",
        "AditionalData": {
          "Data": [
            {
              "Info": [
                {
                  "Name": "OBSERVACIONES",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CANTIDAD_LETRAS",
                  "Data": null,
                  "Value": "TREINTA QUETZALES CON 00/100"
                }
              ],
              "Name": "INFORMACION_ADICIONAL"
            },
            {
              "Info": [
                {
                  "Name": "NumeroLinea",
                  "Data": null,
                  "Value": "1"
                },
                {
                  "Name": "Descripcion_Adicional",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CodigoEAN",
                  "Data": null,
                  "Value": "000111"
                },
                {
                  "Name": "CategoriaAdicional",
                  "Data": null,
                  "Value": "-"
                }
              ],
              "Name": "DetallesAux_Detalle"
            }
          ]
        },
        "AditionalInfo": [
          {
            "Name": "VALIDAR_REFERENCIA_INTERNA",
            "Data": null,
            "Value": "NO_VALIDAR"
          }
        ]
      }
    ]
  }
}
```

</details>

### 15.4 Ejemplo oficial - NDEB

<details><summary>Ver JSON oficial adjunto</summary>

```json
{
  "Version": "1.00",
  "CountryCode": "GT",
  "Header": {
    "DocType": "NDEB",
    "IssuedDateTime": "2022-07-01T08:28:00-06:00",
    "Currency": "GTQ"
  },
  "Seller": {
    "TaxID": "123456",
    "TaxIDAdditionalInfo": [
      {
        "Name": "AfiliacionIVA",
        "Data": null,
        "Value": "GEN"
      }
    ],
    "Name": "FEL TEST",
    "BranchInfo": {
      "Code": "1",
      "Name": "ESTABLECIMIENTO DE PRUEBA",
      "AddressInfo": {
        "Address": "4 AVENIDA 15-70 ZONA 10 LOCAL 3 EDIFICIO PALADIUM",
        "City": "01001",
        "District": "Guatemala",
        "State": "Guatemala",
        "Country": "GT"
      }
    }
  },
  "Buyer": {
    "TaxID": "12345678",
    "Name": "DIGIFACT SERVICIOS SOCIEDAD ANONIMA",
    "AddressInfo": {
      "Address": "4ta avenida 15-70 zona 10",
      "City": "01010",
      "District": "GUATEMALA",
      "State": "GUATEMALA",
      "Country": "GT"
    }
  },
  "Items": [
    {
      "Number": "1",
      "Codes": null,
      "Type": "Bien",
      "Description": "LLANTAS 90/90-17 TL DOBLE PROPOSITO",
      "Qty": "1.000",
      "UnitOfMeasure": "UNO",
      "Price": "19.000",
      "Discounts": null,
      "Taxes": {
        "Tax": [
          {
            "Code": "1",
            "Description": "IVA",
            "TaxableAmount": "16.964286",
            "Amount": "2.035714"
          }
        ]
      },
      "Totals": {
        "TotalItem": "19.00"
      }
    }
  ],
  "Totals": {
    "TotalTaxes": {
      "TotalTax": [
        {
          "Description": "IVA",
          "Amount": "2.035714"
        }
      ]
    },
    "GrandTotal": {
      "InvoiceTotal": "19.000000"
    }
  },
  "AdditionalDocumentInfo": {
    "AdditionalInfo": [
      {
        "Code": "NDEB",
        "Type": "COMPLEMENTO",
        "AditionalInfo": [
          {
            "Name": "NumeroAutorizacionDocumentoOrigen",
            "Data": null,
            "Value": "AAAAAAAA-0000-4000-A000-000000000001"
          },
          {
            "Name": "FechaEmisionDocumentoOrigen",
            "Data": null,
            "Value": "2022-06-29"
          },
          {
            "Name": "MotivoAjuste",
            "Data": null,
            "Value": "Agregado nuevo item en la misma factura"
          },
          {
            "Name": "SerieDocumentoOrigen",
            "Data": null,
            "Value": "D21E95C9"
          },
          {
            "Name": "NumeroDocumentoOrigen",
            "Data": null,
            "Value": "16467003"
          }
        ]
      },
      {
        "Code": "PRUEBAS-DIGIFACT28042216723",
        "Type": "ADENDA",
        "AditionalData": {
          "Data": [
            {
              "Info": [
                {
                  "Name": "NumeroLinea",
                  "Data": null,
                  "Value": "1"
                },
                {
                  "Name": "Descripcion_Adicional",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CodigoEAN",
                  "Data": null,
                  "Value": "00015"
                },
                {
                  "Name": "CategoriaAdicional",
                  "Data": null,
                  "Value": "-"
                }
              ],
              "Name": "DetallesAux_Detalle"
            }
          ]
        },
        "AditionalInfo": [
          {
            "Name": "VALIDAR_REFERENCIA_INTERNA",
            "Data": null,
            "Value": "NO_VALIDAR"
          }
        ]
      }
    ]
  }
}
```

</details>

### 15.5 Ejemplo oficial - NCRE

<details><summary>Ver JSON oficial adjunto</summary>

```json
{
  "Version": "1.00",
  "CountryCode": "GT",
  "Header": {
    "DocType": "NCRE",
    "IssuedDateTime": "2022-06-29T08:28:00-06:00",
    "Currency": "GTQ"
  },
  "Seller": {
    "TaxID": "123456",
    "TaxIDAdditionalInfo": [
      {
        "Name": "AfiliacionIVA",
        "Data": null,
        "Value": "GEN"
      }
    ],
    "Name": "FEL TEST",
    "BranchInfo": {
      "Code": "1",
      "Name": "ESTABLECIMIENTO DE PRUEBA",
      "AddressInfo": {
        "Address": "4 AVENIDA 15-70 ZONA 10 LOCAL 3 EDIFICIO PALADIUM",
        "City": "01001",
        "District": "Guatemala",
        "State": "Guatemala",
        "Country": "GT"
      }
    }
  },
  "Buyer": {
    "TaxID": "12345678",
    "Name": "DIGIFACT SERVICIOS SOCIEDAD ANONIMA",
    "AddressInfo": {
      "Address": "4ta avenida 15-70 zona 10",
      "City": "01010",
      "District": "GUATEMALA",
      "State": "GUATEMALA",
      "Country": "GT"
    }
  },
  "Items": [
    {
      "Number": "1",
      "Codes": null,
      "Type": "Bien",
      "Description": "NARANJA MECANICA",
      "Qty": "1.0000",
      "UnitOfMeasure": "CA",
      "Price": "10.000000",
      "Discounts": null,
      "Taxes": {
        "Tax": [
          {
            "Code": "1",
            "Description": "IVA",
            "TaxableAmount": "8.928571",
            "Amount": "1.071429"
          }
        ]
      },
      "Totals": {
        "TotalItem": "10.000000"
      }
    }
  ],
  "Totals": {
    "TotalTaxes": {
      "TotalTax": [
        {
          "Description": "IVA",
          "Amount": "1.071429"
        }
      ]
    },
    "GrandTotal": {
      "InvoiceTotal": "10.000000"
    }
  },
  "AdditionalDocumentInfo": {
    "AdditionalInfo": [
      {
        "Code": "NCRE",
        "Type": "COMPLEMENTO",
        "AditionalInfo": [
          {
            "Name": "NumeroAutorizacionDocumentoOrigen",
            "Data": null,
            "Value": "BBBBBBBB-0000-4000-B000-000000000002"
          },
          {
            "Name": "FechaEmisionDocumentoOrigen",
            "Data": null,
            "Value": "2022-06-29"
          },
          {
            "Name": "MotivoAjuste",
            "Data": null,
            "Value": "Devolucion porque el producto no salio bueno"
          },
          {
            "Name": "NumeroDocumentoOrigen",
            "Data": null,
            "Value": "1974750325"
          },
          {
            "Name": "SerieDocumentoOrigen",
            "Data": null,
            "Value": "62496FB5"
          }
        ]
      },
      {
        "Code": "FRONT-263C-444B-89BA-6F87EC1330C0",
        "Type": "ADENDA",
        "AditionalData": {
          "Data": [
            {
              "Info": [
                {
                  "Name": "OBSERVACIONES",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CANTIDAD_LETRAS",
                  "Data": null,
                  "Value": "CIENTO NOVENTA QUETZALES CON 00/100"
                }
              ],
              "Name": "INFORMACION_ADICIONAL"
            },
            {
              "Info": [
                {
                  "Name": "NumeroLinea",
                  "Data": null,
                  "Value": "1"
                },
                {
                  "Name": "Descripcion_Adicional",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CodigoEAN",
                  "Data": null,
                  "Value": "00015"
                },
                {
                  "Name": "CategoriaAdicional",
                  "Data": null,
                  "Value": "-"
                }
              ],
              "Name": "DetallesAux_Detalle"
            }
          ]
        },
        "AditionalInfo": [
          {
            "Name": "VALIDAR_REFERENCIA_INTERNA",
            "Data": null,
            "Value": "NO_VALIDAR"
          }
        ]
      }
    ]
  }
}
```

</details>

### 15.6 Ejemplo oficial - NABN

<details><summary>Ver JSON oficial adjunto</summary>

```json
{
  "Version": "1.00",
  "CountryCode": "GT",
  "Header": {
    "DocType": "NABN",
    "IssuedDateTime": "2022-06-30T11:28:00-06:00",
    "Currency": "GTQ"
  },
  "Seller": {
    "TaxID": "123456",
    "TaxIDAdditionalInfo": [
      {
        "Name": "AfiliacionIVA",
        "Data": null,
        "Value": "GEN"
      }
    ],
    "Name": "FEL TEST",
    "BranchInfo": {
      "Code": "1",
      "Name": "ESTABLECIMIENTO DE PRUEBA",
      "AddressInfo": {
        "Address": "4 AVENIDA 15-70 ZONA 10 LOCAL 3 EDIFICIO PALADIUM",
        "City": "01001",
        "District": "Guatemala",
        "State": "Guatemala",
        "Country": "GT"
      }
    }
  },
  "Buyer": {
    "TaxID": "12345678",
    "Name": "DIGIFACT SERVICIOS SOCIEDAD ANONIMA",
    "Contact": {
      "EmailList": {
        "Email": [
          "wendy.ayala@digifact.com.gt"
        ]
      }
    },
    "AddressInfo": {
      "Address": "4ta avenida 15-70 zona 10",
      "City": "01010",
      "District": "GUATEMALA",
      "State": "GUATEMALA",
      "Country": "GT"
    }
  },
  "Items": [
    {
      "Number": "1",
      "Codes": null,
      "Type": "Bien",
      "Description": "RETENEDOR BLANCO",
      "Qty": "1.000000",
      "UnitOfMeasure": "UNI",
      "Price": "100.000000",
      "Discounts": null,
      "Taxes": null,
      "Totals": {
        "TotalItem": "100.000000"
      }
    }
  ],
  "Totals": {
    "GrandTotal": {
      "InvoiceTotal": "100.000000"
    }
  },
  "AdditionalDocumentInfo": {
    "AdditionalInfo": [
      {
        "Code": "FRONT-67C1-4545-BA1E-AA3C115E18D6",
        "Type": "ADENDA",
        "AditionalData": {
          "Data": [
            {
              "Info": [
                {
                  "Name": "OBSERVACIONES",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CANTIDAD_LETRAS",
                  "Data": null,
                  "Value": "CIEN QUETZALES CON 00/100"
                }
              ],
              "Name": "INFORMACION_ADICIONAL"
            },
            {
              "Info": [
                {
                  "Name": "NumeroLinea",
                  "Data": null,
                  "Value": "1"
                },
                {
                  "Name": "Descripcion_Adicional",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CodigoEAN",
                  "Data": null,
                  "Value": "1000"
                },
                {
                  "Name": "CategoriaAdicional",
                  "Data": null,
                  "Value": "-"
                }
              ],
              "Name": "DetallesAux_Detalle"
            }
          ]
        },
        "AditionalInfo": [
          {
            "Name": "VALIDAR_REFERENCIA_INTERNA",
            "Data": null,
            "Value": "NO_VALIDAR"
          }
        ]
      }
    ]
  }
}
```

</details>

### 15.7 Ejemplo oficial - FESP

<details><summary>Ver JSON oficial adjunto</summary>

```json
{
  "Version": "1.00",
  "CountryCode": "GT",
  "Header": {
    "DocType": "FESP",
    "IssuedDateTime": "2022-06-29T11:28:00-06:00",
    "Currency": "GTQ"
  },
  "Seller": {
    "TaxID": "123456",
    "TaxIDAdditionalInfo": [
      {
        "Name": "AfiliacionIVA",
        "Data": null,
        "Value": "GEN"
      }
    ],
    "Name": "FEL TEST",
    "BranchInfo": {
      "Code": "1",
      "Name": "ESTABLECIMIENTO DE PRUEBA",
      "AddressInfo": {
        "Address": "4 AVENIDA 15-70 ZONA 10 LOCAL 3 EDIFICIO PALADIUM",
        "City": "01001",
        "District": "Guatemala",
        "State": "Guatemala",
        "Country": "GT"
      }
    }
  },
  "Buyer": {
    "TaxID": "10001794",
    "Name": "CASTRO ESTRADA MORALES NORA YESENIA",
    "AddressInfo": {
      "Address": "CIUDAD",
      "City": "01010",
      "District": "GUATEMALA",
      "State": "GUATEMALA",
      "Country": "GT"
    }
  },
  "Items": [
    {
      "Number": "1",
      "Type": "Bien",
      "Description": "ALQUILER DE EQUIPO DE LIGASURE",
      "Qty": "1.000000",
      "UnitOfMeasure": "UNI",
      "Price": "2500.000000",
      "Taxes": {
        "Tax": [
          {
            "Code": "1",
            "Description": "IVA",
            "TaxableAmount": "2232.142857",
            "Amount": "267.857143"
          }
        ]
      },
      "Totals": {
        "TotalItem": "2500.000000"
      }
    }
  ],
  "Totals": {
    "TotalTaxes": {
      "TotalTax": [
        {
          "Description": "IVA",
          "Amount": "267.857143"
        }
      ]
    },
    "GrandTotal": {
      "InvoiceTotal": "2500.000000"
    }
  },
  "AdditionalDocumentInfo": {
    "AdditionalInfo": [
      {
        "Code": "FESP",
        "Type": "COMPLEMENTO",
        "AditionalInfo": [
          {
            "Name": "RetencionISR",
            "Data": null,
            "Value": "111.607143"
          },
          {
            "Name": "RetencionIVA",
            "Data": null,
            "Value": "267.857143"
          },
          {
            "Name": "TotalMenosRetenciones",
            "Data": null,
            "Value": "2120.535714"
          }
        ]
      },
      {
        "Code": "FRONT-67C1-4545-BA1E-AA3C115E18D6",
        "Type": "ADENDA",
        "AditionalData": {
          "Data": [
            {
              "Info": [
                {
                  "Name": "OBSERVACIONES",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CANTIDAD_LETRAS",
                  "Data": null,
                  "Value": "DOS MIL QUINIENTOS QUETZALES CON 00/100"
                }
              ],
              "Name": "INFORMACION_ADICIONAL"
            },
            {
              "Info": [
                {
                  "Name": "NumeroLinea",
                  "Data": null,
                  "Value": "1"
                },
                {
                  "Name": "Descripcion_Adicional",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CodigoEAN",
                  "Data": null,
                  "Value": "002"
                },
                {
                  "Name": "CategoriaAdicional",
                  "Data": null,
                  "Value": "-"
                }
              ],
              "Name": "DetallesAux_Detalle"
            }
          ]
        },
        "AditionalInfo": [
          {
            "Name": "VALIDAR_REFERENCIA_INTERNA",
            "Data": null,
            "Value": "NO_VALIDAR"
          }
        ]
      }
    ]
  }
}
```

</details>

### 15.8 Ejemplo oficial - RECI (XML)

<details><summary>Ver XML oficial adjunto</summary>

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!-- 
    UNIVERSIDAD
-->
<Root>
    <Version>1.00</Version>
    <CountryCode>GT</CountryCode>
    <Header>
        <DocType>RECI</DocType> <!-- DatosGenerales@Tipo-->
        <IssuedDateTime>2022-06-29T11:28:00-06:00</IssuedDateTime> <!-- DatosGenerales@FechaHoraEmision --> 
        <Currency>GTQ</Currency> <!-- DatosGenerales@CodigoMoneda  -->  
    </Header>
    
    <Seller>
        <TaxID>123456</TaxID> <!-- Emisor@NITEmisor -->
        <TaxIDAdditionalInfo>
            <Info Name="AfiliacionIVA" Value="GEN"/> <!-- Emisor@AfiliacionIVA -->
        </TaxIDAdditionalInfo>
        <Name>FEL TEST</Name> <!-- Emisor@NombreEmisor -->
        <AdditionlInfo> <!-- FRASES -->
            <Info Name="TipoFrase" Data="1" Value="4"/>
            <Info Name="Escenario" Data="1" Value="5"/>
        </AdditionlInfo>
        <BranchInfo>
            <Code>1</Code> <!-- Emisor@CodigoEstablecimiento -->
            <Name>ESTABLECIMIENTO DE PRUEBA</Name> <!-- Emisor@NombreComercial -->
            <AddressInfo> <!-- DireccionEmisor -->
                <Address>4 AVENIDA 15-70 ZONA 10 LOCAL 3 EDIFICIO PALADIUM</Address> <!-- Direccion -->
                <City>01001</City>      <!-- CodigoPostal -->
                <District>Guatemala</District>  <!-- Municipio -->
                <State>Guatemala</State>   <!-- Departamento -->
                <Country>GT</Country>       <!-- Pais -->
            </AddressInfo>
        </BranchInfo>
    </Seller>
    
    <Buyer>
        <TaxID>12345678</TaxID> <!-- Receptor@IDReceptor -->
        <!-- si la casilla IDReceptor contiene el NIT, debe contener el nombre asociado al NIT, caso contrario puede ser cualquier cosa xd-->
        <Name>DIGIFACT SERVICIOS SOCIEDAD ANONIMA</Name> <!-- Receptor@NombreReceptor -->
        <!-- <AdditionlInfo></AdditionlInfo> -->
        <AddressInfo>   <!-- DireccionReceptor -->
            <Address>4ta avenida 15-70 zona 10</Address>        <!-- Direccion -->
            <City>01010</City>          <!-- CodigoPostal -->
            <District>GUATEMALA</District>  <!-- Municipio -->
            <State>GUATEMALA</State>        <!-- Departamento -->
            <Country>GT</Country>    <!-- Pais -->
        </AddressInfo>
    </Buyer>

    <Items>
        <Item Number="1"> <!-- Number = Item@NumeroLinea -->
            <Type>Bien</Type> <!-- Item@BienOServicio -->
            <Description>Jalapeno</Description> <!-- Descripcion -->
            <Qty>1.000000</Qty> <!-- Cantidad -->
            <UnitOfMeasure>UNI</UnitOfMeasure> <!-- UnidadMedida -->
            <Price>250.000000</Price> <!-- PrecioUnitario | precio sin impuesto -->
            <Totals> <!-- Total -->
                <TotalItem>250.000000</TotalItem> <!-- Total -->
            </Totals>
        </Item>
    </Items>
    
    <Totals> <!-- Totales -->
        <GrandTotal>
            <InvoiceTotal>250.000000</InvoiceTotal> <!-- GranTotal -->
        </GrandTotal>
        <!-- <InWords></InWords> -->
    </Totals>
    
    <AdditionalDocumentInfo>
        <!-- ADENDA -->
        <AdditionalInfo>
            <Code>FRONT-67C1-4545-BA1E-AA3C115E18D6</Code> <!-- REFERENCIA_INTERNA -->
            <Type>ADENDA</Type>
            <AditionalData>
                <!-- INFORMACION_ADICIONAL -->
                <Data Name="INFORMACION_ADICIONAL">
                    <Info Name="OBSERVACIONES" Value="-"/>
                    <Info Name="CANTIDAD_LETRAS" Value="DOSCIENTOS CINCUENTA QUETZALES CON 00/100"/>
                </Data>
                <!-- Detalles_Auxiliares, pueden venir muchos DetallesAux_Detalle-->
                <Data Name="DetallesAux_Detalle">
                    <Info Name="NumeroLinea" Value="1"/>
                    <Info Name="Descripcion_Adicional" Value="-"/>
                    <Info Name="CodigoEAN" Value="00025"/>
                    <Info Name="CategoriaAdicional" Value="-"/>
                </Data>
            </AditionalData>
            <!-- 
            Detalles_Donacion
                Tipo * -> Universidad | Colegio | Inmobiliaria
                NombreAlumno
                Carne
                UnidadAcademica
                Grado
                Seccion
                Jornada
                TipoDePago
                NoReferencia
                Banco
                Total
                Observaciones
                MZ
                APTO
                TipoPago
            -->
            <AditionalInfo>
                <Info Name="VALIDAR_REFERENCIA_INTERNA" Value="NO_VALIDAR"/>
                <Info Name="Tipo" Value="Universidad"/>
                <Info Name="NombreAlumno" Value="Julio Cifuentes"/>
                <Info Name="Carne" Value="201801677"/>
                <Info Name="UnidadAcademica" Value="08"/>
            </AditionalInfo>
        </AdditionalInfo>
    </AdditionalDocumentInfo>
</Root>
```

</details>

### 15.9 Equivalente JSON inferido para RECI (a partir del XML oficial)

<details><summary>Ver JSON inferido</summary>

```json
{
  "Version": "1.00",
  "CountryCode": "GT",
  "Header": {
    "DocType": "RECI",
    "IssuedDateTime": "2022-06-29T11:28:00-06:00",
    "Currency": "GTQ"
  },
  "Seller": {
    "TaxID": "123456",
    "TaxIDAdditionalInfo": [
      {
        "Name": "AfiliacionIVA",
        "Data": null,
        "Value": "GEN"
      }
    ],
    "Name": "FEL TEST",
    "AdditionlInfo": [
      {
        "Name": "TipoFrase",
        "Data": "1",
        "Value": "4"
      },
      {
        "Name": "Escenario",
        "Data": "1",
        "Value": "5"
      }
    ],
    "BranchInfo": {
      "Code": "1",
      "Name": "ESTABLECIMIENTO DE PRUEBA",
      "AddressInfo": {
        "Address": "4 AVENIDA 15-70 ZONA 10 LOCAL 3 EDIFICIO PALADIUM",
        "City": "01001",
        "District": "Guatemala",
        "State": "Guatemala",
        "Country": "GT"
      }
    }
  },
  "Buyer": {
    "TaxID": "12345678",
    "Name": "DIGIFACT SERVICIOS SOCIEDAD ANONIMA",
    "AddressInfo": {
      "Address": "4ta avenida 15-70 zona 10",
      "City": "01010",
      "District": "GUATEMALA",
      "State": "GUATEMALA",
      "Country": "GT"
    }
  },
  "Items": [
    {
      "Number": "1",
      "Type": "Bien",
      "Description": "Jalapeno",
      "Qty": "1.000000",
      "UnitOfMeasure": "UNI",
      "Price": "250.000000",
      "Totals": {
        "TotalItem": "250.000000"
      }
    }
  ],
  "Totals": {
    "GrandTotal": {
      "InvoiceTotal": "250.000000"
    }
  },
  "AdditionalDocumentInfo": {
    "AdditionalInfo": [
      {
        "Code": "FRONT-67C1-4545-BA1E-AA3C115E18D6",
        "Type": "ADENDA",
        "AditionalData": {
          "Data": [
            {
              "Name": "INFORMACION_ADICIONAL",
              "Info": [
                {
                  "Name": "OBSERVACIONES",
                  "Value": "-"
                },
                {
                  "Name": "CANTIDAD_LETRAS",
                  "Value": "DOSCIENTOS CINCUENTA QUETZALES CON 00/100"
                }
              ]
            },
            {
              "Name": "DetallesAux_Detalle",
              "Info": [
                {
                  "Name": "NumeroLinea",
                  "Value": "1"
                },
                {
                  "Name": "Descripcion_Adicional",
                  "Value": "-"
                },
                {
                  "Name": "CodigoEAN",
                  "Value": "00025"
                },
                {
                  "Name": "CategoriaAdicional",
                  "Value": "-"
                }
              ]
            }
          ]
        },
        "AditionalInfo": [
          {
            "Name": "VALIDAR_REFERENCIA_INTERNA",
            "Value": "NO_VALIDAR"
          },
          {
            "Name": "Tipo",
            "Value": "Universidad"
          },
          {
            "Name": "NombreAlumno",
            "Value": "Julio Cifuentes"
          },
          {
            "Name": "Carne",
            "Value": "201801677"
          },
          {
            "Name": "UnidadAcademica",
            "Value": "08"
          }
        ]
      }
    ]
  }
}
```

</details>

### 15.10 Ejemplo oficial - RDON

<details><summary>Ver JSON oficial adjunto</summary>

```json
{
  "Version": "1.00",
  "CountryCode": "GT",
  "Header": {
    "DocType": "RDON",
    "IssuedDateTime": "2022-06-29T11:28:00-06:00",
    "Currency": "GTQ",
    "AdditionalIssueDocInfo": [
      {
        "Name": "TipoPersoneria",
        "Data": null,
        "Value": "734"
      }
    ]
  },
  "Seller": {
    "TaxID": "123456",
    "TaxIDAdditionalInfo": [
      {
        "Name": "AfiliacionIVA",
        "Data": null,
        "Value": "GEN"
      }
    ],
    "Name": "FEL TEST",
    "AdditionlInfo": [
      {
        "Name": "TipoFrase",
        "Data": "1",
        "Value": "4"
      },
      {
        "Name": "Escenario",
        "Data": "1",
        "Value": "4"
      }
    ],
    "BranchInfo": {
      "Code": "1",
      "Name": "ESTABLECIMIENTO DE PRUEBA",
      "AddressInfo": {
        "Address": "4 AVENIDA 15-70 ZONA 10 LOCAL 3 EDIFICIO PALADIUM",
        "City": "01001",
        "District": "Guatemala",
        "State": "Guatemala",
        "Country": "GT"
      }
    }
  },
  "Buyer": {
    "TaxID": "12345678",
    "Name": "DIGIFACT SERVICIOS SOCIEDAD ANONIMA",
    "AddressInfo": {
      "Address": "4ta avenida 15-70 zona 10",
      "City": "01010",
      "District": "GUATEMALA",
      "State": "GUATEMALA",
      "Country": "GT"
    }
  },
  "Items": [
    {
      "Number": "1",
      "Codes": null,
      "Type": "Bien",
      "Description": "Prueba 5",
      "Qty": "1.000000",
      "UnitOfMeasure": "UNI",
      "Price": "50.000000",
      "Discounts": null,
      "Taxes": null,
      "Totals": {
        "TotalItem": "50.000000"
      }
    }
  ],
  "Totals": {
    "GrandTotal": {
      "InvoiceTotal": "50.000000"
    }
  },
  "AdditionalDocumentInfo": {
    "AdditionalInfo": [
      {
        "Code": "FRONT-67C1-4545-BA1E-AA3C115E18D6",
        "Type": "ADENDA",
        "AditionalData": {
          "Data": [
            {
              "Info": [
                {
                  "Name": "OBSERVACIONES",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CANTIDAD_LETRAS",
                  "Data": null,
                  "Value": "CINCUENTA QUETZALES CON 00/100"
                }
              ],
              "Name": "INFORMACION_ADICIONAL"
            },
            {
              "Info": [
                {
                  "Name": "NumeroLinea",
                  "Data": null,
                  "Value": "1"
                },
                {
                  "Name": "Descripcion_Adicional",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CodigoEAN",
                  "Data": null,
                  "Value": "00010"
                },
                {
                  "Name": "CategoriaAdicional",
                  "Data": null,
                  "Value": "-"
                }
              ],
              "Name": "DetallesAux_Detalle"
            }
          ]
        },
        "AditionalInfo": [
          {
            "Name": "VALIDAR_REFERENCIA_INTERNA",
            "Data": null,
            "Value": "NO_VALIDAR"
          }
        ]
      }
    ]
  }
}
```

</details>

### 15.11 Ejemplo oficial - FACT con CCA

<details><summary>Ver JSON oficial adjunto</summary>

```json
{
  "Version": "1.00",
  "CountryCode": "GT",
  "Header": {
    "DocType": "FACT",
    "IssuedDateTime": "2022-08-30T14:00:00-06:00",
    "Currency": "GTQ"
  },
  "Seller": {
    "TaxID": "123456",
    "TaxIDAdditionalInfo": [
      {
        "Name": "AfiliacionIVA",
        "Data": null,
        "Value": "GEN"
      }
    ],
    "Name": "FEL TEST",
    "AdditionlInfo": [
      {
        "Name": "TipoFrase",
        "Data": "1",
        "Value": "1"
      },
      {
        "Name": "Escenario",
        "Data": "1",
        "Value": "1"
      }
    ],
    "BranchInfo": {
      "Code": "1",
      "Name": "ESTABLECIMIENTO DE PRUEBA",
      "AddressInfo": {
        "Address": "4 AVENIDA 15-70 ZONA 10 LOCAL 3 EDIFICIO PALADIUM",
        "City": "01001",
        "District": "Guatemala",
        "State": "Guatemala",
        "Country": "GT"
      }
    }
  },
  "Buyer": {
    "TaxID": "12345678",
    "Name": "DIGIFACT SERVICIOS SOCIEDAD ANONIMA",
    "Contact": {
      "EmailList": {
        "Email": [
          "wendy.ayala@digifact.com.gt"
        ]
      }
    },
    "AddressInfo": {
      "Address": "4ta avenida 15-70 zona 10",
      "City": "01010",
      "District": "GUATEMALA",
      "State": "GUATEMALA",
      "Country": "GT"
    }
  },
  "Items": [
    {
      "Number": "1",
      "Codes": null,
      "Type": "Bien",
      "Description": "LECHE DOS PINOS ENTERA LIQUIDA LITRO (VERDE)",
      "Qty": "2.000000",
      "UnitOfMeasure": "UNI",
      "Price": "50.000000",
      "Discounts": {
        "Discount": [
          {
            "Amount": "10.00"
          }
        ]
      },
      "Taxes": {
        "Tax": [
          {
            "Code": "1",
            "Description": "IVA",
            "TaxableAmount": "80.357143",
            "Amount": "9.642857"
          }
        ]
      },
      "Totals": {
        "TotalItem": "90.000000"
      }
    }
  ],
  "Totals": {
    "TotalTaxes": {
      "TotalTax": [
        {
          "Description": "IVA",
          "Amount": "9.642857"
        }
      ]
    },
    "GrandTotal": {
      "InvoiceTotal": "90.000000"
    }
  },
  "AdditionalDocumentInfo": {
    "AdditionalInfo": [
      {
        "Code": "CCA",
        "Type": "COMPLEMENTO",
        "AditionalData": {
          "Data": [
            {
              "Info": [
                {
                  "Name": "NITtercero",
                  "Data": null,
                  "Value": "112706517"
                },
                {
                  "Name": "NumeroDocumento",
                  "Data": null,
                  "Value": "1"
                },
                {
                  "Name": "FechaDocumento",
                  "Data": null,
                  "Value": "2022-06-03"
                },
                {
                  "Name": "Descripcion",
                  "Data": null,
                  "Value": "Me debe dinero :c"
                },
                {
                  "Name": "BaseImponible",
                  "Data": null,
                  "Value": "100.00"
                },
                {
                  "Name": "MontoCobroDAI",
                  "Data": null,
                  "Value": "0"
                },
                {
                  "Name": "MontoCobroIVA",
                  "Data": null,
                  "Value": "12.00"
                },
                {
                  "Name": "MontoCobroOtros",
                  "Data": null,
                  "Value": "0"
                },
                {
                  "Name": "MontoCobroTotal",
                  "Data": null,
                  "Value": "12.00"
                }
              ]
            },
            {
              "Info": [
                {
                  "Name": "NITtercero",
                  "Data": null,
                  "Value": "112706517"
                },
                {
                  "Name": "NumeroDocumento",
                  "Data": null,
                  "Value": "2"
                },
                {
                  "Name": "FechaDocumento",
                  "Data": null,
                  "Value": "2022-06-03"
                },
                {
                  "Name": "Descripcion",
                  "Data": null,
                  "Value": "Me debe dinero x2 :c"
                },
                {
                  "Name": "BaseImponible",
                  "Data": null,
                  "Value": "120.00"
                },
                {
                  "Name": "MontoCobroDAI",
                  "Data": null,
                  "Value": "0"
                },
                {
                  "Name": "MontoCobroIVA",
                  "Data": null,
                  "Value": "14.40"
                },
                {
                  "Name": "MontoCobroOtros",
                  "Data": null,
                  "Value": "0"
                },
                {
                  "Name": "MontoCobroTotal",
                  "Data": null,
                  "Value": "14.400"
                }
              ]
            }
          ]
        }
      },
      {
        "Code": "FRONT-67C1-4545-BA1E-AA3C115E18D6",
        "Type": "ADENDA",
        "AditionalData": {
          "Data": [
            {
              "Info": [
                {
                  "Name": "OBSERVACIONES",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CANTIDAD_LETRAS",
                  "Data": null,
                  "Value": "NOVENTA QUETZALES CON 00/100"
                }
              ],
              "Name": "INFORMACION_ADICIONAL"
            },
            {
              "Info": [
                {
                  "Name": "NumeroLinea",
                  "Data": null,
                  "Value": "1"
                },
                {
                  "Name": "Descripcion_Adicional",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CodigoEAN",
                  "Data": null,
                  "Value": "000001BOTAS"
                },
                {
                  "Name": "CategoriaAdicional",
                  "Data": null,
                  "Value": "-"
                }
              ],
              "Name": "DetallesAux_Detalle"
            }
          ]
        },
        "AditionalInfo": [
          {
            "Name": "VALIDAR_REFERENCIA_INTERNA",
            "Data": null,
            "Value": "NO_VALIDAR"
          }
        ]
      }
    ]
  }
}
```

</details>

### 15.12 Ejemplo oficial adjunto - FPEQ (con inconsistencia de `DocType`)

<details><summary>Ver JSON oficial adjunto</summary>

```json
{
  "Version": "1.00",
  "CountryCode": "GT",
  "Header": {
    "DocType": "FACT",
    "IssuedDateTime": "2022-12-21T09:30:00-06:00",
    "AdditionalIssueType": null,
    "ExchangeRate": null,
    "Currency": "GTQ"
  },
  "Seller": {
    "TaxID": "123456",
    "TaxIDAdditionalInfo": [
      {
        "Name": "AfiliacionIVA",
        "Data": null,
        "Value": "PEQ"
      }
    ],
    "Name": "FEL TEST",
    "AdditionlInfo": [
      {
        "Name": "TipoFrase",
        "Data": "1",
        "Value": "3"
      },
      {
        "Name": "Escenario",
        "Data": "1",
        "Value": "1"
      }
    ],
    "BranchInfo": {
      "Code": "1",
      "Name": "ESTABLECIMIENTO DE PRUEBA",
      "AddressInfo": {
        "Address": "4 AVENIDA 15-70 ZONA 10 LOCAL 3 EDIFICIO PALADIUM",
        "City": "01010",
        "District": "Guatemala",
        "State": "Guatemala",
        "Country": "GT"
      }
    }
  },
  "Buyer": {
    "TaxID": "CF",
    "Name": "CONSUMIDOR FINAL",
    "AddressInfo": {
      "Address": "CIUDAD",
      "City": "01010",
      "District": "GUATEMALA",
      "State": "GUATEMALA",
      "Country": "GT"
    }
  },
  "Items": [
    {
      "Number": "1",
      "Codes": null,
      "Type": "Bien",
      "Description": "TEST",
      "Qty": "5.000000",
      "UnitOfMeasure": "EA",
      "Price": "25.000000",
      "Discounts": null,
      "Taxes": {
        "Tax": [
          {
            "Code": "1",
            "Description": "TURISMO HOSPEDAJE",
            "TaxableAmount": "169.642857",
            "Amount": "20.357143"
          }
        ]
      },
      "Totals": {
        "TotalItem": "25.000000"
      }
    }
  ],
  "Totals": {
    "TotalTaxes": {
      "TotalTax": [
        {
          "Description": "TURISMO HOSPEDAJE",
          "Amount": "20.357143"
        }
      ]
    },
    "GrandTotal": {
      "InvoiceTotal": "25.000000"
    }
  },
  "AdditionalDocumentInfo": {
    "AdditionalInfo": [
      {
        "Code": "FRONT-263C-444B-89BA-6F87EC1330C0",
        "Type": "ADENDA",
        "AditionalData": {
          "Data": [
            {
              "Info": [
                {
                  "Name": "OBSERVACIONES",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CANTIDAD_LETRAS",
                  "Data": null,
                  "Value": "CIENTO NOVENTA QUETZALES CON 00/100"
                }
              ],
              "Name": "INFORMACION_ADICIONAL"
            },
            {
              "Info": [
                {
                  "Name": "NumeroLinea",
                  "Data": null,
                  "Value": "1"
                },
                {
                  "Name": "Descripcion_Adicional",
                  "Data": null,
                  "Value": "-"
                },
                {
                  "Name": "CodigoEAN",
                  "Data": null,
                  "Value": "00015"
                },
                {
                  "Name": "CategoriaAdicional",
                  "Data": null,
                  "Value": "-"
                }
              ],
              "Name": "DetallesAux_Detalle"
            }
          ]
        },
        "AditionalInfo": [
          {
            "Name": "VALIDAR_REFERENCIA_INTERNA",
            "Data": null,
            "Value": "NO_VALIDAR"
          }
        ]
      }
    ]
  }
}
```

</details>

