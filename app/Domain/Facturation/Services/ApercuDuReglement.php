<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tva\Services\CalculateurLigne;
use Illuminate\Support\Collection;

/**
 * Ce que l'écran d'un règlement en plusieurs parts montre avant de
 * l'enregistrer, dans l'ordre où l'enregistrement l'imputera (points 1 et
 * 23) : le crédit d'avoir du client, puis chaque bon d'avoir présenté dans
 * la part « Avoir », pour son crédit restant dans la limite de ce qui
 * reste, et ce qu'il reste à répartir entre les parts payées. L'écran
 * n'en calcule rien lui-même : il lit cet aperçu, comme l'enregistrement
 * relit les mêmes montants sous le verrou du registre (voir
 * EmettreFactureAction et EncaisserEnPlusieursModesAction), qui refuse un
 * règlement devenu faux entre-temps. Tous les montants sont en centimes.
 */
final readonly class ApercuDuReglement
{
    /**
     * @param  int  $total  à la caisse, le total TTC de la vente ; sur une facture, ce qui en restait dû
     * @param  int  $creditDuClient  à la caisse, le crédit du client qui s'imputera d'office ; sur une facture, celui qui y est déjà imputé
     * @param  array<string, int>  $bons  ce qu'imputera chaque bon accepté, par numéro
     * @param  array<string, string>  $bonsRefuses  le refus de chaque bon qui ne réglera rien, par numéro
     * @param  int  $resteARepartir  ce que les parts payées doivent couvrir : exactement à la caisse, au plus sur une facture
     */
    public function __construct(
        public int $total,
        public int $creditDuClient,
        public array $bons,
        public array $bonsRefuses,
        public int $resteARepartir,
    ) {}

    /**
     * Le règlement d'une vente à la caisse : du total TTC de la vente, le
     * crédit du client (jamais celui du client comptoir), puis les bons,
     * pour la vente de tout client.
     *
     * @param  int  $totalTtc  en centimes
     * @param  list<string|null>  $numerosDesBons  dans l'ordre saisi, un numéro encore vide ignoré
     */
    public static function aLaCaisse(?Client $client, int $totalTtc, array $numerosDesBons): self
    {
        $creditDuClient = min($client?->soldeCrediteurEnCentimes() ?? 0, max(0, $totalTtc));

        return self::avecLesBons($totalTtc, $creditDuClient, $totalTtc - $creditDuClient, $numerosDesBons, true, false);
    }

    /**
     * L'encaissement d'une facture ou d'un ticket déjà émis : ce qui en
     * reste dû, le crédit du client y étant déjà imputé, moins les bons,
     * que seul un ticket du client comptoir accepte (Q6), et qui n'y
     * règlent jamais seuls tout ce qui reste dû : un bon qui le couvrirait
     * est refusé (voir EncaisserEnPlusieursModesAction).
     *
     * @param  list<string|null>  $numerosDesBons  dans l'ordre saisi, un numéro encore vide ignoré
     */
    public static function surLaFacture(Facture $facture, array $numerosDesBons): self
    {
        $calculateur = app(CalculateurLigne::class);
        $somme = fn (Collection $montants): int => (int) $montants->sum(fn (string $montant): int => $calculateur->enCentiemes($montant));
        $creditImpute = $somme($facture->allocationsAvoir()->pluck('montant_alloue'));
        $resteDu = max(0, $calculateur->enCentiemes($facture->total_ttc) - $somme($facture->allocationsPaiement()->pluck('montant_alloue')) - $creditImpute);

        return self::avecLesBons($resteDu, $creditImpute, $resteDu, $numerosDesBons, $facture->client?->estComptoir() ?? false, true);
    }

    /**
     * @param  list<string|null>  $numerosDesBons
     * @param  bool  $avecUnePartPayee  les bons laissent une part à payer, qui les signe
     */
    private static function avecLesBons(int $total, int $creditDuClient, int $reste, array $numerosDesBons, bool $bonsAcceptes, bool $avecUnePartPayee): self
    {
        $bons = [];
        $refuses = [];

        foreach ($numerosDesBons as $numero) {
            $numero = mb_strtoupper(trim((string) $numero));

            if ($numero === '') {
                continue;
            }

            if (isset($bons[$numero]) || isset($refuses[$numero])) {
                $refuses[$numero] = PartDeReglement::BON_EN_DOUBLE;

                continue;
            }

            if (! $bonsAcceptes) {
                $refuses[$numero] = Avoir::BON_D_AVOIR_HORS_DE_LA_CAISSE;

                continue;
            }

            $credit = Avoir::query()->bonDAvoir($numero)->first()?->creditEnCentimes() ?? 0;
            $montant = min($credit, max(0, $reste));

            if ($credit <= 0) {
                $refuses[$numero] = Avoir::BON_D_AVOIR_INTROUVABLE;
            } elseif ($montant <= 0) {
                $refuses[$numero] = sprintf(Avoir::BON_D_AVOIR_SANS_EMPLOI, $numero);
            } elseif ($avecUnePartPayee && $montant >= $reste) {
                $refuses[$numero] = Avoir::BON_D_AVOIR_SANS_PART_PAYEE;
            } else {
                $bons[$numero] = $montant;
                $reste -= $montant;
            }
        }

        return new self($total, $creditDuClient, $bons, $refuses, $reste);
    }
}
