# Zynervox v2

Plataforma web para campañas de llamadas salientes con bot IVR, marcador propio y
detección de contestadoras, sobre Asterisk. Servidor de referencia: `soundhound`
(`SrvPredic`), Rocky Linux 9.4. Instalar un servidor nuevo: **[INSTALL.md](INSTALL.md)**.

- Fuente versionada: `/opt/zynervoxv2-source-soundhound`. El repositorio está publicado en
  `https://github.com/miguelleonh0331/zynervoxv2_sound_hound.git` (rama `main`); la copia de este
  servidor no tiene ningún remoto configurado y nunca hace *push*.
- Web publicada: `/var/www/html/zynervoxv2/` (`/srv/www/htdocs/zynervoxv2` es un
  enlace a la misma carpeta). URL: `http://<servidor>/zynervoxv2/`.
- La fuente y la web son copias independientes: se edita la fuente, se comitea y se
  publica con `deploy-zynervoxv2`. No hay sincronización automática.

## Qué hace

- **Marcador** (`app/web/bot_ivr/campaign_engine.php`, servicio `zynervox-dialer`):
  marca las listas que están «Iniciadas», con reintentos por código SIP y por
  resultado AMD, franjas horarias y cabeceras SIP por campaña. El motor solo
  marca; iniciar o detener listas es una acción del usuario en la web.
- **Regla de canales:** cada lista tiene su máximo de llamadas simultáneas
  (1 a 500, editable en caliente) y el servidor tiene un máximo global
  (`max_total_channels`, 10 por defecto). El máximo de canales de la campaña ya no
  se usa.
- **AMD** (detección de contestadora): parámetros por campaña, auditoría por
  llamada contestada (`marcador/amd_audit.php`, `amd_audit_agi.php`) con audio
  grabado y transcripción posterior prevista (`marcador/amd_stt_batch.php`, no
  programada).
- **Zypad** (`src/features/zypad/`, servicio `zypad-bridge`): puente AudioSocket
  que manda el audio previo al *answer* a un servicio externo y anota o corta la
  llamada cuando detecta una palabra del catálogo (`marcador/zypad*.php`).
- **Lista negra en dos niveles:** una regular por campaña (o para todas), que el
  motor y el dialplan consultan al marcar, y una global por cliente, que solo se
  consulta al cargar una base (los contactos coincidentes quedan «Bloqueado»). Con
  auditoría de altas y retiradas.
- **Perfiles** (`app/web/includes/Permissions.php`): Administrador (9), Supervisor
  (8) y GTR (7). El servidor decide los permisos; lo que no está en el mapa es solo
  del administrador.
- **Test:** listas de prueba de un contacto, una sola llamada y sin reintentos.
- **Guía** (`modules/admin/guia.php`, `modules/admin/vendor/guia/`): ayuda de usuario
  por perfil, con los audios de ejemplo ya generados dentro del repositorio.
- **IVR Builder** (`app/web/ivr_builder/`), **panel VPN** (`modules/vpn`),
  **carriers/troncales** y **dialplans** administrables desde la web.

El legado ViciDial se retiró de la fuente y de producción (`agc/`, `vicidial-js/`,
`modules/agente/`, `manager_send.php`, `user_status.php`, `setup_db.php`). Las
rutas `agc/api.php` y `setup_db.php` deben dar 404.

## Estructura

| Carpeta | Contenido |
|---|---|
| `app/web/` | Código web. `bot_ivr/` (motor, servicios y AGI), `marcador/` (AMD, zypad, lista negra), `modules/` (pantallas admin, supervisor, VPN), `includes/` (gate `IsolatedGate.php`, `Permissions.php`, dialplans), `ivr_builder/`, `zynervox_queries/` (consultas SQL), `config/`, `lib/`, `services/`. |
| `asterisk/synervox/modules/` | Piezas para Asterisk: workers Python, ejemplos de rutas, `zynervox-dialer.service`, `call-audit.cron`. |
| `database/init/` | Esquemas iniciales (001 a 004). |
| `src/features/bot_ivr/models/` | Migraciones del marcador y del bot (001 a 026; falta la 013). |
| `src/features/zypad/` | Puente AudioSocket de zypad, con sus pruebas y la plantilla del servicio. |
| `src/features/core/`, `src/features/installer/` | Modelos y pruebas del núcleo; pruebas del instalador. |
| `installer/` | Scripts de instalación, plantillas Apache/PHP-FPM, política SELinux y el envoltorio de publicación. |
| `docs/` | `DEPLOYMENT.md`, `RESYNC.md`, `DECISIONS.md`, `CHANGELOG_AGENT.md`. |

## Publicar un cambio web

Con los cambios en un commit local y el árbol limpio:

```sh
sudo -n /usr/local/sbin/deploy-zynervoxv2 --dry-run   # revisar los ficheros
sudo -n /usr/local/sbin/deploy-zynervoxv2             # imprime COMMIT y BACKUP
sudo -n /usr/local/sbin/deploy-zynervoxv2 --dry-run   # debe dar 0 líneas ">f"
```

El envoltorio copia el commit `HEAD` de `app/web/` a `/var/www/html/zynervoxv2/`,
conserva `config/deployment.php`, secretos y `runtime/`, deja una copia de lo
reemplazado en `/var/backups/zynervoxv2-web/` y restaura propietarios, modos de los
AGI, ACL de `asterisk` y etiquetas SELinux. Detalles en `docs/DEPLOYMENT.md`.

No ejecutar `installer/install.sh` para publicar un cambio web: también toca
configuración y esquemas de BD. Las migraciones y los servicios se tratan por
separado (ver `INSTALL.md`).

## Datos y dependencias externas

Git excluye credenciales, certificados, claves, perfiles VPN, copias, `runtime/`,
`venvs/` y la configuración particular del despliegue. Las bases de datos, los
secretos de `/etc/zynervox/zynervoxv2` y `/etc/zypad`, la configuración de Asterisk,
las grabaciones y los logs de zypad son recursos operativos independientes y
requieren su propio respaldo. El VPN (`/opt/zynervoxv2-vpn`) está fuera de este
repositorio. Lo que el instalador todavía no hace solo está en la sección «Huecos
conocidos» de `INSTALL.md`.

## Verificación

```sh
bash src/features/installer/tests/deploy-web.sh
git diff --check
```

Las pruebas `deploy-permission.sh` (requiere root) y `deploy-web.sh` no escriben en
producción. La resincronización inicial (29/10/2026) está en `docs/RESYNC.md`.
