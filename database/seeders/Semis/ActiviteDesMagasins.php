<?php

namespace Database\Seeders\Semis;

use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
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
use App\Domain\Tenancy\Actions\ModifierLesParametresDuMagasinAction;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\FicheLue;
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
 * ferment d'office à minuit (voir ClotureAutomatiqueCaisse). Les trois
 * magasins de plus de Maison Ferrand n'ont aucune vente : leurs achats
 * sont ceux de la carte « Écart des prix d'achat » (voir
 * AchatsDeLEcartDesPrix).
 */
final class ActiviteDesMagasins
{
    public function __construct(
        private readonly HorlogeDuSemis $horloge,
        private readonly SemisComplet $semis,
        private readonly ComptesDesCas $comptesDesCas,
        private readonly SocietesDesCas $societesDesCas,
    ) {}

    public function semer(): void
    {
        $gestes = [];

        foreach (['halles', 'republique', 'martinique', 'entrepot', 'ephemere', 'marche', 'conseil', 'epicerie'] as $magasin) {
            if ($this->semis->semeLeMagasin($magasin)) {
                $gestes[$magasin] = new GestesDuMagasin($this->semis->magasin($magasin));
            }
        }

        $this->horloge->aller(2, '11:00');

        if ($this->semis->seme('commerce')) {
            $halles = self::gestesDe($gestes, 'halles');
            $halles->dans(fn () => app(CreerMandatPdpAction::class)->handle($halles->magasin, $this->semis->proprietaire('commerce')));
            $halles->dans(fn (): array => app(ModifierLesParametresDuMagasinAction::class)->handle($this->semis->compte('Camille Laurent'), PermissionRegistry::RELANCES_REGLER, FicheLue::de($halles->magasin->refresh())->valeurs(), ['relances_automatiques' => true]));
            $this->remplirLesHalles($halles);
            $this->remplirLaRepublique(self::gestesDe($gestes, 'republique'));
            $this->remplirLaMartinique(self::gestesDe($gestes, 'martinique'));
        }

        if ($this->semis->seme('services')) {
            $this->remplirLeConseil(self::gestesDe($gestes, 'conseil'));
        }

        if ($this->semis->seme('client')) {
            $this->remplirLEpicerie(self::gestesDe($gestes, 'epicerie'));
        }

        $this->premieresSemaines($gestes);
        $this->joursSuivants($gestes);
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
        ], ['date_facture' => $this->horloge->jour(1), 'numero_fournisseur' => 'GL-2026-118'], [new PieceDeposee('Facture GL-2026-118.pdf', PieceDuSemis::pdf('Grossiste Lumière', 'Facture n° GL-2026-118'))]);

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
     * L'inventaire d'ouverture du magasin de Fort-de-France (Martinique),
     * saisi et validé par sa responsable : des articles aux taux de la
     * Martinique (8,5 % et 2,1 %), vendus à l'heure des Antilles.
     */
    private function remplirLaMartinique(GestesDuMagasin $martinique): void
    {
        $martinique->ouvrirLeStock($this->semis->compte('Joëlle Marie-Sainte'), [
            ['produit' => ['nom' => 'Bougie parfumée coco', 'reference' => 'BOUGIE-COCO', 'prix_vente_ttc' => '19.50', 'taux_tva' => '8.5', 'seuil_alerte' => 5], 'quantite' => 120, 'cout_unitaire_ht' => '7.00'],
            ['produit' => ['nom' => 'Thé citronnelle 100 g', 'reference' => 'THE-CITRONNELLE', 'prix_vente_ttc' => '6.90', 'taux_tva' => '2.1', 'seuil_alerte' => 10], 'quantite' => 150, 'cout_unitaire_ht' => '2.80'],
        ]);
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
     * veut voir, dans les magasins que ce semis sème.
     *
     * @param  array<string, GestesDuMagasin>  $gestes
     */
    private function premieresSemaines(array $gestes): void
    {
        $commerce = $this->semis->seme('commerce');
        $services = $this->semis->seme('services');
        $client = $this->semis->seme('client');

        $this->horloge->aller(3, '10:15');

        if ($commerce) {
            $halles = $gestes['halles'];
            $camille = $this->semis->compte('Camille Laurent');
            $lucas = $this->semis->compte('Lucas Moreau');
            $hotel = $this->client($halles, 'Hôtel des Arts');
            $horizon = $this->client($halles, 'Agence Horizon');
            $halles->vendreALaCaisse($lucas, $halles->comptoir(), [$this->ligne($halles, 'BOUGIE-FIGUE', 2)]);
            $halles->vendreALaCaisse($this->semis->compte('Inès Garcia'), $halles->comptoir(), [$this->ligne($halles, 'THE-SENCHA', 3), $this->ligne($halles, 'CARNET-A5', 1)], ModePaiement::Especes, [[ModePaiement::CarteBancaire, 2000]]);
        }

        if ($services) {
            $conseil = $gestes['conseil'];
            $claire = $this->semis->compte('Claire Dubois');
            $boulangerie = $this->client($conseil, 'Boulangerie Martin');
            $dentiste = $this->client($conseil, 'Cabinet dentaire Morel');
        }

        $this->horloge->aller(3, '11:00');

        if ($commerce) {
            $halles->facturer($camille, $hotel, [$this->ligne($halles, 'DIFF-BAMBOU', 4), $this->ligne($halles, 'BOUGIE-FIGUE', 10)]);
        }

        if ($services) {
            $conseil->facturer($claire, $boulangerie, [$this->ligne($conseil, 'Audit de site web', 1)]);
        }

        if ($client) {
            $epicerie = $gestes['epicerie'];
            $epicerie->vendreALaCaisse($this->semis->compte('Chloé Bernard'), $epicerie->comptoir(), [$this->ligne($epicerie, 'HUILE-OLIVE', 2), $this->ligne($epicerie, 'MIEL-LAVANDE', 1)], ModePaiement::CarteBancaire);
        }

        $this->horloge->aller(5, '10:00');

        if ($commerce) {
            $facturePayee = $halles->facturer($camille, $horizon, [$this->ligne($halles, 'TASSE-GRES', 6), $this->ligne($halles, 'STYLO-PLUME', 2)]);
            $halles->encaisser($camille, $facturePayee, [new PartDeReglement(ModePaiement::Virement, $facturePayee->resteDuEnCentimes())], $this->horloge->jour(5));
        }

        if ($services) {
            $conseil->facturer($claire, $dentiste, [$this->ligne($conseil, 'Heure de conseil', 3)], [new PartDeReglement(ModePaiement::Virement, 27000)]);
        }

        if ($commerce) {
            $republique = $gestes['republique'];
            $republique->vendreALaCaisse($this->semis->compte('Sarah Petit'), $republique->comptoir(), [$this->ligne($republique, 'PLAID-LAINE', 1)], ModePaiement::CarteBancaire);
            $this->horloge->aller(6, '11:00');
            $partielle = $halles->facturer($camille, $hotel, [$this->ligne($halles, 'THE-SENCHA', 10), $this->ligne($halles, 'CARNET-A5', 10)]);
        }

        $this->horloge->aller(7, '10:00');

        if ($commerce) {
            $halles->dans(fn (): Devis => app(CreerDevisAction::class)->handle($camille, $horizon, [$this->ligne($halles, 'DIFF-BAMBOU', 10)], ['date_validite' => $this->validite()]));
            $devisEnvoye = $halles->dans(fn (): Devis => app(CreerDevisAction::class)->handle($camille, $hotel, [$this->ligne($halles, 'BOUGIE-FIGUE', 20), $this->ligne($halles, 'Emballage cadeau', 20)], ['date_validite' => $this->validite()]));
            $halles->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($camille, $devisEnvoye, 'comptabilite@hotel-des-arts.exemple.test', 'Bonjour, voici notre proposition pour vos chambres.', GestesDuMagasin::jeton()));
            $devisAAccepter = $halles->dans(fn (): Devis => app(CreerDevisAction::class)->handle($camille, $horizon, [$this->ligne($halles, 'STYLO-PLUME', 3), $this->ligne($halles, 'Livraison à domicile', 1)], ['date_validite' => $this->validite()]));
            $envoi = $halles->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($camille, $devisAAccepter, 'contact@agence-horizon.exemple.test', null, GestesDuMagasin::jeton()));
        }

        if ($services) {
            $conseil->dans(fn (): Devis => app(CreerDevisAction::class)->handle($claire, $boulangerie, [$this->ligne($conseil, 'Heure de conseil', 5)], ['date_validite' => $this->validite()]));
            $conseil->dans(fn (): Devis => app(EmettreDevisAction::class)->handle($claire, app(CreerDevisAction::class)->handle($claire, $dentiste, [$this->ligne($conseil, 'Audit de site web', 1), $this->ligne($conseil, 'Forfait déplacement', 2)], ['date_validite' => $this->validite()]), GestesDuMagasin::jeton()));
        }

        $this->horloge->aller(8, '09:30');

        if ($commerce) {
            $halles->encaisser($camille, $partielle, [new PartDeReglement(ModePaiement::Virement, 5000)], $this->horloge->jour(8));
        }

        if ($services) {
            $conseil->vendreALaCaisse($this->semis->compte('Maxime Henry'), $this->client($conseil, 'Paul Garnier'), [$this->ligne($conseil, 'Forfait déplacement', 1), $this->ligne($conseil, 'Heure de conseil', 1)], ModePaiement::CarteBancaire);
        }

        if ($commerce) {
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
            $ligneRendue = $halles->dans(fn (): int => $ticketRendu->lignes()->sole()->id);
            $halles->rendre($camille, $halles->comptoir(), [['ligne_facture_id' => $ligneRendue, 'quantite' => 1]], 'Cadeau en double : la cliente garde un bon d\'avoir.');
            $ligneDesTassesVendues = $halles->dans(fn (): int => $facturePayee->lignes()->orderBy('ordre')->firstOrFail()->id);
            $halles->rendre($camille, $horizon, [['ligne_facture_id' => $ligneDesTassesVendues, 'quantite' => 2]], 'Deux tasses ébréchées à la livraison.');

            $hugo = $this->semis->compte('Hugo Lefebvre');
            $studio = $this->client($republique, 'Studio Nord');
            $this->horloge->aller(12, '11:00');
            $factureDuStudio = $republique->facturer($hugo, $studio, [$this->ligne($republique, 'COUSSIN-LIN', 4)], [new PartDeReglement(ModePaiement::Virement, 10000)]);

            $this->horloge->aller(13, '10:00');
            $ligneDesCoussins = $republique->dans(fn (): int => $factureDuStudio->lignes()->sole()->id);
            $republique->rendre($hugo, $studio, [['ligne_facture_id' => $ligneDesCoussins, 'quantite' => 1]], 'Un coussin taché : le studio garde un crédit pour sa prochaine commande.');
            $achat = $halles->dans(fn () => FactureAchat::query()->where('numero_fournisseur', 'GL-2026-118')->sole());
            $ligneDesTasses = $halles->dans(fn () => $achat->lignes()->whereHas('produit', fn ($produit) => $produit->where('reference', 'TASSE-GRES'))->sole());
            $halles->retournerAuFournisseur($camille, $achat, [['ligne_achat_id' => $ligneDesTasses->id, 'quantite' => 2]], 'Deux tasses cassées au déballage.', 'AV-GL-2026-031');

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
        }

        $this->horloge->aller(16, '15:00');

        if ($services) {
            $conseil->vendreALaCaisse($this->semis->compte('Antoine Roux'), $this->client($conseil, 'Paul Garnier'), [$this->ligne($conseil, 'Heure de conseil', 2)], ModePaiement::Virement);
        }

        if ($client) {
            $epicerie->vendreALaCaisse($this->semis->compte('Bastien Leroy'), $epicerie->comptoir(), [$this->ligne($epicerie, 'PANIER-GARNI', 1)], ModePaiement::CarteBancaire);
        }
    }

    /**
     * Les jours suivants, jusqu'à l'avant-veille du semis, sur plus de
     * treize mois : chaque vendeur fait un ou deux tickets presque chaque
     * jour, entre 9 h et 20 h, dimanches compris, en se reposant un jour
     * sur trois selon son rang, sans jamais vendre au-delà du stock ; le
     * soir, les sociétés des cas vendent et changent (voir SocietesDesCas),
     * puis les comptes des cas (voir ComptesDesCas), puis les documents
     * des cas se font (voir DocumentsDesCas) ;
     * toutes les deux semaines, le responsable de chaque magasin
     * réassortit son stock par une facture d'achat validée (un peu plus
     * que ce qui se vend en deux semaines), à un prix qui varie (le coût
     * moyen évolue) ; les charges des sociétés suivent leur calendrier du
     * mois (voir ChargesDesSocietes) ; les achats de la carte « Écart des
     * prix d'achat » se posent avant elles, à 7 h ou, pour l'achat reçu sans
     * date à Fort-de-France, à 4 h 30 (voir AchatsDeLEcartDesPrix) ; les
     * clients professionnels
     * commandent, avant l'ouverture de la caisse (voir
     * CommandesDesProfessionnels). Les sociétés des cas et les
     * démonstrations des sociétés (site réel) ne vivent que sur le poste.
     *
     * @param  array<string, GestesDuMagasin>  $gestes  les magasins que ce semis sème, par clé
     */
    private function joursSuivants(array $gestes): void
    {
        $this->horloge->aller(16, '18:00');
        $documents = new DocumentsDesCas($this->horloge, $this->semis);
        $charges = new ChargesDesSocietes($this->horloge, $this->semis);
        $charges->preparer();
        $commandes = new CommandesDesProfessionnels($this->horloge, $this->semis);
        $commandes->preparer();
        $reassorts = [];

        if ($this->semis->seme('commerce')) {
            $atelier = $gestes['republique']->fiche($this->semis->compte('Hugo Lefebvre'), Fournisseur::class, ['nom' => 'Atelier Lin et Laine', 'siret' => IdentifiantsInsee::avecSaCle('0000002170001'), 'adresse' => '12 quai de la Loire', 'code_postal' => '44000', 'ville' => 'Nantes', 'pays' => 'FR']);
            $artisans = $gestes['martinique']->fiche($this->semis->compte('Joëlle Marie-Sainte'), Fournisseur::class, ['nom' => 'Artisans de la Caraïbe', 'adresse' => '15 rue Victor Schœlcher', 'code_postal' => '97200', 'ville' => 'Fort-de-France', 'pays' => 'FR']);
            $grossiste = $gestes['halles']->dans(fn (): Fournisseur => Fournisseur::query()->where('nom', 'Grossiste Lumière')->sole());
            $reassorts[] = [$gestes['halles'], 'Camille Laurent', $grossiste, 'GL', ['BOUGIE-FIGUE' => [20, 600], 'TASSE-GRES' => [24, 420], 'THE-SENCHA' => [24, 310], 'CARNET-A5' => [44, 140]]];
            $reassorts[] = [$gestes['republique'], 'Hugo Lefebvre', $atelier, 'ALL', ['COUSSIN-LIN' => [28, 850], 'BOUGIE-FIGUE' => [24, 600]]];
            $reassorts[] = [$gestes['martinique'], 'Joëlle Marie-Sainte', $artisans, 'ADC', ['BOUGIE-COCO' => [16, 700], 'THE-CITRONNELLE' => [40, 280]]];
        }

        if ($this->semis->seme('client')) {
            $cooperative = $gestes['epicerie']->fiche($this->semis->compte('Nicolas Lemoine'), Fournisseur::class, ['nom' => 'Coopérative du Sud', 'adresse' => '4 chemin des Oliviers', 'code_postal' => '13100', 'ville' => 'Aix-en-Provence', 'pays' => 'FR']);
            $reassorts[] = [$gestes['epicerie'], 'Nicolas Lemoine', $cooperative, 'CDS', ['MIEL-LAVANDE' => [24, 410], 'HUILE-OLIVE' => [48, 620]]];
        }

        // Le rang de chaque vendeur fixe l'heure de ses ventes (de 9 h à
        // 19 h 57 à Paris, avant les ventes des sociétés des cas, à 20 h) et
        // ses jours de repos : il reste le même dans une base modèle, qui ne
        // garde que les vendeurs de ses magasins. Fort-de-France vend de
        // 13 h 24 à 19 h 57 à Paris, le matin et l'après-midi aux Antilles,
        // par deux comptes de rangs voisins : chaque jour, l'un des deux au
        // moins vend, et le magasin a son relevé de caisse, comme les autres
        // magasins de Maison Ferrand.
        $ventes = array_filter([
            ['halles', 'Lucas Moreau', ['BOUGIE-FIGUE' => 1, 'TASSE-GRES' => 1]],
            ['halles', 'Inès Garcia', ['CARNET-A5' => 2, 'THE-SENCHA' => 1]],
            ['republique', 'Lucas Moreau (République)', ['COUSSIN-LIN' => 1]],
            ['republique', 'Sarah Petit', ['BOUGIE-FIGUE' => 1]],
            ['epicerie', 'Chloé Bernard', ['MIEL-LAVANDE' => 1, 'HUILE-OLIVE' => 1]],
            ['epicerie', 'Mehdi Brahimi', ['HUILE-OLIVE' => 1]],
            ['conseil', 'Maxime Henry', ['Heure de conseil' => 1]],
            ['conseil', 'Julie Mercier', ['Heure de conseil' => 2, 'Forfait déplacement' => 1]],
            ['martinique', 'Kévin Louisy', ['BOUGIE-COCO' => 1, 'THE-CITRONNELLE' => 2]],
            ['martinique', 'Joëlle Marie-Sainte', ['THE-CITRONNELLE' => 1]],
        ], fn (array $vente): bool => isset($gestes[$vente[0]]));
        $modes = [ModePaiement::CarteBancaire, ModePaiement::Especes, ModePaiement::CarteBancaire, ModePaiement::Cheque];

        $demonstrations = $this->semis->surLePoste() ? new DemonstrationsDesSocietes($this->horloge, $this->semis) : null;
        $achatsDeLEcart = new AchatsDeLEcartDesPrix($this->horloge, $this->semis, $gestes);
        $achatsDeLEcart->preparer();
        $tva = new TvaDesSocietes($this->horloge, $this->semis);

        for ($jour = 17; $jour <= SemisComplet::JOURS - 2; $jour++) {
            $tva->auJour($jour);
            $achatsDeLEcart->auJour($jour);
            $charges->auJour($jour);

            if ($jour % 14 === 3) {
                $this->horloge->aller($jour, '08:30');

                foreach ($reassorts as [$gestesDuMagasin, $responsable, $fournisseur, $prefixe, $produits]) {
                    $variation = ($jour / 14) % 3;
                    $gestesDuMagasin->acheter($this->semis->compte($responsable), $fournisseur, array_map(
                        fn (string $reference, array $achat): array => ['produit_id' => $gestesDuMagasin->produit($reference)->id, 'quantite' => $achat[0], 'prix_unitaire_ht' => number_format(($achat[1] + 10 * $variation) / 100, 2, '.', '')],
                        array_keys($produits),
                        $produits,
                    ), ['date_facture' => $this->horloge->jour($jour - 1), 'numero_fournisseur' => sprintf('%s-R%03d', $prefixe, $jour)]);
                }
            }

            $commandes->auJour($jour);

            foreach ([0, 1] as $passage) {
                foreach ($ventes as $rang => [$magasin, $vendeur, $quantites]) {
                    if (($jour + $rang) % 3 === 0 || ($passage === 1 && ($jour + $rang) % 4 !== 1)) {
                        continue;
                    }

                    $minute = 9 * 60 + 33 * $rang + 6 * 60 * $passage;
                    $this->horloge->aller($jour, sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60));
                    $lignes = array_map(fn (string $reference, int $quantite): array => $this->ligne($gestes[$magasin], $reference, $quantite), array_keys($quantites), $quantites);
                    $gestes[$magasin]->vendreALaCaisse($this->semis->compte($vendeur), $gestes[$magasin]->comptoir(), $lignes, $modes[($jour + $rang + $passage) % count($modes)]);
                }
            }

            if ($this->semis->surLePoste()) {
                $this->societesDesCas->auJour($jour);
            }

            $this->comptesDesCas->auJour($jour);
            $documents->auJour($jour);
            $demonstrations?->auJour($jour);
        }
    }

    /**
     * La date de validité des devis semés : un mois après la veille du
     * semis, pour qu'un devis envoyé reste ouvert, et non expiré, quand
     * l'éditeur l'ouvre.
     */
    private function validite(): string
    {
        return Calendrier::ajouterJours($this->horloge->jour(SemisComplet::JOURS - 1), 30);
    }

    /**
     * @return array{produit_id?: int, service_id?: int, quantite: int}
     */
    private function ligne(GestesDuMagasin $gestes, string $referenceOuService, int $quantite): array
    {
        return $gestes->ligne($referenceOuService, $quantite);
    }

    /**
     * Les gestes de ce magasin, que ce semis sème (voir
     * SemisComplet::semeLeMagasin()).
     *
     * @param  array<string, GestesDuMagasin>  $gestes
     *
     * @throws LogicException pour un magasin que ce semis ne sème pas
     */
    private static function gestesDe(array $gestes, string $magasin): GestesDuMagasin
    {
        return $gestes[$magasin] ?? throw new LogicException("Le magasin {$magasin} n'est pas semé.");
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
}
