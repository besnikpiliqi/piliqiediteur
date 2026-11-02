<?php

namespace Tests\Browser\Support;

use App\Domain\Calendrier\Calendrier;
use Closure;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Execution;
use Pest\Browser\Playwright\Locator;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * Un onglet d'un navigateur neuf (sans témoin ni session), piloté comme une
 * personne le ferait : il ouvre une adresse, clique sur un bouton par son
 * libellé, remplit un champ par son étiquette, ouvre et ferme une fenêtre.
 * Après chaque geste, il attend que la page soit au repos (plus aucune
 * requête en cours, Livewire comprise, depuis un instant) puis vérifie
 * qu'aucune erreur JavaScript ni de console n'est apparue et qu'aucune
 * réponse du serveur n'a été un refus ou une erreur (4xx ou 5xx) que le
 * test n'attendait pas (voir JournalDesReponses).
 *
 * Aucune attente n'endort le processus : le serveur de l'application tourne
 * dans le processus du test et ne répond au navigateur que pendant que le
 * test attend le navigateur (voir attendre()).
 */
final class Navigation
{
    /**
     * Le délai d'attente d'un geste, en secondes.
     */
    public const DELAI = 15;

    /**
     * Le temps sans aucune requête au bout duquel la page est au repos, en
     * millisecondes : il couvre l'anti-rebond des champs de Livewire.
     */
    private const REPOS_EN_MS = 300;

    /**
     * Le sélecteur de la fenêtre de Filament ouverte au premier plan.
     */
    public const FENETRE = '.fi-modal.fi-modal-open .fi-modal-window';

    public function __construct(
        private readonly PendingAwaitablePage $onglet,
        private readonly JournalDesReponses $journal,
    ) {}

    /**
     * Un onglet d'un navigateur neuf, en français et à l'heure de Paris,
     * surveillé dès le premier script de chaque page (voir
     * surveillance.js), ouvert sur cette adresse de l'application.
     */
    public static function ouvrir(string $chemin, JournalDesReponses $journal): self
    {
        $onglet = visit('/robots.txt')->withLocale('fr-FR')->withTimezone(Calendrier::deParis()->fuseau());
        $onglet->page()->context()->addInitScript((string) file_get_contents(__DIR__.'/surveillance.js'));
        $navigation = new self($onglet, $journal);

        return $navigation->aller($chemin);
    }

    /**
     * La page de Playwright, pour un geste que cette classe n'offre pas.
     */
    public function page(): Page
    {
        return $this->onglet->page();
    }

    /**
     * La page de Pest, avec ses assertions (assertSee, assertPathIs...).
     */
    public function pest(): PendingAwaitablePage
    {
        return $this->onglet;
    }

    /**
     * Ouvre cette adresse de l'application dans l'onglet, comme une adresse
     * tapée à la main, en un seul chargement, dans le délai d'un geste (la
     * navigation de la page de Pest recommence le chargement toutes les
     * secondes tant qu'il n'est pas fini).
     */
    public function aller(string $chemin): self
    {
        $this->page()->goto(ComputeUrl::from($chemin));

        return $this->attendre()->verifier();
    }

    /**
     * Le chemin de la page affichée, sans le serveur ni la requête.
     */
    public function chemin(): string
    {
        return (string) parse_url($this->page()->url(), PHP_URL_PATH);
    }

    /**
     * Attend que la page soit chargée et au repos : aucune requête en cours,
     * ni aucune depuis REPOS_EN_MS, et chaque composant visible que
     * Filament charge à part (x-load : la liste à recherche d'un champ)
     * démarré, pour qu'il ne lance pas sa requête après le repos. Playwright
     * attend ensuite de lui-même qu'un élément soit immobile (fenêtre
     * ouverte) avant de cliquer dessus.
     */
    public function attendre(): self
    {
        $this->attendreQue(
            'document.readyState === "complete" && window.__piliqi !== undefined && window.__piliqi.enCours === 0 && performance.now() - window.__piliqi.derniereActivite > '.self::REPOS_EN_MS
                .' && [...document.querySelectorAll("[x-load]")].every((composant) => composant.offsetParent === null || composant._x_dataStack !== undefined)',
            'la page ne revient pas au repos',
        );

        return $this;
    }

