<?php

require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

$pageTitle = 'Paramètres notifications';
$activeMenu = 'admin';
$page = 'admin';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/NotificationController.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/NotificationSoundSettingsManager.php';

$pdo = \Database::getConnection();

$notifManager = new \NotificationController($pdo);
$legalManager = new \EmployeeLegalRequestManager($pdo);
$soundSettingsManager = new \NotificationSoundSettingsManager($pdo);
$soundSettingsManager->ensureTable();
$requestTypes = $legalManager->getRequestTypes();
$notifManager->ensureDefaultRulesForLegalRequestTypes($requestTypes);
$notifManager->ensureDefaultRulesForLegalRequestProcessed();
$notifManager->ensureDefaultRulesForDecaissementSubmitted();
$notifManager->ensureDefaultRulesForDecaissementProcessed();
$notifManager->ensureDefaultRulesForScheduleOccurrenceCreated();
$notifManager->ensureDefaultRulesForScheduleTaskAssigned();
$notifManager->ensureDefaultRulesForScheduleTaskChanged();
$notifManager->ensureDefaultRulesForCommunicationTaskAssigned();
$notifManager->ensureDefaultRulesForCommunicationTaskChanged();
$notifManager->ensureDefaultRulesForCrmRdvCreated();
$notifManager->ensureDefaultRulesForCrmRdvReminder();
$notifManager->ensureDefaultRulesForCrmOpportunityNextActionReminder();
$notifManager->ensureDefaultRulesForCrmProspectInactive();
$notifManager->ensureDefaultRulesForBrokenDebourseLinksDetected();
$notifManager->ensureDefaultRulesForRhHierarchyAssigned();
$notifManager->ensureDefaultRulesForSupplierWorkflow();
$notifManager->ensureDefaultRulesForLogisticsRequest();
$notifManager->ensureDefaultRulesForLogisticsAsset();
$notifManager->ensureDefaultRulesForAttendanceDeviceAlert();

$flash = null;
$flashType = 'success';
$saveHandlers = ['approved', 'rejected'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $saveSection = strtolower(trim((string) ($_POST['save_section'] ?? 'legal_request_submitted')));
    try {

    if ($saveSection === 'notification_sound') {
        $soundSettingsManager->save(
            !empty($_POST['sound_enabled']),
            $_FILES['notification_sound_file'] ?? null,
            !empty($_POST['remove_notification_sound']),
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null
        );
    } elseif ($saveSection === 'legal_request_submitted') {
        foreach ($requestTypes as $type => $def) {
            $type = strtolower(trim((string) $type));
            if ($type === '') {
                continue;
            }
            $enabled = !empty($_POST['enabled_' . $type]);
            $recipient = (string) ($_POST['recipient_' . $type] ?? ($def['team'] ?? 'rh'));
            $label = (string) ($_POST['label_' . $type] ?? ('Demande de ' . ($def['label'] ?? $type)));
            $channels = [];
            if (!empty($_POST['ch_system_' . $type])) {
                $channels[] = 'system';
            }
            if (!empty($_POST['ch_email_' . $type])) {
                $channels[] = 'email';
            }
            $notifManager->upsertRule(
                'legal_request_submitted',
                $type,
                $label,
                $enabled,
                $recipient,
                $channels,
                (string) ($_POST['title_tpl_' . $type] ?? ''),
                (string) ($_POST['msg_tpl_' . $type] ?? ''),
                postedExcludedEmails('excluded_' . $type)
            );
        }
    } elseif ($saveSection === 'supplier_workflow') {
        foreach (['submitted', 'approved', 'rejected'] as $k) {
            $enabled = !empty($_POST['enabled_supplier_workflow_' . $k]);
            $label = (string) ($_POST['label_supplier_workflow_' . $k] ?? ('supplier_workflow_' . $k));
            $channels = [];
            if (!empty($_POST['ch_system_supplier_workflow_' . $k])) {
                $channels[] = 'system';
            }
            if (!empty($_POST['ch_email_supplier_workflow_' . $k])) {
                $channels[] = 'email';
            }

            $recipient = $k === 'submitted' ? 'finance' : 'requester';
            $notifManager->upsertRule(
                'supplier_workflow',
                $k,
                $label,
                $enabled,
                $recipient,
                $channels,
                (string) ($_POST['title_tpl_supplier_workflow_' . $k] ?? ''),
                (string) ($_POST['msg_tpl_supplier_workflow_' . $k] ?? ''),
                postedExcludedEmails('excluded_supplier_workflow_' . $k)
            );
        }
    } elseif ($saveSection === 'logistics_request') {
        foreach (['submitted', 'approved', 'rejected', 'details_requested', 'handover', 'recovered'] as $k) {
            $channels = [];
            if (!empty($_POST['ch_system_logistics_request_' . $k])) {
                $channels[] = 'system';
            }
            if (!empty($_POST['ch_email_logistics_request_' . $k])) {
                $channels[] = 'email';
            }
            $recipient = $k === 'submitted' ? 'logistique' : 'requester';
            $notifManager->upsertRule(
                'logistics_request',
                $k,
                (string) ($_POST['label_logistics_request_' . $k] ?? ('logistics_request_' . $k)),
                !empty($_POST['enabled_logistics_request_' . $k]),
                $recipient,
                $channels,
                (string) ($_POST['title_tpl_logistics_request_' . $k] ?? ''),
                (string) ($_POST['msg_tpl_logistics_request_' . $k] ?? ''),
                postedExcludedEmails('excluded_logistics_request_' . $k)
            );
        }
    } elseif ($saveSection === 'logistics_asset') {
        foreach (['asset_created', 'driver_created', 'stock_item_created', 'stock_movement_created', 'maintenance_created', 'incident_created', 'document_created', 'supplier_created'] as $k) {
            $channels = [];
            if (!empty($_POST['ch_system_logistics_asset_' . $k])) {
                $channels[] = 'system';
            }
            if (!empty($_POST['ch_email_logistics_asset_' . $k])) {
                $channels[] = 'email';
            }
            $notifManager->upsertRule(
                'logistics_asset',
                $k,
                (string) ($_POST['label_logistics_asset_' . $k] ?? ('logistics_asset_' . $k)),
                !empty($_POST['enabled_logistics_asset_' . $k]),
                'logistique',
                $channels,
                (string) ($_POST['title_tpl_logistics_asset_' . $k] ?? ''),
                (string) ($_POST['msg_tpl_logistics_asset_' . $k] ?? ''),
                postedExcludedEmails('excluded_logistics_asset_' . $k)
            );
        }
    } elseif ($saveSection === 'attendance_device_alert') {
        foreach (['api_unreachable', 'offline', 'clock_drift', 'server_time_mismatch'] as $k) {
            $channels = [];
            if (!empty($_POST['ch_system_attendance_device_alert_' . $k])) {
                $channels[] = 'system';
            }
            if (!empty($_POST['ch_email_attendance_device_alert_' . $k])) {
                $channels[] = 'email';
            }
            $notifManager->upsertRule(
                'attendance_device_alert',
                $k,
                (string) ($_POST['label_attendance_device_alert_' . $k] ?? ('attendance_device_alert_' . $k)),
                !empty($_POST['enabled_attendance_device_alert_' . $k]),
                (string) ($_POST['recipient_attendance_device_alert_' . $k] ?? 'rh'),
                $channels,
                (string) ($_POST['title_tpl_attendance_device_alert_' . $k] ?? ''),
                (string) ($_POST['msg_tpl_attendance_device_alert_' . $k] ?? ''),
                postedExcludedEmails('excluded_attendance_device_alert_' . $k)
            );
        }
    } elseif ($saveSection === 'legal_request_processed') {
        foreach ($saveHandlers as $k) {
            $enabled = !empty($_POST['enabled_proc_' . $k]);
            $label = (string) ($_POST['label_proc_' . $k] ?? ($k === 'approved' ? 'Demande approuvée' : 'Demande refusée'));
            $channels = [];
            if (!empty($_POST['ch_system_proc_' . $k])) {
                $channels[] = 'system';
            }
            if (!empty($_POST['ch_email_proc_' . $k])) {
                $channels[] = 'email';
            }
            $notifManager->upsertRule('legal_request_processed', $k, $label, $enabled, 'requester', $channels, (string) ($_POST['title_tpl_proc_' . $k] ?? ''), (string) ($_POST['msg_tpl_proc_' . $k] ?? ''));
        }
    } elseif ($saveSection === 'decaissement_submitted') {
        foreach (['default', 'epi'] as $k) {
            $enabled = !empty($_POST['enabled_dec_sub_' . $k]);
            $recipient = (string) ($_POST['recipient_dec_sub_' . $k] ?? ($k === 'epi' ? 'hse' : 'logistique'));
            $label = (string) ($_POST['label_dec_sub_' . $k] ?? ($k === 'epi' ? 'EPI : nouvelle fiche' : 'Nouvelle fiche de décaissement'));
            $channels = [];
            if (!empty($_POST['ch_system_dec_sub_' . $k])) {
                $channels[] = 'system';
            }
            if (!empty($_POST['ch_email_dec_sub_' . $k])) {
                $channels[] = 'email';
            }
            $notifManager->upsertRule('decaissement_submitted', $k, $label, $enabled, $recipient, $channels, (string) ($_POST['title_tpl_dec_sub_' . $k] ?? ''), (string) ($_POST['msg_tpl_dec_sub_' . $k] ?? ''));
        }
    } elseif ($saveSection === 'decaissement_processed') {
        foreach ($saveHandlers as $k) {
            $enabled = !empty($_POST['enabled_dec_proc_' . $k]);
            $label = (string) ($_POST['label_dec_proc_' . $k] ?? ($k === 'approved' ? 'Fiche approuvée' : 'Fiche refusée'));
            $channels = [];
            if (!empty($_POST['ch_system_dec_proc_' . $k])) {
                $channels[] = 'system';
            }
            if (!empty($_POST['ch_email_dec_proc_' . $k])) {
                $channels[] = 'email';
            }
            $notifManager->upsertRule('decaissement_processed', $k, $label, $enabled, 'requester', $channels, (string) ($_POST['title_tpl_dec_proc_' . $k] ?? ''), (string) ($_POST['msg_tpl_dec_proc_' . $k] ?? ''));
        }
    } elseif ($saveSection === 'schedule_occurrence_created') {
        $k = 'recurrence';
        $enabled = !empty($_POST['enabled_schedule_' . $k]);
        $recipient = (string) ($_POST['recipient_schedule_' . $k] ?? 'requester');
        $label = (string) ($_POST['label_schedule_' . $k] ?? 'Planning : tâche récurrente créée');
        $channels = [];
        if (!empty($_POST['ch_system_schedule_' . $k])) {
            $channels[] = 'system';
        }
        if (!empty($_POST['ch_email_schedule_' . $k])) {
            $channels[] = 'email';
        }
        $notifManager->upsertRule('schedule_occurrence_created', $k, $label, $enabled, $recipient, $channels, (string) ($_POST['title_tpl_schedule_' . $k] ?? ''), (string) ($_POST['msg_tpl_schedule_' . $k] ?? ''), postedExcludedEmails('excluded_schedule_' . $k));
    } elseif ($saveSection === 'schedule_task_notifications') {
        foreach (['assigned', 'group_updated', 'group_cancelled', 'restored'] as $k) {
            $eventKey = $k === 'assigned' ? 'schedule_task_assigned' : 'schedule_task_changed';
            $enabled = !empty($_POST['enabled_schedule_task_' . $k]);
            $recipient = (string) ($_POST['recipient_schedule_task_' . $k] ?? 'requester');
            $label = (string) ($_POST['label_schedule_task_' . $k] ?? 'Planning');
            $channels = [];
            if (!empty($_POST['ch_system_schedule_task_' . $k])) {
                $channels[] = 'system';
            }
            if (!empty($_POST['ch_email_schedule_task_' . $k])) {
                $channels[] = 'email';
            }
            $notifManager->upsertRule($eventKey, $k, $label, $enabled, $recipient, $channels, (string) ($_POST['title_tpl_schedule_task_' . $k] ?? ''), (string) ($_POST['msg_tpl_schedule_task_' . $k] ?? ''), postedExcludedEmails('excluded_schedule_task_' . $k));
        }
    } elseif ($saveSection === 'communication_task_notifications') {
        foreach (['group_created', 'group_updated', 'group_cancelled'] as $k) {
            $enabled = !empty($_POST['enabled_communication_task_' . $k]);
            $recipient = (string) ($_POST['recipient_communication_task_' . $k] ?? 'requester');
            $label = (string) ($_POST['label_communication_task_' . $k] ?? 'Communication');
            $channels = [];
            if (!empty($_POST['ch_system_communication_task_' . $k])) {
                $channels[] = 'system';
            }
            if (!empty($_POST['ch_email_communication_task_' . $k])) {
                $channels[] = 'email';
            }
            $eventKey = $k === 'group_created' ? 'communication_task_assigned' : 'communication_task_changed';
            $notifManager->upsertRule($eventKey, $k, $label, $enabled, $recipient, $channels, (string) ($_POST['title_tpl_communication_task_' . $k] ?? ''), (string) ($_POST['msg_tpl_communication_task_' . $k] ?? ''), postedExcludedEmails('excluded_communication_task_' . $k));
        }
    } elseif ($saveSection === 'communication_push_recipients') {
        $notifManager->saveCommunicationPushRecipients((array) ($_POST['communication_push_user_ids'] ?? []));
    } elseif ($saveSection === 'crm_rdv_created') {
        $k = 'created';
        $enabled = !empty($_POST['enabled_crm_rdv_created_' . $k]);
        $recipient = (string) ($_POST['recipient_crm_rdv_created_' . $k] ?? 'requester');
        $label = (string) ($_POST['label_crm_rdv_created_' . $k] ?? 'CRM : RDV créé');
        $channels = [];
        if (!empty($_POST['ch_system_crm_rdv_created_' . $k])) {
            $channels[] = 'system';
        }
        if (!empty($_POST['ch_email_crm_rdv_created_' . $k])) {
            $channels[] = 'email';
        }
        $notifManager->upsertRule('crm_rdv_created', $k, $label, $enabled, $recipient, $channels, (string) ($_POST['title_tpl_crm_rdv_created_' . $k] ?? ''), (string) ($_POST['msg_tpl_crm_rdv_created_' . $k] ?? ''), postedExcludedEmails('excluded_crm_rdv_created_' . $k));
    } elseif ($saveSection === 'crm_rdv_reminder') {
        foreach (['24h', '1h'] as $k) {
            $enabled = !empty($_POST['enabled_crm_rdv_reminder_' . $k]);
            $recipient = (string) ($_POST['recipient_crm_rdv_reminder_' . $k] ?? 'requester');
            $label = (string) ($_POST['label_crm_rdv_reminder_' . $k] ?? 'CRM : rappel RDV');
            $channels = [];
            if (!empty($_POST['ch_system_crm_rdv_reminder_' . $k])) {
                $channels[] = 'system';
            }
            if (!empty($_POST['ch_email_crm_rdv_reminder_' . $k])) {
                $channels[] = 'email';
            }
            $notifManager->upsertRule('crm_rdv_reminder', $k, $label, $enabled, $recipient, $channels, (string) ($_POST['title_tpl_crm_rdv_reminder_' . $k] ?? ''), (string) ($_POST['msg_tpl_crm_rdv_reminder_' . $k] ?? ''), postedExcludedEmails('excluded_crm_rdv_reminder_' . $k));
        }
    } elseif ($saveSection === 'crm_opportunity_next_action_reminder') {
        foreach (['24h', '1h'] as $k) {
            $enabled = !empty($_POST['enabled_crm_opp_next_action_' . $k]);
            $recipient = (string) ($_POST['recipient_crm_opp_next_action_' . $k] ?? 'requester');
            $label = (string) ($_POST['label_crm_opp_next_action_' . $k] ?? 'CRM : rappel action opportunité');
            $channels = [];
            if (!empty($_POST['ch_system_crm_opp_next_action_' . $k])) {
                $channels[] = 'system';
            }
            if (!empty($_POST['ch_email_crm_opp_next_action_' . $k])) {
                $channels[] = 'email';
            }
            $notifManager->upsertRule('crm_opportunity_next_action_reminder', $k, $label, $enabled, $recipient, $channels, (string) ($_POST['title_tpl_crm_opp_next_action_' . $k] ?? ''), (string) ($_POST['msg_tpl_crm_opp_next_action_' . $k] ?? ''), postedExcludedEmails('excluded_crm_opp_next_action_' . $k));
        }
    } elseif ($saveSection === 'crm_prospect_inactive') {
        $k = 'alert';
        $enabled = !empty($_POST['enabled_crm_prospect_inactive_' . $k]);
        $recipient = (string) ($_POST['recipient_crm_prospect_inactive_' . $k] ?? 'requester');
        $label = (string) ($_POST['label_crm_prospect_inactive_' . $k] ?? 'CRM : prospect inactif');
        $channels = [];
        if (!empty($_POST['ch_system_crm_prospect_inactive_' . $k])) {
            $channels[] = 'system';
        }
        if (!empty($_POST['ch_email_crm_prospect_inactive_' . $k])) {
            $channels[] = 'email';
        }
        $notifManager->upsertRule('crm_prospect_inactive', $k, $label, $enabled, $recipient, $channels, (string) ($_POST['title_tpl_crm_prospect_inactive_' . $k] ?? ''), (string) ($_POST['msg_tpl_crm_prospect_inactive_' . $k] ?? ''), postedExcludedEmails('excluded_crm_prospect_inactive_' . $k));
    } elseif ($saveSection === 'broken_debourse_links_detected') {
        $k = 'alert';
        $enabled = !empty($_POST['enabled_broken_debourse_' . $k]);
        $recipient = (string) ($_POST['recipient_broken_debourse_' . $k] ?? 'finance');
        $label = (string) ($_POST['label_broken_debourse_' . $k] ?? 'Décaissement : liens cassés détectés');
        $channels = [];
        if (!empty($_POST['ch_system_broken_debourse_' . $k])) {
            $channels[] = 'system';
        }
        if (!empty($_POST['ch_email_broken_debourse_' . $k])) {
            $channels[] = 'email';
        }
        $notifManager->upsertRule('broken_debourse_links_detected', $k, $label, $enabled, $recipient, $channels, (string) ($_POST['title_tpl_broken_debourse_' . $k] ?? ''), (string) ($_POST['msg_tpl_broken_debourse_' . $k] ?? ''), postedExcludedEmails('excluded_broken_debourse_' . $k));
    } elseif ($saveSection === 'rh_hierarchy_assigned') {
        foreach (['direct_manager', 'department_head'] as $k) {
            $enabled = !empty($_POST['enabled_rh_hierarchy_' . $k]);
            $recipient = (string) ($_POST['recipient_rh_hierarchy_' . $k] ?? 'requester');
            $label = (string) ($_POST['label_rh_hierarchy_' . $k] ?? ($k === 'department_head' ? 'RH : nomination directeur de département' : 'RH : nomination responsable'));
            $channels = [];
            if (!empty($_POST['ch_system_rh_hierarchy_' . $k])) {
                $channels[] = 'system';
            }
            if (!empty($_POST['ch_email_rh_hierarchy_' . $k])) {
                $channels[] = 'email';
            }
            $notifManager->upsertRule('rh_hierarchy_assigned', $k, $label, $enabled, $recipient, $channels, (string) ($_POST['title_tpl_rh_hierarchy_' . $k] ?? ''), (string) ($_POST['msg_tpl_rh_hierarchy_' . $k] ?? ''), postedExcludedEmails('excluded_rh_hierarchy_' . $k));
        }
    }

    \AuditTrail::log('notification_settings_updated', [
        'module' => 'admin',
        'action' => 'notifications_settings',
        'entity_type' => 'notification_settings',
        'entity_label' => $saveSection,
        'result' => 'success',
    ], $pdo);

    $flash = 'Paramètres enregistrés.';
    } catch (\Throwable $e) {
        $flash = 'Erreur lors de l enregistrement : ' . $e->getMessage();
        $flashType = 'error';
        \AuditTrail::log('notification_settings_update_failed', [
            'module' => 'admin',
            'action' => 'notifications_settings',
            'entity_type' => 'notification_settings',
            'entity_label' => $saveSection,
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['error' => $e->getMessage()],
        ], $pdo);
    }
}

