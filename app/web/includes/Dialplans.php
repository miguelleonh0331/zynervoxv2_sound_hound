<?php
namespace Includes;

require_once __DIR__.'/Database.php';
require_once __DIR__.'/Audit.php';
require_once __DIR__.'/DialplanOrigins.php';

class Dialplans {
    private static function database() {
        if (!\Config\Config::deployment('isolated', false) || \Config\Config::get('CORE_DB_database') !== 'zynervox_core') throw new \RuntimeException('Dialplan requiere zynervox_core aislado.');
        return Database::getCoreInstance();
    }

    public static function getAll(): array {
        return self::database()->query('SELECT * FROM v2_dialplans ORDER BY dial_prefix,dialplan_id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function getById(int $id): ?array {
        $stmt=self::database()->prepare('SELECT * FROM v2_dialplans WHERE dialplan_id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function origins(): array {
        return self::database()->query("SELECT dialplan_id,dial_prefix,name FROM v2_dialplans WHERE active='Y' ORDER BY name,dial_prefix")->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function save(?int $id, string $name, string $body, string $active): array {
        $name=trim($name);
        if ($name==='' || mb_strlen($name,'UTF-8')>100 || preg_match('/[\x00-\x1f]/',$name)) throw new \InvalidArgumentException('Ingresa un nombre de hasta 100 caracteres.');
        if (!in_array($active,['Y','N'],true)) throw new \InvalidArgumentException('Estado inválido.');
        $body=DialplanOrigins::body($body);
        if (strlen($body)>262144 || strpos($body,"\0")!==false) throw new \InvalidArgumentException('Dialplan inválido o mayor de 256 KB.');
        $prefixes=DialplanOrigins::prefixes($body);
        if (count($prefixes)!==1) throw new \InvalidArgumentException('Cada dialplan debe tener un único prefijo: exten => _7306X.,1,...');
        $db=self::database();
        $db->beginTransaction();
        try {
            $rows=$db->query('SELECT * FROM v2_dialplans ORDER BY dialplan_id FOR UPDATE')->fetchAll(\PDO::FETCH_ASSOC);
            $found=$id===null;
            $preview=[];
            foreach ($rows as $row) {
                if ((int)$row['dialplan_id']===$id) { $found=true; continue; }
                if ($row['dial_prefix']===$prefixes[0]) throw new \InvalidArgumentException('Este prefijo ya está registrado en otro dialplan.');
                if ($row['active']==='Y') $preview[]=['carrier_id'=>'DIALPLAN-'.$row['dialplan_id'],'dialplan_entry'=>$row['dialplan_entry']];
            }
            if (!$found) throw new \InvalidArgumentException('El dialplan no existe.');
            // Validate helper context conflicts even before publishing a new active plan.
            if ($active==='Y') $preview[]=['carrier_id'=>'DIALPLAN-NEW','dialplan_entry'=>$body];
            DialplanOrigins::render($preview);
            if ($id===null) {
                $db->prepare('INSERT INTO v2_dialplans (name,dial_prefix,dialplan_entry,active) VALUES (?,?,?,?)')->execute([$name,$prefixes[0],$body,$active]);
                $id=(int)$db->lastInsertId();
            } else $db->prepare('UPDATE v2_dialplans SET name=?,dial_prefix=?,dialplan_entry=?,active=? WHERE dialplan_id=?')->execute([$name,$prefixes[0],$body,$active,$id]);
            Audit::logAccess($_SESSION['user'] ?? 'system','DIALPLAN_SAVE',(string)$id);
            $db->commit();
        } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        return ['id'=>$id]+self::publish();
    }

    public static function delete(int $id): array {
        $stmt=self::database()->prepare('DELETE FROM v2_dialplans WHERE dialplan_id=?');
        $stmt->execute([$id]);
        if (!$stmt->rowCount()) throw new \InvalidArgumentException('El dialplan no existe.');
        Audit::logAccess($_SESSION['user'] ?? 'system','DIALPLAN_DELETE',(string)$id);
        return self::publish();
    }

    private static function publish(): array {
        require_once __DIR__.'/Carriers.php';
        return Carriers::regenerateAndReload();
    }
}
