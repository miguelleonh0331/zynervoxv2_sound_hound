// Cliente de prueba AudioSocket: node as_client.mjs <wav> <uuid> [port=8765] [skip_s=0]
import fs from 'node:fs'; import net from 'node:net';
const [wav, uuid, port = 8765] = process.argv.slice(2);
const pcm = fs.readFileSync(wav).subarray(44);
const s = net.connect(+port, '127.0.0.1');
s.on('error', e => { console.error('err', e.message); process.exit(1); });
s.on('connect', async () => {
  const f = (t, p) => { const h = Buffer.alloc(3); h[0] = t; h.writeUInt16BE(p.length, 1); return Buffer.concat([h, p]); };
  s.write(f(1, Buffer.from(uuid.replace(/-/g, ''), 'hex')));
  for (let o = 0; o < pcm.length; o += 320) { s.write(f(0x10, pcm.subarray(o, o + 320))); await new Promise(r => setTimeout(r, 20)); }
  s.write(f(0, Buffer.alloc(0)));
});
s.on('close', () => process.exit(0));
