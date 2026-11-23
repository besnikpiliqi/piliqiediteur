<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;

/**
 * À utiliser sur tout Job de queue qui touche des données scopées par
 * magasin. Un worker de queue n'a pas de session HTTP : sans ce trait, le
 * TenantContext (binding "scoped", voir TenancyServiceProvider) démarre
 * vide pour chaque job et toute requête sur un modèle BelongsToMagasin
 * lèverait TenantContextMissingException -- comportement voulu (fail
 * closed) plutôt que d'hériter silencieusement du tenant du job précédent
 * exécuté sur le même worker.
 *
 * La classe qui utilise ce trait DOIT déclarer sa propre propriété
 * publique `int $magasinId` (sérialisée avec le job) et l'initialiser
 * dans son constructeur à partir du magasin concerné -- jamais depuis
 * auth()->user() au moment du handle(), qui peut avoir changé entre le
 * dispatch et l'exécution.
 *
 * Exemple :
 *
 *   class SubmitInvoiceToPdpAction implements ShouldQueue
 *   {
 *       use Queueable, WithTenantContext;
 *
 *       public function __construct(public int $magasinId, public int $factureId) {}
 *
 *       public function handle(): void
 *       {
 *           $this->demarrerContexteTenant();
 *           // ... à partir d'ici, TenantContext::id() renvoie $this->magasinId
 *       }
 *   }
 */
trait WithTenantContext
{
    protected function demarrerContexteTenant(): void
    {
        $magasin = Magasin::query()->findOrFail($this->magasinId);

        app(TenantContext::class)->set($magasin);
    }
}
