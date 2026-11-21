<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Tests\DemonstrationTestCase;

/**
 * Toutes les cartes du tableau de bord ouvertes dans la démonstration
 * (précision de l'éditeur du 16/11/2026 vers 23 h 59) : sur le site de
 * démonstration, un compte qui n'a encore rien choisi voit toutes les
 * cartes que son écran permet, dans l'ordre du catalogue, chacune à son
 * type par défaut ; une préférence enregistrée prime. Le site réel garde
 * ses quelques cartes principales (voir PreferencesDesGraphiquesTest).
 */
class CartesOuvertesDansLaDemonstrationTest extends DemonstrationTestCase
{
    public function test_chaque_ecran_de_la_copie_ouvre_toutes_les_cartes_que_le_compte_voit(): void
    {
        foreach ([TypeDActivite::Commerce, TypeDActivite::Services] as $type) {
            ['copie' => $copie] = $this->copieDeTest($type);

            app(OuvertureDUneCopie::class)->pendant($copie, function () use ($type): void {
                $magasin = Magasin::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
                $societe = Societe::query()->whereKey($magasin->societe_id)->sole();
                $proprietaire = User::query()->where('societe_id', $societe->getKey())->where('role_societe', RoleSociete::Proprietaire->value)->sole();
                $graphiques = app(PerimetreDesGraphiques::class);
                $contexte = app(TenantContext::class);

                foreach ([
                    $contexte->runAsSociete($societe, fn () => $graphiques->deLaSociete($proprietaire, $societe)),
                    $contexte->runAs($magasin, fn () => $graphiques->duMagasin($proprietaire, $magasin)),
                ] as $perimetre) {
                    $attendues = array_values(array_filter(Graphique::cases(), fn (Graphique $graphique): bool => $perimetre->voit($graphique)));
                    $cartes = ReglagesDuTableauDeBord::parDefaut($perimetre)->cartes;

                    $this->assertGreaterThan(4, count($attendues));
                    $this->assertSame(
                        array_map(fn (Graphique $graphique): string => $graphique->value, $attendues),
                        array_map(fn (CarteDuTableauDeBord $carte): string => $carte->graphique->value, $cartes),
                        "Copie « {$type->value} », écran {$perimetre->ecran->value} : toutes les cartes permises, dans l'ordre du catalogue.",
                    );
                    $this->assertEquals(array_map(fn (Graphique $graphique): CarteDuTableauDeBord => CarteDuTableauDeBord::parDefaut($graphique), $attendues), $cartes, 'Chacune à son type par défaut.');
                    $this->assertSame([Graphique::ChiffreDAffairesParMois->value], array_map(
                        fn (CarteDuTableauDeBord $carte): string => $carte->graphique->value,
                        ReglagesDuTableauDeBord::lire(['cartes' => [['graphique' => Graphique::ChiffreDAffairesParMois->value, 'type' => 'barres']]], $perimetre)->cartes,
                    ), 'Une préférence enregistrée prime : la carte fermée reste fermée.');
                }
            });
        }
    }
}
