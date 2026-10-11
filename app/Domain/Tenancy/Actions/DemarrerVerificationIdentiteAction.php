<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\EvaluateurDeVerification;
use App\Domain\Tenancy\Services\RegistreDesVerifications;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Identite\Contracts\VerificationIdentite;
use App\Integrations\Identite\DemandeDIdentite;
use App\Integrations\Identite\IdentiteIndisponibleException;
use App\Integrations\Identite\SessionDIdentite;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Démarre la vérification d'identité à distance du propriétaire (B1, et
 * première étape de B2), après son consentement explicite au traitement
 * biométrique du prestataire (art. 9.2.a du RGPD) : une session à usage
 * unique, liée à la session PILIQI par un state que l'appelant garde dans
 * la session de la personne et que le retour vérifie (voir
 * RetourVerificationIdentiteController). L'écran de consentement du
 * prestataire affiche « Vous confirmez être {nom du compte} et prendre le
 * contrôle du compte PILIQI de {société}, créé avec l'adresse {e-mail
 * masqué} » : une personne à qui l'usurpateur relaierait la session sait
 * ce qu'elle accorde.
 *
 * La session démarrée s'inscrit au registre des vérifications, en
 * attente. Refusée sans le consentement, quand les voies automatiques sont
 * bloquées (essais répétés), ou quand le prestataire ne répond pas.
 */
class DemarrerVerificationIdentiteAction
{
    public const FOURNISSEUR = 'identite';

    public const MESSAGE_CONSENTEMENT = 'La vérification d\'identité demande votre consentement explicite au traitement de votre visage et de votre pièce d\'identité par le prestataire.';

    public const MESSAGE_VOIES_BLOQUEES = 'Trop d\'essais ont échoué : l\'éditeur doit examiner votre dossier avant toute nouvelle vérification.';

    public const MESSAGE_SANS_AUTORISATION = 'Seul le propriétaire prouve la qualité de représentant de la société, tant qu\'elle est en cours de vérification.';

    public function __construct(
        private readonly VerificationIdentite $identite,
        private readonly EvaluateurDeVerification $evaluateur,
        private readonly RegistreDesVerifications $registre,
    ) {}

    /**
     * @return array{session: SessionDIdentite, state: string}
     *
     * @throws VerificationRefuseeException
     */
    public function handle(User $proprietaire, Societe $societe, bool $consentement, string $urlDeRetour): array
    {
        return app(TenantContext::class)->runAsSociete($societe, function () use ($proprietaire, $societe, $consentement, $urlDeRetour): array {
            self::verifierLaVoie($proprietaire, $societe, $this->evaluateur);

            if (! $consentement) {
                throw new VerificationRefuseeException(self::MESSAGE_CONSENTEMENT);
            }

            $state = Str::random(40);

            try {
                $session = $this->identite->demarrer(new DemandeDIdentite(
                    texteDeConsentement: "Vous confirmez être {$proprietaire->name} et prendre le contrôle du compte ".config('app.name').' de '.$societe->getFilamentName().', créé avec l\'adresse '.self::masquer($proprietaire->email).'.',
                    urlDeRetour: $urlDeRetour,
                    state: $state,
                ));
            } catch (IdentiteIndisponibleException $indisponible) {
                throw new VerificationRefuseeException($indisponible->getMessage());
            }

            $this->registre->noter($societe, TypeVerification::Identite, ResultatVerification::EnAttente, ['consentement' => true], $proprietaire, $proprietaire, self::FOURNISSEUR, $session->reference);

            return ['session' => $session, 'state' => $state];
        });
    }

    /**
     * Le propriétaire peut-il emprunter une voie automatique de la
     * vérification (identité, accord d'un dirigeant, courrier, code) ?
     *
     * @throws VerificationRefuseeException
     */
    public static function verifierLaVoie(User $proprietaire, Societe $societe, EvaluateurDeVerification $evaluateur): void
    {
        if (Gate::forUser($proprietaire)->denies('verifierLaSociete', $societe)) {
            throw new VerificationRefuseeException(self::MESSAGE_SANS_AUTORISATION);
        }

        if ($evaluateur->evaluer($societe)->voiesAutomatiquesBloquees()) {
            throw new VerificationRefuseeException(self::MESSAGE_VOIES_BLOQUEES);
        }
    }

    /**
     * Une adresse e-mail telle que l'écran de consentement la montre :
     *
     * « j***@example.fr ».
     */
    public static function masquer(string $email): string
    {
        [$local, $domaine] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domaine;
    }
}
