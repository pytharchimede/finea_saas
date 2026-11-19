<?php
declare(strict_types=1);

require_once __DIR__ . '/../repositories/DevisOfferProjectRepository.php';

final class DevisOfferProjectService
{
    public function __construct(private DevisOfferProjectRepository $repository) {}

    public function group(array $devis): array
    {
        $classifications = $this->repository->classifications(array_column($devis, 'id'));
        $offers = $this->repository->offerLabels(array_column($devis, 'offre_id'));
        $groups = [];

        foreach ($devis as $row) {
            $offerId = (int) ($row['offre_id'] ?? 0);
            $groupKey = $offerId > 0 ? 'offer-' . $offerId : 'without-offer';
            $classification = $classifications[(int) $row['id']] ?? null;
            $row['project_devis_type'] = $classification['devis_type'] ?? 'UNCLASSIFIED';
            $row['is_project_principal'] = $classification
                && (int) $classification['principal_devis_id'] === (int) $row['id'];

            if (!isset($groups[$groupKey])) {
                $offer = $offers[$offerId] ?? null;
                $groups[$groupKey] = [
                    'key' => $groupKey,
                    'offre_id' => $offerId,
                    'offre_number' => $offer['num_offre'] ?? null,
                    'offre_label' => $offerId > 0
                        ? ($offer['reference_offre'] ?? ('Projet #' . $offerId))
                        : 'Sans projet',
                    'devis' => [],
                ];
            }
            $groups[$groupKey]['devis'][] = $row;
        }

        return array_values($groups);
    }

    public function classify(
        int $devisId,
        string $type,
        int $userId,
        bool $transactional = true
    ): array {
        $type = strtoupper(trim($type));
        if (!in_array($type, ['PRINCIPAL', 'TS'], true)) {
            throw new DomainException('Type de devis invalide.');
        }

        if ($transactional) {
            $this->repository->begin();
        }
        try {
            $devis = $this->repository->lockDevis($devisId);
            if (!$devis) {
                throw new DomainException('Devis introuvable.');
            }
            $offerId = (int) $devis['offre_id'];
            if ($offerId <= 0) {
                throw new DomainException('Le devis doit être rattaché à un projet avant son classement.');
            }

            $this->repository->ensureSettings($offerId, $userId);
            $settings = $this->repository->lockSettings($offerId);
            if ($type === 'PRINCIPAL') {
                $this->repository->demoteOtherPrincipal($offerId, $devisId, $userId);
                $this->repository->setPrincipal($offerId, $devisId, $userId);
            } elseif ((int) ($settings['principal_devis_id'] ?? 0) === $devisId) {
                $this->repository->setPrincipal($offerId, null, $userId);
            }
            $this->repository->upsertClassification($devisId, $offerId, $type, $userId);
            $chantierId=$type==='TS'?$this->repository->linkTsToPrincipalChantier($devisId,$offerId):null;

            if ($transactional) {
                $this->repository->commit();
            }
            return ['devis_id' => $devisId, 'offre_id' => $offerId, 'type' => $type, 'chantier_id'=>$chantierId];
        } catch (Throwable $exception) {
            if ($transactional) {
                $this->repository->rollback();
            }
            throw $exception;
        }
    }

    public function createQuote(
        Devis $devisModel,
        Offre $offreModel,
        array $data,
        array $lines,
        array $input,
        int $userId
    ): array {
        $mode = strtoupper(trim((string) ($input['mode'] ?? 'NEW_PROJECT')));
        if (!in_array($mode, ['NEW_PROJECT', 'TS'], true)) {
            throw new DomainException('Mode projet invalide.');
        }

        $this->repository->begin();
        try {
            if ($mode === 'NEW_PROJECT') {
                $offerReference = trim((string) ($input['offer_reference'] ?? ''));
                if ($offerReference === '') {
                    throw new DomainException('L’intitulé du projet est obligatoire.');
                }
                $offreModel->ajouterOffre([
                    'num_offre' => '',
                    'date_offre' => date('Y-m-d'),
                    'reference_offre' => $offerReference,
                    'commercial_dedie' => (string) ($input['user_name'] ?? ''),
                    'date_creat_offre' => date('Y-m-d'),
                    'client_id' => (int) ($data['client_id'] ?? 0),
                ]);
                $data['offre_id'] = $offreModel->getLastCreatedId();
                if ($data['offre_id'] <= 0) {
                    throw new RuntimeException('Le projet associé au devis n’a pas pu être créé.');
                }
            } else {
                $data['offre_id'] = (int) ($data['offre_id'] ?? 0);
                if ($data['offre_id'] <= 0) {
                    throw new DomainException('Sélectionnez le projet du devis TS.');
                }
                if (!$this->repository->principalForOffer($data['offre_id'])) {
                    throw new DomainException(
                        'Ce projet doit d’abord posséder un devis principal classé.'
                    );
                }
                $projectClient = $this->repository->clientForOffer($data['offre_id']);
                if ($projectClient <= 0) {
                    throw new DomainException('Le projet sélectionné doit être rattaché à un client.');
                }
                $data['client_id'] = $projectClient;
            }

            $devisId = (int) $devisModel->creerDevis($data, $lines);
            $classification = $this->classify(
                $devisId,
                $mode === 'NEW_PROJECT' ? 'PRINCIPAL' : 'TS',
                $userId,
                false
            );
            $snapshot = $this->repository->persistenceSnapshot($devisId);
            if (!$snapshot
                || (int)($snapshot['offre_id'] ?? 0) !== (int)$classification['offre_id']
                || (int)($snapshot['line_count'] ?? 0) !== count($lines)) {
                throw new RuntimeException(
                    'Le contrôle final du devis a échoué. Aucune donnée partielle n’a été conservée.'
                );
            }
            $this->repository->commit();
            $project = $offreModel->getOffreById((int)$classification['offre_id']) ?: [];
            return [
                'devis_id' => $devisId,
                'offre_id' => $classification['offre_id'],
                'project_number' => (string)($project['num_offre'] ?? ''),
                'type' => $classification['type'],
                'numero_devis' => (string)($snapshot['numero_devis'] ?? ''),
                'line_count' => (int)($snapshot['line_count'] ?? 0),
                'verified' => true,
            ];
        } catch (Throwable $exception) {
            $this->repository->rollback();
            throw $exception;
        }
    }
}
