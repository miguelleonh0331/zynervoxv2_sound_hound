<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/Database.php';
require_once __DIR__.'/../zynervox_queries/factory.php';

function bot_ivr_db_config(): array {
    return \Includes\Database::getBotIvrConfig();
}

function bot_ivr_db_connect(array $config): PDO {
    return \ZynervoxQueries\connect($config);
}

function carsa_db(): PDO {
    return \Includes\Database::getBotIvrInstance();
}

function bot_ivr_repository(): \ZynervoxQueries\BotIvrRepository {
    return \ZynervoxQueries\botIvrRepository(bot_ivr_db_config(), carsa_db());
}
