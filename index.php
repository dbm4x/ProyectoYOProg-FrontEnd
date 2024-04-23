<?php
require dirname($_SERVER['DOCUMENT_ROOT']) . '/include/custom/base.php';


class resumen_carga
{
    private $debug;
    private $target_folder;
    private $html;
    private $proceso;
    private $institucion;
    private $id_proceso;
    private $id_institucion;
    private $url_retorno;
    private $message;
    private $cumplimiento;
    private $perfil;
    private $atributos = array();
    private $firmado;
    private $campos = array();
    private $tam_maximo;
    private $fecha_disposicion;

    function __construct()
    {
        global $_html;
        $this->html = $_html;
        $ds = DIRECTORY_SEPARATOR;
        $this->target_folder = dirname($_SERVER['DOCUMENT_ROOT']) . $ds . 'archivos' . $ds;
        $this->debug = $_SESSION['_debug']['debug'];
        $this->tam_maximo = ini_get('post_max_size');
    }

    function run()
    {
        try {
            $this->getParametros();
            $this->validacion();
            $this->atributosCarga();
            $this->inicio();
        } catch (Exception $e) {
            $this->message = $e->getMessage();
            mensaje($this->message, 'Error', $this->url_retorno);
        }
    }

    private function inicio()
    {
        $this->html->addReemplazo('bodyclass', 'captura_articulo');
        $this->html->inicio();

        $this->getCamposMantenedor();

        if ($this->getPorcentajeCumplimiento() >= 100 && $this->cargaFirmada() && $this->cumplimiento['detalle']['certificado_firmado'] != '') {
            echo proceso_dotacion::getHTMLPasosSeleccionarDotacion(2, true);
        }
        else{
            echo proceso_dotacion::getHTMLPasosSeleccionarDotacion(2, false);
        }

        echo $this->infoCarga();
        echo $this->formularioGastosPersonal();
        echo $this->formularioPersonalMunicipal();
        echo $this->formularioEscalafonVigente();
        echo $this->getPopup();
        $html = $this->formularioPoliticasRRHH();
        echo $this->getFormularioFirma($html );

        echo $this->getJavaScript();

        $this->generarCertificado();
    }

    private function getParametros()
    {
        $valores = mantenedor::getValores();
        if (count($valores)) {
            $this->id_proceso = $valores['proceso'];
            $this->id_institucion = $valores['institucion'];
        } else {
            $this->id_institucion = get_parametro('inst_id', 'int');
            $this->id_proceso = get_parametro('proc_id', 'int');
        }

        if (empty($this->id_proceso) || empty($this->id_institucion)) {
            $this->url_retorno = '/seleccionar_proceso/index.php';
            throw new Exception('Debe seleccionar la Municipalidad y el Proceso de Dotación');
        }
    }

    private function validacion()
    {
        global $_session;
        $_session->validar(FUNC_CAPTURA_DOTACION);

        #Valida los permisos sobre la pantalla
        $this->perfil = new perfil($_SESSION['SESSION']->getPerfId());
        if ($this->perfil->getRolId() == ROL_SECTOR) {
            if ($this->perfil->getInstId() != $this->id_institucion) {
                $this->url_retorno = '/principal.php';
                throw new Exception('Acceso denegado', '/principal.php', 'Error');
            }
        }

        $this->proceso = new proceso_dotacion($this->id_proceso, array('error_level' => false));
        if (is_null($this->proceso->getId())) {
            throw new Exception("No existe el proceso de dotación con id {$this->id_proceso}");
        }

        $this->institucion = new institucion($this->id_institucion, array('error_level' => false));
        if (is_null($this->institucion->getId())) {
            throw new Exception("No existe la municipalidad con id {$this->id_institucion}");
        }

    }

    private function generarCertificado(){
        /* SI YA ESTA FIRMADO SE GENERA EL ARCHIVO PDF */
        if ($this->cargaFirmada()) {
            global $_bd;
            include_once 'generar_pdf.php';
            $resumen = resumen_gastos::getResumenGastos($this->id_institucion, $this->id_proceso);
            $link_resumen_gastos = $resumen['link_resumen_gastos'];
            $total_resumen = $resumen['total_resumen'];
            generar_certificado_pdf($this->id_proceso, $this->institucion, $this->cumplimiento, $this->fecha_disposicion, $resumen, $link_resumen_gastos, $total_resumen);
            $_bd->commit();
        }
    }

    private function cargaFirmada(){
        return ($this->firmado && $this->proceso->getEstado());
    }


    private function getPorcentajeCumplimiento(){
        return $this->cumplimiento['completitud']['cumplimiento'];
    }

    private function getPorcentajeCumplimientoDotacion(){
        return $this->cumplimiento['dotacion']['cumplimiento'];
    }

    private function getPorcentajeCumplimientoGastos(){
        return $this->cumplimiento['resumen_gastos']['cumplimiento'];
    }

    private function getPorcentajeCumplimientoEscalafon(){
        return $this->cumplimiento['escalafon']['cumplimiento'];
    }

    private function atributosCarga()
    {
        $this->cumplimiento = cumplimiento::buscar(array('proc_id' => $this->id_proceso, 'inst_id' => $this->id_institucion));
        if (!count($this->cumplimiento)) {
            $this->cumplimiento = cumplimiento::inicializar($this->id_proceso, $this->id_institucion);
        }

        #Verifica que el proceso haya sido firmado por el Encargado
        $firma_proceso = firmas::buscar(array('proc_id' => $this->id_proceso, 'inst_id' => $this->id_institucion));
        $this->firmado = (count($firma_proceso)) ? true : false;

        #PORCENTAJE DE CUMPLIMIENTO y ARCHIVOS SUBIDOS
        $this->cumplimiento = cumplimiento::porcentajeCumplimiento($this->id_institucion, $this->id_proceso, false);

        #Colores para los porcentajes de cumplimiento
        $this->atributos['style_porcentaje_dotacion'] = ($this->cumplimiento['dotacion']['cumplimiento'] >= 100) ? 'verde' : 'rojo';
        $this->atributos['style_porcentaje_resumen'] = ($this->cumplimiento['resumen_gastos']['cumplimiento'] >= 100) ? 'verde' : 'rojo';
        $this->atributos['style_porcentaje_total'] = ($this->cumplimiento['completitud']['cumplimiento'] >= 100 && $this->cumplimiento['detalle']['certificado_firmado'] != '') ? 'verde' : 'rojo';
        $this->atributos['style_porcentaje_escalafon'] = ($this->cumplimiento['escalafon']['cumplimiento'] >= 100) ? 'verde' : 'rojo';
        $this->atributos['style_porcentaje_politicas_rrhh'] = ($this->cumplimiento['politicas_rrhh']['cumplimiento'] >= 100) ? 'verde' : 'rojo';

    }

