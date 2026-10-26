# Plan d'exécution : moteur de TVA centralisé, espace « Charges du magasin » et compléments

Rédigé le 11/10/2026, après les réponses de l'éditeur à la série 7, ses décisions sur l'espace « Charges du magasin » et le lot de compléments 1 à 16. Révisé le 12/10/2026 après trois critiques adversariales : 42 défauts vérifiés dans les documents de décision, le code de l'instantané et la copie de travail ; 36 retenus tels quels, 5 retenus avec une correction adaptée, 1 retenu en partie ; leur traitement est en annexe D. Ce plan est donné tel quel à une chaîne d'agents d'implémentation : un agent par lot, deux relecteurs adversariaux, correction, poussée. Il se lit seul : chaque lot s'exécute en lisant ce plan, `CLAUDE.md` et le code du moment. Les documents de travail cités en 0.2 ne sont que l'historique des décisions ; aucun lot n'a besoin de les ouvrir.

---

## 0. Lire ce plan

### 0.1 Objet

Le plan livre, dans l'ordre de 5.0 :
- le **moteur de TVA centralisé** : un seul moteur, dans un domaine `app/Domain/Tva`, décide de la TVA et calcule tous les montants des ventes et des achats ; les écrans, la caisse, l'API, le stock et, plus tard, les déclarations l'appellent tous ;
- l'**espace « Charges du magasin »** : charges (factures de fournisseurs dont les lignes n'entrent pas en stock), pièces justificatives, charges communes de la société réparties entre ses magasins, salaires en totaux mensuels, totaux par mois et par catégorie ; et, si l'éditeur les retient (6.1), les charges récurrentes (lot CH3) et les avoirs reçus sur des charges (lot CH6) ;
- les **compléments 1 à 16** déjà décidés (lots C-a et C-b, et textes de `CLAUDE.md`) ;
- la **fin de l'étape 1** de la feuille de route : elle n'est cochée qu'après le dernier lot de ce plan.

Il ne livre ni l'étape 2 (SUPER PDP), ni l'étape 5 (comptabilité), ni l'étape 7 (inventaire) : il prépare ce qu'elles liront, sans rien créer pour elles avant l'heure.

Quelques règles de calcul et de périmètre restent soumises à l'éditeur (6.1 c à g) : un lot que concerne une règle de calcul attend sa réponse (portes de 6.0) ; pour une question de périmètre, le code applique en attendant la valeur sûre que 6.1 décrit ; les textes de `CLAUDE.md` qui en dépendent ne s'écrivent qu'avec la réponse (2.0).

### 0.2 Décisions sur lesquelles il repose

- Réponses de l'éditeur à la série 7 (questions TVA1 à TVA16 et corrections O1 à O8), du 11/10/2026 : toutes les recommandations, sauf **TVA9 = c** (« ne jamais vendre de cartes cadeaux dans PILIQI »). Section 1.
- Demande de l'éditeur d'un espace « Charges du magasin » et ses trois décisions (charges communes saisies au niveau de la société et réparties ; salaires en totaux mensuels par magasin, visibles du propriétaire, des administrateurs autorisés et des experts-comptables seulement ; construit avec les achats du moteur de TVA, pièces justificatives de l'étape 8 avancées). Les charges récurrentes n'étaient qu'un avis de Claude : elles sont soumises à l'éditeur (6.1 f). Sections 1.4 et 4.
- Compléments 1 à 16 du 11/10/2026. Section 1.5.
- Organisation de l'équipe de la série 6, telle que les lots A4 et A4b de la chaîne société la livrent : propriétaire sans autorisation demandée ; administrateurs aux autorisations cochées par le propriétaire ; plusieurs responsables par magasin, avec tous les droits du magasin sans case ; vendeurs et observateurs aux cases cochées ; plusieurs experts-comptables en lecture seule sur tous les magasins réels. Toute autorisation nouvelle de ce plan s'y conforme (3.20, 4.9).
- Décisions antérieures écrites dans `CLAUDE.md` (R1 à R5, B1, B2, B10, C1, C2, E1, E5 à E7, E13, E14, I1 à I4, K1 à K5) : inchangées, sauf là où ce plan le dit.
- Questions encore ouvertes (6.1 a à g) et points de l'expert-comptable (6.2) : pour chacun, la recommandation et ce que fait le code en attendant ; un lot que la réponse conditionne ne commence pas sans elle (6.0).

### 0.3 Règles communes à chaque lot

1. **Avant d'écrire** : lire `.ai/rules/index.md` s'il existe à ce moment-là, puis chaque règle dont les chemins couvrent les fichiers du lot, et lancer `grep -rin '<mot-clé>' .ai/rules` ; lire le skill `testing-best-practices` avant d'écrire des tests ; relire chaque fichier avant de le modifier.
2. **La chaîne société a changé le code après la rédaction de ce plan.** Les numéros de ligne cités viennent de l'instantané du commit 9c9ee25 et ne sont que des repères. Quand une classe, une méthode ou une colonne a été renommée par les lots A4 à A7 ou le lot 21 (par exemple les actions qui reçoivent désormais `User $auteur`, `LecteurDesDroits`, `OperateurCourant`, les colonnes de nom figé `*_nom`, `ActionsUniques`, `DerogationsDePrix`, le prix de référence des services), l'agent suit le code, applique l'intention du plan, et le dit dans le message de commit.
3. **Schéma** : une seule migration de création par table, modifiée sur place, puis `php artisan migrate:fresh` ; aucune migration `add_x_to_y`, aucun renommage par migration, aucune reprise de données, aucun code, cast, commentaire ni test de compatibilité. Une table nouvelle reçoit un nom de fichier daté qui la place après les tables qu'elle référence.
4. **Isolation** : tout modèle d'un magasin utilise `BelongsToMagasin`, tout modèle propre à une société `BelongsToSociete` ; toute lecture ou écriture hors du magasin courant est explicite, filtrée par société, limitée aux champs nécessaires, écrite dans une seule classe, testée par un test nommé, et figure dans la liste de la puce 2 de « Produit » de `CLAUDE.md` (textes de 2.2) ; toute règle `exists` passe par `regleExisteDansLeMagasin()` ou `regleExisteDansLaSociete()` ; aucune unicité nouvelle n'est globale. Chaque nouveau modèle s'ajoute à `tests/Feature/Tenancy/TenantIsolationTest.php` (modèles de magasin) ou à `tests/Feature/Tenancy/IsolationDesSocietesTest.php` (modèles de société).
5. **Auteur, droits, verrous** (lot A4) : toute action qu'une personne déclenche reçoit `User $auteur`, relit l'auteur en base par `LecteurDesDroits` (`exiger`, `exigerSousVerrou`, `exigerDansLaSociete`) et écrit sous `OperateurCourant::pendant($compteRelu, …)` ; une opération du système passe par une entrée nommée et `OperateurCourant::pendantLeSysteme()`. L'ordre des verrous est celui du lot A4 : registres par identifiant croissant, ligne `societes`, lignes `users`, lignes du document, produits par identifiant croissant, `sequences_documents`, têtes du journal d'audit. Un verrou que ce plan ajoute sur la ligne `societes` sans en modifier la clé se prend en `FOR NO KEY UPDATE` (`lock('for no key update')`) : toute inscription au journal d'audit vérifie sa clé étrangère vers cette ligne (`FOR KEY SHARE`), qu'un `FOR UPDATE` bloquerait. Une écriture de la société dans des magasins (parts d'une charge commune, salaires) prend les registres des magasins touchés par `RegistreFiscal::sousVerrouDesMagasins()`, puis la ligne de l'auteur par `LecteurDesDroits::exigerDansLaSocieteSousVerrou()` (créée au lot CH4, `FOR NO KEY UPDATE`), puis la ligne de la charge commune ou des salaires, et relit sous ces verrous ce qu'elle contrôle (4.7, 4.8). Chaque table d'historique qui désigne une personne porte la colonne sœur `<colonne>_nom`, posée par le trait `FigeLeNomDeSesAuteurs`.
6. **Demandes répétées** (lot A6) : chaque action de la liste de `CLAUDE.md` passe par `ActionsUniques::executer` ; seule l'action la plus externe consomme le jeton. Les gestes de la société, que les jetons rangés par magasin ne couvrent pas (validation et correction d'une charge commune, saisie des salaires), sont protégés par leur état et le verrou de leur ligne : une demande répétée rend le résultat déjà enregistré, sans rien réécrire ni inscrire (2.10, 4.7, 4.8). Les gestes qui ne sont pas dans la liste sont protégés par leur état ou une contrainte d'unicité, chacun avec un test de double soumission.
7. **Calculs** : tous les montants passent par le moteur et la calculatrice déplacée (`App\Domain\Tva\Calcul\CalculateurLigne`), en entiers (centimes, centièmes de point, dix-millièmes d'euro pour un coût) ; aucune somme `SUM()` SQL sur une colonne décimale (SQLite la range en virgule flottante) : les totaux se cumulent côté PHP, comme `ChiffreDAffaires`. Aucune règle d'arrondi nouvelle sans décision de l'éditeur (protocole, règle 4). Décidées : R4, K2, K3, et TVA13 pour la TVA autoliquidée et ses retours. Soumises à l'éditeur, appliquées seulement selon sa réponse, chaque lot concerné l'attendant (6.0) : la TVA facturée d'un achat et ses retours (6.1 c), la répartition des frais et des droits, la conversion des devises, la TVA déductible partielle et la répartition des charges communes (6.1 d).
8. **Pendant le travail** : tests ciblés (`php artisan test --compact <fichier>`). **Avant chaque commit** : `php artisan migrate:fresh`, puis la suite complète sur SQLite (`php artisan test --parallel`), puis sur PostgreSQL (`DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=piliqi_test DB_USERNAME=piliqi DB_PASSWORD="$PILIQI_PG_MOT_DE_PASSE" php artisan test --parallel`), chacune suivie de `echo $?`, qui doit valoir 0 ; puis `vendor/bin/pint --dirty --format agent`.
9. **Tests existants** : aucun n'est supprimé, affaibli ni réécrit pour passer. Un test change seulement quand la règle qu'il fixe change par une décision citée dans ce plan, ou quand la classe qu'il vise disparaît ; le message de commit le nomme, test par test, avec la décision. L'annexe A liste ceux que chaque lot touche.
10. **Un lot peut se faire en plusieurs commits**, chacun vert sur les deux moteurs. À la fin du lot : relecture par deux relecteurs adversariaux (points de relecture du lot, plus ceux de 0.3), correction, nouveau passage des deux suites, commit de relecture, push, CI verte.
11. **Messages** : à l'écran, en français simple, sans aucun code technique (ni catégorie EN 16931, ni VATEX, ni schéma 0223, ni nom d'énumération, ni identifiant interne). Un test le vérifie (3.23.6).
12. **Fin de lot** : chaque critère de fin du lot est vérifié et ses recherches `git grep` sont vides ; les tests nommés du lot existent et passent ; les « ce qui ne doit pas changer » sont vérifiés.
13. **Portes** : un lot ne commence pas tant qu'une question de 6.1 qui le conditionne n'a pas de réponse (table 6.0). La réponse s'applique comme 6.1 la décrit, option par option ; l'agent la cite dans son message de commit, écrit dans `CLAUDE.md` les phrases qu'elle tranche (2.0) et adapte les tests et montants que 6.1 désigne pour cette option.

### 0.4 Entrée dans la chaîne (vérifié au début du lot M0)

- Les lots A4, A4b, A5, A6, A7 et le lot 21 de la chaîne société sont commités, poussés, et la dernière CI est verte. Sinon, attendre : **aucun lot de ce plan ne commence avant la fin du lot 21**, pour ne jamais travailler en parallèle sur les mêmes fichiers (`EmettreFactureAction`, `ConstructeurLigneDocument`, `services`, actions de vente, registre des autorisations).
- Le lot 21 avait pour critère « étape 1 cochée ». Ce plan le remplace : **l'étape 1 n'est cochée qu'au lot FIN** (5.22). Si le lot 21 l'a cochée, le lot M0 la décoche, avec cette raison dans le message de commit.
- Les constructions des lots A4 à A6 dont le plan dépend existent : `LecteurDesDroits`, `OperateurCourant`, `FigeLeNomDeSesAuteurs`, `PermissionRegistry::consultations()` et `modeleDeLAdministrateur()`, `AutorisationsSociete`, référentiels des autorisations en base, `ActionsUniques`, `ActionTraitee`, `DerogationsDePrix`, `VerificateurDeStock`, prix et taux de référence des services. Si l'une manque ou porte un autre nom, l'agent le note en tête de son commit et suit le code.

### 0.5 Vocabulaire

| Terme | Sens |
|---|---|
| Fait | Une information dont le moteur a besoin, avec sa source (saisie, fiche, dernier document, magasin, société, dérivée, par défaut). |
| Nature d'opération | Le régime d'une vente : vente en France, export, livraison vers un DOM, livraison intracommunautaire, prestation autoliquidée, prestation hors UE (énumération `NatureOperation`). Le mot « régime » reste réservé au régime de TVA de la société (`RegimeTva`). À l'écran : « Régime de TVA de cette vente ». |
| Nature d'achat | Le régime d'une ligne d'achat : achat en France, achat à un fournisseur en franchise, achat auprès de Monaco, acquisition intracommunautaire, livraison en France par un fournisseur non établi, services d'un prestataire non établi, importation (énumération `NatureAchat`). |
| Décision | Ce que rend le moteur : nature, lignes chiffrées, mentions, circuit, conditions, manquements, avertissements, justification, versions. |
| Geste | Un choix de l'utilisateur sur le régime d'une vente : appliquer une nature, ou garder la TVA française ; enregistré avec son auteur et l'instant. |
| Appliqué d'office | Une nature dont toutes les conditions sont réunies grâce à des réponses données dans le document : elle s'applique sans clic, et l'émission la confirme. |
| Proposé | Une nature dont toutes les conditions sont réunies sans qu'aucune question n'ait été posée (prestation à une entreprise établie hors de France) : elle attend un geste. |
| Redevable | Qui paie la TVA au Trésor : le vendeur, le client, la société acheteuse (autoliquidation), le fournisseur, ou personne. |
| Catégorie | Le code de TVA de la norme EN 16931 (S, E, AE, K, G, O). Déduit, jamais enregistré, jamais affiché. |
| Circuit | Où partent les données à l'étape 2 : facture électronique, e-reporting international, e-reporting des ventes aux particuliers, Chorus Pro hors de PILIQI, ou aucun. |
| Bloquant | L'émission (vente) ou la validation (achat, charge) est refusée ; rien n'est numéroté ni signé ; tous les manquements sont dits d'un coup. |
| Avertissement | Rien n'est refusé ; le message s'affiche, se répète dans la fenêtre de confirmation et reste dans la décision. |
| Information | Rien n'est refusé ; un texte s'affiche dans le panneau, sans se répéter dans la fenêtre de confirmation ni rester parmi les avertissements de la décision. |
| Charge | Une facture d'un fournisseur ou d'un organisme dont les lignes n'entrent pas en stock, chacune avec une catégorie (section 4). |
| Charge commune | Une charge de la société, saisie dans l'espace de la société et répartie entre plusieurs de ses magasins réels. |
| Part | Ce qu'une charge commune attribue à un magasin, ligne par ligne. |
| Pièce justificative | Un fichier (PDF, JPEG, PNG, ou XML d'une facture électronique UBL ou CII) joint à une charge, à une facture d'achat, à des salaires ou à une charge commune. C'est une copie de consultation : la société conserve l'original (4.6). |

### 0.6 Conventions

- Code en français, sans accents dans les identifiants, comme le dépôt (verbes et compléments : `CollecteurFaitsDeVente`, `deciderAchat`).
- Tests en PHPUnit, créés par `php artisan make:test --phpunit <Nom>` (ou `--unit`), méthodes `test_` puis une phrase en minuscules sans accents. Un test de concurrence entre dans le groupe `concurrence`, sous PostgreSQL, avec sa version SQLite déterministe quand le lot le dit.
- Chemins des tests : `tests/Unit/Tva/…` pour le moteur pur (aucune base), `tests/Feature/Tva/…` pour les ventes par les actions, `tests/Feature/Achats/…`, `tests/Feature/Charges/…`, `tests/Feature/Filament/…` pour les écrans, `tests/Feature/Societe/…`, `tests/Feature/Tenancy/…`.
- Montants des tests écrits en dur, en euros, recalculés à la main avec les règles de `CLAUDE.md`.

### 0.7 Étiquettes des décisions antérieures

Ce plan cite parfois une décision antérieure par son étiquette ; chacune est déjà écrite dans `CLAUDE.md`, sans étiquette. Les décisions de la série 7 s'écrivent TVA1 à TVA16 et O1 à O8 (section 1) ; les questions encore ouvertes à l'éditeur, 6.1 a à g ; les points de l'expert-comptable, EC1 à EC20 (6.2).

| Étiquette | Décision |
|---|---|
| R1 | Vente au-delà du stock refusée, sauf autorisation de vendre sans stock (avertissement, alerte, journalisation). |
| R2 | Remises et prix dérogatoires (prix d'une ligne, prix de référence d'un service) soumis à l'autorisation « accorder des remises et fixer le prix d'un service ». |
| R3 | Jeton unique des demandes répétées (`ActionsUniques`). |
| R4 | TVA d'une vente à un professionnel : total HT de chaque taux × taux, arrondi une fois, réparti sur les lignes par cumul. |
| R5 | Caisses personnelles fermées d'office par la clôture du jour et à minuit. |
| B2 | Taux de TVA saisi librement, 20 % par défaut (8,5 % en Guadeloupe, Martinique, La Réunion) ; un taux inhabituel est signalé, jamais refusé. |
| B10 | Société en franchise : ventes à 0 % au même prix TTC, mention « TVA non applicable, art. 293 B du CGI ». |
| C1, C2 | Retour au fournisseur au coût de la ligne d'achat retournée, coût moyen recalculé ; modifier une facture d'achat validée exige l'autorisation de valider. |
| E1 | Facture à un professionnel calculée depuis le HT de ligne (quantité × PU TTC ÷ (1 + taux), arrondi une fois). |
| E5, E6, E7 | Statut « encaissée » des services à chaque paiement sauf option pour les débits ; paiements des particuliers pour des services déclarés après confirmation de l'expert-comptable ; clients publics hors plateforme, avec un message. |
| E13 | Une vente que la passerelle ne sait pas transmettre (catégorie non prise en charge) reste hors plateforme, avec un message. |
| E14 | Ventes aux particuliers déclarées par clôture journalière, suivies jusqu'à l'accusé de réception. |
| I1 à I4 | Natures d'opération ; VIES vérifié le jour de l'émission, consultation conservée ; détaxe (le ticket reste à la TVA française, n° du bordereau noté) ; références des preuves d'export et de livraison enregistrées, celles qui manquent rappelées. |
| K1 | La valeur d'un stock ne descend jamais sous 0 €. |
| K2 | Retours partiels d'une ligne à un professionnel par différence de cumul, le dernier soldant la ligne au centime. |
| K3 | Vente exonérée payée au prix hors taxe : prix TTC du catalogue ÷ (1 + taux). |

---

## 1. Décisions appliquées

### 1.1 Série 7 : ce que le code fait

| Question | Réponse | Ce que fait le code | Lot |
|---|---|---|---|
| TVA1 Place du moteur | a | Après la chaîne société et le lot 21, avant la campagne de tests initiale (étape 1 bis). Les compléments s'intercalent (C-a, C-b). L'étape 1 n'est cochée qu'au lot FIN. | M0, FIN |
| TVA2 Le régime s'applique-t-il seul ? | a | Une nature s'applique d'office quand les réponses du document la justifient (marchandises expédiées hors de France, adresse, date), avec le nouveau total et le lien « Rester à la TVA française » ; une prestation à une entreprise établie hors de France est proposée avec un bouton « Appliquer » ; une nature encore proposée à l'émission impose un choix dans la fenêtre « Émettre », enregistré (geste, auteur, instant) ; personne ne force une nature dont une condition manque ; la caisse ne propose jamais d'exonération. | M3 (gestes), M4 (écrans) |
| TVA3 Que demander ? | a | Vente : « Les marchandises quittent-elles la France ? » sans réponse par défaut pour un client établi hors du territoire de TVA français, un n° de TVA étranger ou une livraison hors de France, et pour tout client par le lien « Expédier hors de France ou livrer à une autre adresse ? » ; la réponse de la dernière facture au même client dans le magasin est proposée avec sa source et se confirme à l'émission ; la date de livraison part du jour. Achat : l'origine des marchandises s'affiche dès qu'il y a une ligne de biens (produit, ou charge portant sur un bien), « France » d'office pour un fournisseur établi en France, sinon reprise de la dernière facture validée du même fournisseur dans le magasin, sinon son pays « proposé, à confirmer » ; confirmation exigée pour un fournisseur non établi à sa première facture et à chaque changement : une origine reprise telle quelle de sa dernière facture validée se valide sans nouvelle confirmation (A08, A08b, A08c). | M3, M4, M5d, M6 |
| TVA4 Prix d'un service sans TVA française | a | Le client paie le prix de référence TTC du service ÷ (1 + taux), comme un produit, sans que ce soit une dérogation de prix ; le prix « sans TVA » tapé à la main disparaît. | M4 |
| TVA5 Produit à 0 % en France | a | Pour une société redevable, la fiche d'un produit ou d'un service refuse le taux 0 (`tva.taux_zero_en_france`) ; l'émission le refuse aussi, en filet de sécurité. | M4 |
| TVA6 Une seule nature par document | a | Une nature par document ; une vente en France qui mêle produits et services s'émet ; dans une autre nature, une ligne qu'elle n'admet pas bloque l'émission (`tva.une_nature_par_document`, `tva.document_separe_requis`) et le bouton « Déplacer ces lignes vers une nouvelle facture » la sort du brouillon. | M3 (règle), M4 (bouton) |
| TVA7 Sommes entre magasins | a | Deux lectures de sommes de la société, sur ses magasins réels, démonstration exclue : compteur des ventes à distance et relevé du mois de la société ; montants visibles du seul propriétaire et des administrateurs autorisés à gérer les paramètres de la société ; un vendeur ne voit que « seuil non atteint », « seuil proche » ou « seuil atteint ». | M4 (relevé), M8 (compteur) |
| TVA8 Guichet OSS | a | Compteur de la société (particuliers et clients non assujettis, franchise comprise), deux valeurs de départ, question « inscrite au guichet OSS ? », information « seuil non atteint » sous 9 000 €, avertissement dès 9 000 €, refus de toute expédition qui ferait dépasser 10 000 € et de toute vente à distance quand l'année précédente les a dépassés ou que la société est inscrite à l'OSS ; la vente au comptoir reste possible ; l'OSS lui-même viendra au lot M9, après l'étape 2 (l'option « jamais » a été écartée). | M8 |
| TVA9 Cartes cadeaux | **c** | Voir 1.2 : PILIQI ne gère ni cartes cadeaux ni bons d'achat ; aucun code ne les gère, aucun marqueur ne les reconnaît ; la règle est écrite dans `CLAUDE.md` comme une consigne, et la notice dit qu'un magasin n'en vend pas par PILIQI, pas même comme un produit ordinaire. | M0, M4 |
| TVA10 Franchise hors de France | a | Ventes : les marchandises gardent la mention de franchise ; une prestation à une entreprise établie hors de France suit les règles de lieu (autoliquidation dans l'Union, hors champ ailleurs), ce qui exige le n° de TVA de la société. Achats : acquisitions dans l'Union et importations refusées (`tva.achat_etranger_en_franchise_non_gere`) ; services d'un prestataire étranger calculés (TVA française autoliquidée, non déductible, ajoutée à la charge), sauf une prime d'assurance ou une opération bancaire exonérée, jamais autoliquidée (3.17.2). | M4, M5c, M5e |
| TVA11 Achats non calculés seuls | a | Saisie manuelle de la nature et des montants de TVA d'un achat, avec l'autorisation de magasin dédiée `facture_achat.saisie_manuelle_tva` : le propriétaire et les responsables du magasin l'ont d'office (ils ont tous les droits du magasin) ; elle n'est précochée pour personne d'autre (ni dans le modèle de l'administrateur, ni dans ceux du vendeur et de l'observateur), un expert-comptable ne l'a jamais ; motif obligatoire ; inscription `TvaDAchatSaisieALaMain` au journal d'audit. | M5e |
| TVA12 Importation avant la déclaration en douane | a | La facture se valide et la marchandise se vend, avec un coût provisoire et une liste « Dédouanement à compléter » ; le coût se corrige à la saisie de la déclaration, même sur une facture reçue automatiquement (son contenu reçu ne change pas), jamais après un avoir fournisseur émis sur cette facture. | M5d, M7 |
| TVA13 TVA d'un achat et retours | a | TVA autoliquidée calculée sur le total HT de chaque taux, arrondie une fois, répartie par cumul (0,17 € + 0,66 € = 0,83 €) ; un retour ne rend jamais plus que la part de sa ligne, un retour complet rend exactement sa part. **TVA facturée** : la réponse vise « la TVA d'un achat », et l'option (c), écartée, ne se distinguait de (a) que par la TVA facturée ; le détail « TVA facturée recalculée ligne par ligne » de la conception n'a jamais été montré à l'éditeur. Question 6.1 c, recommandation : la même règle (TVA de chaque taux = total HT du taux × taux, arrondie une fois, répartie par cumul ; A29c : 0,83 €), écart affiché face à la facture du fournisseur quand elle est connue. Le lot M5c attend la réponse. | M5c, M5e |
| TVA14 Frais dans le coût | a | Frais d'acheminement et droits répartis entre les lignes de produits au prorata de leur HT net ; y compris les frais d'un transporteur ou d'un transitaire rattachés à la facture des marchandises du même magasin (une ligne de frais par facture rattachée). L'arrondi de la répartition n'a pas été montré à l'éditeur : question 6.1 d, recommandation par cumul, au centime ; le lot M5c attend la réponse. | M5c, M7 |
| TVA15 TVA étrangère facturée | b | La facture se valide ; la TVA étrangère est enregistrée à part (ni déductible, ni dans le coût) ; la TVA française de l'acquisition est calculée quand même ; avertissement fort ; liste « TVA étrangère à régulariser », dont la facture sort quand la régularisation est notée ou que la facture corrigée ne porte plus de TVA étrangère. | M5e, M6 |
| TVA16 Devises | a | Devise de la facture et taux de change saisi, avec sa source (déclaration en douane, imposée pour une importation ; facture du fournisseur ; relevé bancaire ; autre) ; une devise sans taux bloque la validation (`tva.devise_sans_taux`). Les arrondis de la conversion n'ont pas été montrés à l'éditeur : question 6.1 d, recommandation HT de chaque ligne en devise × taux, arrondi une fois au centime, puis la TVA comme pour un achat en euros ; coût unitaire net = PU HT en devise × taux × (1 − remise) × (1 − escompte), arrondi une fois au dix-millième ; le lot M5e attend la réponse. | M5e, M6 |

### 1.2 Cartes cadeaux et bons d'achat (TVA9 = c) : ce que le code fait et ne fait pas

- **Ne fait pas** : aucune valeur « carte cadeau ou bon d'achat » dans la particularité fiscale d'un produit (elle ne vaut que `aucune`, `accises`, `moyen_de_transport_neuf`) ; aucun code de refus `tva.bon_d_achat_non_gere` ; aucun moyen de paiement « carte cadeau » ni « bon d'achat » (`ModePaiement` garde virement, chèque, espèces, carte bancaire, prélèvement) ; aucun traitement des bons à usage unique ou à usages multiples (art. 256 ter du CGI), ni maintenant ni au lot M9 ; aucun test V32 ; aucune heuristique sur le nom d'un produit, qui serait trompeuse. Rien n'empêche donc techniquement de créer un produit nommé « Carte cadeau » : la règle est une consigne, et `CLAUDE.md` la dit comme telle, sans prétendre qu'un contrôle l'applique.
- **Fait** : la règle est écrite dans `CLAUDE.md` (puce « Prix et TVA des ventes », 2.7) : « PILIQI ne gère ni cartes cadeaux ni bons d'achat (art. 256 ter du CGI) : aucune fonction, aucune particularité fiscale ni aucun moyen de paiement de ce type ; la notice dit qu'un magasin n'en vend pas par PILIQI, pas même comme un produit ordinaire ; le bon d'avoir du client comptoir n'en est pas un ». L'étape 3 de la feuille de route, qui écrit la notice de la caisse sécurisée, y reprend cette consigne (texte de 2.17). Le bon d'avoir (crédit d'un avoir fait au client comptoir, imputé sur présentation de son numéro) reste tel quel : ce n'est pas un bon d'achat vendu.
- **Recherche de fin** (lot M4) : `git grep -n -i -E "carte.?cadeau|bon.?d.?achat|bon_d_achat|usages multiples|256 ter"` vide dans `app/`, `resources/`, `database/` ; elle ne trouve que le texte de `CLAUDE.md`. Le service de démonstration « Emballage cadeau » n'est pas une carte cadeau et reste.

### 1.3 Corrections O1 à O8 (acceptées toutes)

| # | Ce qui sera fait | Lot |
|---|---|---|
| O1 | La tuile « Valeur du stock » affiche le coût d'achat (coût moyen × quantité), sans TVA ajoutée ; pour une société en franchise, le coût comprend la TVA qu'elle ne déduit pas et le bénéfice affiché se calcule sur le prix encaissé ; `produits.cmup_ht` devient `cout_moyen`, `mouvements_stock.cout_unitaire_ht` devient `cout_unitaire`, `CalculateurLigne::valeurAuCoutMoyen()` devient `valeurAuCout()` (sans TVA). | M7 |
| O2 | Professionnel non assujetti et entité publique : calcul comme tout professionnel (depuis le HT de ligne) ; un non-assujetti n'entre jamais en facture électronique et relève de l'e-reporting des ventes aux particuliers ; une entité publique se facture sur Chorus Pro, hors de PILIQI, avec un message. | M3 |
| O3 | VIES reste vérifié le jour de l'émission (I2) ; avertissement `tva.vies_apres_la_livraison` quand la livraison précède l'émission sans vérification faite à sa date. | M4 |
| O4 | Les articles du CGI restent imprimés tant que ceux du CIBS ne sont pas confirmés (admis jusqu'au 30/06/2028) ; le passage se fera par une nouvelle version des règles, un document émis gardant son texte. | M2 |
| O5 | Nom et adresse du client exigés à l'émission de toute facture (professionnel, ou particulier qui demande une facture). | M3 (professionnel), C-b (particulier) |
| O6 | Un magasin de Guadeloupe, de Martinique ou de La Réunion qui expédie hors de son département est refusé (`tva.depart_hors_du_dom_non_gere`) ; une prestation à un professionnel établi dans un DOM est refusée (`tva.prestation_vers_un_dom_non_geree`) ; le régime dédié viendra au lot M9, après l'étape 2, comme l'éditeur l'a décidé. | M3 |
| O7 | Le régime de TVA de la société est un historique daté (« Statut de TVA », « Depuis le ») ; le moteur lit le régime en vigueur à la date de l'opération ; un changement ne modifie jamais un document émis ; une date passée fait lister, magasin par magasin, les documents émis et les achats validés depuis, à revoir avec l'expert-comptable. | M5a |
| O8 | Une facture issue d'un devis reprend ses faits (sortie des biens, livraison, lieu des services) et son geste ; à son émission, le moteur réévalue avec la vérification VIES du jour et refuse, avec la voie « Rester à la TVA française », si une condition est tombée. | M3 |

### 1.4 Espace « Charges du magasin »

| Décision de l'éditeur | Ce que fait le code | Lot |
|---|---|---|
| Une charge = une facture fournisseur dont les lignes n'entrent pas en stock, avec une catégorie ; même moteur, même calcul, même validation, mêmes jetons, même journal d'audit que les achats ; un seul modèle pour toutes les factures fournisseurs | Une charge est une `FactureAchat` de type `charges` dont toutes les lignes sont de type `charge`, chacune avec une catégorie ; une ligne `charge` peut aussi figurer sur une facture de marchandises, qui exige alors la date de la pièce ; validation par `ValiderFactureAchatAction` (jeton de la validation d'achat), décision figée et journal d'audit comme un achat. | M5c, M5d, CH2 |
| TVA récupérable décidée par le moteur et expliquée | Règles par catégorie, par précision et par cas dans les règles datées du moteur (4.3), chaque ligne avec sa raison ; une règle encore à confirmer est signalée comme telle ; une pièce qui n'est pas une facture au nom de la société ou du magasin (ticket, reçu) n'ouvre aucune déduction ; une prime d'assurance ou une opération bancaire exonérée achetée à un prestataire non établi n'est pas autoliquidée ; le moteur suppose un droit à déduction total et signale chaque charge d'une société qui déclare aussi des opérations exonérées ou hors du champ de la TVA. L'arrondi de la TVA déductible partielle est soumis à l'éditeur (6.1 d). | M5c, CH2 |
| Pièces justificatives : « pièces justificatives de l'étape 8 avancées » (décision de calendrier), selon l'avis de Claude que l'éditeur a repris (PDF ou photo, rangées par magasin, conservées 10 ans, jamais supprimées après validation, chaque ajout tracé) | Pièces chiffrées sur un disque privé `justificatifs`, isolées par magasin (et par société pour une charge commune), contrôle du type et de la taille côté serveur ; le XML d'une facture électronique (UBL, CII) est admis ; chaque pièce est une copie de consultation, la société gardant l'original 10 ans, ce que l'écran et la notice disent ; retrait possible seulement sur un brouillon ; une pièce déposée par erreur (bulletin, document d'un tiers) se remplace, avec un motif, même sur un document validé, pour respecter l'exactitude et la minimisation des données (RGPD) ; audit de chaque ajout, retrait et remplacement. | CH1, CH4, CH5 |
| (Avis de Claude, non décidé) Charges récurrentes | Rien tant que l'éditeur n'a pas répondu (6.1 f) ; s'il les retient, le lot CH3 construit le modèle qui crée le brouillon de chaque mois, par une commande planifiée, sans doublon. | CH3 (conditionnel) |
| Charges communes : « Saisie société, répartie » | Saisie dans l'espace de la société par le propriétaire ou un administrateur autorisé (`societe.charges.gerer`), répartition à parts égales ou par pourcentages, calculée par le moteur (arrondi soumis à l'éditeur, 6.1 d ; recommandation : chaque montant de chaque ligne au centime par cumul), pièce gardée à la société, part visible dans chaque magasin ; détail et pièce lisibles, en lecture seule depuis le panneau d'un magasin qui en a une part, du propriétaire, des experts-comptables et d'un administrateur dont l'autorisation couvre toute la répartition (l'espace de la société reste fermé aux experts-comptables, lot A4). Trois restrictions de ce plan, non décidées par l'éditeur, s'appliquent à titre provisoire (6.1 e). | CH5 |
| Salaires : « Totaux du mois » | Table `salaires_mensuels` (salaires bruts, cotisations patronales) par magasin et par mois ; pièce : récapitulatif totalisé de l'établissement, sans détail par salarié ; aucun bulletin ; saisis dans l'espace de la société par le propriétaire et les administrateurs autorisés (`societe.salaires.gerer`) ; vus d'eux et des experts-comptables (dans le panneau du magasin) ; jamais des responsables ni des autres membres, ni par différence dans un résultat (étape 6). | CH4 |
| Calendrier : « dans les lots achats du moteur de TVA (M5 à M7) » | Les lignes de charge et leur catégorie naissent avec le moteur des achats (M5c, M5d) ; les lots des charges (CH1, CH2, CH4, CH5, et CH3, CH6 s'ils sont retenus) prennent place dans le bloc des achats, après M6 et avant M7, qui le termine (5.0). | 5.0 |
| Conséquences pour `CLAUDE.md` | Puce 2 de « Produit » : répartition d'une charge commune, lecture de son détail depuis un magasin et salaires saisis dans l'espace de la société ; « Comptes et équipe » : les salaires sont l'exception aux droits du responsable ; nouvelle puce « Charges du magasin » ; feuille de route : étapes 1, 1 bis et 2 à 9 ajustées (2.17). | M0 |

### 1.5 Compléments 1 à 16

| Point | Sujet | Lot |
|---|---|---|
| 1 | À la contre-passation, le crédit d'avoir disponible s'impute aussitôt sur la facture rouverte | C-a |
| 2 | Correction immédiate d'un produit existant depuis un brouillon d'achat (texte de `CLAUDE.md` ; comportement gardé) | M0 (texte), C-a (test), M6 (bloc « Vente en magasin ») |
| 3 | Valider une facture d'achat qui crée des produits exige le droit de créer des produits | C-a ; gardé par M5d |
| 4 | Preuves : annulation tracée, « établie par » obligatoire, deux parties différentes et indépendantes du magasin et du client | C-a ; M4 lit les preuves attendues dans la table datée |
| 5 | Brouillon d'export rouvert après le passage en franchise : un service reprend son prix TTC du catalogue, à 0 %, avec notification | M0 (texte), M2 (docblock) |
| 6 | Détaxe : rappel d'annuler ou de rectifier le bordereau au retour d'un article détaxé | C-a |
| 7 | Étape 2 : e-reporting international (« out » et « in ») | M0 (texte) |
| 8 | Clôtures : les factures aux professionnels entrent dans les clôtures calculées depuis le registre (étape 3) | M0 (texte) ; M3 donne le circuit de chaque document |
| 9 | Assujetti, entité publique, circuit sur les trois conditions | M0 (texte), M3 (code) |
| 10 | Couverture de l'art. 290 (tous les cas du I, Monaco dans les deux sens et acquisitions de l'art. 258 D I compris) ; opérations des art. 261 à 261 E refusées ou signalées ; absence d'opération appréciée par SIREN, pour la société | M3 (circuit), M4 (taux 0 refusé), M0 (texte de l'étape 2) |
| 11 | Calendrier de l'e-reporting tenu par SUPER PDP ; chaque clôture journalière ayant des opérations envoyée une seule fois, corrigée par une entrée négative | M0 (texte de l'étape 2) ; étape 2 (aucun code ici) |
| 12 | Achats auprès de fournisseurs étrangers ; factures étrangères reçues par Peppol vérifiées au bac à sable pour ne jamais être déclarées deux fois | M5a à M7 ; M0 (texte de l'étape 2) |
| 13, 14, 15 | Facture demandée par un particulier ; « Transformer en facture » | C-b |
| 16 | Retours et avoirs sur les tickets : autorisation dédiée | C-a |

### 1.6 Ce qui change par rapport à la conception finale du moteur

| Changement | Raison |
|---|---|
| M0 passe en tête | Toutes les réponses sont reçues ; les règles s'écrivent avant le code, comme au lot A4 ; les lots C-a et C-b n'écrivent plus leurs propres textes. |
| M8 suit immédiatement M4 | Il ne dépend que des ventes ; le bloc des ventes se termine avant celui des achats. |
| M5 est coupé en cinq lots (M5a à M5e) | Des lots assez petits pour être relus. |
| Le type de ligne d'achat `autre_service` devient `charge`, avec une catégorie obligatoire | Décision des charges : une ligne qui n'entre ni en stock ni dans le coût est une charge, et toute charge a une catégorie. |
| TVA11 : le propriétaire **et les responsables du magasin** ont l'autorisation de saisie manuelle | Réponse de l'éditeur ; série 6 (le responsable a tous les droits du magasin). |
| Aucune carte cadeau, nulle part | TVA9 = c (1.2). |
| `factures.vente_a_distance` et `avoirs.vente_a_distance` naissent au lot M3 | Le compteur du lot M8 interroge cette sortie du moteur : elle est une colonne figée et signée, écrite avec les autres colonnes fiscales, pour que le format signé ne change pas une troisième fois. |
| Le format signé change au lot M3, puis, de façon additive, au lot C-b | Les clés de la facture demandée et de « Transformer en facture » n'existent qu'avec leur fonctionnalité ; `ModuleFiscal::VERSION` reste « 1.0 » jusqu'à l'étape 3. |
| `CalculateurLigne` déménage au lot M1 sans son API en centimes, qui arrive au lot M2 avec le moteur qui l'utilise | Aucun code inutilisé. |
| La consultation VIES à l'émission exige le droit d'émettre, pas celui de modifier le client | Un vendeur qui émet n'a pas forcément `client.update` ; la consultation fait partie de l'émission (3.8). |
| Charges communes et salaires : pas de jeton ; protection par l'état et le verrou de leur ligne, une demande répétée rendant le résultat déjà enregistré | Les jetons du lot A6 sont rangés par magasin (`ActionTraitee` est un modèle de magasin, clé (`magasin_id`, `jeton`)) ; le texte de « Demandes répétées » (2.10) ne vise que les validations d'un magasin et dit la protection des gestes de la société. |
| La TVA facturée d'un achat n'est plus « recalculée ligne par ligne » d'office : sa règle est soumise à l'éditeur (6.1 c), avec pour recommandation la lettre de TVA13 = a | Le résumé montré à l'éditeur ne contenait pas ce détail de la conception, et l'option (c), écartée, ne se distinguait de (a) que par la TVA facturée ; c'est une règle d'arrondi (protocole, règle 4). |
| Les arrondis de la répartition des frais et des droits, de la conversion des devises, de la TVA déductible partielle et de la répartition des charges communes sont soumis à l'éditeur (6.1 d) ; la répartition des charges communes se calcule dans le moteur | Aucun n'était dans les questions montrées à l'éditeur ; un montant de TVA ne se calcule que dans le moteur (P1, P9). |
| Les lots des charges prennent place entre M6 et M7 | Décision de calendrier : « dans les lots achats du moteur de TVA (M5 à M7) ». |
| Les charges récurrentes (CH3) deviennent un lot conditionnel, comme CH6 | Elles n'étaient qu'un avis de Claude, sans décision de l'éditeur (6.1 f). |
| Trois restrictions des charges communes deviennent provisoires (même territoire de TVA ; organisme non établi qui ne facture qu'un service ; aucune saisie manuelle au niveau de la société) | L'éditeur n'a décidé que « parts égales ou pourcentages » ; elles sont soumises en 6.1 e et ne s'écrivent dans `CLAUDE.md` que s'il les garde. |
| Le détail d'une charge commune et sa pièce se lisent depuis le panneau d'un magasin qui en a une part | Le lot A4 ferme l'espace de la société aux experts-comptables, et ses tests le vérifient. |
| Une prime d'assurance ou une opération bancaire exonérée achetée à un prestataire non établi n'est jamais autoliquidée | L'autoliquidation ne change que le redevable d'une taxe due ; elle ne rend pas imposable une opération exonérée (art. 261 C du CGI). |
| Une pièce qui n'est pas une facture au nom de la société ou du magasin n'ouvre aucune déduction | Art. 271 II du CGI : la déduction suppose une facture. |
| La pièce justificative est une copie de consultation ; le XML d'une facture électronique est admis ; une pièce déposée par erreur se remplace avec un motif | L102 B et A102 B-2 du LPF, L123-22 du Code de commerce ; RGPD (exactitude, minimisation). |
| `avoir.sur_ticket` suffit seule pour un retour ou un avoir sur un ticket | Complément 16 : une seule autorisation, et le modèle du vendeur n'a aucune autorisation `avoir.*`. |
| « Transformer en facture » construit la facture avec les faits figés du ticket | Compléments 14 et 15 : exactement les mêmes lignes, prix, taux et totaux ; aucun refus « calcul changé ». |

---

## 2. Textes de `CLAUDE.md` (lot M0)

### 2.0 Méthode

- Les textes ci-dessous sont **intégraux** : chaque puce citée remplace la puce du même titre. Ils partent des textes connus au 11/10/2026 (copie de travail de la chaîne société, lot A4 en cours), auxquels s'ajoutent les textes que les lots A4 à A7 et le lot 21 doivent encore écrire (connus pour A4b, `inscription-sans-magasin.md`).
- **Fusion.** Si la chaîne société a modifié une puce après la rédaction de ce plan, l'agent du lot M0 garde ses changements et n'applique, à leur place, que les changements listés sous chaque texte (« Changements »). Il ne retire aucune règle de la chaîne société. Toute phrase que le lot A4b (`inscription-sans-magasin.md`) ou un autre lot de la chaîne société a écrite et qui manquerait à un texte de ce plan se garde à la fusion. Le message de commit dit, puce par puce, s'il a remplacé le texte ou fusionné.
- **Phrases soumises à une question ouverte.** Sous certains textes, une liste « Soumis à 6.1 » donne les phrases qui dépendent d'une question encore ouverte (6.1 c à g), le texte à écrire selon chaque réponse, et un texte d'attente. Le lot M0 écrit le texte de la réponse quand elle est connue ; sinon il écrit le texte d'attente (le texte actuel de `CLAUDE.md` quand il existe, sinon rien), et le premier lot que la question conditionne (table 6.0) remplace ce texte d'attente par celui de la réponse, dans son propre commit. Aucune règle non décidée n'entre dans `CLAUDE.md`.
- Les puces non citées ne changent pas. Le protocole de rigueur et les règles Laravel Boost ne changent pas.
- Chaque phrase nouvelle découle d'une décision de la section 1 ; le diff du lot M0 ne touche que les puces citées.

### 2.1 « Développement : toujours repartir de zéro »

Inchangé.

### 2.2 « Produit », puce 2 (isolation et lectures hors du magasin)

> - Chaque société est strictement isolée des autres, et dans une société chaque magasin est strictement isolé (`BelongsToMagasin` + `MagasinScope`) : aucun client, produit, fournisseur, service, document, charge, stock, caisse ni réglage local n'est jamais partagé ni mélangé entre magasins, ni copié d'une société à une autre. Les données propres à une société (journal d'audit, vérifications, historique de son régime de TVA, charges communes et leurs pièces, et à l'étape 2 sa connexion et ses factures reçues SUPER PDP) utilisent `BelongsToSociete` + `SocieteScope`. L'identité légale et les réglages fiscaux communs n'existent qu'une fois, sur la société. Seules lectures ou écritures hors du magasin ou de la société courants, explicites, filtrées, limitées aux champs nécessaires et testées : la liste et le tableau de bord des magasins et des membres de l'espace d'administration, limités à la portée de la personne ; le fait qu'un membre de l'équipe d'un magasin a aussi accès à d'autres magasins, lu seulement pour savoir qui peut gérer son compte, sans jamais dire lesquels ; les caisses encore ouvertes d'une personne dans les autres magasins de sa société (une caisse à la fois) ; l'existence d'une première vente de la société ; les destinataires des notifications ; les SIRET, adresses de réception SUPER PDP, codes de routage, adresses et références d'acheteur de ses magasins (pour attribuer les factures reçues) ; des sommes fiscales de la société sur ses magasins réels, démonstration exclue : le HT, net des avoirs, de ses ventes à distance dans l'Union de l'année précédente et de l'année en cours (seuil de 10 000 €), et le relevé du mois de ses livraisons intracommunautaires et de ses prestations autoliquidées par n° de TVA du client, dont les montants ne se voient que du propriétaire et des administrateurs autorisés à gérer les paramètres de la société ; quand la société change son régime de TVA avec une date passée, le nombre et les numéros des documents émis et des achats validés depuis cette date dans chacun de ses magasins réels ; la répartition d'une charge commune entre ses magasins réels, par le propriétaire ou un administrateur autorisé pour des magasins tous dans sa portée, qui écrit dans chacun la part qui lui revient et relit ces parts, chaque magasin ne voyant que la sienne ; le détail d'une charge commune (organisme, lignes, magasins de la répartition et leurs parts) et sa pièce, lus en lecture seule depuis le panneau d'un magasin qui en a une part, par le propriétaire, les experts-comptables et un administrateur dont l'autorisation des charges communes couvre tous les magasins de la répartition ; les totaux mensuels des salaires de ses magasins réels, saisis et relus dans l'espace de la société par le propriétaire et les administrateurs autorisés, pour les magasins de leur portée ; à l'étape 5, les écritures et les documents de tous ses magasins réels, pour l'export comptable de la société produit par le propriétaire ou un expert-comptable ; l'examen exceptionnel d'un SIREN par l'éditeur ; et, pour un même SIREN, l'existence d'une autre société validée ou ayant prouvé sa qualité de représentant (sans jamais son identité) et les codes de magasins d'une société révoquée (pour ne jamais redonner un numéro déjà émis sous ce SIREN).

Changements : « charge » ajouté à la liste de ce qui n'est jamais partagé ; « historique de son régime de TVA, charges communes et leurs pièces » ajouté aux données de société ; « Seules lectures » devient « Seules lectures ou écritures » ; cinq éléments ajoutés à la liste, avant « à l'étape 5 » : sommes fiscales (TVA7), documents à revoir après un changement de régime daté (O7), répartition d'une charge commune, lecture de son détail depuis un magasin, salaires.

### 2.3 « Comptes et équipe »

> - Comptes et équipe : un compte n'appartient qu'à une seule société ; une fois retiré par elle, la personne peut, avec son accord et avec le même compte, rejoindre une autre société ou créer la sienne, jamais avant. Une adresse e-mail déjà utilisée ne sert jamais à un nouveau compte. Une adresse e-mail s'enregistre et se compare en minuscules, sans espaces autour : A@x.fr et a@x.fr sont la même adresse. Chacun change lui-même son nom, son adresse de connexion et son mot de passe dans son profil, en donnant son mot de passe actuel ; une nouvelle adresse ne prend effet qu'après confirmation par un lien envoyé à cette adresse, et l'ancienne est prévenue ; personne ne change le nom ni l'adresse d'un autre compte. Une société s'inscrit par un lien envoyé à l'adresse de son propriétaire, valable 24 heures ; après ce lien, le propriétaire arrive dans l'espace de sa société, sur une liste d'étapes (vérifier la société, créer le premier magasin, ajouter l'équipe, essayer le magasin de démonstration). Elle a un seul propriétaire, qui a tous les droits dans la société et dans chacun de ses magasins sans qu'aucune autorisation ne lui soit demandée ; les règles qui valent pour tous (lecture seule, validation de la société, documents émis, clôtures, une caisse à la fois) s'appliquent à lui comme aux autres. Seul le propriétaire crée et gère les administrateurs : il choisit l'accès de chacun à tous les magasins, présents et futurs, ou à une sélection de magasins, et coche ses autorisations. Un administrateur ne crée ni ne gère jamais les vendeurs et les observateurs, sauf pour nommer responsable l'un d'eux dans son magasin, s'il y est autorisé ; il ne nomme, ne retire et ne change les magasins des responsables que si le propriétaire lui en a donné l'autorisation (« nommer et retirer les responsables »), et seulement dans les magasins auxquels il a accès ; sa nomination prend effet aussitôt, le propriétaire en est prévenu et peut l'annuler en un clic, et l'administrateur peut annuler sa propre nomination faite par erreur ; il ne suspend, ne réactive ni ne rétrograde jamais un responsable ; il peut seulement retirer de la société une personne qui n'est que responsable de magasins tous dans sa portée, et lui renouveler son accès par un lien ; il ne touche jamais au propriétaire, à un autre administrateur ni à un expert-comptable. La société peut avoir plusieurs experts-comptables, chaque collaborateur du cabinet avec son propre compte, dont seul le propriétaire crée, suspend, réactive, retire et réintègre le compte et renouvelle l'accès : un expert-comptable n'a ni affectation ni case ; dans chacun des magasins réels de la société, présents et futurs, il a toutes les autorisations de consultation et aucune autre (documents, paiements, achats, charges et leurs pièces, parts et détail des charges communes, salaires, clôtures, fiches, registre et journal des événements, téléchargements ; jamais le journal d'audit ni les adresses IP et navigateurs) ; il ne vend, ne modifie, ne supprime et n'envoie rien, ne gère aucun compte, signe ses propres consultations, et produit et télécharge lui-même l'export comptable de la société, chaque export étant inscrit au journal d'audit. Un magasin peut avoir plusieurs responsables, et une même personne peut être responsable de plusieurs magasins ; c'est le propriétaire qui les nomme, ou un administrateur ainsi autorisé dans un magasin de sa portée. Un responsable a tous les droits dans son magasin, sans case (ventes, remises et prix, avoirs, achats et saisie manuelle de leur TVA, charges, stock, caisse et clôtures, paramètres du magasin, équipe du magasin), sauf les salaires du magasin, qu'il ne voit jamais, et jamais ceux de la société (identité et réglages fiscaux, charges communes, salaires, création, fermeture, réouverture et cession de magasins, plateforme agréée, journal d'audit, administrateurs, experts-comptables et responsables) ; il ne crée, ne nomme ni ne retire jamais un responsable et ne touche jamais au compte d'un responsable, d'un administrateur, d'un expert-comptable ni du propriétaire. Seuls le propriétaire et un responsable du magasin créent les vendeurs et les observateurs de ce magasin et leur cochent des autorisations valables pour ce magasin, jamais au-delà des siennes : pour eux, le rôle n'est qu'un modèle qui précoche des autorisations. Seul le propriétaire affecte une même personne à plusieurs magasins, avec des autorisations propres à chacun. Un responsable ne gère (crée, coche, modifie, suspend, réactive, retire, renouvelle l'accès) que les vendeurs et les observateurs dont tous les magasins sont des magasins dont il est responsable ; tout autre compte se gère par le propriétaire seul ; dans l'équipe de son magasin, il voit un membre qui travaille aussi ailleurs, sans pouvoir le modifier ni savoir dans quels autres magasins, et il ne voit ni ne touche jamais les comptes d'un autre magasin. Retirer quelqu'un de son dernier magasin le retire de la société. Un compte suspendu ou retiré par le propriétaire n'est réactivé ou réintégré que par lui ; réintégrer un compte retiré par la société, ou proposer un rattachement, suit les règles de la création du rôle proposé ; un compte retiré par son propre refus ne revient que par un rattachement qu'il accepte. Personne ne modifie ses propres droits ni ceux du propriétaire. Un compte créé par la société reçoit un mot de passe provisoire remis en main propre, valable 72 heures, que la personne change à sa première connexion ; ensuite, un accès perdu se renouvelle par un lien envoyé à la personne, et personne d'autre ne connaît son mot de passe. Un nouveau mot de passe provisoire n'est donné qu'à un compte dont le mot de passe est encore un provisoire, et seulement par le propriétaire ou, pour un vendeur ou un observateur, par un responsable de tous ses magasins ; tant que son mot de passe est un provisoire, un compte ne reçoit un nouveau magasin, un rôle de responsable ou une réintégration que de la personne qui l'a créé. Un compte créé par la société, membre, administrateur ou expert-comptable, peut être refusé par la personne tant que son adresse e-mail n'a jamais été confirmée, c'est-à-dire tant que personne n'a suivi un lien envoyé à cette adresse (inscription, « Mot de passe oublié », rattachement, confirmation d'une nouvelle adresse). Ce refus est possible à la connexion avec le mot de passe provisoire, ou sur la page du lien « Mot de passe oublié », même si le mot de passe provisoire a expiré, si le compte est suspendu ou s'il n'a plus de magasin ; une connexion interrompue ne vaut ni acceptation ni refus. Refusé, le compte est retiré, son mot de passe ne sert plus et toutes ses sessions sont fermées, la société ne peut plus le réintégrer sans l'accord de la personne, son auteur (à défaut, le propriétaire) en est prévenu, et la personne peut créer sa propre société avec ce compte, ou accepter un rattachement, après avoir choisi son mot de passe sur la page d'un lien envoyé à son adresse. Le compte du propriétaire ne se refuse jamais. Changer un mot de passe provisoire ne confirme pas l'adresse. L'historique garde le nom de chaque personne tel qu'il était au moment de chaque opération. Chaque vente enregistre le magasin où elle est faite, avec son SIRET, et la personne qui l'a faite, affectée et autorisée dans ce magasin à ce moment. Une personne n'encaisse que dans un magasin à la fois : elle ferme sa caisse d'un magasin avant d'encaisser dans un autre. Un e-mail demandé par une personne ne part que si, au moment de l'envoi, elle a encore accès au magasin et le droit d'envoyer ; sinon il ne part pas et le propriétaire en est prévenu. Connecter la plateforme agréée, prouver la qualité de représentant de la société, céder un magasin, consulter le journal d'audit et transmettre la propriété à un administrateur dont la qualité de représentant est prouvée restent réservés au propriétaire ; signer les attestations aussi, une fois sa propre qualité prouvée.

Changements (deux remplacements, et la phrase du lot A4b) :
- après « valable 24 heures », la phrase de la liste d'étapes que le lot A4b écrit (`inscription-sans-magasin.md`, section 1) ; si A4b l'a écrite autrement, son texte est gardé ;
- dans la phrase de l'expert-comptable, la liste des consultations devient « documents, paiements, achats, charges et leurs pièces, parts et détail des charges communes, salaires, clôtures, fiches, registre et journal des événements, téléchargements » ;
- la phrase du responsable devient « Un responsable a tous les droits dans son magasin, sans case (ventes, remises et prix, avoirs, achats et saisie manuelle de leur TVA, charges, stock, caisse et clôtures, paramètres du magasin, équipe du magasin), sauf les salaires du magasin, qu'il ne voit jamais, et jamais ceux de la société (identité et réglages fiscaux, charges communes, salaires, création, fermeture, réouverture et cession de magasins, plateforme agréée, journal d'audit, administrateurs, experts-comptables et responsables) ».

### 2.4 « Société et établissements »

> - Société et établissements : chaque société (un SIREN, un seul compte PILIQI) s'inscrit avec son seul propriétaire, sans magasin ; tous ses magasins se créent dans l'espace d'administration de la société, par le propriétaire ou un administrateur autorisé ayant accès à tous les magasins, avec le SIREN de la société, que personne ne remplace : on ne saisit que le NIC, et le SIRET, à clé de contrôle valide, actif et rattaché à ce SIREN au répertoire Sirene, n'est jamais celui d'un autre magasin ; un établissement fermé ou inconnu au répertoire est refusé ; à la création d'un magasin, PILIQI propose la liste des établissements actifs du SIREN lue au répertoire Sirene, et choisir un établissement préremplit son NIC, son nom et son adresse et propose un code, la saisie du NIC restant possible. Aucun magasin ne rejoint une société par la saisie d'un SIREN. La société porte l'identité légale (raison sociale, forme juridique — SARL, EURL, SAS, SASU, SA, SCA, SE, SNC, SCS, entrepreneur individuel, micro-entrepreneur compris, ou autre —, capital social, SIREN, immatriculation, adresse du siège ou, pour un entrepreneur individuel, de l'entreprise, n° de TVA) et les réglages fiscaux communs (régime de TVA daté « Depuis le », option pour les débits, mois de fin d'exercice, valeurs de départ de ses ventes à distance et inscription au guichet OSS, et la déclaration qu'elle réalise aussi des opérations exonérées ou hors du champ de la TVA) ; chaque changement d'un réglage qui touche la conformité s'inscrit au journal des événements de chacun de ses magasins. Un changement de régime de TVA prend effet à sa date : chaque vente et chaque achat se calcule avec le régime en vigueur à sa propre date ; il ne modifie jamais un document émis, et une date passée fait lister, magasin par magasin, les documents émis et les achats validés depuis, à revoir avec l'expert-comptable de la société. Chaque magasin garde sa caisse, ses stocks, ses ventes, ses factures, ses charges, ses clients, ses produits, ses fournisseurs, son équipe et ses paramètres locaux (coordonnées, références d'acheteur, barème, relances, échéance et mentions des factures) ; aucun n'a de privilège sur les autres, un établissement principal peut seulement être indiqué. Chaque magasin a un code court, unique dans la société, définitif dès son premier document, inséré dans tous ses numéros (FAC-LYON1-2026-000001). Un retour ou un paiement se fait dans le magasin qui a vendu ; aucun transfert de marchandises entre magasins. Le SIREN se corrige librement avant la validation de la société et se verrouille à sa validation. Ensuite, une erreur ou une usurpation de SIREN ne se traite que par la procédure exceptionnelle de l'éditeur (justificatifs, traçabilité, examen des documents concernés), qui ne modifie ni ne supprime aucun document émis : un avoir ou une facture rectificative n'est émis que si la situation juridique et fiscale l'exige, sans mouvement de stock artificiel ni encaissement ou paiement compté deux fois. Cet avoir de régularisation reprend l'émetteur figé de la facture d'origine (l'ancien SIREN), avec la mention « Avoir de régularisation émis après correction de l'identité du vendeur (SIREN correct : Y) », et la facture rectificative porte le nouveau SIREN ; cette règle est à faire confirmer par l'expert-comptable de la société le jour où le cas se présente. Un changement de SIRET (déménagement) se fait par la société, contrôlé au répertoire Sirene, prend effet le jour même, garde le même magasin et son historique, et l'ancien SIRET reste réservé ; un déménagement qui change le territoire de TVA passe par un nouveau magasin. Une activité reprise par une autre société s'ouvre dans le compte de celle-ci, qui saisit ou importe son catalogue et fait un inventaire d'ouverture ; le magasin cédé est fermé pour cession, son stock sort au coût moyen, journalisé, et cette valeur au coût (hors TVA, ou TVA non déductible comprise pour une société en franchise) est remise au propriétaire pour la rapprocher du prix de cession ; rien d'autre ne suit, et ni les factures, ni les paiements, ni les archives de la société d'origine ne bougent.

Changements : le début de la puce est celui du lot A4b (inscription sans magasin, liste des établissements Sirene) — si A4b l'a écrit autrement, garder le texte d'A4b ; « régime de TVA daté « Depuis le » », « valeurs de départ de ses ventes à distance et inscription au guichet OSS » et « la déclaration qu'elle réalise aussi des opérations exonérées ou hors du champ de la TVA » ajoutés aux réglages fiscaux communs ; phrase nouvelle « Un changement de régime de TVA prend effet à sa date … » ; « ses charges » ajouté à ce que garde chaque magasin ; « cette valeur hors taxe » devient « cette valeur au coût (hors TVA, ou TVA non déductible comprise pour une société en franchise) » (O1).

### 2.5 « Clients, produits, fournisseurs »

> - Clients, produits, fournisseurs : un client professionnel établi en France a un SIREN (9 chiffres, ou son SIRET de 14 chiffres) ; un client établi à l'étranger, un n° de TVA ou un autre identifiant. Un client professionnel est assujetti à la TVA, sauf case contraire, jamais cochée d'office ; il peut être marqué organisme public, proposé quand le répertoire Sirene renvoie une catégorie juridique 7xxx. « Établi en France », qui exige le SIREN, ne se confond jamais avec le territoire de TVA français, d'où sont exclus Saint-Barthélemy, Saint-Martin, Saint-Pierre-et-Miquelon, la Guyane et Mayotte. Le mode de paiement préféré d'un client est présélectionné à l'encaissement. Un fournisseur dont le pays est la France est établi en France ; à l'étranger, il ne l'est pas, sauf case cochée (un n° de TVA FR obtenu par un représentant fiscal ne suffit pas) ; il peut ne pas facturer de TVA (franchise) ; son n° de TVA de l'Union se vérifie sur VIES, chaque consultation conservée dans le magasin. Le pays d'un client ou d'un fournisseur se choisit dans une liste de pays nommés. Un produit peut porter une particularité fiscale (accises, moyen de transport neuf), qui ferme ses ventes hors de France. Une fiche ne se supprime pas : elle s'archive, n'est plus proposée, reste lisible sur les anciens documents et se restaure. Le client comptoir ne s'archive jamais. Les recherches ignorent la casse et les accents (« creme » trouve « Crème »). Une référence produit est unique dans le magasin sans tenir compte de la casse (« ABC » et « abc » sont la même référence) et s'enregistre telle que saisie.

Changements : phrases nouvelles sur l'assujetti et l'organisme public, sur « établi en France » et le territoire de TVA, sur le fournisseur (établissement, franchise, VIES), sur la liste des pays, sur la particularité fiscale ; « Son mode de paiement préféré » devient « Le mode de paiement préféré d'un client ».

### 2.6 « Documents de vente »

> - Documents de vente : le client (nom, adresse, identifiants, assujetti ou non, organisme public ou non) et son type sont figés à l'émission ; une facture émise ne change plus jamais et se corrige par un avoir. Échéance = date d'émission + délai du client, sinon délai du magasin (30 jours, réglable). Toute facture exige le nom et l'adresse du client. Une facture à un professionnel exige l'identité légale complète de la société (raison sociale, forme juridique, SIREN, immatriculation — RCS, RM ou mention de dispense —, adresse du siège ou, pour un entrepreneur individuel, de l'entreprise, n° de TVA hors franchise, et capital social pour toute société commerciale : SARL, EURL, SAS, SASU, SA, SCA, SE, SNC, SCS et leurs variantes), ainsi que le SIRET et l'adresse du magasin ; les tickets restent possibles. Elle porte les mentions réglables : pénalités de 3 fois le taux d'intérêt légal, indemnité forfaitaire de 40 €, pas d'escompte. Le capital social est imprimé quand il est renseigné, avec la mention « à capital variable » le cas échéant ; la dénomination d'un entrepreneur individuel, sans capital, porte la mention « EI », ajoutée automatiquement quand elle manque ; l'adresse du siège n'est pas répétée quand elle est celle du magasin ; identité et adresses sont figées à l'émission. La décision de TVA d'un document (nature, conditions évaluées, faits utilisés et leur source, mentions et note de calcul du jour, circuit, choix du régime avec son auteur et l'instant, versions du moteur et des règles) est figée et signée à son émission ; le PDF, la fiche et le duplicata relisent ses mentions figées. Un avoir reprend la nature, la franchise et le circuit des factures qu'il corrige et prend les mentions de son jour ; il ne mélange pas des factures de types de client, de franchises, de natures ou de circuits différents. Un particulier peut demander une facture, en caisse comme sur une facture : son nom et son adresse sont alors exigés, le document s'intitule « Facture », porte toutes les mentions d'une facture (hors les mentions réglables réservées aux factures à un professionnel) et prend un numéro de la série des factures du magasin ; sans la case, c'est un ticket de caisse ; dans les deux cas la vente reste une vente à un particulier, comptée dans la clôture du jour et dans l'e-reporting des ventes aux particuliers, jamais transmise comme facture électronique. Un ticket émis et entièrement payé se transforme en facture par le bouton « Transformer en facture », qui ne demande que les coordonnées du client : dans une seule transaction signée, un avoir d'annulation total, lié au ticket, dont le crédit n'est jamais remis au client ni proposé comme bon d'avoir, puis une nouvelle vente au particulier avec la case « facture demandée », construite avec les faits figés du ticket (ses lignes, prix, taux, franchise et date de l'opération), donc exactement les mêmes lignes, prix, taux et totaux, payée en totalité par ce crédit (« Payé par l'avoir n° AV-… (annulation du ticket n° TK-…) ») ; l'avoir cite le ticket qu'il annule et la facture qu'il permet d'établir (« Annulation du ticket n° TK-… ; permet d'établir la facture n° FAC-… ») ; ni l'avoir ni la facture ne bougent le stock, la compensation n'est jamais un encaissement ni un paiement déclaré, les deux documents restent liés dans les deux sens, et un ticket déjà transformé, repris en partie par un avoir ou qui n'est pas entièrement payé ne se transforme pas (il s'encaisse d'abord). Un devis n'est jamais figé : son PDF rappelle le moteur avec ses faits et les règles du jour de son émission ; l'envoi d'un devis dont une condition d'exonération manque avertit sans refuser ; une facture issue d'un devis reprend ses réponses et son choix de régime, revérifiés le jour de la facture. Seul un devis brouillon jamais numéroté se supprime ; un devis brouillon dont la validité est déjà dépassée ne s'envoie pas ; un devis envoyé dont la validité est dépassée est traité comme expiré dès son échéance et expire automatiquement ; un devis repassé en brouillon perd sa date d'émission, et son PDF l'indique ; modifier un devis envoyé ou accepté le repasse en brouillon et efface son acceptation ; un devis refusé, expiré ou converti reste figé.

Changements : copie du client figée étendue (assujetti, organisme public) ; « Toute facture exige le nom et l'adresse du client » (O5) ; phrase de la décision figée ; phrase de l'avoir (compatibilité et mentions de son jour) ; facture demandée (complément 13) ; « Transformer en facture » (compléments 14 et 15, facture construite avec les faits figés du ticket, avoir qui cite le ticket et la facture) ; phrase du devis (moteur, avertissement à l'envoi, O8).

### 2.7 « Prix et TVA des ventes »

> - Prix et TVA des ventes : un seul moteur de TVA, dans le domaine `app/Domain/Tva`, décide de la nature d'opération et calcule tous les montants des ventes et des achats ; les écrans, la caisse, l'API, le stock et les déclarations l'appellent, et aucun ne refait un calcul ; il ne lit ni la base ni l'horloge ; les règles qui changent avec le temps (taux, mentions et notes de calcul, catégories, preuves attendues, seuils, géographie, récupération de la TVA des charges) sont des données datées et versionnées, une donnée changée sans nouvelle version fait échouer la suite, et chaque décision garde la version du moteur et celle des règles ; un document émis relit sa décision figée, jamais le moteur. Seul le prix de vente TTC est un prix arrondi, une seule fois, quand la facture d'achat enregistre le produit. Le taux de TVA se saisit librement, 20 % par défaut (8,5 % pour un magasin en Guadeloupe, en Martinique ou à La Réunion), un taux inhabituel pour le territoire étant signalé sans être refusé ; une société redevable ne met jamais 0 % sur la fiche d'un produit ou d'un service, et l'émission refuse une ligne à 0 % vendue en France ; une ligne de produit prend le taux de sa fiche. Accorder une remise et fixer le prix d'un service (son prix de référence au catalogue comme celui d'une ligne) et le prix d'une ligne de produit différent de sa fiche exigent l'autorisation « accorder des remises et fixer le prix d'un service », que le propriétaire et les responsables du magasin ont toujours, précochée pour les administrateurs, jamais pour les vendeurs et les observateurs, à qui le propriétaire ou un responsable du magasin peut la cocher, et qu'un expert-comptable n'a jamais ; changer le produit, la quantité ou le prix d'une ligne qui porte une remise ou un prix dérogatoire exige la même autorisation ; sans elle, une ligne de produit prend le PU TTC de sa fiche, une ligne de service le prix de référence de sa fiche, et un service sans prix de référence ne se vend pas. Une vente à un particulier se calcule depuis le prix TTC, article par article (PU HT et PU TTC au centime, multipliés par la quantité). Une vente à un professionnel : HT de la ligne = quantité × PU TTC ÷ (1 + taux), arrondi une fois ; TVA de chaque taux = total HT du taux × taux, arrondie une fois et répartie sur ses lignes par cumul, dans leur ordre : chaque ligne reçoit la TVA arrondie du cumul des HT jusqu'à elle, moins celle des lignes précédentes. Un retour partiel se recalcule comme la facture, par différence de cumul, le dernier retour soldant la ligne au centime. Une vente exonérée (export, livraison vers un DOM, livraison intracommunautaire) se paie au prix hors taxe, calculé de la même façon, et une prestation vendue sans TVA française (autoliquidation, hors Union) au prix de référence TTC du service ÷ (1 + taux), sans que ce soit une dérogation de prix. Un magasin en franchise en base vend à 0 % au même prix TTC, avec la mention « TVA non applicable, art. 293 B du CGI » ; un brouillon rouvert après le passage en franchise reprend le prix TTC du catalogue de ses produits et de ses services, à 0 %, avec une notification. Le régime de TVA de la société se lit à la date de chaque opération. Un achat se calcule depuis le PU HT du fournisseur : HT = quantité x PU HT, puis la TVA de chaque taux sur le total HT du taux, arrondie une fois et répartie sur ses lignes par cumul, comme pour une vente à un professionnel. Ensuite plus rien ne s'arrondit ni ne se recalcule : les documents affichent les montants tels qu'ils sont enregistrés. PILIQI ne gère ni cartes cadeaux ni bons d'achat (art. 256 ter du CGI) : aucune fonction, aucune particularité fiscale ni aucun moyen de paiement de ce type ; la notice dit qu'un magasin n'en vend pas par PILIQI, pas même comme un produit ordinaire ; le bon d'avoir du client comptoir n'en est pas un.

Changements : phrase d'ouverture (moteur unique, règles datées, décision figée) ; taux inhabituel signalé ; taux 0 refusé (TVA5) ; prix d'un service sans TVA française (TVA4) ; brouillon rouvert en franchise (complément 5) ; régime lu à la date de l'opération (O7) ; phrase du calcul d'un achat (6.1 c) ; phrase des cartes cadeaux (TVA9 = c), écrite comme une consigne. La phrase de R2 est celle du lot A4.

Soumis à 6.1 :
- 6.1 c, « puis la TVA de chaque taux sur le total HT du taux, arrondie une fois et répartie sur ses lignes par cumul, comme pour une vente à un professionnel » : texte de l'option 1 (recommandée, lettre de TVA13 = a). Option 2 : « puis la TVA facturée de chaque ligne sur son HT, arrondie une fois ; la TVA autoliquidée, sur le total HT de chaque taux, arrondie une fois et répartie par cumul ». Texte d'attente : la phrase actuelle (« puis la TVA sur ce HT »), remplacée au lot M5c.

### 2.8 « Ventes hors de France »

> - Ventes hors de France : le pays ne décide jamais seul, il déclenche une question. Un document n'a qu'une nature d'opération (vente en France, export hors UE expédié par le magasin, livraison vers un DOM, livraison intracommunautaire à un professionnel, prestation à un professionnel de l'UE en autoliquidation, prestation à un professionnel hors UE) ; une vente en France peut mêler produits et services ; dans une autre nature, une ligne qu'elle n'admet pas se déplace vers une nouvelle facture. Une nature autre que la vente en France n'exonère que si toutes ses conditions sont réunies, dont un n° de TVA de l'UE valide sur VIES le jour de l'émission, consultation conservée, et le n° de TVA de la société ; sinon la vente reste à la TVA française. Elle s'applique d'elle-même quand les réponses données dans le document la justifient (marchandises expédiées hors de France, adresse, date de livraison), avec le nouveau total affiché et un lien « Rester à la TVA française » ; elle est proposée avec un bouton pour une prestation à une entreprise établie hors de France ; encore proposée à l'émission, elle impose un choix dans la fenêtre d'émission, enregistré avec son auteur et l'instant ; personne ne force une nature dont une condition manque, et rester à la TVA française est toujours possible et tracé. « Les marchandises quittent-elles la France ? » se demande, sans réponse par défaut, pour un client établi hors du territoire de TVA français, un n° de TVA étranger ou une livraison hors de France, et pour tout client par le lien « Expédier hors de France ou livrer à une autre adresse ? » ; la réponse de la dernière facture au même client dans le magasin est proposée avec sa source et se confirme à l'émission. Un service au lieu fixé par la loi (art. 259 A du CGI) se demande s'il est exécuté en France dès qu'un fait international existe ; exécuté hors de France, il est refusé avec un message, comme une expédition d'un magasin des DOM hors de son département et une prestation à une entreprise établie dans un DOM. Une livraison antérieure à l'émission sans vérification VIES à sa date est signalée. La caisse ne propose jamais d'exonération. Une vente à distance (biens expédiés par le magasin vers un autre État membre à un particulier, ou à un client non assujetti sans n° de TVA valide) compte, au HT net des avoirs, dans un compteur de la société, année précédente et année en cours, avec des valeurs de départ saisies à l'arrivée dans PILIQI, franchise comprise ; un avertissement s'affiche dès 9 000 € ; toute expédition qui ferait dépasser 10 000 €, ou toute vente à distance quand l'année précédente les a dépassés ou que la société est inscrite au guichet OSS, est refusée tant que PILIQI ne gère pas l'OSS ; la vente au comptoir reste possible ; un vendeur ne voit que l'état du seuil. Le relevé du mois des livraisons intracommunautaires et des prestations autoliquidées, par n° de TVA du client, net des avoirs, se prépare dans chaque magasin et pour la société, qui le dépose elle-même. Les références des preuves d'export et de livraison sont enregistrées sur la facture, chacune avec son auteur (« établie par » obligatoire) ; une preuve saisie par erreur s'annule par une entrée tracée (auteur, date, motif) et ne compte plus ; les deux documents de transport d'une livraison intracommunautaire viennent de deux parties différentes, indépendantes du magasin et du client ; celles qui manquent sont rappelées. Détaxe : le ticket reste à la TVA française, on note le n° du bordereau, la TVA n'est régularisée qu'après le visa ; un article rendu après qu'un bordereau a été noté fait rappeler, à l'émission de l'avoir, d'annuler ou de rectifier ce bordereau dans PABLO, et le ticket l'indique jusqu'à ce que la suite soit notée.

Changements : puce réécrite (TVA2, TVA3, TVA6, TVA7, TVA8, O3, O6, art. 259 A, compléments 4 et 6) ; la règle VIES du jour de l'émission (I2) et la détaxe (I3) restent.

### 2.9 « Paiements et avoirs »

> - Paiements et avoirs : le montant d'un paiement est égal au total imputé sur les factures. Un paiement erroné se corrige par une contre-passation (paiement opposé, lié à l'original et signé), puis par le bon paiement. Rien n'est jamais remboursé, ni sur un ticket ni sur une facture : un retour donne un crédit, imputé automatiquement d'abord sur ce qui reste dû des factures qu'il corrige, puis sur les autres factures impayées du client, de la plus ancienne à la plus récente (pour le client comptoir, seulement sur les tickets qu'il corrige) ; à la contre-passation, le crédit d'avoir disponible du client s'impute aussitôt de la même façon, dans la même transaction signée. Un avoir fait au client comptoir sert de bon d'avoir : son numéro permet d'imputer son crédit sur une prochaine vente au comptoir. L'avoir d'annulation d'un ticket transformé en facture n'est jamais un bon d'avoir : son crédit s'impute en totalité, dans la transaction de la transformation, sur la facture qui remplace le ticket, avant tout autre crédit ; c'est la seule imputation entre deux clients (le ticket est au client comptoir, la facture au particulier). Un retour ou un avoir sur un ticket de caisse exige l'autorisation « faire un retour ou un avoir sur un ticket », qui suffit seule, que le propriétaire et les responsables du magasin ont toujours, précochée pour les administrateurs, jamais pour les vendeurs et les observateurs, à qui le propriétaire ou un responsable du magasin peut la cocher, et qu'un expert-comptable n'a jamais ; l'autorisation de créer des avoirs reste celle des avoirs sur les factures. Une facture en retard le reste tant qu'un montant reste dû après l'échéance. Seule exception à cet ordre d'imputation : le crédit d'un avoir de régularisation (procédure exceptionnelle de l'éditeur) s'impute d'abord sur la facture qu'il régularise, puis sur sa facture rectificative ; cette imputation n'est jamais déclarée comme un encaissement.

Changements : l'ordre d'imputation écrit tel que le code l'applique déjà (`ImputerAvoirSurSesFacturesAction` : d'abord les factures corrigées, puis les autres de la plus ancienne à la plus récente) ; réimputation à la contre-passation selon le même ordre (complément 1) ; « ni sur un ticket ni sur une facture » (complément 16) ; avoir d'annulation d'un ticket transformé, imputé sur sa facture de remplacement (compléments 14 et 15) ; autorisation des retours sur tickets, suffisante seule (complément 16).

### 2.10 « Demandes répétées »

> - Demandes répétées : chaque émission, vente à la caisse, émission de devis, paiement, contre-passation, avoir, transformation d'un ticket en facture, envoi, fermeture de caisse, clôture du jour et validation d'achat ou de charge d'un magasin porte un jeton unique, lié au contenu de la demande (celui de la caisse est celui de son panier) ; une demande répétée après une réponse perdue renvoie le résultat déjà enregistré, sans doublon ; le même jeton avec une demande différente est refusé ; la validation et la correction d'une charge commune et la saisie des salaires, gestes de la société, sont protégées par leur état et le verrou de leur ligne : une demande répétée rend le résultat déjà enregistré, sans rien réécrire ; les autres gestes sont protégés par leur état ou une contrainte d'unicité.

Changements : « transformation d'un ticket en facture » et « ou de charge d'un magasin » ajoutés ; phrase des gestes de la société (charges communes, salaires).

### 2.11 « Achats et stock »

> - Achats et stock : les nouveaux produits d'une facture d'achat ne sont créés qu'à sa validation, qui exige alors aussi le droit de créer des produits ; la correction d'une fiche de produit existante depuis une ligne d'un brouillon (prix de vente TTC, taux de TVA de vente) est immédiate, son changement de taux inscrit au journal des événements. Le taux facturé d'une ligne d'achat ne modifie jamais le taux de vente du produit. Le fournisseur d'un produit est celui de son dernier achat validé, même saisi à la main. Le moteur de TVA décide de la nature de chaque ligne d'achat d'après l'établissement du fournisseur, l'origine réelle des marchandises, le préfixe de son n° de TVA, le dédouanement, le type de ligne (produit, frais d'acheminement, charge) et le régime de la société à la date de l'achat, jamais d'après le seul pays du fournisseur ; un fait contradictoire bloque la validation, jamais un choix silencieux. L'origine des marchandises s'affiche dès qu'il y a une ligne de biens (produit, ou charge portant sur un bien) : « France » d'office pour un fournisseur établi en France, sinon celle de la dernière facture validée du même fournisseur dans le magasin, sinon son pays à confirmer ; pour un fournisseur non établi, elle se confirme à sa première facture et à chaque changement. La facture d'achat donne la TVA facturée, la TVA autoliquidée, la TVA déductible, le montant à payer au fournisseur, la TVA à payer à l'État et le coût d'entrée en stock. La TVA autoliquidée (acquisition intracommunautaire, livraison ou service d'un fournisseur non établi) se calcule sur le total HT de chaque taux, arrondie une fois et répartie par cumul sur ses lignes ; la TVA d'une importation se reprend de la déclaration en douane, contrôlée sans être recalculée ; une société en franchise ne déduit rien, et ses achats de marchandises dans l'Union et ses importations sont refusés tant que leur traitement n'est pas confirmé par son expert-comptable, ses services étrangers étant autoliquidés sans déduction. Une TVA étrangère facturée par un fournisseur de l'Union est notée à part, « à faire rectifier ou rembourser », ni déductible ni dans le coût, et la facture entre dans une liste à régulariser. Une facture en devise porte le taux de change saisi avec sa source (celui de la déclaration en douane pour une importation) ; ses montants se calculent en euros depuis le HT de chaque ligne en devise × taux, arrondi une fois au centime, puis comme pour un achat en euros. Un achat que le moteur ne calcule pas seul (autoliquidation interne, service à l'étranger au lieu fixé par la loi, opération triangulaire, accises, achat d'un magasin des DOM venu d'ailleurs que de son département, fournisseur de l'Union en franchise ou sans n° de TVA, importation d'un bien qui n'entre pas en stock) se saisit à la main, nature et montants de TVA, avec l'autorisation « saisir à la main la TVA d'un achat ou d'une charge », que le propriétaire et les responsables du magasin ont toujours, jamais précochée pour les autres, qu'un expert-comptable n'a jamais, et un motif obligatoire inscrit au journal d'audit ; cette saisie reste acquise quand un autre geste recalcule la facture (déclaration en douane complétée, frais rattachés), et une modification de ses lignes exige une nouvelle saisie. Le coût d'entrée en stock d'une ligne de produit est son coût net (PU HT, remise et escompte déduits, au dix-millième) plus sa quote-part des frais d'acheminement et des droits de douane, répartis au prorata du HT net des lignes de produits, par cumul, au centime, frais d'un transporteur ou d'un transitaire rattachés à la facture des marchandises du même magasin compris, plus sa TVA non déductible ; il est figé à la validation sur la ligne d'achat, et le mouvement de stock n'en porte aucun. Une marchandise importée se reçoit et se vend avant sa déclaration en douane, avec un coût provisoire et une liste « Dédouanement à compléter » ; compléter la déclaration ou rattacher des frais corrige le coût par le rejeu du grand livre, jamais après un avoir fournisseur émis sur la facture. Modifier une facture d'achat validée exige l'autorisation de valider ; chaque validation et chaque modification fige la décision de TVA et s'inscrit au journal d'audit avec l'ancienne. Un retour au fournisseur sort au coût d'entrée de la ligne d'achat retournée, rend sa TVA par différence de cumul sans jamais rendre plus que la part de la ligne, et recalcule le coût moyen ; la valeur d'un stock ne descend jamais sous 0 € ; la valeur du stock affichée est son coût, sans TVA ajoutée (TVA non déductible comprise pour une société en franchise). Une vente au-delà du stock disponible est refusée, sauf pour une personne autorisée à « vendre sans stock » dans ce magasin (le propriétaire et les responsables du magasin le sont toujours ; pour les autres, cette autorisation n'est jamais précochée, et un expert-comptable ne l'a jamais) : elle voit un avertissement avant de valider, qui se renouvelle si le stock a encore baissé, une alerte de stock part, et la quantité négative reste visible jusqu'à sa régularisation par un achat, une réception ou un inventaire ; ces ventes sont journalisées, et des ventes simultanées respectent ces règles sans jamais rendre le stock incohérent.

Changements : compléments 2 et 3 ; taux facturé distinct du taux de vente ; moteur des achats (TVA3, TVA10, TVA11, TVA12, TVA13, TVA14, TVA15, TVA16) ; saisie manuelle gardée par un recalcul ; décision figée et journal d'audit ; retour au coût d'entrée ; O1 ; lignes de charge, origine demandée pour une charge portant sur un bien et importation d'un tel bien en saisie manuelle (décision des charges). La phrase de R1 est celle du lot A4.

Soumis à 6.1 :
- 6.1 d1 (frais), « répartis au prorata du HT net des lignes de produits, par cumul, au centime » : texte de l'option 1 (recommandée) ; option 2 : « répartis au prorata du HT net des lignes de produits, chaque quote-part arrondie au centime, l'écart d'arrondi porté sur la ligne au plus grand HT » ; texte d'attente : « répartis au prorata du HT net des lignes de produits », complété au lot M5c.
- 6.1 d2 (devises), « depuis le HT de chaque ligne en devise × taux, arrondi une fois au centime, puis comme pour un achat en euros » : texte de l'option 1 (recommandée) ; option 2 : « depuis les totaux de la facture en devise × taux, arrondis une fois, puis répartis sur les lignes par cumul » ; texte d'attente : « depuis les montants en devise convertis au taux saisi », complété au lot M5e.
- 6.1 c : « La TVA autoliquidée … répartie par cumul sur ses lignes » est décidée (TVA13) ; la TVA facturée suit la phrase du calcul d'un achat de 2.7.

### 2.12 « Charges du magasin » (puce nouvelle, après « Achats et stock »)

> - Charges du magasin : une charge est une facture d'un fournisseur ou d'un organisme dont les lignes n'entrent pas en stock, chacune avec une catégorie (loyer, électricité et eau, assurances, téléphone et Internet, logiciels, entretien, frais bancaires, transport, autres charges), précisée pour le loyer, l'entretien, le transport et les autres charges ; elle se saisit depuis le HT de chaque ligne et son taux, comme tout achat, avec la date de la pièce, qu'exige aussi une facture de marchandises qui porte une ligne de charge, si utile la période couverte, et la nature de la pièce (facture au nom de la société ou du magasin, ou ticket ou reçu) ; elle passe par le même moteur de TVA, la même validation, le même jeton et le même journal d'audit que les achats ; une ligne de charge peut aussi figurer sur une facture de marchandises. Le moteur décide de la TVA déductible (récupérable) de chaque ligne et dit pourquoi : aucune sur les salaires, ni sur les primes d'assurance et les opérations bancaires exonérées, qui ne s'autoliquident pas non plus quand un prestataire non établi en France les facture ; sur un loyer de locaux, seulement si le bailleur facture la TVA ; aucune sur les dépenses exclues du droit à déduction (transport de personnes, sauf le transport collectif du personnel sous contrat permanent ; véhicules de tourisme, leur location, leur entretien, leurs péages et parkings ; logement des dirigeants et du personnel, sauf le logement sur place du personnel de gardiennage, de sécurité ou de surveillance et le logement refacturé ; cadeaux de plus de 73 € TTC par bénéficiaire et par an, frais d'emballage et d'envoi compris) ; 80 % sur le gazole, l'essence et le superéthanol E85 d'un véhicule de tourisme, 100 % sur son électricité et son GNV ; aucune sur un ticket ou un reçu qui n'est pas une facture au nom de la société ou du magasin ; rien pour une société en franchise ; une règle encore à confirmer par l'expert-comptable de la société est signalée comme telle. Le moteur suppose un droit à déduction total : une société qui déclare aussi des opérations exonérées ou hors du champ de la TVA voit chaque charge signalée « à confirmer », sans coefficient calculé. À l'étape 5, la date de déduction d'un service acheté en France suivra son paiement, sauf option du fournisseur pour les débits ; d'ici là, les totaux donnent le droit à déduction ouvert par les pièces, jamais le montant à porter tel quel sur une déclaration. Une charge ne se valide qu'avec sa pièce justificative ; chaque pièce (PDF, JPEG, PNG, ou XML d'une facture électronique UBL ou CII, de 10 Mo au plus, type contrôlé sur son contenu) est une copie de consultation : la société conserve l'original papier ou la facture électronique reçue pendant 10 ans, ce que l'écran et la notice disent ; la pièce se range chiffrée par magasin, ou par société pour une charge commune, jamais visible d'un autre magasin ni d'une autre société, se conserve 10 ans et ne se retire que d'un brouillon, sauf une pièce déposée par erreur, qui se remplace avec un motif même sur un document validé, un document validé gardant toujours au moins une pièce ; chaque ajout, retrait et remplacement s'inscrit au journal d'audit. Une charge validée ne se modifie que par une correction tracée, avec l'autorisation de valider. Une charge commune se saisit dans l'espace de la société par le propriétaire ou un administrateur autorisé pour des magasins réels tous dans sa portée et ouverts ; elle se répartit à parts égales ou selon des pourcentages, chaque montant de chaque ligne au centime par cumul, sans écart ; sa pièce reste à la société ; chaque magasin voit sa part dans ses charges et ses totaux ; son détail et sa pièce se lisent en lecture seule depuis le panneau d'un magasin qui en a une part, par le propriétaire, les experts-comptables et un administrateur dont l'autorisation couvre tous les magasins de la répartition. Les salaires sont des totaux mensuels par magasin (salaires bruts et cotisations patronales), avec pour pièce un récapitulatif totalisé de l'établissement, sans détail par salarié, ni bulletin ni journal de paie détaillé ; ils se saisissent dans l'espace de la société par le propriétaire et les administrateurs autorisés, pour les magasins de leur portée, se voient d'eux et des experts-comptables, jamais des responsables ni des autres membres. Voir, saisir et valider les charges d'un magasin sont trois autorisations de magasin, que le propriétaire et les responsables du magasin ont toujours, précochées pour les administrateurs, jamais pour les vendeurs et les observateurs, à qui le propriétaire ou un responsable du magasin peut les cocher ; un expert-comptable ne fait que les consulter et télécharger leurs pièces. Les totaux du magasin se donnent par mois de la pièce et par catégorie (HT, TVA déductible ouverte par les pièces, TTC), charges validées et parts des charges communes comprises, et salaires compris pour qui peut les voir. Les factures de charges reçues par SUPER PDP (étape 2) s'importeront en brouillon de charges, la facture reçue jointe comme pièce.

Changements : puce nouvelle. Les règles de déduction sont celles de 4.3, sourcées ; les trois restrictions provisoires des charges communes (6.1 e) n'y figurent pas.

Soumis à 6.1 :
- 6.1 d3 (TVA déductible partielle), après « 80 % sur le gazole, l'essence et le superéthanol E85 d'un véhicule de tourisme » : option 1 (recommandée) « (TVA de la ligne × 80 %, arrondie une fois au centime, la moitié en s'éloignant de zéro) » ; option 2 « (80 % du total de la TVA de la pièce soumise à ce pourcentage, arrondi une fois et réparti sur ses lignes par cumul) » ; texte d'attente : rien, complété au lot M5c.
- 6.1 d4 (charges communes), « chaque montant de chaque ligne au centime par cumul, sans écart » : texte de l'option 1 (recommandée) ; option 2 : « chaque part arrondie au centime, l'écart d'arrondi porté sur la plus grande part » ; texte d'attente : rien (la phrase s'arrête à « selon des pourcentages »), complétée au lot CH5.
- 6.1 f : si l'éditeur retient les charges récurrentes, le lot CH3 ajoute, après la phrase de la correction tracée, « Une charge récurrente (loyer, abonnement) a un modèle qui crée son brouillon chaque mois, à compléter et valider. » ; sinon rien.
- 6.1 e : pour chaque restriction que l'éditeur garde (option 1), le lot CH5 (ou, si la réponse vient après, un lot d'amendement) ajoute, après « ouverts », la phrase correspondante : « , d'un même territoire de TVA » ; « un organisme non établi en France n'y facture qu'un service » ; « un cas que le moteur ne calcule pas seul se saisit dans un magasin » ; sinon rien.
- 6.1 a : si l'éditeur retient l'option 1, le lot d'amendement écrit dans « Société ou magasin suspendu… » que les charges, les salaires et les parts se saisissent encore dans un magasin fermé par la société.

### 2.13 « Caisse et clôtures »

Une phrase s'ajoute, juste après « Toutes les ventes et leurs règlements entrent dans les clôtures et les grands totaux. » :

> Un ticket transformé en facture garde sa journée : l'avoir d'annulation et la facture qui le remplace sont de la journée de la transformation, dont le total des ventes aux particuliers ne change pas.

Le reste de la puce ne change pas.

### 2.14 « Facture électronique (SUPER PDP) »

> - Facture électronique (SUPER PDP) : chaque société a une seule connexion, faite par son propriétaire, à l'entreprise SUPER PDP de son SIREN, et chaque magasin son adresse de réception (SIREN_SIRET) ; une facture reçue est attribuée automatiquement au magasin que désignent avec certitude son SIRET destinataire, son adresse électronique de facturation ou le code de routage de l'établissement ; l'adresse physique et les références du magasin ne servent qu'à proposer une correspondance ; seule une facture dont le destinataire exact est incertain ou dont les informations se contredisent entre dans la liste « À attribuer » de la société, dont le propriétaire et les administrateurs autorisés sont alertés ; chaque magasin ne voit que les factures qui lui sont attribuées, une facture n'est jamais importée dans deux magasins, et chaque attribution ou correction est journalisée ; refuser ou contester une facture reçue : au propriétaire et aux administrateurs autorisés. Les factures reçues s'importent en brouillon, l'entrée en stock reste une validation manuelle, un produit inconnu est créé, les cas non gérés (quantités décimales, unités de poids ou de volume, remises de pied de facture) passent en saisie manuelle ; un fournisseur est reconnu par son SIRET ; une facture de charges reçue s'importe en brouillon de charges, la facture reçue jointe comme pièce justificative, et sa catégorie se choisit avant la validation. Le circuit de chaque document est calculé par le moteur et figé à son émission : facture électronique pour une opération entre assujettis établis en France, dans le champ de la TVA française et soumise aux règles de facturation françaises ; e-reporting des ventes aux particuliers pour les particuliers, en France ou à l'étranger, facture demandée comprise, et pour les professionnels non assujettis ; e-reporting international pour les professionnels établis hors de France, à Monaco, en Guyane, à Mayotte ou dans une collectivité d'outre-mer, et pour une prestation située hors de France vendue à un assujetti (sens « out »), ainsi que pour les achats auprès de fournisseurs non établis : acquisitions intracommunautaires, livraisons et services autoliquidés, facture par facture (sens « in ») ; Chorus Pro, hors de PILIQI, avec un message, pour un client public ; aucun pour une importation et pour le magasin de démonstration. Les flux lisent les décisions figées sans rien recalculer : la catégorie et le motif d'exonération de chaque ligne se déduisent de la nature, de la franchise et du type de ligne, avec la version des règles de la décision. Le statut « encaissée » d'une facture de services part à chaque paiement, avec le montant et la date, sauf option pour les débits ; les paiements de particuliers pour des services ne sont déclarés qu'une fois la règle confirmée par l'expert-comptable de la société. Une catégorie de TVA n'est transmise qu'une fois prise en charge par la passerelle et vérifiée au bac à sable, le taux normal d'abord ; sinon la facture reste hors plateforme, avec un message. Le crédit d'avoir compte dans le montant déjà payé (« dont X € imputés de l'avoir N »). Les ventes aux particuliers sont déclarées par clôture journalière ayant des opérations, suivies jusqu'à l'accusé de réception, journées manquées rattrapées. Les factures émises avant la connexion se transmettent par un bouton, sans rattrapage des clôtures antérieures. Une facture rejetée est redéposée sous le même numéro en ne corrigeant que les données de transmission ajoutées hors du document signé ; une erreur dans le document lui-même se corrige par un avoir et une nouvelle facture.

Changements : brouillons de charges reçus ; phrase du circuit (compléments 7, 9, 10 ; O2) à la place de « Les factures aux clients publics et aux professionnels non assujettis restent hors plateforme, avec un message » ; phrase des flux ; « Une vente à 0 % n'est pas transmise tant que son motif d'exonération n'est pas géré » devient la phrase des catégories prises en charge (E13 généralisée).

### 2.15 « Journal d'audit »

> - Journal d'audit : les opérations importantes (factures, paiements, autorisations, procédures et réglages de la société, validations, modifications et saisies manuelles de TVA des achats et des charges avec la décision d'avant, charges communes, salaires, ajouts, retraits et remplacements de pièces justificatives) s'inscrivent au journal d'audit de la société, en ajout seul, chaîné par magasin et par société et protégé contre toute modification, consultable par le propriétaire seul ; chaque catégorie se conserve selon l'obligation légale qui la concerne, sans donnée personnelle inutile ; l'adresse IP et le navigateur, du journal d'audit comme du journal des événements de la caisse, sont rangés à part, jamais dans un contenu signé, et effacés après 1 an.

Changements : la liste des opérations importantes s'allonge.

### 2.16 Puces inchangées

« Société ou magasin suspendu, fermé, résilié ou révoqué », « Vérification de la société », « Vendeurs », « E-mails » et « Outillage » ne changent pas. Les tables datées du moteur sont des données PHP du domaine `Tva`, sans paquet ; les noms de pays viennent de l'extension `intl`, déjà exigée par Filament : « Outillage » reste juste.

### 2.17 Feuille de route

Les lignes « Fait » ne changent pas. Les lignes suivantes remplacent celles du même numéro (la ligne 1 garde le texte de la chaîne société jusqu'à « caisses personnelles fermées d'office) », puis s'allonge) :

> 1. [ ] Corrections : ventes toujours au prix TTC et achats toujours au prix HT, retours partiels cumulés, défauts de l'audit complet, décisions de l'éditeur qui ne relèvent ni de SUPER PDP ni de la fin de la caisse sécurisée, dont l'architecture société → établissements → membres (inscription par lien, vérification de la société et magasin de démonstration, espace d'administration de la société, réglages communs de la société, administrateurs créés par le propriétaire, responsables nommés par lui ou par un administrateur autorisé, vendeurs et observateurs créés par le propriétaire ou le responsable de leur magasin, chacun affecté à un ou plusieurs magasins par le propriétaire, experts-comptables de la société créés par le propriétaire, autorisations propres à PILIQI avec leur référentiel en base, journal d'audit de société, fermeture et réouverture des magasins, procédures de l'éditeur) et les règles R1 à R5 (vente sans stock, remises et prix, demandes répétées, répartition de la TVA, caisses personnelles fermées d'office) ; puis le moteur de TVA centralisé des ventes et des achats, l'espace « Charges du magasin » (pièces justificatives, charges, salaires, charges communes) et les compléments (réimputation à la contre-passation, droit de créer des produits, preuves, détaxe, retours sur tickets, facture demandée, « Transformer en facture »), dans l'ordre du plan d'exécution : M0, C-a, M1, M2, M3, C-b, M4, M8, M5a, M5b, M5c, M5d, M5e, M6, CH1, CH2, CH4, CH5, M7, FIN. L'étape 1 n'est cochée qu'au lot FIN.
> 1 bis. [ ] Campagne de tests initiale, après l'architecture société, les lots de décisions 14 à 21, le moteur de TVA, l'espace des charges et les compléments : environnement isolé, sociétés fictives (par société 1 propriétaire, au moins 1 administrateur et 1 expert-comptable, par magasin au moins 1 responsable et 2 vendeurs), concurrence réelle sur PostgreSQL (dont ventes hors de France, achats étrangers, charges, charges communes et salaires pendant des ventes, des fermetures de magasins et des changements de droits), sur cette machine au plus grand volume qu'elle tient (au-delà, résultats signalés comme extrapolés), d'abord avec la configuration actuelle puis avec Redis ; chaque anomalie classée et corrigée par Claude avec un test qui la reproduit, le rapport montrant chaque classement, que l'éditeur peut contester, l'éditeur ne validant que le déclassement d'une anomalie bloquante ; preuves publiées après chaque palier dans une page privée, avec leur empreinte enregistrée dans le dépôt. La campagne est relancée à la fin des étapes 2, 3, 5, 9 et 13 ; une campagne finale, sur une machine dédiée au volume complet, précède la mise en production (étape 15).
> 2. [ ] Intégration SUPER PDP : *(texte actuel conservé jusqu'à « …encaissements. »)*, e-reporting international (ventes à des professionnels établis hors de France, sens « out », et achats à des fournisseurs non établis, sens « in », facture par facture, flux b2bint), le circuit de chaque document lu tel que le moteur l'a figé (trois conditions de la facture électronique, assujetti, organisme public), les catégories et motifs d'exonération déduits des décisions figées sans rien recalculer, chaque catégorie transmise seulement après sa vérification au bac à sable ; couverture de tous les cas de l'art. 290 I du CGI (Monaco dans les deux sens, achats à un fournisseur monégasque compris, acquisitions de l'art. 258 D I), opérations des art. 261 à 261 E refusées ou signalées, achats exonérés à un prestataire non établi (assurance, opération bancaire) laissés hors e-reporting comme elles, sauf avis contraire de l'expert-comptable de la société ; absence d'opération appréciée par SIREN, pour la société ; les factures de charges reçues s'importent en brouillon de charges avec la facture reçue pour pièce, gardée sous sa forme électronique ; factures de fournisseurs étrangers reçues par Peppol vérifiées au bac à sable (réception, attribution, e-reporting) pour ne jamais être déclarées deux fois ; SUPER PDP tient le calendrier de l'e-reporting : PILIQI envoie chaque clôture journalière ayant des opérations dès qu'elle est faite, une seule fois, corrige une clôture déjà transmise par une entrée négative, déclare le régime de TVA et l'option pour les débits de la société, affiche les statuts, et supprime son calendrier interne. *(Suite du texte actuel conservée, de « Plateforme agréée retenue par l'éditeur… » à « …la personne qui s'est connectée. »)* Puis le lot M9 du moteur de TVA : guichet OSS des ventes à distance et régimes propres aux DOM ; et, si l'éditeur le confirme, export emporté par le client, acquisitions d'une société en franchise (après confirmation de son expert-comptable), marqueur des services immatériels. Puis relance de la campagne de tests.
> 3. [ ] Caisse sécurisée (art. 286 CGI), fin : justificatifs et duplicatas produits depuis le contenu signé, clôtures journalières, mensuelles et annuelles calculées depuis le registre avec totaux perpétuels, comprenant toutes les ventes, factures aux professionnels comprises, et regroupant chacune par circuit, archives signées, attestation de l'éditeur. *(Suite du texte actuel conservée.)* La notice dit aussi qu'un magasin ne vend pas de cartes cadeaux ni de bons d'achat par PILIQI, pas même comme un produit ordinaire, et qu'une pièce justificative déposée est une copie de consultation dont la société conserve l'original. Puis relance de la campagne de tests.
> 4. [ ] Journal d'audit des actions importantes *(texte actuel conservé)* ; les validations et modifications des achats et des charges, les charges communes, les salaires et les ajouts, retraits et remplacements de pièces justificatives y sont inscrits depuis l'étape 1.
> 5. [ ] Comptabilité : journaux (ventes, achats, caisse et banque), écritures générées depuis chaque document et chaque charge (charges du magasin, parts des charges communes, salaires), plan de comptes de la société (comptes proposés pour les catégories de charges, à valider par son expert-comptable), journaux par magasin, export comptable de la société (FEC), produit et téléchargé par un expert-comptable ou le propriétaire, chaque export inscrit au journal d'audit, état de TVA (collectée, déductible, autoliquidée, à payer) lu sur les décisions figées des ventes, des achats et des charges par des rubriques reliées aux lignes de la déclaration CA3 par une table versionnée par millésime de notice, exigibilité et date de déduction des achats et des charges (services achetés en France au paiement, sauf option du fournisseur pour les débits ; acquisitions intracommunautaires le 15 du mois suivant la livraison, ou à la date de la facture si elle est antérieure (art. 269-2 d du CGI) ; services autoliquidés à leur réalisation, à confirmer par l'expert-comptable de la société), coefficient de déduction d'une société qui réalise aussi des opérations exonérées ou hors du champ laissé à son expert-comptable, blocage de l'export d'une période tant qu'une déclaration en douane y manque. Puis relance de la campagne de tests.
> 6. [ ] Tableau de bord et rapports : chiffre d'affaires, marges, TVA, meilleurs produits, stock, impayés, charges du magasin et de la société (totalisées par magasin depuis l'étape 1) ; résultat par magasin, qui comprend les salaires, visible seulement de qui voit les salaires de ce magasin ; pour les autres, une marge avant salaires.
> 7. [ ] Stock : inventaire (ouverture et comptage), ajustements et sorties hors vente (démarque, vol, casse, prélèvement, don) avec leur décision de TVA (reversement de la TVA déduite ou dispense), catégories, unités, import d'un catalogue (reprise d'un établissement par une autre société).
> 8. [ ] Pièces jointes : le mécanisme des pièces justificatives, isolé par magasin et par société, existe depuis l'étape 1 pour les achats, les charges, les charges communes et les salaires ; l'étape 8 l'étend aux autres documents et fiches (preuves d'export et de livraison, clients, fournisseurs) ; contacts des clients et fournisseurs, coordonnées bancaires des fournisseurs.
> 9. [ ] RGPD : export et anonymisation des clients, durées de conservation (documents légaux et pièces justificatives conservés à part, récapitulatifs de paie compris), totaux de salaires d'un magasin de peu de salariés traités comme données personnelles des salariés (registre des traitements, information des salariés), sort des sociétés jamais validées et de leurs données de démonstration, analyse d'impact de la vérification d'identité tenue à jour. Puis relance de la campagne de tests.

Les lignes 10 à 15 ne changent pas. Au lot FIN, la ligne 1 passe à `[x]` et sa fin (« dans l'ordre du plan d'exécution : … L'étape 1 n'est cochée qu'au lot FIN. ») est retirée.

Changements : ligne 1 (moteur, charges, compléments, ordre des lots, coche au lot FIN) ; ligne 1 bis (moteur, charges, concurrence) ; ligne 2 (e-reporting international, circuit figé, catégories, compléments 7, 10, 11 et 12, achats exonérés, brouillons de charges, lot M9 : l'OSS et les régimes des DOM y sont décidés, TVA8 et O6 ayant écarté « jamais ») ; ligne 3 (clôtures, notice) ; ligne 4 ; ligne 5 (écritures, état de TVA, exigibilité complète de l'art. 269-2 d, services autoliquidés à confirmer, coefficient laissé à l'expert-comptable) ; ligne 6 (charges, résultat qui ne révèle pas les salaires) ; ligne 7 (sorties hors vente) ; ligne 8 (pièces) ; ligne 9 (conservation des pièces et des récapitulatifs de paie, salaires d'un petit effectif).

Soumis à 6.1 :
- 6.1 f : si l'éditeur retient les charges récurrentes, le lot CH3 ajoute « charges récurrentes » dans la parenthèse de l'espace « Charges du magasin » de la ligne 1, et « CH3 » à l'ordre, après « CH2 » ; sinon rien.
- 6.1 b : si l'éditeur retient l'option 1, le lot CH6 ajoute « avoirs reçus sur des charges » dans cette parenthèse, et « CH6 » à l'ordre, après « CH5 » ; sinon rien.
- 6.1 g : si l'éditeur retient la numérisation fiscale, la ligne 15 gagne « numérisation des pièces justificatives conforme à l'art. A102 B-2 du LPF (copie fidèle, cachet serveur ou signature électronique, organisation documentée), qui permet de détruire les originaux papier » ; sinon rien.

---

## 3. Moteur de TVA : conception de référence

Cette section est la conception que les lots appliquent. Les lots disent ce qu'ils en livrent ; tout ce qui n'est pas dit dans un lot reste à faire dans le lot qui le cite.

### 3.1 Principes

| # | Principe | Conséquence |
|---|---|---|
| P1 | Un seul moteur. | L'aperçu à l'écran, le contrôle à l'émission ou à la validation, le figement, le coût du stock et les déclarations lisent la même décision ; les écrans ne gardent aucune règle fiscale ni aucun calcul. |
| P2 | Un moteur pur et déterministe. | Aucun accès à la base, aucune horloge implicite (la date de l'opération est un fait), aucune lecture de configuration ; les mêmes faits donnent toujours la même décision. |
| P3 | On corrige les faits, jamais le résultat. | L'utilisateur ne choisit pas une exonération : il complète un fait (n° de TVA, sortie des biens, date de livraison) ou fait un geste sur une nature dont toutes les conditions sont réunies. |
| P4 | Le pays déclenche une question, jamais une décision. | Toute nature autre que la vente en France ou l'achat en France exige des conditions cumulatives. |
| P5 | Valeur sûre par défaut. | Vente : TVA française tant que les conditions d'une autre nature ne sont pas toutes réunies. Achat : aucune origine supposée pour un fournisseur non établi. Service au lieu fixé par la loi exécuté hors de France : refus avec un message, jamais une TVA française facturée à tort. |
| P6 | Jamais de bascule silencieuse. | Une nature appliquée d'office affiche le nouveau total et le lien « Rester à la TVA française » ; une nature encore proposée à l'émission impose un choix, enregistré. |
| P7 | Bloquant ou avertissement. | Bloquant si, sans ce fait, la nature retenue est inapplicable, ou si un montant dû, payé ou déclaré serait faux au moment de l'acte ; avertissement si le fait se complète après sans changer le document. |
| P8 | Tous les manquements d'un coup. | Le refus liste tout (identité légale, SIREN et adresse du client compris) et arrive avant le contrôle du stock (R1). |
| P9 | Le moteur produit tous les montants, avec la calculatrice validée. | `CalculateurLigne` passe dans le domaine `Tva` sans changer ses règles ; aucune règle d'arrondi nouvelle sans décision de l'éditeur. Décidées : R4, K2, K3, et TVA13 pour la TVA autoliquidée et ses retours. Soumises à l'éditeur et appliquées selon sa réponse (6.1 c et d) : la TVA facturée d'un achat et ses retours, la répartition des frais et des droits, la conversion des devises, la TVA déductible partielle, la répartition des charges communes. |
| P10 | Un document émis ne relit plus jamais le moteur. | Il relit sa décision figée, comme il relit son émetteur signé. |
| P11 | Des règles en données datées et versionnées. | La version du moteur et celle des règles sont figées avec chaque décision. |
| P12 | Isolation. | Les faits viennent du magasin courant et de sa société ; les seules lectures hors du magasin sont celles de la puce 2 de « Produit ». |
| P13 | Aucun code à l'écran. | Des mots simples ; pour VIES, le n° de preuve donné par VIES et l'heure. |
| P14 | Une seule source par donnée. | Une donnée enregistrée l'est une fois : une colonne si elle est interrogée ou déjà existante, sinon la décision figée en JSON ; ce qui se déduit des faits figés et de la version des règles ne s'enregistre pas. |

### 3.2 Le domaine `app/Domain/Tva`

Un domaine à côté de `Achats`, `Catalogue`, `Contacts`, `Facturation`, `Stock`, `Tenancy` (le dossier `app/Domain` existe : ce n'est pas un dossier de premier niveau nouveau). `Tva` ne dépend d'aucun autre domaine, d'aucun modèle Eloquent ni d'aucun écran ; `Facturation`, `Achats`, `Stock`, `CaisseSecurisee` dépendent de `Tva`. Le nom évite la confusion avec le « module fiscal » de la caisse sécurisée (`ModuleFiscal`, art. 286).

```
app/Domain/Tva/
  MoteurTva.php                  le moteur (pur) ; const VERSION = '1.0'
  Calcul/                        CalculateurLigne (déplacée de Facturation, règles inchangées ; API en centimes ajoutée au lot M2),
                                 CalculDesLignesDeVente, CalculDesRetours (retours de vente K2, retours d'achat TVA13 et 6.1 c),
                                 CalculDesLignesDAchat, RepartitionParCumul (TVA autoliquidée, et selon 6.1 c et d : TVA facturée,
                                 frais et droits, parts des charges communes), BeneficeUnitaire
  Faits/                         objets d'entrée immuables (3.3)
  Decisions/                     objets de sortie immuables (3.7), dont RepartitionDUneChargeCommune (lot CH5) ;
                                 énumération CodeDeManquement (annexe B)
  Regles/                        CatalogueDesRegles, ReglesTva, TableDesTaux, TableDesMentions, TableDesCategories,
                                 TableDesPreuves, Seuils, TableDesCharges (lot M5c puis CH2), ExigenceDuNumeroTva,
                                 CategoriesPrisesEnCharge (lot M3), DevisesGerees (lot M5e)
  Geographie/                    GeographieTva, TerritoireDeTva
  Enums/                         NatureOperation (déplacée), NatureAchat, CategorieTva, Circuit, Redevable, Gravite, SortieDesBiens,
                                 LieuDesServices, Dedouanement, TypeDeLigne, TypeLigneAchat, RaisonDuTauxZero, ParticulariteFiscale,
                                 EtatDeLaDecision, MethodeDeCalcul, SourceDuFait, CategorieOperation (déplacée), CategorieCharge,
                                 PrecisionDeCharge, SourceDuTauxDeChange, TypeDocumentAchat, NatureDeLaPiece
  Exceptions/                    DecisionTvaRefuseeException, CalculDuBrouillonChangeException (porte l'avant et l'après)
```

Les collecteurs restent dans leur module, puisqu'ils lisent ses modèles : `app/Domain/Facturation/Services/CollecteurFaitsDeVente.php`, `app/Domain/Achats/Services/CollecteurFaitsDAchat.php`, `app/Domain/Contacts/Services/CollecteurFaitsDuTiers.php` (sous-dossier `Services` nouveau, comme dans les autres domaines), `app/Domain/Tenancy/Services/CollecteurFaitsDeLaSociete.php`.

Énumérations (valeurs en base, en minuscules avec soulignés) :
- `SortieDesBiens` : `non`, `expedies_par_le_magasin`, `emportes_par_le_client` ; nul : sans réponse.
- `LieuDesServices` : `france`, `hors_de_france` ; nul : non précisé.
- `TypeDeLigne` : `bien`, `service`.
- `TypeLigneAchat` : `produit` (entre en stock), `frais_acheminement` (entre dans le coût des produits), `charge` (n'entre ni en stock ni dans le coût ; catégorie obligatoire).
- `TypeDocumentAchat` : `marchandises`, `charges` (lot CH2).
- `NatureDeLaPiece` : `facture` (facture au nom de la société ou du magasin), `ticket_ou_recu` (lot M5c ; 3.17.1).
- `RaisonDuTauxZero` : `franchise`, `autoliquidation`, `importation`, `exoneree` (ligne de charge exonérée : loyer de locaux nus, prime d'assurance, opération bancaire ou financière ; posée d'office, et modifiable, pour les catégories Assurances et Frais bancaires, y compris quand un prestataire non établi en France les facture : une ligne exonérée n'est jamais autoliquidée, 3.17.2).
- `Dedouanement` : `nous`, `fournisseur`.
- `ParticulariteFiscale` : `aucune`, `accises`, `moyen_de_transport_neuf`. Aucune autre valeur (1.2).
- `Circuit` : `facture_electronique`, `e_reporting_international`, `e_reporting_particuliers`, `chorus_pro`, `aucun`.
- `EtatDeLaDecision` : `france`, `appliquee_d_office`, `appliquee`, `proposee`, `incomplete`, `bloquee`.
- `MethodeDeCalcul` : `article_par_article`, `ht_de_ligne`.
- `SourceDuFait` : `saisi`, `fiche`, `derniere_facture_validee`, `derniere_facture_emise`, `magasin`, `societe`, `derive`, `par_defaut`.
- `NatureAchat` : `achat_en_france`, `fournisseur_en_franchise`, `achat_aupres_de_monaco`, `acquisition_intracommunautaire`, `livraison_par_un_non_etabli`, `services_d_un_non_etabli`, `importation`, `saisie_manuelle`.
- `CategorieCharge` et `PrecisionDeCharge` : section 4.2 et 4.3.
- `SourceDuTauxDeChange` : `declaration_en_douane`, `facture_du_fournisseur`, `releve_bancaire`, `autre`.

### 3.3 Les faits (entrées du moteur)

Objets `final readonly`, comme `Emetteur` ou `CopieClient`. Montants en centimes entiers, taux en centièmes de point, coûts en dix-millièmes d'euro, dates en `CarbonImmutable` données par l'appelant. Chaque valeur garde sa source (`SourceDuFait`), affichée à l'écran et gardée dans la décision.

```php
// La société et le magasin, à la date de l'opération.
final readonly class FaitsDeLaSociete {
    public function __construct(
        public bool $enFranchise,                     // régime en vigueur à la date de l'opération (historique daté, lot M5a)
        public ?string $numeroTva,
        public bool $optionDebits,
        public TerritoireDeTva $territoireDuMagasin,  // métropole, Corse, Guadeloupe-Martinique-La Réunion
        public AdresseFiscale $adresseDuMagasin,
        public bool $estDemonstration,
        public array $identiteManquante,              // list<string> : identité de la société et du magasin, hors n° de TVA
        public ?CompteurDeSeuil $ventesADistance,     // lot M8
        public ?CarbonImmutable $inscriteOssLe,       // lot M8
        public bool $operationsHorsDroitADeduction,   // lot CH2 : la société déclare aussi des opérations exonérées ou hors du champ (4.3)
    ) {}
}

// Le client ou le fournisseur, tel que sa fiche (ou sa copie figée) le dit ce jour-là.
final readonly class FaitsDuTiers {
    public function __construct(
        public bool $estProfessionnel,                // toujours vrai pour un fournisseur
        public bool $assujetti,                       // vrai par défaut pour un professionnel
        public bool $entitePublique,
        public AdresseFiscale $adresse,               // pays et code postal → territoire
        public bool $etablissementEnFrance,
        public ?string $siren,
        public ?string $numeroTva,
        public ?VerificationVies $verificationVies,   // dernière vérification du jour, dans le magasin
        public ?string $identifiantEtranger,
        public bool $neFacturePasDeTva,               // fournisseur en franchise, en France ou dans son État
        public string $nom,                           // pour les messages
    ) {}
}

final readonly class FaitsDeVente {
    public function __construct(
        public CarbonImmutable $dateDeLOperation,     // instant du registre à l'émission ; instant de la requête pour un aperçu
        public FaitsDeLaSociete $societe,
        public FaitsDuTiers $client,
        public ?SortieDesBiens $sortieDesBiens,
        public ?SourceDuFait $sourceDeLaSortie,       // saisi, ou derniere_facture_emise tant que la proposition n'est pas confirmée
        public ?AdresseFiscale $livraison,
        public bool $livraisonComplete,               // adresse, code postal, ville et pays
        public ?CarbonImmutable $dateDeLivraison,
        public ?LieuDesServices $lieuDesServices,
        public ?GesteDeRegime $geste,
        public bool $factureDemandee,                 // lot C-b
        public array $lignes,                         // list<FaitsDeLigneDeVente>
        public array $decisionsDOrigine,              // pour un avoir : list<DecisionFigee>, une par facture corrigée
    ) {}
}

final readonly class FaitsDeLigneDeVente {
    public function __construct(
        public int $ordre,
        public TypeDeLigne $type,
        public bool $fraisAccessoires,                // Service::frais_accessoires
        public bool $lieuFixeParLaLoi,                // Service::lieu_specifique (art. 259 A)
        public ParticulariteFiscale $particularite,
        public int $tauxDeLaFiche,                    // produit : taux de vente ; service : taux de référence (R2)
        public ?int $prixTtcDeLaFiche,                // produit : prix de vente TTC ; service : prix de référence ; null : non fixé
        public ?int $prixTtcSaisi,                    // prix de ligne différent de la fiche, admis avec l'autorisation de R2 (contrôlée avant, par DerogationsDePrix)
        public int $quantite,
        public int $remise,                           // centièmes de point
        public string $libelle,
    ) {}
}

final readonly class FaitsDAchat {
    public function __construct(
        public CarbonImmutable $dateDeLOperation,     // date de la facture du fournisseur, sinon date de validation
        public FaitsDeLaSociete $societe,
        public FaitsDuTiers $fournisseur,
        public TypeDocumentAchat $typeDeDocument,     // lot CH2
        public ?OrigineDesMarchandises $origine,      // pays et code postal de départ réels, avec leur source
        public ?Dedouanement $dedouanePar,
        public ?DeclarationEnDouane $declarationEnDouane,
        public ?string $numeroFournisseur,
        public ?CarbonImmutable $dateDeLaFacture,     // date de la pièce
        public ?NatureDeLaPiece $natureDeLaPiece,     // lot M5c : facture au nom de la société ou du magasin, ou ticket ou reçu, avec sa source
        public int $escompte,                         // centièmes de point
        public ?int $totalDeControle,                 // total TTC de la facture du fournisseur, en euros, facultatif
        public string $devise,                        // lot M5e ; code ISO 4217, EUR par défaut
        public ?string $tauxDeChange,                 // décimal exact (8 décimales), lot M5e
        public ?SourceDuTauxDeChange $sourceDuTaux,   // lot M5e
        public array $lignes,                         // list<FaitsDeLigneDAchat>
        public array $fraisRattaches,                 // lot M7 : list<FraisRattache> (ligne de frais d'une autre facture validée, son HT en centimes)
        public bool $avoirFournisseurEmis,            // un avoir fournisseur émis fige le coût d'entrée
        public ?SaisieManuelle $saisieManuelle,       // lot M5e : celle de la demande, sinon celle reconstruite depuis la décision figée (3.4)
    ) {}
}

final readonly class FaitsDeLigneDAchat {
    public function __construct(
        public int $ordre,
        public TypeLigneAchat $type,
        public int $quantite,
        public int $prixUnitaireHt,                   // centimes, dans la devise de la facture
        public int $remise,                           // centièmes de point
        public int $tauxFacture,                      // tel que sur la facture du fournisseur
        public ?RaisonDuTauxZero $raisonDuTauxZero,
        public ?int $tauxDuEnFrance,                  // saisi ; sinon proposé par le moteur
        public ?int $tauxDeVenteDuProduit,            // proposition du taux dû en France pour une ligne de produit
        public bool $lieuFixeParLaLoi,
        public ParticulariteFiscale $particularite,
        public bool $rattacheeAUneAutreFacture,       // lot M7
        public ?CategorieCharge $categorie,           // ligne de charge seulement
        public ?TypeDeLigne $natureDeLaCharge,        // ligne de charge : bien ou service (proposé par la catégorie)
        public ?PrecisionDeCharge $precision,         // lot CH2
        public bool $seuilDesCadeauxConfirme,         // lot CH2 : case « le total des cadeaux à ce bénéficiaire cette année reste sous le seuil »
    ) {}
}

final readonly class AdresseFiscale { public function __construct(public string $pays, public ?string $codePostal) {} }
final readonly class OrigineDesMarchandises { public function __construct(public AdresseFiscale $adresse, public SourceDuFait $source) {} }
final readonly class VerificationVies {
    public function __construct(public string $numeroTva, public bool $valide, public ?string $numeroDePreuve, public CarbonImmutable $consulteeLe) {}
}
final readonly class DeclarationEnDouane {
    public function __construct(
        public ?string $reference,                    // MRN
        public ?CarbonImmutable $dateBonAEnlever,
        public ?int $valeurEnDouane,
        public ?int $droits,
        public ?int $autresTaxesNonRecuperables,
        public ?int $fraisAccessoires,                // jusqu'au premier lieu de destination (art. 292 du CGI)
        public array $tvaParTaux,                     // list<array{taux: int, base: int, tva: int}>
    ) {}
}
final readonly class GesteDeRegime { public function __construct(public NatureOperation $nature, public int $auteurId, public CarbonImmutable $le) {} }
final readonly class CompteurDeSeuil { public function __construct(public int $anneePrecedente, public int $anneeEnCours) {} }
final readonly class SaisieManuelle {
    public function __construct(public array $lignes, public string $motif, public int $auteurId, public bool $reprise) {} // lignes : nature, taux, TVA facturée, autoliquidée, déductible imposées ; reprise : reconstruite depuis la décision figée
}
```

`VerificationVies` ne porte pas l'identifiant interne de la consultation : il reste dans la colonne `consultation_vies_id` du document ; l'écran montre le n° de preuve donné par VIES et l'heure.

### 3.4 Les collecteurs

Ils lisent la base et construisent les faits ; c'est là, et seulement là, que se font les lectures, sous `MagasinScope` (ou `SocieteScope`). Le moteur ne voit jamais un modèle.

```php
final class CollecteurFaitsDeVente {
    public function pourLeFormulaire(array $etat, Magasin $magasin, CarbonImmutable $instant): FaitsDeVente;  // brouillon, aperçu
    public function pourLEmission(Facture $facture, CarbonImmutable $instant): FaitsDeVente;                // sous le verrou du registre
    public function pourLaCaisse(PanierCaisse $panier, Magasin $magasin, CarbonImmutable $instant): FaitsDeVente;
    public function pourLAvoir(Avoir $avoir, CarbonImmutable $instant): FaitsDeVente;                       // décisions figées des factures corrigées
    public function pourLeDevis(Devis $devis): FaitsDeVente;                                                // à la date du devis
    public function suggestionDeSortie(Client $client): ?SuggestionDeSortie;                                // dernière facture émise au même client, dans le magasin
}

final class CollecteurFaitsDuTiers {
    public function duClient(Client $client, CarbonImmutable $jour): FaitsDuTiers;      // fiche + vérification VIES du jour
    public function duClientFige(Facture|Avoir $document): FaitsDuTiers;               // copie prise à l'émission
    public function duFournisseur(Fournisseur $fournisseur, CarbonImmutable $jour): FaitsDuTiers;
    public function deLOrganisme(ChargeCommune $charge): FaitsDuTiers;                 // lot CH5 : colonnes de l'organisme de la charge commune
}

final class CollecteurFaitsDeLaSociete {
    public function pour(Magasin $magasin, CarbonImmutable $jour): FaitsDeLaSociete;    // régime en vigueur ce jour-là ; société relue sous verrou à l'émission
    public function pourUneChargeCommune(Societe $societe, TerritoireDeTva $territoire, CarbonImmutable $jour): FaitsDeLaSociete; // lot CH5
}

final class CollecteurFaitsDAchat {
    public function pourLeFormulaire(array $etat, Magasin $magasin): FaitsDAchat;
    public function pourLaValidation(FactureAchat $facture, ?SaisieManuelle $saisieDeLaDemande = null): FaitsDAchat; // sous le verrou de la facture d'achat ; saisie reprise sinon (puce ci-dessous)
    public function pourLAvoirFournisseur(AvoirFournisseur $avoir): FaitsDAchat;        // décision figée de l'achat
    public function pourUneChargeCommune(ChargeCommune $charge): FaitsDAchat;           // lot CH5
    public function suggestionDOrigine(Fournisseur $fournisseur): ?OrigineDesMarchandises; // dernière facture validée du même fournisseur, dans le magasin
}
```

- Le catalogue des lignes de vente se lit dans le collecteur comme aujourd'hui dans `ConstructeurLigneDocument::catalogue()` : une requête pour les produits (archivés compris), une pour les services, sous `MagasinScope`. Un produit archivé est refusé avec le message actuel.
- La vérification VIES retenue est la dernière du jour à Paris, du n° exact de la fiche, dans le magasin actif, seulement si elle a trouvé le n° valide : c'est la lecture actuelle de `ControleNatureOperation::consultationDuJour()`, qui rend nul quand la dernière consultation du jour l'a trouvé invalide.
- **Saisie manuelle reprise** (lot M5e). Quand la demande n'apporte pas de saisie manuelle et que la décision figée de la facture en porte une (nature `saisie_manuelle`), `pourLaValidation()` la reconstruit depuis la décision figée (auteur, motif, instantané des faits) et les colonnes des lignes (nature, taux, TVA facturée, autoliquidée et déductible), avec `reprise: true`. Tout recalcul fait par une autre action la garde donc : déclaration en douane complétée, frais d'une autre facture rattachés ou détachés (lot M7). La reprise compare, ligne par ligne, l'instantané des faits de la décision figée (type, quantité, PU HT, remise, taux facturé, catégorie, précision) aux lignes du moment : une différence donne le refus `tva.saisie_manuelle_a_refaire`, levé seulement par une nouvelle saisie d'un membre autorisé (3.17.9). Une saisie reprise ne s'inscrit pas de nouveau au journal d'audit ; l'inscription de l'acte qui recalcule (douane, frais) cite la saisie gardée.

### 3.5 Le moteur

```php
final class MoteurTva {
    public const VERSION = '1.0';
    public function __construct(
        private readonly CatalogueDesRegles $catalogue,
        private readonly CalculDesLignesDeVente $calculDesVentes,
        private readonly CalculDesRetours $calculDesRetours,
        private readonly CalculDesLignesDAchat $calculDesAchats,
    ) {}
    public function deciderVente(FaitsDeVente $faits): DecisionTvaVente;
    public function deciderAvoir(FaitsDeVente $faits): DecisionTvaVente;
    public function deciderAchat(FaitsDAchat $faits): DecisionTvaAchat;
    public function repartirUneChargeCommune(DecisionTvaAchat $decision, array $poids): RepartitionDUneChargeCommune; // lot CH5 ; poids : magasin → centièmes de point
    public function compatibilite(array $decisionsFigees): array;   // list<Manquement> pour un avoir sur plusieurs factures
}
```

**Vente, dans l'ordre :**
1. Choisir les règles en vigueur à la date de l'opération (`CatalogueDesRegles::enVigueurLe()`).
2. Société en franchise : vente en France, lignes à 0 % au même prix TTC, article par article, mention de franchise (B10) ; une prestation à un professionnel établi hors de France suit les règles de lieu (TVA10 = a, lot M4).
3. Natures candidates, d'après les faits : territoire du client, assujetti, n° de TVA et sa vérification, sortie des biens, adresse de livraison, biens ou services, lieu des services. Le pays seul n'en ouvre aucune.
4. Pour chaque candidate, évaluer toutes ses conditions (liste de `Condition`, chacune remplie ou non, avec la valeur constatée), table de 3.15.4.
5. Nature retenue :
   - un geste « appliquer » sur une nature : elle, si toutes ses conditions sont réunies ; sinon bloquant à l'émission, avec tous les manquements et la voie « Rester à la TVA française » ;
   - un geste « TVA française gardée » : vente en France, la nature écartée étant gardée dans la décision ;
   - sans geste, une candidate dont toutes les conditions sont réunies grâce à des réponses données dans ce document (sortie des biens, adresse, date) : appliquée d'office ;
   - sans geste, une candidate réunie sans qu'aucune question n'ait été posée (prestation à un professionnel établi hors de France) : proposée ; la vente reste en France tant que personne ne l'applique, et l'émission exige un choix (`tva.choix_du_regime_requis`) ;
   - sinon, vente en France (état « incomplet » si une candidate n'a pas toutes ses conditions).
6. Lieu des services : un service au lieu fixé par la loi exécuté hors de France est bloquant (`tva.lieu_du_service_hors_de_france`) ; sans réponse alors qu'un fait international existe, bloquant (`tva.lieu_du_service_a_preciser`).
7. Particularités : accises ou moyen de transport neuf ferment les natures d'exonération (`tva.cas_exclu`) ; la vente en France reste possible.
8. Lignes chiffrées par `CalculDesLignesDeVente` : taux, taux du prix, PU HT, PU TTC, PU payé, HT, TVA, TTC ; R4 pour un professionnel ; les frais accessoires suivent les biens ; prix hors taxe hors de la vente en France et hors franchise (K3, et TVA4 pour les services).
9. Vente à distance (lot M8), évaluée avant la branche de la franchise dans ses effets et sur le HT des lignes chiffrées : biens expédiés par le magasin vers un autre État membre, à un particulier ou à un client non assujetti, sans n° de TVA valide sur VIES ; au-delà du seuil, ou si la société est inscrite à l'OSS : bloquant.
10. Mentions et note de calcul, tirées de la table datée.
11. Circuit (3.15.2) ; démonstration : aucun.
12. Preuves attendues (table datée) et obligations annexes ; démonstration : aucune.
13. Identité et clients : identité légale de la société et du magasin (facture à un professionnel, ou facture demandée), n° de TVA de la société selon `ExigenceDuNumeroTva` (facture à un professionnel hors franchise, et toute nature autre que la vente en France), SIREN d'un client établi en France, nom et adresse du client sur une facture (O5).
14. Avertissements : taux inhabituel, nom VIES très différent de la fiche, vente à distance dès que le compteur atteint 9 000 € (seuil proche), service à un particulier dont l'adresse est hors de l'Union (art. 259 B), livraison antérieure à l'émission sans vérification VIES à sa date (O3). Informations, affichées dans le panneau sans se répéter dans la fenêtre d'émission ni rester parmi les avertissements : état « seuil non atteint » d'une vente à distance sous 9 000 € (TVA7, TVA8), export emporté non géré.
15. Versions et instantané des faits.

**Avoir.** `deciderAvoir()` reçoit les décisions figées de toutes les factures corrigées (`decisionsDOrigine`). La clé de compatibilité est (type de client, franchise, nature, circuit) ; deux factures de même nature dont la copie du client diffère (établi en France, puis non) ne vont pas dans le même avoir. Chaque ligne d'avoir reprend la nature de sa facture. Ses montants se calculent par différence de cumul (K2), par `CalculDesRetours`. Ses mentions et sa note de calcul sont celles de la table du jour de l'avoir.

**Achat, dans l'ordre :**
1. Règles à la date de l'opération ; régime de la société à cette date (O7).
2. Nature de chaque ligne (table de 3.17.2), d'après l'établissement du fournisseur, l'origine réelle, le préfixe de son n° de TVA, le dédouanement, le type de ligne (et, pour une charge, sa catégorie et sa nature de bien ou de service), la raison d'une ligne à 0 %, le lieu fixé par la loi et le statut de la société. Une prime d'assurance ou une opération bancaire exonérée n'est jamais autoliquidée, même facturée par un prestataire non établi en France. Un fait contradictoire donne « cas à vérifier », jamais un choix silencieux.
3. Montants (`CalculDesLignesDAchat`) : conversion en euros si la devise n'est pas l'euro (TVA16, arrondis selon 6.1 d) ; TVA facturée selon la réponse à 6.1 c (option 1, recommandée : sur le total HT de chaque taux facturé, arrondie une fois, répartie par cumul ; option 2 : ligne par ligne, `montantsDAchat()`) ; TVA autoliquidée calculée sur le total HT de chaque taux dû en France, arrondie une fois, puis répartie par cumul (TVA13) ; TVA d'importation reprise de la déclaration en douane, contrôlée avec la base de l'art. 292.
4. TVA déductible : la TVA facturée française et l'autoliquidée pour une société redevable, multipliées par le pourcentage récupérable de la ligne (100 % sauf règle d'une ligne de charge, 4.3 ; 0 pour toutes les lignes d'une pièce qui n'est pas une facture au nom de la société ou du magasin), arrondi selon 6.1 d ; rien en franchise, où elles s'ajoutent au coût ou à la charge ; jamais une TVA étrangère. Pour une société qui déclare aussi des opérations exonérées ou hors du champ de la TVA, chaque ligne de charge reçoit l'indicateur « à confirmer » (4.3), sans coefficient calculé.
5. Montant dû au fournisseur, TVA à payer à l'État, montant à payer à la douane ou au transitaire.
6. Coût d'entrée de chaque ligne de produit (3.17.4).
7. Manquements (bloquants) et avertissements ; saisie manuelle si elle est autorisée (lot M5e).
8. Versions et instantané des faits.

**Charge commune (lot CH5).** `deciderAchat()` décide la charge commune entière, avec les faits de la société et de l'organisme (3.4). `repartirUneChargeCommune()` reçoit cette décision et les poids de la répartition (parts égales ou pourcentages) et rend, pour chaque magasin, sa part de chaque ligne : HT, TVA facturée, TVA autoliquidée, TVA déductible, TTC. Chaque montant de chaque ligne se répartit selon la réponse à 6.1 d (recommandation : `RepartitionParCumul`, au centime, dans l'ordre des magasins par identifiant croissant, la somme des parts égale exactement le montant de la ligne). Aucun autre code ne calcule une part : `RepartitionDesChargesCommunes` (4.7) ne fait qu'écrire les parts rendues et les relire.

### 3.6 Les règles datées et versionnées

```php
final class CatalogueDesRegles { public function enVigueurLe(CarbonImmutable $date): ReglesTva; }

final readonly class ReglesTva {
    public function __construct(
        public string $version,                 // « 2026.10.0 », changée à chaque modification d'une donnée
        public TableDesTaux $taux,              // territoire → taux en vigueur, taux normal
        public TableDesMentions $mentions,      // code → texte, du / au ; notes de calcul ; mention de l'avoir de régularisation (lot A7)
        public TableDesCategories $categories,  // nature × franchise × type de ligne → catégorie EN 16931 et VATEX
        public TableDesPreuves $preuves,        // nature × sortie des biens → preuves attendues
        public Seuils $seuils,                  // ventes à distance 10 000 € (depuis le 1/7/2021), seuil proche 9 000 €, détaxe > 100 € TTC, cadeaux 73 € TTC
        public GeographieTva $geographie,       // États membres, préfixes EL et XI, territoires exclus, DOM, collectivités d'outre-mer, Monaco
        public TableDesCharges $charges,        // lot M5c (bien ou service par catégorie), complétée au lot CH2 (4.3)
        public CategoriesPrisesEnCharge $categoriesPrisesEnCharge, // lot M3 : catégorie S seulement (3.15.2)
    ) {}
}
```

- **Des données, pas du code éparpillé.** Chaque entrée porte une date de début et une date de fin éventuelle ; une entrée déjà en vigueur, sans date de début connue, a un début nul.
- **Taux** : métropole 20, 10, 5,5 et 2,1 % ; Corse 20, 13, 10, 5,5, 2,1 et 0,9 % ; Guadeloupe, Martinique et La Réunion 8,5, 2,1, 1,75 et 1,05 % ; Guyane et Mayotte : pas de TVA (magasins non gérés). Une loi de finances qui change un taux devient une entrée datée. Un taux absent de la table du territoire est « inhabituel » (avertissement, jamais un refus, B2).
- **Mentions et notes de calcul** : la table de départ reprend **mot pour mot** les textes d'aujourd'hui, à recopier depuis le code et non depuis ce plan : `NatureOperation::mention()` (« Exonération de TVA, article 262 I du CGI », « Exonération de TVA en application de l'article 294 du Code général des impôts », « Exonération de TVA, article 262 ter I du CGI », « Autoliquidation », « TVA non applicable – article 259-1° du CGI »), `RegimeTva::MENTION_FRANCHISE` (« TVA non applicable, art. 293 B du CGI »), les constantes de `MentionsFiscales` (catégorie de l'opération, option pour les débits, notes de calcul). Les références au CIBS n'y entrent qu'une fois leurs numéros confirmés, par une nouvelle version (O4).
- **Catégories et VATEX** (jamais affichés, jamais enregistrés) : S sans VATEX ; E (franchise) VATEX-FR-FRANCHISE ; K VATEX-EU-IC ; G VATEX-EU-G ; AE VATEX-EU-AE ; O VATEX-EU-O, sans taux (BR-O-05). La catégorie d'une livraison vers un DOM est G dans la table de départ, à confirmer (6.2, EC3) ; la transmission de cette catégorie reste retenue jusqu'à sa vérification au bac à sable.
- **Preuves attendues** : export, MRN ou CN23 ; livraison vers un DOM, MRN ou CN23, et un document de transport ; livraison intracommunautaire, deux documents de transport de catégorie a), ou un a) et un b), émis par deux parties différentes, indépendantes l'une de l'autre, du vendeur et de l'acquéreur, et l'attestation de l'acquéreur au plus tard le 10 du mois suivant s'il emporte les biens.
- **Empreinte** : un test calcule l'empreinte des données et la compare à celle enregistrée avec leur version ; modifier une donnée sans changer de version fait échouer la suite.
- **CIBS** : le jour où les numéros sont confirmés, une version des règles termine l'entrée CGI la veille et ouvre l'entrée CIBS ce jour-là, avec un test daté ; les documents émis avant gardent leur texte figé.
- `ExigenceDuNumeroTva` : la seule fonction qui dit quand le n° de TVA de la société est exigé (facture à un professionnel hors franchise ; toute nature autre que la vente en France ; tout achat autoliquidé ou acquisition d'une société redevable). `Societe::mentionsLegalesManquantes()` l'appelle pour son entrée « n° de TVA ».

### 3.7 La décision et la colonne `decision_tva`

```php
final readonly class DecisionTvaVente {
    public string $versionMoteur;  public string $versionRegles;  public CarbonImmutable $dateDeLOperation;
    public NatureOperation $nature;  public EtatDeLaDecision $etat;
    public array $propositions;               // list<PropositionDeNature> : nature, conditions, manques, total que la vente aurait
    public bool $franchise;  public MethodeDeCalcul $methode;  public bool $retireLaTvaDuPrix;
    public array $lignes;                     // list<LigneDeVenteChiffree>
    public Totaux $totaux;
    public Redevable $redevable;  public array $mentions;  public ?MentionImprimee $noteDeCalcul;
    public Circuit $circuit;  public bool $venteADistance;
    public array $preuvesAttendues;  public array $obligations;
    public array $conditions;  public array $manquements;  public array $avertissements;
    public InstantaneDesFaits $faits;  public ?GesteDeRegime $geste;
    public function bloquants(): array;
    public function ecartAvec(array $lignesEnregistrees, NatureOperation $natureEnregistree): ?EcartDeCalcul; // avant et après
    public function figee(): array;           // contenu de la colonne decision_tva
}

final readonly class LigneDeVenteChiffree {
    public int $ordre;  public TypeDeLigne $type;  public CategorieTva $categorie;  public ?string $vatex;
    public ?int $taux;              // centièmes ; null en catégorie O
    public int $tauxDuPrix;         // taux compris dans le PU TTC (K3)
    public int $quantite;  public int $remise;
    public int $prixUnitaireHt;  public int $prixUnitaireTtc;  public int $prixUnitaireTtcPaye;
    public int $ht;  public int $tva;  public int $ttc;
}

final readonly class DecisionTvaAchat {
    public string $versionMoteur;  public string $versionRegles;  public CarbonImmutable $dateDeLOperation;
    public bool $societeEnFranchise;  public EtatDeLaDecision $etat;
    public array $lignes;           // list<LigneDAchatChiffree>
    public TotauxDAchat $totaux;    // ht, tvaFacturee, tvaEtrangere, tvaAutoliquidee, tvaDeductible, tvaNonDeductible, droits,
                                    // duAuFournisseur, tvaAPayerALEtat, aPayerALaDouane, coutDEntree
    public array $conditions;  public array $manquements;  public array $avertissements;
    public InstantaneDesFaits $faits;  public ?SaisieManuelle $saisieManuelle;
    public function figee(): array;
}

final readonly class LigneDAchatChiffree {
    public int $ordre;  public TypeLigneAchat $type;  public NatureAchat $nature;  public ?CategorieTva $categorieAttendue;
    public int $tauxFacture;  public int $ht;  public int $tvaFacturee;  public ?int $tauxDuEnFrance;  public int $tvaAutoliquidee;
    public int $pourcentageRecuperable;      // centièmes de point : 10000 sauf règle d'une ligne de charge, 0 sur une pièce qui n'est pas une facture
    public int $tvaDeductible;  public int $tvaNonDeductible;  public int $quotePartFrais;  public int $quotePartDroits;
    public ?int $coutUnitaireDEntree;        // dix-millièmes d'euro ; null pour une ligne hors stock
    public ?string $raison;                  // code de la règle de récupération (ligne de charge), texte lu dans la table
    public bool $aConfirmer;                 // règle encore à confirmer par l'expert-comptable, ou société qui déclare des opérations hors droit à déduction (4.3)
}
```

**Ce que garde la colonne `decision_tva`** : seulement ce qui n'a pas de colonne et ne se déduit pas des faits figés.
- Vente : versions, date de l'opération, état, méthode, prix hors taxe ou non, redevable, mentions et note de calcul (code et texte), conditions (code, remplie, constat), faits et leur source, nature écartée quand la TVA française a été gardée, obligations, avertissements. N'y sont pas : la nature, la franchise, le circuit, la vente à distance, les gestes, la sortie des biens, la livraison, le lieu des services et la consultation VIES (colonnes du document) ; les lignes et leurs montants (colonnes des lignes) ; la catégorie et le VATEX de chaque ligne (déduits).
- Achat : versions, date de l'opération, état, conditions, faits et leur source (nature de la pièce comprise), avertissements, saisie manuelle (auteur, motif, reprise ou non), raisons de récupération des lignes de charge et leur indicateur « à confirmer », totaux qui n'ont pas de colonne (TVA étrangère, TVA à payer à l'État, montant à payer à la douane). Les montants des lignes sont des colonnes.
- Données personnelles : seulement l'identifiant de l'utilisateur, jamais son nom, ni adresse IP ni navigateur.

**Exemple : `decision_tva` d'une livraison intracommunautaire à Müller GmbH**

```json
{
  "version_moteur": "1.0", "version_regles": "2026.10.0", "date_operation": "2026-10-14",
  "etat": "appliquee_d_office", "methode": "ht_de_ligne", "retire_la_tva_du_prix": true, "redevable": "client",
  "mentions": [
    { "code": "categorie_biens", "texte": "Catégorie de l'opération : Livraison de biens" },
    { "code": "exoneration_lic", "texte": "Exonération de TVA, article 262 ter I du CGI" }
  ],
  "note_de_calcul": { "code": "professionnel_sans_tva_francaise", "texte": "<texte actuel de MentionsFiscales, recopié>" },
  "conditions": [
    { "code": "client_professionnel_ue", "remplie": true, "constat": "établi en Allemagne" },
    { "code": "vies_valide_du_jour", "remplie": true, "constat": "DE123456789, valide sur VIES le 14/10/2026 à 10:32 (n° de preuve VIES conservé)" },
    { "code": "biens_expedies_hors_de_france", "remplie": true, "constat": "Lyon (métropole) → 10115 Berlin" },
    { "code": "date_de_livraison", "remplie": true, "constat": "14/10/2026" },
    { "code": "seulement_des_biens_et_leurs_frais", "remplie": true, "constat": "1 ligne de biens" },
    { "code": "numero_tva_de_la_societe", "remplie": true, "constat": "renseigné" }
  ],
  "faits": { "client.pays": ["DE", "fiche"], "sortie_des_biens": ["expedies_par_le_magasin", "saisi"], "depart": ["metropole", "derive"] },
  "obligations": [ { "code": "facture_au_plus_tard", "date": "2026-11-15" }, { "code": "releve_etat_recapitulatif", "periode": "2026-10" } ],
  "avertissements": []
}
```

Colonnes JSON : `$table->json()` (type `json` sous PostgreSQL, jamais `jsonb`, qui réordonne les clés) ; écrites une fois, clés dans un ordre canonique (celui de `figee()`) ; un test sous PostgreSQL vérifie qu'elles se relisent à l'identique.

### 3.8 Aperçu, contrôle, figement ; ordre de l'émission ; VIES ; démonstration

| Usage | Quand | Ce qui se passe |
|---|---|---|
| Aperçu | À chaque requête Livewire du formulaire, mémorisé une fois par requête (mécanisme de `ConstructeurLigneDocument::apercu()`) | Décision provisoire chiffrée : nature, questions à afficher, manques, totaux. Aucun appel réseau : VIES n'est jamais consulté à la frappe. |
| Contrôle | À l'émission, sous le verrou du registre ; à la validation d'un achat, sous le verrou de la facture d'achat | Même calcul, depuis les faits relus sous verrou ; refus avec tous les manquements. |
| Figement | Juste après un contrôle réussi | Vente : colonnes, lignes et `decision_tva` écrites et signées. Achat : colonnes des lignes et `decision_tva` écrites, entrée au journal d'audit. |

**Ordre de `EmettreFactureAction`** (il remplace l'emplacement des contrôles de TVA dans l'ordre livré par les lots A4 et A6, sans toucher au reste) :
0. Hors transaction, lecture seule : si le jeton de la demande est déjà enregistré avec la même action, le même auteur et le même contenu, le résultat enregistré est rendu, sans rien consulter (`ActionsUniques::resultatDejaEnregistre()`, ajoutée au lot M1, simple lecture sans verrou) ; si la facture n'est plus un brouillon, rien n'est consulté.
1. Hors transaction : si la nature appliquée, appliquée d'office ou choisie dans la fenêtre « Émettre » exige VIES et qu'aucune vérification valide du jour n'existe pour le n° exact du client, `AssurerUneVerificationViesDuJourAction` interroge `RegistreVies` et enregistre la consultation (auteur relu, nom figé). C'est la condition qu'applique aujourd'hui l'écran (`FactureResource::verifierLeNumeroTvaSurViesSiBesoin()`, avec `consultationDuJour() === null`, nul aussi quand la dernière consultation du jour a trouvé le n° invalide) ; seul le lieu change : la consultation quitte l'écran pour l'action, donc tout appel de l'action la fait, tests compris (5.3). VIES injoignable : rien n'est émis, le brouillon reste, le refus le dit (`tva.vies_injoignable`), aucune consultation n'est enregistrée. Cette consultation est un acte de l'émission : elle exige `emettre` sur la facture, jamais `update` sur le client.
2. Ordre des lots A4 et A6, inchangé : `ActionsUniques::executer`, transaction, verrou du registre, porte de validation du magasin (`verifierQuIlPeutVendre`), `LecteurDesDroits::exigerSousVerrou($auteur, 'emettre', …)` (et `encaisser` avec un règlement comptant), une caisse à la fois, facture verrouillée, statut relu, lignes.
3. **Le moteur**, sur les faits relus sous verrou : calcul du brouillon changé, identité légale, SIREN et adresse du client, choix du régime requis, conditions de la nature, consultation VIES lue (jamais demandée), seuil des ventes à distance. Un seul refus, avec tous les manquements (`DecisionTvaRefuseeException`).
4. Journée clôturée (ticket), poste du vendeur, produits verrouillés, `VerificateurDeStock` (R1, avec la confirmation de vente sans stock) : le refus fiscal arrive donc avant l'avertissement de stock.
5. Numérotation, figement (client, émetteur, colonnes fiscales, `decision_tva`, gestes, circuit), événement d'émission, encaissement, signature, audit `VenteSansStock`, jeton.

Jamais d'appel réseau sous le verrou du registre.

**VIES derrière une interface** : `app/Integrations/Vies/Contracts/RegistreVies.php` ; `ClientVies` l'implémente ; `FauxRegistreVies` (déterministe, sans requête, sur le modèle de `FauxRepertoireSirene`) aussi. Pilote `config('services.vies.pilote')` : `europa` par défaut, `faux` ; refus de démarrer en production avec `faux`, comme les autres fournisseurs ; `phpunit.xml` ne change pas (les tests actuels gardent `Http::fake` sur le vrai client ; ceux qui émettent sans vérification valide du jour lient `FauxRegistreVies`, 5.3). **Magasin de démonstration** : `RegistreVies` se résout en `FauxRegistreVies` quand le magasin actif est la démonstration, quel que soit le pilote ; la décision y a le circuit « aucun » et aucune obligation ; la démonstration est exclue des compteurs et des relevés de la société.

**`VerifierNumeroTvaAction`** (bouton « Vérifier sur VIES » de la fiche) garde son contrôle (`update` sur le client ou, au lot M5b, sur le fournisseur) ; la consultation elle-même passe par un service commun `ConsultationsVies::consulter(Client|Fournisseur $tiers, User $auteur): ConsultationVies` que l'émission appelle aussi.

### 3.9 Refus, codes et messages

`DecisionTvaRefuseeException` (liste de `Manquement`) remplace `NatureOperationRefuseeException` et sert aussi à la validation des achats et des charges ; elle hérite de `LogicException`. Le message des conditions d'une nature garde sa forme exacte d'aujourd'hui : « Impossible d'émettre en « … » : … . Choisissez « Vente en France » (TVA française) si ces conditions ne sont pas remplies. »

Un manquement porte un code stable, le chemin du champ, un message en français identique à l'écran, dans le refus et dans l'API, et sa gravité (bloquant, avertissement, information). Liste complète des codes : annexe B.

Un avoir qui mélange des factures incompatibles garde son refus actuel : `InvalidArgumentException`, avec les messages d'aujourd'hui (`CreerAvoirAction`) ; le moteur fournit la règle (`compatibilite()`), l'action garde le message ; un seul message s'ajoute : « Un avoir ne mélange pas des factures transmises par des circuits différents : faites deux avoirs. »

### 3.10 Géographie

`GeographieTva` et `TerritoireDeTva` absorbent `TerritoireFiscal` et `TerritoireTva`. Valeurs de `TerritoireDeTva` : `metropole`, `corse`, `guadeloupe_martinique_reunion`, `guyane_mayotte`, `collectivite_outre_mer`, `monaco`, `autre_etat_membre`, `irlande_du_nord`, `territoire_exclu_ue`, `pays_tiers`.
- Lot M2 : comportement identique à aujourd'hui (mêmes listes, mêmes résultats).
- Lot M3 : une seule fonction lit toutes les adresses, client, livraison et magasin (le pays d'un DOM s'écrit `FR` + code postal 97x, ou `GP`, `MQ`, `RE`, `GF`, `YT`, partout pareil) ; « identifié au répertoire Sirene » (exige le SIREN : FR, GP, MQ, GF, RE, YT, BL, MF, PM) se sépare du territoire de TVA : Saint-Barthélemy, Saint-Martin, Saint-Pierre-et-Miquelon sont des collectivités d'outre-mer, la Guyane et Mayotte ne sont pas dans le champ de la TVA ; le départ des biens est le territoire du magasin ; Monaco se lit par le mouvement des biens (biens expédiés de France à Monaco, ou venus de Monaco) et par l'établissement pour les services ; les territoires exclus se lisent par pays et code postal (Canaries, Ceuta, Melilla, Åland, mont Athos, Heligoland, Büsingen, Livigno, Campione) ; la Grèce a le préfixe de TVA EL, l'Irlande du Nord XI (biens seulement).

### 3.11 Données, table par table

Règles : migration de création modifiée sur place ; colonnes JSON en `json` ; « exactement l'un des deux » par une contrainte CHECK sous PostgreSQL et des déclencheurs BEFORE INSERT et BEFORE UPDATE sous SQLite (modèle : migration `clotures_caisse_personnelles`), testés sous les deux moteurs ; chaque colonne arrive dans le lot qui s'en sert ; ce qui ne sert qu'aux étapes 2, 5 ou 7 n'est pas créé avant (catégorie B2C, exigibilité, circuit d'achat, rubriques de déclaration, option du fournisseur pour les débits).

| Table | Changements | Lot |
|---|---|---|
| `clients` | `assujetti_tva` (booléen, vrai par défaut, sans objet pour un particulier), `entite_publique` (booléen, faux par défaut) ; `pays` choisi dans une liste nommée (écran). | M3 |
| `factures` | `transport_par` devient `sortie_biens` (string(30) nullable) ; copies figées `client_assujetti_tva`, `client_entite_publique` (booléens nullables, avec les autres `client_*`) ; gestes `nature_confirmee` (string(40) nullable), `nature_confirmee_par` (clé vers `users`, nullable), `nature_confirmee_par_nom` (string(255) nullable, nom figé), `nature_confirmee_le` (datetime nullable) ; `lieu_des_services` (string(20) nullable) ; `circuit` (string(40) nullable, écrit à l'émission) ; `vente_a_distance` (booléen, faux par défaut, écrit à l'émission) ; `decision_tva` (json nullable, écrite une fois à l'émission). `nature_operation` et `franchise_en_base` restent : sorties du moteur à chaque enregistrement du brouillon, figées à l'émission, jamais relues comme une entrée ; commentaires réécrits. | M3 |
| `factures` | `facture_demandee` (booléen, faux par défaut) ; `ticket_transforme_id` (clé nullable vers `factures`, `restrictOnDelete`, unique quand non nulle : un ticket ne se transforme qu'une fois). | C-b |
| `avoirs` | Copies figées du client (assujetti, entité publique) ; `circuit` et `vente_a_distance` (repris des factures corrigées) ; `decision_tva` (json : versions, mentions et note du jour de l'avoir, compatibilité). | M3 |
| `avoirs` | `motif` (string(30), `retour` par défaut ; `annulation_pour_facture` ; et `regularisation` si le lot A7 a créé une colonne de régularisation, qu'il remplace alors). | C-b |
| `devis` | `sortie_biens` remplace `transport_par` ; gestes (`nature_confirmee`, `_par`, `_par_nom`, `_le`) ; `lieu_des_services`. Aucune décision enregistrée. | M3 |
| `lignes_facture`, `lignes_avoir`, `lignes_devis` | Inchangées. | — |
| `services` | Commentaire de `lieu_specifique` corrigé : le lieu dépend du lieu d'exécution. | M3 |
| `produits` | `particularite_fiscale` (string(30), `aucune` par défaut). `taux_tva` reste le taux de vente (« Taux de TVA de vente »). | M4 |
| `produits` | `cmup_ht` devient `cout_moyen` (decimal(12,4), commentaire : TVA non déductible comprise pour une société en franchise). | M7 |
| `societes` | `regime_tva` retirée (lue dans `regimes_tva_societe`). | M5a |
| `societes` | `ventes_a_distance_annee_depart` (smallint nullable), `ventes_a_distance_annee_precedente`, `ventes_a_distance_annee_en_cours` (decimal(12,2) nullable), `oss_inscrite_le` (date nullable). | M8 |
| `societes` | `operations_hors_droit_a_deduction` (booléen, faux par défaut) : la société déclare aussi des opérations exonérées ou hors du champ de la TVA ; réglage fiscal commun, non daté (il ne change aucun montant, seulement l'indicateur « à confirmer » des lignes de charge validées ensuite ; chaque décision figée garde la valeur lue). | CH2 |
| `regimes_tva_societe` (nouvelle) | `id`, `societe_id` (clé, `restrictOnDelete`), `regime` (string(30), valeur de `RegimeTva`), `en_vigueur_le` (date), `saisi_par` (clé vers `users`, nullable), `saisi_par_nom` (string(255) nullable), `created_at`. `BelongsToSociete` ; `DeclencheursDeProtection::ajoutSeul()` ; index (`societe_id`, `en_vigueur_le`, `id`). Le régime à une date est celui de la dernière entrée (par date, puis par identifiant) dont la date est au plus celle-là. | M5a |
| `fournisseurs` | `etabli_en_france` (booléen, sans valeur par défaut en base : le modèle le calcule depuis le pays) ; `ne_facture_pas_de_tva` (booléen, faux) ; `pays` contrôlé comme celui d'un client. | M5b |
| `consultations_vies` | `client_id` devient nullable ; `fournisseur_id` (clé nullable, `restrictOnDelete`) ; exactement l'un des deux ; index (`magasin_id`, `fournisseur_id`, `consultee_le`) ; toujours en ajout seul. | M5b |
| `factures_achat` | `pays_expedition` (string(2) nullable), `code_postal_expedition` (string(10) nullable), `origine_source` (string(30) nullable, valeur de `SourceDuFait`), `dedouane_par` (string(20) nullable) ; `nature_piece` (string(20) nullable, valeur de `NatureDeLaPiece`, exigée à la validation) ; déclaration en douane, une par facture : `douane_reference` (string(30) nullable), `douane_date_bae` (date nullable), `douane_valeur`, `douane_droits`, `douane_autres_taxes`, `douane_frais_accessoires` (decimal(12,2) nullable), `douane_tva` (json nullable : taux, base, TVA) ; `total_de_controle` (decimal(12,2) nullable) ; `decision_tva` (json nullable, écrite à la validation, remplacée à chaque modification). Restent : `total_ht`, `total_tva` (= TVA facturée), `total_ttc` (= dû au fournisseur), `escompte_pourcentage`, `date_validation`, `devise`. | M5d |
| `factures_achat` | `taux_change` (decimal(16,8) nullable), `taux_change_source` (string(30) nullable) ; `tva_etrangere_regularisee_le` (date nullable), `tva_etrangere_regularisee_par` (clé nullable vers `users`), `tva_etrangere_regularisee_par_nom`, `tva_etrangere_regularisation_motif` (string(30) nullable). | M5e |
| `factures_achat` | `type_document` (string(20), `marchandises` par défaut) ; `periode_du`, `periode_au` (dates nullables) ; `modele_charge_recurrente_id` (lot CH3, seulement si l'éditeur le retient, 6.1 f : clé nullable, unique avec `magasin_id` et `periode_du` quand non nulle). | CH2, CH3 |
| `lignes_achat` | `type_ligne` (string(20)) ; `produit_id` et `nouveau_produit` exigés pour une ligne de produit seulement ; `nature_achat` (string(40) nullable, écrite à la validation) ; `taux_tva_france` (decimal(5,2) nullable) ; `raison_taux_zero` (string(20) nullable) ; `montant_tva_autoliquidee`, `montant_tva_deductible`, `quote_part_frais`, `quote_part_droits` (decimal(12,2), 0) ; `cout_unitaire_entree` (decimal(14,4) nullable) ; `lieu_fixe_par_la_loi` (booléen, faux) ; `categorie_charge` (string(30) nullable, exigée pour une ligne de charge) ; `nature_charge` (string(10) nullable : `bien`, `service`). `taux_tva` et `montant_tva` gardent leur nom : le taux et la TVA facturés. | M5d |
| `lignes_achat` | `precision_charge` (string(40) nullable) ; `seuil_cadeaux_confirme` (booléen, faux par défaut). | CH2 |
| `lignes_achat` | `facture_achat_rattachee_id` (clé nullable vers `factures_achat`, `restrictOnDelete`, contrôlée par `regleExisteDansLeMagasin()`). | M7 |
| `avoirs_fournisseur`, `lignes_avoir_fournisseur` | Lignes : `montant_tva_autoliquidee`, `montant_tva_deductible` ; en-tête : `decision_tva` (json). | M5e |
| `mouvements_stock` | `cout_unitaire_ht` devient `cout_unitaire` (seul un retour au fournisseur en porte un). Le coût d'une réception ne s'écrit jamais sur un mouvement. | M7 |
| `clotures_caisse` | Inchangée (l'agrégat par circuit et par taux se construit à l'étape 2 depuis les documents figés). | — |

Tables des compléments et des charges : lots C-a (`annulations_preuves_operation`, `suites_bordereaux_detaxe`), CH1 (`pieces_justificatives`), CH3 s'il est retenu (`modeles_charges_recurrentes`), CH4 (`salaires_mensuels`), CH5 (`charges_communes`, `lignes_charges_communes`, `parts_charges_communes`, `pieces_justificatives_societe`) ; leur schéma est donné dans leur lot.

### 3.12 Contenu signé et conservation

**Ventes (lot M3).**
- À l'émission, dans la transaction où la nature, la livraison et la consultation VIES s'écrivent déjà, s'écrivent aussi le circuit, la vente à distance, les gestes, le lieu des services, les copies du client et `decision_tva`. Ces colonnes sont protégées comme les autres par `ProtegeLesDonneesValidees`.
- Bloc « document » de `ContenuFiscal` : ajoute `sortie_biens` (à la place de `transport_par`), `circuit`, `vente_a_distance`, les gestes (nature, auteur, nom figé, instant), `lieu_des_services` et `decision_tva` **telle qu'enregistrée** (la colonne décodée, jamais resérialisée par une classe du domaine) ; la copie du client signée gagne « assujetti » et « entité publique ». Le bloc « contexte » perd les mentions et la catégorie de l'opération, désormais dans `decision_tva`.
- `VerificateurIntegrite` recalcule le bloc « document » depuis les colonnes et le compare strictement, ordre des clés compris : d'où la colonne JSON écrite une fois, en ordre canonique, de type `json`.
- La ventilation signée reste dans le module fiscal (`ContenuFiscal::lignesEtTotaux()`), séparée de celle des écrans (`VentileLaTvaParTaux`) ; un test prouve qu'elles donnent le même résultat. La clé reste le taux : une nature par document, donc une catégorie par taux.
- `ModuleFiscal::VERSION` reste « 1.0 » jusqu'à la première version attestée (étape 3) ; son docblock nomme les classes du domaine `Tva` dont le résultat entre dans le périmètre signé : `MoteurTva`, `CalculDesLignesDeVente`, `CalculDesRetours`, `CatalogueDesRegles`.
- Le PDF, la fiche et le duplicata relisent les mentions et la note de calcul figées ; ils ne rappellent jamais le moteur. Un avoir fige ses propres mentions et reprend la nature, la franchise et le circuit de ses factures ; un devis n'est jamais figé.
- Lot C-b : le bloc « document » ajoute, de façon additive, `facture_demandee` et `ticket_transforme_id` (facture), `motif` (avoir), et pour un avoir d'annulation `ticket_annule` et `facture_de_remplacement` (n° du ticket et de la facture, déduits des colonnes) ; la facture de remplacement est numérotée avant la signature de l'avoir, dans la même transaction (5.6).

**Achats (lot M5d).**
- La validation écrit les colonnes des lignes et `decision_tva`, et inscrit `FactureAchatValidee` au journal d'audit de la société (catégorie « Opérations », 10 ans) par `JournalAudit::inscrire()`, avec les seuls champs retenus (type de document, nature de chaque ligne, totaux, résumé de la décision).
- Une modification après la validation, une déclaration en douane complétée ou des frais rattachés remplacent `decision_tva` et inscrivent `FactureAchatModifiee` ou `CoutDEntreeRecalcule`, avec l'ancienne décision complète en anciennes valeurs ; une saisie manuelle inscrit `TvaDAchatSaisieALaMain`, avec son motif. Le journal d'audit est l'historique : aucune table de décisions d'achat.

**Stock.**
- Le coût d'entrée de chaque ligne de produit est la colonne `cout_unitaire_entree`, lue à la réception (`IncrementerStockApresValidationFactureAchat`) et par le rejeu du grand livre (`ModifierFactureAchatAction`). Les mouvements ne changent pas ; un coût corrigé recalcule le coût moyen par `StockLedgerService::corrigerUneReception()`, à quantité inchangée ; `receptionner()` garde sa signature.
- Changer le coût d'une ligne est refusé dès qu'un avoir fournisseur a été émis sur sa facture (`tva.cout_fige_par_un_avoir_fournisseur`).

### 3.13 Isolation

- Toutes les colonnes nouvelles du moteur sont sur des modèles d'un magasin, sauf `regimes_tva_societe` (société) et les réglages des lots M8 et CH2 (`societes`).
- « Dernière facture validée du même fournisseur » et « dernière facture émise au même client » se lisent sous `MagasinScope` : deux magasins d'une société ont chacun leur fiche du même fournisseur allemand et leurs vérifications VIES, sans copie de l'une à l'autre.
- Les règles `exists` nouvelles (facture rattachée, fournisseur d'une consultation VIES) passent par `regleExisteDansLeMagasin()`.
- Lectures hors du magasin (puce 2 de « Produit ») : chacune dans une seule classe, sur le modèle de `Societe::aVenduUneFois()` (`withoutGlobalScope(MagasinScope::class)` filtré par `whereIn('magasin_id', $societe->magasinsReels()->select('id'))`, démonstration exclue), sous un test nommé :
  - `CompteurDesVentesADistance` (lot M8) ;
  - `ReleveFiscalDeLaSociete` (lot M4) ;
  - `DocumentsARevoirApresUnChangementDeRegime` (lot M5a) ;
  - `RepartitionDesChargesCommunes` (lot CH5 : écriture des parts que le moteur a calculées, relecture des parts, et lecture du détail d'une charge commune et de sa pièce depuis le panneau d'un magasin qui en a une part) ;
  - `SalairesDeLaSociete` (lot CH4, écritures et relectures).
- Les écritures dans un magasin depuis l'espace de la société passent par `TenantContext::runAs($magasin, …)`, qui refuse un magasin d'une autre société ; `BelongsToMagasin` pose alors `magasin_id`.
- Jobs et commandes : la seule commande nouvelle est celle des charges récurrentes (lot CH3, seulement si l'éditeur le retient, 6.1 f), magasin par magasin sous `TenantContext::runAs()`, comme `ExpirerDevis`. La commande planifiée existante `SoumettreEReportingPeriodique` change seulement sa lecture du régime au lot M5a et garde son filtrage par société.

### 3.14 Remplacement de l'existant, classe par classe

| Élément actuel | Sort | Lot |
|---|---|---|
| `CalculateurLigne` (`app/Domain/Facturation/Services`) | Déplacée vers `app/Domain/Tva/Calcul/`, règles inchangées ; API en centimes (M2) ; `repartirParCumul(int $total, list<int> $poids)` (M5c) ; `coutUnitaireDEntree()` (M5c) ; `valeurAuCout()` à la place de `valeurAuCoutMoyen()` (M7). | M1, M2, M5c, M7 |
| `ContexteDeVente` | Supprimé ; ses prédicats deviennent des lectures de la décision, ou, pour un document enregistré, une fonction des règles sur sa nature et sa franchise ; ses refus « type de client ou régime changé » deviennent `DecisionTvaVente::ecartAvec()`, plus large (taux, nature, montants), avec l'avant et l'après. | M2 |
| `ConstructeurLigneDocument` | Réduit à un traducteur : il appelle le collecteur et le moteur, puis traduit les lignes chiffrées en attributs de ligne ; `apercu()` appelle le moteur. | M2 |
| `NatureOperation` | Déplacée vers `app/Domain/Tva/Enums/` (mêmes valeurs en base) ; textes vers les tables ; prédicats et `proposees()` vers le moteur. | M2 |
| `ControleNatureOperation` | Absorbé par le moteur (conditions pures) et le collecteur (consultation du jour) ; le message faux sur le lieu fixé par la loi est corrigé au lot M3. | M2, M3 |
| `NatureOperationRefuseeException` | Remplacée par `DecisionTvaRefuseeException` (même parent, même message, codes en plus). | M2 |
| `MentionsFiscales` | `pour()` et `calcul()` absorbées (table datée, figées au lot M3) ; `bonDAvoir()` reste. | M2, M3 |
| `CategorieOperation`, `NatureLigne`, `TransportPar`, `TypePreuve` | `CategorieOperation` déplacée vers `Tva` ; `NatureLigne` et `TypePreuve` restent dans `Facturation` ; `TransportPar` remplacée par `SortieDesBiens`. | M2, M3 |
| `TerritoireFiscal`, `TerritoireTva` | Absorbés par `GeographieTva` et `TerritoireDeTva`. | M2, M3 |
| `NumeroTva`, `NumeroTvaAuFormat`, `NumeroTvaNormalise` | Conservés. | — |
| `ClientVies` | Conservé derrière `RegistreVies`, avec `FauxRegistreVies`. | M1 |
| `VerifierNumeroTvaAction` | Conservée, appuyée sur `ConsultationsVies` ; étendue aux fournisseurs. | M1, M5b |
| `FactureResource::verifierLeNumeroTvaSurViesSiBesoin()` | Supprimée (la consultation passe dans le domaine). | M1 |
| `NoteSaNatureDOperation` | Réécrit : il note les faits (sortie des biens, livraison, lieu des services) et le geste, et efface le geste quand un fait déclencheur change (client, sortie, livraison, composition biens et services, lieu des services). | M3 |
| `ChoisitLaNatureDeLOperation` (écran) | Remplacé par le trait `AfficheLeRegimeDeTva` (panneau et questions progressives). | M4 |
| `AjouteDesLignesDeVente` (écran) | Allégé : plus aucune règle recodée ; il affiche l'aperçu du moteur. | M2 |
| `LigneFacture::prixUnitaireTtcPaye()`, `montantsDuRetour()` | Le premier lit la règle « TVA retirée du prix » sur la nature et la franchise du document ; le second délègue à `CalculDesRetours`. | M2 |
| `NoterBordereauDetaxeAction::refusPour()` | Réécrite sur un seul prédicat des règles : « vente en France avec des biens taxés ». | M2 |
| `PreuvesRequises` | Garde le jugement de complétude (PHP et SQL) ; lit les preuves attendues dans la table datée ; `typesAdmis()` supprimée (M4) ; complément 4 (C-a). | C-a, M4 |
| `Facture::relevantDeLeInvoicing()` | Supprimée ; remplacée par la colonne `circuit`. | M3 |
| `Facture::serie()`, `libelleDocument()`, `scopeTickets()` | Conservées, découplées : la série suit le type de client et la facture demandée (C-b) ; la clôture lira le circuit à l'étape 3. | M3, C-b |
| `DeclencherTransmissionPdpApresEmissionFacture` | Réécrit : il lit le circuit et refuse, avec un message, ce que la passerelle ne prend pas encore en charge (E13 : seule la catégorie S est prise en charge). | M3 |
| `PdpGatewayInterface`, `CalculateurPeriodeEReporting`, `GenererSoumissionEReportingAction` | Étape 2. | — |
| `SoumettreEReportingPeriodique` (commande planifiée) | Lit le régime en vigueur le jour de son exécution par `Societe::regimeEnVigueurLe()` au lieu de `societes.regime_tva`, et ne retient que les sociétés qui ont une entrée dans `regimes_tva_societe` ; même cadence, même résultat qu'avant ; son remplacement reste à l'étape 2. | M5a |
| `ModifierParametresFiscauxSocieteAction`, `ActionAuditee` (`ParametresFiscauxModifies`) | Le régime se change par une entrée datée (`regime`, `en_vigueur_le`) au lieu de la colonne ; l'événement d'audit retient le régime et sa date d'effet ; le lot CH2 ajoute `operations_hors_droit_a_deduction` aux champs du réglage et de l'audit. | M5a, CH2 |
| Écouteurs de `FactureEmise` et `AvoirEmis` (`DecrementerStockApresEmissionFacture`, `AppliquerAvoirDisponibleApresEmissionFacture`, `RestaurerStockApresEmissionAvoir`, `ImputerAvoirSurSesFacturesApresEmission`) | N'agissent ni sur une facture qui remplace un ticket (`ticket_transforme_id` non nul) ni sur un avoir d'annulation pour facture (`motif` = `annulation_pour_facture`) : l'action de la transformation fait seule l'imputation, et rien ne bouge le stock (5.6). | C-b |
| `VentileLaTvaParTaux`, `ContenuFiscal::lignesEtTotaux()` | Gardées séparées volontairement, avec un test d'égalité. | M1 |
| `ContenuFiscal`, `VerificateurIntegrite` | Modifiés pour tout le format signé des ventes au lot M3 ; ajout additif au lot C-b. | M3, C-b |
| `ConstructeurLigneAchat` | Réécrit sur la décision : types de lignes, taux facturé distinct du taux dû en France ; le taux d'une ligne ne réécrit jamais la fiche ; le bloc « Vente en magasin » corrige la fiche immédiatement (complément 2), JET 270 si le taux de vente change. | M5d |
| `LigneAchat::coutUnitaireHt()`, `montantsDuRetour()` | Le premier remplacé par la colonne `cout_unitaire_entree` ; le second délègue à `CalculDesRetours`. | M5d, M5e |
| `IncrementerStockApresValidationFactureAchat`, `ModifierFactureAchatAction::lotsDesReceptions()` | Lisent `cout_unitaire_entree` ; les lignes hors stock ne passent pas. | M5d |
| `StockLedgerService` | Conservé, signature comprise ; renommages de O1. | M7 |
| `ValeurStockApercu` | Valeur au coût, sans TVA ajoutée. | M7 |
| Bénéfice affiché à l'achat, `BaremePrixDeVente`, `Produit::synchroniserPrixDeVente()` | Bénéfice calculé par `BeneficeUnitaire` (coût d'entrée et franchise donnés par le moteur) ; le barème garde sa formule ; la conversion TTC ↔ HT de la fiche tient compte de la franchise. | M6, M7 |
| `RegimeTva` | Conservé (aligné sur `vat_regime` de SUPER PDP) ; docblock corrigé (« de la société ») ; la constante de la mention part dans la table. | M2 |
| `Societe::mentionsLegalesManquantes()` | Conservée pour l'identité ; l'entrée « n° de TVA » passe par `ExigenceDuNumeroTva`. | M2 |
| `Emetteur` | Conservé ; il lit le régime en vigueur à la date d'émission. | M5a |
| `EmettreFactureAction` | Ordre de 3.8. | M1, M2 |
| `CreerAvoirAction` | Compatibilité par `MoteurTva::compatibilite()`, mêmes messages, même exception, plus le message du circuit. | M3 |
| `PanierDeCaisse` | Le total du panier vient de l'aperçu du moteur. | M2 |
| `CreerFactureDepuisDevisAction` | Copie les faits et le geste du devis (O8). | M3 |
| `CreerDevisAction`, `ModifierDevisAction`, `ModifierFactureAction`, `EmettreDevisAction` | Faits et gestes notés, montants et nature calculés par le moteur ; l'envoi d'un devis avertit des conditions non réunies (M4). | M2, M4 |
| `FactureResource::lignesEntry()` | Lit la règle « TVA retirée du prix » sur la nature et la franchise du document. | M2 |
| Vues PDF des factures, avoirs, devis | Mentions et note figées (factures, avoirs) ; devis : moteur à la date du devis. | M3 |
| `AjouteDesLignesDAchat` (écran) | Réécrit : aperçu du moteur d'achat ; ne calcule plus ni TVA ni bénéfice. | M6 |

### 3.15 Ventes : client, circuit, natures

#### 3.15.1 Le client

| Client | Méthode de calcul | Série | Circuit (figé dès le lot M3, lu à l'étape 2) |
|---|---|---|---|
| Particulier, en France ou à l'étranger | Article par article | Ticket ; facture s'il la demande (lot C-b) | E-reporting des ventes aux particuliers |
| Professionnel assujetti établi en France (métropole, Corse, Guadeloupe, Martinique, La Réunion) | Depuis le HT de ligne (R4) | Facture | Facture électronique, quand les trois conditions sont réunies (3.15.2) |
| Professionnel établi hors de France, à Monaco, en Guyane, à Mayotte ou dans une collectivité d'outre-mer | Depuis le HT de ligne | Facture | E-reporting international |
| Professionnel non assujetti (association sans activité taxable) | Depuis le HT de ligne (O2) | Facture | E-reporting des ventes aux particuliers ; jamais en facture électronique |
| Organisme public | Depuis le HT de ligne | Facture | Chorus Pro, hors de PILIQI, avec un message |

- « Assujetti » est vrai par défaut pour un professionnel ; il se décoche à l'envers dans la section repliée « Fiscalité » de la fiche (« Ce client n'est pas assujetti à la TVA ») et n'est jamais proposé automatiquement.
- « Organisme public » est faux par défaut ; il est proposé quand la recherche au répertoire Sirene, faite par un bouton de la fiche, renvoie une catégorie juridique qui commence par 7 (`UniteSirene::$categorieJuridique`).
- Les deux sont figés à l'émission avec les autres données du client (`FigeLeClient`) et entrent dans la copie du client signée.
- « Établi en France » (qui exige le SIREN : FR, GP, MQ, GF, RE, YT, BL, MF, PM) ne se confond jamais avec le territoire de TVA français (3.10).

#### 3.15.2 Les règles du circuit

Le moteur calcule le circuit de chaque document dès le lot M3 ; la colonne `circuit` est figée et signée à l'émission. Règles, dans cet ordre :
1. Magasin de démonstration : aucun.
2. Particulier, en France ou à l'étranger, facture demandée comprise : ventes aux particuliers (art. 290 I 2°).
3. Professionnel non assujetti : ventes aux particuliers (art. 290 I 2° b), jamais en facture électronique (O2).
4. Organisme public : Chorus Pro, hors de PILIQI, avec le message « Client public : déposez cette facture sur Chorus Pro ».
5. Professionnel assujetti établi en France, opération dans le champ de la TVA française et soumise aux règles de facturation françaises : facture électronique. Cela comprend la livraison intracommunautaire à un client établi en France sous un n° étranger, l'export à un client établi en France et la livraison vers un DOM à un client établi en Guadeloupe, en Martinique ou à La Réunion.
6. Professionnel établi hors de France, à Monaco, en Guyane, à Mayotte ou dans une collectivité d'outre-mer : e-reporting international (art. 290 I 1° a, b et d, 4° a).
7. Prestation non située en France (art. 259 ou 259 A) vendue à un assujetti, établi en France ou non : e-reporting international (art. 290 I 1° c). Aucune nature ne la produit aujourd'hui (3.15.3) : la règle s'écrira avec la nature qui l'ouvrira.

**Catégories prises en charge par la passerelle.** Une table du domaine `Tva` (`CategoriesPrisesEnCharge`, une donnée datée des règles) dit quelles catégories SUPER PDP transmet : au lot M3, seule la catégorie S. Une facture au circuit « facture électronique » dont une ligne est d'une autre catégorie n'est pas transmise : l'écouteur `DeclencherTransmissionPdpApresEmissionFacture`, réécrit, la laisse hors plateforme avec le message « Pas encore prise en charge pour ce régime : la facture reste hors plateforme ». L'étape 2 ouvre les catégories une à une, après leur vérification au bac à sable.

Les règles 5 à 7 touchent une colonne signée : le bac à sable SUPER PDP les confirme à l'étape 2, avant la production ; d'ici là, aucune donnée réelle n'existe et la base se recrée.

#### 3.15.3 Destination, départ et lieu des services

- La destination des biens est l'adresse de livraison, préremplie depuis la fiche du client, avec son territoire lu par `GeographieTva` (pays et code postal).
- Le départ des biens est le territoire du magasin : le stock est celui du magasin et aucun transfert n'existe entre magasins. Il est copié dans chaque décision.
- Monaco se lit, pour des biens, par le mouvement : des biens expédiés de France vers Monaco relèvent de l'art. 290 I 4° a, quel que soit l'établissement du client ; la TVA reste française. Pour des services, Monaco se lit par l'établissement du client.
- **Lieu des services.** Entre assujettis, le lieu est celui du preneur (art. 259-1°) : prestation autoliquidée dans l'Union, hors champ hors de l'Union. Un service « au lieu fixé par la loi » (`Service::lieu_specifique`, art. 259 A : immeuble, manifestation, location courte d'un moyen de transport, restauration, transport de personnes) a le lieu de son exécution :
  - exécuté en France : TVA française, même pour un client étranger ;
  - exécuté hors de France : refus (`tva.lieu_du_service_hors_de_france`), jamais une TVA française facturée à tort ;
  - la question « Ce service est-il exécuté en France ? » se pose dès qu'un fait international existe (client établi hors de France, n° de TVA étranger, livraison hors de France, lien « Expédier hors de France » ouvert) ; sans réponse, l'émission est refusée (`tva.lieu_du_service_a_preciser`) ; sans fait international, le service est réputé exécuté en France.
- À un particulier, le lieu est celui du prestataire (la France), sauf l'art. 259 A ci-dessus et l'art. 259 B : un particulier dont l'adresse est hors de l'Union et qui achète un service reçoit l'avertissement `tva.service_hors_ue_a_verifier` (aucun marqueur « service immatériel » avant le lot M9).
- Une prestation vendue à un professionnel établi dans un DOM est refusée (`tva.prestation_vers_un_dom_non_geree`, O6).
- Le commentaire de `lieu_specifique` dans la migration des services et le message faux « … est fixé par la loi (art. 259 A du CGI) : la TVA française s'applique » sont corrigés au lot M3.

#### 3.15.4 Les natures et leurs conditions

| Nature | Conditions cumulatives (toutes évaluées par le moteur) | Catégorie (déduite) | Mention (table de départ, recopiée du code) | Preuves attendues | Circuit |
|---|---|---|---|---|---|
| Vente en France | Par défaut | S (E en franchise) | Mentions générales | — | Selon 3.15.1 |
| Export hors UE expédié par le magasin | Des biens et leurs frais accessoires ; sortie « expédiés par le magasin » ; adresse complète hors du territoire de TVA de l'Union, ni à Monaco ni dans un DOM ; départ du territoire du magasin ; n° de TVA de la société | G | « Exonération de TVA, article 262 I du CGI » | MRN ou CN23 | International (professionnel non établi) ; facture électronique (professionnel établi en France) ; ventes aux particuliers |
| Livraison vers un DOM | Des biens ; expédiés par le magasin ; adresse complète dans un DOM autre que celui du magasin ; magasin de métropole ou de Corse ; n° de TVA de la société | G, à confirmer (6.2, EC3) | « Exonération de TVA en application de l'article 294 du Code général des impôts » | MRN ou CN23, et un document de transport | Facture électronique (Guadeloupe, Martinique, La Réunion) ; international (Guyane, Mayotte) ; ventes aux particuliers |
| Livraison intracommunautaire à un professionnel | Client professionnel ; n° de TVA d'un autre État membre (XI admis), vérifié valide sur VIES le jour de l'émission ; biens livrés dans un autre État membre ; date de livraison ; sortie « expédiés par le magasin » ou « emportés par le client » ; seulement des biens et leurs frais ; n° de TVA de la société | K | « Exonération de TVA, article 262 ter I du CGI » | Deux documents de transport de catégorie a), ou un a) et un b), émis par deux parties différentes, indépendantes l'une de l'autre, du magasin et du client (lot C-a) ; attestation de l'acquéreur au plus tard le 10 du mois suivant s'il emporte les biens | International (client non établi) ; facture électronique (client établi en France sous un n° étranger) |
| Prestation à un professionnel de l'UE (autoliquidation) | Client professionnel établi dans un autre État membre (jamais l'Irlande du Nord) ; n° de TVA valide sur VIES le jour de l'émission ; seulement des services, aucun au lieu fixé par la loi ; n° de TVA de la société | AE | « Autoliquidation » | Vérification VIES | International ; relevé du mois |
| Prestation à un professionnel hors UE | Client professionnel établi hors de l'Union (Irlande du Nord comprise, Monaco exclu) ; n° de TVA ou autre identifiant ; seulement des services, aucun au lieu fixé par la loi ; document séparé ; n° de TVA de la société | O, sans taux | « TVA non applicable – article 259-1° du CGI » | Identifiant du client | International |

- Prix payé (K3, TVA4) : hors de la vente en France et hors franchise, le client paie le prix TTC de la fiche ÷ (1 + taux), calculé comme pour son type de client ; pour un service, le prix de référence de sa fiche suit la même règle, sans que ce soit une dérogation de prix (`DerogationsDePrix` compare le PU TTC, la remise et le prix et le taux de référence, c'est-à-dire `taux_tva_du_prix`, jamais le taux que décide le moteur).
- Une nature dont une condition tombe avant l'émission (VIES devenu invalide, adresse changée) bloque l'émission avec la voie « Rester à la TVA française » ; elle ne retombe jamais en silence.
- Export emporté par le client (hors de l'Union) : pas encore géré ; la vente reste à la TVA française, avec l'information `tva.export_emporte_non_gere` (« Export emporté par le client : pas encore géré, la vente reste à la TVA française (détaxe possible pour un voyageur) »).
- Un produit qui porte une particularité fiscale (accises, moyen de transport neuf) ferme les natures d'exonération (`tva.cas_exclu`) ; la vente en France reste possible.

#### 3.15.5 Magasins des DOM (O6)

- Magasin de métropole ou de Corse qui expédie vers un DOM : nature « Livraison vers un DOM ».
- Magasin de Guadeloupe, de Martinique ou de La Réunion qui vend sur place : TVA du département, au taux de la fiche (8,5 % par défaut).
- Magasin des DOM qui expédie hors de son département (métropole comprise) : refus `tva.depart_hors_du_dom_non_gere`, jusqu'au lot M9.
- Prestation à un professionnel établi dans un DOM : refus `tva.prestation_vers_un_dom_non_geree`, jusqu'au lot M9.

#### 3.15.6 Société en franchise

- Toutes ses ventes en France à 0 %, au même prix TTC, catégorie E, mention « TVA non applicable, art. 293 B du CGI » ; jamais S à 0 %.
- Une livraison à un professionnel de l'Union reste couverte par la franchise (mention de franchise), pas par l'exonération de l'art. 262 ter ; un export garde aussi la mention de franchise (TVA10 = a).
- Une prestation à une entreprise établie hors de France suit les règles de lieu (TVA10 = a) : autoliquidation dans l'Union, hors champ ailleurs, avec leurs mentions et non celle de franchise ; elle exige le n° de TVA de la société ; elle se paie au même prix TTC que le catalogue, aucune TVA française n'étant comprise dans les prix d'une société en franchise.
- Le seuil des ventes à distance s'applique (3.15.7) : une expédition à un particulier de l'Union compte au compteur et se refuse au-delà du seuil.
- Un brouillon rouvert après le passage en franchise reprend le prix TTC du catalogue de ses produits et de ses services, à 0 %, avec une notification (complément 5).

#### 3.15.7 Ventes à distance (lot M8)

- **Le fait** : biens expédiés par le magasin vers un autre État membre, à un particulier ou à un client professionnel marqué « non assujetti », sans n° de TVA valide sur VIES. Le moteur le calcule dès le lot M3 (colonne `vente_a_distance` figée et signée) ; il est évalué avant la branche de la franchise.
- Un professionnel de l'UE sans n° de TVA et non marqué « non assujetti » : la vente reste en France, non comptée, avec l'avertissement `tva.vente_a_distance_possible` (« S'il n'est pas assujetti ou relève du régime dérogatoire de son pays, c'est une vente à distance : marquez-le « non assujetti » sur sa fiche. »).
- **Le compteur de la société** (`CompteurDesVentesADistance`, lecture hors du magasin de la puce 2 de « Produit ») : pour une année civile A, Compteur(A) = somme, sur les magasins réels de la société (démonstration exclue), du HT des lignes des factures émises en A marquées `vente_a_distance`, moins le HT des lignes des avoirs émis en A marqués `vente_a_distance` ; plus, si A est l'année de départ Y0 (`ventes_a_distance_annee_depart`), la valeur de départ de l'année en cours ; plus, si A = Y0 − 1, la valeur de départ de l'année précédente. Sommes en centimes, côté PHP.
- **Refus** (`tva.seuil_ventes_a_distance_depasse`) d'une émission marquée vente à distance quand Compteur(année précédente) > 10 000,00 €, ou quand Compteur(année de l'émission) + HT de la facture > 10 000,00 € (strictement) ; refus `tva.oss_non_gere` de toute vente à distance quand la société est inscrite au guichet OSS à la date de l'émission. Message : « Au-delà de 10 000 € de ventes à distance dans l'Union, la TVA du pays du client s'applique : PILIQI ne la gère pas encore. La vente au comptoir reste possible. »
- **État du seuil**, calculé sur Compteur(année de l'émission) + HT de la facture : sous 9 000,00 € (valeur de la table datée des seuils), information `tva.vente_a_distance_seuil_non_atteint` (« Vente à distance : seuil non atteint. »), affichée dans le panneau seulement, ni répétée dans la fenêtre d'émission ni gardée parmi les avertissements de la décision (TVA8 : l'avertissement commence à 9 000 €) ; de 9 000,00 € à 10 000,00 €, avertissement `tva.vente_a_distance_seuil_proche` (« Vente à distance : seuil proche. »), répété dans la fenêtre d'émission et gardé dans la décision ; au-delà, « seuil atteint », avec le refus ci-dessus. Aucun montant dans ces messages : un vendeur ne voit que l'état (TVA7).
- **Concurrence** : le compteur se lit sous le verrou de la ligne `societes`, pris à sa place dans l'ordre du lot A4 (après les registres, avant les comptes), en `FOR NO KEY UPDATE` (`lock('for no key update')`), jamais en `FOR UPDATE` : chaque inscription au journal d'audit vérifie sa clé étrangère vers cette ligne par un `FOR KEY SHARE`, qu'un `FOR UPDATE` bloquerait ; le propriétaire qui valide un achat dans un magasin pendant qu'il émet une vente à distance dans un autre s'interbloquerait sinon. Deux `FOR NO KEY UPDATE` s'excluent : les ventes à distance de la société restent sérialisées entre elles, et avec la modification de ses paramètres fiscaux (`FOR UPDATE`, même ordre : registres, puis société). Si l'émission livrée par la chaîne société ne le prend pas toujours, elle le prend quand le brouillon, relu après le verrou du registre, porte une expédition vers un autre État membre à un particulier ou à un client non assujetti ; si le moteur constate ensuite une vente à distance sans que ce verrou ait été pris (le brouillon a changé entre-temps), l'émission est refusée (`tva.brouillon_change_pendant_l_emission` : « Ce brouillon vient de changer : émettez-le de nouveau. ») sans rien écrire. Deux émissions simultanées dans deux magasins ne dépassent donc jamais le seuil.
- **Qui voit quoi** : le montant se voit dans l'espace de la société, du propriétaire et des administrateurs autorisés à gérer ses paramètres ; le panneau d'un document ne montre que l'état (« seuil non atteint », « seuil proche », « seuil atteint »).
- **Valeurs de départ et OSS** : saisies dans les paramètres fiscaux de la société (année de départ, total de l'année précédente, total de l'année en cours avant l'arrivée dans PILIQI ; « Votre société est-elle inscrite au guichet OSS ? Depuis le … »), inscrites au JET 270 de chaque magasin et au journal d'audit (`ParametresFiscauxModifies`).
- Une fois le seuil atteint, plus aucune expédition à un particulier (ou à un client non assujetti) de l'Union jusqu'au 31 décembre ni pendant toute l'année suivante, tant que l'OSS n'existe pas (TVA8 = a).

#### 3.15.8 Relevé du mois (lot M4)

- La société dépose elle-même l'état récapitulatif de ses livraisons intracommunautaires et la déclaration européenne de services ; PILIQI prépare la liste.
- Contenu : pour un mois, par n° de TVA du client, le HT des livraisons intracommunautaires et celui des prestations autoliquidées, nets des avoirs émis dans le mois, lus sur les documents émis (nature figée, montants des lignes), sans rien recalculer ; démonstration exclue.
- Dans chaque magasin (`ReleveDuMois`), pour qui voit toutes ses factures (`facture.view_toutes`) ; pour la société (`ReleveFiscalDeLaSociete`, lecture hors du magasin), dans son espace, pour le propriétaire et les administrateurs autorisés à gérer ses paramètres. Export CSV (colonnes : n° de TVA, client, HT des livraisons, HT des prestations), séparateur point-virgule, montants à virgule.

### 3.16 Écrans de vente, caisse, devis et avoirs

#### 3.16.1 France d'abord

- Pour un particulier, un professionnel établi en France ou une vente au comptoir, le formulaire n'a **aucun champ de plus qu'aujourd'hui**.
- La liste « Nature de l'opération » disparaît au lot M4. Elle est remplacée par une ligne repliée, « TVA française · vente en France », et par un lien discret, **« Expédier hors de France ou livrer à une autre adresse ? »**, présent pour tout client, qui ouvre la même suite de questions que pour un client étranger.

#### 3.16.2 Préremplissage

Dans cet ordre ; chaque valeur préremplie affiche sa source en petit (« d'après la fiche du client », « comme la facture FAC-LYON1-2026-000123 du 02/10/2026 ») et la décision la garde avec sa source :
1. le document lui-même (brouillon rouvert, ou devis converti : O8) ;
2. la fiche du client (l'adresse de livraison reprend son adresse) ;
3. la dernière facture émise au même client, dans le magasin : sa réponse à « Les marchandises quittent-elles la France ? » et sa destination sont **proposées, jamais tenues pour vraies**, et se confirment d'un clic dans la fenêtre « Émettre » (TVA3) ;
4. le magasin, puis la société : territoire, taux par défaut, régime de TVA à la date du document ;
5. la valeur sûre : vente en France.

La date de livraison se préremplit avec la date du jour (« à corriger si la livraison a lieu un autre jour »). Ne se reprennent jamais d'un autre document : un résultat VIES d'un autre jour, une preuve, un geste sur le régime (sauf du devis à sa facture).

#### 3.16.3 Questions progressives

Des boutons plutôt que des listes, une question à la fois ; elles disparaissent dès qu'elles ne s'appliquent plus.

| Déclencheur | Question ou message | Réponses | Effet |
|---|---|---|---|
| Des biens, **et** un client établi hors du territoire de TVA français, ou un n° de TVA d'un autre État, ou une adresse de livraison hors de France, ou le lien ouvert | « **Les marchandises quittent-elles la France ?** » | Non, remises ou livrées en France · Oui, nous les expédions (ou les faisons expédier) · Oui, le client les emporte ou les fait transporter | Sans réponse par défaut, sauf la proposition de la dernière facture ; exigée à l'émission (`tva.sortie_des_biens_inconnue`). |
| Réponse « Oui » | « **Où sont-elles livrées ?** » : adresse du client préremplie, pays dans une liste de pays nommés, territoire détecté affiché (« Canaries : hors TVA de l'Union », « Martinique : département d'outre-mer », « Monaco : TVA française ») | Adresse | Le moteur évalue export, livraison vers un DOM, livraison intracommunautaire, vente à distance, Monaco. |
| Livraison dans un autre État membre, client professionnel | « **Date de livraison ?** », préremplie avec la date du jour | Date | Condition de la livraison intracommunautaire. |
| « Le client les emporte », vers un autre État membre | Information : « Le client devra vous remettre une attestation de réception au plus tard le 10 du mois suivant. » | — | Preuve attendue. |
| « Le client les emporte », hors de l'Union | Information `tva.export_emporte_non_gere` | — | Vente en France. |
| Client professionnel de l'UE sans n° de TVA | « Ajoutez le n° de TVA du client pour vendre sans TVA française » · bouton « Compléter la fiche » ; avertissement `tva.vente_a_distance_possible` | — | Après l'enregistrement de la fiche : VIES, puis nouvelle évaluation. |
| VIES ne reconnaît pas le n° | « VIES ne reconnaît pas DE123456789 aujourd'hui (11/10 à 10 h 32). La vente reste à la TVA française. » · « Vérifier à nouveau » | — | Vente en France. |
| VIES ne répond pas | « VIES ne répond pas en ce moment (service de l'État membre indisponible). Réessayez : la facture peut être émise jusqu'au 15/11/2026. » · « Réessayer » | — | À l'émission : rien n'est émis, le brouillon reste (`tva.vies_injoignable`). |
| Un service au lieu fixé par la loi, **et** un fait international | « **Ce service est-il exécuté en France ?** (travaux sur un immeuble, événement, location courte de véhicule, restauration…) » | Oui, en France · Non, hors de France | Non : refus `tva.lieu_du_service_hors_de_france`. Sans réponse : exigée à l'émission. |
| Services (lieu non fixé par la loi) à un professionnel établi hors de France | Aucune question | — | Prestation autoliquidée ou hors Union **proposée** (bouton « Appliquer »). |
| Particulier, ou client non assujetti, livré dans un autre État membre | État de la vente à distance : « seuil non atteint » (information, sous 9 000 €), « seuil proche » (avertissement, dès 9 000 €), « seuil atteint » (refus) | — | Lot M8. |
| Particulier dont l'adresse est hors de l'Union, avec un service | Avertissement `tva.service_hors_ue_a_verifier` | — | Vente en France. |
| Dans une nature autre que la vente en France, une ligne qu'elle n'admet pas | « Cette facture ne porte qu'un régime : déplacez ces lignes vers une nouvelle facture. » · bouton « Déplacer ces lignes vers une nouvelle facture » | — | Refus à l'émission (`tva.une_nature_par_document`, `tva.document_separe_requis`). |
| Produit soumis à accises, ou moyen de transport neuf, dans une vente hors de France | « Vente hors de France de ce produit : non gérée automatiquement (accises, moyen de transport neuf). » | — | Nature refusée (`tva.cas_exclu`) ; la vente en France reste possible. |

#### 3.16.4 Le panneau « Régime de TVA de cette vente »

Sur grand écran, sous le client, dans une colonne latérale ; sur téléphone, à pleine largeur après le client. La fiche d'un document émis montre la même présentation, relue de la décision figée. Un mot accompagne toujours la couleur.

| État | Présentation |
|---|---|
| France | Une ligne repliée : « TVA française · vente en France ». |
| Appliqué d'office | Encadré vert : « Livraison intracommunautaire appliquée d'après vos réponses : le client paie 100,00 € au lieu de 120,00 € », lien « Rester à la TVA française », détail ouvert. |
| Proposé | Encadré bleu : « Prestation à une entreprise de l'Union possible : le client paierait 100,00 € au lieu de 120,00 € » · bouton « Appliquer » · lien « Rester à la TVA française ». |
| Appliqué | Encadré vert, après un geste « Appliquer ». |
| Incomplet | Encadré orange : « Livraison intracommunautaire possible. Il manque : un n° de TVA valide sur VIES aujourd'hui. Tant qu'il manque, la vente reste à la TVA française. » Chaque manque est un lien qui place le curseur sur le champ. |
| Bloquant | Encadré rouge, repris dans la fenêtre « Émettre » : « Impossible d'émettre : … », toujours la liste complète. |

Le total « au lieu de » vient du moteur (P9) : aucun calcul dans l'écran.

**Détail (exemple de Müller GmbH)** :

```
Régime de TVA de cette vente
Livraison intracommunautaire à un professionnel : sans TVA française

Taux                0 % sur toutes les lignes (la TVA française est retirée du prix catalogue)
Qui paie la TVA     le client, dans son pays (il déclare lui-même la TVA allemande)
Mention imprimée    « Exonération de TVA, article 262 ter I du CGI »

Pourquoi
  Client professionnel établi en Allemagne ................................. oui
  N° de TVA DE123456789 valide sur VIES le 14/10/2026 à 10 h 32 (preuve VIES conservée) ... oui
  Marchandises expédiées par le magasin, de Lyon à Berlin ................... oui
  Date de livraison : 14/10/2026 ............................................ oui
  Uniquement des marchandises et leurs frais de port ........................ oui

Après l'émission    deux documents de transport, de deux transporteurs ou organismes
                    indépendants de vous et du client
À faire             émettre la facture au plus tard le 15/11/2026 ; relevé du mois
                    pour l'état récapitulatif, à déposer vous-même sur le portail de la douane
Transmission        SUPER PDP la déclare pour vous (e-reporting : client établi hors de France)
```

**Libellés** (aucun code à l'écran ; le texte vient de la table datée, avec la décision) :

| Élément | Libellé |
|---|---|
| Vente en France | « TVA française » · « Le client paie la TVA comprise dans vos prix. » |
| Export | « Export hors de l'Union européenne : sans TVA française » · « Le client paie votre prix sans la TVA française (120,00 € → 100,00 €). À conserver : la déclaration d'export (MRN) ou le CN23 du colis. » ; Royaume-Uni, Suisse, Norvège : « Le pays d'arrivée peut percevoir sa propre TVA. » |
| Livraison vers un DOM | « Expédition vers la Martinique (département d'outre-mer) : sans TVA française » · « La TVA locale et l'octroi de mer sont payés à l'arrivée. » |
| Livraison intracommunautaire | Exemple ci-dessus · « Le client déclare la TVA de son pays. » |
| Prestation à une entreprise de l'Union | « Prestation à une entreprise de l'Union : TVA du pays du client » · « Le client déclare la TVA de son pays. Mention : « Autoliquidation ». » |
| Prestation hors Union | « Prestation à une entreprise hors Union : hors TVA française » · « Cette facture ne doit contenir que ces services. » |
| Franchise de la société | « Votre société est en franchise de TVA » · « Aucune TVA : « TVA non applicable, art. 293 B du CGI ». » |
| Transmission | « SUPER PDP la transmet comme facture électronique » ; « SUPER PDP la déclare pour vous (e-reporting) » ; « Hors plateforme : client public, dépôt sur Chorus Pro » ; « Pas encore prise en charge pour ce régime : la facture reste hors plateforme » ; « Aucune : magasin de démonstration ». |
| VIES | « N° valide sur VIES le 14/10/2026 à 10 h 32 (preuve VIES n° … conservée) » : le n° de preuve donné par VIES, jamais l'identifiant interne. |
| Achats et charges | « TVA déductible », comme sur la déclaration de TVA ; dans les totaux des charges et l'en-tête de leur export : « TVA déductible (droit ouvert par ces pièces) », avec la ligne fixe de 4.11 : le mois où cette TVA se récupère n'est pas encore calculé, et le total ne se reporte pas tel quel sur une déclaration. |

#### 3.16.5 Gestes, changement de régime, fenêtre « Émettre »

- Le lien « Rester à la TVA française » enregistre le geste « TVA française gardée » (colonnes `nature_confirmee`, `nature_confirmee_par`, `nature_confirmee_par_nom`, `nature_confirmee_le`). Le bouton « Appliquer » enregistre le geste « appliquer » sur la nature proposée.
- Le geste s'efface quand un fait déclencheur change : client, sortie des biens, livraison, composition en biens et services, lieu des services (`NoteSaNatureDOperation`, réécrit au lot M3).
- Le menu « Changer de régime » ne liste que les natures permises au client et à la société, chacune avec son état : « possible », « il manque : … » ou « impossible : … ». Choisir une nature ouvre les questions qui lui manquent ; une nature dont une condition manque ne s'applique jamais.
- La **fenêtre « Émettre »** rassemble, avant le numéro : (1) le régime et le total ; (2) une nature encore proposée : « Appliquer et émettre » ou « Émettre à la TVA française », enregistré comme un geste (`tva.choix_du_regime_requis` tant qu'il manque) ; (3) une réponse proposée d'après la dernière facture et pas encore confirmée : « Confirmer » ; (4) un calcul du brouillon changé depuis son enregistrement (type de client, régime de la société, taux de la fiche, nature, montants) : l'avant et l'après, lignes et total, avec « Mettre à jour et émettre », qui enregistre le brouillon recalculé puis émet (un refus à l'émission laisse le brouillon à jour) ; (5) les avertissements (vente à distance dès 9 000 €, preuves à fournir, facture au plus tard le 15 du mois suivant, relevé du mois) ; les informations du panneau (« seuil non atteint », export emporté non géré) n'y sont pas répétées. Tous les refus s'affichent ensemble, avant l'avertissement de vente sans stock (R1).
- **Brouillon rouvert ou devis converti** : le moteur réévalue ; une notification persistante dit ce qui a changé.
- **Fiche d'un document émis** : une section « Régime de TVA », relue de la décision figée (régime, conditions telles qu'à l'émission, consultation VIES retenue, geste avec son auteur et l'instant, version des règles, avertissements, preuves attendues), à la place de la partie fiscale de la section « Transmission électronique » actuelle ; le statut de transmission reste affiché à côté.

#### 3.16.6 Caisse

- Ticket ordinaire : aucune question, aucun panneau. La caisse appelle le moteur avec « remis en France » ; la décision est signée comme pour une facture.
- Client professionnel établi hors de France : une ligne d'information, « Vente en France : marchandises remises en magasin. Pour une expédition hors de France, faites une facture. »
- Services au comptoir à une entreprise établie hors de France : un avertissement, « Pour des services à une entreprise étrangère, la TVA française ne s'applique pas toujours : faites une facture. »
- La caisse ne propose jamais d'exonération ; un particulier de l'UE qui emporte ses achats paie la TVA française, sans question.
- Un produit à 0 % n'existe pas pour une société redevable : la fiche le refuse, la caisse ne le rencontre jamais.
- Lot C-b : la case « Le client demande une facture », qui exige un client particulier avec nom et adresse (fiche choisie ou créée dans la caisse), et le bouton « Transformer en facture » sur la fiche d'un ticket émis.
- Lot C-a : retour ou avoir sur un ticket seulement avec l'autorisation `avoir.sur_ticket`, qui suffit seule (complément 16).

#### 3.16.7 Devis, avoirs, fiches

- **Devis** : même panneau et mêmes questions ; rien n'est figé. À l'envoi, un avertissement (jamais un refus) liste les conditions non réunies. Le PDF rappelle le moteur avec les faits du devis et les règles du jour de son émission. La facture issue d'un devis reprend ses faits et son geste, revérifiés le jour de la facture (O8).
- **Avoir** : aucune question ; chaque ligne hérite de la nature de sa ligne de facture. L'écran d'un avoir sur plusieurs factures regroupe les factures compatibles (type de client, franchise, nature, circuit) et grise les autres dès qu'une première est choisie ; les refus gardent leurs messages actuels (`CreerAvoirAction`), un seul s'ajoute : « Un avoir ne mélange pas des factures transmises par des circuits différents : faites deux avoirs. »
- **Fiche client** : pays dans une liste de pays nommés ; section repliée « Fiscalité » (« Ce client n'est pas assujetti à la TVA », « Organisme public », bouton « Rechercher au répertoire Sirene ») ; n° de TVA avec son état VIES.
- **Fiche produit** : « Taux de TVA de vente » (refus du taux 0 pour une société redevable, `tva.taux_zero_en_france`) ; « Particularité fiscale » (aucune, accises, moyen de transport neuf) dans une section repliée. Fiche service : taux 0 refusé de même.

### 3.17 Achats

#### 3.17.1 Faits demandés et leur origine

| Fait | Source par défaut | Demandé quand | Absent |
|---|---|---|---|
| Établissement du fournisseur (France, Monaco, ailleurs) | Fiche : calculé depuis le pays ; case « facture depuis un établissement en France » pour un pays étranger | Une fois, sur la fiche | — |
| « Ne facture pas de TVA », n° de TVA et état VIES | Fiche | Une fois, sur la fiche | Selon la nature |
| Origine réelle des marchandises (France ou Monaco · autre pays de l'Union · hors de l'Union ; pays ; code postal pour un territoire exclu) | « France » pour un fournisseur établi en France ; sinon celle de la dernière facture validée du même fournisseur, dans le magasin, déjà confirmée, avec sa source affichée ; sinon son pays, proposé à confirmer | Dès qu'il y a une ligne de biens (produit, ou charge portant sur un bien) | Bloquant pour un fournisseur non établi, à sa première facture ou quand l'origine change, tant qu'elle n'est pas confirmée (`tva.origine_non_confirmee`) ; une origine reprise telle quelle de sa dernière facture validée se valide sans nouvelle confirmation (TVA3 ; A08 à A08c) |
| Lieu d'arrivée | Dérivé : le magasin | Jamais | Une livraison directe chez un client est hors périmètre (saisie manuelle) |
| Dédouanement (nous ou notre transitaire · le fournisseur) | Dernière facture validée du même fournisseur | Origine hors de l'Union, dans un DOM, une collectivité d'outre-mer ou un territoire exclu, quel que soit le fournisseur | Bloquant (`tva.dedouanement_inconnu`) |
| Déclaration en douane (référence MRN, date du bon à enlever, valeur, droits, autres taxes, frais jusqu'au premier lieu de destination, TVA par taux) | Aucune | Dédouanement par nous | Avertissement et liste « Dédouanement à compléter » (`tva.dedouanement_a_completer`) |
| TVA portée par la facture d'un fournisseur non établi | « Non » | Fournisseur non établi | TVA15 = b (3.17.8) |
| Nature de la pièce (facture au nom de la société ou du magasin · ticket ou reçu) | « Facture » pour un fournisseur qui a un SIRET et pour une facture reçue par l'API (`source` = `api`) ou, à l'étape 2, par SUPER PDP ; aucune sinon | Toujours, en tête de la facture d'achat ou de la charge (lot M5c pour le fait, M5d pour la colonne, M6 pour l'écran) | Bloquant sans réponse (`achat.nature_piece_inconnue`) ; « ticket ou reçu » : TVA déductible 0 sur toutes les lignes, avec la raison « Ticket ou reçu sans facture : TVA non déductible ; demandez une facture au fournisseur (art. 271 II du CGI) » |
| Type de chaque ligne (produit · frais d'acheminement · charge) | Le bouton utilisé | Toujours | — |
| Catégorie, nature (bien ou service) et précision d'une ligne de charge | Nature proposée par la catégorie (4.2) ; précision demandée pour Loyer, Entretien, Transport et Autres charges (lot CH2) | Ligne de charge | Catégorie absente : bloquant (`achat.categorie_absente`) ; précision absente : bloquant (`charge.precision_absente`) |
| Taux facturé | Taux de vente du produit, lié tant qu'on ne le change pas (achat en France) ; 0 sinon | Achat en France | — |
| Raison d'un taux zéro (`franchise`, `autoliquidation`, `importation`, `exoneree`) | `exoneree`, proposée d'office pour une ligne de charge des catégories Loyer, Assurances et Frais bancaires ; aucune sinon | Ligne à 0 % d'un fournisseur établi en France ; ligne de charge des catégories Assurances ou Frais bancaires d'un prestataire non établi, par la question « Cette ligne est-elle une prime d'assurance ou une opération bancaire exonérée ? » (« Oui » d'office, modifiable) | Bloquant (`tva.taux_zero_sans_raison`) |
| Taux dû en France | Taux de vente du produit ; pour un service ou une charge, le taux normal du territoire du magasin | Natures autoliquidées (jamais pour une ligne exonérée) | Avertissement s'il est inhabituel (`tva.taux_inhabituel`) |
| Lieu fixé par la loi (immeuble, transport de personnes, repas, location courte de véhicule, événement) | Non | Ligne de service, de frais ou de charge d'un fournisseur non établi | Cochée : cas exclu, saisie manuelle |
| N° et date de la facture du fournisseur | Saisie | Toujours visibles | Bloquant pour un fournisseur non établi (`achat.numero_fournisseur_absent`, `achat.date_fournisseur_absente`) ; date bloquante aussi pour toute facture qui porte une ligne de charge, facture de marchandises comprise (`charge.date_piece_absente`), pour que chaque ligne de charge appartienne à un mois des totaux (4.11) |
| Total TTC de la facture du fournisseur (contrôle) | Aucune | Facultatif | — |
| Devise et taux de change avec sa source | Euro | Devise autre que l'euro | Bloquant sans taux (`tva.devise_sans_taux`) |
| Date de réception d'une acquisition ; date de réalisation d'un service autoliquidé | Date de la facture | Étape 5 seulement | — |

#### 3.17.2 Nature de chaque ligne

Une ligne de charge suit les règles d'un bien ou d'un service selon sa nature (`nature_charge`, proposée par la catégorie, modifiable) ; elle n'entre jamais en stock ni dans le coût des produits.

| Ligne | Fournisseur | Origine et dédouanement | Société | Nature |
|---|---|---|---|---|
| Produit, ou charge sur un bien | Établi en France, facture avec TVA | France | Toutes | Achat en France |
| Produit, ou charge sur un bien | Établi en France, ligne à 0 % | France | Toutes | Question « Pourquoi cette ligne n'a pas de TVA ? » : franchise → achat à un fournisseur en franchise ; exonérée (charge) → achat en France exonéré, sans TVA déductible ; « Autoliquidation » → cas exclu ; erreur de saisie → taux de vente repris |
| Produit, ou charge sur un bien | Établi en France, « ne facture pas de TVA » | France | Toutes | Achat à un fournisseur en franchise |
| Produit, ou charge sur un bien | Établi en France | Autre État membre | Toutes | Cas à vérifier (`tva.faits_contradictoires`), saisie manuelle |
| Produit | Tous | Hors de l'Union, DOM, collectivité d'outre-mer ou territoire exclu, dédouané par nous | Redevable | Importation |
| Charge sur un bien | Tous | Hors de l'Union, dédouané par nous | Toutes | Cas exclu (importation d'un bien qui n'entre pas en stock), saisie manuelle |
| Produit, ou charge sur un bien | Établi à Monaco (n° de TVA FR) | Monaco ou France | Toutes | Achat auprès de Monaco : TVA française facturée |
| Produit, ou charge sur un bien | Non établi, n° de TVA d'un autre État membre dont le préfixe est le pays d'origine (EL pour la Grèce, XI pour l'Irlande du Nord) | Autre État membre | Redevable, avec un n° de TVA FR | Acquisition intracommunautaire |
| Produit, ou charge sur un bien | Non établi, préfixe différent du pays d'origine, ou facture portant « art. 141 » ou « opération triangulaire » | Autre État membre | Toutes | Cas à vérifier (`tva.operation_triangulaire_possible`), saisie manuelle |
| Produit, ou charge sur un bien | Non établi, n° de TVA d'un autre État membre | Autre État membre | En franchise | Refus (`tva.achat_etranger_en_franchise_non_gere`, TVA10 = a) |
| Produit, ou charge sur un bien | Non établi, sans n° de TVA de l'Union, ou « ne facture pas de TVA » | Autre État membre | Toutes | Cas exclu, saisie manuelle |
| Produit, ou charge sur un bien | Non établi | Autre État membre, TVA étrangère facturée | Redevable | Acquisition intracommunautaire avec TVA étrangère à régulariser (TVA15 = b) |
| Produit, ou charge sur un bien | Non établi | France, ou hors de l'Union dédouané par le fournisseur | Redevable | Livraison en France par un non-établi (autoliquidation) |
| Produit, ou charge sur un bien | Tous | Hors de l'Union, dédouanement inconnu | Toutes | Bloquant (`tva.dedouanement_inconnu`) |
| Produit | Tous | Hors de l'Union | En franchise | Refus (`tva.achat_etranger_en_franchise_non_gere`) |
| Produit soumis à accises, ou moyen de transport neuf | Non établi | Hors de France | Toutes | Cas exclu, saisie manuelle |
| Frais d'acheminement facturés par le fournisseur des marchandises | — | — | — | Même nature que les marchandises de la facture |
| Frais d'acheminement d'un tiers établi en France, ligne à 0 %, raison « importation », rattachés à une importation (lot M7) | — | — | Toutes | Achat en France exonéré (art. 262 II 14°) ; avertissement `tva.frais_hors_base_importation` si la déclaration rattachée ne compte aucun frais accessoire |
| Frais d'acheminement sans aucune ligne de produit sur la facture ni facture rattachée | — | — | — | Refus `achat.frais_sans_produit` (« Des frais d'acheminement se répartissent sur les produits de leur facture : saisissez-les comme une charge, ou rattachez-les à la facture des marchandises. ») |
| Service, frais d'un tiers, ou charge sur un service | Établi en France | — | Toutes | Achat en France (ou fournisseur en franchise ; exonérée pour une charge des catégories loyer, assurances, frais bancaires sans TVA) |
| Charge sur un service des catégories Assurances ou Frais bancaires | Non établi | — | Toutes | Achat exonéré (art. 261 C 1° et 2° du CGI), raison `exoneree` posée d'office : aucune TVA autoliquidée, TVA déductible 0, TVA à payer à l'État 0 ; la réponse « Non » à la question de 3.17.1 (frais soumis à la TVA, location d'un terminal de paiement) fait suivre les lignes suivantes. L'autoliquidation ne change que le redevable d'une taxe due : elle ne rend pas imposable une opération exonérée. |
| Service, frais d'un tiers, ou charge sur un service | Non établi, lieu non fixé par la loi | — | Redevable | Services d'un prestataire non établi (autoliquidation) |
| Service, frais d'un tiers, ou charge sur un service | Non établi, lieu non fixé par la loi | — | En franchise | Services d'un prestataire non établi, autoliquidés sans déduction (TVA10 = a) |
| Service, ou charge sur un service | Non établi, lieu fixé par la loi | — | Toutes | Cas exclu, saisie manuelle |
| Toute ligne | — | Achat d'un magasin des DOM venu d'ailleurs que de son département | — | Cas exclu (octroi de mer), saisie manuelle |

Le pays du fournisseur ne décide d'aucune ligne : un fournisseur allemand qui livre depuis un entrepôt en France n'est pas une acquisition (A09) ; un fournisseur allemand qui facture depuis son établissement en France facture la TVA française (A10) ; un distributeur belge qui livre depuis l'Allemagne est une opération triangulaire possible (A31) ; un fournisseur établi en France qui vend des marchandises venues de Chine, dédouanées par le magasin, donne une importation (A34).

#### 3.17.3 Calcul

HT de chaque ligne = quantité × PU HT, remise déduite (`montantsDAchat()`), converti en euros pour une devise (3.17.7).

| Nature | TVA facturée | TVA autoliquidée | TVA déductible (société redevable) | Dû au fournisseur | TVA à payer à l'État pour cet achat |
|---|---|---|---|---|---|
| Achat en France | Selon 6.1 c : option 1 (recommandée), total HT de chaque taux facturé × taux, arrondi une fois, réparti sur les lignes par cumul ; option 2, HT × taux facturé, par ligne | 0 | TVA facturée × pourcentage récupérable | HT + TVA facturée | — |
| Fournisseur en franchise ; achat exonéré | 0 | 0 | 0 | HT | — |
| Achat auprès de Monaco | Comme un achat en France | 0 | TVA facturée × pourcentage récupérable | HT + TVA facturée | — |
| Acquisition intracommunautaire ; livraison en France par un non-établi ; services d'un non-établi | 0 | Taux dû en France, sur le total HT de chaque taux, arrondie une fois, répartie par cumul (TVA13) | TVA autoliquidée × pourcentage récupérable | HT (+ TVA étrangère éventuelle) | TVA autoliquidée − TVA déductible |
| Importation | 0 | TVA de la déclaration en douane, reprise, jamais recalculée | TVA d'importation | Prix facturé ; les droits se paient à la douane ou au transitaire | 0,00 € |

- Pourcentage récupérable : 100 % pour une ligne de produit ou de frais ; pour une ligne de charge, celui de la règle de sa catégorie et de sa précision (4.3) ; 0 pour toutes les lignes d'une pièce qui n'est pas une facture au nom de la société ou du magasin ; 0 pour une société en franchise. TVA déductible d'une ligne dont le pourcentage n'est ni 0 ni 100 % : selon 6.1 d (recommandation : arrondi(TVA × pourcentage), un seul arrondi au centime, la moitié arrondie en s'éloignant de zéro ; pour un retour, la même règle appliquée à la TVA rendue, 3.17.6).
- Ligne exonérée d'un prestataire non établi (assurance, opération bancaire) : aucune TVA autoliquidée, déductible 0, TVA à payer à l'État 0 (A51, A52).
- Contrôle de la TVA d'importation : le moteur propose (valeur en douane + droits + autres taxes non déductibles + frais accessoires jusqu'au premier lieu de destination) × taux et signale l'écart (`tva.ecart_tva_importation`, art. 292) ; le montant de la déclaration reste la référence.
- La TVA autoliquidée n'est jamais lue sur la facture du fournisseur : c'est la société qui la calcule. Le taux de la facture (0) et le taux dû en France sont deux données distinctes.
- Société en franchise : aucune TVA déductible ; la TVA facturée et la TVA autoliquidée entrent dans le coût (produit) ou dans la charge, et « TVA à payer à l'État » montre la TVA autoliquidée.
- Escompte : il baisse le coût, pas les totaux ; son effet sur la base de la TVA déductible est à confirmer (6.2, EC2).
- Exigibilité et date de déduction : étape 5 ; rien n'est enregistré avant.

#### 3.17.4 Coût d'entrée en stock

```
coût unitaire d'entrée = coût net unitaire (PU HT × (1 − remise) × (1 − escompte), au dix-millième : coutUnitaireNet(), inchangé)
                       + (quote-part des frais + quote-part des droits + TVA non déductible de la ligne) ÷ quantité
                         (selon 6.1 d ; recommandation : un seul arrondi, au dix-millième)
```

- Sans frais, sans droits et sans TVA non déductible, le coût est exactement celui d'aujourd'hui (`LigneAchat::coutUnitaireHt()`).
- Quote-parts des frais d'acheminement et des droits : réparties entre les lignes de produits au prorata de leur HT net (TVA14 = a) ; l'arrondi de cette répartition est soumis à l'éditeur (6.1 d ; recommandation : par cumul, chaque ligne recevant la part arrondie du cumul jusqu'à elle, moins celle des lignes précédentes, le total réparti tombant juste au centime).
- Exemple, avec l'option recommandée : 100 × 8,00 € et 50 × 4,00 €, port 40,00 € facturé par le fournisseur. 1re ligne : 40,00 × 800 ÷ 1 000 = 32,00 € ; 2e ligne : 40,00 − 32,00 = 8,00 €. Coûts : 8,0000 + 32,00 ÷ 100 = **8,3200 €** ; 4,0000 + 8,00 ÷ 50 = **4,1600 €**.
- Une ligne de frais n'entre jamais en stock elle-même ; une ligne de charge n'entre ni en stock ni dans le coût.
- Le coût est figé à la validation dans `lignes_achat.cout_unitaire_entree`. La réception le lit et le passe à `StockLedgerService::receptionner()`, qui recalcule le coût moyen ; le mouvement de réception ne porte aucun coût.
- Correction après la validation (déclaration en douane complétée, frais rattachés, modification avec l'autorisation de valider) : décision recalculée et remplacée, l'ancienne au journal d'audit (`CoutDEntreeRecalcule` ou `FactureAchatModifiee`) ; coût moyen recalculé par `StockLedgerService::corrigerUneReception()`, à quantité inchangée ; aucun mouvement modifié ni ajouté ; permis sur une facture reçue automatiquement (son contenu reçu ne change pas) ; refusé dès qu'un avoir fournisseur a été émis sur la facture (`tva.cout_fige_par_un_avoir_fournisseur`).
- Magasin cédé : sortie au coût moyen ; pour une société en franchise, ce coût comprend la TVA non déductible, et l'écran le dit (O1).

#### 3.17.5 Importation et frais rattachés

Exemple : un fournisseur chinois facture 100 articles à 6,60 € (660,00 €, CIF Le Havre) ; le transitaire dédouane pour le magasin de Lyon ; un transporteur français achemine du Havre à Lyon pour 50,00 € HT. Déclaration : valeur 660,00 €, droits 24,00 €, frais accessoires 50,00 €, base 734,00 €, TVA 146,80 €.

| Montant | Valeur |
|---|---|
| HT ; TVA facturée | 660,00 € ; 0,00 € |
| TVA d'importation (autoliquidée) et déductible | 146,80 € (contrôle : (660,00 + 24,00 + 50,00) × 20 % = 146,80 €, sans écart) |
| TVA à payer à l'État pour cet achat | 0,00 € |
| Dû au fournisseur ; à payer à la douane | 660,00 € ; 24,00 € de droits |
| Coût d'entrée | (660,00 + 24,00 + 50,00) ÷ 100 = **7,3400 €** avec le transport rattaché (lot M7) ; sans lui, 6,6000 + 24,00 ÷ 100 = **6,8400 €** |
| Circuit | aucun e-reporting |

- La facture du transporteur est une facture d'achat à part, du même magasin ; sa ligne de frais à 0 % porte la raison « importation » ; un transporteur qui facture 20 % donne une TVA déductible sur sa propre facture, et le coût des marchandises reçoit seulement le HT.
- Rattachement (lot M7) : sur une ligne de frais, « Rattacher à » propose les factures d'achat validées du même magasin (règle `exists` par `regleExisteDansLeMagasin()`) ; une ligne se rattache à une seule facture (la facture d'un transitaire qui couvre trois arrivages se saisit en trois lignes) ; valider ou modifier la facture de frais recalcule et fige de nouveau chaque facture rattachée (celles d'avant la modification comprises, dont les frais se détachent), avec l'autorisation de valider, et inscrit `CoutDEntreeRecalcule` pour chacune ; refusé si l'une d'elles a un avoir fournisseur émis. **Ordre des verrous** (ordre du lot A4 : tous les documents, puis tous les produits) : la facture de frais, puis toutes les factures rattachées par identifiant croissant, puis l'union de leurs produits par identifiant croissant, puis les recalculs ; aucun produit n'est verrouillé avant le dernier document. Une facture rattachée dont la TVA a été saisie à la main garde sa saisie (reprise, 3.4) : seul son coût change.
- La déclaration se saisit sur la facture des marchandises (une par facture) ; une déclaration qui couvre deux factures du même arrivage se saisit sur chacune, avec la même référence et la part de chaque facture ; le contrôle se fait facture par facture.
- Liste « Dédouanement à compléter » (lot M7), menu Achats, badge, notification aux membres autorisés à valider ; une facture en sort quand sa déclaration est complète.

#### 3.17.6 Avoirs fournisseur et retours

- Un avoir fournisseur reprend la décision de la ligne achetée : même nature, mêmes taux facturé et dû en France.
- TVA facturée : selon 6.1 c. Option 1 (recommandée) : comme la TVA autoliquidée ci-dessous, jamais plus que la part de la ligne, un retour complet rendant exactement sa part. Option 2 : par différence de cumul sur la ligne, comme aujourd'hui.
- TVA autoliquidée (TVA13) : TVA cumulée rendue = min(arrondi(HT cumulé rendu × taux dû en France), part de la ligne dans la répartition) ; chaque avoir crédite la différence de cumul ; un retour complet rend exactement la part de la ligne.
- TVA déductible rendue : selon 6.1 d pour une ligne dont le pourcentage n'est ni 0 ni 100 % (recommandation : arrondi(TVA cumulée rendue × pourcentage), par différence de cumul, la moitié en s'éloignant de zéro, un retour complet rendant exactement la TVA déductible de la ligne) ; égale à la TVA rendue à 100 %, nulle à 0 %.
- Sortie de stock au coût d'entrée de la ligne retournée, frais et droits compris, puis coût moyen recalculé, plancher à 0 €.
- Exemple : retour de 30 articles sur l'acquisition de 100 × 8,00 € avec 40,00 € de port (part de la ligne : 160,00 € de TVA autoliquidée). Avoir : −240,00 € HT, TVA facturée 0, autoliquidée −48,00 € (min(48,00 ; 160,00)), déductible −48,00 €. Sortie : 30 × 8,4000 = 252,00 €. Les 12,00 € d'écart sont la part du port non remboursée, déjà comptée dans le coût.
- Après un avoir fournisseur émis, le coût de la facture ne change plus.

#### 3.17.7 Devises (TVA16 = a)

- Seules les devises ISO 4217 à 0 ou 2 décimales sont acceptées (liste du domaine `Tva`) ; une autre devise est refusée à la saisie.
- Taux de change saisi sur la facture, décimal exact à 8 décimales (`taux_change`), avec sa source (`taux_change_source` : déclaration en douane, facture du fournisseur, relevé bancaire, autre) ; pour une importation, la source « déclaration en douane » est imposée.
- Conversion, selon 6.1 d (recommandation) : HT de chaque ligne en euros = arrondi(quantité × PU HT en devise × (1 − remise) × taux), une fois, au centime ; la TVA se calcule sur ce HT en euros, comme pour un achat en euros ; coût unitaire net = PU HT en devise × taux × (1 − remise) × (1 − escompte), arrondi une fois au dix-millième. Le lot M5e attend la réponse.
- Exemples, avec l'option recommandée : un service d'un éditeur américain de 100,00 USD au taux 0,92345678 donne 92,35 € HT, 18,47 € de TVA autoliquidée et déductible (A43) ; 10 articles à 12,34 USD au taux 0,90000000 livrés depuis un entrepôt en France par un fournisseur non établi donnent 111,06 € HT, 22,21 € de TVA autoliquidée et un coût unitaire net de 11,1060 € (A43b).
- Une devise sans taux bloque la validation (`tva.devise_sans_taux`). La date du taux retenu est à confirmer (6.2, EC11).

#### 3.17.8 TVA étrangère facturée (TVA15 = b)

- Un fournisseur de l'Union qui facture la TVA de son pays : la facture se valide ; la TVA étrangère s'enregistre à part (totaux de `decision_tva`), ni déductible ni dans le coût ; la TVA française de l'acquisition se calcule quand même ; « Dû au fournisseur » la comprend ; avertissement fort `tva.tva_etrangere_facturee` (« Ce fournisseur a facturé la TVA de son pays : demandez-lui une facture rectificative ou son remboursement. ») ; la facture entre dans la liste « TVA étrangère à régulariser ».
- Elle en sort quand la régularisation est notée (`tva_etrangere_regularisee_le`, `_par`, `_par_nom`, motif : `facture_rectificative_recue`, `remboursement_obtenu`, `autre`), avec l'autorisation de valider, inscrite au journal d'audit (`TvaEtrangereRegularisee`), ou quand la facture corrigée ne porte plus de TVA étrangère.

#### 3.17.9 Société en franchise, DOM, saisie manuelle

- Franchise, achat en France : TVA facturée non déductible, dans le coût (10 × 8,00 € + 16,00 € de TVA → 9,6000 € l'article).
- Franchise, achat hors de France : acquisitions de biens dans l'Union et importations refusées (`tva.achat_etranger_en_franchise_non_gere`, TVA10 = a) ; services d'un prestataire étranger autoliquidés sans déduction, la TVA s'ajoutant à la charge.
- Magasin de Guadeloupe, de Martinique ou de La Réunion : un achat venu d'ailleurs que de son département est un cas exclu (octroi de mer), saisie manuelle.
- **Saisie manuelle** (TVA11) : avec l'autorisation `facture_achat.saisie_manuelle_tva`, un membre indique, ligne par ligne, la nature, le taux, la TVA facturée, autoliquidée et déductible, avec un motif obligatoire (`tva.motif_de_saisie_manuelle_absent` sinon). La décision porte `saisieManuelle` (auteur, motif) ; le journal d'audit inscrit `TvaDAchatSaisieALaMain` avec le motif et les montants. Le moteur contrôle seulement la cohérence arithmétique (TVA déductible au plus la TVA facturée plus l'autoliquidée de la ligne ; montants positifs). La saisie reste acquise quand une autre action recalcule la facture (déclaration en douane complétée, frais rattachés ou détachés) : le collecteur la reprend depuis la décision figée (3.4) ; une modification des lignes de la facture exige une nouvelle saisie (`tva.saisie_manuelle_a_refaire`). Aucune exonération de vente n'est jamais forçable : la saisie manuelle n'existe que pour les achats et les charges.

### 3.18 Écrans d'achat et API

**Fiche fournisseur (lot M5b).**
- Pays dans une liste de pays nommés, contrôlé comme celui d'un client.
- « Établi en France » : pays en France, établi d'office ; Monaco, « établi à Monaco » ; pays étranger, non par défaut, avec la case « Ce fournisseur facture depuis un établissement en France (un n° de TVA FR obtenu par un représentant fiscal ne suffit pas) », visible à côté du pays ; en base, aucune valeur par défaut fixe : le modèle la calcule depuis le pays, y compris pour une création par l'API.
- Section repliée « Fiscalité » : « Ce fournisseur ne facture pas de TVA » ; n° de TVA avec son état VIES, vérifié par le bouton « Vérifier sur VIES » (`VerifierNumeroTvaAction`, droit de modifier le fournisseur).
- L'avertissement « Sans SIRET, SUPER PDP ne pourra pas reconnaître ce fournisseur » s'affiche sur la fiche d'un fournisseur établi en France, à l'enregistrement, jamais sur une facture.

**En-tête de la facture d'achat (lot M6).**

| Déclencheur | Question | Réponses | Effet |
|---|---|---|---|
| Toujours ; pour un fournisseur qui a un SIRET ou une facture reçue par l'API, une ligne repliée « Pièce : facture au nom de la société », modifiable, sans clic | « Cette pièce est-elle une facture au nom de votre société ou de ce magasin ? » | Oui, une facture · Non, un ticket ou un reçu | Préremplie « Oui » pour un fournisseur qui a un SIRET ou une facture reçue par l'API ; sinon sans réponse, exigée à la validation (`achat.nature_piece_inconnue`). « Non » : aucune TVA déductible, avec la raison « Ticket ou reçu sans facture : TVA non déductible ; demandez une facture au fournisseur (art. 271 II du CGI) ». |
| Au moins une ligne de biens (produit, ou charge sur un bien), quel que soit le fournisseur | « Origine des marchandises » | France ou Monaco · Autre pays de l'Union · Hors de l'Union ; puis le pays (et le code postal pour un territoire exclu) | Préremplie « France » pour un fournisseur établi en France, sans clic, source affichée ; sinon d'après la dernière facture validée du même fournisseur dans le magasin, déjà confirmée, source affichée, sans nouvelle confirmation ; sinon son pays, « proposé, à confirmer ». Changer l'origine reprise exige de la confirmer. |
| Origine hors de l'Union, dans un DOM, une collectivité d'outre-mer ou un territoire exclu | « Qui a dédouané les marchandises ? » | Nous, ou notre transitaire · Le fournisseur | Importation, ou livraison en France par le fournisseur. |
| Dédouanées par nous | « Déclaration en douane » : MRN, date du bon à enlever, valeur, droits, autres taxes, frais jusqu'au premier lieu de destination, TVA par taux ; aide « Vous pourrez la compléter plus tard » | Saisie | Coût et TVA d'importation (TVA12). |
| Fournisseur non établi en France | « La facture porte-t-elle de la TVA ? » | Non (cas normal) · Oui, montant | Oui : 3.17.8. |
| Fournisseur établi en France, ligne à 0 % | « Pourquoi cette ligne n'a pas de TVA ? » | Le fournisseur est en franchise (mention 293 B) [« Mémoriser pour ce fournisseur »] · Opération exonérée (loyer de locaux nus, assurance, opération bancaire) · La facture porte « Autoliquidation » · Frais liés à une importation (lot M7) · Erreur de saisie | Voir 3.17.2. Sans réponse : bloquant à la validation. |
| Ligne de charge des catégories Assurances ou Frais bancaires d'un fournisseur non établi | « Cette ligne est-elle une prime d'assurance ou une opération bancaire exonérée ? » | Oui (d'office) · Non | Oui : achat exonéré, ni autoliquidation ni déduction (3.17.2) ; Non : services d'un prestataire non établi. |
| Ligne de service, de frais ou de charge d'un fournisseur non établi | Case « Concerne un immeuble, un transport de personnes, un repas, une location courte de véhicule ou un événement » | Cochée ou non | Cochée : cas exclu, saisie manuelle. |
| Devise autre que l'euro | « Taux de change appliqué » et « Source du taux » | Saisie | Montants en euros. |
| Toujours, facultatif | « Total TTC de la facture du fournisseur (contrôle) » | Montant | Contrôle d'écart. |

**Lignes.**
- « Ajouter un produit » garde la fenêtre actuelle, coupée en deux blocs : « Vente en magasin » (prix de vente TTC et taux de vente de la fiche, corrigés immédiatement, complément 2, avec le JET 270 si le taux change) ; « Cette facture » (quantité, PU HT, remise, taux facturé). Pour un nouveau produit, le taux facturé suit le taux de vente tant qu'on ne le modifie pas. Il est caché, et vaut 0, quand la nature ne porte pas de TVA sur la facture (sauf réponse « Oui » à la TVA portée par la facture). Le taux dû en France n'apparaît qu'en autoliquidation, proposé, avec l'avertissement de taux inhabituel. Le taux facturé ne réécrit jamais le taux de vente.
- « Ajouter des frais ou une charge » (nouveau) : description, type (frais d'acheminement · charge), pour une charge sa catégorie (liste sans « Salaires »), sa nature proposée et, au lot CH2, sa précision ; montant HT ; taux selon la nature ; au lot M7, « Rattacher à » pour des frais.

**Totaux.**
- Achat ordinaire en France (ni autoliquidation, ni droits, ni frais rattachés, ni TVA étrangère) : la ligne d'aujourd'hui reste, avec des mots plus précis : « Total HT · TVA facturée, déductible (non déductible en franchise) · Total TTC à payer au fournisseur ». Le coût d'entrée ne s'affiche à part que s'il diffère du HT.
- Sinon, le détail :

```
Total HT des marchandises et frais ....................... 840,00 €
TVA facturée par le fournisseur ..........................   0,00 €
À payer au fournisseur ................................... 840,00 €
TVA que vous déclarez vous-même (autoliquidation, 20 %) .. 168,00 €
TVA déductible sur la même déclaration ................... 168,00 €
TVA à payer à l'État pour cet achat ......................   0,00 €
Coût d'entrée en stock ....................... 840,00 € (8,40 € l'article)
```

- Importation : « TVA d'importation (déclaration en douane) … 136,80 € » et « À payer à la douane ou au transitaire : 24,00 € de droits ». TVA étrangère : « TVA allemande facturée, à faire rectifier ou rembourser … 152,00 € ». Franchise : « TVA déductible : 0,00 € (franchise) ». Ligne de charge à récupération partielle : « dont non déductible (gazole, essence ou E85 d'un véhicule de tourisme, 20 %) … 2,00 € ». Pièce qui n'est pas une facture : « TVA déductible : 0,00 € (ticket ou reçu sans facture) ».

**Panneau « Régime de TVA de cet achat »** : comme celui d'une vente (nature, qui paie la TVA, coût du stock, « Pourquoi » ligne par ligne, mentions attendues en rappel, transmission à l'étape 2). Pour une ligne de charge, la raison de sa récupération, tirée de la table datée (« Transport de personnes : TVA non déductible (art. 206 IV-2-5° de l'annexe II au CGI) »), et « règle à confirmer par votre expert-comptable » quand elle l'est.

**Contrôles d'écart et de mentions.** L'écart entre la TVA calculée et celle de la facture ne se signale (`tva.ecart_avec_la_facture`) que pour une facture reçue par l'API (ou SUPER PDP à l'étape 2) ou quand le total de contrôle est saisi. Les mentions attendues d'une facture s'affichent comme un rappel, sans case à cocher ; leur absence n'est un avertissement que pour une facture électronique reçue (étape 2), jamais un refus de déduction. Une pièce qui n'est pas une facture (ticket, reçu) n'est pas une facture à laquelle manque une mention : elle n'ouvre aucune déduction (nature de la pièce, 3.17.1).

**Listes de suivi**, menu Achats, badge et notification aux membres autorisés à valider : « TVA étrangère à régulariser » (lot M6) ; « Dédouanement à compléter » (lot M7) ; « Charges à valider » (lot CH2, menu Charges).

**API d'achat** (`CreerFactureAchatAction`, source « API », aucune route HTTP avant l'étape 10) :
1. La charge utile peut porter `type_document` (lot CH2), `nature_piece` (lot M5d ; « facture » d'office pour une facture reçue par l'API), `pays_expedition`, `code_postal_expedition`, `dedouane_par`, la déclaration en douane (`douane_reference`, `douane_date_bae`, `douane_valeur`, `douane_droits`, `douane_autres_taxes`, `douane_frais_accessoires`, `douane_tva`), `total_de_controle`, `devise`, `taux_change` et `taux_change_source` (lot M5e), et pour chaque ligne `type_ligne`, `taux_tva` (taux facturé), `taux_tva_france`, `raison_taux_zero`, `lieu_fixe_par_la_loi`, `categorie_charge`, `nature_charge`, `precision_charge` et `seuil_cadeaux_confirme` (lot CH2), `facture_achat_rattachee_id` (lot M7).
2. Préremplissage identique à l'écran ; la décision donne la source de chaque fait.
3. Un brouillon n'est jamais refusé pour un fait fiscal manquant : il est créé avec sa décision provisoire et ses manquements.
4. La validation est refusée avec tous les manquements, chacun avec son code, son champ et son message ; aucun stock n'entre, aucun numéro n'est consommé.
5. Une contradiction passe en « cas à vérifier », jamais en choix silencieux.
6. La TVA donnée par la source sert au rapprochement (`tva.ecart_avec_la_facture`).
7. Jeton unique sur la validation (R3).
8. Une facture reçue par l'API n'est jamais modifiable ; compléter sa déclaration en douane ou lui rattacher des frais ne modifie pas son contenu reçu : permis, sauf après un avoir fournisseur.

### 3.19 Bloquant ou avertissement

**Ventes**

| Contrôle | Niveau | Moment | Code |
|---|---|---|---|
| Conditions d'une nature appliquée, d'office ou par un geste | Bloquant, avec « Rester à la TVA française » | Émission | `tva.conditions_non_reunies` (message actuel des conditions) |
| Nature encore proposée | Bloquant : la fenêtre « Émettre » impose le choix | Émission | `tva.choix_du_regime_requis` |
| N° de TVA de la société pour une nature autre que la vente en France | Bloquant | Émission | `tva.numero_tva_societe_absent` |
| Identité légale de la société et du magasin ; SIREN d'un client établi en France ; nom et adresse du client sur une facture | Bloquant | Émission, annoncé dès le choix du client | `identite.incomplete`, `client.siren_absent`, `client.adresse_absente` |
| Calcul du brouillon changé depuis son enregistrement | Bloquant, avec l'avant, l'après et « Mettre à jour et émettre » | Émission | `tva.calcul_du_brouillon_change` |
| Sortie des biens sans réponse, pour un client qui la demande | Bloquant | Émission | `tva.sortie_des_biens_inconnue` |
| Lieu d'un service au lieu fixé par la loi : sans réponse, ou hors de France | Bloquant | Émission | `tva.lieu_du_service_a_preciser`, `tva.lieu_du_service_hors_de_france` |
| Dans une nature autre que la vente en France, une ligne qu'elle n'admet pas ; une ligne hors champ avec une autre | Bloquant | Émission, affiché pendant la saisie | `tva.une_nature_par_document`, `tva.document_separe_requis` |
| Accises ou moyen de transport neuf dans une nature hors de France | Bloquant pour cette nature | Émission | `tva.cas_exclu` |
| Ligne à 0 % vendue en France, société redevable | Bloquant | Enregistrement de la fiche ; émission en filet de sécurité | `tva.taux_zero_en_france` |
| Vente à distance au-delà du seuil, année précédente au-delà, société inscrite à l'OSS | Bloquant | Émission | `tva.seuil_ventes_a_distance_depasse`, `tva.oss_non_gere` |
| Vente à distance constatée sans le verrou de la société (brouillon changé pendant l'émission) | Bloquant, rien n'est écrit | Émission | `tva.brouillon_change_pendant_l_emission` |
| Magasin des DOM qui expédie hors de son département ; prestation à un professionnel établi dans un DOM | Bloquant | Émission | `tva.depart_hors_du_dom_non_gere`, `tva.prestation_vers_un_dom_non_geree` |
| VIES injoignable quand la nature l'exige | Bloquant, brouillon gardé | Émission | `tva.vies_injoignable` |
| Facture demandée sans client particulier nommé (client comptoir) | Bloquant | Émission (lot C-b) | `client.comptoir_pour_une_facture` |
| Ticket déjà transformé, repris en partie par un avoir, ou pas entièrement payé | Bloquant | Transformation (lot C-b) | `vente.ticket_deja_transforme`, `vente.ticket_repris_par_un_avoir`, `vente.ticket_non_paye` (la facture, construite avec les faits figés du ticket, le reproduit par construction : aucun refus « calcul changé », 5.6) |
| Exonération possible, TVA française gardée | Information, tracée | Saisie | — |
| Export emporté par le client | Information | Saisie et émission | `tva.export_emporte_non_gere` |
| Vente à distance sous 9 000 € | Information, dans le panneau seulement | Saisie | `tva.vente_a_distance_seuil_non_atteint` |
| Vente à distance dès 9 000 € (seuil proche) | Avertissement | Saisie et émission | `tva.vente_a_distance_seuil_proche` |
| Professionnel de l'UE sans n° de TVA, biens expédiés dans son pays | Avertissement | Saisie | `tva.vente_a_distance_possible` |
| Taux inhabituel pour le territoire | Avertissement | Saisie | `tva.taux_inhabituel` |
| Nom ou adresse VIES très différents de la fiche | Avertissement | Saisie | `tva.vies_nom_different` |
| Service à un particulier dont l'adresse est hors de l'Union | Avertissement | Saisie | `tva.service_hors_ue_a_verifier` |
| Livraison antérieure à l'émission, sans vérification VIES à sa date | Avertissement | Émission | `tva.vies_apres_la_livraison` |
| Retour sur un ticket dont un bordereau de détaxe a été noté | Avertissement, rappel sur le ticket jusqu'à la suite notée | Émission de l'avoir (lot C-a) | `detaxe.bordereau_a_regulariser` |
| Suite d'un bordereau déjà notée, ou plus attendue | Bloquant | Noter la suite (lot C-a) | `detaxe.suite_deja_notee` |
| Preuves à fournir ; facture au plus tard le 15 du mois suivant ; relevé du mois | Avertissement | Après l'émission | — |
| Devis dont une condition manque | Avertissement | Envoi | — |

**Achats et charges**

| Contrôle | Niveau | Moment | Code |
|---|---|---|---|
| Nature de la pièce sans réponse | Bloquant | Validation | `achat.nature_piece_inconnue` |
| Origine non confirmée, pour un fournisseur non établi, à sa première facture ou quand elle change | Bloquant | Validation | `tva.origine_non_confirmee` |
| Dédouanement inconnu pour une origine hors de l'Union | Bloquant | Validation | `tva.dedouanement_inconnu` |
| Faits contradictoires | Bloquant, sauf saisie manuelle | Validation | `tva.faits_contradictoires` |
| Opération triangulaire possible | Bloquant, sauf saisie manuelle | Validation | `tva.operation_triangulaire_possible` |
| TVA étrangère facturée par un fournisseur de l'UE, société redevable | Avertissement fort, liste « à régulariser » | Validation | `tva.tva_etrangere_facturee` |
| Autoliquidation ou acquisition sans n° de TVA de la société | Bloquant | Validation | `tva.numero_tva_societe_absent` |
| Devise sans taux ; devise non gérée | Bloquant | Validation ; saisie | `tva.devise_sans_taux`, `tva.devise_non_geree` |
| Source du taux autre que la déclaration pour une importation | Bloquant | Validation | `tva.source_du_taux_imposee` |
| N° et date de la facture d'un fournisseur non établi | Bloquant | Validation | `achat.numero_fournisseur_absent`, `achat.date_fournisseur_absente` |
| Ligne à 0 % d'un fournisseur établi en France, sans réponse à « Pourquoi ? » | Bloquant | Validation | `tva.taux_zero_sans_raison` |
| Cas exclu, sauf saisie manuelle autorisée | Bloquant | Validation | `tva.cas_exclu` |
| Saisie manuelle sans motif, ou incohérente | Bloquant | Validation | `tva.motif_de_saisie_manuelle_absent`, `tva.saisie_manuelle_incoherente` |
| Saisie manuelle reprise alors que les lignes ont changé | Bloquant, jusqu'à une nouvelle saisie | Modification, recalcul | `tva.saisie_manuelle_a_refaire` |
| Régularisation d'une TVA étrangère déjà notée | Bloquant | Noter la régularisation (lot M5e) | `tva.regularisation_deja_notee` |
| Achat hors de France d'une société en franchise (sauf services) | Bloquant | Validation | `tva.achat_etranger_en_franchise_non_gere` |
| Création de produits sans le droit de créer des produits | Bloquant | Validation | `achat.droit_de_creer_des_produits` |
| Ligne de charge sans catégorie ; catégorie « Salaires » sur une ligne | Bloquant | Validation | `achat.categorie_absente`, `achat.categorie_salaires_interdite` |
| Frais d'acheminement sans produit ni facture rattachée | Bloquant | Validation | `achat.frais_sans_produit` |
| Coût d'une facture qui a déjà un avoir fournisseur émis | Bloquant | Compléter le dédouanement, rattacher des frais | `tva.cout_fige_par_un_avoir_fournisseur` |
| Charge sans pièce justificative ; facture qui porte une ligne de charge, sans date de pièce ; charge avec une ligne de produit ou de frais ; période incohérente | Bloquant | Validation (lot CH2) | `charge.piece_absente`, `charge.date_piece_absente`, `charge.ligne_de_stock_interdite`, `charge.periode_incoherente` |
| Cadeau de plus de 73 € TTC l'unité marqué « jusqu'au seuil » ; ou sans la case « le total des cadeaux à ce bénéficiaire cette année, frais compris, reste sous le seuil » | Bloquant | Validation (lot CH2) | `charge.cadeau_au_dela_du_seuil`, `charge.cadeau_seuil_a_confirmer` |
| Précision absente pour Loyer, Entretien, Transport ou Autres charges | Bloquant | Validation (lot CH2) | `charge.precision_absente` |
| TVA facturée sur une prime d'assurance | Avertissement (TVA non déductible) | Saisie et validation (lot CH2) | `charge.tva_inattendue` |
| Règle de récupération encore à confirmer | Information | Saisie (lot CH2) | `charge.regle_a_confirmer` |
| Société qui déclare aussi des opérations exonérées ou hors du champ de la TVA | Information, sur chaque ligne de charge | Saisie (lot CH2) | `charge.deduction_a_proratiser` |
| Déclaration en douane à compléter | Avertissement, liste | Validation et après | `tva.dedouanement_a_completer` |
| Écart entre la TVA d'importation saisie et celle attendue | Avertissement | Saisie | `tva.ecart_tva_importation` |
| Ligne de frais « importation » sans frais accessoires dans la déclaration rattachée | Avertissement | Saisie | `tva.frais_hors_base_importation` |
| VIES du fournisseur invalide ou non vérifié | Avertissement | Saisie | `tva.vies_fournisseur_invalide` |
| Écart avec la facture du fournisseur | Avertissement | Saisie | `tva.ecart_avec_la_facture` |
| Taux facturé ou dû en France inhabituel | Avertissement | Saisie | `tva.taux_inhabituel` |

**Charges communes, salaires, pièces** : codes de l'annexe B (`charge_commune.*`, `salaires.*`, `piece.*`), tous bloquants.

### 3.20 Autorisations

Les noms suivent le registre livré par le lot A4 (`PermissionRegistry`, `AutorisationsSociete`) ; ceux du lot A6 (remises et prix, vente sans stock) gardent les noms que ce lot leur a donnés. Le propriétaire n'est soumis à aucune autorisation ; un responsable a tous les droits de son magasin sans case, sauf les salaires (4.9) ; un expert-comptable n'a que les consultations (`PermissionRegistry::consultations()`).

| Geste | Autorisation |
|---|---|
| Répondre aux questions, appliquer une proposition, garder la TVA française | Créer la facture ou le devis : aucun droit spécial, le moteur contrôle les conditions. |
| Changer le prix ou la remise d'une ligne | Autorisation de R2 (lot A6) ; appliquer une exonération ou une autoliquidation n'est jamais une dérogation. |
| Forcer une exonération ou une autoliquidation dont une condition manque | Personne. |
| Émettre (consultation VIES du jour comprise) | `facture.emettre`. |
| Voir les montants du compteur des ventes à distance et le relevé de la société | `societe.parametres.gerer` ; un vendeur ne voit que l'état du seuil. |
| Voir le relevé du mois du magasin | `facture.view_toutes`. |
| Retour ou avoir sur un ticket de caisse (nouvelle, lot C-a) | `avoir.sur_ticket` (« Avoirs : faire un retour ou un avoir sur un ticket de caisse »), qui suffit seule (complément 16) ; `avoir.creer` reste celle des avoirs sur les factures ; le propriétaire et les responsables du magasin l'ont toujours ; précochée dans le modèle de l'administrateur, jamais dans ceux du vendeur et de l'observateur, à qui le propriétaire ou un responsable du magasin peut la cocher ; un expert-comptable ne l'a jamais ; réservée aux opérations commerciales. |
| Transformer un ticket en facture (lot C-b) | `facture.emettre` et `avoir.sur_ticket` (l'avoir d'annulation est un avoir sur un ticket) ; une caisse à la fois, comme toute émission ; les lignes reprises telles quelles du ticket gardent l'accord de R2 donné à sa vente (`DerogationsDePrix::introduites()`, 5.6). |
| Annuler une preuve ; noter la suite d'un bordereau | Le droit qui permet aujourd'hui d'ajouter une preuve, ou de noter un bordereau (celui de `AjouterPreuveOperationAction`, de `NoterBordereauDetaxeAction`). |
| Saisir l'origine, le dédouanement et la nature de la pièce d'un achat ; cocher la case du cumul des cadeaux | `facture_achat.creer` (pour une charge : `charge.creer`). |
| Valider, modifier après validation, compléter le dédouanement, rattacher des frais, noter la régularisation d'une TVA étrangère | `facture_achat.valider` (pour une charge : `charge.valider`). |
| Saisir à la main la nature et la TVA d'un achat ou d'une charge (nouvelle, lot M5e) | `facture_achat.saisie_manuelle_tva` (« Achats et charges : saisir à la main leur TVA ») ; jamais précochée, ni pour l'administrateur ni pour le vendeur ni pour l'observateur ; un expert-comptable ne l'a jamais. |
| Régime de TVA daté, option pour les débits, n° de TVA, valeurs de départ des ventes à distance, inscription à l'OSS ; déclaration d'opérations exonérées ou hors du champ (lot CH2) | `societe.parametres.gerer` ; JET 270 dans chaque magasin. |
| Particularité fiscale d'un produit, taux de vente | `produit.update`. |
| Voir, saisir, valider les charges du magasin (nouvelles, lot CH2) | `charge.view`, `charge.creer`, `charge.valider` (4.9). |
| Charges communes, salaires (nouvelles autorisations de société, lots CH4 et CH5) | `societe.charges.gerer`, `societe.salaires.gerer` (4.9). |
| Ajouter une pièce justificative ; la retirer d'un brouillon ; la télécharger | Le droit de créer le document (`facture_achat.creer`, `charge.creer`, `societe.salaires.gerer`, `societe.charges.gerer`) ; pour la télécharger, le droit de voir le document. |
| Remplacer une pièce déposée par erreur, avec un motif (lots CH1, CH4, CH5) | Le propriétaire ; sur un document validé d'un magasin, `facture_achat.valider` (pour une charge : `charge.valider`) ; sur des salaires, `societe.salaires.gerer` pour ce magasin ; sur une charge commune, `societe.charges.gerer` pour tous les magasins de la répartition ; jamais un expert-comptable. |

`modeleDeLAdministrateur()` exclut `parametres.gerer`, l'autorisation de vente sans stock du lot A6 et `facture_achat.saisie_manuelle_tva` ; il comprend `avoir.sur_ticket` et les trois autorisations des charges. `charge.view` entre dans `consultations()`. `avoir.sur_ticket` entre dans `reserveesAuxOperationsCommerciales()`. Les libellés s'ajoutent à `libelles()`. Le référentiel des autorisations de magasin se remplit depuis `PermissionRegistry::toutes()` par sa migration ; celui de la société depuis `AutorisationsSociete::toutes()`.

### 3.21 Ce que liront les étapes 2, 5 et 7

**Étape 2 (SUPER PDP)** : le circuit figé (`factures.circuit`, `avoirs.circuit`) ; la catégorie, le VATEX et le motif de chaque ligne, déduits de la nature, de la franchise et du type de ligne figés, dans la table de la version des règles figée (une lecture de table datée, pas un calcul) ; la ventilation par taux depuis les montants enregistrés ; la table des catégories prises en charge, ouverte catégorie par catégorie ; l'e-reporting international sortant (documents au circuit international) et entrant (factures d'achat validées des natures autoliquidées : n° et date du fournisseur, catégorie attendue, TVA autoliquidée ; les importations n'y sont pas, ni les achats exonérés à un prestataire non établi, assurance ou opération bancaire, les opérations exonérées par les art. 261 à 261 E étant hors e-reporting (art. 290 I, chapeau), à confirmer, EC20) ; l'agrégat des ventes aux particuliers par jour, par circuit et non plus par type de client (factures demandées et non-assujettis compris) ; le statut « encaissée » (exigibilité calculée à cette étape) ; les brouillons de charges reçus, la facture jointe comme pièce.

**Étape 5 (comptabilité)** : des rubriques internes stables déduites des natures figées (ventes taxées par taux, exportations, livraisons intracommunautaires, autres opérations non imposables, ventes à distance, acquisitions, livraisons et services autoliquidés, importations, opérations avec Monaco, TVA déductible, charges par catégorie, parts des charges communes, salaires), reliées aux lignes de la CA3 par une table versionnée par millésime de notice ; les écritures depuis les montants figés, comptes proposés pour les catégories de charges (4.2) ; l'exigibilité et la date de déduction (services achetés en France au paiement, sauf option du fournisseur pour les débits ; énergie à la fin de la période facturée, et aux acomptes encaissés, à confirmer ; acquisitions intracommunautaires le 15 du mois suivant la livraison, ou à la date de la facture si elle est antérieure (art. 269-2 d du CGI) ; services autoliquidés à leur réalisation, à confirmer par l'expert-comptable (EC7)) ; le droit à déduction supposé total, chaque ligne signalée pour une société qui déclare des opérations hors droit à déduction, sans coefficient calculé (EC19) ; le blocage de l'export d'une période tant qu'une déclaration en douane y manque.

**Étape 7 (stock)** : la décision « sortie de stock hors vente » (démarque, vol, casse, prélèvement, don), avec le reversement de la TVA déduite ou sa dispense, ajoutée au catalogue du moteur.

### 3.22 Traçabilité

| Quoi | Où | Durée | Affichage |
|---|---|---|---|
| Faits utilisés et leur source, versions, date de l'opération | `decision_tva` du document (signée pour une vente) ou de la facture d'achat | 10 ans | Fiche, « Pourquoi » |
| Nature, franchise, circuit, vente à distance, sortie des biens, livraison, lieu des services, consultation VIES retenue | Colonnes du document, figées et signées | 10 ans, sans purge | Section « Régime de TVA » |
| Lignes chiffrées | Colonnes des lignes (signées pour une vente) | 10 ans | Document |
| Catégorie et VATEX de chaque ligne | Déduits (version des règles figée) | — | Étape 2 |
| Mentions et note de calcul (code et texte du jour) | `decision_tva` | 10 ans | PDF, fiche, duplicata |
| Geste sur le régime : nature, auteur, nom figé, instant | Colonnes `nature_confirmee*`, signées | 10 ans | Fiche |
| Décision d'achat ou de charge et ses modifications | `decision_tva` (la dernière) ; historique au journal d'audit (`FactureAchatValidee`, `FactureAchatModifiee`, `CoutDEntreeRecalcule`, `TvaDAchatSaisieALaMain`, `TvaEtrangereRegularisee`, avec l'ancienne décision) | 10 ans (« Opérations ») | Historique de la facture |
| Régime de TVA de la société et sa date d'effet | `regimes_tva_societe` (ajout seul), JET 270 de chaque magasin, audit `ParametresFiscauxModifies` | 10 ans | Paramètres fiscaux |
| Valeurs de départ des ventes à distance, OSS | Colonnes de `societes`, JET 270, audit | 10 ans | Paramètres fiscaux |
| Consultations VIES (clients et fournisseurs) | `consultations_vies`, ajout seul | 10 ans | Fiche du tiers et du document |
| Preuves et leurs annulations | `preuves_operation`, `annulations_preuves_operation`, ajout seul | 10 ans | Liste « Preuves à fournir » |
| Bordereaux de détaxe et leurs suites | `bordereaux_detaxe`, `suites_bordereaux_detaxe`, ajout seul | 10 ans | Fiche du ticket |
| Pièces justificatives | `pieces_justificatives`, `pieces_justificatives_societe` (jamais supprimées en base, fichiers chiffrés ; seul le fichier d'une pièce remplacée pour erreur s'efface, sa ligne gardant l'empreinte, l'auteur et le motif), audit de chaque ajout, retrait et remplacement | 10 ans ; la société garde l'original de chaque pièce (4.6) | Document |
| Charges communes, parts, salaires | Tables du lot, audit `ChargeCommuneValidee`, `ChargeCommuneCorrigee`, `SalairesEnregistres`, `SalairesCorriges` | 10 ans | Espace de la société ; charges du magasin |
| Adresse IP et navigateur | Jamais dans une décision ; traces à part, effacées après 1 an (existant) | 1 an | — |

Les décisions (JSON) ne gardent que l'identifiant de l'utilisateur ; les colonnes d'historique gardent en plus son nom figé (`*_nom`).

### 3.23 Tests de référence

Les montants ci-dessous ont été recalculés à la main avec les règles de `CLAUDE.md` ; ils s'écrivent en dur dans les tests. Le lot de chaque test est donné ; un lot crée les tests qui portent son nom. « Fichier::méthode » : le fichier est sous `tests/`.

#### 3.23.1 Ventes

Magasin de Lyon (métropole), société redevable, sauf mention contraire. Produits : Câble 40,00 € TTC à 20 % ; Gourde 12,00 € TTC à 20 %. Services : « Paramétrage à distance », prix de référence 120,00 € TTC à 20 % ; « Installation sur site » (lieu fixé par la loi), 240,00 € TTC à 20 % ; « Port et emballage » (frais accessoires), 18,00 € TTC à 20 %. Client Müller GmbH : professionnel établi à Berlin, n° DE123456789.

| # | Fichier::méthode | Faits | Payé : HT / TVA / TTC | Nature et mention | Circuit | Lot |
|---|---|---|---|---|---|---|
| V01 | `Feature/Tva/VentesEnFranceTest::test_un_ticket_a_un_particulier_reste_en_vente_en_france_sans_aucune_question` | Particulier, 2 Câbles | 66,66 / 13,34 / 80,00 | France ; « Catégorie de l'opération : Livraison de biens » | Particuliers | M2 |
| V02 | `Feature/Tva/VentesEnFranceTest::test_une_facture_a_un_professionnel_etabli_en_france_part_en_facture_electronique` | Professionnel assujetti établi en France, 1 000 × 1,00 € TTC | 833,33 / 166,67 / 1 000,00 | France | Facture électronique | M3 |
| V03 | `Feature/Tva/VentesEnFranceTest::test_la_repartition_r4_est_inchangee` | Professionnel : 1 × 1,00 € (20 %), 2 × 4,50 € (5,5 %), 2 × 2,00 € (20 %) | 12,69 / 1,30 / 13,99 ; lignes 0,83 + 0,17, 8,53 + 0,47, 3,33 + 0,66 | France | — | M2 |
| V04 | `Feature/Tva/VentesEnFranceTest::test_les_retours_partiels_d_une_ligne_professionnelle_restent_cumules` | Professionnel, 2 Câbles, puis deux avoirs d'un article | Facture 66,67 / 13,33 / 80,00 ; avoir 1 : 33,33 / 6,67 / 40,00 ; avoir 2 : 33,34 / 6,66 / 40,00 | France, héritée | — | M2 |
| V05 | `Feature/Tva/VentesHorsDeFranceTest::test_une_livraison_intracommunautaire_aux_conditions_reunies_s_applique_d_office_avec_le_nouveau_total` | Müller, VIES valide le jour même, 10 Gourdes expédiées par le magasin à Berlin, date de livraison | 100,00 / 0,00 / 100,00 (au lieu de 120,00) ; état « appliqué d'office » | Livraison intracommunautaire ; « Exonération de TVA, article 262 ter I du CGI » | International ; facture au plus tard le 15 du mois suivant ; relevé du mois | M4 |
| V06 | `Feature/Tva/VentesHorsDeFranceTest::test_les_frais_de_port_d_une_livraison_intracommunautaire_suivent_les_biens` | V05 + Port et emballage | 115,00 / 0,00 / 115,00 | Idem, deux lignes | Idem | M4 |
| V07 | `Feature/Tva/VentesHorsDeFranceTest::test_un_numero_tva_invalide_sur_vies_laisse_la_vente_a_la_tva_francaise_et_dit_pourquoi` | V05, VIES ne reconnaît pas le n° | 100,00 / 20,00 / 120,00 ; état « incomplet » | France | International | M4 |
| V08 | `Feature/Tva/VentesHorsDeFranceTest::test_un_professionnel_de_l_ue_sans_numero_de_tva_reste_a_la_tva_francaise_avec_l_avertissement_de_vente_a_distance` | Client DE sans n°, biens expédiés | 100,00 / 20,00 / 120,00 ; `tva.vente_a_distance_possible` | France | International | M4 |
| V09 | `Feature/Tva/VentesHorsDeFranceTest::test_un_export_a_un_professionnel_hors_ue_expedie_par_le_magasin` | Client US, 1 000 × 1,00 € TTC, expédiés | 833,33 / 0,00 / 833,33 | Export ; « Exonération de TVA, article 262 I du CGI » | International | M4 |
| V10 | `Feature/Tva/VentesHorsDeFranceTest::test_un_export_a_un_particulier_suisse_expedie_par_le_magasin` | Particulier CH, 2 Câbles | 66,66 / 0,00 / 66,66 | Export | Particuliers | M4 |
| V11 | `Feature/Tva/VentesHorsDeFranceTest::test_une_livraison_vers_la_martinique_a_un_professionnel_etabli_la_bas` | Professionnel assujetti établi en Martinique, 10 Gourdes expédiées | 100,00 / 0,00 / 100,00 | Livraison vers un DOM ; mention de l'art. 294 | Facture électronique, retenue (catégorie non prise en charge) | M4 |
| V12 | `Feature/Tva/VentesHorsDeFranceTest::test_une_prestation_a_un_professionnel_de_l_ue_est_proposee_et_l_emission_exige_un_choix` | Client IE, n° valide, Paramétrage | État « proposé » ; émission refusée sans choix (`tva.choix_du_regime_requis`) ; appliquée : 100,00 / 0,00 / 100,00 | Prestation autoliquidée ; « Autoliquidation » | International ; relevé du mois | M4 |
| V13 | `Feature/Tva/VentesHorsDeFranceTest::test_une_prestation_a_un_professionnel_hors_ue_est_hors_champ` | Client CH avec identifiant, Paramétrage, appliquée | 100,00 / — / 100,00 | Prestation hors UE ; « TVA non applicable – article 259-1° du CGI » | International | M4 |
| V14 | `Feature/Tva/VentesHorsDeFranceTest::test_une_prestation_hors_ue_melangee_a_un_bien_est_refusee_avec_le_bouton_de_deplacement` | V13 + une Gourde | Refus `tva.document_separe_requis` ; le bouton crée une nouvelle facture avec la Gourde | — | — | M4 |
| V15 | `Feature/Tva/VentesHorsDeFranceTest::test_un_service_au_lieu_fixe_par_la_loi_execute_en_france_reste_a_la_tva_francaise_pour_un_client_etranger` | Client DE, Installation sur site, réponse « exécuté en France » | 200,00 / 40,00 / 240,00 | France | International | M3 |
| V16 | `Feature/Tva/VentesHorsDeFranceTest::test_un_client_de_saint_barthelemy_peut_recevoir_une_prestation_hors_champ` | Professionnel à Saint-Barthélemy (SIREN exigé), Paramétrage, geste « appliquer » | 100,00 / — / 100,00 | Prestation hors UE | International | M3 |
| V17 | `Feature/Tva/VentesEnFranceTest::test_une_vente_en_franchise_se_fait_a_zero_au_meme_prix_ttc` | Société en franchise, particulier, 2 Câbles | 80,00 / 0,00 / 80,00 | France ; « TVA non applicable, art. 293 B du CGI » | Particuliers | M2 |
| V18 | `Feature/Tva/CircuitTest::test_une_facture_en_franchise_a_un_professionnel_garde_le_meme_prix_et_reste_hors_plateforme` | Franchise, professionnel établi en France, 1 000 × 1,00 € | 1 000,00 / 0,00 / 1 000,00 | France, franchise | Facture électronique, retenue (catégorie E non prise en charge) | M3 |
| V19 | `Feature/Tva/VentesADistanceTest::test_une_vente_a_distance_sous_le_seuil_reste_a_la_tva_francaise_avec_le_compteur` | Particulier BE, expédié ; compteur 4 000,00 € | 66,66 / 13,34 / 80,00 ; compteur 4 066,66 € ; le vendeur voit l'information « seuil non atteint », absente de la fenêtre d'émission et des avertissements de la décision | France | Particuliers | M8 |
| V19a | `Feature/Tva/VentesADistanceTest::test_une_association_belge_non_assujettie_compte_dans_les_ventes_a_distance` | Association BE « non assujettie », sans n°, expédié ; compteur 4 000,00 € | 66,67 / 13,33 / 80,00 ; compteur 4 066,67 € | France | Particuliers | M8 |
| V19f | `Feature/Tva/VentesADistanceTest::test_une_societe_en_franchise_compte_ses_ventes_a_distance` | Franchise, particulier BE, expédié ; compteur 4 000,00 € | 80,00 / 0,00 / 80,00 ; compteur 4 080,00 € | France, franchise | Particuliers | M8 |
| V20 | `Feature/Tva/VentesADistanceTest::test_une_vente_a_distance_qui_franchirait_le_seuil_est_refusee` | Compteur 9 950,00 €, vente de V19 | Refus `tva.seuil_ventes_a_distance_depasse` (10 016,66 € > 10 000,00 €) | — | — | M8 |
| V20a | `Feature/Tva/VentesADistanceTest::test_une_vente_a_distance_a_une_association_qui_franchirait_le_seuil_est_refusee` | Compteur 9 950,00 €, vente de V19a | Refus (10 016,67 € > 10 000,00 €) | — | — | M8 |
| V20f | `Feature/Tva/VentesADistanceTest::test_une_vente_a_distance_d_une_societe_en_franchise_qui_franchirait_le_seuil_est_refusee` | Franchise, compteur 9 950,00 €, vente de V19f | Refus (10 030,00 € > 10 000,00 €) | — | — | M8 |
| V21 | `Feature/Tva/VentesEnFranceTest::test_un_particulier_de_l_ue_qui_emporte_ses_achats_paie_la_tva_francaise` | Ticket, client belge au comptoir | 66,66 / 13,34 / 80,00 | France, aucune question | Particuliers | M2 |
| V22 | `Feature/Tva/CircuitTest::test_des_biens_expedies_a_monaco_pour_un_client_monegasque_relevent_de_l_e_reporting` | Professionnel établi à Monaco, 10 Gourdes expédiées à Monaco | 100,00 / 20,00 / 120,00 | France | International (art. 290 I 4° a) | M3 |
| V22b | `Feature/Tva/CircuitTest::test_un_client_etabli_en_france_livre_a_monaco_garde_la_tva_francaise` | Professionnel établi en France, 10 Gourdes livrées à Monaco | 100,00 / 20,00 / 120,00 | France | Facture électronique | M3 |
| V23 | `Feature/Tva/CircuitTest::test_une_facture_a_un_organisme_public_reste_hors_plateforme_avec_un_message` | Mairie, 1 000 × 1,00 € | 833,33 / 166,67 / 1 000,00 | France | Chorus Pro | M3 |
| V24 | `Feature/Tva/CircuitTest::test_une_facture_a_un_professionnel_non_assujetti_va_dans_l_e_reporting_des_particuliers` | Association française non assujettie, 10 Gourdes | 100,00 / 20,00 / 120,00 | France | Particuliers | M3 |
| V25 | `Feature/Facturation/FactureDemandeeTest::test_une_facture_demandee_par_un_particulier_garde_le_circuit_des_particuliers` | Particulier, case cochée, 2 Câbles | 66,66 / 13,34 / 80,00 ; série des factures | France | Particuliers, jamais en facture électronique | C-b |
| V26 | `Feature/Tva/VentesHorsDeFranceTest::test_l_avoir_de_deux_livraisons_intracommunautaires_herite_de_leurs_decisions` | Deux factures V05 (geste « appliquer ») au même client ; avoir rendant 3 Gourdes de chacune | 60,00 / 0,00 / 60,00 | Livraison intracommunautaire, héritée | International | M3 |
| V26b | `Feature/Tva/VentesHorsDeFranceTest::test_un_avoir_ne_melange_pas_deux_factures_de_circuits_differents` | Deux livraisons intracommunautaires, la copie du client « établi en France » sur l'une seulement | Refus : « Un avoir ne mélange pas des factures transmises par des circuits différents : faites deux avoirs. » | — | — | M3 |
| V27 | `Feature/Tva/VentesHorsDeFranceTest::test_un_produit_soumis_a_accises_n_est_jamais_exonere_automatiquement` | V05 avec un produit « accises » | Nature refusée (`tva.cas_exclu`) ; vente en France : 100,00 / 20,00 / 120,00 | France | International | M4 |
| V28 | `Feature/Tva/VentesEnFranceTest::test_un_magasin_de_martinique_vend_au_taux_du_departement` | Magasin 972, particulier, 2 Câbles à 8,5 % | 73,74 / 6,26 / 80,00 | France | Particuliers | M2 |
| V29 | `Feature/Tva/VentesHorsDeFranceTest::test_un_magasin_de_martinique_qui_expedie_en_metropole_est_refuse_avec_un_message` | Magasin 972, livraison à Paris | Refus `tva.depart_hors_du_dom_non_gere` | — | — | M3 |
| V30 | `Feature/Tva/VentesHorsDeFranceTest::test_l_export_emporte_par_le_client_n_est_pas_encore_propose` | Client US qui emporte, 1 000 × 1,00 € | 833,33 / 166,67 / 1 000,00 ; information `tva.export_emporte_non_gere` | France | International | M3 |
| V31 | `Feature/Tva/VentesHorsDeFranceTest::test_un_service_au_lieu_fixe_par_la_loi_execute_en_allemagne_est_refuse` | Client DE, Installation sur site, réponse « hors de France » | Refus `tva.lieu_du_service_hors_de_france` | — | — | M3 |
| V33 | `Feature/Tva/VentesEnFranceTest::test_la_fiche_d_un_produit_refuse_le_taux_zero_pour_une_societe_redevable` | Produit à 0 %, société redevable ; puis une ligne mise à 0 % en base sur un brouillon | Fiche refusée (`tva.taux_zero_en_france`) ; émission refusée en filet de sécurité | — | — | M4 |
| V34 | `Feature/Tva/VentesHorsDeFranceTest::test_un_client_etabli_en_france_dont_les_biens_partent_en_suisse_obtient_un_export` | Professionnel établi en France, lien ouvert, 1 000 × 1,00 € expédiés à Genève | 833,33 / 0,00 / 833,33 | Export | Facture électronique, retenue | M4 |
| V35 | `Feature/Tva/VentesEnFranceTest::test_une_vente_en_france_livree_a_une_autre_adresse_imprime_l_adresse_de_livraison` | Professionnel établi en France, 10 Gourdes livrées à son entrepôt de Villeurbanne | 100,00 / 20,00 / 120,00 ; adresse de livraison imprimée sur le PDF | France | Facture électronique | M3 |
| V36 | `Feature/Tva/VentesEnFranceTest::test_une_facture_en_france_melant_un_produit_et_un_service_s_emet` | Professionnel établi en France, 1 Câble + Paramétrage | 133,33 / 26,67 / 160,00 ; lignes 33,33 + 6,67 et 100,00 + 20,00 | France ; « Catégorie de l'opération : Livraison de biens et prestation de services » | Facture électronique | M3 |
| V37 | `Feature/Tva/VentesHorsDeFranceTest::test_une_emission_d_une_nature_proposee_impose_un_choix_qui_est_trace` | V12, « Émettre à la TVA française » | 100,00 / 20,00 / 120,00 ; geste « TVA française gardée » avec auteur, nom figé et instant, signé | France | International | M4 |
| V38 | `Feature/Tva/VentesEnFranceTest::test_un_service_a_un_particulier_domicilie_hors_ue_est_signale` | Particulier à Genève, Paramétrage | 100,00 / 20,00 / 120,00 ; avertissement `tva.service_hors_ue_a_verifier` | France | Particuliers | M4 |
| V39 | `Feature/Tva/VentesHorsDeFranceTest::test_une_societe_en_franchise_vend_une_prestation_a_une_entreprise_de_l_ue_en_autoliquidation_au_prix_du_catalogue` | Franchise avec un n° de TVA, client IE valide, Paramétrage, appliquée | 120,00 / 0,00 / 120,00 | Prestation autoliquidée ; « Autoliquidation », sans la mention 293 B | International | M4 |
| V40 | `Feature/Tva/VentesHorsDeFranceTest::test_appliquer_une_autoliquidation_n_est_jamais_une_derogation_de_prix` | Vendeur sans l'autorisation des remises et prix ; V12 appliquée | Émise : 100,00 / 0,00 / 100,00 ; aucune dérogation enregistrée | Prestation autoliquidée | International | M4 |
| V41 | `Feature/Tva/VentesHorsDeFranceTest::test_une_livraison_anterieure_a_l_emission_sans_verification_a_sa_date_est_signalee` | V05, livraison le 01/10/2026, émission le 14/10/2026, aucune consultation datée du 01/10 | Montants de V05 ; avertissement `tva.vies_apres_la_livraison` | Livraison intracommunautaire | International | M4 |
| V42 | `Feature/Tva/VentesHorsDeFranceTest::test_une_expedition_a_un_particulier_belge_est_marquee_vente_a_distance` | Particulier BE, 2 Câbles expédiés | 66,66 / 13,34 / 80,00 ; `vente_a_distance` vrai, figé et signé | France | Particuliers | M3 |

V32 n'existe pas (TVA9 = c).

**Autres tests de vente**

| Fichier::méthode | Ce qu'il prouve | Lot |
|---|---|---|
| `Feature/Tva/ConsultationViesALEmissionTest::test_un_vies_injoignable_a_l_emission_garde_le_brouillon_et_le_dit` | Livraison intracommunautaire choisie, VIES ne répond pas : rien n'est émis, message distinct de « ne reconnaît pas », avec la date limite du 15/11/2026 ; aucune consultation enregistrée. | M1 |
| `Feature/Tva/ConsultationViesALEmissionTest::test_une_emission_rejouee_ne_consulte_pas_vies` | Même jeton, facture déjà émise : aucun appel à `RegistreVies`, aucune ligne dans `consultations_vies`. | M1 |
| `Feature/Tva/ConsultationViesALEmissionTest::test_aucune_consultation_vies_n_a_lieu_sous_le_verrou_du_registre` | Le faux registre note `DB::transactionLevel()` à l'appel : 0. | M1 |
| `Feature/Tva/ConsultationViesALEmissionTest::test_une_verification_valide_du_jour_n_est_pas_refaite` | Une consultation valide du jour existe : aucun appel. | M1 |
| `Feature/Tva/ConsultationViesALEmissionTest::test_une_derniere_consultation_du_jour_invalide_fait_consulter_vies_de_nouveau` | Seule consultation du jour : invalide ; `FauxRegistreVies` répond « valide » : consultation enregistrée, livraison intracommunautaire émise (même condition qu'aujourd'hui à l'écran). | M1 |
| `Feature/Tva/ConsultationViesALEmissionTest::test_un_numero_verifie_la_veille_est_verifie_de_nouveau_a_l_emission` | Consultation valide de la veille seulement ; `FauxRegistreVies` répond « inconnu » : refus avec le message actuel (« le n° de TVA DE123456789 n'a pas été trouvé valide sur VIES le 09/10/2026 ») ; répond « valide » : émise. | M1 |
| `Feature/Tva/ConsultationViesALEmissionTest::test_la_consultation_a_l_emission_exige_le_droit_d_emettre_et_pas_celui_de_modifier_le_client` | Vendeur avec `facture.emettre` sans `client.update` : consultation enregistrée à son nom, facture émise. | M1 |
| `Feature/Tva/ConsultationViesALEmissionTest::test_le_magasin_de_demonstration_consulte_toujours_le_faux_registre_vies` | Pilote `europa`, magasin de démonstration : `FauxRegistreVies` interrogé, `Http::assertNothingSent()`. | M1 |
| `Feature/Tva/RegistreViesTest::test_le_pilote_faux_est_refuse_en_production` | Démarrage refusé en production avec `services.vies.pilote = faux`. | M1 |
| `Feature/Tva/VentilationTest::test_la_ventilation_signee_et_celle_des_ecrans_donnent_le_meme_resultat` | Facture de V03 : 20 % HT 4,16 TVA 0,83 ; 5,5 % HT 8,53 TVA 0,47, par les deux ventilations. | M1 |
| `Feature/Tva/EmissionTvaTest::test_le_refus_fiscal_arrive_avant_l_avertissement_de_vente_sans_stock` | Produit en rupture et identité incomplète : un seul refus avec tous les manquements, sans demande de confirmation de vente sans stock. | M2 |
| `Feature/Tva/EmissionTvaTest::test_l_apercu_et_l_emission_donnent_la_meme_decision` | Même facture : décision de l'aperçu = décision de l'émission (hors instant). | M2 |
| `Feature/Tva/EmissionTvaTest::test_chaque_decision_porte_la_version_du_moteur_et_celle_des_regles` | « 1.0 » et la version du catalogue. | M2 |
| `Feature/Tva/VentesEnFranceTest::test_un_brouillon_d_export_rouvert_apres_le_passage_en_franchise_reprend_le_prix_ttc_du_catalogue_avec_une_notification` | Brouillon d'export (10 Gourdes + Port et emballage : 115,00 € HT) ; société passée en franchise ; rouvert : 120,00 € + 18,00 € à 0 %, 138,00 €, notification (complément 5 ; comportement gardé). | M2 |
| `Feature/Tva/CircuitTest::test_chaque_client_recoit_son_circuit` | Particulier, professionnel assujetti établi en France, non assujetti, organisme public, professionnel DE, monégasque, guyanais : les circuits de 3.15.2. | M3 |
| `Feature/Tva/CircuitTest::test_la_demonstration_n_a_ni_circuit_ni_obligation` | Démonstration, livraison intracommunautaire par un geste : circuit « aucun », aucune obligation. | M3 |
| `Feature/Tva/DecisionFigeeTest::test_la_decision_est_figee_et_signee_a_l_emission` | `decision_tva` écrite une fois ; bloc « document » signé avec la colonne décodée ; vérificateur sans anomalie. | M3 |
| `Feature/Tva/DecisionFigeeTest::test_une_decision_figee_se_relit_a_l_identique_sous_postgresql` | Sous PostgreSQL : type `json`, ordre des clés gardé. | M3 |
| `Feature/Tva/DecisionFigeeTest::test_une_modification_d_une_colonne_fiscale_figee_est_detectee_par_le_verificateur` | `decision_tva` changée en base (déclencheurs contournés dans le test) : anomalie. | M3 |
| `Feature/Tva/DecisionFigeeTest::test_un_duplicata_imprime_la_mention_figee_meme_apres_un_changement_de_regles` | Faux catalogue changé après l'émission : le duplicata imprime l'ancien texte. | M3 |
| `Feature/Tva/DecisionFigeeTest::test_un_avoir_emis_apres_un_changement_de_regles_garde_la_nature_de_sa_facture_et_prend_les_mentions_de_son_jour` | Avoir du 02/01/2027 sur une facture du 31/12/2026. | M3 |
| `Feature/Tva/GestesTest::test_choisir_une_nature_dans_la_liste_enregistre_le_geste_avec_son_auteur_et_l_instant` | Au lot M3, la liste reste l'entrée du geste. | M3 |
| `Feature/Tva/GestesTest::test_garder_la_vente_en_france_quand_une_nature_est_possible_enregistre_le_geste_tva_francaise_gardee` | Idem. | M3 |
| `Feature/Tva/GestesTest::test_un_geste_s_efface_quand_le_client_ou_la_sortie_des_biens_change` | Faits déclencheurs. | M3 |
| `Feature/Tva/GestesTest::test_une_facture_issue_d_un_devis_reprend_ses_faits_et_son_geste` | Devis d'une livraison intracommunautaire : faits et geste repris ; VIES du jour de la facture ; VIES devenu invalide : refus avec la voie « Rester à la TVA française » (O8). | M3 |
| `Feature/Tva/VentesEnFranceTest::test_le_nom_et_l_adresse_du_client_sont_exiges_sur_toute_facture` | Professionnel sans adresse : `client.adresse_absente` (O5). | M3 |
| `Feature/Filament/ClientResourceTest::test_un_client_de_saint_barthelemy_exige_un_siren_sans_etre_dans_le_territoire_de_tva` | « Établi en France » et territoire séparés. | M3 |
| `Feature/Tva/EmissionTvaTest::test_un_brouillon_dont_le_calcul_a_change_montre_l_avant_et_l_apres` | 10 Gourdes, professionnel : brouillon 100,00 / 20,00 / 120,00 ; taux de la fiche passé à 5,5 % ; refus `tva.calcul_du_brouillon_change`, après 113,74 / 6,26 / 120,00 ; « Mettre à jour et émettre » émet ces montants. | M4 |
| `Feature/Tva/EmissionTvaTest::test_la_derniere_facture_au_meme_client_est_proposee_et_se_confirme_a_l_emission` | Sortie des biens et destination proposées avec leur source ; l'émission exige la confirmation. | M4 |
| `Feature/Tva/ReleveDuMoisTest::test_le_releve_du_mois_somme_les_livraisons_intracommunautaires_nettes_des_avoirs_par_numero_de_tva` | Livraison de 100,00 € à DE123456789, avoir de 30,00 € le même mois, prestation autoliquidée de 100,00 € à un client IE : 70,00 € et 100,00 €. | M4 |
| `Feature/Tva/ReleveDuMoisTest::test_le_releve_d_un_magasin_ignore_les_autres_magasins` | Magasin B : rien de A. | M4 |
| `Feature/Tva/ReleveDuMoisTest::test_le_releve_de_la_societe_somme_ses_magasins_reels_sans_la_demonstration` | A 70,00 €, B 50,00 € au même n° : 120,00 € ; démonstration exclue. | M4 |
| `Feature/Tva/ReleveDuMoisTest::test_le_releve_de_la_societe_ne_se_voit_que_du_proprietaire_et_des_administrateurs_autorises` | Responsable et vendeur refusés. | M4 |
| `Feature/Tva/ReleveDuMoisTest::test_l_export_csv_du_releve` | En-têtes et montants à virgule. | M4 |
| `Feature/Tva/PreuvesAttenduesTest::test_les_preuves_attendues_se_lisent_dans_la_table_datee` | Mêmes manques qu'avant, `typesAdmis()` supprimée. | M4 |
| `Feature/Tva/VentesADistanceTest::test_une_societe_arrivee_en_octobre_avec_9500_euros_de_ventes_a_distance_est_refusee_au_dela_du_seuil` | Valeur de départ de l'année en cours 9 500,00 € ; 16 Câbles à un particulier BE (HT 533,28 €) : refus (10 033,28 € > 10 000,00 €). | M8 |
| `Feature/Tva/VentesADistanceTest::test_une_annee_precedente_au_dela_du_seuil_refuse_toute_vente_a_distance` | Valeur de départ de l'année précédente 12 000,00 € : V19 refusée. | M8 |
| `Feature/Tva/VentesADistanceTest::test_une_societe_inscrite_a_l_oss_ne_peut_pas_faire_de_vente_a_distance` | `tva.oss_non_gere`. | M8 |
| `Feature/Tva/VentesADistanceTest::test_un_avoir_de_vente_a_distance_diminue_le_compteur` | Après V19, avoir d'un Câble (33,33 €) : compteur 4 033,33 €. | M8 |
| `Feature/Tva/VentesADistanceTest::test_le_seuil_proche_s_affiche_des_9000_euros` | Compteur 8 950,00 € + 66,66 € : avertissement « seuil proche » (`tva.vente_a_distance_seuil_proche`), répété dans la fenêtre d'émission et gardé dans la décision ; compteur 8 900,00 € + 66,66 € : information « seuil non atteint » seulement. | M8 |
| `Feature/Tva/VentesADistanceTest::test_la_vente_au_comptoir_reste_possible_au_dela_du_seuil` | Compteur 10 500,00 € ; ticket au comptoir pour un client belge : émis. | M8 |
| `Feature/Tva/VentesADistanceTest::test_deux_ventes_a_distance_simultanees_dans_deux_magasins_ne_depassent_jamais_le_seuil` | Groupe `concurrence`, PostgreSQL : compteur 9 900,00 € ; deux ventes de 66,66 € en parallèle dans A et B ; une seule émise. | M8 |
| `Feature/Tva/VentesADistanceTest::test_une_vente_a_distance_et_une_validation_d_achat_du_meme_compte_ne_s_interbloquent_pas` | Groupe `concurrence`, PostgreSQL : le propriétaire émet une vente à distance dans A pendant qu'il valide un achat dans B (inscription `FactureAchatValidee` au journal d'audit) ; les deux réussissent, aucun interblocage ; la ligne `societes` est prise en `FOR NO KEY UPDATE` (requête relevée). | M8 |
| `Feature/Tva/VentesADistanceTest::test_le_proprietaire_voit_le_montant_du_compteur` et `::test_un_vendeur_ne_voit_pas_le_montant_du_compteur` | Visibilité. | M8 |
| `Feature/Tva/VentesADistanceTest::test_les_valeurs_de_depart_et_l_oss_s_inscrivent_au_jet_270_de_chaque_magasin` | JET 270 et audit. | M8 |

#### 3.23.2 Achats

Société redevable, magasin de Lyon, produit « Gourde » vendu à 20 %, sauf mention contraire. Colonnes : HT ; TVA facturée ; TVA autoliquidée ; TVA déductible ; dû au fournisseur ; coût unitaire d'entrée. **Règle des fichiers** : un test marqué « M5c + M5d » existe deux fois, sous le même nom : dans `Unit/Tva/MoteurAchatTest` (moteur seul, faits construits à la main, aucune base) et dans `Feature/Achats/ValidationDesAchatsTest` (par les actions, validation comprise). Les autres sont dans le fichier indiqué.

| # | Méthode (fichier) | Faits | HT | Facturée | Autoliq. | Déductible | Dû | Coût | Lot |
|---|---|---|---|---|---|---|---|---|---|
| A01 | `test_un_achat_en_france_garde_la_tva_facturee_deductible` | Fournisseur français, 10 × 8,00 € à 20 % | 80,00 | 16,00 | 0 | 16,00 | 96,00 | 8,0000 | M5c + M5d |
| A02 | `test_le_taux_facture_d_une_ligne_d_achat_ne_change_jamais_le_taux_de_vente_du_produit` (ValidationDesAchatsTest) | Idem, ligne à 5,5 % ; produit vendu à 20 % | 80,00 | 4,40 | 0 | 4,40 | 84,40 | 8,0000 ; taux de vente toujours 20 % | M5d |
| A03 | `test_un_fournisseur_francais_en_franchise_ne_facture_aucune_tva` | 10 × 8,00 € | 80,00 | 0 | 0 | 0 | 80,00 | 8,0000 | M5c + M5d |
| A04 | `test_une_acquisition_intracommunautaire_autoliquide_la_tva_sans_rien_payer_au_fournisseur` | Spielwaren (n° DE), origine DE confirmée, 100 × 8,00 € + port du fournisseur 40,00 € | 840,00 | 0 | 168,00 | 168,00 | 840,00 | 8,4000 | M5c + M5d |
| A05 | `test_les_frais_d_une_acquisition_se_repartissent_par_cumul_au_prorata_de_la_valeur` | 100 × 8,00 € + 50 × 4,00 € + port 40,00 € | 1 040,00 | 0 | 208,00 | 208,00 | 1 040,00 | 8,3200 et 4,1600 | M5c + M5d |
| A06 | `test_une_facture_de_l_ue_portant_une_tva_etrangere_se_valide_avec_une_regularisation` (Feature/Achats/AchatsEtrangersTest) | Spielwaren, 100 × 8,00 € à 19 % | 800,00 ; TVA allemande 152,00 à régulariser | 0 (française) | 160,00 | 160,00 | 952,00 | 8,0000 ; liste « à régulariser » | M5e |
| A07 | `test_une_acquisition_sans_numero_de_tva_de_la_societe_est_bloquee` (ValidationDesAchatsTest) | Société sans n° de TVA | Refus `tva.numero_tva_societe_absent` | | | | | | M5d |
| A08 | `test_l_origine_d_un_fournisseur_non_etabli_doit_etre_confirmee_a_sa_premiere_facture` (ValidationDesAchatsTest) | Fournisseur DE, première facture dans le magasin : son pays proposé, non confirmé | Refus `tva.origine_non_confirmee` ; confirmée : validée, montants de A04 | | | | | | M5d |
| A08b | `test_une_deuxieme_facture_reprend_l_origine_de_la_derniere_facture_validee_sans_nouvelle_confirmation` (ValidationDesAchatsTest) | A08 validée ; deuxième facture du même fournisseur | Origine reprise, avec sa source affichée (« comme la facture … du … ») ; validée sans confirmation | | | | | | M5d |
| A08c | `test_une_origine_modifiee_doit_etre_confirmee` (ValidationDesAchatsTest) | Deuxième facture, origine changée en AT | Refus `tva.origine_non_confirmee` jusqu'à la confirmation | | | | | | M5d |
| A09 | `test_le_pays_du_fournisseur_seul_ne_fait_pas_une_acquisition_intracommunautaire` | Fournisseur DE non établi, marchandises parties d'un entrepôt en France, 10 × 50,00 € | 500,00 | 0 | 100,00 | 100,00 | 500,00 | 50,0000 | M5c + M5d |
| A10 | `test_un_fournisseur_etranger_etabli_en_france_facture_la_tva_francaise` | Fournisseur DE, case « établissement en France » cochée, 10 × 50,00 € à 20 % | 500,00 | 100,00 | 0 | 100,00 | 600,00 | 50,0000 | M5c + M5d |
| A11 | `test_une_importation_dedouanee_par_le_magasin_reprend_la_tva_de_la_declaration_et_ajoute_les_droits_au_cout` | Fournisseur CN, 100 × 6,60 € ; déclaration : valeur 660,00, droits 24,00, TVA 136,80 | 660,00 | 0 | 136,80 | 136,80 | 660,00 (+ 24,00 de droits à la douane) | 6,8400 | M5c + M5d |
| A11b | `test_les_frais_jusqu_au_premier_lieu_de_destination_entrent_dans_la_base_de_controle_de_la_tva_d_importation` (MoteurAchatTest) | A11 + frais accessoires 50,00 ; TVA déclarée 146,80 | Attendue (660,00 + 24,00 + 50,00) × 20 % = 146,80 : aucun avertissement | | | | | | M5c |
| A12 | `test_un_ecart_entre_la_tva_d_importation_saisie_et_la_tva_attendue_est_signale` | A11, TVA saisie 136,00 € | Avertissement `tva.ecart_tva_importation` (attendue 136,80 €) ; montant de la déclaration retenu | | | | | | M5c + M5d |
| A13 | `test_une_importation_validee_avant_sa_declaration_en_douane_corrige_son_cout_ensuite` (Feature/Achats/ImportationTest) | Validation sans déclaration, puis droits de 24,00 € | Coût 6,6000, puis 6,8400 ; coût moyen recalculé par le rejeu ; aucun mouvement modifié ni ajouté ; liste « à compléter » vidée | | | | | | M7 |
| A13b | `test_une_facture_saisie_a_la_main_garde_sa_saisie_quand_sa_declaration_en_douane_est_completee` (ImportationTest) | Fournisseur CN, produit soumis à accises (cas exclu), dédouané par nous, 100 × 6,60 € ; TVA d'importation 136,80 € saisie à la main avec un motif ; déclaration complétée ensuite : droits 24,00 € | Coût 6,6000, puis 6,8400 ; TVA saisie, auteur et motif inchangés (saisie reprise) ; audit `CoutDEntreeRecalcule` qui cite la saisie, aucun nouveau `TvaDAchatSaisieALaMain` | | | | | | M7 |
| A14 | `test_une_marchandise_hors_ue_dedouanee_par_un_fournisseur_non_etabli_est_autoliquidee` | Fournisseur CN, dédouané par lui, 10 × 50,00 € | 500,00 | 0 | 100,00 | 100,00 | 500,00 | 50,0000 | M5c + M5d |
| A15 | `test_un_dedouanement_inconnu_bloque_la_validation_d_un_achat_hors_ue` (ValidationDesAchatsTest) | Origine CN, dédouanement non dit | Refus `tva.dedouanement_inconnu` | | | | | | M5d |
| A16 | `test_un_service_d_un_prestataire_de_l_ue_est_autoliquide_et_hors_stock` | Agence IE, ligne de charge « Autres charges », service, 1 000,00 € | 1 000,00 | 0 | 200,00 | 200,00 | 1 000,00 | hors stock | M5c + M5d |
| A17 | `test_un_service_d_un_prestataire_hors_ue_est_autoliquide` | Éditeur US, ligne de charge « Logiciels », 300,00 € | 300,00 | 0 | 60,00 | 60,00 | 300,00 | hors stock | M5c + M5d |
| A18 | `test_un_service_etranger_au_lieu_fixe_par_la_loi_passe_en_saisie_manuelle` (ValidationDesAchatsTest) | Hôtel à Berlin, case « lieu fixé par la loi » | Refus `tva.cas_exclu`, sauf saisie manuelle autorisée | | | | | | M5d |
| A19 | `test_un_transport_facture_par_un_transporteur_francais_entre_dans_le_cout_des_marchandises_rattachees` (ImportationTest) | A04 sans port ; transporteur français 40,00 € HT + 8,00 € rattaché | 40,00 | 8,00 | 0 | 8,00 | 48,00 | Marchandises 8,4000 | M7 |
| A19b | `test_un_transport_exonere_lie_a_une_importation_entre_dans_le_cout` (ImportationTest) | A11b ; transporteur français 50,00 € à 0 %, raison « importation », rattaché | 50,00 | 0 | 0 | 0 | 50,00 | Marchandises 7,3400 | M7 |
| A19c | `test_des_frais_rattaches_a_une_facture_saisie_a_la_main_gardent_sa_saisie` (ImportationTest) | Opération triangulaire possible (A31), 100 × 8,00 €, TVA autoliquidée et déductible de 160,00 € saisies à la main ; puis transporteur français 40,00 € HT + 8,00 € rattaché | Facture du transporteur validée ; marchandises 8,4000 ; TVA saisie inchangée | | | | | | M7 |
| A19d | `test_deux_factures_de_frais_rattachees_aux_memes_factures_dans_l_ordre_inverse_ne_s_interbloquent_pas` (ImportationTest) | Groupe `concurrence`, PostgreSQL : frais F1 rattachés à G puis H, frais F2 à H puis G, validés en parallèle | Les deux validées ; coûts de G et H recalculés deux fois, dans l'ordre des verrous de 3.17.5 | | | | | | M7 |
| A20 | `test_un_fournisseur_monegasque_dont_les_biens_viennent_de_monaco_facture_la_tva_francaise` | Fournisseur MC, origine Monaco, 10 × 8,00 € à 20 % | 80,00 | 16,00 | 0 | 16,00 | 96,00 | 8,0000 | M5c + M5d |
| A20b | `test_un_fournisseur_monegasque_dont_les_biens_partent_de_france_facture_la_tva_francaise` (MoteurAchatTest) | Fournisseur MC, origine France | 80,00 | 16,00 | 0 | 16,00 | 96,00 | 8,0000 | M5c |
| A21 | `test_une_societe_en_franchise_porte_la_tva_d_un_achat_dans_son_cout` | Franchise, 10 × 8,00 € + 20 % | 80,00 | 16,00 | 0 | 0 | 96,00 | 9,6000 | M5c + M5d |
| A22 | `test_une_acquisition_d_une_societe_en_franchise_est_refusee` | Franchise, Spielwaren | Refus `tva.achat_etranger_en_franchise_non_gere` | | | | | | M5c + M5d |
| A23 | `test_un_achat_en_devise_sans_taux_de_change_est_bloque` (Feature/Achats/DevisesTest) | USD sans taux | Refus `tva.devise_sans_taux` | | | | | | M5e |
| A24 | `test_l_avoir_fournisseur_d_une_acquisition_reprend_l_autoliquidation_et_sort_au_cout_d_entree` (Feature/Achats/AvoirsFournisseurTvaTest) | Retour de 30 sur A04 | −240,00 | 0 | −48,00 | −48,00 | −240,00 | Sortie 30 × 8,4000 = 252,00 | M5e |
| A25 | `test_l_avoir_fournisseur_en_france_reprend_la_tva_facturee` (AvoirsFournisseurTvaTest) | Retour de 2 sur A01 | −16,00 | −3,20 | 0 | −3,20 | −19,20 | Sortie 2 × 8,0000 = 16,00 | M5e |
| A26 | `test_l_api_d_achat_cree_un_brouillon_et_refuse_la_validation_avec_tous_les_codes` (ValidationDesAchatsTest) | Brouillon d'un fournisseur DE sans origine ni n° de facture du fournisseur | Brouillon créé ; validation refusée avec `tva.origine_non_confirmee` et `achat.numero_fournisseur_absent` ; aucun stock, aucun numéro | | | | | | M5d |
| A27 | `test_la_validation_fige_la_decision_et_une_modification_la_remplace_avec_l_ancienne_au_journal_d_audit` (ValidationDesAchatsTest) | Validation, puis modification avec l'autorisation | `decision_tva` remplacée ; audit `FactureAchatValidee` puis `FactureAchatModifiee` avec l'ancienne décision | | | | | | M5d |
| A28 | `test_un_fournisseur_de_l_ue_au_numero_invalide_sur_vies_n_est_qu_un_avertissement` (ValidationDesAchatsTest) | A04, VIES du fournisseur invalide | Avertissement `tva.vies_fournisseur_invalide` ; montants de A04 | | | | | | M5d |
| A29 | `test_la_tva_autoliquidee_d_un_taux_se_calcule_sur_son_total_et_se_repartit_par_cumul` | Acquisition, lignes 1 × 0,83 € et 3 × 1,11 € à 20 % | 4,16 | 0 | 0,83 (0,17 + 0,66, et non 0,84) | 0,83 | 4,16 | — | M5c + M5d |
| A29c | Selon 6.1 c : option 1, `test_la_tva_facturee_d_un_taux_se_calcule_sur_son_total_et_se_repartit_par_cumul` ; option 2, `test_la_tva_facturee_se_calcule_ligne_par_ligne` | Fournisseur établi en France, lignes 1 × 0,83 € et 3 × 1,11 € à 20 % | 4,16 | Option 1 : 0,83 (0,17 + 0,66) ; option 2 : 0,84 (0,17 + 0,67) | 0 | Égale à la facturée | 4,99 ou 5,00 | — | M5c + M5d |
| A29b | `test_les_retours_d_une_ligne_autoliquidee_ne_rendent_jamais_plus_que_sa_part` (AvoirsFournisseurTvaTest) | Retour de 2 articles de la 2e ligne de A29, puis du dernier | 1er avoir : −2,22 HT, autoliquidée −0,44 ; 2e avoir : −1,11 HT, autoliquidée −0,22 ; total rendu 0,66 = part de la ligne | | | | | | M5e |
| A31 | `test_un_fournisseur_belge_qui_expedie_d_allemagne_est_une_operation_triangulaire_possible` | n° BE, origine DE | Refus `tva.operation_triangulaire_possible` | | | | | | M5c + M5d |
| A32 | `test_une_ligne_a_zero_d_un_fournisseur_francais_demande_pourquoi` (ValidationDesAchatsTest) | Ligne de produit à 0 %, sans réponse ; puis « La facture porte « Autoliquidation » » | Refus `tva.taux_zero_sans_raison` ; puis `tva.cas_exclu` | | | | | | M5d |
| A33 | `test_un_fournisseur_francais_qui_expedie_d_allemagne_est_un_cas_a_verifier` | Fournisseur établi en France, origine DE | Refus `tva.faits_contradictoires` | | | | | | M5c + M5d |
| A34 | `test_une_importation_dedouanee_par_le_magasin_aupres_d_un_fournisseur_francais` | Fournisseur établi en France, origine CN, dédouané par nous, 100 × 6,60 € sans TVA ; déclaration : droits 24,00, TVA 136,80 | 660,00 | 0 | 136,80 | 136,80 | 660,00 (+ 24,00) | 6,8400 | M5c + M5d |
| A35 | `test_un_fournisseur_a_l_adresse_etrangere_n_est_pas_etabli_en_france_par_defaut` (Feature/Filament/FournisseurResourceTest) | Pays DE sans la case ; puis par l'API sans la valeur ; pays FR | Faux, faux, vrai | | | | | | M5b |
| A36 | `test_un_achat_valide_en_franchise_puis_modifie_apres_le_passage_au_reel_garde_la_non_deduction` (ValidationDesAchatsTest) | Franchise jusqu'au 31/10/2026, réel dès le 01/11 ; facture du 20/10, 10 × 8,00 € + 20 % ; modifiée le 05/11 en 12 articles | 96,00 | 19,20 | 0 | 0 | 115,20 | 9,6000 | M5d |
| A37 | `test_le_cout_d_une_facture_avec_un_avoir_fournisseur_ne_se_corrige_plus` (ImportationTest) | A13, avoir fournisseur émis avant la déclaration | Refus `tva.cout_fige_par_un_avoir_fournisseur` | | | | | | M7 |
| A38 | `test_deux_factures_d_un_meme_arrivage_portent_la_meme_declaration_avec_leur_part` (ValidationDesAchatsTest) | Même référence sur deux factures, chacune sa part | Contrôle facture par facture ; aucun refus | | | | | | M5d |
| A39 | `test_un_taux_d_achat_inhabituel_est_signale` | Ligne à 7 % en métropole | Avertissement `tva.taux_inhabituel` | | | | | | M5c + M5d |
| A40 | `test_la_saisie_manuelle_d_un_achat_exige_l_autorisation_dediee_et_un_motif` (Feature/Achats/SaisieManuelleTvaTest) | A18 ; vendeur (même avec `facture_achat.valider`), administrateur sans la case, expert-comptable, puis responsable, administrateur avec la case, propriétaire | Les trois premiers refusés ; les trois suivants : décision `saisieManuelle` (auteur, motif), audit `TvaDAchatSaisieALaMain` ; sans motif : `tva.motif_de_saisie_manuelle_absent` | | | | | | M5e |
| A40b | `test_modifier_les_lignes_d_une_facture_saisie_a_la_main_exige_une_nouvelle_saisie` (SaisieManuelleTvaTest) | A40 validée par saisie manuelle ; modification de la quantité d'une ligne | Refus `tva.saisie_manuelle_a_refaire` ; nouvelle saisie par un membre autorisé : modifiée, audit `TvaDAchatSaisieALaMain` | | | | | | M5e |
| A41 | `test_un_service_etranger_achete_en_franchise_est_autoliquide_sans_deduction` | Franchise, agence IE, ligne de charge 1 000,00 € | 1 000,00 | 0 | 200,00 | 0 | 1 000,00 | hors stock ; charge 1 200,00 ; TVA à payer à l'État 200,00 | M5c + M5d |
| A42 | `test_le_dedouanement_d_une_facture_recue_par_l_api_se_complete_sans_modifier_ses_lignes` (ImportationTest) | Facture source API, déclaration complétée après validation | Coût recalculé ; lignes inchangées ; `estModifiable()` toujours faux | | | | | | M7 |
| A43 | `test_un_service_facture_en_dollars_se_convertit_au_taux_saisi_avec_sa_source` (DevisesTest) | Éditeur US, ligne de charge « Logiciels », 100,00 USD, taux 0,92345678, source « facture du fournisseur » | 92,35 | 0 | 18,47 | 18,47 | 92,35 (100,00 USD) | hors stock | M5e |
| A43b | `test_des_articles_factures_en_dollars_ont_un_cout_au_dix_millieme` (DevisesTest) | Fournisseur non établi, entrepôt en France, 10 × 12,34 USD, taux 0,90000000 | 111,06 | 0 | 22,21 | 22,21 | 111,06 (123,40 USD) | 11,1060 | M5e |
| A44 | `test_une_importation_en_devise_impose_le_taux_de_la_declaration` (DevisesTest) | A11 en USD, source « relevé bancaire » | Refus `tva.source_du_taux_imposee` | | | | | | M5e |
| A45 | `test_la_regularisation_de_la_tva_etrangere_fait_sortir_la_facture_de_la_liste` (AchatsEtrangersTest) | A06, régularisation notée (« facture rectificative reçue ») par un membre autorisé à valider | Liste vide ; audit `TvaEtrangereRegularisee` ; un vendeur sans `facture_achat.valider` refusé | | | | | | M5e |
| A45b | `test_une_regularisation_deja_notee_est_refusee` (AchatsEtrangersTest) | A45, deuxième demande (double soumission) | Refus `tva.regularisation_deja_notee` ; colonnes inchangées, une seule inscription `TvaEtrangereRegularisee` | | | | | | M5e |
| A46 | `test_le_refus_de_validation_liste_aussi_le_droit_de_creer_des_produits` (ValidationDesAchatsTest) | Nouveau produit, membre sans `produit.create`, origine non confirmée | Un refus : `achat.droit_de_creer_des_produits` et `tva.origine_non_confirmee` | | | | | | M5d |
| A47 | `test_des_frais_sans_produit_ni_facture_rattachee_sont_refuses` (ValidationDesAchatsTest) | Une ligne de frais seule | Refus `achat.frais_sans_produit` | | | | | | M5d |
| A48 | `test_une_ligne_de_charge_exige_une_categorie_et_jamais_salaires` (ValidationDesAchatsTest) | Ligne de charge sans catégorie ; puis « Salaires » | `achat.categorie_absente` ; `achat.categorie_salaires_interdite` | | | | | | M5d |
| A49 | `test_un_loyer_sans_tva_d_un_bailleur_francais_ne_demande_pas_pourquoi` (ValidationDesAchatsTest) | Ligne de charge « Loyer » 1 500,00 € à 0 % | Raison « exonérée » posée d'office ; 1 500,00 / 0 / 0 / 0 / 1 500,00 | | | | | | M5d |
| A50 | `test_une_charge_sur_un_bien_importe_est_un_cas_exclu` (ValidationDesAchatsTest) | Ligne de charge « Autres charges », bien, origine CN, dédouanée par nous | Refus `tva.cas_exclu` | | | | | | M5d |
| A51 | `test_une_assurance_d_un_assureur_luxembourgeois_n_est_pas_autoliquidee` | Assureur établi au Luxembourg, ligne de charge « Assurances », 500,00 € | 500,00 | 0 | 0 | 0 | 500,00 | hors stock ; TVA à payer à l'État 0,00 ; raison « exonérée » | M5c + M5d |
| A52 | `test_des_commissions_d_un_etablissement_de_paiement_irlandais_ne_sont_pas_autoliquidees` | Établissement de paiement établi en Irlande, ligne de charge « Frais bancaires », 30,00 € | 30,00 | 0 | 0 | 0 | 30,00 | hors stock ; TVA à payer à l'État 0,00 | M5c + M5d |
| A52b | `test_des_frais_bancaires_etrangers_soumis_a_la_tva_s_autoliquident_sur_reponse_non` | Location d'un terminal de paiement par un prestataire établi en Irlande, « Frais bancaires », réponse « Non » à la question de l'exonération, 30,00 € | 30,00 | 0 | 6,00 | 6,00 | 30,00 | hors stock | M5c + M5d |
| A53 | `test_une_piece_qui_n_est_pas_une_facture_n_ouvre_aucune_deduction` | Fournisseur établi en France sans SIRET, 10 × 8,00 € à 20 %, pièce « ticket ou reçu » | 80,00 | 16,00 | 0 | 0 | 96,00 | 9,6000 (TVA non déductible dans le coût) | M5c + M5d |
| A53b | `test_la_nature_de_la_piece_est_preremplie_pour_un_fournisseur_qui_a_un_siret_et_exigee_sinon` (ValidationDesAchatsTest) | Fournisseur avec SIRET ; puis sans SIRET, facture saisie à la main ; puis facture reçue par l'API | « Facture » d'office ; refus `achat.nature_piece_inconnue` ; « facture » d'office | | | | | | M5d |

Mentions attendues sur la facture reçue (rappel dans le panneau, avertissement seulement pour une facture électronique reçue ; une mention qui manque à une facture n'est jamais un refus de déduction, alors qu'une pièce qui n'est pas une facture n'en ouvre aucune, A53) : achat en France, mentions générales ; fournisseur en franchise, « TVA non applicable, art. 293 B du CGI » ; acquisition, n° de TVA du fournisseur et de la société, et mention d'exonération ; autoliquidation, « Autoliquidation » ; importation, aucune mention française.

**Tests qui dépendent d'une question de 6.1** (le lot qui les écrit attend la réponse, 6.0, et les écrit pour l'option retenue) : A29c (6.1 c) ; A25 (6.1 c, option 1 : la TVA facturée rendue suit la règle de la TVA autoliquidée rendue ; aucun montant ne change dans cet exemple à une ligne) ; A05 (6.1 d, frais : « par cumul » dans le nom pour l'option recommandée, mêmes montants pour les deux options) ; A43 et A43b (6.1 d, devises : montants de l'option recommandée) ; CH-09 (6.1 d, TVA déductible partielle) ; CH-14 et CH-16 (6.1 d, répartition des charges communes ; CH-15 et CH-22 tombent juste pour toute option).

#### 3.23.3 Stock

| Fichier::méthode | Données et résultat | Lot |
|---|---|---|
| `Feature/Stock/CoutDEntreeTest::test_une_reception_met_le_cout_moyen_au_cout_d_entree_de_la_ligne_sans_ecrire_de_cout_sur_le_mouvement` | A04 : coût moyen 8,4000 € ; le mouvement de réception n'a aucun coût. | M5d |
| `Feature/Stock/CoutDEntreeTest::test_le_cout_moyen_d_une_societe_en_franchise_comprend_la_tva_non_deductible` | A21 : coût moyen 9,6000 €. | M5d |
| `Feature/Stock/CoutDEntreeTest::test_un_retour_fournisseur_sort_au_cout_d_entree_de_sa_ligne_et_recalcule_le_cout_moyen` | 100 articles à 8,4000 € (acquisition), puis 100 à 10,0000 € (France) : coût moyen 9,2000 € ; retour de 30 articles de l'acquisition à 8,4000 € ; 170 articles valent 1 840,00 − 252,00 = 1 588,00 €, coût moyen 9,3412 €. | M5e |
| `Feature/Stock/CoutDEntreeTest::test_la_valeur_du_stock_s_affiche_au_cout_sans_tva` | 10 articles à 8,4000 € : 84,00 € (et non 100,80 €). | M7 |
| `Feature/Stock/CoutDEntreeTest::test_la_correction_d_une_declaration_en_douane_recalcule_le_cout_moyen_sans_toucher_au_grand_livre` | A13. | M7 |
| `Feature/Stock/CoutDEntreeTest::test_le_cout_fige_par_un_avoir_fournisseur_n_est_jamais_corrige` | A37. | M7 |
| `Feature/Stock/CoutDEntreeTest::test_le_benefice_affiche_d_une_societe_en_franchise_se_calcule_sur_le_prix_encaisse` | Franchise : Gourde vendue 12,00 € à 0 %, coût 9,6000 € : bénéfice affiché 2,40 €. | M7 |

#### 3.23.4 Non-régression des montants

- Cas explicites aux montants écrits à la main : V01 à V04, V17, V28, V36, A01, A02, A05 ; avec `tests/Unit/CalculateurLigneTest.php` (déplacé au lot M1 avec sa classe), `RepartitionTvaParCumulTest` et `NatureOperationTest`, ils fixent les montants au centime.
- Comparaison avant et après, une seule fois, hors du dépôt : au lot M2 (ventes : documents tirés au hasard, calculés par le code d'avant et celui d'après) et au lot M5d (achats en France). Le résultat (nombre de documents, aucun écart) est noté dans la relecture du lot ; rien n'est versionné.

#### 3.23.5 Versionnement par date

| Fichier::méthode | Lot |
|---|---|
| `Unit/Tva/CatalogueDesReglesTest::test_une_mention_se_choisit_selon_la_date_de_l_operation` (faux catalogue où le texte change le 01/01/2027 : faits du 31/12/2026, ancien texte ; du 02/01/2027, nouveau) | M2 |
| `Unit/Tva/CatalogueDesReglesTest::test_un_taux_qui_n_est_plus_en_vigueur_a_la_date_de_l_operation_est_signale` | M2 |
| `Unit/Tva/CatalogueDesReglesTest::test_modifier_les_donnees_des_regles_sans_changer_leur_version_fait_echouer_la_suite` (empreinte) | M2 |
| `Unit/Tva/CatalogueDesReglesTest::test_les_mentions_de_depart_reprennent_mot_pour_mot_les_textes_du_code` | M2 |
| `Feature/Tva/DecisionFigeeTest::test_un_duplicata_imprime_la_mention_figee_meme_apres_un_changement_de_regles` | M3 |
| `Feature/Tva/DecisionFigeeTest::test_un_avoir_emis_apres_un_changement_de_regles_garde_la_nature_de_sa_facture_et_prend_les_mentions_de_son_jour` | M3 |
| `Feature/Societe/RegimeTvaDateTest::test_le_regime_de_la_societe_se_lit_a_la_date_de_l_operation` (franchise jusqu'au 31/10/2026, réel dès le 01/11 : un ticket du 31/10 à 0 %, un brouillon émis le 02/11 à la TVA) | M5a |
| `Unit/Tva/CatalogueDesReglesTest::test_les_regles_de_recuperation_des_charges_sont_datees_et_versionnees` | CH2 |

#### 3.23.6 Pureté et cohérence

| Fichier::méthode | Lot |
|---|---|
| `Feature/Tva/PureteDuMoteurTest::test_le_moteur_rend_la_meme_decision_pour_les_memes_faits` | M2 |
| `Feature/Tva/PureteDuMoteurTest::test_le_moteur_ne_lit_ni_la_base_ni_l_horloge` (aucune requête par `DB::listen` ; `Carbon::setTestNow()` ne change rien) | M2 ; étendu aux achats au lot M5c (`::test_le_moteur_d_achat_ne_lit_ni_la_base_ni_l_horloge`) |
| `Feature/Tva/PureteDuMoteurTest::test_le_pays_seul_ne_change_jamais_la_nature_d_une_vente` | M2 |
| `Feature/Tva/PureteDuMoteurTest::test_le_pays_seul_ne_change_jamais_la_nature_d_un_achat` | M5c |
| `Feature/Tva/DecisionFigeeTest::test_une_decision_figee_se_relit_a_l_identique_sous_postgresql` ; `::test_une_decision_d_achat_se_relit_a_l_identique_sous_postgresql` | M3 ; M5d |
| `Feature/Tva/ConsultationViesALEmissionTest::test_aucune_consultation_vies_n_a_lieu_sous_le_verrou_du_registre` | M1 |
| `Feature/Tva/VentilationTest::test_la_ventilation_signee_et_celle_des_ecrans_donnent_le_meme_resultat` | M1 |
| `Feature/Contacts/ConsultationViesTest::test_une_consultation_vies_porte_exactement_un_client_ou_un_fournisseur` (contrainte CHECK sous PostgreSQL, déclencheurs sous SQLite ; insertion directe refusée par les deux) | M5b |
| `Feature/Filament/AucunCodeTechniqueTest::test_aucun_code_technique_n_apparait_dans_les_pages` : les pages de facture, de devis, de caisse (M4), de facture d'achat (M6) et de charge (CH2) ne contiennent ni « VATEX », ni « 0223 », ni un nom d'énumération (`livraison_intracommunautaire`, `acquisition_intracommunautaire`, `e_reporting_international`, `gazole_essence_e85_vehicule_de_tourisme`…), ni une catégorie (« AE », « K », « G ») comme mot isolé | M4, étendu en M6 et CH2 |

#### 3.23.7 Isolation

Fichier `Feature/Tenancy/IsolationTvaTest` (nouveau), sauf mention.

| Méthode | Lot |
|---|---|
| `test_la_suggestion_de_sortie_ne_lit_que_les_factures_du_magasin` | M4 |
| `test_le_releve_de_la_societe_ne_lit_que_ses_magasins_reels` | M4 |
| `test_le_compteur_des_ventes_a_distance_ne_lit_que_des_sommes_des_magasins_reels_de_la_societe` (démonstration et autre société exclues) | M8 |
| `test_le_regime_de_tva_d_une_societe_est_invisible_d_une_autre` | M5a |
| `test_les_documents_a_revoir_ne_listent_que_les_magasins_reels_de_la_societe` | M5a |
| `test_les_consultations_vies_d_un_fournisseur_restent_dans_son_magasin` | M5b |
| `test_les_decisions_d_achat_d_un_magasin_sont_invisibles_dans_un_autre` | M5d |
| `test_le_preremplissage_de_l_origine_ne_lit_que_les_factures_validees_du_magasin` | M5d |
| `test_des_frais_ne_se_rattachent_qu_a_une_facture_d_achat_du_meme_magasin` | M7 |
| `test_une_piece_justificative_d_un_magasin_est_introuvable_dans_un_autre` | CH1 |
| `test_une_charge_d_un_magasin_est_invisible_dans_un_autre` | CH2 |
| `test_un_modele_recurrent_ne_cree_que_dans_son_magasin` | CH3 (s'il est retenu) |
| `test_les_salaires_ne_s_ecrivent_que_dans_un_magasin_de_la_societe_et_de_la_portee` | CH4 |
| `test_une_part_de_charge_commune_ne_se_voit_que_de_son_magasin` | CH5 |
| `test_le_detail_d_une_charge_commune_ne_se_lit_que_depuis_un_magasin_qui_en_a_une_part` (un magasin hors de la répartition, ou d'une autre société, ne lit ni le détail ni la pièce) | CH5 |
| `test_une_charge_commune_d_une_societe_est_invisible_d_une_autre` | CH5 |
| `Feature/Tenancy/TenantIsolationTest::test_chaque_modele_de_magasin_est_liste_ici` : `AnnulationPreuveOperation`, `SuiteBordereauDetaxe` (C-a), `PieceJustificative` (CH1), `ModeleChargeRecurrente` (CH3, s'il est retenu), `SalaireMensuel` (CH4), `PartChargeCommune` (CH5) | C-a, CH1, CH3, CH4, CH5 |
| `Feature/Tenancy/IsolationDesSocietesTest::test_chaque_modele_de_societe_est_liste_ici` : `RegimeTvaSociete` (M5a), `ChargeCommune`, `LigneChargeCommune`, `PieceJustificativeSociete` (CH5) | M5a, CH5 |

#### 3.23.8 Écrans

| Lot | Fichier::méthode |
|---|---|
| M4 | `Feature/Filament/PanneauRegimeDeTvaTest::test_une_vente_a_un_particulier_en_france_n_affiche_aucune_question` |
| M4 | `…::test_une_facture_a_un_professionnel_etabli_en_france_n_affiche_que_la_ligne_tva_francaise_et_le_lien` |
| M4 | `…::test_un_client_professionnel_allemand_fait_apparaitre_la_question_de_sortie_des_marchandises` |
| M4 | `…::test_le_lien_expedier_hors_de_france_ouvre_les_questions_pour_un_client_etabli_en_france` |
| M4 | `…::test_le_pays_du_client_seul_ne_propose_jamais_d_exoneration` |
| M4 | `…::test_une_nature_appliquee_d_office_affiche_le_nouveau_total_et_le_lien_rester_a_la_tva_francaise` |
| M4 | `…::test_une_proposition_affiche_le_total_qu_aurait_la_vente` |
| M4 | `…::test_rester_a_la_tva_francaise_est_toujours_possible_et_se_trace` |
| M4 | `…::test_une_exoneration_dont_une_condition_manque_ne_peut_pas_etre_forcee` |
| M4 | `…::test_un_refus_d_emission_dit_tous_les_manquements_identite_comprise` |
| M4 | `…::test_vies_injoignable_et_vies_invalide_affichent_deux_messages_differents` |
| M4 | `…::test_la_question_du_lieu_d_execution_apparait_pour_un_service_au_lieu_fixe_et_un_client_etranger` |
| M4 | `…::test_le_bouton_deplacer_ces_lignes_cree_une_nouvelle_facture` |
| M4 | `…::test_un_devis_exonere_incomplet_s_envoie_avec_un_avertissement` |
| M4 | `Feature/Filament/AvoirResourceTest::test_l_ecran_d_avoir_grise_les_factures_incompatibles` |
| M4 | `Feature/Filament/CaisseTvaTest::test_la_caisse_n_affiche_aucune_question_pour_un_ticket` |
| M4 | `Feature/Filament/CaisseTvaTest::test_la_caisse_avertit_pour_des_services_a_un_professionnel_etabli_hors_de_france` |
| M4 | `Feature/Filament/ProduitResourceTest::test_la_fiche_produit_refuse_le_taux_zero_pour_une_societe_redevable` |
| M4 | `Feature/Filament/ProduitResourceTest::test_la_particularite_fiscale_se_choisit_sur_la_fiche` |
| M4 | `Feature/Filament/ClientResourceTest::test_la_section_fiscalite_propose_organisme_public_d_apres_le_repertoire_sirene` |
| M4 | `Feature/Filament/AucunCodeTechniqueTest::test_aucun_code_technique_n_apparait_dans_les_pages` |
| M5b | `Feature/Filament/FournisseurResourceTest::test_la_fiche_fournisseur_coche_etabli_en_france_seulement_pour_un_pays_en_france` |
| M5b | `Feature/Filament/FournisseurResourceTest::test_l_avertissement_sans_siret_s_affiche_sur_la_fiche_du_fournisseur` |
| M6 | `Feature/Filament/FactureAchatEcransTvaTest::test_un_achat_a_un_fournisseur_francais_demande_les_memes_champs_qu_aujourd_hui` (la nature de la pièce, préremplie « facture » pour un fournisseur qui a un SIRET, reste une ligne repliée) |
| M6 | `…::test_la_nature_de_la_piece_se_demande_sans_reponse_pour_un_fournisseur_sans_siret` |
| M6 | `…::test_une_assurance_d_un_assureur_etranger_pose_la_question_de_l_exoneration` |
| M6 | `…::test_le_taux_facture_suit_le_taux_de_vente_tant_qu_on_ne_le_change_pas` |
| M6 | `…::test_l_origine_des_marchandises_s_affiche_des_qu_il_y_a_un_produit_et_vaut_france_pour_un_fournisseur_francais` |
| M6 | `…::test_un_achat_a_un_fournisseur_allemand_demande_l_origine_preremplie_depuis_sa_derniere_facture_validee` |
| M6 | `…::test_hors_ue_ouvre_la_question_du_dedouanement_quel_que_soit_le_fournisseur` |
| M6 | `…::test_une_ligne_a_zero_d_un_fournisseur_francais_ouvre_la_question_pourquoi` |
| M6 | `…::test_un_fournisseur_non_etabli_ouvre_la_question_de_la_tva_portee_par_la_facture` |
| M6 | `…::test_un_achat_ordinaire_en_france_garde_une_seule_ligne_de_totaux` |
| M6 | `…::test_une_acquisition_affiche_la_tva_a_payer_a_l_etat_et_le_cout_d_entree` |
| M6 | `…::test_l_avertissement_sans_siret_ne_s_affiche_jamais_sur_la_facture` |
| M6 | `…::test_la_liste_tva_etrangere_a_regulariser_compte_les_factures` |
| M6 | `…::test_une_ligne_de_charge_se_saisit_avec_sa_categorie_sans_salaires` |
| M7 | `Feature/Filament/FactureAchatEcransTvaTest::test_le_champ_rattacher_a_ne_propose_que_les_factures_validees_du_magasin` |
| M7 | `…::test_la_liste_dedouanement_a_completer_compte_les_factures_et_notifie_les_valideurs` |
| M8 | `Feature/Filament/PanneauRegimeDeTvaTest::test_un_vendeur_ne_voit_que_l_etat_du_seuil_des_ventes_a_distance` |
| M8 | `…::test_seuil_non_atteint_est_une_information_absente_de_la_fenetre_d_emission` |
| CH1 à CH5 | Tests d'écran des lots (section 5). |

---

## 4. Espace « Charges du magasin » : conception détaillée

### 4.1 Ce qu'est une charge

- Une **charge** est une `FactureAchat` dont `type_document` vaut `charges` et dont toutes les lignes sont de type `charge`. Il n'existe qu'un modèle pour toutes les factures de fournisseurs : même table, même numérotation interne (série `FA` du magasin, `FA-LYON1-2026-000001`), même moteur, même validation (`ValiderFactureAchatAction`, jeton de la validation d'achat), même modification (`ModifierFactureAchatAction`), même journal d'audit.
- Une **ligne de charge** porte une catégorie (4.2), une nature (bien ou service, proposée par la catégorie, modifiable), et, pour Loyer, Entretien, Transport et Autres charges, une précision (4.3). Elle n'entre jamais en stock ni dans le coût des produits. Elle peut figurer sur une facture de marchandises (par exemple des frais de dossier facturés avec des produits) : elle compte alors dans les totaux des charges par sa catégorie, et la facture exige la date de la pièce (`charge.date_piece_absente`), pour que la ligne appartienne à un mois des totaux (4.11).
- Une charge se saisit **depuis le HT de chaque ligne et son taux**, comme tout achat, avec la date de la pièce (obligatoire pour valider), si utile la période couverte (`periode_du`, `periode_au`), et la **nature de la pièce** : facture au nom de la société ou du magasin, ou ticket ou reçu (3.17.1). Un ticket ou un reçu n'ouvre aucune déduction (art. 271 II du CGI).
- Une charge ne se valide qu'avec au moins une **pièce justificative** (4.6) ; elle ne porte aucune ligne de produit ni de frais d'acheminement.
- Une charge validée ne se modifie que par une **correction tracée**, avec l'autorisation de valider les charges : la décision est recalculée et remplacée, l'ancienne au journal d'audit (`FactureAchatModifiee`).
- Les **salaires** (4.8) et les **charges communes** (4.7) sont des objets à part : les salaires ne sont pas des factures, et une charge commune appartient à la société avant d'être répartie.

### 4.2 Catégories

Énumération `CategorieCharge` (domaine `Tva`, valeurs en base sur 30 caractères).

| Valeur | Libellé | Nature proposée | Précision | Compte proposé pour l'étape 5 (à valider par l'expert-comptable) |
|---|---|---|---|---|
| `loyer` | Loyer | Service | Obligatoire | 6132 (charges locatives : 614 ; location de matériel : 6135) |
| `electricite_eau` | Électricité et eau | Bien | — | 6061 |
| `salaires` | Salaires | — (jamais sur une ligne de facture : totaux mensuels, 4.8) | — | 641 et 645 |
| `assurances` | Assurances | Service | — | 616 |
| `telephone_internet` | Téléphone et Internet | Service | — | 626 |
| `logiciels` | Logiciels | Service | — | 651 (ou 6135) |
| `entretien` | Entretien | Service (bien pour des pièces) | Obligatoire | 615 |
| `frais_bancaires` | Frais bancaires | Service | — | 627 |
| `transport` | Transport | Service | Obligatoire | 624 (déplacements : 6251 ; carburant : 6061) |
| `autres_charges` | Autres charges | Service | Obligatoire | 6068 ou 628 (cadeaux : 6234) |

Le libellé, la nature proposée, les précisions admises et le compte proposé sont des données de `TableDesCharges`, dans les règles datées du moteur ; la liste des catégories d'une ligne de facture exclut « Salaires ».

### 4.3 TVA déductible : règles par catégorie et par précision

Énumération `PrecisionDeCharge` : `local_professionnel`, `locaux_et_materiel`, `livraison_marchandises`, `transport_de_personnes`, `carburant_vehicule_utilitaire`, `gazole_essence_e85_vehicule_de_tourisme`, `electricite_gnv_vehicule_de_tourisme`, `vehicule_de_tourisme`, `cadeau_jusqu_au_seuil`, `cadeau_au_dela_du_seuil`, `logement_dirigeants_personnel`, `autre`. Précisions admises : Loyer (`local_professionnel`, `logement_dirigeants_personnel`, `vehicule_de_tourisme`, `autre`) ; Entretien (`locaux_et_materiel`, `vehicule_de_tourisme`) ; Transport (`livraison_marchandises`, `transport_de_personnes`, `carburant_vehicule_utilitaire`, `gazole_essence_e85_vehicule_de_tourisme`, `electricite_gnv_vehicule_de_tourisme`, `vehicule_de_tourisme`, `autre`) ; Autres charges (`cadeau_jusqu_au_seuil`, `cadeau_au_dela_du_seuil`, `logement_dirigeants_personnel`, `autre`). Une précision qui n'est pas admise pour sa catégorie est refusée (`charge.precision_absente`).

Le pourcentage s'applique à la TVA facturée française et à la TVA autoliquidée de la ligne (3.17.3). Il vaut toujours 0 pour une société en franchise, et pour toutes les lignes d'une pièce qui n'est pas une facture au nom de la société ou du magasin (raison « Ticket ou reçu sans facture : TVA non déductible ; demandez une facture au fournisseur (art. 271 II du CGI) »). Chaque règle porte sa raison (texte affiché) et l'indicateur « à confirmer par l'expert-comptable de la société » ; une règle à confirmer s'affiche comme telle (`charge.regle_a_confirmer`) et sa confirmation se fera par une nouvelle version des règles.

| Catégorie | Précision | Pourcentage | Raison affichée (source) | À confirmer |
|---|---|---|---|---|
| Loyer | `local_professionnel` | 100 % de la TVA facturée | « Location de locaux nus exonérée, sauf option du bailleur : la TVA facturée est déductible » (art. 261 D 2° et 260 2° du CGI) ; à 0 %, raison « exonérée » posée d'office | Non |
| Loyer | `logement_dirigeants_personnel` | 0 % | « Logement des dirigeants et du personnel : TVA non déductible (art. 206 IV-2-2° de l'annexe II au CGI) ; sauf logement sur place du personnel de gardiennage, de sécurité ou de surveillance, ou logement refacturé : choisissez « Autre » » | Oui (exceptions) |
| Loyer | `vehicule_de_tourisme` | 0 % | « Location d'un véhicule de tourisme : TVA non déductible (art. 206 IV-2-6° de l'annexe II au CGI) » | Non |
| Loyer | `autre` | 100 % de la TVA facturée | « Autre location pour l'activité (matériel, terminal de paiement…) » | Oui |
| Électricité et eau | — | 100 % | « Énergie et eau pour l'activité » | Non |
| Assurances | — | 0 % | « Prime d'assurance exonérée » (art. 261 C 2° du CGI) ; une TVA facturée > 0 donne l'avertissement `charge.tva_inattendue` ; d'un assureur non établi en France : exonérée, jamais autoliquidée (3.17.2) | Non |
| Téléphone et Internet | — | 100 % | « Abonnement pour l'activité » | Non |
| Logiciels | — | 100 % | « Logiciel pour l'activité » ; d'un éditeur non établi : autoliquidation (3.17.2) | Non |
| Entretien | `locaux_et_materiel` | 100 % | « Entretien des locaux, du matériel ou d'un véhicule utilitaire » | Non |
| Entretien | `vehicule_de_tourisme` | 0 % | « Entretien ou réparation d'un véhicule de tourisme : TVA non déductible (art. 206 IV-2-6° et 10° de l'annexe II au CGI) » | Non |
| Frais bancaires | — | 100 % de la TVA facturée | « Opérations bancaires le plus souvent exonérées (art. 261 C 1° du CGI) ; une TVA facturée est déductible » ; à 0 %, raison « exonérée » posée d'office ; d'un prestataire non établi : exonérées, jamais autoliquidées, sauf réponse « Non » à la question de l'exonération (3.17.2) | Non |
| Transport | `livraison_marchandises` | 100 % | « Transport de marchandises » | Non |
| Transport | `transport_de_personnes` | 0 % | « Transport de personnes : TVA non déductible (art. 206 IV-2-5° de l'annexe II au CGI) ; sauf transport collectif du personnel vers les lieux de travail sous contrat permanent : choisissez « Autre » » | Non |
| Transport | `carburant_vehicule_utilitaire` | 100 % | « Carburant d'un véhicule utilitaire » (art. 298-4-1° du CGI) | Non |
| Transport | `gazole_essence_e85_vehicule_de_tourisme` | 80 % | « Gazole, essence ou superéthanol E85 d'un véhicule de tourisme : 80 % déductible (art. 298-4-1° du CGI) » ; arrondi selon 6.1 d | Non |
| Transport | `electricite_gnv_vehicule_de_tourisme` | 100 % | « Électricité ou GNV d'un véhicule de tourisme : entièrement déductible » | Oui (électricité, admise par une réponse ministérielle) |
| Transport | `vehicule_de_tourisme` | 0 % | « Achat, location ou entretien d'un véhicule de tourisme, péages et parkings compris : TVA non déductible » (art. 206 IV-2-6° de l'annexe II au CGI ; services liés : 10°) | Oui (péages et parkings) |
| Transport | `autre` | 100 % | « Autre dépense de transport pour l'activité » (GPL compris, à confirmer) | Oui |
| Autres charges | `cadeau_jusqu_au_seuil` | 100 % | « Cadeau de 73 € TTC au plus par bénéficiaire et par an, tous ses cadeaux de l'année et leurs frais d'emballage et d'envoi compris : à vérifier par vous » (art. 206 IV-2-3° de l'annexe II et art. 28-00 A de l'annexe IV au CGI) ; la case « Le total des cadeaux à ce bénéficiaire cette année, frais compris, reste sous le seuil » est exigée (`charge.cadeau_seuil_a_confirmer`) ; une ligne dont le TTC unitaire dépasse 73 € reste refusée, contrôle minimal (`charge.cadeau_au_dela_du_seuil`) | Oui (montant du seuil en 2026) |
| Autres charges | `cadeau_au_dela_du_seuil` | 0 % | « Cadeau de plus de 73 € TTC par bénéficiaire et par an : TVA non déductible » | Oui (montant du seuil en 2026) |
| Autres charges | `logement_dirigeants_personnel` | 0 % | « Logement ou hébergement des dirigeants et du personnel, nuits d'hôtel en déplacement comprises : TVA non déductible (art. 206 IV-2-2° de l'annexe II au CGI) ; sauf logement sur place du personnel de gardiennage, de sécurité ou de surveillance, ou logement refacturé : choisissez « Autre » » ; les repas facturés à part relèvent de « Autre » | Oui (exceptions) |
| Autres charges | `autre` | 100 % | « Dépense pour l'activité » (restaurant compris) | Oui |

- TVA déductible d'une ligne dont le pourcentage n'est ni 0 ni 100 % (le carburant à 80 %, aujourd'hui seul cas) : selon 6.1 d (recommandation : arrondi(TVA × pourcentage), un seul arrondi au centime, la moitié arrondie en s'éloignant de zéro). Le lot M5c attend la réponse.
- Le seuil des cadeaux (73 € TTC) est une valeur de la table datée des seuils ; PILIQI ne connaît ni le bénéficiaire ni ses autres cadeaux : la case engage la personne qui valide, et la raison le dit.
- **Droit à déduction total supposé.** Le moteur suppose que la société n'exerce que des opérations ouvrant droit à déduction. Une société qui déclare, dans ses paramètres fiscaux, réaliser aussi des opérations exonérées (par exemple la sous-location nue d'une partie de son local, art. 261 D 2° du CGI) ou hors du champ de la TVA (`societes.operations_hors_droit_a_deduction`, lot CH2) voit chaque ligne de charge et chaque charge commune recevoir l'indicateur « à confirmer », avec l'information `charge.deduction_a_proratiser` (« Droit à déduction à proratiser selon le coefficient de votre société : à voir avec votre expert-comptable. ») ; aucun coefficient n'est calculé (art. 205 et 206 I à III de l'annexe II au CGI ; 6.2, EC19).
- **Date de déduction.** À l'étape 5, la date de déduction d'un service acheté en France suivra son paiement, sauf option du fournisseur pour les débits (énergie : fin de la période facturée, à confirmer) ; d'ici là, les totaux donnent le droit à déduction ouvert par les pièces (4.11), jamais le montant à porter tel quel sur une déclaration.

### 4.4 Écrans

**Panneau du magasin, menu « Charges du magasin »** (visible avec `charge.view`) :
- **Charges** (`ChargeResource`, liste des `FactureAchat` de type `charges`) : colonnes n° interne, fournisseur, date de la pièce, période, catégories, HT, TVA déductible, TTC, statut, nombre de pièces ; filtres mois, catégorie, statut, fournisseur ; badge « Charges à valider » (brouillons).
- **Formulaire d'une charge** : fournisseur (fiches du magasin ; création rapide avec `fournisseur.create`), n° de la pièce, date de la pièce, période couverte, nature de la pièce (3.18), devise et taux de change (lot M5e) ; lignes (description, catégorie sans « Salaires », nature proposée, précision pour Loyer, Entretien, Transport et Autres charges, case du cumul des cadeaux pour la précision « cadeau jusqu'au seuil », quantité à 1 par défaut, montant HT unitaire, taux facturé, raison d'un taux zéro, question de l'exonération pour une assurance ou des frais bancaires d'un prestataire non établi, case « lieu fixé par la loi » pour un fournisseur non établi) ; total de contrôle facultatif ; pièces justificatives (dépôt, liste, téléchargement, retrait sur un brouillon, remplacement d'une pièce déposée par erreur, avec un motif), avec sous le champ de dépôt le texte de 4.6 ; panneau « TVA de cette charge » (aperçu du moteur : TVA facturée, TVA déductible et sa raison par ligne, totaux, « règle à confirmer ») ; notes. Boutons « Enregistrer », « Valider » (jeton), et sur une charge validée « Corriger » (avec `charge.valider`).
- **Charges récurrentes** (lot CH3, seulement si l'éditeur le retient, 6.1 f).
- **Parts des charges communes** (lot CH5, `PartChargeCommuneResource`, lecture seule), avec, pour qui peut le voir, le détail de la charge commune et sa pièce (4.7).
- **Salaires** (lot CH4, lecture seule, visible du propriétaire, des administrateurs autorisés et des experts-comptables seulement).
- **Totaux** (4.11).

**Espace de la société** : **Charges communes** (lot CH5, `societe.charges.gerer`) ; **Salaires du mois** (lot CH4, `societe.salaires.gerer`). Cet espace reste fermé aux experts-comptables (lot A4) : ils lisent les parts, le détail des charges communes et les salaires depuis le panneau des magasins.

Aucun code technique à l'écran : les catégories et précisions s'affichent par leur libellé (« Gazole, essence ou E85 d'un véhicule de tourisme »), jamais par leur valeur.

### 4.5 Validation, correction, jetons et journal d'audit

- Validation d'une charge : `ValiderFactureAchatAction` (jeton de la validation d'achat, R3), qui contrôle en plus, pour le type `charges` : au moins une pièce ni retirée ni remplacée (`charge.piece_absente`), aucune ligne de produit ni de frais (`charge.ligne_de_stock_interdite`), une période cohérente (`charge.periode_incoherente`), la précision des catégories qui l'exigent (`charge.precision_absente`), le seuil des cadeaux (`charge.cadeau_au_dela_du_seuil`, `charge.cadeau_seuil_a_confirmer`). Pour toute facture qui porte une ligne de charge, charges et marchandises, la date de la pièce (`charge.date_piece_absente`). Pour toute facture d'achat, la nature de la pièce (`achat.nature_piece_inconnue`, contrôle du moteur). Tous les manquements, ceux du moteur compris, sont dits d'un coup.
- Le droit vérifié dépend du type : `charge.valider` pour une charge, `facture_achat.valider` pour des marchandises (une facture de marchandises qui porte des lignes de charge reste une facture de marchandises).
- Validation et correction inscrivent `FactureAchatValidee` et `FactureAchatModifiee` (avec le type de document) ; une saisie manuelle de la TVA d'une charge suit 3.17.9.
- Une charge validée dans un magasin en lecture seule : refusée comme tout achat (`Magasin::verifierQuIlEstOuvert()`), sauf décision contraire de l'éditeur (6.1 a).

### 4.6 Pièces justificatives (lot CH1, étendues aux lots CH4 et CH5)

**Ce qu'est une pièce déposée.** Une copie de consultation. La société conserve l'original papier, ou la facture électronique reçue sous sa forme électronique, pendant 10 ans (art. L123-22 du Code de commerce ; 6 ans au titre de l'art. L102 B du LPF, les documents reçus sous forme électronique se conservant sous cette forme). PILIQI ne garde qu'une empreinte SHA-256 interne : ce n'est pas une numérisation fiscale (art. A102 B-2 du LPF : copie fidèle en PDF ou PDF/A, cachet serveur ou signature électronique, organisation documentée), qui seule permettrait de détruire l'original ; elle n'est mise en place que si l'éditeur la retient (6.1 g). Sous chaque champ de dépôt : « Copie de consultation : conservez l'original, papier ou facture électronique reçue, pendant 10 ans. » La notice le dit avec ses références.

**Table `pieces_justificatives`** (`BelongsToMagasin`, `FigeLeNomDeSesAuteurs`) :
- `id`, `magasin_id` (clé, `restrictOnDelete`) ;
- `facture_achat_id` (clé vers `factures_achat`, `restrictOnDelete` ; non nulle au lot CH1) ; au lot CH4, `salaire_mensuel_id` (clé nullable) s'ajoute dans la même migration de création, `facture_achat_id` devient nullable, et « exactement l'un des deux » est garanti par une contrainte CHECK sous PostgreSQL et des déclencheurs BEFORE INSERT et BEFORE UPDATE sous SQLite ;
- `nom_fichier` (string(255) : nom d'origine, réduit à son nom de base, caractères de contrôle retirés), `type_mime` (string(50) : `application/pdf`, `image/jpeg`, `image/png` ou `application/xml`, lu sur le contenu), `taille` (entier, octets), `empreinte` (string(64), SHA-256 du contenu en clair), `chemin` (string(255) nullable : `magasin-{id}/{32 caractères hexadécimaux}.chiffre` sur le disque `justificatifs`) ;
- `ajoutee_par` (clé nullable vers `users`), `ajoutee_par_nom`, `ajoutee_le` ; `retiree_le` (nullable), `retiree_par` (clé nullable), `retiree_par_nom` ; `remplacement_le` (nullable), `remplacement_par` (clé nullable vers `users`), `remplacement_par_nom`, `remplacement_motif` (string(30) nullable, valeur de `MotifDeRemplacementDePiece` : `mauvais_fichier`, `document_d_un_tiers`, `paie_detaillee_par_salarie`, `autre` ; aucun texte libre, pour qu'aucune donnée personnelle n'entre au journal d'audit), `remplacante_id` (clé nullable vers `pieces_justificatives`) ;
- index unique partiel (`facture_achat_id`, `empreinte`) où `retiree_le` et `remplacement_le` sont nuls (au lot CH4, de même pour `salaire_mensuel_id`) ; index (`magasin_id`, `facture_achat_id`) ;
- `DeclencheursDeProtection::sansSuppression()` : aucune ligne ne s'efface en base.

**Disque** `justificatifs` dans `config/filesystems.php` : `local`, racine `storage_path('app/private/justificatifs')`, `visibility` `private`, `serve` faux, `throw` vrai, `report` faux (comme `verification`).

**Classe `PiecesJustificatives`** (`app/Domain/Achats/Services`, sur le modèle de `PiecesDeVerification`) : `ranger(Magasin, string $contenu): string` (chiffré par `Crypt::encryptString`), `dechiffrer(PieceJustificative): string`, `effacerLeFichier(...)` (efface le fichier après la validation de la transaction, `DB::afterCommit`, et met `chemin` à nul ; la ligne reste), utilisée par le retrait et le remplacement.

**Types admis**, lus sur le contenu, jamais sur l'extension : PDF (un Factur-X est un PDF, gardé tel quel avec son XML incorporé), JPEG, PNG, et XML d'une facture électronique : `finfo` reconnaît du XML, et l'élément racine est une facture ou un avoir UBL (`Invoice`, `CreditNote`, espaces de noms OASIS) ou une facture CII (`CrossIndustryInvoice`, espace de noms UN/CEFACT) ; l'analyse refuse toute déclaration DOCTYPE et n'ouvre aucune ressource externe (`LIBXML_NONET`, aucune entité chargée).

**Actions** :
- `AjouterUnePieceJustificativeAction(User $auteur, FactureAchat|SalaireMensuel $document, string $nomFichier, string $contenu)` ;
- `RetirerUnePieceJustificativeAction(User $auteur, PieceJustificative $piece)` : seulement sur un brouillon, une fois ;
- `RemplacerUnePieceJustificativeAction(User $auteur, PieceJustificative $piece, MotifDeRemplacementDePiece $motif, ?string $nomFichier, ?string $contenu)` : pour une pièce déposée par erreur (bulletin de paie, document d'un tiers, mauvais fichier), sur un brouillon comme sur un document validé ou des salaires. Droits de 3.20. Motif obligatoire, choisi dans la liste (`piece.motif_absent`). Dans une transaction, sous le verrou de la ligne du document : l'ancienne pièce reçoit `remplacement_le`, `remplacement_par`, `remplacement_par_nom`, `remplacement_motif` et `remplacante_id` (la nouvelle pièce, quand un contenu est donné, ajoutée avec les contrôles d'un ajout) ; son fichier s'efface après la validation de la transaction et `chemin` passe à nul ; sa ligne garde l'empreinte, le type, la taille et l'auteur. Un document validé, ou des salaires, gardent toujours au moins une pièce ni retirée ni remplacée (`piece.derniere_piece` : il faut alors donner la pièce qui remplace) ; une pièce déjà remplacée ne se remplace plus (`piece.deja_remplacee`). Audit `PieceJustificativeRemplacee` (document, ancienne et nouvelle empreinte, motif ; jamais le contenu ni le nom du fichier).

Contrôles côté serveur, quel que soit l'écran : contenu non vide (`piece.vide`) et de 10 Mo au plus (`piece.trop_grosse`, constante `TAILLE_MAXIMALE_EN_OCTETS = 10 * 1024 * 1024`) ; type admis (`piece.type_refuse`, même si l'extension dit `.pdf`) ; 20 pièces ni retirées ni remplacées au plus par document (`piece.trop_de_pieces`), comptées après le verrou de la ligne du document (`lockForUpdate`), pour que deux dépôts simultanés ne dépassent jamais ce nombre ; même contenu déjà joint (`piece.deja_jointe`) ; retrait seulement sur un brouillon, une fois (`piece.retrait_d_un_document_valide`, `piece.deja_retiree`) ; jamais de retrait pour des salaires (aucun brouillon), seulement un remplacement. Le fichier rangé s'efface si l'écriture de la ligne échoue.

**Écran** : `FileUpload::make('fichier')->storeFiles(false)->maxSize(10240)->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'application/xml', 'text/xml'])` (le contrôle de l'écran n'est qu'un confort : l'action contrôle tout), avec le texte de la copie de consultation ; pour des salaires, en plus : « Ne déposez ni bulletin ni journal de paie détaillé par salarié : seulement les totaux du magasin. » ; liste des pièces avec « Télécharger », sur un brouillon « Retirer » (confirmation), et « Remplacer une pièce déposée par erreur » (motif, nouveau fichier, confirmation) pour qui en a le droit.

**Téléchargement** : une action Filament de la fiche, contrôlée par la policy du document (droit de le voir) ; réponse `streamDownload` du contenu déchiffré, `Content-Type` enregistré, `Content-Disposition: attachment` avec le nom réduit, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`. Une pièce retirée ou remplacée ne se télécharge plus.

**Journal d'audit** (catégorie « Opérations », 10 ans) : `PieceJustificativeAjoutee` (type et identifiant du document, empreinte, type, taille ; jamais le contenu ni le nom du fichier), `PieceJustificativeRetiree` (empreinte), `PieceJustificativeRemplacee` (ancienne et nouvelle empreinte, motif).

**Conservation** : 10 ans, sans purge, sauf le fichier d'une pièce remplacée pour erreur, effacé aussitôt (RGPD, art. 5-1-c et 5-1-d : minimisation et exactitude ; art. 17), sa trace restant au journal d'audit. Les pièces restent lisibles après une rotation de la clé de l'application tant que l'ancienne clé figure dans `APP_PREVIOUS_KEYS` ; la sauvegarde de la clé relève de l'étape 15 (sans elle, les pièces sont perdues : point de relecture du lot CH1).

**Société** (lot CH5) : table `pieces_justificatives_societe` (`BelongsToSociete`), mêmes colonnes avec `societe_id` et `charge_commune_id`, chemin `societe-{id}/…` sur le même disque, mêmes contrôles, même remplacement et mêmes inscriptions au journal d'audit.

### 4.7 Charges communes (lot CH5)

**Données.**
- `charges_communes` (`BelongsToSociete`, `FigeLeNomDeSesAuteurs`) : `societe_id` ; organisme (`organisme_nom`, `organisme_pays` (2), `organisme_etabli_en_france`, `organisme_numero_tva` nullable, `organisme_siret` nullable, `organisme_ne_facture_pas_de_tva`) ; `numero_piece` (100), `date_piece`, `nature_piece` (20, valeur de `NatureDeLaPiece`), `periode_du`, `periode_au` ; `devise` (EUR par défaut), `taux_change`, `taux_change_source` ; `territoire` (40, valeur de `TerritoireDeTva`, écrit à la validation) ; `mode_repartition` (`parts_egales`, `pourcentages`) ; `repartition` (json : liste de `{magasin_id, pourcentage}` en centièmes de point, par identifiant croissant) ; `statut` (`brouillon`, `validee`) ; `total_ht`, `total_tva`, `total_ttc` (decimal(12,2)), `total_de_controle` (nullable) ; `decision_tva` (json nullable) ; `empreinte_du_contenu` (string(64) nullable : SHA-256 du contenu validé ou corrigé, lignes, répartition et empreintes des pièces, en ordre canonique) ; `cree_par`, `cree_par_nom`, `validee_le`, `validee_par`, `validee_par_nom`, `corrigee_le`, `corrigee_par`, `corrigee_par_nom` ; horodatage.
- `lignes_charges_communes` (`BelongsToSociete`) : `charge_commune_id`, `ordre`, `description`, `categorie_charge`, `nature_charge`, `precision_charge`, `seuil_cadeaux_confirme`, `quantite`, `prix_unitaire_ht`, `taux_tva`, `raison_taux_zero`, `lieu_fixe_par_la_loi`, `montant_ht`, `montant_tva`, `montant_tva_autoliquidee`, `montant_tva_deductible`, `montant_ttc`.
- `parts_charges_communes` (`BelongsToMagasin`) : `magasin_id`, `charge_commune_id`, `ligne_charge_commune_id`, `pourcentage`, `montant_ht`, `montant_tva`, `montant_tva_autoliquidee`, `montant_tva_deductible`, `montant_ttc`, `created_at` ; unique (`ligne_charge_commune_id`, `magasin_id`).
- `pieces_justificatives_societe` (4.6).

**Règles décidées** (décision de l'éditeur et règles existantes de `CLAUDE.md`).
- Saisie dans l'espace de la société par le propriétaire ou un administrateur qui a `societe.charges.gerer`, pour des magasins **réels** de la société, **tous dans sa portée** et **ouverts** (aucun en lecture seule, règle actuelle de « Société ou magasin suspendu… », sauf réponse 1 à 6.1 a) ; au moins deux magasins ; parts égales, ou pourcentages strictement positifs dont la somme fait 100,00 % (`charge_commune.repartition_invalide`, `charge_commune.magasin_hors_portee`, `charge_commune.magasin_ferme`).
- Une charge commune se calcule par le même moteur (`CollecteurFaitsDAchat::pourUneChargeCommune`, organisme lu par `CollecteurFaitsDuTiers::deLOrganisme`, société lue à la date de la pièce et au territoire des magasins par `CollecteurFaitsDeLaSociete::pourUneChargeCommune`) : nature de la pièce, règles de récupération de 4.3, prime d'assurance ou opération bancaire d'un organisme non établi exonérée et jamais autoliquidée (3.17.2), indicateur « à confirmer » d'une société qui déclare des opérations hors droit à déduction ; aucune consultation VIES au niveau de la société (avertissement `tva.vies_fournisseur_invalide`).
- **Répartition** : calculée par le moteur (`MoteurTva::repartirUneChargeCommune`, 3.5) : pour chaque ligne, chacun de ses montants (HT, TVA facturée, TVA autoliquidée, TVA déductible) se répartit entre les magasins pris par identifiant croissant, au prorata des pourcentages (parts égales : poids égaux), selon la réponse à 6.1 d (recommandation : par cumul, chaque magasin recevant l'arrondi au centime du cumul jusqu'à lui, moins ce qu'ont reçu les précédents, le dernier recevant le reste). Le TTC d'une part est son HT plus sa TVA facturée. La somme des parts égale exactement chaque montant de la ligne ; avec un pourcentage de récupération de 100 %, la TVA déductible d'une part est exactement sa TVA. `RepartitionDesChargesCommunes` (seule classe qui écrit et relit les parts hors du magasin courant) écrit les parts rendues par le moteur, sans aucun calcul.
- **Ordre des verrous** (validation et correction ; 0.3, point 5) : dans une transaction, les registres des magasins de la répartition (anciens et nouveaux pour une correction), par identifiant croissant, par `RegistreFiscal::sousVerrouDesMagasins()` ; puis la ligne de l'auteur, par `LecteurDesDroits::exigerDansLaSocieteSousVerrou()` (lot CH4, `FOR NO KEY UPDATE`) ; puis la ligne de la charge commune (`lockForUpdate`) ; puis, relus sous ces verrous, le statut, les magasins (réels, ouverts) et la portée de l'auteur. Une fermeture de magasin (lot A5, qui écrit au registre) ou un retrait de droits (`JournalDesDroits`, qui verrouille les registres touchés puis les comptes) attend donc la fin de la validation, ou la fait refuser.
- **Validation** (`ValiderChargeCommuneAction(User $auteur, ChargeCommune $charge, string $empreinteDuContenu)`) : la demande porte l'empreinte du contenu affiché (lignes, répartition, pièces) ; un brouillon dont le contenu a changé depuis est refusé (`charge_commune.brouillon_change`) ; au moins une pièce (`charge_commune.piece_absente`) ; décision du moteur (refus avec tous les manquements) ; parts écrites dans chaque magasin par `TenantContext::runAs($magasin, …)`, par identifiant croissant ; statut, auteur, instant, `decision_tva`, `empreinte_du_contenu` ; audit `ChargeCommuneValidee` (magasin nul ; nouvelles valeurs : organisme, n° de pièce, totaux, répartition). **Demande répétée** : la charge, trouvée déjà validée par le même auteur avec la même empreinte, est rendue comme résultat, sans rien réécrire ni inscrire ; validée par un autre ou avec un autre contenu : `charge_commune.deja_validee`. Aucun jeton : ceux du lot A6 sont rangés par magasin (`ActionTraitee`, clé (`magasin_id`, `jeton`)) ; la protection est l'état, l'empreinte et le verrou de la ligne (2.10).
- **Correction** (`CorrigerChargeCommuneAction`) : mêmes droits, mêmes verrous et mêmes contrôles, sur les anciens et les nouveaux magasins ; les parts de la charge sont supprimées puis réécrites ; audit `ChargeCommuneCorrigee` avec les anciennes lignes, l'ancienne répartition et les anciennes parts. Une correction répétée dont le contenu est déjà celui de la charge ne réécrit ni n'inscrit rien et rend la charge telle qu'elle est.
- **Visibilité** : chaque magasin voit ses parts (avec `charge.view`), montants seulement. Le détail de la charge commune (organisme, lignes, magasins de la répartition et leurs parts) et sa pièce se lisent en lecture seule depuis une part, dans le panneau du magasin (`PartChargeCommuneResource`, page « Détail de la charge commune »), par le propriétaire, les experts-comptables et un administrateur dont `societe.charges.gerer` couvre tous les magasins de la répartition (`DetailDeChargeCommunePolicy`) ; la lecture passe par `RepartitionDesChargesCommunes::detailPour(PartChargeCommune $part, User $lecteur)`, qui ne lit que la charge commune de cette part, filtrée par la société du magasin, et ses seuls champs affichés (lecture de la puce 2 de « Produit », 2.2). Un responsable, un vendeur ou un observateur ne voit que la part. Dans l'espace de la société, le propriétaire et ces administrateurs voient la charge entière ; cet espace reste fermé aux experts-comptables (lot A4, tests inchangés).

**Restrictions provisoires, non décidées par l'éditeur (6.1 e).** Appliquées en attendant sa réponse, jamais écrites dans `CLAUDE.md` comme des règles, chacune avec son message :
- magasins d'un même territoire de TVA (`charge_commune.territoires_differents`) : le moteur décide une charge pour un seul territoire (taux normal d'une autoliquidation, contrôle des taux), et le taux d'une autoliquidation partagée entre la métropole et un DOM reste à confirmer (6.1 e1) ;
- un organisme non établi en France ne facture qu'un service (`charge_commune.bien_etranger_non_gere`) : l'origine d'un bien acheté hors de France se confirme dans un magasin (TVA3) ;
- aucune saisie manuelle de la TVA au niveau de la société : un cas que le moteur ne calcule pas seul se saisit dans un magasin (`charge_commune.cas_exclu`).
Si la réponse est connue au début du lot CH5, il l'applique : une restriction gardée s'écrit alors dans `CLAUDE.md` (2.12, « Soumis à 6.1 ») ; une restriction levée est remplacée par la règle que 6.1 e décrit. Sinon les restrictions provisoires s'appliquent, et une réponse qui en lève une donne un lot d'amendement.

### 4.8 Salaires (lot CH4)

- Table `salaires_mensuels` (`BelongsToMagasin`, `FigeLeNomDeSesAuteurs`) : `magasin_id`, `mois` (date, premier jour du mois), `salaires_bruts`, `cotisations_patronales` (decimal(12,2), positifs ou nuls), `saisi_par`, `saisi_par_nom`, horodatage ; unique (`magasin_id`, `mois`). Aucun état, aucun bulletin individuel.
- **Pièce** : récapitulatif totalisé de l'établissement (total des salaires bruts et des cotisations patronales du mois, sans détail par salarié), jamais un bulletin ni un journal de paie détaillé ; le texte sous le dépôt le dit (4.6). Une pièce déposée par erreur se remplace, avec un motif (4.6).
- Saisie dans l'espace de la société, page « Salaires du mois » : un mois choisi, une ligne par magasin réel de la portée de la personne (salaires bruts, cotisations patronales, total, récapitulatif totalisé), bouton « Enregistrer » par ligne. Écriture par `SalairesDeLaSociete::enregistrer(User $auteur, Magasin $magasin, CarbonImmutable $mois, int $brutsEnCentimes, int $cotisationsEnCentimes, ?array $piece)` sous `TenantContext::runAs`, seule classe qui écrit et relit les salaires hors du magasin courant.
- **Ordre des verrous** : dans une transaction, le registre du magasin (`RegistreFiscal::sousVerrouDesMagasins()`), puis la ligne de l'auteur par `LecteurDesDroits::exigerDansLaSocieteSousVerrou()`, créée par ce lot (comme `exigerDansLaSociete()`, la ligne `users` relue en `lock('for no key update')`), puis la ligne des salaires du mois si elle existe (`lockForUpdate`) ; contrôles relus sous ces verrous.
- Contrôles : `societe.salaires.gerer` et magasin dans la portée (`salaires.magasin_hors_portee`) ; pièce exigée à la première saisie du mois (`salaires.piece_absente`) ; mois futur refusé (`salaires.mois_futur`) ; montant négatif refusé (`salaires.montant_negatif`) ; magasin en lecture seule refusé (`salaires.magasin_ferme`), sauf décision contraire de l'éditeur (6.1 a).
- **Demande répétée** : les mêmes montants pour le même magasin et le même mois, déjà enregistrés, avec une pièce déjà jointe (même empreinte) ou sans pièce, rendent l'enregistrement tel quel, sans rien réécrire ni inscrire ; des montants différents sont une correction.
- Journal d'audit : `SalairesEnregistres` (magasin, mois, montants), `SalairesCorriges` (anciens et nouveaux montants).
- Panneau du magasin : `SalaireMensuelResource` en lecture seule (mois, bruts, cotisations, total, pièces), visible du propriétaire, d'un administrateur dont `societe.salaires.gerer` couvre ce magasin et des experts-comptables ; jamais d'un responsable, d'un vendeur ni d'un observateur. `SalaireMensuelPolicy` ne passe pas par `PermissionRegistry` : aucune case de magasin n'ouvre les salaires.
- **Données personnelles** : dans un magasin de peu de salariés, le total mensuel est le salaire d'une personne identifiable (RGPD, art. 4-1) : visibilité limitée (4.9), aucune différence qui le révèle dans un résultat (4.11, étape 6), registre des traitements et information des salariés à l'étape 9.

### 4.9 Droits

| Geste | Propriétaire | Administrateur | Responsable du magasin | Vendeur, observateur | Expert-comptable |
|---|---|---|---|---|---|
| Voir les charges du magasin, leurs pièces, leurs totaux (salaires exceptés) | Toujours | `charge.view` (précochée) | Toujours | Si cochée | Toujours (consultation) |
| Saisir des brouillons de charges (et des modèles récurrents, si le lot CH3 est retenu) ; ajouter des pièces ; les retirer d'un brouillon | Toujours | `charge.creer` (précochée) | Toujours | Si cochée | Jamais |
| Valider une charge ; corriger une charge validée | Toujours | `charge.valider` (précochée) | Toujours | Si cochée | Jamais |
| Remplacer une pièce déposée par erreur sur une charge ou une facture d'achat validée | Toujours | `charge.valider` ou `facture_achat.valider` | Toujours | Si l'autorisation de valider est cochée | Jamais |
| Saisir à la main la TVA d'une charge | Toujours | `facture_achat.saisie_manuelle_tva` (jamais précochée) | Toujours | Si cochée | Jamais |
| Voir les parts des charges communes du magasin | Toujours | `charge.view` | Toujours | Si `charge.view` cochée | Toujours |
| Saisir, valider, corriger une charge commune ; remplacer sa pièce | Toujours | `societe.charges.gerer` (jamais précochée), tous les magasins de la répartition dans sa portée | Jamais | Jamais | Jamais |
| Voir le détail d'une charge commune et sa pièce | Toujours | `societe.charges.gerer` couvrant tous les magasins de la répartition | Jamais (sa part seulement) | Jamais (la part seulement, si `charge.view`) | Toujours, depuis le panneau d'un magasin qui en a une part |
| Saisir, corriger les salaires ; remplacer leur pièce | Toujours | `societe.salaires.gerer` (jamais précochée), magasins de sa portée | Jamais | Jamais | Jamais |
| Voir les salaires | Toujours | `societe.salaires.gerer`, magasins de sa portée | Jamais | Jamais | Toujours, depuis le panneau du magasin |

Les autorisations de magasin cochées pour un vendeur ou un observateur le sont par le propriétaire ou un responsable du magasin, jamais au-delà des siennes. Les deux autorisations de société ne se cochent que pour un administrateur, par le propriétaire.

### 4.10 Charges récurrentes (lot CH3, seulement si l'éditeur le retient, 6.1 f)

- Table `modeles_charges_recurrentes` (`BelongsToMagasin`, `FigeLeNomDeSesAuteurs`) : `magasin_id`, `fournisseur_id` (clé, `regleExisteDansLeMagasin()`), `libelle` (100), `jour_du_mois` (1 à 28), `date_debut`, `date_fin` (nullable), `actif`, `lignes` (json : liste de `{description, categorie, nature, precision, quantite, prix_unitaire_ht (centimes), taux (centièmes)}`), `cree_par`, `cree_par_nom`, horodatage.
- `factures_achat.modele_charge_recurrente_id` (clé nullable) ; index unique partiel (`magasin_id`, `modele_charge_recurrente_id`, `periode_du`) où le modèle n'est pas nul ; `SourceFactureAchat::Recurrente` (« Charge récurrente »), modifiable comme une saisie manuelle.
- Commande `app:creer-charges-recurrentes`, planifiée chaque jour vers 05:30 dans `bootstrap/app.php` : pour chaque magasin qui n'est pas en lecture seule, sous `TenantContext::runAs()` et `OperateurCourant::pendantLeSysteme()`, pour chaque modèle actif, pour chaque mois depuis le dernier brouillon créé (ou le mois de `date_debut`) jusqu'au mois courant, dont le jour du modèle est passé et qui précède `date_fin` : un brouillon de charge (type `charges`, période du premier au dernier jour du mois, date de la pièce préremplie au jour du modèle, lignes du modèle), dans l'ordre des mois ; l'index unique empêche tout doublon, même si deux exécutions se croisent (l'exception d'unicité est rattrapée et la création sautée).
- Les modèles se gèrent avec `charge.creer` ; le brouillon se complète (montant réel, pièce, nature de la pièce) et se valide comme toute charge.

### 4.11 Totaux et export

- `TotauxDesCharges::duMois(Magasin $magasin, CarbonImmutable $mois, User $lecteur)` : par catégorie, le HT, la TVA déductible, le TTC et « dont TVA autoliquidée », pour un mois de la date de la pièce : lignes de charge des factures validées (charges et marchandises, toutes datées puisque la date est exigée dès qu'une ligne de charge existe, 4.5), parts des charges communes (date de la pièce de la charge commune), et salaires (brut plus cotisations, sans TVA) seulement si le lecteur peut les voir. Sommes en centimes, côté PHP.
- Page « Totaux » : le mois, une ligne par catégorie, une ligne « Total ». La colonne de TVA s'intitule « TVA déductible (droit ouvert par ces pièces) » ; sous le total, une ligne fixe : « Le mois où cette TVA se récupère dépend de la date de paiement des services (sauf option du fournisseur pour les débits) et de la période facturée pour l'énergie ; il sera calculé à l'étape 5. Ne reportez pas ce total tel quel sur votre déclaration. »
- Export CSV : mois, catégorie, compte proposé, HT, « TVA déductible (droit ouvert par ces pièces) », TTC, dont TVA autoliquidée ; point-virgule, virgule décimale.
- Les totaux d'un lecteur qui ne voit pas les salaires n'en comprennent aucun ; le résultat par magasin de l'étape 6 suivra la même règle (4.12).

### 4.12 Ce qui viendra ensuite

- Étape 2 : une facture de charges reçue par SUPER PDP s'importe en brouillon de charges (catégorie à choisir avant la validation), la facture reçue jointe comme pièce, sous sa forme électronique (XML, ou Factur-X tel quel).
- Étape 5 : écritures des charges, des parts et des salaires, comptes proposés de 4.2 ; exigibilité et date de déduction : services achetés en France au paiement sauf option du fournisseur pour les débits, énergie à la fin de la période facturée (à confirmer), acquisitions intracommunautaires le 15 du mois suivant la livraison ou à la date de la facture si elle est antérieure (art. 269-2 d du CGI), services autoliquidés à leur réalisation (à confirmer, EC7).
- Étape 6 : tableau de bord des charges du magasin et de la société ; résultat par magasin, qui comprend les salaires, visible seulement de qui voit les salaires de ce magasin ; pour les autres, une marge avant salaires.
- Étape 9 : conservation des récapitulatifs de paie à part des autres données ; totaux de salaires d'un magasin de peu de salariés traités comme données personnelles des salariés (registre des traitements, information des salariés).
- Étape 15, seulement si l'éditeur la retient (6.1 g) : numérisation fiscale conforme à l'art. A102 B-2 du LPF.

### 4.13 Scénarios de test des charges

Magasin de Lyon, société redevable, octobre 2026, pièces « facture » sauf mention contraire. Montants : HT / TVA facturée / TVA déductible / TTC.

| # | Fichier::méthode | Données | Résultat | Lot |
|---|---|---|---|---|
| CH-01 | `Feature/Charges/RecuperationDeLaTvaTest::test_un_loyer_sans_tva_n_a_aucune_tva_deductible` | Loyer, local professionnel, 1 500,00 € HT à 0 % (raison « exonérée » posée d'office) | 1 500,00 / 0,00 / 0,00 / 1 500,00 | CH2 |
| CH-02 | `…::test_un_loyer_avec_tva_facturee_par_le_bailleur_est_deductible` | Loyer, local professionnel, 1 500,00 € HT à 20 % | 1 500,00 / 300,00 / 300,00 / 1 800,00 | CH2 |
| CH-03 | `…::test_une_facture_d_electricite_a_deux_taux` | 210,50 € HT à 20 % et 12,34 € HT à 5,5 % | 222,84 / 42,78 (42,10 + 0,68) / 42,78 / 265,62 | CH2 |
| CH-04 | `…::test_une_prime_d_assurance_n_a_aucune_tva_deductible_et_une_tva_facturee_est_signalee` | Assurance 640,00 € HT à 0 % ; puis 100,00 € HT à 20 % | 640,00 / 0 / 0 / 640,00 ; puis 100,00 / 20,00 / 0,00 / 120,00 avec `charge.tva_inattendue` | CH2 |
| CH-05 | `…::test_des_frais_bancaires_exoneres_et_taxables` | 12,00 € à 0 % et 25,00 € HT à 20 % | 37,00 / 5,00 / 5,00 / 42,00 | CH2 |
| CH-06 | `…::test_un_logiciel_achete_a_un_editeur_irlandais_est_autoliquide` | Éditeur IE, 100,00 € HT, service | 100,00 / 0 (autoliquidée 20,00) / 20,00 / 100,00 | CH2 |
| CH-07 | `…::test_le_meme_logiciel_achete_par_une_societe_en_franchise_n_ouvre_aucune_deduction` | CH-06, société en franchise | 100,00 / 0 (autoliquidée 20,00) / 0,00 / 100,00 ; TVA à payer à l'État 20,00 ; coût de la charge 120,00 | CH2 |
| CH-08 | `…::test_un_billet_de_train_n_ouvre_aucune_deduction` | Transport de personnes, 89,00 € HT à 10 % | 89,00 / 8,90 / 0,00 / 97,90 ; la raison cite l'exception du transport collectif du personnel | CH2 |
| CH-09 | `…::test_le_gazole_l_essence_et_l_e85_d_un_vehicule_de_tourisme_sont_deductibles_a_80_pour_cent` | 50,00 € HT à 20 % ; puis 41,67 € HT à 20 % | 50,00 / 10,00 / 8,00 / 60,00 ; puis 41,67 / 8,33 / 6,66 / 50,00 (arrondi de l'option recommandée de 6.1 d) | CH2 |
| CH-09b | `…::test_la_recharge_electrique_d_un_vehicule_de_tourisme_est_entierement_deductible` | Électricité ou GNV d'un véhicule de tourisme, 50,00 € HT à 20 % | 50,00 / 10,00 / 10,00 / 60,00 ; indicateur « à confirmer » | CH2 |
| CH-10 | `…::test_le_carburant_d_un_vehicule_utilitaire_est_entierement_deductible` | 50,00 € HT à 20 % | 50,00 / 10,00 / 10,00 / 60,00 | CH2 |
| CH-11 | `…::test_un_cadeau_sous_le_seuil_est_deductible_et_au_dela_ne_l_est_pas` | Cadeau jusqu'au seuil 50,00 € HT à 20 % (60,00 € TTC), case du cumul cochée ; cadeau au-delà du seuil 100,00 € HT à 20 % ; puis 100,00 € HT marqué « jusqu'au seuil » | Déductible 10,00 ; 0,00 ; refus `charge.cadeau_au_dela_du_seuil` (120,00 € TTC > 73 €) | CH2 |
| CH-11b | `…::test_un_cadeau_jusqu_au_seuil_exige_la_case_du_cumul_de_l_annee` | Cadeau jusqu'au seuil 50,00 € HT à 20 %, case non cochée | Refus `charge.cadeau_seuil_a_confirmer` ; la raison affichée dit « par bénéficiaire et par an, frais d'emballage et d'envoi compris » | CH2 |
| CH-12 | `…::test_une_nuit_d_hotel_d_un_salarie_n_ouvre_aucune_deduction` | Autres charges, logement des dirigeants et du personnel, 120,00 € HT à 10 % | 120,00 / 12,00 / 0,00 / 132,00 ; indicateur « à confirmer » (exceptions) | CH2 |
| CH-13 | `Feature/Charges/SalairesMensuelsTest::test_les_salaires_d_octobre_se_saisissent_en_totaux_avec_le_recapitulatif` | Lyon, octobre 2026 : bruts 8 400,00 €, cotisations patronales 3 360,00 € | Total 11 760,00 € | CH4 |
| CH-14 | `Feature/Charges/ChargesCommunesTest::test_une_charge_commune_de_1000_euros_se_repartit_a_parts_egales_sur_trois_magasins` | Assurance 1 000,00 € HT à 0 %, parts égales sur trois magasins | 333,33 / 333,34 / 333,33 (option recommandée de 6.1 d) | CH5 |
| CH-15 | `…::test_une_charge_commune_se_repartit_selon_des_pourcentages` | Autres charges, autre, 1 200,00 € HT à 20 %, 50 / 30 / 20 % | HT 600,00 / 360,00 / 240,00 ; TVA 120,00 / 72,00 / 48,00 ; TTC 720,00 / 432,00 / 288,00 | CH5 |
| CH-16 | `…::test_chaque_montant_de_chaque_ligne_se_repartit_sans_ecart` | 100,00 € HT à 20 %, parts égales sur trois | HT 33,33 / 33,34 / 33,33 ; TVA 6,67 / 6,66 / 6,67 ; TTC 40,00 chacun ; TVA déductible = TVA de chaque part (option recommandée de 6.1 d) | CH5 |
| CH-17 | `Feature/Charges/TotauxDesChargesTest::test_les_totaux_d_octobre_comprennent_les_parts_et_les_salaires_pour_qui_peut_les_voir` | Lyon : CH-02, CH-03, CH-08, part de 50 % de CH-15 (Autres charges), CH-13 | Loyer 1 500,00 / 300,00 / 1 800,00 ; Électricité et eau 222,84 / 42,78 / 265,62 ; Transport 89,00 / 0,00 / 97,90 ; Autres charges 600,00 / 120,00 / 720,00 ; Salaires 11 760,00 / 0,00 / 11 760,00. Propriétaire : 14 171,84 / 462,78 / 14 643,52. Responsable : 2 411,84 / 462,78 / 2 883,52 (sans salaires). Colonne « TVA déductible (droit ouvert par ces pièces) » et ligne fixe affichées | CH5 |
| CH-18 | `Feature/Charges/RecuperationDeLaTvaTest::test_un_ticket_de_carburant_n_ouvre_aucune_deduction` | Carburant d'un véhicule utilitaire, 50,00 € HT à 20 %, pièce « ticket ou reçu » | 50,00 / 10,00 / 0,00 / 60,00 ; raison « Ticket ou reçu sans facture » | CH2 |
| CH-19 | `…::test_la_location_longue_duree_d_une_voiture_particuliere_n_ouvre_aucune_deduction` | Loyer, véhicule de tourisme, 400,00 € HT à 20 % | 400,00 / 80,00 / 0,00 / 480,00 | CH2 |
| CH-20 | `…::test_la_reparation_d_une_voiture_particuliere_n_ouvre_aucune_deduction` | Entretien, véhicule de tourisme, 300,00 € HT à 20 % | 300,00 / 60,00 / 0,00 / 360,00 | CH2 |
| CH-21 | `…::test_une_societe_qui_declare_des_operations_hors_droit_a_deduction_voit_chaque_charge_a_confirmer` (et `Unit/Tva/MoteurAchatTest`, même nom) | CH-02, société qui déclare des opérations exonérées | 1 500,00 / 300,00 / 300,00 / 1 800,00 (aucun coefficient) ; indicateur « à confirmer », information `charge.deduction_a_proratiser` | CH2 |
| CH-22 | `Feature/Charges/ChargesCommunesTest::test_une_assurance_commune_d_un_assureur_luxembourgeois_n_est_pas_autoliquidee` | Assurance 900,00 € d'un assureur établi au Luxembourg, parts égales sur trois magasins | 300,00 chacun ; autoliquidée 0 ; déductible 0 | CH5 |

---

## 5. Les lots

### 5.0 Ordre et justification

```
(fin du lot 21 de la chaîne société, CI verte ; questions de 6.1 soumises à l'éditeur, 6.0)
  → M0   règles écrites dans CLAUDE.md
  → C-a  compléments 1, 2, 3, 4, 6, 16
  bloc des ventes
  → M1   calculatrice et VIES dans le domaine
  → M2   moteur des ventes, sans changer un résultat
  → M3   profils, circuit, gestes, format signé
  → C-b  facture demandée, « Transformer en facture »
  → M4   écrans de vente
  → M8   ventes à distance
  bloc des achats (lots M5 à M7, charges comprises)
  → M5a  régime de TVA daté
  → M5b  fournisseurs et VIES des fournisseurs
  → M5c  moteur des achats (pur)                              porte : 6.1 c et 6.1 d
  → M5d  schéma, validation, décision figée
  → M5e  devises, TVA étrangère, saisie manuelle, avoirs      porte : 6.1 d
         fournisseur
  → M6   écrans d'achat et API
  → CH1  pièces justificatives
  → CH2  charges
  → CH3  charges récurrentes                                  seulement si 6.1 f = option 1
  → CH4  salaires
  → CH5  charges communes                                     porte : 6.1 d
  → CH6  avoirs reçus sur des charges                         seulement si 6.1 b = option 1
  → M7   coût et valeur du stock
  → FIN  étape 1 cochée
  → étape 1 bis (campagne de tests initiale), hors de ce plan
```

Une « porte » est une question de 6.1 sans la réponse de laquelle le lot ne commence pas (6.0) ; un lot marqué « seulement si » n'existe que si l'éditeur retient l'option dite.

| Lot | Pourquoi à cette place |
|---|---|
| M0 | Les règles s'écrivent avant le code, comme au lot A4. Chaque agent lit `CLAUDE.md` ; les lots suivants y trouvent leurs règles, et C-a et C-b n'ont plus de textes à écrire. Les phrases qui dépendent d'une question encore ouverte attendent sa réponse (2.0). |
| C-a | Petit et indépendant du moteur. Il touche `PreuvesRequises`, `ContrePasserPaiementAction`, `ValiderFactureAchatAction`, `NoterBordereauDetaxeAction` et les avoirs avant que M4 (preuves) et M5d (validation des achats) ne les reprennent, ce qui garde chaque diff lisible ; il crée `avoir.sur_ticket`, dont C-b a besoin. |
| M1 | Aucun changement de montant ni de message ; la consultation VIES change seulement de lieu, de l'écran à l'action. Le moteur de M2 s'appuie sur la calculatrice déplacée ; l'émission de M3 lit une consultation VIES faite hors du verrou. |
| M2 | Le moteur des ventes, seul, sans changer un centime ni un octet signé : la comparaison avant et après n'est possible que si rien d'autre ne change en même temps. |
| M3 | Les profils, le circuit et les gestes imposent le nouveau format signé : il change une seule fois, ici. |
| C-b | Il suppose la série, le circuit et la clôture séparés du type de client (M3) ; il précède M4, dont les écrans intègrent la case « facture demandée ». |
| M4 | Les écrans de vente remplacent la liste des natures ; l'application d'office et le choix imposé arrivent avec le panneau qui les montre. |
| M8 | Il ne dépend que des ventes (colonne `vente_a_distance` de M3, panneau de M4) ; le bloc des ventes se termine avant celui des achats. |
| M5a | Le moteur des achats lit le régime de la société à la date de l'achat (O7). |
| M5b | Les faits du fournisseur (établissement, franchise, VIES) sont des entrées du moteur des achats. |
| M5c | Le moteur des achats, pur, sans schéma : relu seul, testé sans base. Les catégories des lignes de charge naissent ici (décision de calendrier des charges). Il attend les réponses à 6.1 c et 6.1 d (frais, TVA déductible partielle). |
| M5d | Le moteur devient vivant : colonnes, validation, décision figée, journal d'audit, coût d'entrée ; les lignes de charge existent dès ce lot. |
| M5e | Les cas particuliers, chacun avec sa décision (TVA11, TVA13, TVA15, TVA16), après le chemin commun ; il attend la réponse à 6.1 d (devises). |
| M6 | Les écrans d'achat se réécrivent sur un moteur complet. |
| CH1 | Une charge ne se valide qu'avec sa pièce : les pièces viennent avant les charges, d'abord sur les factures d'achat dont M6 vient de réécrire les écrans (étape 8 avancée). |
| CH2 | Les charges complètent les lignes de charge de M5c et M5d (type de document, précisions, écrans, totaux). |
| CH3 | Seulement si l'éditeur retient les charges récurrentes (6.1 f) ; un modèle crée des brouillons de charges : il suppose CH2. |
| CH4 | Les salaires utilisent les pièces (CH1) et s'ajoutent aux totaux de CH2 ; il crée `exigerDansLaSocieteSousVerrou()`, que CH5 reprend. |
| CH5 | Le plus complexe (espace de la société, écritures dans plusieurs magasins, pièces de société) ; le scénario CH-17 réunit tout. Il attend la réponse à 6.1 d (répartition) ; il applique celle de 6.1 e si elle est connue, sinon les restrictions provisoires de 4.7. |
| CH6 | Seulement si l'éditeur retient l'option 1 du point b de 6.1. |
| M7 | Le coût et la valeur du stock (TVA12, TVA14, O1) touchent le grand livre : il ferme le bloc des achats, comme l'éditeur l'a décidé (« dans les lots achats du moteur de TVA, M5 à M7 ») ; les lots des charges n'en dépendent pas (aucune ligne de charge n'entre dans un coût) et M7 n'en dépend pas, d'où leur place avant lui. |
| FIN | L'étape 1 n'est cochée qu'après le dernier lot. |

Chaque lot suit les règles communes de 0.3. Pour chaque lot : objectif ; décisions appliquées ; fichiers et classes ; migrations ; écrans ; tests nommés (les identifiants V, A et CH renvoient aux tableaux de 3.23 et 4.13, qui donnent le fichier, la méthode et les montants) ; critères de fin ; points de relecture ; ce qui ne doit pas changer.

### 5.1 Lot M0 : règles écrites

- **Objectif** : écrire dans `CLAUDE.md` les textes de la section 2 ; décocher l'étape 1 si le lot 21 l'a cochée.
- **Décisions** : section 1 entière ; réponses déjà reçues aux questions de 6.1, appliquées selon 2.0 (« Soumis à 6.1 »).
- **Fichiers** : `CLAUDE.md` seulement.
- **Migrations, écrans** : aucun.
- **Tests** : aucun test nouveau ; les deux suites passent avant le commit (protocole).
- **Critères de fin** :
  - `git diff --stat` du lot ne montre que `CLAUDE.md` ;
  - `grep -c "^- Charges du magasin :" CLAUDE.md` vaut 1 ;
  - `grep -n "Seules lectures ou écritures" CLAUDE.md` trouve la puce 2 de « Produit » ;
  - `grep -n "cartes cadeaux" CLAUDE.md` trouve « Prix et TVA des ventes » et l'étape 3 ;
  - `grep -n "M0, C-a, M1, M2, M3, C-b, M4, M8, M5a, M5b, M5c, M5d, M5e, M6, CH1, CH2, CH4, CH5, M7, FIN" CLAUDE.md` trouve l'étape 1 ;
  - chaque phrase « Soumis à 6.1 » est au texte de la réponse connue, sinon à son texte d'attente ; le message de commit dit lesquelles ;
  - `grep -n -E "même territoire de TVA|n'y facture qu'un service" CLAUDE.md` vide, sauf restriction gardée par l'éditeur (6.1 e) ;
  - `grep -n "^1\. \[ \]" CLAUDE.md` : l'étape 1 n'est pas cochée ;
  - `grep -n -i "bon_d_achat" CLAUDE.md` vide.
- **Points de relecture** : chaque phrase nouvelle découle d'une décision de la section 1 ; aucune phrase soumise à une question encore ouverte n'est écrite comme décidée ; aucune règle de la chaîne société n'est perdue à la fusion (2.0), la phrase du lot A4b sur la liste d'étapes comprise ; les autorisations sont dites de la même façon dans toutes les puces ; rien n'est promis que ce plan ne livre pas (ce qui viendra à une étape suivante est écrit au futur).
- **Ne doit pas changer** : le protocole de rigueur, les règles Laravel Boost, les puces de 2.16, les lignes « Fait » et les étapes 10 à 15.

### 5.2 Lot C-a : compléments 1, 2, 3, 4, 6 et 16

- **Objectif** : réimputer le crédit d'avoir à la contre-passation ; prouver la correction immédiate d'une fiche produit depuis un brouillon d'achat ; exiger le droit de créer des produits à la validation qui en crée ; rendre les preuves annulables, « établie par » obligatoire et les deux documents de transport indépendants ; rappeler le bordereau de détaxe au retour ; réserver les retours et avoirs sur tickets à une autorisation.
- **Décisions** : compléments 1, 2, 3, 4, 6, 16 ; textes de `CLAUDE.md` du lot M0 (« Paiements et avoirs », « Achats et stock », « Ventes hors de France »).
- **Fichiers et classes** :
  - Complément 1 : `app/Domain/Facturation/Services/ImputationDuCredit.php` (nouveau) porte les deux étapes de l'imputation d'un avoir, telles que le code les applique déjà (`ImputerAvoirSurSesFacturesAction`) et que le texte de « Paiements et avoirs » les écrit (2.9) — d'abord ce qui reste dû des factures qu'il corrige (au plus ses propres lignes sur chacune, moins ce qu'il y a déjà imputé), puis les autres factures impayées du client, de la plus ancienne à la plus récente, jamais d'étape 2 pour le client comptoir ; `ImputerAvoirSurSesFacturesAction` l'utilise pour un avoir à son émission ; `ContrePasserPaiementAction` l'utilise, après les imputations inversées et `constaterUnReglement()`, pour les avoirs émis du client qui ont un crédit : d'abord l'étape 1 de chacun, du plus ancien au plus récent, sur les factures rouvertes qu'il corrige, puis l'étape 2 des crédits restants, dans le même ordre, sur les factures dues de la plus ancienne à la plus récente ; les imputations créées sont des `AvoirAllocation` positives dont `paiement_id` est la contre-passation, signées avec elle dans `credits_imputes` (format signé inchangé). Docblocks réécrits : `ContrePasserPaiementAction`, `AppliquerAvoirsDisponiblesAction` (« sans y imputer ce crédit, qui attend le bon paiement » disparaît), `EnregistrerPaiementAction`, `Client::soldeCrediteur()`.
  - Complément 2 : aucun code ; un test.
  - Complément 3 : `ValiderFactureAchatAction` exige, quand la facture crée des produits, le droit de créer des produits, par le mécanisme du lot A4 (`LecteurDesDroits`) ; message « Valider cette facture crée de nouveaux produits : il faut aussi le droit de créer des produits. » ; rien n'est créé ni validé.
  - Complément 4 : `app/Domain/Facturation/Models/AnnulationPreuveOperation.php` (`BelongsToMagasin`, `FigeLeNomDeSesAuteurs`) ; `app/Domain/Facturation/Actions/AnnulerPreuveOperationAction.php` (auteur, preuve, motif obligatoire de 255 caractères au plus ; même droit que l'ajout d'une preuve ; une preuve ne s'annule qu'une fois, garanti par l'index unique) ; `AjouterPreuveOperationAction` : « établie par » obligatoire (« Indiquez qui a établi ce document. ») ; un document de transport établi par le magasin, la société (raison sociale) ou le client (nom figé) est refusé (« Un document de transport doit venir d'une partie indépendante du magasin et du client. »), noms comparés pliés par `RechercheSansAccents::plier()` et espaces réduits ; la règle « une référence une fois par facture » ignore les preuves annulées ; `PreuveOperation::scopeNonAnnulees()` ; `PreuvesRequises::manquantes()` et `incompletes()` ne comptent que les preuves non annulées et exigent, pour une livraison intracommunautaire, au moins un document de catégorie a) et au moins deux émetteurs distincts parmi les documents a) et b) (PHP : `plier()` ; SQL : `count(distinct sans_casse_ni_accents(emetteur))`), message « Un second document de transport, ou une autre preuve d'expédition, d'une autre partie que le premier ».
  - Complément 6 : `app/Domain/Facturation/Models/SuiteBordereauDetaxe.php` ; `app/Domain/Facturation/Actions/NoterLaSuiteDuBordereauAction.php` (auteur, ticket, nature `annule` ou `rectifie`, nouveau n° exigé pour `rectifie` ; même droit que noter un bordereau ; dans une transaction, sous le verrou de la ligne du ticket que prend déjà `NoterBordereauDetaxeAction`, `Facture::bordereauARegulariser()` est relu : nul, la suite est refusée (`detaxe.suite_deja_notee`, « La suite de ce bordereau est déjà notée. ») ; une rectification note aussi le nouveau n° comme bordereau, dans la même transaction ; un index unique (`bordereau_detaxe_id`, `avoir_id`) écarte un doublon que le verrou laisserait passer) ; `Facture::bordereauARegulariser(): ?BordereauDetaxe` (le dernier bordereau noté, quand un avoir émis sur ce ticket est postérieur au bordereau et qu'aucune suite n'est notée après cet avoir) ; `Facture::bordereauDetaxe()` ne rend plus un bordereau suivi d'une suite « annulé » ; `EmettreAvoirAction` rend l'avertissement `detaxe.bordereau_a_regulariser` (« Annulez ou rectifiez le bordereau n° … dans PABLO, puis notez-le ici. »).
  - Complément 16 : `PermissionRegistry::AVOIR_SUR_TICKET = 'avoir.sur_ticket'`, dans `toutes()`, `reserveesAuxOperationsCommerciales()` et `libelles()` (« Avoirs : faire un retour ou un avoir sur un ticket de caisse »), dans le modèle de l'administrateur, absente des modèles du vendeur et de l'observateur (`RoleMagasin::permissionsParDefaut()`), jamais dans `consultations()`. **Elle suffit seule** : pour un avoir dont une facture corrigée est un ticket (`Facture::scopeTickets()`), l'action qui le crée, l'enregistre ou l'émet exige `avoir.sur_ticket` à la place de `avoir.creer`, contrôle côté serveur quel que soit l'écran ; `AvoirPolicy` laisse voir, imprimer et réimprimer un avoir dont toutes les factures corrigées sont des tickets avec `avoir.view` ou `avoir.sur_ticket` (le bon d'avoir se remet au client) ; `avoir.creer` reste l'autorisation des avoirs sur les factures ; un vendeur ne fait un retour que sur un ticket qu'il peut voir (règle « Vendeurs » inchangée).
- **Migrations** : `2026_10_03_100009_create_annulations_preuves_operation_table.php` (`magasin_id`, `preuve_operation_id` unique, `motif` string(255), `annulee_par`, `annulee_par_nom`, `created_at` ; `DeclencheursDeProtection::ajoutSeul()`) ; `2026_10_05_100003_create_suites_bordereaux_detaxe_table.php` (`magasin_id`, `bordereau_detaxe_id`, `avoir_id`, `nature` string(10), `numero_rectificatif` string(30) nullable, `note_par`, `note_par_nom`, `created_at` ; index unique (`bordereau_detaxe_id`, `avoir_id`) ; ajout seul) ; `preuves_operation.emetteur` devient obligatoire (migration de création modifiée).
- **Écrans** : fiche d'une facture, section des preuves : « Établie par » obligatoire, action « Annuler cette preuve » (fenêtre avec motif) ; preuve annulée affichée barrée avec auteur, date et motif ; fiche d'un ticket : encadré « Bordereau n° … à annuler ou rectifier dans PABLO » et action « Noter la suite » ; avertissement persistant après l'émission d'un avoir concerné ; libellé de la nouvelle autorisation dans les écrans d'équipe (par `libelles()`).
- **Tests nommés** :
  - `Feature/Facturation/ReimputationALaContrePassationTest` : `test_le_credit_d_avoir_disponible_s_impute_sur_la_facture_rouverte_par_la_contre_passation` (facture F de 100,00 € payée par virement, avoir de 100,00 € qui la reprend entièrement ; contre-passation du virement : F payée par 100,00 € de crédit, solde créditeur 0,00 €) ; `test_le_credit_s_impute_de_la_plus_ancienne_a_la_plus_recente` (avoirs de 30,00 € puis 50,00 €, qui corrigent des factures G1 et G2 déjà entièrement réglées : leur étape 1 n'impute rien ; factures F1 de 60,00 € puis F2 de 40,00 €, sans lien avec ces avoirs, rouvertes par la contre-passation : imputations 30,00 € et 30,00 € sur F1, 20,00 € sur F2, qui reste due de 20,00 €) ; `test_le_credit_s_impute_d_abord_sur_la_facture_rouverte_qu_il_corrige` (F1 de 60,00 € puis F2 de 40,00 €, réglées par un même virement de 100,00 € ; avoir de 20,00 € sur F2 émis ensuite, crédit disponible ; contre-passation du virement : 20,00 € imputés sur F2, qu'il corrige, et non sur F1, plus ancienne) ; `test_la_reimputation_est_signee_avec_la_contre_passation_et_le_verificateur_la_retrouve` ; `test_le_credit_d_un_bon_d_avoir_du_client_comptoir_ne_s_impute_jamais_sur_une_autre_vente` ; `test_une_contre_passation_sans_credit_disponible_ne_change_rien`.
  - `Feature/Achats/FactureAchatActionsTest` : `test_la_correction_d_un_produit_existant_depuis_un_brouillon_est_immediate_et_son_taux_inscrit_au_jet_270` (prix 12,00 → 13,00 €, taux 20 → 10 % : fiche corrigée avant la validation, JET 270 avec l'ancien et le nouveau taux, brouillon toujours brouillon) ; `test_valider_une_facture_qui_cree_des_produits_exige_le_droit_de_creer_des_produits` ; `test_valider_une_facture_sans_nouveau_produit_n_exige_pas_le_droit_de_creer_des_produits`.
  - `Feature/Facturation/PreuvesOperationTest` : `test_une_preuve_annulee_ne_compte_plus_et_garde_sa_trace` ; `test_annuler_une_preuve_exige_un_motif_et_inscrit_l_auteur` ; `test_une_preuve_ne_s_annule_qu_une_fois` ; `test_une_preuve_annulee_se_ressaisit_avec_la_meme_reference` ; `test_etablie_par_est_obligatoire` ; `test_deux_documents_de_transport_du_meme_emetteur_ne_suffisent_pas` (« Transports Martin » et « transports  martin ») ; `test_un_document_de_transport_etabli_par_le_magasin_la_societe_ou_le_client_est_refuse` ; `test_la_requete_sql_des_factures_incompletes_suit_la_meme_regle` ; `test_une_annulation_ne_se_modifie_ni_ne_s_efface_en_base`.
  - `Feature/Facturation/BordereauDetaxeTest` : `test_un_retour_apres_un_bordereau_rappelle_de_l_annuler_ou_de_le_rectifier` (ticket de 2 Câbles, bordereau BVE-123, avoir d'un Câble) ; `test_le_ticket_l_indique_jusqu_a_ce_que_la_suite_soit_notee` ; `test_une_rectification_note_le_nouveau_numero_qui_fait_foi` (BVE-124) ; `test_un_bordereau_annule_ne_vaut_plus_pour_le_ticket` ; `test_noter_la_suite_exige_le_droit_de_noter_un_bordereau` ; `test_une_suite_ne_se_modifie_ni_ne_s_efface` ; `test_un_retour_sans_bordereau_ne_rappelle_rien` ; `test_une_suite_notee_deux_fois_n_est_enregistree_qu_une_fois` (double soumission : une seule suite, un seul nouveau bordereau pour une rectification, le second refus `detaxe.suite_deja_notee`).
  - `Feature/Facturation/AvoirSurTicketTest` : `test_un_vendeur_sans_l_autorisation_ne_fait_ni_retour_ni_avoir_sur_un_ticket_meme_par_l_action` ; `test_un_vendeur_a_qui_le_responsable_coche_l_autorisation_fait_un_retour_sur_un_ticket` (la seule case `avoir.sur_ticket`, sans `avoir.creer` ni `avoir.view` : avoir créé, émis et imprimé) ; `test_l_autorisation_des_tickets_ne_permet_pas_un_avoir_sur_une_facture` ; `test_le_responsable_et_le_proprietaire_l_ont_toujours` ; `test_le_modele_de_l_administrateur_la_precoche_et_aucun_modele_de_vendeur_ou_d_observateur` ; `test_l_expert_comptable_ne_l_a_jamais` ; `test_un_avoir_sur_une_facture_a_un_professionnel_n_exige_pas_cette_autorisation`.
  - `Feature/Tenancy/TenantIsolationTest::test_chaque_modele_de_magasin_est_liste_ici` (deux modèles) ; `ContrePasserPaiementActionTest::test_aucune_requete_ne_verrouille_une_ligne_d_une_table_en_ajout_seul` (deux tables de plus dans la liste attendue).
  - Tests réécrits par décision (annexe A) : ceux qui fixent l'attente du crédit jusqu'au bon paiement, ceux qui ajoutent une preuve sans émetteur, ceux où un vendeur fait un avoir sur un ticket sans la nouvelle autorisation.
- **Critères de fin** : `git grep -n -i "attend le bon paiement" app tests` vide ; `git grep -n "emetteur', 150)->nullable" database/migrations` vide ; `git grep -n "AVOIR_SUR_TICKET" app` trouve le registre, le contrôle de l'action, `AvoirPolicy` et les modèles de rôles ; les tests nommés existent et passent.
- **Points de relecture** : un crédit imputé deux fois, ou une contre-passation qui laisse un crédit disponible alors qu'une facture rouverte est due ; un ordre d'imputation qui diffère entre l'émission d'un avoir et la contre-passation ; une imputation du client comptoir sur une autre vente ; un `FOR UPDATE` sur une table en ajout seul ; une règle d'émetteurs différente entre PHP et SQL ; une preuve annulée qui compte encore ; un retour sur un ticket possible par un autre chemin (caisse, écran d'avoir, action appelée directement) ; un retour sur un ticket qui exige encore `avoir.creer` ; une suite de bordereau notée deux fois.
- **Ne doit pas changer** : les montants des paiements et des avoirs ; la structure du contenu signé (`credits_imputes` garde ses clés) ; `ModuleFiscal::VERSION` ; les règles de complétude des preuves autres que l'annulation et l'indépendance des émetteurs.

### 5.3 Lot M1 : calculatrice et VIES dans le domaine

- **Objectif** : déplacer `CalculateurLigne` sans changer son API ; mettre VIES derrière une interface avec un faux ; consulter VIES à l'émission, hors transaction, après la recherche du jeton ; supprimer la consultation de l'écran ; prouver l'égalité des deux ventilations. Aucun changement de schéma, de montant ni de message. La consultation garde exactement la condition d'aujourd'hui (aucune vérification valide du jour, ce qui comprend une dernière consultation du jour qui a trouvé le n° invalide : `consultationDuJour() === null`) ; seul son lieu change, de l'écran à l'action : tout appel de l'action consulte donc, tests compris, ce que la mise en place des tests de l'annexe A (ligne M1) prend en compte.
- **Décisions** : 3.8 (étapes 0 et 1) ; 1.6 (consultation à l'émission exigeant le droit d'émettre).
- **Fichiers et classes** :
  - `app/Domain/Facturation/Services/CalculateurLigne.php` → `app/Domain/Tva/Calcul/CalculateurLigne.php` (espace de noms `App\Domain\Tva\Calcul`), règles et API inchangées ; tous les `use` suivent ; `tests/Unit/CalculateurLigneTest.php` → `tests/Unit/Tva/CalculateurLigneTest.php` (ligne `use` et espace de noms seulement).
  - `app/Integrations/Vies/Contracts/RegistreVies.php` (interface : `verifier(string $numeroTva): ResultatVies`, lève `ViesIndisponibleException`) ; `ClientVies` l'implémente ; `app/Integrations/Vies/FauxRegistreVies.php` (déterministe, sans requête, sur le modèle de `FauxRepertoireSirene` : n° valides et invalides configurables, état « injoignable », relevé des appels pour les tests).
  - `app/Providers/VerificationServiceProvider.php` : pilote `vies` (`europa` → `ClientVies`, `faux` → `FauxRegistreVies`), refus de démarrer en production avec `faux` ; `config/services.php` : `'vies' => ['pilote' => env('VIES_PILOTE', 'europa')]` ; `phpunit.xml` inchangé.
  - `app/Domain/Contacts/Services/ConsultationsVies.php` : `consulter(Client $client, User $auteur): ConsultationVies` (le lot M5b ajoute `Fournisseur`) ; `FauxRegistreVies` quand le magasin actif est la démonstration, quel que soit le pilote ; `VerifierNumeroTvaAction` s'y appuie et garde son contrôle (`update` sur le client).
  - `app/Domain/Facturation/Actions/AssurerUneVerificationViesDuJourAction.php` : `handle(User $auteur, Facture $facture): void` ; si la nature choisie exige VIES et qu'aucune vérification valide du jour (Paris) n'existe pour le n° exact du client dans le magasin, consulte et enregistre ; exige `emettre` sur la facture ; VIES injoignable : exception avec le message de 3.16.3, aucune consultation enregistrée.
  - `ActionsUniques::resultatDejaEnregistre(...)` : simple lecture, sans verrou, du résultat enregistré pour le même jeton, la même action, le même auteur et le même contenu.
  - `EmettreFactureAction` : étapes 0 et 1 de 3.8 avant l'ordre des lots A4 et A6.
  - `FactureResource::verifierLeNumeroTvaSurViesSiBesoin()` et ses appels : supprimés.
- **Migrations, écrans** : aucune migration ; l'écran d'émission affiche le refus de VIES injoignable.
- **Tests nommés** : `Feature/Tva/ConsultationViesALEmissionTest` (huit tests de 3.23.1) ; `Feature/Tva/RegistreViesTest::test_le_pilote_faux_est_refuse_en_production` ; `Feature/Tva/VentilationTest::test_la_ventilation_signee_et_celle_des_ecrans_donnent_le_meme_resultat`.
- **Tests existants touchés** (annexe A, ligne M1) : au début du lot, l'agent liste les tests qui émettent, par l'action ou par l'écran, une nature qui exige VIES sans vérification valide du jour du n° du client ; au moins les jeux « n° vérifié la veille » et « n° inconnu de VIES aujourd'hui » de `NatureOperationTest::test_une_livraison_intracommunautaire_sans_toutes_ses_conditions_est_refusee` et `NatureOperationTest::test_une_verification_vies_invalide_plus_recente_du_meme_jour_refuse_l_exoneration`. Chacun lie `FauxRegistreVies` (ou un `Http::fake` sur le vrai client) répondant « inconnu » : l'émission consulte, VIES ne reconnaît pas le n°, et le test attend le même message qu'aujourd'hui. Aucune assertion ne change.
- **Critères de fin** : `git grep -n "verifierLeNumeroTvaSurViesSiBesoin"` vide ; `git grep -n -F 'Facturation\Services\CalculateurLigne'` vide ; `git grep -n "ClientVies::class" app` ne trouve que le fournisseur de services ; mêmes messages et mêmes montants qu'avant le lot, avec un registre VIES simulé là où l'émission consulte désormais : seules changent les lignes `use` et la mise en place de ce registre dans les tests listés.
- **Points de relecture** : un appel réseau sous le verrou du registre ; une consultation enregistrée pour une demande rejouée ; le vrai VIES appelé depuis la démonstration ; une consultation qui exige `client.update` ; un appel à VIES pendant la frappe ; une condition de consultation différente de celle de l'écran d'aujourd'hui ; un test d'émission qui part sur le réseau (`Http::preventStrayRequests()` le ferait échouer).
- **Ne doit pas changer** : les montants, le contenu signé, les messages de `NatureOperationTest`, le bouton « Vérifier sur VIES » de la fiche client.

### 5.4 Lot M2 : moteur des ventes, sans changer un seul résultat

- **Objectif** : créer le domaine `app/Domain/Tva` et y faire passer toutes les décisions et tous les montants des ventes, avec exactement les résultats, les messages et le contenu signé d'aujourd'hui. Seul l'ordre des refus suit 3.8 : le refus fiscal arrive avant l'avertissement de vente sans stock.
- **Décisions** : P1 à P14 ; 3.2 à 3.9 pour les ventes ; complément 5 (docblock).
- **Fichiers et classes créés** : `MoteurTva` (`VERSION = '1.0'`, `deciderVente`, `deciderAvoir`, `compatibilite`) ; `Calcul/CalculDesLignesDeVente`, `Calcul/CalculDesRetours` (retours de vente, K2) ; API en centimes de `CalculateurLigne` ; `Faits/FaitsDeLaSociete`, `FaitsDuTiers`, `FaitsDeVente`, `FaitsDeLigneDeVente`, `AdresseFiscale`, `VerificationVies`, `InstantaneDesFaits` ; `Decisions/DecisionTvaVente`, `LigneDeVenteChiffree`, `Totaux`, `Condition`, `Manquement`, `PropositionDeNature`, `MentionImprimee`, `EcartDeCalcul`, `CodeDeManquement` (énumération des codes de l'annexe B qui relèvent du moteur, avec `message()`) ; `Regles/CatalogueDesRegles`, `ReglesTva`, `TableDesTaux`, `TableDesMentions` (textes recopiés mot pour mot depuis le code), `TableDesCategories`, `TableDesPreuves`, `Seuils`, `ExigenceDuNumeroTva` ; `Geographie/GeographieTva` et `Geographie/TerritoireDeTva` (comportement identique à `TerritoireFiscal` et `TerritoireTva`) ; `Enums/NatureOperation` et `Enums/CategorieOperation` (déplacées, mêmes valeurs), `CategorieTva`, `Redevable`, `Gravite`, `TypeDeLigne`, `EtatDeLaDecision`, `MethodeDeCalcul`, `SourceDuFait` ; `Exceptions/DecisionTvaRefuseeException` (hérite de `LogicException`, même message que l'exception remplacée, codes en plus), `Exceptions/CalculDuBrouillonChangeException` ; collecteurs `Facturation/Services/CollecteurFaitsDeVente`, `Contacts/Services/CollecteurFaitsDuTiers`, `Tenancy/Services/CollecteurFaitsDeLaSociete`. Une classe, une énumération ou un champ de 3.2 et 3.3 qui ne sert qu'à un lot suivant naît dans ce lot-là (par exemple `Circuit`, `SortieDesBiens`, `LieuDesServices` au lot M3, `ParticulariteFiscale` au lot M4).
- **Supprimés** : `ContexteDeVente`, `ControleNatureOperation`, `NatureOperationRefuseeException`, `MentionsFiscales::pour()` et `calcul()`, `TerritoireFiscal`, `Facturation/Enums/TerritoireTva`, les règles recodées dans les écrans.
- **Modifiés** : `ConstructeurLigneDocument` (traducteur : collecteur, moteur, lignes chiffrées → attributs ; `apercu()` appelle le moteur) ; `EmettreFactureAction` (le moteur à l'étape 3 de 3.8, avant le stock) ; `PanierDeCaisse` ; `CreerFactureAction`, `ModifierFactureAction`, `CreerDevisAction`, `ModifierDevisAction`, `EmettreDevisAction` ; `LigneFacture::prixUnitaireTtcPaye()` et `montantsDuRetour()` ; `NoterBordereauDetaxeAction::refusPour()` ; `AjouteDesLignesDeVente` ; `FactureResource::lignesEntry()` ; `Societe::mentionsLegalesManquantes()` (n° de TVA par `ExigenceDuNumeroTva`) ; `RegimeTva` (docblock, mention dans la table) ; `Magasin::territoireTva()` (rend `TerritoireDeTva`) ; `ContenuFiscal` produit le même contenu signé, mentions lues dans la table datée. `DecisionTvaVente::ecartAvec()` couvre exactement les refus d'aujourd'hui (type de client ou régime changé), avec leurs messages ; le lot M4 l'élargit.
- **Migrations, écrans** : aucune migration ; aucun écran ne change d'aspect.
- **Tests nommés** : V01, V03, V04, V17, V21, V28 ; `Feature/Tva/EmissionTvaTest` (trois tests M2 de 3.23.1) ; `Feature/Tva/VentesEnFranceTest::test_un_brouillon_d_export_rouvert_apres_le_passage_en_franchise_reprend_le_prix_ttc_du_catalogue_avec_une_notification` (gardé s'il existe déjà sous un autre nom) ; `Unit/Tva/CatalogueDesReglesTest` (quatre tests M2 de 3.23.5) ; `Feature/Tva/PureteDuMoteurTest` (trois tests M2 de 3.23.6) ; `Unit/Tva/GeographieTvaTest` (les cas de `TerritoireFiscalTest` et de `TerritoireTvaTest`, repris un à un) ; `Unit/Tva/CodesDeManquementTest::test_chaque_code_a_un_message_sans_code_technique`. Réécrits (annexe A) : `NatureOperationTest` (classe de l'exception attendue, à un seul endroit), `ConstructeurLigneDocumentTest` (faits au lieu du contexte, mêmes montants), docblocks de `ConsultationViesTest` et `ServiceResourceTest`.
- **Critères de fin** : `git grep -n -E "ContexteDeVente|ControleNatureOperation|TerritoireFiscal|NatureOperationRefuseeException" app tests resources` vide ; `git grep -n -F 'Facturation\Enums\TerritoireTva'` vide ; `git grep -n -F 'Facturation\Enums\NatureOperation'` vide ; `git grep -n -E "MentionsFiscales::(pour|calcul)"` vide ; `git grep -n -E "DB::|Eloquent|Illuminate.Database" app/Domain/Tva` vide ; `git grep -n -E "now\(\)|today\(\)|Carbon::now" app/Domain/Tva` vide ; comparaison avant et après sans écart, notée dans la relecture.
- **Points de relecture** : un centime qui bouge ; un octet signé qui change ; un texte de mention différent (accent, apostrophe) ; un écran qui garde une règle ; une lecture de base ou d'horloge dans le moteur.
- **Ne doit pas changer** : tout montant, tout contenu signé, tout message, l'ordre de l'émission livré par M1.

### 5.5 Lot M3 : profils, géographie, circuit, gestes ; format signé changé une seule fois

- **Objectif** : assujetti et organisme public sur le client ; géographie complète ; circuit de chaque document et catégories prises en charge ; gestes ; lieu des services ; vente à distance marquée ; décision figée et signée ; avoirs compatibles par circuit ; facture issue d'un devis ; PDF relisant les mentions figées.
- **Décisions** : compléments 8, 9, 10 ; O2, O5, O6, O8 ; TVA6 (règle) ; 3.10 à 3.12 ; 3.15.
- **Comportement transitoire** : au lot M3, la liste « Nature de l'opération » reste l'entrée du geste ; choisir une nature autre que la vente en France enregistre le geste « appliquer » ; garder « Vente en France » quand le moteur trouve une nature possible enregistre le geste « TVA française gardée » ; garder « Vente en France » sans nature possible n'enregistre rien. Le moteur du lot M3 n'a pas encore la branche « appliquée d'office » ni `tva.choix_du_regime_requis` : le lot M4 les ajoute avec le panneau.
- **Fichiers et classes** : `Enums/SortieDesBiens`, `Enums/LieuDesServices`, `Enums/Circuit` ; `Regles/CategoriesPrisesEnCharge` (S seulement) ; `GeographieTva` (3.10, lot M3) ; `MoteurTva` (natures et conditions de 3.15.4, circuit 3.15.2, vente à distance marquée, lieu des services, identité et client, O5, O6) ; `CollecteurFaitsDeVente` (sortie des biens, livraison, lieu des services, gestes) ; `FigeLeClient` (copies assujetti et organisme public) ; `NoteSaNatureDOperation` (réécrit) ; `ChoisitLaNatureDeLOperation` (sortie des biens à trois réponses, lieu des services, liste gardée comme entrée du geste) ; `CreerAvoirAction` (compatibilité par `MoteurTva::compatibilite()`, mêmes messages, plus celui du circuit) ; `CreerFactureDepuisDevisAction` (copie des faits et du geste, O8) ; `ContenuFiscal`, `VerificateurIntegrite` (3.12) ; vues PDF des factures et des avoirs (mentions et note figées ; adresse de livraison imprimée quand elle diffère) ; vue PDF du devis (moteur à la date du devis) ; `DeclencherTransmissionPdpApresEmissionFacture` (circuit et catégories prises en charge) ; `Facture::relevantDeLeInvoicing()` supprimée ; `Facture::serie()` découplée du circuit ; message corrigé du lieu fixé par la loi.
- **Migrations** : tables `clients`, `factures`, `avoirs`, `devis`, `services` de 3.11 (lot M3).
- **Écrans** : fiche client (cases « Ce client n'est pas assujetti à la TVA » et « Organisme public » dans une section repliée, sans le bouton Sirene, qui vient au lot M4) ; formulaire de facture et de devis : sortie des biens à trois réponses et question du lieu des services, dans la section existante.
- **Tests nommés** : V02, V15, V16, V18, V22, V22b, V23, V24, V26, V26b, V29, V30, V31, V35, V36, V42 ; `Feature/Tva/CircuitTest` (deux tests M3) ; `Feature/Tva/DecisionFigeeTest` (cinq tests M3) ; `Feature/Tva/GestesTest` (quatre tests) ; `Feature/Tva/VentesEnFranceTest::test_le_nom_et_l_adresse_du_client_sont_exiges_sur_toute_facture` ; `Feature/Filament/ClientResourceTest::test_un_client_de_saint_barthelemy_exige_un_siren_sans_etre_dans_le_territoire_de_tva`. Réécrits (annexe A) : format signé, `transport_par`, circuit, message du lieu fixé par la loi.
- **Critères de fin** : `git grep -n -E "transport_par|TransportPar|relevantDeLeInvoicing" app tests resources database` vide ; `git grep -n "jsonb" database` vide ; `git grep -n "est fixé par la loi (art. 259 A du CGI) : la TVA française s'applique" app` vide ; `git grep -n "ModuleFiscal::VERSION\|VERSION = '1.0'" app/Domain/CaisseSecurisee` inchangé.
- **Points de relecture** : une décision non signée ; un duplicata qui recalcule ; une clé JSON réordonnée sous PostgreSQL ; un geste qui survit à un changement de client ; un circuit faux pour Monaco, un DOM ou un non-assujetti ; un vérificateur qui accepte une décision modifiée.
- **Ne doit pas changer** : les montants ; `ModuleFiscal::VERSION` ; la liste des natures à l'écran (retirée au lot M4) ; les messages des conditions.

### 5.6 Lot C-b : facture demandée et « Transformer en facture »

- **Objectif** : un particulier peut demander une facture, en caisse comme sur une facture ; un ticket émis et entièrement payé se transforme en facture qui le reproduit exactement.
- **Décisions** : compléments 13, 14, 15 ; textes de M0 (« Documents de vente », « Paiements et avoirs », « Caisse et clôtures », « Demandes répétées ») ; les mentions réglables des factures à un professionnel (pénalités, indemnité forfaitaire, escompte) restent réservées aux factures à un professionnel, comme le dit déjà « Documents de vente ».
- **Fichiers et classes** :
  - `Facture::serie()` (série des factures pour un professionnel ou une facture demandée), `libelleDocument()` (« Facture » ou « Ticket de caisse »), `scopeTickets()` (sans les factures demandées) ; `FaitsDeVente::factureDemandee` (identité légale complète, nom et adresse du client exigés ; client comptoir refusé : `client.comptoir_pour_une_facture`).
  - Caisse : `PanierDeCaisse` et `EnregistrerVenteCaisseAction` (case « Le client demande une facture », client particulier choisi, ou créé dans la caisse par qui a `client.create`, nom et adresse exigés) ; formulaire de facture à un particulier : même case.
  - `app/Domain/Facturation/Actions/TransformerTicketEnFactureAction.php` : `handle(User $auteur, Facture $ticket, array $client, string $jeton)`. Dans l'ordre :
    1. `ActionsUniques::executer` (action `transformer_ticket`, contenu : ticket et coordonnées) ; transaction ; verrou du registre ; `verifierQuIlPeutVendre` ; droits `facture.emettre` et `avoir.sur_ticket` relus par `LecteurDesDroits::exigerSousVerrou` (l'avoir d'annulation est un avoir sur un ticket : `avoir.creer` n'est pas exigée) ; une caisse à la fois, comme toute émission (règle du lot A4) ; journée du jour non clôturée.
    2. Ticket relu verrouillé : c'est un ticket, jamais transformé (`vente.ticket_deja_transforme`, plus l'index unique), sans avoir (`vente.ticket_repris_par_un_avoir`), entièrement payé (`vente.ticket_non_paye`) ; fiche du client particulier choisie, ou créée avec `client.create`, jamais le client comptoir.
    3. Avoir d'annulation (motif `annulation_pour_facture`, toutes les lignes, montants par différence de cumul, donc exactement ceux du ticket).
    4. Facture de remplacement (`facture_demandee`, `ticket_transforme_id`) **construite avec les faits figés du ticket** : ses lignes (produit ou service, quantité, PU TTC payé, remise), ses taux, sa franchise, sa nature et la date de l'opération du ticket ; jamais la fiche ni le régime du jour. Le moteur, appelé sur ces faits, rend par construction les montants du ticket (article par article, mêmes taux, mêmes PU) ; une différence serait un défaut du logiciel : `LogicException` (« La facture de remplacement ne reproduit pas le ticket »), jamais un refus présenté à l'utilisateur ni un code de l'annexe B. `DerogationsDePrix::introduites(lignes du ticket, lignes de la facture)` ne trouve aucune dérogation nouvelle : une ligne reprise telle quelle garde l'accord de R2 donné à la vente du ticket, et qui transforme n'a pas besoin de l'autorisation des remises et prix.
    5. Numérotation de la facture (série des factures du magasin), puis de l'avoir (série des avoirs) ; signature de l'avoir, dont le bloc « document » porte, de façon additive, `ticket_annule` (n° du ticket) et `facture_de_remplacement` (n° de la facture), déduits des colonnes (lignes de l'avoir, `factures.ticket_transforme_id`) et recalculés de même par le vérificateur ; puis signature de la facture.
    6. Imputation du crédit de l'avoir en totalité sur la facture de remplacement, faite par l'action elle-même, avant tout autre crédit : c'est la seule imputation entre deux clients (l'avoir est au client comptoir, la facture au particulier), reconnue par le vérificateur par `factures.ticket_transforme_id`. La facture est payée en totalité par ce crédit, et l'avoir n'en garde aucun.
    7. Aucun mouvement de stock, aucun paiement ni encaissement.
  - Écouteurs (3.14) : `RestaurerStockApresEmissionAvoir` et `ImputerAvoirSurSesFacturesApresEmission` n'agissent pas sur un avoir de motif `annulation_pour_facture` ; `DecrementerStockApresEmissionFacture` et `AppliquerAvoirDisponibleApresEmissionFacture` n'agissent pas sur une facture dont `ticket_transforme_id` est renseigné : aucun autre avoir disponible du particulier ne s'impute sur sa facture de remplacement, et le stock ne bouge pas. `ImputerAvoirSurSesFacturesAction`, `AppliquerAvoirsDisponiblesAction` et la recherche d'un bon d'avoir en caisse ignorent les avoirs d'annulation.
  - `ContenuFiscal`, `VerificateurIntegrite` : clés additives de 3.12 (facture : `facture_demandee`, `ticket_transforme_id` ; avoir : `motif`, et pour un avoir d'annulation `ticket_annule` et `facture_de_remplacement`).
  - PDF et fiche de la facture : « Facture » avec toutes ses mentions, dont la date de la vente (celle du ticket), différente de la date d'émission ; « Payé par l'avoir n° AV-… (annulation du ticket n° TK-…) » ; « Remplace le ticket TK-… ».
  - PDF, duplicata et fiche de l'avoir : « Annulation du ticket n° TK-… ; permet d'établir la facture n° FAC-… », lus dans son bloc signé.
  - Si le lot A7 a créé une colonne qui marque l'avoir de régularisation, elle est remplacée par `avoirs.motif = regularisation` et son code suit.
- **Migrations** : `factures` (`facture_demandee`, `ticket_transforme_id`) et `avoirs` (`motif`) de 3.11 (lot C-b).
- **Écrans** : caisse (case et client) ; formulaire de facture (case pour un particulier) ; fiche d'un ticket émis : bouton « Transformer en facture » (fenêtre des coordonnées seulement), lien « Transformé en facture FAC-… » ; fiche de la facture : « Remplace le ticket TK-… » ; fiche de l'avoir : la mention qui cite le ticket et la facture.
- **Tests nommés** : V25 ; `Feature/Facturation/FactureDemandeeTest` : `test_une_facture_demandee_en_caisse_exige_le_nom_et_l_adresse_du_client`, `test_une_facture_demandee_prend_un_numero_de_la_serie_des_factures` (FAC-LYON1-2026-000001), `test_une_facture_demandee_s_intitule_facture_et_porte_l_identite_legale_complete` (et ne porte ni pénalités ni indemnité forfaitaire), `test_une_facture_demandee_reste_une_vente_a_un_particulier_calculee_article_par_article` (66,66 / 13,34 / 80,00), `test_une_facture_demandee_entre_dans_la_cloture_du_jour`, `test_une_facture_demandee_n_est_jamais_transmise_comme_facture_electronique`, `test_sans_la_case_la_vente_reste_un_ticket_de_caisse`, `test_la_case_facture_demandee_est_signee` ; `Feature/Facturation/TransformerEnFactureTest` (ticket de 2 Câbles, 80,00 € payés en espèces) :
  - `test_un_ticket_paye_se_transforme_en_facture_payee_par_l_avoir_d_annulation` (avoir −66,66 / −13,34 / −80,00 ; facture 66,66 / 13,34 / 80,00, payée ; mention « Payé par l'avoir n° AV-LYON1-2026-000001 (annulation du ticket n° TK-LYON1-2026-000001) » ; date de la vente du ticket imprimée) ;
  - `test_l_avoir_d_annulation_cite_le_ticket_et_la_facture_qu_il_permet_d_etablir` (PDF, duplicata et fiche : « Annulation du ticket n° TK-LYON1-2026-000001 ; permet d'établir la facture n° FAC-LYON1-2026-000001 » ; clés `ticket_annule` et `facture_de_remplacement` dans le bloc signé ; vérificateur sans anomalie) ;
  - `test_la_facture_reproduit_le_ticket_meme_apres_un_changement_de_fiche_ou_de_regime` (après le ticket : prix du Câble 40,00 → 45,00 €, taux 20 → 10 %, puis société passée en franchise ; facture : mêmes lignes, prix, taux et totaux que le ticket) ;
  - `test_un_particulier_qui_a_deja_un_avoir_disponible_voit_sa_facture_payee_par_l_avoir_d_annulation_seul` (crédit disponible de 10,00 € d'un avoir antérieur : la facture est payée par les 80,00 € de l'avoir d'annulation, les 10,00 € restent disponibles, l'avoir d'annulation n'a plus de crédit) ;
  - `test_un_ticket_avec_une_remise_se_transforme_par_un_vendeur_sans_l_autorisation_des_remises` (ticket avec une remise de 10 % accordée par le responsable ; vendeur qui a `facture.emettre` et `avoir.sur_ticket` sans l'autorisation de R2 : facture émise, mêmes lignes, aucune dérogation enregistrée) ;
  - `test_transformer_un_ticket_respecte_une_caisse_a_la_fois` (caisse de la personne ouverte dans un autre magasin : refus comme pour toute émission) ;
  - `test_la_transformation_ne_bouge_pas_le_stock` (stock de 10, 8 après le ticket, 8 après la transformation) ;
  - `test_la_compensation_n_est_ni_un_encaissement_ni_un_paiement` ;
  - `test_les_deux_documents_sont_lies_dans_les_deux_sens` ;
  - `test_le_credit_de_l_avoir_d_annulation_n_est_jamais_un_bon_d_avoir` ;
  - `test_un_ticket_deja_transforme_ne_se_transforme_pas_une_seconde_fois` ;
  - `test_un_ticket_repris_en_partie_par_un_avoir_ne_se_transforme_pas` ;
  - `test_un_ticket_qui_n_est_pas_entierement_paye_ne_se_transforme_pas` ;
  - `test_la_transformation_est_atomique_et_signee` ;
  - `test_une_transformation_rejouee_avec_le_meme_jeton_rend_la_meme_facture` ;
  - `test_le_total_des_ventes_aux_particuliers_de_la_journee_ne_change_pas` ;
  - `test_transformer_un_ticket_exige_les_droits_d_emettre_et_de_faire_un_avoir_sur_un_ticket` (sans `avoir.creer` : accepté ; sans `avoir.sur_ticket` ou sans `facture.emettre` : refusé) ;
  - `test_un_ticket_d_un_autre_magasin_est_introuvable`.
- **Critères de fin** : `git grep -n "facture_demandee" database/migrations` et `git grep -n "ticket_transforme_id" database/migrations` trouvent les colonnes ; `git grep -n "annulation_pour_facture" app` trouve l'action, les écouteurs de stock et d'imputation, `AppliquerAvoirsDisponiblesAction` et la recherche du bon d'avoir ; `git grep -n "transformation_calcul_change" app tests` vide ; aucune autre colonne ne marque un avoir de régularisation.
- **Points de relecture** : une vente comptée deux fois dans la clôture ou les déclarations ; un avoir d'annulation utilisable comme bon d'avoir ; un autre crédit imputé sur la facture de remplacement, ou un crédit de l'avoir d'annulation resté disponible ; un mouvement de stock ; un ticket repris en partie transformé ; une facture recalculée avec la fiche ou le régime du jour ; une dérogation exigée pour une ligne reprise telle quelle ; un refus « calcul changé » présenté à l'utilisateur ; un avoir qui ne cite pas la facture qu'il permet d'établir ; une mention réservée aux professionnels imprimée pour un particulier.
- **Ne doit pas changer** : la série et la numérotation des tickets ; les montants ; les totaux d'une journée sans transformation.

### 5.7 Lot M4 : écrans de vente

- **Objectif** : le panneau « Régime de TVA de cette vente » et ses questions ; l'application d'office et le choix imposé ; les écrans de caisse, de devis, d'avoir et de fiches ; TVA4, TVA5, TVA10, O3 ; les particularités fiscales ; le relevé du mois ; les preuves attendues lues dans la table.
- **Décisions** : TVA2, TVA3, TVA4, TVA5, TVA6 (bouton), TVA7 (relevé), TVA9 (recherche de fin), TVA10 (ventes) ; O3 ; 3.15, 3.16.
- **Fichiers et classes** : `app/Filament/Concerns/AfficheLeRegimeDeTva.php` (remplace `ChoisitLaNatureDeLOperation`) ; formulaires de `FactureResource` et `DevisResource` ; page de caisse (information et avertissement de 3.16.6) ; `AvoirResource` (factures compatibles) ; `ClientResource` (section « Fiscalité », bouton Sirene, liste de pays nommés) ; `ProduitResource` et `ServiceResource` (taux 0 refusé, particularité fiscale) ; `Enums/ParticulariteFiscale` ; `MoteurTva` (branche « appliquée d'office », `tva.choix_du_regime_requis`, prix des services sans TVA française, taux 0 en filet de sécurité, prestations d'une société en franchise, avertissements de 3.19, écart élargi aux taux, à la nature et aux montants) ; `CollecteurFaitsDeVente::suggestionDeSortie()` ; `EmettreFactureAction` (choix du régime et confirmations de la fenêtre « Émettre » reçus avec la demande et compris dans le contenu du jeton ; « Mettre à jour et émettre » enregistre le brouillon recalculé puis émet) ; `PreuvesRequises` (table datée ; `typesAdmis()` supprimée) ; `app/Domain/Facturation/Services/ReleveDuMois.php` et sa page (magasin) ; `app/Domain/Facturation/Services/ReleveFiscalDeLaSociete.php` et sa page (espace de la société) ; avertissement à l'envoi d'un devis ; section « Régime de TVA » de la fiche d'un document émis.
- **Migrations** : `produits.particularite_fiscale` (3.11).
- **Écrans** : 3.16 entier.
- **Tests nommés** : V05 à V14, V27, V33, V34, V37 à V41 ; `Feature/Tva/EmissionTvaTest` (deux tests M4) ; `Feature/Tva/ReleveDuMoisTest` (cinq tests) ; `Feature/Tva/PreuvesAttenduesTest::test_les_preuves_attendues_se_lisent_dans_la_table_datee` ; écrans M4 de 3.23.8 ; `IsolationTvaTest` (deux tests M4) ; `ClotureCaisseResourceTest` (ligne 429 de l'instantané : 5,5 % au lieu de 0 %, annexe A).
- **Critères de fin** : `git grep -n -E "ChoisitLaNatureDeLOperation|typesAdmis" app tests` vide ; `git grep -n -i -E "carte.?cadeau|bon.?d.?achat|bon_d_achat|usages multiples|256 ter" app resources database` vide ; `git grep -n "Select::make('nature_operation')" app/Filament` vide.
- **Points de relecture** : une question pour une vente en France ; une bascule sans total affiché ; un code technique à l'écran ; un refus découvert à la caisse ; une exonération forcée par une requête Livewire fabriquée ; une autoliquidation comptée comme une dérogation de prix.
- **Ne doit pas changer** : les montants des ventes en France ; le formulaire d'un particulier ou d'un professionnel établi en France (aucun champ de plus) ; les messages des conditions.

### 5.8 Lot M8 : ventes à distance

- **Objectif** : compteur de la société, valeurs de départ, inscription à l'OSS, information « seuil non atteint » sous 9 000 €, avertissement dès 9 000 €, refus au-delà de 10 000 €.
- **Décisions** : TVA7, TVA8 ; 3.15.7.
- **Fichiers et classes** : `app/Domain/Facturation/Services/CompteurDesVentesADistance.php` (seule lecture hors du magasin pour ce compteur, sur le modèle de `Societe::aVenduUneFois()`) ; `CollecteurFaitsDeLaSociete` (compteur et date d'inscription à l'OSS) ; `MoteurTva` (étape 9 de 3.5) ; `EmettreFactureAction` (verrou de la ligne `societes` en `FOR NO KEY UPDATE`, à sa place dans l'ordre du lot A4, et refus d'un brouillon changé entre-temps, 3.15.7) ; `ModifierParametresFiscauxSocieteAction` et page `ParametresFiscauxSociete` (valeurs de départ, OSS ; JET 270 ; audit) ; panneau (état du seuil) ; tableau de bord de la société (montant, pour qui peut le voir).
- **Migrations** : `societes` (quatre colonnes de 3.11, lot M8).
- **Écrans** : paramètres fiscaux de la société (année de départ, total de l'année précédente, total de l'année en cours avant PILIQI ; « Votre société est-elle inscrite au guichet OSS ? Depuis le … ») ; tableau de bord de la société (montant du compteur et seuil, pour le propriétaire et les administrateurs autorisés) ; panneau d'un document (état du seuil seulement).
- **Tests nommés** : V19, V19a, V19f, V20, V20a, V20f ; les tests M8 de 3.23.1 (« Autres tests de vente »), dont `test_une_vente_a_distance_et_une_validation_d_achat_du_meme_compte_ne_s_interbloquent_pas` (groupe `concurrence`, PostgreSQL) ; `IsolationTvaTest::test_le_compteur_des_ventes_a_distance_ne_lit_que_des_sommes_des_magasins_reels_de_la_societe` ; écrans M8 de 3.23.8.
- **Critères de fin** : `git grep -n "withoutGlobalScope" app/Domain/Facturation/Services/CompteurDesVentesADistance.php` trouve la lecture filtrée par `magasinsReels()` ; aucune classe autre que `CompteurDesVentesADistance` et `ReleveFiscalDeLaSociete` (lot M4) ne somme des ventes de plusieurs magasins.
- **Points de relecture** : une vente à distance non comptée ; la démonstration comptée ; un montant visible d'un vendeur ; deux émissions simultanées au-delà du seuil ; un `FOR UPDATE` sur la ligne `societes`, qui bloquerait les inscriptions au journal d'audit et interbloquerait ; « seuil non atteint » répété dans la fenêtre d'émission ou gardé parmi les avertissements ; un avoir qui ne diminue pas le compteur ; l'année lue à l'heure UTC au lieu de Paris.
- **Ne doit pas changer** : les ventes au comptoir ; les ventes à un professionnel identifié.

### 5.9 Lot M5a : régime de TVA daté (O7)

- **Objectif** : historique daté du régime de la société, lu à la date de chaque opération.
- **Décisions** : O7 ; texte de M0 (« Société et établissements »).
- **Fichiers et classes** : `app/Domain/Tenancy/Models/RegimeTvaSociete.php` (`BelongsToSociete`, `FigeLeNomDeSesAuteurs`) ; `Societe::regimeEnVigueurLe(CarbonImmutable $date): RegimeTva` et `estEnFranchiseLe(CarbonImmutable $date): bool` (dernière entrée par date puis par identifiant, de date au plus celle demandée) ; `estEnFranchiseDeTva()` supprimée, chaque appelant donne sa date (émission, opération, ou aujourd'hui pour un écran) ; `Emetteur` (régime à la date d'émission) ; `CollecteurFaitsDeLaSociete::pour()` ; `ModifierParametresFiscauxSocieteAction` (nouvelle entrée, JET 270 dans chaque magasin avec la date d'effet, audit `ParametresFiscauxModifies`, et pour une date passée la liste de `DocumentsARevoirApresUnChangementDeRegime`) ; `app/Domain/Tenancy/Services/DocumentsARevoirApresUnChangementDeRegime.php` (lecture hors du magasin : nombre et numéros des documents émis et des achats validés depuis la date, par magasin réel) ; `Societe::parametresDeConformite()` sans `regime_tva` ; `ActionAuditee::ParametresFiscauxModifies` (champs retenus : régime et date d'effet, option pour les débits, mois de fin d'exercice) ; `ModifierParametresFiscauxSocieteAction::CHAMPS` (le régime y devient une entrée datée : régime et date d'effet) ; page `ParametresFiscauxSociete` ; `app/Console/Commands/SoumettreEReportingPeriodique.php` (commande planifiée : cadence lue par `Societe::regimeEnVigueurLe()` au jour de son exécution, sociétés retenues celles qui ont une entrée dans `regimes_tva_societe`, au lieu de `whereNotNull('regime_tva')` et de `$magasin->societe->regime_tva` ; même cadence et même résultat, son remplacement restant à l'étape 2) ; aides de test (`TestCase::reglerLaSociete()`, `MagasinFactory::avecLaSociete()`, fabrique des sociétés) écrivant l'historique quand on leur donne un régime.
- **Migrations** : `regimes_tva_societe` (3.11 ; fichier daté après `users`, par exemple `2026_10_02_100019_create_regimes_tva_societe_table.php`, s'il est libre) ; `societes.regime_tva` retirée.
- **Écrans** : paramètres fiscaux de la société : « Statut de TVA » et « Depuis le » ; historique des régimes ; après l'enregistrement d'une date passée, la liste des documents à revoir, magasin par magasin.
- **Tests nommés** : `Feature/Societe/RegimeTvaDateTest` : `test_le_regime_de_la_societe_se_lit_a_la_date_de_l_operation`, `test_un_changement_de_regime_ne_modifie_aucun_document_emis`, `test_une_date_passee_liste_les_documents_a_revoir_dans_chaque_magasin_reel` (deux magasins et la démonstration), `test_le_changement_de_regime_s_inscrit_au_jet_270_de_chaque_magasin_avec_sa_date_d_effet`, `test_l_historique_du_regime_est_en_ajout_seul`, `test_l_emetteur_fige_le_regime_en_vigueur_a_la_date_d_emission` ; `IsolationTvaTest` (deux tests M5a) ; `IsolationDesSocietesTest` (liste) ; `ContrePasserPaiementActionTest::test_aucune_requete_ne_verrouille_une_ligne_d_une_table_en_ajout_seul` (liste attendue avec `regimes_tva_societe`) ; `SoumettreEReportingPeriodiqueTest::test_la_cadence_suit_le_regime_en_vigueur_le_jour_de_l_execution` ; tests existants adaptés au régime daté, chacun au nom de O7 (annexe A, ligne M5a : `ParametresFiscauxSocieteTest`, `JournalEvenementsTest`, `MagasinDeDemonstrationTest`, `IsolationDesSocietesTest`).
- **Critères de fin** : `git grep -n "regime_tva" database/migrations/0001_01_00_000000_create_societes_table.php` vide ; `git grep -n "estEnFranchiseDeTva" app tests` vide ; `git grep -n -E "societe->regime_tva|whereNotNull\('regime_tva'\)|->update\(\['regime_tva'" app tests` vide (la clé `regime_tva` de l'émetteur signé reste, elle).
- **Points de relecture** : un régime lu à la date du jour au lieu de la date de l'opération ; une commande planifiée, une action ou un test qui lit encore la colonne retirée ; un document émis modifié ; la démonstration listée ; un magasin sans JET 270 ; une entrée de l'historique modifiable.
- **Ne doit pas changer** : les montants d'un document émis ; les autres paramètres fiscaux.

### 5.10 Lot M5b : fournisseurs et VIES des fournisseurs

- **Objectif** : établissement, franchise et pays contrôlé du fournisseur ; consultations VIES des fournisseurs.
- **Décisions** : 3.18 (fiche fournisseur) ; texte de M0 (« Clients, produits, fournisseurs »).
- **Fichiers et classes** : `Fournisseur` (calcul de `etabli_en_france` depuis le pays quand il n'est pas donné, à la création comme par l'API ; casts) ; `FournisseurResource` (pays dans une liste nommée, case d'établissement, section « Fiscalité », bouton VIES, avertissement sans SIRET) ; `ConsultationsVies::consulter(Client|Fournisseur $tiers, User $auteur)` ; `VerifierNumeroTvaAction` (fournisseur : `fournisseur.update`) ; `ConsultationVies` (relation fournisseur) ; `CollecteurFaitsDuTiers::duFournisseur()`.
- **Migrations** : `fournisseurs` et `consultations_vies` de 3.11 (exactement l'un des deux : contrainte CHECK sous PostgreSQL, déclencheurs BEFORE INSERT et BEFORE UPDATE sous SQLite, sur le modèle de `clotures_caisse_personnelles`).
- **Écrans** : fiche fournisseur de 3.18 (pays nommé, case d'établissement à côté du pays, section « Fiscalité », bouton « Vérifier sur VIES », avertissement sans SIRET).
- **Tests nommés** : A35 ; `FournisseurResourceTest` (deux tests M5b de 3.23.8) ; `ConsultationViesTest::test_une_consultation_vies_porte_exactement_un_client_ou_un_fournisseur` ; `ConsultationViesTest::test_la_verification_vies_d_un_fournisseur_exige_le_droit_de_le_modifier` ; `IsolationTvaTest::test_les_consultations_vies_d_un_fournisseur_restent_dans_son_magasin`.
- **Critères de fin** : `git grep -n -E "etabli_en_france'\)->default\(true\)" database/migrations` vide.
- **Points de relecture** : un fournisseur étranger établi en France par défaut ; une consultation sans tiers ou avec deux ; une consultation lue dans un autre magasin.
- **Ne doit pas changer** : les consultations des clients ; les factures d'achat existantes.

### 5.11 Lot M5c : moteur des achats (pur)

- **Objectif** : `MoteurTva::deciderAchat()`, sans base ni écran.
- **Décisions** : TVA10 (achats), TVA13 (TVA autoliquidée), TVA14 ; réponses à 6.1 c (TVA facturée) et à 6.1 d (frais et droits, TVA déductible partielle), sans lesquelles le lot ne commence pas (6.0) ; 3.17.1 à 3.17.4, 3.17.6 (règle de calcul) ; assurance et opération bancaire exonérées d'un prestataire non établi (3.17.2) ; nature de la pièce (3.17.1) ; 4.2 et 4.3 au niveau des catégories.
- **Fichiers et classes** : `Faits/FaitsDAchat`, `FaitsDeLigneDAchat`, `OrigineDesMarchandises`, `DeclarationEnDouane` (champs de 3.3, sauf ceux marqués d'un lot suivant) ; `Decisions/DecisionTvaAchat`, `LigneDAchatChiffree`, `TotauxDAchat` ; `Calcul/CalculDesLignesDAchat`, `Calcul/RepartitionParCumul` (`repartirParCumul(int $total, list<int> $poids): list<int>`, utilisée par la TVA autoliquidée, et selon les réponses à 6.1 c et d par la TVA facturée et les quote-parts de frais et de droits) ; `CalculateurLigne::coutUnitaireDEntree()` ; `CalculDesRetours` (retours d'achat, TVA13 et 6.1 c) ; `Enums/NatureAchat`, `TypeLigneAchat`, `RaisonDuTauxZero`, `Dedouanement`, `CategorieCharge`, `NatureDeLaPiece` ; `Regles/TableDesCharges` (libellé, nature proposée, pourcentage au niveau de la catégorie : 100 %, assurances 0 % avec `charge.tva_inattendue`, assurances et frais bancaires d'un prestataire non établi exonérés et jamais autoliquidés, salaires interdits sur une ligne, compte proposé) ; `ExigenceDuNumeroTva` (cas des achats) ; codes d'achat de l'annexe B dans `CodeDeManquement`.
- **Migrations, écrans** : aucun.
- **Tests nommés** : `Unit/Tva/MoteurAchatTest` : A01, A03, A04, A05, A09, A10, A11, A11b, A12, A14, A16, A17, A20, A20b, A21, A22, A29, A29c (selon 6.1 c), A31, A33, A34, A39, A41, A51, A52, A52b, A53, plus `test_une_prime_d_assurance_n_ouvre_aucune_deduction_meme_avec_une_tva_facturee` (100,00 € HT à 20 % : TVA 20,00, déductible 0,00, avertissement) et `test_un_loyer_a_zero_d_un_bailleur_francais_est_exonere_sans_question` ; `PureteDuMoteurTest` (deux tests M5c).
- **Critères de fin** : mêmes recherches de pureté qu'au lot M2 sur `app/Domain/Tva`.
- **Points de relecture** : le pays seul décisif ; une TVA autoliquidée lue sur la facture ; un arrondi par ligne de la TVA autoliquidée ; un arrondi qui ne suit pas la réponse à 6.1 c ou 6.1 d ; une quote-part qui ne tombe pas juste ; une prime d'assurance ou une opération bancaire exonérée autoliquidée ; une pièce « ticket ou reçu » qui ouvre une déduction.
- **Ne doit pas changer** : tout le reste (le moteur n'est pas encore appelé).

### 5.12 Lot M5d : achats, schéma, validation et décision figée

- **Objectif** : brancher le moteur sur les factures d'achat : colonnes, collecteur, validation avec tous les manquements, décision figée, journal d'audit, coût d'entrée lu par la réception ; lignes de charge.
- **Décisions** : TVA3 (achats : origine reprise de la dernière facture validée sans nouvelle confirmation, confirmée à la première facture et à chaque changement), taux facturé distinct du taux de vente, complément 3 (code) ; nature de la pièce ; 3.11 (lot M5d), 3.12 (achats), 3.17, 3.18 (API).
- **Fichiers et classes** : `FactureAchat`, `LigneAchat` (casts, colonnes nouvelles ; `coutUnitaireHt()` remplacée par `cout_unitaire_entree`) ; `app/Domain/Achats/Services/CollecteurFaitsDAchat.php` (`pourLeFormulaire`, `pourLaValidation`, `suggestionDOrigine` ; nature de la pièce : la colonne, sinon « facture » pour un fournisseur qui a un SIRET ou une facture reçue par l'API, sinon aucune) ; `ConstructeurLigneAchat` (réécrit : types de lignes, taux facturé distinct du taux dû et du taux de vente, bloc « Vente en magasin ») ; `ValiderFactureAchatAction` (moteur sous le verrou de la facture, tous les manquements par `DecisionTvaRefuseeException`, droit de créer des produits parmi eux, colonnes et `decision_tva`, audit `FactureAchatValidee`) ; `ModifierFactureAchatAction` (décision remplacée, audit `FactureAchatModifiee` avec l'ancienne, rejeu du grand livre lisant `cout_unitaire_entree`) ; `CreerFactureAchatAction` (charge utile de 3.18 ; brouillon jamais refusé pour un fait fiscal) ; `IncrementerStockApresValidationFactureAchat` (coût d'entrée ; lignes hors stock ignorées) ; `ActionAuditee` (`FactureAchatValidee`, `FactureAchatModifiee`) ; écran d'achat existant : les lignes créées portent `type_ligne = produit`, les champs nouveaux restent facultatifs (réécriture au lot M6).
- **Migrations** : `factures_achat` et `lignes_achat` de 3.11 (lot M5d).
- **Écrans** : aucun changement visible ; l'écran d'achat actuel continue de fonctionner (lignes de produit, taux facturé) ; un refus de validation affiche tous les manquements avec leurs messages ; la réécriture des écrans vient au lot M6.
- **Tests nommés** : par les actions, A01 à A05, A07 à A12 (dont A08b et A08c, sans A11b), A14 à A18, A20, A21, A22, A26 à A29 (sans A29b), A29c (selon 6.1 c), A31 à A34, A36, A38, A39, A41, A46 à A53b ; `CoutDEntreeTest` (deux tests M5d) ; `IsolationTvaTest` (deux tests M5d) ; `DecisionFigeeTest::test_une_decision_d_achat_se_relit_a_l_identique_sous_postgresql` ; comparaison avant et après des achats en France (3.23.4).
- **Critères de fin** : `git grep -n "coutUnitaireHt" app tests` vide ; `git grep -n "cout_unitaire_entree" database/migrations` trouve la colonne ; aucun coût écrit sur un mouvement de réception.
- **Points de relecture** : le pays seul décisif ; une origine reprise de la dernière facture validée qui exige une nouvelle confirmation, ou une origine changée validée sans confirmation ; une pièce sans nature validée ; un coût écrit sur un mouvement ; le régime lu à la date du jour ; une validation partielle (stock entré, numéro consommé) malgré un refus ; une décision non remplacée à la modification.
- **Ne doit pas changer** : les montants des achats en France (comparaison) ; `taux_tva` et `montant_tva` des lignes (taux et TVA facturés) ; le grand livre.

### 5.13 Lot M5e : devises, TVA étrangère, saisie manuelle, avoirs fournisseur

- **Objectif** : les cas particuliers des achats, chacun avec sa décision.
- **Décisions** : TVA11, TVA13 (retours), TVA15 = b, TVA16 ; réponse à 6.1 d (devises), sans laquelle le lot ne commence pas, et à 6.1 c (TVA facturée rendue) ; 3.17.6 à 3.17.9.
- **Fichiers et classes** : `FaitsDAchat` (devise, taux, source, saisie manuelle) ; `Faits/SaisieManuelle` ; `Regles/DevisesGerees` (devises ISO 4217 à 0 ou 2 décimales) ; moteur (conversion, TVA étrangère, saisie manuelle contrôlée en cohérence) ; `ValiderFactureAchatAction` et `ModifierFactureAchatAction` reçoivent la saisie manuelle avec la demande (autorisation `facture_achat.saisie_manuelle_tva`, motif, audit `TvaDAchatSaisieALaMain`) : rien ne se garde sur le brouillon ; une fois validée, la saisie se reprend depuis la décision figée dans tout recalcul d'une autre action (`CollecteurFaitsDAchat::pourLaValidation()`, 3.4), et une modification des lignes exige une nouvelle saisie (`tva.saisie_manuelle_a_refaire`) ; `PermissionRegistry::FACTURE_ACHAT_SAISIE_MANUELLE_TVA` (libellé « Achats et charges : saisir à la main leur TVA » ; hors du modèle de l'administrateur ; hors des modèles du vendeur et de l'observateur ; hors des consultations) ; `app/Domain/Achats/Actions/NoterLaRegularisationDeLaTvaEtrangereAction.php` (`facture_achat.valider` ; dans une transaction, sous le verrou de la facture d'achat, une facture déjà régularisée est refusée, `tva.regularisation_deja_notee` ; audit `TvaEtrangereRegularisee`) ; `CreerAvoirFournisseurAction`, `EmettreAvoirFournisseurAction` (montants par `CalculDesRetours`, `decision_tva`) ; `LigneAchat::montantsDuRetour()` délègue à `CalculDesRetours`.
- **Migrations** : `factures_achat` (lot M5e) ; `avoirs_fournisseur`, `lignes_avoir_fournisseur` (3.11).
- **Écrans** : champs de devise et de taux (formulaire existant) ; bouton « Saisir la TVA à la main » après un refus pour un cas exclu, visible des membres autorisés ; action « Noter la régularisation » (la liste vient au lot M6).
- **Tests nommés** : A06, A23, A24, A25, A29b, A40, A40b, A43, A43b (montants de l'option retenue à 6.1 d), A44, A45, A45b ; `CoutDEntreeTest::test_un_retour_fournisseur_sort_au_cout_d_entree_de_sa_ligne_et_recalcule_le_cout_moyen` ; `Feature/Achats/SaisieManuelleTvaTest::test_le_modele_de_l_administrateur_ne_precoche_pas_la_saisie_manuelle`.
- **Critères de fin** : `git grep -n "FACTURE_ACHAT_SAISIE_MANUELLE_TVA" app` trouve le registre, le contrôle et l'exclusion du modèle ; aucune devise à trois décimales acceptée.
- **Points de relecture** : une saisie manuelle sans autorisation ou sans motif ; une saisie validée perdue ou remplacée en silence par un recalcul ; une ligne modifiée qui garde l'ancienne saisie ; une TVA étrangère déductible ou dans le coût ; une régularisation notée deux fois ; un retour qui rend plus que la part de sa ligne ; un taux de change arrondi avant la multiplication.
- **Ne doit pas changer** : les achats en euros ; les avoirs fournisseur en France (montants de A25).

### 5.14 Lot M6 : achats, écrans et API

- **Objectif** : écrans d'achat sur le moteur complet.
- **Décisions** : TVA3 (écran), taux facturé distinct du taux de vente, complément 2 (bloc) ; nature de la pièce ; question de l'exonération d'une assurance ou de frais bancaires d'un prestataire non établi ; 3.18.
- **Fichiers et classes** : `AjouteDesLignesDAchat` (réécrit : deux blocs, taux facturé lié au taux de vente, question « Pourquoi ? », question de l'exonération, taux inhabituel, « Ajouter des frais ou une charge ») ; `SaisitLesPrixDeLigne` (totaux) ; `FactureAchatResource` (en-tête avec la nature de la pièce, totaux, panneau, mentions attendues en rappel) ; `app/Domain/Tva/Calcul/BeneficeUnitaire.php` (bénéfice sur le coût d'entrée et la franchise donnés par le moteur) ; liste « TVA étrangère à régulariser » (filtre, badge, notification aux membres autorisés à valider).
- **Migrations** : aucune.
- **Écrans** : 3.18 entier (sauf la fiche fournisseur, livrée au lot M5b).
- **Tests nommés** : écrans M6 de 3.23.8 ; `AucunCodeTechniqueTest` étendu à la facture d'achat ; `FactureAchatResourceTest` réécrit pour la règle du taux facturé (annexe A).
- **Critères de fin** : aucun calcul de TVA ni de bénéfice dans `app/Filament` (`git grep -n -E "\* \(1 \+|/ \(1 \+|taux_tva / 100" app/Filament` vide).
- **Points de relecture** : un champ à remplir de plus pour un achat français (la nature de la pièce, préremplie « facture » pour un fournisseur qui a un SIRET, reste repliée) ; un écran qui calcule une TVA ; un fournisseur étranger traité comme établi en France ; le taux de vente réécrit par le taux facturé.
- **Ne doit pas changer** : le parcours d'un achat ordinaire en France (mêmes champs à remplir, une ligne de totaux).

### 5.15 Lot CH1 : pièces justificatives

- **Objectif** : joindre des pièces chiffrées aux factures d'achat, contrôlées, tracées, jamais supprimées en base ; dire que la pièce est une copie de consultation ; remplacer une pièce déposée par erreur, avec un motif.
- **Décisions** : décision de calendrier des charges (pièces justificatives de l'étape 8 avancées) ; 4.6 ; art. L123-22 du Code de commerce, L102 B et A102 B-2 du LPF (copie de consultation) ; RGPD, art. 5-1-c, 5-1-d et 17 (remplacement d'une pièce déposée par erreur).
- **Fichiers et classes** : `app/Domain/Achats/Models/PieceJustificative.php` (`BelongsToMagasin`, `FigeLeNomDeSesAuteurs` ; garde du modèle : seuls changent, une fois, `retiree_le`, `retiree_par`, `retiree_par_nom` (sur un brouillon), `remplacement_le`, `remplacement_par`, `remplacement_par_nom`, `remplacement_motif`, `remplacante_id` (partout), et `chemin`, mis à nul) ; `app/Domain/Achats/Services/PiecesJustificatives.php` (rangement chiffré, déchiffrement, effacement du fichier après la transaction, reconnaissance des types sur le contenu, analyse des XML sans DOCTYPE ni ressource externe) ; `AjouterUnePieceJustificativeAction`, `RetirerUnePieceJustificativeAction`, `RemplacerUnePieceJustificativeAction` ; `app/Domain/Achats/Enums/MotifDeRemplacementDePiece.php` ; `config/filesystems.php` (disque `justificatifs`) ; `ActionAuditee` (`PieceJustificativeAjoutee`, `PieceJustificativeRetiree`, `PieceJustificativeRemplacee`) ; `FactureAchatResource` (section des pièces, texte de la copie de consultation, téléchargement, remplacement) ; codes `piece.*` dans `CodeDeManquement`.
- **Migrations** : `2026_10_07_100006_create_pieces_justificatives_table.php` (le numéro `2026_10_07_100005` reste libre pour `salaires_mensuels`, lot CH4), avec les colonnes de remplacement de 4.6.
- **Écrans** : section « Pièces justificatives » de la fiche et du formulaire d'une facture d'achat (dépôt avec le texte « Copie de consultation : conservez l'original, papier ou facture électronique reçue, pendant 10 ans. », liste, téléchargement, retrait d'un brouillon avec confirmation, « Remplacer une pièce déposée par erreur » avec un motif choisi dans la liste et une confirmation).
- **Tests nommés** : `Feature/Charges/PiecesJustificativesTest` : `test_une_piece_pdf_se_joint_chiffree_avec_son_empreinte`, `test_un_fichier_d_un_type_non_admis_est_refuse_meme_avec_une_extension_pdf`, `test_un_xml_ubl_d_une_facture_electronique_se_joint_comme_piece`, `test_un_xml_cii_d_une_facture_electronique_se_joint_comme_piece`, `test_un_xml_qui_n_est_pas_une_facture_ubl_ou_cii_est_refuse`, `test_un_xml_qui_declare_une_entite_externe_est_refuse_sans_rien_charger`, `test_un_factur_x_se_joint_tel_quel_comme_un_pdf`, `test_une_piece_de_plus_de_10_mo_est_refusee`, `test_une_piece_vide_est_refusee`, `test_une_meme_piece_ne_se_joint_pas_deux_fois_au_meme_document`, `test_un_document_porte_au_plus_20_pieces`, `test_une_piece_se_retire_d_un_brouillon_et_le_retrait_est_trace`, `test_une_piece_ne_se_retire_jamais_d_un_document_valide`, `test_une_piece_deposee_par_erreur_sur_une_facture_validee_se_remplace_avec_un_motif_et_son_fichier_s_efface` (ligne gardée avec l'empreinte, `chemin` nul, audit avec l'ancienne et la nouvelle empreinte, téléchargement refusé), `test_un_remplacement_exige_un_motif_et_l_autorisation_de_valider` (vendeur sans `facture_achat.valider` et expert-comptable refusés), `test_un_document_valide_garde_toujours_une_piece`, `test_une_piece_ne_se_supprime_jamais_en_base`, `test_chaque_ajout_s_inscrit_au_journal_d_audit_sans_le_nom_du_fichier`, `test_le_texte_de_la_copie_de_consultation_s_affiche_sous_le_depot`, `test_le_telechargement_exige_le_droit_de_voir_le_document_et_sert_le_fichier_dechiffre_en_piece_jointe` (en-têtes `attachment`, `nosniff`, `no-store`), `test_l_expert_comptable_telecharge_une_piece_sans_pouvoir_en_ajouter`, `test_une_piece_reste_lisible_apres_une_rotation_de_la_cle_de_l_application` ; `IsolationTvaTest::test_une_piece_justificative_d_un_magasin_est_introuvable_dans_un_autre` ; `TenantIsolationTest` (liste).
- **Critères de fin** : `git grep -n "disk('justificatifs')" app` ne trouve que `PiecesJustificatives` ; aucun appel de suppression d'un modèle `PieceJustificative` ; `git grep -n "LIBXML_NONET" app` trouve l'analyse des XML.
- **Points de relecture** : un type deviné sur l'extension ; un XML analysé avec ses entités externes ou sa DOCTYPE ; un fichier en clair sur le disque ; un téléchargement sans contrôle de la policy ; une pièce d'un autre magasin servie par son identifiant ; un fichier orphelin quand l'écriture échoue ; un remplacement sans motif, sans droit ou sans audit ; le fichier d'une pièce remplacée encore servi ; un document validé laissé sans pièce ; la dépendance à la clé de l'application (à reporter dans le rapport de relecture pour l'étape 15).
- **Ne doit pas changer** : la validation des achats ; les autres disques.

### 5.16 Lot CH2 : charges

- **Objectif** : le type de document « charges », les précisions, l'écran des charges, les règles complètes de 4.3, la nature de la pièce appliquée aux charges, la déclaration d'opérations hors droit à déduction, les totaux et l'export.
- **Décisions** : charges (1.4) ; 4.1 à 4.5, 4.9 (autorisations de magasin), 4.11 ; art. 271 II du CGI (une pièce qui n'est pas une facture n'ouvre aucune déduction), art. 298-4-1° du CGI (carburants), art. 206 IV-2 de l'annexe II au CGI et ses exceptions, seuil des cadeaux par bénéficiaire et par an ; réponse à 6.1 d pour l'arrondi de la TVA déductible partielle (déjà connue au lot M5c).
- **Fichiers et classes** : `Enums/TypeDocumentAchat`, `Enums/PrecisionDeCharge` (les douze valeurs de 4.3) ; `FaitsDAchat::typeDeDocument`, `FaitsDeLigneDAchat::precision` et `seuilDesCadeauxConfirme`, `FaitsDeLaSociete::operationsHorsDroitADeduction` ; `TableDesCharges` complétée (4.3 : précisions admises par catégorie, pourcentages, raisons, indicateurs « à confirmer » ; nouvelle version des règles) ; `ValiderFactureAchatAction` (contrôles de 4.5 pour une charge, et date de la pièce pour toute facture qui porte une ligne de charge) ; `FactureAchatPolicy` (droits selon le type de document) ; `PermissionRegistry::CHARGE_VIEW`, `CHARGE_CREER`, `CHARGE_VALIDER` (`toutes()`, `consultations()` pour la vue, modèle de l'administrateur, libellés) ; `app/Filament/App/Resources/ChargeResource.php` (liste, formulaire, fiche, validation, correction, pièces) ; `FactureAchatResource` limitée aux marchandises ; `app/Domain/Achats/Services/TotauxDesCharges.php` et sa page avec l'export CSV (libellé « TVA déductible (droit ouvert par ces pièces) » et ligne fixe de 4.11) ; badge « Charges à valider » ; `CreerFactureAchatAction` (`type_document`, `seuil_cadeaux_confirme`) ; réglage `societes.operations_hors_droit_a_deduction` : `ModifierParametresFiscauxSocieteAction::CHAMPS`, `ActionAuditee::ParametresFiscauxModifies`, page `ParametresFiscauxSociete` (« Votre société réalise-t-elle aussi des opérations exonérées ou hors du champ de la TVA ? »), JET 270 et audit comme les autres réglages fiscaux.
- **Migrations** : `factures_achat` (`type_document`, `periode_du`, `periode_au`), `lignes_achat` (`precision_charge`, `seuil_cadeaux_confirme`) et `societes` (`operations_hors_droit_a_deduction`) (3.11).
- **Écrans** : 4.4, panneau du magasin (menu « Charges du magasin » : liste, formulaire, fiche, totaux) ; la liste des factures d'achat ne montre plus que les marchandises ; paramètres fiscaux de la société (la question des opérations hors droit à déduction).
- **Tests nommés** : CH-01 à CH-12, CH-09b, CH-11b, CH-18 à CH-21 ; `Feature/Charges/ChargesTest` : `test_une_charge_ne_se_valide_qu_avec_sa_piece_et_sa_date`, `test_une_charge_ne_porte_ni_produit_ni_frais`, `test_une_periode_incoherente_est_refusee`, `test_loyer_entretien_transport_et_autres_charges_exigent_une_precision_admise`, `test_valider_une_charge_exige_charge_valider_et_non_facture_achat_valider`, `test_une_charge_validee_se_corrige_avec_sa_decision_d_avant_au_journal_d_audit`, `test_une_validation_rejouee_avec_le_meme_jeton_ne_valide_qu_une_fois`, `test_une_ligne_de_charge_sur_une_facture_de_marchandises_compte_dans_les_charges` (et, sans date de la pièce, la facture est refusée : `charge.date_piece_absente`), `test_un_vendeur_sans_case_ne_voit_pas_les_charges_et_l_expert_comptable_les_consulte` ; `Feature/Filament/ChargeResourceTest` : `test_la_liste_des_categories_d_une_ligne_n_offre_pas_les_salaires`, `test_le_panneau_dit_la_raison_de_chaque_ligne_et_les_regles_a_confirmer`, `test_aucun_code_technique_n_apparait_sur_la_page_d_une_charge` ; `Feature/Charges/TotauxDesChargesTest::test_les_totaux_du_mois_par_categorie_des_charges_validees` (CH-02, CH-03 et CH-08 : Loyer 1 500,00 / 300,00 / 1 800,00 ; Électricité et eau 222,84 / 42,78 / 265,62 ; Transport 89,00 / 0,00 / 97,90 ; total 1 811,84 / 342,78 / 2 163,52 ; libellé « TVA déductible (droit ouvert par ces pièces) » et ligne fixe) et `::test_l_export_csv_des_totaux_porte_le_compte_propose_et_le_libelle_du_droit_a_deduction` ; `CatalogueDesReglesTest::test_les_regles_de_recuperation_des_charges_sont_datees_et_versionnees` ; `Feature/Societe/ParametresFiscauxSocieteTest::test_la_declaration_d_operations_hors_droit_a_deduction_s_enregistre_et_s_audite` ; `IsolationTvaTest::test_une_charge_d_un_magasin_est_invisible_dans_un_autre`.
- **Critères de fin** : `git grep -n "CHARGE_VIEW" app/Domain/Authorization/PermissionRegistry.php` trouve la constante dans `consultations()` ; `git grep -n "type_document" database/migrations` trouve la colonne ; `git grep -n -E "carburant_vehicule_de_tourisme|hebergement_dirigeants_personnel" app tests` vide ; `git grep -n -i "à récupérer" app resources` vide ; aucun `SUM(` sur les montants des charges.
- **Points de relecture** : une règle de 4.3 mal recopiée ou non datée ; une charge validée sans pièce ; une ligne de produit dans une charge ; un ticket ou un reçu qui ouvre une déduction ; un carburant à 80 % qui n'est ni gazole, ni essence, ni E85 ; une location ou un entretien de véhicule de tourisme déductible ; une exception de l'art. 206 IV-2 absente de sa raison ; un cadeau « jusqu'au seuil » validé sans la case du cumul ; un coefficient de déduction calculé ; une ligne de charge d'une facture de marchandises sans date ; un vendeur qui voit les charges sans case ; un total qui somme en SQL ; le libellé « à récupérer ».
- **Ne doit pas changer** : les factures de marchandises ; le moteur pour les produits.

### 5.17 Lot CH3 : charges récurrentes (seulement si l'éditeur les retient, 6.1 f)

- **Objectif** : modèles qui créent le brouillon de chaque mois, sans doublon, avec rattrapage.
- **Décisions** : réponse de l'éditeur à 6.1 f (option 1) ; 4.10.
- **Fichiers et classes** : `app/Domain/Achats/Models/ModeleChargeRecurrente.php` ; `SourceFactureAchat::Recurrente` ; `app/Console/Commands/CreerChargesRecurrentes.php` (`app:creer-charges-recurrentes`) ; planification dans `bootstrap/app.php` ; `app/Filament/App/Resources/ModeleChargeRecurrenteResource.php`.
- **Migrations** : `2026_10_06_100001_create_modeles_charges_recurrentes_table.php` ; `factures_achat.modele_charge_recurrente_id` et son index unique partiel.
- **Écrans** : « Charges récurrentes » (liste et formulaire du modèle : fournisseur, libellé, jour du mois, début, fin, actif, lignes) ; un brouillon créé par un modèle porte « Créé par la charge récurrente … ».
- **`CLAUDE.md`** : la phrase de 2.12 (« Soumis à 6.1 », 6.1 f) ; « CH3 » s'ajoute à l'ordre des lots de l'étape 1, après « CH2 ».
- **Tests nommés** : `Feature/Charges/ChargesRecurrentesTest` : `test_un_modele_cree_le_brouillon_du_mois_a_son_jour` (jour 5, exécution le 05/11/2026 : période du 01/11 au 30/11, date de la pièce 05/11/2026, lignes du modèle, source « Charge récurrente »), `test_les_mois_manques_sont_rattrapes_dans_l_ordre` (exécution le 06/11/2026 après un arrêt depuis août : septembre, octobre, novembre), `test_un_brouillon_n_est_jamais_cree_deux_fois_pour_le_meme_mois` (deux exécutions, et sous PostgreSQL deux exécutions simultanées, groupe `concurrence`), `test_un_magasin_en_lecture_seule_ne_recoit_aucun_brouillon`, `test_un_modele_inactif_ou_termine_ne_cree_rien`, `test_un_brouillon_recurrent_se_complete_et_se_valide_comme_une_charge`, `test_gerer_les_modeles_exige_le_droit_de_saisir_les_charges` ; `IsolationTvaTest::test_un_modele_recurrent_ne_cree_que_dans_son_magasin` ; `TenantIsolationTest` (liste) ; `Feature/Filament/ModeleChargeRecurrenteResourceTest::test_un_modele_se_cree_avec_ses_lignes_et_son_jour`.
- **Critères de fin** : `git grep -n "app:creer-charges-recurrentes" bootstrap/app.php` trouve la planification.
- **Points de relecture** : un doublon ; un mois sauté ; un brouillon dans un magasin fermé ou d'une autre société ; une commande qui tourne hors de `TenantContext::runAs`.
- **Ne doit pas changer** : les charges saisies à la main.

### 5.18 Lot CH4 : salaires

- **Objectif** : totaux mensuels des salaires par magasin, saisis dans l'espace de la société, vus de qui y a droit, avec un récapitulatif totalisé pour pièce.
- **Décisions** : salaires « Totaux du mois » ; 4.8, 4.9 ; RGPD, art. 4-1, 5-1-c et 9 (récapitulatif sans détail par salarié, données personnelles d'un petit effectif).
- **Fichiers et classes** : `app/Domain/Achats/Models/SalaireMensuel.php` ; `app/Domain/Tenancy/Services/SalairesDeLaSociete.php` (ordre des verrous et demande répétée de 4.8) ; `LecteurDesDroits::exigerDansLaSocieteSousVerrou()` (comme `exigerDansLaSociete()`, la ligne `users` relue en `lock('for no key update')`) ; `AutorisationsSociete::SALAIRES_GERER = 'societe.salaires.gerer'` (référentiel ; libellé « Salaires : saisir et voir les totaux mensuels ») ; page de la société `app/Filament/Societe/Pages/SalairesDuMois.php` (texte sous le dépôt de 4.6) ; `app/Filament/App/Resources/SalaireMensuelResource.php` (lecture seule) ; `app/Policies/SalaireMensuelPolicy.php` ; `ActionAuditee` (`SalairesEnregistres`, `SalairesCorriges`) ; `TotauxDesCharges` (salaires pour qui peut les voir) ; pièces sur des salaires, dont le remplacement d'une pièce déposée par erreur.
- **Migrations** : `2026_10_07_100005_create_salaires_mensuels_table.php` ; migration des pièces modifiée (`salaire_mensuel_id`, exactement l'un des deux, index unique partiel).
- **Écrans** : espace de la société, page « Salaires du mois » (4.8) ; panneau du magasin, « Salaires » en lecture seule pour qui peut les voir ; ligne « Salaires » des totaux pour qui peut les voir.
- **Tests nommés** : CH-13 ; `Feature/Charges/SalairesMensuelsTest` : `test_les_salaires_exigent_le_recapitulatif_de_paie_a_la_premiere_saisie`, `test_la_page_dit_de_ne_deposer_qu_un_recapitulatif_totalise_sans_detail_par_salarie`, `test_un_bulletin_depose_par_erreur_sur_des_salaires_se_remplace_avec_un_motif` (fichier effacé, ligne gardée, audit `PieceJustificativeRemplacee`), `test_un_mois_ne_porte_qu_une_saisie_par_magasin_et_une_correction_est_tracee`, `test_une_saisie_repetee_des_salaires_ne_reecrit_rien` (mêmes montants : aucune écriture, aucune inscription), `test_un_responsable_ne_voit_jamais_les_salaires_de_son_magasin`, `test_un_vendeur_meme_avec_toutes_les_cases_ne_voit_jamais_les_salaires`, `test_un_administrateur_sans_l_autorisation_des_salaires_ne_les_voit_ni_ne_les_saisit`, `test_un_administrateur_autorise_ne_saisit_que_les_magasins_de_sa_portee`, `test_l_expert_comptable_voit_les_salaires_sans_pouvoir_les_saisir`, `test_la_saisie_depuis_l_espace_de_la_societe_ecrit_dans_le_bon_magasin`, `test_un_mois_futur_est_refuse`, `test_les_salaires_comptent_dans_les_totaux_de_qui_peut_les_voir`, `test_la_fermeture_du_magasin_pendant_la_saisie_des_salaires_la_fait_attendre_puis_refuser` et `test_le_retrait_de_l_autorisation_des_salaires_pendant_la_saisie_la_fait_refuser` (groupe `concurrence`, PostgreSQL) ; `Feature/Authorization/LecteurDesDroitsTest::test_exiger_dans_la_societe_sous_verrou_prend_la_ligne_de_l_auteur_sans_bloquer_les_cles_etrangeres` (verrou `FOR NO KEY UPDATE` relevé ; une inscription au journal d'audit concurrente passe) ; `Feature/Filament/SalairesDuMoisTest::test_la_page_liste_les_magasins_de_la_portee` ; `IsolationTvaTest::test_les_salaires_ne_s_ecrivent_que_dans_un_magasin_de_la_societe_et_de_la_portee` ; `TenantIsolationTest` (liste).
- **Critères de fin** : `git grep -n "SALAIRES_GERER" app` trouve le registre, la policy et la page ; `SalaireMensuelPolicy` ne lit aucune constante de `PermissionRegistry` ; `git grep -n "exigerDansLaSocieteSousVerrou" app` trouve `LecteurDesDroits` et `SalairesDeLaSociete`.
- **Points de relecture** : un responsable qui voit un salaire (liste, totaux, export, pièce) ; une écriture dans un magasin hors de la portée ; une écriture sans le verrou du registre ou de l'auteur, ou dans le mauvais ordre ; une pièce de salaires retirée, ou remplacée sans motif ni audit ; un bulletin individuel accepté sans le texte qui l'interdit ; une demande répétée qui réécrit ou inscrit deux fois.
- **Ne doit pas changer** : les droits du responsable sur tout le reste du magasin.

### 5.19 Lot CH5 : charges communes

- **Objectif** : charges de la société réparties entre ses magasins réels, calculées et réparties par le moteur, lisibles depuis le panneau des magasins par qui y a droit.
- **Décisions** : charges communes « Saisie société, répartie » ; réponse à 6.1 d (arrondi de la répartition), sans laquelle le lot ne commence pas (6.0) ; réponse à 6.1 e si elle est connue, sinon les restrictions provisoires de 4.7 ; 4.7, 4.9.
- **Fichiers et classes** : modèles `app/Domain/Achats/Models/ChargeCommune.php`, `LigneChargeCommune.php` (`BelongsToSociete`), `PartChargeCommune.php` (`BelongsToMagasin`), `PieceJustificativeSociete.php` (`BelongsToSociete`) ; `MoteurTva::repartirUneChargeCommune()` et `Decisions/RepartitionDUneChargeCommune` (3.5) ; `app/Domain/Achats/Services/RepartitionDesChargesCommunes.php` (écriture des parts rendues par le moteur, relecture des parts, `detailPour(PartChargeCommune $part, User $lecteur)` ; aucun calcul) ; actions `EnregistrerChargeCommuneAction` (brouillon), `ValiderChargeCommuneAction`, `CorrigerChargeCommuneAction` (ordre des verrous, empreinte du contenu et demande répétée de 4.7) ; collecteurs (`pourUneChargeCommune`, `deLOrganisme`) ; `AutorisationsSociete::CHARGES_GERER = 'societe.charges.gerer'` (libellé « Charges communes : saisir, répartir et valider ») ; `app/Filament/Societe/Resources/ChargeCommuneResource.php` ; `app/Filament/App/Resources/PartChargeCommuneResource.php` (lecture seule, page « Détail de la charge commune ») ; `app/Policies/DetailDeChargeCommunePolicy.php` ; `TotauxDesCharges` (parts) ; `ActionAuditee` (`ChargeCommuneValidee`, `ChargeCommuneCorrigee`) ; codes `charge_commune.*` ; restrictions de 4.7 selon la réponse à 6.1 e.
- **Migrations** : `2026_10_07_100007_create_charges_communes_table.php`, `…_100008_create_lignes_charges_communes_table.php`, `…_100009_create_parts_charges_communes_table.php`, `…_100010_create_pieces_justificatives_societe_table.php`.
- **Écrans** : espace de la société, « Charges communes » (liste ; formulaire : organisme, pièce et sa nature, période, devise, lignes, magasins et pourcentages, aperçu de la répartition rendu par le moteur, pièces ; validation ; correction) ; panneau du magasin, « Parts des charges communes » en lecture seule, avec la page « Détail de la charge commune » pour qui peut la voir ; parts dans les totaux.
- **`CLAUDE.md`** : la phrase de la répartition de 2.12 selon 6.1 d ; si l'éditeur garde une restriction de 6.1 e, sa phrase dans la puce « Charges du magasin ».
- **Tests nommés** : CH-14, CH-15, CH-16, CH-17, CH-22 ; `Unit/Tva/RepartitionDUneChargeCommuneTest::test_chaque_montant_de_chaque_ligne_se_repartit_sans_ecart` (moteur pur ; montants de CH-16 pour l'option recommandée) ; `Feature/Charges/ChargesCommunesTest` : `test_une_charge_commune_ne_se_repartit_qu_entre_des_magasins_reels_ouverts` (démonstration, magasin fermé : refus), selon la réponse à 6.1 e `test_une_charge_commune_ne_se_repartit_qu_entre_des_magasins_du_meme_territoire` (Martinique avec Lyon : refus), `test_un_organisme_non_etabli_ne_facture_qu_un_service` et `test_un_cas_exclu_se_saisit_dans_un_magasin` (ou les tests de l'option retenue), `test_un_administrateur_ne_repartit_qu_entre_des_magasins_tous_dans_sa_portee`, `test_une_charge_commune_exige_sa_piece_rangee_a_la_societe`, `test_chaque_magasin_ne_voit_que_sa_part`, `test_le_proprietaire_et_l_expert_comptable_voient_le_detail_et_la_piece_depuis_le_panneau_du_magasin`, `test_un_responsable_ne_voit_que_sa_part_sans_le_detail_ni_la_piece`, `test_un_administrateur_qui_ne_couvre_pas_toute_la_repartition_ne_voit_pas_le_detail`, `test_l_espace_de_la_societe_reste_ferme_a_l_expert_comptable` (les tests du lot A4 restent inchangés), `test_la_validation_ne_se_fait_qu_une_fois_meme_deux_fois_en_meme_temps` (groupe `concurrence`, PostgreSQL), `test_une_validation_repetee_rend_la_charge_deja_validee_sans_rien_reecrire`, `test_la_validation_d_un_brouillon_change_entre_temps_est_refusee`, `test_une_correction_reecrit_les_parts_et_garde_l_ancienne_repartition_au_journal_d_audit`, `test_la_tva_d_une_charge_commune_suit_le_moteur` (organisme IE, logiciel, 900,00 € HT, trois magasins : autoliquidée 180,00 €, parts 60,00 € chacune), `test_un_magasin_d_une_autre_societe_ne_peut_pas_recevoir_de_part`, `test_une_piece_de_charge_commune_deposee_par_erreur_se_remplace_avec_un_motif`, `test_la_fermeture_d_un_magasin_pendant_la_validation_la_fait_attendre_puis_refuser` et `test_le_retrait_de_l_autorisation_des_charges_communes_pendant_la_validation_la_fait_refuser` (groupe `concurrence`, PostgreSQL) ; `IsolationTvaTest` (trois tests CH5) ; `IsolationDesSocietesTest` et `TenantIsolationTest` (listes) ; `Feature/Filament/ChargeCommuneResourceTest::test_la_repartition_s_affiche_avant_la_validation`.
- **Critères de fin** : `git grep -n "parts_charges_communes" app` ne trouve que les modèles et `RepartitionDesChargesCommunes` ; `git grep -n "repartirParCumul" app/Domain/Achats` vide (aucune part calculée hors du moteur) ; aucune écriture de part hors de `TenantContext::runAs` ; les tests du lot A4 qui ferment l'espace de la société aux experts-comptables passent sans avoir changé (`git diff` du lot vide sur leurs fichiers).
- **Points de relecture** : une part qui ne tombe pas juste ; une part calculée hors du moteur ; une part écrite dans un magasin d'une autre société ou hors de la portée ; une validation sans le verrou des registres ou de l'auteur, ou dans le mauvais ordre ; un magasin hors de la répartition, ou un responsable, qui lit le détail ou la pièce ; l'espace de la société ouvert à un expert-comptable ; une restriction provisoire écrite comme une règle dans `CLAUDE.md` ; une double validation, ou une demande répétée qui réécrit ou inscrit deux fois ; une correction qui laisse d'anciennes parts.
- **Ne doit pas changer** : les charges des magasins ; leurs totaux hors parts ; les tests du lot A4 sur l'espace de la société.

### 5.20 Lot CH6 : avoirs reçus sur des charges (seulement si l'éditeur retient l'option 1 du point b de 6.1)

- **Objectif** : un avoir d'un fournisseur sur une charge, crédité en montant.
- **Décisions** : réponse de l'éditeur au point b de 6.1 (option 1).
- **Fichiers et classes** : `AvoirFournisseur` étendu aux charges : chaque ligne désigne une ligne de charge et crédite un HT au plus égal à ce qui en reste ; TVA facturée et autoliquidée rendues par différence de cumul, plafonnées à la part de la ligne (règle de TVA13, et de la réponse à 6.1 c pour la TVA facturée) ; TVA déductible rendue au pourcentage de la ligne (6.1 d) ; aucun mouvement de stock ; pièce exigée ; validation avec `charge.valider` ; totaux négatifs au mois de l'avoir.
- **Migrations** : la migration de création de `lignes_avoir_fournisseur` reçoit ce qu'il faut pour créditer une ligne de charge en montant HT, sans quantité.
- **Écrans** : action « Saisir un avoir du fournisseur » sur une charge validée (montant HT crédité par ligne, pièce justificative).
- **Tests nommés** : `Feature/Charges/AvoirsSurChargesTest::test_un_avoir_sur_une_facture_d_electricite_se_credite_en_montant` (charge 210,50 € HT à 20 %, TVA 42,10 € ; avoir de 50,00 € HT : TVA −10,00 €, déductible −10,00 €, TTC −60,00 € ; second avoir de 160,50 € HT : TVA −32,10 €, total rendu 42,10 €) ; `::test_un_avoir_ne_credite_jamais_plus_que_la_ligne` ; `::test_un_avoir_compte_en_negatif_dans_les_totaux_de_son_mois`.
- **`CLAUDE.md`** : « CH6 » s'ajoute à l'ordre des lots de l'étape 1, après « CH5 » et avant « M7 ».
- **Critères de fin** : aucun mouvement de stock pour un avoir sur une charge ; un avoir ne crédite jamais plus que le HT restant de sa ligne.
- **Points de relecture** : une TVA rendue au-delà de la part de la ligne ; un avoir sans pièce ; un total de mois qui oublie l'avoir.
- **Ne doit pas changer** : les avoirs fournisseur des marchandises.

### 5.21 Lot M7 : coût et valeur du stock

- **Objectif** : frais rattachés, raison « importation », déclaration complétée après la validation, liste « Dédouanement à compléter », valeur du stock au coût ; dernier lot du bloc des achats.
- **Décisions** : TVA12, TVA14 (et la réponse à 6.1 d pour l'arrondi des frais, déjà connue au lot M5c) ; O1 ; 3.17.4 et 3.17.5.
- **Fichiers et classes** : `LigneAchat` (`facture_achat_rattachee_id`, règle `regleExisteDansLeMagasin()`) ; champ « Rattacher à » ; raison « importation » ; `app/Domain/Achats/Actions/CompleterLaDeclarationEnDouaneAction.php` (`facture_achat.valider`, refus après un avoir fournisseur, audit `CoutDEntreeRecalcule`, rejeu ; une saisie manuelle de la facture est reprise, 3.4) ; validation et modification d'une facture de frais : recalcul de chaque facture rattachée, anciennes comprises, dans l'ordre des verrous de 3.17.5 (la facture de frais, les factures rattachées par identifiant croissant, l'union de leurs produits par identifiant croissant, puis les recalculs), chaque saisie manuelle reprise ; liste « Dédouanement à compléter » (badge, notification) ; renommages O1 (`cout_moyen`, `cout_unitaire`, `valeurAuCout()`) dans `StockLedgerService`, `Produit`, les écrans et les tests ; `ValeurStockApercu` au coût ; sortie d'un magasin cédé au coût (lot A5) ; `BeneficeUnitaire` pour une société en franchise.
- **Migrations** : `lignes_achat` (lot M7), `produits`, `mouvements_stock` (3.11).
- **Écrans** : champ « Rattacher à » d'une ligne de frais ; réponse « Frais liés à une importation » à la question « Pourquoi cette ligne n'a pas de TVA ? » ; action « Compléter la déclaration en douane » sur une facture validée ; liste « Dédouanement à compléter » ; panneau de la facture des marchandises (frais rattachés et leur répartition) ; tuile « Valeur du stock » au coût.
- **Tests nommés** : A13, A13b, A19, A19b, A19c, A19d, A37, A42 ; `CoutDEntreeTest` (quatre tests M7) ; `IsolationTvaTest::test_des_frais_ne_se_rattachent_qu_a_une_facture_d_achat_du_meme_magasin` ; écrans M7 ; renommages dans les tests listés en annexe A.
- **Critères de fin** : `git grep -n -E "cmup_ht|cout_unitaire_ht|valeurAuCoutMoyen" app tests database resources` vide.
- **Points de relecture** : un mouvement modifié ou ajouté ; un coût changé après un avoir fournisseur ; un rattachement entre magasins ; deux factures de frais qui s'interbloquent ; un produit verrouillé avant le dernier document ; une saisie manuelle perdue ou remplacée en silence par un recalcul ; une valeur de stock avec TVA.
- **Ne doit pas changer** : les quantités en stock ; le grand livre ; le coût moyen d'un produit sans frais, droits ni TVA non déductible ; les charges (aucune ligne de charge n'entre dans un coût).

### 5.22 Lot FIN : étape 1 cochée

- **Objectif** : clore l'étape 1.
- **Actions** : vérifier que les lots M0 à M7 (CH3 et CH6 s'ils ont été retenus) sont commités, poussés, la CI verte ; que chaque question de 6.1 dont un lot dépendait a sa réponse appliquée et citée dans un commit ; `php artisan migrate:fresh`, les deux suites, `echo $?` à 0 ; dans `CLAUDE.md`, l'étape 1 passe à `[x]` et sa fin (« dans l'ordre du plan d'exécution : … L'étape 1 n'est cochée qu'au lot FIN. ») est retirée.
- **Critères de fin** : `grep -n "^1\. \[x\]" CLAUDE.md` ; les recherches de fin de chaque lot sont toujours vides ; chaque code de l'annexe B existe dans le code (`CodeDeManquement` ou message de son action), sauf ceux d'un lot conditionnel non retenu ; chaque test nommé de ce plan existe (`php artisan test --list-tests` comparé à la liste des méthodes de 3.23, 4.13 et section 5), dans la version de l'option retenue quand il dépend d'une question de 6.1 ; aucune phrase « Soumis à 6.1 » n'est restée à son texte d'attente quand sa réponse est connue.
- **Ne doit pas changer** : le code ; seule la feuille de route change.

---

## 6. Points ouverts

Seulement ce qui demande l'éditeur ou l'expert-comptable de la société. Pour chacun : les options, celle qui est recommandée, et ce que le code fait en attendant la réponse (il ne choisit jamais une règle métier nouvelle sans elle).

### 6.0 Portes : quels lots attendent quelle réponse

Les questions de 6.1 partent à l'éditeur avec ce plan, avant le lot M0, par la session qui pilote la chaîne. Une règle de calcul ou d'arrondi ne s'applique qu'avec sa réponse (protocole, règle 4) : le lot qu'elle concerne ne commence pas sans elle, et la chaîne s'arrête à sa porte ; les lots précédents avancent. Une question de périmètre ne bloque aucun lot : le code applique la valeur sûre dite ci-dessous, et une réponse qui l'élargit donne un lot d'amendement.

| Question | Lot qui attend la réponse (porte) | En attendant |
|---|---|---|
| a. Charges d'un magasin fermé | Aucun ; un lot d'amendement après CH5 si l'éditeur retient l'option 1 | Option 2 : la règle actuelle de `CLAUDE.md` (lecture seule) |
| b. Avoirs reçus sur des charges | Le lot CH6 n'existe que si l'éditeur retient l'option 1 | Option 2 : correction tracée de la charge (CH2) |
| c. TVA facturée d'un achat | M5c (et M5e pour les retours) | M5c ne commence pas ; `CLAUDE.md` garde « puis la TVA sur ce HT » |
| d1. Frais et droits ; d3. TVA déductible partielle | M5c | M5c ne commence pas ; textes d'attente de 2.11 |
| d2. Devises | M5e | M5e ne commence pas ; texte d'attente de 2.11 |
| d4. Répartition des charges communes | CH5 | CH5 ne commence pas ; texte d'attente de 2.12 |
| e. Restrictions des charges communes | Aucune : le lot CH5 applique la réponse si elle est connue | Les trois restrictions provisoires de 4.7, jamais écrites dans `CLAUDE.md` |
| f. Charges récurrentes | Le lot CH3 n'existe que si l'éditeur retient l'option 1 | Aucun modèle récurrent |
| g. Numérisation fiscale des pièces | Aucune (ligne 15 de la feuille de route) | Copie de consultation (4.6) |

### 6.1 Pour l'éditeur

**a. Un magasin fermé ou cédé reçoit encore des factures (dernière électricité, loyer du mois de la fermeture, derniers salaires). Peut-on les enregistrer ?**
- Option 1 : enregistrer et valider les charges, les salaires et les parts de charges communes dans un magasin fermé par la société (fermeture ou cession), jamais dans un magasin suspendu par l'éditeur ni dans une société suspendue, résiliée ou révoquée ; aucune vente.
- Option 2 : garder la lecture seule ; ces charges se saisissent ailleurs, ou hors de PILIQI.
- Recommandation : option 1.
- En attendant : option 2, règle actuelle de `CLAUDE.md` : un magasin en lecture seule refuse les charges, les salaires et les parts (`salaires.magasin_ferme`, `charge_commune.magasin_ferme`), et les tests le vérifient. Si l'éditeur retient 1 : un lot d'amendement après CH5 change la puce de `CLAUDE.md` (« Société ou magasin suspendu, fermé… »), la garde des actions des charges, et ces tests.

**b. Un fournisseur émet un avoir sur une charge (trop-perçu d'énergie, remise sur un abonnement).**
- Option 1 : un avoir fournisseur lié à la charge, crédité en montant, TVA par différence de cumul, compté en négatif dans les totaux de son mois (lot CH6).
- Option 2 : seulement corriger la charge validée, sans document d'avoir.
- Recommandation : option 1.
- En attendant : option 2 (la correction tracée existe dès CH2) ; le lot CH6 n'est fait que si l'éditeur retient 1.

**c. La TVA facturée par un fournisseur établi en France, et celle de ses retours, se calcule-t-elle comme la TVA autoliquidée ?** Votre réponse TVA13 = a (« calculée sur le total de chaque taux, puis répartie entre les lignes, comme pour vos ventes ») a été appliquée à la TVA autoliquidée ; le détail « TVA facturée recalculée ligne par ligne » de la conception ne vous a jamais été montré. Exemple : lignes de 1 × 0,83 € HT et 3 × 1,11 € HT à 20 % (4,16 € HT).
- Option 1 : sur le total HT de chaque taux, arrondie une fois, répartie entre les lignes par cumul : 0,17 € + 0,66 € = 0,83 € ; un retour ne rend jamais plus que la part de sa ligne ; l'écart avec la facture du fournisseur, quand elle est connue (facture reçue par l'API ou SUPER PDP, total de contrôle saisi), est signalé.
- Option 2 : ligne par ligne, comme le code actuel : 0,17 € + 0,67 € = 0,84 € ; la TVA autoliquidée reste sur le total du taux.
- Recommandation : option 1. C'est la lettre de votre réponse TVA13, une seule règle pour toute la TVA d'un achat, la même que pour vos ventes à un professionnel, et celle de la norme des factures électroniques (règle EN 16931 BR-CO-17 : TVA d'un taux = base du taux × taux, arrondie), que suivent les factures reçues par SUPER PDP.
- Selon la réponse : texte de 2.7 et de 3.17.3 de l'option ; test A29c de l'option ; option 1 : les tests d'achat existants à plusieurs lignes d'un même taux dont la TVA facturée change d'un centime sont réécrits au nom de 6.1 c (annexe A) ; option 2 : aucun test existant ne change.

**d. Quatre arrondis ne figuraient ni dans les questions TVA14 et TVA16, ni dans votre décision des charges communes.**
- d1, frais d'acheminement et droits répartis entre les produits d'une facture (TVA14 = a, au prorata du HT net) :
  - option 1 : par cumul au centime, chaque ligne recevant la part arrondie du cumul jusqu'à elle, moins celle des lignes précédentes, le total tombant juste ; coût unitaire d'entrée arrondi une seule fois, au dix-millième (précision actuelle des coûts) ;
  - option 2 : chaque quote-part arrondie séparément au centime, l'écart d'arrondi porté sur la ligne au plus grand HT ; même coût au dix-millième.
  - Recommandation : option 1 (la règle de vos ventes, R4, appliquée aux frais).
- d2, devises (TVA16 = a, taux saisi sur la facture) :
  - option 1 : HT de chaque ligne en devise × taux, arrondi une fois au centime, puis la TVA comme pour un achat en euros ; coût unitaire net = PU HT en devise × taux × (1 − remise) × (1 − escompte), arrondi une fois au dix-millième ;
  - option 2 : conversion des totaux de la facture (HT et TVA en devise × taux, arrondis une fois), répartis ensuite sur les lignes par cumul.
  - Recommandation : option 1 (chaque ligne a son montant en euros, et la TVA suit la règle des achats en euros).
- d3, TVA déductible partielle (le gazole, l'essence et l'E85 d'un véhicule de tourisme, 80 %, seul cas aujourd'hui) :
  - option 1 : arrondi(TVA de la ligne × pourcentage), un seul arrondi au centime, la moitié en s'éloignant de zéro ; pour un retour, la même règle sur la TVA cumulée rendue, par différence de cumul ;
  - option 2 : pourcentage appliqué au total de la TVA de la pièce soumise à ce pourcentage, arrondi une fois, réparti sur ses lignes par cumul.
  - Recommandation : option 1 (lisible ligne par ligne ; les deux options ne diffèrent qu'avec plusieurs lignes à 80 % sur une même pièce).
- d4, répartition d'une charge commune entre les magasins (parts égales ou pourcentages) :
  - option 1 : chaque montant de chaque ligne (HT, TVA facturée, TVA autoliquidée, TVA déductible) réparti par cumul au centime entre les magasins pris par identifiant croissant, la somme des parts égale exactement la ligne (1 000,00 € en trois parts égales : 333,33 + 333,34 + 333,33) ;
  - option 2 : chaque part arrondie séparément au centime, l'écart d'arrondi porté sur la plus grande part, le premier magasin à égalité (333,34 + 333,33 + 333,33).
  - Recommandation : option 1 (même mécanisme que vos ventes et que les frais).
- En attendant : M5c (d1, d3), M5e (d2) et CH5 (d4) ne commencent pas ; `CLAUDE.md` garde les textes d'attente (2.11, 2.12).
- Selon la réponse : textes de 2.11, 2.12, 3.17.3, 3.17.4, 3.17.6, 3.17.7, 4.3 et 4.7 de l'option ; tests A05, A43, A43b, CH-09, CH-14 et CH-16 dans la version de l'option (3.23.2).

**e. Trois limites des charges communes ne figurent pas dans votre décision (« parts égales ou pourcentages »). Les garder ?**
- e1, une charge commune ne se répartit qu'entre des magasins d'un même territoire de TVA (pas entre Lyon et Fort-de-France) :
  - option 1 : garder ; une société de plusieurs territoires saisit la charge dans chaque magasin, ou une charge commune par territoire ;
  - option 2 : permettre plusieurs territoires ; le taux d'une autoliquidation est alors celui de l'établissement qui reçoit le service, à confirmer par l'expert-comptable de la société, et le moteur décide la charge pour chaque territoire de la répartition.
  - Recommandation : option 1.
- e2, un organisme non établi en France ne facture, dans une charge commune, qu'un service :
  - option 1 : garder ; un bien acheté hors de France se saisit dans un magasin, où son origine se confirme (TVA3) ;
  - option 2 : permettre un bien, l'origine se saisissant dans l'espace de la société.
  - Recommandation : option 1.
- e3, aucune saisie manuelle de la TVA au niveau de la société :
  - option 1 : garder ; un cas que le moteur ne calcule pas seul se saisit dans un magasin, où l'autorisation de saisie manuelle existe ;
  - option 2 : créer une autorisation de société « saisir à la main la TVA d'une charge commune », jamais précochée.
  - Recommandation : option 1.
- En attendant : si la réponse n'est pas connue au début du lot CH5, il applique les trois restrictions comme provisoires (4.7), sans les écrire dans `CLAUDE.md`.
- Selon la réponse : option 1, `CLAUDE.md` écrit la phrase de la restriction gardée (2.12, « Soumis à 6.1 ») ; option 2 pour l'une, le lot CH5 construit la règle de l'option, ou, si la réponse vient après lui, un lot d'amendement lève la restriction, avec ses tests, et retire son code de refus.

**f. Voulez-vous des charges récurrentes (loyer, abonnements), un modèle qui crée le brouillon de chaque mois ?** Ce n'était qu'un avis de Claude.
- Option 1 : oui, lot CH3 : modèles par magasin, commande planifiée chaque jour qui crée les brouillons manquants sans doublon, rattrapage des mois manqués ; chaque brouillon se complète et se valide comme une charge.
- Option 2 : non ; chaque charge se saisit à sa réception.
- Recommandation : option 2 pour l'instant. Depuis le 1/9/2026, toute entreprise doit pouvoir recevoir des factures électroniques, et les grandes entreprises et les entreprises de taille intermédiaire (énergie, télécoms) les émettent déjà : à l'étape 2, elles arriveront seules en brouillon de charges, et un modèle récurrent créerait un doublon. À reconsidérer après l'étape 2, pour les seules charges qui n'arrivent pas par SUPER PDP (loyer d'un bailleur particulier).
- En attendant : aucun modèle récurrent ; le lot CH3 n'est fait que si l'éditeur retient 1.

**g. Une pièce déposée dans PILIQI doit-elle un jour permettre de jeter l'original papier ?**
- Option 1 : non ; la pièce reste une copie de consultation ; l'écran et la notice disent de conserver l'original pendant 10 ans.
- Option 2 : oui, à l'étape 15 : numérisation conforme à l'art. A102 B-2 du LPF (copie fidèle, couleurs comprises, en PDF ou PDF/A ; cachet serveur ou signature électronique avancée de chaque copie ; horodatage ; organisation documentée et contrôles de fidélité), ce qui suppose un prestataire de cachet électronique, donc un paquet ou un service soumis à votre accord (« Outillage »).
- Recommandation : option 1 aujourd'hui, à reconsidérer à l'étape 15 si les sociétés le demandent.
- En attendant : option 1, le comportement de ce plan (4.6) ; aucune porte. Option 2 : la ligne 15 de la feuille de route gagne le point (2.17, « Soumis à 6.1 »).

### 6.2 Pour l'expert-comptable de la société

À faire confirmer avant la mise en production. Le code applique l'option recommandée ; une règle de récupération concernée porte l'indicateur « à confirmer » (4.3), et sa confirmation se fait par une nouvelle version des règles. Une règle d'arrondi n'est jamais renvoyée ici : elle est soumise à l'éditeur (6.1 c et d).

| # | Question | Recommandation appliquée | Lot |
|---|---|---|---|
| EC1 | Règles de 4.3 : péages et parkings d'un véhicule de tourisme ; restaurant ; TVA facturée sur une prime d'assurance ; seuil des cadeaux en 2026 ; exceptions de l'art. 206 IV-2 de l'annexe II (transport collectif du personnel sous contrat permanent ; logement sur place du personnel de gardiennage, de sécurité ou de surveillance ; logement refacturé) ; électricité et GNV d'un véhicule de tourisme ; GPL ; tickets et reçus : aménagements de la restauration et des péages, seuil éventuel d'une facture simplifiée ; moment de la déduction de l'électricité. | Péages et parkings d'un véhicule de tourisme : précision « véhicule de tourisme », 0 % ; restaurant : 100 % sur facture ; TVA sur une prime d'assurance : non déductible ; seuil : 73 € TTC ; exceptions : précision « Autre », 100 %, à confirmer ; électricité et GNV : 100 %, à confirmer pour l'électricité ; GPL : « Autre », à confirmer ; ticket ou reçu : 0 % ; électricité : un bien, déductible à la facture. | M5c, CH2 |
| EC2 | Effet d'un escompte de la facture du fournisseur sur la base de la TVA déductible. | Aucun : la TVA facturée est déductible telle quelle ; l'escompte ne baisse que le coût. | M5c |
| EC3 | Catégorie de la norme EN 16931 d'une livraison vers un DOM : G ou E. | G ; la transmission reste retenue tant que la catégorie n'est pas vérifiée au bac à sable (3.15.2). | M3 |
| EC4 | Frais d'acheminement d'un achat dont les marchandises ont plusieurs taux : ventilation de leur TVA par taux. | Taux de la facture pour un achat en France ; pour une autoliquidation, taux dû en France proposé égal à celui des marchandises s'il est unique, le taux normal sinon, modifiable. | M5c |
| EC5 | Fournisseur de l'Union en franchise dans son État, ou sans n° de TVA. | Cas exclu, saisie manuelle. | M5c |
| EC6 | Acquisitions intracommunautaires et importations d'une société en franchise (régime dérogatoire sous 10 000 €, compteur de la société, e-reporting). | Refus (`tva.achat_etranger_en_franchise_non_gere`) jusqu'à la confirmation, puis lot M9. | M5c |
| EC7 | Exigibilité des services autoliquidés (date de réalisation ; l'article du CGI n'a pas été retrouvé) et de l'énergie (fin de la période facturée, acomptes). Pour les acquisitions intracommunautaires, la règle de l'art. 269-2 d du CGI (le 15 du mois suivant la livraison, ou la date de la facture si elle est antérieure) est écrite telle quelle. | Rien n'est enregistré avant l'étape 5, qui demandera la date de réception ou de réalisation ; services autoliquidés et énergie signalés « à confirmer ». | Étape 5 |
| EC8 | Monaco : risque de double déclaration si le fournisseur émet par le réseau français ; circuit d'un achat à un fournisseur monégasque livré depuis la France ; e-reporting de l'art. 290 I 4° a en plus de la facture électronique pour des biens livrés à Monaco à un client établi en France. | Circuits de 3.15.2 (V22, V22b) ; achat à un fournisseur monégasque : TVA française, circuit fixé à l'étape 2. | M3, étape 2 |
| EC9 | Catégorie des ventes aux particuliers hors TVA française (export à un particulier, franchise) dans l'e-reporting. | Vérification au bac à sable à l'étape 2 ; rien n'est enregistré avant. | Étape 2 |
| EC10 | Portée de la mention « option pour les débits » sur un document qui ne vend que des biens. | Imprimée comme aujourd'hui sur tout document hors franchise. | M2 |
| EC11 | Date du taux de change d'un achat en devise. | Taux saisi avec sa source ; pour une importation, celui de la déclaration en douane. | M5e |
| EC12 | Garantie de TVA d'un export ou d'une livraison intracommunautaire emportés par l'acquéreur. | Export emporté : vente à la TVA française (`tva.export_emporte_non_gere`) jusqu'au lot M9. | M3 |
| EC13 | Numéros définitifs du CIBS pour les mentions. | Articles du CGI imprimés jusqu'à la confirmation (admis jusqu'au 30/06/2028, O4) ; passage par une nouvelle version des règles. | M2 |
| EC14 | Conditions des dispenses de reversement de la TVA déduite sur des marchandises détruites, volées ou disparues. | Étape 7. | Étape 7 |
| EC15 | Preuve que le transport d'une importation entre dans la base de la TVA d'importation, pour la facture d'un transporteur à 0 % (art. 262 II 14° du CGI). | Avertissement quand la déclaration rattachée ne compte aucun frais accessoire. | M7 |
| EC16 | Documents émis entre le jour du dépassement du seuil de la franchise et la saisie du nouveau régime. | Liste des documents à revoir, magasin par magasin, à corriger par avoir et nouvelle facture sur son conseil. | M5a |
| EC17 | Services de l'art. 259 B vendus à un particulier domicilié hors de l'Union. | Avertissement ; marqueur « service immatériel » au lot M9 si l'éditeur le demande. | M4 |
| EC18 | Lignes de la déclaration CA3 de la notice 2026 (n° 50449#29). | Table versionnée par millésime de notice à l'étape 5. | Étape 5 |
| EC19 | Coefficient de déduction d'une société qui réalise aussi des opérations exonérées (par exemple la sous-location nue d'une partie de son local, art. 261 D 2° du CGI) ou hors du champ de la TVA (art. 205 et 206 I à III de l'annexe II au CGI). | Droit à déduction total supposé ; la société déclare ces opérations dans ses paramètres fiscaux, et chaque ligne de charge est alors signalée « à confirmer » ; aucun coefficient calculé. | CH2, étape 5 |
| EC20 | Déclaration des achats exonérés à un prestataire non établi (prime d'assurance, opération bancaire) : ligne de la CA3 ; e-reporting « in » de l'étape 2, que le chapeau de l'art. 290 I semble exclure pour les opérations exonérées par les art. 261 à 261 E. | Aucune TVA autoliquidée ni déductible (art. 261 C du CGI : l'autoliquidation ne rend pas imposable une opération exonérée) ; hors e-reporting ; ligne de la CA3 fixée à l'étape 5 sur sa réponse. | M5c, étapes 2 et 5 |

---

## Annexe A : tests existants touchés, lot par lot

Règle (0.3, point 9) : un test ne change que si la règle qu'il fixe change par une décision citée, ou si sa classe disparaît ; le message de commit le nomme, test par test, avec la décision. Les numéros de ligne viennent de l'instantané du commit 9c9ee25.

| Lot | Fichiers | Pourquoi | Ce qui change |
|---|---|---|---|
| C-a | `tests/Feature/Facturation/ContrePasserPaiementActionTest.php` : `test_le_bon_paiement_impute_le_credit_d_avoir_disponible_sur_le_reste_de_la_facture`, `test_la_contre_passation_rend_le_credit_que_le_paiement_errone_avait_impute`, `test_le_credit_d_un_avoir_deja_impute_sur_la_facture_s_y_impute_de_nouveau_apres_la_contre_passation`, `test_le_credit_solde_d_abord_la_facture_la_plus_ancienne_avec_l_avoir_le_plus_ancien`, l'aide `paiementErroneQuiImputeDuCredit()` et le docblock de la classe | Complément 1 : le crédit s'impute à la contre-passation, il n'attend plus le bon paiement | Imputations attendues à la contre-passation ; le bon paiement ne règle que ce qui reste dû |
| C-a | `ContrePasserPaiementActionTest::test_aucune_requete_ne_verrouille_une_ligne_d_une_table_en_ajout_seul` | Deux tables en ajout seul nouvelles | Liste attendue : `annulations_preuves_operation` et `suites_bordereaux_detaxe` en plus |
| C-a | `tests/Feature/Facturation/EnregistrerPaiementActionConcurrenceTest.php` (scénario de la l. 122), `tests/Feature/Filament/PaiementResourceTest.php` (l. 833), `tests/Feature/CaisseSecurisee/VerificateurIntegriteTest.php` (l. 103), `tests/Feature/Facturation/AppliquerAvoirsDisponiblesActionTest.php` (crédit rendu par une contre-passation puis pris par une facture émise ensuite) | Complément 1 | Seulement là où le test suppose le crédit en attente après une contre-passation |
| C-a | `tests/Feature/Facturation/PreuvesOperationTest.php` et tout test qui ajoute une preuve sans « établie par » | Complément 4 : « établie par » obligatoire | Émetteur fourni |
| C-a | Tests où un vendeur fait un retour ou un avoir sur un ticket (`AvoirActionsTest`, `AvoirResourceTest`, `CaisseTest` s'il y en a) ; tests qui figent la liste des autorisations ou le modèle de l'administrateur | Complément 16 | Autorisation `avoir.sur_ticket` donnée là où le test veut un vendeur autorisé (elle suffit seule : `avoir.creer` n'y est plus nécessaire) ; liste des autorisations complétée |
| M1 | `tests/Unit/CalculateurLigneTest.php` (déplacé dans `tests/Unit/Tva/`), `tests/Feature/Facturation/ClotureAutomatiqueCaisseTest.php`, `tests/Feature/Facturation/CloturerLesJourneesActionTest.php` | `CalculateurLigne` déménage | Ligne `use` (et espace de noms du test déplacé) ; aucune assertion |
| M1 | `tests/Feature/Facturation/NatureOperationTest.php` : jeux « n° vérifié la veille » et « n° inconnu de VIES aujourd'hui » de `test_une_livraison_intracommunautaire_sans_toutes_ses_conditions_est_refusee` (l. 326-327, 339), `test_une_verification_vies_invalide_plus_recente_du_meme_jour_refuse_l_exoneration` (l. 365) ; tout autre test, listé au début du lot, qui émet une nature exigeant VIES sans vérification valide du jour | La consultation VIES quitte l'écran pour l'émission (3.8, 1.6) : ces tests, qui appellent l'action sans `Http::fake`, feraient une requête que `Http::preventStrayRequests()` refuse | Mise en place seulement : `FauxRegistreVies` (ou un `Http::fake`) répondant « inconnu » ; mêmes messages attendus, aucune assertion changée |
| M2 | `tests/Feature/Facturation/NatureOperationTest.php` | `NatureOperationRefuseeException` remplacée | Classe de l'exception attendue, à un endroit (l. 123) ; messages identiques |
| M2 | `tests/Feature/Facturation/ConstructeurLigneDocumentTest.php` (l. 35, 58, 79, 99) | `ContexteDeVente` disparaît | Faits du moteur ; mêmes montants |
| M2 | `tests/Unit/TerritoireFiscalTest.php`, `tests/Unit/TerritoireTvaTest.php` | Classes absorbées | Mêmes cas dans `tests/Unit/Tva/GeographieTvaTest.php` ; fichiers supprimés avec leurs classes |
| M2 | `tests/Feature/Contacts/ConsultationViesTest.php`, `tests/Feature/Filament/ServiceResourceTest.php` | Docblocks qui citent `ControleNatureOperation` | Commentaires |
| M3 | `tests/Feature/CaisseSecurisee/EmissionSigneeTest.php`, `VerificateurIntegriteTest.php`, `tests/Feature/Facturation/FacturePdfGeneratorTest.php`, `AvoirPdfGeneratorTest.php` | Format signé changé une fois | Clés nouvelles ; aucune assertion perdue |
| M3 | `PreuvesOperationTest.php` (l. 93, 97), `NatureOperationTest.php`, `tests/Feature/Filament/DevisResourceTest.php`, `FactureResourceTest.php` | `transport_par` devient `sortie_biens` | Nom et valeurs du fait |
| M3 | `NatureOperationTest.php` (l. 415) | Message faux sur le lieu fixé par la loi | Nouveau message |
| M3 | `tests/Feature/Facturation/TypeClientFigeTest.php` (l. 96, 118), `tests/Feature/Facturation/TransmissionPdpTest.php` | `relevantDeLeInvoicing()` disparaît | Circuit figé ; transmission sur les trois conditions et les catégories prises en charge |
| M3 | `tests/Feature/Filament/ClientResourceTest.php` | Gardé | Un test de plus (Saint-Barthélemy) |
| C-b | Tests qui supposent qu'une vente à un particulier est toujours un ticket ; tests du lot A7 qui lisent une colonne de régularisation remplacée par `avoirs.motif` | Compléments 13 à 15 | Série selon la case ; motif de l'avoir |
| M4 | `FactureResourceTest.php` (environ 34 tests de TVA), `DevisResourceTest.php`, `tests/Feature/Filament/CaisseTest.php`, `ProduitResourceTest.php`, `AvoirResourceTest.php` | La liste des natures cède la place au panneau ; particularités ; taux 0 refusé | Gestes et questions à la place de la liste |
| M4 | `tests/Feature/Filament/ClotureCaisseResourceTest.php` (l. 429) | Seul test qui émet en France un produit à 0 % (TVA5) | Un second taux légal (5,5 %), pour garder sa ventilation à deux taux |
| M4 | `PreuvesOperationTest.php` | `typesAdmis()` supprimée | Preuves attendues lues dans la table |
| M8 | `tests/Feature/Societe/ParametresFiscauxSocieteTest.php` | Valeurs de départ et OSS | Champs nouveaux |
| M5a | `tests/TestCase.php` (`reglerLaSociete()`, l. 210-217), `database/factories/MagasinFactory.php` (`avecLaSociete()`, l. 86-89), la fabrique des sociétés ; tests qui appellent `estEnFranchiseDeTva()` | `societes.regime_tva` disparaît (O7) | Les aides écrivent l'historique daté ; les fichiers qui règlent le régime par elles ne changent pas |
| M5a | `ParametresFiscauxSocieteTest.php` (l. 54-64, 79-92, 100-104, 119-130, 170-176) | O7 : le régime devient une entrée datée | Formulaire « Statut de TVA » et « Depuis le » ; lecture de l'historique au lieu de la colonne ; date d'effet attendue dans le JET 270 ; l. 176 : l'écriture directe en base vise `regimes_tva_societe` |
| M5a | `tests/Feature/CaisseSecurisee/JournalEvenementsTest.php` (`test_un_reglage_d_une_societe_ne_se_modifie_pas_depuis_le_magasin_d_une_autre_societe`, l. 454-459) | O7 | Le changement tenté hors du contexte de la société crée une entrée datée au lieu de modifier la colonne ; même refus attendu (« Les réglages d'une société ne se modifient que dans son propre contexte. ») ; l'assertion vérifie qu'aucune entrée n'existe |
| M5a | `tests/Feature/Societe/MagasinDeDemonstrationTest.php` (l. 211) | O7 | L'action reçoit le régime et sa date d'effet |
| M5a | `tests/Feature/Tenancy/IsolationDesSocietesTest.php` (`test_aucun_reglage_d_une_autre_societe_n_est_lu_ni_revele`, l. 355) | O7 | Lit l'historique du régime de la nouvelle société (aucune entrée), pour que l'assertion garde son sens au lieu de devenir vraie par l'absence de la colonne |
| M5a | `tests/Feature/Facturation/SoumettreEReportingPeriodiqueTest.php` | La commande lit le régime en vigueur (M5a) | Inchangé (régime réglé par `avecLaSociete()`, qui écrit l'historique) ; un test ajouté (5.9) |
| M5a | `ContrePasserPaiementActionTest::test_aucune_requete_ne_verrouille_une_ligne_d_une_table_en_ajout_seul` | Nouvelle table en ajout seul | `regimes_tva_societe` dans la liste attendue |
| M5b | `tests/Feature/Filament/FournisseurResourceTest.php`, `ConsultationViesTest.php` | Fiche fournisseur ; consultations des fournisseurs | Champs nouveaux ; tests ajoutés |
| M5c, M5d | Selon la réponse à 6.1 c, option 1 seulement : les tests d'achat existants à plusieurs lignes d'un même taux dont la TVA facturée change d'un centime (à lister au début de M5c, parmi `FactureAchatActionsTest`, `AvoirFournisseurActionsTest`, `FactureAchatResourceTest`, `StockLedgerServiceTest`) | 6.1 c | Montant de TVA facturée attendu, au nom de la réponse |
| M5d | `tests/Feature/Achats/FactureAchatActionsTest.php`, `AvoirFournisseurActionsTest.php` | — | Inchangés (`taux_tva` garde son sens sur la ligne d'achat ; la correction du taux de vente passe toujours par les attributs du produit ; la fabrique des fournisseurs donne un SIRET, donc la nature de la pièce « facture » d'office), sauf une lecture de `coutUnitaireHt()` remplacée par la colonne |
| M5d | Tests d'achat dont le fournisseur est créé sans SIRET et dont la facture se valide | Une pièce sans nature ne se valide plus (art. 271 II du CGI, 3.17.1) | Nature de la pièce « facture » donnée à la facture |
| M5e, CH2, CH4, CH5 | Tests qui figent la liste des autorisations de magasin ou de société, ou le modèle de l'administrateur | Autorisations nouvelles | Listes complétées |
| M6 | `tests/Feature/Filament/FactureAchatResourceTest.php` | Règle du taux facturé : le taux de la ligne n'est plus celui de la fiche | Tests réécrits pour la règle nouvelle |
| M7 | `tests/Feature/Filament/ValeurStockWidgetTest.php` (`test_la_valeur_du_stock_est_sa_quantite_fois_son_cmup`) | O1 | Valeur au coût |
| M7 | Les 9 fichiers qui lisent `cmup_ht` (`StockLedgerServiceTest`, `FactureAchatActionsTest`, `AvoirFournisseurActionsTest`, `ProduitTest`, `AvoirFournisseurResourceTest`, `FactureAchatResourceTest`, `FournisseurProduitResourceTest`, `ProduitResourceTest`, `ValeurStockWidgetTest`), les 2 qui lisent `cout_unitaire_ht`, et `CalculateurLigneTest` (`valeurAuCoutMoyen`) | O1 | Noms ; la valeur sans TVA |
| CH1 à CH5 | `tests/Feature/Tenancy/TenantIsolationTest.php`, `tests/Feature/Tenancy/IsolationDesSocietesTest.php` | Modèles nouveaux | Listes complétées |
| CH2 | `ParametresFiscauxSocieteTest.php` (s'il fige la liste des champs du réglage ou de l'audit) | Réglage nouveau | Champ `operations_hors_droit_a_deduction` ajouté |
| Jamais | `RepartitionTvaParCumulTest`, `AvoirActionsTest` (messages de la l. 302 et suivantes), `FranchiseEnBaseTest` (l. 292), `CreerFactureActionTest`, `DevisConversionTest`, `StockLedgerServiceTest` (hors renommages de O1 et réponse 1 à 6.1 c) ; les tests existants de `BordereauDetaxeTest` ; les tests du lot A4 qui ferment l'espace de la société aux experts-comptables | — | Verts à chaque lot, sans changement |

L'étape 2 supprimera `GenererSoumissionEReportingActionTest`, `SoumettreEReportingPeriodiqueTest` et `tests/Unit/CalculateurPeriodeEReportingTest.php` avec leur fonctionnalité (complément 11) : rien dans ce plan.

---

## Annexe B : codes et messages

Les codes du moteur, de la validation des achats et des charges, des pièces, des salaires et des charges communes sont les cas de l'énumération `CodeDeManquement` (domaine `Tva`, créée au lot M2, complétée lot par lot), dont `message()` rend le texte ci-dessous ; les codes du lot C-a sont des messages de leurs actions (le lot C-a précède le domaine `Tva`). Les accolades désignent une valeur insérée. Aucun message ne contient de code technique.

| Code | Gravité | Message | Lot |
|---|---|---|---|
| `tva.conditions_non_reunies` | Bloquant | « Impossible d'émettre en « {nature} » : {conditions manquantes}. Choisissez « Vente en France » (TVA française) si ces conditions ne sont pas remplies. » (message actuel) | M2 |
| `tva.choix_du_regime_requis` | Bloquant | « Choisissez le régime de TVA de cette vente avant de l'émettre : « {nature} » ou TVA française. » | M4 |
| `tva.numero_tva_societe_absent` | Bloquant | « Le n° de TVA de votre société est nécessaire pour ce régime : renseignez-le dans les paramètres de la société. » | M2 |
| `identite.incomplete` | Bloquant | « L'identité légale de la société ou du magasin est incomplète : {éléments}. » | M2 |
| `client.siren_absent` | Bloquant | « Ce client établi en France n'a pas de SIREN : complétez sa fiche. » | M2 |
| `client.adresse_absente` | Bloquant | « Toute facture porte le nom et l'adresse du client : complétez sa fiche. » | M3 |
| `client.comptoir_pour_une_facture` | Bloquant | « Une facture demandée exige le nom et l'adresse du client : choisissez ou créez sa fiche. » | C-b |
| `tva.calcul_du_brouillon_change` | Bloquant | « Le calcul de ce brouillon a changé depuis son enregistrement : vérifiez l'avant et l'après, puis « Mettre à jour et émettre ». » (au lot M2 : messages actuels du type de client et du régime) | M2, M4 |
| `tva.sortie_des_biens_inconnue` | Bloquant | « Dites si les marchandises quittent la France. » | M3 |
| `tva.lieu_du_service_a_preciser` | Bloquant | « Dites si le service « {libellé} » est exécuté en France. » | M3 |
| `tva.lieu_du_service_hors_de_france` | Bloquant | « Ce service est exécuté hors de France : sa TVA n'est pas française et PILIQI ne gère pas encore ce cas. » | M3 |
| `tva.une_nature_par_document` | Bloquant | « Cette facture ne porte qu'un régime : déplacez ces lignes vers une nouvelle facture. » | M3 |
| `tva.document_separe_requis` | Bloquant | « Une prestation à une entreprise hors de l'Union se facture seule : déplacez les autres lignes vers une nouvelle facture. » | M3 |
| `tva.cas_exclu` | Bloquant | Vente : « Vente hors de France de ce produit : non gérée automatiquement (accises, moyen de transport neuf). » Achat : « Ce cas ne se calcule pas seul ({raison}) : un membre autorisé peut saisir sa TVA à la main. » | M4, M5c |
| `tva.taux_zero_en_france` | Bloquant | « Une société qui facture la TVA ne vend pas à 0 % en France : choisissez le taux de ce produit ou de ce service. » | M4 |
| `tva.seuil_ventes_a_distance_depasse` | Bloquant | « Au-delà de 10 000 € de ventes à distance dans l'Union, la TVA du pays du client s'applique : PILIQI ne la gère pas encore. La vente au comptoir reste possible. » | M8 |
| `tva.oss_non_gere` | Bloquant | « Votre société est inscrite au guichet OSS : PILIQI ne gère pas encore ces ventes. La vente au comptoir reste possible. » | M8 |
| `tva.depart_hors_du_dom_non_gere` | Bloquant | « Un magasin d'outre-mer n'expédie pas encore hors de son département dans PILIQI. » | M3 |
| `tva.prestation_vers_un_dom_non_geree` | Bloquant | « Une prestation à une entreprise établie dans un département d'outre-mer n'est pas encore gérée. » | M3 |
| `tva.vies_injoignable` | Bloquant | « VIES ne répond pas en ce moment (service de l'État membre indisponible). Réessayez : la facture peut être émise jusqu'au {date}. » | M1 (message), M2 (code) |
| `tva.export_emporte_non_gere` | Information | « Export emporté par le client : pas encore géré, la vente reste à la TVA française (détaxe possible pour un voyageur). » | M3 |
| `tva.brouillon_change_pendant_l_emission` | Bloquant | « Ce brouillon vient de changer : émettez-le de nouveau. » | M8 |
| `tva.vente_a_distance_seuil_non_atteint` | Information (panneau seulement) | « Vente à distance : seuil non atteint. » | M8 |
| `tva.vente_a_distance_seuil_proche` | Avertissement | « Vente à distance : seuil proche. » | M8 |
| `tva.vente_a_distance_possible` | Avertissement | « S'il n'est pas assujetti ou relève du régime dérogatoire de son pays, c'est une vente à distance : marquez-le « non assujetti » sur sa fiche. » | M4 |
| `tva.taux_inhabituel` | Avertissement | « Taux de {taux} % inhabituel pour {territoire} : vérifiez-le. » | M2 (ventes), M5c (achats) |
| `tva.vies_nom_different` | Avertissement | « Le nom ou l'adresse connus de VIES diffèrent de la fiche du client : vérifiez-la. » | M4 |
| `tva.service_hors_ue_a_verifier` | Avertissement | « Certains services rendus à un particulier établi hors de l'Union ne sont pas soumis à la TVA française (art. 259 B du CGI) : vérifiez avant d'émettre. » | M4 |
| `tva.vies_apres_la_livraison` | Avertissement | « La livraison du {date} précède l'émission : le n° de TVA du client n'a pas été vérifié sur VIES à cette date. » | M4 |
| `vente.ticket_deja_transforme` | Bloquant | « Ce ticket a déjà été transformé en facture ({numéro}). » | C-b |
| `vente.ticket_repris_par_un_avoir` | Bloquant | « Ce ticket a déjà fait l'objet d'un avoir : il ne se transforme plus en facture. » | C-b |
| `vente.ticket_non_paye` | Bloquant | « Encaissez d'abord ce qui reste dû sur ce ticket. » | C-b |
| `preuve.etablie_par_absente` | Bloquant | « Indiquez qui a établi ce document. » | C-a |
| `preuve.emetteur_non_independant` | Bloquant | « Un document de transport doit venir d'une partie indépendante du magasin et du client. » | C-a |
| `preuve.motif_absent` | Bloquant | « Indiquez le motif de l'annulation. » | C-a |
| `preuve.deja_annulee` | Bloquant | « Cette preuve est déjà annulée. » | C-a |
| `detaxe.bordereau_a_regulariser` | Avertissement | « Annulez ou rectifiez le bordereau n° {n°} dans PABLO, puis notez-le ici. » | C-a |
| `detaxe.numero_rectificatif_absent` | Bloquant | « Indiquez le n° du bordereau rectificatif. » | C-a |
| `detaxe.suite_deja_notee` | Bloquant | « La suite de ce bordereau est déjà notée. » | C-a |
| `achat.droit_de_creer_des_produits` | Bloquant | « Valider cette facture crée de nouveaux produits : il faut aussi le droit de créer des produits. » | C-a (message), M5d (code) |
| `achat.nature_piece_inconnue` | Bloquant | « Dites si cette pièce est une facture au nom de votre société ou de ce magasin, ou un ticket ou un reçu. » | M5c |
| `tva.origine_non_confirmee` | Bloquant | « Confirmez l'origine des marchandises de ce fournisseur. » | M5d |
| `tva.dedouanement_inconnu` | Bloquant | « Dites qui a dédouané ces marchandises venues de hors de l'Union. » | M5c |
| `tva.faits_contradictoires` | Bloquant | « Ces informations se contredisent ({détail}) : corrigez-les, ou faites saisir la TVA à la main. » | M5c |
| `tva.operation_triangulaire_possible` | Bloquant | « Le pays de départ n'est pas celui du n° de TVA du fournisseur : c'est peut-être une opération triangulaire. Faites saisir la TVA à la main. » | M5c |
| `tva.tva_etrangere_facturee` | Avertissement fort | « Ce fournisseur a facturé la TVA de son pays : demandez-lui une facture rectificative ou son remboursement. » | M5e |
| `tva.devise_sans_taux` | Bloquant | « Indiquez le taux de change de cette facture en {devise}, avec sa source. » | M5e |
| `tva.devise_non_geree` | Bloquant | « Cette devise n'est pas gérée. » | M5e |
| `tva.source_du_taux_imposee` | Bloquant | « Pour une importation, le taux de change est celui de la déclaration en douane. » | M5e |
| `achat.numero_fournisseur_absent` | Bloquant | « Indiquez le n° de la facture du fournisseur. » | M5c |
| `achat.date_fournisseur_absente` | Bloquant | « Indiquez la date de la facture du fournisseur. » | M5c |
| `tva.taux_zero_sans_raison` | Bloquant | « Dites pourquoi cette ligne n'a pas de TVA. » | M5c |
| `tva.motif_de_saisie_manuelle_absent` | Bloquant | « Indiquez le motif de la saisie à la main. » | M5e |
| `tva.saisie_manuelle_incoherente` | Bloquant | « La TVA déductible d'une ligne ne dépasse pas sa TVA facturée et autoliquidée, et aucun montant n'est négatif. » | M5e |
| `tva.saisie_manuelle_a_refaire` | Bloquant | « Les lignes de cette facture ont changé depuis la saisie à la main de sa TVA : un membre autorisé doit la saisir de nouveau. » | M5e |
| `tva.regularisation_deja_notee` | Bloquant | « La régularisation de cette TVA étrangère est déjà notée. » | M5e |
| `tva.achat_etranger_en_franchise_non_gere` | Bloquant | « Une société en franchise ne peut pas encore acheter de marchandises hors de France dans PILIQI. » | M5c |
| `achat.categorie_absente` | Bloquant | « Choisissez la catégorie de cette charge. » | M5c |
| `achat.categorie_salaires_interdite` | Bloquant | « Les salaires se saisissent en totaux mensuels dans l'espace de la société, jamais sur une facture. » | M5c |
| `achat.frais_sans_produit` | Bloquant | « Des frais d'acheminement se répartissent sur les produits de leur facture : saisissez-les comme une charge, ou rattachez-les à la facture des marchandises. » | M5c |
| `tva.cout_fige_par_un_avoir_fournisseur` | Bloquant | « Un avoir fournisseur a déjà été émis sur cette facture : son coût ne change plus. » | M7 |
| `tva.dedouanement_a_completer` | Avertissement | « Déclaration en douane à compléter : le coût du stock est provisoire. » | M5d |
| `tva.ecart_tva_importation` | Avertissement | « La TVA d'importation saisie ({saisie}) diffère de celle attendue ({attendue}) : vérifiez la déclaration. » | M5c |
| `tva.frais_hors_base_importation` | Avertissement | « La déclaration rattachée ne compte aucun frais accessoire : ce transport exonéré doit entrer dans sa base. » | M7 |
| `tva.vies_fournisseur_invalide` | Avertissement | « Le n° de TVA de ce fournisseur n'est pas vérifié valide sur VIES. » | M5c |
| `tva.ecart_avec_la_facture` | Avertissement | « La TVA calculée ({calculée}) diffère de celle de la facture du fournisseur ({facture}). » | M5c |
| `tva.mention_attendue_absente` | Avertissement | « La facture reçue ne porte pas la mention attendue : {mention}. » | Étape 2 |
| `charge.piece_absente` | Bloquant | « Joignez la pièce justificative de cette charge. » | CH2 |
| `charge.date_piece_absente` | Bloquant | « Indiquez la date de la pièce. » | CH2 |
| `charge.ligne_de_stock_interdite` | Bloquant | « Une charge ne porte ni produit ni frais d'acheminement. » | CH2 |
| `charge.periode_incoherente` | Bloquant | « La fin de la période précède son début. » | CH2 |
| `charge.precision_absente` | Bloquant | « Précisez cette dépense de {catégorie}. » | CH2 |
| `charge.cadeau_au_dela_du_seuil` | Bloquant | « Ce cadeau dépasse 73 € TTC : choisissez « Cadeau au-delà du seuil ». » | CH2 |
| `charge.cadeau_seuil_a_confirmer` | Bloquant | « Confirmez que le total des cadeaux à ce bénéficiaire cette année, frais d'emballage et d'envoi compris, reste sous 73 € TTC, ou choisissez « Cadeau au-delà du seuil ». » | CH2 |
| `charge.tva_inattendue` | Avertissement | « Une prime d'assurance est exonérée : cette TVA n'est pas déductible. Si la ligne n'est pas une assurance, changez sa catégorie. » | M5c |
| `charge.regle_a_confirmer` | Information | « Règle à confirmer par votre expert-comptable. » | CH2 |
| `charge.deduction_a_proratiser` | Information | « Droit à déduction à proratiser selon le coefficient de votre société : à voir avec votre expert-comptable. » | CH2 |
| `piece.vide` | Bloquant | « Ce fichier est vide. » | CH1 |
| `piece.trop_grosse` | Bloquant | « Une pièce fait 10 Mo au plus. » | CH1 |
| `piece.type_refuse` | Bloquant | « Seuls les fichiers PDF, JPEG, PNG et XML de facture électronique (UBL ou CII) sont acceptés. » | CH1 |
| `piece.trop_de_pieces` | Bloquant | « Ce document porte déjà 20 pièces. » | CH1 |
| `piece.deja_jointe` | Bloquant | « Cette pièce est déjà jointe à ce document. » | CH1 |
| `piece.retrait_d_un_document_valide` | Bloquant | « Une pièce ne se retire jamais d'un document validé : si elle a été déposée par erreur, remplacez-la, avec un motif. » | CH1 |
| `piece.deja_retiree` | Bloquant | « Cette pièce est déjà retirée. » | CH1 |
| `piece.motif_absent` | Bloquant | « Indiquez pourquoi cette pièce est remplacée. » | CH1 |
| `piece.derniere_piece` | Bloquant | « Un document validé garde au moins une pièce : déposez celle qui remplace la pièce erronée. » | CH1 |
| `piece.deja_remplacee` | Bloquant | « Cette pièce est déjà remplacée. » | CH1 |
| `salaires.piece_absente` | Bloquant | « Joignez le récapitulatif totalisé des salaires du magasin, sans détail par salarié. » | CH4 |
| `salaires.mois_futur` | Bloquant | « Les salaires d'un mois futur ne se saisissent pas. » | CH4 |
| `salaires.montant_negatif` | Bloquant | « Un montant de salaires n'est jamais négatif. » | CH4 |
| `salaires.magasin_hors_portee` | Bloquant | « Ce magasin n'est pas dans votre portée. » | CH4 |
| `salaires.magasin_ferme` | Bloquant | « Ce magasin est en lecture seule. » | CH4 |
| `charge_commune.repartition_invalide` | Bloquant | « Répartissez entre au moins deux magasins, avec des pourcentages qui font 100 %. » | CH5 |
| `charge_commune.magasin_hors_portee` | Bloquant | « Un magasin de la répartition n'est pas dans votre portée. » | CH5 |
| `charge_commune.magasin_ferme` | Bloquant | « Un magasin de la répartition est en lecture seule. » | CH5 |
| `charge_commune.territoires_differents` | Bloquant | « Les magasins d'une répartition sont du même territoire de TVA. » | CH5 (restriction provisoire, 6.1 e) |
| `charge_commune.piece_absente` | Bloquant | « Joignez la pièce justificative de cette charge commune. » | CH5 |
| `charge_commune.bien_etranger_non_gere` | Bloquant | « Un bien acheté hors de France se saisit dans un magasin. » | CH5 (restriction provisoire, 6.1 e) |
| `charge_commune.cas_exclu` | Bloquant | « Ce cas ne se calcule pas seul : saisissez la charge dans un magasin, où sa TVA peut se saisir à la main. » | CH5 (restriction provisoire, 6.1 e) |
| `charge_commune.brouillon_change` | Bloquant | « Cette charge commune vient de changer : vérifiez-la, puis validez-la de nouveau. » | CH5 |
| `charge_commune.deja_validee` | Bloquant | « Cette charge commune est déjà validée : corrigez-la si besoin. » (une demande répétée du même auteur, au même contenu, rend la charge validée sans ce refus, 4.7) | CH5 |

Aucun code `tva.bon_d_achat_non_gere` (TVA9 = c), ni `vente.transformation_calcul_change` (la facture de remplacement reproduit le ticket par construction ; une différence serait un défaut du logiciel, `LogicException`, 5.6). Un refus d'autorisation garde le message du mécanisme du lot A4.

---

## Annexe C : correspondance avec la conception finale du moteur

| Conception finale | Ce plan | Différence |
|---|---|---|
| 1 Principes et périmètre | 3.1 | Inchangé (P1 à P14). |
| 2 Architecture (faits, collecteurs, moteur, règles, décision, usages, refus) | 3.2 à 3.9 | `TypeLigneAchat` : `autre_service` devient `charge` avec une catégorie ; `CategorieCharge`, `PrecisionDeCharge`, `TypeDocumentAchat`, `SourceDuTauxDeChange`, `NatureDeLaPiece` ajoutées ; `RaisonDuTauxZero` gagne `exoneree`, qui soustrait aussi à l'autoliquidation ; `ParticulariteFiscale` sans valeur de bon ; `MoteurTva::repartirUneChargeCommune()` ; saisie manuelle reprise depuis la décision figée ; consultation VIES à l'émission exigeant le droit d'émettre, avec la condition actuelle de l'écran ; `ActionsUniques::resultatDejaEnregistre()` ; `CodeDeManquement`. |
| 3 Données | 3.11 à 3.13 | `vente_a_distance` au lot M3 ; colonnes de devise et de régularisation au lot M5e ; colonnes des charges et tables des compléments et des charges. |
| 4.1 Classe par classe ; 4.3 Tests touchés | 3.14 ; annexe A | Tests des compléments et des charges ajoutés ; renommages de O1 acquis (O1 acceptée). |
| 5 Ergonomie | 3.16, 3.18, 3.19, 3.20, 4.4 | Aucune carte cadeau ; « Ajouter des frais ou une charge » ; nature de la pièce ; libellé « TVA déductible (droit ouvert par ces pièces) » des totaux des charges ; « seuil non atteint » en information ; saisie manuelle aussi pour les responsables (TVA11). |
| 6 Achats | 3.17 | Réponses appliquées : TVA10 = a, TVA12 = a, TVA13 = a pour la TVA autoliquidée (la TVA facturée est soumise à 6.1 c), TVA14 = a et TVA16 = a (leurs arrondis sont soumis à 6.1 d), TVA15 = b ; origine reprise sans nouvelle confirmation ; lignes de charge ; assurances et opérations bancaires exonérées jamais autoliquidées ; pièce qui n'est pas une facture sans déduction ; importation d'une charge sur un bien en cas exclu ; frais sans produit refusés. |
| 7 Ventes | 3.15 | Réponses appliquées : TVA2 à TVA8, TVA10 ; 7.9 (cartes cadeaux) remplacé par 1.2 (TVA9 = c). |
| 8 Déclarations futures | 3.21 | Charges ajoutées aux étapes 2, 5 et 6. |
| 9 Traçabilité | 3.22 | Pièces, charges communes, salaires, annulations de preuves, suites de bordereaux ajoutés. |
| 10 Tests | 3.23, 4.13 | V32 supprimé ; V39 à V42 ajoutés ; A06, A22, A23, A40 selon les réponses ; A08b, A08c, A13b, A19c, A19d, A29c, A40b, A43 à A53b et A45b ajoutés ; un fichier et un lot pour chaque test ; scénarios CH-01 à CH-22. |
| 11 Plan de lots | 5 | M0 en tête ; C-a avant M1 ; M8 après M4 ; M5 coupé en M5a à M5e ; lots des charges entre M6 et M7, CH3 et CH6 conditionnels ; portes de 6.0 ; FIN ; l'étape 1 n'est cochée qu'au lot FIN. |
| 12 Questions à l'éditeur | 1 (réponses), 6.1 | Toutes tranchées ; points a et b sur les charges ; points c à g nés des critiques du 12/10/2026 (arrondis jamais montrés, restrictions des charges communes, charges récurrentes, numérisation fiscale). |
| Annexe B (expert-comptable) | 6.2 | Points 7, 14 et 17 de la conception (catégorie TNT1, échéances, cartes cadeaux) : le premier devient EC9, le deuxième relève de SUPER PDP, le troisième disparaît (TVA9 = c) ; EC1 (règles des charges), EC19 (coefficient de déduction) et EC20 (achats exonérés à un prestataire non établi) ajoutés ; l'arrondi des 80 % passe à l'éditeur (6.1 d). |
| Annexe C (défauts des relectures) | — | Tous traités dans la conception ; rien à reprendre. |
| — | Annexe D | Les 42 défauts relevés sur ce plan le 12/10/2026, chacun avec son traitement. |

---

## Annexe D : traitement des 42 défauts relevés le 12/10/2026

Trois critiques adversariales ont relevé 42 défauts, numérotés ici dans l'ordre où ils ont été reçus. Chacun a été vérifié dans les documents de décision (réponses à la série 7, résumé montré à l'éditeur, décisions des charges, compléments 1 à 16, inscription sans magasin, avenant A4), dans le code de l'instantané (commit 9c9ee25) et, en lecture seule, dans la copie de travail de la chaîne société. Verdicts : 36 retenus tels quels, 5 retenus avec une correction adaptée (8, 19, 21, 26, 32), 1 retenu en partie (31) ; aucun n'est écarté en entier. Les références juridiques citées par les critiques n'ont pas pu être relues sur Légifrance ni sur le BOFiP (injoignables depuis cette machine) : elles ont été recoupées avec `reglementation.md` et les textes que le plan citait déjà ; ce qui reste incertain est renvoyé à l'expert-comptable (6.2) ou à l'éditeur (6.1).

| # | Défaut (gravité) | Verdict | Vérification et raison | Où le plan le traite |
|---|---|---|---|---|
| 1 | TVA13 : la TVA facturée d'un achat restait calculée ligne par ligne, l'option écartée (bloquant) | Retenu | TVA13 = a ; dans le résumé montré à l'éditeur, l'option b « ligne par ligne » est rejetée et l'option c ne se distingue de a que par la TVA facturée ; le détail « TVA facturée recalculée ligne par ligne » ne figurait que dans la conception. Règle d'arrondi : soumise à l'éditeur (protocole, règle 4), recommandation la lettre de TVA13, lot M5c fermé jusqu'à la réponse. | 0.3 point 7, 1.1 (TVA13), 1.6, 2.7 (« Soumis à 6.1 »), 3.1 (P9), 3.5, 3.17.3, 3.17.6, 3.23.2 (A29c), 5.11, 6.0, 6.1 c, annexe A |
| 2 | Arrondis des frais, des devises, de la TVA déductible partielle et des charges communes présentés comme décidés (important) | Retenu | Les questions T14 et T16 ne contenaient aucune méthode d'arrondi ; la décision des charges communes dit seulement « parts égales ou pourcentages » ; l'arrondi des 80 % n'était renvoyé qu'à l'expert-comptable. Regroupés en 6.1 d (quatre sous-questions, options et recommandation), portes de M5c, M5e et CH5 ; l'arrondi quitte EC1. | 0.3 point 7, 1.1 (TVA14, TVA16), 1.4, 1.6, 2.11 et 2.12 (« Soumis à 6.1 »), 3.1 (P9), 3.5, 3.17.3, 3.17.4, 3.17.6, 3.17.7, 4.3, 4.7, 6.0, 6.1 d, 6.2 (EC1) |
| 3 | Guichet OSS et régimes des DOM devenus conditionnels (important) | Retenu | TVA8 = a (« plus tard ») a écarté « jamais » ; O6 renvoie au régime dédié après SUPER PDP. Texte de la feuille de route repris de la critique. | 1.1 (TVA8), 1.3 (O6), 2.17 (ligne 2), 3.15.2 |
| 4 | A08 exigeait de reconfirmer une origine reprise de la dernière facture validée (important) | Retenu | TVA3 et QE4 : origine reprise « sans nouvelle confirmation », confirmée la première fois. A08 porte sur la première facture ; A08b et A08c ajoutés. | 1.1 (TVA3), 2.11, 3.17.1, 3.18, 3.19, 3.23.2, 5.12 |
| 5 | L'avoir d'annulation ne citait ni le ticket ni la facture qu'il permet d'établir (important) | Retenu | Complément 14. La facture est numérotée avant la signature de l'avoir, dans la même transaction ; clés additives `ticket_annule` et `facture_de_remplacement`, déduites des colonnes et recalculées par le vérificateur ; mention sur le PDF, le duplicata et la fiche ; test nommé. | 2.6, 3.12, 5.6 |
| 6 | Exigences des compléments 10 à 12 pour l'étape 2 reprises nulle part (important) | Retenu | Aucune occurrence de « absence d'op », « entrée négative », « Peppol », « 258 D » dans le plan. Texte de l'étape 2 complété (art. 290 I, Monaco, 258 D I, art. 261 à 261 E, SIREN, entrée négative, Peppol). | 1.5 (points 10 à 12), 2.17 (ligne 2) |
| 7 | Restrictions des charges communes non décidées ni soumises (mineur) | Retenu | La décision ne dit que « parts égales ou pourcentages ». Les trois restrictions deviennent provisoires, hors de `CLAUDE.md`, soumises en 6.1 e ; question de périmètre, elle ne ferme pas le lot CH5, qui applique la réponse si elle est connue. | 1.4, 1.6, 2.12 (« Soumis à 6.1 »), 4.7, 5.0, 5.19, 6.0, 6.1 e, annexe B |
| 8 | Lots des charges placés après M7, contre « dans les lots achats (M5 à M7) » (mineur) | Retenu, correction adaptée | Décision de calendrier vérifiée. Les lots CH1 à CH6 passent entre M6 et M7, qui ferme le bloc des achats. CH1 n'est pas placé après M5d, comme la critique le proposait, parce que ses écrans s'ajoutent aux écrans d'achat que M6 réécrit ; CH2 n'est pas fondu dans M6, déjà gros. Aucun lot des charges ne dépend de M7, et M7 d'aucun d'eux. | 1.4, 1.6, 2.17 (ligne 1), 5.0, 5.1, 5.15 à 5.21 (renumérotés) |
| 9 | « Seuil non atteint » émis comme un avertissement dès le premier euro (mineur) | Retenu | TVA8 : avertissement dès 9 000 € ; 0.5 : un avertissement se répète et reste dans la décision. Sous 9 000 €, information du panneau (`tva.vente_a_distance_seuil_non_atteint`) ; dès 9 000 €, avertissement (`tva.vente_a_distance_seuil_proche`) ; gravité « Information » définie. | 0.5, 1.1 (TVA8), 3.5, 3.15.7, 3.16.3, 3.16.5, 3.19, 3.23.1, 3.23.8, 5.8, annexe B |
| 10 | « Paiements et avoirs » donnait deux ordres d'imputation (mineur) | Retenu | `ImputerAvoirSurSesFacturesAction` impute déjà d'abord sur les factures corrigées, puis sur les autres de la plus ancienne à la plus récente. Puce réécrite telle que le code l'applique ; test précisé (avoirs sur des factures déjà réglées) et test de l'étape 1 ajouté. | 2.9, 5.2 |
| 11 | « Demandes répétées » exigeait un jeton pour la charge commune, qui n'en a pas (mineur) | Retenu | `ActionTraitee` est un modèle de magasin (clé `magasin_id`, `jeton`). Voie de la critique : 2.10 vise la validation d'achat ou de charge « d'un magasin » et dit la protection des gestes de la société par leur état et le verrou de leur ligne, une demande répétée rendant le résultat déjà enregistré (même traitement que 33). | 0.3 point 6, 1.6, 2.10, 4.7, 4.8 |
| 12 | Phrase du lot A4b absente de « Comptes et équipe » (mineur) | Retenu | `inscription-sans-magasin.md`, section 1, puce 3 ; la copie de travail (lot A4 commité) ne l'a pas encore. Phrase insérée ; 2.0 garde toute phrase d'A4b à la fusion. | 2.0, 2.3, 5.1 |
| 13 | TVA9 : `CLAUDE.md` affirmait « aucun produit de ce type » sans rien qui l'empêche (mineur) | Retenu | Aucune heuristique sur le nom d'un produit, volontairement. Texte de la critique : une consigne, et la notice dit qu'un magasin n'en vend pas par PILIQI, pas même comme un produit ordinaire. | 1.1 (TVA9), 1.2, 2.7, 2.17 (ligne 3) |
| 14 | Règle de déduction de l'étape 5 présentée comme déjà en place (mineur) | Retenu | La date de déduction ne se calcule qu'à l'étape 5. Phrase écrite au futur ; les totaux donnent le droit ouvert par les pièces. | 2.12, 4.3, 4.11 |
| 15 | « Transformer en facture » : refus « calcul changé » ajouté aux cas décidés (mineur) | Retenu | Compléments 14 et 15 : « exactement les mêmes lignes, prix, taux et totaux », trois refus seulement. Facture construite avec les faits figés du ticket ; une différence est un défaut du logiciel (`LogicException`), jamais un refus présenté ; code retiré de l'annexe B. | 1.6, 2.6, 3.19, 5.6, annexe B |
| 16 | Retour sur ticket : deux autorisations exigées au lieu d'une (mineur) | Retenu | Complément 16 ; le modèle du vendeur n'a aucune autorisation `avoir.*` (instantané et copie de travail). `avoir.sur_ticket` suffit seule, y compris pour voir et imprimer un avoir sur un ticket ; la transformation exige `facture.emettre` et `avoir.sur_ticket`. | 1.6, 2.9, 3.16.6, 3.20, 5.2, 5.6, annexe A |
| 17 | Une assurance ou une opération bancaire exonérée d'un prestataire non établi était autoliquidée à 20 % (bloquant) | Retenu | Art. 261 C 1° et 2° du CGI ; l'autoliquidation (art. 283) ne change que le redevable d'une taxe due. Raison `exoneree` posée d'office pour Assurances et Frais bancaires d'un prestataire non établi, modifiable par une question (la location d'un terminal de paiement reste taxable), même règle pour l'organisme d'une charge commune ; tests A51, A52, A52b, CH-22 ; EC20. | 1.1 (TVA10), 1.4, 1.6, 2.12, 2.17 (ligne 2), 3.2, 3.5, 3.17.1, 3.17.2, 3.17.3, 3.18, 3.21, 4.3, 4.7, 6.2 (EC20) |
| 18 | 80 % appliqués à tout carburant d'un véhicule de tourisme (important) | Retenu | Art. 298-4-1° du CGI : la limite vise le gazole, l'essence et l'E85 ; électricité et GNV entièrement déductibles (électricité admise par une réponse ministérielle, donc à confirmer) ; GPL incertain. Deux précisions, GPL en « Autre » à confirmer ; CH-09b. | 2.12, 3.18, 3.23.6, 4.3, 4.4, 4.13, 5.16, 6.2 (EC1) |
| 19 | La TVA d'un ticket de caisse était déduite comme celle d'une facture (important) | Retenu, correction adaptée | Art. 271 II du CGI. Le fait est porté par la pièce (en-tête), non par chaque ligne : une pièce est un ticket ou une facture pour toutes ses lignes. Il vaut pour tout achat, marchandises comprises. Prérempli « facture » pour un fournisseur qui a un SIRET et pour une facture reçue par l'API ou SUPER PDP, demandé sans réponse sinon. Phrase « jamais un refus de déduction » limitée aux mentions qui manquent à une facture ; tests A53, A53b, CH-18 ; EC1 étendu. | 0.5, 1.4, 1.6, 2.12, 3.2, 3.3, 3.5, 3.7, 3.11, 3.17.1, 3.17.3, 3.18, 3.19, 3.23.2, 4.1, 4.3, 4.5, 4.7, 5.11, 5.12, 6.2 (EC1), annexes A et B |
| 20 | Totaux des charges intitulés « TVA déductible (à récupérer) » par date de pièce (important) | Retenu | Art. 271 I-2 et 269-2 c du CGI ; la date de déduction n'est calculée qu'à l'étape 5. Libellé « TVA déductible (droit ouvert par ces pièces) », ligne fixe sous le total et dans l'export. | 2.12, 3.16.4, 4.3, 4.11, 4.13 (CH-17), 5.16, annexe C |
| 21 | Coefficient de déduction d'un assujetti partiel ignoré (important) | Retenu, correction adaptée | Art. 205 et 206 I à III de l'annexe II ; `reglementation.md` prévoyait de signaler l'hypothèse. Réglage de la société « opérations exonérées ou hors du champ », non daté (contrairement à la proposition) : il ne change aucun montant, seulement un indicateur, et chaque décision figée garde la valeur lue. Indicateur « à confirmer » et information `charge.deduction_a_proratiser`, aucun coefficient ; hypothèse écrite dans `CLAUDE.md` ; CH-21 ; EC19. | 1.4, 2.4, 2.12, 2.17 (ligne 5), 3.3, 3.5, 3.7, 3.11, 3.20, 3.21, 4.3, 4.7, 5.16, 6.2 (EC19) |
| 22 | La pièce déposée présentée comme conservée, sans dire qu'elle ne remplace pas l'original (important) | Retenu | Art. L123-22 du Code de commerce, L102 B et A102 B-2 du LPF. Copie de consultation dite sous le dépôt et dans la notice ; XML UBL ou CII admis, analysé sans DOCTYPE ni ressource externe ; Factur-X gardé tel quel ; numérisation fiscale soumise à l'éditeur (6.1 g, étape 15). | 0.5, 1.4, 1.6, 2.12, 2.17 (ligne 3, « Soumis à 6.1 »), 3.22, 4.6, 4.12, 5.15, 6.1 g, annexe B |
| 23 | Le récapitulatif de paie pouvait détailler chaque salarié (important) | Retenu | RGPD, art. 4-1, 5-1-c et 9 ; décision « aucun bulletin individuel ». Récapitulatif totalisé, texte sous le dépôt, message de `salaires.piece_absente` ; étape 9 complétée (petit effectif). | 1.4, 2.12, 2.17 (ligne 9), 4.6, 4.8, 4.12, 5.18, annexe B |
| 24 | Une pièce déposée par erreur ne pouvait jamais être retirée d'un document validé ni des salaires (important) | Retenu | RGPD, art. 5-1-c, 5-1-d et 17. « Jamais supprimées après validation » venait de l'avis de Claude que la décision de calendrier a repris ; il vise la conservation des justificatifs, pas une pièce étrangère à l'opération. `RemplacerUnePieceJustificativeAction` : motif, droits, fichier effacé après la transaction, ligne et empreinte gardées, audit, au moins une pièce restante ; point de relecture de CH4 réécrit. | 1.4, 1.6, 2.12, 2.15, 2.17 (ligne 4), 3.20, 3.22, 4.4, 4.6, 4.8, 4.9, 5.15, 5.18, 5.19, annexe B |
| 25 | Exclusions de l'art. 206 IV-2 sans leurs exceptions (mineur) | Retenu | Exceptions citées par la critique (transport collectif du personnel sous contrat permanent ; logement du personnel de gardiennage, de sécurité ou de surveillance ; logement fourni à titre onéreux), recoupées avec la doctrine qu'elle cite. Exceptions dans les raisons, à choisir en « Autre » ; « à confirmer (numéro de l'alinéa) » retiré ; logement « à confirmer » ; EC1. | 2.12, 4.3, 6.2 (EC1) |
| 26 | Loyer et Entretien sans précision donnaient 100 % à des dépenses exclues (mineur) | Retenu, correction adaptée | Art. 206 IV-2-2° et 6° de l'annexe II (dépenses rattachées aux véhicules de tourisme, location et entretien compris). Précision obligatoire comme proposé, avec des valeurs partagées entre catégories (`logement_dirigeants_personnel`, qui remplace aussi `hebergement_dirigeants_personnel`, et `vehicule_de_tourisme`) et une précision `autre` pour le Loyer (location de matériel), que la critique ne prévoyait pas ; CH-19, CH-20. | 2.12, 3.17.1, 3.19, 4.1, 4.2, 4.3, 4.4, 4.13, 5.16 |
| 27 | Seuil des cadeaux contrôlé ligne par ligne (mineur) | Retenu | Seuil par bénéficiaire et par an, frais d'emballage et d'envoi compris. Refus unitaire gardé comme contrôle minimal, raison réécrite, case obligatoire (`charge.cadeau_seuil_a_confirmer`) ; CH-11b. | 2.12, 3.3, 3.11, 3.18, 3.19, 3.20, 4.3, 4.4, 4.5, 4.13, 5.16, annexe B |
| 28 | Exigibilité de l'étape 5 incomplète pour l'AIC et affirmée pour les services autoliquidés (mineur) | Retenu | `reglementation.md` 2.4 : art. 269-2 d (date de la facture si elle est antérieure) ; article des services autoliquidés non retrouvé. Texte repris de la critique, sans l'étiquette EC dans `CLAUDE.md`. | 2.17 (ligne 5), 3.21, 4.12, 6.2 (EC7) |
| 29 | Le résultat par magasin de l'étape 6 laissait retrouver les salaires par différence (mineur) | Retenu | Décision des salaires (pas les responsables ni les vendeurs). Résultat avec salaires pour qui les voit, marge avant salaires pour les autres. | 1.4, 2.17 (ligne 6), 4.8, 4.11, 4.12 |
| 30 | Les experts-comptables voyaient les charges communes dans l'espace de la société, que le lot A4 leur ferme (bloquant) | Retenu | Copie de travail : `canAccessPanel` ouvre l'espace de la société par `peutAdministrer()` (propriétaire ou administrateur) ; avenant A4 et ses tests nommés. Détail et pièce lus depuis le panneau d'un magasin qui a une part (`PartChargeCommuneResource`, `DetailDeChargeCommunePolicy`, `RepartitionDesChargesCommunes::detailPour()`) ; lecture ajoutée à la puce 2 ; test réécrit sur ce chemin ; tests du lot A4 inchangés. | 1.4, 1.6, 2.2, 2.3, 3.13, 3.23.7, 4.4, 4.7, 4.9, 5.19, annexe A |
| 31 | M1 : la consultation VIES déplacée dans l'action changeait des résultats et cassait des tests (important) | Retenu en partie | `ControleNatureOperation::consultationDuJour()` rend nul quand la dernière consultation du jour a trouvé le n° invalide : l'écran consulte déjà de nouveau dans ce cas, la condition ne change pas (second point écarté). Le changement de lieu est réel : `NatureOperationTest` (jeux « n° vérifié la veille » et « n° inconnu de VIES aujourd'hui », test de la l. 365) appelle l'action sans `Http::fake`, et `Http::preventStrayRequests()` ferait échouer la requête. Condition écrite telle qu'elle est ; tests listés avec un registre VIES simulé, assertions inchangées ; critère de fin réécrit ; deux tests ajoutés. | 3.4, 3.8, 3.23.1, 5.3, annexe A |
| 32 | M5a : lecteurs de `societes.regime_tva` oubliés, dont une commande planifiée (important) | Retenu, correction adaptée | Vérifiés sur l'instantané : `SoumettreEReportingPeriodique` (l. 49 et 53), `ActionAuditee` (`ParametresFiscauxModifies`), `ModifierParametresFiscauxSocieteAction::CHAMPS`, et les tests cités (lignes confirmées). La commande lit le régime en vigueur le jour de son exécution, et non à la date de la période : c'est ce qu'elle lit aujourd'hui (le régime courant), sans règle nouvelle, son remplacement restant à l'étape 2. Les tests qui règlent le régime par les aides ne changent pas ; les autres sont listés. | 3.13, 3.14, 5.9, annexe A |
| 33 | La validation d'une charge commune n'avait pas de jeton (important) | Retenu | Même constat que 11. Voie b de la critique : une validation répétée par le même auteur, au même contenu (empreinte portée par la demande), rend la charge validée sans rien réécrire ni inscrire ; un brouillon changé entre-temps est refusé ; les salaires aux mêmes montants ne se réécrivent pas ; plus de contradiction en 1.6. La voie a aurait étendu `ActionsUniques` et sa table, livrées par le lot A6, pour deux gestes. | 0.3 point 6, 1.6, 2.10, 4.7, 4.8, 5.18, 5.19, annexe B |
| 34 | Une saisie manuelle de la TVA d'achat disparaissait, ou bloquait, au recalcul par un autre geste (important) | Retenu | Première voie de la critique : le collecteur reprend la saisie depuis la décision figée, compare l'instantané des faits aux lignes, et exige une nouvelle saisie si elles ont changé (`tva.saisie_manuelle_a_refaire`) ; tests A13b, A19c, A40b. | 2.11, 3.3, 3.4, 3.7, 3.17.5, 3.17.9, 3.19, 5.13, 5.21, annexe B |
| 35 | « Transformer en facture » contournait des règles d'imputation et de prix (important) | Retenu | Vérifié : imputations filtrées par client ; `FactureEmise` déclenche `AppliquerAvoirDisponibleApresEmissionFacture` ; `CreerAvoirAction` refuse les lignes d'un autre client. Imputation entre deux clients faite par l'action seule, avant tout autre crédit, reconnue par `ticket_transforme_id` ; écouteurs de stock et d'imputation neutralisés pour ces documents ; `DerogationsDePrix::introduites(lignes du ticket, lignes de la facture)` ; une caisse à la fois ; tests ajoutés. | 2.9, 3.14, 3.20, 5.6 |
| 36 | La répartition des charges communes se calculait hors du moteur (important) | Retenu | P1 et P9. `MoteurTva::repartirUneChargeCommune()` avec `RepartitionParCumul` ; `RepartitionDesChargesCommunes` n'écrit et ne relit que ; test pur ; arrondi soumis à l'éditeur (6.1 d). | 1.4, 1.6, 3.2, 3.5, 4.7, 5.19, 6.1 d |
| 37 | Les écritures de la société dans les magasins ne prenaient aucun verrou partagé avec la fermeture d'un magasin et le changement des droits (important) | Retenu | Copie de travail : `exigerDansLaSociete()` relit l'auteur sans verrou ; `RegistreFiscal::sousVerrouDesMagasins()` existe. Registres des magasins touchés, puis la ligne de l'auteur par `exigerDansLaSocieteSousVerrou()` (créée au lot CH4, `FOR NO KEY UPDATE`), puis la ligne du document, contrôles relus sous ces verrous ; tests de concurrence sous PostgreSQL. | 0.3 point 5, 4.7, 4.8, 5.18, 5.19 |
| 38 | M8 : verrou de la ligne `societes` sans mode, risque d'interblocage avec le journal d'audit (important) | Retenu | `journal_audit.societe_id` est une clé étrangère vers `societes` ; le seul modèle du code est `lockForUpdate`. `FOR NO KEY UPDATE`, posé en règle générale en 0.3 ; test de concurrence avec une validation d'achat du même compte. | 0.3 point 5, 3.15.7, 3.23.1, 5.8 |
| 39 | M7 : ordre des verrous de plusieurs factures rattachées non fixé (mineur) | Retenu | Ordre du lot A4 (documents, puis produits). Facture de frais, factures rattachées par identifiant croissant, union de leurs produits ; test A19d. | 3.17.5, 3.23.2, 5.21 |
| 40 | Une ligne de charge d'une facture de marchandises sans date sortait des totaux (mineur) | Retenu | `factures_achat.date_facture` est nullable. Première voie : date de la pièce exigée pour toute facture qui porte une ligne de charge ; test étendu au cas sans date. | 1.4, 2.12, 3.17.1, 3.19, 4.1, 4.5, 4.11, 5.16 |
| 41 | Suite d'un bordereau et régularisation d'une TVA étrangère sans protection contre la double soumission (mineur) | Retenu | `NoterBordereauDetaxeAction` verrouille déjà le ticket. Index unique et refus relu sous ce verrou (`detaxe.suite_deja_notee`) ; régularisation refusée si déjà notée, sous le verrou de la facture d'achat (`tva.regularisation_deja_notee`) ; tests de double soumission. | 3.19, 3.23.2 (A45b), 5.2, 5.13, annexe B |
| 42 | Charges récurrentes présentées comme une décision de l'éditeur (mineur) | Retenu | `charges-demande-et-decisions.md` : avis de Claude seulement. Question 6.1 f, recommandation « non pour l'instant » (réception des factures électroniques obligatoire depuis le 1/9/2026 : doublons à l'étape 2) ; lot CH3 conditionnel. | 0.1, 0.2, 1.4, 1.6, 2.12, 2.17, 3.11, 3.13, 4.4, 4.10, 5.0, 5.17, 6.0, 6.1 f |
