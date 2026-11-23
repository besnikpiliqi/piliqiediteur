<?php

namespace Tests\Browser\Support;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\Http\Client\Response;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\WebsocketCloseCode;
use Amp\Websocket\WebsocketCloseInfo;
use Amp\Websocket\WebsocketCount;
use Amp\Websocket\WebsocketMessage;
use Amp\Websocket\WebsocketTimestamp;
use Closure;
use IteratorAggregate;
use RuntimeException;
use stdClass;
use Traversable;

/**
 * La connexion du module browser de Pest au serveur de Playwright, dont
 * chaque appel est adapté au protocole du serveur de Playwright 1.62 et
 * suivants (voir ProtocoleDePlaywright) avant de partir ; tout le reste
 * passe tel quel :
 *
 * - le délai de l'appel, que le module écrit dans ses paramètres
 *   (« params.timeout »), est recopié dans ses métadonnées
 *   (« metadata.timeout »), où ce serveur le lit ;
 * - les fichiers d'un « setInputFiles », que le module désigne par leur
 *   chemin sur le poste (« params.localPaths »), partent par leur contenu
 *   (« params.payloads »), comme le client officiel de Playwright le fait
 *   pour un serveur auquel il se connecte : ce serveur refuse les chemins
 *   d'un client qu'il ne sait pas installé sur sa machine.
 *
 * @implements IteratorAggregate<int, WebsocketMessage>
 */
final class RelaisDuProtocoleDePlaywright implements IteratorAggregate, WebsocketConnection
{
    /**
     * Ce que Playwright accepte de faire passer par la connexion : au-delà,
     * son client officiel envoie le fichier par morceaux, ce que ce relais
     * ne fait pas, et un test n'envoie jamais un tel fichier.
     */
    public const TAILLE_MAXIMALE_D_UN_FICHIER = 50 * 1024 * 1024;

    public function __construct(private readonly WebsocketConnection $connexion) {}

    /**
     * Cet appel adapté au protocole du serveur de Playwright 1.62 et
     * suivants ; un message qui n'est pas un appel à paramètres, ou qui n'a
     * rien à adapter, reste tel quel.
     *
     * @throws RuntimeException un fichier à envoyer ne se lit pas ou dépasse TAILLE_MAXIMALE_D_UN_FICHIER
     */
    public static function adapter(string $message): string
    {
        $appel = json_decode($message);

        if (! $appel instanceof stdClass || ! ($appel->params ?? null) instanceof stdClass) {
            return $message;
        }

        $avecLeDelai = self::poserLeDelaiDansLesMetadonnees($appel);
        $avecLesFichiers = self::envoyerLesFichiersParLeurContenu($appel);

        return $avecLeDelai || $avecLesFichiers ? json_encode($appel, JSON_THROW_ON_ERROR) : $message;
    }

    /**
     * Recopie le délai des paramètres dans les métadonnées, sauf s'il y est
     * déjà ou s'il n'est pas un nombre ; dit si l'appel a changé.
     */
    private static function poserLeDelaiDansLesMetadonnees(stdClass $appel): bool
    {
        $delai = $appel->params->timeout ?? null;
        $metadonnees = $appel->metadata ?? [];

        if (! is_int($delai) && ! is_float($delai)) {
            return false;
        }

        if (is_array($metadonnees)) {
            $metadonnees = (object) $metadonnees;
        }

        if (! $metadonnees instanceof stdClass || property_exists($metadonnees, 'timeout')) {
            return false;
        }

        $metadonnees->timeout = $delai;
        $appel->metadata = $metadonnees;

        return true;
    }

    /**
     * Remplace, dans un « setInputFiles », les chemins de fichiers par leur
     * nom et leur contenu (en base64) ; dit si l'appel a changé. Le type de
     * chaque fichier est celui que Playwright déduit de son nom.
     *
     * @throws RuntimeException un fichier ne se lit pas ou dépasse TAILLE_MAXIMALE_D_UN_FICHIER
     */
    private static function envoyerLesFichiersParLeurContenu(stdClass $appel): bool
    {
        $chemins = $appel->params->localPaths ?? null;

        if (($appel->method ?? null) !== 'setInputFiles' || ! is_array($chemins) || $chemins === [] || array_filter($chemins, is_string(...)) !== $chemins) {
            return false;
        }

        $fichiers = [];

        foreach ($chemins as $chemin) {
            if (! is_file($chemin) || ! is_readable($chemin)) {
                throw new RuntimeException("Le fichier {$chemin} n'existe pas ou ne se lit pas sur le poste : Playwright ne peut pas l'envoyer au champ de fichier.");
            }

            if (filesize($chemin) > self::TAILLE_MAXIMALE_D_UN_FICHIER) {
                throw new RuntimeException("Le fichier {$chemin} dépasse 50 Mo : ce relais ne l'envoie pas à Playwright.");
            }

            $contenu = file_get_contents($chemin);

            if ($contenu === false) {
                throw new RuntimeException("Le fichier {$chemin} ne se lit pas sur le poste : Playwright ne peut pas l'envoyer au champ de fichier.");
            }

            $fichiers[] = ['name' => basename($chemin), 'buffer' => base64_encode($contenu)];
        }

        unset($appel->params->localPaths);
        $appel->params->payloads = $fichiers;

        return true;
    }

    public function sendText(string $data): void
    {
        $this->connexion->sendText(self::adapter($data));
    }

    public function getHandshakeResponse(): Response
    {
        return $this->connexion->getHandshakeResponse();
    }

    public function receive(?Cancellation $cancellation = null): ?WebsocketMessage
    {
        return $this->connexion->receive($cancellation);
    }

    public function getId(): int
    {
        return $this->connexion->getId();
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->connexion->getLocalAddress();
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->connexion->getRemoteAddress();
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return $this->connexion->getTlsInfo();
    }

    public function getCloseInfo(): WebsocketCloseInfo
    {
        return $this->connexion->getCloseInfo();
    }

    public function isCompressionEnabled(): bool
    {
        return $this->connexion->isCompressionEnabled();
    }

    public function sendBinary(string $data): void
    {
        $this->connexion->sendBinary($data);
    }

    public function streamText(ReadableStream $stream): void
    {
        $this->connexion->streamText($stream);
    }

    public function streamBinary(ReadableStream $stream): void
    {
        $this->connexion->streamBinary($stream);
    }

    public function ping(): void
    {
        $this->connexion->ping();
    }

    public function getCount(WebsocketCount $type): int
    {
        return $this->connexion->getCount($type);
    }

    public function getTimestamp(WebsocketTimestamp $type): float
    {
        return $this->connexion->getTimestamp($type);
    }

    public function isClosed(): bool
    {
        return $this->connexion->isClosed();
    }

    public function close(int $code = WebsocketCloseCode::NORMAL_CLOSE, string $reason = ''): void
    {
        $this->connexion->close($code, $reason);
    }

    public function onClose(Closure $onClose): void
    {
        $this->connexion->onClose($onClose);
    }

    public function getIterator(): Traversable
    {
        yield from $this->connexion;
    }
}
