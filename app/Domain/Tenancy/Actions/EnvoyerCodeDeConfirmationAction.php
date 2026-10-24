<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\AnnulationDesCodes;
use App\Domain\Tenancy\Services\CodesDeConfirmation;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\CourrierDeCode;
use App\Domain\Tenancy\Services\CourriersDeVerification;
use App\Domain\Tenancy\Services\DestinataireOfficiel;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use App\Integrations\EnvoiRecommande\DestinataireDuCourrier;
use App\Integrations\EnvoiRecommande\EnvoiRecommandeIndisponibleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Envoie un code de confirmation par courrier recommandé avec avis de
 * réception, par l'API de La Poste (voir ServiceEnvoiRecommande), au nom
 * du représentant légal de l'entreprise inscrit au registre national des
 * entreprises, à l'adresse officielle de son siège (voir
 * DestinataireOfficiel), remis contre signature au destinataire en
 * personne : jamais à une adresse saisie ou tirée d'une pièce. Le courrier
 * mis en page par le logiciel (voir CourrierDeCode) porte le code, valable
 * trente jours et à usage unique, et le QR code de la page où le saisir.
 *
 * Chaque courrier est payé par l'éditeur ; il part :
 * - à la confirmation de l'inscription, une seule fois par SIREN tant
 *   qu'une inscription attend (le SIREN n'appartient qu'à elle), pour une
 *   entreprise active au répertoire Sirene (voir premier(), appelé par
 *   CreerSocieteAction, qui en fait le contrôle) ;
 * - à la demande du propriétaire, un seul nouveau courrier, au plus tôt
 *   quinze jours après le premier (voir nouvelEnvoi()) ;
 * - à la demande de l'éditeur, pour un motif de ses procédures ou après le
 *   traitement à la main d'un courrier non reçu (voir parLEditeur()).
 * Le nouveau courrier de la vérification, que le propriétaire ou l'éditeur
 * le demande, ne part que pour une entreprise encore active au répertoire
 * Sirene, contrôlée le jour même et inscrite au registre des vérifications
 * (voir exigerUneEntrepriseActive()) : une entreprise cessée ou inconnue
 * est refusée avec le motif du répertoire, et un répertoire qui ne répond
 * pas fait réessayer plus tard. Le courrier d'une procédure de l'éditeur
 * part sans ce contrôle : il en décide.
 * Un nouveau courrier annule le code actif du même motif : le premier ne
 * vaut plus. Seule l'empreinte du code se garde (voir CodesDeConfirmation)
 * ; le code en clair ne va qu'au courrier. Un prestataire qui ne répond
 * pas n'envoie rien et n'enregistre rien.
 */
class EnvoyerCodeDeConfirmationAction
{
    public const MESSAGE_SANS_AUTORISATION = 'Seul le propriétaire de la société demande un nouveau courrier.';

    public const MESSAGE_N_ATTEND_PLUS = 'Cette inscription n\'attend plus de code.';

    public const MESSAGE_PAS_ENCORE = 'Un nouveau courrier pourra être demandé à partir du %s, quinze jours après le premier.';

    public const MESSAGE_REPERTOIRE_INDISPONIBLE = 'Le répertoire Sirene de l\'INSEE ne répond pas pour le moment : le courrier n\'est pas parti, réessayez dans quelques minutes.';

    public const MESSAGE_PLUS_DE_NOUVEL_ENVOI = 'Un seul nouveau courrier peut être demandé : déposez des pièces pour que l\'éditeur traite votre demande, ou contactez-le.';

    public function __construct(
        private readonly DestinataireOfficiel $destinataireOfficiel,
        private readonly CodesDeConfirmation $codes,
        private readonly CourrierDeCode $courrier,
        private readonly CourriersDeVerification $courriers,
        private readonly ServiceEnvoiRecommande $envoi,
        private readonly JournalAudit $journalAudit,
        private readonly AnnulationDesCodes $annulationDesCodes,
        private readonly ControleSirene $controleSirene,
    ) {}

