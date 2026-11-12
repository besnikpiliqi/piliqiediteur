<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Cabinets\Exceptions\InvitationInvalideException;
use App\Domain\Cabinets\Jobs\RendreCaduquesLesInvitationsDuSiren;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Cabinets\Support\IdentiteDansLeJournal;
use App\Domain\Tenancy\Actions\ValiderLaSocieteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\PreuveDeValidation;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\DestinataireOfficiel;
use App\Domain\Tenancy\Services\FournisseurDesComptes;
use App\Domain\Tenancy\Services\GenerateurDIdentifiants;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Services\TraceDuVisiteur;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ModeleRelu;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use App\Integrations\Rne\Contracts\RegistreNationalDesEntreprises;
use App\Integrations\Rne\RepresentantRne;
use App\Integrations\Rne\RneIndisponibleException;
use App\Integrations\Sirene\UniteSirene;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

/**
 * La personne qui a reçu le lien de l'invitation d'un cabinet d'expertise
 * comptable l'accepte, en choisissant son mot de passe et le type
 * d'activité de la société (« Commerce ou atelier » ou « Services, sans
 * vente d'articles », jamais « Cabinet d'expertise comptable », sans
 * valeur par défaut, la case du choix définitif cochée). Tout ou rien :
 *
 * - hors de toute transaction, l'entreprise est contrôlée de nouveau au
 *   répertoire Sirene (inconnue ou cessée : refusée ; répertoire
 *   injoignable : réessayer plus tard), puis son identité et son
 *   représentant légal sont lus au registre national des entreprises
 *   (injoignable : réessayer plus tard) ; un SIREN que le registre ne
 *   connaît pas, ou sans représentant légal lisible, s'accepte quand même,
 *   avec l'identité du répertoire Sirene : l'entreprise active au
 *   répertoire suffit ;
 * - puis, dans une transaction, dans l'ordre des verrous des liens : la
 *   ligne du cabinet (partagée), l'invitation, encore ouverte, la ligne de
 *   vérification du cabinet (partagée), encore vérifié ; l'adresse de
 *   l'invitation n'est l'adresse de connexion d'aucun propriétaire ; la
 *   société naît avec le type choisi (définitif) et l'identité lue, son
 *   propriétaire avec l'adresse de l'invitation, confirmée par le lien
 *   suivi, et son identifiant ; elle est validée par l'action unique de la
 *   validation, preuve « invitation du cabinet », sans courrier ni QR code
 *   ; le lien avec le cabinet naît actif, avec les autorisations par défaut
 *   de son type ; l'invitation est acceptée. La personne, propriétaire de
 *   la société née, ne laisse sa trace qu'au journal de celle-ci, jamais à
 *   celui du cabinet (voir TraceDuVisiteur et JournalDesDroits).
 *
 * Le propriétaire est nommé comme celui d'une société qui s'inscrit : le
 * représentant légal que donne le registre ; sans lui, la dénomination du
 * répertoire Sirene, ou « Propriétaire » quand le répertoire la masque ;
 * il le change dans son profil. Deux acceptations simultanées du même
 * SIREN n'en créent qu'une : l'index unique du SIREN arrête la seconde,
 * dont l'invitation devient caduque, son cabinet prévenu. Après la
 * transaction, le cabinet est prévenu, et les invitations des autres
 * cabinets pour ce SIREN deviennent caduques (voir
 * RendreCaduquesLesInvitationsDuSiren). N'ayant pas saisi le code du
 * courrier, le propriétaire signe les attestations à la main.
 */
class AccepterLInvitationAction
{
    public const MESSAGE_TYPE_CABINET = 'Un client invité par un cabinet ne s\'inscrit jamais comme cabinet d\'expertise comptable.';

    public const MESSAGE_CHOIX_DEFINITIF = 'Cochez la case qui confirme que ce type d\'activité ne pourra plus changer.';

