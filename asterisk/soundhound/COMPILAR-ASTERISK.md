# Cómo se compiló y se instala el Asterisk de `soundhound`

Lo escribe Telefonia el 2026-11-10 **solo leyendo** el servidor (árbol de compilación en
`/usr/local/src/zynervox`, `config.log`, `menuselect.makeopts`, `/var/log/dnf.log`, `asterisk -rx` de solo
consulta). Nadie recompiló ni recargó nada. Lo que no se pudo comprobar está en «Sin verificar» al final: no lo
des por probado. La configuración (dialplan, SIP, AMI, AMD) está en [`etc-asterisk/README.md`](etc-asterisk/README.md);
aquí solo la parte de compilar, instalar y arrancar.

## 1. Origen y versión

| Dato | Valor |
|---|---|
| Versión | **Asterisk 20.21.0** (`.version` = `20.21.0`; `asterisk -rx 'core show version'`: «built by root @ SrvPredic … 2026-10-10 22:31:19 UTC») |
| Fuente | `/usr/local/src/zynervox/asterisk-20.21.0.tar.gz` (28 838 213 bytes), descomprimido en `/usr/local/src/zynervox/asterisk-20.21.0/` |
| SHA-256 del tarball | `13fd6e8f1fbb19a3174af82a388dd72c87eb2c92d32fca83c4d51bfab03f686a` (también en `compilacion/asterisk-20.21.0.tar.gz.sha256`) |
| URL de descarga | No consta en el servidor. El nombre coincide con el paquete oficial de `downloads.asterisk.org`; **compara el SHA-256 con el publicado allí antes de fiarte**. No hay repositorio git (no hay commit que citar). |
| Terceros incluidos | `pjproject` 2.17 y `jansson` 2.15.1 **empaquetados** (`third-party/`); se descargan al compilar. |
| Sistema | Rocky Linux 9.4 x86_64 (`os-release`), paquetes con actualizaciones `el9_8`; gcc 11.5.0 |
| Cuándo | Compilado el 2026-10-10 ~17:30-17:32 (hora local), `chan_sip` añadido el 2026-10-11 03:36 (ver §3) |

## 2. Dependencias del sistema (dnf)

Instaladas el 2026-10-10 17:29 según `/var/log/dnf.log` justo antes de compilar:

```bash
sudo dnf install -y gcc gcc-c++ make patch bzip2 tar wget xz-devel zlib-devel \
    ncurses-devel libxml2-devel libedit-devel libuuid-devel openssl-devel \
    sqlite-devel jansson-devel libsrtp-devel python3-devel
```

(`glibc-devel`, `libstdc++-devel`, `libxcrypt-devel` entran por dependencia). En el servidor también están
`git`, `kernel-headers` y `libtool-ltdl`; no está probado que hagan falta. El binario enlaza con:
`libasteriskssl`, `libasteriskpj` (los dos de Asterisk), `libxml2`, `libsqlite3`, `libssl.so.3`, `libcrypto.so.3`,
`libuuid`, `libcrypt`, `libedit`, `libstdc++`, `libz` y `liblzma`. **Hace falta acceso a internet al compilar**:
descarga `pjproject` y `jansson` y, en `make install`, los sonidos del núcleo y la música en espera.

## 3. `./configure` y menuselect

```bash
cd /usr/local/src/zynervox/asterisk-20.21.0
./configure --with-pjproject-bundled --with-jansson-bundled --libdir=/usr/lib64   # (config.log)
```

`--libdir=/usr/lib64` deja los módulos en `/usr/lib64/asterisk/modules` y el resto en el prefijo por defecto
(`/usr`, `/etc/asterisk`, `/var/lib/asterisk`, `/var/spool/asterisk`, `/var/log/asterisk`).

Selección de módulos: el fichero completo con el que se compiló está en
[`compilacion/menuselect.makeopts`](compilacion/menuselect.makeopts); para repetirlo, cópialo como
`menuselect.makeopts` en el árbol (o reproduce los cambios con `menuselect/menuselect`).

- **Opción añadida a mano: `chan_sip`.** En Asterisk 20 `chan_sip` viene desactivado. El primer
  `menuselect.makeopts` (copia `menuselect.makeopts.bak.20261011-103601`) lo tenía en `MENUSELECT_CHANNELS`;
  el actual no. Es la única diferencia entre los dos ficheros, y `chan_sip.so` es el único módulo con fecha
  2026-10-11 03:36. Equivale a:
  ```bash
  menuselect/menuselect --enable chan_sip menuselect.makeopts   # luego: make && make install
  ```
