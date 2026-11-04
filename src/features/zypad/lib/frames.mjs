// Tramas de AudioSocket de Asterisk: 1 byte tipo, 2 bytes longitud (big endian), carga. 0x01 UUID(16), 0x10 audio slin 8 kHz, 0x00 fin.
export const T_HANGUP = 0x00, T_UUID = 0x01, T_AUDIO = 0x10;

export const hex2uuid = b => { const h = b.toString('hex'); return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`; };

/** Devuelve push(chunk) => [{type, payload}] acumulando trozos parciales. */
export function frameParser() {
  let rx = Buffer.alloc(0);
  return chunk => {
    rx = rx.length ? Buffer.concat([rx, chunk]) : chunk;
    const out = [];
    while (rx.length >= 3) {
      const len = rx.readUInt16BE(1);
      if (rx.length < 3 + len) break;
      out.push({ type: rx[0], payload: Buffer.from(rx.subarray(3, 3 + len)) });
      rx = rx.subarray(3 + len);
    }
    return out;
  };
}
