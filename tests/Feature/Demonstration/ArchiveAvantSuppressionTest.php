<?php

namespace Tests\Feature\Demonstration;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Demonstration\Archives\ArchiverUneCopie;
use App\Domain\Demonstration\Archives\FinDesCopies;
use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Bases\BrancherUneBase;
use App\Domain\Demonstration\Construction\CopiesDeLaDemonstration;
use App\Domain\Demonstration\Construction\DossiersDeLaDemonstration;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Etat\EtatDuSite;
use App\Domain\Demonstration\Exceptions\ArchiveImpossible;
use App\Domain\Demonstration\Exceptions\CopieImpossible;
use App\Domain\Demonstration\Jobs\RefaireUneCopie;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Registre\Models\Archive;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Lien;
use App\Domain\Demonstration\Registre\Models\Temoin;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\DemonstrationTestCase;
use ZipArchive;

/**
 * L'archive de la caisse d'une copie avant toute suppression de sa base
 * (lot DEMO-COPIE, C.18, N28) : journées clôturées, sortie du mode
 * d'entraînement (JET 105) dans chaque magasin, registre vérifié, registre
 * et journal des événements exportés, tickets Z de la vie de la copie en
 * PDF, grands totaux, sommaire signé par la clé de caisse de
 * démonstration ; l'archive est relue avant le DROP DATABASE, et une
 * archive qui échoue arrête la suppression. Les archives de six ans
 * s'effacent, pas celles d'avant.
 */
