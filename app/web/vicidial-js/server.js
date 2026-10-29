const express = require('express');
const cors = require('cors');
const engine = require('./engine');

const app = express();
const port = 3000;

app.use(cors());
app.use(express.json());

// Endpoint de Inicio (Abrir ViciDial)
app.post('/api/start', async (req, res) => {
    const { url } = req.body;
    try {
        await engine.start(url);
        res.json({ success: true, message: "Motor iniciado y logueado." });
    } catch (e) {
        res.status(500).json({ success: false, error: e.message });
    }
});

// Endpoint para Colgar
app.post('/api/hangup', async (req, res) => {
    const result = await engine.hangup();
    res.json(result);
});

// Endpoint para Pausa
app.post('/api/pause', async (req, res) => {
    const result = await engine.togglePause();
    res.json(result);
});

// Endpoint de Logout (Ahora soporta cualquier método para compatibilidad)
app.all('/api/logout', async (req, res) => {
    try {
        const agentUser = req.body.user || req.query.user;
        console.log(`ViciDial-JS: Petición de Logout recibida [${req.method}] para agente: ${agentUser}`);
        
        const result = await engine.logout(agentUser);
        res.json(result);
    } catch (e) {
        console.error("Error en endpoint logout:", e);
        res.status(500).json({ success: false, error: e.message });
    }
});

app.get('/api/status', async (req, res) => {
    const result = await engine.getStatus();
    res.json(result);
});

app.listen(port, () => {
    console.log(`ViciDial-JS Gateway escuchando en http://localhost:${port}`);
});
