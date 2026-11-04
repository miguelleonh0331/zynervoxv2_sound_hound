// Puente AudioSocket (Asterisk) -> zypad (WebSocket). Node 22, sin dependencias.
// Escucha en 127.0.0.1:8765, manda el audio previo al answer a zypad (pcm16 8 kHz), detecta las palabras del catalogo
// en los parciales y, con la palabra antes del answer, ejecuta un programa de Telefonia: cortar_llamada.sh (UUID c07e) o anotar_palabra.sh (resto).
// Aislamiento: proceso aparte; cualquier fallo (zypad, catalogo, disco, corte) solo se registra y la llamada sigue.
import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';
import { execFile } from 'node:child_process';
import { performance } from 'node:perf_hooks';
import { loadConfig } from './lib/config.mjs';
import { createCatalog } from './lib/catalog.mjs';
import { frameParser, hex2uuid, T_AUDIO, T_HANGUP, T_UUID } from './lib/frames.mjs';

const MAX_BUF = 16000 * 10;      // 10 s de audio esperando al WS
const MAX_WS_BACKLOG = 1 << 20;  // si zypad no drena, se descarta
const FINAL_WAIT_MS = 8000;
const SCRIPT_TIMEOUT_MS = 3000;

const cfg = loadConfig();
const cutRe = new RegExp(cfg.cutUuidRe);
try { fs.mkdirSync(cfg.logDir, { recursive: true, mode: 0o700 }); } catch (e) { console.error('logdir', e.message); }
const catalog = createCatalog({ cmd: cfg.catalogCmd, file: cfg.catalogFile, cache: cfg.catalogCache, everyMs: cfg.catalogEveryMs, log: o => console.log(JSON.stringify(o)) });

