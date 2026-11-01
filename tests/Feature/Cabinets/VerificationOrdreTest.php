<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Cabinets\Actions\VerifierLOrdreAction;
use App\Domain\Cabinets\Enums\VoieDeVerificationOrdre;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Mail\AlerteEditeurMail;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Cabinet\Pages\VerificationDuCabinet;
use App\Integrations\Ordre\AnnuaireTenuParLEditeur;
use App\Integrations\Ordre\Contracts\AnnuaireDeLOrdre;
use App\Integrations\Ordre\FauxAnnuaireDeLOrdre;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use Database\Factories\SocieteFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La vérification de l'inscription d'un cabinet d'expertise comptable au
 * tableau de l'Ordre (voir VerifierLOrdreAction) : par l'annuaire, quand
 * il est automatique, au même SIREN ; sinon par l'éditeur, prévenu ; par
 * le seul propriétaire du cabinet, cinq essais par heure au plus. Tant que
 * le cabinet n'est pas validé, contrôlé 69.20Z et vérifié à l'Ordre, il
 * n'est pas un cabinet vérifié.
 */
class VerificationOrdreTest extends TestCase
{
    use RefreshDatabase;

    private const DONNEES = ['numero_ordre' => '01-23456', 'conseil_regional' => 'Île-de-France'];

    public function test_avec_l_annuaire_automatique_l_ordre_se_verifie_au_meme_siren(): void
    {
        $this->travelTo(self::aParis('2026-10-06 09:00:00'));
        $cabinet = $this->cabinetValide();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $this->dansLEspaceDuCabinet($cabinet, $proprietaire);

        Livewire::test(VerificationDuCabinet::class)
            ->assertActionVisible('verifierLOrdre')
            ->callAction('verifierLOrdre', ['numero_ordre' => '99-99999', 'conseil_regional' => 'Autre'])
            ->assertHasNoActionErrors()
            ->assertActionHidden('verifierLOrdre');

        $verification = $cabinet->cabinet()->sole();
        $this->assertSame([FauxAnnuaireDeLOrdre::NUMERO_DU_CABINET_DE_TEST, FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST, VoieDeVerificationOrdre::Annuaire], [$verification->numero_ordre, $verification->conseil_regional_ordre, $verification->ordre_voie]);
        $this->assertMemeInstant(self::aParis('2026-10-06 09:00:00'), $verification->ordre_verifie_le);
        $this->assertTrue($cabinet->fresh()->estUnCabinetVerifie());
        [$entree] = $this->entreesDAudit($cabinet, ActionAuditee::OrdreVerifie);
        $this->assertSame(['conseil_regional' => FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST, 'numero_ordre' => FauxAnnuaireDeLOrdre::NUMERO_DU_CABINET_DE_TEST, 'voie' => 'annuaire'], $entree->nouvelles_valeurs);
        $this->assertSame((int) $proprietaire->id, (int) $entree->auteur_id);
        $this->assertContains('Inscription à l\'Ordre vérifiée', $proprietaire->notifications()->get()->pluck('data.title')->all());
    }

    public function test_une_inscription_absente_ou_radiee_de_l_annuaire_est_refusee_sans_rien_verifier(): void
    {
        $inconnu = $this->cabinetValide(SocieteFactory::sirenFictif());
        $radie = $this->cabinetValide(FauxRepertoireSirene::SIREN_CABINET);
        app(AnnuaireDeLOrdre::class)->radier(FauxRepertoireSirene::SIREN_CABINET);

        foreach ([$inconnu, $radie] as $cabinet) {
            $this->assertThrows(
                fn () => app(VerifierLOrdreAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, self::DONNEES),
                VerificationRefuseeException::class,
                VerifierLOrdreAction::MESSAGE_INTROUVABLE,
            );

            $verification = $cabinet->cabinet()->sole();
            $this->assertNull($verification->ordre_verifie_le);
            $this->assertSame(VerifierLOrdreAction::MESSAGE_INTROUVABLE, $verification->ordre_motif_refus);
            $this->assertSame(['motif' => VerifierLOrdreAction::MESSAGE_INTROUVABLE], $this->entreesDAudit($cabinet, ActionAuditee::OrdreRefuse)[0]->nouvelles_valeurs);
            $this->assertFalse($cabinet->fresh()->estUnCabinetVerifie());
        }

        app(AnnuaireDeLOrdre::class)->rendreInjoignable();
        $this->assertThrows(fn () => app(VerifierLOrdreAction::class)->handle($this->proprietaireDuCabinet($inconnu), $inconnu, self::DONNEES), VerificationRefuseeException::class, 'L\'annuaire de l\'Ordre des experts-comptables ne répond pas');
        $this->assertCount(1, $this->entreesDAudit($inconnu, ActionAuditee::OrdreRefuse));
    }

