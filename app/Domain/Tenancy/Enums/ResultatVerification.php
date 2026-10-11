<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Le résultat d'une vérification : concluante ; négative (entreprise
 * cessée, établissement fermé, identité non vérifiée, dirigeant
 * introuvable...) ; impossible parce que le service ne répondait pas, à
 * reprendre ; en attente du prestataire (une vérification d'identité
 * démarrée) ; à examiner par l'éditeur (un prestataire qui doute, un
 * signal de risque constaté). Seule une ligne concluante prouve quelque
 * chose (voir EvaluateurDeVerification).
 */
enum ResultatVerification: string implements HasLabel
{
    case Concluante = 'concluante';
    case Negative = 'negative';
    case Indisponible = 'indisponible';
    case EnAttente = 'en_attente';
    case AExaminer = 'a_examiner';

    public function getLabel(): string
    {
        return match ($this) {
            self::Concluante => 'Concluante',
            self::Negative => 'Négative',
            self::Indisponible => 'Service indisponible',
            self::EnAttente => 'En attente',
            self::AExaminer => 'À examiner par l\'éditeur',
        };
    }
}
