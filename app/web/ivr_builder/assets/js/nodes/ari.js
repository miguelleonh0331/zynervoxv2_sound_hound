// ==== Experimento "doble canal" 4006: menu_ari / capture_stt_ari / amd_ari
// (2026-09-08, ver E:\Proyectos\whatsapp-out\nuevo_discador-stt-silence.md).
// Mismo patron de extension aditiva ya usado arriba por amd / capture_stt
// retry / inject_sql / composite (envolver add/ports/bad/props/validate) --
// CERO cambios a las funciones base ni a los tipos existentes. Escuchan via
// el servicio dynamic_ivr_4006_ari.py (Snoop+ExternalMedia+Deepgram
// streaming) en vez de RECORD FILE, para no depender del corte silencioso
// de RTP confirmado por PCAP (seccion P15). SIN DTMF en esta primera
// version (Snoop solo trae audio, no digitos) -- solo se usan desde el
// flujo de prueba (10), no afecta 2006/3006 ni campañas reales.

const ariBaseAdd = add;
add = function (type, x, y) {
  ariBaseAdd(type, x, y);
  if (!["menu_ari", "capture_stt_ari", "amd_ari"].includes(type)) return;
  let n = flow.nodes[selected];
  if (type === "menu_ari") {
    n.message = "Menú STT (doble canal)";
  } else if (type === "capture_stt_ari") {
    n.message = "Capturar dato STT (doble canal)";
  } else if (type === "amd_ari") {
    n.message = "Detección buzón (doble canal)";
    n.timeout_ms = 3000;
    n.stt_intents = [
      { id: "casilla_de_voz", phrases: [], priority: 100, target: "", fuzzy: false },
      { id: "numero_erroneo", phrases: [], priority: 100, target: "", fuzzy: false },
      { id: "fuera_de_servicio", phrases: [], priority: 100, target: "", fuzzy: false },
    ];
  }
  render();
};

const ariBaseNoAudio = noAudio;
noAudio = function (n) {
  if (n.type === "menu_ari" || n.type === "capture_stt_ari") return !n.audio_hash || n.audio_status !== "ready";
  return ariBaseNoAudio(n);
};

const ariBasePorts = ports;
ports = function (n) {
  if (n.type === "menu_ari") return (n.stt_intents || []).map((x) => ["STT " + x.id, "if"]).concat([["timeout", "timeout"]]);
  if (n.type === "capture_stt_ari") return [["valid", "next"], ["invalid", "timeout"]];
  if (n.type === "amd_ari") return (n.stt_intents || []).map((x) => ["STT " + x.id, "if"]).concat([["humano", "human"]]);
  return ariBasePorts(n);
};

const ariBaseConnect = connect;
connect = function (from, key, to) {
  if (key === "humano" && flow.nodes[from] && flow.nodes[from].type === "amd_ari") {
    flow.nodes[from].fallback = to;
    return;
  }
  return ariBaseConnect(from, key, to);
};

const ariBaseLine = line;
line = function (a, from, key, to) {
  let n = flow.nodes[from];
  if (n && n.type === "amd_ari" && key === "timeout") {
    if (!flow.nodes[to]) return;
    let p1 = dotPoint(from, "humano"), p2 = dotPoint(to, "", true);
    if (!p1 || !p2) return;
    let m = (p1.x + p2.x) / 2;
    a.push(
      `<path class="edge human" onclick="unlink('${esc(from)}','${esc(key)}')" d="${curve(p1.x, p1.y, p2.x, p2.y)}"/><text class=edge-label x="${m}" y="${(p1.y + p2.y) / 2 - 4}">humano</text>`
    );
    return;
  }
  return ariBaseLine(a, from, key, to);
};

const ariBaseBad = bad;
bad = function (n) {
  if (n.type === "menu_ari") return !(n.stt_intents || []).length || !n.fallback;
  if (n.type === "capture_stt_ari") return !n.variable || !n.next || !n.fallback;
  if (n.type === "amd_ari") return !n.fallback || !(n.stt_intents || []).length;
  return ariBaseBad(n);
};

