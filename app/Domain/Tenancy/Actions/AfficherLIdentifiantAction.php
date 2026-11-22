<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * « Afficher l'identifiant », pour un administrateur, sur la fiche d'un
 * compte dont il peut redonner l'accès (voir
 * PorteeDesDroits::peutAfficherLIdentifiant()) : l'identifiant de
 * connexion n'apparaît jamais dans ses listes ni ses fiches, et un
 * administrateur ne le voit que par ce geste. Il s'inscrit au journal
 * d'audit (IdentifiantConsulte : le compte, jamais la valeur). L'identifiant
 * n'est plus jamais un mot de passe (voir MotsDePasseProvisoires) : le voir
 * n'ouvre aucun compte, et le propriétaire n'en est plus prévenu.
 */
class AfficherLIdentifiantAction
{
    use AgitDansLaSociete;

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesDroits $portee,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @return string l'identifiant du compte
     *
     * @throws AuthorizationException
     */
    public function handle(User $auteur, User $compte): string
    {
        $societe = $compte->societe()->firstOrFail();

        return $this->dansLaSociete($societe, PorteeDesDroits::REFUS, fn (): string => $this->journalDesDroits->sousVerrouDesComptes($auteur, [$compte], function (User $auteurRelu, array $relus): string {
            [$compteRelu] = $relus;

            if (! $this->portee->peutAfficherLIdentifiant($auteurRelu, $compteRelu)) {
                throw new AuthorizationException(PorteeDesDroits::REFUS);
            }

            $this->journalAudit->inscrire(ActionAuditee::IdentifiantConsulte, $auteurRelu, null, null, [
                'compte' => ['id' => $compteRelu->getKey(), 'nom' => $compteRelu->name],
            ]);

            return (string) $compteRelu->identifiantDeConnexion();
        }));
    }
}
