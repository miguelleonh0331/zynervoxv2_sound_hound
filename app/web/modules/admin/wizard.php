<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Campaigns.php';
require_once __DIR__ . '/../../includes/UserGroups.php';
require_once __DIR__ . '/../../includes/Motor.php';
require_once __DIR__ . '/../../includes/Dispositions.php';
require_once __DIR__ . '/../../includes/Audit.php';

use Includes\Auth;
use Includes\Campaigns;
use Includes\UserGroups;
use Includes\Motor;
use Includes\Dispositions;
use Includes\Audit;

Auth::checkAccess(9);
session_start();

// Reiniciar el wizard si se solicita
if (isset($_GET['reset'])) {
    unset($_SESSION['wizard']);
    header("Location: wizard.php");
    exit;
}

// Inicializar estado si no existe
if (!isset($_SESSION['wizard'])) {
    $_SESSION['wizard'] = [
        'step' => 1,
        'data' => []
    ];
}

$currentStep = $_SESSION['wizard']['step'];
$wizardData = $_SESSION['wizard']['data'];
$msg = '';

// Procesar avance de pasos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['next'])) {
        // Guardar datos actuales
        foreach ($_POST as $k => $v) {
            if ($k !== 'next') $_SESSION['wizard']['data'][$k] = $v;
        }
        $_SESSION['wizard']['step']++;
        header("Location: wizard.php");
        exit;
    }
    if (isset($_POST['prev'])) {
        $_SESSION['wizard']['step']--;
        header("Location: wizard.php");
        exit;
    }
    
    // Finalizar proceso
    if (isset($_POST['finish'])) {
        $final = $_SESSION['wizard']['data'];
        
        try {
            // 1. Crear Campaña
            $cId = Campaigns::clone($final['source_campaign_id'], $final['campaign_id'], $final['campaign_name']);
            
            if ($cId) {
                // 2. Grupos de Usuario
                if (isset($final['create_new_group']) && $final['create_new_group'] == 'Y') {
                    UserGroups::create([
                        'user_group' => $final['new_group_id'],
                        'group_name' => $final['new_group_name'],
                        'allowed_campaigns' => " {$final['campaign_id']} -"
                    ]);
                }
                
                if (isset($final['existing_groups']) && is_array($final['existing_groups'])) {
                    foreach ($final['existing_groups'] as $egId) {
                        $eg = UserGroups::getById($egId);
                        if ($eg) {
                            $newAllowed = trim(str_replace(' -', '', $eg['allowed_campaigns'])) . " {$final['campaign_id']} -";
                            UserGroups::update($egId, ['allowed_campaigns' => $newAllowed]);
                        }
                    }
                }

                // 3. Aplicar Motor
                if (!empty($final['motor_preset_id'])) {
                    Motor::applyToCampaign($final['motor_preset_id'], $final['campaign_id']);
                }

                // 4. Copiar Tipificaciones
                if (!empty($final['source_dispo_campaign'])) {
                    Dispositions::copyFromCampaign($final['source_dispo_campaign'], $final['campaign_id']);
                }

                $msg = "<p style='color: #10b981; font-weight: bold; padding: 1rem; background: rgba(16,185,129,0.1); border-radius: 8px;'>✨ ¡Campaña '{$final['campaign_id']}' desplegada con éxito en todos los módulos!</p>";
                unset($_SESSION['wizard']);
                $currentStep = 6; // Pantalla de éxito
            }
        } catch (Exception $e) {
            $msg = "<p style='color: #fca5a5;'>❌ Error en el proceso: " . $e->getMessage() . "</p>";
        }
    }
}

