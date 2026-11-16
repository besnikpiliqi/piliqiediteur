<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Services\ChargesDeLaSociete;
use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\Charges\Support\TextesDesCharges;
use App\Domain\Facturation\Enums\OrigineDesAchats;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Les documents du téléchargement par période (décision de l'éditeur du
 * 03/11/2026, lot EX) : une lecture hors du magasin courant, déclarée dans
 * CLAUDE.md, dans l'espace de la société (les magasins de la portée de la
 * personne) et dans l'espace d'un cabinet (les magasins réels d'un client
 * lié qui le lui accorde).
 *
 * Chaque magasin se lit à son tour comme magasin actif, sa société comme
 * société active (voir dansLeMagasin()) : MagasinScope reste en place,
 * aucune requête ne contourne sa portée, et chaque droit se pose à la Gate
 * dans ce magasin (voir ControleDesDroits), la lecture seule d'un magasin
 * fermé, suspendu ou d'une société résiliée laissant le téléchargement
 * permis. Un magasin n'est lu que si la personne y a l'autorisation
 * « télécharger les documents par période » (voir peutTelechargerDans()) ;
 * un magasin réel d'une société qui n'est pas validée n'a aucun document
 * réel et n'est jamais lu ; le magasin de démonstration se lit jusqu'à
 * la validation de la société, ses documents marqués comme tels, puis plus
 * jamais (T115). Les droits d'un compte dans un magasin se
 * lisent une fois par instance (une requête de la page, une préparation,
 * un téléchargement, voir peutTelechargerDans() et lectureDans()), et
 * seulement ceux qui servent : une page qui lit plusieurs magasins ne
 * repose jamais deux fois la même question, et une instance ne revoit pas
 * un droit changé pendant sa vie ; une action qui décide sous un verrou
 * prend une instance neuve.
 *
 * Chacun ne reçoit que les documents qu'il peut voir, type par type, comme
 * leurs listes le font : les ventes qu'il voit (les siennes, ou toutes avec
 * « voir celles de toute l'équipe », comme Facture::scopeVisiblesPar()),
 * les avoirs de même (comme Avoir::scopeVisiblesPar()), les achats et les
 * avoirs fournisseur de l'équipe, les clôtures qu'il a déclenchées ou
 * toutes (voir ClotureCaissePolicy), les encaissements qu'il a enregistrés
 * ou tous (voir PaiementPolicy) ; jamais un brouillon.
 *
 * Les charges de la société, avec leurs pièces (décision de l'éditeur du
 * 15/11/2026, lot DATEOP), se lisent par les mêmes requêtes que la page des
 * charges (voir ChargesDeLaSociete::liste()), hors du scope de la société,
 * filtrées par la société et par les magasins que la personne voit (voir
 * DroitsSurLesCharges) : celles d'un magasin réel où elle télécharge et
 * voit les charges (voir voitLesChargesDans()) ; celles de « Toute la
 * société », sans magasin, seulement quand elle voit les charges de toute
 * la société et y a l'autorisation de télécharger (voir
 * telechargeLesChargesDeLaSociete()) ; jamais dans le magasin de
 * démonstration. Elles se choisissent par leur mois de TVA : celles des
 * mois que la période touche, même en partie ; seulement les validées, ou
 * toutes sur demande (voir ChoixDesCharges). Les jours sont ceux
 * du calendrier de chaque magasin : le jour d'émission d'une vente, d'un
 * avoir et d'un avoir fournisseur, la date de la facture du fournisseur
 * d'un achat (à défaut, pour un achat reçu par l'API, le jour de sa
 * validation), la journée d'une clôture, le jour d'enregistrement d'un
 * paiement. Les montants sont ceux qu'enregistre chaque document,
 * additionnés en centimes pour un relevé des encaissements, jamais par la
 * base.
 */
final class DocumentsDeLaPeriode
{
    /**
     * Le numéro d'une charge dont la pièce n'en porte pas.
     */
    public const SANS_NUMERO = 'Sans numéro';

    /**
     * S'il télécharge dans chaque magasin, lu une fois par instance, sous la
     * clé « compte:magasin ».
     *
     * @var array<string, bool>
     */
    private array $telecharge = [];

    /**
     * Ce qu'il voit dans chaque magasin où il télécharge, lu une fois par
     * instance, sous la clé « compte:magasin ».
     *
     * @var array<string, array{types: list<TypeDeDocumentTelechargeable>, equipe: bool, cloturesDeLEquipe: bool}>
     */
    private array $lectures = [];

