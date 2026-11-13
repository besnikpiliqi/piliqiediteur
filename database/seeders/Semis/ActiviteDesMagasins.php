<?php

namespace Database\Seeders\Semis;

use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Actions\RepondreAuDevisEnLigneAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Actions\CreerMandatPdpAction;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use LogicException;

/**
 * L'activité des magasins semés, jour après jour (voir HorlogeDuSemis),
 * chaque geste par sa vraie action, au nom de la personne qui le fait (voir
 * GestesDuMagasin) : fiches, achats validés (l'un avec la pièce du
 * fournisseur, l'autre sans), inventaires d'ouverture, tickets à la caisse
 * en un et plusieurs modes, factures payées, payées en partie et en
 * retard, devis en brouillon, envoyé, accepté en ligne et transformé en
 * facture, bon d'avoir du client comptoir, avoir d'un client imputé
 * d'office sur sa facture impayée, crédit d'un client gardé pour sa
 * prochaine commande, avoir fournisseur, contre-passation d'un paiement,
 * caisse personnelle fermée par son vendeur et journée clôturée par une
 * responsable ; les autres journées se clôturent et les autres caisses se
 * ferment d'office à minuit (voir ClotureAutomatiqueCaisse).
 */
final class ActiviteDesMagasins
{
    public function __construct(
        private readonly HorlogeDuSemis $horloge,
        private readonly SemisDeDemonstration $semis,
    ) {}

    public function semer(): void
    {
        $halles = new GestesDuMagasin($this->semis->magasin('halles'));
        $republique = new GestesDuMagasin($this->semis->magasin('republique'));
        $conseil = new GestesDuMagasin($this->semis->magasin('conseil'));
        $epicerie = new GestesDuMagasin($this->semis->magasin('epicerie'));

        $this->horloge->aller(2, '11:00');
        $halles->dans(fn () => app(CreerMandatPdpAction::class)->handle($halles->magasin, $this->semis->proprietaire('commerce')));
        $this->remplirLesHalles($halles);
        $this->remplirLaRepublique($republique);
        $this->remplirLeConseil($conseil);
        $this->remplirLEpicerie($epicerie);

        $this->premieresSemaines($halles, $republique, $conseil, $epicerie);
        $this->joursSuivants($halles, $republique, $epicerie);
    }

