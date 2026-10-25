<?php

namespace App\Domain\Tenancy\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use LogicException;
use UnitEnum;

/**
 * Une fiche du magasin telle qu'une personne l'a lue en ouvrant son
 * formulaire : un produit (sa page « Produits » ou la ligne d'une facture
 * d'achat), un client, un fournisseur, un service, les paramètres du
 * magasin. Le formulaire garde cette lecture le temps que la personne le
 * remplit ; à l'enregistrement, seule cette comparaison décide de ce
 * qu'il écrit, pour tous ces écrans :
 *
 * - corrections() : les attributs que la personne a corrigés, ceux dont
 *   la valeur saisie diffère de la valeur lue. Les autres ne s'écrivent
 *   pas : ce qu'une autre personne a changé entre-temps sur la fiche
 *   reste, au lieu d'être remis à la valeur que le formulaire affichait
 *   encore.
 * - refuserUneCorrectionPerimee() : sur la fiche relue sous verrou, au
 *   moment d'écrire, une correction portant sur un attribut qu'une autre
 *   personne a changé depuis la lecture est refusée, avec le message de
 *   l'écran, sauf si cette personne lui a déjà donné la valeur saisie :
 *   personne n'efface sans le savoir la correction d'un autre.
 *
 * Les valeurs se comparent comme le modèle les rend (ses casts et ses
 * mutateurs) : un taux « 20 » saisi vaut le « 20.00 » lu, un nombre de
 * jours « 30 » vaut 30, une énumération vaut sa valeur, un barème vaut un
 * barème de mêmes paliers ; un champ vidé vaut un attribut vide. La fiche
 * lue garde les attributs modifiables de la fiche ($fillable), ou
 * seulement ceux qu'un écran règle (les paramètres d'une page du magasin,
 * que chaque autorisation règle à part, voir
 * ModifierLesParametresDuMagasinAction::parametresDe()), tels que la base
 * les a rendus : des valeurs simples, que l'écran garde avec lui et que le
 * navigateur peut lire (voir valeurs()), jamais un attribut que l'écran
 * ne montre pas.
 */
final class FicheLue
{
    /**
     * @param  class-string<Model>  $modele
     * @param  array<string, mixed>  $valeurs  les attributs modifiables de la fiche, tels que la base les a rendus
     */
    private function __construct(
        private readonly string $modele,
        private readonly array $valeurs,
    ) {}

    /**
     * La fiche telle que la base l'a rendue à sa lecture : ses attributs
     * modifiables, ou seulement ceux-là parmi eux.
     *
     * @param  list<string>|null  $attributs
     */
    public static function de(Model $fiche, ?array $attributs = null): self
    {
        return new self($fiche::class, Arr::only($fiche->getRawOriginal(), self::modifiables($fiche, $attributs)));
    }

    /**
     * La fiche qu'un écran a lue et gardée (voir valeurs()) : ses attributs
     * modifiables, ou seulement ceux-là parmi eux.
     *
     * @param  class-string<Model>  $modele
     * @param  array<string, mixed>  $valeurs
     * @param  list<string>|null  $attributs
     */
    public static function depuis(string $modele, array $valeurs, ?array $attributs = null): self
    {
        return new self($modele, Arr::only($valeurs, self::modifiables(new $modele, $attributs)));
    }

    /**
     * Les attributs modifiables de la fiche ($fillable), ou ceux-là
     * seulement parmi eux.
     *
     * @param  list<string>|null  $attributs
     * @return list<string>
     */
    private static function modifiables(Model $fiche, ?array $attributs): array
    {
        return $attributs === null ? $fiche->getFillable() : array_values(array_intersect($fiche->getFillable(), $attributs));
    }

    /**
     * Ce que l'écran garde de sa lecture.
     *
     * @return array<string, mixed>
     */
    public function valeurs(): array
    {
        return $this->valeurs;
    }

    /**
     * Un modèle qui porte les valeurs lues, pour les afficher ou les
     * comparer ; il ne s'enregistre jamais.
     */
    public function fiche(): Model
    {
        $fiche = new $this->modele;
        $fiche->setRawAttributes($this->valeurs, true);

        return $fiche;
    }

    /**
     * Les attributs de la saisie dont la valeur diffère de la valeur lue,
     * avec leur valeur saisie : ce que la personne a corrigé. Un attribut
     * absent de la saisie, ou que la fiche lue n'a pas, n'est pas corrigé.
     *
     * @param  array<string, mixed>  $saisie
     * @return array<string, mixed>
     */
    public function corrections(array $saisie): array
    {
        $lue = $this->fiche();

        return array_filter(
            Arr::only($saisie, array_keys($this->valeurs)),
            fn (mixed $valeur, string $attribut): bool => ! self::egales($this->valeurSaisie($attribut, $valeur), $lue->getAttribute($attribut)),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Sur la fiche relue sous verrou par l'action qui écrit : refuse les
     * corrections dès que l'une porte sur un attribut qu'une autre
     * personne a changé depuis la lecture, sauf si elle lui a déjà donné
     * la valeur saisie.
     *
     * @param  array<string, mixed>  $corrections  voir corrections()
     *
     * @throws LogicException
     */
    public function refuserUneCorrectionPerimee(Model $relue, array $corrections, string $refus): void
    {
        $lue = $this->fiche();

        foreach ($corrections as $attribut => $valeur) {
            $enBase = $relue->getAttribute($attribut);

            if (! self::egales($enBase, $lue->getAttribute($attribut)) && ! self::egales($enBase, $this->valeurSaisie($attribut, $valeur))) {
                throw new LogicException($refus);
            }
        }
    }

    /**
     * La valeur saisie telle que le modèle la rendrait une fois écrite.
     */
    private function valeurSaisie(string $attribut, mixed $valeur): mixed
    {
        $fiche = $this->fiche();
        $fiche->setAttribute($attribut, $valeur);

        return $fiche->getAttribute($attribut);
    }

    /**
     * Deux valeurs telles que le modèle les rend : égales strictement, un
     * objet (un barème) par ses propriétés, une chaîne vide valant un
     * attribut vide.
     */
    private static function egales(mixed $une, mixed $autre): bool
    {
        $une = $une === '' ? null : $une;
        $autre = $autre === '' ? null : $autre;

        return is_object($une) && ! $une instanceof UnitEnum ? $une == $autre : $une === $autre;
    }
}
