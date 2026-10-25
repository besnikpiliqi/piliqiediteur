<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * La page d'un document que sa suppression garde en base (un devis, voir
 * SupprimerDevisAction), restée ouverte pendant qu'une autre requête le
 * supprimait. Livewire relit le document de la page à chaque geste sans
 * les portées de son modèle (voir Model::newQueryForRestoration()) : il
 * le retrouverait, supprimé, et la page continuerait sur lui (le bouton
 * « Supprimer » disparaîtrait sans rien dire). Son absence se dit donc
 * comme celle d'un brouillon supprimé de la base : rien ne s'exécute, et
 * la personne revient à la liste, qui dit que ce document n'existe pas,
 * ou plus, dans ce magasin (voir ReponseAUnDocumentDisparu).
 */
trait RevientALaListeSiLeDocumentEstSupprime
{
    /**
     * Après le contrôle d'accès de la page (voir
     * InteractsWithRecord::hydrateCanAuthorizeAccess()), avant tout geste.
     *
     * @throws ModelNotFoundException
     */
    public function hydrateRevientALaListeSiLeDocumentEstSupprime(): void
    {
        $document = $this->getRecord();

        if ($document->trashed()) {
            throw (new ModelNotFoundException)->setModel($document::class, [$document->getKey()]);
        }
    }
}