- **Desactivados** (lo que el fichero lista, ya sea por defecto o por dependencias que faltan): `chan_dahdi`, `chan_motif`,
  `chan_console`, `chan_alsa`, `chan_mgcp`, `chan_skinny`; todos los `*_odbc` (incluidos `res_odbc`, `func_odbc`,
  `cdr_odbc`); `func_curl`, `res_curl`, `app_macro`, `app_meetme`, `app_voicemail_imap/odbc`, `pbx_lua`, `codec_opus`,
  `codec_g729a`, `res_stir_shaken`, `res_geolocation`, `res_xmpp` y los módulos de pruebas. Sonidos: solo
  `CORE-SOUNDS-EN-GSM`; música: `MOH-OPSOUND-WAV`.
- **Habilitados y usados por este sistema**: `chan_sip` (SIP UDP 5060 y TLS 5065), `app_amd` (AMD), `res_audiosocket`,
  `chan_audiosocket` y `app_audiosocket` (audio hacia el puente de zypad), `app_chanspy` (escucha antes del
  answer), `app_mixmonitor`, `res_agi` (los AGI en PHP), `func_shell`, `func_cut`, `func_strings`, `func_math`,
  `func_db` (AstDB), `func_callerid`, `func_uri`, `app_originate`, `app_stack`, `pbx_config`, `res_musiconhold`,
  `res_rtp_asterisk`, `res_srtp` y `res_crypto`. Todos aparecen cargados en `module show`.
- **Compilado pero NO cargado**: PJSIP completo (`chan_pjsip`, `res_pjsip*`): `modules.conf` lo apaga con `noload`. No
  hace falta compilarlo para que el sistema funcione, pero así está compilado el binario (`libasteriskpj`).

El árbol dice `DEPSFAILED` para unos pocos módulos que no se pudieron construir por falta de bibliotecas; ninguno
es de los usados arriba.

## 4. Compilar e instalar

No hay historial de shell; esta es la secuencia estándar que concuerda con los ficheros del servidor (binario
`/usr/sbin/asterisk`, módulos en `/usr/lib64/asterisk/modules`, sonidos y documentación en `/var/lib/asterisk`):

```bash
cd /usr/local/src/zynervox
tar xzf asterisk-20.21.0.tar.gz && cd asterisk-20.21.0
./configure --with-pjproject-bundled --with-jansson-bundled --libdir=/usr/lib64
cp /ruta/a/compilacion/menuselect.makeopts menuselect.makeopts      # incluye chan_sip
make -j"$(nproc)"
sudo make install
```

**No se ejecutó `make samples`** (en `/etc/asterisk` no hay ficheros de ejemplo, solo los de `etc-asterisk/`) **ni
`make config`** (la unidad es manual, §6).

## 5. Usuario, grupo y directorios

```bash
sudo groupadd -r asterisk     # gid 990 en este servidor
sudo useradd -r -g asterisk -d /var/lib/asterisk -s /sbin/nologin asterisk   # uid 995
```

| Ruta | Propietario y modo | Contexto SELinux |
|---|---|---|
| `/etc/asterisk` | `root:asterisk` 0750 | `asterisk_etc_t` |
| `/etc/asterisk/tls` | `asterisk:asterisk` 0755 | — (ver §8) |
| `/usr/lib64/asterisk/modules` | `root:root` 0755 | `lib_t` |
| `/var/lib/asterisk` (+ `agi-bin`) | `asterisk:asterisk` 0750 | `asterisk_var_lib_t` (`agi-bin`: `bin_t`) |
| `/var/spool/asterisk` | `asterisk:asterisk` 0750 | `asterisk_spool_t` |
| `/var/log/asterisk` | `asterisk:asterisk` 0750 | `asterisk_log_t` |
| `/run/asterisk` | `asterisk:asterisk` 0750 (la crea systemd con `RuntimeDirectory`) | `asterisk_var_run_t` |
| `/usr/sbin/asterisk` | `root:root` 0755 | `asterisk_exec_t` |
| `/var/lib/zynervoxv2/asterisk` (includes generados y grabaciones) | ver [`etc-asterisk/README.md`](etc-asterisk/README.md) e `INSTALL.md` | `zynervox_runtime_t` |

