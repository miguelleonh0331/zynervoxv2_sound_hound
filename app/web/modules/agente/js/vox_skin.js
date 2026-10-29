(function() {
    console.log("VOX Skin: Real-time UI modernization active.");

    function modernizeDOM() {
        // 1. Agregar el Header Moderno
        if (!document.getElementById('vox-header')) {
            const header = document.createElement('div');
            header.id = 'vox-header';
            header.innerHTML = `
                <div class="vox-logo">VOX SPHERE<span>AGENT</span></div>
                <div class="vox-status-pills">
                    <span id="vox-timer">00:00:00</span>
                    <span id="vox-agent-id">AGENT</span>
                </div>
            `;
            document.body.prepend(header);
        }

        // 2. Mapear valores originales a los modernos
        const originalTimer = document.getElementById('SecondsDisplay');
        if (originalTimer) {
            document.getElementById('vox-timer').innerText = originalTimer.innerText;
        }

        const agentID = document.getElementById('AgentIDspan');
        if (agentID) {
            document.getElementById('vox-agent-id').innerText = agentID.innerText;
        }

        // 3. Ocultar el Logo de ViciDial y el espaciado feo del tope
        const logoImg = document.querySelector('img[src*="vicidial_logo"]');
        if (logoImg) logoImg.style.display = 'none';

        // 4. Estilizar las tablas de grupo
        document.querySelectorAll('table').forEach(tbl => {
            if (tbl.width === "100%" || tbl.width === "760" || tbl.width === "800") {
                tbl.style.width = "95%";
                tbl.style.margin = "0 auto";
            }
        });
    }

    // Ejecutar inmediatamente y luego en intervalo para capturar cambios dinámicos de ViciDial
    modernizeDOM();
    setInterval(modernizeDOM, 1000);

    // Sobrecarga de alert() para que no bloquee (Opcional, precaución con ViciDial)
    /*
    window.alert = function(msg) {
        console.log("VOX ALERTA: " + msg);
        // Aquí podríamos disparar un toast moderno
    };
    */
})();
