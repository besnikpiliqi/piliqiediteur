<?php

namespace Tests\Feature\Societe;

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
use App\Domain\Tenancy\Actions\ModifierEtablissementAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Actions\RouvrirMagasinAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Mail\AlerteEditeurMail;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\Pages\VerificationSociete;
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
 * numéroté.
 */
class TerritoireDuMagasinTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE_DU_PROPRIETAIRE = 'password';

    private const POINTE_A_PITRE = ['97110', 'POINTE-A-PITRE', '97120'];

    private const FORT_DE_FRANCE = ['97200', 'FORT-DE-FRANCE', '97209'];

    private const SAINT_DENIS_DE_LA_REUNION = ['97400', 'SAINT-DENIS', '97411'];

    /**
     * Un établissement dont la commune est en Martinique donne au magasin
     * la Martinique, son territoire de TVA et son taux de 8,5 % ; la fiche
     * du magasin dans l'espace de la société le dit, et le journal d'audit
     * de sa création le retient.
     */
    public function test_la_creation_fixe_le_territoire_lu_a_la_commune_de_l_etablissement(): void
    {
        $magasin = $this->magasinSitue(self::FORT_DE_FRANCE);

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
        $magasin = $this->magasinSitue(self::FORT_DE_FRANCE, ['siren' => FauxRepertoireSirene::SIREN_DIFFUSION_PARTIELLE]);

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
            fn () => $this->magasinSitue(['97133', 'SAINT-BARTHELEMY', '97701']),
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

        $magasin = $this->magasinSitue(self::FORT_DE_FRANCE);
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
        $valide = $this->magasinSitue(self::POINTE_A_PITRE);
        $aVerifier = $this->magasinSitue(self::POINTE_A_PITRE, ['a_verifier' => true]);
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
        $magasin = $this->magasinSitue(self::POINTE_A_PITRE, ['a_verifier' => true]);
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
        $magasin = $this->magasinSitue(self::FORT_DE_FRANCE, ['siren' => FauxRepertoireSirene::SIREN_DIFFUSION_PARTIELLE]);
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
     * Martinique sont refusées à un magasin de la Guadeloupe, Pointe-à-Pitre
     * passe, et une adresse sans code postal aussi.
     */
    public function test_une_adresse_d_un_autre_territoire_est_refusee_meme_au_meme_taux(): void
    {
        $magasin = $this->magasinSitue(self::POINTE_A_PITRE);

        foreach (['97400', '97200'] as $codePostal) {
            $this->assertThrows(
                fn () => $this->modifier($magasin, ['code_postal' => $codePostal]),
                fn (ValidationException $refus): bool => $refus->errors() === ['code_postal' => [ModifierEtablissementAction::MESSAGE_TERRITOIRE_CHANGE]],
            );
        }

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
        $magasin = $this->magasinSitue(self::FORT_DE_FRANCE);
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
        config(['editeur.operateurs' => ['alice' => password_hash('secret-d-alice', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1])]]);
        $magasin = $this->magasinSitue(self::FORT_DE_FRANCE);
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
     * Un magasin d'une nouvelle société, validée sauf avec a_verifier,
     * créé par son propriétaire sur l'établissement de son siège, que le
     * répertoire factice situe à cette adresse (code postal, ville,
     * commune). Il devient le magasin actif.
     *
     * @param  array{0: string, 1: string, 2: string}  $adresse
     * @param  array<string, mixed>  $options  celles de ouvrirUneSociete()
     */
    private function magasinSitue(array $adresse, array $options = []): Magasin
    {
        $siren = $options['siren'] ?? SocieteFactory::sirenFictif();
        $siege = FauxRepertoireSirene::siretDuSiege($siren);
        $this->repertoire()->deplacer($siege, ...$adresse);

        return $this->ouvrirUneSociete([...$options, 'siren' => $siren, 'nic' => substr($siege, 9)]);
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

    private function repertoire(): FauxRepertoireSirene
    {
        $repertoire = app(RepertoireSirene::class);
        $this->assertInstanceOf(FauxRepertoireSirene::class, $repertoire);

        return $repertoire;
    }
}
