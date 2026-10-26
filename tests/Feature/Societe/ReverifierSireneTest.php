<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\ChangerSiretAction;
use App\Domain\Tenancy\Actions\ModifierEtablissementAction;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Mail\AlerteEditeurMail;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\ReverificationSirene;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * La reprise des contrôles Sirene (voir app:reverifier-sirene) : le
 * contrôle d'un établissement resté impossible reprend ; la revérification
 * d'une société validée ne change rien de ce qu'elle a enregistré, et
 * signale ses écarts sans rien bloquer. Ce que la commande écrit
 * s'inscrit au journal d'audit, le système pour auteur.
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
     * Le propriétaire connecté demande la reprise par le bouton
     * « Revérifier » : le contrôle de l'établissement de son magasin, créé
     * pendant une panne de l'INSEE, reprend et le vérifie, et s'inscrit au
     * journal d'audit au nom du système, jamais au sien.
     */
    public function test_la_reprise_demandee_par_le_proprietaire_connecte_s_inscrit_au_nom_du_systeme(): void
    {
        app(RepertoireSirene::class)->rendreInjoignable();
        $magasin = $this->ouvrirUneSociete(['a_verifier' => true]);
        app(RepertoireSirene::class)->rendreInjoignable(false);
        $societe = $magasin->societe;
        app(TenantContext::class)->clear();
        $this->actingAs($this->proprietaireDe($magasin));
        $dejaInscrites = $this->entrees($societe)->count();

        app(ReverificationSirene::class)->reprendre($societe, false);

        $this->assertNotNull($magasin->fresh()->etablissement_verifie_le);
        $entree = $this->entrees($societe)->slice($dejaInscrites)->sole();
        $this->assertSame(['verification_enregistree', 'systeme', null], [$entree->action->value, $entree->origine, $entree->auteur_id]);
    }

    /**
     * Après la validation, la revérification mensuelle signale au
     * propriétaire et à l'éditeur une adresse d'établissement qui n'est
     * plus celle du magasin, et une catégorie juridique différente, sans
     * rien bloquer : le magasin reste vérifié.
     */
    public function test_une_adresse_ou_une_categorie_differente_se_signale_au_proprietaire_et_a_l_editeur(): void
    {
        Mail::fake();
        config(['editeur.email' => 'editeur@piliqi.test']);
        $magasin = $this->ouvrirUneSociete();
        $societe = $magasin->societe;
        $proprietaire = $this->proprietaireDe($magasin);
        app(TenantContext::class)->runAsSociete($societe, fn (): bool => $societe->forceFill(['categorie_juridique_insee' => '5499'])->save());
        app(RepertoireSirene::class)->deplacer((string) $magasin->siret, '69001', 'LYON', '69381');

        $this->artisan('app:reverifier-sirene', ['--toutes' => true])->assertSuccessful();

        $this->assertNotNull($magasin->fresh()->etablissement_verifie_le);
        $this->assertSame([true, false], [$societe->fresh()->estValidee(), $societe->fresh()->estClose()]);
        $avis = $proprietaire->notifications()->get()->filter(fn ($notification): bool => $notification->data['title'] === 'Écart au répertoire Sirene')->map(fn ($notification): string => $notification->data['body'])->values();
        $this->assertCount(2, $avis);
        $this->assertStringContainsString('Catégorie juridique lue avant : 5499 ; lue aujourd\'hui : 5710.', $avis->implode("\n"));
        $this->assertStringContainsString("Ma Boutique (SIRET {$magasin->siret}) : L'adresse du magasin n'est plus celle que le répertoire donne de son établissement : 1 RUE DE LA REPUBLIQUE, 69001 LYON.", $avis->implode("\n"));
        Mail::assertSent(AlerteEditeurMail::class, 2);
        Mail::assertSent(AlerteEditeurMail::class, fn (AlerteEditeurMail $mail): bool => $mail->objet === 'Revérification Sirene : écart sur une société validée' && str_contains(implode("\n", $mail->lignes), '69001 LYON'));
    }

    /**
     * La catégorie juridique d'une société validée suit sa dernière lecture
     * au répertoire ; la forme qu'elle a choisie ne change pas, et l'écart
     * se signale (voir
     * test_une_adresse_ou_une_categorie_differente_se_signale_au_proprietaire_et_a_l_editeur).
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
     * Le NIC d'un magasin créé pendant une panne de l'INSEE se corrige
     * pendant que la reprise du contrôle consulte le répertoire, l'INSEE ne
     * répondant plus à la correction. Le contrôle repris, concluant pour
     * l'ancien SIRET, ne vaut pas pour le nouveau : rien ne s'inscrit, le
     * magasin reste à vérifier, et la reprise suivante contrôle le nouveau
     * SIRET, fermé au répertoire, sans le rendre vérifié.
     */
    public function test_un_controle_repris_ne_vaut_pas_pour_un_siret_corrige_pendant_la_consultation(): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Lyon centre', 'a_verifier' => true]);
        $proprietaire = $this->proprietaireDe($premier);
        app(RepertoireSirene::class)->rendreInjoignable();
        $magasin = $this->ouvrirUnAutreMagasin($premier, ['nom' => 'Lyon 2']);
        app(RepertoireSirene::class)->rendreInjoignable(false);
        $ancienSiret = (string) $magasin->siret;
        $nouveauSiret = IdentifiantsInsee::avecSaCle($premier->societe->siren.'9001');
        $this->pendantLaConsultationDe($ancienSiret, function () use ($proprietaire, $magasin, $nouveauSiret): void {
            app(RepertoireSirene::class)->rendreInjoignable();
            app(ModifierEtablissementAction::class)->handle($proprietaire, $magasin->fresh(), ['nic' => substr($nouveauSiret, 9)]);
            app(RepertoireSirene::class)->rendreInjoignable(false);
        });
        app(TenantContext::class)->clear();

        $this->artisan('app:reverifier-sirene')->assertSuccessful();

        $this->assertSame([$nouveauSiret, null], [$magasin->fresh()->siret, $magasin->fresh()->etablissement_verifie_le]);
        $this->assertSame([['indisponible', $ancienSiret], ['indisponible', $nouveauSiret]], $this->controlesDeLEtablissement($magasin));

        $this->artisan('app:reverifier-sirene')->assertSuccessful();

        $this->assertNull($magasin->fresh()->etablissement_verifie_le);
        $this->assertSame([['indisponible', $ancienSiret], ['indisponible', $nouveauSiret], ['negative', $nouveauSiret]], $this->controlesDeLEtablissement($magasin));
    }

    /**
     * Après la validation, le magasin déménage pendant que la
     * revérification mensuelle consulte l'établissement qu'il quitte, que
     * l'INSEE ferme : ce contrôle ne vaut plus pour le magasin, qui a
     * changé de SIRET. Rien ne s'inscrit à son nom, et aucun écart ne se
     * signale, ni au propriétaire ni à l'éditeur.
     */
    public function test_un_demenagement_pendant_la_reverification_mensuelle_ne_signale_aucun_ecart(): void
    {
        Mail::fake();
        config(['editeur.email' => 'editeur@piliqi.test']);
        $siege = $this->ouvrirUneSociete(['nom' => 'Siège']);
        $proprietaire = $this->proprietaireDe($siege);
        $magasin = $this->ouvrirUnAutreMagasin($siege, ['nom' => 'Ma Boutique']);
        $ancienSiret = (string) $magasin->siret;
        $nouveauSiret = IdentifiantsInsee::avecSaCle($magasin->societe->siren.'0007');
        $this->pendantLaConsultationDe($ancienSiret, function () use ($proprietaire, $magasin, $ancienSiret, $nouveauSiret): void {
            app(ChangerSiretAction::class)->handle($proprietaire, $magasin->fresh(), substr($nouveauSiret, 9), 'password');
            app(RepertoireSirene::class)->fermer($ancienSiret);
        });
        app(TenantContext::class)->clear();

        $this->artisan('app:reverifier-sirene', ['--toutes' => true])->assertSuccessful();

        $this->assertSame($nouveauSiret, $magasin->fresh()->siret);
        $this->assertSame([['concluante', $ancienSiret], ['concluante', $nouveauSiret]], $this->controlesDeLEtablissement($magasin));
        $this->assertSame([], $proprietaire->notifications()->get()->filter(fn ($notification): bool => $notification->data['title'] === 'Écart au répertoire Sirene')->all());
        Mail::assertNotSent(AlerteEditeurMail::class);
    }

    /**
     * @return Collection<int, EntreeAudit>
     */
    private function entrees(Societe $societe): Collection
    {
        return app(TenantContext::class)->runAsSociete($societe, fn (): Collection => EntreeAudit::query()->orderBy('id')->get());
    }
}
