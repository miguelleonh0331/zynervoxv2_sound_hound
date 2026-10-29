/* 
 * VOX SPHERE MOBILE - INTERFAZ OPTIMIZADA PARA PULGAR
 * Inyectado en vicidial-mobile.php
 */

(function() {
    console.log("VOX SPHERE MOBILE: Iniciando interfaz táctil...");
    document.title = "VOX SPHERE - MOBILE";

    // 1. ESTILOS MÓVILES (Optimizado para pulgar y flujo dinámico)
    const style = document.createElement('style');
    style.innerHTML = `
        /* Ocultar UI original */
        body > center, body > table, body > span#Header, body > span#Tabs, body > span#MainPanel, body > form#vicidial_form {
            opacity: 0 !important; visibility: hidden !important; pointer-events: none !important;
            height: 0 !important; overflow: hidden !important;
        }

        body {
            background: #0f172a !important; margin: 0; padding: 0;
            font-family: 'Inter', -apple-system, blinkmacsystemfont, sans-serif;
            overflow: hidden; touch-action: manipulation;
        }

        #vox-mobile-ui {
            position: fixed; inset: 0; z-index: 999999 !important;
            display: flex; flex-direction: column; background: #0f172a; color: white;
            height: 100%; width: 100%;
        }

        .mobile-header {
            flex-shrink: 0; padding: 20px; background: rgba(255,255,255,0.03);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            display: flex; justify-content: space-between; align-items: center;
        }

        .client-info-big {
            flex-grow: 1; display: flex; flex-direction: column;
            justify-content: center; align-items: center; text-align: center;
            padding: 20px;
        }

        .customer-name { font-size: 3.5rem; font-weight: 900; margin-bottom: 10px; color: #f8fafc; letter-spacing: -2px; }
        .customer-phone { font-size: 2.2rem; color: #38bdf8; font-weight: 300; margin-bottom: 25px; }

        /* DOCK DE ACCIONES XL */
        .action-dock {
            flex-shrink: 0; background: #1e293b;
            padding: 40px 25px calc(env(safe-area-inset-bottom, 20px) + 30px) 25px;
            border-top: 1px solid rgba(255,255,255,0.1);
            display: flex; flex-direction: column; gap: 20px;
            border-radius: 50px 50px 0 0;
            box-shadow: 0 -20px 60px rgba(0,0,0,0.7);
        }

        .xl-btn {
            width: 100%; height: 130px; border-radius: 35px; border: none;
            font-weight: 900; font-size: 1.6rem; color: white;
            display: flex; align-items: center; justify-content: center; gap: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.4);
            text-transform: uppercase;
            transition: transform 0.1s, filter 0.1s;
        }
        .xl-btn:active { transform: scale(0.95); filter: brightness(1.2); }

        .btn-call { background: linear-gradient(135deg, #10b981, #059669); }
        .btn-hangup { background: linear-gradient(135deg, #ef4444, #b91c1c); display:none; }
        .btn-dispo { display:none; height: 110px; font-size: 1.4rem; }
        .btn-contacto { background: #38bdf8; }
        .btn-ncontacto { background: #64748b; }

        .util-btn {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);
            color: #94a3b8; padding: 15px 25px; border-radius: 20px; font-size: 14px; font-weight: bold;
        }

        .mobile-modal {
            position: fixed; inset: 0; background: rgba(0,0,0,0.95);
            z-index: 1000001; display: none; flex-direction: column; padding: 30px;
        }
        .client-list { overflow-y: auto; flex-grow: 1; display: flex; flex-direction: column; gap: 10px; margin-top:20px; }
        .client-item { background: #1e293b; padding: 20px; border-radius: 20px; display: flex; justify-content: space-between; align-items:center; }
        
        /* DIALPAD GRID */
        .dialpad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 30px; }
        .dial-btn { 
            height: 90px; border-radius: 25px; background: rgba(255,255,255,0.05); 
            border: 1px solid rgba(255,255,255,0.1); color: white; font-size: 2rem; font-weight: 900;
            display: flex; align-items: center; justify-content: center;
        }
        .dial-btn:active { background: #38bdf8; color: #0f172a; transform: scale(0.9); }
        .dial-display { 
            font-size: 3rem; text-align: center; margin-bottom: 20px; font-weight: 900; 
            color: #38bdf8; letter-spacing: 5px; min-height: 60px;
        }
    `;
    document.head.appendChild(style);

    // 2. CREAR DOM MÓVIL
    const ui = document.createElement('div');
    ui.id = 'vox-mobile-ui';
    ui.innerHTML = `
        <div class="mobile-header">
            <span style="font-weight:900; color:#38bdf8; font-size:1.2rem;">VOX SPHERE</span>
            <div id="m-status" style="background:#10b981; padding:6px 15px; border-radius:12px; font-size:12px; font-weight:bold;">READY</div>
        </div>

        <div class="client-info-big">
            <div id="m-cust-name" class="customer-name">ESPERANDO...</div>
            <div style="width: 100%; max-width: 500px; margin-bottom: 25px;">
                <input type="tel" id="m-cust-phone-input" class="customer-phone" placeholder="INGRESE NÚMERO" 
                    style="background:none; border:none; border-bottom:2px solid rgba(56,189,248,0.3); width:100%; text-align:center; outline:none; color:#38bdf8;">
            </div>
            <div style="display:flex; gap:10px; margin-top:10px;">
                <button onclick="window.voxShowClientList()" style="background:rgba(56,189,248,0.15); border:2px solid rgba(56,189,248,0.3); color:#38bdf8; padding:20px 30px; border-radius:25px; font-size:16px; font-weight:900; letter-spacing:1px; flex:1;">👥 AGENDA</button>
                <button onclick="window.voxShowDialer()" style="background:rgba(16,185,129,0.15); border:2px solid rgba(16,185,129,0.3); color:#10b981; padding:20px 30px; border-radius:25px; font-size:16px; font-weight:900; letter-spacing:1px; flex:1;">🔢 TECLADO</button>
            </div>
        </div>

        <div class="action-dock" id="m-action-dock">
            <!-- ESTADO 1: READY -->
            <button id="btn-main-call" class="xl-btn btn-call" onclick="window.voxGsmCall(document.getElementById('m-cust-phone-input').value)">
                <span style="font-size:48px;">📞</span> LLAMAR GSM
            </button>
            
            <!-- ESTADO 2: EN LLAMADA -->
            <button id="btn-main-hangup" class="xl-btn btn-hangup" onclick="window.voxHangup()">
                <span style="font-size:48px;">🛑</span> COLGAR LLAMADA
            </button>

            <!-- ESTADO 3: TIPIFICAR -->
            <div id="dispo-group" style="display:none; flex-direction:column; gap:15px; width:100%;">
                <h3 style="text-align:center; margin-bottom:10px; color:#38bdf8; font-size:1.2rem; font-weight:900;">¿QUÉ PASÓ EN LA LLAMADA?</h3>
                <button class="xl-btn btn-dispo btn-contacto" onclick="window.voxSetDisposition('Contacto')">🤝 CONTACTO</button>
                <button class="xl-btn btn-dispo btn-ncontacto" onclick="window.voxSetDisposition('NContacto')">❌ NO CONTACTO</button>
            </div>

            <div style="display:flex; justify-content:space-between; gap:15px; margin-top:10px;">
                <button onclick="window.voxTogglePause()" id="m-btn-pause" class="util-btn" style="flex:1;">⏸️ PAUSAR</button>
                <button onclick="window.voxShowDetails()" class="util-btn" style="flex:1; background:rgba(56,189,248,0.1); border-color:rgba(56,189,248,0.2); color:#38bdf8;">📋 VER FICHA</button>
                <button onclick="window.voxLogout()" class="util-btn" style="background:none; border-color:rgba(239,68,68,0.3); color:#ef4444;">🚪 SALIDA</button>
            </div>
        </div>

        <div id="m-client-modal" class="mobile-modal">
            <h2 style="color:#38bdf8; text-align:center;">CLIENTES RECIENTES</h2>
            <div id="m-client-list" class="client-list"></div>
            <button onclick="document.getElementById('m-client-modal').style.display='none'" style="margin-top:20px; padding:20px; background:#1e293b; color:white; border:none; border-radius:20px;">CERRAR</button>
        </div>

        <div id="m-details-modal" class="mobile-modal">
            <div id="m-details-content" style="width:100%;"></div>
            <button onclick="document.getElementById('m-details-modal').style.display='none'" style="margin-top:20px; padding:20px; background:#ef4444; color:white; border:none; border-radius:20px; width:100%; font-weight:bold;">CERRAR FICHA</button>
        </div>

        <!-- MODAL TECLADO -->
        <div id="m-dial-modal" class="mobile-modal">
            <h2 style="color:#38bdf8; text-align:center; font-weight:900;">TECLADO NUMÉRICO</h2>
            <div id="m-dial-display" class="dial-display"></div>
            <div class="dialpad">
                <button class="dial-btn" onclick="window.voxDialAdd('1')">1</button>
                <button class="dial-btn" onclick="window.voxDialAdd('2')">2</button>
                <button class="dial-btn" onclick="window.voxDialAdd('3')">3</button>
                <button class="dial-btn" onclick="window.voxDialAdd('4')">4</button>
                <button class="dial-btn" onclick="window.voxDialAdd('5')">5</button>
                <button class="dial-btn" onclick="window.voxDialAdd('6')">6</button>
                <button class="dial-btn" onclick="window.voxDialAdd('7')">7</button>
                <button class="dial-btn" onclick="window.voxDialAdd('8')">8</button>
                <button class="dial-btn" onclick="window.voxDialAdd('9')">9</button>
                <button class="dial-btn" style="background:#ef444422; border-color:#ef444444; color:#ef4444;" onclick="window.voxDialClear()">⌫</button>
                <button class="dial-btn" onclick="window.voxDialAdd('0')">0</button>
                <button class="dial-btn" style="background:#10b981; border-color:#10b981; color:white;" onclick="window.voxDialStart()">📞</button>
            </div>
            <button onclick="document.getElementById('m-dial-modal').style.display='none'" style="margin-top:40px; padding:20px; background:rgba(255,255,255,0.05); color:#94a3b8; border:1px solid rgba(255,255,255,0.1); border-radius:20px;">CANCELAR</button>
        </div>
    `;
    document.body.appendChild(ui);

    // 3. LOGICA DE CONTROL Y ESTADOS
    let currentLeadId = "";
    let currentClientsData = [];
    let manualSelection = false;

    window.voxSetState = function(state) {
        const btnCall = document.getElementById('btn-main-call');
        const btnHangup = document.getElementById('btn-main-hangup');
        const groupDispo = document.getElementById('dispo-group');
        const btnsDispo = document.querySelectorAll('.btn-dispo');

        // Reset
        btnCall.style.display = 'none';
        btnHangup.style.display = 'none';
        groupDispo.style.display = 'none';
        btnsDispo.forEach(b => b.style.display = 'none');

        if (state === 'READY') {
            btnCall.style.display = 'flex';
        } else if (state === 'INCALL') {
            btnHangup.style.display = 'flex';
        } else if (state === 'DISPO') {
            groupDispo.style.display = 'flex';
            btnsDispo.forEach(b => b.style.display = 'flex');
        }
    };

    window.voxGsmCall = function(phone, index = -1) {
        if (!phone || phone.includes("-")) {
            alert("Seleccione un número válido.");
            return;
        }

        // Si viene de la lista, cargar primero los datos en pantalla
        if (index !== -1) {
            window.voxSelectLead(index);
            document.getElementById('m-client-modal').style.display = 'none';
            // Abrir automáticamente la ventana de detalles importantes
            window.voxShowDetails(index);
        }

        console.log("VOX SPHERE: Disparando llamada GSM a", phone);
        
        // 1. Bridge de marcación manual (Prepara lead y Sincroniza Espejo)
        fetch(`vox_manual_dial.php?phone=${phone.trim()}`)
            .then(res => res.json())
            .then(mirrorData => {
                if (mirrorData.status === 'success') {
                    currentLeadId = mirrorData.lead_id;
                    // 2. Disparar comando GSM real
                    return fetch(`gsm_handler.php?action=CALL&phone=${phone.trim()}`);
                } else {
                    throw new Error(mirrorData.message);
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    window.voxSetState('INCALL');
                    document.getElementById('m-status').innerText = "EN LLAMADA GSM";
                    document.getElementById('m-status').style.background = "#38bdf8";
                } else {
                    alert("Error GSM: " + data.message);
                }
            })
            .catch(err => alert("Error en marcación: " + err.message));
    };

    // 4. LÓGICA DE TECLADO
    window.voxShowDialer = function() {
        document.getElementById('m-dial-display').innerText = "";
        document.getElementById('m-dial-modal').style.display = "flex";
    };

    window.voxDialAdd = function(num) {
        const d = document.getElementById('m-dial-display');
        const mainInput = document.getElementById('m-cust-phone-input');
        if (d.innerText.length < 15) {
            d.innerText += num;
            mainInput.value = d.innerText;
        }
    };

    window.voxDialClear = function() {
        const d = document.getElementById('m-dial-display');
        const mainInput = document.getElementById('m-cust-phone-input');
        d.innerText = d.innerText.slice(0, -1);
        mainInput.value = d.innerText;
    };

    window.voxDialStart = function() {
        const d = document.getElementById('m-dial-display');
        const phone = d.innerText;
        if (phone.length < 5) {
            alert("Número demasiado corto");
            return;
        }
        document.getElementById('m-dial-modal').style.display = "none";
        document.getElementById('m-cust-name').innerText = "MARCACIÓN MANUAL";
        document.getElementById('m-cust-phone').innerText = phone;
        window.voxGsmCall(phone);
    };

    window.voxHangup = function() {
        console.log("VOX SPHERE: Colgando y pasando a tipificación...");
        fetch('gsm_handler.php?action=HANGUP').then(() => {
            window.voxSetState('DISPO');
            document.getElementById('m-status').innerText = "TIPIFICANDO...";
            document.getElementById('m-status').style.background = "#f59e0b";
            
            // Sincronizar con el motor nativo de ViciDial
            const vHangup = document.getElementById('HangupLink') || document.querySelector('a[href*="Hangup"]');
            if (vHangup) vHangup.click();
        });
    };

    window.voxSetDisposition = function(status) {
        if (!currentLeadId) {
            const lField = document.getElementById('lead_id');
            if (lField) currentLeadId = lField.value;
        }

        if (!currentLeadId) {
            alert("No se cargó un ID de lead.");
            window.voxSetState('READY');
            return;
        }

        fetch(`vox_update_lead.php?lead_id=${currentLeadId}&status=${status}`)
            .then(res => res.json())
            .then(data => {
                console.log("Status actualizado:", data);
                manualSelection = false; // Liberar bloqueo
                window.voxSetState('READY');
                document.getElementById('m-status').innerText = "READY";
                document.getElementById('m-status').style.background = "#10b981";
                document.getElementById('m-details-modal').style.display = 'none';
            });
    };

    window.voxShowDetails = function(index = -1) {
        let c = null;
        if (index !== -1 && currentClientsData[index]) {
            c = currentClientsData[index];
        } else {
            const gV = (id) => (document.getElementById(id) || document.getElementsByName(id)[0] || {value:''}).value;
            c = {
                first_name: document.getElementById('m-cust-name').innerText,
                last_name: '',
                phone_number: document.getElementById('m-cust-phone').innerText,
                lead_id: currentLeadId || gV('lead_id'),
                address1: gV('address1'),
                address2: gV('address2'),
                city: gV('city'),
                state: gV('state'),
                email: gV('email'),
                comments: gV('comments'),
                alt_phone: gV('alt_phone'),
                status: gV('status')
            };
        }

        if (!c || (!c.lead_id && !c.phone_number)) {
            alert("No hay datos del lead cargados.");
            return;
        }

        const content = document.getElementById('m-details-content');
        content.innerHTML = `
            <div style="background:rgba(255,255,255,0.05); padding:25px; border-radius:30px; display:flex; flex-direction:column; gap:15px; border:1px solid rgba(255,255,255,0.1); width:100%; box-sizing:border-box;">
                <div style="text-align:center;">
                    <div style="color:#bdf838; font-size:1.5rem; font-weight:900;">${c.first_name} ${c.last_name}</div>
                    <div style="color:#38bdf8; font-size:2.2rem; font-weight:900; margin-top:5px;">${c.phone_number}</div>
                </div>
                
                <hr style="border:0; border-top:1px solid rgba(255,255,255,0.1); margin:10px 0;">
                
                <div style="background:rgba(56,189,248,0.1); padding:20px; border-radius:20px; border:1px solid rgba(56,189,248,0.2);">
                    <small style="color:#38bdf8; font-weight:bold; text-transform:uppercase; font-size:12px;">🏠 DIRECCIÓN PRINCIPAL</small>
                    <div style="font-size:1.4rem; margin-top:8px; font-weight:900; color:#fff;">${c.address1 || '---'}</div>
                    <div style="font-size:1.2rem; margin-top:4px; color:#94a3b8; font-weight:bold;">${c.address2 || ''}</div>
                    <div style="font-size:1.2rem; margin-top:8px; color:#e2e8f0;">📍 ${c.city || ''}, ${c.state || ''}</div>
                </div>

                <div style="background:rgba(16,185,129,0.1); padding:20px; border-radius:20px; border:1px solid rgba(16,185,129,0.2);">
                    <small style="color:#10b981; font-weight:bold; font-size:12px;">📱 TELÉFONO ALTERNATIVO</small>
                    <div style="font-size:1.8rem; margin-top:5px; font-weight:900; color:#fff;">${c.alt_phone || '---'}</div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div style="background:rgba(255,255,255,0.03); padding:15px; border-radius:15px;">
                        <small style="color:#64748b; font-weight:bold; font-size:11px;">ID LEAD</small><br><b style="font-size:1.2rem;">#${c.lead_id}</b>
                    </div>
                </div>
                
                <div style="background:rgba(255,158,11,0.05); padding:20px; border-radius:20px; border:1px solid rgba(255,158,11,0.1);">
                    <small style="color:#f59e0b; font-weight:bold; font-size:12px;">📝 COMENTARIOS</small><br>
                    <div style="font-size:1.1rem; color:#94a3b8; font-style:italic; margin-top:5px;">${c.comments || 'Sin comentarios adicionales.'}</div>
                </div>

                <div id="m-history-section" style="margin-top:10px;">
                    <small style="color:#64748b; font-weight:bold; font-size:12px;">🕒 HISTORIAL RECIENTE (CRM)</small>
                    <div id="m-hist-list" style="margin-top:10px; display:flex; flex-direction:column; gap:10px;">
                        <div style="color:#475569; font-size:0.9rem; text-align:center; padding:15px; background:rgba(255,255,255,0.02); border-radius:15px;">Cargando historial...</div>
                    </div>
                </div>
            </div>
        `;
        document.getElementById('m-details-modal').style.display = 'flex';

        // Cargar historial asíncronamente
        fetch(`vox_get_log.php?lead_id=${c.lead_id}`)
            .then(res => res.json())
            .then(data => {
                const histList = document.getElementById('m-hist-list');
                if (data.status === 'success' && data.data.length > 0) {
                    histList.innerHTML = "";
                    data.data.forEach(log => {
                        const row = document.createElement('div');
                        row.style = "background:rgba(255,255,255,0.03); padding:12px 15px; border-radius:15px; border-left:4px solid " + (log.status === 'Contacto' ? '#10b981' : '#64748b');
                        row.innerHTML = `
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <b style="font-size:1rem; color:${log.status === 'Contacto' ? '#10b981' : '#94a3b8'}">${log.status}</b>
                                <small style="color:#475569; font-size:10px;">${log.event_date}</small>
                            </div>
                            <div style="color:#64748b; font-size:0.8rem; margin-top:3px;">Agente: ${log.user}</div>
                        `;
                        histList.appendChild(row);
                    });
                } else {
                    histList.innerHTML = `<div style="color:#475569; font-size:0.9rem; text-align:center; padding:10px;">Sin interacciones previas.</div>`;
                }
            });
    };

    window.voxShowClientList = function() {
        document.getElementById('m-client-modal').style.display = 'flex';
        fetch('vox_get_customers.php')
            .then(res => res.json())
            .then(data => {
                const list = document.getElementById('m-client-list');
                list.innerHTML = "";
                if (data.status === 'success' && data.data.length > 0) {
                    currentClientsData = data.data;
                    data.data.forEach((c, i) => {
                        const d = document.createElement('div');
                        d.className = "client-item";
                        d.innerHTML = `
                            <div onclick="window.voxSelectLead(${i}); document.getElementById('m-client-modal').style.display='none';" style="flex:1; cursor:pointer;">
                                <div style="font-weight:900; font-size:1.3rem; margin-bottom:5px;">${c.first_name} ${c.last_name}</div>
                                <div style="color:#38bdf8; font-size:1.2rem; font-weight:bold;">${c.phone_number}</div>
                                <div style="color:#64748b; font-size:0.9rem; margin-top:5px;">${c.city || ''} ${c.state || ''}</div>
                            </div>
                            <button onclick="window.voxGsmCall('${c.phone_number}', ${i})" style="background:linear-gradient(135deg, #10b981, #059669); border:none; color:white; width:80px; height:80px; border-radius:25px; font-size:35px; display:flex; align-items:center; justify-content:center; box-shadow:0 10px 20px rgba(0,0,0,0.3);">📞</button>
                        `;
                        list.appendChild(d);
                    });
                }
            });
    };

    window.voxSelectLead = function(i) {
        const c = currentClientsData[i];
        if (!c) return;
        manualSelection = true; // Bloquear Sync Loop
        currentLeadId = c.lead_id;
        document.getElementById('m-cust-name').innerText = (c.first_name + ' ' + c.last_name).trim();
        document.getElementById('m-cust-phone-input').value = c.phone_number;
        window.voxSetState('READY');
        
        // Cargar ficha técnica automáticamente
        window.voxShowDetails(i);
    };

    window.voxTogglePause = function() {
        const img = document.querySelector('#DiaLControl img');
        if (img && typeof AutoDial_ReSume_PauSe === 'function') {
            const isP = img.src.includes('paused');
            AutoDial_ReSume_PauSe(isP ? 'VDADready' : 'VDADpause','','','','','','','YES');
        }
    };

    window.voxLogout = function() {
        if (confirm("¿Cerrar sesión?")) {
            if (typeof LogouT === 'function') LogouT('','','');
            setTimeout(() => location.href = "../../index.php", 1000);
        }
    };

    // 4. SYNC LOOP ROBUSTO
    setInterval(() => {
        if (manualSelection) return; // Si hay selección de agenda, no tocar UI central

        const getVal = (id) => {
            const el = document.getElementById(id) || document.getElementsByName(id)[0];
            return el ? el.value : "";
        };

        const fn = getVal('first_name');
        const ln = getVal('last_name');
        const ph = getVal('phone_number');
        const lid = getVal('lead_id');

        const nameDisplay = document.getElementById('m-cust-name');
        const phoneInput = document.getElementById('m-cust-phone-input');

        if (lid && lid !== "") {
            currentLeadId = lid;
            if (nameDisplay) nameDisplay.innerText = (fn + ' ' + ln).trim() || "SIN NOMBRE";
            if (phoneInput && !manualSelection && document.activeElement !== phoneInput) {
                phoneInput.value = ph || "";
            }
        } else {
            if (nameDisplay) nameDisplay.innerText = "ESPERANDO LEAD...";
            if (phoneInput && !manualSelection && document.activeElement !== phoneInput) {
                phoneInput.value = "";
            }
        }
        
        // Sincronizar estado (Ready/Paused/etc)
        const sSpan = document.getElementById('MainStatuSSpan');
        const sBadge = document.getElementById('m-status');
        if (sSpan && sBadge && !sBadge.innerText.includes("GSM")) {
            sBadge.innerText = sSpan.innerText;
            sBadge.style.background = sSpan.innerText.includes("PAUSED") ? "#f59e0b" : "#10b981";
        }
    }, 1000);

    // Forzar Viewport para Escala 1:1 y evitar zoom automático en inputs
    const meta = document.createElement('meta');
    meta.name = "viewport";
    meta.content = "width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0";
    document.head.appendChild(meta);

    // Inicializar
    window.voxSetState('READY');

})();