    private function getCamposMantenedor(){

        $this->campos = array(
                'proceso' => array(
                    'tipo' => 'int',
                    'tipo_generico' => 'number',
                    'atributo' => 'proceso',
                    'nombre' => 'proceso',
                    'crearOculto' => true,
                    'editarOculto' => true,
                    'default' => $this->id_proceso,
            ),
                 'institucion' => array(
                    'tipo' => 'int',
                    'tipo_generico' => 'number',
                    'atributo' => 'institucion',
                    'nombre' => 'institucion',
                    'crearOculto' => true,
                    'editarOculto' => true,
                    'default' => $this->id_institucion,
            )
        );

        /* Mantenedor para los datos de firma del proceso */
        
            $codigo_area = array();
            $codigos_chile = pais::listar(array('_orden' => 'pais_area', 'pais_codigo' => 'CH', '_iterator' => true));
            foreach ($codigos_chile as $codigo => $nombre){
                $pais = new pais($codigo);
                $codigo_area[$codigo] = $pais->getArea();
            }

            $requerido = ($this->perfil->getRolId() != ROL_OPERADOR) ? true : false;

           $this->campos = array_merge(
            $this->campos,
            array(
                'rut' => array(
                    'nombre' => 'RUT',
                    'atributo' => 'rut',
                    'tipo' => 'rut',
                    'tipo_generico' => 'string',
                    'largoMax' => 12,
                    'requerido' => $requerido,
                ),
                'nombre' => array(
                    'nombre' => 'Nombre',
                    'atributo' => 'nombre',
                    'tipo' => 'text',
                    'tipo_generico' => 'string',
                    'largoMax' => 100,
                    'requerido' => $requerido,
                ),
                'cargo' => array(
                    'nombre' => 'Cargo',
                    'atributo' => 'cargo',
                    'tipo' => 'text',
                    'tipo_generico' => 'string',
                    'largoMax' => 50,
                    'requerido' => $requerido,
                ),
                'correo' => array(
                    'nombre' => 'Correo',
                    'atributo' => 'correo',
                    'tipo' => 'email',
                    'tipo_generico' => 'string',
                    'largoMax' => 50,
                    'requerido' => $requerido,
                ),
                'codigo' => array(
                    'nombre' => 'Código',
                    'atributo' => 'codigo',
                    'tipo' => 'int',
                    'default' => '+56',
                    'noEdita'   => true,
                    'requerido' => $requerido,
                ),
                'area' => array(
                    'nombre' => 'Área',
                    'atributo' => 'area',
                    'tipo' => 'lista',
                    'requerido' => $requerido,
                    'valores' => $codigo_area,
                    'style' => 'width:50px',
                ),
                'telefono' => array(
                    'nombre' => 'Teléfono',
                    'atributo' => 'telefono',
                    'tipo' => 'int',
                    'largoMin' => 8,
                    'largoMax' => 8,
                    'requerido' => $requerido,
                    'atributos' => 'width:50px',
                ),
                'password' => array(
                    'tipo' => 'text',
                    'type' => 'password',
                    'atributo' => 'password',
                    'nombre' => 'Contraseña',
                    'requerido' => $requerido,
                    'largoMax' => 50,
                    'default' => '******'
                )
            )
            );
    }


    private function infoCarga()
    {
        if ($this->getPorcentajeCumplimiento() >= 100 && $this->cumplimiento['detalle']['certificado_firmado'] == '') {
            $porcentajeTotalCumplimiento = '99';
        }
        else{
            $porcentajeTotalCumplimiento = $this->getPorcentajeCumplimiento();
        }

        $html = <<<HTML
        <input id="proceso" type="hidden" value="{$this->id_proceso}"/>
        <input id="institucion" type="hidden" value="{$this->id_institucion}"/>
        <input id="debug" type="hidden" value="{$this->debug}"/>
        <br>
HTML;
                if ($this->getPorcentajeCumplimiento() >= 100 && $this->cargaFirmada() && $this->cumplimiento['detalle']['certificado_firmado'] == '') {
            $html .= <<<HTML
            <div id="aviso-amarillo">
                <div align="justify"><strong>El Certificado Final debe ser firmado por el Secretario Municipal, y cargado en el sistema para dar por cerrado el Proceso satisfactoriamente</strong></div>
            </div>
            <br/>
HTML;
        }
        $html .= <<<HTML
        <table width="100%" border="0" class="tabla-simple">
        <tr>
            <th align="center"><h2 class="resumen_titulo">Municipalidad </h2><h4></h4></th>
            <th align="center"><h2 class="resumen_titulo">Proceso  </h2><h4></h4></th>
            <th align="center"><h2>Cumplimiento <span id="cumplimiento"></span></h2></th>
        </tr>
        <tr>
            <td align="center"><h3>{$this->institucion->getNombre()}</h3></td>
            <td align="center"><h3>{$this->proceso->getNombre()}</h3></td>
            <td align="center"><h3 class="resumen_porcentaje {$this->atributos['style_porcentaje_total']}">{$porcentajeTotalCumplimiento}%</h3></td>
        </tr>
        </table>
        <br/>
        
	    
HTML;
        if ($this->atributos['porc_cumplimiento'] < 100) {
            $html .= <<<HTML
            <div id="aviso-amarillo">
                <div align="center"><strong>Importante </strong></div><br/> <br/>
                <div align="justify">Deberá cargar la sección PERSONAL MUNICIPAL completamente, incluyendo el certificado firmado y cerrar el proceso para poder continuar con la sección GASTOS ANUAL EN PERSONAL Y ALCALDE</div>
            </div>
            <br/>
HTML;
        }

        return $html;
    }

