<?php

namespace Tests\Unit;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\ReferenceDeTva;
use App\Domain\Facturation\Support\TableDesMentionsDeTva;
use App\Domain\Tenancy\Enums\Territoire;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La table datée des mentions de la chaîne TVA (décisions de Claude T40.1
 * et T40.2 du 22/11/2026, D76), sans base ni application : chaque texte
 * cite l'article du code général des impôts (CGI) pour un document émis
 * jusqu'au 31 décembre 2026, celui du code des impositions sur les biens
 * et services (CIBS) à partir du 1er janvier 2027, au calendrier du
 * magasin du document ; une version ne change que les textes qu'elle
 * nomme, et le texte d'une version ne se corrige plus : chaque texte de
 * chaque version est écrit ici en entier, et une version nouvelle s'ajoute
 * ici avec ses textes.
 */
class TableDesMentionsDeTvaTest extends TestCase
{
    /**
     * Chaque référence de la table, avec son texte du CGI et celui du
     * CIBS (table des mentions du 22/11/2026, § 4).
     *
     * @return array<string, array{ReferenceDeTva, string, string}>
     */
    public static function textesDeChaqueReference(): array
    {
        return [
            'export hors UE' => [ReferenceDeTva::MentionDeLExportHorsUe, 'Exonération de TVA, article 262 I du CGI', 'Exonération de TVA, articles L. 213-12, 2° et L. 213-14 du CIBS'],
            'livraison vers un DOM' => [ReferenceDeTva::MentionDeLaLivraisonVersUnDom, 'Exonération de TVA en application de l\'article 294 du Code général des impôts', 'Exonération de TVA, articles L. 211-7, L. 213-12, 2° et L. 213-14 du CIBS'],
            'livraison intracommunautaire' => [ReferenceDeTva::MentionDeLaLivraisonIntracommunautaire, 'Exonération de TVA, article 262 ter I du CGI', 'Exonération de TVA, articles L. 213-12, 1° et L. 213-13 du CIBS'],
            'autoliquidation, sans article' => [ReferenceDeTva::MentionDeLAutoliquidation, 'Autoliquidation', 'Autoliquidation'],
            'prestation hors UE' => [ReferenceDeTva::MentionDeLaPrestationHorsUe, 'TVA non applicable – article 259-1° du CGI', 'TVA non applicable – article L. 211-92 du CIBS'],
            'mention de la franchise' => [ReferenceDeTva::MentionDeLaFranchise, 'TVA non applicable, art. 293 B du CGI', 'TVA non applicable, art. L. 233-3 du CIBS'],
            'article de la franchise' => [ReferenceDeTva::ArticleDeLaFranchise, 'art. 293 B du CGI', 'art. L. 233-3 du CIBS'],
            'assujetti unique' => [ReferenceDeTva::ArticleDeLAssujettiUnique, 'art. 256 C du CGI', 'art. L. 234-2 du CIBS'],
            'lieu fixé par la loi' => [ReferenceDeTva::ArticlesDuLieuFixeParLaLoi, 'art. 259 A du CGI', 'art. L. 211-75, L. 211-81, L. 211-85, L. 211-87 et L. 211-89 du CIBS'],
            'location de logements' => [ReferenceDeTva::ArticleDeLaLocationDeLogements, 'art. 261 D du CGI', 'art. L. 231-21 du CIBS'],
            'ventes à distance' => [ReferenceDeTva::ArticlesDesVentesADistance, 'art. 259 D du CGI', 'art. L. 232-16 et L. 232-18 du CIBS'],
            'transmission d\'une activité' => [ReferenceDeTva::TransmissionDUneActiviteEconomique, 'transmission d\'universalité, art. 257 bis du CGI', 'transmission d\'une activité économique autonome, art. L. 211-107 et L. 211-108 du CIBS'],
        ];
    }

