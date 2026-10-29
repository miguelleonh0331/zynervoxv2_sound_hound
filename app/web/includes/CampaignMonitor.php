<?php

namespace Includes;

use Includes\Database;
use PDO;

require_once __DIR__ . '/Database.php';

// Monitor de Campañas: progreso de listas (lotes), KPIs calculados y
// agentes por campaña. Mismo patrón que AgentMonitor.php (PDO, try/catch,
// renderHtml() como única fuente de verdad para carga inicial + auto-refresh).
//
// IMPORTANTE (2026-09-16): "Contactabilidad %", "Hit Ratio %" y la
// categorización de resultados NO son campos nativos de VICIdial — son
// métricas y un mapeo definidos en este archivo (autorización explícita
// del usuario: "tienes total decision para la creacion de formulas o
// calculos"). Ajustar STATUS_CATEGORY_MAP si cambian las disposiciones
// reales usadas en campaña.
class CampaignMonitor {

    // Mapeo status de vicidial_list -> categoria del panel. Basado en los
    // codigos por defecto de VICIdial (vicidial_statuses) confirmados en
    // esta base. Cualquier status NO listado aqui (ej. disposiciones
    // custom de campana) cae en 'contactado' por defecto: se asume que si
    // alguien le puso una disposicion propia fue porque atendio la llamada.
    const STATUS_CATEGORY_MAP = [
        'NEW'    => 'nuevo',
        'A'      => 'casilla', 'AA' => 'casilla', 'AM' => 'casilla', 'AL' => 'casilla', 'AFAX' => 'casilla',
        'B'      => 'ocupado', 'AB' => 'ocupado',
        'DC'     => 'no_existe', 'ADC' => 'no_existe',
        'N'      => 'no_responde', 'NA' => 'no_responde',
        'CALLBK' => 'programado', 'CBHOLD' => 'programado',
        'DNC'    => 'lista_negra', 'DNCL' => 'lista_negra', 'DNCC' => 'lista_negra',
        'SALE'   => 'venta',
    ];

    const CATEGORY_LABELS = [
        'nuevo'        => 'Nuevos',
        'venta'        => 'Ventas',
        'contactado'   => 'Contactados',
        'casilla'      => 'Casilla de voz',
        'ocupado'      => 'Ocupado',
        'no_existe'    => 'No existe',
        'no_responde'  => 'No responde',
        'programado'   => 'Programados',
        'lista_negra'  => 'Lista negra',
    ];

    const CATEGORY_COLORS = [
        'nuevo'        => '#9CA0AC',
        'venta'        => '#10b981',
        'contactado'   => '#3b82f6',
        'casilla'      => '#8b5cf6',
        'ocupado'      => '#f59e0b',
        'no_existe'    => '#ef4444',
        'no_responde'  => '#6b7280',
        'programado'   => '#06b6d4',
        'lista_negra'  => '#111827',
    ];

    private static function categoryFor($status) {
        return self::STATUS_CATEGORY_MAP[$status] ?? 'contactado';
    }

