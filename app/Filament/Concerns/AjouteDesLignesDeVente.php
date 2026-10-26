<?php

namespace App\Filament\Concerns;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Actions\TrouverOuCreerServiceAction;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneDevis;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\DerogationsDePrix;
use App\Domain\Facturation\Services\DocumentChiffre;
use App\Domain\Facturation\Services\QuantiteEntiere;
use App\Domain\Facturation\Services\SaisieDecimale;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\ProduitResource;
use App\Filament\App\Resources\ServiceResource;
use Closure;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Livewire\Component as LivewireComponent;
use Livewire\Livewire;
use LogicException;
use WeakMap;

/**
 * Les lignes d'une vente (caisse, facture, devis) : chacune vend un produit
 * du catalogue ou un service du magasin, jamais une ligne libre (voir
 * ConstructeurLigneDocument). Deux boutons sous les lignes les ajoutent :
 * « Ajouter un produit » ouvre le grand tableau des produits (point 13,
 * voir SelecteurDeProduits), « Ajouter un service » la liste des services ;
 * au-dessus des lignes, le champ de la douchette ajoute le produit d'un
 * code-barres ou d'une référence sans ouvrir le tableau. Les produits
 * choisis arrivent au prix de leur fiche, et s'ajoutent à la ligne du même
 * produit qui n'a ni remise ni prix dérogatoire (voir
 * ajouterLesProduitsChoisis()).
 *
 * Les totaux affichés pendant la saisie passent par le même
 * constructeur que l'enregistrement réel, avec le client choisi, la nature
 * de l'opération (voir ChoisitLaNatureDeLOperation) et le régime de TVA du
 * magasin (voir apercuDesLignes()) : l'écran ne peut jamais afficher un
 * centime différent de ce qui sera enregistré. Une ligne ne montre aucun
 * total sous elle : ses montants dépendent des autres lignes de son taux
 * (voir CalculateurLigne::chiffrerUneVente()), et seuls les totaux du
 * document, par taux dès qu'il y en a plusieurs, s'affichent (voir
 * totauxDuDocument()). Une vente sans TVA française se calcule sans TVA,
 * et le prix d'un service s'y saisit sans TVA.
 *
 * Remises et prix (R2) : sans l'autorisation « accorder des remises et
 * fixer le prix d'un service », la remise, le prix d'une ligne et le taux
 * d'un service sont affichés sans pouvoir se changer, avec une aide qui dit
 * pourquoi (voir peutAccorderDesRemises()) : une ligne de produit prend le
 * prix de sa fiche, une ligne de service son prix de référence, et un
 * service sans prix de référence n'est pas proposé. Ces valeurs partent
 * tout de même avec le formulaire : une ligne remisée par un responsable
 * et reprise telle quelle garde sa remise, et le serveur refuse toute
 * valeur changée (voir DerogationsDePrix). Une ligne que la personne ne
 * pourrait pas enregistrer telle quelle reprend le prix du catalogue à la
 * réouverture du panier ou du document (voir lignesAuxPrixPermis()), et
 * une ligne dont elle change la quantité, le prix du catalogue d'aujourd'hui
 * (Q1) : un prix de fiche changé depuis ne la bloque jamais.
 */
trait AjouteDesLignesDeVente
{
    use SaisitLesPrixDeLigne;

    /**
     * Les calculs déjà faits pendant la requête en cours, par page Livewire
     * (une nouvelle instance à chaque requête) puis par calcul : le
     * contexte de vente de chaque client choisi, l'aperçu de chaque jeu de
     * lignes pour un client (voir calculUneFoisParRequete()).
     *
     * @var WeakMap<LivewireComponent, array<string, mixed>>|null
     */
    private static ?WeakMap $calculsDeLaRequete = null;

    /**
     * Un produit choisi qui n'est pas, ou plus, au catalogue du magasin :
     * archivé depuis son choix, ou d'un autre magasin (requête forgée),
     * sans dire lequel.
     */
    public const PRODUIT_INTROUVABLE = 'Un produit choisi n\'est plus au catalogue du magasin : il a pu être archivé. Choisissez de nouveau.';

    /**
     * Le champ de la douchette, au-dessus des lignes (voir
     * ajouterLeProduitScanne()), puis les lignes et leurs deux boutons
     * d'ajout.
     *
     * @return array<Component>
     */
    public static function lignesDeVente(): array
    {
        return [
            self::champDeLaDouchette(),
            Repeater::make('lignes')
                ->label('')
                ->schema(self::ligneSchema())
                ->columns(9)
                ->defaultItems(1)
                ->itemLabel(fn (array $state): string => filled($state['service_id'] ?? null) ? 'Service' : 'Produit')
                // Remplacé par les deux boutons ci-dessous.
                ->addable(false)
                ->required(),
            Actions::make([
                self::actionAjouterUnProduit(),
                self::actionAjouterUnService(),
            ])->alignCenter(),
        ];
    }

    /**
     * « Scanner ou taper une référence » : une douchette USB tape les
     * chiffres scannés dans le champ actif, puis Entrée, qui ajoute le
     * produit (ou une unité de plus à sa ligne) sans ouvrir le tableau des
     * produits (voir RecoitLesProduitsChoisis::scannerUneReference()) ;
     * Entrée ne valide jamais le formulaire de la page. Le champ n'est
     * jamais enregistré ni validé : un code qu'aucun produit ne porte y
     * reste, quelle que soit sa longueur (un code GS1 DataMatrix dépasse
     * souvent 60 caractères), sans jamais arrêter l'encaissement ni
     * l'enregistrement du document.
     */
    private static function champDeLaDouchette(): TextInput
    {
        return TextInput::make('douchette')
            ->label('Scanner ou taper une référence')
            ->placeholder('Code-barres ou référence exacte, puis Entrée')
            ->prefixIcon('heroicon-m-qr-code')
            ->autocomplete(false)
            ->dehydrated(false)
            ->extraInputAttributes(['x-on:keydown.enter.prevent' => '$wire.scannerUneReference()']);
    }

