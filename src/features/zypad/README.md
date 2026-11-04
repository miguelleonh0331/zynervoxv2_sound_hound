# zypad-bridge (puente AudioSocket → zypad)

Recibe el audio de la llamada desde Asterisk (AudioSocket, 127.0.0.1:8765), lo manda en tiempo real al servicio zypad (VPS `remote.zynervox.site`, WebSocket `/stt-test`, `format=pcm16`) y busca las palabras del catálogo en los parciales de texto. Node 22, sin dependencias. Dueño: Zypad.

## Contrato con el dialplan (Telefonia)
- Telefonia abre AudioSocket(<uuid>, 127.0.0.1:8765) sobre un ChanSpy del canal del proveedor (solo audio entrante). El UUID es el MD5 de `ZV2_KEY` en 8-4-4-4-12; es el `call_id` en zypad y el `zypad_call_id` del marcador.
- El socket se cierra en el 200 OK (answer) o al terminar la llamada. Lo que aparece con el socket abierto es **pre-answer**; una palabra posterior queda en el log (`word_post`) y no se anota.
- Primera palabra del catálogo vista antes del answer, una vez por llamada, sin bloquear la llamada:
  - UUID con marca `c07e` en el cuarto grupo (dialplans 7312/7313) → `CUT_CMD <uuid> <palabra> <ms_epoch> <audio_ms>` (`cortar_llamada.sh`).
  - Cualquier otro UUID (7314/7315, sombra) → `ANNOTATE_CMD <uuid> <palabra> <ms_epoch> <audio_ms>` (`anotar_palabra.sh`). Solo anota; no corta.
  - `audio_ms` = milisegundos de audio enviados a zypad hasta el parcial donde salió la palabra (desde el primer frame). El programa responde una línea JSON con `estado`; se guarda en el log. Límite 3 s; si falla, se ignora.
- Si zypad, el catálogo, el disco o los programas fallan, el puente solo lo registra: la llamada nunca se entera.

## Catálogo
`CATALOG_CMD` (programa que imprime `{"version":…,"words":[{"word","category","active"}]}`) o `CATALOG_FILE` (mismo JSON). Se relee cada `CATALOG_EVERY_MS` (10 s) con límite de 3 s; si `version` cambia, se aplica a las llamadas siguientes y a las en curso (sin reiniciar). Última copia buena en `CATALOG_CACHE`. Sin fuente ni copia, no detecta. Comparación sin mayúsculas ni acentos, palabra o frase completa.

## Configuración
Fichero `ZYPAD_CONFIG` (por defecto `/etc/zypad/bridge.env`, modo 640, dueño root, grupo del servicio) con `ZYPAD_URL` y `ZYPAD_API_KEY`; el resto por entorno (ver `lib/config.mjs`): `BRIDGE_HOST` (127.0.0.1), `BRIDGE_PORT` (8765), `BRIDGE_LOG_DIR` (`/var/lib/zypad-bridge/logs`), `CATALOG_*`, `CUT_CMD`, `ANNOTATE_CMD`, `CUT_UUID_RE`. La clave nunca se imprime ni se registra.

## Logs y retención
`BRIDGE_LOG_DIR/<uuid>.jsonl`, directorio 700 y ficheros 600: **contienen transcripciones** (datos personales). Eventos: `start`, `ws_open`, `first_audio` (UTC con ms), `text` (cada cambio de texto), `word_pre`/`word_post`, `cut_*`/`annotate_*`, `closed` (segundo del answer), `final`, `summary`. Retención sugerida: 14 días (`systemd-tmpfiles`).

## Servicio
`zypad-bridge.service` (plantilla en este directorio; la instala Instalador). Arranca con el sistema y se reinicia solo. Recargar el catálogo no reinicia nada.

## Pruebas
`node --test tests/zypad.test.mjs` (matcher, tramas, catálogo con copia, config). Para una llamada de prueba sin Asterisk: `tests/as_client.mjs <wav> <uuid> [puerto]`.
