<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesAdministrateurs;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use LogicException;
use SensitiveParameter;

/**
 * Le propriétaire, et lui seul, transmet la propriété de sa société, en
 * redonnant son mot de passe (voir MotDePasseRedemande), à un
 * administrateur actif de la société dont la qualité de représentant
 * légal ou de mandataire est prouvée à son nom (voir
 * User::aSaQualiteVerifiee()) ; jamais à un expert-comptable, à un
 * membre, à un compte suspendu, retiré ou d'une autre société.
 *
 * Dans cet ordre, à cause des gardes de la base et de l'index d'un seul
 * propriétaire par société (voir GardesDesDroits) : le nouveau propriétaire
 * perd sa portée et ses autorisations de société, l'ancien devient
 * administrateur, le nouveau devient propriétaire, puis l'ancien reçoit
 * tous les magasins, présents et futurs, avec toutes les autorisations de
 * magasin et de société. Tout se relit sous les verrous des registres de
 * tous les magasins de la société puis des lignes des deux comptes ; le
 * changement s'inscrit au JET 130 de chaque magasin et au journal d'audit
 * (voir JournalDesDroits), et chacun des deux en est prévenu.
 */
class TransfererProprieteAction
{
    use AgitDansLaSociete;

    public const MESSAGE_PROPRIETAIRE_SEUL = 'Seul le propriétaire transmet la propriété de sa société.';

    public const MESSAGE_ADMINISTRATEUR = 'La propriété ne se transmet qu\'à un administrateur actif de la société.';

    public const MESSAGE_QUALITE = 'Cette personne doit d\'abord prouver sa qualité de représentant de la société.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesAdministrateurs $porteeDesAdministrateurs,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, User $nouveau, #[SensitiveParameter] string $motDePasse): void
    {
        $societe = Societe::query()->findOrFail($auteur->societe_id);

        $this->dansLaSociete($societe, self::MESSAGE_PROPRIETAIRE_SEUL, function () use ($auteur, $nouveau, $societe, $motDePasse): void {
            self::exigerLeProprietaire($auteur, $societe);

            MotDePasseRedemande::exiger($auteur, $motDePasse);

            $magasins = Magasin::query()->where('societe_id', $societe->getKey())->orderBy('id')->get();

            $this->journalDesDroits->modifier($auteur, ActionAuditee::ProprieteTransferee, [$nouveau, $auteur], $magasins, function (User $auteurRelu, array $relus) use ($societe): void {
                [$nouveauRelu] = $relus;
                self::exigerLeProprietaire($auteurRelu, $societe);

                if ($nouveauRelu->is($auteurRelu) || ! $nouveauRelu->estAdministrateur() || ! $nouveauRelu->peutAgir() || (int) $nouveauRelu->societe_id !== $societe->getKey()) {
                    throw new AuthorizationException(self::MESSAGE_ADMINISTRATEUR);
                }

                if (! $nouveauRelu->aSaQualiteVerifiee()) {
                    throw new LogicException(self::MESSAGE_QUALITE);
                }

                $this->porteeDesAdministrateurs->effacer($nouveauRelu);
                $auteurRelu->forceFill(['role_societe' => RoleSociete::Administrateur])->save();
                $nouveauRelu->forceFill(['role_societe' => RoleSociete::Proprietaire])->save();
                $this->porteeDesAdministrateurs->ecrire($auteurRelu, [
                    'tous' => true,
                    'magasins' => new Collection,
                    'cases' => [0 => PermissionRegistry::toutes()],
                    'societe' => AutorisationsSociete::toutes(),
                ]);
            });
        });

        $auteur->refresh()->notify(new AvisDeLEquipe($societe, 'Propriété transmise', "Vous avez transmis la propriété de {$societe->getFilamentName()} à {$nouveau->name} : vous en êtes désormais administrateur de tous les magasins, avec toutes les autorisations."));
        $nouveau->refresh()->notify(new AvisDeLEquipe($societe, 'Vous êtes propriétaire', "{$auteur->name} vous a transmis la propriété de {$societe->getFilamentName()} : vous avez désormais tous les droits dans la société et dans chacun de ses magasins.", nature: AvisDeLEquipe::ATTENTION));
    }

    /**
     * @throws AuthorizationException
     */
    private static function exigerLeProprietaire(User $auteur, Societe $societe): void
    {
        if (! app(PorteeDesDroits::class)->gereSonEquipe($auteur) || ! $auteur->estProprietaire() || (int) $auteur->societe_id !== $societe->getKey()) {
            throw new AuthorizationException(self::MESSAGE_PROPRIETAIRE_SEUL);
        }
    }
}
