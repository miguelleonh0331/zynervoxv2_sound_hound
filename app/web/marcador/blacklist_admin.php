<?php
declare(strict_types=1);
// Marcador: blacklist in two levels (migration 026). Service for the admin screens; every change is audited (who, when, number, list).
// GLOBAL lists (per client, e.g. «BCP»): a campaign picks ONE or none; read only when a base is loaded. There is no bulk delete: only single numbers can be removed.
// REGULAR blacklist (zynervox_blacklist): per campaign or all campaigns (campaign_id 0); read when dialing by the engine and the dialplan. Bulk loads are done by the user in MySQL.

/** A number as the user types it -> its 9 digits. Accepts 9 digits, 51/+51 prefix, spaces, dashes and parentheses. Throws RuntimeException with a plain message otherwise. */
function marcador_blacklist_phone9(string $input): string {
    if (!preg_match('/^[+0-9 ().-]{1,30}$/D', trim($input))) throw new RuntimeException('Número inválido: usa solo dígitos (puede llevar +, espacios, guiones o paréntesis).');
    $d = preg_replace('/\D+/', '', $input) ?? '';
    if (strlen($d) === 11 && str_starts_with($d, '51')) $d = substr($d, 2);
    if (!preg_match('/^[0-9]{9}$/D', $d)) throw new RuntimeException('El número debe tener 9 dígitos (se acepta con prefijo 51 o +51, espacios o guiones).');
    return $d;
}
function marcador_blacklist_actor(string $actor): string {
    $actor = trim($actor);
    if ($actor === '' || mb_strlen($actor) > 80) throw new RuntimeException('Falta el usuario que hace el cambio.');
    return $actor;
}
function marcador_blacklist_list_name(string $name): string {
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 80 || preg_match('/[\x00-\x1f]/u', $name)) throw new RuntimeException('El nombre de la lista es obligatorio (hasta 80 caracteres).');
    return $name;
}
function marcador_blacklist_scope(string $scope): string {
    if (!in_array($scope, ['global', 'regular'], true)) throw new RuntimeException('Tipo de lista inválido: global o regular.');
    return $scope;
}

/** Global lists: list_id, name, created_at (UTC), created_by, numbers, campaigns (how many campaigns use it). */
function bot_bl_lists(\ZynervoxQueries\BotIvrRepository $db): array {
    return array_map(fn($r) => ['list_id'=>(int)$r['list_id'], 'name'=>$r['name'], 'created_at'=>$r['created_at'], 'created_by'=>$r['created_by'], 'numbers'=>(int)$r['numbers'], 'campaigns'=>(int)$r['campaigns']], $db->blacklistLists());
}
function bot_bl_list_create(\ZynervoxQueries\BotIvrRepository $db, string $name, string $actor): int {
    return $db->createBlacklistList(marcador_blacklist_list_name($name), marcador_blacklist_actor($actor));
}
function bot_bl_list_rename(\ZynervoxQueries\BotIvrRepository $db, int $listId, string $name, string $actor): void {
    $db->renameBlacklistList($listId, marcador_blacklist_list_name($name), marcador_blacklist_actor($actor));
}
/** Where a number is: rows {scope: global|regular, list_id (global), list_name (global list or campaign / «Todas las campañas»), campaign_id (regular), reason, created_at, created_by}. */
function bot_bl_search(\ZynervoxQueries\BotIvrRepository $db, string $phone): array {
    return $db->blacklistSearch(marcador_blacklist_phone9($phone));
}
/** Adds (or updates the reason of) a number. $scope 'global' with $target = global list id, or 'regular' with $target = campaign id (0 = all campaigns). */
function bot_bl_add(\ZynervoxQueries\BotIvrRepository $db, string $scope, int $target, string $phone, string $reason, string $actor): string {
    $scope = marcador_blacklist_scope($scope); $reason = trim($reason);
    if (mb_strlen($reason) > 160) throw new RuntimeException('El motivo no puede superar 160 caracteres.');
    if ($target < 0 || ($scope === 'global' && $target < 1)) throw new RuntimeException('Elige la lista.');
    $key = marcador_blacklist_phone9($phone);
    $db->blacklistAdd($scope, $target, $key, $reason, marcador_blacklist_actor($actor));
    return $key;
}
/** Removes ONE number; $confirm must be true (the screen asks first). Returns 1 when it was removed, 0 when it was not in the list. */
function bot_bl_remove(\ZynervoxQueries\BotIvrRepository $db, string $scope, int $target, string $phone, string $actor, bool $confirm): int {
    if (!$confirm) throw new RuntimeException('Confirma antes de retirar el número.');
    $scope = marcador_blacklist_scope($scope);
    if ($target < 0 || ($scope === 'global' && $target < 1)) throw new RuntimeException('Elige la lista.');
    return $db->blacklistRemove($scope, $target, marcador_blacklist_phone9($phone), marcador_blacklist_actor($actor));
}
/** Numbers in the REGULAR blacklist of one campaign (0 = only the «all campaigns» rows, same scope as bot_bl_clear_regular). Read only. */
function bot_bl_count_regular(\ZynervoxQueries\BotIvrRepository $db, int $campaignId): int {
    if ($campaignId < 0) throw new RuntimeException('Campaña inválida.');
    return $db->blacklistCountRegular($campaignId);
}
/** Empties the REGULAR blacklist of one campaign (0 = the «all campaigns» rows only; other campaigns are untouched). $confirm must be true. Returns the rows removed. There is no such operation for global lists. */
function bot_bl_clear_regular(\ZynervoxQueries\BotIvrRepository $db, int $campaignId, string $actor, bool $confirm): int {
    if (!$confirm) throw new RuntimeException('Confirma antes de vaciar la lista.');
    if ($campaignId < 0) throw new RuntimeException('Campaña inválida.');
    if ($campaignId > 0) $db->campaign($campaignId);
    return $db->blacklistClearRegular($campaignId, marcador_blacklist_actor($actor));
}
/** The campaign uses ONE global list ($listId) or none (null / 0). The campaign's «Blacklist» Sí/No (regular) is independent. */
function bot_bl_assign_global(\ZynervoxQueries\BotIvrRepository $db, int $campaignId, ?int $listId, string $actor): void {
    $db->setCampaignGlobalBlacklist($campaignId, $listId === 0 ? null : $listId, marcador_blacklist_actor($actor));
}
/** Audit trail, newest first: audit_id, at (UTC), actor, action (add|remove|clear|list_create|list_rename|assign), scope, list_id, list_name, campaign_id, phone, rows_affected, detail. $phone filters one number. */
function bot_bl_audit(\ZynervoxQueries\BotIvrRepository $db, int $limit = 50, ?string $phone = null): array {
    return $db->blacklistAuditRows($limit, $phone === null || trim($phone) === '' ? null : marcador_blacklist_phone9($phone));
}
