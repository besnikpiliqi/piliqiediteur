<?php

namespace Database\Seeders\Semis;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Actions\ArchiverUneFicheAction;
use App\Domain\Contacts\Actions\VerifierNumeroTvaAction;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\AjouterPreuveOperationAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Actions\NoterBordereauDetaxeAction;
use App\Domain\Facturation\Actions\NoterLeVisaDuBordereauAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Facturation\Actions\RegulariserUneExonerationNonProuveeAction;
use App\Domain\Facturation\Actions\RepondreAuDevisEnLigneAction;
use App\Domain\Facturation\Actions\RetirerLeLienDuDevisAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\BordereauDetaxe;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LienDuDevis;
use App\Domain\Facturation\Models\PreuveOperation;
use App\Domain\Facturation\Models\VisaDetaxe;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Tenancy\Actions\ModifierLesParametresDuMagasinAction;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\FicheLue;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Integrations\Pdp\FakePdpGateway;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use LogicException;

/**
 * Les documents de chaque cas (demande de l'éditeur du 15/11/2026, cahier
 * SEED-TOUT § 3.3), chacun par sa vraie action, au nom de qui fait le
 * geste, le soir, après les ventes du jour (voir ActiviteDesMagasins), à
 * des jours comptés depuis la fin de l'histoire : ventes hors de France
 * (exports hors de l'Union avec leur preuve de douane, MRN ou CN23, sans
 * preuve depuis plus ou moins de trente jours, et sans preuve au bout de
 * cent cinquante jours, régularisé ; livraison vers un DOM ; livraisons
 * intracommunautaires, expédiée et enlevée, avec la consultation VIES du
 * client et leurs preuves ; prestations de services à des professionnels
 * de l'Union, autoliquidée, et hors de l'Union), ventes en détaxe avec
 * leur bordereau, visé ou non, vente sans stock d'une responsable (stock
 * négatif), paiement par prélèvement, caisse d'un vendeur fermée par sa
 * responsable, devis refusés (en ligne et par le magasin), expiré, accepté
 * et bientôt expiré, et les derniers jours, une facture de chaque état
 * (brouillon, émise, envoyée, payée en partie), un avoir en brouillon, une
 * facture d'achat et un avoir fournisseur en brouillon, un fournisseur de
 * Monaco et une fiche archivée ; la date de l'opération (T49) : une
 * prestation de plusieurs jours (période imprimée), une facture datée de
 * la veille de son émission, une livraison expédiée deux jours après la
 * facture, une livraison enlevée le jour de son émission (date notée sans
 * mention), et un devis qui prévoit sa livraison ; les devis de
 * dépannage à domicile de trois magasins (T51.6, T86, voir DEPANNAGES et
 * casDuDepannage()) ; et les incidents : une facture rejetée par
 * la plateforme agréée et une autre « non transmise : incident technique »
 * (voir transmettreAvecDesIncidents()), et un envoi en échec pendant une
 * panne du serveur d'envoi (voir ServeurDEnvoiEnPanne).
 */
final class DocumentsDesCas
{
    /**
     * L'adresse de livraison aux États-Unis des exports hors de l'Union.
     *
     * @var array<string, mixed>
     */
    private const NEW_YORK = ['livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US'];

    /**
     * L'adresse de livraison à Berlin des livraisons intracommunautaires.
     *
     * @var array<string, mixed>
     */
    private const BERLIN = ['livraison_adresse' => 'Musterstraße 1', 'livraison_code_postal' => '10115', 'livraison_ville' => 'Berlin', 'livraison_pays' => 'DE'];

    /**
     * Le jour, compté depuis la fin de l'histoire, de l'export sans preuve
     * qui se régularise cent cinquante jours plus tard.
     */
    private const EXPORT_NON_PROUVE = 175;