    /**
     * Les fiches et les achats du magasin des Halles : deux fournisseurs,
     * deux clients professionnels, une cliente particulière, deux services,
     * et deux factures d'achat validées, l'une avec la pièce du
     * fournisseur, l'autre sans, qui font naître six produits.
     */
    private function remplirLesHalles(GestesDuMagasin $halles): void
    {
        $camille = $this->semis->compte('Camille Laurent');
        $grossiste = $halles->fiche($camille, Fournisseur::class, ['nom' => 'Grossiste Lumière', 'siret' => IdentifiantsInsee::avecSaCle('0000002160001'), 'adresse' => '8 rue des Artisans', 'code_postal' => '69007', 'ville' => 'Lyon', 'pays' => 'FR', 'email' => 'commandes@grossiste-lumiere.exemple.test']);
        $papeterie = $halles->fiche($camille, Fournisseur::class, ['nom' => 'Papeterie du Centre', 'adresse' => '3 place du Marché', 'code_postal' => '45000', 'ville' => 'Orléans', 'pays' => 'FR']);
        $halles->fiche($camille, Client::class, ['nom' => 'Hôtel des Arts', 'type_client' => TypeClient::Professionnel, 'siren' => IdentifiantsInsee::avecSaCle('00000022'), 'adresse' => '14 rue de Rivoli', 'code_postal' => '75004', 'ville' => 'Paris', 'pays' => 'FR', 'email' => 'comptabilite@hotel-des-arts.exemple.test', 'mode_paiement_prefere' => ModePaiement::Virement->value]);
        $halles->fiche($camille, Client::class, ['nom' => 'Agence Horizon', 'type_client' => TypeClient::Professionnel, 'siren' => IdentifiantsInsee::avecSaCle('00000023'), 'adresse' => '27 boulevard Voltaire', 'code_postal' => '75011', 'ville' => 'Paris', 'pays' => 'FR', 'email' => 'contact@agence-horizon.exemple.test']);
        $halles->fiche($camille, Client::class, ['nom' => 'Marie Dupuis', 'type_client' => TypeClient::Particulier, 'pays' => 'FR', 'email' => 'marie.dupuis@exemple.test']);
        $halles->fiche($camille, Service::class, ['nom' => 'Emballage cadeau', 'prix_ttc' => '3.00', 'taux_tva' => '20', 'actif' => true]);
        $halles->fiche($camille, Service::class, ['nom' => 'Livraison à domicile', 'prix_ttc' => '9.90', 'taux_tva' => '20', 'actif' => true]);

        $halles->acheter($camille, $grossiste, [
            ['produit' => ['nom' => 'Bougie parfumée figue', 'reference' => 'BOUGIE-FIGUE', 'prix_vente_ttc' => '18.00', 'taux_tva' => '20', 'suivi_stock' => true, 'seuil_alerte' => 5], 'quantite' => 40, 'prix_unitaire_ht' => '6.00'],
            ['produit' => ['nom' => 'Diffuseur en bambou', 'reference' => 'DIFF-BAMBOU', 'prix_vente_ttc' => '29.90', 'taux_tva' => '20', 'suivi_stock' => true, 'seuil_alerte' => 3], 'quantite' => 15, 'prix_unitaire_ht' => '11.50'],
            ['produit' => ['nom' => 'Tasse en grès', 'reference' => 'TASSE-GRES', 'prix_vente_ttc' => '12.50', 'taux_tva' => '20', 'suivi_stock' => true, 'seuil_alerte' => 6], 'quantite' => 30, 'prix_unitaire_ht' => '4.20'],
            ['produit' => ['nom' => 'Thé vert Sencha 100 g', 'reference' => 'THE-SENCHA', 'prix_vente_ttc' => '7.90', 'taux_tva' => '5.5', 'suivi_stock' => true, 'seuil_alerte' => 5], 'quantite' => 25, 'prix_unitaire_ht' => '3.10'],
        ], ['date_facture' => $this->horloge->jour(1), 'numero_fournisseur' => 'GL-2026-118'], [new PieceDeposee('Facture GL-2026-118.pdf', self::pieceDuFournisseur('Grossiste Lumière', 'GL-2026-118'))]);

        $halles->acheter($camille, $papeterie, [
            ['produit' => ['nom' => 'Carnet A5 ligné', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20', 'suivi_stock' => true, 'seuil_alerte' => 10], 'quantite' => 50, 'prix_unitaire_ht' => '1.40'],
            ['produit' => ['nom' => 'Stylo plume laqué', 'reference' => 'STYLO-PLUME', 'prix_vente_ttc' => '24.00', 'taux_tva' => '20', 'suivi_stock' => true, 'seuil_alerte' => 2], 'quantite' => 10, 'prix_unitaire_ht' => '9.00'],
        ], ['date_facture' => $this->horloge->jour(2), 'numero_fournisseur' => 'PC-5521']);
    }

    /**
     * L'inventaire d'ouverture du magasin de la République, saisi et validé
     * par son responsable : son stock existant au démarrage, avec son coût.
     */
    private function remplirLaRepublique(GestesDuMagasin $republique): void
    {
        $hugo = $this->semis->compte('Hugo Lefebvre');
        $republique->ouvrirLeStock($hugo, [
            ['produit' => ['nom' => 'Plaid en laine', 'reference' => 'PLAID-LAINE', 'prix_vente_ttc' => '49.00', 'taux_tva' => '20', 'seuil_alerte' => 3], 'quantite' => 12, 'cout_unitaire_ht' => '18.00'],
            ['produit' => ['nom' => 'Coussin en lin', 'reference' => 'COUSSIN-LIN', 'prix_vente_ttc' => '25.00', 'taux_tva' => '20', 'seuil_alerte' => 4], 'quantite' => 20, 'cout_unitaire_ht' => '8.50'],
            ['produit' => ['nom' => 'Bougie parfumée figue', 'reference' => 'BOUGIE-FIGUE', 'prix_vente_ttc' => '18.00', 'taux_tva' => '20', 'seuil_alerte' => 5], 'quantite' => 15, 'cout_unitaire_ht' => '6.00'],
        ]);
        $republique->fiche($hugo, Client::class, ['nom' => 'Studio Nord', 'type_client' => TypeClient::Professionnel, 'siren' => IdentifiantsInsee::avecSaCle('00000024'), 'adresse' => '5 rue du Faubourg', 'code_postal' => '75010', 'ville' => 'Paris', 'pays' => 'FR', 'email' => 'bonjour@studio-nord.exemple.test']);
    }

    /**
     * Le catalogue de services et les clients du cabinet de conseil, créés
     * par sa responsable : une société de services n'a ni produit, ni
     * fournisseur, ni achat.
     */
    private function remplirLeConseil(GestesDuMagasin $conseil): void
    {
        $claire = $this->semis->compte('Claire Dubois');
        $conseil->fiche($claire, Service::class, ['nom' => 'Heure de conseil', 'prix_ttc' => '90.00', 'taux_tva' => '20', 'actif' => true]);
        $conseil->fiche($claire, Service::class, ['nom' => 'Audit de site web', 'prix_ttc' => '1200.00', 'taux_tva' => '20', 'actif' => true]);
        $conseil->fiche($claire, Service::class, ['nom' => 'Forfait déplacement', 'prix_ttc' => '45.00', 'taux_tva' => '20', 'actif' => true]);
        $conseil->fiche($claire, Client::class, ['nom' => 'Boulangerie Martin', 'type_client' => TypeClient::Professionnel, 'siren' => IdentifiantsInsee::avecSaCle('00000025'), 'adresse' => '2 rue Lepic', 'code_postal' => '75018', 'ville' => 'Paris', 'pays' => 'FR', 'email' => 'boulangerie.martin@exemple.test', 'delai_paiement_jours' => 30]);
        $conseil->fiche($claire, Client::class, ['nom' => 'Cabinet dentaire Morel', 'type_client' => TypeClient::Professionnel, 'siren' => IdentifiantsInsee::avecSaCle('00000026'), 'adresse' => '40 avenue Mozart', 'code_postal' => '75016', 'ville' => 'Paris', 'pays' => 'FR', 'email' => 'accueil@dentaire-morel.exemple.test']);
        $conseil->fiche($claire, Client::class, ['nom' => 'Paul Garnier', 'type_client' => TypeClient::Particulier, 'pays' => 'FR']);
    }

    /**
     * L'inventaire d'ouverture de l'épicerie invitée par le cabinet, saisi
     * et validé par son responsable.
     */
    private function remplirLEpicerie(GestesDuMagasin $epicerie): void
    {
        $epicerie->ouvrirLeStock($this->semis->compte('Nicolas Lemoine'), [
            ['produit' => ['nom' => 'Huile d\'olive 50 cl', 'reference' => 'HUILE-OLIVE', 'prix_vente_ttc' => '11.90', 'taux_tva' => '5.5', 'seuil_alerte' => 5], 'quantite' => 30, 'cout_unitaire_ht' => '6.20'],
            ['produit' => ['nom' => 'Miel de lavande 250 g', 'reference' => 'MIEL-LAVANDE', 'prix_vente_ttc' => '8.50', 'taux_tva' => '5.5', 'seuil_alerte' => 5], 'quantite' => 24, 'cout_unitaire_ht' => '4.10'],
            ['produit' => ['nom' => 'Panier garni', 'reference' => 'PANIER-GARNI', 'prix_vente_ttc' => '39.00', 'taux_tva' => '20', 'seuil_alerte' => 2], 'quantite' => 8, 'cout_unitaire_ht' => '19.00'],
        ]);
    }

    /**
     * Les deux premières semaines de vente, avec chaque cas que l'éditeur
     * veut voir.
     */
    private function premieresSemaines(GestesDuMagasin $halles, GestesDuMagasin $republique, GestesDuMagasin $conseil, GestesDuMagasin $epicerie): void
    {
        $camille = $this->semis->compte('Camille Laurent');
        $lucas = $this->semis->compte('Lucas Moreau');
        $ines = $this->semis->compte('Inès Garcia');
        $claire = $this->semis->compte('Claire Dubois');
        $hotel = $this->client($halles, 'Hôtel des Arts');
        $horizon = $this->client($halles, 'Agence Horizon');
        $boulangerie = $this->client($conseil, 'Boulangerie Martin');
        $dentiste = $this->client($conseil, 'Cabinet dentaire Morel');

        $this->horloge->aller(3, '10:15');
        $halles->vendreALaCaisse($lucas, $halles->comptoir(), [$this->ligne($halles, 'BOUGIE-FIGUE', 2)]);
        $halles->vendreALaCaisse($ines, $halles->comptoir(), [$this->ligne($halles, 'THE-SENCHA', 3), $this->ligne($halles, 'CARNET-A5', 1)], ModePaiement::Especes, [[ModePaiement::CarteBancaire, 2000]]);
        $this->horloge->aller(3, '11:00');
        $halles->facturer($camille, $hotel, [$this->ligne($halles, 'DIFF-BAMBOU', 4), $this->ligne($halles, 'BOUGIE-FIGUE', 10)]);
        $conseil->facturer($claire, $boulangerie, [$this->ligne($conseil, 'Audit de site web', 1)]);
        $epicerie->vendreALaCaisse($this->semis->compte('Chloé Bernard'), $epicerie->comptoir(), [$this->ligne($epicerie, 'HUILE-OLIVE', 2), $this->ligne($epicerie, 'MIEL-LAVANDE', 1)], ModePaiement::CarteBancaire);

        $this->horloge->aller(5, '10:00');
        $facturePayee = $halles->facturer($camille, $horizon, [$this->ligne($halles, 'TASSE-GRES', 6), $this->ligne($halles, 'STYLO-PLUME', 2)]);
        $halles->encaisser($camille, $facturePayee, [new PartDeReglement(ModePaiement::Virement, $facturePayee->resteDuEnCentimes())], $this->horloge->jour(5));
        $conseil->facturer($claire, $dentiste, [$this->ligne($conseil, 'Heure de conseil', 3)], [new PartDeReglement(ModePaiement::Virement, 27000)]);
        $republique->vendreALaCaisse($this->semis->compte('Sarah Petit'), $republique->comptoir(), [$this->ligne($republique, 'PLAID-LAINE', 1)], ModePaiement::CarteBancaire);

        $this->horloge->aller(6, '11:00');
        $partielle = $halles->facturer($camille, $hotel, [$this->ligne($halles, 'THE-SENCHA', 10), $this->ligne($halles, 'CARNET-A5', 10)]);

        $this->horloge->aller(7, '10:00');
        $halles->dans(fn (): Devis => app(CreerDevisAction::class)->handle($camille, $horizon, [$this->ligne($halles, 'DIFF-BAMBOU', 10)], ['date_validite' => $this->validite()]));
        $devisEnvoye = $halles->dans(fn (): Devis => app(CreerDevisAction::class)->handle($camille, $hotel, [$this->ligne($halles, 'BOUGIE-FIGUE', 20), $this->ligne($halles, 'Emballage cadeau', 20)], ['date_validite' => $this->validite()]));
        $halles->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($camille, $devisEnvoye, 'comptabilite@hotel-des-arts.exemple.test', 'Bonjour, voici notre proposition pour vos chambres.', GestesDuMagasin::jeton()));
        $devisAAccepter = $halles->dans(fn (): Devis => app(CreerDevisAction::class)->handle($camille, $horizon, [$this->ligne($halles, 'STYLO-PLUME', 3), $this->ligne($halles, 'Livraison à domicile', 1)], ['date_validite' => $this->validite()]));
        $envoi = $halles->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($camille, $devisAAccepter, 'contact@agence-horizon.exemple.test', null, GestesDuMagasin::jeton()));
        $conseil->dans(fn (): Devis => app(CreerDevisAction::class)->handle($claire, $boulangerie, [$this->ligne($conseil, 'Heure de conseil', 5)], ['date_validite' => $this->validite()]));
        $conseil->dans(fn (): Devis => app(EmettreDevisAction::class)->handle($claire, app(CreerDevisAction::class)->handle($claire, $dentiste, [$this->ligne($conseil, 'Audit de site web', 1), $this->ligne($conseil, 'Forfait déplacement', 2)], ['date_validite' => $this->validite()]), GestesDuMagasin::jeton()));

        $this->horloge->aller(8, '09:30');
        $halles->encaisser($camille, $partielle, [new PartDeReglement(ModePaiement::Virement, 5000)], $this->horloge->jour(8));
        $conseil->vendreALaCaisse($this->semis->compte('Maxime Henry'), $this->client($conseil, 'Paul Garnier'), [$this->ligne($conseil, 'Forfait déplacement', 1), $this->ligne($conseil, 'Heure de conseil', 1)], ModePaiement::CarteBancaire);

        $this->horloge->aller(9, '16:20');
        app(TenantContext::class)->clear();
        app(RepondreAuDevisEnLigneAction::class)->accepter(self::jetonDuDevis($envoi), 'Sophie Arnaud', true);

        $this->horloge->aller(10, '10:00');
        $halles->dans(function () use ($camille, $devisAAccepter): Facture {
            return app(EmettreFactureAction::class)->handle($camille, app(CreerFactureDepuisDevisAction::class)->handle($camille, $devisAAccepter->refresh()), null, null, GestesDuMagasin::jeton());
        });

        $this->horloge->aller(11, '10:00');
        $ticketRendu = $halles->vendreALaCaisse($lucas, $halles->comptoir(), [$this->ligne($halles, 'DIFF-BAMBOU', 1)], ModePaiement::CarteBancaire);
        $this->horloge->aller(11, '15:30');
        $ligneRendue = $halles->dans(fn (): int => (int) $ticketRendu->lignes()->sole()->getKey());
        $halles->rendre($camille, $halles->comptoir(), [['ligne_facture_id' => $ligneRendue, 'quantite' => 1]], 'Cadeau en double : la cliente garde un bon d\'avoir.');
        $ligneDesTassesVendues = $halles->dans(fn (): int => (int) $facturePayee->lignes()->orderBy('ordre')->firstOrFail()->getKey());
        $halles->rendre($camille, $horizon, [['ligne_facture_id' => $ligneDesTassesVendues, 'quantite' => 2]], 'Deux tasses ébréchées à la livraison.');

        $hugo = $this->semis->compte('Hugo Lefebvre');
        $studio = $this->client($republique, 'Studio Nord');
        $this->horloge->aller(12, '11:00');
        $factureDuStudio = $republique->facturer($hugo, $studio, [$this->ligne($republique, 'COUSSIN-LIN', 4)], [new PartDeReglement(ModePaiement::Virement, 10000)]);

        $this->horloge->aller(13, '10:00');
        $ligneDesCoussins = $republique->dans(fn (): int => (int) $factureDuStudio->lignes()->sole()->getKey());
        $republique->rendre($hugo, $studio, [['ligne_facture_id' => $ligneDesCoussins, 'quantite' => 1]], 'Un coussin taché : le studio garde un crédit pour sa prochaine commande.');
        $achat = $halles->dans(fn () => FactureAchat::query()->where('numero_fournisseur', 'GL-2026-118')->sole());
        $ligneDesTasses = $halles->dans(fn () => $achat->lignes()->whereHas('produit', fn ($produit) => $produit->where('reference', 'TASSE-GRES'))->sole());
        $halles->retournerAuFournisseur($camille, $achat, [['ligne_achat_id' => (int) $ligneDesTasses->getKey(), 'quantite' => 2]], 'Deux tasses cassées au déballage.', 'AV-GL-2026-031');

        $this->horloge->aller(14, '10:00');
        $cheque = $halles->payer($camille, $partielle, ModePaiement::Cheque, '30.00', $this->horloge->jour(14));
        $this->horloge->aller(14, '10:20');
        $halles->contrePasser($camille, $cheque, 'Chèque saisi par erreur : l\'hôtel a payé ces 30,00 € par virement.');
        $halles->payer($camille, $partielle, ModePaiement::Virement, '30.00', $this->horloge->jour(14));

        $this->horloge->aller(15, '17:45');
        $halles->vendreALaCaisse($lucas, $halles->comptoir(), [$this->ligne($halles, 'TASSE-GRES', 2), $this->ligne($halles, 'Emballage cadeau', 1)], ModePaiement::Especes);
        $this->horloge->aller(15, '18:30');
        $halles->dans(fn () => app(FermerSaCaisseAction::class)->handle($lucas, $lucas, GestesDuMagasin::jeton()));
        $this->horloge->aller(15, '19:30');
        $halles->dans(fn () => app(CloturerLesJourneesAction::class)->parUnePersonne($camille, $halles->magasin, $this->horloge->jour(15), GestesDuMagasin::jeton()));

        $thomas = $this->semis->compte('Thomas Girard');
        $this->horloge->aller(16, '10:00');
        $halles->vendreALaCaisse($thomas, $halles->comptoir(), [$this->ligne($halles, 'CARNET-A5', 2)], ModePaiement::Especes);
        $this->horloge->aller(16, '12:00');
        $halles->dans(fn () => app(FermerSaCaisseAction::class)->handle($thomas, $thomas, GestesDuMagasin::jeton()));
        $this->horloge->aller(16, '15:00');
        $republique->vendreALaCaisse($thomas, $republique->comptoir(), [$this->ligne($republique, 'COUSSIN-LIN', 2)], ModePaiement::CarteBancaire);
        $conseil->vendreALaCaisse($this->semis->compte('Antoine Roux'), $this->client($conseil, 'Paul Garnier'), [$this->ligne($conseil, 'Heure de conseil', 2)], ModePaiement::Virement);
    }

