<?php

namespace App\Integrations\Antivirus;

use App\Integrations\Antivirus\Contracts\Antivirus;
use Closure;
use ErrorException;

/**
 * ClamAV sur le serveur (pilote « clamav ») : le démon clamd, joint par sa
 * socket Unix (CLAMAV_SOCKET) ou par son hôte et son port (CLAMAV_HOTE,
 * CLAMAV_PORT), en au plus CLAMAV_DELAI secondes par échange. Le contenu
 * part par la commande INSTREAM : « zINSTREAM\0 », puis des morceaux,
 * chacun précédé de sa longueur sur quatre octets (ordre du réseau), puis
 * une longueur nulle ; clamd répond « stream: OK », ou « stream: <menace>
 * FOUND ». Toute autre réponse (une erreur de clamd, un contenu plus grand
 * que son StreamMaxLength), une connexion refusée ou un délai dépassé : il
 * ne répond pas (voir AntivirusInjoignableException), et le fichier n'est
 * ni accepté ni servi. Le contenu ne quitte jamais le serveur : clamd y
 * tourne, sans service extérieur.
 */
final class ClamAv implements Antivirus
{
    /**
     * La taille des morceaux envoyés, bien en dessous du StreamMaxLength de
     * clamd (25 Mo par défaut).
     */
    private const TAILLE_DES_MORCEAUX = 65536;

    /**
     * La longueur au-delà de laquelle une réponse de clamd n'en est pas
     * une.
     */
    private const REPONSE_MAXIMALE = 4096;

    /**
     * @param  string  $adresse  unix:///chemin/de/la/socket ou tcp://hote:port
     * @param  (Closure(string, int): resource)|null  $connexion  ouvre la connexion à cette adresse, en ce délai ; par défaut stream_socket_client()
     */
    public function __construct(
        private readonly string $adresse,
        private readonly int $delai,
        private readonly ?Closure $connexion = null,
    ) {}

    public function analyser(string $contenu): ResultatDeLAntivirus
    {
        $reponse = $this->echanger(function ($flux) use ($contenu): void {
            self::ecrire($flux, "zINSTREAM\0");

            for ($debut = 0; $debut < strlen($contenu); $debut += self::TAILLE_DES_MORCEAUX) {
                $morceau = substr($contenu, $debut, self::TAILLE_DES_MORCEAUX);
                self::ecrire($flux, pack('N', strlen($morceau)).$morceau);
            }

            self::ecrire($flux, pack('N', 0));
        });

        if ($reponse === 'stream: OK') {
            return ResultatDeLAntivirus::sain();
        }

        if (preg_match('/^stream: (.+) FOUND$/', $reponse, $trouvee) === 1) {
            return ResultatDeLAntivirus::menace($trouvee[1]);
        }

        throw new AntivirusInjoignableException("réponse inattendue de clamd ({$reponse}).");
    }

    public function description(): string
    {
        return $this->echanger(fn ($flux) => self::ecrire($flux, "zVERSION\0"));
    }

    /**
     * Ouvre une connexion à clamd, y envoie une commande, et rend sa
     * réponse, sans le caractère nul qui la termine.
     *
     * @param  Closure(resource): void  $envoyer
     *
     * @throws AntivirusInjoignableException
     */
    private function echanger(Closure $envoyer): string
    {
        $flux = $this->ouvrir();

        try {
            stream_set_timeout($flux, $this->delai);
            $envoyer($flux);
            $reponse = '';

            while (! str_contains($reponse, "\0") && strlen($reponse) <= self::REPONSE_MAXIMALE) {
                $lu = fread($flux, 8192);

                if (stream_get_meta_data($flux)['timed_out']) {
                    throw new AntivirusInjoignableException("clamd n'a pas répondu en {$this->delai} secondes.");
                }

                if ($lu === false || $lu === '') {
                    break;
                }

                $reponse .= $lu;
            }
        } catch (ErrorException $erreur) {
            throw new AntivirusInjoignableException("l'échange avec clamd a échoué ({$erreur->getMessage()}).", $erreur);
        } finally {
            fclose($flux);
        }

        $reponse = trim(explode("\0", $reponse, 2)[0]);

        return $reponse !== '' ? $reponse : throw new AntivirusInjoignableException('clamd n\'a rien répondu.');
    }

    /**
     * @return resource
     *
     * @throws AntivirusInjoignableException
     */
    private function ouvrir()
    {
        if ($this->connexion !== null) {
            return ($this->connexion)($this->adresse, $this->delai);
        }

        $flux = @stream_socket_client($this->adresse, $code, $message, $this->delai);

        return $flux !== false ? $flux : throw new AntivirusInjoignableException("clamd est injoignable à {$this->adresse} ({$message}).");
    }

    /**
     * @param  resource  $flux
     *
     * @throws AntivirusInjoignableException
     */
    private static function ecrire($flux, string $donnees): void
    {
        for ($ecrits = 0; $ecrits < strlen($donnees); $ecrits += $nombre) {
            $nombre = fwrite($flux, substr($donnees, $ecrits));

            if ($nombre === false || $nombre === 0) {
                throw new AntivirusInjoignableException('clamd a fermé la connexion pendant l\'envoi.');
            }
        }
    }
}
