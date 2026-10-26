<?php

namespace App\Domain\Contacts\Support;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Fournisseur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * Une fiche du magasin que l'index unique de sa table arrête au moment
 * de l'écrire : le SIRET d'un fournisseur (magasin_id, siret) ou le nom
 * d'un service (magasin_id, nom_normalise) qu'une autre personne vient
 * d'enregistrer dans le magasin, entre la validation du formulaire et
 * l'écriture. Sa création (voir CreerUneFicheAction) comme sa
 * modification (voir ModifierUneFicheAction) le refusent comme la
 * validation du formulaire, avec un message, jamais par l'erreur de la
 * base. La référence d'un produit se refuse dans son modèle (voir
 * Produit::refuserUneReferencePriseEntreTemps()), et toute autre
 * violation remonte telle quelle.
 */
final class DoublonDUneFiche
{
    public const SIRET_PRIS = 'Ce SIRET est déjà celui d\'un autre fournisseur du magasin, peut-être archivé : affichez les fournisseurs archivés pour le restaurer.';

    public const NOM_DE_SERVICE_PRIS = 'Un service de ce nom existe déjà dans le magasin : une autre personne vient de l\'enregistrer.';

    /**
     * Le refus à lever à la place de la violation de l'index unique, pour
     * une fiche de ce modèle.
     *
     * @param  class-string<Model>  $modele
     */
    public static function refus(string $modele, UniqueConstraintViolationException $doublon): LogicException|UniqueConstraintViolationException
    {
        return match ($modele) {
            Fournisseur::class => new LogicException(self::SIRET_PRIS, previous: $doublon),
            Service::class => new LogicException(self::NOM_DE_SERVICE_PRIS, previous: $doublon),
            default => $doublon,
        };
    }
}
