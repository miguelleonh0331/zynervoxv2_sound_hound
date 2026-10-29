# Documentación de `zynervox.php`

> **Qué es:** la pantalla del agente (agent screen) del call center. Es un fork de
> `vicidial.php` de VICIDIAL (© Matt Florell, AGPLv2, versión base `2.14-710c`,
> build `240830-1112`) con personalizaciones de marca **ZYNERVOX**.
>
> **Tamaño:** ~25,431 líneas. Un solo archivo que mezcla **PHP (lógica + HTML generado)**
> y **JavaScript (toda la interacción del agente)**.
>
> **Última actualización de este documento:** 2026-09-16

---

## Índice

1. [Visión general](#1-visión-general)
2. [Mapa del archivo por líneas](#2-mapa-del-archivo-por-líneas)
3. [Dependencias externas](#3-dependencias-externas)
4. [Flujo de ejecución (request → pantallas)](#4-flujo-de-ejecución)
5. [Configuración y variables clave](#5-configuración-y-variables-clave)
6. [Pantallas que genera el PHP](#6-pantallas-que-genera-el-php)
7. [Base de datos: tablas usadas](#7-base-de-datos-tablas-usadas)
8. [JavaScript: motor del agente](#8-javascript-motor-del-agente)
9. [Comunicación en tiempo real (AJAX)](#9-comunicación-en-tiempo-real-ajax)
10. [Personalizaciones ZYNERVOX](#10-personalizaciones-zynervox)
11. [Puntos de extensión (INSERT_*)](#11-puntos-de-extensión)
12. [Guía de modificación (recetas)](#12-guía-de-modificación)
13. [Depuración y problemas comunes](#13-depuración-y-problemas-comunes)

---

## 1. Visión general

`zynervox.php` es el cliente web del agente VICIDIAL. Su función es:

1. **Autenticar al agente** (usuario + contraseña + campaña) y validar teléfono, shifts, timeclock, 2FA, etc.
2. **Reservar una conferencia Asterisk** (sala MeetMe/ConfBridge) para el agente y ordenar a Asterisk (vía tabla `vicidial_manager`) que llame al teléfono/webphone del agente y lo meta a la sala.
3. **Renderizar la pantalla del agente**: cabecera con estado, botones de control (Dial/Hangup/Dispo), pestañas (Main / SCRIPT / FORM / Email / Chats), panel de transferencias, hotkeys, callbacks programados, etc.
4. **Ejecutar todo el ciclo de la llamada** en el navegador con JavaScript: detectar llamada entrante, mostrar datos del lead, marcar manualmente, transferir, dejar buzón, colgar y disposicionar — todo mediante peticiones AJAX a scripts `vdc_*` y `manager_send.php`.

**Patrón clave para entenderlo:** el PHP casi nunca habla directamente con Asterisk ni procesa llamadas; solo *prepara variables* y *pinta HTML/JS*. Las acciones reales las hacen:

| Acción | Ejecutada por |
|---|---|
| Leer estado de llamadas/sesión cada ~1s | `vdc_db_query.php` (AJAX) |
| Mandar comandos a Asterisk | `INSERT` en tabla `vicidial_manager` (desde `manager_send.php` o directamente) |
| Sincronizar reloj / sala de conferencia | `conf_exten_check.php` (AJAX) |
| Script del agente con variables | `vdc_script_display.php` |
| Formulario de campos personalizados | `vdc_form_display.php` |
| Email / Chat cliente / Chat interno | `vdc_email_display.php`, `vdc_chat_display.php`, `agc_agent_manager_chat_interface.php` |
| API externa (CRM, marcar, colgar…) | `api.php` |

---

## 2. Mapa del archivo por líneas

| Líneas | Sección | Contenido |
|---:|---|---|
| 1–746 | **Changelog** | Historial completo de cambios de VICIDIAL (2006–2024). Solo comentarios. |
| 747–759 | **Config inicial** | `$version`, `$build`, `$mel` (mysql error log), `$DB` (debug), requiere `dbconnect_mysqli.php` y `functions.php`. |
| 761–858 | **Lectura de $_GET/$_POST** | Recolección y saneamiento de variables de entrada (`VD_login`, `VD_pass`, `VD_campaign`, `phone_login`, `stage`, etc.). |
| 860–950 | **SYSTEM_SETTINGS + idioma** | Una sola query a `system_settings` carga ~60 settings globales (non_latin, webforms, chats, colores, 2FA…). Carga idioma del usuario (`vicidial_users.selected_language`). |
| 952–1004 | **Saneamiento extra** | Regex de limpieza por si `non_latin` está activo o no. Manejo de `$force_logout`. |
| 1006–1081 | **DEFINABLE SETTINGS** | ~40 variables hard-codeadas que puedes sobreescribir desde `options.php` (si existe). ⭐ Aquí viven los flags de comportamiento de la pantalla. |
| 1083–1143 | **Colores y logo** | Paleta por defecto + override desde tabla `vicidial_screen_colors`. Selección del logo. |
| 1145–1187 | **Variables de entorno web** | `$PHP_SELF`, protocolo (http/https), `$FQDN`, `$agcPAGE` (URL de esta misma página), `$vdc_form_display`, reemplazo de `LOCALFQDN` en URLs. |
| 1189–1470 | **Cabecera HTML + selector de campaña** | `<head>`, CSS (`css/style.css`, `css/custom.css`), `calendar_db.js`, `confetti.php`. Construcción del `<select>` de campañas (solo campañas activas y permitidas al grupo del usuario). `browser_dimensions()` JS. |
| 1471–1650 | **Pantalla de LOGIN ZYNERVOX** | Tarjeta de login estilizada (`zv-login-*`). Si `$user_login_first=1` pide solo user/pass y autocompleta `phone_login/phone_pass` desde `vicidial_users`. Rama alternativa: login clásico de 4 campos (phone/user). |
| 1650–1730 | **Fallback phone-login** | Pantalla simple pidiendo solo `phone_login/phone_pass` si aún faltan. |
| 1730–2458 | **Validaciones de login** | `user_authorization()`, lockout por intentos fallidos, cambio de contraseña forzado, 2FA (contenedor, envío de código por EMAIL/PHONE/SMS y validación), window validation. |
| 2460–2898 | **Checks previos al login efectivo** | Timeclock logueado (si no → error), Shift Enforcement (fuera de turno → error con opción de manager override), etiquetas de campos custom. |
| 2959–4365 | **Carga masiva de configuración del agente** | Estado de campaña, statuses de dispo (sistema + usuario + campaña + hotkeys), settings de campaña (~150 columnas), script de campaña, presets, pause codes, in-groups (CLOSER), territorios, group aliases, leads en hopper. |
| 4366–4555 | **Phone login load balancing** | Elección de servidor para el teléfono del agente según `vicidial_servers` y settings containers. |
| 4556–4950 | **Registro de sesión** | Validación teléfono↔usuario (`vicidial_users` + `phones`), obtención de `$server_ip`, `$SIP_user`, `$extension`, `vicidial_webservers`/`vicidial_urls` (métricas), reset de estados viejos (`ERI`, hopper, `vicidial_live_agents`). |
| 4952–5210 | **QueueMetrics + sipsak + login** | Settings QM, mensajes sipsak, `login_kickall` (patear sesiones viejas), cálculo del CallerID de login (`$SIqueryCID`). |
| 5015–5065 | **⭐ ZYNERVOX webphone temprano** | Inyección de `ZV_WEBPHONE_CONFIG` + carga de `assets/js/sip.min.js` + `assets/js/webphone.js` + `zvStartPhone()`, `flush()` y `sleep(5)` ANTES del Originate (ver §10.2). |
| 5066–5570 | **Originate de login** | INSERT en `vicidial_manager` para llamar al teléfono del agente; rama webphone nativa VICIDIAL (iframe) alternativa; vicidial_agent_log, campañas permitidas, peso y llamadas del día. |
| 5575–5644 | **Longitudes de campos** | `SHOW COLUMNS FROM vicidial_list` → `$MAX*` para maxlength de inputs. |
| 5645–5775 | **Logs + agent screen settings** | `vicidial_user_log` LOGIN, `vicidial_agent_log` insert, settings de `vicidial_users` (sidebar, fullscreen, etc.). |
| 5775–5975 | **Callback calendar** | Construcción del calendario de 12 meses para callbacks programados. |
| 5980–6560 | **Cálculos de layout** | Dimensiones (`$BROWSER_WIDTH/HEIGHT`, `$MASTERwidth/HEIGHT`, alturas de paneles, `$HKheight`, `$SFheight`, `$HTheight`…). Zonas: `DO NOT EDIT`. |
| 6560–7000 | **Impresión de variables JS** | ~250 `var X = '<?php echo ... ?>'` — el puente PHP→JS. ⭐ Casi toda la config de campaña/usuario llega al JS aquí. |
| 7000–7150 | **Utilidades JS de visibilidad** | `confirmExit`, visibility API (`onVisible/onHidden`), sonido si el agente oculta la ventana. |
| 7154–7937 | **Funciones JS de llamada básicas** | `livehangup_send_hangup`, `play_browser_sound`, `volume_control`, `MuteRecording`, `alert_control`, `custom_button_transfer`, `xfer_park_dial`, `leave_3way_call`, `SendManualDial`, `basic_originate_call` (⭐ core de marcado), `set_length`, `SendConfDTMF`, `check_for_conf_calls`. |
| 7938–9680 | **Grabación y transferencias** | `conf_send_recording`, `mainxfer_send_redirect` (⭐ núcleo de transfers BLIND/CLOSER/LOCAL/AGENTDIRECT), `transfer_email`, `ManualDialAltDonE`, `DialLog`. |
| 9680–10483 | **Contadores y alertas** | `DiaLableLeaDsCounT`, `AlerTDisplaY`, `CalLBacKsCounTCheck`, `CalLBacKsLisTCheck`, `InternalChatsCheck`, `alert_box`. |
| 10345–12071 | **Marcado manual** | `new_callback_call`, `manual_dial_finished`, `NeWManuaLDiaLCalL`, `LeaDSearcHSelecT`, `NeWManuaLDiaLCalLSubmiT`, `NoDiaLSwitcH`, `ManualDialCheckChanneL`, `UpdateFieldsData`, `ManualDialNext` (⭐ dial next / callbacks / alt-phone). |
| 12955–13500 | **Manual dial avanzado** | `ManualDialSkip`, `ManualValidateSubmit/Cancel`, `ManualDialOnly`. |
| 13504–13724 | **Pausa/Resume + detección** | `AutoDial_ReSume_PauSe` (⭐ pause codes, wrapup), `ReChecKCustoMerChaN`. |
| 13725–14708 | **Scripts, alt-phone, formularios** | `load_script_contents`, `alt_phone_change`, `UpdateFieldsData`, `check_for_incoming_other` (⭐ reloj principal de detección de llamada). |
| 14709–16452 | **Ciclo de vida de la llamada** | `RefresHScript`, `WebFormRefresH(1/2/3)`, `DispoHanguPAgaiN`, `bothcall_send_hangup`, `hangup_customer_button_click`, `dialedcall_send_hangup` (⭐ colgar + post-call URLs), `xfercall_send_hangup`, `DialTimeHangup`. |
| 16502–17173 | **Disposiciones** | `CustomerData_update`, `DispoSelectContent_create`, `PauseCodeSelectContent_create`, búsquedas, presets, VM messages, group alias, `WeBForMDispoSelect_submit`, `DispoSelect_submit` (⭐ disposition + logging + pausa). |
| 17737–18450 | **Zonas horarias, dead triggers, pause codes** | `SBC_timezone_choose`, `dead_trigger_url_send`, `SendURLs`, `PauseCodeSelect_submit`, manager approval. |
| 18029–18244 | **Settings + presets DTMF** | `UpdatESettingS`, `DtMf_PreSet_a..e`, timers de transferencia. |
| 18245–18732 | **Estados de sesión** | `CustomerChanneLGone`, `CustomerGoneOK/Hangup`, `NoneInSession*`, `CloserSelect*` (in-groups), `TerritorySelect*`. |
| 18773–19300 | **Logout + hotkeys** | `BrowserCloseLogout`, `NormalLogout`, `LogouT`, `hotkeypress` (dos variantes: auto-dial y manual), `enter_disable`, `URLDecode`. |
| 19300–20030 | **Formato de teléfono** | `phone_number_format`. |
| 20030–20360 | **Vistas auxiliares** | `refresh_agents_view`, `callinqueuegrab`, `refresh_calls_in_queue`, `AgentsViewOpen`, `webphoneOpen`, `XferAgentSelect*`, `call_requeue_launch`. |
| 20357–21220 | **Logs visuales y búsquedas** | `VieWLeaDInfO`, `VieWCalLLoG`, `ContactSearch*`, `LeadSearch*`, `ManualDialHide`, `VieWNotesLoG`, `LaunchAgentTimeReport`, `webform_click_log`, `agent_events`. |
| 20998–21224 | **Carga de paneles** | `FormContentsLoad`, `EmailContentsLoad`, `CustomerChatContentsLoad`, `InternalChatContentsLoad`, `DispoMinimize/Maximize`. |
| 21224–21500 | **Callbacks y timer actions** | `CallBackDatE_submit`, `TimerActionRun` (HANGUP/CALLMENU/EXTENSION/IN_GROUP/Dx_DIAL_QUIET), `WrapupFinish`, `HKWrapupFinish`. |
| 21497–22700 | **⭐ all_refresh()** | El bucle maestro (~1s): relojes, detección de llamada, refresco de listas, llamadas en cola, agentes, callbacks, chats, detección de cliente colgado. `pause()/start()/faster()/slower()`. |
| 22700–23460 | **UI: paneles y pestañas** | `showDiv/hideDiv/clearDiv/buildDiv`, `conf_channels_detail`, `HotKeys`, `ViewComments`, `ShoWTransferMain`, `MainPanelToFront`, `ScriptPanelToFront`, `FormPanelToFront`, `EmailPanelToFront`, `CustomerChatPanelToFront`, `InternalChatPanelToFront`. |
| 23390–23620 | **⭐ SHELL ZYNERVOX** | CSS `zv-*`, sidebar con botones READY/PAUSA/COLGAR/LLAMAR/CERRAR, indicador de webphone, iframe CRM, funciones `zv*`. |
| 23620–25360 | **HTML de la pantalla** | `<form vicidial_form>`, LoadingBox, Header, Tabs, MainPanel (info cliente + controles), ScriptPanel, FormPanel, EmailPanel, ChatPanels, TransferMain, DispoSelectBox, WrapupBox, NeWManuaLDiaLBox, AlertBox, etc. |
| 25360–25431 | **Cierre** | Audios de alerta (chat/email/sonidos BAS), `$INSERT_before_body_close`, cierre de `zv-main`/`zv-app-shell`, `exit`. |

---

## 3. Dependencias externas

### Scripts PHP requeridos (mismo directorio `agc/`)
| Archivo | Para qué |
|---|---|
| `dbconnect_mysqli.php` | Conexión MySQLi (`$link`, `$server_ip`…) |
| `functions.php` | `_QXZ()` (traducción), `user_authorization()`, `mysql_error_logging()`, `mysql_to_mysqli()` |
| `options.php` *(opcional)* | Override de variables sin tocar el código (ver §5.3) |

### Scripts PHP llamados por AJAX desde el JS
`vdc_db_query.php` · `manager_send.php` · `conf_exten_check.php` · `vdc_script_display.php` · `vdc_form_display.php` · `vdc_email_display.php` · `vdc_chat_display.php` · `agc_agent_manager_chat_interface.php` · `timeclock.php` · `api.php` (desde el exterior)

### Assets
| Ruta | Contenido |
|---|---|
| `assets/js/sip.min.js` | Librería SIP.js (WebSocket → RTP/WebRTC) |
| `assets/js/webphone.js` | Webphone ZYNERVOX (ver §10.2) |
| `css/style.css`, `css/custom.css` | Estilos base + custom |
| `calendar_db.js`, `calendar.css`, `confetti.php` | Calendario de callbacks |
| `sounds/*.mp3` | Alertas (`browser_alert_sounds_list` contiene los nombres) |
| `images/vicidial_admin_web_logo.png` | Logo |

---

## 4. Flujo de ejecución

```
GET/POST zynervox.php
      │
      ├─ ¿force_logout? ──► mensaje y exit
      │
      ├─ Faltan VD_login/VD_pass ──► PANTALLA LOGIN (tarjeta ZYNERVOX) ──► exit
      │
      ├─ Faltan phone_login/phone_pass
      │     ├─ $user_login_first=1 ──► autocompletar desde vicidial_users.phone_login/phone_pass
      │     └─ si aún faltan ──► PANTALLA PHONE LOGIN ──► exit
      │
      ├─ user_authorization() falla ──► pantalla de error / lockout ──► exit
      ├─ force_change_password ──► PANTALLA CAMBIO DE CONTRASEÑA ──► exit
      ├─ 2FA activo ──► PANTALLA CÓDIGO AUTH (EMAIL/PHONE/SMS) ──► exit
      ├─ Timeclock no logueado ──► ERROR ──► exit
      ├─ Fuera de shift (y sin override) ──► ERROR ──► exit
      │
      ├─ LOGIN EFECTIVO:
      │    1. Marcar leads QUEUE/INCALL viejos como ERI, limpiar hopper/vla/vlia
      │    2. Registrar vicidial_user_log LOGIN
      │    3. Reservar sala de conferencia (vicidial_conferences/confbridges)
      │    4. ⭐ ZYNERVOX: arrancar webphone SIP.js ya (flush + sleep 5)
      │    5. INSERT vicidial_manager → Asterisk llama al agente y lo mete a la sala
      │    6. Insertar vicidial_agent_log
      │
      └─ PANTALLA DEL AGENTE:
           - Variables PHP → JS (6560–7000)
           - HTML de paneles (23620+)
           - onload → begin_all_refresh() → all_refresh() cada ~1s
```

### El ciclo `all_refresh()` (corazón del runtime)

Cada segundo (variable `refresh_rate`, ajustable con `faster()/slower()`):

1. Actualiza relojes (`UnixTime`, `epoch_sec`, fechas formateadas).
2. Llama AJAX `vdc_db_query.php` con `stage=add`/`update` → devuelve el bloque `||AGENTrefresh...||` con: estado del agente, llamada en curso, lead, canal, pausas, callbacks, chats, emails.
3. Parsea esa respuesta y dispara reacciones: mostrar DispoSelectBox si el cliente colgó, lanzar sonidos, refrescar script/webform, contadores de callbacks/cola, etc.
4. `check_for_incoming_other()` detecta llamada nueva → muestra datos del lead.
5. Si no hay respuesta del servidor `conf_check_attempts` veces → aviso de conexión.

---

## 5. Configuración y variables clave

### 5.1 Variables de debug/logging (líneas 747–758)
```php
$version = '2.14-710c';  $build = '240830-1112';
$mel=1;                  # MySQL Error Log habilitado
$mysql_log_count=103;
$DB=0;                   # debug web: ?DB=1 imprime queries (requiere SSallow_web_debug)
```

### 5.2 DEFINABLE SETTINGS (líneas 1006–1081) — los más útiles

| Variable | Default | Efecto |
|---|---|---|
| `$user_login_first` | `1` | ⭐ **ZYNERVOX**: login solo user/pass; phone creds se autocompletan de `vicidial_users` |
| `$conf_silent_prefix` / `$dtmf_silent_prefix` | `5` / `7` | Prefijos para entrar silencioso a conferencias |
| `$HKuser_level` | `1` | Nivel mínimo de usuario para hotkeys |
| `$campaign_login_list` | `1` | Mostrar dropdown de campañas en login |
| `$manual_dial_preview` | `1` | Permitir preview en marcado manual |
| `$multi_line_comments` | `1` | Comentarios multi-línea |
| `$view_scripts` | `1` | Mostrar pestaña SCRIPT |
| `$no_delete_sessions` | `1` | No borrar sesiones al logout |
| `$LogouTKicKAlL` | `1` | Colgar todas las llamadas de la sesión al cerrar sesión |
| `$hangup_all_non_reserved` | `1` | Colgar canales no reservados al Hangup Customer |
| `$HidEMonitoRSessionS` | `1` | Ocultar canales de monitoreo en "calls in session" |
| `$volumecontrol_active` | `1` | Control de volumen por canal |
| `$PhonESComPIP` | `1` | Guardar IP de la PC en `phones.computer_ip` |
| `$PreseT_DiaL_LinKs` | `0` | Mostrar links DIAL de presets |
| `$agentcallsstatus` / `$callholdstatus` | `0`/`1` | Estadísticas de agentes/llamadas en espera en cabecera |
| `$stretch_dimensions` | `1` | Ajustar pantalla al tamaño del navegador |
| `$BROWSER_WIDTH/HEIGHT` | `770`/`500` | Tamaño mínimo de diseño |
| `$webphone_width/height` | `460`/`500` | Dimensiones del iframe webphone nativo |
| `$window_validation` | `0` | Si `1`, solo permite logins desde ventana lanzada por `launch.php` (`$win_valid_name`) |
| `$focus_blur_enabled` | `0` | Bloqueo de Enter con focus/blur (problemas IE) |
| `$TEST_all_statuses` | `0` | SOLO DEBUG: todos los statuses en dispo |
| `$INSERT_head_script`, `$INSERT_head_js`, `$INSERT_first_onload`, `$INSERT_window_onload`, `$INSERT_agent_events` | `''` | **Hooks de inyección** (ver §11) |

> ⚠️ Si existe `options.php` en el directorio, se incluye y puede sobreescribir **cualquiera** de estas variables. Es el mecanismo oficial para cambios que sobrevivan a upgrades.

### 5.3 Variables de entrada ($_GET/$_POST) principales
`DB` (debug) · `JS_browser_width/height` · `phone_login/phone_pass` (alias `pl`/`pp`) · `VD_login/VD_pass` · `VD_campaign` · `VD_language` · `relogin` · `MGR_override` (+`MGR_login/pass<fecha>`) · `admin_test` · `LOGINvarONE..FIVE` · `hide_relogin_fields` · `set_pass`, `new_pass1/2` · `stage` · `rank` · `auth_entry` · `force_logout`

Todas pasan por `preg_replace` de saneamiento (más estricto si `non_latin < 1`).

---

## 6. Pantallas que genera el PHP

| Pantalla | Condición de salida | Líneas aprox. |
|---|---|---:|
| **Login ZYNERVOX** (tarjeta, user/pass/campaña) | faltan credenciales de usuario | 1562–1638 |
| Login clásico (4 campos) | `$user_login_first` desactivado / no hay phone creds en BD | 1641–1696 |
| Phone login | faltan solo phone creds | 1730–1790 |
| Cambio de contraseña | `force_change_password=Y` | 2500–2550 |
| 2FA — envío de código | contenedor 2FA activo | 2085–2291 |
| 2FA — elegir método | primera vez | 2293–2383 |
| Errores (timeclock, shift, lockout, campaign inactiva…) | validaciones fallidas | dispersas |
| **Pantalla del agente** | login OK | 6560–25431 |

---

## 7. Base de datos: tablas usadas

| Tabla | Uso en este archivo |
|---|---|
| `system_settings` | ~120 columnas leídas (2 lookups: arranque y login) |
| `vicidial_users` | credenciales, permisos del agente (~40 columnas), phone_login/pass, idioma, 2FA |
| `vicidial_user_groups` | `allowed_campaigns` (filtro del dropdown de campañas) |
| `vicidial_campaigns` | settings de campaña (~150 columnas: dial method, statuses, webforms, xfer…) |
| `vicidial_campaign_statuses` / `vicidial_statuses` / `vicidial_user_statuses` | statuses de disposición (+hotkeys, status groups) |
| `vicidial_live_agents` (vla) | estado en tiempo real del agente |
| `vicidial_live_inbound_agents` | blended/inbound en tiempo real |
| `vicidial_auto_calls` | llamada en curso |
| `vicidial_list` | datos del lead; `SHOW COLUMNS` para maxlengths |
| `vicidial_hopper` | leads listos para marcar (se limpia al login) |
| `vicidial_conferences` / `vicidial_confbridges` | reserva de sala (`extension` = usuario) |
| `vicidial_manager` | ⭐ cola de comandos hacia Asterisk (Originate, Hangup…) |
| `vicidial_log` / `vicidial_closer_log` | log de llamadas |
| `vicidial_agent_log` | log de sesión del agente (pausas, dispo…) |
| `vicidial_user_log` | eventos LOGIN/LOGOUT |
| `vicidial_user_dial_log` | tipo de llamada de login (APL) |
| `vicidial_agent_function_log` | 2FA y funciones API |
| `vicidial_callbacks` / `vicidial_list` (callbacks) | callbacks programados/agent-only |
| `vicidial_inbound_groups` | grupos disponibles (CLOSER) y para transferencias |
| `vicidial_phone_alias` / `phones` | teléfono del agente, computer_ip |
| `vicidial_servers` | server_ip, conf_engine, ext_context, codecs… |
| `vicidial_webservers` / `vicidial_urls` | métricas de servidores web |
| `vicidial_scripts` | scripts activos |
| `vicidial_presets` / `vicidial_campaign_presets` | presets de transferencia/DTMF |
| `vicidial_pause_codes` | códigos de pausa |
| `vicidial_screen_colors` | paleta |
| `vicidial_territories` / `vicidial_campaign_territories` | territorios |
| `vicidial_users.custom_one..five` | variables custom del usuario |
| `vicidial_two_factor_auth` | códigos 2FA |
| `routing_initiated_recordings` | grabaciones iniciadas por routing (se invalidan al login) |
| `vicidial_admin_log` | manager override de shifts |
| `web_client_sessions` | sesión del cliente web |

> Convención de errores: cada query lleva `mysql_error_logging($NOW_TIME,$link,$mel,$stmt,'NNNNN',...)`. El código de 5 dígitos (ej. `01031`) identifica el punto exacto del archivo. Búscalo en los logs para localizar la línea.

---

## 8. JavaScript: motor del agente

Toda la interacción vive en JS generado por PHP. Groupos funcionales:

### 8.1 Estado global (vars PHP→JS, 6560–7000)
Variables como `VDRP_stage` (READY/PAUSED/CLOSER), `VD_live_customer_call`, `MD_channel_look`, `livecall_alt_dial`, `call_id`… son el espejo JS del estado del agente. **Si agregas un setting de campaña, debes exponerlo aquí** para usarlo en JS.

### 8.2 Funciones núcleo (llamadas desde los botones)
| Función | Qué hace |
|---|---|
| `basic_originate_call(...)` | Crea el Originate (INSERT `vicidial_manager` vía `manager_send.php`) para marcar |
| `AutoDial_ReSume_PauSe(action,...)` | Pausa/Resume con pause codes; actualiza `vicidial_live_agents` |
| `dialedcall_send_hangup(...)` | Colgar cliente + URLs post-call + preparar dispo |
| `DispoSelect_submit(...)` | Guarda dispo + comentarios + alt_phone + pausa posterior |
| `ManualDialNext(...)` | "Dial next number" en modos MANUAL/INBOUND_MAN; también callbacks |
| `NeWManuaLDiaLCalL / ...SubmiT` | Caja de llamada manual (lookup de lead, DNC, filtro) |
| `mainxfer_send_redirect(...)` | Motor de transferencias: BLIND, CLOSER (in-group), LOCAL, AGENTDIRECT, con consultative |
| `leave_3way_call(...)` | Dejar la llamada 3-way (con mensaje de buzón opcional) |
| `check_for_conf_calls / conf_channels_detail` | Listar canales de la conferencia; volumen/mute por canal |
| `SendConfDTMF / SendManualDial` | Enviar DTMF / marcar desde el panel de transferencia |
| `hotkeypress(evt)` | Hotkeys de disposición (dos variantes según modo) |
| `all_refresh()` | Bucle maestro de 1s (ver §4) |
| `check_for_incoming_other()` | Detección de llamada entrante y presentación del lead |
| `LogouT(reason,...)` / `NormalLogout()` | Logout completo o AJAX |
| `TimerActionRun(...)` | Acciones temporizadas (HANGUP/CALLMENU/EXTENSION/IN_GROUP) |
| `UpdatESettingS()` | Re-lee settings de usuario/campaña (AJAX `vdc_db_query.php?stage=update_settings`) |
| `zvSetReady / zvSetPause / zvHangup / zvManualDial / zvLogout` | ⭐ Puentes del sidebar ZYNERVOX a la UI VICIDIAL (§10.3) |

### 8.3 Cajas (spans) flotantes
`AlertBox`, `DispoSelectBox`, `WrapupBox`, `CustomerGoneBox`, `NoneInSessionBox`, `NeWManuaLDiaLBox`, `CallBacksLisTBox`, `CalLLoGDisplaYBox`, `SearcHResultSDisplaYBox`, `TransferMain`, `PauseCodeSelectBox`, `PresetsSelectBox`, `GroupAliasSelectBox`…
Todas se ocultan al inicio en `start_all_refresh()` y se muestran con `showDiv()/hideDiv()`. **Nota ZYNERVOX:** el CSS de §10.4 las deja con `visibility:hidden` por defecto; los `showDiv` de VICIDIAL las muestran cuando corresponde.

---

## 9. Comunicación en tiempo real (AJAX)

- Todo usa `XMLHttpRequest` (no fetch). Patrón típico:
  ```js
  xmlhttp.open('POST','vdc_db_query.php');
  xmlhttp.setRequestHeader('Content-type','application/x-www-form-urlencoded');
  xmlhttp.send('stage=add&...' + params);
  ```
- `vdc_db_query.php` responde con un bloque de texto delimitado `||AGENTrefresh...||` que `all_refresh()` parsea con `indexOf/slice` (no XML/JSON).
- `manager_send.php` se usa para INSERTs en `vicidial_manager` desde JS (colgar, transferir, grabar, DTMF, estacionar…).
- `conf_exten_check.php` sincroniza hora y validad la sala cada pocos segundos.

---

## 10. Personalizaciones ZYNERVOX

Busca los marcadores `/* ZYNERVOX ... */` o `# ZYNERVOX` (hay ~20) y las funciones `zv*`.

### 10.1 Login "user-first" (línea 1016)
```php
$user_login_first = '1';  # ZYNERVOX: pide solo user/pass; phone_login/phone_pass
                          # se autocompletan desde vicidial_users.phone_login/phone_pass
                          # (mismo valor que el username, por convención)
```
Efecto: el agente nunca ve campos de teléfono. Requiere que en `vicidial_users` estén llenos `phone_login` y `phone_pass`.

### 10.2 Webphone SIP.js arrancado antes del Originate (líneas ~5015–5065) ⭐ crítico
**Problema que resuelve:** VICIDIAL insertaba el Originate (llamada al agente) *antes* de que el navegador hubiera registrado el webphone SIP → "invalid URI… registered and reachable?".

**Solución implementada:** inyectar temprano la config y arrancar el webphone:
```php
echo "window.ZV_WEBPHONE_CONFIG = { user, password, domain:'demo.zynervox.site',
      wssUrl:'wss://demo.zynervox.site:8089/ws', displayName, stunServer }";
echo "<script src='assets/js/sip.min.js'></script>";
echo "<script src='assets/js/webphone.js?v=1'></script>";
echo "<script>zvStartPhone();</script>";
@ob_flush(); flush(); sleep(5);   // espera real ANTES del INSERT del Originate
```
⚠️ Reglas:
- **Única inclusión** de `sip.min.js`/`webphone.js` en toda la página (la segunda inclusión re-ejecuta `let zvUserAgent=null` y crea una 2ª conexión que compite por el contacto SIP `max_contacts=1`).
- Cambiar dominio/WSS aquí si cambia la infraestructura SIP.
- `zv_presession.php` existe como página de prueba de esta carrera de registro (registra el webphone primero y solo habilita el botón "entrar" cuando el registro SIP está confirmado).

### 10.3 Sidebar y shell ZYNERVOX (líneas ~23347–23453)
- `#zv-app-shell` > `#zv-sidebar` + `#zv-main`. El ancho del layout VICIDIAL se descuenta del sidebar (`browser_dimensions()` en línea ~1333).
- Botones: READY, PAUSA, COLGAR, LLAMAR manual, CERRAR SESIÓN.
  - **2026-09-16 (v1) — recoloreado a paleta de marca (intermedio, ya reemplazado por v2):** READY/PAUSA/LLAMAR pasaron de verde/azul genéricos a naranja/navy/azul propios; COLGAR y CERRAR SESIÓN sin cambio. Seguían siendo "botones de colores" (una tinta distinta por acción), que es justo lo que v2 eliminó.
  - **2026-09-16 (v2) — rediseño "sin botones de colores", clonando la paleta real de `modules/admin/layout.css`** (a pedido explícito del usuario: mismos colores que la barra lateral de `/zynervox/modules/admin/users.php`, sin botones de colores, sencillo):
    - Fondo del sidebar `#0b1f33`→`#2D2F3B`, acento marca `#ff8a3d`→`#F5821F`, texto apagado `#9fb3cc`→`#9CA0AC` — **valores idénticos** a `:root` de `modules/admin/layout.css` (`--bg-sidebar`, `--primary`, `--sidebar-text-muted`).
    - Los 5 botones dejan de tener relleno de color: ahora son filas planas tipo `.nav-item` (clase `.zv-btn`, líneas ~23413–23420) — fondo transparente en reposo, texto `#9CA0AC`; hover = franja `rgba(255,255,255,.08)` + texto naranja `#F5821F` (mismo patrón que `.sidebar-link:hover/.nav-item.active` del admin).
    - Única excepción: COLGAR y CERRAR SESIÓN llevan clase extra `.zv-btn-danger` → texto rojo suave `#f5a3a3` (mismo tono que `.logout-link` del admin), sin relleno, mismo hover neutro. El resto (READY/PAUSA/LLAMAR) es visualmente idéntico entre sí — ya no hay color por acción.
    - `#zv-sidebar` perdió el padding horizontal fijo (`22px 18px`→`1.25rem 0`, como `.sidebar` del admin); cada fila interna (`.zv-btn`, `.zv-manual-dial-label`, `.zv-input`, `#zv-phone-row`, `<details>`) ahora trae su propio `padding:0 1rem` (o el `.zv-btn` lo incluye en su padding vertical+horizontal), para que el hover llegue de borde a borde del sidebar igual que en el admin.
    - `.zv-divider` pasó de borde azul `#1464a5` a `rgba(255,255,255,.08)` (línea sutil, igual que `--sidebar-border` del admin). `.zv-input` pasó a fondo/borde `rgba(255,255,255,.08)` (equivalente oscuro del `--sidebar-glass` del admin, que en el admin es claro porque su input vive en el área de contenido blanca, no en el sidebar).
    - **Fuera de alcance, queda con colores viejos:** `assets/js/webphone.js` sigue pintando el punto de estado del webphone con hex hardcodeados (`#02a5a5` registrado, `#9fb3cc` sin iniciar, líneas 66/177/181) — es un indicador de estado (no un botón), no se tocó; si se quiere unificar también, hay que editar ese archivo aparte.
  - **2026-09-16 (v3) — PAUSA pasó de botón+popup a `<select>` inline** (reemplaza la v2 de este mismo día; layout también cambió: READY y PAUSA ahora van lado a lado en `.zv-controls-row`, no apiladas). A pedido del usuario: no quería el popup nativo `#PauseCodeSelectBox` (tabla verde sin estilo propio) — quería elegir el motivo directo en un dropdown junto al botón READY.
    - HTML: `#zv-btn-pause` (botón) se reemplazó por `#zv-pause-select` (`<select>`), envuelto junto a `#zv-btn-ready` en `<div class="zv-controls-row">` (flex, lado a lado). Clase nueva `.zv-btn-half` (líneas ~23434–23443): controles compactos con fondo propio `rgba(255,255,255,.06)`, distinto del patrón de fila plana de ancho completo que usan LLAMAR/CERRAR SESION (ver v2) — aquí sí hace falta destacarlos como par de controles, no como lista.
    - JS: `zvFillPauseSelect(sel)` puebla el `<select>` en el `load` leyendo los mismos arrays globales que ya usa VICIdial para pintar su popup nativo (`VARpause_codes`/`VARpause_code_names`/`VD_pause_codes_ct`, línea ~6112) — no se tocó PHP, no se duplicó data. `zvPauseWithCode(code)` reproduce el orden del flujo nativo: primero `AutoDial_ReSume_PauSe('VDADpause',...)` (pasa a PAUSED de verdad: `VDRP_stage`/`DiaLControl`/`AutoDialWaiting`; si ya estaba pausado es inofensivo) y recién después fija el motivo — con `PauseCodeSelect_submit(code,'YES')` para códigos normales, o `PauseCodeOpen_mgrapr(code,name,'YES')` si el código tiene `VARpause_code_mgrapr[i]=='YES'` (requiere aprobación de supervisor; ese modal nativo `#PauseCodeMgrAprBox` no se tocó, sigue igual). `zvSetPause()`/`PauseCodeSelectContent_create('YES')` (v2) quedaron sin uso, eliminadas del wiring.
    - `php -l` local correcto.
  - **2026-09-16 (v3.1) — fix: STATUS quedaba `PAUSED` pero la columna `PAUSE` de "Agents Time On Calls" quedaba vacía** (bug real reportado por el usuario con captura de `admin.php` en vivo). Causa: `AutoDial_ReSume_PauSe()` manda su AJAX y hace `return agent_log_id` de forma **síncrona** (línea 13658), antes de que responda el servidor; el global `agent_log_id` recién se actualiza al ID nuevo dentro de su `onreadystatechange` (línea 13649). `zvPauseWithCode()` llamaba a `PauseCodeSelect_submit()` en el mismo tick, así que viajaba con el `agent_log_id` **viejo** (el de antes de pausar) y el `UPDATE` del código de pausa no impactaba la fila activa correcta. El popup nativo no sufre esto porque el agente tarda segundos en hacer clic en un código del popup — para entonces la respuesta ya llegó. **Fix:** `setTimeout(..., 600)` entre `AutoDial_ReSume_PauSe('VDADpause',...)` y el submit del código, dando tiempo a que la respuesta actualice `agent_log_id` antes de fijar el motivo. `php -l` local correcto.
  - **2026-09-16 (v2) — PAUSA ahora abre el popup de códigos de pausa** (reemplaza la v1 de este mismo día). Causa: la v1 llamaba `AutoDial_ReSume_PauSe('VDADpause',...)` directo, que solo abre el selector de códigos si `agent_pause_codes_active=='FORCE'` (línea ~13596); en esta campaña es `'Y'` (opcional, vía el link nativo "ENTER A PAUSE CODE" / `#PauseCodeLinkSpan`, confirmado por el usuario comparando con VICIdial normal), así que pausaba en silencio sin mostrar nada. **Fix:** `zvSetPause()` ahora llama `PauseCodeSelectContent_create('YES')` (línea 16704) — la misma función que usa el link nativo: valida si hace falta pre-pausar (`auto_pause_precall`) y pinta `#PauseCodeSelectBox` con los códigos reales de la campaña (`VARpause_codes`/`VARpause_code_names`). `zvSetReady()` no cambió (sigue en `AutoDial_ReSume_PauSe('VDADready',...)`, eso ya funcionaba). `php -l` local correcto.
  - **2026-09-16 — fix de fondo: `functions.php::mysql_to_mysqli()` (compartido por TODO `agc/`, no solo `zynervox.php`).** Causa raíz real de por qué el fix anterior de READY/PAUSA seguía sin funcionar en vivo: PHP 8.3 hace que `mysqli_query()` lance `mysqli_sql_exception` en vez de devolver `false` cuando una consulta falla (default desde PHP 8.1); todo el código VICIdial de 2006 asume el comportamiento viejo (revisa `$errno` después vía `mysql_error_logging()`, nunca espera excepción). Confirmado en `/var/log/apache2/error.log`: `Fatal error: Uncaught mysqli_sql_exception: Duplicate entry 'PLUSER001-HIDDEN-...' for key 'visibleuser'` tumbando `conf_exten_check.php` con 500 vía `functions.php:2764`. Cadena hacia el botón: si cualquier consulta dentro de `check_for_auto_incoming()` (polling de llamada entrante) falla así, esa petición AJAX responde 500, el JS nunca resetea `CFAI_sent` a 0, y cualquier clic posterior en READY/PAUSA queda bloqueado con el alert nativo "CHECK-FOR-CALL RUNNING, PLEASE WAIT" hasta recargar la página. **Fix:** `mysql_to_mysqli()` ahora envuelve `mysqli_query()` en `try/catch(mysqli_sql_exception)` y devuelve `false` en el catch, restaurando el comportamiento pre-8.1 que el resto del código ya espera. Alcance: archivo compartido por `vicidial.php`, `zynervox.php`, `vicidial_free.php`, `vdc_db_query.php`, `conf_exten_check.php`, `api.php` (todo `agc/`) — no es una personalización ZYNERVOX, es corrección de compatibilidad PHP 8.1+. `php -l` local correcto. **Workaround inmediato si vuelve a trabarse:** recargar la página/re-loguear resetea `CFAI_sent` en el cliente.
  - **2026-09-16 — fix: READY y PAUSA no funcionaban.** Causa: `zvSetReady()`/`zvSetPause()` adivinaban el estado leyendo el `alt` de la imagen dentro de `#DiaLControl` y simulaban clic sobre el primer `<a>` que encontraran ahí (`zvClickReal`). Dos fallas: (1) `#DiaLControl` arranca siempre con el HTML por defecto "Dial Next Number" (línea ~23694) hasta que el primer ciclo de `all_refresh()` lo actualiza — un clic en READY en ese instante disparaba `ManualDialNext(...)` en vez de poner al agente listo; (2) el fallback de PAUSA hacía clic en `#PauseCodeLinkSpan`, que normalmente está vacío (`<span id="PauseCodeLinkSpan"></span>`, solo se llena en casos puntuales) → el botón no hacía nada. **Fix:** `zvSetReady()`/`zvSetPause()` ahora llaman directo a `AutoDial_ReSume_PauSe('VDADready',...)` / `AutoDial_ReSume_PauSe('VDADpause',...)` (línea 13504), la función real de VICIdial, con los mismos parámetros que usan sus propios links nativos — ella misma valida llamada en curso, actualiza `DiaLControl` y abre selección de código de pausa si la campaña lo exige (`agent_pause_codes_active=='FORCE'`). `zvDialControlState()`/`zvClickReal()` quedan definidas pero ya no las usan estos dos botones (sigue viva para otros usos si hicieran falta). `php -l` local correcto.
  - **2026-09-16 — quitado el rótulo "Webphone"** del sidebar (era el `<div class="zv-manual-dial-label">Webphone</div>` justo antes de `#zv-phone-row`, línea ~23457): a pedido del usuario se eliminó esa línea; el indicador (punto + texto de estado), el audio remoto y el `<details>`/log siguen igual, solo sin el título de sección.
  - **2026-09-16 — texto de estado "En llamada" → "Listo"** (`assets/js/webphone.js` línea 61, dentro de `zvWireSession`): cuando la sesión SIP.js pasa a `Established` (el webphone queda conectado al canal/conferencia tras el Originate de login), el indicador `#zv-phone-status` decía "En llamada", que confundía porque en ese punto no hay necesariamente una llamada real de cliente en curso, solo el canal persistente listo. Ahora dice "Listo" (mismo color `#02a5a5`, mismo trigger). Los estados "Registrado" y "Sin iniciar" no cambiaron. `node --check` local correcto (sintaxis JS).
    - Solo se tocaron bloques CSS (`#zv-sidebar*`, `.zv-btn*`, `.zv-divider`, `.zv-manual-dial-label`, `.zv-input`, líneas ~23350–23422) y el HTML de `#zv-controls` (clases de los `<button>` + padding inline de `#zv-phone-row`/`<details>`, líneas ~23429–23453). JS (`zv*` funciones) sin cambios. `php -l` local y remoto correctos, hash local/remoto coincidente (`026807cf...`), `HTTP 200` en `https://194.36.91.174/agc/zynervox.php`, Apache/MariaDB/Asterisk activos sin reinicios. Backups remotos pendientes de confirmación visual del usuario antes de limpiar: `/var/www/html/agc/zynervox.php.bak.20260916-124417` (v1, recolor) y `/var/www/html/agc/zynervox.php.bak.20260916-132808` (v2, rediseño plano).
- Funciones puente (hacen *click real* sobre la UI VICIDIAL oculta para reutilizar toda su lógica):
  - `zvClickReal(id)` — click al primer `<a>` dentro del span oculto.
  - `zvDialControlState()` — lee el `alt` de la imagen de `#DiaLControl` (PAUSED/READY/MANUAL).
  - `zvSetReady()` / `zvSetPause()` / `zvHangup()`.
  - `zvManualDial()` — abre caja manual, escribe número y llama `NeWManuaLDiaLCalLSubmiT('NOW','YES')`.
  - `zvLogout()` — `NormalLogout()` + MutationObserver en `#LogouTProcess` → redirige a `zynervox.php` limpio (nunca muestra "LOGOUT PROCESS COMPLETE").
  - `zvSyncCrmFrame()` — copia el href de `#WebFormSpan` al iframe CRM `#zv-crm-frame` cada 1.5s.
  - Auto-click en `#DeactivateDOlDSessioNSpan` (cierra el aviso de sesión vieja).
- Indicador de webphone: `#zv-phone-dot`, `#zv-phone-status`, log `#zv-phone-log`, audio `#zv-phone-audio` (lo consume `webphone.js`).

### 10.4 Ocultamiento de UI vieja (CSS + JS)
- CSS (líneas ~23390+): spans VICIDIAL con `visibility:hidden` (AlertBox, NeWManuaLDiaLBox, etc.) — la lógica sigue viva, solo se ocultan.
- `agent_logout_link` forzado a `'0'` (línea 6818).
- Bloques PHP de "Another live agent session..." y "No one is in your session" neutralizados (líneas 24574, 25330) a pedido del usuario.

### 10.5 Otras variantes del proyecto
| Archivo | Rol |
|---|---|
| `vicidial.php` | Original VICIDIAL sin modificar |
| `vicidial_free.php` | Variante "ZYNERVOX EDITION" minimalista (sidebar oscura) |
| `zynervox.php` | **Esta** pantalla (login premium + sidebar + webphone SIP.js) |
| `api.php` | API para control externo del agente |
| `zv_presession.php` | Laboratorio de pre-registro SIP |

---

## 11. Puntos de extensión (INSERT_*)

Variables vacías por defecto (idealmente desde `options.php`) que el código inserta en lugares concretos del HTML/JS final:

| Variable | Se inyecta en |
|---|---|
| `$INSERT_head_script` | Justo antes de `<script language="Javascript">` principal (post-login) |
| `$INSERT_head_js` | Después de la primera función JS |
| `$INSERT_first_onload` | Al inicio de `begin_all_refresh()` |
| `$INSERT_window_onload` | Al final del `onload` |
| `$INSERT_agent_events` | Dentro de `agent_events()` |

Úsalas para agregar JS/CSS sin tocar el archivo (sobrevive a actualizaciones si se define en `options.php`).

---

## 12. Guía de modificación

### Reglas generales
1. **No tocar** las zonas marcadas `### SCREEN WIDTH AND HEIGHT CALCULATIONS ###  DO NOT EDIT`.
2. Cualquier setting nuevo de campaña/usuario: leerlo en PHP (§6 config de campaña), exponerlo como `var` JS (líneas 6560–7000), y usarlo en la función JS correspondiente.
3. Mantener el estilo: `"..."._QXZ("texto")."..."` para todo texto visible (traducción).
4. Los `alt` de las imágenes de control (`vdc_LB_paused.gif`, `vdc_LB_active.gif`…) **son datos**: `zvDialControlState()` y VICIDIAL los leen; no cambiarlos.
5. Probar siempre con `?DB=1` (si `SSallow_web_debug=1`) y revisar el SQL que se imprime.

### Recetas rápidas

**A) Cambiar textos/branding del login**
→ Líneas 1562–1638 (`zv-login-card`). Los textos están en el HTML del echo.

**B) Cambiar dominio/WSS del webphone**
→ Líneas ~5038–5055 (`ZV_WEBPHONE_CONFIG`). Ver §10.2.

**C) Agregar un botón al sidebar**
→ HTML en `#zv-controls` (~23426), CSS `.zv-btn*`, JS en el `addEventListener('load')` (~23580). Para accionar lógica VICIDIAL usa `zvClickReal('IdDelSpan')`.

**D) Agregar/quitar campaña del dropdown del login**
→ Es automático por `vicidial_user_groups.allowed_campaigns` (líneas 1215–1240). El texto visible es `campaign_name` (línea ~1282) pero el `value` es el ID.

**E) Cambiar comportamiento de pausa/diposición**
→ `AutoDial_ReSume_PauSe` (13504) y `DispoSelect_submit` (17173).

**F) Cambiar el bucle de refresco**
→ `all_refresh()` (22440). Velocidades en `pause()/start()/faster()/slower()` (22689–22702).

**G) Ajustar layout/tamaños**
→ Bloque de cálculo 5980–6560 (`$BROWSER_*`, `$MASTER*`, `$SFheight`, `$HTheight`, `$MNwidth`…). Respeta `DO NOT EDIT` solo en el bloque indicado; el resto es calculable.

**H) Alertas de sonido**
→ Lista en `$browser_alert_sounds_list` (línea ~1127); reproducción con `play_browser_sound()` (7226). Los `<audio>` se imprimen al final (25390+).

### Checklist antes de tocar producción
- [ ] Copia de seguridad del archivo (`cp zynervox.php zynervox.php.bak-$(date +%Y%m%d)`)
- [ ] `php -l zynervox.php` (sintaxis)
- [ ] Probar login completo con un agente de prueba (webphone registra → llamada de login suena)
- [ ] Probar: llamada auto-dial, manual, transferencia blind y closer, dispo, pausa, logout
- [ ] Revisar `vicidial_user_log` y `mysql error log` por errores nuevos

---

## 13. Depuración y problemas comunes

| Síntoma | Dónde mirar |
|---|---|
| Agente no recibe la llamada de login | §10.2: ¿webphone registrado antes del Originate? Revisa log `#zv-phone-log` y el INSERT de `vicidial_manager` (¿existe contacto SIP registrado? `pjsip show contacts`) |
| "Conference no conecta" | Reserva de sala 4809–4870; verificar `vicidial_conferences.extension` no quedó travado del login anterior (se limpia al loguear) |
| Login rechazado sin razón aparente | Lockout de 10 intentos/15min; `user_authorization()` en `functions.php`; case-sensitive users |
| Pantalla en blanco tras logout | El flujo ZYNERVOX redirige solo; si aparece, revisar `zvLogout()` (23500) y `#LogouTProcess` |
| Cajas que aparecen "de la nada" | CSS de visibility (23390) + `showDiv/hideDiv`; ver comentario ZYNERVOX línea 23390 |
| Queries lentas/errores SQL | `$mel=1` + código de error NNNNN → buscar en el archivo |
| Ver las queries | `?DB=1` en la URL (requiere `allow_web_debug=1` en `system_settings`) |
| El iframe CRM no carga la URL | `zvSyncCrmFrame()` depende de `#WebFormSpan a[href]`; verificar web form de campaña/usuario |

---

### Glosario rápido
- **VLA**: `vicidial_live_agents`, tabla de estado en vivo.
- **Originate**: comando Asterisk (AMI) para iniciar una llamada; en VICIDIAL se encola como fila en `vicidial_manager`.
- **Dispo (disposition)**: resultado que el agente asigna a la llamada (SALE, DROP, NI…).
- **CLOSER**: campaña de entrada; **in-group**: cola de entrada.
- **Hopper**: `vicidial_hopper`, leads preparados para marcar.
- **Wrapup**: tiempo post-llamada antes de estar READY.
- **3-way / CXFER / AXFER**: tipos de transferencia/conferencia.
- **QM**: QueueMetrics.
- **sipsak**: herramienta para mensajes SIP de prueba/informativos.
