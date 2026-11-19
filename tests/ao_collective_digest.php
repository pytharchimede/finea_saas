<?php
require_once __DIR__ . '/../services/AoDigestPolicy.php';
require_once __DIR__ . '/../services/ContenuLocalAoAdapter.php';
$checks = 0;
function check(bool $value, string $message): void { global $checks; $checks++; if (!$value) throw new RuntimeException($message); }
$tz = new DateTimeZone('Africa/Abidjan');
check(AoDigestPolicy::isDue(new DateTimeImmutable('2026-10-05 08:00', $tz)), 'Lundi 8 h');
check(AoDigestPolicy::isDue(new DateTimeImmutable('2026-10-09 08:00', $tz)), 'Vendredi 8 h');
check(!AoDigestPolicy::isDue(new DateTimeImmutable('2026-10-05 07:59', $tz)), 'Pas avant 8 h');
check(!AoDigestPolicy::isDue(new DateTimeImmutable('2026-10-07 08:00', $tz)), 'Pas mercredi');
check(AoDigestPolicy::isDue(new DateTimeImmutable('2026-10-05 10:00', new DateTimeZone('Europe/Paris'))), 'Fuseau Abidjan');
$base = ['id' => 1, 'date_limite' => '2026-10-07', 'score_pertinence' => 60, 'dg_decision' => 'pending', 'statut_interne' => 'nouveau', 'alerts_disabled' => 0];
$rows = [$base, array_replace($base, ['id'=>2,'date_limite'=>'2026-10-06']), array_replace($base,['id'=>3,'score_pertinence'=>59]),
    array_replace($base,['id'=>4,'date_limite'=>null]), array_replace($base,['id'=>5,'dg_decision'=>'not_interested']),
    array_replace($base,['id'=>6,'alerts_disabled'=>1]), array_replace($base,['id'=>7,'statut_interne'=>'non_interessant']),
    array_replace($base,['id'=>8,'statut_interne'=>'converti_crm','dg_decision'=>'interested'])];
$selection = AoDigestPolicy::selectOpportunities($rows,60,new DateTimeImmutable('2026-10-07 08:00',$tz));
check(array_column($selection,'id') === [1,8], 'Offres ouvertes, date connue, seuil inclusif, décisions et CRM');
check(AoDigestPolicy::selectOpportunities($rows,60,new DateTimeImmutable('2026-10-07 08:00',$tz)) === $selection, 'Même offre reprise dans les bilans suivants');
$recipient = ['email'=>'chef@example.test','name'=>'Chef','role'=>'to','status'=>'included'];
$envelope=AoDigestPolicy::envelope([$recipient,['email'=>'DG@example.test','name'=>'DG','role'=>'cc','status'=>'included'],
    ['email'=>'dg@example.test','name'=>'Doublon','role'=>'cc','status'=>'included'],
    ['email'=>'admin@example.test','name'=>'Admin','role'=>'cc','status'=>'disabled'],
    ['email'=>'','name'=>'Sans mail','role'=>'cc','status'=>'invalid_email']]);
check($envelope['to']['email']==='chef@example.test' && count($envelope['cc'])===1,'Principal, copie, doublons et désactivation admin');
$blocked=false;try { AoDigestPolicy::envelope([]); } catch (RuntimeException $e) { $blocked=true; }check($blocked,'Responsable absent doit bloquer');
$blocked=false;try { AoDigestPolicy::envelope([$recipient,array_replace($recipient,['email'=>'autre@example.test'])]); } catch (RuntimeException $e) { $blocked=true; }check($blocked,'Deux responsables doivent bloquer');
$adapter = new ContenuLocalAoAdapter([]);
$offer=$adapter->normalizeOpportunity(['id'=>1915,'reference'=>'CDI-202610-1','title'=>'Étude d’impact environnemental et social','org'=>['name'=>'VAALCO Energy'],
    'launchPlannedAt'=>'2026-10-06T00:00:00Z','launchEndAt'=>'2026-10-29T00:00:00Z','status'=>'LAUNCHED','type'=>'AO']);
check($offer['date_limite']==='2026-10-29','Fin de lancement comme date de dépôt');
check($offer['autorite_contractante']==='VAALCO Energy','Autorité source');
$a=AoOpportunity::prepare($offer);$b=AoOpportunity::prepare($adapter->normalizeOpportunity(['id'=>1910,'title'=>'Travaux électriques','reference'=>'29092026 EG']));
check($a['url_canonical']!==$b['url_canonical'],'Routes SPA distinctes pour éviter les fusions erronées');
check($adapter->normalizeOpportunity(['id'=>99,'marketProject'=>['title'=>'Construction','subject'=>'Terrassement']])['titre']==='Construction','Titre depuis projet');
check($adapter->normalizeOpportunity(['id'=>99])['date_limite']===null,'Date inconnue reste inconnue');
check($adapter->normalizeOpportunity(['id'=>99])['reference']==='DGH-99','Référence stable de secours');
check($adapter->normalizeOpportunity(['id'=>99,'launchEndAt'=>'2026-02-31'])['date_limite']===null,'Date source impossible exclue');
check($adapter->normalizeOpportunity(['id'=>99,'marketProject'=>['publishedAt'=>'2026-10-02T00:00:00Z']])['date_publication']==='2026-10-02','Publication héritée du projet');
echo $checks . " vérifications AO réussies.\n";
