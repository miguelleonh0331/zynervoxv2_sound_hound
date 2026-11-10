# Despliegue autorizado de Zynervox v2 en soundhound

Los especialistas corren como `codex-web`. Para publicar codigo web cuando la
tarea lo autorice, no necesitan una contrasena ni sudo general:

```sh
sudo -n /usr/local/sbin/deploy-zynervoxv2 --dry-run
sudo -n /usr/local/sbin/deploy-zynervoxv2
```

Antes, guardar los cambios revisados en un commit local y dejar limpio el arbol
de `/opt/zynervoxv2-source-soundhound`. El comando toma una fotografia del commit
HEAD de `app/web/` y la copia exclusivamente a `/var/www/html/zynervoxv2/`.
No acepta rutas ni otros argumentos. No elimina archivos sobrantes del destino.
Un candado impide dos despliegues simultaneos.

El codigo se prepara con Git y tar como `codex-web`, sin privilegios. La parte
root solo instala esa fotografia, con propietario root, grupo apache, permisos
web y de los cuatro AGI (`bot_ivr/call_tracking_agi.php`, `ivr_engine_agi.php`,
`forward_headers_agi.php` y `marcador/amd_audit_agi.php`), ACL de lectura para
`asterisk` y `codex-web` sobre todo lo publicado, y contexto SELinux. No ejecuta PHP ni scripts de la
fuente como root. Se omiten enlaces simbolicos y archivos especiales.

Se conservan `config/deployment.php`, `config/reporting_mirror.json`, runtime,
venvs, secretos, backups, adjuntos, claves y archivos `.env`. La configuracion
PBX/TLS y las bases de datos estan fuera de este comando.

Cada despliegue real deja los archivos reemplazados en
`/var/backups/zynervoxv2-web/<fecha-hora>.<id>/`. La salida indica `COMMIT` y
`BACKUP`. La simulacion no modifica la web ni crea un respaldo de despliegue.

## Instalacion protegida

- Ejecutable instalado: `/usr/local/sbin/deploy-zynervoxv2`, root:root, 0750.
- Politica: `/etc/sudoers.d/codex-web-zynervoxv2-deploy`, root:root, 0440.
- Copias versionadas para revision: `installer/deploy-zynervoxv2` y
  `installer/codex-web-zynervoxv2-deploy.sudoers`.

Los AGI se listan una sola vez en `installer/agi-list.txt`. El envoltorio lo lee del
commit que publica (como dato, validado: solo rutas `carpeta/nombre.php`), así que un
AGI nuevo no obliga a reinstalar el envoltorio ni a editar más scripts. En un
servidor ya instalado falta solo su regla SELinux por ruta
(`semanage fcontext -a -t zynervox_agi_exec_t <ruta>`). Ver `INSTALL.md`.

Modificar las copias de la fuente no cambia el comando privilegiado. Solo root
puede instalar una revision de este ejecutable o de su politica, previa revision
y validacion con `bash -n` y `visudo`. No dar sudo al `deploy-web.sh` editable.

Este permiso cubre despliegues web. Los cambios de servicios, sistema operativo,
sudoers, configuracion PBX o esquemas de BD requieren su procedimiento propio.
