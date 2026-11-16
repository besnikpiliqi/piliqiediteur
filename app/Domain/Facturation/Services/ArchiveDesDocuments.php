<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Services\FicheDeSaisiePdfGenerator;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Models\PieceDeCharge;
use App\Domain\Charges\Services\PiecesDesCharges;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Exceptions\AucunePieceDeChargeAServirException;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Fichiers\Exceptions\PieceBloqueeException;
use App\Domain\Fichiers\NomDeFichier;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Integrations\Antivirus\AntivirusInjoignableException;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Le fichier ZIP du téléchargement par période, « PDF (ZIP) » (décision de
 * l'éditeur du 03/11/2026, E2) : chaque document choisi, rangé par type
 * puis par mois de son jour (ventes/2026-10/FAC-PARIS-2026-000012.pdf,
 * achats/2026-10/…) :
 * - une vente ou un avoir client : son PDF tel qu'émis (voir
 *   FacturePdfGenerator et AvoirPdfGenerator) ;
 * - une facture d'achat ou un avoir fournisseur : ses pièces du
 *   fournisseur, telles que conservées, chacune sous notre numéro suivi de
 *   son nom réécrit avec l'extension de son type (voir NomDeFichier),
 *   chacune analysée de nouveau par l'antivirus quand sa dernière analyse
 *   date de plus de 24 heures, une pièce bloquée n'y entrant jamais (voir
 *   PiecesDuFournisseur::contenuServable()) ; sans pièce à servir, sa
 *   fiche de saisie marquée « Saisie PILIQI » (voir
 *   FicheDeSaisiePdfGenerator). Un antivirus injoignable fait échouer
 *   l'archive (voir PreparerLeTelechargementDesDocumentsJob) ;
 * - une clôture du jour : son ticket Z (voir ClotureCaissePdfGenerator) ;
 * - un relevé des encaissements : son PDF, par magasin et par mois (voir
 *   ReleveDesEncaissementsPdfGenerator) ;
 * - une charge (décision de l'éditeur du 15/11/2026, lot DATEOP) : sa
 *   pièce (PDF, JPEG ou PNG) telle qu'elle a été enregistrée, dans le
 *   dossier « Charges », nommée « <mois de TVA> <type> <fournisseur> <n°
 *   de pièce>.<ext> » (voir NomDeFichier::dUnFichierProduit()), analysée
 *   de nouveau par l'antivirus quand sa dernière analyse date de plus de
 *   24 heures, une pièce bloquée n'y entrant jamais (voir
 *   PiecesDesCharges::contenuServable()) ; une charge sans pièce n'y a
 *   rien, le récapitulatif CSV la dit « pièce manquante ». Ses charges se
 *   relisent dans leur société, hors de son scope, filtrées par elle. Des
 *   documents qui ne sont que des charges sans pièce à servir ne font
 *   aucune archive : c'est un refus (voir
 *   AucunePieceDeChargeAServirException), jamais une erreur à rejouer.
 * Chaque document se relit dans son magasin, comme magasin actif (voir
 * DocumentsDeLaPeriode::dansLeMagasin()) ; chaque fichier s'écrit d'abord dans un dossier
 * temporaire, effacé ensuite, pour ne jamais tenir toute l'archive en
 * mémoire. Deux fichiers du même nom dans un même dossier sont distingués
 * par leur rang (« (2) »).
 */
final class ArchiveDesDocuments
{
    public const AUCUN_DOCUMENT = 'Aucun des documents choisis ne vous est plus accessible.';

    /**
     * Le dossier des pièces des charges dans l'archive.
     */
    public const DOSSIER_DES_CHARGES = 'Charges';

    public function __construct(
        private readonly DocumentsDeLaPeriode $documents,
        private readonly FacturePdfGenerator $factures,
        private readonly AvoirPdfGenerator $avoirs,
        private readonly ClotureCaissePdfGenerator $clotures,
        private readonly ReleveDesEncaissementsPdfGenerator $releves,
        private readonly FicheDeSaisiePdfGenerator $fiches,
        private readonly PiecesDuFournisseur $pieces,
        private readonly PiecesDesCharges $piecesDesCharges,
    ) {}

