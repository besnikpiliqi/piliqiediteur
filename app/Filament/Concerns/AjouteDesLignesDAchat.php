<?php

namespace App\Filament\Concerns;

use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Achats\Services\ConstructeurLigneAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentChiffre;
use App\Domain\Facturation\Services\SaisieDecimale;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\ProduitResource;
use Closure;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Support\Enums\MaxWidth;
use InvalidArgumentException;

/**
 * Les lignes d'une facture d'achat : chacune se saisit dans un formulaire
 * complet (le produit, existant ou nouveau, puis ce qu'il coûte sur cette
 * facture et ce qu'il se vendra en magasin) et apparaît en lecture seule
 * dans la facture. Le bouton « Modifier » d'une ligne rouvre le même
 * formulaire.
 *
 * Prix d'achat HT et prix de vente TTC sont deux champs distincts : le
 * prix de vente ne change jamais le prix d'achat, et seul le barème du
 * magasin, s'il l'a activé, propose un prix de vente d'après le prix
 * d'achat (voir appliquerLeBareme()). Un produit existant n'est corrigé
 * qu'à l'enregistrement de la facture, avec elle, et un nouveau produit ne
 * naît qu'à sa validation (voir ConstructeurLigneAchat) : l'état d'une
 * ligne porte donc le produit sous sa clé "produit".
 */
trait AjouteDesLignesDAchat
{
    use SaisitLesPrixDeLigne;

    private const PRODUIT_EXISTANT = 'existant';

    private const NOUVEAU_PRODUIT = 'nouveau';

    /**
     * @return array<Component>
     */
    public static function lignesDAchat(): array
    {
        return [
            Repeater::make('lignes')
                ->label('')
                ->schema(self::ligneDAchatSchema())
                ->columns(5)
                ->defaultItems(0)
                ->itemLabel(fn (array $state): ?string => $state['produit']['nom'] ?? null)
                // Remplacé par le bouton ci-dessous, qui ouvre le formulaire
                // de ligne.
                ->addable(false)
                ->extraItemActions([self::actionModifierLaLigne()])
                ->required()
                ->validationMessages(['required' => 'Ajoutez au moins un produit à la facture.']),
            Actions::make([self::actionAjouterUnProduit()])->alignCenter(),
        ];
    }

    /**
     * Une ligne ne se modifie que par son formulaire : ses champs restent
     * affichés en lecture seule, mais sont bien enregistrés.
     *
     * @return array<Component>
     */
    private static function ligneDAchatSchema(): array
    {
        return [
            Hidden::make('produit_id'),
            // Gardée avec la ligne pour la retrouver à sa réouverture (voir
            // appliquerLeBareme()), jamais enregistrée.
            Hidden::make('prix_vente_propose')->dehydrated(false),
            ...array_map(
                fn (string $champ): Hidden => Hidden::make("produit.{$champ}"),
                array_diff(ConstructeurLigneAchat::ATTRIBUTS_DU_PRODUIT, ['prix_vente_ttc']),
            ),
            TextInput::make('quantite')->label('Quantité')->disabled()->dehydrated(),
            TextInput::make('prix_unitaire_ht')->label('Prix d\'achat HT')->suffix('€')->disabled()->dehydrated(),
            TextInput::make('remise_pourcentage')->label('Remise')->suffix('%')->disabled()->dehydrated(),
            TextInput::make('taux_tva')->label('TVA')->suffix('%')->disabled()->dehydrated(),
            TextInput::make('produit.prix_vente_ttc')->label('Prix de vente TTC')->suffix('€')->disabled()->dehydrated(),
            self::montantPayeAuFournisseur(),
            self::venteEnMagasin(),
        ];
    }

    /**
     * Ce que la ligne coûte au magasin : le prix d'achat x la quantité,
     * TVA comprise -- jamais un montant de vente. Sa TVA est sa part de
     * celle de son taux, calculée sur le total HT des lignes de ce taux
     * (voir ConstructeurLigneAchat::apercu()).
     */
    private static function montantPayeAuFournisseur(): Placeholder
    {
        return Placeholder::make('montant_paye_au_fournisseur')
            ->label('Montant payé au fournisseur')
            ->columnSpan(2)
            ->content(fn (Get $get, Placeholder $component): string => self::montantEnTexte(self::montantsDeLaLigneDAchat($get, $component)['montant_ttc']).' TTC')
            ->helperText(function (Get $get, Placeholder $component): string {
                $montants = self::montantsDeLaLigneDAchat($get, $component);

                return ($get('quantite') ?? 0).' x '.self::montantEnTexte($get('prix_unitaire_ht') ?? '0').' HT'
                    .(filled($get('remise_pourcentage')) && (float) $get('remise_pourcentage') > 0 ? ' remisé de '.self::pourcentageEnTexte($get('remise_pourcentage')).' %' : '')
                    .' = '.self::montantEnTexte($montants['montant_ht']).' HT + '.self::montantEnTexte($montants['montant_tva']).' de TVA';
            });
    }