    // Cache estatica de pause_code -> nombre legible (vicidial_pause_codes),
    // para no repetir la query por cada campaña en el mismo request.
    private static $pauseCodeNamesCache = null;
    private static function getPauseCodeNames(PDO $db) {
        if (self::$pauseCodeNamesCache !== null) return self::$pauseCodeNamesCache;
        $names = [];
        try {
            $stmt = $db->query("SELECT pause_code, pause_code_name FROM vicidial_pause_codes");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $names[$row['pause_code']] = $row['pause_code_name'];
            }
        } catch (\PDOException $e) {
            $names = [];
        }
        self::$pauseCodeNamesCache = $names;
        return $names;
    }

    // 2026-09-17: pedido explícito del usuario -- SOLO estadísticos
    // agregados por campaña (cantidad en READY, en pausa por motivo, etc.),
    // sin nombres de agentes ni tabla individual (versión anterior mostraba
    // usuario+tiempos por fila, se sacó). Menos columnas leídas = menos
    // carga también (no hace falta el JOIN con vicidial_users).
    private static function getAgentSnapshot(PDO $db, $campaignId) {
        $counts = ['READY' => 0, 'INCALL' => 0, 'PAUSED' => 0, 'QUEUE' => 0, 'MQUEUE' => 0, 'CLOSER' => 0];
        $pauseBreakdown = []; // pause_code (o '(sin código)') => cantidad
        try {
            $stmt = $db->prepare("SELECT status, pause_code FROM vicidial_live_agents WHERE campaign_id = :cid");
            $stmt->execute(['cid' => $campaignId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $rows = [];
        }

        $pauseNames = self::getPauseCodeNames($db);
        foreach ($rows as $r) {
            $status = $r['status'] ?: 'PAUSED';
            if (isset($counts[$status])) { $counts[$status]++; }
            if ($status === 'PAUSED') {
                $code = trim((string)($r['pause_code'] ?? ''));
                $label = $code !== '' ? ($pauseNames[$code] ?? $code) : '(sin código)';
                $pauseBreakdown[$label] = ($pauseBreakdown[$label] ?? 0) + 1;
            }
        }
        ksort($pauseBreakdown);

        // Tiempos agregados de HOY (suma de TODOS los agentes de la
        // campaña, sin desglose por persona).
        $talkTotal = 0;
        $pauseTotal = 0;
        try {
            $stmtT = $db->prepare(
                "SELECT SUM(talk_sec) AS talk, SUM(pause_sec) AS pause
                 FROM vicidial_agent_log WHERE campaign_id = :cid AND event_time >= CURDATE()"
            );
            $stmtT->execute(['cid' => $campaignId]);
            $row = $stmtT->fetch(PDO::FETCH_ASSOC);
            $talkTotal = (int)($row['talk'] ?? 0);
            $pauseTotal = (int)($row['pause'] ?? 0);
        } catch (\PDOException $e) {
            // deja los totales en 0
        }

        return [
            'counts' => $counts,
            'pause_breakdown' => $pauseBreakdown,
            'talk_sec_total' => $talkTotal,
            'pause_sec_total' => $pauseTotal,
        ];
    }

    // Stats NATIVAS de VICIdial (vicidial_campaign_stats), agregado por
    // el propio motor de campañas -- distinto a la categorización de
    // vicidial_list de arriba (esa es "estado de los leads ahora",
    // esta es "llamadas del día" pre-agregadas). Formulas documentadas en
    // agc/realtime_report.md / AST_timeonVDADall.md (motor nativo del
    // Real-Time Main Report de VICIdial), NO inventadas:
    //   Drop % = drops_today / answers_today * 100
    //   Productividad = answers_today / agent_non_pause_sec * 60
    // ADVERTENCIA (2026-09-17, verificado en este servidor): NINGÚN cron
    // de VICIdial actualiza esta tabla en kamatera (no hay
    // AST_manager_15.pl ni equivalente corriendo, solo
    // AST_manager_listen.pl/AST_manager_send.pl/kill_hung_congested.pl).
    // La fila puede existir pero quedar en 0 o desactualizada
    // indefinidamente hasta que se instale/active ese cron. Se muestra
    // igual (pedido explícito del usuario) con la fecha de
    // `update_time` visible para que se note si está viva o no.
    private static function getNativeStats(PDO $db, $campaignId) {
        try {
            $stmt = $db->prepare(
                "SELECT update_time, dialable_leads, calls_today, answers_today, drops_today,
                        agent_non_pause_sec, hold_sec_stat_one, hold_sec_stat_two
                 FROM vicidial_campaign_stats WHERE campaign_id = :cid LIMIT 1"
            );
            $stmt->execute(['cid' => $campaignId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $row = false;
        }

        if (!$row) {
            return ['available' => false];
        }

        $answers = (int)$row['answers_today'];
        $drops = (int)$row['drops_today'];
        $nonPauseSec = (int)$row['agent_non_pause_sec'];

        // MathZDC de VICIdial: division protegida contra cero.
        $dropPct = $answers > 0 ? round(($drops / $answers) * 100, 1) : 0.0;
        $productivity = $nonPauseSec > 0 ? round(($answers / $nonPauseSec) * 60, 2) : 0.0;

        return [
            'available' => true,
            'update_time' => $row['update_time'],
            'dialable_leads' => (int)$row['dialable_leads'],
            'calls_today' => (int)$row['calls_today'],
            'answers_today' => $answers,
            'drops_today' => $drops,
            'drop_pct' => $dropPct,
            'productivity' => $productivity,
        ];
    }

    // Cuenta los leads de UN list_id agrupados por categoria + pendientes
    // en hopper. Reusado tanto para listas registradas (con campana) como
    // para listas huerfanas (ver getOrphanListSnapshot).
    private static function summarizeList(PDO $db, $listId, $listName) {
        $byStatus = [];
        try {
            $stmtS = $db->prepare("SELECT status, COUNT(*) AS ct FROM vicidial_list WHERE list_id = :lid GROUP BY status");
            $stmtS->execute(['lid' => $listId]);
            $byStatus = $stmtS->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $byStatus = [];
        }

        $catCounts = array_fill_keys(array_keys(self::CATEGORY_LABELS), 0);
        $leadCount = 0;
        foreach ($byStatus as $row) {
            $cat = self::categoryFor($row['status']);
            $ct = (int)$row['ct'];
            $catCounts[$cat] += $ct;
            $leadCount += $ct;
        }

        $hopperPending = 0;
        try {
            $stmtH = $db->prepare("SELECT COUNT(*) FROM vicidial_hopper WHERE list_id = :lid");
            $stmtH->execute(['lid' => $listId]);
            $hopperPending = (int)$stmtH->fetchColumn();
        } catch (\PDOException $e) {
            $hopperPending = 0;
        }

        return [
            'list_id' => $listId,
            'list_name' => $listName,
            'total' => $leadCount,
            'categories' => $catCounts,
            'hopper_pending' => $hopperPending,
        ];
    }

    // Listas huerfanas: list_id que tienen leads en vicidial_list pero
    // NINGUN registro en vicidial_lists (por lo tanto no se puede saber a
    // que campana pertenecen -- son datos sueltos, ej. una carga directa
    // sin pasar por "crear lista"). Se muestran aparte, no dentro de
    // ninguna tarjeta de campana especifica, para no inventar un vinculo
    // que la base no tiene. Confirmado en esta base: list_id=101 (7 leads)
    // cae en este caso.
    public static function getOrphanLists() {
        $db = Database::getInstance();
        try {
            $stmt = $db->query(
                "SELECT vl.list_id, COUNT(*) AS ct
                 FROM vicidial_list vl
                 LEFT JOIN vicidial_lists l ON vl.list_id = l.list_id
                 WHERE l.list_id IS NULL
                 GROUP BY vl.list_id"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::summarizeList($db, $r['list_id'], "Lista {$r['list_id']} (sin campaña asociada)");
        }
        return $out;
    }

    // Progreso de las listas de una campaña: totales por lista + categorias
    // agregadas de TODAS sus listas juntas (para los KPIs). No filtra por
    // active='Y': en datos reales puede haber listas inactivas con leads
    // todavia sin resultado final, y en este ambiente de prueba las unicas
    // listas registradas (998/999) estan inactivas -- si se filtrara por
    // activa, el panel nunca mostraria nada util.
    private static function getListSnapshot(PDO $db, $campaignId) {
        $lists = [];
        try {
            $stmt = $db->prepare(
                "SELECT list_id, list_name FROM vicidial_lists WHERE campaign_id = :cid ORDER BY list_id"
            );
            $stmt->execute(['cid' => $campaignId]);
            $listRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $listRows = [];
        }

        $totals = array_fill_keys(array_keys(self::CATEGORY_LABELS), 0);
        $totalLeads = 0;

        foreach ($listRows as $lr) {
            $summary = self::summarizeList($db, $lr['list_id'], $lr['list_name']);
            foreach ($summary['categories'] as $cat => $ct) {
                $totals[$cat] += $ct;
            }
            $totalLeads += $summary['total'];
            $lists[] = $summary;
        }

        // KPIs: marcados = todo lo que no es 'nuevo'; contactados = marcados
        // menos las categorias donde NO hubo un humano real al telefono.
        $marcados = $totalLeads - $totals['nuevo'];
        $noHumano = $totals['casilla'] + $totals['ocupado'] + $totals['no_existe'] + $totals['no_responde'] + $totals['lista_negra'];
        $contactados = max(0, $marcados - $noHumano);
        $ventas = $totals['venta'];

        $contactabilidad = $marcados > 0 ? round(($contactados / $marcados) * 100, 1) : 0.0;
        $hitRatio = $contactados > 0 ? round(($ventas / $contactados) * 100, 1) : 0.0;

        return [
            'lists' => $lists,
            'totals' => $totals,
            'total_leads' => $totalLeads,
            'marcados' => $marcados,
            'contactados' => $contactados,
            'ventas' => $ventas,
            'contactabilidad' => $contactabilidad,
            'hit_ratio' => $hitRatio,
        ];
    }

    // Snapshot completo de la(s) campaña(s) pedidas. $campaignIds acepta:
    // null -> TODAS las activas (uso interno/administrativo, ej. futuros
    //         scripts); '' o [] -> NINGUNA (evita golpear la BD con
    //         campañas que a nadie le interesan, pedido explícito del
    //         usuario); string -> una sola; array -> varias puntuales
    //         (esto es lo que usa la pantalla, manejada por el "+" y la
    //         lista guardada en localStorage del navegador).
    public static function getCampaignSnapshots($campaignIds = null) {
        $db = Database::getInstance();
        $sql = "SELECT campaign_id, campaign_name FROM vicidial_campaigns WHERE active = 'Y'";
        $params = [];

        if ($campaignIds === '' || $campaignIds === []) {
            return [];
        }
        if (is_array($campaignIds)) {
            $campaignIds = array_values(array_unique(array_filter($campaignIds)));
            if (empty($campaignIds)) return [];
            $placeholders = [];
            foreach ($campaignIds as $i => $cid) {
                $key = "cid{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = $cid;
            }
            $sql .= " AND campaign_id IN (" . implode(',', $placeholders) . ")";
        } elseif ($campaignIds !== null) {
            $sql .= " AND campaign_id = :cid";
            $params['cid'] = $campaignIds;
        }
        $sql .= " ORDER BY campaign_id ASC";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }

        $snapshots = [];
        foreach ($campaigns as $c) {
            $agentData = self::getAgentSnapshot($db, $c['campaign_id']);
            $listData = self::getListSnapshot($db, $c['campaign_id']);
            $native = self::getNativeStats($db, $c['campaign_id']);
            $snapshots[] = [
                'campaign_id' => $c['campaign_id'],
                'campaign_name' => $c['campaign_name'],
                'agent_counts' => $agentData['counts'],
                'pause_breakdown' => $agentData['pause_breakdown'],
                'talk_sec_total' => $agentData['talk_sec_total'],
                'pause_sec_total' => $agentData['pause_sec_total'],
                'native' => $native,
            ] + $listData;
        }
        return $snapshots;
    }

    private static function fmtSecs($sec) {
        $sec = (int)$sec;
        return sprintf('%02d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
    }

    // Tarjeta aparte (fuera de cualquier campaña) para listas huérfanas
    // (ver getOrphanLists) -- se llama independiente de renderHtml() tanto
    // en la carga inicial como en el auto-refresh.
    public static function renderOrphanHtml($orphanLists) {
        if (empty($orphanLists)) return '';
        $html = "<div class='card' style='margin-bottom:1rem; border-color:#f59e0b;'>";
        $html .= "<h2 style='margin-bottom:.4rem; color:#f59e0b;'>⚠ Leads sin campaña asociada</h2>";
        $html .= "<p style='color:var(--text-muted); font-size:.75rem; margin-bottom:.5rem;'>Estas listas tienen leads en <code>vicidial_list</code> pero ningún registro en <code>vicidial_lists</code> — no hay forma de saber a qué campaña pertenecen desde la base. Revisar manualmente.</p>";
        foreach ($orphanLists as $list) {
            $html .= "<div style='margin-bottom:.6rem;'>";
            $html .= "<div style='font-size:.75rem; font-weight:600; color:var(--text); margin-bottom:.3rem;'>"
                . htmlspecialchars($list['list_name']) . " <span style='color:var(--text-muted); font-weight:400;'>&middot; {$list['total']} leads &middot; {$list['hopper_pending']} en cola de marcado</span></div>";
            $html .= "<div style='display:flex; gap:2px; border:1px solid var(--border); border-radius:2px; overflow:hidden;'>";
            foreach ($list['categories'] as $cat => $count) {
                $color = self::CATEGORY_COLORS[$cat] ?? '#6B7280';
                $numColor = $count > 0 ? $color : 'var(--text-muted)';
                $bg = $count > 0 ? "{$color}14" : 'transparent';
                $html .= "<div style='flex:1 1 0; min-width:0; text-align:center; padding:.3rem .15rem; background:{$bg};'>";
                $html .= "<div style='font-size:.85rem; font-weight:700; color:{$numColor}; line-height:1.1;'>{$count}</div>";
                $html .= "<div style='font-size:.55rem; color:var(--text-muted); text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;'>" . self::CATEGORY_LABELS[$cat] . "</div>";
                $html .= "</div>";
            }
            $html .= "</div></div>";
        }
        $html .= "</div>";
        return $html;
    }

    // Grilla de cajas "numero grande + label chica" -- MISMO estilo que
    // usa la categorizacion de leads (progreso de lista). Reusada tambien
    // para conteos de agentes por estado y pausados por motivo, asi todo
    // el panel usa una sola forma visual de mostrar estadisticos.
    // $counts: ['clave' => cantidad]; $colors/$labels: ['clave' => valor].
    private static function renderStatGrid($counts, $colors, $labels) {
        if (empty($counts)) return '';
        $html = "<div style='display:flex; gap:2px; margin-bottom:.6rem; border:1px solid var(--border); border-radius:2px; overflow:hidden;'>";
        foreach ($counts as $key => $count) {
            $color = $colors[$key] ?? '#6B7280';
            $numColor = $count > 0 ? $color : 'var(--text-muted)';
            $bg = $count > 0 ? "{$color}14" : 'transparent';
            $html .= "<div style='flex:1 1 0; min-width:0; text-align:center; padding:.3rem .15rem; background:{$bg};'>";
            $html .= "<div style='font-size:.85rem; font-weight:700; color:{$numColor}; line-height:1.1;'>{$count}</div>";
            $html .= "<div style='font-size:.55rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;'>" . htmlspecialchars($labels[$key] ?? $key) . "</div>";
            $html .= "</div>";
        }
        $html .= "</div>";
        return $html;
    }

    // Renderiza el HTML de las tarjetas de campaña; usado tanto en la
    // carga inicial de campaign_monitor.php como en el fragmento que
    // devuelve api_campaign_monitor.php para el auto-refresh.
    public static function renderHtml($snapshots) {
        if (empty($snapshots)) {
            return "<div class='card'><p style='color:var(--text-muted); text-align:center; padding:2rem; font-size:0.85rem;'>No hay campañas activas.</p></div>";
        }

        $html = '';
        foreach ($snapshots as $s) {
            $totalAgents = array_sum($s['agent_counts']);
            $html .= "<div class='card'>";
            $html .= "<h2 style='margin-bottom:.4rem;'>" . htmlspecialchars($s['campaign_name'])
                . " <span style='color:var(--text-muted); font-weight:400; font-size:0.8rem;'>[" . htmlspecialchars($s['campaign_id'])
                . "] &middot; {$totalAgents} agentes</span></h2>";

            // Franja de agentes por estado -- MISMO estilo de grilla que las
            // categorias de leads de abajo (caja con numero grande + label
            // chica), a pedido del usuario, en vez de las pastillas chicas
            // de antes. Siguen siendo puros agregados, sin nombres.
            $agentLabels = ['READY' => 'Disponible', 'INCALL' => 'En llamada', 'PAUSED' => 'Pausado', 'QUEUE' => 'En cola', 'MQUEUE' => 'Cola manual', 'CLOSER' => 'Cierre'];
            $agentColors = ['READY' => '#10b981', 'INCALL' => '#3b82f6', 'PAUSED' => '#f59e0b', 'QUEUE' => '#8b5cf6', 'MQUEUE' => '#8b5cf6', 'CLOSER' => '#06b6d4'];
            $html .= self::renderStatGrid($s['agent_counts'], $agentColors, $agentLabels);
            if ($totalAgents === 0) {
                $html .= "<p style='color:var(--text-muted); font-size:.7rem; margin:0 0 .6rem;'>Sin agentes conectados.</p>";
            }

            // Desglose de PAUSADOS por motivo (pause_code real de
            // vicidial_pause_codes, ej. "BATHROOM", "VENTA") -- mismo estilo
            // de grilla. Pedido explicito del usuario: solo estadisticos,
            // sin nombres de agentes ni tabla individual.
            if (!empty($s['pause_breakdown'])) {
                $html .= "<div style='font-size:.6rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.2px; margin:.3rem 0 .2rem;'>Pausados por motivo</div>";
                $pauseColors = array_fill_keys(array_keys($s['pause_breakdown']), '#f59e0b');
                $pauseLabels = array_combine(array_keys($s['pause_breakdown']), array_keys($s['pause_breakdown']));
                $html .= self::renderStatGrid($s['pause_breakdown'], $pauseColors, $pauseLabels);
            }
            if ($s['talk_sec_total'] > 0 || $s['pause_sec_total'] > 0) {
                $html .= "<div style='display:flex; gap:1.2rem; margin-bottom:.6rem; font-size:.7rem; color:var(--text-muted);'>";
                $html .= "<span>Tiempo hablado hoy (total campaña): <strong style='color:var(--text);'>" . self::fmtSecs($s['talk_sec_total']) . "</strong></span>";
                $html .= "<span>Tiempo en pausa hoy (total campaña): <strong style='color:var(--text);'>" . self::fmtSecs($s['pause_sec_total']) . "</strong></span>";
                $html .= "</div>";
            }

            // Stats NATIVAS de VICIdial (vicidial_campaign_stats) -- distintas
            // de los KPIs propios de abajo. En este servidor no hay cron que
            // mantenga esta tabla al dia (ver comentario en getNativeStats()),
            // por eso se muestra la fecha de actualizacion siempre visible:
            // si dice "nunca" o queda vieja, los numeros no son confiables.
            $native = $s['native'];
            $html .= "<div style='margin-bottom:.5rem; padding:.5rem .7rem; background:rgba(255,255,255,.02); border:1px dashed var(--border); border-radius:2px;'>";
            $html .= "<div style='font-size:.6rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.3px; margin-bottom:.35rem;'>Stats nativas VICIdial (vicidial_campaign_stats) &mdash; "
                . ($native['available'] && !empty($native['update_time']) ? "actualizado: " . htmlspecialchars($native['update_time']) : "<span style='color:#f59e0b;'>sin cron activo que la actualice en este servidor</span>")
                . "</div>";
            if ($native['available']) {
                $html .= "<div style='display:flex; gap:1.2rem; flex-wrap:wrap;'>";
                $html .= "<div><div style='font-size:.95rem; font-weight:700; color:var(--text);'>{$native['calls_today']}</div><div style='font-size:.6rem; color:var(--text-muted); text-transform:uppercase;'>Llamadas hoy</div></div>";
                $html .= "<div><div style='font-size:.95rem; font-weight:700; color:var(--text);'>{$native['answers_today']}</div><div style='font-size:.6rem; color:var(--text-muted); text-transform:uppercase;'>Contestadas</div></div>";
                $html .= "<div><div style='font-size:.95rem; font-weight:700; color:var(--text);'>{$native['drops_today']}</div><div style='font-size:.6rem; color:var(--text-muted); text-transform:uppercase;'>Colgadas (drop)</div></div>";
                $html .= "<div><div style='font-size:.95rem; font-weight:700; color:#ef4444;'>{$native['drop_pct']}%</div><div style='font-size:.6rem; color:var(--text-muted); text-transform:uppercase;'>Drop %</div></div>";
                $html .= "<div><div style='font-size:.95rem; font-weight:700; color:var(--text);'>{$native['dialable_leads']}</div><div style='font-size:.6rem; color:var(--text-muted); text-transform:uppercase;'>Leads marcables</div></div>";
                $html .= "<div><div style='font-size:.95rem; font-weight:700; color:var(--text);'>{$native['productivity']}</div><div style='font-size:.6rem; color:var(--text-muted); text-transform:uppercase;'>Productividad</div></div>";
                $html .= "</div>";
            } else {
                $html .= "<div style='font-size:.75rem; color:var(--text-muted);'>Sin fila en vicidial_campaign_stats para esta campaña.</div>";
            }
            $html .= "</div>";

            // KPIs.
            $html .= "<div style='display:flex; gap:1.2rem; flex-wrap:wrap; margin-bottom:.7rem; padding:.5rem .7rem; background:var(--glass); border-radius:2px;'>";
            $html .= "<div><div style='font-size:1.05rem; font-weight:700; color:var(--text);'>{$s['total_leads']}</div><div style='font-size:.62rem; color:var(--text-muted); text-transform:uppercase;'>Leads en lista</div></div>";
            $html .= "<div><div style='font-size:1.05rem; font-weight:700; color:var(--text);'>{$s['marcados']}</div><div style='font-size:.62rem; color:var(--text-muted); text-transform:uppercase;'>Marcados</div></div>";
            $html .= "<div><div style='font-size:1.05rem; font-weight:700; color:#3b82f6;'>{$s['contactabilidad']}%</div><div style='font-size:.62rem; color:var(--text-muted); text-transform:uppercase;'>Contactabilidad</div></div>";
            $html .= "<div><div style='font-size:1.05rem; font-weight:700; color:#10b981;'>{$s['hit_ratio']}%</div><div style='font-size:.62rem; color:var(--text-muted); text-transform:uppercase;'>Hit ratio</div></div>";
            $html .= "<div><div style='font-size:1.05rem; font-weight:700; color:var(--text);'>{$s['ventas']}</div><div style='font-size:.62rem; color:var(--text-muted); text-transform:uppercase;'>Ventas</div></div>";
            $html .= "</div>";

            // Progreso por lista (lote).
            if (empty($s['lists'])) {
                $html .= "<p style='color:var(--text-muted); font-size:.75rem; margin:.4rem 0;'>Sin listas activas para esta campaña.</p>";
            } else {
                foreach ($s['lists'] as $list) {
                    $html .= "<div style='margin-bottom:.6rem;'>";
                    $html .= "<div style='font-size:.75rem; font-weight:600; color:var(--text); margin-bottom:.3rem;'>"
                        . htmlspecialchars($list['list_name']) . " <span style='color:var(--text-muted); font-weight:400;'>[" . htmlspecialchars($list['list_id']) . "] &middot; {$list['total']} leads &middot; {$list['hopper_pending']} en cola de marcado</span></div>";
                    $html .= "<div style='display:flex; gap:2px; border:1px solid var(--border); border-radius:2px; overflow:hidden;'>";
                    foreach ($list['categories'] as $cat => $count) {
                        $color = self::CATEGORY_COLORS[$cat] ?? '#6B7280';
                        $numColor = $count > 0 ? $color : 'var(--text-muted)';
                        $bg = $count > 0 ? "{$color}14" : 'transparent';
                        $html .= "<div style='flex:1 1 0; min-width:0; text-align:center; padding:.3rem .15rem; background:{$bg};'>";
                        $html .= "<div style='font-size:.85rem; font-weight:700; color:{$numColor}; line-height:1.1;'>{$count}</div>";
                        $html .= "<div style='font-size:.55rem; color:var(--text-muted); text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;'>" . self::CATEGORY_LABELS[$cat] . "</div>";
                        $html .= "</div>";
                    }
                    $html .= "</div></div>";
                }
            }

            $html .= "</div>";
        }
        return $html;
    }
}
