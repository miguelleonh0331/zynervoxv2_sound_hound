# Instalar un servidor Zynervox v2 desde cero

Guía para montar un servidor nuevo, en orden. Cada paso nombra el script real de
`installer/` que lo hace, o dice **manual** y da el comando.

**Cómo leerla.** Lo que está comprobado en el código o en el servidor `soundhound`
va sin marca. Lo que no se ha comprobado va marcado **sin verificar**. Esta guía
**no se ha ejecutado de punta a punta en un servidor limpio con el estado actual de
la fuente**: es la lectura ordenada de los scripts y de lo que hay en `soundhound`.

Los secretos se nombran por su ruta. Nunca se escriben en este repositorio.

## 0. Qué se instala

- La web (`app/web/`) en `/var/www/html/zynervoxv2/`, detrás de un *gate* PHP
  (`includes/IsolatedGate.php`) que solo deja pasar las pantallas de la lista blanca.
- Dos bases en el mismo MariaDB local: `zynervox_core` (login, campañas, listas,
  intentos de llamada, marcador) y la de flujos IVR/bot.
- Los AGI de Asterisk (`bot_ivr/*_agi.php` y `marcador/amd_audit_agi.php`), el motor
  de marcación (`zynervox-dialer.service`) y el puente de zypad (`zypad-bridge.service`).
- Asterisk y su dialplan **no** los instala este repositorio (ver «Huecos conocidos»).

Modo de instalación soportado: **v2 aislado** (`ZYNERVOX_ISOLATED=1`). Lo activa
`installer/isolated-env.sh` cuando `WEB_ROOT` termina en `/zynervoxv2`. En ese modo
el login, las campañas y el IVR no comparten datos ni configuración con ninguna
instalación anterior, y la marcación legacy queda bloqueada.

## 1. Sistema base

Servidor de referencia (`soundhound`): Rocky Linux 9.4, SELinux en *Enforcing*,
firewalld activo. Versiones comprobadas allí: PHP 8.3.33, Apache 2.4.62
(`httpd`), MariaDB 10.11.18, Asterisk 20.21.0, Node.js 22.23.2, Python 3.9.25.

Paquetes. **Manual.** `installer/install.sh` solo instala paquetes en Debian/Ubuntu;
en Rocky sale con «use --skip-packages o instale dependencias manualmente». Hay que
tener instalados `httpd` con el módulo `proxy_fcgi`, `php-fpm` con las extensiones
que pide `installer/check.sh` (curl, json, mbstring, mysqli, pdo_mysql, session, xml, zip), `mariadb-server`, Asterisk, Node.js 22 (para el
puente de zypad), Python 3 y las herramientas de SELinux (`policycoreutils`,
`checkpolicy`, `policycoreutils-python-utils`) y `acl`. La **lista exacta de paquetes
y nombres de repositorio está sin verificar**: no existe un listado en el repo.

Usuario del grupo web en Rocky: `apache` (el instalador exige `WEB_GROUP=apache`).

## 2. La fuente

Manual. Dejar la fuente en `/opt/zynervoxv2-source-soundhound` y mantenerla como
repositorio git local con el árbol limpio antes de publicar. Esta fuente no tiene
remoto configurado. `installer/bootstrap.sh` y `installer/unattended.sh
--update-source` hablan con un remoto git de GitHub: **sin verificar** que exista y
no sirven para este servidor; no usarlos.

## 3. Instalación v2 aislada

Como root, desde la raíz de la fuente. La contraseña del administrador inicial es
obligatoria en modo aislado y no se imprime en ningún sitio de este documento:

```sh
export ZYNERVOX_CORE_ADMIN_PASSWORD='<contraseña elegida>'
export WEB_GROUP=apache
bash installer/install.sh --skip-packages
```

Qué hace `install.sh` en este modo (en este orden, según el código):

| Paso | Script |
|---|---|
| Copia la web y fija permisos (root:apache, 0750/0640, `runtime/` 0770) | `deploy-web.sh` + bloque de `install.sh` |
| Marca el despliegue como aislado (`config/deployment.php`) y crea las carpetas de flujos, sonidos y grabaciones | `deployment-marker.sh` |
| Apache + PHP-FPM con el gate (`/etc/httpd/conf.d/zynervoxv2-isolated.conf`, `/etc/php-fpm.d/zynervoxv2.conf`) | `isolated-apache.sh` (plantillas `apache-fpm-isolated.conf.template`, `php-fpm-isolated.conf.template`) |
| BD `zynervox_core`, usuario `zynervoxv2_core` y administrador | `zynervox-core.sh` |
| BD de flujos IVR/bot, usuario `zynervoxv2_bot_ivr`, esquemas y permisos | `ivr-builder-bot-db.sh` |
| Troncales SIP / carriers | `carriers.sh` |
| Motor de audio local (venv) | `macelioai-tts.sh` |
| Política SELinux, contextos, puerto 8765, ACL de `asterisk` | `rocky-selinux.sh` + `rocky-selinux.te` |
| Diagnóstico | `check.sh` |