    /**
     * Son accès aux charges de chaque société, lu une fois par instance,
     * sous la clé « compte:société » ; false quand il n'en a aucun.
     *
     * @var array<string, AccesAuxCharges|false>
     */
    private array $accesAuxCharges = [];

    /**
     * S'il télécharge les charges de « Toute la société » de chaque
     * société, lu une fois par instance, sous la clé « compte:société ».
     *
     * @var array<string, bool>
     */
    private array $chargesDeLaSociete = [];

    public function __construct(
        private readonly TenantContext $contexte,
        private readonly CalculateurLigne $calculateur,
        private readonly DroitsSurLesCharges $droitsSurLesCharges,
        private readonly ChargesDeLaSociete $charges,
        private readonly AccesDesCabinets $cabinets,
        private readonly ExpertsComptablesDeclares $expertsComptables,
    ) {}

    /**
     * Ce magasin, s'il y télécharge.
     *
     * @return Collection<int, Magasin>
     */
    public function magasinsDuMagasin(User $compte, Magasin $magasin): Collection
    {
        return collect([$magasin])->filter(fn (Magasin $candidat): bool => $this->peutTelechargerDans($compte, $candidat))->values();
    }

    /**
     * Les magasins de sa société dans sa portée (tous pour le propriétaire,
     * la sélection d'un administrateur, voir User::magasinsDeSaPortee()) où
     * il télécharge (voir peutTelechargerDans()) : le magasin de
     * démonstration avant la validation de la société, ses magasins réels
     * ensuite, par nom, chacun avec cette société, qui ne se relit pas.
     *
     * @return Collection<int, Magasin>
     */
    public function magasinsDeLaSociete(User $compte, Societe $societe): Collection
    {
        if ((int) $compte->societe_id !== CleDuModele::de($societe) || ! $compte->peutAdministrer()) {
            return new Collection;
        }

        return $compte->magasinsDeSaPortee()
            ->orderBy('nom')
            ->orderBy('id')
            ->get()
            ->each(fn (Magasin $magasin): Magasin => $magasin->setRelation('societe', $societe))
            ->filter(fn (Magasin $magasin): bool => $this->peutTelechargerDans($compte, $magasin))
            ->values()
            ->toBase();
    }

    /**
     * Les magasins réels des clients liés que le cabinet ouvre au compte
     * (son propriétaire, ou le collaborateur à qui ils sont confiés, voir
     * AccesDesCabinets::magasinsOuverts()), où chaque client accorde au
     * cabinet le téléchargement par période, rangés par client (l'identifiant
     * de sa société), par dénomination puis par nom, chacun avec sa société,
     * lue d'un coup pour tous.
     *
     * @return Collection<int, Collection<int, Magasin>>
     */
    public function magasinsDesClients(User $compte): Collection
    {
        if (! $compte->estDUnCabinet()) {
            return new Collection;
        }

        return $this->cabinets->magasinsOuverts($compte)
            ->load('societe')
            ->filter(fn (Magasin $magasin): bool => $this->peutTelechargerDans($compte, $magasin))
            ->toBase()
            ->groupBy(fn (Magasin $magasin): int => (int) $magasin->societe_id)
            ->map(fn (Collection $magasins): Collection => $magasins->values());
    }

    /**
     * A-t-il, dans ce magasin, l'autorisation de télécharger les documents
     * par période, posée à la Gate dans ce magasin ? Jamais dans un magasin
     * réel d'une société qui n'est pas validée, qui n'a aucun document réel,
     * ni dans le magasin de démonstration d'une société validée, qui a
     * disparu de tous ses écrans (T115, lot DEMO-V).
     */
    public function peutTelechargerDans(User $compte, Magasin $magasin): bool
    {
        $aDesDocuments = $magasin->est_demonstration === true ? ! $magasin->societe->estValidee() : $magasin->societe->estValidee();

        return $this->telecharge[CleDuModele::de($compte).':'.CleDuModele::de($magasin)] ??= $aDesDocuments
            && $this->dansLeMagasin($magasin, fn (): bool => Gate::forUser($compte)->allows(PermissionRegistry::DOCUMENTS_TELECHARGER));
    }

