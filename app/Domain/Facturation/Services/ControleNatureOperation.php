<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Facturation\Exceptions\NatureOperationRefuseeException;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Tva\Services\TerritoireFiscal;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Une vente ne sort de la TVA française que si toutes les conditions de sa
 * nature sont réunies le jour de son émission (I1, I2, voir
 * NatureOperation) ; sinon elle reste à la TVA française, et l'émission
 * dit tout ce qui manque d'un coup (voir NatureOperationRefuseeException).
 *
 * La nature elle-même est permise au client et au magasin : un brouillon
 * ne s'enregistre que dans une nature que le type de son client et le
 * régime de la société permettent -- aucune nature réservée aux
 * professionnels pour un particulier, la seule vente en France en
 * franchise en base (voir ContexteDeVente::pourEnregistrer()) --, et son
 * émission est refusée avant ce contrôle si le client a changé de type ou
 * la société de régime depuis (voir EmettreFactureAction).
 *
 * - Toute autre nature que la vente en France exige le n° de TVA de la
 *   société, que la facture porte (règles BR-G-02, BR-IC-02 et BR-AE-02
 *   de la facture électronique). Il se lit par l'émetteur (voir
 *   Emetteur::duMagasin()), comme les autres réglages fiscaux.
 * - Export hors UE (art. 262 I du CGI) : des biens, et leurs frais
 *   accessoires, expédiés par le magasin à une adresse complète hors du
 *   territoire de TVA de l'Union, ni à Monaco ni dans un département
 *   d'outre-mer, qui a sa propre nature (voir TerritoireFiscal).
 * - Livraison vers un DOM (art. 294 du CGI) : des biens, et leurs frais
 *   accessoires, expédiés par le magasin à une adresse complète dans un
 *   département d'outre-mer autre que celui du magasin.
 * - Livraison intracommunautaire (art. 262 ter I du CGI) : des biens, et
 *   leurs frais accessoires, livrés à un professionnel à une adresse
 *   complète d'un autre État membre (l'Irlande du Nord comprise), à une
 *   date donnée, par un transport précisé, le client ayant un n° de TVA
 *   d'un autre État membre (XI admis) valide sur VIES ce jour-là.
 * - Prestation autoliquidée (art. 259-1° du CGI) : des services au lieu
 *   non fixé par la loi, à un professionnel établi dans un autre État
 *   membre, dont le n° de TVA de cet État (jamais FR ni XI) est valide
 *   sur VIES ce jour-là.
 * - Prestation hors UE (art. 259-1° du CGI) : des services au lieu non
 *   fixé par la loi, à un professionnel établi hors de l'Union (ni à
 *   Monaco), identifié par son n° de TVA ou un autre identifiant.
 *
 * Le client contrôlé est sa fiche, celle que la facture copie à son
 * émission (voir FigeLeClient). La consultation VIES retenue est la
 * dernière vérification faite ce jour-là à Paris du n° exact de cette
 * fiche, si elle l'a trouvé valide (voir consultationDuJour()), cherchée
 * dans le magasin actif (MagasinScope) : jamais une consultation reçue
 * d'un formulaire ni celle d'un autre magasin.
 */
class ControleNatureOperation
{
    /**
     * Refuse une facture dont la nature n'a pas toutes ses conditions, et
     * donne la consultation VIES qu'elle retient quand sa nature en exige
     * une (voir NatureOperation::exigeVies()).
     *
     * @param  CarbonInterface  $jour  l'instant de l'émission
     *
     * @throws NatureOperationRefuseeException
     */
    public function verifier(Facture $facture, CarbonInterface $jour): ?ConsultationVies
    {
        $manquements = $this->manquements($facture, $jour);

        if ($manquements !== []) {
            throw new NatureOperationRefuseeException($facture->nature_operation, $manquements);
        }

        return $facture->nature_operation->exigeVies() ? $this->consultationDuJour($facture->client, $jour) : null;
    }

