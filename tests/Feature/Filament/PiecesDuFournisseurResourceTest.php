<?php

namespace Tests\Feature\Filament;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EnregistrerAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\PieceDuFournisseur;
use App\Domain\Achats\Services\PieceDeposee;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\AvoirFournisseurResource\Pages\ListAvoirsFournisseur;
use App\Filament\App\Resources\AvoirFournisseurResource\Pages\ViewAvoirFournisseur;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\App\Resources\FactureAchatResource\Pages\CreateFactureAchat;
use App\Filament\App\Resources\FactureAchatResource\Pages\EditFactureAchat;
use App\Filament\App\Resources\FactureAchatResource\Pages\ListFacturesAchat;
use App\Filament\App\Resources\FactureAchatResource\Pages\ViewFactureAchat;
use App\Filament\App\Widgets\PiecesDuFournisseurManquantesApercu;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

use function Livewire\invade;

/**
 * Les pièces du fournisseur à l'écran (lot EX) : le champ de dépôt et la
 * date obligatoire du formulaire d'une facture d'achat, le dépôt dans la
 * fenêtre d'un avoir fournisseur, la colonne et le filtre « pièce du
 * fournisseur manquante » des listes, les gestes de la fiche, « À faire »
 * au tableau de bord, et l'adresse de téléchargement d'une pièce, servie
 * seulement dans son magasin à qui voit son document.
 */
class PiecesDuFournisseurResourceTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\nfacture du fournisseur\n%%EOF";

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00note d'avoir";

    private Magasin $magasin;

    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PiecesDuFournisseur::DISQUE);
        $this->magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port', 'code' => 'PORT']);
        $this->responsable = $this->membre($this->magasin, RoleMagasin::Responsable, identite: ['name' => 'Rose Martin']);
        $this->actingAs($this->responsable);
        Filament::setTenant($this->magasin);
    }

    public function test_le_formulaire_exige_la_date_et_joint_les_pieces_deposees(): void
    {
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => false]);
        $formulaire = [
            'fournisseur_id' => Fournisseur::factory()->create(['nom' => 'Grossiste du Nord'])->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ht' => 3, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ];

        Livewire::test(CreateFactureAchat::class)->fillForm($formulaire)->call('create')->assertHasFormErrors(['date_facture' => 'required']);
        $this->assertSame(0, FactureAchat::query()->count());

        Livewire::test(CreateFactureAchat::class)
            ->fillForm([...$formulaire, 'date_facture' => '2026-02-27', 'pieces' => [UploadedFile::fake()->createWithContent('facture-812.pdf', self::PDF)]])
            ->call('create')
            ->assertHasNoFormErrors();

        $factureAchat = FactureAchat::query()->sole();
        $this->assertSame(['2026-02-27', false], [$factureAchat->date_facture, $factureAchat->piece_manquante]);
        $this->assertSame(['facture-812.pdf', hash('sha256', self::PDF)], [$factureAchat->pieces()->sole()->nom_origine, $factureAchat->pieces()->sole()->empreinte]);

        Livewire::test(EditFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->fillForm(['pieces' => [UploadedFile::fake()->createWithContent('page-2.jpg', self::JPEG)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['facture-812.pdf', 'page-2.jpg'], $factureAchat->pieces()->pluck('nom_origine')->all());
    }

    public function test_un_fichier_refuse_garde_la_saisie_et_ne_cree_rien(): void
    {
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFactureAchat::class)
            ->fillForm([
                'fournisseur_id' => Fournisseur::factory()->create()->id,
                'date_facture' => '2026-02-27',
                'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ht' => 3, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
                'pieces' => [UploadedFile::fake()->createWithContent('facture.pdf', 'MZ pas un PDF')],
            ])
            ->call('create')
            ->assertNotified(sprintf(PiecesDuFournisseur::REFUS_TYPE, 'facture.pdf'));

        $this->assertSame([0, 0], [FactureAchat::query()->count(), PieceDuFournisseur::query()->count()]);
    }

    public function test_la_liste_marque_et_filtre_les_pieces_manquantes_et_a_faire_les_rappelle(): void
    {
        $sansPiece = $this->saisir([]);
        $avecPiece = $this->saisir([new PieceDeposee('facture.pdf', self::PDF)]);

        Livewire::test(ListFacturesAchat::class)
            ->assertCanSeeTableRecords([$sansPiece, $avecPiece])
            ->assertTableColumnStateSet('pieces_count', 'Manquante', $sansPiece->getKey())
            ->assertTableColumnStateSet('pieces_count', '1 pièce', $avecPiece->getKey())
            ->filterTable('piece_manquante')
            ->assertCanSeeTableRecords([$sansPiece])
            ->assertCanNotSeeTableRecords([$avecPiece]);

        Livewire::test(PiecesDuFournisseurManquantesApercu::class)
            ->assertSeeText('À faire')
            ->assertSeeText('1 facture d\'achat sans sa pièce')
            ->assertSee(FactureAchatResource::getUrl('index', ['tableFilters' => ['piece_manquante' => ['isActive' => true]]]), false);

        $avoir = $this->retourner(app(ValiderFactureAchatAction::class)->handle($this->responsable, $avecPiece));
        Livewire::test(ListAvoirsFournisseur::class)->filterTable('piece_manquante')->assertCanSeeTableRecords([$avoir]);
        Livewire::test(PiecesDuFournisseurManquantesApercu::class)->assertSeeText('1 avoir fournisseur sans sa pièce');

        $vendeur = $this->membre($this->magasin, RoleMagasin::Vendeur);
        $this->actingAs($vendeur);
        $this->assertFalse(PiecesDuFournisseurManquantesApercu::canView());
    }

    public function test_la_fiche_ajoute_une_piece_et_sur_un_brouillon_la_remplace_ou_la_retire(): void
    {
        $brouillon = $this->saisir([new PieceDeposee('mauvaise.pdf', self::PDF)]);
        $piece = $brouillon->pieces()->sole();

        Livewire::test(ViewFactureAchat::class, ['record' => $brouillon->getRouteKey()])
            ->assertSeeText('mauvaise.pdf')
            ->assertSee(FactureAchatResource::adresseDeLaPiece($piece), false)
            ->callAction('remplacer_une_piece', ['piece' => $piece->uuid, 'pieces' => [UploadedFile::fake()->createWithContent('bonne.jpg', self::JPEG)]])
            ->assertNotified('Pièce remplacée.');
        $this->assertSame(['bonne.jpg'], $brouillon->pieces()->pluck('nom_origine')->all());

        Livewire::test(ViewFactureAchat::class, ['record' => $brouillon->getRouteKey()])
            ->callAction('retirer_une_piece', ['piece' => $brouillon->pieces()->sole()->uuid])
            ->assertNotified('Pièce retirée.');
        $this->assertTrue($brouillon->refresh()->piece_manquante);

        $validee = app(ValiderFactureAchatAction::class)->handle($this->responsable, $brouillon);
        Livewire::test(ViewFactureAchat::class, ['record' => $validee->getRouteKey()])
            ->assertSeeText(FactureAchatResource::PIECE_MANQUANTE)
            ->assertActionHidden('retirer_une_piece')
            ->assertActionHidden('remplacer_une_piece')
            ->callAction('ajouter_des_pieces', ['pieces' => [UploadedFile::fake()->createWithContent('facture.pdf', self::PDF)]])
            ->assertNotified('Pièce jointe.');
        $this->assertFalse($validee->refresh()->piece_manquante);
    }

    public function test_la_fenetre_de_l_avoir_joint_sa_note_et_la_fiche_de_l_avoir_en_ajoute(): void
    {
        $factureAchat = app(ValiderFactureAchatAction::class)->handle($this->responsable, $this->saisir([new PieceDeposee('facture.pdf', self::PDF)]));

        $fiche = Livewire::test(ViewFactureAchat::class, ['record' => $factureAchat->getRouteKey()])->mountAction('creer_avoir_fournisseur');
        $fiche->set('mountedActionsData.0.lignes.'.array_key_first($fiche->get('mountedActionsData.0.lignes')).'.quantite', 1)
            ->setActionData(['numero_fournisseur' => 'AV-9', 'motif' => 'Carnet abîmé', 'pieces' => [UploadedFile::fake()->createWithContent('note-av9.jpg', self::JPEG)]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $avoir = AvoirFournisseur::query()->sole();
        $this->assertSame([false, ['note-av9.jpg']], [$avoir->piece_manquante, $avoir->pieces()->pluck('nom_origine')->all()]);

        Livewire::test(ViewAvoirFournisseur::class, ['record' => $avoir->getRouteKey()])
            ->assertSeeText('note-av9.jpg')
            ->callAction('ajouter_des_pieces', ['pieces' => [UploadedFile::fake()->createWithContent('note-av9-p2.pdf', self::PDF.' p2')]])
            ->assertNotified('Pièce jointe.');
        $this->assertSame(2, $avoir->pieces()->count());
    }

    public function test_la_fenetre_de_l_avoir_renvoyee_avec_sa_note_apres_une_reponse_perdue_rend_l_avoir_deja_emis(): void
    {
        $factureAchat = app(ValiderFactureAchatAction::class)->handle($this->responsable, $this->saisir([new PieceDeposee('facture.pdf', self::PDF)]));
        $fiche = Livewire::test(ViewFactureAchat::class, ['record' => $factureAchat->getRouteKey()])->mountAction('creer_avoir_fournisseur');
        $fiche->set('mountedActionsData.0.lignes.'.array_key_first($fiche->get('mountedActionsData.0.lignes')).'.quantite', 1)
            ->setActionData(['numero_fournisseur' => 'AV-9', 'motif' => 'Carnet abîmé', 'pieces' => [UploadedFile::fake()->createWithContent('note-av9.jpg', self::JPEG)]]);
        $demandeEnvoyee = invade($fiche)->lastState;

        $fiche->callMountedAction()->assertHasNoActionErrors();
        $avoir = AvoirFournisseur::query()->sole();
        $fiche->assertNotified("Avoir fournisseur {$avoir->numero} émis.");

        invade($fiche)->lastState = $demandeEnvoyee;
        $fiche->callMountedAction()->assertHasNoActionErrors()->assertNotified("Avoir fournisseur {$avoir->numero} émis.");

        $this->assertSame(1, AvoirFournisseur::query()->count());
        $this->assertSame(['note-av9.jpg'], $avoir->pieces()->pluck('nom_origine')->all());
    }

    public function test_les_gestes_de_la_fiche_ne_s_offrent_qu_a_qui_saisit_les_achats(): void
    {
        $brouillon = $this->saisir([new PieceDeposee('facture.pdf', self::PDF)]);
        $this->actingAs($this->membre($this->magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_ACHAT_VIEW]));

        Livewire::test(ViewFactureAchat::class, ['record' => $brouillon->getRouteKey()])
            ->assertSeeText('facture.pdf')
            ->assertActionHidden('ajouter_des_pieces')
            ->assertActionHidden('retirer_une_piece')
            ->assertActionHidden('remplacer_une_piece');
    }

    public function test_une_piece_se_telecharge_telle_que_deposee_pour_qui_voit_son_document(): void
    {
        $piece = $this->saisir([new PieceDeposee('Facture Grossiste.pdf', self::PDF)])->pieces()->sole();

        $reponse = $this->get(FactureAchatResource::adresseDeLaPiece($piece));

        $reponse->assertOk()->assertDownload('Facture Grossiste.pdf');
        $this->assertSame(self::PDF, $reponse->streamedContent());
        $this->assertSame(['application/pdf', 'nosniff'], [$reponse->headers->get('Content-Type'), $reponse->headers->get('X-Content-Type-Options')]);
        $this->assertStringContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));
    }

    public function test_l_adresse_d_une_piece_est_refusee_hors_de_son_magasin_ou_sans_le_droit_de_voir_les_achats(): void
    {
        $piece = $this->saisir([new PieceDeposee('facture.pdf', self::PDF)])->pieces()->sole();
        $adresse = FactureAchatResource::adresseDeLaPiece($piece);
        $autre = $this->ouvrirUnAutreMagasin($this->magasin, ['nom' => 'Boutique de la Gare', 'code' => 'GARE']);
        $proprietaire = $this->proprietaireDe($this->magasin);

        $this->telechargerEnTantQue($proprietaire, route('filament.app.pieces-du-fournisseur.telecharger', ['tenant' => $autre, 'piece' => $piece]))->assertNotFound();
        $this->telechargerEnTantQue($proprietaire, route('filament.app.pieces-du-fournisseur.telecharger', ['tenant' => $this->magasin, 'piece' => 'a3c8f0c4-3b5f-4b11-9a68-0f1f3b6d2c10']))->assertNotFound();
        $this->telechargerEnTantQue($proprietaire, route('filament.app.pieces-du-fournisseur.telecharger', ['tenant' => $this->magasin, 'piece' => '1']))->assertNotFound();
        $this->telechargerEnTantQue($this->membre($autre, RoleMagasin::Responsable), $adresse)->assertNotFound();
        $this->telechargerEnTantQue($this->membre($this->magasin, RoleMagasin::Vendeur), $adresse)->assertForbidden();

        $etranger = $this->ouvrirUneSociete(['nom' => 'Autre société']);
        $this->telechargerEnTantQue($this->proprietaireDe($etranger), $adresse)->assertNotFound();

        app(TenantContext::class)->set($this->magasin);
        $this->telechargerEnTantQue($this->collaborateurDuCabinetLie($this->magasin), $adresse)->assertOk();
        $this->telechargerEnTantQue($proprietaire, $adresse)->assertOk();
    }

    /**
     * Une requête de ce compte, dans une session neuve, comme depuis son
     * propre navigateur.
     */
    private function telechargerEnTantQue(User $compte, string $adresse): TestResponse
    {
        $this->flushSession();

        return $this->actingAs($compte)->get($adresse);
    }

    /**
     * @param  list<PieceDeposee>  $pieces
     */
    private function saisir(array $pieces): FactureAchat
    {
        $produit = Produit::query()->where('reference', 'CARNET')->first() ?? Produit::factory()->create(['reference' => 'CARNET', 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(CreerFactureAchatAction::class)->handle($this->responsable, Fournisseur::query()->firstOrCreate(['nom' => 'Grossiste du Nord']), [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => '3.00'],
        ], ['date_facture' => '2026-02-27', 'numero_fournisseur' => 'F-812'], null, $pieces);
    }

    private function retourner(FactureAchat $factureAchat): AvoirFournisseur
    {
        return app(EnregistrerAvoirFournisseurAction::class)->handle($this->responsable, $factureAchat, [['ligne_achat_id' => $factureAchat->lignes()->sole()->id, 'quantite' => 1]], 'Carnet abîmé', 'AV-12');
    }
}
