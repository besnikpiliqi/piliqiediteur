<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TransmissionPropriete;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\JetonsDuLien;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesAdministrateurs;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use SensitiveParameter;

/**
 * L'administrateur suit le lien de la transmission de la propriété (voir
 * DemanderLaTransmissionDeLaProprieteAction), connecté à son compte, et la
 * confirme en redonnant son mot de passe (voir AccepterLaTransmission) :
 * c'est seulement là que la propriété passe. Un lien inconnu, expiré,
 * annulé, déjà suivi, remplacé par un lien renvoyé, demandé par un compte
 * qui n'est plus propriétaire ou ouvert par un autre compte ne change rien
 * et le dit sans détail.
 *
 * Sous les verrous des registres de tous les magasins de la société, puis
 * des lignes des deux comptes (voir JournalDesDroits), puis de la ligne de
 * la demande (l'annulation verrouille les deux comptes, puis la demande,
 * dans le même ordre : aucun interblocage), tout se relit : la demande est
 * valable ; le propriétaire l'est toujours et gère encore son équipe, dans
 * une société qui n'est pas révoquée (voir PorteeDesDroits::gereSonEquipe(),
 * comme la demande et l'annulation) ; l'administrateur remplit toujours les
 * conditions de la demande (inscription qui n'est pas close, compte actif,
 * mot de passe qui n'est plus son identifiant, adresse renseignée par lui,
 * différente de celle du propriétaire), sans aucune preuve de qualité de
 * représentant ; son adresse est celle de la demande et n'est l'adresse de
 * connexion d'aucun propriétaire. L'index unique des adresses de connexion
 * des propriétaires refuse en dernier lieu une adresse prise entre-temps.
 *
 * Dans cet ordre, à cause des gardes de la base et de l'index d'un seul
 * propriétaire par société (voir GardesDesDroits) : le nouveau propriétaire
 * perd sa portée et ses autorisations de société, l'ancien devient
 * administrateur, le nouveau devient propriétaire, son adresse devenant
 * son adresse de connexion, dans la même mise à jour, puis l'ancien reçoit
 * tous les magasins, présents et futurs, avec les autorisations de magasin
 * qu'il avait comme propriétaire, toutes sauf celles de la vente (voir
 * PermissionRegistry::duProprietaire()), que le nouveau propriétaire peut
 * lui cocher, et toutes celles de la société. L'ancien propriétaire garde
 * son identifiant et ne se connecte plus qu'avec lui (son adresse n'est
 * plus qu'un contact, sans date de confirmation) ; ses sessions se
 * ferment, son « se souvenir de moi » est coupé, son lien « Mot de passe
 * oublié » en attente s'efface, et la saisie du code du courrier qu'il
 * avait faite ne compte plus : devenu propriétaire par une transmission,
 * le nouveau comme lui s'il le redevient signe les attestations à la main
 * (voir User::signeLesAttestationsDansLApplication()). Le changement
 * s'inscrit au JET 130 de chaque magasin et au journal d'audit (ProprieteTransferee,
 * voir JournalDesDroits), et chacun des deux en est prévenu. Le nouveau propriétaire ne vend plus : une caisse qu'il avait
 * ouverte comme administrateur se ferme comme celle de tout vendeur, par
 * lui-même (voir ClotureCaissePersonnellePolicy::fermer()), pour lui par
 * qui peut clôturer la journée, ou d'office par la clôture du jour ; tant
 * qu'elle reste ouverte, il n'opère dans aucun autre magasin (voir
 * VerificateurCaissesPersonnelles::refuserSiSaCaisseEstOuverteAilleurs()).
 * Un brouillon qu'il avait créé devient, à son émission, la vente de qui
 * l'émet (voir EmettreFactureAction).
 */
class AccepterLaTransmissionDeLaProprieteAction
{
    use AgitDansLaSociete;

    public const MESSAGE_LIEN = 'Ce lien n\'est plus valable, ou il ne vous est pas destiné.';

    public const MESSAGE_ADRESSE_CHANGEE = 'Votre adresse a changé depuis la demande : demandez au propriétaire un nouveau lien.';