const ariBaseValidate = validate;
validate = function () {
  let errors = ariBaseValidate();
  // La validate() base exige "conecte next." para cualquier tipo fuera de su
  // lista de excepciones; menu_ari/amd_ari no usan next (igual que menu/amd).
  errors = errors.filter((msg) => {
    let match = msg.match(/^(.+?): conecte next\.$/);
    return !(match && flow.nodes[match[1]] && ["menu_ari", "amd_ari"].includes(flow.nodes[match[1]].type));
  });
  ids().forEach((id) => {
    let n = flow.nodes[id];
    if (n.type === "menu_ari") {
      if (!(n.stt_intents || []).length) errors.push(id + ": agregue al menos una intención STT.");
      if (!n.fallback) errors.push(id + ": conecte timeout.");
      if (Number(n.retry_count || 0) > 0 && (!n.retry_text || !n.retry_audio)) errors.push(id + ": genere audio retry STT.");
      (n.stt_intents || []).forEach((x) => {
        if (!x.phrases || !x.phrases.length) errors.push(id + ": agregue frases STT " + x.id + ".");
        if (!x.target) errors.push(id + ": conecte STT " + x.id + ".");
      });
    } else if (n.type === "capture_stt_ari") {
      if (!n.variable || !n.next || !n.fallback) errors.push(id + ": capture STT (doble canal) requiere variable, valid y invalid.");
      if (Number(n.retry_count || 0) > 0 && (!n.retry_text || !n.retry_audio)) errors.push(id + ": genere audio retry STT.");
    } else if (n.type === "amd_ari") {
      if (!n.fallback) errors.push(id + ": conecte humano (categoría por defecto, sin match).");
      if (!(n.stt_intents || []).length) errors.push(id + ": agregue al menos una categoría.");
      (n.stt_intents || []).forEach((x) => {
        if (!x.phrases || !x.phrases.length) errors.push(id + ": agregue frases a la categoría " + x.id + ".");
        if (!x.target) errors.push(id + ": conecte la categoría " + x.id + ".");
      });
    }
  });
  return errors;
};

