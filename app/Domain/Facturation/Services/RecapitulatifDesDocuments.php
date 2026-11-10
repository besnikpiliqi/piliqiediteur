<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\TauxAvoirFournisseur;
use App\Domain\Achats\Models\TauxFactureAchat;
use App\Domain\Calendrier\Calendrier;
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
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\ResultatDePhp;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
 * utilisé ; un texte qui commencerait comme une formule est désamorcé
 * (voir texte()). Les montants sont ceux qu'enregistre chaque document,
 * additionnés en centimes par taux, jamais recalculés ni additionnés par la
 * base. Chaque document se relit dans son magasin, comme magasin actif
 * (voir DocumentsDeLaPeriode::dansLeMagasin()), les documents d'un magasin
 * lus ensemble, type par type, avec leurs lignes et leurs règlements : le
 * nombre de requêtes ne dépend pas du nombre de documents.
 */
final class RecapitulatifDesDocuments
{
    public const COLONNES = ['Type', 'Magasin', 'Numéro', 'Date', 'Tiers', 'Identifiant du tiers', 'Origine', 'Pièce du fournisseur', 'Mode de paiement', 'Taux de TVA', 'Base HT', 'TVA', 'TTC', 'Payé à la fin de la période', 'Restant dû à la fin de la période'];

