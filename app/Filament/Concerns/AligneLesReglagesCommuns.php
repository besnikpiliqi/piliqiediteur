<?php

namespace App\Filament\Concerns;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ReglagesCommunsDeLaSociete;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Illuminate\Validation\ValidationException;

/**
 * Les pages où se règlent les réglages communs aux magasins d'une même
 * société (voir ReglagesCommunsDeLaSociete) : Identité du magasin et
 * Paramètres fiscaux. Un encadré en tête de la page dit ce que le magasin
 * doit encore aligner sur le magasin principal de son SIREN, d'après ce
 * qui est enregistré ; à l'enregistrement, chaque champ commun de la page
 * dont la valeur n'est pas celle du principal est refusé, avec la valeur
 * à prendre.
 */
trait AligneLesReglagesCommuns
{
    /**
     * L'encadré des réglages à aligner, masqué quand le magasin est
     * aligné, seul de son SIREN, ou lui-même le principal.
     */
    protected function encadreDesReglagesAAligner(): Section
    {
        return Section::make('Réglages à aligner sur le magasin principal de votre société')
            ->icon('heroicon-o-exclamation-triangle')
            ->iconColor('danger')
            ->schema([
                Placeholder::make('reglages_a_aligner')
                    ->hiddenLabel()
                    ->content(fn (): ?string => $this->refusDEmission()),
            ])
            ->visible(fn (): bool => $this->refusDEmission() !== null);
    }

    /**
     * Refuse ce que la page enregistre quand un réglage commun n'y est pas
     * celui du principal : chaque champ commun de la page en écart dit la
     * valeur à prendre. Un SIREN tout juste saisi exige aussi les
     * réglages communs qui ne sont pas sur la page, les paramètres
     * fiscaux pour l'identité du magasin : le SIREN le dit. $donnees
     * s'appliquent à une copie du magasin du panneau, que l'écran
     * continue de montrer tel qu'il est enregistré.
     *
     * @param  array<string, mixed>  $donnees  les champs de la page, tels qu'ils s'enregistrent
     *
     * @throws ValidationException
     */
    protected function refuserLesReglagesCommunsDifferents(array $donnees): void
    {
        $apercu = (clone $this->magasinDesReglages())->fill($donnees);
        $ecarts = app(ReglagesCommunsDeLaSociete::class)->ecarts($apercu);
        $erreurs = [];

        foreach (array_intersect_key($ecarts, $donnees) as $champ => $valeurDuPrincipal) {
            $erreurs["data.{$champ}"] = ReglagesCommunsDeLaSociete::messageDuChamp($apercu->siren, $champ, $valeurDuPrincipal);
        }

        $horsDeLaPage = array_diff_key($ecarts, $donnees);

        if ($horsDeLaPage !== [] && $apercu->isDirty('siren')) {
            $erreurs['data.siren'] = ReglagesCommunsDeLaSociete::messageDuSiren($horsDeLaPage);
        }

        if ($erreurs !== []) {
            throw ValidationException::withMessages($erreurs);
        }
    }

    private function refusDEmission(): ?string
    {
        return app(ReglagesCommunsDeLaSociete::class)->refusDEmission($this->magasinDesReglages());
    }

    private function magasinDesReglages(): Magasin
    {
        /** @var Magasin */
        return Filament::getTenant();
    }
}
