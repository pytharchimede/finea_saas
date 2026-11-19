<?php
declare(strict_types=1);
require __DIR__.'/ContractWorkflowEndToEndTest.php';
// Reuse the isolated fixture, then exercise automatic operation across cron cycles.
$pdo->exec("DELETE FROM contract_whatsapp_templates;DELETE FROM contract_whatsapp_outbox;UPDATE contract_whatsapp_settings SET enabled=0,activated_at=NULL,auto_activation=1;UPDATE contrat_sous_traitance SET statut='brouillon',signature_banamur=NULL,signature_prestataire=NULL;UPDATE contrat_workflow_etape SET statut=CASE WHEN etape='rh' THEN 'en_cours' ELSE 'en_attente' END,signature=NULL");
$api=new ContractFakeApi();$channel=new ContractFakeChannel();$wa=new ContractWhatsAppService($pdo,$api,$channel);$wa->provision();
$models=$pdo->query('SELECT template_key,content_sid FROM contract_whatsapp_templates')->fetchAll(PDO::FETCH_KEY_PAIR);
foreach($models as $sid)$api->approvals[$sid]=['status'=>'pending','category'=>'UTILITY','reason'=>''];
$before=$count;
$state=$wa->runCycle();check(!$state['enabled']&&$state['automatic'],'All-pending installation keeps monitoring without requiring enable.');check($channel->sends===0,'No unapproved sends.');check((int)$pdo->query('SELECT COUNT(*) FROM contract_whatsapp_outbox')->fetchColumn()===1,'Notifications wait while every model is pending.');
$api->approvals[$models['rh']]['status']='approved';$wa->poll(true);$state=$wa->runCycle();check($state['enabled']&&$state['approved_count']===1,'A single Utility approval activates its own stage.');check($channel->sends===1,'RH sends without nine other approvals.');
$wa->runCycle();check($channel->sends===1,'Repeated automatic cycles preserve deduplication.');
$pdo->exec("UPDATE contrat_workflow_etape SET statut='validee',signature='signed' WHERE etape='rh';UPDATE contrat_workflow_etape SET statut='en_cours' WHERE etape='juridique'");
$wa->runCycle();check($channel->sends===1,'Next stage waits for its own approval.');
$api->approvals[$models['juridique']]['status']='approved';$wa->poll(true);$wa->runCycle();check($channel->sends===2,'Newly approved stage drains its waiting notification.');
$wa->setAutomatic(false);$pdo->exec("UPDATE contrat_workflow_etape SET statut='validee',signature='signed' WHERE etape='juridique';UPDATE contrat_workflow_etape SET statut='en_cours' WHERE etape='technique'");$api->approvals[$models['technique']]['status']='approved';$wa->poll(true);$state=$wa->runCycle();check(!$state['enabled']&&!$state['automatic'],'Manual pause survives new approvals.');check($channel->sends===2,'Manual pause stops delivery.');
$wa->setAutomatic(true);$wa->runCycle();check($channel->sends===3,'Resume drains the current approved stage.');
$pdo->exec("UPDATE contrat_workflow_etape SET donnees_json=donnees_json||' ' WHERE etape='technique'");$api->approvals[$models['technique']]['status']='disabled';$api->approvals[$models['revision']]['status']='approved';$api->approvals[$models['revision']]['category']='MARKETING';$wa->poll(true);$state=$wa->runCycle();check($channel->sends===3,'Disabled and recategorized templates cannot send new messages.');
$api->approvals[$models['revision']]['category']='UTILITY';$wa->poll(true);$wa->runCycle();check($channel->sends===4,'Utility reapproval resumes its pending revision only.');
$api->fail=true;$pdo->exec('UPDATE contract_whatsapp_templates SET checked_at=NULL');$state=$wa->runCycle();check(!$state['enabled']&&$state['automatic'],'Provider failures suspend sending but retain automatic monitoring.');
check($channel->sends===4,'Unverifiable approval cannot send.');
echo ($count-$before)." automatic activation assertions passed.\n";
