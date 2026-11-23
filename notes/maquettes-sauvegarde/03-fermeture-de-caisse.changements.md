# Maquette 03 : « Fermer ma caisse » et « Fermer la caisse d'un vendeur », bouton grisé quand la caisse n'est pas ouverte

Fichier : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/maquettes/03-fermeture-de-caisse.html` (une seule page, CSS et JavaScript en ligne, aucune ressource d'un autre site, aucune police externe, aucune adresse `http` dans le fichier).
Captures : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/maquettes/captures/03-fermeture-de-caisse-*.png` (40 images : 1 280 px et 390 px, clair et sombre : page entière, deux boutons grisés, info-bulle de chaque bouton, fenêtre « Fermer ma caisse », fenêtre « Fermer la caisse d'un vendeur », après une fermeture, demande forgée, propriétaire, « Voir » avec l'info-bulle).
Vérification : `.../maquettes/verif/verif-03.js` (Playwright), sortie complète : `.../maquettes/verif/sortie-verif-03.txt`.

Sources relues : `semis-complet-plan-17-11.md` (lignes 170 à 190, dont le « À appliquer (lot « écrans », maquette à montrer avant) » de la ligne 185), `etat-chaines.md` (535 à 560), `resumes-de-session.md` (sections « Fermeture de caisse » et « All user messages », messages de l'éditeur du 18/11), et, en lecture seule, `ListClotureCaissePersonnelles`, `ClotureCaissePersonnelleResource`, `FermerSaCaisseAction`, `VerificateurCaissesPersonnelles`, `ClotureCaissePersonnellePolicy`, `CaissesPersonnellesOuvertesApercu`, `encaisse-par-mode.blade.php` et `ChosesAFaireDuMagasin::maCaisseOuverte()`, pour que l'écran de la maquette soit celui d'aujourd'hui (colonnes, textes, fenêtres).

## 1. Ce que la maquette montre

La page « Ma caisse » ou « Les caisses » du panneau d'un magasin (Maison Ferrand République ; équipe fictive : Hugo Lefebvre responsable, Sarah Petit vendeuse, Karim Haddad vendeur, Élise Ferrand propriétaire ; « aujourd'hui » est le dimanche 22/11/2026) :

- le titre (« Ma caisse » pour une personne qui tient une caisse sans voir les relevés de toute l'équipe, « Les caisses » pour les autres) et, à droite, les boutons « Fermer ma caisse » et « Fermer la caisse d'un vendeur », chacun présent selon la personne (voir § 2, n° 11) ;
- pour « Les caisses », l'encart « Caisses personnelles ouvertes » d'aujourd'hui, qui suit l'état des caisses ;
- le tableau des relevés de caisse d'aujourd'hui (Vendeur, Depuis, Jusqu'à, Fermeture, Ventes, TTC vendu, Encaissé par mode, Crédit d'avoir, Reste dû), avec neuf relevés de départ, dont aucun à zéro (toute fermeture exige une vente ou un encaissement), deux « Fermée automatiquement », un « Fermée par Hugo Lefebvre » et Karim Haddad qui a fermé deux fois dimanche, avec des ventes entre les deux (11 h 55, puis 13 h 30) ; « Ma caisse » n'y montre que les relevés de la personne ;
- la fenêtre « Fermer ma caisse » (le relevé avant de fermer, le texte d'aujourd'hui, « Confirmer » et « Annuler ») et la fenêtre « Fermer la caisse d'un vendeur » (le menu « Vendeur », le relevé du vendeur choisi, « Fermer sa caisse ») ;
- l'alerte du refus serveur, la notification « Caisse de Sarah Petit fermée ».

Au-dessus de l'écran, un « pupitre de la maquette » (cadre en pointillés, rien de cela n'est dans PILIQI) : qui regarde (Hugo Lefebvre responsable, Sarah Petit, Karim Haddad, Élise Ferrand propriétaire) ; pour chaque vendeur, les opérations non comptées par une fermeture, avec trois boutons pour en ajouter (« + une vente », « + un encaissement », « + une contre-passation ») ; « Aucune caisse ouverte » et « Revenir à l'état de départ » ; les demandes que le serveur reçoit sans passer par un bouton (fermer ma caisse, fermer la caisse d'un vendeur, double clic sur « Confirmer », fermeture d'office) ; et « Ce que le serveur a répondu » (le journal de ces demandes, avec le jeton raccourci). Le menu « Affichage de la page » (clair, sombre, comme l'appareil) est un confort de la maquette, comme dans les maquettes 01 et 02.

