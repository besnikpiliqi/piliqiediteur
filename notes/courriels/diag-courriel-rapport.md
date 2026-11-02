# Relecture adversariale du diagnostic « les e-mails ne partent pas » (02/11/2026)

Copie isolée au commit 53ed088 (les fichiers en cause sont identiques au commit 0d2b6a3, HEAD actuel du dépôt : config/mail.php, config/queue.php, EnvoyerDocumentJob, EnvoieLesDocuments, EnvoyerDocumentAction, .env.example, le test et la migration des envois ; le README n'a changé que sur un paragraphe sans rapport, et ses numéros de ligne sont les mêmes). Base SQLite, QUEUE_CONNECTION=database, MAIL_MAILER=smtp, config/mail.php tel quel ('timeout' => null), « php artisan queue:listen » avec ses réglages par défaut. Heures en UTC.

## Verdict

Le diagnostic est confirmé sur le fond. Dans PILIQI, l'envoi d'un ticket, d'une facture, d'un devis ou d'un avoir marche de bout en bout quand un travailleur tourne et que le serveur SMTP répond. « Rien ne s'envoie » s'explique par la file d'attente : soit aucun travailleur ne tourne, soit queue:listen est tombé sur un serveur SMTP qui ne répond pas (même erreur que l'éditeur le 01/11), soit le travailleur a démarré avec d'anciennes valeurs du .env. Dans ces trois cas, l'écran ne dit rien. Les erreurs SMTP (identifiants, TLS, serveur injoignable) se voient au bout d'environ 11 minutes seulement, avec un message trompeur.

Quatre points du premier diagnostic sont à corriger. (1) `public int $timeout` n'a aucun effet sous Windows. (2) Le signal « Oldest pending job » ne voit pas le cas exact de l'éditeur. (3) `(int) env('MAIL_TIMEOUT', 10)` peut donner un délai 0, qui casse tout envoi. (4) La table de lecture des erreurs de la consigne est inexacte pour un port filtré. Le relecteur a aussi trouvé trois défauts de plus : `queue:retry` renvoie un envoi déjà en échec ; un listener lancé avec MAIL_MAILER=log affiche « Envoyé » sans que rien n'arrive dans Mailtrap ; les e-mails envoyés sans file d'attente (inscription…) donnent une erreur HTTP 500.

## Expériences refaites (toutes dans la copie, aucune requête extérieure)

- **E1 : SMTP qui répond, travailleur lancé.** À 02:09:58, le responsable envoie le ticket TK-PARIS-2026-000001 à client-comptoir@exemple.test. Le destinataire n'est pas prérempli ; l'écran répond en 3,4 s « Envoi à … programmé ». La tâche passe (DONE) en 345 ms à 02:10:04. Le message reçu a pour objet « Ticket de caisse TK-PARIS-2026-000001 — Boutique Republique », pour expéditeur « Boutique Republique <test@piliqi.test> », pour adresse de réponse boutique@exemple.test, sans TLS (le serveur local ne propose pas STARTTLS). La pièce jointe ticket-TK-PARIS-2026-000001.pdf fait 879 636 octets. Sans rechargement, la fiche montre encore « En attente » 15 s plus tard. Confirmé.
- **E2 : travailleur arrêté.** À 02:11:05, la propriétaire envoie la facture FAC-PARIS-2026-000001 : même confirmation verte. Après 40 s et un rechargement, la fiche montre toujours « En attente ». La table jobs garde une ligne (attempts 0, non réservée) ; failed_jobs ne bouge pas. Aucune notification n'arrive, et ni le tableau de bord de la société ni celui du magasin ne disent rien. Relance de queue:listen à 02:12:20 : la tâche est prise à 02:12:21 (DONE en 402 ms) et l'e-mail arrive, sans nouvelle demande. Confirmé.
- **E3 : SMTP muet sur 127.0.0.1:2527, queue:listen relancé dès chaque arrêt.** Envoi de la facture à 02:12:50, tâche lancée à 02:12:54, connexion ouverte à 02:12:55. À 02:13:54, le client coupe la connexion après 59,5 s et le listener s'arrête sur « ProcessTimedOutException … exceeded the timeout of 60 seconds. at vendor/symfony/process/Process.php:1212 ». laravel.log ne contient que cette erreur, jamais l'erreur SMTP. Tentatives suivantes : 02:14:27 (coupée à 02:15:28) et 02:15:56 (coupée à 02:16:58). La 4e réservation, à 02:17:29, échoue sur MaxAttemptsExceededException. L'envoi passe alors en « Échec » avec « Une erreur inattendue… », et seul le responsable est prévenu. Même avec des relances immédiates, il faut au moins 3 × 90 s (retry_after), soit 4 min 37 s ici. Sans relance, l'envoi reste « En attente » indéfiniment. Le verrou d'unicité est posé pour 24 h. Confirmé.
- **E4 (nouveau) : listener lancé quand le .env portait MAIL_MAILER=log, puis .env remis à smtp et config:clear.** Le ticket envoyé à 02:18:08 passe en « Envoyé » à 02:18:12. Pourtant le serveur SMTP ne reçoit rien : l'e-mail est écrit en local.DEBUG dans laravel.log (« Subject: Ticket de caisse … »). Un « Envoyé » sur la fiche ne prouve donc pas que le message est arrivé dans Mailtrap.
- **E5 (nouveau) : `php artisan queue:retry` sur la tâche échouée de l'envoi 17**, déjà en « Échec » et dont le responsable avait été prévenu. Avec un SMTP qui répond, l'e-mail repart à 02:18:58 et l'envoi passe en « Envoyé » ; l'erreur est effacée, alors que la notification d'échec reste dans la cloche. Cause : `handle()` ne s'arrête que pour un envoi `Envoye` (EnvoyerDocumentJob.php:121).
- **E6 (nouveau) : serveur qui refuse les identifiants (535) sur 127.0.0.1:2528.** L'avoir AV-PARIS-2026-000001 est envoyé à 02:19:28. 1re tentative : FAIL en 305 ms. La fiche reste « En attente », sans erreur, et la tentative suivante est prévue 60 s plus tard. La 3e tentative a été avancée en remettant available_at à maintenant, pour ne pas attendre 11 min. Résultat final : « Le serveur d'envoi des e-mails n'a pas accepté le message : vérifiez l'adresse du destinataire, puis renvoyez-le. ». C'est faux : ce sont les identifiants de l'éditeur. laravel.log et failed_jobs contiennent « Failed to authenticate on SMTP server with username "utilisateur-fictif" … 535 », donc l'identifiant SMTP, mais jamais le mot de passe.
- **E7 (nouveau) : tâche laissée réservée par un listener tombé** (SMTP muet, puis aucune relance). 100 s après la réservation, au-delà de retry_after, queue:monitor affiche « Pending jobs 0 / Reserved jobs 1 / Oldest pending job N/A » et `creationTimeOfOldestPendingJob('default')` rend NULL. Le listener relancé ensuite sur le bon port fait partir l'e-mail aussitôt (02:25:03).
- **E8 (nouveau) : inscription avec le SMTP muet.** L'e-mail d'inscription part sans file d'attente. Le formulaire répond HTTP 500 au bout de 61,1 s, et la demande d'inscription est déjà enregistrée en base.
- **Essais directs du transport, sans file d'attente.** Chaque essai a donné l'erreur de transport suivante :
  - 2526 qui répond : accepté. La commande tinker de la consigne, tapée telle quelle, n'affiche rien et le message arrive.
  - 2528 (535) : TransportException « Failed to authenticate on SMTP server with username "…" … Expected response code "235" but got code "535" ».
  - 2530 (STARTTLS, certificat autosigné) : TransportException « Unable to connect with STARTTLS: stream_socket_enable_crypto(): SSL operation failed … certificate verify failed ».
  - 2531 (port fermé) : TransportException « Connection could not be established with host "127.0.0.1:2531": … (Connection refused) ».
  - MAIL_SCHEME=tls : UnsupportedSchemeException, qui n'est pas une TransportExceptionInterface, « The "tls" scheme is not supported… ».
  - 2527 (muet), délai 5 s : « Connection to "127.0.0.1:2527" timed out. ».
  - 2529 (port filtré simulé : écoute jamais acceptée, file d'attente pleine, les SYN restent sans réponse), délai 5 s : « Connection could not be established with host "127.0.0.1:2529": … (Connection timed out) ».
  - 'timeout' => 2 sur 2527 : échec en 2 s. 'timeout' => 0 sur 2526, qui répond pourtant : échec immédiat « Unable to read from connection to "127.0.0.1:2526" ».

## Lignes citées par le premier diagnostic : toutes vérifiées

config/mail.php:48 ('timeout' => null, sans variable) ; config/queue.php:43 (retry_after 90) ; EnvoyerDocumentJob.php:96-103 (tries 3, backoff [60, 600], aucun $timeout), :276-277 (messages) ; EnvoieLesDocuments.php:157-175 (section sans rafraîchissement) et :207 (« programmé ») ; aucun wire:poll ni ->poll() dans app ni resources/views ; tests/Feature/Facturation/EnvoyerDocumentJobTest.php:398-404 (535 → « vérifiez l'adresse ») ; README.md:47 et 85-90 ; .env.example:61-64 (« en production, un worker doit tourner »). Dans le code de Laravel 13.34 : ListenCommand --timeout=60 ; SocketStream utilise default_socket_timeout quand aucun délai n'est fixé ; Listener::makeProcess crée l'enfant avec env null, et Symfony Process lui transmet $_ENV, que Dotenv, immuable, ne remplace pas.

## Objections au premier diagnostic

1. **Correction 2 (`$timeout = 45`) : sans effet sous Windows.** Le délai par tâche passe par pcntl (Worker::supportsAsyncSignals() vaut extension_loaded('pcntl'), puis pcntl_alarm). Or pcntl n'existe pas sous Windows. Sur le PC de l'éditeur, seuls le délai SMTP et la durée du PDF bornent une tentative ; sans eux, queue:listen la coupe toujours à 60 s. La correction reste utile en production sous Linux, mais il ne faut pas promettre qu'elle protège le listener sous Windows.
2. **Correction 6 (alerte fondée sur « Oldest pending job ») : aveugle dans le cas de l'éditeur.** `creationTimeOfOldestPendingJob` filtre `whereNull('reserved_at')`. Une tâche coupée avec le listener garde son reserved_at même après retry_after, et le signal reste NULL (E7). Pour l'écran du magasin, il faut lire l'âge de l'envoi « en_attente » de ce magasin (EnvoiDocument, BelongsToMagasin), sans aucune lecture hors du magasin. Pour l'éditeur, une commande doit compter aussi les tâches réservées depuis plus que retry_after. De plus, AlerteEditeur envoie par le même SMTP, sans file d'attente (AlerteEditeur.php:30) : elle ne part pas quand c'est le SMTP qui est en panne. Il faut donc aussi écrire au journal de l'application.
3. **Correction 1 : `(int) env('MAIL_TIMEOUT', 10)` peut valoir 0.** Avec « MAIL_TIMEOUT= » ou « MAIL_TIMEOUT=null », env() rend '' ou null, et non la valeur par défaut. Un délai 0 casse tout envoi, même vers un serveur qui répond (vérifié). Le délai doit rester strictement positif, avec un test.
4. **Table de lecture des erreurs de la consigne : inexacte.**
   - « Connection to "…:2525" timed out » veut dire : connexion TCP acceptée, puis serveur muet (antivirus ou proxy qui intercepte, serveur bloqué). Un Test-NetConnection réussi n'exclut donc pas ce cas.
   - Un port filtré, dont les paquets sont jetés, donne « Connection could not be established with host … (Connection timed out) » (vérifié). Sous Windows, on attend « … A connection attempt failed because the connected party did not properly respond after a period of time … » (non vérifié ici).
   - Un refus sous Windows donne « No connection could be made because the target machine actively refused it » (non vérifié ici).
   - Un nom d'hôte erroné donne « getaddrinfo … failed ».
   - Le message 535 commence par « Failed to authenticate on SMTP server with username "<identifiant Mailtrap>" » : il faut masquer l'identifiant avant de coller la sortie.
5. **« Envoyé » ne prouve pas l'arrivée dans Mailtrap** (E4). La consigne doit faire chercher « Subject: » dans laravel.log et relancer le listener.
6. **php.ini.** PHP 8.5 n'est pas celui que livre XAMPP : il faut trouver le fichier chargé par `php --ini` (« Loaded Configuration File »). `php -d default_socket_timeout=…` ne passe pas aux processus enfants de queue:listen (Listener::phpBinary() appelle PhpExecutableFinder::find(false), sans les options -d).
7. **Inférence non établie.** « C'est pourquoi l'éditeur a pu recevoir son lien d'inscription… » : on ne sait pas si l'éditeur a reçu un e-mail par Mailtrap, et sa société a pu être inscrite avec MAIL_MAILER=log. Avec un SMTP muet, l'inscription échoue au contraire en HTTP 500 (E8). Sous Windows, max_execution_time compte le temps réel (documentation de PHP) et peut couper plus tôt (non vérifié).
8. **Défaut 3, à élargir.** « Vérifiez l'adresse du destinataire » s'affiche aussi pour des identifiants refusés (E6, prouvé au niveau de la tâche), pour un échec STARTTLS ou de certificat, et pour une connexion refusée. MAIL_SCHEME=tls donne « erreur inattendue ».
9. **Durée.** Le cas du listener coupé dure au moins 4 min 37 s (3 × retry_after) avec des relances immédiates ; les 6 min 35 s mesurées par le premier agent comprenaient le temps de ses relances à la main. Le cas avec délai court dure environ 11 min 30 s (backoff 60 + 600 s).

## Défauts ajoutés

- EnvoyerDocumentJob.php:121 : `queue:retry` (ou tout rejeu) renvoie un envoi déjà « Échec » et efface son erreur (E5).
- Envois sans file d'attente sans aucune gestion d'erreur du transport : InscriptionSociete.php:171 et :194, DemandeDeLien (LienPourChoisirSonMotDePasse, canal mail), EnvoiDesLiensDesCabinets.php:45 et :71, InviterSonCabinetACreerSonEspaceAction.php:58, AlerteEditeur.php:30. Résultat : HTTP 500 au bout du délai (E8).
- README.md:41 : « les e-mails s'écrivent dans storage/logs/laravel.log ». C'est faux pour les devis, factures, tickets et avoirs tant qu'aucun travailleur ne tourne.
- EnvoieLesDocuments.php:199-200 : le commentaire promet que la section « Envois » se met à jour sans recharger. C'est vrai seulement pour l'état au moment de la demande.
- Mineur : un PDF d'une page pèse 880 Ko, car DejaVuSans et DejaVuSans-Bold sont embarquées entières (laravel-dompdf sans sous-ensemble de polices, MiseEnPageA4.php:60). Chaque e-mail fait donc 1,2 Mo, ce qui compte dans la taille et le quota d'une boîte Mailtrap.

## Corrections finales pour le lot ML

1. **Délai SMTP réglable.** Dans config/mail.php : `'timeout' => (($t = (int) env('MAIL_TIMEOUT', 10)) > 0 ? $t : 10)`, ou une écriture équivalente qui garantit un délai strictement positif. MAIL_TIMEOUT=10 dans .env.example. Tests :
   - getStream()->getTimeout() vaut 10.0 par défaut ;
   - MAIL_TIMEOUT vide ou « null » donne 10 ;
   - avec un socket local muet (stream_socket_server('tcp://127.0.0.1:0')) et un délai de 1 s, une TransportException « timed out » arrive en moins de 3 s ;
   - aucune connexion extérieure.
2. **Durée maximale de la tâche.** `public int $timeout = 45` dans EnvoyerDocumentJob, avec un test : strictement plus que MAIL_TIMEOUT, et strictement moins que 60 et que retry_after. Dire dans le PHPDoc et le README que ce délai n'agit qu'avec pcntl (Linux, production) : sous Windows, seul MAIL_TIMEOUT empêche queue:listen de couper la tâche.
3. **Une tâche ne traite qu'un envoi en attente.** `handle()` (et `failed()`) ne traitent qu'un envoi `EnAttente`. Un envoi « Envoyé » ou « Échec » ne repart jamais par un rejeu (queue:retry). Test : un envoi en Échec, la tâche relancée, Mail::assertNothingSent(), et le statut et l'erreur restent inchangés.
4. **Message d'échec selon la cause, jamais le texte du serveur.** Classer d'abord par getCode() (535 ou 530 pour les identifiants ; 550, 553 ou 501 au RCPT pour l'adresse), puis par classe (UnsupportedSchemeException pour MAIL_SCHEME ; MaxAttemptsExceededException et TimeoutExceededException pour les tentatives interrompues), puis par motif (« timed out », « could not be established », « STARTTLS » ou « certificate »). Pour une cause qui tient à la plateforme (identifiants, TLS, schéma, serveur injoignable), le message dit « prévenez l'éditeur » ou « renvoyez-le plus tard », jamais de vérifier l'adresse, et l'incident s'écrit au journal de l'application au niveau critique. Test avec un fournisseur de données (data provider) qui reproduit les vrais messages de Symfony, dont « Failed to authenticate … with username "smtp-user" … 535 ». Le test existant EnvoyerDocumentJobTest:398-404 change d'attente (535 donne le message des identifiants) ; son intention, aucun identifiant dans le message, est gardée et renforcée. Le changement est à nommer dans le commit.
5. **Tentative ratée visible.** Colonnes tentatives, derniere_erreur (message traduit) et prochaine_tentative_le, ajoutées dans la migration de création 2026_10_13_100002, puis migrate:fresh. L'heure s'affiche selon le calendrier du magasin. Test au temps figé avec fuseau et withFakeQueueInteractions(). Le backoff [60, 600] d'un envoi demandé par une personne est à soumettre à l'éditeur, avec la mesure de 11 min 30 s avant le verdict.
6. **Fiche à jour sans F5 et confirmation honnête.** wire:poll toutes les 5 s sur ViewFacture, ViewDevis et ViewAvoir tant qu'un envoi est « en_attente », et plus du tout ensuite. Texte : « Envoi à … demandé : il part dans quelques secondes ; son résultat s'affiche dans « Envois » ». Corriger le PHPDoc de confirmerLEnvoi(). Tests Livewire : assertSeeHtml('wire:poll') quand un envoi attend, assertDontSeeHtml('wire:poll') sinon.
7. **Envoi qui attend trop longtemps.**
   - Sur l'écran : un envoi « en_attente » du magasin vieux de plus de 2 minutes, sans tentative en cours ni prévue, affiche « L'envoi attend encore : il partira dès que le service d'envoi reprendra ; sinon vous serez prévenu ». Ce signal se fonde sur l'envoi du magasin, jamais sur la table jobs, commune à toutes les sociétés.
   - Pour l'éditeur : une commande planifiée compte les tâches disponibles depuis plus de 2 min, et aussi celles réservées depuis plus que retry_after, que creationTimeOfOldestPendingJob ignore (E7). Elle écrit au journal au niveau critique, puis prévient par AlerteEditeur une fois par incident (AlerteEditeur échoue si c'est le SMTP qui est en panne).
   - Tests : le cas de la tâche réservée expirée, une tâche de 30 s qui ne déclenche rien, et une seule alerte par incident.
8. **Commande php artisan courriel:essayer {adresse}.**
   - Elle envoie de façon synchrone par le transport configuré, avec MAIL_TIMEOUT.
   - Elle nomme le pilote actif ; avec MAIL_MAILER=log, elle dit que le message va dans laravel.log.
   - Elle dit « accepté par le serveur », ou la cause en français avec son remède, classée comme au point 4 : port filtré, serveur muet, connexion refusée, nom d'hôte, identifiants, STARTTLS ou certificat, MAIL_SCHEME.
   - Elle n'affiche jamais le message brut de Symfony, qui contient l'identifiant, ni MAIL_USERNAME ni MAIL_PASSWORD.
   - Elle signale une configuration en cache (app()->configurationIsCached()) et l'état de la file : en attente, en attente de nouvelle tentative, réservées expirées. Elle rappelle de relancer queue:listen après tout changement du .env.
   - Tests sans réseau : succès ; socket muet (délai 1 s, moins de 5 s) ; port fermé ; schéma tls ; un faux serveur local qui répond 535. La sortie ne contient jamais l'identifiant ni le mot de passe fictifs.
9. **Envois sans file d'attente.** Inscription (InscriptionSociete), lien pour choisir son mot de passe (DemandeDeLien), invitations et demandes de rattachement d'un cabinet, invitation à créer un espace : attraper TransportExceptionInterface et afficher un message en français (« l'e-mail n'a pas pu partir, réessayez dans quelques minutes »), jamais une erreur 500. Le délai est borné par MAIL_TIMEOUT. Tests avec un transport qui lève l'exception.
10. **README et .env.example.**
   - La file d'attente doit tourner en développement comme en production, même avec MAIL_MAILER=log (corriger README.md:41 et .env.example:61-64).
   - queue:listen doit rester ouvert ; après tout changement du .env ou du php.ini : Ctrl+C, php artisan optimize:clear, puis relancer serve et queue:listen. Un listener garde le .env lu à son démarrage, mailer compris (E4).
   - Expliquer MAIL_TIMEOUT, et le délai de 60 s de queue:listen, seul délai par tâche sous Windows.
   - Pour Mailtrap : ports 2525 et 587, Test-NetConnection, courriel:essayer, queue:monitor, queue:failed, Select-String ; jamais MAIL_SCHEME=tls, jamais queue:retry.
   - Test : chaque clé env() de config/mail.php figure dans .env.example.
11. **Facultatif, à soumettre à l'éditeur.** Sous-ensemble de polices pour les PDF : 880 Ko pour une page, 1,2 Mo par e-mail.

## Consigne finale pour l'éditeur (PC Windows, PowerShell, C:\xampp\htdocs\piliqiediteur-v2)

0. **Trois fenêtres PowerShell restent ouvertes en permanence :** `php artisan serve`, `php artisan queue:listen` et `php artisan schedule:work`. Si la fenêtre de queue:listen est fermée, ou s'est arrêtée sur « ProcessTimedOutException », aucun devis, facture, ticket ni avoir ne part : ils restent « En attente » sans aucun message, et partent seuls dès que queue:listen est relancé.
1. **Le .env** (ne jamais coller le mot de passe dans un message) :
   ```
   MAIL_MAILER=smtp
   MAIL_SCHEME=null
   MAIL_HOST=sandbox.smtp.mailtrap.io
   MAIL_PORT=2525
   MAIL_USERNAME=<identifiant de la boîte Mailtrap, onglet SMTP>
   MAIL_PASSWORD=<mot de passe de cette boîte>
   MAIL_FROM_ADDRESS="piliqi@exemple.test"
   QUEUE_CONNECTION=database
   ```
   Jamais `MAIL_SCHEME=tls`. MAIL_ENCRYPTION est ignoré.
2. **En attendant la correction de PILIQI,** lancer `php --ini`, ouvrir le fichier indiqué sur la ligne « Loaded Configuration File », y mettre `default_socket_timeout=15`, puis vérifier avec `php -r "echo ini_get('default_socket_timeout');"`, qui doit afficher 15. Ne pas passer par `php -d …` : le réglage n'arrive pas aux tâches lancées par queue:listen.
3. **Après chaque changement du .env ou du php.ini :** Ctrl+C dans les fenêtres de serve et de queue:listen, puis `php artisan optimize:clear`, puis relancer les deux. Un queue:listen déjà lancé garde les anciennes valeurs, MAIL_MAILER et MAIL_PORT compris.
4. **Essai direct, sans file d'attente :**
   `php artisan tinker --execute "Mail::to('essai@exemple.test')->send((new Illuminate\Mail\Mailable)->subject('Essai SMTP PILIQI')->html('Essai'));"`
   Si rien ne s'affiche et que le message est dans Mailtrap, le SMTP est bon. Sinon, lire l'erreur, et masquer l'identifiant Mailtrap avant de la copier :
   - « Connection to "sandbox.smtp.mailtrap.io:2525" timed out » : la connexion est acceptée mais rien ne répond (antivirus ou proxy qui intercepte) : désactiver la protection des e-mails de l'antivirus, puis essayer le port 587.
   - « Connection could not be established … timed out » ou « … did not properly respond after a period of time … » : le port est filtré (pare-feu, réseau) : essayer le port 587.
   - « … Connection refused » ou « … actively refused it » : l'hôte ou le port est faux.
   - « getaddrinfo … failed » : le nom d'hôte est mal écrit, ou le DNS ne répond pas.
   - « Failed to authenticate … 535 » : l'identifiant ou le mot de passe Mailtrap est faux (ceux de l'onglet SMTP de la boîte, pas un jeton d'API).
   - « Unable to connect with STARTTLS … certificate verify failed » : l'antivirus inspecte le TLS : désactiver sa protection des e-mails.
   - « The "tls" scheme is not supported » : corriger MAIL_SCHEME.
   - Un message qui parle de limite ou de quota : la boîte Mailtrap gratuite est pleine (non vérifiable ici).
5. **Réseau :** `Test-NetConnection sandbox.smtp.mailtrap.io -Port 2525`, puis `-Port 587`. « False » veut dire un port filtré. « True » n'exclut pas un antivirus qui accepte la connexion puis se tait.
6. **File d'attente :** `php artisan queue:monitor default`.
   - « Pending jobs » supérieur à 0 sans que rien ne bouge : aucun travailleur ne tourne.
   - « Reserved jobs » à 1 et queue:listen arrêté : la tâche a été coupée ; relancer queue:listen, elle repart au bout de 90 s.
   - « Delayed jobs » : une nouvelle tentative est prévue, dans 1 min puis dans 10 min.
7. **Fiche du document, après F5,** section « Envois » :
   - « En attente » : le travailleur n'est pas passé, voir le point 6.
   - « Envoyé » mais rien dans Mailtrap : lancer `Get-Content storage\logs\laravel.log -Tail 400 | Select-String "Subject:"`. Si le document y figure, queue:listen avait été lancé avec MAIL_MAILER=log : refaire le point 3.
   - « Échec » : le texte affiché peut être trompeur tant que PILIQI n'est pas corrigé (« vérifiez l'adresse » s'affiche aussi pour des identifiants refusés). Lire `php artisan queue:failed`, puis `Get-Content storage\logs\laravel.log -Tail 300 | Select-String "local.ERROR"`.
8. **Pour renvoyer un document,** utiliser « Envoyer par e-mail » sur la fiche, jamais `php artisan queue:retry` : c'est prouvé, cela fait repartir un envoi déjà en « Échec » sans nouvelle demande.
9. **Pour un ticket,** le client comptoir n'a pas d'adresse : il faut taper le destinataire.

## Nettoyage

Le serveur web 8766, le travailleur, les SMTP 2526 et 2527 et les serveurs de la relecture (2528, 2529, 2530) ont été arrêtés. Le worktree a été retiré par `git worktree remove --force`, puis `git worktree prune` a été lancé. Le dossier diag-courriel a été supprimé ; seul ce rapport reste. Rien n'a été modifié dans /home/user/piliqiediteur ni dans diag-419 (seules des lectures : git log, git show, cat et diff de fichiers). Aucune requête n'est partie vers un fournisseur externe et aucun e-mail n'est sorti de la machine.
