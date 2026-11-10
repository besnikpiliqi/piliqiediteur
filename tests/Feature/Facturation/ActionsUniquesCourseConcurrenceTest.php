<?php

namespace Tests\Feature\Facturation;

use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Models\ActionTraitee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * La course que seul l'index unique (magasin, jeton) arrête (R3) : deux
 * demandes du même jeton qu'aucun verrou ne fait passer l'une après
 * l'autre. L'autre demande, dans son processus, s'inscrit et se valide
 * pendant celle du test, qui ne l'a pas encore trouvée ; l'inscription du
 * test bute alors sur l'index : sa demande est annulée en entier, puis
 * relit le jeton et rend le résultat de l'autre, pourvu qu'elle soit la
 * même, sinon elle est refusée (voir ActionsUniques).
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé, et le test voit ce que l'autre a validé.
 */
class ActionsUniquesCourseConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    private const ACTION = 'essai.course';

    private const PARAMETRES = ['nom' => 'Atelier Dupont'];

    public function test_la_demande_qui_perd_la_course_est_annulee_et_rend_le_resultat_de_l_autre(): void
    {
        [$auteur, $resultatDeLAutre, $autre] = $this->uneAutreDemandeDuMemeJeton(self::PARAMETRES, etapes: false);

        $resultat = app(ActionsUniques::class)->executer($autre['jeton'], self::ACTION, Client::class, $auteur, self::PARAMETRES, static fn (): null => null, fn (): Client => $this->pendantLAutreDemande($autre['operation']));

        $this->assertTrue($resultat->is($resultatDeLAutre));
        $this->assertSame([0, 1], [Client::query()->where('nom', 'Client de la demande qui perd')->count(), ActionTraitee::query()->count()]);
    }

    public function test_l_etape_qui_perd_la_course_est_annulee_et_rend_le_resultat_de_l_autre_deja_enregistre(): void
    {
        [$auteur, $resultatDeLAutre, $autre] = $this->uneAutreDemandeDuMemeJeton(self::PARAMETRES, etapes: true);

        $resultat = app(ActionsUniques::class)->executerParEtapes($autre['jeton'], self::ACTION, Client::class, $auteur, self::PARAMETRES, static fn (): null => null, fn (): array => [$this->pendantLAutreDemande($autre['operation']), true]);

        $this->assertInstanceOf(Client::class, $resultat['resultat']);
        $this->assertTrue($resultat['resultat']->is($resultatDeLAutre));
        $this->assertTrue($resultat['deja_enregistree']);
        $this->assertSame([0, 1], [Client::query()->where('nom', 'Client de la demande qui perd')->count(), ActionTraitee::query()->count()]);
    }

    public function test_la_demande_qui_perd_la_course_contre_une_demande_differente_est_refusee_et_annulee(): void
    {
        [$auteur, , $autre] = $this->uneAutreDemandeDuMemeJeton(['nom' => 'Atelier Martin'], etapes: false);

        $this->assertThrows(
            fn () => app(ActionsUniques::class)->executer($autre['jeton'], self::ACTION, Client::class, $auteur, self::PARAMETRES, static fn (): null => null, fn (): Client => $this->pendantLAutreDemande($autre['operation'])),
            LogicException::class,
            ActionsUniques::REFUS,
        );

        $this->assertSame([0, 1], [Client::query()->where('nom', 'Client de la demande qui perd')->count(), ActionTraitee::query()->count()]);
    }

    /**
     * Un magasin, l'auteur des deux demandes, le client que rend celle de
     * l'autre processus, et cette autre demande : son jeton et l'opération
     * qui la fait, de bout en bout, avec ces paramètres, par executer() ou
     * par une seule étape d'executerParEtapes(), sans verrou.
     *
     * @param  array<string, string>  $parametres
     * @return array{0: User, 1: Client, 2: array{jeton: string, operation: Closure(): void}}
     */
    private function uneAutreDemandeDuMemeJeton(array $parametres, bool $etapes): array
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $auteur = self::responsableDeTest($magasin);
        $client = Client::factory()->create(['nom' => 'Client de l\'autre demande']);
        $jeton = (string) Str::uuid();
        [$magasinId, $auteurId, $clientId] = [$magasin->id, $auteur->id, $client->id];

        return [$auteur, $client, ['jeton' => $jeton, 'operation' => static function () use ($magasinId, $auteurId, $clientId, $jeton, $parametres, $etapes): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            $auteur = User::query()->findOrFail($auteurId);
            $client = static fn (): Client => Client::query()->findOrFail($clientId);

            $etapes
                ? app(ActionsUniques::class)->executerParEtapes($jeton, self::ACTION, Client::class, $auteur, $parametres, static fn (): null => null, static fn (): array => [$client(), true])
                : app(ActionsUniques::class)->executer($jeton, self::ACTION, Client::class, $auteur, $parametres, static fn (): null => null, $client);
        }]];
    }

    /**
     * L'opération de la demande du test : elle attend que l'autre demande,
     * lancée dans son processus, se soit inscrite et validée, puis crée son
     * propre client, que l'annulation de sa demande effacera.
     *
     * @param  Closure(): void  $autre
     */
    private function pendantLAutreDemande(Closure $autre): Client
    {
        $resultat = $this->demarrerUnProcessusConcurrent($autre)->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());

        return Client::factory()->create(['nom' => 'Client de la demande qui perd']);
    }
}
