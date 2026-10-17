<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\QuantiteEntiere;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Un avoir est rattaché à un CLIENT, pas à une seule facture : il peut
 * regrouper des lignes provenant de plusieurs factures différentes de ce
 * même client en une seule fois (ex : un client retourne des articles
 * achetés lors de visites différentes). La loi n'impose pas qu'une facture
 * rectificative porte sur une seule facture d'origine, seulement qu'elle
 * référence précisément ce qu'elle corrige -- chaque ligne garde sa trace
 * vers sa ligne de facture d'origine (voir LigneAvoir::ligne_facture_id).
 *
 * Il peut aussi être partiel : porter sur une sélection de lignes, et sur
 * une quantité partielle de chacune. Plusieurs avoirs successifs restent
 * possibles sur une même ligne de facture (ex : un second défaut découvert
 * après un premier avoir), tant que leur somme ne dépasse jamais la
 * quantité facturée (voir LigneFacture::quantiteCreditableRestante()).
 *
 * Jamais de tickets de caisse et de factures dans un même avoir : leurs
 * circuits fiscaux diffèrent (clôture de caisse et e-reporting, ou
 * facture électronique), et un avoir n'en suit qu'un (voir
 * Facture::serie()). Il prend donc le client des documents qu'il corrige,
 * tel qu'ils l'ont figé à leur émission : leur type dès sa création,
 * particulier pour des tickets, professionnel pour des factures, et à son
 * émission la copie de son nom, de son adresse et de ses identifiants
 * (voir Avoir::clientAFiger()).
 *
 * Jamais non plus de documents émis en franchise en base de TVA et
 * d'autres : l'avoir reprend leur franchise, dont il imprime la mention
 * (voir MentionsFiscales), et ses lignes leurs taux, 0 % en franchise. Ni
 * de documents de natures d'opération différentes (une vente en France et
 * un export...) : l'avoir reprend leur nature et sa mention, ses lignes
 * se calculent comme les leurs, sans TVA hors de la vente en France, et
 * la facture électronique interdit de mélanger une prestation hors du
 * champ de la TVA avec une autre catégorie (règle BR-O-11).
 *
 * L'avoir créé ici reste un brouillon jusqu'à son émission : l'interface
 * passe toujours par EnregistrerAvoirAction, qui fait les deux d'un seul
 * geste.
 *
 * Son auteur, relu en base, doit pouvoir créer un avoir dans le magasin
 * actif et voir chaque facture qu'il corrige (voir FacturePolicy::view() :
 * un vendeur sans « voir celles de toute l'équipe » ne corrige que les
 * siennes) ; c'est lui que l'avoir enregistre comme auteur.
 */
class CreerAvoirAction
{
    public function __construct(
        private readonly CalculateurLigne $calculateurLigne,
        private readonly LecteurDesDroits $lecteur,
    ) {}

