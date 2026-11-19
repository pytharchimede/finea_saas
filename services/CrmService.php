<?php

require_once __DIR__ . '/../repositories/CrmAuditRepository.php';
require_once __DIR__ . '/../repositories/CrmScheduleRepository.php';
require_once __DIR__ . '/../repositories/CrmNotificationRepository.php';
require_once __DIR__ . '/../repositories/CrmClientRepository.php';
require_once __DIR__ . '/../repositories/CrmProspectRepository.php';
require_once __DIR__ . '/../repositories/CrmRdvRepository.php';
require_once __DIR__ . '/../repositories/CrmOpportuniteRepository.php';
require_once __DIR__ . '/../repositories/CrmDashboardRepository.php';

class CrmService
{
    private CrmClientRepository $clients;
    private CrmProspectRepository $prospects;
    private CrmRdvRepository $rdvs;
    private CrmOpportuniteRepository $opportunites;
    private CrmDashboardRepository $dashboard;
    private CrmAuditRepository $auditRepository;
    private CrmScheduleRepository $scheduleRepository;
    private CrmNotificationRepository $notificationRepository;

    public function __construct(CrmClientRepository $clients, CrmProspectRepository $prospects, CrmRdvRepository $rdvs, CrmOpportuniteRepository $opportunites, CrmDashboardRepository $dashboard, CrmAuditRepository $auditRepository, CrmScheduleRepository $scheduleRepository, CrmNotificationRepository $notificationRepository)
    {
        $this->clients = $clients;
        $this->prospects = $prospects;
        $this->rdvs = $rdvs;
        $this->opportunites = $opportunites;
        $this->dashboard = $dashboard;
        $this->auditRepository = $auditRepository;
        $this->scheduleRepository = $scheduleRepository;
        $this->notificationRepository = $notificationRepository;
    }

    public function clients(): CrmClientRepository
    {
        return $this->clients;
    }

    public function prospects(): CrmProspectRepository
    {
        return $this->prospects;
    }

    public function rdvs(): CrmRdvRepository
    {
        return $this->rdvs;
    }

    public function opportunites(): CrmOpportuniteRepository
    {
        return $this->opportunites;
    }

    public function dashboard(): CrmDashboardRepository
    {
        return $this->dashboard;
    }

    public function listLatestClients(int $limit = 200): array
    {
        return $this->clients->listLatest($limit);
    }

    public function listClientsForSelect(int $limit = 1200): array
    {
        return $this->clients->listForSelect($limit);
    }

    public function listClientsForProspectForm(int $limit = 2000): array
    {
        return $this->clients->listForProspectForm($limit);
    }

    public function listLatestProspects(int $limit = 200): array
    {
        return $this->prospects->listLatest($limit);
    }

    public function listProspectsForSelect(int $limit = 1000): array
    {
        return $this->prospects->listForSelect($limit);
    }

    public function listUpcomingRdv(int $limit = 60): array
    {
        return $this->rdvs->listUpcoming($limit);
    }

    public function listRecentOpportunities(int $limit = 160): array
    {
        return $this->opportunites->listRecent($limit);
    }

