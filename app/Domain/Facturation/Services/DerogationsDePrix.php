<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Les remises et les prix dérogatoires d'une vente (R2) : une ligne
 * remisée, une ligne de produit à un autre prix TTC que celui de sa
 * fiche, une ligne de service à un autre prix ou un autre taux que son
 * prix de référence (voir Service::estASonPrixDeReference()), ou d'un
 * service qui n'en a pas. Seule une personne autorisée à « accorder des
 * remises et fixer le prix d'un service » dans le magasin en introduit
 * une (voir PermissionRegistry::VENTE_REMISES_ET_PRIX) ; sans elle, une
 * ligne de produit prend le prix de sa fiche, une ligne de service son
 * prix de référence, et un service sans prix de référence ne se vend pas.
 *
 * Une ligne qui porte une dérogation et que l'enregistrement reprend
 * strictement telle quelle (même article, même quantité, même prix, même
 * taux, même remise) garde l'accord déjà donné : modifier une autre ligne
 * d'un brouillon remisé par un responsable reste permis. Changer le
 * produit, le service, la quantité, le prix, le taux ou la remise d'une
 * ligne dérogatoire en introduit une nouvelle. La conversion d'un devis,
 * qui recopie ses lignes sans les reconstruire (voir
 * CreerFactureDepuisDevisAction), garde de même l'accord donné au devis.
 *
 * Contrôlé par les actions qui enregistrent les lignes d'une vente (voir
 * CreerFactureAction, par laquelle passe aussi une vente à la caisse,
 * ModifierFactureAction, CreerDevisAction et ModifierDevisAction), sur le
 * compte relu en base (voir LecteurDesDroits), quel que soit ce que
 * l'écran a montré ou désactivé.
 */
class DerogationsDePrix
{
    public const AUTORISATION = 'demande l\'autorisation « accorder des remises et fixer le prix d\'un service »';

    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * Refuse les lignes qui introduisent une dérogation quand le compte n'a
     * pas l'autorisation dans le magasin actif.
     *
     * @param  iterable<array<string, mixed>>  $lignesAvant  les lignes déjà enregistrées du document, aucune pour un nouveau
     * @param  array<array-key, array<string, mixed>>  $lignesApres  les lignes construites à enregistrer (voir ConstructeurLigneDocument)
     *
     * @throws AuthorizationException
     */
    public function exiger(User $compte, iterable $lignesAvant, array $lignesApres, ContexteDeVente $contexte): void
    {
        $introduites = $this->introduites($lignesAvant, $lignesApres, $contexte);

        if ($introduites !== [] && Gate::forUser($compte)->denies(PermissionRegistry::VENTE_REMISES_ET_PRIX)) {
            throw new AuthorizationException(implode(' ', $introduites));
        }
    }

    /**
     * Les dérogations que les lignes à enregistrer introduisent, une
     * phrase pour chacune, par clé de ligne, dans l'ordre des lignes ;
     * aucune quand chaque ligne dérogatoire reprend telle quelle une ligne
     * déjà enregistrée. L'écran les lit aussi, pour remettre au prix du
     * catalogue les lignes qu'une personne sans l'autorisation ne pourrait
     * pas enregistrer (voir AjouteDesLignesDeVente::lignesAuxPrixPermis()).
     *
     * @param  iterable<array<string, mixed>>  $lignesAvant
     * @param  array<array-key, array<string, mixed>>  $lignesApres
     * @return array<array-key, string>
     */
    public function introduites(iterable $lignesAvant, array $lignesApres, ContexteDeVente $contexte): array
    {
        $dejaAccordees = [];

        foreach ($lignesAvant as $ligne) {
            $cle = $this->cle($ligne);
            $dejaAccordees[$cle] = ($dejaAccordees[$cle] ?? 0) + 1;
        }

        $produits = Produit::withTrashed()->whereKey(array_filter(array_column($lignesApres, 'produit_id')))->get()->keyBy('id');
        $services = Service::query()->whereKey(array_filter(array_column($lignesApres, 'service_id')))->get()->keyBy('id');
        $introduites = [];

        foreach ($lignesApres as $cleDeLigne => $ligne) {
            $derogation = $this->derogation($ligne, $produits->get((int) $ligne['produit_id']), $services->get((int) $ligne['service_id']), $contexte);

            if ($derogation === null) {
                continue;
            }

            $cle = $this->cle($ligne);

            if (($dejaAccordees[$cle] ?? 0) > 0) {
                $dejaAccordees[$cle]--;

                continue;
            }

            $introduites[$cleDeLigne] = $derogation;
        }

        return $introduites;
    }