    /**
     * Attend que cette expression JavaScript soit vraie, en laissant le
     * serveur de l'application répondre pendant l'attente ; échoue avec ce
     * motif au bout du délai.
     */
    public function attendreQue(string $expression, string $motif): self
    {
        $fin = microtime(true) + self::DELAI;

        while (true) {
            $vrai = $this->evaluerPendantUneNavigation('() => { try { return Boolean('.$expression.'); } catch (e) { return false; } }');

            if ($vrai === true) {
                return $this;
            }

            if (microtime(true) > $fin) {
                Assert::fail('Au bout de '.self::DELAI.' s, '.$motif.' (page '.$this->page()->url().').');
            }

            Execution::instance()->wait(0.05);
        }
    }

    /**
     * Le résultat de cette fonction JavaScript dans la page, ou false si la
     * page change sous elle (un clic qui mène à une autre page détruit le
     * contexte de l'ancienne) ou n'a pas encore de contexte où l'évaluer
     * (Playwright l'attend alors jusqu'à son délai) : l'attente qui
     * l'appelle recommence jusqu'au sien.
     */
    private function evaluerPendantUneNavigation(string $fonction): mixed
    {
        try {
            return $this->page()->evaluate($fonction);
        } catch (ExpectationFailedException $exception) {
            foreach (['Execution context was destroyed', 'Cannot find context', 'Timeout'] as $pendantUneNavigation) {
                if (str_contains($exception->getMessage(), $pendantUneNavigation)) {
                    return false;
                }
            }

            throw $exception;
        }
    }

    /**
     * Aucune erreur JavaScript ni de console depuis la vérification
     * précédente, et aucune réponse du serveur en 4xx ou 5xx que le test
     * n'attendait pas (voir JournalDesReponses::tolerer()).
     */
    public function verifier(): self
    {
        $erreurs = $this->page()->evaluate('() => window.__piliqi ? window.__piliqi.erreurs.splice(0) : []');

        if (is_array($erreurs) && $erreurs !== []) {
            Assert::fail('Erreurs dans le navigateur sur '.$this->page()->url().' :'.PHP_EOL.implode(PHP_EOL, $erreurs));
        }

        $this->journal->verifier();

        return $this;
    }

    /**
     * Fait ce geste, qui appelle le serveur après un délai (la recherche
     * d'un tableau attend que la frappe s'arrête), attend que la requête
     * parte, puis que la page revienne au repos, et la vérifie.
     */
    public function gesteQuiAppelleLeServeur(Closure $geste, string $description): self
    {
        $avant = (int) $this->page()->evaluate('() => window.__piliqi ? window.__piliqi.lancees : 0');
        $this->geste($geste, $description);

        return $this->attendreQue('window.__piliqi.lancees > '.$avant, $description.' n\'a appelé aucune fois le serveur')
            ->attendre()
            ->verifier();
    }

    /**
     * Un élément par son sélecteur (CSS ou de Playwright), qui doit être le
     * seul à y répondre.
     */
    public function element(string $selecteur): Locator
    {
        return $this->page()->locator($selecteur);
    }

    /**
     * Clique sur l'élément de ce sélecteur, puis attend la page au repos et
     * la vérifie.
     */
    public function cliquer(string $selecteur): self
    {
        return $this->geste(fn () => $this->element($selecteur)->click(), 'le clic sur '.$selecteur);
    }

    /**
     * Fait ce geste dans la page, puis attend qu'elle revienne au repos et
     * la vérifie. Le repos se compte depuis le geste : une requête que le
     * geste ne lance qu'un instant après (Livewire regroupe ses appels)
     * n'est pas manquée. Un geste impossible (un élément introuvable,
     * caché ou couvert) échoue en le nommant, et la page s'enregistre en
     * image dans tests/Browser/Screenshots, que la CI garde.
     */
    public function geste(Closure $geste, string $description = 'le geste'): self
    {
        $this->page()->evaluate('() => { if (window.__piliqi) { window.__piliqi.derniereActivite = performance.now(); } }');

        try {
            $geste();
        } catch (ExpectationFailedException $exception) {
            $this->page()->screenshot(true, 'echec-'.substr(md5($this->page()->url().$description), 0, 12));
            Assert::fail(ucfirst($description).' a échoué sur '.$this->page()->url().' : '.$exception->getMessage());
        }

        return $this->attendre()->verifier();
    }

