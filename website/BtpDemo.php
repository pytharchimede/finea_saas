<?php
declare(strict_types=1);
final class BtpDemo {
 public static function ids():array {return array_map(static fn($key)=>md5('finea-btp-demo-'.$key),['building','road','bridge','earth','water','team']);}
 public static function asset(string $id):?string {foreach(['building','road','bridge','earth','water','team'] as $key)if(hash_equals(md5('finea-btp-demo-'.$key),$id))return __DIR__.'/demo/'.$key.'.svg';return null;}
 public static function photo(string $id):?string {
 foreach(['building'=>35846752,'road'=>2833686,'bridge'=>4254898,'earth'=>14546539,'water'=>20296265,'team'=>20609453] as $key=>$number)if(hash_equals(md5('finea-btp-demo-'.$key),$id))return 'https://images.pexels.com/photos/'.$number.'/pexels-photo-'.$number.'.jpeg?auto=compress&cs=tinysrgb&w=1600';
 return null;
 }
 public static function content(array $brand):array {
 $name=$brand['company_name'];$d=SiteContent::defaults($brand);
 $entry=static fn($title,$body,$key,$category='',$location='')=>['title'=>$title,'body'=>$body,'image'=>md5('finea-btp-demo-'.$key),'category'=>$category,'location'=>$location];
 $d['headline']='Construire aujourd’hui les infrastructures de demain';
 $d['tagline']='Bâtiment · Génie civil · Travaux publics';
 $d['intro']='De la préparation du chantier à la réception des ouvrages, découvrez une approche structurée de la construction, centrée sur la qualité, la sécurité et la maîtrise des délais.';
 $d['about']=$name." — modèle de présentation BTP à personnaliser.\nNotre approche couvre l’étude des besoins, la préparation technique, la mobilisation des équipes, le suivi des travaux et la réception des ouvrages. Chaque projet appelle une organisation adaptée à ses contraintes techniques, environnementales et économiques.\nLes domaines, références et engagements présentés ici constituent un modèle éditorial de démonstration à valider par l’entreprise.";
 $d['hero_image']=md5('finea-btp-demo-building');$d['about_image']=md5('finea-btp-demo-team');
 $d['seo_title']=$name.' | Bâtiment, génie civil et travaux publics';$d['seo_description']='Découvrez les domaines d’intervention, les projets et les engagements de notre site de démonstration BTP.';
 $d['services']=[
 $entry('Construction de bâtiments','Études préparatoires, gros œuvre, second œuvre, réhabilitation et coordination des corps d’état.','building'),
 $entry('Travaux routiers','Préparation des plateformes, chaussées, voiries et aménagements de circulation.','road'),
 $entry('Génie civil','Ouvrages en béton, infrastructures et structures techniques adaptées aux contraintes du projet.','bridge'),
 $entry('Terrassement','Décapage, déblais, remblais, nivellement et préparation des plateformes.','earth'),
 $entry('VRD et assainissement','Réseaux divers, drainage, évacuation des eaux et aménagements extérieurs.','water'),
 $entry('Études et coordination technique','Analyse des besoins, planification, suivi technique et contrôle des travaux.','team')];
 $d['slides']=[$entry('Des bâtiments conçus pour durer','Une approche intégrée du chantier, de la préparation à la réception.','building'),$entry('Relier les territoires','Des solutions pour les infrastructures et la mobilité.','road'),$entry('Maîtriser les ouvrages complexes','Préparation, coordination et contrôle technique.','bridge')];
 $d['projects']=[
 $entry('Immeuble de bureaux — exemple','Exemple fictif de fiche de chantier : préparation du terrain, fondations, structure et finitions. À remplacer par une référence réelle.','building','Bâtiment','Projet fictif'),
 $entry('Aménagement de voirie — exemple','Exemple fictif : terrassement, drainage et réalisation de chaussée.','road','Travaux publics','Projet fictif'),
 $entry('Ouvrage de franchissement — exemple','Exemple fictif : études, fondations, tablier et contrôle de réception.','bridge','Génie civil','Projet fictif'),
 $entry('Réseau de drainage — exemple','Exemple fictif : pose de conduites et aménagement des exutoires.','water','VRD','Projet fictif')];
 $d['values']=[['title'=>'Qualité','Contrôler les matériaux et les ouvrages à chaque étape.'],['title'=>'Sécurité','Préparer les interventions et prévenir les risques professionnels.'],['title'=>'Environnement','Limiter les nuisances et organiser la gestion des déchets.'],['title'=>'Transparence','Documenter les décisions, les délais et les écarts.']];
 $d['figures']=[['title'=>'6','Domaines illustrés dans ce modèle'],['title'=>'4','Exemples de projets fictifs'],['title'=>'QHSE','Axes d’engagement proposés']];
 $d['news']=[$entry('Préparer un chantier : les étapes essentielles','L’analyse des plans, la reconnaissance du site, la planification et la prévention des risques sont des étapes importantes avant le démarrage des travaux.','team','Conseils techniques'),$entry('La sécurité au cœur de la préparation','Les équipements adaptés, les consignes et les contrôles contribuent à la prévention des accidents sur les chantiers.','building','QHSE')];
 $d['partners']=[$entry('Maîtres d’ouvrage','Espace destiné aux références clients validées par l’entreprise. Aucun client réel n’est revendiqué.','building','Exemple'),$entry('Partenaires techniques','Espace destiné aux bureaux d’études, fournisseurs et sous-traitants autorisés.','team','Exemple')];
 $d['careers']=[$entry('Métiers du chantier','Présentation des métiers de conducteur de travaux, chef de chantier et technicien. Ceci n’est pas une offre d’emploi ouverte.','team','Information'),$entry('Métiers des études','Présentation des fonctions de préparation, méthodes et études techniques. Ceci n’est pas une offre d’emploi ouverte.','bridge','Information')];
 $d['contact_form']=true;$d['demo']=true;return $d;
 }
}
