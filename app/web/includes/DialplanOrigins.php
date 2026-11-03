<?php
namespace Includes;

class DialplanOrigins {
    const HEADER = "; Manual calls: list 1. Future worker must set ZV2_LIST_ID explicitly.\n[zynervoxv2]\n";

    const COMMON = <<<'ASTERISK'
[zynervoxv2-bot-ivr]
exten => s,1,Set(AGISIGHUP=no)
 same => n,MixMonitor(/var/lib/zynervoxv2/asterisk/recordings/bot_ivr/${FILTER(0-9A-Za-z._-,${ZV2_CALL_ID})}.wav)
 same => n,Gosub(zynervoxv2-amd,s,1)
 same => n,GotoIf($["${AMDSTATUS}" = "MACHINE"]?fin)
 same => n,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/ivr_engine_agi.php,${ZV2_LIST_ID},${ZV2_LEAD_ID},${ZV2_CALL_ID})
 same => n(fin),StopMixMonitor()
 same => n,Set(GOSUB_RESULT=CONTINUE)
 same => n,Return()

[zynervoxv2-bot-ivr-sin-amd]
exten => s,1,Set(AGISIGHUP=no)
 same => n,MixMonitor(/var/lib/zynervoxv2/asterisk/recordings/bot_ivr/${FILTER(0-9A-Za-z._-,${ZV2_CALL_ID})}.wav)
 same => n,Set(MASTER_CHANNEL(ZV2_ANSWERED)=1)
 same => n,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,event,${ZV2_CALL_ID},ANSWER)
 same => n,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/ivr_engine_agi.php,${ZV2_LIST_ID},${ZV2_LEAD_ID},${ZV2_CALL_ID})
 same => n,StopMixMonitor()
 same => n,Set(GOSUB_RESULT=CONTINUE)
 same => n,Return()

[zynervoxv2-amd]
exten => s,1,Set(MASTER_CHANNEL(ZV2_ANSWERED)=1)
 same => n,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,event,${ZV2_CALL_ID},ANSWER)
 same => n,AMD()
 same => n,Set(MASTER_CHANNEL(ZV2_AMD_STATUS)=${AMDSTATUS})
 same => n,Set(MASTER_CHANNEL(ZV2_AMD_CAUSE)=${AMDCAUSE})
 same => n,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,event,${ZV2_CALL_ID},AMD,${AMDSTATUS},${AMDCAUSE})
 same => n,NoOp(AMD resultado=${AMDSTATUS} causa=${AMDCAUSE})
 same => n,GotoIf($["${AMDSTATUS}" = "MACHINE"]?casilla)
 same => n,Return()
 same => n(casilla),Set(GOSUB_RESULT=CONTINUE)
 same => n,Return()

[zynervoxv2-call-finish]
exten => s,1,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,finish,${ZV2_CALL_ID},${DIALSTATUS},${ZV2_AMD_STATUS},${ZV2_AMD_CAUSE},${HANGUPCAUSE},${ZV2_ANSWERED})
 same => n,Return()

[zynervoxv2-outbound]
exten => s,1,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,event,${ZV2_CALL_ID},OUTBOUND)
 same => n,Return()
[zynervoxv2-marcador]
exten => s,1,Set(AGISIGHUP=no)
 same => n,Set(CHANNEL(hangup_handler_push)=zynervoxv2-marcador-fin,s,1)
 same => n,Set(ZV2_ANSWERED=1)
 same => n,Set(ZV2_STAGE=ANSWER)
 same => n,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,event,${ZV2_CALL_ID},ANSWER)
 same => n,GotoIf($["${ZV2_AMD}" != "1"]?xfer)
 same => n,AMD()
 same => n,Set(ZV2_AMD_STATUS=${AMDSTATUS})
 same => n,Set(ZV2_AMD_CAUSE=${AMDCAUSE})
 same => n,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,event,${ZV2_CALL_ID},AMD,${AMDSTATUS},${AMDCAUSE})
 same => n,GotoIf($["${AMDSTATUS}" = "MACHINE"]?machine)
 same => n(xfer),Set(ZV2_STAGE=XFER)
 same => n,GotoIf($["${ZV2_XFER_TRUNK}" = "" | "${ZV2_XFER_DEST}" = ""]?sindestino)
 same => n,ExecIf($["${ZV2_XFER_TIMEOUT}" = ""]?Set(ZV2_XFER_TIMEOUT=45))
 same => n,Dial(SIP/${ZV2_XFER_TRUNK}/${ZV2_XFER_DEST},${ZV2_XFER_TIMEOUT},b(zynervoxv2-forward-headers^s^1))
 same => n,Hangup()
 same => n(sindestino),Set(ZV2_STAGE=XFER_NODEST)
 same => n,Hangup()
 same => n(machine),Set(ZV2_STAGE=AMD_MACHINE)
 same => n,Hangup()

