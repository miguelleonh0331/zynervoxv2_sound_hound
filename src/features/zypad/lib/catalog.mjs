// Catalogo de palabras de MarcadorBuilder, recargado sin reiniciar. Nunca bloquea ni lanza: sin catalogo ni copia, la lista queda vacia.
import fs from 'node:fs';
import path from 'node:path';
import { execFile } from 'node:child_process';
import { buildMatcher } from './matcher.mjs';

const valid = j => j && typeof j === 'object' && Array.isArray(j.words) && j.words.every(w => w && typeof w.word === 'string');

function readJson(file) { try { const j = JSON.parse(fs.readFileSync(file, 'utf8')); return valid(j) ? j : null; } catch { return null; } }

function fetchCmd(cmd) {
  return new Promise(resolve => {
    const [bin, ...args] = cmd.trim().split(/\s+/);
    try {
      execFile(bin, args, { timeout: 3000, maxBuffer: 1 << 20 }, (err, so) => {
        if (err) return resolve(null);
        try { const j = JSON.parse(so); resolve(valid(j) ? j : null); } catch { resolve(null); }
      });
    } catch { resolve(null); }
  });
}

export function createCatalog({ cmd = '', file = '', cache = '', everyMs = 10000, log = () => {} } = {}) {
  let current = { version: null, source: 'none', words: [] };
  let match = buildMatcher([]);
  const set = (j, source) => { current = { version: j.version ?? null, source, words: j.words }; match = buildMatcher(j.words); };

  async function refresh() {
    let j = cmd ? await fetchCmd(cmd) : null;
    if (!j && file) j = readJson(file);   // CATALOG_FILE sirve de respaldo si el programa no responde
    if (j) {
      if (j.version === current.version && current.source === 'live') return;
      set(j, 'live'); log({ ev: 'catalog', source: 'live', version: j.version ?? null, words: j.words.filter(w => w.active !== false).length });
      if (cache) { try { const tmp = `${cache}.tmp`; fs.mkdirSync(path.dirname(cache), { recursive: true, mode: 0o700 }); fs.writeFileSync(tmp, JSON.stringify(j), { mode: 0o600 }); fs.renameSync(tmp, cache); } catch {} }
    } else if (current.source === 'none' && cache) {
      const c = readJson(cache);
      if (c) { set(c, 'cache'); log({ ev: 'catalog', source: 'cache', version: c.version ?? null }); }
    }
  }

  return {
    async start() { await refresh().catch(() => {}); const t = setInterval(() => refresh().catch(() => {}), everyMs); t.unref(); },
    refresh,
    find: text => match(text),
    info: () => ({ version: current.version, source: current.source, words: current.words.length }),
  };
}
