/**
 * VOX SPHERE - Webphone Interface
 * Handles SIP.js registration and calls.
 */

let userAgent;
let session;

const VOXPhone = {
    initialize: async (config) => {
        console.log("VOXPhone: Iniciando con config:", config);
        
        try {
            // Configuración del servidor de transporte (WSS)
            const transportOptions = {
                server: config.wssUrl,
                traceSip: true,
                connectionTimeout: 10,
                keepAliveInterval: 20,
                keepAliveKeepOptional: true
            };

            // Configuración del User Agent
            const userAgentOptions = {
                uri: UserAgent.makeURI(`sip:${config.user}@${config.serverIp}`),
                transportConstructor: Transport,
                transportOptions: transportOptions,
                authorizationUsername: config.user,
                authorizationPassword: config.password,
                displayName: config.displayName,
                gracefulShutdown: true,
                userAgentString: "VOX SPHERE Webphone 1.0",
                // Forzar contact alternativo para evitar problemas de IP privada
                contactName: config.user
            };

            console.log("VOXPhone: Creando UserAgent...");
            userAgent = new UserAgent(userAgentOptions);

            userAgent.stateChange.addListener((newState) => {
                console.log("VOXPhone: UserAgent state change:", newState);
            });

            // Manejadores de eventos de transporte
            userAgent.delegate = {
                onConnect: () => {
                    console.log("VOXPhone: CONECTADO al WebSocket.");
                    VOXPhone.updateStatus("Enlazado", "yellow");
                },
                onDisconnect: (error) => {
                    console.warn("VOXPhone: DESCONECTADO del WebSocket.", error);
                    VOXPhone.updateStatus("Desconectado", "red");
                }
            };

            await userAgent.start();
            console.log("VOXPhone: UserAgent iniciado.");
            
            const registerer = new Registerer(userAgent);
            
            // ESCUCHAR LLAMADAS ENTRANTES (Para la conferencia)
            userAgent.delegate.onInvite = (invitation) => {
                console.log("VOXPhone: LLAMADA ENTRANTE de:", invitation.remoteIdentity.uri.toString());
                
                // Auto-Contestar para entrar a la conferencia
                console.log("VOXPhone: Auto-contestando...");
                
                const constraints = {
                    audio: true,
                    video: false
                };

                const options = {
                    sessionDescriptionHandlerOptions: { constraints }
                };

                invitation.accept(options).then(() => {
                    console.log("VOXPhone: Llamada contestada y audio establecido.");
                    session = invitation;
                    VOXPhone.updateStatus("EN CONFERENCIA", "#10b981");

                    // EXTRAER Y REPRODUCIR AUDIO
                    const remoteStream = new MediaStream();
                    invitation.sessionDescriptionHandler.peerConnection.getReceivers().forEach((receiver) => {
                        if (receiver.track) {
                            remoteStream.addTrack(receiver.track);
                        }
                    });

                    // Crear elemento audio si no existe
                    let remoteAudio = document.getElementById('remoteAudio');
                    if (!remoteAudio) {
                        remoteAudio = document.createElement('audio');
                        remoteAudio.id = 'remoteAudio';
                        remoteAudio.autoplay = true;
                        document.body.appendChild(remoteAudio);
                    }
                    
                    remoteAudio.srcObject = remoteStream;
                    remoteAudio.play().catch(e => console.error("VOXPhone: Error al reproducir audio:", e));

                }).catch((error) => {
                    console.error("VOXPhone: Error al contestar:", error);
                });
            };

            let callTriggered = false;
            registerer.stateChange.addListener((newState) => {
                console.info("VOXPhone: Estado de Registro ->", newState);
                switch(newState) {
                    case RegistererState.Registered:
                        VOXPhone.updateStatus("CONECTADO", "#4ade80");
                        
                        // Disparar la llamada de conferencia solo la primera vez que se registra
                        if (!callTriggered) {
                            console.log("VOXPhone: Registro exitoso. Solicitando entrada a conferencia...");
                            fetch('index.php?action=trigger_call');
                            callTriggered = true;
                        }
                        break;
                    case RegistererState.Unregistered:
                        VOXPhone.updateStatus("SIN REGISTRAR", "orange");
                        break;
                    case RegistererState.Terminated:
                        VOXPhone.updateStatus("SESION FINALIZADA", "red");
                        break;
                }
            });

            await registerer.register();

        } catch (error) {
            console.error("VOXPhone: ERROR CRÍTICO", error);
            VOXPhone.updateStatus("ERROR SIP", "#ef4444");
        }
    },

    updateStatus: (text, color) => {
        const el = document.getElementById('phone-status');
        const dot = document.getElementById('status-dot');
        if (el) el.innerText = text;
        if (dot) dot.style.backgroundColor = color;
    },

    hangup: () => {
        if (session) {
            console.log("VOXPhone: Colgando sesión activa...");
            session.bye();
            session = null;
            VOXPhone.updateStatus("CONECTADO", "#4ade80");
        } else {
            console.warn("VOXPhone: No hay sesión activa para colgar.");
        }
    }
};

window.VOXPhone = VOXPhone;