Los contextos `asterisk_*` vienen de la política SELinux de la distribución; los de `zynervox_*` y el puerto 8765,
de `installer/rocky-selinux.*` (de Instalador). Los AGI en PHP no están en `agi-bin`: los dialplans los llaman por
ruta absoluta en la web (`/srv/www/htdocs/zynervoxv2/...`).

## 6. Unidad systemd

Copia exacta, sin secretos, en [`compilacion/asterisk.service`](compilacion/asterisk.service) (`/etc/systemd/system/asterisk.service`):

```ini
[Unit]
Description=Asterisk PBX for Zynervox v2
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=asterisk
Group=asterisk
RuntimeDirectory=asterisk
RuntimeDirectoryMode=0750
ExecStart=/usr/sbin/asterisk -f -C /etc/asterisk/asterisk.conf
ExecReload=/usr/sbin/asterisk -rx "core reload"
Restart=on-failure
RestartSec=5
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
```

```bash
sudo install -m 0644 -o root -g root compilacion/asterisk.service /etc/systemd/system/asterisk.service
sudo systemctl daemon-reload && sudo systemctl enable asterisk   # en este servidor está «enabled»
```

No arranques el servicio hasta tener `/etc/asterisk` y los TLS puestos (§7 y §8).

## 7. Configuración, AMI y dialplan

Todo en [`etc-asterisk/README.md`](etc-asterisk/README.md): qué fichero va a `/etc/asterisk`, los ficheros que genera la web
en `/var/lib/zynervoxv2/asterisk/modules/asterisk/`, la tabla `v2_dialplans` (cuerpos en `etc-asterisk/dialplans-v2/`), el
AMI (`manager.conf`, usuario `zynervoxv2` en `127.0.0.1:5038`, y `/etc/zynervox/zynervoxv2/pbx/ami.json` con el
mismo secreto) y los dos marcadores `<SECRETO:…>` que hay que rellenar. Debe existir el enlace
`/srv/www/htdocs/zynervoxv2 → /var/www/html/zynervoxv2`. Cambios de dialplan o SIP: `asterisk -rx 'dialplan reload'` /
`'sip reload'`, con 0 canales abiertos.

## 8. Certificados TLS (no están en el repositorio)

`sip.conf` abre TLS en el puerto 5065 y espera estos tres ficheros en `/etc/asterisk/tls/` (propietario `asterisk`):

| Fichero | Qué es | Modo |
|---|---|---|
| `voip.servex.com.pe-fullchain.pem` | Certificado del dominio `voip.servex.com.pe` con su cadena | 0644 |
| `voip.servex.com.pe.key` | Clave privada de ese certificado | **0600** |
| `soundhound-ca-digicert.pem` | CA con la que SoundHound firma su lado (`tlscafile`) | 0644 |

**Hay que pedírselos** al propietario del dominio `voip.servex.com.pe` (certificado y clave) y a SoundHound (su CA).
No se versionan y no deben copiarse a mensajes ni tickets; `.gitignore` ya excluye `*.pem` y `*.key`. En
`/etc/asterisk/tls/` del servidor hay además copias intermedias y un `.csr`; no son necesarias. Sin estos tres
ficheros `chan_sip` no abre el puerto 5065 y el peer `soundhound` no funciona (el UDP 5060 sí).

## Sin verificar

- Que el tarball sea idéntico al oficial (solo se conoce su SHA-256) y desde qué URL se bajó.
- La secuencia exacta de órdenes que se ejecutó: no hay historial. La de §4 es la estándar y concuerda con los
  ficheros instalados, pero no se ha repetido en un servidor limpio.
- Si hizo falta alguno de los paquetes de §2 que no estén en el registro de `dnf` del 2026-10-10, y la lista mínima de
  dependencias de ejecución en un servidor sin los `-devel`.
- Los comandos de §5 para crear usuario y grupo: se deducen de `getent passwd/group` (sistema, `nologin`, home
  `/var/lib/asterisk`), no de un registro.
- Los contextos SELinux de `/etc/asterisk/tls` y de los ficheros de configuración tal como los deja la política.
- Que compilar sin PJSIP (`--without-pjproject`) funcionaría igual: no se probó.
- Los certificados TLS y quién es su emisor.
