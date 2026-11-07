<?php

namespace App\Filament\Concerns;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Enums\FormatDuTelechargement;
use App\Domain\Facturation\Enums\OrigineDesAchats;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentsDeLaPeriode;
use App\Domain\Facturation\Services\DocumentTrouve;
use App\Domain\Tenancy\Actions\DemanderLeTelechargementDesDocumentsAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use App\Domain\Tenancy\Services\ExercicesDeLaSociete;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;

/**
 * La page « Télécharger les documents » (décision de l'éditeur du
 * 03/11/2026, lot EX), dans un magasin, dans l'espace de la société et dans
 * l'espace du cabinet : une période « du … au … » et ses raccourcis, les
 * cases des types de documents que la personne peut voir, l'origine des
 * achats et la case « pièce du fournisseur manquante seulement » (décision
 * de l'éditeur du 03/11/2026, F1 = b), et, hors d'un magasin, les cases des
 * magasins proposés ; la liste des documents trouvés (voir
 * DocumentsDeLaPeriode::trouver()), par pages, un achat sans sa pièce
 * marqué, une case par document, « Tout sélectionner » (tous les documents
 * trouvés, pas seulement la page affichée) et « Tout désélectionner », le
 * nombre et le total TTC de la sélection ; puis le format et
 * « Télécharger », qui confie la demande à la file d'attente (voir
 * DemanderLeTelechargementDesDocumentsAction) ; enfin, ses téléchargements
 * des sept derniers jours dans cette société, chacun avec son lien une fois
 * prêt.
 *
 * Ce que le navigateur envoie ne choisit jamais rien hors de ce que la page
 * propose : les magasins cochés se croisent avec ceux que la page propose
 * (voir magasinsProposes()), les types avec ceux que la personne voit,
 * la sélection avec les documents trouvés ; aucune méthode publique ne
 * rend un document ni ne reçoit un identifiant.
 *
 * Les magasins proposés, les types proposés, les droits de la personne
 * dans chaque magasin et les documents trouvés se lisent une fois par
 * requête (propriétés privées, que Livewire ne garde pas d'une requête à
 * l'autre) : un clic ne repose jamais deux fois la même question.
 */
trait TelechargeLesDocumentsParPeriode
{
    /**
     * Le premier jour de la période, AAAA-MM-JJ.
     */
    public string $du = '';

    /**
     * Son dernier jour, AAAA-MM-JJ.
     */
    public string $au = '';

    /**
     * Les types cochés (voir TypeDeDocumentTelechargeable).
     *
     * @var list<string>
     */
    public array $types = [];

    /**
     * L'origine des achats (voir OrigineDesAchats).
     */
    public string $origine = 'toutes';

    /**
     * Seulement les factures d'achat et les avoirs fournisseur saisis sans
     * la pièce du fournisseur (décision de l'éditeur du 03/11/2026, F1 = b).
     */
    public bool $pieceManquanteSeulement = false;

    /**
     * Les magasins cochés, par identifiant, parmi ceux que la page propose.
     *
     * @var list<int|string>
     */
    public array $magasinsCoches = [];

    /**
     * Les clés des documents cochés (voir DocumentTrouve::cle()).
     *
     * @var list<string>
     */
    public array $selection = [];

    /**
     * Le format du fichier (voir FormatDuTelechargement).
     */
    public string $format = 'zip';

    /**
     * La page de la liste des documents affichée.
     */
    public int $pageDeLaListe = 1;

    /**
     * Les documents trouvés pendant cette requête, et ce qui les a
     * trouvés : la liste ne se relit qu'une fois par requête.
     *
     * @var array{cle: string, documents: list<DocumentTrouve>}|null
     */
    private ?array $trouves = null;

    /**
     * Les magasins proposés, lus une fois par requête.
     *
     * @var Collection<int, Magasin>|null
     */
    private ?Collection $magasinsLus = null;

    /**
     * Les types proposés, lus une fois par requête.
     *
     * @var list<TypeDeDocumentTelechargeable>|null
     */
    private ?array $typesLus = null;

    /**
     * Les documents de la période et les droits de la personne dans chaque
     * magasin, lus une fois par requête (voir DocumentsDeLaPeriode).
     */
    private ?DocumentsDeLaPeriode $documentsDeLaPeriode = null;

