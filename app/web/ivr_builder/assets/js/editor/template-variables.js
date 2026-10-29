(function(){
  "use strict";
  const MIN_COLS=2,MAX_COLS=10;
  let availableVariables=[],autocomplete=null;

  function slug(value){
    return String(value||"").replace(/^\uFEFF/,"").trim().toLocaleLowerCase("es").normalize("NFD")
      .replace(/[\u0300-\u036f]/g,"").replace(/[^a-z0-9_]+/g,"_").replace(/_+/g,"_").replace(/^_+|_+$/g,"");
  }

  function firstCsvRow(text){
    let row=[],field="",quoted=false;
    for(let i=0;i<text.length;i++){
      const char=text[i];
      if(quoted){
        if(char==='"'&&text[i+1]==='"'){field+='"';i++;}
        else if(char==='"')quoted=false;
        else field+=char;
      }else if(char==='"'&&field==='')quoted=true;
      else if(char===','){row.push(field);field="";}
      else if(char==='\r'||char==='\n'){row.push(field);return row;}
      else field+=char;
    }
    row.push(field);
    return row;
  }

  function showVariables(fileName,variables){
    availableVariables=variables;
    $("templateName").textContent=fileName+" · "+variables.length+" variables";
    $("templateVariables").innerHTML=variables.map(variable=>
      '<button type="button" class="variable-chip" title="Copiar variable" data-variable="'+esc(variable)+'">{'+esc(variable)+'}</button>'
    ).join("");
    document.querySelectorAll("#templateVariables .variable-chip").forEach(button=>{
      button.onclick=async()=>{
        const value="{"+button.dataset.variable+"}";
        try{await navigator.clipboard.writeText(value);$("status").textContent=value+" copiada";}
        catch(error){$("status").textContent=value;}
      };
    });
  }

  function hideAutocomplete(){
    document.getElementById("variableAutocomplete")?.remove();
    autocomplete=null;
  }

  function insertVariable(index){
    if(!autocomplete)return;
    const variable=autocomplete.matches[index],field=autocomplete.field;
    if(!variable||!field.isConnected)return hideAutocomplete();
    const cursor=field.selectionStart;
    field.value=field.value.slice(0,autocomplete.start)+"{"+variable+"}"+field.value.slice(cursor);
    const next=autocomplete.start+variable.length+2;
    field.setSelectionRange(next,next);
    field.dispatchEvent(new Event("input",{bubbles:true}));
    hideAutocomplete();
    field.focus();
  }

  function renderAutocomplete(field,start,matches,index=0){
    hideAutocomplete();
    if(!matches.length)return;
    const rect=field.getBoundingClientRect(),menu=document.createElement("div");
    menu.id="variableAutocomplete";
    menu.className="variable-autocomplete";
    menu.setAttribute("role","listbox");
    menu.style.left=Math.max(8,Math.min(rect.left,window.innerWidth-230))+"px";
    menu.style.top=Math.min(rect.bottom+4,window.innerHeight-180)+"px";
    menu.innerHTML=matches.map((variable,i)=>'<button type="button" class="'+(i===index?"active":"")+'" data-index="'+i+'">{'+esc(variable)+'}</button>').join("");
    document.body.appendChild(menu);
    autocomplete={field,start,matches,index};
    menu.querySelectorAll("button").forEach(button=>button.addEventListener("mousedown",event=>{
      event.preventDefault();
      insertVariable(Number(button.dataset.index));
    }));
  }

  function autocompleteMatch(field){
    if(!availableVariables.length||typeof field.selectionStart!=="number")return hideAutocomplete();
    const before=field.value.slice(0,field.selectionStart),match=before.match(/@([a-zA-Z0-9_]*)$/);
    if(!match)return hideAutocomplete();
    const query=match[1].toLowerCase(),matches=availableVariables.filter(variable=>variable.includes(query));
    renderAutocomplete(field,field.selectionStart-match[0].length,matches);
  }

  document.addEventListener("input",event=>{
    const field=event.target.closest?.("#props textarea, #props input:not([type]), #props input[type=text]");
    if(field)autocompleteMatch(field);
  });
  document.addEventListener("keydown",event=>{
    if(!autocomplete||event.target!==autocomplete.field)return;
    if(event.key==="Escape"){event.preventDefault();hideAutocomplete();return;}
    if(event.key==="ArrowDown"||event.key==="ArrowUp"){
      event.preventDefault();
      const direction=event.key==="ArrowDown"?1:-1;
      const index=(autocomplete.index+direction+autocomplete.matches.length)%autocomplete.matches.length;
      renderAutocomplete(autocomplete.field,autocomplete.start,autocomplete.matches,index);
      return;
    }
    if(event.key==="Enter"||event.key==="Tab"){
      event.preventDefault();
      insertVariable(autocomplete.index);
    }
  });
  document.addEventListener("mousedown",event=>{
    if(autocomplete&&!event.target.closest("#variableAutocomplete")&&event.target!==autocomplete.field)hideAutocomplete();
  });

  async function loadTemplate(file){
    if(!file)return;
    try{
      if(!/\.txt$/i.test(file.name))throw new Error("La plantilla debe ser un archivo .txt");
      const bytes=await file.arrayBuffer();
      const text=new TextDecoder("utf-8",{fatal:true}).decode(bytes);
      const header=firstCsvRow(text).map(slug).filter(Boolean);
      if(header.length<MIN_COLS||header.length>MAX_COLS)throw new Error("La plantilla debe tener entre 2 y 10 columnas. Se detectaron: "+header.length+".");
      if(new Set(header).size!==header.length)throw new Error("Hay cabeceras duplicadas después de normalizarlas.");
      if(!header.includes("numero"))throw new Error('Falta la columna obligatoria "numero".');
      showVariables(file.name,header.filter(name=>name!=="numero"));
      $("status").textContent="Plantilla cargada";
    }catch(error){
      $("status").textContent="";
      alert(error instanceof TypeError?"El archivo debe estar codificado en UTF-8.":error.message);
    }finally{$("templateFile").value="";}
  }

  function clearVariables(){
    availableVariables=[];
    $("templateName").textContent="Carga una plantilla TXT o selecciona una lista para ver sus variables.";
    $("templateVariables").innerHTML='<span class="variable-empty">Sin plantilla</span>';
  }

  window.openTemplatePicker=function(){$("templateFile").click();};
  window.showVariables=showVariables;
  window.clearVariables=clearVariables;
  $("templateFile").addEventListener("change",event=>loadTemplate(event.target.files[0]));
})();
