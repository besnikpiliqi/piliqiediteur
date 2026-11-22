<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Bases\BrancherUneBase;
use App\Domain\Demonstration\Construction\ConstruireUnModele;
use App\Domain\Demonstration\Construction\DossiersDeLaDemonstration;
use App\Domain\Demonstration\Construction\SemisDuModeleParLeSemeur;
use App\Domain\Demonstration\Contracts\SemisDUnModele;
use App\Domain\Demonstration\Registre\Models\Modele;
use App\Domain\Demonstration\Services\CarteDeLaDemonstration;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Semis\ComptesDuBandeauDuModele;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use Tests\DemonstrationTestCase;

/**
 * Les vraies bases modèles du site de démonstration (lot DEMO-COPIE, N15,
 * F.1 à F.3), construites par la vraie construction et le vrai semis
 * (plusieurs minutes chacune : groupe « copies », hors de la suite par
 * défaut, une tâche de la CI à lui), puis relues dans une copie : chaque
 * modèle ne contient que les sociétés de son type (Maison Ferrand seule,
 * Conseil Vasseur seule, le cabinet Martin et ses clients), jamais une
 * société qu'aucun compte du bandeau n'ouvre ; dans les deux modèles de
 * sociétés, l'expert-comptable déclaré a un compte actif et aucun cabinet
 * n'existe ; Maison Ferrand a son magasin de Fort-de-France ; chaque
 * magasin a son JET 100 ; les tables techniques sont vides ; chaque compte
 * du bandeau est un compte non retiré de la société mise en avant, avec le
 * mot de passe de démonstration du modèle, sans préférence de tableau de
 * bord (toutes ses cartes permises s'ouvrent).
 */
#[Group('copies')]
class BasesModelesTest extends DemonstrationTestCase
{
    public function test_le_modele_commerce_ne_contient_que_maison_ferrand_avec_fort_de_france_et_son_expert_comptable_declare(): void
    {
        [$modele, $lecture] = $this->construireEtLire(TypeDActivite::Commerce);

        $this->assertSame(['MAISON FERRAND'], $lecture['societes']);
        $this->assertStringContainsString('plusieurs magasins,', CarteDeLaDemonstration::phraseACreer(TypeDActivite::Commerce), 'La carte « À créer » décrit ce modèle : six magasins.');
        $this->assertSame([
            ['Maison Ferrand Entrepôt', 'metropole'],
            ['Maison Ferrand Fort-de-France', 'martinique'],
            ['Maison Ferrand Halles', 'metropole'],
            ['Maison Ferrand Marché', 'metropole'],
            ['Maison Ferrand République', 'metropole'],
            ['Maison Ferrand Éphémère', 'metropole'],
        ], $lecture['magasins'], 'Maison Ferrand compte six magasins (dans l\'ordre binaire de la base, où « É » vient après les lettres) : les trois premiers et trois magasins d\'achats.');
        $this->assertCasDeLEcartDesPrixDAchat($lecture);
        $this->assertSame(['2.1', '8.5'], $lecture['taux_de_fort_de_france'], 'Les articles de Fort-de-France sont aux taux de la Martinique.');
        $this->assertGreaterThan(0, $lecture['produits']);
        $this->assertGreaterThan(0, $lecture['factures_achat']);
        $this->assertSame(['Lina Martin', 'Paul Lefèvre'], $lecture['experts_comptables_actifs'], 'L\'expert-comptable déclaré par le SIREN du cabinet Martin, absent du modèle, a ses comptes actifs (Paul Lefèvre, qui a choisi son mot de passe, est au bandeau).');
        $this->assertSame(['declare'], $lecture['liens_cabinet'], 'Maison Ferrand déclare son expert-comptable au lieu d\'être liée au cabinet Martin, absent du modèle.');
        $this->assertSame([], $lecture['cabinets']);
        $this->assertNotSame([], $lecture['courriels'], 'La page « E-mails qui seraient partis » d\'une copie neuve montre de vrais exemples de sa société (F.4).');
        $this->assertSame(['MAISON FERRAND'], array_values(array_unique(array_column($lecture['courriels'], 'raison_sociale'))));
        $this->assertBandeauDuModele($modele, $lecture, TypeDActivite::Commerce);
        $this->assertCommunAuxModeles($lecture);
    }

