<?php

namespace App\Domain\Tenancy\Notifications;

use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\MessageDeCession;
use Filament\Notifications\Notification as NotificationFilament;

/**
 * Le magasin vient d'être cédé, ouvert ou déjà fermé (voir
 * FermerMagasinAction) : son propriétaire reçoit la valeur hors taxe du
 * stock sorti au coût moyen, à rapprocher du prix de cession, ce qu'elle
 * devient pour la TVA selon que le magasin a été cédé avec son activité ou
 * que son stock se vendait seul (voir MessageDeCession, qui l'avait déjà
 * dit avant la confirmation de la cession), et la liste des produits
 * restés en stock négatif, qui n'ont fait aucun mouvement.
 */
class StockDuMagasinCede extends NotificationDuMagasin
{
    /**
     * @param  string  $valeurHt  la valeur hors taxe du stock sorti, au centime (« 1234.50 »)
     * @param  list<string>  $produitsEnStockNegatif  les références et les noms des produits restés en stock négatif
     * @param  string  $jour  AAAA-MM-JJ, le jour de la cession, qui choisit l'article cité (CGI ou CIBS)
     */
    public function __construct(Magasin $magasin, public readonly string $valeurHt, public readonly array $produitsEnStockNegatif, public readonly string $jour)
    {
        parent::__construct($magasin);
    }

    protected function contenu(): NotificationFilament
    {
        $calculateur = app(CalculateurLigne::class);

        return NotificationFilament::make()
            ->title('Magasin cédé : valeur du stock sorti')
            ->body(app(MessageDeCession::class)->apresLaCession($calculateur->enCentiemes($this->valeurHt), $this->jour)
                .($this->produitsEnStockNegatif === [] ? '' : ' Produits restés en stock négatif, sans mouvement : '.implode(', ', $this->produitsEnStockNegatif).'.'))
            ->icon('heroicon-o-building-storefront')
            ->warning();
    }
}
