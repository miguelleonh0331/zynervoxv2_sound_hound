// Configuracion del puente: fichero de claves (ZYPAD_CONFIG, por defecto /etc/zypad/bridge.env, modo 640) y variables de entorno.
import fs from 'node:fs';

export function parseEnvFile(text) {
  const out = {};
  for (const line of text.split('\n')) {
    const i = line.indexOf('=');
    if (i < 1 || line.trimStart().startsWith('#')) continue;
    out[line.slice(0, i).trim()] = line.slice(i + 1).trim().replace(/^["']|["']$/g, '');
  }
  return out;
}

export function loadConfig(processEnv = process.env) {
  const file = processEnv.ZYPAD_CONFIG || '/etc/zypad/bridge.env';
  const e = { ...parseEnvFile(fs.readFileSync(file, 'utf8')), ...processEnv };
  if (!e.ZYPAD_URL || !e.ZYPAD_API_KEY) throw new Error(`Faltan ZYPAD_URL o ZYPAD_API_KEY en ${file}`);
  return {
    zypadBase: e.ZYPAD_URL.split('?')[0],
    apiKey: e.ZYPAD_API_KEY,
    host: e.BRIDGE_HOST || '127.0.0.1',
    port: +(e.BRIDGE_PORT || 8765),
    logDir: e.BRIDGE_LOG_DIR || '/var/lib/zypad-bridge/logs',
    catalogCmd: e.CATALOG_CMD || '',          // programa que imprime {"version":..,"words":[{word,category,active}]}
    catalogFile: e.CATALOG_FILE || '',        // alternativa: fichero JSON con ese mismo formato
    catalogCache: e.CATALOG_CACHE || '/var/lib/zypad-bridge/catalog.json',
    catalogEveryMs: +(e.CATALOG_EVERY_MS || 10000),
    cutCmd: e.CUT_CMD || '',                  // <cmd> <uuid> <palabra> <ms_epoch> <audio_ms>; solo UUID c07e; vacio = nunca corta
    annotateCmd: e.ANNOTATE_CMD || '',        // mismo formato, para el resto (modo sombra: anota, no corta); vacio = no anota
    cutUuidRe: e.CUT_UUID_RE || '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-c07e-[0-9a-f]{12}$',
  };
}
