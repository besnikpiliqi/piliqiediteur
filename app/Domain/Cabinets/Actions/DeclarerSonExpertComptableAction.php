<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\MotifDeFinDuLien;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Cabinets\Jobs\RattacherLesDeclarationsDuSiren;
use App\Domain\Cabinets\Models\CompteExpertComptable;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Cabinets\Services\EnvoiDesLiensDesExpertsComptables;
use App\Domain\Cabinets\Services\RechercheDesCabinets;
use App\Domain\Cabinets\Support\IdentiteDansLeJournal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Rules\SirenValide;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\Cabinet\Pages\InvitationsEtDemandes;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use Throwable;

/**
 * Le propriétaire d'une société validée déclare son expert-comptable par
 * le SIREN de son cabinet, quand ce cabinet n'a pas d'espace vérifié sur
 * PILIQI (décision de l'éditeur du 01/11/2026), avec sa première personne :
 * son nom et son adresse e-mail professionnelle, saisie deux fois, où part
 * le lien de 7 jours de son mot de passe (voir CompteExpertComptable). La
 * déclaration est un lien en attente, sans cabinet, dans la société qui
 * déclare (voir LienCabinet), avec les autorisations qu'un client accorde
 * à un cabinet lié et son choix de magasins ; le compte de la personne est
 * un compte de la société, au rôle d'expert-comptable de la société, qui
 * ouvre avec ces autorisations les magasins réels qu'elle lui ouvre.
 *
 * Dans cet ordre, rien ne s'écrit au premier refus : le SIREN (9 chiffres
 * à clé valide, pas celui de la société) ; le lien en cours de la société
 * (le même SIREN déjà déclaré, ou le cabinet déjà lié) ; un cabinet déjà
 * vérifié de ce SIREN, à qui la société demande plutôt le lien ; au plus 10
 * contrôles du répertoire par heure et par société ; le répertoire Sirene
 * (entreprise connue, active, de code d'activité 69.20Z) ; la dénomination
 * lue se garde, jamais saisie.
 *
 * Sous les verrous de JournalDesDroits::modifierLeLien() : le lien en cours
 * d'un autre cabinet ou d'un autre SIREN ne se remplace qu'avec la case de
 * confirmation (il prend fin, motif « remplacé », et une déclaration
 * remplacée retire ses comptes) ; chaque demande de lien envoyée par la
 * société et encore en cours est retirée ; une demande répétée après une
 * réponse perdue rend le compte déjà créé, sans rien écrire ni envoyer.
 * Après la transaction, le lien du mot de passe part, sans file d'attente,
 * le jeton en mémoire seulement (voir EnvoiDesLiensDesExpertsComptables).
 */
class DeclarerSonExpertComptableAction
{
    public const MESSAGE_PROPRIETAIRE_SEUL = 'Seul le propriétaire déclare l\'expert-comptable de la société et gère les comptes de ses personnes.';

    public const MESSAGE_SOCIETE_NON_VALIDEE = 'Vous pourrez déclarer votre expert-comptable dès que votre société sera validée par le code de son courrier. D\'ici là, vous pouvez demander le lien à un cabinet déjà présent sur PILIQI ou inviter votre expert-comptable à y créer son espace.';

    public const MESSAGE_SON_PROPRE_SIREN = 'Votre société ne peut pas se déclarer elle-même comme expert-comptable.';

    public const MESSAGE_DEJA_DECLARE = 'Ce cabinet est déjà votre expert-comptable déclaré : ajoutez-y une personne.';

    public const MESSAGE_DEJA_LIE = 'Ce cabinet est déjà votre cabinet lié.';

    public const MESSAGE_CABINET_SUR_PILIQI = 'Ce cabinet a déjà son espace sur PILIQI : demandez-lui le lien dans « Désigner votre cabinet » ; il entrera par son espace dès qu\'il l\'aura accepté.';

    public const MESSAGE_TROP_DE_CONTROLES = 'Trop de vérifications de SIREN pour votre société : réessayez dans une heure.';

    public const MESSAGE_REPERTOIRE_INJOIGNABLE = 'Le répertoire Sirene ne répond pas : réessayez dans quelques minutes.';

