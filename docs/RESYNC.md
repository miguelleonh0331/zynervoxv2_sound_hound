# Resincronizacion web - 2026-10-29

Alcance exclusivo: recuperar el codigo web publicado en soundhound dentro de
`/opt/zynervoxv2-source-soundhound/app/web`. No se escribe en la web publicada,
no se reinician servicios y no se modifican datos ni configuraciones SIP/TLS.

## Puntos de recuperacion

- Respaldo previo de la fuente (incluye el Git vacio encontrado):
  `/opt/backups/zynervoxv2-resync-20261029/source-before.tar.gz`.
- Fotografia de la web antes de importar, excluyendo `runtime/` y `venvs/`:
  `/opt/backups/zynervoxv2-resync-20261029/web-before.tar.gz`.
- Commit inicial de fuente: `95a92d4`.
- Rama existente conservada: `master`. No se configuro remoto ni se publico.
- Autor tecnico de los commits: `Codex <codex@localhost>`; sin cambios a la
  identidad Git global del servidor.

El respaldo de la fuente permite recuperar tambien los archivos ignorados por
Git. No extraerlo encima de la fuente activa sin revisar y respaldar primero.
Para deshacer solo la resincronizacion versionada, usar `git revert` sobre su
commit final, conservando el historial y sin afectar la web.

## Codigo recuperado

Se incorporaron 10 archivos modificados y 7 nuevos (17 en total):

- IVR: cambios de edicion de campanas, plantillas de audio y motor de grafos.
- Forwarding: validacion, editor, renderizado y AGI para headers SIP/PJSIP.
- Carriers: generacion separada de cuentas SIP y PJSIP.
- Panel VPN: cuatro archivos web, enlace en sidebar y permiso del gate aislado.

El helper de despliegue compara checksums para detectar incluso archivos con
igual tamano y fecha. Excluye backups, entornos virtuales y bytecode Python.
Los tres AGI conservan permiso de ejecucion en el helper y despues del ajuste
general de permisos de los dos instaladores existentes.

## Exclusiones y limites

`config/deployment.php` se genera para cada instalacion. No se copia a la fuente.
Tambien se excluyen el reporting local, secretos, adjuntos, runtime, entornos,
backups y claves. La equivalencia de codigo se verifica con SHA-256 sobre todos
los archivos web elegibles, en ambos sentidos; no solo sobre sus nombres/fechas.

La importacion conserva el comportamiento ya publicado. No demuestra que todas
las funciones del sistema sean correctas: se valida integridad, sintaxis del
codigo importado y despliegue en directorios temporales; no se lanzan llamadas,
operaciones VPN ni escrituras de BD para probarlo en produccion.

La fuente historica de Asterisk y los esquemas se conservan. La captura de los
cambios operativos del PBX, el codigo del contenedor VPN externo y las bases de
datos es un trabajo separado; no se presenta esta resincronizacion como una
reconstruccion completa y portable del servidor.

## Validacion realizada

- SHA-256: 1013 archivos web elegibles coinciden entre fuente y web, sin extras
  ni faltantes. Todos los archivos incluidos en la captura de web permanecen
  intactos, incluyendo la configuracion de despliegue excluida de la fuente.
- Sintaxis: 13 archivos PHP, 3 JavaScript y 4 scripts shell validados.
- Prueba del helper en directorios temporales: copia inicial, repeticion sin
  transferencias, archivos nuevos/modificados, checksum con igual fecha/tamano,
  permisos de tres AGI y conservacion de runtime, venv y configuracion local.
- Prueba existente de deteccion SIP/PJSIP aprobada.
- `git diff --check` aprobado antes de cerrar la version.
