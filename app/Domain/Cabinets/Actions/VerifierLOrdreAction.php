<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Cabinets\Enums\VoieDeVerificationOrdre;
use App\Domain\Cabinets\Jobs\PrevenirLesClientsDuCabinet;
use App\Domain\Cabinets\Models\Cabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\AlerteEditeur;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Filament\Cabinet\Pages\VerificationDuCabinet;
use App\Integrations\Ordre\AnnuaireDeLOrdreIndisponibleException;
use App\Integrations\Ordre\Contracts\AnnuaireDeLOrdre;
use App\Integrations\Ordre\InscriptionALOrdre;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Le propriétaire d'un cabinet d'expertise comptable fait vérifier son
 * inscription au tableau de l'Ordre des experts-comptables, avec son n°
 * d'inscription et son conseil régional (voir CabinetPolicy::verifierLOrdre()).
 * Cinq essais par heure et par cabinet au plus.
 *
 * Avec un annuaire automatique (voir AnnuaireDeLOrdre::estAutomatique()),
 * l'annuaire se lit hors de toute transaction : une inscription au même
 * SIREN, au tableau (ni radiée ni suspendue), vérifie l'inscription, avec
 * le n° et le conseil que donne l'annuaire ; sinon la vérification est
 * refusée, avec son motif, et le propriétaire le lit ; un annuaire qui ne
 * répond pas fait réessayer plus tard, et rien n'est écrit. Sans annuaire
 * automatique (le pilote « editeur »), la demande se note, avec le n° et
 * le conseil déclarés, et l'éditeur, prévenu, la vérifie dans sa procédure
 * (voir ConstaterLInscriptionALOrdreAction et
 * RefuserLInscriptionALOrdreAction).
 *
 * L'écriture se fait dans une transaction qui verrouille la ligne de
 * vérification du cabinet puis relit son auteur, au nom duquel elle
 * s'inscrit au journal d'audit ; le propriétaire est prévenu après, et,
 * quand le cabinet devient ainsi vérifié, le propriétaire de chacun de ses
 * clients liés (des liens gardés après la correction de son SIREN, voir
 * CorrigerSirenAction), dont l'accès revient, par un job en file d'attente
 * (voir PrevenirLesClientsDuCabinet).
 */
class VerifierLOrdreAction
{
    use AgitDansLaSociete;

    public const ESSAIS_PAR_HEURE = 5;

    public const MESSAGE_PROPRIETAIRE_SEUL = 'Seul le propriétaire du cabinet fait vérifier son inscription au tableau de l\'Ordre.';

    public const MESSAGE_INTROUVABLE = 'L\'annuaire de l\'Ordre ne trouve pas d\'inscription active à ce SIREN.';

    public const MESSAGE_TROP_D_ESSAIS = 'Trop d\'essais de vérification pour votre cabinet : réessayez dans une heure.';

