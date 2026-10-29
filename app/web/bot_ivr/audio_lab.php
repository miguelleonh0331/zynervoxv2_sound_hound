<?php
declare(strict_types=1);
require __DIR__.'/campaigns_page.php';
require __DIR__.'/audio_lab_service.php';

if (($_GET['action'] ?? '') === 'audio') {
    try {
        $path = bot_audio_lab_file((string)($_GET['id'] ?? ''));
        session_write_close();
        header('Content-Type: audio/wav');
        header('Content-Length: '.filesize($path));
        header('Content-Disposition: inline; filename="zynervox-prueba.wav"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
    } catch (Throwable $e) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Audio no disponible en esta sesión.';
    }
    exit;
}
$text = '';
$provider = 'gtts';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'generate_audio') {
    try {
        bot_campaign_csrf();
        $text = (string)($_POST['audio_text'] ?? '');
        $provider = (string)($_POST['provider'] ?? '');
        set_time_limit($provider === 'rga' ? 190 : 100);
        bot_audio_lab_generate($text, $provider);
        bot_campaign_redirect('audio_lab.php', 'Audio creado. Puedes escucharlo abajo.');
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
bot_campaign_header('Prueba de creación de audios');
?>
<style>
.audio-lab{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px}.audio-lab textarea{min-height:160px;resize:vertical}
.audio-lab audio{width:100%;margin:8px 0}.audio-example{font-size:12px;color:var(--text-muted);line-height:1.5}.audio-result{padding:12px 0;border-bottom:1px solid var(--border)}.audio-result:last-child{border:0}.audio-result p{white-space:pre-wrap;overflow-wrap:anywhere;font-size:13px}
@media(max-width:900px){.audio-lab{grid-template-columns:minmax(0,1fr)}}
</style>
<div class="audio-lab">
<section class="carsa-card"><h2>Crear audio desde texto</h2>
<form method="post" class="carsa-form" id="audioLabForm">
<input type="hidden" name="action" value="generate_audio"><?php bot_campaign_token(); ?>
<div class="carsa-field"><label for="audio_provider">Proveedor TTS</label><select name="provider" id="audio_provider" required>
<?php foreach (bot_audio_lab_providers() as $key=>$label): ?><option value="<?php echo h($key); ?>" <?php echo $provider === $key ? 'selected' : ''; ?>><?php echo h($label); ?></option><?php endforeach; ?>
</select></div>
<div class="carsa-field"><label for="audio_text">Texto para pronunciar</label><textarea id="audio_text" name="audio_text" maxlength="1000" required placeholder="Hola, esta es una prueba de audio de Zynervox."><?php echo h($text); ?></textarea></div>
<p class="audio-example">Español · Velocidad de voz 1.3 · Máximo 1000 caracteres.<br>Escribe el texto tal como quieres escucharlo.</p>
<button class="carsa-btn" id="createAudio">Crear audio</button><span id="audioBusy" class="audio-example" hidden>Generando audio…</span>
</form></section>
<section class="carsa-card"><h2>Escuchar audios de prueba</h2>
<?php $history = array_reverse($_SESSION['bot_audio_lab'] ?? [], true); ?>
<?php if (!$history): ?><p class="audio-example">Los audios aparecerán aquí después de crearlos.</p><?php endif; ?>
<?php foreach ($history as $id=>$item): ?>
<article class="audio-result"><strong><?php echo h($item['created_at']); ?></strong><div class="audio-example"><?php echo h(bot_audio_lab_providers()[$item['provider']] ?? $item['provider']); ?></div><p><?php echo h($item['text']); ?></p>
<audio controls preload="none" src="audio_lab.php?action=audio&amp;id=<?php echo h($id); ?>">Tu navegador no permite reproducir audio.</audio>
<a class="carsa-btn secondary" href="audio_lab.php?action=audio&amp;id=<?php echo h($id); ?>" download="zynervox-prueba.wav">Descargar WAV</a></article>
<?php endforeach; ?>
<p class="audio-example">Se conservan los últimos cinco audios de esta sesión de prueba.</p>
</section></div>
<script>document.getElementById('audioLabForm').addEventListener('submit',()=>{document.getElementById('createAudio').disabled=true;document.getElementById('audioBusy').hidden=false;});</script>
<?php bot_campaign_footer(); ?>