    /**
     * Les magasins qui font le dépannage, la réparation ou l'entretien à
     * domicile (T51.6, T86, voir casDuDepannage()) : l'Atelier Brun, en
     * franchise en base, sur le poste seulement, et, pour que chaque base
     * modèle du site de démonstration en montre un, le magasin de la
     * République de Maison Ferrand (modèles « Commerce » et « Cabinet ») et
     * le cabinet de conseil (modèle « Services »), dont les jours tombent
     * dans les derniers de l'histoire, ceux dont les e-mails paraissent dans
     * « E-mails qui seraient partis » de chaque copie neuve (voir
     * DatabaseSeeder::JOURS_DES_E_MAILS_DES_MODELES) : l'envoi du devis,
     * sans PDF, et la confirmation de son acceptation, avec le devis signé
     * et le droit de rétractation. Pour chacun : sa société, son
     * responsable, l'heure de ses gestes, ses quatre jours comptés depuis la
     * fin de l'histoire (voir casDuDepannage()), le service de
     * l'intervention et celui du déplacement (sans prix quand le magasin
     * l'a déjà), le prix d'un devis payant, les trois clients particuliers,
     * le message de l'envoi, le lieu du devis payant et le devis à un
     * professionnel (client, adresse de l'envoi, article ou service,
     * quantité).
     *
     * @var array<string, array{societe: string, responsable: string, heure: string, jours: array{0: int, 1: int, 2: int, 3: int}, intervention: array{0: string, 1: string}, deplacement: array{0: string, 1: string|null}, prix_payant: string, signe: array{nom: string, adresse: string, code_postal: string, ville: string, email: string}, avec_debut: array{nom: string, adresse: string, code_postal: string, ville: string, email: string}, payant: array{nom: string, adresse: string, code_postal: string, ville: string, email: string}, message: string, lieu_du_payant: string, professionnel: array{0: string, 1: string, 2: string, 3: int}}>
     */
    private const DEPANNAGES = [
        'atelier' => [
            'societe' => 'atelier',
            'responsable' => 'Mathilde Brun (atelier)',
            'heure' => '23:20',
            'jours' => [16, 15, 13, 11],
            'intervention' => ['Réparation de machine à coudre', '65.00'],
            'deplacement' => ['Déplacement à domicile', '30.00'],
            'prix_payant' => '35.00',
            'signe' => ['nom' => 'Jeanne Roussel', 'adresse' => '18 rue des Martyrs', 'code_postal' => '75009', 'ville' => 'Paris', 'email' => 'jeanne.roussel@exemple.test'],
            'avec_debut' => ['nom' => 'Bernard Petit', 'adresse' => '5 villa des Tilleuls', 'code_postal' => '75020', 'ville' => 'Paris', 'email' => 'bernard.petit@exemple.test'],
            'payant' => ['nom' => 'Hélène Garnier', 'adresse' => '42 rue de Charonne', 'code_postal' => '75011', 'ville' => 'Paris', 'email' => 'helene.garnier@exemple.test'],
            'message' => 'Bonjour, voici notre devis pour la réparation de votre machine à coudre.',
            'lieu_du_payant' => "Chez Mme Odette Garnier\n9 rue Oberkampf\n75011 Paris",
            'professionnel' => ['Agence Pixel', 'contact@agence-pixel.exemple.test', 'Retouche de vêtement', 6],
        ],
        'republique' => [
            'societe' => 'commerce',
            'responsable' => 'Hugo Lefebvre',
            'heure' => '23:20',
            'jours' => [7, 6, 5, 4],
            'intervention' => ['Entretien de tapis à domicile', '60.00'],
            'deplacement' => ['Déplacement à domicile', '25.00'],
            'prix_payant' => '20.00',
            'signe' => ['nom' => 'Sylvie Caron', 'adresse' => '14 rue de Malte', 'code_postal' => '75011', 'ville' => 'Paris', 'email' => 'sylvie.caron@exemple.test'],
            'avec_debut' => ['nom' => 'Laurent Chevalier', 'adresse' => '22 rue de Lancry', 'code_postal' => '75010', 'ville' => 'Paris', 'email' => 'laurent.chevalier@exemple.test'],
            'payant' => ['nom' => 'Françoise Blanc', 'adresse' => '6 rue Jean-Pierre Timbaud', 'code_postal' => '75011', 'ville' => 'Paris', 'email' => 'francoise.blanc@exemple.test'],
            'message' => 'Bonjour, voici notre devis pour l\'entretien de vos tapis.',
            'lieu_du_payant' => "Maison de Mme Françoise Blanc\n4 chemin des Prés\n77580 Crécy-la-Chapelle",
            'professionnel' => ['Studio Nord', 'bonjour@studio-nord.exemple.test', 'COUSSIN-LIN', 4],
        ],
        'conseil' => [
            'societe' => 'services',
            'responsable' => 'Claire Dubois',
            'heure' => '23:25',
            'jours' => [7, 6, 5, 4],
            'intervention' => ['Dépannage informatique à domicile', '80.00'],
            'deplacement' => ['Forfait déplacement', null],
            'prix_payant' => '25.00',
            'signe' => ['nom' => 'Monique Faure', 'adresse' => '12 rue Caulaincourt', 'code_postal' => '75018', 'ville' => 'Paris', 'email' => 'monique.faure@exemple.test'],
            'avec_debut' => ['nom' => 'Gérard Lambert', 'adresse' => '3 rue des Pyrénées', 'code_postal' => '75020', 'ville' => 'Paris', 'email' => 'gerard.lambert@exemple.test'],
            'payant' => ['nom' => 'Nathalie Roy', 'adresse' => '27 avenue Parmentier', 'code_postal' => '75011', 'ville' => 'Paris', 'email' => 'nathalie.roy@exemple.test'],
            'message' => 'Bonjour, voici notre devis pour la remise en route de votre ordinateur.',
            'lieu_du_payant' => "Chez M. André Roy\n8 rue Saint-Maur\n75011 Paris",
            'professionnel' => ['Cabinet dentaire Morel', 'accueil@dentaire-morel.exemple.test', 'Heure de conseil', 2],
        ],
    ];

    private ?Facture $exportARegulariser = null;

    private ?Facture $exportAvecMrn = null;

    private ?Facture $exportAvecCn23 = null;

    private ?Facture $livraisonExpediee = null;

    private ?Facture $livraisonEnlevee = null;

    private ?Facture $ticketEnDetaxe = null;

    private ?EnvoiDocument $devisARefuserEnLigne = null;

    private ?EnvoiDocument $devisAAccepterEnLigne = null;

    /**
     * Les devis de dépannage envoyés par e-mail, par magasin, que leur
     * client accepte en ligne (voir casDuDepannage()).
     *
     * @var array<string, array<'avec_debut'|'payant', EnvoiDocument>>
     */
    private array $depannagesEnvoyes = [];

    /**
     * L'envoi, resté en file, du devis de dépannage de chaque magasin dont
     * la validité s'achève le jour de son envoi : la tâche ne passe que le
     * lendemain soir, le devis est alors expiré (voir casDuDepannage()).
     *
     * @var array<string, EnvoyerDocumentJob>
     */
    private array $envoisDeDepannageExpires = [];

    private bool $fichesCreees = false;

    public function __construct(
        private readonly HorlogeDuSemis $horloge,
        private readonly SemisComplet $semis,
    ) {}

    /**
     * Le travail de ce jour de l'histoire : le soir, à partir de 23 h, le
     * cas que ce jour porte.
     */
    public function auJour(int $rang): void
    {
        if (! $this->fichesCreees) {
            $this->horloge->aller($rang, '23:00');
            $this->creerLesFiches();
            $this->fichesCreees = true;
        }

        $avantLaFin = SemisComplet::JOURS - $rang;

        if ($this->semis->seme('commerce')) {
            $this->casDesHalles($rang, $avantLaFin);
        }

        if ($this->semis->seme('services')) {
            $this->casDuConseil($rang, $avantLaFin);
        }

        foreach (self::DEPANNAGES as $magasin => $cas) {
            if ($this->semis->seme($cas['societe'])) {
                $this->casDuDepannage($magasin, $rang, $avantLaFin);
            }
        }

        if ($this->semis->seme('commerce') && $rang % 10 === 0) {
            $this->fermerUneCaisseLeSoir($rang, $this->gestes('halles'), $this->semis->compte('Camille Laurent'));
        }
    }

