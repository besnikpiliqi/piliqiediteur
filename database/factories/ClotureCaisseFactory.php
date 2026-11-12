<?php

namespace Database\Factories;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\RecapitulatifDeCloture;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClotureCaisse>
 *
 * Une clôture du magasin actif, du jour de son calendrier : la fiche n'a
 * pas encore de magasin_id, que BelongsToMagasin pose à la création depuis
 * TenantContext ; le jour se lit donc dans le calendrier de ce même
 * magasin actif, qui la recevra. Elle est numérotée d'après sa journée et
 * datée de maintenant, puis signée au journal des événements de son
 * magasin par l'événement 50 qui en reprend le contenu, au nom de son
 * auteur (du système sans auteur), comme la clôture le fait (voir
 * CloturerCaisseAction) : sans lui, la base la refuserait à la validation
 * de la transaction (voir GardesDesClotures). Elle ne compte aucun
 * document : un test qui en a besoin passe par la vraie clôture, et un
 * faux ticket Z s'écrit par TestCase::insererUnFauxTicketZ().
 */
class ClotureCaisseFactory extends Factory
{
    protected $model = ClotureCaisse::class;

    public function definition(): array
    {
        return [
            'date_cloture' => app(TenantContext::class)->magasin()->calendrier()->aujourdhui(),
            'numero' => fn (array $clotureCaisse): string => 'Z-FAB-'.str_replace('-', '', ValeursLues::texteDe($clotureCaisse, 'date_cloture')),
            'cloturee_a' => now(),
            'recapitulatif' => RecapitulatifDeCloture::vide(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ClotureCaisse $cloture): void {
            $cloture->refresh();
            $magasin = app(TenantContext::class)->magasin();
            $registre = app(RegistreFiscal::class);
            $calculateur = app(CalculateurLigne::class);
            $auteur = $cloture->cree_par === null ? null : User::query()->findOrFail($cloture->cree_par);
            $recapitulatif = $cloture->recapitulatif;
            $signer = fn () => $registre->journaliser(CodeEvenement::CloturePeriode, [
                'numero' => $cloture->numero,
                'journee' => $cloture->date_cloture,
                'nombre_ventes' => $cloture->nombre_ventes,
                'nombre_avoirs' => $cloture->nombre_avoirs,
                'total_ht' => $calculateur->enCentiemes($cloture->total_ht),
                'total_tva' => $calculateur->enCentiemes($cloture->total_tva),
                'total_ttc' => $calculateur->enCentiemes($cloture->total_ttc),
                'encaissements' => array_column($recapitulatif['encaissements'], 'montant', 'mode'),
                'credit_avoir_impute' => $recapitulatif['credit_avoir_impute'],
                'grands_totaux' => $recapitulatif['grands_totaux'],
                'recapitulatif' => $recapitulatif,
                'derniere_sequence_vente' => $cloture->derniere_sequence_vente,
                'derniere_sequence_encaissement' => $cloture->derniere_sequence_encaissement,
            ], $cloture);

            $registre->sousVerrou($magasin, function () use ($registre, $magasin, $cloture, $auteur, $signer): void {
                $auteur === null ? app(OperateurCourant::class)->pendantLeSysteme($signer) : app(OperateurCourant::class)->pendant($auteur, $signer);
                $registre->noterLaJourneeCloturee($magasin, $cloture->date_cloture);
            });
        });
    }
}
