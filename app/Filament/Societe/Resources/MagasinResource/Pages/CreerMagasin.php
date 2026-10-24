<?php

namespace App\Filament\Societe\Resources\MagasinResource\Pages;

use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CodeMagasin;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use App\Filament\Societe\Resources\MagasinResource;
use App\Integrations\Sirene\EtablissementSirene;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * La création d'un magasin de la société, le premier compris : la page
 * propose les établissements actifs de son SIREN, lus au répertoire Sirene
 * de l'INSEE à son ouverture (voir ControleSirene::etablissementsActifs())
 * puis gardés quelques minutes pour cette société (voir
 * listeDuRepertoire()). Choisir un établissement préremplit son NIC, le
 * nom du magasin (son enseigne, sinon « Magasin » et sa commune) et son
 * adresse, et propose un code tiré de sa commune, libre dans la société
 * (voir CodeMagasin::proposer()), que la personne modifie à son gré. Un
 * établissement qui est déjà un magasin de la société est marqué « déjà
 * un magasin de votre société », un établissement d'un territoire que le
 * logiciel ne gère pas l'est aussi, et aucun ne se choisit. La liste ne
 * dit jamais rien d'une autre société. Quand le répertoire ne répond pas,
 * ou pour un établissement absent de la liste, le NIC se saisit.
 *
 * La limite de l'API de l'INSEE est commune à tout le logiciel (voir
 * InseeSirene) : une société ne l'épuise pas, en rouvrant la page ou en
 * essayant des NIC, au détriment des inscriptions et des contrôles des
 * autres. Chaque lecture de la liste au répertoire et chaque tentative de
 * création compte parmi CONSULTATIONS_SIRENE_PAR_MINUTE par minute et par
 * société ; au-delà, la liste n'est pas lue (le NIC se saisit) et la
 * création est refusée, sans consulter le répertoire.
 *
 * Ce que montre la page n'est qu'une proposition : la création passe,
 * comme pour tout magasin, par CreerEtablissementAction, qui revérifie
 * l'auteur, contrôle l'établissement au répertoire Sirene, refuse un SIRET
 * déjà pris ou réservé avec ses propres messages, et prend l'adresse du
 * répertoire. Une donnée qu'elle refuse s'affiche sur son champ.
 *
 * @property Form $form
 */
