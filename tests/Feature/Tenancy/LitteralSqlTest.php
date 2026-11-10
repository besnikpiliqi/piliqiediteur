<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Support\LitteralSql;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Un texte du code écrit en littéral SQL entre dollars (voir LitteralSql) :
 * PostgreSQL le relit tel quel, apostrophes et barres obliques inverses
 * comprises, dans une requête comme dans le corps d'une fonction entre
 * dollars ; un texte vide ou qui contient un dollar, qui pourrait le
 * terminer, est refusé.
 */
class LitteralSqlTest extends TestCase
{
    public function test_postgresql_relit_tel_quel_le_texte_ecrit_entre_dollars(): void
    {
        $relu = DB::selectOne('SELECT '.LitteralSql::de('une pièce ne s\'efface que d\'un brouillon \\ ; fin').' AS texte');

        $this->assertSame('$texte$une pièce ne s\'efface que d\'un brouillon \\ ; fin$texte$', LitteralSql::de('une pièce ne s\'efface que d\'un brouillon \\ ; fin'));
        $this->assertSame('une pièce ne s\'efface que d\'un brouillon \\ ; fin', $relu->texte);
    }

    public function test_le_corps_d_une_fonction_entre_dollars_garde_le_texte_tel_quel(): void
    {
        DB::beginTransaction();

        try {
            DB::unprepared('CREATE FUNCTION pg_temp.litteral_entre_dollars() RETURNS text LANGUAGE sql AS $$ SELECT '.LitteralSql::de('l\'apostrophe et la barre \\').' $$');
            $relu = DB::selectOne('SELECT pg_temp.litteral_entre_dollars() AS texte');
        } finally {
            DB::rollBack();
        }

        $this->assertSame('l\'apostrophe et la barre \\', $relu->texte);
    }

    public function test_un_texte_qui_contient_un_dollar_est_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(LitteralSql::REFUS_DOLLAR);

        LitteralSql::de('100 $');
    }

    /**
     * Vide, il s'écrirait « $texte$$texte$ », où PostgreSQL lirait la fin du
     * corps entre dollars de la fonction qui le contient.
     */
    public function test_un_texte_vide_est_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(LitteralSql::REFUS_VIDE);

        LitteralSql::de('');
    }
}
