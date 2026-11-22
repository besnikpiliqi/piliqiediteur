<?php

namespace Tests\Feature\Charges;

use App\Domain\Charges\Enums\FormatDeLEtat;
use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\Charges\Services\EtatDesCharges;
use App\Domain\Charges\Services\EtatDesChargesEnPdf;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\Charges\Support\FiltresDeLEtat;
use App\Domain\Charges\Support\TextesDesCharges;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\PageDesCharges;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;
use ZipArchive;

/**
 * « TVA autoliquidée », en orange, sous le montant de la TVA d'une charge
 * dont le fournisseur est établi hors de France (décision de l'éditeur du
 * 20/11/2026, maquette v5) : dans la fenêtre « Ajouter une charge », sous le
 * champ TVA de la ligne, dans la liste des charges et dans l'état
 * téléchargé (PDF et Excel) ; le libellé « TTC » ne change pas, et une
 * charge d'un fournisseur établi en France n'a aucune mention.
 */
class TvaAutoliquideeEnOrangeTest extends TestCase
{
    use PrepareDesCharges;
    use RefreshDatabase;

    private Societe $societe;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disqueDesCharges();
        $this->travelTo(self::aParis('2026-05-20 10:00:00'));
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->societe = $lyon->societe;
        $this->proprietaire = $this->proprietaireDe($lyon);
        app(TenantContext::class)->clear();
        $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:abonnements_logiciels', '20.00', champs: ['fournisseur' => 'Cloud Nordique AB', 'numero_piece' => 'CN-1', 'fournisseur_hors_france' => true, 'magasin' => '']));
        $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:loyer', '1200.00', champs: ['fournisseur' => 'SCI des Halles', 'numero_piece' => 'Q-05', 'magasin' => '']));
    }

    public function test_la_fenetre_ecrit_la_mention_orange_sous_la_tva_quand_la_case_est_cochee_et_rien_sinon(): void
    {
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        // La liste, sous la fenêtre, porte déjà la mention de la charge du fournisseur établi hors de France.
        $page = Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->mountTableAction('ajouterUneCharge')
            ->set('mountedTableActionsData.0.lignes', ['ligne' => ['taux' => '2000', 'ht' => '20,00', 'tva' => '4,00', 'ttc' => '24,00']]);
        $dansLaListe = substr_count($page->html(), 'data-tva-autoliquidee');
        $this->assertSame(1, $dansLaListe);

        $page->set('mountedTableActionsData.0.fournisseur_hors_france', true)
            ->assertSet('mountedTableActionsData.0.lignes.ligne.ttc', '20,00', 'Le libellé « TTC » reste, et le TTC de la ligne est son HT.')
            ->assertSee('TTC');
        $this->assertSame($dansLaListe + 1, substr_count($page->html(), 'data-tva-autoliquidee'), 'Une mention de plus, sous la TVA de la seule ligne de la fenêtre.');
        $this->assertStringContainsString('<span class="piliqi-charges__autoliquidee" data-tva-autoliquidee>TVA autoliquidée</span>', $page->html());

        $page->set('mountedTableActionsData.0.fournisseur_hors_france', false)
            ->assertSet('mountedTableActionsData.0.lignes.ligne.ttc', '24,00');
        $this->assertSame($dansLaListe, substr_count($page->html(), 'data-tva-autoliquidee'), 'Case décochée, la mention de la fenêtre disparaît.');
    }

    public function test_la_liste_ecrit_la_mention_sous_la_tva_de_la_seule_charge_autoliquidee(): void
    {
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        $page = Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])->assertSee('Cloud Nordique AB')->assertSee('SCI des Halles');

        $this->assertSame(1, substr_count($page->html(), 'data-tva-autoliquidee'), 'Seule la charge du fournisseur établi hors de France porte la mention, pas le loyer.');
    }

    public function test_le_pdf_et_l_excel_disent_la_meme_mention_orange_sous_la_tva_de_la_seule_charge_autoliquidee(): void
    {
        $contenu = app(EtatDesCharges::class)->contenu($this->acces(), $this->filtres(), null, 'Édité par le propriétaire');
        $html = html_entity_decode(app(EtatDesChargesEnPdf::class)->html($contenu), ENT_QUOTES | ENT_HTML5);

        $this->assertSame(1, substr_count($html, '<span class="autoliquidee">'.TextesDesCharges::TVA_AUTOLIQUIDEE.'</span>'), 'Une mention dans la liste du PDF, sous la TVA du seul fournisseur hors de France.');
        $this->assertStringContainsString('.autoliquidee { color: #8a4a06;', $html);

        $fichier = app(EtatDesCharges::class)->telecharger($this->acces(), $this->filtres(), null, '', FormatDeLEtat::Excel);
        $liste = self::lireLeClasseur($fichier['contenu'])['Liste des charges'];
        $colonne = array_search('Autoliquidation', $liste[0], true);
        $this->assertIsInt($colonne);
        $valeurs = array_map(fn (array $ligne): mixed => $ligne[$colonne] ?? null, array_slice($liste, 1));
        $this->assertEqualsCanonicalizing(['TVA autoliquidée', ''], array_map(fn (mixed $valeur): string => (string) $valeur, $valeurs));
        $this->assertStringContainsString('FF8A4A06', self::styles($fichier['contenu']), 'La mention de l\'Excel est en orange.');
    }

    private function acces(): AccesAuxCharges
    {
        return app(DroitsSurLesCharges::class)->pour($this->proprietaire, $this->societe) ?? throw new LogicException('Le propriétaire devrait voir les charges.');
    }

    private function filtres(): FiltresDeLEtat
    {
        return new FiltresDeLEtat(
            mois: array_map(fn (int $mois): string => sprintf('2026-%02d', $mois), range(1, 12)),
            periode: 'Année 2026',
            vue: 'toutes',
            magasin: null,
            sansMagasin: false,
            libelleDuMagasin: 'Toute la société et tous les magasins',
        );
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    private static function lireLeClasseur(string $contenu): array
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'etat-lu-');
        file_put_contents($chemin, $contenu);
        $lecteur = new Reader;
        $lecteur->open($chemin);
        $feuilles = [];

        foreach ($lecteur->getSheetIterator() as $feuille) {
            $lignes = [];

            foreach ($feuille->getRowIterator() as $ligne) {
                $lignes[] = array_values($ligne->toArray());
            }

            $feuilles[$feuille->getName()] = $lignes;
        }

        $lecteur->close();
        unlink($chemin);

        return $feuilles;
    }

    private static function styles(string $contenu): string
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'etat-zip-');
        file_put_contents($chemin, $contenu);
        $zip = new ZipArchive;
        $zip->open($chemin);
        $styles = (string) $zip->getFromName('xl/styles.xml');
        $zip->close();
        unlink($chemin);

        return $styles;
    }
}
