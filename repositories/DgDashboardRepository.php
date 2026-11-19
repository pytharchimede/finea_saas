<?php

require_once __DIR__ . '/../decaissement/model/PayrollManager.php';
require_once __DIR__ . '/CommercialProjectFinanceRepository.php';
require_once __DIR__ . '/../services/CommercialProjectFinanceMigrationService.php';

class DgDashboardRepository
{
    private PDO $pdo;
    private array $tables = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        (new CommercialProjectFinanceMigrationService($pdo))->ensureSchema();
    }

    public function connection(): PDO { return $this->pdo; }

    public function finance(string $start, string $end, string $trendMode='month'): array
    {
        $empty = ['month_paid' => 0.0, 'paid_count' => 0, 'pending_amount' => 0.0, 'pending_count' => 0, 'rejected_count' => 0, 'urgent_feb' => 0, 'trend' => []];
        if (!$this->tableExists('fiche_decaissement')) {
            return $empty;
        }
        try {
            $row = $this->pdo->query(
                "SELECT
                    COALESCE(SUM(CASE WHEN decaisse = 1 AND COALESCE(date_decaisse, date_creat_fiche) BETWEEN " . $this->pdo->quote($start . ' 00:00:00') . " AND " . $this->pdo->quote($end . ' 23:59:59') . " THEN montant_fiche ELSE 0 END), 0) month_paid,
                    SUM(CASE WHEN decaisse = 1 AND COALESCE(date_decaisse, date_creat_fiche) BETWEEN " . $this->pdo->quote($start . ' 00:00:00') . " AND " . $this->pdo->quote($end . ' 23:59:59') . " THEN 1 ELSE 0 END) paid_count,
                    COALESCE(SUM(CASE WHEN COALESCE(decaisse,0)=0 AND date_refuse IS NULL AND COALESCE(date_decaissement_minimum, planned_start_date, DATE(date_creat_fiche)) BETWEEN " . $this->pdo->quote($start) . " AND " . $this->pdo->quote($end) . " THEN montant_fiche ELSE 0 END), 0) pending_amount,
                    SUM(CASE WHEN COALESCE(decaisse,0)=0 AND date_refuse IS NULL AND COALESCE(date_decaissement_minimum, planned_start_date, DATE(date_creat_fiche)) BETWEEN " . $this->pdo->quote($start) . " AND " . $this->pdo->quote($end) . " THEN 1 ELSE 0 END) pending_count,
                    SUM(CASE WHEN date_refuse BETWEEN " . $this->pdo->quote($start . ' 00:00:00') . " AND " . $this->pdo->quote($end . ' 23:59:59') . " THEN 1 ELSE 0 END) rejected_count
                 FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id=f.chantier_id WHERE (f.chantier_id IS NULL OR c.archived_at IS NULL)"
            )->fetch(PDO::FETCH_ASSOC) ?: [];
            $empty = array_merge($empty, [
                'month_paid' => (float) ($row['month_paid'] ?? 0),
                'paid_count' => (int) ($row['paid_count'] ?? 0),
                'pending_amount' => (float) ($row['pending_amount'] ?? 0),
                'pending_count' => (int) ($row['pending_count'] ?? 0),
                'rejected_count' => (int) ($row['rejected_count'] ?? 0),
            ]);
            $dateExpr="DATE(COALESCE(date_decaisse,date_creat_fiche))";$periodExpr=match($trendMode){'day'=>$dateExpr,'week'=>"DATE_SUB($dateExpr,INTERVAL WEEKDAY($dateExpr) DAY)",'quarter'=>"CONCAT(YEAR($dateExpr),'-Q',QUARTER($dateExpr))",default=>"DATE_FORMAT($dateExpr,'%Y-%m')"};
            $trend=$this->pdo->prepare("SELECT $periodExpr period,COALESCE(SUM(montant_fiche),0) total FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id=f.chantier_id WHERE (f.chantier_id IS NULL OR c.archived_at IS NULL) AND decaisse=1 AND $dateExpr BETWEEN :start AND :end GROUP BY $periodExpr ORDER BY period");$trend->execute(['start'=>$start,'end'=>$end]);$empty['trend']=$trend->fetchAll(PDO::FETCH_ASSOC)?:[];$empty['trend_mode']=$trendMode;
        } catch (Throwable $e) {
        }
        if ($this->tableExists('employee_legal_requests')) {
            try {
                $empty['urgent_feb'] = (int) $this->pdo->query("SELECT COUNT(*) FROM employee_legal_requests r WHERE request_type='feb' AND status IN ('draft','submitted','pending') AND (payload_json LIKE '%urgent%' OR payload_json LIKE '%urgence%') AND NOT EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.chantier_id')) AS UNSIGNED) AND c.archived_at IS NOT NULL)")->fetchColumn();
            } catch (Throwable $e) {
            }
        }
        return $empty;
    }

    public function projectFinanceDecision(?string $dateFrom=null, ?string $dateTo=null): array
    {
        $empty = [
            'real_balance' => 0.0, 'projected_balance' => 0.0, 'real_inflows' => 0.0,
            'real_outflows' => 0.0, 'forecast_inflows' => 0.0, 'monthly_cashflow' => [],
            'forecast' => [], 'coverage' => ['chantiers' => 0, 'bc_complete' => 0, 'bc_legacy' => 0, 'invoiced' => 0],
            'top_chantiers' => [],
        ];
        if (!$this->tableExists('facture_vente_banamur') || !$this->tableExists('fiche_decaissement')) {
            return $empty;
        }
        try {
            $opening = $this->tableExists('treasury_opening_balances')
                ? (float) $this->pdo->query("SELECT COALESCE(SUM(amount),0) FROM treasury_opening_balances WHERE currency='XOF'")->fetchColumn()
                : 0.0;
            $empty['real_inflows'] = (float) $this->pdo->query(
                "SELECT COALESCE(SUM(CASE WHEN fv.collected_amount>0 THEN fv.collected_amount ELSE fv.total_ttc END),0)
                 FROM facture_vente_banamur fv JOIN chantier_banamur c ON c.id=fv.chantier_id AND c.archived_at IS NULL WHERE fv.paiement_statut='payee'"
            )->fetchColumn();
            $empty['real_outflows'] = (float) $this->pdo->query(
                'SELECT COALESCE(SUM(f.montant_fiche),0) FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id=f.chantier_id WHERE (f.chantier_id IS NULL OR c.archived_at IS NULL) AND f.decaisse=1'
            )->fetchColumn();
            $empty['forecast_inflows'] = (float) $this->pdo->query(
                "SELECT COALESCE(SUM(GREATEST(fv.total_ttc-fv.collected_amount,0)),0)
                 FROM facture_vente_banamur fv JOIN chantier_banamur c ON c.id=fv.chantier_id AND c.archived_at IS NULL WHERE paiement_statut='emise'
                   AND fv.expected_collection_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 90 DAY)"
            )->fetchColumn();
            $empty['real_balance'] = $opening + $empty['real_inflows'] - $empty['real_outflows'];
            $empty['projected_balance'] = $empty['real_balance'] + $empty['forecast_inflows'];

            $inflows = $this->pdo->query(
                "SELECT DATE_FORMAT(COALESCE(fv.paid_at,fv.updated_at),'%Y-%m') period,
                        SUM(CASE WHEN fv.collected_amount>0 THEN fv.collected_amount ELSE fv.total_ttc END) total
                 FROM facture_vente_banamur fv JOIN chantier_banamur c ON c.id=fv.chantier_id AND c.archived_at IS NULL WHERE paiement_statut='payee'
                   AND COALESCE(fv.paid_at,fv.updated_at)>=DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 5 MONTH),'%Y-%m-01')
                 GROUP BY period"
            )->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            $outflows = $this->pdo->query(
                "SELECT DATE_FORMAT(COALESCE(date_decaisse,date_creat_fiche),'%Y-%m') period,SUM(montant_fiche) total
                 FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id=f.chantier_id WHERE (f.chantier_id IS NULL OR c.archived_at IS NULL) AND decaisse=1
                   AND COALESCE(date_decaisse,date_creat_fiche)>=DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 5 MONTH),'%Y-%m-01')
                 GROUP BY period"
            )->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            for ($offset = 5; $offset >= 0; $offset--) {
                $period = date('Y-m', strtotime("-$offset month"));
                $empty['monthly_cashflow'][] = [
                    'period' => $period,
                    'inflows' => (float) ($inflows[$period] ?? 0),
                    'outflows' => (float) ($outflows[$period] ?? 0),
                ];
            }
            $forecastRows = $this->pdo->query(
                "SELECT DATE_FORMAT(fv.expected_collection_date,'%Y-%m') period,
                        SUM(GREATEST(fv.total_ttc-fv.collected_amount,0)) total
                 FROM facture_vente_banamur fv JOIN chantier_banamur c ON c.id=fv.chantier_id AND c.archived_at IS NULL WHERE paiement_statut='emise'
                   AND fv.expected_collection_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 90 DAY)
                 GROUP BY period ORDER BY period"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $empty['forecast'] = $forecastRows;

            if ($this->tableExists('chantier_banamur')) {
                $empty['coverage']['chantiers'] = (int) $this->pdo->query('SELECT COUNT(*) FROM chantier_banamur WHERE archived_at IS NULL')->fetchColumn();
                if ($this->tableExists('client_purchase_orders') && $this->tableExists('chantier_client_purchase_orders')) {
                    $coverage = $this->pdo->query(
                        "SELECT SUM(po.status='ACTIVE') bc_complete,SUM(po.status='LEGACY_INCOMPLETE') bc_legacy
                         FROM chantier_client_purchase_orders l JOIN client_purchase_orders po ON po.id=l.client_purchase_order_id
                         JOIN chantier_banamur c ON c.id=l.chantier_id WHERE c.archived_at IS NULL"
                    )->fetch(PDO::FETCH_ASSOC) ?: [];
                    $empty['coverage']['bc_complete'] = (int) ($coverage['bc_complete'] ?? 0);
                    $empty['coverage']['bc_legacy'] = (int) ($coverage['bc_legacy'] ?? 0);
                }
                $empty['coverage']['invoiced'] = (int) $this->pdo->query(
                    'SELECT COUNT(DISTINCT fv.chantier_id) FROM facture_vente_banamur fv
                     JOIN chantier_banamur c ON c.id=fv.chantier_id WHERE c.archived_at IS NULL'
                )->fetchColumn();
                $empty['top_chantiers'] = $this->pdo->query(
                    "SELECT c.id,c.code,CASE WHEN c.titre LIKE '%Ã%' THEN CONVERT(BINARY(CONVERT(c.titre USING latin1)) USING utf8mb4) ELSE c.titre END titre,
                        COALESCE(c.montant_devis,d.total_ttc,0)+COALESCE((SELECT SUM(ts.total_ttc) FROM devis_chantier_links dcl_ts INNER JOIN devis_banamur ts ON ts.id=dcl_ts.devis_id WHERE dcl_ts.chantier_id=c.id),0) contract_amount,
                        COALESCE((SELECT SUM(db.montant_debourse) FROM debourse_banamur db LEFT JOIN devis_chantier_links dcl ON dcl.devis_id=db.devis_id WHERE db.devis_id=c.devis_id OR dcl.chantier_id=c.id),0) budgeted_outflows,
                        COALESCE((SELECT SUM(f.montant_fiche) FROM fiche_decaissement f WHERE f.chantier_id=c.id AND f.decaisse=1),0) outflows,
                        COALESCE((SELECT SUM(fv.total_ttc) FROM facture_vente_banamur fv WHERE fv.chantier_id=c.id),0) invoiced,
                        COALESCE((SELECT SUM(CASE WHEN fv.collected_amount>0 THEN fv.collected_amount ELSE fv.total_ttc END) FROM facture_vente_banamur fv WHERE fv.chantier_id=c.id AND fv.paiement_statut='payee'),0) collected
                     FROM chantier_banamur c LEFT JOIN devis_banamur d ON d.id=c.devis_id
                     WHERE c.archived_at IS NULL ORDER BY GREATEST(outflows,invoiced,collected,contract_amount) DESC LIMIT 30"
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach($empty['top_chantiers'] as &$site){$budget=(float)($site['budgeted_outflows']??0);$spent=(float)($site['outflows']??0);$contract=(float)($site['contract_amount']??0);$site['remaining_outflows']=max(0,$budget-$spent);$site['budget_overrun']=max(0,$spent-$budget);$site['forecast_margin']=$contract-$budget;}unset($site);
            }
        } catch (Throwable $exception) {
            $empty['error'] = 'Données financières projet partiellement indisponibles.';
        }
        try {
            $cash=(new CommercialProjectFinanceRepository($this->pdo))->treasurySummary(90,$dateFrom,$dateTo);
            $empty=array_merge($empty,$cash);
            $empty['forecast']=array_map(static fn(array $row):array=>['period'=>$row['period']??'','total'=>(float)($row['forecast_inflows']??0)],$cash['monthly_cashflow']??[]);
        } catch(Throwable $e) { $empty['error']='Données de trésorerie partiellement indisponibles.'; }
        return $empty;
    }

    public function chantiers(): array
    {
        $data = ['active' => 0, 'late' => 0, 'top_consumers' => []];
        if (!$this->tableExists('chantier_banamur')) {
            return $data;
        }
        try {
            $data['active'] = (int) $this->pdo->query('SELECT COUNT(*) FROM chantier_banamur WHERE archived_at IS NULL')->fetchColumn();
            $data['late'] = (int) $this->pdo->query('SELECT COUNT(*) FROM chantier_banamur WHERE archived_at IS NULL AND closed_date IS NOT NULL AND closed_date < CURDATE()')->fetchColumn();
            if ($this->tableExists('fiche_decaissement')) {
                $data['top_consumers'] = $this->pdo->query(
                    "SELECT c.id, c.code, c.titre, COALESCE(SUM(f.montant_fiche),0) total
                     FROM chantier_banamur c LEFT JOIN fiche_decaissement f ON f.chantier_id=c.id AND f.decaisse=1
                     WHERE c.archived_at IS NULL GROUP BY c.id, c.code, c.titre ORDER BY total DESC LIMIT 5"
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {
        }
        return $data;
    }

    public function rh(): array
    {
        $data = [
            'headcount' => 0,
            'attendance_headcount' => 0,
            'present' => 0,
            'mission' => 0,
            'half_day' => 0,
            'conge' => 0,
            'maladie' => 0,
            'repos' => 0,
            'absent' => 0,
            'late' => 0,
            'is_working_day' => true,
            'pending_requests' => 0,
        ];

        if ($this->tableExists('personnel')) {
            try {
                $data['headcount'] = (int) $this->pdo->query("SELECT COUNT(*) FROM personnel WHERE date_sortie IS NULL OR date_sortie='0000-00-00'")->fetchColumn();
            } catch (Throwable $e) {
            }
        }

        if ($this->tableExists('rh_attendance_daily')) {
            try {
                $board = (new PayrollManager($this->pdo))->getAttendanceBoard(date('Y-m-d'));
                $stats = is_array($board['stats'] ?? null) ? $board['stats'] : [];
                $data['attendance_headcount'] = (int) ($board['total'] ?? 0);
                $data['is_working_day'] = !empty($board['is_working_day']);

                foreach (['present', 'mission', 'half_day', 'conge', 'maladie', 'repos', 'absence'] as $status) {
                    $target = $status === 'absence' ? 'absent' : $status;
                    $data[$target] = (int) ($stats[$status] ?? 0);
                }

                $settings = $this->attendanceSettings();
                foreach (($board['items'] ?? []) as $item) {
                    $attendance = is_array($item['attendance'] ?? null) ? $item['attendance'] : [];
                    if ($this->isLateAttendance($attendance, $settings)) {
                        $data['late']++;
                    }
                }
            } catch (Throwable $e) {
            }
        }

        if ($this->tableExists('employee_legal_requests')) {
            try {
                $data['pending_requests'] = (int) $this->pdo->query("SELECT COUNT(*) FROM employee_legal_requests WHERE request_type <> 'feb' AND status IN ('submitted','pending')")->fetchColumn();
            } catch (Throwable $e) {
            }
        }

        return $data;
    }

    private function attendanceSettings(): array
    {
        $settings = [
            'late_reference_time' => '08:30',
            'arrival_tolerance_minutes' => '10',
        ];
        if (!$this->tableExists('rh_attendance_settings')) {
            return $settings;
        }

        try {
            $stmt = $this->pdo->query("
                SELECT setting_key, setting_value
                FROM rh_attendance_settings
                WHERE setting_key IN ('late_reference_time', 'arrival_tolerance_minutes')
            ");
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $key = (string) ($row['setting_key'] ?? '');
                if (array_key_exists($key, $settings)) {
                    $settings[$key] = (string) ($row['setting_value'] ?? $settings[$key]);
                }
            }
        } catch (Throwable $e) {
        }

        return $settings;
    }

    private function isLateAttendance(array $attendance, array $settings): bool
    {
        $status = (string) ($attendance['attendance_status'] ?? '');
        if (!in_array($status, ['present', 'half_day'], true)) {
            return false;
        }

        $checkIn = trim((string) ($attendance['check_in_time'] ?? ''));
        $reference = trim((string) ($settings['late_reference_time'] ?? '08:30'));
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $checkIn, $checkInParts)
            || !preg_match('/^(\d{1,2}):(\d{2})/', $reference, $referenceParts)) {
            return false;
        }

        $checkInMinutes = ((int) $checkInParts[1] * 60) + (int) $checkInParts[2];
        $referenceMinutes = ((int) $referenceParts[1] * 60) + (int) $referenceParts[2];
        $tolerance = max(0, (int) ($settings['arrival_tolerance_minutes'] ?? 10));

        return $checkInMinutes > ($referenceMinutes + $tolerance);
    }

    public function tickets(int $userId): array
    {
        $data = ['open' => 0, 'urgent' => 0, 'overdue' => 0, 'assigned_to_me' => 0];
        if (!$this->tableExists('finea_tickets')) {
            return $data;
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    SUM(status NOT IN ('resolu','cloture','rejete')) open_count,
                    SUM(status NOT IN ('resolu','cloture','rejete') AND (priority_id=1 OR LOWER(title) LIKE '%urgent%')) urgent_count,
                    SUM(status NOT IN ('resolu','cloture','rejete') AND due_at IS NOT NULL AND due_at<NOW()) overdue_count,
                    SUM(status NOT IN ('resolu','cloture','rejete') AND assigned_user_id=:user_id) assigned_count
                 FROM finea_tickets"
            );
            $stmt->execute(['user_id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $data = ['open' => (int) ($row['open_count'] ?? 0), 'urgent' => (int) ($row['urgent_count'] ?? 0), 'overdue' => (int) ($row['overdue_count'] ?? 0), 'assigned_to_me' => (int) ($row['assigned_count'] ?? 0)];
        } catch (Throwable $e) {
        }
        return $data;
    }

    public function crm(): array
    {
        $data = ['prospects' => 0, 'opportunities' => 0, 'pipeline' => 0.0, 'followups' => 0, 'clients' => 0];
        try {
            if ($this->tableExists('crm_prospect_banamur')) {
                $data['prospects'] = (int) $this->pdo->query('SELECT COUNT(*) FROM crm_prospect_banamur')->fetchColumn();
            }
            if ($this->tableExists('crm_opportunite_banamur')) {
                $row = $this->pdo->query("SELECT COUNT(*) opportunities, COALESCE(SUM(montant_xof),0) pipeline, SUM(next_action_at IS NOT NULL AND next_action_at<=DATE_ADD(NOW(),INTERVAL 7 DAY)) followups FROM crm_opportunite_banamur WHERE LOWER(etape) NOT IN ('gagne','gagné','perdu','cloture','clôturé')")->fetch(PDO::FETCH_ASSOC) ?: [];
                $data['opportunities'] = (int) ($row['opportunities'] ?? 0);
                $data['pipeline'] = (float) ($row['pipeline'] ?? 0);
                $data['followups'] = (int) ($row['followups'] ?? 0);
            }
            if ($this->tableExists('client_banamur')) {
                $data['clients'] = (int) $this->pdo->query('SELECT COUNT(*) FROM client_banamur')->fetchColumn();
            }
        } catch (Throwable $e) {
        }
        return $data;
    }

    public function ao(): array
    {
        $data = ['active' => 0, 'new' => 0, 'preparing' => 0, 'due_soon' => 0];
        if (!$this->tableExists('ao_opportunities')) {
            return $data;
        }
        try {
            $row = $this->pdo->query(
                "SELECT
                    SUM(date_limite>=CURDATE()) active_count,
                    SUM(date_limite>=CURDATE() AND statut_interne='nouveau') new_count,
                    SUM(date_limite>=CURDATE() AND statut_interne IN ('interessant','crm','preparation')) preparing_count,
                    SUM(date_limite BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)) due_soon_count
                 FROM ao_opportunities"
            )->fetch(PDO::FETCH_ASSOC) ?: [];
            $data = ['active' => (int) ($row['active_count'] ?? 0), 'new' => (int) ($row['new_count'] ?? 0), 'preparing' => (int) ($row['preparing_count'] ?? 0), 'due_soon' => (int) ($row['due_soon_count'] ?? 0)];
        } catch (Throwable $e) {
        }
        return $data;
    }

    public function documentation(): array
    {
        $data = ['documents' => 0, 'recent' => 0, 'shared' => 0, 'expiring' => 0];
        try {
            if ($this->tableExists('doc_documents')) {
                $row = $this->pdo->query('SELECT COUNT(*) total, SUM(created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) recent_count FROM doc_documents WHERE is_archived=0')->fetch(PDO::FETCH_ASSOC) ?: [];
                $data['documents'] = (int) ($row['total'] ?? 0);
                $data['recent'] = (int) ($row['recent_count'] ?? 0);
            }
            if ($this->tableExists('doc_document_shares')) {
                $row = $this->pdo->query('SELECT COUNT(*) shared_count, SUM(expires_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 30 DAY)) expiring_count FROM doc_document_shares')->fetch(PDO::FETCH_ASSOC) ?: [];
                $data['shared'] = (int) ($row['shared_count'] ?? 0);
                $data['expiring'] = (int) ($row['expiring_count'] ?? 0);
            }
        } catch (Throwable $e) {
        }
        return $data;
    }

    public function logistics(): array
    {
        $data = ['unavailable_assets' => 0, 'expiring_documents' => 0, 'open_requests' => 0];
        try {
            if ($this->tableExists('logistics_assets')) {
                $data['unavailable_assets'] = (int) $this->pdo->query("SELECT COUNT(*) FROM logistics_assets WHERE status NOT IN ('disponible','active')")->fetchColumn();
            }
            if ($this->tableExists('logistics_documents')) {
                $data['expiring_documents'] = (int) $this->pdo->query('SELECT COUNT(*) FROM logistics_documents WHERE expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)')->fetchColumn();
            }
            if ($this->tableExists('logistics_requests')) {
                $data['open_requests'] = (int) $this->pdo->query("SELECT COUNT(*) FROM logistics_requests WHERE status NOT IN ('terminee','annulee','rejetee')")->fetchColumn();
            }
        } catch (Throwable $e) {
        }
        return $data;
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tables)) {
            return $this->tables[$table];
        }
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
            $stmt->execute(['table' => $table]);
            return $this->tables[$table] = (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return $this->tables[$table] = false;
        }
    }
}
