<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Campaigns.php';
require_once __DIR__ . '/../../includes/Lists.php';
require_once __DIR__ . '/../../includes/Leads.php';
require_once __DIR__ . '/../../includes/Audit.php';

use Includes\Auth;
use Includes\Campaigns;
use Includes\Lists;
use Includes\Leads;
use Includes\Audit;

Auth::checkAccess(9);

$msg = '';
$campaigns = Campaigns::getAll();

// Manejar Acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Carga de Leads
    if (isset($_POST['process_leads'])) {
        $list_id = $_POST['list_id'];
        $list_name = $_POST['list_name'];
        $campaign_id = $_POST['campaign_id'];
        $leads_json = $_POST['leads_json'];
        $mapping_json = $_POST['mapping_json'];

        try {
            $leads = json_decode($leads_json, true);
            $mapping = json_decode($mapping_json, true);
            $listExists = Lists::getById($list_id);
            if (!$listExists) {
                Lists::create(['list_id' => $list_id, 'list_name' => $list_name, 'campaign_id' => $campaign_id, 'active' => 'Y']);
            }
            $inserted = Leads::bulkInsertMapped($list_id, $leads, $mapping);
            $msg = "<div class='alert success'>✨ ¡Proceso completado! Se han insertado <strong>$inserted</strong> leads en la lista $list_id.</div>";
        } catch (Exception $e) { $msg = "<div class='alert error'>❌ Error: " . $e->getMessage() . "</div>"; }
    }

    // 2. Reasignar Campaña
    if (isset($_POST['reassign_campaign'])) {
        if (Lists::updateCampaign($_POST['list_id'], $_POST['new_campaign_id'])) {
            $msg = "<div class='alert success'>✅ Lista <strong>{$_POST['list_id']}</strong> reasignada a <strong>{$_POST['new_campaign_id']}</strong>.</div>";
        }
    }

    // 3. Eliminar Lista (Limpia leads automáticamente)
    if (isset($_POST['delete_list'])) {
        if (Lists::delete($_POST['list_id'], true)) {
            $msg = "<div class='alert success'>🗑️ Lista <strong>{$_POST['list_id']}</strong> y sus leads eliminados correctamente.</div>";
        }
    }

    // 4. Activar/Desactivar Lista
    if (isset($_POST['toggle_status'])) {
        if (Lists::toggleStatus($_POST['list_id'])) {
            $msg = "<div class='alert success'>🔄 Estado de la lista <strong>{$_POST['list_id']}</strong> actualizado.</div>";
        }
    }
}

$searchResults = [];
if (isset($_GET['search_query']) && !empty($_GET['search_query'])) {
    $searchResults = Leads::search($_GET['search_query']);
}

