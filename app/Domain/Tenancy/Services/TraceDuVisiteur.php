<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Support\Reglage;
use Carbon\CarbonImmutable;
use Closure;

/**
 * L'adresse IP et le navigateur du visiteur d'une requête HTTP, que les
 * traces techniques du journal d'audit et du journal des événements
 * gardent à part de leur contenu signé (voir TraceAudit et
 * TraceEvenement), effacés après un an : seul endroit qui les lit dans la
 * requête. Une commande ou un job n'a qu'une requête de façade, sans
 * visiteur, et ne laisse aucune trace.
 *
 * Une personne ne laisse jamais son adresse IP ni son navigateur dans les
 * journaux d'une autre société que celle où elle agit (isolation des
 * sociétés, et aucune donnée personnelle inutile, voir CLAUDE.md) : une
 * écriture au journal d'une autre société se fait sous horsDeSaSociete(),
 * qui n'y note aucune trace. Ainsi, entre un cabinet d'expertise comptable
 * et un client, chacun ne garde que les traces de ses propres comptes et
 * de ce qu'un compte de l'autre fait lui-même dans ses magasins (voir
 * JournalDesDroits et les actions des liens), et la personne invitée par
 * un cabinet ne laisse aucune trace dans le journal du cabinet (voir
 * RefuserLInvitationAction et AccepterLInvitationAction).
 *
 * Lié en scoped() (voir TenancyServiceProvider), comme OperateurCourant :
 * chaque requête et chaque job repart de rien, et chaque écriture hors de
 * la société du visiteur rétablit l'état d'avant à sa fin, même après une
 * exception.
 */
final class TraceDuVisiteur
{
    private int $ecrituresHorsDeSaSociete = 0;

    /**
     * Exécute cette écriture au journal d'une autre société que celle où
     * le visiteur agit : aucune trace n'y est notée.
     *
     * @template TResultat
     *
     * @param  Closure(): TResultat  $ecriture
     * @return TResultat
     */
    public function horsDeSaSociete(Closure $ecriture): mixed
    {
        $this->ecrituresHorsDeSaSociete++;

        try {
            return $ecriture();
        } finally {
            $this->ecrituresHorsDeSaSociete--;
        }
    }

    /**
     * Les colonnes de la trace de l'écriture en cours : l'adresse IP, le
     * navigateur (255 caractères au plus) et la date d'effacement, après la
     * durée de config/conservation.php ; null quand rien ne se note (hors
     * d'une requête HTTP routée, ou hors de la société du visiteur).
     *
     * @return array{ip: string|null, agent_utilisateur: string|null, expire_le: CarbonImmutable}|null
     */
    public function colonnes(): ?array
    {
        $requete = request();

        if ($this->ecrituresHorsDeSaSociete > 0 || $requete->route() === null) {
            return null;
        }

        $navigateur = $requete->userAgent();

        return [
            'ip' => $requete->ip(),
            'agent_utilisateur' => $navigateur === null ? null : mb_substr($navigateur, 0, 255),
            'expire_le' => Calendrier::deParis()->decalerEnMoisSansDebordement(CarbonImmutable::now(), Reglage::entier('conservation.traces_en_mois')),
        ];
    }
}
