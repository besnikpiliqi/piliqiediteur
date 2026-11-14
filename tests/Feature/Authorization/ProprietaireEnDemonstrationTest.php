<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EncaisserEnPlusieursModesAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\FermerLesCaissesPersonnellesAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Support\GardesDeLaVente;
use App\Domain\Stock\Exceptions\ConfirmationVenteSansStockRequiseException;
use App\Domain\Stock\Services\ConfirmationVenteSansStock;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages\ListClotureCaissePersonnelles;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Filament\App\Resources\PaiementResource\Pages\ListPaiements;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Dans le magasin de démonstration encore ouvert de sa société, le
 * propriétaire a toutes les autorisations qui existent dans les magasins de
 * son type, celles de la vente comprises (décision de l'éditeur du
 * 12/11/2026, D71) : il y ouvre la caisse, vend, encaisse en un ou
 * plusieurs modes, vend sans stock, accorde des remises, fait des avoirs,
 * utilise un bon d'avoir, ferme sa caisse et clôture la journée, comme un
 * responsable ; la base accepte ces ventes et ces encaissements à son nom
 * dans ce seul magasin. Partout ailleurs, il ne vend toujours jamais (voir
 * ProprietaireSansVenteTest) : ni dans un magasin réel, ni dans une
 * démonstration fermée, à la validation de sa société ou après trente
 * jours sans opération.
 */
class ProprietaireEnDemonstrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_a_toutes_les_autorisations_dans_la_demonstration_et_aucune_de_vente_dans_ses_magasins_reels(): void
    {
        foreach ([TypeDActivite::Commerce, TypeDActivite::Services] as $type) {
            $societe = $this->inscrireUneSociete(type: $type);
            $reel = $this->creerLeMagasinDuSiege($societe);
            $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);
            $proprietaire = $societe->proprietaire()->firstOrFail();
            $venteDuType = array_values(array_intersect(PermissionRegistry::deVente(), PermissionRegistry::pourLeType($type)));

            $this->assertEqualsCanonicalizing(PermissionRegistry::pourLeType($type), $proprietaire->autorisationsDans($demonstration), $type->value);
            $this->assertEqualsCanonicalizing(PermissionRegistry::duProprietaire($type), $proprietaire->autorisationsDans($reel), $type->value);
            $this->assertSame([true, false], [$proprietaire->tientUneCaisseDans($demonstration), $proprietaire->tientUneCaisseDans($reel)], $type->value);
            app(TenantContext::class)->runAs($demonstration, function () use ($proprietaire, $venteDuType, $type): void {
                foreach ([...$venteDuType, PermissionRegistry::VENTE_REMISES_ET_PRIX] as $autorisation) {
                    $this->assertTrue($proprietaire->can($autorisation), "{$type->value} : {$autorisation}");
                }
            });
        }
    }

    /**
     * Le propriétaire ouvre la caisse et voit les boutons de la vente :
     * « Nouvelle facture », « Encaisser », « Nouveau paiement », « Fermer
     * ma caisse ».
     */
    public function test_le_proprietaire_ouvre_la_caisse_de_la_demonstration_et_voit_les_boutons_de_la_vente(): void
    {
        $societe = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $this->dansLeMagasin($demonstration, $proprietaire);
        $ticket = app(EmettreFactureAction::class)->handle($proprietaire, app(CreerFactureAction::class)->handle($proprietaire, $this->comptoir(), $this->carnets(1)));

        $this->get(Caisse::getUrl(tenant: $demonstration))->assertOk();
        Livewire::test(ListFactures::class)->assertActionVisible('create');
        Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])->assertActionVisible('encaisser');
        Livewire::test(ListPaiements::class)->assertActionVisible('create');
        Livewire::test(ListClotureCaissePersonnelles::class)->assertActionVisible('fermer_ma_caisse');
    }

    /**
     * Toute la journée d'une caisse, par le propriétaire seul : un ticket à
     * la caisse, une facture remisée émise puis encaissée en deux modes, une
     * vente sans stock confirmée, un avoir au client comptoir, son bon
     * d'avoir utilisé sur une vente suivante, sa caisse fermée, la journée
     * clôturée. Chaque vente et chaque encaissement est à son nom, signé à
     * son nom au registre de la démonstration.
     */
    public function test_le_proprietaire_vend_encaisse_fait_un_avoir_utilise_un_bon_ferme_sa_caisse_et_cloture_dans_la_demonstration(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $this->dansLeMagasin($demonstration, $proprietaire);
        $aujourdhui = Calendrier::deParis()->aujourdhui();

        $ticket = self::vendreAuComptant($proprietaire, $this->comptoir(), $this->carnets(2), ModePaiement::CarteBancaire);
        $facture = app(EmettreFactureAction::class)->handle($proprietaire, app(CreerFactureAction::class)->handle($proprietaire, Client::factory()->create(), [[...$this->carnets(1)[0], 'remise_pourcentage' => 10]]));
        $facture = app(EncaisserEnPlusieursModesAction::class)->handle($proprietaire, $facture, [new PartDeReglement(ModePaiement::Especes, 200), new PartDeReglement(ModePaiement::Cheque, $facture->montantRestantEnCentimes() - 200)], [], $aujourdhui);
        $tasses = [['produit_id' => $this->produit('DEMO-TASSE')->id, 'quantite' => 12]];
        $avertissement = $this->avertissementDe(fn () => self::vendreAuComptant($proprietaire, $this->comptoir(), $tasses));
        $sansStock = self::vendreAuComptant($proprietaire, $this->comptoir(), $tasses, confirmation: ConfirmationVenteSansStock::de($avertissement->manques));
        $bon = app(EnregistrerAvoirAction::class)->handle($proprietaire, $this->comptoir(), [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Carnet rendu');
        $avecLeBon = self::vendreAuComptant($proprietaire, $this->comptoir(), [['produit_id' => $this->produit('DEMO-STYLO')->id, 'quantite' => 5]], bonsDAvoir: [$bon->numero]);
        $releve = app(FermerSaCaisseAction::class)->handle($proprietaire, $proprietaire);
        $cloture = app(CloturerCaisseAction::class)->handle($proprietaire, $demonstration, $aujourdhui);

        $this->assertMatchesRegularExpression('/^TK-DEMO-/', (string) $ticket->numero);
        $this->assertSame(['4.41', StatutFacture::Payee], [$facture->total_ttc, $facture->statut]);
        $this->assertSame(-2, $this->produit('DEMO-TASSE')->stock_quantite);
        $this->assertSame([0, StatutFacture::Payee], [$bon->fresh()->creditEnCentimes(), $avecLeBon->statut]);
        $this->assertSame([(int) $proprietaire->id], Facture::query()->pluck('cree_par')->map(fn (mixed $auteur): int => (int) $auteur)->unique()->values()->all());
        $this->assertSame([(int) $proprietaire->id], Paiement::query()->pluck('cree_par')->map(fn (mixed $auteur): int => (int) $auteur)->unique()->values()->all());
        $this->assertSame(
            [(int) $proprietaire->id],
            EnregistrementFiscal::query()->whereIn('chaine', [ChaineFiscale::Vente->value, ChaineFiscale::Encaissement->value])->pluck('operateur_id')->map(fn (mixed $operateur): int => (int) $operateur)->unique()->values()->all(),
        );
        $this->assertSame([(int) $proprietaire->id, ModeFermetureCaisse::Vendeur, 4], [(int) $releve->vendeur_id, $releve->mode_fermeture, $releve->nombre_ventes]);
        $this->assertSame([1, 4], [ClotureCaisse::query()->count(), $cloture->nombre_ventes]);
        $this->assertSame(4, Facture::query()->whereKey([$ticket->id, $facture->id, $sansStock->id, $avecLeBon->id])->count());
    }

    /**
     * Dans la démonstration, où il vend, le brouillon du propriétaire reste
     * le sien quand un compte d'entraînement l'émet, comme celui de tout
     * autre compte, l'émission étant signée au nom de qui l'émet ; dans un
     * magasin réel, le brouillon qu'un propriétaire
     * a fait comme administrateur devient la vente de qui l'émet (voir
     * TransfererProprieteTest).
     */
    public function test_le_brouillon_du_proprietaire_dans_la_demonstration_reste_le_sien_quand_un_autre_compte_l_emet(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);
        $proprietaire = $societe->proprietaire()->firstOrFail();
        app(TenantContext::class)->set($demonstration);
        $brouillon = app(CreerFactureAction::class)->handle($proprietaire, $this->comptoir(), $this->carnets(1));

        $responsable = $this->responsableDeTest($demonstration);

        $ticket = app(EmettreFactureAction::class)->handle($responsable, $brouillon);

        $this->assertSame([(int) $proprietaire->id, (int) $responsable->id], [(int) $ticket->cree_par, (int) $ticket->enregistrementDeVente()->firstOrFail()->operateur_id]);
    }

    /**
     * Fermée à la validation de la société, la démonstration ne vend plus,
     * même pour le propriétaire, et sa caisse s'y est fermée d'office ;
     * dans le magasin réel de la société désormais validée, il ne vend
     * toujours pas, quand son responsable y vend.
     */
    public function test_le_proprietaire_ne_vend_ni_dans_la_demonstration_fermee_a_la_validation_ni_dans_un_magasin_reel(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);
        $proprietaire = $societe->proprietaire()->firstOrFail();
        app(TenantContext::class)->runAs($demonstration, fn (): Facture => self::vendreAuComptant($proprietaire, $this->comptoir(), $this->carnets(1)));

        $this->saisirLeCodeDuCourrier($societe);

        $proprietaire = $societe->proprietaire()->firstOrFail();
        $this->assertSame(StatutMagasin::Ferme, $demonstration->fresh()->statut);
        app(TenantContext::class)->runAs($demonstration->fresh(), function () use ($proprietaire, $demonstration): void {
            $this->assertSame(ModeFermetureCaisse::Automatique, ClotureCaissePersonnelle::query()->where('vendeur_id', $proprietaire->id)->sole()->mode_fermeture);
            $this->assertFalse($proprietaire->can(PermissionRegistry::FACTURE_CREATE));
            $this->assertThrows(fn () => self::vendreAuComptant($proprietaire, $this->comptoir(), $this->carnets(1)), VenteNonAutoriseeException::class, 'Ce magasin est fermé : seules la consultation et les téléchargements restent possibles.');
            $this->assertSame([1, false], [Facture::query()->count(), $demonstration->fresh()->peutVendre()]);
        });
        app(TenantContext::class)->runAs($reel->fresh(), function () use ($proprietaire, $reel): void {
            $this->assertThrows(fn () => self::vendreAuComptant($proprietaire, $this->comptoir(), $this->lignes()), AuthorizationException::class, LecteurDesDroits::REFUS);
            $this->assertSame(0, Facture::query()->count());

            $vente = self::vendreAuComptant($this->responsableDeTest($reel), $this->comptoir(), $this->lignes());

            $this->assertSame((int) $this->responsableDeTest($reel)->id, (int) $vente->cree_par);
        });
    }

    /**
     * Fermée après trente jours sans opération, la démonstration ne vend
     * plus, même pour le propriétaire ; rouverte, il y vend de nouveau, dès
     * le lendemain, sa fermeture ayant clôturé la journée.
     */
    public function test_le_proprietaire_ne_vend_plus_dans_la_demonstration_fermee_pour_inactivite_et_y_vend_de_nouveau_une_fois_rouverte(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);
        $proprietaire = $societe->proprietaire()->firstOrFail();
        app(TenantContext::class)->runAs($demonstration, fn (): Facture => self::vendreAuComptant($proprietaire, $this->comptoir(), $this->carnets(1)));

        $this->travelTo(self::aParis('2026-11-09 10:00:00'));
        $this->artisan('app:fermer-demonstrations-inactives')->assertSuccessful();

        app(TenantContext::class)->runAs($demonstration->fresh(), function () use ($proprietaire): void {
            $this->assertFalse($proprietaire->can(PermissionRegistry::FACTURE_CREATE));
            $this->assertThrows(fn () => self::vendreAuComptant($proprietaire, $this->comptoir(), $this->carnets(1)), VenteNonAutoriseeException::class, 'Ce magasin est fermé : seules la consultation et les téléchargements restent possibles.');
        });

        $rouverte = app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe->fresh());
        $this->travelTo(self::aParis('2026-11-10 10:00:00'));

        app(TenantContext::class)->runAs($rouverte, function () use ($proprietaire): void {
            $this->assertSame((int) $proprietaire->id, (int) self::vendreAuComptant($proprietaire, $this->comptoir(), $this->carnets(1))->cree_par);
            $this->assertSame(2, Facture::query()->count());
        });
    }

    /**
     * Fermée à la validation de la société, la démonstration ne laisse au
     * propriétaire que ses droits d'un magasin réel, sans la vente : il n'y
     * tient plus de caisse, « Fermer ma caisse » ne s'y montre plus et
     * aucun relevé à zéro ne s'y écrit, trois jours plus tard, à son nom.
     */
    public function test_dans_la_demonstration_fermee_le_proprietaire_ne_tient_plus_de_caisse_ni_n_y_ferme_un_releve_a_zero(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $this->creerLeMagasinDuSiege($societe);
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);
        $this->saisirLeCodeDuCourrier($societe);
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));

        $proprietaire = $societe->proprietaire()->firstOrFail();
        $fermee = $demonstration->fresh();

        $this->assertSame(StatutMagasin::Ferme, $fermee->statut);
        $this->assertEqualsCanonicalizing(PermissionRegistry::duProprietaire(TypeDActivite::Commerce), $proprietaire->autorisationsDans($fermee));
        $this->assertFalse($proprietaire->tientUneCaisseDans($fermee));
        $this->dansLeMagasin($fermee, $proprietaire);
        Livewire::test(ListClotureCaissePersonnelles::class)->assertOk()->assertActionHidden('fermer_ma_caisse');
        $this->assertThrows(fn () => app(FermerSaCaisseAction::class)->handle($proprietaire, $proprietaire), AuthorizationException::class);
        $this->assertSame(0, ClotureCaissePersonnelle::query()->count());
    }

    /**
     * Si la fermeture d'office de sa caisse a échoué à la fermeture de la
     * démonstration, le propriétaire y ferme encore lui-même la caisse
     * qu'il y a laissée ouverte, avec sa vente ; ensuite, il n'en ferme
     * plus aucune.
     */
    public function test_dans_la_demonstration_fermee_le_proprietaire_ferme_encore_la_caisse_qu_il_y_a_laissee_ouverte(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $this->creerLeMagasinDuSiege($societe);
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);
        $proprietaire = $societe->proprietaire()->firstOrFail();
        app(TenantContext::class)->runAs($demonstration, fn (): Facture => self::vendreAuComptant($proprietaire, $this->comptoir(), $this->carnets(1)));
        $this->mock(FermerLesCaissesPersonnellesAction::class, fn ($fermeture) => $fermeture->shouldReceive('handle')->andThrow(new RuntimeException('Registre indisponible.')));

        $this->saisirLeCodeDuCourrier($societe);
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));

        $proprietaire = $societe->proprietaire()->firstOrFail();
        $this->dansLeMagasin($demonstration->fresh(), $proprietaire);
        $this->assertSame(StatutMagasin::Ferme, $demonstration->fresh()->statut);
        $this->assertFalse($proprietaire->tientUneCaisseDans($demonstration->fresh()));
        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertActionVisible('fermer_ma_caisse')
            ->callAction('fermer_ma_caisse')
            ->assertHasNoActionErrors();

        $releve = ClotureCaissePersonnelle::query()->sole();
        $this->assertSame([(int) $proprietaire->id, ModeFermetureCaisse::Vendeur, 1], [(int) $releve->vendeur_id, $releve->mode_fermeture, $releve->nombre_ventes]);
        Livewire::test(ListClotureCaissePersonnelles::class)->assertActionHidden('fermer_ma_caisse');
    }

    /**
     * Les droits du propriétaire dans la démonstration suivent son
     * statut, au journal des événements (JET 130) : la vente comprise à
     * son ouverture, retirée à sa fermeture pour inactivité, rendue à sa
     * réouverture ; ceux d'un administrateur de tous les magasins, que le
     * statut ne change pas, ne s'y réinscrivent pas.
     */
    public function test_la_fermeture_et_la_reouverture_de_la_demonstration_s_inscrivent_aux_droits_du_proprietaire(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $administrateur = $this->administrateur($reel, null, [AutorisationsSociete::MAGASINS_GERER]);
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe->fresh());
        $proprietaire = $societe->proprietaire()->firstOrFail();

        $this->travelTo(self::aParis('2026-11-09 10:00:00'));
        $this->artisan('app:fermer-demonstrations-inactives')->assertSuccessful();
        app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe->fresh());

        $enDemonstration = PermissionRegistry::duProprietaireEnDemonstration(TypeDActivite::Commerce);
        $horsDeLaVente = PermissionRegistry::duProprietaire(TypeDActivite::Commerce);
        $modifications = collect($this->modificationsDesDroits($demonstration));
        $this->assertSame(
            [[null, $enDemonstration], [$enDemonstration, $horsDeLaVente], [$horsDeLaVente, $enDemonstration]],
            $modifications->where('membre.id', $proprietaire->id)->map(fn (array $modification): array => [$modification['avant']['permissions'] ?? null, $modification['apres']['permissions']])->values()->all(),
        );
        $this->assertCount(1, $modifications->where('membre.id', $administrateur->id));
    }

    /**
     * Écrites directement en base, une facture, un paiement et
     * l'enregistrement signé d'un ticket ou d'un encaissement au nom du
     * propriétaire passent dans le magasin de démonstration de sa société,
     * et restent refusés dans un magasin réel, comme dans la démonstration
     * d'une autre société.
     */
    public function test_la_base_accepte_une_vente_ou_un_encaissement_du_proprietaire_dans_sa_demonstration_seulement(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $reel = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaireDuReel = $this->proprietaireDe($reel);
        $this->ventesDuResponsable($reel, $this->lignes(...));
        $societe = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $this->ventesDuResponsable($demonstration, fn (): array => $this->carnets(1));

        foreach ([[$reel, $proprietaireDuReel], [$reel, $proprietaire], [$demonstration, $proprietaireDuReel]] as [$magasin, $compte]) {
            foreach ($this->ventesEcrites($magasin, $compte) as $ecrire) {
                $this->assertThrows($this->dansUnPointDeSauvegarde($ecrire), QueryException::class, GardesDeLaVente::MESSAGE);
            }
        }

        foreach ($this->ventesEcrites($demonstration, $proprietaire) as $ecrire) {
            $ecrire();
        }
        $this->assertSame([2, 1, 2], [
            Facture::withoutGlobalScope(MagasinScope::class)->where('magasin_id', $demonstration->id)->where('cree_par', $proprietaire->id)->count(),
            Paiement::withoutGlobalScope(MagasinScope::class)->where('magasin_id', $demonstration->id)->where('cree_par', $proprietaire->id)->count(),
            EnregistrementFiscal::withoutGlobalScope(MagasinScope::class)->where('magasin_id', $demonstration->id)->whereIn('chaine', [ChaineFiscale::Vente->value, ChaineFiscale::Encaissement->value])->where('operateur_id', $proprietaire->id)->count(),
        ]);
        $this->assertSame(0, Facture::withoutGlobalScope(MagasinScope::class)->whereIn('cree_par', [$proprietaire->id, $proprietaireDuReel->id])->where('magasin_id', '!=', $demonstration->id)->count());
    }

    /**
     * La base n'accepte la vente du propriétaire que dans le magasin de
     * démonstration : un magasin réel ne le devient jamais, ni l'inverse.
     */
    public function test_la_base_refuse_qu_un_magasin_devienne_ou_cesse_d_etre_la_demonstration(): void
    {
        $reel = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $societe = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);

        foreach ([[$reel, true], [$demonstration, false]] as [$magasin, $estDemonstration]) {
            $this->assertThrows(
                $this->dansUnPointDeSauvegarde(fn () => DB::table('magasins')->where('id', $magasin->id)->update(['est_demonstration' => $estDemonstration])),
                QueryException::class,
                'magasins.est_demonstration : valeur figée, changement refusé',
            );
        }

        $this->assertSame([false, true], [(bool) $reel->fresh()->est_demonstration, (bool) $demonstration->fresh()->est_demonstration]);
    }

    /**
     * Dans ce magasin, par son responsable de test : un ticket vendu au
     * comptoir, payé en espèces, et un brouillon, de ces lignes.
     *
     * @param  \Closure(): list<array{produit_id: int, quantite: int}>  $lignes
     */
    private function ventesDuResponsable(Magasin $magasin, \Closure $lignes): void
    {
        app(TenantContext::class)->runAs($magasin, function () use ($magasin, $lignes): void {
            $responsable = $this->responsableDeTest($magasin);
            self::vendreAuComptant($responsable, $this->comptoir(), $lignes());
            app(CreerFactureAction::class)->handle($responsable, $this->comptoir(), $lignes());
        });
    }

    /**
     * Les écritures directes en base d'une vente et d'un encaissement au
     * nom de ce compte, dans ce magasin, à partir du ticket et du brouillon
     * de son responsable de test (voir ventesDuResponsable()) : une copie
     * du brouillon à son nom, le brouillon qu'on lui attribue, une copie du
     * paiement du ticket à son nom, et l'enregistrement signé d'un ticket et
     * d'un paiement à son nom.
     *
     * @return array<string, \Closure(): mixed>
     */
    private function ventesEcrites(Magasin $magasin, User $compte): array
    {
        $brouillon = Facture::withoutGlobalScope(MagasinScope::class)->where('magasin_id', $magasin->id)->where('statut', StatutFacture::Brouillon->value)->orderBy('id')->firstOrFail();
        $facture = $this->copieDe('factures', $brouillon->id, ['cree_par' => $compte->id]);
        $paiement = $this->copieDe('paiements', Paiement::withoutGlobalScope(MagasinScope::class)->where('magasin_id', $magasin->id)->orderBy('id')->firstOrFail()->id, ['cree_par' => $compte->id]);
        $signee = (array) DB::table('enregistrements_fiscaux')->where('magasin_id', $magasin->id)->where('chaine', ChaineFiscale::Vente->value)->orderBy('id')->first();
        $sequence = (int) DB::table('enregistrements_fiscaux')->max('sequence') + 1000;
        $enregistrement = function (array $modifications) use ($signee, &$sequence): array {
            $ligne = [...$signee, ...$modifications, 'sequence' => $sequence++];
            unset($ligne['id']);

            return $ligne;
        };

        return [
            'facture' => fn () => DB::table('factures')->insert($facture),
            'attribution' => fn () => DB::table('factures')->where('id', $brouillon->id)->update(['cree_par' => $compte->id]),
            'paiement' => fn () => DB::table('paiements')->insert($paiement),
            'ticket signé' => fn () => DB::table('enregistrements_fiscaux')->insert($enregistrement(['chaine' => 'vente', 'type' => 'ticket', 'operateur_id' => $compte->id])),
            'paiement signé' => fn () => DB::table('enregistrements_fiscaux')->insert($enregistrement(['chaine' => 'encaissement', 'type' => 'paiement', 'operateur_id' => $compte->id])),
        ];
    }

    /**
     * Ce nombre de carnets du catalogue fictif de la démonstration, à
     * 4,90 € TTC, 20 en stock.
     *
     * @return list<array{produit_id: int, quantite: int}>
     */
    private function carnets(int $quantite): array
    {
        return [['produit_id' => $this->produit('DEMO-CARNET')->id, 'quantite' => $quantite]];
    }

    /**
     * Une ligne d'un article à 12,00 € TTC, à 20 %, sans suivi de stock,
     * dans le magasin actif.
     *
     * @return list<array{produit_id: int, quantite: int}>
     */
    private function lignes(): array
    {
        return [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]];
    }

    private function produit(string $reference): Produit
    {
        return Produit::query()->where('reference', $reference)->sole();
    }

    private function comptoir(): Client
    {
        return Client::query()->where('est_comptoir', true)->sole();
    }

    private function avertissementDe(callable $vente): ConfirmationVenteSansStockRequiseException
    {
        try {
            $vente();
        } catch (ConfirmationVenteSansStockRequiseException $avertissement) {
            return $avertissement;
        }

        $this->fail('La vente aurait dû demander la confirmation de l\'avertissement.');
    }

    /**
     * La ligne de cette table, lue en base, sans son identifiant, avec ces
     * modifications.
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
