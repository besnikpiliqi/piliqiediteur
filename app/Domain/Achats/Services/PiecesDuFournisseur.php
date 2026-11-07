<?php

namespace App\Domain\Achats\Services;

use App\Domain\Achats\Enums\SourceAvoirFournisseur;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\PieceDuFournisseur;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;

/**
 * Les pièces du fournisseur jointes aux factures d'achat et aux avoirs
 * fournisseur (décision de l'éditeur du 03/11/2026, lot EX), écrites une
 * seule fois ici, quel que soit l'écran ou l'action qui les dépose :
 *
 * - une pièce est un PDF, une photo JPEG ou PNG, reconnu sur son contenu,
 *   jamais sur son extension, de 10 Mo au plus, et un document en porte au
 *   plus dix, comptées sous le verrou de sa ligne : deux dépôts simultanés
 *   ne dépassent jamais ce nombre ; un même fichier ne se joint qu'une fois
 *   au même document ;
 * - le fichier se range sur le disque privé « pieces_fournisseur », dans
 *   le dossier de son magasin, sous un nom tiré au hasard ; la ligne garde
 *   son nom d'origine, son type, sa taille et son empreinte SHA-256. Une
 *   transaction annulée efface le fichier déjà rangé ;
 * - avant la validation d'une facture d'achat, une pièce se retire ; jointe
 *   à une facture validée ou à un avoir émis, elle ne se retire plus (la
 *   base le garantit aussi, voir GardesDesPiecesDuFournisseur), on ne peut
 *   qu'en ajouter une. Le fichier d'une pièce retirée s'efface une fois la
 *   transaction validée ;
 * - un document saisi à la main sans aucune pièce porte la marque « pièce
 *   du fournisseur manquante » (F1 = b) : il s'enregistre et se valide
 *   quand même, et la marque se lève à l'ajout de la première pièce (voir
 *   marquer()) ; un document reçu par l'API n'en exige pas ;
 * - chaque ajout, chaque retrait, chaque pose et chaque levée de la marque
 *   s'inscrit au journal d'audit de la société, dans la chaîne du magasin
 *   (document, nom d'origine, empreinte, auteur).
 *
 * Les actions qui l'appellent ont déjà pris, dans l'ordre, la ligne de la
 * société, celle du magasin et celle de l'auteur relu ; la ligne du
 * document se verrouille ici.
 */
final class PiecesDuFournisseur
{
    public const DISQUE = 'pieces_fournisseur';

    public const TAILLE_MAXIMALE_EN_OCTETS = 10 * 1024 * 1024;

    public const NOMBRE_MAXIMAL_PAR_DOCUMENT = 10;

    /**
     * Les types admis, lus sur le contenu, et l'extension de leur fichier
     * rangé.
     */
    public const TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public const REFUS_VIDE = 'Le fichier « %s » est vide.';

    public const REFUS_TROP_GROS = 'Le fichier « %s » dépasse 10 Mo : une pièce fait 10 Mo au plus.';

    public const REFUS_TYPE = 'Le fichier « %s » n\'est ni un PDF, ni une photo JPEG ou PNG.';

    public const REFUS_TROP_DE_PIECES = 'Un document porte au plus dix pièces du fournisseur.';

    public const REFUS_DEJA_JOINTE = 'Le fichier « %s » est déjà joint à ce document.';

    public const REFUS_RETRAIT = 'Une pièce ne se retire que d\'une facture d\'achat en brouillon : jointe à un document validé, elle se conserve comme lui ; vous pouvez en ajouter une autre.';

    public function __construct(private readonly JournalAudit $journalAudit) {}

