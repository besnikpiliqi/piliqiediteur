<?php

namespace Tests\Feature\Authorization;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\PosteReconnu;
use App\Domain\Tenancy\Services\PostesReconnus;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les postes reconnus d'un magasin (voir PosteReconnuPolicy) : son
 * propriétaire et ses responsables les voient, en reconnaissent un et
 * retirent un poste qui ne l'est pas encore ; personne d'autre, et jamais
 * le poste d'un autre magasin.
 */
class PosteReconnuPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seuls_le_proprietaire_et_un_responsable_voient_reconnaissent_et_retirent_les_postes_du_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $postes = app(PostesReconnus::class);
        $posteDAilleurs = $postes->reconnaitre($proprietaire, $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre boutique']), 'Caisse de l\'autre boutique', request());
        app(TenantContext::class)->set($magasin);
        $poste = $postes->reconnaitre($proprietaire, $magasin, 'Caisse 1', request());
        $retire = $postes->reconnaitre($proprietaire, $magasin, 'Ancienne caisse', request());
        $postes->retirer($proprietaire, $retire);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        app(TenantContext::class)->set($magasin);

        foreach ([$proprietaire, $responsable] as $gerant) {
            $this->assertSame([true, true, true, true, false, false], [
                $gerant->can('viewAny', PosteReconnu::class),
                $gerant->can('view', $poste),
                $gerant->can('create', PosteReconnu::class),
                $gerant->can('update', $poste),
                $gerant->can('update', $retire->fresh()),
                $gerant->can('view', $posteDAilleurs),
            ], $gerant->name);
        }

        $this->assertSame([false, false, false, false], [
            $vendeur->can('viewAny', PosteReconnu::class),
            $vendeur->can('view', $poste),
            $vendeur->can('create', PosteReconnu::class),
            $vendeur->can('update', $poste),
        ]);
    }
}