    /**
     * Les magasins où la personne télécharge les documents, que la page
     * propose, lus en base.
     *
     * @return Collection<int, Magasin>
     */
    abstract protected function chercherLesMagasins(): Collection;

    /**
     * La société dont la page lit les documents.
     */
    abstract protected function societeDesDocuments(): ?Societe;

    /**
     * Le calendrier dont les raccourcis prennent le jour courant : celui du
     * magasin, ou celui de Paris pour la société.
     */
    abstract protected function calendrierDesRaccourcis(): Calendrier;

    public const DOCUMENTS_PAR_PAGE = 50;

    /**
     * Les raccourcis de la période, chacun sous son libellé.
     *
     * @var array<string, string>
     */
    public const RACCOURCIS = [
        'ce_mois' => 'Ce mois',
        'mois_dernier' => 'Mois dernier',
        'trimestre' => 'Trimestre en cours',
        'trimestre_dernier' => 'Trimestre dernier',
        'exercice' => 'Exercice en cours',
        'exercice_precedent' => 'Exercice précédent',
    ];

    /**
     * Ce mois, tous les types proposés, tous les magasins proposés.
     */
    protected function preparerLaPage(): void
    {
        $this->raccourci('ce_mois');
        $this->magasinsCoches = $this->magasinsProposes()->map(fn (Magasin $magasin): int => (int) $magasin->getKey())->all();
        $this->types = array_map(fn (TypeDeDocumentTelechargeable $type): string => $type->value, $this->typesProposes());
    }

    /**
     * Pose la période d'un raccourci ; un nom inconnu ne change rien.
     */
    public function raccourci(string $nom): void
    {
        $aujourdhui = $this->calendrierDesRaccourcis()->aujourdhui();
        $mois = Calendrier::premierJourDuMois($aujourdhui);
        $trimestre = self::moisDecale($mois, -(((int) substr($mois, 5, 2) - 1) % 3));
        $societe = $this->societeDesDocuments();
        $exercices = app(ExercicesDeLaSociete::class);

        [$du, $au] = match (true) {
            $nom === 'ce_mois' => [$mois, Calendrier::ajouterJours(self::moisDecale($mois, 1), -1)],
            $nom === 'mois_dernier' => [self::moisDecale($mois, -1), Calendrier::ajouterJours($mois, -1)],
            $nom === 'trimestre' => [$trimestre, Calendrier::ajouterJours(self::moisDecale($trimestre, 3), -1)],
            $nom === 'trimestre_dernier' => [self::moisDecale($trimestre, -3), Calendrier::ajouterJours($trimestre, -1)],
            $nom === 'exercice' && $societe !== null => [$exercices->debutDeLExerciceEnCours($societe, $aujourdhui), $exercices->finDeLExerciceEnCours($societe, $aujourdhui)],
            $nom === 'exercice_precedent' && $societe !== null => [
                $exercices->debutDeLExerciceEnCours($societe, $veille = Calendrier::ajouterJours($exercices->debutDeLExerciceEnCours($societe, $aujourdhui), -1)),
                $veille,
            ],
            default => [null, null],
        };

        if ($du === null || $au === null) {
            return;
        }

        $this->du = $du;
        $this->au = $au;
        $this->pageDeLaListe = 1;
    }

    /**
     * Le premier jour du mois qui vient ce nombre de mois après (avant,
     * s'il est négatif) celui de ce premier jour d'un mois.
     */
    private static function moisDecale(string $premierDuMois, int $nombre): string
    {
        for ($fait = 0; $fait < abs($nombre); $fait++) {
            $premierDuMois = $nombre > 0 ? Calendrier::jourDuMoisSuivant($premierDuMois, 1) : Calendrier::premierJourDuMois(Calendrier::ajouterJours($premierDuMois, -1));
        }

        return $premierDuMois;
    }

    /**
     * Coche tous les documents trouvés, sur toutes les pages.
     */
    public function toutSelectionner(): void
    {
        $this->selection = array_map(fn (DocumentTrouve $document): string => $document->cle(), $this->documentsTrouves());
    }

    public function toutDeselectionner(): void
    {
        $this->selection = [];
    }

