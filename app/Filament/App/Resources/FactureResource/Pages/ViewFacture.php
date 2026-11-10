<?php

namespace App\Filament\App\Resources\FactureResource\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Actions\AjouterPreuveOperationAction;
use App\Domain\Facturation\Actions\EmettreLaFactureRectificativeAction;
use App\Domain\Facturation\Actions\FaireLAvoirDeRegularisationAction;
use App\Domain\Facturation\Actions\NoterBordereauDetaxeAction;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\PreuvesRequises;
use App\Domain\Facturation\Services\RegularisationDUneFacture;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\FactureResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\PrendLeCalendrierDuMagasin;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * La fiche d'une facture ou d'un ticket. Un brouillon s'y émet, par
 * « Émettre » ou par « Émettre et encaisser », qui enregistre aussi son
 * règlement, en tout ou en partie (R1, voir FactureResource::emettre() et
 * FactureResource::emettreEtEncaisser()). Ce qui suit la vente sans la
 * changer s'y enregistre, à qui est autorisé à noter les preuves
 * d'exonération et les bordereaux de détaxe (facture.justifier, voir
 * FacturePolicy::justifier()), le propriétaire compris, qui ne vend
 * pas : les preuves de son exonération (voir
 * AjouterPreuveOperationAction) et le bordereau de détaxe d'un ticket
 * (voir NoterBordereauDetaxeAction). Chaque bouton n'apparaît que là où
 * son action l'accepte (voir leurs refusPour()). « Encaisser » le règle
 * d'un seul geste, en un ou plusieurs modes, comme sur la liste (voir
 * FactureResource::encaisser()). Son retour passe par « Avoir », qui ouvre
 * la recherche par numéro de « Nouvel avoir » sur ce document (voir
 * faireUnAvoir()). Après la correction du SIREN de la société par
 * l'éditeur, une facture que désigne son examen se régularise ici, en deux
 * gestes faits dans l'ordre que l'on veut (voir RegularisationDUneFacture)
 * : « Faire l'avoir de régularisation », pour qui peut faire un avoir dans
 * ce magasin, et « Émettre la facture rectificative », pour qui peut créer
 * et émettre une facture dans ce magasin, jamais le propriétaire.
 */
class ViewFacture extends ViewRecord
{
    use GereLesExceptionsMetier, PrendLeCalendrierDuMagasin;

