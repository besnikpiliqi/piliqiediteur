<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Cabinets\Models\CompteExpertComptable;
use App\Domain\Cabinets\Services\EnvoiDesLiensDesExpertsComptables;
use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ModeDAccesRedonne;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Filament\Societe\Resources\MembreResource;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

/**
 * Redonne l'accès à un compte actif qui l'a perdu, par qui en a le droit
 * (voir PorteeDesDroits::peutRedonnerLAcces()) : le propriétaire pour tout
 * compte ; un responsable de son magasin pour un vendeur ou un observateur ;
 * un administrateur autorisé à nommer les responsables pour un responsable
 * d'un magasin réel de sa portée ; un administrateur autorisé à redonner
 * l'accès pour un vendeur ou un observateur d'un magasin réel de sa portée.
 * Personne ne redonne l'accès du propriétaire, qui passe par « Mot de passe
 * oublié ».
 * - La personne est là : elle tape elle-même deux fois son nouveau mot de
 *   passe sur l'écran de qui le redonne, jamais affiché, suivant la règle
 *   du mot de passe choisi (voir RegleDuMotDePasse) ; la fenêtre porte un
 *   jeton d'écran, et la même demande répétée après une réponse perdue
 *   reste sans effet.
 * - Elle est absente : son mot de passe redevient son identifiant ; refusé
 *   quand il l'est déjà (rien à remettre), et toujours pour un
 *   collaborateur d'un cabinet, que son identifiant n'ouvre jamais, faute
 *   de poste reconnu (voir PostesReconnus::ouvreLeCompte()), et pour un
 *   expert-comptable de la société, qui n'a jamais son identifiant pour
 *   mot de passe.
 * - Pour un expert-comptable de la société seulement, par un nouveau lien
 *   de 7 jours envoyé à son adresse (voir parUnNouveauLien()) : son mot de
 *   passe d'avant ne sert plus dès l'envoi, remplacé par un mot de passe
 *   tiré au hasard que personne ne connaît, et il choisit le sien par le
 *   lien ; au plus 5 liens par jour et par compte. En sa présence, son
 *   lien en cours s'efface et la date de son premier mot de passe choisi
 *   s'écrit, ce qui ferme la correction de son adresse par le
 *   propriétaire.
 * Dans les deux cas, sous le verrou des lignes de l'auteur et du compte
 * relues : toutes les sessions du compte se ferment et son « se souvenir
 * de moi » est coupé ; la date, l'auteur et le mode s'écrivent sur le
 * compte, montrés à la personne à sa connexion suivante (voir
 * SuiviDesConnexions) ; l'entrée d'audit AccesRedonne s'inscrit, sans
 * identifiant ni mot de passe ; le propriétaire est prévenu, dans sa cloche
 * et par e-mail, de chaque accès redonné par un autre, puis du premier mot
 * de passe que la personne choisit ensuite (voir
 * ChangerSonMotDePasseAction). Un accès redonné en l'absence d'un
 * administrateur, son mot de passe redevenu son identifiant, lui interdit
 * de recevoir la propriété tant qu'il n'a pas choisi le sien (voir
 * DemanderLaTransmissionDeLaProprieteAction::exigerLesConditions()).
 */
class RedonnerLAccesAction
{
    use AgitDansLaSociete;

    public const MESSAGE_INACTIF = 'L\'accès d\'un compte suspendu ou retiré ne se redonne pas : réactivez-le d\'abord.';

    public const MESSAGE_DEJA_L_IDENTIFIANT = 'Son mot de passe est déjà son identifiant.';

    public const MESSAGE_DEJA_REDONNE = 'L\'accès a déjà été redonné.';

    public const MESSAGE_COLLABORATEUR_PRESENT = 'Un collaborateur choisit son mot de passe en votre présence : son identifiant n\'ouvre pas son compte.';

    public const MESSAGE_EXPERT_COMPTABLE = 'Un expert-comptable de la société reçoit un nouveau lien à son adresse, ou choisit son mot de passe en votre présence : son identifiant n\'ouvre jamais son compte.';

    public const MESSAGE_LIEN_POUR_L_EXPERT_COMPTABLE_SEUL = 'Seul un expert-comptable de la société reçoit un nouveau lien à son adresse.';

    public const MESSAGE_TROP_DE_LIENS = 'Vous avez déjà envoyé 5 liens à cette personne aujourd\'hui : réessayez demain.';

