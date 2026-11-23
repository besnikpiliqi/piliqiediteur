<?php

namespace Tests\Feature;

use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\ReferenceDeTva;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\EReportingDesParticuliers;
use App\Domain\Facturation\Services\MentionsFiscales;
use App\Domain\Facturation\Support\TableDesMentionsDeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\Semis\SemisComplet;
use Database\Seeders\Semis\SocietesDesCas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Tests\SemeSansTransaction;
use Tests\TestCase;

/**
 * Les mentions de TVA de part et d'autre du 1er janvier 2027 dans le semis
 * (règle 6 de CLAUDE.md, décisions de Claude T40.1 et T40.2, D76) : le
 * semis date ses documents par décalage depuis son horloge et finit la
 * veille de son départ ; lancé après le 2 janvier 2027, son histoire
 * traverse le changement des articles de la TVA, et chaque mention s'y
 * trouve des deux côtés. Il tourne ici le 3 janvier 2027 à 1 h 10, heure
 * où le site de démonstration refait chaque nuit ses bases modèles : le
 * premier semis dont l'histoire contient la veille et le jour même, qui
 * sont aussi les jours des derniers gestes des démonstrations des
 * sociétés ; puis le 15 février 2027 à 10 h, six semaines plus tard.
 * Chaque fois : un export hors de l'Union, une livraison vers un DOM et
 * une livraison intracommunautaire des Halles,
 * une prestation autoliquidée et une prestation hors de l'Union du magasin
 * de services, les tickets et les devis de l'Atelier Brun, en franchise en
 * base, et les exports de Fort-de-France, dont celui du 31 décembre 2026 à
 * 20 h, quand Paris est déjà le 1er janvier ; chaque document semé signe
 * les articles du jour de son émission, au calendrier de son magasin ; le
 * jour même, l'avoir d'une partie de l'export de la veille cite le CIBS ;
 * l'aperçu de l'e-reporting de chaque journée de l'Atelier cite la
 * franchise de son jour, et chaque devis se relit avec la mention du jour
 * de son envoi ; l'isolation de la base est vérifiée à zéro problème à la
 * fin du semis. Le semis prend plusieurs minutes : groupe « semis », hors
 * de la suite par défaut (phpunit.xml).
 */
#[Group('semis')]
class SemisDesMentionsDeTvaTest extends TestCase
{
    use RefreshDatabase;
    use SemeSansTransaction;

    /**
     * Le premier semis dont l'histoire contient la veille et le jour même :
     * le 3 janvier 2027 à 1 h 10, quand le site de démonstration refait ses
     * bases modèles ; ses deux derniers jours suivants, où les
     * démonstrations des sociétés font leurs derniers gestes, sont le
     * 31 décembre 2026 et le 1er janvier 2027.
     */
    public function test_le_premier_semis_qui_traverse_le_1er_janvier_2027_seme_chaque_mention_des_deux_cotes(): void
    {
        $this->semerEtVerifier(self::aParis('2027-01-03 01:10:00'));
    }

    /**
     * Le semis lancé six semaines plus tard, le 15 février 2027 à 10 h.
     */
    public function test_le_semis_lance_apres_le_1er_janvier_2027_seme_chaque_mention_des_deux_cotes(): void
    {
        $this->semerEtVerifier(self::aParis('2027-02-15 10:00:00'));
    }

