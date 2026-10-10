<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\LignesRetirees;
use LogicException;

/**
 * Un brouillon n'a aucune existence légale ni fiscale (voir
 * FacturePolicy::delete()) : contrairement à une facture émise, le modifier
 * en place -- y compris ses lignes -- ne pose aucun problème de
 * conservation. Ça évite à l'utilisateur de devoir tout ressaisir pour
 * corriger une erreur de saisie.
 *
 * Un brouillon reste pourtant une vente commencée : chaque article qu'il
 * perd -- ligne retirée, produit ou service remplacé, quantité baissée --
 * s'inscrit au journal des événements (JET 323, voir LignesRetirees),
 * comme sa suppression y inscrit l'abandon de la vente (voir
 * Facture::delete()). Tout se fait donc sous le verrou du registre fiscal
 * du magasin, le brouillon relu verrouillé : si le journal ne s'écrit pas,
 * le brouillon reste tel quel.
 *
 * Dès l'émission, cette action redevient interdite (voir
 * FacturePolicy::update()) : une facture numérotée se corrige par un avoir,
 * jamais en la réécrivant.
 *
 * Comme CreerFactureAction, elle reçoit le client déjà résolu par une
 * requête limitée au magasin, jamais un client_id brut venu du formulaire,
 * et note de nouveau son type : un brouillon réenregistré après que son
 * client a changé de type s'émet dans l'autre série (voir
 * EmettreFactureAction).
 */
class ModifierFactureAction
{
    public function __construct(
        private readonly ConstructeurLigneDocument $constructeurLigne,
        private readonly CalculateurLigne $calculateurLigne,
        private readonly RegistreFiscal $registre,
        private readonly LignesRetirees $lignesRetirees,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs
     *
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(Facture $facture, Client $client, array $lignes, array $attributs = []): Facture
    {
        return $this->registre->sousVerrou($facture->magasin, function () use ($facture, $client, $lignes, $attributs): Facture {
            $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->firstOrFail();

            if ($facture->statut !== StatutFacture::Brouillon) {
                throw new LogicException('Seule une facture brouillon peut être modifiée.');
            }

            $avant = $facture->lignes
                ->map(fn (LigneFacture $ligne): array => $this->lignesRetirees->comparable($ligne->produit_id, $ligne->service_id, $ligne->description, $ligne->quantite, $ligne->prix_unitaire_ttc))
                ->all();

            $facture->fill($attributs)->forceFill(['client_id' => $client->getKey(), 'type_client' => $client->type_client])->save();

            $facture->lignes()->delete();

            $lignesConstruites = $this->constructeurLigne->construireToutes($lignes);

            foreach ($lignesConstruites as $ligne) {
                $facture->lignes()->create($ligne);
            }

            $apres = array_map(
                fn (array $ligne): array => $this->lignesRetirees->comparable($ligne['produit_id'], $ligne['service_id'], $ligne['description'], $ligne['quantite'], $ligne['prix_unitaire_ttc']),
                $lignesConstruites,
            );

            foreach ($this->lignesRetirees->entre($avant, $apres) as $retrait) {
                $this->registre->journaliser(CodeEvenement::LigneRetiree, ['origine' => 'brouillon', 'facture_id' => $facture->getKey(), ...$retrait]);
            }

            $facture->forceFill($this->calculateurLigne->totaliser($lignesConstruites))->save();

            return $facture->fresh('lignes');
        });
    }
}
