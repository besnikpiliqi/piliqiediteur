<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\AvoirPdfGenerator;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Facturation\Services\EReportingDesParticuliers;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Facturation\Services\MentionsFiscales;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La bascule des mentions de TVA du code général des impôts (CGI) au code
 * des impositions sur les biens et services (CIBS), pour les documents
 * émis à partir du 1er janvier 2027 au calendrier de leur magasin
 * (décisions de Claude T40.1 et T40.2 du 22/11/2026, D76, voir
 * TableDesMentionsDeTva) : chaque document signe à son émission la
 * mention de son jour, et l'imprime ensuite pour toujours, dans son PDF,
 * téléchargé, réimprimé ou joint à un e-mail, et sur sa fiche ; un avoir,
 * un brouillon émis plus tard et la facture tirée d'un devis suivent le
 * jour de leur propre émission ; un devis envoyé se relit au jour de son
 * dernier envoi ; l'aperçu de l'e-reporting suit le jour de sa clôture.
 * Le texte des PDF se lit dans le PDF produit (voir
 * TestCase::pagesDuPdf()).
 */
class BasculeDesMentionsAuCibsTest extends TestCase
{
    use RefreshDatabase;

    private const EXPORT_DU_CGI = 'Exonération de TVA, article 262 I du CGI';

    private const EXPORT_DU_CIBS = 'Exonération de TVA, articles L. 213-12, 2° et L. 213-14 du CIBS';

    private const FRANCHISE_DU_CGI = 'TVA non applicable, art. 293 B du CGI';

    private const FRANCHISE_DU_CIBS = 'TVA non applicable, art. L. 233-3 du CIBS';

    private const LIVRAISON_NEW_YORK = ['livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US'];

