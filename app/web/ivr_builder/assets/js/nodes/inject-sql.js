/* Inject SQL: extensión aislada sobre el builder existente. */
const injectSqlAllowedColumns=["ID","FECHA","TELEFONO_BOT","ESTADO","RESULTADO","CLIENTE","NUMERO_CLIENTE","DIRECCION","TIENDA","MONTO","FECHA_AGENDADA","RESPUESTA_REGISTRADA","FECHA_INDICADA","HORA_VISITA","MINUTO_VISITA","ID_CONTACTO","CAMPAÑA","COLA","CALL_ID","DURACION_LLAMADA","PROVEEDOR","ID_CLASE","ID_USUARIO","FECHA_DE_GESTION","HORA_GESTION","HORA_INICIA_GESTION","HORA_FIN_GESTION","NUM_DOC","PRI_NOMBRE","SEG_NOMBRE","APE_PATERNO","APE_MATERNO","ID_TIPIFICACION_01","ID_TIPIFICACION_02","ID_TIPIFICACION_03","ID_AGENCIA"];
const injectSqlDefaultFields={"TELEFONO_BOT":"{numero}","NUMERO_CLIENTE":"{numero}","ESTADO":"EN_PROCESO","CALL_ID":"{call_id}","CAMPAÑA":"{campaign_id}","COLA":"{queue_id}"};
const injectSqlBaseAdd=add;
add=function(type,x,y){injectSqlBaseAdd(type,x,y);if(type==="inject_sql"){let n=flow.nodes[selected];n.message="Inject SQL";n.inject_sql_fields={...injectSqlDefaultFields};render()}};
function setInjectSqlFields(text){try{let value=JSON.parse(text);if(!value||Array.isArray(value)||typeof value!=="object")throw new Error("Debe ser un objeto JSON");flow.nodes[selected].inject_sql_fields=value;$('status').textContent="Mapeo SQL válido";render()}catch(error){$('status').textContent="Mapeo SQL inválido: "+error.message}}
const injectSqlBaseProps=props;
props=function(){injectSqlBaseProps();let n=flow.nodes[selected];if(!n||n.type!=="inject_sql")return;let form=document.querySelector("#props .form");if(!form)return;let typeSelect=form.querySelector("select");if(typeSelect&&!Array.from(typeSelect.options).some(option=>option.value==="inject_sql")){let option=document.createElement("option");option.value="inject_sql";option.textContent="inject_sql";option.selected=true;typeSelect.appendChild(option)}let panel=document.createElement("div");panel.className="help";panel.innerHTML='<b>Inject SQL · dbo.GESTIONES_BOT</b><label>Mapeo columna → plantilla<textarea id="injectSqlFields" spellcheck="false"></textarea></label><p class="small">Variables: {numero}, {telefono}, {call_id}, {flow_code}, {flow_name}, {campaign_id}, {queue_id} y cualquier variable capturada. Vacío se inserta como NULL.</p><p class="small">Columnas permitidas: '+esc(injectSqlAllowedColumns.join(", "))+'</p>';let textarea=panel.querySelector("textarea");textarea.value=JSON.stringify(n.inject_sql_fields||{},null,2);textarea.addEventListener("change",()=>setInjectSqlFields(textarea.value));let timeoutLabel=Array.from(form.querySelectorAll("label")).find(label=>label.textContent.trim().startsWith("Timeout"));form.insertBefore(panel,timeoutLabel||null)};
const injectSqlBaseValidate=validate;
validate=function(){let errors=injectSqlBaseValidate();ids().forEach(id=>{let n=flow.nodes[id];if(n.type!=="inject_sql")return;let fields=n.inject_sql_fields||{};if(!Object.keys(fields).length)errors.push(id+": agregue al menos una columna SQL.");Object.keys(fields).forEach(column=>{if(!injectSqlAllowedColumns.includes(column.toUpperCase()))errors.push(id+": columna SQL inválida "+column);if(String(fields[column]??"").trim()==="")errors.push(id+": plantilla SQL vacía para "+column)});if(!n.next)errors.push(id+": conecte ok.");if(!n.fallback)errors.push(id+": conecte error.")});return errors};
/* Inject SQL: adapta las rutinas visuales heredadas de execute. */
function injectSqlAsExecute(callback){
    let changed=[];
    ids().forEach(id=>{let n=flow.nodes[id];if(n.type==="inject_sql"){changed.push(n);n.type="execute"}});
    try{return callback()}finally{changed.forEach(n=>n.type="inject_sql")}
}
line=function(a,from,key,to){
    if(!flow.nodes[to])return;
    let p1=dotPoint(from,key),p2=dotPoint(to,"",true);
    if(!p1||!p2)return;
    let m=(p1.x+p2.x)/2;
    let c=key==="next"||key==="valid"||key==="ok"?"next":key==="timeout"||key==="invalid"||key==="error"?"timeout":"if";
    a.push(`<path class="edge ${c}" onclick="unlink('${esc(from)}','${esc(key)}')" d="${curve(p1.x,p1.y,p2.x,p2.y)}"/><text class=edge-label x="${m}" y="${(p1.y+p2.y)/2-4}">${esc(key)}</text>`)
};
const injectSqlBaseDraw=draw;
draw=function(){return injectSqlAsExecute(()=>injectSqlBaseDraw())};
const injectSqlBaseExportFlow=exportFlow;
exportFlow=function(format){return injectSqlAsExecute(()=>injectSqlBaseExportFlow(format))};
const injectSqlConnectionProps=props;
props=function(){
    injectSqlConnectionProps();
    let n=flow.nodes[selected];
    if(!n||n.type!=="inject_sql")return;
    let heading=Array.from(document.querySelectorAll("#props strong")).find(item=>item.textContent==="Conexiones");
    for(let row=heading?.nextElementSibling;row&&row.tagName==="P";row=row.nextElementSibling){
        let textNode=Array.from(row.childNodes).find(item=>item.nodeType===Node.TEXT_NODE);
        if(textNode)textNode.nodeValue=textNode.nodeValue.replace(/^next/,"ok").replace(/^timeout/,"error")
    }
};