    /**
     * Joint ces pièces au document, puis met sa marque à jour.
     *
     * @param  list<PieceDeposee>  $pieces
     * @return list<PieceDuFournisseur>
     *
     * @throws InvalidArgumentException quand une pièce est refusée
     */
    public function joindre(User $compte, FactureAchat|AvoirFournisseur $document, array $pieces): array
    {
        if ($pieces === []) {
            return [];
        }

        return DB::transaction(function () use ($compte, $document, $pieces): array {
            $document = $this->verrouiller($document);
            $empreintes = PieceDuFournisseur::query()->where($this->colonneDu($document), $document->getKey())->pluck('empreinte')->all();

            if (count($empreintes) + count($pieces) > self::NOMBRE_MAXIMAL_PAR_DOCUMENT) {
                throw new InvalidArgumentException(self::REFUS_TROP_DE_PIECES);
            }

            $controlees = [];

            foreach ($pieces as $piece) {
                $nom = self::nomDOrigine($piece->nom);
                $type = self::controler($nom, $piece->contenu);
                $empreinte = hash('sha256', $piece->contenu);

                if (in_array($empreinte, $empreintes, true)) {
                    throw new InvalidArgumentException(sprintf(self::REFUS_DEJA_JOINTE, $nom));
                }

                $empreintes[] = $empreinte;
                $controlees[] = [$nom, $type, $empreinte, $piece->contenu];
            }

            $jointes = array_map(fn (array $controlee): PieceDuFournisseur => $this->ranger($compte, $document, ...$controlee), $controlees);
            $this->marquer($compte, $document);

            return $jointes;
        });
    }

    /**
     * Retire cette pièce de sa facture d'achat en brouillon, puis, sauf
     * quand une autre la remplace aussitôt (voir RetirerUnePieceAction), met
     * la marque du document à jour ; son fichier s'efface une fois la
     * transaction validée.
     *
     * @throws LogicException quand le document n'est pas un brouillon
     */
    public function retirer(User $compte, PieceDuFournisseur $piece, bool $mettreAJourLaMarque = true): void
    {
        DB::transaction(function () use ($compte, $piece, $mettreAJourLaMarque): void {
            $document = $this->verrouiller($piece->document());

            if (! $document instanceof FactureAchat || $document->statut !== StatutFactureAchat::Brouillon) {
                throw new LogicException(self::REFUS_RETRAIT);
            }

            $piece = PieceDuFournisseur::query()->whereKey($piece->getKey())->firstOrFail();
            $chemin = $piece->chemin;
            $piece->delete();
            DB::afterCommit(fn () => Storage::disk(self::DISQUE)->delete($chemin));

            $this->journalAudit->inscrire(ActionAuditee::PieceDuFournisseurRetiree, $compte, $document->magasin, null, self::valeursAuditees($document, $piece), $document);

            if ($mettreAJourLaMarque) {
                $this->marquer($compte, $document);
            }
        });
    }

    /**
     * Retire toutes les pièces d'une facture d'achat en brouillon qui se
     * supprime (voir SupprimerFactureAchatAction), chacune inscrite au
     * journal d'audit ; aucune marque à mettre à jour.
     */
    public function retirerToutes(User $compte, FactureAchat $factureAchat): void
    {
        foreach (PieceDuFournisseur::query()->where('facture_achat_id', $factureAchat->getKey())->orderBy('id')->get() as $piece) {
            $chemin = $piece->chemin;
            $piece->delete();
            DB::afterCommit(fn () => Storage::disk(self::DISQUE)->delete($chemin));
            $this->journalAudit->inscrire(ActionAuditee::PieceDuFournisseurRetiree, $compte, $factureAchat->magasin, null, self::valeursAuditees($factureAchat, $piece), $factureAchat);
        }
    }

    /**
     * Pose ou lève la marque « pièce du fournisseur manquante » : posée sur
     * un document saisi à la main qui n'a aucune pièce, levée sinon ;
     * jamais sur un document reçu par l'API. Chaque changement s'inscrit au
     * journal d'audit.
     */
    public function marquer(User $compte, FactureAchat|AvoirFournisseur $document): void
    {
        $saisieALaMain = $document instanceof FactureAchat
            ? ($document->source ?? SourceFactureAchat::Manuelle) === SourceFactureAchat::Manuelle
            : ($document->source ?? SourceAvoirFournisseur::Manuelle) === SourceAvoirFournisseur::Manuelle;
        $manquante = $saisieALaMain && PieceDuFournisseur::query()->where($this->colonneDu($document), $document->getKey())->doesntExist();

        if ((bool) $document->piece_manquante === $manquante) {
            return;
        }

        $document->forceFill(['piece_manquante' => $manquante])->save();
        $this->journalAudit->inscrire(
            $manquante ? ActionAuditee::PieceDuFournisseurManquantePosee : ActionAuditee::PieceDuFournisseurManquanteLevee,
            $compte,
            $document->magasin,
            null,
            ['document' => TvaDuFournisseur::designation($document)],
            $document,
        );
    }