    /**
     * Clique sur le bouton (ou le lien qui en a l'air) de ce libellé exact,
     * dans la fenêtre ouverte s'il y en a une, sinon dans la page.
     */
    public function cliquerSur(string $libelle): self
    {
        return $this->cliquer($this->portee().' '.self::bouton($libelle));
    }

    /**
     * Clique sur le lien de ce libellé exact.
     */
    public function cliquerSurLeLien(string $libelle): self
    {
        return $this->cliquer($this->portee().' internal:role=link[name='.self::texte($libelle).'s]');
    }

    /**
     * Remplit le champ de cette étiquette (sans l'astérisque d'un champ
     * obligatoire), dans la fenêtre ouverte s'il y en a une, et dans
     * l'élément de ce sélecteur s'il est donné (une ligne d'une liste de
     * lignes, dont chaque champ a la même étiquette).
     */
    public function remplir(string $etiquette, string $valeur, ?string $dans = null): self
    {
        return $this->geste(fn () => $this->champDansLaPortee($etiquette, $dans)->fill($valeur), 'la saisie dans « '.$etiquette.' »');
    }

    /**
     * Choisit cette option, par son libellé ou sa valeur, dans la liste
     * déroulante native de cette étiquette.
     */
    public function choisir(string $etiquette, string $option, ?string $dans = null): self
    {
        return $this->geste(fn () => $this->champDansLaPortee($etiquette, $dans)->selectOption($option), 'le choix dans « '.$etiquette.' »');
    }

    /**
     * Remplit ce champ de la ligne de ce rang (à partir de 0) de cette liste
     * de lignes du formulaire (un Repeater de Filament, par son nom : parts,
     * lignes...), dans la fenêtre ouverte s'il y en a une : chaque ligne a
     * les mêmes étiquettes.
     */
    public function remplirLaLigne(string $liste, int $rang, string $champ, string $valeur): self
    {
        return $this->geste(fn () => $this->champDeLaLigne($liste, $rang, $champ)->fill($valeur), "la saisie dans {$champ} de la ligne {$rang} de {$liste}");
    }

    /**
     * Choisit cette option, par son libellé ou sa valeur, dans la liste
     * déroulante native de ce champ de la ligne de ce rang de cette liste de
     * lignes (voir remplirLaLigne()).
     */
    public function choisirDansLaLigne(string $liste, int $rang, string $champ, string $option): self
    {
        return $this->geste(fn () => $this->champDeLaLigne($liste, $rang, $champ)->selectOption($option), "le choix dans {$champ} de la ligne {$rang} de {$liste}");
    }

    private function champDeLaLigne(string $liste, int $rang, string $champ): Locator
    {
        return $this->element($this->portee().' [id*='.self::texte('.'.$liste.'.').'][id$='.self::texte('.'.$champ).'] >> nth='.$rang);
    }

    /**
     * Clique sur le bouton qui envoie le formulaire, de ce libellé, dans la
     * fenêtre ouverte s'il y en a une : un autre bouton de la page peut
     * porter le même nom (la création d'une option d'une liste).
     */
    public function soumettre(string $libelle): self
    {
        return $this->cliquer($this->portee().' button[type=submit]:has-text('.self::texte($libelle).')');
    }

    /**
     * Le champ de cette étiquette dans la fenêtre ouverte ou la page, et
     * dans l'élément de ce sélecteur s'il est donné.
     */
    private function champDansLaPortee(string $etiquette, ?string $dans): Locator
    {
        return $this->element($this->portee().' '.($dans === null ? '' : $dans.' >> ').self::champ($etiquette));
    }

