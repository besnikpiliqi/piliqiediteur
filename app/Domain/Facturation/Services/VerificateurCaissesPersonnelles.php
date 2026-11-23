<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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
        $magasin = app(TenantContext::class)->magasin();

        return $this->vendeursAvecCaisseOuverteDans([CleDuModele::de($magasin) => [$magasin, $journee]])[CleDuModele::de($magasin)];
    }

    /**
     * Pour chacun de ces magasins, les vendeurs dont la caisse personnelle
     * est ouverte au soir de sa journée, dans son calendrier, chacun une
     * fois, par identifiant : la règle de vendeursAvecCaisseOuverte(), lue
     * d'un coup pour tous, en quatre requêtes quel que soit leur nombre,
     * chacune filtrée explicitement par ces magasins et la journée de
     * chacun (la page « Les caisses » de la société lit ainsi les caisses
     * ouvertes de chaque magasin de sa portée, voir CaissesDeLaSociete).
     * Une vente ou un paiement qu'une fermeture a comptés figure dans sa
     * table de liaison, toujours avec une fermeture de son magasin. Sans
     * magasin, rien n'est lu : aucune requête sans filtre de magasin.
     *
     * @param  array<int, array{Magasin, string}>  $journees  le magasin et sa journée (« AAAA-MM-JJ »), par identifiant du magasin
     * @return array<int, Collection<int, User>> par identifiant du magasin
     */
    public function vendeursAvecCaisseOuverteDans(array $journees): array
    {
        if ($journees === []) {
            return [];
        }

        $ouverts = fn (string $table, string $liaison, string $cle) => fn (Builder $fermetures) => $fermetures->selectRaw('1')->from($liaison)->whereColumn("{$liaison}.{$cle}", "{$table}.id");
        $ventesDuSoir = function (EloquentBuilder $ventes) use ($journees): void {
            foreach ($journees as $id => [$magasin, $journee]) {
                $ventes->orWhere(fn (EloquentBuilder $duMagasin) => $duMagasin->where('factures.magasin_id', $id)->where('factures.date_emission', '<=', $magasin->calendrier()->bornesDuJour($journee)[1]->subSecond()));
            }
        };
        $paiementsDuSoir = function (EloquentBuilder|Builder $paiements) use ($journees): void {
            foreach ($journees as $id => [, $journee]) {
                $paiements->orWhere(fn (EloquentBuilder|Builder $duMagasin) => $duMagasin->where('paiements.magasin_id', $id)->where('paiements.recu_le', '<=', $journee));
            }
        };

        $caisses = Facture::withoutGlobalScope(MagasinScope::class)
            ->emises()
            ->whereNotExists($ouverts('factures', 'clotures_caisse_personnelles_factures', 'facture_id'))
            ->where($ventesDuSoir)
            ->distinct()
            ->get(['factures.magasin_id', 'factures.cree_par'])
            ->toBase()
            ->merge(Paiement::withoutGlobalScope(MagasinScope::class)
                ->whereNull('paiements.contre_passe_paiement_id')
                ->whereNotExists($ouverts('paiements', 'clotures_caisse_personnelles_paiements', 'paiement_id'))
                ->where($paiementsDuSoir)
                ->distinct()
                ->get(['paiements.magasin_id', 'paiements.cree_par']))
            ->merge(Paiement::withoutGlobalScope(MagasinScope::class)
                ->whereIn('paiements.magasin_id', array_keys($journees))
                ->whereIn('paiements.id', fn (Builder $contrePassations) => $contrePassations
                    ->select('paiements.contre_passe_paiement_id')
                    ->from('paiements')
                    ->whereNotExists($ouverts('paiements', 'clotures_caisse_personnelles_paiements', 'paiement_id'))
                    ->where($paiementsDuSoir))
                ->distinct()
                ->get(['paiements.magasin_id', 'paiements.cree_par']));
        $vendeurs = User::query()->whereKey($caisses->pluck('cree_par')->all())->orderBy('id')->get();

        return array_map(
            fn (int $id): Collection => $vendeurs->only($caisses->where('magasin_id', $id)->pluck('cree_par')->all()),
            array_combine(array_keys($journees), array_keys($journees)),
        );
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
        return Facture::query()->nonFermeesParLeVendeur(CleDuModele::de($compte))->exists()
            || Paiement::query()->nonFermesParLeVendeur(CleDuModele::de($compte))->exists();
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
