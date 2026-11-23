<?php

namespace Database\Seeders\Semis;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Demonstration\Actions\RecommencerLaDemonstrationAction;
use App\Domain\Demonstration\Actions\RenouvelerLeLienDeLaDemonstrationAction;
use App\Domain\Demonstration\Contracts\SiteDeDemonstration;
use App\Domain\Demonstration\Integrations\SiteDeDemonstrationFactice;
use App\Domain\Demonstration\Jobs\EnvoyerLesMessagesALaDemonstration;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Services\BoiteDEnvoi;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Les démonstrations des sociétés du semis (lot DEMO-COPIE, F.7), par les
 * vraies actions de la partie B, avec un faux site de démonstration (voir
 * SiteDeDemonstrationFactice) qui répond l'état voulu, ne crée aucune
 * copie et ne joint rien. Chaque geste se fait le soir, à partir de 22 h,
 * après tout autre geste du jour (voir ActiviteDesMagasins, et alHeure()), au nom du
 * propriétaire de chaque société ; la tâche de minuit
 * demonstration:marquer-les-fins termine la démonstration de l'Épicerie
 * Lemoine au 30e jour (voir HorlogeDuSemis).
 *
 * Les cas, par jours avant la fin de l'histoire :
 *  - MENUISERIE PICARD (attend son code) : créée au 20e jour du semis,
 *    supprimée quand son inscription se clôt faute de code ;
 *  - ÉPICERIE LEMOINE : créée 33 jours avant la fin, terminée au 30e jour ;
 *  - BOULANGERIE DUVAL : créée la veille de sa suspension, gardée ;
 *  - VERGER DES COTEAUX : créée avant qu'une autre personne saisisse le
 *    code du courrier, supprimée (nouveau propriétaire) ;
 *  - LIBRAIRIE DES QUAIS : créée la veille de la transmission de sa
 *    propriété, gardée après sa résiliation : le nouveau propriétaire lit
 *    « La démonstration a été créée par l'ancien propriétaire… » ;
 *  - MAROQUINERIE DELMAS : créée après la saisie de son code, supprimée à
 *    sa révocation ;
 *  - STUDIO LUMIERE : trois échecs à une heure d'écart au moins, terminée
 *    (échec) : « Votre démonstration n'a pas pu être créée le … » ;
 *  - MAISON FERRAND : prête, lien renouvelé avec son accusé (« Ancien lien
 *    coupé le … »), ouverte par le bouton, un message « etat » ;
 *  - CONSEIL VASSEUR : prête, remise à zéro ;
 *  - MARTIN EXPERTISE COMPTABLE : prête, de type « Cabinet », son lien
 *    renouvelé pendant une mise à jour du site de démonstration, sans
 *    accusé : « La démonstration n'a pas encore reçu le renouvellement… » ;
 *  - BOULANGERIE DUVAL, de plus : son lien renouvelé pendant une panne du
 *    serveur d'envoi, accusé, mais son e-mail parti en échec après ses
 *    trois tentatives : avis « Le lien de votre démonstration n'a pas pu
 *    partir par e-mail… » ;
 *  - CABINET ROUSSEL (non vérifié) : en échec, deuxième essai ;
 *  - FROMAGERIE MORIN (attend son code) : en préparation ;
 *  - FLEURS DE SAISON (attend son code) : prête avant la validation,
 *    demandée le dernier jour, après son inscription
 *    (creerAvantLaValidation()) ;
 *  - GROUPE NORD DISTRIBUTION : en attente d'une place ;
 *  - ATELIER BRUN : demandée, son message « creer » en reprise après deux
 *    tentatives (« La démonstration ne répond pas (délai dépassé). »).
 */
final class DemonstrationsDesSocietes
{
    private readonly SiteDeDemonstrationFactice $site;

    public function __construct(
        private readonly HorlogeDuSemis $horloge,
        private readonly SemisComplet $semis,
    ) {
        $this->site = new SiteDeDemonstrationFactice;
        $paire = MessagesSignes::genererUnePaire();
        app()->instance(SiteDeDemonstration::class, $this->site);
        config([
            'demonstration.url' => SiteDeDemonstrationFactice::ORIGINE,
            'demonstration.messages.cle_privee' => $paire['cle_privee'],
            'demonstration.messages.cle_id' => $paire['cle_id'],
        ]);
    }