    /**
     * La veille du 1er janvier 2027, le CGI ; le jour même et le
     * lendemain, le CIBS ; bien avant, le CGI, et bien après, au-delà de la
     * fin de la tolérance des références au CGI (30 juin 2028), le CIBS.
     */
    #[DataProvider('textesDeChaqueReference')]
    public function test_chaque_texte_cite_le_cgi_jusqu_au_31_decembre_2026_puis_le_cibs(ReferenceDeTva $reference, string $duCgi, string $duCibs): void
    {
        $attendus = ['2020-01-01' => $duCgi, '2026-12-31' => $duCgi, '2027-01-01' => $duCibs, '2027-01-02' => $duCibs, '2028-07-01' => $duCibs, '2035-06-15' => $duCibs];
        $lus = [];

        foreach (array_keys($attendus) as $jour) {
            $lus[$jour] = TableDesMentionsDeTva::texte($reference, $jour);
        }

        $this->assertSame($attendus, $lus);
        $this->assertSame(array_values(array_unique([$duCgi, $duCibs])), TableDesMentionsDeTva::textesDe($reference), 'Les textes qu\'un document émis a pu signer, d\'origine puis de chaque version.');
    }

    /**
     * Chaque référence a un texte d'origine, et la table n'en connaît pas
     * d'autres que celles de ce test.
     */
    public function test_chaque_reference_a_ses_textes_dans_ce_test(): void
    {
        $this->assertEqualsCanonicalizing(
            array_map(fn (ReferenceDeTva $reference): string => $reference->value, ReferenceDeTva::cases()),
            array_map(fn (array $textes): string => $textes[0]->value, array_values(self::textesDeChaqueReference())),
        );
    }

    /**
     * Le jour qui décide est celui du calendrier du magasin du document :
     * à Paris, la dernière seconde du 31 décembre 2026 cite le CGI et la
     * première du 1er janvier 2027 le CIBS ; en Martinique, un document émis
     * le 31 décembre à 20 h, quand Paris est déjà le 1er janvier à 1 h,
     * cite le CGI, comme celui de la dernière seconde de l'année, à près de
     * 5 h à Paris, et celui du 1er janvier à 0 h 30 le CIBS ; à La Réunion,
     * qui bascule la première, un document émis le 1er janvier à 0 h 30,
     * le 31 décembre à 21 h 30 à Paris, cite le CIBS, et celui de la
     * dernière seconde de 2026, à près de 21 h à Paris, le CGI.
     *
     * @return array<string, array{Territoire, CarbonImmutable, string, string, bool}>
     */
    public static function instantsDeLaBascule(): array
    {
        $paris = Calendrier::duTerritoire(Territoire::Metropole);
        $martinique = Calendrier::duTerritoire(Territoire::Martinique);
        $reunion = Calendrier::duTerritoire(Territoire::Reunion);

        return [
            'Paris, dernière seconde de 2026' => [Territoire::Metropole, $paris->heureMurale('2026-12-31 23:59:59'), '2026-12-31', '2026-12-31', false],
            'Paris, première seconde de 2027' => [Territoire::Metropole, $paris->heureMurale('2027-01-01 00:00:00'), '2027-01-01', '2027-01-01', true],
            'Paris, le lendemain' => [Territoire::Metropole, $paris->heureMurale('2027-01-02 10:00:00'), '2027-01-02', '2027-01-02', true],
            'Martinique, le 31 décembre à 20 h' => [Territoire::Martinique, $martinique->heureMurale('2026-12-31 20:00:00'), '2026-12-31', '2027-01-01', false],
            'Martinique, dernière seconde de 2026' => [Territoire::Martinique, $martinique->heureMurale('2026-12-31 23:59:59'), '2026-12-31', '2027-01-01', false],
            'Martinique, le 1er janvier à 0 h 30' => [Territoire::Martinique, $martinique->heureMurale('2027-01-01 00:30:00'), '2027-01-01', '2027-01-01', true],
            'La Réunion, dernière seconde de 2026' => [Territoire::Reunion, $reunion->heureMurale('2026-12-31 23:59:59'), '2026-12-31', '2026-12-31', false],
            'La Réunion, le 1er janvier à 0 h 30' => [Territoire::Reunion, $reunion->heureMurale('2027-01-01 00:30:00'), '2027-01-01', '2026-12-31', true],
        ];
    }