    public const LIENS_PAR_JOUR = 5;

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesDroits $portee,
        private readonly SessionsDesComptes $sessions,
        private readonly JournalAudit $journalAudit,
        private readonly DebitDesEmails $debit,
        private readonly EnvoiDesLiensDesExpertsComptables $envoi,
    ) {}

    /**
     * @param  string|null  $motDePasse  le mot de passe que la personne présente tape elle-même ; null en son absence
     * @param  string|null  $jeton  le jeton de la fenêtre, tiré à son ouverture, en présence de la personne
     * @return bool faux quand la même demande, au même jeton, a déjà redonné l'accès
     *
     * @throws AuthorizationException
     * @throws ValidationException quand le mot de passe tapé ne suit pas la règle
     * @throws LogicException
     */
    public function handle(User $auteur, User $compte, bool $enPresence, #[SensitiveParameter] ?string $motDePasse = null, ?string $jeton = null): bool
    {
        if ($enPresence) {
            RegleDuMotDePasse::exiger((string) $motDePasse, $compte->identifiantDeConnexion(), 'mot_de_passe');
        }

        $societe = $compte->societe()->firstOrFail();

        $parLeProprietaire = false;
        $redonne = $this->dansLaSociete($societe, PorteeDesDroits::REFUS, function () use ($auteur, $compte, $enPresence, $motDePasse, $jeton, &$parLeProprietaire): bool {
            return $this->journalDesDroits->sousVerrouDesComptes($auteur, [$compte], function (User $auteurRelu, array $relus) use ($enPresence, $motDePasse, $jeton, &$parLeProprietaire): bool {
                [$compteRelu] = $relus;

                if ($this->portee->gereSonEquipe($auteurRelu) && (int) $compteRelu->societe_id === (int) $auteurRelu->societe_id && ! $compteRelu->estActif()) {
                    throw new LogicException(self::MESSAGE_INACTIF);
                }

                if (! $this->portee->peutRedonnerLAcces($auteurRelu, $compteRelu)) {
                    throw new AuthorizationException(PorteeDesDroits::REFUS);
                }

                if ($enPresence && $jeton !== null && $compteRelu->jeton_acces_redonne === $jeton) {
                    return false;
                }

                if (! $enPresence && $compteRelu->estCollaborateur()) {
                    throw new LogicException(self::MESSAGE_COLLABORATEUR_PRESENT);
                }

                if (! $enPresence && $compteRelu->estExpertComptable()) {
                    throw new LogicException(self::MESSAGE_EXPERT_COMPTABLE);
                }

                if (! $enPresence && $compteRelu->mot_de_passe_est_l_identifiant) {
                    throw new LogicException(self::MESSAGE_DEJA_L_IDENTIFIANT);
                }

                $compteRelu->forceFill([
                    'password' => $enPresence ? (string) $motDePasse : (string) $compteRelu->identifiantDeConnexion(),
                    'mot_de_passe_est_l_identifiant' => ! $enPresence,
                    'acces_redonne_le' => now(),
                    'acces_redonne_par' => $auteurRelu->getKey(),
                    'acces_redonne_mode' => $enPresence ? ModeDAccesRedonne::Presence : ModeDAccesRedonne::Absence,
                    'signaler_le_prochain_mot_de_passe' => ! $auteurRelu->estProprietaire(),
                    'jeton_acces_redonne' => $enPresence ? $jeton : null,
                ])->save();

                if ($compteRelu->estExpertComptable()) {
                    $attachement = self::attachementVerrouille($compteRelu);
                    $attachement->oublierLeLien();

                    if (! $attachement->aChoisiSonMotDePasse()) {
                        $attachement->forceFill(['mot_de_passe_choisi_le' => now()])->save();
                    }
                }

                $this->sessions->fermer($compteRelu);
                $parLeProprietaire = $auteurRelu->estProprietaire();
                $this->journalAudit->inscrire(ActionAuditee::AccesRedonne, $auteurRelu, null, null, [
                    'compte' => ['id' => $compteRelu->getKey(), 'nom' => $compteRelu->name],
                    'mode' => ($enPresence ? ModeDAccesRedonne::Presence : ModeDAccesRedonne::Absence)->value,
                ]);

                return true;
            });
        });

        if (! $redonne) {
            return false;
        }

        $compte->refresh();
        $this->prevenirLeProprietaire($societe, $auteur, $compte, $parLeProprietaire, $enPresence ? ModeDAccesRedonne::Presence : ModeDAccesRedonne::Absence);

        return true;
    }

    /**
     * Redonne l'accès d'un expert-comptable de la société par un nouveau
     * lien de 7 jours envoyé à son adresse, où il choisit son mot de passe
     * (décision de l'éditeur du 01/11/2026, P4) : sous le verrou des lignes
     * de l'auteur et du compte, puis de sa ligne d'attachement, son mot de
     * passe devient un aléatoire que personne ne connaît, ses sessions se
     * ferment et le lien d'avant ne vaut plus. Le jeton de la fenêtre rend
     * sans effet la même demande répétée, sans second e-mail ; au plus 5
     * liens par jour et par compte.
     *
     * @return bool faux quand la même demande, au même jeton, a déjà envoyé le lien
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function parUnNouveauLien(User $auteur, User $compte, string $jeton): bool
    {
        $societe = $compte->societe()->firstOrFail();
        $cle = 'lien-expert-comptable:'.CleDuModele::de($compte);

        $parLeProprietaire = false;
        $lien = $this->dansLaSociete($societe, PorteeDesDroits::REFUS, function () use ($auteur, $compte, $jeton, $cle, &$parLeProprietaire): ?array {
            return $this->journalDesDroits->sousVerrouDesComptes($auteur, [$compte], function (User $auteurRelu, array $relus) use ($jeton, $cle, &$parLeProprietaire): ?array {
                [$compteRelu] = $relus;

                if ($this->portee->gereSonEquipe($auteurRelu) && (int) $compteRelu->societe_id === (int) $auteurRelu->societe_id && ! $compteRelu->estActif()) {
                    throw new LogicException(self::MESSAGE_INACTIF);
                }

                if (! $this->portee->peutRedonnerLAcces($auteurRelu, $compteRelu)) {
                    throw new AuthorizationException(PorteeDesDroits::REFUS);
                }

                if (! $compteRelu->estExpertComptable()) {
                    throw new LogicException(self::MESSAGE_LIEN_POUR_L_EXPERT_COMPTABLE_SEUL);
                }

                if ($compteRelu->jeton_acces_redonne === $jeton) {
                    return null;
                }

                if (RateLimiter::tooManyAttempts($cle, self::LIENS_PAR_JOUR)) {
                    throw new LogicException(self::MESSAGE_TROP_DE_LIENS);
                }

                $this->debit->verifier($auteurRelu);
                $compteRelu->forceFill([
                    'password' => Str::password(64),
                    'mot_de_passe_est_l_identifiant' => false,
                    'acces_redonne_le' => now(),
                    'acces_redonne_par' => $auteurRelu->getKey(),
                    'acces_redonne_mode' => ModeDAccesRedonne::Lien,
                    'signaler_le_prochain_mot_de_passe' => ! $auteurRelu->estProprietaire(),
                    'jeton_acces_redonne' => $jeton,
                ])->save();
                $jetonDuLien = self::attachementVerrouille($compteRelu)->nouveauLien();
                $this->sessions->fermer($compteRelu);
                $parLeProprietaire = $auteurRelu->estProprietaire();
                $this->journalAudit->inscrire(ActionAuditee::AccesRedonne, $auteurRelu, null, null, [
                    'compte' => ['id' => $compteRelu->getKey(), 'nom' => $compteRelu->name],
                    'mode' => ModeDAccesRedonne::Lien->value,
                ]);

                return ['jeton' => $jetonDuLien, 'auteur' => CleDuModele::de($auteurRelu)];
            });
        });

        if ($lien === null) {
            return false;
        }

        RateLimiter::hit($cle, 86400);
        $this->debit->compter($auteur);
        $compte->refresh();
        $this->envoi->envoyer(CleDuModele::de($compte), $lien['jeton'], $lien['auteur'], accesRedonne: true);
        $this->prevenirLeProprietaire($societe, $auteur, $compte, $parLeProprietaire, ModeDAccesRedonne::Lien);

        return true;
    }

    /**
     * La ligne d'attachement d'un expert-comptable de la société, verrouillée
     * après son compte, que l'appelant tient déjà.
     *
     * @throws LogicException
     */
    private static function attachementVerrouille(User $compte): CompteExpertComptable
    {
        return CompteExpertComptable::query()->where('societe_id', $compte->societe_id)->where('user_id', $compte->getKey())->lockForUpdate()->first()
            ?? throw new LogicException('Ce compte d\'expert-comptable n\'est attaché à aucune déclaration.');
    }

    /**
     * Prévient le propriétaire, dans sa cloche et par e-mail, d'un accès
     * redonné par un autre que lui.
     */
    private function prevenirLeProprietaire(Societe $societe, User $auteur, User $compte, bool $parLeProprietaire, ModeDAccesRedonne $mode): void
    {
        if (! $parLeProprietaire) {
            $societe->proprietaire?->notify(new AvisDeLEquipe(
                $societe,
                'Accès redonné',
                "{$auteur->name} a redonné l'accès de {$compte->name}, ".$mode->phraseAuProprietaire().match ($mode) {
                    ModeDAccesRedonne::Presence => ' : la personne a tapé elle-même son nouveau mot de passe.',
                    ModeDAccesRedonne::Absence => ' : son mot de passe est redevenu son identifiant.',
                    ModeDAccesRedonne::Lien => ' : elle choisira son mot de passe par ce lien.',
                }.' Vous serez prévenu du premier mot de passe que cette personne choisira ensuite.',
                MembreResource::getUrl('view', ['record' => $compte], panel: 'societe', tenant: $societe),
                nature: AvisDeLEquipe::ATTENTION,
                parCourriel: true,
            ));
        }
    }
}
