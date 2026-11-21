<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Cabinets\Actions\RefuserLInvitationAction;
use App\Domain\Cabinets\Actions\RetirerLInvitationAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Jobs\RattacherLesDeclarationsDuSiren;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Support\GardesDesCabinets;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Integrations\Sirene\FauxRepertoireSirene;
use Database\Factories\SocieteFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Les commandes horaires de l'espace des cabinets : l'expiration des
 * invitations et des demandes de lien restées 7 jours sans réponse,
 * société par société, et la purge des invitations closes.
 */
class CommandesDesCabinetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_expiration_ferme_les_invitations_et_demandes_perimees_societe_par_societe(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        $premier = $this->ouvrirUnCabinet();
        $second = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $this->inviterUneEntreprise($premier, SocieteFactory::sirenFictif(), 'a@b.test');
        $this->inviterUneEntreprise($second, SocieteFactory::sirenFictif(), 'c@d.test');
        $this->travelTo(self::aParis('2026-10-15 10:00:00'));
        $recente = $this->inviterUneEntreprise($premier, SocieteFactory::sirenFictif(), 'e@f.test');

        $this->travelTo(self::aParis('2026-10-19 10:00:01'));
        $this->artisan('app:expirer-invitations-cabinets')->assertSuccessful();

        $etats = InvitationCabinet::query()->withoutGlobalScopes()->orderBy('id')->get();
        $this->assertSame([EtatInvitation::Expiree, EtatInvitation::Expiree, EtatInvitation::Ouverte], $etats->pluck('etat')->all());
        $this->assertSame([true, true, false], $etats->map(fn (InvitationCabinet $invitation): bool => $invitation->close_le !== null)->all());
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('invitations_cabinet')->where('email', 'e@f.test')->update(['etat' => 'expiree'])), QueryException::class, GardesDesCabinets::INVITATIONS);
        $this->assertNotSame('', $recente);
    }

    public function test_la_purge_efface_les_invitations_closes_depuis_quarante_huit_heures_quel_que_soit_leur_etat(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $acceptee = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'acceptee@b.test');
        $this->accepterLInvitation($acceptee);
        $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'retiree@b.test');
        app(RetirerLInvitationAction::class)->handle($proprietaire, InvitationCabinet::query()->withoutGlobalScopes()->where('email', 'retiree@b.test')->sole());
        app(RefuserLInvitationAction::class)->handle($this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'refusee@b.test'));
        $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'ouverte@b.test');

        $this->travelTo(self::aParis('2026-10-02 10:00:00'));
        $this->artisan('app:purger-invitations-cabinets')->assertSuccessful();
        $this->assertSame(4, DB::table('invitations_cabinet')->count());

        $this->travelTo(self::aParis('2026-10-03 10:00:01'));
        $this->artisan('app:purger-invitations-cabinets')->assertSuccessful();
        $this->assertEqualsCanonicalizing(['refusee@b.test', 'ouverte@b.test'], DB::table('invitations_cabinet')->pluck('email')->all());

        $this->travelTo(self::aParis('2026-10-08 10:00:01'));
        $this->artisan('app:purger-invitations-cabinets')->assertSuccessful();
        $this->assertSame([], DB::table('invitations_cabinet')->where('email', 'refusee@b.test')->pluck('id')->all());
        $this->assertSame(1, LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Actif->value)->count());
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::InvitationAcceptee));
        $this->assertCount(1, $this->entreesDAudit($cabinet, ActionAuditee::InvitationRefusee));
    }

    public function test_l_expiration_d_une_demande_de_rattachement_efface_son_jeton(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        $cabinet = $this->ouvrirUnCabinet();
        $client = $this->ouvrirUneSociete(['nom' => 'Boutique cliente'])->societe;
        $jeton = $this->demanderLeRattachement($cabinet, $client, 'compta@cliente.test');

        $this->travelTo(self::aParis('2026-10-19 10:00:01'));
        $this->artisan('app:expirer-invitations-cabinets')->assertSuccessful();

        $this->assertSame([EtatDuLien::Expire, null], [LienCabinet::query()->withoutGlobalScopes()->sole()->etat, DB::table('liens_cabinet')->value('jeton_hache')]);
        $this->get(route('cabinets.rattachement.afficher', ['jeton' => $jeton]))->assertStatus(410);
    }

    /**
     * La tâche horaire envoie le rattachement de chaque SIREN dont le
     * cabinet est vérifié et qui a une déclaration en attente, et rien
     * d'autre : ni le SIREN d'un cabinet non vérifié, ni celui d'une
     * déclaration sans cabinet sur PILIQI, ni un cabinet sans déclaration.
     */
    public function test_la_commande_horaire_rattache_chaque_siren_dont_le_cabinet_est_verifie_et_rien_d_autre(): void
    {
        $sirenVerifie = FauxRepertoireSirene::SIREN_CABINET;
        $sirenSuspendu = SocieteFactory::sirenFictif();
        $this->declarerSonExpertComptable($this->ouvrirUneSociete(['nom' => 'A', 'siren' => SocieteFactory::sirenFictif()]), siren: $sirenVerifie);
        $this->declarerSonExpertComptable($this->ouvrirUneSociete(['nom' => 'B', 'siren' => SocieteFactory::sirenFictif()]), siren: $sirenVerifie, email: 'b@petit-associes.test');
        $this->declarerSonExpertComptable($this->ouvrirUneSociete(['nom' => 'C', 'siren' => SocieteFactory::sirenFictif()]), siren: $sirenSuspendu, email: 'c@roux.test');
        $this->declarerSonExpertComptable($this->ouvrirUneSociete(['nom' => 'D', 'siren' => SocieteFactory::sirenFictif()]), siren: '000000927', email: 'd@autre.test');
        Bus::fake([RattacherLesDeclarationsDuSiren::class]);
        $this->ouvrirUnCabinet($sirenVerifie);
        $suspendu = $this->ouvrirUnCabinet($sirenSuspendu, 'Hélène Roux');
        DB::table('societes')->where('id', $suspendu->id)->update(['statut' => 'suspendue']);
        $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Paul Sans Client');
        $avant = Bus::dispatched(RattacherLesDeclarationsDuSiren::class)->count();

        $this->artisan('app:rattacher-experts-comptables')->expectsOutput('1 SIREN de cabinet vérifié avec une déclaration en attente.')->assertSuccessful();

        $this->assertSame([$sirenVerifie], Bus::dispatched(RattacherLesDeclarationsDuSiren::class)->slice($avant)->map(fn (RattacherLesDeclarationsDuSiren $tache): string => $tache->siren)->values()->all());
    }
}