    /**
     * Les gestes de ce jour du semis, le soir.
     */
    public function auJour(int $rang): void
    {
        match ($rang) {
            20 => $this->creer($rang, '22:00', 'sans code'),
            default => null,
        };

        match (SemisComplet::JOURS - $rang) {
            33 => $this->creer($rang, '22:00', 'client'),
            21 => $this->creer($rang, '22:00', 'boulangerie'),
            20 => $this->creer($rang, '22:00', 'remplacée'),
            16 => $this->creer($rang, '22:00', 'librairie'),
            11 => $this->creer($rang, '22:00', 'révoquée'),
            6 => $this->echouer($rang, '22:00', 'studio', creer: true),
            5 => $this->troisiemeEchecDuStudio($rang),
            4 => $this->veilleDeLaFin($rang),
            3 => $this->avantDernierJour($rang),
            2 => $this->dernierJour($rang),
            default => null,
        };
    }

    /**
     * La copie de FLEURS DE SAISON, qui attend encore son code, demandée le
     * dernier jour de l'histoire, après son inscription : prête avant la
     * validation de la société.
     */
    public function creerAvantLaValidation(): void
    {
        $this->creer(SemisComplet::JOURS - 1, '09:30', 'inscription');
    }

    private function veilleDeLaFin(int $rang): void
    {
        $this->creer($rang, '22:00', 'commerce');
        $this->echouer($rang, '22:10', 'cabinet non vérifié', creer: true);
        $this->creer($rang, '22:20', 'services');
    }

    private function avantDernierJour(int $rang): void
    {
        $this->alHeure($rang, '22:00');
        $this->renouveler('commerce');
        $this->creer($rang, '22:30', 'cabinet');
    }

    private function dernierJour(int $rang): void
    {
        $this->alHeure($rang, '22:00');
        $this->semis->horsDeToutContexte(fn (): array => app(OuvrirLaDemonstrationAction::class)->handle($this->semis->proprietaire('commerce')));
        $this->demanderLEtat($rang, '22:05', 'commerce');
        $this->echouer($rang, '22:10', 'cabinet non vérifié', creer: false);
        $this->alHeure($rang, '22:30');
        $this->semis->horsDeToutContexte(fn () => app(RecommencerLaDemonstrationAction::class)->handle($this->semis->proprietaire('services'), null));

        $this->site->repondre(202, ['etat' => 'en_preparation']);
        $this->creer($rang, '22:40', 'pièces');
        $this->site->repondre(202, ['etat' => 'en_attente_d_une_place']);
        $this->creer($rang, '22:50', 'groupe');

        $this->site->couper();
        $this->creer($rang, '23:00', 'atelier');
        $this->alHeure($rang, '23:01');
        $atelier = $this->demonstrationDe('atelier');
        EnvoyerLesMessagesALaDemonstration::dispatch(CleDuModele::de($atelier), (int) $atelier->societe_id);
        $this->site->repondre(200, ['etat' => 'prete']);

        $this->renouvelerSansAccuse($rang, '23:10', 'cabinet');
        $this->renouvelerPendantUnePanneDeLEnvoi($rang, ['23:15', '23:16', '23:19'], 'boulangerie');
    }

    /**
     * Le lien renouvelé pendant une mise à jour du site de démonstration
     * (réponse 409) : le message reste sans accusé, et la carte du
     * propriétaire dit, passé un quart d'heure, que l'ancien lien marche
     * peut-être encore (CarteDeLaDemonstration::PHRASE_COUPURE_EN_RETARD).
     */
    private function renouvelerSansAccuse(int $rang, string $heure, string $societe): void
    {
        $this->alHeure($rang, $heure);
        $this->site->repondre(409, []);

        try {
            $this->renouveler($societe);
        } finally {
            $this->site->repondre(200, ['etat' => 'prete']);
        }
    }