    /**
     * Ce que la colonne « Pièce du fournisseur » dit d'un achat saisi à la
     * main sans sa pièce.
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
        $parMagasin = collect($documents)->groupBy(fn (DocumentTrouve $document): int => $document->magasinId);
        $magasins = Magasin::query()->with('societe')->whereKey($parMagasin->keys()->all())->get()->keyBy(fn (Magasin $magasin): int => (int) $magasin->getKey());

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
     * Les documents de ce magasin actif, lus ensemble type par type : les
     * ventes avec leurs lignes et ce qui en était payé à la fin de la
     * période, les avoirs avec leurs lignes, les achats et les avoirs
     * fournisseur avec leurs taux et leur fournisseur, les clôtures, et,
     * pour les relevés, les paiements que la personne voit et le crédit
     * d'avoir utilisé de toute la période, par mois.
     *
     * @param  Collection<int, DocumentTrouve>  $documents
     * @return array{ventes: Collection<int, Facture>, paye: array<int, int>, avoirs: Collection<int, Avoir>, achats: Collection<int, FactureAchat>, avoirsFournisseur: Collection<int, AvoirFournisseur>, clotures: Collection<int, ClotureCaisse>, paiements: Collection<string, Collection<int, Paiement>>, credits: array<string, int>}
     */
    private function lire(User $compte, Magasin $magasin, Collection $documents, string $du, string $au): array
    {
        $identifiants = fn (TypeDeDocumentTelechargeable $type): array => $documents->filter(fn (DocumentTrouve $document): bool => $document->type === $type)
            ->map(fn (DocumentTrouve $document): int => (int) $document->identifiant)
            ->values()
            ->all();
        $lireLes = fn (TypeDeDocumentTelechargeable $type, Builder $requete): Collection => ($ids = $identifiants($type)) === [] ? collect() : $requete->whereKey($ids)->get()->keyBy(fn (Model $modele): int => (int) $modele->getKey());
        $calendrier = $magasin->calendrier();
        [$debut] = $calendrier->bornesDuJour($du);
        [, $fin] = $calendrier->bornesDuJour($au);
        $ventes = $lireLes(TypeDeDocumentTelechargeable::Ventes, Facture::query()->with('lignes'));
        $releves = $documents->contains(fn (DocumentTrouve $document): bool => $document->type === TypeDeDocumentTelechargeable::Encaissements);
        $parMois = fn (mixed $instant): string => substr($calendrier->jourDe($instant), 0, 7);

        return [
            'ventes' => $ventes,
            'paye' => $this->payes(ValeursLues::entiers($ventes->keys()), $fin),
            'avoirs' => $lireLes(TypeDeDocumentTelechargeable::AvoirsClients, Avoir::query()->with('lignes')),
            'achats' => $lireLes(TypeDeDocumentTelechargeable::FacturesAchat, FactureAchat::query()->with(['taux', 'fournisseur'])),
            'avoirsFournisseur' => $lireLes(TypeDeDocumentTelechargeable::AvoirsFournisseur, AvoirFournisseur::query()->with(['taux', 'factureAchat.fournisseur'])),
            'clotures' => $lireLes(TypeDeDocumentTelechargeable::Clotures, ClotureCaisse::query()),
            'paiements' => $releves ? $this->documents->paiementsVisibles($compte, $calendrier, $du, $au)->get(['id', 'mode_paiement', 'montant', 'created_at'])->groupBy(fn (Paiement $paiement): string => $parMois($paiement->created_at)) : collect(),
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
     * Les lignes d'un document.
     *
     * @param  array{ventes: Collection<int, Facture>, paye: array<int, int>, avoirs: Collection<int, Avoir>, achats: Collection<int, FactureAchat>, avoirsFournisseur: Collection<int, AvoirFournisseur>, clotures: Collection<int, ClotureCaisse>, paiements: Collection<string, Collection<int, Paiement>>, credits: array<string, int>}  $lus
     * @return list<list<string>>
     */
    private function lignes(DocumentTrouve $document, array $lus): array
    {
        $ligne = fn (array $taux, ?string $identifiant = null, ?string $mode = null, ?array $reglement = null): array => [
            $document->type->libelleDUnDocument(),
            self::texte($document->magasin),
            self::texte($document->numero),
            Calendrier::afficherJour($document->jour, 'd/m/Y'),
            self::texte($document->tiers ?? ''),
            self::texte($identifiant ?? ''),
            $document->origine ?? '',
            $document->pieceManquante ? self::PIECE_MANQUANTE : '',
            $mode ?? '',
            $taux['taux'] ?? '',
            isset($taux['ht']) ? self::montant($taux['ht']) : '',
            isset($taux['tva']) ? self::montant($taux['tva']) : '',
            self::montant($taux['ttc']),
            $reglement === null ? '' : self::montant($reglement[0]),
            $reglement === null ? '' : self::montant($reglement[1]),
        ];

        switch ($document->type) {
            case TypeDeDocumentTelechargeable::Ventes:
                $facture = self::lu($lus['ventes'], $document);
                $paye = $lus['paye'][(int) $facture->getKey()] ?? 0;

                return array_values($this->parTaux($facture->lignes)->values()->map(fn (array $taux, int $rang): array => $ligne(
                    $taux,
                    identifiant: self::identifiantDuClient($facture),
                    reglement: $rang === 0 ? [$paye, $this->calculateur->enCentiemes($facture->total_ttc) - $paye] : null,
                ))->all());
            case TypeDeDocumentTelechargeable::AvoirsClients:
                $avoir = self::lu($lus['avoirs'], $document);

                return array_values($this->parTaux($avoir->lignes)->map(fn (array $taux): array => $ligne($taux, identifiant: self::identifiantDuClient($avoir)))->all());
            case TypeDeDocumentTelechargeable::FacturesAchat:
            case TypeDeDocumentTelechargeable::AvoirsFournisseur:
                $achat = $document->type === TypeDeDocumentTelechargeable::FacturesAchat ? self::lu($lus['achats'], $document) : self::lu($lus['avoirsFournisseur'], $document);
                $fournisseur = $achat instanceof FactureAchat ? $achat->fournisseur : $achat->factureAchat->fournisseur;

                return array_values($achat->taux->map(fn (TauxFactureAchat|TauxAvoirFournisseur $taux): array => $ligne([
                    'taux' => self::taux($taux->taux_tva),
                    'ht' => $this->calculateur->enCentiemes($taux->base_ht),
                    'tva' => $this->calculateur->enCentiemes($taux->montant_tva),
                    'ttc' => $this->calculateur->enCentiemes($taux->base_ht) + $this->calculateur->enCentiemes($taux->montant_tva),
                ], identifiant: self::identifiantDuFournisseur($fournisseur)))->all());
            case TypeDeDocumentTelechargeable::Clotures:
                $nets = self::lu($lus['clotures'], $document)->netParTaux();

                return $nets === []
                    ? [$ligne(['ht' => 0, 'tva' => 0, 'ttc' => 0])]
                    : array_map(fn (array $taux): array => $ligne([...$taux, 'taux' => self::taux($taux['taux'] / 100)]), $nets);
            case TypeDeDocumentTelechargeable::Encaissements:
                return array_map(
                    fn (array $encaisse): array => $ligne(['ttc' => $encaisse['ttc']], mode: $encaisse['mode']),
                    $this->encaissementsDuReleve($lus['paiements']->get($document->identifiant, collect()), $lus['credits'][$document->identifiant] ?? 0),
                );
        }
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
            ->groupBy(fn (LigneFacture|LigneAvoir $ligne): string => number_format((float) $ligne->taux_tva, 2, '.', ''))
            ->sortKeysUsing(fn (string $a, string $b): int => (float) $b <=> (float) $a)
            ->map(fn (Collection $duTaux, string $taux): array => [
                'taux' => self::taux($taux),
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
     * Un taux tel qu'il s'écrit : « 20 », « 5,5 ».
     */
    private static function taux(mixed $taux): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format((float) $taux, 2, '.', ''), '0'), '.'));
    }

    /**
     * Un texte saisi par une personne (un nom de magasin, de client ou de
     * fournisseur, un numéro, un identifiant) tel qu'il s'écrit dans le
     * fichier : précédé d'une apostrophe quand il commence comme une
     * formule (=, +, -, @, une tabulation ou un retour chariot), qu'Excel
     * exécuterait à l'ouverture au lieu de l'afficher.
     */
    private static function texte(string $valeur): string
    {
        return preg_match('/^[=+\-@\t\r]/', $valeur) === 1 ? "'".$valeur : $valeur;
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
}