    public const MESSAGE_ENTREPRISE_INACTIVE = 'L\'entreprise n\'est plus active au répertoire Sirene : l\'invitation ne peut pas être acceptée.';

    public const MESSAGE_REPERTOIRE_INDISPONIBLE = 'Le répertoire Sirene ne répond pas : réessayez dans quelques minutes.';

    public const MESSAGE_REGISTRE_INDISPONIBLE = 'Le registre national des entreprises ne répond pas : réessayez dans quelques minutes.';

    public const MESSAGE_CABINET_NE_PEUT_PLUS = 'Le cabinet qui vous a invité ne peut plus inviter : inscrivez votre entreprise vous-même.';

    public const MESSAGE_CABINET_SUSPENDU = 'Le cabinet qui vous a invité est suspendu : son invitation ne peut pas être acceptée tant qu\'il l\'est. Vous pouvez la refuser.';

    public const MESSAGE_ADRESSE_PRISE = 'Cette adresse est déjà l\'adresse de connexion d\'un compte PILIQI : demandez au cabinet une invitation à une autre adresse.';

    public const MESSAGE_SIREN_INSCRIT = 'Cette entreprise vient d\'être inscrite sur PILIQI : son propriétaire peut lier votre cabinet depuis son espace.';

    public function __construct(
        private readonly ControleSirene $controleSirene,
        private readonly RegistreNationalDesEntreprises $rne,
        private readonly DestinataireOfficiel $destinataireOfficiel,
        private readonly GenerateurDIdentifiants $generateur,
        private readonly JournalAudit $journalAudit,
        private readonly JournalDesDroits $journalDesDroits,
        private readonly ValiderLaSocieteAction $validation,
        private readonly TraceDuVisiteur $traces,
    ) {}

