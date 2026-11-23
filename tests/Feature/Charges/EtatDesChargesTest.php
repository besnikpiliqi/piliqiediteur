<?php

namespace Tests\Feature\Charges;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Charges\Actions\RenvoyerUneChargeAction;
use App\Domain\Charges\Actions\ValiderUneChargeAction;
use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Enums\FormatDeLEtat;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\Charges\Services\EtatDesCharges;
use App\Domain\Charges\Services\EtatDesChargesEnPdf;
use App\Domain\Charges\Services\TvaDeLaPeriode;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\Charges\Support\FiltresDeLEtat;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Fichiers\EnTetesDesFichiers;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\PageDesCharges;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * L'état des charges téléchargeable (modèle E3 choisi par l'éditeur le
 * 15/11/2026, voir EtatDesCharges) : le PDF et l'Excel de ce que la page
 * « Charges » montre à la personne, avec ses filtres ; le tableau des mois
 * avec la pastille et le TTC de chaque case, la liste de la vue et la TVA
 * de chaque mois ; servis en pièce jointe, inscrits au journal d'audit de
 * la société ; jamais une charge que la page ne montre pas à la même
 * personne.
 *
 * Le jeu : une société de deux magasins (Lyon, Paris) liée à un cabinet ;
 * en mai, un loyer de « Toute la société » à valider (1 440 € TTC) et un
 * loyer de Lyon validé par le cabinet (1 200 € TTC) ; en avril, une pièce
 * d'électricité de Paris sans ses chiffres ; en mars, des fournitures de
 * Lyon renvoyées au client (120 € TTC) ; en février, des fournitures de
 * Lyon à valider dont le fournisseur commence comme une formule.
 */
class EtatDesChargesTest extends TestCase
{
    use PrepareDesCharges;
    use RefreshDatabase;

    private Magasin $lyon;

    private Magasin $paris;

    private Societe $societe;

    private User $proprietaire;

    private User $collaborateur;

    /** @var array<string, Charge> */
    private array $charges = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disqueDesCharges();
        $this->travelTo(self::aParis('2026-05-20 10:00:00'));
        $this->lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->paris = $this->ouvrirUnAutreMagasin($this->lyon, ['nom' => 'Paris']);
        $this->societe = $this->lyon->societe;
        $this->proprietaire = $this->proprietaireDe($this->lyon);
        $this->collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        app(TenantContext::class)->clear();

