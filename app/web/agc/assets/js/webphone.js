/* ZYNERVOX webphone — adaptado de la referencia CARSA (mirmidon,
   /srv/www/htdocs/synervox/encuestas/carsa/assets/js/webphone.js).
   Diferencia principal: sin campos manuales, se autoconfigura con
   ZV_WEBPHONE_CONFIG (inyectado por PHP en zynervox.php) y se conecta
   solo al cargar la pagina. Auto-contesta cualquier INVITE entrante
   (asi es como VICIdial "timbra" el telefono del agente al hacer login). */

let zvUserAgent = null;
let zvRegisterer = null;
let zvCurrentSession = null;
let zvPendingInvitation = null;

function zvPhoneLog(message, data) {
	var el = document.getElementById('zv-phone-log');
	var suffix = data ? ' ' + String(data) : '';
	var line = '[' + new Date().toLocaleTimeString() + '] ' + message + suffix;
	if (el) {
		el.textContent += line + '\n';
		el.scrollTop = el.scrollHeight;
	}
	console.log('[ZV webphone]', message, data || '');
}

function zvPhoneSetStatus(text, color) {
	var dot = document.getElementById('zv-phone-dot');
	var txt = document.getElementById('zv-phone-status');
	if (dot) dot.style.background = color;
	if (txt) txt.textContent = text;
	zvPhoneLog('Estado: ' + text);
}

async function zvAttachRemoteAudio(session) {
	var handler = session.sessionDescriptionHandler;
	var pc = handler && handler.peerConnection;
	if (!pc) { zvPhoneLog('No hay peerConnection disponible'); return; }

	var stream = new MediaStream();
	pc.getReceivers().forEach(function (receiver) {
		if (receiver.track) { stream.addTrack(receiver.track); }
	});

	var audio = document.getElementById('zv-phone-audio');
	if (!audio) return;
	audio.srcObject = stream;
	try {
		await audio.play();
		zvPhoneLog('Audio remoto reproduciendo');
	} catch (error) {
		zvPhoneLog('Autoplay bloqueado, requiere interaccion del usuario', error.message);
	}
}

function zvWireSession(session, label) {
	zvCurrentSession = session;
	zvPhoneLog(label + ': sesion creada');

	session.stateChange.addListener(function (state) {
		zvPhoneLog(label + ': estado ' + state);

		if (String(state).includes('Established')) {
			zvPhoneSetStatus('Listo', '#02a5a5');
			setTimeout(function () { zvAttachRemoteAudio(session); }, 400);
		}

		if (String(state).includes('Terminated')) {
			zvPhoneSetStatus(zvRegisterer ? 'Registrado' : 'Sin iniciar', zvRegisterer ? '#02a5a5' : '#9fb3cc');
			if (zvCurrentSession === session) zvCurrentSession = null;
		}
	});
}

async function zvAnswerCall() {
	if (!zvPendingInvitation) return;
	var invitation = zvPendingInvitation;
	zvPendingInvitation = null;
	await invitation.accept({
		sessionDescriptionHandlerOptions: { constraints: { audio: true, video: false } }
	});
}

