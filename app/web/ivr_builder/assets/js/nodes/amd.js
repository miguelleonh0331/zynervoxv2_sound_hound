// ==== amd (2026-09-05) — detección de buzón/número erróneo/fuera de
// servicio escuchando solo unos segundos al cliente, sin reproducir nada.
// Reusa TAL CUAL el campo stt_intents (misma función real match_stt_intent()
// del lado Python, ya probada en produccion para nodos "menu"). El puerto
// usa el mismo prefijo "STT " a propósito para que connect()/unlink()/draw()/
// line() (ya genéricos, sin tocar) lo manejen sin cambios.

const amdBaseAdd = add;
add = function (type, x, y) {
  amdBaseAdd(type, x, y);
  if (type === "amd") {
    let n = flow.nodes[selected];
    n.message = "Detección buzón (AMD)";
    n.timeout_ms = 3000;
    n.stt_intents = [
      { id: "casilla_de_voz", phrases: [], priority: 100, target: "", fuzzy: false },
      { id: "numero_erroneo", phrases: [], priority: 100, target: "", fuzzy: false },
      { id: "fuera_de_servicio", phrases: [], priority: 100, target: "", fuzzy: false },
    ];
    render();
  }
};

const amdBasePorts = ports;
ports = function (n) {
  if (n.type === "amd") {
    return (n.stt_intents || []).map((x) => ["STT " + x.id, "if"]).concat([["humano", "human"]]);
  }
  return amdBasePorts(n);
};

// "humano" es el puerto por defecto (sin audio o cualquier palabra que no
// esté en las categorías declaradas): mismo campo n.fallback de siempre,
// solo que para "amd" se etiqueta y colorea distinto (verde, no rojo) porque
// NO es un error/timeout -- es el resultado esperado y bueno (sigue como
// llamada humana normal).
const amdBaseConnect = connect;
connect = function (from, key, to) {
  if (key === "humano") {
    flow.nodes[from].fallback = to;
    return;
  }
  return amdBaseConnect(from, key, to);
};

// NOTA: draw() (funcion base, sin tocar) dibuja el fallback de CUALQUIER
// tipo generico (amd incluido) llamando siempre line(a,id,"timeout",n.fallback)
// -- la palabra "timeout" ahi es solo la clave interna que ya entiende
// unlink() para borrar n.fallback (funciona igual para amd, no hace falta
// tocar unlink). Lo unico que se intercepta aqui es el DIBUJO (color verde +
// etiqueta "humano" en vez de rojo "timeout"), sustituyendo esa llamada
// puntual sin duplicar la linea.
const amdBaseLine = line;
line = function (a, from, key, to) {
  let n = flow.nodes[from];
  if (n && n.type === "amd" && key === "timeout") {
    if (!flow.nodes[to]) return;
    let p1 = dotPoint(from, "humano"),
      p2 = dotPoint(to, "", true);
    if (!p1 || !p2) return;
    let m = (p1.x + p2.x) / 2;
    a.push(
      `<path class="edge human" onclick="unlink('${esc(from)}','${esc(key)}')" d="${curve(p1.x, p1.y, p2.x, p2.y)}"/><text class=edge-label x="${m}" y="${(p1.y + p2.y) / 2 - 4}">humano</text>`
    );
    return;
  }
  return amdBaseLine(a, from, key, to);
};

const amdBaseBad = bad;
bad = function (n) {
  if (n.type === "amd") return !n.fallback || !(n.stt_intents || []).length;
  return amdBaseBad(n);
};

