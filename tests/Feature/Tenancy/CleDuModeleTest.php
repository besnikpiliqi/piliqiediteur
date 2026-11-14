<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\CleDuModele;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * La clé d'un enregistrement (voir CleDuModele) : l'entier qu'Eloquent
 * rend pour un enregistrement en base, même lue en texte ; un modèle qui
 * n'est pas en base est refusé.
 */
class CleDuModeleTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_cle_d_un_enregistrement_en_base_est_rendue_en_entier(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $cle = $magasin->getKey();

        $this->assertIsInt($cle);
        $this->assertSame($cle, CleDuModele::de($magasin));
        $this->assertSame($cle, CleDuModele::de((new Magasin)->setRawAttributes(['id' => (string) $cle])));
    }

    public function test_un_modele_qui_n_est_pas_en_base_est_refuse(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(CleDuModele::REFUS, Magasin::class, 'null'));

        CleDuModele::de(new Magasin);
    }
}
