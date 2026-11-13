<?php

namespace App\Filament\Concerns;

use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Achats\Models\TauxFactureAchat;
use App\Domain\Achats\Services\ConstructeurLigneAchat;
use App\Domain\Achats\Services\EcartsDUnTaux;
use App\Domain\Achats\Services\MontantsDuFournisseur;
use App\Domain\Achats\Services\TvaDuFournisseur;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Rules\MontantAuCentime;
use App\Domain\Facturation\Rules\PrixDAchatEcrit;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentChiffre;
use App\Domain\Facturation\Services\QuantiteEntiere;
use App\Domain\Facturation\Services\SaisieDecimale;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\FicheLue;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\App\Resources\ProduitResource;
use Closure;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Checkbox;
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
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/**
 * Les lignes d'une facture d'achat : chacune se saisit dans un formulaire
 * complet (le produit, existant ou nouveau, puis ce qu'il coûte sur cette
 * facture et ce qu'il se vendra en magasin) et apparaît en lecture seule
 * dans la facture. Le bouton « Modifier » d'une ligne rouvre le même
 * formulaire. Une facture d'achat n'a qu'une ligne par produit du
 * catalogue (décision de l'éditeur du 26/10/2026, série 9) : un produit
 * ajouté de nouveau rejoint sa ligne (voir actionAjouterUnProduit()), et
 * l'enregistrement refuse deux lignes du même produit (voir
 * ConstructeurLigneAchat).
 *
 * Les montants sont ceux qu'écrit la facture du fournisseur (décision de
 * l'éditeur du 02/11/2026, T1 et T2, voir TvaDuFournisseur) : le PU HT se
 * saisit tel qu'elle l'écrit, six décimales au plus, et s'affiche tel qu'il
 * est enregistré ; PILIQI propose le HT de chaque ligne et la TVA de chaque
 * taux, que la personne remplace par ceux de la facture (voir
 * tvaDeLaFacture()) ; un total TTC se saisit pour contrôle. Un écart au-delà
 * de la tolérance s'affiche avec son avertissement et demande la case de
 * confirmation, qui marque la facture « TVA à vérifier ».
 *
 * Prix d'achat HT et prix de vente TTC sont deux champs distincts : le
 * prix de vente ne change jamais le prix d'achat, et seul le barème du
 * magasin, s'il l'a activé, propose un prix de vente d'après le prix
 * d'achat (voir appliquerLeBareme()). Un produit existant n'est corrigé
 * qu'à l'enregistrement de la facture, avec elle, et un nouveau produit ne
 * naît qu'à sa validation (voir ConstructeurLigneAchat) : l'état d'une
 * ligne porte donc le produit sous sa clé "produit", et, pour un produit
 * du catalogue, sa fiche telle que la ligne l'a lue sous sa clé
 * "fiche_lue" (voir FicheLue), lue quand le produit est choisi dans la
 * ligne ou quand la facture s'ouvre : l'enregistrement n'écrit que ce que
 * la personne a corrigé depuis cette lecture.
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
                ->itemLabel(fn (array $state): ?string => ValeurSimple::enTexteOuNul(ValeursLues::valeurAu($state, 'produit', 'nom')))
                // Remplacé par le bouton ci-dessous, qui ouvre le formulaire
                // de ligne.
                ->addable(false)
                ->extraItemActions([self::actionModifierLaLigne()])
                ->required()
                ->validationMessages(['required' => 'Ajoutez au moins un produit à la facture.'])
                // Une ligne ajoutée, modifiée ou retirée change les taux de
                // la facture : leur TVA suit (voir tvaDeLaFacture()).
                ->afterStateUpdated(fn (Get $get, Set $set) => self::synchroniserLesTaux($get, $set)),
            Actions::make([self::actionAjouterUnProduit()])->alignCenter(),
        ];
    }

    /**
     * La TVA de chaque taux de la facture, telle que l'écrit la facture du
     * fournisseur (T1), le total TTC saisi pour contrôle, et la case qui
     * confirme les écarts au-delà de la tolérance : une rangée par taux des
     * lignes, sa base et la TVA que PILIQI propose, que la TVA de la
     * facture remplace quand elle en diffère.
     *
     * @return array<Component>
     */
    public static function tvaDeLaFacture(): array
    {
        return [
            Repeater::make('taux')
                ->label('TVA de la facture du fournisseur')
                ->helperText('PILIQI propose la TVA de chaque taux, calculée sur le total HT de ses lignes. Si la facture du fournisseur en écrit une autre, saisissez la sienne : c\'est elle qui est déductible.')
                ->schema([
                    Hidden::make('taux_tva'),
                    TextInput::make('montant_tva')
                        ->label(fn (Get $get): string => 'TVA à '.str_replace('.', ',', self::pourcentageEnTexte(ValeurSimple::enTexteOuNul($get('taux_tva')))).' % sur la facture')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('€')
                        ->rule(new MontantAuCentime)
                        ->placeholder(fn (Get $get): string => self::tvaProposee($get))
                        ->helperText(fn (Get $get): string => self::detailDeLaTva($get))
                        ->saisieEnDirect(),
                ])
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->defaultItems(0)
                ->visible(fn (Get $get): bool => filled($get('taux'))),
            // Jamais « total_ttc » : la page de modification remplit le
            // formulaire depuis les colonnes de la facture, dont son total.
            TextInput::make('total_ttc_controle')
                ->label('Total TTC de la facture (contrôle)')
                ->helperText('Facultatif : le total TTC qu\'écrit la facture du fournisseur, pour vérifier vos saisies. Il doit valoir le total HT plus la TVA.')
                ->numeric()
                ->minValue(0)
                ->suffix('€')
                ->rule(new MontantAuCentime)
                ->saisieEnDirect(),
            Checkbox::make('confirmer_les_ecarts')
                ->label('Je confirme ces montants : ce sont ceux de la facture du fournisseur. La facture sera marquée « TVA à vérifier ».')
                ->helperText(fn (Get $get, ?Model $record): HtmlString => new HtmlString(implode('<br>', array_map(e(...), self::avertissementsDesEcarts($get, $record)))))
                ->visible(fn (Get $get, ?Model $record): bool => self::avertissementsDesEcarts($get, $record) !== [])
                ->accepted()
                ->validationMessages(['accepted' => 'Confirmez les montants de la facture, ou corrigez votre saisie.']),
        ];
    }

    /**
     * La saisie de la TVA et du contrôle telle que l'enregistrement la
     * reçoit (voir MontantsDuFournisseur).
     *
     * @param  array<string, mixed>  $data
     */
    public static function montantsDuFournisseur(array $data): MontantsDuFournisseur
    {
        return MontantsDuFournisseur::depuisLaSaisie([
            'taux' => $data['taux'] ?? [],
            'total_ttc' => $data['total_ttc_controle'] ?? null,
            'confirmation' => $data['confirmer_les_ecarts'] ?? false,
        ]);
    }

    /**
     * La TVA de chaque taux d'une facture enregistrée, telle que son
     * formulaire la rouvre : celle que la personne avait saisie, sinon
     * rien, la proposition de PILIQI.
     *
     * @return array<string, array{taux_tva: string, montant_tva: string|null}>
     */
    public static function tauxDejaEnregistres(FactureAchat $factureAchat): array
    {
        return $factureAchat->taux()->get()
            ->mapWithKeys(fn (TauxFactureAchat $taux): array => [self::cleDuTaux($taux->taux_tva) => ['taux_tva' => $taux->taux_tva, 'montant_tva' => $taux->montant_tva_saisi]])
            ->all();
    }

    /**
     * Les rangées de la TVA suivent les taux des lignes, dans l'ordre où ils
     * apparaissent (voir rangeesDeLaTva()).
     */
    private static function synchroniserLesTaux(Get $get, Set $set): void
    {
        $set('taux', self::rangeesDeLaTva(array_keys(app(ConstructeurLigneAchat::class)->apercu(ValeursLues::tableau($get('lignes') ?? []))->parTaux), $get('taux')));
    }

    /**
     * Une rangée de TVA pour chacun de ces taux, dans leur ordre : celle
     * d'un taux qui reste garde sa clé et la TVA qui y est saisie. Une
     * réponse qui changerait sa clé remplacerait dans la page le champ de
     * sa TVA, peut-être en pleine frappe, et réécrirait ce que la personne
     * tape ailleurs dans le formulaire (voir saisieEnDirect() dans
     * AppServiceProvider).
     *
     * @param  iterable<mixed>  $taux  écrits comme leur colonne (« 20.00 »)
     * @param  mixed  $rangees  les rangées actuelles
     * @return array<string, array{taux_tva: mixed, montant_tva: mixed}>
     */
    private static function rangeesDeLaTva(iterable $taux, mixed $rangees): array
    {
        $existantes = collect(is_array($rangees) ? $rangees : [])
            ->filter(fn (mixed $rangee): bool => is_array($rangee) && filled($rangee['taux_tva'] ?? null))
            ->mapWithKeys(fn (array $rangee, int|string $cle): array => [self::cleDuTaux($rangee['taux_tva']) => ['cle' => (string) $cle, 'montant_tva' => $rangee['montant_tva'] ?? null]]);
        $nouvelles = [];

        foreach ($taux as $unTaux) {
            $existante = $existantes->get(self::cleDuTaux($unTaux));
            $nouvelles[$existante['cle'] ?? self::cleDuTaux($unTaux)] = ['taux_tva' => $unTaux, 'montant_tva' => $existante['montant_tva'] ?? null];
        }

        return $nouvelles;
    }

    /**
     * La clé d'une rangée de TVA : son taux, en centièmes de point.
     */
    private static function cleDuTaux(mixed $taux): string
    {
        try {
            return 'taux-'.SaisieDecimale::enCentiemes($taux);
        } catch (InvalidArgumentException) {
            return 'taux-'.md5((string) json_encode($taux));
        }
    }

    /**
     * La TVA saisie de chaque taux, telle que l'aperçu la reçoit : une
     * saisie illisible ou vide ne dit rien.
     *
     * @return array<int, int>
     */
    private static function tvaEcriteDuFormulaire(mixed $taux): array
    {
        $tvaParTaux = [];

        foreach (is_array($taux) ? $taux : [] as $rangee) {
            try {
                if (is_array($rangee) && filled($rangee['taux_tva'] ?? null) && filled($rangee['montant_tva'] ?? null)) {
                    $tvaParTaux[SaisieDecimale::enCentiemes($rangee['taux_tva'])] = SaisieDecimale::montantAuCentime($rangee['montant_tva']);
                }
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $tvaParTaux;
    }

    /**
     * La TVA que PILIQI propose pour le taux de la rangée.
     */
    private static function tvaProposee(Get $get): string
    {
        $document = app(ConstructeurLigneAchat::class)->apercu(ValeursLues::tableau($get('../../lignes') ?? []));
        $taux = self::tauxDeLaRangee($get);

        return isset($document->parTaux[$taux]) ? self::montantEnTexte($document->tvaProposee($taux)) : '';
    }

    /**
     * Sous la TVA d'un taux : sa base, la TVA que PILIQI propose et, quand
     * la saisie s'en écarte au-delà de la tolérance, l'avertissement.
     */
    private static function detailDeLaTva(Get $get): string
    {
        $taux = self::tauxDeLaRangee($get);
        $document = app(ConstructeurLigneAchat::class)->apercu(ValeursLues::tableau($get('../../lignes') ?? []), self::tvaEcriteDuFormulaire($get('../../taux')));
        $montants = $document->parTaux[$taux] ?? null;

        if ($montants === null) {
            return '';
        }

        $detail = 'Base HT '.self::montantEnTexte($montants['montant_ht']).' · calcul depuis les lignes : '.self::montantEnTexte($document->tvaProposee($taux)).'. Laissez vide pour le garder.';
        $ecart = app(TvaDuFournisseur::class)->ecarts($document)[$taux] ?? null;

        return $ecart !== null && $ecart->tvaHorsTolerance() ? $detail.' '.implode(' ', array_slice($ecart->avertissements(), -1)) : $detail;
    }

    /**
     * Le taux de la rangée de TVA, écrit comme sa colonne (« 20.00 »).
     */
    private static function tauxDeLaRangee(Get $get): string
    {
        try {
            return app(CalculateurLigne::class)->enDecimal(SaisieDecimale::enCentiemes($get('taux_tva')));
        } catch (InvalidArgumentException) {
            return '';
        }
    }

    /**
     * Les écarts au-delà de la tolérance que la saisie n'a pas déjà
     * confirmés (voir TvaDuFournisseur::ecartsAConfirmer()) : chaque
     * avertissement, que la case de confirmation montre.
     *
     * @return list<string>
     */
    private static function avertissementsDesEcarts(Get $get, ?Model $record): array
    {
        $document = app(ConstructeurLigneAchat::class)->apercu(ValeursLues::tableau($get('lignes') ?? []), self::tvaEcriteDuFormulaire($get('taux')));
        $ecarts = app(TvaDuFournisseur::class)->ecartsAConfirmer($document, $record instanceof FactureAchat ? $record : null);

        return array_merge(...array_map(fn (EcartsDUnTaux $ecart): array => $ecart->avertissements(), $ecarts));
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
            Hidden::make('fiche_lue'),
            // Gardée avec la ligne pour la retrouver à sa réouverture (voir
            // appliquerLeBareme()), jamais enregistrée.
            Hidden::make('prix_vente_propose')->dehydrated(false),
            ...array_map(
                fn (string $champ): Hidden => Hidden::make("produit.{$champ}"),
                array_diff(ConstructeurLigneAchat::ATTRIBUTS_DU_PRODUIT, ['prix_vente_ttc']),
            ),
            // Le HT de la ligne qu'écrit la facture du fournisseur, quand il
            // diffère de la proposition de PILIQI (voir formulaireDeLigne()).
            Hidden::make('montant_ht'),
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
     * TVA comprise -- jamais un montant de vente. Son HT est celui
     * qu'écrit la facture du fournisseur, sinon la proposition de PILIQI ;
     * sa TVA est sa part de celle de son taux (voir
     * ConstructeurLigneAchat::apercu()).
     */
    private static function montantPayeAuFournisseur(): Placeholder
    {
        return Placeholder::make('montant_paye_au_fournisseur')
            ->label('Montant payé au fournisseur')
            ->columnSpan(2)
            ->content(fn (Get $get, Placeholder $component): string => self::montantEnTexte(self::montantsDeLaLigneDAchat($get, $component)['montant_ttc']).' TTC')
            ->helperText(function (Get $get, Placeholder $component): string {
                $montants = self::montantsDeLaLigneDAchat($get, $component);

                return ValeurSimple::enTexte($get('quantite') ?? 0).' x '.self::prixDAchatEnTexte($get('prix_unitaire_ht')).' HT'
                    .(filled($get('remise_pourcentage')) && self::remiseSaisie($get) > 0 ? ' remisé de '.self::pourcentageEnTexte(ValeurSimple::enTexteOuNul($get('remise_pourcentage'))).' %' : '')
                    .' = '.self::montantEnTexte($montants['montant_ht_calcule']).' HT'
                    .($montants['montant_ht'] !== $montants['montant_ht_calcule'] ? ' ; HT de la facture : '.self::montantEnTexte($montants['montant_ht']) : '')
                    .' + '.self::montantEnTexte($montants['montant_tva']).' de TVA';
            });
    }

    /**
     * Le HT que PILIQI propose pour la ligne du formulaire : quantité x PU
     * HT, remise déduite, arrondi une fois ; aucun tant que la saisie n'est
     * pas valable.
     */
    private static function htProposeEnTexte(Get $get): ?string
    {
        $calculateur = app(CalculateurLigne::class);

        try {
            return $calculateur->enEuros($calculateur->htProposeDUneLigneDAchat(
                SaisieDecimale::enMillioniemes($get('prix_unitaire_ht')),
                QuantiteEntiere::depuisSaisie($get('quantite')),
                filled($get('remise_pourcentage')) ? SaisieDecimale::enCentiemes($get('remise_pourcentage')) : 0,
            ));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Sous le HT de la ligne : la proposition de PILIQI, et, quand le HT de
     * la facture s'en écarte de plus de 0,02 €, l'avertissement ; la
     * confirmation se demande avec la facture.
     */
    private static function detailDuHtDeLaLigne(Get $get): string
    {
        $propose = self::htProposeEnTexte($get);

        if ($propose === null) {
            return 'Le HT qu\'écrit la facture du fournisseur pour cette ligne, s\'il diffère de la quantité × le prix d\'achat, remise déduite.';
        }

        $detail = "Calcul de PILIQI : {$propose} (quantité × prix d'achat, remise déduite). Laissez vide pour le garder, ou saisissez celui de la facture s'il en diffère.";

        try {
            $calculateur = app(CalculateurLigne::class);
            $ecart = abs(SaisieDecimale::montantAuCentime($get('montant_ht')) - $calculateur->htProposeDUneLigneDAchat(
                SaisieDecimale::enMillioniemes($get('prix_unitaire_ht')),
                QuantiteEntiere::depuisSaisie($get('quantite')),
                filled($get('remise_pourcentage')) ? SaisieDecimale::enCentiemes($get('remise_pourcentage')) : 0,
            ));
        } catch (InvalidArgumentException) {
            return $detail;
        }

        return $ecart > EcartsDUnTaux::TOLERANCE_DU_HT_D_UNE_LIGNE
            ? $detail.' Écart de '.$calculateur->enEuros($ecart).', au-delà de 0,02 € : vérifiez la facture ; il faudra le confirmer.'
            : $detail;
    }

    /**
     * Un prix d'achat tel qu'il s'affiche : tel qu'il est saisi, sans zéro
     * inutile au-delà du centime (« 0,1234 € », voir
     * CalculateurLigne::prixUnitaireExact()) ; « — » tant qu'il n'est pas un
     * prix lisible.
     */
    private static function prixDAchatEnTexte(mixed $prix): string
    {
        try {
            return app(CalculateurLigne::class)->prixUnitaireExact(SaisieDecimale::enMillioniemes($prix));
        } catch (InvalidArgumentException) {
            return '—';
        }
    }

    /**
     * Un prix d'achat tel que le garde la ligne et que le montre le champ où
     * il se saisit : « 0.1234 », « 12.50 » (voir
     * CalculateurLigne::prixUnitaireExactEnDecimal()) ; une saisie illisible reste
     * telle quelle, que l'enregistrement refuse avec son motif.
     */
    private static function prixDAchatDansLeChamp(mixed $prix): mixed
    {
        try {
            return app(CalculateurLigne::class)->prixUnitaireExactEnDecimal(SaisieDecimale::enMillioniemes($prix));
        } catch (InvalidArgumentException) {
            return $prix;
        }
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

                $vente = self::prixUnitaireEnTexte($get('produit.prix_vente_ttc')).' TTC l\'article';
                $tauxTva = ProduitResource::tauxDeTvaSaisi($get('produit.taux_tva')) ?? ProduitResource::tauxDeTvaSaisi($get('taux_tva'));

                if ($tauxTva === null) {
                    return $vente;
                }

                try {
                    $calculateur = app(CalculateurLigne::class);
                    $benefice = $calculateur->beneficeUnitaire(
                        $calculateur->prixUnitaireHtDepuisTtc(SaisieDecimale::enCentiemes($get('produit.prix_vente_ttc')), $tauxTva),
                        self::prixDAchatNet($get),
                    );
                } catch (InvalidArgumentException) {
                    return $vente;
                }

                return $vente.' · bénéfice '.$calculateur->enEuros($benefice).' HT par article';
            });
    }

    /**
     * Une facture d'achat n'a qu'une ligne par produit (décision de
     * l'éditeur du 26/10/2026, série 9) : un produit du catalogue déjà sur
     * la facture, choisi de nouveau, ajoute sa quantité à sa ligne, qui
     * garde sa place (voir reunirAvecSaLigne()) ; la fenêtre le dit dès le
     * choix du produit, et un message après l'ajout.
     */
    private static function actionAjouterUnProduit(): Action
    {
        return Action::make('ajouterUnProduit')
            ->label('Ajouter un produit')
            ->icon('heroicon-m-plus')
            ->modalHeading('Ajouter un produit à la facture')
            ->modalSubmitActionLabel('Ajouter à la facture')
            ->modalWidth(MaxWidth::FourExtraLarge)
            ->form(self::formulaireDeLigne(ajout: true))
            ->action(function (array $data, HasForms $livewire): void {
                $repeteur = ValeursLues::instance(ValeurRequise::de($livewire->getForm('form'), 'le formulaire de la page')->getComponent('data.lignes'), Repeater::class);
                $lignes = ValeursLues::tableau($repeteur->getState() ?? []);
                $ligne = self::ligneDepuisLeFormulaire(ValeursLues::champs($data));
                $cle = self::ligneDuProduit($lignes, $ligne['produit_id']);

                if ($cle === null) {
                    $repeteur->state([...$lignes, $repeteur->generateUuid() => $ligne]);
                    $repeteur->callAfterStateUpdated();

                    return;
                }

                $lignes[$cle] = self::reunirAvecSaLigne(ValeursLues::champs($lignes[$cle]), $ligne);
                $repeteur->state($lignes);
                $repeteur->callAfterStateUpdated();

                Notification::make()
                    ->title('« '.ValeurSimple::enTexte(ValeursLues::valeurAu($ligne, 'produit', 'nom')).' » était déjà sur la facture : sa ligne compte désormais '.ValeurSimple::enTexte($lignes[$cle]['quantite']).' articles à '.self::prixDAchatEnTexte($lignes[$cle]['prix_unitaire_ht']).' HT.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Une ligne ne prend pas le produit d'une autre ligne de la facture :
     * c'est cette autre ligne qui se modifie, une facture d'achat n'ayant
     * qu'une ligne par produit. Refusé, la fenêtre reste ouverte avec la
     * raison.
     */
    private static function actionModifierLaLigne(): Action
    {
        return Action::make('modifierLaLigne')
            ->label('Modifier')
            ->icon('heroicon-m-pencil-square')
            ->color('gray')
            ->modalHeading('Modifier la ligne')
            ->modalSubmitActionLabel('Enregistrer la ligne')
            ->modalWidth(MaxWidth::FourExtraLarge)
            ->fillForm(fn (array $arguments, Repeater $component): array => self::formulaireDepuisLaLigne($component->getRawItemState(ValeurSimple::enTexte($arguments['item']))))
            ->form(self::formulaireDeLigne())
            ->action(function (array $arguments, array $data, Repeater $component, Action $action): void {
                $item = ValeurSimple::enTexte($arguments['item']);
                $etat = ValeursLues::tableau($component->getState() ?? []);
                $ligne = self::ligneDepuisLeFormulaire(ValeursLues::champs($data));
                $autres = array_diff_key($etat, [$item => true]);

                if (self::ligneDuProduit($autres, $ligne['produit_id']) !== null) {
                    Notification::make()
                        ->title('« '.ValeurSimple::enTexte(ValeursLues::valeurAu($ligne, 'produit', 'nom')).' » est déjà sur une autre ligne de cette facture d\'achat : modifiez cette ligne-là, une facture d\'achat n\'a qu\'une ligne par produit.')
                        ->danger()
                        ->send();
                    $action->halt();
                }

                $etat[$item] = $ligne;

                $component->state($etat);
                $component->callAfterStateUpdated();
            });
    }

    /**
     * La clé de la ligne de la facture qui porte ce produit du catalogue ;
     * null pour un nouveau produit, qui n'a pas encore de fiche, ou un
     * produit sur aucune ligne.
     *
     * @param  array<array-key, mixed>  $lignes
     */
    private static function ligneDuProduit(array $lignes, mixed $produitId): int|string|null
    {
        if (blank($produitId)) {
            return null;
        }

        foreach ($lignes as $cle => $ligne) {
            if (is_array($ligne) && filled($ligne['produit_id'] ?? null) && ValeurSimple::enTexte($ligne['produit_id']) === ValeurSimple::enTexte($produitId)) {
                return $cle;
            }
        }

        return null;
    }

    /**
     * La ligne d'un produit déjà sur la facture, quand on l'ajoute de
     * nouveau : la quantité des deux, et, si le fournisseur le facture au
     * même prix et à la même remise, ce prix et cette remise ; sinon (10
     * câbles à 4,00 € HT et 2 offerts à 0,00 €), le prix moyen, sans remise,
     * que donne le HT des deux (40,00 € pour 12 câbles : 3,333333 €, voir
     * CalculateurLigne::prixMoyenDUnAchat()). Le HT de chaque part est celui
     * qu'écrit la facture du fournisseur, sinon la proposition de PILIQI ;
     * leur somme reste le HT de la ligne quand le calcul de PILIQI ne la
     * retrouve pas au centime. L'écart d'un centime possible avec la
     * facture du fournisseur est accepté par l'éditeur. La fiche du produit
     * est celle du dernier formulaire, lue et corrigée en dernier.
     *
     * @param  array<string, mixed>  $existante  voir ligneDepuisLeFormulaire()
     * @param  array<string, mixed>  $ajout  voir ligneDepuisLeFormulaire()
     * @return array<string, mixed>
     */
    private static function reunirAvecSaLigne(array $existante, array $ajout): array
    {
        $calculateur = app(CalculateurLigne::class);
        $quantite = QuantiteEntiere::depuisSaisie($existante['quantite']) + QuantiteEntiere::depuisSaisie($ajout['quantite']);
        [$prixExistant, $remiseExistante, $htExistant] = self::prixRemiseEtHt($existante);
        [$prixAjoute, $remiseAjoutee, $htAjoute] = self::prixRemiseEtHt($ajout);
        $ht = $htExistant + $htAjoute;
        [$prix, $remise] = $prixExistant === $prixAjoute && $remiseExistante === $remiseAjoutee
            ? [$prixExistant, $remiseExistante]
            : [$calculateur->prixMoyenDUnAchat($ht, $quantite), 0];

        return [
            ...$ajout,
            'quantite' => $quantite,
            'prix_unitaire_ht' => $calculateur->prixUnitaireExactEnDecimal($prix),
            'remise_pourcentage' => self::pourcentageEnTexte($calculateur->enDecimal($remise)),
            'montant_ht' => $ht === $calculateur->htProposeDUneLigneDAchat($prix, $quantite, $remise) ? null : $calculateur->enDecimal($ht),
        ];
    }

    /**
     * Le PU HT d'une ligne (en millionièmes d'euro), sa remise (en
     * centièmes de point) et son HT (en centimes) : celui qu'écrit la
     * facture du fournisseur, sinon la proposition de PILIQI.
     *
     * @param  array<string, mixed>  $ligne
     * @return array{int, int, int}
     */
    private static function prixRemiseEtHt(array $ligne): array
    {
        $calculateur = app(CalculateurLigne::class);
        $prix = SaisieDecimale::enMillioniemes($ligne['prix_unitaire_ht']);
        $remise = filled($ligne['remise_pourcentage'] ?? null) ? SaisieDecimale::enCentiemes($ligne['remise_pourcentage']) : 0;
        $ht = filled($ligne['montant_ht'] ?? null)
            ? SaisieDecimale::montantAuCentime($ligne['montant_ht'])
            : $calculateur->htProposeDUneLigneDAchat($prix, QuantiteEntiere::depuisSaisie($ligne['quantite']), $remise);

        return [$prix, $remise, $ht];
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
    private static function formulaireDeLigne(bool $ajout = false): array
    {
        // Archivé compris : la ligne qui le garde se rouvre sur sa fiche,
        // non modifiable (voir ProduitPolicy::update()), et l'enregistrement
        // la refuse ensuite avec un message (voir ConstructeurLigneAchat).
        $produitSelectionne = fn (Get $get): ?Produit => filled($get('produit_id')) ? Produit::withTrashed()->whereKey(ValeursLues::cle($get('produit_id')))->first() : null;
        $produitChoisi = fn (Get $get): bool => $get('origine') === self::NOUVEAU_PRODUIT || filled($get('produit_id'));
        $ficheNonModifiable = fn (Get $get): bool => ($produit = $produitSelectionne($get)) !== null && ! CompteConnecte::exiger()->can('update', $produit);
        $figeParSonFournisseur = fn (Get $get): bool => $produitSelectionne($get)?->vientDUneFactureAchatApi() ?? false;
        $identificationNonModifiable = fn (Get $get): bool => $ficheNonModifiable($get) || $figeParSonFournisseur($get);

        return [
            Section::make('Produit')->schema([
                Hidden::make('fiche_lue'),
                Radio::make('origine')
                    ->label('')
                    ->options([
                        self::PRODUIT_EXISTANT => 'Produit du catalogue',
                        self::NOUVEAU_PRODUIT => 'Nouveau produit',
                    ])
                    ->inline()
                    ->default(self::PRODUIT_EXISTANT)
                    ->required()
                    ->visible(fn (): bool => CompteConnecte::exiger()->can(PermissionRegistry::PRODUIT_CREATE))
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
                    ->getOptionLabelUsing(fn ($value): ?string => Produit::withTrashed()->whereKey(ValeursLues::cle($value))->first()?->nom)
                    ->required()
                    ->visible(fn (Get $get): bool => $get('origine') !== self::NOUVEAU_PRODUIT)
                    ->live()
                    ->columnSpanFull()
                    ->afterStateUpdated(fn (int|string|null $state, Set $set) => self::remplirDepuisLeProduit(filled($state) ? Produit::find($state) : null, $set)),
                Placeholder::make('deja_sur_la_facture')
                    ->label('')
                    ->content(fn (): string => 'Ce produit est déjà sur cette facture d\'achat, qui n\'a qu\'une ligne par produit : la quantité saisie ici s\'ajoute à sa ligne. Si le fournisseur le facture à deux prix (des articles offerts à 0,00 €, par exemple), sa ligne prend le prix moyen des deux ; un écart d\'un centime avec sa facture reste possible.')
                    ->visible(fn (Get $get, HasForms $livewire): bool => $ajout && $get('origine') !== self::NOUVEAU_PRODUIT && self::ligneDuProduit(ValeursLues::tableau(ValeursLues::champs(self::etatDeLaPage($livewire))['lignes'] ?? []), $get('produit_id')) !== null)
                    ->columnSpanFull(),
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
                        ->helperText('Le prix unitaire payé au fournisseur, avant remise, tel que sa facture l\'écrit : six décimales au plus.')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('€')
                        ->required()
                        ->rule(new PrixDAchatEcrit)
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
                    TextInput::make('montant_ht')
                        ->label('Total HT de la ligne sur la facture')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('€')
                        ->rule(new MontantAuCentime)
                        ->placeholder(fn (Get $get): string => self::htProposeEnTexte($get) ?? '')
                        ->helperText(fn (Get $get): string => self::detailDuHtDeLaLigne($get))
                        ->saisieEnDirect(),
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
     * Le formulaire de la page tel qu'il est saisi, depuis la fenêtre d'une
     * ligne.
     *
     * @return array<array-key, mixed>
     */
    private static function etatDeLaPage(HasForms $livewire): array
    {
        $etat = ValeurRequise::de($livewire->getForm('form'), 'le formulaire de la page')->getRawState();

        return is_array($etat) ? $etat : $etat->toArray();
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
                $prixDeVente = app(CalculateurLigne::class)->enDecimal($prix['prix_vente_ttc']);
                $set('prix_vente_ttc', $prixDeVente);
                $set('prix_vente_propose', $prixDeVente);

                return;
            }

            // Au-delà du dernier palier, sans bénéfice prévu : le prix de
            // vente se saisit à la main. Une proposition faite pour un prix
            // d'achat précédent ne doit pas rester comme si elle valait
            // encore -- sauf si la personne l'a déjà changée elle-même.
            if (filled($get('prix_vente_propose')) && $get('prix_vente_ttc') === $get('prix_vente_propose')) {
                $produit = filled($get('produit_id')) ? Produit::withTrashed()->whereKey(ValeursLues::cle($get('produit_id')))->first() : null;

                $set('prix_vente_ttc', $produit === null ? null : self::prixEnTexte($produit->prix_vente_ttc));
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
                $calculateur->prixUnitaireHtDepuisTtc(SaisieDecimale::enCentiemes($get('prix_vente_ttc')), $tauxTva),
                self::prixDAchatNet($get),
            );
        } catch (InvalidArgumentException) {
            return $detail;
        }

        return $detail.' · bénéfice '.$calculateur->enEuros($benefice).' HT par article';
    }

    /**
     * Les lignes saisies de la facture d'achat, avec la TVA que la personne
     * a saisie pour chaque taux, calculées comme à leur enregistrement.
     */
    protected static function documentDesLignesSaisies(Get $get): DocumentChiffre
    {
        return app(ConstructeurLigneAchat::class)->apercu(ValeursLues::tableau($get('lignes') ?? []), self::tvaEcriteDuFormulaire($get('taux')));
    }

    /**
     * La ligne du champ, lue par sa clé dans le répéteur dans l'aperçu de
     * toutes les lignes de la facture d'achat, avec la TVA saisie de chaque
     * taux (voir documentDesLignesSaisies()) ; aucun montant tant qu'elle
     * n'est pas valable.
     *
     * @return array{montant_ht: string, montant_ht_calcule: string, montant_tva: string, montant_ttc: string}
     */
    private static function montantsDeLaLigneDAchat(Get $get, Placeholder $champ): array
    {
        $ligne = app(ConstructeurLigneAchat::class)->apercu(ValeursLues::tableau($get('../../lignes') ?? []), self::tvaEcriteDuFormulaire($get('../../taux')))->lignes[$champ->getContainer()->getStatePath(isAbsolute: false)] ?? [];

        return [
            'montant_ht' => ValeursLues::texte($ligne['montant_ht'] ?? '0.00'),
            'montant_ht_calcule' => ValeursLues::texte($ligne['montant_ht_calcule'] ?? '0.00'),
            'montant_tva' => ValeursLues::texte($ligne['montant_tva'] ?? '0.00'),
            'montant_ttc' => ValeursLues::texte($ligne['montant_ttc'] ?? '0.00'),
        ];
    }

    /**
     * Ce que coûte un article, remise de la ligne déduite, au dix-millième
     * d'euro (voir CalculateurLigne::coutUnitaireNet()).
     *
     * @throws InvalidArgumentException tant que le prix d'achat n'est pas lisible, ou une remise hors de 0-100 %
     */
    private static function prixDAchatNet(Get $get): int
    {
        return app(CalculateurLigne::class)->coutUnitaireNet(SaisieDecimale::enMillioniemes(ValeurSimple::enTexte($get('prix_unitaire_ht'))), self::remiseSaisie($get));
    }

    /**
     * La remise saisie de la ligne, en centièmes de point, lue au centième
     * comme son enregistrement (voir SaisieDecimale::enCentiemes()) : un
     * champ vide ou une saisie qui n'est pas encore un nombre valent 0, le
     * temps de la corriger.
     */
    private static function remiseSaisie(Get $get): int
    {
        $remise = ValeurSimple::simple($get('remise_pourcentage'));

        try {
            return blank($remise) ? 0 : SaisieDecimale::enCentiemes($remise);
        } catch (InvalidArgumentException) {
            return 0;
        }
    }

    /**
     * Les valeurs du produit choisi, et sa fiche telle que la ligne la lit
     * à cet instant ; ou une fiche vierge.
     */
    private static function remplirDepuisLeProduit(?Produit $produit, Set $set): void
    {
        if ($produit === null) {
            $set('produit_id', null);
        }

        $set('fiche_lue', $produit === null ? null : FicheLue::de($produit)->valeurs());

        foreach (self::valeursDuProduit($produit) as $champ => $valeur) {
            $set($champ, $valeur);
        }

        $set('prix_unitaire_ht', $produit?->dernierPrixDAchatHt());
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
            'prix_vente_ttc' => blank($produit?->prix_vente_ttc) ? null : self::prixEnTexte($produit->prix_vente_ttc),
            'taux_tva' => self::pourcentageEnTexte($produit->taux_tva ?? app(TenantContext::class)->magasin()->tauxTvaParDefaut()),
            'suivi_stock' => $produit->suivi_stock ?? true,
            'seuil_alerte' => $produit->seuil_alerte ?? 0,
        ];
    }

    /**
     * Les champs non modifiables de la fiche ne reviennent pas du
     * formulaire : la ligne reprend alors ceux de la fiche qu'elle a lue,
     * jamais une relecture, qui passerait pour une correction. Un produit
     * archivé depuis reste celui de la ligne, que l'enregistrement refuse
     * avec un message (voir ConstructeurLigneAchat) : il ne devient jamais
     * un nouveau produit en silence.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function ligneDepuisLeFormulaire(array $data): array
    {
        $produit = filled($data['produit_id'] ?? null) ? Produit::withTrashed()->whereKey(ValeursLues::cle($data['produit_id']))->first() : null;
        $ficheLue = match (true) {
            $produit === null => null,
            is_array($data['fiche_lue'] ?? null) => FicheLue::depuis(Produit::class, ValeursLues::champs($data['fiche_lue'])),
            default => FicheLue::de($produit),
        };
        $valeursDuProduit = [...($ficheLue === null ? [] : self::valeursDuProduit($ficheLue->fiche())), ...array_intersect_key($data, array_flip(ConstructeurLigneAchat::ATTRIBUTS_DU_PRODUIT))];
        $prixDeVente = ValeurSimple::simple($valeursDuProduit['prix_vente_ttc']);
        $valeursDuProduit['prix_vente_ttc'] = self::prixEnTexte(blank($prixDeVente) ? 0 : $prixDeVente);

        return [
            'produit_id' => $produit?->id,
            'fiche_lue' => $ficheLue?->valeurs(),
            'produit' => $valeursDuProduit,
            'quantite' => $data['quantite'],
            'prix_unitaire_ht' => self::prixDAchatDansLeChamp($data['prix_unitaire_ht']),
            'remise_pourcentage' => $data['remise_pourcentage'] ?? 0,
            // Le taux de la fiche s'applique aussi à cette ligne.
            'taux_tva' => $valeursDuProduit['taux_tva'],
            'montant_ht' => filled($data['montant_ht'] ?? null) ? $data['montant_ht'] : null,
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
            ...ValeursLues::champs($ligne['produit'] ?? []),
            'origine' => filled($ligne['produit_id'] ?? null) ? self::PRODUIT_EXISTANT : self::NOUVEAU_PRODUIT,
            'produit_id' => $ligne['produit_id'] ?? null,
            'fiche_lue' => $ligne['fiche_lue'] ?? null,
            'quantite' => $ligne['quantite'] ?? 1,
            'prix_unitaire_ht' => $ligne['prix_unitaire_ht'] ?? null,
            'remise_pourcentage' => $ligne['remise_pourcentage'] ?? 0,
            'taux_tva' => $ligne['taux_tva'] ?? ValeursLues::valeurAu($ligne, 'produit', 'taux_tva') ?? self::pourcentageEnTexte(app(TenantContext::class)->magasin()->tauxTvaParDefaut()),
            'montant_ht' => $ligne['montant_ht'] ?? null,
            'prix_vente_propose' => $ligne['prix_vente_propose'] ?? null,
        ];
    }

    /**
     * Une ligne déjà enregistrée, telle que la facture l'affiche à sa
     * modification : le produit tel qu'il est aujourd'hui, avec sa fiche
     * ainsi lue, ou la fiche du nouveau produit que le brouillon garde
     * jusqu'à sa validation ; la ligne telle qu'elle a été achetée, son PU
     * HT tel que le fournisseur l'a écrit et le HT de la facture, s'il a
     * été saisi.
     *
     * @return array<string, mixed>
     */
    public static function ligneDejaEnregistree(LigneAchat $ligne): array
    {
        return [
            'produit_id' => $ligne->produit_id,
            'fiche_lue' => $ligne->produit === null ? null : FicheLue::de($ligne->produit)->valeurs(),
            'produit' => self::valeursDuProduit($ligne->produit ?? new Produit(ValeursLues::champs(ValeurRequise::de($ligne->nouveau_produit, 'la fiche du nouveau produit d\'une ligne sans produit')))),
            'quantite' => $ligne->quantite,
            'prix_unitaire_ht' => $ligne->prix_unitaire_ht,
            'remise_pourcentage' => self::pourcentageEnTexte($ligne->remise_pourcentage),
            'taux_tva' => self::pourcentageEnTexte($ligne->taux_tva),
            'montant_ht' => $ligne->montant_ht_saisi,
        ];
    }
}
