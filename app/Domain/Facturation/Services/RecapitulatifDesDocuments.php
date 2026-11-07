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
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Le récapitulatif du téléchargement par période, « Récapitulatif (CSV) »
 * (décision de l'éditeur du 03/11/2026, E2) : un fichier CSV qui s'ouvre
 * dans Excel (UTF-8 avec sa marque d'ordre des octets, séparateur « ; »,
 * virgule décimale, jours JJ/MM/AAAA), une ligne par document et par taux
 * de TVA : son type, son magasin, son numéro, son jour, son tiers (nom et
 * identifiant tels que le document les a figés ; pour un achat, son
 * fournisseur), l'origine d'un achat, la base HT, le taux, la TVA et le TTC
 * de chaque taux, puis, sur la première ligne d'une vente, ce qui en était
 * payé et ce qui en restait dû à la fin du dernier jour de la période,
 * dans le calendrier de son magasin. Une clôture du jour donne le net de
 * chaque taux de ses ventes aux particuliers ; un relevé des encaissements
 * une ligne par mode, avec son mode, et une ligne pour le crédit d'avoir
 * utilisé ; un texte qui commencerait comme une formule est désamorcé
 * (voir texte()). Les montants sont ceux qu'enregistre chaque document,
 * additionnés en centimes par taux, jamais recalculés ni additionnés par la
 * base. Chaque document se relit dans son magasin, comme magasin actif
 * (voir DocumentsDeLaPeriode::dansLeMagasin()).
 */
final class RecapitulatifDesDocuments
{
    public const COLONNES = ['Type', 'Magasin', 'Numéro', 'Date', 'Tiers', 'Identifiant du tiers', 'Origine', 'Mode de paiement', 'Taux de TVA', 'Base HT', 'TVA', 'TTC', 'Payé à la fin de la période', 'Restant dû à la fin de la période'];

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
        $flux = fopen('php://temp', 'r+');
        fwrite($flux, "\xEF\xBB\xBF");
        fputcsv($flux, self::COLONNES, ';', '"', '', "\r\n");

        foreach (collect($documents)->groupBy(fn (DocumentTrouve $document): int => $document->magasinId) as $magasinId => $duMagasin) {
            $magasin = Magasin::query()->findOrFail($magasinId);

            $this->documents->dansLeMagasin($magasin, function () use ($compte, $magasin, $duMagasin, $du, $au, $flux): void {
                foreach ($duMagasin as $document) {
                    foreach ($this->lignes($compte, $magasin, $document, $du, $au) as $ligne) {
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
     * Les lignes d'un document.
     *
     * @return list<list<string>>
     */
    private function lignes(User $compte, Magasin $magasin, DocumentTrouve $document, string $du, string $au): array
    {
        $ligne = fn (array $taux, ?string $tiers = null, ?string $identifiant = null, ?string $mode = null, ?array $reglement = null): array => [
            $document->type->libelleDUnDocument(),
            self::texte($document->magasin),
            self::texte($document->numero),
            Calendrier::afficherJour($document->jour, 'd/m/Y'),
            self::texte($tiers ?? $document->tiers ?? ''),
            self::texte($identifiant ?? ''),
            $document->origine ?? '',
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
                $facture = Facture::query()->with('lignes')->findOrFail($document->identifiant);
                $paye = $this->payeA($facture, $magasin->calendrier()->bornesDuJour($au)[1]);

                return $this->parTaux($facture->lignes)->values()->map(fn (array $taux, int $rang): array => $ligne(
                    $taux,
                    identifiant: self::identifiantDuClient($facture),
                    reglement: $rang === 0 ? [$paye, $this->calculateur->enCentiemes($facture->total_ttc) - $paye] : null,
                ))->all();
            case TypeDeDocumentTelechargeable::AvoirsClients:
                $avoir = Avoir::query()->with('lignes')->findOrFail($document->identifiant);

                return $this->parTaux($avoir->lignes)->values()->map(fn (array $taux): array => $ligne($taux, identifiant: self::identifiantDuClient($avoir)))->all();
            case TypeDeDocumentTelechargeable::FacturesAchat:
            case TypeDeDocumentTelechargeable::AvoirsFournisseur:
                $achat = $document->type === TypeDeDocumentTelechargeable::FacturesAchat
                    ? FactureAchat::query()->with(['taux', 'fournisseur'])->findOrFail($document->identifiant)
                    : AvoirFournisseur::query()->with(['taux', 'factureAchat.fournisseur'])->findOrFail($document->identifiant);
                $fournisseur = $achat instanceof FactureAchat ? $achat->fournisseur : $achat->factureAchat->fournisseur;

                return $achat->taux->map(fn (TauxFactureAchat|TauxAvoirFournisseur $taux): array => $ligne([
                    'taux' => self::taux($taux->taux_tva),
                    'ht' => $this->calculateur->enCentiemes($taux->base_ht),
                    'tva' => $this->calculateur->enCentiemes($taux->montant_tva),
                    'ttc' => $this->calculateur->enCentiemes($taux->base_ht) + $this->calculateur->enCentiemes($taux->montant_tva),
                ], identifiant: self::identifiantDuFournisseur($fournisseur)))->values()->all();
            case TypeDeDocumentTelechargeable::Clotures:
                $cloture = ClotureCaisse::query()->findOrFail($document->identifiant);
                $nets = $cloture->netParTaux();

                return $nets === []
                    ? [$ligne(['ht' => 0, 'tva' => 0, 'ttc' => 0])]
                    : array_map(fn (array $taux): array => $ligne([...$taux, 'taux' => self::taux($taux['taux'] / 100)]), $nets);
            case TypeDeDocumentTelechargeable::Encaissements:
                return $this->lignesDuReleve($compte, $magasin, $document, $du, $au, $ligne);
        }
    }

    /**
     * Une ligne par mode qui a des paiements, puis le crédit d'avoir
     * utilisé, s'il y en a.
     *
     * @param  callable(array<string, mixed>, ?string=, ?string=, ?string=, ?array<int, int>=): list<string>  $ligne
     * @return list<list<string>>
     */
    private function lignesDuReleve(User $compte, Magasin $magasin, DocumentTrouve $document, string $du, string $au, callable $ligne): array
    {
        $calendrier = $magasin->calendrier();
        $premier = max($du, $document->identifiant.'-01');
        $dernier = min($au, DocumentsDeLaPeriode::dernierJourDuMois($document->identifiant));
        $paiements = $this->documents->paiementsVisibles($compte, $calendrier, $premier, $dernier)->get(['id', 'mode_paiement', 'montant']);
        [$debut] = $calendrier->bornesDuJour($premier);
        [, $fin] = $calendrier->bornesDuJour($dernier);
        $credit = (int) AvoirAllocation::query()
            ->where('created_at', '>=', $debut)
            ->where('created_at', '<', $fin)
            ->whereIn('facture_id', Facture::query()->visiblesPar($compte)->select('id'))
            ->pluck('montant_alloue')
            ->sum(fn (mixed $montant): int => $this->calculateur->enCentiemes($montant));
        $lignes = [];

        foreach ([...ModePaiement::aLaCaisse(), ModePaiement::Prelevement] as $mode) {
            $duMode = $paiements->filter(fn ($paiement): bool => $paiement->mode_paiement === $mode);

            if ($duMode->isNotEmpty()) {
                $lignes[] = $ligne(['ttc' => (int) $duMode->sum(fn ($paiement): int => $this->calculateur->enCentiemes($paiement->montant))], mode: $mode->getLabel());
            }
        }

        if ($credit !== 0) {
            $lignes[] = $ligne(['ttc' => $credit], mode: 'Avoir (crédit utilisé)');
        }

        return $lignes;
    }

    /**
     * Les montants de ces lignes, additionnés en centimes taux par taux,
     * du plus fort au plus faible.
     *
     * @param  Collection<int, Model>  $lignes
     * @return Collection<string, array{taux: string, ht: int, tva: int, ttc: int}>
     */
    private function parTaux(Collection $lignes): Collection
    {
        return $lignes
            ->groupBy(fn (Model $ligne): string => number_format((float) $ligne->taux_tva, 2, '.', ''))
            ->sortKeysUsing(fn (string $a, string $b): int => (float) $b <=> (float) $a)
            ->map(fn (Collection $duTaux, string $taux): array => [
                'taux' => self::taux($taux),
                'ht' => (int) $duTaux->sum(fn (Model $ligne): int => $this->calculateur->enCentiemes($ligne->montant_ht)),
                'tva' => (int) $duTaux->sum(fn (Model $ligne): int => $this->calculateur->enCentiemes($ligne->montant_tva)),
                'ttc' => (int) $duTaux->sum(fn (Model $ligne): int => $this->calculateur->enCentiemes($ligne->montant_ttc)),
            ]);
    }

    /**
     * Ce que la facture avait reçu avant cet instant : ses paiements et le
     * crédit d'avoir imputé, contre-passations déduites, en centimes.
     */
    private function payeA(Facture $facture, DateTimeInterface $instant): int
    {
        return (int) PaiementAllocation::query()->where('facture_id', $facture->getKey())->where('created_at', '<', $instant)->pluck('montant_alloue')
            ->concat(AvoirAllocation::query()->where('facture_id', $facture->getKey())->where('created_at', '<', $instant)->pluck('montant_alloue'))
            ->sum(fn (mixed $montant): int => $this->calculateur->enCentiemes($montant));
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
