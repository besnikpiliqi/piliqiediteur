<?php

namespace App\Filament;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\ValeursLues;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le filtre « Jour » des listes de documents d'un magasin (choix de l'éditeur
 * du 16/11/2026, lot GRAPH-AN, « liste filtrée des documents de ce jour ») :
 * là où mène le clic sur un jour d'un graphique par période. Le jour est un
 * jour « AAAA-MM-JJ » du calendrier du magasin (voir Magasin::calendrier()),
 * de son minuit au suivant : une valeur qui n'est pas un jour réel ne filtre
 * rien. Chaque liste dit comment elle date ses documents (la date de leur
 * émission, la date de la facture du fournisseur). Le filtre ne montre que
 * ce que la liste montre déjà à la personne, qui la filtre : les droits de
 * la liste (voir ses scopes et ses politiques) ne changent pas.
 */
final class FiltreDUnJour
{
    /** Le nom du filtre, celui de son adresse : `tableFilters[jour][jour]=AAAA-MM-JJ`. */
    public const NOM = 'jour';

    /**
     * @param  string  $libelle  ce que dit le champ (« Jour d'émission »)
     * @param  Closure  $requete  (Builder, Calendrier, string): Builder : la requête de la liste restreinte aux documents de ce jour du calendrier du magasin
     */
    public static function make(string $libelle, Closure $requete): Filter
    {
        return Filter::make(self::NOM)
            ->label($libelle)
            ->form([DatePicker::make('jour')->label($libelle)])
            ->query(function (Builder $query, array $data) use ($requete): Builder {
                $jour = self::jourDe($data);

                return $jour === null ? $query : ValeursLues::instance($requete($query, ValeursLues::instance(Filament::getTenant(), Magasin::class)->calendrier(), $jour), Builder::class);
            })
            ->indicateUsing(function (array $data) use ($libelle): ?string {
                $jour = self::jourDe($data);

                return $jour === null ? null : $libelle.' : '.Calendrier::afficherJour($jour, 'd/m/Y');
            });
    }

    /**
     * Le jour que dit le formulaire du filtre, s'il est réel.
     *
     * @param  array<array-key, mixed>  $data
     */
    private static function jourDe(array $data): ?string
    {
        $jour = $data['jour'] ?? null;

        return is_string($jour) ? Calendrier::jourValide($jour) : null;
    }
}