    /**
     * Les jours suivants, jusqu'à l'avant-veille du semis : quelques
     * tickets chaque jour ouvré dans les magasins qui ont des articles,
     * sans jamais vendre au-delà du stock.
     */
    private function joursSuivants(GestesDuMagasin $halles, GestesDuMagasin $republique, GestesDuMagasin $epicerie): void
    {
        $ventes = [
            [$halles, 'Lucas Moreau', ['BOUGIE-FIGUE' => 1, 'TASSE-GRES' => 1]],
            [$halles, 'Inès Garcia', ['CARNET-A5' => 2, 'THE-SENCHA' => 1]],
            [$republique, 'Lucas Moreau (République)', ['COUSSIN-LIN' => 1]],
            [$republique, 'Sarah Petit', ['BOUGIE-FIGUE' => 1]],
            [$epicerie, 'Chloé Bernard', ['MIEL-LAVANDE' => 1, 'HUILE-OLIVE' => 1]],
        ];

        for ($jour = 17; $jour <= SemisDeDemonstration::JOURS - 2; $jour++) {
            foreach ($ventes as $rang => [$gestes, $vendeur, $quantites]) {
                if (($jour + $rang) % 3 === 0) {
                    continue;
                }

                $this->horloge->aller($jour, sprintf('%02d:%02d', 10 + $rang, 15));
                $lignes = array_map(fn (string $reference, int $quantite): array => $this->ligne($gestes, $reference, $quantite), array_keys($quantites), $quantites);
                $gestes->vendreALaCaisse($this->semis->compte($vendeur), $gestes->comptoir(), $lignes, $jour % 2 === 0 ? ModePaiement::CarteBancaire : ModePaiement::Especes);
            }
        }
    }