    /**
     * La dérogation que porte la ligne, en une phrase, ou null.
     *
     * @param  array<string, mixed>  $ligne
     */
    private function derogation(array $ligne, ?Produit $produit, ?Service $service, ContexteDeVente $contexte): ?string
    {
        $article = $produit?->nom ?? $service?->nom ?? (string) ($ligne['description'] ?? '');
        $prix = $this->calculateur->arrondirAuCentime((float) $ligne['prix_unitaire_ttc']);

        if ($service !== null && ! $service->aUnPrixDeReference()) {
            return "Le prix du service « {$article} » n'est pas fixé : demandez à un responsable.";
        }

        if ((float) ($ligne['remise_pourcentage'] ?? 0) !== 0.0) {
            return 'Accorder une remise de '.self::pourcentage($ligne['remise_pourcentage'])." % sur « {$article} » ".self::AUTORISATION.'.';
        }

        if ($produit !== null && $prix !== $this->calculateur->arrondirAuCentime((float) $produit->prix_vente_ttc)) {
            return "Vendre « {$article} » à ".self::euros($prix).' au lieu de '.self::euros((float) $produit->prix_vente_ttc).', le prix de sa fiche, '.self::AUTORISATION.'.';
        }

        if ($service === null || $service->estASonPrixDeReference($prix, (float) $ligne['taux_tva_du_prix'], $contexte)) {
            return null;
        }

        // Le prix de la ligne et celui de référence tels que l'écran les
        // montre : sans TVA hors de la TVA française.
        $prixMontre = $this->calculateur->arrondirAuCentime((float) ($contexte->retireLaTvaDuPrix() ? $ligne['prix_unitaire_ht'] : $ligne['prix_unitaire_ttc']));
        $prixDeReference = $this->calculateur->arrondirAuCentime((float) $service->prixDeReferenceDans($contexte));

        if ($prixMontre !== $prixDeReference) {
            return "Vendre « {$article} » à ".self::euros($prixMontre).' au lieu de '.self::euros($prixDeReference).', son prix de référence, '.self::AUTORISATION.'.';
        }

        return "Vendre « {$article} » à ".self::pourcentage($ligne['taux_tva_du_prix']).' % de TVA au lieu de '.self::pourcentage($service->taux_tva).' %, le taux de son prix de référence, '.self::AUTORISATION.'.';
    }

    /**
     * Ce qui distingue une ligne d'une autre pour l'accord donné : son
     * article, sa quantité, son prix, son taux et sa remise.
     *
     * @param  array<string, mixed>  $ligne
     */
    private function cle(array $ligne): string
    {
        return implode('|', [
            (int) ($ligne['produit_id'] ?? 0),
            (int) ($ligne['service_id'] ?? 0),
            (int) $ligne['quantite'],
            number_format((float) $ligne['prix_unitaire_ttc'], 2, '.', ''),
            number_format((float) ($ligne['taux_tva_du_prix'] ?? 0), 2, '.', ''),
            number_format((float) ($ligne['remise_pourcentage'] ?? 0), 2, '.', ''),
        ]);
    }

    private static function euros(float $montant): string
    {
        return number_format($montant, 2, ',', ' ').' €';
    }

    private static function pourcentage(mixed $pourcentage): string
    {
        return str_replace('.', ',', (string) (float) $pourcentage);
    }
}
