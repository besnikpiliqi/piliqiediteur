<?php

namespace App\Domain\Charges\Actions;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Services\SeuilDuTrimestriel;
use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * « C'est vérifié », sur la ligne de l'« À faire » qui dit qu'une société
 * au réel normal trimestriel dépasse 1 100 000 € de chiffre d'affaires
 * cette année (lot TVA27, voir SeuilDuTrimestriel) : le propriétaire, ou
 * l'administrateur qui peut modifier les réglages fiscaux de la société,
 * dit avoir vérifié son régime de TVA, et la ligne sort de l'« À faire »
 * jusqu'à l'année suivante. Rien d'autre ne change : le régime reste celui
 * que la société a choisi.
 *
 * Sous le verrou de la ligne de la société (comme toute entrée de son
 * journal d'audit), puis de l'auteur relu : deux « C'est vérifié »
 * simultanés passent l'un après l'autre, et le second trouve l'année déjà
 * vérifiée. Le geste s'inscrit au journal d'audit de la société, avec
 * l'année et le chiffre d'affaires HT lu dans PILIQI, en centimes.
 */
class VerifierLeSeuilDuTrimestrielAction
{
    use AgitDansLaSociete;

    public const REFUS = 'Seul le propriétaire, ou l\'administrateur qui peut modifier les réglages fiscaux de la société, dit que son régime de TVA est vérifié.';

    public const DEJA_VERIFIE = 'Le régime de TVA est déjà vérifié pour cette année.';

    public const RIEN_A_VERIFIER = 'Rien à vérifier : la société n\'est pas au réel normal trimestriel, ou son chiffre d\'affaires de l\'année ne dépasse pas 1 100 000 €.';

    public function __construct(
        private readonly SeuilDuTrimestriel $seuil,
        private readonly JournalDesDroits $journalDesDroits,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $societe): void
    {
        $this->dansLaSociete($societe, self::REFUS, fn () => $this->journalDesDroits->sousVerrouDesComptes($auteur, [], function (User $auteurRelu) use ($societe): void {
            $relue = Societe::query()->whereKey($societe->getKey())->firstOrFail();

            if ($auteurRelu->societe_id !== CleDuModele::de($relue) || ! $auteurRelu->can('modifierLesParametresFiscaux', $relue)) {
                throw new AuthorizationException(self::REFUS);
            }

            $annee = (int) substr(Calendrier::deParis()->aujourdhui(), 0, 4);

            if ($this->seuil->estVerifie($relue, $annee)) {
                throw new LogicException(self::DEJA_VERIFIE);
            }

            if ($this->seuil->anneeASignaler($relue) !== $annee) {
                throw new LogicException(self::RIEN_A_VERIFIER);
            }

            $this->journalAudit->inscrire(ActionAuditee::SeuilDuTrimestrielVerifie, $auteurRelu, null, null, [
                'annee' => $annee,
                'regime_tva' => $relue->regime_tva?->value,
                'chiffre_d_affaires_ht' => $this->seuil->chiffreDAffaires($relue, $annee),
            ], $relue);
        }, fn () => Societe::verrouillerAvantSonJournalDAudit(CleDuModele::de($societe))));
    }
}
