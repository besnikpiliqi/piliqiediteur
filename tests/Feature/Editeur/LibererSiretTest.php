<?php

namespace Tests\Feature\Editeur;

use App\Domain\Tenancy\Actions\ChangerSiretAction;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\LibererUnSiretSaisiParErreurAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\RegistreDesOperateurs;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/**
 * La libération par l'éditeur d'un SIRET saisi par erreur (voir
 * LibererUnSiretSaisiParErreurAction) : deux opérateurs, le code du
 * courrier de libération de ce SIRET saisi depuis moins de trente jours,
 * qui ne sert qu'une fois, et le magasin fautif passé à son bon SIRET, ou
 * fermé pour cessation ou cédé ; le SIRET sert ensuite au magasin qui doit
 * le porter, le magasin fautif garde ses documents, et la société son seul
 * propriétaire actif. Le temps est figé au 2 novembre 2026, à Paris, sauf
 * où un test le fait avancer.
 */
class LibererSiretTest extends TestCase
{
    use RefreshDatabase;

    private const JUSTIFICATION = 'NIC de l\'entrepôt saisi à la place de celui de la boutique';

    private const PIECE = 'DOSSIER-2026-0093';

    protected function setUp(): void
    {
        parent::setUp();

        config(['editeur.operateurs' => [
            'alice' => password_hash('secret-d-alice', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]),
            'bruno' => password_hash('secret-de-bruno', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]),
        ]]);
        $this->travelTo(self::aParis('2026-11-02 10:00:00'));
    }

    /**
     * La société a créé sa boutique sur le NIC de son entrepôt par erreur,
     * puis lui a donné son bon SIRET : l'ancien reste réservé et refuse le
     * magasin de l'entrepôt ; libéré par l'éditeur, il le sert.
     */
    public function test_un_siret_saisi_par_erreur_libere_sert_au_magasin_qui_doit_le_porter(): void
    {
        $boutique = $this->ouvrirUneSociete(['nom' => 'Boutique']);
        $societe = $boutique->societe;
        $siretErrone = (string) $boutique->siret;
        $bonSiret = IdentifiantsInsee::avecSaCle($societe->siren.'0007');
        app(ChangerSiretAction::class)->handle($this->proprietaireDe($boutique), $boutique->fresh(), substr($bonSiret, 9), 'password');
        $this->assertThrows(
            fn () => $this->creerLeMagasinSur($societe, $siretErrone),
            fn (ValidationException $refus): bool => $refus->errors() === ['nic' => [SiretsDesMagasins::MESSAGE_SIRET_RESERVE]],
        );

        $this->prouverLaRemiseDuCourrier($societe, $siretErrone);
        $signatures = $this->signaturesDuRegistre($boutique);
        app(LibererUnSiretSaisiParErreurAction::class)->handle($societe->fresh(), $siretErrone, $this->editeur());

        $this->assertFalse(SiretsDesMagasins::estReserve($siretErrone));
        [$entree] = $this->entreesDAudit($societe, ActionAuditee::SiretLibere);
        $this->assertSame(['editeur', ['motif' => LibererUnSiretSaisiParErreurAction::MOTIF, 'siret' => $siretErrone]], [$entree->origine, $entree->nouvelles_valeurs]);
        $this->assertSame($signatures, $this->signaturesDuRegistre($boutique));
        $this->assertUnSeulProprietaireActif($societe);
        $entrepot = $this->creerLeMagasinSur($societe, $siretErrone);
        $this->assertSame($siretErrone, (string) $entrepot->siret);
        $this->assertTrue(SiretsDesMagasins::estReserve($siretErrone));
        $this->assertSame($bonSiret, (string) $boutique->fresh()->siret);
    }

    /**
     * Un code de libération ne prouve que la libération du SIRET que son
     * courrier désigne, et une seule : libérer un autre SIRET de la société,
     * ou le même une seconde fois, exige un nouveau courrier ; un code saisi
     * il y a plus de trente jours ne prouve plus rien.
     */
    public function test_un_code_de_liberation_ne_vaut_que_pour_son_siret_une_fois_et_trente_jours(): void
    {
        $boutique = $this->ouvrirUneSociete(['nom' => 'Boutique']);
        $societe = $boutique->societe;
        $premier = (string) $boutique->siret;
        $second = IdentifiantsInsee::avecSaCle($societe->siren.'0007');
        app(ChangerSiretAction::class)->handle($this->proprietaireDe($boutique), $boutique->fresh(), substr($second, 9), 'password');
        app(ChangerSiretAction::class)->handle($this->proprietaireDe($boutique), $boutique->fresh(), substr(IdentifiantsInsee::avecSaCle($societe->siren.'0008'), 9), 'password');
        $this->prouverLaRemiseDuCourrier($societe, $premier);

        $this->assertThrows(fn () => app(LibererUnSiretSaisiParErreurAction::class)->handle($societe->fresh(), $second, $this->editeur()), VerificationRefuseeException::class, LibererUnSiretSaisiParErreurAction::MESSAGE_SANS_CODE);
        app(LibererUnSiretSaisiParErreurAction::class)->handle($societe->fresh(), $premier, $this->editeur());
        $this->travelTo(self::aParis('2026-11-03 10:00:00'));
        $this->assertThrows(fn () => app(LibererUnSiretSaisiParErreurAction::class)->handle($societe->fresh(), $second, $this->editeur()), VerificationRefuseeException::class, LibererUnSiretSaisiParErreurAction::MESSAGE_SANS_CODE);
        $this->prouverLaRemiseDuCourrier($societe, $second);
        $this->travelTo(self::aParis('2026-12-03 10:00:01'));
        $this->assertThrows(fn () => app(LibererUnSiretSaisiParErreurAction::class)->handle($societe->fresh(), $second, $this->editeur()), VerificationRefuseeException::class, LibererUnSiretSaisiParErreurAction::MESSAGE_SANS_CODE);

        $this->assertFalse(SiretsDesMagasins::estReserve($premier));
        $this->assertTrue(SiretsDesMagasins::estReserve($second));
        $this->assertCount(1, $this->entreesDAudit($societe, ActionAuditee::SiretLibere));
    }

    /**
     * Le SIRET d'un magasin cédé, qui ne se rouvre jamais, se libère comme
     * celui d'un magasin fermé pour cessation : le magasin garde ses
     * documents et ne reprend plus ce SIRET.
     */
    public function test_le_siret_d_un_magasin_cede_se_libere(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique']);
        $societe = $magasin->societe;
        $siret = (string) $magasin->siret;
        $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre boutique']);
        app(TenantContext::class)->clear();
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Cession, 'password', $magasin->nom);
        $this->prouverLaRemiseDuCourrier($societe, $siret);

        app(LibererUnSiretSaisiParErreurAction::class)->handle($societe->fresh(), $siret, $this->editeur());

        $this->assertFalse(SiretsDesMagasins::estReserve($siret));
        $this->assertTrue($magasin->fresh()->aEteCede());
        $this->assertNotNull($magasin->fresh()->siret_libere_le);
    }

    /**
     * Par la commande de deux opérateurs distincts, le SIRET se libère ;
     * sans la confirmation retapée, en mode non interactif, avec un
     * opérateur inconnu ou le même opérateur deux fois, rien ne se libère.
     */
    public function test_la_commande_libere_le_siret_et_refuse_sans_confirmation_ni_deux_operateurs_authentifies(): void
    {
        $boutique = $this->ouvrirUneSociete(['nom' => 'Boutique']);
        $societe = $boutique->societe;
        $siretErrone = (string) $boutique->siret;
        app(ChangerSiretAction::class)->handle($this->proprietaireDe($boutique), $boutique->fresh(), substr(IdentifiantsInsee::avecSaCle($societe->siren.'0007'), 9), 'password');
        $this->prouverLaRemiseDuCourrier($societe, $siretErrone);
        $parametres = ['siret' => $siretErrone, '--justification' => self::JUSTIFICATION, '--piece' => self::PIECE, '--confirmer' => $societe->siren];

        $this->lancerUneProcedureDeLEditeur('editeur:siret:liberer', $societe, [...$parametres, '--confirmer' => '000000018'], [])
            ->expectsOutputToContain('Confirmation absente ou différente : rien n\'est fait.')
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:siret:liberer', $societe, [...$parametres, '--confirmer' => null, '--no-interaction' => true], [])
            ->expectsOutputToContain('Confirmation absente ou différente : rien n\'est fait.')
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:siret:liberer', $societe, [...$parametres, '--no-interaction' => true], [])
            ->expectsOutputToContain('ne se lance que dans un terminal interactif')
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:siret:liberer', $societe, $parametres, [['mallory', 'secret-de-mallory']], 2)
            ->expectsOutputToContain(RegistreDesOperateurs::MESSAGE_REFUS)
            ->assertFailed();
        $this->lancerUneProcedureDeLEditeur('editeur:siret:liberer', $societe, $parametres, [['bruno', 'secret-de-bruno'], ['bruno', 'secret-de-bruno']], 2)
            ->expectsOutputToContain('Cette procédure exige deux opérateurs distincts : rien n\'est fait.')
            ->assertFailed();
        $this->assertTrue(SiretsDesMagasins::estReserve($siretErrone));
        $this->assertSame([], $this->entreesDAudit($societe, ActionAuditee::SiretLibere));

        $this->lancerUneProcedureDeLEditeur('editeur:siret:liberer', $societe, $parametres, [['alice', 'secret-d-alice'], ['bruno', 'secret-de-bruno']], 2)
            ->expectsOutputToContain("SIRET {$siretErrone} libéré (magasin {$boutique->code}) : il peut se réserver pour le magasin qui doit le porter.")
            ->assertSuccessful();

        $this->assertFalse(SiretsDesMagasins::estReserve($siretErrone));
        [$entree] = $this->entreesDAudit($societe, ActionAuditee::SiretLibere);
        $this->assertSame(['alice', 'bruno'], $entree->operateur['operateurs']);
        $this->assertUnSeulProprietaireActif($societe);
    }

    /**
     * Le SIRET d'un magasin ouvert ne se libère pas ; sans le code du
     * courrier, ou avec un seul opérateur, rien ne se libère non plus.
     */
    public function test_le_siret_d_un_magasin_ouvert_ne_se_libere_pas_ni_sans_le_code_ni_sans_deux_operateurs(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique']);
        $societe = $magasin->societe;
        $siret = (string) $magasin->siret;

        $this->assertThrows(fn () => app(LibererUnSiretSaisiParErreurAction::class)->handle($societe->fresh(), $siret, $this->editeur()), VerificationRefuseeException::class, LibererUnSiretSaisiParErreurAction::MESSAGE_SANS_CODE);
        $this->prouverLaRemiseDuCourrier($societe, $siret);
        $this->assertThrows(fn () => app(LibererUnSiretSaisiParErreurAction::class)->handle($societe->fresh(), $siret, new InterventionDeLEditeur(self::JUSTIFICATION, self::PIECE, ['alice'])), VerificationRefuseeException::class, LibererUnSiretSaisiParErreurAction::MESSAGE_DEUX_OPERATEURS);
        $this->assertThrows(fn () => app(LibererUnSiretSaisiParErreurAction::class)->handle($societe->fresh(), $siret, $this->editeur()), LogicException::class, LibererUnSiretSaisiParErreurAction::MESSAGE_ENCORE_UTILISE);
        $this->assertThrows(fn () => app(LibererUnSiretSaisiParErreurAction::class)->handle($societe->fresh(), IdentifiantsInsee::avecSaCle($societe->siren.'00099'), $this->editeur()), LogicException::class, LibererUnSiretSaisiParErreurAction::MESSAGE_PAS_RESERVE);

        $this->assertTrue(SiretsDesMagasins::estReserve($siret));
        $this->assertSame([], $this->entreesDAudit($societe, ActionAuditee::SiretLibere));
    }

    /**
     * Le courrier de libération désigne son SIRET, que l'opérateur retape :
     * il part au titulaire de ce SIRET, que le résumé nomme, et seulement
     * pour un SIRET que réserve un magasin de la société ; son code se
     * retrouve par le SIREN de ce titulaire.
     */
    public function test_le_courrier_de_liberation_designe_son_siret_et_part_a_son_titulaire(): void
    {
        $boutique = $this->ouvrirUneSociete(['nom' => 'Boutique']);
        $societe = $boutique->societe;
        $siret = (string) $boutique->siret;
        $parametres = ['--motif' => 'liberation_siret', '--siret' => $siret, '--justification' => self::JUSTIFICATION, '--piece' => self::PIECE];

        $this->lancerUneProcedureDeLEditeur('editeur:courrier:envoyer', $societe, $parametres, [])
            ->expectsOutputToContain("Il part à la société elle-même (SIREN {$societe->siren}), titulaire de ce SIRET ; destinataire officiel : Jeanne DUPONT, ENTREPRISE {$societe->siren}")
            ->expectsQuestion("Retapez le SIRET à libérer {$siret} pour confirmer", (string) $societe->siren)
            ->assertFailed();
        $this->assertThrows(fn () => app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->fresh(), MotifCode::LiberationSiret, $this->editeur(), siretALiberer: IdentifiantsInsee::avecSaCle($societe->siren.'00099')), VerificationRefuseeException::class, EnvoyerCodeDeConfirmationAction::MESSAGE_SIRET_A_LIBERER);
        $this->assertThrows(fn () => app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->fresh(), MotifCode::LiberationSiret, $this->editeur()), VerificationRefuseeException::class, EnvoyerCodeDeConfirmationAction::MESSAGE_SIRET_A_LIBERER);

        $this->lancerUneProcedureDeLEditeur('editeur:courrier:envoyer', $societe, [...$parametres, '--confirmer' => $siret], [['alice', 'secret-d-alice']])
            ->expectsOutputToContain('Courrier parti')
            ->assertSuccessful();

        $courrier = app(TenantContext::class)->runAsSociete($societe, fn (): CodeConfirmation => CodeConfirmation::query()->where('motif', MotifCode::LiberationSiret->value)->sole());
        $this->assertSame([(string) $societe->siren, $siret], [$courrier->siren, $courrier->siret]);
    }

    private function editeur(): InterventionDeLEditeur
    {
        return new InterventionDeLEditeur(self::JUSTIFICATION, self::PIECE, ['alice', 'bruno']);
    }

    /**
     * L'éditeur envoie le courrier de libération de ce SIRET au
     * représentant légal de son titulaire, ici la société elle-même, à
     * l'adresse officielle de son siège, et son code est saisi sur sa page.
     */
    private function prouverLaRemiseDuCourrier(Societe $societe, string $siret): void
    {
        app(TenantContext::class)->clear();
        app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->fresh(), MotifCode::LiberationSiret, new InterventionDeLEditeur(self::JUSTIFICATION, self::PIECE, ['alice']), siretALiberer: $siret);
        app(SaisirCodeDeConfirmationAction::class)->saisirUnCodeDeProcedure(substr($siret, 0, 9), MotifCode::LiberationSiret, $this->codeDuCourrier($societe, MotifCode::LiberationSiret), '127.0.0.1');
        app(TenantContext::class)->clear();
    }

    /**
     * Le magasin de l'entrepôt, que le propriétaire crée sur ce SIRET.
     */
    private function creerLeMagasinSur(Societe $societe, string $siret): Magasin
    {
        app(TenantContext::class)->clear();

        return app(CreerEtablissementAction::class)->handle($societe->proprietaire()->firstOrFail(), $societe->fresh(), ['nom' => 'Entrepôt', 'code' => null, 'email' => null, 'nic' => substr($siret, 9)]);
    }
}