    public function buildDashboardData(int $currentUserId, int $personnelId, bool $scheduleReady): array
    {
        $data = [
            'kpiProspects' => 0,
            'kpiClients' => 0,
            'kpiRdvToday' => 0,
            'kpiRdvNext7' => 0,
            'upcomingRdv' => [],
            'todoTodayEvents' => [],
            'todoWeekEvents' => [],
            'todoTodayOpp' => [],
            'todoWeekOpp' => [],
            'todoInactiveProspects' => [],
        ];

        try {
            $data['kpiProspects'] = $this->prospects->countAll();
            $data['kpiClients'] = $this->clients->countAll();
            $data['kpiRdvToday'] = $this->rdvs->countToday();
            $data['kpiRdvNext7'] = $this->rdvs->countNextDays(7);
            $data['upcomingRdv'] = $this->rdvs->listUpcoming(8);
        } catch (Throwable $e) {
        }

        try {
            $today = new DateTimeImmutable('today');
            $todayStart = $today->setTime(0, 0, 0);
            $todayEnd = $today->setTime(23, 59, 59);
            $weekEnd = $today->modify('+7 days')->setTime(23, 59, 59);

            if ($scheduleReady && $personnelId > 0) {
                $data['todoTodayEvents'] = $this->dashboard->listScheduleEvents($personnelId, $todayStart->format('Y-m-d H:i:s'), $todayEnd->format('Y-m-d H:i:s'), 50);
                $data['todoWeekEvents'] = $this->dashboard->listScheduleEvents($personnelId, $todayStart->format('Y-m-d H:i:s'), $weekEnd->format('Y-m-d H:i:s'), 50);
            }

            if ($currentUserId > 0) {
                $data['todoTodayOpp'] = $this->dashboard->listUserNextActions($currentUserId, $todayStart->format('Y-m-d H:i:s'), $todayEnd->format('Y-m-d H:i:s'), 50);
                $data['todoWeekOpp'] = $this->dashboard->listUserNextActions($currentUserId, $todayStart->format('Y-m-d H:i:s'), $weekEnd->format('Y-m-d H:i:s'), 50);
                $data['todoInactiveProspects'] = $this->dashboard->listInactiveProspectsForUser($currentUserId, 8);
            }
        } catch (Throwable $e) {
        }

        return $data;
    }

    public function isScheduleReady(): bool
    {
        return $this->scheduleRepository->isReady();
    }

    public function resolvePersonnelIdByEmail(string $email): ?int
    {
        return $this->scheduleRepository->resolvePersonnelIdByEmail($email);
    }

    public function createClient(array $post): array
    {
        $code = trim((string)($post['code_client'] ?? ''));
        $nom = trim((string)($post['nom_client'] ?? ''));
        $localisation = trim((string)($post['localisation_client'] ?? ''));
        $commune = trim((string)($post['commune_client'] ?? ''));
        $bp = trim((string)($post['bp_client'] ?? ''));
        $pays = trim((string)($post['pays_client'] ?? ''));
        $telephone = trim((string)($post['telephone_client'] ?? ''));
        $annee = (int)($post['date_creat_client'] ?? (int)date('Y'));

        if ($nom === '' || $localisation === '' || $commune === '' || $bp === '' || $pays === '') {
            return ['ok' => false, 'message' => 'Veuillez renseigner au minimum : Nom, Localisation, Commune, BP, Pays.'];
        }

        try {
            $clientId = $this->clients->create([
                'code_client' => ($code !== '' ? $code : null),
                'nom_client' => $nom,
                'localisation_client' => $localisation,
                'commune_client' => $commune,
                'bp_client' => $bp,
                'pays_client' => $pays,
                'date_creat_client' => ($annee > 1900 ? $annee : (int) date('Y')),
                'telephone_client' => ($telephone !== '' ? $telephone : null),
            ]);

            $this->audit('crm_client_created', 'clients_create', 'client', $clientId, $nom, 'success', [
                'code_client' => ($code !== '' ? $code : null),
                'telephone_client' => ($telephone !== '' ? $telephone : null),
                'commune_client' => $commune,
                'pays_client' => $pays,
                'view_mode' => 'form_submit',
            ]);

            return ['ok' => true, 'message' => 'Client créé.'];
        } catch (Throwable $e) {
            $this->audit('crm_client_create_failed', 'clients_create', 'client', null, $nom !== '' ? $nom : 'Création client CRM', 'failed', [
                'code_client' => ($code !== '' ? $code : null),
                'telephone_client' => ($telephone !== '' ? $telephone : null),
                'error_message' => 'Impossible de créer le client.',
                'view_mode' => 'form_submit',
            ], 'warning');
            return ['ok' => false, 'message' => 'Impossible de créer le client.'];
        }
    }

