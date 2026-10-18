<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\QuantiteEntiere;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Un avoir fournisseur (retour de marchandise) référence une facture
 * d'achat pour la traçabilité (quel produit, quelle facture d'achat
 * d'origine) SANS jamais la modifier : elle reste un document figé, au
 * même titre qu'une facture de vente émise.
 *
 * Il peut être partiel : porter sur une sélection de lignes, et sur une
 * quantité partielle de chacune (même logique que CreerAvoirAction côté
 * vente). Un produit déjà vendu à un client ne peut jamais être inclus :
 * voir LigneAchat::quantiteRetournableRestante(), qui plafonne la
 * quantité retournable de chaque ligne au stock actuellement disponible du
 * produit, et AvoirFournisseur::verifierQueLeStockSuffit(), qui plafonne
 * aussi le total des lignes d'un même produit.
 *
 * Il garde le n° de l'avoir du fournisseur, facultatif : l'écran le
 * demande avec un avertissement, sans refus, quand il manque (voir
 * FactureAchatResource::creerUnAvoirFournisseur()), car c'est lui qui
 * rapprochera l'avoir que le fournisseur enverra par la plateforme de
 * facturation électronique de celui saisi ici.
 *
 * Son auteur, relu en base (voir LecteurDesDroits), doit pouvoir créer un
 * avoir fournisseur ; l'avoir l'enregistre comme auteur.
 */
class CreerAvoirFournisseurAction
{
    public function __construct(
        private readonly CalculateurLigne $calculateurLigne,
        private readonly LecteurDesDroits $lecteur,
    ) {}

    /**
     * Le plus long n° d'avoir du fournisseur, comme celui de sa facture
     * (voir la colonne numero_fournisseur des deux tables).
     */
    public const LONGUEUR_NUMERO_FOURNISSEUR = 60;

    /**
     * @param  list<array{ligne_achat_id: int|string, quantite: int|string}>  $lignesSelectionnees
     * @param  string|null  $numeroFournisseur  le n° de l'avoir chez le fournisseur, facultatif
     *
     * @throws AuthorizationException
     */
    public function handle(User $auteur, FactureAchat $factureAchat, array $lignesSelectionnees, ?string $motif = null, ?string $numeroFournisseur = null): AvoirFournisseur
    {
        $numeroFournisseur = self::numeroFournisseur($numeroFournisseur);

        return DB::transaction(function () use ($auteur, $factureAchat, $lignesSelectionnees, $motif, $numeroFournisseur) {
            $compte = $this->lecteur->exiger($auteur, 'create', app(TenantContext::class)->magasin(), AvoirFournisseur::class);
            $factureAchat = FactureAchat::query()->whereKey($factureAchat->getKey())->lockForUpdate()->firstOrFail();
            $factureAchat->load('lignes');

            if ($factureAchat->statut !== StatutFactureAchat::Validee) {
                throw new LogicException("Impossible de créer un avoir fournisseur sur une facture d'achat au statut [{$factureAchat->statut->value}].");
            }

            if ($lignesSelectionnees === []) {
                throw new InvalidArgumentException('Un avoir fournisseur doit porter sur au moins une ligne.');
            }

            // Même règle que CreerAvoirAction : une ligne une seule fois.
            $identifiantsLignes = array_map(fn (array $selection): int => (int) $selection['ligne_achat_id'], $lignesSelectionnees);

            if (count($identifiantsLignes) !== count(array_unique($identifiantsLignes))) {
                throw new InvalidArgumentException('Une même ligne de facture d\'achat ne peut apparaître qu\'une fois dans un avoir fournisseur.');
            }

            $avoir = (new AvoirFournisseur([
                'facture_achat_id' => $factureAchat->id,
                'numero_fournisseur' => $numeroFournisseur,
                'motif' => $motif,
            ]))->forceFill(['cree_par' => $compte->getKey()]);
            $avoir->save();

            $lignesConstruites = [];

            foreach (array_values($lignesSelectionnees) as $index => $selection) {
                $ligneAchat = $factureAchat->lignes->firstWhere('id', (int) $selection['ligne_achat_id']);

                if ($ligneAchat === null) {
                    throw new InvalidArgumentException("Cette ligne n'appartient pas à la facture d'achat créditée.");
                }

                $restante = $ligneAchat->quantiteRetournableRestante();
                $quantite = QuantiteEntiere::depuisSaisie($selection['quantite'] ?? null, 'La quantité à retourner');

                if ($quantite > $restante) {
                    throw new LogicException("La ligne \"{$ligneAchat->description}\" ne peut pas être retournée au-delà de la quantité disponible ({$restante}) : elle a déjà été vendue ou déjà retournée.");
                }

                $montants = $ligneAchat->montantsDuRetour($quantite);

                $avoir->lignes()->create([
                    'ligne_achat_id' => $ligneAchat->id,
                    'produit_id' => $ligneAchat->produit_id,
                    'description' => $ligneAchat->description,
                    'reference' => $ligneAchat->reference,
                    'quantite' => $quantite,
                    'prix_unitaire_ht' => $ligneAchat->prix_unitaire_ht,
                    'taux_tva' => $ligneAchat->taux_tva,
                    'remise_pourcentage' => $ligneAchat->remise_pourcentage,
                    'ordre' => $index,
                    ...$montants,
                ]);

                $lignesConstruites[] = $montants;
            }

            $avoir->verifierQueLeStockSuffit();
            $avoir->forceFill($this->calculateurLigne->totaliser($lignesConstruites))->save();

            return $avoir->fresh('lignes');
        });
    }

    /**
     * Le n° saisi, sans espaces autour ; rien de saisi, aucun n°.
     *
     * @throws InvalidArgumentException
     */
    private static function numeroFournisseur(?string $saisie): ?string
    {
        $numero = trim($saisie ?? '');

        if (mb_strlen($numero) > self::LONGUEUR_NUMERO_FOURNISSEUR) {
            throw new InvalidArgumentException('Le n° de l\'avoir du fournisseur ne dépasse pas '.self::LONGUEUR_NUMERO_FOURNISSEUR.' caractères.');
        }

        return $numero === '' ? null : $numero;
    }
}
