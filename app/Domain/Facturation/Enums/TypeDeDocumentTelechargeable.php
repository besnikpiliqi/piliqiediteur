<?php

namespace App\Domain\Facturation\Enums;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Filament\Support\Contracts\HasLabel;

/**
 * Les types de documents du téléchargement par période (décision de
 * l'éditeur du 03/11/2026, E1 = tout ; voir DocumentsDeLaPeriode) : les
 * ventes (factures et tickets), les avoirs des clients, les factures
 * d'achat et les avoirs fournisseur (seulement dans une société qui a des
 * articles), les clôtures du jour (tickets Z) et le relevé des
 * encaissements, un par magasin et par mois. Ni les devis ni les reçus de
 * paiement : le relevé des encaissements tient lieu des seconds.
 */
enum TypeDeDocumentTelechargeable: string implements HasLabel
{
    case Ventes = 'ventes';
    case AvoirsClients = 'avoirs';
    case FacturesAchat = 'achats';
    case AvoirsFournisseur = 'avoirs-fournisseur';
    case Clotures = 'clotures';
    case Encaissements = 'encaissements';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ventes => 'Ventes (factures et tickets)',
            self::AvoirsClients => 'Avoirs des clients',
            self::FacturesAchat => 'Factures d\'achat',
            self::AvoirsFournisseur => 'Avoirs fournisseur',
            self::Clotures => 'Clôtures du jour (tickets Z)',
            self::Encaissements => 'Relevé des encaissements',
        };
    }

    /**
     * Le nom d'un document de ce type dans la liste et dans le
     * récapitulatif.
     */
    public function libelleDUnDocument(): string
    {
        return match ($this) {
            self::Ventes => 'Vente',
            self::AvoirsClients => 'Avoir client',
            self::FacturesAchat => 'Facture d\'achat',
            self::AvoirsFournisseur => 'Avoir fournisseur',
            self::Clotures => 'Clôture du jour',
            self::Encaissements => 'Relevé des encaissements',
        };
    }

    /**
     * Le modèle dont la policy dit qui voit les documents de ce type (voir
     * FacturePolicy, AvoirPolicy, FactureAchatPolicy,
     * AvoirFournisseurPolicy, ClotureCaissePolicy et PaiementPolicy) : un
     * type qu'on ne peut pas voir n'est pas proposé.
     *
     * @return class-string
     */
    public function modele(): string
    {
        return match ($this) {
            self::Ventes => Facture::class,
            self::AvoirsClients => Avoir::class,
            self::FacturesAchat => FactureAchat::class,
            self::AvoirsFournisseur => AvoirFournisseur::class,
            self::Clotures => ClotureCaisse::class,
            self::Encaissements => Paiement::class,
        };
    }

    /**
     * Un document d'achat, dont on choisit l'origine (voir
     * OrigineDesAchats).
     */
    public function estUnAchat(): bool
    {
        return $this === self::FacturesAchat || $this === self::AvoirsFournisseur;
    }

    /**
     * Ce type existe-t-il dans une société de ce type d'activité ? Les
     * achats seulement dans une société qui a des articles.
     */
    public function existeDans(TypeDActivite $type): bool
    {
        return $type->aDesMagasins() && (! $this->estUnAchat() || $type->aDesArticles());
    }
}
