<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Societe;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Collection;

/**
 * Les courriers recommandés de la vérification d'une société, lus dans
 * cette société seulement, et ce qu'ils permettent : le premier part à la
 * confirmation de l'inscription ; un seul nouveau courrier peut être
 * demandé, au plus tôt quinze jours après le premier (voir
 * EnvoyerCodeDeConfirmationAction::nouvelEnvoi()) ; ensuite, le
 * propriétaire dépose des pièces pour que l'éditeur traite son cas à la
 * main (voir DeposerUnePieceAction), et seul l'éditeur envoie encore un
 * courrier. L'inscription garde son SIREN trente jours au plus après son
 * dernier courrier (voir CloreLInscriptionAction).
 */
class CourriersDeVerification
{
    public const JOURS_AVANT_UN_NOUVEL_ENVOI = 15;

    public const COURRIERS_DEMANDES_PAR_LE_PROPRIETAIRE_AU_PLUS = 2;

    /**
     * Ses courriers de vérification, du premier au dernier.
     *
     * @return Collection<int, CodeConfirmation>
     */
    public function courriers(Societe $societe): Collection
    {
        return app(TenantContext::class)->runAsSociete($societe, fn (): Collection => CodeConfirmation::query()
            ->where('motif', MotifCode::Verification->value)
            ->orderBy('envoye_le')
            ->orderBy('id')
            ->get());
    }

    public function dernier(Societe $societe): ?CodeConfirmation
    {
        return $this->courriers($societe)->last();
    }

    /**
     * Le jour à partir duquel le propriétaire peut demander un nouveau
     * courrier, quinze jours après le premier ; null quand il ne le peut
     * plus (l'inscription n'attend plus de code, ou le nouveau courrier est
     * déjà parti).
     */
    public function nouvelEnvoiPossibleLe(Societe $societe): ?CarbonImmutable
    {
        $courriers = $this->courriers($societe);

        if (! $societe->attendSonCode() || $courriers->count() >= self::COURRIERS_DEMANDES_PAR_LE_PROPRIETAIRE_AU_PLUS) {
            return null;
        }

        $premier = $courriers->first()?->envoye_le ?? $societe->created_at;

        return Calendrier::deParis()->decaler($premier, CarbonInterval::days(self::JOURS_AVANT_UN_NOUVEL_ENVOI));
    }

    /**
     * Le propriétaire peut-il déposer des pièces pour l'éditeur ? Une fois
     * son nouveau courrier parti, tant que l'inscription attend son code.
     */
    public function piecesPossibles(Societe $societe): bool
    {
        return $societe->attendSonCode() && $this->courriers($societe)->count() >= self::COURRIERS_DEMANDES_PAR_LE_PROPRIETAIRE_AU_PLUS;
    }

    /**
     * Le jour où l'inscription sera close sans code saisi : trente jours
     * après son dernier courrier, ou après sa création si aucun n'est parti.
     */
    public function cloturePrevueLe(Societe $societe): CarbonImmutable
    {
        return Calendrier::deParis()->decaler($this->dernier($societe)?->envoye_le ?? $societe->created_at, CarbonInterval::days(CodeConfirmation::DUREE_DE_VALIDITE_EN_JOURS));
    }
}
