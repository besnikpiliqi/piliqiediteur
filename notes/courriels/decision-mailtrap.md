# Décision de l'éditeur du 01/11/2026 : Mailtrap pour vérifier les e-mails

## 1. Les mots de l'éditeur (message du 01/11/2026, vers 11 h 40 UTC)

> je veut utilier le https://mailtrap.io pour les email. donc tu va mettre en place un vrai mail un vrai serveur , comme ca on sait que tous les send mail vont avec succes. donc tu peux faire quelque testes mais pas à chaque fois pour ne pas prendre du temps pour rien. peut etre t'en a besion les identifiant

## 2. Ce que la session a constaté

- Le conteneur de la session ne joint aucun serveur SMTP externe (aucune résolution DNS hors du mandataire HTTPS : `sandbox.smtp.mailtrap.io` introuvable, ports 2525 et 587 fermés). Il joint en revanche l'API HTTPS de Mailtrap (`sandbox.api.mailtrap.io`, `send.api.mailtrap.io` répondent).
- `config/mail.php` lit déjà `MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` : sur le PC de l'éditeur, la boîte de test SMTP de Mailtrap ne demande que son `.env` (aucun code). `QUEUE_CONNECTION=database` : les e-mails envoyés par une tâche (documents, relances) ne partent que si `php artisan queue:work` tourne.
- Consigne de sécurité : aucun identifiant dans la conversation ni dans le dépôt ; les identifiants SMTP vont dans le `.env` du PC de l'éditeur ; pour la session, un jeton d'API dans les variables d'environnement de l'environnement cloud (un nouveau conteneur les lit).

## 3. Ce qui est décidé (choix de Claude, que l'éditeur peut contester)

1. **PC de l'éditeur** : boîte de test SMTP de Mailtrap (« Email Testing »), réglée dans son `.env` ; le README du dépôt dit comment, sans aucune valeur.
2. **Tests réels depuis la session** : par l'API HTTPS de la boîte de test, avec trois variables d'environnement de l'environnement cloud, jamais dans le dépôt : `MAILTRAP_API_TOKEN`, `MAILTRAP_ACCOUNT_ID`, `MAILTRAP_INBOX_ID`.
3. **Lot ML** de la chaîne (après A7, avant EC2) : un transport d'envoi « mailtrap » écrit dans PILIQI avec le client HTTP de Laravel (aucun nouveau paquet), choisi seulement par `MAIL_MAILER=mailtrap` ; une commande qui envoie un e-mail d'essai par le transport configuré et dit clairement si le serveur l'a accepté ; un groupe de tests « courriels-reels », exclu de la suite normale et de la CI, lancé à la demande (« pas à chaque fois ») : il envoie chaque e-mail de PILIQI à la boîte de test et vérifie par l'API qu'il y est arrivé, avec son objet, son expéditeur et ses pièces jointes ; sans les trois variables, ces tests sont sautés en le disant.
4. **Quand lancer le groupe réel** : à la fin d'un lot qui ajoute ou change un e-mail, et avant la mise en production ; jamais à chaque commit.
