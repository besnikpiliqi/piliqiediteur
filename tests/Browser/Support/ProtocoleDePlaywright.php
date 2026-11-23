<?php

namespace Tests\Browser\Support;

use Amp\Websocket\Client\WebsocketConnection;
use LogicException;
use Pest\Browser\Playwright\Client;
use ReflectionProperty;

/**
 * Le protocole que le module browser de Pest 4 parle au serveur de
 * Playwright, adapté à celui des versions 1.62 et suivantes de ce serveur.
 *
 * Le module a été écrit pour Playwright 1.59 et fait deux choses que ces
 * versions ne comprennent plus :
 *
 * - il écrit le délai de chaque appel dans ses paramètres
 *   (« params.timeout »), où le serveur le lisait jusqu'à la version 1.61 ;
 *   depuis la 1.62, le serveur ne lit plus que « metadata.timeout » et laisse
 *   de côté tout paramètre qu'il ne connaît pas : sans ce délai, un clic sur
 *   un élément caché, l'attente d'un élément ou d'une page qui n'arrive pas
 *   n'échouerait jamais, et le test attendrait sans fin au lieu d'échouer au
 *   bout du délai d'un geste (voir Navigation::delai()) ;
 * - il désigne les fichiers d'un champ de fichier par leur chemin sur le poste
 *   (« localPaths »), que le serveur refuse maintenant (« localPaths are not
 *   allowed when the client is not local ») pour tout client qu'il ne sait pas
 *   installé sur sa machine, ce que le serveur lancé par « playwright
 *   run-server » ne sait jamais.
 *
 * Cette classe pose donc, sur la connexion que le module a ouverte, un relais
 * qui adapte chaque appel avant de l'envoyer (voir
 * RelaisDuProtocoleDePlaywright), comme le fait le client officiel de
 * Playwright ; un serveur de la version 1.59 comprend aussi l'appel adapté
 * (la métadonnée de plus est ignorée, et les fichiers par leur contenu sont
 * ceux d'un client distant).
 */
final class ProtocoleDePlaywright
{
    /**
     * Pose le relais sur la connexion du module, une seule fois par
     * processus : à appeler avant le premier appel au serveur, une fois la
     * connexion ouverte, ce que le module fait au début de chaque test en
     * navigateur.
     *
     * @throws LogicException le module n'a pas ouvert sa connexion
     */
    public static function adapter(): void
    {
        $client = Client::instance();
        $propriete = new ReflectionProperty(Client::class, 'websocketConnection');
        $connexion = $propriete->getValue($client);

        if ($connexion instanceof RelaisDuProtocoleDePlaywright) {
            return;
        }

        if (! $connexion instanceof WebsocketConnection) {
            throw new LogicException('Le module browser de Pest n\'a pas ouvert sa connexion au serveur de Playwright : son protocole ne peut pas y être adapté.');
        }

        $propriete->setValue($client, new RelaisDuProtocoleDePlaywright($connexion));
    }
}
