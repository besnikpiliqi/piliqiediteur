<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Un brouillon part au statut « Envoyé » (voir EnvoyerDocumentAction) : il
 * reçoit son numéro à son premier envoi, et garde le sien, sans en
 * consommer un autre, quand il repart après avoir été modifié (voir
 * ModifierDevisAction). Sa date d'émission est celle de cet envoi : elle
 * remplace celle de l'envoi précédent, qu'il gardait depuis sa
 * modification. Un magasin qui ne peut pas vendre (lecture seule, société
 * en cours de vérification, établissement pas encore confirmé) n'en envoie
 * pas (voir Magasin::verifierQuIlPeutVendre()). Un devis dont la date de validité est
 * déjà dépassée ne part pas : son client ne pourrait plus l'accepter (voir
 * AccepterDevisAction).
 *
 * Comme une facture à son émission (voir EmettreFactureAction), un devis
 * enregistré avant que son client change de type, ou que la société entre
 * en franchise en base ou en sorte, ne part pas tant qu'il n'est pas
 * recalculé : le client accepterait un prix que la facture tirée du devis
 * ne reprendrait pas (voir ContexteDeVente::refuserSiLeTypeDeClientAChange()
 * et ContexteDeVente::refuserSiLeRegimeAChange()).
 *
 * L'émission demandée par une personne porte un jeton (R3, voir
 * ActionsUniques), cherché sous le verrou partagé de la ligne du magasin
 * puis sous celui de la ligne de son auteur :
 * répétée après une réponse perdue, elle rend le devis déjà émis. Un envoi
 * (voir EnvoyerDocumentAction) l'appelle sans jeton : il porte le sien.
 */
class EmettreDevisAction
{
    public function __construct(
        private readonly DocumentNumberingService $numerotation,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly ActionsUniques $actionsUniques,
    ) {}

    /**
     * Le magasin actif, relu sous un verrou partagé, doit pouvoir vendre ;
     * puis son auteur, relu sous le verrou de sa ligne avant celui du devis
     * (voir LecteurDesDroits), doit pouvoir envoyer le devis relu. Une
     * fermeture du magasin, qui verrouille sa ligne (voir
     * FermerMagasinAction), passe tout entière avant ou après l'émission :
     * aucun numéro de devis ne se consomme dans un magasin déjà fermé. Un
     * devis supprimé entre-temps est refusé.
     *
     * @param  string|null  $jeton  le jeton de la demande (R3), null quand une autre action l'appelle
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Devis $devis, ?string $jeton = null): Devis
    {
        $magasin = app(TenantContext::class)->magasin();
        $verrou = function () use ($magasin, $auteur): void {
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail();
            ActionsUniques::verrouDe($auteur)();
        };

        return $this->actionsUniques->executer($jeton, ActionsUniques::EMETTRE_DEVIS, $auteur, ['devis_id' => $devis->getKey()], $verrou, fn (): Devis => DB::transaction(function () use ($auteur, $devis, $magasin) {
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlPeutVendre();
            $compte = $this->lecteur->exigerSousVerrou($auteur, PermissionRegistry::DEVIS_ENVOYER, $magasin);
            $devis = Devis::query()->whereKey($devis->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(Devis::REFUS_INTROUVABLE);
            $this->lecteur->exiger($compte, 'envoyer', $magasin, $devis);

            if (! $devis->statut->peutTransitionnerVers(StatutDevis::Envoye)) {
                throw new LogicException("Impossible d'envoyer un devis au statut [{$devis->statut->value}].");
            }

            if ($devis->lignes()->doesntExist()) {
                throw new LogicException('Impossible d\'envoyer un devis sans lignes.');
            }

            if ($devis->validiteDepassee()) {
                throw new LogicException('La date de validité de ce devis est dépassée ('.Calendrier::afficherJour($devis->date_validite, 'd/m/Y').") : changez-la avant de l'envoyer.");
            }

            ContexteDeVente::refuserSiLeTypeDeClientAChange($devis);
            ContexteDeVente::refuserSiLeRegimeAChange($devis);

            $maintenant = CarbonImmutable::now();

            return $this->operateur->pendant($compte, fn (): Devis => tap($devis->forceFill([
                'numero' => $devis->numero ?? $this->numerotation->suivant($magasin, TypeDocument::Devis, $devis->magasin->calendrier()->anneeDe($maintenant)),
                'statut' => StatutDevis::Envoye,
                'date_emission' => $maintenant,
            ]))->save());
        }));
    }
}
