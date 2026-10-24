<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Filament\Societe\Resources\MembreResource;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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
 *   quand il l'est déjà (rien à remettre).
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

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesDroits $portee,
        private readonly SessionsDesComptes $sessions,
        private readonly JournalAudit $journalAudit,
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

                if (! $enPresence && $compteRelu->mot_de_passe_est_l_identifiant) {
                    throw new LogicException(self::MESSAGE_DEJA_L_IDENTIFIANT);
                }

                $compteRelu->forceFill([
                    'password' => $enPresence ? (string) $motDePasse : (string) $compteRelu->identifiantDeConnexion(),
                    'mot_de_passe_est_l_identifiant' => ! $enPresence,
                    'acces_redonne_le' => now(),
                    'acces_redonne_par' => $auteurRelu->getKey(),
                    'acces_redonne_en_presence' => $enPresence,
                    'signaler_le_prochain_mot_de_passe' => ! $auteurRelu->estProprietaire(),
                    'jeton_acces_redonne' => $enPresence ? $jeton : null,
                ])->save();
                $this->sessions->fermer($compteRelu);
                $parLeProprietaire = $auteurRelu->estProprietaire();
                $this->journalAudit->inscrire(ActionAuditee::AccesRedonne, $auteurRelu, null, null, [
                    'compte' => ['id' => $compteRelu->getKey(), 'nom' => $compteRelu->name],
                    'en_presence' => $enPresence,
                ]);

                return true;
            });
        });

        if (! $redonne) {
            return false;
        }

        $compte->refresh();

        if (! $parLeProprietaire) {
            $societe->proprietaire?->notify(new AvisDeLEquipe(
                $societe,
                'Accès redonné',
                "{$auteur->name} a redonné l'accès de {$compte->name}, ".($enPresence ? 'en sa présence : la personne a tapé elle-même son nouveau mot de passe.' : 'en son absence : son mot de passe est redevenu son identifiant.').' Vous serez prévenu du premier mot de passe que cette personne choisira ensuite.',
                MembreResource::getUrl('view', ['record' => $compte], panel: 'societe', tenant: $societe),
                nature: AvisDeLEquipe::ATTENTION,
                parCourriel: true,
            ));
        }

        return true;
    }
}
