<?php

namespace App\Filament;

use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Actions\CorrigerUneChargeAction;
use App\Domain\Charges\Actions\DeposerUneChargeAction;
use App\Domain\Charges\Actions\GererLesTypesDeChargeAction;
use App\Domain\Charges\Actions\RenvoyerUneChargeAction;
use App\Domain\Charges\Actions\SaisirUneChargeAction;
use App\Domain\Charges\Actions\SupprimerUneChargeAction;
use App\Domain\Charges\Actions\ValiderUneChargeAction;
use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Enums\NatureDeCharge;
use App\Domain\Charges\Enums\TauxDeCharge;
use App\Domain\Charges\Enums\TypeDeCharge;
use App\Domain\Charges\Exceptions\ConfirmationRequiseException;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Models\LigneDeCharge;
use App\Domain\Charges\Models\PieceDeCharge;
use App\Domain\Charges\Models\TypeDeChargeDeLaSociete;
use App\Domain\Charges\Services\ChargesDeLaSociete;
use App\Domain\Charges\Services\ChiffrageDUneCharge;
use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\Charges\Services\GestesSurLesCharges;
use App\Domain\Charges\Services\PiecesDesCharges;
use App\Domain\Charges\Services\PropositionsDeTva;
use App\Domain\Charges\Services\TvaDeLaPeriode;
use App\Domain\Charges\Services\TypesDeCharge;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\Charges\Support\MontantsDeTva;
use App\Domain\Charges\Support\SaisieDeCharge;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\SaisieDecimale;
use App\Domain\Fichiers\ControleDesFichiers;
use App\Domain\Fichiers\EnTetesDesFichiers;
use App\Domain\Fichiers\Exceptions\PieceBloqueeException;
use App\Domain\Fichiers\NomDeFichier;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\TableauDeBord\Services\ChargesDuTableauDeBord;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\PorteUnJeton;
use App\Http\Controllers\TelechargerUnePieceDuFournisseurController;
use App\Integrations\Antivirus\AntivirusInjoignableException;
use Carbon\CarbonImmutable;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La page « Charges » (modèle C1 et fenêtre C2 validés par l'éditeur le
 * 14/11/2026, lot CHARGES), le même composant dans les quatre écrans qui
 * la montrent : l'espace de la société (le propriétaire, et
 * l'administrateur à qui il coche « Charges : ajouter »), la page d'un
 * client lié dans l'espace du cabinet (son propriétaire et le
 * collaborateur à qui le client est confié), la page de l'expert-comptable
 * déclaré et la page « Charges du magasin » d'un responsable coché, dans
 * le panneau de son magasin. Le droit de chacun (voir DroitsSurLesCharges)
 * se relit à chaque requête ; sans lui, la page répond 403.
 *
 * En haut, la période (une année civile ou un exercice), la vue (toutes,
 * à valider, renvoyées au client) et, quand l'écran montre plusieurs
 * magasins, le magasin ; puis le tableau « type de charge × mois », une
 * case par type et par mois avec le symbole et la couleur de l'état le
 * moins avancé et le nombre de charges, dont un clic filtre la liste ;
 * puis la liste, triée par mois puis par type, 50 par page, avec le geste
 * permis à chacun : « Écrire les chiffres », « Valider », « Renvoyer au
 * client » (avec un mot), « Corriger » (après la validation, avec un
 * motif), « Voir la pièce » (téléchargée en pièce jointe, jamais affichée)
 * et « Supprimer » avant la validation. La fenêtre « Ajouter une charge »
 * dépose la pièce, écrit ses chiffres par taux (la TVA et le TTC se
 * calculent en direct depuis le HT, sans réécrire le champ en cours de
 * frappe), dit si une charge de ce type est déjà saisie pour ce mois, et
 * demande une confirmation pour un écart de TVA ou un doublon probable.
 * La TVA déductible et le mois de TVA proposés suivent en direct les
 * chiffres, le type et les dates, tant que personne ne les a changés ; le
 * client les voit sans les écrire. Les confirmations données dans une
 * fenêtre (écart de TVA, doublon probable) valent toutes jusqu'à sa
 * fermeture. Sous le tableau, la TVA du mois choisi : collectée,
 * déductible des achats et des charges validées, à payer réelle et
 * estimée, telle que le tableau de bord de la personne la montre (voir
 * TvaDeLaPeriode::duPerimetre()), ou, pour qui ne voit pas les graphiques,
 * celle des seules charges.
 *
 * Chaque geste passe par l'action du domaine, avec son jeton de demande
 * répétée. Les charges se lisent hors du scope de la société courante,
 * filtrées par la société de l'accès et ses magasins, limitées aux
 * colonnes que la page montre et que ses gestes demandent (voir
 * ChargesDeLaSociete) ; les gestes s'exécutent dans le contexte de la
 * société de la charge, qu'ils relisent sous son verrou (voir
 * GestesSurLesCharges).
 */
final class PageDesCharges extends Component implements HasActions, HasForms, HasTable
{
    use GereLesExceptionsMetier;
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;
    use PorteUnJeton;

    public const NOM = 'page-des-charges';

    /** L'espace de la société. */
    public const SOCIETE = 'societe';

    /** La page d'un client dans l'espace du cabinet. */
    public const CABINET = 'cabinet';

    /** La page de l'expert-comptable déclaré, dans le panneau des magasins. */
    public const EXPERT_COMPTABLE = 'expert_comptable';

    /** « Charges du magasin », dans le panneau du magasin d'un responsable. */
    public const MAGASIN = 'magasin';

    public const REFUS = 'Vous ne voyez pas les charges de cette société.';