`isolate-v2.sh` es una variante más corta (BD y web, sin Apache ni SELinux) que
usan las pruebas de `src/features/installer/tests/`.

Reintentar un módulo suelto: `sudo bash installer/<modulo>.sh`.

## 4. Base de datos y migraciones, en orden

Las aplican los scripts del paso 3; no hay que ejecutarlas a mano en una instalación
nueva. El orden es:

1. `zynervox-core.sh`: `database/init/003-ivr-deploy-config.sql` y la tabla de
   usuarios. Crea el usuario `admin` (nivel 9) con la contraseña de
   `ZYNERVOX_CORE_ADMIN_PASSWORD`; la guarda en
   `/etc/zynervox/zynervoxv2/zynervox-core-admin.env` (0600).
2. `ivr-builder-bot-db.sh`: `database/init/002` y `004` y los modelos
   `src/features/bot_ivr/models/001` a `012`, con permisos de tabla para
   `zynervoxv2_bot_ivr`. Si el modo es aislado, al final llama a `call-audit.sh`
   (cron, paso 7) y a `list-call-worker.sh`.
3. `list-call-worker.sh`: modelos `012` (otra vez, idempotente) y `014` a `026`, los `GRANT` de las tablas
   del marcador a `zynervoxv2_bot_ivr`, y las carpetas `bot_ivr/call_jobs`,
   `modules/bot_ivr` y `recordings/marcador`.

Todas las migraciones son idempotentes (`IF NOT EXISTS`) y se pueden reaplicar.
**No existe `013`** en el repositorio; que sea un salto intencionado está **sin
verificar**. `database/init/001-schema.sql` y `installer/migrate.php` no los llama
ningún script del paso 3: su papel actual está **sin verificar**.

**Regla para nuevas migraciones.** Añadirlas a `list-call-worker.sh` junto con sus
`GRANT` mínimos; si no, una instalación nueva no las tendrá.

Una migración se aplica en un servidor ya instalado siempre con copia previa:

```sh
sudo mysqldump --single-transaction --routines --triggers zynervox_core > /var/backups/zynervoxv2-db/zynervox_core-<fecha>.sql
sudo mysql zynervox_core < src/features/bot_ivr/models/<NNN>-<nombre>.sql
```

## 5. Publicar la web: `deploy-zynervoxv2`

Es la vía normal de publicación; `deploy-web.sh` solo se usa dentro de la
instalación inicial. `install.sh` y `deploy-web.sh` solo ajustan el modo de los tres
AGI de `bot_ivr/`. El envoltorio ajusta también `marcador/amd_audit_agi.php`, repone
las ACL de `asterisk` y de `codex-web` y reetiqueta con SELinux. Por eso, **tras la
instalación, instalar el envoltorio y publicar una vez con él** (paso 5b).

5a. Instalar el envoltorio y su política. **Manual, como root** (solo root puede
cambiarlos):

```sh
install -m 0750 -o root -g root installer/deploy-zynervoxv2 /usr/local/sbin/deploy-zynervoxv2
visudo -cf installer/codex-web-zynervoxv2-deploy.sudoers
install -m 0440 -o root -g root installer/codex-web-zynervoxv2-deploy.sudoers /etc/sudoers.d/codex-web-zynervoxv2-deploy
```

5b. Publicar (con el árbol git limpio y comiteado):

```sh
sudo -n /usr/local/sbin/deploy-zynervoxv2 --dry-run   # solo ficheros esperados
sudo -n /usr/local/sbin/deploy-zynervoxv2             # imprime COMMIT y BACKUP
sudo -n /usr/local/sbin/deploy-zynervoxv2 --dry-run   # debe dar 0 líneas ">f"
```

Detalles y límites del envoltorio: `docs/DEPLOYMENT.md`. Una vez creada la regla
SELinux por ruta, un AGI **nuevo** necesita además su línea en `rocky-selinux.sh` y
en el envoltorio; no se hace solo (ver «Huecos conocidos»).

## 6. Asterisk y dialplan

**Manual / Telefonia. Sin verificar en este documento.** El repositorio contiene
ejemplos y piezas en `asterisk/synervox/modules/` (por ejemplo
`bot_ivr/bot_ivr_route_example.conf`, `asterisk/extensions-forwarding.conf`) y la
tabla de dialplans de la web (`src/features/core/models/dialplans-v2.sql`,
`includes/Dialplans.php`, `modules/admin/dialplan.php`). Dónde escribe esos ficheros
la web, qué `include` hay en `/etc/asterisk`, la configuración PJSIP y el AMI
(`/etc/zynervox/zynervoxv2/pbx/ami.json` lo nombra `rocky-selinux.sh`) **no se han
verificado aquí** porque `/etc/asterisk` no es legible para el usuario del equipo
de instalación.