    /**
     * Sans annuaire automatique (le pilote « editeur »), la demande se
     * note avec le n° et le conseil déclarés, l'éditeur est prévenu et le
     * cabinet attend sa procédure ; la page le dit.
     */
    public function test_sans_annuaire_automatique_la_demande_alerte_l_editeur_et_attend_sa_procedure(): void
    {
        Mail::fake();
        config(['editeur.email' => 'alertes@editeur.test']);
        $this->app->instance(AnnuaireDeLOrdre::class, new AnnuaireTenuParLEditeur);
        $this->travelTo(self::aParis('2026-10-06 09:00:00'));
        $cabinet = $this->cabinetValide();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $this->dansLEspaceDuCabinet($cabinet, $proprietaire);

        Livewire::test(VerificationDuCabinet::class)
            ->assertActionHasLabel('verifierLOrdre', 'Demander la vérification de mon inscription à l\'Ordre')
            ->callAction('verifierLOrdre', self::DONNEES)
            ->assertHasNoActionErrors();

        $verification = $cabinet->cabinet()->sole();
        $this->assertSame(['01-23456', 'Île-de-France', null], [$verification->numero_ordre, $verification->conseil_regional_ordre, $verification->ordre_verifie_le]);
        $this->assertMemeInstant(self::aParis('2026-10-06 09:00:00'), $verification->ordre_demande_le);
        $this->assertFalse($cabinet->fresh()->estUnCabinetVerifie());
        $this->assertSame(['conseil_regional' => 'Île-de-France', 'numero_ordre' => '01-23456'], $this->entreesDAudit($cabinet, ActionAuditee::OrdreDemande)[0]->nouvelles_valeurs);
        Mail::assertSent(AlerteEditeurMail::class, fn (AlerteEditeurMail $mail): bool => $mail->hasTo('alertes@editeur.test') && str_contains(implode(' ', $mail->lignes), '01-23456'));
        $this->get(VerificationDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet))
            ->assertOk()
            ->assertSeeText('Demandée le 06/10/2026 : l\'éditeur vérifie votre inscription au tableau de l\'Ordre et vous prévient.');
    }

    /**
     * Seul le propriétaire du cabinet fait vérifier son inscription : ni
     * le propriétaire d'une autre société, par une action forgée, ni un
     * propriétaire suspendu ; une inscription vérifiée ne se redemande pas.
     */
    public function test_seul_le_proprietaire_du_cabinet_demande_la_verification_de_l_ordre(): void
    {
        $cabinet = $this->cabinetValide();
        $autre = $this->proprietaireDe($this->ouvrirUneSociete());
        app(TenantContext::class)->clear();

        $this->assertThrows(fn () => app(VerifierLOrdreAction::class)->handle($autre, $cabinet, self::DONNEES), AuthorizationException::class, VerifierLOrdreAction::MESSAGE_PROPRIETAIRE_SEUL);

        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $proprietaire->forceFill(['statut' => StatutCompte::Suspendu])->save();
        $this->assertThrows(fn () => app(VerifierLOrdreAction::class)->handle($proprietaire, $cabinet, self::DONNEES), AuthorizationException::class);
        $this->assertNull($cabinet->cabinet()->sole()->ordre_verifie_le);

        $proprietaire->forceFill(['statut' => StatutCompte::Actif])->save();
        app(VerifierLOrdreAction::class)->handle($proprietaire, $cabinet, self::DONNEES);
        $this->assertThrows(fn () => app(VerifierLOrdreAction::class)->handle($proprietaire, $cabinet, self::DONNEES), AuthorizationException::class);
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::OrdreVerifie));
    }

    /**
     * Un cabinet n'est vérifié que validé par le code de son courrier, son
     * code d'activité contrôlé et son inscription à l'Ordre vérifiée, et
     * actif.
     */
    public function test_tant_que_le_cabinet_n_est_pas_verifie_il_n_invite_ni_ne_lie_aucun_client(): void
    {
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        $sansCode = $this->inscrireUneSociete(FauxRepertoireSirene::SIREN_CABINET, type: TypeDActivite::Cabinet);
        $siren = SocieteFactory::sirenFictif();
        app(RepertoireSirene::class)->exercer($siren, null);
        app(AnnuaireDeLOrdre::class)->inscrire($siren, '75-00001', 'Île-de-France');
        $sansActivite = $this->inscrireUneSociete($siren, type: TypeDActivite::Cabinet);
        app(VerifierLOrdreAction::class)->handle($this->saisirLeCodeDuCourrier($sansActivite), $sansActivite->fresh(), self::DONNEES);
        $sansOrdre = $this->cabinetValide(SocieteFactory::sirenFictif());
        $verifie = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif());

        $this->assertSame([false, false, false, true], [
            $sansCode->fresh()->estUnCabinetVerifie(),
            $sansActivite->fresh()->estUnCabinetVerifie(),
            $sansOrdre->fresh()->estUnCabinetVerifie(),
            $verifie->fresh()->estUnCabinetVerifie(),
        ]);
        $this->assertNotNull($sansActivite->cabinet()->sole()->ordre_verifie_le);

        $verifie->forceFill(['statut' => 'suspendue'])->save();
        $this->assertFalse($verifie->fresh()->estUnCabinetVerifie());
        $this->assertFalse($this->ouvrirUneSociete()->societe->estUnCabinetVerifie());
    }

    public function test_les_essais_automatiques_sont_limites_a_cinq_par_heure(): void
    {
        $this->travelTo(self::aParis('2026-10-06 09:00:00'));
        $cabinet = $this->cabinetValide(SocieteFactory::sirenFictif());
        $proprietaire = $this->proprietaireDuCabinet($cabinet);

        for ($essai = 1; $essai <= VerifierLOrdreAction::ESSAIS_PAR_HEURE; $essai++) {
            $this->assertThrows(fn () => app(VerifierLOrdreAction::class)->handle($proprietaire, $cabinet, self::DONNEES), VerificationRefuseeException::class, VerifierLOrdreAction::MESSAGE_INTROUVABLE);
        }

        app(AnnuaireDeLOrdre::class)->inscrire($cabinet->siren, '75-00002', 'Bretagne');
        $this->assertThrows(fn () => app(VerifierLOrdreAction::class)->handle($proprietaire, $cabinet, self::DONNEES), VerificationRefuseeException::class, VerifierLOrdreAction::MESSAGE_TROP_D_ESSAIS);
        $this->assertCount(VerifierLOrdreAction::ESSAIS_PAR_HEURE, $this->entreesDAudit($cabinet, ActionAuditee::OrdreRefuse));

        $this->travelTo(self::aParis('2026-10-06 10:00:01'));
        app(VerifierLOrdreAction::class)->handle($proprietaire, $cabinet, self::DONNEES);
        $this->assertTrue($cabinet->fresh()->estUnCabinetVerifie());
    }

    /**
     * Un cabinet inscrit sous ce SIREN (le cabinet de test du répertoire
     * Sirene factice à défaut), d'activité 69.20Z, validé par le code de son
     * courrier, dont l'inscription à l'Ordre n'est pas encore vérifiée.
     * Aucun contexte n'est actif ensuite.
     */
    private function cabinetValide(string $siren = FauxRepertoireSirene::SIREN_CABINET): Societe
    {
        app(RepertoireSirene::class)->exercer($siren, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        $cabinet = $this->inscrireUneSociete($siren, type: TypeDActivite::Cabinet);
        $this->saisirLeCodeDuCourrier($cabinet);
        app(TenantContext::class)->clear();

        return $cabinet->fresh();
    }
}
