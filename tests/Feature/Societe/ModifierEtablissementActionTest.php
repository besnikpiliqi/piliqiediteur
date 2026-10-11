<?php

namespace Tests\Feature\Societe;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Tenancy\Actions\ModifierEtablissementAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Rules\CodeMagasinLibre;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La modification d'un magasin de la société (voir
 * ModifierEtablissementAction) : son nom, son adresse sans changer de
 * territoire de TVA, son code tant qu'il n'a numéroté aucun document, son
 * NIC tant que la société n'est pas validée. Les garanties de l'ancienne
 * identité du magasin s'y retrouvent, en attendant l'écran des magasins
 * de l'administration de la société.
 */
class ModifierEtablissementActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le magasin réenregistre son propre SIRET sans se le voir refuser.
     */
    public function test_le_magasin_reenregistre_son_propre_siret(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);

        $modifie = $this->modifier($magasin, ['nom' => 'Ma Boutique de Lyon', 'nic' => substr($magasin->siret, 9)]);

        $this->assertSame(['Ma Boutique de Lyon', $magasin->siret], [$modifie->nom, $modifie->siret]);
    }

    public function test_le_code_saisi_en_minuscules_s_enregistre_en_majuscules(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $this->modifier($magasin, ['code' => 'lyon1']);

        $this->assertSame('LYON1', $magasin->fresh()->code);
    }

    public function test_le_pays_saisi_en_minuscules_s_enregistre_en_majuscules(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $this->modifier($magasin, ['pays' => 'fr', 'code_postal' => '13002', 'ville' => 'Marseille']);

        $this->assertSame(['FR', '13002'], [$magasin->fresh()->pays, $magasin->fresh()->code_postal]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function codesRefuses(): array
    {
        return [
            'code d\'une lettre' => ['L', 'Le code compte 2 à 10 lettres ou chiffres, sans espace ni tiret.'],
            'code avec un tiret' => ['LYON-1', 'Le code compte 2 à 10 lettres ou chiffres, sans espace ni tiret.'],
            'code d\'un autre magasin de la société' => ['LYON1', CodeMagasinLibre::MESSAGE_DEJA_UTILISE],
            'code d\'un magasin fermé de la société' => ['ANCIEN', CodeMagasinLibre::MESSAGE_DEJA_UTILISE],
        ];
    }

    /**
     * Un code n'appartient qu'à un seul magasin de la société, même fermé ;
     * refusé, il n'est pas enregistré.
     */
    #[DataProvider('codesRefuses')]
    public function test_un_code_refuse_n_est_pas_enregistre(string $code, string $message): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Lyon', 'code' => 'LYON1']);
        $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Ancien', 'code' => 'ANCIEN'])->forceFill(['statut' => StatutMagasin::Ferme])->save();

        $this->assertThrows(
            fn () => $this->modifier($magasin, ['code' => $code]),
            fn (ValidationException $refus): bool => in_array($message, $refus->errors()['code'] ?? [], true),
        );

        $this->assertSame('MABOUT', $magasin->fresh()->code);
    }

    /**
     * Dès le premier document numéroté, le code figure dans les numéros :
     * le modèle refuse tout changement.
     */
    public function test_le_code_ne_change_plus_apres_un_premier_devis_numerote(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->numeroterUnPremierDevis();

        $this->assertThrows(fn () => $magasin->fresh()->update(['code' => 'AUTRE']), LogicException::class, 'Le code du magasin figure déjà dans les numéros de ses documents : il ne change plus.');

        $this->assertSame('MABOUT', $magasin->fresh()->code);
        $this->assertTrue($magasin->fresh()->aNumeroteUnDocument());
    }

    /**
     * Une référence de produit (PRD-000001) ne porte pas le code : le
     * premier produit ne le rend pas définitif.
     */
    public function test_un_premier_produit_ne_rend_pas_le_code_definitif(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        Produit::factory()->create(['reference' => null]);

        $this->modifier($magasin, ['code' => 'LYON1']);

        $this->assertSame('LYON1', $magasin->fresh()->code);
    }

    /**
     * Un premier document numéroté pendant que l'écran est ouvert : le
     * code saisi n'est pas enregistré, le reste l'est.
     */
    public function test_un_code_devenu_definitif_pendant_la_saisie_n_est_pas_enregistre(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->numeroterUnPremierDevis();

        $this->modifier($magasin, ['code' => 'AUTRE', 'nom' => 'Ma Boutique de Lyon']);

        $this->assertSame(['MABOUT', 'Ma Boutique de Lyon'], [$magasin->fresh()->code, $magasin->fresh()->nom]);
    }

    /**
     * Un magasin qui n'a encore rien vendu ne numérote aucun document la
     * nuit, ni clôture de caisse ni déclaration d'e-reporting : son code
     * reste modifiable jusqu'à son premier document.
     */
    public function test_un_magasin_sans_vente_garde_son_code_modifiable_apres_les_commandes_de_la_nuit(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'regime_tva' => RegimeTva::ReelSimplifie]);
        $this->travelTo('2026-10-10 01:00:00');
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();
        $this->artisan('app:soumettre-ereporting-periodique')->assertSuccessful();

        $this->modifier($magasin, ['code' => 'LYON1']);

        $this->assertSame('LYON1', $magasin->fresh()->code);
    }

    /**
     * Seuls les documents du magasin rendent son code définitif : le
     * premier document d'un autre magasin de la société ne le verrouille
     * pas.
     */
    public function test_le_premier_document_d_un_autre_magasin_ne_rend_pas_le_code_definitif(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre boutique']);
        app(TenantContext::class)->runAs($autre, fn () => $this->numeroterUnPremierDevis());

        $this->modifier($magasin, ['code' => 'LYON3']);

        $this->assertSame('LYON3', $magasin->fresh()->code);
        $this->assertTrue($autre->fresh()->aNumeroteUnDocument());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function valeursPrisesPendantLEnregistrement(): array
    {
        return [
            'code' => ['code'],
            'SIRET' => ['nic'],
        ];
    }

    /**
     * Un code ou un SIRET, libre à la validation, pris par un autre magasin
     * de la société juste avant l'enregistrement : refusé comme par la
     * validation, sans erreur du serveur, et rien n'est enregistré.
     */
    #[DataProvider('valeursPrisesPendantLEnregistrement')]
    public function test_une_valeur_prise_pendant_l_enregistrement_est_refusee_comme_par_la_validation(string $champ): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);
        $siret = IdentifiantsInsee::avecSaCle($magasin->societe->siren.'0005');
        $saisie = ['code' => ['code' => 'LYON1'], 'nic' => ['nic' => substr($siret, 9)]][$champ];
        $concurrentEnCours = false;
        Magasin::updating(function () use ($magasin, $siret, $saisie, &$concurrentEnCours): void {
            if (! $concurrentEnCours) {
                $concurrentEnCours = true;
                Magasin::factory()->for($magasin->societe)->create(isset($saisie['code']) ? ['code' => 'LYON1'] : ['siret' => $siret]);
            }
        });

        $this->assertThrows(
            fn () => $this->modifier($magasin, [...$saisie, 'nom' => 'Ma Boutique de Lyon']),
            fn (ValidationException $refus): bool => $refus->errors() === [$champ => [$champ === 'code' ? CodeMagasinLibre::MESSAGE_DEJA_UTILISE : SiretsDesMagasins::MESSAGE_SIRET_RESERVE]],
        );

        $this->assertSame(['MABOUT', 'Ma Boutique'], [$magasin->fresh()->code, $magasin->fresh()->nom]);
    }

    /**
     * Un doublon qui n'est ni celui du code ni celui du SIRET n'est
     * attribué à aucun champ : le refus ne dit pas à tort « Ce code est
     * déjà utilisé », et rien n'est enregistré.
     */
    public function test_un_autre_doublon_n_est_pas_attribue_au_code(): void
    {
        $autre = Magasin::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        Magasin::updating(fn (Magasin $enregistre) => $enregistre->forceFill(['uuid' => $autre->uuid]));

        $this->assertThrows(fn () => $this->modifier($magasin, ['nom' => 'Ma Boutique de Lyon']), UniqueConstraintViolationException::class);

        $this->assertSame('Ma Boutique', $magasin->fresh()->nom);
    }

    /**
     * Une adresse qui change le territoire de TVA du magasin (son taux par
     * défaut et ses règles de vente) est refusée : un tel déménagement
     * passe par un nouveau magasin.
     */
    public function test_un_changement_d_adresse_qui_change_le_territoire_de_tva_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $this->assertThrows(
            fn () => $this->modifier($magasin, ['code_postal' => '97200', 'ville' => 'Fort-de-France']),
            fn (ValidationException $refus): bool => $refus->errors() === ['code_postal' => [ModifierEtablissementAction::MESSAGE_TERRITOIRE_CHANGE]],
        );

        $this->assertSame('75001', $magasin->fresh()->code_postal);
    }

    /**
     * Le NIC se corrige tant que la société n'est pas validée, contrôlé au
     * répertoire Sirene, et le changement de SIRET s'inscrit au journal des
     * événements du magasin ; une fois la société validée, le SIRET ne
     * change plus ainsi.
     */
    public function test_le_nic_se_corrige_avant_la_validation_seulement(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);
        $ancien = $magasin->siret;
        $nic = substr(IdentifiantsInsee::avecSaCle($magasin->societe->siren.'0005'), 9);

        $this->modifier($magasin, ['nic' => $nic]);

        $this->assertSame($magasin->societe->siren.$nic, $magasin->fresh()->siret);
        $this->assertSame(
            [['parametre' => 'siret', 'magasin' => ['id' => $magasin->id, 'nom' => 'Ma Boutique'], 'ancienne_valeur' => $ancien, 'nouvelle_valeur' => $magasin->societe->siren.$nic]],
            app(TenantContext::class)->runAs($magasin, fn (): array => EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->where('type', '270')->get()->map(fn (EnregistrementFiscal $evenement): array => $evenement->contenuSigne()['donnees']['details'])->all()),
        );
        $magasin->societe->forceFill(['validee_le' => now()])->save();
        $this->assertThrows(
            fn () => $this->modifier($magasin->fresh(), ['nic' => substr($ancien, 9)]),
            LogicException::class,
            ModifierEtablissementAction::MESSAGE_SIRET_SOCIETE_VALIDEE,
        );
    }

    /**
     * Ce qui change, et seulement cela, s'inscrit au journal d'audit, avec
     * l'ancienne et la nouvelle valeur.
     */
    public function test_la_modification_s_inscrit_au_journal_d_audit(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $this->modifier($magasin, ['nom' => 'Ma Boutique de Lyon', 'code' => 'MABOUT']);

        $entree = app(TenantContext::class)->runAsSociete($magasin->societe, fn (): EntreeAudit => EntreeAudit::query()->where('action', ActionAuditee::MagasinModifie->value)->sole());
        $this->assertSame([['nom' => 'Ma Boutique'], ['nom' => 'Ma Boutique de Lyon'], $magasin->id], [$entree->anciennes_valeurs, $entree->nouvelles_valeurs, (int) $entree->magasin_id]);
    }

    /**
     * Le magasin que modifie le propriétaire de sa société.
     *
     * @param  array<string, mixed>  $donnees
     */
    private function modifier(Magasin $magasin, array $donnees): Magasin
    {
        $proprietaire = $this->proprietaireDe($magasin);
        $contexte = app(TenantContext::class);
        $avant = $contexte->has() ? $contexte->magasin() : null;
        $contexte->clear();

        try {
            return app(ModifierEtablissementAction::class)->handle($proprietaire, $magasin->fresh(), $donnees);
        } finally {
            if ($avant !== null) {
                $contexte->set($avant);
            }
        }
    }

    private function numeroterUnPremierDevis(): void
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);
        $devis = app(CreerDevisAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);

        app(EmettreDevisAction::class)->handle($devis);
    }
}
