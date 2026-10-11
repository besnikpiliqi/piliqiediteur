<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Mail\DemandeAccordDirigeantMail;
use App\Domain\Tenancy\Models\DemandeAccordDirigeant;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\EvaluateurDeVerification;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\RapprochementDesDirigeants;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Rne\RneIndisponibleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Un propriétaire qui n'est pas dirigeant demande à l'un des dirigeants
 * de l'entreprise, tel que le registre national des entreprises le donne
 * (voir RapprochementDesDirigeants::candidats()), d'approuver son mandat
 * (B2) : « J'autorise {nom} à gérer le compte PILIQI de {société} ». Son
 * identité doit être vérifiée d'abord : le mandat ne vaut qu'avec elle.
 *
 * Le dirigeant reçoit, à l'adresse que le propriétaire donne, un lien
 * construit depuis APP_URL, valable sept jours, dont seule l'empreinte se
 * garde, avec l'information
 * que lui doit l'article 14 du RGPD (voir DemandeAccordDirigeantMail) ;
 * il vérifie son identité, que PILIQI rapproche du dirigeant visé, avant
 * d'approuver (voir ApprouverMandatAction). Une seule demande attend à la
 * fois.
 */
class DemanderAccordDUnDirigeantAction
{
    public const MESSAGE_IDENTITE_D_ABORD = 'Vérifiez d\'abord votre propre identité : le mandat d\'un dirigeant ne vaut qu\'avec elle.';

    public const MESSAGE_DEMANDE_EN_ATTENTE = 'Une demande d\'accord attend déjà la réponse d\'un dirigeant.';

    public const MESSAGE_DIRIGEANT_INCONNU = 'Choisissez l\'un des dirigeants que le registre national des entreprises donne de votre entreprise.';

    public const MESSAGE_RNE_INDISPONIBLE = 'Le registre national des entreprises ne répond pas pour le moment : réessayez plus tard.';

    public function __construct(
        private readonly EvaluateurDeVerification $evaluateur,
        private readonly RapprochementDesDirigeants $rapprochement,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @param  int  $rang  le rang du dirigeant parmi les candidats du registre
     *
     * @throws VerificationRefuseeException
     * @throws ValidationException quand l'adresse du dirigeant n'est pas une adresse e-mail
     */
    public function handle(User $proprietaire, Societe $societe, int $rang, string $emailDuDirigeant): DemandeAccordDirigeant
    {
        $email = Validator::make(['email' => $emailDuDirigeant], ['email' => ['required', 'string', 'email', 'max:255']], attributes: ['email' => 'adresse e-mail du dirigeant'])->validate()['email'];

        return app(TenantContext::class)->runAsSociete($societe, function () use ($proprietaire, $societe, $rang, $email): DemandeAccordDirigeant {
            DemarrerVerificationIdentiteAction::verifierLaVoie($proprietaire, $societe, $this->evaluateur);

            if (! $this->evaluateur->evaluer($societe)->identiteVerifiee) {
                throw new VerificationRefuseeException(self::MESSAGE_IDENTITE_D_ABORD);
            }

            try {
                $candidat = $this->rapprochement->candidats((string) $societe->siren)[$rang] ?? throw new VerificationRefuseeException(self::MESSAGE_DIRIGEANT_INCONNU);
            } catch (RneIndisponibleException) {
                throw new VerificationRefuseeException(self::MESSAGE_RNE_INDISPONIBLE);
            }

            $jeton = Str::random(64);

            $demande = DB::transaction(function () use ($proprietaire, $candidat, $email, $jeton): DemandeAccordDirigeant {
                if (DemandeAccordDirigeant::query()->whereNull('approuvee_le')->whereNull('refusee_le')->where('expire_le', '>', now())->lockForUpdate()->exists()) {
                    throw new VerificationRefuseeException(self::MESSAGE_DEMANDE_EN_ATTENTE);
                }

                $demande = tap((new DemandeAccordDirigeant)->forceFill([
                    'demande_par' => $proprietaire->getKey(),
                    'email_dirigeant' => $email,
                    'representant_rne' => ['representant' => $candidat['representant']->enTableau(), 'chaine' => $candidat['chaine']],
                    'jeton_hache' => DemandeAccordDirigeant::empreinte($jeton),
                    'expire_le' => now()->addDays(DemandeAccordDirigeant::DUREE_DE_VALIDITE_EN_JOURS),
                ]))->save();

                $this->journalAudit->inscrire(ActionAuditee::AccordDirigeantDemande, $proprietaire, null, null, [
                    'dirigeant' => $candidat['representant']->libelle(),
                    'role' => $candidat['representant']->role,
                ], $demande);

                return $demande;
            });

            Mail::to($email)->send(new DemandeAccordDirigeantMail(
                nomDuDirigeant: trim(($candidat['representant']->prenoms[0] ?? '').' '.$candidat['representant']->nom),
                nomDuDemandeur: $proprietaire->name,
                societe: $societe->getFilamentName(),
                siren: (string) $societe->siren,
                lien: rtrim((string) config('app.url'), '/').route('accord-dirigeant.afficher', ['jeton' => $jeton], absolute: false),
                expireLe: $demande->expire_le->setTimezone(config('app.timezone'))->format('d/m/Y'),
            ));

            return $demande;
        });
    }
}
