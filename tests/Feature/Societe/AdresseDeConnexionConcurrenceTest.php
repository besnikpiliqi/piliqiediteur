<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\ConfirmerNouvelleAdresseAction;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Deux confirmations simultanées de la même adresse de connexion, chacune
 * dans son processus, sur une vraie base PostgreSQL (voir
 * ConfirmerNouvelleAdresseAction) : deux sociétés dont le code du courrier
 * a été saisi par une autre personne que celle qui les a inscrites, qui a
 * choisi la même adresse (une adresse en attente ne bloque rien). La
 * seconde confirmation relit l'adresse libre, la transaction de la
 * première n'étant pas terminée, puis attend, sur l'index unique des
 * adresses de connexion (users_adresse_de_connexion), la fin de cette
 * transaction, et échoue avec le seul refus de l'adresse : une seule
 * adresse de connexion posée, l'autre compte reste sans adresse de
 * connexion et son lien ne sert plus.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class AdresseDeConnexionConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    private const ADRESSE = 'convoitee@exemple.fr';

    #[Group('concurrence')]
    public function test_deux_confirmations_simultanees_de_la_meme_adresse_n_en_posent_qu_une(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        Mail::fake();
        $premier = $this->saisirLeCodeCommeUneAutrePersonne($this->inscrireUneSociete(null, 'premiere@exemple.fr'), self::ADRESSE);
        $jetonDuPremier = $this->jetonDeLAdresseEnAttente(self::ADRESSE);
        $second = $this->saisirLeCodeCommeUneAutrePersonne($this->inscrireUneSociete(null, 'seconde@exemple.fr'), self::ADRESSE);
        $jetonDuSecond = $this->jetonDeLAdresseEnAttente(self::ADRESSE);
        $secondId = (int) $second->getKey();
        $connexionDuTest = DB::getDefaultConnection();
        $concurrente = null;
        DB::listen(function (QueryExecuted $requete) use (&$concurrente, $connexionDuTest, $jetonDuSecond, $secondId): void {
            if ($concurrente === null && $requete->connectionName === $connexionDuTest && str_starts_with($requete->sql, 'update "users" set "email"')) {
                $concurrente = $this->demarrerUnProcessusConcurrent(static function () use ($jetonDuSecond, $secondId): void {
                    app(ConfirmerNouvelleAdresseAction::class)->handle($jetonDuSecond, User::query()->findOrFail($secondId));
                });
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });

        app(ConfirmerNouvelleAdresseAction::class)->handle($jetonDuPremier, $premier);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString(ConfirmerNouvelleAdresseAction::MESSAGE_ADRESSE_INUTILISABLE, $resultat->errorOutput());
        $this->assertSame([self::ADRESSE, null], [$premier->refresh()->email, $second->refresh()->email]);
        $this->assertSame([null, null], [$second->nouvelle_adresse, ConfirmerNouvelleAdresseAction::compteEnAttente($jetonDuSecond)]);
        $this->assertSame([(int) $premier->getKey()], User::query()->where('role_societe', RoleSociete::Proprietaire->value)->where('email', self::ADRESSE)->pluck('id')->map(intval(...))->all());
    }
}
