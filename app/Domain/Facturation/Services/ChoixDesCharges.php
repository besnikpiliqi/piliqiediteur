<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Tenancy\Models\Societe;

/**
 * Ce que le téléchargement par période choisit parmi les charges de la
 * société (décision de l'éditeur du 15/11/2026, lot DATEOP ; voir
 * DocumentsDeLaPeriode) : les charges de « Toute la société » de cette
 * société, sans magasin, en plus de celles des magasins choisis ($deLaSociete,
 * null pour aucune), et toutes les charges, quel que soit leur état, ou
 * seulement les validées, le choix par défaut ($toutes).
 */
final readonly class ChoixDesCharges
{
    public function __construct(
        public ?Societe $deLaSociete = null,
        public bool $toutes = false,
    ) {}
}