    /** Les mois abrégés de l'en-tête du tableau des mois. */
    private const ABREVIATIONS_DES_MOIS = ['Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];

    public const TOUTE_LA_SOCIETE = 'Toute la société';

    #[Locked]
    public string $ecran = self::SOCIETE;

    #[Locked]
    public ?string $lien = null;

    /** « civile:2026 » ou « exercice:2026 » (l'exercice qui finit en 2026). */
    public string $periode = '';

    /** « toutes », « a_valider » ou « renvoyees », lue aussi dans l'adresse (le lien de l'« À faire »). */
    #[Url]
    public string $vue = 'toutes';

    /** « » pour tous, « societe » pour « Toute la société », ou l'uuid d'un magasin. */
    public string $magasinFiltre = '';

    public ?string $caseType = null;

    public ?string $caseMois = null;

    /** La confirmation que la dernière demande attend (voir ConfirmationRequiseException). */
    public ?string $confirmationDemandee = null;

    public ?string $messageDeConfirmation = null;

    /**
     * Les confirmations déjà données depuis l'ouverture de la fenêtre : un
     * écart de TVA et un doublon probable se confirment chacun une fois.
     *
     * @var list<string>
     */
    #[Locked]
    public array $confirmationsDonnees = [];

    /** Le mois « AAAA-MM » de la TVA montrée sous le tableau, l'un de ceux de la période. */
    public string $moisDeLaTva = '';

    private ?AccesAuxCharges $accesLu = null;

    public function mount(string $ecran = self::SOCIETE, ?string $lien = null): void
    {
        if (! in_array($ecran, [self::SOCIETE, self::CABINET, self::EXPERT_COMPTABLE, self::MAGASIN], true)) {
            throw new AuthorizationException(self::REFUS);
        }

        $this->ecran = $ecran;
        $this->lien = $lien;
        $this->periode = 'civile:'.Calendrier::deParis()->anneeDe(CarbonImmutable::now());
        $this->updatedVue();
        $this->acces();
        $this->moisDeLaTva = $this->moisDeLaTvaParDefaut();
    }

    /**
     * L'accès du compte connecté aux charges de la société de cet écran,
     * relu à chaque requête ; 403 sans lui.
     *
     * @throws AuthorizationException
     */
    public function acces(): AccesAuxCharges
    {
        if ($this->accesLu !== null) {
            return $this->accesLu;
        }

        $compte = CompteConnecte::exiger();
        $tenant = Filament::getTenant();
        $societe = match ($this->ecran) {
            self::SOCIETE => $tenant instanceof Societe ? $tenant : null,
            self::MAGASIN => $tenant instanceof Magasin && $compte->estMembre() && $compte->estResponsableDe($tenant) ? $tenant->societe : null,
            self::EXPERT_COMPTABLE => $tenant instanceof Magasin && $compte->estExpertComptable() && $compte->peutOuvrir($tenant) ? $tenant->societe : null,
            self::CABINET => $this->clientDuLien(),
            default => null,
        };
        $acces = $societe === null ? null : app(DroitsSurLesCharges::class)->pour($compte, $societe);

        if ($acces === null) {
            throw new AuthorizationException(self::REFUS);
        }

        return $this->accesLu = $acces;
    }

    public function render(): View
    {
        return view('filament.page-des-charges', [
            'tableau' => app(ChargesDeLaSociete::class)->tableauDesMois($this->acces(), $this->mois(), $this->magasinDuFiltre(), $this->magasinFiltre === 'societe'),
            'mois' => $this->mois(),
            'tva' => $this->tvaDuMois(),
        ]);
    }

    public function filtrerLaCase(string $type, string $mois): void
    {
        $this->caseType = $type;
        $this->caseMois = in_array($mois, $this->mois(), true) ? $mois : null;
        $this->resetTable();
    }

    public function oublierLaCase(): void
    {
        $this->caseType = null;
        $this->caseMois = null;
        $this->resetTable();
    }

    public function updatedPeriode(): void
    {
        if (! array_key_exists($this->periode, $this->periodes())) {
            $this->periode = array_key_first($this->periodes()) ?? '';
        }

        $this->moisDeLaTva = $this->moisDeLaTvaParDefaut();
        $this->oublierLaCase();
    }

    public function updatedMoisDeLaTva(): void
    {
        if (! in_array($this->moisDeLaTva, $this->mois(), true)) {
            $this->moisDeLaTva = $this->moisDeLaTvaParDefaut();
        }
    }

    /**
     * La TVA du mois choisi, sur le magasin du filtre : telle que le
     * tableau de bord de la personne la montre, ventes et achats compris
     * (voir TvaDeLaPeriode::duPerimetre()) ; pour qui ne voit pas les
     * graphiques de cet écran, celle des seules charges qu'il voit, sans
     * les ventes ni les achats ; null dans une société en franchise en base
     * (TVA non applicable).
     *
     * @return array{mois: string, montants: MontantsDeTva, avec_les_ventes: bool, sans_celles_de_la_societe: bool}|null
     */
    public function tvaDuMois(): ?array
    {
        $acces = $this->acces();

        if ($acces->societe->estEnFranchiseDeTva()) {
            return null;
        }

        $mois = in_array($this->moisDeLaTva, $this->mois(), true) ? $this->moisDeLaTva : $this->moisDeLaTvaParDefaut();
        $perimetre = $this->perimetreDeLaTva();

        if ($perimetre !== null) {
            $montants = app(TvaDeLaPeriode::class)->duPerimetre($perimetre, $mois);

            return $montants === null ? null : ['mois' => $mois, 'montants' => $montants, 'avec_les_ventes' => true, 'sans_celles_de_la_societe' => $perimetre->laisseLesChargesDeLaSociete(TvaDeLaPeriode::magasinsComptes($perimetre))];
        }

        $magasin = $this->magasinDuFiltre();
        $magasins = match (true) {
            $magasin !== null => [$magasin],
            $this->magasinFiltre === 'societe' => [],
            default => CleDuModele::desModeles($this->magasinsVus()),
        };
        $deLaSociete = $acces->chargesDeLaSociete && $magasin === null;
        $charges = ChargesDuTableauDeBord::lire($acces->societe, [$mois], $magasins, $deLaSociete);

        return ['mois' => $mois, 'montants' => TvaDeLaPeriode::parMois([$mois], $magasins, [], [], $charges, $deLaSociete)[$mois], 'avec_les_ventes' => false, 'sans_celles_de_la_societe' => false];
    }

    public function updatedVue(): void
    {
        $this->vue = in_array($this->vue, ['toutes', 'a_valider', 'renvoyees'], true) ? $this->vue : 'toutes';
        $this->resetTable();
    }

    public function updatedMagasinFiltre(): void
    {
        if (! array_key_exists($this->magasinFiltre, $this->magasinsDuFiltre())) {
            $this->magasinFiltre = '';
        }

        $this->oublierLaCase();
    }

    /**
     * Les périodes proposées : l'année civile en cours et la précédente,
     * puis, quand l'exercice de la société ne finit pas en décembre,
     * l'exercice en cours et le précédent.
     *
     * @return array<string, string>
     */
    public function periodes(): array
    {
        $aujourdhui = Calendrier::deParis()->aujourdhui();
        $annee = (int) Calendrier::afficherJour($aujourdhui, 'Y');
        $periodes = ['civile:'.$annee => 'Année '.$annee, 'civile:'.($annee - 1) => 'Année '.($annee - 1)];
        $fin = (int) $this->acces()->societe->mois_fin_exercice;

        if ($fin !== 12) {
            $finEnCours = (int) Calendrier::afficherJour($aujourdhui, 'n') <= $fin ? $annee : $annee + 1;

            foreach ([$finEnCours, $finEnCours - 1] as $finAnnee) {
                $mois = self::moisDeLExercice($finAnnee, $fin);
                $periodes['exercice:'.$finAnnee] = 'Exercice de '.self::nomDuMois($mois[0]).' à '.self::nomDuMois($mois[11]);
            }
        }

        return $periodes;
    }

    /**
     * Les douze mois « AAAA-MM » de la période choisie.
     *
     * @return list<string>
     */
    public function mois(): array
    {
        [$sorte, $annee] = explode(':', $this->periode) + ['', ''];
        $annee = ctype_digit($annee) ? (int) $annee : (int) Calendrier::deParis()->anneeDe(CarbonImmutable::now());

        return $sorte === 'exercice'
            ? self::moisDeLExercice($annee, (int) $this->acces()->societe->mois_fin_exercice)
            : array_map(fn (int $mois): string => sprintf('%04d-%02d', $annee, $mois), range(1, 12));
    }

    /**
     * Les choix du filtre de magasin, dans un écran qui en montre
     * plusieurs : tous, « Toute la société », puis chaque magasin que
     * l'accès voit.
     *
     * @return array<string, string>
     */
    public function magasinsDuFiltre(): array
    {
        if ($this->ecran === self::MAGASIN) {
            return ['' => 'Mon magasin'];
        }

        $choix = ['' => 'Toute la société et tous les magasins'];

        if ($this->acces()->chargesDeLaSociete) {
            $choix['societe'] = self::TOUTE_LA_SOCIETE.' seulement';
        }

        foreach ($this->magasinsVus() as $magasin) {
            $choix[(string) $magasin->uuid] = (string) $magasin->nom;
        }

        return $choix;
    }

    /**
     * L'état d'une charge dans la liste : la même pastille colorée que sa
     * case du tableau des mois (modèle C1, resources/css/charges.css),
     * suivie de son libellé.
     */
    public static function pastilleDeLEtat(EtatDeLaCharge $etat): HtmlString
    {
        return new HtmlString('<span class="piliqi-charges__etat" data-etat-de-la-charge="'.e($etat->value).'"><span class="piliqi-charges__case piliqi-charges__case--'.e($etat->value).'" aria-hidden="true">'.e($etat->symbole()).'</span>'.e($etat->getLabel()).'</span>');
    }

    /**
     * Le mois « AAAA-MM » en toutes lettres (« mars 2026 »), tel quel s'il
     * n'est pas un mois.
     */
    public static function nomDuMois(string $mois): string
    {
        return preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $mois, $parties) === 1
            ? mb_strtolower(Calendrier::nomDuMois((int) $parties[2])).' '.$parties[1]
            : $mois;
    }

