<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Authorization\OperateurCourant;
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
 * La procédure de l'éditeur qui refuse l'inscription d'un cabinet
 * d'expertise comptable au tableau de l'Ordre (editeur:cabinet:refuser-ordre)
 * : la demande en attente s'efface, le refus se note avec son motif, que
 * la page de la vérification du cabinet montre, et s'inscrit au journal
 * d'audit du cabinet, origine « éditeur » ; son propriétaire est prévenu
 * avec le motif et peut demander une nouvelle vérification. Une
 * inscription déjà vérifiée ne se refuse pas (sa radiation se constate
 * par une autre procédure) ; une société qui n'est pas un cabinet, ou
 * dont l'inscription est close, est refusée sans rien écrire.
 */
class RefuserLInscriptionALOrdreAction
{
    public const MESSAGE_DEJA_VERIFIEE = 'L\'inscription de ce cabinet à l\'Ordre est déjà vérifiée : elle ne se refuse plus.';

    public function __construct(
        private readonly JournalAudit $journalAudit,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @throws LogicException
     */
    public function handle(Societe $cabinet, InterventionDeLEditeur $editeur, string $motif): void
    {
        ConstaterLInscriptionALOrdreAction::exigerUnCabinetOuvert($cabinet);
        $motif = trim($motif);

        if ($motif === '' || mb_strlen($motif) > 255) {
            throw new LogicException('Donnez le motif du refus (255 caractères au plus) : le propriétaire du cabinet le lira.');
        }

        $this->operateur->pendantLeSysteme(fn () => app(TenantContext::class)->runAsSociete($cabinet, fn () => DB::transaction(function () use ($cabinet, $editeur, $motif): void {
            $verification = $cabinet->cabinet()->lockForUpdate()->firstOrFail();

            if ($verification->ordre_verifie_le !== null) {
                throw new LogicException(self::MESSAGE_DEJA_VERIFIEE);
            }

            $verification->forceFill(['ordre_demande_le' => null, 'ordre_refuse_le' => now(), 'ordre_motif_refus' => $motif])->save();
            $this->journalAudit->inscrire(ActionAuditee::OrdreRefuse, null, null, null, ['motif' => $motif], $cabinet, $editeur);
        })));

        $cabinet->proprietaire()->first()?->notify(new AvisDuCabinet(
            $cabinet,
            'Inscription à l\'Ordre refusée',
            "L'éditeur n'a pas pu vérifier l'inscription de votre cabinet au tableau de l'Ordre : {$motif}",
            VerificationDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet),
            'Voir la vérification',
            AvisDuCabinet::REFUS,
            parCourriel: true,
        ));
    }
}
