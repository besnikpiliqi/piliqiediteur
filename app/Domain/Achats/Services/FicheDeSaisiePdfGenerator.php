<?php

namespace App\Domain\Achats\Services;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Achats\Models\LigneAvoirFournisseur;
use App\Domain\Achats\Models\TauxAvoirFournisseur;
use App\Domain\Achats\Models\TauxFactureAchat;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\Emetteur;
use App\Domain\Facturation\Services\MiseEnPageA4;
use App\Domain\Facturation\Services\PiedDePage;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * La fiche de saisie d'une facture d'achat ou d'un avoir fournisseur sans
 * pièce du fournisseur (reçu par l'API sans fichier, ou saisi à la main
 * sans sa pièce), que le téléchargement des documents par période range à
 * sa place (décision de l'éditeur du 03/11/2026, E1 ; voir
 * ArchiveDesDocuments) : marquée « Saisie PILIQI — l'original est la
 * facture du fournisseur » (ou sa note d'avoir), elle n'est pas le
 * document du fournisseur, seulement ce que le magasin en a saisi : le
 * fournisseur, notre numéro et le sien, sa date, chaque ligne (référence,
 * désignation, quantité, PU HT tel qu'il est enregistré, taux, HT), la
 * TVA de chaque taux et les totaux, tels qu'ils sont enregistrés, et sa
 * marque « TVA à vérifier ». Une vraie page A4 en portrait (voir
 * MiseEnPageA4), le magasin en tête, l'identité de la société dans le pied.
 */
class FicheDeSaisiePdfGenerator
{
    public const MARQUE_FACTURE = 'Saisie PILIQI — l\'original est la facture du fournisseur';

    public const MARQUE_AVOIR = 'Saisie PILIQI — l\'original est la note d\'avoir du fournisseur';

    public function __construct(
        private readonly MiseEnPageA4 $miseEnPage,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * @throws VenteNonAutoriseeException
     */
    public function generer(FactureAchat|AvoirFournisseur $document): PdfDocument
    {
        return $this->miseEnPage->pdf($this->html($document));
    }

    /**
     * @throws VenteNonAutoriseeException
     */
    public function html(FactureAchat|AvoirFournisseur $document): string
    {
        $magasin = $document->magasin;
        $magasin->verifierQuIlPeutImprimer();
        $calendrier = $magasin->calendrier();
        $emetteur = Emetteur::duMagasin($magasin);
        $estUneFacture = $document instanceof FactureAchat;
        $fournisseur = $estUneFacture ? $document->fournisseur : $document->factureAchat->fournisseur;
        $euros = fn (mixed $montant): string => $this->calculateur->enEuros($this->calculateur->enCentiemes($montant));

        return view('achats.fiche-de-saisie', [
            'emetteur' => $emetteur,
            'pied' => PiedDePage::pourUnRecu($emetteur),
            'marque' => $estUneFacture ? self::MARQUE_FACTURE : self::MARQUE_AVOIR,
            'titre' => $estUneFacture ? 'Facture d\'achat' : 'Avoir fournisseur',
            'details' => array_values(array_filter([
                $document->numero,
                filled($document->numero_fournisseur) ? 'N° du fournisseur : '.$document->numero_fournisseur : null,
                $estUneFacture
                    ? ($document->date_facture === null ? null : 'Facture du '.Calendrier::afficherJour($document->date_facture, 'd/m/Y'))
                    : 'Émis le '.$calendrier->afficher($document->date_emission, 'd/m/Y').' sur la facture d\'achat '.$document->factureAchat->numero,
                $document->source->getLabel(),
            ])),
            'fournisseur' => array_values(array_filter([$fournisseur->nom, filled($fournisseur->siret) ? 'SIRET '.$fournisseur->siret : null, filled($fournisseur->numero_tva) ? 'N° de TVA '.$fournisseur->numero_tva : null])),
            'lignes' => $document->lignes()->get()->map(fn (LigneAchat|LigneAvoirFournisseur $ligne): array => [
                'reference' => $ligne->reference,
                'description' => $ligne->description,
                'quantite' => $ligne->quantite,
                'prix_unitaire_ht' => str_replace('.', ',', (string) $ligne->prix_unitaire_ht).' €',
                'taux' => str_replace('.', ',', rtrim(rtrim((string) $ligne->taux_tva, '0'), '.')).' %',
                'montant_ht' => $euros($ligne->montant_ht),
            ])->all(),
            'taux' => $document->taux()->get()->map(fn (TauxFactureAchat|TauxAvoirFournisseur $taux): array => [
                'taux' => str_replace('.', ',', rtrim(rtrim((string) $taux->taux_tva, '0'), '.')).' %',
                'base_ht' => $euros($taux->base_ht),
                'montant_tva' => $euros($taux->montant_tva),
            ])->all(),
            'totaux' => [
                ['libelle' => 'Total HT', 'montant' => $euros($document->total_ht)],
                ['libelle' => 'Total TVA', 'montant' => $euros($document->total_tva)],
                ['libelle' => 'Total TTC', 'montant' => $euros($document->total_ttc)],
            ],
            'tvaAVerifier' => (bool) $document->tva_a_verifier,
        ])->render();
    }

    public function nomFichier(FactureAchat|AvoirFournisseur $document): string
    {
        return "{$document->numero} - saisie PILIQI.pdf";
    }
}