    /**
     * Le cas des Halles que ce jour porte.
     */
    private function casDesHalles(int $rang, int $avantLaFin): void
    {
        $halles = $this->gestes('halles');
        $camille = $this->semis->compte('Camille Laurent');

        match ($avantLaFin) {
            self::EXPORT_NON_PROUVE => $this->aDouzeHeures($rang, function () use ($halles, $camille): void {
                $this->exportARegulariser = $halles->facturer($camille, $this->client($halles, 'Acme Inc.'), [$this->ligne($halles, 'BOUGIE-FIGUE', 5)], [], ['nature_operation' => NatureOperation::ExportHorsUe, ...self::NEW_YORK]);
            }),
            self::EXPORT_NON_PROUVE - 150 => $this->aDouzeHeures($rang, function () use ($halles, $camille): void {
                $export = $this->exportARegulariser ?? throw new LogicException('L\'export à régulariser n\'a pas été semé.');
                $halles->dans(fn (): Facture => app(RegulariserUneExonerationNonProuveeAction::class)->handle($camille, $export->refresh(), GestesDuMagasin::jeton()));
            }),
            100 => $this->aDouzeHeures($rang, function () use ($halles, $camille): void {
                $this->exportAvecMrn = $halles->facturer($camille, $this->client($halles, 'Acme Inc.'), [$this->ligne($halles, 'TASSE-GRES', 6)], [new PartDeReglement(ModePaiement::Virement, 6250)], ['nature_operation' => NatureOperation::ExportHorsUe, ...self::NEW_YORK]);
            }),
            95 => $this->aDouzeHeures($rang, fn (): PreuveOperation => $this->prouver($halles, $camille, $this->exportAvecMrn, TypePreuve::Mrn, '26FR000000123456A1', null, 'Douane du Havre')),
            90 => $this->aDouzeHeures($rang, function () use ($halles): void {
                $this->exportAvecCn23 = $halles->facturer($this->semis->compte('Lucas Moreau'), $this->client($halles, 'Acme Inc.'), [$this->ligne($halles, 'THE-SENCHA', 4)], [], ['nature_operation' => NatureOperation::ExportHorsUe, ...self::NEW_YORK]);
            }),
            88 => $this->aDouzeHeures($rang, fn (): PreuveOperation => $this->prouver($halles, $camille, $this->exportAvecCn23, TypePreuve::Cn23, 'CN23-LA-552190', null, 'La Poste')),
            70 => $this->aDouzeHeures($rang, function () use ($halles, $camille): void {
                $this->livraisonExpediee = $halles->facturer($camille, $this->verifieSurVies($halles, $camille, 'Muster GmbH'), [$this->ligne($halles, 'CARNET-A5', 20)], [], ['nature_operation' => NatureOperation::LivraisonIntracommunautaire, ...self::BERLIN, 'date_operation_fin' => $this->horloge->jour(SemisComplet::JOURS - 68), 'transport_par' => TransportPar::Magasin->value]);
            }),
            66 => $this->aDouzeHeures($rang, function () use ($halles, $camille): void {
                $this->prouver($halles, $camille, $this->livraisonExpediee, TypePreuve::TransportA, 'LV-2026-8812', $this->horloge->jour(SemisComplet::JOURS - 68), 'Transports Rapides SA');
                $this->prouver($halles, $camille, $this->livraisonExpediee, TypePreuve::TransportB, 'CMR-44190', $this->horloge->jour(SemisComplet::JOURS - 67), 'Transports Rapides SA');
                $this->livraisonEnlevee = $halles->facturer($camille, $this->verifieSurVies($halles, $camille, 'Muster GmbH'), [$this->ligne($halles, 'BOUGIE-FIGUE', 4)], [new PartDeReglement(ModePaiement::Virement, 6000)], ['nature_operation' => NatureOperation::LivraisonIntracommunautaire, ...self::BERLIN, 'date_operation_fin' => $this->horloge->jour(SemisComplet::JOURS - 66), 'transport_par' => TransportPar::Acquereur->value]);
            }),
            64 => $this->aDouzeHeures($rang, fn (): PreuveOperation => $this->prouver($halles, $camille, $this->livraisonEnlevee, TypePreuve::AttestationAcquereur, 'ATT-MUSTER-2026-07', $this->horloge->jour(SemisComplet::JOURS - 65), 'Muster GmbH')),
            60 => $this->aDouzeHeures($rang, fn (): Facture => $halles->facturer($camille, $this->client($halles, 'Lucie Bernard'), [$this->ligne($halles, 'DIFF-BAMBOU', 1)], [], ['nature_operation' => NatureOperation::LivraisonDom, 'livraison_adresse' => '1 rue Victor Hugo', 'livraison_code_postal' => '97200', 'livraison_ville' => 'Fort-de-France', 'livraison_pays' => 'FR'])),
            55 => $this->aDouzeHeures($rang, function () use ($halles, $camille): void {
                $devis = $halles->dans(fn (): Devis => app(CreerDevisAction::class)->handle($camille, $this->client($halles, 'Agence Horizon'), [$this->ligne($halles, 'DIFF-BAMBOU', 6)], ['date_validite' => $this->horloge->jour(SemisComplet::JOURS - 45), 'date_operation_fin' => $this->horloge->jour(SemisComplet::JOURS - 42)]));
                $halles->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($camille, $devis, 'contact@agence-horizon.exemple.test', 'Bonjour, voici notre devis pour vos bureaux.', GestesDuMagasin::jeton()));
            }),
            45 => $this->aDouzeHeures($rang, function () use ($halles): void {
                $halles->facturer($this->semis->compte('Lucas Moreau'), $this->client($halles, 'Acme Inc.'), [$this->ligne($halles, 'CARNET-A5', 10)], [], ['nature_operation' => NatureOperation::ExportHorsUe, ...self::NEW_YORK]);
            }),
            40 => $this->aDouzeHeures($rang, function () use ($halles, $camille): void {
                $devis = $halles->dans(fn (): Devis => app(CreerDevisAction::class)->handle($camille, $this->client($halles, 'Hôtel des Arts'), [$this->ligne($halles, 'TASSE-GRES', 24)], ['date_validite' => $this->horloge->jour(SemisComplet::JOURS - 10)]));
                $this->devisARefuserEnLigne = $halles->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($camille, $devis, 'comptabilite@hotel-des-arts.exemple.test', null, GestesDuMagasin::jeton()));
                $refuse = $halles->dans(fn (): Devis => app(EmettreDevisAction::class)->handle($camille, app(CreerDevisAction::class)->handle($camille, $this->client($halles, 'Marie Dupuis'), [$this->ligne($halles, 'STYLO-PLUME', 1)], ['date_validite' => $this->horloge->jour(SemisComplet::JOURS - 20)]), GestesDuMagasin::jeton()));
                $halles->dans(fn (): Devis => app(RefuserDevisAction::class)->handle($camille, $refuse));
            }),
            38 => $this->leSoir($rang, '23:10', fn (): mixed => app(RepondreAuDevisEnLigneAction::class)->refuser(self::jetonDuDevis($this->devisARefuserEnLigne), 'Nous avons choisi un autre fournisseur cette année.')),
            30 => $this->aDouzeHeures($rang, function () use ($halles): void {
                $lucas = $this->semis->compte('Lucas Moreau');
                $this->ticketEnDetaxe = $halles->vendreALaCaisse($lucas, $halles->comptoir(), [$this->ligne($halles, 'DIFF-BAMBOU', 3), $this->ligne($halles, 'BOUGIE-FIGUE', 4)], ModePaiement::CarteBancaire);
                $halles->dans(fn (): BordereauDetaxe => app(NoterBordereauDetaxeAction::class)->handle($lucas, $this->ticketEnDetaxe, 'PABLO-2026-118807'));
            }),
            26 => $this->aDouzeHeures($rang, function () use ($halles): void {
                $lucas = $this->semis->compte('Lucas Moreau');
                $ticket = $this->ticketEnDetaxe ?? throw new LogicException('Le ticket en détaxe n\'a pas été semé.');
                $halles->dans(fn (): VisaDetaxe => app(NoterLeVisaDuBordereauAction::class)->handle($lucas, $ticket->refresh(), $this->horloge->jour(SemisComplet::JOURS - 27)));
            }),
            22 => $this->aDouzeHeures($rang, function () use ($halles, $camille): void {
                $halles->vendreALaCaisse($camille, $this->client($halles, 'Marie Dupuis'), [$this->ligne($halles, 'STYLO-PLUME', 8)], ModePaiement::CarteBancaire, sansStock: true);
                $halles->dans(fn () => app(ArchiverUneFicheAction::class)->archiver($camille, $this->fournisseur($halles, 'Papeterie du Centre')));
            }),
            14 => $this->aDouzeHeures($rang, function () use ($halles): void {
                $lucas = $this->semis->compte('Lucas Moreau');
                $ticket = $halles->vendreALaCaisse($lucas, $halles->comptoir(), [$this->ligne($halles, 'DIFF-BAMBOU', 4)], ModePaiement::CarteBancaire);
                $halles->dans(fn (): BordereauDetaxe => app(NoterBordereauDetaxeAction::class)->handle($lucas, $ticket, 'PABLO-2026-121530'));
            }),
            12 => $this->aDouzeHeures($rang, function () use ($halles): void {
                $halles->facturer($this->semis->compte('Lucas Moreau'), $this->client($halles, 'Acme Inc.'), [$this->ligne($halles, 'TASSE-GRES', 4)], [], ['nature_operation' => NatureOperation::ExportHorsUe, ...self::NEW_YORK]);
            }),
            9 => $this->aDouzeHeures($rang, fn () => $this->transmettreAvecDesIncidents($rang, $halles, $camille)),
            8 => $this->aDouzeHeures($rang, function () use ($halles, $camille): void {
                $devis = $halles->dans(fn (): Devis => app(CreerDevisAction::class)->handle($camille, $this->client($halles, 'Agence Horizon'), [$this->ligne($halles, 'BOUGIE-FIGUE', 12), $this->ligne($halles, 'Emballage cadeau', 12)], ['date_validite' => $this->horloge->jour(SemisComplet::JOURS - 1)]));
                $this->devisAAccepterEnLigne = $halles->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($camille, $devis, 'contact@agence-horizon.exemple.test', null, GestesDuMagasin::jeton()));
            }),
            6 => $this->leSoir($rang, '23:10', fn (): mixed => app(RepondreAuDevisEnLigneAction::class)->accepter(self::jetonDuDevis($this->devisAAccepterEnLigne), 'Sophie Arnaud', true)),
            5 => $this->aDouzeHeures($rang, function () use ($halles, $camille): void {
                $devis = $halles->dans(fn (): Devis => app(CreerDevisAction::class)->handle($camille, $this->client($halles, 'Hôtel des Arts'), [$this->ligne($halles, 'THE-SENCHA', 30)], ['date_validite' => $this->horloge->jour(SemisComplet::JOURS - 1)]));
                $halles->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($camille, $devis, 'comptabilite@hotel-des-arts.exemple.test', null, GestesDuMagasin::jeton()));
            }),
            4 => $this->aDouzeHeures($rang, function () use ($halles, $camille): void {
                $partielle = $halles->facturer($camille, $this->client($halles, 'Agence Horizon'), [$this->ligne($halles, 'DIFF-BAMBOU', 2), $this->ligne($halles, 'CARNET-A5', 10)]);
                $halles->encaisser($camille, $partielle, [new PartDeReglement(ModePaiement::Cheque, 3000)], $this->horloge->jour(SemisComplet::JOURS - 4));
                $envoyee = $halles->facturer($camille, $this->client($halles, 'Hôtel des Arts'), [$this->ligne($halles, 'BOUGIE-FIGUE', 6)]);
                $halles->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($camille, $envoyee, 'comptabilite@hotel-des-arts.exemple.test', 'Bonjour, voici votre facture.', GestesDuMagasin::jeton()));
                ServeurDEnvoiEnPanne::pendant(fn (): EnvoiDocument => $halles->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($camille, $partielle, 'contact@agence-horizon.exemple.test', 'Bonjour, voici votre facture, déjà réglée en partie.', GestesDuMagasin::jeton())));
            }),
            3 => $this->aDouzeHeures($rang, function () use ($halles, $camille): void {
                $halles->facturer($camille, $this->client($halles, 'Agence Horizon'), [$this->ligne($halles, 'TASSE-GRES', 3)]);
                $halles->dans(fn (): Facture => app(CreerFactureAction::class)->handle($camille, $this->client($halles, 'Hôtel des Arts'), [$this->ligne($halles, 'DIFF-BAMBOU', 1)]));
                $vendue = $halles->dans(fn (): Facture => Facture::query()->where('client_id', $this->client($halles, 'Agence Horizon')->id)->whereNotNull('numero')->latest('id')->firstOrFail());
                $halles->dans(fn (): Avoir => app(CreerAvoirAction::class)->handle($camille, $this->client($halles, 'Agence Horizon'), [['ligne_facture_id' => $vendue->lignes()->orderBy('ordre')->firstOrFail()->id, 'quantite' => 1]], 'Une tasse rapportée, à vérifier avant l\'avoir.'));
            }),
            2 => $this->aDouzeHeures($rang, function () use ($halles, $camille): void {
                $grossiste = $this->fournisseur($halles, 'Grossiste Lumière');
                $halles->dans(fn (): FactureAchat => app(CreerFactureAchatAction::class)->handle($camille, $grossiste, [['produit_id' => $halles->produit('TASSE-GRES')->id, 'quantite' => 12, 'prix_unitaire_ht' => '4.30']], ['date_facture' => $this->horloge->jour(SemisComplet::JOURS - 3), 'numero_fournisseur' => 'GL-2026-990']));
                $achat = $halles->dans(fn (): FactureAchat => FactureAchat::query()->where('numero_fournisseur', 'GL-2026-118')->sole());
                $ligne = $halles->dans(fn (): int => $achat->lignes()->whereHas('produit', fn ($produit) => $produit->where('reference', 'DIFF-BAMBOU'))->sole()->id);
                $halles->dans(fn (): AvoirFournisseur => app(CreerAvoirFournisseurAction::class)->handle($camille, $achat, [['ligne_achat_id' => $ligne, 'quantite' => 1]], 'Diffuseur fêlé, en attente de la note d\'avoir.', null));
                $monaco = $halles->fiche($camille, Fournisseur::class, ['nom' => 'Parfums de Monte-Carlo', 'adresse' => '7 avenue des Spélugues', 'code_postal' => '98000', 'ville' => 'Monaco', 'pays' => 'MC']);
                $halles->acheter($camille, $monaco, [['produit_id' => $halles->produit('BOUGIE-FIGUE')->id, 'quantite' => 10, 'prix_unitaire_ht' => '6.40']], ['date_facture' => $this->horloge->jour(SemisComplet::JOURS - 3), 'numero_fournisseur' => 'PMC-2026-77']);
            }),
            default => null,
        };
    }

    /**
     * Le cas du magasin de services que ce jour porte : prestations à un
     * professionnel de l'Union en autoliquidation et hors de l'Union,
     * facture à une mairie sur plusieurs jours, prélèvement.
     */
    private function casDuConseil(int $rang, int $avantLaFin): void
    {
        $conseil = $this->gestes('conseil');
        $claire = $this->semis->compte('Claire Dubois');

        match ($avantLaFin) {
            80 => $this->aDouzeHeures($rang, fn (): Facture => $conseil->facturer($claire, $this->verifieSurVies($conseil, $claire, 'Bauer Consulting GmbH'), [$this->ligne($conseil, 'Audit de site web', 1)], [], ['nature_operation' => NatureOperation::PrestationUeAutoliquidation])),
            75 => $this->aDouzeHeures($rang, fn (): Facture => $conseil->facturer($claire, $this->client($conseil, 'Northwind Trading Ltd'), [$this->ligne($conseil, 'Heure de conseil', 4)], [], ['nature_operation' => NatureOperation::PrestationHorsUe])),
            50 => $this->aDouzeHeures($rang, fn (): Facture => $conseil->facturer($claire, $this->client($conseil, 'Mairie de Montreuil'), [$this->ligne($conseil, 'Audit de site web', 1), $this->ligne($conseil, 'Forfait déplacement', 1)], [], ['date_operation_debut' => $this->horloge->jour(SemisComplet::JOURS - 56), 'date_operation_fin' => $this->horloge->jour(SemisComplet::JOURS - 52)])),
            18 => $this->aDouzeHeures($rang, function () use ($conseil, $claire): void {
                $facture = $conseil->facturer($claire, $this->client($conseil, 'Boulangerie Martin'), [$this->ligne($conseil, 'Heure de conseil', 2)], [], ['date_operation_fin' => $this->horloge->jour(SemisComplet::JOURS - 19)]);
                $conseil->payer($claire, $facture, ModePaiement::Prelevement, '180.00', $this->horloge->jour(SemisComplet::JOURS - 18), 'PRLV-2026-0418');
            }),
            default => null,
        };
    }

    /**
     * Les fiches des cas : clients établis hors de France (dont un client
     * de l'Union dont le n° de TVA est vérifié sur VIES), une cliente
     * particulière de la Martinique, une mairie, et des modes de paiement
     * préférés variés.
     */
    private function creerLesFiches(): void
    {
        if ($this->semis->seme('commerce')) {
            $this->creerLesFichesDesHalles();
        }

        if ($this->semis->seme('services')) {
            $this->creerLesFichesDuConseil();
        }
    }

    private function creerLesFichesDesHalles(): void
    {
        $halles = $this->gestes('halles');
        $camille = $this->semis->compte('Camille Laurent');

        $halles->fiche($camille, Client::class, ['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789', 'adresse' => '1 Main Street', 'code_postal' => '10001', 'ville' => 'New York', 'email' => 'purchasing@acme.exemple.test', 'mode_paiement_prefere' => ModePaiement::Virement->value]);
        $muster = $halles->fiche($camille, Client::class, ['nom' => 'Muster GmbH', 'type_client' => TypeClient::Professionnel, 'pays' => 'DE', 'etabli_en_france' => false, 'numero_tva' => 'DE123456789', 'adresse' => 'Musterstraße 1', 'code_postal' => '10115', 'ville' => 'Berlin', 'email' => 'einkauf@muster.exemple.test', 'mode_paiement_prefere' => ModePaiement::Virement->value]);
        $halles->dans(fn (): ConsultationVies => app(VerifierNumeroTvaAction::class)->handle($camille, $muster));
        $halles->fiche($camille, Client::class, ['nom' => 'Lucie Bernard', 'type_client' => TypeClient::Particulier, 'pays' => 'FR', 'adresse' => '1 rue Victor Hugo', 'code_postal' => '97200', 'ville' => 'Fort-de-France', 'email' => 'lucie.bernard@exemple.test', 'mode_paiement_prefere' => ModePaiement::CarteBancaire->value]);
        $halles->fiche($camille, Client::class, ['nom' => 'Brasserie du Pont', 'type_client' => TypeClient::Professionnel, 'siren' => IdentifiantsInsee::avecSaCle('00000027'), 'adresse' => '3 quai de la Tournelle', 'code_postal' => '75005', 'ville' => 'Paris', 'pays' => 'FR', 'mode_paiement_prefere' => ModePaiement::Cheque->value]);
    }

    private function creerLesFichesDuConseil(): void
    {
        $conseil = $this->gestes('conseil');
        $claire = $this->semis->compte('Claire Dubois');

        $bauer = $conseil->fiche($claire, Client::class, ['nom' => 'Bauer Consulting GmbH', 'type_client' => TypeClient::Professionnel, 'pays' => 'DE', 'etabli_en_france' => false, 'numero_tva' => 'DE987654321', 'adresse' => 'Leopoldstraße 20', 'code_postal' => '80802', 'ville' => 'München', 'email' => 'office@bauer.exemple.test']);
        $conseil->dans(fn (): ConsultationVies => app(VerifierNumeroTvaAction::class)->handle($claire, $bauer));
        $conseil->fiche($claire, Client::class, ['nom' => 'Northwind Trading Ltd', 'type_client' => TypeClient::Professionnel, 'pays' => 'GB', 'etabli_en_france' => false, 'identifiant_etranger' => 'GB-CRN 01234567', 'adresse' => '10 Downing Lane', 'code_postal' => 'EC1A 1BB', 'ville' => 'London', 'email' => 'accounts@northwind.exemple.test']);
        $conseil->fiche($claire, Client::class, ['nom' => 'Mairie de Montreuil', 'type_client' => TypeClient::Professionnel, 'siren' => IdentifiantsInsee::avecSaCle('00000029'), 'adresse' => 'Place Jean-Jaurès', 'code_postal' => '93100', 'ville' => 'Montreuil', 'pays' => 'FR', 'email' => 'finances@mairie-montreuil.exemple.test', 'delai_paiement_jours' => 30, 'mode_paiement_prefere' => ModePaiement::Virement->value]);
        $conseil->fiche($claire, Client::class, ['nom' => 'Salon Coiffure Élise', 'type_client' => TypeClient::Professionnel, 'siren' => IdentifiantsInsee::avecSaCle('00000028'), 'adresse' => '9 rue des Abbesses', 'code_postal' => '75018', 'ville' => 'Paris', 'pays' => 'FR', 'mode_paiement_prefere' => ModePaiement::Prelevement->value]);
        $conseil->fiche($claire, Client::class, ['nom' => 'Thomas Leroux', 'type_client' => TypeClient::Particulier, 'pays' => 'FR', 'mode_paiement_prefere' => ModePaiement::Especes->value]);
    }

    /**
     * Le dépannage à domicile d'un magasin (T51.6, T86, voir DEPANNAGES),
     * aux jours de son cas : le premier, son responsable coche le réglage
     * du magasin (devis gratuit), crée le service de l'intervention et, au
     * besoin, celui du déplacement, sur une ligne à part, et trois clients
     * particuliers ; il fait signer chez le premier un devis, qui imprime le
     * droit de rétractation et son formulaire, et qu'il enregistre comme
     * accepté ; il envoie par e-mail au deuxième un devis qui n'est pas
     * signé chez lui, et à un professionnel un devis qui ne porte aucune de
     * ces mentions, dont la page du lien ne parle pas de rétractation. Le lendemain soir, le deuxième l'accepte en
     * ligne en demandant que la prestation commence avant la fin de son
     * délai de rétractation. Le troisième jour, le responsable rend ses
     * devis de dépannage payants : les devis déjà envoyés gardent « Devis
     * gratuit », figé à leur envoi ; il envoie au troisième client un devis
     * payant pour un autre lieu que son adresse, et garde un brouillon pour
     * le premier, qui suit le réglage d'aujourd'hui. Le quatrième, ce client
     * accepte en ligne sans demander un début anticipé.
     */
    private function casDuDepannage(string $magasin, int $rang, int $avantLaFin): void
    {
        $cas = self::DEPANNAGES[$magasin];
        [$premier, $lendemain, $payant, $acceptationDuPayant] = $cas['jours'];

        match ($avantLaFin) {
            $premier => $this->leSoir($rang, $cas['heure'], fn () => $this->semerLeDepannageADomicile($magasin, $rang)),
            $lendemain => $this->leSoir($rang, $cas['heure'], fn () => $this->semerLeLendemainDuDepannage($magasin)),
            $payant => $this->leSoir($rang, $cas['heure'], fn () => $this->semerLeDepannagePayant($magasin)),
            $acceptationDuPayant => $this->leSoir($rang, $cas['heure'], fn (): mixed => app(RepondreAuDevisEnLigneAction::class)->accepter(self::jetonDuDevis($this->depannagesEnvoyes[$magasin]['payant'] ?? null), $cas['payant']['nom'], true)),
            default => null,
        };
    }

    /**
     * Le premier jour du dépannage : les services et les clients, puis un
     * brouillon pour un particulier enregistré avant que le responsable
     * coche le réglage, sans lieu de l'intervention (son PDF imprime
     * « Lieu de l'intervention : — », « Envoyer par e-mail » le refuse, et
     * sa modification préremplit le lieu avec l'adresse du client) ; le
     * réglage coché, le devis signé chez le client, dont l'e-mail attend
     * en file pendant que le responsable enregistre l'acceptation signée à
     * la main sur place, si bien qu'il ne part pas (« Le devis a été
     * accepté entre-temps », voir EnvoyerDocumentJob) ; le devis envoyé que
     * le client acceptera en ligne en demandant que la prestation commence
     * avant la fin du délai, dont un premier envoi, resté en file, est
     * remplacé par un second avant son départ (il ne part pas : « Un envoi
     * plus récent de ce devis a remplacé le lien de cet envoi »), et un
     * devis à un professionnel, sans rien de tout cela, dont un premier
     * envoi, resté en file, perd son lien retiré par le responsable avant
     * son départ (il ne part pas non plus : « Le lien de réponse de cet
     * envoi a été retiré entre-temps »), un second envoi lui donnant un
     * lien neuf ; enfin deux devis envoyés dont l'e-mail ne part pas
     * davantage : celui que le responsable note refusé avant son départ
     * (« Le devis a été refusé entre-temps »), et celui dont la validité
     * s'achève le jour même, dont l'e-mail attend en file jusqu'au
     * lendemain soir, quand le devis est expiré (« La validité du devis est
     * dépassée », voir semerLeLendemainDuDepannage()).
     */
    private function semerLeDepannageADomicile(string $magasin, int $rang): void
    {
        $cas = self::DEPANNAGES[$magasin];
        $gestes = $this->gestes($magasin);
        $responsable = $this->semis->compte($cas['responsable']);

        foreach ([$cas['intervention'], $cas['deplacement']] as [$nom, $prix]) {
            if ($prix !== null) {
                $gestes->fiche($responsable, Service::class, ['nom' => $nom, 'prix_ttc' => $prix, 'taux_tva' => '20', 'actif' => true]);
            }
        }

        $clients = [];

        foreach (['signe', 'avec_debut', 'payant'] as $role) {
            $client = $cas[$role];
            $clients[$role] = $gestes->fiche($responsable, Client::class, ['nom' => $client['nom'], 'type_client' => TypeClient::Particulier, 'adresse' => $client['adresse'], 'code_postal' => $client['code_postal'], 'ville' => $client['ville'], 'pays' => 'FR', 'email' => $client['email']]);
        }

        $lignes = [$this->ligne($gestes, $cas['intervention'][0], 1), $this->ligne($gestes, $cas['deplacement'][0], 1)];
        $validite = $this->validiteDesDevisDeDepannage();
        $gestes->dans(fn (): Devis => app(CreerDevisAction::class)->handle($responsable, $clients['payant'], [$this->ligne($gestes, $cas['intervention'][0], 1)], ['date_validite' => $validite]));
        $gestes->dans(fn (): array => app(ModifierLesParametresDuMagasinAction::class)->handle($responsable, PermissionRegistry::PARAMETRES_GERER, FicheLue::de($gestes->magasin->refresh())->valeurs(), ['depannage_a_domicile' => true]));

        $signe = $gestes->dans(fn (): Devis => app(CreerDevisAction::class)->handle($responsable, $clients['signe'], $lignes, ['date_validite' => $validite, 'lieu_d_intervention' => self::adresseDe($cas['signe']), 'signe_chez_le_client' => true]));
        $envoiDuSigne = $this->retenirLEnvoi(fn (): EnvoiDocument => $gestes->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($responsable, $signe, $cas['signe']['email'], null, GestesDuMagasin::jeton())));
        $gestes->dans(fn (): Devis => app(AccepterDevisAction::class)->handle($responsable, $signe->refresh()));
        $gestes->dans($envoiDuSigne->handle(...));
        $enLigne = $gestes->dans(fn (): Devis => app(CreerDevisAction::class)->handle($responsable, $clients['avec_debut'], $lignes, ['date_validite' => $validite, 'lieu_d_intervention' => self::adresseDe($cas['avec_debut'])]));
        // Un premier envoi attend en file, un second le remplace avant son
        // départ : l'e-mail du premier ne part pas, son lien n'étant plus
        // celui du devis (« Un envoi plus récent de ce devis a remplacé le
        // lien de cet envoi », voir EnvoyerDocumentJob::MOTIF_LIEN_REMPLACE).
        $premierEnvoi = $this->retenirLEnvoi(fn (): EnvoiDocument => $gestes->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($responsable, $enLigne, $cas['avec_debut']['email'], $cas['message'], GestesDuMagasin::jeton())));
        $this->depannagesEnvoyes[$magasin]['avec_debut'] = $gestes->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($responsable, $enLigne->refresh(), $cas['avec_debut']['email'], $cas['message'], GestesDuMagasin::jeton()));
        $gestes->dans($premierEnvoi->handle(...));
        [$professionnel, $adresse, $article, $quantite] = $cas['professionnel'];
        $devisDuProfessionnel = $gestes->dans(fn (): Devis => app(CreerDevisAction::class)->handle($responsable, $this->client($gestes, $professionnel), [$this->ligne($gestes, $article, $quantite)], ['date_validite' => $validite]));
        // Le premier envoi à ce professionnel attend en file pendant que le
        // responsable en retire le lien, et part de nouveau avec un lien
        // neuf : seul le second e-mail part (« Le lien de réponse de cet
        // envoi a été retiré entre-temps », voir
        // EnvoyerDocumentJob::MOTIF_LIEN_RETIRE).
        $envoiARetirer = $this->retenirLEnvoi(fn (): EnvoiDocument => $gestes->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($responsable, $devisDuProfessionnel, $adresse, null, GestesDuMagasin::jeton())));
        $gestes->dans(fn (): LienDuDevis => app(RetirerLeLienDuDevisAction::class)->handle($responsable, $devisDuProfessionnel->refresh()));
        $gestes->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($responsable, $devisDuProfessionnel->refresh(), $adresse, null, GestesDuMagasin::jeton()));
        $gestes->dans($envoiARetirer->handle(...));
        // Le responsable note le refus du client pendant que l'e-mail du
        // devis attend en file : il ne part pas (« Le devis a été refusé
        // entre-temps », voir EnvoyerDocumentJob::MOTIF_DEVIS_REFUSE_ENTRE_TEMPS).
        $aRefuser = $gestes->dans(fn (): Devis => app(CreerDevisAction::class)->handle($responsable, $clients['payant'], [$this->ligne($gestes, $cas['intervention'][0], 1)], ['date_validite' => $validite, 'lieu_d_intervention' => self::adresseDe($cas['payant'])]));
        $envoiARefuser = $this->retenirLEnvoi(fn (): EnvoiDocument => $gestes->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($responsable, $aRefuser, $cas['payant']['email'], null, GestesDuMagasin::jeton())));
        $gestes->dans(fn (): Devis => app(RefuserDevisAction::class)->handle($responsable, $aRefuser->refresh()));
        $gestes->dans($envoiARefuser->handle(...));
        // Le devis, valable jusqu'à ce soir, est envoyé alors que le
        // serveur des tâches est à l'arrêt : sa tâche ne passe que le
        // lendemain soir, quand le devis est expiré (« La validité du devis
        // est dépassée », voir EnvoyerDocumentJob::MOTIF_DEVIS_EXPIRE_ENTRE_TEMPS).
        $expirant = $gestes->dans(fn (): Devis => app(CreerDevisAction::class)->handle($responsable, $clients['avec_debut'], [$this->ligne($gestes, $cas['intervention'][0], 1)], ['date_validite' => $this->horloge->jour($rang), 'lieu_d_intervention' => self::adresseDe($cas['avec_debut'])]));
        $this->envoisDeDepannageExpires[$magasin] = $this->retenirLEnvoi(fn (): EnvoiDocument => $gestes->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($responsable, $expirant, $cas['avec_debut']['email'], null, GestesDuMagasin::jeton())));
    }

    /**
     * Le lendemain du dépannage, le soir : le client qui l'a demandé accepte
     * en ligne en demandant que la prestation commence avant la fin de son
     * délai de rétractation, et la tâche de l'envoi du devis qui n'était
     * valable que la veille passe enfin, devis expiré (l'e-mail ne part pas).
     */
    private function semerLeLendemainDuDepannage(string $magasin): void
    {
        $cas = self::DEPANNAGES[$magasin];
        app(RepondreAuDevisEnLigneAction::class)->accepter(self::jetonDuDevis($this->depannagesEnvoyes[$magasin]['avec_debut'] ?? null), $cas['avec_debut']['nom'], true, true);
        $envoiExpire = $this->envoisDeDepannageExpires[$magasin] ?? throw new LogicException('L\'envoi du devis expiré n\'a pas été semé.');
        $this->gestes($magasin)->dans($envoiExpire->handle(...));
    }

    /**
     * La tâche d'un envoi d'e-mail demandé par ce geste, que la file garde
     * au lieu de la faire tourner : l'e-mail ne part qu'au passage de
     * cette tâche (sa méthode handle(), comme le travailleur la ferait), et
     * rien de ce qui se passe entre-temps (une acceptation au magasin, un
     * second envoi, le retrait du lien) ne l'attend. Aucune table de file
     * n'est lue : la tâche reste en mémoire, dans la base de la construction
     * d'un modèle du site de démonstration comme sur le poste (dont la file
     * est celle du registre, voir config/queue.php).
     *
     * @param  Closure(): EnvoiDocument  $envoi
     */
    private function retenirLEnvoi(Closure $envoi): EnvoyerDocumentJob
    {
        $file = app('queue');
        Queue::fake([EnvoyerDocumentJob::class]);

        try {
            $envoi();
            $retenus = Queue::pushed(EnvoyerDocumentJob::class);
        } finally {
            Queue::swap($file);
        }

        $tache = $retenus->sole();
        assert($tache instanceof EnvoyerDocumentJob);

        return $tache;
    }

    private function semerLeDepannagePayant(string $magasin): void
    {
        $cas = self::DEPANNAGES[$magasin];
        $gestes = $this->gestes($magasin);
        $responsable = $this->semis->compte($cas['responsable']);
        $gestes->dans(fn (): array => app(ModifierLesParametresDuMagasinAction::class)->handle($responsable, PermissionRegistry::PARAMETRES_GERER, FicheLue::de($gestes->magasin->refresh())->valeurs(), ['prix_du_devis_de_depannage' => $cas['prix_payant']]));
        $validite = $this->validiteDesDevisDeDepannage();
        $payant = $gestes->dans(fn (): Devis => app(CreerDevisAction::class)->handle($responsable, $this->client($gestes, $cas['payant']['nom']), [$this->ligne($gestes, $cas['intervention'][0], 2), $this->ligne($gestes, $cas['deplacement'][0], 1)], ['date_validite' => $validite, 'lieu_d_intervention' => $cas['lieu_du_payant']]));
        $this->depannagesEnvoyes[$magasin]['payant'] = $gestes->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($responsable, $payant, $cas['payant']['email'], null, GestesDuMagasin::jeton()));
        $gestes->dans(fn (): Devis => app(CreerDevisAction::class)->handle($responsable, $this->client($gestes, $cas['signe']['nom']), [$this->ligne($gestes, $cas['intervention'][0], 1)], ['date_validite' => $validite, 'lieu_d_intervention' => self::adresseDe($cas['signe'])]));
    }

    /**
     * La validité des devis de dépannage : un mois après la veille du
     * semis, pour que les liens de leurs e-mails mènent encore, quand
     * l'éditeur ou le visiteur d'une copie les ouvre, à leur page ou à la
     * confirmation de leur acceptation et au devis signé.
     */
    private function validiteDesDevisDeDepannage(): string
    {
        return Calendrier::ajouterJours($this->horloge->jour(SemisComplet::JOURS - 1), 30);
    }

    /**
     * L'adresse d'un client particulier, telle que le formulaire du devis
     * la préremplit pour le lieu de l'intervention.
     *
     * @param  array{adresse: string, code_postal: string, ville: string}  $client
     */
    private static function adresseDe(array $client): string
    {
        return "{$client['adresse']}\n{$client['code_postal']} {$client['ville']}";
    }

    /**
     * Tous les dix jours, le soir, le vendeur Lucas Moreau vend un ticket
     * et sa responsable ferme sa caisse pour lui.
     */
    private function fermerUneCaisseLeSoir(int $rang, GestesDuMagasin $halles, User $camille): void
    {
        $this->horloge->aller($rang, '23:40');
        $lucas = $this->semis->compte('Lucas Moreau');
        $halles->vendreALaCaisse($lucas, $halles->comptoir(), [$this->ligne($halles, 'CARNET-A5', 1)], ModePaiement::Especes);
        $halles->dans(fn () => app(FermerSaCaisseAction::class)->handle($camille, $lucas, GestesDuMagasin::jeton()));
    }

    private function prouver(GestesDuMagasin $gestes, User $auteur, ?Facture $facture, TypePreuve $type, string $reference, ?string $date, ?string $emetteur): PreuveOperation
    {
        $vente = $facture ?? throw new LogicException('La vente à prouver n\'a pas été semée.');

        return $gestes->dans(fn (): PreuveOperation => app(AjouterPreuveOperationAction::class)->handle($auteur, $vente->refresh(), $type, $reference, $date, $emetteur));
    }

    /**
     * La plateforme agréée (factice, voir FakePdpGateway) rejette le dépôt
     * d'une facture des Halles, avec son code et son motif, puis ne répond
     * plus pendant le dépôt d'une autre : la file retente aux délais de
     * SubmitFactureToPdpJob, puis la transmission passe « non transmise :
     * incident technique », et la propriétaire en est prévenue. PILIQI la
     * relancera chaque heure, une fois le semis fini.
     */
    private function transmettreAvecDesIncidents(int $rang, GestesDuMagasin $halles, User $camille): void
    {
        $plateforme = ValeursLues::instance(app(PdpGatewayInterface::class), FakePdpGateway::class);
        $plateforme->rejeterLeProchainDepot('BR-FR-10', 'Le SIREN de l\'acheteur ne figure pas à l\'annuaire de la facturation électronique.');
        $halles->facturer($camille, $this->client($halles, 'Agence Horizon'), [$this->ligne($halles, 'CARNET-A5', 6)]);

        $plateforme->rendreInjoignable();

        try {
            FileDAttenteDuSemis::plusTard(fn (): Facture => $halles->facturer($camille, $this->client($halles, 'Hôtel des Arts'), [$this->ligne($halles, 'TASSE-GRES', 4)]));

            foreach (['23:00', '23:02', '23:08', '23:24'] as $tentative) {
                $this->horloge->aller($rang, $tentative);
                FileDAttenteDuSemis::passerCeQuiEstDu();
            }
        } finally {
            $plateforme->rendreInjoignable(false);
        }
    }

    /**
     * @param  callable(): mixed  $geste
     */
    private function aDouzeHeures(int $rang, callable $geste): void
    {
        $this->leSoir($rang, '23:00', $geste);
    }

    /**
     * @param  callable(): mixed  $geste
     */
    private function leSoir(int $rang, string $heure, callable $geste): void
    {
        $this->horloge->aller($rang, $heure);
        app(TenantContext::class)->clear();
        RequeteDuPoste::pendant('/app/caisse', $geste(...));
    }

    private function gestes(string $magasin): GestesDuMagasin
    {
        return new GestesDuMagasin($this->semis->magasin($magasin));
    }

    private function client(GestesDuMagasin $gestes, string $nom): Client
    {
        return $gestes->dans(fn (): Client => Client::query()->where('nom', $nom)->sole());
    }

    /**
     * Ce client de l'Union, dont le n° de TVA vient d'être vérifié sur VIES,
     * le jour même de la vente qui l'exige.
     */
    private function verifieSurVies(GestesDuMagasin $gestes, User $auteur, string $nom): Client
    {
        $client = $this->client($gestes, $nom);
        $gestes->dans(fn (): ConsultationVies => app(VerifierNumeroTvaAction::class)->handle($auteur, $client));

        return $client->refresh();
    }

    private function fournisseur(GestesDuMagasin $gestes, string $nom): Fournisseur
    {
        return $gestes->dans(fn (): Fournisseur => Fournisseur::query()->where('nom', $nom)->sole());
    }

    /**
     * @return array{produit_id?: int, service_id?: int, quantite: int}
     */
    private function ligne(GestesDuMagasin $gestes, string $referenceOuService, int $quantite): array
    {
        return $gestes->ligne($referenceOuService, $quantite);
    }

    /**
     * Le jeton du lien de réponse que porte l'e-mail de cet envoi, tel que
     * le client le lit.
     *
     * @throws LogicException quand l'envoi n'a pas été semé ou que l'e-mail ne porte aucun lien
     */
    private static function jetonDuDevis(?EnvoiDocument $envoi): string
    {
        $envoiSeme = $envoi ?? throw new LogicException('L\'envoi du devis n\'a pas été semé.');
        $mail = Mail::sent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->envoi->is($envoiSeme))->last();

        if (! $mail instanceof DocumentMail || preg_match('#/devis/([A-Za-z0-9]{64})"#', $mail->render(), $trouve) !== 1) {
            throw new LogicException('L\'e-mail du devis ne porte aucun lien de réponse.');
        }

        return $trouve[1];
    }
}
