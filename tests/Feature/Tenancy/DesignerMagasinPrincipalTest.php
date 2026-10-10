<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Exceptions\ReglagesCommunsDifferentsException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ReglagesCommunsDeLaSociete;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * La commande de l'éditeur qui désigne le magasin principal d'une
 * société (K5) : il fixe ensuite seul les réglages communs aux magasins
 * de son SIREN.
 */
class DesignerMagasinPrincipalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le magasin désigné fixe désormais les réglages communs, à la place
     * du plus ancien ; le magasin désigné d'une autre société garde sa
     * désignation. La désignation s'inscrit au journal de l'application.
     */
    public function test_le_magasin_designe_fixe_seul_les_reglages_communs_de_sa_societe(): void
    {
        $plusAncien = $this->magasinDeLaSociete();
        $designe = Magasin::factory()->deLaSocieteDe($plusAncien)->create(['nom' => 'Part-Dieu']);
        $autreSociete = Magasin::factory()->create(['siren' => '552100554', 'siret' => '55210055400013']);
        $autreSociete->forceFill(['designe_principal' => true])->save();
        Log::spy();

        $this->artisan('app:designer-magasin-principal', ['magasin' => $designe->id, '--force' => true])
            ->expectsOutputToContain("« Part-Dieu » (#{$designe->id}) est désormais le magasin principal du SIREN 732829320.")
            ->assertSuccessful();

        $this->changerSonRegime($designe, RegimeTva::ReelNormalTrimestriel);
        $this->assertThrows(fn () => $this->changerSonRegime($plusAncien, RegimeTva::FranchiseEnBase), ReglagesCommunsDifferentsException::class);
        $this->assertSame([true, null, true], [$designe->fresh()->designe_principal, $plusAncien->fresh()->designe_principal, $autreSociete->fresh()->designe_principal]);
        Log::shouldHaveReceived('info')->once()->with('Magasin principal désigné par l\'éditeur', ['siren' => '732829320', 'magasin_id' => $designe->id, 'ancien_principal_id' => $plusAncien->id]);
    }

    /**
     * Avant de désigner, la commande dit quels magasins devront s'aligner
     * sur le nouveau principal, et sur quoi ; sans confirmation, rien ne
     * change.
     */
    public function test_la_commande_dit_les_reglages_a_aligner_et_ne_designe_rien_sans_confirmation(): void
    {
        $plusAncien = $this->magasinDeLaSociete();
        $suivant = Magasin::factory()->deLaSocieteDe($plusAncien)->create(['nom' => 'Part-Dieu']);
        $this->changerSonRegime($plusAncien, RegimeTva::ReelNormalTrimestriel);

        $this->artisan('app:designer-magasin-principal', ['magasin' => $suivant->uuid])
            ->expectsTable(['Magasin du SIREN', 'Nom', 'Réglages communs à aligner sur le nouveau principal'], [
                ["#{$plusAncien->id} (principal actuel)", 'Bellecour', 'régime de TVA « Réel normal (déclaration mensuelle) »'],
            ])
            ->expectsConfirmation("Désigner « Part-Dieu » (#{$suivant->id}) magasin principal du SIREN 732829320 ?", 'no')
            ->expectsOutputToContain('Désignation abandonnée : rien n\'a changé.')
            ->assertFailed();

        $this->assertSame([null, null], [$plusAncien->fresh()->designe_principal, $suivant->fresh()->designe_principal]);
    }

    /**
     * Une seule désignation par SIREN : la nouvelle efface l'ancienne,
     * même portée par un magasin supprimé depuis.
     */
    public function test_une_nouvelle_designation_efface_l_ancienne_meme_d_un_magasin_supprime(): void
    {
        $premier = $this->magasinDeLaSociete();
        $second = Magasin::factory()->deLaSocieteDe($premier)->create();
        $this->artisan('app:designer-magasin-principal', ['magasin' => $second->id, '--force' => true])->assertSuccessful();
        $second->delete();

        $this->artisan('app:designer-magasin-principal', ['magasin' => $premier->id, '--force' => true])->assertSuccessful();

        $this->assertSame([true, null], [$premier->fresh()->designe_principal, Magasin::withTrashed()->find($second->id)->designe_principal]);
    }

    /**
     * Un magasin qui change de SIREN perd sa désignation : il ne devient
     * pas principal de sa nouvelle société.
     */
    public function test_un_changement_de_siren_efface_la_designation(): void
    {
        $designe = $this->magasinDeLaSociete();
        $this->artisan('app:designer-magasin-principal', ['magasin' => $designe->id, '--force' => true])->assertSuccessful();

        $designe->update(['siren' => '552100554', 'siret' => '55210055400013']);

        $this->assertNull($designe->fresh()->designe_principal);
    }

    /**
     * Un magasin désigné rejoint une société qui a déjà son magasin
     * désigné : il perd sa désignation en prenant le nouveau SIREN, même
     * chargé avant elle, et le magasin désigné de cette société reste son
     * principal, bien que plus récent que lui.
     */
    public function test_un_magasin_designe_rejoint_une_societe_qui_a_deja_son_magasin_designe(): void
    {
        $designeAilleurs = $this->magasinDeLaSociete();
        $designeIci = Magasin::factory()->create(['siren' => '552100554', 'siret' => '55210055400013', 'regime_tva' => RegimeTva::ReelNormalTrimestriel]);
        $this->artisan('app:designer-magasin-principal', ['magasin' => $designeAilleurs->id, '--force' => true])->assertSuccessful();
        $this->artisan('app:designer-magasin-principal', ['magasin' => $designeIci->id, '--force' => true])->assertSuccessful();

        app(TenantContext::class)->runAs($designeAilleurs, fn () => $designeAilleurs->update(['siren' => '552100554', 'siret' => '55210055400021', ...$designeIci->only(array_keys(ReglagesCommunsDeLaSociete::CHAMPS))]));

        $this->assertSame(['552100554', null, true], [$designeAilleurs->fresh()->siren, $designeAilleurs->fresh()->designe_principal, $designeIci->fresh()->designe_principal]);
        $this->assertSame($designeIci->id, app(ReglagesCommunsDeLaSociete::class)->idDuMagasinPrincipal('552100554'));
    }

    public function test_un_magasin_sans_siren_n_est_pas_designe(): void
    {
        $magasin = Magasin::factory()->sansIdentiteLegale()->create(['nom' => 'Croix-Rousse']);

        $this->artisan('app:designer-magasin-principal', ['magasin' => $magasin->id, '--force' => true])
            ->expectsOutputToContain('Le magasin « Croix-Rousse » n\'a pas de SIREN : il n\'appartient à aucune société.')
            ->assertFailed();

        $this->assertNull($magasin->fresh()->designe_principal);
    }

    public function test_un_magasin_inconnu_est_refuse(): void
    {
        $this->artisan('app:designer-magasin-principal', ['magasin' => '999', '--force' => true])
            ->expectsOutputToContain('Aucun magasin « 999 ».')
            ->assertFailed();
    }

    /**
     * Le magasin Bellecour de la société SIREN 732829320, réel normal
     * mensuel.
     */
    private function magasinDeLaSociete(): Magasin
    {
        return Magasin::factory()->create([
            'nom' => 'Bellecour',
            'siren' => '732829320',
            'siret' => '73282932000074',
            'regime_tva' => RegimeTva::ReelNormalMensuel,
        ]);
    }

    private function changerSonRegime(Magasin $magasin, RegimeTva $regime): void
    {
        app(TenantContext::class)->runAs($magasin, fn () => $magasin->update(['regime_tva' => $regime]));
    }
}
