// Comparacion de palabras del catalogo contra el texto parcial: sin mayusculas ni acentos, palabra o frase completa.
export const norm = t => String(t).normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().replace(/\s+/g, ' ').trim();

const esc = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** words: [{word, category, active}] -> (texto) => {word, category} | null (la primera que aparece en el texto). */
export function buildMatcher(words) {
  const list = (words || []).filter(w => w && w.active !== false && typeof w.word === 'string' && norm(w.word))
    .map(w => ({ word: w.word, category: w.category || '', re: new RegExp(`(^|[^\\p{L}\\p{N}])${esc(norm(w.word))}($|[^\\p{L}\\p{N}])`, 'u') }));
  return text => {
    const t = norm(text);
    let best = null, bestPos = Infinity;
    for (const w of list) { const m = w.re.exec(t); if (m && m.index < bestPos) { best = w; bestPos = m.index; } }
    return best ? { word: best.word, category: best.category } : null;
  };
}
