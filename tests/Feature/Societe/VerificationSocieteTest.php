<?php

namespace Tests\Feature\Societe;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Tenancy\Actions\AccepterLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\CreerSocieteAction;
use App\Domain\Tenancy\Actions\DeposerUnePieceAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\SuivreLesEnvoisAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifCloture;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\TypePiece;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Mail\AlerteEditeurMail;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\SiretMagasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\CourrierDeCode;
use App\Domain\Tenancy\Services\CourriersDeVerification;
use App\Domain\Tenancy\Services\DestinataireOfficiel;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\ReverificationSirene;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Domain\Tenancy\Support\QrCode;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Filament\Societe\Pages\VerificationSociete as PageDeVerification;
use App\Http\Controllers\RappelsDesPrestatairesController;
use App\Integrations\EnvoiRecommande\AdressePostale;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use App\Integrations\EnvoiRecommande\DestinataireDuCourrier;
use App\Integrations\EnvoiRecommande\FauxServiceEnvoiRecommande;
use App\Integrations\Rne\Contracts\RegistreNationalDesEntreprises;
use App\Integrations\Rne\FauxRne;
use App\Integrations\Rne\RepresentantRne;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\MagasinFactory;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La vérification d'une société par le seul courrier recommandé (voir
 * EnvoyerCodeDeConfirmationAction, SaisirCodeDeConfirmationAction et
 * ValiderLaSocieteAction) : le courrier part à la confirmation de
 * l'inscription, au nom du représentant légal inscrit au registre
 * national des entreprises, à l'adresse officielle du siège, remis contre
 * signature au destinataire en personne ; son code, unique, à usage unique
 * et valable trente jours, saisi sur la page que son QR code ouvre, valide
 * la société et fait propriétaire la personne qui le saisit. Sans code
 * trente jours après le dernier courrier, l'inscription se clôt et libère
 * le SIREN ; un seul nouveau courrier se demande, au plus tôt quinze jours
 * après le premier, puis l'éditeur traite le cas à la main, sur pièces.
 * Tous les prestataires sont les implémentations factices (voir
 * VerificationServiceProvider) : aucune requête ne part.
 *
 * La porte de validation (aucune opération commerciale avant la
 * validation) est essayée dans PorteDeValidationTest ; la concurrence des
 * saisies dans ValiderLaSocieteConcurrenceTest, celle des inscriptions
 * dans InscriptionConcurrenceTest.
 */
class VerificationSocieteTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_DES_RAPPELS_D_ENVOI = 'secret-des-rappels-d-envoi-des-tests';

    private const NOUVEAU_MOT_DE_PASSE = 'un-mot-de-passe-sur';

    /**
     * Le premier courrier part au représentant légal que donne le registre
     * national, à l'adresse officielle du siège. Le nouveau courrier relit
     * le registre le jour de son envoi : il part au représentant légal du
     * moment (le premier qui a le pouvoir de représenter l'entreprise),
     * jamais à l'adresse ni sous la raison sociale que le compte a saisies,
     * qu'il n'imprime ni ne garde.
     */
    public function test_le_courrier_part_au_representant_legal_du_registre_a_l_adresse_officielle_du_siege(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $siren = (string) $societe->siren;
        $this->assertSame(['Jeanne DUPONT', "ENTREPRISE {$siren}", '1 RUE DE LA REPUBLIQUE', '75001 PARIS'], collect($this->envois()->envois())->sole()['destinataire']->lignes());
        app(TenantContext::class)->runAsSociete($societe, fn (): bool => $societe->forceFill(['raison_sociale' => 'Communiquez ce code au 06 00 00 00 00', 'siege_adresse' => '99 RUE DES USURPATEURS', 'siege_code_postal' => '13001', 'siege_ville' => 'MARSEILLE'])->save());
        $this->rne()->dirigeants($siren, [
            new RepresentantRne('MARTIN', null, ['Paul'], '1975-02', 'Directeur général délégué', false, null, '2021-01-01'),
            new RepresentantRne('BERNARD', 'LEROY', ['Claire', 'Anne'], '1982-06', 'Présidente', true, null, '2026-09-01'),
        ]);
        $this->travelTo(self::aParis('2026-10-20 10:00:00'));

        $second = app(EnvoyerCodeDeConfirmationAction::class)->nouvelEnvoi($societe->proprietaire, $societe->fresh());

        $envoi = $this->envois()->envois()[$second->reference_envoi];
        $this->assertSame(['Claire LEROY', "ENTREPRISE {$siren}", '1 RUE DE LA REPUBLIQUE', '75001 PARIS'], $envoi['destinataire']->lignes());
        $this->assertSame($envoi['destinataire']->enTableau(), $second->destinataire);
        $this->assertTrue($envoi['remise_en_personne']);
        $texte = $this->texteDuPdf($envoi['pdf']);
        $this->assertStringContainsString('Claire LEROY', $texte);
        foreach (['06 00 00 00 00', 'USURPATEURS', 'MARSEILLE'] as $saisi) {
            $this->assertStringNotContainsString($saisi, $texte);
            $this->assertStringNotContainsString($saisi, json_encode(DB::table('codes_confirmation')->get(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Une personne morale dirigeante reçoit le courrier à son nom, « à
     * l'attention de son représentant » ; le propriétaire de l'inscription
     * porte ce nom tant qu'il ne le change pas dans son profil.
     */
    public function test_une_personne_morale_dirigeante_recoit_le_courrier_a_l_attention_de_son_representant(): void
    {
        $siren = SocieteFactory::sirenFictif();
        $this->rne()->dirigeants($siren, [new RepresentantRne('HOLDING DUPONT', null, [], null, 'Présidente', true, '123456782', '2020-01-01')]);

        $societe = $this->inscrireUneSociete($siren);

        $envoi = collect($this->envois()->envois())->sole();
        $this->assertSame(['HOLDING DUPONT', DestinataireDuCourrier::A_L_ATTENTION_DE_SON_REPRESENTANT, "ENTREPRISE {$siren}", '1 RUE DE LA REPUBLIQUE', '75001 PARIS'], $envoi['destinataire']->lignes());
        $this->assertStringContainsString(DestinataireDuCourrier::A_L_ATTENTION_DE_SON_REPRESENTANT, $this->texteDuPdf($envoi['pdf']));
        $this->assertSame('HOLDING DUPONT', $societe->proprietaire->name);
    }

    /**
     * Sans adresse du siège au registre national, le courrier part au
     * siège que donne le répertoire Sirene ; quand le répertoire la masque
     * aussi (diffusion partielle), aucun courrier ne part et aucune
     * inscription n'est créée : l'adresse personnelle d'un dirigeant, ou
     * une adresse saisie, ne sert jamais.
     */
    public function test_sans_adresse_au_registre_le_courrier_part_au_siege_du_repertoire_et_sinon_aucun_ne_part(): void
    {
        $siren = SocieteFactory::sirenFictif();
        $this->rne()->domicilier($siren, null);

        $this->inscrireUneSociete($siren);

        $destinataire = collect($this->envois()->envois())->sole()['destinataire'];
        $this->assertSame([AdressePostale::SOURCE_SIRENE, ['Jeanne DUPONT', "SOCIETE FICTIVE {$siren}", '1 RUE DE LA REPUBLIQUE', '75001 PARIS']], [$destinataire->adresse->source, $destinataire->lignes()]);

        $partielle = FauxRepertoireSirene::SIREN_DIFFUSION_PARTIELLE;
        $this->rne()->domicilier($partielle, null);
        $this->assertThrows(
            fn () => $this->inscrireUneSociete($partielle),
            fn (ValidationException $refus): bool => $refus->errors() === ['siren' => [DestinataireOfficiel::MESSAGE_AUCUNE_ADRESSE]],
        );
        $this->assertCount(1, $this->envois()->envois());
        $this->assertFalse(Societe::sirenPris($partielle));
    }

    /**
     * Le courrier est une vraie page A4 en portrait : le destinataire, la
     * mention de la remise en personne, le SIREN, le code, sa date de fin
     * de validité et l'adresse courte de la page du code. Son QR code est
     * celui de l'adresse de cette page, le SIREN déjà rempli, construite
     * depuis APP_URL : il ne porte jamais le code.
     */
    public function test_le_courrier_est_une_page_a4_avec_le_code_l_adresse_courte_et_un_qr_code_qui_n_ouvre_que_la_page(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $siren = (string) $societe->siren;
        $envoi = collect($this->envois()->envois())->sole();
        $code = $this->codeDuCourrier($societe);

        $this->assertSame(['0.000 0.000 595.280 841.890'], array_column($this->pagesDuPdf($envoi['pdf']), 'format'));
        $texte = $this->texteDuPdf($envoi['pdf']);
        foreach (['Jeanne DUPONT', 'LETTRE RECOMMANDÉE AVEC AVIS DE RÉCEPTION', "SIREN {$siren}", $code, '04/11/2026', CourrierDeCode::adresseCourte()] as $attendu) {
            $this->assertStringContainsString($attendu, $texte);
        }

        $adresse = CourrierDeCode::adresseDeLaPage($siren);
        $this->assertSame(rtrim((string) config('app.url'), '/')."/code/{$siren}", $adresse);
        $this->assertSame(rtrim(preg_replace('#^https?://#', '', (string) config('app.url')), '/').'/code', CourrierDeCode::adresseCourte());
        $html = app(CourrierDeCode::class)->html($envoi['destinataire'], $siren, MotifCode::Verification, $code, self::aParis('2026-11-04 10:00:00'));
        $this->assertStringContainsString('src="'.QrCode::uriDeDonnees($adresse).'"', $html);
        $this->assertSame(QrCode::svg($adresse), base64_decode(substr(QrCode::uriDeDonnees($adresse), strlen('data:image/svg+xml;base64,')), true));
        $this->assertStringNotContainsString(str_replace('-', '', $code), $adresse);
    }

    /**
     * Le courrier dit une seule fois le jour et l'heure (heure de Paris)
     * jusqu'où son code vaut, et que l'inscription se clôt sans code saisi
     * d'ici là : le dernier jour, le code vaut jusqu'à cette heure.
     */
    public function test_le_code_vaut_jusqu_au_jour_et_a_l_heure_que_le_courrier_imprime(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $code = $this->codeDuCourrier($societe);
        $actif = $this->codeActif($societe);

        $html = app(CourrierDeCode::class)->html(DestinataireDuCourrier::depuisTableau($actif->destinataire), (string) $societe->siren, MotifCode::Verification, $code, $actif->expire_le);

        $this->assertStringContainsString('Ce code est valable jusqu\'au 04/11/2026 à 10:00 (heure de Paris) et ne sert qu\'une fois.', $html);
        $this->assertStringContainsString('Sans code saisi d\'ici là, l\'inscription sera close.', $html);
        $this->assertSame(1, substr_count($html, '04/11/2026'));

        $this->travelTo(self::aParis('2026-11-04 09:59:59'));
        $this->saisirCommeLInscrit($societe, $code);

        $this->assertTrue($societe->fresh()->estValidee());
    }

    /**
     * Le code (12 caractères de l'alphabet de Crockford) ne se garde que
     * haché, nulle part en clair ; la base n'admet qu'un code actif par
     * SIREN et par motif ; il vaut trente jours après son envoi ; sa
     * saisie ignore la casse, les espaces et les tirets ; il ne sert
     * qu'une fois.
     */
    public function test_le_code_est_hache_unique_valable_trente_jours_et_a_usage_unique(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $code = $this->codeDuCourrier($societe);
        $actif = $this->codeActif($societe);

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $code);
        $base = json_encode(collect(Schema::getTableListing())->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->get()])->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(str_replace('-', '', $code), $base);
        $this->assertStringNotContainsString($code, $base);
        $this->assertTrue(Hash::check(str_replace('-', '', $code), $actif->code_hache));
        $this->assertMemeInstant(self::aParis('2026-11-04 10:00:00'), $actif->expire_le);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => app(TenantContext::class)->runAsSociete($societe, fn (): bool => $actif->replicate()->save())), UniqueConstraintViolationException::class);

        $this->travelTo(self::aParis('2026-11-04 10:00:00'));
        $this->assertThrows(fn () => $this->saisirCommeLInscrit($societe, $code), fn (ValidationException $refus): bool => $refus->errors() === ['code' => [SaisirCodeDeConfirmationAction::MESSAGE_CODE_FAUX]]);

        $autre = $this->inscrireUneSociete();
        $codeDeLAutre = $this->codeDuCourrier($autre);
        $this->saisirCommeLInscrit($autre, strtolower(str_replace('-', ' ', $codeDeLAutre)));
        $this->assertTrue($autre->fresh()->estValidee());
        $this->assertThrows(fn () => $this->saisirCommeLInscrit($autre, $codeDeLAutre), fn (ValidationException $refus): bool => $refus->errors() === ['code' => [SaisirCodeDeConfirmationAction::MESSAGE_CODE_FAUX]]);
        $this->assertSame(1, $this->lignes($autre, TypeVerification::Courrier)->count());
    }

    /**
     * Un code de courrier prouve l'envoi et la remise : son suivi se pose,
     * mais la base refuse de l'effacer tant que la fin de sa conservation
     * n'est pas fixée, ou pas passée ; ensuite, il s'efface.
     */
    public function test_un_code_de_courrier_ne_s_efface_qu_apres_la_fin_de_sa_conservation(): void
    {
        $code = $this->codeActif($this->inscrireUneSociete());
        $effacer = $this->dansUnPointDeSauvegarde(fn (): int => DB::table('codes_confirmation')->where('id', $code->id)->delete());
        $conserverJusquAu = fn (CarbonImmutable $fin): int => DB::table('codes_confirmation')->where('id', $code->id)->update(['conserver_jusqu_au' => $fin->utc()->format('Y-m-d H:i:s')]);

        $this->assertThrows($effacer, QueryException::class, 'codes_confirmation : suppression refusée avant la fin de la conservation');
        $this->assertSame(1, $conserverJusquAu($this->maintenantSelonLaBase()->addHours(24)));
        $this->assertThrows($effacer, QueryException::class, 'codes_confirmation : suppression refusée avant la fin de la conservation');
        $this->assertSame(1, $conserverJusquAu($this->maintenantSelonLaBase()->subMinute()));

        $this->assertSame(1, $effacer());
    }

    /**
     * La page du code limite les saisies : au-delà de dix par heure depuis
     * une même adresse IP, même le bon code est refusé ; depuis une autre
     * adresse, il valide la société.
     */
    public function test_la_page_du_code_limite_les_saisies_par_adresse_ip(): void
    {
        $societe = $this->inscrireUneSociete();
        $code = $this->codeDuCourrier($societe);

        foreach (range(1, SaisirCodeDeConfirmationAction::SAISIES_PAR_HEURE_ET_PAR_ADRESSE_IP) as $essai) {
            $this->assertThrows(fn () => $this->saisirCommeLInscrit($societe, self::codeFaux($code), '203.0.113.7'), fn (ValidationException $refus): bool => $refus->errors() === ['code' => [SaisirCodeDeConfirmationAction::MESSAGE_CODE_FAUX]]);
        }
        $this->assertThrows(fn () => $this->saisirCommeLInscrit($societe, $code, '203.0.113.7'), fn (ValidationException $refus): bool => $refus->errors() === ['code' => [SaisirCodeDeConfirmationAction::MESSAGE_TROP_DE_SAISIES]]);
        $this->assertFalse($societe->fresh()->estValidee());

        $this->saisirCommeLInscrit($societe, $code, '198.51.100.4');

        $this->assertTrue($societe->fresh()->estValidee());
    }

    /**
     * Une saisie fausse se compte sur le code et s'inscrit au journal
     * d'audit de la société, sans jamais annuler le courrier : un tiers qui
     * essaie des codes ne prive pas le représentant légal du sien, qui
     * valide ensuite la société.
     */
    public function test_un_code_faux_se_compte_et_s_inscrit_sans_jamais_annuler_le_courrier(): void
    {
        $societe = $this->inscrireUneSociete();
        $code = $this->codeDuCourrier($societe);

        foreach (range(1, 3) as $essai) {
            $this->assertThrows(fn () => $this->saisirCommeLInscrit($societe, self::codeFaux($code)), ValidationException::class);
        }

        $actif = $this->codeActif($societe);
        $this->assertSame([3, null, null], [$actif->tentatives, $actif->annule_le, $actif->utilise_le]);
        $this->assertSame([['faux', 1], ['faux', 2], ['faux', 3]], $this->audit($societe, ActionAuditee::CodeSaisi)->map(fn (EntreeAudit $entree): array => [$entree->nouvelles_valeurs['resultat'], $entree->nouvelles_valeurs['tentatives']])->all());

        $this->saisirCommeLInscrit($societe, $code);

        $this->assertTrue($societe->fresh()->estValidee());
        $this->assertSame(['juste', 4], [$this->audit($societe, ActionAuditee::CodeSaisi)->last()->nouvelles_valeurs['resultat'], $this->audit($societe, ActionAuditee::CodeSaisi)->last()->nouvelles_valeurs['tentatives']]);
    }

    /**
     * Un SIREN mal formé, inconnu, sans courrier en cours (société déjà
     * validée, inscription close) reçoit exactement la réponse d'un code
     * faux : la page ne dit rien de plus.
     */
    public function test_un_siren_inconnu_sans_courrier_ou_mal_forme_recoit_la_meme_reponse_qu_un_code_faux(): void
    {
        $enCours = $this->inscrireUneSociete();
        $code = $this->codeDuCourrier($enCours);
        $validee = $this->inscrireUneSociete();
        $codeDeLaValidee = $this->codeDuCourrier($validee);
        $this->saisirCommeLInscrit($validee, $codeDeLaValidee);
        $close = $this->inscrireUneSociete();
        $codeDeLaClose = $this->codeDuCourrier($close);
        app(TenantContext::class)->clear();
        $this->refuserParLEditeur($close);
        $memeReponse = fn (ValidationException $refus): bool => $refus->errors() === ['code' => [SaisirCodeDeConfirmationAction::MESSAGE_CODE_FAUX]];

        foreach ([['12345', $code], [SocieteFactory::sirenFictif(), $code], [(string) $validee->siren, $codeDeLaValidee], [(string) $close->siren, $codeDeLaClose], [(string) $enCours->siren, self::codeFaux($code)]] as [$siren, $saisie]) {
            $this->assertThrows(fn () => app(SaisirCodeDeConfirmationAction::class)->handle($siren, $saisie, 'quelquun@exemple.fr', self::NOUVEAU_MOT_DE_PASSE, self::NOUVEAU_MOT_DE_PASSE, '192.0.2.10'), $memeReponse);
        }
        $this->assertSame([false, true, false], [$enCours->fresh()->estValidee(), $validee->fresh()->estValidee(), $close->fresh()->estValidee()]);
    }

    /**
     * La personne qui a inscrit la société saisit le code, avec l'adresse
     * et le mot de passe de son inscription : la société est validée, le
     * contrôle du courrier s'inscrit au registre des vérifications, la
     * validation au journal d'audit avec sa preuve, et tous les comptes déjà
     * créés restent ; elle signe les attestations dans l'application.
     */
    public function test_la_personne_inscrite_qui_saisit_le_code_valide_la_societe_et_garde_ses_comptes(): void
    {
        $societe = $this->inscrireUneSociete();
        $magasin = $this->creerLeMagasinDuSiege($societe);
        $administrateur = $this->administrateur($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $inscrit = $societe->proprietaire()->sole();

        $proprietaire = $this->saisirLeCodeDuCourrier($societe);

        $this->assertTrue($proprietaire->is($inscrit));
        $this->assertTrue($societe->fresh()->estValidee());
        foreach ([$inscrit, $administrateur, $vendeur] as $compte) {
            $this->assertSame(StatutCompte::Actif, $compte->refresh()->statut);
        }
        $courrier = $this->lignes($societe, TypeVerification::Courrier)->sole();
        $this->assertSame([ResultatVerification::Concluante, 'recommande', ['siren', 'envoi_id', 'motif', 'source_adresse', 'reference_envoi']], [$courrier->resultat, $courrier->fournisseur, array_keys($courrier->details)]);
        $validation = $this->audit($societe, ActionAuditee::SocieteValidee)->sole();
        $this->assertSame(['code_du_courrier', $inscrit->id, [$magasin->siret]], [$validation->nouvelles_valeurs['preuve'], $validation->nouvelles_valeurs['proprietaire']['id'], $validation->nouvelles_valeurs['sirets']]);
        $this->assertTrue($inscrit->refresh()->signeLesAttestationsDansLApplication());
        $this->assertSame('Votre société est validée', $inscrit->notifications()->sole()->data['title']);
    }

    /**
     * Une autre personne que celle qui a inscrit la société saisit le code
     * : elle choisit son adresse et son mot de passe et devient
     * propriétaire, par un nouveau compte au nom du représentant légal du
     * courrier, avec son identifiant, son adresse attendant la confirmation
     * de son lien (voir AdresseDeConnexionDuProprietaireTest) ; tous les
     * comptes créés avant, celui de la personne inscrite compris (devenue
     * administrateur), sont retirés de la société par elle, leurs sessions
     * fermées.
     */
    public function test_une_autre_personne_qui_saisit_le_code_devient_proprietaire_et_les_comptes_d_avant_sont_retires(): void
    {
        $societe = $this->inscrireUneSociete(null, 'inscrit@exemple.fr');
        $magasin = $this->creerLeMagasinDuSiege($societe);
        $inscrit = $societe->proprietaire()->sole();
        $administrateur = $this->administrateur($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        foreach ([$inscrit, $administrateur, $vendeur] as $compte) {
            $this->ouvrirUneSessionAilleurs($compte);
        }
        $code = $this->codeDuCourrier($societe);
        app(TenantContext::class)->clear();

        $nouveau = app(SaisirCodeDeConfirmationAction::class)->handle((string) $societe->siren, $code, 'Representante@Exemple.FR', self::NOUVEAU_MOT_DE_PASSE, self::NOUVEAU_MOT_DE_PASSE, '127.0.0.1');

        $this->assertSame([null, 'representante@exemple.fr', RoleSociete::Proprietaire, 'Jeanne DUPONT', StatutCompte::Actif], [$nouveau->email, $nouveau->nouvelle_adresse, $nouveau->role_societe, $nouveau->name, $nouveau->statut]);
        $this->assertNotSame($inscrit->id, $nouveau->id);
        $this->assertMatchesRegularExpression('/^[1-9][0-9]{7}$/D', (string) $nouveau->identifiantDeConnexion());
        $this->assertTrue(Hash::check(self::NOUVEAU_MOT_DE_PASSE, $nouveau->password));
        $this->assertTrue($societe->fresh()->estValidee());
        $this->assertTrue($nouveau->signeLesAttestationsDansLApplication());
        foreach ([$inscrit, $administrateur, $vendeur] as $compte) {
            $compte->refresh();
            $this->assertSame([StatutCompte::Retire, $nouveau->id], [$compte->statut, (int) $compte->statut_change_par]);
            $this->assertSessionsFermees($compte);
        }
        $this->assertSame([RoleSociete::Administrateur, null], [$inscrit->role_societe, $inscrit->email_verified_at]);
        $this->assertSame([$nouveau->id], User::query()->where('societe_id', $societe->id)->where('role_societe', RoleSociete::Proprietaire->value)->pluck('id')->all());
        $this->assertCount(1, $this->audit($societe, ActionAuditee::ProprieteReprise));
        $this->assertSame($nouveau->id, $this->audit($societe, ActionAuditee::SocieteValidee)->sole()->nouvelles_valeurs['proprietaire']['id']);
    }

    /**
     * L'adresse de l'inscription saisie avec un autre mot de passe est
     * refusée, sans rien changer : le code reste actif, ni utilisé ni
     * compté, et personne ne devient propriétaire.
     */
    public function test_l_adresse_de_l_inscription_avec_un_autre_mot_de_passe_est_refusee_sans_rien_changer(): void
    {
        $societe = $this->inscrireUneSociete();
        $code = $this->codeDuCourrier($societe);
        $inscrit = $societe->proprietaire()->sole();
        app(TenantContext::class)->clear();

        $this->assertThrows(
            fn () => app(SaisirCodeDeConfirmationAction::class)->handle((string) $societe->siren, $code, (string) $inscrit->email, 'devine-au-hasard', 'devine-au-hasard', '127.0.0.1'),
            fn (ValidationException $refus): bool => $refus->errors() === ['password' => [SaisirCodeDeConfirmationAction::MESSAGE_MOT_DE_PASSE_DE_L_INSCRIPTION]],
        );

        $actif = $this->codeActif($societe);
        $this->assertSame([false, null, 0], [$societe->fresh()->estValidee(), $actif->utilise_le, $actif->tentatives]);
        $this->assertSame([$inscrit->id], User::query()->where('societe_id', $societe->id)->pluck('id')->all());
    }

    /**
     * Une autre personne choisit un mot de passe qui suit la règle du mot
     * de passe choisi, confirmé, et une adresse qui n'est l'adresse de
     * connexion d'aucun autre propriétaire : sinon rien ne change et le
     * code reste actif.
     */
    public function test_une_autre_personne_choisit_un_mot_de_passe_conforme_et_une_adresse_libre(): void
    {
        $societe = $this->inscrireUneSociete();
        $code = $this->codeDuCourrier($societe);
        $autreProprietaire = $this->inscrireUneSociete()->proprietaire()->sole();
        app(TenantContext::class)->clear();
        $saisir = fn (string $email, string $motDePasse, string $confirmation) => fn () => app(SaisirCodeDeConfirmationAction::class)->handle((string) $societe->siren, $code, $email, $motDePasse, $confirmation, '127.0.0.1');

        $this->assertThrows($saisir('nouvelle@exemple.fr', '12345678', '12345678'), fn (ValidationException $refus): bool => array_keys($refus->errors()) === ['password']);
        $this->assertThrows($saisir('nouvelle@exemple.fr', self::NOUVEAU_MOT_DE_PASSE, 'un-autre-mot-de-passe'), fn (ValidationException $refus): bool => array_keys($refus->errors()) === ['password']);
        $this->assertThrows($saisir((string) $autreProprietaire->email, self::NOUVEAU_MOT_DE_PASSE, self::NOUVEAU_MOT_DE_PASSE), fn (ValidationException $refus): bool => $refus->errors() === ['email' => [CreerSocieteAction::MESSAGE_ADRESSE_INUTILISABLE]]);

        $actif = $this->codeActif($societe);
        $this->assertSame([false, null], [$societe->fresh()->estValidee(), $actif->utilise_le]);
        $this->assertSame(1, User::query()->where('societe_id', $societe->id)->count());
    }

    /**
     * La page du code s'ouvre, sans être connecté, par l'adresse que porte
     * le QR code, le SIREN déjà rempli. Une saisie refusée y revient avec
     * son motif, le SIREN et l'adresse gardés, jamais le code ni le mot de
     * passe ; le bon code connecte le propriétaire et le mène au tableau de
     * bord de sa société. La page ne transmet pas son adresse.
     */
    public function test_la_page_du_code_s_ouvre_par_le_qr_code_avec_le_siren_rempli_et_connecte_le_proprietaire(): void
    {
        $societe = $this->inscrireUneSociete();
        $code = $this->codeDuCourrier($societe);
        $proprietaire = $societe->proprietaire()->sole();
        app(TenantContext::class)->clear();

        $this->get(CourrierDeCode::adresseDeLaPage((string) $societe->siren))
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('value="'.$societe->siren.'"', false);

        $this->post(route('code.saisir'), ['siren' => $societe->siren, 'code' => self::codeFaux($code), 'email' => $proprietaire->email, 'password' => self::MOT_DE_PASSE_CHOISI])
            ->assertRedirect(route('code.afficher'))
            ->assertSessionHasErrors(['code' => SaisirCodeDeConfirmationAction::MESSAGE_CODE_FAUX]);
        $this->assertSame(['siren', 'email'], array_keys(session('_old_input')));
        $this->assertGuest();

        $this->post(route('code.saisir'), ['siren' => $societe->siren, 'code' => $code, 'email' => $proprietaire->email, 'password' => self::MOT_DE_PASSE_CHOISI])
            ->assertRedirect(TableauDeBord::getUrl(panel: 'societe', tenant: $societe))
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertAuthenticatedAs($proprietaire);
        $this->assertTrue($societe->fresh()->estValidee());
    }

    /**
     * Le courrier d'une procédure de l'éditeur (ici, la récupération du
     * compte du propriétaire) mène à la page de son motif, que son QR code
     * ouvre avec le SIREN rempli : la page du code de la vérification ne
     * reconnaît pas son code, et ne le compte pas ; sur la sienne, un code
     * faux se compte, et le bon sert une seule fois, prouve la remise du
     * courrier au registre des vérifications avec son motif et prévient
     * l'éditeur, sans rien valider, reprendre ni connecter.
     */
    public function test_le_code_d_un_courrier_de_l_editeur_se_saisit_sur_la_page_de_son_motif_et_previent_l_editeur(): void
    {
        Mail::fake();
        config(['editeur.email' => 'editeur@piliqi.test']);
        $societe = $this->inscrireUneSociete();
        $proprietaire = $this->saisirLeCodeDuCourrier($societe);
        $siren = (string) $societe->siren;
        app(TenantContext::class)->clear();
        $courrier = app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->fresh(), MotifCode::Recuperation, self::editeur());
        $code = $this->codeDuCourrier($societe, MotifCode::Recuperation);
        $relu = fn (): CodeConfirmation => app(TenantContext::class)->runAsSociete($societe, fn (): CodeConfirmation => CodeConfirmation::query()->findOrFail($courrier->id));
        $objet = ['objet' => MotifCode::Recuperation->value];

        $adresse = CourrierDeCode::adresseDeLaPage($siren, MotifCode::Recuperation);
        $this->assertSame(rtrim((string) config('app.url'), '/')."/code/recuperation/{$siren}", $adresse);
        $this->assertStringContainsString(CourrierDeCode::adresseCourte(MotifCode::Recuperation), $this->texteDuPdf($this->envois()->envois()[$courrier->reference_envoi]['pdf']));
        $html = app(CourrierDeCode::class)->html(DestinataireDuCourrier::depuisTableau($courrier->destinataire), $siren, MotifCode::Recuperation, $code, $courrier->expire_le);
        $this->assertStringContainsString('src="'.QrCode::uriDeDonnees($adresse).'"', $html);
        $this->assertStringContainsString('Une fois ce code saisi, l\'éditeur poursuit la procédure et vous contacte.', $html);
        $this->assertStringNotContainsString('devient propriétaire', $html);

        $this->post(route('code.saisir'), ['siren' => $siren, 'code' => $code, 'email' => $proprietaire->email, 'password' => self::MOT_DE_PASSE_CHOISI])
            ->assertRedirect(route('code.afficher'))
            ->assertSessionHasErrors(['code' => SaisirCodeDeConfirmationAction::MESSAGE_CODE_FAUX]);
        $this->assertSame([0, null], [$relu()->tentatives, $relu()->utilise_le]);

        $this->get($adresse)
            ->assertOk()
            ->assertSee(MotifCode::Recuperation->getLabel())
            ->assertSee('value="'.$siren.'"', false)
            ->assertDontSee('name="email"', false);
        $this->post(route('code.procedure.saisir', $objet), ['siren' => $siren, 'code' => self::codeFaux($code)])
            ->assertRedirect(route('code.procedure.afficher', $objet))
            ->assertSessionHasErrors(['code' => SaisirCodeDeConfirmationAction::MESSAGE_CODE_FAUX]);
        $this->assertSame(1, $relu()->tentatives);

        $this->post(route('code.procedure.saisir', $objet), ['siren' => $siren, 'code' => $code])
            ->assertRedirect(route('code.procedure.afficher', $objet))
            ->assertSessionHas('code_reconnu', true);

        $this->assertGuest();
        $this->assertNotNull($relu()->utilise_le);
        $ligne = $this->lignes($societe, TypeVerification::Courrier)->last();
        $this->assertSame([ResultatVerification::Concluante, MotifCode::Recuperation->value, $courrier->reference_envoi], [$ligne->resultat, $ligne->details['motif'], $ligne->reference]);
        $this->assertSame([MotifCode::Recuperation->value, 'juste', 2], array_values(collect($this->audit($societe, ActionAuditee::CodeSaisi)->last()->nouvelles_valeurs)->only(['motif', 'resultat', 'tentatives'])->all()));
        Mail::assertSent(AlerteEditeurMail::class, fn (AlerteEditeurMail $mail): bool => $mail->objet === 'Code d\'un courrier de l\'éditeur saisi');
        $this->assertSame([[$proprietaire->id, RoleSociete::Proprietaire, StatutCompte::Actif]], User::query()->where('societe_id', $societe->id)->get()->map(fn (User $compte): array => [$compte->id, $compte->role_societe, $compte->statut])->all());
        $this->post(route('code.procedure.saisir', $objet), ['siren' => $siren, 'code' => $code])
            ->assertSessionHasErrors(['code' => SaisirCodeDeConfirmationAction::MESSAGE_CODE_FAUX]);
    }

    /**
     * La validation suit la preuve, sans auteur : le propriétaire connecté
     * qui saisit le code ne signe pas les droits implicites qu'elle inscrit
     * au journal des événements de son magasin ; le système les signe.
     */
    public function test_la_validation_s_inscrit_aux_journaux_des_evenements_au_nom_du_systeme(): void
    {
        $societe = $this->inscrireUneSociete();
        $this->creerLeMagasinDuSiege($societe);
        $this->actingAs($societe->proprietaire);
        $dejaInscrits = EnregistrementFiscal::withoutGlobalScopes()->max('id') ?? 0;

        $this->saisirLeCodeDuCourrier($societe);

        $this->assertTrue($societe->fresh()->estValidee());
        $inscrits = EnregistrementFiscal::withoutGlobalScopes()
            ->whereIn('magasin_id', Magasin::query()->where('societe_id', $societe->id)->select('id'))
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('id', '>', $dejaInscrits)
            ->get();
        $this->assertContains((string) CodeEvenement::ModificationDesDroits->value, $inscrits->pluck('type')->map(fn (mixed $type): string => (string) $type)->all());
        $this->assertSame([null], $inscrits->pluck('operateur_id')->unique()->values()->all());
    }

    /**
     * Rien n'est réservé avant la validation ; elle réserve le SIRET de
     * chaque magasin réel actif, ouvre sa période d'activité et inscrit les
     * droits du propriétaire au journal des événements de chacun. Un
     * magasin dont l'établissement attend encore la confirmation du
     * répertoire Sirene ne retient pas la validation : il est mis en
     * activité, mais ne vend qu'après la confirmation de son établissement.
     */
    public function test_la_validation_met_en_activite_les_magasins_reels_actifs_et_un_etablissement_a_confirmer_ne_vend_qu_apres(): void
    {
        $societe = $this->inscrireUneSociete();
        $premier = $this->creerLeMagasinDuSiege($societe);
        $this->repertoire()->rendreInjoignable();
        $aConfirmer = app(CreerEtablissementAction::class)->handle($societe->proprietaire, $societe, ['nom' => 'Second magasin', 'nic' => MagasinFactory::nicLibre($societe)]);
        $this->repertoire()->rendreInjoignable(false);
        app(TenantContext::class)->clear();
        $this->assertSame([0, 0], [SiretMagasin::query()->count(), PeriodeActivite::query()->count()]);

        $this->saisirLeCodeDuCourrier($societe);

        $this->assertTrue($societe->fresh()->estValidee());
        $magasins = collect([$premier, $aConfirmer]);
        $this->assertEqualsCanonicalizing(
            $magasins->map(fn (Magasin $magasin): array => [$magasin->id, $magasin->siret])->all(),
            SiretMagasin::query()->where('societe_id', $societe->id)->whereNull('retire_le')->whereNull('libere_le')->get()->map(fn (SiretMagasin $reserve): array => [$reserve->magasin_id, $reserve->siret])->all(),
        );
        foreach ($magasins as $magasin) {
            $this->assertNull(PeriodeActivite::query()->where('magasin_id', $magasin->id)->sole()->fin);
            $this->assertSame(1, EnregistrementFiscal::withoutGlobalScope(MagasinScope::class)->where('magasin_id', $magasin->id)->where('chaine', ChaineFiscale::Evenement->value)->where('type', (string) CodeEvenement::ModificationDesDroits->value)->count());
        }
        $this->assertSame([true, false], [$premier->fresh()->peutVendre(), $aConfirmer->fresh()->peutVendre()]);

        app(ReverificationSirene::class)->reprendre($societe->fresh(), false);

        $this->assertTrue($aConfirmer->fresh()->peutVendre());
    }

    /**
     * Une société se valide sans aucun magasin : aucun SIRET ne se
     * réserve, et aucune période d'activité ne s'ouvre hors de sa
     * démonstration, qui se ferme à la validation : aucun magasin ne peut
     * alors vendre. Son premier magasin, créé ensuite, se met aussitôt en
     * activité.
     */
    public function test_la_societe_se_valide_sans_aucun_magasin_et_ne_vend_rien_avant_son_premier_magasin(): void
    {
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe);

        $this->saisirLeCodeDuCourrier($societe);

        $this->assertTrue($societe->fresh()->estValidee());
        $this->assertSame([0, 0], [$societe->magasinsReels()->count(), SiretMagasin::query()->count()]);
        $this->assertSame([$demonstration->id], array_map(intval(...), PeriodeActivite::query()->pluck('magasin_id')->unique()->values()->all()));
        $this->assertSame(StatutMagasin::Ferme, $demonstration->fresh()->statut);
        $this->assertFalse($proprietaire->refresh()->magasinsAccessibles()->contains(fn (Magasin $magasin): bool => $magasin->peutVendre()));

        $premier = $this->creerLeMagasinDuSiege($societe->fresh());

        $this->assertSame([$premier->id], SiretMagasin::query()->pluck('magasin_id')->all());
        $this->assertNull(PeriodeActivite::query()->where('magasin_id', $premier->id)->sole()->fin);
        $this->assertTrue($premier->fresh()->peutVendre());
    }

    /**
     * Un magasin réel fermé avant la validation ne compte pas : la société
     * est validée, et lui n'est ni réservé ni ouvert.
     */
    public function test_un_magasin_ferme_avant_la_validation_n_est_ni_reserve_ni_ouvert(): void
    {
        $societe = $this->inscrireUneSociete();
        $ouvert = $this->creerLeMagasinDuSiege($societe);
        $ferme = app(CreerEtablissementAction::class)->handle($societe->proprietaire, $societe, ['nom' => 'Fermé', 'nic' => MagasinFactory::nicLibre($societe)]);
        $ferme->forceFill(['statut' => StatutMagasin::Ferme])->save();
        app(TenantContext::class)->clear();

        $this->saisirLeCodeDuCourrier($societe);

        $this->assertTrue($societe->fresh()->estValidee());
        $this->assertSame([$ouvert->id], SiretMagasin::query()->pluck('magasin_id')->all());
        $this->assertSame(0, PeriodeActivite::query()->where('magasin_id', $ferme->id)->count());
    }

    /**
     * Sans code saisi trente jours après son courrier, l'inscription se
     * clôt au passage horaire qui suit (pas avant) : la société reste,
     * close, ses magasins en lecture seule ; son code s'annule, sa
     * démonstration se ferme, son propriétaire est prévenu, la clôture
     * s'inscrit au journal d'audit, et le SIREN est libéré.
     */
    public function test_sans_code_trente_jours_apres_le_courrier_l_inscription_se_clot_et_libere_le_siren(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $magasin = $this->creerLeMagasinDuSiege($societe);
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe->fresh());

        $this->travelTo(self::aParis('2026-11-04 09:59:00'));
        $this->artisan('app:clore-inscriptions-sans-code')->expectsOutput('0 inscription(s) close(s).')->assertSuccessful();
        $this->assertTrue($societe->fresh()->attendSonCode());

        $this->travelTo(self::aParis('2026-11-04 10:00:00'));
        $this->artisan('app:clore-inscriptions-sans-code')->expectsOutput('1 inscription(s) close(s).')->assertSuccessful();

        $close = $societe->fresh();
        $this->assertSame([true, MotifCloture::DelaiExpire, false], [$close->estClose(), $close->motif_cloture, $close->estValidee()]);
        $this->assertFalse(Societe::sirenPris((string) $societe->siren));
        $this->assertNotNull($this->codeDuSiren($societe)->annule_le);
        $this->assertSame(StatutMagasin::Ferme, $demonstration->fresh()->statut);
        $this->assertTrue($magasin->fresh()->estEnLectureSeule());
        $this->assertSame('L\'inscription de votre société est close', $societe->proprietaire->notifications()->sole()->data['title']);
        $this->assertSame(['motif' => 'delai_expire'], $this->audit($societe, ActionAuditee::InscriptionClose)->sole()->nouvelles_valeurs);
    }

    /**
     * Le nouveau courrier repousse la clôture : l'inscription garde son
     * SIREN trente jours après son dernier courrier, pas après le premier.
     */
    public function test_un_nouveau_courrier_repousse_la_cloture_a_trente_jours_apres_lui(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $this->travelTo(self::aParis('2026-10-20 10:00:00'));
        app(EnvoyerCodeDeConfirmationAction::class)->nouvelEnvoi($societe->proprietaire, $societe);

        $this->travelTo(self::aParis('2026-11-19 09:00:00'));
        $this->artisan('app:clore-inscriptions-sans-code')->assertSuccessful();
        $this->assertTrue($societe->fresh()->attendSonCode());

        $this->travelTo(self::aParis('2026-11-19 10:00:00'));
        $this->artisan('app:clore-inscriptions-sans-code')->assertSuccessful();
        $this->assertTrue($societe->fresh()->estClose());
    }

    /**
     * La clôture horaire ne touche que les inscriptions échues, chacune
     * dans sa propre société : une inscription récente et une société
     * validée depuis longtemps ne changent pas, et rien ne s'inscrit à
     * leur journal d'audit.
     */
    public function test_la_cloture_sans_code_ne_touche_que_les_inscriptions_echues_de_chaque_societe(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $echue = $this->inscrireUneSociete();
        $validee = $this->inscrireUneSociete();
        $this->saisirLeCodeDuCourrier($validee);
        $this->travelTo(self::aParis('2026-10-25 10:00:00'));
        $recente = $this->inscrireUneSociete();
        $this->travelTo(self::aParis('2026-11-01 10:00:00'));

        $this->artisan('app:clore-inscriptions-sans-code')->expectsOutput('1 inscription(s) close(s).')->assertSuccessful();

        $this->assertSame([true, false, false], [$echue->fresh()->estClose(), $validee->fresh()->estClose(), $recente->fresh()->estClose()]);
        $this->assertSame([true, true], [$validee->fresh()->estValidee(), $recente->fresh()->attendSonCode()]);
        $this->assertSame([1, 0, 0], [$this->audit($echue, ActionAuditee::InscriptionClose)->count(), $this->audit($validee, ActionAuditee::InscriptionClose)->count(), $this->audit($recente, ActionAuditee::InscriptionClose)->count()]);
        $this->assertNull($this->codeActif($recente)->annule_le);
    }

    /**
     * Un seul nouveau courrier se demande, par le propriétaire, au plus
     * tôt quinze jours après le premier : il porte un nouveau code, et le
     * premier ne vaut plus. Ensuite le propriétaire n'en demande plus ;
     * l'éditeur, qui traite le cas à la main, peut encore en envoyer un.
     */
    public function test_un_seul_nouveau_courrier_au_plus_tot_quinze_jours_apres_le_premier(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $premierCode = $this->codeDuCourrier($societe);
        $proprietaire = $societe->proprietaire()->sole();
        $nouvelEnvoi = fn () => app(EnvoyerCodeDeConfirmationAction::class)->nouvelEnvoi($proprietaire, $societe->fresh());

        $this->travelTo(self::aParis('2026-10-20 09:59:59'));
        $this->assertThrows($nouvelEnvoi, VerificationRefuseeException::class, sprintf(EnvoyerCodeDeConfirmationAction::MESSAGE_PAS_ENCORE, '20/10/2026 à 10:00'));
        $this->assertCount(1, $this->envois()->envois());

        $this->travelTo(self::aParis('2026-10-20 10:00:00'));
        $nouvelEnvoi();

        $this->assertCount(2, $this->envois()->envois());
        $this->assertThrows(fn () => $this->saisirCommeLInscrit($societe, $premierCode), ValidationException::class);
        $this->assertThrows($nouvelEnvoi, VerificationRefuseeException::class, EnvoyerCodeDeConfirmationAction::MESSAGE_PLUS_DE_NOUVEL_ENVOI);

        app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->fresh(), MotifCode::Verification, self::editeur());

        $this->assertCount(3, $this->envois()->envois());
        $this->assertSame(1, app(TenantContext::class)->runAsSociete($societe, fn (): int => CodeConfirmation::query()->whereNull('annule_le')->count()));
        $this->saisirLeCodeDuCourrier($societe);
        $this->assertTrue($societe->fresh()->estValidee());
    }

    /**
     * Un nouveau courrier de la vérification, que le propriétaire ou
     * l'éditeur le demande, ne part que pour une entreprise encore active
     * au répertoire Sirene, contrôlée le jour même et inscrite au registre
     * des vérifications : une entreprise cessée depuis l'inscription est
     * refusée avec le motif du répertoire ; un répertoire qui ne répond pas
     * fait réessayer plus tard. Dans les deux cas, rien ne part, et le
     * nouveau courrier reste possible.
     */
    public function test_un_nouveau_courrier_ne_part_que_pour_une_entreprise_encore_active_au_repertoire(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire()->sole();
        $nouvelEnvoi = fn () => app(EnvoyerCodeDeConfirmationAction::class)->nouvelEnvoi($proprietaire, $societe->fresh());
        $this->travelTo(self::aParis('2026-10-20 10:00:00'));

        $this->repertoire()->rendreInjoignable();
        $this->assertThrows($nouvelEnvoi, VerificationRefuseeException::class, EnvoyerCodeDeConfirmationAction::MESSAGE_REPERTOIRE_INDISPONIBLE);
        $this->repertoire()->rendreInjoignable(false);
        $this->repertoire()->cesser((string) $societe->siren);

        $this->assertThrows($nouvelEnvoi, VerificationRefuseeException::class, ControleSirene::MOTIF_ENTREPRISE_CESSEE);
        $this->assertThrows(fn () => app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->fresh(), MotifCode::Verification, self::editeur()), VerificationRefuseeException::class, ControleSirene::MOTIF_ENTREPRISE_CESSEE);

        $this->assertCount(1, $this->envois()->envois());
        $this->assertSame([ResultatVerification::Concluante, ResultatVerification::Negative, ResultatVerification::Negative], $this->lignes($societe, TypeVerification::SireneUnite)->pluck('resultat')->all());
        $this->assertFalse(app(CourriersDeVerification::class)->nouvelEnvoiPossibleLe($societe->fresh())->isFuture());
    }

    /**
     * La page de la vérification montre le courrier (destinataire,
     * adresse, jour d'envoi, jour et heure où son code et l'inscription
     * prennent fin, dits une seule fois) et la page où saisir son code ;
     * elle ne propose un nouveau courrier qu'au propriétaire, à partir du
     * quinzième jour à l'heure du premier, puis le dépôt de pièces pour
     * l'éditeur.
     */
    public function test_la_page_de_la_verification_montre_le_courrier_et_ne_propose_un_nouveau_courrier_qu_au_quinzieme_jour(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $magasin = $this->creerLeMagasinDuSiege($societe);
        $administrateur = $this->administrateur($magasin);
        $proprietaire = $societe->proprietaire()->sole();

        $this->ouvrirLaPageDeVerification($societe, $proprietaire);
        Livewire::test(PageDeVerification::class)
            ->assertSeeText(['Jeanne DUPONT', '1 RUE DE LA REPUBLIQUE', 'Son code est valable jusqu\'au 04/11/2026 à 10:00 et ne sert qu\'une fois ; sans code saisi d\'ici là, l\'inscription sera close et le SIREN libéré.', 'Un nouveau courrier pourra être demandé à partir du 20/10/2026 à 10:00.'], false)
            ->assertSee(CourrierDeCode::adresseDeLaPage((string) $societe->siren), false)
            ->assertActionHidden('demanderUnNouveauCourrier')
            ->assertActionHidden('deposerUnePiece');

        $this->travelTo(self::aParis('2026-10-20 10:00:00'));
        $this->ouvrirLaPageDeVerification($societe, $administrateur);
        Livewire::test(PageDeVerification::class)->assertActionHidden('demanderUnNouveauCourrier');
        $this->ouvrirLaPageDeVerification($societe, $proprietaire);
        Livewire::test(PageDeVerification::class)
            ->assertActionVisible('demanderUnNouveauCourrier')
            ->callAction('demanderUnNouveauCourrier')
            ->assertNotified('Le nouveau courrier est parti.');

        Livewire::test(PageDeVerification::class)
            ->assertActionHidden('demanderUnNouveauCourrier')
            ->assertActionVisible('deposerUnePiece')
            ->assertSeeText('Le nouveau courrier est parti.');
        $this->assertCount(2, $this->envois()->envois());
    }

    /**
     * Les pièces ne se déposent qu'une fois le nouveau courrier parti,
     * pour que l'éditeur traite le cas à la main : jamais comme une voie
     * normale de la vérification.
     */
    public function test_les_pieces_ne_se_deposent_qu_apres_le_nouveau_courrier(): void
    {
        Storage::fake(PiecesDeVerification::DISQUE);
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire()->sole();

        $this->assertThrows(fn () => app(DeposerUnePieceAction::class)->handle($proprietaire, $societe, TypePiece::Kbis, 'Extrait Kbis'), VerificationRefuseeException::class, DeposerUnePieceAction::MESSAGE_PAS_ENCORE);
        $this->travelTo(self::aParis('2026-10-20 10:00:00'));
        app(EnvoyerCodeDeConfirmationAction::class)->nouvelEnvoi($proprietaire, $societe);

        app(DeposerUnePieceAction::class)->handle($proprietaire, $societe->fresh(), TypePiece::Kbis, 'Extrait Kbis');

        $this->assertSame(1, app(TenantContext::class)->runAsSociete($societe, fn (): int => PieceVerification::query()->count()));
    }

    /**
     * Les pièces déposées se rangent chiffrées ; l'éditeur ne les lit que
     * dans un dossier temporaire effacé à la fin de son examen ; sa
     * décision (un nouveau courrier) les efface, et celles qu'il n'a pas
     * examinées s'effacent trente jours après leur dépôt. La trace de
     * chaque pièce reste ; la société n'est validée que par le code.
     */
    public function test_les_pieces_deposees_sont_chiffrees_et_effacees_a_la_decision(): void
    {
        Storage::fake(PiecesDeVerification::DISQUE);
        config(['editeur.operateurs' => ['alice' => password_hash('secret-d-alice', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1])]]);
        $societe = $this->societeDontLesCourriersNeSontPasParvenus();
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
        $this->assertSame([], PiecesDeVerification::dossiersDExamen());
        $this->assertSame(['kbis'], $this->audit($societe, ActionAuditee::PiecesConsultees)->sole()->nouvelles_valeurs['pieces']);

        $this->procedureDeLEditeur('editeur:societe:examiner', $societe)->assertSuccessful();

        $piece->refresh();
        $this->assertSame([null, true, false], [$piece->chemin, $piece->effacee_le !== null, $societe->fresh()->estValidee()]);
        Storage::disk(PiecesDeVerification::DISQUE)->assertMissing($chemin);
        $this->assertSame(PiecesDeVerification::MOTIF_DECISION, $this->audit($societe, ActionAuditee::PieceEffacee)->sole()->nouvelles_valeurs['motif']);
        $this->assertCount(3, $this->envois()->envois());
        $this->saisirLeCodeDuCourrier($societe);
        $this->assertTrue($societe->fresh()->estValidee());

        $autre = $this->societeDontLesCourriersNeSontPasParvenus();
        $oubliee = app(DeposerUnePieceAction::class)->handle($autre->proprietaire, $autre, TypePiece::Identite, 'Carte d\'identité');
        $this->travelTo(self::aParis('2026-11-20 10:00:00'));
        $this->artisan('app:effacer-pieces-verification')->assertSuccessful();
        Storage::disk(PiecesDeVerification::DISQUE)->assertMissing((string) $oubliee->chemin);
        $this->assertNotNull(app(TenantContext::class)->runAsSociete($autre, fn () => $oubliee->fresh()->effacee_le));
    }

    /**
     * Le fichier envoyé au formulaire de dépôt ne reste jamais en clair
     * dans le dossier temporaire des envois : ni après un dépôt accepté,
     * ni après un dépôt refusé (dix pièces attendent déjà l'éditeur). Le
     * dépôt se fait à l'heure que relisent les fichiers : Livewire efface
     * un envoi qu'il croit vieux de plus d'un jour selon l'heure de
     * l'application.
     */
    public function test_un_depot_accepte_ou_refuse_ne_laisse_aucun_fichier_temporaire(): void
    {
        Storage::fake(PiecesDeVerification::DISQUE);
        $societe = $this->societeDontLesCourriersNeSontPasParvenus();
        $this->figerALHeureDesFichiers();
        $this->ouvrirLaPageDeVerification($societe, $societe->proprietaire);
        $envois = FileUploadConfiguration::storage();

        Livewire::test(PageDeVerification::class)
            ->callAction('deposerUnePiece', data: ['type' => TypePiece::Identite->value, 'fichier' => UploadedFile::fake()->createWithContent('cni.pdf', '%PDF-1.4 Carte d\'identité de Jeanne Dupont')])
            ->assertHasNoActionErrors()
            ->assertNotified('Pièce déposée : l\'éditeur est prévenu.');
        $this->assertSame([], $envois->allFiles(FileUploadConfiguration::path()));

        foreach (range(2, DeposerUnePieceAction::PIECES_EN_ATTENTE_AU_PLUS) as $rang) {
            app(DeposerUnePieceAction::class)->handle($societe->proprietaire, $societe, TypePiece::Autre, "Pièce {$rang}");
        }
        Livewire::test(PageDeVerification::class)
            ->callAction('deposerUnePiece', data: ['type' => TypePiece::Identite->value, 'fichier' => UploadedFile::fake()->createWithContent('cni.pdf', '%PDF-1.4 Carte d\'identité de Jeanne Dupont')])
            ->assertNotified(DeposerUnePieceAction::MESSAGE_TROP_DE_PIECES);

        $this->assertSame([], $envois->allFiles(FileUploadConfiguration::path()));
        $this->assertSame(DeposerUnePieceAction::PIECES_EN_ATTENTE_AU_PLUS, app(TenantContext::class)->runAsSociete($societe, fn (): int => PieceVerification::query()->count()));
    }

    /**
     * Le refus de l'éditeur, après le traitement à la main d'un courrier
     * non reçu, clôt l'inscription pour toujours : ses pièces s'effacent,
     * son code s'annule, son SIREN est libéré, et une nouvelle inscription
     * de ce SIREN reçoit son propre courrier.
     */
    public function test_le_refus_de_l_editeur_clot_l_inscription_efface_les_pieces_et_libere_le_siren(): void
    {
        Storage::fake(PiecesDeVerification::DISQUE);
        config(['editeur.operateurs' => ['alice' => password_hash('secret-d-alice', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1])]]);
        $societe = $this->societeDontLesCourriersNeSontPasParvenus();
        $piece = app(DeposerUnePieceAction::class)->handle($societe->proprietaire, $societe, TypePiece::Kbis, 'Extrait Kbis');

        $this->procedureDeLEditeur('editeur:societe:refuser', $societe)->expectsOutputToContain('Inscription refusée et close.')->assertSuccessful();

        $close = $societe->fresh();
        $this->assertSame([true, MotifCloture::Refus], [$close->estClose(), $close->motif_cloture]);
        Storage::disk(PiecesDeVerification::DISQUE)->assertMissing((string) $piece->chemin);
        $this->assertSame(0, app(TenantContext::class)->runAsSociete($societe, fn (): int => CodeConfirmation::query()->whereNull('annule_le')->whereNull('utilise_le')->count()));
        $nouvelle = $this->inscrireUneSociete((string) $societe->siren);
        $this->assertTrue($nouvelle->attendSonCode());
    }

    /**
     * Un courrier revenu à l'expéditeur, faute d'avoir été retiré au
     * bureau de poste, annule son code et prévient le propriétaire ; le
     * suivi ne se refait pas. Un courrier remis note sa remise, et son
     * code vaut toujours.
     */
    public function test_un_courrier_revenu_annule_son_code_et_previent_le_proprietaire(): void
    {
        $revenu = $this->inscrireUneSociete();
        $codeRevenu = $this->codeDuCourrier($revenu);
        $remis = $this->inscrireUneSociete();
        $this->envois()->retourner((string) $this->codeActif($revenu)->reference_envoi);
        $this->envois()->remettre((string) $this->codeActif($remis)->reference_envoi);

        $this->assertSame(2, app(SuivreLesEnvoisAction::class)->suivreTout());
        $this->assertSame(0, app(SuivreLesEnvoisAction::class)->suivreTout());

        $courrierRevenu = $this->codeDuSiren($revenu);
        $this->assertSame([true, true], [$courrierRevenu->retourne_le !== null, $courrierRevenu->annule_le !== null]);
        $this->assertSame('Votre courrier recommandé est revenu', $revenu->proprietaire->notifications()->sole()->data['title']);
        $this->assertThrows(fn () => $this->saisirCommeLInscrit($revenu, $codeRevenu), ValidationException::class);
        $this->assertNotNull($this->codeActif($remis)->remis_le);
        $this->saisirLeCodeDuCourrier($remis);
        $this->assertTrue($remis->fresh()->estValidee());
    }

    /**
     * L'avis d'un courrier revenu, comme la page de la vérification, dit ce
     * que le propriétaire peut encore faire : avant le quinzième jour, à
     * partir de quand il pourra demander un nouveau courrier ; ensuite, le
     * demander ; quand le nouveau courrier revient aussi, déposer des pièces
     * pour l'éditeur ou le contacter.
     */
    public function test_l_avis_d_un_courrier_revenu_dit_ce_que_le_proprietaire_peut_encore_faire(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $tot = $this->inscrireUneSociete();
        $tard = $this->inscrireUneSociete();
        $retourner = function (Societe $societe): void {
            $this->envois()->retourner((string) $this->codeActif($societe)->reference_envoi);
            app(SuivreLesEnvoisAction::class)->suivreTout();
        };
        $avis = fn (Societe $societe): array => $societe->proprietaire->notifications()->reorder('created_at')->get()->pluck('data.body')->all();
        $revenu = 'Le courrier recommandé n\'a pas été retiré au bureau de poste et son code ne vaut plus. ';

        $this->travelTo(self::aParis('2026-10-06 10:00:00'));
        $retourner($tot);

        $this->assertSame([$revenu.'Un nouveau courrier pourra être demandé depuis la page de la vérification à partir du 20/10/2026 à 10:00.'], $avis($tot));
        $this->ouvrirLaPageDeVerification($tot, $tot->proprietaire);
        Livewire::test(PageDeVerification::class)
            ->assertSeeText('Ce courrier est revenu sans avoir été retiré au bureau de poste : son code ne vaut plus. Sans nouveau courrier, l\'inscription sera close le 04/11/2026 à 10:00 et le SIREN libéré.', false)
            ->assertDontSeeText('Son code est valable');

        $this->travelTo(self::aParis('2026-10-21 10:00:00'));
        $retourner($tard);

        $this->assertSame([$revenu.'Demandez un nouveau courrier depuis la page de la vérification.'], $avis($tard));

        app(EnvoyerCodeDeConfirmationAction::class)->nouvelEnvoi($tot->proprietaire, $tot->fresh());
        $retourner($tot);

        $this->assertSame($revenu.'Déposez des pièces depuis la page de la vérification pour que l\'éditeur traite votre demande, ou contactez-le.', collect($avis($tot))->last());
        $this->assertCount(2, $avis($tot));
    }

    /**
     * Les rappels du prestataire d'envoi : mal signés, signés d'un autre
     * secret, trop anciens ou sans secret configuré, ils sont refusés sans
     * rien écrire ; justes et rejoués, ils ne refont rien.
     */
    public function test_un_rappel_du_prestataire_mal_signe_ou_rejoue_est_refuse(): void
    {
        config(['services.recommande.secret_rappel' => self::SECRET_DES_RAPPELS_D_ENVOI]);
        $societe = $this->inscrireUneSociete();
        $confirmation = $this->codeActif($societe);
        $retourne = ['reference' => $confirmation->reference_envoi, 'statut' => 'retourne'];

        $this->rappeler($retourne, 'un-autre-secret')->assertUnauthorized();
        $this->rappeler($retourne, self::SECRET_DES_RAPPELS_D_ENVOI, signature: '')->assertUnauthorized();
        $this->rappeler($retourne, self::SECRET_DES_RAPPELS_D_ENVOI, horodatage: time() - RappelsDesPrestatairesController::ECART_MAXIMAL_EN_SECONDES - 60)->assertUnauthorized();
        $this->assertSame([null, null], [$this->codeActif($societe)->retourne_le, $this->codeActif($societe)->annule_le]);

        $this->rappeler(['reference' => $confirmation->reference_envoi, 'statut' => 'remis'], self::SECRET_DES_RAPPELS_D_ENVOI)->assertOk();
        $this->rappeler($retourne, self::SECRET_DES_RAPPELS_D_ENVOI)->assertOk();

        $actif = $this->codeActif($societe);
        $this->assertSame([true, null, null], [$actif->remis_le !== null, $actif->retourne_le, $actif->annule_le]);
        config(['services.recommande.secret_rappel' => null]);
        $this->rappeler($retourne, '')->assertUnauthorized();
    }

    /**
     * Le prestataire factice du développement garde ses courriers d'une
     * requête à l'autre, chaque requête ayant ses propres instances, comme
     * un nouveau processus : le code d'un courrier se lit puis se saisit
     * plus tard.
     */
    public function test_le_prestataire_factice_garde_ses_courriers_d_une_requete_a_l_autre(): void
    {
        $societe = $this->inscrireUneSociete();

        $this->app->forgetInstance(ServiceEnvoiRecommande::class);

        $this->assertInstanceOf(FauxServiceEnvoiRecommande::class, $this->envois());
        $this->assertCount(1, $this->envois()->envois());
        $this->saisirLeCodeDuCourrier($societe);
        $this->assertTrue($societe->fresh()->estValidee());
    }

    /**
     * Le volet 2 des attestations se signe dans l'application par le seul
     * propriétaire qui a validé la société en saisissant le code du
     * courrier ; un administrateur ne signe pas ; devenu propriétaire par
     * une transmission, le nouveau propriétaire signe à la main, comme
     * l'ancien s'il le redevient.
     */
    public function test_seul_le_proprietaire_qui_a_saisi_le_code_signe_les_attestations_dans_l_application(): void
    {
        $societe = $this->inscrireUneSociete();
        $magasin = $this->creerLeMagasinDuSiege($societe);
        $proprietaire = $societe->proprietaire()->sole();
        $this->assertFalse($proprietaire->signeLesAttestationsDansLApplication());

        $this->saisirLeCodeDuCourrier($societe);
        $repreneur = $this->administrateurPretAReprendre($magasin->fresh());

        $this->assertSame([true, false], [$proprietaire->refresh()->signeLesAttestationsDansLApplication(), $repreneur->signeLesAttestationsDansLApplication()]);

        $jeton = $this->demanderLaTransmission($proprietaire, $repreneur, self::MOT_DE_PASSE_CHOISI);
        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($repreneur, $jeton, self::MOT_DE_PASSE_CHOISI);

        $this->assertSame([RoleSociete::Proprietaire, false], [$repreneur->refresh()->role_societe, $repreneur->signeLesAttestationsDansLApplication()]);
        $this->assertSame([RoleSociete::Administrateur, false, null], [$proprietaire->refresh()->role_societe, $proprietaire->signeLesAttestationsDansLApplication(), $proprietaire->code_du_courrier_saisi_le]);
    }

    /**
     * Après la validation, une revérification négative (entreprise cessée,
     * établissement fermé au répertoire) s'inscrit et prévient le
     * propriétaire et l'éditeur, sans rien bloquer : les ventes et les
     * paiements de l'équipe continuent.
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
        $this->assertSame([true, false], [$societe->fresh()->estValidee(), $societe->fresh()->estClose()]);
        $this->assertNotNull($magasin->fresh()->etablissement_verifie_le);
        Mail::assertSent(AlerteEditeurMail::class, fn (AlerteEditeurMail $mail): bool => str_contains($mail->objet, 'Revérification Sirene négative'));
        $this->assertContains('Écart au répertoire Sirene', $proprietaire->notifications()->get()->pluck('data.title')->all());

        app(TenantContext::class)->set($magasin->fresh());
        $this->actingAs($this->responsableDeTest($magasin));
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = self::vendreAuComptant(self::auteur(), Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 2]]));
        app(EnregistrerPaiementAction::class)->handle(self::auteur(), $facture->client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => $facture->total_ttc,
            'recu_le' => Calendrier::deParis()->aujourdhui(),
        ], [['facture_id' => $facture->id, 'montant' => $facture->total_ttc]]);

        $this->assertSame([StatutFacture::Payee, StatutFacture::Payee], [$ticket->fresh()->statut, $facture->fresh()->statut]);
    }

    /**
     * Une inscription dont les deux courriers ne sont pas parvenus : le
     * premier, inscrit le 5 octobre à 10 h, puis le nouveau courrier,
     * demandé quinze jours plus tard, à l'heure où l'horloge reste figée ;
     * son propriétaire peut déposer des pièces pour l'éditeur.
     */
    private function societeDontLesCourriersNeSontPasParvenus(): Societe
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $this->travelTo(self::aParis('2026-10-20 10:00:00'));
        app(EnvoyerCodeDeConfirmationAction::class)->nouvelEnvoi($societe->proprietaire, $societe);
        app(TenantContext::class)->clear();

        return $societe->fresh();
    }

    /**
     * Le propriétaire inscrit saisit ce code, avec l'adresse et le mot de
     * passe de son inscription, depuis cette adresse IP.
     */
    private function saisirCommeLInscrit(Societe $societe, string $code, string $adresseIp = '127.0.0.1'): User
    {
        $proprietaire = $societe->proprietaire()->firstOrFail();
        app(TenantContext::class)->clear();

        return app(SaisirCodeDeConfirmationAction::class)->handle((string) $societe->siren, $code, (string) $proprietaire->email, self::MOT_DE_PASSE_CHOISI, '', $adresseIp);
    }

    /**
     * Un code faux, de la forme d'un vrai : ce code, son premier caractère
     * changé.
     */
    private static function codeFaux(string $code): string
    {
        return ($code[0] === 'A' ? 'B' : 'A').substr($code, 1);
    }

    /**
     * Le code de vérification encore actif de la société.
     */
    private function codeActif(Societe $societe): CodeConfirmation
    {
        return app(TenantContext::class)->runAsSociete($societe, fn (): CodeConfirmation => CodeConfirmation::query()->whereNull('annule_le')->whereNull('utilise_le')->where('motif', MotifCode::Verification->value)->sole());
    }

    /**
     * Le dernier code de vérification de la société, actif ou non.
     */
    private function codeDuSiren(Societe $societe): CodeConfirmation
    {
        return app(TenantContext::class)->runAsSociete($societe, fn (): CodeConfirmation => CodeConfirmation::query()->where('motif', MotifCode::Verification->value)->orderByDesc('id')->firstOrFail());
    }

    /**
     * Tout le texte d'un PDF de dompdf, ses morceaux mis bout à bout.
     */
    private function texteDuPdf(string $pdf): string
    {
        return implode(' ', array_merge(...array_map(fn (array $page): array => array_column($page['textes'], 'texte'), $this->pagesDuPdf($pdf))));
    }

    /**
     * Le refus de l'éditeur, qui clôt l'inscription.
     */
    private function refuserParLEditeur(Societe $societe): void
    {
        app(CloreLInscriptionAction::class)->refuser($societe, self::editeur());
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
     * Ce compte sur la page de la vérification de sa société.
     */
    private function ouvrirLaPageDeVerification(Societe $societe, User $compte): void
    {
        app(TenantContext::class)->clear();
        $this->actingAs($compte);
        Filament::setCurrentPanel(Filament::getPanel('societe'));
        Filament::setTenant($societe);
        app(TenantContext::class)->poserSociete($societe);
    }

    /**
     * Une procédure de l'éditeur sur la société, lancée par l'opératrice
     * alice avec sa justification, sa pièce et le SIREN retapé.
     */
    private function procedureDeLEditeur(string $commande, Societe $societe): PendingCommand
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
     * Un rappel du prestataire d'envoi, signé de ce secret sur son
     * horodatage et son corps, sauf signature donnée.
     *
     * @param  array<string, mixed>  $corps
     */
    private function rappeler(array $corps, string $secret, ?int $horodatage = null, ?string $signature = null): TestResponse
    {
        $contenu = json_encode($corps, JSON_THROW_ON_ERROR);
        $horodatage ??= time();

        return $this->call('POST', route('rappels.recommande'), [], [], [], $this->transformHeadersToServerVars([
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

    private function rne(): FauxRne
    {
        return app(RegistreNationalDesEntreprises::class);
    }

    private function repertoire(): FauxRepertoireSirene
    {
        return app(RepertoireSirene::class);
    }

    private function envois(): FauxServiceEnvoiRecommande
    {
        return app(ServiceEnvoiRecommande::class);
    }
}