$filterCampaign = $_GET['filter_campaign'] ?? '';
$allLists = Lists::getAll();
if ($filterCampaign) {
    $allLists = array_filter($allLists, function($l) use ($filterCampaign) {
        return $l['campaign_id'] === $filterCampaign;
    });
}
$systemFields = [
    'phone_number' => 'Teléfono (Principal)', 'first_name' => 'Nombre', 'last_name' => 'Apellido',
    'address1' => 'Dirección 1', 'address2' => 'Dirección 2', 'address3' => 'Dirección 3',
    'city' => 'Ciudad', 'state' => 'Estado/Region', 'postal_code' => 'Código Postal',
    'email' => 'Correo Electrónico', 'alt_phone' => 'Teléfono Alternativo',
    'vendor_lead_code' => 'ID Externo (Vendor ID)', 'source_id' => 'Source ID',
    'comments' => 'Comentarios', 'owner' => 'Propietario (Owner)', 'gender' => 'Género',
    'date_of_birth' => 'Fecha Nacimiento', 'phone_code' => 'Código País (Phone Code)',
    'status' => 'Estado del Lead (Status)', 'user' => 'Usuario Asignado',
    'title' => 'Tratamiento (Title)', 'rank' => 'Rango (Rank)',
    'entry_list_id' => 'Entry List ID', 'gmt_offset_now' => 'GMT Offset'
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Administrar Bases - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .tabs { display: flex; gap: 0.75rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.4rem; }
        .tab-btn { background: none; border: none; color: var(--text-muted); padding: 0.4rem 0.8rem; cursor: pointer; font-weight: 600; font-size: 0.8125rem; border-radius: 2px; }
        .tab-btn.active { color: var(--primary); background: var(--glass); }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .list-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .alert { padding: 0.5rem 0.75rem; border-radius: 2px; margin-bottom: 0.75rem; font-size: 0.8rem; }
        .success { background: #ECFDF5; border: 1px solid #10b981; color: #047857; }
        .error { background: #FEF2F2; border: 1px solid #ef4444; color: #b91c1c; }

        .upload-area { border: 2px dashed var(--border); border-radius: 2px; padding: 1.25rem; text-align: center; cursor: pointer; }
        .upload-area:hover { border-color: var(--primary); }
        .mapping-table select { width: 100%; padding: 4px; background: #fff; border: 1px solid var(--border); color: var(--text); border-radius: 2px; }
        .wizard-step { display: none; }
        .wizard-step.active { display: block; }

        .btn-mini { padding: 2px 8px; font-size: 0.7rem; border-radius: 2px; cursor: pointer; border: 1px solid var(--border); background: var(--bg-card); color: var(--text); text-decoration: none; }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/sidebar.php'; renderSidebar('lists'); ?>

    <main class="main-content">
        <header class="top-bar">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;">Administrar Bases</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Gestión Integral de Listas y Leads</p>
            </div>
        </header>

        <?php echo $msg; ?>

        <div class="tabs">
            <button class="tab-btn <?php echo !isset($_GET['search_query']) ? 'active' : ''; ?>" onclick="showTab('tab-manage')">📊 Gestión y Carga</button>
            <button class="tab-btn <?php echo isset($_GET['search_query']) ? 'active' : ''; ?>" onclick="showTab('tab-search')">🔍 Buscar Lead</button>
        </div>

        <!-- TAB: GESTION Y CARGA -->
        <div id="tab-manage" class="tab-content <?php echo !isset($_GET['search_query']) ? 'active' : ''; ?>">
            <div class="list-grid">
                <!-- FORMULARIO DE CARGA (WIZARD) -->
                <div class="card">
                    <div id="step-1" class="wizard-step active">
                        <h2 style="margin-bottom: 1.5rem;">Carga Inteligente de Leads</h2>
                        <div class="field"><label>ID de Lista</label><input type="text" id="list_id" placeholder="Ej: 1001" required></div>
                        <div class="field"><label>Nombre</label><input type="text" id="list_name" placeholder="Ej: Leads Marzo"></div>
                        <div class="field">
                            <label>Campaña</label>
                            <select id="campaign_id">
                                <?php foreach ($campaigns as $c): ?>
                                    <option value="<?php echo $c['campaign_id']; ?>"><?php echo $c['campaign_name']; ?> [<?php echo $c['campaign_id']; ?>]</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="file" id="csv_file" accept=".csv,.txt" style="display:none">
                        <div class="upload-area" onclick="document.getElementById('csv_file').click()">
                            <span>📁</span><h3>Selecciona CSV</h3>
                            <div id="file-info" style="margin-top: 1rem; color: var(--primary);"></div>
                        </div>
                        <button class="btn-primary" style="width:100%; margin-top:1.5rem;" onclick="goToStep2()">Continuar al Mapeo →</button>
                    </div>

                    <div id="step-2" class="wizard-step">
                        <h2 style="margin-bottom: 1.5rem;">Mapeo de Columnas</h2>
                        <div style="max-height: 300px; overflow-y: auto;">
                            <table class="mapping-table"><thead><tr><th>Columna</th><th>Dato</th><th>Ejemplo</th></tr></thead><tbody id="mapping-body"></tbody></table>
                        </div>
                        <div style="display:flex; gap:10px; margin-top:1.5rem;">
                            <button class="btn-action" style="flex:1" onclick="goToStep(1)">← Atrás</button>
                            <button class="btn-primary" style="flex:1" onclick="goToStep3()">Previsualizar →</button>
                        </div>
                    </div>

                    <div id="step-3" class="wizard-step">
                        <h2 style="margin-bottom: 1rem;">Confirmación</h2>
                        <p>Total Leads: <strong id="total-leads-count">0</strong></p>
                        <div id="final-preview-table" style="max-height: 200px; overflow: auto; background: var(--glass); border: 1px solid var(--border); font-size: 0.7rem; border-radius: 2px; margin: 1rem 0;"></div>
                        <form method="POST">
                            <input type="hidden" name="list_id" id="form-list-id"><input type="hidden" name="list_name" id="form-list-name">
                            <input type="hidden" name="campaign_id" id="form-campaign-id"><input type="hidden" name="leads_json" id="form-leads-json">
                            <input type="hidden" name="mapping_json" id="form-mapping-json">
                            <div style="display:flex; gap:10px;">
                                <button type="button" class="btn-action" style="flex:1" onclick="goToStep(2)">← Corregir</button>
                                <button type="submit" name="process_leads" class="btn-primary" style="flex:1; background: #10b981;">🚀 Importar</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                        <h2 style="margin:0;">Bases en el Sistema</h2>
                        <form method="GET" style="display:flex; gap:10px; align-items:center;">
                            <select name="filter_campaign" onchange="this.form.submit()" style="font-size:0.8rem; padding:4px; border-radius:2px; width:auto;">
                                <option value="">🔍 Todas las Campañas</option>
                                <?php foreach ($campaigns as $c): ?>
                                    <option value="<?php echo $c['campaign_id']; ?>" <?php echo $filterCampaign === $c['campaign_id'] ? 'selected':''; ?>>
                                        Filtrar: <?php echo $c['campaign_name']; ?> [<?php echo $c['campaign_id']; ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <div style="max-height: 500px; overflow-y: auto;">
                        <table>
                            <thead><tr><th>ID</th><th>Campaña</th><th>Leads</th><th>Status</th><th>Acciones</th></tr></thead>
                            <tbody>
                                <?php foreach ($allLists as $l): ?>
                                <tr>
                                    <td><strong><?php echo $l['list_id']; ?></strong><br><small style="color:var(--text-muted)"><?php echo $l['list_name']; ?></small></td>
                                    <td>
                                        <form method="POST" style="display:flex; gap:5px;">
                                            <input type="hidden" name="list_id" value="<?php echo $l['list_id']; ?>">
                                            <select name="new_campaign_id" style="font-size:0.75rem; padding:2px; width:auto;">
                                                <?php foreach ($campaigns as $c): ?>
                                                    <option value="<?php echo $c['campaign_id']; ?>" <?php echo $c['campaign_id'] == $l['campaign_id'] ? 'selected':''; ?>><?php echo $c['campaign_name']; ?> [<?php echo $c['campaign_id']; ?>]</option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="reassign_campaign" class="btn-mini" title="Reasignar">💾</button>
                                        </form>
                                    </td>
                                    <td><span class="badge" style="background:var(--glass); color:var(--text); padding:2px 6px; border-radius:2px; font-size:0.75rem;"><?php echo number_format($l['lead_count']); ?></span></td>
                                    <td>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="list_id" value="<?php echo $l['list_id']; ?>">
                                            <button type="submit" name="toggle_status" class="btn-mini" style="background: <?php echo $l['active'] == 'Y' ? 'rgba(16,185,129,0.1)':'rgba(239,68,68,0.1)'; ?>; color: <?php echo $l['active'] == 'Y' ? '#10b981':'#ef4444'; ?>; border-color: <?php echo $l['active'] == 'Y' ? '#10b981':'#ef4444'; ?>;">
                                                <?php echo $l['active'] == 'Y' ? 'Activa' : 'Inactiva'; ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('¿Seguro que deseas eliminar esta base y todos sus leads?');">
                                            <input type="hidden" name="list_id" value="<?php echo $l['list_id']; ?>">
                                            <button type="submit" name="delete_list" class="btn-mini" style="color:#ef4444; border-color:#ef4444;">Borrar 🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB: BUSCAR LEAD -->
        <div id="tab-search" class="tab-content <?php echo isset($_GET['search_query']) ? 'active' : ''; ?>">
            <div class="card">
                <form method="GET">
                    <div style="display:flex; gap:1rem; align-items:flex-end;">
                        <div class="field" style="flex:1; margin-bottom:0;">
                            <label>Buscar por Teléfono, Nombre o Lead ID</label>
                            <input type="text" name="search_query" value="<?php echo htmlspecialchars($_GET['search_query'] ?? ''); ?>" placeholder="Ej: 5551234567 o 12345" required>
                        </div>
                        <button type="submit" class="btn-primary" style="padding:10px 30px;">Buscar 🔍</button>
                    </div>
                </form>

                <?php if (!empty($searchResults)): ?>
                    <div style="margin-top: 2rem;">
                        <h3>Resultados de Búsqueda</h3>
                        <table>
                            <thead><tr><th>Lead ID</th><th>Teléfono</th><th>Nombre</th><th>Lista</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($searchResults as $ls): ?>
                                <tr>
                                    <td><strong><?php echo $ls['lead_id']; ?></strong></td>
                                    <td><?php echo $ls['phone_number']; ?></td>
                                    <td><?php echo $ls['first_name'] . " " . $ls['last_name']; ?></td>
                                    <td><code><?php echo $ls['list_id']; ?></code></td>
                                    <td><span class="badge" style="background:var(--glass); color:var(--primary); padding:2px 6px; border-radius:2px;"><?php echo $ls['status']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif (isset($_GET['search_query'])): ?>
                    <p style="text-align:center; padding:2rem; color:var(--text-muted);">No se encontraron leads con ese criterio.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        let rawData = []; let headers = [];
        const systemFields = <?php echo json_encode($systemFields); ?>;
        function showTab(id) {
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(id).classList.add('active');
            event.currentTarget.classList.add('active');
        }
        document.getElementById('csv_file').addEventListener('change', function(e) {
            const file = e.target.files[0]; if (!file) return;
            document.getElementById('file-info').innerText = file.name;
            const reader = new FileReader(); reader.onload = e => parseCSV(e.target.result); reader.readAsText(file);
        });
        function parseCSV(text) {
            const lines = text.split('\n'); if (lines.length === 0) return;
            const firstLine = lines[0]; let sep = firstLine.includes(';') ? ';' : (firstLine.includes('\t') ? '\t' : ',');
            headers = firstLine.split(sep).map(h => h.trim()); rawData = [];
            for (let i = 1; i < lines.length; i++) {
                if (!lines[i].trim()) continue;
                const cols = lines[i].split(sep); const row = {};
                headers.forEach((h, idx) => row[h] = cols[idx] ? cols[idx].trim() : '');
                rawData.push(row);
            }
        }
        function goToStep(n) { document.querySelectorAll('.wizard-step').forEach(s => s.classList.remove('active')); document.getElementById('step-' + n).classList.add('active'); }
        function goToStep2() {
            const listIdInput = document.getElementById('list_id');
            const listId = listIdInput.value.trim();
            if (rawData.length === 0) return alert('Por favor, selecciona un archivo CSV primero.');
            if (!listId || isNaN(listId) || parseInt(listId) <= 0) {
                listIdInput.focus();
                return alert('Por favor, ingresa un ID de Lista válido (numérico).');
            }
            const body = document.getElementById('mapping-body'); body.innerHTML = '';
            headers.forEach(h => {
                let sel = ''; const hl = h.toLowerCase();
                if (hl.includes('tel') || hl.includes('phone')) sel = 'phone_number';
                else if (hl.includes('nom') || hl.includes('name')) sel = 'first_name';
                else if (hl.includes('ape') || hl.includes('last')) sel = 'last_name';
                else if (hl.includes('mail')) sel = 'email';
                else if (hl.includes('vendor') || hl.includes('ext')) sel = 'vendor_lead_code';
                else if (hl.includes('alt') || hl.includes('tel2')) sel = 'alt_phone';
                else if (hl.includes('dir3') || hl.includes('add3')) sel = 'address3';
                let opts = '<option value="">-- Ignorar --</option>';
                for (const [k, v] of Object.entries(systemFields)) opts += `<option value="${k}" ${sel === k ? 'selected':''}>${v}</option>`;
                const tr = document.createElement('tr');
                tr.innerHTML = `<td><strong>${h}</strong></td><td><select class="map-select" data-header="${h}">${opts}</select></td><td style="color:var(--text-muted);font-size:0.7rem">${rawData[0][h]||''}</td>`;
                body.appendChild(tr);
            });
            goToStep(2);
        }
        function goToStep3() {
            const mapping = {}; document.querySelectorAll('.map-select').forEach(sel => { if (sel.value) mapping[sel.dataset.header] = sel.value; });
            document.getElementById('total-leads-count').innerText = rawData.length;
            document.getElementById('form-list-id').value = document.getElementById('list_id').value;
            document.getElementById('form-list-name').value = document.getElementById('list_name').value;
            document.getElementById('form-campaign-id').value = document.getElementById('campaign_id').value;
            document.getElementById('form-leads-json').value = JSON.stringify(rawData);
            document.getElementById('form-mapping-json').value = JSON.stringify(mapping);
            let html = '<table><thead><tr>';
            for (const f of Object.values(mapping)) html += `<th>${systemFields[f]}</th>`;
            html += '</tr></thead><tbody>';
            rawData.slice(0, 3).forEach(r => { html += '<tr>'; for (const h of Object.keys(mapping)) html += `<td>${r[h]}</td>`; html += '</tr>'; });
            document.getElementById('final-preview-table').innerHTML = html + '</tbody></table>';
            goToStep(3);
        }
    </script>
</body>
</html>