    /**
     * La carte « Écart des prix d'achat » paraît d'elle-même dans une base
     * modèle qui contient Maison Ferrand (le compte du bandeau n'a aucune
     * préférence : toutes ses cartes permises s'ouvrent) : l'Entrepôt porte
     * ses cas (des dizaines de lignes d'achat validées), l'Éphémère n'a
     * aucun achat validé et le Marché un seul.
     *
     * @param  array<string, mixed>  $lecture
     */
    private function assertCasDeLEcartDesPrixDAchat(array $lecture): void
    {
        $achats = $lecture['achats_valides_par_magasin'];

        $this->assertGreaterThan(40, $achats['Maison Ferrand Entrepôt'] ?? 0, 'L\'Entrepôt porte les cas de la carte.');
        $this->assertArrayNotHasKey('Maison Ferrand Éphémère', $achats, 'L\'Éphémère n\'a aucun achat validé.');
        $this->assertSame(1, $achats['Maison Ferrand Marché'] ?? 0, 'Le Marché n\'a qu\'un produit, acheté une seule fois.');
        $this->assertGreaterThan(0, $achats['Maison Ferrand Halles'] ?? 0);
    }

    public function test_le_modele_services_ne_contient_que_conseil_vasseur_sans_article_avec_son_expert_comptable_declare(): void
    {
        [$modele, $lecture] = $this->construireEtLire(TypeDActivite::Services);

        $this->assertSame(['CONSEIL VASSEUR'], $lecture['societes']);
        $this->assertSame([['Conseil Vasseur Paris', 'metropole']], $lecture['magasins']);
        $this->assertSame([], $lecture['achats_valides_par_magasin'], 'Une société de services n\'a aucun achat : elle n\'a pas la carte « Écart des prix d\'achat ».');
        $this->assertStringContainsString('un magasin,', CarteDeLaDemonstration::phraseACreer(TypeDActivite::Services), 'La carte « À créer » décrit ce modèle : un seul magasin.');
        $this->assertSame(0, $lecture['produits']);
        $this->assertSame(0, $lecture['factures_achat']);
        $this->assertSame(['Marc Petit', 'Sophie Petit'], $lecture['experts_comptables_actifs']);
        $this->assertSame([], $lecture['cabinets']);
        $this->assertNotSame([], $lecture['courriels'], 'La page « E-mails qui seraient partis » d\'une copie neuve montre de vrais exemples de sa société (F.4).');
        $this->assertSame(['CONSEIL VASSEUR'], array_values(array_unique(array_column($lecture['courriels'], 'raison_sociale'))));
        $this->assertBandeauDuModele($modele, $lecture, TypeDActivite::Services);
        $this->assertCommunAuxModeles($lecture);
    }

    public function test_le_modele_cabinet_contient_le_cabinet_martin_et_ses_clients_sans_compte_d_un_client_au_bandeau(): void
    {
        [$modele, $lecture] = $this->construireEtLire(TypeDActivite::Cabinet);

        $this->assertSame(['EPICERIE LEMOINE', 'MAISON FERRAND', 'MARTIN EXPERTISE COMPTABLE'], $lecture['societes']);
        $this->assertSame(['MARTIN EXPERTISE COMPTABLE'], $lecture['cabinets']);
        $this->assertSame(0, $lecture['magasins_du_cabinet'], 'Un cabinet n\'a pas de magasin.');
        $this->assertSame(['actif', 'actif'], $lecture['liens_cabinet'], 'Le cabinet est lié à ses deux clients.');
        $this->assertCasDeLEcartDesPrixDAchat($lecture);
        $this->assertSame(['acceptee', 'expiree', 'ouverte', 'refusee', 'retiree'], $lecture['invitations'], 'L\'invitation acceptée par l\'Épicerie Lemoine, et celles à des entreprises qui ne s\'inscrivent pas, dans chaque état qui ne demande pas une autre société.');
        $this->assertSame([], $lecture['experts_comptables_actifs']);
        $objetsDuCabinet = array_column(array_filter($lecture['courriels'], static fn (array $courriel): bool => $courriel['raison_sociale'] === 'MARTIN EXPERTISE COMPTABLE'), 'objet');
        $this->assertNotSame(
            [],
            array_filter($objetsDuCabinet, static fn (string $objet): bool => str_starts_with($objet, 'MARTIN EXPERTISE COMPTABLE vous invite sur ')),
            'Les invitations du cabinet, envoyées hors de tout contexte après leur transaction, s\'écrivent dans les e-mails du cabinet (F.4).',
        );
        $this->assertBandeauDuModele($modele, $lecture, TypeDActivite::Cabinet);
        $this->assertCommunAuxModeles($lecture);
    }