    /**
     * Choisit l'option qui contient ce texte dans la liste à recherche de
     * cette étiquette (un Select de Filament qui n'est pas natif) : ouvre la
     * liste, y tape le texte si elle a une recherche, puis clique sur
     * l'option.
     */
    public function choisirDansLaListe(string $etiquette, string $option): self
    {
        $id = $this->page()->evaluate(<<<'JS'
            (etiquette) => {
                const etiquettes = [...document.querySelectorAll('label[for]')];
                const trouvee = etiquettes.find((candidate) => candidate.innerText.replace('*', '').trim() === etiquette && candidate.offsetParent !== null);

                return trouvee ? trouvee.getAttribute('for') : null;
            }
        JS, $etiquette);
        Assert::assertIsString($id, "Aucun champ visible n'a l'étiquette « {$etiquette} ».");
        $liste = '.choices:has([id='.self::texte($id).'])';
        $this->cliquer($liste.' .choices__inner');
        $recherche = $this->element($liste.' input.choices__input--cloned');

        if ($recherche->count() > 0 && $recherche->isVisible()) {
            $this->geste(fn () => $recherche->fill($option));
        }

        return $this->geste(fn () => $this->element($liste.' .choices__list--dropdown .choices__item--choice:has-text('.self::texte($option).')')->first()->click());
    }

    /**
     * Coche la case de cette étiquette.
     */
    public function cocher(string $etiquette, ?string $dans = null): self
    {
        return $this->geste(fn () => $this->champDansLaPortee($etiquette, $dans)->check());
    }

    /**
     * Ce texte se lit dans la page.
     */
    public function voir(string $texte): self
    {
        $this->onglet->assertSee($texte);

        return $this;
    }

    /**
     * Ce texte ne se lit pas dans la page.
     */
    public function nePasVoir(string $texte): self
    {
        $this->onglet->assertDontSee($texte);

        return $this;
    }

    /**
     * Une fenêtre de Filament est ouverte au premier plan.
     */
    public function fenetreOuverte(): bool
    {
        return $this->page()->evaluate('() => document.querySelector('.self::texte(self::FENETRE).') !== null') === true;
    }

    /**
     * Ferme la fenêtre ouverte sans la valider, par son bouton « Annuler »
     * s'il existe, sinon par sa croix, et attend qu'elle soit fermée.
     */
    public function fermerLaFenetre(): self
    {
        $annuler = $this->page()->locator(self::FENETRE.' >> '.self::bouton('Annuler'));

        return $this->geste(fn () => $annuler->count() > 0
            ? $annuler->last()->click()
            : $this->page()->locator(self::FENETRE.' .fi-modal-close-btn')->last()->click());
    }

    /**
     * Se déconnecte par le menu du compte du panneau affiché, et attend la
     * page de connexion.
     */
    public function seDeconnecter(): self
    {
        $this->cliquer('.fi-user-menu .fi-dropdown-trigger');

        return $this->cliquer('.fi-user-menu >> '.self::bouton('Déconnexion'));
    }

    /**
     * La portée d'un geste : la fenêtre ouverte au premier plan s'il y en
     * a une, sinon la page entière.
     */
    private function portee(): string
    {
        return $this->fenetreOuverte() ? self::FENETRE.' >>' : 'body >>';
    }

    /**
     * Le sélecteur de Playwright d'un bouton de ce libellé exact.
     */
    public static function bouton(string $libelle): string
    {
        return 'internal:role=button[name='.self::texte($libelle).'s]';
    }

    /**
     * Le sélecteur de Playwright d'un champ de cette étiquette ; celle d'un
     * champ obligatoire de Filament se termine par un astérisque.
     */
    public static function champ(string $etiquette): string
    {
        return 'internal:label=/^\s*'.preg_quote($etiquette, '/').'\s*\*?\s*$/';
    }

    /**
     * Ce texte entre guillemets, échappé pour un sélecteur de Playwright ou
     * une expression JavaScript.
     */
    public static function texte(string $texte): string
    {
        return json_encode($texte, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