    private function formularioGastosPersonal()
    {
        $resumen = resumen_gastos::getResumenGastos($this->id_institucion, $this->id_proceso);
        $total_resumen = $resumen['total_resumen'];

        #Si el documento no ha sido firmado se muestran los botones
        $btnResumenGastos="";
        if (!$this->cargaFirmada()) {
            if ($this->getPorcentajeCumplimientoDotacion() >= 100) {
                $btnResumenGastos = $this->html->getBoton('CARGAR', "/resumen_gastos/?inst_id={$this->id_institucion}&proc_id={$this->id_proceso}");
            }

        }

        if (!empty($this->cumplimiento['detalle']['resumen_gasto']['url']) && !($this->getPorcentajeCumplimientoGastos() < 100)) {
            $url_certificado = $this->cumplimiento['detalle']['resumen_gasto']['url'];
            $botonDescargarCertificadoSecretario = $this->html->getBoton('Descargar', $url_certificado, array('target' => '_blank'));
            $btnResumenGastos="";
        } else {
            $botonDescargarCertificadoSecretario = (!empty($this->cumplimiento['detalle']['resumen_gasto']['url'])) ? 'Items por cargar' : 'Certificado por cargar.';
        }

        $html = <<<HTML
        <table width="100%" border="0" class="tabla-simple">
            <thead>
                <tr>
                  <th COLSPAN=3 align="left">
                    <span class="resumen_titulo">GASTOS ANUAL EN PERSONAL Y ALCALDE</span>
                  </th>
                  <th>
                    <span class="resumen_porcentaje {$this->atributos['style_porcentaje_resumen']}">{$this->getPorcentajeCumplimientoGastos()}%</span>
                  </th>
                </tr>
            </thead>
        <tbody>
            <tr>
                <td><strong>Remuneración Alcalde</strong></td>
                <td class="moneda"><div class="pdf-pesos">$</div> <div class="pdf-moneda"> {$resumen["planta_alcalde"]}</div></td>
                <td width="35%"><strong>Dotación Planta (Excluya Remuneración del Alcalde)</strong></td>
                <td width="15%" class="moneda"><div class="pdf-pesos">$</div> <div class="pdf-moneda">  {$resumen["planta"]}</div></td>
            </tr>
            <tr>
                <td width="35%"><strong>Personal a Contrata</strong></td>
                <td width="15%" class="moneda"> <div class="pdf-pesos">$</div> <div class="pdf-moneda"> {$resumen["contrata"]}</div></td>
                <td><strong>Jornales</strong></td>
                <td class="moneda"> <div class="pdf-pesos">$</div> <div class="pdf-moneda"> {$resumen["jornales"]}</div></td>
            </tr>
            <tr>
                <td><strong>Código del Trabajo</strong></td>
                <td class="moneda"> <div class="pdf-pesos">$</div> <div class="pdf-moneda"> {$resumen["codigo_trabajo"]}</div></td>
                <td><strong>Suplentes</strong></td>
                <td class="moneda"><div class="pdf-pesos">$</div> <div class="pdf-moneda">  {$resumen["calidad_suplente"]}</div></td>
            </tr>
            <tr>
                <td><strong>Reemplazo</strong></td>
                <td class="moneda"> <div class="pdf-pesos">$</div> <div class="pdf-moneda"> {$resumen["calidad_reemplazo"]}</div></td>
                <td><strong>Personal a trato y/o Temporal</strong></td>
                <td class="moneda"><div class="pdf-pesos">$</div> <div class="pdf-moneda">  {$resumen["trato_temporal"]}</div></td>
            </tr>
            <tr>
                <td><strong>Alumnos en Práctica</strong></td>
                <td class="moneda"> <div class="pdf-pesos">$</div> <div class="pdf-moneda"> {$resumen["alumnos"]}</div></td>
                <td><strong>Honorarios Fondos de Terceros</strong></td>
                <td class="moneda"> <div class="pdf-pesos">$</div> <div class="pdf-moneda"> {$resumen["honorario_fondo"]}</div></td>
            </tr>
HTML;

        if ($this->proceso->getVersion() >= 2) {
            $html .= <<<HTML
            <tr>
                <td><strong>Honorarios Fondos Municipales</strong></td>
                <td class="moneda"><div class="pdf-pesos">$</div> <div class="pdf-moneda">  {$resumen["honorario_municipal"]}</div></td>
                <td>&nbsp;</td>
                 <td>&nbsp;</td>
            </tr>
HTML;
        } else {
            $html .= <<<HTML
            <tr>
                <td><strong>Honorarios asimilados a Grado</strong></td>
                <td class="moneda"> <div class="pdf-pesos">$</div> <div class="pdf-moneda"> {$resumen["asimilado"]}</div></td>
                <td><strong>Honorarios a Suma Alzada</strong></td>
                <td class="moneda"><div class="pdf-pesos">$</div> <div class="pdf-moneda">  {$resumen["suma_alzada"]}</div></td>
            </tr>
            <tr>
                <td><strong>Honorarios a Programas</strong></td>
                <td class="moneda"><div class="pdf-pesos">$</div> <div class="pdf-moneda">  {$resumen["honorarios"]}</div></td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
            </tr>
            <tr>
                <th colspan="2"  align="left"><strong>Resumen del Gasto Total </strong></th>
                <th colspan="2" align="left">$  {$total_resumen} </th>

            </tr>
            <tr>
                <th colspan="2"  align="left"><strong>Certificado de resumen firmado de la municipalidad</strong></th>
                <th colspan="2" align="right">{$botonDescargarCertificadoSecretario}</th>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" align="right">{$btnResumenGastos} </td>
            </tr>
    </tfoot>
</table>

<br>
HTML;

        }

        $html .= <<<HTML
        <tr>
                <th colspan="2"  align="left"><strong>Resumen del Gasto Total </strong></th>
                <th colspan="2" align="left">$  {$total_resumen} </th>

            </tr>
            <tr>
                <th colspan="2"  align="left"><strong>Certificado de resumen firmado de la municipalidad</strong></th>
                <th colspan="2" align="right">{$botonDescargarCertificadoSecretario}</th>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" align="right">{$btnResumenGastos} </td>
            </tr>
    </tfoot>
</table>

<br>
HTML;
    return $html;

    }


