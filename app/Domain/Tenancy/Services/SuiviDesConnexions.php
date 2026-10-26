<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Ce que note une connexion réussie, une fois le compte admis dans le
 * panneau et sa session régénérée (voir Connexion::authenticate()), et
 * jamais une connexion refusée :
 * - à la première : premiere_connexion_le, l'entrée d'audit
 *   PremiereConnexion et un avis au propriétaire (pas pour lui-même) ;
 *   tant que le mot de passe est encore l'identifiant, une alerte qui
 *   conseille à la personne de le changer ;
 * - à chaque connexion : la date et l'heure de sa connexion précédente
 *   (rien à la première), et, si son accès a été redonné depuis, quand,
 *   par qui et en sa présence ou non ; puis derniere_connexion_le. La
 *   personne voit ainsi si quelqu'un est entré entre-temps.
 */
final class SuiviDesConnexions
{
    public const ALERTE_IDENTIFIANT = 'Votre mot de passe est encore votre identifiant : toute personne qui connaît votre identifiant peut se connecter à votre place. Nous vous conseillons de le changer dans votre profil.';

    public function __construct(private readonly JournalAudit $journalAudit) {}

    public function noterLaConnexion(User $compte): void
    {
        $societe = $compte->societe()->firstOrFail();

        [$premiere, $precedente, $redonne] = app(TenantContext::class)->runAsSociete($societe, fn (): array => DB::transaction(function () use ($compte): array {
            $relu = User::query()->whereKey($compte->getKey())->lockForUpdate()->firstOrFail();
            $premiere = $relu->premiere_connexion_le === null;
            $precedente = $relu->derniere_connexion_le;
            $redonne = $relu->acces_redonne_le !== null && ($precedente === null || $relu->acces_redonne_le->greaterThan($precedente));

            $relu->forceFill(['premiere_connexion_le' => $relu->premiere_connexion_le ?? now(), 'derniere_connexion_le' => now()])->save();

            if ($premiere && ! $relu->estProprietaire()) {
                $this->journalAudit->inscrire(ActionAuditee::PremiereConnexion, $relu, null, null, ['compte' => ['id' => $relu->getKey(), 'nom' => $relu->name]]);
            }

            return [$premiere, $precedente, $redonne ? $relu : null];
        }));

        $compte->refresh();

        if ($premiere && ! $compte->estProprietaire()) {
            $societe->proprietaire?->notify(new AvisDeLEquipe($societe, 'Première connexion', "{$compte->name} s'est connecté à son compte pour la première fois."));
        }

        if ($premiere && $compte->mot_de_passe_est_l_identifiant && ! $compte->doitChoisirSonMotDePasse()) {
            Notification::make()
                ->title('Changez votre mot de passe')
                ->body(self::ALERTE_IDENTIFIANT)
                ->warning()
                ->persistent()
                ->actions([Action::make('changer')->label('Changer mon mot de passe')->url(Filament::getProfileUrl())->button()])
                ->send();
        }

        if ($precedente !== null) {
            Notification::make()->title('Votre connexion précédente : '.self::date($compte, $precedente))->info()->send();
        }

        if ($redonne !== null) {
            Notification::make()
                ->title(self::accesRedonne($redonne))
                ->warning()
                ->persistent()
                ->send();
        }
    }

    /**
     * Le message d'un accès redonné, tel que la personne le voit à sa
     * connexion suivante.
     */
    public static function accesRedonne(User $compte): string
    {
        $auteur = $compte->acces_redonne_par === null ? null : User::query()->whereKey($compte->acces_redonne_par)->value('name');

        return 'Votre accès a été redonné '.self::date($compte, $compte->acces_redonne_le).($auteur === null ? '' : " par {$auteur}").', '.($compte->acces_redonne_en_presence ? 'en votre présence.' : 'en votre absence.');
    }

    /**
     * Une heure du compte, dans son calendrier (voir User::calendrier()),
     * sans mention : la personne lit sa propre heure.
     */
    private static function date(User $compte, CarbonInterface $instant): string
    {
        return 'le '.$compte->calendrier()->afficher($instant, 'd/m/Y à H:i');
    }
}
