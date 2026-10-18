<?php

namespace Tests\Feature\Societe;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Tenancy\Actions\ChangerSiretAction;
use App\Domain\Tenancy\Actions\DeposerUnePieceAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\ModifierEtablissementAction;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\TypePiece;
use App\Domain\Tenancy\Mail\AlerteEditeurMail;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\ReverificationSirene;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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
     * Le propriétaire connecté demande la reprise par le bouton
     * « Revérifier » : le n° de TVA qu'elle complète s'inscrit au journal
     * des événements de son magasin au nom du système, comme au journal
     * d'audit, jamais au sien.
     */
    public function test_la_reprise_demandee_par_le_proprietaire_connecte_s_inscrit_au_nom_du_systeme(): void
    {
        $societe = $this->societeInscritePendantUnePanne();
        $this->actingAs($societe->proprietaire);
        $magasin = $societe->magasinsReels()->sole();

        app(ReverificationSirene::class)->reprendre($societe, false);

        $evenement = app(TenantContext::class)->runAs($magasin, fn (): EnregistrementFiscal => EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', (string) CodeEvenement::ParametreDeConformite->value)
            ->sole());
        $this->assertSame(['numero_tva', $societe->fresh()->numero_tva], [$evenement->contenuSigne()['donnees']['details']['parametre'], $evenement->contenuSigne()['donnees']['details']['nouvelle_valeur']]);
        $this->assertNull($evenement->operateur_id);
        $this->assertNull($evenement->contenuSigne()['operateur']);
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
     * Une entreprise cessée avant la validation fait refuser la société
     * comme un refus de l'éditeur : la pièce déposée s'efface dès cette
     * décision, le code encore actif s'annule, la démonstration se ferme,
     * et le propriétaire est prévenu, avec le motif du répertoire.
     */
    public function test_un_refus_automatique_efface_les_pieces_annule_les_codes_ferme_la_demonstration_et_previent_le_proprietaire(): void
    {
        Storage::fake(PiecesDeVerification::DISQUE);
        app(RepertoireSirene::class)->rendreInjoignable();
        $societe = $this->inscrireUneSociete(null, 'Luc Bernard');
        app(RepertoireSirene::class)->rendreInjoignable(false);
        $proprietaire = $societe->proprietaire;
        $piece = app(DeposerUnePieceAction::class)->handle($proprietaire, $societe, TypePiece::Identite, 'Carte d\'identité de Luc Bernard');
        $code = app(EnvoyerCodeDeConfirmationAction::class)->handle($societe, MotifCode::Verification, editeur: new InterventionDeLEditeur('Courrier de vérification demandé par l\'éditeur', 'DOSSIER-2026-0003', ['alice']));
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe);
        app(TenantContext::class)->clear();
        app(RepertoireSirene::class)->cesser($societe->siren);

        $this->artisan('app:reverifier-sirene')->assertSuccessful();

        $this->assertTrue($societe->fresh()->estRefusee());
        Storage::disk(PiecesDeVerification::DISQUE)->assertMissing((string) $piece->chemin);
        $this->assertSame(PiecesDeVerification::MOTIF_DECISION, $this->entrees($societe)->where('action.value', 'piece_effacee')->sole()->nouvelles_valeurs['motif']);
        $this->assertNotNull($code->fresh()->annule_le);
        $this->assertSame(StatutMagasin::Ferme, $demonstration->fresh()->statut);
        $avis = $proprietaire->notifications()->sole();
        $this->assertSame('L\'inscription de votre société est refusée', $avis->data['title']);
        $this->assertStringStartsWith(ControleSirene::MOTIF_ENTREPRISE_CESSEE, $avis->data['body']);
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
        app(RepertoireSirene::class)->deplacer((string) $magasin->siret, '69001', 'LYON');

        $this->artisan('app:reverifier-sirene', ['--toutes' => true])->assertSuccessful();

        $this->assertNotNull($magasin->fresh()->etablissement_verifie_le);
        $this->assertSame([true, false], [$societe->fresh()->estValidee(), $societe->fresh()->estRefusee()]);
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