    /**
     * Voit-il les charges de ce magasin réel, en plus d'y télécharger les
     * documents (voir lectureDans()) : le propriétaire toujours, un
     * administrateur ou un responsable à qui « Charges : ajouter » y est
     * cochée, le cabinet lié et l'expert-comptable déclaré à qui le client
     * accorde « Charges : voir, ajouter, saisir et valider » et ouvre ce
     * magasin (voir DroitsSurLesCharges) ; jamais dans le magasin de
     * démonstration, ni avant la validation de la société.
     */
    public function voitLesChargesDans(User $compte, Magasin $magasin): bool
    {
        return $magasin->est_demonstration !== true
            && ($this->accesAuxCharges($compte, $magasin->societe)?->voitLeMagasin(CleDuModele::de($magasin)) ?? false);
    }

    /**
     * Télécharge-t-il les charges de « Toute la société » de cette société
     * validée : il les voit (le propriétaire, l'administrateur à qui
     * « Charges : ajouter » est cochée, le cabinet lié et l'expert-comptable
     * déclaré qui ont les charges, voir DroitsSurLesCharges) et y a
     * l'autorisation de télécharger les documents par période, qui ne
     * dépend pas d'un magasin : toujours pour le propriétaire, cochée à au
     * moins une de ses affectations pour un administrateur, accordée par
     * le client au cabinet ou à la déclaration de son expert-comptable
     * (décision de Claude, D76). Jamais un responsable, qui ne voit que
     * les charges de son magasin.
     */
    public function telechargeLesChargesDeLaSociete(User $compte, Societe $societe): bool
    {
        return $this->chargesDeLaSociete[CleDuModele::de($compte).':'.CleDuModele::de($societe)] ??= ($this->accesAuxCharges($compte, $societe)?->chargesDeLaSociete ?? false)
            && in_array(PermissionRegistry::DOCUMENTS_TELECHARGER, match (true) {
                $compte->estProprietaire() => [PermissionRegistry::DOCUMENTS_TELECHARGER],
                $compte->estDUnCabinet() => $this->cabinets->autorisationsParClient($compte)[CleDuModele::de($societe)] ?? [],
                $compte->estExpertComptable() => $this->expertsComptables->declarationDe($compte)?->autorisationsRetenues() ?? [],
                $compte->estAdministrateur() => $compte->casesDeSesAffectations(),
                default => [],
            }, true);
    }

    /**
     * Son accès aux charges de cette société validée, sans lui demander
     * s'il les valide (voir DroitsSurLesCharges::pour()), lu une fois par
     * instance ; null quand il n'en a aucun.
     */
    private function accesAuxCharges(User $compte, Societe $societe): ?AccesAuxCharges
    {
        $acces = $this->accesAuxCharges[CleDuModele::de($compte).':'.CleDuModele::de($societe)] ??= ($societe->estValidee() ? $this->droitsSurLesCharges->pour($compte, $societe, pourValider: false) : null) ?? false;

        return $acces === false ? null : $acces;
    }

    /**
     * Les types de documents qu'il voit dans ce magasin où il télécharge,
     * dans l'ordre de l'énumération, ceux qui existent dans le type
     * d'activité de sa société seulement ; aucun là où il ne télécharge
     * pas.
     *
     * @return list<TypeDeDocumentTelechargeable>
     */
    public function typesVisibles(User $compte, Magasin $magasin): array
    {
        return $this->lectureDans($compte, $magasin)['types'];
    }

    /**
     * Les documents de ces types, du jour $du au jour $au compris, dans
     * chacun de ces magasins où il télécharge, ceux qu'il peut voir
     * seulement, magasin par magasin puis type par type, chacun dans
     * l'ordre de ses jours. L'origine, et, sur demande, la seule marque
     * « pièce du fournisseur manquante », choisissent parmi les factures
     * d'achat et les avoirs fournisseur ; ils ne touchent pas aux autres
     * types. Les charges choisissent les leurs (voir ChoixDesCharges) :
     * celles de « Toute la société » d'abord, s'il les télécharge, puis
     * celles de chaque magasin, chacune dans l'ordre de la page des
     * charges.
     *
     * @param  iterable<Magasin>  $magasins
     * @param  list<TypeDeDocumentTelechargeable>  $types
     * @return list<DocumentTrouve>
     */
    public function trouver(User $compte, iterable $magasins, string $du, string $au, array $types, OrigineDesAchats $origine, bool $pieceManquanteSeulement = false, ChoixDesCharges $charges = new ChoixDesCharges): array
    {
        $societe = $charges->deLaSociete;
        $documents = in_array(TypeDeDocumentTelechargeable::Charges, $types, true) && $societe !== null && $this->telechargeLesChargesDeLaSociete($compte, $societe)
            ? $this->chargesTrouvees($compte, $societe, null, $du, $au, $charges->toutes)
            : [];

        foreach ($magasins as $magasin) {
            $visibles = array_values(array_filter($this->typesVisibles($compte, $magasin), fn (TypeDeDocumentTelechargeable $type): bool => in_array($type, $types, true)));

            if ($visibles !== []) {
                $documents = [...$documents, ...$this->dansLeMagasin($magasin, fn (): array => $this->duMagasin($compte, $magasin, $du, $au, $visibles, $origine, $pieceManquanteSeulement, $charges->toutes))];
            }
        }

        return $documents;
    }

