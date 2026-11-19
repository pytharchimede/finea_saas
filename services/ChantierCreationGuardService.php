<?php
declare(strict_types=1);
require_once __DIR__.'/ClientBusinessPolicy.php';

final class ChantierCreationGuardService
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        foreach (['project_start_date' => 'DATE NULL', 'project_end_date' => 'DATE NULL'] as $column => $definition) {
            $check = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="devis_banamur" AND COLUMN_NAME=:column');
            $check->execute(['column' => $column]);
            if (!(int) $check->fetchColumn()) {
                $this->pdo->exec("ALTER TABLE devis_banamur ADD COLUMN `$column` $definition");
            }
        }
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS devis_billing_schedule (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            devis_id INT NOT NULL,
            label VARCHAR(190) NOT NULL,
            expected_date DATE NOT NULL,
            percentage DECIMAL(7,4) NOT NULL,
            expected_amount DECIMAL(20,2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(id), KEY idx_devis_billing_schedule(devis_id,expected_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function savePreparation(int $devisId, string $startDate, string $endDate, array $schedule): void
    {
        $this->assertDate($startDate, 'La date de début du planning est obligatoire.');
        $this->assertDate($endDate, 'La date de fin du planning est obligatoire.');
        if ($endDate < $startDate) {
            throw new DomainException('La date de fin du planning doit être postérieure ou égale à la date de début.');
        }
        if ($schedule === []) {
            throw new DomainException('Ajoutez au moins une échéance de facturation et d’encaissement.');
        }
        $quote = $this->quote($devisId);
        ClientBusinessPolicy::requireClient($this->pdo,(int)($quote['client_id']??0));
        if ($this->isUsableDate((string) ($quote['date_emission'] ?? '')) && $startDate < $quote['date_emission']) {
            throw new DomainException('Le chantier ne peut pas commencer avant l’émission du devis du ' . $this->frDate($quote['date_emission']) . '.');
        }
        $total = (float) $quote['total_ttc'];
        $percentageTotal = 0.0;
        $previousBillingDate = null;
        foreach ($schedule as $index => $row) {
            $percentage = round((float) ($row['percentage'] ?? 0), 4);
            $date = trim((string) ($row['expected_date'] ?? ''));
            $label = trim((string) ($row['label'] ?? ''));
            $this->assertDate($date, 'La date de l’échéance ' . ($index + 1) . ' est obligatoire.');
            if ($this->isUsableDate((string) ($quote['date_emission'] ?? '')) && $date < $quote['date_emission']) {
                throw new DomainException('L’échéance « ' . ($label ?: ($index + 1)) . ' » ne peut pas être prévue avant l’émission du devis (' . $this->frDate($quote['date_emission']) . ').');
            }
            if ($previousBillingDate !== null && $date < $previousBillingDate) {
                throw new DomainException('Les échéances doivent être saisies dans l’ordre chronologique. La date de « ' . ($label ?: ($index + 1)) . ' » est antérieure à l’échéance précédente.');
            }
            if ($percentage <= 0 || $percentage > 100) {
                throw new DomainException('Le pourcentage de chaque échéance doit être compris entre 0 et 100 %.');
            }
            if ($label === '') {
                throw new DomainException('Le libellé de chaque échéance est obligatoire.');
            }
            $percentageTotal += $percentage;
            $previousBillingDate = $date;
        }
        if (abs($percentageTotal - 100.0) > 0.01) {
            throw new DomainException('L’échéancier doit couvrir exactement 100 % du devis. Total actuel : ' . number_format($percentageTotal, 2, ',', ' ') . ' %.');
        }
        require_once __DIR__ . '/ChantierWorkPeriodService.php';
        ChantierWorkPeriodService::save($this->pdo, $devisId, $startDate, $endDate);
        $this->pdo->prepare('DELETE FROM devis_billing_schedule WHERE devis_id=:id')->execute(['id' => $devisId]);
        $insert = $this->pdo->prepare('INSERT INTO devis_billing_schedule(devis_id,label,expected_date,percentage,expected_amount) VALUES(:devis,:label,:date,:percentage,:amount)');
        foreach ($schedule as $row) {
            $percentage = round((float) $row['percentage'], 4);
            $insert->execute(['devis' => $devisId, 'label' => trim((string) $row['label']), 'date' => (string) $row['expected_date'], 'percentage' => $percentage, 'amount' => round($total * $percentage / 100, 2)]);
        }
    }

    public function validate(int $devisId): array
    {
        $quote = $this->quote($devisId);
        $quoteTotal = (float) $quote['total_ttc'];
        if ($quoteTotal <= 0) {
            throw new DomainException('Création impossible : le montant total du devis doit être supérieur à zéro.');
        }
        if (empty($quote['project_start_date']) || empty($quote['project_end_date']) || $quote['project_end_date'] < $quote['project_start_date']) {
            throw new DomainException('Création impossible : le planning du devis doit comporter une date de début et une date de fin valides.');
        }
        if ($this->isUsableDate((string) ($quote['date_emission'] ?? '')) && $quote['project_start_date'] < $quote['date_emission']) {
            throw new DomainException('Création impossible : le chantier commence le ' . $this->frDate($quote['project_start_date']) . ', avant l’émission du devis du ' . $this->frDate($quote['date_emission']) . '.');
        }
        $lineStmt = $this->pdo->prepare('SELECT COUNT(*) FROM ligne_devis_banamur WHERE devis_id=:id');
        $lineStmt->execute(['id' => $devisId]);
        if ((int) $lineStmt->fetchColumn() === 0) {
            throw new DomainException('Création impossible : le devis doit contenir au moins une ligne.');
        }
        $debit = $this->pdo->prepare('SELECT COUNT(*) count_rows, COALESCE(SUM(d.montant_debourse),0) total, SUM(CASE WHEN d.date_debut IS NULL OR d.date_fin IS NULL OR d.date_fin<d.date_debut THEN 1 ELSE 0 END) invalid_dates FROM debourse_banamur d INNER JOIN ligne_devis_banamur ld ON ld.id=d.ligne_devis_id AND ld.devis_id=d.devis_id WHERE d.devis_id=:id');
        $debit->execute(['id' => $devisId]);
        $debitRow = $debit->fetch(PDO::FETCH_ASSOC) ?: [];
        $debitTotal = (float) ($debitRow['total'] ?? 0);
        if ((int) ($debitRow['count_rows'] ?? 0) === 0 || $debitTotal <= 0) {
            throw new DomainException('Création impossible : un déboursé lié au devis est obligatoire.');
        }
        if ((int) ($debitRow['invalid_dates'] ?? 0) > 0) {
            throw new DomainException('Création impossible : chaque déboursé doit avoir des dates de début et de fin valides.');
        }
        $outsideDebit = $this->pdo->prepare('SELECT d.id,COALESCE(NULLIF(ld.designation,""),CONCAT("Déboursé #",d.id)) label,d.date_debut,d.date_fin FROM debourse_banamur d INNER JOIN ligne_devis_banamur ld ON ld.id=d.ligne_devis_id AND ld.devis_id=d.devis_id WHERE d.devis_id=:id AND (d.date_debut<:start OR d.date_fin>:end) ORDER BY d.id LIMIT 1');
        $outsideDebit->execute(['id' => $devisId, 'start' => $quote['project_start_date'], 'end' => $quote['project_end_date']]);
        if ($row = $outsideDebit->fetch(PDO::FETCH_ASSOC)) {
            throw new DomainException('Création impossible : « ' . $row['label'] . ' » est planifié du ' . $this->frDate($row['date_debut']) . ' au ' . $this->frDate($row['date_fin']) . ', en dehors du chantier prévu du ' . $this->frDate($quote['project_start_date']) . ' au ' . $this->frDate($quote['project_end_date']) . '.');
        }
        $limit = round($quoteTotal * 0.65, 2);
        if ($debitTotal > $limit + 0.01) {
            throw new DomainException('Création impossible : le déboursé représente ' . number_format($debitTotal / $quoteTotal * 100, 2, ',', ' ') . ' % du devis. La limite autorisée est 65 %.');
        }
        $billing = $this->pdo->prepare('SELECT COUNT(*) count_rows, COALESCE(SUM(percentage),0) percentage_total, COALESCE(SUM(expected_amount),0) amount_total, SUM(expected_date IS NULL) invalid_dates FROM devis_billing_schedule WHERE devis_id=:id');
        $billing->execute(['id' => $devisId]);
        $billingRow = $billing->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int) ($billingRow['count_rows'] ?? 0) === 0 || abs((float) ($billingRow['percentage_total'] ?? 0) - 100) > 0.01 || (int) ($billingRow['invalid_dates'] ?? 0) > 0) {
            throw new DomainException('Création impossible : l’échéancier de facturation doit comporter des dates et couvrir exactement 100 % du devis.');
        }
        $billingOrder = $this->schedule($devisId);
        $previousDate = null;
        foreach ($billingOrder as $row) {
            $date = (string) $row['expected_date'];
            if ($this->isUsableDate((string) ($quote['date_emission'] ?? '')) && $date < $quote['date_emission']) {
                throw new DomainException('Création impossible : l’échéance « ' . $row['label'] . ' » précède la date d’émission du devis.');
            }
            if ($previousDate !== null && $date < $previousDate) throw new DomainException('Création impossible : les échéances de facturation ne sont pas dans l’ordre chronologique.');
            $previousDate = $date;
        }
        $this->validateOperationalPlanning($devisId, $quote);
        return ['quote' => $quote, 'debourse_total' => $debitTotal, 'debourse_limit' => $limit, 'billing_total' => (float) $billingRow['amount_total']];
    }

    public function schedule(int $devisId): array
    {
        $statement = $this->pdo->prepare('SELECT label,expected_date,percentage,expected_amount FROM devis_billing_schedule WHERE devis_id=:id ORDER BY expected_date,id');
        $statement->execute(['id' => $devisId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function quote(int $devisId): array
    {
        $statement = $this->pdo->prepare('SELECT id,client_id,total_ttc,date_emission,date_expiration,project_start_date,project_end_date FROM devis_banamur WHERE id=:id LIMIT 1');
        $statement->execute(['id' => $devisId]);
        $quote = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$quote) {
            throw new DomainException('Création impossible : devis introuvable.');
        }
        return $quote;
    }

    private function assertDate(string $date, string $message): void
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new DomainException($message);
        }
    }

    private function validateOperationalPlanning(int $devisId, array $quote): void
    {
        try {
            $statement = $this->pdo->prepare('SELECT p.id,p.libelle,p.date_debut,p.date_fin_prevue FROM planning_chantier_projects p JOIN chantier_banamur c ON c.id=p.chantier_id WHERE c.devis_id=:id AND c.archived_at IS NULL AND (p.date_debut IS NULL OR p.date_fin_prevue IS NULL OR p.date_fin_prevue<p.date_debut OR p.date_debut<:start OR p.date_fin_prevue>:end) ORDER BY p.id LIMIT 1');
            $statement->execute(['id' => $devisId, 'start' => $quote['project_start_date'], 'end' => $quote['project_end_date']]);
            if ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                throw new DomainException('Planning incohérent : « ' . ($row['libelle'] ?: ('activité #' . $row['id'])) . ' » doit être placé entre le ' . $this->frDate($quote['project_start_date']) . ' et le ' . $this->frDate($quote['project_end_date']) . '.');
            }
        } catch (PDOException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'doesn')) throw $e;
        }
    }

    private function isUsableDate(string $date): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date !== '0000-00-00';
    }

    private function frDate(?string $date): string
    {
        return $this->isUsableDate((string) $date) ? (new DateTimeImmutable((string) $date))->format('d/m/Y') : 'date non renseignée';
    }
}

