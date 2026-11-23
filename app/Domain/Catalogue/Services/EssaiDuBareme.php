<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Services;

use App\Domain\Catalogue\Enums\ArrondiDuPrixDeVente;
use App\Domain\Facturation\Services\CalculateurLigne;
use InvalidArgumentException;

/**
 * La lecture de « Essayer le barème » (Paramètres > Prix de vente) : le
 * barème en cours de saisie appliqué à un prix d'achat d'essai, comme le
 * fera une facture d'achat -- le prix de vente avant et après l'arrondi, et
 * le détail du calcul en phrases grises. Rien ne s'enregistre.
 */
final class EssaiDuBareme
{
    /**
     * Ce que la page montre quand l'essai n'a rien à calculer.
     *
     * @return array{avant: string, apres: string, lignes: list<array{texte: string, grise: bool}>}
     */
    public static function illisible(): array
    {
        return [
            'avant' => '—',
            'apres' => '—',
            'lignes' => [['texte' => 'Saisissez un prix d\'achat HT, une TVA et un barème lisibles : des montants au centime (au dix-millième pour le prix d\'achat), sans lettre.', 'grise' => true]],
        ];
    }

    /**
     * Le barème appliqué à un prix d'achat d'essai.
     *
     * @param  int  $prixAchatHt  en dix-millièmes d'euro
     * @param  int  $tauxTva  en centièmes de point
     * @return array{avant: string, apres: string, lignes: list<array{texte: string, grise: bool}>}
     *
     * @throws InvalidArgumentException
     */
    public static function lire(BaremePrixDeVente $bareme, int $prixAchatHt, int $tauxTva): array
    {
        $calculateur = app(CalculateurLigne::class);
        $prix = $bareme->prixDeVente($prixAchatHt, $tauxTva);

        if ($prix === null) {
            return [
                'avant' => '—',
                'apres' => '—',
                'lignes' => [['texte' => 'Aucun bénéfice prévu à ce prix d\'achat : le prix de vente se saisit à la main.', 'grise' => false]],
            ];
        }

        $exact = $calculateur->prixTtcExact($prixAchatHt + $prix['benefice'] * 100, $tauxTva);
        $palier = $bareme->palierPour($prixAchatHt);
        $beneficeReel = $calculateur->beneficeUnitaire($calculateur->prixUnitaireHtAffiche($prix['prix_vente_ttc'], $tauxTva), $prixAchatHt);
        $lignes = [[
            'texte' => '('.self::enEurosAuDixMillieme($prixAchatHt).' + '.$calculateur->enEuros($prix['benefice']).') × (1 + '.CalculateurLigne::tauxEnTexte($tauxTva).') = '.$calculateur->enEuros($prix['prix_cible_ttc']),
            'grise' => false,
        ]];

        if ($bareme->arrondi !== ArrondiDuPrixDeVente::Aucun && $exact % 1_000_000 !== 0) {
            $lignes[] = ['texte' => 'Le prix exact est '.ExemplesDArrondi::enEurosExact($exact).' ; l\'arrondi se fait sur ce prix exact, pas sur '.$calculateur->enEuros($prix['prix_cible_ttc']).'.', 'grise' => true];
        }

        $lignes[] = ['texte' => 'Bénéfice du barème : '.$calculateur->enEuros($prix['benefice']).' HT, '.($palier === null ? 'au-delà du dernier palier' : 'palier « inférieur à '.$calculateur->enEuros($palier).' »').'.', 'grise' => true];
        $lignes[] = ['texte' => 'Bénéfice réel '.$calculateur->enEuros($beneficeReel).' HT (visé '.$calculateur->enEuros($prix['benefice']).').', 'grise' => true];

        if ($prix['laisse_tel_quel']) {
            $lignes[] = ['texte' => 'Pas d\'arrondi à ce prix : '.$bareme->arrondi->raisonDuPrixLaisseTel().'.', 'grise' => true];
        }

        return [
            'avant' => $calculateur->enEuros($prix['prix_cible_ttc']),
            'apres' => $calculateur->enEuros($prix['prix_vente_ttc']),
            'lignes' => $lignes,
        ];
    }

    /**
     * Un prix d'achat au dix-millième, sans zéro inutile au-delà du
     * centime : « 41,70 € », « 0,1234 € ».
     */
    private static function enEurosAuDixMillieme(int $dixMilliemes): string
    {
        $decimales = str_pad((string) ($dixMilliemes % 10000), 4, '0', STR_PAD_LEFT);

        return number_format(intdiv($dixMilliemes, 10000), 0, ',', ' ').','.(str_ends_with($decimales, '00') ? substr($decimales, 0, 2) : $decimales).' €';
    }
}
