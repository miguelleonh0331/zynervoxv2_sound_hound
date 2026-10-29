<?php

namespace Includes;

use Includes\Database;
use PDO;

require_once __DIR__ . '/Database.php';

class AgentMonitor {

    // Devuelve los agentes en vivo agrupados por campaña.
    public static function getLiveByCampaign($campaignFilter = null) {
        $db = Database::getInstance();
        $sql = "SELECT la.user, u.full_name, la.campaign_id,
                       c.campaign_name, la.status, la.extension,
                       la.calls_today, la.pause_code, la.last_state_change,
                       la.last_call_time
                FROM vicidial_live_agents la
                LEFT JOIN vicidial_campaigns c ON la.campaign_id = c.campaign_id
                LEFT JOIN vicidial_users u ON la.user = u.user
                WHERE 1=1";
        $params = [];
        if ($campaignFilter) {
            $sql .= " AND la.campaign_id = :cid";
            $params['cid'] = $campaignFilter;
        }
        $sql .= " ORDER BY la.campaign_id ASC, la.user ASC";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }

        $grouped = [];
        foreach ($rows as $r) {
            $cid = $r['campaign_id'] ?: 'SIN_CAMPANA';
            if (!isset($grouped[$cid])) {
                $grouped[$cid] = [
                    'campaign_id' => $cid,
                    'campaign_name' => $r['campaign_name'] ?: $cid,
                    'agents' => [],
                    'counts' => ['READY' => 0, 'INCALL' => 0, 'PAUSED' => 0, 'QUEUE' => 0, 'MQUEUE' => 0, 'CLOSER' => 0],
                ];
            }
            $grouped[$cid]['agents'][] = $r;
            $status = $r['status'] ?: 'PAUSED';
            if (isset($grouped[$cid]['counts'][$status])) {
                $grouped[$cid]['counts'][$status]++;
            }
        }

        // Orden dentro de cada campana: alfabetico por nombre (nombre
        // completo si existe, si no el usuario) - pedido explicito del
        // usuario, para ubicar rapido a alguien por nombre en listas largas.
        foreach ($grouped as &$g) {
            usort($g['agents'], function ($a, $b) {
                $na = $a['full_name'] ?: $a['user'];
                $nb = $b['full_name'] ?: $b['user'];
                return strcasecmp($na, $nb);
            });
        }
        unset($g);

