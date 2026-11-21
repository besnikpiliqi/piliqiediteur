<?php

namespace Tests\Fixtures\EcartDesPrixDAchat;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Domain\TableauDeBord\Support\Mois;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sème, par les vraies actions, les achats du jeu de la maquette de la carte
 * « Écart des prix d'achat » (verite.json, `jeu`) dans le magasin actif :
 * les 12 produits de la maquette, dans son ordre, par un inventaire
 * d'ouverture validé (qui fixe l'ordre de création des produits et ne compte
 * pas dans la carte), puis les 122 achats, à raison d'une facture d'achat par
 * jour distinct (62 factures), chacune portant les lignes des produits
 * achetés ce jour-là, créée puis validée au nom de l'auteur donné, avec un
 * jeton de demande répétée. Les factures se valident dans l'ordre des jours :
 * le rang de validation suit le calendrier.
 *
 * Les 12 mois de la maquette sont « décembre 2025 » à « novembre 2026 », la
 * période « 12 derniers mois » du 13/11/2026 : k = 0 est décembre 2025. Les
 * prix `c` sont des centimes écrits en euros HT. L'aide relit ce qu'elle a
 * semé et refuse de rendre la main si les lignes ne sont pas 122 ou si les
 * jours distincts ne sont pas 62.
 */
final class AchatsDeLaMaquette
{
    public const LIGNES = 122;

    public const JOURS = 62;

    public const PRODUITS = 12;

    /**
     * Le magasin actif (voir TenantContext) reçoit les produits et les
     * achats ; l'auteur peut y valider les achats et y créer des produits.
     *
     * @return array<string, int> l'identifiant de chaque produit, par nom, dans l'ordre de la maquette
     */
    public static function semer(User $auteur, Fournisseur $fournisseur): array
    {
        $jeu = LectureDesFixtures::verite()['jeu']['produits'];
        $mois = LectureDesFixtures::moisDeLaMaquette();
        $lignes = [];

        foreach ($jeu as $rang => $produit) {
            $lignes[] = ['produit' => ['nom' => $produit['nom'], 'reference' => sprintf('MAQ-%02d', $rang + 1), 'prix_vente_ttc' => '29.90', 'taux_tva' => '20'], 'quantite' => 1, 'cout_unitaire_ht' => '1.00'];
        }

        app(ValiderInventaireOuvertureAction::class)->handle($auteur, app(CreerInventaireOuvertureAction::class)->handle($auteur, $lignes), (string) Str::uuid());

        $identifiants = [];

        foreach ($jeu as $produit) {
            $identifiants[$produit['nom']] = (int) Produit::query()->where('nom', $produit['nom'])->sole()->getKey();
        }

        $parJour = [];

        foreach ($jeu as $produit) {
            foreach ($produit['achats'] as $achat) {
                $parJour[sprintf('%s-%02d', $mois[$achat['k']], $achat['j'])][] = [
                    'produit_id' => $identifiants[$produit['nom']],
                    'quantite' => $achat['q'],
                    'prix_unitaire_ht' => sprintf('%d.%02d', intdiv($achat['c'], 100), $achat['c'] % 100),
                    'taux_tva' => 20,
                ];
            }
        }

        ksort($parJour, SORT_STRING);

        foreach ($parJour as $jour => $achats) {
            app(ValiderFactureAchatAction::class)->handle($auteur, app(CreerFactureAchatAction::class)->handle($auteur, $fournisseur, $achats, ['date_facture' => $jour]), (string) Str::uuid());
        }

        self::verifier();

        return $identifiants;
    }

    /**
     * Les mois « AAAA-MM » de la maquette, k = 0 étant décembre 2025.
     *
     * @return list<string>
     */
    public static function mois(): array
    {
        return Mois::du('2025-12', '2026-11');
    }

    /**
     * Ce que la base porte : 122 lignes d'achat validées de 62 jours
     * distincts, de 62 factures validées.
     */
    private static function verifier(): void
    {
        $magasin = app(TenantContext::class)->magasin()->getKey();
        $lignes = DB::table('lignes_achat')
            ->join('factures_achat', fn (JoinClause $jointure): JoinClause => $jointure->on('factures_achat.magasin_id', '=', 'lignes_achat.magasin_id')->on('factures_achat.id', '=', 'lignes_achat.facture_achat_id'))
            ->where('factures_achat.magasin_id', $magasin)
            ->where('factures_achat.statut', 'validee')
            ->count();
        $factures = DB::table('factures_achat')->where('magasin_id', $magasin)->where('statut', 'validee');
        $jours = (clone $factures)->distinct()->count('date_facture');
        $nombre = $factures->count();

        if ($lignes !== self::LIGNES || $jours !== self::JOURS || $nombre !== self::JOURS) {
            throw new RuntimeException(sprintf('Les achats de la maquette sont mal semés : %d lignes (attendu %d), %d jours distincts (attendu %d) et %d factures (attendu %d).', $lignes, self::LIGNES, $jours, self::JOURS, $nombre, self::JOURS));
        }
    }
}
