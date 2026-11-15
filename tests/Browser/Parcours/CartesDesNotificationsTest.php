<?php

use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Filament\App\Resources\DevisResource;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use App\Filament\Societe\Resources\MembreResource;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\SocieteDeTest;

/*
 * Lot DEMO-V (modèle A choisi par l'éditeur le 14/11/2026) : la
 * notification de la cloche qui s'affiche toute seule en carte, pour un
 * vendeur et une responsable dans le panneau du magasin, la propriétaire
 * dans l'espace de la société et un collaborateur dans l'espace du
 * cabinet. Les pages regardent ici chaque seconde s'il y a du nouveau
 * (30 secondes en vrai) ; l'horloge, figée, avance d'une seconde avant la
 * première notification, pour qu'elle arrive après toutes celles de la
 * société de test, puis après chaque arrivée, car une notification ne se
 * montre qu'une fois sa seconde d'arrivée finie.
 *
 * À la connexion, la carte de la plus récente notification déjà là glisse
 * en haut à droite, puis s'en va seule. Une notification arrivée pendant
 * que la page est ouverte glisse à son tour, sous la barre du haut, avec
 * son bouton ; sa croix la ferme sans toucher la notification, que la
 * cloche garde, non lue, avec la même pastille. Une troisième arrive, et
 * son bouton ouvre la fiche et marque la notification comme lue.
 */

/*
 * Le parcours des cartes pour ce compte, connecté sur ce panneau, sur la
 * page où la connexion le mène, avec des notifications de cette fabrique
 * (titre, adresse) qui mènent à cette fiche, dont la page se lit à ce
 * texte.
 */
$parcoursDesCartes = function (User $compte, string $panneau, Closure $notification, string $fiche, string $texteDeLaFiche): void {
    $test = $this;
    config(['piliqi.intervalle_des_notifications_en_secondes' => 1]);
    $avertir = function (string $titre, ?string $url) use ($compte, $notification): DatabaseNotification {
        $avant = $compte->notifications()->pluck('id')->all();
        $compte->notify($notification($titre, $url));

        return $compte->notifications()->whereNotIn('id', $avant)->sole();
    };
    $test->travel(1)->seconds();
    $avertir('Avis déjà là avant la connexion', null);
    $test->travel(1)->seconds();

    $onglet = $test->seConnecter($compte, panneau: $panneau)->fermerLesAvis();
    poserLeReleveDesCartes($onglet);
    $onglet->attendreQue('document.querySelector(".piliqi-carte") !== null', 'la carte de l\'avis déjà là ne s\'affiche pas', releveDesCartes($onglet, $compte))
        ->voir('Avis déjà là avant la connexion', releveDesCartes($onglet, $compte))
        ->attendreQue('document.querySelector(".piliqi-carte") === null', 'la carte de l\'avis déjà là ne s\'en va pas seule', releveDesCartes($onglet, $compte));

    $nouvelle = $avertir('Nouvelle notification de PILIQI', $fiche);
    $test->travel(2)->seconds();

    $onglet->attendreQue('document.querySelector(".piliqi-carte") !== null', 'la carte de la nouvelle notification ne s\'affiche pas', releveDesCartes($onglet, $compte))
        ->voir('Nouvelle notification de PILIQI', releveDesCartes($onglet, $compte));
    expect(placeDeLaCarte($onglet))->toBe(['sousLaBarre' => true, 'aDroite' => true, 'annoncee' => true, 'boutons' => ['Voir']]);
    expect(pastilleDeLaCloche($onglet))->toBe((string) $compte->unreadNotifications()->count());

    $onglet->cliquer('.piliqi-carte .piliqi-carte__fermer')
        ->attendreQue('document.querySelector(".piliqi-carte") === null', 'la croix ne ferme pas la carte', releveDesCartes($onglet, $compte));
    expect(DatabaseNotification::query()->findOrFail($nouvelle->getKey())->getAttributes())->toBe($nouvelle->getAttributes())
        ->and(pastilleDeLaCloche($onglet))->toBe((string) $compte->unreadNotifications()->count());
    $onglet->cliquer('.fi-topbar-database-notifications-btn')
        ->voir('Nouvelle notification de PILIQI', releveDesCartes($onglet, $compte))
        ->fermerLaFenetre();

    $aOuvrir = $avertir('Notification à ouvrir', $fiche);
    $test->travel(2)->seconds();
    $onglet->attendreQue('document.querySelector(".piliqi-carte") !== null', 'la carte de la notification à ouvrir ne s\'affiche pas', releveDesCartes($onglet, $compte))
        ->voir('Notification à ouvrir', releveDesCartes($onglet, $compte))
        ->cliquer('.piliqi-carte a.fi-ac-link-action')
        ->attendreLaPage('^'.preg_quote((string) parse_url($fiche, PHP_URL_PATH), '#').'$')
        ->voir($texteDeLaFiche);

    expect($aOuvrir->fresh()?->read_at)->not->toBeNull()
        ->and($nouvelle->fresh()?->read_at)->toBeNull();
    $onglet->seDeconnecter();
};

