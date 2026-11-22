<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Concerns\CompteursAtomiques;
use App\Domain\Tenancy\Jobs\AlerterDeLaMonteeDesEchecsJob;
use App\Domain\Tenancy\Jobs\AlerterDuSeuilDEchecsJob;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\Reglage;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Models\User;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * La limitation des essais de connexion (voir Connexion), sur le cache de
 * l'application, partagé entre les processus. Seuls les échecs comptent ;
 * une connexion réussie efface le compteur de sa saisie et de son adresse
 * IP. Jamais de blocage définitif d'un compte : seulement des attentes.
 *
 * Chaque compteur s'ajoute s'il manque, puis s'incrémente par l'opération
 * atomique du cache (voir compter()) : des échecs simultanés sont tous
 * comptés. Le RateLimiter de Laravel n'est pas utilisé : après un ajout
 * manqué, il remet à 1 un compteur qu'il vient d'incrémenter à 1, ce qui,
 * sous des échecs simultanés, efface ceux qu'un autre processus a comptés
 * entre-temps.
 *
 * Les compteurs se rangent sous la saisie normalisée (8 chiffres sans
 * séparateur, adresse en minuscules sans espaces : « 4821 0937 » et
 * « 48210937 » comptent pour le même identifiant) et sous l'adresse IP, et
 * chaque clé est un HMAC-SHA256 avec une clé secrète dérivée d'APP_KEY,
 * jamais la saisie en clair ni une empreinte sans clé, qui se retrouverait
 * en quelques secondes parmi 90 millions d'identifiants. Les mêmes
 * compteurs et la même réponse valent pour un identifiant qui n'existe pas.
 *
 * Sans témoin valable d'un poste connu du compte (voir PostesConnus) :
 * - par saisie et adresse IP : 5 échecs en 15 minutes, puis des attentes
 *   croissantes (1, 2, 4, 8, puis 15 minutes au plus), une à chaque échec
 *   suivant ; la progression se garde tant que les échecs se suivent (qui
 *   respecte chaque attente et recommence ne repart jamais d'une minute)
 *   et ne s'oublie que 15 minutes après la fin de la dernière attente sans
 *   nouvel échec, ou à une connexion réussie ;
 * - par saisie, toutes adresses : 20 échecs par heure, puis 15 minutes ;
 * - par saisie, toutes adresses, sur la journée : 25 échecs (décision de
 *   Claude D76, T36.1 à T36.4), puis l'attente de ce qui reste de la
 *   journée qui a commencé au premier d'entre eux ; jamais plus de 25 essais
 *   par saisie et par jour hors d'un poste connu, jamais un blocage pour de
 *   bon : la fenêtre finie, l'accès revient ;
 * - par adresse IP, toutes saisies : 30 échecs par heure, puis 15
 *   minutes ; une rafale d'erreurs depuis l'adresse commune d'un magasin ne
 *   fait pas attendre ses postes connus.
 * Avec un témoin valable, aucune attente ; ses échecs se comptent sous la
 * clé de son nonce, et au 10e échec de suite il ne compte plus comme poste
 * connu pendant l'heure qui suit ce 10e échec.
 *
 * Alertes : au propriétaire de la société d'un compte dont la saisie
 * dépasse 20 échecs en une heure, tous échecs comptés, poste connu compris,
 * une fois par heure au plus, par une tâche en file (voir
 * AlerterDuSeuilDEchecsJob) ; à l'éditeur, dans le journal de
 * l'application, sans identifiant ni adresse IP, au-delà de 200 échecs par
 * heure, sur toute la plateforme, dont le mot de passe tapé est la saisie
 * elle-même ; et à l'éditeur encore, par e-mail et par une tâche en file
 * (voir AlerterDeLaMonteeDesEchecsJob), une fois par heure et par motif, quand
 * les échecs de toute la plateforme dans l'heure dépassent un seuil absolu
 * (500) ou trois fois la moyenne horaire des 7 jours d'avant (avec un
 * plancher de 100), et quand plus de 50 identifiants différents échouent
 * depuis une même adresse IP dans l'heure (voir surveillerLaPlateforme()) :
 * seuils réglables (config/piliqi.php), compteurs en HMAC, aucun identifiant
 * ni adresse en clair. Tous les échecs comptent, poste connu compris.
 */
final class LimitationDesConnexions
{
    use CompteursAtomiques;

    public const ECHECS_PAR_SAISIE_ET_ADRESSE = 5;

    public const FENETRE_PAR_SAISIE_ET_ADRESSE = 900;

    /**
     * Les attentes, en secondes, au 5e échec par saisie et adresse IP, puis
     * à chaque échec qui suit : au 6e, au 7e, au 8e, et au-delà.
     */
    public const ATTENTES_CROISSANTES = [60, 120, 240, 480, 900];

    public const ECHECS_PAR_SAISIE = 20;

    public const ECHECS_PAR_ADRESSE = 30;

    public const ECHECS_PAR_SAISIE_ET_PAR_JOUR = 25;

    public const UN_JOUR = 86400;

    /**
     * Combien d'heures d'avant la moyenne horaire compte : 7 jours.
     */
    public const HEURES_DE_LA_MOYENNE = 168;

