<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Support\GardesDesCabinets;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use Database\Factories\SocieteFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les gardes par lesquelles la base elle-même tient l'espace des cabinets
 * d'expertise comptable (voir GardesDesCabinets), même écrit par
 * DB::table() : chacun de leurs refus, et les transitions permises.
 */
class GardesDesCabinetsEnBaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: TypeDActivite}>
     */
    public static function typesQuiNeSontPasDesCabinets(): array
    {
        return ['commerce' => [TypeDActivite::Commerce], 'services' => [TypeDActivite::Services]];
    }

    #[DataProvider('typesQuiNeSontPasDesCabinets')]
    public function test_la_base_refuse_une_ligne_de_cabinet_pour_une_societe_qui_n_est_pas_un_cabinet(TypeDActivite $type): void
    {
        $societe = $this->ouvrirUneSociete(['nom' => 'Boutique', 'type' => $type])->societe;

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('cabinets')->insert(['societe_id' => $societe->id, 'created_at' => now(), 'updated_at' => now()])), QueryException::class, GardesDesCabinets::CABINETS);
    }

    public function test_la_base_refuse_un_lien_dont_le_cabinet_n_est_pas_un_cabinet_ou_dont_le_client_est_un_cabinet_ou_vers_soi(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $autreCabinet = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $client = $this->ouvrirUneSociete(['nom' => 'Cliente'])->societe;

        foreach ([[$client->id, $client->id], [$autreCabinet->id, $cabinet->id], [$cabinet->id, $cabinet->id]] as [$societeId, $cabinetId]) {
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert($this->lien($societeId, $cabinetId))), QueryException::class, GardesDesCabinets::LIENS);
        }
    }

    public function test_la_base_refuse_un_jeton_de_rattachement_sur_un_lien_qui_n_est_pas_une_demande_du_cabinet_en_cours(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$this->lien($client->id, $cabinet->id), 'etat' => 'demande_par_le_client', 'jeton_hache' => str_repeat('a', 64)])), QueryException::class, GardesDesCabinets::LIENS);
        $id = DB::table('liens_cabinet')->insertGetId([...$this->lien($client->id, $cabinet->id), 'jeton_hache' => str_repeat('b', 64)]);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $id)->update(['etat' => 'refuse'])), QueryException::class, GardesDesCabinets::LIENS);
    }

    public function test_la_base_refuse_une_transition_de_lien_non_permise_et_la_suppression_d_un_lien(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $id = DB::table('liens_cabinet')->insertGetId($this->lien($client->id, $cabinet->id));
        DB::table('liens_cabinet')->where('id', $id)->update(['etat' => 'refuse']);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $id)->update(['etat' => 'actif'])), QueryException::class, GardesDesCabinets::LIENS);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$this->lien($client->id, $cabinet->id), 'etat' => 'termine'])), QueryException::class, GardesDesCabinets::LIENS);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$this->lien($client->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'demande_du_cabinet'])), QueryException::class, GardesDesCabinets::LIENS);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $id)->update(['origine' => 'invitation'])), QueryException::class, GardesDesCabinets::LIENS);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $id)->delete()), QueryException::class);
        $this->assertSame(1, DB::table('liens_cabinet')->count());
    }

    public function test_la_base_refuse_un_second_lien_actif_pour_un_client(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $autreCabinet = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        DB::table('liens_cabinet')->insert([...$this->lien($client->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'invitation']);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$this->lien($client->id, $autreCabinet->id), 'etat' => 'actif', 'origine' => 'invitation'])), QueryException::class);
    }

    public function test_la_base_refuse_une_autorisation_hors_du_cabinet_ou_sur_un_lien_inactif(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $actif = DB::table('liens_cabinet')->insertGetId([...$this->lien($client->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'invitation']);
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre'])->societe;
        $demande = DB::table('liens_cabinet')->insertGetId($this->lien($autre->id, $cabinet->id));

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_cabinet')->insert(['societe_id' => $client->id, 'lien_id' => $actif, 'autorisation' => PermissionRegistry::FACTURE_CREATE])), QueryException::class, GardesDesCabinets::AUTORISATIONS);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_cabinet')->insert(['societe_id' => $autre->id, 'lien_id' => $demande, 'autorisation' => PermissionRegistry::FACTURE_VIEW])), QueryException::class, GardesDesCabinets::AUTORISATIONS);
        DB::table('autorisations_cabinet')->insert(['societe_id' => $client->id, 'lien_id' => $actif, 'autorisation' => PermissionRegistry::FACTURE_VIEW]);
        $this->assertSame(1, DB::table('autorisations_cabinet')->count());
    }

    public function test_la_base_refuse_une_autorisation_d_articles_pour_un_client_sans_articles(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $client = $this->ouvrirUneSociete(['nom' => 'Atelier', 'type' => TypeDActivite::Services])->societe;
        $lien = DB::table('liens_cabinet')->insertGetId([...$this->lien($client->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'invitation']);

        foreach (array_intersect(PermissionRegistry::duCabinet(TypeDActivite::Commerce), PermissionRegistry::articles()) as $autorisation) {
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_cabinet')->insert(['societe_id' => $client->id, 'lien_id' => $lien, 'autorisation' => $autorisation])), QueryException::class, GardesDesCabinets::AUTORISATIONS);
        }
    }

    public function test_la_base_refuse_de_confier_un_client_a_un_compte_qui_n_est_pas_collaborateur_ou_un_lien_inactif(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $actif = DB::table('liens_cabinet')->insertGetId([...$this->lien($client->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'invitation']);
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre'])->societe;
        $demande = DB::table('liens_cabinet')->insertGetId($this->lien($autre->id, $cabinet->id));
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $collaborateur = $this->collaborateur($cabinet);
        $ligne = fn (int $lien, int $compte): array => ['societe_id' => $cabinet->id, 'lien_id' => $lien, 'user_id' => $compte, 'confie_par' => $proprietaire->id, 'confie_par_nom' => 'Claire Martin', 'created_at' => now()];

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('clients_confies')->insert($ligne($actif, $proprietaire->id))), QueryException::class, GardesDesCabinets::CLIENTS_CONFIES);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('clients_confies')->insert($ligne($demande, $collaborateur->id))), QueryException::class, GardesDesCabinets::CLIENTS_CONFIES);
        DB::table('clients_confies')->insert($ligne($actif, $collaborateur->id));
        $this->assertSame(1, DB::table('clients_confies')->count());
    }

    public function test_la_base_refuse_une_invitation_d_une_societe_qui_n_est_pas_un_cabinet_ou_close_sans_sa_date(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $proprietaireClient = $client->proprietaire()->sole();
        $invitation = fn (int $societe, int $auteur): array => ['uuid' => (string) Str::uuid(), 'societe_id' => $societe, 'siren' => '552100554', 'email' => 'a@b.test', 'jeton_hache' => Str::random(64), 'invitee_par' => $auteur, 'invitee_par_nom' => 'x', 'etat' => 'ouverte', 'expire_le' => now(), 'created_at' => now(), 'updated_at' => now()];

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('invitations_cabinet')->insert($invitation($client->id, $proprietaireClient->id))), QueryException::class, GardesDesCabinets::INVITATIONS);
        $id = DB::table('invitations_cabinet')->insertGetId($invitation($cabinet->id, $this->proprietaireDuCabinet($cabinet)->id));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('invitations_cabinet')->where('id', $id)->update(['etat' => 'expiree'])), QueryException::class, GardesDesCabinets::INVITATIONS);
        DB::table('invitations_cabinet')->where('id', $id)->update(['etat' => 'expiree', 'close_le' => now()]);
        $this->assertSame('expiree', DB::table('invitations_cabinet')->value('etat'));
    }

    public function test_les_transitions_permises_passent(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $acceptee = DB::table('liens_cabinet')->insertGetId([...$this->lien($client->id, $cabinet->id), 'jeton_hache' => str_repeat('c', 64)]);
        DB::table('liens_cabinet')->where('id', $acceptee)->update(['etat' => 'actif', 'jeton_hache' => null]);
        DB::table('liens_cabinet')->where('id', $acceptee)->update(['etat' => 'termine']);

        foreach (['refuse', 'retire', 'expire'] as $issue) {
            $id = DB::table('liens_cabinet')->insertGetId($this->lien($client->id, $cabinet->id));
            DB::table('liens_cabinet')->where('id', $id)->update(['etat' => $issue]);
        }

        $this->assertSame(['termine', 'refuse', 'retire', 'expire'], DB::table('liens_cabinet')->orderBy('id')->pluck('etat')->all());
    }

    /**
     * @return array{0: Societe, 1: Societe}
     */
    private function cabinetEtClient(): array
    {
        return [$this->ouvrirUnCabinet(), $this->ouvrirUneSociete(['nom' => 'Cliente'])->societe];
    }

    /**
     * Une demande de rattachement du cabinet, en colonnes.
     *
     * @return array<string, mixed>
     */
    private function lien(int $societeId, int $cabinetId): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'societe_id' => $societeId,
            'cabinet_id' => $cabinetId,
            'origine' => 'demande_du_cabinet',
            'etat' => 'demande_par_le_cabinet',
            'demande_le' => now(),
            'expire_le' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