    /**
     * Tout ce qui empêche la facture d'être émise dans sa nature, chacun en
     * une phrase sans point final ; rien pour une vente en France.
     *
     * @param  CarbonInterface  $jour  l'instant de l'émission
     * @return list<string>
     */
    public function manquements(Facture $facture, CarbonInterface $jour): array
    {
        $nature = $facture->nature_operation;

        if (! $nature->exonere()) {
            return [];
        }

        $client = $facture->client;
        $lignes = $facture->lignes()->with('service')->get();
        $emetteur = Emetteur::duMagasin($facture->magasin);

        return array_values(array_filter([
            ...match (true) {
                $nature->porteSurDesBiens() => $this->manquementsDeLaLivraison($facture, $lignes, $emetteur),
                $nature->porteSurDesServices() => $this->manquementsDeLaPrestation($facture, $client, $lignes),
            },
            ...($nature->exigeVies() ? $this->manquementsDuNumeroTva($nature, $client, $jour) : []),
            blank($emetteur->numeroTva) ? 'renseignez le n° de TVA intracommunautaire de la société (Administration de la société > Identité)' : null,
        ]));
    }

    /**
     * La dernière vérification, ce jour-là à Paris, du n° de TVA qu'a
     * aujourd'hui la fiche du client, dans le magasin actif, si elle l'a
     * trouvé valide ; null sinon : aucune vérification ce jour-là, ou la
     * dernière l'a trouvé inconnu de VIES, même après une vérification
     * valide plus tôt dans la journée -- le magasin sait alors que le n° ne
     * vaut plus, et la vente reste à la TVA française.
     */
    public function consultationDuJour(Client $client, CarbonInterface $jour): ?ConsultationVies
    {
        if (blank($client->numero_tva)) {
            return null;
        }

        $derniere = ConsultationVies::query()
            ->where('client_id', $client->getKey())
            ->where('numero_tva', $client->numero_tva)
            ->duJour($jour)
            ->latest('consultee_le')
            ->latest('id')
            ->first();

        return $derniere?->valide ? $derniere : null;
    }

    /**
     * Export, livraison vers un DOM, livraison intracommunautaire : la
     * destination, l'adresse, le transport et des lignes de biens ou de
     * frais accessoires.
     *
     * @param  Collection<int, LigneFacture>  $lignes
     * @return list<string|null>
     */
    private function manquementsDeLaLivraison(Facture $facture, Collection $lignes, Emetteur $emetteur): array
    {
        $nature = $facture->nature_operation;
        $pays = $facture->livraison_pays;
        $codePostal = $facture->livraison_code_postal;
        $adresseComplete = filled($facture->livraison_adresse) && filled($codePostal) && filled($facture->livraison_ville) && filled($pays);
        $servicesNonAccessoires = $lignes
            ->filter(fn (LigneFacture $ligne): bool => $ligne->service_id !== null && ! ($ligne->service?->frais_accessoires ?? false))
            ->map(fn (LigneFacture $ligne): string => "« {$ligne->description} »")
            ->unique()
            ->implode(', ');

        return [
            $adresseComplete ? null : 'indiquez l\'adresse de livraison complète (adresse, code postal, ville et pays)',
            ...($adresseComplete ? [$this->manquementDeLaDestination($nature, $pays, $codePostal, $emetteur)] : []),
            match (true) {
                $nature === NatureOperation::LivraisonIntracommunautaire && $facture->livraison_date === null => 'indiquez la date de livraison',
                default => null,
            },
            match (true) {
                $nature === NatureOperation::LivraisonIntracommunautaire && $facture->transport_par === null => 'indiquez qui transporte les biens',
                $nature->transportImpose() === TransportPar::Magasin && $facture->transport_par !== TransportPar::Magasin => 'les biens doivent être expédiés par le magasin ou pour son compte',
                default => null,
            },
            $servicesNonAccessoires === '' ? null : "{$servicesNonAccessoires} : seuls des biens et leurs frais de livraison ou accessoires (port, emballage, assurance) se vendent ainsi",
        ];
    }

