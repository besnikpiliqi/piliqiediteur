<?php

namespace Tests\Feature\Societe;

use App\Console\Commands\Editeur\ProcedureDeLEditeur;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\TerritoireTva;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\SequenceDocument;
use App\Domain\Facturation\Services\ControleNatureOperation;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Domain\Tenancy\Actions\ChangerSiretAction;
use App\Domain\Tenancy\Actions\CorrigerLeTerritoireAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\LibererLeSiretAction;
use App\Domain\Tenancy\Actions\ModifierEtablissementAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Actions\RouvrirMagasinAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Mail\AlerteEditeurMail;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\SiretMagasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\Pages\VerificationSociete;
use App\Filament\Societe\Resources\MagasinResource\Pages\CreerMagasin;
use App\Filament\Societe\Resources\MagasinResource\Pages\ModifierMagasin;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use Database\Factories\SocieteFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\PendingCommand;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Le territoire d'un magasin (voir Territoire) : lu au répertoire Sirene, à
 * la commune de son établissement, à défaut à son code postal, il se fixe
 * au premier contrôle concluant et ne change plus. Il donne son taux de
 * TVA par défaut et son département d'outre-mer ; un magasin qui n'en a
 * pas encore ne vend pas et ne numérote aucun document. Seule la procédure
 * de l'éditeur corrige un territoire mal lu, avant le premier document
 * numéroté ; ensuite, la société ferme le magasin pour cessation, et
 * l'éditeur libère son SIRET pour un nouveau magasin sur le même
 * établissement.
 */
class TerritoireDuMagasinTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE_DU_PROPRIETAIRE = 'password';

    private const POINTE_A_PITRE = ['97110', 'POINTE-A-PITRE', '97120'];

    private const FORT_DE_FRANCE = ['97200', 'FORT-DE-FRANCE', '97209'];

    private const SAINT_DENIS_DE_LA_REUNION = ['97400', 'SAINT-DENIS', '97411'];

    private const CAYENNE = ['97300', 'CAYENNE', '97302'];

    /**
     * Un établissement dont la commune est en Martinique donne au magasin
     * la Martinique, son territoire de TVA et son taux de 8,5 % ; la fiche
     * du magasin dans l'espace de la société le dit, et le journal d'audit
     * de sa création le retient.
     */
    public function test_la_creation_fixe_le_territoire_lu_a_la_commune_de_l_etablissement(): void
    {
        $magasin = $this->ouvrirUneSocieteSituee(self::FORT_DE_FRANCE);

        $this->assertSame([Territoire::Martinique, TerritoireTva::AntillesReunion, 8.5], [$magasin->territoire, $magasin->territoireTva(), $magasin->tauxTvaParDefaut()]);
        $this->assertSame('martinique', $this->entreesDAudit($magasin->societe, ActionAuditee::MagasinCree)[0]->nouvelles_valeurs['territoire']);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        Livewire::test(ModifierMagasin::class, ['record' => $magasin->getRouteKey()])->assertSee('Martinique');
    }

    /**
     * Le répertoire ne diffuse pas l'adresse d'un établissement en
     * diffusion partielle, mais sa commune : le magasin est en Martinique,
     * à 8,5 %, et non en métropole à 20 % faute de code postal. Une adresse
     * saisie en Martinique passe ; une adresse en métropole est refusée.
     */
    public function test_un_etablissement_en_diffusion_partielle_prend_le_territoire_de_sa_commune(): void
    {
        $magasin = $this->ouvrirUneSocieteSituee(self::FORT_DE_FRANCE, ['siren' => FauxRepertoireSirene::SIREN_DIFFUSION_PARTIELLE]);

        $this->assertSame([Territoire::Martinique, null, 8.5], [$magasin->territoire, $magasin->code_postal, $magasin->tauxTvaParDefaut()]);

        $this->modifier($magasin, ['adresse' => '12 rue Victor Hugo', 'code_postal' => '97200', 'ville' => 'Fort-de-France']);
        $this->assertThrows(
            fn () => $this->modifier($magasin, ['code_postal' => '75001', 'ville' => 'Paris']),
            fn (ValidationException $refus): bool => $refus->errors() === ['code_postal' => [ModifierEtablissementAction::MESSAGE_TERRITOIRE_CHANGE]],
        );
        $this->assertSame(['97200', Territoire::Martinique], [$magasin->fresh()->code_postal, $magasin->fresh()->territoire]);
    }

    /**
     * Saint-Barthélemy a un code postal de la Guadeloupe, mais sa commune
     * (977) dit une collectivité d'outre-mer, hors du territoire de TVA
     * français : l'établissement est refusé, sans créer de magasin.
     */
    public function test_un_etablissement_d_une_collectivite_d_outre_mer_est_refuse_par_sa_commune(): void
    {
        $this->assertThrows(
            fn () => $this->ouvrirUneSocieteSituee(['97133', 'SAINT-BARTHELEMY', '97701']),
            fn (ValidationException $refus): bool => $refus->errors() === ['nic' => [ControleSirene::MOTIF_TERRITOIRE_NON_GERE]],
        );
        $this->assertSame(0, Magasin::query()->count());
    }

    /**
     * Un établissement que le répertoire ne situe dans aucune commune
     * française, comme un établissement à l'étranger, n'a ni taux ni heure
     * : il est refusé à la création, au changement de SIRET et à la
     * réouverture, sans rien changer.
     */
    public function test_un_etablissement_qu_aucune_commune_francaise_ne_situe_est_refuse(): void
    {
        $siren = SocieteFactory::sirenFictif();
        $siege = FauxRepertoireSirene::siretDuSiege($siren);
        $this->repertoire()->situerHorsDeFrance($siege);

        $this->assertThrows(
            fn () => $this->ouvrirUneSociete(['siren' => $siren, 'nic' => substr($siege, 9)]),
            fn (ValidationException $refus): bool => $refus->errors() === ['nic' => [ControleSirene::MOTIF_TERRITOIRE_INCONNU]],
        );

        $magasin = $this->ouvrirUneSocieteSituee(self::FORT_DE_FRANCE);
        $aLEtranger = IdentifiantsInsee::avecSaCle($magasin->societe->siren.'0007');
        $this->repertoire()->situerHorsDeFrance($aLEtranger);
        app(TenantContext::class)->clear();
        $this->assertThrows(
            fn () => app(ChangerSiretAction::class)->handle($this->proprietaireDe($magasin), $magasin, substr($aLEtranger, 9), self::MOT_DE_PASSE_DU_PROPRIETAIRE),
            fn (ValidationException $refus): bool => $refus->errors() === ['nic' => [ControleSirene::MOTIF_TERRITOIRE_INCONNU]],
        );

        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Temporaire, self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        $this->repertoire()->situerHorsDeFrance((string) $magasin->siret);
        $this->assertThrows(
            fn () => app(RouvrirMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), self::MOT_DE_PASSE_DU_PROPRIETAIRE),
            LogicException::class,
            ControleSirene::MOTIF_TERRITOIRE_INCONNU,
        );

        $intact = $magasin->fresh();
        $this->assertSame([Territoire::Martinique, $magasin->siret, true], [$intact->territoire, $intact->siret, $intact->estFerme()]);
    }

    /**
     * Créé quand l'INSEE ne répond pas, le magasin n'a pas de territoire,
     * donc ni heure ni année pour ses numéros : il ne valide ni facture
     * d'achat ni inventaire d'ouverture, et aucun numéro n'est consommé ;
     * la fiche de l'espace de la société dit que son territoire est à
     * fixer.
     */
    public function test_un_magasin_cree_quand_l_insee_ne_repond_pas_ne_numerote_rien(): void
    {
        $magasin = $this->magasinCreePendantUnePanne();

        $this->assertNull($magasin->territoire);
        $this->assertThrows(fn () => $this->validerUnAchat(), LogicException::class, Magasin::REFUS_TERRITOIRE_INCONNU);
        $this->assertThrows(fn () => $this->validerUnInventaireDOuverture(), LogicException::class, Magasin::REFUS_TERRITOIRE_INCONNU);
        $this->assertSame(0, SequenceDocument::query()->where('magasin_id', $magasin->id)->where('type_document', '!=', TypeDocument::Produit->value)->count());
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        Livewire::test(ModifierMagasin::class, ['record' => $magasin->getRouteKey()])->assertSee('à fixer : établissement pas encore confirmé');
    }

    /**
     * La reprise du contrôle, l'INSEE revenu, confirme l'établissement et
     * fixe le territoire lu à sa commune, ce qu'inscrit le journal des
     * événements du magasin (JET 270, de rien à la Martinique) ; le magasin
     * numérote alors ses achats. Le 16 décembre 2026 à 15 h UTC, Paris et
     * la Martinique ont le même jour et la même année.
     */
    public function test_la_reprise_du_controle_fixe_le_territoire_et_l_inscrit_au_journal_des_evenements(): void
    {
        $this->travelTo(self::enUtc('2026-12-16 15:00:00'));
        $magasin = $this->magasinCreePendantUnePanne();
        $this->repertoire()->rendreInjoignable(false);
        $this->repertoire()->deplacer((string) $magasin->siret, ...self::FORT_DE_FRANCE);

        $this->artisan('app:reverifier-sirene')->assertSuccessful();

        $confirme = $magasin->fresh();
        $this->assertSame([Territoire::Martinique, '97200'], [$confirme->territoire, $confirme->code_postal]);
        $this->assertNotNull($confirme->etablissement_verifie_le);
        $this->assertSame([['parametre' => 'territoire', 'magasin' => ['id' => $magasin->id, 'nom' => 'Ma Boutique'], 'ancienne_valeur' => null, 'nouvelle_valeur' => 'martinique']], $this->changementsDeTerritoire($magasin));
        app(TenantContext::class)->set($confirme);
        $this->assertSame("FA-{$confirme->code}-2026-000001", $this->validerUnAchat()->numero);
    }

    /**
     * Fermé avant d'avoir été confirmé, le magasin reçoit son territoire à
     * sa réouverture, quand le répertoire confirme enfin son établissement
     * (JET 270, journal d'audit).
     */
    public function test_une_reouverture_fixe_le_territoire_d_un_magasin_jamais_confirme(): void
    {
        $magasin = $this->magasinCreePendantUnePanne();
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Temporaire, self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        $this->repertoire()->rendreInjoignable(false);
        $this->repertoire()->deplacer((string) $magasin->siret, ...self::POINTE_A_PITRE);

        app(RouvrirMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), self::MOT_DE_PASSE_DU_PROPRIETAIRE);

        $this->assertSame(Territoire::Guadeloupe, $magasin->fresh()->territoire);
        $this->assertSame([[null, 'guadeloupe']], array_map(fn (array $details): array => [$details['ancienne_valeur'], $details['nouvelle_valeur']], $this->changementsDeTerritoire($magasin)));
        $entree = $this->entreesDAudit($magasin->societe, ActionAuditee::MagasinRouvert)[0];
        $this->assertSame([null, 'guadeloupe'], [$entree->anciennes_valeurs['territoire'], $entree->nouvelles_valeurs['territoire']]);
    }

    /**
     * Un magasin de la Guadeloupe ne déménage ni à La Réunion ni en
     * Martinique, au même taux de TVA, en restant le même magasin : ni par
     * un changement de SIRET, ni par un NIC corrigé avant la validation
     * (réponse A de l'éditeur, 25/10/2026). Rien ne change.
     */
    public function test_un_nouvel_etablissement_d_un_autre_territoire_est_refuse_meme_au_meme_taux(): void
    {
        $valide = $this->ouvrirUneSocieteSituee(self::POINTE_A_PITRE);
        $aVerifier = $this->ouvrirUneSocieteSituee(self::POINTE_A_PITRE, ['a_verifier' => true]);
        app(TenantContext::class)->clear();

        foreach ([self::SAINT_DENIS_DE_LA_REUNION, self::FORT_DE_FRANCE] as $ailleurs) {
            $siret = IdentifiantsInsee::avecSaCle($valide->societe->siren.'0007');
            $nic = IdentifiantsInsee::avecSaCle($aVerifier->societe->siren.'0007');
            $this->repertoire()->deplacer($siret, ...$ailleurs);
            $this->repertoire()->deplacer($nic, ...$ailleurs);

            $this->assertThrows(
                fn () => app(ChangerSiretAction::class)->handle($this->proprietaireDe($valide), $valide->fresh(), substr($siret, 9), self::MOT_DE_PASSE_DU_PROPRIETAIRE),
                fn (ValidationException $refus): bool => $refus->errors() === ['nic' => [ChangerSiretAction::MESSAGE_TERRITOIRE_CHANGE]],
            );
            $this->assertThrows(
                fn () => $this->modifier($aVerifier, ['nic' => substr($nic, 9)]),
                fn (ValidationException $refus): bool => $refus->errors() === ['nic' => [ChangerSiretAction::MESSAGE_TERRITOIRE_CHANGE]],
            );
        }

        $this->assertSame([Territoire::Guadeloupe, $valide->siret], [$valide->fresh()->territoire, $valide->fresh()->siret]);
        $this->assertSame([Territoire::Guadeloupe, $aVerifier->siret], [$aVerifier->fresh()->territoire, $aVerifier->fresh()->siret]);
    }

    /**
     * Un NIC corrigé pendant une panne de l'INSEE est accepté en attente ;
     * s'il désigne un établissement d'un autre territoire, la reprise du
     * contrôle ne le confirme pas : le contrôle s'inscrit négatif avec son
     * motif, que la page de la vérification montre, l'établissement reste
     * à vérifier, et ni l'adresse ni le territoire du magasin ne changent.
     */
    public function test_un_nic_corrige_pendant_une_panne_vers_un_autre_territoire_ne_se_confirme_pas_a_la_reprise(): void
    {
        $magasin = $this->ouvrirUneSocieteSituee(self::POINTE_A_PITRE, ['a_verifier' => true]);
        $aLaReunion = IdentifiantsInsee::avecSaCle($magasin->societe->siren.'0007');
        $this->repertoire()->rendreInjoignable();
        $this->modifier($magasin, ['nic' => substr($aLaReunion, 9)]);
        $this->assertSame([$aLaReunion, null], [$magasin->fresh()->siret, $magasin->fresh()->etablissement_verifie_le]);
        $magasin->societe->forceFill(['validee_le' => now()])->save();
        $this->repertoire()->rendreInjoignable(false);
        $this->repertoire()->deplacer($aLaReunion, ...self::SAINT_DENIS_DE_LA_REUNION);

        $this->artisan('app:reverifier-sirene')->assertSuccessful();

        $repris = $magasin->fresh();
        $this->assertSame([Territoire::Guadeloupe, '97110', null], [$repris->territoire, $repris->code_postal, $repris->etablissement_verifie_le]);
        $this->assertSame(['negative', $aLaReunion], $this->controlesDeLEtablissement($magasin)[array_key_last($this->controlesDeLEtablissement($magasin))]);
        $this->assertSame("L'établissement de ce magasin (SIRET {$aLaReunion}) n'est pas encore confirmé par le répertoire Sirene de l'INSEE : aucune vente avant sa confirmation.", $repris->refusDeVente());
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        Livewire::test(VerificationSociete::class)->assertSee(ChangerSiretAction::MESSAGE_TERRITOIRE_CHANGE);
    }

    /**
     * Le département d'un magasin de la Martinique se lit à son territoire,
     * même quand le répertoire ne diffuse pas son code postal : une
     * « Livraison vers un DOM » à Fort-de-France reste dans son propre
     * département et reste à la TVA française ; à Pointe-à-Pitre, elle va
     * dans un autre département.
     */
    public function test_une_livraison_dans_le_departement_d_un_magasin_sans_code_postal_reste_taxee(): void
    {
        $magasin = $this->ouvrirUneSocieteSituee(self::FORT_DE_FRANCE, ['siren' => FauxRepertoireSirene::SIREN_DIFFUSION_PARTIELLE]);
        $this->assertNull($magasin->code_postal);
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 8.5, 'suivi_stock' => false]);
        $livraison = fn (array $destination): array => app(ControleNatureOperation::class)->manquements(
            app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]], [
                'nature_operation' => NatureOperation::LivraisonDom,
                'livraison_adresse' => '1 rue Victor Hugo',
                'livraison_code_postal' => $destination[0],
                'livraison_ville' => $destination[1],
                'livraison_pays' => 'FR',
            ]),
            '2026-10-09',
        );

        $this->assertContains('la livraison reste dans le département d\'outre-mer du magasin', $livraison(self::FORT_DE_FRANCE));
        $this->assertNotContains('la livraison reste dans le département d\'outre-mer du magasin', $livraison(self::POINTE_A_PITRE));
    }

    /**
     * Une adresse saisie ne sort jamais le magasin de son territoire, même
     * vers un autre territoire au même taux de TVA : La Réunion et la
     * Martinique sont refusées à un magasin de la Guadeloupe, comme une
     * adresse dans un autre pays, même sans code postal ; Pointe-à-Pitre
     * passe, et une adresse en France sans code postal aussi.
     */
    public function test_une_adresse_d_un_autre_territoire_est_refusee_meme_au_meme_taux(): void
    {
        $magasin = $this->ouvrirUneSocieteSituee(self::POINTE_A_PITRE);

        foreach (['97400', '97200'] as $codePostal) {
            $this->assertThrows(
                fn () => $this->modifier($magasin, ['code_postal' => $codePostal]),
                fn (ValidationException $refus): bool => $refus->errors() === ['code_postal' => [ModifierEtablissementAction::MESSAGE_TERRITOIRE_CHANGE]],
            );
        }

        $this->assertThrows(
            fn () => $this->modifier($magasin, ['code_postal' => null, 'pays' => 'BE', 'ville' => 'Bruxelles']),
            fn (ValidationException $refus): bool => $refus->errors() === ['pays' => [ModifierEtablissementAction::MESSAGE_TERRITOIRE_CHANGE]],
        );
        $this->assertSame(['FR', '97110', 'POINTE-A-PITRE'], [$magasin->fresh()->pays, $magasin->fresh()->code_postal, $magasin->fresh()->ville]);

        $this->modifier($magasin, ['adresse' => '3 rue Frébault', 'code_postal' => '97110']);
        $this->modifier($magasin, ['code_postal' => null]);
        $this->assertSame(['3 rue Frébault', null, Territoire::Guadeloupe], [$magasin->fresh()->adresse, $magasin->fresh()->code_postal, $magasin->fresh()->territoire]);
    }

    /**
     * Un territoire renseigné ne s'efface jamais, ni par le modèle ni par
     * une requête qui ne passe pas par lui ; le modèle refuse aussi de le
     * changer, et la base le refuse dès que le magasin a numéroté un
     * document (jusque-là, seule la procédure de l'éditeur le corrige, voir
     * le test suivant) ; poser un territoire sur un magasin qui n'en a pas
     * passe.
     */
    public function test_la_base_et_le_modele_refusent_de_changer_un_territoire_fixe(): void
    {
        $magasin = Magasin::factory()->dansLeTerritoire(Territoire::Martinique)->create();
        $sansTerritoire = Magasin::factory()->create(['territoire' => null, 'etablissement_verifie_le' => null]);
        $enBase = fn (Magasin $leMagasin, ?string $territoire): int => DB::table('magasins')->where('id', $leMagasin->id)->update(['territoire' => $territoire]);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => $enBase($magasin, null)), QueryException::class);
        $this->assertThrows(fn () => app(TenantContext::class)->runAsSociete($magasin->societe, fn (): bool => $magasin->fresh()->forceFill(['territoire' => Territoire::Guadeloupe])->save()), LogicException::class, 'Le territoire d\'un magasin ne change plus une fois fixé');
        $this->assertSame(1, $enBase($sansTerritoire, 'martinique'));

        app(DocumentNumberingService::class)->suivant($magasin, TypeDocument::Devis, '2026');

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => $enBase($magasin, 'guadeloupe')), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => $enBase($magasin, null)), QueryException::class);
        $this->assertSame(1, $enBase($magasin, 'martinique'));
        $this->assertSame([Territoire::Martinique, Territoire::Martinique], [$magasin->fresh()->territoire, $sansTerritoire->fresh()->territoire]);
    }

    /**
     * Une revérification qui lit, pour l'établissement déjà confirmé du
     * magasin, un autre territoire (sa commune corrigée au répertoire)
     * prévient le propriétaire et l'éditeur, sans rien changer, en plus de
     * l'écart d'adresse ; une réouverture fait de même.
     */
    public function test_une_reverification_qui_lit_un_autre_territoire_alerte_sans_rien_changer(): void
    {
        Mail::fake();
        config(['editeur.email' => 'editeur@piliqi.test']);
        $magasin = $this->ouvrirUneSocieteSituee(self::FORT_DE_FRANCE);
        $this->repertoire()->deplacer((string) $magasin->siret, ...self::POINTE_A_PITRE);

        $this->artisan('app:reverifier-sirene', ['--toutes' => true])->assertSuccessful();

        $this->assertSame(Territoire::Martinique, $magasin->fresh()->territoire);
        Mail::assertSent(AlerteEditeurMail::class, fn (AlerteEditeurMail $mail): bool => $mail->objet === 'Revérification Sirene : établissement situé dans un autre territoire');
        $this->assertSame(['Écart au répertoire Sirene', 'Territoire de votre magasin au répertoire Sirene'], $this->titresDesAvis($magasin->societe));

        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Temporaire, self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        app(RouvrirMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), self::MOT_DE_PASSE_DU_PROPRIETAIRE);

        $this->assertSame([Territoire::Martinique, false], [$magasin->fresh()->territoire, $magasin->fresh()->estFerme()]);
        $this->assertCount(2, Mail::sent(AlerteEditeurMail::class, fn (AlerteEditeurMail $mail): bool => $mail->objet === 'Revérification Sirene : établissement situé dans un autre territoire'));
        $this->assertSame([], $this->changementsDeTerritoire($magasin));
    }

    /**
     * Le territoire mal lu au répertoire, puis corrigé par l'INSEE, se
     * corrige par la procédure de l'éditeur tant que le magasin n'a
     * numéroté aucun document (réponse C de l'éditeur, 25/10/2026) : la
     * correction prend le territoire que lit le répertoire, s'inscrit au
     * journal des événements du magasin et au journal d'audit, et le
     * propriétaire en est prévenu. Après le premier document numéroté, la
     * procédure la refuse, et rien ne change.
     */
    public function test_l_editeur_corrige_un_territoire_mal_lu_avant_le_premier_document_numerote_et_jamais_apres(): void
    {
        $magasin = $this->ouvrirUneSocieteSituee(self::FORT_DE_FRANCE);
        $this->repertoire()->deplacer((string) $magasin->siret, ...self::POINTE_A_PITRE);

        $this->corrigerLeTerritoire($magasin)->expectsOutputToContain("Territoire du magasin {$magasin->code} corrigé : Guadeloupe.")->assertSuccessful();

        $this->assertSame([Territoire::Guadeloupe, 8.5], [$magasin->fresh()->territoire, $magasin->fresh()->tauxTvaParDefaut()]);
        $this->assertSame([['martinique', 'guadeloupe']], array_map(fn (array $details): array => [$details['ancienne_valeur'], $details['nouvelle_valeur']], $this->changementsDeTerritoire($magasin)));
        $entree = $this->entreesDAudit($magasin->societe, ActionAuditee::TerritoireCorrige)[0];
        $this->assertSame([['territoire' => 'martinique'], ['territoire' => 'guadeloupe'], 'editeur', null], [$entree->anciennes_valeurs, $entree->nouvelles_valeurs, $entree->origine, $entree->auteur_id]);
        $this->assertSame(['Territoire de votre magasin corrigé'], $this->titresDesAvis($magasin->societe));

        app(TenantContext::class)->set($magasin->fresh());
        $this->validerUnAchat();
        $this->repertoire()->deplacer((string) $magasin->siret, ...self::SAINT_DENIS_DE_LA_REUNION);

        $this->corrigerLeTerritoire($magasin)->expectsOutputToContain(CorrigerLeTerritoireAction::MESSAGE_DOCUMENT_NUMEROTE)->assertFailed();
        $this->assertSame(Territoire::Guadeloupe, $magasin->fresh()->territoire);
    }

    /**
     * L'alerte d'un territoire lu ailleurs ne propose au propriétaire que la
     * suite possible, sans nommer de commande, et seule l'alerte à
     * l'éditeur nomme ses procédures : pour un territoire que le logiciel ne
     * gère pas (Cayenne), aucune correction ; avant le premier document
     * numéroté, la correction du territoire par l'éditeur ; ensuite, la
     * fermeture pour cessation, puis la libération du SIRET par l'éditeur
     * pour un nouveau magasin (réponse C de l'éditeur, 25/10/2026).
     */
    public function test_l_alerte_d_un_territoire_lu_ailleurs_ne_propose_que_la_suite_possible(): void
    {
        Mail::fake();
        config(['editeur.email' => 'editeur@piliqi.test']);
        $magasin = $this->ouvrirUneSocieteSituee(self::FORT_DE_FRANCE);
        $ecart = fn (string $lieu): string => "Ma Boutique (SIRET {$magasin->siret}) : le répertoire Sirene situe désormais son établissement {$lieu}, et non en Martinique ; son territoire, qui fixe son taux de TVA par défaut et son heure, ne change pas.";

        $this->repertoire()->deplacer((string) $magasin->siret, ...self::CAYENNE);
        $this->assertSame([
            $ecart('en Guyane').' Le logiciel ne gère pas ce territoire : aucune correction n\'est possible, ni du territoire du magasin ni par un nouveau magasin sur cet établissement. Vos ventes continuent ; l\'éditeur, prévenu, pourra vous contacter.',
            'Territoire que le logiciel ne gère pas : ni editeur:corriger-territoire ni editeur:liberer-siret ne s\'appliquent ; à vous de décider.',
        ], $this->suiteDeLAlerteDuTerritoire($magasin));

        $this->repertoire()->deplacer((string) $magasin->siret, ...self::POINTE_A_PITRE);
        $this->assertSame([
            $ecart('en Guadeloupe').' Le magasin n\'a numéroté aucun document : l\'éditeur peut corriger son territoire. Vos ventes continuent ; l\'éditeur, prévenu, pourra vous contacter.',
            'Le magasin n\'a numéroté aucun document : corrigez son territoire (editeur:corriger-territoire).',
        ], $this->suiteDeLAlerteDuTerritoire($magasin));

        $this->validerUnAchat();
        $this->assertSame([
            $ecart('en Guadeloupe').' Le magasin a déjà numéroté des documents : son territoire ne se corrige plus. Pour vendre sous le bon territoire, fermez ce magasin pour cessation ; l\'éditeur libérera alors son SIRET, et vous créerez un nouveau magasin sur cet établissement. Vos ventes continuent ; l\'éditeur, prévenu, pourra vous contacter.',
            'Le magasin a déjà numéroté des documents : son territoire ne se corrige plus. Une fois le magasin fermé pour cessation par la société, libérez son SIRET (editeur:liberer-siret) pour un nouveau magasin sur cet établissement.',
        ], $this->suiteDeLAlerteDuTerritoire($magasin));
    }

    /**
     * Après le premier document numéroté, le territoire mal lu ne se
     * corrige plus, et la procédure de correction dit la suite, dans son
     * ordre : la société ferme le magasin pour cessation (la libération
     * l'attend), puis l'éditeur libère son SIRET (réponse C de l'éditeur,
     * 25/10/2026), et un nouveau magasin s'ouvre sur le même établissement,
     * au territoire que lit le répertoire, avec sa propre réservation du
     * SIRET. L'ancien
     * magasin garde son SIRET et son territoire, ne se rouvre plus et ne
     * change plus de SIRET, ce que sa fiche dit ; la libération s'inscrit
     * au registre des SIRET réservés, au registre des vérifications et au
     * journal d'audit, le propriétaire en est prévenu, et elle ne se refait
     * pas.
     */
    public function test_apres_un_document_numerote_le_siret_libere_par_l_editeur_sert_a_un_nouveau_magasin(): void
    {
        $magasin = $this->ouvrirUneSocieteSituee(self::FORT_DE_FRANCE);
        $this->validerUnAchat();
        $this->repertoire()->deplacer((string) $magasin->siret, ...self::POINTE_A_PITRE);
        $this->corrigerLeTerritoire($magasin)
            ->expectsOutputToContain('La société ferme ce magasin pour cessation, puis l\'éditeur libère son SIRET (editeur:liberer-siret) pour un nouveau magasin sur le même établissement.')
            ->assertFailed();
        $this->libererLeSiret($magasin)->expectsOutputToContain(LibererLeSiretAction::MESSAGE_PAS_FERME_POUR_CESSATION)->assertFailed();
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Cessation, self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        $this->assertThrows(fn () => $this->ouvrirUnAutreMagasin($magasin, ['nic' => substr((string) $magasin->siret, 9)]), ValidationException::class);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        Livewire::test(CreerMagasin::class)->assertSeeText(CreerMagasin::MARQUE_DEJA_UN_MAGASIN);

        $this->libererLeSiret($magasin)
            ->expectsOutputToContain("SIRET {$magasin->siret} du magasin {$magasin->code} libéré : la société peut créer un nouveau magasin sur cet établissement, qui prendra son territoire (Guadeloupe).")
            ->assertSuccessful();

        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        Livewire::test(CreerMagasin::class)
            ->assertDontSeeText(CreerMagasin::MARQUE_DEJA_UN_MAGASIN)
            ->fillForm(['etablissement' => $magasin->siret, 'nom' => 'Boutique de Pointe-à-Pitre'])
            ->call('create')
            ->assertHasNoFormErrors();
        $nouveau = $magasin->societe->magasins()->where('nom', 'Boutique de Pointe-à-Pitre')->sole();
        $this->assertSame([$magasin->siret, Territoire::Guadeloupe, 8.5], [$nouveau->siret, $nouveau->territoire, $nouveau->tauxTvaParDefaut()]);
        $this->assertNotSame($magasin->code, $nouveau->code);
        $this->assertSame(
            [[$magasin->id, true, LibererLeSiretAction::MOTIF_TERRITOIRE_MAL_LU], [$nouveau->id, false, null]],
            SiretMagasin::query()->where('siret', $magasin->siret)->orderBy('id')->get()->map(fn (SiretMagasin $reservation): array => [$reservation->magasin_id, $reservation->libere_le !== null, $reservation->motif_liberation])->all(),
        );
        $ancien = $magasin->fresh();
        $this->assertSame([$magasin->siret, Territoire::Martinique, true, true], [$ancien->siret, $ancien->territoire, $ancien->aLibereSonSiret(), $ancien->estFermePourCessation()]);
        $this->assertSame([['concluante', $magasin->siret], ['concluante', $magasin->siret]], $this->controlesDeLEtablissement($magasin));
        $entree = $this->entreesDAudit($magasin->societe, ActionAuditee::SiretLibere)[0];
        $this->assertSame(
            [null, ['motif' => LibererLeSiretAction::MOTIF_TERRITOIRE_MAL_LU, 'siret' => $magasin->siret, 'territoire' => 'martinique', 'territoire_lu' => 'guadeloupe'], 'editeur', null, $magasin->id],
            [$entree->anciennes_valeurs, $entree->nouvelles_valeurs, $entree->origine, $entree->auteur_id, $entree->magasin_id],
        );
        $this->assertContains('SIRET de votre magasin libéré', $this->titresDesAvis($magasin->societe));

        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        Livewire::test(ModifierMagasin::class, ['record' => $magasin->getRouteKey()])
            ->assertSeeText('Fermé : cessation d\'activité ; SIRET libéré pour un nouveau magasin')
            ->assertActionHidden('rouvrir')
            ->assertActionHidden('changerLeSiret');
        $this->assertThrows(fn () => app(RouvrirMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), self::MOT_DE_PASSE_DU_PROPRIETAIRE), LogicException::class, RouvrirMagasinAction::MESSAGE_SIRET_LIBERE);
        $this->assertThrows(fn () => app(ChangerSiretAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), substr(IdentifiantsInsee::avecSaCle($magasin->societe->siren.'0007'), 9), self::MOT_DE_PASSE_DU_PROPRIETAIRE), LogicException::class, ChangerSiretAction::MESSAGE_SIRET_LIBERE);
        $this->libererLeSiret($magasin)->expectsOutputToContain(LibererLeSiretAction::MESSAGE_DEJA_LIBERE)->assertFailed();
        $this->assertSame([true, $magasin->siret], [$magasin->fresh()->estFermePourCessation(), $magasin->fresh()->siret]);
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::SiretLibere));
    }

    /**
     * Avant la validation de la société, rien n'est réservé : la libération
     * ne se pose que sur le magasin, fermé pour cessation, dont le NIC ne
     * se corrige plus, et un nouveau magasin s'ouvre sur le même
     * établissement, au territoire que lit le répertoire.
     */
    public function test_avant_la_validation_le_siret_libere_sert_aussi_a_un_nouveau_magasin(): void
    {
        $magasin = $this->ouvrirUneSocieteSituee(self::FORT_DE_FRANCE, ['a_verifier' => true]);
        $this->validerUnAchat();
        $this->repertoire()->deplacer((string) $magasin->siret, ...self::POINTE_A_PITRE);
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Cessation, self::MOT_DE_PASSE_DU_PROPRIETAIRE);

        $this->libererLeSiret($magasin)->assertSuccessful();

        $this->assertThrows(fn () => $this->modifier($magasin, ['nic' => substr(IdentifiantsInsee::avecSaCle($magasin->societe->siren.'0007'), 9)]), LogicException::class, ModifierEtablissementAction::MESSAGE_SIRET_LIBERE);
        $nouveau = $this->ouvrirUnAutreMagasin($magasin, ['nic' => substr((string) $magasin->siret, 9)]);
        $this->assertSame([$magasin->siret, Territoire::Guadeloupe], [$nouveau->siret, $nouveau->territoire]);
        $this->assertSame([$magasin->siret, true], [$magasin->fresh()->siret, $magasin->fresh()->aLibereSonSiret()]);
        $this->assertSame(0, SiretMagasin::query()->where('siret', $magasin->siret)->count());
    }

    /**
     * Le SIRET ne se libère que dans son cas, et une libération refusée
     * n'écrit rien : un magasin ouvert ou fermé temporairement (la société
     * le ferme d'abord pour cessation), sans document numéroté, ouvert ou
     * fermé (son territoire se corrige), que le répertoire situe dans son
     * propre territoire ou dans un territoire que le logiciel ne gère pas,
     * ou dont l'INSEE ne confirme pas l'établissement, garde son SIRET.
     */
    public function test_la_liberation_du_siret_est_refusee_hors_de_son_cas_sans_rien_ecrire(): void
    {
        $magasin = $this->ouvrirUneSocieteSituee(self::FORT_DE_FRANCE);
        $this->validerUnAchat();
        $sansDocument = $this->ouvrirUnAutreMagasinSitue($magasin, Territoire::Martinique);
        $this->libererLeSiret($sansDocument)->expectsOutputToContain(LibererLeSiretAction::MESSAGE_AUCUN_DOCUMENT)->assertFailed();
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $sansDocument->fresh(), MotifFermeture::Cessation, self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        $this->repertoire()->deplacer((string) $sansDocument->siret, ...self::POINTE_A_PITRE);
        $this->libererLeSiret($sansDocument)->expectsOutputToContain(LibererLeSiretAction::MESSAGE_AUCUN_DOCUMENT)->assertFailed();
        $this->repertoire()->deplacer((string) $magasin->siret, ...self::POINTE_A_PITRE);
        $this->libererLeSiret($magasin)->expectsOutputToContain(LibererLeSiretAction::MESSAGE_PAS_FERME_POUR_CESSATION)->assertFailed();
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Temporaire, self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        $this->libererLeSiret($magasin)->expectsOutputToContain(LibererLeSiretAction::MESSAGE_PAS_FERME_POUR_CESSATION)->assertFailed();
        $this->repertoire()->deplacer((string) $magasin->siret, ...self::FORT_DE_FRANCE);
        app(RouvrirMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Cessation, self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        $controles = $this->controlesDeLEtablissement($magasin);

        $this->libererLeSiret($magasin)->expectsOutputToContain(LibererLeSiretAction::MESSAGE_MEME_TERRITOIRE)->assertFailed();
        $this->repertoire()->deplacer((string) $magasin->siret, ...self::CAYENNE);
        $this->libererLeSiret($magasin)->expectsOutputToContain(ControleSirene::MOTIF_TERRITOIRE_NON_GERE)->assertFailed();
        $this->repertoire()->rendreInjoignable();
        $this->libererLeSiret($magasin)->expectsOutputToContain(LibererLeSiretAction::MESSAGE_REPERTOIRE_NE_CONFIRME_PAS)->assertFailed();

        $this->assertSame([false, false], [$magasin->fresh()->aLibereSonSiret(), $sansDocument->fresh()->aLibereSonSiret()]);
        $this->assertSame(0, SiretMagasin::query()->whereIn('magasin_id', [$magasin->id, $sansDocument->id])->whereNotNull('libere_le')->count());
        $this->assertSame($controles, $this->controlesDeLEtablissement($magasin));
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::SiretLibere));
    }

    /**
     * La procédure désigne le magasin par son code dans la société donnée :
     * le code d'un magasin d'une autre société n'y désigne rien, même quand
     * ce magasin remplit toutes les conditions de la libération, et rien ne
     * change.
     */
    public function test_la_liberation_ne_designe_jamais_le_magasin_d_une_autre_societe(): void
    {
        $autre = $this->ouvrirUneSocieteSituee(self::FORT_DE_FRANCE, ['code' => 'ANSE1']);
        $this->validerUnAchat();
        $this->repertoire()->deplacer((string) $autre->siret, ...self::POINTE_A_PITRE);
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($autre), $autre->fresh(), MotifFermeture::Cessation, self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        $societe = $this->ouvrirUneSociete(['code' => 'LYON1'])->societe;

        $this->libererLeSiret($autre, $societe)->expectsOutputToContain(ProcedureDeLEditeur::MESSAGE_MAGASIN_INTROUVABLE)->assertFailed();

        $this->assertFalse($autre->fresh()->aLibereSonSiret());
        $this->assertSame([], $this->entreesDAudit($autre->societe, ActionAuditee::SiretLibere));
        $this->assertSame([], $this->entreesDAudit($societe, ActionAuditee::SiretLibere));
    }

    /**
     * Un SIRET n'appartient qu'à un seul magasin de la société dont
     * l'éditeur ne l'a pas libéré : la base refuse un second magasin sur le
     * même SIRET, avant comme après une libération, et refuse d'effacer une
     * libération, même par une requête qui ne passe pas par le modèle ; le
     * modèle ne libère que le SIRET d'un magasin fermé pour cessation, et
     * un magasin libéré ne se rouvre plus. La société, en cours de
     * vérification, n'a rien au registre des SIRET réservés : seule la
     * table des magasins garde l'unicité.
     */
    public function test_la_base_et_le_modele_gardent_un_seul_magasin_par_siret_non_libere(): void
    {
        $this->travelTo(self::enUtc('2026-11-02 10:00:00'));
        $magasin = Magasin::factory()->dansLeTerritoire(Territoire::Martinique)->create(['societe_id' => Societe::factory()->avecProprietaire()]);
        $this->assertFalse($magasin->societe->estValidee());
        $memeSiret = fn (): Magasin => Magasin::factory()->dansLeTerritoire(Territoire::Guadeloupe)->create(['societe_id' => $magasin->societe_id, 'siret' => $magasin->siret]);
        $liberer = fn (Magasin $leMagasin): bool => $leMagasin->forceFill(['siret_libere_le' => now()])->save();
        $this->assertThrows($this->dansUnPointDeSauvegarde($memeSiret), QueryException::class);
        $this->assertThrows(fn () => $liberer($magasin->fresh()), LogicException::class, 'Seul le SIRET d\'un magasin fermé pour cessation se libère');

        $magasin->forceFill(['statut' => StatutMagasin::Ferme, 'motif_fermeture' => MotifFermeture::Cessation])->save();
        $liberer($magasin->fresh());
        $nouveau = $memeSiret();

        $this->assertSame([$magasin->siret, false], [$nouveau->siret, $nouveau->aLibereSonSiret()]);
        $this->assertThrows($this->dansUnPointDeSauvegarde($memeSiret), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn (): int => DB::table('magasins')->where('id', $magasin->id)->update(['siret_libere_le' => null])), QueryException::class);
        $this->assertThrows(fn () => $magasin->fresh()->forceFill(['statut' => StatutMagasin::Actif, 'motif_fermeture' => null])->save(), LogicException::class, 'Un magasin dont le SIRET a été libéré ne se rouvre jamais et garde son SIRET.');
        $this->travelTo(self::enUtc('2026-11-03 10:00:00'));
        $this->assertThrows(fn () => $liberer($magasin->fresh()), LogicException::class, 'Seul le SIRET d\'un magasin fermé pour cessation se libère');
        $this->assertSame(self::enUtc('2026-11-02 10:00:00')->getTimestamp(), $magasin->fresh()->siret_libere_le->getTimestamp());
    }

    /**
     * Un magasin dont l'établissement est confirmé a toujours un territoire,
     * comme le magasin de démonstration : le modèle refuse de les
     * enregistrer sans lui.
     */
    public function test_un_magasin_verifie_et_la_demonstration_ont_toujours_un_territoire(): void
    {
        $societe = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);

        $this->assertSame(Territoire::Metropole, $demonstration->territoire);
        $this->assertThrows(fn () => Magasin::factory()->create(['territoire' => null]), LogicException::class);
        $this->assertThrows(fn () => Magasin::factory()->create(['est_demonstration' => true, 'siret' => null, 'territoire' => null, 'etablissement_verifie_le' => null]), LogicException::class);
    }

    /**
     * Un magasin d'une société validée, créé quand l'INSEE ne répondait
     * pas : son établissement attend sa confirmation, sans territoire.
     * L'INSEE reste injoignable ; le magasin est le magasin actif.
     */
    private function magasinCreePendantUnePanne(): Magasin
    {
        $this->repertoire()->rendreInjoignable();

        return $this->ouvrirUneSociete();
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    private function modifier(Magasin $magasin, array $donnees): Magasin
    {
        app(TenantContext::class)->clear();

        return app(ModifierEtablissementAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), $donnees);
    }

    /**
     * Une facture d'achat d'un produit, validée dans le magasin actif.
     */
    private function validerUnAchat(): FactureAchat
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 24, 'taux_tva' => 20, 'suivi_stock' => true]);

        return app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 10],
        ]));
    }

    private function validerUnInventaireDOuverture(): void
    {
        app(ValiderInventaireOuvertureAction::class)->handle(self::auteur(), app(CreerInventaireOuvertureAction::class)->handle(self::auteur(), [
            ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '2.00'],
        ]));
    }

    /**
     * Les changements du territoire inscrits au journal des événements du
     * magasin (JET 270), dans l'ordre.
     *
     * @return list<array<string, mixed>>
     */
    private function changementsDeTerritoire(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs($magasin, fn (): array => EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', '270')
            ->orderBy('sequence')
            ->get()
            ->map(fn (EnregistrementFiscal $evenement): array => $evenement->contenuSigne()['donnees']['details'])
            ->filter(fn (array $details): bool => $details['parametre'] === 'territoire')
            ->values()
            ->all());
    }

    /**
     * Les titres des avis reçus par le propriétaire de la société, dans
     * l'ordre.
     *
     * @return list<string>
     */
    private function titresDesAvis(Societe $societe): array
    {
        return $societe->proprietaire->notifications()->oldest()->get()->map(fn ($avis): string => $avis->data['title'])->all();
    }

    private function corrigerLeTerritoire(Magasin $magasin): PendingCommand
    {
        $this->declarerLOperateurAlice();

        return $this->artisan('editeur:corriger-territoire', [
            'societe' => (string) $magasin->societe_id,
            'magasin' => $magasin->code,
            '--justification' => 'Commune de l\'établissement corrigée au répertoire Sirene',
            '--piece' => 'SIRENE-2026-0007',
            '--confirmer' => $magasin->societe->siren,
        ])
            ->expectsQuestion('Nom de l\'opérateur', 'alice')
            ->expectsQuestion('Secret de l\'opérateur', 'secret-d-alice');
    }

    /**
     * Revérifie la société du magasin (app:reverifier-sirene --toutes) et
     * rend ce que dit l'alerte du territoire lu ailleurs : le texte de
     * l'avis au propriétaire et la suite donnée à l'éditeur, la dernière
     * ligne de son alerte.
     *
     * @return array{0: string, 1: string}
     */
    private function suiteDeLAlerteDuTerritoire(Magasin $magasin): array
    {
        $proprietaire = $this->proprietaireDe($magasin);
        $proprietaire->notifications()->delete();

        $this->artisan('app:reverifier-sirene', ['--toutes' => true])->assertSuccessful();

        $avis = $proprietaire->notifications()->get()->filter(fn ($notification): bool => $notification->data['title'] === 'Territoire de votre magasin au répertoire Sirene')->sole();
        $alerte = Mail::sent(AlerteEditeurMail::class, fn (AlerteEditeurMail $mail): bool => $mail->objet === 'Revérification Sirene : établissement situé dans un autre territoire')->last();

        return [$avis->data['body'], $alerte->lignes[array_key_last($alerte->lignes)]];
    }

    /**
     * La libération du SIRET du magasin par l'éditeur, désigné par son code
     * dans sa société, ou dans $societe ; l'opérateur alice s'authentifie.
     */
    private function libererLeSiret(Magasin $magasin, ?Societe $societe = null): PendingCommand
    {
        $societe ??= $magasin->societe;
        $this->declarerLOperateurAlice();

        return $this->artisan('editeur:liberer-siret', [
            'societe' => (string) $societe->getKey(),
            'magasin' => $magasin->code,
            '--justification' => 'Commune de l\'établissement corrigée au répertoire Sirene après son premier document',
            '--piece' => 'SIRENE-2026-0008',
            '--confirmer' => $societe->siren,
        ])
            ->expectsQuestion('Nom de l\'opérateur', 'alice')
            ->expectsQuestion('Secret de l\'opérateur', 'secret-d-alice');
    }

    /**
     * L'opérateur alice, déclaré pour les procédures de l'éditeur, avec son
     * secret haché à bas coût.
     */
    private function declarerLOperateurAlice(): void
    {
        config(['editeur.operateurs' => ['alice' => password_hash('secret-d-alice', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1])]]);
    }

    private function repertoire(): FauxRepertoireSirene
    {
        $repertoire = app(RepertoireSirene::class);
        $this->assertInstanceOf(FauxRepertoireSirene::class, $repertoire);

        return $repertoire;
    }
}
