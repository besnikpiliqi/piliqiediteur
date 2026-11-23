# Maquette 04 : la page « TVA » (d'où vient chaque euro, le crédit et ses remboursements)

Revue le 23/11/2026 après deux relectures indépendantes (36 défauts, tous corrigés : voir le § 0).

- **Fichier** : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/maquettes/04-page-tva.html`. Une seule page, au format de publication : pas de balises `html`, `head` ni `body`, un seul `<title>` (« TVA de la société »), CSS et JavaScript en ligne, aucune ressource d'un autre site, aucune police externe, aucune adresse `http` dans le fichier. La page déclare elle-même sa langue (`lang="fr"`).
- **Copie à publier** : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/maquettes/publie/04-page-tva.html`, identique (contrôlé avec `cmp`).
- **Captures** : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/maquettes/captures/04-page-tva-*.png`, 124 images, toutes refaites le 23/11/2026, sans les notifications des gestes d'avant :
  - chaque cas (A, B, B2, C, D avec votre décision, D avec P15, E, F) en 1 280 px et en 390 px, en clair et en sombre ;
  - la période déclarée (février 2026), la chaîne après une demande et après un refus de forme, la déclaration de mai indiquée (essai), les étapes 6 et 7 (contestation) ;
  - les fenêtres (demande, refus avec la question du dépôt, fiche d'une facture d'achat, fiche d'une charge, fiche au téléphone dans l'écran large) ;
  - le refus au-dessus du crédit et l'avertissement du seuil ;
  - le cabinet lié, l'expert-comptable déclaré, l'administrateur avec un magasin non compté, le responsable (page légère, puis carte seule), le vendeur, le haut de la page ;
  - les charges à 320 px, en clair et en sombre, et avec l'espacement du texte élargi.
- **Vérification** : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/maquettes/verif/verif-04.js` (Playwright, Chromium). Sortie : `.../maquettes/verif/sortie-verif-04.txt`. Résultat : **7 994 contrôles réussis, 0 échec** (détail au § 7).
- **Sources de la page** : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/maquettes/src-04/`.
  - `donnees.py` : le jeu de données, en centimes, avec ses contrôles (dont : la TVA de la société de chaque mois est celle de la maquette d'avant, au centime ; Conseil Vasseur reste sous 4 000 € de TVA en 2026) ;
  - `modele-style.css`, `modele-corps.html`, `modele-script.js` : la page ;
  - `construire.py` : assemble la page, met les espaces insécables, contrôle le format et copie dans `publie/`.

  Pour refaire la page : `python3 -I src-04/construire.py`. Les sources d'avant la relecture sont gardées dans `verif/*-avant-relecture.*`.
- **Lu avant** :
  - `nouvelle-session/tva-page/etat-des-lieux.md` et `droit.md`. `droit.md` fait foi pour tout texte juridique de la page ; il a été complété le 23/11/2026 (R4, B5, B6, et les nouveaux faits B10 et B11, voir le § 4) ;
  - les maquettes acceptées : `carte-ecart/maquette-validee.html`, `modeles/tva-credit-report.html` (K2), `maquettes/message-tva-avant-piliqi.html` (18/11 : « le crédit de TVA se déclare pour un mois de votre choix, à partir du mois d'arrivée » ; « Franchise en base : aucune TVA affichée nulle part (ni carte, ni tableau, ni message) ») et `modeles/graphiques-tableau-de-bord.html` ;
  - en lecture seule, le code poussé : `CouleursLisibles`, les menus des trois panneaux, `EtatDeLaCharge`, `PropositionsDeTva`, `TypeDeCharge`, `Perimetre`, le semis (`SemisComplet.php` : les six magasins de Maison Ferrand, dont Fort-de-France) ;
  - le lot TVA27 (`wt-tva`, fbdf759a) : `ReportDuCreditDeTva` (la chaîne part de l'arrivée, la plus récente des déclarations sert d'ancre), `TextesDuCreditDeTva`, la carte de la TVA de `grille-des-graphiques.blade.php`, le bloc du crédit de `page-des-charges.blade.php`, `CreditsDeTva`, la ligne « Charges et TVA » de son `CLAUDE.md`, `ParametresFiscauxSociete::AIDE_DU_REGIME` ;
  - les textes officiels ajoutés le 23/11/2026 : BOI-CTX-ADM-10-20-30, § 90 à 110 (qui représente la société devant le tribunal), Conseil d'État, 14/11/2025, n° 498880 (délai de recours sans mention des voies et délais), BOI-TVA-DED-50-20-10, § 40 à 60 (montant minimal demandé), BOI-TVA-DED-30-30-40, § 50 et 210 (carburants), le formulaire CA3 2026 (cadre « néant ») et Conseil d'État, 28/12/2005, n° 263982 (Sodinel).

## 0. La relecture du 23/11/2026 : chaque défaut, et ce qui en est fait

Aucun défaut n'est écarté. Quatre sont corrigés autrement que la relecture le proposait ; la raison est dite.

| N° | Défaut relevé | Ce qui est fait |
|---|---|---|
| 1 | Le focus tombe sur la page à chaque nouveau rendu (mois, magasin, estimation, période de la chaîne, gestes, cas du pupitre). | `rendre()` retient l'élément actif (son id, ou l'attribut qui le désigne) et lui rend le focus sans défiler. Après un geste noté, le focus va à l'encadré « Recalcul » ; après le choix d'une période, au titre du résultat ; à la fermeture d'une fenêtre, au bouton qui l'a ouverte. |
| 2 | Le menu en tiroir couvre toute la hauteur de la page ; un toucher à côté ne le ferme pas ; Échap laisse le focus perdu. | Le tiroir a la hauteur de son contenu (au plus celle de la fenêtre) et défile ; un clic à côté le ferme ; Échap et un lien du tiroir le ferment et rendent le focus au bouton. |
| 3 | Au téléphone, une case recalculée déborde ; le trait orange touche le texte. | En cartes, l'ancienne valeur est sur sa ligne, une espace la sépare de la nouvelle, le trait orange devient une bordure avec sa marge. |
| 4 | Au téléphone, le tableau de la fiche cache la TVA retenue ; le titre coupe le numéro. | Le tableau de la fiche passe en cartes, une par taux ; le numéro du titre reste d'un seul tenant. |
| 5 | La page ne déclare pas sa langue. | `lang="fr"` sur `main` et sur la zone des notifications, et sur `html` quand l'enveloppe n'en met pas. L'enveloppe de vérification ne pose plus de `lang`. |
| 6 | Les valeurs barrées se lisent collées, sans dire laquelle compte. | « avant : 3 260,83 € » et « maintenant : 260,83 € » en texte masqué, une espace entre les deux, dans la chaîne et dans le calcul posé. |
| 7 | Le cabinet et l'expert-comptable lisent des phrases écrites pour le propriétaire. | Toutes les phrases ont leur variante « la société » (calcul, collectée, récupérée, états, crédit, remboursements, conditions, lecture, franchise, fenêtres). « Vous » ne désigne plus que le comptable lui-même (« vous validez ou corrigez »), comme la relecture le proposait. |
| 8 | « avec les 1 charge à valider ». | « avec la charge à valider ». |
| 9 | La carte P11 ne retombe pas sur la page (« estimation » pour la TVA validée, pas de ligne d'estimation, « déductible »). | La carte du lot TVA27, avec les mots de la page : « TVA du mois · octobre 2026 −106,90 € », « calculée avec les charges validées », « collectée … − récupérée … », « estimation avec les 2 charges à valider : −350,20 € ». |
| 10 | Le menu « Télécharger » perd le focus et ne se ferme pas d'un clic à côté. | Échap et un format choisi le ferment et rendent le focus au bouton ; un clic à côté, ou le focus qui sort, le ferment. |
| 11 | La période choisie n'est marquée que par un fond presque invisible, sans `aria-current`. | `aria-current="true"` sur son bouton, un filet de 3 px à la couleur du panneau, et la sous-ligne « période affichée ». |
| 12 | « Dont 35,20 € sans la facture jointe » se lit comme une part du non récupérable. | Dans la pastille « Comptée » : « Comptée : 3 752,96 €, dont 265,20 € sans la facture jointe » (l'achat reçu par l'API sans pièce y est compté aussi, voir 29). |
| 13 | La phrase d'une ligne n'est pas liée à sa ligne ; « Voir ses ventes » ne dit pas quel magasin. | Le bouton de chaque document est décrit (`aria-describedby`) par son état et sa phrase. « Voir ses ventes » garde son texte visible et ajoute le magasin en texte masqué (« Voir ses ventes (Maison Ferrand Halles) ») : *autrement que proposé*, parce qu'un `aria-label` remplacerait le texte visible du nom accessible (WCAG 2.5.3). |
| 14 | Le fil et les onglets du cabinet ne sont pas des liens. | Des liens, qui répondent comme le menu ; « TVA » porte `aria-current="page"`. |
| 15 | Après un refus de forme, la chaîne ne dit pas d'où vient le crédit revenu. | « dont 3 000,00 € d'un refus de forme » sous le crédit reporté de la période de retour. |
| 16 | Au pupitre, la largeur « téléphone » garde des fenêtres de 620 px. | Les fenêtres prennent 358 px, une colonne et un pied en pleine largeur dans ce mode aussi. |
| 17 | À 320 px et avec l'espacement élargi, des valeurs se chevauchent. | En cartes, les valeurs longues se coupent ; le libellé « Rembourse-ment » se coupe au trait d'union conditionnel ; le calcul posé passe sur deux lignes ; le titre des fenêtres est plus petit. |
| 18 | « Comment lire cette page » et les « Voir » font défiler sans déplacer le focus. | La page va au début de la section et le focus à son titre (ou à l'endroit montré) ; contrôlé pour les 27 boutons « Voir ». |
| 19 | Les seuils ne visaient que le crédit, pas le montant demandé. | « Au moins 760 € demandés, sur un crédit d'au moins 760 €, … ; au moins 150 € demandés pour décembre ou le 4e trimestre. » L'avertissement vise le crédit ou le montant ; essai « 500 € sur mai ». R4 de `droit.md` et § 3 bis corrigés. |
| 20 | Le point de départ était une déclaration d'avant l'arrivée, contre la règle du 18/11. | La règle de TVA27 est jouée : la chaîne part de la période d'arrivée, chaque société indique une déclaration depuis son arrivée, la plus récente sert de point de départ. Maison Ferrand arrive le 12/01/2026 et indique février 2026 (1 250,00 €), comme l'exemple de la maquette approuvée ; janvier est « sans crédit déclaré (estimation) ». Les chiffres de mars à novembre ne changent pas. D2 est réécrit. |
| 21 | Le retour d'un refus de forme tombait un mois trop tard. | La date de réception de la lettre est demandée. Reçue un 24 au plus tard (au trimestriel, dans le premier mois du trimestre), PILIQI demande si la déclaration de la période d'avant était déjà déposée, « non » proposé ; sinon le retour va à la période de la lettre ; aucune autre période n'est proposée. Étape 4 refaite (§ 1). |
| 22 | Le comptable « saisit le tribunal » ; l'état ignorait le délai complémentaire. | « La société peut saisir le tribunal administratif, elle-même ou par un avocat ; votre comptable peut vous conseiller » (fait B11). « Six mois, neuf si l'administration a annoncé un délai complémentaire. » Le délai complémentaire se note par un geste daté, *autrement que proposé* (une case) : il s'inscrit au journal d'audit et n'est accepté qu'annoncé avant la fin des six mois. |
| 23 | Le délai pour contester courait de la décision. | Date de réception et mention des voies et délais demandées ; deux mois depuis la réception si la lettre les mentionne, un an au plus sinon (fait B10). Une date n'est écrite que calculée depuis la réception (« à contester avant le … »), et « le délai … est passé » quand il l'est. B6 de `droit.md` corrigé. |
| 24 | Rien ne disait comment une demande et une déclaration indiquée se combinent. | Règle écrite (P18, § 3 bis, § 6) et jouée : une demande sur la déclaration qui sert de point de départ, ou avant elle, est déjà comptée ; seul compte le retour d'un refus de forme qui tombe après elle ; pas de plafond sur la période déclarée. Essai au pupitre. |
| 25 | Une demande d'avant PILIQI ne pouvait plus évoluer ; pas d'état après une contestation. | Une demande d'avant PILIQI garde son état et ses gestes, avec la marque « Déclaration d'avant PILIQI » (décembre 2025 : sans réponse ; son refus de forme revient en octobre 2026). États « Contesté » et « Accordé après contestation », gestes « Noter la contestation » et « Noter la décision », étapes 6 et 7. |
| 26 | « 31 décembre de la deuxième année » : une date mal comptée, trois phrases sans « après quoi ». | La date se compte depuis l'année où le refus est reçu (Sodinel) ; les trois phrases disent « qui suit celle du crédit », « … de l'omission », « … du refus ». |
| 27 | La page prêtait à PILIQI la lecture de la déclaration ; P10 allait trop loin. | « D'après PILIQI, votre crédit de mai 2026 serait de 3 260,83 € … Si votre déclaration le montre aussi … » ; conseil du seuil au conditionnel ; P10 : « peut servir de base au relevé … d'une entreprise nouvelle, en y ajoutant l'adresse de chaque fournisseur ». |
| 28 | « PILIQI vérifie » et l'état « Récupérée » promettaient trop. | « Ce que fait PILIQI », avec des verbes exacts ; les lignes comptées disent « Comptée » et « elle se récupère sur votre déclaration si la facture est conforme ». |
| 29 | Un achat reçu par l'API passait pour justifié. | « Comptée, sans pièce » : « Reçue par l'API, sans pièce jointe : gardez la facture du fournisseur ; sans elle, cette TVA ne se récupère pas. » Question 1 limitée à la plateforme agréée ; question 2 nouvelle. |
| 30 | Carburant et tickets, plus larges que le droit. | « L'essence, le gazole et le superéthanol E85 … qu'à 80 % ; pour un autre carburant, voyez votre comptable » ; « … dans l'espace prévu pour cela sur la note de restaurant (jusqu'à 150 € HT), le reçu de péage ou le ticket de parking ». |
| 31 | Le cas E montrait un trimestriel interdit en 2026. | *Autrement que proposé* : la maquette est au 22/11/2026, et 2027 y serait dans le futur. Conseil Vasseur reste au trimestriel en 2026, comme la loi le permet sous 4 000 € de TVA par an : ses chiffres sont refaits (3 232,00 € de TVA brute sur l'année, contrôlé). D9 dit que les seuils de TVA27 sont ceux de 2027 ; question 14 pour TVA27. |
| 32 | « Achats faits hors de France » au lieu de fournisseurs établis hors de France. | « TVA due sur vos achats à des fournisseurs établis hors de France (autoliquidation) » ; P13 et le § 6 citent aussi la phrase fausse de `CLAUDE.md`. |
| 33 | Le magasin de Fort-de-France du semis avait disparu ; aucune phrase outre-mer. | Le magasin est rendu (ventes à 8,5 % et 2,1 %, loyer, fournisseur local), sans changer la TVA de la société d'un centime (la facture d'ajustement de chaque mois compense sa TVA nette) ; sous la chaîne, la phrase P17. *Non repris* : les magasins Entrepôt et Éphémère du semis, qui n'ajouteraient que des lignes vides. |
| 34 | « Néant » écrit même avec un crédit reporté. | « Néant » seulement sans crédit à reporter ; sinon « déposez votre déclaration en y reportant votre crédit de … (ligne 22, puis ligne 27) : ce n'est pas une déclaration « néant » ». |
| 35 | L'estimation ne se montrait que si la TVA de la période différait. | Elle se montre quand le résultat des deux chaînes diffère (TVA à payer ou crédit à reporter), jamais pour la période déclarée (règle de TVA27). |
| 36 | P15 se disait fidèle à votre décision. | P15 est présentée comme une révision du « ni message » (question 9). Le cas D montre d'abord votre décision (pas d'entrée « TVA », aucun message) ; P15 est au pupitre. |

## 1. Ce que la maquette montre

### La page « TVA » de PILIQI

L'écran reprend l'aspect de Filament : barre du haut, menu de côté, cartes blanches. Ses couleurs sont celles des panneaux : vert dans l'espace de la société, indigo dans le panneau d'un magasin, bleu ciel dans l'espace du cabinet, avec les teintes lisibles de `CouleursLisibles`. De haut en bas :

1. **En-tête** :
   - le titre « TVA » (« TVA de la société » pour l'expert-comptable déclaré, « TVA de Maison Ferrand » avec son fil et ses onglets en liens pour le cabinet, « TVA du magasin » dans un magasin) ;
   - le régime (« Réel normal mensuel : une déclaration CA3 par mois ») et un lien vers Paramètres fiscaux pour le propriétaire ;
   - « Comment lire cette page » et « Télécharger » (PDF ou Excel) ;
   - les filtres : la période (mois ou trimestre selon le régime) et, dans l'espace de la société, le magasin.
2. **Le résultat**, en une phrase et un montant : « TVA à payer », « Crédit de TVA à reporter », « Crédit de TVA à reporter (votre déclaration) » pour la période déclarée, ou « TVA non applicable (franchise en base) ». Sous le montant :
   - « calculée avec les charges validées », puis l'estimation quand elle change le résultat ;
   - à côté, **le calcul posé** : collectée − récupérée sur les achats − récupérée sur les charges validées = TVA du mois ; − crédit reporté (ligne 22) = TVA à payer, ou crédit (ligne 25) − remboursement demandé (ligne 26) = crédit à reporter (ligne 27). Sur la période déclarée, le calcul s'arrête à la TVA du mois « calculée par PILIQI », puis donne le crédit à reporter de la déclaration ;
   - la même formule en une ligne, avec les vrais chiffres ;
   - les phrases d'une période vide (« néant » ou report), d'une période déclarée, d'une période d'avant la déclaration ;
   - toujours visible : « Estimation calculée à partir de vos documents ; ce n'est pas votre déclaration de TVA … », et ce que PILIQI ne voit pas.
3. **TVA collectée**, par taux, sans nom de client : les ventes à chaque taux (8,5 % et 2,1 % dits « taux de la Martinique »), les ventes sans TVA française avec leur preuve de sortie, les avoirs aux clients à part, l'autoliquidation des charges d'un fournisseur établi hors de France, le total.
4. **TVA récupérée** :
   - les pastilles : comptée (dont sans la facture jointe), en estimation seulement, pas encore comptées, non récupérable ;
   - une ligne par facture d'achat et par avoir fournisseur, une ligne par charge, chacune avec son état et, dessous, **la phrase qui dit pourquoi**, liée au bouton du document ;
   - les totaux, puis la somme « achats + charges ».
5. **Par magasin** (toute la société) : la TVA de chaque magasin, Fort-de-France compris, et une ligne pour les charges de « Toute la société ». Leur somme est la TVA de la société.
6. **Votre crédit de TVA** :
   - le point de départ : la déclaration indiquée la plus récente (« crédit reporté de votre déclaration de février 2026 (ligne 27) : 1 250,00 € »), son état, qui l'a saisie et vérifiée, les autres déclarations indiquées, la période d'arrivée, et le lien « Indiquer le crédit d'une autre déclaration » (le geste de TVA27) ;
   - la chaîne, période par période depuis l'arrivée : TVA de la période, crédit reporté (ligne 22, avec « dont … d'un refus de forme »), remboursement demandé (ligne 26), TVA à payer, crédit à reporter (ligne 27) ; la période déclarée dit « déclarée » et « déclaré » ; les périodes d'avant elle, « sans crédit déclaré (estimation) » ; un clic choisit une période ;
   - le choix « avec les charges validées » ou « estimation » ;
   - la phrase outre-mer (P17), le seuil de remboursement du dernier crédit (au conditionnel), le conseil à un exportateur.
7. **Remboursements de crédit de TVA** :
   - « Noter une demande de remboursement », le rappel d'un crédit au-dessus du seuil sans demande notée, l'encadré « Recalcul » après chaque geste ;
   - chaque demande avec son état, sa vérification, la marque « Déclaration d'avant PILIQI » le cas échéant, son effet sur le crédit, ses traces et ses gestes ;
   - « Comment demander un remboursement » et « Les états d'une demande ».
8. **Les conditions pour récupérer la TVA** : quatre conditions, chacune avec « Ce que fait PILIQI » et « À juger sur la pièce ».
9. **Comment lire cette page** : votre exemple 560 / 500 en mots simples, avec deux petits calculs posés.

Les sociétés sans crédit à suivre ont des pages plus courtes : la franchise en base (votre décision : pas de page ; P15 : une page sans montant), le périmètre qui ne compte pas toute la société (ni crédit ni remboursements, avec les phrases grises).

### Le pupitre de la maquette

C'est un cadre en pointillés, hors de PILIQI. Il propose :

- **le cas** :
  - A : votre exemple, 112 − 100 = 12 € ;
  - B : la même vente vers La Réunion, un crédit de 100 €, imputé en octobre ;
  - B2 : le crédit qui grossit, puis le remboursement de mai, pas à pas ;
  - C : octobre, avec des charges à valider ;
  - D : franchise en base, votre décision d'abord, P15 au choix ;
  - E : société de services, au trimestre ;
  - F : un mois sans rien et sans crédit à reporter.

  Un clic sur le cas déjà choisi le remet à son départ.
- **qui regarde** : le propriétaire, un administrateur qui voit tout, un administrateur dont un magasin n'est pas compté, le responsable d'un magasin, le cabinet lié, l'expert-comptable déclaré, un vendeur ou un observateur ;
- **la période**, **l'affichage** (comme l'appareil, clair, sombre), **la largeur** (1 280 px ou 390 px : l'écran et ses fenêtres se règlent sur leur propre largeur), **les repères** (pointillés pour mes propositions P1 à P18, traits pleins pour vos décisions D1 à D9) ;
- **le panneau d'un magasin** : page légère ou carte seule ;
- **la franchise en base** : votre décision ou P15 ;
- **les sept étapes du remboursement de mai 2026** (cas B2) ;
- **huit essais** : montant au-dessus du crédit, période en cours, période sans crédit, crédit sous le seuil, montant sous le seuil, déclaration de mai indiquée puis 3 000 € notés sur mai, société en franchise, administrateur.

En bas de la page, hors de PILIQI : la réponse courte à votre question, vos décisions, mes propositions, le droit et les questions, chacun avec un bouton « Voir ».

### Le jeu de données

Tous les montants sont en centimes, écrits une fois par `donnees.py`. La page n'additionne rien : elle affiche les lignes et les totaux écrits. Seule la chaîne du crédit se recalcule quand on note un remboursement ou qu'une déclaration est indiquée : c'est la règle de la CA3 (ligne 27 = ligne 25 − ligne 26) et celle de TVA27 (la plus récente des déclarations sert de point de départ).

| Cas | Société (fictive) | Ce qu'on voit |
|---|---|---|
| A | Comptoir des Îles, Bordeaux, mensuel, arrivée le 22/06/2026 | Août 2026 : une vente de 560,00 € HT, un achat de 500,00 € HT. **112,00 − 100,00 = 12,00 € à payer.** Point de départ : la déclaration de juin 2026 (aucun crédit), saisie le 20/07/2026, vérifiée le 23/07/2026. |
| B | Comptoir des Îles | Septembre 2026 : la vente part à La Réunion, preuve de sortie notée. **0,00 − 100,00 : crédit de 100,00 € à reporter.** Octobre : 120,00 − 100,00 = **20,00 € à payer**. Le crédit de 100,00 € est sous le seuil : la page le dit, au conditionnel. |
| B2 | Maison Ferrand : Halles, République et Marché à Paris, Fort-de-France en Martinique ; mensuel ; arrivée le 12/01/2026 | Janvier : 712,59 € de crédit, « sans crédit déclaré (estimation) ». Février : la déclaration indiquée, 1 250,00 € (PILIQI calculait −530,26 € de TVA du mois). Le crédit grossit : 2 208,51 € en mars, 2 704,81 € en avril, 3 260,83 € en mai. Sans remboursement, juin montre un crédit de 518,63 € et juillet 1 580,88 € à payer. Avec les 3 000,00 € demandés le 17/06/2026 : **juin 2 481,37 € à payer, juillet 2 099,51 €**. Deux demandes d'avant l'arrivée : novembre 2025 (2 400,00 €, remboursée) et décembre 2025 (980,00 €, sans réponse depuis plus de six mois). |
| C | Maison Ferrand | Octobre 2026 : 7 factures d'achat (dont Fort-de-France), un avoir fournisseur, un brouillon non compté, 17 charges dans tous les états. TVA du mois 992,70 € − crédit de septembre 379,62 € = **613,08 € à payer** ; **estimation 322,60 €** avec les 5 charges à valider. « Comptée : 3 752,96 €, dont 265,20 € sans la facture jointe. » |
| D | Céramiques Aubin, entreprise individuelle | Franchise en base. Votre décision : pas d'entrée « TVA », aucun message ; P15 : « TVA non applicable (franchise en base) », aucun montant. |
| E | Conseil Vasseur, services, trimestriel, arrivé le 06/04/2026 | 2e trimestre : la déclaration indiquée, 350,00 €, saisie à 320,00 € par la propriétaire et corrigée par le comptable (PILIQI calculait −297,94 €). 3e trimestre : 796,56 − 350,00 = **446,56 € à payer**, estimation **398,56 €** avec la charge à valider. 3 232,00 € de TVA brute en 2026 : sous les 4 000 € qui permettent le trimestriel cette année-là. |
| F | Comptoir des Îles | Juillet 2026 : rien, et aucun crédit à reporter : « déclaration « néant » ». Avec un crédit reporté, la même période dirait de le reporter (contrôlé). |

Les gestes rejoués et contrôlés :
- **demande** de 3 000,00 € sur mai : juin part de 260,83 € au lieu de 3 260,83 € ;
- **refus de forme** (décision du 03/08/2026, lettre reçue le 05/08/2026, déclaration de juillet pas encore déposée) : les 3 000,00 € reviennent au crédit reporté de **juillet**, qui finit avec 900,49 € de crédit ; août 750,06 € à payer ; septembre 379,62 € de crédit ; octobre 613,08 € à payer. Répondu « oui, déjà déposée » : août. Lettre reçue le 26/08 : août, sans question ;
- **remboursé en partie** (2 400,00 € accordés, le reste refusé sur le fond, lettre reçue le 16/11/2026, voies de recours mentionnées) : « à contester avant le 16/01/2027 » ; rien ne revient ;
- **refus sur le fond sans mention des voies de recours** (lettre reçue le 10/11/2026) : « à contester avant le 10/11/2027 » ;
- **refus faute de justificatifs** (lettre reçue le 10/11/2026) : nouvelle déclaration possible jusqu'au 31/12/2028 ;
- **délai complémentaire** (lettre du 02/11/2026) : la décision peut attendre jusqu'au 17/03/2027 ;
- **la demande de décembre 2025 refusée en la forme** (lettre reçue le 09/11/2026, déclaration d'octobre pas encore déposée) : ses 980,00 € reviennent en octobre 2026, qui finit avec 366,92 € de crédit ;
- **contestation puis décision** (étapes 6 et 7) : accordé après contestation, 18,40 € d'intérêts moratoires, rien ne change dans le calcul ;
- **correction par le comptable** (2 500,00 €) : juin part de 760,83 € ;
- **100,00 € notés quand même** dans le cas B : octobre paie 120,00 € ;
- **la déclaration de mai indiquée (260,83 €), puis 3 000,00 € notés sur mai** : juin part de 260,83 €, la demande est « déjà comptée », rien n'est retiré deux fois.

## 2. Ce que vous avez décidé, et où c'est dans la maquette

| N° | Décision | Dans la maquette |
|---|---|---|
| D1 | TVA collectée − TVA déductible, en centimes, depuis les documents émis ou validés et les charges. Chaque vente et chaque achat compte dans le mois de son magasin, chaque charge dans son mois de TVA. | Le calcul posé et la formule en une ligne ; les blocs « TVA collectée » et « TVA récupérée », dont les lignes retombent sur les totaux. |
| D2 | Le crédit se reporte de période en période (choix K2 du 15/11/2026). La société indique le crédit à reporter (ligne 27) d'une ou de plusieurs de ses déclarations, pour une période de son choix depuis son arrivée dans PILIQI ; la plus récente sert de point de départ, et les périodes d'avant elle restent une estimation sans crédit déclaré (maquette « Message TVA avant PILIQI », 18/11/2026). Mois ou trimestre civil selon le régime (TVA27). | Bloc « Votre crédit de TVA » : la déclaration indiquée, puis la chaîne depuis l'arrivée (Maison Ferrand : janvier sans crédit déclaré, février déclaré). |
| D3 | Les charges et leurs états ; la TVA déductible proposée (0 % véhicule de tourisme, hébergement, transport de personnes, cadeaux ; 80 % carburant d'un véhicule de tourisme) ; le mois de TVA proposé (D76) ; le comptable valide ou corrige. | Le tableau des charges, cas C et E. |
| D4 | La pièce est obligatoire pour valider une charge. | Condition 1 ; état « Pièce sans chiffres ». |
| D5 | La TVA compte les charges validées ; l'estimation ajoute les charges à valider, et se montre dès qu'elle change le résultat d'une période, jamais pour la période déclarée (TVA27). | « Estimation, avec les 5 charges à valider : TVA à payer 322,60 € » (cas C) ; « avec la charge à valider » (cas E). |
| D6 | Qui voit la TVA : comme les graphiques. Jamais un vendeur ni un observateur ; un magasin compté seulement si la personne y voit toutes les ventes et tous les achats ; les charges de « Toute la société » seulement avec tous les magasins ; le crédit seulement sur la TVA de toute la société. | L'administrateur avec un magasin non compté ; le responsable, sans crédit, sans « Toute la société », sans aucun autre magasin ; le vendeur, sans page ni menu. |
| D7 | Franchise en base : aucune TVA affichée, ni carte, ni graphique, ni tableau, ni message, pas même zéro (18/11/2026). | Cas D, d'abord : pas d'entrée « TVA », aucun message. |
| D8 | Un espace pour noter les remboursements de crédit de TVA, demandés puis reçus, et recalculer le crédit (vos messages du 22/11/2026). La décision du lot TVA27, « avec l'état de TVA, étape 5 », est révisée. | Bloc « Remboursements de crédit de TVA » ; cas B2 pas à pas. |
| D9 | Régime simplifié retiré ; CA3 mensuelle ou trimestrielle (TVA27). Les seuils de 1 000 000 € et 1 100 000 € que suit TVA27 valent à partir du 01/01/2027 ; en 2026, la CA3 trimestrielle n'est permise que sous 4 000 € de TVA par an (CGI, art. 287, 2). | Le sous-titre de la page ; le cas E, sous les 4 000 €. |

## 3. Mes propositions, avec mon conseil

Rien de ce paragraphe n'est décidé. Chaque point a son repère en pointillés dans l'écran.

1. **P1, l'entrée de menu** : « TVA » après « Charges » dans l'espace de la société ; « TVA de la société » pour l'expert-comptable déclaré ; un onglet « TVA » sur la page de chaque client du cabinet ; « TVA du magasin » dans un magasin, pour qui y voit les graphiques ; rien pour un vendeur ou un observateur, et aucun nombre dans le menu. *Conseil* : à côté de « Charges ».
2. **P2** : la page s'ouvre sur la dernière période terminée.
3. **P3** : le résultat en une phrase, puis le calcul posé, avec les numéros de lignes de la CA3 de 2026 en gris.
4. **P4** : « TVA collectée » par taux, avec à part les ventes sans TVA française, les avoirs et l'autoliquidation ; « Par magasin » pour toute la société, sans nom de client.
5. **P5, les états d'une ligne** : comptée ; pièce à joindre ; comptée, sans pièce ; TVA à vérifier ; à valider ; pièce sans chiffres ; renvoyée ; non récupérable ; comptée à 80 % ; autoliquidée ; autoliquidée, non récupérable ; sans TVA ; avoir fournisseur. *Conseil* : « comptée » plutôt que « récupérée » : PILIQI ne lit pas la pièce ; la TVA se récupère sur la déclaration si la facture est conforme.
6. **P6** : le panneau des quatre conditions, chacune avec « Ce que fait PILIQI » (une charge ne se valide pas sans sa pièce ; une facture d'achat sans pièce reste comptée, marquée ; PILIQI compare la TVA saisie à son calcul ; il propose les limites et le mois de TVA, que le comptable fixe) et « À juger sur la pièce ».
7. **P7, les remboursements** : voir le § 3 bis.
8. **P8** : « Crédit reporté de votre dernière déclaration (ligne 27) » au lieu de « crédit de départ », un terme officiel qui désigne autre chose (BOI-TVA-DED-60-40). *Conseil* : renommer partout dans le même lot.
9. **P9** : « calculée avec les charges validées » au lieu de « réelle ».
10. **P10, « Télécharger »** : PDF (A4 à l'horizontale) et Excel, sur le modèle de l'état des charges (E3), « document de travail, n'est pas une déclaration de TVA », inscrit au journal d'audit. *Conseil* : il peut servir de base au relevé des factures d'achat que demande la première demande de remboursement d'une entreprise nouvelle (fait R7), en y ajoutant l'adresse de chaque fournisseur, que la page n'a pas.
11. **P11, le panneau d'un magasin** : une page « TVA du magasin » légère, sans crédit, sans remboursements, sans charge de « Toute la société ». *Ma préférence* : la page légère plutôt que la carte seule (la carte de TVA27, avec les mots de la page, jouable au pupitre).
12. **P12** : « Comment lire cette page », avec votre exemple 560 / 500.
13. **P13** : le texte de l'autoliquidation corrigé : « sans effet sur votre solde » seulement quand elle se récupère en entier ; sinon « elle augmente votre TVA à payer » (Leasys Belgique, octobre). C'est le texte faux de `PageDesCharges.php:837`, et la même règle fausse est dans `CLAUDE.md`, ligne « Charges et TVA » : « est à la fois due et déductible, sans effet sur la TVA à payer ».
14. **P14** : le conseil à un exportateur (achats en franchise, CGI, art. 275), seulement pour une société qui vend souvent sans TVA.
15. **P15, la franchise en base** : une entrée « TVA » avec une page sans aucun montant, qui dit pourquoi. **Elle révise votre décision du 18/11 (« ni message »)** : à trancher avec la question 11 ; si vous ne la retenez pas, aucune entrée « TVA » en franchise. *Ma préférence* : l'entrée, pour expliquer ; le pupitre montre votre décision d'abord.
16. **P16, le rappel des crédits remboursables** : quand PILIQI calcule, après la déclaration indiquée, un crédit au-dessus du seuil sans demande notée, la page le dit au conditionnel (« D'après PILIQI, votre crédit de mai 2026 serait de 3 260,83 € … Si votre déclaration le montre aussi … »), avec « Noter une demande pour mai 2026 ».
17. **P17, un magasin outre-mer** : sous la chaîne, « Votre société a un magasin outre-mer : si vous déposez une déclaration par territoire, chacune a son propre crédit ; ce calcul suppose une seule déclaration », jusqu'au réglage de la question 4.
18. **P18, les remboursements et les déclarations indiquées** : voir le § 3 bis.

### 3 bis. Les remboursements (P7 et P18) : « vous faites mieux » que « déjà faite et récupérée »

**La règle qui rend la chaîne juste.** Le montant sort du crédit **le jour de la demande**, à la période dont la déclaration le porte (ligne 26), et non au virement (annexe II, art. 242-0 E ; B1, B2). La ligne 27 de cette période, reportée en ligne 22 de la suivante, est déjà nette. Un virement reçu ne change aucun calcul.

**Les remboursements et les déclarations indiquées (P18).** La ligne 27 d'une déclaration indiquée est déjà nette des demandes faites sur elle et avant elle. Donc :
- une demande notée sur la déclaration qui sert de point de départ, ou sur une période d'avant elle, ou d'avant l'arrivée, ne change pas la chaîne : elle est « déjà comptée » ;
- seul compte le retour d'un refus de forme, quand il tombe sur une période d'après la déclaration ;
- sur la période déclarée, PILIQI ne plafonne pas le montant : sa ligne 25 est la ligne 27 indiquée plus la demande.

L'essai du pupitre le montre : la déclaration de mai indiquée à 260,83 €, puis 3 000,00 € notés sur mai ; juin part toujours de 260,83 €.

**Les états** (le refus suit son motif) :

| État | Effet sur le crédit |
|---|---|
| Demandé | Le montant est sorti du crédit, en attente de la décision. |
| Sans réponse depuis plus de six mois (neuf avec un délai complémentaire noté) | Calculé par PILIQI. Le silence ne vaut pas accord ; la société peut saisir le tribunal administratif, elle-même ou par un avocat (R13, B11). Rien ne change. |
| Remboursé | Rien ne change. Si le virement est plus petit que le montant accordé (compensation, R17), la page le dit : le crédit est remboursé en entier. |
| Remboursé en partie | Le reste suit son motif. |
| Refusé, motif de forme | Le montant revient au crédit reporté (ligne 22) de la première déclaration déposée après la lettre (B4). |
| Refusé faute de justificatifs | Rien ne revient d'office : la TVA peut se déclarer de nouveau (ligne 21) jusqu'au 31 décembre de la deuxième année qui suit celle du refus (B5) ; le comptable indique alors le nouveau crédit reporté. |
| Refusé sur le fond | Perdu si la décision devient définitive. La société peut la contester devant le tribunal administratif, elle-même ou par un avocat : deux mois après la réception de la lettre si elle mentionne les voies et délais de recours, un an au plus sinon (B6, B10, B11). |
| Contesté | Le montant reste hors du crédit, en attente de la décision. |
| Accordé après contestation | Remboursé avec des intérêts moratoires (R16) ; rien ne change. |

Une demande d'avant PILIQI garde son état et ses gestes ; la marque « Déclaration d'avant PILIQI » le dit, et son montant est « déjà sorti du crédit reporté de votre déclaration de février 2026 ».

**Les gestes.**
- « Noter une demande de remboursement » : la déclaration (ligne 26, avec ce que PILIQI sait de chaque période, ou « une déclaration d'avant PILIQI »), le montant (plafonné au crédit calculé après la déclaration indiquée, proposé à ce plafond), la date de la demande n° 3519, la référence de l'accusé de réception. Un aperçu avant/après.
- « Noter la réception » : montant accordé, date, montant reçu facultatif ; quand tout n'est pas accordé, le motif du reste, la date de réception de la lettre, la question du dépôt (forme) ou la mention des voies et délais (fond).
- « Noter le refus » : date de la décision, date de réception de la lettre, motif ; pour un motif de forme, la question « Aviez-vous déjà déposé votre déclaration de juillet 2026 le 05/08/2026, quand vous avez reçu la lettre ? » (posée quand la lettre arrive un 24 au plus tard, au trimestriel dans le premier mois du trimestre ; « non » proposé) ; pour le fond, « La lettre indique-t-elle les voies et délais de recours ? ».
- « Noter un délai complémentaire » (date de la lettre, avant la fin des six mois), « Noter la contestation » (date de la requête), « Noter la décision » (accordé, avec le montant et les intérêts, ou rejeté).
- Pour le comptable : « Confirmer » et « Corriger » (motif obligatoire).

**Le recalcul sous les yeux.** Après chaque geste, l'encadré « Recalcul » (qui reçoit le focus) reprend la phrase avant/après, donne la liste des périodes changées et barre les anciennes valeurs, dites « avant » et « maintenant » au lecteur d'écran.

**Qui note.** Le propriétaire (sa demande naît « À vérifier par votre comptable ») ; le cabinet lié et l'expert-comptable déclaré qui ont « Charges : voir, ajouter, saisir et valider » (leur demande naît vérifiée). Jamais un administrateur, un responsable, un vendeur ni un observateur. Chaque geste porte un jeton de demande répétée (un double clic ne note qu'une fois, contrôlé) et s'inscrit au journal d'audit de la société.

**Les refus**, bloquants : montant au-dessus du crédit calculé (avec le conseil d'indiquer le crédit reporté de la déclaration) ; période pas encore terminée ; période sans crédit ; seconde demande sur la même déclaration ; date avant la fin de la période ou dans le futur ; lettre reçue avant la décision ; date de réception, motif ou mention des voies de recours manquants ; délai complémentaire noté après les six mois ; correction sans motif ; société en franchise ; administrateur.

**L'avertissement, sans blocage** : « Au moins 760 € demandés, sur un crédit d'au moins 760 €, pour une déclaration de janvier à novembre ou des trois premiers trimestres ; au moins 150 € demandés pour décembre ou le 4e trimestre » (R4). PILIQI avertit quand le crédit ou le montant demandé est sous le seuil, et propose « Noter quand même ». Aucun texte lu ne dit qu'une telle demande serait irrecevable en la forme.

**Ce que PILIQI ne fait pas** : il ne dépose rien et ne voit pas la déclaration. La demande se fait dans l'espace professionnel, rubrique « Effectuez une demande de remboursement de crédit de TVA », avec le montant porté ligne 26 de la CA3, au plus tard le 31 décembre de la deuxième année qui suit celle du crédit.

## 4. Les corrections à vos énoncés de droit

Elles viennent de `droit.md` (faits vérifiés le 22/11/2026, complétés le 23/11/2026) et sont reprises dans la page, section « Le droit ».

1. **« CA3 ou CA12 »** n'est juste que pour 2026, où la CA3 est mensuelle, ou trimestrielle si la TVA de l'année est inférieure à 4 000 €. La CA12 disparaît au 01/01/2027 ; la CA3 devient trimestrielle jusqu'à 1 000 000 € de chiffre d'affaires l'année précédente et 1 100 000 € l'année en cours. Sources : https://www.impots.gouv.fr/actualite/le-regime-simplifie-dimposition-la-tva-est-supprime-compter-du-1er-janvier-2027 et https://bofip.impots.gouv.fr/bofip/1001-PGP.html/identifiant=BOI-TVA-DECLA-20-20-10-10-20230118 (P1 à P4).
2. **Cas A** : juste pour des biens ; pour des services, encaissement et paiement décalent les 112 € et les 100 €. Sources : https://entreprendre.service-public.gouv.fr/vosdroits/F23569 et https://bofip.impots.gouv.fr/bofip/1531-PGP.html/identifiant=BOI-TVA-DECLA-30-20-20-30-20250108 (C5, N9).
3. **Cas B** : juste pour des biens expédiés vers un DOM, avec la preuve de sortie ; faux pour des services. Sources : https://bofip.impots.gouv.fr/bofip/341-PGP.html/identifiant=BOI-TVA-GEO-20-20230118, https://bofip.impots.gouv.fr/bofip/792-PGP.html/identifiant=BOI-TVA-GEO-20-40-20240724, https://bofip.impots.gouv.fr/bofip/2266-PGP.html/identifiant=BOI-TVA-CHAMP-30-30-10-10-20131118 (R9, R10).
4. **Les 100 € du cas B ne se remboursent pas** : il faut au moins 760 € demandés, sur un crédit d'au moins 760 €, de janvier à novembre ou les trois premiers trimestres, et au moins 150 € demandés pour décembre ou le 4e trimestre. Sources : notice CA3 2026, ligne 26, https://www.impots.gouv.fr/sites/default/files/formulaires/3310-ca3-sd/2026/3310-ca3-sd_5426.pdf, et https://bofip.impots.gouv.fr/bofip/1435-PGP.html/identifiant=BOI-TVA-DED-50-20-10-20150506, § 40 à 60 (R4).
5. **« Le même mois » n'est pas un report** : le report va de la ligne 27 à la ligne 22 de la déclaration suivante, sans limite de durée. Source : https://bofip.impots.gouv.fr/bofip/1405-PGP.html/identifiant=BOI-TVA-DED-50-10-20241009 (R1).
6. **Il n'y a pas de case à cocher** : le montant va ligne 26 de la CA3, la demande se fait à part (n° 3519), en ligne, au plus tard le 31 décembre de la deuxième année qui suit celle du crédit, sur tout ou partie du crédit. Sources : https://bofip.impots.gouv.fr/bofip/1435-PGP.html/identifiant=BOI-TVA-DED-50-20-10-20150506 (§ 70) et https://www.impots.gouv.fr/professionnel/questions/comment-faire-ma-demande-de-remboursement-de-credit-de-tva (R2, R3).
7. **« 2 à 4 semaines »** : aucun délai garanti ; six mois, neuf si l'administration annonce un délai complémentaire ; en pratique, 89,1 % en 30 jours en 2023. Sources : https://bofip.impots.gouv.fr/bofip/1375-PGP.html/identifiant=BOI-CTX-PREA-10-80-20210203 et https://www2.assemblee-nationale.fr/static/17/Annexes-DL/PLF-2025/Remboursements_degrevements.pdf (R13, R15).
8. **Le ticket et les 150 € HT** : une facture porte le nom et l'adresse du client à tout montant ; les seules tolérances sont la note de restaurant jusqu'à 150 € HT, le reçu de péage et le ticket de parking, qui portent les autres mentions et un espace prévu pour cela, où l'on écrit soi-même le nom et l'adresse de la société. Sources : https://bofip.impots.gouv.fr/bofip/140-PGP.html/identifiant=BOI-TVA-DECLA-30-20-20-10-20131018 et https://bofip.impots.gouv.fr/bofip/142-PGP.html/identifiant=BOI-TVA-DECLA-30-20-20-20-20220119 (N3 à N6).
9. **« La TVA clairement indiquée »** : elle doit aussi avoir été due. Sources : https://bofip.impots.gouv.fr/bofip/1111-PGP.html/identifiant=BOI-TVA-DED-40-10-10-20250108 (§ 60) et https://bofip.impots.gouv.fr/bofip/1079-PGP.html/identifiant=BOI-TVA-DECLA-40-10-20-20260701 (N11, N12).
10. **« Lié à l'activité »**, avec ses nuances, dont : l'essence, le gazole et le superéthanol E85 d'une voiture de tourisme ne se récupèrent qu'à 80 % ; le GPL et le gaz naturel suivent le droit commun, d'autres gaz de pétrole et le pétrole lampant 50 % (BOI-TVA-DED-30-30-40, § 50 et 210). Source : https://bofip.impots.gouv.fr/bofip/1182-PGP.html/identifiant=BOI-TVA-DED-30-10-20151007 (N13, X1 à X7).
11. **Le moment** : un bien à la livraison, un service au paiement ; une TVA oubliée se rattrape ligne 21 jusqu'au 31 décembre de la deuxième année qui suit celle de l'omission. Source : https://bofip.impots.gouv.fr/bofip/1133-PGP.html/identifiant=BOI-TVA-DED-40-20-20250108 (N8 à N10).
12. **« Déjà faite et récupérée »** : c'est la demande qui retire le montant du crédit, pas le virement. Un refus de forme fait revenir le montant sur la première déclaration déposée après la décision ; un refus faute de justificatifs laisse redéclarer avant le 31 décembre de la deuxième année qui suit celle du refus ; un refus sur le fond non contesté dans les délais (deux mois après la réception de la lettre qui mentionne les voies et délais, un an au plus sinon) fait perdre le crédit ; la société saisit le tribunal elle-même ou par un avocat, jamais par son expert-comptable. Sources : https://bofip.impots.gouv.fr/bofip/1435-PGP.html/identifiant=BOI-TVA-DED-50-20-10-20150506 (§ 80), notice CA3 ligne 22, https://www.conseil-etat.fr/fr/arianeweb/CE/decision/2005-12-28/263982, https://www.conseil-etat.fr/fr/arianeweb/CE/decision/2017-12-04/395947, https://www.conseil-etat.fr/fr/arianeweb/CE/analyse/2025-11-14/498880, https://bofip.impots.gouv.fr/bofip/553-PGP.html/identifiant=BOI-CTX-ADM-10-20-30-20120912 (B1 à B6, B10, B11).
13. **Une société qui arrive après un remboursement** indique la ligne 27 d'une déclaration, déjà nette de ses demandes. Source : https://www.impots.gouv.fr/sites/default/files/formulaires/3519-sd/2017/3519-sd_1854.pdf (B2).
14. **« Crédit de départ »** désigne autre chose dans la doctrine. Source : https://bofip.impots.gouv.fr/bofip/1417-PGP.html/identifiant=BOI-TVA-DED-60-40-20120912 (B8).
15. **Une déclaration « néant »** n'a aucune ligne remplie : une période sans opération qui reporte un crédit (lignes 22 et 27) n'en est pas une. Source : formulaire 3310-CA3-SD 2026, https://www.impots.gouv.fr/sites/default/files/formulaires/3310-ca3-sd/2026/3310-ca3-sd_5377.pdf.
16. **Un magasin outre-mer** : une seule déclaration (choix irrévocable) ou une par territoire, chacune avec son crédit. Source : notice CA3 2026 (C6).
17. **Mieux qu'un remboursement pour un exportateur** : acheter en franchise (CGI, art. 275). Source : https://bofip.impots.gouv.fr/bofip/353-PGP.html/identifiant=BOI-TVA-CHAMP-30-30-50-10-20260225 (R11).

## 5. Ce que la page ne fait pas, et le dit

**Ce n'est pas une CA3.** PILIQI ne dépose rien, ne paie rien, ne voit pas la déclaration (d'où le conditionnel du rappel et du seuil), ni les opérations faites hors de lui, ni les acquisitions taxables ; il compte les ventes de services à la date de la facture. La phrase de limite est sous le résultat dans tous les cas où il y a un montant.

**La pièce n'est pas lue.** Le panneau des conditions dit, pour chacune, ce que fait PILIQI et ce qui se juge sur la pièce ; une ligne comptée « se récupère si la facture est conforme ».

**Ce qui n'est pas montré**, parce que PILIQI ne le gère pas aujourd'hui : l'autoliquidation des achats de marchandises, le prorata de déduction, les seuils de la franchise, la régularisation d'un ticket détaxé, l'exigibilité exacte des services, les lignes de la CA3 au-delà de 22, 25, 26 et 27, une déclaration par territoire (la phrase P17 le dit). Les magasins Entrepôt et Éphémère du semis ne sont pas repris. Les numéros de lignes sont ceux de la CA3 de 2026.

## 6. Pour le lot qui fera la page

Ce sont des notes : rien de ceci n'est fait.

**Les données.**
- Une table des remboursements de crédit de TVA, données de la société (`BelongsToSociete`), à côté de `credits_de_tva` : la période (une période d'avant l'arrivée est possible), la cadence, le montant ; la date de dépôt et la référence ; l'état (demandé, remboursé, remboursé en partie, refusé, contesté, accordé après contestation ; « sans réponse » se calcule) ; le montant accordé et le montant reçu ; la date de la décision, la date de réception de la lettre, le motif, la mention des voies et délais (oui ou non), la réponse à la question du dépôt ; la période de retour et son montant ; la date du délai complémentaire ; la date et le montant de la contestation ; la date, le montant et les intérêts de la décision sur la contestation, ou la date de son rejet ; qui a noté et vérifié chaque geste, comme quoi, avec la date et le motif d'une correction.
- La chaîne de `ReportDuCreditDeTva` lit la ligne 26 et les retours de forme, avec la règle P18 : les demandes faites sur la période de l'ancre ou avant elle sont ignorées (déjà dans sa ligne 27) ; un retour de forme ne compte que sur une période d'après l'ancre ; pas de plafond sur la période de l'ancre.
- La période de retour se calcule depuis la date de réception de la lettre et la réponse à la question du dépôt (§ 3 bis) ; aucune autre n'est acceptée.

**Ce qu'il faut déclarer et corriger.**
- La lecture nouvelle à déclarer dans `CLAUDE.md:158`, dans le même commit : la liste des factures d'achat, des avoirs fournisseur et des charges de plusieurs magasins, dans l'espace de la société, du cabinet et de l'expert-comptable.
- Les textes à corriger : `TextesDuCreditDeTva::SANS_LES_REMBOURSEMENTS`, qui devient faux ; `SANS_LE_REPORT`, qui dit « tous ses magasins » dans le panneau d'un magasin ; la phrase de l'autoliquidation de `PageDesCharges.php:837` ; la même règle dans `CLAUDE.md`, ligne « Charges et TVA » (« à la fois due et déductible, sans effet sur la TVA à payer » : à remplacer par « due, et déductible selon la nature de la charge : sans effet sur la TVA à payer seulement quand elle se récupère en entier ») ; « crédit de départ », partout.
- Pour TVA27, relevé en passant : le trimestriel s'y choisit en 2026 avec le seul seuil de 1 100 000 €, alors que la loi de 2026 demande moins de 4 000 € de TVA par an (question 14) ; `ParametresFiscauxSociete::AIDE_DU_REGIME` dit « Le régime simplifié n'existe plus depuis le 1er janvier 2027 », au passé, avant cette date. Une déclaration plus récente remplace l'ancre : les périodes d'avant elle redeviennent une estimation sans crédit déclaré, même si une déclaration plus ancienne les couvrait (règle de TVA27, que l'essai de mai montre).

**Le semis** (règle 6) doit contenir, pour Maison Ferrand et une société au trimestriel :
- une demande dans chaque état, dont « contesté » et « accordé après contestation » ;
- une demande d'avant l'arrivée encore sans réponse, et une remboursée ;
- une demande à vérifier et une vérifiée ;
- un délai complémentaire noté, de part et d'autre des six mois ;
- un refus de forme qui revient sur la période d'avant (déclaration pas encore déposée) et un sur la période de la lettre (lettre reçue après le 24) ;
- un refus sur le fond avec et sans mention des voies de recours, un délai de contestation passé et un en cours ;
- un refus faute de justificatifs ;
- une demande notée sur la déclaration indiquée ;
- une demande sous le seuil notée quand même, de part et d'autre de 760 € et de 150 €, pour le crédit et pour le montant ;
- une période vide sans crédit (« néant ») et une avec un crédit reporté ;
- un magasin outre-mer (la phrase P17).

**Les tests.** La chaîne avec chaque état et la règle P18 ; la période de retour (les deux réponses, la lettre après le 24, le trimestriel) ; les délais (deux mois, un an, 31 décembre, six et neuf mois) ; le plafond et les refus ; les droits de chacun ; le jeton ; l'audit ; la règle « néant » ; la règle de l'estimation ; en navigateur, chaque geste, dans les deux modes et aux deux largeurs, avec le focus.

## 7. Vérifications faites (Playwright, Chromium)

Le script a tourné dans quatre contextes, 1 280 px à la souris et 390 px au doigt, en clair et en sombre, en `fr-FR`, puis à 320 px (clair et sombre) et avec l'espacement du texte élargi (390 px clair, 320 px sombre), dans une enveloppe sans `lang`, fidèle au squelette de publication. **7 994 contrôles réussis, aucun échec** :

- **Console et réseau** : aucune erreur, aucun avertissement, aucune requête hors de la page.
- **Langue** : `html`, `main` et la zone des notifications en français.
- **Débordement** : aucun débordement de la page ; aucun élément hors de l'écran ou de sa fenêtre ; en cartes, aucune case dont le contenu dépasse (390 px, 320 px, espacement élargi) ; à 390 px, la fiche en cartes et son numéro d'un seul tenant.
- **Contraste AA** pour tous les textes visibles, dans chaque mode, chaque fenêtre et l'affichage forcé ; bordures et fonds des contrôles à 3:1 au moins ; le filet de la période affichée.
- **Chaque cas retombe juste** : lignes et totaux des blocs, TVA récupérée = achats + charges, calcul posé, « Par magasin », résultats exacts (A 12,00 €, B 100,00 € de crédit puis 20,00 €, B2 518,63 € de crédit, C 613,08 €, E 446,56 €, F 0,00 € « néant », D sans montant), la formule du cas A mot pour mot, la période déclarée (février : 1 250,00 €) et la période d'avant elle (janvier : estimation sans crédit déclaré).
- **La chaîne, recalculée à part** par le script avec la règle de TVA27 (arrivée, ancre la plus récente) et celle des remboursements, comparée à chaque cellule, en validées et en estimation, pour chaque période des trois sociétés, chaque magasin choisi et chaque étape ; `aria-current` sur la seule période affichée.
- **L'estimation**, montrée exactement quand le résultat des deux chaînes diffère, jamais sur la période déclarée, avec le bon montant et le bon accord.
- **La règle « néant »** : sans crédit, « néant » ; avec 50,00 € reportés, la phrase du report.
- **Les gestes rejoués** (§ 1), dont : l'aperçu et le recalcul mot pour mot ; le double clic ; la question du dépôt (« non », « oui », lettre du 26) ; les délais de contestation (deux mois, un an, passé) ; le 31/12/2028 ; le délai complémentaire ; le retour de la demande d'avant PILIQI ; la décision sur la contestation ; les sept étapes ; les refus (onze messages) ; l'avertissement du seuil, pour le crédit et pour le montant ; la déclaration de mai et la demande déjà comptée ; la confirmation et la correction par le cabinet ; la demande notée par le comptable.
- **Le focus** : flèches dans « Mois », « Magasin » et « Le cas », Entrée sur « estimation », clic sur chaque période, après une demande et un refus (encadré « Recalcul »), à la fermeture des fenêtres, « Comment lire cette page », les 27 « Voir », le menu « Télécharger » (Échap, format choisi, clic à côté, focus qui sort), le tiroir (hauteur, clic à côté, Échap, lien).
- **Les textes** : « Ce que fait PILIQI » quatre fois, jamais « PILIQI vérifie » ; aucun état « Récupérée » ; l'achat reçu par l'API ; carburant et tickets ; autoliquidation ; les trois « 31 décembre » complets ; les seuils ; le tribunal saisi par la société ; aucune phrase du propriétaire chez le cabinet et l'expert-comptable (cinq pages) ; la carte du responsable ; P15 dite révision ; fil et onglets du cabinet.
- **Les données** : chaque déclaration indiquée est d'une période depuis l'arrivée ; Conseil Vasseur sous 4 000 € de TVA en 2026.

**Non vérifié** : un vrai téléphone (seule l'émulation de Chromium a servi) et un lecteur d'écran réel (l'arbre et les attributs le sont).

## 8. Questions ouvertes

Elles sont reprises en bas de la page, chacune avec mon conseil.

1. **Une facture d'achat validée sans pièce** compte toute sa TVA. Ne la compter que dans l'estimation ? *Conseil* : oui pour une facture saisie à la main ; seule une facture électronique reçue par la plateforme agréée est la facture elle-même. En octobre, 613,08 € deviendraient 648,28 € à payer.
2. **Un achat reçu par l'API sans pièce jointe** : le traiter comme une facture saisie à la main ? *Conseil* : oui ; d'ici là, « Comptée, sans pièce » (230,00 € en octobre).
3. **L'exigibilité des ventes de services** : dire la limite maintenant, la traiter à l'étape 5.
4. **Un magasin outre-mer déclaré à part** : un réglage à l'étape 5, et d'ici là la phrase P17.
5. **Un montant au-dessus du crédit calculé** : refuser, avec le conseil d'indiquer la ligne 27 ; pas de plafond sur une déclaration indiquée (P18).
6. **Sous le seuil** (crédit ou montant) : avertir sans bloquer.
7. **Le retour d'un refus de forme** : la date de réception et, quand il le faut, la question du dépôt. *Conseil* : oui.
8. **Un refus faute de justificatifs** : PILIQI ne remet pas le montant ; le comptable indique le nouveau crédit après la ligne 21.
9. **« Sans réponse »** (six mois, neuf avec un délai complémentaire noté) : une ligne dans l'« À faire » du comptable ? *Conseil* : oui.
10. **« Crédit de départ »** devient partout « crédit reporté de votre dernière déclaration » ? *Conseil* : oui.
11. **Franchise en base** : votre décision du 18/11 (aucune entrée, aucun message) ou P15, qui la révise ? *Conseil* : P15 ; sans votre accord, la décision reste.
12. **La fiche d'un fournisseur** : une case « en franchise, sans TVA », et 8,5 % proposé dans un magasin d'outre-mer ? *Conseil* : oui.
13. **Le crédit de la dernière CA12** (exercice 2026) : sur quelle CA3 de 2027 ? Aucun texte lu ne le dit ; le comptable saisit la période qu'il donne.
14. **TVA27 et le trimestriel en 2026** : ajouter la règle des 4 000 € de TVA par an jusqu'au 31/12/2026, et écrire l'aide du régime au futur jusqu'à cette date ? *Conseil* : oui.
