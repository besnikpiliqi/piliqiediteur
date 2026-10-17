<?php

namespace App\Filament\App\Resources\FactureResource\Pages;

use App\Domain\Facturation\Actions\AjouterPreuveOperationAction;
use App\Domain\Facturation\Actions\NoterBordereauDetaxeAction;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\PreuvesRequises;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\FactureResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * La fiche d'une facture ou d'un ticket. Ce qui suit la vente sans la
 * changer s'y enregistre, à qui peut l'émettre (voir
 * FacturePolicy::justifier()) : les preuves de son exonération (voir
 * AjouterPreuveOperationAction) et le bordereau de détaxe d'un ticket
 * (voir NoterBordereauDetaxeAction). Chaque bouton n'apparaît que là où
 * son action l'accepte (voir leurs refusPour()). Son retour passe par
 * « Avoir », qui ouvre la recherche par numéro de « Nouvel avoir » sur ce
 * document (voir faireUnAvoir()).
 */
class ViewFacture extends ViewRecord
{
    use GereLesExceptionsMetier;

    protected static string $resource = FactureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FactureResource::telechargerLePdf(Actions\Action::make('telecharger_pdf')),
            FactureResource::envoyerParEmail(Actions\Action::make('envoyer_par_email')),
            FactureResource::relancer(Actions\Action::make('relancer')),
            $this->faireUnAvoir(),
            $this->ajouterUnePreuve(),
            $this->noterLeBordereauDeDetaxe(),
        ];
    }

    /**
     * « Avoir » : la page « Nouvel avoir », dont la recherche par numéro
     * part de ce ticket ou de cette facture (voir CreateAvoir::afterFill()),
     * où d'autres documents du même client peuvent s'ajouter. Le bouton
     * apparaît comme l'action « Avoir » de la liste des factures (voir
     * AvoirResource::peutCrediter()).
     */
    private function faireUnAvoir(): Actions\Action
    {
        return Actions\Action::make('avoir')
            ->label('Avoir')
            ->icon('heroicon-o-receipt-refund')
            ->color('danger')
            ->visible(fn (Facture $record): bool => AvoirResource::peutCrediter($record))
            ->url(fn (Facture $record): string => AvoirResource::getUrl('create', ['document' => $record->numero]));
    }

    /**
     * Un document d'un type que la nature de la vente admet (voir
     * PreuvesRequises::typesAdmis()), avec sa référence, sa date et qui
     * l'a établi. Le type se valide sur le serveur parmi ceux que la liste
     * propose : un autre, envoyé sans passer par elle, est une erreur de
     * saisie.
     */
    private function ajouterUnePreuve(): Actions\Action
    {
        return Actions\Action::make('ajouter_preuve')
            ->label('Ajouter une preuve')
            ->icon('heroicon-o-document-check')
            ->visible(fn (Facture $record): bool => AjouterPreuveOperationAction::refusPour($record) === null)
            ->authorize('justifier')
            ->form(function (Facture $record): array {
                $types = collect(PreuvesRequises::typesAdmis($record->nature_operation))
                    ->mapWithKeys(fn (TypePreuve $type): array => [$type->value => $type->getLabel()])
                    ->all();

                return [
                    Select::make('type')
                        ->label('Type de preuve')
                        ->options($types)
                        ->required()
                        ->in(array_keys($types))
                        ->validationMessages(['in' => 'Choisissez un type de preuve proposé pour cette vente.']),
                    TextInput::make('reference')
                        ->label('Référence')
                        ->helperText('Le n° que porte le document : MRN, n° du CN23, de la lettre de voiture, de la facture du transporteur...')
                        ->required()
                        ->maxLength(100),
                    DatePicker::make('date_document')
                        ->label('Date du document')
                        ->maxDate(today()),
                    TextInput::make('emetteur')
                        ->label('Établie par')
                        ->helperText('La douane, le transporteur, l\'assureur ou l\'acquéreur. Deux documents de transport doivent venir de deux parties indépendantes du magasin et du client.')
                        ->maxLength(150),
                ];
            })
            ->action(fn (Facture $record, array $data) => static::executerSansCasser(function () use ($record, $data): void {
                app(AjouterPreuveOperationAction::class)->handle(auth()->user(), $record, TypePreuve::from($data['type']), $data['reference'], $data['date_document'] ?? null, $data['emetteur'] ?? null);

                Notification::make()->title('Preuve enregistrée.')->success()->send();
            }));
    }

    /**
     * Le n° du bordereau émis dans PABLO pour un voyageur : noté sur la
     * fiche, jamais sur le ticket. Des biens taxés emportés pour 100 € TTC
     * ou moins se détaxent quand même, mais la notification l'avertit
     * (voir BordereauDetaxe::avertissement()).
     */
    private function noterLeBordereauDeDetaxe(): Actions\Action
    {
        return Actions\Action::make('noter_bordereau_detaxe')
            ->label('Noter le bordereau de détaxe (BVE)')
            ->icon('heroicon-o-globe-europe-africa')
            ->visible(fn (Facture $record): bool => NoterBordereauDetaxeAction::refusPour($record) === null)
            ->authorize('justifier')
            ->modalDescription('Le ticket reste à la TVA française : sa TVA ne se régularise qu\'après le visa du bordereau à la sortie de l\'Union européenne. Une faute se corrige en notant le bon n°, le dernier noté fait foi.')
            ->form([
                TextInput::make('numero')
                    ->label('N° du bordereau (BVE)')
                    ->required()
                    ->maxLength(30),
            ])
            ->action(fn (Facture $record, array $data) => static::executerSansCasser(function () use ($record, $data): void {
                $bordereau = app(NoterBordereauDetaxeAction::class)->handle(auth()->user(), $record, $data['numero']);
                $notification = Notification::make()->title("Bordereau de détaxe n° {$bordereau->numero} noté.");
                $avertissement = $bordereau->avertissement();

                ($avertissement === null ? $notification->success() : $notification->warning()->body($avertissement))->send();
            }));
    }
}