[zynervoxv2-marcador-fin]
exten => s,1,Set(ZV2_SIP=)
 same => n,Set(ZV2_SIPKEY=${HANGUPCAUSE_KEYS()})
 same => n,GotoIf($["${ZV2_SIPKEY}" = ""]?fin)
 same => n,Set(ZV2_SIP=${HANGUPCAUSE(${ZV2_SIPKEY},tech)})
 same => n(fin),AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,finish,${ZV2_CALL_ID},${DIALSTATUS},${ZV2_AMD_STATUS},${ZV2_AMD_CAUSE},${HANGUPCAUSE},${ZV2_ANSWERED},${ZV2_SIP},${ZV2_STAGE})
 same => n,Return()

[zynervoxv2-marcador-salida]
; Marcador de pruebas (multicampaña por variables de canal). El motor origina Local/<teléfono>@zynervoxv2-marcador-salida/n con
; __ZV2_CALL_ID, __ZV2_TRUNK, __ZV2_DIAL_PREFIX y opcionales __ZV2_DIAL_TIMEOUT, __ZV2_TRANSFERIR=1, __ZV2_XFER_TRUNK, __ZV2_XFER_DEST, __ZV2_FORWARD_HEADERS.
; Quien marca es esta pata, no el Originate: así el cierre lee siempre el código SIP y la causa Q.850, también sin contestar.
exten => _X.,1,Set(AGISIGHUP=no)
 same => n,Set(CHANNEL(hangup_handler_push)=zynervoxv2-marcador-cierre,s,1)
 same => n,Set(ZV2_STAGE=DIAL)
 same => n,Set(ZV2_PHONE=${FILTER(0-9,${EXTEN})})
 same => n,GotoIf($["${ZV2_CALL_ID}" = "" | "${ZV2_TRUNK}" = "" | "${ZV2_PHONE}" != "${EXTEN}" | "${FILTER(A-Za-z0-9_.-,${ZV2_TRUNK})}" != "${ZV2_TRUNK}" | "${FILTER(0-9*#+,${ZV2_DIAL_PREFIX})}" != "${ZV2_DIAL_PREFIX}"]?sinruta)
 same => n,ExecIf($["${ZV2_DIAL_TIMEOUT}" = ""]?Set(ZV2_DIAL_TIMEOUT=30))
 same => n,Dial(SIP/${ZV2_TRUNK}/${ZV2_DIAL_PREFIX}${ZV2_PHONE},${ZV2_DIAL_TIMEOUT},b(zynervoxv2-outbound^s^1)U(zynervoxv2-marcador-contestada))
 same => n,Hangup()
 same => n(sinruta),Set(ZV2_STAGE=SIN_RUTA)
 same => n,Hangup()

