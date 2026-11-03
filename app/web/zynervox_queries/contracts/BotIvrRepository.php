<?php
declare(strict_types=1);
namespace ZynervoxQueries;
interface BotIvrRepository {
    public function createDialRun(int $listId,int $campaignId,string $runId,int $channels,array $config,string $actor): void;
    public function dialRun(int $listId,int $campaignId,?string $runId=null): ?array;
    public function transitionDialRun(string $runId,string $state,string $error=''): void;
    public function claimDialLead(string $runId,string $callId): ?array;
    public function rejectDialClaim(string $runId,string $callId): void;
    public function saveIvrResult(string $callId, string $nodeId, array $fields): void;
    public function ivrCallContext(int $listId, int $leadId, string $callId): array;
    public function recordCallEvent(string $callId, string $type, string $eventId, int $epoch, array $data): void;
    public function openCallsForRecovery(): array;
    public function observeCallPresence(string $callId, bool $active, int $epoch): bool;
    public function startCall(int $listId, string $phone, string $callId): int;
    /** @param array $causes optional keys sip_cause, disposition, cause_source (migration 014) */
    public function finishCall(string $callId, string $dialStatus, string $amdStatus, string $amdCause, int $hangupCause, bool $answered, array $causes = []): void;
    public function campaignConfig(int $id): array;
    public function saveCampaignConfig(?int $id, array $campaign, array $headers, array $rules, array $schedules): int;
    public function setDialerState(int $id, string $state, string $actor): void;
    public function campaignLeadFields(int $id): array;
    public function dialerCampaignIds(?int $only = null): array;
    public function campaignActiveCalls(int $id): int;
    public function campaignAttemptsReport(?int $campaignId, int $limit): array;
    public function previewCampaignLeads(int $id, string $order, int $limit): array;
    public function countEligibleLeads(int $id, string $order): int;
    public function claimCampaignLead(int $id, string $order, string $callId, callable $resolve, string $rejectStatus = 'HDR_ERR'): ?array;
    public function rejectCampaignClaim(string $callId): void;
    public function finishUnansweredCall(string $callId, string $dialStatus, int $q850, string $sipText, string $disposition): void;
    public function campaignMonitor(): array;
    public function campaigns(): array;
    public function campaign(int $id): array;
    public function createCampaign(string $name, bool $active): int;
    public function updateCampaign(int $id, array $values): void;
    public function lists(int $campaignId): array;
    public function createList(int $campaignId, string $name, bool $active, ?int $flowId = null): int;
    public function updateList(int $campaignId, int $listId, string $name, bool $active, ?int $flowId = null): void;
    public function list(int $listId, int $campaignId): array;
    public function deleteList(int $listId, int $campaignId): int;
    public function updateListDialPrefix(int $listId, int $campaignId, string $prefix): void;
    public function listStatistics(int $listId,int $campaignId): array;
    public function leadCount(int $listId): int;
    public function audioLeads(int $listId, int $campaignId): array;
    public function importLeads(int $listId, int $campaignId, array $parsed): array;
}
