<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Support\ExpressionDUneColonne;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Une expression SQL du code autour d'une colonne (voir
 * ExpressionDUneColonne) : la grammaire de PostgreSQL écrit à la place de
 * chaque « {colonne} » le nom de la colonne protégé, qualifié par sa table,
 * et le constructeur de requêtes l'accepte comme toute expression.
 */
class ExpressionDUneColonneTest extends TestCase
{
    public function test_chaque_marque_devient_la_colonne_protegee_par_la_grammaire(): void
    {
        $grammaire = DB::connection()->getQueryGrammar();

        $this->assertSame('lower("clients"."nom") || "clients"."nom"', (new ExpressionDUneColonne('lower('.ExpressionDUneColonne::COLONNE.') || '.ExpressionDUneColonne::COLONNE, 'clients.nom'))->getValue($grammaire));
        $this->assertSame('"nom""; drop table clients; --" is null', (new ExpressionDUneColonne(ExpressionDUneColonne::COLONNE.' is null', 'nom"; drop table clients; --'))->getValue($grammaire));
    }

    public function test_le_constructeur_de_requetes_l_execute_comme_toute_expression(): void
    {
        $noms = DB::query()
            ->fromRaw("(values ('Pain'), ('Lait'), ('PAIN')) as t(nom)")
            ->where(new ExpressionDUneColonne('lower('.ExpressionDUneColonne::COLONNE.')', 't.nom'), 'pain')
            ->orderBy(new ExpressionDUneColonne(ExpressionDUneColonne::COLONNE.' collate "C"', 't.nom'))
            ->pluck('nom')
            ->all();

        $this->assertSame(['PAIN', 'Pain'], $noms);
    }
}
