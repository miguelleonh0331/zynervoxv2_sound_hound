<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
initial_survey_require_login();

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="plantilla_carga.txt"');
header('X-Content-Type-Options: nosniff');

echo "\xEF\xBB\xBF";
echo "id_contacto,nombre,numero,monto,tienda,direccion\r\n";
echo "902930,MARÍA GARCÍA,919192014,7000,CARSA PUCALLPA PORTILLO,AV. UNIVERSITARIA MZA. A LOTE. 33\r\n";
echo "903167,ELIZABETH CHÁVEZ,965092929,11100,CARSA AGUAYTÍA,AVENIDA SAN MARTÍN 927\r\n";
