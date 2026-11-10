<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\PieceDuFournisseur;
use App\Domain\Achats\Services\FicheDeSaisiePdfGenerator;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
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
 *   fournisseur, telles que déposées, chacune sous notre numéro suivi de
 *   son nom d'origine ; sans pièce, sa fiche de saisie marquée « Saisie
 *   PILIQI » (voir FicheDeSaisiePdfGenerator) ;
 * - une clôture du jour : son ticket Z (voir ClotureCaissePdfGenerator) ;
 * - un relevé des encaissements : son PDF, par magasin et par mois (voir
 *   ReleveDesEncaissementsPdfGenerator).
 * Chaque document se relit dans son magasin, comme magasin actif (voir
 * DocumentsDeLaPeriode::dansLeMagasin()) ; chaque fichier s'écrit d'abord dans un dossier
 * temporaire, effacé ensuite, pour ne jamais tenir toute l'archive en
 * mémoire. Deux fichiers du même nom dans un même dossier sont distingués
 * par leur rang (« (2) »).
 */
final class ArchiveDesDocuments
{
    public const AUCUN_DOCUMENT = 'Aucun des documents choisis ne vous est plus accessible.';

    public function __construct(
        private readonly DocumentsDeLaPeriode $documents,
        private readonly FacturePdfGenerator $factures,
        private readonly AvoirPdfGenerator $avoirs,
        private readonly ClotureCaissePdfGenerator $clotures,
        private readonly ReleveDesEncaissementsPdfGenerator $releves,
        private readonly FicheDeSaisiePdfGenerator $fiches,
        private readonly PiecesDuFournisseur $pieces,
    ) {}

    /**
     * Écrit l'archive de ces documents à ce chemin local.
     *
     * @param  list<DocumentTrouve>  $documents
     *
     * @throws RuntimeException quand aucun document n'y entre ou que l'archive ne s'écrit pas
     */
    public function ecrire(User $compte, array $documents, string $du, string $au, string $chemin): void
    {
        $zip = new ZipArchive;

        if ($zip->open($chemin, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('L\'archive des documents ne s\'ouvre pas.');
        }

        $temporaire = storage_path('framework/cache/telechargement-'.Str::random(32));
        File::ensureDirectoryExists($temporaire);
        $noms = [];

        try {
            foreach (collect($documents)->groupBy(fn (DocumentTrouve $document): int => $document->magasinId) as $magasinId => $duMagasin) {
                $magasin = Magasin::query()->findOrFail($magasinId);

                $this->documents->dansLeMagasin($magasin, function () use ($compte, $magasin, $duMagasin, $du, $au, $zip, $temporaire, &$noms): void {
                    foreach ($duMagasin as $document) {
                        foreach ($this->fichiers($compte, $magasin, $document, $du, $au) as [$nom, $contenu]) {
                            $place = self::placeLibre($document->type->value.'/'.$document->mois().'/'.self::nomSur($nom), $noms);
                            $copie = $temporaire.'/'.count($noms);
                            File::put($copie, $contenu);
                            $zip->addFile($copie, $place);
                        }
                    }
                });
            }

            if ($noms === []) {
                throw new RuntimeException(self::AUCUN_DOCUMENT);
            }

            if (! $zip->close()) {
                throw new RuntimeException('L\'archive des documents ne s\'écrit pas.');
            }
        } finally {
            File::deleteDirectory($temporaire);
        }
    }

    /**
     * Les fichiers d'un document : chacun son nom et son contenu.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function fichiers(User $compte, Magasin $magasin, DocumentTrouve $document, string $du, string $au): array
    {
        return match ($document->type) {
            TypeDeDocumentTelechargeable::Ventes => [[$document->numero.'.pdf', $this->factures->generer(Facture::query()->findOrFail($document->identifiant))->output()]],
            TypeDeDocumentTelechargeable::AvoirsClients => [[$document->numero.'.pdf', $this->avoirs->generer(Avoir::query()->findOrFail($document->identifiant))->output()]],
            TypeDeDocumentTelechargeable::FacturesAchat => $this->piecesOuFiche(FactureAchat::query()->findOrFail($document->identifiant)),
            TypeDeDocumentTelechargeable::AvoirsFournisseur => $this->piecesOuFiche(AvoirFournisseur::query()->findOrFail($document->identifiant)),
            TypeDeDocumentTelechargeable::Clotures => [[$document->numero.'.pdf', $this->clotures->generer(ClotureCaisse::query()->findOrFail($document->identifiant))->output()]],
            TypeDeDocumentTelechargeable::Encaissements => [[$this->releves->nomFichier($magasin, $document->identifiant), $this->releves->generer($compte, $magasin, $document->identifiant, $du, $au)->output()]],
        };
    }

    /**
     * Les pièces du fournisseur d'un achat, telles que déposées, ou sa
     * fiche de saisie quand il n'en a aucune.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function piecesOuFiche(FactureAchat|AvoirFournisseur $document): array
    {
        $pieces = $document->pieces()->get();

        if ($pieces->isEmpty()) {
            return [[$this->fiches->nomFichier($document), $this->fiches->generer($document)->output()]];
        }

        return array_values($pieces->map(fn (PieceDuFournisseur $piece): array => [$document->numero.' - '.$piece->nom_origine, $this->pieces->contenu($piece)])->all());
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