async function zvStartPhone() {
	/* ZYNERVOX v2: idempotente a proposito. zvStartPhone() ahora se llama
	   explicitamente y de forma temprana (server-side flush, antes del
	   Originate) para que esta sea LA UNICA conexion SIP.js de toda la
	   sesion. El listener de 'load' de mas abajo sigue existiendo solo como
	   red de seguridad (login sin webphone temprano); si ya hay una conexion
	   en curso o establecida, no crear una segunda que compita por el mismo
	   contacto (aor/max_contacts=1). */
	if (zvUserAgent) {
		zvPhoneLog('zvStartPhone: ya existe una conexion activa, se ignora la llamada duplicada');
		return;
	}

	var cfg = window.ZV_WEBPHONE_CONFIG;
	if (!cfg || !cfg.user || !cfg.password || !cfg.domain || !cfg.wssUrl) {
		zvPhoneSetStatus('Sin configurar', '#da3851');
		zvPhoneLog('Falta ZV_WEBPHONE_CONFIG (user/password/domain/wssUrl)');
		return;
	}

	zvPhoneSetStatus('Conectando', '#f7893b');

	var uri = SIP.UserAgent.makeURI('sip:' + cfg.user + '@' + cfg.domain);
	zvUserAgent = new SIP.UserAgent({
		uri: uri,
		transportOptions: {
			server: cfg.wssUrl,
			traceSip: false,
			connectionTimeout: 10,
			keepAliveInterval: 20
		},
		authorizationUsername: cfg.user,
		authorizationPassword: cfg.password,
		displayName: cfg.displayName || cfg.user,
		userAgentString: 'Zynervox Webphone',
		contactName: cfg.user,
		sessionDescriptionHandlerFactoryOptions: {
			peerConnectionConfiguration: {
				iceServers: cfg.stunServer ? [{ urls: cfg.stunServer }] : []
			}
		},
		delegate: {
			onInvite: async function (invitation) {
				zvPendingInvitation = invitation;
				zvWireSession(invitation, 'Entrante');
				zvPhoneSetStatus('Llamada entrante', '#f7893b');
				zvPhoneLog('Entrante desde ' + invitation.remoteIdentity.uri.toString());
				/* Auto-contestar siempre: asi es como VICIdial conecta el canal
				   del agente al hacer login/reservar el telefono. */
				await zvAnswerCall();
			}
		}
	});

	zvUserAgent.stateChange.addListener(function (state) { zvPhoneLog('UserAgent: ' + state); });

	try {
		await zvUserAgent.start();
		zvPhoneLog('WebSocket iniciado (' + cfg.wssUrl + ')');
	} catch (error) {
		zvPhoneSetStatus('Error de conexion', '#da3851');
		zvPhoneLog('No se pudo iniciar el WebSocket', error.message);
		return;
	}

	zvRegisterer = new SIP.Registerer(zvUserAgent);
	zvRegisterer.stateChange.addListener(function (state) {
		zvPhoneLog('Registro: ' + state);
		if (String(state).includes('Registered')) zvPhoneSetStatus('Registrado', '#02a5a5');
		if (String(state).includes('Unregistered')) zvPhoneSetStatus('Sin registrar', '#f7893b');
		if (String(state).includes('Terminated')) zvPhoneSetStatus('Registro terminado', '#da3851');
	});

	try {
		await zvRegisterer.register();
	} catch (error) {
		zvPhoneSetStatus('Error de registro', '#da3851');
		zvPhoneLog('No se pudo registrar', error.message);
	}
}

async function zvStopPhone() {
	try {
		if (zvCurrentSession) {
			var session = zvCurrentSession;
			if (session.bye) await session.bye();
			else if (session.cancel) await session.cancel();
			else if (session.reject) await session.reject();
			zvCurrentSession = null;
		}
		if (zvRegisterer) { await zvRegisterer.unregister(); zvRegisterer = null; }
		if (zvUserAgent) { await zvUserAgent.stop(); zvUserAgent = null; }
	} catch (error) {
		zvPhoneLog('Error al desconectar', error.message);
	}
	zvPendingInvitation = null;
	zvPhoneSetStatus('Sin iniciar', '#9fb3cc');
}

window.addEventListener('load', function () {
	zvPhoneSetStatus('Sin iniciar', '#9fb3cc');
	zvPhoneLog('SIP.js cargado: ' + Boolean(window.SIP));
	/* Autoconfiguracion: se conecta solo, sin pedir datos al agente. */
	zvStartPhone();
});

window.addEventListener('beforeunload', function () {
	if (zvUserAgent) { try { zvUserAgent.stop(); } catch (e) {} }
});
