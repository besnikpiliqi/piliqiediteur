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
 * Le devis reçoit son numéro quand il part (voir EnvoyerDocumentAction).
 * Un magasin suspendu ou fermé n'en envoie plus (voir
 * Magasin::verifierQuIlEstOuvert()). Comme une facture à son émission (voir
 * EmettreFactureAction), un devis enregistré avant que son client change
 * de type, ou que le magasin entre en franchise en base ou en sorte, ne
 * part pas tant qu'il n'est pas recalculé : le client accepterait un prix
 * que la facture tirée du devis ne reprendrait pas (voir
 * ContexteDeVente::refuserSiLeTypeDeClientAChange() et
 * ContexteDeVente::refuserSiLeRegimeAChange()).
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

            ContexteDeVente::refuserSiLeTypeDeClientAChange($devis);
            ContexteDeVente::refuserSiLeRegimeAChange($devis);

            $numero = $this->numerotation->suivant($devis->magasin, TypeDocument::Devis, now()->format('Y'));

            $devis->forceFill([
                'numero' => $numero,
                'statut' => StatutDevis::Envoye,
                'date_emission' => now(),
            ])->save();

            return $devis;
        });
    }
}
