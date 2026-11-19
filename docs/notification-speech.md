# Lecture vocale des notifications

La synthèse vocale utilise le Web Speech API du navigateur (`speechSynthesis` et `SpeechSynthesisUtterance`). Aucun fournisseur TTS payant ni nouvelle migration n'est requis.

## Utilisation

Ouvrir `/notifications.php`, déplier **Lecture vocale des notifications**, choisir la voix française, la vitesse et le volume, puis **Tester la voix**. La lecture automatique est désactivée par défaut. Une option permet de lire les nouvelles notifications lorsque FINEA est visible et que la fenêtre est active. Une interaction avec la page est nécessaire après son ouverture pour satisfaire les restrictions du navigateur.

Le bouton **Écouter** est disponible dans le centre de notifications, la liste de la cloche, les toasts système et les popups. **Arrêter la lecture** se trouve dans les préférences et apparaît aussi pendant la lecture sur toutes les pages. Lire une notification ne la marque pas comme lue et ne valide pas les actions/QCM d'une popup.

Les préférences sont enregistrées par identifiant utilisateur dans `localStorage`, sur ce navigateur et cet appareil. Elles ne sont pas synchronisées avec d'autres appareils. Les valeurs saisies sont bornées (vitesse 0,5–2 ; volume 0–1). Les voix françaises sont chargées et actualisées lorsque le navigateur annonce leur disponibilité.

Les notifications historiques chargées au démarrage de la cloche ne sont pas relues automatiquement. La file vocale est limitée à cinq notifications en attente, lues successivement. Une lecture manuelle remplace la file en cours. Le passage en arrière-plan ou la navigation arrête la lecture, sans rejouer les éléments interrompus au retour.

Les identifiants déjà annoncés sont conservés (200 derniers), séparés pour les notifications système et popups. Web Locks coordonne les onglets lorsqu'il est disponible ; sinon la déduplication par stockage local est une protection au mieux. Une fenêtre inactive ne consomme pas les identifiants de la fenêtre active. Aucun polling supplémentaire n'est ajouté.

## Limites

La qualité, le français et la disponibilité de la voix dépendent du navigateur et du système. Certaines voix peuvent utiliser un service réseau fourni par le navigateur. La fonctionnalité ne promet pas une lecture application fermée ou téléphone verrouillé. Sans prise en charge, les boutons vocaux sont désactivés et les notifications continuent de fonctionner.

Le texte est normalisé, le HTML et les scripts sont écartés, les liens sont remplacés par une indication et FCFA/XOF sont développés. La lecture est limitée à 2 500 caractères. Les textes sensibles sont audibles : l'utilisateur garde le choix d'activer la lecture automatique.

## Validation

`tests/NotificationSpeechTest.cjs` simule le moteur vocal dans jsdom : réglages par utilisateur, lecture manuelle, file successive, doublons, voix, masquage de la page, perte de focus, interaction avec les popups, erreurs navigateur et absence de support. Vérifier la voix réellement disponible sur ordinateur et téléphone après déploiement.