    protected static string $resource = FactureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FactureResource::emettre(Actions\Action::make('emettre')),
            FactureResource::emettreEtEncaisser(Actions\Action::make('emettre_et_encaisser')),
            FactureResource::encaisser(Actions\Action::make('encaisser')),
            FactureResource::telechargerLePdf(Actions\Action::make('telecharger_pdf')),
            FactureResource::envoyerParEmail(Actions\Action::make('envoyer_par_email')),
            FactureResource::relancer(Actions\Action::make('relancer')),
            $this->faireUnAvoir(),
            $this->faireLAvoirDeRegularisation(),
            $this->emettreLaFactureRectificative(),
            $this->ajouterUnePreuve(),
            $this->noterLeBordereauDeDetaxe(),
        ];
    }

    /**
     * « Faire l'avoir de régularisation » d'une facture que désigne
     * l'examen du SIREN de la société, tant qu'il n'est pas émis (voir
     * FaireLAvoirDeRegularisationAction), pour qui peut faire un avoir dans
     * ce magasin. La fenêtre de confirmation porte le jeton de la demande
     * (R3) ; l'avoir émis s'ouvre ensuite.
     */
    private function faireLAvoirDeRegularisation(): Actions\Action
    {
        return Actions\Action::make('faireLAvoirDeRegularisation')
            ->label('Faire l\'avoir de régularisation')
            ->icon('heroicon-o-receipt-refund')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Faire l\'avoir de régularisation')
            ->modalDescription('L\'avoir crédite ce qui reste à créditer de cette facture, ce que les avoirs déjà émis n\'en ont pas crédité, sous l\'identité qu\'elle a signée et avec la mention de la correction du SIREN, sans rien rendre au stock. Son crédit règle ce qui en reste dû, puis sa facture rectificative, et rien d\'autre.')
            ->form([FactureResource::champDuJeton()])
            ->visible(fn (Facture $record): bool => (auth()->user()?->can('create', Avoir::class) ?? false) && app(RegularisationDUneFacture::class)->refusDeLAvoir($record) === null)
            ->action(fn (Facture $record, array $data) => static::executerSansCasser(function () use ($record, $data): void {
                $avoir = app(FaireLAvoirDeRegularisationAction::class)->handle(CompteConnecte::exiger(), $record, $data['jeton']);

                Notification::make()->title("Avoir de régularisation {$avoir->numero} émis.")->success()->send();
                $this->redirect(AvoirResource::getUrl('view', ['record' => $avoir]));
            }));
    }

    /**
     * « Émettre la facture rectificative » d'une facture que désigne
     * l'examen du SIREN de la société, tant qu'elle n'est pas émise (voir
     * EmettreLaFactureRectificativeAction), pour qui peut créer et émettre
     * une facture dans ce magasin, jamais le propriétaire ni un compte d'un
     * cabinet lié. La fenêtre de confirmation porte le jeton de la demande
     * (R3) ; la facture rectificative s'ouvre ensuite.
     */
    private function emettreLaFactureRectificative(): Actions\Action
    {
        return Actions\Action::make('emettreLaFactureRectificative')
            ->label('Émettre la facture rectificative')
            ->icon('heroicon-o-document-duplicate')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Émettre la facture rectificative')
            ->modalDescription('La facture rectificative reprend les lignes de cette facture, que personne ne modifie, sous l\'identité actuelle de la société, avec la mention de la facture qu\'elle rectifie, sans sortie de stock. Un numéro définitif lui est attribué.')
            ->form([FactureResource::champDuJeton()])
            ->visible(fn (Facture $record): bool => (auth()->user()?->can('create', Facture::class) ?? false)
                && auth()->user()->can(PermissionRegistry::FACTURE_EMETTRE)
                && app(RegularisationDUneFacture::class)->refusDeLaRectificative($record) === null)
            ->action(fn (Facture $record, array $data) => static::executerSansCasser(function () use ($record, $data): void {
                $rectificative = app(EmettreLaFactureRectificativeAction::class)->handle(CompteConnecte::exiger(), $record, $data['jeton']);

                Notification::make()->title("Facture rectificative {$rectificative->numero} émise.")->success()->send();
                $this->redirect(FactureResource::getUrl('view', ['record' => $rectificative]));
            }));
    }

    /**
     * « Avoir » : la page « Nouvel avoir », dont la recherche par numéro
     * part de ce ticket ou de cette facture (voir CreateAvoir::afterFill()),
     * où d'autres documents du même client peuvent s'ajouter. Le bouton
     * apparaît comme l'action « Avoir » de la liste des factures (voir
     * AvoirResource::peutCrediter()), et seulement pour qui peut ouvrir
     * cette page (voir AvoirResource::peutOuvrirLaRecherche()).
     */
    private function faireUnAvoir(): Actions\Action
    {
        return Actions\Action::make('avoir')
            ->label('Avoir')
            ->icon('heroicon-o-receipt-refund')
            ->color('danger')
            ->visible(fn (Facture $record): bool => AvoirResource::peutCrediter($record) && AvoirResource::peutOuvrirLaRecherche())
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
                        ->maxDate(fn (): string => self::calendrierDuMagasin()->aujourdhui()),
                    TextInput::make('emetteur')
                        ->label('Établie par')
                        ->helperText('La douane, le transporteur, l\'assureur ou l\'acquéreur. Deux documents de transport doivent venir de deux parties indépendantes du magasin et du client.')
                        ->maxLength(150),
                ];
            })
            ->action(fn (Facture $record, array $data) => static::executerSansCasser(function () use ($record, $data): void {
                app(AjouterPreuveOperationAction::class)->handle(CompteConnecte::exiger(), $record, TypePreuve::from($data['type']), $data['reference'], $data['date_document'] ?? null, $data['emetteur'] ?? null);

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
                $bordereau = app(NoterBordereauDetaxeAction::class)->handle(CompteConnecte::exiger(), $record, $data['numero']);
                $notification = Notification::make()->title("Bordereau de détaxe n° {$bordereau->numero} noté.");
                $avertissement = $bordereau->avertissement();

                ($avertissement === null ? $notification->success() : $notification->warning()->body($avertissement))->send();
            }));
    }
}