const amdBaseValidate = validate;
validate = function () {
  let errors = amdBaseValidate();
  // La validate() base exige "conecte next." para cualquier tipo que no
  // esté en su lista de excepciones (menu/hangup/capture_stt/bridge/
  // execute/decision_range) -- "amd" no estaba en esa lista y no usa next
  // (usa stt_intents + fallback, igual que menu). Se filtra ese falso
  // positivo aquí en vez de tocar la función base.
  errors = errors.filter((msg) => {
    let match = msg.match(/^(.+?): conecte next\.$/);
    return !(match && flow.nodes[match[1]] && flow.nodes[match[1]].type === "amd");
  });
  ids().forEach((id) => {
    let n = flow.nodes[id];
    if (n.type !== "amd") return;
    if (!n.fallback) errors.push(id + ": conecte humano (categoría por defecto, sin match).");
    if (!(n.stt_intents || []).length) errors.push(id + ": agregue al menos una categoría.");
    (n.stt_intents || []).forEach((x) => {
      if (!x.phrases || !x.phrases.length) errors.push(id + ": agregue frases a la categoría " + x.id + ".");
      if (!x.target) errors.push(id + ": conecte la categoría " + x.id + ".");
    });
  });
  return errors;
};

function addAmdCategory() {
  let key = (prompt("Nombre de la categoría (ej: casilla_de_voz, numero_erroneo, fuera_de_servicio):") || "").trim();
  if (!/^[a-zA-Z0-9_-]+$/.test(key)) return alert("Nombre inválido");
  let n = flow.nodes[selected];
  n.stt_intents = n.stt_intents || [];
  if (n.stt_intents.some((x) => x.id === key)) return alert("Categoría duplicada");
  n.stt_intents.push({ id: key, phrases: [], priority: 100, target: "", fuzzy: false });
  render();
}

const amdBaseProps = props;
props = function () {
  amdBaseProps();
  let n = flow.nodes[selected];
  if (!n || n.type !== "amd") return;
  let form = document.querySelector("#props .form");
  if (form) {
    let typeSelect = form.querySelector("select");
    if (typeSelect && !Array.from(typeSelect.options).some((o) => o.value === "amd")) {
      let option = document.createElement("option");
      option.value = "amd";
      option.textContent = "amd";
      option.selected = true;
      typeSelect.appendChild(option);
    }
  }
  let heading = Array.from(document.querySelectorAll("#props strong")).find((x) => x.textContent === "Conexiones");
  if (!heading) return;
  // Relabel visual: la lista de Conexiones (generica, de amdBaseProps) dice
  // "timeout" porque asi la etiqueta el codigo base para cualquier tipo sin
  // rama propia -- para amd se muestra como "humano" (no toca el boton
  // "Quitar", que sigue funcionando igual).
  Array.from(document.querySelectorAll("#props p")).forEach((p) => {
    let textNode = Array.from(p.childNodes).find((node) => node.nodeType === Node.TEXT_NODE);
    if (textNode && /^timeout\s*→/.test(textNode.nodeValue)) {
      textNode.nodeValue = textNode.nodeValue.replace(/^timeout/, "humano");
    }
  });
  let intentsHtml = (n.stt_intents || [])
    .map(
      (x, i) => `
    <div class=help>
      <b>${esc(x.id)}${x.target ? " → " + esc(x.target) : " (sin conectar)"}</b>
      <label>Frases, una por línea<textarea onchange="setIntent(${i},'phrases',this.value.split(/\\r?\\n/).map(v=>v.trim()).filter(Boolean))">${esc((x.phrases || []).join("\n"))}</textarea></label>
      <button class=danger onclick="removeIntent(${i})">Eliminar categoría</button>
    </div>`
    )
    .join("");
  heading.insertAdjacentHTML(
    "beforebegin",
    `<div class=help style="border:1px solid #a65b5b;border-radius:7px">
      <b style="color:#f0a5a5">📵 Categorías de corte (AMD)</b>
      <p class=small>Escucha ${Number(n.timeout_ms || 3000)} ms solo al cliente, sin reproducir nada. Si el texto coincide con una categoría, va a su destino (arrástralo desde el puerto del nodo en el canvas). Si no hay voz o dice cualquier otra cosa que no está en la lista, va al puerto verde <b>"humano"</b> (categoría por defecto — solo arrastra su conexión).</p>
      ${intentsHtml}
      <button onclick="addAmdCategory()">+ Agregar categoría</button>
    </div>`
  );
};