function handle(sock) {
  sock.on('error', () => {});
  const parse = frameParser();
  let callId = null, ws = null, wsOpen = false, wsDead = false;
  const cum = [];
  let pending = [], pendingBytes = 0, sent = 0, recvB = 0, frames = 0, dropped = 0;
  let t0 = null, tFirstAudio = null, tWsOpen = null, tEnded = null, lastText = '', finalMsg = null, ended = false, logFd = null, wsErr = null;
  let hit = null, postHit = null, scriptState = null, done = false;
  const rel = () => +(performance.now() - t0).toFixed(1);
  const log = o => { if (logFd !== null) { try { fs.writeSync(logFd, JSON.stringify(o) + '\n'); } catch {} } };

  // Pide a un programa de Telefonia que corte (UUID c07e) o anote la palabra (sin marca). Una vez por llamada, sin bloquear nada.
  function runScript(kind, cmd, word, audio_s) {
    const msEpoch = Date.now(), tOrder = rel();
    log({ ev: `${kind}_order`, word, order_utc: new Date(msEpoch).toISOString(), t_ms: tOrder });
    if (!cmd) { scriptState = 'SIN_PROGRAMA'; return; }
    try {
      execFile(cmd, [callId, word, String(msEpoch), String(Math.round(audio_s * 1000))], { timeout: SCRIPT_TIMEOUT_MS }, (err, so) => {
        const out = String(so || '').trim();
        try { scriptState = JSON.parse(out).estado || 'DESCONOCIDO'; } catch { scriptState = err ? 'ERROR' : 'DESCONOCIDO'; }
        log({ ev: `${kind}_result`, ok: !err, code: err ? (err.code ?? err.signal ?? 'err') : 0, estado: scriptState, end_utc: new Date().toISOString(), ms: +(rel() - tOrder).toFixed(1), out: out.slice(0, 300) });
      });
    } catch (e) { scriptState = 'ERROR'; log({ ev: `${kind}_result`, ok: false, code: String(e.message || e) }); }
  }

  function onText(m) {
    if (m.text === lastText) return;
    lastText = m.text;
    const t = rel(), audio_s = +((cum[m.chunk - 1] || 0) / 16000).toFixed(3);
    log({ ev: 'text', chunk: m.chunk, t_ms: t, audio_s, text: m.text });
    if (!m.text || hit || postHit) return;
    const found = catalog.find(m.text);
    if (!found) return;
    const h = { word: found.word, category: found.category, t_ms: t, audio_s, wall_utc: new Date().toISOString() };
    if (ended) { postHit = h; log({ ev: 'word_post', ...h }); return; }   // despues del answer: no cuenta como pre-answer
    hit = h; log({ ev: 'word_pre', ...h });
    if (cutRe.test(callId)) runScript('cut', cfg.cutCmd, found.word, audio_s);
    else runScript('annotate', cfg.annotateCmd, found.word, audio_s);
  }

  function openWs() {
    try {
      ws = new WebSocket(`${cfg.zypadBase}?apikey=${encodeURIComponent(cfg.apiKey)}&format=pcm16&call_id=${encodeURIComponent(callId)}`);
      ws.binaryType = 'arraybuffer';
      ws.onopen = () => { wsOpen = true; tWsOpen = rel(); log({ ev: 'ws_open', t_ms: tWsOpen }); for (const b of pending) sendWs(b); pending = []; pendingBytes = 0; if (ended) endStream(); };
      ws.onmessage = e => {
        let m; try { m = JSON.parse(e.data); } catch { return; }
        try {
          if (m.type === 'partial') onText(m);
          else if (m.type === 'final') { finalMsg = m; log({ ev: 'final', t_ms: rel(), bytes_total: m.bytes_total, chunks_total: m.chunks_total, duration_s: m.duration_s, text: m.text }); finish(); }
        } catch (err) { log({ ev: 'error', where: 'onmessage', err: String(err.message || err) }); }
      };
      ws.onerror = e => { wsErr = e.message || 'ws_error'; wsDead = true; log({ ev: 'ws_error', t_ms: rel(), err: wsErr }); };
      ws.onclose = () => { wsDead = true; wsOpen = false; finish(); };
    } catch (e) { wsDead = true; wsErr = String(e.message || e); }
  }
  function sendWs(b) { try { if (ws.bufferedAmount > MAX_WS_BACKLOG) { dropped += b.length; return; } ws.send(b); sent += b.length; cum.push(sent); } catch { dropped += b.length; wsDead = true; } }
  function audio(b) {
    if (tFirstAudio === null) { tFirstAudio = rel(); log({ ev: 'first_audio', t_ms: tFirstAudio, wall_utc: new Date().toISOString() }); }
    frames++; recvB += b.length;
    if (wsDead) { dropped += b.length; return; }
    if (wsOpen) return sendWs(b);
    if (pendingBytes + b.length <= MAX_BUF) { pending.push(b); pendingBytes += b.length; } else dropped += b.length;
  }
  function endStream() { try { if (wsOpen) { ws.send(JSON.stringify({ event: 'end_of_stream' })); setTimeout(finish, FINAL_WAIT_MS).unref(); } else finish(); } catch { finish(); } }

  function finish() {
    if (done || !ended) return; done = true;
    const sum = { ev: 'summary', call_id: callId, frames, bytes_in: recvB, bytes_sent: sent, bytes_dropped: dropped, ws_open_ms: tWsOpen, first_audio_ms: tFirstAudio, answer_s: tEnded === null ? null : +(tEnded / 1000).toFixed(2), final_len: finalMsg ? finalMsg.text.length : null, word_pre: hit && hit.word, word_post: postHit && postHit.word, script: scriptState, ws_err: wsErr };
    log(sum); console.log(JSON.stringify({ ...sum, ev: 'summary' }));
    try { ws && ws.close(); } catch {}
    if (logFd !== null) { try { fs.closeSync(logFd); } catch {} logFd = null; }
    sock.destroy();
  }
  function onHangup() {
    if (ended) return; ended = true; tEnded = t0 === null ? 0 : rel();
    log({ ev: 'closed', t_ms: tEnded });
    if (!ws) { finish(); return; }
    endStream();
  }

  sock.on('data', d => {
    try {
      for (const f of parse(d)) {
        if (f.type === T_UUID && f.payload.length === 16 && !callId) {
          callId = hex2uuid(f.payload);
          try { logFd = fs.openSync(path.join(cfg.logDir, `${callId}.jsonl`), 'a', 0o600); } catch {}
          t0 = performance.now(); log({ ev: 'start', call_id: callId, wall: Date.now(), catalog: catalog.info() });
          openWs();
        } else if (f.type === T_AUDIO) { if (callId) audio(f.payload); }
        else if (f.type === T_HANGUP) onHangup();
      }
    } catch (e) { console.error('frame_error', e.message); }
  });
  sock.on('close', onHangup);
}

const srv = net.createServer(handle);
srv.on('error', e => { console.error('server_error', e.message); process.exit(1); });
process.on('uncaughtException', e => console.error('uncaught', e.message));
process.on('unhandledRejection', e => console.error('unhandled', String(e)));
await catalog.start();
srv.listen(cfg.port, cfg.host, () => console.error(`zypad-bridge escuchando ${cfg.host}:${cfg.port}`));
