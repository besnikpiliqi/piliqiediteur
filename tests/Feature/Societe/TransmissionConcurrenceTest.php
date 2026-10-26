<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\AccepterLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\AnnulerLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Actions\DemanderLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TransmissionPropriete;
use App\Domain\Tenancy\Services\FournisseurDesComptes;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * La transmission de la propriété face à des gestes simultanés, chacun dans
 * son processus, sur une vraie base PostgreSQL (voir
 * AccepterLaTransmissionDeLaProprieteAction) : l'acceptation verrouille les
 * registres de tous les magasins, puis les deux comptes, puis la ligne de
 * la demande ; l'annulation, les deux comptes, puis cette ligne, dans le
 * même ordre, sans interblocage. Une seule issue
 * à chaque course : jamais une propriété transmise par un lien annulé,
 * jamais deux fois, jamais à un administrateur dont l'accès vient d'être
 * redonné, jamais une adresse de connexion gardée par le propriétaire
 * d'une inscription close au même instant.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class TransmissionConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * L'acceptation tient les comptes et la ligne de la demande quand
     * l'annulation arrive : l'annulation attend, relit un auteur qui n'est
     * plus propriétaire et ne change rien. Puis l'inverse, sur une autre
     * société : l'annulation tient les comptes et la ligne quand
     * l'acceptation arrive, qui attend, relit une demande annulée et ne
     * transmet rien ; l'entrée du journal d'audit de l'annulation, dont la
     * clé vers le compte du propriétaire attendait avant sur le verrou de
     * l'acceptation, ne crée plus d'interblocage.
     */
    #[Group('concurrence')]
    public function test_accepter_et_annuler_une_transmission_en_meme_temps_ne_donne_qu_un_resultat(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();

        [$proprietaireId, $administrateurId, $jeton] = $this->transmissionDemandee('Acceptée d\'abord');
        $annulation = null;
        $this->concurrencerDesQueLaDemandeEstVerrouillee(static function () use ($proprietaireId, $administrateurId): void {
            app(AnnulerLaTransmissionDeLaProprieteAction::class)->handle(User::query()->findOrFail($proprietaireId), User::query()->findOrFail($administrateurId));
        }, $annulation);

        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle(User::query()->findOrFail($administrateurId), $jeton, self::MOT_DE_PASSE_CHOISI);

        $this->assertStringContainsString(DemanderLaTransmissionDeLaProprieteAction::MESSAGE_PROPRIETAIRE_SEUL, $this->attendreLEchec($annulation));
        $this->assertSame([RoleSociete::Proprietaire, RoleSociete::Administrateur], [User::query()->findOrFail($administrateurId)->role_societe, User::query()->findOrFail($proprietaireId)->role_societe]);
        $demande = TransmissionPropriete::withoutGlobalScopes()->where('destinataire_id', $administrateurId)->sole();
        $this->assertSame([true, null], [$demande->acceptee_le !== null, $demande->annulee_le]);

        [$autreProprietaireId, $autreAdministrateurId, $autreJeton] = $this->transmissionDemandee('Annulée d\'abord');
        $acceptation = null;
        $this->concurrencerDesQueLaDemandeEstVerrouillee(static function () use ($autreAdministrateurId, $autreJeton): void {
            app(AccepterLaTransmissionDeLaProprieteAction::class)->handle(User::query()->findOrFail($autreAdministrateurId), $autreJeton, self::MOT_DE_PASSE_CHOISI);
        }, $acceptation);

        app(AnnulerLaTransmissionDeLaProprieteAction::class)->handle(User::query()->findOrFail($autreProprietaireId), User::query()->findOrFail($autreAdministrateurId));

        $this->assertStringContainsString(AccepterLaTransmissionDeLaProprieteAction::MESSAGE_LIEN, $this->attendreLEchec($acceptation));
        $this->assertSame([RoleSociete::Administrateur, RoleSociete::Proprietaire], [User::query()->findOrFail($autreAdministrateurId)->role_societe, User::query()->findOrFail($autreProprietaireId)->role_societe]);
        $autreDemande = TransmissionPropriete::withoutGlobalScopes()->where('destinataire_id', $autreAdministrateurId)->sole();
        $this->assertSame([null, true], [$autreDemande->acceptee_le, $autreDemande->annulee_le !== null]);
    }

    /**
     * Le même lien suivi deux fois au même instant : la seconde acceptation
     * attend la première sur les registres, relit une demande acceptée et
     * un propriétaire qui ne l'est plus, et ne change rien.
     */
    #[Group('concurrence')]
    public function test_deux_acceptations_simultanees_du_meme_lien_ne_transmettent_qu_une_fois(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        [$proprietaireId, $administrateurId, $jeton] = $this->transmissionDemandee('Ma Boutique');
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($administrateurId, $jeton): void {
            app(AccepterLaTransmissionDeLaProprieteAction::class)->handle(User::query()->findOrFail($administrateurId), $jeton, self::MOT_DE_PASSE_CHOISI);
        }, $concurrente);

        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle(User::query()->findOrFail($administrateurId), $jeton, self::MOT_DE_PASSE_CHOISI);

        $this->assertStringContainsString(AccepterLaTransmissionDeLaProprieteAction::MESSAGE_LIEN, $this->attendreLEchec($concurrente));
        $this->assertSame([RoleSociete::Proprietaire, RoleSociete::Administrateur], [User::query()->findOrFail($administrateurId)->role_societe, User::query()->findOrFail($proprietaireId)->role_societe]);
        $this->assertSame(1, User::query()->where('societe_id', User::query()->findOrFail($administrateurId)->societe_id)->where('role_societe', RoleSociete::Proprietaire->value)->count());
    }

    /**
     * Le propriétaire redonne l'accès de l'administrateur, absent, pendant
     * que celui-ci accepte la transmission : son mot de passe redevient son
     * identifiant ; l'acceptation attend sur le verrou des comptes, relit
     * ce mot de passe et ne transmet rien (personne ne devient propriétaire
     * avec un mot de passe égal à son identifiant).
     */
    #[Group('concurrence')]
    public function test_un_acces_redonne_pendant_l_acceptation_d_une_transmission_la_fait_refuser(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        [$proprietaireId, $administrateurId, $jeton] = $this->transmissionDemandee('Ma Boutique');
        $connexionDuTest = DB::getDefaultConnection();
        $acceptation = null;
        DB::listen(function (QueryExecuted $requete) use (&$acceptation, $connexionDuTest, $administrateurId, $jeton): void {
            if ($acceptation === null && $requete->connectionName === $connexionDuTest && str_starts_with($requete->sql, 'select * from "users"') && str_contains($requete->sql, 'for update')) {
                $acceptation = $this->demarrerUnProcessusConcurrent(static function () use ($administrateurId, $jeton): void {
                    app(AccepterLaTransmissionDeLaProprieteAction::class)->handle(User::query()->findOrFail($administrateurId), $jeton, self::MOT_DE_PASSE_CHOISI);
                });
                $this->attendreQueLeProcessusAttendeUnVerrou($acceptation);
            }
        });

        app(RedonnerLAccesAction::class)->handle(User::query()->findOrFail($proprietaireId), User::query()->findOrFail($administrateurId), false, null, (string) Str::uuid());

        $this->assertStringContainsString(DemanderLaTransmissionDeLaProprieteAction::MESSAGE_MOT_DE_PASSE, $this->attendreLEchec($acceptation));
        $this->assertSame([RoleSociete::Administrateur, RoleSociete::Proprietaire], [User::query()->findOrFail($administrateurId)->role_societe, User::query()->findOrFail($proprietaireId)->role_societe]);
        $this->assertNull(TransmissionPropriete::withoutGlobalScopes()->where('destinataire_id', $administrateurId)->sole()->acceptee_le);
    }

    /**
     * L'inscription se clôt (trente jours sans code) pendant que
     * l'administrateur accepte la transmission : l'acceptation tient les
     * deux comptes, la clôture tient la société sans empêcher l'entrée du
     * journal d'audit de l'acceptation de la référencer, et attend ces
     * comptes ; l'acceptation passe, puis la clôture voit le nouveau
     * propriétaire et lui retire l'adresse de connexion que la
     * transmission venait de lui donner. Aucun interblocage.
     */
    #[Group('concurrence')]
    public function test_une_transmission_acceptee_pendant_la_cloture_de_l_inscription_ne_garde_aucune_adresse_de_connexion(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete(null, 'inscrite@example.test');
        $administrateur = $this->administrateurPretAReprendre($this->creerLeMagasinDuSiege($societe), 'repreneur@example.test');
        $this->travelTo(self::aParis('2026-11-04 09:00:00'));
        $jeton = $this->demanderLaTransmission($societe->proprietaire()->sole(), $administrateur, self::MOT_DE_PASSE_CHOISI);
        $this->travelTo(self::aParis('2026-11-04 10:00:00'));
        app(TenantContext::class)->clear();
        $societeId = (int) $societe->getKey();
        $limite = self::aParis('2026-10-05 10:00:00')->getTimestamp();
        $connexionDuTest = DB::getDefaultConnection();
        $cloture = null;
        DB::listen(function (QueryExecuted $requete) use (&$cloture, $connexionDuTest, $societeId, $limite): void {
            if ($cloture === null && $requete->connectionName === $connexionDuTest && str_starts_with($requete->sql, 'update "users" set "role_societe"')) {
                $cloture = $this->demarrerUnProcessusConcurrent(static function () use ($societeId, $limite): void {
                    if (! app(CloreLInscriptionAction::class)->cloreSansCode(Societe::query()->findOrFail($societeId), CarbonImmutable::createFromTimestamp($limite, date_default_timezone_get()))) {
                        throw new LogicException('L\'inscription n\'a pas été close.');
                    }
                });
                $this->attendreQueLeProcessusAttendeUnVerrou($cloture);
            }
        });

        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur->refresh(), $jeton, self::MOT_DE_PASSE_CHOISI);

        $this->assertInstanceOf(InvokedProcess::class, $cloture);
        $resultat = $cloture->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $this->assertTrue(Societe::query()->findOrFail($societeId)->estClose());
        $nouveau = User::query()->findOrFail($administrateur->id);
        $this->assertSame([RoleSociete::Proprietaire, null, null], [$nouveau->role_societe, $nouveau->email, $nouveau->email_verified_at]);
        $this->assertNull(FournisseurDesComptes::proprietaireDeLAdresse('repreneur@example.test'));
    }

    /**
     * Une société, son propriétaire et un administrateur qui remplit les
     * conditions de la transmission, à qui le propriétaire vient de la
     * demander : leurs identifiants et le jeton du lien.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    private function transmissionDemandee(string $nomDuMagasin): array
    {
        $magasin = $this->ouvrirUneSociete(['nom' => $nomDuMagasin]);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurPretAReprendre($magasin, Str::slug($nomDuMagasin).'@example.test');
        $jeton = $this->demanderLaTransmission($proprietaire, $administrateur);

        return [(int) $proprietaire->id, (int) $administrateur->id, $jeton];
    }

    /**
     * Démarre l'opération concurrente dès que le test tient le verrou de la
     * ligne d'une demande de transmission, puis attend qu'elle attende un
     * verrou que tient le test.
     *
     * @param  Closure(): void  $operationConcurrente
     */
    private function concurrencerDesQueLaDemandeEstVerrouillee(Closure $operationConcurrente, ?InvokedProcess &$concurrente): void
    {
        $connexionDuTest = DB::getDefaultConnection();

        DB::listen(function (QueryExecuted $requete) use (&$concurrente, $operationConcurrente, $connexionDuTest): void {
            if ($concurrente === null && $requete->connectionName === $connexionDuTest && str_contains($requete->sql, 'from "transmissions_propriete"') && str_contains($requete->sql, 'for update')) {
                $concurrente = $this->demarrerUnProcessusConcurrent($operationConcurrente);
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });
    }

    /**
     * Le processus concurrent a échoué : son message d'erreur.
     */
    private function attendreLEchec(?InvokedProcess $concurrente): string
    {
        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertFalse($resultat->successful(), 'L\'opération concurrente aurait dû être refusée.');

        return $resultat->errorOutput();
    }
}
