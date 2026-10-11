<?php

namespace Tests\Feature\Societe;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\DemanderAccordDUnDirigeantAction;
use App\Domain\Tenancy\Actions\DemarrerVerificationIdentiteAction;
use App\Domain\Tenancy\Actions\DeposerUnePieceAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\ExaminerLesPiecesAction;
use App\Domain\Tenancy\Actions\LeverUnSignalAction;
use App\Domain\Tenancy\Actions\RecevoirResultatIdentiteAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\SuivreLesEnvoisAction;
use App\Domain\Tenancy\Actions\ValiderLaSocieteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\CanalEnvoi;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\SignalDeRisque;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\TypePiece;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Jobs\EnvoyerLaLettreDInformationJob;
use App\Domain\Tenancy\Mail\AlerteEditeurMail;
use App\Domain\Tenancy\Mail\DemandeAccordDirigeantMail;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\DemandeAccordDirigeant;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\SiretMagasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\EtatDeVerification;
use App\Domain\Tenancy\Services\EvaluateurDeVerification;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\ReverificationSirene;
use App\Domain\Tenancy\Services\SignauxDeRisque;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\Societe\Pages\VerificationSociete as PageDeVerification;
use App\Http\Controllers\RappelsDesPrestatairesController;
use App\Http\Controllers\RetourVerificationIdentiteController;
use App\Integrations\EnvoiRecommande\AdressePostale;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use App\Integrations\EnvoiRecommande\FauxServiceEnvoiRecommande;
use App\Integrations\Identite\Contracts\VerificationIdentite;
use App\Integrations\Identite\FausseVerificationIdentite;
use App\Integrations\Rne\Contracts\RegistreNationalDesEntreprises;
use App\Integrations\Rne\FauxRne;
use App\Integrations\Rne\RepresentantRne;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Database\Factories\MagasinFactory;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La vérification d'une société (S6, S7, voir EvaluateurDeVerification et
 * ValiderLaSocieteAction) : elle n'est validée qu'avec son entreprise et
 * ses établissements confirmés au répertoire Sirene (A), une preuve de la
 * qualité de représentant de son propriétaire (B1 identité rapprochée
 * d'un dirigeant, B2 mandat d'un dirigeant vérifié, B4 pièces examinées
 * par l'éditeur, toujours avec le code d'un courrier recommandé) et
 * chaque signal de risque levé par ce qu'il exige ; jamais par le seul
 * code d'un courrier, jamais au détriment d'une autre inscription du même
 * SIREN. Tous les prestataires sont les implémentations factices (voir
 * VerificationServiceProvider) : aucune requête ne part.
 *
 * Les cinq premiers tests de la section 7.10 de la conception (porte de
 * validation) sont dans PorteDeValidationTest ; celui de la concurrence
 * dans ValiderLaSocieteConcurrenceTest.
 */
class VerificationSocieteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La réponse du prestataire d'identité pour un propriétaire vérifié qui
     * n'est aucun des dirigeants de l'entreprise.
     */
    private const IDENTITE_SANS_DIRIGEANT = [
        'statut' => 'verifiee',
        'nom' => 'BERNARD',
        'prenoms' => ['Luc'],
        'date_naissance' => '1990-03-03',
        'niveau_garantie' => 'substantiel',
    ];

    private const SECRET_DES_RAPPELS_D_IDENTITE = 'secret-des-rappels-d-identite-des-tests';

    private const SECRET_DES_RAPPELS_D_ENVOI = 'secret-des-rappels-d-envoi-des-tests';

    public function test_l_identite_verifiee_rapprochee_d_un_dirigeant_du_rne_valide_la_societe(): void
    {
        $societe = $this->inscrireUneSociete();
        $this->assertNull($this->etat($societe)->preuveDeQualite);
        $this->assertFalse($societe->estValidee());

        $identite = $this->verifierLIdentiteDuProprietaire($societe);

        $societe->refresh();
        $this->assertTrue($societe->estValidee());
        $this->assertSame(ResultatVerification::Concluante, $identite->resultat);
        $rapprochement = $this->lignes($societe, TypeVerification::RneRepresentants)->sole();
        $this->assertSame(
            [ResultatVerification::Concluante, $societe->proprietaire->id, [FauxRne::presidente()->libelle()], 'Présidente', '2020-01-01'],
            [$rapprochement->resultat, $rapprochement->beneficiaire_id, $rapprochement->details['chaine'], $rapprochement->details['role'], $rapprochement->details['debut_fonction']],
        );
        $this->assertNotNull($societe->proprietaire->qualite_verifiee_le);
        $this->assertSame(EtatDeVerification::PREUVE_IDENTITE_RAPPROCHEE, $this->audit($societe, ActionAuditee::SocieteValidee)->sole()->nouvelles_valeurs['preuve']);
    }

    /**
     * Le dirigeant de la filiale est une personne morale (sa holding
     * présidente) : le président de cette holding la représente, et la
     * chaîne qui mène à lui se garde avec la preuve.
     */
    public function test_le_president_de_la_holding_presidente_valide_la_filiale(): void
    {
        $siren = SocieteFactory::sirenFictif();
        $this->rne()->dirigeants($siren, [new RepresentantRne('HOLDING MARTIN', null, [], null, 'Présidente', true, '000000117', '2018-05-01')]);
        $this->rne()->dirigeants('000000117', [
            new RepresentantRne('DURAND', null, ['Sophie'], '1970-02', 'Commissaire aux comptes', false, null, '2018-05-01'),
            new RepresentantRne('MARTIN', null, ['Paul', 'Henri'], '1975-06', 'Président', true, null, '2015-01-01'),
        ]);
        $societe = $this->inscrireUneSociete($siren, 'Paul Martin');

        $this->verifierLIdentiteDuProprietaire($societe, ['statut' => 'verifiee', 'nom' => 'Martin', 'prenoms' => ['Paul', 'Henri'], 'date_naissance' => '1975-06-21', 'niveau_garantie' => 'substantiel']);

        $this->assertTrue($societe->fresh()->estValidee());
        $this->assertSame(
            ['HOLDING MARTIN (Présidente)', 'MARTIN Paul (Président) de HOLDING MARTIN'],
            $this->lignes($societe, TypeVerification::RneRepresentants)->sole()->details['chaine'],
        );
    }

    /**
     * Un propriétaire qui n'est pas dirigeant, son identité vérifiée,
     * demande l'accord de la présidente : elle ouvre le lien reçu, sans
     * compte, vérifie son identité, et le mandat approuvé prouve la
     * qualité de mandataire du propriétaire (B2).
     */
    public function test_un_mandat_approuve_par_un_dirigeant_verifie_valide_la_societe(): void
    {
        Mail::fake();
        $societe = $this->inscrireUneSociete(null, 'Luc Bernard');
        $proprietaire = $societe->proprietaire;
        $this->verifierLIdentiteDuProprietaire($societe, self::IDENTITE_SANS_DIRIGEANT);
        $this->assertFalse($societe->fresh()->estValidee());

        app(DemanderAccordDUnDirigeantAction::class)->handle($proprietaire, $societe, 0, 'jeanne.dupont@example.test');
        $lien = null;
        Mail::assertSent(DemandeAccordDirigeantMail::class, function (DemandeAccordDirigeantMail $mail) use (&$lien): bool {
            $lien = $mail->lien;

            return $mail->hasTo('jeanne.dupont@example.test') && $mail->nomDuDemandeur === 'Luc Bernard' && $mail->nomDuDirigeant === 'Jeanne DUPONT';
        });

        $this->identite()->repondreAvec(FausseVerificationIdentite::REPONSE_PAR_DEFAUT);
        $this->get($lien)->assertOk()->assertSee('Luc Bernard vous demande l\'autorisation de gérer le compte', false);
        $redirection = $this->post($lien)->assertRedirect();
        $this->get((string) $redirection->headers->get('Location'))->assertOk()->assertSee('Merci : le mandat est approuvé.');

        $this->assertTrue($societe->fresh()->estValidee());
        $mandat = $this->lignes($societe, TypeVerification::Mandat)->sole();
        $this->assertSame(
            [ResultatVerification::Concluante, $proprietaire->id, 'DUPONT Jeanne (Présidente)'],
            [$mandat->resultat, $mandat->beneficiaire_id, $mandat->details['dirigeant']],
        );
        $this->assertNotNull(app(TenantContext::class)->runAsSociete($societe, fn () => DemandeAccordDirigeant::query()->sole()->approuvee_le));
        $this->assertSame(EtatDeVerification::PREUVE_MANDAT, $this->audit($societe, ActionAuditee::SocieteValidee)->sole()->nouvelles_valeurs['preuve']);
        $this->get($lien)->assertStatus(410);
    }

    public function test_une_identite_sans_dirigeant_correspondant_ne_valide_rien(): void
    {
        $societe = $this->inscrireUneSociete(null, 'Luc Bernard');

        $identite = $this->verifierLIdentiteDuProprietaire($societe, self::IDENTITE_SANS_DIRIGEANT);

        $etat = $this->etat($societe);
        $this->assertSame(ResultatVerification::Concluante, $identite->resultat);
        $this->assertSame(ResultatVerification::Negative, $this->lignes($societe, TypeVerification::RneRepresentants)->sole()->resultat);
        $this->assertFalse($societe->fresh()->estValidee());
        $this->assertSame([true, null, [SignalDeRisque::IdentiteNonConcluante]], [$etat->identiteVerifiee, $etat->preuveDeQualite, $etat->signauxActifs]);
        $this->assertNull($societe->proprietaire->fresh()->qualite_verifiee_le);
    }

    /**
     * Le titulaire du compte, Marc Lefort, relaie la session à la vraie
     * présidente, qui la passe à sa place : l'écran du prestataire lui
     * disait au nom de qui et pour quel compte elle agissait ; la personne
     * vérifiée se montre au propriétaire, et la lettre d'information du
     * siège la nomme.
     */
    public function test_une_identite_verifiee_au_nom_d_un_autre_que_le_titulaire_du_compte_est_montree_et_nommee_dans_la_lettre(): void
    {
        $societe = $this->inscrireUneSociete(null, 'Marc Lefort');
        $proprietaire = $societe->proprietaire;

        $this->verifierLIdentiteDuProprietaire($societe);

        $consentement = collect($this->identite()->sessions())->sole()['demande']->texteDeConsentement;
        $this->assertStringContainsString('Vous confirmez être Marc Lefort et prendre le contrôle du compte '.config('app.name').' de '.$societe->getFilamentName(), $consentement);
        $this->assertStringContainsString(DemarrerVerificationIdentiteAction::masquer($proprietaire->email), $consentement);
        $this->assertStringNotContainsString($proprietaire->email, $consentement);
        $this->assertTrue($societe->fresh()->estValidee());
        $this->actingAs($proprietaire)
            ->get(PageDeVerification::getUrl(panel: 'societe', tenant: $societe))
            ->assertOk()
            ->assertSeeText('Identité vérifiée : Jeanne DUPONT, née ou né en 1980-01.');
        $this->assertStringContainsString('à la demande de Jeanne DUPONT', implode("\n", $this->lettresSimples()->sole()['corps']));
        $this->assertSame('Jeanne DUPONT', $this->lignes($societe, TypeVerification::LettreInformation)->sole()->details['personne_verifiee']);
    }

    /**
     * La session ouverte depuis la page de la vérification ne sert qu'au
     * navigateur et au compte qui l'ont ouverte, avec son state, et une
     * seule fois : un lien de retour relayé à une autre session, à un
     * autre compte, avec un state deviné, ou rejoué, n'écrit rien.
     */
    public function test_une_session_d_identite_ne_sert_qu_une_fois_et_qu_a_la_session_qui_l_a_ouverte(): void
    {
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $this->ouvrirLaPageDeVerification($societe, $proprietaire);

        Livewire::test(PageDeVerification::class)
            ->callAction('verifierMonIdentite', data: ['consentement' => false])
            ->assertHasActionErrors(['consentement' => 'accepted']);
        $this->assertSame([], $this->identite()->sessions());

        Livewire::test(PageDeVerification::class)
            ->callAction('verifierMonIdentite', data: ['consentement' => true])
            ->assertRedirect();
        $attendue = session(RetourVerificationIdentiteController::CLE_DE_SESSION);
        $this->assertSame([$attendue['reference']], array_keys($this->identite()->sessions()));
        $retour = route('verification.identite.retour', ['reference' => $attendue['reference'], 'state' => $attendue['state']]);

        session()->forget(RetourVerificationIdentiteController::CLE_DE_SESSION);
        $this->get($retour)->assertRedirect('/');
        $this->assertSame(0, $this->resultatsDIdentiteRecus($societe));

        session()->put(RetourVerificationIdentiteController::CLE_DE_SESSION, $attendue);
        $this->actingAs(User::factory()->create())->get($retour)->assertRedirect();
        $this->assertSame(0, $this->resultatsDIdentiteRecus($societe));

        session()->put(RetourVerificationIdentiteController::CLE_DE_SESSION, $attendue);
        $this->actingAs($proprietaire)->get(route('verification.identite.retour', ['reference' => $attendue['reference'], 'state' => 'un-state-devine']))->assertRedirect();
        $this->assertSame(0, $this->resultatsDIdentiteRecus($societe));
        $this->assertFalse($societe->fresh()->estValidee());

        session()->put(RetourVerificationIdentiteController::CLE_DE_SESSION, $attendue);
        $this->get($retour)->assertRedirect(PageDeVerification::getUrl(panel: 'societe', tenant: $societe));
        $this->assertSame(1, $this->resultatsDIdentiteRecus($societe));
        $this->assertTrue($societe->fresh()->estValidee());
        $this->assertNull(session(RetourVerificationIdentiteController::CLE_DE_SESSION));

        session()->put(RetourVerificationIdentiteController::CLE_DE_SESSION, $attendue);
        $this->get($retour)->assertRedirect();
        $this->assertSame(1, $this->resultatsDIdentiteRecus($societe));
    }

    /**
     * L'examen des pièces par l'éditeur (B4) ne vaut qu'avec le code du
     * courrier recommandé (C) qu'il fait partir : sans ce code, rien n'est
     * validé ; avec lui, la société l'est.
     */
    public function test_une_validation_manuelle_sans_code_de_courrier_ne_valide_rien(): void
    {
        Storage::fake(PiecesDeVerification::DISQUE);
        $societe = $this->inscrireUneSociete(null, 'Luc Bernard');
        app(DeposerUnePieceAction::class)->handle($societe->proprietaire, $societe, TypePiece::Kbis, 'Extrait Kbis');
        app(DeposerUnePieceAction::class)->handle($societe->proprietaire, $societe, TypePiece::Identite, 'Carte d\'identité');

        $code = app(ExaminerLesPiecesAction::class)->handle($societe, self::editeur());

        $etat = $this->etat($societe);
        $this->assertInstanceOf(CodeConfirmation::class, $code);
        $this->assertSame(['kbis', 'identite'], $this->lignes($societe, TypeVerification::Manuelle)->sole()->details['pieces']);
        $this->assertSame([true, null, false, true], [$etat->piecesExaminees, $etat->preuveDeQualite, $etat->adresseControlee, $etat->attendUnCourrier()]);
        $this->assertFalse(app(ValiderLaSocieteAction::class)->siPossible($societe->fresh(), self::editeur()));
        $this->assertFalse($societe->fresh()->estValidee());

        $this->assertTrue($this->saisirLeCode($societe));

        $this->assertTrue($societe->fresh()->estValidee());
        $this->assertSame(EtatDeVerification::PREUVE_PIECES_ET_COURRIER, $this->audit($societe, ActionAuditee::SocieteValidee)->sole()->nouvelles_valeurs['preuve']);
        $this->assertStringContainsString('à la demande de Luc Bernard', implode("\n", $this->lettresSimples()->sole()['corps']));
    }

    /**
     * Une société de ce SIREN a déjà été révoquée : les pièces examinées
     * et le code du courrier ne suffisent pas ; seule la levée motivée de
     * ce signal par l'éditeur, en plus du courrier, le lève.
     */
    public function test_la_validation_manuelle_ne_leve_pas_un_antecedent_d_usurpation(): void
    {
        Storage::fake(PiecesDeVerification::DISQUE);
        $siren = SocieteFactory::sirenFictif();
        $revoquee = $this->inscrireUneSociete($siren, 'Paul Usurpateur');
        $revoquee->forceFill(['statut' => StatutSociete::Revoquee])->save();
        $societe = $this->inscrireUneSociete($siren);
        app(DeposerUnePieceAction::class)->handle($societe->proprietaire, $societe, TypePiece::Kbis, 'Extrait Kbis');
        app(ExaminerLesPiecesAction::class)->handle($societe, self::editeur());

        $this->assertTrue($this->saisirLeCode($societe));

        $etat = $this->etat($societe);
        $this->assertFalse($societe->fresh()->estValidee());
        $this->assertSame([EtatDeVerification::PREUVE_PIECES_ET_COURRIER, [SignalDeRisque::AntecedentUsurpation]], [$etat->preuveDeQualite, $etat->signauxActifs]);

        app(LeverUnSignalAction::class)->lever($societe, SignalDeRisque::AntecedentUsurpation, self::editeur());

        $this->assertTrue($societe->fresh()->estValidee());
        $this->assertSame('Examen du dossier de vérification par l\'éditeur', $this->audit($societe, ActionAuditee::SocieteValidee)->sole()->justification);
    }

    /**
     * Chaque signal, constaté, ne se lève que par l'une des alternatives
     * qu'il exige, réunie en entier (voir SignalDeRisque::leveePar()) :
     * toutes les combinaisons des vérifications possibles sont essayées.
     * Les pièces examinées (B4), la levée d'un autre signal, ou une levée
     * antérieure au dernier constat ne lèvent jamais rien.
     *
     * @param  list<list<string>>  $alternatives  les ensembles minimaux de vérifications qui le lèvent
     */
    #[DataProvider('signauxEtCeQuiLesLeve')]
    public function test_chaque_signal_ne_se_leve_que_par_ce_qu_il_exige(SignalDeRisque $signal, array $alternatives): void
    {
        $verifications = ['courrier', 'courrier_rne', 'lre', 'manuelle', 'manuelle_anterieure', 'pieces', 'autre_signal'];
        $societe = (new Societe)->forceFill(['siren' => '000000018']);

        for ($combinaison = 0; $combinaison < 2 ** count($verifications); $combinaison++) {
            $presentes = array_values(array_filter($verifications, fn (string $verification, int $rang): bool => ($combinaison & (1 << $rang)) !== 0, ARRAY_FILTER_USE_BOTH));
            $lignes = collect([self::ligne(10, TypeVerification::Signal, $signal)])
                ->merge(array_map(fn (string $verification): VerificationSociete => self::preuve($verification, $signal), $presentes))
                ->sortBy('id')
                ->values();
            $attendu = collect($alternatives)->contains(fn (array $alternative): bool => array_diff($alternative, $presentes) === []);

            $actifs = app(SignauxDeRisque::class)->actifs($societe, $lignes, null, collect(), false, false);

            $this->assertSame($attendu ? [] : [$signal], $actifs, "{$signal->value} avec : ".(implode(', ', $presentes) ?: 'rien'));
        }
    }

    /**
     * @return array<string, array{0: SignalDeRisque, 1: list<list<string>>}>
     */
    public static function signauxEtCeQuiLesLeve(): array
    {
        $parCourrierEtLEditeur = [['courrier', 'manuelle'], ['courrier_rne', 'manuelle']];
        $parLEditeur = [['manuelle']];

        return [
            'diffusion partielle' => [SignalDeRisque::DiffusionPartielle, [['lre'], ['courrier_rne'], ['manuelle']]],
            'inscriptions concurrentes' => [SignalDeRisque::InscriptionsConcurrentes, $parCourrierEtLEditeur],
            'antécédent d\'usurpation' => [SignalDeRisque::AntecedentUsurpation, $parCourrierEtLEditeur],
            'dirigeant récent' => [SignalDeRisque::DirigeantRecent, [['courrier'], ['courrier_rne'], ['lre']]],
            'forme contradictoire' => [SignalDeRisque::FormeContradictoire, $parLEditeur],
            'adresse contradictoire' => [SignalDeRisque::AdresseContradictoire, $parLEditeur],
            'identité non concluante' => [SignalDeRisque::IdentiteNonConcluante, $parCourrierEtLEditeur],
            'essais répétés' => [SignalDeRisque::EssaisRepetes, $parLEditeur],
            'doute de l\'éditeur' => [SignalDeRisque::DecisionEditeur, $parLEditeur],
        ];
    }

    /**
     * Le code d'un courrier recommandé prouve qu'on le reçoit à l'adresse
     * officielle, jamais la qualité de représentant : ni seul, ni avec une
     * identité vérifiée qui n'est celle d'aucun dirigeant.
     */
    public function test_le_code_du_courrier_seul_ne_valide_jamais_la_societe(): void
    {
        $societe = $this->inscrireUneSociete(null, 'Luc Bernard');
        $this->envoyerUnCourrierParLEditeur($societe);

        $this->assertTrue($this->saisirLeCode($societe));

        $etat = $this->etat($societe);
        $this->assertSame([true, null], [$etat->adresseControlee, $etat->preuveDeQualite]);
        $this->assertFalse($societe->fresh()->estValidee());

        $this->verifierLIdentiteDuProprietaire($societe, self::IDENTITE_SANS_DIRIGEANT);

        $etat = $this->etat($societe);
        $this->assertSame([true, true, null], [$etat->adresseControlee, $etat->identiteVerifiee, $etat->preuveDeQualite]);
        $this->assertFalse($societe->fresh()->estValidee());
    }

    /**
     * Le code du pli (12 caractères de l'alphabet de Crockford) ne se
     * garde que haché ; un seul est actif par SIREN et par motif dans tout
     * le logiciel ; il expire trente jours après son envoi ; il ne sert
     * qu'une fois ; sa saisie ignore la casse et les tirets.
     */
    public function test_le_code_est_hache_unique_temporaire_et_a_usage_unique(): void
    {
        $societe = $this->inscrireUneSociete();
        $premier = $this->envoyerUnCourrierParLEditeur($societe);
        $code = (string) $this->envois()->codeDuDernierPli();

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $code);
        $enregistre = (array) DB::table('codes_confirmation')->sole();
        $this->assertStringNotContainsString(str_replace('-', '', $code), json_encode($enregistre, JSON_THROW_ON_ERROR));
        $this->assertTrue(Hash::check(str_replace('-', '', $code), $enregistre['code_hache']));
        $this->assertSame(now()->addDays(CodeConfirmation::DUREE_DE_VALIDITE_EN_JOURS)->toDateString(), $premier->expire_le->toDateString());

        $autre = $this->inscrireUneSociete($societe->siren);
        $this->assertThrows(fn () => $this->envoyerUnCourrierParLEditeur($autre), VerificationRefuseeException::class, EnvoyerCodeDeConfirmationAction::MESSAGE_CODE_EN_COURS);
        $this->assertThrows(fn () => $this->envoyerUnCourrierParLEditeur($societe), VerificationRefuseeException::class, EnvoyerCodeDeConfirmationAction::MESSAGE_CODE_EN_COURS);
        $this->assertSame(1, DB::table('codes_confirmation')->count());
        $this->assertCount(1, $this->envois()->envois());

        $this->travel(CodeConfirmation::DUREE_DE_VALIDITE_EN_JOURS + 1)->days();
        $this->assertThrows(fn () => $this->saisirLeCode($societe, $code), VerificationRefuseeException::class, SaisirCodeDeConfirmationAction::MESSAGE_AUCUN_CODE);

        $this->envoyerUnCourrierParLEditeur($societe);
        $nouveau = (string) $this->envois()->codeDuDernierPli();
        $this->assertNotNull($premier->fresh()->annule_le);
        $this->assertTrue($this->saisirLeCode($societe, strtolower(str_replace('-', '', $nouveau))));
        $this->assertThrows(fn () => $this->saisirLeCode($societe, $nouveau), VerificationRefuseeException::class, SaisirCodeDeConfirmationAction::MESSAGE_AUCUN_CODE);
        $this->assertSame(1, $this->lignes($societe, TypeVerification::Courrier)->count());
    }

    /**
     * Cinq codes faux annulent le code et constatent les essais répétés :
     * vérification d'identité, courrier et saisie se bloquent jusqu'à la
     * levée de l'éditeur.
     */
    public function test_le_cinquieme_code_faux_annule_le_code_et_bloque_les_voies_automatiques(): void
    {
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire;
        $confirmation = $this->envoyerUnCourrierParLEditeur($societe);
        $code = (string) $this->envois()->codeDuDernierPli();
        $faux = $code === 'AAAA-AAAA-AAAA' ? 'BBBB-BBBB-BBBB' : 'AAAA-AAAA-AAAA';

        foreach (range(1, CodeConfirmation::ESSAIS_PERMIS - 1) as $essai) {
            $this->assertFalse($this->saisirLeCode($societe, $faux));
        }
        $this->assertThrows(fn () => $this->saisirLeCode($societe, $faux), VerificationRefuseeException::class, SaisirCodeDeConfirmationAction::MESSAGE_CODE_ANNULE);

        $confirmation->refresh();
        $this->assertSame(CodeConfirmation::ESSAIS_PERMIS, $confirmation->tentatives);
        $this->assertNotNull($confirmation->annule_le);
        $this->assertSame([SignalDeRisque::EssaisRepetes], $this->etat($societe)->signauxActifs);
        $this->assertThrows(fn () => $this->saisirLeCode($societe, $code), VerificationRefuseeException::class, DemarrerVerificationIdentiteAction::MESSAGE_VOIES_BLOQUEES);
        $this->assertThrows(fn () => $this->verifierLIdentiteDuProprietaire($societe), VerificationRefuseeException::class, DemarrerVerificationIdentiteAction::MESSAGE_VOIES_BLOQUEES);
        $this->assertThrows(fn () => app(EnvoyerCodeDeConfirmationAction::class)->handle($societe, MotifCode::Verification, proprietaire: $proprietaire), VerificationRefuseeException::class, DemarrerVerificationIdentiteAction::MESSAGE_VOIES_BLOQUEES);
        $this->assertSame(CodeConfirmation::ESSAIS_PERMIS, $this->audit($societe, ActionAuditee::CodeSaisi)->filter(fn (EntreeAudit $entree): bool => $entree->nouvelles_valeurs['resultat'] === 'faux')->count());

        app(LeverUnSignalAction::class)->lever($societe, SignalDeRisque::EssaisRepetes, self::editeur());
        $this->verifierLIdentiteDuProprietaire($societe);

        $this->assertTrue($societe->fresh()->estValidee());
    }

    /**
     * Le courrier part à l'adresse du siège que donne le répertoire Sirene
     * le jour même de l'envoi, jamais à celle que la société a saisie.
     */
    public function test_le_courrier_part_a_l_adresse_officielle_sirene_jamais_a_une_adresse_saisie(): void
    {
        $societe = $this->inscrireUneSociete(null, 'Luc Bernard');
        $societe->forceFill(['siege_adresse' => '99 rue des Usurpateurs', 'siege_code_postal' => '13001', 'siege_ville' => 'Marseille'])->save();
        $this->verifierLIdentiteDuProprietaire($societe, self::IDENTITE_SANS_DIRIGEANT);
        $this->repertoire()->deplacer(FauxRepertoireSirene::siretDuSiege($societe->siren), '69002', 'LYON');

        $confirmation = app(EnvoyerCodeDeConfirmationAction::class)->handle($societe, MotifCode::Verification, proprietaire: $societe->proprietaire);

        $adresse = new AdressePostale("SOCIETE FICTIVE {$societe->siren}", '1 RUE DE LA REPUBLIQUE', null, '69002', 'LYON', AdressePostale::SOURCE_SIRENE);
        $this->assertEquals($adresse, $this->envois()->envois()[$confirmation->reference_envoi]['destinataire']);
        $this->assertSame(FauxServiceEnvoiRecommande::COURRIER, $this->envois()->envois()[$confirmation->reference_envoi]['genre']);
        $this->assertSame($adresse->enTableau(), $confirmation->destinataire);
        $this->assertStringNotContainsString('Usurpateurs', json_encode(DB::table('codes_confirmation')->get(), JSON_THROW_ON_ERROR));
    }

    /**
     * En diffusion partielle, le répertoire masque l'adresse du siège : le
     * courrier part à celle du registre national des entreprises, et à
     * aucune autre ; sans elle, rien ne part.
     */
    public function test_en_diffusion_partielle_aucun_courrier_ne_part_a_une_adresse_fournie(): void
    {
        $siren = FauxRepertoireSirene::SIREN_DIFFUSION_PARTIELLE;
        $this->rne()->domicilier($siren, null);
        $societe = $this->inscrireUneSociete($siren);
        $societe->forceFill(['siege_adresse' => '5 rue Fournie', 'siege_code_postal' => '13001', 'siege_ville' => 'Marseille'])->save();
        $this->verifierLIdentiteDuProprietaire($societe);
        $this->assertFalse($societe->fresh()->estValidee());
        $this->assertSame([SignalDeRisque::DiffusionPartielle], $this->etat($societe)->signauxActifs);

        $this->assertThrows(fn () => app(EnvoyerCodeDeConfirmationAction::class)->handle($societe, MotifCode::Verification, proprietaire: $societe->proprietaire), VerificationRefuseeException::class, 'Aucune adresse officielle de votre entreprise n\'est publiée : l\'éditeur vous contactera.');
        $this->assertSame([], $this->envois()->envois());
        $this->assertSame(0, DB::table('codes_confirmation')->count());

        $siegeAuRne = new AdressePostale('ENTREPRISE EN DIFFUSION PARTIELLE', '7 AVENUE DU REGISTRE', null, '69002', 'LYON', AdressePostale::SOURCE_RNE);
        $this->rne()->domicilier($siren, $siegeAuRne);
        $confirmation = app(EnvoyerCodeDeConfirmationAction::class)->handle($societe, MotifCode::Verification, proprietaire: $societe->proprietaire);

        $this->assertEquals($siegeAuRne, $this->envois()->envois()[$confirmation->reference_envoi]['destinataire']);
        $this->assertTrue($this->saisirLeCode($societe));
        $this->assertSame(AdressePostale::SOURCE_RNE, $this->lignes($societe, TypeVerification::Courrier)->sole()->details['source_adresse']);
        $this->assertTrue($societe->fresh()->estValidee());
    }

    /**
     * Une inscription factice du SIREN, sans identité vérifiée, ne fait
     * partir aucun pli chez le vrai titulaire et ne lui impose rien : il
     * valide sa propre inscription sans courrier.
     */
    public function test_une_inscription_factice_n_impose_pas_de_courrier_au_vrai_titulaire(): void
    {
        $siren = SocieteFactory::sirenFictif();
        $factice = $this->inscrireUneSociete($siren, 'Paul Usurpateur');

        $this->assertThrows(fn () => app(EnvoyerCodeDeConfirmationAction::class)->handle($factice, MotifCode::Verification, proprietaire: $factice->proprietaire), VerificationRefuseeException::class, EnvoyerCodeDeConfirmationAction::MESSAGE_IDENTITE_D_ABORD);
        $this->verifierLIdentiteDuProprietaire($factice, ['statut' => 'refusee']);
        $this->assertThrows(fn () => app(EnvoyerCodeDeConfirmationAction::class)->handle($factice, MotifCode::Verification, proprietaire: $factice->proprietaire), VerificationRefuseeException::class, EnvoyerCodeDeConfirmationAction::MESSAGE_IDENTITE_D_ABORD);
        $this->assertSame([], $this->envois()->envois());

        $titulaire = $this->inscrireUneSociete($siren);
        $this->assertSame([], $this->etat($titulaire)->signauxActifs);
        $this->verifierLIdentiteDuProprietaire($titulaire);

        $this->assertTrue($titulaire->fresh()->estValidee());
        $this->assertSame(0, DB::table('codes_confirmation')->count());
        $this->assertSame([FauxServiceEnvoiRecommande::LETTRE_SIMPLE], array_values(array_column($this->envois()->envois(), 'genre')));
    }

    /**
     * Deux plis au plus par SIREN et par trente jours à la demande des
     * propriétaires, toutes inscriptions de ce SIREN confondues ; l'éditeur
     * n'y est pas soumis. Le propriétaire d'une autre inscription, que ces
     * plis ou le code en route ne concernent pas, lit un refus qui ne lui
     * en apprend rien, et l'éditeur est alerté.
     */
    public function test_le_nombre_de_courriers_est_limite_par_siren(): void
    {
        Mail::fake();
        config(['editeur.email' => 'editeur@piliqi.test']);
        $siren = SocieteFactory::sirenFictif();
        $societe = $this->inscrireUneSociete($siren, 'Luc Bernard');
        $this->verifierLIdentiteDuProprietaire($societe, self::IDENTITE_SANS_DIRIGEANT);

        foreach (range(1, EnvoyerCodeDeConfirmationAction::PLIS_PAR_SIREN_ET_PAR_PERIODE) as $pli) {
            $confirmation = app(EnvoyerCodeDeConfirmationAction::class)->handle($societe, MotifCode::Verification, proprietaire: $societe->proprietaire);
            $this->envois()->retourner($confirmation->reference_envoi);
            app(SuivreLesEnvoisAction::class)->suivreTout();
            $this->assertNotNull($confirmation->fresh()->annule_le);
        }

        $this->assertThrows(fn () => app(EnvoyerCodeDeConfirmationAction::class)->handle($societe, MotifCode::Verification, proprietaire: $societe->proprietaire), VerificationRefuseeException::class, EnvoyerCodeDeConfirmationAction::MESSAGE_LIMITE);
        $autre = $this->inscrireUneSociete($siren, 'Luc Bernard');
        $this->verifierLIdentiteDuProprietaire($autre, self::IDENTITE_SANS_DIRIGEANT);
        $this->assertThrows(fn () => app(EnvoyerCodeDeConfirmationAction::class)->handle($autre, MotifCode::Verification, proprietaire: $autre->proprietaire), VerificationRefuseeException::class, EnvoyerCodeDeConfirmationAction::MESSAGE_EXAMEN);
        $this->assertCount(2, $this->envois()->envois());

        $this->envoyerUnCourrierParLEditeur($societe);
        $this->assertCount(3, $this->envois()->envois());

        $this->travel(EnvoyerCodeDeConfirmationAction::JOURS_DE_LA_PERIODE + 1)->days();
        app(EnvoyerCodeDeConfirmationAction::class)->handle($autre, MotifCode::Verification, proprietaire: $autre->proprietaire);
        $this->assertCount(4, $this->envois()->envois());
        $this->assertThrows(fn () => app(EnvoyerCodeDeConfirmationAction::class)->handle($societe, MotifCode::Verification, proprietaire: $societe->proprietaire), VerificationRefuseeException::class, EnvoyerCodeDeConfirmationAction::MESSAGE_EXAMEN);
        $this->assertCount(4, $this->envois()->envois());
        Mail::assertSent(AlerteEditeurMail::class, fn (AlerteEditeurMail $mail): bool => str_starts_with($mail->objet, 'Courrier demandé alors qu\'une autre inscription du même SIREN'));
        Mail::assertSentCount(2);
    }

    /**
     * La présidente est en fonction depuis dix jours : son identité
     * rapprochée ne suffit pas sans le contrôle de l'adresse. Une lettre
     * recommandée électronique qualifiée le remplace, une fois acceptée par
     * la personne que le prestataire a identifiée comme elle ; acceptée
     * par une autre, son code s'annule.
     */
    public function test_une_lre_qualifiee_remise_a_un_dirigeant_identifie_remplace_le_courrier(): void
    {
        $siren = SocieteFactory::sirenFictif();
        $this->rne()->dirigeants($siren, [new RepresentantRne('DUPONT', null, ['Jeanne'], '1980-01', 'Présidente', true, null, now()->subDays(10)->toDateString())]);
        $societe = $this->inscrireUneSociete($siren);
        $proprietaire = $societe->proprietaire;
        $this->verifierLIdentiteDuProprietaire($societe);
        $this->assertFalse($societe->fresh()->estValidee());
        $this->assertSame([SignalDeRisque::DirigeantRecent], $this->etat($societe)->signauxActifs);
        $lre = ['rang' => 0, 'email' => 'jeanne.dupont@example.test'];

        $premiere = app(EnvoyerCodeDeConfirmationAction::class)->handle($societe, MotifCode::Verification, CanalEnvoi::LreQualifiee, $proprietaire, lre: $lre);

        $this->assertSame(
            [FauxServiceEnvoiRecommande::LRE, ['nom' => 'Jeanne DUPONT', 'email' => 'jeanne.dupont@example.test']],
            [$this->envois()->envois()[$premiere->reference_envoi]['genre'], $this->envois()->envois()[$premiere->reference_envoi]['destinataire']],
        );
        $this->assertThrows(fn () => $this->saisirLeCode($societe), VerificationRefuseeException::class, SaisirCodeDeConfirmationAction::MESSAGE_LRE_NON_ACCEPTEE);
        $this->envois()->remettre($premiere->reference_envoi, ['statut' => 'verifiee', 'nom' => 'MARTIN', 'prenoms' => ['Paul'], 'date_naissance' => '1975-06-01']);
        app(SuivreLesEnvoisAction::class)->suivreTout();
        $this->assertSame([null, true], [$premiere->fresh()->remis_le, $premiere->fresh()->annule_le !== null]);
        $this->assertThrows(fn () => $this->saisirLeCode($societe), VerificationRefuseeException::class, SaisirCodeDeConfirmationAction::MESSAGE_AUCUN_CODE);

        $seconde = app(EnvoyerCodeDeConfirmationAction::class)->handle($societe, MotifCode::Verification, CanalEnvoi::LreQualifiee, $proprietaire, lre: $lre);
        $this->envois()->remettre($seconde->reference_envoi, FausseVerificationIdentite::REPONSE_PAR_DEFAUT);
        app(SuivreLesEnvoisAction::class)->suivreTout();
        $this->assertNotNull($seconde->fresh()->remis_le);
        $this->assertTrue($this->saisirLeCode($societe));

        $this->assertTrue($societe->fresh()->estValidee());
        $this->assertSame('Jeanne DUPONT', $this->lignes($societe, TypeVerification::Lre)->sole()->details['dirigeant']);
        $this->assertNotContains(FauxServiceEnvoiRecommande::COURRIER, array_column($this->envois()->envois(), 'genre'));
    }

    /**
     * Rien n'est réservé avant la validation ; elle réserve le SIREN et le
     * SIRET de chaque magasin réel actif, ouvre leur période d'activité et
     * inscrit les droits du propriétaire au journal des événements de
     * chacun.
     */
    public function test_la_validation_reserve_le_siren_et_les_siret_des_magasins_actifs_et_ouvre_leurs_periodes(): void
    {
        $societe = $this->inscrireUneSociete();
        $premier = $societe->magasinsReels()->sole();
        $second = app(CreerEtablissementAction::class)->handle($societe->proprietaire, $societe, ['nom' => 'Second magasin', 'nic' => MagasinFactory::nicLibre($societe)]);
        app(TenantContext::class)->clear();
        $this->assertSame([0, 0], [SiretMagasin::query()->count(), PeriodeActivite::query()->count()]);

        $this->verifierLIdentiteDuProprietaire($societe);

        $this->assertTrue($societe->fresh()->estValidee());
        $magasins = collect([$premier, $second]);
        $this->assertEqualsCanonicalizing(
            $magasins->map(fn (Magasin $magasin): array => [$magasin->id, $magasin->siret])->all(),
            SiretMagasin::query()->where('societe_id', $societe->id)->whereNull('retire_le')->whereNull('libere_le')->get()->map(fn (SiretMagasin $reserve): array => [$reserve->magasin_id, $reserve->siret])->all(),
        );
        foreach ($magasins as $magasin) {
            $this->assertNull(PeriodeActivite::query()->where('magasin_id', $magasin->id)->sole()->fin);
            $this->assertSame(1, EnregistrementFiscal::withoutGlobalScope(MagasinScope::class)->where('magasin_id', $magasin->id)->where('chaine', ChaineFiscale::Evenement->value)->where('type', (string) CodeEvenement::ModificationDesDroits->value)->count());
        }

        $autre = $this->inscrireUneSociete($societe->siren);
        $this->verifierLIdentiteDuProprietaire($autre);
        $this->assertFalse($autre->fresh()->estValidee());
        $this->assertTrue($this->etat($autre)->reclamationPossible());
    }

    /**
     * Un magasin réel fermé avant la validation, même dont l'établissement
     * n'a jamais été confirmé, ne compte pas : la société est validée, et
     * lui n'est ni réservé ni ouvert.
     */
    public function test_un_magasin_ferme_avant_la_validation_ne_bloque_pas_la_validation(): void
    {
        $societe = $this->inscrireUneSociete();
        $this->repertoire()->rendreInjoignable();
        $ferme = app(CreerEtablissementAction::class)->handle($societe->proprietaire, $societe, ['nom' => 'Fermé', 'nic' => MagasinFactory::nicLibre($societe)]);
        $this->repertoire()->rendreInjoignable(false);
        $this->assertNull($ferme->fresh()->etablissement_verifie_le);
        $ferme->forceFill(['statut' => StatutMagasin::Ferme])->save();
        app(TenantContext::class)->clear();

        $this->verifierLIdentiteDuProprietaire($societe);

        $this->assertTrue($societe->fresh()->estValidee());
        $this->assertSame([$societe->magasinsReels()->where('statut', StatutMagasin::Actif->value)->sole()->id], SiretMagasin::query()->pluck('magasin_id')->all());
        $this->assertSame(0, PeriodeActivite::query()->where('magasin_id', $ferme->id)->count());
    }

    /**
     * La validation d'une société ne refuse aucune autre inscription du
     * même SIREN : celle qui avait déjà prouvé sa qualité apprend qu'un
     * examen de l'éditeur est nécessaire, sans rien de l'autre ; celle qui
     * n'a rien prouvé n'apprend rien, jusqu'à ce qu'elle prouve sa qualité
     * et devienne une réclamation que l'éditeur examine.
     *
     * La première, dont l'éditeur doutait, et la troisième ont toutes deux
     * prouvé leur qualité : chacune porte le signal des inscriptions
     * concurrentes, et l'éditeur, après le courrier, valide la première.
     */
    public function test_la_validation_ne_refuse_aucune_autre_inscription_du_meme_siren(): void
    {
        Mail::fake();
        config(['editeur.email' => 'editeur@piliqi.test']);
        $siren = SocieteFactory::sirenFictif();
        $premiere = $this->inscrireUneSociete($siren);
        $seconde = $this->inscrireUneSociete($siren);
        $troisieme = $this->inscrireUneSociete($siren);
        app(LeverUnSignalAction::class)->signalerUnDoute($premiere, self::editeur());
        $this->verifierLIdentiteDuProprietaire($premiere);
        $this->verifierLIdentiteDuProprietaire($troisieme);
        $this->assertSame([false, false], [$premiere->fresh()->estValidee(), $troisieme->fresh()->estValidee()]);
        $this->assertSame(
            [[SignalDeRisque::InscriptionsConcurrentes, SignalDeRisque::DecisionEditeur], [SignalDeRisque::InscriptionsConcurrentes]],
            [$this->etat($premiere)->signauxActifs, $this->etat($troisieme)->signauxActifs],
        );

        $this->envoyerUnCourrierParLEditeur($premiere);
        $this->assertTrue($this->saisirLeCode($premiere));
        app(LeverUnSignalAction::class)->lever($premiere, SignalDeRisque::DecisionEditeur, self::editeur());
        $this->assertFalse($premiere->fresh()->estValidee());
        app(LeverUnSignalAction::class)->lever($premiere, SignalDeRisque::InscriptionsConcurrentes, self::editeur());

        $this->assertTrue($premiere->fresh()->estValidee());
        foreach ([$seconde, $troisieme] as $autre) {
            $autre->refresh();
            $this->assertSame([null, null, StatutSociete::Active], [$autre->validee_le, $autre->refusee_le, $autre->statut]);
        }
        $avis = $troisieme->proprietaire->notifications()->sole();
        $this->assertSame('Un examen de l\'éditeur est nécessaire', $avis->data['title']);
        $this->assertStringNotContainsString((string) $premiere->getFilamentName(), json_encode($avis->data, JSON_THROW_ON_ERROR));
        $this->assertSame(0, $seconde->proprietaire->notifications()->count());
        $this->actingAs($seconde->proprietaire)
            ->get(PageDeVerification::getUrl(panel: 'societe', tenant: $seconde))
            ->assertOk()
            ->assertSee('Société en cours de vérification')
            ->assertDontSee('déjà utilisé')
            ->assertDontSeeText('Contrôle de l\'adresse officielle', false)
            ->assertDontSeeText('Un examen de votre dossier par l\'éditeur est nécessaire.', false);
        $this->assertSame([SignalDeRisque::InscriptionsConcurrentes], $this->etat($seconde)->signauxActifs);
        $this->flushSession();
        $this->actingAs($troisieme->proprietaire)
            ->get(PageDeVerification::getUrl(panel: 'societe', tenant: $troisieme))
            ->assertSeeText('Contrôle de l\'adresse officielle', false)
            ->assertSeeText('Un examen de votre dossier par l\'éditeur est nécessaire.', false);

        $this->flushSession();
        $this->actingAs($seconde->proprietaire);
        $this->verifierLIdentiteDuProprietaire($seconde);

        $this->assertFalse($seconde->fresh()->estValidee());
        $this->assertTrue($this->etat($seconde)->reclamationPossible());
        Mail::assertSent(AlerteEditeurMail::class, fn (AlerteEditeurMail $mail): bool => str_contains($mail->objet, 'Réclamation possible') && str_contains(implode("\n", $mail->lignes), "Société n° {$seconde->id}"));
        $this->get(PageDeVerification::getUrl(panel: 'societe', tenant: $seconde))
            ->assertOk()
            ->assertSee('Ce SIREN est déjà utilisé par un autre compte');
    }

    /**
     * À la validation, une lettre simple part au siège que donne le
     * répertoire, nommant la personne vérifiée ; elle ne part qu'une fois.
     */
    public function test_la_validation_envoie_la_lettre_d_information_au_siege(): void
    {
        $societe = $this->inscrireUneSociete();

        $this->verifierLIdentiteDuProprietaire($societe);

        $lettre = $this->lettresSimples()->sole();
        $this->assertEquals(new AdressePostale("SOCIETE FICTIVE {$societe->siren}", '1 RUE DE LA REPUBLIQUE', null, '75001', 'PARIS', AdressePostale::SOURCE_SIRENE), $lettre['destinataire']);
        $corps = implode("\n", $lettre['corps']);
        $this->assertStringContainsString("Votre entreprise (SIREN {$societe->siren}) vient d'être validée", $corps);
        $this->assertStringContainsString('à la demande de Jeanne DUPONT', $corps);
        $this->assertStringContainsString('signalez-le sans attendre à l\'éditeur', $corps);
        $ligne = $this->lignes($societe, TypeVerification::LettreInformation)->sole();
        $this->assertSame([ResultatVerification::Concluante, AdressePostale::SOURCE_SIRENE], [$ligne->resultat, $ligne->details['source_adresse']]);

        EnvoyerLaLettreDInformationJob::dispatchSync($societe->id, 'Jeanne DUPONT');

        $this->assertCount(1, $this->lettresSimples());
    }

    /**
     * Les rappels des prestataires : mal signés, signés d'un autre secret,
     * d'un corps modifié, trop anciens ou sans secret configuré, ils sont
     * refusés sans rien écrire ; justes et rejoués, ils ne refont rien.
     */
    public function test_un_rappel_de_prestataire_mal_signe_ou_rejoue_est_refuse(): void
    {
        config(['services.identite.secret_rappel' => self::SECRET_DES_RAPPELS_D_IDENTITE, 'services.recommande.secret_rappel' => self::SECRET_DES_RAPPELS_D_ENVOI]);
        $societe = $this->inscrireUneSociete();
        $demarree = app(DemarrerVerificationIdentiteAction::class)->handle($societe->proprietaire, $societe, true, 'http://localhost/verification/identite/retour');
        $resultat = ['reference' => $demarree['session']->reference, ...FausseVerificationIdentite::REPONSE_PAR_DEFAUT];
        $signeAilleurs = json_encode([...$resultat, 'nom' => 'MARTIN'], JSON_THROW_ON_ERROR);

        $this->rappeler('rappels.identite', $resultat, self::SECRET_DES_RAPPELS_D_IDENTITE, signature: '')->assertUnauthorized();
        $this->rappeler('rappels.identite', $resultat, 'un-autre-secret')->assertUnauthorized();
        $this->rappeler('rappels.identite', $resultat, self::SECRET_DES_RAPPELS_D_IDENTITE, horodatage: time() - RappelsDesPrestatairesController::ECART_MAXIMAL_EN_SECONDES - 60)->assertUnauthorized();
        $this->rappeler('rappels.identite', $resultat, self::SECRET_DES_RAPPELS_D_IDENTITE, signature: RappelsDesPrestatairesController::signature(self::SECRET_DES_RAPPELS_D_IDENTITE, (string) time(), $signeAilleurs))->assertUnauthorized();
        $this->assertSame(0, $this->resultatsDIdentiteRecus($societe));

        $this->rappeler('rappels.identite', $resultat, self::SECRET_DES_RAPPELS_D_IDENTITE)->assertOk();
        $this->rappeler('rappels.identite', $resultat, self::SECRET_DES_RAPPELS_D_IDENTITE)->assertOk();

        $this->assertSame(1, $this->resultatsDIdentiteRecus($societe));
        $this->assertTrue($societe->fresh()->estValidee());
        $this->rappeler('rappels.identite', $resultat, self::SECRET_DES_RAPPELS_D_IDENTITE, horodatage: time() - 600)->assertUnauthorized();
        config(['services.identite.secret_rappel' => null]);
        $this->rappeler('rappels.identite', $resultat, '')->assertUnauthorized();

        $autre = $this->inscrireUneSociete();
        $confirmation = $this->envoyerUnCourrierParLEditeur($autre);
        $retourne = ['reference' => $confirmation->reference_envoi, 'statut' => 'retourne'];
        $this->rappeler('rappels.recommande', $retourne, self::SECRET_DES_RAPPELS_D_IDENTITE)->assertUnauthorized();
        $this->assertSame([null, null], [$confirmation->fresh()->retourne_le, $confirmation->fresh()->annule_le]);
        $this->rappeler('rappels.recommande', ['reference' => $confirmation->reference_envoi, 'statut' => 'remis'], self::SECRET_DES_RAPPELS_D_ENVOI)->assertOk();
        $this->rappeler('rappels.recommande', $retourne, self::SECRET_DES_RAPPELS_D_ENVOI)->assertOk();

        $confirmation->refresh();
        $this->assertSame([true, null, null], [$confirmation->remis_le !== null, $confirmation->retourne_le, $confirmation->annule_le]);
        $this->assertSame([], $this->etat($autre)->signauxActifs);
    }

    /**
     * L'INSEE ou l'INPI qui ne répond pas laisse son étape en attente,
     * sans rien refuser ni rien tenir pour prouvé : à son retour, le
     * contrôle repris valide la société.
     */
    public function test_l_insee_ou_l_inpi_injoignable_laisse_l_etape_en_attente(): void
    {
        $this->repertoire()->rendreInjoignable();
        $societe = $this->inscrireUneSociete();
        $this->repertoire()->rendreInjoignable(false);
        $this->verifierLIdentiteDuProprietaire($societe);

        $etat = $this->etat($societe);
        $this->assertSame(ResultatVerification::Indisponible, $this->lignes($societe, TypeVerification::SireneUnite)->last()->resultat);
        $this->assertSame([false, EtatDeVerification::PREUVE_IDENTITE_RAPPROCHEE, false], [$etat->entrepriseEtEtablissements, $etat->preuveDeQualite, $societe->fresh()->estValidee()]);
        app(ReverificationSirene::class)->reprendre($societe->fresh(), false);
        $this->assertTrue($societe->fresh()->estValidee());

        $autre = $this->inscrireUneSociete();
        $this->rne()->rendreInjoignable();
        $this->verifierLIdentiteDuProprietaire($autre);

        $etat = $this->etat($autre);
        $this->assertSame(ResultatVerification::Indisponible, $this->lignes($autre, TypeVerification::RneRepresentants)->sole()->resultat);
        $this->assertSame([true, null, []], [$etat->identiteVerifiee, $etat->preuveDeQualite, $etat->signauxActifs]);
        $this->assertFalse($autre->fresh()->estValidee());
        $this->rne()->rendreInjoignable(false);
        app(RecevoirResultatIdentiteAction::class)->rapprocherDeNouveau($autre->proprietaire, $autre);
        $this->assertTrue($autre->fresh()->estValidee());
    }

    /**
     * Le prestataire d'identité en donne plus qu'il n'en faut : rien hors
     * de la liste blanche (nom, nom d'usage, prénoms, mois et année de
     * naissance, niveau de garantie) ne se garde, dans aucune table.
     */
    public function test_aucune_donnee_d_identite_hors_liste_blanche_n_est_conservee(): void
    {
        $societe = $this->inscrireUneSociete();

        $this->verifierLIdentiteDuProprietaire($societe, [
            ...FausseVerificationIdentite::REPONSE_PAR_DEFAUT,
            'numero_piece' => 'X4RTBPFW46',
            'date_expiration_piece' => '2031-04-30',
            'adresse' => '12 rue des Secrets',
            'lieu_naissance' => 'Clermont-Ferrand',
            'photo' => base64_encode('image-du-visage'),
            'score_biometrique' => 0.987654,
        ]);

        $this->assertSame(
            ['nom' => 'DUPONT', 'nom_usage' => null, 'prenoms' => ['Jeanne'], 'naissance' => '1980-01', 'niveau_garantie' => 'substantiel'],
            $this->lignes($societe, TypeVerification::Identite)->last()->details,
        );
        $base = json_encode(collect(Schema::getTableListing())->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->get()])->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (['X4RTBPFW46', '2031-04-30', '12 rue des Secrets', 'Clermont-Ferrand', base64_encode('image-du-visage'), '0.987654', '1980-01-15'] as $horsListe) {
            $this->assertStringNotContainsString($horsListe, $base);
        }
    }

    /**
     * Les pièces déposées se rangent chiffrées ; l'éditeur ne les lit que
     * dans un dossier temporaire effacé à la fin de son examen ; sa
     * décision les efface, et celles qu'il n'a pas examinées s'effacent
     * trente jours après leur dépôt. La trace de chaque pièce reste.
     */
    public function test_les_pieces_deposees_sont_chiffrees_et_effacees_a_la_decision(): void
    {
        Storage::fake(PiecesDeVerification::DISQUE);
        config(['editeur.operateurs' => ['alice' => password_hash('secret-d-alice', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1])]]);
        $societe = $this->inscrireUneSociete(null, 'Luc Bernard');
        $contenu = 'Extrait Kbis de la société, lisible en clair';

        $piece = app(DeposerUnePieceAction::class)->handle($societe->proprietaire, $societe, TypePiece::Kbis, $contenu);

        $chemin = (string) $piece->chemin;
        $chiffre = Storage::disk(PiecesDeVerification::DISQUE)->get($chemin);
        $this->assertStringNotContainsString('Kbis', $chiffre);
        $this->assertSame($contenu, Crypt::decryptString($chiffre));
        $this->assertSame(hash('sha256', $contenu), $piece->empreinte);

        $this->procedureDeLEditeur('editeur:societe:pieces', $societe)
            ->expectsQuestion('Appuyez sur Entrée une fois l\'examen terminé : le dossier sera effacé', '')
            ->assertSuccessful();
        $this->assertSame([], glob(storage_path('app/private/examen-*')) ?: []);
        $this->assertSame(['kbis'], $this->audit($societe, ActionAuditee::PiecesConsultees)->sole()->nouvelles_valeurs['pieces']);

        $this->procedureDeLEditeur('editeur:societe:valider', $societe)->assertSuccessful();

        $piece->refresh();
        $this->assertSame([null, true], [$piece->chemin, $piece->effacee_le !== null]);
        Storage::disk(PiecesDeVerification::DISQUE)->assertMissing($chemin);
        $this->assertSame(PiecesDeVerification::MOTIF_DECISION, $this->audit($societe, ActionAuditee::PieceEffacee)->sole()->nouvelles_valeurs['motif']);

        $autre = $this->inscrireUneSociete(null, 'Luc Bernard');
        $oubliee = app(DeposerUnePieceAction::class)->handle($autre->proprietaire, $autre, TypePiece::Identite, 'Carte d\'identité');
        $this->travel(PieceVerification::CONSERVATION_EN_JOURS + 1)->days();
        $this->artisan('app:effacer-pieces-verification')->assertSuccessful();
        Storage::disk(PiecesDeVerification::DISQUE)->assertMissing((string) $oubliee->chemin);
        $this->assertNotNull(app(TenantContext::class)->runAsSociete($autre, fn () => $oubliee->fresh()->effacee_le));
    }

    /**
     * Après la validation, une revérification négative (entreprise cessée,
     * établissement fermé au répertoire) s'inscrit et prévient le
     * propriétaire et l'éditeur, sans rien bloquer : les ventes et les
     * paiements continuent.
     */
    public function test_une_reverification_negative_n_arrete_ni_les_ventes_ni_les_paiements(): void
    {
        Mail::fake();
        config(['editeur.email' => 'editeur@piliqi.test']);
        $magasin = $this->ouvrirUneSociete();
        $societe = $magasin->societe;
        $proprietaire = $this->proprietaireDe($magasin);
        $this->repertoire()->cesser($societe->siren);
        $this->repertoire()->fermer((string) $magasin->siret);

        $this->artisan('app:reverifier-sirene', ['--toutes' => true])->assertSuccessful();

        $this->assertSame(ResultatVerification::Negative, $this->lignes($societe, TypeVerification::SireneUnite)->last()->resultat);
        $this->assertSame(ResultatVerification::Negative, $this->lignes($societe, TypeVerification::SireneEtablissement)->last()->resultat);
        $this->assertSame([true, false], [$societe->fresh()->estValidee(), $societe->fresh()->estRefusee()]);
        $this->assertNotNull($magasin->fresh()->etablissement_verifie_le);
        Mail::assertSent(AlerteEditeurMail::class, fn (AlerteEditeurMail $mail): bool => str_contains($mail->objet, 'Revérification Sirene négative'));
        $this->assertContains('Écart au répertoire Sirene', $proprietaire->notifications()->get()->pluck('data.title')->all());

        app(TenantContext::class)->set($magasin->fresh());
        $this->actingAs($proprietaire);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = app(EnregistrerVenteCaisseAction::class)->handle(Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 2]]));
        app(EnregistrerPaiementAction::class)->handle($facture->client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => $facture->total_ttc,
            'recu_le' => today()->toDateString(),
        ], [['facture_id' => $facture->id, 'montant' => $facture->total_ttc]]);

        $this->assertSame([StatutFacture::Payee, StatutFacture::Payee], [$ticket->fresh()->statut, $facture->fresh()->statut]);
    }

    private function etat(Societe $societe): EtatDeVerification
    {
        return app(EvaluateurDeVerification::class)->evaluer($societe->fresh());
    }

    /**
     * Les lignes de ce type du registre des vérifications de la société,
     * par ordre d'inscription.
     *
     * @return Collection<int, VerificationSociete>
     */
    private function lignes(Societe $societe, TypeVerification $type): Collection
    {
        return app(TenantContext::class)->runAsSociete($societe, fn () => VerificationSociete::query()->where('type', $type->value)->orderBy('id')->get());
    }

    /**
     * Les entrées de cette action au journal d'audit de la société.
     *
     * @return Collection<int, EntreeAudit>
     */
    private function audit(Societe $societe, ActionAuditee $action): Collection
    {
        return app(TenantContext::class)->runAsSociete($societe, fn () => EntreeAudit::query()->where('action', $action->value)->orderBy('id')->get());
    }

    /**
     * Le nombre de résultats de vérification d'identité reçus par la
     * société (les sessions seulement démarrées n'en sont pas).
     */
    private function resultatsDIdentiteRecus(Societe $societe): int
    {
        return $this->lignes($societe, TypeVerification::Identite)->reject(fn (VerificationSociete $ligne): bool => $ligne->resultat === ResultatVerification::EnAttente)->count();
    }

    /**
     * Les lettres simples parties, sans code.
     *
     * @return Collection<string, array{genre: string, destinataire: AdressePostale|array{nom: string, email: string}, objet: string, corps: list<string>}>
     */
    private function lettresSimples(): Collection
    {
        return collect($this->envois()->envois())->filter(fn (array $envoi): bool => $envoi['genre'] === FauxServiceEnvoiRecommande::LETTRE_SIMPLE);
    }

    private function envoyerUnCourrierParLEditeur(Societe $societe): CodeConfirmation
    {
        return app(EnvoyerCodeDeConfirmationAction::class)->handle($societe, MotifCode::Verification, editeur: self::editeur());
    }

    /**
     * Le propriétaire saisit ce code, ou celui du dernier pli.
     */
    private function saisirLeCode(Societe $societe, ?string $code = null): bool
    {
        return app(SaisirCodeDeConfirmationAction::class)->handle($societe->proprietaire()->firstOrFail(), $societe, $code ?? (string) $this->envois()->codeDuDernierPli());
    }

    /**
     * Le propriétaire sur la page de la vérification de sa société.
     */
    private function ouvrirLaPageDeVerification(Societe $societe, User $proprietaire): void
    {
        app(TenantContext::class)->clear();
        $this->actingAs($proprietaire);
        Filament::setCurrentPanel(Filament::getPanel('societe'));
        Filament::setTenant($societe);
        app(TenantContext::class)->poserSociete($societe);
    }

    /**
     * Une procédure de l'éditeur sur la société, lancée par l'opératrice
     * alice avec sa justification, sa pièce et le SIREN retapé.
     */
    private function procedureDeLEditeur(string $commande, Societe $societe): mixed
    {
        return $this->artisan($commande, [
            'societe' => $societe->id,
            '--justification' => 'Examen du dossier de vérification par l\'éditeur',
            '--piece' => 'DOSSIER-2026-0001',
            '--confirmer' => $societe->siren,
        ])
            ->expectsQuestion('Nom de l\'opérateur', 'alice')
            ->expectsQuestion('Secret de l\'opérateur', 'secret-d-alice');
    }

    /**
     * Un rappel du prestataire, signé de ce secret sur son horodatage et
     * son corps, sauf signature donnée.
     *
     * @param  array<string, mixed>  $corps
     */
    private function rappeler(string $route, array $corps, string $secret, ?int $horodatage = null, ?string $signature = null): TestResponse
    {
        $contenu = json_encode($corps, JSON_THROW_ON_ERROR);
        $horodatage ??= time();

        return $this->call('POST', route($route), [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Horodatage' => (string) $horodatage,
            'X-Signature' => $signature ?? RappelsDesPrestatairesController::signature($secret, (string) $horodatage, $contenu),
        ]), $contenu);
    }

    private static function editeur(): InterventionDeLEditeur
    {
        return new InterventionDeLEditeur('Examen du dossier de vérification par l\'éditeur', 'DOSSIER-2026-0001', ['alice']);
    }

    /**
     * Une ligne du registre des vérifications en mémoire.
     *
     * @param  array<string, mixed>  $details
     */
    private static function ligne(int $id, TypeVerification $type, ?SignalDeRisque $signal = null, array $details = []): VerificationSociete
    {
        return (new VerificationSociete)->forceFill([
            'id' => $id,
            'type' => $type,
            'resultat' => $type === TypeVerification::Signal ? ResultatVerification::AExaminer : ResultatVerification::Concluante,
            'signal' => $signal,
            'details' => $details,
        ]);
    }

    /**
     * La ligne de cette vérification, pour le signal essayé : le code d'un
     * courrier au siège du répertoire ou du registre national, ou d'une
     * lettre électronique, saisi ; la levée de ce signal par l'éditeur,
     * après son constat ou avant ; les pièces examinées ; la levée d'un
     * autre signal.
     */
    private static function preuve(string $verification, SignalDeRisque $signal): VerificationSociete
    {
        $autreSignal = $signal === SignalDeRisque::DecisionEditeur ? SignalDeRisque::EssaisRepetes : SignalDeRisque::DecisionEditeur;

        return match ($verification) {
            'courrier' => self::ligne(11, TypeVerification::Courrier, details: ['motif' => MotifCode::Verification->value, 'source_adresse' => AdressePostale::SOURCE_SIRENE]),
            'courrier_rne' => self::ligne(12, TypeVerification::Courrier, details: ['motif' => MotifCode::Verification->value, 'source_adresse' => AdressePostale::SOURCE_RNE]),
            'lre' => self::ligne(13, TypeVerification::Lre, details: ['motif' => MotifCode::Verification->value]),
            'manuelle' => self::ligne(14, TypeVerification::Manuelle, $signal),
            'manuelle_anterieure' => self::ligne(5, TypeVerification::Manuelle, $signal),
            'pieces' => self::ligne(15, TypeVerification::Manuelle, details: ['pieces' => ['kbis']]),
            'autre_signal' => self::ligne(16, TypeVerification::Manuelle, $autreSignal),
        };
    }

    private function rne(): FauxRne
    {
        return app(RegistreNationalDesEntreprises::class);
    }

    private function repertoire(): FauxRepertoireSirene
    {
        return app(RepertoireSirene::class);
    }

    private function identite(): FausseVerificationIdentite
    {
        return app(VerificationIdentite::class);
    }

    private function envois(): FauxServiceEnvoiRecommande
    {
        return app(ServiceEnvoiRecommande::class);
    }
}