    /**
     * Sème la base à cet instant, puis vérifie chaque mention des deux
     * côtés du 1er janvier 2027.
     */
    private function semerEtVerifier(CarbonImmutable $depart): void
    {
        config(['piliqi.semis_mot_de_passe' => null]);
        $this->travelTo($depart);

        Storage::fake('local');
        Storage::fake(PiecesDuFournisseur::DISQUE);
        $this->artisan('db:seed')->expectsOutputToContain('Isolation vérifiée')->assertSuccessful();

        $cotes = [];

        foreach (Magasin::query()->withoutGlobalScopes()->with('societe')->orderBy('id')->get() as $magasin) {
            app(TenantContext::class)->runAs($magasin, function () use ($magasin, &$cotes): void {
                foreach ([Facture::query()->whereNotNull('numero')->get(), Avoir::query()->whereNotNull('numero')->get()] as $documents) {
                    foreach ($documents as $document) {
                        $this->verifierLaMentionSignee($magasin, $document, $cotes);
                    }
                }
            });
        }

        $attendus = [];
        $vus = [];

        foreach ([ReferenceDeTva::MentionDeLExportHorsUe, ReferenceDeTva::MentionDeLaLivraisonVersUnDom, ReferenceDeTva::MentionDeLaLivraisonIntracommunautaire, ReferenceDeTva::MentionDeLAutoliquidation, ReferenceDeTva::MentionDeLaPrestationHorsUe, ReferenceDeTva::MentionDeLaFranchise] as $reference) {
            $attendus[$reference->value] = ['avant' => true, 'apres' => true];
            $vus[$reference->value] = ['avant' => isset($cotes[$reference->value]['avant']), 'apres' => isset($cotes[$reference->value]['apres'])];
        }

        $this->assertSame($attendus, $vus, 'Chaque mention a un document de chaque côté du 1er janvier 2027.');

        $this->assertLExportDeFortDeFranceDuSoirDeLaVeille();
        $this->assertLAvoirDuJourMemeSurLExportDeLaVeille();
        $this->assertLAtelierDeLaVeilleEtDuJourMeme();
    }

    /**
     * Le document émis signe, pour sa nature et pour la franchise en base,
     * le texte de la table au jour de son émission, au calendrier de son
     * magasin, et se range du côté de ce jour.
     *
     * @param  array<string, array{avant?: bool, apres?: bool}>  $cotes
     */
    private function verifierLaMentionSignee(Magasin $magasin, Facture|Avoir $document, array &$cotes): void
    {
        $jour = self::jourDEmission($magasin, $document);
        $cote = strcmp($jour, TableDesMentionsDeTva::PREMIER_JOUR_DU_CIBS) < 0 ? 'avant' : 'apres';
        $mentions = app(MentionsFiscales::class);
        $reference = ReferenceDeTva::deLaNature($document->nature_operation);

        if ($reference !== null) {
            $this->assertSame(TableDesMentionsDeTva::texte($reference, $jour), $mentions->mentionDeLaNature($document), "{$document->numero} du {$jour}");
            $cotes[$reference->value][$cote] = true;
        }

        if ($document->franchise_en_base) {
            $this->assertContains(TableDesMentionsDeTva::mentionDeLaFranchise($jour), $document->mentionsSignees(), "{$document->numero} du {$jour}");
            $cotes[ReferenceDeTva::MentionDeLaFranchise->value][$cote] = true;
        }
    }

    /**
     * L'export de Fort-de-France (Maison Ferrand) émis le 31 décembre 2026
     * à 20 h, heure de la Martinique, quand Paris est déjà le 1er janvier
     * 2027, cite l'article du CGI ; celui du soir du 1er janvier, du CIBS.
     */
    private function assertLExportDeFortDeFranceDuSoirDeLaVeille(): void
    {
        $fortDeFrance = $this->magasin(SemisComplet::SIREN_COMMERCE, 'FDF');

        app(TenantContext::class)->runAs($fortDeFrance, function () use ($fortDeFrance): void {
            $exports = Facture::query()->whereNotNull('numero')->where('nature_operation', NatureOperation::ExportHorsUe->value)->orderBy('date_emission')->get();
            $jours = $exports->map(fn (Facture $export): array => [self::jourDEmission($fortDeFrance, $export), self::jourDEmission(null, $export), app(MentionsFiscales::class)->mentionDeLaNature($export)])->all();

            $this->assertSame([
                ['2026-12-31', '2027-01-01', 'Exonération de TVA, article 262 I du CGI'],
                ['2027-01-01', '2027-01-01', 'Exonération de TVA, articles L. 213-12, 2° et L. 213-14 du CIBS'],
            ], $jours);
        });
    }