    /**
     * Ceux de ces documents dont la clé est choisie, relus comme trouver()
     * les trouve : une clé qui ne désigne aucun document que la personne
     * peut voir ne désigne rien.
     *
     * @param  iterable<Magasin>  $magasins
     * @param  list<TypeDeDocumentTelechargeable>  $types
     * @param  list<string>  $cles
     * @return list<DocumentTrouve>
     */
    public function choisis(User $compte, iterable $magasins, string $du, string $au, array $types, OrigineDesAchats $origine, array $cles, bool $pieceManquanteSeulement = false, ChoixDesCharges $charges = new ChoixDesCharges): array
    {
        $choisies = array_flip($cles);

        return array_values(array_filter(
            $this->trouver($compte, $magasins, $du, $au, $types, $origine, $pieceManquanteSeulement, $charges),
            fn (DocumentTrouve $document): bool => isset($choisies[$document->cle()]),
        ));
    }

    /**
     * Combien des documents de ces clés (voir DocumentTrouve::cle()) il
     * voit encore, dans ceux de ces magasins où il télécharge encore, quels
     * que soient leur jour et les critères de la recherche qui les a
     * trouvés : son type lui reste visible dans son magasin et le document
     * aussi (une vente ou un avoir d'un autre vendeur, une clôture qu'un
     * autre a déclenchée, seulement s'il voit encore ceux de toute
     * l'équipe ; le relevé des encaissements de toute l'équipe, seulement
     * s'il voit encore ceux de toute l'équipe ; une charge, s'il voit
     * encore les charges de son magasin, ou celles de « Toute la société »
     * de cette société et les y télécharge encore). Le lien d'un fichier
     * préparé ne le sert que s'il les voit tous (voir
     * TelechargerLesDocumentsController).
     *
     * @param  iterable<Magasin>  $magasins
     * @param  list<string>  $cles
     */
    public function nombreEncoreVisibles(User $compte, iterable $magasins, array $cles, ?Societe $chargesDeLaSociete = null): int
    {
        $parMagasin = [];

        foreach ($cles as $cle) {
            $parties = explode(':', $cle);

            if (count($parties) >= 3 && ctype_digit($parties[1]) && ($type = TypeDeDocumentTelechargeable::tryFrom($parties[0])) !== null) {
                $parMagasin[(int) $parties[1]][$type->value][] = ['identifiant' => $parties[2], 'portee' => $parties[3] ?? null];
            }
        }

        $nombre = 0;
        $deLaSociete = $parMagasin[0][TypeDeDocumentTelechargeable::Charges->value] ?? [];

        if ($deLaSociete !== [] && $chargesDeLaSociete !== null && $this->telechargeLesChargesDeLaSociete($compte, $chargesDeLaSociete)) {
            $nombre += $this->chargesEncoreVues($compte, $chargesDeLaSociete, null, $deLaSociete);
        }

        foreach ($magasins as $magasin) {
            $lecture = $this->lectureDans($compte, $magasin);

            foreach ($parMagasin[CleDuModele::de($magasin)] ?? [] as $type => $designes) {
                $type = TypeDeDocumentTelechargeable::from($type);

                if ($type === TypeDeDocumentTelechargeable::Charges) {
                    $nombre += in_array($type, $lecture['types'], true) ? $this->chargesEncoreVues($compte, $magasin->societe, CleDuModele::de($magasin), $designes) : 0;
                } elseif (in_array($type, $lecture['types'], true)) {
                    $nombre += $this->dansLeMagasin($magasin, fn (): int => $this->nombreVisiblesDuType($compte, $type, $designes, $lecture));
                }
            }
        }

        return $nombre;
    }

