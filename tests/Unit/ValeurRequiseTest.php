<?php

namespace Tests\Unit;

use App\Domain\Tenancy\Support\ValeurRequise;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Une valeur requise (voir ValeurRequise) : rendue telle quelle quand elle
 * n'est pas nulle, même vide ou fausse ; nulle, elle est refusée par une
 * exception qui la nomme.
 */
class ValeurRequiseTest extends TestCase
{
    public function test_une_valeur_non_nulle_est_rendue_telle_quelle_meme_vide_ou_fausse(): void
    {
        $this->assertSame('2026-11-10', ValeurRequise::de('2026-11-10', 'le jour'));
        $this->assertSame('', ValeurRequise::de('', 'le texte'));
        $this->assertSame(0, ValeurRequise::de(0, 'le nombre'));
        $this->assertFalse(ValeurRequise::de(false, 'le choix'));
        $this->assertSame([], ValeurRequise::de([], 'la liste'));
    }

    public function test_une_valeur_nulle_est_refusee_avec_son_nom(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ValeurRequise::REFUS, "l'échéance de la facture émise"));

        ValeurRequise::de(null, "l'échéance de la facture émise");
    }
}