class ArchiveAvantSuppressionTest extends DemonstrationTestCase
{
    public function test_le_message_supprimer_archive_la_caisse_relit_l_archive_puis_supprime_la_base(): void
    {
        $this->travelTo(self::aParis('2026-11-20 15:00:00'));
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
        $this->entrerParLeLien($secret);
        $this->vendreDansLaCopie($copie, 'Les Halles');
        $this->travelTo(self::aParis('2026-11-21 10:00:00'));

        $this->postJson('/interne/messages', $this->messageSigne(['quoi' => 'supprimer', 'reference' => $copie->reference]))
            ->assertOk()
            ->assertExactJson(['etat' => 'supprimee']);

        $copie->refresh();
        $this->assertSame(EtatDUneCopie::Supprimee, $copie->etat);
        $this->assertFalse(app(BasesDeLaDemonstration::class)->existe((string) $copie->nom_de_la_base), 'La base de la copie est supprimée.');
        $this->assertDirectoryDoesNotExist(DossiersDeLaDemonstration::dUneCopie($copie->reference));
        $this->assertSame(0, Lien::on('demo_admin')->where('copie_id', $copie->id)->whereNull('retire_le')->count());
        $this->assertSame(0, Temoin::on('demo_admin')->where('copie_id', $copie->id)->whereNull('retire_le')->count());

        $archive = Archive::on('demo_admin')->where('reference', $copie->reference)->sole();
        $this->assertSame('supprimer', $archive->motif);
        $this->assertSame('2032-11-21T09:00:00Z', MessagesSignes::instant($archive->a_effacer_le), 'L\'archive se garde six ans.');
        $this->assertStringStartsWith(DossiersDeLaDemonstration::desArchives($copie->reference).'/', $archive->chemin);
        $this->assertSame(hash_file('sha256', $archive->chemin), $archive->empreinte);
        app(ArchiverUneCopie::class)->relire($archive);

        $fichiers = $this->fichiersDuZip($archive->chemin);
        $sommaire = json_decode($fichiers['sommaire.json'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['archivee_le', 'cle_id', 'creee_le', 'fichiers', 'fin', 'format', 'magasins', 'motif', 'prete_le', 'reference', 'repere', 'type', 'version_du_code', 'version_du_module_fiscal'], array_keys($sommaire), 'Le sommaire ne porte que la référence, le repère, le type et des données fictives de la copie.');
        $this->assertSame($copie->reference, $sommaire['reference']);
        $this->assertSame('K7M2QD', $sommaire['repere']);
        $this->assertTrue(app(SignataireFiscal::class)->verifier($fichiers['sommaire.json'], $fichiers['sommaire.sig'], $sommaire['cle_id']), 'Le sommaire est signé par la clé de caisse de démonstration.');

        $codes = array_keys($sommaire['magasins']);
        $this->assertCount(2, $codes);

        foreach ($codes as $code) {
            $this->assertArrayHasKey($code.'/registre.csv', $fichiers);
            $journal = $fichiers[$code.'/journal-des-evenements.csv'];
            $this->assertStringContainsString(';'.CodeEvenement::SortieDuModeDemonstration->value.';', $journal, 'Chaque magasin porte la sortie du mode d\'entraînement (JET 105).');
            $this->assertStringContainsString(';'.CodeEvenement::OperationDeControle->value.';', $journal, 'La vérification du registre a eu lieu avant l\'export.');
        }

        $halles = collect($sommaire['magasins'])->filter(fn (array $magasin): bool => $magasin['tickets_z'] > 0);
        $this->assertCount(1, $halles, 'Seul le magasin qui a vendu a un ticket Z, celui de la journée du 20 novembre.');
        $this->assertSame(1200, $halles->first()['grands_totaux']['perpetuel_reel']);
        $this->assertNotEmpty(array_filter(array_keys($fichiers), fn (string $nom): bool => str_contains($nom, '/tickets-z/') && str_ends_with($nom, '.pdf')));

        foreach ($sommaire['fichiers'] as $nom => $empreinte) {
            $this->assertSame($empreinte, hash('sha256', $fichiers[$nom]));
        }
    }

    public function test_la_tache_de_chaque_heure_archive_puis_supprime_la_seule_copie_arrivee_a_sa_fin(): void
    {
        $this->travelTo(self::aParis('2026-11-20 15:00:00'));
        ['copie' => $echue] = $this->copieDeTest(TypeDActivite::Services);
        $this->travelTo(self::aParis('2026-11-21 15:00:00'));
        ['copie' => $vivante] = $this->copieDeTest(TypeDActivite::Services);

        $this->travelTo($echue->fin->addSecond());
        $this->artisan('demonstration:supprimer-les-copies-echues')->assertSuccessful();

        $this->assertSame(EtatDUneCopie::Supprimee, $echue->fresh()?->etat, 'La copie arrivée à sa fin est supprimée par la tâche de chaque heure (C.14).');
        $this->assertFalse(app(BasesDeLaDemonstration::class)->existe((string) $echue->nom_de_la_base));
        $this->assertSame('trente_jours', Archive::on('demo_admin')->where('reference', $echue->reference)->sole()->motif, 'Son archive est faite et relue avant le DROP DATABASE.');
        $this->assertSame(EtatDUneCopie::Prete, $vivante->fresh()?->etat, 'Une copie qui n\'est pas à sa fin reste.');
        $this->assertTrue(app(BasesDeLaDemonstration::class)->existe((string) $vivante->nom_de_la_base));
        $this->assertSame(0, Archive::on('demo_admin')->where('reference', $vivante->reference)->count());
    }

    public function test_recommencer_archive_puis_refait_la_copie_avec_la_meme_reference_le_meme_lien_et_la_meme_fin(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
        $temoin = $this->entrerParLeLien($secret);
        Temoin::on('demo_admin')->where('copie_id', $copie->id)->update(['compte_choisi' => 7]);
        $ancienneBase = (string) $copie->nom_de_la_base;
        $lien = Lien::on('demo_admin')->where('copie_id', $copie->id)->sole();

        $this->postJson('/interne/messages', $this->messageSigne(['quoi' => 'recommencer', 'reference' => $copie->reference]))
            ->assertOk()
            ->assertExactJson(['etat' => 'prete', 'generation' => 1]);

        $refaite = $copie->fresh();
        $this->assertNotNull($refaite);
        $this->assertSame(EtatDUneCopie::Prete, $refaite->etat);
        $this->assertNotSame($ancienneBase, $refaite->nom_de_la_base, 'La copie refaite a une base neuve.');
        $this->assertFalse(app(BasesDeLaDemonstration::class)->existe($ancienneBase));
        $this->assertTrue(app(BasesDeLaDemonstration::class)->existe((string) $refaite->nom_de_la_base));
        $this->assertMemeInstant($copie->fin, $refaite->fin, 'Recommencer ne change pas la date de fin (choix 11).');
        $this->assertSame($lien->id, Lien::on('demo_admin')->where('copie_id', $copie->id)->whereNull('retire_le')->sole()->id, 'Le même lien sert toujours.');
        $this->assertNull(Temoin::on('demo_admin')->where('copie_id', $copie->id)->sole()->compte_choisi, 'Le compte choisi en dernier est effacé : la visite suivante choisit de nouveau.');
        $this->assertSame(FinDesCopies::MOTIF_REMISE_A_ZERO, $refaite->motif_du_message);
        $this->assertStringStartsWith('Votre démonstration a été remise à zéro le ', (string) $refaite->message);
        $this->assertSame('recommencer', Archive::on('demo_admin')->where('reference', $copie->reference)->sole()->motif);

        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/app')->assertRedirect();
    }

    public function test_recommencer_dit_en_preparation_tant_que_le_travail_attend_son_tour_et_n_en_lance_qu_un(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        Bus::fake([RefaireUneCopie::class]);

        $this->postJson('/interne/messages', $this->messageSigne(['quoi' => 'recommencer', 'reference' => $copie->reference]))
            ->assertStatus(202)
            ->assertExactJson(['etat' => 'en_preparation', 'generation' => 1]);
        Bus::assertDispatched(RefaireUneCopie::class, fn (RefaireUneCopie $travail): bool => $travail->reference === $copie->reference);

        Copie::on('demo_admin')->whereKey($copie->id)->update(['etat' => EtatDUneCopie::ARefaire->value]);
        $this->postJson('/interne/messages', $this->messageSigne(['quoi' => 'recommencer', 'reference' => $copie->reference]))->assertStatus(202);
        Bus::assertDispatchedTimes(RefaireUneCopie::class, 1);
        $this->postJson('/interne/messages', $this->messageSigne(['quoi' => 'recommencer', 'reference' => str_repeat('f', 32)]))->assertStatus(410);
    }

    public function test_une_archive_qui_echoue_arrete_la_suppression_et_l_etat_publie_le_dit(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Commerce);
        config(['caisse_securisee.cle_privee' => null]);

        try {
            app(FinDesCopies::class)->supprimer($copie->reference, 'supprimer');
            $this->fail('L\'archive aurait dû échouer.');
        } catch (ArchiveImpossible) {
        }

        $copie->refresh();
        $this->assertSame(EtatDUneCopie::EnArchivage, $copie->etat);
        $this->assertNotNull($copie->cause_de_l_echec);
        $this->assertTrue(app(BasesDeLaDemonstration::class)->existe((string) $copie->nom_de_la_base), 'Jamais de DROP DATABASE sans archive relue.');
        $this->assertSame(0, Archive::on('demo_admin')->count());
        $this->assertSame([], glob(DossiersDeLaDemonstration::desArchives($copie->reference).'/*') ?: [], 'Aucun ZIP à moitié écrit ne reste.');
        $this->assertContains(
            '1 copie(s) arrêtée(s) par une erreur pendant l\'archive de leur caisse ou leur remise à zéro : leur base n\'est pas supprimée sans archive relue.',
            app(EtatDuSite::class)->construire()['problemes'],
        );
    }