class CreerMagasin extends CreateRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    /**
     * Le choix qui mène à la saisie du NIC.
     */
    public const AUTRE_ETABLISSEMENT = 'autre';

    public const MARQUE_DEJA_UN_MAGASIN = 'déjà un magasin de votre société';

    public const MESSAGE_LISTE_INDISPONIBLE = 'Le répertoire Sirene de l\'INSEE ne répond pas pour le moment : saisissez le NIC de l\'établissement du magasin.';

    public const MESSAGE_LISTE_PARTIELLE = 'Votre entreprise compte plus d\'établissements que le répertoire Sirene n\'en liste en une fois : si celui du magasin n\'y est pas, saisissez son NIC.';

    public const MESSAGE_TROP_DE_CONSULTATIONS = 'Trop de contrôles au répertoire Sirene de l\'INSEE en peu de temps : réessayez dans une minute.';

    public const CONSULTATIONS_SIRENE_PAR_MINUTE = 10;

    /**
     * Combien de minutes la liste lue au répertoire se garde pour la
     * société.
     */
    public const MINUTES_DE_LA_LISTE = 5;

    protected static string $resource = MagasinResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * Les établissements actifs du SIREN, tels que le répertoire les a
     * listés, par SIRET (voir listeDuRepertoire()).
     *
     * @var array<string, array{nic: string, enseigne: string|null, adresse: string, ville: string|null, siege: bool, territoire_gere: bool}>
     */
    #[Locked]
    public array $etablissements = [];

    #[Locked]
    public bool $listeDisponible = false;

    #[Locked]
    public bool $listeComplete = true;

    /**
     * Les SIRET des magasins de la société, lus une fois par requête (voir
     * motifDIndisponibilite()).
     *
     * @var array<string, int>|null
     */
    private ?array $siretsDesMagasins = null;

    public function mount(): void
    {
        parent::mount();

        $liste = $this->listeDuRepertoire();
        $this->listeDisponible = $liste !== null;
        $this->listeComplete = $liste['complete'] ?? true;
        $this->etablissements = $liste['etablissements'] ?? [];
    }

    public function getTitle(): string|Htmlable
    {
        return 'Créer un magasin';
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Établissement')
                ->description(fn (): string => "Les établissements actifs de votre SIREN {$this->societe()->siren} au répertoire Sirene de l'INSEE : choisissez celui du magasin.")
                ->schema([
                    Placeholder::make('liste_indisponible')
                        ->hiddenLabel()
                        ->content(self::MESSAGE_LISTE_INDISPONIBLE)
                        ->visible(fn (): bool => ! $this->listeDisponible),
                    Placeholder::make('liste_partielle')
                        ->hiddenLabel()
                        ->content(self::MESSAGE_LISTE_PARTIELLE)
                        ->visible(fn (): bool => $this->listeDisponible && ! $this->listeComplete),
                    Radio::make('etablissement')
                        ->label('Établissement')
                        ->options(fn (): array => $this->options())
                        ->descriptions(fn (): array => $this->descriptions())
                        ->disableOptionWhen(fn (string|int $value): bool => $this->motifDIndisponibilite((string) $value) !== null)
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (?string $state, ?string $old, Get $get, Set $set) => $this->preremplir($state, $old, $get, $set)),
                    TextInput::make('nic')
                        ->label('NIC de l\'établissement')
                        ->helperText('Les 5 derniers chiffres de son SIRET, après le SIREN.')
                        ->required()
                        ->maxLength(5)
                        ->readOnly(fn (Get $get): bool => array_key_exists((string) $get('etablissement'), $this->etablissements)),
                    Placeholder::make('adresse')
                        ->label('Adresse')
                        ->content(fn (Get $get): string => $this->etablissements[(string) $get('etablissement')]['adresse'] ?? 'Celle que le répertoire Sirene donne de l\'établissement, reprise à la création.'),
                ]),
            Section::make('Magasin')
                ->schema([
                    TextInput::make('nom')
                        ->label('Nom du magasin')
                        ->required()
                        ->maxLength(150),
                    TextInput::make('code')
                        ->label('Code du magasin')
                        ->helperText('Il figurera dans les numéros de tous ses documents (ex. FAC-LYON1-2026-000001) et ne changera plus après son premier document. Laissé vide, il est tiré du nom du magasin.')
                        ->maxLength(CodeMagasin::LONGUEUR_MAXIMALE)
                        ->extraInputAttributes(['style' => 'text-transform: uppercase']),
                    TextInput::make('email')
                        ->label('Adresse e-mail du magasin')
                        ->email()
                        ->maxLength(150),
                ])
                ->columns(2),
        ])->statePath('data');
    }

    /**
     * Le nom que propose un établissement de la liste : son enseigne,
     * sinon « Magasin » et sa commune, en minuscules accentuées comme le
     * répertoire les écrit en capitales (« BOUTIQUE REPUBLIQUE » donne
     * « Boutique Republique », « PARIS » donne « Magasin Paris »).
     *
     * @param  array{enseigne: string|null, ville: string|null}  $etablissement
     */
    public static function nomPropose(array $etablissement): string
    {
        if ($etablissement['enseigne'] !== null) {
            return Str::title(Str::lower($etablissement['enseigne']));
        }

        return trim('Magasin '.Str::title(Str::lower((string) $etablissement['ville'])));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        if (! $this->consulterLeRepertoire()) {
            throw ValidationException::withMessages(['data.nic' => self::MESSAGE_TROP_DE_CONSULTATIONS]);
        }

        return static::executerOuInterrompre(function () use ($data): Magasin {
            try {
                return app(CreerEtablissementAction::class)->handle(auth()->user(), $this->societe(), [
                    'nom' => $data['nom'],
                    'code' => $data['code'] ?? null,
                    'nic' => $data['nic'],
                    'email' => $data['email'] ?? null,
                ]);
            } catch (ValidationException $refus) {
                throw ValidationException::withMessages(collect($refus->errors())->mapWithKeys(fn (array $messages, string $champ): array => ["data.{$champ}" => $messages])->all());
            }
        });
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        /** @var Magasin $magasin */
        $magasin = $this->getRecord();

        return $magasin->etablissement_verifie_le === null
            ? "Le magasin {$magasin->nom} est créé : le répertoire Sirene n'ayant pas répondu, son établissement sera vérifié avant toute vente."
            : "Le magasin {$magasin->nom} est créé.";
    }

    private function societe(): Societe
    {
        /** @var Societe */
        return Filament::getTenant();
    }

    /**
     * Les établissements actifs du SIREN de la société, tels que la page
     * les montre : lus au répertoire, puis gardés MINUTES_DE_LA_LISTE
     * minutes pour cette société seule, qui ne lit jamais ceux qu'une autre
     * société du même SIREN a fait lire (rien ne lui dit qu'elle existe).
     * Null, sans rien garder, quand le répertoire ne répond pas ou que la
     * société a atteint sa limite de consultations.
     *
     * @return array{etablissements: array<string, array{nic: string, enseigne: string|null, adresse: string, ville: string|null, siege: bool, territoire_gere: bool}>, complete: bool}|null
     */
    private function listeDuRepertoire(): ?array
    {
        $societe = $this->societe();
        $cle = "sirene-etablissements-actifs:{$societe->getKey()}:{$societe->siren}";
        $gardee = Cache::get($cle);

        if (is_array($gardee)) {
            return $gardee;
        }

        if (! $this->consulterLeRepertoire()) {
            return null;
        }

        $lue = app(ControleSirene::class)->etablissementsActifs((string) $societe->siren);

        if ($lue['resultat'] !== ResultatVerification::Concluante) {
            return null;
        }

        $liste = [
            'complete' => $lue['complete'],
            'etablissements' => collect($lue['etablissements'])->mapWithKeys(fn (EtablissementSirene $etablissement): array => [$etablissement->siret => [
                'nic' => $etablissement->nic(),
                'enseigne' => $etablissement->enseigne,
                'adresse' => ControleSirene::adresseEnUneLigne($etablissement),
                'ville' => $etablissement->ville,
                'siege' => $etablissement->estSiege,
                'territoire_gere' => ControleSirene::estDansUnTerritoireGere($etablissement),
            ]])->all(),
        ];
        Cache::put($cle, $liste, now()->addMinutes(self::MINUTES_DE_LA_LISTE));

        return $liste;
    }

    /**
     * Compte une consultation du répertoire Sirene pour la société : faux,
     * sans rien compter, au-delà de CONSULTATIONS_SIRENE_PAR_MINUTE dans la
     * minute.
     */
    private function consulterLeRepertoire(): bool
    {
        return RateLimiter::attempt('consultations-sirene-magasins:'.$this->societe()->getKey(), self::CONSULTATIONS_SIRENE_PAR_MINUTE, static fn (): bool => true, 60);
    }

    /**
     * Les établissements de la liste, puis la saisie du NIC. Les clés, des
     * SIRET, se gardent telles quelles (l'opérateur +, et non une
     * décomposition, qui renumérote les clés numériques).
     *
     * @return array<string, string>
     */
    private function options(): array
    {
        return collect($this->etablissements)
            ->map(fn (array $etablissement, string|int $siret): string => implode(' · ', array_filter(["SIRET {$siret}", $etablissement['enseigne']])).($etablissement['siege'] ? ' (siège)' : ''))
            ->all() + [self::AUTRE_ETABLISSEMENT => $this->listeDisponible ? 'Un autre établissement : saisir son NIC' : 'Saisir le NIC de l\'établissement'];
    }

    /**
     * L'adresse de chaque établissement de la liste, et ce qui empêche de
     * le choisir.
     *
     * @return array<string, string>
     */
    private function descriptions(): array
    {
        return collect($this->etablissements)
            ->map(fn (array $etablissement, string|int $siret): string => implode(' — ', array_filter([$etablissement['adresse'], $this->motifDIndisponibilite((string) $siret)])))
            ->all();
    }

    /**
     * Pourquoi cet établissement de la liste ne se choisit pas : il est déjà
     * un magasin de la société, ou dans un territoire que le logiciel ne
     * gère pas encore ; null s'il se choisit. Seuls les SIRET des magasins
     * de la société se lisent ici, une fois par requête.
     */
    private function motifDIndisponibilite(string $choix): ?string
    {
        if (! array_key_exists($choix, $this->etablissements)) {
            return null;
        }

        $this->siretsDesMagasins ??= array_flip($this->societe()->magasins()->whereNotNull('siret')->pluck('siret')->all());

        return match (true) {
            array_key_exists($choix, $this->siretsDesMagasins) => self::MARQUE_DEJA_UN_MAGASIN,
            ! $this->etablissements[$choix]['territoire_gere'] => ControleSirene::MOTIF_TERRITOIRE_NON_GERE,
            default => null,
        };
    }

    /**
     * Le choix d'un établissement : celui de la liste donne son NIC, le nom
     * qu'il propose et le code tiré de sa commune ; la saisie du NIC vide
     * le NIC, et le nom et le code que proposait l'établissement choisi
     * avant, qui ne sont plus ceux du magasin. Un nom ou un code que la
     * personne a saisi n'est jamais écrasé : chacun ne change que vide, ou
     * encore égal à ce que proposait l'établissement choisi avant.
     */
    private function preremplir(?string $choix, ?string $avant, Get $get, Set $set): void
    {
        $etablissement = $this->etablissements[(string) $choix] ?? null;
        $precedent = $this->etablissements[(string) $avant] ?? null;
        $set('nic', $etablissement['nic'] ?? null);

        if (blank($get('nom')) || ($precedent !== null && $get('nom') === self::nomPropose($precedent))) {
            $set('nom', $etablissement === null ? null : self::nomPropose($etablissement));
        }

        if (blank($get('code')) || ($precedent !== null && $get('code') === CodeMagasin::proposer($this->societe(), (string) $precedent['ville']))) {
            $set('code', $etablissement === null ? null : CodeMagasin::proposer($this->societe(), (string) $etablissement['ville']));
        }
    }
}
