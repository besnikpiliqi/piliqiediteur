<?php

namespace Tests\Feature\Contacts;

use App\Domain\CaisseSecurisee\Exceptions\DonneeFiscaleInalterableException;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Une vérification VIES est une preuve datée : elle ne se modifie ni ne
 * se supprime, et son jour est celui de Paris.
 */
class ConsultationViesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Une vérification du n° DE123456789 d'un client du magasin actif, à
     * cet instant, écrite comme l'écrit VerifierNumeroTvaAction.
     */
    private function consultation(Client $client, CarbonImmutable $consulteeLe, bool $valide = true, ?string $identifiant = 'WAPIAAAAW1234567'): ConsultationVies
    {
        return ConsultationVies::forceCreate([
            'client_id' => $client->id,
            'numero_tva' => 'DE123456789',
            'valide' => $valide,
            'identifiant_consultation' => $identifiant,
            'consultee_le' => $consulteeLe,
            'reponse' => '{"valid":'.($valide ? 'true' : 'false').'}',
        ]);
    }

    private function clientAllemand(): Client
    {
        app(TenantContext::class)->set(Magasin::factory()->create());

        return Client::factory()->etranger('DE', 'DE123456789')->create();
    }

    public function test_une_consultation_ne_se_modifie_ni_ne_se_supprime_par_le_modele(): void
    {
        $consultation = $this->consultation($this->clientAllemand(), self::aParis('2026-10-09 14:32:10'));

        $this->assertThrows(
            fn () => $consultation->forceFill(['valide' => false])->save(),
            DonneeFiscaleInalterableException::class,
            'Une vérification VIES est une preuve : elle ne se modifie ni ne se supprime. Modification refusée : valide.',
        );
        $this->assertThrows(
            fn () => $consultation->delete(),
            DonneeFiscaleInalterableException::class,
            'Une vérification VIES est une preuve : elle ne se modifie ni ne se supprime. Suppression refusée.',
        );

        $this->assertTrue($consultation->fresh()->valide);
    }

    public function test_la_base_refuse_de_modifier_ou_de_supprimer_une_consultation(): void
    {
        $id = $this->consultation($this->clientAllemand(), self::aParis('2026-10-09 14:32:10'))->id;

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('consultations_vies')->where('id', $id)->update(['valide' => false])), QueryException::class, 'consultations_vies : table en ajout seul, modification refusée');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('consultations_vies')->where('id', $id)->delete()), QueryException::class, 'consultations_vies : table en ajout seul, suppression refusée');

        $this->assertSame(1, DB::table('consultations_vies')->where('id', $id)->where('valide', true)->count());
    }

    /**
     * Minuit à Paris est 22 h UTC la veille en octobre : une vérification
     * faite à 0 h 30 à Paris compte pour ce jour, pas pour la veille. Une
     * vérification qui a trouvé le n° inconnu compte aussi pour son jour :
     * la dernière du jour décide (voir ControleNatureOperation).
     */
    public function test_une_consultation_compte_pour_son_jour_a_paris(): void
    {
        $client = $this->clientAllemand();
        $veilleAuSoir = $this->consultation($client, self::aParis('2026-10-09 23:30:00'));
        $lendemainANuit = $this->consultation($client, self::aParis('2026-10-10 00:30:00'));
        $inconnuLeLendemain = $this->consultation($client, self::aParis('2026-10-10 09:00:00'), valide: false);

        $this->assertSame([$veilleAuSoir->id], ConsultationVies::query()->duJour(Calendrier::deParis(), '2026-10-09')->pluck('id')->all());
        $this->assertSame([$lendemainANuit->id, $inconnuLeLendemain->id], ConsultationVies::query()->duJour(Calendrier::deParis(), '2026-10-10')->orderBy('id')->pluck('id')->all());
        $this->assertSame([$lendemainANuit->id, $inconnuLeLendemain->id], ConsultationVies::query()->duJour(Calendrier::deParis(), Calendrier::deParis()->jourDe(self::aParis('2026-10-10 12:00')))->orderBy('id')->pluck('id')->all());
    }

    public function test_la_reference_cite_le_numero_de_consultation_quand_vies_en_a_donne_un(): void
    {
        $client = $this->clientAllemand();

        $this->assertSame('consultation WAPIAAAAW1234567 du 09/10/2026 à 14:32', $this->consultation($client, self::aParis('2026-10-09 14:32:10'))->fresh()->reference());
        $this->assertSame('consultation du 09/10/2026 à 14:32', $this->consultation($client, self::aParis('2026-10-09 14:32:10'), identifiant: null)->fresh()->reference());
    }
}
