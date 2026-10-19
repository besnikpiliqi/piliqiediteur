<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Mail\TransmissionDeLaProprieteMail;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TransmissionPropriete;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Domain\Tenancy\Services\FournisseurDesComptes;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;

/**
 * Le propriétaire, et lui seul, demande la transmission de la propriété de
 * sa société à un administrateur, depuis sa fiche dans l'espace de la
 * société, en redonnant son mot de passe (voir MotDePasseRedemande). Rien
 * ne change encore : un lien, valable 24 heures, part à l'adresse que
 * l'administrateur a renseignée lui-même dans son profil, et la propriété
 * ne passe que quand il le suit, connecté à son compte (voir
 * AccepterLaTransmissionDeLaProprieteAction) ; jusque-là le propriétaire
 * peut annuler (voir AnnulerLaTransmissionDeLaProprieteAction).
 *
 * Les conditions, relues sous le verrou des lignes du propriétaire et de
 * l'administrateur, chacune avec son message à l'écran du propriétaire
 * (voir exigerLesConditions()) : l'administrateur est actif ; sa qualité de
 * représentant est prouvée et son accès n'a pas été redonné depuis (sinon
 * qui le lui a redonné pourrait accepter à sa place) ; son mot de passe
 * n'est plus son identifiant, que le propriétaire a connu à sa création ;
 * il a renseigné lui-même son adresse, jamais saisie par le propriétaire ;
 * cette adresse n'est pas celle du propriétaire, dont « Mot de passe
 * oublié » réinitialiserait ensuite le compte du nouveau propriétaire.
 *
 * Une demande encore valable pour le même administrateur et la même
 * adresse est rendue telle quelle, sans nouveau lien ni nouvelle entrée
 * d'audit : la même demande répétée après une réponse perdue ne fait rien
 * de plus. « Renvoyer le lien » est un geste distinct (voir
 * renvoyerLeLien()) : un nouveau jeton, l'ancien lien ne sert plus. Une
 * demande pour un autre administrateur, ou pour une autre adresse, annule
 * celle qui attendait (entrée TransmissionAnnulee). Seule l'empreinte du
 * jeton est gardée (voir TransmissionPropriete).
 *
 * Si l'adresse est déjà l'adresse de connexion du propriétaire d'une autre
 * société, le courriel le dit à cette adresse, sans lien ; l'écran du
 * propriétaire dit comme toujours qu'un lien a été envoyé : rien ne révèle
 * à un tiers qu'une adresse est celle d'un propriétaire. Chaque courriel
 * compte au débit des e-mails du propriétaire (voir DebitDesEmails).
 * Chaque demande s'inscrit au journal d'audit (TransmissionDemandee) et
 * l'administrateur en est prévenu dans sa cloche.
 */
class DemanderLaTransmissionDeLaProprieteAction
{
    use AgitDansLaSociete;

    public const MESSAGE_PROPRIETAIRE_SEUL = 'Seul le propriétaire transmet la propriété de sa société.';

    public const MESSAGE_ADMINISTRATEUR = 'La propriété ne se transmet qu\'à un administrateur actif de la société.';

    public const MESSAGE_QUALITE = 'Cette personne doit d\'abord prouver sa qualité de représentant de la société.';

    public const MESSAGE_MOT_DE_PASSE = 'Cet administrateur doit d\'abord choisir son propre mot de passe dans son profil.';

    public const MESSAGE_ADRESSE = 'Cet administrateur doit d\'abord renseigner lui-même son adresse e-mail dans son profil.';

    public const MESSAGE_ADRESSE_DU_PROPRIETAIRE = 'L\'adresse de cet administrateur est la vôtre : il doit renseigner la sienne dans son profil.';

    public const MESSAGE_AUCUNE_DEMANDE = 'Aucune transmission de la propriété n\'attend pour cet administrateur.';