    private function formularioPersonalMunicipal(){
        $version = "";
        $extension = "xlsx";
        if($this->proceso->getVersion() >= 3){
            $version="";
            $extension = "csv";
        }

        if ($this->proceso->getVersion() == 1) {
            $archivos_honorarios = <<<HTML
                    <tr>
                      <td>Honorarios</td>
                      <td align="center"><a href="/resumen_carga/documentos/Plantilla_honorarios_art_3.xlsx" target="_blank"><img src="/images/ico-documento2.png" /></a></td>
                      <td align="center"><a href="/resumen_carga/documentos/ " target="_blank"><img src="/images/ico-pdf.png" alt="" /></a></td>
                       <td>{$this->cumplimiento['detalle']['honorarios']['link']}</td>
                      <td>{$this->cumplimiento['detalle']['honorarios']['fecha_cargado']}</td>
                    </tr>
HTML;
         } else {
            $archivos_honorarios = <<<HTML
                    <td>Honorarios Fondos de Terceros</td>
                      <td align="center"><a href="/resumen_carga/documentos/{$version}Plantilla_Honorarios_Tercero_Art_3.{$extension}" target="_blank"><img src="/images/ico-documento2.png" /></a></td>
                      <td align="center"><a href="/resumen_carga/documentos/{$version}6.- D.D HONORARIOS FONDOS DE TERCEROS - PROCESO 2024.pdf" target="_blank"><img src="/images/ico-pdf.png" alt="" /></a></td>
                       <td>{$this->cumplimiento["detalle"]["honorarios_tercero"]["link"]}</td>
                      <td>{$this->cumplimiento['detalle']['honorarios_tercero']['fecha_cargado']}</td>
                    </tr>
                    <tr>
                      <td>Honorarios Fondos Municipales</td>
                      <td align="center"><a href="/resumen_carga/documentos/{$version}Plantilla_Honorarios_Municipal_Art_3.{$extension}" target="_blank"><img src="/images/ico-documento2.png" /></a></td>
                      <td align="center"><a href="/resumen_carga/documentos/{$version}5.- D.D HONORARIOS MUNICIPALES - PROCESO 2024.pdf" target="_blank"><img src="/images/ico-pdf.png" alt="" /></a></td>
                       <td>{$this->cumplimiento["detalle"]["honorarios_municipal"]["link"]}</td>
                      <td>{$this->cumplimiento["detalle"]["honorarios_municipal"]["fecha_cargado"]}</td>
                    </tr>
                    <tr>
HTML;
        }

        if (!empty($this->cumplimiento['detalle']['certificado_dotacion']['url'])) {
            $url_dotacion = $this->cumplimiento['detalle']['certificado_dotacion']['url'];
            $botonDescargarCertificadoDotacion = $this->html->getBoton('Descargar', $url_dotacion, array('target' => '_blank'));
        } else {
            $botonDescargarCertificadoDotacion = 'Certificado por cargar.';
        }

        #Si el documento no ha sido firmado se muestran los botones
        if (!$this->cargaFirmada()) {
            $btnArticulo3 = $btnResumenGastos = '';
            if ($this->getPorcentajeCumplimientoDotacion() < 100) {
                $btnArticulo3 = $this->html->getBoton('CARGAR', "/subir_archivos/articulo3/?inst_id={$this->id_institucion}&proc_id={$this->id_proceso}");
            }
        }

        $html = <<<HTML
<table  width="100%"  class="tabla-simple" id="tabla_articulo3">
    <thead>
        <tr>
            <th colspan="4" align="left"><span class="resumen_titulo">PERSONAL MUNICIPAL</span></th>
            <th><span class="resumen_porcentaje {$this->atributos['style_porcentaje_dotacion']}">{$this->getPorcentajeCumplimientoDotacion()}%</span></th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td width="30%"><h4>Nombre Archivo</h4></td>
            <td width="15%" align="center"><h4>Descargar Planilla</h4></td>
            <td width="15%" align="center"><h4>Diccionario</h4></td>
            <td width="20%"><h4>Archivo Cargado</h4></td>
            <td width="20%"><h4>Fecha de Ingreso</h4></td>
        </tr>
        <tr>
          <td>Dotación Planta</td>
          <td align="center"><a href="/resumen_carga/documentos/{$version}Plantilla_planta_art_3.{$extension}" target="_blank"><img src="/images/ico-documento2.png" /></a></td>
          <td align="center"><a href="/resumen_carga/documentos/1.- D.D DOTACION PLANTA - PROCESO 2024.pdf" target="_blank"><img src="/images/ico-pdf.png" alt="" /></a></td>
           <td>{$this->cumplimiento["detalle"]["dotacion_planta"]["link"]}</td>
          <td>{$this->cumplimiento["detalle"]["dotacion_planta"]["fecha_cargado"]}</td>
        </tr>
        <tr>
          <td>Personal a Contrata</td>
          <td align="center"><a href="/resumen_carga/documentos/{$version}Plantilla_Personal_Contrata.{$extension}" target="_blank"><img src="/images/ico-documento2.png" /></a></td>
          <td align="center"><a href="/resumen_carga/documentos/2.- D.D DOTACION CONTRATA - PROCESO 2024.pdf" target="_blank"><img src="/images/ico-pdf.png" alt="" /></a></td>
           <td>{$this->cumplimiento["detalle"]["dotacion_contrata"]["link"]}</td>
          <td>{$this->cumplimiento["detalle"]["dotacion_contrata"]["fecha_cargado"]}</td>
        </tr>
        <tr>
          <td>Jornales</td>
          <td align="center"><a href="/resumen_carga/documentos/{$version}Plantilla_Jornales_art_3.{$extension}" target="_blank"><img src="/images/ico-documento2.png" /></a></td>
          <td align="center"><a href="/resumen_carga/documentos/7.- D.D JORNALES - PROCESO 2024.pdf" target="_blank"><img src="/images/ico-pdf.png" alt="" /></a></td>
           <td>{$this->cumplimiento["detalle"]["jornales"]["link"]}</td>
          <td>{$this->cumplimiento["detalle"]["jornales"]["fecha_cargado"]}</td>
        </tr>
        <tr>
          <td>Código del Trabajo</td>
          <td align="center"><a href="/resumen_carga/documentos/{$version}Plantilla_Codigo_Del_trabajo_art_3.{$extension}" target="_blank"><img src="/images/ico-documento2.png" /></a></td>
          <td align="center"><a href="/resumen_carga/documentos/{$version}4.- D.D CODIGO DEL TRABAJO - PROCESO 2024.pdf" target="_blank"><img src="/images/ico-pdf.png" alt="" /></a></td>
          <td>{$this->cumplimiento["detalle"]["codigo_trabajo"]["link"]}</td>
          <td>{$this->cumplimiento["detalle"]["codigo_trabajo"]["fecha_cargado"]}</td>
        </tr>
        <tr>
          <td>Suplentes</td>
          <td align="center"><a href="/resumen_carga/documentos/{$version}Plantilla_suplentes_art_3.{$extension}" target="_blank"><img src="/images/ico-documento2.png" /></a></td>
          <td align="center"><a href="/resumen_carga/documentos/{$version}8.- D.D SUPLENTES - PROCESO 2024.pdf" target="_blank"><img src="/images/ico-pdf.png" alt="" /></a></td>
           <td>{$this->cumplimiento["detalle"]["suplentes"]["link"]}</td>
          <td>{$this->cumplimiento["detalle"]["suplentes"]["fecha_cargado"]}</td>
        </tr>
        <tr>
          <td>Reemplazo</td>
          <td align="center"><a href="/resumen_carga/documentos/{$version}Plantilla_reemplazo_art_3.{$extension}" target="_blank"><img src="/images/ico-documento2.png" /></a></td>
          <td align="center"><a href="/resumen_carga/documentos/{$version}9.- D.D REEMPLAZO - PROCESO 2024.pdf" target="_blank"><img src="/images/ico-pdf.png" alt="" /></a></td>
           <td>{$this->cumplimiento["detalle"]["reemplazo"]["link"]}</td>
          <td>{$this->cumplimiento["detalle"]["reemplazo"]["fecha_cargado"]}</td>
        </tr>
        <tr>
         <tr>
          <td>Personal a trato y/o Temporal</td>
          <td align="center"><a href="/resumen_carga/documentos/{$version}Plantilla_trato_temporal_art_3.{$extension}" target="_blank"><img src="/images/ico-documento2.png" /></a></td>
          <td align="center"><a href="/resumen_carga/documentos/{$version}10.- D.D TRATO O TEMPORAL - PROCESO 2024.pdf" target="_blank"><img src="/images/ico-pdf.png" alt="" /></a></td>
           <td>{$this->cumplimiento["detalle"]["trato_temporal"]["link"]}</td>
          <td>{$this->cumplimiento["detalle"]["trato_temporal"]["fecha_cargado"]}</td>
        </tr>
        <td>Alumnos en Práctica</td>
          <td align="center"><a href="/resumen_carga/documentos/{$version}Plantilla_practicantes_art_3_2023.{$extension}" target="_blank"><img src="/images/ico-documento2.png" /></a></td>
          <td align="center"><a href="/resumen_carga/documentos/{$version}11.- D.D ALUMNO EN PRACTICA - PROCESO 2024.pdf" target="_blank"><img src="/images/ico-pdf.png" alt="" /></a></td>
           <td>{$this->cumplimiento["detalle"]["practicantes"]["link"]}</td>
          <td>{$this->cumplimiento["detalle"]["practicantes"]["fecha_cargado"]}</td>
        </tr>
        {$archivos_honorarios}
        <tr>
          <td>Modificaciones de Planta</td>
          <td align="center"><a href="/resumen_carga/documentos/{$version}Plantilla_modifica_planta_art_3.{$extension}" target="_blank"><img src="/images/ico-documento2.png" /></a></td>
          <td align="center"><a href="/resumen_carga/documentos/{$version}3.- D.D MODIFICACIONES DE PLANTA - PROCESO 2024.pdf" target="_blank"><img src="/images/ico-pdf.png" alt="" /></a></td>
           <td>{$this->cumplimiento["detalle"]["modificaciones_planta"]["link"]}</td>
          <td>{$this->cumplimiento["detalle"]["modificaciones_planta"]["fecha_cargado"]}</td>
        </tr>
        <tr>
            <th colspan="3" align="left"><strong>Certificado de resumen firmado de la municipalidad</strong></th>
            <th colspan="2" align="right">{$botonDescargarCertificadoDotacion}</th>
        </tr>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5" align="right">
              $btnArticulo3
            </td>
        </tr>
    </tfoot>
</table>
HTML;

        return $html;
    }

