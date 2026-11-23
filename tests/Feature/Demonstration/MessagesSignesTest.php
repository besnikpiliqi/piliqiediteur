<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Jobs\CreerUneCopie;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Lien;
use App\Domain\Demonstration\Registre\Models\Temoin;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Http\Controllers\Demonstration\MessagesDuSiteReelController;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DemonstrationTestCase;

/**
 * La réception des messages signés du site réel sur le site de
 * démonstration (lot DEMO-COPIE, C.7, N4) : seul un message de la liste
 * fermée, signé par une clé connue, à jour, jamais vu, pour ce site, est lu ;
 * chaque refus répond 422 avec sa cause, sans rien créer. « creer » répété
 * ne crée qu'une copie ; « etat » ne change rien ; « renouveler_le_lien »
 * retire l'ancien lien et toutes les visites, et répond la génération.
 */
class MessagesSignesTest extends DemonstrationTestCase
{
    public function test_un_message_creer_signe_par_la_bonne_cle_cree_la_copie_et_repete_n_en_cree_pas_une_seconde(): void
    {
        $this->modeleDeTest(TypeDActivite::Services);
        $creer = $this->creer('0123456789abcdef0123456789abcdef');

        $this->postJson('/interne/messages', $this->messageSigne($creer))
            ->assertOk()
            ->assertExactJson(['etat' => 'prete', 'generation' => 1]);
        $this->postJson('/interne/messages', $this->messageSigne($creer))
            ->assertOk()
            ->assertExactJson(['etat' => 'prete', 'generation' => 1]);

        $copie = Copie::on('demo_admin')->sole();
        $this->assertSame(EtatDUneCopie::Prete, $copie->etat);
        $this->assertSame('K7M2QD', $copie->repere);
        $this->assertSame(1, Lien::on('demo_admin')->count());
    }

    public function test_le_lien_et_le_billet_d_une_copie_tout_juste_demandee_menent_a_la_page_d_attente_avant_le_travail_de_creation(): void
    {
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
        Bus::fake([CreerUneCopie::class]);
        $secret = MessagesSignes::enBase64Url(random_bytes(32));
        $reference = '0123456789abcdef0123456789abcdef';

        $this->postJson('/interne/messages', $this->messageSigne([...$this->creer($reference), 'empreinte_du_lien' => hash('sha256', $secret)]))
            ->assertStatus(202)
            ->assertExactJson(['etat' => 'en_preparation', 'generation' => 1]);

        Bus::assertDispatched(CreerUneCopie::class, fn (CreerUneCopie $travail): bool => $travail->demande['reference'] === $reference);
        $copie = Copie::on('demo_admin')->sole();
        $this->assertSame(EtatDUneCopie::EnPreparation, $copie->etat);
        $this->assertNull($copie->nom_de_la_base, 'Le travail de création n\'est pas encore passé.');

        $temoin = $this->entrerParLeLien($secret);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')
            ->assertStatus(503)->assertSee('Votre démonstration se prépare')->assertSee('http-equiv="refresh"', false);

        $billet = $this->messageSigne(['aud' => MessagesSignes::AUDIENCE_DE_L_ENTREE, 'quoi' => MessagesSignes::BILLET_D_ENTREE, 'reference' => $reference]);
        $this->withHeaders(['Origin' => 'https://piliqi.test'])->post('/entrer-par-le-compte', $billet)->assertRedirect('/choisir')->assertStatus(303);
    }

