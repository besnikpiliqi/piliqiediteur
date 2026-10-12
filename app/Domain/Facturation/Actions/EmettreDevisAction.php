<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
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
 */
class EmettreDevisAction
{
    public function __construct(
        private readonly DocumentNumberingService $numerotation,
        private readonly LecteurDesDroits $lecteur,
    ) {}

    /**
     * Le magasin actif, relu, doit pouvoir vendre ; puis son auteur, relu
     * sous le verrou de sa ligne avant celui du devis (voir
     * LecteurDesDroits), doit pouvoir envoyer ce devis.
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Devis $devis): Devis
    {
        return DB::transaction(function () use ($auteur, $devis) {
            $magasin = Magasin::query()->findOrFail(app(TenantContext::class)->id());
            $magasin->verifierQuIlPeutVendre();
            $this->lecteur->exigerSousVerrou($auteur, 'envoyer', $magasin, $devis);
            $devis = Devis::query()->whereKey($devis->getKey())->lockForUpdate()->firstOrFail();

            if (! $devis->statut->peutTransitionnerVers(StatutDevis::Envoye)) {
                throw new LogicException("Impossible d'envoyer un devis au statut [{$devis->statut->value}].");
            }

            if ($devis->lignes()->doesntExist()) {
                throw new LogicException('Impossible d\'envoyer un devis sans lignes.');
            }

            if ($devis->validiteDepassee()) {
                throw new LogicException("La date de validité de ce devis est dépassée ({$devis->date_validite->format('d/m/Y')}) : changez-la avant de l'envoyer.");
            }

            ContexteDeVente::refuserSiLeTypeDeClientAChange($devis);
            ContexteDeVente::refuserSiLeRegimeAChange($devis);

            $devis->forceFill([
                'numero' => $devis->numero ?? $this->numerotation->suivant($devis->magasin, TypeDocument::Devis, now()->format('Y')),
                'statut' => StatutDevis::Envoye,
                'date_emission' => now(),
            ])->save();

            return $devis;
        });
    }
}