    /**
     * Le 1er janvier 2027, la cliente américaine des Halles rend une tasse
     * de l'export du 31 décembre : l'avoir cite le CIBS, l'export qu'il
     * corrige garde le CGI.
     */
    private function assertLAvoirDuJourMemeSurLExportDeLaVeille(): void
    {
        $halles = $this->magasin(SemisComplet::SIREN_COMMERCE, 'HALLES');

        app(TenantContext::class)->runAs($halles, function () use ($halles): void {
            $avoir = Avoir::query()->whereNotNull('numero')->where('motif', 'Une tasse arrivée fêlée, rendue au transporteur.')->sole();
            $export = $avoir->facturesConcernees()->sole();

            $this->assertSame(['2027-01-01', '2026-12-31'], [self::jourDEmission($halles, $avoir), self::jourDEmission($halles, $export)]);
            $this->assertSame(['Exonération de TVA, articles L. 213-12, 2° et L. 213-14 du CIBS', 'Exonération de TVA, article 262 I du CGI'], [app(MentionsFiscales::class)->mentionDeLaNature($avoir), app(MentionsFiscales::class)->mentionDeLaNature($export)]);
        });
    }

    /**
     * À l'Atelier Brun, en franchise en base : le devis envoyé le
     * 31 décembre 2026 se relit avec l'article du CGI, celui du 1er janvier
     * 2027 avec celui du CIBS ; l'aperçu de l'e-reporting de la clôture de
     * chacune de ces journées cite la franchise de son jour.
     */
    private function assertLAtelierDeLaVeilleEtDuJourMeme(): void
    {
        $atelier = $this->magasin(SocietesDesCas::SIREN_ATELIER, 'ATELIER');

        app(TenantContext::class)->runAs($atelier, function () use ($atelier): void {
            $devis = Devis::query()->with('magasin')->whereHas('client', fn ($client) => $client->where('nom', 'Odile Marchand'))->orderBy('date_emission')->get();
            $mentions = $devis->map(fn (Devis $unDevis): array => [app(MentionsFiscales::class)->jourDesMentions($unDevis), array_values(array_filter(app(MentionsFiscales::class)->imprimees($unDevis, $unDevis->emetteur()), fn (string $mention): bool => str_starts_with($mention, 'TVA non applicable')))])->all();

            $this->assertSame([
                ['2026-12-31', ['TVA non applicable, art. 293 B du CGI']],
                ['2027-01-01', ['TVA non applicable, art. L. 233-3 du CIBS']],
            ], $mentions);

            $motifs = [];

            foreach (['2026-12-31', '2027-01-01'] as $jour) {
                $cloture = ClotureCaisse::query()->where('magasin_id', $atelier->getKey())->where('date_cloture', $jour)->sole();
                $motifs[$jour] = array_values(array_unique(array_column(app(EReportingDesParticuliers::class)->apercu($cloture)->transactions, 'motif')));
            }

            $this->assertSame(['2026-12-31' => ['Franchise en base de TVA (art. 293 B du CGI)'], '2027-01-01' => ['Franchise en base de TVA (art. L. 233-3 du CIBS)']], $motifs);
        });
    }

    /**
     * Le jour de l'émission du document, au calendrier de ce magasin, ou de
     * Paris sans magasin.
     *
     * @throws LogicException pour un document sans date d'émission
     */
    private static function jourDEmission(?Magasin $magasin, Facture|Avoir $document): string
    {
        $emission = $document->date_emission ?? throw new LogicException("Le document {$document->numero} n'a pas de date d'émission.");

        return ($magasin?->calendrier() ?? Calendrier::deParis())->jourDe($emission);
    }

    /**
     * Le magasin de ce code de la société de ce SIREN.
     */
    private function magasin(string $siren, string $code): Magasin
    {
        $societe = Societe::query()->where('siren', $siren)->sole();

        return Magasin::query()->withoutGlobalScopes()->where('societe_id', $societe->getKey())->where('code', $code)->sole();
    }
}
