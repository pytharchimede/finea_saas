<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
require_once dirname(__DIR__).'/src/Services/TenantService.php';
$pdo=require_admin();
$error=''; $company=''; $legal=''; $primary='#173B68'; $accent='#D9A441';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 if (!check_csrf()) { http_response_code(403); $error='Session expirée. Recharge la page.'; }
 else {
  $company=trim(is_string($_POST['company_name']??null)?$_POST['company_name']:'');
  $legal=trim(is_string($_POST['legal_name']??null)?$_POST['legal_name']:'');
  $primary=is_string($_POST['primary_color']??null)?$_POST['primary_color']:'';
  $accent=is_string($_POST['accent_color']??null)?$_POST['accent_color']:'';
  try {
   $id=(new \Finea\Saas\Services\TenantService())->create($company,$legal,$primary,$accent);
   $_SESSION['csrf']=bin2hex(random_bytes(32));
   header('Location: company.php?id='.$id.'&created=1',true,303);exit;
  } catch (\RuntimeException $e) { $error=$e->getMessage(); }
    catch (\Throwable $e) { error_log('FINEA SaaS tenant create: '.$e->getMessage()); $error='Création impossible. Consulte les journaux du serveur.'; }
 }
}
shell_top('Nouvelle entreprise','Créez un espace client et personnalisez son identité dès son inscription.','new');
echo '<div class="actions" style="margin-bottom:22px"><a class="btn" href="dashboard.php">← Retour aux entreprises</a></div>';
if ($error) echo '<div class="notice error">'.esc($error).'</div>';
echo '<div class="split"><section class="panel"><h2>Informations de l’entreprise</h2><p class="sub">L’identifiant, l’UUID et le nom de base seront générés automatiquement.</p><form method="post"><input type="hidden" name="csrf" value="'.esc(csrf_token()).'"><div class="form-grid"><div class="field full"><label for="company_name">Nom commercial *</label><input id="company_name" name="company_name" maxlength="190" required placeholder="Ex. IBTP" value="'.esc($company).'"></div><div class="field full"><label for="legal_name">Raison sociale</label><input id="legal_name" name="legal_name" maxlength="255" placeholder="Ex. IBTP SARL" value="'.esc($legal).'"></div><div class="field"><label for="primary_color">Couleur principale</label><input id="primary_color" type="color" name="primary_color" value="'.esc(preg_match('/^#[0-9a-fA-F]{6}$/',$primary)?$primary:'#173B68').'"></div><div class="field"><label for="accent_color">Couleur d’accent</label><input id="accent_color" type="color" name="accent_color" value="'.esc(preg_match('/^#[0-9a-fA-F]{6}$/',$accent)?$accent:'#D9A441').'"></div></div><div class="form-actions"><button class="btn primary" type="submit">Créer l’entreprise →</button></div></form></section><aside class="panel"><h2>Parcours d’activation</h2><p class="sub">Un onboarding structuré, sans intervention technique pour créer la fiche.</p><div class="keyval"><span>01 · Création</span><span>Identité et branding</span></div><div class="keyval"><span>02 · Responsable</span><span>Premier administrateur</span></div><div class="keyval"><span>03 · Infrastructure</span><span>Provisionnement isolé</span></div><div class="keyval"><span>04 · Activation</span><span>Accès à FINEA</span></div><div class="notice">L’entreprise restera en attente jusqu’à la création effective de son environnement métier. Aucun accès ERP fictif ne sera créé.</div></aside></div>';
shell_bottom();
