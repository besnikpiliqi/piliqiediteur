<?php

namespace App\Filament\Concerns;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\SaisieDecimale;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tva\Services\CalculateurLigne;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * La nature de l'opération d'une facture ou d'un devis (I1, voir
 * NatureOperation) et la livraison qu'elle demande. Un particulier
 * choisit la vente en France, l'export ou la livraison vers un DOM ; un
 * professionnel, les six natures ; une société en franchise en base, la
 * vente en France seule. Le choix se valide aussi côté serveur, comme à
 * l'enregistrement (voir ContexteDeVente::pourEnregistrer()) : une liste
 * déroulante ne restreint ses choix qu'à l'affichage. L'aide dit la
 * mention imprimée et les conditions, que l'émission contrôle (voir
 * ControleNatureOperation).
 *
 * Changer de nature peut faire entrer la vente dans la TVA française ou
 * l'en faire sortir : le prix saisi d'un service change alors de sens, et
 * se convertit (voir lignesAuxPrixDeLaNature()). Un document rouvert dans
 * une nature que son client ou le régime de la société ne permettent plus
 * revient à la vente en France (voir natureARouvrir()).
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
            ->in(fn (Get $get): array => array_keys(self::naturesProposees($get('client_id'))))
            ->validationMessages(['in' => 'Choisissez une nature proposée pour ce client.'])
            ->live()
            ->helperText(fn (Get $get): string => self::aideDeLaNature(NatureOperation::depuisSaisie($get('nature_operation'))))
            ->afterStateUpdated(function (Get $get, Set $set, mixed $old): void {
                self::convertirLesPrixDesServices($get, $set, NatureOperation::depuisSaisie($old));
                self::preremplirLaLivraison($get, $set);
            })
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
     * la vente en France, avec le prix de ses services converti, et une
     * livraison encore vide prend son adresse.
     */
    public static function ajusterLaNatureAuClient(Get $get, Set $set): void
    {
        $nature = NatureOperation::depuisSaisie($get('nature_operation'));

        if (! array_key_exists($nature->value, self::naturesProposees($get('client_id')))) {
            $set('nature_operation', NatureOperation::VenteEnFrance->value);
            self::convertirLesPrixDesServices($get, $set, $nature);
        }

        self::preremplirLaLivraison($get, $set);
    }

    /**
     * Les données d'un brouillon de facture ou d'un devis telles que son
     * formulaire les rouvre, ses lignes comprises (voir
     * AjouteDesLignesDeVente::lignesARouvrir()) : sa nature, tant que son
     * client et le régime de la société la permettent encore. Sinon -- le
     * client est devenu particulier, la société est entrée en franchise en
     * base --, le document revient à la vente en France, le prix de ses
     * services converti comme pour un changement de nature (voir
     * lignesAuxPrixDeLaNature()), et une notification le dit avant qu'il ne
     * s'enregistre ainsi : sans quoi l'écran afficherait une nature que la
     * liste ne propose plus, et l'enregistrement la refuserait (voir
     * ContexteDeVente::pourEnregistrer()).
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    public static function natureARouvrir(Facture|Devis $document, array $donnees): array
    {
        $nature = $document->nature_operation;

        if (array_key_exists($nature->value, self::naturesProposees($document->client_id))) {
            return $donnees;
        }

        $lignes = self::lignesAuxPrixDeLaNature($donnees['lignes'] ?? [], ContexteDeVente::documentRetireLaTvaDuPrix($document), false);
        $raison = app(TenantContext::class)->magasin()->societe->estEnFranchiseDeTva()
            ? 'Votre société est en franchise en base de TVA, qui couvre aussi ses ventes hors de France'
            : 'Ce client n\'est plus un professionnel';

        Notification::make()
            ->title("« {$nature->getLabel()} » n'est plus possible")
            ->body("{$raison} : la vente repasse en « ".NatureOperation::VenteEnFrance->getLabel().' »'
                .($lignes === null ? '' : ', et le prix de ses services est désormais TTC')
                .'. Vérifiez-la avant d\'enregistrer.')
            ->warning()
            ->persistent()
            ->send();

        return [...$donnees, 'nature_operation' => NatureOperation::VenteEnFrance->value, 'lignes' => $lignes ?? ($donnees['lignes'] ?? [])];
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
     * de la société ; celles d'un particulier tant qu'aucun client n'est
     * choisi.
     *
     * @return array<string, string>
     */
    private static function naturesProposees(mixed $clientId): array
    {
        $identifiant = is_bool($clientId) ? false : filter_var($clientId, FILTER_VALIDATE_INT);
        $client = $identifiant === false ? null : Client::withTrashed()->find($identifiant);

        return collect(NatureOperation::proposees($client?->estProfessionnel() ?? false, app(TenantContext::class)->magasin()->societe->estEnFranchiseDeTva()))
            ->mapWithKeys(fn (NatureOperation $nature): array => [$nature->value => $nature->getLabel()])
            ->all();
    }

    /**
     * La nature vient de changer : si la vente est entrée dans la TVA
     * française ou en est sortie, le prix saisi de ses services se
     * convertit (voir lignesAuxPrixDeLaNature()), et une notification le
     * dit, pour que le vendeur le vérifie.
     */
    private static function convertirLesPrixDesServices(Get $get, Set $set, NatureOperation $avant): void
    {
        $apres = self::sansTvaFrancaise(NatureOperation::depuisSaisie($get('nature_operation')));
        $lignes = self::lignesAuxPrixDeLaNature($get('lignes'), self::sansTvaFrancaise($avant), $apres);

        if ($lignes === null) {
            return;
        }

        $set('lignes', $lignes);

        Notification::make()
            ->title('Prix des services convertis')
            ->body($apres
                ? 'Sans TVA française, un service se facture sans TVA : son prix TTC est devenu son prix sans TVA, au taux de sa ligne. Vérifiez-le.'
                : 'En France, le prix d\'un service se saisit TTC : son prix sans TVA est devenu son prix TTC, au taux de sa ligne. Vérifiez ce prix et ce taux.')
            ->warning()
            ->send();
    }

    /**
     * Le prix saisi d'un service change de sens quand la vente entre dans
     * la TVA française ou en sort (voir ContexteDeVente::retireLaTvaDuPrix()) :
     * TTC au taux de sa ligne en France, sans TVA hors de France (K3). Il
     * se convertit donc au taux de sa ligne, au centime comme le PU HT d'un
     * produit (voir CalculateurLigne) : 18,00 € TTC à 20 % font 15,00 € sans
     * TVA, et 15,00 € sans TVA font 18,00 € TTC. Une ligne de produit garde
     * son PU TTC, celui de son catalogue, dont le calcul retire ou non la
     * TVA ; une ligne de service sans prix ou sans taux valable reste telle
     * quelle, sa validation le signalera. Rien ne change entre deux natures
     * sans TVA française, ni d'une nature à l'autre en franchise en base, où
     * tout se vend au même prix TTC ; un document d'export rouvert après
     * l'entrée de la société en franchise revient en France à ce prix TTC, que
     * le client paie sans TVA (voir natureARouvrir()).
     *
     * Le taux d'un service vendu sans TVA française est caché : c'est celui
     * qu'il avait en France, ou, pour une ligne ajoutée ou rouverte hors de
     * France, celui de son prix de référence, sinon le taux du territoire
     * du magasin (voir AjouteDesLignesDeVente::lignesARouvrir()).
     *
     * Une ligne vendue au prix de référence de son service (R2, voir
     * Service) prend directement ce prix de référence et son taux dans la
     * nouvelle nature, sans l'arrondi d'un aller-retour : elle reste au
     * prix de référence, que peut vendre une personne qui n'est pas
     * autorisée à fixer les prix (voir DerogationsDePrix). Hors de la TVA
     * française, son prix seul la reconnaît, comme à l'enregistrement
     * (voir Service::vendAuPrixDeReferenceSansTva()) ; en France, son prix
     * et son taux.
     *
     * @param  mixed  $lignes  les lignes du formulaire
     * @return array<array-key, mixed>|null les lignes, prix des services convertis ; null si aucun n'a changé
     */
    private static function lignesAuxPrixDeLaNature(mixed $lignes, bool $sansTvaAvant, bool $sansTvaApres): ?array
    {
        if ($sansTvaAvant === $sansTvaApres || ! is_array($lignes)) {
            return null;
        }

        $calculateur = app(CalculateurLigne::class);
        $services = Service::query()->whereKey(collect($lignes)->filter(fn (mixed $ligne): bool => is_array($ligne))->pluck('service_id')->filter(fn (mixed $id): bool => is_numeric($id))->all())->get()->keyBy('id');
        $converties = false;

        foreach ($lignes as $cle => $ligne) {
            if (! is_array($ligne) || blank($ligne['service_id'] ?? null) || ! is_numeric($ligne['prix_unitaire_ttc'] ?? null)) {
                continue;
            }

            try {
                $prix = SaisieDecimale::enCentiemes($ligne['prix_unitaire_ttc']);
                $taux = is_numeric($ligne['taux_tva'] ?? null) ? SaisieDecimale::enCentiemes($ligne['taux_tva']) : null;
                $reference = self::prixDeReferenceConverti($services->get((int) $ligne['service_id']), $prix, $taux, $sansTvaAvant, $sansTvaApres);

                if ($reference === null && $taux === null) {
                    continue;
                }

                [$prixConverti, $tauxConverti] = $reference ?? [$sansTvaApres
                    ? $calculateur->enDecimal($calculateur->prixUnitaireHtDepuisTtc($prix, $taux))
                    : number_format($calculateur->prixUnitaireTtcDepuisHt((float) $calculateur->enDecimal($prix), (float) $calculateur->enDecimal($taux)), 2, '.', ''), $calculateur->enDecimal($taux)];
            } catch (InvalidArgumentException) {
                continue;
            }

            // Présentés comme un prix relu en base (cast decimal:2), "15.00",
            // et un taux tel qu'on l'écrit dans le champ, "20".
            $lignes[$cle]['prix_unitaire_ttc'] = $prixConverti;
            $lignes[$cle]['taux_tva'] = (string) (float) $tauxConverti;
            $converties = true;
        }

        return $converties ? $lignes : null;
    }

    /**
     * Le prix de référence du service dans la nouvelle nature, avec son
     * taux, écrits comme leurs colonnes, quand la ligne était à son prix de
     * référence dans l'ancienne : TTC en France, sans TVA hors de France ;
     * null sinon. Prix et taux se comparent en centimes et en centièmes de
     * point.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function prixDeReferenceConverti(?Service $service, int $prix, ?int $taux, bool $sansTvaAvant, bool $sansTvaApres): ?array
    {
        if ($service === null || ! $service->aUnPrixDeReference()) {
            return null;
        }

        $calculateur = app(CalculateurLigne::class);
        $prixDeReference = $calculateur->enCentiemes($service->prix_ttc);
        $tauxDeReference = $calculateur->enCentiemes($service->taux_tva);
        $prixSansTva = $calculateur->prixUnitaireHtDepuisTtc($prixDeReference, $tauxDeReference);
        $auPrixDeReference = $sansTvaAvant
            ? $prix === $prixSansTva
            : $prix === $prixDeReference && $taux === $tauxDeReference;

        if (! $auPrixDeReference) {
            return null;
        }

        return [$calculateur->enDecimal($sansTvaApres ? $prixSansTva : $prixDeReference), $service->taux_tva];
    }

    /**
     * La vente de cette nature, dans le régime d'aujourd'hui de la société,
     * se paie-t-elle sans la TVA française (voir
     * ContexteDeVente::retireLaTvaDuPrix()) ?
     */
    private static function sansTvaFrancaise(NatureOperation $nature): bool
    {
        return ContexteDeVente::pour(null, app(TenantContext::class)->magasin(), $nature)->retireLaTvaDuPrix();
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
