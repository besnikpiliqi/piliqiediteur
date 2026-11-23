<?php

namespace App\Domain\Tenancy\Notifications;

use App\Domain\Facturation\Enums\ReferenceDeTva;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Support\TableDesMentionsDeTva;
use App\Domain\Tenancy\Models\Magasin;
use Filament\Notifications\Notification as NotificationFilament;

/**
 * Le magasin vient d'être cédé, ouvert ou déjà fermé (voir
 * FermerMagasinAction) : son propriétaire reçoit la valeur hors taxe du
 * stock sorti au coût moyen, à rapprocher du prix de cession, et la liste
 * des produits restés en stock négatif, qui n'ont fait aucun mouvement.
 * L'avis cite les articles de la transmission d'une activité en vigueur le
 * jour de la cession, au calendrier du magasin (voir
 * TableDesMentionsDeTva), et se fige à son envoi, comme toute notification.
 */
class StockDuMagasinCede extends NotificationDuMagasin
{
    /**
     * @param  string  $valeurHt  la valeur hors taxe du stock sorti, au centime (« 1234.50 »)
     * @param  list<string>  $produitsEnStockNegatif  les références et les noms des produits restés en stock négatif
     */
    public function __construct(Magasin $magasin, public readonly string $valeurHt, public readonly array $produitsEnStockNegatif)
    {
        parent::__construct($magasin);
    }

    protected function contenu(): NotificationFilament
    {
        $calculateur = app(CalculateurLigne::class);
        $valeur = $calculateur->enEuros($calculateur->enCentiemes($this->valeurHt));

        return NotificationFilament::make()
            ->title('Magasin cédé : valeur du stock sorti')
            ->body("Valeur du stock au coût moyen : {$valeur} HT. À rapprocher du prix de cession prévu à l'acte et à faire vérifier par votre expert-comptable (".TableDesMentionsDeTva::texte(ReferenceDeTva::TransmissionDUneActiviteEconomique, $this->magasin->calendrier()->aujourdhui()).', ou vente du stock à facturer).'
                .($this->produitsEnStockNegatif === [] ? '' : ' Produits restés en stock négatif, sans mouvement : '.implode(', ', $this->produitsEnStockNegatif).'.'))
            ->icon('heroicon-o-building-storefront')
            ->warning();
    }
}
