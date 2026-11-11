<?php

declare(strict_types=1);

namespace App\Domain\Facturation\Services;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
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
     * @param  array<array-key, array<string, mixed>>  $lignesAvant  les lignes déjà enregistrées du document, aucune pour un nouveau
     * @param  array<array-key, array<string, mixed>>  $lignesApres  les lignes construites à enregistrer (voir ConstructeurLigneDocument)
     *
     * @throws AuthorizationException
     */
    public function exiger(User $compte, array $lignesAvant, array $lignesApres, ContexteDeVente $contexte): void
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
     * @param  array<array-key, array<string, mixed>>  $lignesAvant
     * @param  array<array-key, array<string, mixed>>  $lignesApres
     * @return array<array-key, string>
     */
    public function introduites(array $lignesAvant, array $lignesApres, ContexteDeVente $contexte): array
    {
        // Combien de lignes déjà enregistrées portent chaque dérogation :
        // chacune n'en couvre qu'une.
        $dejaAccordees = array_count_values(array_map($this->cle(...), $lignesAvant));

        $produits = Produit::withTrashed()->whereKey(array_column($lignesApres, 'produit_id'))->get()->keyBy('id');
        $services = Service::query()->whereKey(array_column($lignesApres, 'service_id'))->get()->keyBy('id');
        $introduites = [];

        foreach ($lignesApres as $cleDeLigne => $ligne) {
            $article = $ligne['produit_id'] !== null ? $produits->get(ValeurSimple::enEntier($ligne['produit_id'])) : $services->get(ValeurSimple::enEntier($ligne['service_id']));
            $derogation = $this->derogation($ligne, ValeurRequise::de($article, 'l\'article du catalogue de la ligne'), $contexte);

            if ($derogation === null) {
                continue;
            }

            $cle = $this->cle($ligne);

            if (! empty($dejaAccordees[$cle])) {
                $dejaAccordees[$cle]--;

                continue;
            }

            $introduites[$cleDeLigne] = $derogation;
        }

        return $introduites;
    }

    /**
     * La dérogation que porte la ligne de ce produit ou de ce service (voir
     * ConstructeurLigneDocument, qui ne construit que des lignes d'un
     * article du catalogue), en une phrase, ou null : ses prix, son taux et
     * sa remise, écrits comme leurs colonnes, se comparent en centimes et
     * en centièmes de point.
     *
     * @param  array<string, mixed>  $ligne
     */
    private function derogation(array $ligne, Produit|Service $article, ContexteDeVente $contexte): ?string
    {
        $prix = $this->calculateur->enCentiemes($ligne['prix_unitaire_ttc']);

        if ($article instanceof Service && ! $article->aUnPrixDeReference()) {
            return "Le prix du service « {$article->nom} » n'est pas fixé : demandez à un responsable.";
        }

        if ($this->calculateur->enCentiemes($ligne['remise_pourcentage']) !== 0) {
            return 'Accorder une remise de '.self::pourcentage($ligne['remise_pourcentage'])." % sur « {$article->nom} » ".self::AUTORISATION.'.';
        }

        if ($article instanceof Produit) {
            return $prix === $this->calculateur->enCentiemes($article->prix_vente_ttc)
                ? null
                : "Vendre « {$article->nom} » à ".$this->prixUnitaire(ValeursLues::texte($ligne['prix_unitaire_ttc'])).' au lieu de '.$this->prixUnitaire($article->prix_vente_ttc).', le prix de sa fiche, '.self::AUTORISATION.'.';
        }

        if ($article->estASonPrixDeReference($prix, $this->calculateur->enCentiemes($ligne['taux_tva_du_prix']), $contexte)) {
            return null;
        }

        // Le prix de la ligne et celui de référence tels que l'écran les
        // montre : sans TVA hors de la TVA française, où un service vendu à
        // un autre prix que sa référence a pour PU TTC ce prix saisi, sans
        // TVA (voir ConstructeurLigneDocument).
        $prixDeReference = ValeurRequise::de($article->prixDeReferenceDans($contexte), 'le prix de référence du service');

        if ($prix !== $this->calculateur->enCentiemes($prixDeReference)) {
            return "Vendre « {$article->nom} » à ".$this->prixUnitaire(ValeursLues::texte($ligne['prix_unitaire_ttc'])).' au lieu de '.$this->prixUnitaire($prixDeReference).', son prix de référence, '.self::AUTORISATION.'.';
        }

        return "Vendre « {$article->nom} » à ".self::pourcentage($ligne['taux_tva_du_prix']).' % de TVA au lieu de '.self::pourcentage($article->taux_tva).' %, le taux de son prix de référence, '.self::AUTORISATION.'.';
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
            ValeurSimple::enTexte($ligne['produit_id']),
            ValeurSimple::enTexte($ligne['service_id']),
            ValeurSimple::enTexte($ligne['quantite']),
            $this->calculateur->enCentiemes($ligne['prix_unitaire_ttc']),
            $this->calculateur->enCentiemes($ligne['taux_tva_du_prix']),
            $this->calculateur->enCentiemes($ligne['remise_pourcentage']),
        ]);
    }

    /**
     * Un prix écrit comme sa colonne (« 1234.50 »), tel que le montre tout
     * prix unitaire en lecture (voir CalculateurLigne::prixUnitaireAffiche()) :
     * « 1 234,50 € ».
     */
    private function prixUnitaire(string $prix): string
    {
        return $this->calculateur->prixUnitaireAffiche($this->calculateur->enMillioniemes($prix));
    }

    private static function pourcentage(mixed $pourcentage): string
    {
        return str_replace('.', ',', (string) (float) ValeurSimple::simple($pourcentage));
    }
}
