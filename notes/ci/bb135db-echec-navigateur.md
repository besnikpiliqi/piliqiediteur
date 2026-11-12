# CI rouge sur bb135db (lot AUD poussé), 12/11/2026 07 h 23 UTC

- Course 43170351074. Seul le job « PHP 8.4, PostgreSQL 17 » échoue : 1 test sur 6 226.
  - Il échoue dans tests/Browser/Parcours/TelechargerLesDocumentsTest.php:315 :
    « le bouton « Télécharger » de la notification fait télécharger le fichier, et un lien expiré mène à une page qui ramène à l'accueil ».
  - Le texte « Vos documents du 01/03/2026 au 31/03/2026 sont prêts » n'est pas vu dans la cloche, dans le délai de 15 s (Navigation::DELAI).
  - Les jobs 8.3/PG17, 8.3/PG18, 8.4/PG18 et 8.5/PG17 sont verts. Le job 8.5/PG18 a été annulé par l'échec.
- Cause (lue dans le code, non encore rejouée, pour ne pas charger la machine pendant la suite du lot DEMO) :
  - Le composant de la cloche (Filament DatabaseNotifications) ne se recharge que de deux façons : par son interrogation toutes les 30 s (`$pollingInterval = '30s'`, non réglé dans les panneaux), ou par l'événement Livewire `databaseNotificationsSent`.
  - Dans les tests, la file est `sync` : l'avis est créé pendant l'action « Télécharger » de TelechargeLesDocumentsParPeriode.
  - Mais rien ne prévient la cloche, déjà rendue au chargement de la page. Le test ne passe donc que si une interrogation de 30 s tombe dans les 15 s qui suivent le clic sur la cloche : il dépend de l'horloge.
- Correction proposée (code, pas test) : après l'envoi du job dans l'action « Télécharger », `$this->dispatch('databaseNotificationsSent')`. La cloche se recharge aussitôt quand l'avis est déjà là (file sync, ou job rapide) ; sinon, l'interrogation de 30 s le montre comme aujourd'hui.
- Qui répare : la chaîne. La consigne commune (serie9-rapide.js l. 34) fait revérifier la CI à l'agent DEMO:implementation avant son dernier commit, et réparer d'abord une CI rouge, dans un commit à part. Je contrôle à l'envoi de DEMO que la réparation y est. Sinon, je la fais faire.

## Contrôle de 08 h 10 UTC : l'hypothèse des 30 s est FAUSSE

- Rejoué dans l'arbre jetable wt-demo, à d23eb96, base piliqi_verif, avec un autoload Composer propre à cet arbre :
  - sans correction : 2 réussites sur 2 ;
  - avec la correction (`databaseNotificationsSent`) : 3 sur 3 ;
  - interrogation de la cloche coupée (`databaseNotificationsPolling(null)`), avec ou sans la correction : les deux passent ;
  - interrogation forcée à 1 s : 3 sur 3.
- La cloche reçoit donc l'avis sans l'interrogation. La correction proposée ne change rien et n'est pas poussée.
- La capture de la CI (artefact captures-php-8.4-postgresql-17) montre la cloche avec le badge « 2 », mais sa fenêtre fermée. Le clic sur la cloche n'a pas pris, ou la fenêtre s'est refermée. Cause non trouvée.
- Suite : la CI de l'envoi du lot DEMO rejoue ce test sur les 5 configurations ; elle tient lieu de l'unique nouvel essai permis. S'il échoue de nouveau, il faut chercher plus loin : clic couvert par l'avis « Votre fichier est en préparation. », ou fenêtre fermée par un redessin.