    /**
     * La date de validité des devis semés : un mois après la veille du
     * semis, pour qu'un devis envoyé reste ouvert, et non expiré, quand
     * l'éditeur l'ouvre.
     */
    private function validite(): string
    {
        return Calendrier::ajouterJours($this->horloge->jour(SemisDeDemonstration::JOURS - 1), 30);
    }

    /**
     * Une ligne de vente : le produit de cette référence, ou le service de
     * ce nom, en cette quantité.
     *
     * @return array{produit_id?: int, service_id?: int, quantite: int}
     */
    private function ligne(GestesDuMagasin $gestes, string $referenceOuService, int $quantite): array
    {
        $produit = $gestes->dans(fn () => Produit::query()->where('reference', $referenceOuService)->first());

        return $produit !== null
            ? ['produit_id' => (int) $produit->getKey(), 'quantite' => $quantite]
            : ['service_id' => (int) $gestes->service($referenceOuService)->getKey(), 'quantite' => $quantite];
    }

    private function client(GestesDuMagasin $gestes, string $nom): Client
    {
        return $gestes->dans(fn (): Client => Client::query()->where('nom', $nom)->sole());
    }

    /**
     * Le jeton du lien de réponse que porte l'e-mail de cet envoi, tel que
     * le client le lit : le logiciel n'en garde que l'empreinte.
     *
     * @throws LogicException quand l'e-mail ne porte aucun lien
     */
    private static function jetonDuDevis(EnvoiDocument $envoi): string
    {
        $mail = Mail::sent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->envoi->is($envoi))->last();

        if (! $mail instanceof DocumentMail || preg_match('#/devis/([A-Za-z0-9]{64})"#', $mail->render(), $trouve) !== 1) {
            throw new LogicException('L\'e-mail du devis ne porte aucun lien de réponse.');
        }

        return $trouve[1];
    }

    /**
     * La facture du fournisseur, en PDF, telle qu'il l'a envoyée : une page
     * qui porte son nom et son numéro.
     */
    private static function pieceDuFournisseur(string $fournisseur, string $numero): string
    {
        return Pdf::loadHTML('<html><body style="font-family: DejaVu Sans"><h1>'.e($fournisseur).'</h1><p>Facture n° '.e($numero).'</p><p>Document fictif du jeu de démonstration de PILIQI.</p></body></html>')->setPaper('a4')->output();
    }
}
