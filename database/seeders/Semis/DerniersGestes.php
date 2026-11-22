<?php

namespace Database\Seeders\Semis;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Enums\FormatDeLEtat;
use App\Domain\Charges\Services\ChargesDeLaSociete;
use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\Charges\Services\EtatDesCharges;
use App\Domain\Charges\Services\TvaDeLaPeriode;
use App\Domain\Charges\Support\FiltresDeLEtat;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Enums\FormatDuTelechargement;
use App\Domain\Facturation\Enums\OrigineDesAchats;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\ChoixDesCharges;
use App\Domain\Facturation\Services\DocumentsDeLaPeriode;
use App\Domain\Facturation\Services\DocumentTrouve;
use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\TypeDeGraphique;
use App\Domain\TableauDeBord\Services\PreferencesDesGraphiques;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Domain\Tenancy\Actions\DemanderLeTelechargementDesDocumentsAction;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\DemandeInscription;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Integrations\Pdp\FakePdpGateway;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use LogicException;

/**
 * Les derniers gestes de l'histoire semée, la veille du semis : une
 * demande d'inscription dont le lien n'a pas été suivi et les préférences
 * des graphiques de chaque écran (espace de la société, panneau d'un
 * magasin, espace du cabinet, page de l'expert-comptable déclaré), sur le
 * poste seulement (dont la carte « Écart des prix d'achat » : à l'affichage
 * des douze mois pour la propriétaire de Maison Ferrand, par mois pour la
 * responsable des Halles et le propriétaire du cabinet), les notifications
 * que certains comptes ont lues (les autres
 * restent non lues dans leur cloche), et les téléchargements des
 * documents par période, en ZIP et en CSV, que la propriétaire de Maison
 * Ferrand a demandés, et l'état de ses charges, que cette propriétaire a
 * téléchargé en PDF et le collaborateur de son cabinet en Excel, et la
 * facture que la responsable du cabinet de conseil envoie par e-mail.
 */
final class DerniersGestes
{
    public function __construct(
        private readonly SemisComplet $semis,
        private readonly MotsDePasseDuSemis $motsDePasse,
    ) {}

    public function semer(): void
    {
        if ($this->semis->surLePoste()) {
            $this->demanderUneInscription();
            $this->choisirLesGraphiques();
        }

        $this->lireDesNotifications();

        if ($this->semis->seme('commerce')) {
            $this->telechargerDesDocuments();
            $this->telechargerLEtatDesCharges();
            $this->laisserDesTachesEnFile();
        }

        if ($this->semis->seme('services')) {
            $this->envoyerUneFactureDuConseil();
        }
    }

    /**
     * La responsable du cabinet de conseil envoie par e-mail à la
     * Boulangerie Martin sa dernière facture : dans le modèle « Services »
     * du site de démonstration, elle paraît dans « E-mails qui seraient
     * partis » de chaque copie neuve (lot DEMO-COPIE, F.4).
     */
    private function envoyerUneFactureDuConseil(): void
    {
        $conseil = new GestesDuMagasin($this->semis->magasin('conseil'));
        $claire = $this->semis->compte('Claire Dubois');
        $conseil->dans(function () use ($claire): EnvoiDocument {
            $boulangerie = Client::query()->where('nom', 'Boulangerie Martin')->sole();
            $facture = Facture::query()->where('client_id', $boulangerie->getKey())->whereNotNull('numero')->latest('id')->firstOrFail();

            return app(EnvoyerDocumentAction::class)->handle($claire, $facture, 'boulangerie.martin@exemple.test', 'Bonjour, voici votre facture.', GestesDuMagasin::jeton());
        });
    }

    /**
     * Une demande d'inscription faite sur la page d'inscription, dont le
     * lien de confirmation n'a pas encore été suivi.
     */
    private function demanderUneInscription(): void
    {
        $siren = IdentifiantsInsee::avecSaCle('00000045');
        ValeursLues::instance(app(RepertoireSirene::class), FauxRepertoireSirene::class)->nommer($siren, 'CORDONNERIE BLANC');
        DemandeInscription::enregistrer('proprietaire@cordonnerie-blanc.exemple.test', $siren, TypeDActivite::Services, app(RepertoireSirene::class)->unite($siren), $this->motsDePasse->nouveau());
    }