    public const MESSAGE_ADRESSE_PRISE = 'La propriété n\'a pas pu vous être transmise avec cette adresse : renseignez une autre adresse dans votre profil, puis demandez un nouveau lien.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesAdministrateurs $porteeDesAdministrateurs,
        private readonly SessionsDesComptes $sessions,
        private readonly JetonsDuLien $jetonsDuLien,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $administrateur, string $jeton, #[SensitiveParameter] string $motDePasse): void
    {
        $demande = self::demandeDestineeA($administrateur, $jeton) ?? throw new LogicException(self::MESSAGE_LIEN);
        $societe = Societe::query()->findOrFail($demande->societe_id);

        MotDePasseRedemande::exiger($administrateur, $motDePasse);

        $ancien = $this->dansLaSociete($societe, self::MESSAGE_LIEN, function () use ($administrateur, $demande, $societe): User {
            $ancien = User::query()->where('societe_id', $societe->getKey())->where('role_societe', RoleSociete::Proprietaire->value)->first()
                ?? throw new LogicException(self::MESSAGE_LIEN);
            $magasins = Magasin::query()->where('societe_id', $societe->getKey())->orderBy('id')->get();

            try {
                $this->journalDesDroits->modifier($administrateur, ActionAuditee::ProprieteTransferee, [$administrateur, $ancien], $magasins, function (User $auteurRelu, array $relus) use ($demande, $societe): void {
                    [$nouveauRelu, $ancienRelu] = $relus;
                    $demandeRelue = TransmissionPropriete::query()->whereKey($demande->getKey())->lockForUpdate()->first();

                    if ($demandeRelue === null || ! $demandeRelue->estValable() || $demandeRelue->jeton_hache !== $demande->jeton_hache
                        || (int) $demandeRelue->destinataire_id !== (int) $nouveauRelu->getKey()
                        || ! $ancienRelu->estProprietaire() || (int) $demandeRelue->demandee_par !== (int) $ancienRelu->getKey()
                        || ! app(PorteeDesDroits::class)->gereSonEquipe($ancienRelu)) {
                        throw new LogicException(self::MESSAGE_LIEN);
                    }

                    DemanderLaTransmissionDeLaProprieteAction::exigerLesConditions($ancienRelu, $nouveauRelu, $societe);

                    if ($nouveauRelu->email !== $demandeRelue->adresse) {
                        throw new LogicException(self::MESSAGE_ADRESSE_CHANGEE);
                    }

                    if (User::query()->where('role_societe', RoleSociete::Proprietaire->value)->where('email', $demandeRelue->adresse)->exists()) {
                        throw new LogicException(self::MESSAGE_ADRESSE_PRISE);
                    }

                    $this->porteeDesAdministrateurs->effacer($nouveauRelu);
                    $ancienRelu->forceFill([
                        'role_societe' => RoleSociete::Administrateur,
                        'email_verified_at' => null,
                        'code_du_courrier_saisi_le' => null,
                        'nouvelle_adresse' => null,
                        'nouvelle_adresse_jeton_hache' => null,
                        'nouvelle_adresse_expire_le' => null,
                    ])->save();
                    $nouveauRelu->forceFill([
                        'role_societe' => RoleSociete::Proprietaire,
                        'email' => $demandeRelue->adresse,
                        'email_verified_at' => now(),
                    ])->save();
                    $this->porteeDesAdministrateurs->ecrire($ancienRelu, [
                        'tous' => true,
                        'magasins' => new Collection,
                        'cases' => [0 => PermissionRegistry::duProprietaire()],
                        'societe' => AutorisationsSociete::toutes(),
                    ]);
                    $this->sessions->fermer($ancienRelu);
                    $this->jetonsDuLien->effacer((string) $ancienRelu->email);
                    $demandeRelue->forceFill(['acceptee_le' => now()])->save();
                });
            } catch (UniqueConstraintViolationException $doublon) {
                if (! str_contains($doublon->getMessage(), 'users_adresse_de_connexion') && ! str_contains($doublon->getMessage(), 'users.email')) {
                    throw $doublon;
                }

                throw new LogicException(self::MESSAGE_ADRESSE_PRISE);
            }

            return $ancien;
        });

        $ancien->refresh()->notify(new AvisDeLEquipe($societe, 'Propriété transmise', "{$administrateur->name} a accepté la propriété de {$societe->getFilamentName()} : vous en êtes désormais administrateur de tous les magasins, avec toutes les autorisations de magasin sauf celles de la vente, que le nouveau propriétaire peut vous cocher, et toutes celles de la société. Vous vous connectez désormais avec votre identifiant."));
        $administrateur->refresh()->notify(new AvisDeLEquipe($societe, 'Vous êtes propriétaire', "{$ancien->name} vous a transmis la propriété de {$societe->getFilamentName()} : vous avez désormais tous les droits dans la société et dans chacun de ses magasins, sauf celui de vendre : le propriétaire supervise et contrôle. Votre adresse {$administrateur->email} est désormais votre adresse de connexion.", nature: AvisDeLEquipe::ATTENTION));
    }

    /**
     * La demande que désigne ce lien, si elle est encore valable et
     * destinée à ce compte ; null sinon, sans dire pourquoi. La seule
     * lecture hors de la société active : la demande que désigne
     * l'empreinte du jeton, et rien d'autre (voir
     * TransmissionPropriete::demandeDuLien()).
     */
    public static function demandeDestineeA(User $compte, string $jeton): ?TransmissionPropriete
    {
        $demande = $jeton === '' ? null : TransmissionPropriete::demandeDuLien($jeton);

        return $demande !== null
            && $demande->estValable()
            && (int) $demande->destinataire_id === (int) $compte->getKey()
            && (int) $demande->societe_id === (int) $compte->societe_id
                ? $demande
                : null;
    }
}