    private function formularioEscalafonVigente(){
        $dotacionEscalafon = cumplimiento::buscar(array('proc_id' => $this->id_proceso, 'inst_id' => $this->id_institucion, 'cump_operacion' => 'E', 'cump_dd__distinto' => 'certificado_secretario'));
        if (!empty($dotacionEscalafon)) {
            $cumpl = new cumplimiento(reset($dotacionEscalafon), array('error_level' => false));
            $this->fecha_disposicion = formato::fechaCorta($cumpl->getFecha());
        } else {
            $this->fecha_disposicion = $this->cumplimiento['detalle']['fecha_disposicion'];
        }

        #Si el documento no ha sido firmado se muestran los botones
        $btnDescarga="";
        if (!$this->cargaFirmada()) {
            $btnEscalafonVigente = $this->html->getBoton('CARGAR', "/subir_archivos/escalafon_vigente/?inst_id={$this->id_institucion}&proc_id={$this->id_proceso}");
        }

        if(!empty($this->cumplimiento['detalle']["certificado_secretario"]["url"])){
            $btnDescarga = $this->html->getBoton('DESCARGAR', $this->cumplimiento['detalle']["certificado_secretario"]["url"], array('target' => '_blank'));
        }

        $version="";
        $extension="xlsx";
        if($this->proceso->getVersion() >= 3){
            $version="v3/";
            $extension = "csv";
        }

        $html = <<<HTML
    <table  width="100%" class="tabla-simple" id="tabla_escalafon">
    <thead>
        <tr>
          <th COLSPAN=4 align="left"><span class="resumen_titulo">ESCALAFÓN DE MÉRITO VIGENTE</span></th>
          <th><span class="resumen_porcentaje {$this->atributos['style_porcentaje_escalafon']}">{$this->getPorcentajeCumplimientoEscalafon()}%</span></th>
        </tr>
    </thead>
    <tbody>
        <tr>
          <td width="30%"><h4>Nombre Archivo</h4></td>
          <td width="15%" align="center"><h4>Descargar Planilla</h4></td>
          <td width="15%" align="center"><h4>Diccionario</h4></td>
          <td width="20%"><h4>Archivo Cargado</h4></td>
          <td width="20%"><h4>Fecha de Ingreso</h4></td>
        </tr>
        <tr>
          <td>Planta de Directivos</td>
          <td rowspan=7 align="center"><a href="/resumen_carga/documentos/{$version}Plantilla_Escalafon_art_3.{$extension}" target="_blank"><img src="/images/ico-documento2.png" /></a></td>
          <td rowspan=7 align="center"><a href="/resumen_carga/documentos/{$version}12.- D.D ESCALAFON DE MERITO - PROCESO 2024.pdf" target="_blank"><img src="/images/ico-pdf.png" alt="" /></a></td>
          <td>{$this->cumplimiento["detalle"]["escalafon_directivo"]["link"]}</td>
          <td>{$this->cumplimiento["detalle"]["escalafon_directivo"]["fecha_cargado"]}</td>
        </tr>
        <tr>
          <td>Planta de Profesionales</td>
          <td>{$this->cumplimiento["detalle"]["escalafon_profesional"]["link"]}</td>
          <td>{$this->cumplimiento["detalle"]["escalafon_profesional"]["fecha_cargado"]}</td>
        </tr>
        <tr>
          <td>Planta de Jefaturas</td>
          <td>{$this->cumplimiento["detalle"]["escalafon_jefaturas"]["link"]}</td>
          <td>{$this->cumplimiento["detalle"]["escalafon_jefaturas"]["fecha_cargado"]}</td>
        </tr>
        <tr>
          <td>Planta de Técnicos</td>
          <td>{$this->cumplimiento["detalle"]["escalafon_tecnicos"]["link"]}</td>
          <td>{$this->cumplimiento["detalle"]["escalafon_tecnicos"]["fecha_cargado"]}</td>
        </tr>
        <tr>
          <td>Planta de Administrativos</td>
          <td>{$this->cumplimiento["detalle"]["escalafon_administrativo"]["link"]}</td>
          <td>{$this->cumplimiento["detalle"]["escalafon_administrativo"]["fecha_cargado"]}</td>
        </tr>
        <tr>
          <td>Planta Auxiliares</td>
          <td>{$this->cumplimiento["detalle"]["escalafon_auxiliar"]["link"]}</td>
          <td>{$this->cumplimiento["detalle"]["escalafon_auxiliar"]["fecha_cargado"]}</td>
        </tr>        
    </tbody>
    <tfoot>
        <tr>        
              <th colspan="3"  align="left">Certificado oficio secretario municipal</th>
              <th  align="left">Fecha de Carga: {$this->cumplimiento['detalle']["certificado_secretario"]["fecha_cargado"]}</th>
              <th  align="right">{$btnDescarga}</th>
        </tr>
        <tr>
            <th colspan="3" align="left">
                Fecha puesta a disposición del Personal Municipal:
            </th>
            <th colspan="2" align="left">
               {$this->fecha_disposicion}
            </th>
        </tr>
        <tr>
            <td colspan="5" align="right">
              {$btnEscalafonVigente}
            </td>
        </tr>
    </tfoot>
</table>
<br/>
HTML;

        return $html;
    }