    /**
     * Le lien renouvelé pendant une panne du serveur d'envoi : le site de
     * démonstration accuse le renouvellement, mais l'e-mail du lien échoue
     * à ses trois tentatives (aussitôt, 30 secondes puis 2 minutes après),
     * passées par le travailleur de la file à l'heure du semis ; le
     * propriétaire en reçoit l'avis (EnvoyerLeLienDeLaDemonstration::MESSAGE_NON_PARTI).
     *
     * @param  list<string>  $tentatives  l'heure de chaque passage du travailleur
     */
    private function renouvelerPendantUnePanneDeLEnvoi(int $rang, array $tentatives, string $societe): void
    {
        $this->alHeure($rang, $tentatives[0]);
        FileDAttenteDuSemis::plusTard(fn () => $this->renouveler($societe));

        ServeurDEnvoiEnPanne::pendant(function () use ($rang, $tentatives): void {
            foreach ($tentatives as $heure) {
                $this->alHeure($rang, $heure);
                $this->passerLaFileDeLaDemonstration();
            }
        });
    }

    private function renouveler(string $societe): void
    {
        $this->semis->horsDeToutContexte(fn () => app(RenouvelerLeLienDeLaDemonstrationAction::class)->handle($this->semis->proprietaire($societe), $this->semis->motDePasseDuProprietaire($societe), null));
    }

    /**
     * Des passages du travailleur sur la file des travaux de la
     * démonstration du site réel, tant qu'un travail y est dû à l'heure du
     * semis.
     *
     * @throws LogicException quand le travailleur s'arrête en erreur
     */
    private function passerLaFileDeLaDemonstration(): void
    {
        $dus = static fn (): bool => DB::table('jobs')
            ->where('queue', EnvoyerLesMessagesALaDemonstration::FILE)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->getTimestamp())
            ->exists();

        while ($dus()) {
            if (Artisan::call('queue:work', ['connection' => FileDAttenteDuSemis::BASE, '--queue' => EnvoyerLesMessagesALaDemonstration::FILE, '--once' => true, '--sleep' => 0]) !== 0) {
                throw new LogicException('Le travailleur de la file de la démonstration s\'est arrêté en erreur : '.trim(Artisan::output()));
            }
        }
    }

    private function troisiemeEchecDuStudio(int $rang): void
    {
        $this->echouer($rang, '22:00', 'studio', creer: false);
    }

    /**
     * Le site de démonstration répond « en échec » : à la création, ou à
     * une demande de l'état ; un essai de plus au moins une heure après le
     * précédent (voir EtatsDeLaDemonstration).
     */
    private function echouer(int $rang, string $heure, string $societe, bool $creer): void
    {
        $this->site->repondre(202, ['etat' => 'en_echec', 'cause' => 'La copie du modèle n\'a pas pu être créée : le disque du site de démonstration est plein.']);

        if ($creer) {
            $this->creer($rang, $heure, $societe);
        } else {
            $this->demanderLEtat($rang, $heure, $societe);
        }

        $this->site->repondre(200, ['etat' => 'prete']);
    }

    private function creer(int $rang, string $heure, string $societe): void
    {
        $this->alHeure($rang, $heure);
        $this->semis->horsDeToutContexte(fn () => app(DemanderUneDemonstrationAction::class)->handle($this->semis->proprietaire($societe), null, null));
    }

    private function demanderLEtat(int $rang, string $heure, string $societe): void
    {
        $this->alHeure($rang, $heure);
        $demonstration = $this->demonstrationDe($societe);
        $this->semis->horsDeToutContexte(function () use ($societe, $demonstration): void {
            app(TenantContext::class)->runAsSociete($this->semis->societe($societe), fn () => DB::transaction(fn () => app(BoiteDEnvoi::class)->demanderLEtat($demonstration)));
        });
    }

    /**
     * Va à cette heure du soir, ou, si un autre geste du jour l'a déjà
     * passée, à la minute qui suit : le semis ne remonte jamais le temps.
     */
    private function alHeure(int $rang, string $heure): void
    {
        $calendrier = Calendrier::deParis();
        $voulu = $calendrier->heureMurale($this->horloge->jour($rang).' '.$heure);

        $this->horloge->aller($rang, $voulu->greaterThan(CarbonImmutable::now()) ? $heure : $calendrier->afficher(CarbonImmutable::now()->addMinute(), 'H:i'));
    }

    private function demonstrationDe(string $societe): Demonstration
    {
        return Demonstration::query()->withoutGlobalScopes()->where('societe_id', $this->semis->societe($societe)->getKey())->orderByDesc('id')->firstOrFail();
    }
}