    /**
     * Le contenu du fichier d'une pièce.
     */
    public function contenu(PieceDuFournisseur $piece): string
    {
        return (string) Storage::disk(self::DISQUE)->get($piece->chemin);
    }

    /**
     * Le type d'un contenu, lu sur ses premiers octets, jamais sur un nom
     * de fichier : un PDF commence par « %PDF- » (après une marque d'ordre
     * des octets ou des blancs, que certains logiciels écrivent avant), une
     * photo JPEG par FF D8 FF, une image PNG par sa signature de huit
     * octets ; null pour tout autre contenu, même s'il contient « %PDF- »
     * plus loin.
     */
    public static function typeDuContenu(string $contenu): ?string
    {
        return match (true) {
            str_starts_with(ltrim(str_starts_with($contenu, "\xEF\xBB\xBF") ? substr($contenu, 3) : $contenu, " \t\r\n"), '%PDF-') => 'application/pdf',
            str_starts_with($contenu, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($contenu, "\x89PNG\r\n\x1A\n") => 'image/png',
            default => null,
        };
    }

    /**
     * Le nom d'origine tel qu'il s'enregistre : son seul nom de base, sans
     * chemin ni caractère de contrôle, 255 caractères au plus ; « pièce »
     * quand il n'en reste rien.
     */
    public static function nomDOrigine(string $nom): string
    {
        $base = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $nom))));

        return $base === '' || $base === '.' || $base === '..' ? 'pièce' : mb_substr($base, 0, 255);
    }

    /**
     * Les valeurs qu'un ajout ou un retrait inscrit au journal d'audit.
     *
     * @return array{document: array{nature: string, numero: string|null, numero_fournisseur: string|null}, nom_origine: string, empreinte: string}
     */
    private static function valeursAuditees(FactureAchat|AvoirFournisseur $document, PieceDuFournisseur $piece): array
    {
        return ['document' => TvaDuFournisseur::designation($document), 'nom_origine' => $piece->nom_origine, 'empreinte' => $piece->empreinte];
    }

    /**
     * Le type d'une pièce admise.
     *
     * @throws InvalidArgumentException
     */
    private static function controler(string $nom, string $contenu): string
    {
        if ($contenu === '') {
            throw new InvalidArgumentException(sprintf(self::REFUS_VIDE, $nom));
        }

        if (strlen($contenu) > self::TAILLE_MAXIMALE_EN_OCTETS) {
            throw new InvalidArgumentException(sprintf(self::REFUS_TROP_GROS, $nom));
        }

        return self::typeDuContenu($contenu) ?? throw new InvalidArgumentException(sprintf(self::REFUS_TYPE, $nom));
    }

    /**
     * Range le fichier, puis enregistre sa ligne et l'inscrit au journal
     * d'audit ; le fichier s'efface si la transaction est annulée.
     */
    private function ranger(User $compte, FactureAchat|AvoirFournisseur $document, string $nom, string $type, string $empreinte, string $contenu): PieceDuFournisseur
    {
        $chemin = 'magasin-'.$document->magasin_id.'/'.bin2hex(random_bytes(16)).'.'.self::TYPES[$type];
        Storage::disk(self::DISQUE)->put($chemin, $contenu);
        DB::afterRollBack(fn () => Storage::disk(self::DISQUE)->delete($chemin));

        $piece = (new PieceDuFournisseur)->forceFill([
            $this->colonneDu($document) => $document->getKey(),
            'nom_origine' => $nom,
            'type_mime' => $type,
            'taille' => strlen($contenu),
            'empreinte' => $empreinte,
            'chemin' => $chemin,
            'ajoutee_par' => $compte->getKey(),
        ]);
        $piece->save();

        $this->journalAudit->inscrire(ActionAuditee::PieceDuFournisseurJointe, $compte, $document->magasin, null, self::valeursAuditees($document, $piece), $document);

        return $piece;
    }

    /**
     * Le document relu sous le verrou de sa ligne.
     */
    private function verrouiller(FactureAchat|AvoirFournisseur $document): FactureAchat|AvoirFournisseur
    {
        return $document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
    }

    private function colonneDu(FactureAchat|AvoirFournisseur $document): string
    {
        return $document instanceof FactureAchat ? 'facture_achat_id' : 'avoir_fournisseur_id';
    }
}
