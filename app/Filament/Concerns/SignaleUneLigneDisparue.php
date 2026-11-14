<?php

namespace App\Filament\Concerns;

use Filament\Notifications\Notification;

/**
 * La liste d'une ressource du magasin dont une ligne peut sortir pendant
 * qu'elle est affichée : un brouillon supprimé, émis ou validé par une
 * autre requête, une fiche archivée ou restaurée, une ligne qui ne répond
 * plus aux filtres. Filament ne monte pas l'action d'une ligne qui n'est
 * plus dans la liste, sans rien dire, et, quand sa fenêtre (une
 * confirmation, un formulaire) était déjà ouverte, la garde ouverte sur
 * une ligne disparue, ce qui finit en erreur du serveur au geste suivant.
 * Ici, dans les deux cas, rien ne s'exécute, la fenêtre se ferme et la
 * liste dit pourquoi ; elle se réaffiche sans la ligne, ou avec son état
 * du moment.
 */
trait SignaleUneLigneDisparue
{
    public const LIGNE_DISPARUE = 'Cette ligne n\'est plus dans la liste : elle a été supprimée, archivée ou modifiée entre-temps, et rien n\'a été fait.';

    /**
     * Le bouton d'une ligne qui n'est plus dans la liste.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function mountTableAction(string $name, ?string $record = null, array $arguments = []): mixed
    {
        if (filled($record) && blank($this->mountedTableActions) && $this->getTableRecord($record) === null) {
            $this->signalerLaLigneDisparue();

            return null;
        }

        return parent::mountTableAction($name, $record, $arguments);
    }

    /**
     * La fenêtre ouverte d'une ligne sortie de la liste depuis : à chaque
     * requête, une fois le tableau construit (voir
     * InteractsWithTable::bootedInteractsWithTable()), avant tout geste.
     */
    public function bootedSignaleUneLigneDisparue(): void
    {
        if (filled($this->mountedTableActionRecord) && $this->getMountedTableActionRecord() === null) {
            $this->unmountTableAction();
            $this->signalerLaLigneDisparue();
        }
    }

    private function signalerLaLigneDisparue(): void
    {
        Notification::make()->title(self::LIGNE_DISPARUE)->warning()->send();
    }
}