function checkedAttr(bool $checked): string
{
    return $checked ? 'checked' : '';
}

function selectedAttr(?string $currentValue, string $expectedValue): string
{
    return (string) $currentValue === $expectedValue ? 'selected' : '';
}

function postedExcludedEmails(string $fieldName): array
{
    $raw = (string) ($_POST[$fieldName] ?? '');
    if (trim($raw) === '') {
        return [];
    }

    $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
    return array_values(array_filter(array_map(static function (string $value): string {
        return trim($value);
    }, $parts), static function (string $value): bool {
        return $value !== '';
    }));
}

function excludedEmailsText(array $rule): string
{
    $emails = array_values(array_filter(array_map('strval', (array) ($rule['excluded_emails'] ?? [])), static function (string $value): bool {
        return trim($value) !== '';
    }));

    return implode("\n", $emails);
}

function defaultTitleTemplate(string $eventKey, string $subKey, array $def = []): string
{
    $eventKey = strtolower(trim($eventKey));
    $subKey = strtolower(trim($subKey));

    return match ($eventKey) {
        'legal_request_submitted' => 'Nouvelle demande : ' . (string) ($def['label'] ?? $subKey),
        'legal_request_processed' => $subKey === 'rejected' ? 'Votre demande a été refusée' : 'Votre demande a été approuvée',
        'decaissement_submitted' => $subKey === 'epi' ? 'Nouvelle fiche EPI soumise' : 'Nouvelle fiche de décaissement',
        'decaissement_processed' => $subKey === 'rejected' ? 'Votre fiche a été refusée' : 'Votre fiche a été approuvée',
        'schedule_occurrence_created' => 'Nouvelle occurrence de planning',
        'schedule_task_assigned' => 'Nouvelle tâche planifiée',
        'schedule_task_changed' => $subKey === 'group_cancelled' ? 'Tâche planifiée annulée' : ($subKey === 'restored' ? 'Tâche planifiée restaurée' : 'Tâche planifiée modifiée'),
        'communication_task_assigned' => 'Nouvelle tâche communication créée',
        'communication_task_changed' => $subKey === 'group_cancelled' ? 'Tâche communication annulée' : 'Tâche communication modifiée',
        'crm_rdv_created' => 'Nouveau rendez-vous CRM',
        'crm_rdv_reminder' => $subKey === '1h' ? 'Rappel RDV dans 1h' : 'Rappel RDV dans 24h',
        'crm_opportunity_next_action_reminder' => $subKey === '1h' ? 'Rappel action dans 1h' : 'Rappel action dans 24h',
        'crm_prospect_inactive' => 'Alerte prospect inactif',
        'broken_debourse_links_detected' => 'Alerte liens de déboursés',
        'rh_hierarchy_assigned' => 'Décision de nomination : ' . ($subKey === 'department_head' ? 'Directeur du département' : 'Responsable'),
        'supplier_workflow' => match ($subKey) {
            'submitted' => 'Fournisseur à valider',
            'approved' => 'Fournisseur validé',
            'rejected' => 'Fournisseur rejeté',
            default => 'Workflow fournisseur',
        },
        'logistics_request' => match ($subKey) {
            'submitted' => 'Nouvelle demande logistique',
            'approved' => 'Demande logistique accordee',
            'rejected' => 'Demande logistique refusee',
            'details_requested' => 'Details demandes',
            'handover' => 'Element logistique remis',
            'recovered' => 'Element logistique recupere',
            default => 'Demande logistique',
        },
        'logistics_asset' => 'Action logistique : {{entity_label}}',
        'attendance_device_alert' => match ($subKey) {
            'api_unreachable' => 'Alerte badgeuse : connexion impossible',
            'offline' => 'Badgeuse deconnectee : {{device_label}}',
            'clock_drift' => 'Heure badgeuse a verifier : {{device_label}}',
            'server_time_mismatch' => 'Alerte heure serveur pointage',
            default => 'Alerte badgeuse',
        },
        default => 'Notification',
    };
}

function defaultMessageTemplate(string $eventKey, string $subKey, array $def = []): string
{
    $eventKey = strtolower(trim($eventKey));
    $subKey = strtolower(trim($subKey));

    if ($eventKey === 'legal_request_submitted') {
        return '{{nom_expediteur}} a soumis une demande ({{type_label}}) le {{date}}.';
    }
    if ($eventKey === 'legal_request_processed') {
        return $subKey === 'rejected'
            ? 'Votre demande "{{type_label}}" a été {{statut_label}}. Motif: {{motif}}'
            : 'Votre demande "{{type_label}}" a été {{statut_label}}.';
    }
    if ($eventKey === 'decaissement_submitted') {
        return $subKey === 'epi'
            ? '{{nom_expediteur}} a soumis une fiche EPI ({{num_fiche}}) — {{montant}} FCFA.'
            : '{{nom_expediteur}} a soumis une fiche ({{num_fiche}}) — {{montant}} FCFA.';
    }
    if ($eventKey === 'decaissement_processed') {
        return $subKey === 'rejected'
            ? 'Votre fiche ({{num_fiche}}) a été {{statut_label}}. Motif: {{motif}}'
            : 'Votre fiche ({{num_fiche}}) a été {{statut_label}}.';
    }
    if ($eventKey === 'schedule_occurrence_created') {
        return 'Une nouvelle occurrence a été ajoutée à votre planning le {{date_event}} ({{start}} → {{end}}).';
    }
    if ($eventKey === 'schedule_task_assigned') {
        return 'Une tâche vous a été affectée le {{date_event}} ({{start}} → {{end}}). Portée : {{scope_label}}.';
    }
    if ($eventKey === 'communication_task_assigned') {
        return 'Nouvelle série de tâches communication : {{titre}}';
    }
    if ($eventKey === 'communication_task_assigned') {
        return '{{actor_name}} a créé une série de tâches communication. Échéance : {{deadline}}. Portée : {{scope_label}}.';
    }
    if ($eventKey === 'schedule_task_changed') {
        if ($subKey === 'group_cancelled') {
            return '{{actor_name}} a annulé une tâche groupée prévue le {{date_event}} ({{start}} → {{end}}). Portée : {{scope_label}}.';
        }
        if ($subKey === 'restored') {
            return '{{actor_name}} a restauré une tâche prévue le {{date_event}} ({{start}} → {{end}}). Portée : {{scope_label}}.';
        }
        return '{{actor_name}} a modifié une tâche groupée prévue le {{date_event}} ({{start}} → {{end}}). Portée : {{scope_label}}.';
    }
    if ($eventKey === 'communication_task_changed') {
        return $subKey === 'group_cancelled'
            ? '{{actor_name}} a annulé une série de tâches communication. Échéance : {{deadline}}. Portée : {{scope_label}}.'
            : '{{actor_name}} a modifié une série de tâches communication. Échéance : {{deadline}}. Portée : {{scope_label}}.';
    }
    if ($eventKey === 'crm_rdv_created') {
        return 'Un RDV a été créé le {{date_debut}} ({{start}} → {{end}}) {{linked_label}}.';
    }
    if ($eventKey === 'crm_rdv_reminder') {
        return $subKey === '24h'
            ? 'Rappel : votre RDV est prévu le {{date_debut}} ({{start}} → {{end}}) {{linked_label}}.'
            : 'Rappel : votre RDV commence dans ~1h ({{start}}) {{linked_label}}.';
    }
    if ($eventKey === 'crm_opportunity_next_action_reminder') {
        return $subKey === '24h'
            ? 'Rappel : une action est prévue le {{date_action}} à {{start}} {{linked_label}}.'
            : 'Rappel : votre action commence dans ~1h ({{start}}) {{linked_label}}.';
    }
    if ($eventKey === 'crm_prospect_inactive') {
        return 'Aucun suivi depuis {{days_inactive}} jours : {{prospect_nom}} {{societe_label}}. Dernière activité : {{last_activity}}.';
    }
    if ($eventKey === 'broken_debourse_links_detected') {
        return '{{broken_count}} fiche(s) présentent un lien déboursé invalide {{chantier_label}}. Ouvrir l’outil de reliaison pour correction.';
    }
    if ($eventKey === 'rh_hierarchy_assigned') {
        return 'Par la présente, nous vous informons de votre nomination aux fonctions de "{{role_label}}" du département {{department_label}}.';
    }
    if ($eventKey === 'supplier_workflow') {
        return match ($subKey) {
            'submitted' => '{{actor_name}} a soumis le fournisseur {{supplier_name}} pour validation.',
            'approved' => 'Votre fournisseur {{supplier_name}} a été validé.',
            'rejected' => 'Votre fournisseur {{supplier_name}} a été rejeté. Motif : {{validation_note}}',
            default => '{{supplier_name}}',
        };
    }

    if ($eventKey === 'logistics_request') {
        return match ($subKey) {
            'submitted' => '{{requester_name}} demande {{asset_label}} pour {{need_type}}.',
            'approved' => 'Votre demande {{request_code}} a ete accordee pour {{asset_label}}.',
            'rejected' => 'Votre demande {{request_code}} a ete refusee.',
            'details_requested' => 'La logistique demande des details supplementaires pour {{request_code}}.',
            'handover' => '{{asset_label}} vous a ete remis pour la demande {{request_code}}.',
            'recovered' => '{{asset_label}} a ete recupere et remis disponible. Retour : {{return_location}}.',
            default => '{{request_code}}',
        };
    }
    if ($eventKey === 'logistics_asset') {
        return '{{actor_name}} a enregistre {{entity_label}} dans la logistique.';
    }
    if ($eventKey === 'attendance_device_alert') {
        return match ($subKey) {
            'api_unreachable' => 'FINEA ne parvient pas a joindre attendance.fidest.ci. Detail : {{detail}}',
            'offline' => '{{device_label}} ne communique plus depuis {{last_seen_label}}. Seuil : {{threshold_label}}.',
            'clock_drift' => 'Lecture directe horloge : ecart observe avec GMT+0 {{drift_label}}. Heure badgeuse : {{device_time}}.',
            'server_time_mismatch' => 'Ecart detecte entre PHP GMT+0 et MySQL UTC : {{drift_label}}. PHP : {{php_gmt}}. MySQL : {{mysql_utc}}.',
            default => '{{device_label}}',
        };
    }

    return '';
}