// Datos para Selects
$allCampaigns = Campaigns::getAll();
$templates = Campaigns::getTemplates();
$groups = UserGroups::getAll();
$motorPresets = Motor::getPresets();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Asistente de Campaña - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>
        .wizard-steps { display: flex; gap: 8px; margin-bottom: 1.25rem; }
        .w-step { flex: 1; height: 4px; background: var(--border); border-radius: 2px; position: relative; }
        .w-step.active { background: var(--primary); }
        .w-step span { position: absolute; top: 8px; left: 0; font-size: 0.65rem; color: var(--text-muted); text-transform: uppercase; white-space: nowrap; }
        .w-step.active span { color: var(--primary); font-weight: 700; }

        .wizard-card { max-width: 800px; margin: 0 auto; min-height: 360px; display: flex; flex-direction: column; }
        .wizard-body { flex: 1; padding: 1.25rem 0; }
        .wizard-footer { display: flex; justify-content: space-between; padding-top: 1rem; border-top: 1px solid var(--border); }

        .option-box { background: var(--glass); border: 1px solid var(--border); border-radius: 2px; padding: 1rem; cursor: pointer; }
        .option-box:hover { border-color: var(--primary); }
        .option-box.selected { border-width: 2px; border-color: var(--primary); }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/sidebar.php'; renderSidebar('wizard'); ?>

    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1.25rem; text-align: center;">
            <div style="flex: 1;">
                <h1 style="font-size: 1.25rem; font-weight: 700; color: var(--text);">Asistente de Nueva Campaña</h1>
                <p style="color: var(--text-muted); font-size: 0.8125rem;">Despliegue de infraestructura Zynervox en 5 pasos</p>
            </div>
        </header>

        <div class="wizard-steps">
            <div class="w-step <?php echo $currentStep >= 1 ? 'active' : ''; ?>"><span>1. Identidad</span></div>
            <div class="w-step <?php echo $currentStep >= 2 ? 'active' : ''; ?>"><span>2. Acceso</span></div>
            <div class="w-step <?php echo $currentStep >= 3 ? 'active' : ''; ?>"><span>3. Motor</span></div>
            <div class="w-step <?php echo $currentStep >= 4 ? 'active' : ''; ?>"><span>4. Estados</span></div>
            <div class="w-step <?php echo $currentStep >= 5 ? 'active' : ''; ?>"><span>5. Revisión</span></div>
        </div>

        <?php echo $msg; ?>

        <?php if ($currentStep == 6): ?>
            <div class="card" style="text-align: center; padding: 2.5rem 1.5rem;">
                <div style="font-size: 2.5rem; margin-bottom: 1rem;">🚀</div>
                <h2 style="font-size: 1.25rem; margin-bottom: 0.75rem;">Campaña Desplegada</h2>
                <p style="color: var(--text-muted); margin-bottom: 2rem;">Todo el entorno ha sido configurado y está listo para operar.</p>
                <div style="display: flex; gap: 1rem; justify-content: center;">
                    <a href="campaigns.php" class="btn-primary" style="padding: 1rem 2rem;">Ir a Campañas</a>
                    <a href="wizard.php?reset=1" class="btn-action" style="padding: 1rem 2rem;">Crear Otra</a>
                </div>
            </div>
        <?php else: ?>

            <form method="POST">
                <div class="card wizard-card">
                    <div class="wizard-body">
                        
                        <?php if ($currentStep == 1): ?>
                            <!-- PASO 1: CAMPAÑA -->
                            <h2 style="margin-bottom: 0.5rem;">Campaña Base</h2>
                            <p style="color: var(--text-muted); margin-bottom: 2rem;">Define los datos básicos y la plantilla técnica.</p>
                            
                            <div class="field">
                                <label>ID de Campaña (Numérico/Texto)</label>
                                <input type="text" name="campaign_id" required value="<?php echo $wizardData['campaign_id'] ?? ''; ?>" placeholder="Ej: 8001 o VENTAS">
                            </div>
                            <div class="field">
                                <label>Nombre de Campaña</label>
                                <input type="text" name="campaign_name" required value="<?php echo $wizardData['campaign_name'] ?? ''; ?>" placeholder="Ej: Ventas Portabilidad BBVA">
                            </div>
                            <div class="field">
                                <label>Clonar desde Plantilla Técnica</label>
                                <select name="source_campaign_id" required>
                                    <option value="">-- Seleccionar Plantilla --</option>
                                    <?php foreach ($templates as $t): ?>
                                        <option value="<?php echo $t['campaign_id']; ?>" <?php echo ($wizardData['source_campaign_id'] ?? '') == $t['campaign_id'] ? 'selected' : ''; ?>>
                                            <?php echo "{$t['campaign_id']} - {$t['campaign_name']}"; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p style="font-size: 0.7rem; color: var(--primary); margin-top: 5px;">ℹ️ Todas las configuraciones avanzadas heredadas de la plantilla.</p>
                            </div>

                        <?php elseif ($currentStep == 2): ?>
                            <!-- PASO 2: GRUPOS -->
                            <h2 style="margin-bottom: 0.5rem;">Grupos de Acceso</h2>
                            <p style="color: var(--text-muted); margin-bottom: 2rem;">¿Quién gestionará esta campaña?</p>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                                <!-- NUEO GRUPO -->
                                <div class="option-box <?php echo ($wizardData['create_new_group'] ?? '') == 'Y' ? 'selected' : ''; ?>" style="text-align: left;">
                                    <label style="display:flex; justify-content: space-between; align-items: center; width: 100%; cursor: pointer; margin:0;">
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            <input type="checkbox" name="create_new_group" value="Y" <?php echo ($wizardData['create_new_group'] ?? '') == 'Y' ? 'checked' : ''; ?> onchange="toggleDisplay('div-new-grp')">
                                            <strong style="font-size: 1rem;">Crear Nuevo Grupo</strong>
                                        </div>
                                    </label>
                                    <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 10px;">Para una gestión aislada y dedicada.</p>
                                    
                                    <div id="div-new-grp" style="margin-top: 1.5rem; display: <?php echo ($wizardData['create_new_group'] ?? '') == 'Y' ? 'block' : 'none'; ?>;">
                                        <div class="field">
                                            <input type="text" name="new_group_id" placeholder="ID Grupo (Ej: BBVA)" value="<?php echo $wizardData['new_group_id'] ?? ''; ?>" style="margin-bottom: 10px;">
                                            <input type="text" name="new_group_name" placeholder="Nombre (Ej: Grupo Ventas BBVA)" value="<?php echo $wizardData['new_group_name'] ?? ''; ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- GRUPOS EXISTENTES -->
                                <div class="option-box" style="text-align: left; cursor: default;">
                                    <strong style="display: block; margin-bottom: 5px;">Añadir a Grupos Existentes</strong>
                                    <p style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 1.5rem;">Permitir acceso a otros niveles.</p>
                                    <div style="max-height: 200px; overflow-y: auto; background: var(--glass); border-radius: 2px; padding: 8px; border: 1px solid var(--border);">
                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                            <?php foreach ($groups as $g): ?>
                                                <label style="display:flex; align-items:center; gap:12px; padding: 6px; font-size: 0.8rem; cursor: pointer; border-radius: 2px;">
                                                    <input type="checkbox" name="existing_groups[]" value="<?php echo $g['user_group']; ?>"
                                                        <?php echo in_array($g['user_group'], $wizardData['existing_groups'] ?? []) ? 'checked' : ''; ?>>
                                                    <span style="color: var(--primary); font-weight: 700; min-width: 60px;"><?php echo $g['user_group']; ?></span>
                                                    <span style="color: var(--text-muted);"><?php echo $g['group_name']; ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        <?php elseif ($currentStep == 3): ?>
                            <!-- PASO 3: MOTOR -->
                            <h2 style="margin-bottom: 0.5rem;">Dialing Motor</h2>
                            <p style="color: var(--text-muted); margin-bottom: 2rem;">Velocidad y agresividad de marcación.</p>
                            
                            <div style="display: grid; gap: 1rem;">
                                <?php foreach ($motorPresets as $p): ?>
                                    <label class="option-box" style="display: flex; justify-content: space-between; align-items: center; cursor: pointer;">
                                        <div>
                                            <strong style="color: var(--primary);"><?php echo $p['preset_name']; ?></strong>
                                            <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 5px;">Dial Level: <?php echo $p['dial_level']; ?> | Dial Method: <?php echo $p['dial_method']; ?></p>
                                        </div>
                                        <input type="radio" name="motor_preset_id" value="<?php echo $p['id']; ?>" required <?php echo ($wizardData['motor_preset_id'] ?? '') == $p['id'] ? 'checked' : ''; ?>>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                        <?php elseif ($currentStep == 4): ?>
                            <!-- PASO 4: TIPOS -->
                            <h2 style="margin-bottom: 0.5rem;">Tipificaciones</h2>
                            <p style="color: var(--text-muted); margin-bottom: 2rem;">Disposiciones de llamada y estados.</p>
                            
                            <div class="field">
                                <label>Importar Estados y Disposiciones desde:</label>
                                <select name="source_dispo_campaign" required>
                                    <option value="">-- Seleccionar Campaña Fuente --</option>
                                    <?php foreach ($allCampaigns as $c): ?>
                                        <option value="<?php echo $c['campaign_id']; ?>" <?php echo ($wizardData['source_dispo_campaign'] ?? '') == $c['campaign_id'] ? 'selected' : ''; ?>>
                                            <?php echo "{$c['campaign_id']} - {$c['campaign_name']}"; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p style="font-size: 0.7rem; color: var(--primary); margin-top: 5px;">ℹ️ Se copiarán todos los estados locales de la campaña seleccionada.</p>
                            </div>

                        <?php elseif ($currentStep == 5): ?>
                            <!-- PASO 5: RESUMEN -->
                            <h2 style="margin-bottom: 0.5rem;">Resumen de Configuración</h2>
                            <p style="color: var(--text-muted); margin-bottom: 2rem;">Verifica los datos antes de desplegar.</p>
                            
                            <div style="background: var(--glass); border: 1px solid var(--border); border-radius: 2px; padding: 1rem;">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; font-size: 0.85rem;">
                                    <div><span style="color: var(--text-muted);">Campaña:</span> <strong><?php echo $wizardData['campaign_id']; ?></strong></div>
                                    <div><span style="color: var(--text-muted);">Nombre:</span> <strong><?php echo $wizardData['campaign_name']; ?></strong></div>
                                    <div><span style="color: var(--text-muted);">Plantilla Base:</span> <strong><?php echo $wizardData['source_campaign_id']; ?></strong></div>
                                    <div><span style="color: var(--text-muted);">Nuevo Grupo:</span> <strong><?php echo ($wizardData['create_new_group'] ?? 'N') == 'Y' ? $wizardData['new_group_id'] : 'No'; ?></strong></div>
                                    <div><span style="color: var(--text-muted);">Motor Preset ID:</span> <strong><?php echo $wizardData['motor_preset_id']; ?></strong></div>
                                    <div><span style="color: var(--text-muted);">Copia Tipos:</span> <strong><?php echo $wizardData['source_dispo_campaign']; ?></strong></div>
                                </div>
                            </div>
                            <div style="margin-top: 1rem; border: 1px dashed var(--primary); border-radius: 2px; padding: 0.75rem; background: var(--glass); text-align: center;">
                                <p style="font-size: 0.8rem; color: var(--primary);">Este proceso creará múltiples registros en las tablas de Vicidial y generará auditorías completas.</p>
                            </div>

                        <?php endif; ?>

                    </div>
                    
                    <div class="wizard-footer">
                        <div>
                            <?php if ($currentStep > 1): ?>
                                <button type="submit" name="prev" class="btn-action">← Atrás</button>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($currentStep < 5): ?>
                                <button type="submit" name="next" class="btn-primary" style="padding: 0.75rem 2.5rem;">Continuar →</button>
                            <?php else: ?>
                                <button type="submit" name="finish" class="btn-primary" style="padding: 0.75rem 3rem; background: #10b981; border-color: #10b981;">🚀 Desplegar Entorno</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </main>

    <script>
        function toggleDisplay(id) {
            const el = document.getElementById(id);
            el.style.display = el.style.display === 'none' ? 'block' : 'none';
        }
    </script>
</body>
</html>
