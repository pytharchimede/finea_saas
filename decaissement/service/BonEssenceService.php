<?php
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/DatabaseApp.php';
require_once __DIR__ . '/../../model/EnvConfig.php';
require_once __DIR__ . '/../model/Fiche.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../model/DemandeEssence.php';

class BonEssenceService
{
    private PDO $pdoProj;
    private PDO $pdoApp;

    public function __construct(?PDO $pdoProj = null, ?PDO $pdoApp = null)
    {
        $this->pdoProj = $pdoProj ?: (new \Database())->getConnection();
        $this->pdoApp  = $pdoApp ?: \DatabaseApp::getConnection();
    }

    public function findBonById(string $idBon): array
    {
        $debug = [
            'id_input' => $idBon,
            'demande_found' => false,
            'demande_db' => null,
            'tried_dbs' => [],
            'fiche_source' => null,
            'suffix_search' => null,
        ];

        $ficheApp   = new \Fiche($this->pdoApp);
        $ficheDec   = new \FicheDecaissement($this->pdoProj);
        $demandeObj = new \DemandeEssence($this->pdoApp);

        $demande = $demandeObj->getByCodeBon($idBon);
        if (!$demande) {
            $stmt = $this->pdoApp->prepare('SELECT * FROM demande_essence WHERE TRIM(code_bon)=?');
            $stmt->execute([trim($idBon)]);
            $demande = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$demande) {
            $idBase = preg_replace('/-\d+$/', '', $idBon);
            $debug['id_base'] = $idBase;
            if ($idBase && $idBase !== $idBon) {
                $stmt = $this->pdoApp->prepare('SELECT * FROM demande_essence WHERE TRIM(code_bon)=?');
                $stmt->execute([$idBase]);
                $demande = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
        if (!$demande && $this->pdoProj !== $this->pdoApp) {
            // essayer en base PROJET si table existe
            try {
                $stmt = $this->pdoProj->prepare('SELECT * FROM demande_essence WHERE TRIM(code_bon)=?');
                $stmt->execute([trim($idBon)]);
                $demande = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$demande) {
                    $idBase = preg_replace('/-\d+$/', '', $idBon);
                    if ($idBase) {
                        $stmt = $this->pdoProj->prepare('SELECT * FROM demande_essence WHERE TRIM(code_bon)=?');
                        $stmt->execute([$idBase]);
                        $demande = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    }
                }
                if ($demande) {
                    $debug['demande_db'] = 'PROJET';
                }
            } catch (\Throwable $e) {
                // table absente: ignorer
            }
        }

        $debug['demande_found'] = (bool)$demande;

        // Déterminer num_fiche
        $num_fiche = $demande['num_fiche'] ?? (ctype_digit($idBon) ? $idBon : null);

        $fiche = null;
        if ($num_fiche) {
            $fiche = $ficheApp->getByNumFiche($num_fiche);
            if (!$fiche) {
                $fiche = $ficheDec->getByNum($num_fiche);
                if ($fiche) {
                    $debug['fiche_source'] = 'PROJET';
                }
            } else {
                $debug['fiche_source'] = 'APP';
            }
        }
        if (!$fiche && !$demande) {
            // Recherche par suffixe BE-YYMM-XXXXX(-N)
            $idBase = preg_replace('/-\d+$/', '', $idBon);
            if (preg_match('/^BE-\d{4}-(\d{3,})$/', $idBase, $m)) {
                $suffix = substr(str_pad($m[1], 5, '0', STR_PAD_LEFT), -5);
                $debug['suffix_search'] = true;
                $debug['suffix'] = $suffix;
                try {
                    $stmt = $this->pdoApp->prepare("SELECT * FROM fiche WHERE RIGHT(LPAD(num_fiche,5,'0'),5) = :s LIMIT 1");
                    $stmt->execute([':s' => $suffix]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $fiche = $row;
                        $debug['fiche_source'] = 'APP-suffix';
                    }
                } catch (\Throwable $e) {
                }
                if (!$fiche) {
                    try {
                        $stmt = $this->pdoProj->prepare("SELECT * FROM fiche_decaissement WHERE RIGHT(LPAD(num_fiche,5,'0'),5) = :s LIMIT 1");
                        $stmt->execute([':s' => $suffix]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row) {
                            $fiche = $row;
                            $debug['fiche_source'] = 'PROJET-suffix';
                            $num_fiche = $row['num_fiche'] ?? $num_fiche;
                        }
                    } catch (\Throwable $e) {
                    }
                }
            } else {
                $debug['suffix_search'] = false;
            }
        }

        if (!$demande && !$fiche) {
            return ['ok' => false, 'message' => 'Bon introuvable', 'debug' => $debug];
        }

        // Construire les données du bon
        $benef = $demande['nom_beneficiaire'] ?? ($fiche['beficiaire_fiche'] ?? '');
        $montant = $demande['montant'] ?? ($fiche['montant_fiche'] ?? 0);
        $dateDem = $demande['date_demande'] ?? ($fiche['date_creat_fiche'] ?? date('Y-m-d'));
        $motif  = $demande['motif'] ?? ($fiche['designation_fiche'] ?? '');
        $dgNom  = $demande['dg_nom'] ?? '';
        $codeAff = $demande['code_bon'] ?? ($num_fiche ?? (string)$idBon);
        $date = new DateTime($dateDem);
        $codeBon = $demande['code_bon'] ?? ('BE-' . $date->format('ym') . '-' . substr(str_pad($num_fiche ?? '0', 5, '0', STR_PAD_LEFT), -5));

        return [
            'ok' => true,
            'bon' => [
                'code_bon' => $codeBon,
                'code_aff' => $codeAff,
                'num_fiche' => $num_fiche,
                'beneficiaire' => $benef,
                'montant' => (float)$montant,
                'date_demande' => $dateDem,
                'motif' => $motif,
                'dg_nom' => $dgNom,
            ],
            'debug' => $debug,
        ];
    }
}