    /**
     * Exécute ce callback avec ce magasin pour magasin actif et sa société
     * pour société active, d'où qu'il parte (l'espace d'un cabinet pose la
     * société du cabinet, celui d'une société la sienne), puis restaure le
     * contexte d'avant.
     *
     * @template TResultat
     *
     * @param  Closure(): TResultat  $callback
     * @return TResultat
     */
    public function dansLeMagasin(Magasin $magasin, Closure $callback): mixed
    {
        return $this->contexte->runAsSociete($magasin->societe, fn (): mixed => $this->contexte->runAs($magasin, $callback));
    }

    /**
     * Les paiements d'un relevé des encaissements qu'il voit : enregistrés
     * dans le magasin actif de $du à $au compris, dans son calendrier, ceux
     * qu'il a enregistrés, ou tous avec « voir celles de toute l'équipe »,
     * dans l'ordre de leur enregistrement.
     *
     * @return Builder<Paiement>
     */
    public function paiementsVisibles(User $compte, Calendrier $calendrier, string $du, string $au): Builder
    {
        [$debut] = $calendrier->bornesDuJour($du);
        [, $fin] = $calendrier->bornesDuJour($au);

        return Paiement::query()
            ->where('created_at', '>=', $debut)
            ->where('created_at', '<', $fin)
            ->when(! $this->releveDeLEquipe($compte), fn (Builder $paiements): Builder => $paiements->where('cree_par', $compte->getKey()))
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Voit-il, dans le magasin actif, les encaissements de toute l'équipe,
     * et non les siens seulement ?
     */
    public function releveDeLEquipe(User $compte): bool
    {
        return Gate::forUser($compte)->allows(PermissionRegistry::FACTURE_VIEW_TOUTES);
    }

    /**
     * Le dernier jour de ce mois (AAAA-MM).
     */
    public static function dernierJourDuMois(string $mois): string
    {
        return Calendrier::ajouterJours(Calendrier::jourDuMoisSuivant($mois.'-01', 1), -1);
    }

    /**
     * Ce qu'il voit dans ce magasin où il télécharge, lu une fois, à la
     * Gate, dans ce magasin : les types qu'il y voit (les charges, selon
     * voitLesChargesDans()), et s'il voit les
     * ventes, les avoirs et les encaissements de toute l'équipe, puis les
     * clôtures de toute l'équipe ; rien là où il ne télécharge pas.
     *
     * @return array{types: list<TypeDeDocumentTelechargeable>, equipe: bool, cloturesDeLEquipe: bool}
     */
    private function lectureDans(User $compte, Magasin $magasin): array
    {
        if (! $this->peutTelechargerDans($compte, $magasin)) {
            return ['types' => [], 'equipe' => false, 'cloturesDeLEquipe' => false];
        }

        return $this->lectures[CleDuModele::de($compte).':'.CleDuModele::de($magasin)] ??= $this->dansLeMagasin($magasin, function () use ($compte, $magasin): array {
            $gate = Gate::forUser($compte);

            return [
                'types' => array_values(array_filter(
                    TypeDeDocumentTelechargeable::cases(),
                    fn (TypeDeDocumentTelechargeable $type): bool => $type->existeDans($magasin->societe->type_activite)
                        && ($type === TypeDeDocumentTelechargeable::Charges ? $this->voitLesChargesDans($compte, $magasin) : $gate->allows('viewAny', $type->modele())),
                )),
                'equipe' => $this->releveDeLEquipe($compte),
                'cloturesDeLEquipe' => $gate->allows(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES),
            ];
        });
    }

    /**
     * Combien de ces documents d'un type qu'il voit dans le magasin actif
     * il voit encore.
     *
     * @param  list<array{identifiant: string, portee: string|null}>  $designes
     * @param  array{types: list<TypeDeDocumentTelechargeable>, equipe: bool, cloturesDeLEquipe: bool}  $droits
     */
    private function nombreVisiblesDuType(User $compte, TypeDeDocumentTelechargeable $type, array $designes, array $droits): int
    {
        if ($type === TypeDeDocumentTelechargeable::Encaissements) {
            return count(array_filter($designes, fn (array $releve): bool => preg_match('/^\d{4}-\d{2}$/', $releve['identifiant']) === 1
                && ($releve['portee'] === DocumentTrouve::RELEVE_DE_LA_PERSONNE || ($releve['portee'] === DocumentTrouve::RELEVE_DE_L_EQUIPE && $droits['equipe']))));
        }

        $identifiants = array_values(array_unique(array_map(intval(...), array_filter(array_column($designes, 'identifiant'), ctype_digit(...)))));

        return match ($type) {
            TypeDeDocumentTelechargeable::Ventes => self::siens(Facture::query()->emises(), $droits['equipe'], $compte)->whereKey($identifiants)->count(),
            TypeDeDocumentTelechargeable::AvoirsClients => self::siens(Avoir::query()->where('statut', StatutAvoir::Emise), $droits['equipe'], $compte)->whereKey($identifiants)->count(),
            TypeDeDocumentTelechargeable::FacturesAchat => FactureAchat::query()->where('statut', StatutFactureAchat::Validee)->whereKey($identifiants)->count(),
            TypeDeDocumentTelechargeable::AvoirsFournisseur => AvoirFournisseur::query()->where('statut', StatutAvoirFournisseur::Emise)->whereKey($identifiants)->count(),
            TypeDeDocumentTelechargeable::Clotures => self::siens(ClotureCaisse::query(), $droits['cloturesDeLEquipe'], $compte)->whereKey($identifiants)->count(),
            TypeDeDocumentTelechargeable::Charges => 0,
        };
    }

    /**
     * Combien de ces charges désignées il voit encore : celles de ce
     * magasin, ou de « Toute la société » pour null, parmi celles que son
     * accès voit (voir AccesAuxCharges::filtrer()), quel que soit leur
     * état.
     *
     * @param  list<array{identifiant: string, portee: string|null}>  $designes
     */
    private function chargesEncoreVues(User $compte, Societe $societe, ?int $magasin, array $designes): int
    {
        $acces = $this->accesAuxCharges($compte, $societe);
        $identifiants = array_values(array_unique(array_map(intval(...), array_filter(array_column($designes, 'identifiant'), ctype_digit(...)))));

        return $acces === null ? 0 : $this->charges->visibles($acces)
            ->whereKey($identifiants)
            ->when($magasin === null, fn (Builder $charges): Builder => $charges->whereNull('charges.magasin_id'), fn (Builder $charges): Builder => $charges->where('charges.magasin_id', $magasin))
            ->count();
    }

    /**
     * Les charges que son accès voit, de ce magasin ou de « Toute la
     * société » pour null, dont le mois de TVA est un mois que la période
     * touche, toutes ou seulement les validées, lues par la requête de la
     * page des charges (voir ChargesDeLaSociete::liste()), dans son ordre :
     * par mois de TVA, puis par type. Chacune est un document de la liste :
     * son numéro de pièce, son jour (la date de la pièce, ou le premier
     * jour de son mois de TVA), son fournisseur, son TTC, sa marque « pièce
     * manquante » quand elle n'a aucune pièce à servir, et le libellé qui
     * dit son type, son mois de TVA et son état.
     *
     * @return list<DocumentTrouve>
     */
    private function chargesTrouvees(User $compte, Societe $societe, ?Magasin $magasin, string $du, string $au, bool $toutes): array
    {
        $acces = $this->accesAuxCharges($compte, $societe);

        if ($acces === null) {
            return [];
        }

        $mois = [];

        for ($premier = Calendrier::premierJourDuMois($du); $premier <= $au; $premier = Calendrier::jourDuMoisSuivant($premier, 1)) {
            $mois[] = substr($premier, 0, 7);
        }

        $nom = $magasin === null ? TextesDesCharges::TOUTE_LA_SOCIETE : "{$magasin->nom} ({$magasin->code})";

        return array_values($this->charges->liste($acces, $mois, $magasin === null ? null : CleDuModele::de($magasin), $magasin === null, 'toutes')
            ->when(! $toutes, fn (Builder $charges): Builder => $charges->where('charges.etat', EtatDeLaCharge::Validee->value))
            ->get()
            ->map(fn (Charge $charge): DocumentTrouve => new DocumentTrouve(
                TypeDeDocumentTelechargeable::Charges,
                $magasin === null ? 0 : CleDuModele::de($magasin),
                $nom,
                (string) $charge->getKey(),
                $charge->numero_piece ?? self::SANS_NUMERO,
                $charge->date_piece ?? $charge->mois_tva.'-01',
                $charge->fournisseur,
                $charge->total_ttc,
                pieceManquante: ValeursLues::texteOuNul($charge->getAttributes()['piece_nom'] ?? null) === null,
                libelle: 'Charge : '.$charge->libelleDuType().', TVA '.self::duMois($charge->mois_tva).', '.TextesDesCharges::enMinuscule($charge->etat->getLabel()),
                societeId: CleDuModele::de($societe),
            ))
            ->all());
    }

    /**
     * « de mars 2026 », « d'avril 2026 » : ce mois (AAAA-MM) après « de »,
     * élidé devant une voyelle.
     */
    private static function duMois(string $mois): string
    {
        $nom = TextesDesCharges::nomDuMois($mois);

        return (preg_match('/^[aeiou]/u', $nom) === 1 ? 'd\'' : 'de ').$nom;
    }

    /**
     * Les documents de la requête, ou seulement ceux que le compte a créés
     * quand il ne voit pas ceux de toute l'équipe.
     *
     * @template TDocument of Model
     *
     * @param  Builder<TDocument>  $documents
     * @return Builder<TDocument>
     */
    private static function siens(Builder $documents, bool $deLEquipe, User $compte): Builder
    {
        return $documents->when(! $deLEquipe, fn (Builder $leurs): Builder => $leurs->where('cree_par', $compte->getKey()));
    }

    /**
     * Les achats de l'origine choisie, et seulement ceux dont la pièce du
     * fournisseur manque quand on ne veut qu'eux.
     *
     * @template TAchat of FactureAchat|AvoirFournisseur
     *
     * @param  Builder<TAchat>  $achats
     * @return Builder<TAchat>
     */
    private static function achats(Builder $achats, OrigineDesAchats $origine, bool $pieceManquanteSeulement): Builder
    {
        return $achats->whereIn('source', $origine->sources())->when($pieceManquanteSeulement, fn (Builder $sansPiece): Builder => $sansPiece->where('piece_manquante', true));
    }

    /**
     * @param  list<TypeDeDocumentTelechargeable>  $types
     * @return list<DocumentTrouve>
     */
    private function duMagasin(User $compte, Magasin $magasin, string $du, string $au, array $types, OrigineDesAchats $origine, bool $pieceManquanteSeulement, bool $toutesLesCharges): array
    {
        $droits = $this->lectureDans($compte, $magasin);
        $calendrier = $magasin->calendrier();
        [$debut] = $calendrier->bornesDuJour($du);
        [, $fin] = $calendrier->bornesDuJour($au);
        $nom = "{$magasin->nom} ({$magasin->code})";
        $documents = [];

        foreach ($types as $type) {
            $documents = [...$documents, ...match ($type) {
                TypeDeDocumentTelechargeable::Ventes => self::siens(Facture::query()->emises(), $droits['equipe'], $compte)
                    ->where('date_emission', '>=', $debut)->where('date_emission', '<', $fin)
                    ->orderBy('date_emission')->orderBy('id')
                    ->get(['id', 'numero', 'date_emission', 'client_nom', 'total_ttc'])
                    ->map(fn (Facture $facture): DocumentTrouve => new DocumentTrouve($type, CleDuModele::de($magasin), $nom, (string) $facture->id, (string) $facture->numero, $calendrier->jourDe(ValeurRequise::de($facture->date_emission, 'la date d\'émission de la facture émise')), $facture->client_nom, $this->calculateur->enCentiemes($facture->total_ttc)))
                    ->all(),
                TypeDeDocumentTelechargeable::AvoirsClients => self::siens(Avoir::query()->where('statut', StatutAvoir::Emise), $droits['equipe'], $compte)
                    ->where('date_emission', '>=', $debut)->where('date_emission', '<', $fin)
                    ->orderBy('date_emission')->orderBy('id')
                    ->get(['id', 'numero', 'date_emission', 'client_nom', 'total_ttc'])
                    ->map(fn (Avoir $avoir): DocumentTrouve => new DocumentTrouve($type, CleDuModele::de($magasin), $nom, (string) $avoir->id, (string) $avoir->numero, $calendrier->jourDe(ValeurRequise::de($avoir->date_emission, 'la date d\'émission de l\'avoir émis')), $avoir->client_nom, $this->calculateur->enCentiemes($avoir->total_ttc)))
                    ->all(),
                TypeDeDocumentTelechargeable::FacturesAchat => self::achats(FactureAchat::query()->with('fournisseur'), $origine, $pieceManquanteSeulement)
                    ->where('statut', StatutFactureAchat::Validee)
                    ->where(fn (Builder $factures): Builder => $factures->whereBetween('date_facture', [$du, $au])
                        ->orWhere(fn (Builder $sansDate): Builder => $sansDate->whereNull('date_facture')->where('date_validation', '>=', $debut)->where('date_validation', '<', $fin)))
                    ->orderBy('rang_validation')
                    ->get()
                    ->map(fn (FactureAchat $facture): DocumentTrouve => new DocumentTrouve($type, CleDuModele::de($magasin), $nom, (string) $facture->id, (string) $facture->numero, $facture->date_facture ?? $calendrier->jourDe(ValeurRequise::de($facture->date_validation, 'la date de validation de la facture d\'achat validée')), ValeurRequise::de($facture->fournisseur, 'le fournisseur de la facture d\'achat')->nom, $this->calculateur->enCentiemes($facture->total_ttc), $facture->source->getLabel(), (bool) $facture->piece_manquante))
                    ->sortBy(fn (DocumentTrouve $trouve): string => $trouve->jour)
                    ->values()
                    ->all(),
                TypeDeDocumentTelechargeable::AvoirsFournisseur => self::achats(AvoirFournisseur::query()->with('factureAchat.fournisseur'), $origine, $pieceManquanteSeulement)
                    ->where('statut', StatutAvoirFournisseur::Emise)
                    ->where('date_emission', '>=', $debut)->where('date_emission', '<', $fin)
                    ->orderBy('date_emission')->orderBy('numero')
                    ->get()
                    ->map(fn (AvoirFournisseur $avoir): DocumentTrouve => new DocumentTrouve($type, CleDuModele::de($magasin), $nom, (string) $avoir->id, (string) $avoir->numero, $calendrier->jourDe(ValeurRequise::de($avoir->date_emission, 'la date d\'émission de l\'avoir fournisseur émis')), ValeurRequise::de(ValeurRequise::de($avoir->factureAchat, 'la facture d\'achat de l\'avoir fournisseur')->fournisseur, 'le fournisseur de la facture d\'achat')->nom, $this->calculateur->enCentiemes($avoir->total_ttc), $avoir->source->getLabel(), (bool) $avoir->piece_manquante))
                    ->all(),
                TypeDeDocumentTelechargeable::Clotures => self::siens(ClotureCaisse::query(), $droits['cloturesDeLEquipe'], $compte)
                    ->whereBetween('date_cloture', [$du, $au])
                    ->orderBy('date_cloture')
                    ->get(['id', 'numero', 'date_cloture', 'total_ttc'])
                    ->map(fn (ClotureCaisse $cloture): DocumentTrouve => new DocumentTrouve($type, CleDuModele::de($magasin), $nom, (string) $cloture->id, (string) $cloture->numero, $cloture->date_cloture, null, $this->calculateur->enCentiemes($cloture->total_ttc)))
                    ->all(),
                TypeDeDocumentTelechargeable::Encaissements => $this->relevesDesEncaissements($compte, $magasin, $nom, $calendrier, $du, $au, $droits['equipe']),
                TypeDeDocumentTelechargeable::Charges => $this->chargesTrouvees($compte, $magasin->societe, $magasin, $du, $au, $toutesLesCharges),
            }];
        }

        return $documents;
    }

    /**
     * Un relevé des encaissements par mois qui a des paiements que la
     * personne voit dans la période : son jour est le dernier de ce mois
     * dans la période, son TTC le total encaissé, contre-passations
     * déduites ; il dit s'il réunit ceux de toute l'équipe.
     *
     * @return list<DocumentTrouve>
     */
    private function relevesDesEncaissements(User $compte, Magasin $magasin, string $nom, Calendrier $calendrier, string $du, string $au, bool $deLEquipe): array
    {
        return array_values($this->paiementsVisibles($compte, $calendrier, $du, $au)
            ->get(['id', 'montant', 'created_at'])
            ->groupBy(fn (Paiement $paiement): string => substr($calendrier->jourDe(ValeurRequise::de($paiement->created_at, 'la date d\'enregistrement du paiement')), 0, 7))
            ->map(fn (Collection $paiements, string $mois): DocumentTrouve => new DocumentTrouve(
                TypeDeDocumentTelechargeable::Encaissements,
                CleDuModele::de($magasin),
                $nom,
                $mois,
                'Encaissements '.$mois,
                min($au, self::dernierJourDuMois($mois)),
                null,
                (int) $paiements->sum(fn (Paiement $paiement): int => $this->calculateur->enCentiemes($paiement->montant)),
                porteeDuReleve: $deLEquipe ? DocumentTrouve::RELEVE_DE_L_EQUIPE : DocumentTrouve::RELEVE_DE_LA_PERSONNE,
            ))
            ->all());
    }
}