$teamOptions = [
    'rh' => 'RH',
    'finance' => 'Finances',
    'logistique' => 'Logistique',
    'hse' => 'HSE',
    'admin' => 'Administration',
    'all' => 'Tous',
];

$recipientOptionsWithRequester = array_merge(['requester' => 'Demandeur'], $teamOptions);
$recipientOptionsHierarchy = array_merge(['requester' => 'Utilisateur nommé'], $teamOptions);

$rules = $notifManager->listRulesForEvent('legal_request_submitted');
$rulesBySub = [];
foreach ($rules as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesBySub[$sk] = $r;
    }
}

$rulesProcessed = $notifManager->listRulesForEvent('legal_request_processed');
$rulesProcessedBySub = [];
foreach ($rulesProcessed as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesProcessedBySub[$sk] = $r;
    }
}

$rulesDecSubmitted = $notifManager->listRulesForEvent('decaissement_submitted');
$rulesDecSubmittedBySub = [];
foreach ($rulesDecSubmitted as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesDecSubmittedBySub[$sk] = $r;
    }
}

$rulesDecProcessed = $notifManager->listRulesForEvent('decaissement_processed');
$rulesDecProcessedBySub = [];
foreach ($rulesDecProcessed as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesDecProcessedBySub[$sk] = $r;
    }
}

$rulesSchedule = $notifManager->listRulesForEvent('schedule_occurrence_created');
$rulesScheduleBySub = [];
foreach ($rulesSchedule as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesScheduleBySub[$sk] = $r;
    }
}

$rulesScheduleAssigned = $notifManager->listRulesForEvent('schedule_task_assigned');
$rulesScheduleAssignedBySub = [];
foreach ($rulesScheduleAssigned as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesScheduleAssignedBySub[$sk] = $r;
    }
}

$rulesScheduleChanged = $notifManager->listRulesForEvent('schedule_task_changed');
$rulesScheduleChangedBySub = [];
foreach ($rulesScheduleChanged as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesScheduleChangedBySub[$sk] = $r;
    }
}

$rulesCommunicationTaskAssigned = $notifManager->listRulesForEvent('communication_task_assigned');
$rulesCommunicationTaskAssignedBySub = [];
foreach ($rulesCommunicationTaskAssigned as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesCommunicationTaskAssignedBySub[$sk] = $r;
    }
}

$rulesCommunicationTaskChanged = $notifManager->listRulesForEvent('communication_task_changed');
$rulesCommunicationTaskChangedBySub = [];
foreach ($rulesCommunicationTaskChanged as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesCommunicationTaskChangedBySub[$sk] = $r;
    }
}

$communicationPushRecipients = $notifManager->listCommunicationPushRecipients();
$communicationPushSelectedCount = count(array_filter($communicationPushRecipients, static function (array $row): bool {
    return !empty($row['is_selected']);
}));

$rulesCrmRdvCreated = $notifManager->listRulesForEvent('crm_rdv_created');
$rulesCrmRdvCreatedBySub = [];
foreach ($rulesCrmRdvCreated as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesCrmRdvCreatedBySub[$sk] = $r;
    }
}

$rulesCrmRdvReminder = $notifManager->listRulesForEvent('crm_rdv_reminder');
$rulesCrmRdvReminderBySub = [];
foreach ($rulesCrmRdvReminder as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesCrmRdvReminderBySub[$sk] = $r;
    }
}

$rulesCrmOppNextActionReminder = $notifManager->listRulesForEvent('crm_opportunity_next_action_reminder');
$rulesCrmOppNextActionReminderBySub = [];
foreach ($rulesCrmOppNextActionReminder as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesCrmOppNextActionReminderBySub[$sk] = $r;
    }
}

$rulesCrmProspectInactive = $notifManager->listRulesForEvent('crm_prospect_inactive');
$rulesCrmProspectInactiveBySub = [];
foreach ($rulesCrmProspectInactive as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesCrmProspectInactiveBySub[$sk] = $r;
    }
}

$rulesBrokenDebourse = $notifManager->listRulesForEvent('broken_debourse_links_detected');
$rulesBrokenDebourseBySub = [];
foreach ($rulesBrokenDebourse as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesBrokenDebourseBySub[$sk] = $r;
    }
}

$rulesRhHierarchy = $notifManager->listRulesForEvent('rh_hierarchy_assigned');
$rulesRhHierarchyBySub = [];
foreach ($rulesRhHierarchy as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesRhHierarchyBySub[$sk] = $r;
    }
}

$rulesSupplierWorkflow = $notifManager->listRulesForEvent('supplier_workflow');
$rulesSupplierWorkflowBySub = [];
foreach ($rulesSupplierWorkflow as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesSupplierWorkflowBySub[$sk] = $r;
    }
}

$rulesLogisticsRequest = $notifManager->listRulesForEvent('logistics_request');
$rulesLogisticsRequestBySub = [];
foreach ($rulesLogisticsRequest as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesLogisticsRequestBySub[$sk] = $r;
    }
}

$rulesLogisticsAsset = $notifManager->listRulesForEvent('logistics_asset');
$rulesLogisticsAssetBySub = [];
foreach ($rulesLogisticsAsset as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesLogisticsAssetBySub[$sk] = $r;
    }
}

$rulesAttendanceDeviceAlert = $notifManager->listRulesForEvent('attendance_device_alert');
$rulesAttendanceDeviceAlertBySub = [];
foreach ($rulesAttendanceDeviceAlert as $r) {
    $sk = (string) ($r['sub_key'] ?? '');
    if ($sk !== '') {
        $rulesAttendanceDeviceAlertBySub[$sk] = $r;
    }
}

$notificationSoundSettings = $soundSettingsManager->get();
?>