    /**
     * Un export émis à Paris à la dernière seconde de 2026 signe et imprime
     * l'article du CGI, celui de la première seconde de 2027 celui du CIBS ;
     * réimprimé après la fin de la tolérance des références au CGI (30 juin
     * 2028), le premier cite toujours le CGI, tel qu'il l'a signé, sur son
     * PDF comme dans le motif de son taux de 0 %.
     */
    public function test_un_export_de_la_veille_cite_le_cgi_pour_toujours_et_celui_du_jour_meme_le_cibs(): void
    {
        $this->travelTo(self::aParis('2026-12-31 23:00:00'));
        $this->magasinDeParis();
        $client = $this->clientAmericain();
        $produit = $this->produit();

        $this->travelTo(self::aParis('2026-12-31 23:59:59'));
        $veille = $this->exporter($client, $produit);
        $this->travelTo(self::aParis('2027-01-01 00:00:00'));
        $jourMeme = $this->exporter($client, $produit);

        $this->assertContains(self::EXPORT_DU_CGI, $veille->mentionsSignees());
        $this->assertContains(self::EXPORT_DU_CIBS, $jourMeme->mentionsSignees());
        $this->assertTexteDuPdf(self::EXPORT_DU_CGI, 'CIBS', app(FacturePdfGenerator::class)->generer($veille)->output());
        $this->assertTexteDuPdf(self::EXPORT_DU_CIBS, '262 I', app(FacturePdfGenerator::class)->generer($jourMeme)->output());

        $this->travelTo(self::aParis('2028-09-01 10:00:00'));
        $duplicata = Facture::query()->findOrFail($veille->id);

        $this->assertTexteDuPdf(self::EXPORT_DU_CGI, 'CIBS', app(FacturePdfGenerator::class)->generer($duplicata)->output());
        $this->assertSame([self::EXPORT_DU_CGI, self::EXPORT_DU_CGI], [app(MentionsFiscales::class)->mentionDeLaNature($duplicata), app(MentionsFiscales::class)->motifDuTauxZero($duplicata)]);
        $this->assertSame('2026-12-31', app(MentionsFiscales::class)->jourDesMentions($duplicata));
        $enregistrement = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente->value)->where('document_type', $duplicata->getMorphClass())->where('document_id', $duplicata->id)->sole();
        $this->assertSame(ValeursLues::tableauDe(ValeursLues::tableauDe($enregistrement->donneesSignees(), 'contexte'), 'mentions'), app(MentionsFiscales::class)->imprimees($duplicata, $duplicata->emetteur()), 'Le PDF imprime les mentions que le registre a signées.');
    }

    /**
     * Un avoir émis en 2027 sur un export de 2026 cite le CIBS, au jour de
     * sa propre émission ; l'export qu'il corrige garde le CGI. La facture
     * tirée en 2027 d'un devis d'export accepté en 2026 cite aussi le CIBS,
     * et le devis accepté garde la mention de son envoi.
     */
    public function test_un_avoir_et_la_facture_d_un_devis_emis_en_2027_suivent_le_jour_de_leur_emission(): void
    {
        $this->travelTo(self::aParis('2026-12-15 10:00:00'));
        $this->magasinDeParis();
        $client = $this->clientAmericain();
        $produit = $this->produit();
        $export = $this->exporter($client, $produit);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 3]], ['nature_operation' => NatureOperation::ExportHorsUe, ...self::LIVRAISON_NEW_YORK, 'date_validite' => '2027-01-31']);
        app(AccepterDevisAction::class)->handle(self::auteur(), app(EmettreDevisAction::class)->handle(self::auteur(), $devis));

        $this->travelTo(self::aParis('2027-01-05 10:00:00'));
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $export->lignes->sole()->id, 'quantite' => 1]], 'Lampe rapportée');
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureDepuisDevisAction::class)->handle(self::auteur(), $devis->fresh()));

        $this->assertContains(self::EXPORT_DU_CIBS, $avoir->mentionsSignees());
        $this->assertTexteDuPdf(self::EXPORT_DU_CIBS, '262 I', app(AvoirPdfGenerator::class)->generer($avoir)->output());
        $this->assertTexteDuPdf(self::EXPORT_DU_CGI, 'CIBS', app(FacturePdfGenerator::class)->generer($export->fresh())->output());
        $this->assertContains(self::EXPORT_DU_CIBS, $facture->mentionsSignees());
        $this->assertTexteDuPdf(self::EXPORT_DU_CGI, 'CIBS', app(DevisPdfGenerator::class)->generer($devis->fresh())->output());
    }

    /**
     * Un brouillon imprimé le 31 décembre 2026 cite le CGI, comme il
     * s'émettrait ce jour-là ; imprimé et émis le 2 janvier 2027, il cite
     * le CIBS et le signe.
     */
    public function test_un_brouillon_cite_la_mention_du_jour_ou_il_s_imprime_puis_celle_de_son_emission(): void
    {
        $this->travelTo(self::aParis('2026-12-31 18:00:00'));
        $this->magasinDeParis();
        $brouillon = $this->brouillonDExport($this->clientAmericain(), $this->produit());

        $this->assertTexteDuPdf(self::EXPORT_DU_CGI, 'CIBS', app(FacturePdfGenerator::class)->generer($brouillon)->output());

        $this->travelTo(self::aParis('2027-01-02 09:00:00'));
        $this->assertTexteDuPdf(self::EXPORT_DU_CIBS, '262 I', app(FacturePdfGenerator::class)->generer($brouillon->fresh())->output());
        $emise = app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon->fresh());

        $this->assertContains(self::EXPORT_DU_CIBS, $emise->mentionsSignees());
        $this->assertTexteDuPdf(self::EXPORT_DU_CIBS, '262 I', app(FacturePdfGenerator::class)->generer($emise)->output());
    }

    /**
     * Les tickets en franchise en base suivent le calendrier de leur
     * magasin : à La Réunion, celui du 1er janvier 2027 à 0 h 30, encore le
     * 31 décembre à Paris, cite le CIBS ; en Martinique, celui du
     * 31 décembre 2026 à 20 h, déjà le 1er janvier à Paris, cite le CGI, et
     * celui du 1er janvier à 0 h 30 le CIBS. L'aperçu de l'e-reporting de
     * chaque journée cite la franchise de son jour, et le ticket du
     * 31 décembre se réimprime en 2027 avec le CGI.
     */
    public function test_les_tickets_en_franchise_et_l_apercu_de_l_e_reporting_suivent_le_calendrier_de_leur_magasin(): void
    {
        $this->travelTo(self::aLHeureDe(Territoire::Reunion, '2027-01-01 00:30:00'));
        $this->ouvrirUneSocieteSituee(Territoire::Reunion, ['regime_tva' => RegimeTva::FranchiseEnBase]);
        $ticketDeLaReunion = $this->vendreEnFranchise();

        $this->assertSame('2026-12-31', $this->jourDeParis());
        $this->assertSame([self::FRANCHISE_DU_CIBS], array_values(array_intersect([self::FRANCHISE_DU_CGI, self::FRANCHISE_DU_CIBS], $ticketDeLaReunion->mentionsSignees())));

        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-12-31 20:00:00'));
        $martinique = $this->ouvrirUneSocieteSituee(Territoire::Martinique, ['regime_tva' => RegimeTva::FranchiseEnBase]);
        $ticketDu31 = $this->vendreEnFranchise();

        $this->assertSame('2027-01-01', $this->jourDeParis());
        $this->assertContains(self::FRANCHISE_DU_CGI, $ticketDu31->mentionsSignees());

        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2027-01-01 00:10:00'));
        $clotureDu31 = app(CloturerCaisseAction::class)->handle(self::auteur(), $martinique, '2026-12-31');
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2027-01-01 00:30:00'));
        $ticketDu1er = $this->vendreEnFranchise();
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2027-01-02 00:10:00'));
        $clotureDu1er = app(CloturerCaisseAction::class)->handle(self::auteur(), $martinique, '2027-01-01');

        $this->assertContains(self::FRANCHISE_DU_CIBS, $ticketDu1er->mentionsSignees());
        $this->assertSame(['Franchise en base de TVA (art. 293 B du CGI)'], array_values(array_unique(array_column(app(EReportingDesParticuliers::class)->apercu($clotureDu31)->transactions, 'motif'))));
        $this->assertSame(['Franchise en base de TVA (art. L. 233-3 du CIBS)'], array_values(array_unique(array_column(app(EReportingDesParticuliers::class)->apercu($clotureDu1er)->transactions, 'motif'))));
        $this->assertTexteDuPdf(self::FRANCHISE_DU_CGI, 'CIBS', app(FacturePdfGenerator::class)->generer($ticketDu31->fresh())->output());
    }

    /**
     * Un devis envoyé le 20 décembre 2026 se relit en janvier 2027 avec la
     * mention de son envoi ; modifié, il redevient un brouillon qui cite le
     * CIBS, et repart avec lui.
     */
    public function test_un_devis_envoye_en_2026_garde_la_mention_de_son_envoi_jusqu_a_sa_modification(): void
    {
        $this->travelTo(self::aParis('2026-12-20 10:00:00'));
        $this->magasinDeParis(['regime_tva' => RegimeTva::FranchiseEnBase]);
        $client = Client::factory()->create(['nom' => 'Hélène Garnier']);
        $lignes = [['produit_id' => $this->produit()->id, 'quantite' => 1]];
        $devis = app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(self::auteur(), $client, $lignes, ['date_validite' => '2027-01-31']));

        $this->travelTo(self::aParis('2027-01-10 10:00:00'));
        $envoye = Devis::query()->findOrFail($devis->id);

        $this->assertSame('2026-12-20', app(MentionsFiscales::class)->jourDesMentions($envoye));
        $this->assertTexteDuPdf(self::FRANCHISE_DU_CGI, 'CIBS', app(DevisPdfGenerator::class)->generer($envoye)->output());

        $modifie = app(ModifierDevisAction::class)->handle(self::auteur(), $envoye, $client, $lignes, ['date_validite' => '2027-01-31']);

        $this->assertSame('2027-01-10', app(MentionsFiscales::class)->jourDesMentions($modifie));
        $this->assertTexteDuPdf(self::FRANCHISE_DU_CIBS, '293 B', app(DevisPdfGenerator::class)->generer($modifie)->output());
        $this->assertTexteDuPdf(self::FRANCHISE_DU_CIBS, '293 B', app(DevisPdfGenerator::class)->generer(app(EmettreDevisAction::class)->handle(self::auteur(), $modifie))->output());
    }

    /**
     * Envoyée par e-mail en 2027, une facture de 2026 part avec le PDF de
     * son émission, qui cite le CGI ; une facture de 2027 part avec le
     * sien, qui cite le CIBS.
     */
    public function test_l_e_mail_d_une_facture_joint_le_pdf_de_son_emission(): void
    {
        Mail::fake();
        $this->travelTo(self::aParis('2026-12-15 10:00:00'));
        $this->magasinDeParis();
        $client = $this->clientAmericain();
        $produit = $this->produit();
        $deDecembre = $this->exporter($client, $produit);

        $this->travelTo(self::aParis('2027-02-01 10:00:00'));
        $deFevrier = $this->exporter($client, $produit);
        app(EnvoyerDocumentAction::class)->handle(self::auteur(), $deDecembre->fresh(), 'purchasing@acme.example', null);
        app(EnvoyerDocumentAction::class)->handle(self::auteur(), $deFevrier->fresh(), 'purchasing@acme.example', null);

        $this->assertTexteDuPdf(self::EXPORT_DU_CGI, 'CIBS', $this->pdfJoint(fn (DocumentMail $mail): bool => $mail->envoi->document_id === $deDecembre->id));
        $this->assertTexteDuPdf(self::EXPORT_DU_CIBS, '262 I', $this->pdfJoint(fn (DocumentMail $mail): bool => $mail->envoi->document_id === $deFevrier->id));
    }

    /**
     * La fiche d'une facture montre, sous sa nature, la mention qu'elle a
     * signée : en septembre 2028, celle du CGI pour une facture de 2026,
     * celle du CIBS pour une facture de 2027 ; la fiche d'un brouillon, la
     * mention du jour.
     */
    public function test_la_fiche_d_une_facture_montre_la_mention_qu_elle_a_signee(): void
    {
        $this->travelTo(self::aParis('2026-12-30 10:00:00'));
        $magasin = $this->magasinDeParis();
        $this->actingAs(self::responsableDeTest($magasin));
        Filament::setTenant($magasin);
        $client = $this->clientAmericain();
        $produit = $this->produit();
        $de2026 = $this->exporter($client, $produit);
        $this->travelTo(self::aParis('2027-01-04 10:00:00'));
        $de2027 = $this->exporter($client, $produit);
        $brouillon = $this->brouillonDExport($client, $produit);

        $this->travelTo(self::aParis('2028-09-01 10:00:00'));

        Livewire::test(ViewFacture::class, ['record' => $de2026->getRouteKey()])->assertSeeText(self::EXPORT_DU_CGI)->assertDontSeeText('CIBS');
        Livewire::test(ViewFacture::class, ['record' => $de2027->getRouteKey()])->assertSeeText(self::EXPORT_DU_CIBS)->assertDontSeeText('262 I');
        Livewire::test(ViewFacture::class, ['record' => $brouillon->getRouteKey()])->assertSeeText(self::EXPORT_DU_CIBS)->assertDontSeeText('262 I');
    }

    /**
     * Le magasin d'une société de Paris à l'identité légale complète,
     * magasin actif, avec ces réglages de la société.
     *
     * @param  array<string, mixed>  $reglages
     */
    private function magasinDeParis(array $reglages = []): Magasin
    {
        return $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique', ...$reglages]));
    }

    private function clientAmericain(): Client
    {
        return Client::factory()->create(['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789']);
    }

    private function produit(): Produit
    {
        return Produit::factory()->create(['nom' => 'Lampe de bureau', 'prix_vente_ttc' => '60.00', 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    private function brouillonDExport(Client $client, Produit $produit): Facture
    {
        return app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 2]], ['nature_operation' => NatureOperation::ExportHorsUe, ...self::LIVRAISON_NEW_YORK]);
    }

    private function exporter(Client $client, Produit $produit): Facture
    {
        return app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillonDExport($client, $produit));
    }

    /**
     * Un ticket à la caisse du magasin actif, en franchise en base, d'un
     * article à 12,00 €.
     */
    private function vendreEnFranchise(): Facture
    {
        return self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => Produit::factory()->create(['nom' => 'Savon au coco', 'prix_vente_ttc' => '12.00', 'taux_tva' => '8.5', 'suivi_stock' => false])->id, 'quantite' => 1]]);
    }

    private function jourDeParis(): string
    {
        return Calendrier::deParis()->aujourdhui();
    }

    /**
     * Le PDF joint à l'e-mail parti que désigne $filtre.
     *
     * @param  Closure(DocumentMail): bool  $filtre
     */
    private function pdfJoint(Closure $filtre): string
    {
        $mail = Mail::sent(DocumentMail::class, $filtre)->sole();
        $pieceJointe = $mail->attachments()[0];

        $this->assertInstanceOf(Attachment::class, $pieceJointe);

        return $pieceJointe->attachWith(fn (): string => '', fn (Closure $donnees): string => $donnees());
    }

    /**
     * Le texte que le PDF imprime contient $attendu et pas $absent.
     */
    private function assertTexteDuPdf(string $attendu, string $absent, string $pdf): void
    {
        $texte = (string) preg_replace('/\s+/u', ' ', implode(' ', array_merge(...array_map(fn (array $page): array => array_column($page['textes'], 'texte'), $this->pagesDuPdf($pdf)))));

        $this->assertStringContainsString($attendu, $texte);
        $this->assertStringNotContainsString($absent, $texte);
    }
}
