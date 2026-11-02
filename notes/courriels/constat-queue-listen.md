# Constat de l'éditeur du 01/11/2026 : `queue:listen` coupé au bout de 60 secondes

## Message de l'éditeur (PC Windows, XAMPP, `.env` réglé sur la boîte de test SMTP de Mailtrap)

```
PS C:\xampp\htdocs\piliqiediteur-v2> php artisan queue:listen
   INFO  Processing jobs from the [default] queue.

   Symfony\Component\Process\Exception\ProcessTimedOutException
  The process "C:\xampp\php\php.exe artisan queue:work --once "--name=default" "--queue=default" "--backoff=0" "--memory=128" "--sleep=3" "--tries=1"" exceeded the timeout of 60 seconds.
  at vendor\symfony\process\Process.php:1212
```

## Cause probable (lecture du code, non reproduite sur le PC de l'éditeur)

- `queue:listen` lance chaque tâche dans un processus `queue:work --once` qu'il coupe au bout de `--timeout`, soit 60 secondes par défaut ; la coupure fait tomber le listener lui-même.
- La tâche d'envoi d'un document (`app/Domain/Facturation/Jobs/EnvoyerDocumentJob.php`) fabrique le PDF (dompdf, sans ressource distante) puis l'envoie par `Mail::to(...)->send(...)`.
- `config/mail.php`, transport `smtp` : `'timeout' => null`. Sans délai, la connexion SMTP attend `default_socket_timeout` de PHP, soit 60 secondes par défaut : exactement le délai du listener. Un serveur SMTP qui ne répond pas (port 2525 bloqué par le réseau, protection des e-mails d'un antivirus) fait donc tomber le listener sans jamais montrer l'erreur SMTP.
- Diagnostic donné à l'éditeur : `Test-NetConnection sandbox.smtp.mailtrap.io -Port 2525` (puis 587), `php artisan queue:work --once` (non coupé sous Windows, faute de pcntl), dernière erreur de `storage\logs\laravel.log`, et savoir si l'e-mail d'inscription (envoyé sans file d'attente) arrive.

## Ce que le lot ML doit couvrir en plus

1. Un délai SMTP réglable, `MAIL_TIMEOUT` lu par `config/mail.php` pour le transport `smtp`, court par défaut (de l'ordre de 10 à 15 secondes, nettement sous les 60 secondes du listener et de `retry_after`), pour qu'un serveur muet fasse échouer l'envoi vite, avec l'erreur du transport dans le journal et dans les tâches échouées, au lieu de faire tomber le listener ; test qui lit la configuration.
2. La commande d'e-mail d'essai (point 2 du cahier) dit l'erreur exacte d'un serveur muet ou d'un port bloqué (délai dépassé, connexion refusée, certificat), sans jamais afficher de secret, avec le remède en français (port 587 au lieu de 2525, protection des e-mails de l'antivirus, `php artisan optimize:clear` après un changement du `.env`) ; test avec un serveur qui ne répond pas (socket local qui accepte sans répondre, ou transport simulé), sans aucune connexion réseau extérieure.
3. README, étape « Lancement » et réglage Mailtrap du PC : la commande de la file d'attente en développement (`php artisan queue:listen`, ce que fait son `--timeout`, et `php artisan queue:work --once` pour voir l'erreur d'une tâche) et la marche à suivre quand le port SMTP est bloqué (Windows : `Test-NetConnection sandbox.smtp.mailtrap.io -Port 2525`, puis 587).


## Diagnostic vérifié des envois d'e-mails (atelier wf_df3308cd-c49, 02/11/2026 ; rapport complet : `courriels/diag-courriel-rapport.md`)

Constat de l'éditeur du 02/11/2026 : « tous les envois des mails ils s'envoient pas, comme ex une facture ou ticket ou devis donc rien ne s'envoie ». Ensuite, avec `QUEUE_CONNECTION=sync`, son devis est arrivé dans Mailtrap avec son PDF joint.

Conclusion vérifiée (un agent, puis un vérificateur qui a refait les essais) : dans PILIQI, l'envoi d'un ticket, d'une facture, d'un devis ou d'un avoir marche de bout en bout quand un travailleur de file d'attente tourne et que le serveur SMTP répond (11 e-mails reçus sur un serveur SMTP local, PDF joints, propriétaire et responsable). « Rien ne s'envoie » vient de la file d'attente : aucun travailleur (l'envoi reste « En attente » sans aucun signal), `queue:listen` qui tombe sur un SMTP muet (délai SMTP `null`, donc 60 s, autant que `queue:listen`), ou un travailleur lancé avec un ancien `.env`. Les autres erreurs SMTP n'apparaissent qu'après environ 11 minutes, avec un message faux (« vérifiez l'adresse du destinataire »).

