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
 * société active (voir dansLeMagasin()) : MagasinScope reste en place, aucune requête ne
 * contourne sa portée, et chaque droit se pose à la Gate dans ce magasin
 * (voir ControleDesDroits), la lecture seule d'un magasin fermé, suspendu
 * ou d'une société résiliée laissant le téléchargement permis. Un magasin
 * n'est lu que si la personne y a l'autorisation « télécharger les
 * documents par période » (voir peutTelechargerDans()) ; un magasin réel
 * d'une société qui n'est pas validée n'a aucun document réel et n'est
 * jamais lu ; le magasin de démonstration se lit, ses documents marqués
 * comme tels.
 *
 * Chacun ne reçoit que les documents qu'il peut voir, type par type, comme
 * leurs listes le font (voir typesVisibles()) : les ventes qu'il voit (les
 * siennes, ou toutes avec « voir celles de toute l'équipe », voir
 * Facture::scopeVisiblesPar()), les avoirs de même (voir
 * Avoir::scopeVisiblesPar()), les achats et les avoirs fournisseur de
 * l'équipe, les clôtures qu'il a déclenchées ou toutes (voir
 * ClotureCaissePolicy), les encaissements qu'il a enregistrés ou tous (voir
 * PaiementPolicy) ; jamais un brouillon. Les jours sont ceux du calendrier
 * de chaque magasin : le jour d'émission d'une vente, d'un avoir et d'un
 * avoir fournisseur, la date de la facture du fournisseur d'un achat (à
 * défaut, pour un achat reçu par l'API, le jour de sa validation), la
 * journée d'une clôture, le jour d'enregistrement d'un paiement. Les
 * montants sont ceux qu'enregistre chaque document, additionnés en
 * centimes pour un relevé des encaissements, jamais par la base.
 */
