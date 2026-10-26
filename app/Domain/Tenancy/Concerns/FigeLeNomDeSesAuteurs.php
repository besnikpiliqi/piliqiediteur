<?php

namespace App\Domain\Tenancy\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * L'historique garde le nom de chaque personne tel qu'il était au moment
 * de chaque opération : une ligne d'historique qui désigne une personne
 * (son auteur, le vendeur d'une caisse...) enregistre aussi son nom, dans
 * une colonne sœur, posée depuis le nom que la base donne pour ce compte,
 * dans la même transaction, chaque fois que la colonne de la personne est
 * posée ou change. Les écrans, les exports et les notifications lisent ce
 * nom figé, jamais le nom actuel du compte : un changement de nom dans le
 * profil, un compte refusé ou rattaché à une autre société ne change rien
 * à ce que montre l'historique. Le nom figé ne se modifie jamais seul.
 *
 * Chaque modèle déclare ses couples (voir colonnesAuNomFige()).
 */
trait FigeLeNomDeSesAuteurs
{
    /**
     * @return array<string, string> la colonne qui désigne une personne => la colonne de son nom figé
     */
    abstract protected static function colonnesAuNomFige(): array;

    protected static function bootFigeLeNomDeSesAuteurs(): void
    {
        static::saving(static function (Model $modele): void {
            foreach (static::colonnesAuNomFige() as $colonne => $colonneDuNom) {
                if (! $modele->exists || $modele->isDirty($colonne)) {
                    $personne = $modele->getAttribute($colonne);
                    $modele->setAttribute($colonneDuNom, $personne === null ? null : User::query()->whereKey($personne)->value('name'));

                    continue;
                }

                if ($modele->isDirty($colonneDuNom)) {
                    throw new LogicException('Le nom figé d\'une personne dans l\'historique ne se modifie jamais.');
                }
            }
        });
    }
}
