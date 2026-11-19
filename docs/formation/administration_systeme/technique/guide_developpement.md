# Guide de developpement de l Administration systeme

## Nature du module

L Administration systeme n est pas un simple dashboard. C est un ensemble d outils privilegies qui influencent directement la securite, la visibilite portail et la supervision applicative.

## Regles de prudence

- eviter toute simplification excessive des controles d acces ;
- conserver l audit sur les actions sensibles ;
- tester les effets sur les permissions avant mise en production ;
- ne pas exposer un outil admin a un profil metier par erreur.

## Zones sensibles

- edition des utilisateurs ;
- bulk update des permissions ;
- configuration des modules portail ;
- journaux emails et audit ;
- outils techniques base / maintenance.

## Bonnes pratiques

- centraliser les permissions dans AccessManager ;
- garder les ecrans admin explicites et limites ;
- documenter tout nouvel outil admin ;
- verifier les impacts sur selection_portail.php et les cartes visibles.

## Tests minimaux

1. ouvrir dashboard_admin ;
2. modifier un utilisateur test ;
3. verifier gestion_acces ;
4. verifier portal_modules ;
5. verifier email_logs ;
6. verifier un outil technique reserve admin.