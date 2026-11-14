<?php

namespace App\Domain\Cabinets\Services;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Mail\LienDuMotDePasseDeLExpertComptableMail;
use App\Domain\Cabinets\Models\CompteExpertComptable;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Filament\Societe\Pages\CabinetComptable;
use App\Integrations\Courriel\CourrielNonParti;
use App\Integrations\Courriel\EnvoiImmediat;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use SensitiveParameter;

/**
 * L'envoi du lien de 7 jours où un expert-comptable de la société choisit
 * son mot de passe, après la transaction qui l'a tiré (déclaration, ajout
 * d'une personne, correction de son adresse, accès redonné par un lien) :
 * sans file d'attente, le jeton en mémoire seulement, jamais écrit dans une
 * file ni en base (seule son empreinte l'est, voir CompteExpertComptable).
 *
 * Tout est relu au moment de l'envoi : le lien ne part que si le compte est
 * actif et de ce rôle, son lien encore celui-ci et pas expiré, sa
 * déclaration en attente, sa société ni close ni révoquée et l'auteur
 * encore son propriétaire ; sinon il ne part pas, et le propriétaire
 * actuel en est prévenu (« E-mail non envoyé »). Une messagerie en panne
 * efface le lien, prévient le propriétaire, qui en envoie un autre depuis
 * la fiche, et le dit à l'écran (voir CourrielNonParti).
 */
class EnvoiDesLiensDesExpertsComptables
{
    /**
     * @throws CourrielNonParti
     */
    public function envoyer(int $compteId, #[SensitiveParameter] string $jeton, int $auteurId, bool $accesRedonne): void
    {
        $attachement = CompteExpertComptable::parSonJeton($jeton);
        $compte = $attachement === null ? null : User::query()->where('societe_id', $attachement->societe_id)->whereKey($compteId)->first();
        $societe = $compte === null ? null : Societe::query()->find($compte->societe_id);

        if ($attachement === null || $compte === null || $societe === null || $attachement->user_id !== $compteId) {
            return;
        }

        $auteur = User::query()->where('societe_id', $societe->getKey())->whereKey($auteurId)->first();
        $declaration = app(TenantContext::class)->runAsSociete($societe, fn () => app(ExpertsComptablesDeclares::class)->declarationDe($compte));

        if (! $compte->estActif() || $compte->role_societe !== RoleSociete::ExpertComptable || ! $attachement->lienValable()
            || $declaration === null || ! $declaration->estDeclare() || $societe->estClose() || $societe->statut === StatutSociete::Revoquee
            || $auteur === null || ! $auteur->estActif() || ! $auteur->estProprietaire()) {
            self::prevenir($societe, 'E-mail non envoyé', "Le lien de {$compte->name} n'est pas parti : son compte ou votre déclaration n'est plus ouvert.");

            return;
        }

        $cabinet = $declaration->nomDuCabinetDeclare();

        try {
            EnvoiImmediat::exiger("Le lien de {$compte->name}", fn () => Mail::to(ValeurRequise::de($compte->email, 'l\'adresse d\'un expert-comptable de la société'))->send(new LienDuMotDePasseDeLExpertComptableMail(
                route('experts-comptables.lien.afficher', ['jeton' => $jeton]),
                $compte->name,
                $societe->getFilamentName(),
                (string) $societe->siren,
                $cabinet,
                Calendrier::deParis()->afficher(ValeurRequise::de($attachement->jeton_expire_le, 'l\'échéance du lien'), 'd/m/Y à H:i'),
                PermissionRegistry::libellesDe(app(TenantContext::class)->runAsSociete($societe, fn (): array => $declaration->autorisations())),
                $accesRedonne ? $auteur->name : null,
            )), 'Envoyez-lui un nouveau lien depuis sa fiche.');
        } catch (CourrielNonParti $refus) {
            app(TenantContext::class)->runAsSociete($societe, fn () => DB::transaction(function () use ($attachement): void {
                CompteExpertComptable::query()->whereKey($attachement->getKey())->lockForUpdate()->first()?->oublierLeLien();
            }));
            self::prevenir($societe, 'E-mail non envoyé', "Le compte de {$compte->name} est créé, mais l'e-mail n'a pas pu partir : envoyez-lui un nouveau lien depuis sa fiche.");

            throw $refus;
        }
    }

    /**
     * Prévient le propriétaire actuel de la société, dans sa cloche.
     */
    private static function prevenir(Societe $societe, string $titre, string $texte): void
    {
        $societe->proprietaire()->first()?->notify(new AvisDeLEquipe(
            $societe,
            $titre,
            $texte,
            CabinetComptable::getUrl(panel: 'societe', tenant: $societe),
            'Voir le cabinet comptable',
            AvisDeLEquipe::ATTENTION,
        ));
    }
}
