<?php

/*
 | Un faux serveur SMTP pour les tests de courriel:essayer, sans aucune
 | connexion hors de la machine : il écoute sur 127.0.0.1, au port libre
 | que choisit le système, écrit ce port sur sa sortie, puis répond à une
 | seule connexion et s'arrête. Mode « accepte » : il prend le message.
 | Mode « refuse-identifiants » : il propose l'authentification et la
 | refuse (535), comme un serveur qui ne reconnaît pas l'identifiant et le
 | mot de passe.
 */

$mode = $argv[1] ?? 'accepte';
$serveur = stream_socket_server('tcp://127.0.0.1:0', $codeDErreur, $erreur);

if ($serveur === false) {
    fwrite(STDERR, "Écoute impossible : {$erreur}\n");
    exit(1);
}

fwrite(STDOUT, explode(':', (string) stream_socket_get_name($serveur, false))[1]."\n");
fflush(STDOUT);
$client = stream_socket_accept($serveur, 30);

if ($client === false) {
    exit(1);
}

$repondre = static function (string $reponse) use ($client): void {
    fwrite($client, $reponse."\r\n");
};
$repondre('220 faux.serveur.test ESMTP');
$dansLeMessage = false;

while (($ligne = fgets($client)) !== false) {
    $commande = strtoupper(trim($ligne));

    if ($dansLeMessage) {
        if (trim($ligne) === '.') {
            $dansLeMessage = false;
            $repondre('250 2.0.0 Message accepté');
        }

        continue;
    }

    match (true) {
        str_starts_with($commande, 'EHLO') => $repondre($mode === 'refuse-identifiants' ? "250-faux.serveur.test\r\n250 AUTH LOGIN PLAIN" : '250 faux.serveur.test'),
        str_starts_with($commande, 'AUTH') => $repondre('535 5.7.8 Authentication failed'),
        str_starts_with($commande, 'DATA') => (function () use ($repondre, &$dansLeMessage): void {
            $dansLeMessage = true;
            $repondre('354 Envoyez le message');
        })(),
        str_starts_with($commande, 'QUIT') => $repondre('221 2.0.0 Au revoir'),
        default => $repondre('250 2.0.0 OK'),
    };

    if (str_starts_with($commande, 'QUIT')) {
        break;
    }
}

fclose($client);
fclose($serveur);