    public function test_une_remise_a_zero_dont_l_archive_echoue_garde_sa_base_jusqu_a_ce_que_sa_reprise_l_archive(): void
    {
        $this->modeleDeTest(TypeDActivite::Commerce);
        $this->travelTo(self::aParis('2026-11-20 15:00:00'));
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Commerce);
        $vente = $this->vendreDansLaCopie($copie, 'Les Halles');
        $base = (string) $copie->nom_de_la_base;
        $cle = config('caisse_securisee.cle_privee');
        config(['caisse_securisee.cle_privee' => null]);

        try {
            Bus::dispatchSync(new RefaireUneCopie($copie->reference));
            $this->fail('L\'archive aurait dû échouer.');
        } catch (ArchiveImpossible $erreur) {
            (new RefaireUneCopie($copie->reference))->failed($erreur);
        }

        $copie->refresh();
        $this->assertSame(EtatDUneCopie::ARefaire, $copie->etat, 'Jamais « en échec » : une copie en échec se refait depuis le modèle.');
        $this->assertSame($base, $copie->nom_de_la_base);
        $this->assertNotNull($copie->cause_de_l_echec);
        $this->assertEquals(self::aParis('2026-11-20 16:00:00'), $copie->prochain_essai_le);
        $this->assertContains(
            '1 copie(s) arrêtée(s) par une erreur pendant l\'archive de leur caisse ou leur remise à zéro : leur base n\'est pas supprimée sans archive relue.',
            app(EtatDuSite::class)->construire()['problemes'],
        );

        $this->travelTo(self::aParis('2026-11-20 16:30:00'));

        try {
            Artisan::call('demonstration:reprendre-les-copies');
            $this->fail('L\'archive aurait dû échouer de nouveau.');
        } catch (ArchiveImpossible) {
        }

