<?php

namespace App\Domain\Charges\Actions;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Models\CreditDeTva;
use App\Domain\Charges\Services\CreditsDeTva;
use App\Domain\Charges\Services\GestesSurLeCreditDeTva;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\CadenceDeLaTva;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * « Indiquer mon crédit de TVA » (lot TVA27 ; choix K2 de l'éditeur du
 * 15/11/2026, règle du 18/11/2026) : le solde de TVA d'une déclaration que
 * la société a déposée, pour une période de son choix, celle de son
 * arrivée dans PILIQI ou une suivante, déjà terminée (voir
 * CreditsDeTva::periodesDeclarables()), écrit comme au tableau de bord :
 * négatif pour un crédit (−1 250,00 €), 0 € sans crédit (« Non, aucun
 * crédit »). Une TVA à payer, payée avec sa déclaration, ne se reporte
 * pas : un solde positif est refusé. Une période déjà déclarée se change
 * sur sa ligne (voir ModifierUnCreditDeTvaAction et
 * CorrigerUnCreditDeTvaAction).
 *
 * Par le propriétaire de la société, ou par son comptable (le cabinet lié
 * ou l'expert-comptable déclaré, qui a « Charges : voir, ajouter, saisir
 * et valider ») : indiquée par le comptable, elle naît vérifiée. Au réel
 * normal seulement : jamais en franchise en base, ni avant le choix du
 * régime, que la base refuse aussi (voir GardesDesCreditsDeTva). Son jeton
 * de demande répétée la protège d'un double envoi ; deux déclarations
 * simultanées de la même période passent l'une après l'autre sous le
 * verrou de la société, la seconde trouvant la période déjà déclarée.
 */
class DeclarerUnCreditDeTvaAction
{
    public const REFUS_POSITIF = 'Une TVA à payer de votre déclaration a été payée avec elle : elle ne se reporte pas. Saisissez votre crédit en négatif, ou 0 € si vous n\'en avez pas.';

    public const REFUS_DEJA_DECLAREE = 'Le crédit de cette période est déjà indiqué : changez-le sur sa ligne.';

    public function __construct(private readonly GestesSurLeCreditDeTva $gestes) {}

    /**
     * @param  string  $periode  la période de la déclaration, par son premier mois « AAAA-MM »
     * @param  int  $solde  le solde de la déclaration en centimes : négatif pour un crédit, 0 sans crédit
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $societe, string $periode, int $solde, ?string $jeton = null): CreditDeTva
    {
        return $this->gestes->executer($auteur, $societe, ActionsUniques::DECLARER_UN_CREDIT_DE_TVA, $jeton, ['periode' => $periode, 'solde' => $solde], function (User $auteur, Societe $societe, ValideePar $comme) use ($periode, $solde): CreditDeTva {
            $cadence = $societe->regime_tva?->cadence() ?? throw new LogicException(GestesSurLeCreditDeTva::REFUS_REGIME);

            if ($solde > 0) {
                throw new LogicException(self::REFUS_POSITIF);
            }

            if (! in_array($periode, CreditsDeTva::periodesDeclarables($societe, $cadence, Calendrier::deParis()->aujourdhui()), true)) {
                throw new LogicException(self::refusDeLaPeriode($societe, $cadence, $periode));
            }

            if (CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)->where('societe_id', CleDuModele::de($societe))->where('cadence', $cadence->value)->where('periode', $periode)->exists()) {
                throw new LogicException(self::REFUS_DEJA_DECLAREE);
            }

            $credit = new CreditDeTva;
            $credit->forceFill([
                'cadence' => $cadence,
                'periode' => $periode,
                'solde' => $solde,
                'saisi_par' => $auteur->getKey(),
                'saisi_comme' => $comme,
                'saisi_le' => now(),
                ...($comme === ValideePar::Proprietaire ? [] : ['verifie_par' => $auteur->getKey(), 'verifie_comme' => $comme, 'verifie_le' => now()]),
            ])->save();
            $this->gestes->auditer(ActionAuditee::CreditDeTvaDeclare, $auteur, null, $credit);

            return $credit;
        });
    }

    /**
     * Pourquoi cette période ne se déclare pas, aujourd'hui : avant
     * l'arrivée de la société dans PILIQI, ou pas encore terminée.
     */
    public static function refusDeLaPeriode(Societe $societe, CadenceDeLaTva $cadence, string $periode): string
    {
        $premiere = CreditsDeTva::premierePeriode($societe, $cadence);
        $nom = $cadence->nomDeLaPeriode();

        if (! $cadence->estUnePeriode($periode)) {
            return "Cette période ne se déclare pas : choisissez un {$nom} de la liste.";
        }

        return $periode < $premiere
            ? "PILIQI ne compte pas cette période, d'avant votre arrivée : déclarez le crédit du {$nom} de votre arrivée ({$cadence->libelle($premiere)}) ou d'un {$nom} suivant."
            : "Ce {$nom} n'est pas encore terminé : sa déclaration se dépose après sa fin, et son crédit se déclare à partir du ".Calendrier::afficherJour($cadence->lendemainDe($periode), 'd/m/Y').'.';
    }
}