    public function pagePrecedente(): void
    {
        $this->pageDeLaListe = max(1, $this->pageDeLaListe - 1);
    }

    public function pageSuivante(): void
    {
        $this->pageDeLaListe = min($this->nombreDePages(), $this->pageDeLaListe + 1);
    }

    /**
     * Une recherche changée revient à la première page de la liste.
     */
    public function updated(string $propriete): void
    {
        if (in_array(explode('.', $propriete)[0], ['du', 'au', 'types', 'origine', 'pieceManquanteSeulement', 'magasinsCoches'], true)) {
            $this->pageDeLaListe = 1;
        }
    }

    /**
     * « Télécharger » : la demande part dans la file d'attente avec les
     * documents cochés que la page a trouvés ; la personne est prévenue
     * quand le fichier est prêt.
     */
    public function telecharger(): void
    {
        $societe = $this->societeDesDocuments();
        $compte = auth()->user();

        if (! $societe instanceof Societe || ! $compte instanceof User) {
            return;
        }

        $cochees = array_flip($this->clesCochees());

        try {
            app(DemanderLeTelechargementDesDocumentsAction::class)->handle(
                $compte,
                $societe,
                $this->magasinsChoisis(),
                $this->du,
                $this->au,
                $this->typesChoisis(),
                $this->origineChoisie(),
                FormatDuTelechargement::tryFrom($this->format) ?? FormatDuTelechargement::Zip,
                array_values(array_filter(
                    array_map(fn (DocumentTrouve $document): string => $document->cle(), $this->documentsTrouves()),
                    fn (string $cle): bool => isset($cochees[$cle]),
                )),
                $this->pieceManquanteSeulement,
            );
        } catch (AuthorizationException|InvalidArgumentException|LogicException $refus) {
            Notification::make()->title($refus->getMessage())->danger()->send();

            return;
        }

        $this->selection = [];
        Notification::make()
            ->title('Votre fichier est en préparation.')
            ->body('Une notification vous préviendra quand il sera prêt ; son lien vaudra sept jours.')
            ->success()
            ->send();
    }

    /**
     * Ce que la vue montre : les magasins proposés, les types proposés,
     * la page de la liste, la sélection comptée et totalisée, les
     * téléchargements récents de la personne dans cette société.
     *
     * @return array<string, mixed>
     */
    protected function donneesDeLaVue(): array
    {
        $documents = $this->documentsTrouves();
        $cochees = array_flip($this->clesCochees());
        $selectionnes = array_values(array_filter($documents, fn (DocumentTrouve $document): bool => isset($cochees[$document->cle()])));
        $this->pageDeLaListe = max(1, min($this->pageDeLaListe, $this->nombreDePages()));
        $calculateur = app(CalculateurLigne::class);
        $societe = $this->societeDesDocuments();
        $compte = auth()->user();

        return [
            'magasinsProposes' => $this->magasinsProposes(),
            'typesProposes' => $this->typesProposes(),
            'avecLesAchats' => collect($this->typesProposes())->contains(fn (TypeDeDocumentTelechargeable $type): bool => $type->estUnAchat()),
            'raccourcis' => self::RACCOURCIS,
            'origines' => OrigineDesAchats::cases(),
            'formats' => FormatDuTelechargement::cases(),
            'periodeValide' => $this->periodeValide(),
            'documents' => array_slice($documents, ($this->pageDeLaListe - 1) * self::DOCUMENTS_PAR_PAGE, self::DOCUMENTS_PAR_PAGE),
            'nombreTrouves' => count($documents),
            'nombreDePages' => $this->nombreDePages(),
            'nombreSelectionnes' => count($selectionnes),
            'totalSelectionne' => $calculateur->enEuros(array_sum(array_map(fn (DocumentTrouve $document): int => $document->ttc, $selectionnes))),
            'euros' => fn (int $centimes): string => $calculateur->enEuros($centimes),
            'telechargements' => $societe instanceof Societe && $compte instanceof User ? TelechargementDeDocuments::recentsDe($compte, $societe)->get() : collect(),
        ];
    }

    /**
     * Les magasins où la personne télécharge les documents, que la page
     * propose, lus une fois par requête.
     *
     * @return Collection<int, Magasin>
     */
    protected function magasinsProposes(): Collection
    {
        return $this->magasinsLus ??= $this->chercherLesMagasins();
    }