    public function createProspect(array $post, array $session): array
    {
        $clientId = (int)($post['client_id'] ?? 0);
        $nom = trim((string)($post['nom'] ?? ''));
        $societe = trim((string)($post['societe'] ?? ''));
        $telephone = trim((string)($post['telephone'] ?? ''));
        $email = trim((string)($post['email'] ?? ''));
        $statut = trim((string)($post['statut'] ?? 'Nouveau'));
        $source = trim((string)($post['source'] ?? ''));
        $notes = trim((string)($post['notes'] ?? ''));
        $codeClient = trim((string)($post['code_client'] ?? ''));
        $localisation = trim((string)($post['localisation'] ?? ''));
        $commune = trim((string)($post['commune'] ?? ''));
        $bp = trim((string)($post['bp'] ?? ''));
        $pays = trim((string)($post['pays'] ?? ''));

        $allowedStatus = ['Nouveau', 'Qualifie', 'Qualifié', 'En negociation', 'En négociation', 'Gagne', 'Gagné', 'Perdu'];
        if (!in_array($statut, $allowedStatus, true)) {
            $statut = 'Nouveau';
        }

        if ($clientId > 0) {
            $client = $this->clients->find($clientId);
            if ($client) {
                $societe = trim((string)($client['nom_client'] ?? ''));
                $telephone = trim((string)($client['telephone_client'] ?? ''));
                $codeClient = trim((string)($client['code_client'] ?? ''));
                $localisation = trim((string)($client['localisation_client'] ?? ''));
                $commune = trim((string)($client['commune_client'] ?? ''));
                $bp = trim((string)($client['bp_client'] ?? ''));
                $pays = trim((string)($client['pays_client'] ?? ''));
                if ($nom === '') {
                    $nom = $societe !== '' ? $societe : 'Prospect';
                }
            } else {
                $clientId = 0;
            }
        }

        if ($nom === '') {
            return ['ok' => false, 'message' => 'Le nom du prospect est obligatoire (ou sélectionnez un client existant).'];
        }

        try {
            $prospectId = $this->prospects->create([
                'client_id' => ($clientId > 0 ? $clientId : null),
                'code_client' => ($codeClient !== '' ? $codeClient : null),
                'localisation' => ($localisation !== '' ? $localisation : null),
                'commune' => ($commune !== '' ? $commune : null),
                'bp' => ($bp !== '' ? $bp : null),
                'pays' => ($pays !== '' ? $pays : null),
                'nom' => $nom,
                'societe' => ($societe !== '' ? $societe : null),
                'telephone' => ($telephone !== '' ? $telephone : null),
                'email' => ($email !== '' ? $email : null),
                'statut' => $statut,
                'source' => ($source !== '' ? $source : null),
                'notes' => ($notes !== '' ? $notes : null),
                'created_by' => (int)($session['user_id'] ?? 0) ?: null,
            ]);
            $this->audit('crm_prospect_created', 'prospects_create', 'prospect', $prospectId, $nom, 'success', [
                'client_id' => $clientId > 0 ? $clientId : null,
                'societe' => ($societe !== '' ? $societe : null),
                'statut' => $statut,
                'source' => ($source !== '' ? $source : null),
                'view_mode' => 'form_submit',
            ]);
            return ['ok' => true, 'message' => 'Prospect créé.'];
        } catch (Throwable $e) {
            $this->audit('crm_prospect_create_failed', 'prospects_create', 'prospect', null, $nom !== '' ? $nom : 'Création prospect CRM', 'failed', [
                'client_id' => $clientId > 0 ? $clientId : null,
                'societe' => ($societe !== '' ? $societe : null),
                'error_message' => 'Impossible de créer le prospect.',
                'view_mode' => 'form_submit',
            ], 'warning');
            return ['ok' => false, 'message' => 'Impossible de créer le prospect.'];
        }
    }

    public function createRdv(array $post, array &$session): array
    {
        $linkedType = (string)($post['linked_type'] ?? 'prospect');
        $linkedType = in_array($linkedType, ['prospect', 'client'], true) ? $linkedType : 'prospect';
        $prospectId = (int)($post['prospect_id'] ?? 0);
        $clientId = (int)($post['client_id'] ?? 0);
        $titre = trim((string)($post['titre'] ?? ''));
        $dateDebutRaw = (string)($post['date_debut'] ?? '');
        $dateFinRaw = (string)($post['date_fin'] ?? '');
        $lieu = trim((string)($post['lieu'] ?? ''));
        $statut = trim((string)($post['statut'] ?? 'Planifié'));
        $description = trim((string)($post['description'] ?? ''));

        if (!in_array($statut, ['Planifie', 'Planifié', 'Fait', 'Annule', 'Annulé'], true)) {
            $statut = 'Planifie';
        }
        if ($titre === '' || $dateDebutRaw === '') {
            return ['ok' => false, 'message' => 'Titre et date de début sont obligatoires.'];
        }

        $dateDebut = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $dateDebutRaw);
        if (!$dateDebut) {
            return ['ok' => false, 'message' => 'Date de début invalide.'];
        }