[zynervoxv2-marcador-contestada]
exten => s,1,Set(AGISIGHUP=no)
 same => n,Set(MASTER_CHANNEL(ZV2_ANSWERED)=1)
 same => n,Set(MASTER_CHANNEL(ZV2_STAGE)=AMD)
 same => n,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,event,${ZV2_CALL_ID},ANSWER)
 same => n,AMD()
 same => n,Set(MASTER_CHANNEL(ZV2_AMD_STATUS)=${AMDSTATUS})
 same => n,Set(MASTER_CHANNEL(ZV2_AMD_CAUSE)=${AMDCAUSE})
 same => n,AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,event,${ZV2_CALL_ID},AMD,${AMDSTATUS},${AMDCAUSE})
 same => n,GotoIf($["${ZV2_TRANSFERIR}" = "1" & "${AMDSTATUS}" = "HUMAN"]?xfer)
 same => n,Set(MASTER_CHANNEL(ZV2_STAGE)=ESPERA)
 same => n,Wait(3)
 same => n,Set(MASTER_CHANNEL(ZV2_STAGE)=FIN_PRUEBA)
 same => n,Hangup()
 same => n(xfer),Set(MASTER_CHANNEL(ZV2_STAGE)=XFER)
 same => n,GotoIf($["${ZV2_XFER_TRUNK}" = "" | "${ZV2_XFER_DEST}" = ""]?sindestino)
 same => n,ExecIf($["${ZV2_XFER_TIMEOUT}" = ""]?Set(ZV2_XFER_TIMEOUT=45))
 same => n,Dial(SIP/${ZV2_XFER_TRUNK}/${ZV2_XFER_DEST},${ZV2_XFER_TIMEOUT},b(zynervoxv2-forward-headers^s^1))
 same => n,Set(ZV2_XFER_KEY=${HANGUPCAUSE_KEYS()})
 same => n,GotoIf($["${ZV2_XFER_KEY}" = ""]?finxfer)
 same => n,Set(MASTER_CHANNEL(ZV2_XFER_SIP)=${HANGUPCAUSE(${ZV2_XFER_KEY},tech)})
 same => n,Set(MASTER_CHANNEL(ZV2_XFER_Q850)=${HANGUPCAUSE(${ZV2_XFER_KEY},ast)})
 same => n(finxfer),Hangup()
 same => n(sindestino),Set(MASTER_CHANNEL(ZV2_STAGE)=XFER_NODEST)
 same => n,Hangup()

[zynervoxv2-marcador-cierre]
exten => s,1,Set(ZV2_SIP=)
 same => n,Set(ZV2_CAUSE_SRC=DIAL)
 same => n,Set(ZV2_Q850=${HANGUPCAUSE})
 same => n,Set(ZV2_SIPKEY=${HANGUPCAUSE_KEYS()})
 same => n,GotoIf($["${ZV2_SIPKEY}" = ""]?xfer)
 same => n,Set(ZV2_SIP=${HANGUPCAUSE(${ZV2_SIPKEY},tech)})
 same => n(xfer),GotoIf($["${ZV2_XFER_SIP}" = ""]?fin)
 same => n,Set(ZV2_SIP=${ZV2_XFER_SIP})
 same => n,Set(ZV2_Q850=${ZV2_XFER_Q850})
 same => n,Set(ZV2_CAUSE_SRC=XFER)
 same => n(fin),AGI(/srv/www/htdocs/zynervoxv2/bot_ivr/call_tracking_agi.php,finish,${ZV2_CALL_ID},${DIALSTATUS},${ZV2_AMD_STATUS},${ZV2_AMD_CAUSE},${ZV2_Q850},${ZV2_ANSWERED},${ZV2_SIP},${ZV2_STAGE},${ZV2_CAUSE_SRC})
 same => n,Return()
