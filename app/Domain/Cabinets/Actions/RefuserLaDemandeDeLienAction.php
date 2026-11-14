<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le refus d'une demande de lien par l'autre partie : une demande de
 * rattachement par le propriétaire du client, une désignation par le
 * propriétaire du cabinet, chacun contrôlé dans sa propre société (voir
 * LienCabinetPolicy::refuser()). L'auteur de la demande en est prévenu ;
 * au cabinet, l'avis d'une demande qu'il avait envoyée ne cite que le
 * SIREN. Après un refus, la même partie ne renouvelle sa demande qu'après
 * 7 jours.
 */
class RefuserLaDemandeDeLienAction extends RepondreAUneDemandeDeLien
{
    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, LienCabinet $lien): void
    {
        $refusee = self::exigerUneDemande($this->cloreLaDemande($auteur, $lien, EtatDuLien::Refuse, ActionAuditee::LienRefuse, function (?User $auteurRelu, LienCabinet $relu): void {
            $this->journalDesDroits->exigerDansSaSociete(ValeurRequise::de($auteurRelu, 'l\'auteur du geste, relu sous verrou'), 'refuser', $relu);
        }));

        DB::afterCommit(function () use ($refusee): void {
            $client = Societe::query()->findOrFail($refusee->societe_id);
            $cabinet = Societe::query()->findOrFail($refusee->cabinet_id);

            $refusee->origine === OrigineDuLien::DemandeDuCabinet
                ? self::prevenirLeCabinet($refusee, 'Demande de rattachement refusée', "Votre demande de rattachement pour le SIREN {$client->siren} a été refusée.")
                : self::prevenirLeClient($refusee, 'Demande de lien refusée', "Le cabinet {$cabinet->getFilamentName()} a refusé votre demande de lien.");
        });
    }
}
