// Retry STT para nodos capture_stt. Los nodos menu conservan su UI existente.
const captureRetryProps=props;
props=function(){
    captureRetryProps();
    let n=flow.nodes[selected];
    if(!n||n.type!=="capture_stt")return;
    let heading=Array.from(document.querySelectorAll("#props strong")).find(item=>item.textContent==="Conexiones");
    if(!heading)return;
    heading.insertAdjacentHTML("beforebegin",`<div class=help><b>Retry STT</b><label>Reintentos<input type=number min=0 max=3 value="${Number(n.retry_count||0)}" onchange="set('retry_count',Math.max(0,Math.min(3,Number(this.value))))"></label><label>Texto repregunta<textarea onchange="set('retry_text',this.value)">${esc(n.retry_text||"")}</textarea></label><button onclick="generateRetryAudio()">Generar / regenerar audio retry</button><p id=retryAudioState>${n.retry_audio_hash?"ready hash "+esc(n.retry_audio_hash.slice(0,12)):"pending"}</p>${n.retry_audio_hash?`<audio controls src="audio_serve.php?hash=${esc(n.retry_audio_hash)}&v=${Date.now()}" style="width:100%"></audio>`:""}</div>`);
};

const captureRetryValidate=validate;
validate=function(){
    let errors=captureRetryValidate();
    ids().forEach(id=>{
        let n=flow.nodes[id];
        if(n.type==="capture_stt"&&Number(n.retry_count||0)>0&&(!n.retry_text||!n.retry_audio)){
            errors.push(id+": genere audio retry STT.");
        }
    });
    return errors;
};

generateRetryAudio=async function(){
    let n=flow.nodes[selected],state=$("retryAudioState");
    if(!n||!(n.type==="menu"||n.type==="capture_stt")||!n.retry_text)return;
    state.textContent="generando...";
    try{
        let d=await(await fetch("generate_audio.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({text:n.retry_text})})).json();
        if(!d.ok)throw new Error(d.error);
        n.retry_audio=d.audio;
        n.retry_audio_hash=d.hash;
        $("status").textContent=(d.cached?"Cache hit ":"Audio retry generado ")+(d.duration_ms/1000).toFixed(2)+" s";
        render();
    }catch(e){
        state.textContent="Error: "+e.message;
    }
};