    /**
     * Les lignes saisies, calculées comme à leur enregistrement pour ce
     * client et cette nature d'opération, la vente en France sans nature
     * (la Caisse), (voir ConstructeurLigneDocument::apercu()), par clé de
     * ligne, avec les totaux par taux et du document ; une ligne pas encore
     * complète n'y est pas.
     *
     * Les totaux du document et ceux de la Caisse (reste à payer, monnaie
     * à rendre) lisent tous ce même aperçu : il ne se calcule, client et
     * catalogue lus, qu'une fois par requête de la page pour les mêmes
     * lignes, le même client et la même nature, quel que soit le nombre de
     * lignes.
     *
     * @param  array<array-key, mixed>  $lignes
     */
    public static function apercuDesLignes(array $lignes, mixed $clientId, mixed $nature = null): DocumentChiffre
    {
        $nature = NatureOperation::depuisSaisie($nature);

        return self::calculUneFoisParRequete(
            'apercu:'.hash('xxh128', serialize([$lignes, $clientId, $nature->value])),
            fn (): DocumentChiffre => app(ConstructeurLigneDocument::class)->apercu($lignes, self::contexteDeVente($clientId, $nature)),
        );
    }

    /**
     * Le résultat d'un calcul de la page, fait une seule fois par requête
     * pour la même clé ; hors d'une page Livewire, refait à chaque appel.
     *
     * @template T
     *
     * @param  Closure(): T  $calculer
     * @return T
     */
    private static function calculUneFoisParRequete(string $cle, Closure $calculer): mixed
    {
        $page = Livewire::current();

        if (! $page instanceof LivewireComponent) {
            return $calculer();
        }

        self::$calculsDeLaRequete ??= new WeakMap;

        if (! array_key_exists($cle, self::$calculsDeLaRequete[$page] ?? [])) {
            $resultat = $calculer();
            // Relu après le calcul, qui a pu en mémoriser un autre (le
            // contexte de vente d'un aperçu).
            self::$calculsDeLaRequete[$page] = [...(self::$calculsDeLaRequete[$page] ?? []), $cle => $resultat];
        }

        return self::$calculsDeLaRequete[$page][$cle];
    }

    /**
     * Les lignes d'un brouillon de facture ou d'un devis, telles que son
     * formulaire les rouvre. Le taux d'une ligne de produit se reprend de
     * sa fiche (voir champsPrixDeLigne()) ; celui d'une ligne de service,
     * tel qu'il a été saisi -- sauf sur un document enregistré en
     * franchise en base ou sans TVA française, où son 0 % n'a pas été
     * saisi mais imposé (voir ConstructeurLigneDocument) : la ligne propose
     * alors le taux retiré de son prix (celui de son prix de référence, voir
     * Service::vendAuPrixDeReferenceSansTva()), sinon celui du prix de
     * référence de son service, sinon le taux du territoire du magasin :
     * celui qu'elle prend si le magasin est sorti de la franchise ou la
     * vente revenue en France depuis, son prix sans TVA converti à ce taux
     * (voir ChoisitLaNatureDeLOperation::natureARouvrir()). Sinon, le champ
     * reste caché.
     *
     * Sans TVA française, le prix d'un service se montre sans TVA, au
     * centime, comme il se saisit : son prix de référence sans sa TVA (voir
     * Service::prixDeReferenceDans()), ou l'autre prix saisi, déjà sans
     * TVA. Le PU HT d'une ligne se montre avec ses décimales utiles, tel
     * qu'il est enregistré (voir CalculateurLigne::prixUnitaireHtExact()).
     *
     * @return list<array<string, mixed>>
     */
    public static function lignesARouvrir(Facture|Devis $document): array
    {
        $tauxImpose = $document->franchise_en_base || $document->nature_operation->exonere();
        $prixSansTva = ContexteDeVente::documentRetireLaTvaDuPrix($document);
        $calculateur = app(CalculateurLigne::class);

        return $document->lignes->loadMissing('service')->map(fn (LigneFacture|LigneDevis $ligne): array => [
            'produit_id' => $ligne->produit_id,
            'service_id' => $ligne->service_id,
            'description' => $ligne->description,
            'reference' => $ligne->reference,
            'quantite' => $ligne->quantite,
            'prix_unitaire_ht' => $calculateur->enDecimalUtile($calculateur->enMillioniemes($ligne->prix_unitaire_ht)),
            'prix_unitaire_ttc' => $prixSansTva && $ligne->service_id !== null
                ? $calculateur->enDecimal($calculateur->prixUnitaireHtDepuisTtc($calculateur->enCentiemes($ligne->prix_unitaire_ttc), $calculateur->enCentiemes($ligne->taux_tva_du_prix)))
                : $ligne->prix_unitaire_ttc,
            'taux_tva' => self::pourcentageEnTexte(match (true) {
                ! $tauxImpose || $ligne->service_id === null => $ligne->taux_tva,
                app(CalculateurLigne::class)->enCentiemes($ligne->taux_tva_du_prix) > 0 => $ligne->taux_tva_du_prix,
                default => $ligne->service?->taux_tva ?? self::magasin()->tauxTvaParDefaut(),
            }),
            'remise_pourcentage' => self::pourcentageEnTexte($ligne->remise_pourcentage),
        ])->all();
    }

