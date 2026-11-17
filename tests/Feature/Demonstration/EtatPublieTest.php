<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Enums\EtatDUnModele;
use App\Domain\Demonstration\Etat\EtatDuSite;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Registre\Models\Modele;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Illuminate\Support\Facades\DB;
use Tests\DemonstrationTestCase;

/**
 * L'état publié du site de démonstration (lot DEMO-COPIE, C.19, N31) :
 * GET /interne/etat, sans témoin, signé par la clé de l'état ; il dit le
 * modèle en service de chaque type, les échecs de construction, les copies
 * par état et la place libre, sans aucune référence ni donnée d'une copie.
 */
class EtatPublieTest extends DemonstrationTestCase
{
    public function test_l_etat_publie_est_signe_et_ne_porte_aucune_reference_ni_donnee_d_une_copie(): void
    {
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
        $paire = MessagesSignes::genererUnePaire();
        config(['demonstration.etat.cle_privee' => $paire['cle_privee']]);
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        Modele::on('demo_admin')->create([
            'type' => TypeDActivite::Cabinet, 'nom_de_la_base' => 'demo_travail_cabinet_202611160110', 'commence_le' => now(),
            'version_du_code' => 'essai', 'mot_de_passe' => 'Demo-abcdef-12', 'etat' => EtatDUnModele::EnEchec, 'cause_de_l_echec' => 'La vérification de l\'isolation a trouvé 2 problèmes.',
        ]);

        $reponse = $this->getJson('/interne/etat');

        $reponse->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $etat = (string) $reponse->json('etat');
        $this->assertTrue(sodium_crypto_sign_verify_detached((string) MessagesSignes::depuisLaBase64Url((string) $reponse->json('signature')), $etat, (string) base64_decode($paire['cle_publique'], true)));
        $champs = json_decode($etat, true);
        $this->assertSame(['prete' => 1], $champs['copies']);
        $this->assertNotNull($champs['modeles']['services']['construit_le']);
        $this->assertNull($champs['modeles']['commerce']['construit_le']);
        $this->assertContains('La construction du modèle « cabinet » a échoué : La vérification de l\'isolation a trouvé 2 problèmes.', $champs['problemes']);
        $this->assertContains('Le modèle « commerce » n\'a pas été construit depuis plus de '.EtatDuSite::JOURS_SANS_CONSTRUCTION.' jours.', $champs['problemes']);
        $this->assertStringNotContainsString($copie->reference, $etat);
        $this->assertStringNotContainsString((string) $copie->nom_de_la_base, $etat);
        $this->assertStringNotContainsString($copie->repere, $etat);
    }

    /**
     * Le site de démonstration n'envoie aucun e-mail : une file du
     * registre que personne ne prend paraît dans l'état publié, que le
     * site réel transforme en alerte à l'éditeur, et s'en efface quand elle
     * repart.
     */
    public function test_une_file_du_registre_qui_ne_part_plus_parait_dans_l_etat_publie_sans_aucun_e_mail(): void
    {
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
        DB::connection('demo_admin')->table('jobs')->insert([
            'queue' => 'demonstration-admin', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(10)->getTimestamp(), 'created_at' => now()->subMinutes(10)->getTimestamp(),
        ]);

        $this->artisan('app:surveiller-la-file-d-attente')->assertFailed();

        $file = array_values(array_filter(app(EtatDuSite::class)->construire()['problemes'], static fn (string $ligne): bool => str_starts_with($ligne, 'La file d\'attente du site de démonstration ne part plus.')));
        $this->assertSame(['La file d\'attente du site de démonstration ne part plus. File « demonstration-admin » : 1 tâche(s) disponible(s) depuis plus de 2 minutes, que personne ne prend, et 0 tâche(s) réservée(s) depuis plus de 1900 secondes, laissée(s) par un travailleur arrêté.'], $file);
        $this->assertSame(0, DB::connection('demo_admin')->table('jobs')->where('queue', '!=', 'demonstration-admin')->count(), 'Aucun e-mail ni aucun travail de plus.');

        DB::connection('demo_admin')->table('jobs')->delete();
        $this->artisan('app:surveiller-la-file-d-attente')->assertSuccessful();

        $this->assertSame([], array_filter(app(EtatDuSite::class)->construire()['problemes'], static fn (string $ligne): bool => str_starts_with($ligne, 'La file d\'attente')));
    }
}