    public const MESSAGE_PAS_UN_CABINET_COMPTABLE = 'Le répertoire Sirene ne donne pas à cette entreprise le code d\'activité 69.20Z (activités comptables) : elle ne peut pas être déclarée comme votre expert-comptable. Si c\'est bien votre cabinet, invitez-le à créer son espace sur PILIQI : son activité y sera contrôlée.';

    public const MESSAGE_LIENS_CHANGES = 'Les liens de votre société ont changé : recommencez.';

    public const CONTROLES_DU_REPERTOIRE_PAR_HEURE = 10;

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly CreationDesComptes $creation,
        private readonly ControleSirene $sirene,
        private readonly RechercheDesCabinets $recherche,
        private readonly DebitDesEmails $debit,
        private readonly AccesDesCabinets $acces,
        private readonly EnvoiDesLiensDesExpertsComptables $envoi,
    ) {}

    /**
     * @param  array<string, mixed>  $donnees  siren, name, email, email_confirmation, autorisations, magasins (null pour tous), remplacer
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $societe, array $donnees, #[SensitiveParameter] string $motDePasse, ?string $jeton = null): User
    {
        $siren = $this->validerLeSiren($donnees['siren'] ?? null, $societe);
        $identite = $this->creation->valider(['name' => $donnees['name'] ?? null]);
        $adresse = $this->creation->validerLAdresse($donnees['email'] ?? null, $donnees['email_confirmation'] ?? null);
        $autorisations = self::autorisationsValidees($donnees['autorisations'] ?? [], $societe);
        $magasins = isset($donnees['magasins']) && is_array($donnees['magasins']) ? $donnees['magasins'] : null;
        $remplacer = ($donnees['remplacer'] ?? false) === true;

        MotDePasseRedemande::exiger($auteur, $motDePasse);
        $auteurRelu = $this->exigerLeProprietaire($auteur, $societe);
        self::exigerUneSocieteValidee($societe);
        $this->debit->verifier($auteurRelu);

        $empreinte = CreationDesComptes::empreinte(['role_societe' => RoleSociete::ExpertComptable->value, 'name' => $identite['name'], 'email' => $adresse, 'siren_cabinet' => $siren, 'autorisations' => $autorisations, 'magasins' => $magasins, 'remplacer' => $remplacer]);
        $dejaCree = app(TenantContext::class)->runAsSociete($societe, fn (): ?User => $this->creation->compteDejaCree($auteurRelu, $societe, $jeton, $empreinte));

        if ($dejaCree !== null) {
            return $dejaCree;
        }

        $this->refuserLeLienEnCoursDuMemeCabinet(self::lienEnCours($societe), $siren);

        if ($this->recherche->pour($siren)->isNotEmpty()) {
            throw ValidationException::withMessages(['siren' => self::MESSAGE_CABINET_SUR_PILIQI]);
        }

        $denomination = $this->controlerAuRepertoire($siren, $societe);
        $demandes = self::demandesEnvoyees($societe);

        try {
            $resultat = $this->journalDesDroits->modifierLeLien($auteurRelu, $societe, array_values($demandes->all()), function (?User $auteurSousVerrou, Societe $client, array $demandesRelues) use ($siren, $denomination, $identite, $adresse, $autorisations, $magasins, $remplacer, $jeton, $empreinte, $demandes): array {
                $auteurSousVerrou = $this->exigerLeProprietaire(ValeurRequise::de($auteurSousVerrou, 'l\'auteur de la déclaration, relu sous verrou'), $client);
                self::exigerUneSocieteValidee($client);
                $existant = $this->creation->compteDejaCree($auteurSousVerrou, $client, $jeton, $empreinte);

                if ($existant !== null) {
                    return ['existant' => $existant];
                }

                $enCours = self::lienEnCours($client);
                $this->refuserLeLienEnCoursDuMemeCabinet($enCours, $siren);

                if ($enCours !== null && ! $remplacer) {
                    throw ValidationException::withMessages(['remplacer' => 'Votre société a déjà un expert-comptable : '.self::nomDuLienEnCours($enCours).'. Cochez la case pour le remplacer.']);
                }

                if (ValeursLues::entiers(self::demandesEnvoyees($client)->modelKeys()) !== ValeursLues::entiers($demandes->modelKeys())) {
                    throw new LogicException(self::MESSAGE_LIENS_CHANGES);
                }

                $comptesRemplaces = $enCours === null ? new Collection : $this->acces->comptesQuiOntAcces($enCours);

                if ($enCours !== null) {
                    MettreFinAuLienAction::terminerSousVerrou($enCours, $auteurSousVerrou, MotifDeFinDuLien::Remplace);
                }

                foreach ($demandesRelues as $demande) {
                    RepondreAUneDemandeDeLien::cloreSousVerrou($demande, EtatDuLien::Retire, $auteurSousVerrou);
                }

                $declaration = tap((new LienCabinet)->forceFill([
                    'origine' => OrigineDuLien::DeclarationDuClient,
                    'etat' => EtatDuLien::Declare,
                    'siren_cabinet' => $siren,
                    'denomination_cabinet' => $denomination,
                    'demande_par' => $auteurSousVerrou->getKey(),
                    'demande_le' => now(),
                ]))->save();
                $declaration->accorder($autorisations);
                $declaration->choisirLesMagasins($magasins);

                $this->creation->verifierLaLimiteDuJour($auteurSousVerrou, $client);
                $compte = $this->creation->creer($auteurSousVerrou, $client, $identite, RoleSociete::ExpertComptable, null, $jeton, $empreinte, $adresse);
                $attachement = tap((new CompteExpertComptable)->forceFill(['lien_id' => $declaration->getKey(), 'user_id' => $compte->getKey()]))->save();

                return [
                    'compte' => $compte,
                    'declaration' => $declaration,
                    'remplace' => $enCours,
                    'comptesRemplaces' => $comptesRemplaces,
                    'demandes' => $demandesRelues,
                    'jetonDuLien' => $attachement->nouveauLien(),
                ];
            }, fn (Societe $journal, array $demandesRelues, array $resultat): array => self::entrees($journal, $societe, $resultat));
        } catch (UniqueConstraintViolationException $doublon) {
            if (! CreationDesComptes::estUnJetonDejaServi($doublon)) {
                throw $doublon;
            }

            return app(TenantContext::class)->runAsSociete($societe, fn (): User => $this->creation->compteDejaCree($auteurRelu->refresh(), $societe, $jeton, $empreinte) ?? throw $doublon);
        }

        if (isset($resultat['existant'])) {
            return $resultat['existant'];
        }

        $compte = $resultat['compte'];
        $this->debit->compter($auteurRelu);
        RattacherLesDeclarationsDuSiren::dispatch($siren);
        $this->prevenirLesCabinets($societe, $resultat['remplace'], $resultat['comptesRemplaces'], $resultat['demandes'], $auteurRelu);
        $this->envoi->envoyer(CleDuModele::de($compte), $resultat['jetonDuLien'], CleDuModele::de($auteurRelu), accesRedonne: false);

        return $compte;
    }

    /**
     * Le lien en cours de cette société, actif ou déclaré, lu dans sa
     * société ; null s'il n'y en a aucun.
     */
    public static function lienEnCours(Societe $societe): ?LienCabinet
    {
        return app(TenantContext::class)->runAsSociete($societe, fn (): ?LienCabinet => LienCabinet::query()->enCours()->first());
    }

    /**
     * Le nom de l'expert-comptable que ce lien en cours désigne : la
     * dénomination du cabinet lié, ou celle du cabinet déclaré.
     */
    public static function nomDuLienEnCours(LienCabinet $lien): string
    {
        return $lien->cabinet_id === null
            ? $lien->nomDuCabinetDeclare()
            : Societe::query()->findOrFail($lien->cabinet_id)->getFilamentName();
    }

    /**
     * Les demandes de lien que cette société a envoyées (désignation d'un
     * cabinet vérifié) et qui attendent encore leur réponse.
     *
     * @return Collection<int, LienCabinet>
     */
    public static function demandesEnvoyees(Societe $societe): Collection
    {
        return app(TenantContext::class)->runAsSociete($societe, fn (): Collection => LienCabinet::query()->where('etat', EtatDuLien::DemandeParLeClient->value)->orderBy('id')->get());
    }

    /**
     * @throws ValidationException
     */
    private function validerLeSiren(mixed $siren, Societe $societe): string
    {
        $valide = Validator::make(['siren' => is_string($siren) ? preg_replace('/\s+/', '', $siren) : $siren], ['siren' => ['required', 'string', new SirenValide]], [], ['siren' => 'SIREN de son cabinet'])->validate();
        $siren = ValeursLues::texteDe($valide, 'siren');

        if ($siren === (string) $societe->siren) {
            throw ValidationException::withMessages(['siren' => self::MESSAGE_SON_PROPRE_SIREN]);
        }

        return $siren;
    }

    /**
     * @param  array<array-key, mixed>|mixed  $autorisations
     * @return list<string>
     *
     * @throws ValidationException
     */
    private static function autorisationsValidees(mixed $autorisations, Societe $societe): array
    {
        try {
            return PermissionRegistry::verifierDuCabinet(is_array($autorisations) ? $autorisations : [], $societe->type_activite);
        } catch (InvalidArgumentException $refus) {
            throw ValidationException::withMessages(['autorisations' => $refus->getMessage()]);
        }
    }

    /**
     * Le propriétaire actif de cette société, relu, qui gère son cabinet
     * (voir SocietePolicy::gererSonCabinet()) : jamais dans un cabinet, ni
     * dans une société close ou révoquée.
     *
     * @throws AuthorizationException
     */
    private function exigerLeProprietaire(User $auteur, Societe $societe): User
    {
        try {
            return $this->journalDesDroits->exigerDansSaSociete($auteur, 'gererSonCabinet', $societe);
        } catch (AuthorizationException) {
            throw new AuthorizationException(self::MESSAGE_PROPRIETAIRE_SEUL);
        }
    }

    /**
     * Seule une société validée déclare son expert-comptable : une société
     * qui attend le code de son courrier demande le lien à un cabinet déjà
     * présent ou invite son expert-comptable à créer son espace.
     *
     * @throws LogicException
     */
    private static function exigerUneSocieteValidee(Societe $societe): void
    {
        $relue = Societe::query()->findOrFail($societe->getKey());

        if (! $relue->estValidee() || $relue->estClose() || $relue->statut === StatutSociete::Revoquee) {
            throw new LogicException(self::MESSAGE_SOCIETE_NON_VALIDEE);
        }
    }

    /**
     * Le SIREN déjà déclaré, ou celui du cabinet déjà lié, ne se déclare
     * pas de nouveau.
     *
     * @throws ValidationException
     */
    private function refuserLeLienEnCoursDuMemeCabinet(?LienCabinet $enCours, string $siren): void
    {
        if ($enCours === null) {
            return;
        }

        if ($enCours->estDeclare() && $enCours->siren_cabinet === $siren) {
            throw ValidationException::withMessages(['siren' => self::MESSAGE_DEJA_DECLARE]);
        }

        if ($enCours->estActif() && Societe::query()->whereKey($enCours->cabinet_id)->value('siren') === $siren) {
            throw ValidationException::withMessages(['siren' => self::MESSAGE_DEJA_LIE]);
        }
    }

    /**
     * L'entreprise de ce SIREN, connue, active et de code d'activité 69.20Z
     * au répertoire Sirene, hors de toute transaction, au plus 10 fois par
     * heure et par société : rend sa dénomination, nulle quand le
     * répertoire la masque.
     *
     * @throws ValidationException
     */
    private function controlerAuRepertoire(string $siren, Societe $societe): ?string
    {
        $cle = 'declaration-expert-comptable:'.CleDuModele::de($societe);

        if (RateLimiter::tooManyAttempts($cle, self::CONTROLES_DU_REPERTOIRE_PAR_HEURE)) {
            throw ValidationException::withMessages(['siren' => self::MESSAGE_TROP_DE_CONTROLES]);
        }

        RateLimiter::hit($cle, 3600);
        $controle = $this->sirene->unite($siren);

        if ($controle['resultat'] === ResultatVerification::Indisponible) {
            throw ValidationException::withMessages(['siren' => self::MESSAGE_REPERTOIRE_INJOIGNABLE]);
        }

        if ($controle['resultat'] !== ResultatVerification::Concluante || $controle['unite'] === null) {
            throw ValidationException::withMessages(['siren' => $controle['motif'] ?? ControleSirene::MOTIF_ENTREPRISE_INCONNUE]);
        }

        if (ControleSirene::exerceLesActivitesComptables($controle['unite']) !== true) {
            throw ValidationException::withMessages(['siren' => self::MESSAGE_PAS_UN_CABINET_COMPTABLE]);
        }

        return $controle['unite']->denomination;
    }

    /**
     * Les entrées de chaque journal : au journal du client, la fin du lien
     * remplacé, le retrait de chaque demande, puis la déclaration avec les
     * droits des comptes de la société ; au journal d'un cabinet, la fin de
     * son lien ou le retrait de sa demande, sans rien de la déclaration.
     *
     * @param  array<string, mixed>  $resultat
     * @return list<array{0: ActionAuditee, 1: array<string, mixed>, 2: int|null}>
     */
    private static function entrees(Societe $journal, Societe $client, array $resultat): array
    {
        if (isset($resultat['existant'])) {
            return [];
        }

        $duClient = $journal->is($client);
        $entrees = [];
        $remplace = $resultat['remplace'] ?? null;

        if ($remplace instanceof LienCabinet && ($duClient || (int) $remplace->cabinet_id === CleDuModele::de($journal))) {
            $entrees[] = [ActionAuditee::LienTermine, [
                'cabinet' => $remplace->cabinet_id === null ? IdentiteDansLeJournal::declaree($remplace) : IdentiteDansLeJournal::de(Societe::query()->findOrFail($remplace->cabinet_id)),
                'client' => IdentiteDansLeJournal::de($client),
                'motif' => MotifDeFinDuLien::Remplace->value,
            ], $remplace->cabinet_id === null ? CleDuModele::de($client) : (int) $remplace->cabinet_id];
        }

        foreach ($resultat['demandes'] ?? [] as $demande) {
            if ($duClient || (int) $demande->cabinet_id === CleDuModele::de($journal)) {
                $entrees[] = [ActionAuditee::DemandeDeLienRetiree, [
                    'cabinet' => IdentiteDansLeJournal::de(Societe::query()->findOrFail($demande->cabinet_id)),
                    'client' => IdentiteDansLeJournal::de($client),
                ], null];
            }
        }

        if ($duClient) {
            $entrees[] = [ActionAuditee::ExpertComptableDeclare, [
                'cabinet' => IdentiteDansLeJournal::declaree($resultat['declaration']),
                'autorisations' => $resultat['declaration']->autorisations(),
                'magasins' => IdentiteDansLeJournal::magasinsOuverts($resultat['declaration']),
            ], CleDuModele::de($client)];
        }

        return $entrees;
    }

    /**
     * Prévient, après la transaction, le cabinet lié remplacé (sans
     * apprendre par qui) et chaque cabinet dont la demande est retirée ;
     * les personnes d'une déclaration remplacée ne reçoivent pas d'e-mail.
     *
     * @param  Collection<int, User>  $comptesRemplaces
     * @param  list<LienCabinet>  $demandes
     */
    private function prevenirLesCabinets(Societe $client, ?LienCabinet $remplace, Collection $comptesRemplaces, array $demandes, User $auteur): void
    {
        $raison = 'votre client a choisi un autre expert-comptable.';

        if ($remplace !== null && $remplace->cabinet_id !== null) {
            MettreFinAuLienAction::prevenir($remplace, $comptesRemplaces, $auteur, $raison, prevenirLeClient: false);
        }

        foreach ($demandes as $demande) {
            $cabinet = Societe::query()->findOrFail($demande->cabinet_id);

            try {
                $cabinet->proprietaire()->first()?->notify(new AvisDuCabinet(
                    $cabinet,
                    'Demande de lien retirée',
                    "{$client->getFilamentName()} (SIREN {$client->siren}) a retiré sa demande de lien : {$raison}",
                    InvitationsEtDemandes::getUrl(panel: 'cabinet', tenant: $cabinet),
                    'Voir les invitations et demandes',
                    AvisDuCabinet::INFORMATION,
                    parCourriel: true,
                    clientId: CleDuModele::de($client),
                ));
            } catch (Throwable $erreur) {
                report($erreur);
            }
        }
    }
}