    /**
     * Le prix auquel chaque article se vendra en magasin, et ce qu'il
     * rapporte une fois son prix d'achat payé.
     */
    private static function venteEnMagasin(): Placeholder
    {
        return Placeholder::make('vente_en_magasin')
            ->label('Vente en magasin')
            ->columnSpan(3)
            ->content(function (Get $get): string {
                if (blank($get('produit.prix_vente_ttc'))) {
                    return '—';
                }

                $vente = self::montantEnTexte((float) $get('produit.prix_vente_ttc')).' TTC l\'article';
                $tauxTva = ProduitResource::tauxDeTvaSaisi($get('produit.taux_tva')) ?? ProduitResource::tauxDeTvaSaisi($get('taux_tva'));

                if ($tauxTva === null) {
                    return $vente;
                }

                try {
                    $calculateur = app(CalculateurLigne::class);
                    $benefice = $calculateur->beneficeUnitaire(
                        (float) $calculateur->enDecimal($calculateur->prixUnitaireHtDepuisTtc(SaisieDecimale::enCentiemes($get('produit.prix_vente_ttc')), SaisieDecimale::enCentiemes($tauxTva))),
                        self::prixDAchatNet($get),
                    );
                } catch (InvalidArgumentException) {
                    return $vente;
                }

                return $vente.' · bénéfice '.self::montantEnTexte($benefice).' HT par article';
            });
    }

    private static function actionAjouterUnProduit(): Action
    {
        return Action::make('ajouterUnProduit')
            ->label('Ajouter un produit')
            ->icon('heroicon-m-plus')
            ->modalHeading('Ajouter un produit à la facture')
            ->modalSubmitActionLabel('Ajouter à la facture')
            ->modalWidth(MaxWidth::FourExtraLarge)
            ->form(self::formulaireDeLigne())
            ->action(function (array $data, HasForms $livewire): void {
                /** @var Repeater $lignes */
                $lignes = $livewire->getForm('form')->getComponent('data.lignes');

                $lignes->state([...($lignes->getState() ?? []), $lignes->generateUuid() => self::ligneDepuisLeFormulaire($data)]);
                $lignes->callAfterStateUpdated();
            });
    }

    private static function actionModifierLaLigne(): Action
    {
        return Action::make('modifierLaLigne')
            ->label('Modifier')
            ->icon('heroicon-m-pencil-square')
            ->color('gray')
            ->modalHeading('Modifier la ligne')
            ->modalSubmitActionLabel('Enregistrer la ligne')
            ->modalWidth(MaxWidth::FourExtraLarge)
            ->fillForm(fn (array $arguments, Repeater $component): array => self::formulaireDepuisLaLigne($component->getRawItemState($arguments['item'])))
            ->form(self::formulaireDeLigne())
            ->action(function (array $arguments, array $data, Repeater $component): void {
                $etat = $component->getState();
                $etat[$arguments['item']] = self::ligneDepuisLeFormulaire($data);

                $component->state($etat);
                $component->callAfterStateUpdated();
            });
    }

