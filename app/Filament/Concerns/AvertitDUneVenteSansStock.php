<?php

namespace App\Filament\Concerns;

use App\Domain\Stock\Exceptions\ConfirmationVenteSansStockRequiseException;
use App\Domain\Stock\Services\ConfirmationVenteSansStock;
use App\Domain\Stock\Services\ManqueDeStock;
use Illuminate\Support\HtmlString;

/**
 * L'avertissement d'une vente au-delà du stock (R1), que renvoie
 * l'émission à une personne autorisée à vendre sans stock (voir
 * ConfirmationVenteSansStockRequiseException) : la fenêtre qui le montre
 * reçoit en arguments ce qu'elle affiche et la confirmation qu'elle
 * renverra (voir Caisse et FactureResource). Le serveur revérifie tout à
 * la confirmation : l'autorisation de la personne et le stock du moment.
 */
trait AvertitDUneVenteSansStock
{
    /**
     * Les arguments de la fenêtre : une phrase par manque, et la
     * confirmation qui les accepte.
     *
     * @return array{avertissements: list<string>, confirmation: array<int, int>}
     */
    protected static function argumentsDeLAvertissement(ConfirmationVenteSansStockRequiseException $avertissement): array
    {
        return [
            'avertissements' => array_map(fn (ManqueDeStock $manque): string => $manque->avertissement().'.', $avertissement->manques),
            'confirmation' => ConfirmationVenteSansStock::de($avertissement->manques)->enTableau(),
        ];
    }

    /**
     * La confirmation que renvoie la fenêtre, null si elle n'en porte
     * aucune.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected static function confirmationDesArguments(array $arguments): ?ConfirmationVenteSansStock
    {
        return is_array($arguments['confirmation'] ?? null) ? ConfirmationVenteSansStock::depuisTableau($arguments['confirmation']) : null;
    }

    /**
     * Le texte de la fenêtre : chaque manque, puis la question.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected static function descriptionDeLAvertissement(array $arguments): HtmlString
    {
        return new HtmlString(collect($arguments['avertissements'] ?? [])
            ->map(fn (mixed $avertissement): string => e((string) $avertissement))
            ->push(e('Confirmer la vente ? La quantité négative restera à régulariser par un achat, une réception ou un inventaire.'))
            ->implode('<br>'));
    }
}
