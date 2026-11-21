<?php

namespace Tests\Feature\Contacts;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Actions\ModifierUneFicheAction;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Contacts\Support\DoublonDUneFiche;
use App\Domain\Tenancy\Support\FicheLue;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La modification d'une fiche qui reprend ce qu'une autre fiche du
 * magasin porte déjà, comme quand une autre personne vient de
 * l'enregistrer entre la validation du formulaire et l'écriture : l'index
 * unique de la table l'arrête, et l'action le refuse comme le
 * formulaire, avec un message, jamais par l'erreur de la base ; la fiche
 * reste telle qu'elle était.
 */
class DoublonDUneFicheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
    }

    /**
     * La fiche, la correction qui reprend ce qu'une autre fiche porte, le
     * refus attendu et l'attribut qui doit rester tel qu'il était.
     *
     * @return array<string, array{0: Closure(): Model, 1: array<string, string>, 2: string, 3: string}>
     */
    public static function doublons(): array
    {
        return [
            'le SIRET d\'un autre fournisseur' => [
                static fn (): Model => tap(Fournisseur::factory()->create(['siret' => null]), fn () => Fournisseur::factory()->create(['siret' => '73282932000074'])),
                ['siret' => '73282932000074'],
                DoublonDUneFiche::SIRET_PRIS,
                'siret',
            ],
            'le nom d\'un autre service' => [
                static fn (): Model => tap(Service::factory()->create(['nom' => 'Réparation']), fn () => Service::factory()->create(['nom' => 'Diagnostic'])),
                ['nom' => 'diagnostic'],
                DoublonDUneFiche::NOM_DE_SERVICE_PRIS,
                'nom',
            ],
            'la référence d\'un autre produit' => [
                static fn (): Model => tap(Produit::factory()->create(['reference' => 'COQUE-1']), fn () => Produit::factory()->create(['reference' => 'ECRAN-1'])),
                ['reference' => 'ecran-1'],
                'La référence « ecran-1 » est déjà celle d\'un autre produit.',
                'reference',
            ],
        ];
    }

    /**
     * @param  Closure(): Model  $creer
     * @param  array<string, string>  $saisie
     */
    #[DataProvider('doublons')]
    public function test_une_modification_qui_reprend_ce_qu_une_autre_fiche_porte_est_refusee_sans_rien_ecrire(Closure $creer, array $saisie, string $refus, string $attribut): void
    {
        $fiche = $creer();
        $avant = $fiche->fresh()->getAttribute($attribut);

        $this->assertThrows(
            fn () => app(ModifierUneFicheAction::class)->handle(self::auteur(), $fiche, FicheLue::de($fiche)->valeurs(), $saisie),
            LogicException::class,
            $refus,
        );

        $this->assertSame($avant, $fiche->fresh()->getAttribute($attribut));
        $this->assertSame(2, $fiche::query()->count());
    }
}
