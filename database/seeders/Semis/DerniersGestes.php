<?php

namespace Database\Seeders\Semis;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Enums\FormatDuTelechargement;
use App\Domain\Facturation\Enums\OrigineDesAchats;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
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
use App\Domain\Tenancy\Support\ValeursLues;
use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Integrations\Pdp\FakePdpGateway;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Les derniers gestes de l'histoire semée, la veille du semis : une
 * demande d'inscription dont le lien n'a pas été suivi, les préférences
 * des graphiques de chaque écran (espace de la société,
 * panneau d'un magasin, espace du cabinet, page de l'expert-comptable
 * déclaré), les notifications que certains comptes ont lues (les autres
 * restent non lues dans leur cloche), et les téléchargements des
 * documents par période, en ZIP et en CSV, que la propriétaire de Maison
 * Ferrand a demandés.
 */
final class DerniersGestes
{
    public function __construct(
        private readonly SemisDeDemonstration $semis,
        private readonly MotsDePasseDuSemis $motsDePasse,
    ) {}

    public function semer(): void
    {
        $this->demanderUneInscription();
        $this->choisirLesGraphiques();
        $this->lireDesNotifications();
        $this->telechargerDesDocuments();
        $this->laisserDesTachesEnFile();
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

    private function choisirLesGraphiques(): void
    {
        $preferences = app(PreferencesDesGraphiques::class);
        $preferences->enregistrer($this->semis->proprietaire('commerce'), EcranDuTableauDeBord::Societe, new ReglagesDuTableauDeBord([
            CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois, TypeDeGraphique::Lignes),
            CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMagasin),
            CarteDuTableauDeBord::parDefaut(Graphique::EncaissementsParMode, TypeDeGraphique::Anneau),
            CarteDuTableauDeBord::parDefaut(Graphique::MargeBruteParMois),
        ], PeriodeDuTableauDeBord::AnneeEnCours, null, true));
        $preferences->enregistrer($this->semis->compte('Camille Laurent'), EcranDuTableauDeBord::Magasin, new ReglagesDuTableauDeBord([
            CarteDuTableauDeBord::parDefaut(Graphique::VentesEtAchatsParMois),
            CarteDuTableauDeBord::parDefaut(Graphique::MeilleursProduits, TypeDeGraphique::BarresCouchees),
            CarteDuTableauDeBord::parDefaut(Graphique::ValeurDuStock, TypeDeGraphique::Aires),
            CarteDuTableauDeBord::parDefaut(Graphique::VentesParJourDeLaSemaine, TypeDeGraphique::Radar),
        ]));
        $preferences->enregistrer($this->semis->proprietaire('cabinet'), EcranDuTableauDeBord::Cabinet, new ReglagesDuTableauDeBord([
            CarteDuTableauDeBord::parDefaut(Graphique::TvaAPayerParMois),
            CarteDuTableauDeBord::parDefaut(Graphique::FacturesParAnciennete),
        ], PeriodeDuTableauDeBord::AnneePrecedente));
        $preferences->enregistrer($this->semis->compte('Marc Petit'), EcranDuTableauDeBord::ExpertComptable, new ReglagesDuTableauDeBord([
            CarteDuTableauDeBord::parDefaut(Graphique::ChiffreDAffairesParMois, TypeDeGraphique::Aires),
            CarteDuTableauDeBord::parDefaut(Graphique::VentesParTauxDeTva, TypeDeGraphique::Camembert),
        ], PeriodeDuTableauDeBord::DouzeDerniersMois, null, true));
    }

    /**
     * La propriétaire de Maison Ferrand, la responsable des Halles et le
     * propriétaire du cabinet ont lu leurs plus anciennes notifications :
     * les plus récentes restent non lues.
     */
    private function lireDesNotifications(): void
    {
        foreach ([$this->semis->proprietaire('commerce'), $this->semis->compte('Camille Laurent'), $this->semis->proprietaire('cabinet')] as $compte) {
            $nonLues = $compte->unreadNotifications()->reorder('created_at')->get();
            $nonLues->take(intdiv($nonLues->count(), 2))->each(static fn (DatabaseNotification $notification) => $notification->markAsRead());
        }
    }

    /**
     * La propriétaire de Maison Ferrand télécharge les clôtures et le
     * relevé des encaissements du dernier mois écoulé des Halles, en ZIP
     * puis en CSV, depuis l'espace de sa société : le travailleur de la
     * file prépare chacun (voir FileDAttenteDuSemis). Elle demande ensuite
     * les ventes de la dernière semaine en ZIP, que le travailleur n'a pas
     * encore prises : la demande reste « en préparation ».
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

        $this->demanderUnTelechargement($proprietaire, $societe, $halles, Calendrier::ajouterJours($aujourdhui, -7), Calendrier::ajouterJours($aujourdhui, -1), [TypeDeDocumentTelechargeable::Ventes], FormatDuTelechargement::Zip);
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
