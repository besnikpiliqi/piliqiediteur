<?php

namespace App\Filament;

use App\Domain\Tenancy\Enums\StatutDuTelechargement;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * La section « Vos téléchargements des sept derniers jours » de la page
 * « Télécharger les documents » d'un magasin, de l'espace de la société et
 * de l'espace du cabinet (voir TelechargeLesDocumentsParPeriode), dans son
 * propre composant (constat de l'éditeur du 15/11/2026, lot DATEOP) : tant
 * qu'au moins une demande de la personne est en préparation, elle se relit
 * d'elle-même toutes les cinq secondes, seule, sans recharger le reste de la
 * page ni toucher à ses cases cochées ni à sa page de liste, et s'arrête dès
 * qu'aucune ne l'est plus : la ligne devient « prêt » avec son lien, ou dit
 * l'échec et sa cause, sans rechargement. Une demande encore en préparation
 * deux minutes après avoir été faite dit que le service des tâches en
 * arrière-plan doit tourner (voir le README). La page la relit aussitôt
 * qu'elle confie une demande à la file d'attente (voir DEMANDE).
 *
 * Chaque relecture ne lit que les demandes de la personne connectée dans
 * cette société (voir TelechargementDeDocuments::recentsDe()), la société
 * se retrouvant par son uuid, que la page lui donne et que le navigateur
 * ne change pas.
 */
final class VosTelechargements extends Component
{
    /**
     * Le nom du composant, enregistré par AppServiceProvider.
     */
    public const NOM = 'vos-telechargements';

    /**
     * L'événement que la page envoie quand elle confie une demande à la
     * file d'attente.
     */
    public const DEMANDE = 'piliqi-telechargement-demande';

    /**
     * L'intervalle des relectures, tant qu'une demande est en préparation.
     */
    public const INTERVALLE = '5s';

    /**
     * Après combien de minutes une demande encore en préparation dit que
     * le service des tâches en arrière-plan doit tourner.
     */
    public const MINUTES_AVANT_L_AVERTISSEMENT = 2;

    public const TRAVAILLEUR_ARRETE = 'La préparation n\'a pas encore commencé : le service des tâches en arrière-plan doit tourner (voir le README).';

    /**
     * L'uuid de la société dont la page lit les documents.
     */
    #[Locked]
    public string $societe = '';

    /**
     * La page vient de confier une demande à la file d'attente : la section
     * se relit (le rendu suit chaque appel).
     */
    #[On(self::DEMANDE)]
    public function relire(): void {}

    public function render(): View
    {
        $telechargements = $this->telechargements();

        return view('filament.vos-telechargements', [
            'telechargements' => $telechargements,
            'enPreparation' => $telechargements->contains(fn (TelechargementDeDocuments $telechargement): bool => $telechargement->statut === StatutDuTelechargement::EnPreparation),
            'avertissementDepuis' => now()->subMinutes(self::MINUTES_AVANT_L_AVERTISSEMENT),
        ]);
    }

    /**
     * Les téléchargements récents de la personne connectée dans cette
     * société ; aucun pour un uuid qui ne désigne aucune société.
     *
     * @return Collection<int, TelechargementDeDocuments>
     */
    private function telechargements(): Collection
    {
        $compte = auth()->user();
        $societe = $compte instanceof User && $this->societe !== '' ? Societe::query()->where('uuid', $this->societe)->first() : null;

        return $compte instanceof User && $societe instanceof Societe
            ? TelechargementDeDocuments::recentsDe($compte, $societe)->get()->each->setRelation('demandeur', $compte)->toBase()
            : new Collection;
    }
}
