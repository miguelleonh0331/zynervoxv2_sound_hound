const puppeteer = require('puppeteer-core');
const https = require('https');

/**
 * Motor ViciDial-JS
 * Gestiona la instancia de navegador y las interacciones con el DOM original.
 */
class ViciEngine {
    constructor() {
        this.browser = null;
        this.page = null;
        this.selectors = {
            hangup: 'a:has-text("HANGUP CUSTOMER")',
            pause: '#MainStatuS',
            firstName: '#first_name',
            lastName: '#last_name',
            manualDial: 'a:has-text("MANUAL DIAL")',
            statusText: '#MainStatuSSpan'
        };
    }

    async start(url) {
        console.log("ViciDial-JS: Preparando entorno (Limpieza de sesiones previas)...");
        
        // 0. LIMPIEZA PREVIA SILENCIOSA
        try {
            const serverIp = new URL(url).hostname;
            const params = new URLSearchParams(url.split('?')[1]);
            const agentUser = params.get('VD_login');
            if (agentUser) await this.silentLogout(serverIp, agentUser);
        } catch (e) {
            console.warn("Aviso: No se pudo realizar limpieza inicial:", e.message);
        }

        console.log("ViciDial-JS: Iniciando motor Puppeteer...");
        try {
            this.browser = await puppeteer.launch({
                executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
                headless: true,
                args: [
                    '--ignore-certificate-errors',
                    '--start-maximized',
                    '--no-sandbox',
                    '--use-fake-ui-for-media-stream',
                    '--use-fake-device-for-media-stream',
                    '--autoplay-policy=no-user-gesture-required'
                ]
            });

            this.page = await this.browser.newPage();
            
            // Manejo automático de diálogos alert/confirm
            this.page.on('dialog', async dialog => {
                console.log(`ViciDial Alert: [${dialog.type()}] ${dialog.message()}`);
                await dialog.accept();
            });

            await this.page.goto(url, { waitUntil: 'networkidle2' });
            console.log("ViciDial-JS: Navegación completada. Detectando estado de login...");

            // --- AUTO-LOGIN INJECTION ---
            try {
                // 1. Detectar si estamos en la pantalla de login de Agente
                const loginVisible = await this.page.waitForSelector('#VD_login', { timeout: 3000 }).catch(() => null);
                if (loginVisible) {
                    console.log("ViciDial-JS: Formulario de Login detectado. Autocompletando...");
                    const params = new URLSearchParams(new URL(url).search);
                    const user = params.get('VD_login') || '10457765'; // Fallback al agente de prueba
                    const pass = params.get('VD_pass') || 'Persepolis';

                    await this.page.type('#VD_login', user);
                    await this.page.type('#VD_pass', pass);
                    await this.page.click('#SUBMIT');
                    console.log("ViciDial-JS: Formulario enviado.");
                }

                // 2. Manejar posible pantalla de Login de Teléfono (Phone Login)
                const phoneLoginVisible = await this.page.waitForSelector('input[name="phone_login"]', { timeout: 3000 }).catch(() => null);
                if (phoneLoginVisible) {
                    console.log("ViciDial-JS: Pantalla de Teléfono detectada. Enviando...");
                    await this.page.click('input[type="submit"]');
                }
            } catch (e) {
                console.log("ViciDial-JS: Login saltado (posiblemente ya dentro o error en selectores).");
            }

            console.log("ViciDial-JS: Esperando estabilización...");

            // 1. Manejar el popup de "Conflict" o Session Alerts que no son nativos
            try {
                await this.page.waitForSelector('input[value="OK"]', { timeout: 5000 });
                await this.page.click('input[value="OK"]');
                console.log("ViciDial-JS: Alerta de conflicto descartada.");
            } catch (e) { }

            // 2. Forzar estado ACTIVO para aparecer en reportes live
            try {
                const status = await this.getStatus();
                if (status.status && status.status.includes("PAUSED")) {
                    console.log("ViciDial-JS: Agente en PAUSA. Cambiando a ACTIVO...");
                    await this.page.click(this.selectors.pause);
                }
            } catch (e) { }

            console.log("ViciDial-JS: Sesionada y activa correctamente.");
            return true;
        } catch (err) {
            console.error("Error crítico al iniciar motor:", err);
            throw err;
        }
    }

    async silentLogout(serverIp, agentUser) {
        return new Promise((resolve) => {
            const adminUser = '6666';
            const adminPass = 'Persepolis';
            
            // EL CAMINO MÁS EFECTIVO DETECTADO: user_status.php?stage=log_agent_out
            // Esto limpia Tablas SQL (vicidial_live_agents) y saca al agente de Asterisk
            const apiUrl = `https://${serverIp}/vicidial/user_status.php?user=${agentUser}&stage=log_agent_out`;

            console.log(`ViciDial-JS: [DEEP CLEAN] Solicitando Logout de Emergencia para ${agentUser}...`);
            console.log(`ViciDial-JS: [DEEP CLEAN] URL: ${apiUrl}`);

            // Preparar Auth Básico
            const auth = 'Basic ' + Buffer.from(adminUser + ':' + adminPass).toString('base64');
            
            const options = {
                rejectUnauthorized: false,
                headers: {
                    'Authorization': auth
                }
            };

            const req = https.get(apiUrl, options, (res) => {
                let data = '';
                res.on('data', (chunk) => { data += chunk; });
                res.on('end', () => {
                    const cleanOutput = data.replace(/<[^>]*>?/gm, '').trim(); // Limpiar HTML para log
                    console.log(`ViciDial-JS: [DEEP CLEAN] Respuesta ViciDial (Status ${res.statusCode}): ${cleanOutput.substring(0, 100)}...`);
                    resolve();
                });
            });

            req.on('error', (e) => {
                console.warn(`ViciDial-JS: [DEEP CLEAN] Error en conexión (¿Servidor caído?): ${e.message}`);
                resolve();
            });

            req.end();
        });
    }

    async hangup() {
        console.log("ViciDial-JS: Ejecutando Hangup...");
        try {
            await this.page.click('a[href*="Hangup"]');
            return { success: true };
        } catch (e) {
            return { success: false, error: e.message };
        }
    }

    async togglePause() {
        console.log("ViciDial-JS: Alternando Pausa/Activo...");
        try {
            await this.page.click(this.selectors.pause);
            return { success: true };
        } catch (e) {
            return { success: false, error: e.message };
        }
    }

    async logout(targetUser) {
        console.log(`ViciDial-JS: Solicitud de Logout Total para agente: ${targetUser || 'autodetect'}...`);
        try {
            let serverIp = '192.168.1.192';
            let agentUser = targetUser;

            if (this.page) {
                const url = this.page.url();
                serverIp = new URL(url).hostname;
                if (!agentUser) {
                    const params = new URLSearchParams(url.split('?')[1]);
                    agentUser = params.get('VD_login');
                }
            }

            if (agentUser) {
                await this.silentLogout(serverIp, agentUser);
            }

            if (this.browser) {
                await this.browser.close();
                this.browser = null;
                this.page = null;
            }
            return { success: true };
        } catch (e) {
            console.error("Error en logout:", e);
            if (this.browser) await this.browser.close();
            this.browser = null;
            return { success: true };
        }
    }

    async getStatus() {
        if (!this.page) return { status: 'OFFLINE' };
        try {
            const text = await this.page.$eval(this.selectors.statusText, el => el.innerText);
            return { status: text };
        } catch (e) {
            return { status: 'UNKNOWN' };
        }
    }
}

module.exports = new ViciEngine();
