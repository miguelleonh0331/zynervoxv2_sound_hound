<?php
/* ZYNERVOX: pagina de prueba para descartar la carrera de registro.
   Registra el webphone PRIMERO (SIP.js) y solo permite entrar al login real
   (zynervox.php) una vez que el registro esta confirmado -- asi el Originate
   del login encuentra el contacto YA existente, en vez de originar al mismo
   tiempo que el navegador todavia esta negociando el WebSocket. */
$agent_user = isset($_GET['user']) ? preg_replace('/[^A-Za-z0-9_]/','',$_GET['user']) : 'PLUSER001';
$agent_pass = isset($_GET['pass']) ? preg_replace('/[^A-Za-z0-9_]/','',$_GET['pass']) : 'PLUSER001';
$campaign   = isset($_GET['campaign']) ? preg_replace('/[^A-Za-z0-9_]/','',$_GET['campaign']) : 'PLBASE';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Zynervox - Pre-sesion webphone</title>
<style>
	body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
		font-family:Arial, Helvetica, sans-serif; background:#0e1420; }
	.card { width:360px; background:#fff; border-radius:14px; padding:32px; text-align:center;
		box-shadow:0 25px 60px rgba(0,0,0,.4); }
	.dot { width:12px; height:12px; border-radius:50%; background:#9fb3cc; display:inline-block; margin-right:8px; }
	#status { font-weight:bold; font-size:15px; margin-bottom:18px; }
	#entrar { display:inline-block; margin-top:10px; padding:12px 28px; border:0; border-radius:8px;
		background:#9fb3cc; color:#fff; font-weight:bold; font-size:14px; cursor:not-allowed; text-decoration:none; }
	#entrar.ready { background:#f0873a; cursor:pointer; }
	pre { text-align:left; max-height:220px; overflow:auto; background:#0e2740; color:#9fdcd4;
		font-size:11px; padding:10px; border-radius:6px; margin-top:16px; }
</style>
</head>
<body>
	<div class="card">
		<h3 style="margin-top:0;">Pre-sesion: <?php echo htmlspecialchars($agent_user); ?></h3>
		<div><span class="dot" id="dot"></span><span id="status">Conectando...</span></div>
		<a href="#" id="entrar">Entrar (esperando registro)</a>
		<pre id="log"></pre>
	</div>

<script>
window.ZV_WEBPHONE_CONFIG = {
	user: <?php echo json_encode($agent_user); ?>,
	password: <?php echo json_encode($agent_pass); ?>,
	domain: "demo.zynervox.site",
	wssUrl: "wss://demo.zynervox.site:8089/ws",
	displayName: <?php echo json_encode($agent_user); ?>,
	stunServer: "stun:stun.l.google.com:19302"
};
var ZV_CAMPAIGN = <?php echo json_encode($campaign); ?>;

/* alias minimos para que webphone.js (pensado para el sidebar) escriba aca */
document.getElementById = (function(orig){
	return function(id){
		if (id === 'zv-phone-log') return document.getElementById('log') || orig.call(document,'log');
		if (id === 'zv-phone-status') return document.getElementById('status');
		if (id === 'zv-phone-dot') return document.getElementById('dot');
		if (id === 'zv-phone-audio') {
			var a = orig.call(document,'zv-phone-audio-real');
			if (!a) { a = document.createElement('audio'); a.id='zv-phone-audio-real'; a.autoplay=true; a.playsInline=true; document.body.appendChild(a); }
			return a;
		}
		return orig.call(document, id);
	};
})(document.getElementById);
</script>
<script src="assets/js/sip.min.js"></script>
<script src="assets/js/webphone.js?v=1"></script>
<script>
var btn = document.getElementById('entrar');
var lastStatus = '';
setInterval(function(){
	var s = document.getElementById('status').textContent;
	if (s !== lastStatus) {
		lastStatus = s;
		if (/registrado/i.test(s)) {
			btn.classList.add('ready');
			btn.textContent = 'Entrar ahora';
		} else {
			btn.classList.remove('ready');
			btn.textContent = 'Entrar (esperando registro)';
		}
	}
}, 300);

btn.addEventListener('click', function(e){
	e.preventDefault();
	if (!btn.classList.contains('ready')) { return; }
	var cfg = window.ZV_WEBPHONE_CONFIG;
	var url = 'zynervox.php?VD_login=' + encodeURIComponent(cfg.user)
		+ '&VD_pass=' + encodeURIComponent(cfg.password)
		+ '&VD_campaign=' + encodeURIComponent(ZV_CAMPAIGN)
		+ '&phone_login=' + encodeURIComponent(cfg.user)
		+ '&phone_pass=' + encodeURIComponent(cfg.password)
		+ '&hide_relogin_fields=YES';
	window.location.href = url;
});
</script>
</body>
</html>