    public function test_le_role_des_pages_n_inscrit_une_copie_que_par_sa_fonction_et_ne_la_change_jamais(): void
    {
        $this->modeleDeTest(TypeDActivite::Services);
        $this->postJson('/interne/messages', $this->messageSigne($this->creer('0123456789abcdef0123456789abcdef')))->assertOk();
        $copie = Copie::on('demo_admin')->sole();

        foreach ([
            fn () => DB::connection('registre')->table('copies')->insert(['reference' => str_repeat('e', 32), 'repere' => 'K7M2QD', 'type' => 'services', 'validee' => true, 'generation_du_lien' => 1, 'etat' => 'prete']),
            fn () => DB::connection('registre')->table('copies')->where('id', $copie->id)->update(['etat' => 'supprimee']),
            fn () => DB::connection('registre')->table('liens')->where('copie_id', $copie->id)->delete(),
        ] as $ecriture) {
            try {
                $ecriture();
                $this->fail('Le rôle des pages a pu écrire les copies ou les liens hors de la fonction d\'inscription.');
            } catch (QueryException $refus) {
                $this->assertSame('42501', $refus->errorInfo[0] ?? null, $refus->getMessage());
            }
        }

        $this->assertSame(EtatDUneCopie::Prete, $copie->fresh()?->etat);
        $this->assertSame(1, Lien::on('demo_admin')->count());
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function messagesRefuses(): array
    {
        return [
            'un champ hors de la liste fermée' => ['Le message porte un champ hors de sa liste fermée.', ['raison_sociale' => 'MARTIN ET FILS']],
            'une version trop vieille' => ['La version du protocole n\'est ni la courante ni la précédente.', ['v' => MessagesSignes::VERSION - 1]],
            'une version à venir' => ['La version du protocole n\'est ni la courante ni la précédente.', ['v' => MessagesSignes::VERSION + 1]],
            'l\'audience du billet d\'entrée' => ['L\'audience du message n\'est pas celle de cette adresse.', ['aud' => MessagesSignes::AUDIENCE_DE_L_ENTREE]],
            'un autre destinataire' => ['Le message est destiné à un autre site.', ['destinataire' => 'un-autre-site']],
            'une action inconnue' => ['L\'action du message est inconnue.', ['quoi' => 'tout_effacer']],
            'une référence qui remonte les dossiers' => ['La référence de la copie est mal formée.', ['reference' => '../../../../etc/passwd']],
            'une référence avec un point-virgule' => ['La référence de la copie est mal formée.', ['reference' => '0123456789abcdef0123456789abcd;e']],
            'un type inconnu' => ['Le type de la copie est inconnu.', ['type' => 'boulangerie']],
            'une fin trop lointaine' => ['La fin de la copie est hors de ses bornes (au plus 30 jours et 1 heure).', ['fin' => '2026-12-16T11:00:01Z']],
            'un nonce mal formé' => ['Le nonce du message est mal formé.', ['nonce' => 'pas-un-nonce']],
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('messagesRefuses')]
    public function test_un_message_hors_de_ses_bornes_est_refuse_avec_sa_cause_sans_rien_creer(string $cause, array $change): void
    {
        $this->travelTo(self::aParis('2026-11-16 11:00:00'));

        $this->postJson('/interne/messages', $this->messageSigne([...$this->creer('0123456789abcdef0123456789abcdef'), ...$change]))
            ->assertUnprocessable()
            ->assertExactJson(['cause' => $cause]);
        $this->assertSame(0, Copie::on('demo_admin')->count());
    }

    public function test_une_signature_fausse_ou_d_une_autre_cle_est_refusee(): void
    {
        $signe = $this->messageSigne($this->creer('0123456789abcdef0123456789abcdef'));
        $autre = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

        $this->postJson('/interne/messages', ['message' => $signe['message'], 'signature' => $this->messageSigne(['quoi' => 'etat', 'reference' => str_repeat('a', 32)])['signature']])
            ->assertUnprocessable()->assertExactJson(['cause' => 'La signature du message est fausse.']);
        $this->postJson('/interne/messages', $this->messageSigne($this->creer('0123456789abcdef0123456789abcdef'), $autre))
            ->assertUnprocessable()->assertExactJson(['cause' => 'La signature du message est fausse.']);
        $this->postJson('/interne/messages', $this->messageSigne([...$this->creer('0123456789abcdef0123456789abcdef'), 'kid' => 'inconnue']))
            ->assertUnprocessable()->assertExactJson(['cause' => 'La clé du message est inconnue.']);
        $this->assertSame(0, Copie::on('demo_admin')->count());
    }

    public function test_un_message_rejoue_trop_ancien_ou_venu_du_futur_est_refuse(): void
    {
        $this->travelTo(self::aParis('2026-11-16 11:00:00'));
        $etat = ['quoi' => 'etat', 'reference' => str_repeat('b', 32)];
        $signe = $this->messageSigne($etat);

        $this->postJson('/interne/messages', $signe)->assertStatus(410);
        $this->postJson('/interne/messages', $signe)->assertUnprocessable()->assertExactJson(['cause' => 'Ce message a déjà été reçu.']);
        $this->postJson('/interne/messages', $this->messageSigne([...$etat, 'emis_le' => '2026-11-16T09:54:59Z']))
            ->assertUnprocessable()->assertExactJson(['cause' => 'Le message est trop ancien.']);
        $this->postJson('/interne/messages', $this->messageSigne([...$etat, 'emis_le' => '2026-11-16T10:00:31Z']))
            ->assertUnprocessable()->assertExactJson(['cause' => 'Le message est émis dans le futur.']);
        $this->postJson('/interne/messages', $this->messageSigne([...$etat, 'emis_le' => '2026-11-16T09:55:01Z']))->assertStatus(410);
    }

    public function test_au_dela_de_trente_refus_dans_la_minute_l_adresse_recoit_429_sans_que_rien_ne_soit_lu(): void
    {
        $this->travelTo(self::aParis('2026-11-16 11:00:00'));

        for ($message = 1; $message <= 5; $message++) {
            $this->postJson('/interne/messages', $this->messageSigne(['quoi' => 'etat', 'reference' => str_repeat('c', 32)]))->assertStatus(410);
        }

        for ($refus = 1; $refus <= MessagesDuSiteReelController::REFUS_PAR_MINUTE; $refus++) {
            $this->postJson('/interne/messages', ['message' => '{}', 'signature' => 'fausse'])->assertUnprocessable();
        }

        $signe = $this->messageSigne(['quoi' => 'etat', 'reference' => str_repeat('d', 32)]);
        $this->postJson('/interne/messages', $signe)
            ->assertTooManyRequests()->assertExactJson(['cause' => MessagesDuSiteReelController::TROP_DE_REFUS]);

        $this->travel(61)->seconds();
        $this->postJson('/interne/messages', $signe)->assertStatus(410);
    }

    public function test_etat_ne_change_rien_et_rend_l_etat_de_la_copie(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);

        $this->postJson('/interne/messages', $this->messageSigne(['quoi' => 'etat', 'reference' => $copie->reference]))
            ->assertOk()->assertExactJson(['etat' => 'prete', 'generation' => 1]);
        $this->assertEquals($copie->getAttributes(), $copie->fresh()?->getAttributes());
    }

    public function test_renouveler_le_lien_retire_l_ancien_lien_et_toutes_les_visites_et_repond_la_generation(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Services);
        $this->entrerParLeLien($secret);
        $nouveau = hash('sha256', 'nouveau secret');

        $this->postJson('/interne/messages', $this->messageSigne(['quoi' => 'renouveler_le_lien', 'reference' => $copie->reference, 'empreinte_du_lien' => $nouveau, 'generation' => 2]))
            ->assertOk()->assertExactJson(['etat' => 'prete', 'generation' => 2]);

        $this->assertSame(2, $copie->fresh()?->generation_du_lien);
        $this->assertSame([$nouveau], Lien::on('demo_admin')->whereNull('retire_le')->pluck('empreinte')->all());
        $this->assertSame(0, Temoin::on('demo_admin')->whereNull('retire_le')->count(), 'Un renouvellement coupe toutes les visites.');
        $this->postJson('/interne/messages', $this->messageSigne(['quoi' => 'renouveler_le_lien', 'reference' => $copie->reference, 'empreinte_du_lien' => $nouveau, 'generation' => 2]))
            ->assertOk()->assertExactJson(['etat' => 'prete', 'generation' => 2]);
        $this->postJson('/interne/messages', $this->messageSigne(['quoi' => 'renouveler_le_lien', 'reference' => $copie->reference, 'empreinte_du_lien' => hash('sha256', 'autre'), 'generation' => 2]))
            ->assertUnprocessable();
        $this->postJson('/interne/messages', $this->messageSigne(['quoi' => 'renouveler_le_lien', 'reference' => $copie->reference, 'empreinte_du_lien' => hash('sha256', 'ancien'), 'generation' => 1]))
            ->assertUnprocessable()->assertExactJson(['cause' => 'Cette génération du lien est plus ancienne que celle que la démonstration connaît.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function creer(string $reference): array
    {
        return [
            'quoi' => 'creer',
            'reference' => $reference,
            'repere' => 'K7M2QD',
            'type' => TypeDActivite::Services->value,
            'validee' => true,
            'empreinte_du_lien' => hash('sha256', 'secret-'.$reference),
            'generation' => 1,
            'fin' => MessagesSignes::instant(CarbonImmutable::now()->addSeconds(30 * 86400)),
        ];
    }
}