const ariBaseProps = props;
props = function () {
  ariBaseProps();
  let n = flow.nodes[selected];
  if (!n || !["menu_ari", "capture_stt_ari", "amd_ari"].includes(n.type)) return;
  let form = document.querySelector("#props .form");
  if (form) {
    let typeSelect = form.querySelector("select");
    if (typeSelect && !Array.from(typeSelect.options).some((o) => o.value === n.type)) {
      let option = document.createElement("option");
      option.value = n.type;
      option.textContent = n.type;
      option.selected = true;
      typeSelect.appendChild(option);
    }
  }
  let heading = Array.from(document.querySelectorAll("#props strong")).find((x) => x.textContent === "Conexiones");
  if (!heading) return;

  if (n.type === "amd_ari") {
    Array.from(document.querySelectorAll("#props p")).forEach((p) => {
      let textNode = Array.from(p.childNodes).find((node) => node.nodeType === Node.TEXT_NODE);
      if (textNode && /^timeout\s*→/.test(textNode.nodeValue)) textNode.nodeValue = textNode.nodeValue.replace(/^timeout/, "humano");
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
      `<div class=help style="border:1px solid #3a6ea6;border-radius:7px">
        <b style="color:#8ec4f0">🔀 Doble canal (Snoop/ExternalMedia + Deepgram streaming, sin RECORD FILE)</b>
        <p class=small>Escucha ${Number(n.timeout_ms || 3000)} ms solo al cliente, sin reproducir nada. Si el proveedor corta el RTP (audio muerto), corta directo por el puerto verde <b>"humano"</b>. Categorías:</p>
        ${intentsHtml}
        <button onclick="addAmdCategory()">+ Agregar categoría</button>
      </div>`
    );
    return;
  }

  heading.insertAdjacentHTML(
    "beforebegin",
    `<div class=help style="border:1px solid #3a6ea6;border-radius:7px">
      <b style="color:#8ec4f0">🔀 Doble canal (Snoop/ExternalMedia + Deepgram streaming, sin RECORD FILE)</b>
      <p class=small>El texto/prompt se reproduce igual que siempre; lo que cambia es que este nodo escucha via el servicio auxiliar dynamic_ivr_4006_ari.py en vez de RECORD FILE. Solo Marcelo IA/Deepgram disponible aqui (micrófono y co-work quedan para los nodos clásicos por ahora).</p>
      <label>Texto audio (Marcelo IA)<textarea oninput="flow.nodes[selected].audio_text=this.value">${esc(n.audio_text || "")}</textarea></label>
      <button onclick="generateAudio()">Generar / regenerar</button>
      <p id=audioState>${esc(n.audio_status || "pending")} ${n.audio_hash ? "hash " + esc(n.audio_hash.slice(0, 12)) : ""}</p>
      ${n.audio_hash ? '<audio controls src="' + audioSrc(n) + '" style="width:100%"></audio>' : ""}
    </div>`
  );

  if (n.type === "capture_stt_ari") {
    heading.insertAdjacentHTML(
      "beforebegin",
      `<div class=help><b>Captura STT (doble canal)</b>
        <label>Modo<select onchange="set('capture_mode',this.value)">${["date", "text", "number", "name"].map((x) => `<option ${x === n.capture_mode ? "selected" : ""}>${x}</option>`).join("")}</select></label>
        <label>Variable<input value="${esc(n.variable || "")}" onchange="set('variable',this.value)"></label>
      </div>`
    );
  }

  if (n.type === "menu_ari") {
    let intentsHtml = (n.stt_intents || [])
      .map(
        (x, i) => `
      <div class=help>
        <b>${esc(x.id)}</b>
        <label>Frases, una por linea<textarea onchange="setIntent(${i},'phrases',this.value.split(/\\r?\\n/).map(v=>v.trim()).filter(Boolean))">${esc((x.phrases || []).join("\n"))}</textarea></label>
        <label>Prioridad<input type=number value="${x.priority}" onchange="setIntent(${i},'priority',Number(this.value))"></label>
        <label><input type=checkbox ${x.fuzzy ? "checked" : ""} onchange="setIntent(${i},'fuzzy',this.checked)"> Tolerancia a variaciones (coincidencia aproximada)</label>
        <button class=danger onclick="removeIntent(${i})">Eliminar intent</button>
      </div>`
      )
      .join("");
    heading.insertAdjacentHTML(
      "beforebegin",
      `<strong>Intents STT</strong>${intentsHtml}<button onclick="addStt(event,selected)">+ intención STT</button>`
    );
  }

  heading.insertAdjacentHTML(
    "beforebegin",
    `<div class=help>
      <b>Retry STT</b>
      <label>Reintentos<input type=number min=0 max=3 value="${Number(n.retry_count || 0)}" onchange="set('retry_count',Math.max(0,Math.min(3,Number(this.value))))"></label>
      <label>Texto repregunta<textarea oninput="flow.nodes[selected].retry_text=this.value">${esc(n.retry_text || "")}</textarea></label>
      <button onclick="generateRetryAudio()">Generar / regenerar audio retry</button>
      <p id=retryAudioState>${n.retry_audio_hash ? "ready hash " + esc(n.retry_audio_hash.slice(0, 12)) : "pending"}</p>
      ${n.retry_audio_hash ? `<audio controls src="audio_serve.php?hash=${esc(n.retry_audio_hash)}&v=${Date.now()}" style="width:100%"></audio>` : ""}
    </div>`
  );
};

// generateAudio/generateRetryAudio: mismo patron de reemplazo total que ya
// uso capture_stt mas arriba (linea ~283) para sumar su propio tipo -- se
// amplia la lista de tipos aceptados, mismo cuerpo de siempre.
generateAudio = async function () {
  let n = flow.nodes[selected];
  if (!n || !["create_audio", "capture_stt", "menu", "menu_ari", "capture_stt_ari"].includes(n.type)) return;
  let state = $("audioState"), start = Date.now();
  state.textContent = "generando 0.0 s";
  let timer = setInterval(() => (state.textContent = "generando " + ((Date.now() - start) / 1000).toFixed(1) + " s"), 100);
  try {
    let d = await (
      await fetch("generate_audio.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ text: n.audio_text }) })
    ).json();
    if (!d.ok) throw new Error(d.error);
    n.audio = d.audio;
    n.audio_hash = d.hash;
    n.audio_status = "ready";
    $("status").textContent = (d.cached ? "Cache hit " : "Audio generado ") + (d.duration_ms / 1000).toFixed(2) + " s";
    render();
  } catch (e) {
    n.audio_status = "error";
    state.textContent = "Error: " + e.message;
  } finally {
    clearInterval(timer);
  }
};

generateRetryAudio = async function () {
  let n = flow.nodes[selected],
    state = $("retryAudioState");
  if (!n || !["menu", "capture_stt", "menu_ari", "capture_stt_ari"].includes(n.type) || !n.retry_text) return;
  state.textContent = "generando...";
  try {
    let d = await (
      await fetch("generate_audio.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ text: n.retry_text }) })
    ).json();
    if (!d.ok) throw new Error(d.error);
    n.retry_audio = d.audio;
    n.retry_audio_hash = d.hash;
    $("status").textContent = (d.cached ? "Cache hit " : "Audio retry generado ") + (d.duration_ms / 1000).toFixed(2) + " s";
    render();
  } catch (e) {
    state.textContent = "Error: " + e.message;
  }
};
