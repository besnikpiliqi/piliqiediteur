<?php

namespace Tests\Feature\CaisseSecurisee;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Chaque table que sa migration protège (voir DeclencheursDeProtection)
 * refuse elle-même d'être vidée, même par le TRUNCATE ... CASCADE de
 * Laravel, avec le message de sa protection : la migration a bien posé
 * ses déclencheurs. Ce que chaque protection refuse d'autre (modifier,
 * supprimer une ligne, avant ou après son expiration) se vérifie sur une
 * table de test (DeclencheursDeProtectionTest) et sur les tables de
 * l'application (voir les tests de chacune).
 */
class ProtectionDesTablesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaque table protégée par sa migration, et son refus du vidage.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tablesProtegees(): array
    {
        $ajoutSeul = ['changements_fin_exercice', 'consultations_vies', 'preuves_operation', 'bordereaux_detaxe', 'recus_paiement', 'paiements', 'paiement_allocations', 'lignes_recu_paiement', 'avoir_allocations', 'enregistrements_fiscaux'];
        $sansEffacementPermis = ['societes', 'magasins', 'sirets_magasins', 'periodes_activite', 'tetes_journal_audit', 'pieces_verification', 'cabinets', 'liens_cabinet', 'journal_audit', 'traces_audit', 'verifications_societe', 'traces_evenements', 'codes_confirmation'];

        return [
            ...array_combine($ajoutSeul, array_map(static fn (string $table): array => [$table, "{$table} : table en ajout seul, vidage refusé"], $ajoutSeul)),
            ...array_combine($sansEffacementPermis, array_map(static fn (string $table): array => [$table, "{$table} : vidage refusé"], $sansEffacementPermis)),
        ];
    }

    #[DataProvider('tablesProtegees')]
    public function test_chaque_table_protegee_refuse_d_etre_videe(string $table, string $refus): void
    {
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table($table)->truncate()), QueryException::class, $refus);
    }
}
