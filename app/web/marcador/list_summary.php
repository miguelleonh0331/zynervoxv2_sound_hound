<?php
declare(strict_types=1);
// Marcador: summary per list (migration 019). Incremental counters that survive deleting the list; the engine keeps them fresh,
// the dashboard and the reports read them instead of scanning every attempt. Own folder, outside bot_ivr/.

/** AMD figures count only attempts from here on (audio of the provider reached Asterisk from 03/11/2026 15:31:54 Lima). */
function marcador_amd_valid_from_utc(): string {
    return (new DateTimeImmutable('2026-11-03 15:31:54', new DateTimeZone('America/Lima')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Adds the attempts finished since the last pass. $state: running | stopped | finished | deleted. */
function marcador_list_summary_refresh(\ZynervoxQueries\BotIvrRepository $repo, int $listId, string $state): void {
    $repo->refreshListSummary($listId, $state, marcador_amd_valid_from_utc());
}

/** Writes the summary for every list or attempt list id that has none yet (one-off after migrating, and for lists deleted before 019). */
function marcador_list_summary_backfill(\ZynervoxQueries\BotIvrRepository $repo): int {
    $n = 0;
    foreach ($repo->listIdsWithoutSummary() as $id) {
        $row = $repo->listSummary($id);
        $exists = false;
        foreach ($repo->listsChannels() as $l) if ((int)$l['list_id'] === $id) { $exists = true; $running = $l['dialer_state'] === 'running'; }
        marcador_list_summary_refresh($repo, $id, $exists ? (!empty($running) ? 'running' : 'stopped') : 'deleted');
        $n++;
    }
    return $n;
}

/**
 * A running list with no open calls and nothing left to dial now or later (no new contacts, no retries pending) is FINISHED:
 * its summary is closed, it deactivates itself and stops (and so does its campaign if it was the last one running).
 */
function marcador_list_finish_if_done(\ZynervoxQueries\BotIvrRepository $repo, int $campaignId, int $listId, string $actor = 'motor'): bool {
    $c = $repo->campaign($campaignId);
    if (!$repo->listHasNoPendingWork($campaignId, $listId, (string)$c['lead_order'])) return false;
    bot_cfg_list_stop($repo, $campaignId, $listId, $actor);
    $repo->deactivateList($campaignId, $listId);
    marcador_list_summary_refresh($repo, $listId, 'finished');
    return true;
}

/** One maintenance pass (the engine runs it every few seconds): refresh started lists and lists with calls still open, finish the done ones, mark deleted lists. */
function marcador_list_summary_maintain(\ZynervoxQueries\BotIvrRepository $repo): array {
    $r = ['refreshed'=>0, 'finished'=>0, 'deleted'=>0];
    foreach ($repo->listsToMaintain() as $l) {
        $id = (int)$l['list_id']; $cid = (int)$l['campaign_id'];
        $running = $l['dialer_state'] === 'running' && (int)$l['active'] === 1;
        marcador_list_summary_refresh($repo, $id, $running ? 'running' : 'stopped'); $r['refreshed']++;
        if ($running && marcador_list_finish_if_done($repo, $cid, $id)) $r['finished']++;
    }
    foreach ($repo->summariesOfDeletedLists() as $id) { marcador_list_summary_refresh($repo, $id, 'deleted'); $r['deleted']++; }
    return $r;
}