    /**
     * Les champs du produit sont ceux de sa fiche (voir ProduitResource) et
     * suivent les mêmes droits : créer un produit demande de pouvoir créer
     * des produits, corriger sa fiche de pouvoir la modifier. Ceux qui
     * viennent de son fournisseur restent figés pour un produit reçu sur une
     * facture récupérée automatiquement (voir
     * Produit::vientDUneFactureAchatApi()).
     *
     * @return array<Component>
     */
    private static function formulaireDeLigne(): array
    {
        // Archivé compris : la ligne qui le garde se rouvre sur sa fiche,
        // non modifiable (voir ProduitPolicy::update()), et l'enregistrement
        // la refuse ensuite avec un message (voir ConstructeurLigneAchat).
        $produitSelectionne = fn (Get $get): ?Produit => filled($get('produit_id')) ? Produit::withTrashed()->find($get('produit_id')) : null;
        $produitChoisi = fn (Get $get): bool => $get('origine') === self::NOUVEAU_PRODUIT || filled($get('produit_id'));
        $ficheNonModifiable = fn (Get $get): bool => ($produit = $produitSelectionne($get)) !== null && ! auth()->user()->can('update', $produit);
        $figeParSonFournisseur = fn (Get $get): bool => $produitSelectionne($get)?->vientDUneFactureAchatApi() ?? false;
        $identificationNonModifiable = fn (Get $get): bool => $ficheNonModifiable($get) || $figeParSonFournisseur($get);

        return [
            Section::make('Produit')->schema([
                Radio::make('origine')
                    ->label('')
                    ->options([
                        self::PRODUIT_EXISTANT => 'Produit du catalogue',
                        self::NOUVEAU_PRODUIT => 'Nouveau produit',
                    ])
                    ->inline()
                    ->default(self::PRODUIT_EXISTANT)
                    ->required()
                    ->visible(fn (): bool => auth()->user()->can(PermissionRegistry::PRODUIT_CREATE))
                    ->live()
                    ->columnSpanFull()
                    // Changer d'avis repart d'une fiche vierge : un nouveau
                    // produit ne doit rien garder de celui choisi avant.
                    ->afterStateUpdated(fn (Set $set) => self::remplirDepuisLeProduit(null, $set)),
                Select::make('produit_id')
                    ->label('Produit')
                    ->helperText('Recherchez par nom, référence, ou scannez le code-barres.')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => ProduitResource::rechercher(Produit::query(), $search))
                    ->getOptionLabelUsing(fn ($value): ?string => Produit::withTrashed()->find($value)?->nom)
                    ->required()
                    ->visible(fn (Get $get): bool => $get('origine') !== self::NOUVEAU_PRODUIT)
                    ->live()
                    ->columnSpanFull()
                    ->afterStateUpdated(fn (int|string|null $state, Set $set) => self::remplirDepuisLeProduit(filled($state) ? Produit::find($state) : null, $set)),
                Placeholder::make('fiche_non_modifiable')
                    ->label('')
                    ->content(fn (Get $get): string => $figeParSonFournisseur($get)
                        ? 'Reçu sur une facture d\'achat récupérée automatiquement : sa référence et son nom sont ceux du fournisseur.'
                        : 'Vous ne pouvez pas modifier la fiche de ce produit : seule la ligne se modifie ici.')
                    ->visible($identificationNonModifiable)
                    ->columnSpanFull(),
                ...array_map(
                    fn (Field $champ): Field => $champ->visible($produitChoisi)->disabled($identificationNonModifiable),
                    ProduitResource::champsIdentification($produitSelectionne),
                ),
            ])->columns(2),

            Section::make('Prix')
                ->visible($produitChoisi)
                ->schema([
                    // La dernière proposition du barème, pour savoir si la
                    // personne a changé le prix de vente elle-même (voir
                    // appliquerLeBareme()).
                    Hidden::make('prix_vente_propose'),
                    TextInput::make('quantite')
                        ->label('Quantité reçue')
                        ->integer()
                        ->minValue(1)
                        ->required()
                        ->default(1),
                    TextInput::make('prix_unitaire_ht')
                        ->label('Prix d\'achat HT')
                        ->helperText('Le prix unitaire payé au fournisseur, avant remise.')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('€')
                        ->required()
                        ->saisieEnDirect()
                        ->afterStateUpdated(self::appliquerLeBareme($ficheNonModifiable)),
                    TextInput::make('remise_pourcentage')
                        ->label('Remise du fournisseur')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->default(0)
                        ->saisieEnDirect()
                        ->afterStateUpdated(self::appliquerLeBareme($ficheNonModifiable)),
                    ...array_map(
                        fn (Field $champ): Field => (match ($champ->getName()) {
                            'prix_vente_ttc' => $champ->helperText(fn (Get $get): ?string => self::detailDuPrixDeVente($get)),
                            'taux_tva' => $champ->afterStateUpdated(self::appliquerLeBareme($ficheNonModifiable)),
                            default => $champ,
                        })->disabled($ficheNonModifiable),
                        ProduitResource::prixVenteTtcSchema(),
                    ),
                ])->columns(3),

            Section::make('Stock')
                ->visible($produitChoisi)
                ->schema(array_map(
                    fn (Field $champ): Field => $champ->disabled($ficheNonModifiable),
                    ProduitResource::champsSuiviDuStock(),
                ))
                ->columns(2),
        ];
    }

    /**
     * Le barème du magasin (voir BaremePrixDeVente) propose le prix de vente
     * TTC dès que le prix d'achat, sa remise ou la TVA changent ; la
     * personne qui enregistre la facture d'achat reste libre de le changer
     * ensuite. Rien ne change sur une fiche qu'elle ne peut pas modifier,
     * ni tant que la TVA est vide.
     */
    private static function appliquerLeBareme(Closure $ficheNonModifiable): Closure
    {
        return function (Get $get, Set $set) use ($ficheNonModifiable): void {
            $bareme = app(TenantContext::class)->magasin()->baremePrixDeVenteActif();
            $tauxTva = ProduitResource::tauxDeTvaSaisi($get('taux_tva'));

            if ($bareme === null || $ficheNonModifiable($get) || blank($get('prix_unitaire_ht')) || $tauxTva === null) {
                return;
            }

            try {
                $prix = $bareme->prixDeVente(self::prixDAchatNet($get), $tauxTva);
            } catch (InvalidArgumentException) {
                // Saisie pas encore valable (remise ou TVA hors limites).
                return;
            }

            if ($prix !== null) {
                $set('prix_vente_ttc', self::prixEnTexte($prix['prix_vente_ttc']));
                $set('prix_vente_propose', self::prixEnTexte($prix['prix_vente_ttc']));

                return;
            }

            // Au-delà du dernier palier, sans bénéfice prévu : le prix de
            // vente se saisit à la main. Une proposition faite pour un prix
            // d'achat précédent ne doit pas rester comme si elle valait
            // encore -- sauf si la personne l'a déjà changée elle-même.
            if (filled($get('prix_vente_propose')) && $get('prix_vente_ttc') === $get('prix_vente_propose')) {
                $produit = filled($get('produit_id')) ? Produit::withTrashed()->find($get('produit_id')) : null;

                $set('prix_vente_ttc', $produit === null ? null : self::prixEnTexte((float) $produit->prix_vente_ttc));
            }

            $set('prix_vente_propose', null);
        };
    }

    /**
     * « Soit 8,33 € HT + 1,67 € de TVA », et le bénéfice que ce prix laisse
     * sur chaque article, comme le montre le barème.
     */
    private static function detailDuPrixDeVente(Get $get): ?string
    {
        $detail = ProduitResource::detailPrixDeVente($get);
        $tauxTva = ProduitResource::tauxDeTvaSaisi($get('taux_tva'));

        if ($detail === null || $tauxTva === null || blank($get('prix_unitaire_ht'))) {
            return $detail;
        }

        try {
            $calculateur = app(CalculateurLigne::class);
            $benefice = $calculateur->beneficeUnitaire(
                (float) $calculateur->enDecimal($calculateur->prixUnitaireHtDepuisTtc(SaisieDecimale::enCentiemes($get('prix_vente_ttc')), SaisieDecimale::enCentiemes($tauxTva))),
                self::prixDAchatNet($get),
            );
        } catch (InvalidArgumentException) {
            return $detail;
        }

        return $detail.' · bénéfice '.number_format($benefice, 2, ',', ' ').' € HT par article';
    }

    protected static function documentDesLignesSaisies(Get $get): DocumentChiffre
    {
        return app(ConstructeurLigneAchat::class)->apercu($get('lignes') ?? []);
    }

    /**
     * La ligne du champ, lue par sa clé dans le répéteur dans l'aperçu de
     * toutes les lignes de la facture d'achat (voir
     * documentDesLignesSaisies()) ; aucun montant tant qu'elle n'est pas
     * valable.
     *
     * @return array{montant_ht: string, montant_tva: string, montant_ttc: string}
     */
    private static function montantsDeLaLigneDAchat(Get $get, Placeholder $champ): array
    {
        $ligne = app(ConstructeurLigneAchat::class)->apercu($get('../../lignes') ?? [])->lignes[$champ->getContainer()->getStatePath(isAbsolute: false)] ?? [];

        return [
            'montant_ht' => $ligne['montant_ht'] ?? '0.00',
            'montant_tva' => $ligne['montant_tva'] ?? '0.00',
            'montant_ttc' => $ligne['montant_ttc'] ?? '0.00',
        ];
    }

    /**
     * Ce que coûte un article, remise de la ligne déduite, sans arrondi
     * (voir CalculateurLigne::coutUnitaireNet()).
     */
    private static function prixDAchatNet(Get $get): float
    {
        return app(CalculateurLigne::class)->coutUnitaireNet((float) $get('prix_unitaire_ht'), (float) ($get('remise_pourcentage') ?? 0));
    }

    /**
     * Les valeurs du produit choisi, ou une fiche vierge.
     */
    private static function remplirDepuisLeProduit(?Produit $produit, Set $set): void
    {
        if ($produit === null) {
            $set('produit_id', null);
        }

        foreach (self::valeursDuProduit($produit) as $champ => $valeur) {
            $set($champ, $valeur);
        }

        $set('prix_unitaire_ht', $produit === null ? null : self::prixEnTexte($produit->dernierPrixDAchatHt()));
        $set('prix_vente_propose', null);
    }

    /**
     * La fiche du produit telle que le formulaire l'affiche ; les valeurs
     * par défaut d'un nouveau produit sans produit.
     *
     * @return array<string, mixed>
     */
    private static function valeursDuProduit(?Produit $produit): array
    {
        return [
            'reference' => $produit?->reference,
            'code_barres' => $produit?->code_barres,
            'nom' => $produit?->nom,
            'taille' => $produit?->taille,
            'couleur' => $produit?->couleur,
            'prix_vente_ttc' => blank($produit?->prix_vente_ttc) ? null : self::prixEnTexte((float) $produit->prix_vente_ttc),
            'taux_tva' => self::pourcentageEnTexte($produit->taux_tva ?? app(TenantContext::class)->magasin()->tauxTvaParDefaut()),
            'suivi_stock' => $produit->suivi_stock ?? true,
            'seuil_alerte' => $produit->seuil_alerte ?? 0,
        ];
    }

    /**
     * Les champs non modifiables de la fiche ne reviennent pas du
     * formulaire : la ligne reprend alors ceux du produit. Un produit
     * archivé depuis reste celui de la ligne, que l'enregistrement refuse
     * avec un message (voir ConstructeurLigneAchat) : il ne devient jamais
     * un nouveau produit en silence.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function ligneDepuisLeFormulaire(array $data): array
    {
        $produit = filled($data['produit_id'] ?? null) ? Produit::withTrashed()->find($data['produit_id']) : null;
        $valeursDuProduit = [...($produit === null ? [] : self::valeursDuProduit($produit)), ...array_intersect_key($data, array_flip(ConstructeurLigneAchat::ATTRIBUTS_DU_PRODUIT))];
        $valeursDuProduit['prix_vente_ttc'] = self::prixEnTexte((float) $valeursDuProduit['prix_vente_ttc']);

        return [
            'produit_id' => $produit?->id,
            'produit' => $valeursDuProduit,
            'quantite' => $data['quantite'],
            'prix_unitaire_ht' => self::prixEnTexte((float) $data['prix_unitaire_ht']),
            'remise_pourcentage' => $data['remise_pourcentage'] ?? 0,
            // Le taux de la fiche s'applique aussi à cette ligne.
            'taux_tva' => $valeursDuProduit['taux_tva'],
            'prix_vente_propose' => $data['prix_vente_propose'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $ligne
     * @return array<string, mixed>
     */
    private static function formulaireDepuisLaLigne(array $ligne): array
    {
        return [
            ...($ligne['produit'] ?? []),
            'origine' => filled($ligne['produit_id'] ?? null) ? self::PRODUIT_EXISTANT : self::NOUVEAU_PRODUIT,
            'produit_id' => $ligne['produit_id'] ?? null,
            'quantite' => $ligne['quantite'] ?? 1,
            'prix_unitaire_ht' => $ligne['prix_unitaire_ht'] ?? null,
            'remise_pourcentage' => $ligne['remise_pourcentage'] ?? 0,
            'taux_tva' => $ligne['taux_tva'] ?? ($ligne['produit']['taux_tva'] ?? self::pourcentageEnTexte(app(TenantContext::class)->magasin()->tauxTvaParDefaut())),
            'prix_vente_propose' => $ligne['prix_vente_propose'] ?? null,
        ];
    }

    /**
     * Une ligne déjà enregistrée, telle que la facture l'affiche à sa
     * modification : le produit tel qu'il est aujourd'hui, ou la fiche du
     * nouveau produit que le brouillon garde jusqu'à sa validation ; la
     * ligne telle qu'elle a été achetée.
     *
     * @return array<string, mixed>
     */
    public static function ligneDejaEnregistree(LigneAchat $ligne): array
    {
        return [
            'produit_id' => $ligne->produit_id,
            'produit' => self::valeursDuProduit($ligne->produit ?? new Produit($ligne->nouveau_produit)),
            'quantite' => $ligne->quantite,
            'prix_unitaire_ht' => $ligne->prix_unitaire_ht,
            'remise_pourcentage' => self::pourcentageEnTexte($ligne->remise_pourcentage),
            'taux_tva' => self::pourcentageEnTexte($ligne->taux_tva),
        ];
    }
}
