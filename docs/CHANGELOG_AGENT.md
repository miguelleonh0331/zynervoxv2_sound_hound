# Cambios de agentes

## 2026-10-29 - soundhound: fuente web resincronizada

- Respaldo de fuente y captura de web en `/opt/backups/zynervoxv2-resync-20261029`.
- Commit base de 1119 archivos, excluyendo secretos y datos operativos.
- Importacion de 17 archivos web desde la aplicacion publicada (10 modificados,
  7 nuevos): IVR, forwarding, carriers y panel VPN.
- Despliegue por checksum, exclusion de venvs/backups y permisos de los tres AGI.
- Documentacion de fuente, limites y recursos externos; web activa conservada.

### Validacion de cierre

1013 archivos web iguales por SHA-256. Sintaxis de 13 PHP, 3 JS y 4 shell;
pruebas de despliegue temporal y deteccion SIP/PJSIP aprobadas. La web publicada
se comparo con su respaldo y permanece sin cambios.

## 2026-10-29 - Despliegue web delegado a codex-web

Por autorizacion del usuario, se habilita un comando root protegido y limitado a
publicar `HEAD:app/web` de esta fuente en `/var/www/html/zynervoxv2`. Se permite
simulacion y aplicacion sin contrasena; no se concede sudo general ni se ejecuta
el instalador editable como root. Se versionan las plantillas y el procedimiento
en `docs/DEPLOYMENT.md`. El despliegue real queda a cargo del agente del usuario.