    /**
     * Construit le vrai modèle de ce type, puis le relit dans une copie
     * jetable, supprimée ensuite, même après un échec.
     *
     * @return array{0: Modele, 1: array<string, mixed>}
     */
    private function construireEtLire(TypeDActivite $type): array
    {
        $this->app->instance(SemisDUnModele::class, new SemisDuModeleParLeSemeur);
        $this->travelTo(self::aParis('2026-11-16 01:10:00')->addMinutes(self::jeton() * 3 + array_search($type, TypeDActivite::cases(), true)));
        $modele = app(ConstruireUnModele::class)->construire($type);
        $this->assertTrue(File::isDirectory(DossiersDeLaDemonstration::duModele($modele->nom_de_la_base)), 'Les fichiers du modèle (pièces, documents) sont sous son dossier, que chaque copie recopie.');
        $bases = app(BasesDeLaDemonstration::class);
        $copie = $bases->nomDUneCopie();
        $bases->copierUnModele($modele->nom_de_la_base, $copie);

        try {
            $lecture = $this->lireLaCopie($copie);
        } finally {
            $bases->supprimer($copie);
        }

        return [$modele, $lecture];
    }

    /**
     * Ce que le test relit dans la copie jetable d'un modèle, par
     * demo_admin.
     *
     * @return array<string, mixed>
     */
    private function lireLaCopie(string $copie): array
    {
        return BrancherUneBase::pendant(BrancherUneBase::parLAdministration($copie), fn (): array => [
            'societes' => DB::table('societes')->orderBy('raison_sociale')->pluck('raison_sociale')->all(),
            'cabinets' => DB::table('societes')->where('type_activite', TypeDActivite::Cabinet->value)->pluck('raison_sociale')->all(),
            'magasins' => DB::table('magasins')->join('societes', 'societes.id', '=', 'magasins.societe_id')->where('societes.type_activite', '!=', TypeDActivite::Cabinet->value)->orderBy('magasins.nom')->get(['magasins.nom', 'magasins.territoire'])->map(fn (object $magasin): array => [(string) $magasin->nom, (string) $magasin->territoire])->all(),
            'achats_valides_par_magasin' => DB::table('lignes_achat')
                ->join('factures_achat', fn ($jointure) => $jointure->on('factures_achat.magasin_id', '=', 'lignes_achat.magasin_id')->on('factures_achat.id', '=', 'lignes_achat.facture_achat_id'))
                ->join('magasins', 'magasins.id', '=', 'factures_achat.magasin_id')
                ->where('factures_achat.statut', 'validee')
                ->groupBy('magasins.nom')
                ->orderBy('magasins.nom')
                ->selectRaw('magasins.nom as nom, count(*) as achats')
                ->get()->mapWithKeys(fn (object $ligne): array => [(string) $ligne->nom => (int) $ligne->achats])->all(),
            'magasins_du_cabinet' => DB::table('magasins')->join('societes', 'societes.id', '=', 'magasins.societe_id')->where('societes.type_activite', TypeDActivite::Cabinet->value)->count(),
            'taux_de_fort_de_france' => DB::table('produits')->join('magasins', 'magasins.id', '=', 'produits.magasin_id')->where('magasins.nom', 'Maison Ferrand Fort-de-France')->distinct()->orderBy('taux_tva')->pluck('taux_tva')->map(fn (mixed $taux): string => rtrim(rtrim((string) $taux, '0'), '.'))->all(),
            'produits' => DB::table('produits')->count(),
            'factures_achat' => DB::table('factures_achat')->count(),
            'experts_comptables_actifs' => DB::table('users')->where('role_societe', 'expert_comptable')->where('statut', 'actif')->orderBy('name')->pluck('name')->all(),
            'liens_cabinet' => DB::table('liens_cabinet')->orderBy('etat')->pluck('etat')->all(),
            'invitations' => DB::table('invitations_cabinet')->orderBy('etat')->pluck('etat')->all(),
            'jet_100_manquants' => DB::table('magasins')->whereNotExists(fn ($requete) => $requete->from('enregistrements_fiscaux')->whereColumn('enregistrements_fiscaux.magasin_id', 'magasins.id')->where('enregistrements_fiscaux.type', '100'))->pluck('nom')->all(),
            'tables_techniques' => collect(ConstruireUnModele::TABLES_TECHNIQUES)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all(),
            'bandeau' => DB::table('comptes_du_bandeau')
                ->join('users', 'users.id', '=', 'comptes_du_bandeau.user_id')
                ->join('societes', 'societes.id', '=', 'comptes_du_bandeau.societe_id')
                ->orderBy('comptes_du_bandeau.ordre')
                ->get(['users.name', 'users.statut', 'users.password', 'users.societe_id as societe_du_compte', 'comptes_du_bandeau.societe_id', 'comptes_du_bandeau.groupe', 'societes.raison_sociale'])
                ->map(fn (object $ligne): array => (array) $ligne)
                ->all(),
            'preferences_du_bandeau' => DB::table('preferences_tableau_de_bord')
                ->whereIn('user_id', DB::table('comptes_du_bandeau')->select('user_id'))
                ->count(),
            'regimes_manquants' => DB::table('societes')->where('type_activite', '!=', TypeDActivite::Cabinet->value)->whereNull('regime_tva')->pluck('raison_sociale')->all(),
            'courriels' => DB::table('courriels_de_la_demonstration')
                ->join('societes', 'societes.id', '=', 'courriels_de_la_demonstration.societe_id')
                ->orderBy('courriels_de_la_demonstration.id')
                ->get(['societes.raison_sociale', 'courriels_de_la_demonstration.objet', 'courriels_de_la_demonstration.envoye_le'])
                ->map(fn (object $ligne): array => (array) $ligne)
                ->all(),
        ]);
    }

