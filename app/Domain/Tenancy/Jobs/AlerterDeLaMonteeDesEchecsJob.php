<?php

namespace App\Domain\Tenancy\Jobs;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Services\AlerteEditeur;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;

/**
 * Prévient l'éditeur (PILIQI_EDITEUR_EMAIL, voir AlerteEditeur) d'une
 * montée anormale des essais de connexion manqués sur toute la plateforme
 * (voir LimitationDesConnexions) : une tâche en file, jamais pendant la
 * requête de connexion, dont le délai dirait sinon quelque chose. Trois
 * motifs, chacun une fois par heure au plus :
 * - MOTIF_ABSOLU : plus d'échecs dans l'heure qu'un seuil absolu ;
 * - MOTIF_RELATIF : plus de trois fois la moyenne horaire des 7 jours d'avant
 *   (avec un plancher) ;
 * - MOTIF_ADRESSE : trop d'identifiants différents qui échouent depuis une
 *   même adresse IP (un même mot de passe essayé sur beaucoup de comptes).
 * Le message ne porte que des nombres et l'heure : jamais un identifiant, un
 * mot de passe, une adresse e-mail ni une adresse IP en clair, au plus les
 * huit premiers caractères de l'empreinte HMAC de l'adresse, qui permettent
 * de retrouver ses échecs dans les compteurs sans la nommer.
 */
class AlerterDeLaMonteeDesEchecsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const MOTIF_ABSOLU = 'absolu';

    public const MOTIF_RELATIF = 'relatif';

    public const MOTIF_ADRESSE = 'adresse';

    /**
     * @param  int  $nombre  les échecs de l'heure, ou les identifiants différents d'une adresse
     * @param  int  $seuil  le seuil franchi
     * @param  int  $heure  le début de l'heure, en secondes depuis 1970
     * @param  string|null  $empreinte  les huit premiers caractères de l'empreinte HMAC de l'adresse, pour le motif de l'adresse
     */
    public function __construct(
        public readonly string $motif,
        public readonly int $nombre,
        public readonly int $seuil,
        public readonly int $heure,
        public readonly ?string $empreinte = null,
    ) {}

    public function handle(AlerteEditeur $alerte): void
    {
        $quand = Calendrier::deParis()->afficher(now()->setTimestamp($this->heure), 'd/m/Y à H \h');

        [$objet, $lignes] = match ($this->motif) {
            self::MOTIF_ABSOLU => ['Connexions : montée des essais manqués', [
                "Plus de {$this->seuil} essais de connexion manqués dans l'heure qui commence le {$quand} (heure de Paris), sur toute la plateforme : {$this->nombre} au moment de l'alerte.",
            ]],
            self::MOTIF_RELATIF => ['Connexions : essais manqués au-dessus de la moyenne', [
                "{$this->nombre} essais de connexion manqués dans l'heure qui commence le {$quand} (heure de Paris), sur toute la plateforme, plus que le seuil de {$this->seuil} (trois fois la moyenne horaire des 7 jours d'avant, au moins le plancher de l'application).",
            ]],
            self::MOTIF_ADRESSE => ['Connexions : un même mot de passe essayé sur beaucoup de comptes', [
                "Plus de {$this->seuil} identifiants différents ont échoué depuis une même adresse IP dans l'heure qui commence le {$quand} (heure de Paris) : {$this->nombre} au moment de l'alerte. Empreinte de l'adresse : {$this->empreinte}.",
            ]],
            default => throw new InvalidArgumentException("Motif d'alerte inconnu : {$this->motif}."),
        };

        $alerte->alerterDeLaPlateforme($objet, $lignes);
    }
}