    /**
     * Les préférences de quelques comptes du poste. Jamais dans une base
     * modèle : sur le site de démonstration, chaque compte du bandeau ouvre
     * son tableau de bord avec toutes les cartes que son écran permet
     * (précision de l'éditeur du 16/11/2026 vers 23 h 59), et une
     * préférence enregistrée primerait sur ce défaut.
     */
    private function choisirLesGraphiques(): void
    {
        $preferences = app(PreferencesDesGraphiques::class);

        if ($this->semis->seme('commerce')) {
            $this->choisirLesGraphiquesDeMaisonFerrand($preferences);
        }

        if ($this->semis->seme('cabinet')) {
            $preferences->enregistrer($this->semis->proprietaire('cabinet'), EcranDuTableauDeBord::Cabinet, new ReglagesDuTableauDeBord([
                CarteDuTableauDeBord::parDefaut(Graphique::TvaAPayerParMois),
                CarteDuTableauDeBord::parDefaut(Graphique::FacturesParAnciennete),
                CarteDuTableauDeBord::parDefaut(Graphique::EcartDesPrixDAchat),
            ], PeriodeDuTableauDeBord::AnneePrecedente));
        }

        if ($this->semis->seme('services')) {
            // Tous les graphiques qu'il voit sont affichés : son menu
            // « Ajouter un graphique » dit « Tous les graphiques sont
            // affichés ».
            $preferences->enregistrer($this->semis->compte('Marc Petit'), EcranDuTableauDeBord::ExpertComptable, new ReglagesDuTableauDeBord([
                CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois, TypeDeGraphique::Aires),
                CarteDuTableauDeBord::parDefaut(Graphique::VentesParTauxDeTva, TypeDeGraphique::Camembert),
                ...array_map(fn (Graphique $graphique): CarteDuTableauDeBord => CarteDuTableauDeBord::parDefaut($graphique), array_values(array_filter(Graphique::cases(), fn (Graphique $graphique): bool => ! in_array($graphique, [Graphique::ChiffreDAffairesParMois, Graphique::VentesParTauxDeTva], true)))),
            ], PeriodeDuTableauDeBord::DouzeDerniersMois, null, true));
        }
    }

    private function choisirLesGraphiquesDeMaisonFerrand(PreferencesDesGraphiques $preferences): void
    {
        $preferences->enregistrer($this->semis->proprietaire('commerce'), EcranDuTableauDeBord::Societe, new ReglagesDuTableauDeBord([
            CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois, TypeDeGraphique::Lignes),
            CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMagasin),
            CarteDuTableauDeBord::parDefaut(Graphique::EncaissementsParMode, TypeDeGraphique::Anneau),
            CarteDuTableauDeBord::parDefaut(Graphique::VentesParVendeur),
            CarteDuTableauDeBord::parDefaut(Graphique::MargeBruteParMois),
            new CarteDuTableauDeBord(Graphique::EcartDesPrixDAchat, TypeDeGraphique::Barres, affichage: CarteDuTableauDeBord::AFFICHAGE_SUR_DOUZE_MOIS),
        ], PeriodeDuTableauDeBord::AnneeEnCours, null, true));
        $preferences->enregistrer($this->semis->compte('Camille Laurent'), EcranDuTableauDeBord::Magasin, new ReglagesDuTableauDeBord([
            CarteDuTableauDeBord::parDefaut(Graphique::VentesEtAchatsParMois),
            CarteDuTableauDeBord::parDefaut(Graphique::MeilleursProduits, TypeDeGraphique::BarresCouchees),
            CarteDuTableauDeBord::parDefaut(Graphique::VentesParVendeur),
            CarteDuTableauDeBord::parDefaut(Graphique::ValeurDuStock, TypeDeGraphique::Aires),
            CarteDuTableauDeBord::parDefaut(Graphique::VentesParJourDeLaSemaine, TypeDeGraphique::Radar),
            CarteDuTableauDeBord::parDefaut(Graphique::EcartDesPrixDAchat),
        ]));
    }

    /**
     * La propriétaire de Maison Ferrand, la responsable des Halles et le
     * propriétaire du cabinet ont lu leurs plus anciennes notifications :
     * les plus récentes restent non lues.
     */
    private function lireDesNotifications(): void
    {
        $comptes = [
            ...$this->semis->seme('commerce') ? [$this->semis->proprietaire('commerce'), $this->semis->compte('Camille Laurent')] : [],
            ...$this->semis->seme('cabinet') ? [$this->semis->proprietaire('cabinet')] : [],
        ];

        foreach ($comptes as $compte) {
            $nonLues = $compte->unreadNotifications()->reorder('created_at')->get();
            $nonLues->take(intdiv($nonLues->count(), 2))->each(static fn (DatabaseNotification $notification) => $notification->markAsRead());
        }
    }

    /**
     * La propriétaire de Maison Ferrand télécharge les clôtures et le
     * relevé des encaissements du dernier mois écoulé des Halles, en ZIP
     * puis en CSV, depuis l'espace de sa société : le travailleur de la
     * file prépare chacun (voir FileDAttenteDuSemis), puis les pièces des
     * charges du dernier mois déposé (voir dernierMoisDeChargesDeposees() et
     * telechargerLesPiecesDesCharges()). Elle
     * demande ensuite les ventes de la dernière semaine en ZIP, que le
     * travailleur n'a pas encore prises : la demande reste « en
     * préparation ».
     */
    private function telechargerDesDocuments(): void
    {
        $proprietaire = $this->semis->proprietaire('commerce');
        $societe = $this->semis->societe('commerce');
        $halles = $this->semis->magasin('halles');
        $aujourdhui = $halles->calendrier()->aujourdhui();
        $au = Calendrier::ajouterJours(Calendrier::premierJourDuMois($aujourdhui), -1);
        $du = Calendrier::premierJourDuMois($au);

        foreach ([FormatDuTelechargement::Zip, FormatDuTelechargement::Csv] as $format) {
            $this->demanderUnTelechargement($proprietaire, $societe, $halles, $du, $au, [TypeDeDocumentTelechargeable::Clotures, TypeDeDocumentTelechargeable::Encaissements], $format);
            FileDAttenteDuSemis::passer(FileDAttenteDuSemis::TELECHARGEMENTS);
        }

        $this->telechargerLesPiecesDesCharges($proprietaire, $societe, [$halles, $this->semis->magasin('republique')], ...$this->dernierMoisDeChargesDeposees($aujourdhui));
        FileDAttenteDuSemis::passer(FileDAttenteDuSemis::TELECHARGEMENTS);

        $this->demanderUnTelechargement($proprietaire, $societe, $halles, Calendrier::ajouterJours($aujourdhui, -7), Calendrier::ajouterJours($aujourdhui, -1), [TypeDeDocumentTelechargeable::Ventes], FormatDuTelechargement::Zip);
    }

    /**
     * Le premier et le dernier jour du dernier mois dont les charges sont
     * déposées à l'instant du semis : le propriétaire dépose celles du mois
     * précédent le 5 de chaque mois (voir ChargesDesSocietes), et la boucle
     * des jours s'arrête avant-hier. Un semis des 1er au 5 du mois n'a donc
     * encore aucune charge du mois écoulé : il prend le mois d'avant.
     *
     * @return array{0: string, 1: string}
     */
    private function dernierMoisDeChargesDeposees(string $aujourdhui): array
    {
        $dernierJourDeLaBoucle = Calendrier::ajouterJours($aujourdhui, -2);
        $au = Calendrier::ajouterJours(Calendrier::premierJourDuMois(Calendrier::ajouterJours($dernierJourDeLaBoucle, -4)), -1);

        return [Calendrier::premierJourDuMois($au), $au];
    }

    /**
     * Les pièces de toutes les charges du mois écoulé de Maison Ferrand,
     * celles de « Toute la société » comprises, quel que soit leur état, en
     * ZIP, demandées par sa propriétaire depuis l'espace de la société
     * (décision de l'éditeur du 15/11/2026, lot DATEOP) : le travailleur de
     * la file les prépare ensuite.
     *
     * @param  list<Magasin>  $magasins
     */
    private function telechargerLesPiecesDesCharges(User $compte, Societe $societe, array $magasins, string $du, string $au): void
    {
        app(TenantContext::class)->runAsSociete($societe, function () use ($compte, $societe, $magasins, $du, $au): void {
            $types = [TypeDeDocumentTelechargeable::Charges];
            $cles = array_map(static fn (DocumentTrouve $document): string => $document->cle(), app(DocumentsDeLaPeriode::class)->trouver($compte, $magasins, $du, $au, $types, OrigineDesAchats::Toutes, charges: new ChoixDesCharges($societe, true)));
            app(DemanderLeTelechargementDesDocumentsAction::class)->handle($compte, $societe, $magasins, $du, $au, $types, OrigineDesAchats::Toutes, FormatDuTelechargement::Zip, $cles, chargesDeLaSociete: true, toutesLesCharges: true);
        });
    }

    /**
     * L'état des charges de l'année de Maison Ferrand (voir
     * EtatDesCharges), téléchargé en PDF par sa propriétaire depuis
     * l'espace de la société, et en Excel par Paul Lefèvre, le
     * collaborateur du cabinet à qui elle est confiée, la vue « À valider »
     * : chacun s'inscrit au journal d'audit de la société.
     */
    private function telechargerLEtatDesCharges(): void
    {
        $societe = $this->semis->societe('commerce');
        $annee = substr(Calendrier::deParis()->aujourdhui(), 0, 4);
        $mois = array_map(static fn (int $mois): string => sprintf('%s-%02d', $annee, $mois), range(1, 12));

        foreach ([[$this->semis->proprietaire('commerce'), 'toutes', FormatDeLEtat::Pdf], [$this->semis->compte('Paul Lefèvre'), 'a_valider', FormatDeLEtat::Excel]] as [$compte, $vue, $format]) {
            $acces = app(DroitsSurLesCharges::class)->pour($compte, $societe) ?? throw new LogicException('Ce compte du semis voit les charges de Maison Ferrand.');
            $filtres = new FiltresDeLEtat($mois, 'Année '.$annee, $vue, null, false, 'Toute la société et tous les magasins');
            $tva = app(TvaDeLaPeriode::class)->pourLaPageDesCharges($acces, null, $mois, null, false, CleDuModele::desModeles(app(ChargesDeLaSociete::class)->magasinsVus($acces)));
            app(EtatDesCharges::class)->telecharger($acces, $filtres, $tva, 'Édité par '.$compte->name, $format);
            app(TenantContext::class)->clear();
        }
    }

    /**
     * Une demande de téléchargement de tous les documents de ces types que
     * la personne voit dans ce magasin pendant cette période, telle que la
     * page les lui propose (voir DocumentsDeLaPeriode).
     *
     * @param  list<TypeDeDocumentTelechargeable>  $types
     */
    private function demanderUnTelechargement(User $compte, Societe $societe, Magasin $magasin, string $du, string $au, array $types, FormatDuTelechargement $format): void
    {
        app(TenantContext::class)->runAsSociete($societe, function () use ($compte, $societe, $magasin, $du, $au, $types, $format): void {
            $cles = array_map(static fn (DocumentTrouve $document): string => $document->cle(), app(DocumentsDeLaPeriode::class)->trouver($compte, [$magasin], $du, $au, $types, OrigineDesAchats::Toutes));
            app(DemanderLeTelechargementDesDocumentsAction::class)->handle($compte, $societe, [$magasin], $du, $au, $types, OrigineDesAchats::Toutes, $format, $cles);
        });
    }

    /**
     * Deux tâches que le travailleur de la file n'a pas fini de traiter
     * quand le semis s'arrête (voir FileDAttenteDuSemis) : la transmission
     * d'une facture des Halles, déposée pendant une panne de la plateforme
     * agréée, reste « soumise » jusqu'à sa tentative suivante ; l'e-mail de
     * cette facture, refusé par le serveur d'envoi arrêté, reste « en
     * attente » de la sienne. Le travailleur du poste les reprendra.
     */
    private function laisserDesTachesEnFile(): void
    {
        $halles = new GestesDuMagasin($this->semis->magasin('halles'));
        $camille = $this->semis->compte('Camille Laurent');
        $horizon = $halles->dans(fn (): Client => Client::query()->where('nom', 'Agence Horizon')->sole());
        $plateforme = ValeursLues::instance(app(PdpGatewayInterface::class), FakePdpGateway::class);
        $plateforme->rendreInjoignable();

        try {
            $facture = FileDAttenteDuSemis::plusTard(fn (): Facture => $halles->facturer($camille, $horizon, [$halles->ligne('CARNET-A5', 4)]));
            FileDAttenteDuSemis::passerCeQuiEstDu();
        } finally {
            $plateforme->rendreInjoignable(false);
        }

        ServeurDEnvoiEnPanne::pendant(function () use ($halles, $camille, $facture): void {
            FileDAttenteDuSemis::plusTard(fn (): EnvoiDocument => $halles->dans(fn (): EnvoiDocument => app(EnvoyerDocumentAction::class)->handle($camille, $facture, 'contact@agence-horizon.exemple.test', 'Bonjour, voici votre facture.', GestesDuMagasin::jeton())));
            FileDAttenteDuSemis::passerCeQuiEstDu();
        });
    }
}
