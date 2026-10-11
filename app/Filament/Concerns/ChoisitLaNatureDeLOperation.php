<?php

namespace App\Filament\Concerns;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Tenancy\Services\TenantContext;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;

/**
 * La nature de l'opération d'une facture ou d'un devis (I1, voir
 * NatureOperation) et la livraison qu'elle demande. Un particulier
 * choisit la vente en France, l'export ou la livraison vers un DOM ; un
 * professionnel, les six natures ; un magasin en franchise en base, la
 * vente en France seule. L'aide dit la mention imprimée et les
 * conditions, que l'émission contrôle (voir ControleNatureOperation).
 *
 * La section « Livraison » n'apparaît que pour des biens qui partent hors
 * de France, préremplie avec l'adresse du client : son pays est
 * obligatoire, sa date aussi pour une livraison intracommunautaire, la
 * seule où le client peut transporter lui-même les biens ; un export ou
 * une livraison vers un DOM part toujours par le magasin (voir
 * NatureOperation::transportImpose()).
 */
trait ChoisitLaNatureDeLOperation
{
    /**
     * Les champs de la nature et de la livraison que les actions des
     * factures et des devis notent (voir NoteSaNatureDOperation) : ceux du
     * formulaire, une section cachée n'en envoyant aucun.
     */
    private const CHAMPS_DE_LA_NATURE = ['nature_operation', 'livraison_adresse', 'livraison_complement', 'livraison_code_postal', 'livraison_ville', 'livraison_pays', 'livraison_date', 'transport_par'];

    public static function champNatureDeLOperation(): Select
    {
        return Select::make('nature_operation')
            ->label('Nature de l\'opération')
            ->options(fn (Get $get): array => self::naturesProposees($get('client_id')))
            ->default(NatureOperation::VenteEnFrance->value)
            ->selectablePlaceholder(false)
            ->required()
            ->live()
            ->helperText(fn (Get $get): string => self::aideDeLaNature(NatureOperation::depuisSaisie($get('nature_operation'))))
            ->afterStateUpdated(fn (Get $get, Set $set) => self::preremplirLaLivraison($get, $set))
            ->columnSpanFull();
    }

    public static function sectionLivraison(): Section
    {
        $livraisonIntracommunautaire = fn (Get $get): bool => NatureOperation::depuisSaisie($get('nature_operation')) === NatureOperation::LivraisonIntracommunautaire;

        return Section::make('Livraison')
            ->description('Où partent les biens : la destination décide de l\'exonération.')
            ->visible(fn (Get $get): bool => NatureOperation::depuisSaisie($get('nature_operation'))->exigeUneLivraison())
            ->schema([
                TextInput::make('livraison_adresse')->label('Adresse')->maxLength(255)->columnSpanFull(),
                TextInput::make('livraison_complement')->label('Complément d\'adresse')->maxLength(255)->columnSpanFull(),
                TextInput::make('livraison_code_postal')->label('Code postal')->maxLength(10),
                TextInput::make('livraison_ville')->label('Ville')->maxLength(100),
                TextInput::make('livraison_pays')
                    ->label('Pays')
                    ->helperText('Code du pays à deux lettres : DE, US, GP...')
                    ->required()
                    ->maxLength(2)
                    ->regex('/^[A-Za-z]{2}$/')
                    ->validationMessages(['regex' => 'Le code du pays compte deux lettres : DE, US, GP...'])
                    ->extraInputAttributes(['style' => 'text-transform: uppercase']),
                DatePicker::make('livraison_date')
                    ->label('Date de livraison')
                    ->required($livraisonIntracommunautaire),
                Radio::make('transport_par')
                    ->label('Transport')
                    ->options(TransportPar::class)
                    ->required()
                    ->visible($livraisonIntracommunautaire)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /**
     * Le client choisi change les natures proposées : une nature qu'il ne
     * peut plus choisir (un particulier après un professionnel) revient à
     * la vente en France, et une livraison encore vide prend son adresse.
     */
    public static function ajusterLaNatureAuClient(Get $get, Set $set): void
    {
        if (! array_key_exists(NatureOperation::depuisSaisie($get('nature_operation'))->value, self::naturesProposees($get('client_id')))) {
            $set('nature_operation', NatureOperation::VenteEnFrance->value);
        }

        self::preremplirLaLivraison($get, $set);
    }

    /**
     * La nature et la livraison saisies, telles que les actions des
     * factures et des devis les notent.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    public static function attributsDeLaNature(array $donnees): array
    {
        return array_intersect_key($donnees, array_flip(self::CHAMPS_DE_LA_NATURE));
    }

    /**
     * Les natures que le client choisi (du magasin actif, archivé compris
     * pour un brouillon qui le garde) peut choisir, dans le régime de TVA
     * du magasin ; celles d'un particulier tant qu'aucun client n'est
     * choisi.
     *
     * @return array<string, string>
     */
    private static function naturesProposees(mixed $clientId): array
    {
        $identifiant = is_bool($clientId) ? false : filter_var($clientId, FILTER_VALIDATE_INT);
        $client = $identifiant === false ? null : Client::withTrashed()->find($identifiant);

        return collect(NatureOperation::proposees($client?->estProfessionnel() ?? false, app(TenantContext::class)->magasin()->estEnFranchiseDeTva()))
            ->mapWithKeys(fn (NatureOperation $nature): array => [$nature->value => $nature->getLabel()])
            ->all();
    }

    private static function aideDeLaNature(NatureOperation $nature): string
    {
        return $nature->mention() === null ? $nature->conditions() : "Mention : « {$nature->mention()} ». {$nature->conditions()}";
    }

    /**
     * Une livraison demandée et encore vide prend l'adresse du client
     * choisi : le plus souvent, les biens partent chez lui.
     */
    private static function preremplirLaLivraison(Get $get, Set $set): void
    {
        $identifiant = filter_var($get('client_id'), FILTER_VALIDATE_INT);

        if (! NatureOperation::depuisSaisie($get('nature_operation'))->exigeUneLivraison() || $identifiant === false
            || filled($get('livraison_adresse')) || filled($get('livraison_code_postal')) || filled($get('livraison_ville')) || filled($get('livraison_pays'))) {
            return;
        }

        $client = Client::withTrashed()->find($identifiant);

        if ($client === null) {
            return;
        }

        $set('livraison_adresse', $client->adresse);
        $set('livraison_complement', $client->complement_adresse);
        $set('livraison_code_postal', $client->code_postal);
        $set('livraison_ville', $client->ville);
        $set('livraison_pays', $client->pays);
    }
}