    /**
     * Écrit l'archive de ces documents à ce chemin local, et rend les
     * identifiants des pièces du fournisseur et des pièces des charges
     * qu'elle contient, que son lien revoit avant de la servir (voir
     * TelechargerLesDocumentsController).
     *
     * @param  list<DocumentTrouve>  $documents
     * @return array{pieces: list<int>, piecesDeCharge: list<int>}
     *
     * @throws AucunePieceDeChargeAServirException quand aucun fichier n'y entre : tout autre document en a au moins un, et ces documents ne sont donc que des charges sans pièce à servir
     * @throws RuntimeException quand l'archive ne s'ouvre ou ne s'écrit pas
     * @throws AntivirusInjoignableException quand l'antivirus ne répond pas pour une pièce à analyser de nouveau
     */
    public function ecrire(User $compte, array $documents, string $du, string $au, string $chemin): array
    {
        $zip = new ZipArchive;

        if ($zip->open($chemin, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('L\'archive des documents ne s\'ouvre pas.');
        }

        $temporaire = storage_path('framework/cache/telechargement-'.Str::random(32));
        File::ensureDirectoryExists($temporaire);
        $noms = [];
        $pieces = [];
        $piecesDeCharge = [];
        [$charges, $autres] = collect($documents)->partition(fn (DocumentTrouve $document): bool => $document->type === TypeDeDocumentTelechargeable::Charges);

        try {
            foreach ($this->piecesDesCharges($compte, $charges->values()->all()) as [$nom, $contenu, $piece]) {
                $place = self::placeLibre(self::DOSSIER_DES_CHARGES.'/'.$nom, $noms);
                $copie = $temporaire.'/'.count($noms);
                File::put($copie, $contenu);
                $zip->addFile($copie, $place);
                $piecesDeCharge[] = $piece;
            }

            foreach ($autres->groupBy(fn (DocumentTrouve $document): int => $document->magasinId) as $magasinId => $duMagasin) {
                $magasin = Magasin::query()->findOrFail($magasinId);

                $this->documents->dansLeMagasin($magasin, function () use ($compte, $magasin, $duMagasin, $du, $au, $zip, $temporaire, &$noms, &$pieces): void {
                    foreach ($duMagasin as $document) {
                        foreach ($this->fichiers($compte, $magasin, $document, $du, $au) as [$nom, $contenu, $piece]) {
                            $place = self::placeLibre($document->type->value.'/'.$document->mois().'/'.self::nomSur($nom), $noms);
                            $copie = $temporaire.'/'.count($noms);
                            File::put($copie, $contenu);
                            $zip->addFile($copie, $place);

                            if ($piece !== null) {
                                $pieces[] = $piece;
                            }
                        }
                    }
                });
            }

            if ($noms === []) {
                throw new AucunePieceDeChargeAServirException;
            }

            if (! $zip->close()) {
                throw new RuntimeException('L\'archive des documents ne s\'écrit pas.');
            }
        } finally {
            File::deleteDirectory($temporaire);
        }

        return ['pieces' => $pieces, 'piecesDeCharge' => $piecesDeCharge];
    }

    /**
     * Les pièces de ces charges que ce compte peut se faire servir, dans
     * l'ordre des charges : chacune son nom, son contenu tel qu'enregistré
     * et son identifiant ; rien pour une charge sans pièce ou dont la pièce
     * est bloquée. Les charges et leurs pièces se lisent ensemble, dans la
     * société de chaque charge, hors de son scope, filtrées par elle.
     *
     * @param  list<DocumentTrouve>  $charges
     * @return list<array{0: string, 1: string, 2: int}>
     *
     * @throws AntivirusInjoignableException
     */
    private function piecesDesCharges(User $compte, array $charges): array
    {
        $servies = [];

        foreach (collect($charges)->groupBy(fn (DocumentTrouve $charge): int => (int) $charge->societeId) as $societe => $deLaSociete) {
            $lues = Charge::query()->withoutGlobalScope(SocieteScope::class)
                ->where('societe_id', $societe)
                ->whereKey($deLaSociete->map(fn (DocumentTrouve $charge): int => (int) $charge->identifiant)->all())
                ->with([
                    'piece' => fn (HasOne $piece): HasOne => $piece->withoutGlobalScope(SocieteScope::class)->where('pieces_de_charge.societe_id', $societe),
                    'typeDeLaSociete' => fn (BelongsTo $type): BelongsTo => $type->withoutGlobalScope(SocieteScope::class)->where('types_de_charge_de_la_societe.societe_id', $societe),
                ])
                ->get(['id', 'societe_id', 'type', 'type_de_la_societe_id', 'fournisseur', 'numero_piece', 'mois_tva'])
                ->keyBy(fn (Charge $charge): int => CleDuModele::de($charge));

            foreach ($deLaSociete as $document) {
                $charge = $lues->get((int) $document->identifiant);
                $piece = $charge?->piece;

                if ($charge === null || $piece === null) {
                    continue;
                }

                try {
                    $contenu = $this->piecesDesCharges->contenuServable($compte, $piece);
                } catch (PieceBloqueeException) {
                    continue;
                }

                $servies[] = [self::nomDeLaPieceDUneCharge($charge, $piece), $contenu, CleDuModele::de($piece)];
            }
        }

        return $servies;
    }

    /**
     * Le nom de la pièce d'une charge dans l'archive : « <AAAA-MM> <type>
     * <fournisseur> <n° de pièce>.<ext> », sans ce qui manque, l'extension
     * étant celle de son type (voir NomDeFichier::dUnFichierProduit()).
     */
    public static function nomDeLaPieceDUneCharge(Charge $charge, PieceDeCharge $piece): string
    {
        return NomDeFichier::dUnFichierProduit(
            implode(' ', array_filter([$charge->mois_tva, $charge->libelleDuType(), $charge->fournisseur, $charge->numero_piece], fn (?string $partie): bool => $partie !== null && trim($partie) !== '')),
            NomDeFichier::EXTENSIONS[$piece->type_mime] ?? 'pdf',
        );
    }

    /**
     * Les fichiers d'un document : chacun son nom, son contenu, et
     * l'identifiant de la pièce du fournisseur qu'il est, ou null.
     *
     * @return list<array{0: string, 1: string, 2: int|null}>
     */
    private function fichiers(User $compte, Magasin $magasin, DocumentTrouve $document, string $du, string $au): array
    {
        return match ($document->type) {
            TypeDeDocumentTelechargeable::Ventes => [[$document->numero.'.pdf', $this->factures->generer(Facture::query()->findOrFail($document->identifiant))->output(), null]],
            TypeDeDocumentTelechargeable::AvoirsClients => [[$document->numero.'.pdf', $this->avoirs->generer(Avoir::query()->findOrFail($document->identifiant))->output(), null]],
            TypeDeDocumentTelechargeable::FacturesAchat => $this->piecesOuFiche($compte, FactureAchat::query()->findOrFail($document->identifiant)),
            TypeDeDocumentTelechargeable::AvoirsFournisseur => $this->piecesOuFiche($compte, AvoirFournisseur::query()->findOrFail($document->identifiant)),
            TypeDeDocumentTelechargeable::Clotures => [[$document->numero.'.pdf', $this->clotures->generer(ClotureCaisse::query()->findOrFail($document->identifiant))->output(), null]],
            TypeDeDocumentTelechargeable::Encaissements => [[$this->releves->nomFichier($magasin, $document->identifiant), $this->releves->generer($compte, $magasin, $document->identifiant, $du, $au)->output(), null]],
            TypeDeDocumentTelechargeable::Charges => [],
        };
    }

    /**
     * Les pièces du fournisseur d'un achat que ce compte peut se faire
     * servir, telles que conservées, chacune avec son identifiant, ou sa
     * fiche de saisie quand il n'en a aucune.
     *
     * @return list<array{0: string, 1: string, 2: int|null}>
     *
     * @throws AntivirusInjoignableException
     */
    private function piecesOuFiche(User $compte, FactureAchat|AvoirFournisseur $document): array
    {
        $servies = [];

        foreach ($document->pieces()->get() as $piece) {
            try {
                $servies[] = [$document->numero.' - '.$piece->nom, $this->pieces->contenuServable($compte, $piece), CleDuModele::de($piece)];
            } catch (PieceBloqueeException) {
                continue;
            }
        }

        return $servies !== [] ? $servies : [[$this->fiches->nomFichier($document), $this->fiches->generer($document)->output(), null]];
    }

    /**
     * Un nom de fichier sans séparateur de dossier ni caractère de
     * contrôle.
     */
    private static function nomSur(string $nom): string
    {
        $sur = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]/u', '_', $nom));

        return $sur === '' ? 'document' : $sur;
    }

    /**
     * Ce chemin dans l'archive, ou, s'il est déjà pris, le même suivi de
     * son rang, « (2) » avant l'extension ; noté comme pris.
     *
     * @param  array<string, true>  $noms
     */
    private static function placeLibre(string $chemin, array &$noms): string
    {
        $place = $chemin;
        $extension = pathinfo($chemin, PATHINFO_EXTENSION);
        $base = $extension === '' ? $chemin : substr($chemin, 0, -strlen($extension) - 1);

        for ($rang = 2; isset($noms[$place]); $rang++) {
            $place = $base.' ('.$rang.')'.($extension === '' ? '' : '.'.$extension);
        }

        $noms[$place] = true;

        return $place;
    }
}
