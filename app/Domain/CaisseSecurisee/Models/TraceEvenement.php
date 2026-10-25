<?php

namespace App\Domain\CaisseSecurisee\Models;

use App\Domain\CaisseSecurisee\Casts\HorodatageUtc;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * La trace technique d'un événement technique (JET) inscrit pendant une
 * requête HTTP : l'adresse IP et le navigateur du visiteur, rangés à part
 * du registre fiscal, jamais dans un contenu signé (décision de l'éditeur,
 * S13). Rien dans les règles de la caisse sécurisée ne les exige ;
 * l'opérateur, lui, reste dans l'en-tête signé de l'événement.
 *
 * Elle s'écrit dans la transaction de l'événement (voir noterPour(),
 * appelé par RegistreFiscal), seul endroit qui lit l'adresse IP et le
 * navigateur de la requête pour la caisse sécurisée : une trace qui ne
 * s'écrit pas annule l'événement. Elle ne se modifie jamais et s'efface à
 * expire_le, après la durée de config/conservation.php (voir
 * app:purger-traces) ; la base refuse tout le reste (voir
 * DeclencheursDeProtection::effacementApresExpiration()). Elle ne se lit
 * que par le propriétaire du magasin (voir
 * EnregistrementFiscalResource et ExporterJournalDesEvenementsAction).
 *
 * @property int $enregistrement_fiscal_id
 * @property string|null $ip
 * @property string|null $agent_utilisateur
 * @property CarbonImmutable $expire_le
 */
class TraceEvenement extends Model
{
    use BelongsToMagasin;

    protected $table = 'traces_evenements';

    protected $primaryKey = 'enregistrement_fiscal_id';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'expire_le' => HorodatageUtc::class,
    ];

    /**
     * Note la trace de l'événement que vient d'inscrire le registre
     * fiscal, si une requête HTTP routée l'a fait inscrire : une commande
     * ou un job n'a qu'une requête de façade, sans visiteur, et ne laisse
     * aucune trace.
     */
    public static function noterPour(EnregistrementFiscal $evenement): void
    {
        $requete = request();

        if ($requete->route() === null) {
            return;
        }

        $navigateur = $requete->userAgent();

        (new self)->forceFill([
            'enregistrement_fiscal_id' => $evenement->getKey(),
            'ip' => $requete->ip(),
            'agent_utilisateur' => $navigateur === null ? null : mb_substr($navigateur, 0, 255),
            'expire_le' => Calendrier::deParis()->decalerEnMoisSansDebordement(CarbonImmutable::now(), config('conservation.traces_en_mois')),
        ])->save();
    }
}
