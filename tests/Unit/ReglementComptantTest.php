<?php

namespace Tests\Unit;

use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Le règlement d'une vente encaissé à son émission (voir
 * ReglementComptant), sans base ni application : à la caisse, jamais par
 * prélèvement ; hors de la caisse, dans chacun des cinq modes, sans bon
 * d'avoir ; jamais deux parts dans le même mode ; et l'écart que l'écran
 * de la caisse montre entre les parts et ce qui reste à payer. Un petit
 * fichier rapide : Infection y trouve le test qui arrête un mutant de ces
 * règles avant son délai (voir le README).
 */
class ReglementComptantTest extends TestCase
{
    public function test_a_la_caisse_le_prelevement_est_refuse_et_hors_de_la_caisse_chaque_mode_regle(): void
    {
        $this->assertRefuse(fn () => new ReglementComptant([new PartDeReglement(ModePaiement::Especes, 500), new PartDeReglement(ModePaiement::Prelevement, 100)]), 'Le prélèvement ne règle pas une vente à la caisse.');

        $aLaCaisse = new ReglementComptant([new PartDeReglement(ModePaiement::Especes, 500), new PartDeReglement(ModePaiement::CarteBancaire, 250)]);
        $horsDeLaCaisse = ReglementComptant::horsDeLaCaisse([new PartDeReglement(ModePaiement::Prelevement, 100), new PartDeReglement(ModePaiement::Virement, 900)]);

        $this->assertSame([true, 750], [$aLaCaisse->aLaCaisse, $aLaCaisse->totalDesParts()]);
        $this->assertSame([false, 1000, [ModePaiement::Prelevement, ModePaiement::Virement]], [
            $horsDeLaCaisse->aLaCaisse,
            $horsDeLaCaisse->totalDesParts(),
            array_map(fn (PartDeReglement $part): ModePaiement => $part->mode, $horsDeLaCaisse->parts),
        ]);
    }

    /**
     * Chaque mode ne règle qu'une part ; un bon d'avoir ne règle qu'une
     * vente à la caisse, où son numéro se lit en majuscules, sans espaces
     * autour, dans l'ordre de la saisie.
     */
    public function test_un_mode_ne_regle_qu_une_part_et_un_bon_d_avoir_ne_se_presente_qu_a_la_caisse(): void
    {
        $this->assertRefuse(fn () => new ReglementComptant([new PartDeReglement(ModePaiement::Cheque, 500), new PartDeReglement(ModePaiement::Cheque, 100)]), 'Chaque mode ne règle qu\'une part du règlement.');
        $this->assertRefuse(fn () => new ReglementComptant([new PartDeReglement(ModePaiement::Virement, 500)], ['AV-LYON1-2026-000001'], false), Avoir::BON_D_AVOIR_HORS_DE_LA_CAISSE);

        $this->assertSame(['AV-LYON1-2026-000002', 'AV-LYON1-2026-000001'], (new ReglementComptant([], [' av-lyon1-2026-000002 ', 'AV-LYON1-2026-000001']))->bonsDAvoir);
    }

    public function test_l_ecart_entre_les_parts_et_ce_qui_reste_a_payer_se_dit_en_euros(): void
    {
        $this->assertSame('Il reste 12,50 € à répartir.', ReglementComptant::ecartEntre(1000, 2250));
        $this->assertSame('Les parts dépassent le total de 0,01 €.', ReglementComptant::ecartEntre(2251, 2250));
        $this->assertNull(ReglementComptant::ecartEntre(2250, 2250));
    }

    private function assertRefuse(Closure $reglement, string $message): void
    {
        try {
            $reglement();
        } catch (LogicException $refus) {
            $this->assertSame($message, $refus->getMessage());

            return;
        }

        $this->fail("Le règlement devait être refusé : {$message}");
    }
}