Lo que sí exige el resto del sistema a Asterisk:

- Corre como usuario `asterisk` y debe poder **ejecutar** los cuatro AGI
  (`bot_ivr/call_tracking_agi.php`, `ivr_engine_agi.php`, `forward_headers_agi.php`,
  `marcador/amd_audit_agi.php`): modo 0750, `user:asterisk:r-x`, contexto SELinux
  `zynervox_agi_exec_t`.
- Escribe las grabaciones en `/var/lib/zynervoxv2/asterisk/recordings/marcador/`
  (propietario `asterisk`, grupo `apache`, modo 2770, contexto `zynervox_runtime_t`).
- Se conecta al puente de zypad en `127.0.0.1:8765`; `rocky-selinux.sh` etiqueta ese
  puerto como `zynervox_audiosocket_port_t` y deja a `asterisk_t` conectar.

## 7. Servicios y tareas programadas

**Auditoría de llamadas (cron).** Lo crea `call-audit.sh`
(llamado por `ivr-builder-bot-db.sh`): `/etc/cron.d/zynervoxv2-call-audit`, cada
minuto, `call_tracking_agi.php --replay`.

**Motor de marcación `zynervox-dialer.service`.** **Manual**; ningún script del
instalador lo instala. La unidad está en
`asterisk/synervox/modules/bot_ivr/zynervox-dialer.service` (usuario `apache`,
ejecuta `bot_ivr/campaign_engine.php`):

```sh
install -m 0644 -o root -g root asterisk/synervox/modules/bot_ivr/zynervox-dialer.service /etc/systemd/system/zynervox-dialer.service
restorecon -F /etc/systemd/system/zynervox-dialer.service
systemctl daemon-reload
systemctl enable zynervox-dialer.service
# systemctl start zynervox-dialer.service   -> solo cuando se autorice marcar
```

Arrancar el motor **no** inicia llamadas: solo marca listas puestas en «Iniciada».
Reiniciarlo con llamadas abiertas las puede afectar: hacerlo con 0 canales.

**Puente de zypad `zypad-bridge.service`.** `installer/zypad-bridge.sh` lo instala y
lo deja habilitado, sin arrancar. **Manual** en el sentido de que `install.sh` no lo
llama y pide un fichero con `ZYPAD_URL` y `ZYPAD_API_KEY` que se prepara aparte:

```sh
sudo bash installer/zypad-bridge.sh "$PWD" <fichero con ZYPAD_URL y ZYPAD_API_KEY>
# crea /etc/zypad/bridge.env (root:codex-web 0640) y /var/lib/zypad-bridge/logs (0700)
# systemctl start zypad-bridge.service   -> con 0 canales abiertos
```

Escucha solo en `127.0.0.1:8765`, necesita Node.js 22. Los logs contienen
transcripciones y no se borran por edad (decisión del propietario de los datos).
El catálogo de palabras lo lee con `sudo -n -u apache php .../marcador/zypad_catalog.php`
(definido en `/etc/systemd/system/zypad-bridge.service.d/20-catalog.conf`, que crea
el script). **Ver huecos 3 y 4.**

**Pasada de transcripción (STT).** `marcador/amd_stt_batch.php` existe pero **no está
programada**; no hay motor STT configurado.

## 8. Primer usuario administrador

Lo crea `zynervox-core.sh` en el paso 3: usuario `admin`, nivel 9
(Administrador), contraseña `ZYNERVOX_CORE_ADMIN_PASSWORD`, guardada en
`/etc/zynervox/zynervoxv2/zynervox-core-admin.env` (0600). Los perfiles
**Supervisor (8)** y **GTR (7)** se distinguen por `user_level` (ver
`app/web/includes/Permissions.php`); cómo se crean esos usuarios desde la web está
**sin verificar**. Todo lo que no esté en el mapa de permisos es solo del
Administrador.

## 9. Comprobaciones finales

```sh
sudo bash installer/check.sh                                   # diagnóstico del instalador
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/zynervoxv2/index.php   # 200
sudo -n /usr/local/sbin/deploy-zynervoxv2 --dry-run            # 0 líneas ">f"
getfacl -p /var/www/html/zynervoxv2/marcador/amd_audit_agi.php # user:asterisk:r-x
ls -Z /var/www/html/zynervoxv2/marcador/amd_audit_agi.php      # zynervox_agi_exec_t
systemctl is-active httpd php-fpm mariadb zypad-bridge zynervox-dialer
sudo ausearch -m avc -ts recent | grep -E 'asterisk|httpd'     # tras una llamada: sin denegaciones nuevas
```

