<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * La reprise des contrôles Sirene (voir app:reverifier-sirene) : seule
 * une société jamais préremplie, pas encore validée, voit son identité
 * complétée depuis le répertoire factice ; la revérification d'une
 * société validée ne change rien de ce qu'elle a enregistré. Ce que la
 * commande écrit s'inscrit au journal d'audit, le système pour auteur.
 */
class ReverifierSireneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Une société validée en franchise en base a retiré le n° de TVA et la
     * raison sociale proposés à son inscription : la revérification
     * mensuelle ne les lui rend pas, et n'inscrit au journal d'audit que
     * ses contrôles.
     */
    public function test_la_reverification_mensuelle_ne_rend_pas_a_une_societe_validee_ce_qu_elle_a_vide(): void
    {
        $magasin = $this->ouvrirUneSociete(['regime_tva' => 'franchise_en_base']);
        $societe = $magasin->societe;
        app(TenantContext::class)->clear();
        app(ModifierIdentiteSocieteAction::class)->handle($this->proprietaireDe($magasin), $societe, ['numero_tva' => '', 'raison_sociale' => '', 'capital_social' => '1000']);
        $dejaInscrites = $this->entrees($societe)->count();

        $this->artisan('app:reverifier-sirene', ['--toutes' => true])->assertSuccessful();

        $this->assertSame([null, null], [$societe->fresh()->numero_tva, $societe->fresh()->raison_sociale]);
        $this->assertSame(
            ['verification_enregistree', 'verification_enregistree'],
            $this->entrees($societe)->slice($dejaInscrites)->map(fn (EntreeAudit $entree): string => $entree->action->value)->values()->all(),
        );
    }

    /**
     * Une société inscrite pendant une panne de l'INSEE, qui a saisi sa
     * raison sociale entre-temps : la reprise du contrôle complète chaque
     * champ encore vide de son identité, garde ce qu'elle a saisi, et
     * inscrit ce complément au journal d'audit, le système pour auteur.
     */
    public function test_la_reprise_d_un_controle_impossible_complete_l_identite_vide_au_journal_d_audit(): void
    {
        $societe = $this->societeInscritePendantUnePanne(['raison_sociale' => 'Ma raison sociale']);

        $this->artisan('app:reverifier-sirene')->assertSuccessful();

        $completee = $societe->fresh();
        $this->assertSame(
            ['Ma raison sociale', '5710', FormeJuridique::Sas, '1 RUE DE LA REPUBLIQUE', '75001', 'PARIS'],
            [$completee->raison_sociale, $completee->categorie_juridique_insee, $completee->forme_juridique, $completee->siege_adresse, $completee->siege_code_postal, $completee->siege_ville],
        );
        $entree = $this->entrees($societe)->where('action.value', 'identite_modifiee')->sole();
        $this->assertSame(['systeme', null], [$entree->origine, $entree->auteur_id]);
        $this->assertSame(
            ['categorie_juridique_insee' => '5710', 'forme_juridique' => 'sas', 'numero_tva' => $completee->numero_tva, 'siege_adresse' => '1 RUE DE LA REPUBLIQUE', 'siege_code_postal' => '75001', 'siege_ville' => 'PARIS'],
            $entree->nouvelles_valeurs,
        );
        $this->assertSame(array_fill_keys(array_keys($entree->nouvelles_valeurs), null), $entree->anciennes_valeurs);
    }

    /**
     * Une entreprise cessée avant la validation de sa société la fait
     * refuser pour toujours à la reprise du contrôle : le refus s'inscrit
     * au journal d'audit, avec son motif.
     */
    public function test_une_entreprise_cessee_avant_la_validation_fait_refuser_la_societe_au_journal_d_audit(): void
    {
        $societe = $this->societeInscritePendantUnePanne();
        app(RepertoireSirene::class)->cesser($societe->siren);

        $this->artisan('app:reverifier-sirene')->assertSuccessful();

        $this->assertTrue($societe->fresh()->estRefusee());
        $entree = $this->entrees($societe)->where('action.value', 'societe_refusee')->sole();
        $this->assertSame(['systeme', ['motif' => ControleSirene::MOTIF_ENTREPRISE_CESSEE]], [$entree->origine, $entree->nouvelles_valeurs]);
    }

    /**
     * La catégorie juridique d'une société validée suit sa dernière lecture
     * au répertoire ; la forme qu'elle a choisie ne change pas, et l'écart
     * se signale à l'éditeur.
     */
    public function test_la_categorie_juridique_suit_le_repertoire_sans_changer_la_forme_d_une_societe_validee(): void
    {
        $societe = $this->ouvrirUneSociete()->societe;
        app(TenantContext::class)->runAsSociete($societe, fn (): bool => $societe->forceFill(['categorie_juridique_insee' => '5499', 'forme_juridique' => FormeJuridique::Sarl])->save());
        Log::spy();

        $this->artisan('app:reverifier-sirene', ['--toutes' => true])->assertSuccessful();

        $this->assertSame(['5710', FormeJuridique::Sarl], [$societe->fresh()->categorie_juridique_insee, $societe->fresh()->forme_juridique]);
        $entree = $this->entrees($societe)->where('action.value', 'identite_modifiee')->sole();
        $this->assertSame([['categorie_juridique_insee' => '5499'], ['categorie_juridique_insee' => '5710'], 'systeme'], [$entree->anciennes_valeurs, $entree->nouvelles_valeurs, $entree->origine]);
        Log::shouldHaveReceived('warning')->with('Revérification Sirene : catégorie juridique différente pour une société validée.', ['societe_id' => $societe->id, 'avant' => '5499', 'apres' => '5710'])->once();
    }

    /**
     * Une société à vérifier, sans identité préremplie, dont le dernier
     * contrôle de l'entreprise est resté impossible, comme après une
     * inscription pendant une panne de l'INSEE ; avec ce que son
     * propriétaire a saisi depuis.
     *
     * @param  array<string, mixed>  $saisie
     */
    private function societeInscritePendantUnePanne(array $saisie = []): Societe
    {
        $proprietaire = User::factory()->for(Societe::factory()->sansIdentiteLegale()->state(['categorie_juridique_insee' => null, ...$saisie]))->create();
        $societe = $this->ouvrirUneSociete(['a_verifier' => true], $proprietaire)->societe;
        app(TenantContext::class)->clear();
        app(TenantContext::class)->runAsSociete($societe, fn () => app(ControleSirene::class)->noterUnite($societe, ['resultat' => ResultatVerification::Indisponible, 'unite' => null, 'motif' => null]));

        return $societe;
    }

    /**
     * @return Collection<int, EntreeAudit>
     */
    private function entrees(Societe $societe): Collection
    {
        return app(TenantContext::class)->runAsSociete($societe, fn (): Collection => EntreeAudit::query()->orderBy('id')->get());
    }
}
