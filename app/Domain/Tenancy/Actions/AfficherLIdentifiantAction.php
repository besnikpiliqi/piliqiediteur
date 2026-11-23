<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Filament\Societe\Resources\MembreResource;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * « Afficher l'identifiant », pour un administrateur, sur la fiche d'un
 * compte dont il peut redonner l'accès (voir
 * PorteeDesDroits::peutAfficherLIdentifiant()) : l'identifiant de
 * connexion n'apparaît jamais dans ses listes ni ses fiches, puisque, pour
 * un compte qui ne l'a pas changé, il est aussi le mot de passe. Le geste
 * s'inscrit au journal d'audit (IdentifiantConsulte : le compte, jamais la
 * valeur), et, quand le mot de passe du compte est encore son identifiant,
 * le propriétaire en est prévenu, dans sa cloche et par e-mail.
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

        [$identifiant, $estLeMotDePasse] = $this->dansLaSociete($societe, PorteeDesDroits::REFUS, fn (): array => $this->journalDesDroits->sousVerrouDesComptes($auteur, [$compte], function (User $auteurRelu, array $relus): array {
            [$compteRelu] = $relus;

            if (! $this->portee->peutAfficherLIdentifiant($auteurRelu, $compteRelu)) {
                throw new AuthorizationException(PorteeDesDroits::REFUS);
            }

            $this->journalAudit->inscrire(ActionAuditee::IdentifiantConsulte, $auteurRelu, null, null, [
                'compte' => ['id' => $compteRelu->getKey(), 'nom' => $compteRelu->name],
            ]);

            return [(string) $compteRelu->identifiantDeConnexion(), $compteRelu->mot_de_passe_est_l_identifiant];
        }));

        if ($estLeMotDePasse) {
            $societe->proprietaire?->notify(new AvisDeLEquipe(
                $societe,
                'Identifiant affiché à un administrateur',
                "{$auteur->name} a affiché l'identifiant de connexion de {$compte->name}, dont le mot de passe est encore cet identifiant : il pourrait se connecter à sa place.",
                MembreResource::getUrl('view', ['record' => $compte], panel: 'societe', tenant: $societe),
                nature: AvisDeLEquipe::ATTENTION,
                parCourriel: true,
            ));
        }

        return $identifiant;
    }
}