    /**
     * Une ligne vend un produit du catalogue ou un service du magasin : sa
     * description vient du catalogue et ne se corrige que dans la fiche du
     * produit ou du service, et seul le PU TTC se saisit -- le PU HT en est
     * la conversion. Une ligne de service n'a ni produit à chercher ni PU
     * HT affiché.
     *
     * @return array<Component>
     */
    public static function ligneSchema(): array
    {
        return [
            Hidden::make('service_id'),
            Select::make('produit_id')
                ->label('Produit')
                ->helperText('Recherchez par nom, référence, ou scannez le code-barres.')
                ->searchable()
                // Un produit archivé n'est plus proposé ; celui d'une ligne
                // déjà composée reste affiché, et le document ne s'enregistre
                // plus tant qu'il n'est pas restauré ou retiré (voir
                // ConstructeurLigneDocument).
                ->getSearchResultsUsing(fn (string $search): array => ProduitResource::rechercher(Produit::query(), $search))
                ->getOptionLabelUsing(fn ($value) => Produit::withTrashed()->find($value)?->nom)
                ->required(fn (Get $get): bool => ! self::estUneLigneDeService($get))
                ->live()
                ->columnSpan(2)
                ->visible(fn (Get $get): bool => ! self::estUneLigneDeService($get))
                ->afterStateUpdated(function (?int $state, mixed $old, Set $set) {
                    $produit = $state ? Produit::find($state) : null;

                    if ($produit === null) {
                        return;
                    }

                    // Comme dans le tableau des produits, même par une
                    // requête forgée : un produit épuisé ne se choisit pas
                    // sans l'autorisation de vendre sans stock (R1), et la
                    // ligne garde son produit d'avant.
                    $refus = self::refusDuProduit($produit, self::peutVendreSansStock());

                    if ($refus !== null) {
                        $set('produit_id', $old);
                        Notification::make()->title($refus)->danger()->send();

                        return;
                    }

                    $ligne = self::ligneDuProduit($produit);

                    foreach (['description', 'reference', 'prix_unitaire_ttc', 'prix_unitaire_ht', 'taux_tva'] as $champ) {
                        $set($champ, $ligne[$champ]);
                    }

                    // Sans l'autorisation, changer le produit d'une ligne
                    // remisée en retire la remise, qu'il ne peut accorder.
                    if (! self::peutAccorderDesRemises()) {
                        $set('remise_pourcentage', '0');
                    }
                }),
            TextInput::make('description')
                ->label(fn (Get $get): string => self::estUneLigneDeService($get) ? 'Service' : 'Description')
                ->disabled()
                // Reprend la place du produit et du PU HT, absents d'une
                // ligne de service.
                ->columnSpan(fn (Get $get): int => self::estUneLigneDeService($get) ? 5 : 2),
            // live() et non live(onBlur: true) sur tous les champs de la
            // ligne : le total (et, dans la Caisse, la monnaie à rendre) se
            // met à jour pendant la saisie, pas seulement au clic ailleurs.
            TextInput::make('quantite')
                ->integer()
                ->minValue(1)
                ->required()
                ->default(1)
                ->saisieEnDirect()
                // Sans l'autorisation de fixer les prix, la ligne dont la
                // quantité change prend le prix du catalogue d'aujourd'hui
                // (Q1) : le prix d'une fiche changé depuis sa saisie, que
                // l'enregistrement refuserait, ne la bloque pas.
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    if (self::peutAccorderDesRemises()) {
                        return;
                    }

                    $ligne = self::ligneAuPrixDuCatalogue(self::ligneSaisie($get), self::contexteDeVente($get('../../client_id'), NatureOperation::depuisSaisie($get('../../nature_operation'))));

                    foreach (['prix_unitaire_ttc', 'prix_unitaire_ht', 'taux_tva'] as $champ) {
                        $set($champ, $ligne[$champ]);
                    }
                }),
            ...self::champsPrixDeLigne(),
            TextInput::make('remise_pourcentage')
                ->label('Remise %')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->default(0)
                ->saisieEnDirect()
                ->disabled(fn (): bool => ! self::peutAccorderDesRemises())
                ->dehydrated()
                ->helperText(fn (): ?string => self::peutAccorderDesRemises() ? null : self::aideSansAutorisation('Une remise')),
        ];
    }

    public static function estUneLigneDeService(Get $get): bool
    {
        return filled($get('service_id'));
    }

    /**
     * PU HT, PU TTC et TVA d'une ligne de vente : seul le PU TTC se saisit
     * (le prix affiché au client, repris tel quel de la fiche du produit),
     * le PU HT n'en est que la conversion au millionième d'euro, montrée à
     * côté telle que la ligne l'enregistrera : un champ montre le prix tel
     * qu'il est enregistré, quand les documents, les fiches et les listes
     * l'affichent arrondi au centime (voir
     * CalculateurLigne::prixUnitaireAffiche()).
     *
     * Le taux d'une ligne de produit est celui de sa fiche (voir
     * ConstructeurLigneDocument) : affiché, il ne se modifie pas et ne
     * part pas avec le formulaire. Celui d'une ligne de service se saisit,
     * celui de son prix de référence, sinon le taux du territoire du
     * magasin, par défaut (voir Magasin::tauxTvaParDefaut()) ; sans
     * l'autorisation de fixer les prix, le prix et le taux sont affichés
     * sans se changer (voir peutAccorderDesRemises()). En franchise en base, toute ligne est à
     * 0 % : le taux d'un produit affiche 0, celui d'un service ne se saisit
     * pas. Sans TVA française, le taux d'un produit reste affiché, celui
     * qui est retiré de son prix TTC, et le prix d'un service se saisit
     * sans TVA, sans taux ; il se convertit quand la vente entre dans la
     * TVA française ou en sort (voir ChoisitLaNatureDeLOperation).
     *
     * @return array<Component>
     */
    private static function champsPrixDeLigne(): array
    {
        return [
            TextInput::make('prix_unitaire_ht')
                ->label('PU HT')
                ->numeric()
                // Une ligne de service ne montre que son TTC.
                ->visible(fn (Get $get): bool => ! self::estUneLigneDeService($get))
                ->disabled(),
            TextInput::make('prix_unitaire_ttc')
                ->label(fn (Get $get): string => self::estUneLigneDeService($get) && self::venteSansTvaFrancaise($get) ? 'Prix (sans TVA)' : 'PU TTC')
                ->numeric()
                ->minValue(0)
                ->saisieEnDirect()
                ->required()
                ->disabled(fn (): bool => ! self::peutAccorderDesRemises())
                ->dehydrated()
                ->helperText(fn (Get $get): ?string => self::peutAccorderDesRemises() ? null : (self::estUneLigneDeService($get) ? 'Prix de référence du service. ' : 'Prix de la fiche du produit. ').self::aideSansAutorisation('Un autre prix'))
                // Le PU HT d'une ligne de produit suit le PU TTC saisi, au
                // taux affiché ; une ligne de service n'en montre pas.
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                    if (blank($state) || self::estUneLigneDeService($get)) {
                        return;
                    }

                    self::suivreLePrixUnitaireHt($state, $get('taux_tva'), $set);
                }),
            TextInput::make('taux_tva')
                ->label('TVA %')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->required(fn (Get $get): bool => self::estUneLigneDeService($get))
                ->default(fn (): string => self::pourcentageEnTexte(self::magasin()->tauxTvaParDefaut()))
                ->disabled(fn (Get $get): bool => ! self::estUneLigneDeService($get) || ! self::peutAccorderDesRemises())
                ->dehydrated(fn (Get $get): bool => self::estUneLigneDeService($get))
                ->hidden(fn (Get $get): bool => self::estUneLigneDeService($get) && (self::magasin()->societe->estEnFranchiseDeTva() || self::venteSansTvaFrancaise($get)))
                ->helperText(fn (Get $get): ?string => self::aideDuTaux($get))
                ->saisieEnDirect()
                // Une ligne de produit rouverte (brouillon, devis, panier de
                // la caisse) reprend le taux de sa fiche et le régime du
                // magasin d'aujourd'hui, ceux avec lesquels elle sera
                // enregistrée ; une ligne de service garde le taux saisi
                // (voir lignesARouvrir()).
                ->afterStateHydrated(function (Get $get, Set $set): void {
                    $produit = self::estUneLigneDeService($get) || blank($get('produit_id'))
                        ? null
                        : Produit::withTrashed()->find($get('produit_id'));

                    if ($produit !== null) {
                        self::appliquerLeTauxDuProduit($produit, $get, $set);
                    }
                }),
        ];
    }

    /**
     * L'aide du taux d'une ligne : en franchise en base, aucune TVA ; sans
     * TVA française, celle du produit est retirée de son prix ; pour un
     * service, un taux qui n'est pas en vigueur sur le territoire du
     * magasin est signalé, sans être refusé (X8), et, sans l'autorisation
     * de fixer les prix, le taux reste celui de son prix de référence.
     */
    private static function aideDuTaux(Get $get): ?string
    {
        if (self::magasin()->societe->estEnFranchiseDeTva()) {
            return 'TVA non applicable';
        }

        if (self::venteSansTvaFrancaise($get)) {
            return 'Sans TVA française : retirée du prix, aucune TVA facturée';
        }

        if (! self::estUneLigneDeService($get)) {
            return null;
        }

        $aides = array_filter([
            self::magasin()->territoireTva()?->aideSiTauxInhabituel($get('taux_tva')),
            self::peutAccorderDesRemises() ? null : 'Taux du prix de référence du service. '.self::aideSansAutorisation('Un autre taux'),
        ]);

        return $aides === [] ? null : implode(' ', $aides);
    }

    /**
     * Le PU HT d'une ligne de produit, affiché à côté de son PU TTC : sa
     * conversion au millionième d'euro au taux de la ligne, avec ses
     * décimales utiles, celle que la ligne enregistrera (voir
     * CalculateurLigne::prixUnitaireHtExact()). Une saisie qui n'est pas
     * encore un prix ou un taux valable ne change rien.
     */
    private static function suivreLePrixUnitaireHt(mixed $prixUnitaireTtc, mixed $tauxTva, Set $set): void
    {
        $calculateur = app(CalculateurLigne::class);

        try {
            $set('prix_unitaire_ht', $calculateur->enDecimalUtile($calculateur->prixUnitaireHtExact(SaisieDecimale::enCentiemes($prixUnitaireTtc), SaisieDecimale::enCentiemes($tauxTva))));
        } catch (InvalidArgumentException) {
            // Prix ou taux hors limites (voir leur validation) : rien à convertir.
        }
    }

    /**
     * L'aide d'un champ que verrouille l'absence de l'autorisation de fixer
     * les prix (R2) : ce qu'elle demande, et à qui s'adresser.
     */
    private static function aideSansAutorisation(string $geste): string
    {
        return "{$geste} ".DerogationsDePrix::AUTORISATION.' : demandez à un responsable.';
    }

    /**
     * Pour une personne qui n'est pas autorisée à fixer les prix (R2), les
     * lignes que l'enregistrement refuserait comme une dérogation qu'elle
     * introduit (voir DerogationsDePrix) reprennent le prix du catalogue,
     * sans remise (voir ligneAuPrixDuCatalogue()) : celles du panier de la
     * caisse dont la fiche a changé de prix depuis leur saisie, ou dont
     * l'autorisation a été retirée entre-temps, celles d'un brouillon qui ne
     * se reprendraient plus telles quelles (le prix de référence de leur
     * service a changé, hors de la TVA française). Les lignes d'un
     * brouillon que l'enregistrement reprendrait telles quelles, remise ou
     * prix déjà accordés par un responsable compris, ne changent pas. Rien
     * ne change pour une personne autorisée.
     *
     * @param  array<array-key, mixed>  $lignes  les lignes du formulaire
     * @param  Facture|Devis|null  $document  le brouillon rouvert ; aucun pour le panier de la caisse
     * @return array<array-key, mixed>
     */
    public static function lignesAuxPrixPermis(array $lignes, mixed $clientId, mixed $nature = null, Facture|Devis|null $document = null): array
    {
        if (self::peutAccorderDesRemises()) {
            return $lignes;
        }

        $contexte = self::contexteDeVente($clientId, NatureOperation::depuisSaisie($nature));
        $refusees = app(DerogationsDePrix::class)->introduites(
            $document?->lignes->map(fn (LigneFacture|LigneDevis $ligne): array => $ligne->attributesToArray())->all() ?? [],
            app(ConstructeurLigneDocument::class)->apercu($lignes, $contexte)->lignes,
            $contexte,
        );

        foreach (array_keys($refusees) as $cle) {
            $lignes[$cle] = [...self::ligneAuPrixDuCatalogue($lignes[$cle], $contexte), 'remise_pourcentage' => '0'];
        }

        return $lignes;
    }

    /**
     * La ligne au prix du catalogue dans cette vente : le prix de la fiche
     * de son produit et le PU HT qui en découle, ou le prix de référence de
     * son service, tel que l'écran le montre, et son taux. Une ligne de
     * service sans prix de référence reste telle quelle : elle ne se vend
     * pas sans l'autorisation.
     *
     * @param  array<string, mixed>  $ligne
     * @return array<string, mixed>
     */
    private static function ligneAuPrixDuCatalogue(array $ligne, ContexteDeVente $contexte): array
    {
        $produit = filled($ligne['produit_id'] ?? null) ? Produit::withTrashed()->find($ligne['produit_id']) : null;

        if ($produit !== null) {
            $calculateur = app(CalculateurLigne::class);

            return [
                ...$ligne,
                'prix_unitaire_ttc' => $produit->prix_vente_ttc,
                'prix_unitaire_ht' => $calculateur->enDecimalUtile($calculateur->prixUnitaireHtExact(
                    $calculateur->enCentiemes($produit->prix_vente_ttc),
                    $contexte->franchiseEnBase ? 0 : $calculateur->enCentiemes($produit->taux_tva),
                )),
            ];
        }

        $service = filled($ligne['service_id'] ?? null) ? Service::query()->find($ligne['service_id']) : null;

        if ($service === null || ! $service->aUnPrixDeReference()) {
            return $ligne;
        }

        return [
            ...$ligne,
            'prix_unitaire_ttc' => $service->prixDeReferenceDans($contexte),
            'taux_tva' => self::pourcentageEnTexte($service->taux_tva),
        ];
    }

    /**
     * Le taux de la fiche du produit, ou 0 en franchise, et le PU HT qui en
     * découle.
     */
    private static function appliquerLeTauxDuProduit(Produit $produit, Get $get, Set $set): void
    {
        $tauxTva = self::magasin()->societe->estEnFranchiseDeTva() ? '0' : $produit->taux_tva;

        $set('taux_tva', self::pourcentageEnTexte($tauxTva));

        if (filled($get('prix_unitaire_ttc'))) {
            self::suivreLePrixUnitaireHt($get('prix_unitaire_ttc'), $tauxTva, $set);
        }
    }

    /**
     * La ligne du champ est vendue sans TVA française, à son prix sans la
     * TVA française (voir ContexteDeVente::retireLaTvaDuPrix()) : jamais à
     * la Caisse, qui vend en France.
     */
    private static function venteSansTvaFrancaise(Get $get): bool
    {
        return self::contexteDeVente($get('../../client_id'), NatureOperation::depuisSaisie($get('../../nature_operation')))->retireLaTvaDuPrix();
    }

    protected static function documentDesLignesSaisies(Get $get): DocumentChiffre
    {
        return self::apercuDesLignes($get('lignes') ?? [], $get('client_id'), $get('nature_operation'));
    }

    /**
     * Le client choisi s'il est du magasin actif (MagasinScope), archivé
     * compris, et le magasin actif, dont le régime de TVA et le territoire
     * décident du calcul, avec la nature choisie ; lus une seule fois par
     * requête de la page pour le même client et la même nature.
     */
    private static function contexteDeVente(mixed $clientId, NatureOperation $nature = NatureOperation::VenteEnFrance): ContexteDeVente
    {
        return self::calculUneFoisParRequete('contexte:'.hash('xxh128', serialize([$clientId, $nature->value])), function () use ($clientId, $nature): ContexteDeVente {
            $identifiant = is_bool($clientId) ? false : filter_var($clientId, FILTER_VALIDATE_INT);

            return ContexteDeVente::pour($identifiant === false ? null : Client::withTrashed()->find($identifiant), self::magasin(), $nature);
        });
    }

    private static function magasin(): Magasin
    {
        return app(TenantContext::class)->magasin();
    }

    /**
     * « Ajouter un produit » ouvre le grand tableau des produits (point
     * 13, voir SelecteurDeProduits), sur tout l'écran, avec le client et
     * la nature de l'opération du document, dont dépend le total de la
     * sélection. La fenêtre n'a pas de bouton de validation : le tableau
     * envoie sa sélection à la page (voir RecoitLesProduitsChoisis), qui
     * la ferme une fois les lignes ajoutées.
     */
    private static function actionAjouterUnProduit(): Action
    {
        return Action::make('ajouterUnProduit')
            ->label('Ajouter un produit')
            ->color('gray')
            ->size(ActionSize::Small)
            ->modalHeading('Ajouter des produits')
            ->modalDescription('Cliquez sur les produits à ajouter, réglez leurs quantités en bas, puis ajoutez-les tous d\'un coup.')
            ->modalWidth(MaxWidth::Screen)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer')
            ->modalContent(fn (Get $get): View => view('filament.app.livewire.fenetre-du-selecteur-de-produits', [
                'clientId' => $get('client_id'),
                'nature' => $get('nature_operation'),
            ]));
    }

    /**
     * La personne connectée saisit-elle des ventes dans le magasin actif
     * (caisse, facture, devis) ? Seule à ouvrir les tableaux des produits
     * et des clients (voir SelecteurDeProduits et SelecteurDeClients),
     * qui le revérifient à chaque requête.
     */
    public static function peutSaisirUneVente(): bool
    {
        return auth()->user()?->canAny([PermissionRegistry::FACTURE_CREATE, PermissionRegistry::DEVIS_CREATE, PermissionRegistry::DEVIS_UPDATE]) ?? false;
    }

    /**
     * La personne connectée est-elle autorisée, dans le magasin actif, à
     * vendre sans stock (R1) ? Sans, un produit épuisé ne se choisit pas.
     */
    public static function peutVendreSansStock(): bool
    {
        return auth()->user()?->can(PermissionRegistry::STOCK_VENDRE_SANS_STOCK) ?? false;
    }

    /**
     * Pourquoi ce produit ne se choisit pas pour une vente : il est épuisé,
     * et la personne n'est pas autorisée à vendre sans stock (R1, voir
     * peutVendreSansStock(), lu une fois par qui appelle). Null quand il se
     * choisit. À l'émission, VerificateurDeStock reste le contrôle du
     * stock, quantités comprises.
     */
    public static function refusDuProduit(Produit $produit, bool $vendSansStock): ?string
    {
        return $produit->estEpuise() && ! $vendSansStock
            ? "« {$produit->nom} » est épuisé : seule une personne autorisée à vendre sans stock peut le vendre."
            : null;
    }

    /**
     * La ligne qui vend ce produit au prix de sa fiche, comme quand on le
     * choisit dans une ligne : sa description, sa référence, son PU TTC,
     * son taux (0 % en franchise en base) et le PU HT qui en découle, avec
     * ses décimales utiles (voir CalculateurLigne::prixUnitaireHtExact()),
     * sans remise.
     *
     * @return array{produit_id: int, service_id: null, description: string, reference: string, quantite: int, prix_unitaire_ttc: string, prix_unitaire_ht: string, taux_tva: string, remise_pourcentage: string}
     */
    public static function ligneDuProduit(Produit $produit, int $quantite = 1): array
    {
        $calculateur = app(CalculateurLigne::class);
        $tauxTva = self::magasin()->societe->estEnFranchiseDeTva() ? '0' : self::pourcentageEnTexte($produit->taux_tva);

        return [
            'produit_id' => $produit->getKey(),
            'service_id' => null,
            'description' => $produit->nom,
            'reference' => $produit->reference,
            'quantite' => $quantite,
            'prix_unitaire_ttc' => $produit->prix_vente_ttc,
            'prix_unitaire_ht' => $calculateur->enDecimalUtile($calculateur->prixUnitaireHtExact($calculateur->enCentiemes($produit->prix_vente_ttc), SaisieDecimale::enCentiemes($tauxTva))),
            'taux_tva' => $tauxTva,
            'remise_pourcentage' => '0',
        ];
    }

    /**
     * Les produits d'une sélection du tableau des produits, revérifiés :
     * chacun du catalogue du magasin actif (MagasinScope), non archivé, et
     * que la personne peut vendre (voir refusDuProduit()), avec sa
     * quantité, un nombre entier d'articles, au moins 1 ; un produit
     * choisi deux fois cumule ses quantités. Dans l'ordre de la sélection.
     *
     * @param  mixed  $selection  une liste de {produit_id, quantite}, telle que l'envoie le navigateur
     * @return list<array{produit: Produit, quantite: int}>
     *
     * @throws InvalidArgumentException la raison du refus, à montrer telle quelle
     */
    public static function produitsChoisis(mixed $selection): array
    {
        if (! is_array($selection) || $selection === []) {
            throw new InvalidArgumentException('Choisissez au moins un produit.');
        }

        $quantites = [];

        foreach ($selection as $choix) {
            $identifiant = is_array($choix) ? ($choix['produit_id'] ?? null) : null;

            if (! is_int($identifiant) && ! (is_string($identifiant) && ctype_digit($identifiant))) {
                throw new InvalidArgumentException(self::PRODUIT_INTROUVABLE);
            }

            $quantites[(int) $identifiant] = self::quantiteCumulee($quantites[(int) $identifiant] ?? 0, QuantiteEntiere::depuisSaisie($choix['quantite'] ?? null));
        }

        $produits = Produit::query()->whereKey(array_keys($quantites))->get()->keyBy('id');
        $vendSansStock = self::peutVendreSansStock();
        $choisis = [];

        foreach ($quantites as $identifiant => $quantite) {
            $produit = $produits->get($identifiant) ?? throw new InvalidArgumentException(self::PRODUIT_INTROUVABLE);
            $refus = self::refusDuProduit($produit, $vendSansStock);

            if ($refus !== null) {
                throw new InvalidArgumentException($refus);
            }

            $choisis[] = ['produit' => $produit, 'quantite' => $quantite];
        }

        return $choisis;
    }

    /**
     * Ajoute au document les produits d'une sélection du tableau des
     * produits, revérifiée (voir produitsChoisis()) : chacun sur une
     * nouvelle ligne au prix de sa fiche (R2), ou, si une ligne du même
     * produit existe déjà sans remise ni prix dérogatoire, en augmentant sa
     * quantité ; une ligne remisée ou à un autre prix n'est jamais
     * touchée, sa quantité exigeant l'autorisation des remises. Les lignes
     * encore vides (aucun produit ni service choisi) disparaissent. Une
     * sélection refusée n'ajoute rien, et la raison s'affiche.
     *
     * @return bool les lignes ont été ajoutées
     */
    public static function ajouterLesProduitsChoisis(HasForms $livewire, mixed $selection): bool
    {
        /** @var Repeater $repeteur */
        $repeteur = $livewire->getForm('form')->getComponent('data.lignes');

        try {
            $choisis = self::produitsChoisis($selection);
            $lignes = array_filter($repeteur->getState() ?? [], fn (mixed $ligne): bool => is_array($ligne) && (filled($ligne['produit_id'] ?? null) || filled($ligne['service_id'] ?? null)));
            $nouveaux = [];

            foreach ($choisis as ['produit' => $produit, 'quantite' => $quantite]) {
                $cle = self::ligneSansDerogationDuProduit($lignes, $produit);

                if ($cle === null) {
                    $nouveaux[] = self::ligneDuProduit($produit, $quantite);

                    continue;
                }

                $lignes[$cle]['quantite'] = self::quantiteCumulee(QuantiteEntiere::depuisSaisie($lignes[$cle]['quantite']), $quantite);
            }
        } catch (InvalidArgumentException $refus) {
            Notification::make()->title($refus->getMessage())->danger()->send();

            return false;
        }

        $repeteur->state($lignes);

        foreach ($nouveaux as $ligne) {
            self::ajouterUneLigne($livewire, $ligne);
        }

        $repeteur->callAfterStateUpdated();

        return true;
    }

    /**
     * La douchette, ou une référence tapée : le produit du magasin dont le
     * code-barres ou la référence (sans tenir compte de la casse, comme
     * son unicité, voir Produit::normaliserReference()) est exactement le
     * texte saisi, ajouté comme une sélection d'un article (voir
     * ajouterLesProduitsChoisis()). Aucun, ou plusieurs : la page le dit,
     * et le tableau des produits reste le moyen de choisir.
     *
     * @return bool le produit a été ajouté
     */
    public static function ajouterLeProduitScanne(HasForms $livewire, mixed $saisie): bool
    {
        $code = is_string($saisie) ? trim($saisie) : '';

        if ($code === '') {
            return false;
        }

        $produits = Produit::query()
            ->where(fn (Builder $produits): Builder => $produits->where('code_barres', $code)->orWhere('reference_normalisee', Produit::normaliserReference($code)))
            ->limit(2)
            ->get();

        if ($produits->count() !== 1) {
            Notification::make()
                ->title($produits->isEmpty()
                    ? "Aucun produit du magasin n'a le code-barres ni la référence « {$code} »."
                    : "Plusieurs produits ont le code « {$code} » : choisissez le bon dans le tableau des produits.")
                ->warning()
                ->send();

            return false;
        }

        return self::ajouterLesProduitsChoisis($livewire, [['produit_id' => $produits->sole()->getKey(), 'quantite' => 1]]);
    }

    /**
     * La clé de la première ligne de ce produit au prix de sa fiche, sans
     * remise : celle dont la quantité peut augmenter sans l'autorisation
     * des remises (voir DerogationsDePrix). Null s'il n'y en a pas.
     *
     * @param  array<array-key, array<string, mixed>>  $lignes
     */
    private static function ligneSansDerogationDuProduit(array $lignes, Produit $produit): int|string|null
    {
        $prixDeLaFiche = app(CalculateurLigne::class)->enCentiemes($produit->prix_vente_ttc);

        foreach ($lignes as $cle => $ligne) {
            if ((string) ($ligne['produit_id'] ?? '') !== (string) $produit->getKey() || filled($ligne['service_id'] ?? null)) {
                continue;
            }

            try {
                $sansDerogation = SaisieDecimale::enCentiemes(filled($ligne['remise_pourcentage'] ?? null) ? $ligne['remise_pourcentage'] : 0) === 0
                    && SaisieDecimale::enCentiemes($ligne['prix_unitaire_ttc'] ?? null) === $prixDeLaFiche;
                QuantiteEntiere::depuisSaisie($ligne['quantite'] ?? null);
            } catch (InvalidArgumentException) {
                // Une ligne en cours de saisie (prix, remise ou quantité
                // illisible) ne se complète pas.
                continue;
            }

            if ($sansDerogation) {
                return $cle;
            }
        }

        return null;
    }

    /**
     * Deux quantités d'un même produit ajoutées l'une à l'autre ; au-delà
     * de ce qu'un entier peut compter, la sélection est refusée.
     *
     * @throws InvalidArgumentException
     */
    private static function quantiteCumulee(int $quantite, int $ajoutee): int
    {
        $somme = $quantite + $ajoutee;

        if (! is_int($somme)) {
            throw new InvalidArgumentException('La quantité d\'un produit compterait plus d\'articles qu\'une vente ne peut en compter : baissez-la.');
        }

        return $somme;
    }

    /**
     * Cases à cocher des services que le magasin a déjà créés ; un service
     * qui n'y est pas encore se crée ici, avec son prix de référence pour
     * qui peut le fixer, et y sera proposé la prochaine fois (voir
     * TrouverOuCreerServiceAction). Chaque service coché devient une ligne
     * à son prix de référence, ou, sans prix de référence, dont la personne
     * autorisée saisit le prix, TTC en France, sans TVA sinon (voir
     * champsPrixDeLigne()). La recherche passe par
     * le serveur, sans casse ni accents (voir servicesProposes()) : celle
     * d'une liste de cases à cocher de Filament, faite dans le navigateur,
     * tiendrait compte des accents.
     *
     * Cocher est ouvert à quiconque peut vendre ; créer un service demande
     * l'autorisation de créer des produits et des services (voir
     * TrouverOuCreerServiceAction) : sans elle, le champ du nouveau service
     * n'apparaît pas, et le bouton non plus tant que le magasin n'a aucun
     * service.
     */
    private static function actionAjouterUnService(): Action
    {
        return Action::make('ajouterUnService')
            ->label('Ajouter un service')
            ->color('gray')
            ->size(ActionSize::Small)
            ->modalHeading('Ajouter un service')
            ->modalDescription('Cochez les services réalisés : chacun devient une ligne dont vous saisissez le prix.')
            ->modalSubmitActionLabel('Ajouter')
            ->modalWidth(MaxWidth::Large)
            ->visible(fn (): bool => self::servicesVendables()->exists() || self::peutCreerUnService())
            ->form([
                TextInput::make('recherche')
                    ->label('Rechercher un service')
                    ->placeholder('Ex. : ecran')
                    ->live(debounce: 300)
                    ->dehydrated(false)
                    ->visible(fn (): bool => self::servicesVendables()->exists()),
                CheckboxList::make('services')
                    ->label('Services du magasin')
                    ->options(fn (Get $get): array => self::servicesProposes($get('recherche'), $get('services')))
                    ->helperText(fn (): ?string => self::peutAccorderDesRemises() ? null : 'Les services dont le prix n\'est pas fixé ne sont pas proposés : demandez à un responsable.')
                    ->visible(fn (): bool => self::servicesVendables()->exists())
                    ->required(fn (Get $get): bool => blank($get('nouveau_service')))
                    // Pas d'astérisque quand un nouveau service tapé plus
                    // bas dispense de cocher.
                    ->markAsRequired(fn (): bool => ! self::peutCreerUnService())
                    ->validationMessages(['required' => 'Cochez au moins un service, ou écrivez le nom d\'un nouveau service.']),
                Placeholder::make('aucun_service_ne_correspond')
                    ->hiddenLabel()
                    ->content('Aucun service ne correspond.')
                    ->visible(fn (Get $get): bool => filled($get('recherche')) && self::servicesProposes($get('recherche'), $get('services')) === []),
                TextInput::make('nouveau_service')
                    ->label(fn (): string => self::servicesVendables()->exists() ? 'Le service n\'est pas dans la liste ?' : 'Nom du service')
                    ->placeholder('Ex. : Remplacement écran')
                    ->helperText('Il sera ajouté à la liste : la prochaine fois, il suffira de le cocher.')
                    ->maxLength(Service::LONGUEUR_MAXIMALE_NOM)
                    ->rule(fn (): Closure => ServiceResource::regleNomRempli())
                    ->required(fn (): bool => ! self::servicesVendables()->exists())
                    ->live(onBlur: true)
                    ->visible(fn (): bool => self::peutCreerUnService()),
                ...ServiceResource::champsDuPrixDeReference()
                    ->map(fn (TextInput $champ): TextInput => $champ->visible(fn (Get $get): bool => self::peutCreerUnService() && self::peutAccorderDesRemises() && filled($get('nouveau_service'))))
                    ->all(),
            ])
            ->action(fn (array $data, HasForms $livewire) => self::ajouterLesServices($livewire, $data));
    }

    private static function peutCreerUnService(): bool
    {
        return auth()->user()?->can('create', Service::class) ?? false;
    }

    /**
     * La personne connectée est-elle autorisée, dans le magasin actif, à
     * accorder des remises et fixer le prix d'un service (R2) ? Sans, la
     * remise, le prix et le taux d'une ligne s'affichent sans se changer.
     */
    public static function peutAccorderDesRemises(): bool
    {
        return auth()->user()?->can(PermissionRegistry::VENTE_REMISES_ET_PRIX) ?? false;
    }

    /**
     * Les services actifs du magasin (MagasinScope) dont le nom contient
     * le terme cherché, sans casse ni accents (« ecran » trouve
     * « Remplacement écran ») ; ceux déjà cochés restent proposés même
     * s'ils ne correspondent plus au terme, pour rester visibles et
     * décochables. Sans l'autorisation de fixer les prix, seuls ceux qui
     * ont un prix de référence : un service sans prix fixé ne se vend pas.
     *
     * @return array<int, string>
     */
    private static function servicesProposes(mixed $recherche, mixed $coches): array
    {
        $recherche = is_string($recherche) ? trim($recherche) : '';
        $coches = array_map('intval', is_array($coches) ? $coches : []);

        return self::servicesVendables()
            ->when($recherche !== '', fn (Builder $services): Builder => $services->where(
                fn (Builder $services): Builder => RechercheSansAccents::appliquer($services, $recherche, ['nom'])->orWhereKey($coches),
            ))
            ->orderBy('nom')
            ->orderBy('id')
            ->pluck('nom', 'id')
            ->all();
    }

    /**
     * Les services actifs que la personne connectée peut vendre : tous, ou,
     * sans l'autorisation de fixer les prix, ceux qui ont un prix de
     * référence.
     *
     * @return Builder<Service>
     */
    private static function servicesVendables(): Builder
    {
        return Service::query()
            ->actifs()
            ->when(! self::peutAccorderDesRemises(), fn (Builder $services): Builder => $services->whereNotNull('prix_ttc'));
    }

    /**
     * @param  array{services?: list<int|string>|null, nouveau_service?: string|null, prix_ttc?: string|null, taux_tva?: string|null}  $data
     */
    private static function ajouterLesServices(HasForms $livewire, array $data): void
    {
        $identifiants = array_map('intval', $data['services'] ?? []);
        $nouveauService = Service::nettoyerNom((string) ($data['nouveau_service'] ?? ''));

        if ($nouveauService !== '') {
            try {
                $nouveau = app(TrouverOuCreerServiceAction::class)->handle(auth()->user(), $nouveauService, $data['prix_ttc'] ?? null, $data['taux_tva'] ?? null);
            } catch (LogicException|AuthorizationException $exception) {
                Notification::make()->title($exception->getMessage())->danger()->send();

                return;
            }

            if ($nouveau->wasRecentlyCreated) {
                Notification::make()
                    ->title("Service « {$nouveau->nom} » ajouté à la liste")
                    ->body($nouveau->aUnPrixDeReference() || self::peutAccorderDesRemises()
                        ? 'La prochaine fois, il suffira de le cocher.'
                        : 'Son prix n\'est pas fixé : demandez à un responsable de le fixer pour pouvoir le vendre.')
                    ->success()
                    ->send();
            } elseif ($nouveau->wasChanged('actif')) {
                Notification::make()
                    ->title("Service « {$nouveau->nom} » de nouveau proposé à la caisse")
                    ->success()
                    ->send();
            }

            $identifiants[] = $nouveau->id;
        }

        // Seulement ceux que la personne peut vendre : un identifiant envoyé
        // sans passer par les cases à cocher n'ajoute rien.
        $services = self::servicesVendables()->whereKey($identifiants)->get()->keyBy('id');
        $etat = $livewire->getForm('form')->getRawState();
        $contexte = self::contexteDeVente($etat['client_id'] ?? null, NatureOperation::depuisSaisie($etat['nature_operation'] ?? null));

        // Dans l'ordre où le vendeur les a cochés ; le nouveau service en
        // dernier, une seule fois même s'il était déjà coché. Un service à
        // prix de référence arrive à ce prix et à son taux.
        foreach (array_unique($identifiants) as $identifiant) {
            $service = $services->get($identifiant);

            if ($service !== null) {
                self::ajouterUneLigne($livewire, [
                    'service_id' => $service->id,
                    'description' => $service->nom,
                    ...($service->aUnPrixDeReference() ? [
                        'prix_unitaire_ttc' => $service->prixDeReferenceDans($contexte),
                        'taux_tva' => self::pourcentageEnTexte($service->taux_tva),
                    ] : []),
                ]);
            }
        }
    }

    /**
     * Comme le bouton natif du Repeater : la nouvelle ligne reçoit les
     * valeurs par défaut de ses champs (quantité 1, taux du territoire du
     * magasin...), puis celles données.
     *
     * @param  array<string, mixed>  $valeurs
     */
    private static function ajouterUneLigne(HasForms $livewire, array $valeurs): void
    {
        /** @var Repeater $lignes */
        $lignes = $livewire->getForm('form')->getComponent('data.lignes');

        $uuid = $lignes->generateUuid();

        $lignes->state([...($lignes->getState() ?? []), $uuid => []]);
        $lignes->getChildComponentContainer($uuid)->fill();

        $etat = $lignes->getState();
        $etat[$uuid] = [...$etat[$uuid], ...$valeurs];
        $lignes->state($etat);

        $lignes->callAfterStateUpdated();
    }
}