    private function manquementDeLaDestination(NatureOperation $nature, string $pays, string $codePostal, Emetteur $emetteur): ?string
    {
        $departement = TerritoireFiscal::departementDom($pays, $codePostal);

        return match ($nature) {
            NatureOperation::ExportHorsUe => match (true) {
                $departement !== null => 'la livraison va dans un département d\'outre-mer : utilisez « '.NatureOperation::LivraisonDom->getLabel().' »',
                strtoupper($pays) === 'MC' => 'Monaco suit la TVA française',
                ! TerritoireFiscal::estHorsUePourDesBiens($pays, $codePostal) => 'la livraison reste dans le territoire de TVA de l\'Union européenne',
                default => null,
            },
            NatureOperation::LivraisonDom => match (true) {
                $departement === null => 'la livraison doit aller dans un département d\'outre-mer (Guadeloupe, Martinique, Guyane, La Réunion ou Mayotte)',
                $departement === TerritoireFiscal::departementDom($emetteur->pays, $emetteur->codePostal) => 'la livraison reste dans le département d\'outre-mer du magasin',
                default => null,
            },
            default => TerritoireFiscal::estDestinationUePourDesBiens($pays, $codePostal) ? null : 'la livraison doit arriver dans un autre État membre de l\'Union européenne',
        };
    }

    /**
     * Prestation autoliquidée ou hors UE : un client établi hors de France,
     * dans l'Union ou hors de l'Union, identifié, et des services au lieu
     * non fixé par la loi.
     *
     * @param  Collection<int, LigneFacture>  $lignes
     * @return list<string|null>
     */
    private function manquementsDeLaPrestation(Facture $facture, Client $client, Collection $lignes): array
    {
        $autoliquidation = $facture->nature_operation === NatureOperation::PrestationUeAutoliquidation;
        $biens = $lignes->whereNull('service_id')->map(fn (LigneFacture $ligne): string => "« {$ligne->description} »")->unique()->implode(', ');
        $lieuxFixes = $lignes
            ->filter(fn (LigneFacture $ligne): bool => $ligne->service?->lieu_specifique ?? false)
            ->map(fn (LigneFacture $ligne): string => "« {$ligne->description} »")
            ->unique()
            ->implode(', ');

        return [
            $client->estEtabliEnFrance() ? 'le client doit être établi hors de France' : null,
            match (true) {
                $client->estEtabliEnFrance() => null,
                $autoliquidation && ! TerritoireFiscal::estDansUnAutreEtatMembrePourDesServices($client->pays, $client->code_postal) => 'le client doit être établi dans un autre État membre de l\'Union européenne',
                ! $autoliquidation && ! TerritoireFiscal::estHorsUePourDesServices($client->pays, $client->code_postal) => 'le client doit être établi hors de l\'Union européenne',
                default => null,
            },
            ! $autoliquidation && blank($client->numero_tva) && blank($client->identifiant_etranger) ? 'indiquez le n° de TVA ou un autre identifiant du client sur sa fiche' : null,
            $biens === '' ? null : "{$biens} : seuls des services se facturent ainsi",
            $lieuxFixes === '' ? null : "le lieu de {$lieuxFixes} est fixé par la loi (art. 259 A du CGI) : la TVA française s'applique",
        ];
    }

    /**
     * Le n° de TVA du client, d'un autre État membre (XI admis pour des
     * biens, jamais pour des services), valide sur VIES le jour de
     * l'émission.
     *
     * @return list<string|null>
     */
    private function manquementsDuNumeroTva(NatureOperation $nature, Client $client, CarbonInterface $jour): array
    {
        $prefixe = TerritoireFiscal::prefixeTvaUe($client->numero_tva);
        $prefixesRefuses = $nature === NatureOperation::LivraisonIntracommunautaire ? ['FR'] : ['FR', 'XI'];

        if ($prefixe === null || in_array($prefixe, $prefixesRefuses, true)) {
            return [$nature === NatureOperation::LivraisonIntracommunautaire
                ? 'le client doit avoir un n° de TVA d\'un autre État membre de l\'Union (ou XI pour l\'Irlande du Nord)'
                : 'le client doit avoir un n° de TVA d\'un autre État membre de l\'Union (XI ne vaut que pour des biens)'];
        }

        return [$this->consultationDuJour($client, $jour) === null
            ? "le n° de TVA {$client->numero_tva} n'a pas été trouvé valide sur VIES le {$jour->copy()->setTimezone(config('app.timezone'))->format('d/m/Y')}"
            : null];
    }
}
