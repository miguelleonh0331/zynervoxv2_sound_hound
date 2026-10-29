<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
ivr_builder_require_login();
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>IVR Builder - Zynervox</title>
<link rel="stylesheet" href="../modules/admin/layout.css">
<link rel="stylesheet" href="assets/css/ivr-builder.css"></head><body>
<?php require_once __DIR__ . '/../modules/admin/sidebar.php'; renderSidebar('ivr_builder', '../'); ?>
<div class="ivr-shell">
<header class="builder-toolbar"><label class="toolbar-field flow-picker">IVR <select id="flowCode" onchange="openFlow(this.value)"></select></label><button onclick="newFlow()">Nuevo</button><button onclick="cloneFlow()">Clonar</button><button class="danger" onclick="deleteFlow()">Eliminar</button><input id="flowName" placeholder="Nombre flujo"><label class="toolbar-field list-picker">Lista <select id="flowList" onchange="onListChange()"><option value="">(ninguna)</option></select></label><label class="toolbar-field start-picker">Inicio <select id="start"></select></label><button onclick="save()">Guardar y publicar</button><button onclick="openTemplatePicker()">Cargar plantilla</button><input id="templateFile" type="file" accept=".txt,text/plain" hidden><button onclick="location.href='results.php'">Resultados</button><input id="testPhone" placeholder="Telefono prueba" maxlength="11"><button onclick="launchCall()">Lanzar 2006</button><button onclick="openConfigPanel()" title="Configuración de servidores">⚙️</button><span id="status"></span></header>
<div id="configOverlay" class="config-overlay" hidden onclick="if(event.target===this)closeConfigPanel()">
<div class="config-panel">
<header><strong>Configuración de servidores</strong><button class="config-close" onclick="closeConfigPanel()">✕</button></header>
<section><h3>Base de datos (flujos IVR)</h3>
<p class="small">La conexión a base de datos ahora se gestiona en <a href="../modules/admin/services/database.php">Servicios &gt; Base de datos</a> (config compartida por todo el sistema telefónico, pensada para cluster).</p>
</section>
<section><h3>Servidor Asterisk destino</h3>
<label>URL base del API<input id="cfgAstUrl" placeholder="http://209.17.220.5/zynervoxv2205/ivr_builder"></label>
<label>Token<input id="cfgAstToken" type="password" placeholder="••••••••"></label>
<div class="config-actions"><button onclick="testAsteriskConfig()">Test</button><button onclick="saveAsteriskConfig()">Guardar</button><span id="cfgAstStatus" class="small"></span></div>
</section>
</div>
</div>
<div class="app"><aside class="side"><strong>Arrastrar bloques</strong>
<section class="template-variables" aria-live="polite"><strong>Variables disponibles</strong><p id="templateName" class="small">Carga una plantilla TXT o selecciona una lista para ver sus variables.</p><div id="templateVariables" class="variable-list"><span class="variable-empty">Sin plantilla</span></div></section>
<div class="palette" draggable="true" data-type="noop">Evento / Bienvenida</div><div class="palette" draggable="true" data-type="create_audio">Crear audio - Marcelo IA</div><div class="palette" draggable="true" data-type="create_audio_dynamic">Crear audio dinámico - Marcelo IA</div><div class="palette" draggable="true" data-type="create_audio_composite">Crear audio compuesto (frases)</div><div class="palette" draggable="true" data-type="amd">Detección buzón (AMD)</div><div class="palette" draggable="true" data-type="capture_stt">Capturar STT</div><div class="palette" draggable="true" data-type="decision_range">Decisión por rango numérico</div><div class="palette" draggable="true" data-type="bridge">Puente Conversacional</div><div class="palette" draggable="true" data-type="execute">Ejecutar URL</div><div class="palette" draggable="true" data-type="inject_sql">Inject SQL</div><div class="palette" draggable="true" data-type="playback">Audio existente</div><div class="palette" draggable="true" data-type="menu">Condicional IF / Menú</div><div class="palette" draggable="true" data-type="hangup">Finalizar</div><div class="palette" draggable="true" data-type="reenviar">Reenviar a anexo</div><div class="palette" draggable="true" data-type="menu_ari" style="border-color:#3a6ea6">🔀 Menú STT (doble canal)</div><div class="palette" draggable="true" data-type="capture_stt_ari" style="border-color:#3a6ea6">🔀 Capturar STT (doble canal)</div><div class="palette" draggable="true" data-type="amd_ari" style="border-color:#3a6ea6">🔀 Detección buzón (doble canal)</div>
<p class="help"><b>Conectar:</b><br>1. Arrastre desde salida azul, amarilla o roja.<br>2. Suelte sobre entrada verde del destino.<br><br><b>Eliminar flecha:</b><br>Click sobre línea o use panel lateral.</p></aside>
<main class="canvas" id="canvas"><div class="world" id="world"><svg id="svg"></svg><div id="nodes"></div></div></main>
<div class="resizer" id="resizer" title="Arrastra para agrandar/achicar el panel de propiedades"></div>
<aside class="side right"><strong>Propiedades</strong><div id="props" class="small">Seleccione nodo.</div></aside></div>
</div><!-- /.ivr-shell -->
<script src="assets/js/editor/core.js"></script>
<script src="assets/js/api/client.js"></script>
<script src="assets/js/nodes/media.js"></script>
<script src="assets/js/nodes/inject-sql.js"></script>
<script src="assets/js/nodes/capture-stt.js"></script>
<script src="assets/js/nodes/composite.js"></script>
<script src="assets/js/nodes/amd.js"></script>
<script src="assets/js/nodes/ari.js"></script>
<script src="assets/js/editor/panel-resizer.js"></script>
<script src="assets/js/editor/template-variables.js"></script>
<script src="assets/js/editor/config-panel.js"></script></body></html>