    #MANEJO DEL ARCHIVO DE POLITICAS RRHH
    private function formularioPoliticasRRHH(){
        $politica = new politica_rrhh($this->id_institucion, $this->id_proceso);
        $archivos_politicas = $politica->getPoliticasAnteriores();

        #Si no esta firmado aun se puede subir el archivo de Politicas RRHH
        if (!$this->cargaFirmada()) {
            $total_archivos = count($archivos_politicas);
            $titulo_rrhh = 'Subir Nuevo Archivo';

            $archivo_rrhh = <<<HTML
            <span class="mantenedor-campo-politicas_rrhh">
                <input type="file" onchange=";" onkeyup="" name="campo_politicas_rrhh" data-tipo="politicas" class="mantenedor_input_file" id="mantenedor_form_1_politicas_rrhh">
                <br>
                <span class="mantenedor_nota nota-pequena">Tamaño máximo del archivo: {$this->tam_maximo}</span>
            </span>
HTML;
            if ($total_archivos > 1) {
                $link_politicas_rrhh = $this->institucion->getPoliticaRrhh(true);

                $this->campos['utilizar_politica_rrhh'] = array(
                    'tipo' => 'lista',
                    'atributo' => 'utilizar_politica_rrhh',
                    'nombre' => 'Mantener archivo del proceso anterior',
                    'valores' => $archivos_politicas,
                    'default' => $this->institucion->getPoliticaRrhh(),
                );

                $html_politicas = "
                    <tr>
                        <td>{label:utilizar_politica_rrhh}</td>
                        <td>{campo:utilizar_politica_rrhh}</td>
                        <td align='right'><div id=\"link_politicas_rrhh\">{$link_politicas_rrhh}</div></td>
                    </tr>";
            }

        } else {
            $nombre_archivo_rrhh = array_keys($archivos_politicas);
            $archivo_rrhh = $nombre_archivo_rrhh[1];
            $url = URL_POLITICAS_RRHH.$nombre_archivo_rrhh[1];
            $archivo_rrhh_link =  $botonDescargarCertificado = $this->html->getBoton('Descargar', $url, array('target' => '_blank'));
            $titulo_rrhh = ($archivo_rrhh != '') ? '<b>Políticas de RRHH:</b>' : '';
            $html_politicas = $archivo_rrhh = '';
        }

        $html = <<<HTML
    <table width="100%" class="tabla-simple">
        <thead>
            <tr>
                <th colspan="2" align="left"><span class="resumen_titulo">POLÍTICAS RRHH </span></th>
                <th><span class="resumen_porcentaje {$this->atributos['style_porcentaje_politicas_rrhh']}">{$this->cumplimiento['politicas_rrhh']['cumplimiento']}%</span></th>
            </tr>
        </thead>
        <tbody>
        {$html_politicas}
        <tr>
            <td width="20%"><label for="mantenedor_form_1_politicas_rrhh"></label>{$titulo_rrhh}</td>
            <td width="50%" >{$archivo_rrhh}</td>
            <td width="30%">
                <div id="mantenedor_form_1_politicas_rrhh_div" style="text-align: right;">{$archivo_rrhh_link}</div>
            </td>
        </tr>
    </tbody>
    </table>
    <br/>
HTML;

        return $html;
    }