    public const MESSAGE_ADRESSE_CHANGEE = 'L\'adresse de cet administrateur a changé depuis la demande : demandez de nouveau la transmission.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly JournalAudit $journalAudit,
        private readonly DebitDesEmails $debit,
    ) {}

    /**
     * @return TransmissionPropriete la demande créée, ou celle, encore valable, que la même demande avait déjà créée
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, User $administrateur, #[SensitiveParameter] string $motDePasse): TransmissionPropriete
    {
        $societe = Societe::query()->findOrFail($auteur->societe_id);

        return $this->dansLaSociete($societe, self::MESSAGE_PROPRIETAIRE_SEUL, function () use ($auteur, $administrateur, $societe, $motDePasse): TransmissionPropriete {
            self::exigerLeProprietaire($auteur, $societe);
            MotDePasseRedemande::exiger($auteur, $motDePasse);
            $this->debit->verifier($auteur);

            [$demande, $jeton] = $this->journalDesDroits->sousVerrouDesComptes($auteur, [$administrateur], function (User $auteurRelu, array $relus) use ($societe): array {
                [$administrateurRelu] = $relus;
                self::exigerLeProprietaire($auteurRelu, $societe);
                self::exigerLesConditions($auteurRelu, $administrateurRelu, $societe);

                $enAttente = $this->demandesEnAttente($societe);
                $memeDemande = $enAttente->first(fn (TransmissionPropriete $demande): bool => (int) $demande->destinataire_id === (int) $administrateurRelu->getKey() && $demande->adresse === $administrateurRelu->email);

                if ($memeDemande !== null) {
                    return [$memeDemande, null];
                }

                foreach ($enAttente as $ancienne) {
                    $this->annuler($auteurRelu, $ancienne);
                }

                $jeton = Str::random(64);
                $demande = new TransmissionPropriete;
                $demande->forceFill([
                    'societe_id' => $societe->getKey(),
                    'demandee_par' => $auteurRelu->getKey(),
                    'destinataire_id' => $administrateurRelu->getKey(),
                    'adresse' => $administrateurRelu->email,
                    'jeton_hache' => TransmissionPropriete::empreinte($jeton),
                    'expire_le' => now()->addHours(TransmissionPropriete::DUREE_DE_VALIDITE_EN_HEURES),
                ])->save();
                $this->journalAudit->inscrire(ActionAuditee::TransmissionDemandee, $auteurRelu, null, null, [
                    'destinataire' => ['id' => $administrateurRelu->getKey(), 'nom' => $administrateurRelu->name],
                    'lien_renvoye' => false,
                ], $demande);

                return [$demande, $jeton];
            });

            if ($jeton !== null) {
                $this->envoyer($auteur, $societe, $demande, $jeton);
            }

            return $demande;
        });
    }

    /**
     * « Renvoyer le lien » de la demande qui attend pour cet administrateur
     * : un nouveau jeton remplace l'ancien, qui ne sert plus, et la demande
     * vaut de nouveau 24 heures ; les conditions sont relues, et l'adresse
     * de l'administrateur doit être celle de la demande.
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function renvoyerLeLien(User $auteur, User $administrateur): void
    {
        $societe = Societe::query()->findOrFail($auteur->societe_id);

        $this->dansLaSociete($societe, self::MESSAGE_PROPRIETAIRE_SEUL, function () use ($auteur, $administrateur, $societe): void {
            self::exigerLeProprietaire($auteur, $societe);
            $this->debit->verifier($auteur);

            [$demande, $jeton] = $this->journalDesDroits->sousVerrouDesComptes($auteur, [$administrateur], function (User $auteurRelu, array $relus) use ($societe): array {
                [$administrateurRelu] = $relus;
                self::exigerLeProprietaire($auteurRelu, $societe);
                self::exigerLesConditions($auteurRelu, $administrateurRelu, $societe);

                $demande = $this->demandesEnAttente($societe)->first(fn (TransmissionPropriete $demande): bool => (int) $demande->destinataire_id === (int) $administrateurRelu->getKey())
                    ?? throw new LogicException(self::MESSAGE_AUCUNE_DEMANDE);

                if ($demande->adresse !== $administrateurRelu->email) {
                    throw new LogicException(self::MESSAGE_ADRESSE_CHANGEE);
                }

                $jeton = Str::random(64);
                $demande->forceFill([
                    'jeton_hache' => TransmissionPropriete::empreinte($jeton),
                    'expire_le' => now()->addHours(TransmissionPropriete::DUREE_DE_VALIDITE_EN_HEURES),
                ])->save();
                $this->journalAudit->inscrire(ActionAuditee::TransmissionDemandee, $auteurRelu, null, null, [
                    'destinataire' => ['id' => $administrateurRelu->getKey(), 'nom' => $administrateurRelu->name],
                    'lien_renvoye' => true,
                ], $demande);

                return [$demande, $jeton];
            });

            $this->envoyer($auteur, $societe, $demande, $jeton);
        });
    }

    /**
     * Les conditions de la transmission, relues sous verrou à la demande, au
     * renvoi du lien et à l'acceptation (voir
     * AccepterLaTransmissionDeLaProprieteAction), dans cet ordre.
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public static function exigerLesConditions(User $proprietaire, User $administrateur, Societe $societe): void
    {
        if ($administrateur->is($proprietaire) || ! $administrateur->estAdministrateur() || ! $administrateur->estActif() || (int) $administrateur->societe_id !== $societe->getKey()) {
            throw new AuthorizationException(self::MESSAGE_ADMINISTRATEUR);
        }

        if (! $administrateur->aSaQualiteVerifiee() || ($administrateur->acces_redonne_le !== null && ! $administrateur->acces_redonne_le->lt($administrateur->qualite_verifiee_le))) {
            throw new LogicException(self::MESSAGE_QUALITE);
        }

        if ($administrateur->mot_de_passe_est_l_identifiant) {
            throw new LogicException(self::MESSAGE_MOT_DE_PASSE);
        }

        if (blank($administrateur->email)) {
            throw new LogicException(self::MESSAGE_ADRESSE);
        }

        if ($administrateur->email === $proprietaire->email) {
            throw new LogicException(self::MESSAGE_ADRESSE_DU_PROPRIETAIRE);
        }
    }

    /**
     * @throws AuthorizationException
     */
    public static function exigerLeProprietaire(User $auteur, Societe $societe): void
    {
        if (! app(PorteeDesDroits::class)->gereSonEquipe($auteur) || ! $auteur->estProprietaire() || (int) $auteur->societe_id !== $societe->getKey()) {
            throw new AuthorizationException(self::MESSAGE_PROPRIETAIRE_SEUL);
        }
    }

    /**
     * Les demandes encore valables de la société, sous le verrou de leurs
     * lignes.
     *
     * @return Collection<int, TransmissionPropriete>
     */
    private function demandesEnAttente(Societe $societe): Collection
    {
        return TransmissionPropriete::query()
            ->where('societe_id', $societe->getKey())
            ->whereNull('annulee_le')
            ->whereNull('acceptee_le')
            ->where('expire_le', '>', now())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function annuler(User $auteur, TransmissionPropriete $demande): void
    {
        $demande->forceFill(['annulee_le' => now()])->save();
        $destinataire = User::query()->whereKey($demande->destinataire_id)->firstOrFail();
        $this->journalAudit->inscrire(ActionAuditee::TransmissionAnnulee, $auteur, null, null, [
            'destinataire' => ['id' => $destinataire->getKey(), 'nom' => $destinataire->name],
        ], $demande);
    }

    /**
     * Le courriel du lien à l'adresse de la demande, sans le lien quand
     * cette adresse est déjà l'adresse de connexion du propriétaire d'une
     * autre société ; l'administrateur en est prévenu dans sa cloche.
     */
    private function envoyer(User $auteur, Societe $societe, TransmissionPropriete $demande, string $jeton): void
    {
        $proprietaireDeLAdresse = FournisseurDesComptes::proprietaireDeLAdresse($demande->adresse);
        $lien = $proprietaireDeLAdresse === null
            ? rtrim((string) config('app.url'), '/').route('filament.societe.transmission', ['jeton' => $jeton], absolute: false)
            : null;

        Mail::to($demande->adresse)->send(new TransmissionDeLaProprieteMail(
            $lien,
            $societe->getFilamentName(),
            $auteur->name,
            $demande->expire_le->setTimezone(config('app.timezone'))->format('d/m/Y à H:i'),
        ));
        $this->debit->compter($auteur);

        User::query()->whereKey($demande->destinataire_id)->firstOrFail()->notify(new AvisDeLEquipe(
            $societe,
            'Transmission de la propriété',
            "{$auteur->name} souhaite vous transmettre la propriété de {$societe->getFilamentName()} : un e-mail vient d'être envoyé à votre adresse {$demande->adresse}. Rien ne change tant que vous n'avez pas suivi son lien, valable 24 heures.",
            nature: AvisDeLEquipe::ATTENTION,
        ));
    }
}
