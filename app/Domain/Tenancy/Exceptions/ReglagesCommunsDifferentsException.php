<?php

namespace App\Domain\Tenancy\Exceptions;

use App\Domain\Tenancy\Services\ReglagesCommunsDeLaSociete;
use LogicException;

/**
 * Un magasin dont les réglages communs à sa société ne sont pas ceux du
 * magasin principal de son SIREN (J2, K5, voir ReglagesCommunsDeLaSociete)
 * : il ne les enregistre pas, et il n'émet ni facture ni ticket. Le
 * message dit chaque réglage qui diffère et la valeur du magasin principal
 * à prendre, jamais le nom ni aucune autre donnée de ce magasin.
 */
class ReglagesCommunsDifferentsException extends LogicException
{
    /**
     * @param  array<string, string|null>  $ecarts  réglage => valeur du magasin principal, telle que l'écran la montre (null : non renseignée)
     */
    private function __construct(public readonly array $ecarts, string $message)
    {
        parent::__construct($message);
    }

    /**
     * Le refus d'une facture ou d'un ticket (voir EmettreFactureAction),
     * qui dit aussi où se change chaque réglage.
     *
     * @param  array<string, string|null>  $ecarts
     */
    public static function aLEmission(string $siren, array $ecarts): self
    {
        return new self($ecarts, "Ce magasin n'émet ni facture ni ticket tant que ses réglages communs ne sont pas ceux du magasin principal de votre société (SIREN {$siren}) ; à prendre : ".ReglagesCommunsDeLaSociete::liste($ecarts, avecLaPage: true).'.');
    }

    /**
     * Le refus d'enregistrer, par n'importe quel écran ou commande, une
     * valeur qui n'est pas celle du magasin principal (voir
     * Magasin::booted()).
     *
     * @param  array<string, string|null>  $ecarts
     */
    public static function aLEnregistrement(string $siren, array $ecarts): self
    {
        return new self($ecarts, "Ces réglages sont communs à tous les magasins de votre société (SIREN {$siren}) et fixés par son magasin principal ; à prendre : ".ReglagesCommunsDeLaSociete::liste($ecarts).'.');
    }
}