Rutas retiradas del legado que deben dar 404: `agc/api.php`, `setup_db.php`.
Rutas protegidas que deben dar 403: `config/deployment.php`, `includes/Database.php`.
Pruebas del instalador: `bash src/features/installer/tests/deploy-web.sh` y
`deploy-permission.sh`. `rocky-http.sh` y `central-database.sh` crean BD de prueba y
piden la contraseña de administrador; **no se han ejecutado completos con la fuente
actual**.

## 10. Fuera del repositorio

No están en git y se respaldan por separado:

- Secretos: `/etc/zynervox/zynervoxv2/` (contraseñas de BD, administrador, `.conf`
  de la web) y `/etc/zypad/bridge.env`.
- Configuración de Asterisk de este servidor, certificados TLS y SIP.
- Grabaciones (`/var/lib/zynervoxv2/asterisk/recordings/`) y logs de zypad
  (`/var/lib/zypad-bridge/logs/`).
- Copias de BD y de despliegues: `/var/backups/zynervoxv2-db/`,
  `/var/backups/zynervoxv2-web/`.
- El VPN (`/opt/zynervoxv2-vpn`, contenedor `zynervoxv2-openvpn`).
- Las reglas de firewalld por IP de acceso (las gestiona Jarvis).

## 11. Huecos conocidos

| # | Qué falta | Riesgo | Quién debería cubrirlo |
|---|---|---|---|
| 1 | `install.sh` no instala paquetes en Rocky y no hay lista de paquetes. | Un servidor nuevo no llega a instalarse sin conocimiento previo. | Instalador |
| 2 | Ningún script instala `zynervox-dialer.service`. | Tras reinstalar no hay motor y el Dashboard lo marca apagado. | Instalador, con MarcadorBuilder |
| 3 | `zypad-bridge.sh` no se llama desde `install.sh`, y el servicio usa `sudo -n -u apache` para el catálogo sin una regla sudoers propia (en `soundhound` funciona porque el usuario de servicio tiene sudo amplio). | En un servidor sin ese sudo el puente no obtiene el catálogo; con sudo amplio, privilegio excesivo. | Instalador y Zypad |
| 4 | La plantilla `src/features/zypad/zypad-bridge.service` apunta `CUT_CMD` y `ANNOTATE_CMD` a herramientas dentro del espacio de trabajo de otro agente, y esas herramientas no están en el repositorio. | Si ese espacio cambia, el corte y la anotación dejan de funcionar. | Zypad (comitearlas en el repo) |
| 5 | `install.sh` y `deploy-web.sh` solo ajustan 3 de los 4 AGI; el cuarto (`amd_audit_agi.php`) lo cubre solo el envoltorio. Un AGI nuevo exige editar a mano `rocky-selinux.sh`, el envoltorio y su prueba. | AGI no ejecutable por Asterisk hasta publicar con el envoltorio; olvidar la regla de un AGI nuevo lo rompe en silencio. | Instalador |
| 6 | El envoltorio y su sudoers se instalan a mano como root; no hay script que lo haga. | Un servidor nuevo queda sin la vía de publicación segura. | Instalador / quien administre el servidor |
| 7 | Dialplan, PJSIP, AMI y `include` de `/etc/asterisk` no están en este repositorio ni verificados aquí. | Sin ellos no hay llamadas; la reinstalación depende de memoria. | Telefonia |
| 8 | `amd_stt_batch.php` no está programado y no hay motor STT. | Las grabaciones se acumulan sin transcribir. | MarcadorBuilder y el propietario de los datos |
| 9 | Falta la migración `013` y `database/init/001-schema.sql` / `migrate.php` no los usa el instalador. | Sospecha de esquema incompleto en una instalación nueva. | MarcadorBuilder y BotIVR |
| 10 | La creación de usuarios Supervisor y GTR no la hace el instalador. | Solo existe el administrador tras instalar. | Plataforma |
| 11 | Las reglas de firewalld por IP son manuales. | Web inaccesible, o abierta de más, en un servidor nuevo. | Jarvis |
| 12 | No hay copias programadas de BD ni de grabaciones (solo las manuales que se citan arriba; **sin verificar** que no exista otro respaldo). | Pérdida de datos. | Jarvis y el propietario de los datos |
| 13 | El VPN vive en `/opt/zynervoxv2-vpn`, fuera del repo; su volumen y token no están respaldados. | El panel VPN no se puede reconstruir. | Jarvis |
| 14 | Esta guía no se ha probado en un servidor limpio, ni los tests `rocky-http.sh` y `central-database.sh` completos. | Pasos que fallen al instalar de verdad. | Instalador, con un servidor de prueba |
