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
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\App\Resources\ServiceResource;
use Closure;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Exists;
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
 * code-barres ou d'une référence sans ouvrir le tableau. Une ligne montre
 * son produit ou son service, sans liste pour le changer, et se retire par
 * sa corbeille ; une nouvelle facture, un nouveau devis et la caisse
 * s'ouvrent sans ligne.
 *
 * Un produit n'a qu'une ligne, et un service qu'une ligne par prix et par
 * taux (décision de l'éditeur du 26/10/2026, série 9) : choisi de nouveau,
 * par le tableau, la douchette ou « Ajouter un service », il augmente la
 * quantité de sa ligne, qui garde sa place, son prix et sa remise (voir
 * ajouterLesProduitsChoisis() et ajouterLesServices()) ; un produit absent
 * arrive au prix de sa fiche, un service à son prix de référence. Le
 * document ne part jamais avec un article sur deux lignes (voir
 * regleUnArticleParLigne()), et l'enregistrement le refuse aussi (voir
 * ConstructeurLigneDocument). Un brouillon rouvert garde ses lignes telles
 * qu'enregistrées : seul un nouvel ajout se réunit à une ligne.
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
 * (Q1) : un prix de fiche changé depuis ne la bloque jamais. Sans
 * l'autorisation, un article choisi de nouveau dont la ligne porte une
 * remise, un prix enregistré sur le brouillon autre que celui du
 * catalogue, ou reprend une ligne du devis accepté, est refusé, avec la
 * raison (voir refusDeChangerLaQuantite()) : jamais une seconde ligne ;
 * une ligne seulement restée au prix d'une fiche changée depuis reprend,
 * comme pour la quantité tapée, le prix du catalogue d'aujourd'hui (voir
 * completerLaLigne()).
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
     * d'ajout ; aucune ligne au départ. Dans une société sans articles (voir
     * Societe::aDesArticles()), seulement les lignes et « Ajouter un
     * service » : ni douchette, ni tableau des produits.
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
                ->defaultItems(0)
                ->itemLabel(fn (array $state): string => filled($state['service_id'] ?? null) ? 'Service' : 'Produit')
                // Remplacé par les deux boutons ci-dessous.
                ->addable(false)
                ->required()
                ->validationMessages(['required' => 'Ajoutez au moins un produit ou un service.'])
                ->rule(self::regleUnArticleParLigne()),
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
            ->visible(fn (): bool => self::aDesArticles())
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

        $calculs = self::$calculsDeLaRequete ??= new WeakMap;

        if (! array_key_exists($cle, $calculs[$page] ?? [])) {
            $resultat = $calculer();
            // Relu après le calcul, qui a pu en mémoriser un autre (le
            // contexte de vente d'un aperçu).
            $calculs[$page] = [...($calculs[$page] ?? []), $cle => $resultat];
        }

        return $calculs[$page][$cle];
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
     * TVA. Le PU HT d'une ligne se montre comme partout, celui d'un
     * article, depuis son PU TTC et le taux que comprend ce prix (voir
     * puHtDansLeChamp()) ; celui d'une ligne de produit se reprend du PU TTC
     * et du taux de sa fiche une fois le formulaire rempli (voir
     * champsPrixDeLigne()).
     *
     * @return list<array<string, mixed>>
     */
    public static function lignesARouvrir(Facture|Devis $document): array
    {
        $tauxImpose = $document->franchise_en_base || $document->nature_operation->exonere();
        $prixSansTva = ContexteDeVente::documentRetireLaTvaDuPrix($document);
        $calculateur = app(CalculateurLigne::class);

        return array_values($document->lignes->loadMissing('service')->map(fn (LigneFacture|LigneDevis $ligne): array => [
            'produit_id' => $ligne->produit_id,
            'service_id' => $ligne->service_id,
            'description' => $ligne->description,
            'reference' => $ligne->reference,
            'quantite' => $ligne->quantite,
            'prix_unitaire_ht' => self::puHtDansLeChamp($calculateur->enCentiemes($ligne->prix_unitaire_ttc), $calculateur->enCentiemes($ligne->taux_tva_du_prix)),
            'prix_unitaire_ttc' => $prixSansTva && $ligne->service_id !== null
                ? $calculateur->enDecimal($calculateur->prixUnitaireHtAffiche($calculateur->enCentiemes($ligne->prix_unitaire_ttc), $calculateur->enCentiemes($ligne->taux_tva_du_prix)))
                : $ligne->prix_unitaire_ttc,
            'taux_tva' => self::pourcentageEnTexte(match (true) {
                ! $tauxImpose || $ligne->service_id === null => $ligne->taux_tva,
                app(CalculateurLigne::class)->enCentiemes($ligne->taux_tva_du_prix) > 0 => $ligne->taux_tva_du_prix,
                default => $ligne->service->taux_tva ?? self::magasin()->tauxTvaParDefaut(),
            }),
            'remise_pourcentage' => self::pourcentageEnTexte($ligne->remise_pourcentage),
        ])->all());
    }

    /**
     * Une ligne vend un produit du catalogue ou un service du magasin : sa
     * description vient du catalogue et ne se corrige que dans la fiche du
     * produit ou du service, et seul le PU TTC se saisit -- le PU HT en est
     * la conversion. Le produit ou le service d'une ligne ne se change pas :
     * il se retire par la corbeille de sa ligne, et un autre s'ajoute par
     * les boutons sous les lignes (voir lignesDeVente()). Une ligne de
     * service n'a pas de PU HT affiché.
     *
     * @return array<Component>
     */
    public static function ligneSchema(): array
    {
        return [
            Hidden::make('service_id'),
            // Archivé compris : seul l'enregistrement refuse, avec un
            // message, la ligne d'un produit archivé depuis (voir
            // ConstructeurLigneDocument) ; un produit d'un autre magasin,
            // ou un identifiant qui n'est pas un entier, que seule une
            // requête forgée envoie, ne passe jamais, et la base ne lit
            // jamais ce dernier.
            Hidden::make('produit_id')
                ->required(fn (Get $get): bool => ! self::estUneLigneDeService($get))
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (filled($value) && self::identifiantDuProduit($value) === null) {
                        $fail(self::PRODUIT_INTROUVABLE);
                    }
                })
                ->rule(fn (): Exists => Produit::regleExisteDansLeMagasin(Produit::withTrashed()), fn (mixed $state): bool => self::identifiantDuProduit($state) !== null)
                ->validationMessages(['required' => 'Une ligne vend un produit ou un service du catalogue.', 'exists' => self::PRODUIT_INTROUVABLE]),
            TextInput::make('description')
                ->label(fn (Get $get): string => self::estUneLigneDeService($get) ? 'Service' : 'Produit')
                ->helperText(fn (Get $get): ?string => self::estUneLigneDeService($get) || blank($get('reference')) ? null : 'Référence : '.ValeurSimple::enTexte($get('reference')))
                ->disabled()
                // Reprend la place du PU HT, absent d'une ligne de service.
                ->columnSpan(fn (Get $get): int => self::estUneLigneDeService($get) ? 5 : 4),
            // live() et non live(onBlur: true) sur tous les champs de la
            // ligne : le total (et, dans la Caisse, la monnaie à rendre) se
            // met à jour pendant la saisie, pas seulement au clic ailleurs.
            TextInput::make('quantite')
                ->integer()
                ->minValue(1)
                ->required()
                ->default(1)
                ->saisieEnDirect()
                ->helperText(fn (Get $get, HasForms $livewire): ?string => self::stockDUneLigneDeDevis($get, $livewire))
                // Sans l'autorisation de fixer les prix, la ligne dont la
                // quantité change prend le prix du catalogue d'aujourd'hui
                // (Q1) : le prix d'une fiche changé depuis sa saisie, que
                // l'enregistrement refuserait, ne la bloque pas.
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    if (self::peutAccorderDesRemises()) {
                        return;
                    }

                    $ligne = self::ligneAuPrixDuCatalogue(self::ligneSaisie($get), self::contexteDuChamp($get));

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
                ->extraInputAttributes(fn (Get $get): array => self::reunitSiIdentique($get))
                ->helperText(fn (): ?string => self::peutAccorderDesRemises() ? null : self::aideSansAutorisation('Une remise')),
        ];
    }

    /**
     * Le prix, le taux ou la remise d'une ligne de service, une fois saisi
     * (le champ quitté, ou Entrée), fait réunir les lignes de ce service
     * devenues identiques (voir reunirLesLignesIdentiques()) ; jamais
     * pendant la frappe, où « 60 » réunirait la ligne d'un « 60,50 » à
     * venir. Une ligne de produit n'en a pas besoin : un produit n'a
     * jamais qu'une ligne.
     *
     * @return array<string, string>
     */
    private static function reunitSiIdentique(Get $get): array
    {
        return self::estUneLigneDeService($get) ? ['x-on:change' => '$wire.reunirLesLignesIdentiques()'] : [];
    }

    public static function estUneLigneDeService(Get $get): bool
    {
        return filled($get('service_id'));
    }

    /**
     * L'identifiant du produit d'une ligne, tel que l'écran le pose (un
     * entier, ou le texte qui l'écrit) ; null pour toute autre valeur, que
     * seule une requête forgée envoie et qu'aucune requête ne doit lire.
     */
    private static function identifiantDuProduit(mixed $valeur): ?int
    {
        if (! is_int($valeur) && ! is_string($valeur)) {
            return null;
        }

        $identifiant = filter_var($valeur, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $identifiant === false ? null : $identifiant;
    }

    /**
     * PU HT, PU TTC et TVA d'une ligne de vente : seul le PU TTC se saisit
     * (le prix affiché au client, repris tel quel de la fiche du produit)
     * et se montre tel qu'il est enregistré. Le PU HT n'en est que la
     * conversion, que la ligne enregistrera au millionième d'euro (voir
     * ConstructeurLigneDocument) : son champ, qui ne se saisit pas et ne
     * part pas avec le formulaire, montre celui d'un article, le PU TTC
     * moins sa TVA arrondie au centime, comme partout dans PILIQI et pour
     * toute vente (voir puHtDansLeChamp() et
     * CalculateurLigne::prixUnitaireHtAffiche(), décision de l'éditeur du
     * 14/11/2026) : 8.32 pour 9,99 € TTC à 20 %.
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
                ->extraInputAttributes(fn (Get $get): array => self::reunitSiIdentique($get))
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
                ->extraInputAttributes(fn (Get $get): array => self::reunitSiIdentique($get))
                ->saisieEnDirect()
                // Une ligne de produit rouverte (brouillon, devis, panier de
                // la caisse) reprend le taux de sa fiche et le régime du
                // magasin d'aujourd'hui, ceux avec lesquels elle sera
                // enregistrée ; une ligne de service garde le taux saisi
                // (voir lignesARouvrir()).
                ->afterStateHydrated(function (Get $get, Set $set): void {
                    $identifiant = self::estUneLigneDeService($get) ? null : self::identifiantDuProduit($get('produit_id'));
                    $produit = $identifiant === null ? null : Produit::withTrashed()->whereKey($identifiant)->first();

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
     * Le PU HT d'une ligne de produit, affiché à côté de son PU TTC (voir
     * puHtDansLeChamp()). Une saisie qui n'est pas encore un prix ou un
     * taux valable ne change rien.
     */
    private static function suivreLePrixUnitaireHt(mixed $prixUnitaireTtc, mixed $tauxTva, Set $set): void
    {
        $puHt = self::puHtDeLaSaisie($prixUnitaireTtc, $tauxTva);

        if ($puHt !== null) {
            $set('prix_unitaire_ht', $puHt);
        }
    }

    /**
     * Le PU HT que montre le champ d'une ligne de produit pour ce PU TTC et
     * ce taux saisis (voir puHtDansLeChamp()) ; aucun tant qu'ils ne sont
     * pas un prix ou un taux valable (voir leur validation).
     */
    private static function puHtDeLaSaisie(mixed $prixUnitaireTtc, mixed $tauxTva): ?string
    {
        try {
            return self::puHtDansLeChamp(SaisieDecimale::enCentiemes($prixUnitaireTtc), SaisieDecimale::enCentiemes($tauxTva));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Le PU HT d'une ligne tel que le montre son champ, qui ne se saisit
     * pas (voir champsPrixDeLigne()) : celui d'un article, son PU TTC moins
     * sa TVA arrondie au centime, comme partout dans PILIQI (voir
     * CalculateurLigne::prixUnitaireHtAffiche(), décision de l'éditeur du
     * 14/11/2026), écrit comme une colonne au centime : « 8.32 » pour
     * 9,99 € TTC à 20 %. La ligne enregistre son PU HT au millionième
     * (voir CalculateurLigne::prixUnitaireHtExact()).
     *
     * @param  int  $prixUnitaireTtc  en centimes
     * @param  int  $tauxDuPrix  en centièmes de point
     *
     * @throws InvalidArgumentException
     */
    private static function puHtDansLeChamp(int $prixUnitaireTtc, int $tauxDuPrix): string
    {
        $calculateur = app(CalculateurLigne::class);

        return $calculateur->enDecimal($calculateur->prixUnitaireHtAffiche($prixUnitaireTtc, $tauxDuPrix));
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

        $contexte = self::contexteDeVente($clientId, $nature);
        $refusees = app(DerogationsDePrix::class)->introduites(
            $document?->lignes->map(fn (LigneFacture|LigneDevis $ligne): array => $ligne->attributesToArray())->all() ?? [],
            app(ConstructeurLigneDocument::class)->apercu($lignes, $contexte)->lignes,
            $contexte,
        );

        foreach (array_keys($refusees) as $cle) {
            $lignes[$cle] = [...self::ligneAuPrixDuCatalogue(ValeursLues::champs($lignes[$cle]), $contexte), 'remise_pourcentage' => '0'];
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
        $identifiant = self::identifiantDuProduit($ligne['produit_id'] ?? null);
        $produit = $identifiant === null ? null : Produit::withTrashed()->whereKey($identifiant)->first();

        if ($produit !== null) {
            $calculateur = app(CalculateurLigne::class);

            return [
                ...$ligne,
                'prix_unitaire_ttc' => $produit->prix_vente_ttc,
                'prix_unitaire_ht' => self::puHtDansLeChamp(
                    $calculateur->enCentiemes($produit->prix_vente_ttc),
                    $contexte->franchiseEnBase ? 0 : $calculateur->enCentiemes($produit->taux_tva),
                ),
            ];
        }

        $service = filled($ligne['service_id'] ?? null) ? Service::query()->whereKey(ValeursLues::cle($ligne['service_id']))->first() : null;

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
        return self::contexteDuChamp($get)->retireLaTvaDuPrix();
    }

    /**
     * La vente de la ligne d'un champ (voir contexteDeVente()) : $get lit
     * le formulaire depuis l'un des champs de la ligne.
     */
    private static function contexteDuChamp(Get $get): ContexteDeVente
    {
        return self::contexteDeVente($get('../../client_id'), $get('../../nature_operation'));
    }

    /**
     * Le formulaire de la page tel qu'il est saisi.
     *
     * @return array<array-key, mixed>
     */
    private static function etatDuFormulaire(HasForms $livewire): array
    {
        $etat = ValeurRequise::de($livewire->getForm('form'), 'le formulaire de la page')->getRawState();

        return is_array($etat) ? $etat : $etat->toArray();
    }

    protected static function documentDesLignesSaisies(Get $get): DocumentChiffre
    {
        return self::apercuDesLignes(ValeursLues::tableau($get('lignes') ?? []), $get('client_id'), $get('nature_operation'));
    }

    /**
     * Le client choisi s'il est du magasin actif (MagasinScope), archivé
     * compris, et le magasin actif, dont le régime de TVA et le territoire
     * décident du calcul, avec la nature choisie (la vente en France sans
     * nature connue, voir NatureOperation::depuisSaisie()) ; lus une seule
     * fois par requête de la page pour le même client et la même nature.
     */
    public static function contexteDeVente(mixed $clientId, mixed $nature = null): ContexteDeVente
    {
        $nature = NatureOperation::depuisSaisie($nature);

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
     * La société du magasin actif a-t-elle des articles (voir
     * Societe::aDesArticles()) ? Sans, une vente n'a que des lignes de
     * service.
     */
    public static function aDesArticles(): bool
    {
        return self::magasin()->societe->aDesArticles();
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
            ->modalDescription('Cliquez sur les produits à ajouter : chaque clic en ajoute un de plus. Réglez leurs quantités en bas, puis ajoutez-les tous d\'un coup ; un produit déjà sur une ligne en augmente la quantité.')
            ->modalWidth(MaxWidth::Screen)
            // Le haut et le bas de la fenêtre restent visibles, seul le
            // tableau des produits défile (voir
            // resources/css/fenetres-de-selection.css).
            ->extraModalWindowAttributes(['class' => 'piliqi-fenetre-de-selection'])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer')
            ->visible(fn (): bool => self::aDesArticles())
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
     * Sous la quantité d'une ligne de produit d'un devis : le stock
     * disponible du magasin aujourd'hui, et un avertissement quand la
     * quantité de la ligne le dépasse (décision de l'éditeur du 02/11/2026,
     * option « a »), comme le montrent sa fiche et l'avis de son acceptation
     * (voir Devis::stocksDeSesProduits()) ; le devis ne réserve rien, et
     * l'émission de la facture qui en sortira contrôle le stock (voir
     * VerificateurDeStock). Un produit n'a qu'une ligne : sa quantité est
     * toute la demande du devis. Rien hors des pages d'un devis (une
     * facture, la caisse, qui reprennent les mêmes lignes), ni pour un
     * service ou un produit qui ne suit pas son stock. Les stocks de tous
     * les produits du formulaire se lisent en une seule requête par requête
     * de la page (voir calculUneFoisParRequete()) : autant de lectures du
     * catalogue pour vingt lignes que pour une.
     */
    public static function stockDUneLigneDeDevis(Get $get, HasForms $livewire): ?string
    {
        $produitId = self::identifiantDuProduit($get('produit_id'));

        if (! $livewire instanceof ResourcePage || $livewire->getModel() !== Devis::class || $produitId === null) {
            return null;
        }

        $identifiants = collect(ValeursLues::tableau($get('../../lignes') ?? []))
            ->map(fn (mixed $ligne): ?int => is_array($ligne) ? self::identifiantDuProduit($ligne['produit_id'] ?? null) : null)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
        $stocks = self::calculUneFoisParRequete('stocks:'.implode(',', $identifiants), fn (): array => Produit::withTrashed()
            ->whereKey($identifiants)
            ->where('suivi_stock', true)
            ->get(['id', 'stock_quantite'])
            ->mapWithKeys(fn (Produit $produit): array => [$produit->id => $produit->stock_quantite])
            ->all());
        $disponible = $stocks[$produitId] ?? null;

        return $disponible === null ? null : self::texteDuStockDUneLigne($disponible, max(0, ValeurSimple::enEntierOuNul($get('quantite')) ?? 0));
    }

    /**
     * Ce que dit le stock sous une ligne de produit d'un devis, dans son
     * formulaire comme sur sa fiche (voir FactureResource::lignesEntry()) :
     * le stock disponible, puis l'avertissement quand la quantité de la
     * ligne le dépasse.
     */
    public static function texteDuStockDUneLigne(int $disponible, int $quantite): string
    {
        return $quantite <= $disponible
            ? "Stock disponible : {$disponible}."
            : "Stock disponible : {$disponible}. La quantité dépasse le stock : le devis ne réserve rien, la facture le contrôlera.";
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
     * La ligne qui vend ce produit au prix de sa fiche dans cette vente,
     * telle qu'un ajout la crée : sa description, sa
     * référence, son PU TTC, son taux (0 % en franchise en base) et le PU HT
     * qui en découle, montré comme cette vente le montre (voir
     * puHtDansLeChamp()), sans remise.
     *
     * @return array{produit_id: int, service_id: null, description: string, reference: string, quantite: int, prix_unitaire_ttc: string, prix_unitaire_ht: string, taux_tva: string, remise_pourcentage: string}
     */
    public static function ligneDuProduit(Produit $produit, int $quantite, ContexteDeVente $contexte): array
    {
        $calculateur = app(CalculateurLigne::class);
        $tauxTva = $contexte->franchiseEnBase ? '0' : self::pourcentageEnTexte($produit->taux_tva);

        return [
            'produit_id' => CleDuModele::de($produit),
            'service_id' => null,
            'description' => $produit->nom,
            'reference' => $produit->reference,
            'quantite' => $quantite,
            'prix_unitaire_ttc' => $produit->prix_vente_ttc,
            'prix_unitaire_ht' => self::puHtDansLeChamp($calculateur->enCentiemes($produit->prix_vente_ttc), SaisieDecimale::enCentiemes($tauxTva)),
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
     * produits ou de la douchette, revérifiée (voir produitsChoisis()) : un
     * produit absent du document arrive sur une nouvelle ligne, au prix de
     * sa fiche (R2) ; un produit déjà sur une ligne voit la quantité de
     * cette ligne augmenter, qui garde sa place, son prix et sa remise (voir
     * completerLaLigne()), et un message le dit. Une sélection refusée pour
     * l'un de ses produits n'ajoute rien, et la raison s'affiche.
     *
     * @return bool les lignes ont été ajoutées
     */
    public static function ajouterLesProduitsChoisis(HasForms $livewire, mixed $selection): bool
    {
        try {
            $choisis = self::produitsChoisis($selection);
            [$lignes, $contexte] = self::lignesAvantUnAjout($livewire);
            $nouvelles = [];
            $completees = [];

            foreach ($choisis as ['produit' => $produit, 'quantite' => $quantite]) {
                $cle = self::ligneDeLArticle($lignes, 'produit_id', CleDuModele::de($produit));

                if ($cle === null) {
                    $nouvelles[] = self::ligneDuProduit($produit, $quantite, $contexte);

                    continue;
                }

                [$lignes[$cle], $completees[]] = self::completerLaLigne($livewire, $lignes[$cle], $quantite, $contexte);
            }
        } catch (InvalidArgumentException $refus) {
            Notification::make()->title($refus->getMessage())->danger()->send();

            return false;
        }

        self::remplacerLesLignes($livewire, $lignes, $nouvelles, $completees);

        return true;
    }

    /**
     * Les lignes du formulaire qui portent un produit ou un service, par clé
     * de ligne -- les lignes encore vides disparaissent --, et la vente de la
     * page.
     *
     * @return array{array<array-key, array<string, mixed>>, ContexteDeVente}
     */
    private static function lignesAvantUnAjout(HasForms $livewire): array
    {
        $etat = self::etatDuFormulaire($livewire);
        $lignes = [];

        foreach (ValeursLues::tableau(self::repeteurDesLignes($livewire)->getState() ?? []) as $cle => $ligne) {
            if (is_array($ligne) && (filled($ligne['produit_id'] ?? null) || filled($ligne['service_id'] ?? null))) {
                $lignes[$cle] = ValeursLues::champs($ligne);
            }
        }

        return [$lignes, self::contexteDeVente($etat['client_id'] ?? null, $etat['nature_operation'] ?? null)];
    }

    /**
     * Les lignes du formulaire remplacées par celles-ci, dans leur ordre,
     * puis les nouvelles lignes, chacune avec les valeurs par défaut de ses
     * champs (voir ajouterUneLigne()) ; les lignes dont la quantité a
     * augmenté le disent, et celles qui ont repris le prix du catalogue
     * d'aujourd'hui disent ce prix (voir completerLaLigne()).
     *
     * @param  array<array-key, array<string, mixed>>  $lignes
     * @param  list<array<string, mixed>>  $nouvelles
     * @param  list<array{ligne: array<string, mixed>, auPrixDuCatalogue: bool}>  $completees
     */
    private static function remplacerLesLignes(HasForms $livewire, array $lignes, array $nouvelles, array $completees): void
    {
        $repeteur = self::repeteurDesLignes($livewire);
        $repeteur->state($lignes);

        foreach ($nouvelles as $ligne) {
            self::ajouterUneLigne($livewire, $ligne);
        }

        $repeteur->callAfterStateUpdated();

        if ($completees === []) {
            return;
        }

        $quantites = array_map(
            fn (array $completee): string => '« '.ValeurSimple::enTexte($completee['ligne']['description'] ?? '').' » ('.ValeurSimple::enTexte($completee['ligne']['quantite']).')'
                .($completee['auPrixDuCatalogue'] ? ' au prix du catalogue d\'aujourd\'hui, '.self::prixUnitaireEnTexte($completee['ligne']['prix_unitaire_ttc']) : ''),
            $completees,
        );

        Notification::make()
            ->title(count($completees) === 1
                ? 'Déjà sur une ligne : '.$quantites[0].', sa quantité a augmenté.'
                : 'Déjà sur une ligne, leur quantité a augmenté : '.implode(', ', $quantites).'.')
            ->success()
            ->send();
    }

    private static function repeteurDesLignes(HasForms $livewire): Repeater
    {
        return ValeursLues::instance(ValeurRequise::de($livewire->getForm('form'), 'le formulaire de la page')->getComponent('data.lignes'), Repeater::class);
    }

    /**
     * La clé de la première ligne qui porte ce produit ou ce service ; null
     * s'il n'est sur aucune ligne.
     *
     * @param  array<array-key, array<string, mixed>>  $lignes
     * @param  'produit_id'|'service_id'  $champ
     */
    private static function ligneDeLArticle(array $lignes, string $champ, int $identifiant): int|string|null
    {
        foreach ($lignes as $cle => $ligne) {
            if (ValeurSimple::enTexte($ligne[$champ] ?? '') === (string) $identifiant) {
                return $cle;
            }
        }

        return null;
    }

    /**
     * La ligne d'un article choisi de nouveau, sa quantité augmentée de
     * celle choisie (décision de l'éditeur du 26/10/2026, série 9) : elle
     * garde sa place, son prix et sa remise, qui s'appliquent aux articles
     * ajoutés. Refusé, jamais une seconde ligne : tant que la ligne est en
     * cours de saisie (une quantité, un prix ou une remise qui n'est pas
     * encore un nombre valable), et, pour une personne qui n'est pas
     * autorisée à fixer les prix, quand changer sa quantité le demande (voir
     * refusDeChangerLaQuantite()).
     *
     * Pour cette personne, une ligne qui n'est qu'à un prix de fiche ou de
     * référence d'avant (la fiche a changé de prix depuis l'ajout de la
     * ligne), sans remise et sans prix enregistré sur le brouillon,
     * reprend d'abord le prix du catalogue d'aujourd'hui, comme quand elle
     * tape la quantité dans la ligne (Q1, voir ligneSchema()) ; le message
     * de l'ajout le dit.
     *
     * @param  array<string, mixed>  $ligne
     * @return array{array<string, mixed>, array{ligne: array<string, mixed>, auPrixDuCatalogue: bool}}
     *
     * @throws InvalidArgumentException la raison du refus, à montrer telle quelle
     */
    private static function completerLaLigne(HasForms $livewire, array $ligne, int $ajoutee, ContexteDeVente $contexte): array
    {
        $construite = self::ligneConstruite($ligne, $contexte);
        $auPrixDuCatalogue = false;

        if (! self::peutAccorderDesRemises()) {
            $refus = self::refusDeChangerLaQuantite($livewire, $construite, $contexte);

            if ($refus !== null) {
                throw new InvalidArgumentException($refus);
            }

            if (app(DerogationsDePrix::class)->introduites([], ['ligne' => $construite], $contexte) !== []) {
                $ligne = self::ligneAuPrixDuCatalogue($ligne, $contexte);
                $construite = self::ligneConstruite($ligne, $contexte);
                $derogations = app(DerogationsDePrix::class)->introduites([], ['ligne' => $construite], $contexte);

                // Un service sans prix de référence n'a pas de prix du
                // catalogue à reprendre.
                if ($derogations !== []) {
                    throw new InvalidArgumentException(ValeurSimple::enTexte(reset($derogations)));
                }

                $auPrixDuCatalogue = true;
            }
        }

        $ligne = [...$ligne, 'quantite' => self::quantiteCumulee(ValeursLues::entier($construite['quantite']), $ajoutee)];

        return [$ligne, ['ligne' => $ligne, 'auPrixDuCatalogue' => $auPrixDuCatalogue]];
    }

    /**
     * Une ligne du formulaire telle que l'enregistrement la construira dans
     * cette vente (voir ConstructeurLigneDocument).
     *
     * @param  array<string, mixed>  $ligne
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException une ligne encore en cours de saisie
     */
    private static function ligneConstruite(array $ligne, ContexteDeVente $contexte): array
    {
        return ValeursLues::champs(app(ConstructeurLigneDocument::class)->apercu(['ligne' => $ligne], $contexte)->lignes['ligne']
            ?? throw new InvalidArgumentException('Terminez d\'abord la ligne « '.ValeurSimple::enTexte($ligne['description'] ?? '').' » : sa quantité, son prix ou sa remise n\'est pas encore un nombre valable.'));
    }

    /**
     * Pourquoi une personne qui n'est pas autorisée à accorder des remises
     * et fixer le prix d'un service (R2) ne change pas la quantité de cette
     * ligne construite (voir ConstructeurLigneDocument) : elle porte une
     * remise, elle reprend une ligne du devis accepté dont la facture est
     * issue (voir DerogationsDePrix::exigerPourLesLignesDuDevis()), ou elle
     * reprend telle quelle une ligne enregistrée sur le brouillon à un
     * autre prix que celui de la fiche de son produit ou du prix de
     * référence de son service, que l'enregistrement tient pour accordé
     * (voir DerogationsDePrix) ; null sinon. Une ligne saisie sur cette page
     * et seulement restée au prix d'une fiche qui a changé depuis n'est pas
     * refusée : elle reprend le prix du catalogue (voir completerLaLigne()).
     *
     * @param  array<string, mixed>  $ligne
     */
    private static function refusDeChangerLaQuantite(HasForms $livewire, array $ligne, ContexteDeVente $contexte): ?string
    {
        $nom = ValeurSimple::enTexte($ligne['description']);
        $demande = 'demandez à un responsable de changer sa quantité.';
        $derogations = app(DerogationsDePrix::class);

        return match (true) {
            app(CalculateurLigne::class)->enCentiemes($ligne['remise_pourcentage']) !== 0 => "« {$nom} » a une remise sur sa ligne : {$demande}",
            self::repriseDuDevis($livewire, $ligne) => "« {$nom} » est sur une ligne reprise du devis accepté par le client : {$demande}",
            $derogations->introduites([], ['ligne' => $ligne], $contexte) !== []
                && $derogations->introduites(self::lignesEnregistrees($livewire), ['ligne' => $ligne], $contexte) === [] => $ligne['produit_id'] !== null
                    ? "« {$nom} » est sur sa ligne au prix enregistré sur ce brouillon, différent de celui de sa fiche : {$demande}"
                    : "« {$nom} » est sur sa ligne au prix ou au taux enregistré sur ce brouillon, différent de son prix de référence : {$demande}",
            default => null,
        };
    }

    /**
     * Les lignes déjà enregistrées du brouillon de facture ou du devis que
     * la page modifie, telles que DerogationsDePrix les compare ; aucune
     * pour un nouveau document ou la caisse.
     *
     * @return list<array<string, mixed>>
     */
    private static function lignesEnregistrees(HasForms $livewire): array
    {
        $document = $livewire instanceof EditRecord ? $livewire->getRecord() : null;

        return $document instanceof Facture || $document instanceof Devis
            ? array_values($document->lignes()->get()->map(fn (LigneFacture|LigneDevis $ligne): array => $ligne->attributesToArray())->all())
            : [];
    }

    /**
     * La ligne construite de ce produit ou de ce service reprend-elle une
     * ligne du devis accepté dont le brouillon de facture de la page est
     * issu ?
     *
     * @param  array<string, mixed>  $ligne
     */
    private static function repriseDuDevis(HasForms $livewire, array $ligne): bool
    {
        $facture = $livewire instanceof EditRecord ? $livewire->getRecord() : null;

        if (! $facture instanceof Facture || $facture->devis_id === null) {
            return false;
        }

        return LigneDevis::query()
            ->where('devis_id', $facture->devis_id)
            ->where($ligne['produit_id'] !== null ? 'produit_id' : 'service_id', $ligne['produit_id'] ?? $ligne['service_id'])
            ->exists();
    }

    /**
     * Deux lignes du même service au même prix, au même taux et à la même
     * remise, dans cette vente, n'en font qu'une (décision de l'éditeur du
     * 26/10/2026, série 9, règle 3) : quand la saisie du prix, du taux ou de
     * la remise d'une ligne de service la rend identique à une autre (un
     * service sans prix de référence saisi à 60,00 € sur deux lignes), la
     * ligne la plus haute garde sa place et reçoit la somme des quantités,
     * l'autre disparaît, et un message le dit. Rien ne s'inscrit au journal
     * des événements : réunir deux lignes du même article au même prix ne
     * retire rien (voir LignesRetirees). Une ligne encore en cours de saisie
     * ne se compare pas ; deux lignes du même service au même prix et au
     * même taux mais à deux remises restent, et leur envoi est refusé avec
     * la raison (voir regleUnArticleParLigne()).
     */
    public static function reunirLesLignesIdentiques(HasForms $livewire): void
    {
        $etat = self::etatDuFormulaire($livewire);
        $repeteur = self::repeteurDesLignes($livewire);
        $lignes = ValeursLues::tableau($repeteur->getState() ?? []);
        $premieres = [];
        $quantites = [];

        try {
            foreach (self::apercuDesLignes($lignes, $etat['client_id'] ?? null, $etat['nature_operation'] ?? null)->lignes as $cle => $ligne) {
                if ($ligne['service_id'] === null) {
                    continue;
                }

                $conditions = implode('|', array_map(ValeurSimple::enTexte(...), [$ligne['service_id'], $ligne['prix_unitaire_ttc'], $ligne['taux_tva_du_prix'], $ligne['remise_pourcentage']]));
                $haute = $premieres[$conditions] ?? null;

                if ($haute === null) {
                    $premieres[$conditions] = $cle;

                    continue;
                }

                $quantites[$haute] = self::quantiteCumulee($quantites[$haute] ?? QuantiteEntiere::depuisSaisie(ValeursLues::champs($lignes[$haute])['quantite'] ?? null), ValeursLues::entier($ligne['quantite']));
                unset($lignes[$cle]);
            }
        } catch (InvalidArgumentException $refus) {
            Notification::make()->title($refus->getMessage())->danger()->send();

            return;
        }

        if ($quantites === []) {
            return;
        }

        foreach ($quantites as $haute => $quantite) {
            $lignes[$haute] = [...ValeursLues::champs($lignes[$haute]), 'quantite' => $quantite];
        }

        $repeteur->state($lignes);
        $repeteur->callAfterStateUpdated();

        Notification::make()
            ->title(implode(' ', array_map(
                fn (int|string $haute): string => '« '.ValeurSimple::enTexte(ValeursLues::champs($lignes[$haute])['description'] ?? '').' » : ses lignes au même prix sont réunies (quantité '.$quantites[$haute].').',
                array_keys($quantites),
            )))
            ->success()
            ->send();
    }

    /**
     * Le document ne part jamais avec un article sur deux lignes (voir
     * ConstructeurLigneDocument::articleSurDeuxLignes()) : l'écran réunit
     * un produit ou un service choisi de nouveau, et les lignes d'un
     * service devenues identiques (voir reunirLesLignesIdentiques()), et
     * refuse d'envoyer, avec la raison sous les lignes, ce qui reste
     * malgré tout en double (deux lignes d'un service au même prix et au
     * même taux mais à deux remises, une requête forgée). Les lignes se
     * comparent comme l'enregistrement les construira, pour le client et la
     * nature de l'opération saisis.
     */
    private static function regleUnArticleParLigne(): Closure
    {
        return fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $refus = ConstructeurLigneDocument::articleSurDeuxLignes(self::apercuDesLignes(is_array($value) ? $value : [], $get('client_id'), $get('nature_operation'))->lignes);

            if ($refus !== null) {
                $fail($refus);
            }
        };
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
     * Deux quantités d'un même produit, entières et positives (voir
     * QuantiteEntiere), ajoutées l'une à l'autre ; au-delà de ce qu'un
     * entier peut compter, la sélection est refusée avant l'addition, qui
     * deviendrait sinon un flottant approché.
     *
     * @throws InvalidArgumentException
     */
    private static function quantiteCumulee(int $quantite, int $ajoutee): int
    {
        if ($quantite > PHP_INT_MAX - $ajoutee) {
            throw new InvalidArgumentException('La quantité d\'un produit compterait plus d\'articles qu\'une vente ne peut en compter : baissez-la.');
        }

        return $quantite + $ajoutee;
    }

    /**
     * Cases à cocher des services que le magasin a déjà créés ; un service
     * qui n'y est pas encore se crée ici, avec son prix de référence pour
     * qui peut le fixer, et y sera proposé la prochaine fois (voir
     * TrouverOuCreerServiceAction). Chaque service coché devient une ligne
     * à son prix de référence, ou ajoute 1 à la quantité de sa ligne s'il y
     * est déjà (voir ajouterLesServices()) ; sans prix de référence, il
     * devient toujours une nouvelle ligne, dont la personne autorisée saisit
     * le prix, TTC en France, sans TVA sinon (voir champsPrixDeLigne()). La
     * recherche passe par
     * le serveur, sans casse ni accents (voir servicesProposes()) : celle
     * d'une liste de cases à cocher de Filament, faite dans le navigateur,
     * tiendrait compte des accents.
     *
     * Cocher est ouvert à quiconque peut vendre ; créer un service demande
     * l'autorisation « Services : créer » (voir ServicePolicy et
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
            ->modalDescription('Cochez les services réalisés : chacun devient une ligne, ou ajoute 1 à la quantité de sa ligne s\'il y est déjà. Un service dont le prix n\'est pas fixé arrive toujours sur une nouvelle ligne, où vous saisissez son prix ; saisi au prix d\'une autre ligne de ce service, il la rejoint.')
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
                // Le service coché prend la teinte de la rangée choisie d'une
                // fenêtre de sélection (constat C5, voir
                // resources/css/rangees-choisies.css).
                CheckboxList::make('services')
                    ->label('Services du magasin')
                    ->extraAttributes(['class' => 'piliqi-liste-de-choix'])
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
            // Refusé, la fenêtre reste ouverte avec ses cases, pour décocher
            // le service refusé et ajouter les autres.
            ->action(function (array $data, HasForms $livewire, Action $action): void {
                if (! self::ajouterLesServices($livewire, ValeursLues::champs($data))) {
                    $action->halt();
                }
            });
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
        $coches = array_map(ValeurSimple::enEntier(...), is_array($coches) ? $coches : []);

        return ValeursLues::textesParCle(self::servicesVendables()
            ->when($recherche !== '', fn (Builder $services): Builder => $services->where(
                fn (Builder $services): Builder => RechercheSansAccents::appliquer($services, $recherche, ['nom'])->orWhereKey($coches),
            ))
            ->orderBy('nom')
            ->orderBy('id')
            ->pluck('nom', 'id'));
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
     * Les services cochés (« services »), puis le nouveau service tapé
     * (« nouveau_service ») avec son prix de référence (« prix_ttc ») et
     * son taux (« taux_tva »), tels que la fenêtre les envoie. Un service à
     * prix de référence déjà sur une ligne voit la quantité de sa ligne
     * augmenter de 1, qui garde sa place, son prix et sa remise, comme un
     * produit choisi de nouveau (voir completerLaLigne()) ; sinon il arrive
     * sur une nouvelle ligne, à ce prix et à son taux. Un service sans prix
     * de référence arrive toujours sur une nouvelle ligne, dont le prix se
     * saisit : saisi au prix et au taux d'une autre ligne de ce service, sa
     * ligne la rejoint (voir reunirLesLignesIdentiques()). Un ajout refusé
     * pour l'un des services n'ajoute rien, et la raison s'affiche.
     *
     * @param  array<string, mixed>  $data
     * @return bool les services cochés ont été ajoutés
     */
    private static function ajouterLesServices(HasForms $livewire, array $data): bool
    {
        $identifiants = array_map(ValeurSimple::enEntier(...), ValeursLues::tableau($data['services'] ?? []));
        $nouveauService = Service::nettoyerNom(ValeurSimple::enTexte($data['nouveau_service'] ?? ''));

        if ($nouveauService !== '') {
            try {
                $nouveau = app(TrouverOuCreerServiceAction::class)->handle(CompteConnecte::exiger(), $nouveauService, ValeurSimple::enTexteOuNul($data['prix_ttc'] ?? null), ValeurSimple::enTexteOuNul($data['taux_tva'] ?? null));
            } catch (LogicException|AuthorizationException $exception) {
                Notification::make()->title($exception->getMessage())->danger()->send();

                return false;
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

        try {
            [$lignes, $contexte] = self::lignesAvantUnAjout($livewire);
            $nouvelles = [];
            $completees = [];

            // Dans l'ordre où le vendeur les a cochés ; le nouveau service en
            // dernier, une seule fois même s'il était déjà coché.
            foreach (array_unique($identifiants) as $identifiant) {
                $service = $services->get($identifiant);

                if ($service === null) {
                    continue;
                }

                $cle = $service->aUnPrixDeReference() ? self::ligneDeLArticle($lignes, 'service_id', $service->id) : null;

                if ($cle === null) {
                    $nouvelles[] = [
                        'service_id' => $service->id,
                        'description' => $service->nom,
                        ...($service->aUnPrixDeReference() ? [
                            'prix_unitaire_ttc' => $service->prixDeReferenceDans($contexte),
                            'taux_tva' => self::pourcentageEnTexte($service->taux_tva),
                        ] : []),
                    ];

                    continue;
                }

                [$lignes[$cle], $completees[]] = self::completerLaLigne($livewire, $lignes[$cle], 1, $contexte);
            }
        } catch (InvalidArgumentException $refus) {
            Notification::make()->title($refus->getMessage())->danger()->send();

            return false;
        }

        self::remplacerLesLignes($livewire, $lignes, $nouvelles, $completees);

        return true;
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
        $lignes = self::repeteurDesLignes($livewire);

        $uuid = $lignes->generateUuid();

        $lignes->state([...ValeursLues::tableau($lignes->getState() ?? []), $uuid => []]);
        $lignes->getChildComponentContainer($uuid)->fill();

        $etat = ValeursLues::tableau($lignes->getState());
        $etat[$uuid] = [...ValeursLues::tableau($etat[$uuid]), ...$valeurs];
        $lignes->state($etat);
    }
}
