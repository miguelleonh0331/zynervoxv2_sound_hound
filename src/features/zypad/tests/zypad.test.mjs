import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { buildMatcher, norm } from '../lib/matcher.mjs';
import { frameParser, hex2uuid } from '../lib/frames.mjs';
import { createCatalog } from '../lib/catalog.mjs';
import { parseEnvFile } from '../lib/config.mjs';

test('matcher: sin mayusculas ni acentos, palabra completa, solo activas', () => {
  const m = buildMatcher([{ word: 'Mensaje', category: 'casilla_voz', active: true }, { word: 'buzón de voz', active: true }, { word: 'apagada', active: false }]);
  assert.equal(m('Deje su MENSAJE después del tono').word, 'Mensaje');
  assert.equal(m('el buzon de voz no esta disponible').word, 'buzón de voz');
  assert.equal(m('mensajero'), null);
  assert.equal(m('apagada'), null);
  assert.equal(norm('Canción'), 'cancion');
});

test('matcher: devuelve la que aparece primero en el texto', () => {
  const m = buildMatcher([{ word: 'tono', active: true }, { word: 'mensaje', active: true }]);
  assert.equal(m('deje un mensaje despues del tono').word, 'mensaje');
});

test('frames: trozos partidos y varias tramas', () => {
  const f = (t, p) => Buffer.concat([Buffer.from([t, p.length >> 8, p.length & 255]), p]);
  const uuid = Buffer.from('0123456789abcdef0123456789abcdef', 'hex');
  const all = Buffer.concat([f(1, uuid), f(0x10, Buffer.alloc(320, 1)), f(0, Buffer.alloc(0))]);
  const push = frameParser();
  const out = [...push(all.subarray(0, 10)), ...push(all.subarray(10, 200)), ...push(all.subarray(200))];
  assert.deepEqual(out.map(x => x.type), [1, 0x10, 0]);
  assert.equal(hex2uuid(out[0].payload), '01234567-89ab-cdef-0123-456789abcdef');
});

test('catalogo: usa la copia si no hay fuente, y nunca falla', async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'zc-'));
  const cache = path.join(dir, 'c.json'), src = path.join(dir, 's.json');
  fs.writeFileSync(src, JSON.stringify({ version: 'a', words: [{ word: 'mensaje', active: true }] }));
  const c1 = createCatalog({ file: src, cache }); await c1.refresh();
  assert.equal(c1.find('un mensaje').word, 'mensaje');
  fs.unlinkSync(src);
  const c2 = createCatalog({ file: src, cache }); await c2.refresh();     // fuente caida, copia en disco
  assert.equal(c2.find('un mensaje').word, 'mensaje'); assert.equal(c2.info().source, 'cache');
  const c3 = createCatalog({ cmd: '/no/existe', cache: path.join(dir, 'nada.json') }); await c3.refresh();
  assert.equal(c3.find('un mensaje'), null);                              // sin fuente ni copia: no detecta
  fs.writeFileSync(src, '{roto'); await c1.refresh();
  assert.equal(c1.find('un mensaje').word, 'mensaje');                    // JSON roto: conserva el anterior
});

test('catalogo: si el programa falla usa el fichero de respaldo', async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'zf-'));
  const src = path.join(dir, 's.json');
  fs.writeFileSync(src, JSON.stringify({ version: 'r', words: [{ word: 'mensaje', active: true }] }));
  const c = createCatalog({ cmd: '/no/existe', file: src }); await c.refresh();
  assert.equal(c.find('un mensaje').word, 'mensaje');
});

test('config: lee fichero de claves', () => {
  assert.deepEqual(parseEnvFile('# c\nA=1\nB="x=y"\n'), { A: '1', B: 'x=y' });
});