        $this->assertTrue(app(BasesDeLaDemonstration::class)->existe($base), 'La reprise ne supprime jamais une base sans son archive.');
        $this->assertSame(1, BrancherUneBase::pendant(BrancherUneBase::parLAdministration($base), fn (): int => DB::table('factures')->where('id', $vente->id)->count()));
        $this->assertSame(0, Archive::on('demo_admin')->count());

        config(['caisse_securisee.cle_privee' => $cle]);
        $this->travelTo(self::aParis('2026-11-20 18:00:00'));
        $this->assertSame(0, Artisan::call('demonstration:reprendre-les-copies'));

        $refaite = $copie->fresh();
        $this->assertSame(EtatDUneCopie::Prete, $refaite?->etat);
        $this->assertNotSame($base, $refaite->nom_de_la_base);
        $this->assertFalse(app(BasesDeLaDemonstration::class)->existe($base), 'L\'ancienne base n\'est supprimée qu\'après son archive relue.');
        $this->assertSame('recommencer', Archive::on('demo_admin')->where('reference', $copie->reference)->sole()->motif);
    }

    public function test_la_preparation_ne_supprime_jamais_une_base_qui_existait_avant_elle(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        $base = (string) $copie->nom_de_la_base;
        Copie::on('demo_admin')->whereKey($copie->id)->update(['etat' => EtatDUneCopie::EnEchec->value]);

        try {
            app(CopiesDeLaDemonstration::class)->preparer($copie->reference);
            $this->fail('La préparation devait refuser de remplacer une base qui existe encore.');
        } catch (CopieImpossible $refus) {
            $this->assertSame(CopiesDeLaDemonstration::CAUSE_BASE_A_ARCHIVER, $refus->getMessage());
        }

        $this->assertTrue(app(BasesDeLaDemonstration::class)->existe($base));
        $this->assertSame($base, $copie->fresh()?->nom_de_la_base);
    }

    public function test_une_archive_modifiee_ne_se_relit_plus(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        $archive = app(ArchiverUneCopie::class)->archiver($copie, 'supprimer');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive->chemin));
        $nom = (string) collect(array_keys($archive->sommaire['fichiers']))->first(fn (string $fichier): bool => str_ends_with($fichier, 'registre.csv'));
        $zip->addFromString($nom, 'modifié');
        $zip->close();
        $archive->forceFill(['empreinte' => hash_file('sha256', $archive->chemin)]);

        $this->expectException(ArchiveImpossible::class);
        $this->expectExceptionMessage('le fichier « '.$nom.' » manque ou a changé');
        app(ArchiverUneCopie::class)->relire($archive);
    }

    public function test_les_archives_de_six_ans_s_effacent_et_pas_celles_d_avant(): void
    {
        $this->travelTo(self::aParis('2026-11-20 10:00:00'));
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        $ancienne = app(ArchiverUneCopie::class)->archiver($copie, 'supprimer');
        $this->travelTo(self::aParis('2026-11-20 11:00:00'));
        $recente = app(ArchiverUneCopie::class)->archiver($copie, 'recommencer');

        $this->travelTo(self::aParis('2032-11-20 10:30:00'));
        $this->assertSame(0, Artisan::call('demonstration:purger-les-archives'));

        $this->assertFileDoesNotExist($ancienne->chemin);
        $this->assertNull(Archive::on('demo_admin')->find($ancienne->id));
        $this->assertFileExists($recente->chemin);
        $this->assertNotNull(Archive::on('demo_admin')->find($recente->id));
    }

    /**
     * Un ticket de 12,00 € TTC émis dans ce magasin de la copie, par son
     * responsable de test, comme le ferait la caisse.
     */
    private function vendreDansLaCopie(Copie $copie, string $nomDuMagasin): Facture
    {
        return app(OuvertureDUneCopie::class)->pendant($copie, function () use ($nomDuMagasin): Facture {
            $magasin = Magasin::query()->withoutGlobalScopes()->where('nom', $nomDuMagasin)->sole();
            app(TenantContext::class)->set($magasin);
            $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
            $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [
                ['produit_id' => $produit->id, 'quantite' => 1],
            ]));
            app(TenantContext::class)->clear();
            DB::disconnect();

            return $facture;
        });
    }

    /**
     * @return array<string, string>
     */
    private function fichiersDuZip(string $chemin): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($chemin, ZipArchive::RDONLY));
        $fichiers = [];

        for ($indice = 0; $indice < $zip->numFiles; $indice++) {
            $nom = (string) $zip->getNameIndex($indice);
            $fichiers[$nom] = (string) $zip->getFromName($nom);
        }

        $zip->close();

        return $fichiers;
    }
}
