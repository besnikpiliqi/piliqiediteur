<?php

namespace Tests\Feature\Editeur;

use App\Domain\Tenancy\Actions\ChangerSiretAction;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\LibererUnSiretSaisiParErreurAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
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
 * courrier de libération saisi, et le magasin fautif passé à son bon
 * SIRET ou fermé pour cessation ; le SIRET sert ensuite au magasin qui
 * doit le porter, et le magasin fautif garde ses documents.
 */
class LibererSiretTest extends TestCase
{
    use RefreshDatabase;

    private const JUSTIFICATION = 'NIC de l\'entrepôt saisi à la place de celui de la boutique';

    private const PIECE = 'DOSSIER-2026-0093';

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

        $this->prouverLaRemiseDuCourrier($societe);
        app(LibererUnSiretSaisiParErreurAction::class)->handle($societe->fresh(), $siretErrone, $this->editeur());

        $this->assertFalse(SiretsDesMagasins::estReserve($siretErrone));
        [$entree] = $this->entreesDAudit($societe, ActionAuditee::SiretLibere);
        $this->assertSame(['editeur', ['motif' => LibererUnSiretSaisiParErreurAction::MOTIF, 'siret' => $siretErrone]], [$entree->origine, $entree->nouvelles_valeurs]);
        $entrepot = $this->creerLeMagasinSur($societe, $siretErrone);
        $this->assertSame($siretErrone, (string) $entrepot->siret);
        $this->assertTrue(SiretsDesMagasins::estReserve($siretErrone));
        $this->assertSame($bonSiret, (string) $boutique->fresh()->siret);
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
        $this->prouverLaRemiseDuCourrier($societe);
        $this->assertThrows(fn () => app(LibererUnSiretSaisiParErreurAction::class)->handle($societe->fresh(), $siret, new InterventionDeLEditeur(self::JUSTIFICATION, self::PIECE, ['alice'])), VerificationRefuseeException::class, LibererUnSiretSaisiParErreurAction::MESSAGE_DEUX_OPERATEURS);
        $this->assertThrows(fn () => app(LibererUnSiretSaisiParErreurAction::class)->handle($societe->fresh(), $siret, $this->editeur()), LogicException::class, LibererUnSiretSaisiParErreurAction::MESSAGE_ENCORE_UTILISE);
        $this->assertThrows(fn () => app(LibererUnSiretSaisiParErreurAction::class)->handle($societe->fresh(), IdentifiantsInsee::avecSaCle($societe->siren.'00099'), $this->editeur()), LogicException::class, LibererUnSiretSaisiParErreurAction::MESSAGE_PAS_RESERVE);

        $this->assertTrue(SiretsDesMagasins::estReserve($siret));
        $this->assertSame([], $this->entreesDAudit($societe, ActionAuditee::SiretLibere));
    }

    private function editeur(): InterventionDeLEditeur
    {
        return new InterventionDeLEditeur(self::JUSTIFICATION, self::PIECE, ['alice', 'bruno']);
    }

    /**
     * L'éditeur envoie le courrier de libération au représentant légal, à
     * l'adresse officielle du siège, et son code est saisi sur sa page.
     */
    private function prouverLaRemiseDuCourrier(Societe $societe): void
    {
        app(TenantContext::class)->clear();
        app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->fresh(), MotifCode::LiberationSiret, new InterventionDeLEditeur(self::JUSTIFICATION, self::PIECE, ['alice']));
        app(SaisirCodeDeConfirmationAction::class)->saisirUnCodeDeProcedure((string) $societe->siren, MotifCode::LiberationSiret, $this->codeDuCourrier($societe, MotifCode::LiberationSiret), '127.0.0.1');
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