<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-purple-50">
    <div class="container mx-auto py-10 px-4">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">Paramètres notifications</h1>
                <p class="text-gray-600 mt-1">Configurer les notifications système et email.</p>
            </div>
            <div class="flex gap-3">
                <a href="dashboard_admin.php" class="px-4 py-2 bg-white border border-gray-200 rounded-xl text-gray-700 hover:shadow">Retour admin</a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="mb-6 rounded-xl border p-4 <?= $flashType === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-green-200 bg-green-50 text-green-800' ?>">
                <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="mb-8 rounded-2xl border border-gray-100 bg-white p-6 shadow-xl">
            <div class="mb-4 flex flex-col gap-2">
                <h2 class="text-xl font-bold text-gray-800">Son des notifications systeme</h2>
                <p class="text-sm text-gray-600">Choisissez un fichier audio joue lors de l arrivee d une nouvelle notification systeme. Si aucun son personnalise n est actif, Finea garde le bip discret par defaut.</p>
            </div>

            <form method="post" enctype="multipart/form-data" class="space-y-5">
                <input type="hidden" name="save_section" value="notification_sound" />

                <div class="grid gap-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(280px,0.8fr)]">
                    <div class="space-y-4">
                        <label class="inline-flex items-center gap-3">
                            <input type="checkbox" name="sound_enabled" value="1" class="h-4 w-4" <?= checkedAttr(!empty($notificationSoundSettings['is_enabled'])) ?>>
                            <span class="text-sm font-medium text-gray-800">Activer un son personnalise pour les notifications</span>
                        </label>

                        <div>
                            <label for="notification_sound_file" class="mb-2 block text-sm font-semibold text-gray-700">Fichier audio</label>
                            <input id="notification_sound_file" type="file" name="notification_sound_file" accept=".mp3,.wav,.ogg,.m4a,.aac,.webm,audio/*" class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-3 text-sm text-gray-700 file:mr-4 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100">
                            <p class="mt-2 text-xs text-gray-500">Formats acceptes : mp3, wav, ogg, m4a, aac, webm.</p>
                        </div>

                        <label class="inline-flex items-center gap-3">
                            <input type="checkbox" name="remove_notification_sound" value="1" class="h-4 w-4">
                            <span class="text-sm text-gray-700">Supprimer le fichier actuel et revenir au son par defaut</span>
                        </label>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Configuration active</div>
                        <div class="mt-4 space-y-3 text-sm text-slate-700">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-slate-500">Statut</span>
                                <span class="rounded-full px-3 py-1 text-xs font-semibold <?= !empty($notificationSoundSettings['is_enabled']) ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700' ?>">
                                    <?= !empty($notificationSoundSettings['is_enabled']) ? 'Actif' : 'Par defaut' ?>
                                </span>
                            </div>
                            <div>
                                <div class="text-slate-500">Fichier</div>
                                <div class="mt-1 font-medium text-slate-900"><?= htmlspecialchars($notificationSoundSettings['original_name'] !== '' ? $notificationSoundSettings['original_name'] : 'Aucun fichier charge', ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <?php if (!empty($notificationSoundSettings['sound_path'])): ?>
                                <div>
                                    <div class="text-slate-500">Chemin stocke</div>
                                    <div class="mt-1 break-all text-xs text-slate-700"><?= htmlspecialchars((string) $notificationSoundSettings['sound_path'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($notificationSoundSettings['sound_path'])): ?>
                                <audio controls preload="none" class="mt-2 w-full">
                                    <source src="<?= htmlspecialchars('../' . ltrim((string) $notificationSoundSettings['sound_path'], '/'), ENT_QUOTES, 'UTF-8') ?>" type="<?= htmlspecialchars((string) ($notificationSoundSettings['mime_type'] ?: 'audio/mpeg'), ENT_QUOTES, 'UTF-8') ?>">
                                </audio>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="submit" class="rounded-xl bg-indigo-600 px-6 py-3 text-white shadow hover:bg-indigo-700">Enregistrer</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mb-8">
            <div class="flex flex-col gap-2 mb-4">
                <h2 class="text-xl font-bold text-gray-800">Évènement : Workflow fournisseur</h2>
                <p class="text-sm text-gray-600">Soumission : diffusion métier vers finance, administration et direction avec possibilité d'exclure des adresses. Validation et rejet : notification du demandeur.</p>
            </div>

            <form method="post" class="space-y-4">
                <input type="hidden" name="save_section" value="supplier_workflow" />
                <div class="overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="py-3 pr-4">Évènement</th>
                                <th class="py-3 pr-4">Envoie notification</th>
                                <th class="py-3 pr-4">Diffusion</th>
                                <th class="py-3 pr-4">Canaux</th>
                                <th class="py-3 pr-4">Libellé</th>
                                <th class="py-3 pr-4">Titre (template)</th>
                                <th class="py-3 pr-4">Message (template)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (['submitted' => 'Soumission pour validation', 'approved' => 'Fournisseur validé', 'rejected' => 'Fournisseur rejeté'] as $subKey => $rowLabel): ?>
                                <?php
                                $rule = $rulesSupplierWorkflowBySub[$subKey] ?? [];
                                $isEnabled = (bool) ($rule['is_enabled'] ?? true);
                                $channels = (array) ($rule['channels'] ?? ['system', 'email']);
                                $label = (string) ($rule['label'] ?? defaultTitleTemplate('supplier_workflow', $subKey, []));
                                $titleTpl = (string) ($rule['title_template'] ?? defaultTitleTemplate('supplier_workflow', $subKey, []));
                                $msgTpl = (string) ($rule['message_template'] ?? defaultMessageTemplate('supplier_workflow', $subKey, []));
                                $excludedEmails = excludedEmailsText((array) $rule);
                                $diffusionLabel = $subKey === 'submitted' ? 'Finance, administration, direction' : 'Demandeur';
                                ?>
                                <tr class="border-b last:border-b-0 align-top">
                                    <td class="py-3 pr-4 font-medium text-gray-800"><?= htmlspecialchars($rowLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="py-3 pr-4">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="enabled_supplier_workflow_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr($isEnabled) ?> class="w-4 h-4">
                                            <span class="text-gray-700">Activer</span>
                                        </label>
                                    </td>
                                    <td class="py-3 pr-4 min-w-[240px]">
                                        <div class="text-sm font-medium text-gray-800"><?= htmlspecialchars($diffusionLabel, ENT_QUOTES, 'UTF-8') ?></div>
                                        <textarea name="excluded_supplier_workflow_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" rows="2" placeholder="email1@exemple.com&#10;email2@exemple.com" class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"><?= htmlspecialchars($excludedEmails, ENT_QUOTES, 'UTF-8') ?></textarea>
                                        <p class="mt-1 text-xs text-gray-500">Un email par ligne, ou séparés par virgule.</p>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <div class="flex items-center gap-4">
                                            <label class="inline-flex items-center gap-2">
                                                <input type="checkbox" name="ch_system_supplier_workflow_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('system', $channels, true)) ?> class="w-4 h-4">
                                                <span class="text-gray-700">Système</span>
                                            </label>
                                            <label class="inline-flex items-center gap-2">
                                                <input type="checkbox" name="ch_email_supplier_workflow_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('email', $channels, true)) ?> class="w-4 h-4">
                                                <span class="text-gray-700">Email</span>
                                            </label>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input type="text" name="label_supplier_workflow_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[220px] px-3 py-2 border rounded-lg" />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input type="text" name="title_tpl_supplier_workflow_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($titleTpl, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[220px] px-3 py-2 border rounded-lg" />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <textarea name="msg_tpl_supplier_workflow_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" rows="2" class="w-full min-w-[280px] px-3 py-2 border rounded-lg"><?= htmlspecialchars($msgTpl, ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-end gap-3 pt-4">
                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 shadow">Enregistrer</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mb-8">
            <div class="flex flex-col gap-2 mb-4">
                <h2 class="text-xl font-bold text-gray-800">Evenement : Demandes logistiques</h2>
                <p class="text-sm text-gray-600">Validation, remise et recuperation des elements attribues.</p>
            </div>
            <form method="post" class="space-y-4">
                <input type="hidden" name="save_section" value="logistics_request" />
                <div class="overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr class="text-left text-gray-600 border-b"><th class="py-3 pr-4">Evenement</th><th class="py-3 pr-4">Actif</th><th class="py-3 pr-4">Destinataire</th><th class="py-3 pr-4">Canaux</th><th class="py-3 pr-4">Libelle</th><th class="py-3 pr-4">Titre</th><th class="py-3 pr-4">Message</th></tr></thead>
                        <tbody>
                            <?php foreach (['submitted' => 'Nouvelle demande', 'approved' => 'Accordee', 'rejected' => 'Refusee', 'details_requested' => 'Details demandes', 'handover' => 'Element remis', 'recovered' => 'Element recupere'] as $subKey => $rowLabel): ?>
                                <?php $rule = $rulesLogisticsRequestBySub[$subKey] ?? []; $channels = (array) ($rule['channels'] ?? ['system']); $isEnabled = (bool) ($rule['is_enabled'] ?? true); $label = (string) ($rule['label'] ?? defaultTitleTemplate('logistics_request', $subKey)); $titleTpl = (string) ($rule['title_template'] ?? defaultTitleTemplate('logistics_request', $subKey)); $msgTpl = (string) ($rule['message_template'] ?? defaultMessageTemplate('logistics_request', $subKey)); ?>
                                <tr class="border-b last:border-b-0 align-top">
                                    <td class="py-3 pr-4 font-medium text-gray-800"><?= htmlspecialchars($rowLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="py-3 pr-4"><input type="checkbox" name="enabled_logistics_request_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr($isEnabled) ?> class="w-4 h-4"></td>
                                    <td class="py-3 pr-4 text-gray-700"><?= $subKey === 'submitted' ? 'Logistique' : 'Demandeur' ?><textarea name="excluded_logistics_request_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" rows="1" placeholder="emails exclus" class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-xs"><?= htmlspecialchars(excludedEmailsText((array) $rule), ENT_QUOTES, 'UTF-8') ?></textarea></td>
                                    <td class="py-3 pr-4"><label class="mr-3"><input type="checkbox" name="ch_system_logistics_request_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('system', $channels, true)) ?>> Systeme</label><label><input type="checkbox" name="ch_email_logistics_request_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('email', $channels, true)) ?>> Email</label></td>
                                    <td class="py-3 pr-4"><input type="text" name="label_logistics_request_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[190px] px-3 py-2 border rounded-lg"></td>
                                    <td class="py-3 pr-4"><input type="text" name="title_tpl_logistics_request_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($titleTpl, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[220px] px-3 py-2 border rounded-lg"></td>
                                    <td class="py-3 pr-4"><textarea name="msg_tpl_logistics_request_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" rows="2" class="w-full min-w-[280px] px-3 py-2 border rounded-lg"><?= htmlspecialchars($msgTpl, ENT_QUOTES, 'UTF-8') ?></textarea></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end pt-4"><button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 shadow">Enregistrer</button></div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mb-8">
            <div class="flex flex-col gap-2 mb-4">
                <h2 class="text-xl font-bold text-gray-800">Evenement : Actions logistiques</h2>
                <p class="text-sm text-gray-600">Creation parc, chauffeurs, stock, documents, incidents, maintenance et fournisseurs.</p>
            </div>
            <form method="post" class="space-y-4">
                <input type="hidden" name="save_section" value="logistics_asset" />
                <div class="overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr class="text-left text-gray-600 border-b"><th class="py-3 pr-4">Evenement</th><th class="py-3 pr-4">Actif</th><th class="py-3 pr-4">Canaux</th><th class="py-3 pr-4">Libelle</th><th class="py-3 pr-4">Titre</th><th class="py-3 pr-4">Message</th></tr></thead>
                        <tbody>
                            <?php foreach (['asset_created' => 'Parc', 'driver_created' => 'Chauffeur', 'stock_item_created' => 'Article stock', 'stock_movement_created' => 'Mouvement stock', 'maintenance_created' => 'Maintenance', 'incident_created' => 'Incident', 'document_created' => 'Document', 'supplier_created' => 'Fournisseur'] as $subKey => $rowLabel): ?>
                                <?php $rule = $rulesLogisticsAssetBySub[$subKey] ?? []; $channels = (array) ($rule['channels'] ?? ['system']); $isEnabled = (bool) ($rule['is_enabled'] ?? true); $label = (string) ($rule['label'] ?? $rowLabel); $titleTpl = (string) ($rule['title_template'] ?? defaultTitleTemplate('logistics_asset', $subKey)); $msgTpl = (string) ($rule['message_template'] ?? defaultMessageTemplate('logistics_asset', $subKey)); ?>
                                <tr class="border-b last:border-b-0 align-top">
                                    <td class="py-3 pr-4 font-medium text-gray-800"><?= htmlspecialchars($rowLabel, ENT_QUOTES, 'UTF-8') ?><textarea name="excluded_logistics_asset_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" rows="1" placeholder="emails exclus" class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-xs"><?= htmlspecialchars(excludedEmailsText((array) $rule), ENT_QUOTES, 'UTF-8') ?></textarea></td>
                                    <td class="py-3 pr-4"><input type="checkbox" name="enabled_logistics_asset_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr($isEnabled) ?> class="w-4 h-4"></td>
                                    <td class="py-3 pr-4"><label class="mr-3"><input type="checkbox" name="ch_system_logistics_asset_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('system', $channels, true)) ?>> Systeme</label><label><input type="checkbox" name="ch_email_logistics_asset_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('email', $channels, true)) ?>> Email</label></td>
                                    <td class="py-3 pr-4"><input type="text" name="label_logistics_asset_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[190px] px-3 py-2 border rounded-lg"></td>
                                    <td class="py-3 pr-4"><input type="text" name="title_tpl_logistics_asset_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($titleTpl, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[220px] px-3 py-2 border rounded-lg"></td>
                                    <td class="py-3 pr-4"><textarea name="msg_tpl_logistics_asset_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" rows="2" class="w-full min-w-[280px] px-3 py-2 border rounded-lg"><?= htmlspecialchars($msgTpl, ENT_QUOTES, 'UTF-8') ?></textarea></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end pt-4"><button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 shadow">Enregistrer</button></div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mb-8">
            <div class="flex flex-col gap-2 mb-4">
                <h2 class="text-xl font-bold text-gray-800">Evenement : Supervision badgeuse</h2>
                <p class="text-sm text-gray-600">Alerte email et systeme quand attendance.fidest.ci est inaccessible, quand une badgeuse est deconnectee ou quand l'heure serveur/base du pointage est incoherente. La derive d'horloge badgeuse reste reservee a une future lecture directe fiable.</p>
            </div>
            <form method="post" class="space-y-4">
                <input type="hidden" name="save_section" value="attendance_device_alert" />
                <div class="overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr class="text-left text-gray-600 border-b"><th class="py-3 pr-4">Evenement</th><th class="py-3 pr-4">Actif</th><th class="py-3 pr-4">Destinataire</th><th class="py-3 pr-4">Canaux</th><th class="py-3 pr-4">Libelle</th><th class="py-3 pr-4">Titre</th><th class="py-3 pr-4">Message</th></tr></thead>
                        <tbody>
                            <?php foreach (['api_unreachable' => 'API inaccessible', 'offline' => 'Badgeuse deconnectee', 'clock_drift' => 'Lecture directe horloge badgeuse', 'server_time_mismatch' => 'Decalage serveur / base'] as $subKey => $rowLabel): ?>
                                <?php $rule = $rulesAttendanceDeviceAlertBySub[$subKey] ?? []; $channels = (array) ($rule['channels'] ?? ['system', 'email']); $isEnabled = (bool) ($rule['is_enabled'] ?? true); $label = (string) ($rule['label'] ?? $rowLabel); $titleTpl = (string) ($rule['title_template'] ?? defaultTitleTemplate('attendance_device_alert', $subKey)); $msgTpl = (string) ($rule['message_template'] ?? defaultMessageTemplate('attendance_device_alert', $subKey)); $recipient = (string) ($rule['recipient_team'] ?? 'rh'); ?>
                                <tr class="border-b last:border-b-0 align-top">
                                    <td class="py-3 pr-4 font-medium text-gray-800"><?= htmlspecialchars($rowLabel, ENT_QUOTES, 'UTF-8') ?><textarea name="excluded_attendance_device_alert_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" rows="1" placeholder="emails exclus" class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-xs"><?= htmlspecialchars(excludedEmailsText((array) $rule), ENT_QUOTES, 'UTF-8') ?></textarea></td>
                                    <td class="py-3 pr-4"><input type="checkbox" name="enabled_attendance_device_alert_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr($isEnabled) ?> class="w-4 h-4"></td>
                                    <td class="py-3 pr-4">
                                        <select name="recipient_attendance_device_alert_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" class="min-w-[150px] rounded-lg border border-gray-300 px-3 py-2">
                                            <?php foreach ($teamOptions as $value => $labelTeam): ?>
                                                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $recipient === $value ? 'selected' : '' ?>><?= htmlspecialchars($labelTeam, ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="py-3 pr-4"><label class="mr-3"><input type="checkbox" name="ch_system_attendance_device_alert_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('system', $channels, true)) ?>> Systeme</label><label><input type="checkbox" name="ch_email_attendance_device_alert_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('email', $channels, true)) ?>> Email</label></td>
                                    <td class="py-3 pr-4"><input type="text" name="label_attendance_device_alert_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[190px] px-3 py-2 border rounded-lg"></td>
                                    <td class="py-3 pr-4"><input type="text" name="title_tpl_attendance_device_alert_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($titleTpl, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[220px] px-3 py-2 border rounded-lg"></td>
                                    <td class="py-3 pr-4"><textarea name="msg_tpl_attendance_device_alert_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" rows="2" class="w-full min-w-[280px] px-3 py-2 border rounded-lg"><?= htmlspecialchars($msgTpl, ENT_QUOTES, 'UTF-8') ?></textarea></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end pt-4"><button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 shadow">Enregistrer</button></div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Évènement : Soumission d'une demande employé</h2>

            <form method="post" class="space-y-4">
                <input type="hidden" name="save_section" value="legal_request_submitted" />
                <div class="overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="py-3 pr-4">Évènement</th>
                                <th class="py-3 pr-4">Envoie notification</th>
                                <th class="py-3 pr-4">Destinataire</th>
                                <th class="py-3 pr-4">Canaux</th>
                                <th class="py-3 pr-4">Libellé</th>
                                <th class="py-3 pr-4">Titre (template)</th>
                                <th class="py-3 pr-4">Message (template)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requestTypes as $type => $def): ?>
                                <?php
                                $type = strtolower(trim((string) $type));
                                $labelType = (string) ($def['label'] ?? $type);
                                $defaultTeam = (string) ($def['team'] ?? 'rh');

                                $rule = $rulesBySub[$type] ?? null;
                                $isEnabled = $rule ? (bool) ($rule['is_enabled'] ?? true) : true;
                                $recipientTeam = $rule ? (string) ($rule['recipient_team'] ?? $defaultTeam) : $defaultTeam;
                                $channels = $rule ? (array) ($rule['channels'] ?? ['system']) : ['system'];
                                $label = $rule ? (string) ($rule['label'] ?? ('Demande de ' . $labelType)) : ('Demande de ' . $labelType);
                                $titleTpl = $rule ? (string) ($rule['title_template'] ?? '') : '';
                                $msgTpl = $rule ? (string) ($rule['message_template'] ?? '') : '';
                                if (trim($titleTpl) === '') {
                                    $titleTpl = defaultTitleTemplate('legal_request_submitted', $type, $def);
                                }
                                if (trim($msgTpl) === '') {
                                    $msgTpl = defaultMessageTemplate('legal_request_submitted', $type, $def);
                                }
                                ?>
                                <tr class="border-b last:border-b-0">
                                    <td class="py-3 pr-4 font-medium text-gray-800">
                                        <?= htmlspecialchars($labelType, ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="enabled_<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr($isEnabled) ?> class="w-4 h-4">
                                            <span class="text-gray-700">Activer</span>
                                        </label>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <select name="recipient_<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" class="px-3 py-2 border rounded-lg bg-white">
                                            <?php foreach ($teamOptions as $val => $txtOpt): ?>
                                                <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= selectedAttr($recipientTeam, $val) ?>>
                                                    <?= htmlspecialchars($txtOpt, ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <textarea name="excluded_<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" rows="2" placeholder="Emails exclus" class="mt-2 w-full min-w-[220px] px-3 py-2 border rounded-lg text-sm"><?= htmlspecialchars(excludedEmailsText((array) $rule), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <div class="flex items-center gap-4">
                                            <label class="inline-flex items-center gap-2">
                                                <input type="checkbox" name="ch_system_<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('system', $channels, true)) ?> class="w-4 h-4">
                                                <span class="text-gray-700">Système</span>
                                            </label>
                                            <label class="inline-flex items-center gap-2">
                                                <input type="checkbox" name="ch_email_<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('email', $channels, true)) ?> class="w-4 h-4">
                                                <span class="text-gray-700">Email</span>
                                            </label>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input type="text" name="label_<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[240px] px-3 py-2 border rounded-lg" data-preview-label />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input type="text" name="title_tpl_<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($titleTpl, ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex: Nouvelle demande {{type_label}}" class="w-full min-w-[220px] px-3 py-2 border rounded-lg" data-preview-title />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <textarea name="msg_tpl_<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" rows="2" placeholder="Ex: {{nom_expediteur}} a soumis une demande {{type_label}}" class="w-full min-w-[280px] px-3 py-2 border rounded-lg" data-preview-message><?= htmlspecialchars($msgTpl, ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-end gap-3 pt-4">
                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 shadow">Enregistrer</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Évènement : Traitement d'une fiche de décaissement (Approuvée / Refusée)</h2>
            <form method="post" class="space-y-4">
                <input type="hidden" name="save_section" value="decaissement_processed" />
                <div class="overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="py-3 pr-4">Décision</th>
                                <th class="py-3 pr-4">Envoie notification</th>
                                <th class="py-3 pr-4">Canaux</th>
                                <th class="py-3 pr-4">Libellé</th>
                                <th class="py-3 pr-4">Titre (template)</th>
                                <th class="py-3 pr-4">Message (template)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (['approved' => 'Approuvée', 'rejected' => 'Refusée'] as $k => $txt): ?>
                                <?php
                                $rule = $rulesDecProcessedBySub[$k] ?? null;
                                $isEnabled = $rule ? (bool) ($rule['is_enabled'] ?? true) : true;
                                $channels = $rule ? (array) ($rule['channels'] ?? ['system']) : ['system'];
                                $label = $rule ? (string) ($rule['label'] ?? ($k === 'approved' ? 'Fiche approuvée' : 'Fiche refusée')) : ($k === 'approved' ? 'Fiche approuvée' : 'Fiche refusée');
                                $titleTpl = $rule ? (string) ($rule['title_template'] ?? '') : '';
                                $msgTpl = $rule ? (string) ($rule['message_template'] ?? '') : '';
                                if (trim($titleTpl) === '') {
                                    $titleTpl = defaultTitleTemplate('decaissement_processed', $k, []);
                                }
                                if (trim($msgTpl) === '') {
                                    $msgTpl = defaultMessageTemplate('decaissement_processed', $k, []);
                                }
                                ?>
                                <tr class="border-b last:border-b-0">
                                    <td class="py-3 pr-4 font-medium text-gray-800"><?= htmlspecialchars($txt, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="py-3 pr-4">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="enabled_dec_proc_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr($isEnabled) ?> class="w-4 h-4">
                                            <span class="text-gray-700">Activer</span>
                                        </label>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <div class="flex items-center gap-4">
                                            <label class="inline-flex items-center gap-2">
                                                <input type="checkbox" name="ch_system_dec_proc_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('system', $channels, true)) ?> class="w-4 h-4">
                                                <span class="text-gray-700">Système</span>
                                            </label>
                                            <label class="inline-flex items-center gap-2">
                                                <input type="checkbox" name="ch_email_dec_proc_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('email', $channels, true)) ?> class="w-4 h-4">
                                                <span class="text-gray-700">Email</span>
                                            </label>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input type="text" name="label_dec_proc_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[240px] px-3 py-2 border rounded-lg" />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input type="text" name="title_tpl_dec_proc_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($titleTpl, ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex: Fiche {{num_fiche}}" class="w-full min-w-[220px] px-3 py-2 border rounded-lg" />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <textarea name="msg_tpl_dec_proc_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" placeholder="Ex: Votre fiche {{num_fiche}} a été {{statut}}" class="w-full min-w-[280px] px-3 py-2 border rounded-lg"><?= htmlspecialchars($msgTpl, ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-end gap-3 pt-4">
                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 shadow">Enregistrer</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Évènement : Planning — création d'une occurrence récurrente</h2>
            <form method="post" class="space-y-4">
                <input type="hidden" name="save_section" value="schedule_occurrence_created" />
                <div class="overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="py-3 pr-4">Type</th>
                                <th class="py-3 pr-4">Envoie notification</th>
                                <th class="py-3 pr-4">Destinataire</th>
                                <th class="py-3 pr-4">Canaux</th>
                                <th class="py-3 pr-4">Libellé</th>
                                <th class="py-3 pr-4">Titre (template)</th>
                                <th class="py-3 pr-4">Message (template)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $k = 'recurrence';
                            $rule = $rulesScheduleBySub[$k] ?? null;
                            $isEnabled = $rule ? (bool) ($rule['is_enabled'] ?? true) : true;
                            $recipientTeam = $rule ? (string) ($rule['recipient_team'] ?? 'requester') : 'requester';
                            $channels = $rule ? (array) ($rule['channels'] ?? ['system', 'email']) : ['system', 'email'];
                            $label = $rule ? (string) ($rule['label'] ?? 'Planning : tâche récurrente créée') : 'Planning : tâche récurrente créée';
                            $titleTpl = $rule ? (string) ($rule['title_template'] ?? '') : '';
                            $msgTpl = $rule ? (string) ($rule['message_template'] ?? '') : '';
                            if (trim($titleTpl) === '') {
                                $titleTpl = defaultTitleTemplate('schedule_occurrence_created', $k, []);
                            }
                            if (trim($msgTpl) === '') {
                                $msgTpl = defaultMessageTemplate('schedule_occurrence_created', $k, []);
                            }
                            ?>
                            <tr class="border-b last:border-b-0">
                                <td class="py-3 pr-4 font-medium text-gray-800">Récurrence</td>
                                <td class="py-3 pr-4">
                                    <label class="inline-flex items-center gap-2">
                                        <input type="checkbox" name="enabled_schedule_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr($isEnabled) ?> class="w-4 h-4">
                                        <span class="text-gray-700">Activer</span>
                                    </label>
                                </td>
                                <td class="py-3 pr-4">
                                    <select name="recipient_schedule_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" class="px-3 py-2 border rounded-lg bg-white">
                                        <?php foreach ($recipientOptionsWithRequester as $val => $txtOpt): ?>
                                            <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= selectedAttr($recipientTeam, $val) ?>>
                                                <?= htmlspecialchars($txtOpt, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <textarea name="excluded_schedule_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" placeholder="Emails exclus" class="mt-2 w-full min-w-[220px] px-3 py-2 border rounded-lg text-sm"><?= htmlspecialchars(excludedEmailsText((array) $rule), ENT_QUOTES, 'UTF-8') ?></textarea>
                                </td>
                                <td class="py-3 pr-4">
                                    <div class="flex items-center gap-4">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="ch_system_schedule_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('system', $channels, true)) ?> class="w-4 h-4">
                                            <span class="text-gray-700">Système</span>
                                        </label>
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="ch_email_schedule_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('email', $channels, true)) ?> class="w-4 h-4">
                                            <span class="text-gray-700">Email</span>
                                        </label>
                                    </div>
                                </td>
                                <td class="py-3 pr-4">
                                    <input type="text" name="label_schedule_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[240px] px-3 py-2 border rounded-lg" />
                                </td>
                                <td class="py-3 pr-4">
                                    <input type="text" name="title_tpl_schedule_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($titleTpl, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[220px] px-3 py-2 border rounded-lg" />
                                </td>
                                <td class="py-3 pr-4">
                                    <textarea name="msg_tpl_schedule_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" class="w-full min-w-[280px] px-3 py-2 border rounded-lg"><?= htmlspecialchars($msgTpl, ENT_QUOTES, 'UTF-8') ?></textarea>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-end gap-3 pt-4">
                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 shadow">Enregistrer</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-2">Centre de préférences planning</h2>
            <p class="text-sm text-gray-600 mb-4">Pilote séparément l'affectation, la modification groupée, l'annulation groupée et la restauration d'une tâche planning.</p>
            <form method="post" class="space-y-4" id="schedule-task-notifications-form">
                <input type="hidden" name="save_section" value="schedule_task_notifications" />
                <div class="overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="py-3 pr-4">Type</th>
                                <th class="py-3 pr-4">Envoie notification</th>
                                <th class="py-3 pr-4">Destinataire</th>
                                <th class="py-3 pr-4">Canaux</th>
                                <th class="py-3 pr-4">Libellé</th>
                                <th class="py-3 pr-4">Titre (template)</th>
                                <th class="py-3 pr-4">Message (template)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (
                                [
                                    'assigned' => ['label' => 'Affectation', 'event' => 'schedule_task_assigned', 'rules' => $rulesScheduleAssignedBySub],
                                    'group_updated' => ['label' => 'Modification groupée', 'event' => 'schedule_task_changed', 'rules' => $rulesScheduleChangedBySub],
                                    'group_cancelled' => ['label' => 'Annulation groupée', 'event' => 'schedule_task_changed', 'rules' => $rulesScheduleChangedBySub],
                                    'restored' => ['label' => 'Restauration', 'event' => 'schedule_task_changed', 'rules' => $rulesScheduleChangedBySub],
                                ] as $k => $meta
                            ): ?>
                                <?php
                                $rule = $meta['rules'][$k] ?? null;
                                $isEnabled = $rule ? (bool) ($rule['is_enabled'] ?? true) : true;
                                $recipientTeam = $rule ? (string) ($rule['recipient_team'] ?? 'requester') : 'requester';
                                $channels = $rule ? (array) ($rule['channels'] ?? ['system', 'email']) : ['system', 'email'];
                                $defaultLabel = $meta['label'];
                                if ($k === 'assigned') {
                                    $defaultLabel = 'Planning : tâche affectée';
                                } elseif ($k === 'group_updated') {
                                    $defaultLabel = 'Planning : groupe modifié';
                                } elseif ($k === 'group_cancelled') {
                                    $defaultLabel = 'Planning : groupe annulé';
                                } elseif ($k === 'restored') {
                                    $defaultLabel = 'Planning : tâche restaurée';
                                }
                                $label = $rule ? (string) ($rule['label'] ?? $defaultLabel) : $defaultLabel;
                                $titleTpl = $rule ? (string) ($rule['title_template'] ?? '') : '';
                                $msgTpl = $rule ? (string) ($rule['message_template'] ?? '') : '';
                                if (trim($titleTpl) === '') {
                                    $titleTpl = defaultTitleTemplate((string) $meta['event'], $k, []);
                                }
                                if (trim($msgTpl) === '') {
                                    $msgTpl = defaultMessageTemplate((string) $meta['event'], $k, []);
                                }
                                ?>
                                <tr class="border-b last:border-b-0">
                                    <td class="py-3 pr-4 font-medium text-gray-800"><?= htmlspecialchars((string) $meta['label'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="py-3 pr-4">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="enabled_schedule_task_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr($isEnabled) ?> class="w-4 h-4">
                                            <span class="text-gray-700">Activer</span>
                                        </label>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <select name="recipient_schedule_task_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" class="px-3 py-2 border rounded-lg bg-white">
                                            <?php foreach ($recipientOptionsWithRequester as $val => $txtOpt): ?>
                                                <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= selectedAttr($recipientTeam, $val) ?>>
                                                    <?= htmlspecialchars($txtOpt, ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <textarea name="excluded_schedule_task_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" placeholder="Emails exclus" class="mt-2 w-full min-w-[220px] px-3 py-2 border rounded-lg text-sm"><?= htmlspecialchars(excludedEmailsText((array) $rule), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <div class="flex items-center gap-4">
                                            <label class="inline-flex items-center gap-2">
                                                <input type="checkbox" name="ch_system_schedule_task_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('system', $channels, true)) ?> class="w-4 h-4">
                                                <span class="text-gray-700">Système</span>
                                            </label>
                                            <label class="inline-flex items-center gap-2">
                                                <input type="checkbox" name="ch_email_schedule_task_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('email', $channels, true)) ?> class="w-4 h-4">
                                                <span class="text-gray-700">Email</span>
                                            </label>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input type="text" name="label_schedule_task_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[240px] px-3 py-2 border rounded-lg" data-preview-label />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input type="text" name="title_tpl_schedule_task_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($titleTpl, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[220px] px-3 py-2 border rounded-lg" data-preview-title />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <textarea name="msg_tpl_schedule_task_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" class="w-full min-w-[280px] px-3 py-2 border rounded-lg" data-preview-message><?= htmlspecialchars($msgTpl, ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5" id="schedule-task-preview-card">
                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <div>
                            <div class="text-sm font-semibold text-slate-900">Aperçu temps réel du template email</div>
                            <div class="text-xs text-slate-500 mt-1">Le rendu se met à jour dès que vous modifiez le titre ou le message de la ligne planning active.</div>
                        </div>
                        <div id="schedule-task-preview-label" class="px-3 py-1 rounded-full bg-white border text-xs font-semibold text-slate-700">Planning</div>
                    </div>
                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">
                        <div class="px-4 py-3 border-b border-slate-200 bg-slate-900 text-white">
                            <div class="text-[11px] uppercase tracking-wide text-slate-300">Objet</div>
                            <div id="schedule-task-preview-subject" class="mt-1 text-sm font-semibold">Nouvelle tâche : Réunion sécurité</div>
                        </div>
                        <div class="px-4 py-4">
                            <div class="text-[11px] uppercase tracking-wide text-slate-500">Corps</div>
                            <div id="schedule-task-preview-body" class="mt-2 text-sm leading-6 text-slate-700"></div>
                        </div>
                    </div>
                    <div class="mt-3 text-xs text-slate-500">Exemple injecté : titre, acteur, date, plage horaire, portée et collaborateur.</div>
                </div>
                <div class="flex items-center justify-end gap-3 pt-4">
                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 shadow">Enregistrer</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-2">Centre de préférences communication</h2>
            <p class="text-sm text-gray-600 mb-4">Pilote les notifications liées à la création groupée, aux modifications groupées et aux annulations groupées des tâches du module communication.</p>
            <form method="post" class="space-y-4" id="communication-task-notifications-form">
                <input type="hidden" name="save_section" value="communication_task_notifications" />
                <div class="overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="py-3 pr-4">Type</th>
                                <th class="py-3 pr-4">Envoie notification</th>
                                <th class="py-3 pr-4">Destinataire</th>
                                <th class="py-3 pr-4">Canaux</th>
                                <th class="py-3 pr-4">Libellé</th>
                                <th class="py-3 pr-4">Titre (template)</th>
                                <th class="py-3 pr-4">Message (template)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (
                                [
                                    'group_created' => ['label' => 'Création groupée', 'event' => 'communication_task_assigned', 'rules' => $rulesCommunicationTaskAssignedBySub],
                                    'group_updated' => ['label' => 'Modification groupée'],
                                    'group_cancelled' => ['label' => 'Annulation groupée'],
                                ] as $k => $meta
                            ): ?>
                                <?php
                                $ruleSet = (array) ($meta['rules'] ?? $rulesCommunicationTaskChangedBySub);
                                $rule = $ruleSet[$k] ?? null;
                                $isEnabled = $rule ? (bool) ($rule['is_enabled'] ?? true) : true;
                                $recipientTeam = $rule ? (string) ($rule['recipient_team'] ?? 'requester') : 'requester';
                                $channels = $rule ? (array) ($rule['channels'] ?? ['system', 'email']) : ['system', 'email'];
                                $defaultLabel = $k === 'group_created'
                                    ? 'Communication : groupe créé'
                                    : ($k === 'group_cancelled' ? 'Communication : groupe annulé' : 'Communication : groupe modifié');
                                $label = $rule ? (string) ($rule['label'] ?? $defaultLabel) : $defaultLabel;
                                $titleTpl = $rule ? (string) ($rule['title_template'] ?? '') : '';
                                $msgTpl = $rule ? (string) ($rule['message_template'] ?? '') : '';
                                if (trim($titleTpl) === '') {
                                    $titleTpl = defaultTitleTemplate($k === 'group_created' ? 'communication_task_assigned' : 'communication_task_changed', $k, []);
                                }
                                if (trim($msgTpl) === '') {
                                    $msgTpl = defaultMessageTemplate($k === 'group_created' ? 'communication_task_assigned' : 'communication_task_changed', $k, []);
                                }
                                ?>
                                <tr class="border-b last:border-b-0">
                                    <td class="py-3 pr-4 font-medium text-gray-800"><?= htmlspecialchars((string) $meta['label'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="py-3 pr-4">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="enabled_communication_task_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr($isEnabled) ?> class="w-4 h-4">
                                            <span class="text-gray-700">Activer</span>
                                        </label>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <select name="recipient_communication_task_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" class="px-3 py-2 border rounded-lg bg-white">
                                            <?php foreach ($recipientOptionsWithRequester as $val => $txtOpt): ?>
                                                <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= selectedAttr($recipientTeam, $val) ?>>
                                                    <?= htmlspecialchars($txtOpt, ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <textarea name="excluded_communication_task_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" placeholder="Emails exclus" class="mt-2 w-full min-w-[220px] px-3 py-2 border rounded-lg text-sm"><?= htmlspecialchars(excludedEmailsText((array) $rule), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <div class="flex items-center gap-4">
                                            <label class="inline-flex items-center gap-2">
                                                <input type="checkbox" name="ch_system_communication_task_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('system', $channels, true)) ?> class="w-4 h-4">
                                                <span class="text-gray-700">Système</span>
                                            </label>
                                            <label class="inline-flex items-center gap-2">
                                                <input type="checkbox" name="ch_email_communication_task_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('email', $channels, true)) ?> class="w-4 h-4">
                                                <span class="text-gray-700">Email</span>
                                            </label>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input type="text" name="label_communication_task_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[240px] px-3 py-2 border rounded-lg" data-preview-label />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input type="text" name="title_tpl_communication_task_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($titleTpl, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[220px] px-3 py-2 border rounded-lg" data-preview-title />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <textarea name="msg_tpl_communication_task_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" class="w-full min-w-[280px] px-3 py-2 border rounded-lg" data-preview-message><?= htmlspecialchars($msgTpl, ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-end gap-3 pt-4">
                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 shadow">Enregistrer</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mt-8">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div>
                    <h2 class="text-xl font-bold text-gray-800 mb-2">Destinataires push du module communication</h2>
                    <p class="text-sm text-gray-600">Cette liste ajoute des destinataires système supplémentaires pour les tâches du module communication. Les collaborateurs directement affectés continuent d'être notifiés selon les règles ci-dessus.</p>
                </div>
                <div class="inline-flex items-center rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-800">
                    <?= (int) $communicationPushSelectedCount ?> sélectionné(s) / <?= count($communicationPushRecipients) ?> actif(s)
                </div>
            </div>

            <form method="post" class="space-y-4 mt-6">
                <input type="hidden" name="save_section" value="communication_push_recipients" />
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex-1">
                        <label for="communicationPushSearch" class="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Recherche rapide</label>
                        <input id="communicationPushSearch" type="text" placeholder="Nom, email, service, statut..." class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700" />
                    </div>
                    <div class="flex flex-wrap items-center gap-4 pt-1 lg:pt-6">
                        <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                            <input id="communicationPushSelectedOnly" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                            <span>Afficher seulement les sélectionnés</span>
                        </label>
                        <div id="communicationPushVisibleCount" class="rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700">
                            <?= count($communicationPushRecipients) ?> ligne(s) visibles
                        </div>
                    </div>
                </div>
                <div class="overflow-auto rounded-2xl border border-slate-200">
                    <table id="communicationPushTable" class="min-w-full text-sm bg-white">
                        <thead class="bg-slate-50 text-left text-gray-600 border-b border-slate-200">
                            <tr>
                                <th class="py-3 px-4">Push</th>
                                <th class="py-3 px-4">Collaborateur</th>
                                <th class="py-3 px-4">Email</th>
                                <th class="py-3 px-4">Service</th>
                                <th class="py-3 px-4">Statut RH</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($communicationPushRecipients as $recipient): ?>
                                <?php
                                $searchBlob = strtolower(trim(implode(' ', [
                                    (string) ($recipient['display'] ?? ''),
                                    (string) ($recipient['mail_pro'] ?? ''),
                                    (string) ($recipient['service_label'] ?? ''),
                                    (string) ($recipient['status_label'] ?? ''),
                                ])));
                                ?>
                                <tr class="border-b last:border-b-0 border-slate-100" data-push-row="1" data-selected="<?= !empty($recipient['is_selected']) ? '1' : '0' ?>" data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') ?>">
                                    <td class="py-3 px-4 align-top">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="communication_push_user_ids[]" value="<?= (int) ($recipient['id'] ?? 0) ?>" <?= checkedAttr(!empty($recipient['is_selected'])) ?> class="w-4 h-4">
                                            <span class="text-gray-700">Activer</span>
                                        </label>
                                    </td>
                                    <td class="py-3 px-4 align-top font-medium text-gray-800">
                                        <?= htmlspecialchars((string) ($recipient['display'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="py-3 px-4 align-top text-gray-600">
                                        <?= htmlspecialchars((string) ($recipient['mail_pro'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="py-3 px-4 align-top text-gray-600">
                                        <?= htmlspecialchars((string) ($recipient['service_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="py-3 px-4 align-top text-gray-600">
                                        <?= htmlspecialchars((string) ($recipient['status_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 shadow">Enregistrer la liste push</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Évènement : CRM — RDV créé</h2>
            <form method="post" class="space-y-4">
                <input type="hidden" name="save_section" value="crm_rdv_created" />
                <div class="overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="py-3 pr-4">Type</th>
                                <th class="py-3 pr-4">Envoie notification</th>
                                <th class="py-3 pr-4">Destinataire</th>
                                <th class="py-3 pr-4">Canaux</th>
                                <th class="py-3 pr-4">Libellé</th>
                                <th class="py-3 pr-4">Titre (template)</th>
                                <th class="py-3 pr-4">Message (template)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $k = 'created';
                            $rule = $rulesCrmRdvCreatedBySub[$k] ?? null;
                            $isEnabled = $rule ? (bool) ($rule['is_enabled'] ?? true) : true;
                            $recipientTeam = $rule ? (string) ($rule['recipient_team'] ?? 'requester') : 'requester';
                            $channels = $rule ? (array) ($rule['channels'] ?? ['system', 'email']) : ['system', 'email'];
                            $label = $rule ? (string) ($rule['label'] ?? 'CRM : RDV créé') : 'CRM : RDV créé';
                            $titleTpl = $rule ? (string) ($rule['title_template'] ?? '') : '';
                            $msgTpl = $rule ? (string) ($rule['message_template'] ?? '') : '';
                            if (trim($titleTpl) === '') {
                                $titleTpl = defaultTitleTemplate('crm_rdv_created', $k, []);
                            }
                            if (trim($msgTpl) === '') {
                                $msgTpl = defaultMessageTemplate('crm_rdv_created', $k, []);
                            }
                            ?>
                            <tr class="border-b last:border-b-0">
                                <td class="py-3 pr-4 font-medium text-gray-800">Création RDV</td>
                                <td class="py-3 pr-4">
                                    <label class="inline-flex items-center gap-2">
                                        <input type="checkbox" name="enabled_crm_rdv_created_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr($isEnabled) ?> class="w-4 h-4">
                                        <span class="text-gray-700">Activer</span>
                                    </label>
                                </td>
                                <td class="py-3 pr-4">
                                    <select name="recipient_crm_rdv_created_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" class="px-3 py-2 border rounded-lg bg-white">
                                        <?php foreach ($recipientOptionsWithRequester as $val => $txtOpt): ?>
                                            <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= selectedAttr($recipientTeam, $val) ?>>
                                                <?= htmlspecialchars($txtOpt, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <textarea name="excluded_crm_rdv_created_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" placeholder="Emails exclus" class="mt-2 w-full min-w-[220px] px-3 py-2 border rounded-lg text-sm"><?= htmlspecialchars(excludedEmailsText((array) $rule), ENT_QUOTES, 'UTF-8') ?></textarea>
                                </td>
                                <td class="py-3 pr-4">
                                    <div class="flex items-center gap-4">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="ch_system_crm_rdv_created_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('system', $channels, true)) ?> class="w-4 h-4">
                                            <span class="text-gray-700">Système</span>
                                        </label>
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="ch_email_crm_rdv_created_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('email', $channels, true)) ?> class="w-4 h-4">
                                            <span class="text-gray-700">Email</span>
                                        </label>
                                    </div>
                                </td>
                                <td class="py-3 pr-4">
                                    <input type="text" name="label_crm_rdv_created_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[240px] px-3 py-2 border rounded-lg" />
                                </td>
                                <td class="py-3 pr-4">
                                    <input type="text" name="title_tpl_crm_rdv_created_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($titleTpl, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[220px] px-3 py-2 border rounded-lg" />
                                </td>
                                <td class="py-3 pr-4">
                                    <textarea name="msg_tpl_crm_rdv_created_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" class="w-full min-w-[280px] px-3 py-2 border rounded-lg"><?= htmlspecialchars($msgTpl, ENT_QUOTES, 'UTF-8') ?></textarea>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-end gap-3 pt-4">
                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 shadow">Enregistrer</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Évènement : CRM — rappels RDV</h2>
            <form method="post" class="space-y-4">
                <input type="hidden" name="save_section" value="crm_rdv_reminder" />
                <div class="overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="py-3 pr-4">Type</th>
                                <th class="py-3 pr-4">Envoie notification</th>
                                <th class="py-3 pr-4">Destinataire</th>
                                <th class="py-3 pr-4">Canaux</th>
                                <th class="py-3 pr-4">Libellé</th>
                                <th class="py-3 pr-4">Titre (template)</th>
                                <th class="py-3 pr-4">Message (template)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (['24h' => 'Rappel 24h', '1h' => 'Rappel 1h'] as $k => $kLabel): ?>
                                <?php
                                $rule = $rulesCrmRdvReminderBySub[$k] ?? null;
                                $isEnabled = $rule ? (bool) ($rule['is_enabled'] ?? true) : true;
                                $recipientTeam = $rule ? (string) ($rule['recipient_team'] ?? 'requester') : 'requester';
                                $channels = $rule ? (array) ($rule['channels'] ?? ['system', 'email']) : ['system', 'email'];
                                $defaultLabel = $k === '24h' ? 'CRM : rappel RDV (24h)' : 'CRM : rappel RDV (1h)';
                                $label = $rule ? (string) ($rule['label'] ?? $defaultLabel) : $defaultLabel;
                                $titleTpl = $rule ? (string) ($rule['title_template'] ?? '') : '';
                                $msgTpl = $rule ? (string) ($rule['message_template'] ?? '') : '';
                                if (trim($titleTpl) === '') {
                                    $titleTpl = defaultTitleTemplate('crm_rdv_reminder', $k, []);
                                }
                                if (trim($msgTpl) === '') {
                                    $msgTpl = defaultMessageTemplate('crm_rdv_reminder', $k, []);
                                }
                                ?>
                                <tr class="border-b last:border-b-0">
                                    <td class="py-3 pr-4 font-medium text-gray-800"><?= htmlspecialchars((string)$kLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="py-3 pr-4">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="enabled_crm_rdv_reminder_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr($isEnabled) ?> class="w-4 h-4">
                                            <span class="text-gray-700">Activer</span>
                                        </label>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <select name="recipient_crm_rdv_reminder_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" class="px-3 py-2 border rounded-lg bg-white">
                                            <?php foreach ($recipientOptionsWithRequester as $val => $txtOpt): ?>
                                                <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= selectedAttr($recipientTeam, $val) ?>>
                                                    <?= htmlspecialchars($txtOpt, ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <textarea name="excluded_crm_rdv_reminder_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" placeholder="Emails exclus" class="mt-2 w-full min-w-[220px] px-3 py-2 border rounded-lg text-sm"><?= htmlspecialchars(excludedEmailsText((array) $rule), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <div class="flex items-center gap-4">
                                            <label class="inline-flex items-center gap-2">
                                                <input type="checkbox" name="ch_system_crm_rdv_reminder_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('system', $channels, true)) ?> class="w-4 h-4">
                                                <span class="text-gray-700">Système</span>
                                            </label>
                                            <label class="inline-flex items-center gap-2">
                                                <input type="checkbox" name="ch_email_crm_rdv_reminder_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('email', $channels, true)) ?> class="w-4 h-4">
                                                <span class="text-gray-700">Email</span>
                                            </label>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input type="text" name="label_crm_rdv_reminder_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[240px] px-3 py-2 border rounded-lg" />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input type="text" name="title_tpl_crm_rdv_reminder_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($titleTpl, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[220px] px-3 py-2 border rounded-lg" />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <textarea name="msg_tpl_crm_rdv_reminder_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" class="w-full min-w-[280px] px-3 py-2 border rounded-lg"><?= htmlspecialchars($msgTpl, ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-end gap-3 pt-4">
                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 shadow">Enregistrer</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Évènement : CRM — rappels “prochaine action” (opportunités)</h2>
            <form method="post" class="space-y-4">
                <input type="hidden" name="save_section" value="crm_opportunity_next_action_reminder" />
                <div class="overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="py-3 pr-4">Type</th>
                                <th class="py-3 pr-4">Envoie notification</th>
                                <th class="py-3 pr-4">Destinataire</th>
                                <th class="py-3 pr-4">Canaux</th>
                                <th class="py-3 pr-4">Libellé</th>
                                <th class="py-3 pr-4">Titre (template)</th>
                                <th class="py-3 pr-4">Message (template)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (['24h' => 'Rappel 24h', '1h' => 'Rappel 1h'] as $k => $kLabel): ?>
                                <?php
                                $rule = $rulesCrmOppNextActionReminderBySub[$k] ?? null;
                                $isEnabled = $rule ? (bool) ($rule['is_enabled'] ?? true) : true;
                                $recipientTeam = $rule ? (string) ($rule['recipient_team'] ?? 'requester') : 'requester';
                                $channels = $rule ? (array) ($rule['channels'] ?? ['system', 'email']) : ['system', 'email'];
                                $defaultLabel = $k === '24h' ? 'CRM : rappel action opportunité (24h)' : 'CRM : rappel action opportunité (1h)';
                                $label = $rule ? (string) ($rule['label'] ?? $defaultLabel) : $defaultLabel;
                                $titleTpl = $rule ? (string) ($rule['title_template'] ?? '') : '';
                                $msgTpl = $rule ? (string) ($rule['message_template'] ?? '') : '';
                                if (trim($titleTpl) === '') {
                                    $titleTpl = defaultTitleTemplate('crm_opportunity_next_action_reminder', $k, []);
                                }
                                if (trim($msgTpl) === '') {
                                    $msgTpl = defaultMessageTemplate('crm_opportunity_next_action_reminder', $k, []);
                                }
                                ?>
                                <tr class="border-b last:border-b-0">
                                    <td class="py-3 pr-4 font-medium text-gray-800"><?= htmlspecialchars((string)$kLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="py-3 pr-4">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="enabled_crm_opp_next_action_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr($isEnabled) ?> class="w-4 h-4">
                                            <span class="text-gray-700">Activer</span>
                                        </label>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <select name="recipient_crm_opp_next_action_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" class="px-3 py-2 border rounded-lg bg-white">
                                            <?php foreach ($recipientOptionsWithRequester as $val => $txtOpt): ?>
                                                <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= selectedAttr($recipientTeam, $val) ?>>
                                                    <?= htmlspecialchars($txtOpt, ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <textarea name="excluded_crm_opp_next_action_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" placeholder="Emails exclus" class="mt-2 w-full min-w-[220px] px-3 py-2 border rounded-lg text-sm"><?= htmlspecialchars(excludedEmailsText((array) $rule), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <div class="flex items-center gap-4">
                                            <label class="inline-flex items-center gap-2">
                                                <input type="checkbox" name="ch_system_crm_opp_next_action_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('system', $channels, true)) ?> class="w-4 h-4">
                                                <span class="text-gray-700">Système</span>
                                            </label>
                                            <label class="inline-flex items-center gap-2">
                                                <input type="checkbox" name="ch_email_crm_opp_next_action_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('email', $channels, true)) ?> class="w-4 h-4">
                                                <span class="text-gray-700">Email</span>
                                            </label>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input type="text" name="label_crm_opp_next_action_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[240px] px-3 py-2 border rounded-lg" />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input type="text" name="title_tpl_crm_opp_next_action_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($titleTpl, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[220px] px-3 py-2 border rounded-lg" />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <textarea name="msg_tpl_crm_opp_next_action_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" class="w-full min-w-[280px] px-3 py-2 border rounded-lg"><?= htmlspecialchars($msgTpl, ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-end gap-3 pt-4">
                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 shadow">Enregistrer</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Évènement : CRM — prospect inactif</h2>
            <form method="post" class="space-y-4">
                <input type="hidden" name="save_section" value="crm_prospect_inactive" />
                <div class="overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="py-3 pr-4">Type</th>
                                <th class="py-3 pr-4">Envoie notification</th>
                                <th class="py-3 pr-4">Destinataire</th>
                                <th class="py-3 pr-4">Canaux</th>
                                <th class="py-3 pr-4">Libellé</th>
                                <th class="py-3 pr-4">Titre (template)</th>
                                <th class="py-3 pr-4">Message (template)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $k = 'alert';
                            $kLabel = 'Alerte';
                            $rule = $rulesCrmProspectInactiveBySub[$k] ?? null;
                            $isEnabled = $rule ? (bool) ($rule['is_enabled'] ?? true) : true;
                            $recipientTeam = $rule ? (string) ($rule['recipient_team'] ?? 'requester') : 'requester';
                            $channels = $rule ? (array) ($rule['channels'] ?? ['system', 'email']) : ['system', 'email'];
                            $label = $rule ? (string) ($rule['label'] ?? 'CRM : prospect inactif') : 'CRM : prospect inactif';
                            $titleTpl = $rule ? (string) ($rule['title_template'] ?? '') : '';
                            $msgTpl = $rule ? (string) ($rule['message_template'] ?? '') : '';
                            if (trim($titleTpl) === '') {
                                $titleTpl = defaultTitleTemplate('crm_prospect_inactive', $k, []);
                            }
                            if (trim($msgTpl) === '') {
                                $msgTpl = defaultMessageTemplate('crm_prospect_inactive', $k, []);
                            }
                            ?>
                            <tr class="border-b last:border-b-0">
                                <td class="py-3 pr-4 font-medium text-gray-800"><?= htmlspecialchars((string)$kLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-3 pr-4">
                                    <label class="inline-flex items-center gap-2">
                                        <input type="checkbox" name="enabled_crm_prospect_inactive_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr($isEnabled) ?> class="w-4 h-4">
                                        <span class="text-gray-700">Activer</span>
                                    </label>
                                </td>
                                <td class="py-3 pr-4">
                                    <select name="recipient_crm_prospect_inactive_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" class="px-3 py-2 border rounded-lg bg-white">
                                        <?php foreach ($recipientOptionsWithRequester as $val => $txtOpt): ?>
                                            <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= selectedAttr($recipientTeam, $val) ?>>
                                                <?= htmlspecialchars($txtOpt, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <textarea name="excluded_crm_prospect_inactive_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" placeholder="Emails exclus" class="mt-2 w-full min-w-[220px] px-3 py-2 border rounded-lg text-sm"><?= htmlspecialchars(excludedEmailsText((array) $rule), ENT_QUOTES, 'UTF-8') ?></textarea>
                                </td>
                                <td class="py-3 pr-4">
                                    <div class="flex items-center gap-4">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="ch_system_crm_prospect_inactive_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('system', $channels, true)) ?> class="w-4 h-4">
                                            <span class="text-gray-700">Système</span>
                                        </label>
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="ch_email_crm_prospect_inactive_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('email', $channels, true)) ?> class="w-4 h-4">
                                            <span class="text-gray-700">Email</span>
                                        </label>
                                    </div>
                                </td>
                                <td class="py-3 pr-4">
                                    <input type="text" name="label_crm_prospect_inactive_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[240px] px-3 py-2 border rounded-lg" />
                                </td>
                                <td class="py-3 pr-4">
                                    <input type="text" name="title_tpl_crm_prospect_inactive_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($titleTpl, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[220px] px-3 py-2 border rounded-lg" />
                                </td>
                                <td class="py-3 pr-4">
                                    <textarea name="msg_tpl_crm_prospect_inactive_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" class="w-full min-w-[280px] px-3 py-2 border rounded-lg"><?= htmlspecialchars($msgTpl, ENT_QUOTES, 'UTF-8') ?></textarea>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-end gap-3 pt-4">
                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 shadow">Enregistrer</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Évènement : Décaissement — liens cassés détectés</h2>
            <form method="post" class="space-y-4">
                <input type="hidden" name="save_section" value="broken_debourse_links_detected" />
                <div class="overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="py-3 pr-4">Type</th>
                                <th class="py-3 pr-4">Envoie notification</th>
                                <th class="py-3 pr-4">Destinataire</th>
                                <th class="py-3 pr-4">Canaux</th>
                                <th class="py-3 pr-4">Libellé</th>
                                <th class="py-3 pr-4">Titre (template)</th>
                                <th class="py-3 pr-4">Message (template)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $k = 'alert';
                            $kLabel = 'Alerte';
                            $rule = $rulesBrokenDebourseBySub[$k] ?? null;
                            $isEnabled = $rule ? (bool) ($rule['is_enabled'] ?? true) : true;
                            $recipientTeam = $rule ? (string) ($rule['recipient_team'] ?? 'finance') : 'finance';
                            $channels = $rule ? (array) ($rule['channels'] ?? ['system', 'email']) : ['system', 'email'];
                            $label = $rule ? (string) ($rule['label'] ?? 'Décaissement : liens cassés détectés') : 'Décaissement : liens cassés détectés';
                            $titleTpl = $rule ? (string) ($rule['title_template'] ?? '') : '';
                            $msgTpl = $rule ? (string) ($rule['message_template'] ?? '') : '';
                            if (trim($titleTpl) === '') {
                                $titleTpl = defaultTitleTemplate('broken_debourse_links_detected', $k, []);
                            }
                            if (trim($msgTpl) === '') {
                                $msgTpl = defaultMessageTemplate('broken_debourse_links_detected', $k, []);
                            }
                            ?>
                            <tr class="border-b last:border-b-0">
                                <td class="py-3 pr-4 font-medium text-gray-800"><?= htmlspecialchars((string)$kLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-3 pr-4">
                                    <label class="inline-flex items-center gap-2">
                                        <input type="checkbox" name="enabled_broken_debourse_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr($isEnabled) ?> class="w-4 h-4">
                                        <span class="text-gray-700">Activer</span>
                                    </label>
                                </td>
                                <td class="py-3 pr-4">
                                    <select name="recipient_broken_debourse_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" class="px-3 py-2 border rounded-lg bg-white">
                                        <?php foreach ($recipientOptionsWithRequester as $val => $txtOpt): ?>
                                            <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= selectedAttr($recipientTeam, $val) ?>>
                                                <?= htmlspecialchars($txtOpt, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <textarea name="excluded_broken_debourse_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" placeholder="Emails exclus" class="mt-2 w-full min-w-[220px] px-3 py-2 border rounded-lg text-sm"><?= htmlspecialchars(excludedEmailsText((array) $rule), ENT_QUOTES, 'UTF-8') ?></textarea>
                                </td>
                                <td class="py-3 pr-4">
                                    <div class="flex items-center gap-4">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="ch_system_broken_debourse_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('system', $channels, true)) ?> class="w-4 h-4">
                                            <span class="text-gray-700">Système</span>
                                        </label>
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="ch_email_broken_debourse_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= checkedAttr(in_array('email', $channels, true)) ?> class="w-4 h-4">
                                            <span class="text-gray-700">Email</span>
                                        </label>
                                    </div>
                                </td>
                                <td class="py-3 pr-4">
                                    <input type="text" name="label_broken_debourse_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[240px] px-3 py-2 border rounded-lg" />
                                </td>
                                <td class="py-3 pr-4">
                                    <input type="text" name="title_tpl_broken_debourse_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($titleTpl, ENT_QUOTES, 'UTF-8') ?>" class="w-full min-w-[220px] px-3 py-2 border rounded-lg" />
                                </td>
                                <td class="py-3 pr-4">
                                    <textarea name="msg_tpl_broken_debourse_<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" rows="2" class="w-full min-w-[280px] px-3 py-2 border rounded-lg"><?= htmlspecialchars($msgTpl, ENT_QUOTES, 'UTF-8') ?></textarea>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-end gap-3 pt-4">
                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 shadow">Enregistrer</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mt-6">
            <h2 class="text-xl font-bold text-gray-800 mb-2">Évènement : Nomination hiérarchique RH</h2>
            <p class="text-sm text-gray-600 mb-4">Paramètre les notifications envoyées lorsqu'un utilisateur est nommé responsable direct ou directeur du département depuis l'écran de hiérarchie RH.</p>

            <form method="post" class="space-y-4">
                <input type="hidden" name="save_section" value="rh_hierarchy_assigned" />
                <div class="overflow-auto" data-preview-mount>
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="py-3 pr-4">Évènement</th>
                                <th class="py-3 pr-4">Envoie notification</th>
                                <th class="py-3 pr-4">Destinataire</th>
                                <th class="py-3 pr-4">Canaux</th>
                                <th class="py-3 pr-4">Libellé</th>
                                <th class="py-3 pr-4">Titre (template)</th>
                                <th class="py-3 pr-4">Message (template)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (['direct_manager' => 'Nomination responsable', 'department_head' => 'Nomination directeur de département'] as $subKey => $eventLabel): ?>
                                <?php
                                $rule = $rulesRhHierarchyBySub[$subKey] ?? null;
                                $enabled = $rule ? (bool) ($rule['is_enabled'] ?? true) : true;
                                $recipient = (string) ($rule['recipient_team'] ?? 'requester');
                                $channels = $rule ? (array) ($rule['channels'] ?? ['system', 'email']) : ['system', 'email'];
                                $label = (string) ($rule['label'] ?? defaultTitleTemplate('rh_hierarchy_assigned', $subKey));
                                $titleTemplate = (string) ($rule['title_template'] ?? defaultTitleTemplate('rh_hierarchy_assigned', $subKey));
                                $messageTemplate = (string) ($rule['message_template'] ?? defaultMessageTemplate('rh_hierarchy_assigned', $subKey));
                                ?>
                                <tr class="border-b align-top">
                                    <td class="py-3 pr-4 font-medium text-gray-800"><?= htmlspecialchars($eventLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="py-3 pr-4">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" name="enabled_rh_hierarchy_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" value="1" <?= checkedAttr($enabled) ?> />
                                            <span>Actif</span>
                                        </label>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <select name="recipient_rh_hierarchy_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2">
                                            <?php foreach ($recipientOptionsHierarchy as $value => $optionLabel): ?>
                                                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= selectedAttr($recipient, $value) ?>><?= htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <textarea name="excluded_rh_hierarchy_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" rows="2" placeholder="Emails exclus" class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"><?= htmlspecialchars(excludedEmailsText((array) $rule), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <label class="block"><input type="checkbox" name="ch_system_rh_hierarchy_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" value="1" <?= checkedAttr(in_array('system', $channels, true)) ?> /> Système</label>
                                        <label class="block mt-1"><input type="checkbox" name="ch_email_rh_hierarchy_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" value="1" <?= checkedAttr(in_array('email', $channels, true)) ?> /> Email</label>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input type="text" data-preview-label value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" name="label_rh_hierarchy_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2" />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <input type="text" data-preview-title value="<?= htmlspecialchars($titleTemplate, ENT_QUOTES, 'UTF-8') ?>" name="title_tpl_rh_hierarchy_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2" />
                                    </td>
                                    <td class="py-3 pr-4">
                                        <textarea data-preview-message name="msg_tpl_rh_hierarchy_<?= htmlspecialchars($subKey, ENT_QUOTES, 'UTF-8') ?>" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2"><?= htmlspecialchars($messageTemplate, ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-end gap-3 pt-4">
                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 shadow">Enregistrer</button>
                </div>
            </form>
        </div>

        <div class="mt-6 text-sm text-gray-600">
            <p><strong>Note :</strong> les notifications « push » (en haut) et le badge sont alimentés par le canal <strong>Système</strong>. Le canal <strong>Email</strong> envoie un email aux utilisateurs actifs du destinataire (RH/Finances/Admin/Logistique/HSE) via l’email professionnel.</p>
            <p class="mt-2"><strong>Emails exclus :</strong> dans les lignes avec destinataire par service ou rôle, vous pouvez saisir une ou plusieurs adresses à retirer de la diffusion email, même si ces utilisateurs appartiennent au groupe ciblé.</p>
            <p class="mt-2"><strong>Templates :</strong> vous pouvez utiliser des champs comme <code>{{nom_expediteur}}</code>, <code>{{date}}</code>, <code>{{type_label}}</code>, <code>{{statut}}</code>, <code>{{statut_label}}</code>, <code>{{motif}}</code>, <code>{{num_fiche}}</code>, <code>{{montant}}</code>, <code>{{titre}}</code>, <code>{{date_event}}</code>, <code>{{date_debut}}</code>, <code>{{date_action}}</code>, <code>{{start}}</code>, <code>{{end}}</code>, <code>{{deadline}}</code>, <code>{{actor_name}}</code>, <code>{{scope_label}}</code>, <code>{{linked_name}}</code>, <code>{{linked_label}}</code>, <code>{{prospect_nom}}</code>, <code>{{societe_label}}</code>, <code>{{days_inactive}}</code>, <code>{{last_activity}}</code>, <code>{{broken_count}}</code>, <code>{{chantier_code}}</code>, <code>{{chantier_titre}}</code>, <code>{{chantier_label}}</code>, <code>{{role_label}}</code>, <code>{{department_label}}</code>, <code>{{supplier_name}}</code>, <code>{{supplier_code}}</code>, <code>{{validation_note}}</code>.</p>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var sampleData = {
            titre: 'Réunion sécurité',
            actor_name: 'Jean Kouassi',
            date_event: '14/04/2026',
            deadline: '14/04/2026 17:00',
            start: '08:30',
            end: '10:00',
            scope_label: 'Service QHSE',
            nom_expediteur: 'Jean Kouassi',
            collaborateur: 'Awa Koné',
            type_label: 'Validation',
            statut: 'planifié',
            statut_label: 'Planifié',
            motif: 'Suivi opérationnel',
            num_fiche: 'FEB-2026-041',
            montant: '245 000 FCFA',
            date: '14/04/2026',
            date_debut: '14/04/2026',
            date_action: '14/04/2026 08:30',
            linked_name: 'Client Horizon',
            linked_label: 'Prospect prioritaire',
            prospect_nom: 'Horizon BTP',
            societe_label: 'Finéa Construction',
            days_inactive: '12',
            last_activity: '02/04/2026',
            broken_count: '3',
            chantier_code: 'CH-118',
            chantier_titre: 'Réhabilitation bloc A',
            chantier_label: 'CH-118 - Réhabilitation bloc A'
        };

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function renderTemplate(template) {
            return String(template || '').replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, function(match, token) {
                if (Object.prototype.hasOwnProperty.call(sampleData, token)) {
                    return sampleData[token];
                }
                return match;
            });
        }

        function findPreviewInputs(row) {
            if (!row) {
                return null;
            }

            var labelInput = row.querySelector('[data-preview-label], input[name^="label_"]');
            var titleInput = row.querySelector('[data-preview-title], input[name^="title_tpl_"]');
            var messageInput = row.querySelector('[data-preview-message], textarea[name^="msg_tpl_"]');
            if (!labelInput && !titleInput && !messageInput) {
                return null;
            }

            return {
                label: labelInput,
                title: titleInput,
                message: messageInput
            };
        }

        function ensurePreviewCard(form) {
            var existing = form.querySelector('[data-template-preview]');
            if (existing) {
                return {
                    label: existing.querySelector('[data-preview-role="label"]'),
                    subject: existing.querySelector('[data-preview-role="subject"]'),
                    body: existing.querySelector('[data-preview-role="body"]')
                };
            }

            var mount = form.querySelector('[data-preview-mount]') || form.querySelector('.overflow-auto') || form;
            var card = document.createElement('div');
            card.setAttribute('data-template-preview', '1');
            card.className = 'mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-5';
            card.innerHTML = '' +
                '<div class="flex items-start justify-between gap-4 flex-wrap">' +
                '<div>' +
                '<div class="text-sm font-semibold text-slate-900">Aperçu temps réel du template email</div>' +
                '<div class="text-xs text-slate-500 mt-1">Le rendu se met à jour dès que vous modifiez la ligne active.</div>' +
                '</div>' +
                '<div data-preview-role="label" class="px-3 py-1 rounded-full bg-white border text-xs font-semibold text-slate-700">Notification</div>' +
                '</div>' +
                '<div class="mt-4 rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">' +
                '<div class="px-4 py-3 border-b border-slate-200 bg-slate-900 text-white">' +
                '<div class="text-[11px] uppercase tracking-wide text-slate-300">Objet</div>' +
                '<div data-preview-role="subject" class="mt-1 text-sm font-semibold">Aperçu objet</div>' +
                '</div>' +
                '<div class="px-4 py-4">' +
                '<div class="text-[11px] uppercase tracking-wide text-slate-500">Corps</div>' +
                '<div data-preview-role="body" class="mt-2 text-sm leading-6 text-slate-700"></div>' +
                '</div>' +
                '</div>' +
                '<div class="mt-3 text-xs text-slate-500">Exemple injecté automatiquement selon les variables connues.</div>';
            mount.insertAdjacentElement('afterend', card);

            return {
                label: card.querySelector('[data-preview-role="label"]'),
                subject: card.querySelector('[data-preview-role="subject"]'),
                body: card.querySelector('[data-preview-role="body"]')
            };
        }

        function updatePreviewFromRow(form, row, preview) {
            var inputs = findPreviewInputs(row);
            if (!inputs || !preview) {
                return;
            }

            var panel = form.closest('.bg-white');
            var heading = panel ? panel.querySelector('h2') : null;
            var fallbackLabel = heading ? heading.textContent.trim() : 'Notification';
            var labelText = inputs.label ? inputs.label.value : fallbackLabel;
            var subjectText = inputs.title ? renderTemplate(inputs.title.value) : '';
            var bodyText = inputs.message ? renderTemplate(inputs.message.value) : '';

            if (preview.label) {
                preview.label.textContent = labelText || fallbackLabel;
            }
            if (preview.subject) {
                preview.subject.textContent = subjectText || 'Aperçu objet';
            }
            if (preview.body) {
                preview.body.innerHTML = escapeHtml(bodyText || 'Aperçu message').replace(/\n/g, '<br>');
            }
        }

        function initPreviewForm(form) {
            if (!form || !form.querySelector('input[name="save_section"]')) {
                return;
            }

            var rows = Array.prototype.slice.call(form.querySelectorAll('tbody tr'));
            var previewableRows = rows.filter(function(row) {
                return !!findPreviewInputs(row);
            });
            if (!previewableRows.length) {
                return;
            }

            var preview = ensurePreviewCard(form);
            var activeRow = previewableRows[0];
            updatePreviewFromRow(form, activeRow, preview);

            form.addEventListener('focusin', function(event) {
                var row = event.target.closest('tr');
                if (!row || !findPreviewInputs(row)) {
                    return;
                }
                activeRow = row;
                updatePreviewFromRow(form, activeRow, preview);
            });

            form.addEventListener('input', function(event) {
                var row = event.target.closest('tr');
                if (!row || !findPreviewInputs(row)) {
                    return;
                }
                activeRow = row;
                updatePreviewFromRow(form, activeRow, preview);
            });

            form.addEventListener('change', function(event) {
                var row = event.target.closest('tr');
                if (!row || !findPreviewInputs(row)) {
                    return;
                }
                activeRow = row;
                updatePreviewFromRow(form, activeRow, preview);
            });
        }

        Array.prototype.forEach.call(document.querySelectorAll('form'), function(form) {
            initPreviewForm(form);
        });

        (function initCommunicationPushFilters() {
            var searchInput = document.getElementById('communicationPushSearch');
            var selectedOnlyInput = document.getElementById('communicationPushSelectedOnly');
            var table = document.getElementById('communicationPushTable');
            var visibleCount = document.getElementById('communicationPushVisibleCount');
            if (!searchInput || !selectedOnlyInput || !table || !visibleCount) {
                return;
            }

            var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-push-row="1"]'));
            if (!rows.length) {
                return;
            }

            function applyFilter() {
                var query = String(searchInput.value || '').toLowerCase().trim();
                var selectedOnly = !!selectedOnlyInput.checked;
                var visible = 0;

                rows.forEach(function(row) {
                    var haystack = String(row.getAttribute('data-search') || '').toLowerCase();
                    var isSelected = row.getAttribute('data-selected') === '1';
                    var matchesQuery = query === '' || haystack.indexOf(query) !== -1;
                    var matchesSelected = !selectedOnly || isSelected;
                    var show = matchesQuery && matchesSelected;
                    row.style.display = show ? '' : 'none';
                    if (show) {
                        visible += 1;
                    }
                });

                visibleCount.textContent = visible.toLocaleString('fr-FR') + ' ligne(s) visibles';
            }

            table.addEventListener('change', function(event) {
                var input = event.target;
                if (!input || input.type !== 'checkbox' || input.name !== 'communication_push_user_ids[]') {
                    return;
                }
                var row = input.closest('tr[data-push-row="1"]');
                if (!row) {
                    return;
                }
                row.setAttribute('data-selected', input.checked ? '1' : '0');
                applyFilter();
            });

            searchInput.addEventListener('input', applyFilter);
            selectedOnlyInput.addEventListener('change', applyFilter);
            applyFilter();
        })();
    });
</script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
