<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Jobs\AlerterDuSeuilDEchecsJob;
use App\Models\User;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
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
 * elle-même.
 */
final class LimitationDesConnexions
{
    public const ECHECS_PAR_SAISIE_ET_ADRESSE = 5;

    public const FENETRE_PAR_SAISIE_ET_ADRESSE = 900;

    /**
     * Les attentes, en secondes, au 5e échec par saisie et adresse IP, puis
     * à chaque échec qui suit : au 6e, au 7e, au 8e, et au-delà.
     */
    public const ATTENTES_CROISSANTES = [60, 120, 240, 480, 900];

    public const ECHECS_PAR_SAISIE = 20;

    public const ECHECS_PAR_ADRESSE = 30;

    public const ATTENTE = 900;

    public const UNE_HEURE = 3600;

    public const ECHECS_DE_SUITE_PAR_POSTE = 10;

    public const ECHECS_EGAUX_A_LA_SAISIE_PAR_HEURE = 200;

    public const MESSAGE_ALERTE_EDITEUR = 'Connexions : plus de 200 essais manqués en une heure, sur toute la plateforme, dont le mot de passe tapé est l\'identifiant tapé.';

    public function __construct(private readonly Factory $caches) {}

    /**
     * Le message d'une attente, en minutes entières.
     */
    public static function message(int $secondes): string
    {
        $minutes = max(1, (int) ceil($secondes / 60));

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
     * Le nombre d'échecs que compte, en ce moment, le compteur de cette clé.
     */
    public function compteDe(string $cle): int
    {
        return (int) $this->cache()->get($cle, 0);
    }

    /**
     * Compte un échec : pour cette saisie, cette adresse IP, ce poste connu
     * s'il y en a un, et le compte qu'elle désigne s'il existe.
     */
    public function echec(#[SensitiveParameter] string $saisie, string $adresse, ?string $nonceDuPoste, ?User $compte, bool $motDePasseEgalALaSaisie): void
    {
        $echecsDeLaSaisie = $this->compter($this->cle('alerte', $saisie), self::UNE_HEURE);

        if ($compte !== null && $echecsDeLaSaisie >= self::ECHECS_PAR_SAISIE && $this->cache()->add($this->cle('alerte-envoyee', $saisie), true, self::UNE_HEURE)) {
            AlerterDuSeuilDEchecsJob::dispatch((int) $compte->getKey());
        }

        if ($motDePasseEgalALaSaisie && $this->compter('connexion-plateforme-mot-de-passe-egal-a-la-saisie', self::UNE_HEURE) === self::ECHECS_EGAUX_A_LA_SAISIE_PAR_HEURE + 1) {
            Log::warning(self::MESSAGE_ALERTE_EDITEUR);
        }

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
     * La clé d'un compteur : son nom, puis le HMAC de chaque valeur.
     */
    public function cle(string $compteur, string ...$valeurs): string
    {
        return 'connexion-'.$compteur.':'.implode(':', array_map($this->hmac(...), $valeurs));
    }

    private function cleDAttente(string $compteur, string ...$valeurs): string
    {
        return $this->cle('attente-'.$compteur, ...$valeurs);
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

    /**
     * Ajoute un à ce compteur, créé à zéro s'il manque, pour cette fenêtre
     * en secondes, à partir de son premier échec ; rend le nouveau compte.
     * L'ajout ne remplace jamais un compteur qui existe, et l'incrément est
     * atomique : des échecs simultanés sont tous comptés. Un compteur
     * expiré entre l'ajout et l'incrément se recrée, une fois.
     *
     * @throws RuntimeException quand le compteur ne s'incrémente pas
     */
    private function compter(string $cle, int $fenetre): int
    {
        foreach ([1, 2] as $essai) {
            $this->cache()->add($cle.':fin', now()->addSeconds($fenetre)->getTimestamp(), $fenetre);
            $this->cache()->add($cle, 0, $fenetre);
            $compte = $this->cache()->increment($cle);

            if ($compte !== false) {
                return (int) $compte;
            }
        }

        throw new RuntimeException('Le compteur des essais de connexion ne s\'incrémente pas.');
    }

    /**
     * Les secondes qui restent avant la fin de la fenêtre de ce compteur,
     * s'il compte au moins un échec ; 0 sinon.
     */
    private function secondesRestantes(string $cle): int
    {
        return $this->compteDe($cle) > 0 ? max(0, (int) $this->cache()->get($cle.':fin', 0) - now()->getTimestamp()) : 0;
    }

    /**
     * Fait vivre ce compteur encore ce nombre de secondes, à partir de
     * maintenant, sans toucher à son compte.
     */
    private function prolonger(string $cle, int $secondes): void
    {
        $this->cache()->touch($cle, $secondes);
        $this->cache()->put($cle.':fin', now()->addSeconds($secondes)->getTimestamp(), $secondes);
    }

    private function effacer(string $cle): void
    {
        $this->cache()->forget($cle);
        $this->cache()->forget($cle.':fin');
    }

    /**
     * Fait attendre cette clé ce nombre de secondes, à partir de maintenant.
     */
    private function attendre(string $cle, int $secondes): void
    {
        $this->effacer($cle);
        $this->compter($cle, $secondes);
    }

    /**
     * Le cache des compteurs : celui des limitations de l'application
     * (cache.limiter), sinon le cache par défaut, partagé entre les
     * processus.
     */
    private function cache(): Repository
    {
        return $this->caches->store(config('cache.limiter'));
    }

    /**
     * Le HMAC-SHA256 d'une valeur, avec une clé dérivée d'APP_KEY propre à
     * la limitation des connexions.
     */
    private function hmac(#[SensitiveParameter] string $valeur): string
    {
        return hash_hmac('sha256', $valeur, self::cleSecrete());
    }

    private static function cleSecrete(): string
    {
        $cle = (string) config('app.key');

        if ($cle === '') {
            throw new RuntimeException('APP_KEY manque : les compteurs des essais de connexion ne peuvent pas être protégés.');
        }

        $brute = Str::startsWith($cle, 'base64:') ? base64_decode(substr($cle, 7), true) : $cle;

        return hash_hkdf('sha256', (string) $brute, 32, 'limitation-des-connexions');
    }
}