    /**
     * Chaque compte du bandeau est un compte non retiré de la société mise
     * en avant du modèle, avec le mot de passe de démonstration du modèle,
     * dans les groupes et l'ordre du semis (voir ComptesDuBandeauDuModele).
     *
     * @param  array<string, mixed>  $lecture
     */
    private function assertBandeauDuModele(Modele $modele, array $lecture, TypeDActivite $type): void
    {
        $attendus = [];

        foreach (ComptesDuBandeauDuModele::COMPTES[$type->value][1] as $comptes) {
            foreach (array_keys($comptes) as $nom) {
                $attendus[] = str_starts_with($nom, 'propriétaire ') ? null : preg_replace('/ \(.*\)$/', '', $nom);
            }
        }

        $bandeau = $lecture['bandeau'];
        $this->assertIsArray($bandeau);
        $this->assertCount(count($attendus), $bandeau);
        $societes = array_unique(array_column($bandeau, 'raison_sociale'));
        $this->assertSame([$type === TypeDActivite::Cabinet ? 'MARTIN EXPERTISE COMPTABLE' : ($type === TypeDActivite::Commerce ? 'MAISON FERRAND' : 'CONSEIL VASSEUR')], array_values($societes), 'Seuls les comptes de la société mise en avant entrent au bandeau.');

        foreach ($bandeau as $rang => $ligne) {
            $this->assertSame($ligne['societe_id'], $ligne['societe_du_compte']);
            $this->assertNotSame('retire', $ligne['statut']);
            $this->assertTrue(Hash::check($modele->mot_de_passe, (string) $ligne['password']) || $ligne['name'] === 'Inès Garcia', "Le compte {$ligne['name']} a le mot de passe de démonstration du modèle.");

            if ($attendus[$rang] !== null) {
                $this->assertSame($attendus[$rang], $ligne['name']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $lecture
     */
    private function assertCommunAuxModeles(array $lecture): void
    {
        $this->assertSame([], $lecture['jet_100_manquants'], 'Chaque magasin d\'une base modèle a son JET 100 (mode d\'entraînement).');
        $this->assertSame(array_fill_keys(ConstruireUnModele::TABLES_TECHNIQUES, 0), $lecture['tables_techniques']);
        $this->assertSame([], $lecture['regimes_manquants'], 'Chaque société qui vend a son régime de TVA.');
        $this->assertSame(0, $lecture['preferences_du_bandeau'], 'Aucun compte du bandeau n\'a de préférence de tableau de bord : chacun ouvre toutes les cartes que son écran permet (précision du 16/11/2026 vers 23 h 59).');
        $this->assertIsArray($lecture['courriels']);
        $debut = Calendrier::deParis()->debutDuJour(Calendrier::ajouterJours(Calendrier::deParis()->jourDe(CarbonImmutable::now()), -DatabaseSeeder::JOURS_DES_E_MAILS_DES_MODELES));

        foreach ($lecture['courriels'] as $courriel) {
            $this->assertGreaterThanOrEqual($debut->getTimestamp(), CarbonImmutable::parse((string) $courriel['envoye_le'], 'UTC')->getTimestamp(), 'Seuls les e-mails des 7 derniers jours de l\'histoire semée sont écrits dans « E-mails qui seraient partis » (F.4).');
        }
    }
}