    public function __construct(
        private readonly AnnuaireDeLOrdre $annuaire,
        private readonly LecteurDesDroits $lecteur,
        private readonly JournalAudit $journalAudit,
        private readonly AlerteEditeur $alerteEditeur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @param  array<string, mixed>  $donnees  numero_ordre et conseil_regional
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws VerificationRefuseeException
     */
    public function handle(User $auteur, Societe $cabinet, array $donnees): Cabinet
    {
        return $this->dansLaSociete($cabinet, self::MESSAGE_PROPRIETAIRE_SEUL, function () use ($auteur, $cabinet, $donnees): Cabinet {
            $verification = $cabinet->cabinet()->first() ?? throw new AuthorizationException(self::MESSAGE_PROPRIETAIRE_SEUL);
            $this->lecteur->exigerDansLaSociete($auteur, 'verifierLOrdre', $cabinet, $verification, new AuthorizationException(self::MESSAGE_PROPRIETAIRE_SEUL));

            $valides = Validator::make($donnees, [
                'numero_ordre' => ['required', 'string', 'max:30'],
                'conseil_regional' => ['required', 'string', 'max:100'],
            ], attributes: ['numero_ordre' => 'n° d\'inscription au tableau', 'conseil_regional' => 'conseil régional'])->validate();

            if (! RateLimiter::attempt("ordre:{$cabinet->getKey()}", self::ESSAIS_PAR_HEURE, static fn (): bool => true, 3600)) {
                throw new VerificationRefuseeException(self::MESSAGE_TROP_D_ESSAIS);
            }

            if (! $this->annuaire->estAutomatique()) {
                return $this->demanderALEditeur($auteur, $cabinet, trim($valides['numero_ordre']), trim($valides['conseil_regional']));
            }

            try {
                $inscription = $this->annuaire->inscription((string) $cabinet->siren);
            } catch (AnnuaireDeLOrdreIndisponibleException $indisponible) {
                throw new VerificationRefuseeException($indisponible->getMessage());
            }

            $trouvee = $inscription !== null && $inscription->inscrite && $inscription->siren === $cabinet->siren ? $inscription : null;
            $resultat = $this->ecrire($auteur, $cabinet, function (Cabinet $verrouillee, User $auteurRelu) use ($trouvee, $cabinet): Cabinet {
                return $trouvee === null ? $this->refuser($verrouillee, $auteurRelu, $cabinet) : $this->verifier($verrouillee, $auteurRelu, $cabinet, $trouvee);
            });

            if ($trouvee === null) {
                $this->prevenir($cabinet, 'Inscription à l\'Ordre non trouvée', self::MESSAGE_INTROUVABLE, AvisDuCabinet::REFUS);

                throw new VerificationRefuseeException(self::MESSAGE_INTROUVABLE);
            }

            $this->prevenir($cabinet, 'Inscription à l\'Ordre vérifiée', 'L\'annuaire de l\'Ordre confirme l\'inscription de votre cabinet au tableau.', AvisDuCabinet::SUCCES);

            if ($cabinet->fresh()->estUnCabinetVerifie()) {
                PrevenirLesClientsDuCabinet::dispatch((int) $cabinet->getKey(), accesRetabli: true);
            }

            return $resultat;
        });
    }

    /**
     * Sans annuaire automatique : la demande se note et l'éditeur, prévenu
     * après la transaction, la vérifie dans sa procédure.
     */
    private function demanderALEditeur(User $auteur, Societe $cabinet, string $numero, string $conseil): Cabinet
    {
        $verification = $this->ecrire($auteur, $cabinet, function (Cabinet $verrouillee, User $auteurRelu) use ($cabinet, $numero, $conseil): Cabinet {
            $verrouillee->forceFill([
                'numero_ordre' => $numero,
                'conseil_regional_ordre' => $conseil,
                'ordre_demande_le' => now(),
                'ordre_refuse_le' => null,
                'ordre_motif_refus' => null,
            ])->save();
            $this->journalAudit->inscrire(ActionAuditee::OrdreDemande, $auteurRelu, null, null, ['numero_ordre' => $numero, 'conseil_regional' => $conseil], $cabinet);

            return $verrouillee;
        });

        $this->alerteEditeur->alerter($cabinet, 'Inscription à l\'Ordre à vérifier', [
            "N° d'inscription au tableau déclaré : {$numero}.",
            "Conseil régional : {$conseil}.",
            'Vérifiez-la dans l\'annuaire de l\'Ordre, puis concluez par php artisan editeur:cabinet:verifier-ordre ou editeur:cabinet:refuser-ordre.',
        ]);
        $this->prevenir($cabinet, 'Vérification demandée', 'L\'éditeur vérifie votre inscription au tableau de l\'Ordre et vous prévient.', AvisDuCabinet::INFORMATION);

        return $verification;
    }

    private function verifier(Cabinet $verrouillee, User $auteurRelu, Societe $cabinet, InscriptionALOrdre $inscription): Cabinet
    {
        $verrouillee->forceFill([
            'numero_ordre' => $inscription->numero,
            'conseil_regional_ordre' => $inscription->conseilRegional,
            'ordre_verifie_le' => now(),
            'ordre_voie' => VoieDeVerificationOrdre::Annuaire,
            'ordre_demande_le' => null,
            'ordre_refuse_le' => null,
            'ordre_motif_refus' => null,
        ])->save();
        $this->journalAudit->inscrire(ActionAuditee::OrdreVerifie, $auteurRelu, null, null, [
            'voie' => VoieDeVerificationOrdre::Annuaire->value,
            'numero_ordre' => $inscription->numero,
            'conseil_regional' => $inscription->conseilRegional,
        ], $cabinet);

        return $verrouillee;
    }

    private function refuser(Cabinet $verrouillee, User $auteurRelu, Societe $cabinet): Cabinet
    {
        $verrouillee->forceFill(['ordre_refuse_le' => now(), 'ordre_motif_refus' => self::MESSAGE_INTROUVABLE])->save();
        $this->journalAudit->inscrire(ActionAuditee::OrdreRefuse, $auteurRelu, null, null, ['motif' => self::MESSAGE_INTROUVABLE], $cabinet);

        return $verrouillee;
    }

    /**
     * Écrit sous le verrou de la ligne de vérification du cabinet, au nom
     * de l'auteur relu, qui doit encore pouvoir demander la vérification.
     *
     * @param  callable(Cabinet, User): Cabinet  $ecriture
     */
    private function ecrire(User $auteur, Societe $cabinet, callable $ecriture): Cabinet
    {
        return DB::transaction(function () use ($auteur, $cabinet, $ecriture): Cabinet {
            $verrouillee = $cabinet->cabinet()->lockForUpdate()->firstOrFail();
            $auteurRelu = $this->lecteur->exigerDansLaSociete($auteur, 'verifierLOrdre', $cabinet, $verrouillee, new AuthorizationException(self::MESSAGE_PROPRIETAIRE_SEUL));

            return $this->operateur->pendant($auteurRelu, fn (): Cabinet => $ecriture($verrouillee, $auteurRelu));
        });
    }

    private function prevenir(Societe $cabinet, string $titre, string $texte, string $nature): void
    {
        $cabinet->proprietaire()->first()?->notify(new AvisDuCabinet(
            $cabinet,
            $titre,
            $texte,
            VerificationDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet),
            'Voir la vérification',
            $nature,
        ));
    }
}