/**
 * Où se trouve la carte affichée et ce qu'elle porte.
 *
 * @return array{sousLaBarre: bool, aDroite: bool, annoncee: bool, boutons: list<string>}
 */
function placeDeLaCarte(Navigation $onglet): array
{
    return $onglet->page()->evaluate(<<<'JS'
        () => {
            const carte = document.querySelector('.piliqi-carte');
            const place = carte.getBoundingClientRect();
            const barre = document.querySelector('.fi-topbar').getBoundingClientRect();

            return {
                sousLaBarre: place.top >= barre.bottom,
                aDroite: window.innerWidth - place.right < 40,
                annoncee: carte.closest('[role="status"]') !== null,
                boutons: Array.from(carte.querySelectorAll('a.fi-ac-link-action')).map((bouton) => bouton.textContent.trim()),
            };
        }
        JS);
}

/**
 * Pose dans la page le journal des cartes, que relit releveDesCartes() :
 * chaque carte ajoutée ou retirée de la pile, chaque changement de la
 * place de la pile et de la classe d'une carte ou de sa barre du temps
 * (la minuterie qui repart ou s'arrête), chaque appui, relâchement, clic
 * et sortie de la souris, chaque arrivée et départ du focus, chaque
 * passage des cartes et chaque requête de Livewire, avec son composant,
 * ses appels et sa réponse.
 */
