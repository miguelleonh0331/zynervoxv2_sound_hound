// ==== create_audio_composite (2026-09-05) ====
// Solo diseño del flujo: elegir proveedor y armar la lista de frases con su
// pausa. La generación real del audio ocurre en prebuild_campaign_audios.py
// al pregenerar la campaña (Activar motor -> Generar audios), NO hay botón
// de preview en el Builder en esta primera version. Mismo patron de
// extensión (envolver add/props/validate/noAudio) que ya usan inject_sql y
// el retry STT de capture_stt en este mismo archivo, arriba.

const compositeBaseAdd = add;
add = function (type, x, y) {
  compositeBaseAdd(type, x, y);
  if (type === "create_audio_composite") {
    let n = flow.nodes[selected];
    n.message = "Audio compuesto";
    n.compose_provider = "deepgram";
    n.segments = [{ id: "seg_1", text: "", pause_after_ms: 20 }];
    n.composite_hash = "";
    n.composite_status = "pending";
    render();
  }
};

const compositeBaseNoAudio = noAudio;
noAudio = function (n) {
  if (n.type === "create_audio_composite") return n.composite_status !== "ready";
  return compositeBaseNoAudio(n);
};

const compositeBaseValidate = validate;
validate = function () {
  let errors = compositeBaseValidate();
  ids().forEach((id) => {
    let n = flow.nodes[id];
    if (n.type !== "create_audio_composite") return;
    if (!n.next) errors.push(id + ": conecte next.");
    let segs = n.segments || [];
    if (!segs.length) errors.push(id + ": agregue al menos un segmento.");
    segs.forEach((s, i) => {
      if (!String(s.text || "").trim()) errors.push(id + ": segmento " + (i + 1) + " sin texto.");
    });
  });
  return errors;
};

function addSegment() {
  let n = flow.nodes[selected];
  n.segments = n.segments || [];
  n.segments.push({ id: "seg_" + (n.segments.length + 1), text: "", pause_after_ms: 20 });
  render();
}
function removeSegment(i) {
  flow.nodes[selected].segments.splice(i, 1);
  render();
}
function setSegmentText(i, v) {
  flow.nodes[selected].segments[i].text = v;
}
function setSegmentPause(i, v) {
  flow.nodes[selected].segments[i].pause_after_ms = Math.max(0, Math.min(3000, Number(v) || 0));
}
function setComposeProvider(v) {
  flow.nodes[selected].compose_provider = v;
}

const compositeBaseProps = props;
props = function () {
  compositeBaseProps();
  let n = flow.nodes[selected];
  if (!n || n.type !== "create_audio_composite") return;
  let form = document.querySelector("#props .form");
  if (form) {
    let typeSelect = form.querySelector("select");
    if (typeSelect && !Array.from(typeSelect.options).some((option) => option.value === "create_audio_composite")) {
      let option = document.createElement("option");
      option.value = "create_audio_composite";
      option.textContent = "create_audio_composite";
      option.selected = true;
      typeSelect.appendChild(option);
    }
  }
  let heading = Array.from(document.querySelectorAll("#props strong")).find((x) => x.textContent === "Conexiones");
  if (!heading) return;
  let providers = ["deepgram", "deepgram_pool", "deepgram_pod", "macelioai", "macelioai_remote", "voicescloning"];
  let segmentsHtml = (n.segments || [])
    .map(
      (s, i) => `
    <div class=help>
      <b>Segmento ${i + 1}</b>
      <label>Texto<textarea onchange="setSegmentText(${i},this.value)">${esc(s.text || "")}</textarea></label>
      <label>Pausa después (ms)<input type=number min=0 max=3000 value="${Number(s.pause_after_ms || 0)}" oninput="setSegmentPause(${i},this.value)"></label>
      <button class=danger onclick="removeSegment(${i})">Eliminar segmento</button>
    </div>`
    )
    .join("");
  heading.insertAdjacentHTML(
    "beforebegin",
    `<div class=help style="border:1px solid #2f7a4f;border-radius:7px">
      <b style="color:#7be0a0">🔊 Audio compuesto (frases)</b>
      <label>Proveedor<select onchange="setComposeProvider(this.value)">${providers
        .map((p) => `<option value="${p}" ${p === (n.compose_provider || "deepgram") ? "selected" : ""}>${p}</option>`)
        .join("")}</select></label>
      ${segmentsHtml}
      <button onclick="addSegment()">+ Agregar segmento</button>
      <p class=small>El audio se genera al pregenerar la campaña (Activar motor → Generar audios); todavía no hay vista previa aquí.</p>
      <p id=compositeState>${esc(n.composite_status || "pending")} ${n.composite_hash ? "· hash " + esc(n.composite_hash.slice(0, 12)) : ""}</p>
    </div>`
  );
};
