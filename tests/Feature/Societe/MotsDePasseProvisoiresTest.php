<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Enums\EtatDuMotDePasse;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Services\MotsDePasseProvisoires;
use App\Domain\Tenancy\Support\MotDePasseProvisoire;
use App\Domain\Tenancy\Support\MotsDePasseCourants;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/**
 * Le mot de passe provisoire d'un compte que la société crée ou dont elle
 * redonne l'accès en l'absence de la personne (voir MotsDePasseProvisoires,
 * décision de Claude D76, T36.1 à T36.4) : tiré au hasard, jamais
 * l'identifiant, valable 72 heures, à usage unique, jamais gardé en clair.
 */
class MotsDePasseProvisoiresTest extends TestCase
{
    use RefreshDatabase;

    private const FORME = '/^[a-hjkmnp-z2-9]{4}-[a-hjkmnp-z2-9]{4}-[a-hjkmnp-z2-9]{4}$/D';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
    }

    public function test_un_mot_de_passe_provisoire_a_douze_caracteres_en_trois_groupes_sans_caractere_ambigu(): void
    {
        $service = app(MotsDePasseProvisoires::class);

        foreach (range(1, 200) as $rang) {
            $this->assertMatchesRegularExpression(self::FORME, $service->tirer()->valeur());
        }

        foreach (['0', 'o', '1', 'l', 'i'] as $ambigu) {
            $this->assertStringNotContainsString($ambigu, MotsDePasseProvisoires::ALPHABET);
        }
    }

    public function test_deux_tirages_different_et_ne_sont_ni_un_identifiant_ni_un_mot_de_passe_courant(): void
    {
        $service = app(MotsDePasseProvisoires::class);
        $tires = [];

        foreach (range(1, 200) as $rang) {
            $valeur = $service->tirer()->valeur();
            $tires[$valeur] = true;
            $this->assertFalse(MotsDePasseCourants::contient($valeur), $valeur);
            $this->assertDoesNotMatchRegularExpression('/\d{8}/', $valeur);
        }

        $this->assertCount(200, $tires);
    }

    public function test_un_mot_de_passe_provisoire_vaut_soixante_douze_heures(): void
    {
        $this->assertSame(72, MotsDePasseProvisoires::dureeEnHeures());

        $provisoire = app(MotsDePasseProvisoires::class)->tirer();

        $this->assertMemeInstant(self::aParis('2026-10-12 10:00:00'), $provisoire->expireLe);
    }

    /**
     * En clair, il ne vit que le temps de le montrer : il ne se sérialise
     * pas et un vidage de l'objet ne le porte pas.
     */
    public function test_un_mot_de_passe_provisoire_ne_se_serialise_ni_ne_se_deverse_en_clair(): void
    {
        $provisoire = app(MotsDePasseProvisoires::class)->tirer();

        $this->assertThrows(fn () => serialize($provisoire), LogicException::class);
        $this->assertStringNotContainsString($provisoire->valeur(), print_r($provisoire, true));
        $this->assertStringNotContainsString($provisoire->valeur(), var_export(json_encode($provisoire->__debugInfo()), true));
    }

    public function test_la_base_n_en_garde_que_l_empreinte_et_le_compte_cree_en_porte_la_valeur_une_fois(): void
    {
        [$compte, $motDePasse] = $this->compteAuMotDePasseProvisoire($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);

        $this->assertMatchesRegularExpression(self::FORME, $motDePasse);
        $enBase = (string) User::query()->whereKey($compte->getKey())->value('password');
        $this->assertNotSame($motDePasse, $enBase);
        $this->assertTrue(Hash::check($motDePasse, $enBase));
        $this->assertNotSame((string) $compte->identifiantDeConnexion(), $motDePasse);
        $this->assertFalse(Hash::check((string) $compte->identifiantDeConnexion(), $enBase));
        $this->assertNull(User::query()->findOrFail($compte->getKey())->motDePasseProvisoire);
    }

    public function test_on_ne_consomme_le_mot_de_passe_provisoire_qu_une_fois(): void
    {
        [$compte] = $this->compteAuMotDePasseProvisoire($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $service = app(MotsDePasseProvisoires::class);
        $this->assertSame(EtatDuMotDePasse::ProvisoireValable, $compte->refresh()->etatDuMotDePasse());

        $this->assertTrue($service->consommer($compte));
        $this->assertFalse($service->consommer($compte));

        $this->assertSame(EtatDuMotDePasse::ProvisoireUtilise, $compte->refresh()->etatDuMotDePasse());
        $this->assertTrue($compte->doitChoisirSonMotDePasse());
    }

    /**
     * À l'instant exact de son délai, et après, il ne se consomme plus :
     * il est expiré, et jamais un blocage, l'accès se redonne.
     */
    public function test_un_mot_de_passe_provisoire_expire_ne_se_consomme_pas(): void
    {
        [$compte] = $this->compteAuMotDePasseProvisoire($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $service = app(MotsDePasseProvisoires::class);
        $expire = $compte->refresh()->mot_de_passe_provisoire_expire_le;
        $this->assertNotNull($expire);

        $this->travelTo($expire->copy()->subSecond());
        $this->assertSame(EtatDuMotDePasse::ProvisoireValable, $compte->refresh()->etatDuMotDePasse());

        $this->travelTo($expire);
        $this->assertSame(EtatDuMotDePasse::ProvisoireExpire, $compte->refresh()->etatDuMotDePasse());
        $this->assertFalse($service->consommer($compte));

        $this->travelTo($expire->copy()->addHours(24));
        $this->assertFalse($service->consommer($compte));
        $this->assertSame(EtatDuMotDePasse::ProvisoireExpire, $compte->refresh()->etatDuMotDePasse());
    }

    public function test_un_mot_de_passe_choisi_ne_se_consomme_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->assertSame(EtatDuMotDePasse::Choisi, $vendeur->refresh()->etatDuMotDePasse());
        $this->assertFalse(app(MotsDePasseProvisoires::class)->consommer($vendeur));
        $this->assertFalse($vendeur->doitChoisirSonMotDePasse());
    }

    public function test_les_colonnes_de_chaque_etat_du_mot_de_passe(): void
    {
        $provisoire = new MotDePasseProvisoire('k7qp-2mzr-xa4d', self::aParis('2026-10-12 10:00:00'));

        $this->assertSame(
            ['password' => 'k7qp-2mzr-xa4d', 'mot_de_passe_choisi_le' => null, 'mot_de_passe_provisoire_expire_le' => $provisoire->expireLe, 'mot_de_passe_provisoire_utilise_le' => null],
            MotsDePasseProvisoires::colonnesDuProvisoire($provisoire),
        );

        $choisi = MotsDePasseProvisoires::colonnesDuMotDePasseChoisi('violette-2704-ocre');
        $this->assertSame('violette-2704-ocre', $choisi['password']);
        $this->assertNotNull($choisi['mot_de_passe_choisi_le']);
        $this->assertNull($choisi['mot_de_passe_provisoire_expire_le']);
        $this->assertNull($choisi['mot_de_passe_provisoire_utilise_le']);

        $sans = MotsDePasseProvisoires::colonnesSansMotDePasse();
        $this->assertNull($sans['mot_de_passe_choisi_le']);
        $this->assertNull($sans['mot_de_passe_provisoire_expire_le']);
        $this->assertNull($sans['mot_de_passe_provisoire_utilise_le']);
    }

    /**
     * Les cinq états de la fiche d'un compte : choisi, provisoire valable,
     * expiré, utilisé sans fin du choix, aucun.
     */
    public function test_l_etat_du_mot_de_passe_se_lit_de_ses_trois_colonnes(): void
    {
        $maintenant = now();
        $etat = fn (?string $choisi, ?string $expire, ?string $utilise): EtatDuMotDePasse => (new User)->forceFill([
            'mot_de_passe_choisi_le' => $choisi === null ? null : $maintenant->copy()->modify($choisi),
            'mot_de_passe_provisoire_expire_le' => $expire === null ? null : $maintenant->copy()->modify($expire),
            'mot_de_passe_provisoire_utilise_le' => $utilise === null ? null : $maintenant->copy()->modify($utilise),
        ])->etatDuMotDePasse();

        $this->assertSame(EtatDuMotDePasse::Choisi, $etat('-1 hour', null, null));
        $this->assertSame(EtatDuMotDePasse::ProvisoireValable, $etat(null, '+2 hours', null));
        $this->assertSame(EtatDuMotDePasse::ProvisoireExpire, $etat(null, '-1 second', null));
        $this->assertSame(EtatDuMotDePasse::ProvisoireUtilise, $etat(null, '+2 hours', '-1 hour'));
        $this->assertSame(EtatDuMotDePasse::ProvisoireUtilise, $etat(null, '-2 hours', '-3 hours'));
        $this->assertSame(EtatDuMotDePasse::Aucun, $etat(null, null, null));

        foreach (EtatDuMotDePasse::cases() as $cas) {
            $this->assertNotSame('', $cas->libelle());
            $this->assertNotSame('', $cas->phraseDeLaFiche());
            $this->assertSame($cas !== EtatDuMotDePasse::Choisi, $cas->estEnAttente());
        }
    }

    /**
     * Les requêtes de l'À faire et des listes : « pas encore choisis »
     * compte les comptes actifs sans mot de passe choisi, « provisoires
     * valables » ceux dont le provisoire n'a pas servi et n'a pas expiré.
     */
    public function test_les_requetes_des_comptes_dont_le_mot_de_passe_est_en_attente(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $choisi = $this->membre($magasin, RoleMagasin::Vendeur);
        [$valable] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        [$expire] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Observateur);
        $expire->forceFill(['mot_de_passe_provisoire_expire_le' => now()->subHour()])->save();
        [$utilise] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        app(MotsDePasseProvisoires::class)->consommer($utilise);

        $enAttente = MotsDePasseProvisoires::pasEncoreChoisis(User::query()->where('societe_id', $magasin->societe_id))->pluck('users.id')->all();
        $this->assertEqualsCanonicalizing([$valable->id, $expire->id, $utilise->id], $enAttente);
        $this->assertNotContains($choisi->id, $enAttente);

        $valables = MotsDePasseProvisoires::provisoiresValables(User::query()->where('societe_id', $magasin->societe_id))->pluck('users.id')->all();
        $this->assertSame([$valable->id], $valables);
    }

    public function test_la_saisie_d_un_mot_de_passe_provisoire_se_normalise(): void
    {
        $this->assertSame('k7qp-2mzr-xa4d', MotsDePasseProvisoires::normaliser(" K7QP-2mzr-XA4D \n"));
    }

    /**
     * Qui a choisi son mot de passe ne peut pas garder le provisoire que
     * la société lui a remis : le choisir de nouveau est refusé, quelle
     * que soit la casse ou les espaces ; un autre mot de passe passe.
     */
    public function test_le_mot_de_passe_remis_ne_se_garde_jamais_comme_mot_de_passe_choisi(): void
    {
        [$compte, $remis] = $this->compteAuMotDePasseProvisoire($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $compte->refresh();

        foreach ([$remis, strtoupper($remis), "  {$remis}  "] as $ecriture) {
            $this->assertTrue(MotsDePasseProvisoires::estCeluiQuiAEteRemis($compte, $ecriture), $ecriture);
            $this->assertThrows(fn () => MotsDePasseProvisoires::exigerUnAutreQueCeluiQuiAEteRemis($compte, $ecriture, 'password'), ValidationException::class, MotsDePasseProvisoires::MESSAGE_CELUI_QUI_A_ETE_REMIS);
        }

        $this->assertFalse(MotsDePasseProvisoires::estCeluiQuiAEteRemis($compte, 'violette-2704-ocre'));
        MotsDePasseProvisoires::exigerUnAutreQueCeluiQuiAEteRemis($compte, 'violette-2704-ocre', 'password');

        $compte->forceFill(MotsDePasseProvisoires::colonnesDuMotDePasseChoisi($remis))->save();
        $this->assertFalse(MotsDePasseProvisoires::estCeluiQuiAEteRemis($compte->refresh(), $remis));
    }

    /**
     * Le mot de passe provisoire respecte lui-même la règle des mots de
     * passe choisis sauf la longueur : il n'est jamais un mot de passe
     * courant ni un identifiant.
     */
    public function test_un_tirage_ne_porte_aucun_reproche_hors_la_longueur_et_la_composition(): void
    {
        $service = app(MotsDePasseProvisoires::class);

        foreach (range(1, 100) as $rang) {
            $reproches = RegleDuMotDePasse::reproches($service->tirer()->valeur(), '48210937');
            $this->assertNotContains(RegleDuMotDePasse::MESSAGE_COURANT, $reproches);
            $this->assertNotContains(RegleDuMotDePasse::MESSAGE_IDENTIFIANT, $reproches);
        }
    }
}
