<?php

namespace Tests\Unit;

use App\Domain\Facturation\Services\CalculateurLigne;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

/**
 * Le PU HT affiché d'une vente n'a qu'un calcul (décision de l'éditeur du
 * 14/11/2026, lot DEMO-V) : son PU TTC moins la TVA d'un article,
 * arrondie une fois au centime (voir
 * CalculateurLigne::prixUnitaireHtAffiche()), que la fiche du produit, la
 * caisse, les lignes des formulaires de vente, les fiches et les PDF des
 * documents et la page publique du devis appellent tous. Ce test refuse
 * tout autre arrondi : le calculateur n'offre aucun autre calcul public
 * d'un HT depuis un TTC à montrer, et aucun écran, aucun PDF, aucune page
 * ni aucun courriel ne met en forme le PU HT enregistré d'une ligne de
 * vente (au millionième, celui de la facture électronique) avec une autre
 * fonction. Le PU HT d'un achat, que le fournisseur écrit, s'affiche tel
 * qu'il est enregistré : ses fichiers ne sont pas lus ici. Ce test lit les
 * fichiers, sans base de données.
 */
class PuHtAfficheDesVentesTest extends TestCase
{
    /**
     * Les mises en forme d'un prix unitaire enregistré qu'aucune ligne qui
     * lit le PU HT d'une vente ne doit appeler.
     *
     * @var list<string>
     */
    private const AUTRES_ARRONDIS = ['prixUnitaireExact(', 'prixUnitaireAffiche(', 'prixUnitaireDansUnChamp(', 'prixUnitaireExactEnDecimal(', 'prixUnitaireAuCentime(', 'number_format(', 'Number::format(', 'Number::currency('];

    /**
     * Les dossiers et fichiers qui montrent des ventes.
     *
     * @var list<string>
     */
    private const AFFICHAGES = ['app/Filament', 'app/Http', 'app/Livewire', 'app/Mail', 'app/Notifications', 'resources/views', 'app/Domain/Facturation/Services/TableauDesLignes.php', 'app/Domain/Facturation/Services/MentionsFiscales.php'];

    /**
     * Le calculateur n'offre, à côté des deux calculs du PU HT enregistré
     * au millionième, qui ne s'affichent pas (depuis le PU TTC, voir
     * prixUnitaireHtExact(), et depuis le HT d'une ligne rectificative,
     * voir prixUnitaireHtDuMontant()), que le calcul du PU HT affiché et
     * son écriture en euros : aucun autre PU HT public.
     */
    public function test_le_calculateur_n_offre_qu_un_calcul_du_pu_ht_affiche_depuis_le_pu_ttc(): void
    {
        $calculsDuHtDepuisLeTtc = array_values(array_filter(
            array_map(fn (ReflectionMethod $methode): string => $methode->getName(), (new ReflectionClass(CalculateurLigne::class))->getMethods(ReflectionMethod::IS_PUBLIC)),
            fn (string $nom): bool => preg_match('/^(prixUnitaireHt|htDepuis|prixUnitaireHtDepuis)/i', $nom) === 1,
        ));
        sort($calculsDuHtDepuisLeTtc);

        $this->assertSame(['prixUnitaireHtAffiche', 'prixUnitaireHtAfficheEnEuros', 'prixUnitaireHtDuMontant', 'prixUnitaireHtExact'], $calculsDuHtDepuisLeTtc);
    }

    public function test_aucun_ecran_ni_aucun_pdf_ne_met_en_forme_autrement_le_pu_ht_d_une_vente(): void
    {
        $lignesLues = 0;
        $fautes = [];

        foreach (self::fichiersDesVentes() as $fichier) {
            foreach (file($fichier, FILE_IGNORE_NEW_LINES) ?: [] as $numero => $ligne) {
                if (! str_contains($ligne, 'prix_unitaire_ht')) {
                    continue;
                }

                $lignesLues++;

                if (self::arrondiInterdit($ligne)) {
                    $fautes[] = $fichier.':'.($numero + 1).' : '.trim($ligne);
                }
            }
        }

        $this->assertGreaterThanOrEqual(4, $lignesLues);
        $this->assertSame([], $fautes);
    }

    /**
     * Le contrôle reconnaît l'ancienne mise en forme du PU HT d'une vente,
     * tel qu'il est enregistré, et laisse passer le calcul du PU HT d'un
     * article.
     */
    public function test_le_controle_refuse_l_ancienne_mise_en_forme_du_pu_ht_et_garde_celle_d_un_article(): void
    {
        $this->assertTrue(self::arrondiInterdit('? $calculateur->prixUnitaireExact($calculateur->enMillioniemes($ligne->prix_unitaire_ht))'));
        $this->assertTrue(self::arrondiInterdit("'prix_unitaire_ht' => \$calculateur->prixUnitaireDansUnChamp(\$millioniemes),"));
        $this->assertFalse(self::arrondiInterdit("'prix_unitaire_ht' => self::puHtDansLeChamp(\$calculateur->enCentiemes(\$ligne->prix_unitaire_ttc), \$calculateur->enCentiemes(\$ligne->taux_tva_du_prix)),"));
    }

    private static function arrondiInterdit(string $ligne): bool
    {
        foreach (self::AUTRES_ARRONDIS as $arrondi) {
            if (str_contains($ligne, $arrondi)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les fichiers PHP et Blade qui montrent des ventes, sans ceux des
     * achats, des avoirs fournisseur et des inventaires d'ouverture.
     *
     * @return list<string>
     */
    private static function fichiersDesVentes(): array
    {
        $racine = dirname(__DIR__, 2).'/';
        $fichiers = [];

        foreach (self::AFFICHAGES as $chemin) {
            if (is_file($racine.$chemin)) {
                $fichiers[] = $racine.$chemin;

                continue;
            }

            if (! is_dir($racine.$chemin)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine.$chemin, RecursiveDirectoryIterator::SKIP_DOTS)) as $fichier) {
                if ($fichier instanceof SplFileInfo && $fichier->getExtension() === 'php' && preg_match('/Achat|Fournisseur|Inventaire|achats/', $fichier->getPathname()) !== 1) {
                    $fichiers[] = $fichier->getPathname();
                }
            }
        }

        sort($fichiers);

        return $fichiers;
    }
}
