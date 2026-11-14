<?php

namespace Tests\Feature\TableauDeBord;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\TypeDeGraphique;
use App\Domain\TableauDeBord\Models\PreferencesDuTableauDeBord;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\TableauDeBord\Services\PreferencesDesGraphiques;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\GrilleDesGraphiques;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les préférences du tableau de bord (décision de l'éditeur du 14/11/2026,
 * lot GRAPH) : pour chaque compte et chaque écran, les graphiques
 * affichés, leur ordre, leur type et les filtres, retenus en base dans la
 * société du compte, jamais dans le navigateur ; une valeur forgée est
 * refusée et rien ne change ; le réglage d'un compte ne touche jamais
 * celui d'un autre.
 */
class PreferencesDesGraphiquesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le propriétaire ajoute « Ventes par taux de TVA », le passe en
     * camembert, le monte avant le chiffre d'affaires par magasin, retire
     * la TVA à payer, choisit l'année précédente, Villeurbanne et la
     * comparaison ; à sa connexion suivante, tout est là, dans cet ordre.
     */
    public function test_ajout_retrait_type_ordre_et_filtres_sont_retenus_apres_une_nouvelle_connexion(): void
    {
        [$lyon, $villeurbanne] = $this->deuxMagasins();
        $proprietaire = $this->proprietaireDe($lyon);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);

        Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value])
            ->call('ajouterUnGraphique', Graphique::VentesParTauxDeTva->value)
            ->call('changerLeType', Graphique::VentesParTauxDeTva->value, TypeDeGraphique::Camembert->value)
            ->call('monter', Graphique::VentesParTauxDeTva->value)
            ->call('retirerLeGraphique', Graphique::TvaAPayerParMois->value)
            ->call('choisirLaPeriode', PeriodeDuTableauDeBord::AnneePrecedente->value)
            ->call('choisirLeMagasin', (string) $villeurbanne->uuid)
            ->call('comparer', true)
            ->assertHasNoErrors();

        app(TenantContext::class)->clear();
        $this->flushSession();
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire->refresh());
        $reglages = app(PreferencesDesGraphiques::class)->lire($proprietaire, app(PerimetreDesGraphiques::class)->deLaSociete($proprietaire, $lyon->societe));

        $this->assertSame([
            'cartes' => [
                ['graphique' => Graphique::VentesEtAchatsParMois->value, 'type' => 'barres'],
                ['graphique' => Graphique::ChiffreDAffairesParMois->value, 'type' => 'barres'],
                ['graphique' => Graphique::VentesParTauxDeTva->value, 'type' => 'camembert'],
                ['graphique' => Graphique::ChiffreDAffairesParMagasin->value, 'type' => 'anneau'],
            ],
            'periode' => 'annee_precedente',
            'magasin' => (string) $villeurbanne->uuid,
            'comparer' => true,
        ], $reglages->versTableau());
        Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value])
            ->assertSeeInOrder(['Ventes et achats HT par mois', 'Chiffre d\'affaires HT par mois', 'Ventes par taux de TVA', 'Chiffre d\'affaires par magasin']);
    }

    /**
     * Chaque valeur forgée (un graphique hors du catalogue, un type que le
     * graphique ne permet pas, une période inconnue, un filtre de magasin dans
     * le panneau d'un magasin) est refusée en alerte, le magasin d'une
     * autre société aussi, sans dire pourquoi, et rien n'est enregistré.
     */
    public function test_une_valeur_forgee_est_refusee_et_rien_ne_change(): void
    {
        [$lyon] = $this->deuxMagasins();
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre Boutique']);
        $proprietaire = $this->proprietaireDe($lyon);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);
        $grille = Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value]);

        foreach ([
            ['ajouterUnGraphique', ['inconnu']],
            ['changerLeType', [Graphique::ChiffreDAffairesParMois->value, TypeDeGraphique::Radar->value]],
            ['changerLeType', [Graphique::ChiffreDAffairesParMois->value, 'histogramme']],
            ['changerLaMesure', [Graphique::ChiffreDAffairesParMois->value, CarteDuTableauDeBord::PAR_QUANTITE]],
            ['choisirLaPeriode', ['depuis_toujours']],
        ] as [$geste, $valeurs]) {
            $grille->call($geste, ...$valeurs)->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
        }

        $grille->call('choisirLeMagasin', (string) $autre->uuid)->assertNotified(PerimetreDesGraphiques::REFUS);

        $this->assertSame(0, PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->count());

        $this->dansLeMagasin($lyon, $proprietaire);
        Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Magasin->value])
            ->call('choisirLeMagasin', (string) $lyon->uuid)
            ->assertNotified(GrilleDesGraphiques::REFUS_DE_LA_VALEUR);
        $this->assertSame(0, PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->count());
    }

    /**
     * Ce qui se lit en base est vérifié valeur par valeur : un graphique
     * inconnu, une carte en double, une période illisible ou un magasin qui
     * n'est pas un identifiant reprennent le défaut, sans rien casser.
     */
    public function test_une_preference_illisible_en_base_reprend_le_defaut(): void
    {
        [$lyon] = $this->deuxMagasins();
        $proprietaire = $this->proprietaireDe($lyon);
        $perimetre = app(PerimetreDesGraphiques::class)->deLaSociete($proprietaire, $lyon->societe);
        $ecrire = fn (array $reglages): int => PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->upsert(
            [['societe_id' => $proprietaire->societe_id, 'user_id' => $proprietaire->id, 'ecran' => EcranDuTableauDeBord::Societe->value, 'reglages' => json_encode($reglages, JSON_THROW_ON_ERROR)]],
            ['societe_id', 'user_id', 'ecran'],
            ['reglages', 'updated_at'],
        );

        $ecrire(['cartes' => [['graphique' => 'inconnu', 'type' => 'barres']], 'periode' => 'depuis_toujours', 'magasin' => 'Lyon', 'comparer' => 'oui']);
        $illisible = app(PreferencesDesGraphiques::class)->lire($proprietaire, $perimetre);
        $ecrire(['cartes' => [['graphique' => 'ca_par_mois', 'type' => 'barres'], ['graphique' => 'ca_par_mois', 'type' => 'lignes']], 'periode' => 'annee_en_cours', 'comparer' => true]);
        $enDouble = app(PreferencesDesGraphiques::class)->lire($proprietaire, $perimetre);

        $this->assertEquals(ReglagesDuTableauDeBord::parDefaut($perimetre)->versTableau(), $illisible->versTableau());
        $this->assertSame(ReglagesDuTableauDeBord::parDefaut($perimetre)->versTableau()['cartes'], $enDouble->versTableau()['cartes']);
        $this->assertSame(['annee_en_cours', true], [$enDouble->periode->value, $enDouble->comparer]);
    }

    /**
     * Le réglage d'un compte ne touche jamais celui d'un autre, ni celui du
     * même compte sur un autre écran : l'administrateur coché garde les
     * graphiques par défaut quand le propriétaire retire les siens, et le
     * panneau du magasin du propriétaire aussi.
     */
    public function test_le_reglage_d_un_compte_ne_touche_jamais_celui_d_un_autre(): void
    {
        [$lyon] = $this->deuxMagasins();
        $proprietaire = $this->proprietaireDe($lyon);
        $administrateur = $this->administrateur($lyon, autorisationsMagasin: [...PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), PermissionRegistry::GRAPHIQUES_VOIR]);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);

        Livewire::test(GrilleDesGraphiques::class, ['ecran' => EcranDuTableauDeBord::Societe->value])
            ->call('retirerLeGraphique', Graphique::VentesEtAchatsParMois->value)
            ->call('retirerLeGraphique', Graphique::TvaAPayerParMois->value);

        $this->assertSame([Graphique::ChiffreDAffairesParMois, Graphique::ChiffreDAffairesParMagasin], $this->graphiquesDe($proprietaire, $lyon));
        $this->assertSame([Graphique::VentesEtAchatsParMois, Graphique::TvaAPayerParMois, Graphique::ChiffreDAffairesParMois, Graphique::ChiffreDAffairesParMagasin], $this->graphiquesDe($administrateur, $lyon));
        $reglagesDuMagasin = app(PreferencesDesGraphiques::class)->lire($proprietaire, app(PerimetreDesGraphiques::class)->duMagasin($proprietaire, $lyon));
        $this->assertSame([Graphique::VentesEtAchatsParMois, Graphique::TvaAPayerParMois, Graphique::ChiffreDAffairesParMois], array_map(fn (CarteDuTableauDeBord $carte): Graphique => $carte->graphique, $reglagesDuMagasin->cartes));
    }

    /**
     * Les graphiques retenus de ce compte dans l'espace de la société.
     *
     * @return list<Graphique>
     */
    private function graphiquesDe(User $compte, Magasin $magasin): array
    {
        app(TenantContext::class)->clear();
        $reglages = app(PreferencesDesGraphiques::class)->lire($compte, app(PerimetreDesGraphiques::class)->deLaSociete($compte, $magasin->societe));

        return array_map(fn (CarteDuTableauDeBord $carte): Graphique => $carte->graphique, $reglages->cartes);
    }

    /**
     * Lyon et Villeurbanne, deux magasins réels de la même société, le
     * 15 octobre 2026 ; aucun contexte n'est actif ensuite.
     *
     * @return array{0: Magasin, 1: Magasin}
     */
    private function deuxMagasins(): array
    {
        $this->travelTo(self::aParis('2026-10-15 10:00:00'));
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        app(TenantContext::class)->clear();

        return [$lyon->fresh(), $villeurbanne->fresh()];
    }
}
