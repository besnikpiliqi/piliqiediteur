<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Services\CaissesDeLaSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

/**
 * « Les caisses » de la société, dans son espace d'administration : pour
 * le propriétaire et chaque administrateur, les caisses encore ouvertes,
 * puis les relevés de caisse fermés le jour choisi (aujourd'hui par
 * défaut), de chaque magasin réel de sa portée où il voit les relevés de
 * toute l'équipe (voir CaissesDeLaSociete), chacun avec un lien vers sa
 * page des relevés dans le magasin. Aucun nom de client, aucun document.
 * Consultable même quand la société est en lecture seule ; fermée à qui
 * n'administre pas la société et à qui n'y voit aucun magasin.
 */
class LesCaisses extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Les caisses';

    protected static ?string $title = 'Les caisses';

    protected static ?string $slug = 'caisses';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.societe.pages.les-caisses';

    /**
     * Le jour des relevés, AAAA-MM-JJ ; une valeur illisible vaut
     * aujourd'hui (voir jourChoisi()).
     */
    public string $jour = '';

    public static function canAccess(): bool
    {
        $compte = auth()->user();
        $societe = Filament::getTenant();

        return $compte instanceof User
            && $societe instanceof Societe
            && (int) $compte->societe_id === (int) $societe->getKey()
            && $compte->peutAdministrer()
            && app(CaissesDeLaSociete::class)->magasins($compte)->isNotEmpty();
    }

    public function mount(): void
    {
        $this->jour = Calendrier::deParis()->aujourdhui();
    }

    /**
     * Ce que la vue montre : la journée choisie (la propriété $jour garde
     * la saisie telle quelle), puis les caisses de chaque magasin de sa
     * portée, chacune avec l'adresse de sa page des relevés. Rien de cela
     * n'est une méthode publique : le navigateur, qui peut appeler toute
     * méthode publique d'une page Livewire avec les paramètres qu'il veut,
     * n'obtient jamais les caisses ni l'adresse d'un magasin qu'il
     * désignerait lui-même.
     *
     * @return array{journee: string, caisses: list<array{magasin: Magasin, url: string, caisses_ouvertes: list<string>, releves: Collection<int, ClotureCaissePersonnelle>}>}
     */
    protected function getViewData(): array
    {
        /** @var User $compte */
        $compte = auth()->user();
        $jour = $this->jourChoisi();

        return [
            'journee' => $jour,
            'caisses' => array_map(
                fn (array $caisses): array => [...$caisses, 'url' => ClotureCaissePersonnelleResource::getUrl('index', panel: 'app', tenant: $caisses['magasin'])],
                app(CaissesDeLaSociete::class)->pour($compte, $jour),
            ),
        ];
    }

    /**
     * Le jour choisi (« AAAA-MM-JJ »), aujourd'hui si la saisie n'est pas
     * un jour.
     */
    private function jourChoisi(): string
    {
        return Calendrier::jourValide($this->jour) ?? Calendrier::deParis()->aujourdhui();
    }
}
