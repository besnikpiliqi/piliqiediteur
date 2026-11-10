<?php

namespace App\Filament\Cabinet\Pages;

use App\Domain\Cabinets\Actions\VerifierLOrdreAction;
use App\Domain\Cabinets\Models\Cabinet;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Filament\Societe\Pages\VerificationSociete;
use App\Integrations\Ordre\Contracts\AnnuaireDeLOrdre;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;

/**
 * La vérification d'un cabinet d'expertise comptable, dans l'espace du
 * cabinet : où en est son courrier recommandé, avec les mêmes gestes que
 * la vérification d'une société (un nouveau courrier, des pièces pour
 * l'éditeur, voir VerificationSociete), et, tant que le code n'est pas
 * saisi, le rappel de son type d'activité (la vue partielle
 * filament.rappel-du-type) ; son code d'activité lu au répertoire Sirene,
 * contrôlé ou à contrôler par l'éditeur ; son inscription au tableau de
 * l'Ordre, vérifiée (par l'annuaire ou par l'éditeur), demandée, refusée
 * avec son motif ou retirée, et, pour son propriétaire seul, le formulaire
 * qui la fait vérifier (voir VerifierLOrdreAction) : « Vérifier » avec un
 * annuaire automatique, « Demander la vérification » sinon. Ses
 * collaborateurs la consultent. Ni magasin ni démonstration : un cabinet
 * n'en a aucun.
 */
class VerificationDuCabinet extends VerificationSociete
{
    protected static string $view = 'filament.cabinet.pages.verification-du-cabinet';

    public static function canAccess(): bool
    {
        $societe = Filament::getTenant();

        return $societe instanceof Societe && $societe->estUnCabinet() && (auth()->user()?->can('view', $societe) ?? false);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Vérification du cabinet';
    }

    /**
     * La vérification propre au cabinet : son code d'activité et son
     * inscription à l'Ordre.
     */
    public function verification(): ?Cabinet
    {
        return $this->societe()->cabinet()->first();
    }

    public function annuaireAutomatique(): bool
    {
        return app(AnnuaireDeLOrdre::class)->estAutomatique();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->verifierLOrdreAction(),
            ...parent::getHeaderActions(),
        ];
    }

    public function verifierLOrdreAction(): Action
    {
        return Action::make('verifierLOrdre')
            ->label(fn (): string => $this->annuaireAutomatique() ? 'Vérifier mon inscription à l\'Ordre' : 'Demander la vérification de mon inscription à l\'Ordre')
            ->icon('heroicon-o-academic-cap')
            ->visible(fn (): bool => ($verification = $this->verification()) !== null && (auth()->user()?->can('verifierLOrdre', $verification) ?? false))
            ->modalDescription(fn (): string => $this->annuaireAutomatique()
                ? 'L\'annuaire de l\'Ordre est consulté pour le SIREN de votre cabinet.'
                : 'L\'éditeur vérifie votre inscription au tableau de l\'Ordre et vous prévient.')
            ->form([
                TextInput::make('numero_ordre')->label('N° d\'inscription au tableau')->required()->maxLength(30),
                TextInput::make('conseil_regional')->label('Conseil régional')->required()->maxLength(100),
            ])
            ->action(fn (array $data) => static::executerSansCasser(function () use ($data): void {
                app(VerifierLOrdreAction::class)->handle(CompteConnecte::exiger(), $this->societe(), $data);
                Notification::make()
                    ->title($this->annuaireAutomatique() ? 'Inscription à l\'Ordre vérifiée.' : 'Demande envoyée : l\'éditeur vérifie votre inscription et vous prévient.')
                    ->success()
                    ->send();
            }));
    }
}