    /**
     * L'abréviation du mois « AAAA-MM » dans l'en-tête du tableau des mois
     * (« Janv. », « Févr. »…), tel quel s'il n'est pas un mois.
     */
    public static function abregeDuMois(string $mois): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $mois, $parties) === 1
            ? self::ABREVIATIONS_DES_MOIS[(int) $parties[1] - 1]
            : $mois;
    }

    public static function enEuros(int $centimes): string
    {
        return app(CalculateurLigne::class)->enEuros($centimes);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->requeteDeLaListe())
            ->columns([
                TextColumn::make('mois_tva')->label('Mois de TVA')->formatStateUsing(fn (string $state): string => ucfirst(self::nomDuMois($state))),
                TextColumn::make('type')->label('Type')->state(fn (Charge $record): string => $record->type?->getLabel() ?? ValeursLues::texteOuNul($record->getAttributes()['type_nom'] ?? null) ?? ''),
                TextColumn::make('magasin_nom')->label('Magasin')->placeholder(self::TOUTE_LA_SOCIETE)->visible($this->ecran !== self::MAGASIN),
                TextColumn::make('fournisseur')->label('Fournisseur')->placeholder('—'),
                TextColumn::make('numero_piece')->label('N° de la pièce')->placeholder('—'),
                TextColumn::make('total_ht')->label('HT')->alignEnd()->state(fn (Charge $record): string => self::enEuros($record->total_ht)),
                TextColumn::make('total_tva')->label('TVA')->alignEnd()->state(fn (Charge $record): string => self::enEuros($record->total_tva)),
                TextColumn::make('total_ttc')->label('TTC')->alignEnd()->state(fn (Charge $record): string => self::enEuros($record->total_ttc)),
                TextColumn::make('total_tva_deductible')->label('TVA déductible')->alignEnd()->state(fn (Charge $record): string => self::enEuros($record->total_tva_deductible))
                    ->description(fn (Charge $record): ?string => $record->tva_a_verifier ? 'TVA à vérifier' : null),
                TextColumn::make('origine')->label('Origine')->formatStateUsing(fn (Charge $record): string => $record->origine->getLabel()),
                TextColumn::make('etat')->label('État')
                    ->formatStateUsing(fn (Charge $record): HtmlString => self::pastilleDeLEtat($record->etat))
                    ->description(fn (Charge $record): ?string => match ($record->etat) {
                        EtatDeLaCharge::Validee => $record->validee_par?->getLabel(),
                        EtatDeLaCharge::Renvoyee => 'Mot du comptable : '.$record->motif_renvoi,
                        default => null,
                    }),
            ])
            ->paginated([50])
            ->defaultPaginationPageOption(50)
            ->headerActions([$this->ajouterUneCharge(), $this->typesDeCharge(), $this->ajouterUnType(), $this->supprimerUnType()])
            ->actions([
                $this->ecrireLesChiffres(),
                $this->valider(),
                $this->renvoyer(),
                $this->corriger(),
                $this->voirLaPiece(),
                $this->supprimer(),
            ])
            ->emptyStateHeading('Aucune charge sur cette période')
            ->emptyStateDescription('Ajoutez une charge par « Ajouter une charge » : sa pièce, puis ses chiffres.');
    }

    /**
     * La pièce de cette charge, servie en pièce jointe, jamais affichée
     * dans la page (comme les pièces du fournisseur), avec les seuls
     * en-têtes de toute pièce reçue (EnTetesDesFichiers::fichierRecu(), son
     * nom en pièce jointe compris) ; refusée quand elle est bloquée par
     * l'antivirus. PiecesDesCharges::contenuServable() pose lui-même la
     * société de la charge pour l'analyse et le blocage, même depuis
     * l'espace d'un cabinet lié.
     */
    public function telechargerLaPiece(string $charge): ?StreamedResponse
    {
        $trouvee = app(ChargesDeLaSociete::class)->trouver($this->acces(), $charge);
        $piece = $trouvee === null ? null : PieceDeCharge::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $trouvee->societe_id)->where('charge_id', $trouvee->getKey())->first();

        if ($piece === null) {
            return $this->refuserLeTelechargement('Cette charge n\'a pas de pièce.');
        }

        try {
            $contenu = app(PiecesDesCharges::class)->contenuServable(CompteConnecte::exiger(), $piece);
        } catch (PieceBloqueeException $bloquee) {
            return $this->refuserLeTelechargement($bloquee->getMessage());
        } catch (AntivirusInjoignableException $injoignable) {
            report($injoignable);

            return $this->refuserLeTelechargement(TelechargerUnePieceDuFournisseurController::ANTIVIRUS_INJOIGNABLE);
        }

        return response()->streamDownload(function () use ($contenu): void {
            echo $contenu;
        }, null, EnTetesDesFichiers::fichierRecu($piece->nom, $piece->type_mime));
    }

    /**
     * La liste : les charges que l'accès voit sur les mois de la période,
     * selon la vue (« À valider » : les charges à valider et les pièces sans
     * chiffres), le magasin et la case choisis, limitées aux colonnes que la
     * liste et ses gestes demandent, avec le nom de leur magasin, de leur
     * type de la société et leur pièce, lus dans la même requête, chacun
     * filtré par la société de la charge.
     *
     * @return Builder<Charge>
     */
    private function requeteDeLaListe(): Builder
    {
        $magasin = $this->magasinDuFiltre();

        return app(ChargesDeLaSociete::class)->visibles($this->acces())
            ->select(ChargesDeLaSociete::COLONNES_DE_LA_LISTE)
            ->addSelect([
                'magasin_nom' => Magasin::query()->select('nom')->whereColumn('magasins.id', 'charges.magasin_id')->whereColumn('magasins.societe_id', 'charges.societe_id')->limit(1),
                'type_nom' => TypeDeChargeDeLaSociete::query()->withoutGlobalScope(SocieteScope::class)->select('nom')
                    ->whereColumn('types_de_charge_de_la_societe.id', 'charges.type_de_la_societe_id')->whereColumn('types_de_charge_de_la_societe.societe_id', 'charges.societe_id')->limit(1),
                'type_uuid' => TypeDeChargeDeLaSociete::query()->withoutGlobalScope(SocieteScope::class)->select('uuid')
                    ->whereColumn('types_de_charge_de_la_societe.id', 'charges.type_de_la_societe_id')->whereColumn('types_de_charge_de_la_societe.societe_id', 'charges.societe_id')->limit(1),
                'piece_nom' => PieceDeCharge::query()->withoutGlobalScope(SocieteScope::class)->select('nom')
                    ->whereColumn('pieces_de_charge.charge_id', 'charges.id')->whereColumn('pieces_de_charge.societe_id', 'charges.societe_id')->whereNull('bloquee_le')->limit(1),
            ])
            ->whereIn('charges.mois_tva', $this->mois())
            ->when($this->vue === 'a_valider', fn (Builder $requete): Builder => $requete->whereIn('charges.etat', EtatDeLaCharge::attendantLaValidation()))
            ->when($this->vue === 'renvoyees', fn (Builder $requete): Builder => $requete->where('charges.etat', EtatDeLaCharge::Renvoyee->value))
            ->when($this->magasinFiltre === 'societe', fn (Builder $requete): Builder => $requete->whereNull('charges.magasin_id'))
            ->when($magasin !== null, fn (Builder $requete): Builder => $requete->where('charges.magasin_id', $magasin))
            ->when($this->caseType !== null && $this->caseMois !== null, fn (Builder $requete): Builder => $this->filtrerParLeType($requete->where('charges.mois_tva', $this->caseMois), (string) $this->caseType))
            ->orderBy('charges.mois_tva')
            ->orderBy('charges.type')
            ->orderBy('charges.type_de_la_societe_id')
            ->orderBy('charges.id');
    }

    /**
     * @param  Builder<Charge>  $requete
     * @return Builder<Charge>
     */
    private function filtrerParLeType(Builder $requete, string $cle): Builder
    {
        if (str_starts_with($cle, TypesDeCharge::PILIQI)) {
            return $requete->where('charges.type', substr($cle, strlen(TypesDeCharge::PILIQI)));
        }

        $uuid = substr($cle, strlen(TypesDeCharge::SOCIETE));

        return $requete->whereIn('charges.type_de_la_societe_id', TypeDeChargeDeLaSociete::query()->withoutGlobalScope(SocieteScope::class)
            ->select('id')->where('societe_id', $this->acces()->societe->getKey())->where('uuid', Str::isUuid($uuid) ? $uuid : null));
    }

    private function ajouterUneCharge(): Action
    {
        return Action::make('ajouterUneCharge')
            ->label('Ajouter une charge')
            ->icon('heroicon-o-plus')
            ->modalHeading('Ajouter une charge')
            ->modalWidth('4xl')
            ->form(fn (): array => $this->champsDeLaCharge(avecLaPiece: true))
            ->fillForm(fn (): array => $this->fenetreOuverte(['magasin' => $this->ecran === self::MAGASIN ? $this->premierMagasin() : '', 'lignes' => [['taux' => (string) TauxDeCharge::Vingt->value]]]))
            ->modalSubmitActionLabel(fn (): string => match (true) {
                $this->acces()->estComptable() => 'Enregistrer (validée)',
                $this->acces()->valide() => 'Enregistrer (à valider)',
                default => 'Enregistrer (à valider par le comptable)',
            })
            ->visible(fn (): bool => ! GestesSurLesCharges::touchePlusASesCharges($this->acces()->societe))
            ->action(function (array $data): void {
                $this->executerAvecConfirmation(ValeursLues::champs($data), function (SaisieDeCharge $saisie, array $data): void {
                    app(DeposerUneChargeAction::class)->handle(CompteConnecte::exiger(), $this->acces()->societe, $saisie, self::pieceDeposee($data), ValeursLues::texteOuNul($data['jeton'] ?? null));
                    Notification::make()->title('Charge enregistrée.')->success()->send();
                });
            });
    }

    private function ecrireLesChiffres(): Action
    {
        return Action::make('ecrireLesChiffres')
            ->label('Écrire les chiffres')
            ->icon('heroicon-o-pencil-square')
            ->modalHeading('Écrire les chiffres de la charge')
            ->modalWidth('4xl')
            ->form(fn (): array => $this->champsDeLaCharge(avecLaPiece: true))
            ->fillForm(fn (Charge $record): array => $this->fenetreOuverte($this->donneesDe($record)))
            ->modalSubmitActionLabel(fn (): string => match (true) {
                $this->acces()->estComptable() => 'Enregistrer et valider',
                $this->acces()->valide() => 'Enregistrer (à valider)',
                default => 'Enregistrer (à valider par le comptable)',
            })
            ->visible(fn (Charge $record): bool => ! $record->estValidee() && ! GestesSurLesCharges::touchePlusASesCharges($this->acces()->societe))
            ->action(function (Charge $record, array $data): void {
                $this->executerAvecConfirmation(ValeursLues::champs($data), function (SaisieDeCharge $saisie, array $data) use ($record): void {
                    app(SaisirUneChargeAction::class)->handle(CompteConnecte::exiger(), $this->acces()->societe, (string) $record->uuid, $saisie, self::pieceDeposee($data), ValeursLues::texteOuNul($data['jeton'] ?? null));
                    Notification::make()->title('Chiffres enregistrés.')->success()->send();
                });
            });
    }

    private function corriger(): Action
    {
        return Action::make('corriger')
            ->label('Corriger')
            ->icon('heroicon-o-wrench')
            ->modalHeading('Corriger une charge validée')
            ->modalDescription('La charge et sa pièce restent ; la correction et son motif s\'inscrivent au journal d\'audit.')
            ->modalWidth('4xl')
            ->form(fn (): array => [...$this->champsDeLaCharge(avecLaPiece: false), Textarea::make('motif')->label('Motif de la correction')->required()->maxLength(500)])
            ->fillForm(fn (Charge $record): array => $this->fenetreOuverte($this->donneesDe($record)))
            ->modalSubmitActionLabel('Corriger')
            ->visible(fn (Charge $record): bool => $record->estValidee() && $this->acces()->valide() && ! GestesSurLesCharges::touchePlusASesCharges($this->acces()->societe))
            ->action(function (Charge $record, array $data): void {
                $this->executerAvecConfirmation(ValeursLues::champs($data), function (SaisieDeCharge $saisie, array $data) use ($record): void {
                    app(CorrigerUneChargeAction::class)->handle(CompteConnecte::exiger(), $this->acces()->societe, (string) $record->uuid, $saisie, ValeursLues::texte($data['motif'] ?? ''), ValeursLues::texteOuNul($data['jeton'] ?? null));
                    Notification::make()->title('Charge corrigée.')->success()->send();
                });
            });
    }

    private function valider(): Action
    {
        return Action::make('valider')
            ->label('Valider')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Valider la charge')
            ->modalDescription('Une fois validée, la charge et sa pièce ne s\'effacent plus ; elle ne se corrige plus que par « Corriger », avec un motif.')
            ->form([self::champDuJeton()])
            ->modalSubmitActionLabel('Valider')
            ->visible(fn (Charge $record): bool => $record->etat === EtatDeLaCharge::AValider && $this->acces()->valide() && ! GestesSurLesCharges::touchePlusASesCharges($this->acces()->societe))
            ->action(function (Charge $record, array $data): void {
                static::executerLeGeste(function () use ($record, $data): void {
                    app(ValiderUneChargeAction::class)->handle(CompteConnecte::exiger(), $this->acces()->societe, (string) $record->uuid, ValeursLues::texteOuNul($data['jeton'] ?? null));
                    Notification::make()->title('Charge validée.')->success()->send();
                });
            });
    }

    private function renvoyer(): Action
    {
        return Action::make('renvoyer')
            ->label('Renvoyer au client')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->modalHeading('Renvoyer la charge au client')
            ->form([
                self::champDuJeton(),
                Textarea::make('mot')->label('Votre mot au client')->helperText('Par exemple : « pièce illisible », « ce n\'est pas la bonne facture ».')->required()->maxLength(500),
            ])
            ->modalSubmitActionLabel('Renvoyer')
            ->visible(fn (Charge $record): bool => in_array($record->etat, [EtatDeLaCharge::AValider, EtatDeLaCharge::PieceSansChiffres], true) && $this->acces()->estComptable() && ! GestesSurLesCharges::touchePlusASesCharges($this->acces()->societe))
            ->action(function (Charge $record, array $data): void {
                static::executerLeGeste(function () use ($record, $data): void {
                    app(RenvoyerUneChargeAction::class)->handle(CompteConnecte::exiger(), $this->acces()->societe, (string) $record->uuid, ValeursLues::texte($data['mot'] ?? ''), ValeursLues::texteOuNul($data['jeton'] ?? null));
                    Notification::make()->title('Charge renvoyée au client.')->success()->send();
                });
            });
    }

    private function supprimer(): Action
    {
        return Action::make('supprimer')
            ->label('Supprimer')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Supprimer la charge')
            ->modalDescription('La charge et sa pièce s\'effacent ; la suppression s\'inscrit au journal d\'audit.')
            ->form([self::champDuJeton()])
            ->modalSubmitActionLabel('Supprimer')
            ->visible(fn (Charge $record): bool => ! $record->estValidee() && ($this->acces()->valide() || $record->deposee_par === CompteConnecte::exiger()->getKey()) && ! GestesSurLesCharges::touchePlusASesCharges($this->acces()->societe))
            ->action(function (Charge $record, array $data): void {
                static::executerLeGeste(function () use ($record, $data): void {
                    app(SupprimerUneChargeAction::class)->handle(CompteConnecte::exiger(), $this->acces()->societe, (string) $record->uuid, ValeursLues::texteOuNul($data['jeton'] ?? null));
                    Notification::make()->title('Charge supprimée.')->success()->send();
                });
            });
    }

    private function voirLaPiece(): Action
    {
        return Action::make('voirLaPiece')
            ->label('Voir la pièce')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (Charge $record): bool => $this->aUnePiece($record))
            ->action(fn (Charge $record): ?StreamedResponse => $this->telechargerLaPiece((string) $record->uuid));
    }

    private function typesDeCharge(): Action
    {
        return Action::make('typesDeCharge')
            ->label('Types de charge')
            ->icon('heroicon-o-tag')
            ->color('gray')
            ->modalHeading('Types de charge')
            ->modalDescription('Les types habituels ont leur ligne dans le tableau même sans charge, pour voir les mois qui manquent. Un type de la société déjà utilisé ne se supprime pas : il s\'archive et n\'est plus proposé.')
            ->form(fn (): array => [
                CheckboxList::make('habituels')->label('Types habituels (liste de PILIQI)')->options(collect(TypeDeCharge::cases())->mapWithKeys(fn (TypeDeCharge $type): array => [$type->value => $type->getLabel()])->all())->columns(3),
                CheckboxList::make('archives')->label('Types de la société archivés')->options($this->typesDeLaSociete()->mapWithKeys(fn (TypeDeChargeDeLaSociete $type): array => [(string) $type->uuid => (string) $type->nom])->all())->columns(2)
                    ->visible($this->typesDeLaSociete()->isNotEmpty()),
            ])
            ->fillForm(fn (): array => [
                'habituels' => array_map(fn (TypeDeCharge $type): string => $type->value, app(TypesDeCharge::class)->habituels($this->acces()->societe)),
                'archives' => $this->typesDeLaSociete()->whereNotNull('archive_le')->map(fn (TypeDeChargeDeLaSociete $type): string => (string) $type->uuid)->values()->all(),
            ])
            ->modalSubmitActionLabel('Enregistrer')
            ->visible(fn (): bool => $this->acces()->gereLesTypes && ! GestesSurLesCharges::touchePlusASesCharges($this->acces()->societe))
            ->action(function (array $data): void {
                static::executerLeGeste(function () use ($data): void {
                    $gestes = app(GererLesTypesDeChargeAction::class);
                    $compte = CompteConnecte::exiger();
                    $habituels = array_values(array_filter(array_map(fn (mixed $valeur): ?TypeDeCharge => TypeDeCharge::tryFrom(ValeursLues::texte($valeur)), ValeursLues::tableau($data['habituels'] ?? []))));
                    $gestes->choisirLesHabituels($compte, $this->acces()->societe, $habituels);
                    $archives = array_map(ValeursLues::texte(...), ValeursLues::tableau($data['archives'] ?? []));

                    foreach ($this->typesDeLaSociete() as $type) {
                        $archive = in_array((string) $type->uuid, $archives, true);

                        if ($archive !== ($type->archive_le !== null)) {
                            $gestes->archiver($compte, $this->acces()->societe, (string) $type->uuid, $archive);
                        }
                    }

                    Notification::make()->title('Types de charge enregistrés.')->success()->send();
                });
            });
    }

    private function ajouterUnType(): Action
    {
        return Action::make('ajouterUnType')
            ->label('Ajouter un type')
            ->icon('heroicon-o-plus-circle')
            ->color('gray')
            ->modalHeading('Ajouter un type de charge')
            ->form([
                TextInput::make('nom')->label('Nom du type')->required()->maxLength(80),
                Select::make('nature')->label('Nature')->options(collect(NatureDeCharge::cases())->mapWithKeys(fn (NatureDeCharge $nature): array => [$nature->value => $nature->getLabel()])->all())->default(NatureDeCharge::Services->value)->required()
                    ->helperText('Biens : la TVA se déduit au mois de la pièce. Services : au mois du paiement, sauf si le fournisseur a opté pour les débits.'),
            ])
            ->modalSubmitActionLabel('Ajouter')
            ->visible(fn (): bool => $this->acces()->gereLesTypes && ! GestesSurLesCharges::touchePlusASesCharges($this->acces()->societe))
            ->action(function (array $data): void {
                static::executerLeGeste(function () use ($data): void {
                    app(GererLesTypesDeChargeAction::class)->ajouter(CompteConnecte::exiger(), $this->acces()->societe, ValeursLues::texte($data['nom'] ?? ''), NatureDeCharge::from(ValeursLues::texte($data['nature'] ?? NatureDeCharge::Services->value)));
                    Notification::make()->title('Type ajouté.')->success()->send();
                });
            });
    }

    /**
     * Supprimer un type de la société qu'aucune charge ne porte (créé par
     * erreur) ; un type porté par une charge ne se supprime pas, il
     * s'archive (voir GererLesTypesDeChargeAction::supprimer()).
     */
    private function supprimerUnType(): Action
    {
        return Action::make('supprimerUnType')
            ->label('Supprimer un type')
            ->icon('heroicon-o-trash')
            ->color('gray')
            ->modalHeading('Supprimer un type de charge')
            ->modalDescription('Seul un type de la société qu\'aucune charge ne porte se supprime ; un type déjà utilisé s\'archive, dans « Types de charge ».')
            ->form(fn (): array => [
                Select::make('type')->label('Type de la société')->options(fn (): array => $this->typesSupprimables())->required(),
            ])
            ->modalSubmitActionLabel('Supprimer')
            ->visible(fn (): bool => $this->acces()->gereLesTypes && ! GestesSurLesCharges::touchePlusASesCharges($this->acces()->societe) && $this->typesSupprimables() !== [])
            ->action(function (array $data): void {
                static::executerLeGeste(function () use ($data): void {
                    app(GererLesTypesDeChargeAction::class)->supprimer(CompteConnecte::exiger(), $this->acces()->societe, ValeursLues::texte($data['type'] ?? ''));
                    Notification::make()->title('Type supprimé.')->success()->send();
                });
            });
    }

    /**
     * Les types de la société qu'aucune charge ne porte, par nom : ceux qui
     * se suppriment.
     *
     * @return array<string, string>
     */
    private function typesSupprimables(): array
    {
        $acces = $this->acces();

        return TypeDeChargeDeLaSociete::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $acces->societe->getKey())
            ->whereNotIn('id', Charge::query()->withoutGlobalScope(SocieteScope::class)->select('type_de_la_societe_id')->where('societe_id', $acces->societe->getKey())->whereNotNull('type_de_la_societe_id'))
            ->orderBy('nom_cle')
            ->get(['uuid', 'nom'])
            ->mapWithKeys(fn (TypeDeChargeDeLaSociete $type): array => [(string) $type->uuid => (string) $type->nom])
            ->all();
    }

    /**
     * Les champs de la fenêtre C2.
     *
     * @return list<\Filament\Forms\Components\Component>
     */
    private function champsDeLaCharge(bool $avecLaPiece): array
    {
        $acces = $this->acces();
        $champs = [self::champDuJeton()];

        if ($avecLaPiece) {
            $champs[] = FileUpload::make('piece')
                ->label('La pièce (PDF, JPEG ou PNG)')
                ->helperText('Facultative pour l\'enregistrer, obligatoire pour la valider : une TVA ne se déduit pas sans pièce. Elle remplace la pièce d\'avant.')
                ->maxSize(ControleDesFichiers::TAILLE_MAXIMALE_EN_OCTETS / 1024)
                ->acceptedFileTypes(array_keys(NomDeFichier::EXTENSIONS))
                ->storeFiles(false)
                ->columnSpanFull();
        }

        return [
            ...$champs,
            Grid::make(3)->schema([
                Select::make('type')->label('Type de charge')->options(app(TypesDeCharge::class)->options($acces->societe))->required()->live()->searchable()
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $old): void {
                        $this->suivreLeMois($get, $set, $this->moisPropose($old, $get('date_piece'), $get('date_paiement')));
                        self::suivreLesDeductibles($get, $set, self::typeDePiliqi($old), (bool) $get('vehicule_utilitaire'));
                    }),
                TextInput::make('fournisseur')->label('Fournisseur')->datalist(fn (): array => $this->fournisseursProposes())->maxLength(120)
                    ->helperText('Texte libre, ou un fournisseur de vos magasins ou de vos charges ; aucune fiche de fournisseur n\'est créée.'),
                TextInput::make('numero_piece')->label('N° de la pièce')->maxLength(60)->helperText('Facultatif pour un ticket.'),
                DatePicker::make('date_piece')->label('Date de la pièce')->native()->required()->live()
                    ->afterStateUpdated(fn (Get $get, Set $set, mixed $old) => $this->suivreLeMois($get, $set, $this->moisPropose($get('type'), $old, $get('date_paiement')))),
                DatePicker::make('date_paiement')->label('Date de paiement')->native()->live()
                    ->afterStateUpdated(fn (Get $get, Set $set, mixed $old) => $this->suivreLeMois($get, $set, $this->moisPropose($get('type'), $get('date_piece'), $old))),
                TextInput::make('mois_tva')->label($acces->valide() ? 'Mois de TVA' : 'Mois de TVA proposé')->type('month')->live()
                    ->helperText(fn (Get $get): string => $this->phraseDuMois($get))
                    ->disabled(! $acces->valide()),
                Select::make('magasin')->label('Magasin concerné')->options(fn (): array => $this->magasinsDeLaSaisie())->selectablePlaceholder(false)
                    ->required($this->ecran === self::MAGASIN || ! $acces->chargesDeLaSociete),
                Checkbox::make('fournisseur_hors_france')->label('Fournisseur établi hors de France')->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculerLesLignes($get, $set))
                    ->helperText('Autoliquidation : la ligne se saisit HT, sa TVA est à la fois due et déductible, sans effet sur la TVA à payer.'),
                Checkbox::make('vehicule_utilitaire')->label('Véhicule utilitaire (100 %)')->live()
                    ->afterStateUpdated(fn (Get $get, Set $set, mixed $old) => self::suivreLesDeductibles($get, $set, self::typeDePiliqi($get('type')), (bool) $old))
                    ->helperText('Sinon véhicule de tourisme (80 % de la TVA déductible).')
                    ->visible(fn (Get $get): bool => $get('type') === TypesDeCharge::PILIQI.TypeDeCharge::Carburant->value),
            ]),
            Placeholder::make('deja_saisies')->hiddenLabel()->columnSpanFull()
                ->content(fn (Get $get, ?Charge $record): ?HtmlString => $this->avertissementDesDoublons($get, $record))
                ->visible(fn (Get $get, ?Charge $record): bool => $this->avertissementDesDoublons($get, $record) !== null),
            Repeater::make('lignes')->label('Une ligne par taux')
                ->schema([
                    Select::make('taux')->label('Taux')->options(collect(TauxDeCharge::cases())->mapWithKeys(fn (TauxDeCharge $taux): array => [(string) $taux->value => $taux->getLabel()])->all())->required()->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::calculerDepuisLeHt($get, $set)),
                    TextInput::make('ht')->label('HT')->inputMode('decimal')->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::calculerDepuisLeHt($get, $set)),
                    TextInput::make('tva')->label('TVA')->inputMode('decimal')->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set, mixed $old): void {
                            $ht = self::centimes($get('ht'));
                            $tva = self::centimes($get('tva'));

                            if ($ht !== null && $tva !== null) {
                                $set('ttc', self::enSaisie(ChiffrageDUneCharge::ttcCalcule($ht, $tva, (bool) $get('../../fournisseur_hors_france'))));
                            }

                            self::suivreLaDeductible($get, $set, '', self::centimes($old), $tva, self::typeDePiliqi($get('../../type')), (bool) $get('../../vehicule_utilitaire'), self::typeDePiliqi($get('../../type')), (bool) $get('../../vehicule_utilitaire'));
                        }),
                    TextInput::make('ttc')->label('TTC')->inputMode('decimal'),
                    TextInput::make('tva_deductible')->label($acces->valide() ? 'TVA déductible' : 'TVA déductible proposée')->inputMode('decimal')
                        ->helperText(fn (Get $get): ?string => PropositionsDeTva::raisonDeLaDeduction(self::typeDePiliqi($get('../../type')), (bool) $get('../../vehicule_utilitaire')))
                        ->disabled(! $acces->valide()),
                ])
                ->columns(5)
                ->addActionLabel('Ajouter un autre taux')
                ->defaultItems(0)
                ->reorderable(false)
                ->columnSpanFull(),
            Textarea::make('note')->label('Note')->maxLength(1000)->rows(2)->columnSpanFull(),
            Checkbox::make('confirmer')->label(fn (): string => (string) $this->messageDeConfirmation)->columnSpanFull()
                ->visible(fn (): bool => $this->confirmationDemandee !== null),
        ];
    }

    /**
     * Enregistre la saisie de la fenêtre ; quand l'action demande une
     * confirmation (un écart de TVA au-delà d'un centime, un doublon
     * probable), la fenêtre reste ouverte et montre la case, décochée, qui
     * la donne ; chaque confirmation donnée vaut jusqu'à la fermeture de la
     * fenêtre, et l'envoi suivant les passe toutes.
     *
     * @param  array<string, mixed>  $data
     * @param  \Closure(SaisieDeCharge, array<string, mixed>): void  $geste
     */
    private function executerAvecConfirmation(array $data, \Closure $geste): void
    {
        if ((bool) ($data['confirmer'] ?? false) && $this->confirmationDemandee !== null && ! in_array($this->confirmationDemandee, $this->confirmationsDonnees, true)) {
            $this->confirmationsDonnees[] = $this->confirmationDemandee;
        }

        try {
            $saisie = SaisieDeCharge::duFormulaire(
                [...$data, 'magasin' => self::saisi($data['magasin'] ?? null)],
                confirmeLesEcarts: in_array(ConfirmationRequiseException::ECARTS, $this->confirmationsDonnees, true),
                confirmeLeDoublon: in_array(ConfirmationRequiseException::DOUBLON, $this->confirmationsDonnees, true),
            );
            $geste($saisie, $data);
            // Le fichier envoyé ne s'efface qu'une fois la charge enregistrée :
            // après une confirmation demandée ou un refus, la fenêtre reste
            // ouverte et l'envoi suivant le reprend.
            PieceDeposee::effacerLesEnvois($data['piece'] ?? []);
            $this->confirmationDemandee = null;
            $this->confirmationsDonnees = [];
            $this->accesLu = null;
        } catch (ConfirmationRequiseException $demande) {
            // Une nouvelle confirmation se donne pour elle-même : sa case se
            // décoche, celles d'avant restent données.
            $this->confirmationDemandee = $demande->motif;
            $this->messageDeConfirmation = $demande->getMessage();
            $rang = array_key_last($this->mountedTableActionsData ?? []);

            if ($rang !== null) {
                $this->mountedTableActionsData[$rang]['confirmer'] = false;
            }

            Notification::make()->title($demande->getMessage())->warning()->send();

            throw new Halt;
        } catch (InvalidArgumentException|AuthorizationException|\LogicException $refus) {
            self::notifierLeRefus($refus);

            throw new Halt;
        }
    }

    /**
     * La phrase grise du mois de TVA : « à confirmer au paiement » pour une
     * charge de services sans date de paiement.
     */
    private function phraseDuMois(Get $get): string
    {
        $date = self::saisi($get('date_piece'));
        $type = self::saisi($get('type'));

        if ($date === null || $type === null) {
            return 'Calculé par défaut depuis les dates ; le comptable fixe le mois définitif.';
        }

        $nature = $this->natureDuType($type);
        $propose = PropositionsDeTva::moisDeTva($nature, $date, self::saisi($get('date_paiement')));

        return 'Proposé : '.self::nomDuMois($propose).(PropositionsDeTva::aConfirmerAuPaiement($nature, self::saisi($get('date_paiement'))) ? '. '.PropositionsDeTva::A_CONFIRMER_AU_PAIEMENT : '.');
    }

    /**
     * L'avertissement des charges du même type déjà saisies pour ce mois
     * (modèle C2), dès que le type et la date de la pièce sont choisis.
     */
    private function avertissementDesDoublons(Get $get, ?Charge $record): ?HtmlString
    {
        $type = self::saisi($get('type'));
        $date = self::saisi($get('date_piece'));

        if ($type === null || $date === null || Calendrier::jourValide($date) === null) {
            return null;
        }

        $mois = self::saisi($get('mois_tva')) ?? PropositionsDeTva::moisDeTva($this->natureDuType($type), $date, self::saisi($get('date_paiement')));

        try {
            $deja = app(ChargesDeLaSociete::class)->dejaSaisiesCeMois($this->acces(), $type, $mois, $record === null ? null : CleDuModele::de($record));
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($deja === []) {
            return null;
        }

        $lignes = array_map(fn (array $charge): string => '<li>'.e($charge['etat']->symbole().' '.$charge['etat']->getLabel()).($charge['fournisseur'] !== null ? ' — '.e($charge['fournisseur']) : '').($charge['numero_piece'] !== null ? ', pièce n° '.e($charge['numero_piece']) : '').'</li>', $deja);

        return new HtmlString('<div data-doublon-de-charge class="piliqi-charges__avertissement"><p class="font-medium">'.e(count($deja) > 1 ? count($deja).' charges de ce type sont déjà saisies pour '.self::nomDuMois($mois).' :' : 'Une charge de ce type est déjà saisie pour '.self::nomDuMois($mois).' :').'</p><ul>'.implode('', $lignes).'</ul></div>');
    }

    private function natureDuType(string $cle): NatureDeCharge
    {
        try {
            return TypesDeCharge::natureDe(app(TypesDeCharge::class)->resoudre($this->acces()->societe, $cle));
        } catch (InvalidArgumentException) {
            return NatureDeCharge::Services;
        }
    }

    /**
     * La TVA et le TTC de la ligne, calculés en direct depuis son HT, sans
     * réécrire le HT en cours de frappe.
     */
    private static function calculerDepuisLeHt(Get $get, Set $set): void
    {
        $ht = self::centimes($get('ht'));
        $taux = TauxDeCharge::tryFrom((int) self::saisi($get('taux')));

        if ($ht === null || $taux === null) {
            return;
        }

        $tvaAvant = self::centimes($get('tva'));
        $tva = ChiffrageDUneCharge::tvaCalculee($ht, $taux);
        $set('tva', self::enSaisie($tva));
        $set('ttc', self::enSaisie(ChiffrageDUneCharge::ttcCalcule($ht, $tva, (bool) $get('../../fournisseur_hors_france'))));
        $type = self::typeDePiliqi($get('../../type'));
        $vehicule = (bool) $get('../../vehicule_utilitaire');
        self::suivreLaDeductible($get, $set, '', $tvaAvant, $tva, $type, $vehicule, $type, $vehicule);
    }

    /**
     * La case « Fournisseur établi hors de France » change : la TVA et le
     * TTC de chaque ligne qui a un HT se recalculent (le TTC d'une ligne
     * autoliquidée est son HT).
     */
    private static function recalculerLesLignes(Get $get, Set $set): void
    {
        foreach (ValeursLues::tableau($get('lignes') ?? []) as $cle => $ligne) {
            $ligne = ValeursLues::tableau($ligne);
            $ht = self::centimes($ligne['ht'] ?? null);
            $taux = TauxDeCharge::tryFrom((int) self::saisi($ligne['taux'] ?? null));

            if ($ht !== null && $taux !== null) {
                $tva = ChiffrageDUneCharge::tvaCalculee($ht, $taux);
                $set('lignes.'.$cle.'.tva', self::enSaisie($tva));
                $set('lignes.'.$cle.'.ttc', self::enSaisie(ChiffrageDUneCharge::ttcCalcule($ht, $tva, (bool) $get('fournisseur_hors_france'))));
                $type = self::typeDePiliqi($get('type'));
                $vehicule = (bool) $get('vehicule_utilitaire');
                self::suivreLaDeductible($get, $set, 'lignes.'.$cle.'.', self::centimes($ligne['tva'] ?? null), $tva, $type, $vehicule, $type, $vehicule);
            }
        }
    }

    /**
     * Le type ou le véhicule du carburant change : la TVA déductible de
     * chaque ligne suit la nouvelle proposition, tant qu'elle valait celle
     * d'avant.
     */
    private static function suivreLesDeductibles(Get $get, Set $set, ?TypeDeCharge $typeAvant, bool $vehiculeAvant): void
    {
        $type = self::typeDePiliqi($get('type'));
        $vehicule = (bool) $get('vehicule_utilitaire');

        foreach (array_keys(ValeursLues::tableau($get('lignes') ?? [])) as $cle) {
            $tva = self::centimes($get('lignes.'.$cle.'.tva'));
            self::suivreLaDeductible($get, $set, 'lignes.'.$cle.'.', $tva, $tva, $typeAvant, $vehiculeAvant, $type, $vehicule);
        }
    }

    /**
     * La TVA déductible de la ligne de ce chemin suit, en direct, ce que
     * PILIQI propose (voir PropositionsDeTva) quand sa TVA, son type ou le
     * véhicule changent, tant qu'elle est vide ou vaut la proposition
     * d'avant : celle qu'une personne a écrite elle-même ne se réécrit
     * jamais.
     */
    private static function suivreLaDeductible(Get $get, Set $set, string $chemin, ?int $tvaAvant, ?int $tva, ?TypeDeCharge $typeAvant, bool $vehiculeAvant, ?TypeDeCharge $type, bool $vehicule): void
    {
        $actuelle = self::centimes($get($chemin.'tva_deductible'));

        if ($actuelle !== null && ($tvaAvant === null || $actuelle !== PropositionsDeTva::tvaDeductible($typeAvant, $vehiculeAvant, $tvaAvant))) {
            return;
        }

        $set($chemin.'tva_deductible', $tva === null ? null : self::enSaisie(PropositionsDeTva::tvaDeductible($type, $vehicule, $tva)));
    }

    /**
     * Le mois de TVA suit, en direct, celui que PILIQI propose quand le
     * type ou une date change, tant qu'il est vide ou vaut la proposition
     * d'avant : celui que le comptable a choisi lui-même ne se réécrit
     * jamais.
     */
    private function suivreLeMois(Get $get, Set $set, ?string $proposeAvant): void
    {
        $actuel = self::saisi($get('mois_tva'));
        $propose = $this->moisPropose($get('type'), $get('date_piece'), $get('date_paiement'));

        if ($propose === null || ($actuel !== null && $actuel !== $proposeAvant)) {
            return;
        }

        $set('mois_tva', $propose);
    }

    /**
     * Le mois de TVA que PILIQI propose pour ce type et ces dates ; null
     * sans type ou sans date de pièce valable.
     */
    private function moisPropose(mixed $type, mixed $datePiece, mixed $datePaiement): ?string
    {
        $type = self::saisi($type);
        $date = self::saisi($datePiece);

        if ($type === null || $date === null || Calendrier::jourValide($date) === null) {
            return null;
        }

        $paiement = self::saisi($datePaiement);

        return PropositionsDeTva::moisDeTva($this->natureDuType($type), $date, $paiement !== null && Calendrier::jourValide($paiement) !== null ? $paiement : null);
    }

    private static function centimes(mixed $saisie): ?int
    {
        $texte = self::saisi(is_int($saisie) ? (string) $saisie : $saisie);

        if ($texte === null) {
            return null;
        }

        try {
            return SaisieDecimale::montantAuCentime(str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], $texte));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Un champ saisi : son texte, ou null quand il est vide ou n'est pas un
     * texte.
     */
    private static function saisi(mixed $valeur): ?string
    {
        return is_string($valeur) && trim($valeur) !== '' ? trim($valeur) : null;
    }

    private static function enSaisie(int $centimes): string
    {
        return number_format($centimes / 100, 2, ',', '');
    }

    private static function typeDePiliqi(mixed $cle): ?TypeDeCharge
    {
        $texte = is_string($cle) ? $cle : '';

        return str_starts_with($texte, TypesDeCharge::PILIQI) ? TypeDeCharge::tryFrom(substr($texte, strlen(TypesDeCharge::PILIQI))) : null;
    }

    /**
     * La saisie d'une charge enregistrée, pour remplir la fenêtre : ses
     * lignes de la version en cours, lues dans sa société.
     *
     * @return array<string, mixed>
     */
    private function donneesDe(Charge $charge): array
    {
        $lignes = LigneDeCharge::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $charge->societe_id)->where('charge_id', $charge->getKey())->where('version', $charge->corrections)
            ->orderBy('rang')->get();

        return [
            'type' => $charge->type !== null ? TypesDeCharge::PILIQI.$charge->type->value : TypesDeCharge::SOCIETE.ValeursLues::texte(TypeDeChargeDeLaSociete::query()->withoutGlobalScope(SocieteScope::class)->where('societe_id', $charge->societe_id)->whereKey($charge->type_de_la_societe_id)->value('uuid')),
            'fournisseur' => $charge->fournisseur,
            'numero_piece' => $charge->numero_piece,
            'date_piece' => $charge->date_piece,
            'date_paiement' => $charge->date_paiement,
            'mois_tva' => $charge->mois_tva,
            'magasin' => $charge->magasin_id === null ? '' : ValeursLues::texte(Magasin::query()->whereKey($charge->magasin_id)->where('societe_id', $charge->societe_id)->value('uuid')),
            'fournisseur_hors_france' => $charge->fournisseur_hors_france,
            'vehicule_utilitaire' => (bool) $charge->vehicule_utilitaire,
            'note' => $charge->note,
            'lignes' => $lignes->map(fn (LigneDeCharge $ligne): array => [
                'taux' => (string) $ligne->taux->value,
                'ht' => self::enSaisie((int) $ligne->ht),
                'tva' => self::enSaisie((int) $ligne->tva),
                'ttc' => self::enSaisie((int) $ligne->ttc),
                'tva_deductible' => self::enSaisie((int) $ligne->tva_deductible),
            ])->all(),
        ];
    }

    /**
     * Une fenêtre s'ouvre : elle n'attend encore aucune confirmation.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    private function fenetreOuverte(array $donnees): array
    {
        $this->confirmationDemandee = null;
        $this->messageDeConfirmation = null;
        $this->confirmationsDonnees = [];

        return $donnees;
    }

    /**
     * La charge a-t-elle une pièce servable : lu dans la requête de la
     * liste, ou relu dans sa société pour une charge relue seule.
     */
    private function aUnePiece(Charge $charge): bool
    {
        if (array_key_exists('piece_nom', $charge->getAttributes())) {
            return $charge->getAttributes()['piece_nom'] !== null;
        }

        return PieceDeCharge::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $charge->societe_id)->where('charge_id', $charge->getKey())->whereNull('bloquee_le')->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function pieceDeposee(array $data): ?PieceDeposee
    {
        return PieceDeposee::lire($data['piece'] ?? [])[0] ?? null;
    }

    /**
     * Les magasins que l'accès voit, réels, de la société de l'accès.
     *
     * @return Collection<int, Magasin>
     */
    private function magasinsVus(): Collection
    {
        $acces = $this->acces();

        return Magasin::query()->where('societe_id', $acces->societe->getKey())->where('est_demonstration', false)
            ->when($acces->magasins !== null, fn (Builder $requete): Builder => $requete->whereKey($acces->magasins ?? []))
            ->orderBy('nom')->get(['id', 'uuid', 'nom', 'societe_id']);
    }

    /**
     * @return array<string, string>
     */
    private function magasinsDeLaSaisie(): array
    {
        $choix = $this->acces()->chargesDeLaSociete && $this->ecran !== self::MAGASIN ? ['' => self::TOUTE_LA_SOCIETE] : [];

        foreach ($this->magasinsVus() as $magasin) {
            $choix[(string) $magasin->uuid] = (string) $magasin->nom;
        }

        return $choix;
    }

    private function premierMagasin(): string
    {
        return (string) $this->magasinsVus()->first()?->uuid;
    }

    /**
     * L'identifiant du magasin du filtre, s'il est l'un de ceux que l'accès
     * voit ; dans « Charges du magasin », celui du responsable.
     */
    private function magasinDuFiltre(): ?int
    {
        if ($this->magasinFiltre === '' || $this->magasinFiltre === 'societe') {
            return null;
        }

        $magasin = $this->magasinsVus()->first(fn (Magasin $magasin): bool => (string) $magasin->uuid === $this->magasinFiltre);

        return $magasin === null ? null : CleDuModele::de($magasin);
    }

    /**
     * Les fournisseurs proposés à la saisie, sans en créer de fiche : ceux
     * des charges que l'accès voit déjà, et les fournisseurs non archivés
     * des magasins réels qu'il voit, leur nom seulement, lus filtrés par la
     * société et ces magasins ; sans doublon, par nom.
     *
     * @return list<string>
     */
    private function fournisseursProposes(): array
    {
        $acces = $this->acces();
        $desCharges = ValeursLues::textes(app(ChargesDeLaSociete::class)->visibles($acces)
            ->whereNotNull('charges.fournisseur')->distinct()->orderBy('charges.fournisseur')->limit(200)->pluck('charges.fournisseur'));
        $desMagasins = ValeursLues::textes(Fournisseur::query()->withoutGlobalScope(MagasinScope::class)
            ->whereIn('magasin_id', Magasin::query()->select('id')->where('societe_id', $acces->societe->getKey())->where('est_demonstration', false)
                ->when($acces->magasins !== null, fn (Builder $requete): Builder => $requete->whereKey($acces->magasins ?? [])))
            ->distinct()->orderBy('nom')->limit(200)->pluck('nom'));
        $noms = array_values(array_unique([...$desCharges, ...$desMagasins]));
        sort($noms);

        return $noms;
    }

    /**
     * @return Collection<int, TypeDeChargeDeLaSociete>
     */
    private function typesDeLaSociete(): Collection
    {
        return app(TypesDeCharge::class)->tousDeLaSociete($this->acces()->societe);
    }

    private function clientDuLien(): ?Societe
    {
        $lien = $this->lienDuClient();

        return $lien === null ? null : Societe::query()->whereKey($lien->societe_id)->first();
    }

    private function lienDuClient(): ?LienCabinet
    {
        return $this->lien === null ? null : app(AccesDesCabinets::class)->liensOuverts(CompteConnecte::exiger())->parUuid($this->lien)->first();
    }

    /**
     * Le mois de la TVA par défaut : le mois en cours quand il est dans la
     * période, sinon son dernier mois.
     */
    private function moisDeLaTvaParDefaut(): string
    {
        $mois = $this->mois();
        $ceMois = substr(Calendrier::deParis()->aujourdhui(), 0, 7);

        return in_array($ceMois, $mois, true) ? $ceMois : $mois[count($mois) - 1];
    }

    /**
     * Le périmètre des graphiques de cet écran pour le compte connecté (voir
     * PerimetreDesGraphiques), restreint au magasin du filtre ; null pour
     * qui n'y voit aucun graphique, ou ne voit pas les graphiques de ce
     * magasin.
     */
    private function perimetreDeLaTva(): ?Perimetre
    {
        $compte = CompteConnecte::exiger();
        $perimetres = app(PerimetreDesGraphiques::class);
        $tenant = Filament::getTenant();

        try {
            $perimetre = match ($this->ecran) {
                self::SOCIETE => $perimetres->deLaSociete($compte, $this->acces()->societe),
                self::CABINET => $perimetres->duClient($compte, $this->lienDuClient() ?? throw new AuthorizationException(self::REFUS)),
                self::EXPERT_COMPTABLE => $perimetres->delExpertComptable($compte),
                default => $tenant instanceof Magasin ? $perimetres->duMagasin($compte, $tenant) : throw new AuthorizationException(self::REFUS),
            };

            return $this->magasinFiltre === '' || $this->magasinFiltre === 'societe' ? $perimetre : $perimetre->restreintA($this->magasinFiltre);
        } catch (AuthorizationException) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private static function moisDeLExercice(int $anneeDeFin, int $moisDeFin): array
    {
        if ($moisDeFin < 1 || $moisDeFin > 12) {
            throw new InvalidArgumentException('Mois de fin d\'exercice invalide.');
        }

        // Les mois comptés depuis l'an 0 : le douzième est le mois de fin.
        $dernier = $anneeDeFin * 12 + $moisDeFin - 1;

        return array_map(fn (int $rang): string => sprintf('%04d-%02d', intdiv($dernier - 11 + $rang, 12), ($dernier - 11 + $rang) % 12 + 1), range(0, 11));
    }

    private function refuserLeTelechargement(string $raison): null
    {
        Notification::make()->title('Cette pièce ne se télécharge pas')->body($raison)->danger()->persistent()->send();

        return null;
    }
}
