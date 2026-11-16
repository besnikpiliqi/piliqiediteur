<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\TauxAvoirFournisseur;
use App\Domain\Achats\Models\TauxFactureAchat;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Models\LigneDeCharge;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Fichiers\CelluleDeTableur;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ResultatDePhp;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * Le récapitulatif du téléchargement par période, « Récapitulatif (CSV) »
 * (décision de l'éditeur du 03/11/2026, E2) : un fichier CSV qui s'ouvre
 * dans Excel (UTF-8 avec sa marque d'ordre des octets, séparateur « ; »,
 * virgule décimale, jours JJ/MM/AAAA), une ligne par document et par taux
 * de TVA : son type, son magasin, son numéro, son jour, son tiers (nom et
 * identifiant tels que le document les a figés ; pour un achat, son
 * fournisseur), l'origine d'un achat et sa marque « pièce du fournisseur
 * manquante » (décision de l'éditeur du 03/11/2026, F1 = b), la base HT, le
 * taux, la TVA et le TTC de chaque taux, puis, sur la première ligne d'une
 * vente, ce qui en était payé et ce qui en restait dû à la fin du dernier
 * jour de la période, dans le calendrier de son magasin. Une clôture du jour donne le net de
 * chaque taux de ses ventes aux particuliers ; un relevé des encaissements
 * une ligne par mode, avec son mode, et une ligne pour le crédit d'avoir
 * utilisé ; une charge de la société (décision de l'éditeur du
 * 15/11/2026, lot DATEOP), une ligne par taux (une seule, sans montants,
 * pour une pièce sans chiffres), avec les colonnes de la feuille « Liste
 * des charges » de l'état des charges (mois de TVA, type, date de
 * paiement, TVA déductible, autoliquidation, état, validée par et le,
 * motif du renvoi), son origine, et « Manquante » dans la colonne de la
 * pièce quand elle n'en a aucune à servir ; un texte qui commencerait
 * comme une formule est désamorcé (voir CelluleDeTableur). Les montants sont ceux qu'enregistre chaque document,
 * additionnés en centimes par taux, jamais recalculés ni additionnés par la
 * base. Chaque document se relit dans son magasin, comme magasin actif
 * (voir DocumentsDeLaPeriode::dansLeMagasin()), les documents d'un magasin
 * lus ensemble, type par type, avec leurs lignes et leurs règlements : le
 * nombre de requêtes ne dépend pas du nombre de documents.
 */
final class RecapitulatifDesDocuments
{
    public const COLONNES = ['Type', 'Magasin', 'Numéro', 'Date', 'Tiers', 'Identifiant du tiers', 'Origine', 'Pièce du fournisseur', 'Mode de paiement', 'Taux de TVA', 'Base HT', 'TVA', 'TTC', 'Payé à la fin de la période', 'Restant dû à la fin de la période', ...self::COLONNES_DES_CHARGES];

    /**
     * Les colonnes que seule une charge remplit, à la fin de chaque ligne.
     */
    public const COLONNES_DES_CHARGES = ['Mois de TVA', 'Type de charge', 'Date de paiement', 'TVA déductible', 'Autoliquidation', 'État', 'Validée par', 'Validée le', 'Motif du renvoi'];

    /**
     * Ce que la colonne « Pièce du fournisseur » dit d'un achat saisi à la
     * main sans sa pièce, ou d'une charge sans pièce à servir.
     */
    public const PIECE_MANQUANTE = 'Manquante';

    public function __construct(
        private readonly CalculateurLigne $calculateur,
        private readonly DocumentsDeLaPeriode $documents,
    ) {}

    /**
     * Le contenu du fichier CSV de ces documents.
     *
     * @param  list<DocumentTrouve>  $documents
     */
    public function ecrire(User $compte, array $documents, string $du, string $au): string
    {
        $flux = ResultatDePhp::flux(fopen('php://temp', 'r+'), 'L\'ouverture du fichier temporaire du récapitulatif');
        fwrite($flux, "\xEF\xBB\xBF");
        fputcsv($flux, self::COLONNES, ';', '"', '', "\r\n");
        $charges = collect($documents)->filter(self::estUneCharge(...))->values();
        $autres = collect($documents)->reject(self::estUneCharge(...));
        $lues = $this->charges($charges);

        foreach ($charges as $document) {
            foreach ($this->lignesDUneCharge($document, $lues->get((int) $document->identifiant) ?? throw (new ModelNotFoundException)->setModel(Charge::class, [$document->identifiant])) as $ligne) {
                fputcsv($flux, $ligne, ';', '"', '', "\r\n");
            }
        }

        $parMagasin = $autres->groupBy(fn (DocumentTrouve $document): int => $document->magasinId);
        $magasins = Magasin::query()->with('societe')->whereKey($parMagasin->keys()->all())->get()->keyBy(fn (Magasin $magasin): int => CleDuModele::de($magasin));

        foreach ($parMagasin as $magasinId => $duMagasin) {
            $magasin = $magasins->get($magasinId) ?? throw (new ModelNotFoundException)->setModel(Magasin::class, [$magasinId]);

            $this->documents->dansLeMagasin($magasin, function () use ($compte, $magasin, $duMagasin, $du, $au, $flux): void {
                $lus = $this->lire($compte, $magasin, $duMagasin, $du, $au);

                foreach ($duMagasin as $document) {
                    foreach ($this->lignes($document, $lus) as $ligne) {
                        fputcsv($flux, $ligne, ';', '"', '', "\r\n");
                    }
                }
            });
        }

        rewind($flux);
        $contenu = (string) stream_get_contents($flux);
        fclose($flux);

        return $contenu;
    }

    /**
     * Ces charges, lues ensemble avec leurs lignes, leur type de la société
     * et leur pièce, dans la société de chacune, hors de son scope, filtrées
     * par elle, par identifiant.
     *
     * @param  Collection<int, DocumentTrouve>  $charges
     * @return Collection<int, Charge>
     */
    private function charges(Collection $charges): Collection
    {
        $lues = new Collection;

        foreach ($charges->groupBy(fn (DocumentTrouve $charge): int => (int) $charge->societeId) as $societe => $deLaSociete) {
            $lues = $lues->union(Charge::query()->withoutGlobalScope(SocieteScope::class)
                ->where('societe_id', $societe)
                ->whereKey($deLaSociete->map(fn (DocumentTrouve $charge): int => (int) $charge->identifiant)->all())
                ->with([
                    'lignes' => fn (HasMany $lignes): HasMany => $lignes->withoutGlobalScope(SocieteScope::class)->where('lignes_de_charge.societe_id', $societe),
                    'piece' => fn (HasOne $piece): HasOne => $piece->withoutGlobalScope(SocieteScope::class)->where('pieces_de_charge.societe_id', $societe)->whereNull('bloquee_le'),
                    'typeDeLaSociete' => fn (BelongsTo $type): BelongsTo => $type->withoutGlobalScope(SocieteScope::class)->where('types_de_charge_de_la_societe.societe_id', $societe),
                ])
                ->get()
                ->keyBy(fn (Charge $charge): int => CleDuModele::de($charge))
                ->toBase());
        }

        return $lues;
    }

    /**
     * Les lignes d'une charge : une par taux, ou une seule, sans montants,
     * pour une pièce sans chiffres.
     *
     * @return list<list<string>>
     */
    private function lignesDUneCharge(DocumentTrouve $document, Charge $charge): array
    {
        $colonnes = [
            $charge->mois_tva,
            CelluleDeTableur::texte($charge->libelleDuType()),
            $charge->date_paiement === null ? '' : Calendrier::afficherJour($charge->date_paiement, 'd/m/Y'),
        ];
        $etat = [
            $charge->fournisseur_hors_france ? 'Oui' : 'Non',
            $charge->etat->getLabel(),
            $charge->validee_par?->getLabel() ?? '',
            ($validation = $charge->jourDeValidation()) === null ? '' : Calendrier::afficherJour($validation, 'd/m/Y'),
            CelluleDeTableur::texte($charge->motif_renvoi ?? ''),
        ];
        $debut = fn (): array => [
            $document->type->libelleDUnDocument(),
            CelluleDeTableur::texte($document->magasin),
            CelluleDeTableur::texte($document->numero),
            Calendrier::afficherJour($document->jour, 'd/m/Y'),
            CelluleDeTableur::texte($document->tiers ?? ''),
            '',
            $charge->origine->getLabel(),
            $charge->piece === null ? self::PIECE_MANQUANTE : '',
            '',
        ];

        if ($charge->etat === EtatDeLaCharge::PieceSansChiffres || $charge->lignes->isEmpty()) {
            return [[...$debut(), '', '', '', '', '', '', ...$colonnes, '', ...$etat]];
        }

        return array_values($charge->lignes->map(fn (LigneDeCharge $ligne): array => [
            ...$debut(),
            self::taux($ligne->taux->value),
            self::montant($ligne->ht),
            self::montant($ligne->tva),
            self::montant($ligne->ttc),
            '',
            '',
            ...$colonnes,
            self::montant($ligne->tva_deductible),
            ...$etat,
        ])->all());
    }

    /**
     * Les documents de ce magasin actif, lus ensemble type par type : les
     * ventes avec leurs lignes et ce qui en était payé à la fin de la
     * période, les avoirs avec leurs lignes, les achats et les avoirs
     * fournisseur avec leurs taux et leur fournisseur, les clôtures, et,
     * pour les relevés, les paiements que la personne voit et le crédit
     * d'avoir utilisé de toute la période, par mois.
     *
     * @param  Collection<int, DocumentTrouve>  $documents
     * @return array{ventes: Collection<int, Facture>, paye: array<int, int>, avoirs: Collection<int, Avoir>, achats: Collection<int, FactureAchat>, avoirsFournisseur: Collection<int, AvoirFournisseur>, clotures: Collection<int, ClotureCaisse>, paiements: Collection<array-key, Collection<int, Paiement>>|null, credits: array<array-key, int>}
     */
    private function lire(User $compte, Magasin $magasin, Collection $documents, string $du, string $au): array
    {
        $identifiants = fn (TypeDeDocumentTelechargeable $type): array => $documents->filter(fn (DocumentTrouve $document): bool => $document->type === $type)
            ->map(fn (DocumentTrouve $document): int => (int) $document->identifiant)
            ->values()
            ->all();
        $calendrier = $magasin->calendrier();
        [$debut] = $calendrier->bornesDuJour($du);
        [, $fin] = $calendrier->bornesDuJour($au);
        $ventes = self::lesDocuments(Facture::query()->with('lignes'), $identifiants(TypeDeDocumentTelechargeable::Ventes));
        $releves = $documents->contains(fn (DocumentTrouve $document): bool => $document->type === TypeDeDocumentTelechargeable::Encaissements);
        $parMois = fn (?DateTimeInterface $instant): string => substr($calendrier->jourDe(ValeurRequise::de($instant, 'l\'instant d\'enregistrement')), 0, 7);

        return [
            'ventes' => $ventes,
            'paye' => $this->payes(ValeursLues::entiers($ventes->keys()), $fin),
            'avoirs' => self::lesDocuments(Avoir::query()->with('lignes'), $identifiants(TypeDeDocumentTelechargeable::AvoirsClients)),
            'achats' => self::lesDocuments(FactureAchat::query()->with(['taux', 'fournisseur']), $identifiants(TypeDeDocumentTelechargeable::FacturesAchat)),
            'avoirsFournisseur' => self::lesDocuments(AvoirFournisseur::query()->with(['taux', 'factureAchat.fournisseur']), $identifiants(TypeDeDocumentTelechargeable::AvoirsFournisseur)),
            'clotures' => self::lesDocuments(ClotureCaisse::query(), $identifiants(TypeDeDocumentTelechargeable::Clotures)),
            'paiements' => $releves ? $this->documents->paiementsVisibles($compte, $calendrier, $du, $au)->get(['id', 'mode_paiement', 'montant', 'created_at'])->toBase()->groupBy(fn (Paiement $paiement): string => $parMois($paiement->created_at)) : null,
            'credits' => $releves ? AvoirAllocation::query()
                ->where('created_at', '>=', $debut)
                ->where('created_at', '<', $fin)
                ->whereIn('facture_id', Facture::query()->visiblesPar($compte)->select('id'))
                ->get(['montant_alloue', 'created_at'])
                ->groupBy(fn (AvoirAllocation $allocation): string => $parMois($allocation->created_at))
                ->map(fn (Collection $allocations): int => (int) $allocations->sum(fn (AvoirAllocation $allocation): int => $this->calculateur->enCentiemes($allocation->montant_alloue)))
                ->all() : [],
        ];
    }

    /**
     * Ces documents du magasin actif, par identifiant ; aucun sans
     * identifiant.
     *
     * @template TDocument of Model
     *
     * @param  Builder<TDocument>  $requete
     * @param  array<int, int>  $identifiants
     * @return Collection<int, TDocument>
     */
    private static function lesDocuments(Builder $requete, array $identifiants): Collection
    {
        return $identifiants === [] ? new Collection : $requete->whereKey($identifiants)->get()->keyBy(fn (Model $document): int => CleDuModele::de($document))->toBase();
    }

    /**
     * Les lignes d'un document.
     *
     * @param  array{ventes: Collection<int, Facture>, paye: array<int, int>, avoirs: Collection<int, Avoir>, achats: Collection<int, FactureAchat>, avoirsFournisseur: Collection<int, AvoirFournisseur>, clotures: Collection<int, ClotureCaisse>, paiements: Collection<array-key, Collection<int, Paiement>>|null, credits: array<array-key, int>}  $lus
     * @return list<list<string>>
     */
    private function lignes(DocumentTrouve $document, array $lus): array
    {
        switch ($document->type) {
            case TypeDeDocumentTelechargeable::Ventes:
                $facture = self::lu($lus['ventes'], $document);
                $paye = $lus['paye'][CleDuModele::de($facture)] ?? 0;

                return array_values($this->parTaux($facture->lignes)->values()->map(fn (array $taux, int $rang): array => self::ligne(
                    $document,
                    $taux,
                    identifiant: self::identifiantDuClient($facture),
                    reglement: $rang === 0 ? [$paye, $this->calculateur->enCentiemes($facture->total_ttc) - $paye] : null,
                ))->all());
            case TypeDeDocumentTelechargeable::AvoirsClients:
                $avoir = self::lu($lus['avoirs'], $document);

                return array_values($this->parTaux($avoir->lignes)->map(fn (array $taux): array => self::ligne($document, $taux, identifiant: self::identifiantDuClient($avoir)))->all());
            case TypeDeDocumentTelechargeable::FacturesAchat:
            case TypeDeDocumentTelechargeable::AvoirsFournisseur:
                $achat = $document->type === TypeDeDocumentTelechargeable::FacturesAchat ? self::lu($lus['achats'], $document) : self::lu($lus['avoirsFournisseur'], $document);
                $fournisseur = ValeurRequise::de(($achat instanceof FactureAchat ? $achat : ValeurRequise::de($achat->factureAchat, 'la facture d\'achat de l\'avoir fournisseur'))->fournisseur, 'le fournisseur de la facture d\'achat');

                return array_values($achat->taux->map(fn (TauxFactureAchat|TauxAvoirFournisseur $taux): array => self::ligne($document, [
                    'taux' => self::taux($this->calculateur->enCentiemes($taux->taux_tva)),
                    'ht' => $this->calculateur->enCentiemes($taux->base_ht),
                    'tva' => $this->calculateur->enCentiemes($taux->montant_tva),
                    'ttc' => $this->calculateur->enCentiemes($taux->base_ht) + $this->calculateur->enCentiemes($taux->montant_tva),
                ], identifiant: self::identifiantDuFournisseur($fournisseur)))->all());
            case TypeDeDocumentTelechargeable::Clotures:
                $nets = self::lu($lus['clotures'], $document)->netParTaux();

                return $nets === []
                    ? [self::ligne($document, ['ht' => 0, 'tva' => 0, 'ttc' => 0])]
                    : array_map(fn (array $taux): array => self::ligne($document, [...$taux, 'taux' => self::taux($taux['taux'])]), $nets);
            case TypeDeDocumentTelechargeable::Charges:
                return [];
            case TypeDeDocumentTelechargeable::Encaissements:
                return array_map(
                    fn (array $encaisse): array => self::ligne($document, ['ttc' => $encaisse['ttc']], mode: $encaisse['mode']),
                    $this->encaissementsDuReleve(ValeurRequise::de($lus['paiements'], 'les paiements des relevés')->get($document->identifiant, new Collection), $lus['credits'][$document->identifiant] ?? 0),
                );
        }
    }

    /**
     * Une ligne du récapitulatif : le document, puis ce taux (ou le seul
     * TTC d'un relevé), et, sur la première ligne d'une vente, ce qui en
     * était payé et ce qui en restait dû.
     *
     * @param  array{taux?: string, ht?: int, tva?: int, ttc: int}  $taux
     * @param  array{int, int}|null  $reglement
     * @return list<string>
     */
    private static function ligne(DocumentTrouve $document, array $taux, ?string $identifiant = null, ?string $mode = null, ?array $reglement = null): array
    {
        return [
            $document->type->libelleDUnDocument(),
            CelluleDeTableur::texte($document->magasin),
            CelluleDeTableur::texte($document->numero),
            Calendrier::afficherJour($document->jour, 'd/m/Y'),
            CelluleDeTableur::texte($document->tiers ?? ''),
            CelluleDeTableur::texte($identifiant ?? ''),
            $document->origine ?? '',
            $document->pieceManquante ? self::PIECE_MANQUANTE : '',
            $mode ?? '',
            $taux['taux'] ?? '',
            isset($taux['ht']) ? self::montant($taux['ht']) : '',
            isset($taux['tva']) ? self::montant($taux['tva']) : '',
            self::montant($taux['ttc']),
            $reglement === null ? '' : self::montant($reglement[0]),
            $reglement === null ? '' : self::montant($reglement[1]),
            ...array_fill(0, count(self::COLONNES_DES_CHARGES), ''),
        ];
    }

    /**
     * Ce que le relevé encaisse : un montant par mode qui a des paiements,
     * puis le crédit d'avoir utilisé, s'il y en a.
     *
     * @param  Collection<int, Paiement>  $paiements  les paiements du mois que la personne voit
     * @return list<array{ttc: int, mode: string}>
     */
    private function encaissementsDuReleve(Collection $paiements, int $credit): array
    {
        $encaissements = [];

        foreach ([...ModePaiement::aLaCaisse(), ModePaiement::Prelevement] as $mode) {
            $duMode = $paiements->filter(fn (Paiement $paiement): bool => $paiement->mode_paiement === $mode);

            if ($duMode->isNotEmpty()) {
                $encaissements[] = ['ttc' => (int) $duMode->sum(fn (Paiement $paiement): int => $this->calculateur->enCentiemes($paiement->montant)), 'mode' => $mode->getLabel()];
            }
        }

        if ($credit !== 0) {
            $encaissements[] = ['ttc' => $credit, 'mode' => 'Avoir (crédit utilisé)'];
        }

        return $encaissements;
    }

    /**
     * Les montants de ces lignes, additionnés en centimes taux par taux,
     * du plus fort au plus faible.
     *
     * @template TLigne of LigneFacture|LigneAvoir
     *
     * @param  Collection<int, TLigne>  $lignes
     * @return Collection<string, array{taux: string, ht: int, tva: int, ttc: int}>
     */
    private function parTaux(Collection $lignes): Collection
    {
        return $lignes
            ->groupBy(fn (LigneFacture|LigneAvoir $ligne): string => $this->calculateur->enDecimal($this->calculateur->enCentiemes($ligne->taux_tva)))
            ->sortKeysUsing(fn (string $a, string $b): int => $this->calculateur->enCentiemes($b) <=> $this->calculateur->enCentiemes($a))
            ->map(fn (Collection $duTaux, string $taux): array => [
                'taux' => self::taux($this->calculateur->enCentiemes($taux)),
                'ht' => (int) $duTaux->sum(fn (LigneFacture|LigneAvoir $ligne): int => $this->calculateur->enCentiemes($ligne->montant_ht)),
                'tva' => (int) $duTaux->sum(fn (LigneFacture|LigneAvoir $ligne): int => $this->calculateur->enCentiemes($ligne->montant_tva)),
                'ttc' => (int) $duTaux->sum(fn (LigneFacture|LigneAvoir $ligne): int => $this->calculateur->enCentiemes($ligne->montant_ttc)),
            ]);
    }

    /**
     * Ce que chacune de ces factures avait reçu avant cet instant : ses
     * paiements et le crédit d'avoir imputé, contre-passations déduites, en
     * centimes, par facture.
     *
     * @param  list<int>  $factures
     * @return array<int, int>
     */
    private function payes(array $factures, mixed $instant): array
    {
        if ($factures === []) {
            return [];
        }

        $payes = [];
        $montants = PaiementAllocation::query()->whereIn('facture_id', $factures)->where('created_at', '<', $instant)->get(['facture_id', 'montant_alloue'])
            ->concat(AvoirAllocation::query()->whereIn('facture_id', $factures)->where('created_at', '<', $instant)->get(['facture_id', 'montant_alloue']));

        foreach ($montants as $allocation) {
            $payes[(int) $allocation->facture_id] = ($payes[(int) $allocation->facture_id] ?? 0) + $this->calculateur->enCentiemes($allocation->montant_alloue);
        }

        return $payes;
    }

    /**
     * Le document lu que désigne ce document trouvé.
     *
     * @template TModele of Model
     *
     * @param  Collection<int, TModele>  $lus
     * @return TModele
     */
    private static function lu(Collection $lus, DocumentTrouve $document): Model
    {
        return $lus->get((int) $document->identifiant) ?? throw (new ModelNotFoundException)->setModel($document->type->modele(), [$document->identifiant]);
    }

    private static function identifiantDuClient(Facture|Avoir $document): string
    {
        return $document->client_siret ?? $document->client_siren ?? $document->client_numero_tva ?? $document->client_identifiant_etranger ?? '';
    }

    private static function identifiantDuFournisseur(Fournisseur $fournisseur): string
    {
        return $fournisseur->siret ?? $fournisseur->numero_tva ?? '';
    }

    /**
     * Un taux en centièmes de point tel qu'il s'écrit : « 20 », « 5,5 ».
     */
    private static function taux(int $taux): string
    {
        return str_replace('.', ',', CalculateurLigne::centiemesDansUnChamp($taux));
    }

    /**
     * Un montant en centimes tel que l'écrit Excel en français : « 1234,56 »,
     * « -12,00 », sans séparateur des milliers.
     */
    private static function montant(int $centimes): string
    {
        $absolu = abs($centimes);

        return ($centimes < 0 ? '-' : '').intdiv($absolu, 100).','.str_pad((string) ($absolu % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Ce document est-il une charge, dont la pièce et les lignes se lisent
     * dans la société et non dans un magasin ?
     */
    private static function estUneCharge(DocumentTrouve $document): bool
    {
        return $document->type === TypeDeDocumentTelechargeable::Charges;
    }
}
