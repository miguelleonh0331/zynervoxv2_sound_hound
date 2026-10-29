<?php

namespace Includes;

// Checklist interno de roadmap ("funciones estilo VICIdial que faltan"),
// visible y editable UNICAMENTE por el usuario 6666 (pedido explicito del
// usuario: "solo admin como user 6666"). Estado persistido en un JSON en
// disco (pedido explicito: "en un json"), no en tabla de BD ni localStorage.
class DevChecklist {

    // 'runtime/' es la unica carpeta bajo el webroot que Apache (www-data)
    // puede escribir de verdad (confirmado con sudo -u www-data test -w);
    // 'data/' no existia y el webroot en si es root:root 755, no escribible.
    const STATE_FILE = __DIR__ . '/../runtime/dev_checklist/state.json';

    // Unico usuario autorizado a ver/usar esta pagina.
    const ALLOWED_USER = '6666';

    // Items fijos del gap analysis (ver E:\tabuladores\gaps-vicidial-zynervox-20260916-000000.html).
    const ITEMS = [
        ['key' => 'predictive_dialing', 'label' => 'Marcado predictivo real (ratio/nivel adaptativo, no solo concurrencia fija)', 'priority' => 'alta'],
        ['key' => 'list_management',    'label' => 'Gestion clasica de listas (CRUD, mezcla, reciclaje, scrub DNC/duplicados)', 'priority' => 'alta'],
        ['key' => 'dnc_compliance',     'label' => 'DNC / cumplimiento (lista negra administrable, ventana horaria de marcado)', 'priority' => 'alta'],
        ['key' => 'callbacks',          'label' => 'Callbacks programados (cola y recordatorio de CALLBK/CBHOLD)', 'priority' => 'media'],
        ['key' => 'inbound_acd',        'label' => 'Cola/IVR inbound con distribucion (ACD, ring groups)', 'priority' => 'media'],
        ['key' => 'qa_recordings',      'label' => 'QA de grabaciones (evaluacion, scoring, coaching)', 'priority' => 'media'],
        ['key' => 'dispositions',       'label' => 'Gestion de disposiciones por campana (CRUD de status codes)', 'priority' => 'media'],
        ['key' => 'sla_alerts',         'label' => 'Alertas SLA / abandono / umbral (push, no solo dashboard)', 'priority' => 'media'],
        ['key' => 'wallboard',          'label' => 'Wallboard / modo TV', 'priority' => 'baja'],
        ['key' => 'agent_scripting',    'label' => 'Scripting de agente / webforms dinamicos en llamada', 'priority' => 'baja'],
        ['key' => 'voicemail_drop',     'label' => 'Voicemail drop (mensaje automatico al detectar buzon)', 'priority' => 'baja'],
        ['key' => 'api_webhooks',       'label' => 'API/webhooks formales hacia CRM externo', 'priority' => 'baja'],
        ['key' => 'transfer_3way',      'label' => 'Transferencia / tres vias en llamada activa (agente humano)', 'priority' => 'baja'],
        ['key' => 'agent_scorecards',   'label' => 'Scorecards de agente / adherencia a horario', 'priority' => 'baja'],
    ];

    public static function isAuthorized(): bool {
        return (($_SESSION['user'] ?? '') === self::ALLOWED_USER);
    }

    public static function getState(): array {
        if (!file_exists(self::STATE_FILE)) {
            return [];
        }
        $raw = @file_get_contents(self::STATE_FILE);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    // Lanza \InvalidArgumentException si $key no es un item valido.
    public static function toggle(string $key, bool $checked): array {
        $allowedKeys = array_column(self::ITEMS, 'key');
        if (!in_array($key, $allowedKeys, true)) {
            throw new \InvalidArgumentException('Item de checklist desconocido: ' . $key);
        }

        $state = self::getState();
        $state[$key] = [
            'checked'    => $checked,
            'updated_at' => date('Y-m-d H:i:s'),
            'by'         => $_SESSION['user'] ?? '',
        ];
        self::saveState($state);
        return $state;
    }

    private static function saveState(array $state): void {
        $dir = dirname(self::STATE_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $fp = fopen(self::STATE_FILE, 'c+');
        if ($fp === false) {
            return;
        }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public static function progress(array $state): array {
        $total = count(self::ITEMS);
        $done = 0;
        foreach (self::ITEMS as $item) {
            if (!empty($state[$item['key']]['checked'])) {
                $done++;
            }
        }
        return [
            'done'  => $done,
            'total' => $total,
            'pct'   => $total > 0 ? (int) round($done / $total * 100) : 0,
        ];
    }
}
