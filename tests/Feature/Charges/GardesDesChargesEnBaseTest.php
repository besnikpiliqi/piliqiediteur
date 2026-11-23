<?php

namespace Tests\Feature\Charges;

use App\Domain\Charges\Actions\ValiderUneChargeAction;
use App\Domain\Charges\Support\GardesDesCharges;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Les gardes de la base sur les charges de la société (voir
 * GardesDesCharges) : une charge validée ne s'efface jamais et ne s'écrit
 * que par une correction comptée, ses lignes non plus, sa pièce ne se
 * modifie ni ne s'efface ; une charge validée a sa pièce, une charge à
 * valider ses lignes ; jamais dans un cabinet ; l'état et la validation
 * restent cohérents.
 */
class GardesDesChargesEnBaseTest extends TestCase
{
    use PrepareDesCharges;
    use RefreshDatabase;

    private Magasin $magasin;

    private Societe $societe;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disqueDesCharges();
        $this->magasin = $this->ouvrirUneSociete();
        $this->societe = $this->magasin->societe;
        $this->proprietaire = $this->proprietaireDe($this->magasin);
        app(TenantContext::class)->clear();
    }

    public function test_une_charge_validee_ne_s_efface_ni_ne_s_ecrit_hors_d_une_correction(): void
    {
        $charge = $this->deposer($this->proprietaire, $this->societe);
        app(ValiderUneChargeAction::class)->handle($this->proprietaire, $this->societe, $charge->uuid);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('charges')->where('id', $charge->id)->delete()), QueryException::class, 'charges_validee_jamais_effacee');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('charges')->where('id', $charge->id)->update(['total_ht' => 1])), QueryException::class, 'charges_validee_figee');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('charges')->where('id', $charge->id)->update(['etat' => 'a_valider', 'validee_par' => null, 'validee_le' => null, 'validee_par_compte' => null, 'validee_par_nom' => null])), QueryException::class, 'charges_validee_figee');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('charges')->where('id', $charge->id)->update(['corrections' => 1, 'validee_par' => 'cabinet'])), QueryException::class, 'charges_validee_figee');
        $this->assertTrue(DB::table('charges')->where('id', $charge->id)->update(['corrections' => 1, 'total_ht' => 1]) === 1);

        $ligne = DB::table('lignes_de_charge')->where('charge_id', $charge->id)->first();
        $this->assertNotNull($ligne);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('lignes_de_charge')->where('id', $ligne->id)->update(['ht' => 2])), QueryException::class, 'lignes_de_charge_d_une_charge_validee');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('lignes_de_charge')->insert([...(array) $ligne, 'id' => null, 'taux' => 1000, 'version' => 0])), QueryException::class, 'lignes_de_charge_d_une_charge_validee');

        $piece = DB::table('pieces_de_charge')->where('charge_id', $charge->id)->first();
        $this->assertNotNull($piece);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('pieces_de_charge')->where('id', $piece->id)->delete()), QueryException::class, 'pieces_de_charge_effacees_avant_la_validation');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('pieces_de_charge')->where('id', $piece->id)->update(['nom' => 'autre.pdf'])), QueryException::class, 'pieces_de_charge_jamais_modifiees');
    }

    /**
     * Les lignes d'une charge validée ne s'ajoutent ni ne s'effacent hors de
     * la transaction de sa correction, même de sa version : une ligne
     * fantôme à 10 % ne s'ajoute pas à une charge validée jamais corrigée.
     */
    public function test_les_lignes_d_une_charge_validee_ne_s_ecrivent_que_dans_sa_correction(): void
    {
        $charge = $this->deposer($this->proprietaire, $this->societe);
        app(ValiderUneChargeAction::class)->handle($this->proprietaire, $this->societe, $charge->uuid);
        $ligne = DB::table('lignes_de_charge')->where('charge_id', $charge->id)->first();
        $this->assertNotNull($ligne);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('lignes_de_charge')->insert([...(array) $ligne, 'id' => null, 'taux' => 1000, 'version' => 0])), QueryException::class, 'lignes_de_charge_d_une_charge_validee');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('lignes_de_charge')->where('id', $ligne->id)->delete()), QueryException::class, 'lignes_de_charge_d_une_charge_validee');
        $this->assertSame(1, DB::table('lignes_de_charge')->where('charge_id', $charge->id)->count());

        DB::table('charges')->where('id', $charge->id)->update(['corrections' => 1]);
        $this->assertSame(1, DB::table('lignes_de_charge')->where('id', $ligne->id)->delete());
        $this->assertTrue(DB::table('lignes_de_charge')->insert(array_diff_key([...(array) $ligne, 'version' => 1], ['id' => true])));
    }

    public function test_avant_sa_validation_une_charge_n_a_aucune_correction(): void
    {
        $charge = $this->deposer($this->proprietaire, $this->societe);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('charges')->where('id', $charge->id)->update(['corrections' => 1])), QueryException::class, 'charges_validee_figee');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('charges')->where('id', $charge->id)->update(['etat' => 'validee'])), QueryException::class, 'charges_validee_avec_sa_validation');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('charges')->where('id', $charge->id)->update(['etat' => 'renvoyee'])), QueryException::class, 'charges_renvoyee_avec_son_mot');
        $this->assertSame(1, DB::table('charges')->where('id', $charge->id)->update(['total_ht' => 5]));
    }

    public function test_une_charge_validee_a_sa_piece_et_une_charge_a_valider_ses_chiffres(): void
    {
        $sansPiece = $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'S-1']), avecLaPiece: false);
        $avecPiece = $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:gaz', champs: ['numero_piece' => 'G-1']));
        DB::statement('SET CONSTRAINTS '.GardesDesCharges::COHERENTE.', '.GardesDesCharges::COHERENTE.'_lignes IMMEDIATE');

        $validation = ['etat' => 'validee', 'validee_par' => 'proprietaire', 'validee_le' => now(), 'validee_par_compte' => $this->proprietaire->id, 'validee_par_nom' => 'Propriétaire'];
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('charges')->where('id', $sansPiece->id)->update($validation)), QueryException::class, GardesDesCharges::COHERENTE);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('lignes_de_charge')->where('charge_id', $avecPiece->id)->delete()), QueryException::class, GardesDesCharges::COHERENTE);
        $this->assertSame(1, DB::table('charges')->where('id', $avecPiece->id)->update($validation));
    }

    /**
     * Une charge n'existe jamais dans un cabinet d'expertise comptable ; son
     * magasin est un magasin de sa société : la clé étrangère composite
     * (societe_id, magasin_id) refuse un magasin qui n'existe pas, ou celui
     * d'une autre société.
     */
    public function test_une_charge_n_existe_ni_dans_un_cabinet_ni_dans_un_magasin_inconnu_ou_d_une_autre_societe(): void
    {
        $charge = (array) DB::table('charges')->where('id', $this->deposer($this->proprietaire, $this->societe)->id)->first();
        $cabinet = $this->ouvrirUnCabinet();
        $autreMagasin = $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        app(TenantContext::class)->clear();
        $inconnu = (int) DB::table('magasins')->max('id') + 1000;
        $avant = DB::table('charges')->count();
        $copie = fn (array $champs): array => [...array_diff_key($charge, ['id' => true]), 'uuid' => (string) Str::uuid(), 'etat' => 'piece_sans_chiffres', 'total_ht' => 0, 'total_tva' => 0, 'total_ttc' => 0, 'total_tva_deductible' => 0, ...$champs];

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('charges')->insert($copie(['societe_id' => $cabinet->id, 'magasin_id' => null]))), QueryException::class, 'jamais dans un cabinet');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('charges')->insert($copie(['magasin_id' => $inconnu]))), QueryException::class, 'charges_societe_id_magasin_id_foreign');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('charges')->insert($copie(['magasin_id' => $autreMagasin->id]))), QueryException::class, 'charges_societe_id_magasin_id_foreign');
        $this->assertSame($avant, DB::table('charges')->count());
    }
}
