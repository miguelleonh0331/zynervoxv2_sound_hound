<?php
declare(strict_types=1);
namespace ZynervoxQueries;
require_once __DIR__.'/connection.php';
require_once __DIR__.'/contracts/BotIvrRepository.php';
function botIvrRepository(array $config, ?\PDO $connection = null): BotIvrRepository {
    engine($config);
    if ($connection !== null && $connection->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new \RuntimeException('La conexión no corresponde al adaptador MySQL/MariaDB.');
    }
    require_once __DIR__.'/mysql/bot_ivr/repository.php';
    return new Mysql\BotIvr\Repository($connection ?? connect($config));
}
