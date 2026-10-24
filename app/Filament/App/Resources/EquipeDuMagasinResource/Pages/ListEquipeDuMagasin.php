<?php

namespace App\Filament\App\Resources\EquipeDuMagasinResource\Pages;

use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\EquipeDuMagasinResource;
use App\Filament\Concerns\MontreLeCompteCree;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;

/**
 * L'équipe du magasin, avec la création d'un compte de ce magasin, le seul
 * qu'il ouvrira : un vendeur ou un observateur, par le propriétaire ou un
 * responsable du magasin, ou un responsable, par le propriétaire ; dans le
 * magasin de démonstration, des comptes d'entraînement. La fenêtre tire son
 * jeton à son ouverture (une demande répétée après une réponse perdue ne
 * crée jamais un second compte), ne demande aucune adresse e-mail, propose
 * de réintégrer l'ancien compte retiré du même nom, et elle est suivie de
 * la fenêtre qui montre l'identifiant du compte créé, aussi son premier mot
 * de passe (voir GereLesComptes::fenetreDuCompteCree()).
 */
class ListEquipeDuMagasin extends ListRecords
{
    use MontreLeCompteCree;

    protected static string $resource = EquipeDuMagasinResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->creerUnCompteAction(),
        ];
    }

    public function creerUnCompteAction(): Action
    {
        return Action::make('creerUnCompte')
            ->label('Créer un compte')
            ->icon('heroicon-o-user-plus')
            ->authorize('create', Affectation::class)
            ->fillForm(fn (): array => ['jeton' => (string) Str::uuid()])
            ->form([
                ...EquipeDuMagasinResource::champsDuNouveauMembre(fn (): Magasin => $this->magasin()),
                EquipeDuMagasinResource::champRole(),
                ...EquipeDuMagasinResource::champsAutorisations(),
            ])
            ->modalDescription('Un compte ne vaut que pour ce magasin. Son identifiant, tiré au hasard, est aussi son premier mot de passe : vous le verrez juste après, pour le remettre en main propre.')
            ->action(function (array $data): void {
                $cree = null;

                EquipeDuMagasinResource::executerLeGeste(function () use ($data, &$cree): void {
                    $cree = EquipeDuMagasinResource::creerOuReintegrerUnMembre(
                        $this->auteur(),
                        $this->magasin(),
                        $data,
                        $data['role'],
                        EquipeDuMagasinResource::autorisationsSaisies($data, 'autorisations'),
                    );
                });

                if ($cree instanceof User) {
                    $this->montrerLeCompteCree($cree);
                }
            });
    }

    private function magasin(): Magasin
    {
        /** @var Magasin */
        return Filament::getTenant();
    }

    private function auteur(): User
    {
        $auteur = auth()->user();

        abort_unless($auteur instanceof User, 403);

        return $auteur;
    }
}