    /**
     * @param  list<array{ligne_facture_id: int|string, quantite: int|string}>  $lignesSelectionnees
     *
     * @throws AuthorizationException
     */
    public function handle(User $auteur, Client $client, array $lignesSelectionnees, ?string $motif = null): Avoir
    {
        if ($lignesSelectionnees === []) {
            throw new InvalidArgumentException('Un avoir doit porter sur au moins une ligne.');
        }

        // Une même ligne deux fois dans un avoir échapperait au plafond de
        // la quantité restante (calculé sur les avoirs déjà émis) et au
        // solde exact du dernier retour.
        $identifiantsLignes = array_map(fn (array $selection): int => (int) $selection['ligne_facture_id'], $lignesSelectionnees);

        if (count($identifiantsLignes) !== count(array_unique($identifiantsLignes))) {
            throw new InvalidArgumentException('Une même ligne de facture ne peut apparaître qu\'une fois dans un avoir.');
        }

        return DB::transaction(function () use ($auteur, $client, $lignesSelectionnees, $motif) {
            $magasin = app(TenantContext::class)->magasin();
            $compte = $this->lecteur->exiger($auteur, 'create', $magasin, Avoir::class);
            $lignesDeLAvoir = [];
            $premiere = null;

            foreach (array_values($lignesSelectionnees) as $index => $selection) {
                $ligneFacture = LigneFacture::query()
                    ->with('facture')
                    ->whereKey((int) $selection['ligne_facture_id'])
                    ->lockForUpdate()
                    ->first();

                if ($ligneFacture === null || $ligneFacture->facture === null) {
                    throw new InvalidArgumentException('Cette ligne de facture est introuvable.');
                }

                $facture = $ligneFacture->facture;

                if ((int) $facture->client_id !== $client->getKey()) {
                    throw new InvalidArgumentException("Cette ligne appartient à une facture d'un autre client.");
                }

                $this->lecteur->exiger($compte, 'view', $magasin, $facture);

                if ($facture->statut === StatutFacture::Brouillon) {
                    throw new LogicException("Impossible de créditer une ligne d'une facture au statut [{$facture->statut->value}].");
                }

                $premiere ??= $facture;
                $incompatibilite = self::incompatibilite($premiere, $facture);

                if ($incompatibilite !== null) {
                    throw new InvalidArgumentException($incompatibilite);
                }

                $restante = $ligneFacture->quantiteCreditableRestante();
                $quantite = QuantiteEntiere::depuisSaisie($selection['quantite'] ?? null, 'La quantité à créditer');

                if ($quantite > $restante) {
                    throw new LogicException("La ligne \"{$ligneFacture->description}\" ne peut pas être créditée au-delà de la quantité restante ({$restante}).");
                }

                $lignesDeLAvoir[] = [
                    'ligne_facture_id' => $ligneFacture->id,
                    'produit_id' => $ligneFacture->produit_id,
                    'nature' => $ligneFacture->nature,
                    'description' => $ligneFacture->description,
                    'reference' => $ligneFacture->reference,
                    'quantite' => $quantite,
                    'prix_unitaire_ht' => $ligneFacture->prix_unitaire_ht,
                    'prix_unitaire_ttc' => $ligneFacture->prix_unitaire_ttc,
                    'taux_tva' => $ligneFacture->taux_tva,
                    'taux_tva_du_prix' => $ligneFacture->taux_tva_du_prix,
                    'remise_pourcentage' => $ligneFacture->remise_pourcentage,
                    'ordre' => $index,
                    ...$ligneFacture->montantsDuRetour($quantite),
                ];
            }

            $avoir = new Avoir(['motif' => $motif]);
            $avoir->forceFill([
                'client_id' => $client->getKey(),
                'cree_par' => $compte->getKey(),
                'type_client' => $premiere->type_client,
                'franchise_en_base' => $premiere->franchise_en_base,
                'nature_operation' => $premiere->nature_operation,
                ...$this->calculateurLigne->totaliser($lignesDeLAvoir),
            ])->save();

            foreach ($lignesDeLAvoir as $ligne) {
                $avoir->lignes()->create($ligne);
            }

            return $avoir->fresh('lignes');
        });
    }

    /**
     * Pourquoi $facture n'entre pas dans le même avoir que $premiere, la
     * première facture qu'il corrige (voir le docblock de la classe) :
     * jamais de tickets de caisse et de factures, de documents émis en
     * franchise de TVA et d'autres, ni de natures d'opération différentes.
     * Null si rien ne l'empêche. L'écran « Nouvel avoir » refuse ainsi
     * d'ajouter un document, avec le même message que l'action (voir
     * AvoirResource::lignesAvecLeDocument()).
     */
    public static function incompatibilite(Facture $premiere, Facture $facture): ?string
    {
        return match (true) {
            $facture->type_client !== $premiere->type_client => 'Un avoir ne mélange jamais des tickets de caisse et des factures : faites un avoir pour les tickets, et un autre pour les factures.',
            $facture->franchise_en_base !== $premiere->franchise_en_base => 'Un avoir ne mélange pas des factures émises en franchise de TVA et d\'autres : faites deux avoirs.',
            $facture->nature_operation !== $premiere->nature_operation => 'Un avoir ne mélange pas des factures de natures d\'opération différentes : faites un avoir par nature.',
            default => null,
        };
    }
}