        $this->charges['loyer_societe'] = $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-05', 'magasin' => '']));
        $this->charges['loyer_lyon'] = $this->deposer($this->proprietaire, $this->societe, $this->saisie(ht: '1000.00', champs: ['fournisseur' => 'Bailleur de Lyon', 'numero_piece' => 'L-05', 'magasin' => (string) $this->lyon->uuid]));
        $this->charges['electricite_paris'] = $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:electricite', null, champs: ['fournisseur' => 'EDF', 'numero_piece' => 'E-04', 'date_piece' => '2026-04-03', 'magasin' => (string) $this->paris->uuid]));
        $this->charges['fournitures_lyon'] = $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:fournitures', '100.00', champs: ['fournisseur' => 'Papeterie du centre', 'numero_piece' => 'F-1', 'date_piece' => '2026-03-10', 'magasin' => (string) $this->lyon->uuid]));
        $this->charges['formule'] = $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:fournitures', '10.00', champs: ['fournisseur' => '=HYPERLINK("http://exemple.invalid")', 'numero_piece' => '+33', 'date_piece' => '2026-02-05', 'magasin' => (string) $this->lyon->uuid]));
        app(ValiderUneChargeAction::class)->handle($this->collaborateur, $this->societe, (string) $this->charges['loyer_lyon']->uuid);
        app(TenantContext::class)->clear();
        app(RenvoyerUneChargeAction::class)->handle($this->collaborateur, $this->societe, (string) $this->charges['fournitures_lyon']->uuid, 'Pièce illisible');
        app(TenantContext::class)->clear();
    }

    public function test_le_tableau_porte_l_etat_le_nombre_et_le_ttc_de_chaque_case_et_les_totaux(): void
    {
        $contenu = app(EtatDesCharges::class)->contenu($this->accesDu($this->proprietaire), $this->filtres(), null, 'Édité par la propriétaire');
        $lignes = collect($contenu->lignes)->keyBy('cle');

        $this->assertSame(['nombre' => 2, 'etat' => EtatDeLaCharge::AValider, 'ttc' => 264000], self::case($lignes->get('piliqi:loyer'), '2026-05'), 'La case de mai : deux loyers, l\'état le moins avancé, et leur TTC additionné.');
        $this->assertSame(['nombre' => 1, 'etat' => EtatDeLaCharge::PieceSansChiffres, 'ttc' => null], self::case($lignes->get('piliqi:electricite'), '2026-04'), 'Une pièce sans chiffres n\'a pas de TTC : « — ».');
        $this->assertSame(['nombre' => 1, 'etat' => EtatDeLaCharge::Renvoyee, 'ttc' => 12000], self::case($lignes->get('piliqi:fournitures'), '2026-03'));
        $this->assertSame(['nombre' => 1, 'etat' => EtatDeLaCharge::AValider, 'ttc' => 1200], self::case($lignes->get('piliqi:fournitures'), '2026-02'));
        $this->assertSame(264000, $lignes->get('piliqi:loyer')['total'] ?? null);
        $this->assertSame(13200, $lignes->get('piliqi:fournitures')['total'] ?? null);
        $this->assertSame(EtatDeLaCharge::Renvoyee, $lignes->get('piliqi:fournitures')['dernier_etat'] ?? null, 'Le dernier état d\'un type est celui de sa case du dernier mois qui en a une.');
        $this->assertSame(['2026-02' => 1200, '2026-03' => 12000, '2026-04' => 0, '2026-05' => 264000], array_intersect_key($contenu->totauxDuMois, array_flip(['2026-02', '2026-03', '2026-04', '2026-05'])));
        $this->assertSame(277200, $contenu->total);
        $this->assertSame(['piliqi:loyer', 'piliqi:electricite', 'piliqi:fournitures'], $lignes->keys()->all(), 'Les lignes de la page : les types habituels (loyer, électricité), puis ceux qui ont une charge, dans l\'ordre de la liste de PILIQI.');
        $this->assertCount(5, $contenu->charges);
    }

    public function test_la_liste_suit_la_vue_et_le_tableau_reste_celui_de_la_page(): void
    {
        $contenu = app(EtatDesCharges::class)->contenu($this->accesDu($this->proprietaire), $this->filtres(vue: 'a_valider'), null, '');

        $this->assertEqualsCanonicalizing(
            [$this->charges['loyer_societe']->getKey(), $this->charges['electricite_paris']->getKey(), $this->charges['formule']->getKey()],
            array_map(fn (Charge $charge): int => (int) $charge->getKey(), $contenu->charges),
            'La vue « À valider » : les charges à valider et les pièces sans chiffres.',
        );
        $this->assertSame(277200, $contenu->total, 'Le tableau, comme sur la page, ne suit pas la vue.');
        $this->assertSame(['2026-02', '2026-02', '2026-04', '2026-05'][0], $contenu->charges[0]->mois_tva, 'Triées par mois de TVA.');
    }

    public function test_le_pdf_a_l_horizontale_porte_les_trois_parties(): void
    {
        $euros = fn (int $centimes): string => app(CalculateurLigne::class)->enEuros($centimes);
        $tva = app(TvaDeLaPeriode::class)->pourLaPageDesCharges($this->accesDu($this->proprietaire), null, $this->filtres()->mois, null, false, [$this->lyon->getKey(), $this->paris->getKey()]);
        $contenu = app(EtatDesCharges::class)->contenu($this->accesDu($this->proprietaire), $this->filtres(), $tva, 'Édité le 20/05/2026 à 10:00 par Hélène');
        $html = html_entity_decode(app(EtatDesChargesEnPdf::class)->html($contenu), ENT_QUOTES | ENT_HTML5);

        foreach ([
            'État des charges — année 2026',
            'SIREN '.implode(' ', str_split((string) $this->societe->siren, 3)),
            'Toute la société et tous les magasins · Toutes les charges',
            'Édité le 20/05/2026 à 10:00 par Hélène',
            'Total TTC du mois',
            $euros(264000),
            $euros(277200),
            'Liste des charges — année 2026',
            'Bailleur de Lyon',
            'Pièce sans chiffres',
            'Renvoyée au client',
            'TVA par mois — année 2026',
            'Déductible des charges validées',
            $euros(20000),
            'Document de travail, n\'est pas une déclaration de TVA.',
            'PILIQI — état des charges, document de travail',
        ] as $attendu) {
            $this->assertStringContainsString($attendu, $html, "Le PDF montre « {$attendu} ».");
        }

        foreach (EtatDeLaCharge::cases() as $etat) {
            $this->assertStringContainsString('.pc-'.$etat->value.' { background: #'.$etat->couleursImprimees()['fond'], $html, 'Chaque état a sa pastille colorée.');
        }

        $this->assertStringContainsString('<span class="pc pc-a_valider">! 2</span>', $html, 'La case de mai : le symbole et le nombre de ses charges.');
        $pdf = app(EtatDesChargesEnPdf::class)->generer($contenu);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertMatchesRegularExpression('#/MediaBox \[0(\.0+)? 0(\.0+)? 841\.8\d* 595\.2\d*\]#', $pdf, 'Une page A4 à l\'horizontale.');
    }

    public function test_sans_tva_en_franchise_en_base_le_pdf_dit_non_applicable(): void
    {
        $contenu = app(EtatDesCharges::class)->contenu($this->accesDu($this->proprietaire), $this->filtres(), null, '');

        $this->assertStringContainsString('TVA non applicable (franchise en base).', app(EtatDesChargesEnPdf::class)->html($contenu));
    }

    public function test_l_excel_ecrit_des_nombres_des_dates_des_couleurs_et_desamorce_les_formules(): void
    {
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);
        $fichier = app(EtatDesCharges::class)->telecharger($this->accesDu($this->proprietaire), $this->filtres(), null, '', FormatDeLEtat::Excel);
        $feuilles = self::lireLeClasseur($fichier['contenu']);

        $this->assertSame(['Tableau', 'Liste des charges', 'TVA par mois'], array_keys($feuilles));
        $tableau = $feuilles['Tableau'];
        $this->assertSame('État des charges — année 2026 · Toute la société et tous les magasins · Toutes les charges', $tableau[0][0]);
        $loyer = collect($tableau)->first(fn (array $ligne): bool => ($ligne[0] ?? null) === 'Loyer') ?? [];
        $this->assertIsNotString($loyer[5] ?? null, 'Le TTC d\'une case est un vrai nombre.');
        $this->assertEquals(2640, $loyer[5] ?? null);
        $this->assertEquals(2640, $loyer[13] ?? null, 'La colonne « Total TTC ».');
        $this->assertSame('À valider', $loyer[14] ?? null, 'La colonne « Dernier état ».');
        $electricite = collect($tableau)->first(fn (array $ligne): bool => ($ligne[0] ?? null) === 'Électricité') ?? [];
        $this->assertSame('◦', $electricite[4] ?? null, 'Une pièce sans chiffres : « ◦ ».');

        $liste = $feuilles['Liste des charges'];
        $this->assertSame(['Mois de TVA', 'Type', 'Magasin', 'Fournisseur', 'N° de la pièce', 'Date de la pièce', 'Date de paiement', 'HT', 'TVA', 'TTC', 'TVA déductible', 'Autoliquidation', 'Origine', 'État', 'Validée par', 'Validée le', 'Motif du renvoi'], $liste[0]);
        $formule = collect($liste)->first(fn (array $ligne): bool => ($ligne[0] ?? null) === '2026-02') ?? [];
        $this->assertSame('\'=HYPERLINK("http://exemple.invalid")', $formule[3] ?? null, 'Un texte qui commence comme une formule est écrit comme texte, précédé d\'une apostrophe.');
        $this->assertSame('\'+33', $formule[4] ?? null);
        $this->assertInstanceOf(DateTimeInterface::class, $formule[5] ?? null);
        $this->assertSame('2026-02-05', ($formule[5] ?? null)?->format('Y-m-d'));
        $loyerDeLyon = collect($liste)->first(fn (array $ligne): bool => ($ligne[3] ?? null) === 'Bailleur de Lyon') ?? [];
        $this->assertEquals([1000, 200, 1200, 200], array_slice($loyerDeLyon, 7, 4));
        $this->assertSame(['Lyon', 'Validée', '20/05/2026'], [$loyerDeLyon[2] ?? null, $loyerDeLyon[13] ?? null, ($loyerDeLyon[15] ?? null)?->format('d/m/Y')]);
        $renvoyee = collect($liste)->first(fn (array $ligne): bool => ($ligne[3] ?? null) === 'Papeterie du centre') ?? [];
        $this->assertSame('Pièce illisible', $renvoyee[16] ?? null);

        $this->assertSame([['TVA non applicable (franchise en base).']], $feuilles['TVA par mois']);

        $zip = self::fichiersDuClasseur($fichier['contenu']);
        foreach (EtatDeLaCharge::cases() as $etat) {
            $this->assertStringContainsString($etat->couleursImprimees()['fond'], $zip['xl/styles.xml'], "Le fond de l'état « {$etat->getLabel()} ».");
        }
        $this->assertStringNotContainsString('<f>', $zip['xl/worksheets/sheet1.xml'].$zip['xl/worksheets/sheet2.xml'].$zip['xl/worksheets/sheet3.xml'], 'Aucune formule dans le classeur.');
        $this->assertStringContainsString('<autoFilter ref="A1:Q6"/>', $zip['xl/worksheets/sheet2.xml'], 'Le filtre automatique sur la liste.');
        $this->assertStringContainsString('ySplit="1"', $zip['xl/worksheets/sheet2.xml'], 'La ligne des titres figée.');
    }

    public function test_la_tva_par_mois_est_celle_de_la_page(): void
    {
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);
        $page = Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])->set('moisDeLaTva', '2026-05');
        $tvaDeLaPage = $page->instance()->tvaDuMois();
        $reponse = $page->instance()->telechargerLEtat('xlsx');
        $this->assertInstanceOf(StreamedResponse::class, $reponse);
        $feuilles = self::lireLeClasseur(self::contenuDe($reponse));

        $mai = collect($feuilles['TVA par mois'])->first(fn (array $ligne): bool => ($ligne[0] ?? null) === 'Mai 2026') ?? [];
        $this->assertNotNull($tvaDeLaPage);
        $montants = $tvaDeLaPage['montants'];
        $this->assertEquals([$montants->collectee(), $montants->deductibleDesAchats, $montants->deductibleDesChargesValidees, $montants->reelle(), $montants->deductibleDesChargesAValider, $montants->estimee()], array_map(fn (mixed $valeur): int => (int) round(((float) $valeur) * 100), array_slice($mai, 1, 6)), 'La TVA de mai de l\'état est celle que la page montre pour mai.');
        $this->assertSame([20000, 24000], [$montants->deductibleDesChargesValidees, $montants->deductibleDesChargesAValider]);
        $this->assertSame(
            ['Janvier 2026', 'Février 2026', 'Mars 2026', 'Avril 2026', 'Mai 2026', 'Juin 2026', 'Juillet 2026', 'Août 2026', 'Septembre 2026', 'Octobre 2026', 'Novembre 2026', 'Décembre 2026'],
            array_column(array_slice($feuilles['TVA par mois'], 1, 12), 0),
            'Une ligne par mois de la période, sous les titres.',
        );
        $this->assertContains(['Document de travail, n\'est pas une déclaration de TVA.'], $feuilles['TVA par mois']);
    }

    public function test_le_fichier_se_telecharge_en_piece_jointe_et_s_inscrit_au_journal_d_audit_sans_son_contenu(): void
    {
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        $reponse = Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])->set('vue', 'a_valider')->instance()->telechargerLEtat('pdf');

        $this->assertInstanceOf(StreamedResponse::class, $reponse);
        $nom = 'Charges 2026 — '.$this->societe->raison_sociale.'.pdf';
        $attendus = EnTetesDesFichiers::fichierProduitEnPieceJointe($nom, 'application/pdf');
        $this->assertSame(['Content-Type', 'X-Content-Type-Options', 'Cache-Control', 'Content-Disposition'], array_keys($attendus));
        $this->assertSame(['application/pdf', 'nosniff', 'no-store, private', $attendus['Content-Disposition']], array_map(fn (string $entete): ?string => $reponse->headers->get($entete), array_keys($attendus)));
        $this->assertStringStartsWith('attachment; filename=', (string) $reponse->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', self::contenuDe($reponse));

        $entree = EntreeAudit::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->where('action', ActionAuditee::EtatDesChargesTelecharge->value)->sole();
        $this->assertSame($this->proprietaire->getKey(), $entree->auteur_id);
        $this->assertNull($entree->magasin_id, 'Un état de toute la société s\'inscrit au journal de la société.');
        $this->assertEquals([
            'periode' => ['du' => '2026-01', 'au' => '2026-12', 'libelle' => 'Année 2026'],
            'vue' => 'a_valider',
            'magasin' => null,
            'format' => 'pdf',
            'nombre_charges' => 3,
        ], $entree->nouvelles_valeurs, 'Ses filtres, son format et le nombre de ses charges.');
        $this->assertStringNotContainsString('Bailleur', (string) json_encode($entree->nouvelles_valeurs), 'Jamais le contenu de l\'état.');
    }

    public function test_un_format_inconnu_est_refuse_sans_rien_produire_ni_inscrire(): void
    {
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->call('telechargerLEtat', 'docx')
            ->assertNoFileDownloaded()
            ->assertNotified('Ce format n\'existe pas : rien n\'a été téléchargé.');

        $this->assertFalse(EntreeAudit::query()->withoutGlobalScopes()->where('action', ActionAuditee::EtatDesChargesTelecharge->value)->exists());
    }

    public function test_le_fichier_d_un_responsable_ne_contient_que_les_charges_de_son_magasin(): void
    {
        $responsable = $this->membre($this->lyon, RoleMagasin::Responsable, [PermissionRegistry::CHARGES_AJOUTER]);
        $this->dansLeMagasin($this->lyon, $responsable);

        $reponse = Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::MAGASIN])->instance()->telechargerLEtat('xlsx');

        $this->assertInstanceOf(StreamedResponse::class, $reponse);
        $feuilles = self::lireLeClasseur(self::contenuDe($reponse));
        $this->assertEqualsCanonicalizing(['Bailleur de Lyon', 'Papeterie du centre', '\'=HYPERLINK("http://exemple.invalid")'], array_column(array_slice($feuilles['Liste des charges'], 1), 3), 'Ni la charge de « Toute la société », ni celle de Paris.');
        $this->assertSame('État des charges — année 2026 · Lyon · Toutes les charges', $feuilles['Tableau'][0][0] ?? null);
        $this->assertSame($this->lyon->getKey(), EntreeAudit::query()->withoutGlobalScopes()->where('action', ActionAuditee::EtatDesChargesTelecharge->value)->sole()->magasin_id, 'L\'état d\'un seul magasin s\'inscrit au journal de ce magasin.');
    }

    public function test_le_cabinet_n_a_que_les_magasins_que_le_client_lui_ouvre_et_s_inscrit_chez_le_client(): void
    {
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->sole();
        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaire, $lien, $lien->autorisations(), [$this->lyon->getKey()]);
        app(TenantContext::class)->clear();
        $cabinet = $this->collaborateur->societe()->firstOrFail();
        $this->dansLEspaceDuCabinet($cabinet, $this->collaborateur);

        $reponse = Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::CABINET, 'lien' => (string) $lien->uuid])->instance()->telechargerLEtat('xlsx');

        $this->assertInstanceOf(StreamedResponse::class, $reponse);
        $fournisseurs = array_column(array_slice(self::lireLeClasseur(self::contenuDe($reponse))['Liste des charges'], 1), 3);
        $this->assertNotContains('EDF', $fournisseurs, 'Paris n\'est pas ouvert au cabinet : sa charge n\'est jamais dans le fichier.');
        $this->assertContains('SCI des Halles', $fournisseurs, '« Toute la société » reste ouverte au cabinet.');
        $entree = EntreeAudit::query()->withoutGlobalScopes()->where('action', ActionAuditee::EtatDesChargesTelecharge->value)->sole();
        $this->assertSame([$this->societe->getKey(), $this->collaborateur->getKey()], [$entree->societe_id, $entree->auteur_id], 'Inscrit au journal du client, au nom du collaborateur.');
    }

    public function test_le_cabinet_sans_l_autorisation_n_ouvre_pas_la_page_et_n_a_donc_aucun_etat(): void
    {
        $sansLesCharges = array_values(array_diff(PermissionRegistry::parDefautDuCabinet($this->societe->type_activite), [PermissionRegistry::CHARGES_VALIDER]));
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $this->societe->getKey())->sole();
        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaire, $lien, $sansLesCharges, null);
        app(TenantContext::class)->clear();
        $this->dansLEspaceDuCabinet($this->collaborateur->societe()->firstOrFail(), $this->collaborateur);

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::CABINET, 'lien' => (string) $lien->uuid])->assertForbidden();
        $this->assertFalse(EntreeAudit::query()->withoutGlobalScopes()->where('action', ActionAuditee::EtatDesChargesTelecharge->value)->exists());
    }

    public function test_une_charge_d_une_autre_societe_n_apparait_jamais(): void
    {
        $autre = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $this->deposer($this->proprietaireDe($autre), $autre->societe, $this->saisie(champs: ['fournisseur' => 'Bailleur d\'ailleurs', 'numero_piece' => 'A-1', 'magasin' => '']));

        $contenu = app(EtatDesCharges::class)->contenu($this->accesDu($this->proprietaire), $this->filtres(), null, '');

        $this->assertNotContains('Bailleur d\'ailleurs', array_map(fn (Charge $charge): ?string => $charge->fournisseur, $contenu->charges));
        $this->assertSame(277200, $contenu->total);
    }

    /**
     * @param  'toutes'|'a_valider'|'renvoyees'  $vue
     */
    private function filtres(string $vue = 'toutes'): FiltresDeLEtat
    {
        return new FiltresDeLEtat(
            mois: array_map(fn (int $mois): string => sprintf('2026-%02d', $mois), range(1, 12)),
            periode: 'Année 2026',
            vue: $vue,
            magasin: null,
            sansMagasin: false,
            libelleDuMagasin: 'Toute la société et tous les magasins',
        );
    }

    private function accesDu(User $compte): AccesAuxCharges
    {
        return app(DroitsSurLesCharges::class)->pour($compte, $this->societe) ?? throw new \LogicException('Ce compte devrait voir les charges.');
    }

    /**
     * @param  array{cases: array<string, array{etat: EtatDeLaCharge, nombre: int, ttc: int|null}>}|null  $ligne
     * @return array{nombre: int, etat: EtatDeLaCharge, ttc: int|null}|null
     */
    private static function case(?array $ligne, string $mois): ?array
    {
        $case = $ligne['cases'][$mois] ?? null;

        return $case === null ? null : ['nombre' => $case['nombre'], 'etat' => $case['etat'], 'ttc' => $case['ttc']];
    }

    private static function contenuDe(StreamedResponse $reponse): string
    {
        ob_start();
        $reponse->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * Les feuilles du classeur, chacune comme ses lignes de valeurs, lues
     * par OpenSpout.
     *
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

    /**
     * Les fichiers XML du classeur, par chemin.
     *
     * @return array<string, string>
     */
    private static function fichiersDuClasseur(string $contenu): array
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'etat-zip-');
        file_put_contents($chemin, $contenu);
        $zip = new ZipArchive;
        $zip->open($chemin);
        $fichiers = [];

        for ($rang = 0; $rang < $zip->numFiles; $rang++) {
            $nom = (string) $zip->getNameIndex($rang);
            $fichiers[$nom] = (string) $zip->getFromIndex($rang);
        }

        $zip->close();
        unlink($chemin);

        return $fichiers;
    }
}