    /**
     * Les types que la personne voit dans au moins un magasin proposé, dans
     * l'ordre de l'énumération, lus une fois par requête.
     *
     * @return list<TypeDeDocumentTelechargeable>
     */
    protected function typesProposes(): array
    {
        $compte = auth()->user();

        if (! $compte instanceof User) {
            return [];
        }

        return $this->typesLus ??= (function () use ($compte): array {
            $vus = $this->magasinsProposes()->flatMap(fn (Magasin $magasin): array => $this->documentsDeLaPeriode()->typesVisibles($compte, $magasin))->unique(fn (TypeDeDocumentTelechargeable $type): string => $type->value);

            return array_values(array_filter(TypeDeDocumentTelechargeable::cases(), fn (TypeDeDocumentTelechargeable $type): bool => $vus->contains($type)));
        })();
    }

    /**
     * Oublie les magasins et les types proposés lus pendant cette requête :
     * ce qui les choisit vient de changer (le client de l'espace du
     * cabinet).
     */
    protected function oublierLesMagasinsProposes(): void
    {
        $this->magasinsLus = null;
        $this->typesLus = null;
        $this->trouves = null;
    }

    /**
     * L'instance qui lit les documents et les droits de la personne
     * pendant cette requête.
     */
    protected function documentsDeLaPeriode(): DocumentsDeLaPeriode
    {
        return $this->documentsDeLaPeriode ??= app(DocumentsDeLaPeriode::class);
    }

    /**
     * Les magasins proposés que la personne a cochés.
     *
     * @return Collection<int, Magasin>
     */
    protected function magasinsChoisis(): Collection
    {
        $coches = array_map('intval', array_filter($this->magasinsCoches, 'is_scalar'));

        return $this->magasinsProposes()->filter(fn (Magasin $magasin): bool => in_array((int) $magasin->getKey(), $coches, true))->values();
    }

    /**
     * Les types proposés que la personne a cochés.
     *
     * @return list<TypeDeDocumentTelechargeable>
     */
    protected function typesChoisis(): array
    {
        return array_values(array_filter($this->typesProposes(), fn (TypeDeDocumentTelechargeable $type): bool => in_array($type->value, $this->types, true)));
    }

    /**
     * Les clés des documents cochés, telles que le navigateur les envoie :
     * des textes seulement.
     *
     * @return list<string>
     */
    protected function clesCochees(): array
    {
        return array_values(array_filter($this->selection, 'is_string'));
    }

    protected function origineChoisie(): OrigineDesAchats
    {
        return OrigineDesAchats::tryFrom($this->origine) ?? OrigineDesAchats::Toutes;
    }

    protected function periodeValide(): bool
    {
        return Calendrier::jourValide($this->du) !== null && Calendrier::jourValide($this->au) !== null && $this->du <= $this->au;
    }

    /**
     * Les documents de la recherche, lus une fois par requête : aucun tant
     * que la période n'est pas valide.
     *
     * @return list<DocumentTrouve>
     */
    protected function documentsTrouves(): array
    {
        $compte = auth()->user();
        $magasins = $this->magasinsChoisis();
        $types = $this->typesChoisis();
        $origine = $this->origineChoisie();
        $cle = json_encode([$this->du, $this->au, $magasins->map(fn (Magasin $magasin): int => (int) $magasin->getKey())->all(), array_map(fn (TypeDeDocumentTelechargeable $type): string => $type->value, $types), $origine->value, $this->pieceManquanteSeulement]);

        if ($this->trouves !== null && $this->trouves['cle'] === $cle) {
            return $this->trouves['documents'];
        }

        $documents = ! $compte instanceof User || ! $this->periodeValide() || $types === [] ? [] : $this->documentsDeLaPeriode()->trouver($compte, $magasins, $this->du, $this->au, $types, $origine, $this->pieceManquanteSeulement);
        $this->trouves = ['cle' => (string) $cle, 'documents' => $documents];

        return $documents;
    }

    protected function nombreDePages(): int
    {
        return max(1, (int) ceil(count($this->documentsTrouves()) / self::DOCUMENTS_PAR_PAGE));
    }
}
