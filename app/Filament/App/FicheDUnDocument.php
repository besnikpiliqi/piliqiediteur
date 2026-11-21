<?php

namespace App\Filament\App;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Filament\App\Resources\AvoirFournisseurResource;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\ClotureCaisseResource;
use App\Filament\App\Resources\DevisResource;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\App\Resources\FactureResource;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * La fiche d'un document du magasin actif, vers laquelle mène son numéro
 * (constat C2 de l'éditeur, confirmé à T43 : « tout numéro de document
 * affiché devient un lien vers ce document pour qui peut le voir ») : le
 * numéro d'un ticket, d'une facture, d'un avoir, d'un devis, d'une
 * facture d'achat, d'un avoir fournisseur ou d'une clôture du jour mène à
 * sa fiche quand la personne connectée peut la voir (la ressource lui est
 * ouverte et la règle de ce document, voir les policies, le lui permet :
 * ses ventes seulement, ou celles de toute l'équipe) ; sinon il reste un
 * texte simple, sans lien qui mènerait à un refus. Un document d'un autre
 * magasin n'a jamais de lien (voir les policies : appartientAuMagasinActif).
 */
final class FicheDUnDocument
{
    /**
     * La ressource de chaque sorte de document.
     *
     * @var array<class-string<Model>, class-string<\Filament\Resources\Resource>>
     */
    private const RESSOURCES = [
        Facture::class => FactureResource::class,
        Avoir::class => AvoirResource::class,
        Devis::class => DevisResource::class,
        FactureAchat::class => FactureAchatResource::class,
        AvoirFournisseur::class => AvoirFournisseurResource::class,
        ClotureCaisse::class => ClotureCaisseResource::class,
    ];

    /**
     * L'adresse de la fiche de ce document pour la personne connectée ;
     * null si elle ne peut pas la voir, ou sans document.
     */
    public static function adresse(?Model $document): ?string
    {
        $ressource = $document === null ? null : (self::RESSOURCES[$document::class] ?? null);

        if ($ressource === null || ! $ressource::canAccess() || ! $ressource::canView($document)) {
            return null;
        }

        return $ressource::getUrl('view', ['record' => $document]);
    }

    /**
     * Ce que ce document de vente est : « Ticket » ou « Facture », selon
     * sa série.
     */
    public static function nature(Facture $document): string
    {
        return $document->serie() === TypeDocument::TicketCaisse ? 'Ticket' : 'Facture';
    }

    /**
     * Le numéro de ce document, en lien vers sa fiche pour qui peut la voir,
     * en texte simple pour les autres, échappé dans les deux cas : pour une
     * liste de numéros dans une seule entrée ou une seule colonne.
     */
    public static function numeroEnLien(Model $document, string $numero): Htmlable
    {
        $adresse = self::adresse($document);

        return new HtmlString($adresse === null
            ? e($numero)
            : '<a href="'.e($adresse).'" class="text-primary-600 underline dark:text-primary-400">'.e($numero).'</a>');
    }
}
