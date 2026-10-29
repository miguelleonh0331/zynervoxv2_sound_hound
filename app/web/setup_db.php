<?php
require_once __DIR__ . '/includes/Database.php';
use Includes\Database;

echo "<h1>Setup de Base de Datos - VOX SPHERE</h1>";

try {
    $db = Database::getInstance();
    
    // 1. Tabla de Logs de Acceso (Login/Logout)
    $sql_access = "CREATE TABLE IF NOT EXISTS vox_sphere_access_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        user VARCHAR(50),
        action ENUM('LOGIN', 'LOGOUT', 'UNAUTHORIZED'),
        ip_address VARCHAR(45),
        details TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql_access);
    echo "<p style='color: green;'>✅ Tabla 'vox_sphere_access_log' lista.</p>";

    // 2. Tabla de Logs de Campañas (Estructura espejo)
    // Intentamos crearla a partir de la estructura de vicidial_campaigns
    $sql_camp_log = "CREATE TABLE IF NOT EXISTS vox_sphere_vicidial_campaigns_log AS 
                     SELECT * FROM vicidial_campaigns WHERE 1=0";
    $db->exec($sql_camp_log);
    
    // Añadimos las columnas de auditoría si no existen
    try {
        $db->exec("ALTER TABLE vox_sphere_vicidial_campaigns_log 
                   ADD COLUMN audit_id INT AUTO_INCREMENT PRIMARY KEY FIRST,
                   ADD COLUMN audit_user VARCHAR(50) AFTER audit_id,
                   ADD COLUMN audit_action VARCHAR(50) AFTER audit_user,
                   ADD COLUMN audit_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER audit_action");
        echo "<p style='color: green;'>✅ Tabla 'vox_sphere_vicidial_campaigns_log' configurada con campos de auditoría.</p>";
    } catch (Exception $e) {
        // Probablemente las columnas ya existen
        echo "<p style='color: blue;'>ℹ️ Las columnas de auditoría ya parecen estar configuradas.</p>";
    }

    // 3. Tabla de Presets de Motor
    $sql_motor = "CREATE TABLE IF NOT EXISTS vox_sphere_motor_presets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        preset_name VARCHAR(100) NOT NULL,
        dial_method VARCHAR(20) DEFAULT 'MANUAL',
        auto_dial_level VARCHAR(20) DEFAULT '0',
        adaptive_maximum_level VARCHAR(20) DEFAULT '3.0',
        adaptive_dropped_percentage VARCHAR(20) DEFAULT '3',
        available_only_ratio_tally VARCHAR(10) DEFAULT 'N',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql_motor);
    echo "<p style='color: green;'>✅ Tabla 'vox_sphere_motor_presets' lista.</p>";

    // 4. Tabla de Auditoría para Presets de Motor
    $sql_motor_log = "CREATE TABLE IF NOT EXISTS vox_sphere_vox_sphere_motor_presets_log AS 
                      SELECT * FROM vox_sphere_motor_presets WHERE 1=0";
    $db->exec($sql_motor_log);
    
    try {
        $db->exec("ALTER TABLE vox_sphere_vox_sphere_motor_presets_log 
                   ADD COLUMN audit_id INT AUTO_INCREMENT PRIMARY KEY FIRST,
                   ADD COLUMN audit_user VARCHAR(50) AFTER audit_id,
                   ADD COLUMN audit_action VARCHAR(50) AFTER audit_user,
                   ADD COLUMN audit_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER audit_action");
        echo "<p style='color: green;'>✅ Auditoría para 'vox_sphere_motor_presets' configurada.</p>";
    } catch (Exception $e) {}

    // 5. Tabla de Logs de Phones (Extensiones)
    $sql_phones_log = "CREATE TABLE IF NOT EXISTS vox_sphere_phones_log AS 
                       SELECT * FROM phones WHERE 1=0";
    $db->exec($sql_phones_log);
    
    try {
        $db->exec("ALTER TABLE vox_sphere_phones_log 
                   ADD COLUMN audit_id INT AUTO_INCREMENT PRIMARY KEY FIRST,
                   ADD COLUMN audit_user VARCHAR(50) AFTER audit_id,
                   ADD COLUMN audit_action VARCHAR(50) AFTER audit_user,
                   ADD COLUMN audit_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER audit_action");
        echo "<p style='color: green;'>✅ Tabla 'vox_sphere_phones_log' configurada.</p>";
    } catch (Exception $e) {
        echo "<p style='color: blue;'>ℹ️ Auditoría de phones ya configurada.</p>";
    }

    echo "<h3>Query Manual para Phones Log:</h3>";
    echo "<pre>
CREATE TABLE vox_sphere_motor_presets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    preset_name VARCHAR(100) NOT NULL,
    dial_method VARCHAR(20) DEFAULT 'MANUAL',
    auto_dial_level VARCHAR(20) DEFAULT '0',
    adaptive_maximum_level VARCHAR(20) DEFAULT '3.0',
    adaptive_dropped_percentage VARCHAR(20) DEFAULT '3',
    available_only_ratio_tally VARCHAR(10) DEFAULT 'N',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vox_sphere_vox_sphere_motor_presets_log AS 
SELECT * FROM vox_sphere_motor_presets WHERE 1=0;

ALTER TABLE vox_sphere_vox_sphere_motor_presets_log 
ADD COLUMN audit_id INT AUTO_INCREMENT PRIMARY KEY FIRST,
ADD COLUMN audit_user VARCHAR(50) AFTER audit_id,
ADD COLUMN audit_action VARCHAR(50) AFTER audit_user,
ADD COLUMN audit_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER audit_action;
    </pre>";

} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
