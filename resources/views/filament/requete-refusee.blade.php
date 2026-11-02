{{--
    Dans chaque panneau (voir AppPanelProvider, SocietePanelProvider et
    CabinetPanelProvider), pages de connexion et d'inscription comprises :
    une requête Livewire refusée parce que la page n'est plus à jour (419 :
    session expirée, autre compte connecté dans ce navigateur, nouvelle
    version du logiciel) ou n'est plus accessible au compte connecté (404,
    par exemple une page de magasin restée ouverte quand un autre compte
    s'est connecté dans un autre onglet) ne montre ni la question en anglais
    de Livewire (« This page has expired… ») ni sa page technique. À la
    place, un avis en français dit quoi faire, avec un bouton qui recharge
    la page et un autre qui ferme l'avis, sans rien recharger d'office : la
    personne peut d'abord recopier ce qu'elle a saisi. Le refus lui-même ne
    change pas, et chaque 419 s'inscrit au journal (voir
    ReponseAUnePageExpiree). Toute autre erreur garde le comportement de
    Livewire.

    Avant les scripts de Filament et de Livewire : le script s'abonne à
    « livewire:init » avant que Livewire ne démarre.
--}}
<style>
    .avis-de-requete-refusee { position: fixed; z-index: 60; inset-inline: 1rem; bottom: 1rem; margin-inline: auto; max-width: 32rem; padding: 1rem 1.25rem; border-radius: .75rem; border-inline-start: .375rem solid rgb(220 38 38); background: #fff; color: rgb(17 24 39); box-shadow: 0 10px 25px rgba(0, 0, 0, .2); font-size: .875rem; line-height: 1.4; }
    .dark .avis-de-requete-refusee { background: rgb(24 24 27); color: #fff; }
    .avis-de-requete-refusee p { margin: 0 0 .5rem; }
    .avis-de-requete-refusee .titre { font-weight: 600; font-size: 1rem; }
    .avis-de-requete-refusee .boutons { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; }
    .avis-de-requete-refusee button { cursor: pointer; border-radius: .5rem; padding: .5rem .875rem; font-weight: 600; font-size: .875rem; border: 1px solid transparent; }
    .avis-de-requete-refusee .recharger { background: rgb(79 70 229); color: #fff; }
    .avis-de-requete-refusee .fermer { background: transparent; color: inherit; border-color: rgb(209 213 219); }
</style>
<script>
    document.addEventListener('livewire:init', () => {
        const avis = @js([
            404 => [
                'titre' => 'Cette page n\'est plus accessible.',
                'texte' => 'Elle n\'existe plus, ou le compte connecté dans ce navigateur n\'y a pas accès : quand un autre compte se connecte dans un onglet, c\'est lui qui est connecté dans tous les onglets du navigateur. Rechargez la page pour continuer.',
            ],
            419 => [
                'titre' => 'Cette page n\'est plus à jour.',
                'texte' => 'Votre session a pu expirer, un autre compte a pu se connecter dans ce navigateur, ou le logiciel a pu être mis à jour. Rechargez la page pour continuer : ce que vous avez saisi sans l\'enregistrer sera perdu.',
            ],
        ]);

        const montrer = ({ titre, texte }) => {
            document.getElementById('avis-de-requete-refusee')?.remove();

            const cadre = document.createElement('div');
            cadre.id = 'avis-de-requete-refusee';
            cadre.className = 'avis-de-requete-refusee';
            cadre.setAttribute('role', 'alertdialog');
            cadre.setAttribute('aria-labelledby', 'avis-de-requete-refusee-titre');
            cadre.setAttribute('aria-describedby', 'avis-de-requete-refusee-texte');

            const enTete = document.createElement('p');
            enTete.id = 'avis-de-requete-refusee-titre';
            enTete.className = 'titre';
            enTete.textContent = titre;

            const corps = document.createElement('p');
            corps.id = 'avis-de-requete-refusee-texte';
            corps.textContent = texte;

            const recharger = document.createElement('button');
            recharger.type = 'button';
            recharger.className = 'recharger';
            recharger.textContent = 'Recharger la page';
            recharger.addEventListener('click', () => window.location.reload());

            const fermer = document.createElement('button');
            fermer.type = 'button';
            fermer.className = 'fermer';
            fermer.textContent = 'Fermer';
            fermer.addEventListener('click', () => cadre.remove());

            const boutons = document.createElement('div');
            boutons.className = 'boutons';
            boutons.append(recharger, fermer);
            cadre.append(enTete, corps, boutons);
            document.body.append(cadre);
            recharger.focus();
        };

        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                if (avis[status] === undefined) {
                    return;
                }

                preventDefault();
                montrer(avis[status]);
            });
        });
    });
</script>
