# Asterisk del servidor `soundhound`

Copia, tomada el 2026-11-10 con Asterisk 20.21.0 en marcha, de lo que hace falta para recrear la
parte de Asterisk de este servidor. Lo escribe Telefonia. **Nada de esto se despliega solo**: es la
referencia para reconstruir y para revisar diferencias. Los secretos están sustituidos por
marcadores `<SECRETO:nombre>`; los certificados y claves TLS no se versionan.

## Qué hay

```
asterisk/soundhound/etc-asterisk/   (este directorio)
  asterisk.conf extensions.conf manager.conf modules.conf pjsip.conf rtp.conf
  sip.conf logger.conf amd.conf   copia de /etc/asterisk (solo los ficheros vivos; sin *.bak*)
  generados/                  copia de /var/lib/zynervoxv2/asterisk/modules/asterisk/
  dialplans-v2/               cada dialplan de la tabla v2_dialplans (cuerpo) + index.tsv
  bin/                        herramientas que Asterisk/zypad ejecutan
```

### Ficheros de la raíz (origen: `/etc/asterisk/`)

| Fichero | Para qué |
|---|---|
| `asterisk.conf` | Directorios y usuario `asterisk`. Arranque: `asterisk -f -C /etc/asterisk/asterisk.conf` (unidad `asterisk.service`, fuera de la fuente; ver «Fuera del repositorio»). |
| `modules.conf` | `autoload=yes` y **PJSIP completo desactivado** (`noload`). El servidor usa **chan_sip**. |
| `sip.conf` | UDP 5060 y TLS 5065, contexto por defecto `zynervoxv2`, y al final `#include` de los dos `sip-zynervoxv2*.conf` generados. Contiene el peer `soundhound` (TLS, pre-producción; el de producción está comentado). |
| `pjsip.conf` | Solo un transporte UDP y los `#include` de los PJSIP generados. **No se usa**: `chan_pjsip` no está cargado. |
| `extensions.conf` | Solo dos `#include`: `extensions-zynervoxv2.conf` y `extensions-forwarding.conf` (generados). |
| `manager.conf` | AMI en `127.0.0.1:5038`, usuario `zynervoxv2`, permisos `originate,call,command,user`. |
| `amd.conf` | Parámetros por defecto de AMD. Los dialplans del marcador los sobreescriben con los 9 argumentos de `AMD()` (variables `ZV2_AMDP1..9` del contexto `zynervoxv2-amd-params`). |
| `rtp.conf` | RTP 10000-20000. |
| `logger.conf` | `messages` y consola con notice, warning y error. |

### `generados/` (origen: `/var/lib/zynervoxv2/asterisk/modules/asterisk/`)

Los escribe la aplicación web (modo aislado: `config/deployment.php` con
`runtime=/var/lib/zynervoxv2/asterisk`), propietario `apache`. **No se editan a mano.**

| Fichero | Quién lo genera |
|---|---|
| `extensions-zynervoxv2.conf` | `Dialplans::save` / `Carriers.php`: contextos comunes de `DialplanOrigins.php` (`COMMON`) más un bloque `exten => _<prefijo>X.` por cada fila activa de `v2_dialplans`. |
| `extensions-forwarding.conf` | Contexto `zynervoxv2-forward-headers` (llama a `bot_ivr/forward_headers_agi.php`). Sin él, la transferencia no envía INVITE. |
| `sip-zynervoxv2.conf` | Un peer por carrier (`carrier1`, `troncal_sim`) desde `Carriers.php`. |
| `sip-zynervoxv2-annexos.conf` | Anexos (teléfonos) desde `Phones.php`. |
| `pjsip-zynervoxv2*.conf` | Mismo generador para PJSIP; con chan_sip quedan vacíos y no se leen. |

Los `*.bak*` que hay junto a los originales no se copian.

### `dialplans-v2/`

La **fuente real** de los dialplans de llamada es la tabla `zynervox_core.v2_dialplans`
(`dialplan_entry`), no el fichero generado. Cada `<prefijo>-<nombre>.conf` es el cuerpo de una
fila; `index.tsv` da `dialplan_id`, prefijo, nombre y activo. Para recrear: guardar cada cuerpo con
`bin/aplicar_dialplan.php` (ver abajo) y recargar. Ojo: los contextos auxiliares que comparten dos
dialplans (`zynervoxv2-pa-hora`, `-amd-params`, `-xfer-contestada`, `-zs-*`, `-pc-*`, `-pa-*`) deben ser
**idénticos** en todos; si no, `Dialplans::save` rechaza el guardado. Para cambiar un par (p. ej. 7314 y
7315): guardar el simulado con `ACTIVO=N`, luego el real, luego el simulado con `ACTIVO=Y`.

