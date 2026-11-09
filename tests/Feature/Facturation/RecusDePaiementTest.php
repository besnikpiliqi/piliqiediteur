<?php

namespace Tests\Feature\Facturation;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Exceptions\DonneeFiscaleInalterableException;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EncaisserEnPlusieursModesAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureRecuPaiement;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneRecuPaiement;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\RecuPaiement;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\RecuPaiementPdfGenerator;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le reçu de paiement (R2, décision de l'éditeur du 02/11/2026) : chaque
 * encaissement fait après l'émission d'une facture ou d'un ticket, toutes
 * ses parts comprises, et chaque contre-passation donnent un reçu en PDF,
 * produit depuis les paiements enregistrés et signés, jamais recalculé, qui
 * n'est pas une facture et ne modifie jamais le document qu'il règle ; il
 * se télécharge par qui voit ce paiement et part par e-mail chez qui peut
 * envoyer ce document.
 */
class RecusDePaiementTest extends TestCase
{
    use RefreshDatabase;

    private function magasin(): Magasin
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));

        return $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'LYON1']));
    }

    /**
     * Une facture émise, sans règlement, de $quantite articles à ce prix TTC
     * à 20 %.
     */
    private function facture(Client $client, float $prixTtc, int $quantite = 1, ?User $auteur = null): Facture
    {
        $auteur ??= self::auteur();
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, $client, [['produit_id' => $produit->id, 'quantite' => $quantite]]));
    }

    /**
     * « Encaisser » une facture émise, en ces parts, le 09/10/2026.
     *
     * @param  list<PartDeReglement>  $parts
     * @param  list<string>  $bons
     */
    private static function encaisser(Facture $facture, array $parts, array $bons = [], ?User $auteur = null): Facture
    {
        return app(EncaisserEnPlusieursModesAction::class)->handle($auteur ?? self::auteur(), $facture, $parts, $bons, '2026-10-09', (string) Str::uuid());
    }

    /**
     * Les documents qu'un reçu règle : numéro, montant imputé, crédit et
     * restant dû ensuite.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function documentsDu(RecuPaiement $recu): array
    {
        return $recu->lignes()->with('facture')->get()
            ->map(fn (LigneRecuPaiement $ligne): array => [$ligne->facture->numero, $ligne->montant, $ligne->credit, $ligne->reste_du])
            ->all();
    }

    /**
     * Le texte du reçu tel que son PDF l'imprime, entités décodées.
     */
    private static function texteDu(RecuPaiement $recu): string
    {
        return html_entity_decode(app(RecuPaiementPdfGenerator::class)->html($recu->fresh()), ENT_QUOTES);
    }

    public function test_un_encaissement_en_plusieurs_modes_donne_un_seul_recu_qui_dit_ce_qui_reste_du(): void
    {
        $magasin = $this->magasin();
        $facture = $this->facture(Client::factory()->professionnel()->create(['nom' => 'Atelier Dupont SARL']), 100);

        self::encaisser($facture, [new PartDeReglement(ModePaiement::Especes, 3000), new PartDeReglement(ModePaiement::CarteBancaire, 2000, 'CB-778899')]);

        $recu = RecuPaiement::query()->sole();
        $this->assertSame(['RP-LYON1-2026-000001', NatureRecuPaiement::Encaissement, '2026-10-09', 'Ma Boutique'], [$recu->numero, $recu->nature, $recu->recu_le, $recu->emetteur()->nom]);
        $this->assertSame([['especes', '30.00', null], ['carte_bancaire', '20.00', 'CB-778899']], $recu->paiements->map(fn (Paiement $paiement): array => [$paiement->mode_paiement->value, $paiement->montant, $paiement->reference])->all());
        $this->assertSame([[$facture->numero, '50.00', '0.00', '50.00']], self::documentsDu($recu));
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Un paiement saisi seul dans « Paiements », un virement qui règle deux
     * factures, a son reçu, avec chacune et ce qui en reste dû.
     */
    public function test_un_paiement_saisi_dans_paiements_a_son_recu_avec_chaque_facture_reglee(): void
    {
        $this->magasin();
        $client = Client::factory()->professionnel()->create();
        $premiere = $this->facture($client, 60);
        $seconde = $this->facture($client, 30);

        app(EnregistrerPaiementAction::class)->handle(self::auteur(), $client, ['mode_paiement' => ModePaiement::Virement->value, 'montant' => '70.00', 'reference' => 'VIR-42', 'recu_le' => '2026-10-08'], [
            ['facture_id' => $premiere->id, 'montant' => '60.00'],
            ['facture_id' => $seconde->id, 'montant' => '10.00'],
        ], (string) Str::uuid());

        $recu = RecuPaiement::query()->sole();
        $this->assertSame(['2026-10-08', [[$premiere->numero, '60.00', '0.00', '0.00'], [$seconde->numero, '10.00', '0.00', '20.00']]], [$recu->recu_le, self::documentsDu($recu)]);
        $this->assertSame([$recu->id], Paiement::query()->pluck('recu_paiement_id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    /**
     * Un paiement reçu à l'émission, à la caisse ou par « Émettre et
     * encaisser », n'a pas de reçu : le document l'imprime lui-même.
     */
    public function test_un_paiement_recu_a_l_emission_n_a_pas_de_recu(): void
    {
        $this->magasin();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);
        self::vendreAuComptant(self::auteur(), Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $produit->id, 'quantite' => 1]]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);

        app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon, ReglementComptant::horsDeLaCaisse([new PartDeReglement(ModePaiement::Cheque, 1000)]));

        $this->assertSame([0, [null, null]], [RecuPaiement::query()->count(), Paiement::query()->orderBy('id')->pluck('recu_paiement_id')->all()]);
    }

    /**
     * Une contre-passation a son reçu, « Contre-passation de paiement » : le
     * virement de 60,00 € contre-passé rouvre la facture, et le crédit de
     * 60,00 € qu'un avoir sur cette facture laissait disponible s'y impute
     * aussitôt, si bien qu'il n'en reste rien dû.
     */
    public function test_une_contre_passation_a_son_recu_avec_le_credit_qu_elle_reimpute(): void
    {
        $magasin = $this->magasin();
        $client = Client::factory()->professionnel()->create();
        $facture = $this->facture($client, 60);
        self::encaisser($facture, [new PartDeReglement(ModePaiement::Virement, 6000, 'VIR-1')]);
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $virement = Paiement::query()->sole();

        app(ContrePasserPaiementAction::class)->handle(self::auteur(), $virement, 'Virement saisi deux fois', (string) Str::uuid());

        $recu = RecuPaiement::query()->where('nature', NatureRecuPaiement::ContrePassation)->sole();
        $this->assertSame(['RP-LYON1-2026-000002', [['virement', '-60.00', 'VIR-1']]], [$recu->numero, $recu->paiements->map(fn (Paiement $paiement): array => [$paiement->mode_paiement->value, $paiement->montant, $paiement->reference])->all()]);
        $this->assertSame([[$facture->numero, '-60.00', '60.00', '0.00']], self::documentsDu($recu));
        $texte = self::texteDu($recu);
        $this->assertStringContainsString('Contre-passation de paiement', $texte);
        $this->assertStringContainsString('Contre-passation du 09/10/2026', $texte);
        $this->assertStringContainsString("<td class=\"mode\">Avoir</td>\n                <td class=\"reference\"><span class=\"insecable\">{$avoir->numero}</span></td>", $texte);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Les bons d'avoir qu'un encaissement présente pour un ticket du client
     * comptoir resté dû figurent sur son reçu, à côté de la part payée.
     */
    public function test_les_bons_d_avoir_d_un_encaissement_figurent_sur_son_recu(): void
    {
        $this->magasin();
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $premier = self::vendreAuComptant(self::auteur(), $comptoir, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]]);
        $bon = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $comptoir, [['ligne_facture_id' => $premier->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $ticket = $this->facture($comptoir, 50);

        self::encaisser($ticket, [new PartDeReglement(ModePaiement::Especes, 3000)], [$bon->numero]);

        $recu = RecuPaiement::query()->sole();
        $this->assertSame([[$ticket->numero, '30.00', '20.00', '0.00']], self::documentsDu($recu));
        $texte = self::texteDu($recu);
        $this->assertStringContainsString('Total encaissé', $texte);
        $this->assertStringContainsString('Crédit d\'avoir imputé', $texte);
        $this->assertStringContainsString($bon->numero, $texte);
    }

    /**
     * Le reçu imprime tout ce qu'il atteste, A4, avec l'en-tête du magasin
     * et le pied de l'identité de la société, et la mention qu'il n'est pas
     * une facture.
     */
    public function test_le_pdf_du_recu_dit_tout_ce_qu_il_atteste(): void
    {
        $this->magasin();
        $facture = $this->facture(Client::factory()->professionnel()->create(['nom' => 'Atelier Dupont SARL']), 100);
        self::encaisser($facture, [new PartDeReglement(ModePaiement::Especes, 3000), new PartDeReglement(ModePaiement::CarteBancaire, 2000, 'CB-778899')]);
        $recu = RecuPaiement::query()->sole();

        $texte = self::texteDu($recu);
        $pages = $this->pagesDuPdf(app(RecuPaiementPdfGenerator::class)->generer($recu)->output());

        foreach (['Reçu de paiement', 'RP-LYON1-2026-000001', 'Encaissement du 09/10/2026', 'Atelier Dupont SARL', 'Espèces', 'Carte bancaire', 'CB-778899', '30,00 €', '20,00 €', 'Total encaissé', "Facture {$facture->numero}", 'Restant dû après cet encaissement', RecuPaiement::MENTION, 'SIREN'] as $attendu) {
            $this->assertStringContainsString($attendu, $texte, $attendu);
        }
        $this->assertSame('recu-RP-LYON1-2026-000001.pdf', app(RecuPaiementPdfGenerator::class)->nomFichier($recu));
        $this->assertCount(1, $pages);
        $this->assertChaqueTexteTientEntreLesMarges($pages[0]);
    }

    /**
     * Deux téléchargements donnent le même contenu, même une fois le
     * magasin renommé, un autre paiement reçu et la facture soldée : le
     * reçu se produit depuis ce qu'il a enregistré, et le PDF de la facture
     * ne change pas.
     */
    public function test_deux_telechargements_donnent_le_meme_contenu_et_le_document_ne_change_jamais(): void
    {
        $magasin = $this->magasin();
        $facture = $this->facture(Client::factory()->professionnel()->create(), 100);
        $pdfDeLaFactureAvant = app(FacturePdfGenerator::class)->html($facture->fresh());
        self::encaisser($facture, [new PartDeReglement(ModePaiement::Especes, 3000)]);
        $recu = RecuPaiement::query()->sole();
        $premier = self::texteDu($recu);

        $magasin->forceFill(['nom' => 'Nouveau nom'])->save();
        self::encaisser($facture->fresh(), [new PartDeReglement(ModePaiement::Cheque, 7000, 'CHQ 1')]);

        $this->assertSame($premier, self::texteDu($recu));
        $this->assertStringContainsString('Ma Boutique', $premier);
        $this->assertSame([[$facture->numero, '30.00', '0.00', '70.00']], self::documentsDu($recu));
        $this->assertSame($pdfDeLaFactureAvant, app(FacturePdfGenerator::class)->html($facture->fresh()));
        $this->assertSame(2, RecuPaiement::query()->count());
    }

    /**
     * Le magasin de démonstration marque ses reçus « Démonstration — sans
     * valeur ».
     */
    public function test_le_magasin_de_demonstration_marque_ses_recus(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);
        $responsable = $this->membre($demonstration, RoleMagasin::Responsable);
        app(TenantContext::class)->set($demonstration);
        $facture = $this->facture(Client::factory()->create(), 40, auteur: $responsable);

        self::encaisser($facture, [new PartDeReglement(ModePaiement::Especes, 1000)], auteur: $responsable);

        $this->assertStringContainsString('DÉMONSTRATION — SANS VALEUR', self::texteDu(RecuPaiement::query()->sole()));
    }

    /**
     * Un reçu et ses lignes ne se modifient ni ne se suppriment, ni par le
     * modèle ni dans la base.
     */
    public function test_un_recu_ne_se_modifie_ni_ne_se_supprime(): void
    {
        $this->magasin();
        self::encaisser($this->facture(Client::factory()->professionnel()->create(), 100), [new PartDeReglement(ModePaiement::Especes, 3000)]);
        $recu = RecuPaiement::query()->sole();
        $ligne = LigneRecuPaiement::query()->sole();

        $this->assertThrows(fn () => $recu->forceFill(['numero' => 'RP-X'])->save(), DonneeFiscaleInalterableException::class);
        $this->assertThrows(fn () => $ligne->forceFill(['reste_du' => '0.00'])->save(), DonneeFiscaleInalterableException::class);
        $this->assertThrows(fn () => $recu->delete(), DonneeFiscaleInalterableException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('recus_paiement')->update(['numero' => 'RP-X'])), QueryException::class, 'recus_paiement : table en ajout seul, modification refusée');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('lignes_recu_paiement')->delete()), QueryException::class, 'lignes_recu_paiement : table en ajout seul, suppression refusée');

        $this->assertSame(['RP-LYON1-2026-000001', '70.00'], [$recu->fresh()->numero, $ligne->fresh()->reste_du]);
    }

    /**
     * « Envoyer le reçu » : l'e-mail part au client, le PDF du reçu en
     * pièce jointe, et dit qu'il n'est pas une facture.
     */
    public function test_le_recu_part_par_e_mail_avec_son_pdf(): void
    {
        Mail::fake();
        $this->magasin();
        $facture = $this->facture(Client::factory()->professionnel()->create(['email' => 'compta@atelier.fr']), 100);
        self::encaisser($facture, [new PartDeReglement(ModePaiement::Virement, 4000, 'VIR-9')]);
        $recu = RecuPaiement::query()->sole();

        $envoi = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $recu, 'compta@atelier.fr', null, (string) Str::uuid());

        $this->assertSame([StatutEnvoi::Envoye, 'recu_paiement', 'Reçu de paiement RP-LYON1-2026-000001 — Ma Boutique'], [$envoi->fresh()->statut, $envoi->document_type, $envoi->fresh()->objet]);
        Mail::assertSent(DocumentMail::class, function (DocumentMail $mail): bool {
            $pdf = $mail->attachments()[0];

            return $mail->hasTo('compta@atelier.fr')
                && [$pdf->as, $pdf->mime] === ['recu-RP-LYON1-2026-000001.pdf', 'application/pdf']
                && str_contains($mail->render(), 'Veuillez trouver ci-joint notre reçu RP-LYON1-2026-000001 pour votre paiement de 40,00 €')
                && str_contains($mail->render(), RecuPaiement::MENTION);
        });
    }

    /**
     * L'envoi du reçu suit les règles de tout e-mail : la même demande
     * répétée avec le même jeton ne part qu'une fois ; un magasin suspendu
     * n'écrit plus à ses clients ; l'e-mail ne part pas si l'expéditeur a
     * perdu, avant le départ, le droit d'envoyer la facture.
     */
    public function test_l_envoi_du_recu_suit_les_regles_des_e_mails(): void
    {
        Mail::fake();
        Queue::fake([EnvoyerDocumentJob::class]);
        $magasin = $this->magasin();
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        app(TenantContext::class)->set($magasin->fresh());
        $facture = $this->facture(Client::factory()->professionnel()->create(), 100, auteur: $vendeur);
        self::encaisser($facture, [new PartDeReglement(ModePaiement::Especes, 3000)], auteur: $vendeur);
        $recu = RecuPaiement::query()->sole();
        $jeton = (string) Str::uuid();

        $envoi = app(EnvoyerDocumentAction::class)->handle($vendeur, $recu, 'client@exemple.fr', null, $jeton);
        $repete = app(EnvoyerDocumentAction::class)->handle($vendeur, $recu, 'client@exemple.fr', null, $jeton);
        app(ModifierMembreAction::class)->handle($proprietaire, $vendeur, RoleMagasin::Vendeur, array_values(array_diff(RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), [PermissionRegistry::FACTURE_ENVOYER])));
        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->handle();

        $this->assertTrue($envoi->is($repete));
        $this->assertSame([1, StatutEnvoi::Echec, EnvoyerDocumentJob::MOTIF_EXPEDITEUR_SANS_DROIT], [EnvoiDocument::query()->count(), $envoi->fresh()->statut, $envoi->fresh()->erreur]);
        Mail::assertNothingSent();
        $magasin->forceFill(['suspendu_le' => now()])->save();
        $this->assertThrows(fn () => app(EnvoyerDocumentAction::class)->handle(self::auteur(), $recu, 'client@exemple.fr', null, (string) Str::uuid()), VenteNonAutoriseeException::class);
        $this->assertSame(1, EnvoiDocument::query()->count());
    }

    /**
     * Le compte de l'application n'aura, en production, aucun droit de
     * modifier une table en ajout seul, que PostgreSQL exige pour un
     * SELECT ... FOR UPDATE ou FOR SHARE : ni la naissance d'un reçu, ni
     * son envoi par e-mail, ni son téléchargement ne verrouille une ligne
     * de ces tables.
     */
    public function test_ni_l_encaissement_ni_l_envoi_du_recu_ne_verrouille_une_ligne_en_ajout_seul(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SQLite n\'écrit aucun verrou de ligne : ce test ne tourne que sous PostgreSQL.');
        }

        Mail::fake();
        $this->magasin();
        $facture = $this->facture(Client::factory()->professionnel()->create(['email' => 'compta@atelier.fr']), 100);
        $verrous = [];
        DB::listen(static function (QueryExecuted $requete) use (&$verrous): void {
            if (preg_match('/\bfor\s+(no\s+key\s+update|update|key\s+share|share)\b/i', $requete->sql) === 1 && preg_match('/\b(from|join)\s+"(recus_paiement|lignes_recu_paiement|paiements|paiement_allocations|avoir_allocations)"/i', $requete->sql) === 1) {
                $verrous[] = $requete->sql;
            }
        });

        self::encaisser($facture, [new PartDeReglement(ModePaiement::Especes, 3000)]);
        $recu = RecuPaiement::query()->sole();
        app(EnvoyerDocumentAction::class)->handle(self::auteur(), $recu, 'compta@atelier.fr', null, (string) Str::uuid());
        app(RecuPaiementPdfGenerator::class)->generer($recu);

        $this->assertSame([], $verrous);
        Mail::assertSent(DocumentMail::class, 1);
    }

    /**
     * Le reçu se télécharge par qui voit ce paiement, un compte du cabinet
     * lié compris, et part chez qui peut envoyer le document : jamais le
     * cabinet ; un vendeur sans « voir celles de toute l'équipe » n'a que
     * le reçu des encaissements qu'il a faits.
     *
     * @return array<string, array{string, bool, bool}>
     */
    public static function comptes(): array
    {
        return [
            'responsable' => ['responsable', true, true],
            'propriétaire' => ['propriétaire', true, true],
            'vendeur qui n\'a pas encaissé' => ['vendeur', false, false],
            'observateur sans les paiements' => ['observateur', false, false],
            'compte du cabinet lié' => ['cabinet', true, false],
        ];
    }

    #[DataProvider('comptes')]
    public function test_le_recu_se_telecharge_par_qui_voit_le_paiement_et_part_chez_qui_envoie_le_document(string $compte, bool $telecharge, bool $envoie): void
    {
        $magasin = $this->magasin();
        $qui = match ($compte) {
            'responsable' => self::responsableDeTest($magasin),
            'propriétaire' => $this->proprietaireDe($magasin),
            'vendeur' => $this->membre($magasin, RoleMagasin::Vendeur),
            'observateur' => $this->membre($magasin, RoleMagasin::Observateur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES]),
            'cabinet' => $this->collaborateurDuCabinetLie($magasin),
        };
        app(TenantContext::class)->set($magasin->fresh());
        self::encaisser($this->facture(Client::factory()->professionnel()->create(), 100, auteur: self::responsableDeTest($magasin)), [new PartDeReglement(ModePaiement::Especes, 3000)], auteur: self::responsableDeTest($magasin));
        $recu = RecuPaiement::query()->sole();

        $this->assertSame([$telecharge, $envoie], [$qui->can('view', $recu), $qui->can('envoyer', $recu)]);
    }

    /**
     * Le vendeur qui a émis la facture l'envoie, mais ne voit ni n'envoie
     * le reçu d'un encaissement qu'un autre a fait sur elle, sans « voir
     * celles de toute l'équipe ».
     */
    public function test_le_vendeur_auteur_de_la_facture_n_envoie_pas_le_recu_d_un_encaissement_fait_par_un_autre(): void
    {
        $magasin = $this->magasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        app(TenantContext::class)->set($magasin->fresh());
        $facture = $this->facture(Client::factory()->professionnel()->create(), 100, auteur: $vendeur);
        self::encaisser($facture, [new PartDeReglement(ModePaiement::Especes, 3000)], auteur: self::responsableDeTest($magasin));
        $recu = RecuPaiement::query()->sole();

        $this->assertTrue($vendeur->can('envoyer', $facture->fresh()));
        $this->assertSame([false, false], [$vendeur->can('view', $recu), $vendeur->can('envoyer', $recu)]);
    }

    /**
     * Sur la fiche de la facture, chaque paiement reçu après l'émission
     * propose son reçu ; le téléchargement donne le PDF du reçu.
     */
    public function test_la_fiche_de_la_facture_telecharge_le_recu_de_chaque_encaissement(): void
    {
        $magasin = $this->magasin();
        $facture = $this->facture(Client::factory()->professionnel()->create(), 100);
        self::encaisser($facture, [new PartDeReglement(ModePaiement::Especes, 3000)]);
        $this->actingAs(self::auteur());
        Filament::setTenant($magasin);

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSee('RP-LYON1-2026-000001')
            ->assertInfolistActionVisible('allocationsPaiement.0.telecharger_recuAction', 'telecharger_recu')
            ->callInfolistAction('allocationsPaiement.0.telecharger_recuAction', 'telecharger_recu')
            ->assertFileDownloaded('recu-RP-LYON1-2026-000001.pdf');
    }

    /**
     * Le client comptoir sans adresse ne reçoit jamais de reçu par e-mail :
     * l'action ne se propose pas.
     */
    public function test_le_recu_ne_se_propose_pas_par_e_mail_au_client_comptoir_sans_adresse(): void
    {
        $magasin = $this->magasin();
        $ticket = $this->facture(Client::query()->where('est_comptoir', true)->sole(), 50);
        self::encaisser($ticket, [new PartDeReglement(ModePaiement::Especes, 2000)]);
        $this->actingAs(self::auteur());
        Filament::setTenant($magasin);

        Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])
            ->assertInfolistActionVisible('allocationsPaiement.0.telecharger_recuAction', 'telecharger_recu')
            ->assertDontSeeHtml('allocationsPaiement.0.envoyer_recuAction')
            ->mountInfolistAction('allocationsPaiement.0.envoyer_recuAction', 'envoyer_recu')
            ->assertSet('mountedInfolistActions', []);
    }

    /**
     * Le reçu d'un magasin reste dans son magasin : un autre magasin de la
     * même société ne le voit pas.
     */
    public function test_un_autre_magasin_ne_voit_pas_le_recu(): void
    {
        $magasin = $this->magasin();
        self::encaisser($this->facture(Client::factory()->professionnel()->create(), 100), [new PartDeReglement(ModePaiement::Especes, 3000)]);
        $recu = RecuPaiement::query()->sole();
        $autre = $this->ouvrirUnAutreMagasin($magasin);

        app(TenantContext::class)->set($autre);

        $this->assertSame([0, 0, false], [RecuPaiement::query()->count(), LigneRecuPaiement::query()->count(), self::responsableDeTest($autre)->can('view', $recu)]);
    }
}