        return array_values($grouped);
    }

    // Version plana para el endpoint JSON del auto-refresh.
    public static function getLiveFlat($campaignFilter = null) {
        $groups = self::getLiveByCampaign($campaignFilter);
        $out = [];
        foreach ($groups as $g) {
            foreach ($g['agents'] as $a) {
                $out[] = [
                    'user' => $a['user'],
                    'full_name' => $a['full_name'],
                    'campaign_id' => $g['campaign_id'],
                    'campaign_name' => $g['campaign_name'],
                    'status' => $a['status'],
                    'extension' => $a['extension'],
                    'calls_today' => (int)$a['calls_today'],
                    'pause_code' => $a['pause_code'],
                    'last_state_change' => $a['last_state_change'],
                ];
            }
        }
        return $out;
    }

    private static $statusColors = [
        'READY'  => '#10b981',
        'INCALL' => '#3b82f6',
        'PAUSED' => '#f59e0b',
        'QUEUE'  => '#8b5cf6',
        'MQUEUE' => '#8b5cf6',
        'CLOSER' => '#06b6d4',
    ];

    private static $statusLabels = [
        'READY'  => 'Disponible',
        'INCALL' => 'En llamada',
        'PAUSED' => 'Pausado',
        'QUEUE'  => 'En cola',
        'MQUEUE' => 'Cola manual',
        'CLOSER' => 'Cierre',
    ];

    // Renderiza el HTML de los grupos de campaña; usado tanto en la carga
    // inicial de monitor.php como en el fragmento que devuelve api_monitor.php
    // para el auto-refresh (mismo markup, una sola fuente de verdad).
    public static function renderHtml($groups) {
        if (empty($groups)) {
            return "<div class='card'><p style='color:var(--text-muted); text-align:center; padding:2rem; font-size:0.85rem;'>No hay agentes conectados en este momento.</p></div>";
        }

        $html = '';
        foreach ($groups as $g) {
            $totalAgentsCamp = array_sum($g['counts']);
            $html .= "<div class='card'>";
            $html .= "<h2 style='margin-bottom:.5rem;'>" . htmlspecialchars($g['campaign_name']) . " <span style='color:var(--text-muted); font-weight:400; font-size:0.8rem;'>[" . htmlspecialchars($g['campaign_id']) . "] &middot; {$totalAgentsCamp} agentes</span></h2>";

            // Estadistico de cantidad de agentes por estado: pedido explicito
            // del usuario, mas visible que el badge chico de antes. Se
            // muestran los 6 estados siempre (aunque esten en 0) para poder
            // escanear rapido cuantos hay en cada uno.
            $html .= "<div style='display:flex; gap:2px; margin-bottom:.7rem; border:1px solid var(--border); border-radius:2px; overflow:hidden;'>";
            foreach ($g['counts'] as $st => $count) {
                $color = self::$statusColors[$st] ?? '#6B7280';
                $numColor = $count > 0 ? $color : 'var(--text-muted)';
                $bg = $count > 0 ? "{$color}14" : 'transparent';
                $html .= "<div style='flex:1 1 0; min-width:0; text-align:center; padding:.35rem .2rem; background:{$bg};'>";
                $html .= "<div style='font-size:1rem; font-weight:700; color:{$numColor}; line-height:1.1;'>{$count}</div>";
                $html .= "<div style='font-size:.6rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;'>" . (self::$statusLabels[$st] ?? $st) . "</div>";
                $html .= "</div>";
            }
            $html .= "</div>";

            // Lista vertical de una sola columna, estilo lista de contactos
            // (avatar + nombre + linea de estado), en vez de tabla ancha de
            // 5 columnas - pedido explicito del usuario para que cada
            // tarjeta de campana quede angosta y quepan muchas lado a lado.
            $html .= "<div class='agent-list'>";
            foreach ($g['agents'] as $a) {
                $st = $a['status'] ?: 'PAUSED';
                $color = self::$statusColors[$st] ?? '#6B7280';
                $label = self::$statusLabels[$st] ?? $st;
                if ($st === 'PAUSED' && !empty($a['pause_code'])) {
                    $label .= ' (' . htmlspecialchars($a['pause_code']) . ')';
                }
                $since = '';
                if (!empty($a['last_state_change'])) {
                    $diff = time() - strtotime($a['last_state_change']);
                    if ($diff < 0) $diff = 0;
                    $since = sprintf('%02d:%02d', floor($diff / 60), $diff % 60);
                }
                $name = $a['full_name'] ?: $a['user'];
                $initial = strtoupper(substr($name, 0, 1)) ?: '?';

                $html .= "<div class='agent-row' style='display:flex; align-items:center; gap:.6rem; padding:.4rem 0; border-bottom:1px solid var(--border);'>";
                $html .= "<div style='position:relative; flex:0 0 auto; width:32px; height:32px; border-radius:50%; background:var(--dark); color:#fff; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:700;'>"
                    . htmlspecialchars($initial)
                    . "<span style='position:absolute; bottom:-1px; right:-1px; width:9px; height:9px; border-radius:50%; background:{$color}; border:2px solid var(--bg-card);'></span>"
                    . "</div>";
                $html .= "<div style='min-width:0; flex:1 1 auto;'>";
                $html .= "<div style='font-size:.8125rem; font-weight:600; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;'>"
                    . htmlspecialchars($name)
                    . " <span style='font-weight:400; color:var(--text-muted); font-size:.7rem;'>" . htmlspecialchars($a['user']) . "</span></div>";
                $html .= "<div style='font-size:.7rem; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;'>"
                    . "<span style='color:{$color}; font-weight:700;'>&#9679;</span> {$label}"
                    . " &middot; anexo " . htmlspecialchars($a['extension'])
                    . " &middot; {$since}"
                    . " &middot; " . (int)$a['calls_today'] . " hoy"
                    . "</div>";
                $html .= "</div></div>";
            }
            $html .= "</div></div>";
        }
        return $html;
    }
}
