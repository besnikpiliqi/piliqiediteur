<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Models\CompteExpertComptable;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\EnvoiDesLiensDesExpertsComptables;
use App\Domain\Cabinets\Services\RechercheDesCabinets;
use App\Domain\Cabinets\Support\IdentiteDansLeJournal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

/**
 * Le propriétaire ajoute une personne du cabinet déjà déclaré comme
 * expert-comptable de la société (voir DeclarerSonExpertComptableAction),
 * en redonnant son mot de passe : son nom et son adresse professionnelle,
 * saisie deux fois. Elle reçoit son compte et son identifiant, avec les
 * autorisations de la déclaration, communes à toutes ses personnes, et le
 * lien de 7 jours de son mot de passe. Refusé quand le cabinet de ce SIREN
 * a désormais son espace vérifié sur PILIQI (son compte se fermerait
 * aussitôt) ou quand la déclaration n'est plus en attente ; une demande
 * répétée après une réponse perdue rend le compte déjà créé, sans second
 * lien ; les créations comptent dans la limite du jour du propriétaire.
 */
class AjouterUnExpertComptableAction
{
    public const MESSAGE_CABINET_SUR_PILIQI = 'Le cabinet de ce SIREN a désormais son espace sur PILIQI : il sera relié à votre société dès qu\'une personne déclarée y aura un compte actif. Réactivez-en une, ou retirez votre expert-comptable.';

    public const MESSAGE_PLUS_DECLARE = 'Votre expert-comptable n\'est plus déclaré : son cabinet est peut-être désormais lié à votre société.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly CreationDesComptes $creation,
        private readonly RechercheDesCabinets $recherche,
        private readonly DebitDesEmails $debit,
        private readonly EnvoiDesLiensDesExpertsComptables $envoi,
    ) {}

    /**
     * @param  array<string, mixed>  $personne  name, email, email_confirmation
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws LogicException
     */
    public function handle(User $auteur, LienCabinet $declaration, array $personne, #[SensitiveParameter] string $motDePasse, ?string $jeton = null): User
    {
        $client = Societe::query()->findOrFail($declaration->societe_id);
        $identite = $this->creation->valider(['name' => $personne['name'] ?? null]);
        $adresse = $this->creation->validerLAdresse($personne['email'] ?? null, $personne['email_confirmation'] ?? null);

        MotDePasseRedemande::exiger($auteur, $motDePasse);
        $auteurRelu = $this->exigerLeProprietaire($auteur, $client);
        $this->debit->verifier($auteurRelu);

        if (! $declaration->estDeclare()) {
            throw new LogicException(self::MESSAGE_PLUS_DECLARE);
        }

        if ($declaration->siren_cabinet !== null && $this->recherche->pour($declaration->siren_cabinet)->isNotEmpty()) {
            throw new LogicException(self::MESSAGE_CABINET_SUR_PILIQI);
        }

        $empreinte = CreationDesComptes::empreinte(['role_societe' => RoleSociete::ExpertComptable->value, 'name' => $identite['name'], 'email' => $adresse, 'lien' => (string) $declaration->uuid]);

        try {
            $resultat = $this->journalDesDroits->modifierLeLien($auteurRelu, $client, [$declaration], function (?User $auteurSousVerrou, Societe $clientVerrouille, array $relus) use ($identite, $adresse, $jeton, $empreinte): array {
                [$relue] = $relus;
                $auteurSousVerrou = $this->exigerLeProprietaire(ValeurRequise::de($auteurSousVerrou, 'l\'auteur de l\'ajout, relu sous verrou'), $clientVerrouille);
                $existant = $this->creation->compteDejaCree($auteurSousVerrou, $clientVerrouille, $jeton, $empreinte);

                if ($existant !== null) {
                    return ['existant' => $existant];
                }

                if (! $relue->estDeclare()) {
                    throw new LogicException(self::MESSAGE_PLUS_DECLARE);
                }

                $this->creation->verifierLaLimiteDuJour($auteurSousVerrou, $clientVerrouille);
                $compte = $this->creation->creer($auteurSousVerrou, $clientVerrouille, $identite, RoleSociete::ExpertComptable, null, $jeton, $empreinte, $adresse);
                $attachement = tap((new CompteExpertComptable)->forceFill(['lien_id' => $relue->getKey(), 'user_id' => $compte->getKey()]))->save();

                return ['compte' => $compte, 'jetonDuLien' => $attachement->nouveauLien()];
            }, fn (Societe $journal, array $relus, array $resultat): array => isset($resultat['existant']) || ! $journal->is($client) ? [] : [[ActionAuditee::ExpertComptableCree, [
                'cabinet' => IdentiteDansLeJournal::declaree($relus[0]),
            ], CleDuModele::de($client)]]);
        } catch (UniqueConstraintViolationException $doublon) {
            if (! CreationDesComptes::estUnJetonDejaServi($doublon)) {
                throw $doublon;
            }

            return app(TenantContext::class)->runAsSociete($client, fn (): User => $this->creation->compteDejaCree($auteurRelu->refresh(), $client, $jeton, $empreinte) ?? throw $doublon);
        }

        if (isset($resultat['existant'])) {
            return $resultat['existant'];
        }

        $this->debit->compter($auteurRelu);
        $this->envoi->envoyer(CleDuModele::de($resultat['compte']), $resultat['jetonDuLien'], CleDuModele::de($auteurRelu), accesRedonne: false);

        return $resultat['compte'];
    }

    /**
     * Le propriétaire du client, relu, qui gère son cabinet (voir
     * SocietePolicy::gererSonCabinet()) ; la déclaration en attente se
     * contrôle à part, pour le dire.
     *
     * @throws AuthorizationException
     */
    private function exigerLeProprietaire(User $auteur, Societe $client): User
    {
        try {
            return $this->journalDesDroits->exigerDansSaSociete($auteur, 'gererSonCabinet', $client);
        } catch (AuthorizationException) {
            throw new AuthorizationException(DeclarerSonExpertComptableAction::MESSAGE_PROPRIETAIRE_SEUL);
        }
    }
}
