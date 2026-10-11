<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Les caisses personnelles restées ouvertes (voir FermerSaCaisseAction) :
 * elles ne bloquent rien. La clôture de la journée agrège directement les
 * factures, jamais les relevés personnels (voir CloturerCaisseAction), puis
 * les ferme d'office (voir FermerLesCaissesPersonnellesAction), comme la
 * clôture automatique de minuit, même après une clôture manuelle ; la
 * fenêtre de clôture nomme celles qu'elle fermera (voir ListClotureCaisses).
 *
 * La caisse d'un vendeur est ouverte au soir d'une journée tant qu'il a
 * des ventes ou des encaissements de cette journée ou d'une journée
 * antérieure qu'aucune de ses fermetures n'a comptés. Une vente est de la
 * journée de son émission, à l'heure de Paris ; un encaissement, de celle
 * où il est reçu, une contre-passation comprise, qui revient au vendeur du
 * paiement qu'elle corrige (voir Paiement::scopeNonFermesParLeVendeur()).
 * Quiconque enregistre un paiement a ainsi sa caisse, le comptable comme le
 * vendeur (voir ClotureCaissePersonnellePolicy::fermer()).
 */
class VerificateurCaissesPersonnelles
{
    /**
     * Les vendeurs du magasin actif dont la caisse personnelle est ouverte
     * au soir de cette journée, chacun une fois, par identifiant. Une date
     * porte sa journée dans son propre fuseau.
     *
     * @return Collection<int, User>
     */
    public function vendeursAvecCaisseOuverte(CarbonInterface $jour): Collection
    {
        $journee = $jour->toDateString();
        $finDeLaJournee = CarbonImmutable::parse($journee, config('app.timezone'))->endOfDay();
        $paiementsOuverts = Paiement::query()
            ->whereDoesntHave('cloturesCaissePersonnelles')
            ->whereDate('recu_le', '<=', $journee);

        $vendeurIds = Facture::query()
            ->emises()
            ->whereDoesntHave('cloturesCaissePersonnelles')
            ->emisJusquA($finDeLaJournee)
            ->distinct()
            ->pluck('cree_par')
            ->merge((clone $paiementsOuverts)->whereNull('contre_passe_paiement_id')->distinct()->pluck('cree_par'))
            ->merge(Paiement::query()->whereIn('id', (clone $paiementsOuverts)->select('contre_passe_paiement_id'))->distinct()->pluck('cree_par'))
            ->filter()
            ->unique();

        return User::query()->whereKey($vendeurIds->all())->orderBy('id')->get();
    }
}