| Prefijo | Nombre | Uso |
|---|---|---|
| 7307 | test_no_amd | Prueba antigua con `call_tracking_agi` y sin AMD. |
| 7308 | marcador_pruebas | Marcador v7: AMD, grabación, transferencia y auditoría. |
| 7309 | llamada_directa | Llamada directa por carrier1 (MicroSIP); sin AMD, sin AGI y **sin lista negra**. |
| 7310 / 7311 | preanswer_zypad (real / simulada) | Escucha previa al answer por AudioSocket; sin corte. |
| 7312 / 7313 | preanswer_corte (real / simulada) | Igual, con corte antes del answer (UUID con `c07e`). |
| 7314 / 7315 | zypad_sombra (real / simulada) | Modo sombra: escucha, anota la palabra, nunca corta. Es el que usa el motor. |

Todas (salvo 7309) consultan antes del Dial la lista negra por campaña (`ZV2_BLACKLIST=SI` y
`amd_audit_agi.php,check`); 7308, 7314 y 7315 llevan además `Set(CALLERID(num)=${ZV2_XFER_DEST})` en la
pata de transferencia.

### `bin/`

| Fichero | Para qué |
|---|---|
| `cortar_llamada.sh` | Lo ejecuta el puente de zypad (`CUT_CMD`) para cortar antes del answer; solo UUID con `c07e`. Usa `sudo -n asterisk -rx`. |
| `anotar_palabra.sh` | Lo ejecuta el puente de zypad (`ANNOTATE_CMD`) en modo sombra; solo anota en AstDB (`zypadword`). |
| `aplicar_dialplan.php` | Guarda un dialplan con `Dialplans::save`. Como `apache`, desde un directorio legible (no desde el espacio de un agente): `php aplicar_dialplan.php <raiz web> <id|nuevo> <fichero> [nombre]`; `ACTIVO=Y|N` en el entorno. No recarga Asterisk. |

## Marcadores que hay que rellenar

| Marcador | Dónde | De dónde sale el valor |
|---|---|---|
| `<SECRETO:ami_zynervoxv2>` | `manager.conf` (línea `secret`) | Inventar uno largo y aleatorio. **El mismo valor** debe estar en `/etc/zynervox/zynervoxv2/pbx/ami.json` (`{"host":"127.0.0.1","port":5038,"username":"zynervoxv2","secret":"..."}`, propietario `root:apache`, 0640). |
| `<SECRETO:anexo_70553559>` | `generados/sip-zynervoxv2-annexos.conf` | Contraseña del anexo SIP `70553559` (MicroSIP). En el servidor actual **coincide con el propio número del anexo**: cambiarla. Normalmente no se rellena a mano: se vuelve a generar desde el panel (`Phones.php`). |

Ficheros TLS que `sip.conf` espera y **no están en el repositorio** (la clave y los `.pem` están en
`.gitignore`): en `/etc/asterisk/tls/`, `voip.servex.com.pe-fullchain.pem` (asterisk), `voip.servex.com.pe.key`
(asterisk, 0600) y `soundhound-ca-digicert.pem` (asterisk). Hay que pedirlos al propietario del dominio
`voip.servex.com.pe`.

## Cómo recrearlo (orden)

1. Compilar/instalar Asterisk 20.21.0 (en este servidor, desde `/usr/local/src/zynervox/asterisk-20.21.0`,
   módulos en `/usr/lib64/asterisk/modules`) con `chan_sip`, `app_amd` y los módulos AudioSocket
   (`res_audiosocket`, `chan_audiosocket`, `app_audiosocket`).
2. Copiar los `*.conf` de este directorio (no `generados/` ni `dialplans-v2/`) a `/etc/asterisk/`, rellenar los marcadores
   y poner los ficheros TLS. Propietario `root:asterisk`, 0640.
3. Crear `/var/lib/zynervoxv2/asterisk/modules/asterisk/` (el instalador lo hace) y dejar que la web genere
   `generados/`: crear los carriers (`carrier1`, `troncal_sim`) y el anexo desde el panel, y restaurar o recrear
   `v2_dialplans` (ver `dialplans-v2/`). El dialplan generado depende de esa tabla.
4. Verificar que existe el enlace `/srv/www/htdocs/zynervoxv2 → /var/www/html/zynervoxv2`: los dialplans llaman a
   los AGI con la ruta `/srv/www/htdocs/zynervoxv2/...`.
5. `asterisk -rx 'sip reload'` y `asterisk -rx 'dialplan reload'` (con 0 canales abiertos para no tocar llamadas).

## Fuera del repositorio

- `/etc/systemd/system/asterisk.service`: `User/Group=asterisk`, `RuntimeDirectory=asterisk`,
  `ExecStart=/usr/sbin/asterisk -f -C /etc/asterisk/asterisk.conf`, `ExecReload=asterisk -rx "core reload"`,
  `Restart=on-failure`, `LimitNOFILE=65536`.
- `/etc/zynervox/zynervoxv2/pbx/ami.json` (ver arriba).
- Certificados TLS, política SELinux (`installer/rocky-selinux.*`), firewall (UDP 10000-20000 abierto) y las
  grabaciones en `/var/lib/zynervoxv2/asterisk/recordings/`.