function poserLeReleveDesCartes(Navigation $onglet): void
{
    $onglet->page()->evaluate(<<<'JS'
        () => {
            const pile = document.querySelector('.piliqi-cartes');
            const debut = performance.now();
            const releve = { journal: [], souris: null };
            window.__releveDesCartes = releve;
            const noter = (quoi) => {
                releve.journal.push(Math.round(performance.now() - debut) + ' ms : ' + quoi);
                releve.journal.splice(0, Math.max(0, releve.journal.length - 300));
            };
            const titre = (carte) => (carte.querySelector('.fi-no-notification-title') ?? carte).textContent.trim().replace(/\s+/g, ' ').slice(0, 60);
            const place = (element) => {
                const rectangle = element.getBoundingClientRect();

                return '(' + Math.round(rectangle.left) + ', ' + Math.round(rectangle.top) + ', ' + Math.round(rectangle.width) + ' x ' + Math.round(rectangle.height) + ')';
            };
            const decrire = (cible) => {
                if (! (cible instanceof Element)) {
                    return String(cible);
                }

                const carte = cible.closest('.piliqi-carte');
                const nom = cible.tagName.toLowerCase() + (typeof cible.className === 'string' && cible.className.trim() !== '' ? '.' + cible.className.trim().split(/\s+/).slice(0, 2).join('.') : '');

                return carte === null ? nom : nom + ' de la carte « ' + titre(carte) + ' » ' + place(carte);
            };

            new MutationObserver((mutations) => {
                for (const mutation of mutations) {
                    mutation.addedNodes.forEach((noeud) => noeud instanceof Element && noeud.classList.contains('piliqi-carte') && noter('carte ajoutée « ' + titre(noeud) + ' » ' + place(noeud)));
                    mutation.removedNodes.forEach((noeud) => noeud instanceof Element && noeud.classList.contains('piliqi-carte') && noter('carte retirée « ' + titre(noeud) + ' »'));

                    if (mutation.type === 'attributes' && mutation.target === pile) {
                        noter('pile : style « ' + pile.getAttribute('style') + ' »');
                    } else if (mutation.type === 'attributes' && mutation.target instanceof Element && mutation.target.closest('.piliqi-carte') !== null) {
                        noter('classe « ' + mutation.target.getAttribute('class') + ' » sur ' + decrire(mutation.target));
                    }
                }
            }).observe(pile, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class'] });

            for (const genre of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click']) {
                window.addEventListener(genre, (evenement) => noter(genre + ' sur ' + decrire(evenement.target) + ' en (' + evenement.clientX + ', ' + evenement.clientY + ')' + (evenement.isTrusted ? '' : ', simulé')), true);
                window.addEventListener(genre, (evenement) => noter('… ' + genre + ' arrivé à la fenêtre' + (evenement.defaultPrevented ? ', empêché' : '') + (evenement.target instanceof Node && ! evenement.target.isConnected ? ', cible détachée' : '')));
            }

            const barre = document.querySelector('.fi-topbar');

            if (barre !== null) {
                new MutationObserver((mutations) => {
                    const retires = mutations.reduce((nombre, mutation) => nombre + mutation.removedNodes.length, 0);
                    const ajoutes = mutations.reduce((nombre, mutation) => nombre + mutation.addedNodes.length, 0);
                    const attributs = mutations.filter((mutation) => mutation.type === 'attributes').map((mutation) => mutation.attributeName + ' de ' + decrire(mutation.target));
                    noter('barre du haut : ' + retires + ' nœud(s) retiré(s), ' + ajoutes + ' ajouté(s)' + (attributs.length > 0 ? ', ' + attributs.slice(0, 4).join(' ; ') : ''));
                }).observe(barre, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style', 'disabled', 'aria-hidden'] });
            }

            new MutationObserver((mutations) => {
                for (const mutation of mutations) {
                    mutation.removedNodes.forEach((noeud) => noeud instanceof Element && (noeud.matches('.fi-topbar-database-notifications-btn') || noeud.querySelector('.fi-topbar-database-notifications-btn') !== null) && noter('la cloche est retirée de la page'));
                    mutation.addedNodes.forEach((noeud) => noeud instanceof Element && (noeud.matches('.fi-topbar-database-notifications-btn') || noeud.querySelector('.fi-topbar-database-notifications-btn') !== null) && noter('la cloche est ajoutée à la page'));
                }
            }).observe(document.body, { childList: true, subtree: true });
            window.addEventListener('open-modal', (evenement) => noter('ouverture de la fenêtre ' + JSON.stringify(evenement.detail)));
            window.addEventListener('close-modal', (evenement) => noter('fermeture de la fenêtre ' + JSON.stringify(evenement.detail)));

            for (const genre of ['focusin', 'focusout']) {
                document.addEventListener(genre, (evenement) => noter(genre + ' sur ' + decrire(evenement.target) + ', vers ' + decrire(evenement.relatedTarget) + ', page ' + (document.hasFocus() ? 'au premier plan' : 'sans le focus')), true);
            }

            for (const genre of ['blur', 'focus']) {
                window.addEventListener(genre, (evenement) => evenement.target === window && noter('la fenêtre reçoit ' + genre));
            }

            document.addEventListener('mouseleave', (evenement) => evenement.target instanceof Element && evenement.target.classList.contains('piliqi-carte') && noter('la souris quitte ' + decrire(evenement.target)), true);
            document.addEventListener('mousemove', (evenement) => {
                const carte = evenement.target instanceof Element ? evenement.target.closest('.piliqi-carte') : null;
                const ici = carte === null ? 'hors des cartes' : 'la carte « ' + titre(carte) + ' »';

                if (releve.souris === null || releve.souris.ici !== ici) {
                    noter('la souris bouge sur ' + ici + ' en (' + evenement.clientX + ', ' + evenement.clientY + ')');
                }

                releve.souris = { ici, x: evenement.clientX, y: evenement.clientY };
            }, true);
            window.addEventListener('piliqi-cartes-des-notifications', (evenement) => noter('passage des cartes : ' + evenement.detail.cartes.map((carte) => {
                const modele = document.createElement('template');
                modele.innerHTML = carte;

                return '« ' + titre(modele.content.firstElementChild ?? modele.content) + ' »';
            }).join(', ')));
            window.Livewire.hook('commit', ({ component, commit, succeed, fail }) => {
                const nom = component.name + ' ' + commit.calls.map((appel) => appel.method).join(', ');
                noter('requête Livewire : ' + nom);
                succeed(() => noter('réponse Livewire : ' + nom));
                fail(() => noter('échec Livewire : ' + nom));
            });
            noter('relevé posé, pile ' + place(pile) + ', ' + pile.children.length + ' carte(s)');
        }
        JS);
}

/**
 * Le relevé des cartes au moment d'un échec : pour chaque carte, son
 * titre, sa classe, sa place (et si elle bouge encore 200 ms après), son
 * temps (survolée, minuterie, barre), si la souris ou le focus est
 * dessus ; le focus et la souris de la page, les requêtes en cours ; puis
 * le journal posé par poserLeReleveDesCartes() ; et, en base, l'heure du
 * serveur, les cartes déjà montrées au compte et ses notifications.
 *
 * @return Closure(): string
 */
function releveDesCartes(Navigation $onglet, User $compte): Closure
{
    return function () use ($onglet, $compte): string {
        $page = $onglet->page()->evaluate(<<<'JS'
            async () => {
                const titre = (carte) => (carte.querySelector('.fi-no-notification-title') ?? carte).textContent.trim().replace(/\s+/g, ' ').slice(0, 60);
                const place = (carte) => {
                    const rectangle = carte.getBoundingClientRect();

                    return [Math.round(rectangle.left * 10) / 10, Math.round(rectangle.top * 10) / 10, Math.round(rectangle.width), Math.round(rectangle.height)];
                };
                const cartes = [...document.querySelectorAll('.piliqi-carte')];
                const avant = cartes.map(place);
                await new Promise((fini) => setTimeout(fini, 200));
                const actif = document.activeElement;

                return JSON.stringify({
                    cartes: cartes.map((carte, rang) => {
                        const donnees = window.Alpine ? window.Alpine.$data(carte) : {};

                        return {
                            titre: titre(carte),
                            classe: carte.className,
                            dansLaPage: document.contains(carte),
                            place: avant[rang],
                            placeApres200ms: place(carte),
                            survolee: donnees.survolee ?? null,
                            minuterie: donnees.minuterie ?? null,
                            barre: carte.querySelector('.piliqi-carte__temps i')?.className ?? null,
                            sousLaSouris: carte.matches(':hover'),
                            focus: carte.contains(actif),
                            focusDuClavier: carte.querySelector(':focus-visible') !== null,
                        };
                    }),
                    pile: document.querySelector('.piliqi-cartes')?.getAttribute('style') ?? null,
                    focus: actif ? actif.tagName.toLowerCase() + '.' + String(actif.className).trim().split(/\s+/).slice(0, 2).join('.') : null,
                    souris: window.__releveDesCartes ? window.__releveDesCartes.souris : null,
                    requetes: window.__piliqi ? { enCours: window.__piliqi.enCours, lancees: window.__piliqi.lancees, finies: window.__piliqi.finies } : null,
                    avisDeFilament: [...document.querySelectorAll('.fi-no .fi-no-notification')].map((avis) => avis.textContent.trim().replace(/\s+/g, ' ').slice(0, 60)),
                    cloche: (() => {
                        const boutons = [...document.querySelectorAll('.fi-topbar-database-notifications-btn')];
                        const bouton = boutons[0];

                        if (bouton === undefined) {
                            return 'absente';
                        }

                        const rectangle = bouton.getBoundingClientRect();
                        const auCentre = document.elementFromPoint(rectangle.left + rectangle.width / 2, rectangle.top + rectangle.height / 2);

                        return {
                            nombre: boutons.length,
                            place: [Math.round(rectangle.left), Math.round(rectangle.top), Math.round(rectangle.width), Math.round(rectangle.height)],
                            auCentre: auCentre === null ? null : auCentre.tagName.toLowerCase() + '.' + String(auCentre.className).trim().split(/\s+/).slice(0, 3).join('.') + (bouton.contains(auCentre) ? ' (dans la cloche)' : ' (hors de la cloche)'),
                        };
                    })(),
                    fenetresOuvertes: [...document.querySelectorAll('.fi-modal.fi-modal-open')].map((fenetre) => (fenetre.id || fenetre.getAttribute('x-data') || '').slice(0, 60) + ' : ' + fenetre.textContent.trim().replace(/\s+/g, ' ').slice(0, 120)),
                    notificationsDeLaCloche: [...document.querySelectorAll('.fi-topbar-database-notifications-btn ~ * .fi-no-notification-title, .fi-modal .fi-no-notification-title')].map((titre) => titre.textContent.trim()),
                    journal: window.__releveDesCartes ? window.__releveDesCartes.journal : ['relevé non posé'],
                }, null, 1);
            }
            JS);
        $notifications = $compte->notifications()->reorder('created_at')->get()
            ->map(fn (DatabaseNotification $notification): string => $notification->created_at?->toIso8601String().' « '.ValeurSimple::enTexte($notification->data['title'] ?? '').' » '.($notification->read_at === null ? 'non lue' : 'lue'))
            ->implode(PHP_EOL.'  ');

        return 'Relevé des cartes : '.ValeurSimple::enTexte($page).PHP_EOL
            .'Heure du serveur : '.now()->toIso8601String().', cartes montrées jusqu\'au '.($compte->fresh()?->cartes_montrees_jusqu_au?->toIso8601String() ?? 'jamais').PHP_EOL
            .'Notifications du compte :'.PHP_EOL.'  '.$notifications;
    };
}

/**
 * Le nombre de la pastille de la cloche.
 */
function pastilleDeLaCloche(Navigation $onglet): string
{
    return (string) $onglet->page()->evaluate('() => document.querySelector(".fi-topbar-database-notifications-btn .fi-badge")?.textContent.trim() ?? ""');
}

/**
 * La fiche du devis envoyé par le vendeur de la Boutique du Port.
 */
function ficheDuDevisEnvoye(SocieteDeTest $equipe): array
{
    $devis = app(TenantContext::class)->runAs($equipe->magasin, fn (): Devis => Devis::query()->whereNotNull('numero')->firstOrFail());
    app(TenantContext::class)->clear();

    return [DevisResource::getUrl('view', ['record' => $devis], panel: 'app', tenant: $equipe->magasin), (string) $devis->numero];
}

test('le vendeur voit glisser la carte de chaque notification arrivée, la ferme, et ouvre la fiche par son bouton', function () use ($parcoursDesCartes) {
    $equipe = $this->societeComplete();
    [$fiche, $numero] = ficheDuDevisEnvoye($equipe);

    $parcoursDesCartes->call($this, $equipe->vendeur, 'app', fn (string $titre, ?string $url): Notification => new AvisDeLEquipe($equipe->societe, $titre, 'Le texte de la notification.', $url), $fiche, $numero);
});

test('la responsable voit glisser la carte de chaque notification arrivée, la ferme, et ouvre la fiche par son bouton', function () use ($parcoursDesCartes) {
    $equipe = $this->societeComplete();
    [$fiche, $numero] = ficheDuDevisEnvoye($equipe);

    $parcoursDesCartes->call($this, $equipe->responsable, 'app', fn (string $titre, ?string $url): Notification => new AvisDeLEquipe($equipe->societe, $titre, 'Le texte de la notification.', $url), $fiche, $numero);
});

test('la propriétaire voit glisser dans l’espace de la société la carte de chaque notification arrivée, la ferme, et ouvre la fiche par son bouton', function () use ($parcoursDesCartes) {
    $equipe = $this->societeComplete();
    $fiche = MembreResource::getUrl('view', ['record' => $equipe->responsable], panel: 'societe', tenant: $equipe->societe);

    $parcoursDesCartes->call($this, $equipe->proprietaire, 'societe', fn (string $titre, ?string $url): Notification => new AvisDeLEquipe($equipe->societe, $titre, 'Le texte de la notification.', $url), $fiche, 'Rose Martin');
});

test('le collaborateur voit glisser dans l’espace du cabinet la carte de chaque notification arrivée, la ferme, et ouvre la fiche par son bouton', function () use ($parcoursDesCartes) {
    $equipe = $this->societeComplete();
    $lien = LienCabinet::query()->withoutGlobalScopes()->where('cabinet_id', $equipe->cabinet->getKey())->where('societe_id', $equipe->societe->getKey())->sole();
    $fiche = ClientDuCabinetResource::getUrl('view', ['record' => $lien->uuid], panel: 'cabinet', tenant: $equipe->cabinet);

    $parcoursDesCartes->call($this, $equipe->collaborateur, 'cabinet', fn (string $titre, ?string $url): Notification => new AvisDuCabinet($equipe->cabinet, $titre, 'Le texte de la notification.', $url), $fiche, (string) $equipe->societe->siren);
});

/*
 * Une carte qui arrive à la place d'une carte fermée par sa croix glisse
 * sous la souris restée immobile sur cette croix : elle n'a pas été
 * survolée par la personne, qui n'a pas bougé, et s'en va seule au bout de
 * son temps, comme toute carte. Seule une souris qui bouge sur une carte
 * la retient (constat de la CI de 036841d, où une carte arrivée sous la
 * souris restait sans fin).
 */
test('une carte arrivée sous la souris immobile s’en va seule au bout de son temps', function () {
    $equipe = $this->societeComplete();
    $compte = $equipe->proprietaire;
    config(['piliqi.intervalle_des_notifications_en_secondes' => 1]);
    $avertir = fn (string $titre) => $compte->notify(new AvisDeLEquipe($equipe->societe, $titre, 'Le texte de la notification.', null));
    $this->travel(1)->seconds();

    $avertir('Avis déjà là avant la connexion');
    $this->travel(1)->seconds();

    $onglet = $this->seConnecter($compte, panneau: 'societe')->fermerLesAvis();
    poserLeReleveDesCartes($onglet);
    $onglet->attendreQue('document.querySelector(".piliqi-carte") !== null', 'la carte de l\'avis déjà là ne s\'affiche pas', releveDesCartes($onglet, $compte))
        ->attendreQue('document.querySelector(".piliqi-carte") === null', 'la carte de l\'avis déjà là ne s\'en va pas seule', releveDesCartes($onglet, $compte));
    $avertir('Première notification');
    $this->travel(2)->seconds();
    $onglet->attendreQue('document.querySelector(".piliqi-carte") !== null', 'la carte de la première notification ne s\'affiche pas', releveDesCartes($onglet, $compte))
        ->voir('Première notification', releveDesCartes($onglet, $compte))
        ->cliquer('.piliqi-carte .piliqi-carte__fermer')
        ->attendreQue('document.querySelector(".piliqi-carte") === null', 'la croix ne ferme pas la carte', releveDesCartes($onglet, $compte));

    $avertir('Notification arrivée sous la souris');
    $this->travel(2)->seconds();
    $onglet->attendreQue('document.querySelector(".piliqi-carte") !== null', 'la carte arrivée sous la souris ne s\'affiche pas', releveDesCartes($onglet, $compte))
        ->voir('Notification arrivée sous la souris', releveDesCartes($onglet, $compte));
    $onglet->attendreQue('document.querySelector(".piliqi-carte") === null', 'la carte arrivée sous la souris immobile ne s\'en va pas seule', releveDesCartes($onglet, $compte));
    $onglet->seDeconnecter();
});

/*
 * Le focus qu'un appui de la souris donne à la croix ne retient pas la
 * carte : un clic perdu (la carte déplacée entre l'appui et le
 * relâchement, quand une autre carte arrive au-dessus d'elle, constat de
 * la CI de 743f9bc dans l'espace du cabinet) la laissait sans fin, la
 * souris partie, le focus resté sur sa croix ; elle s'en va au bout de son
 * temps. Le clavier, lui, la retient tant qu'il est dessus, puis elle s'en
 * va à son tour.
 */
test('le focus d’un appui de la souris ne retient pas la carte, celui du clavier la retient', function () {
    $equipe = $this->societeComplete();
    $compte = $equipe->collaborateur;
    config(['piliqi.intervalle_des_notifications_en_secondes' => 1]);
    $avertir = fn (string $titre) => $compte->notify(new AvisDuCabinet($equipe->cabinet, $titre, 'Le texte de la notification.', null));
    $this->travel(1)->seconds();

    $avertir('Avis déjà là avant la connexion');
    $this->travel(1)->seconds();

    $onglet = $this->seConnecter($compte, panneau: 'cabinet')->fermerLesAvis();
    poserLeReleveDesCartes($onglet);
    $onglet->attendreQue('document.querySelector(".piliqi-carte") !== null', 'la carte de l\'avis déjà là ne s\'affiche pas', releveDesCartes($onglet, $compte))
        ->attendreQue('document.querySelector(".piliqi-carte") === null', 'la carte de l\'avis déjà là ne s\'en va pas seule', releveDesCartes($onglet, $compte));

    $avertir('Carte dont la croix a pris le focus de la souris');
    $this->travel(2)->seconds();
    $onglet->attendreQue('document.querySelector(".piliqi-carte") !== null', 'la carte ne s\'affiche pas', releveDesCartes($onglet, $compte))
        ->voir('Carte dont la croix a pris le focus de la souris', releveDesCartes($onglet, $compte));
    $onglet->appuyerPuisRelacherAilleurs('.piliqi-carte .piliqi-carte__fermer');
    expect($onglet->page()->evaluate('() => document.activeElement.matches(".piliqi-carte__fermer") && ! document.activeElement.matches(":focus-visible")'))->toBeTrue('La croix n\'a pas pris le focus de l\'appui de la souris.');
    $onglet->attendreQue('document.querySelector(".piliqi-carte") === null', 'la carte dont la croix a pris le focus de la souris ne s\'en va pas seule', releveDesCartes($onglet, $compte));

    $avertir('Carte tenue au clavier');
    $this->travel(2)->seconds();
    $onglet->attendreQue('document.querySelector(".piliqi-carte") !== null', 'la carte tenue au clavier ne s\'affiche pas', releveDesCartes($onglet, $compte))
        ->voir('Carte tenue au clavier', releveDesCartes($onglet, $compte));
    $onglet->page()->evaluate('() => document.querySelector(".piliqi-carte .piliqi-carte__fermer").focus({ focusVisible: true })');
    expect($onglet->page()->evaluate('() => document.activeElement.matches(".piliqi-carte__fermer:focus-visible")'))->toBeTrue();
    $onglet->page()->evaluate('() => new Promise((fini) => setTimeout(fini, 9000))');
    $restee = $onglet->page()->evaluate('() => document.querySelector(".piliqi-carte") !== null');
    expect($restee)->toBeTrue($restee === true ? '' : 'La carte tenue au clavier est partie avant que le clavier la quitte.'.PHP_EOL.releveDesCartes($onglet, $compte)());
    $onglet->page()->evaluate('() => document.activeElement.blur()');
    $onglet->attendreQue('document.querySelector(".piliqi-carte") === null', 'la carte quittée par le clavier ne s\'en va pas seule', releveDesCartes($onglet, $compte));
    $onglet->seDeconnecter();
});

/*
 * La croix d'une carte, la cloche, la croix de sa liste et celle de chaque
 * notification de la liste ne font aucune requête : elles restent actives
 * pendant que la page regarde s'il y a du nouveau. Le bouton de Filament
 * se désactivait pendant chaque requête de son composant, si bien qu'un
 * clic tombé pendant ce regard se perdait (constat de la CI de 8ce8bd4 :
 * la cloche ne s'ouvrait pas, ou la carte restait sous la souris) et que
 * la croix tenue au clavier perdait son focus. Les réponses du serveur
 * sont ici retenues, pour que les clics tombent pendant le regard.
 */
test('la croix d’une carte, la cloche et les croix de sa liste restent actives pendant que la page regarde les notifications', function () {
    $equipe = $this->societeComplete();
    $compte = $equipe->vendeur;
    config(['piliqi.intervalle_des_notifications_en_secondes' => 1]);

    $onglet = $this->seConnecter($compte, panneau: 'app')->fermerLesAvis();
    poserLeReleveDesCartes($onglet);
    $this->travel(1)->seconds();
    $compte->notify(new AvisDeLEquipe($equipe->societe, 'Notification arrivée pendant la visite', 'Le texte de la notification.', null));
    $this->travel(2)->seconds();
    $onglet->attendreQue('document.querySelector(".piliqi-carte") !== null', 'la carte de la notification ne s\'affiche pas', releveDesCartes($onglet, $compte))
        ->voir('Notification arrivée pendant la visite', releveDesCartes($onglet, $compte))
        ->attendreQue('document.querySelectorAll(".fi-modal .fi-no-notification-close-btn").length === 1', 'la liste de la cloche n\'a pas la notification', releveDesCartes($onglet, $compte));
    $onglet->page()->evaluate('() => document.querySelector(".piliqi-carte .piliqi-carte__fermer").focus({ focusVisible: true })');

    retenirLesReponses($onglet);
    $onglet->attendreQue('window.__regardsRetenus.has("cartes-des-notifications") && window.__regardsRetenus.has("filament.livewire.database-notifications")', 'la page ne regarde pas les notifications', releveDesCartes($onglet, $compte));
    $etat = $onglet->page()->evaluate('() => ({ croix: document.querySelector(".piliqi-carte .piliqi-carte__fermer").disabled, cloche: document.querySelector(".fi-topbar-database-notifications-btn").disabled, focusDuClavier: document.activeElement.matches(".piliqi-carte__fermer:focus-visible"), croixDeLaListe: document.querySelector(".fi-modal .fi-modal-close-btn").disabled, croixDeLaNotification: document.querySelector(".fi-modal .fi-no-notification-close-btn").disabled })');
    expect($etat)->toBe(['croix' => false, 'cloche' => false, 'focusDuClavier' => true, 'croixDeLaListe' => false, 'croixDeLaNotification' => false], 'Pendant le regard : '.ValeurSimple::enTexte(json_encode($etat)).PHP_EOL.releveDesCartes($onglet, $compte)());

    $onglet->element('.piliqi-carte .piliqi-carte__fermer')->click();
    $onglet->attendreQue('document.querySelector(".piliqi-carte") === null', 'la croix cliquée pendant le regard ne ferme pas la carte', releveDesCartes($onglet, $compte));
    $onglet->element('.fi-topbar-database-notifications-btn')->click();
    $onglet->attendreQue('document.querySelector(".fi-modal.fi-modal-open") !== null', 'la cloche cliquée pendant le regard ne s\'ouvre pas', releveDesCartes($onglet, $compte));
    $onglet->element('.fi-modal.fi-modal-open .fi-modal-close-btn')->click();
    $onglet->attendreQue('document.querySelector(".fi-modal.fi-modal-open") === null', 'la croix de la liste cliquée pendant le regard ne la ferme pas', releveDesCartes($onglet, $compte));
    expect($onglet->page()->evaluate('() => window.__regardsRetenus.size > 0'))->toBeTrue('Les clics ne sont pas tombés pendant le regard.');

    $onglet->page()->evaluate('() => window.__relacherLesReponses()');
    $onglet->attendre()
        ->cliquer('.fi-topbar-database-notifications-btn')
        ->voir('Notification arrivée pendant la visite', releveDesCartes($onglet, $compte))
        ->fermerLaFenetre()
        ->seDeconnecter();
});

/**
 * Retient dans la page chaque réponse du serveur jusqu'à
 * window.__relacherLesReponses() ; window.__regardsRetenus nomme les
 * composants de Livewire dont une requête attend sa réponse.
 */
function retenirLesReponses(Navigation $onglet): void
{
    $onglet->page()->evaluate(<<<'JS'
        () => {
            const fetchDeLaPage = window.fetch;
            let relacher;
            const relachees = new Promise((fini) => { relacher = fini; });
            const regards = new Set();
            window.__regardsRetenus = regards;
            window.__relacherLesReponses = relacher;
            window.Livewire.hook('commit', ({ component, succeed, fail }) => {
                regards.add(component.name);
                succeed(() => regards.delete(component.name));
                fail(() => regards.delete(component.name));
            });
            window.fetch = function (...arguments_) {
                return fetchDeLaPage.apply(this, arguments_).then((reponse) => relachees.then(() => reponse));
            };
        }
        JS);
}