    /**
     * Le courrier de la confirmation de l'inscription, dans sa transaction
     * : la société vient d'être créée, sous elle, avec son propriétaire.
     *
     * @throws VerificationRefuseeException quand le prestataire ne répond pas
     */
    public function premier(Societe $societe, User $proprietaire, DestinataireDuCourrier $destinataire): CodeConfirmation
    {
        return $this->envoyer($societe, MotifCode::Verification, $destinataire, $proprietaire, null);
    }

    /**
     * Le nouveau courrier que demande le propriétaire, une seule fois, au
     * plus tôt quinze jours après le premier.
     *
     * @throws VerificationRefuseeException
     */
    public function nouvelEnvoi(User $proprietaire, Societe $societe): CodeConfirmation
    {
        return app(TenantContext::class)->runAsSociete($societe, function () use ($proprietaire, $societe): CodeConfirmation {
            $proprietaire = app(LecteurDesDroits::class)->exigerDansLaSociete($proprietaire, 'verifierLaSociete', $societe, $societe, new VerificationRefuseeException(self::MESSAGE_SANS_AUTORISATION));
            $this->exigerUnNouvelEnvoiPossible($societe);
            $this->exigerUneEntrepriseActive($societe, $proprietaire, null);
            $destinataire = $this->destinataireOfficiel->du((string) $societe->siren);

            return DB::transaction(function () use ($proprietaire, $societe, $destinataire): CodeConfirmation {
                $verrouillee = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();
                $this->exigerUnNouvelEnvoiPossible($verrouillee);

                return $this->envoyer($verrouillee, MotifCode::Verification, $destinataire, $proprietaire, null);
            });
        });
    }

