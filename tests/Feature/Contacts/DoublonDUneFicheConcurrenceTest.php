<?php

namespace Tests\Feature\Contacts;

use App\Domain\Contacts\Actions\CreerUneFicheAction;
use App\Domain\Contacts\Actions\ModifierUneFicheAction;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Contacts\Support\DoublonDUneFiche;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\FicheLue;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Un collègue enregistre un fournisseur avec un SIRET pendant qu'une
 * autre personne donne ce même SIRET à un fournisseur existant, chacun
 * dans son processus, sur une vraie base PostgreSQL. La modification et
 * la création relisent toutes deux la ligne du magasin sous un verrou
 * partagé (voir ModifierUneFicheAction et CreerUneFicheAction) : la
 * création passe pendant la modification, déjà validée par son
 * formulaire, et l'index unique (magasin_id, siret) arrête la
 * modification au moment d'écrire. Elle est refusée comme par le
 * formulaire, jamais par l'erreur de la base, et la fiche reste telle
 * qu'elle était.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class DoublonDUneFicheConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    #[Group('concurrence')]
    public function test_un_siret_enregistre_par_un_collegue_pendant_une_modification_la_fait_refuser_avec_un_message(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = self::responsableDeTest($magasin);
        $fournisseur = Fournisseur::factory()->create(['nom' => 'Grossiste', 'siret' => null]);
        $magasinId = $magasin->id;
        $creation = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('magasins', 'for share', static function () use ($magasinId): void {
            $magasin = Magasin::query()->findOrFail($magasinId);
            app(TenantContext::class)->set($magasin);
            app(CreerUneFicheAction::class)->handle(User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(), Fournisseur::class, ['nom' => 'Grossiste du Sud', 'siret' => '73282932000074']);
        }, $creation);

        $this->assertThrows(
            fn () => app(ModifierUneFicheAction::class)->handle($responsable, $fournisseur, FicheLue::de($fournisseur)->valeurs(), ['siret' => '73282932000074']),
            LogicException::class,
            DoublonDUneFiche::SIRET_PRIS,
        );

        $this->assertInstanceOf(InvokedProcess::class, $creation);
        $resultat = $creation->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertNull($fournisseur->fresh()->siret);
        $this->assertSame(['Grossiste du Sud'], Fournisseur::query()->where('siret', '73282932000074')->pluck('nom')->all());
    }
}
