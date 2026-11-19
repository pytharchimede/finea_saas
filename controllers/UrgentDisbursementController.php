<?php

require_once __DIR__ . '/../services/UrgentDisbursementService.php';

class UrgentDisbursementController
{
    private UrgentDisbursementService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new UrgentDisbursementService($pdo);
    }

    public function service(): UrgentDisbursementService
    {
        return $this->service;
    }

    public function submitEmployee(array $post, array $files, array $requester): int
    {
        return $this->service->createFromEmployee($post, $files['justificatif'] ?? [], $requester);
    }

    public function handleAccountingAction(array $post, int $actorUserId, string $actorName): string
    {
        $action = trim((string)($post['urgent_action'] ?? ''));
        $id = (int)($post['urgent_id'] ?? 0);
        $note = trim((string)($post['note'] ?? ''));
        if ($id <= 0) {
            throw new RuntimeException('Demande urgente introuvable.');
        }
        if ($action === 'decaiss') {
            if (!$this->service->markDecaissed($id, $actorUserId, $actorName, $note)) {
                throw new RuntimeException('Decaissement impossible pour cette demande.');
            }
            return 'Demande urgente decaissee. Le demandeur doit regulariser sous 3 jours.';
        }
        if ($action === 'reject') {
            if (!$this->service->reject($id, $actorUserId, $actorName, $note)) {
                throw new RuntimeException('Refus impossible pour cette demande.');
            }
            return 'Demande urgente refusee.';
        }
        throw new RuntimeException('Action non reconnue.');
    }
}
