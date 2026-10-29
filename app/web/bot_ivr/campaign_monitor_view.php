<?php
require_once __DIR__.'/db.php';
if(session_status()===PHP_SESSION_NONE)session_start();
if(empty($_SESSION['user']) || !in_array((int)($_SESSION['user_level']??0),[7,8,9],true)){http_response_code(403);exit;}
$monitorError='';$snapshot=[];
try{$snapshot=bot_ivr_repository()->campaignMonitor();}catch(Throwable $e){$monitorError='No se pudo consultar las campanas.';}
$monitorTitle=$monitorTitle??'Monitor de campanas';
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php echo htmlspecialchars($monitorTitle,ENT_QUOTES,'UTF-8'); ?> - Zynervox</title>
<link rel="stylesheet" href="layout.css">
<style>
.monitor-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.campaign-card{background:var(--bg-card);border:1px solid var(--border);border-top:4px solid #6b7280;border-radius:8px;padding:18px}.campaign-card[data-state="running"],.campaign-card[data-state="starting"]{border-top-color:#10b981}.campaign-card[data-state="stopping"]{border-top-color:#f59e0b}.campaign-card[data-state="failed"]{border-top-color:#ef4444}.campaign-head{display:flex;justify-content:space-between;gap:12px;align-items:center}.campaign-head a{color:var(--text);font-weight:bold;text-decoration:none}.campaign-state{padding:5px 9px;background:var(--glass);border-radius:12px;font-size:12px}.campaign-counts{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:18px 0}.campaign-counts div{text-align:center;background:var(--glass);padding:12px;border-radius:6px}.campaign-counts strong{display:block;font-size:26px}.campaign-counts span,.campaign-list{font-size:12px;color:var(--text-muted)}.campaign-list{margin-top:14px}.campaign-list a{color:var(--primary);text-decoration:none}.campaign-list-head{display:flex;justify-content:space-between;gap:12px}progress{display:block;width:100%;height:12px;margin-top:8px;accent-color:#10b981}.monitor-error{color:#c74451;font-size:13px}.monitor-note{color:var(--text-muted);font-size:12px;margin:10px 0 20px}@media(max-width:900px){.monitor-grid{grid-template-columns:1fr}}
</style></head><body>
<?php if((int)($_SESSION['user_level']??0)===9){require_once __DIR__.'/../modules/admin/sidebar.php';renderSidebar($monitorTitle==='Dashboard'?'home':'campaign_monitor');}else{require_once __DIR__.'/../modules/sup/sidebar.php';renderSupSidebar('campaign_monitor');} ?>
<main class="main-content"><h1><?php echo htmlspecialchars($monitorTitle,ENT_QUOTES,'UTF-8'); ?></h1>
<p class="monitor-note">Estado de las campanas IVR &middot; actualizacion cada 5 segundos</p>
<p id="monitor-error" class="monitor-error" role="status"><?php echo htmlspecialchars($monitorError,ENT_QUOTES,'UTF-8'); ?></p>
<div id="monitor-campaigns" class="monitor-grid"></div>
</main><script>
(() => {
 const grid=document.getElementById('monitor-campaigns'),error=document.getElementById('monitor-error');let timer;
 const labels={idle:'Sin marcacion',starting:'Iniciando',running:'Discando',stopping:'Deteniendo',stopped:'Detenida',completed:'Finalizada',failed:'Error'};
 function element(tag,text,cls){const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;}
 function render(rows){grid.replaceChildren();
  if(!rows.length){grid.append(element('p','No hay campanas activas.'));return;}
  for(const c of rows){
   const card=element('section',undefined,'campaign-card');card.dataset.state=c.state;
   const head=element('div',undefined,'campaign-head');const link=element('a','#'+c.campaign_id+' - '+c.name);link.href='../../bot_ivr/campaign_edit.php?id='+c.campaign_id;
   head.append(link,element('span',labels[c.state],'campaign-state'));card.append(head);
   const counts=element('div',undefined,'campaign-counts');for(const [key,label] of [['total','Total leads'],['calling','Llamando'],['finished','Terminados']]){const box=element('div');box.append(element('strong',c[key]),element('span',label));counts.append(box);}card.append(counts);
   card.append(element('h3','Resumen por estado del lead','campaign-list'));
   for(const status of c.states){const row=element('div',undefined,'campaign-list');const label=element('div',undefined,'campaign-list-head');label.append(element('span',status.status),element('strong',status.count));const bar=element('progress');bar.max=Math.max(1,c.total);bar.value=status.count;bar.setAttribute('aria-label',status.status+': '+status.count);row.append(label,bar);card.append(row);}
   if(!c.states.length)card.append(element('p','Sin leads cargados.','campaign-list'));
   for(const l of c.lists){const row=element('div',undefined,'campaign-list'),head=element('div',undefined,'campaign-list-head');const a=element('a','#'+l.list_id+' - '+l.name);a.href='../../bot_ivr/list_edit.php?id='+l.list_id+'&campaign_id='+c.campaign_id;
    head.append(a,element('span',labels[l.state]+' | '+l.calling+' llamando'+(['running','starting','stopping'].includes(l.state)?' / '+l.channels+' canales':'')));const bar=element('progress');bar.max=Math.max(1,l.total);bar.value=l.finished;bar.setAttribute('aria-label','Terminados '+l.finished+' de '+l.total);row.append(head,bar);if(l.error)row.append(element('p',l.error,'monitor-error'));card.append(row);
   }grid.append(card);
  }
 }
 async function poll(){clearTimeout(timer);if(document.hidden)return;try{const response=await fetch('campaign_monitor_api.php',{cache:'no-store',credentials:'same-origin'});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'No se pudo actualizar el monitor.');render(data.campaigns);error.textContent='';}catch(e){error.textContent=e.message;}timer=setTimeout(poll,5000);}
 render(<?php echo json_encode($snapshot,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>);timer=setTimeout(poll,5000);
 document.addEventListener('visibilitychange',()=>{clearTimeout(timer);if(!document.hidden)poll();});
})();
</script></body></html>
