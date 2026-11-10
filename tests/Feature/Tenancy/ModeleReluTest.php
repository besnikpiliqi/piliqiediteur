<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\ModeleRelu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * Un modèle relu en base (voir ModeleRelu) : une copie à jour, avec les
 * relations demandées ; un modèle qui n'existe pas en base est refusé.
 */
class ModeleReluTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_modele_est_relu_a_jour_avec_les_relations_demandees(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        DB::table('magasins')->where('id', $magasin->getKey())->update(['nom' => 'Mon Atelier']);

        $relu = ModeleRelu::de($magasin, 'societe');

        $this->assertNotSame($magasin, $relu);
        $this->assertTrue($relu->is($magasin));
        $this->assertSame('Mon Atelier', $relu->nom);
        $this->assertSame('Ma Boutique', $magasin->nom);
        $this->assertTrue($relu->relationLoaded('societe'));
        $this->assertFalse(ModeleRelu::de($magasin)->relationLoaded('societe'));
    }

    public function test_un_modele_qui_n_existe_pas_en_base_est_refuse(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(ModeleRelu::REFUS, Magasin::class));

        ModeleRelu::de(new Magasin);
    }
}
