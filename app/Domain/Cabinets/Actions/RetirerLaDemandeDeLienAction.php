<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\MotifDeFinDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le retrait d'une demande de lien par la partie qui l'a faite : le
 * propriétaire du cabinet pour une demande de rattachement, celui du
 * client pour une désignation, chacun contrôlé dans sa propre société
 * (voir LienCabinetPolicy::retirer()). Le lien de l'e-mail d'une demande
 * de rattachement ne vaut plus rien.
 */
class RetirerLaDemandeDeLienAction extends RepondreAUneDemandeDeLien
{
    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, LienCabinet $lien): void
    {
        self::exigerUneDemande($this->cloreLaDemande($auteur, $lien, EtatDuLien::Retire, ActionAuditee::DemandeDeLienRetiree, function (?User $auteurRelu, LienCabinet $relu): void {
            $this->journalDesDroits->exigerDansSaSociete($auteurRelu, 'retirer', $relu);
        }));
    }

    /**
     * Le retrait d'une demande au nom du système, dans la transaction de la
     * clôture d'une inscription ou de la saisie de son code par une autre
     * personne : une société non validée qui change de mains, ou close, ne
     * garde aucune demande ; le cabinet en est prévenu, par le seul SIREN.
     */
    public function parLeSysteme(LienCabinet $lien, MotifDeFinDuLien $motif): void
    {
        $retiree = $this->cloreLaDemande(null, $lien, EtatDuLien::Retire, ActionAuditee::DemandeDeLienRetiree, static fn (): null => null);

        if ($retiree !== null) {
            DB::afterCommit(function () use ($retiree, $motif): void {
                $client = Societe::query()->findOrFail($retiree->societe_id);
                self::prevenirLeCabinet($retiree, 'Demande de lien retirée', "La demande de lien avec le SIREN {$client->siren} a pris fin : {$motif->getLabel()}.");
            });
        }
    }

    /**
     * L'expiration d'une demande restée sans réponse 7 jours, au nom du
     * système (voir ExpirerInvitationsDesCabinets) : relue sous son verrou,
     * seulement si elle est encore en cours et périmée.
     */
    public function expirer(LienCabinet $lien): void
    {
        $this->cloreLaDemande(null, $lien, EtatDuLien::Expire, ActionAuditee::DemandeDeLienExpiree, static function (?User $auteur, LienCabinet $relu): void {
            if ($relu->expire_le === null || $relu->expire_le->isFuture()) {
                throw new LogicException(AccepterLaDemandeDeLienAction::MESSAGE_PLUS_VALABLE);
            }
        });
    }
}
