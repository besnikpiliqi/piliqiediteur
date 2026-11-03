<?php

namespace App\Domain\CaisseSecurisee\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Services\TraceDuVisiteur;
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
 * appelé par RegistreFiscal), avec l'adresse IP et le navigateur que lit
 * TraceDuVisiteur, seul endroit qui les lit dans la requête : une trace
 * qui ne s'écrit pas annule l'événement. Elle ne se modifie jamais et s'efface à
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
        'expire_le' => Instant::class,
    ];

    /**
     * Note la trace de l'événement que vient d'inscrire le registre
     * fiscal, si le visiteur d'une requête HTTP l'a fait inscrire dans un
     * magasin de la société où il agit (voir TraceDuVisiteur).
     */
    public static function noterPour(EnregistrementFiscal $evenement): void
    {
        $colonnes = app(TraceDuVisiteur::class)->colonnes();

        if ($colonnes !== null) {
            (new self)->forceFill(['enregistrement_fiscal_id' => $evenement->getKey(), ...$colonnes])->save();
        }
    }
}
