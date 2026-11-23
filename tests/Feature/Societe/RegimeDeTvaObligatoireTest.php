<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Charges\Support\TextesDuCreditDeTva;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\UrgenceAFaire;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ChosesAFaireDeLaSociete;
use App\Domain\Tenancy\Services\ChosesAFaireDuMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Domain\Tenancy\Support\GardesDuRegimeDeTva;
use App\Filament\Societe\Pages\ParametresFiscauxSociete;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le régime de TVA obligatoire avant toute opération réelle (lot TVA27,
 * point 3 bis ; décision de Claude, D76) : tant que la société n'a pas
 * choisi son régime, PILIQI ne sait ni si elle est en franchise en base
 * ni à quel rythme elle déclare, et refuse tout ticket, facture, avoir,
 * encaissement et devis numéroté, avec un message selon le rôle ; la base
 * le refuse aussi ; l'« À faire » de l'espace de la société et celui de
 * chaque magasin le disent, et la liste d'étapes du propriétaire le
 * demande avant le premier magasin. Le temps est figé au 5 novembre 2026,
 * à Paris.
 */
class RegimeDeTvaObligatoireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-11-05 10:00:00'));
    }

    /**
     * Sans régime, chaque opération commerciale réelle est refusée, sans
     * rien écrire au registre : un ticket, une facture, un devis
     * numéroté ; et, pour la facture émise avant que le régime ne soit
     * effacé en base pour l'essai, son avoir et son encaissement. Le
     * responsable qui vend lit qu'il doit le demander au propriétaire.
     */
    public function test_sans_regime_chaque_operation_commerciale_reelle_est_refusee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $client = Client::factory()->create();
        $cable = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = $this->emettre($client, $cable);
        $this->effacerLeRegime($magasin);
        $enregistrements = EnregistrementFiscal::query()->whereIn('chaine', [ChaineFiscale::Vente->value, ChaineFiscale::Encaissement->value])->count();

        $this->assertThrows(fn () => self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $cable->id, 'quantite' => 1]]), VenteNonAutoriseeException::class, Magasin::REFUS_REGIME_A_DEMANDER);
        $this->assertThrows(fn () => $this->emettre($client, $cable), VenteNonAutoriseeException::class, Magasin::REFUS_REGIME_A_DEMANDER);
        $this->assertThrows(fn () => app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]], 'Retour'), VenteNonAutoriseeException::class, Magasin::REFUS_REGIME_A_DEMANDER);
        $this->assertThrows(fn () => app(EnregistrerPaiementAction::class)->handle(self::auteur(), $client, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => 12, 'recu_le' => '2026-11-05'], [['facture_id' => $facture->id, 'montant' => 12]]), VenteNonAutoriseeException::class, Magasin::REFUS_REGIME_A_DEMANDER);
        $this->assertThrows(fn () => app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $cable->id, 'quantite' => 1]], ['date_validite' => '2026-12-05'])), VenteNonAutoriseeException::class, Magasin::REFUS_REGIME_A_DEMANDER);

        $this->assertSame($enregistrements, EnregistrementFiscal::query()->whereIn('chaine', [ChaineFiscale::Vente->value, ChaineFiscale::Encaissement->value])->count());
        $this->assertSame([1, 0, 0], [Facture::query()->whereNotNull('numero')->count(), Avoir::query()->count(), Paiement::query()->count()]);
    }

    /**
     * Le refus dit au propriétaire, et à l'administrateur qui gère les
     * paramètres de la société (tous les magasins dans sa portée), de
     * choisir le régime dans les paramètres fiscaux ; à tout autre compte,
     * et sans compte, de le demander au propriétaire. Une fois choisi, la
     * vente reprend.
     */
    public function test_le_refus_dit_de_choisir_le_regime_a_qui_le_regle_et_de_le_demander_aux_autres(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon', 'regime_tva' => null]);
        $proprietaire = $this->proprietaireDe($magasin);
        $fiscal = $this->administrateur($magasin, autorisationsSociete: [AutorisationsSociete::PARAMETRES_GERER], nom: 'Admin fiscal');
        $sansLaFiscalite = $this->administrateur($magasin, nom: 'Admin des ventes');
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $magasin = $magasin->fresh();

        $this->assertSame('Choisissez d\'abord le régime de TVA de la société dans ses paramètres fiscaux.', Magasin::REFUS_REGIME_A_CHOISIR);
        $this->assertSame('Le régime de TVA de la société n\'est pas encore choisi : demandez-le au propriétaire.', Magasin::REFUS_REGIME_A_DEMANDER);
        $this->assertSame(Magasin::REFUS_REGIME_A_CHOISIR, $magasin->refusDeVente($proprietaire));
        $this->assertSame(Magasin::REFUS_REGIME_A_CHOISIR, $magasin->refusDeVente($fiscal));
        $this->assertSame(Magasin::REFUS_REGIME_A_DEMANDER, $magasin->refusDeVente($sansLaFiscalite));
        $this->assertSame(Magasin::REFUS_REGIME_A_DEMANDER, $magasin->refusDeVente($vendeur));
        $this->assertSame(Magasin::REFUS_REGIME_A_DEMANDER, $magasin->refusDeVente());
        $this->assertFalse($magasin->peutVendre());

        app(TenantContext::class)->runAsSociete($magasin->societe, fn () => app(ModifierParametresFiscauxSocieteAction::class)->handle($proprietaire, $magasin->societe->fresh(), ['regime_tva' => RegimeTva::ReelNormalTrimestriel->value]));
        app(TenantContext::class)->set($magasin->fresh());

        $ticket = self::vendreAuComptant(self::responsableDeTest($magasin), Client::factory()->create(), [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]]);
        $this->assertNotNull($ticket->numero);
        $this->assertTrue($magasin->fresh()->peutVendre());
    }

    /**
     * La base refuse elle-même l'enregistrement signé d'une vente ou d'un
     * encaissement, et le numéro d'un devis, d'une société sans régime,
     * même écrits directement ; un événement du journal passe.
     */
    public function test_la_base_refuse_une_vente_un_encaissement_ou_un_devis_numerote_sans_regime(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $client = Client::factory()->create();
        $cable = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $cable->id, 'quantite' => 1]]);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $cable->id, 'quantite' => 1]], ['date_validite' => '2026-12-05']);
        $vente = (array) DB::table('enregistrements_fiscaux')->where('id', $ticket->enregistrementDeVente()->firstOrFail()->id)->first();
        $sequence = 1000;
        $copie = function (array $modifications) use ($vente, &$sequence): array {
            $ligne = [...$vente, ...$modifications, 'sequence' => $sequence++];
            unset($ligne['id']);

            return $ligne;
        };
        $this->effacerLeRegime($magasin);

        foreach ([['chaine' => 'vente', 'type' => 'ticket'], ['chaine' => 'vente', 'type' => 'avoir'], ['chaine' => 'encaissement', 'type' => 'paiement']] as $refusee) {
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('enregistrements_fiscaux')->insert($copie($refusee))), QueryException::class, GardesDuRegimeDeTva::MESSAGE);
        }
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('devis')->where('id', $devis->id)->update(['numero' => 'DEV-LYON-2026-000001'])), QueryException::class, GardesDuRegimeDeTva::MESSAGE);

        DB::table('enregistrements_fiscaux')->insert($copie(['chaine' => 'evenement', 'type' => '270']));
        $this->assertSame(1, DB::table('enregistrements_fiscaux')->where('sequence', '>=', 1000)->count());
        $this->assertNull($devis->fresh()->numero);
    }

    /**
     * L'« À faire » de l'espace de la société dit, en rouge, de choisir le
     * régime, au propriétaire avec le lien vers les paramètres fiscaux, à
     * l'administrateur qui ne les règle pas de le demander, sans lien ;
     * celui de chaque magasin le répète ; un régime choisi les efface.
     */
    public function test_l_a_faire_de_la_societe_et_du_magasin_demandent_le_regime(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon', 'regime_tva' => null]);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, nom: 'Admin des ventes');
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $pourLeProprietaire = $this->choseDeLaSociete($proprietaire, $magasin);
        $this->assertSame(['Choisissez le régime de TVA de la société.', UrgenceAFaire::Rouge, false, ParametresFiscauxSociete::getUrl(panel: 'societe', tenant: $magasin->societe), 'Choisir le régime'], [$pourLeProprietaire?->phrase, $pourLeProprietaire?->urgence, $pourLeProprietaire?->aSurveiller, $pourLeProprietaire?->url, $pourLeProprietaire?->bouton]);
        $pourLAdministrateur = $this->choseDeLaSociete($administrateur, $magasin);
        $this->assertSame([Magasin::REFUS_REGIME_A_DEMANDER, true, null], [$pourLAdministrateur?->phrase, $pourLAdministrateur?->aSurveiller, $pourLAdministrateur?->url]);

        $duMagasin = $this->choseDuMagasin($vendeur, $magasin);
        $this->assertSame([Magasin::REFUS_REGIME_A_DEMANDER, UrgenceAFaire::Rouge, true], [$duMagasin?->phrase, $duMagasin?->urgence, $duMagasin?->aSurveiller]);
        $this->assertSame(Magasin::REFUS_REGIME_A_CHOISIR, $this->choseDuMagasin($proprietaire, $magasin)?->phrase);

        DB::table('societes')->where('id', $magasin->societe_id)->update(['regime_tva' => RegimeTva::ReelNormalMensuel->value]);
        $this->assertNull($this->choseDeLaSociete($proprietaire, $magasin));
        $this->assertNull($this->choseDuMagasin($vendeur, $magasin));
    }

    /**
     * Une société en lecture seule ne choisit plus son régime : sans régime,
     * révoquée, son propriétaire ne lit ni la ligne rouge de son « À
     * faire », ni celle du magasin, ni le message du tableau de bord, ni un
     * lien vers les paramètres fiscaux dans l'étape, qui dit seulement que
     * le régime ne se choisit pas en lecture seule ; la vente reste refusée
     * pour la lecture seule. De même, une société révoquée au réel normal
     * ne demande plus son crédit de TVA : ni message, ni étape, ni ligne
     * de l'« À faire ».
     */
    public function test_une_societe_en_lecture_seule_ne_demande_ni_le_regime_ni_le_credit(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon', 'regime_tva' => null]);
        $proprietaire = $this->proprietaireDe($magasin);
        $this->revoquerLaSociete($magasin->societe()->firstOrFail());
        $societe = $magasin->societe()->firstOrFail();

        $this->assertTrue($societe->estEnLectureSeule());
        $this->assertNull($this->choseDeLaSociete($proprietaire, $magasin));
        $this->assertNull($this->choseDuMagasin($proprietaire, $magasin));
        $this->assertSame($magasin->fresh()->refusEnLectureSeule(), $magasin->fresh()->refusDeVente($proprietaire));

        $this->dansLEspaceDeLaSociete($societe, $proprietaire);
        $tableau = Livewire::test(TableauDeBord::class)->assertDontSeeHtml('data-message-de-la-tva');
        $etapes = $tableau->instance()->etapes();
        $regime = $etapes[array_search(TableauDeBord::ETAPE_REGIME_DE_TVA, array_column($etapes, 'titre'), true)];
        $this->assertSame([false, 'Pas encore choisi : il ne se choisit pas tant que votre société est en lecture seule.', null], [$regime['faite'], $regime['etat'], $regime['url']]);

        $auReel = $this->ouvrirUneSociete(['nom' => 'Annecy']);
        $proprietaireAuReel = $this->proprietaireDe($auReel);
        $this->travelTo(self::aParis('2026-12-15 10:00:00'));
        $this->revoquerLaSociete($auReel->societe()->firstOrFail());
        $revoquee = $auReel->societe()->firstOrFail();
        $this->dansLEspaceDeLaSociete($revoquee, $proprietaireAuReel);
        $etapes = Livewire::test(TableauDeBord::class)->assertDontSeeHtml('data-message-de-la-tva')->instance()->etapes();

        $this->assertNotContains(TextesDuCreditDeTva::TITRE_DE_L_ETAPE, array_column($etapes, 'titre'));
        $this->assertSame([], array_values(array_filter(app(ChosesAFaireDeLaSociete::class)->delaSociete($proprietaireAuReel, $revoquee), fn (ChoseAFaire $chose): bool => str_starts_with($chose->cle, 'credit_de_tva'))));
    }

    /**
     * La liste d'étapes du propriétaire demande « Choisir le régime de
     * TVA » avant « Créer votre premier magasin », et la coche une fois le
     * régime choisi.
     */
    public function test_la_liste_d_etapes_demande_le_regime_avant_le_premier_magasin(): void
    {
        $societe = $this->inscrireUneSociete();
        $proprietaire = $this->saisirLeCodeDuCourrier($societe);
        $this->dansLEspaceDeLaSociete($societe->fresh(), $proprietaire);

        $etapes = Livewire::test(TableauDeBord::class)->instance()->etapes();
        $titres = array_column($etapes, 'titre');
        $regime = $etapes[array_search(TableauDeBord::ETAPE_REGIME_DE_TVA, $titres, true)];

        $this->assertLessThan(array_search(TableauDeBord::ETAPE_PREMIER_MAGASIN, $titres, true), array_search(TableauDeBord::ETAPE_REGIME_DE_TVA, $titres, true));
        $this->assertSame([false, 'Obligatoire avant la première vente : il dit comment PILIQI calcule votre TVA et à quel rythme vous la déclarez.'], [$regime['faite'], $regime['etat']]);

        DB::table('societes')->where('id', $societe->id)->update(['regime_tva' => RegimeTva::ReelNormalTrimestriel->value]);
        $this->dansLEspaceDeLaSociete($societe->fresh(), $proprietaire);
        $etapes = Livewire::test(TableauDeBord::class)->instance()->etapes();
        $regime = $etapes[array_search(TableauDeBord::ETAPE_REGIME_DE_TVA, array_column($etapes, 'titre'), true)];

        $this->assertSame([true, 'Régime choisi : Réel normal, déclaration trimestrielle (CA3).'], [$regime['faite'], $regime['etat']]);
    }

    private function choseDeLaSociete(User $compte, Magasin $magasin): ?ChoseAFaire
    {
        app(TenantContext::class)->clear();
        $societe = $magasin->societe()->firstOrFail();
        $this->dansLEspaceDeLaSociete($societe, $compte);

        return collect(app(ChosesAFaireDeLaSociete::class)->delaSociete($compte, $societe))->firstWhere('cle', 'regime_de_tva');
    }

    private function choseDuMagasin(User $compte, Magasin $magasin): ?ChoseAFaire
    {
        app(TenantContext::class)->clear();
        $this->dansLeMagasin($magasin->fresh(), $compte);
        $choses = ChosesAFaireDuMagasin::pour($compte, $magasin->fresh());

        return collect([...$choses['a_faire'], ...$choses['a_surveiller']])->firstWhere('cle', 'regime_de_tva');
    }

    private function emettre(Client $client, Produit $produit): Facture
    {
        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    /**
     * Efface en base, pour l'essai, le régime d'une société qui a déjà vendu
     * (aucun écran ni aucune action ne le permet : il ne redevient jamais
     * vide), puis repose le magasin relu comme magasin actif.
     */
    private function effacerLeRegime(Magasin $magasin): void
    {
        DB::table('societes')->where('id', $magasin->societe_id)->update(['regime_tva' => null]);
        $magasin->unsetRelation('societe');
        app(TenantContext::class)->set($magasin->fresh());
    }
}
