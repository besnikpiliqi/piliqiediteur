<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\DerogationsDePrix;
use App\Domain\Facturation\Services\LignesRetirees;
use App\Domain\Facturation\Services\PiedDePage;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
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
 * et chaque baisse du prix d'un article resté, avec son prix d'avant et
 * d'après (Q8), s'inscrit au journal des événements (JET 323, voir
 * LignesRetirees),
 * comme sa suppression y inscrit l'abandon de la vente (voir
 * Facture::delete()). Tout se fait donc sous le verrou du registre fiscal
 * du magasin, premier verrou de sa transaction, puis la ligne du magasin
 * sous un verrou partagé (un magasin suspendu, fermé ou cédé, ou dont la
 * société est en lecture seule, ne modifie plus rien, voir
 * Magasin::verifierQuIlEstOuvert()), puis son auteur sous le verrou de sa
 * ligne, puis le brouillon sous le sien : une fermeture du magasin, qui
 * prend le même registre (voir FermerMagasinAction), passe tout entière
 * avant ou après la modification, et aucune ligne retirée ne s'inscrit
 * après elle. Un brouillon supprimé ou émis entre-temps est refusé ; si
 * le journal ne s'écrit pas, le brouillon reste tel quel.
 *
 * Dès l'émission, cette action redevient interdite (voir
 * FacturePolicy::update()) : une facture numérotée se corrige par un avoir,
 * jamais en la réécrivant.
 *
 * Comme CreerFactureAction, elle reçoit le client déjà résolu par une
 * requête limitée au magasin, jamais un client_id brut venu du formulaire,
 * et note de nouveau son type, le régime de TVA de la société et la nature de
 * l'opération, avec lesquels les lignes se recalculent : un brouillon
 * réenregistré après que
 * son client a changé de type s'émet dans l'autre série, et celui
 * réenregistré après un changement de régime de la société s'émet de nouveau
 * (voir EmettreFactureAction).
 *
 * Son auteur, relu sous le verrou de sa ligne avant le brouillon, doit
 * pouvoir modifier le brouillon relu (voir FacturePolicy::update() et
 * LecteurDesDroits) ; le journal des événements l'inscrit à son nom. Une remise ou un prix
 * dérogatoire qu'il introduit, ligne nouvelle ou changée, exige qu'il soit
 * autorisé à accorder des remises et fixer les prix ; une ligne reprise
 * telle quelle garde l'accord déjà donné (R2, voir DerogationsDePrix).
 */
class ModifierFactureAction
{
    public function __construct(
        private readonly ConstructeurLigneDocument $constructeurLigne,
        private readonly RegistreFiscal $registre,
        private readonly LignesRetirees $lignesRetirees,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly DerogationsDePrix $derogations,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException quand les conditions de paiement sont trop longues pour le pied du PDF (voir PiedDePage)
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(User $auteur, Facture $facture, Client $client, array $lignes, array $attributs = []): Facture
    {
        PiedDePage::verifierLesConditionsDePaiement($attributs['conditions_paiement'] ?? null);

        $magasin = app(TenantContext::class)->magasin();

        return $this->registre->sousVerrou($magasin, function () use ($auteur, $facture, $client, $lignes, $attributs, $magasin): Facture {
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, Facture::class);

            $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(Facture::REFUS_INTROUVABLE);

            if ($facture->statut !== StatutFacture::Brouillon) {
                throw new LogicException('Seule une facture brouillon peut être modifiée.');
            }

            $this->lecteur->exiger($compte, 'update', $magasin, $facture);

            return $this->operateur->pendant($compte, fn (): Facture => $this->modifier($compte, $facture, $client, $lignes, $attributs));
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    private function modifier(User $compte, Facture $facture, Client $client, array $lignes, array $attributs): Facture
    {
        $avant = $facture->lignes
            ->map(fn (LigneFacture $ligne): array => $this->lignesRetirees->comparable($ligne->produit_id, $ligne->service_id, $ligne->description, $ligne->quantite, $ligne->prix_unitaire_ttc))
            ->all();

        $contexte = ContexteDeVente::pourEnregistrer($client, $facture->magasin, $facture->fill($attributs)->noterSaNatureDOperation($attributs));
        $document = $this->constructeurLigne->construireToutes($lignes, $contexte);
        $this->derogations->exiger($compte, $facture->lignes->map(fn (LigneFacture $ligne): array => $ligne->attributesToArray())->all(), $document->lignes, $contexte);
        $facture->forceFill(['client_id' => $client->getKey(), ...$contexte->colonnes()])->save();

        $facture->lignes()->delete();

        foreach ($document->lignes as $ligne) {
            $facture->lignes()->create($ligne);
        }

        $apres = array_map(
            fn (array $ligne): array => $this->lignesRetirees->comparable($ligne['produit_id'], $ligne['service_id'], $ligne['description'], $ligne['quantite'], $ligne['prix_unitaire_ttc']),
            $document->lignes,
        );

        foreach ($this->lignesRetirees->entre($avant, $apres) as $retrait) {
            $this->registre->journaliser(CodeEvenement::LigneRetiree, ['origine' => 'brouillon', 'facture_id' => $facture->getKey(), ...$retrait]);
        }

        $facture->forceFill($document->totaux)->save();

        return $facture->fresh('lignes');
    }
}
