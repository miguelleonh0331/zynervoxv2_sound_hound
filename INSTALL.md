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
- Asterisk y su dialplan **no** los instala este repositorio (ver «Huecos conocidos»). Hay
  una copia de referencia, sin secretos reales, de la configuración de este servidor en
  `asterisk/soundhound/etc-asterisk/`.

Modo de instalación soportado: **v2 aislado** (`ZYNERVOX_ISOLATED=1`). Lo activa
`installer/isolated-env.sh` cuando `WEB_ROOT` termina en `/zynervoxv2`. En ese modo
el login, las campañas y el IVR no comparten datos ni configuración con ninguna
instalación anterior, y la marcación legacy queda bloqueada.

## 1. Sistema base

Servidor de referencia (`soundhound`): Rocky Linux 9.4, SELinux en *Enforcing*,
firewalld activo. Versiones comprobadas allí: PHP 8.3.33, Apache 2.4.62
(`httpd`), MariaDB 10.11.18, Asterisk 20.21.0, Node.js 22.23.2, Python 3.9.25.

Paquetes y repositorios: `installer/packages-rocky9.sh` (lo llama `install.sh` en
Rocky/RHEL 9 cuando no se pasa `--skip-packages`). Habilita CRB y EPEL (de ahí salen
`sox` y `ffmpeg-free`), los flujos de módulo `php:8.3`, `nodejs:22` y `mariadb:10.11`,
e instala 34 paquetes: Apache, PHP-FPM y sus extensiones, MariaDB, Python con PyMySQL
y pip, sox, ffmpeg-free, Node.js, las herramientas de SELinux, ACL, rsync, git,
openssl, curl, sudo, cron y firewalld. `sudo bash installer/packages-rocky9.sh --check`
solo comprueba y no cambia nada. La lista está sacada de `rpm -qa` y `dnf repolist` de
`soundhound` y limitada a lo que usan los scripts y la web. **Sin verificar:** que
`install.sh` en un Rocky limpio llegue a instalar todo con ella (solo se probó
`--check` en `soundhound`, donde ya está todo). **Asterisk no está en la lista**: en
`soundhound` no es un paquete (`/usr/sbin/asterisk`, versión 20.21.0, compilado desde
fuente); no hay procedimiento de compilación en el repo (hueco 15). Docker y
Tailscale (repos presentes en `soundhound`) son opcionales y quedan fuera.

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
export WEB_ROOT=/var/www/html/zynervoxv2
export URL_PATH=/zynervoxv2
export WEB_GROUP=apache
export ZYNERVOX_CORE_ADMIN_PASSWORD='<contraseña elegida>'
bash installer/install.sh
```

`WEB_ROOT` y `URL_PATH` son **obligatorios**: el modo aislado solo se activa si
`WEB_ROOT` termina en `/zynervoxv2`. Sin ellos Rocky usaría `/var/www/html/zynervox` y
se instalaría otra cosa; por eso `install.sh` se detiene con un error claro («modo v2
aislado NO activado», código 2) en lugar de seguir. Para pedir expresamente la
instalación heredada existe `--allow-non-isolated`, que **no** es la de esta guía.
Comprobación sin escribir nada: `sudo bash installer/install.sh --dry-run` (debe
imprimir `web=/var/www/html/zynervoxv2`).

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
   `src/features/bot_ivr/models/001` a `012`; concede a `zynervoxv2_bot_ivr` los permisos
   de las tablas base. Los de las tablas nuevas (desde la `015`) van en
   `list-call-worker.sh`. Si el modo es aislado, al final llama a `call-audit.sh`
   (cron, paso 7) y a `list-call-worker.sh`.
3. `list-call-worker.sh`: modelos `012` (otra vez, idempotente) y `014` a `026`, los `GRANT` de las tablas
   del marcador a `zynervoxv2_bot_ivr`, y las carpetas `bot_ivr/call_jobs`,
   `modules/bot_ivr` y `recordings/marcador`.

Todas las migraciones son idempotentes (`IF NOT EXISTS`) y se pueden reaplicar.
**No existe `013` a propósito**: se retiró (commit `a4e8926`) y la sustituye la `014`
(cabeceras por campaña); no falta nada. `database/init/001-schema.sql` es el volcado de
la BD central heredada (`database.sh`, con Docker) y `installer/migrate.php` aplica las
migraciones heredadas `001` a `005` de `asterisk/synervox/modules/migrations`. Ninguno
crea tablas del marcador, que salen solo de `models/001` a `012` y `014` a `026`.

**Regla para nuevas migraciones.** Añadirlas a `list-call-worker.sh` junto con sus
`GRANT` mínimos; si no, una instalación nueva no las tendrá.

Una migración se aplica en un servidor ya instalado siempre con copia previa:

```sh
sudo mysqldump --single-transaction --routines --triggers zynervox_core > /var/backups/zynervoxv2-db/zynervox_core-<fecha>.sql
sudo mysql zynervox_core < src/features/bot_ivr/models/<NNN>-<nombre>.sql
```

## 5. Publicar la web: `deploy-zynervoxv2`

Es la vía normal de publicación; `deploy-web.sh` solo se usa dentro de la
instalación inicial. Los AGI que ejecuta Asterisk se listan **una sola vez**, en
`installer/agi-list.txt`: de ahí leen el instalador (modo 0750, propietario), el
SELinux (`rocky-selinux.sh`), las pruebas y el envoltorio (que la lee del commit que
publica). El envoltorio, además, repone las ACL de `asterisk` y de `codex-web` y
reetiqueta con SELinux, por eso **tras la instalación conviene instalar el envoltorio
y publicar una vez con él** (paso 5b).

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

Detalles y límites del envoltorio: `docs/DEPLOYMENT.md`. **Un AGI nuevo** se añade
solo a `installer/agi-list.txt`; en un servidor ya instalado hay que además crear su
regla SELinux por ruta (`semanage fcontext -a -t zynervox_agi_exec_t <ruta>`), que
`rocky-selinux.sh` hace en una instalación nueva.

## 6. Asterisk y dialplan

**Manual / Telefonia.** Asterisk 20.21.0 está compilado desde `/usr/local/src/zynervox`
(módulos en `/usr/lib64/asterisk/modules`). Usa **chan_sip** (UDP 5060 y TLS 5065); PJSIP
está en `noload` y los `pjsip-zynervoxv2*.conf` están vacíos. Los `extensions`, `sip` y
`pjsip` de `/etc/asterisk` incluyen los ficheros generados en
`/var/lib/zynervoxv2/asterisk/modules/asterisk/`. En este modo aislado los ficheros
generados son `*-zynervoxv2.conf`; los nombres `pjsip-zynervox.conf` y
`extensions-zynervox.conf` (y las constantes `Carriers::CONF_PATH` y
`Carriers::DIALPLAN_PATH`) son del modo no aislado. **Guardar en la web no recarga
Asterisk**: hay que hacer `sip reload` y `dialplan reload` con 0 canales. El AMI escucha
en `127.0.0.1:5038` con el usuario `zynervoxv2` y el mismo secreto que
`/etc/zynervox/zynervoxv2/pbx/ami.json`. Una copia de referencia de la configuración
(sin secretos) está en `asterisk/soundhound/etc-asterisk/`. Los dialplans llaman a los
AGI por `/srv/www/htdocs/zynervoxv2`, que es un enlace a `/var/www/html/zynervoxv2`:
**el enlace tiene que existir**. **Sin verificar aquí:** que `DialplanOrigins` regenere
`extensions-zynervoxv2.conf` exactamente igual en un servidor nuevo, y los
certificados TLS.

Lo que sí exige el resto del sistema a Asterisk:

- Corre como usuario `asterisk` y debe poder **ejecutar** los cuatro AGI
  (`bot_ivr/call_tracking_agi.php`, `ivr_engine_agi.php`, `forward_headers_agi.php`,
  `marcador/amd_audit_agi.php`, que admite las acciones `audit`, `check` y `blocked`):
  modo 0750, `user:asterisk:r-x`, contexto SELinux
  `zynervox_agi_exec_t`.
- Escribe las grabaciones en `/var/lib/zynervoxv2/asterisk/recordings/marcador/`
  (propietario `asterisk`, grupo `apache`, modo 2770, contexto `zynervox_runtime_t`);
  `recordings/bot_ivr/` tiene el mismo propietario, modo y contexto.
- Se conecta al puente de zypad en `127.0.0.1:8765`; `rocky-selinux.sh` etiqueta ese
  puerto como `zynervox_audiosocket_port_t` y deja a `asterisk_t` conectar.

## 7. Servicios y tareas programadas

**Auditoría de llamadas (cron).** Lo crea `call-audit.sh`
(llamado por `ivr-builder-bot-db.sh`): `/etc/cron.d/zynervoxv2-call-audit`, cada
minuto, `call_tracking_agi.php --replay`. **No lo usa el motor**: reinyecta los cierres
de llamada que no llegaron a la BD (cola `call_audit_pending`). Antes de vaciar datos
del marcador hay que vaciar esa cola.

**Motor de marcación `zynervox-dialer.service`.** Lo instala y habilita
`installer/dialer.sh` (lo llama `install.sh` en modo aislado, como módulo; si falla,
la instalación continúa y queda en el resumen). **No lo arranca nunca.** La unidad
está en `asterisk/synervox/modules/bot_ivr/zynervox-dialer.service` (por defecto usuario
`apache`, ejecuta `/var/www/html/zynervoxv2/bot_ivr/campaign_engine.php`):

```sh
sudo bash installer/dialer.sh --check   # solo comprueba: instalada, habilitada, activa
sudo bash installer/dialer.sh           # instala y habilita; sin arrancar
# systemctl start zynervox-dialer.service   -> solo cuando se autorice marcar
```

Arrancar el motor **no** inicia llamadas: solo marca listas puestas en «Iniciada»
(todas nacen detenidas). No se arranca en la instalación porque un servidor nuevo aún
no tiene AMI, dialplan ni troncales: arrancarlo es la decisión explícita final, y
reiniciarlo, siempre con 0 llamadas abiertas.

La unidad se **genera** con `User=$WEB_USER`, `Group=$WEB_GROUP` y la ruta
`$WEB_ROOT/bot_ivr/campaign_engine.php` (por defecto `apache`, `apache` y
`/var/www/html/zynervoxv2`); el resto es la del repo. Para poder arrancar necesita:
BD accesible con las migraciones 014 a 026 y los `GRANT` de `list-call-worker.sh`
(si no, falla cada pasada y `Restart=on-failure` lo reinicia cada 5 s), las
configuraciones de BD y `pbx/ami.json` legibles por el grupo web, y
`recordings/marcador` creada (`list-call-worker.sh`). La unidad fija además
`/usr/bin/php` como intérprete; con otro PHP hay que editarla. No lleva `Requires=` a propósito
(`After=` de MariaDB y Asterisk basta y no se para en cadena) y es independiente de
`zypad-bridge`. Comprobación sin arrancar:
`cd $WEB_ROOT/bot_ivr && sudo -u $WEB_USER php campaign_engine.php --dry-run`
(JSON con `"dry_run": true`).
Probado: `bash -n`, `--check` en `soundhound` (instalada, habilitada y activa, con la
unidad generada idéntica a la instalada) y la generación con otros valores; **el modo
instalar no se ha ejecutado**, ni en este ni en un servidor limpio.

**Puente de zypad `zypad-bridge.service`.** `installer/zypad-bridge.sh` lo instala y lo
deja habilitado, sin arrancar. `install.sh` lo llama en modo aislado **solo si** se
define `ZYPAD_BRIDGE_ENV_FILE` con la ruta de un fichero que contiene `ZYPAD_URL` y
`ZYPAD_API_KEY` (se prepara aparte; sin él se omite con un aviso). A mano:

```sh
sudo bash installer/zypad-bridge.sh --check "$PWD"                      # solo comprueba
sudo bash installer/zypad-bridge.sh "$PWD" <fichero con ZYPAD_URL y ZYPAD_API_KEY>
# systemctl start zypad-bridge.service   -> con 0 canales abiertos
```

Instala: `/etc/zypad/bridge.env` (root:`$ZYPAD_SERVICE_USER` 0640), `/var/lib/zypad-bridge/logs`
(0700), la unidad (con `User=$ZYPAD_SERVICE_USER`, por defecto `codex-web`), el
*drop-in* del catálogo y la regla sudoers mínima
`src/features/zypad/zypad-bridge.sudoers` → `/etc/sudoers.d/zypad-bridge` (0440,
validada con `visudo`): solo el catálogo como `apache` y cinco órdenes concretas del CLI
de Asterisk. Escucha solo en `127.0.0.1:8765` y necesita Node.js 22. Los logs contienen
transcripciones y no se borran por edad (decisión del propietario de los datos). La
unidad ejecuta el código de `/opt/zynervoxv2-source-soundhound/src/features/zypad/` y
las herramientas de corte y anotación de
`asterisk/soundhound/etc-asterisk/bin/`, es decir, **lo que haya en la fuente en ese
momento, comiteado o no**.
**En `soundhound` el servicio instalado difiere de este paso:** `--check` informa de
que la unidad instalada no es igual a la generada y de que `/etc/sudoers.d/zypad-bridge`
no existe (hoy funciona con el sudo amplio del usuario de servicio). Reinstalarlas
exige una decisión aparte porque el servicio está activo. Probado: `bash -n`, `--check`
y la validación con `visudo`; **el modo instalar no se ha ejecutado**.

**Pasada de transcripción (STT).** Es una pasada opcional y posterior: la auditoría AMD
(`amd_audit_agi.php`) no depende de ella. `marcador/amd_stt_batch.php` existe pero **no
está programada** y no hay motor STT configurado; elegir el motor y programarla lo
decide el propietario de los datos.

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
sudo asterisk -rx 'sip show peers'                             # el proveedor, OK
sudo asterisk -rx 'dialplan show 7314900000000@zynervoxv2'     # el dialplan de prueba está cargado
sudo asterisk -rx 'core show channels count'                   # 0 canales antes de reiniciar nada
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
- De Asterisk solo quedan fuera del repo los certificados TLS, `asterisk.service` y `ami.json`
  (el resto de la configuración está en `asterisk/soundhound/etc-asterisk/`).
- Grabaciones (`/var/lib/zynervoxv2/asterisk/recordings/`) y logs de zypad
  (`/var/lib/zypad-bridge/logs/`).
- Copias de BD y de despliegues: `/var/backups/zynervoxv2-db/`,
  `/var/backups/zynervoxv2-web/`.
- El VPN (`/opt/zynervoxv2-vpn`, contenedor `zynervoxv2-openvpn`).
- Las reglas de firewalld por IP de acceso (las gestiona Jarvis).

## 11. Huecos conocidos

| # | Qué falta | Riesgo | Quién debería cubrirlo |
|---|---|---|---|
| 6 | El envoltorio y su sudoers se instalan a mano como root; no hay script que lo haga. | Un servidor nuevo queda sin la vía de publicación segura. | Instalador / quien administre el servidor |
| 7 | Quedan fuera del repo los certificados TLS, `asterisk.service` y el procedimiento de compilar Asterisk (hueco 15). | Sin ellos un servidor nuevo no tiene llamadas. | Telefonia |
| 8 | `amd_stt_batch.php` no está programado y no hay motor STT (la auditoría AMD no depende de él). | Las grabaciones se acumulan sin transcribir. | El propietario de los datos (elige el motor) y MarcadorBuilder |
| 10 | La creación de usuarios Supervisor y GTR no la hace el instalador. | Solo existe el administrador tras instalar. | Plataforma |
| 11 | Las reglas de firewalld por IP son manuales. | Web inaccesible, o abierta de más, en un servidor nuevo. | Jarvis |
| 12 | No hay copias programadas de BD ni de grabaciones (solo las manuales que se citan arriba; **sin verificar** que no exista otro respaldo). | Pérdida de datos. | Jarvis y el propietario de los datos |
| 13 | El VPN vive en `/opt/zynervoxv2-vpn`, fuera del repo; su volumen y token no están respaldados. | El panel VPN no se puede reconstruir. | Jarvis |
| 14 | Esta guía no se ha probado en un servidor limpio, ni los tests `rocky-http.sh` y `central-database.sh` completos. | Pasos que fallen al instalar de verdad. | Instalador, con un servidor de prueba |
| 15 | Asterisk 20.21.0 está compilado desde fuente en `soundhound`: no hay paquete ni procedimiento de compilación (módulos, `menuselect`, `res_audiosocket`, usuario) en el repo. | Un servidor nuevo no puede tener el mismo Asterisk sin conocimiento previo. | Telefonia |

Los números son estables. Resueltos: 1 (paquetes de Rocky, `installer/packages-rocky9.sh`), 2 (motor, `installer/dialer.sh`), 4 (las herramientas de corte y anotación ya están en el repo, `asterisk/soundhound/etc-asterisk/bin/`, y la plantilla las usa), 5 (lista única de AGI, `installer/agi-list.txt`) y 9 (la `013` se retiró a propósito; `001-schema.sql` y `migrate.php` son del legado y no crean tablas del marcador).
