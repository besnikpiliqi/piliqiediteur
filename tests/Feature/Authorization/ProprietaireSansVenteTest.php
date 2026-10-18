<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AjouterPreuveOperationAction;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EncaisserEnPlusieursModesAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Actions\NoterBordereauDetaxeAction;
use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Domain\Facturation\Support\GardesDeLaVente;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages\ListClotureCaissePersonnelles;
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages\CreateMembreDuMagasin;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Filament\App\Resources\PaiementResource\Pages\CreatePaiement;
use App\Filament\App\Resources\PaiementResource\Pages\ListPaiements;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le propriétaire supervise et contrôle : il a tous les droits dans sa
 * société et dans chacun de ses magasins, sauf celui de vendre (voir
 * PermissionRegistry::deVente() et duProprietaire()), pas même dans le
 * magasin de démonstration. Il ne crée ni n'émet aucun ticket ni aucune
 * facture, n'enregistre aucun encaissement, n'utilise aucun bon d'avoir,
 * n'ouvre pas la caisse et n'a pas de caisse personnelle, ni par l'écran,
 * ni par Livewire, ni par une action appelée directement, ni par une
 * demande fabriquée ; la base refuse une vente ou un encaissement à son
 * nom (voir GardesDeLaVente). Il garde la consultation, la clôture du
 * jour, la fermeture de la caisse d'un vendeur, la contre-passation,
 * l'envoi, les avoirs et les devis (réponse (a) à Q-V1), et note les
 * preuves d'exonération et les bordereaux de détaxe (réponse (b) à Q-V2).
 */
class ProprietaireSansVenteTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_n_a_aucune_autorisation_de_vente_dans_aucun_magasin_ni_dans_la_demonstration(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $enVerification = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($enVerification->proprietaire, $enVerification);

        foreach ([[$lyon, $this->proprietaireDe($lyon)], [$villeurbanne, $this->proprietaireDe($lyon)], [$demonstration, $enVerification->proprietaire]] as [$magasin, $proprietaire]) {
            $this->assertEqualsCanonicalizing(PermissionRegistry::duProprietaire(), $proprietaire->autorisationsDans($magasin), $magasin->nom);
            app(TenantContext::class)->runAs($magasin, function () use ($magasin, $proprietaire): void {
                foreach (PermissionRegistry::deVente() as $autorisation) {
                    $this->assertFalse($proprietaire->can($autorisation), "{$magasin->nom} : {$autorisation}");
                }
            });
        }
    }

    public function test_le_proprietaire_n_ouvre_pas_la_caisse_et_n_a_pas_de_caisse_personnelle(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $this->dansLeMagasin($magasin, $proprietaire);

        $this->get(Caisse::getUrl(tenant: $magasin))->assertForbidden();

        $this->assertFalse(Caisse::canAccess());
        $this->assertFalse($proprietaire->tientUneCaisseDans($magasin));
        $this->assertFalse($proprietaire->can('fermer', ClotureCaissePersonnelle::class));
        $this->assertThrows(fn () => app(FermerSaCaisseAction::class)->handle($proprietaire, $proprietaire), AuthorizationException::class, LecteurDesDroits::REFUS);
        $this->assertSame(0, ClotureCaissePersonnelle::query()->count());
    }

    /**
     * Ni le menu « Caisse », ni « Nouvelle facture », ni « Émettre », ni
     * « Encaisser », ni « Nouveau paiement », ni « Fermer ma caisse ».
     */
    public function test_le_proprietaire_ne_voit_aucun_bouton_de_vente_a_l_ecran(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $brouillon = $this->brouillonDuResponsable($magasin);
        $ticket = $this->ticketDuResponsable($magasin);
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));

        $this->get(FactureResource::getUrl('index', tenant: $magasin))->assertOk()->assertDontSee('href="'.Caisse::getUrl(tenant: $magasin).'"', false);
        Livewire::test(ListFactures::class)
            ->assertActionHidden('create')
            ->assertTableActionHidden('emettre', $brouillon)
            ->assertTableActionHidden('encaisser', $ticket);
        Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])->assertActionHidden('encaisser');
        Livewire::test(ListPaiements::class)->assertActionHidden('create');
        Livewire::test(ListClotureCaissePersonnelles::class)->assertActionHidden('fermer_ma_caisse');
        $this->get(Caisse::getUrl(tenant: $magasin))->assertForbidden();
    }

    public function test_le_proprietaire_ne_vend_pas_par_livewire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $brouillon = $this->brouillonDuResponsable($magasin);
        $ticket = $this->ticketDuResponsable($magasin);
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));
        $reglement = ['jeton' => (string) Str::uuid(), 'parts' => [['mode' => ModePaiement::Especes->value, 'montant' => '12.00']], 'recu_le' => today()->toDateString()];

        Livewire::test(CreateFacture::class)->assertForbidden();
        Livewire::test(CreatePaiement::class)->assertForbidden();
        $factures = Livewire::test(ListFactures::class);
        $this->forgerLActionDeLigne($factures, 'emettre', $brouillon, ['jeton' => (string) Str::uuid()]);
        $this->forgerLActionDeLigne($factures, 'encaisser', $ticket, $reglement);
        $this->forgerLAction(Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()]), 'encaisser', $reglement);

        $this->assertSame([StatutFacture::Brouillon, StatutFacture::Emise], [$brouillon->fresh()->statut, $ticket->fresh()->statut]);
        $this->assertSame(0, Paiement::query()->count());
    }

    public function test_le_proprietaire_ne_vend_pas_par_l_action_appelee_directement(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $brouillon = $this->brouillonDuResponsable($magasin);
        $ticket = $this->ticketDuResponsable($magasin);
        $ventesAvant = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente->value)->count();

        $refus = [
            fn () => app(CreerFactureAction::class)->handle($proprietaire, Client::factory()->create(), $this->lignes()),
            fn () => app(EmettreFactureAction::class)->handle($proprietaire, $brouillon),
            fn () => app(EnregistrerPaiementAction::class)->handle($proprietaire, $ticket->client, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => '12.00', 'recu_le' => today()->toDateString()], [['facture_id' => $ticket->id, 'montant' => '12.00']]),
            fn () => app(EncaisserEnPlusieursModesAction::class)->handle($proprietaire, $ticket, [new PartDeReglement(ModePaiement::Especes, 1200)], [], today()->toDateString()),
            fn () => self::vendreAuComptant($proprietaire, $this->comptoir(), $this->lignes()),
        ];
        foreach ($refus as $geste) {
            $this->assertThrows($geste, AuthorizationException::class, LecteurDesDroits::REFUS);
        }

        $this->assertSame([2, StatutFacture::Brouillon, StatutFacture::Emise], [Facture::query()->count(), $brouillon->fresh()->statut, $ticket->fresh()->statut]);
        $this->assertSame([0, $ventesAvant], [Paiement::query()->count(), EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente->value)->count()]);
    }

    /**
     * L'instantané de la caisse, pris par le responsable, rejoué sous la
     * session du propriétaire : la caisse le refuse et rien n'est vendu ;
     * rejoué par le responsable, il encaisse.
     */
    public function test_une_demande_fabriquee_au_nom_du_proprietaire_est_refusee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        PanierCaisse::factory()->avecLeProduit(Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]), 1)->create(['user_id' => $responsable->id]);
        $instantane = $this->instantaneLivewire($this->actingAs($responsable)->get(Caisse::getUrl(tenant: $magasin))->assertOk()->getContent(), 'caisse');
        $this->flushSession();

        $this->actingAs($this->proprietaireDe($magasin))->requeteLivewire($instantane, [], 'encaisser')->assertForbidden();

        $this->assertSame([0, 0], [Facture::query()->count(), Paiement::query()->count()]);
        $this->flushSession();
        $this->actingAs($responsable)->requeteLivewire($instantane, [], 'encaisser')->assertOk();
        $this->assertSame([[(int) $responsable->id, StatutFacture::Payee]], Facture::query()->get()->map(fn (Facture $vente): array => [(int) $vente->cree_par, $vente->statut])->all());
    }

    public function test_le_proprietaire_garde_la_consultation_la_cloture_du_jour_la_fermeture_d_une_caisse_la_contre_passation_et_l_envoi(): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsableDeTest($magasin);
        $ticket = self::vendreAuComptant($responsable, Client::factory()->create(), $this->lignes(), ModePaiement::CarteBancaire);
        $this->dansLeMagasin($magasin, $proprietaire);

        $this->get(FactureResource::getUrl('view', ['record' => $ticket], tenant: $magasin))->assertOk()->assertSee($ticket->numero);
        $envoi = app(EnvoyerDocumentAction::class)->handle($proprietaire, $ticket, 'client@example.test', null);
        $contrePassation = app(ContrePasserPaiementAction::class)->handle($proprietaire, Paiement::query()->sole(), 'Mauvais mode');
        $releve = app(FermerSaCaisseAction::class)->handle($proprietaire, $responsable);
        $cloture = app(CloturerCaisseAction::class)->handle($proprietaire, $magasin, today()->toDateString());

        $this->assertSame([1, (int) $proprietaire->id], [EnvoiDocument::query()->count(), (int) $envoi->envoye_par]);
        $this->assertSame([(int) $proprietaire->id, '-12.00'], [(int) $contrePassation->cree_par, $contrePassation->montant]);
        $this->assertSame([(int) $responsable->id, ModeFermetureCaisse::Responsable, 1], [(int) $releve->vendeur_id, $releve->mode_fermeture, $releve->nombre_ventes]);
        $this->assertSame([1, 1], [ClotureCaisse::query()->count(), $cloture->nombre_ventes]);
        $this->assertSame([], app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(today())->all());
        $this->assertSame(0, ClotureCaissePersonnelle::query()->where('vendeur_id', $proprietaire->id)->count());
    }

    /**
     * Le propriétaire contre-passe un paiement saisi par erreur ; le bon
     * paiement, encaissement comme un autre, se fait par un compte qui
     * vend : ni « Nouveau paiement » ni « Encaisser » pour lui, et
     * l'action le refuse (F 10).
     */
    public function test_le_proprietaire_contre_passe_un_paiement_mais_n_enregistre_pas_le_bon_paiement(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsableDeTest($magasin);
        $ticket = $this->ticketDuResponsable($magasin);
        $erreur = app(EnregistrerPaiementAction::class)->handle($responsable, $ticket->client, ['mode_paiement' => ModePaiement::Cheque->value, 'montant' => '12.00', 'recu_le' => today()->toDateString()], [['facture_id' => $ticket->id, 'montant' => '12.00']]);

        app(ContrePasserPaiementAction::class)->handle($proprietaire, $erreur, 'Chèque refusé');

        $this->assertSame(StatutFacture::Emise, $ticket->fresh()->statut);
        $this->dansLeMagasin($magasin, $proprietaire);
        Livewire::test(ListPaiements::class)->assertActionHidden('create');
        Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])->assertActionHidden('encaisser');
        $this->assertThrows(fn () => app(EnregistrerPaiementAction::class)->handle($proprietaire, $ticket->client, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => '12.00', 'recu_le' => today()->toDateString()], [['facture_id' => $ticket->id, 'montant' => '12.00']]), AuthorizationException::class, LecteurDesDroits::REFUS);
        $this->assertSame(2, Paiement::query()->count());

        app(EnregistrerPaiementAction::class)->handle($responsable, $ticket->client, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => '12.00', 'recu_le' => today()->toDateString()], [['facture_id' => $ticket->id, 'montant' => '12.00']]);

        $this->assertSame(StatutFacture::Payee, $ticket->fresh()->statut);
        $this->assertSame([$responsable->id], app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(today())->modelKeys());
    }

    /**
     * Q6 (a) : sur un ticket du client comptoir resté dû, le bon d'avoir
     * s'utilise par « Encaisser », un encaissement : ni à l'écran ni par
     * l'action pour le propriétaire ; le responsable le peut.
     */
    public function test_le_proprietaire_n_utilise_pas_un_bon_d_avoir_sur_un_ticket_impaye(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsableDeTest($magasin);
        $vendu = self::vendreAuComptant($responsable, $this->comptoir(), $this->lignes(20));
        $bon = app(EnregistrerAvoirAction::class)->handle($responsable, $this->comptoir(), [['ligne_facture_id' => $vendu->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $ticket = app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, $this->comptoir(), $this->lignes(50)));
        $this->dansLeMagasin($magasin, $proprietaire);

        Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])->assertActionHidden('encaisser');
        $this->assertThrows(fn () => app(EncaisserEnPlusieursModesAction::class)->handle($proprietaire, $ticket, [new PartDeReglement(ModePaiement::CarteBancaire, 3000)], [$bon->numero], today()->toDateString()), AuthorizationException::class, LecteurDesDroits::REFUS);
        $this->assertSame([2000, StatutFacture::Emise], [$bon->fresh()->creditEnCentimes(), $ticket->fresh()->statut]);

        app(EncaisserEnPlusieursModesAction::class)->handle($responsable, $ticket, [new PartDeReglement(ModePaiement::CarteBancaire, 3000)], [$bon->numero], today()->toDateString());

        $this->assertSame([0, StatutFacture::Payee], [$bon->fresh()->creditEnCentimes(), $ticket->fresh()->statut]);
    }

    /**
     * Réponse (a) à Q-V1 : l'avoir et le devis ne sont pas des ventes ; le
     * propriétaire les fait, et la base garde son avoir signé à son nom.
     */
    public function test_le_proprietaire_fait_les_avoirs_et_les_devis(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $ticket = self::vendreAuComptant($this->responsableDeTest($magasin), Client::factory()->create(), $this->lignes());

        $avoir = app(EnregistrerAvoirAction::class)->handle($proprietaire, $ticket->client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Article rendu');
        $devis = app(EmettreDevisAction::class)->handle($proprietaire, app(CreerDevisAction::class)->handle($proprietaire, Client::factory()->create(), $this->lignes()));

        $this->assertSame([(int) $proprietaire->id, '12.00'], [(int) $avoir->cree_par, $avoir->total_ttc]);
        $this->assertSame((int) $proprietaire->id, (int) $avoir->enregistrementDeVente()->firstOrFail()->operateur_id);
        $this->assertSame(StatutDevis::Envoye, $devis->statut);
        $this->assertNotNull($devis->numero);
    }

    /**
     * Réponse (b) à Q-V2 : noter une preuve d'exonération ou un bordereau
     * de détaxe n'est pas une vente ; le propriétaire le fait, sur la fiche
     * comme par l'action, sur une vente émise par un autre compte.
     */
    public function test_le_proprietaire_note_une_preuve_d_exoneration_et_un_bordereau_de_detaxe(): void
    {
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsableDeTest($magasin);
        $acme = Client::factory()->create(['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789']);
        $export = app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, $acme, $this->lignes(), ['nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US']));
        $detaxe = self::vendreAuComptant($responsable, Client::factory()->create(), $this->lignes(250));
        $this->dansLeMagasin($magasin, $proprietaire);

        Livewire::test(ViewFacture::class, ['record' => $export->getRouteKey()])->assertActionVisible('ajouter_preuve');
        Livewire::test(ViewFacture::class, ['record' => $detaxe->getRouteKey()])->assertActionVisible('noter_bordereau_detaxe');
        $preuve = app(AjouterPreuveOperationAction::class)->handle($proprietaire, $export, TypePreuve::Mrn, '26FR000000123456E1', today()->toDateString(), 'Bureau de douane de Lyon');
        $bordereau = app(NoterBordereauDetaxeAction::class)->handle($proprietaire, $detaxe, '2026FR000123456');

        $this->assertSame([(int) $proprietaire->id, (int) $proprietaire->id], [(int) $preuve->note_par, (int) $bordereau->note_par]);
    }

    /**
     * Le propriétaire coche les autorisations de vente d'un vendeur, à
     * l'écran de l'équipe du magasin, et celles d'un administrateur, sans
     * les exercer lui-même : l'un et l'autre vendent ensuite.
     */
    public function test_le_proprietaire_coche_les_autorisations_de_vente_d_un_vendeur_et_d_un_administrateur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $this->dansLeMagasin($magasin, $proprietaire);

        Livewire::test(CreateMembreDuMagasin::class)
            ->fillForm(['name' => 'Paul', 'email' => 'paul@example.test', 'mot_de_passe_provisoire' => self::MOT_DE_PASSE_PROVISOIRE, 'role' => RoleMagasin::Vendeur->value])
            ->set('data.autorisations', [PermissionRegistry::FACTURE_VIEW])
            ->set('data.autorisations_vente', PermissionRegistry::deVente())
            ->call('create')
            ->assertHasNoFormErrors();
        $administrateur = app(CreerAdministrateurAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Alix', 'email' => 'alix@example.test'], self::MOT_DE_PASSE_PROVISOIRE, null, [...PermissionRegistry::modeleDeLAdministrateur(), ...PermissionRegistry::deVente()], []);

        $vendeur = $this->choisirSonMotDePasse(User::query()->where('email', 'paul@example.test')->sole());
        $administrateur = $this->choisirSonMotDePasse($administrateur);
        $this->assertEqualsCanonicalizing([PermissionRegistry::FACTURE_VIEW, ...PermissionRegistry::deVente()], $vendeur->autorisationsDans($magasin));
        $this->assertSame([], array_values(array_diff(PermissionRegistry::deVente(), $administrateur->autorisationsDans($magasin))));
        foreach ([$vendeur, $administrateur] as $compte) {
            $this->assertSame((int) $compte->id, (int) self::vendreAuComptant($compte, Client::factory()->create(), $this->lignes())->cree_par);
            app(FermerSaCaisseAction::class)->handle($compte, $compte);
        }
    }

    /**
     * Écrit directement en base, sans l'application : l'enregistrement
     * signé d'un ticket, d'une facture ou d'un paiement au nom du
     * propriétaire est refusé ; celui d'un avoir, d'une contre-passation,
     * d'un événement ou du système passe, comme la même vente au nom du
     * responsable.
     */
    public function test_la_base_refuse_une_vente_ou_un_encaissement_signe_au_nom_du_proprietaire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsableDeTest($magasin);
        $ticket = self::vendreAuComptant($responsable, Client::factory()->create(), $this->lignes());
        $vente = (array) DB::table('enregistrements_fiscaux')->where('id', $ticket->enregistrementDeVente()->firstOrFail()->id)->first();
        $sequence = 1000;
        $copie = function (array $modifications) use ($vente, &$sequence): array {
            $ligne = [...$vente, ...$modifications, 'sequence' => $sequence++];
            unset($ligne['id']);

            return $ligne;
        };

        foreach ([['chaine' => 'vente', 'type' => 'ticket'], ['chaine' => 'vente', 'type' => 'facture'], ['chaine' => 'encaissement', 'type' => 'paiement']] as $refusee) {
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('enregistrements_fiscaux')->insert($copie([...$refusee, 'operateur_id' => $proprietaire->id]))), QueryException::class, GardesDeLaVente::MESSAGE);
        }
        $this->assertSame(0, DB::table('enregistrements_fiscaux')->where('operateur_id', $proprietaire->id)->count());

        foreach ([
            ['chaine' => 'vente', 'type' => 'avoir', 'operateur_id' => $proprietaire->id],
            ['chaine' => 'encaissement', 'type' => 'contre_passation', 'operateur_id' => $proprietaire->id],
            ['chaine' => 'evenement', 'type' => (string) CodeEvenement::OperationDeControle->value, 'operateur_id' => $proprietaire->id],
            ['chaine' => 'vente', 'type' => 'ticket', 'operateur_id' => null],
            ['chaine' => 'vente', 'type' => 'ticket', 'operateur_id' => $responsable->id],
        ] as $permise) {
            DB::table('enregistrements_fiscaux')->insert($copie($permise));
        }
        $this->assertSame(3, DB::table('enregistrements_fiscaux')->where('operateur_id', $proprietaire->id)->count());
    }

    /**
     * Écrite directement en base : une facture ou un paiement au nom du
     * propriétaire, ou une facture qu'on lui attribue ensuite, est refusé ;
     * sa contre-passation d'un paiement et son avoir passent.
     */
    public function test_la_base_refuse_une_facture_ou_un_paiement_au_nom_du_proprietaire_mais_pas_sa_contre_passation_ni_son_avoir(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsableDeTest($magasin);
        $ticket = self::vendreAuComptant($responsable, Client::factory()->create(), $this->lignes());
        $brouillon = $this->brouillonDuResponsable($magasin);
        $avoir = app(EnregistrerAvoirAction::class)->handle($responsable, $ticket->client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $facture = $this->copieDe('factures', $brouillon->id);
        $paiement = $this->copieDe('paiements', Paiement::query()->sole()->id);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('factures')->insert([...$facture, 'cree_par' => $proprietaire->id])), QueryException::class, GardesDeLaVente::MESSAGE);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('factures')->where('id', $brouillon->id)->update(['cree_par' => $proprietaire->id])), QueryException::class, GardesDeLaVente::MESSAGE);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('paiements')->insert([...$paiement, 'cree_par' => $proprietaire->id])), QueryException::class, GardesDeLaVente::MESSAGE);
        $this->assertSame([2, 1, (int) $responsable->id], [Facture::query()->count(), Paiement::query()->count(), (int) $brouillon->fresh()->cree_par]);

        DB::table('paiements')->insert([...$paiement, 'cree_par' => $proprietaire->id, 'montant' => '-12.00', 'contre_passe_paiement_id' => Paiement::query()->sole()->id]);
        DB::table('avoirs')->insert([...$this->copieDe('avoirs', $avoir->id, ['numero' => null]), 'cree_par' => $proprietaire->id]);
        DB::table('factures')->insert([...$facture, 'cree_par' => $responsable->id]);

        $this->assertSame([1, 1, 0], [
            Paiement::query()->where('cree_par', $proprietaire->id)->whereNotNull('contre_passe_paiement_id')->count(),
            Avoir::query()->where('cree_par', $proprietaire->id)->count(),
            Facture::query()->where('cree_par', $proprietaire->id)->count(),
        ]);
    }

    /**
     * Chaque autorisation du registre est soit de vente, soit hors vente,
     * jamais les deux ; avec la réponse (a) à Q-V1, l'avoir et le devis
     * sont hors vente, et avec la réponse (b) à Q-V2, noter les preuves
     * aussi.
     */
    public function test_chaque_autorisation_du_registre_est_classee_vente_ou_hors_vente(): void
    {
        $deVente = PermissionRegistry::deVente();
        $horsVente = PermissionRegistry::horsVente();

        $this->assertSame([], array_values(array_intersect($deVente, $horsVente)));
        $this->assertEqualsCanonicalizing(PermissionRegistry::toutes(), [...$deVente, ...$horsVente]);
        $this->assertCount(count(PermissionRegistry::toutes()), [...$deVente, ...$horsVente]);
        $this->assertEqualsCanonicalizing([PermissionRegistry::FACTURE_CREATE, PermissionRegistry::FACTURE_EMETTRE, PermissionRegistry::PAIEMENT_CREER, PermissionRegistry::STOCK_VENDRE_SANS_STOCK], $deVente);
        foreach ([PermissionRegistry::AVOIR_CREER, PermissionRegistry::DEVIS_CREATE, PermissionRegistry::DEVIS_UPDATE, PermissionRegistry::DEVIS_ENVOYER, PermissionRegistry::FACTURE_JUSTIFIER, PermissionRegistry::PAIEMENT_CONTRE_PASSER, PermissionRegistry::VENTE_REMISES_ET_PRIX] as $autorisation) {
            $this->assertContains($autorisation, $horsVente);
        }
        $this->assertEqualsCanonicalizing($horsVente, PermissionRegistry::duProprietaire());
    }

    /**
     * Un brouillon de 12,00 € TTC créé par le responsable de test du
     * magasin.
     */
    private function brouillonDuResponsable(Magasin $magasin): Facture
    {
        return app(CreerFactureAction::class)->handle($this->responsableDeTest($magasin), Client::factory()->create(), $this->lignes());
    }

    /**
     * Un ticket de 12,00 € TTC émis par le responsable de test du magasin,
     * pas encore réglé.
     */
    private function ticketDuResponsable(Magasin $magasin): Facture
    {
        $responsable = $this->responsableDeTest($magasin);

        return app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, Client::factory()->create(), $this->lignes()));
    }

    /**
     * Une ligne d'un article à ce prix TTC, à 20 %, sans suivi de stock.
     *
     * @return list<array{produit_id: int, quantite: int}>
     */
    private function lignes(float $prixTtc = 12): array
    {
        return [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]];
    }

    private function comptoir(): Client
    {
        return Client::query()->where('est_comptoir', true)->sole();
    }

    /**
     * La ligne de cette table, lue en base, sans son identifiant, avec ces
     * modifications : de quoi en écrire une autre directement.
     *
     * @param  array<string, mixed>  $modifications
     * @return array<string, mixed>
     */
    private function copieDe(string $table, int $id, array $modifications = []): array
    {
        $ligne = [...(array) DB::table($table)->where('id', $id)->first(), ...$modifications];
        unset($ligne['id']);

        return $ligne;
    }
}
