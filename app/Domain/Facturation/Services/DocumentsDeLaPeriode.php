<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Calendrier\Calendrier;
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
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
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
 * réel et n'est jamais lu ; le magasin de démonstration se lit, ses
 * documents marqués comme tels. Les droits d'un compte dans un magasin se
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
 * ou tous (voir PaiementPolicy) ; jamais un brouillon. Les jours sont ceux
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

    public function __construct(
        private readonly TenantContext $contexte,
        private readonly CalculateurLigne $calculateur,
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
     * il télécharge, les magasins réels d'abord, par nom, chacun avec cette
     * société, qui ne se relit pas.
     *
     * @return Collection<int, Magasin>
     */
    public function magasinsDeLaSociete(User $compte, Societe $societe): Collection
    {
        if ((int) $compte->societe_id !== (int) $societe->getKey() || ! $compte->peutAdministrer()) {
            return collect();
        }

        return $compte->magasinsDeSaPortee()
            ->orderBy('est_demonstration')
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
            return collect();
        }

        return app(AccesDesCabinets::class)->magasinsOuverts($compte)
            ->load('societe')
            ->filter(fn (Magasin $magasin): bool => $this->peutTelechargerDans($compte, $magasin))
            ->toBase()
            ->groupBy(fn (Magasin $magasin): int => (int) $magasin->societe_id)
            ->map(fn (Collection $magasins): Collection => $magasins->values());
    }

    /**
     * A-t-il, dans ce magasin, l'autorisation de télécharger les documents
     * par période, posée à la Gate dans ce magasin ? Jamais dans un magasin
     * réel d'une société qui n'est pas validée, qui n'a aucun document réel.
     */
    public function peutTelechargerDans(User $compte, Magasin $magasin): bool
    {
        return $this->telecharge[$compte->getKey().':'.$magasin->getKey()] ??= ($magasin->est_demonstration || $magasin->societe->estValidee())
            && $this->dansLeMagasin($magasin, fn (): bool => Gate::forUser($compte)->allows(PermissionRegistry::DOCUMENTS_TELECHARGER));
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
     * types.
     *
     * @param  iterable<Magasin>  $magasins
     * @param  list<TypeDeDocumentTelechargeable>  $types
     * @return list<DocumentTrouve>
     */
    public function trouver(User $compte, iterable $magasins, string $du, string $au, array $types, OrigineDesAchats $origine, bool $pieceManquanteSeulement = false): array
    {
        $documents = [];

        foreach ($magasins as $magasin) {
            $visibles = array_values(array_filter($this->typesVisibles($compte, $magasin), fn (TypeDeDocumentTelechargeable $type): bool => in_array($type, $types, true)));

            if ($visibles !== []) {
                $documents = [...$documents, ...$this->dansLeMagasin($magasin, fn (): array => $this->duMagasin($compte, $magasin, $du, $au, $visibles, $origine, $pieceManquanteSeulement))];
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
    public function choisis(User $compte, iterable $magasins, string $du, string $au, array $types, OrigineDesAchats $origine, array $cles, bool $pieceManquanteSeulement = false): array
    {
        $choisies = array_flip($cles);

        return array_values(array_filter(
            $this->trouver($compte, $magasins, $du, $au, $types, $origine, $pieceManquanteSeulement),
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
     * s'il voit encore ceux de toute l'équipe). Le lien d'un fichier
     * préparé ne le sert que s'il les voit tous (voir
     * TelechargerLesDocumentsController).
     *
     * @param  iterable<Magasin>  $magasins
     * @param  list<string>  $cles
     */
    public function nombreEncoreVisibles(User $compte, iterable $magasins, array $cles): int
    {
        $parMagasin = [];

        foreach ($cles as $cle) {
            $parties = explode(':', $cle);

            if (count($parties) >= 3 && ctype_digit($parties[1]) && ($type = TypeDeDocumentTelechargeable::tryFrom($parties[0])) !== null) {
                $parMagasin[(int) $parties[1]][$type->value][] = ['identifiant' => $parties[2], 'portee' => $parties[3] ?? null];
            }
        }

        $nombre = 0;

        foreach ($magasins as $magasin) {
            $lecture = $this->lectureDans($compte, $magasin);

            foreach ($parMagasin[(int) $magasin->getKey()] ?? [] as $type => $designes) {
                $type = TypeDeDocumentTelechargeable::from($type);

                if (in_array($type, $lecture['types'], true)) {
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
     * Gate, dans ce magasin : les types qu'il y voit, et s'il voit les
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

        return $this->lectures[$compte->getKey().':'.$magasin->getKey()] ??= $this->dansLeMagasin($magasin, function () use ($compte, $magasin): array {
            $gate = Gate::forUser($compte);

            return [
                'types' => array_values(array_filter(
                    TypeDeDocumentTelechargeable::cases(),
                    fn (TypeDeDocumentTelechargeable $type): bool => $type->existeDans($magasin->societe->type_activite) && $gate->allows('viewAny', $type->modele()),
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
        $siens = fn (Builder $documents, bool $deLEquipe): Builder => $documents->when(! $deLEquipe, fn (Builder $leurs): Builder => $leurs->where('cree_par', $compte->getKey()));

        return match ($type) {
            TypeDeDocumentTelechargeable::Ventes => $siens(Facture::query()->emises(), $droits['equipe'])->whereKey($identifiants)->count(),
            TypeDeDocumentTelechargeable::AvoirsClients => $siens(Avoir::query()->where('statut', StatutAvoir::Emise), $droits['equipe'])->whereKey($identifiants)->count(),
            TypeDeDocumentTelechargeable::FacturesAchat => FactureAchat::query()->where('statut', StatutFactureAchat::Validee)->whereKey($identifiants)->count(),
            TypeDeDocumentTelechargeable::AvoirsFournisseur => AvoirFournisseur::query()->where('statut', StatutAvoirFournisseur::Emise)->whereKey($identifiants)->count(),
            TypeDeDocumentTelechargeable::Clotures => $siens(ClotureCaisse::query(), $droits['cloturesDeLEquipe'])->whereKey($identifiants)->count(),
        };
    }

    /**
     * @param  list<TypeDeDocumentTelechargeable>  $types
     * @return list<DocumentTrouve>
     */
    private function duMagasin(User $compte, Magasin $magasin, string $du, string $au, array $types, OrigineDesAchats $origine, bool $pieceManquanteSeulement): array
    {
        $droits = $this->lectureDans($compte, $magasin);
        $calendrier = $magasin->calendrier();
        [$debut] = $calendrier->bornesDuJour($du);
        [, $fin] = $calendrier->bornesDuJour($au);
        $nom = "{$magasin->nom} ({$magasin->code})";
        $siens = fn (Builder $documents, bool $deLEquipe): Builder => $documents->when(! $deLEquipe, fn (Builder $leurs): Builder => $leurs->where('cree_par', $compte->getKey()));
        $achats = fn (Builder $achats): Builder => $achats->whereIn('source', $origine->sources())->when($pieceManquanteSeulement, fn (Builder $sansPiece): Builder => $sansPiece->where('piece_manquante', true));
        $documents = [];

        foreach ($types as $type) {
            $documents = [...$documents, ...match ($type) {
                TypeDeDocumentTelechargeable::Ventes => $siens(Facture::query()->emises(), $droits['equipe'])
                    ->where('date_emission', '>=', $debut)->where('date_emission', '<', $fin)
                    ->orderBy('date_emission')->orderBy('id')
                    ->get(['id', 'numero', 'date_emission', 'client_nom', 'total_ttc'])
                    ->map(fn (Facture $facture): DocumentTrouve => new DocumentTrouve($type, $magasin->getKey(), $nom, (string) $facture->id, (string) $facture->numero, $calendrier->jourDe($facture->date_emission), $facture->client_nom, $this->calculateur->enCentiemes($facture->total_ttc)))
                    ->all(),
                TypeDeDocumentTelechargeable::AvoirsClients => $siens(Avoir::query()->where('statut', StatutAvoir::Emise), $droits['equipe'])
                    ->where('date_emission', '>=', $debut)->where('date_emission', '<', $fin)
                    ->orderBy('date_emission')->orderBy('id')
                    ->get(['id', 'numero', 'date_emission', 'client_nom', 'total_ttc'])
                    ->map(fn (Avoir $avoir): DocumentTrouve => new DocumentTrouve($type, $magasin->getKey(), $nom, (string) $avoir->id, (string) $avoir->numero, $calendrier->jourDe($avoir->date_emission), $avoir->client_nom, $this->calculateur->enCentiemes($avoir->total_ttc)))
                    ->all(),
                TypeDeDocumentTelechargeable::FacturesAchat => $achats(FactureAchat::query()->with('fournisseur'))
                    ->where('statut', StatutFactureAchat::Validee)
                    ->where(fn (Builder $factures): Builder => $factures->whereBetween('date_facture', [$du, $au])
                        ->orWhere(fn (Builder $sansDate): Builder => $sansDate->whereNull('date_facture')->where('date_validation', '>=', $debut)->where('date_validation', '<', $fin)))
                    ->orderBy('rang_validation')
                    ->get()
                    ->map(fn (FactureAchat $facture): DocumentTrouve => new DocumentTrouve($type, $magasin->getKey(), $nom, (string) $facture->id, (string) $facture->numero, $facture->date_facture ?? $calendrier->jourDe($facture->date_validation), $facture->fournisseur->nom, $this->calculateur->enCentiemes($facture->total_ttc), $facture->source->getLabel(), (bool) $facture->piece_manquante))
                    ->sortBy(fn (DocumentTrouve $trouve): string => $trouve->jour)
                    ->values()
                    ->all(),
                TypeDeDocumentTelechargeable::AvoirsFournisseur => $achats(AvoirFournisseur::query()->with('factureAchat.fournisseur'))
                    ->where('statut', StatutAvoirFournisseur::Emise)
                    ->where('date_emission', '>=', $debut)->where('date_emission', '<', $fin)
                    ->orderBy('date_emission')->orderBy('numero')
                    ->get()
                    ->map(fn (AvoirFournisseur $avoir): DocumentTrouve => new DocumentTrouve($type, $magasin->getKey(), $nom, (string) $avoir->id, (string) $avoir->numero, $calendrier->jourDe($avoir->date_emission), $avoir->factureAchat->fournisseur->nom, $this->calculateur->enCentiemes($avoir->total_ttc), $avoir->source->getLabel(), (bool) $avoir->piece_manquante))
                    ->all(),
                TypeDeDocumentTelechargeable::Clotures => $siens(ClotureCaisse::query(), $droits['cloturesDeLEquipe'])
                    ->whereBetween('date_cloture', [$du, $au])
                    ->orderBy('date_cloture')
                    ->get(['id', 'numero', 'date_cloture', 'total_ttc'])
                    ->map(fn (ClotureCaisse $cloture): DocumentTrouve => new DocumentTrouve($type, $magasin->getKey(), $nom, (string) $cloture->id, (string) $cloture->numero, $cloture->date_cloture, null, $this->calculateur->enCentiemes($cloture->total_ttc)))
                    ->all(),
                TypeDeDocumentTelechargeable::Encaissements => $this->relevesDesEncaissements($compte, $magasin, $nom, $calendrier, $du, $au, $droits['equipe']),
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
        return $this->paiementsVisibles($compte, $calendrier, $du, $au)
            ->get(['id', 'montant', 'created_at'])
            ->groupBy(fn (Paiement $paiement): string => substr($calendrier->jourDe($paiement->created_at), 0, 7))
            ->map(fn (Collection $paiements, string $mois): DocumentTrouve => new DocumentTrouve(
                TypeDeDocumentTelechargeable::Encaissements,
                $magasin->getKey(),
                $nom,
                $mois,
                'Encaissements '.$mois,
                min($au, self::dernierJourDuMois($mois)),
                null,
                (int) $paiements->sum(fn (Paiement $paiement): int => $this->calculateur->enCentiemes($paiement->montant)),
                porteeDuReleve: $deLEquipe ? DocumentTrouve::RELEVE_DE_L_EQUIPE : DocumentTrouve::RELEVE_DE_LA_PERSONNE,
            ))
            ->values()
            ->all();
    }
}
