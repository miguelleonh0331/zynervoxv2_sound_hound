# Vox Sphere (zynervox) — Roadmap y features faltantes

- Última actualización: 2026-09-13
- Basado en inventario real del código en `/var/www/html/zynervox`

## 0. Ya construido y probado (esta fase de rediseño + features nuevas)

- [x] Estilo clásico/liviano (tablas oscuras, sin cards/sombras/gradientes) aplicado a
      Users, Campaigns, Motor, Dispositions, Pauses, User Groups, Lists, Wizard, Dashboard.
- [x] **Monitor** de agentes en vivo por campaña (`monitor.php` + `api_monitor.php`, refresco 5s).
- [x] **Filters**: buscar contacto (teléfono/nombre/lead_id) → historial de llamadas
      (`vicidial_log`) → grabaciones asociadas (`recording_log`) con reproductor/descarga
      (`play_recording.php`).
- [x] **Agents GSM** (`remoteagents.php`): mismo form/tabla que Usuarios, pero NO crea
      anexo en `phones` (agentes que se loguean vía dial-out a un número externo).
- [x] **Carriers** (`carriers.php`): troncales PJSIP reales — escribe
      `pjsip-zynervox.conf` + `extensions-zynervox.conf` y recarga Asterisk
      (`pjsip reload` + `dialplan reload`) vía wrapper sudo restringido. Probado en vivo
      con endpoint y contexto de dialplan reales.

## 1. Roles vacíos (solo pantalla "Acceso Confirmado", sin funcionalidad)

- [ ] **GTR** (`modules/gtr/index.php`, nivel 2)
- [ ] **Supervisor** (`modules/supervisor/index.php`, nivel 3)
- [ ] **Backoffice** (`modules/backoffice/index.php`, nivel 4)

## 2. Módulo de Agente real (el corazón operativo, sin auditar)

- [ ] `modules/agente/vox_sphere.php` y `vox_sphere-mobile.php` (~26,000 líneas cada
      uno, difieren entre sí, nunca probados en una llamada real).
- [ ] `functions.php`, `conf_exten_check.php`, `vdc_form_display.php`,
      `vdc_chat_display.php`, `user_status.php` — sin revisar.
- [ ] Sin esto, nadie puede atender/marcar llamadas reales desde el panel.

## 3. Reportería real de negocio (pendiente, prioridad alta — pedido por el cliente)

Hoy `reporting.php`/`includes/Reporting.php` NO son reportes: solo configuran una
sincronización a una base de datos espejo remota (backup de leads). Falta construir
reportería real. Tablas fuente ya confirmadas en la BD:

- `vicidial_log` — CDR de todos los intentos de marcación saliente.
- `vicidial_closer_log` — llamadas que llegaron a un agente (contactos reales, ventas).
- `vicidial_agent_log` — tiempos por agente/llamada: `pause_sec`, `wait_sec`,
  `talk_sec`, `dispo_sec` (base para reporte de tiempos de agente).
- `vicidial_campaign_stats`, `vicidial_daily_max_stats`, `vicidial_daily_ra_stats` —
  estadísticas pre-agregadas del propio VICIdial.

Reportes propuestos (a definir alcance exacto con el cliente):

- [ ] **Reporte por Campaña**: llamadas totales, contactos, ventas, tasa de
      contacto/venta, duración promedio — por rango de fechas.
- [ ] **Reporte por Agente**: tiempo en pausa / hablando / esperando / tipificando,
      llamadas atendidas, ventas — por rango de fechas.
- [ ] **Detalle de Llamadas (CDR)**: listado filtrable de `vicidial_log`/`vicidial_closer_log`
      (fecha, agente, campaña, status, duración) con export.
- [ ] Decidir si `reporting.php` (sync a BD espejo) se conserva como función aparte
      o se elimina/reemplaza.

## 4. Existen pero sin probar funcionalmente end-to-end

- [ ] `modules/admin/lists.php` — carga de leads (CSV + mapeo) nunca probada con datos reales.
- [ ] `modules/admin/wizard.php` — flujo de 5 pasos nunca probado end-to-end.

## 5. Sin backend ni UI todavía

- [ ] **Quality Control** (`quality.php`, stub).
- [ ] **Scripts** (`scripts.php`, stub).
- [ ] **Inbound / Ingroups / IVR** (`inbound.php`, stub) — enrutamiento de llamadas
      entrantes y DIDs.
- [ ] **Anexos / Teléfonos (UI)**: `includes/Phones.php` existe (usado automáticamente
      al crear un Usuario nivel Agente) pero no hay página para ver/editar extensiones
      directamente.

## 6. Infraestructura / conectividad (fuera del código PHP)

- [ ] Credenciales AMI (`manager.conf` vs `astguiclient.conf`) sin verificar para que
      los daemons Perl controlen Asterisk correctamente.
- [ ] Trunk SIP real hacia mirmidon: zynerdesk ya tiene 5060/udp y RTP 10000-20000/udp
      abiertos y PJSIP respondiendo; falta abrir el firewall del proveedor en mirmidon
      (producción, pendiente por decisión del usuario).
- [ ] Sin una troncal real activa, no hay llamadas reales grabándose todavía — el
      reproductor de audios en Filters está probado solo con datos sembrados.
- [ ] **Agents GSM**: el dial-out real (Asterisk llamando al celular del agente) no está
      probado end-to-end — falta una troncal real para verificarlo.
- [ ] Definir usuario/nombre final que reemplace a `6666` como admin.
- [ ] `reporting.php` sigue huérfano del sidebar (sin decidir si se borra/recicla).

## Prioridad sugerida

1. **Módulo de Agente real** — sin esto el dialer no marca nada.
2. **Reportería real de negocio** — pedido explícito, datos fuente ya identificados.
3. Ingroups / Inbound / IVR (enrutamiento entrante).
4. Probar `lists.php` y `wizard.php` end-to-end.
5. Quality Control, Scripts, roles vacíos (GTR/Supervisor/Backoffice), Anexos UI.
6. Troncal real hacia mirmidon (depende del proveedor, fuera de nuestro control directo).
