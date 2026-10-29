const server = require('./server');
const engine = require('./engine');

console.log("========================================");
console.log("      VICIDIAL-JS HEADLESS GATEWAY      ");
console.log("========================================");
console.log("Cargando motor...");

// Link de auto-login proporcionado por el usuario
const AUTO_LOGIN_URL = "https://192.168.1.192/agc/vicidial.php?relogin=no&VD_login=10457765&VD_campaign=00004&phone_login=10457765&phone_pass=ngFoavIybh37nM8&VD_pass=PLUSER001";

(async () => {
    try {
        console.log("Iniciando instancia automatizada...");
        await engine.start(AUTO_LOGIN_URL);
        console.log("\nViciDial-JS está listo.");
        console.log("Comandos disponibles:");
        console.log(" - POST http://localhost:3000/api/hangup");
        console.log(" - POST http://localhost:3000/api/pause");
        console.log(" - GET  http://localhost:3000/api/status");
        
        // Mantener viva la IIFE por si acaso, aunque Express debería hacerlo
        setInterval(() => {
            if (engine.page) {
                // Pequeño heartbeat para asegurar que el proceso no se considere inactivo
            }
        }, 1000 * 60);

    } catch (e) {
        console.error("Error al iniciar ViciDial-JS:", e);
    }
})();

// Evitar cierres inesperados del proceso
process.on('SIGINT', () => {
    console.log("Gateway deteniéndose...");
    process.exit();
});
