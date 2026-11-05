<?php
declare(strict_types=1);
// Marcador: global blacklist (migration 023). A blocked number is never dialed: the engine skips it before the Originate and the dialplans check the same table
// before calling. The attempt is recorded as «BLOCKED» (own state «Bloqueado», no carrier, no retry) and the contact counts in the «Bloqueado» category.

/** Key of the table: the last 9 digits of the number (same normalization as the contacts). '' when the number has no digits. */
function marcador_blacklist_key(string $phone): string {
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    return $d === '' ? '' : substr($d, -9);
}