Tout fonctionne : chaque vente, encaissement ou contre-passation ajouté ouvre la caisse de la personne (le relevé de la fenêtre le compte) ; une fermeture l'écrit en tête du tableau, remet ses opérations à zéro et regrise le bouton jusqu'à l'opération suivante ; l'encart des caisses ouvertes, la liste du menu « Vendeur » et l'état des boutons se recalculent à chaque geste.

## 2. Ce que l'éditeur a décidé, et où c'est dans la maquette

Mots de l'éditeur (18/11/2026, nuit) : « tant qu'il n as pas vendu dans la caisse alors pourquoi afficher fermer la caisse? donc ma decision est de bloquer la fermeture tant qu'il n y a pas de ventes, et si ferme apres une vente alors il faut bloquer le button apres quand il ferme la caisse. donc la caisse s ouvre une fois vendu », puis « oui button grisé avec message en tooltip il y pas de vente pour ferma la caisse », puis « tu mets commme ca "Aucune vente à fermer" mais tu ajuste par rapport ce que fais la caisse donc vente , encaissement etc. donc petit phrase comprhensible ».

| N° | Décision | Dans la maquette |
|---|---|---|
| 1 | Une caisse personnelle est OUVERTE seulement quand elle a au moins une vente émise, un encaissement ou une contre-passation non comptés ; « la caisse s'ouvre une fois vendu » | Pupitre, « La caisse de chacun » : l'état dit « Caisse pas ouverte : rien de non compté » ou « Caisse ouverte : 2 ventes, 1 encaissement non comptés ». Les trois boutons « + » ouvrent chacun la caisse (contrôlé une sorte après l'autre). Boutons « Voir : pas encore de vente » et « Voir : après une vente » |
| 2 | « Fermer ma caisse » reste VISIBLE mais GRISÉ quand la caisse n'est pas ouverte, avec l'info-bulle « Aucune vente ni encaissement à fermer. » | Bouton « Voir : bouton grisé et info-bulle » : Sarah Petit sans opération. Le bouton garde son texte et son icône, le fond est gris, l'info-bulle s'ouvre. Un clic sur le bouton grisé n'ouvre rien |
| 3 | Après chaque fermeture le bouton se grise de nouveau jusqu'à la vente suivante ; plusieurs fermetures par jour sont possibles (cas des marchés : ouvre, vend, ferme, revient, vend, ferme) | « Voir : une caisse à fermer, puis fermez-la » : fermez, le bouton se grise et le relevé paraît en tête ; « + une vente » du pupitre le rend actif, et une seconde fermeture est possible (contrôlé). Karim Haddad a deux relevés dimanche dans le tableau |
| 4 | « Fermer la caisse d'un vendeur » ne propose que les vendeurs dont la caisse est ouverte | « Voir : la fenêtre, avec un seul vendeur proposé » : Sarah Petit a une caisse ouverte, Karim Haddad non ; le menu ne propose que Sarah Petit. Pour le propriétaire (« Voir : propriétaire », puis une vente chez Hugo et chez Sarah) : les deux caisses ouvertes |
| 5 | Grisé avec « Aucune caisse de vendeur à fermer : aucune vente ni encaissement. » quand aucun vendeur n'a de caisse ouverte | « Voir : responsable, aucune caisse ouverte » et « Voir : propriétaire » : les deux boutons du responsable sont grisés, celui du propriétaire aussi ; chacun a son info-bulle |
| 6 | Refus serveur d'une demande forgée : « Rien à fermer : aucune vente ni encaissement depuis votre dernière fermeture. » | « Voir : demande forgée » (ou « Fermer ma caisse (sans le bouton) » du pupitre) : alerte rouge (`role="alert"`) avec ce texte, aucun relevé écrit, ligne « refusée » dans « Ce que le serveur a répondu » |
| 7 | Double clic = relevé déjà fermé (jeton) | « Voir : double clic » (ou « Double clic sur « Confirmer » » du pupitre, ou un vrai double clic sur « Confirmer » dans la fenêtre) : un seul relevé est écrit ; le journal dit « le relevé déjà fermé est rendu, aucun nouveau relevé ». La fenêtre reste 0,4 s après le premier clic, pour que le second clic ait lieu |
| 8 | Fermeture d'office inchangée | « Voir : fermeture d'office » : les caisses ouvertes (Sarah, Karim) donnent un relevé « Fermée automatiquement » ; Hugo, qui n'avait rien à compter, n'a aucun relevé (« rien à compter : aucun relevé écrit ») ; l'encart dit « Toutes les caisses personnelles sont fermées. » |
| 9 | L'info-bulle est annoncée aux lecteurs d'écran et lisible en clair et en sombre | Le bouton garde son texte (nom accessible « Fermer ma caisse ») ; `aria-disabled="true"` et `aria-describedby` vers le message, présent dans la page (contrôlé : la description est exactement le texte décidé). « Voir en clair » et « Voir en sombre » règlent l'affichage et ouvrent l'info-bulle ; contrastes au § 4 |
| 10 | La phrase grise sous le bouton n'est plus demandée | Absente (contrôlé : aucune phrase « … votre caisse s'ouvre à votre prochaine vente » dans l'écran) |
| 11 | (Déjà établi, repris tel quel) Qui a quel bouton | Vendeuse : « Ma caisse », un seul bouton, « Fermer ma caisse ». Responsable : « Les caisses », les deux boutons. Propriétaire : « Les caisses », seulement « Fermer la caisse d'un vendeur » (il ne vend jamais, donc pas de caisse : voir la question 3) |

## 3. Inchangé, montré seulement pour que l'écran soit complet

Rien de ceci n'est une décision de ce lot ; tout existe aujourd'hui dans le code relu :

- les colonnes du tableau et la cellule « Encaissé » par mode (ESP, CB, CHQ, VIR, PRLV, « Total » en gras quand il y a plusieurs modes) ;
- l'encart « Caisses personnelles ouvertes » et ses deux phrases (« Fermées automatiquement à la clôture de la journée : … » ; « Toutes les caisses personnelles sont fermées. ») ;
- le texte des deux fenêtres (le relevé en quatre rubriques et le paragraphe « Ce relevé compte vos ventes et vos paiements qu'aucune fermeture n'a encore comptés… Ceci ne clôture pas la journée du magasin. ») ; pour un vendeur, « ses » à la place de « vos » ;
- la notification « Caisse de Sarah Petit fermée » (fermeture pour un vendeur) ; fermer sa propre caisse n'affiche aucune notification aujourd'hui, seul le relevé paraît dans la liste ;
- le message « La caisse de ce vendeur n'est plus ouverte. » (alerte orange) quand le vendeur choisi n'a plus de caisse ouverte (voir la question 1) ;
- « Le champ vendeur est obligatoire. » quand on valide la fenêtre sans choisir ;
- la ligne de l'« À faire » « Votre caisse est ouverte : fermez-la à la fin de votre service. » (bouton « Fermer ma caisse ») : elle n'existe déjà que lorsque la caisse est ouverte (`ChosesAFaireDuMagasin::maCaisseOuverte()`), donc elle est cohérente avec la règle sans rien changer ; elle n'est pas dessinée ici ;
- le cas d'un responsable qui clôture la journée sans voir les relevés de toute l'équipe (« Son relevé ne vous sera pas montré… ») : non dessiné.

## 4. Vérifications faites (Playwright, Chromium)

Quatre contextes : 1 280 px (souris) et 390 px (écran tactile), en clair et en sombre ; 180 contrôles réussis, aucun échec :

- aucune erreur ni avertissement de console, aucune requête hors de la page ;
- aucun débordement horizontal de la page, aucun élément de l'écran qui sorte de son cadre (le tableau défile de côté dans son cadre sur téléphone), pour la page d'abord, puis avec chaque fenêtre ouverte ;
- contraste AA de tous les textes visibles (233 à 234 textes par état, aucun sous 4,5:1, ou 3:1 pour un grand texte), dans les deux modes, avec les boutons grisés, avec chaque fenêtre ouverte (le fond est alors celui de la fenêtre) et avec l'info-bulle ouverte ; bordures et fonds de boutons et de menus : 3:1 au moins (minimum constaté 3,5:1 en clair, 3,9:1 en sombre, 4,83:1 et 5,46:1 dans les fenêtres) ; le bouton grisé garde son texte à 6,1:1 sur son fond (clair) ;
- info-bulle : texte sur fond 16,97:1 en clair et 20,12:1 en sombre ; bord de l'info-bulle sur la carte 17,72:1 en clair et 5,46:1 en sombre (le fond noir ne se distingue pas du fond sombre de la carte, le bord la dessine, comme dans la maquette 02) ; l'info-bulle reste dans l'écran à 1 280 px et à 390 px ;
- souris : survol de chaque bouton grisé (info-bulle décidée), fermeture quand la souris part ; clavier : le bouton grisé reçoit le focus avec Tab et l'info-bulle s'ouvre, Échap la ferme ; doigt : un appui ouvre l'info-bulle, un second appui sur le même bouton ou un appui ailleurs la ferme, un appui sur l'autre bouton grisé la déplace ;
- toutes les règles du § 2 jouées jusqu'au bout : les trois sortes d'opération ouvrent la caisse, une fermeture regrise le bouton, une seconde fermeture après une nouvelle vente, la liste du menu « Vendeur », le refus forgé (alerte, aucun relevé), le double clic simulé et le vrai double clic (un seul relevé de plus), la fermeture d'office, le propriétaire sans « Fermer ma caisse », « Ma caisse » qui ne montre que les relevés de la personne.

Non vérifié : un vrai téléphone ou une vraie tablette (l'émulation tactile de Chromium est la seule preuve) ; un lecteur d'écran réel (on a contrôlé le nom accessible et la description du bouton, pas la voix).

## 5. Choix de présentation de Claude (nécessaires pour montrer la règle, pas des décisions de l'éditeur)

- Le bouton grisé : fond gris, texte et bordure plus sombres que le gris (contraste vérifié), curseur « interdit » ; il est marqué `aria-disabled="true"` et non `disabled`, pour rester atteignable au clavier et laisser lire sa description. À la réalisation, l'action « Transformer en facture » du devis grise déjà un bouton avec `->disabled()->tooltip()` : c'est le modèle à suivre. Je n'ai pas vérifié ce que Filament écrit dans la page pour un bouton désactivé ; si c'est l'attribut `disabled`, une info-bulle dessus ne s'ouvre pas toujours au survol selon le navigateur, et jamais au clavier ni au doigt. Le test en navigateur du lot doit donc contrôler l'info-bulle de chaque bouton grisé (souris, clavier, doigt) et, si elle ne s'ouvre pas, passer par `aria-disabled` comme ici.
- L'info-bulle : sombre dans les deux modes, avec un bord, au-dessus du bouton (en dessous s'il n'y a pas de place), jamais hors de l'écran ; c'est celle de la maquette 02, que l'éditeur a vue.
- Les relevés de départ, leurs montants et leurs dates, l'équipe, le magasin (« Maison Ferrand République », nom du semis) : fictifs, vraisemblables.
- Les fenêtres sont celles de Filament en plus simple (fenêtre native du navigateur) ; les notifications arrivent en haut à droite, comme celles de Filament, 9 secondes au plus.
- La page montre la fermeture à 1 280 px et à 390 px : sur téléphone, les deux boutons passent l'un sous l'autre, en pleine largeur.

## 6. Pour le lot « écrans » (non montré, à reprendre)

- Code : `ListClotureCaissePersonnelles` (les deux actions d'en-tête : grisées au lieu de cachées, avec `->tooltip()`), `FermerSaCaisseAction` (refus de « Rien à fermer… » quand rien n'est à compter ; son docblock dit aujourd'hui « Aucune condition de blocage… relevé à zéro » et l'appel passe `memeAZero: true` : à réécrire), `VerificateurCaissesPersonnelles` (la définition d'une caisse ouverte est déjà celle de la décision : une vente émise ou un paiement non compté, contre-passations comprises ; `aSaCaisseOuverte()` et `vendeursAvecCaisseOuverte()`).
- Tests : ceux qui attendent la fermeture à zéro sont réécrits d'après la décision (jamais affaiblis) ; un test en navigateur par bouton grisé (info-bulle, nom accessible, description), clair et sombre, ordinateur et téléphone.
- Semis (règle 6) : au moins une caisse personnelle ouverte et une qui ne l'est pas, de part et d'autre de la règle, dans chaque magasin où des comptes vendent ; au moins un relevé fermé deux fois dans la même journée avec des ventes entre les deux (Karim Haddad dans la maquette) ; aucun relevé à zéro.

## 7. Propositions de Claude (mes idées, NON décidées, absentes de la maquette)

1. **Dire dans l'info-bulle quand la caisse s'est fermée.** « Aucune vente ni encaissement à fermer. Dernière fermeture à 13 h 30. » aide à comprendre un bouton qui vient de se griser. Cela change le texte que vous avez fixé : à vous de dire.
2. **Une confirmation après « Fermer ma caisse ».** Fermer la caisse d'un vendeur affiche « Caisse de Sarah Petit fermée » ; fermer la sienne n'affiche rien, seul le relevé paraît en tête de la liste. Proposition : « Votre caisse est fermée. » (notification verte). Non montrée dans la maquette.

## 8. Questions ouvertes (reprises en bas de la page)

1. **Demande forgée pour la caisse d'un autre vendeur.** Le message décidé parle de « votre dernière fermeture » : il convient à sa propre caisse. Pour la caisse d'un vendeur qui n'a rien à fermer, la maquette garde le message déjà en place, « La caisse de ce vendeur n'est plus ouverte. » (bouton « Envoyer » du pupitre, avec Karim Haddad). Le garder, ou « Rien à fermer : aucune vente ni encaissement depuis sa dernière fermeture. » ?
2. **Info-bulle au clavier et au doigt.** Vous avez décidé une info-bulle annoncée aux lecteurs d'écran ; la maquette l'ouvre aussi à la prise de focus au clavier et à l'appui du doigt (sans cela, elle n'existe pas sur téléphone). À confirmer.
3. **Le propriétaire.** Il n'a pas de bouton « Fermer ma caisse » (il ne vend jamais, comme aujourd'hui) : le bouton n'est pas grisé, il n'existe pas pour lui. À confirmer.
4. **Double clic.** La seconde demande rend le relevé déjà fermé, sans message (comme aujourd'hui). Un message est-il voulu ?
5. **Une caisse restée ouverte la veille.** Les opérations non comptées d'un jour précédent gardent la caisse ouverte (le relevé les compte « y compris ceux d'un jour précédent », comme aujourd'hui) jusqu'à la fermeture d'office de minuit. Rien à changer ?