ASTERISK;

    public static function template(): string {
        return self::COMMON."\n\n".self::HEADER;
    }

    private static function commonContexts(): array {
        preg_match_all('/^\s*\[([^\]\n]+)\][^\n]*\n?(.*?)(?=^\s*\[|\z)/ms',self::COMMON,$matches,PREG_SET_ORDER);
        $contexts=[];
        foreach ($matches as $match) $contexts[$match[1]]=trim($match[2]);
        return $contexts;
    }

    public static function body(string $dialplan): string {
        $dialplan = str_replace(["\r\n", "\r"], "\n", $dialplan);
        $dialplan = preg_replace('/^\s*; Manual calls: list 1\. Future worker must set ZV2_LIST_ID explicitly\.\s*\n/', '', $dialplan);
        $common=self::commonContexts();
        $dialplan=preg_replace_callback('/^\s*\[(zynervoxv2-bot-ivr-sin-amd|zynervoxv2-bot-ivr|zynervoxv2-amd|zynervoxv2-call-finish|zynervoxv2-outbound|zynervoxv2-marcador|zynervoxv2-marcador-fin|zynervoxv2-marcador-salida|zynervoxv2-marcador-contestada|zynervoxv2-marcador-cierre)\][^\n]*\n?(.*?)(?=^\s*\[|\z)/ms', function ($match) use ($common) {
            if (trim($match[2])!==$common[$match[1]]) throw new \InvalidArgumentException('El contexto '.$match[1].' pertenece a la plantilla común; no lo redefinas.');
            return '';
        },$dialplan);
        return ltrim(preg_replace('/^\s*\[zynervoxv2\]\s*(?:;[^\n]*)?\n?/', '', $dialplan));
    }

    public static function prefixes(string $dialplan): array {
        $body = self::body($dialplan);
        // Only the initial zynervoxv2 routes are reusable origins, not helper contexts.
        $body = preg_split('/^\s*\[.*$/m', $body, 2)[0];
        preg_match_all('/^\s*exten\s*=>\s*_([0-9]{1,20})X\.\s*,\s*1\s*,/mi', $body, $matches);
        return array_values(array_unique($matches[1]));
    }

    /** Peer chan_sip y prefijo de marcado de la ruta de un origen, leídos de su Dial(SIP/<peer>/<prefijo>${ZV2_PHONE}). */
    public static function route(string $dialPrefix, string $dialplan): array {
        if (!preg_match('/^[0-9]{1,20}$/D',$dialPrefix)) throw new \InvalidArgumentException('Prefijo de origen inválido.');
        $body=self::body($dialplan);
        $block=preg_split('/^\s*\[.*$/m',$body,2)[0];
        $start=preg_match('/^\s*exten\s*=>\s*_'.$dialPrefix.'X\.\s*,\s*1\s*,/mi',$block,$m,PREG_OFFSET_CAPTURE);
        if (!$start) throw new \InvalidArgumentException('El origen '.$dialPrefix.' no tiene ruta.');
        $rest=substr($block,$m[0][1]+strlen($m[0][0]));
        $rest=preg_split('/^\s*exten\s*=>/mi',$rest,2)[0];
        if (!preg_match('/Dial\(SIP\/([A-Za-z0-9_.-]+)\/([0-9*#+]*)\$\{ZV2_PHONE\}/',$rest,$d)) throw new \InvalidArgumentException('La ruta '.$dialPrefix.' no marca por SIP/<peer>/<prefijo>${ZV2_PHONE}.');
        return ['peer'=>$d[1],'prefix'=>$d[2]];
    }

    public static function render(array $carriers): string {
        $routes = '';
        $contexts = [];
        foreach ($carriers as $carrier) {
            $body = self::body($carrier['dialplan_entry']);
            if (trim($body) === '') continue;
            $parts = preg_split('/(?=^\s*\[[^\]\n]+\])/m', $body, 2);
            $comment = '; ---- Carrier: '.$carrier['carrier_id'].' ----'."\n";
            $routes .= $comment.rtrim($parts[0])."\n\n";
            if (isset($parts[1])) {
                preg_match_all('/^\s*\[([^\]\n]+)\][^\n]*\n?(.*?)(?=^\s*\[|\z)/ms', $parts[1], $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $name=trim($match[1]);
                    if (strtolower($name)==='zynervoxv2') throw new \InvalidArgumentException('La cabecera zynervoxv2 es fija; no la repitas dentro del dialplan.');
                    $definition=trim($match[2]);
                    if (isset($contexts[$name]) && $contexts[$name]!==$definition) throw new \InvalidArgumentException('Contexto auxiliar repetido con contenido distinto: '.$name);
                    $contexts[$name]=$definition;
                }
            }
        }
        $helpers='';
        foreach ($contexts as $name=>$definition) $helpers.='['.$name."]\n".$definition."\n\n";
        return self::template().$routes.$helpers;
    }
}