        $dateFin = null;
        if ($dateFinRaw !== '') {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $dateFinRaw);
            if ($parsed) {
                $dateFin = $parsed;
            }
        }

        if ($linkedType === 'prospect') {
            $clientId = 0;
            if ($prospectId <= 0) {
                return ['ok' => false, 'message' => 'Veuillez sélectionner un prospect.'];
            }
        } else {
            $prospectId = 0;
            if ($clientId <= 0) {
                return ['ok' => false, 'message' => 'Veuillez sélectionner un client.'];
            }
        }

        try {
            $rdvId = $this->rdvs->create([
                'linked_type' => $linkedType,
                'prospect_id' => ($prospectId > 0 ? $prospectId : null),
                'client_id' => ($clientId > 0 ? $clientId : null),
                'titre' => $titre,
                'date_debut' => $dateDebut->format('Y-m-d H:i:s'),
                'date_fin' => $dateFin ? $dateFin->format('Y-m-d H:i:s') : null,
                'lieu' => ($lieu !== '' ? $lieu : null),
                'statut' => $statut,
                'description' => ($description !== '' ? $description : null),
                'created_by' => (int)($session['user_id'] ?? 0) ?: null,
            ]);

            $this->notifyRdvCreated($rdvId, (int)($session['user_id'] ?? 0));
            $schedule = $this->addRdvToSchedule($titre, $dateDebut, $dateFin, $statut, $session);

            $this->audit('crm_rdv_created', 'rdv_create', 'crm_rdv', $rdvId, $titre, 'success', [
                'linked_type' => $linkedType,
                'prospect_id' => $prospectId > 0 ? $prospectId : null,
                'client_id' => $clientId > 0 ? $clientId : null,
                'date_debut' => $dateDebut->format('Y-m-d H:i:s'),
                'date_fin' => $dateFin ? $dateFin->format('Y-m-d H:i:s') : null,
                'statut' => $statut,
                'added_to_schedule' => (bool) $schedule['ok'],
                'schedule_error' => $schedule['ok'] ? null : $schedule['error'],
            ]);

            return [
                'ok' => true,
                'message' => $schedule['ok'] ? 'RDV créé et ajouté à votre emploi du temps.' : 'RDV créé.',
                'warning' => $schedule['ok'] ? '' : (string) $schedule['error'],
            ];
        } catch (Throwable $e) {
            $this->audit('crm_rdv_create_failed', 'rdv_create', 'crm_rdv', null, $titre !== '' ? $titre : 'Création RDV CRM', 'failed', [
                'linked_type' => $linkedType,
                'prospect_id' => $prospectId > 0 ? $prospectId : null,
                'client_id' => $clientId > 0 ? $clientId : null,
                'error_message' => 'Impossible de créer le RDV.',
            ], 'warning');
            return ['ok' => false, 'message' => 'Impossible de créer le RDV.'];
        }
    }

    public function updateOpportunityStage(array $post): array
    {
        $id = (int)($post['id'] ?? 0);
        $etape = trim((string)($post['etape'] ?? ''));
        $probabilite = ($post['probabilite'] ?? null);
        $probabilite = ($probabilite === null || $probabilite === '') ? null : (int) $probabilite;
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Opportunité invalide.'];
        }
        if (!in_array($etape, $this->allowedOpportunitySteps(), true)) {
            return ['ok' => false, 'message' => 'Étape invalide.'];
        }

        try {
            $this->opportunites->updateEtape($id, $etape, $probabilite);
            $this->audit('crm_opportunity_updated', 'opportunites_update_stage', 'opportunity', $id, 'Mise à jour étape opportunité', 'success', [
                'etape' => $etape,
                'probabilite' => $probabilite,
                'view_mode' => 'form_submit',
            ]);
            return ['ok' => true, 'message' => 'Opportunité mise à jour.'];
        } catch (Throwable $e) {
            $this->audit('crm_opportunity_update_failed', 'opportunites_update_stage', 'opportunity', $id, 'Mise à jour étape opportunité', 'failed', [
                'etape' => $etape,
                'probabilite' => $probabilite,
                'error_message' => 'Impossible de mettre à jour l\'opportunité.',
                'view_mode' => 'form_submit',
            ], 'warning');
            return ['ok' => false, 'message' => 'Impossible de mettre à jour l\'opportunité.'];
        }
    }

    public function setOpportunityNextAction(array $post, array &$session): array
    {
        $id = (int)($post['id'] ?? 0);
        $nextActionAt = trim((string)($post['next_action_at'] ?? ''));
        $durationMinRaw = ($post['next_action_duration_min'] ?? '');
        $durationMin = $durationMinRaw === '' ? null : (int) $durationMinRaw;
        $doClear = !empty($post['clear_next_action']);
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Opportunité invalide.'];
        }
        if ($doClear) {
            $nextActionAt = '';
            $durationMin = null;
        }

        try {
            $row = $this->opportunites->findNextAction($id);
            if ($nextActionAt === '') {
                $this->deleteScheduleEvent((int)($row['next_action_event_id'] ?? 0), $session);
                $this->opportunites->setNextAction($id, null, null, null);
                return ['ok' => true, 'message' => 'Prochaine action supprimée.'];
            }

            $this->opportunites->setNextAction($id, $nextActionAt, $durationMin, null);
            $row = $this->opportunites->findNextAction($id) ?: $row;
            $schedule = $this->upsertOpportunityScheduleEvent($id, (string)($row['titre'] ?? 'Opportunité'), $nextActionAt, $durationMin, (int)($row['next_action_event_id'] ?? 0), $session);
            if (!empty($schedule['event_id'])) {
                $this->opportunites->setNextAction($id, $nextActionAt, $durationMin, (int) $schedule['event_id']);
            }

            $this->audit('crm_opportunity_next_action_updated', 'opportunites_set_next_action', 'opportunity', $id, 'Prochaine action opportunité', 'success', [
                'next_action_at' => $nextActionAt,
                'next_action_duration_min' => $durationMin,
                'next_action_event_id' => $schedule['event_id'] ?? null,
                'schedule_error' => $schedule['ok'] ? null : $schedule['error'],
            ]);

            return [
                'ok' => true,
                'message' => $schedule['ok'] ? 'Prochaine action planifiée dans l’emploi du temps.' : 'Prochaine action enregistrée.',
                'warning' => $schedule['ok'] ? '' : (string) $schedule['error'],
            ];
        } catch (Throwable $e) {
            $this->audit('crm_opportunity_next_action_update_failed', 'opportunites_set_next_action', 'opportunity', $id, 'Prochaine action opportunité', 'failed', [
                'next_action_at' => $nextActionAt,
                'next_action_duration_min' => $durationMin,
                'error_message' => 'Impossible d’enregistrer la prochaine action.',
            ], 'warning');
            return ['ok' => false, 'message' => 'Impossible d’enregistrer la prochaine action.'];
        }
    }

    public function createOpportunity(array $post, array &$session): array
    {
        $linkedType = (string)($post['linked_type'] ?? 'prospect');
        $linkedType = in_array($linkedType, ['prospect', 'client'], true) ? $linkedType : 'prospect';
        $prospectId = (int)($post['prospect_id'] ?? 0);
        $clientId = (int)($post['client_id'] ?? 0);
        $titre = trim((string)($post['titre'] ?? ''));
        $etape = trim((string)($post['etape'] ?? 'Nouveau'));
        $montant = trim((string)($post['montant_xof'] ?? ''));
        $probabilite = (int)($post['probabilite'] ?? 10);
        $notes = trim((string)($post['notes'] ?? ''));
        $nextActionAt = trim((string)($post['next_action_at'] ?? ''));
        $durationMinRaw = ($post['next_action_duration_min'] ?? '');
        $durationMin = $durationMinRaw === '' ? null : (int) $durationMinRaw;

        if ($titre === '') {
            return ['ok' => false, 'message' => 'Le titre est obligatoire.'];
        }
        if (!in_array($etape, $this->allowedOpportunitySteps(), true)) {
            $etape = 'Nouveau';
        }
        if ($linkedType === 'prospect') {
            $clientId = 0;
            if ($prospectId <= 0) {
                return ['ok' => false, 'message' => 'Veuillez sélectionner un prospect.'];
            }
        } else {
            $prospectId = 0;
            if ($clientId <= 0) {
                return ['ok' => false, 'message' => 'Veuillez sélectionner un client.'];
            }
        }

        try {
            $oppId = $this->opportunites->create([
                'linked_type' => $linkedType,
                'prospect_id' => ($prospectId > 0 ? $prospectId : null),
                'client_id' => ($clientId > 0 ? $clientId : null),
                'titre' => $titre,
                'etape' => $etape,
                'montant_xof' => ($montant !== '' ? $montant : null),
                'probabilite' => $probabilite,
                'notes' => ($notes !== '' ? $notes : null),
                'next_action_at' => ($nextActionAt !== '' ? $nextActionAt : null),
                'next_action_duration_min' => $durationMin,
                'created_by' => (int)($session['user_id'] ?? 0) ?: null,
            ]);

            $schedule = ['ok' => true, 'error' => '', 'event_id' => null];
            if ($nextActionAt !== '') {
                $schedule = $this->upsertOpportunityScheduleEvent($oppId, $titre, $nextActionAt, $durationMin, 0, $session);
                if (!empty($schedule['event_id'])) {
                    $this->opportunites->setNextAction($oppId, $nextActionAt, $durationMin, (int) $schedule['event_id']);
                }
            }

            $this->audit('crm_opportunity_created', 'opportunites_create', 'opportunity', $oppId, $titre, 'success', [
                'linked_type' => $linkedType,
                'prospect_id' => $prospectId > 0 ? $prospectId : null,
                'client_id' => $clientId > 0 ? $clientId : null,
                'etape' => $etape,
                'probabilite' => $probabilite,
                'next_action_at' => $nextActionAt !== '' ? $nextActionAt : null,
                'schedule_error' => $schedule['ok'] ? null : $schedule['error'],
            ]);

            return [
                'ok' => true,
                'message' => ($nextActionAt !== '' && $schedule['ok']) ? 'Opportunité créée et ajoutée à votre emploi du temps.' : 'Opportunité créée.',
                'warning' => $schedule['ok'] ? '' : (string) $schedule['error'],
            ];
        } catch (Throwable $e) {
            $this->audit('crm_opportunity_create_failed', 'opportunites_create', 'opportunity', null, $titre !== '' ? $titre : 'Création opportunité CRM', 'failed', [
                'linked_type' => $linkedType,
                'prospect_id' => $prospectId > 0 ? $prospectId : null,
                'client_id' => $clientId > 0 ? $clientId : null,
                'error_message' => 'Impossible de créer l\'opportunité.',
            ], 'warning');
            return ['ok' => false, 'message' => 'Impossible de créer l\'opportunité.'];
        }
    }

    private function audit(string $event, string $action, string $entityType, ?int $entityId, string $label, string $result, array $details, string $severity = 'info'): void
    {
        $this->auditRepository->log($event, [
            'module' => 'crm',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId !== null && $entityId > 0 ? (string) $entityId : null,
            'entity_label' => $label,
            'result' => $result,
            'severity' => $severity,
            'details' => $details,
        ]);
    }

    private function allowedOpportunitySteps(): array
    {
        return ['Prospection', 'Nouveau', 'Qualification', 'Proposition', 'Devis envoye', 'Devis envoyé', 'Negociation', 'Négociation', 'Gagne', 'Gagné', 'Perdu'];
    }

    private function notifyRdvCreated(int $rdvId, int $createdByUserId): void
    {
        if ($createdByUserId <= 0) {
            return;
        }
        try {
            $row = $this->rdvs->getById($rdvId);
            if ($row) {
                $this->notificationRepository->notifyRdvCreated($createdByUserId, $row);
            }
        } catch (Throwable $e) {
        }
    }

    private function addRdvToSchedule(string $titre, DateTimeImmutable $dateDebut, ?DateTimeImmutable $dateFin, string $statut, array &$session): array
    {
        $personnel = $this->resolveSchedulePersonnel($session);
        if (!$personnel['ok']) {
            return $personnel;
        }

        $endForSchedule = $dateFin ?: $dateDebut->modify('+1 hour');
        $statusCode = 'planifie';
        if ($statut === 'Fait') {
            $statusCode = 'termine';
        } elseif (in_array($statut, ['Annule', 'Annulé'], true)) {
            $statusCode = 'annule';
        }

        $res = $this->scheduleRepository->createEvent(
            (int) $personnel['personnel_id'],
            'RDV - ' . $titre,
            $dateDebut->format('Y-m-d H:i:s'),
            $endForSchedule->format('Y-m-d H:i:s'),
            $statusCode,
            (int)($session['user_id'] ?? 0) ?: null
        );

        return !empty($res['ok'])
            ? ['ok' => true, 'error' => '', 'event_id' => (int)($res['id'] ?? 0)]
            : ['ok' => false, 'error' => (string)($res['error'] ?? 'Ajout impossible.'), 'event_id' => null];
    }

    private function upsertOpportunityScheduleEvent(int $oppId, string $titre, string $nextActionAt, ?int $durationMin, int $existingEventId, array &$session): array
    {
        $personnel = $this->resolveSchedulePersonnel($session);
        if (!$personnel['ok']) {
            return $personnel + ['event_id' => null];
        }

        try {
            $startDt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $nextActionAt) ?: new DateTimeImmutable($nextActionAt);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Date de prochaine action invalide.', 'event_id' => null];
        }

        $dur = max(5, min(480, (int)($durationMin ?? 30)));
        $endDt = $startDt->modify('+' . $dur . ' minutes');
        $title = 'CRM - Action : ' . trim($titre !== '' ? $titre : 'Opportunité');

        if ($existingEventId > 0) {
            $res = $this->scheduleRepository->updateEvent($existingEventId, (int) $personnel['personnel_id'], $title, $startDt->format('Y-m-d H:i:s'), $endDt->format('Y-m-d H:i:s'), 'planifie');
            if (!empty($res['ok'])) {
                return ['ok' => true, 'error' => '', 'event_id' => $existingEventId];
            }
        }

        $res = $this->scheduleRepository->createEvent($personnel['personnel_id'], $title, $startDt->format('Y-m-d H:i:s'), $endDt->format('Y-m-d H:i:s'), 'planifie', (int)($session['user_id'] ?? 0) ?: null);
        if (!empty($res['ok'])) {
            return ['ok' => true, 'error' => '', 'event_id' => (int)($res['id'] ?? 0)];
        }

        return ['ok' => false, 'error' => (string)($res['error'] ?? 'Ajout impossible.'), 'event_id' => null];
    }

    private function deleteScheduleEvent(int $eventId, array &$session): void
    {
        if ($eventId <= 0) {
            return;
        }
        $personnel = $this->resolveSchedulePersonnel($session);
        if ($personnel['ok']) {
            $this->scheduleRepository->deleteEvent($eventId, (int) $personnel['personnel_id']);
        }
    }

    private function resolveSchedulePersonnel(array &$session): array
    {
        if (!$this->scheduleRepository->isReady()) {
            return ['ok' => false, 'error' => $this->scheduleRepository->getInitError() ?: 'Module indisponible.', 'personnel_id' => 0];
        }

        $key = 'emploi_du_temps_personnel_id';
        $personnelId = (int)($session[$key] ?? 0);
        if ($personnelId <= 0) {
            $autoId = $this->scheduleRepository->resolvePersonnelIdByEmail((string)($session['mail_pro'] ?? ''));
            if ($autoId) {
                $personnelId = (int) $autoId;
                $session[$key] = $personnelId;
            }
        }

        if ($personnelId <= 0) {
            return ['ok' => false, 'error' => 'Aucun personnel associé à ce compte (ouvrez Emploi du temps une fois ou vérifiez l’email pro).', 'personnel_id' => 0];
        }

        return ['ok' => true, 'error' => '', 'personnel_id' => $personnelId];
    }
}
