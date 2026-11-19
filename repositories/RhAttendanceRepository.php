<?php

require_once __DIR__ . '/../model/AttendanceSyncService.php';
require_once __DIR__ . '/../model/RhAbsenceNotifier.php';

class RhAttendanceRepository
{
    private PDO $pdo;
    private AttendanceSyncService $sync;
    private RhAbsenceNotifier $absenceNotifier;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->sync = new AttendanceSyncService($pdo);
        $this->absenceNotifier = new RhAbsenceNotifier($pdo);
    }

    public function sync(): AttendanceSyncService
    {
        return $this->sync;
    }

    public function absenceNotifier(): RhAbsenceNotifier
    {
        return $this->absenceNotifier;
    }

    public function listPointageEligiblePersonnel(): array
    {
        return $this->pdo->query('
            SELECT
                p.id_personnel_soignant AS id,
                p.matricule_personnel_soignant AS matricule,
                p.nom_personnel_soignant AS full_name,
                p.email_personnel_soignant AS email,
                COALESCE(NULLIF(TRIM(s.lib_service), ""), "Service non renseigne") AS service_label,
                COALESCE(NULLIF(TRIM(f.lib_fonction), ""), "Fonction non renseignee") AS fonction_label
            FROM personnel p
            LEFT JOIN service s ON s.id_service = p.service_id
            LEFT JOIN fonction f ON f.id_fonction = p.fonction_id
            WHERE (p.date_sortie IS NULL OR p.date_sortie = "0000-00-00")
            ORDER BY p.nom_personnel_soignant ASC
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
