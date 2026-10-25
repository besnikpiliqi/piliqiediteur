<?php

namespace App\Filament\Concerns;

use App\Domain\Contacts\Actions\ModifierUneFicheAction;
use App\Domain\Tenancy\Support\FicheLue;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * La page de modification d'une fiche du magasin (un client, un
 * fournisseur, un produit) : elle garde la fiche telle qu'elle l'a lue à
 * son ouverture (voir FicheLue), sans que le navigateur puisse la changer,
 * et l'enregistrement passe par ModifierUneFicheAction, qui n'écrit que ce
 * que la personne a corrigé depuis cette lecture, refuse une correction
 * sur un attribut qu'une autre personne a changé entre-temps, et relit
 * sous verrou le magasin, son auteur et la fiche. Un refus se lit en
 * notification, et la saisie reste à l'écran.
 */
trait EnregistreLesCorrectionsDeLaFiche
{
    use GereLesExceptionsMetier;

    /**
     * La fiche telle que la page l'a lue (voir FicheLue::valeurs()).
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $ficheLue = [];

    protected function fillForm(): void
    {
        $this->ficheLue = FicheLue::de($this->getRecord())->valeurs();

        parent::fillForm();
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $enregistree = static::executerOuInterrompre(fn (): Model => app(ModifierUneFicheAction::class)->handle(auth()->user(), $record, $this->ficheLue, $data));
        $this->ficheLue = FicheLue::de($enregistree)->valeurs();

        return $enregistree;
    }
}
