// ---- CO-WORK: COMPARTIR ENLACE DE GRABACION ----
let coworkPollers = {}; // token -> intervalId

function shareCowork(){
    let n = flow.nodes[selected];
    if(!n || !(n.type==="create_audio" || n.type==="capture_stt")) return;
    // Paso 1: mostrar formulario de comentario antes de generar el enlace
    let modal = document.createElement("div");
    modal.id = "coworkModal";
    modal.style = "position:fixed;inset:0;background:#0009;display:flex;align-items:center;justify-content:center;z-index:9999";
    modal.innerHTML = `
      <div style="background:#172334;border:1px solid #354961;border-radius:10px;padding:28px;max-width:480px;width:90%;color:#e8eef5">
        <h3 style="margin-bottom:12px">🔗 Co-work — Nodo: ${esc(selected)}</h3>
        <p style="font-size:13px;color:#7890a8;margin-bottom:12px">Escribe una nota para el especialista (opcional). Verá esta instrucción al abrir el enlace.</p>
        <textarea id="coworkComment" placeholder="Ej: Saludo inicial, tono amable, máximo 10 segundos..." style="width:100%;padding:9px;border-radius:5px;border:1px solid #486079;background:#111d2b;color:#edf4fa;font-size:13px;min-height:80px;margin-bottom:12px;resize:vertical"></textarea>
        <button onclick="generateCoworkLink('${esc(selected)}')" style="width:100%;padding:10px;background:#176499;color:#fff;border:none;border-radius:5px;cursor:pointer;margin-bottom:8px">🔗 Generar enlace</button>
        <button onclick="document.getElementById('coworkModal').remove()" style="width:100%;padding:8px;background:#354961;color:#e8eef5;border:none;border-radius:5px;cursor:pointer">Cancelar</button>
      </div>`;
    document.body.appendChild(modal);
}

async function generateCoworkLink(nodeId){
    let comment = (document.getElementById("coworkComment")||{}).value||"";
    let d = await (await fetch("cowork_create.php",{
        method:"POST",
        headers:{"Content-Type":"application/json"},
        body: JSON.stringify({node_id: nodeId, flow_code: flowCode, comment})
    })).json();
    if(!d.ok){ $("status").textContent = "Error: " + d.error; return; }
    // Paso 2: mostrar el enlace generado
    let modal = document.getElementById("coworkModal");
    if(modal) modal.querySelector("div").innerHTML = `
        <h3 style="margin-bottom:12px">🔗 Enlace listo — Nodo: ${esc(nodeId)}</h3>
        <p style="font-size:13px;color:#7890a8;margin-bottom:12px">Comparte este enlace. El audio se asignará automáticamente cuando el especialista lo envíe.<br><span style="color:#f0c56d">⏰ Expira en 48 horas.</span></p>
        <input value="${d.url}" readonly style="width:100%;padding:9px;border-radius:5px;border:1px solid #486079;background:#111d2b;color:#edf4fa;font-size:12px;margin-bottom:10px">
        <button onclick="navigator.clipboard.writeText('${d.url}');this.textContent='✅ Copiado!'" style="width:100%;padding:10px;background:#176499;color:#fff;border:none;border-radius:5px;cursor:pointer;margin-bottom:8px">📋 Copiar enlace</button>
        <button onclick="document.getElementById('coworkModal').remove()" style="width:100%;padding:8px;background:#354961;color:#e8eef5;border:none;border-radius:5px;cursor:pointer">Cerrar (polling activo en segundo plano)</button>`;
    startCoworkPolling(d.token, nodeId);
}

function startCoworkPolling(token, nodeId){
    if(coworkPollers[token]) return; // ya hay uno corriendo
    coworkPollers[token] = setInterval(async ()=>{
        try{
            let d = await (await fetch("cowork_poll.php?token="+encodeURIComponent(token),{cache:"no-store"})).json();
            if(d.ok && d.status === "done"){
                clearInterval(coworkPollers[token]);
                delete coworkPollers[token];
                // Asignar audio al nodo correcto
                let n = flow.nodes[nodeId];
                if(n){
                    n.audio      = d.audio;
                    n.audio_hash = d.audio_hash;
                    n.audio_status = "ready";
                    $("status").textContent = "✅ Co-work: grabación recibida en nodo " + nodeId;
                    render();
                }
                // Cerrar modal si sigue abierto
                let modal = document.getElementById("coworkModal");
                if(modal) modal.remove();
            }
        }catch(e){ /* polling silencioso, no mostrar errores */ }
    }, 4000); // consulta cada 4 segundos
}

// ---- GRABACION POR MICROFONO ----
let micRecorder=null, micChunks=[], micStream=null, micActive=false;

async function toggleMic(){
    if(micActive){ stopMic(); return; }
    let btn=document.getElementById("micBtn");
    if(!btn)return;
    try{
        micStream = await navigator.mediaDevices.getUserMedia({audio:true});
        micChunks = [];
        micRecorder = new MediaRecorder(micStream);
        micRecorder.ondataavailable = e=>{ if(e.data.size>0) micChunks.push(e.data); };
        micRecorder.onstop = uploadMicAudio;
        micRecorder.start();
        micActive = true;
        btn.textContent = "⏹️ Detener";
        btn.style.background = "#8a3838";
        document.getElementById("audioState").textContent = "🔴 Grabando...";
    }catch(e){
        alert("No se pudo acceder al micrófono: "+e.message);
    }
}

function stopMic(){
    if(micRecorder && micRecorder.state!=="inactive") micRecorder.stop();
    if(micStream) micStream.getTracks().forEach(t=>t.stop());
    micActive = false;
    let btn=document.getElementById("micBtn");
    if(btn){ btn.textContent="🎙️ Grabar"; btn.style.background=""; }
}

async function uploadMicAudio(){
    let n=flow.nodes[selected];
    if(!n) return;
    let state=document.getElementById("audioState");
    if(state) state.textContent="Subiendo y convirtiendo...";
    let blob = new Blob(micChunks, {type: micRecorder.mimeType || "audio/webm"});
    let form = new FormData();
    form.append("audio", blob, "mic_recording.webm");
    let start=Date.now();
    try{
        let resp = await fetch("upload_audio.php", {method:"POST", body:form});
        let d = await resp.json();
        if(!d.ok) throw new Error(d.error);
        n.audio = d.audio;
        n.audio_hash = d.hash;
        n.audio_status = "ready";
        $("status").textContent = (d.cached?"Cache hit ":"Audio mic subido ")+(d.duration_ms/1000).toFixed(2)+" s";
        render();
    }catch(e){
        n.audio_status = "error";
        if(state) state.textContent = "Error: "+e.message;
    }
}
