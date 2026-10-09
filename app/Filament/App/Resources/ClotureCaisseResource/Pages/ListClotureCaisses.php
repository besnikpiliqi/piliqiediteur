<?php

namespace App\Filament\App\Resources\ClotureCaisseResource\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Filament\App\Resources\ClotureCaisseResource;
use App\Filament\App\Resources\ClotureCaisseResource\Widgets\CaissesPersonnellesOuvertesApercu;
use App\Filament\App\Resources\ClotureCaisseResource\Widgets\VentesEnAttenteApercu;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListClotureCaisses extends ListRecords
{
    use GereLesExceptionsMetier;

    protected static string $resource = ClotureCaisseResource::class;

    /**
     * Clôturer la journée n'est jamais un choix de date : le magasin ne
     * clôture que la veille (le seul jour "terminé" en attente), exactement
     * comme le ferait la commande automatique de minuit si le propriétaire
     * ne la déclenche pas lui-même -- voir ClotureAutomatiqueCaisse. Les
     * deux chemins mènent donc toujours au même jour, jamais à un jour
     * choisi arbitrairement.
     */
    protected function getHeaderActions(): array
    {
        $veille = now()->subDay();

        // Mêmes critères que CloturerCaisseAction (ventes au comptoir de la
        // veille et avoirs du même jour, particuliers uniquement, pas déjà
        // clôturés) : le vendeur voit avant de confirmer exactement ce qui
        // va être regroupé, pas seulement un avertissement générique.
        $ventesAClore = Facture::query()->enAttenteDeCloture($veille)->get();
        $avoirsAClore = Avoir::query()->enAttenteDeCloture($veille)->get();
        $calculateur = app(CalculateurLigne::class);
        $totalNet = number_format($calculateur->deduire($calculateur->cumuler($ventesAClore), $calculateur->cumuler($avoirsAClore))['total_ttc'], 2, ',', ' ');

        $avertissement = 'Cette action est IRRÉVERSIBLE : une fois clôturée, cette journée ne pourra plus jamais être modifiée, ni clôturée à nouveau.';

        $modalDescription = match (true) {
            $ventesAClore->isEmpty() && $avoirsAClore->isEmpty() => "Aucune vente au comptoir n'a été enregistrée ce jour-là : la clôture sera créée à zéro. {$avertissement}",
            $avoirsAClore->isEmpty() => sprintf('%d vente(s) au comptoir pour un total de %s € seront regroupées dans cette clôture. %s', $ventesAClore->count(), $totalNet, $avertissement),
            default => sprintf('%d vente(s) au comptoir et %d avoir(s) déduit(s), pour un total net de %s €, seront regroupés dans cette clôture. %s', $ventesAClore->count(), $avoirsAClore->count(), $totalNet, $avertissement),
        };

        // Seule la clôture manuelle attend les caisses personnelles : tant
        // qu'un vendeur n'a pas fermé ses ventes de la journée, le bouton
        // reste désactivé et nomme les vendeurs à relancer (voir
        // VerificateurCaissesPersonnelles). La clôture automatique de
        // minuit, elle, clôture toujours.
        $vendeursEnAttente = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte($veille);

        return [
            Actions\Action::make('cloturer')
                ->label('Clôturer une journée')
                ->icon('heroicon-o-lock-closed')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Clôturer la journée du '.$veille->translatedFormat('d/m/Y').' ?')
                ->modalDescription($modalDescription)
                ->modalSubmitActionLabel('Oui, clôturer définitivement')
                ->visible(fn () => auth()->user()->can(PermissionRegistry::CLOTURE_CAISSE_GERER)
                    && ClotureCaisse::query()->whereDate('date_cloture', $veille)->doesntExist())
                ->disabled($vendeursEnAttente->isNotEmpty())
                ->tooltip($vendeursEnAttente->isNotEmpty()
                    ? 'Caisses personnelles non fermées : '.$vendeursEnAttente->pluck('name')->implode(', ')
                    : null)
                ->action(fn () => static::executerSansCasser(fn () => app(CloturerCaisseAction::class)->handle(Filament::getTenant(), $veille))),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            VentesEnAttenteApercu::class,
            CaissesPersonnellesOuvertesApercu::class,
        ];
    }
}