    /**
     * @param  array<string, mixed>  $donnees  password, password_confirmation, type_activite, choix_definitif
     *
     * @throws InvitationInvalideException
     * @throws ValidationException
     * @throws LogicException
     */
    public function handle(#[SensitiveParameter] string $jeton, array $donnees): User
    {
        $invitation = InvitationCabinet::parSonJeton($jeton);

        if ($invitation === null || ! $invitation->estOuverte()) {
            throw new InvitationInvalideException;
        }

        $type = $this->valider($donnees);
        $motDePasseHache = Hash::make(ValeursLues::texteDe($donnees, 'password'));
        $siren = (string) $invitation->siren;

        $consultation = $this->controleSirene->unite($siren);

        if ($consultation['resultat'] === ResultatVerification::Indisponible) {
            throw new LogicException(self::MESSAGE_REPERTOIRE_INDISPONIBLE);
        }

        if ($consultation['resultat'] !== ResultatVerification::Concluante || $consultation['unite'] === null) {
            throw new LogicException(self::MESSAGE_ENTREPRISE_INACTIVE);
        }

        $unite = $consultation['unite'];

        try {
            $identiteDuRegistre = $this->rne->identite($siren);
            $representant = $this->destinataireOfficiel->representantLegal($siren);
        } catch (RneIndisponibleException) {
            throw new LogicException(self::MESSAGE_REGISTRE_INDISPONIBLE);
        }

        $identite = $identiteDuRegistre === null ? $this->controleSirene->identiteDepuisSirene($unite) : $this->controleSirene->identiteDepuisLeRegistre($identiteDuRegistre, $unite);
        $nom = $representant?->nomPourLeCourrier() ?? $unite->denomination ?? 'Propriétaire';
        $cabinet = Societe::query()->findOrFail($invitation->societe_id);

        try {
            $proprietaire = DB::transaction(fn (): User => $this->creer($invitation, $cabinet, $type, $identite, $representant, $nom, $motDePasseHache, $consultation, $unite));
        } catch (UniqueConstraintViolationException $doublon) {
            if (str_contains($doublon->getMessage(), 'users_adresse_de_connexion') || str_contains($doublon->getMessage(), 'users.email')) {
                throw new LogicException(self::MESSAGE_ADRESSE_PRISE);
            }

            if (! str_contains($doublon->getMessage(), 'societes_siren_unique') && ! str_contains($doublon->getMessage(), 'societes.siren')) {
                throw $doublon;
            }

            $this->rendreCaduque($invitation, $cabinet);

            throw new LogicException(self::MESSAGE_SIREN_INSCRIT);
        }

        $client = Societe::query()->findOrFail($proprietaire->societe_id);
        $this->validation->apresLaValidation($client, $proprietaire);
        RendreCaduquesLesInvitationsDuSiren::dispatch($siren, CleDuModele::de($invitation));
        $cabinet->proprietaire()->first()?->notify(new AvisDuCabinet(
            $cabinet,
            'Invitation acceptée',
            "{$client->getFilamentName()} a accepté votre invitation : vous pouvez maintenant confier ce client à vos collaborateurs.",
            ClientDuCabinetResource::getUrl('index', panel: 'cabinet', tenant: $cabinet),
            'Voir vos clients',
            AvisDuCabinet::SUCCES,
            parCourriel: true,
            clientId: CleDuModele::de($client),
        ));

        return $proprietaire;
    }

    /**
     * @param  array<string, mixed>  $donnees
     *
     * @throws ValidationException
     */
    private function valider(array $donnees): TypeDActivite
    {
        $valides = Validator::make($donnees, [
            'password' => ['required', 'string', 'confirmed'],
            'type_activite' => ['required', 'string', Rule::in([TypeDActivite::Commerce->value, TypeDActivite::Services->value])],
            'choix_definitif' => ['accepted'],
        ], [
            'type_activite.in' => self::MESSAGE_TYPE_CABINET,
            'choix_definitif.accepted' => self::MESSAGE_CHOIX_DEFINITIF,
        ], ['password' => 'mot de passe', 'type_activite' => 'type d\'activité'])->validate();
        RegleDuMotDePasse::exiger(ValeursLues::texteDe($valides, 'password'), null);

        return TypeDActivite::from(ValeursLues::texteDe($valides, 'type_activite'));
    }

    /**
     * La société, son propriétaire, sa validation et son lien, dans la
     * transaction de l'acceptation.
     *
     * @param  array<string, mixed>  $identite
     * @param  array{resultat: ResultatVerification, unite: UniteSirene|null, motif: string|null}  $consultation  ce qu'a rendu ControleSirene::unite()
     *
     * @throws InvitationInvalideException
     * @throws LogicException
     */
    private function creer(InvitationCabinet $invitation, Societe $cabinet, TypeDActivite $type, array $identite, ?RepresentantRne $representant, string $nom, string $motDePasseHache, array $consultation, UniteSirene $unite): User
    {
        $cabinet = Societe::query()->whereKey($cabinet->getKey())->sharedLock()->firstOrFail();
        $relue = InvitationCabinet::query()->withoutGlobalScopes()->where('societe_id', $cabinet->getKey())->whereKey($invitation->getKey())->lockForUpdate()->first();

        if ($relue === null || ! $relue->estOuverte()) {
            throw new InvitationInvalideException;
        }

        $cabinet->cabinet()->sharedLock()->first();

        if ($cabinet->estUnCabinetSuspendu()) {
            throw new LogicException(self::MESSAGE_CABINET_SUSPENDU);
        }

        if (! $cabinet->estUnCabinetVerifie()) {
            throw new LogicException(self::MESSAGE_CABINET_NE_PEUT_PLUS);
        }

        if (FournisseurDesComptes::proprietaireDeLAdresse((string) $relue->email) !== null) {
            throw new LogicException(self::MESSAGE_ADRESSE_PRISE);
        }

        $client = tap((new Societe)->forceFill([
            ...$identite,
            'siren' => $relue->siren,
            'type_activite' => $type,
            'numero_tva' => IdentifiantsInsee::numeroTvaPropose((string) $relue->siren),
            'representant_legal' => $representant === null ? null : mb_substr("{$representant->nomPourLeCourrier()}, {$representant->role}", 0, 255),
        ]))->save();

        $proprietaire = app(TenantContext::class)->runAsSociete($client, function () use ($client, $cabinet, $relue, $nom, $motDePasseHache, $consultation, $unite): User {
            Societe::query()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();
            $proprietaire = tap((new User(['name' => mb_substr($nom, 0, 255), 'email' => $relue->email, 'password' => $motDePasseHache]))->forceFill([
                'societe_id' => $client->getKey(),
                'role_societe' => RoleSociete::Proprietaire,
                'statut' => StatutCompte::Actif,
                'email_verified_at' => now(),
            ]))->save();
            $this->generateur->attribuer($proprietaire, motDePasseHache: $motDePasseHache);
            $this->controleSirene->noterUnite($client, $consultation, $proprietaire);
            $this->journalAudit->inscrire(ActionAuditee::SocieteInscrite, $proprietaire, null, null, [
                'siren' => $client->siren,
                'type_activite' => JournalAudit::valeur($client->type_activite),
                'code_activite' => $unite->activitePrincipale,
                'nomenclature_activite' => $unite->nomenclatureActivite,
                'raison_sociale' => $client->raison_sociale,
                'forme_juridique' => JournalAudit::valeur($client->forme_juridique),
                'proprietaire' => ['id' => $proprietaire->getKey(), 'nom' => $proprietaire->name],
                'origine' => 'invitation',
            ], $client);
            $this->validation->valider(Societe::query()->whereKey($client->getKey())->firstOrFail(), $proprietaire, PreuveDeValidation::InvitationDuCabinet, $cabinet);

            return $proprietaire;
        });

        $this->journalDesDroits->modifierLeLien(null, ModeleRelu::de($client), [], function (?User $systeme, Societe $clientVerrouille) use ($cabinet, $relue, $type): void {
            $lien = tap((new LienCabinet)->forceFill([
                'cabinet_id' => $cabinet->getKey(),
                'origine' => OrigineDuLien::Invitation,
                'etat' => EtatDuLien::Actif,
                'demande_le' => $relue->created_at,
                'actif_depuis' => now(),
            ]))->save();
            $lien->accorder(PermissionRegistry::parDefautDuCabinet($type));
        }, function (Societe $journal, array $liens) use ($cabinet, $client): array {
            return [[ActionAuditee::LienActive, [
                'cabinet' => IdentiteDansLeJournal::de($cabinet),
                'client' => IdentiteDansLeJournal::de(ModeleRelu::de($client)),
                'origine' => OrigineDuLien::Invitation->value,
                'autorisations' => 'par_defaut',
                'magasins' => 'tous',
            ], CleDuModele::de($cabinet)]];
        }, [CleDuModele::de($cabinet)]);

        app(TenantContext::class)->runAsSociete($cabinet, function () use ($relue, $client): void {
            $relue->forceFill(['etat' => EtatInvitation::Acceptee, 'close_le' => now()])->save();
            $this->traces->horsDeSaSociete(fn () => $this->journalAudit->inscrire(ActionAuditee::InvitationAcceptee, null, null, null, ['siren' => $relue->siren, 'client' => IdentiteDansLeJournal::de(ModeleRelu::de($client))], $relue));
        });

        return $proprietaire;
    }

    /**
     * L'invitation dont le SIREN vient d'être inscrit par une autre voie
     * devient caduque, dans une transaction à part, et son cabinet est
     * prévenu.
     */
    private function rendreCaduque(InvitationCabinet $invitation, Societe $cabinet): void
    {
        (new RendreCaduquesLesInvitationsDuSiren((string) $invitation->siren, 0))->rendreCaduque(CleDuModele::de($invitation), CleDuModele::de($cabinet));
    }
}
