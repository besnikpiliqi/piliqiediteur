<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use LogicException;

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
 * journée du calendrier de son émission ; un encaissement, de celle
 * où il est reçu, une contre-passation comprise, qui revient au vendeur du
 * paiement qu'elle corrige (voir Paiement::scopeNonFermesParLeVendeur()).
 * Quiconque enregistre un paiement a ainsi sa caisse, et la ferme tant
 * qu'elle est ouverte, même s'il n'est plus autorisé à vendre (voir
 * aSaCaisseOuverte() et ClotureCaissePersonnellePolicy::fermer()).
 *
 * Un compte autorisé à vendre n'encaisse que dans un magasin à la fois,
 * même s'il a accès à plusieurs magasins (un administrateur à qui le
 * propriétaire a coché la vente dans plusieurs magasins) : tant que sa
 * caisse est ouverte dans un magasin de sa société, il n'émet, n'encaisse
 * ni ne contre-passe rien dans un autre (voir
 * refuserSiSaCaisseEstOuverteAilleurs()).
 */
class VerificateurCaissesPersonnelles
{
    /**
     * Les vendeurs du magasin actif dont la caisse personnelle est ouverte
     * au soir de cette journée de son calendrier (« AAAA-MM-JJ », voir
     * Magasin::calendrier()), sa dernière seconde comprise, chacun une
     * fois, par identifiant.
     *
     * @return Collection<int, User>
     */
    public function vendeursAvecCaisseOuverte(string $journee): Collection
    {
        $finDeLaJournee = app(TenantContext::class)->magasin()->calendrier()->bornesDuJour($journee)[1]->subSecond();
        $paiementsOuverts = Paiement::query()
            ->whereDoesntHave('cloturesCaissePersonnelles')
            ->where('recu_le', '<=', $journee);

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

    /**
     * Ce compte a-t-il sa caisse personnelle ouverte dans le magasin actif :
     * une vente émise ou un encaissement, quelle qu'en soit la journée,
     * qu'aucune de ses fermetures n'a comptés (voir
     * Facture::scopeNonFermeesParLeVendeur() et
     * Paiement::scopeNonFermesParLeVendeur()) ? Lu sous MagasinScope, dans
     * ce seul magasin.
     */
    public function aSaCaisseOuverte(User $compte): bool
    {
        return Facture::query()->nonFermeesParLeVendeur($compte->getKey())->exists()
            || Paiement::query()->nonFermesParLeVendeur($compte->getKey())->exists();
    }

    /**
     * Refuse l'opération dans ce magasin si ce compte a encore sa caisse
     * personnelle ouverte dans un autre magasin de la même société : une
     * vente émise ou un encaissement reçu là-bas qu'aucune de ses
     * fermetures n'a comptés. C'est une lecture hors du magasin, déclarée
     * dans CLAUDE.md : seulement les magasins de la société de ce magasin,
     * pour ce seul compte, et seulement l'identifiant et le nom du premier
     * magasin trouvé. L'appelante tient déjà la ligne du compte verrouillée
     * (voir LecteurDesDroits::exigerSousVerrou()) : deux opérations de la
     * même personne dans deux magasins se suivent, et la seconde voit la
     * première.
     *
     * @throws LogicException
     */
    public function refuserSiSaCaisseEstOuverteAilleurs(User $compte, Magasin $magasin): void
    {
        $ailleurs = Magasin::query()
            ->where('societe_id', $magasin->societe_id)
            ->whereKeyNot($magasin->getKey())
            ->where(fn ($magasins) => $magasins
                ->whereIn('id', Facture::withoutGlobalScope(MagasinScope::class)
                    ->select('factures.magasin_id')
                    ->emises()
                    ->where('factures.cree_par', $compte->getKey())
                    ->whereNotExists(fn (Builder $fermetures) => $fermetures->selectRaw('1')->from('clotures_caisse_personnelles_factures')->whereColumn('clotures_caisse_personnelles_factures.facture_id', 'factures.id')))
                ->orWhereIn('id', Paiement::withoutGlobalScope(MagasinScope::class)
                    ->select('paiements.magasin_id')
                    ->where(fn ($paiements) => $paiements
                        ->where(fn ($saisis) => $saisis->whereNull('paiements.contre_passe_paiement_id')->where('paiements.cree_par', $compte->getKey()))
                        ->orWhereIn('paiements.contre_passe_paiement_id', Paiement::withoutGlobalScope(MagasinScope::class)->select('id')->where('cree_par', $compte->getKey())))
                    ->whereNotExists(fn (Builder $fermetures) => $fermetures->selectRaw('1')->from('clotures_caisse_personnelles_paiements')->whereColumn('clotures_caisse_personnelles_paiements.paiement_id', 'paiements.id'))))
            ->orderBy('id')
            ->first(['id', 'nom']);

        if ($ailleurs !== null) {
            throw new LogicException("Fermez d'abord votre caisse de {$ailleurs->nom} : vous n'encaissez que dans un magasin à la fois.");
        }
    }
}