    function getFormularioFirma($html){
        /* Habilita la visibilidad del formulario para firmar */
        $boton_enviar = "{boton:enviar}";
        if ($this->getPorcentajeCumplimiento() >= 100 || $this->perfil->getRolId() == ROL_OPERADOR) { //Si el porcentaje de coumplimiento es 100% y no se ha firmado el proceso
            if ($this->cargaFirmada() || $this->perfil->getRolId() == ROL_OPERADOR) {
                $firma_proceso = firmas::buscar(array('proc_id' => $this->id_proceso, 'inst_id' => $this->id_institucion));
                $firmas = new firmas(reset($firma_proceso));
                $this->campos['rut']['default'] = $firmas->getRut();
                $this->campos['nombre']['default'] = $firmas->getNombre();
                
                if (!$this->cargaFirmada() && $this->perfil->getRolId() == ROL_OPERADOR) {
                $this->campos['password']['default'] = '';
            }
                $this->campos['cargo']['default'] = $firmas->getCargo();
                $this->campos['correo']['default'] = $firmas->getCorreo();
                $this->campos['telefono']['default'] = $firmas->getTelefono();
                //$campos['codigo']['default'] = $firmas->getPaisId();
                $this->campos['area']['default'] = $firmas->getCodigoArea();
                $boton_enviar = "";
                debug($firmas);
                foreach ($this->campos as $key => $valor) {
                    $this->campos[$key]['noEdita'] = true;
                }
            }


        $html .= <<<HTML
        <div>
            <div id="contenedor_campo_password">
                <table  width="100%"  class="tabla-simple">
                    <thead>
                        <tr>
                          <th COLSPAN=4 align="left"><span class="resumen_titulo">ENCARGADO</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td width="15%">{label:rut}</td>
                            <td width="35%">{campo:rut}</td>
                            <td width="15%">{label:nombre}</td>
                            <td width="35%">{campo:nombre}</td>
                        </tr>
                        <tr>
                            <td>{label:cargo}</td>
                            <td>{campo:cargo}</td>
                            <td>{label:correo}</td>
                            <td>{campo:correo}</td>
                        </tr>
                        <tr>
                            <td>{label:telefono}</td>
                            <td>{campo:codigo} {campo:area} {campo:telefono}</td>
                            <td>{label:password}</td>
                            <td>{campo:password}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr align="center">
                            <td colspan="4">{$boton_enviar}</td>
                        </tr>
                    </tfoot>
                </table>
                {campo:institucion}
                {campo:proceso}
            </div>
        </div>
        <br>
HTML;

            /* Muestra la opcion para cargar el certificado firmado */
            $botonCertificado = $certificado_firmado = "";

            $file_name = "Firmado_" . md5(($this->id_proceso) + ($this->id_institucion)) . "_" . $this->id_proceso . "_" . $this->id_institucion . ".pdf";
            if ($this->cargaFirmada() || $this->perfil->getRolId() == ROL_OPERADOR) {
                if ($this->cumplimiento['detalle']['certificado_firmado'] != '') {

                    $url = $this->cumplimiento['detalle']['certificado_firmado'];
                    $botonDescargarCertificado = $this->html->getBoton('Descargar', $url, array('target' => '_blank'));
                   $certificado_firmado = '<td width="50%">' . $this->cumplimiento['detalle']['certificado_nombre'] . '</td><td width="30%" align="right">
                    <div id="mantenedor_form_1_certificado_div">' . $botonDescargarCertificado . '</div>
                </td>';
                    $this->atributos['style_porcentaje_certificado'] = 'verde';
                    $porcentajeCertificado = '100';
                    
                    //$botonCertificado = $this->html->getBoton('Ver Certificado', "/certificado/?proc_id={$this->id_proceso}&inst_id={$this->id_institucion}", array()); // Se agrega paso 3
                } else if ($this->cargaFirmada() || $this->perfil->getRolId() != ROL_OPERADOR) {
                    $botonCertificado = $this->html->getBoton('Ver Certificado', "/certificado/?proc_id={$this->id_proceso}&inst_id={$this->id_institucion}", array());
                    $certificado_firmado = '<td width="50%"><span class="mantenedor-campo-politicas_rrhh">
                        <input type="file" onchange=";" onkeyup="" name="campo_certificado"  data-tipo="certificado" class="mantenedor_input_file" id="mantenedor_form_1_certificado">
                        <br>
                        <span class="mantenedor_nota nota-pequena">Tamaño máximo del archivo: ' . $this->tam_maximo . '</span>
                    </span></td><td width="30%" align="right">
                    <div id="mantenedor_form_1_certificado_div">' . $botonCertificado . '</div>
                </td>';
                    $porcentajeCertificado = '0';
                    $this->atributos['style_porcentaje_certificado'] = 'rojo';
                } else {
                    $certificado_firmado = '<td colspan="2"><div id="aviso-amarillo">
                    <div align="justify"><strong>El Certificado Final debe ser firmado por el Secretario Municipal, y cargado en el sistema para dar por cerrado el Proceso satisfactoriamente</strong></div>
                </div></td>';
                    $botonCertificado = "";
                    $porcentajeCertificado = '0';
                    $this->atributos['style_porcentaje_certificado'] = 'rojo';
                }

                $html .= <<<HTML
            <br/>
            <table width="100%"  class="tabla-simple">
                <thead>
                    <tr>
                        <th colspan="2" align="left"><span class="resumen_titulo">CERTIFICADO</span></th>
                        <th><span class="resumen_porcentaje {$this->atributos['style_porcentaje_certificado']}">{$porcentajeCertificado}%</span></th>
                    </tr>
                </thead>
                <tbody>
                <tr>
                            <td width="20%"><label for="mantenedor_form_1_certificado">Certificado</label></td>
                            {$certificado_firmado}
                        </tr>
                </tbody>
            </table>
HTML;
            }

            if (empty($botonCertificado)) {
                $botonCertificado = "";
            }
        

        $botonVolver = $this->html->getBoton('Volver', '/seleccionar_proceso', array());
        $html .= <<<HTML
            <div>
                <div style="text-align: center; margin:5px;">{$botonVolver}{$botonCertificado}</div>
            </div>
HTML;
        $mantencion = array(
            'url' => '/resumen_carga/action.php',
            'campos' => $this->campos,
        );

        echo mantenedor::generar($mantencion, $html, "Enviar");
    }
}


