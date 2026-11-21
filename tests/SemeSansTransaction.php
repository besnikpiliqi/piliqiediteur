<?php

namespace Tests;

use App\Integrations\Vies\Contracts\ServiceVies;
use Illuminate\Support\Facades\DB;

/**
 * Pour les tests du semis complet (groupe « semis ») : la base de test
 * n'est pas enveloppée dans une transaction, et le semis valide ses gestes
 * un à un, comme php artisan migrate:fresh --seed. Une seule transaction
 * de plusieurs minutes garderait chaque version des lignes que le semis
 * modifie sans cesse (têtes du registre, séquences, stocks), que chaque
 * lecture parcourrait : le semis ne finirait pas. Après le test, la base
 * est recréée vide (migrate:fresh), pour les tests suivants du même
 * processus, qui la trouvent migrée comme RefreshDatabase la laisse.
 */
trait SemeSansTransaction
{
    /**
     * Aucune connexion ouverte en transaction par RefreshDatabase.
     *
     * @var list<string|null>
     */
    protected array $connectionsToTransact = [];

    /**
     * RefreshDatabase remplace le gestionnaire des transactions de
     * l'application (db.transactions) sans le donner à la connexion qu'il
     * n'enveloppe pas : la connexion reçoit le même, pour que les
     * transactions du semis s'y voient (le registre fiscal ne se
     * verrouille que dans une transaction qu'il voit) et que les rappels
     * après validation partent à la validation de chacune. Le semis exige
     * le faux VIES, comme les autres fournisseurs factices de phpunit.xml,
     * où les tests prennent le vrai, qu'ils simulent (Http::fake).
     */
    protected function setUpSemeSansTransaction(): void
    {
        DB::connection()->setTransactionManager(app('db.transactions'));
        config(['services.vies.pilote' => 'faux']);
        app()->forgetInstance(ServiceVies::class);
    }

    protected function tearDownSemeSansTransaction(): void
    {
        $this->artisan('migrate:fresh');
    }
}
