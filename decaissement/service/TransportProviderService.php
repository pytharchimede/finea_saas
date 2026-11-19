<?php
require_once __DIR__ . '/../../model/EnvConfig.php';
\EnvConfig::loadFromDirectory(__DIR__);

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FournisseurTransport.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../model/DatabaseApp.php';
require_once __DIR__ . '/../model/DemandeEssence.php';
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../../services/NotificationWhatsAppChannelService.php';

class TransportProviderService
{
    private PDO $pdoProj;
    private PDO $pdoApp;

    public function __construct(?PDO $pdoProj = null, ?PDO $pdoApp = null)
    {
        $this->pdoProj = $pdoProj ?: \Database::getConnection();
        $this->pdoApp  = $pdoApp  ?: \DatabaseApp::getConnection();
    }

    public function listProviders(): array
    {
        $repo = new \FournisseurTransport($this->pdoProj);
        return $repo->getAllActifs();
    }

    // Génère 3 fiches (carburant, frais, solde) selon conditions du fournisseur
    // entrée: base {chantier_code, beneficiaire, tel, details, ligne_debourse_id, montant_total}
    // conditions: du fournisseur (modes percent/fixed)
    public function generateVoyageFiches(int $providerId, array $input): array
    {
        $repoF = new \FournisseurTransport($this->pdoProj);
        $provider = $repoF->getById($providerId);
        if (!$provider) return ['ok' => false, 'error' => 'Fournisseur introuvable'];
        try{(new SupplierReferenceService($this->pdoProj))->resolve((int)($provider['master_supplier_id']??0));}catch(DomainException $e){return ['ok'=>false,'error'=>$e->getMessage()];}

        $total = (float)($input['montant_total'] ?? 0);
        // calcul parts
        $carb = $this->applyCond($total, $provider['carburant_mode'], (float)$provider['carburant_valeur']);
        $frais = $this->applyCond($total, $provider['frais_mode'], (float)$provider['frais_valeur']);
        $solde = max(0.0, $total - $carb - $frais);

        $ficheRepo = new \FicheDecaissement($this->pdoProj);
        $secur = $input['secur'] ?? 'SYSTEM';
        $ip = $input['ip'] ?? '';
        $port = (int)($input['port'] ?? 0);

        // Résolution des lignes de dépense cibles
        $ligneBase = (int)($input['ligne_debourse_id'] ?? 0);
        $cfgCarb = (int) (\EnvConfig::get('TRANSPORT_LIGNE_ID_CARBURANT', '545') ?: 545);
        $cfgFrais = (int) (\EnvConfig::get('TRANSPORT_LIGNE_ID_FRAIS', '546') ?: 546);
        $cfgLoc  = (int) (\EnvConfig::get('TRANSPORT_LIGNE_ID_LOCATION', '547') ?: 547);
        $ligneCarbId = $this->ligneExists($cfgCarb) ? $cfgCarb : $ligneBase;
        $ligneFraisId = $this->ligneExists($cfgFrais) ? $cfgFrais : $ligneBase;
        $ligneLocId  = $this->ligneExists($cfgLoc)  ? $cfgLoc  : $ligneBase;

        $created = [];
        // carburant
        $num1 = $ficheRepo->createFicheTransportPart(
            $input['beneficiaire'],
            $input['tel'],
            $input['chantier_code'],
            $ligneCarbId,
            'Carburant - ' . $input['details'],
            $carb
        );
        if ($num1) {
            $ficheRepo->setConforme($num1, $secur);
            $ficheRepo->validateAchat($num1, $secur, $ip, $port);
            $created['carburant'] = $num1;
        }

        // frais
        $num2 = $ficheRepo->createFicheTransportPart(
            $input['beneficiaire'],
            $input['tel'],
            $input['chantier_code'],
            $ligneFraisId,
            'Frais de route - ' . $input['details'],
            $frais
        );
        if ($num2) {
            $ficheRepo->setConforme($num2, $secur);
            $ficheRepo->validateAchat($num2, $secur, $ip, $port);
            $created['frais'] = $num2;
        }

        // solde
        $num3 = $ficheRepo->createFicheTransportPart(
            $input['beneficiaire'],
            $input['tel'],
            $input['chantier_code'],
            $ligneLocId,
            'Location / Voyage - ' . $input['details'],
            $solde
        );
        if ($num3) {
            $ficheRepo->setConforme($num3, $secur);
            $ficheRepo->validateAchat($num3, $secur, $ip, $port);
            $created['solde'] = $num3;
        }

        // Créer le bon d'essence en APP pour la fiche carburant
        $bonInfo = null;
        if (!empty($created['carburant'])) {
            $fiche = $ficheRepo->getByNum($created['carburant']);
            if ($fiche) {
                $dt = new DateTime($fiche['date_creat_fiche'] ?? date('Y-m-d H:i:s'));
                $seq = preg_replace('/\D+/', '', $created['carburant']);
                $seq = substr(str_pad($seq, 5, '0', STR_PAD_LEFT), -5);
                $code_bon = 'BE-' . $dt->format('ym') . '-' . $seq;
                $appDem = new \DemandeEssence($this->pdoApp);
                if ($appDem->getByCodeBon($code_bon)) {
                    $i = 2;
                    do {
                        $cand = $code_bon . '-' . $i;
                        if (!$appDem->getByCodeBon($cand)) {
                            $code_bon = $cand;
                            break;
                        }
                        $i++;
                    } while ($i < 100);
                }
                $appDem->create([
                    'num_fiche' => $created['carburant'],
                    'code_bon' => $code_bon,
                    'nom_beneficiaire' => $input['beneficiaire'],
                    'vehicule' => $input['vehicule'] ?? '',
                    'quantite' => $input['quantite'] ?? 0,
                    'montant' => $carb,
                    'date_demande' => $dt->format('Y-m-d'),
                    'motif' => 'Carburant - ' . $input['details'],
                    'dg_nom' => 'Direction Générale'
                ]);
                $bonInfo = [
                    'code_bon' => $code_bon,
                    'num_fiche' => $created['carburant'],
                    'date_demande' => $dt->format('Y-m-d')
                ];

                // Envoi WhatsApp: call-to-action carburant au responsable logistique (DG)
                $sid = defined('TWILIO_SID') ? TWILIO_SID : (\EnvConfig::get('TWILIO_SID', '') ?: '');
                $tokenTw = defined('TWILIO_TOKEN') ? TWILIO_TOKEN : (\EnvConfig::get('TWILIO_TOKEN', '') ?: '');
                $from = defined('TWILIO_WHATSAPP_FROM') ? TWILIO_WHATSAPP_FROM : (\EnvConfig::get('TWILIO_WHATSAPP_FROM', '') ?: '');
                $dgPhone = defined('DG_PHONE') ? DG_PHONE : (\EnvConfig::get('DG_PHONE', '') ?: '');

                if ($sid && $tokenTw && $from && $dgPhone) {
                    try {
                        $wa = new \NotificationWhatsAppChannelService($sid, $tokenTw, $from);

                        // Construire un lien d'approbation (signé si possible, sinon token fallback)
                        $secret = \EnvConfig::get('BON_APPROVAL_SECRET', '') ?: '';
                        $approvalToken = \EnvConfig::get('APPROVAL_TOKEN', '') ?: '';
                        $ts = time();
                        $query = ['code_bon' => $code_bon];
                        if ($secret !== '') {
                            $base = $code_bon . '|' . $ts;
                            $sig = hash_hmac('sha256', $base, $secret);
                            $query['ts'] = (string)$ts;
                            $query['sig'] = $sig;
                        } elseif ($approvalToken !== '') {
                            $query['token'] = $approvalToken;
                        }
                        // Utiliser le template avec liens (consultation + approbation)
                        $toDG = '+225' . preg_replace('/\D+/', '', (string)$dgPhone);
                        $date_str = $dt->format('d/m/Y');
                        $wa->sendNotifCreatToGerant(
                            $toDG,
                            $code_bon,
                            $input['beneficiaire'],
                            (string)$carb,
                            $date_str,
                            /* id_bon_view */
                            $code_bon,
                            /* id_bon_confirm */
                            $code_bon
                        );

                        // Optionnel: notifier le demandeur de la soumission
                        if (!empty($input['tel'])) {
                            $toBenef = (strpos($input['tel'], '+') === 0) ? $input['tel'] : ('+225' . preg_replace('/\D+/', '', (string)$input['tel']));
                            $wa->sendConfirmationSoumissionFicheDecaissement($toBenef, $input['beneficiaire'], $created['carburant']);
                        }
                    } catch (\Throwable $e) {
                        // silencieux
                    }
                }
            }
        }

        return [
            'ok' => true,
            'created' => $created,
            'parts' => ['carburant' => $carb, 'frais' => $frais, 'solde' => $solde],
            'bon' => $bonInfo
        ];
    }

    private function applyCond(float $total, string $mode, float $val): float
    {
        if ($mode === 'percent') return round($total * ($val / 100.0), 2);
        return round($val, 2);
    }

    private function ligneExists(int $id): bool
    {
        if ($id <= 0) return false;
        try {
            $st = $this->pdoProj->prepare('SELECT 1 FROM ligne_debourse_banamur WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            return (bool)$st->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }
}