    #[DataProvider('instantsDeLaBascule')]
    public function test_le_jour_du_document_se_lit_au_calendrier_de_son_magasin(Territoire $territoire, CarbonImmutable $instant, string $jourDuMagasin, string $jourDeParis, bool $cibs): void
    {
        $jour = Calendrier::duTerritoire($territoire)->jourDe($instant);

        $this->assertSame([$jourDuMagasin, $jourDeParis], [$jour, Calendrier::deParis()->jourDe($instant)]);
        $this->assertSame(
            $cibs ? 'Exonération de TVA, articles L. 213-12, 2° et L. 213-14 du CIBS' : 'Exonération de TVA, article 262 I du CGI',
            TableDesMentionsDeTva::mentionDeLaNature(NatureOperation::ExportHorsUe, $jour),
        );
        $this->assertSame($cibs ? 'TVA non applicable, art. L. 233-3 du CIBS' : 'TVA non applicable, art. 293 B du CGI', TableDesMentionsDeTva::mentionDeLaFranchise($jour));
        $this->assertSame($cibs ? 'Franchise en base de TVA (art. L. 233-3 du CIBS)' : 'Franchise en base de TVA (art. 293 B du CGI)', TableDesMentionsDeTva::motifDeLaFranchise($jour));
    }

    /**
     * Chaque nature d'opération imprime la mention de sa référence ; la
     * vente en France n'en imprime aucune.
     */
    public function test_chaque_nature_imprime_la_mention_de_sa_reference(): void
    {
        foreach (NatureOperation::cases() as $nature) {
            $reference = ReferenceDeTva::deLaNature($nature);

            $this->assertSame(
                $reference === null ? null : TableDesMentionsDeTva::texte($reference, '2027-01-01'),
                TableDesMentionsDeTva::mentionDeLaNature($nature, '2027-01-01'),
                $nature->value,
            );
        }

        $this->assertNull(ReferenceDeTva::deLaNature(NatureOperation::VenteEnFrance));
    }

    /**
     * Un article dont le numéro du CIBS n'est pas confirmé n'entre pas dans
     * une version : il garde son texte du CGI, et seule une version
     * datée du jour où il est confirmé, au plus tard le lendemain de la fin
     * de la tolérance, le change, sans toucher aux documents des jours
     * d'avant.
     */
    public function test_un_article_non_confirme_garde_le_texte_du_cgi_jusqu_a_la_version_qui_le_nomme(): void
    {
        $origine = [
            ReferenceDeTva::MentionDeLExportHorsUe->value => 'Exonération de TVA, article 262 I du CGI',
            ReferenceDeTva::MentionDeLaLivraisonVersUnDom->value => 'Exonération de TVA en application de l\'article 294 du Code général des impôts',
        ];
        $versions = [
            '2027-01-01' => [ReferenceDeTva::MentionDeLExportHorsUe->value => 'Exonération de TVA, articles L. 213-12, 2° et L. 213-14 du CIBS'],
            '2028-07-01' => [ReferenceDeTva::MentionDeLaLivraisonVersUnDom->value => 'Exonération de TVA, articles L. 211-7, L. 213-12, 2° et L. 213-14 du CIBS'],
        ];
        $texte = fn (ReferenceDeTva $reference, string $jour): string => TableDesMentionsDeTva::texteDans($origine, $versions, $reference, $jour);

        $this->assertSame('Exonération de TVA, articles L. 213-12, 2° et L. 213-14 du CIBS', $texte(ReferenceDeTva::MentionDeLExportHorsUe, '2027-01-01'));
        $this->assertSame('Exonération de TVA en application de l\'article 294 du Code général des impôts', $texte(ReferenceDeTva::MentionDeLaLivraisonVersUnDom, '2027-01-01'));
        $this->assertSame('Exonération de TVA en application de l\'article 294 du Code général des impôts', $texte(ReferenceDeTva::MentionDeLaLivraisonVersUnDom, TableDesMentionsDeTva::DERNIER_JOUR_DES_ARTICLES_DU_CGI));
        $this->assertSame('Exonération de TVA, articles L. 211-7, L. 213-12, 2° et L. 213-14 du CIBS', $texte(ReferenceDeTva::MentionDeLaLivraisonVersUnDom, '2028-07-01'));
        $this->assertSame('Exonération de TVA, articles L. 213-12, 2° et L. 213-14 du CIBS', $texte(ReferenceDeTva::MentionDeLExportHorsUe, '2028-07-01'));
        $this->expectException(InvalidArgumentException::class);
        $texte(ReferenceDeTva::MentionDeLaFranchise, '2027-01-01');
    }

