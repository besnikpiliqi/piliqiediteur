<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Notifications\LienPourChoisirSonMotDePasse;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Password;

/**
 * Renouvelle l'accès d'une personne par un lien envoyé à son adresse, qui
 * mène à la page du lien (voir PageDuLien), le même que celui de « Mot de
 * passe oublié » : elle y choisit elle-même son mot de passe, que
 * l'auteur ne connaît jamais. Par qui peut renouveler l'accès de ce compte
 * (voir PorteeDesDroits::peutRenouvelerLAcces()) : le propriétaire, un
 * responsable de tous les magasins d'un vendeur ou d'un observateur, un
 * administrateur autorisé pour une personne qui n'est que responsable de
 * magasins de sa portée.
 *
 * Contrôlé sur l'auteur et le compte relus sous le verrou de leurs
 * lignes ; le geste s'inscrit au journal d'audit.
 */
class EnvoyerUnLienDeReinitialisationAction
{
    use AgitDansLaSociete;

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesDroits $portee,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(User $auteur, User $compte): void
    {
        [$auteurRelu, $jeton] = $this->dansLaSociete($compte->societe()->firstOrFail(), PorteeDesDroits::REFUS, fn (): array => $this->journalDesDroits->sousVerrouDesComptes($auteur, [$compte], function (User $auteurRelu, array $relus): array {
            [$compteRelu] = $relus;

            if (! $this->portee->peutRenouvelerLAcces($auteurRelu, $compteRelu)) {
                throw new AuthorizationException(PorteeDesDroits::REFUS);
            }

            $this->journalAudit->inscrire(ActionAuditee::LienDAccesEnvoye, $auteurRelu, null, null, [
                'compte' => ['id' => $compteRelu->getKey(), 'nom' => $compteRelu->name],
            ], $compteRelu);

            return [$auteurRelu, Password::broker()->createToken($compteRelu)];
        }));

        $compte->refresh()->notify(new LienPourChoisirSonMotDePasse(
            Filament::getPanel('app')->getResetPasswordUrl($jeton, $compte),
            $auteurRelu->name,
            (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
        ));
    }
}
