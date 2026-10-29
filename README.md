# Fuente Zynervox v2 de soundhound

Servidor exclusivo: `soundhound` (`SrvPredic`, Tailscale `100.113.29.10`), Rocky Linux 9.4.

- Fuente versionada: `/opt/zynervoxv2-source-soundhound`.
- Codigo web: `app/web/`.
- Web publicada: `/var/www/html/zynervoxv2/`.
- `/srv/www/htdocs/zynervoxv2` es un enlace a esa misma web.
- URL: `http://100.113.29.10/zynervoxv2/`.

La fuente y la web son copias independientes. La resincronizacion del 29/10/2026
recupero en `app/web/` los cambios que solo existian en la web publicada. Desde
esta base, editar primero la fuente y desplegar los cambios revisados. No existe
sincronizacion automatica en segundo plano.

## Despliegue web

Previa revision del diff y respaldo, el helper existente copia el codigo usando
checksums y conserva archivos locales del destino:

```sh
bash installer/deploy-web.sh "$PWD/app/web" /var/www/html/zynervoxv2
```

Este helper solo transfiere archivos y ajusta los ejecutables AGI; los permisos,
propietarios y contextos SELinux deben seguir el procedimiento del servidor.
No ejecutar la instalacion completa para publicar un cambio web: tambien tiene
efectos en configuracion y esquemas de BD. La resincronizacion no la ejecuto.

## Datos y dependencias externas

Git excluye credenciales, certificados, claves, perfiles VPN, backups, `runtime/`,
`venvs/`, datos adjuntos y configuracion particular de despliegue. Los sonidos e
imagenes estaticos de la aplicacion si pertenecen al codigo.

Las bases de datos, configuraciones bajo `/etc/zynervox/zynervoxv2`, configuracion
SIP/TLS, grabaciones y flujos publicados bajo `/var/lib/zynervoxv2` son recursos
operativos independientes y requieren su propio respaldo.

El panel `app/web/modules/vpn` consume `http://127.0.0.1:18081/api`, servido por el
contenedor `zynervoxv2-openvpn`. Su proyecto Compose esta en
`/opt/zynervoxv2-vpn/docker-compose.yml`, fuera de esta fuente; su volumen
`zynervoxv2-vpn-data` y el token no se importaron. Este repositorio sincroniza el
codigo web, no constituye por si solo un respaldo instalable de todo el VPS.

## Verificacion

```sh
bash src/features/installer/tests/deploy-web.sh
git diff --check
```

Los detalles, el respaldo y las exclusiones estan en `docs/RESYNC.md`.