    private function getPopup(){
        return <<<HTML
        <link href="/css/popup.css" rel="stylesheet" type="text/css"/>
        <style>
            .grave{
                color: red;
            }
        </style>
        <!-- Popup -->
        <div class="sinim_popup" data-popup="popup-1">
            <div class="sinim_popup-inner">
                <div>
                    <div style="width:80%;float:left;">
                        <h2>Errores encontrados</h2>
                    </div>
                    <div style="width:20%;float:left;">
                         <span id="div_btn_2" class="yui-button yui-link-button">
                                <span class="first-child">
                                        <a data-popup-close="popup-1" href="#">Cerrar Ventana</a>
                                </span>
                            </span>
                    </div>
                </div>
                <br/><br/><br/><br/><br/>
                <div class="sinim_popup-content" id="lista_errores">
                    <!-- Aqui va la tabla con los errores encontrados -->
                </div>
                <a class="sinim_popup-close" data-popup-close="popup-1" href="#">x</a>
            </div>
        </div>
        <!-- Popup -->
HTML;

    }

    private function getJavaScript(){
        return <<<JAVASCRIPT

<script>
    $(document).ready(function () {
        if ($('#debug').val() == 1) {
            console.log("Está activado el DEBUG")
        }
        

       $('[data-popup-close]').on('click', function (e) {
            var targeted_popup_class = jQuery(this).attr('data-popup-close');
            $('[data-popup="' + targeted_popup_class + '"]').fadeOut(350);

            e.preventDefault();
        });
       
         $("#tabla_articulo3").on("click", "a.btn",function(e){
            var targeted_popup_class = jQuery(this).attr('data-popup-open');
            var proceso = $(this).data('proceso');            
            var log = $(this).data('log');

            $('[data-popup="' + targeted_popup_class + '"]').fadeIn(350);
            showErrores("/subir_archivos/articulo3/lista_errores.php", proceso, log);
            e.preventDefault();
        });
         
         
         $("#tabla_escalafon").on("click", "a.btn",function(e){
            var targeted_popup_class = jQuery(this).attr('data-popup-open');
            var proceso = $(this).data('proceso');
            var log = $(this).data('log');
            
            $('[data-popup="' + targeted_popup_class + '"]').fadeIn(350);            
            showErrores("/subir_archivos/lista_errores.php", proceso, log);

            e.preventDefault();
        });
               
         
         function showErrores(url, proceso, log) {
            var institucion = $("#institucion").val();
            $.ajax({
                url: url,
                type: "GET",
                data: {proceso: proceso, log: log, institucion: institucion}
            })
            .done(function (request) {
                $("#lista_errores").html(request);
            });
         }
         
        function MostrarErrores(e){
            $('[data-popup="popup-1"]').fadeIn(350);
            $("#lista_errores").html(e);
        }
        
        function subirArchivo(archivo) {
            var extension = archivo.val().substring(archivo.val().lastIndexOf("."));
            if (extension.toLowerCase() != ".pdf") {
                alert("Tipo de archivo no válido, sólo se permiten archivos PDF");
                archivo.val("");
                archivo.replaceWith(archivo.val('').clone(true));
                return;
            }
            var id = archivo.attr('id');
            var url = archivo.data('tipo');
            var proceso = $("#proceso").val();
            var institucion = $("#institucion").val();
            var formData = new FormData();

            var file = archivo.prop('files')[0];
            formData.append("file", file);
            formData.append("proceso", proceso);
            formData.append("institucion", institucion);

            $("#" + id).attr('disabled', true);
            var spam = "#" + id + "_div";
            $(spam).html("Subiendo el archivo....");
            $(document.body).css('cursor', 'progress');

            try {
                $.ajax({
                    url: "upload." + url + ".php",
                    type: "POST",
                    data: formData,
                    dataType: "json",
                    processData: false,
                    contentType: false
                })
                    .done(function (request) {
                        $(spam).html(request.mensaje);
                        archivo.attr('disabled', false);
                        alert("Archivo subido satisfactoriamente");
                        //if (url == "certificado") {
                            location.reload();
                        //}
                    })
                    .fail(function (jqXHR, textStatus, errorThrown) {
                        console.log(jqXHR)
                        if (jqXHR.responseJSON != undefined) {
                            var data = jqXHR.responseJSON;
                            error = data.mensaje;
                        } else {
                            //console.log(jqXHR.responseText);
                            if ($('#debug').val() == 1) {
                                error = jqXHR.responseText;
                            }
                            error = "Error al subir el archivo ...";
                        }
                        $(spam).html(error);
                        alert(error);
                        $("#mantenedor_form_1_certificado").attr('disabled', '');
                    })
                    .always(function () {
                        archivo.val("");
                        archivo.replaceWith(archivo.val('').clone(true));
                        archivo.attr('disabled', false);
                        $(document.body).css('cursor', 'default');
                    });
            } catch (e) {
                if ($('#debug').val() == 1) {
                    console.log(e);
                }
                alert("Error al subir el archivo!");
                $(spam).html("Error al subir el archivo!");
                archivo.attr('disabled', false);
                archivo.val("");
                archivo.replaceWith(archivo.val('').clone(true));
                $(document.body).attr('cursor', 'default');
            }
        }


        $(".mantenedor_input_file").on('change', function () {
            subirArchivo($(this));
        });

        $("#mantenedor_form_1_utilizar_politica_rrhh").on('change', function () {
            var politica = $("#politica_rrhh_actual").html();
            if(politica  != 'undefined') {
                var r = $(this).val();
                var institucion = $("#institucion").val();

                $("#mantenedor_form_1_politicas_rrhh").prop( "disabled", r != "\tNULL");
                try {
                    $.ajax({
                        url: "update.politicas.php",
                        type: "POST",
                        data: {r: r, inst_id: institucion},
                        dataType: "json"
                    })
                        .done(function (request) {
                            $("#link_politicas_rrhh").html(request);
                        })
                        .fail(function (jqXHR, textStatus, errorThrown) {
                            console.log(jqXHR);
                            $("#link_politicas_rrhh").html(jqXHR.responseText);
                        });
                } catch (e) {
                    $("#link_politicas_rrhh").html("Error al actualizar el archivo");
                }
            }
        });

    });
</script>
JAVASCRIPT;

    }

}

$carga = new resumen_carga();
$carga->run();