<?php
declare(strict_types=1);
// Marcador: blacklist per campaign (migrations 023/024). Only campaigns with the option «Blacklist» = Sí consult it. A blocked number is never dialed: the engine skips it before the Originate and the dialplans check the same table
// before calling (a row applies to one campaign, or to all with campaign_id 0). The attempt is recorded as «BLOCKED» (own state «Bloqueado», no carrier, no retry) and the contact counts in the «Bloqueado» category.

/** Key of the table: the last 9 digits of the number (same normalization as the contacts). '' when the number has no digits. */
function marcador_blacklist_key(string $phone): string {
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    return $d === '' ? '' : substr($d, -9);
}

/** Dialplan answer for a number of a campaign: 'SI' blocked, 'NO' free (also: no campaign, or the campaign has the option off), 'ERROR' when the check could not be done on a campaign that needs it (the dialplan then does not call). Never throws. */
function marcador_blacklist_check(\ZynervoxQueries\BotIvrRepository $repo, string $phone, string $campaign = ''): string {
    try {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $campaign)) return 'NO'; // no campaign (MicroSIP 7309, old dialplans): nothing to consult
        $key = marcador_blacklist_key($phone);
        if ($key === '') return 'ERROR'; // a number without digits cannot be checked: do not call blind
        return $repo->blacklistLookup($key, (int)$campaign) ? 'SI' : 'NO';
    } catch (\Throwable $e) { error_log('ZV2 blacklist_agi: '.get_class($e).': '.$e->getMessage()); return 'ERROR'; }
}
