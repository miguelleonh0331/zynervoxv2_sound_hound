<?php
declare(strict_types=1);
// Marcador: global blacklist (migration 023). A blocked number is never dialed: the engine skips it before the Originate and the dialplans check the same table
// before calling. The attempt is recorded as «BLOCKED» (own state «Bloqueado», no carrier, no retry) and the contact counts in the «Bloqueado» category.

/** Key of the table: the last 9 digits of the number (same normalization as the contacts). '' when the number has no digits. */
function marcador_blacklist_key(string $phone): string {
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    return $d === '' ? '' : substr($d, -9);
}

/** Dialplan answer for a number: 'SI' blocked, 'NO' free, 'ERROR' when the check could not be done (the dialplan then does not call). Never throws. */
function marcador_blacklist_check(\ZynervoxQueries\BotIvrRepository $repo, string $phone): string {
    try {
        $key = marcador_blacklist_key($phone);
        if ($key === '') return 'ERROR'; // a number without digits cannot be checked: do not call blind
        return $repo->blacklistLookup($key) ? 'SI' : 'NO';
    } catch (\Throwable $e) { error_log('ZV2 blacklist_agi: '.get_class($e).': '.$e->getMessage()); return 'ERROR'; }
}