    /**
     * Le courrier que l'éditeur envoie, sans limite : pour un motif de ses
     * procédures, ou pour la vérification d'une inscription qui attend son
     * code (après le traitement à la main d'un courrier non reçu).
     *
     * @throws VerificationRefuseeException
     */
    public function parLEditeur(Societe $societe, MotifCode $motif, InterventionDeLEditeur $editeur): CodeConfirmation
    {
        return app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $motif, $editeur): CodeConfirmation {
            if ($motif === MotifCode::Verification) {
                if (! $societe->attendSonCode()) {
                    throw new VerificationRefuseeException(self::MESSAGE_N_ATTEND_PLUS);
                }

                $this->exigerUneEntrepriseActive($societe, null, $editeur);
            }

            $destinataire = $this->destinataireOfficiel->du((string) $societe->siren);

            return DB::transaction(function () use ($societe, $motif, $editeur, $destinataire): CodeConfirmation {
                $verrouillee = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();

                if ($motif === MotifCode::Verification && ! $verrouillee->attendSonCode()) {
                    throw new VerificationRefuseeException(self::MESSAGE_N_ATTEND_PLUS);
                }

                return $this->envoyer($verrouillee, $motif, $destinataire, null, $editeur);
            });
        });
    }

    /**
     * Le code, sa ligne, le courrier et son inscription au journal d'audit,
     * dans une transaction (celle de l'appelant s'il en a une) : si le
     * prestataire ne répond pas, rien ne s'enregistre.
     *
     * @throws VerificationRefuseeException
     */
    private function envoyer(Societe $societe, MotifCode $motif, DestinataireDuCourrier $destinataire, ?User $proprietaire, ?InterventionDeLEditeur $editeur): CodeConfirmation
    {
        $code = $this->codes->generer();
        $expireLe = now()->addDays(CodeConfirmation::DUREE_DE_VALIDITE_EN_JOURS);

        try {
            return DB::transaction(function () use ($societe, $motif, $destinataire, $proprietaire, $editeur, $code, $expireLe): CodeConfirmation {
                $this->annulerLesCodesQuiNeServirontPlus((string) $societe->siren, $motif);
                CodeConfirmation::query()->where('motif', $motif->value)->whereNull('utilise_le')->whereNull('annule_le')->orderBy('id')->lockForUpdate()->get()
                    ->each(fn (CodeConfirmation $remplace) => $this->annulationDesCodes->annuler($remplace, AnnulationDesCodes::NOUVEAU_COURRIER, $proprietaire, $editeur));

                $confirmation = tap((new CodeConfirmation)->forceFill([
                    'siren' => $societe->siren,
                    'motif' => $motif,
                    'destinataire' => $destinataire->enTableau(),
                    'code_hache' => $this->codes->hacher($code),
                    'expire_le' => $expireLe,
                    'envoye_le' => now(),
                    'demande_par' => $proprietaire?->getKey(),
                ]))->save();

                $reference = $this->envoi->envoyerCourrier($destinataire, $this->courrier->pdf($destinataire, (string) $societe->siren, $motif, $code, $expireLe), remiseEnPersonne: true);
                $confirmation->forceFill(['reference_envoi' => $reference])->save();

                $this->journalAudit->inscrire(ActionAuditee::CodeEnvoye, $proprietaire, null, null, [
                    'motif' => $motif->value,
                    'source_adresse' => $destinataire->adresse->source,
                    'reference_envoi' => $reference,
                ], $confirmation, $editeur);

                return $confirmation;
            });
        } catch (EnvoiRecommandeIndisponibleException $indisponible) {
            throw new VerificationRefuseeException($indisponible->getMessage());
        }
    }

    /**
     * @throws VerificationRefuseeException
     */
    private function exigerUnNouvelEnvoiPossible(Societe $societe): void
    {
        if (! $societe->attendSonCode()) {
            throw new VerificationRefuseeException(self::MESSAGE_N_ATTEND_PLUS);
        }

        $possibleLe = $this->courriers->nouvelEnvoiPossibleLe($societe);

        if ($possibleLe === null) {
            throw new VerificationRefuseeException(self::MESSAGE_PLUS_DE_NOUVEL_ENVOI);
        }

        if ($possibleLe->isFuture()) {
            throw new VerificationRefuseeException(sprintf(self::MESSAGE_PAS_ENCORE, $possibleLe->setTimezone(config('app.timezone'))->format('d/m/Y à H:i')));
        }
    }

    /**
     * Contrôle le jour même, au répertoire Sirene, que l'entreprise est
     * encore active, et inscrit la consultation au registre des
     * vérifications, au nom de qui demande le courrier ; sinon le courrier
     * ne part pas.
     *
     * @throws VerificationRefuseeException
     */
    private function exigerUneEntrepriseActive(Societe $societe, ?User $proprietaire, ?InterventionDeLEditeur $editeur): void
    {
        $consultation = $this->controleSirene->unite((string) $societe->siren);

        if ($consultation['resultat'] === ResultatVerification::Indisponible) {
            throw new VerificationRefuseeException(self::MESSAGE_REPERTOIRE_INDISPONIBLE);
        }

        $this->controleSirene->noterUnite($societe, $consultation, $proprietaire, $editeur);

        if ($consultation['resultat'] !== ResultatVerification::Concluante) {
            throw new VerificationRefuseeException((string) $consultation['motif']);
        }
    }

    /**
     * Annule les codes de ce SIREN et de ce motif qui ne serviront plus,
     * sans les avoir servis : ceux qui ont expiré, et ceux d'une société de
     * ce SIREN close ou révoquée (une révocation ne passe pas par
     * l'annulation de ses codes) ; seul un code encore valable d'une
     * société qui garde ce SIREN reste actif, et l'index d'un seul code
     * actif par SIREN et par motif admet le nouveau. Écriture limitée à ce
     * SIREN, qui ne lit des sociétés qui l'ont eu que leur identifiant.
     */
    private function annulerLesCodesQuiNeServirontPlus(string $siren, MotifCode $motif): void
    {
        CodeConfirmation::withoutGlobalScope(SocieteScope::class)
            ->where('siren', $siren)
            ->where('motif', $motif->value)
            ->whereNull('utilise_le')
            ->whereNull('annule_le')
            ->where(fn ($codes) => $codes->where('expire_le', '<=', now())->orWhereIn('societe_id', Societe::query()
                ->select('id')
                ->where('siren', $siren)
                ->where(fn ($sansSiren) => $sansSiren->whereNotNull('close_le')->orWhere('statut', StatutSociete::Revoquee->value))))
            ->orderBy('id')
            ->get()
            ->each(fn (CodeConfirmation $perime) => $perime->forceFill(['annule_le' => now()])->save());
    }
}
