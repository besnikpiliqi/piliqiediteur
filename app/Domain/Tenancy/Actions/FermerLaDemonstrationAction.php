<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Actions\FermerLesCaissesPersonnellesAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Identifiant;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PeriodesDActivite;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use LogicException;
use Throwable;

/**
 * Ferme le magasin de démonstration d'une société : à sa validation (voir
 * ValiderLaSocieteAction) ou à la clôture de son inscription (voir
 * CloreLInscriptionAction), ou après trente jours sans
 * opération (voir app:fermer-demonstrations-inactives). Sous le verrou de
 * son registre, en une transaction : ses journées en attente se clôturent
 * jusqu'au jour même, sa sortie du mode démonstration s'inscrit à son
 * journal des événements (JET 105), son statut passe à fermé avec son
 * motif (JET 270), sa période d'activité se ferme ; puis ses caisses personnelles restées
 * ouvertes se ferment d'office (R5), sans jamais annuler la fermeture.
 * Le registre l'inscrit au nom de la personne qui la ferme, ou du système ;
 * les journées se clôturent et les caisses se ferment au nom du système.
 *
 * Quand elle se ferme pour de bon, c'est-à-dire quand la société est
 * validée ou close, ses comptes d'entraînement (les comptes de membre
 * rattachés à la démonstration, voir User::estUnCompteDEntrainement()) sont
 * retirés de la société, dans la même transaction, au nom du système et
 * sans décision du propriétaire (voir JournalDesDroits) : ils n'ouvrent
 * plus rien, leurs sessions se ferment, et ils gardent leur historique de
 * démonstration. Une démonstration déjà fermée pour inactivité retire
 * alors aussi les siens. Fermée après trente jours sans opération, elle
 * garde ses comptes d'entraînement, qui la retrouvent à sa réouverture.
 *
 * Fermé, il reste consultable, en lecture seule (« Démonstration
 * (terminée) »), et ne produit plus de ticket Z. Déjà fermé, rien n'est
 * refait.
 */
class FermerLaDemonstrationAction
{
    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly CloturerLesJourneesAction $cloturerLesJournees,
        private readonly FermerLesCaissesPersonnellesAction $fermerLesCaisses,
        private readonly PeriodesDActivite $periodesDActivite,
        private readonly JournalAudit $journalAudit,
        private readonly OperateurCourant $operateur,
        private readonly JournalDesDroits $journalDesDroits,
        private readonly SessionsDesComptes $sessions,
    ) {}

    /**
     * @param  User|null  $auteur  la personne qui la ferme ; null pour le système
     *
     * @throws LogicException quand le magasin n'est pas un magasin de démonstration
     */
    public function handle(Magasin $demonstration, MotifFermeture $motif = MotifFermeture::FinDemonstration, ?User $auteur = null): void
    {
        if (! $demonstration->est_demonstration) {
            throw new LogicException('Seul le magasin de démonstration se ferme ainsi.');
        }

        $contexte = app(TenantContext::class);
        $maintenant = CarbonImmutable::now();

        $auNomDe = fn (Closure $operation): mixed => $auteur === null ? $this->operateur->pendantLeSysteme($operation) : $this->operateur->pendant($auteur, $operation);

        $fermee = $contexte->runAs($demonstration, fn (): bool => $auNomDe(fn (): bool => $this->registre->sousVerrou($demonstration, function () use ($demonstration, $motif, $auteur, $maintenant): bool {
            $verrouille = Magasin::query()->whereKey($demonstration->getKey())->lockForUpdate()->firstOrFail();

            if ($verrouille->statut === StatutMagasin::Ferme) {
                $this->retirerLesComptesDEntrainementSiElleFinit($verrouille);

                return false;
            }

            $this->cloturerLesJournees->parLeSysteme($verrouille, Calendrier::deParis()->jourDe($maintenant));
            $this->registre->journaliser(CodeEvenement::SortieDuModeDemonstration, ['motif' => $motif->value]);
            $verrouille->forceFill(['statut' => StatutMagasin::Ferme, 'motif_fermeture' => $motif])->save();
            $this->registre->journaliser(CodeEvenement::ParametreDeConformite, [
                'parametre' => 'statut_magasin',
                'magasin' => ['id' => $verrouille->getKey(), 'nom' => $verrouille->nom],
                'ancienne_valeur' => StatutMagasin::Actif->value,
                'nouvelle_valeur' => StatutMagasin::Ferme->value,
            ]);
            $this->periodesDActivite->fermer($verrouille, $motif, $auteur);
            $this->journalAudit->inscrire(ActionAuditee::DemonstrationFermee, $auteur, $verrouille, null, ['motif' => $motif->value], $verrouille);
            $this->retirerLesComptesDEntrainementSiElleFinit($verrouille);

            return true;
        })));

        if (! $fermee) {
            return;
        }

        try {
            $contexte->runAs($demonstration, fn () => $this->fermerLesCaisses->handle($demonstration, $maintenant));
        } catch (Throwable $erreur) {
            report($erreur);
        }
    }

    /**
     * Retire les comptes d'entraînement de cette démonstration quand sa
     * société est validée ou close, au nom du système, sous le verrou du
     * registre de la démonstration (déjà pris) puis de leurs lignes (voir
     * JournalDesDroits) ; rien sinon.
     */
    private function retirerLesComptesDEntrainementSiElleFinit(Magasin $demonstration): void
    {
        if (! $demonstration->estUneDemonstrationTerminee()) {
            return;
        }

        $comptes = User::query()
            ->where('societe_id', $demonstration->societe_id)
            ->where('role_societe', RoleSociete::Membre->value)
            ->where('statut', '<>', StatutCompte::Retire->value)
            ->whereIn('id', Identifiant::query()->where('magasin_id', $demonstration->getKey())->select('user_id'))
            ->orderBy('id')
            ->get()
            ->all();

        if ($comptes === []) {
            return;
        }

        $this->journalDesDroits->modifier(null, ActionAuditee::CompteRetire, $comptes, [], function (?User $systeme, array $relus): void {
            foreach ($relus as $compte) {
                if ($compte->estRetire()) {
                    continue;
                }

                ChangerStatutCompteAction::retirerLesDroits($compte);
                $compte->forceFill($compte->nouveauStatut(StatutCompte::Retire, null))->save();
                $compte->effacerToutesSesNotifications();
                $this->sessions->fermer($compte);
            }
        });
    }
}