    public const ATTENTE = 900;

    public const UNE_HEURE = 3600;

    public const ECHECS_DE_SUITE_PAR_POSTE = 10;

    public const ECHECS_EGAUX_A_LA_SAISIE_PAR_HEURE = 200;

    public const MESSAGE_ALERTE_EDITEUR = 'Connexions : plus de 200 essais manqués en une heure, sur toute la plateforme, dont le mot de passe tapé est l\'identifiant tapé.';

    public function __construct(private readonly Factory $caches) {}

    protected function prefixeDesCles(): string
    {
        return 'connexion-';
    }

    /**
     * Le message d'une attente, en minutes entières, et en heures entières
     * au-delà de deux heures (l'attente du plafond d'une journée).
     */
    public static function message(int $secondes): string
    {
        $minutes = max(1, (int) ceil($secondes / 60));

        if ($minutes > 120) {
            $heures = (int) ceil($minutes / 60);

            return 'Trop de tentatives. Réessayez dans '.$heures.' heures.';
        }

        return 'Trop de tentatives. Réessayez dans '.$minutes.' minute'.($minutes > 1 ? 's' : '').'.';
    }

    /**
     * Combien de secondes cette saisie, depuis cette adresse IP, doit
     * encore attendre ; 0 sans attente, et toujours 0 avec le témoin
     * valable d'un poste connu du compte.
     */
    public function attente(#[SensitiveParameter] string $saisie, string $adresse, bool $posteConnu): int
    {
        if ($posteConnu) {
            return 0;
        }

        return max(array_map($this->secondesRestantes(...), [
            $this->cleDAttente('paire', $saisie, $adresse),
            $this->cleDAttente('saisie', $saisie),
            $this->cleDAttente('jour', $saisie),
            $this->cleDAttente('adresse', $adresse),
        ]));
    }

    /**
     * Le témoin de ce nonce ne compte-t-il plus comme poste connu : dans
     * l'heure qui suit son 10e échec de suite ?
     */
    public function posteIgnore(string $nonce): bool
    {
        return $this->compteDe($this->cle('poste-ignore', $nonce)) > 0;
    }

    /**
     * Compte un échec : pour cette saisie, cette adresse IP, ce poste connu
     * s'il y en a un, et le compte qu'elle désigne s'il existe.
     */
    public function echec(#[SensitiveParameter] string $saisie, string $adresse, ?string $nonceDuPoste, ?User $compte, bool $motDePasseEgalALaSaisie): void
    {
        $echecsDeLaSaisie = $this->compter($this->cle('alerte', $saisie), self::UNE_HEURE);

        if ($compte !== null && $echecsDeLaSaisie >= self::ECHECS_PAR_SAISIE && $this->cache()->add($this->cle('alerte-envoyee', $saisie), true, self::UNE_HEURE)) {
            AlerterDuSeuilDEchecsJob::dispatch(CleDuModele::de($compte));
        }

        if ($motDePasseEgalALaSaisie && $this->compter('connexion-plateforme-mot-de-passe-egal-a-la-saisie', self::UNE_HEURE) === self::ECHECS_EGAUX_A_LA_SAISIE_PAR_HEURE + 1) {
            Log::warning(self::MESSAGE_ALERTE_EDITEUR);
        }

        $this->surveillerLaPlateforme($saisie, $adresse);

        if ($nonceDuPoste !== null) {
            if ($this->compter($this->cle('poste', $nonceDuPoste), self::UNE_HEURE) >= self::ECHECS_DE_SUITE_PAR_POSTE) {
                $this->attendre($this->cle('poste-ignore', $nonceDuPoste), self::UNE_HEURE);
            }

            return;
        }

        $attente = $this->attenteCroissante($saisie, $adresse);

        if ($attente !== null) {
            $this->attendre($this->cleDAttente('paire', $saisie, $adresse), $attente);
        }

        if ($this->compter($this->cle('saisie', $saisie), self::UNE_HEURE) >= self::ECHECS_PAR_SAISIE) {
            $this->attendre($this->cleDAttente('saisie', $saisie), self::ATTENTE);
        }

        if ($this->compter($this->cle('jour', $saisie), self::UN_JOUR) >= self::ECHECS_PAR_SAISIE_ET_PAR_JOUR) {
            $this->attendre($this->cleDAttente('jour', $saisie), max(1, $this->secondesRestantes($this->cle('jour', $saisie))));
        }

        if ($this->compter($this->cle('adresse', $adresse), self::UNE_HEURE) >= self::ECHECS_PAR_ADRESSE) {
            $this->attendre($this->cleDAttente('adresse', $adresse), self::ATTENTE);
        }
    }

    /**
     * Une connexion réussie : le compteur de cette saisie et de cette
     * adresse IP s'efface, avec sa progression et son attente, et celui du
     * poste connu.
     */
    public function reussite(#[SensitiveParameter] string $saisie, string $adresse, ?string $nonceDuPoste): void
    {
        $this->effacer($this->cle('paire', $saisie, $adresse));
        $this->effacer($this->cle('progression', $saisie, $adresse));
        $this->effacer($this->cleDAttente('paire', $saisie, $adresse));

        if ($nonceDuPoste !== null) {
            $this->effacer($this->cle('poste', $nonceDuPoste));
        }
    }

    /**
     * Compte cet échec parmi ceux de toute la plateforme dans l'heure, et
     * prévient l'éditeur, une fois par heure et par motif, quand ils
     * dépassent un seuil absolu ou trois fois la moyenne horaire des 7 jours
     * d'avant (avec son plancher), ou quand trop d'identifiants différents
     * échouent depuis une même adresse IP. Les compteurs sont ceux, en HMAC,
     * de cette limitation : l'alerte ne porte aucun identifiant ni aucune
     * adresse en clair.
     */
    private function surveillerLaPlateforme(#[SensitiveParameter] string $saisie, string $adresse): void
    {
        $debut = (int) (floor(now()->getTimestamp() / self::UNE_HEURE) * self::UNE_HEURE);
        $heure = (string) $debut;
        $total = $this->compter($this->cle('plateforme-heure', $heure), 8 * self::UN_JOUR);
        $seuilAbsolu = Reglage::entier('piliqi.connexions.alerte_echecs_par_heure');
        $plancher = Reglage::entier('piliqi.connexions.alerte_plancher_par_heure');

        if ($total > $seuilAbsolu && $this->cache()->add($this->cle('alerte-plateforme-absolu', $heure), true, 2 * self::UNE_HEURE)) {
            AlerterDeLaMonteeDesEchecsJob::dispatch(AlerterDeLaMonteeDesEchecsJob::MOTIF_ABSOLU, $total, $seuilAbsolu, $debut);
        }

        if ($total > $plancher) {
            $seuilRelatif = max($plancher, (int) ceil(Reglage::entier('piliqi.connexions.alerte_multiple_de_la_moyenne') * $this->moyenneHoraire($debut)));

            if ($total > $seuilRelatif && $this->cache()->add($this->cle('alerte-plateforme-relatif', $heure), true, 2 * self::UNE_HEURE)) {
                AlerterDeLaMonteeDesEchecsJob::dispatch(AlerterDeLaMonteeDesEchecsJob::MOTIF_RELATIF, $total, $seuilRelatif, $debut);
            }
        }

        if ($this->cache()->add($this->cle('adresse-saisie-heure', $heure, $adresse, $saisie), true, 2 * self::UNE_HEURE)) {
            $differentes = $this->compter($this->cle('adresse-identifiants-heure', $heure, $adresse), 2 * self::UNE_HEURE);
            $seuilDeLAdresse = Reglage::entier('piliqi.connexions.alerte_identifiants_par_adresse');

            if ($differentes > $seuilDeLAdresse && $this->cache()->add($this->cle('alerte-plateforme-adresse', $heure, $adresse), true, 2 * self::UNE_HEURE)) {
                AlerterDeLaMonteeDesEchecsJob::dispatch(AlerterDeLaMonteeDesEchecsJob::MOTIF_ADRESSE, $differentes, $seuilDeLAdresse, $debut, substr($this->hmac($adresse), 0, 8));
            }
        }
    }

    /**
     * La moyenne horaire des échecs de toute la plateforme sur les 7 jours
     * qui précèdent cette heure (168 heures, une heure sans échec compte
     * pour zéro).
     */
    private function moyenneHoraire(int $debutDeLHeure): float
    {
        $cles = [];

        foreach (range(1, self::HEURES_DE_LA_MOYENNE) as $rang) {
            $cles[] = $this->cle('plateforme-heure', (string) ($debutDeLHeure - $rang * self::UNE_HEURE));
        }

        return array_sum(array_map(ValeurSimple::enEntier(...), $this->cache()->many($cles))) / self::HEURES_DE_LA_MOYENNE;
    }

    /**
     * L'attente qu'impose cet échec de cette saisie depuis cette adresse
     * IP ; null sans attente. Tant qu'aucune progression n'est en cours,
     * l'échec compte parmi ceux des 15 dernières minutes, et le 5e lance la
     * progression ; ensuite chaque échec la fait monter d'un cran (1, 2,
     * 4, 8, puis 15 minutes au plus) et la garde jusqu'à 15 minutes après
     * la fin de l'attente qu'il impose.
     */
    private function attenteCroissante(#[SensitiveParameter] string $saisie, string $adresse): ?int
    {
        $progression = $this->cle('progression', $saisie, $adresse);

        if ($this->compteDe($progression) === 0
            && $this->compter($this->cle('paire', $saisie, $adresse), self::FENETRE_PAR_SAISIE_ET_ADRESSE) < self::ECHECS_PAR_SAISIE_ET_ADRESSE) {
            return null;
        }

        $cran = $this->compter($progression, self::FENETRE_PAR_SAISIE_ET_ADRESSE);
        $attente = self::ATTENTES_CROISSANTES[min($cran, count(self::ATTENTES_CROISSANTES)) - 1];
        $this->prolonger($progression, $attente + self::FENETRE_PAR_SAISIE_ET_ADRESSE);

        return $attente;
    }
}
