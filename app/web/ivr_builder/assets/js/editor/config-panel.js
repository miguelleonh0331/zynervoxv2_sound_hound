async function openConfigPanel(){
  $("configOverlay").hidden=false;
  try{
    let d=await(await fetch("config_api.php?action=get",{cache:"no-store"})).json();
    if(!d.ok)return;
    let c=d.config;
    window.ivrConfigCsrf=d.csrf;
    $("cfgAstUrl").value=c.asterisk_api_url||"";
    $("cfgAstToken").value=c.asterisk_api_token||"";
  }catch(e){}
}
function closeConfigPanel(){$("configOverlay").hidden=true}

async function postConfig(action,payload){
  let d=await(await fetch("config_api.php",{method:"POST",headers:{"Content-Type":"application/json","X-CSRF-Token":window.ivrConfigCsrf||""},body:JSON.stringify({action,...payload})})).json();
  return d;
}

async function testAsteriskConfig(){
  let el=$("cfgAstStatus");el.className="small";el.textContent="probando...";
  let d=await postConfig("test_asterisk",{asterisk_api_url:$("cfgAstUrl").value.trim(),asterisk_api_token:$("cfgAstToken").value});
  el.className=d.ok?"small status":"small error";el.textContent=d.ok?d.message:d.error;
}
async function saveAsteriskConfig(){
  let el=$("cfgAstStatus");el.className="small";el.textContent="guardando...";
  let d=await postConfig("save_asterisk",{asterisk_api_url:$("cfgAstUrl").value.trim(),asterisk_api_token:$("cfgAstToken").value});
  el.className=d.ok?"small status":"small error";el.textContent=d.ok?d.message:d.error;
}