### Ce que le lot ML doit corriger en plus (corrections finales du vérificateur, qui priment sur les trois points ci-dessus quand elles les précisent)

1. Délai SMTP réglable et toujours positif. Dans config/mail.php, transport smtp : 'timeout' => (($t = (int) env('MAIL_TIMEOUT', 10)) > 0 ? $t : 10), et MAIL_TIMEOUT=10 dans .env.example. Tests :
- getSymfonyTransport()->getStream()->getTimeout() vaut 10.0 ;
- MAIL_TIMEOUT vide ou « null » donne 10 ;
- avec un socket local muet (stream_socket_server('tcp://127.0.0.1:0')) et un délai de 1 s, une TransportException « timed out » arrive en moins de 3 s (hrtime) ;
- aucune connexion extérieure.
2. Durée maximale de la tâche : public int $timeout = 45 dans EnvoyerDocumentJob. Test : strictement plus que MAIL_TIMEOUT, strictement moins que 60 et que retry_after. Le PHPDoc et le README disent qu'il n'agit qu'avec pcntl (Linux, production) : sous Windows, seul MAIL_TIMEOUT empêche queue:listen de couper la tâche.
3. Une tâche ne traite qu'un envoi en attente. handle() et failed() ne traitent qu'un envoi EnAttente : un envoi Envoyé ou Échec ne repart jamais par un rejeu (queue:retry, prouvé en E5). Test : un envoi en Échec, la tâche relancée, Mail::assertNothingSent(), le statut et l'erreur inchangés.
4. Message d'échec selon la cause, jamais le texte brut du serveur.
- Classer par getCode() : 535 ou 530 pour les identifiants ; 550, 553 ou 501 pour une adresse refusée.
- Puis par classe : UnsupportedSchemeException pour MAIL_SCHEME ; MaxAttemptsExceededException et TimeoutExceededException pour les tentatives interrompues.
- Puis par motif : « timed out », « could not be established », « STARTTLS » ou « certificate ».
- Une cause qui tient à la plateforme (identifiants, TLS, schéma, serveur injoignable) ne dit jamais de vérifier l'adresse ; elle dit « renvoyez-le plus tard » ou « prévenez l'éditeur », et l'incident s'écrit au journal au niveau critique.
- Test avec un fournisseur de données (data provider) qui reproduit les vrais messages de Symfony, dont « Failed to authenticate … with username "smtp-user" … 535 », dans envoi->erreur et dans la notification, sans l'identifiant.
- L'attente du test EnvoyerDocumentJobTest:398-404 change (535 donne le message des identifiants) ; son intention, aucun identifiant dans le message, est gardée. À nommer dans le commit et à signaler à l'éditeur.
5. Tentative ratée visible. Colonnes tentatives, derniere_erreur (message traduit) et prochaine_tentative_le, ajoutées dans la migration de création 2026_10_13_100002_create_envois_documents_table, puis migrate:fresh. La section « Envois » affiche par exemple « En attente — le serveur d'envoi ne répond pas, nouvelle tentative vers HH:MM », à l'heure du calendrier du magasin. Test au temps figé avec fuseau, withFakeQueueInteractions(), TransportException « timed out » : le statut reste en_attente, tentatives vaut 1, et l'heure de la tentative suivante est juste. Le backoff [60, 600] d'un envoi demandé par une personne (11 min 30 s mesurées avant le verdict) est à soumettre à l'éditeur.
6. Fiche à jour sans F5. ViewFacture, ViewDevis et ViewAvoir se rafraîchissent (wire:poll toutes les 5 s) tant qu'un envoi est en_attente, et plus du tout ensuite. La confirmation devient « Envoi à … demandé : il part dans quelques secondes ; son résultat s'affiche dans « Envois » ». Corriger le PHPDoc de confirmerLEnvoi() (EnvoieLesDocuments.php:199-200). Tests Livewire : assertSeeHtml('wire:poll') quand un envoi attend, assertDontSeeHtml('wire:poll') sinon.
7. Envoi qui attend trop longtemps.
- Sur l'écran : un envoi en_attente du magasin vieux de plus de 2 min, sans tentative en cours ni prévue, affiche « L'envoi attend encore : il partira dès que le service d'envoi reprendra ; sinon vous serez prévenu ». Le signal se fonde sur l'EnvoiDocument du magasin (BelongsToMagasin), jamais sur la table jobs, commune à toutes les sociétés.
- Pour l'éditeur : une commande planifiée compte les tâches disponibles depuis plus de 2 min et celles réservées depuis plus que retry_after, que creationTimeOfOldestPendingJob ignore (E7). Elle écrit au journal au niveau critique, puis appelle AlerteEditeur une fois par incident ; AlerteEditeur passe par le même SMTP et ne part pas si c'est lui qui est en panne.
- Tests : le cas de la tâche réservée expirée, une tâche de 30 s qui ne déclenche rien, une seule alerte par incident.
8. Commande php artisan courriel:essayer {adresse}.
- Elle envoie de façon synchrone par le transport configuré, avec MAIL_TIMEOUT.
- Elle nomme le pilote actif : avec MAIL_MAILER=log, elle dit que le message va dans laravel.log.
- Elle dit « accepté par le serveur », ou la cause en français avec son remède, classée comme au point 4 : port filtré, serveur muet, connexion refusée, nom d'hôte, identifiants, STARTTLS ou certificat, MAIL_SCHEME.
- Elle n'affiche jamais le message brut de Symfony, qui contient l'identifiant, ni MAIL_USERNAME ni MAIL_PASSWORD.
- Elle signale une configuration en cache (configurationIsCached()) et l'état de la file : en attente, en attente de nouvelle tentative, réservées expirées. Elle rappelle de relancer queue:listen après tout changement du .env.
- Tests sans réseau : succès (code 0) ; socket muet (délai 1 s, moins de 5 s, code 1) ; port fermé ; schéma tls ; faux serveur local qui répond 535. La sortie ne contient jamais l'identifiant ni le mot de passe fictifs.
9. E-mails envoyés sans file d'attente. Inscription (InscriptionSociete.php:171 et :194), LienPourChoisirSonMotDePasse (DemandeDeLien), invitations et demandes de rattachement (EnvoiDesLiensDesCabinets.php:45 et :71), InviterSonCabinetACreerSonEspaceAction.php:58 : attraper TransportExceptionInterface et afficher un message en français, jamais une erreur 500 (E8). Le délai est borné par MAIL_TIMEOUT. Tests avec un transport qui lève l'exception.
10. README et .env.example.
- La file doit tourner en développement comme en production, même avec MAIL_MAILER=log (corriger README.md:41 et .env.example:61-64).
- queue:listen doit rester ouvert ; après tout changement du .env ou du php.ini : Ctrl+C, php artisan optimize:clear, puis relancer serve et queue:listen. Un listener garde le .env lu à son démarrage, mailer et port compris.
- Expliquer MAIL_TIMEOUT, et le délai de 60 s de queue:listen, seul délai par tâche sous Windows (pas de pcntl).
- Réglage Mailtrap : ports 2525 et 587, Test-NetConnection, courriel:essayer, queue:monitor default, queue:failed, Select-String ; jamais MAIL_SCHEME=tls ; jamais queue:retry pour renvoyer un document.
- Test : chaque clé env() de config/mail.php, dont MAIL_TIMEOUT, figure dans .env.example.
11. Facultatif, à soumettre à l'éditeur : un sous-ensemble de polices dans les PDF. Une page pèse 880 Ko (DejaVuSans et DejaVuSans-Bold embarquées entières), soit 1,2 Mo par e-mail.

### Choix de Claude et point soumis à l'éditeur

- Point 11 (taille des PDF) : choix de Claude, appliqué par le lot ML, que l'éditeur peut contester : n'embarquer dans les PDF que les caractères utilisés des polices (sous-ensemble), sans rien changer à l'apparence ni au contenu signé ; une page passe d'environ 880 Ko à quelques dizaines de Ko. Test : un PDF d'une page pèse moins de 150 Ko et se lit (pdftotext) avec les mêmes textes.
- Point 5 (rythme des nouvelles tentatives d'un envoi demandé par une personne) : tranché par l'éditeur le 02/11/2026 (« Fais comme tu proposes - recommandé », option b) : trois tentatives, la première aussitôt, la deuxième 30 secondes après, la troisième 2 minutes après (backoff [30, 120] au lieu de [60, 600]) ; l'échec définitif est donc connu environ 3 minutes après la demande ; la fiche affiche à chaque échec intermédiaire la cause traduite et l'heure de la tentative suivante (point 5 du vérificateur). Tests : rythme exact au temps figé ; envoi en échec 3 tentatives plus tard, avec la cause traduite et l'avis au demandeur et au propriétaire.
