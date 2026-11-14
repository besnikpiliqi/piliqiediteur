<?php

namespace App\Domain\Fichiers\Enums;

/**
 * Pourquoi un fichier reçu est refusé (voir ControleDesFichiers). Un refus
 * pour une raison de sécurité s'inscrit au journal d'audit de la société
 * (décision de l'éditeur du 07/11/2026, lot FI) ; un fichier vide ou trop
 * gros, ou un antivirus ou un analyseur qui ne répond pas, non : le
 * fichier n'a rien montré de dangereux.
 */
enum RaisonDuRefus: string
{
    case Vide = 'vide';
    case TropGros = 'trop_gros';
    case Type = 'type';
    case PdfActif = 'pdf_actif';
    case PdfIllisible = 'pdf_illisible';
    case PdfProtege = 'pdf_protege';
    case ImageIllisible = 'image_illisible';
    case ImageTropGrande = 'image_trop_grande';
    case Menace = 'menace';
    case AntivirusInjoignable = 'antivirus_injoignable';
    case AnalyseIndisponible = 'analyse_indisponible';

    /**
     * Comment obtenir une copie propre d'un PDF refusé.
     */
    public const COPIE_PROPRE = 'Pour joindre une copie propre, demandez à son expéditeur un PDF sans code, imprimez le document en PDF (« Imprimer », puis « Enregistrer au format PDF ») ou photographiez-le.';

    public function estDeSecurite(): bool
    {
        return match ($this) {
            self::Type, self::PdfActif, self::PdfIllisible, self::PdfProtege, self::ImageIllisible, self::ImageTropGrande, self::Menace => true,
            self::Vide, self::TropGros, self::AntivirusInjoignable, self::AnalyseIndisponible => false,
        };
    }

    /**
     * Le message qui dit pourquoi le fichier de ce nom est refusé, et quoi
     * faire.
     *
     * @param  list<ContenuActif>  $contenus  ce que contient un PDF refusé pour son code actif
     */
    public function message(string $nom, array $contenus = []): string
    {
        return match ($this) {
            self::Vide => "Le fichier « {$nom} » est vide.",
            self::TropGros => "Le fichier « {$nom} » dépasse 10 Mo : une pièce fait 10 Mo au plus.",
            self::Type => "Le fichier « {$nom} » n'est ni un PDF, ni une photo JPEG ou PNG.",
            self::PdfActif => "Le fichier « {$nom} » est refusé : ce PDF contient ".self::enumerer(array_map(fn (ContenuActif $contenu): string => $contenu->libelle(), $contenus)).'. '.self::COPIE_PROPRE,
            self::PdfIllisible => "Le fichier « {$nom} » est refusé : ce PDF ne se lit pas en entier (abîmé, incomplet ou suivi d'un autre contenu). ".self::COPIE_PROPRE,
            self::PdfProtege => "Le fichier « {$nom} » est refusé : ce PDF est protégé par un mot de passe d'ouverture. ".self::COPIE_PROPRE,
            self::ImageIllisible => "Le fichier « {$nom} » est refusé : cette image ne se lit pas. Photographiez de nouveau le document, ou joignez-le en PDF.",
            self::ImageTropGrande => "Le fichier « {$nom} » est refusé : cette image est trop grande (plus de 16 000 pixels de côté ou de 40 millions de pixels). Réduisez-la, ou joignez le document en PDF.",
            self::Menace => "Le fichier « {$nom} » est refusé : l'antivirus y a trouvé une menace. Ne l'ouvrez pas sur votre poste, et demandez une copie saine à son expéditeur.",
            self::AntivirusInjoignable => "Le fichier « {$nom} » n'a pas pu être analysé par l'antivirus : réessayez dans quelques minutes.",
            self::AnalyseIndisponible => "Le fichier « {$nom} » n'a pas pu être analysé : réessayez dans quelques minutes.",
        };
    }

    /**
     * « a », « a et b », « a, b et c ».
     *
     * @param  list<string>  $elements
     */
    private static function enumerer(array $elements): string
    {
        $dernier = array_pop($elements);

        return $elements === [] ? (string) $dernier : implode(', ', $elements).' et '.$dernier;
    }
}
