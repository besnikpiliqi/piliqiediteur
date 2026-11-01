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
