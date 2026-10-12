<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Enregistre les réglages fiscaux de la société, communs à tous ses
 * magasins, un seul assujetti : son régime de TVA (dont dépendent la
 * cadence de l'e-reporting, voir CalculateurPeriodeEReporting, et la
 * franchise en base de ses ventes), l'option pour le paiement de la taxe
 * d'après les débits, et le mois de fin de son exercice (décembre par
 * défaut), verrouillé dès la première vente de l'un de ses magasins (voir
 * Societe::aVenduUneFois()).
 *
 * Réservé à qui règle les paramètres de la société (voir SocietePolicy).
 * Chacun touche la conformité de la caisse : l'enregistrement se fait
 * sous le verrou des registres de tous les magasins de la société, fermés
 * compris, pris par identifiant croissant, et chaque changement s'inscrit
 * au journal des événements de chacun, avec l'ancienne et la nouvelle
 * valeur (JET 270, voir JournaliseSesParametresDeConformite) : une vente
 * attend le changement de régime, ou le précède, et lit le régime
 * d'après (voir EmettreFactureAction). Si l'un des registres ne peut pas
 * l'inscrire, rien n'est enregistré, dans aucun magasin. Ce qui change
 * s'inscrit aussi au journal d'audit.
 */
class ModifierParametresFiscauxSocieteAction
{
    public const CHAMPS = ['regime_tva', 'option_debits_tva', 'mois_fin_exercice'];

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $donnees  les champs de CHAMPS ; un champ absent ne change pas
     *
     * @throws LogicException quand l'auteur ne peut pas modifier les paramètres de cette société, ou que le mois de fin d'exercice est verrouillé
     * @throws ValidationException quand une donnée est refusée
     */
    public function handle(User $auteur, Societe $societe, array $donnees): Societe
    {
        $contexte = app(TenantContext::class);

        if ($contexte->aUneSociete() && $contexte->societe()->getKey() !== $societe->getKey()) {
            throw new LogicException(ModifierIdentiteSocieteAction::MESSAGE_SANS_AUTORISATION);
        }

        return $contexte->runAsSociete($societe, function () use ($auteur, $societe, $donnees): Societe {
            $auteur = app(LecteurDesDroits::class)->exigerDansLaSociete($auteur, 'modifierLesParametres', $societe, $societe, new LogicException(ModifierIdentiteSocieteAction::MESSAGE_SANS_AUTORISATION));

            $valides = Validator::make(array_intersect_key($donnees, array_flip(self::CHAMPS)), [
                'regime_tva' => ['sometimes', 'required', Rule::enum(RegimeTva::class)],
                'option_debits_tva' => ['sometimes', 'boolean'],
                'mois_fin_exercice' => ['sometimes', 'required', 'integer', 'between:1,12'],
            ], attributes: ['regime_tva' => 'régime de TVA', 'mois_fin_exercice' => 'mois de fin d\'exercice'])->validate();

            return $this->registre->sousVerrouDesMagasins($societe->magasins()->orderBy('id')->get(), function () use ($auteur, $societe, $valides): Societe {
                $enregistree = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();
                $avant = self::valeursAuditees($enregistree);

                $enregistree->fill($valides)->save();

                $this->journalAudit->inscrireLesChangements(ActionAuditee::ParametresFiscauxModifies, $auteur, null, $avant, self::valeursAuditees($enregistree), $enregistree);

                return $enregistree;
            });
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function valeursAuditees(Societe $societe): array
    {
        return array_map(JournalAudit::valeur(...), $societe->only(self::CHAMPS));
    }
}
