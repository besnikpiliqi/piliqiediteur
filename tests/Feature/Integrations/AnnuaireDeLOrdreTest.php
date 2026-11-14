<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Ordre\AnnuaireDeLOrdreIndisponibleException;
use App\Integrations\Ordre\AnnuaireTenuParLEditeur;
use App\Integrations\Ordre\Contracts\AnnuaireDeLOrdre;
use App\Integrations\Ordre\FauxAnnuaireDeLOrdre;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Providers\FournisseursExternesServiceProvider;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * L'annuaire de l'Ordre des experts-comptables (voir AnnuaireDeLOrdre) :
 * le pilote factice des tests, automatique, connaît le cabinet de test et
 * se règle ; le pilote de production, tenu par l'éditeur, n'est pas
 * automatique et ne lit rien ; le pilote factice ne sert jamais en
 * production. Aucune requête ne part.
 */
class AnnuaireDeLOrdreTest extends TestCase
{
    public function test_le_faux_annuaire_connait_le_cabinet_de_test_et_se_regle(): void
    {
        Http::preventStrayRequests();
        $annuaire = new FauxAnnuaireDeLOrdre;

        $this->assertTrue($annuaire->estAutomatique());
        $inscription = $annuaire->inscription(FauxRepertoireSirene::SIREN_CABINET);
        $this->assertSame([FauxRepertoireSirene::SIREN_CABINET, FauxAnnuaireDeLOrdre::NUMERO_DU_CABINET_DE_TEST, FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST, true], [$inscription->siren, $inscription->numero, $inscription->conseilRegional, $inscription->inscrite]);
        $this->assertNull($annuaire->inscription('000000018'));

        $annuaire->inscrire('000000018', '69-00042', 'Auvergne-Rhône-Alpes');
        $this->assertSame(['69-00042', true], [$annuaire->inscription('000000018')->numero, $annuaire->inscription('000000018')->inscrite]);

        $annuaire->radier('000000018');
        $this->assertSame(['69-00042', false], [$annuaire->inscription('000000018')->numero, $annuaire->inscription('000000018')->inscrite]);

        $annuaire->rendreInjoignable();
        $this->assertThrows(fn () => $annuaire->inscription(FauxRepertoireSirene::SIREN_CABINET), AnnuaireDeLOrdreIndisponibleException::class);
    }

    public function test_l_annuaire_tenu_par_l_editeur_n_est_pas_automatique_et_refuse_toute_lecture(): void
    {
        config(['services.ordre.pilote' => 'editeur']);
        $this->app->forgetInstance(AnnuaireDeLOrdre::class);
        $annuaire = $this->app->make(AnnuaireDeLOrdre::class);

        $this->assertInstanceOf(AnnuaireTenuParLEditeur::class, $annuaire);
        $this->assertFalse($annuaire->estAutomatique());
        $this->assertThrows(fn () => $annuaire->inscription(FauxRepertoireSirene::SIREN_CABINET), LogicException::class);
    }

    public function test_le_pilote_faux_de_l_ordre_est_refuse_en_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['services.sirene.pilote' => 'insee', 'services.rne.pilote' => 'inpi', 'services.recommande.pilote' => null, 'services.ordre.pilote' => 'faux', 'services.pdp.pilote' => null]);

        $this->assertThrows(fn () => (new FournisseursExternesServiceProvider($this->app))->boot(), RuntimeException::class, 'Le fournisseur factice de « ordre » (pilote faux) ne sert jamais en production.');

        config(['services.ordre.pilote' => 'editeur']);
        (new FournisseursExternesServiceProvider($this->app))->boot();
        $this->app->detectEnvironment(fn (): string => 'testing');
    }
}
