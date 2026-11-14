<?php

use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\TenantContext;
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
    $onglet->attendreQue('document.querySelector(".piliqi-carte") !== null', 'la carte de l\'avis déjà là ne s\'affiche pas')
        ->voir('Avis déjà là avant la connexion')
        ->attendreQue('document.querySelector(".piliqi-carte") === null', 'la carte de l\'avis déjà là ne s\'en va pas seule');

    $nouvelle = $avertir('Nouvelle notification de PILIQI', $fiche);
    $test->travel(2)->seconds();

    $onglet->attendreQue('document.querySelector(".piliqi-carte") !== null', 'la carte de la nouvelle notification ne s\'affiche pas')
        ->voir('Nouvelle notification de PILIQI');
    expect(placeDeLaCarte($onglet))->toBe(['sousLaBarre' => true, 'aDroite' => true, 'annoncee' => true, 'boutons' => ['Voir']]);
    expect(pastilleDeLaCloche($onglet))->toBe((string) $compte->unreadNotifications()->count());

    $onglet->cliquer('.piliqi-carte .piliqi-carte__fermer')
        ->attendreQue('document.querySelector(".piliqi-carte") === null', 'la croix ne ferme pas la carte');
    expect(DatabaseNotification::query()->findOrFail($nouvelle->getKey())->getAttributes())->toBe($nouvelle->getAttributes())
        ->and(pastilleDeLaCloche($onglet))->toBe((string) $compte->unreadNotifications()->count());
    $onglet->cliquer('.fi-topbar-database-notifications-btn')
        ->voir('Nouvelle notification de PILIQI')
        ->fermerLaFenetre();

    $aOuvrir = $avertir('Notification à ouvrir', $fiche);
    $test->travel(2)->seconds();
    $onglet->attendreQue('document.querySelector(".piliqi-carte") !== null', 'la carte de la notification à ouvrir ne s\'affiche pas')
        ->voir('Notification à ouvrir')
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
