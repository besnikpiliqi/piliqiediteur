<?php

namespace App\Domain\Fichiers;

use App\Domain\Fichiers\Enums\RaisonDuRefus;
use App\Domain\Fichiers\Exceptions\FichierRefuseException;
use App\Integrations\Antivirus\AntivirusInjoignableException;
use App\Integrations\Antivirus\Contracts\Antivirus;
use Carbon\CarbonImmutable;

/**
 * Le contrôle de chaque fichier reçu avant qu'il s'enregistre (décisions
 * de l'éditeur du 07/11/2026, lot FI, Q-FI1 = a et Q-FI2 = a), écrit une
 * seule fois ici : pièce du fournisseur déposée à l'écran ou jointe par
 * l'API (voir PiecesDuFournisseur), pièce de la vérification de la société
 * (voir DeposerUnePieceAction). Dans cet ordre :
 *
 * - un fichier vide, ou de plus de TAILLE_MAXIMALE_EN_OCTETS, est refusé ;
 * - l'antivirus l'analyse tel que reçu, avant toute lecture par PILIQI
 *   (voir Antivirus) : une menace le fait refuser ; un antivirus
 *   injoignable aussi, sans le juger (« réessayez dans quelques
 *   minutes ») : un fichier n'est jamais accepté sans analyse ;
 * - son type se lit dans son contenu, jamais dans son nom : un PDF, une
 *   photo JPEG ou PNG (voir typeDuContenu()) ; tout autre contenu est
 *   refusé ;
 * - son nom est réécrit avec l'extension de ce type, sans caractère
 *   invisible (voir NomDeFichier) ;
 * - un PDF est lu en entier, et refusé s'il contient du code actif ou ne
 *   se lit pas en entier (voir AnalyseurDePdf) ; il se conserve tel que
 *   reçu ;
 * - une photo est décodée et réenregistrée sans ses métadonnées (voir
 *   ReenregistrementDesImages) : c'est ce fichier qui se conserve.
 *
 * Il ne touche pas à la base : l'action qui l'appelle inscrit au journal
 * d'audit un refus pour une raison de sécurité (voir
 * RaisonDuRefus::estDeSecurite()), hors de toute transaction, puis le
 * laisse remonter.
 */
final class ControleDesFichiers
{
    public const TAILLE_MAXIMALE_EN_OCTETS = 10 * 1024 * 1024;

    public function __construct(
        private readonly Antivirus $antivirus,
        private readonly AnalyseurDePdf $pdf,
        private readonly ReenregistrementDesImages $images,
    ) {}

    /**
     * Ce fichier déposé, contrôlé et prêt à s'enregistrer.
     *
     * @throws FichierRefuseException
     */
    public function controler(PieceDeposee $piece): FichierControle
    {
        if ($piece->contenu === '') {
            throw FichierRefuseException::pour($piece, RaisonDuRefus::Vide);
        }

        if (strlen($piece->contenu) > self::TAILLE_MAXIMALE_EN_OCTETS) {
            throw FichierRefuseException::pour($piece, RaisonDuRefus::TropGros);
        }

        try {
            $resultat = $this->antivirus->analyser($piece->contenu);
        } catch (AntivirusInjoignableException $injoignable) {
            report($injoignable);

            throw FichierRefuseException::pour($piece, RaisonDuRefus::AntivirusInjoignable);
        }

        if (! $resultat->estSain()) {
            throw FichierRefuseException::pour($piece, RaisonDuRefus::Menace, menace: $resultat->menace);
        }

        $analyseLe = CarbonImmutable::now();
        $type = self::typeDuContenu($piece->contenu) ?? throw FichierRefuseException::pour($piece, RaisonDuRefus::Type);

        if ($type === 'application/pdf') {
            $this->pdf->analyser($piece);
            $contenu = $piece->contenu;
        } else {
            $contenu = $this->images->reenregistrer($piece, $type);
        }

        return new FichierControle(
            NomDeFichier::nettoyer($piece->nom, $type),
            $type,
            $contenu,
            $piece->nom,
            hash('sha256', $piece->contenu),
            strlen($piece->contenu),
            $analyseLe,
        );
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
}