final class DocumentsDeLaPeriode
{
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
     * il télécharge, les magasins réels d'abord, par nom.
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
            ->filter(fn (Magasin $magasin): bool => $this->peutTelechargerDans($compte, $magasin))
            ->values();
    }

    /**
     * Les magasins réels de ce client lié que le cabinet ouvre au compte
     * (son propriétaire, ou le collaborateur à qui il est confié, voir
     * AccesDesCabinets::magasinsOuverts()) et où le client accorde au
     * cabinet le téléchargement par période.
     *
     * @return Collection<int, Magasin>
     */
    public function magasinsDuClient(User $compte, Societe $client): Collection
    {
        if (! $compte->estDUnCabinet()) {
            return collect();
        }

        return app(AccesDesCabinets::class)->magasinsOuverts($compte)
            ->where('societe_id', $client->getKey())
            ->filter(fn (Magasin $magasin): bool => $this->peutTelechargerDans($compte, $magasin))
            ->values();
    }

    /**
     * A-t-il, dans ce magasin, l'autorisation de télécharger les documents
     * par période, posée à la Gate dans ce magasin ? Jamais dans un magasin
     * réel d'une société qui n'est pas validée, qui n'a aucun document réel.
     */
    public function peutTelechargerDans(User $compte, Magasin $magasin): bool
    {
        if (! $magasin->est_demonstration && ! $magasin->societe->estValidee()) {
            return false;
        }

        return $this->dansLeMagasin($magasin, fn (): bool => Gate::forUser($compte)->allows(PermissionRegistry::DOCUMENTS_TELECHARGER));
    }

    /**
     * Les types de documents qu'il voit dans ce magasin, dans l'ordre de
     * l'énumération, ceux qui existent dans le type d'activité de sa
     * société seulement.
     *
     * @return list<TypeDeDocumentTelechargeable>
     */
    public function typesVisibles(User $compte, Magasin $magasin): array
    {
        return $this->dansLeMagasin($magasin, fn (): array => array_values(array_filter(
            TypeDeDocumentTelechargeable::cases(),
            fn (TypeDeDocumentTelechargeable $type): bool => $type->existeDans($magasin->societe->type_activite) && Gate::forUser($compte)->allows('viewAny', $type->modele()),
        )));
    }

    /**
     * Les documents de ces types, du jour $du au jour $au compris, dans
     * chacun de ces magasins où il télécharge, ceux qu'il peut voir
     * seulement, magasin par magasin puis type par type, chacun dans
     * l'ordre de ses jours.
     *
     * @param  iterable<Magasin>  $magasins
     * @param  list<TypeDeDocumentTelechargeable>  $types
     * @return list<DocumentTrouve>
     */
    public function trouver(User $compte, iterable $magasins, string $du, string $au, array $types, OrigineDesAchats $origine): array
    {
        $documents = [];

        foreach ($magasins as $magasin) {
            if (! $this->peutTelechargerDans($compte, $magasin)) {
                continue;
            }

            $visibles = array_values(array_filter($this->typesVisibles($compte, $magasin), fn (TypeDeDocumentTelechargeable $type): bool => in_array($type, $types, true)));
            $documents = [...$documents, ...$this->dansLeMagasin($magasin, fn (): array => $this->duMagasin($compte, $magasin, $du, $au, $visibles, $origine))];
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
    public function choisis(User $compte, iterable $magasins, string $du, string $au, array $types, OrigineDesAchats $origine, array $cles): array
    {
        $choisies = array_flip($cles);

        return array_values(array_filter(
            $this->trouver($compte, $magasins, $du, $au, $types, $origine),
            fn (DocumentTrouve $document): bool => isset($choisies[$document->cle()]),
        ));
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
            ->when(! Gate::forUser($compte)->allows(PermissionRegistry::FACTURE_VIEW_TOUTES), fn (Builder $paiements): Builder => $paiements->where('cree_par', $compte->getKey()))
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Le dernier jour de ce mois (AAAA-MM).
     */
    public static function dernierJourDuMois(string $mois): string
    {
        return Calendrier::ajouterJours(Calendrier::jourDuMoisSuivant($mois.'-01', 1), -1);
    }

    /**
     * @param  list<TypeDeDocumentTelechargeable>  $types
     * @return list<DocumentTrouve>
     */
    private function duMagasin(User $compte, Magasin $magasin, string $du, string $au, array $types, OrigineDesAchats $origine): array
    {
        $calendrier = $magasin->calendrier();
        [$debut] = $calendrier->bornesDuJour($du);
        [, $fin] = $calendrier->bornesDuJour($au);
        $nom = "{$magasin->nom} ({$magasin->code})";
        $documents = [];
        $document = fn (TypeDeDocumentTelechargeable $type, string|int $identifiant, ?string $numero, string $jour, ?string $tiers, mixed $ttc, ?string $origine = null): DocumentTrouve => new DocumentTrouve($type, $magasin->getKey(), $nom, (string) $identifiant, (string) $numero, $jour, $tiers, is_int($ttc) ? $ttc : $this->calculateur->enCentiemes($ttc), $origine);

        foreach ($types as $type) {
            $documents = [...$documents, ...match ($type) {
                TypeDeDocumentTelechargeable::Ventes => Facture::query()->emises()->visiblesPar($compte)
                    ->where('date_emission', '>=', $debut)->where('date_emission', '<', $fin)
                    ->orderBy('date_emission')->orderBy('id')
                    ->get(['id', 'numero', 'date_emission', 'client_nom', 'total_ttc'])
                    ->map(fn (Facture $facture): DocumentTrouve => $document($type, $facture->id, $facture->numero, $calendrier->jourDe($facture->date_emission), $facture->client_nom, $facture->total_ttc))
                    ->all(),
                TypeDeDocumentTelechargeable::AvoirsClients => Avoir::query()->where('statut', StatutAvoir::Emise)->visiblesPar($compte)
                    ->where('date_emission', '>=', $debut)->where('date_emission', '<', $fin)
                    ->orderBy('date_emission')->orderBy('id')
                    ->get(['id', 'numero', 'date_emission', 'client_nom', 'total_ttc'])
                    ->map(fn (Avoir $avoir): DocumentTrouve => $document($type, $avoir->id, $avoir->numero, $calendrier->jourDe($avoir->date_emission), $avoir->client_nom, $avoir->total_ttc))
                    ->all(),
                TypeDeDocumentTelechargeable::FacturesAchat => FactureAchat::query()->with('fournisseur')
                    ->where('statut', StatutFactureAchat::Validee)
                    ->whereIn('source', $origine->sources())
                    ->where(fn (Builder $factures): Builder => $factures->whereBetween('date_facture', [$du, $au])
                        ->orWhere(fn (Builder $sansDate): Builder => $sansDate->whereNull('date_facture')->where('date_validation', '>=', $debut)->where('date_validation', '<', $fin)))
                    ->orderBy('rang_validation')
                    ->get()
                    ->map(fn (FactureAchat $facture): DocumentTrouve => $document($type, $facture->id, $facture->numero, $facture->date_facture ?? $calendrier->jourDe($facture->date_validation), $facture->fournisseur->nom, $facture->total_ttc, $facture->source->getLabel()))
                    ->sortBy(fn (DocumentTrouve $trouve): string => $trouve->jour)
                    ->values()
                    ->all(),
                TypeDeDocumentTelechargeable::AvoirsFournisseur => AvoirFournisseur::query()->with('factureAchat.fournisseur')
                    ->where('statut', StatutAvoirFournisseur::Emise)
                    ->whereIn('source', $origine->sources())
                    ->where('date_emission', '>=', $debut)->where('date_emission', '<', $fin)
                    ->orderBy('date_emission')->orderBy('numero')
                    ->get()
                    ->map(fn (AvoirFournisseur $avoir): DocumentTrouve => $document($type, $avoir->id, $avoir->numero, $calendrier->jourDe($avoir->date_emission), $avoir->factureAchat->fournisseur->nom, $avoir->total_ttc, $avoir->source->getLabel()))
                    ->all(),
                TypeDeDocumentTelechargeable::Clotures => ClotureCaisse::query()
                    ->whereBetween('date_cloture', [$du, $au])
                    ->when(! Gate::forUser($compte)->allows(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES), fn (Builder $clotures): Builder => $clotures->where('cree_par', $compte->getKey()))
                    ->orderBy('date_cloture')
                    ->get(['id', 'numero', 'date_cloture', 'total_ttc'])
                    ->map(fn (ClotureCaisse $cloture): DocumentTrouve => $document($type, $cloture->id, $cloture->numero, $cloture->date_cloture, null, $cloture->total_ttc))
                    ->all(),
                TypeDeDocumentTelechargeable::Encaissements => $this->relevesDesEncaissements($compte, $calendrier, $du, $au, $document),
            }];
        }

        return $documents;
    }

    /**
     * Un relevé des encaissements par mois qui a des paiements que la
     * personne voit dans la période : son jour est le dernier de ce mois
     * dans la période, son TTC le total encaissé, contre-passations
     * déduites.
     *
     * @param  callable(TypeDeDocumentTelechargeable, string|int, ?string, string, ?string, mixed, ?string=): DocumentTrouve  $document
     * @return list<DocumentTrouve>
     */
    private function relevesDesEncaissements(User $compte, Calendrier $calendrier, string $du, string $au, callable $document): array
    {
        return $this->paiementsVisibles($compte, $calendrier, $du, $au)
            ->get(['id', 'montant', 'created_at'])
            ->groupBy(fn (Paiement $paiement): string => substr($calendrier->jourDe($paiement->created_at), 0, 7))
            ->map(fn (Collection $paiements, string $mois): DocumentTrouve => $document(
                TypeDeDocumentTelechargeable::Encaissements,
                $mois,
                'Encaissements '.$mois,
                min($au, self::dernierJourDuMois($mois)),
                null,
                (int) $paiements->sum(fn (Paiement $paiement): int => $this->calculateur->enCentiemes($paiement->montant)),
            ))
            ->values()
            ->all();
    }
}
