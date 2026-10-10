<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Un brouillon part au statut « Envoyé » (voir EnvoyerDocumentAction) : il
 * reçoit son numéro à son premier envoi, et garde le sien, sans en
 * consommer un autre, quand il repart après avoir été modifié (voir
 * ModifierDevisAction). Sa date d'émission est celle de cet envoi : elle
 * remplace celle de l'envoi précédent, qu'il gardait depuis sa
 * modification. Un magasin suspendu ou fermé n'en envoie plus (voir
 * Magasin::verifierQuIlEstOuvert()). Un devis dont la date de validité est
 * déjà dépassée ne part pas : son client ne pourrait plus l'accepter (voir
 * AccepterDevisAction).
 *
 * Comme une facture à son émission (voir EmettreFactureAction), un devis
 * enregistré avant que son client change de type, ou que le magasin entre
 * en franchise en base ou en sorte, ne part pas tant qu'il n'est pas
 * recalculé : le client accepterait un prix que la facture tirée du devis
 * ne reprendrait pas (voir ContexteDeVente::refuserSiLeTypeDeClientAChange()
 * et ContexteDeVente::refuserSiLeRegimeAChange()).
 */
class EmettreDevisAction
{
    public function __construct(private readonly DocumentNumberingService $numerotation) {}

    /**
     * @throws LogicException
     */
    public function handle(Devis $devis): Devis
    {
        return DB::transaction(function () use ($devis) {
            $devis = Devis::query()->whereKey($devis->getKey())->lockForUpdate()->firstOrFail();
            $devis->magasin->verifierQuIlEstOuvert();

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
