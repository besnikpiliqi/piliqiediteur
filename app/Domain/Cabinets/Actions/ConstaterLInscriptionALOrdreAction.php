<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\Cabinets\Enums\VoieDeVerificationOrdre;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\Cabinet\Pages\VerificationDuCabinet;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La procédure de l'éditeur qui constate l'inscription d'un cabinet
 * d'expertise comptable au tableau de l'Ordre (editeur:cabinet:verifier-ordre),
 * tant qu'aucun accès automatique à l'annuaire de l'Ordre n'existe : le n°
 * d'inscription et le conseil régional constatés, la voie « éditeur », la
 * demande en attente et un refus antérieur effacés, une radiation
 * constatée avant levée (le cabinet est de nouveau inscrit au tableau) ;
 * avec $activiteControlee, le code d'activité d'un cabinet classé dans une
 * autre nomenclature que la NAF rév. 2, ou sans code au répertoire Sirene,
 * est aussi contrôlé. Chaque constat s'inscrit au journal d'audit du
 * cabinet, origine « éditeur », sous le verrou de sa ligne de
 * vérification ; son propriétaire est prévenu. Une société qui n'est pas
 * un cabinet, ou dont l'inscription est close, est refusée sans rien
 * écrire.
 */
class ConstaterLInscriptionALOrdreAction
{
    public const MESSAGE_PAS_UN_CABINET = 'Cette société n\'est pas un cabinet d\'expertise comptable.';

    public const MESSAGE_INSCRIPTION_CLOSE = 'L\'inscription de ce cabinet est close.';

    public function __construct(
        private readonly JournalAudit $journalAudit,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @throws LogicException
     */
    public function handle(Societe $cabinet, InterventionDeLEditeur $editeur, string $numero, string $conseil, bool $activiteControlee): void
    {
        self::exigerUnCabinetOuvert($cabinet);
        $numero = trim($numero);
        $conseil = trim($conseil);

        if ($numero === '' || mb_strlen($numero) > 30 || $conseil === '' || mb_strlen($conseil) > 100) {
            throw new LogicException('Donnez le n° d\'inscription au tableau (30 caractères au plus) et le conseil régional de l\'Ordre (100 caractères au plus).');
        }

        $this->operateur->pendantLeSysteme(fn () => app(TenantContext::class)->runAsSociete($cabinet, fn () => DB::transaction(function () use ($cabinet, $editeur, $numero, $conseil, $activiteControlee): void {
            $verification = $cabinet->cabinet()->lockForUpdate()->firstOrFail();
            $activiteAControler = $activiteControlee && ! $verification->aSonActiviteControlee();

            $verification->forceFill([
                'numero_ordre' => $numero,
                'conseil_regional_ordre' => $conseil,
                'ordre_verifie_le' => now(),
                'ordre_voie' => VoieDeVerificationOrdre::Editeur,
                'ordre_demande_le' => null,
                'ordre_refuse_le' => null,
                'ordre_motif_refus' => null,
                'ordre_retire_le' => null,
                ...($activiteAControler ? ['activite_controlee_le' => now()] : []),
            ])->save();

            $this->journalAudit->inscrire(ActionAuditee::OrdreVerifie, null, null, null, [
                'voie' => VoieDeVerificationOrdre::Editeur->value,
                'numero_ordre' => $numero,
                'conseil_regional' => $conseil,
            ], $cabinet, $editeur);

            if ($activiteAControler) {
                $this->journalAudit->inscrire(ActionAuditee::ActiviteDuCabinetControlee, null, null, null, [
                    'code_activite' => $verification->code_activite,
                    'nomenclature' => $verification->nomenclature_activite,
                    'resultat' => 'controle_par_l_editeur',
                ], $cabinet, $editeur);
            }
        })));

        $cabinet->proprietaire()->first()?->notify(new AvisDuCabinet(
            $cabinet,
            'Inscription à l\'Ordre vérifiée',
            'L\'éditeur a vérifié l\'inscription de votre cabinet au tableau de l\'Ordre.',
            VerificationDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet),
            'Voir la vérification',
            AvisDuCabinet::SUCCES,
        ));
    }

    /**
     * Une procédure de l'Ordre ne porte que sur un cabinet d'expertise
     * comptable dont l'inscription n'est pas close.
     *
     * @throws LogicException
     */
    public static function exigerUnCabinetOuvert(Societe $societe): void
    {
        if (! $societe->estUnCabinet()) {
            throw new LogicException(self::MESSAGE_PAS_UN_CABINET);
        }

        if ($societe->estClose()) {
            throw new LogicException(self::MESSAGE_INSCRIPTION_CLOSE);
        }
    }
}