    /**
     * La règle de la table : ses versions se suivent dans l'ordre de leur
     * premier jour, chacune un jour du calendrier après le précédent ; la
     * seule version datée est aujourd'hui celle du CIBS, qu'un ajout
     * suivra.
     */
    public function test_les_versions_se_suivent_dans_l_ordre_de_leur_premier_jour(): void
    {
        $jours = TableDesMentionsDeTva::premiersJoursDesVersions();

        $this->assertSame([TableDesMentionsDeTva::PREMIER_JOUR_DU_CIBS], $jours);
        $this->assertSame('2027-01-01', TableDesMentionsDeTva::PREMIER_JOUR_DU_CIBS);
        $this->assertSame('2028-06-30', TableDesMentionsDeTva::DERNIER_JOUR_DES_ARTICLES_DU_CGI);

        foreach ($jours as $rang => $jour) {
            $this->assertSame($jour, Calendrier::jourValide($jour));
            $this->assertTrue($rang === 0 || strcmp($jours[$rang - 1], $jour) < 0, "La version du {$jour} suit la précédente.");
        }
    }

    /**
     * Jamais de référence au CIBS avant le 1er janvier 2027 ; à partir de ce
     * jour, aucun texte ne cite plus le CGI, et en tout cas aucun au-delà
     * de la fin de la tolérance des références au CGI.
     */
    public function test_aucun_texte_ne_cite_le_cibs_avant_sa_date_ni_le_cgi_apres(): void
    {
        $citeLeCgi = fn (string $texte): bool => preg_match('/\bdu CGI\b|code général des impôts/iu', $texte) === 1;
        $lendemainDuFilet = Calendrier::ajouterJours(TableDesMentionsDeTva::DERNIER_JOUR_DES_ARTICLES_DU_CGI, 1);

        foreach (ReferenceDeTva::cases() as $reference) {
            $veille = TableDesMentionsDeTva::texte($reference, Calendrier::ajouterJours(TableDesMentionsDeTva::PREMIER_JOUR_DU_CIBS, -1));

            $this->assertStringNotContainsString('CIBS', $veille, $reference->value);
            $this->assertSame($reference !== ReferenceDeTva::MentionDeLAutoliquidation, $citeLeCgi($veille), $reference->value);
            $this->assertFalse($citeLeCgi(TableDesMentionsDeTva::texte($reference, TableDesMentionsDeTva::PREMIER_JOUR_DU_CIBS)), $reference->value);
            $this->assertFalse($citeLeCgi(TableDesMentionsDeTva::texte($reference, $lendemainDuFilet)), $reference->value);
        }
    }

    /**
     * La table ne se lit qu'au jour d'un calendrier, « AAAA-MM-JJ ».
     *
     * @return array<string, array{string}>
     */
    public static function joursMalEcrits(): array
    {
        return [
            'jour à la française' => ['31/12/2026'],
            'jour qui n\'existe pas' => ['2026-02-30'],
            'jour sans zéro' => ['2027-1-1'],
            'vide' => [''],
        ];
    }

    #[DataProvider('joursMalEcrits')]
    public function test_un_jour_mal_ecrit_est_refuse(string $jour): void
    {
        $this->expectException(InvalidArgumentException::class);

        TableDesMentionsDeTva::texte(ReferenceDeTva::MentionDeLaFranchise, $jour);
    }
}
