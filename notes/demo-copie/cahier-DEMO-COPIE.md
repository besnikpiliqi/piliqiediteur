## Lot « DEMO-COPIE » : une démonstration par société, copiée d'une société modèle, sur un site à part (décision de l'éditeur du 15/11/2026 en soirée, confirmée le 16/11/2026 vers 0 h 50, précisée vers 1 h 30 et 1 h 40, heure de Paris)

> **16/11/2026 vers 10 h (Paris) — DÉCISIONS QUI PRIMENT** : Q2 REPRISE (« tu fait le A et tu supprime le demo du site reel ») : la note de suspension de 9 h 40 est annulée, la partie D se fait. Choix A : une base par société. Les trois lots DEMO-COPIE passent MAINTENANT, avant DEVIS-RET, MDP, IDENT, CESSION, NAF25, VIES-DEP et TVA27 : les consignes « pendant TVA27 » ne s'appliquent pas. DEVIS-RET (a3227944) attend sur la branche locale sauvegarde/devis-ret-a-relire. Vos mots : « et ben tu commence comm on c 'est dit donc tu fait le A et tu supprime le demo du site reel. et tu arret tout les autre qui tourne mtn et tu t occupe que pour la parti de nouveau demo et le suppression de demo reel. donc le traveil qui tourne mtn tu sauvegare pour continuer apres le demo ».

> Ce cahier remplace la section « Lot « DEMO-SITE » » de `a-faire-apres-chaine.md`. Il est fait des cinq relevés de `demo-copie/` (règles, code, tests, notes, architecture), lus le 16/11/2026 sur la tête `a9bb1a78`. Version 2 : il reprend les 60 problèmes trouvés par trois contre-lecteurs (voir `problemes-ecartes.md` pour ceux qui ont été corrigés autrement ou réunis) et les deux précisions de l'éditeur de 1 h 30 et 1 h 40. Les questions qui restent à l'éditeur sont dans `questions-editeur.md` ; en attendant ses réponses, le lot part des recommandations de ce fichier. Maquette : https://claude.ai/artifact/855CcM1W2SUsd58taPYk7i (copie : `modeles/demo-copie.html`, version 3). Les noms de la maquette (« Quincaillerie des Tilleuls », « Atelier Lumen Réparations », « Cabinet Aubier ») sont des exemples : les sociétés modèles sont celles du semis (partie F).

### Vos mots

« Q1 oui, Q2 oui, Q3 oui, Q4 oui » (16/11/2026, vers 0 h 50, heure de Paris).

Et, dans le même message : « on a pas encore fini le logiciel et meme si modifié alors il sera mis à jours pour toujours et alors comment se met à jour pour les site donc reel et demo? » La réponse est la partie E.

Vers 1 h 30 : « je veux pas qu'une societe "Commerce ou atelier" puisse partire "Services, sans vente d'articles" ou "Cabinet d'expertise comptable" et au contraire. donc chaque societe doit voir que son type de son model et pas les trois ».

Vers 1 h 40 : « pas besoin d affiche ce texte […] car la societe ne doit pas savoir comment ca marche sur les autre type ».

Les quatre choix :
- **Q1 : un seul lien par société.** Le propriétaire le tient. Il peut le donner à son équipe. Il peut le renouveler : l'ancien cesse de marcher, la copie reste. Le lien a 256 bits tirés au hasard. Le site réel ne garde que son empreinte. À la première visite, le lien est échangé contre un témoin du site de démonstration, et l'adresse se réécrit sans lui. `Referrer-Policy: no-referrer`. Il vaut 30 jours. Il s'obtient dans le compte connecté (« Ouvrir ma démonstration ») ou par e-mail, toujours après la confirmation de l'adresse. Qui a le lien entre sans se connecter.
- **Q2 : le magasin de démonstration du compte réel est retiré.** Plus d'exception « le propriétaire vend dans le magasin de démonstration ». Plus de comptes d'entraînement. Plus d'archive finale du magasin de démonstration du compte réel. Plus de JET 100 ni 105 dans une société réelle. Une société non validée s'essaie désormais dans sa copie.
- **Q3 : la copie est supprimée en entier au bout de 30 jours** (`DROP DATABASE`). « Recommencer à zéro » pendant le mois. Une nouvelle copie sur demande ensuite. Une seule à la fois. La loi demande de garder les données d'un mode d'entraînement de la caisse : avant chaque suppression, une archive signée de la caisse de la copie est gardée six ans, hors de toute base (partie « Ce que dit la loi » ; question 1 de `questions-editeur.md`).
- **Q4 : identité entièrement fictive dans la copie.** Jamais le nom, le SIREN ni aucune donnée de la vraie société.

Le reste de la décision du 15/11 en soirée, avec les précisions de 1 h 30 et 1 h 40 :
- une société modèle fictive par type d'activité (« Commerce ou atelier », « Services, sans vente d'articles », « Cabinet d'expertise comptable »), remplie comme le semis, par les vraies actions et les fournisseurs factices, avec les cas de son type ;
- chaque nuit, PILIQI refait les trois bases modèles ;
- une demande du propriétaire (adresse de connexion confirmée) fait copier par PostgreSQL la base modèle du type de sa société (`CREATE DATABASE … TEMPLATE …`), sans aucun choix de type : c'est la base de cette société, sur un site de démonstration séparé, même code, en mode « démonstration ». Le site de démonstration a son propre nom de domaine, jamais un sous-domaine du site réel (A.10 ; question 2 de `questions-editeur.md`) ;
- un bandeau « Vous êtes : » en haut de chaque page de la copie, avec un menu qui change de compte d'un clic, sans mot de passe ; le menu ne propose que les comptes de la société de la copie, jamais une société d'un autre type ni l'espace d'un cabinet pour une société ; toutes les règles restent celles du vrai logiciel ;
- aucun écran, aucun e-mail ni aucun message de la démonstration ou de la carte « Ma démonstration » ne parle des autres types d'activité ni de leurs démonstrations ;
- aucun e-mail ne part de la démonstration : page « E-mails qui seraient partis » ;
- séparation : clés de signature propres à la démonstration (jamais celle de la caisse réelle), documents marqués « Démonstration — sans valeur », fournisseurs factices ; le site réel et le site de démonstration ne lisent jamais la base de l'autre ; entre eux ne passent que des messages signés (« crée une copie de tel type, avec telle empreinte de lien, jusqu'à telle date », « renouvelle », « recommence », « supprime », et l'état d'une copie, C.7 et C.19) ;
- une seule version du logiciel pour les deux sites, déployée ensemble (partie E).

### Ce que ce lot remplace

1. **Le lot DEMO-SITE** (site commun, décision du 15/11 vers 18 h 30). Il n'a jamais commencé : aucun code n'existe. Ce cahier reprend ce qui sert encore :
   - le réglage `PILIQI_SITE=reel|demonstration` et le refus d'une valeur inconnue ;
   - les fournisseurs factices exigés sur le site de démonstration ;
   - `noindex` et `robots.txt` ;
   - la reconstruction de chaque nuit, avec le contrôle d'isolation à zéro avant la mise en service ;
   - les numéros fictifs vérifiés absents du fichier public du répertoire Sirene ;
   - les adresses du semis sur des domaines réservés.
2. **La démonstration intérieure**, née à l'étape 1 et précisée par les lots DEMO (12/11), DEMO-V (13/11, T115) et la décision de l'archive (14/11) :
   - le magasin de démonstration de chaque société (code DEMO, sans SIRET, au territoire du siège) ;
   - les comptes d'entraînement ;
   - l'exception « le propriétaire vend dans le magasin de démonstration » (D71) ;
   - la fermeture après 30 jours sans opération, et la réouverture ;
   - la fermeture à la validation et la disparition des écrans (T115) ;
   - les JET 100 et 105 écrits dans les magasins des sociétés réelles (les deux codes restent, pour les copies seulement : C.18) ;
   - l'archive finale signée du magasin de démonstration du compte réel (pas encore codée : étape 3). L'archive d'une copie la remplace (C.18).
3. **Les décisions remplacées**, à marquer « remplacée » dans la matrice des décisions (H.4) : D68, D70, D71, T115, ARCHIVE-DEMO-14-11, Q4-g, 2.1-112, 2.1-113, 2.1-115, T35.2 ; et en partie : precision-1 (la part « jamais le propriétaire » tient), Q4-h, S6, T4, 2.1-079, T35.4 (la conservation des données du mode d'entraînement tient : C.18).

### Ce que dit la loi (caisse sécurisée, mode d'entraînement)

Recherche du projet du 12/11/2026 (`demo/decision-demo.md`), relue pour ce cahier :
- BOI-TVA-DECLA-30-10-30-20260325 (https://bofip.impots.gouv.fr/bofip/10691-PGP.html) :
  - § 50 : « Lorsque la transaction n'est que simulée au moyen d'un module de type « école » ou « test », les données sont également concernées » ;
  - § 130 : pas de suppression de données sans trace ;
  - § 150 : les données du mode école sont clairement identifiées (mention « factice » ou « simulation »), avec l'opérateur ;
  - § 155 : conservation ou archivage pendant six ans (LPF, art. L. 102 B) ;
  - § 180 : garder seulement les tickets Z ne suffit pas.
- Référentiel LNE de certification des logiciels de caisse, version 1.8 du 12/12/2025 :
  - exigence 5 : les données du mode école sont sécurisées comme les autres ; une base séparée est admise si elle entre dans les archives ;
  - exigence 13 : une purge seulement après une archive complète ;
  - exigence 16 : conservation six ans.

Ce que cela veut dire pour la copie. Le site de démonstration fait tourner le même logiciel, au même commit, avec le même module fiscal. Il signe, chaîne et clôture des ventes simulées. C'est un mode d'entraînement au sens du § 50. Et rien n'empêche un visiteur d'y encaisser un vrai client (C.17). Supprimer la base sans rien garder serait donc probablement contraire aux § 130, 155 et 180 et aux exigences 13 et 16. C'est pour cette raison que l'éditeur avait renoncé, le 12/11 (D70), à supprimer la démonstration intérieure.

**Décision de Claude (D76), appliquée par le lot, à confirmer par l'éditeur (question 1 de `questions-editeur.md`) : l'option (a).** Avant toute suppression de la base d'une copie (30 jours, « Recommencer à zéro », copie refaite après une mise à jour ou une migration ratée, clôture ou révocation de la société, nettoyage d'une copie échue), le site de démonstration :
1. clôture les journées en attente de chaque magasin de la copie (règle de la caisse) ;
2. inscrit le JET 105 (sortie du mode d'entraînement) dans chaque magasin ;
3. fait l'archive finale de la caisse de la copie (registre, journal des événements, tickets Z en PDF, totaux perpétuels), signée avec la clé de démonstration, et la vérifie (`caisse:verifier` de la copie, code 0) ;
4. la range hors de toute base, sous la référence de la copie, pour six ans (C.18) ;
5. seulement ensuite : `DROP DATABASE`.

La base disparaît donc en entier, comme l'a voulu Q3. L'archive ne contient que l'identité fictive et ce qu'ont tapé les visiteurs dans la caisse. Rien de la vraie société n'y est. L'option (b), la suppression pure, reste possible si l'éditeur la choisit : son risque est alors écrit en toutes lettres dans l'onglet « À faire vérifier par un professionnel » et dans la notice. La question reste posée au professionnel (T35.4) ; sa réponse prime.

Les clôtures mensuelle et de l'exercice et le format définitif de l'archive signée arrivent à l'étape 3. Jusque-là, l'archive d'une copie réunit ce qui existe (clôtures du jour, export du registre et du journal des événements, tickets Z), avec un sommaire signé. L'étape 3 la remplace par l'archive finale d'un magasin fermé pour cessation (H.5, A2).

### Place, isolation, vérifications

- **Place : après TVA27, avant RLS.** Ainsi :
  - les sociétés modèles ont déjà leur régime de TVA et leur crédit de départ (TVA27) ;
  - RLS couvre aussitôt les tables nouvelles du site réel et chaque copie, qui a les mêmes migrations communes.
- **Trois lots, relus chacun à part** (estimation : 45 à 65 heures en tout, relectures et corrections comprises, partie I) :
  - **DEMO-COPIE-1 : retrait du magasin de démonstration intérieur** (partie D, semis renommé, textes du retrait), 10 à 14 heures ;
  - **DEMO-COPIE-2 : site de démonstration, copies et demande** (parties A, B, C.1 à C.7, C.12, C.18, C.19), 18 à 26 heures ;
  - **DEMO-COPIE-3 : bandeau, e-mails, tâches des copies, mises à jour, semis des états, navigateur** (C.8 à C.11, C.13 à C.17, parties E, F.4 et F.7, textes de la copie), 15 à 22 heures.
  
  Entre DEMO-COPIE-1 et DEMO-COPIE-2, aucune société n'a de démonstration. C'est voulu : rien n'est encore en production, et le site réel reste juste à chaque envoi.
- **Les lots qui passent avant** (DEVIS-RET, MDP, IDENT, CESSION, NAF25, VIES-DEP, TVA27) n'ajoutent aucun code ni aucun test nouveau propre au magasin de démonstration intérieur. Une garde qui vise les opérations réelles s'écrit sur les magasins réels, avec le filtre qui existe déjà, sans branche nouvelle. Cas précis de TVA27 (décision de l'éditeur du 15/11 vers 15 h 45 : la démonstration n'est jamais bloquée) :
  - dans `Magasin::refusDeVente()`, le contrôle du régime de TVA et du crédit de départ se place après la branche existante de la démonstration (aucune branche nouvelle) ;
  - en base, la garde du régime ne porte que sur un magasin qui a un SIRET (`magasins.siret IS NOT NULL`) : le magasin intérieur n'en a pas, et cette condition devient vraie partout quand DEMO-COPIE-1 rend le SIRET obligatoire (choix 25) ;
  - le test « démonstration utilisable sans régime ni crédit » reste pendant TVA27 ; DEMO-COPIE-1 le supprime et retire la condition sur le SIRET (D.2, point 16 bis).
  
  Un test « démonstration permise » écrit quand même est noté dans le compte rendu de son lot ; DEMO-COPIE-1 le supprime.
- **Les lots touchent l'isolation et la caisse sécurisée** :
  - des tables nouvelles de la société (`demonstrations`, sa boîte d'envoi) ;
  - les gardes en base de la vente, des cabinets, des charges, des identifiants et des droits ;
  - le contrôle `app:verifier-isolation` ;
  - le routage de chaque requête du site de démonstration vers une base ;
  - les clés de signature, l'en-tête signé du registre, les codes du journal des événements et la version du module fiscal (3.0, D.7).
  
  Exception du point 3 de CLAUDE.md, pour chacun des trois lots : **toute la suite sur PostgreSQL, une fois, juste avant le dernier commit du lot** (`DB_PASSWORD="$PILIQI_PG_MOT_DE_PASSE" php artisan test --parallel`, code de sortie 0, contrôlé par `echo $?`), plus le groupe `copies` (G.2) pour DEMO-COPIE-2 et DEMO-COPIE-3.
- **Avant chaque commit** : les tests liés (cherchés par `git grep` des classes et méthodes changées), les tests en navigateur des écrans touchés, `tests/Browser/InventaireDesActionsTest.php`, `tests/Browser/Balayage/ContrasteDesDeuxModesTest.php`, le groupe `concurrence`, chacun au code 0 sur l'arbre exact du commit. Migrations changées : `php artisan migrate:fresh` avant. `vendor/bin/pint --dirty --format agent`.
- **CI** : relue au début de chaque lot et juste avant son commit. Rouge : commit à part « Réparation de la CI rouge de <commit> », avant ceux du lot.
- **Le semis** (point 6 de CLAUDE.md) se met à jour dans chaque lot, et `migrate:fresh --seed` finit par `app:verifier-isolation` à zéro problème.

### Les choix tranchés pour ce lot

Ces points n'ont pas encore de réponse de l'éditeur. Les choix ci-dessous s'appliquent au départ du lot. L'éditeur peut les contester ; sa réponse prime. Chacun entre dans la matrice des décisions. Ceux qui touchent la loi entrent aussi dans l'onglet « À faire vérifier par un professionnel » (décision de Claude, D76). Les vraies décisions de l'éditeur (coût, domaine, conservation) sont dans `questions-editeur.md`.

1. **Le lien ne se remontre pas.** Le site réel ne garde que l'empreinte : il ne peut pas réafficher le lien.
   - Le lien en clair s'affiche une seule fois, à la création et à chaque renouvellement, avec « Copier le lien ». Il part aussi, à ces deux moments, par e-mail à l'adresse de connexion confirmée.
   - « Ouvrir ma démonstration » n'a pas besoin du lien : le bouton envoie un billet d'entrée signé, valable 60 secondes (B.4).
   - Il n'y a pas de bouton « M'envoyer le lien par e-mail » à part : il ne pourrait que tirer un nouveau lien, donc couper toute l'équipe, comme « Renouveler le lien ». « Renouveler le lien » affiche le nouveau lien et l'envoie par e-mail ; sa fenêtre dit que l'ancien cessera de marcher pour tous. Question 3 de `questions-editeur.md`.
2. **Le secret du lien est après « # »** : `https://<site de démonstration>/d#<43 caractères>`. Le navigateur n'envoie jamais cette partie au serveur, ni dans une adresse, ni dans un en-tête `Referer`. Il peut pourtant rester dans l'historique du navigateur et chez une messagerie qui réécrit les liens pour les contrôler. Les textes le disent exactement : « Le secret du lien n'est jamais envoyé par le navigateur au serveur. Il peut rester dans l'historique du navigateur et chez une messagerie qui réécrit les liens : renouvelez le lien en cas de doute. » La maquette écrivait `/d/<secret>` : c'est la seule différence de forme.
3. **Le comptable de la copie d'une société est un expert-comptable déclaré** (précision de l'éditeur du 16/11 vers 1 h 30). Dans les modèles « Commerce ou atelier » et « Services », la société mise en avant a une déclaration d'expert-comptable, avec un compte actif, jamais un cabinet lié : un cabinet est une société d'un autre type. Dans le modèle « Cabinet », il n'y a ni rôle « propriétaire d'un client » ni compte d'un client : les clients fictifs ne se voient que depuis l'espace du cabinet, comme le cabinet les voit.
4. **La page de connexion de la copie est fermée**, comme « Mot de passe oublié », l'inscription et la saisie du code du courrier. Elles mènent à « Choisissez qui vous êtes » pour qui a un témoin.
5. **« E-mails qui seraient partis » ne montre que les e-mails de la société de la copie** : ceux écrits au nom de la société mise en avant, ou, dans la copie « Cabinet », au nom du cabinet. Jamais ceux d'une société cliente d'un autre type (précision du 16/11 vers 1 h 40). Tout compte du bandeau voit la page : c'est un outil de la démonstration, et une seule personne s'entraîne derrière tous les comptes (D8). Cette lecture est une exception écrite dans la puce « Produit » de CLAUDE.md (H.1, A3).
6. **Avant la mise en production, toutes les copies sont refaites à chaque déploiement**, après leur archive (partie « Ce que dit la loi »), et le registre du site de démonstration est vidé quand la base réelle est recréée (partie E). Avant l'étape 15, il n'y a que le poste de l'éditeur et la CI.
7. **Plafond de copies atteint : la demande attend** une place, elle n'est pas refusée. La carte le dit. Pour qu'une vague de fausses inscriptions ne prenne pas toutes les places, la moitié du plafond est réservée aux sociétés validées (`DEMONSTRATION_PART_DES_VALIDEES`, 50 % au départ ; question 4 de `questions-editeur.md`). Une demande se limite aussi par adresse IP (B.11).
8. **ClamAV réel sur le site de démonstration**, seule exception à « seulement les fournisseurs factices » : il est local et ne joint aucun service extérieur. Ainsi la démonstration ne sert jamais à faire circuler un fichier dangereux. En développement et en test, l'antivirus reste le factice.
9. **En production, le site de démonstration a sa machine et son serveur PostgreSQL à lui.** C'est une règle, pas une recommandation : une copie qui s'écrit remplirait le journal WAL, les sauvegardes et le disque du site réel, et le rôle qui crée des bases (`CREATEDB`) n'existe jamais sur le serveur du réel. `caisse:diagnostiquer` du site réel rougit, en production, si une base `demo_*` existe sur son serveur ou si son rôle a `CREATEDB`. Sur le poste de l'éditeur et dans la CI, un seul serveur PostgreSQL sert les deux sites. Le choix de l'hébergeur et son coût restent à l'étape 15 (question 5 de `questions-editeur.md`), pas la séparation.
10. **Un renouvellement coupe toutes les visites ouvertes**, celles du bouton du propriétaire comprises : tous les témoins de la copie sont retirés. Le propriétaire rentre en un clic par « Ouvrir ma démonstration » : il ne perd rien. Ainsi un ancien propriétaire, un poste prêté ou un lien perdu se coupent d'un seul geste.
11. **« Recommencer à zéro » ne change pas la date de fin** (D2). Elle reste celle de la création.
12. **Le sort de la copie selon l'état de la société** (D3) :
    - inscription close, ou société révoquée : copie archivée puis supprimée aussitôt ;
    - société suspendue ou résiliée : copie gardée jusqu'à sa date ; « Ouvrir » et « Renouveler le lien » restent permis (le propriétaire doit pouvoir couper un lien perdu) ; ni nouvelle copie, ni « Recommencer à zéro ».
13. **Une société validée par l'invitation de son cabinet voit la carte**, comme toute société (D4). Elle ne voit toujours pas l'étape du courrier.
14. **Le cabinet a l'étape « Ouvrir ma démonstration »** dans sa liste d'étapes (D5). Les textes de sa vérification n'en parlent pas.
15. **La première visite ouvre « Choisissez qui vous êtes »**, le propriétaire fictif en premier (D6). Les visites suivantes rouvrent le dernier compte choisi, sauf après une réfection de la copie (C.8).
16. **Les comptes du bandeau** (D7, précision du 16/11 vers 1 h 30) : seulement ceux de la société de la copie.
    - copie « Commerce ou atelier » : propriétaire, administrateur sans vente, administrateur qui vend, pour chaque magasin son ou ses responsables, ses vendeurs et son observateur, expert-comptable déclaré ;
    - copie « Services » : les mêmes rôles, sans articles ;
    - copie « Cabinet » : propriétaire du cabinet, collaborateurs ;
    - et tout compte que le visiteur crée dans sa copie.
    
    Aucun groupe « autres sociétés », aucun propriétaire d'un client, aucun compte d'une société d'un autre type.
17. **Les territoires** (D9) : dans le modèle « Commerce ou atelier », la société mise en avant a, en plus de ses deux magasins de métropole, un magasin à Fort-de-France (Martinique : 8,5 % et 2,1 %, heure des Antilles), dans le semis du poste comme dans le modèle. La Corse et La Réunion restent dans le semis du poste (COMPTOIR DES ILES), hors des copies.
18. **Le marqueur `demonstration` du contenu signé** (D10) : la clé `demonstration` n'est écrite, à vrai, que dans une copie ; le format du site réel ne change pas (aucune clé `demonstration` dans le contenu signé réel, comme aujourd'hui). Le filigrane et le bandeau des PDF d'un document émis lisent ce marqueur figé, jamais le mode du site au moment de l'impression. Le vérificateur d'intégrité du site réel signale tout enregistrement ou émetteur figé marqué `demonstration`. L'aperçu de l'e-reporting, qui n'est pas signé, perd son champ `demonstration` : il montre le vrai aperçu.
19. **Les JET 100 et 105 restent, pour les copies seulement** (D11). Le JET 100 (entrée en mode d'entraînement) s'inscrit dans chaque magasin de la société modèle à sa construction ; chaque copie en hérite. Le JET 105 (sortie) s'inscrit juste avant l'archive finale de la copie (partie « Ce que dit la loi »). Le site réel n'en écrit jamais, et son vérificateur signale un JET 100 ou 105 dans une base réelle.
20. **Le protocole des messages a une version** (D12). Le site de démonstration accepte la version courante et la précédente.
21. **Le mot « démonstration » du semis du poste devient « semis »** (D13) : `SemisDeDemonstration` → `SemisComplet`, `comptes-de-demonstration.txt` → `comptes-du-semis.txt`, `PILIQI_DEMO_MOT_DE_PASSE` → `PILIQI_SEMIS_MOT_DE_PASSE`, `piliqi.demo_mot_de_passe` → `piliqi.semis_mot_de_passe`, `DonneesDeDemonstrationTest.php` → `DonneesSemeesTest.php`, `MOT_DE_PASSE_DE_DEMONSTRATION` → `MOT_DE_PASSE_DU_SEMIS`, et les messages de `DatabaseSeeder` et `MotsDePasseDuSemis`. Le mot « démonstration » ne désigne plus que le site de démonstration.
22. **Les tâches du calendrier des magasins tournent dans chaque copie** (D14) : clôture automatique, passage en retard, expiration des devis, relances (capturées). Sinon la copie se figerait pendant 30 jours.
23. **`magasinsReels()` devient `magasins()`**, et ses 27 appels suivent. Le mot « réel » ne sert plus à exclure un magasin.
24. **`DEMO` redevient un code de magasin libre.**
25. **`magasins.siret` devient obligatoire** (`NOT NULL`), après la vérification de chaque chemin qui crée un magasin (action, semis, fabriques des tests).
26. **En production, le site réel refuse les domaines réservés** (`example.com`, `example.net`, `example.org`, `.example`, `.test`, `.invalid`, `.localhost`) à toute saisie d'une adresse de connexion, d'une adresse professionnelle d'expert-comptable et d'une adresse d'invitation. En développement et en test, le semis s'en sert : il reste permis.
27. **Les liens publics marchent dans la copie, pour qui a son témoin** : réponse à un devis, invitation d'un cabinet et demande de rattachement (copie « Cabinet » seulement, vers des sociétés fictives qui ne s'ouvrent pas), mot de passe d'un expert-comptable déclaré, transmission de la propriété, confirmation d'une adresse de connexion. Le visiteur peut ainsi accepter son propre devis comme son client, ou finir une transmission de la propriété en passant à l'administrateur qui la reçoit. Sans témoin, ils répondent « introuvable ».
28. **Les cas d'inscription n'entrent pas dans les modèles** : société qui attend son code, inscription close, refusée, révoquée, pièces déposées, demande jamais confirmée. Ils servent aux procédures de l'éditeur et au parcours d'inscription, fermés sur le site de démonstration. Ils restent dans le semis du poste.
29. **Un mot de passe de démonstration connu, affiché dans le bandeau.** Une vingtaine de gestes redemandent le mot de passe (fermer, rouvrir ou céder un magasin, changer le SIRET, transmettre la propriété, déclarer un expert-comptable, inviter, redonner l'accès, gérer les comptes, changer son profil : `MotDePasseRedemande` et ses dix fichiers). Dans un modèle, chaque compte a donc un mot de passe de démonstration, tiré à la construction, le même pour tous les comptes du modèle, qui suit la règle des mots de passe et n'est jamais un identifiant. Le bandeau l'affiche (« Mot de passe de démonstration : … »). La redemande reste la vraie : aucune exception n'est ajoutée à `MotDePasseRedemande`. Seul le changement de compte du bandeau saute le mot de passe (C.8). Un compte que le visiteur crée reçoit son mot de passe selon les vraies règles.
30. **Chaque société ne voit que son type** (précision du 16/11 vers 1 h 40). La carte, ses fenêtres, l'e-mail du lien, `/choisir`, le bandeau, les pages d'attente et la page des e-mails ne nomment jamais un autre type d'activité, une société d'un autre type ni une démonstration d'un autre type. Un test le balaie (N27).
31. **La franchise en base, le trimestriel et l'option pour les débits** : la société mise en avant a un seul régime (réel normal mensuel). Une société a un seul régime : on ne peut pas tous les montrer dans une seule société. Comme dans le vrai logiciel, le visiteur change le régime de la société fictive dans ses paramètres fiscaux ; les ventes suivantes suivent le nouveau régime (mention « TVA non applicable, art. 293 B du CGI », catégorie E de l'aperçu de l'e-reporting). `/choisir` le dit en une phrase. Question 6 de `questions-editeur.md` (copie adaptée au régime de la vraie société, refusée par défaut au nom de Q4).
32. **Les noms des témoins.** Sur le site de démonstration, le témoin de la copie s'appelle `__Host-piliqi-demo` en production, `piliqi-demo` ailleurs ; sa session s'appelle `__Host-piliqi-demo-session` en production, `piliqi-demo-session` ailleurs ; aucun `XSRF-TOKEN`. Sur le site réel, la session s'appelle `__Host-piliqi-session` en production (`Secure`, `Path=/`, sans `Domain`). Sur le poste et dans les tests, les deux sites se servent sous deux noms d'hôte (`127.0.0.1` pour le réel, `localhost` pour la démonstration), pour que leurs témoins ne se mêlent pas.
33. **Deux rôles PostgreSQL sur le site de démonstration, dès ce lot** (poste, CI et production) : `demo_admin` (`CREATEDB`, propriétaire des modèles et des copies, membre de `pg_signal_backend`), utilisé seulement par les commandes `demonstration:*` et par un travailleur dédié à la file `demonstration-admin` ; `demo_app` (sans `CREATEDB`, propriétaire de rien), pour les pages et les travaux des copies. Le site réel garde son seul rôle.
34. **L'état des copies remonte par un message signé que le site réel demande** : le message `etat` (référence seule), et une lecture de l'état général du site de démonstration (`GET /interne/etat`, signé par le site de démonstration). Ainsi le site réel n'est jamais lu par le site de démonstration, et l'éditeur est prévenu des échecs (C.19).

### Découpage en commits

Trois lots. Chaque commit passe ses tests ciblés au code 0. Chaque lot est relu, corrigé et poussé avant le suivant.

**DEMO-COPIE-1**
0. Si la CI de la tête est rouge : « Réparation de la CI rouge de <commit> ».
1. **« Démonstration : retrait du magasin de démonstration intérieur »** : partie D en entier (sans aucun marqueur `demonstration` au registre ni dans l'émetteur : il revient au lot 2) ; le semis sans magasin de démonstration ni comptes d'entraînement ; le renommage du semis (choix 21) ; les tests supprimés et réécrits ; dans CLAUDE.md, les phrases supprimées et celles qui ne parlent plus que du propriétaire qui ne vend pas (H.1) ; la matrice (décisions remplacées).

**DEMO-COPIE-2**
0. CI de la tête.
1. **« Démonstration : mode du site, domaine, témoins et gardes de démarrage »** : partie A.
2. **« Démonstration : registre, rôles, bases modèles et copies »** : C.1 à C.3, C.6, C.12, C.18 (archive avant suppression), l'étiquetage du semis par modèle (F.1 à F.3), le marqueur `demonstration` piloté par le mode du site et la version 3.0 du module fiscal (D.7, D.8).
3. **« Démonstration : demande depuis le site réel et messages signés »** : partie B, C.4, C.5, C.7, C.19.

**DEMO-COPIE-3**
0. CI de la tête.
1. **« Démonstration : bandeau, e-mails qui seraient partis et tâches des copies »** : C.8 à C.11, C.13 à C.17.
2. **« Démonstration : mises à jour, semis des états, textes »** : partie E, les états des demandes dans le semis (F.4, F.7), les textes (partie H), les tests en navigateur, la suite PostgreSQL complète, le groupe `copies`, `migrate:fresh --seed` au code 0.

---

### A. Le mode « site de démonstration » de l'application (même code)

1. **Le réglage.** `PILIQI_SITE=reel|demonstration`, lu une seule fois au démarrage (`config/piliqi.php`, clé `site`).
   - Une énumération `SiteDePiliqi` (`Reel`, `Demonstration`) et `SiteDePiliqi::courant()`.
   - Défaut : `reel`.
   - Une valeur inconnue empêche de démarrer, avec un message en français qui dit quoi écrire.
   - Aucun code ne décide du mode par `APP_ENV` ni par `app()->environment()` : seulement par `SiteDePiliqi`.
2. **Un seul code.** Le site de démonstration est le même dépôt, le même commit, la même version.
   - Ses routes à lui (`routes/demonstration.php` : `/d`, `/entrer`, `/entrer-par-le-compte`, `/choisir`, `/demonstration/changer-de-compte`, `/demonstration/courriels`, `/demonstration/quitter`, `/interne/messages`, `/interne/etat`), toutes nommées `demonstration.*`, ne se chargent qu'en mode démonstration.
   - Chaque classe propre au site de démonstration appelle `SiteDePiliqi::exiger(SiteDePiliqi::Demonstration)` et lève une exception hors de ce mode, même appelée autrement qu'à l'écran.
   - Chaque classe propre au site réel (demande, renouvellement, boîte d'envoi, lecture de l'état) exige `Reel` de même.
3. **Les gardes au démarrage, mode démonstration.** Un fournisseur de services chargé le premier (`SiteDePiliqiServiceProvider`) refuse de démarrer, avec la cause en français. Il ne remplace jamais un réglage en silence :
   - un pilote réel pour le répertoire Sirene, le registre national des entreprises, le courrier recommandé, l'annuaire de l'Ordre, la plateforme agréée ou VIES ;
   - un antivirus autre que `clamav` ou `factice` (choix 8 : `clamav` en production, `factice` en développement et en test) ;
   - un transport d'e-mail autre que `demonstration` ;
   - une variable d'un vrai service présente : `MAILTRAP_API_TOKEN`, `MAIL_HOST`, `SUPERPDP_CLIENT_ID`, `SUPERPDP_CLIENT_SECRET`, et toute clé d'un fournisseur réel listée dans `config/services.php` ;
   - une clé privée de la caisse dont la clé publique n'est pas épinglée dans `resources/caisse-securisee/cles-demonstration` (le `cle_id` d'une clé de caisse est l'empreinte SHA-256 de sa clé publique : `SignataireFiscal.php:22`) ;
   - une clé du journal d'audit (`JOURNAL_AUDIT_CLE`, et chacune de `JOURNAL_AUDIT_CLES_PRECEDENTES`) dont l'empreinte SHA-256 n'est pas épinglée « démonstration » (A.4, C.12) : l'identifiant `JOURNAL_AUDIT_CLE_ID` est une étiquette libre (`config/journal_audit.php:24-26`) et ne prouve rien ;
   - la clé privée des messages (`DEMONSTRATION_MESSAGES_CLE_PRIVEE`), qui n'appartient qu'au site réel ;
   - Laravel Octane, ou tout serveur qui garde l'application en mémoire d'une requête à l'autre (C.6 point 8) ;
   - une base sentinelle (`DB_DATABASE`) qui a une table, ou dont le nom n'est pas `demo_aucune_copie` (C.6 point 7) ;
   - un nom de témoin de session autre que celui du choix 32.
4. **Les gardes au démarrage, mode réel.** Le même fournisseur de services refuse :
   - en production, un pilote faux, pour chaque fournisseur, VIES compris. La garde d'aujourd'hui (`FournisseursExternesServiceProvider.php:79-88`, qui refuse tout pilote `faux` dès que `APP_ENV` vaut `production`) ne vaut plus qu'en mode réel ; sans ce changement, le site de démonstration, en production, ne démarrerait jamais ;
   - le transport `demonstration` ;
   - une clé privée de la caisse dont la clé publique est épinglée dans `resources/caisse-securisee/cles-demonstration` ;
   - une clé du journal d'audit (courante ou précédente) dont l'empreinte est épinglée « démonstration » ;
   - les clés publiques des messages (`DEMONSTRATION_MESSAGES_CLES_PUBLIQUES`) et la connexion `registre`, qui n'appartiennent qu'au site de démonstration ;
   - une route nommée `demonstration.*` dans la table des routes (un cache des routes construit en mode démonstration, par exemple) : l'application s'arrête, avec la cause en français ;
   - un `SESSION_DOMAIN` qui couvrirait l'hôte de `DEMONSTRATION_URL` ;
   - en production : un témoin de session sans le préfixe `__Host-` (choix 32), et une liste d'hôtes de confiance ouverte aux sous-domaines (A.10).
5. **La marque de la base.** Une table `site_de_la_base`, d'une seule ligne, dans les migrations communes :
   - sa migration de création écrit la valeur de `config('piliqi.site')` : `reel` sur le site réel, `demonstration` dans une base modèle, donc dans chaque copie ;
   - une garde en base refuse toute modification, toute suppression et toute seconde ligne ;
   - le site réel refuse de servir une base marquée `demonstration`, et `caisse:diagnostiquer` rougit ;
   - le site de démonstration refuse de servir une base qui n'est pas marquée `demonstration`.
   Ainsi une erreur de configuration (un site pointé sur la base de l'autre) est refusée au lieu d'écrire des données fictives dans le réel.
6. **Les fournisseurs factices.** En mode démonstration, chaque fournisseur extérieur est le faux : un pilote réel est refusé au démarrage (point 3), jamais remplacé en silence.
   - VIES reçoit enfin un pilote (`VIES_PILOTE=faux|reel`) ; le faux reprend les réponses que le semis simule aujourd'hui par `Http::fake` (`DatabaseSeeder.php:83-95`).
   - Le faux répertoire Sirene, en mode démonstration, ne connaît que les SIREN et SIRET fictifs du semis (F.5) et une plage réservée de numéros tirés pour les essais des visiteurs (C.11). Tout autre numéro répond « inconnu » : un visiteur ne crée jamais un magasin au SIRET d'une vraie entreprise. Aujourd'hui, le faux accepte tout SIRET de clé valide (`FauxRepertoireSirene.php:11-21`) : c'est gardé pour le semis du poste et les tests, changé seulement en mode démonstration.
   - `Http::preventStrayRequests()` au démarrage, pour tout le site : une requête vers l'extérieur lève une erreur, rendue en alerte française.
   - La liste `PILOTES_FACTICES` de `DatabaseSeeder.php:74-81` devient la liste unique, lue par le semis et par les gardes du démarrage.
7. **Les pages.**
   - Sur tout le site de démonstration : `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer`, et `robots.txt` qui refuse tout.
   - `Cache-Control: no-store` sur `/d`, `/entrer`, `/entrer-par-le-compte`, `/choisir` et sur toute réponse du site réel qui contient le lien (B.3).
   - Fermées, avec la page introuvable française : l'API.
   - Fermées, en menant à « Choisissez qui vous êtes » pour qui a un témoin, sinon introuvables : la connexion, « Mot de passe oublié », l'inscription (`/app/register`), la saisie du code du courrier (`/code`).
   - Ouvertes pour qui a le témoin de la copie (choix 27) : les liens publics de la copie, dont la confirmation d'une adresse de connexion et la transmission de la propriété.
   - Sans témoin valable, toute autre adresse répond la page introuvable française (C.15).
8. **La version et le mode.** `PILIQI_VERSION` (date et court numéro du commit), écrite au déploiement, s'affiche en bas de chaque page des deux sites et dans le bandeau de la copie, avec le mode du site (« site réel » ou « site de démonstration »). Ainsi un essai mal lancé se voit tout de suite (I.10).
9. **Le semis et les migrations sur le site de démonstration.** Le semeur refuse aujourd'hui tout ce qui n'est pas `local` ou `testing` (`DatabaseSeeder.php:117`). Une troisième permission, étroite, s'ajoute :
   - `PILIQI_SITE=demonstration` ;
   - base courante dont le nom commence par `demo_travail_` ;
   - appel par `demonstration:construire-les-modeles` seulement, qui passe au semeur un objet « construction en cours » (pas une variable d'environnement de plus).
   
   `db:seed` lancé à la main sur le site de démonstration reste refusé. En mode réel, en production, chaque chemin du semis reste refusé. En mode démonstration, `migrate`, `migrate:fresh`, `migrate:refresh`, `migrate:reset` et `db:wipe` sont refusés sur toute base dont le nom ne répond pas à `^demo_(travail|copie)_`, sauf les migrations du registre, sur la connexion `registre`, avec leur `--path`. Ainsi une fausse manœuvre ne donne jamais le schéma à la base sentinelle. C'est la porte la plus dangereuse du lot : chacun de ces refus a son test (G.1).
10. **Le domaine et les témoins** (choix 32).
    - Le site de démonstration est servi sur un domaine enregistrable à lui (par exemple `piliqi-demo.fr` ; le nom est la question 2 de `questions-editeur.md`), **jamais un sous-domaine du site réel**. Un sous-domaine ferait partie du même site que le réel : `SameSite=Lax` ne protégerait plus le réel des requêtes parties d'une page de la démonstration ; une page de la démonstration pourrait poser des témoins pour tout le domaine du réel (fixation de session) ; et `trustHosts()` du réel accepte aujourd'hui tous les sous-domaines d'`APP_URL` (`bootstrap/app.php:47-52`). Or le site de démonstration sert un Filament complet où n'importe quel porteur d'un lien saisit du contenu, avec une politique de contenu qui permet `unsafe-inline` et `unsafe-eval` : une faille d'injection de script y est plus probable qu'ailleurs, et elle ne doit jamais toucher le réel.
    - Sur le site réel, en production : témoin de session `__Host-piliqi-session` (`Secure`, `Path=/`, sans `Domain`), et `trustHosts()` avec une liste fermée d'hôtes (`HOTES_DE_CONFIANCE`, sans sous-domaines).
    - Sur le site de démonstration : les noms du choix 32, et aucun `XSRF-TOKEN` (Livewire porte son jeton dans la page).
    - Hors production (poste, CI, tests), les noms sans préfixe `__Host-` ni `Secure`, puisque le poste sert en `http` ; les deux sites sous deux noms d'hôte (`127.0.0.1` et `localhost`), pour que leurs témoins ne se mêlent pas : les témoins ne tiennent pas compte du port.
11. **Les caches de configuration, de routes et d'événements** se refont sur chaque serveur, après la pose de son `.env`, jamais dans le paquet commun du déploiement (E.1). Un cache de configuration fait en mode démonstration figerait `PILIQI_SITE=demonstration` ; un cache des routes ferait exister les routes de la démonstration sur le réel. La garde du point 4 arrête l'application dans ce cas.
12. **Les rôles PostgreSQL** (choix 33).
    - `demo_admin` : `CREATEDB`, pas superutilisateur, propriétaire des bases modèles et des copies, membre de `pg_signal_backend` (pour `DROP DATABASE … WITH (FORCE)`, qui ferme les sessions des autres rôles). Il ne sert qu'aux commandes `demonstration:*` et au travailleur de la file `demonstration-admin` (C.13), par une connexion à lui (`DB_DEMO_ADMIN_USERNAME`, `DB_DEMO_ADMIN_PASSWORD`).
    - `demo_app` : sans `CREATEDB`, propriétaire de rien, avec seulement les droits d'écrire dans les tables d'une copie (accordés à la construction du modèle, donc hérités par chaque copie). Il sert aux pages, à Livewire et aux travaux des copies. Une injection SQL ou un défaut dans une copie ne peut donc ni supprimer une autre copie, ni créer des bases jusqu'à remplir le disque.
    - Sur le poste et dans la CI, les deux rôles existent aussi (I.10, G.2), pour que les tests le prouvent.

### B. Le côté du site réel

1. **Les données.** Deux tables de la société (`BelongsToSociete` + `SocieteScope`), chacune dans sa migration de création :
   - `demonstrations` :
     - `uuid`, `societe_id` ;
     - `reference` : 128 bits tirés au hasard, en hexadécimal (32 caractères), unique dans tout le logiciel. C'est une unicité voulue, ajoutée au point 2 du protocole (H.1) : c'est l'adresse de la copie dans le registre du site de démonstration. Elle n'est jamais affichée. Elle ne sort du site réel que dans les messages signés et dans le billet d'entrée signé (B.4), qui ne vaut qu'une fois et 60 secondes ;
     - `repere` : 6 caractères tirés au hasard (lettres sans ambiguïté), affichés sur la carte et dans le bandeau de la copie, pour que le propriétaire reconnaisse la sienne (C.4). Il ne donne aucun accès ;
     - `type_d_activite` de la société au moment de la demande ;
     - `empreinte_du_lien` (SHA-256 du lien, en hexadécimal), `generation_du_lien` (1 à la création, puis un de plus à chaque renouvellement), `lien_tire_le` ;
     - `generation_confirmee` et `confirmee_le` : la dernière génération que le site de démonstration a reçue (accusé `200` du message `renouveler_le_lien`) ;
     - `cree_le`, `fin_le` (instant UTC : 30 jours après la création, à la seconde) ;
     - `etat` : `demandee`, `en_preparation`, `en_attente_d_une_place`, `prete`, `en_echec`, `terminee`, `supprimee` ;
     - `essais_en_echec` (nombre) et `cause_de_l_echec` (en clair, sans rien de la société) ;
     - `recommencee_le` (le dernier « Recommencer à zéro » ; une remise à zéro par une mise à jour n'y est pas : le site réel ne la connaît pas, E.4) ;
     - `demandee_par` (le compte du propriétaire, clé composée `(societe_id, user_id)`) ;
     - index unique partiel : une seule ligne par société dont l'état est `demandee`, `en_preparation`, `en_attente_d_une_place` ou `prete`. Une ligne `en_echec` n'y compte pas (point 13).
   - `messages_vers_la_demonstration` (boîte d'envoi) :
     - `societe_id`, `demonstration_id` (clé composée vers `(societe_id, id)` de `demonstrations`) ;
     - `quoi` (`creer`, `renouveler_le_lien`, `recommencer`, `supprimer`, `etat`), le contenu sans signature, `cree_le`, `envoye_le`, `accuse_le`, `tentatives`, `derniere_erreur` (en clair, sans le contenu) ;
   - ni le lien en clair, ni le nom de la base de la copie, ni rien de ce que les visiteurs y font ;
   - `ReglesDIsolation` déclare les deux tables ; `app:verifier-isolation` les contrôle par SQL et par Laravel, avec un test qui prouve qu'il trouve une faute dans chacune ;
   - la politique de sécurité des lignes de chacune vient au lot RLS.
2. **La carte « Ma démonstration ».**
   - **Où** : sur le tableau de bord de l'espace de la société et sur celui de l'espace du cabinet.
   - **Qui** : le propriétaire seul. Jamais un administrateur (même de tous les magasins et autorisé à tout), un responsable, un vendeur, un observateur, un collaborateur, un compte d'un cabinet lié, ni un expert-comptable déclaré.
   - **Quand** : avant comme après la validation. Pas dans une inscription close ni dans une société révoquée.
   - **Mode** : seulement sur le site réel. Le site de démonstration ne montre jamais cette carte (pas de démonstration de la démonstration).
   - **Un seul type** (choix 30) : la carte ne parle que du type de la société. Aucun texte ne nomme ni ne décrit un autre type ou sa démonstration.
   - **Ses états**, avec les textes de la maquette (version 3) :
     - « Adresse à confirmer » : « Confirmez d'abord votre adresse de connexion : le lien de votre démonstration ne part qu'à une adresse confirmée. » Boutons : « Créer ma démonstration » inactif, « Renvoyer le lien de confirmation » ;
     - « À créer » : pour une société « Commerce ou atelier », « Une société fictive, déjà remplie : plusieurs magasins, toute l'équipe, treize mois de ventes, d'achats et de clôtures. Rien de votre vraie société n'y entre. » ; pour une société de services, la même phrase sans les achats ; pour un cabinet, « Un cabinet fictif, déjà rempli : ses collaborateurs, ses clients et ce qu'ils vous autorisent. Rien de votre vrai cabinet n'y entre. » Bouton : « Créer ma démonstration ». Après une copie terminée, la phrase grise « Votre démonstration précédente s'est terminée le 16/12/2026. » ;
     - « En préparation » : « Votre démonstration se prépare. Elle s'ouvrira dès qu'elle sera prête. » ; « en attente d'une place » si le plafond est atteint (choix 7) : « Votre démonstration sera prête dès qu'une place se libère. » ;
     - « Prête » : « Supprimée le 16/12/2026, dans 30 jours. Vous pouvez donner le lien à votre équipe. Repère de votre démonstration : K7M2QD. » Boutons : « Ouvrir ma démonstration », « Renouveler le lien », « Recommencer à zéro » ;
     - sous « Renouveler le lien », tant que l'accusé du site de démonstration n'est pas reçu : « Coupure de l'ancien lien en cours… » ; puis « Ancien lien coupé le 18/11/2026 à 10:42. » ; après 15 minutes sans accusé : « La démonstration n'a pas encore reçu le renouvellement : l'ancien lien marche peut-être encore. Nous réessayons ; l'éditeur est prévenu. » ;
     - « En échec » : « Votre démonstration n'a pas pu être créée. Nous réessayons ; l'éditeur est prévenu. » ; après le dernier essai (point 13) : la carte revient à « À créer », avec la phrase grise « Votre démonstration n'a pas pu être créée le 16/11/2026. Vous pouvez la redemander. » ;
     - sous les boutons, en gris : « Chaque création, renouvellement, ouverture et nouveau départ s'inscrit au journal d'audit de la société, sans jamais le lien. »
   - **Un site de démonstration absent** (poste de développement sans site de démonstration lancé, `DEMONSTRATION_URL` vide) : la carte le dit (« Le site de démonstration n'est pas lancé sur ce poste : voir le README. »), sans erreur.
3. **« Créer ma démonstration »** (`DemanderUneDemonstrationAction`).
   - Fenêtre de confirmation : les 30 jours, « Rien de votre vraie société n'y entre. », et la phrase exacte du choix 2.
   - Contrôles : propriétaire ; adresse de connexion confirmée ; société ni close, ni révoquée, ni suspendue, ni résiliée (choix 12) ; aucune copie en cours (sinon la carte montre celle-ci, sans second message) ; limites du point 11 ; jeton de demande répétée.
   - Dans une seule transaction, sous un verrou de la ligne de la société : la référence (`random_bytes(16)`), le repère, le lien (`random_bytes(32)`, en base64url, 43 caractères), son empreinte SHA-256, la ligne `demonstrations`, le message `creer` dans la boîte d'envoi, l'entrée du journal d'audit.
   - **Le lien ne passe que par la réponse de l'action qui le tire.** Il n'est jamais placé dans une propriété Livewire qui persiste d'une requête à l'autre (l'instantané de la page reviendrait dans le corps de chaque requête suivante), ni dans la session, ni dans un message flash : la colonne `sessions.payload` n'est pas chiffrée (`SESSION_ENCRYPT=false`, `config/session.php:50`) et entre dans les sauvegardes. La fenêtre le reçoit dans la réponse de l'action, avec `Cache-Control: no-store`, et la propriété qui le porte est vidée dès que la fenêtre se ferme.
   - Une demande répétée après une réponse perdue rend la même copie, mais la réponse gardée pour elle ne porte jamais le lien : « Le lien vous a été envoyé par e-mail. S'il est perdu, renouvelez-le. »
   - Réponse : une fenêtre montre le lien une seule fois, avec « Copier le lien » et la phrase « Ce lien vous a aussi été envoyé par e-mail. Il ne s'affichera plus : gardez-le. », puis la phrase du choix 2.
   - L'e-mail part par un travail chiffré (`ShouldBeEncrypted`) : le lien n'est jamais écrit en clair dans la table `jobs`. Après le dernier échec, le travail efface sa ligne de `failed_jobs` et le propriétaire est prévenu : « Le lien de votre démonstration n'a pas pu partir par e-mail. Renouvelez-le depuis votre compte. »
4. **« Ouvrir ma démonstration ».**
   - Le site réel signe un billet d'entrée : même format et même clé que les messages, mais une audience à lui (`aud = entree`, quand les messages portent `aud = messages`), `quoi = billet_d_entree`, valable 60 secondes, un nonce. Il porte la référence. La référence seule ne donne aucun accès : sans la signature du site réel, rien n'entre.
   - Une page du site réel envoie le billet au site de démonstration (`POST /entrer-par-le-compte`), par un formulaire qui part seul, dans un nouvel onglet. Jamais dans une adresse.
   - **La politique de contenu de cette seule page.** La politique de toutes les réponses porte `form-action 'self'` (`AjouterLesEnTetesDeSecurite.php:42`) : le navigateur bloquerait le formulaire. Cette page pose sa propre politique, que l'intergiciel garde (il garde déjà un en-tête posé par la réponse) : la politique commune avec `form-action 'self' <origine exacte de DEMONSTRATION_URL>`, et `Referrer-Policy: no-referrer`, `Cache-Control: no-store`. Aucune autre page n'élargit `form-action`. Le formulaire part par un petit fichier servi par le site (`/js/envoyer-le-billet.js`), jamais par un script écrit dans la page. C'est le seul formulaire du logiciel qui parte vers un autre site (puce « Écrans », H.1).
   - Le site de démonstration crée un témoin de la génération courante du lien (C.5).
   - Copie encore en préparation : la page d'attente du site de démonstration, qui se relit seule.
   - Journal d'audit ; au plus 30 ouvertures par heure.
5. **(Retiré)** « M'envoyer le lien par e-mail » n'existe pas (choix 1). Le numéro reste pour que les renvois des autres notes tiennent.
6. **« Renouveler le lien ».**
   - Fenêtre : « Le lien n'est gardé nulle part : PILIQI en tire un nouveau, l'affiche ici et vous l'envoie par e-mail. L'ancien cessera de marcher dès que la démonstration l'aura reçu, en général en quelques secondes, sur tous les appareils, pour tous ceux à qui vous l'aviez donné, et toutes les visites ouvertes se fermeront, la vôtre comprise : rouvrez-la par « Ouvrir ma démonstration ». Votre démonstration et ce que vous y avez fait restent. » Mot de passe redemandé.
   - Nouveau lien (génération suivante), message `renouveler_le_lien` avec la génération, e-mail chiffré comme au point 3, journal d'audit. Le nouveau lien s'affiche une seule fois, comme au point 3. La date de fin ne change pas.
   - La carte suit l'accusé (point 2) : « Coupure de l'ancien lien en cours… », puis « Ancien lien coupé le … ». Sans accusé en 15 minutes : alerte à l'éditeur (`AlerteEditeur`) et phrase au propriétaire.
   - Au plus 5 renouvellements par jour.
7. **« Recommencer à zéro ».**
   - Fenêtre : « Votre démonstration repart de la société fictive de départ ; le lien reste le même ; la date de fin ne bouge pas. »
   - Message `recommencer`, journal d'audit, au plus 3 fois par jour.
   - La carte dit ensuite « Remise à zéro le 20/11/2026 à 10:42. »
8. **La fin à 30 jours.**
   - Une tâche du site réel, chaque heure (`demonstration:marquer-les-fins`), passe à `terminee` chaque ligne dont `fin_le` est passée, envoie `supprimer` (le site de démonstration archive puis supprime de lui-même à la même heure, C.14 ; le message n'est qu'une seconde sûreté), et inscrit « Démonstration terminée (30 jours) » au journal d'audit, au nom du système.
   - La carte revient à « À créer », avec la phrase grise du point 2. Une nouvelle copie repart de zéro, avec un nouveau lien.
   - **Comment lisent les commandes et les travaux du site réel** (`demonstration:marquer-les-fins`, `demonstration:relancer-les-messages`, `demonstration:renvoyer-les-demandes`, `demonstration:lire-l-etat`, `EnvoyerLesMessagesALaDemonstration`) : ils tournent hors de tout contexte de société. Ils lisent donc par `withoutGlobalScopes`, avec un filtre explicite (`societe_id` et `demonstration_id`), seulement les colonnes nommées (identifiant, référence, état, fin, empreinte, génération : jamais un nom). Chaque écriture et chaque entrée du journal d'audit se fait dans le contexte de la société de la ligne. Ces lectures sont ajoutées à la puce « Produit » de CLAUDE.md (H.1, A3) et testées par N12.
9. **Les autres fins.**
   - Inscription close (faute de code, ou par la procédure de l'éditeur) et révocation de la société : message `supprimer` dans la même transaction, état `supprimee`, journal d'audit (choix 12). Le site de démonstration archive puis supprime (C.14).
   - Une autre personne saisit le code du courrier et devient propriétaire (tous les comptes d'avant sont retirés) : la copie est supprimée de même ; le nouveau propriétaire en crée une s'il le veut.
   - Transmission de la propriété : la copie reste ; le nouveau propriétaire la tient. La carte du nouveau propriétaire dit une fois : « La démonstration a été créée par l'ancien propriétaire. Pour lui retirer l'accès, renouvelez le lien. » Un renouvellement coupe toutes les visites, celles de l'ancien propriétaire comprises (choix 10).
10. **L'e-mail du lien** (`LienDeLaDemonstration`), en français, comme la maquette :
    - objet « Votre démonstration PILIQI » ;
    - « voici le lien de la démonstration de votre société. Il ouvre une société fictive, sans votre identifiant ni votre mot de passe : gardez-le pour vous et votre équipe. Il marche jusqu'au 16/12/2026. Repère de votre démonstration : K7M2QD. » ;
    - « Si ce lien a été donné à quelqu'un par erreur, renouvelez-le depuis votre compte : l'ancien ne marchera plus. » ;
    - la phrase exacte du choix 2 ;
    - aucun autre type d'activité nommé (choix 30) ;
    - seulement à l'adresse de connexion confirmée du propriétaire, jamais à une autre adresse, jamais avec l'identifiant ;
    - avec le transport `log` du développement, le lien est remplacé par « (lien masqué dans le journal) » avant l'écriture (écouteur de `MessageSending`) : il n'entre jamais dans `storage/logs`.
11. **Les limites.**
    - 30 ouvertures par heure ; 5 renouvellements par jour ; 3 « Recommencer à zéro » par jour ; une copie à la fois ; les limites d'e-mails de CLAUDE.md (60 par heure et par membre).
    - Créations : au plus 3 par adresse IP et par heure, et 10 par adresse IP au total sur 30 jours, toutes sociétés confondues (limite du site, dans le cache du réel, par l'empreinte de l'adresse, jamais l'adresse en clair).
    - La moitié des places du site de démonstration est réservée aux sociétés validées (choix 7) : le message `creer` porte `validee` (vrai ou faux), seule donnée de la société qui passe, sans rien dire d'elle (Q4).
    - Chaque refus se dit en clair : « Vous avez déjà renouvelé ce lien 5 fois aujourd'hui. Réessayez demain. »
12. **Le journal d'audit.** Catégorie « gestion des accès » (gardée 5 ans), jamais le lien, jamais son empreinte, jamais la référence. `ActionAuditee` perd `DemonstrationOuverte` et `DemonstrationFermee`, et gagne :
    - `DemonstrationCreee` (« Démonstration créée ») ;
    - `DemonstrationOuverteParLeBouton` (« Démonstration ouverte depuis le compte ») ;
    - `LienDeDemonstrationRenouvele` (« Lien de la démonstration renouvelé ») ;
    - `DemonstrationRecommencee` (« Démonstration remise à zéro ») ;
    - `DemonstrationTerminee` (« Démonstration terminée », avec la cause : 30 jours, clôture, révocation, nouveau propriétaire, échec).
    
    Attention : `ActionAuditee` est modifié en ce moment par la course DATEOP ; relire avant d'écrire.
13. **La boîte d'envoi, le transport et l'état.**
    - Un travail (`EnvoyerLesMessagesALaDemonstration`, file `demonstration`) lit les messages non envoyés d'une copie, dans l'ordre de cette copie (les copies ne s'attendent pas entre elles), les signe au moment de l'envoi (`aud = messages`, `emis_le`, nonce neuf), et les envoie par HTTPS à `POST /interne/messages` du site de démonstration.
    - Reprises : 30 secondes, 2 minutes, 10 minutes, puis toutes les 5 minutes par la tâche `demonstration:relancer-les-messages`.
    - Réponses : `202` (en préparation, ou en attente d'une place), `200` (prête, ou fait), `409` (en mise à jour : réessayer), `410` (inconnue ou supprimée), `422` (refusé, avec la cause). Le site réel n'en tire que l'état de la carte, l'accusé d'une génération et la cause d'un échec.
    - **L'état revient à la demande du site réel.** Pour chaque ligne qui n'est ni `prete` ni finie, et pour chaque renouvellement sans accusé, `demonstration:relancer-les-messages` envoie toutes les 5 minutes le message `etat` (référence seule), qui répond l'état de la copie au registre. L'affichage de la carte le demande aussi, au plus une fois par minute et par société.
    - **Les passages d'état** : `demandee` → `en_preparation` ou `en_attente_d_une_place` → `prete` ; `en_echec` → nouvel essai (le site de démonstration réessaie seul, C.3) ; après 3 réponses `en_echec` à 1 heure d'écart, la ligne passe `terminee` avec la cause « échec », l'éditeur est prévenu, la carte revient à « À créer » avec sa phrase grise, et une nouvelle demande est permise.
    - Le site réel n'attend jamais le site de démonstration pour un geste : un geste réussit dès que le message est dans la boîte d'envoi. La carte dit ensuite l'état réel (point 2), jamais un état que la démonstration n'a pas confirmé.
    - Le site de démonstration ne joint jamais le site réel et ne lit jamais sa base. Le site réel lit chaque heure l'état général du site de démonstration (`demonstration:lire-l-etat`, C.19) et lève une `AlerteEditeur` pour chaque problème qu'il y trouve.
14. **La liste d'étapes.** « Essayer le magasin de démonstration » devient « Ouvrir ma démonstration », dans la liste de la société (après « créer le premier magasin », « ajouter l'équipe ») et dans celle du cabinet (choix 14). Elle est cochée quand une copie existe ou a existé. Son lien mène à la carte. Une société validée par l'invitation de son cabinet la voit (choix 13).
15. **Les domaines réservés** (choix 26) : en production, sur le site réel, une adresse de connexion, une adresse professionnelle d'expert-comptable ou une adresse d'invitation sur un domaine réservé est refusée : « Cette adresse est une adresse d'exemple : aucun e-mail n'y arrive. Saisissez une vraie adresse. »

### C. Le site de démonstration

1. **Le registre des copies.** Une base à lui, `piliqi_demo_registre`, sur la connexion `registre` (rôle `demo_admin` pour les commandes et le travailleur de la file `demonstration-admin` ; rôle `demo_app` pour les pages, qui lisent le registre et n'y écrivent que les témoins, les messages reçus et les travaux), avec ses migrations à lui (`database/migrations-registre/`), passées seulement en mode démonstration. Une migration de création par table, comme ailleurs.
   - `modeles` : type (`commerce`, `services`, `cabinet`), nom de base, construit le, version du code, empreinte du schéma, mot de passe de démonstration (choix 29 ; fictif, propre au modèle), état (`en_service`, `precedent`, `en_construction`, `en_echec`), cause d'un échec.
   - `copies` : référence (reçue du site réel, contrôlée par `^[0-9a-f]{32}$`), repère, type, validée (vrai ou faux, B.11), nom de base (`demo_copie_<ULID en minuscules>`), créée le, fin, génération du lien, état (`en_preparation`, `en_attente_d_une_place`, `prete`, `en_echec`, `a_refaire`, `en_migration`, `en_lecture_seule`, `en_archivage`, `en_suppression`, `supprimee`, `perdue`), essais, version, empreinte du schéma, taille mesurée, dernière visite, dernière passe des tâches, dernière vérification du registre fiscal, message à montrer et son motif.
   - `liens` : copie, empreinte, génération, fin, retiré le.
   - `temoins` : empreinte du témoin, copie, génération du lien à sa création, créé le, fin, vu le, retiré le, compte choisi en dernier. Au plus 50 témoins actifs par copie : au-delà, le plus ancien est retiré.
   - `messages_recus` : nonce, audience, reçu le ; effacés après 1 jour.
   - `archives` : référence de la copie, chemin, empreinte SHA-256, sommaire signé, créée le, à effacer le (six ans plus tard, C.18).
   - les tables techniques du site : `jobs`, `failed_jobs`, `job_batches` (les files du point 13), `cache`, `cache_locks` (le cache `registre`, point 6). `failed_jobs` se purge au bout de 30 jours au plus ; les lignes de `jobs` et de `failed_jobs` d'une copie s'effacent à sa suppression (la référence est dans la charge utile, point 13).
   - Rien ne vient de la vraie société : ni nom, ni SIREN, ni adresse, ni compte. Seulement la référence, le type, l'empreinte du lien, la génération, la fin et le fait qu'elle soit validée.
   - Une copie supprimée garde sa ligne 30 jours (pour répondre « cette démonstration est terminée »), puis la ligne s'efface ; sa ligne d'`archives` reste six ans.
   - Le registre n'est pas sauvegardé : une base `demo_copie_*` sans ligne est un orphelin, supprimé la nuit, sous les gardes du point 2.
2. **Les bases modèles, refaites chaque nuit** : `demonstration:construire-les-modeles`, à 01 h 10, heure de Paris (hors de 2 h–3 h), sur un seul serveur, par `demo_admin`. Pour chaque type, l'un après l'autre :
   1. créer une base de travail vide `demo_travail_<type>_<AAAAMMJJHHMM>` depuis `template0`, en UTF8, collation `C.UTF-8`, comme le site réel ;
   2. y passer les migrations communes (`database/migrations`), puis celles du site de démonstration (`database/migrations-demonstration/` : `comptes_du_bandeau`, `courriels_de_la_demonstration`) ; elles posent le fuseau UTC et la marque `demonstration` ; puis accorder à `demo_app` les droits d'écriture sur les tables et les séquences, avec les mêmes privations qu'au réel à l'étape 15 (ni `UPDATE`, ni `DELETE`, ni `TRUNCATE` sur les tables en ajout seul) ;
   3. tirer le mot de passe de démonstration du modèle (choix 29) ;
   4. y semer le modèle (`--modele=<type>`, partie F), par les vraies actions et les fournisseurs factices, planificateur suspendu (`SemisEnCours`) ; le semis inscrit le JET 100 dans chaque magasin de la société modèle (choix 19) ;
   5. lancer `app:verifier-isolation` sur cette base : zéro problème exigé ;
   6. lancer `caisse:verifier --complet` avec la clé publique de démonstration : code 0 exigé ;
   7. nettoyer : vider `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens` ; ne pas écrire la liste des comptes sur le disque (le bandeau la lit en base, point 8) ; garder tout le reste, qui est le contenu ;
   8. geler : `VACUUM (FREEZE, ANALYZE)`, pour qu'aucune copie ne refasse ce travail plus tard ;
   9. fermer : `ALTER DATABASE … WITH ALLOW_CONNECTIONS false IS_TEMPLATE true` ;
   10. basculer : le registre pointe le nouveau modèle (une ligne, sous un verrou consultatif par type) ; jamais de renommage de base ;
   11. l'ancien modèle reste « précédent » jusqu'à la construction réussie suivante, puis `ALTER DATABASE … IS_TEMPLATE false` et `DROP DATABASE`, sous le même verrou, après la fin des copies en cours.
   - **Si une étape échoue** : l'ancien modèle reste en service, la base de travail est supprimée, la cause s'écrit au registre, et l'échec paraît dans l'état publié (C.19), que le site réel lit chaque heure et transforme en `AlerteEditeur` (une par nuit et par type). Au-delà de 3 jours sans construction réussie, `caisse:diagnostiquer` du site de démonstration le signale.
   - **Les fichiers du modèle** (pièces du fournisseur et pièces de charge semées par `PieceDuSemis`) s'écrivent dans `storage/demonstration/modeles/<nom de base>/`, qui suit le pointeur et s'efface avec l'ancien modèle.
   - **Le nettoyage**, chaque nuit après la construction (`demonstration:nettoyer`) :
     - une base `demo_copie_*` absente du registre, ou dont la copie est supprimée ou terminée : archive (C.18) si sa marque et son registre fiscal se lisent, puis `DROP DATABASE … WITH (FORCE)` ;
     - une base `demo_travail_*` de plus de 6 heures : supprimée (elle n'a jamais servi de copie) ;
     - un dossier de fichiers sans copie vivante : supprimé ;
     - une copie du registre dont la base n'existe plus : marquée `perdue`, refaite depuis le modèle à la visite suivante, avec un message (E.7).
     - Le nettoyage ne lit que des noms et ne supprime que ce qui répond au motif exact `^demo_(copie|travail)_[a-z0-9_]+$`.
     - **Il refuse d'agir**, et le dit dans l'état publié (alerte à l'éditeur), quand le registre ne compte aucune copie alors que des bases `demo_copie_*` existent, ou quand plus de 10 % des copies vivantes seraient supprimées d'un coup. Un registre vide ou mal pointé ne fait donc jamais tout disparaître.
   - **À mesurer au lot** : durée de chaque construction (les trois doivent finir avant 2 h, sinon l'heure avance), taille de chaque modèle (`pg_database_size`) et de son dossier.
3. **La copie par `TEMPLATE`.** Le message `creer` (point 7) met la copie « en préparation » et lance un travail `CreerUneCopie`, sur la file `demonstration-admin`, que seul le travailleur de `demo_admin` traite :
   - `CREATE DATABASE demo_copie_<ulid> TEMPLATE <modèle en service> OWNER demo_admin CONNECTION LIMIT 20` ; le nom ne contient rien de la société ;
   - aussitôt après, connecté à la copie, la fonction unique « réglages de la base » (celle qu'appelle la migration du fuseau) repose ce que `CREATE DATABASE … TEMPLATE` ne recopie pas : `ALTER DATABASE … SET timezone TO 'UTC'`, les `GRANT … ON DATABASE` à `demo_app`, et tout réglage qu'un futur lot ajoutera ; un test compare `pg_db_role_setting` et les droits du modèle et d'une copie ;
   - copie du dossier de fichiers du modèle vers `storage/demonstration/copies/<référence>/`, par `Process` avec un tableau d'arguments (`['cp', '-a', '--reflink=auto', $source, $cible]`), jamais par une chaîne de shell, après le contrôle du motif de la référence ;
   - lien (empreinte reçue), génération, état `prete`, version et empreinte du schéma au registre ;
   - une copie à la fois, deux au plus (réglable), pour borner les écritures sur le disque ;
   - plafond `DEMONSTRATION_COPIES_MAX` atteint (200 au départ, à revoir après mesure), avec la part réservée aux sociétés validées (choix 7) : état `en_attente_d_une_place`, réponse `202` ; la place libérée va d'abord à la plus ancienne demande d'une société validée, puis aux autres, dans l'ordre ;
   - échec ou modèle occupé : aucune base à moitié faite (`DROP DATABASE … WITH (FORCE)` de ce qui a pu naître, avant tout contenu), nouvel essai (30 secondes, 2 minutes, 10 minutes), puis état `en_echec`, répondu au site réel par le message `etat`, et repris une heure plus tard ; l'échec paraît dans l'état publié (C.19) ;
   - stratégie de copie (`WAL_LOG`, `FILE_COPY`, et, en PostgreSQL 18, `file_copy_method = clone`) : à lire dans la documentation de PostgreSQL 18 et à mesurer au lot (durée, place, volume écrit dans le journal WAL) ; le compte rendu donne les chiffres ;
   - le modèle est intact après la copie (test).
4. **L'entrée par le lien.**
   - Le lien est `https://<site de démonstration>/d#<secret>`.
   - La page `/d` charge un fichier statique servi par le site (`/js/entrer-dans-la-demonstration.js`) et pose sa propre politique de contenu, stricte : `default-src 'none'; script-src 'self'; connect-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'`, sans `unsafe-inline` ni `unsafe-eval` et sans nonce (un nonce ajouté à la politique commune ferait ignorer `unsafe-inline` et casserait Filament). Le script lit le secret, l'envoie par `POST /entrer` dans le corps, puis efface l'adresse (`history.replaceState`).
   - Si le navigateur a déjà un témoin valable d'une autre copie, `/d` demande d'abord : « Vous êtes déjà dans une autre démonstration (repère K7M2QD, créée le 16/11/2026). Ouvrir celle de ce lien à la place ? » Ainsi un lien reçu d'un inconnu ne fait jamais entrer quelqu'un dans une copie qu'il croirait la sienne.
   - `/entrer` est hors du jeton CSRF et sans session, exige l'en-tête `Origin` du site de démonstration, est limitée à 10 essais par minute et par adresse IP (cache `registre`, point 6).
   - Le serveur calcule l'empreinte, la cherche dans `liens` (génération en cours, non retirée, non échue), crée le témoin du choix 32 (`Secure` et préfixe `__Host-` en production, `HttpOnly`, `SameSite=Lax`, `Path=/`, sans `Domain`), d'une valeur de 256 bits tirée au hasard dont le registre ne garde que l'empreinte, valable jusqu'à la fin de la copie, et redirige vers `/choisir`.
   - Le témoin n'est pas chiffré par Laravel : il est exclu d'`EncryptCookies` et lu brut.
   - Lien faux, ancien lien, lien d'une copie supprimée : la même page introuvable, avec le même code, après le même délai.
   - Le champ du secret est dans `dontFlash` et n'est écrit dans aucun journal.
   - Sans script, la page ne marche pas : acceptable, Filament exige déjà le script.
   - Le bandeau et `/choisir` montrent le repère et la date de création de la copie (B.1), pour qu'on reconnaisse la sienne.
5. **L'entrée par le bouton du propriétaire** : `POST /entrer-par-le-compte` reçoit le billet signé (B.4).
   - Contrôles : signature, audience `entree` (un message d'audience `messages`, `creer` par exemple, est refusé), `quoi = billet_d_entree`, destinataire, 60 secondes, nonce jamais vu (consommé), copie connue, en-tête `Origin` égal à l'origine exacte du site réel (`DEMONSTRATION_ORIGINE_DU_SITE_REEL`). Un billet envoyé depuis la page d'un autre site, même valable, est refusé.
   - Le témoin créé porte la génération courante du lien : un renouvellement le coupe comme les autres (choix 10).
   - Même page introuvable pour tout refus.
   - Route sans session et hors CSRF, comme `/entrer`.
6. **Une requête arrive à sa copie : l'intergiciel `OuvrirLaCopie`**, global, avant le groupe `web` (donc avant `StartSession` : la session se lit déjà dans la bonne base).
   1. Il lit le témoin. Sans témoin, seules répondent `/d`, `/entrer`, `/entrer-par-le-compte`, `/interne/messages`, `/interne/etat`, les feuilles de style et les scripts du site, et la page « démonstration terminée ». Ces routes sont sans `StartSession` et lisent seulement le registre. Tout le reste répond la page introuvable française.
   2. Il cherche l'empreinte du témoin dans le registre.
   3. Il vérifie : témoin non retiré et non échu ; copie `prete` (ou `en_lecture_seule`, point 16) et non échue ; empreinte du schéma égale à celle du code (E.2) ; marque `demonstration` de la base (A.5).
   4. Il règle tout ce qui lit ou écrit pour une copie, puis remet à zéro les instances déjà résolues :
      - la connexion `pgsql` sur la base de la copie, avec le rôle `demo_app` (`config([...])`, `DB::purge('pgsql')`) ;
      - le cache par défaut, ses verrous et le limiteur de débit (`cache.stores.database.connection`, `lock_connection`), et la session (`session.connection`), sur la connexion de la copie ; puis `app()->forgetInstance('cache')`, `'cache.store'`, `RateLimiter`, `'session'`, `'session.store'` ;
      - la racine de **chaque disque local** de `config/filesystems.php` (`local`, `verification`, `pieces_fournisseur`, `pieces_de_charge`, `telechargements`, `public`, et les envois temporaires de Livewire) sous `storage/demonstration/copies/<référence>/` ; un test échoue si un disque local de la configuration n'est pas réglé.
      
      Ainsi les limites d'envoi, les limites d'essais et les verrous des travaux uniques (`ShouldBeUnique`, onze classes, dont les clés sont faites des identifiants des fiches, les mêmes dans toutes les copies) restent propres à chaque copie : un travail unique de la copie A ne fait jamais abandonner celui de la copie B.
   5. Sinon, une page claire en français, jamais une erreur technique : « Votre démonstration se prépare » (se relit seule), « Votre démonstration sera prête dès qu'une place se libère », « Mise à jour en cours, une minute » (se relit seule), « Nous mettons votre démonstration à l'heure » (point 13), « Cette démonstration est terminée ».
   6. Le nom de la base ne vient jamais de la requête : il vient du registre, et il est contrôlé par le motif `^demo_copie_[a-z0-9]{26}$` avant tout usage. Un nom hors du motif arrête la requête et paraît dans l'état publié (C.19).
   7. Fermé par défaut : sur le site de démonstration, `DB_DATABASE` vaut une base sentinelle vide, `demo_aucune_copie`, sans aucune table (contrôlé au démarrage, A.3, et protégé contre les migrations, A.9). Une requête qui échapperait à l'intergiciel ne lit rien.
   8. Une connexion ne passe jamais d'une copie à l'autre : chaque requête PHP-FPM ouvre ses connexions ; Octane est refusé au démarrage (A.3).
   9. Les requêtes de Livewire et de Filament passent par le même chemin.
   10. Un code qui écrirait par `storage_path('app…')` au lieu d'un disque échapperait au dossier de la copie : un test refuse tout nouvel usage de `storage_path('app` dans `app/` hors de `config/`.
   11. **Le cache `registre`** : un magasin de cache nommé, sur la connexion `registre`, sert seulement à ce qui passe avant toute copie : les limites de `/entrer`, `/entrer-par-le-compte` et `/interne/messages`, le travailleur (`illuminate:queue:restart`), le planificateur (`withoutOverlapping`, `onOneServer`) et les commandes `demonstration:*`. Sans lui, ces chemins liraient la base sentinelle, qui n'a pas de table `cache` : erreur 500 au lieu d'une limite.
7. **La réception des messages : `POST /interne/messages`.**
   - Le format : un JSON canonique (clés triées, aucun nombre à virgule), signé octet par octet, signature Ed25519 détachée en base64url (`sodium_crypto_sign_detached`).
   - Les champs, liste fermée : `v` (version du protocole), `kid` (clé), `aud` (`messages` ; `entree` pour le seul billet, refusé ici), `quoi` (`creer`, `renouveler_le_lien`, `recommencer`, `supprimer`, `etat`), `reference`, `type` (pour `creer`), `validee` (pour `creer`), `empreinte_du_lien` et `generation` (pour `creer` et `renouveler_le_lien`), `fin`, `emis_le` (UTC), `nonce` (128 bits), `destinataire` (le nom du site de démonstration). Un champ de plus est refusé : c'est la preuve que rien de la société ne passe (Q4).
   - Refusés, chacun avec sa cause (réponse `422`) : une signature fausse, une clé inconnue, une audience autre que `messages` (le billet d'entrée compris), un message émis il y a plus de 5 minutes ou plus de 30 secondes dans le futur, un nonce déjà vu, un autre destinataire, une action inconnue, une version du protocole ni courante ni précédente (choix 20), une référence hors de `^[0-9a-f]{32}$`, une fin plus de 30 jours et 1 heure après la réception, une génération inférieure ou égale à celle que le registre connaît (pour `renouveler_le_lien`). Une signature juste ne suffit pas : ces bornes sont une seconde barrière si le site réel avait un défaut ou si sa clé était volée.
   - Idempotence : `creer` d'une référence connue rend l'état de la copie, sans en créer une seconde ; `supprimer` d'une copie supprimée répond « fait » ; `etat` ne change rien et répond l'état.
   - `renouveler_le_lien` : nouvelle empreinte, génération reçue ; l'ancienne empreinte et **tous** les témoins de la copie sont retirés, ceux du bouton compris (choix 10) ; réponse `200` avec la génération, qui fait l'accusé du site réel (B.6).
   - `recommencer` : la copie passe `a_refaire`, puis archive (C.18), `DROP DATABASE … WITH (FORCE)` et nouvelle copie du modèle du jour, même référence, même lien, même fin (choix 11), message « Votre démonstration a été remise à zéro le … » ; une visite ouverte pendant ce temps voit « Votre démonstration se prépare », jamais une erreur.
   - `supprimer` : comme la suppression à 30 jours (point 14).
   - La route n'a pas de témoin ni de session ; à l'étape 15, le pare-feu ne l'ouvre qu'au site réel.
   - Les clés : une paire Ed25519 propre aux messages, distincte de la caisse et du journal d'audit ; la clé privée seulement sur le site réel (`DEMONSTRATION_MESSAGES_CLE_PRIVEE`, `DEMONSTRATION_MESSAGES_CLE_ID`), les clés publiques seulement sur le site de démonstration (`DEMONSTRATION_MESSAGES_CLES_PUBLIQUES`, une liste par `kid`, pour tourner la clé sans coupure) ; jamais dans le dépôt ; tirées par `demonstration:generer-cle`, comme `caisse:generer-cle`.
   - `ext-sodium` se déclare dans `composer.json` (règle « Outillage » : extension utile à la sécurité ; justifiée dans le compte rendu et le README).
8. **« Choisissez qui vous êtes » et le bandeau « Vous êtes : ».**
   - **La liste** : une table `comptes_du_bandeau`, dans les migrations du site de démonstration (`database/migrations-demonstration/`, jamais dans la base réelle), écrite par le semis du modèle : compte, libellé du rôle, groupe (« La société », un magasin, « Le comptable » ; dans la copie « Cabinet » : « Le cabinet »), ordre (choix 16). Seuls les comptes de la société mise en avant y entrent (précision du 16/11 vers 1 h 30).
   - **Un compte créé dans la copie** s'y ajoute, à la fin de son groupe, par un écouteur de `CompteCree` (événement levé par `CreerMembreAction`, `CreerAdministrateurAction`, la création des comptes de l'expert-comptable déclaré et celle des collaborateurs d'un cabinet), chargé seulement en mode démonstration. Un compte retiré n'y est plus ; un compte suspendu y reste, grisé, et son choix reçoit le refus de la connexion, en français.
   - **La page `/choisir`** : les comptes groupés, le propriétaire en premier avec « Commencer en tant que propriétaire » (choix 15), le message à montrer s'il y en a un (E.4), la date de fin, le repère, le mot de passe de démonstration (choix 29), et les phrases : « Ne saisissez pas de vraies données de vos clients : cette démonstration est supprimée le … » ; « Pour voir la franchise en base ou une déclaration trimestrielle, changez le régime de TVA dans les paramètres fiscaux de la société. » (choix 31 ; seulement dans une copie de société, jamais celle d'un cabinet). Aucun nom d'une autre société, aucun autre type d'activité (choix 30).
   - **Le bandeau**, en haut de chaque page des espaces de la copie (crochet d'écran de chaque fournisseur de panneau, chargé seulement en mode démonstration) : « Démonstration · données fictives » | le nom de la société de la copie | « Vous êtes : » et le menu | « Mot de passe de démonstration : … » | « E-mails qui seraient partis : N » | « Fin le 16/12/2026 » | repère | la version | « Quitter ».
   - **Le bandeau montre les magasins.** Le menu range les comptes par magasin (« Les Halles », « La République ») : un vendeur des Halles y voit donc que la société a d'autres magasins. C'est une exception voulue à la règle « aucun écran du panneau d'un magasin ne laisse deviner qu'il existe d'autres magasins », écrite dans CLAUDE.md (H.1, A3) : le bandeau n'est pas un écran de PILIQI mais l'outil de la démonstration, et une seule personne s'entraîne derrière tous les comptes de sa propre société fictive. Il ne nomme jamais une autre société.
   - **Le geste** : `POST /demonstration/changer-de-compte` avec l'identifiant interne du compte :
     1. route chargée seulement en mode démonstration ; la classe refuse hors de ce mode ;
     2. témoin valable de cette copie exigé ;
     3. compte présent dans la copie et dans `comptes_du_bandeau`, non retiré, de la société mise en avant ; un identifiant forgé, ou celui d'un compte d'une autre société de la même base (un client du cabinet, dans la copie « Cabinet »), est refusé ;
     4. déconnexion du compte précédent par le vrai chemin (`Auth::logout()`, donc l'événement `Logout` : JET 40 « fin de session » dans le magasin ouvert, comme `JournaliserFinDeSession`), session invalidée et régénérée, contexte de la société et du magasin effacés (`TenantContext`, `MagasinsOuvertsParLaSession`), puis connexion par le même chemin qu'après une connexion réussie (date de la connexion précédente, première connexion signalée au propriétaire fictif) ; le premier accès du nouveau compte à un magasin inscrit le JET 80 (`JournaliserOuvertureDeSession`). Ainsi le registre de la copie dit quel opérateur a fait chaque vente (BOFiP § 150) ;
     5. inscrit au journal d'audit de la société fictive comme une connexion, « depuis le bandeau de la démonstration ».
   - **La seule exception aux règles** : le changement de compte saute le mot de passe et le poste reconnu. Tout le reste est la règle du vrai logiciel : le propriétaire ne vend jamais, un vendeur ne voit que ses ventes, un observateur ne vend pas, l'expert-comptable déclaré ne voit que ce que la société lui accorde, un collaborateur ne voit que les clients qui lui sont confiés, les graphiques ne se montrent qu'aux rôles permis, et chaque geste qui redemande le mot de passe le redemande (choix 29). Au lot RLS, le contexte PostgreSQL se repose comme à une connexion.
   - **« Bonjour »** dit le rôle du compte choisi, suivi de « (démonstration) ».
   - **« Quitter »** retire le témoin de ce navigateur et le marque retiré au registre (« retiré le »), ferme la session, puis montre « Vous avez quitté la démonstration. Votre lien marche toujours jusqu'au … ». Une valeur du témoin copiée avant ne marche plus.
   - **Après une réfection de la copie** (« Recommencer à zéro », mise à jour, copie perdue), le « compte choisi en dernier » de tous ses témoins est effacé : la visite suivante ouvre « Choisissez qui vous êtes ». L'identifiant d'un compte de l'ancienne base ne désigne jamais une autre personne de la nouvelle.
   - **Écrans** : lisible en clair et en sombre (contraste AA), menu annoncé aux lecteurs d'écran, navigable au clavier, tenant sur un téléphone (le menu défile dans son cadre).
9. **« E-mails qui seraient partis ».**
   - Un transport `demonstration` (`Mail::extend('demonstration', …)`, comme `mailtrap`) écrit chaque message dans une table de la copie, `courriels_de_la_demonstration` (dans `database/migrations-demonstration/`, jamais dans la base réelle) : société d'où il part, expéditeur, destinataires, objet, HTML, texte, pièces jointes (nom, type, taille, contenu ; au-delà de 10 Mo, gardée sans contenu, avec une phrase), envoyé le, compte d'où il part.
   - Toutes les règles d'envoi du réel décident si le message « serait parti » : 60 par heure et par membre, 200 par heure et par magasin, une relance par jour et par facture, « ne part que si la personne a encore accès et le droit d'envoyer », relances aux particuliers seulement si le magasin le coche.
   - La page `/demonstration/courriels`, ouverte depuis le bandeau à tout compte de la copie : la liste (envoyé le, par, à, objet), 50 par page, **seulement les e-mails de la société mise en avant** (dans la copie « Cabinet », ceux du cabinet), choix 5 et 30.
   - La fiche montre le HTML dans un cadre `<iframe srcdoc sandbox="allow-popups allow-popups-to-escape-sandbox">`, jamais `allow-scripts` ni `allow-same-origin`, avec un `<base target="_blank">` ajouté au HTML du message : un clic sur un lien s'ouvre dans un nouvel onglet (les pages de PILIQI refusent d'être encadrées : `frame-ancestors 'none'`). Les pièces se téléchargent en pièce jointe, jamais affichées. Un test vérifie les attributs.
   - Les liens du message (choix 27) mènent au site de démonstration et marchent dans la copie pour qui a son témoin.
   - `app:verifier-isolation` connaît cette table et `comptes_du_bandeau` comme des tables de la démonstration (liste nommée et testée), pas comme un oubli. La lecture de toute la société est l'exception écrite au choix 5.
   - La table disparaît avec la copie. Elle n'entre pas dans l'archive (C.18) : ce ne sont pas des données de caisse.
10. **Le marquage.**
    - `Emetteur::duMagasin()` (brouillons, devis envoyés, clôtures, reçus, état des charges) prend `estDemonstration` du mode du site : vrai sur le site de démonstration, faux au réel. Un document émis fige ce marqueur dans son émetteur signé ; ensuite, ses PDF (filigrane, bandeau) relisent le marqueur figé (`Emetteur::duContenuSigne()`), jamais le mode du site au moment de l'impression (« le PDF reproduit le document tel qu'émis »).
    - L'en-tête de chaque enregistrement du registre suit la même règle (choix 18) : la clé `demonstration`, à vrai, seulement dans une copie ; absente au réel, comme aujourd'hui (`RegistreFiscal.php:675`, `Emetteur.php:283`), donc les fixtures du contenu signé réel ne changent pas.
    - Le SIREN fictif de la société modèle s'y écrit normalement.
    - `Emetteur::filigrane()` (« DÉMONSTRATION — SANS VALEUR ») et `bandeauDeDemonstration()` (« TICKET DE DÉMONSTRATION — NE PAS REMETTRE À UN CLIENT »), le filigrane et le bandeau rouge des PDF (`documents/pdf/en-tete.blade.php`, `styles.blade.php`) : gardés, sur `$this->estDemonstration`. Les PDF des clôtures, des reçus, des devis, des factures, des tickets, des avoirs et de l'état des charges les portent.
    - L'état des charges en Excel, le récapitulatif CSV et le ZIP de « Télécharger les documents » portent « Démonstration — sans valeur » (titre de la feuille, première ligne, nom du fichier).
    - Les numéros portent les codes fictifs des magasins du modèle.
    - Le vérificateur d'intégrité du site réel (`VerifierIntegriteAction`, JET 90) signale un défaut pour tout enregistrement ou émetteur figé marqué `demonstration`, et pour tout JET 100 ou 105.
11. **Les fournisseurs factices** (A.6), et dans la copie : « Connecter la plateforme agréée » mène au faux parcours ; aucune clé SUPER PDP n'existe sur le serveur de démonstration ; une transmission va à la fausse plateforme ; une déclaration de l'e-reporting ne part jamais vers l'extérieur ; l'aperçu de la clôture montre ce qui serait déclaré. Le faux répertoire Sirene ne connaît que les numéros fictifs : pour créer un magasin dans sa copie, le visiteur choisit un établissement que propose la liste (les établissements fictifs de la société modèle, dont deux encore libres) ; un SIRET tapé à la main hors de la plage réservée répond « Établissement inconnu au répertoire Sirene ». Le filigrane reste sur chaque page et dans le nom de chaque fichier.
12. **Les clés propres.**
    - Caisse : deux dossiers épinglés, `resources/caisse-securisee/cles` (réel) et `resources/caisse-securisee/cles-demonstration` (démonstration). `config/caisse_securisee.php` lit l'un ou l'autre selon `env('PILIQI_SITE')`, avec la même liste fermée de valeurs que `SiteDePiliqi` (ce fichier se charge avant `config/piliqi.php` et ne peut pas lire `config('piliqi.site')`), jamais les deux. Un document signé par la démonstration ne se vérifie jamais comme un document réel.
    - Sur le poste, chaque site épingle sa clé de développement sans la versionner, comme aujourd'hui (le dossier `resources/caisse-securisee/cles` est vide dans le dépôt) : `php artisan --env=demonstration caisse:generer-cle` écrit dans `cles-demonstration` (I.10).
    - Une clé de démonstration retirée du service reste épinglée tant qu'une copie ou une archive signée par elle existe (six ans, C.18).
    - Journal d'audit : `JOURNAL_AUDIT_CLE` propre au site de démonstration. Le dépôt épingle l'empreinte SHA-256 de chaque clé HMAC de démonstration (`resources/journal-audit/cles-demonstration.txt`), jamais la clé : le site de démonstration refuse toute clé, courante ou précédente, dont l'empreinte n'y est pas ; le site réel refuse toute clé dont l'empreinte y est. Aucune commande ne tire aujourd'hui la clé du journal d'audit (le README la fait tirer par `php -r`) : le lot ajoute `journal-audit:epingler-la-cle`, qui écrit l'empreinte de la clé courante dans `resources/journal-audit/cles-demonstration.txt` (en mode démonstration) ; sur le poste, ce fichier n'est pas versionné, comme les clés de caisse de développement ; seules les empreintes des clés de démonstration de production entrent au dépôt.
    - `APP_KEY` différente sur les deux sites : un témoin ou une adresse signée d'un site ne vaut jamais sur l'autre.
    - `caisse:verifier` d'une copie passe avec la clé publique de démonstration et échoue avec celle du réel.
    - Le serveur de démonstration écrit au registre fiscal de chaque copie : il est à l'heure NTP, contrôlé par `caisse:diagnostiquer`, comme le réel.
13. **Les files, le planificateur et les tâches de chaque copie.**
    - **Deux familles de files, dans le registre** (`jobs` de la connexion `registre`, `after_commit = true`) :
      - `demonstration-admin` : `CreerUneCopie`, `RecommencerUneCopie`, `ArchiverPuisSupprimerUneCopie`, `MigrerUneCopie`. Un travailleur à lui, connecté par `demo_admin`. Ainsi les travaux lourds des visiteurs ne retardent jamais la création d'une copie ;
      - `default` et `telechargements` (jusqu'à 30 minutes), comme le réel, pour les travaux des copies, traités par le travailleur de `demo_app`, en `queue:listen` (un processus par travail).
      - `Queue::createPayloadUsing` ajoute la référence de la copie courante à chaque travail d'une copie.
    - **Le travail retrouve sa copie** sur l'événement `JobProcessing`, avant de relire ses modèles : il règle la connexion, le cache, les verrous et les disques comme l'intergiciel (point 6). Copie supprimée, échue ou en migration : travail abandonné sans nouvelle tentative, rien n'est écrit ailleurs.
    - **Chaque tâche de `bootstrap/app.php` est rangée** dans une liste du code (`TachesDesSites`), et un test échoue si une tâche n'y est pas :
      - pour chaque copie : `ClotureAutomatiqueCaisse`, `MarquerFacturesEnRetard`, `ExpirerDevis`, `RelancerFacturesImpayees` (capturées), `ExpirerInvitationsDesCabinets`, `PurgerInvitationsDesCabinets`, `RattacherLesExpertsComptables`, `EffacerTelechargementsDeDocuments`, `RelancerTransmissionsPdp` (fausse plateforme), `PurgerActionsTraitees` ;
      - pour chaque copie visitée depuis la dernière passe, une fois par nuit : `VerifierIntegriteFiscale` en mode incrémental (`caisse:verifier` de la copie, clé publique de démonstration). Les défauts de la nuit se réunissent en une seule ligne de l'état publié (C.19), donc une seule alerte à l'éditeur, sans rien bloquer. C'est aussi la vérification qui précède l'archive (C.18) ;
      - pendant la construction d'un modèle seulement : `VerifierLIsolation`, `VerifierJournalAudit` ;
      - pour le site de démonstration seul : `demonstration:construire-les-modeles` (01 h 10), `demonstration:nettoyer` (après la construction), `demonstration:supprimer-les-copies-echues` (chaque heure), `demonstration:taches-des-copies` (chaque heure), `demonstration:mesurer-les-copies` (chaque heure, point 16), `demonstration:purger-les-messages-recus` (chaque jour), `demonstration:purger-les-archives` (chaque jour, C.18), `demonstration:purger-les-travaux-echoues` (chaque jour, 30 jours), `SurveillerLaFileDAttente` (sur les files du registre) ;
      - jamais sur le site de démonstration : `PurgerTraces` (aucune trace n'y est gardée, C.17), `PurgerJournalAudit`, `PurgerVerifications`, `EffacerPiecesVerification`, `PurgerDemandesInscription`, `CloreInscriptionsSansCode`, `ReverifierSirene` (chaque heure et le 1er du mois), `SuivreEnvoisRecommandes` ;
      - sur le site réel seul : toutes les tâches d'aujourd'hui, plus `demonstration:relancer-les-messages` (toutes les 5 minutes), `demonstration:marquer-les-fins` (chaque heure) et `demonstration:lire-l-etat` (chaque heure) ;
      - `FermerDemonstrationsInactives` disparaît (partie D).
    - **Quand passent les tâches d'une copie** (`demonstration:taches-d-une-copie <référence>`, dans un processus à part, 4 à la fois au plus, 10 minutes au plus chacune, qui règle la connexion puis exécute les tâches « pour chaque copie » dans l'ordre) :
      - chaque heure, pour les copies visitées depuis moins de 48 heures ;
      - à l'entrée (lien, bouton, ou première requête après 60 minutes sans passe) : si la dernière passe a plus d'une heure, un rattrapage part et la page « Nous mettons votre démonstration à l'heure » attend sa fin, puis ouvre la copie ;
      - une fois par nuit, pour toutes les copies.
    - **Une copie vieille de 20 jours** rattrape ses journées dans l'ordre, un ticket Z par jour même à zéro : c'est la règle de la caisse, rien à écrire de plus. L'horloge est celle du serveur, jamais avancée ni reculée ; le modèle finit à l'instant de sa construction, le temps ne fait qu'avancer, et la règle « une opération horodatée avant le dernier verrou de son magasin est refusée » tient.
    - `SemisEnCours` vit dans le cache de la base courante : il n'est jamais posé dans une copie, et les tâches des copies ne passent pas par le planificateur mais par la commande ci-dessus.
    - **À mesurer** : la durée d'une passe sur une copie neuve, et sur une copie de 20 jours jamais visitée.
14. **La suppression à 30 jours** : `demonstration:supprimer-les-copies-echues`, chaque heure. Pour chaque copie dont la fin est passée : état `en_archivage`, archive (C.18), état `en_suppression`, `DROP DATABASE … WITH (FORCE)` (par `demo_admin`), dossier de fichiers effacé, lignes de `jobs` et `failed_jobs` de la copie effacées, liens et témoins retirés, état `supprimee`. La fin est un instant UTC : la copie répond « terminée » dès sa fin, même avant le passage de la tâche, à travers un changement d'heure aussi. Une archive qui échoue arrête la suppression de cette copie (jamais de `DROP` sans archive vérifiée), et l'échec paraît dans l'état publié. Le message `supprimer` du site réel n'est qu'une seconde sûreté.
15. **La page introuvable.** Sans témoin valable, toute adresse du site de démonstration répond la page introuvable française, la même qu'au site réel, sans dire qu'une démonstration existe.
16. **Les quotas d'une copie.** `demonstration:mesurer-les-copies`, chaque heure, mesure `pg_database_size` de chaque copie et la taille de tout son dossier (pièces déposées, ZIP et PDF de « Télécharger les documents », envois temporaires).
    - Au-delà de 3 fois la taille de son modèle (base et dossier, seuils réglables), la copie passe `en_lecture_seule` : chaque écriture est refusée avec « Votre démonstration a atteint sa place. « Recommencer à zéro », dans votre compte, la libère. » ; la consultation reste.
    - Une pièce déposée qui ferait dépasser la place est refusée avec le même message.
    - La place libre du disque du serveur paraît dans l'état publié (C.19) ; sous 20 %, alerte à l'éditeur, et plus aucune copie neuve ne se crée (« en attente d'une place »).
    - Les limites d'e-mails et d'essais du réel valent aussi.
17. **Ce que les visiteurs tapent.** Rien n'empêche un visiteur de taper de vraies données dans sa copie.
    - La base meurt avec la copie (`DROP DATABASE`, aucune sauvegarde). Seule l'archive de la caisse (C.18) reste six ans, comme la loi le demande ; `/choisir` le dit : « Ne saisissez pas de vraies données de vos clients : cette démonstration est supprimée le … ; seule la caisse en est archivée, comme la loi le demande. »
    - En mode démonstration, ni l'adresse IP ni le navigateur ne s'enregistrent, ni au journal d'audit, ni au journal des événements, ni dans les sessions (colonnes laissées vides).
    - Les journaux du site de démonstration (`storage/logs`) sont quotidiens et gardés 14 jours au plus : une `QueryException` y porte les valeurs liées.
    - Les lignes de `jobs` et de `failed_jobs` d'une copie s'effacent avec elle (point 14), et `failed_jobs` se purge à 30 jours.
    - À noter pour l'étape 9 (RGPD).
18. **Le mode d'entraînement de la caisse et l'archive avant suppression** (partie « Ce que dit la loi »).
    - **JET 100** : inscrit dans chaque magasin de la société modèle à sa construction (C.2), donc présent dans chaque copie, avec l'opérateur du semis. Un magasin créé par le visiteur dans sa copie l'inscrit à sa première ouverture.
    - **JET 105** : inscrit dans chaque magasin juste avant l'archive.
    - **L'archive** (`ArchiverUneCopie`, file `demonstration-admin`) : journées en attente clôturées ; JET 105 ; vérification du registre (`caisse:verifier` complet de la copie, code 0) ; export du registre et du journal des événements (`ExporterJournalDesEvenementsAction`) ; tickets Z en PDF ; totaux perpétuels de chaque magasin ; un sommaire (référence, repère, type, dates, nombre de documents par magasin, empreinte de chaque fichier) signé avec la clé de caisse de démonstration ; le tout dans un ZIP sous `storage/demonstration/archives/<référence>/<horodatage UTC>.zip`, avec sa ligne dans `archives` (empreinte, à effacer le). Une archive est relue (empreinte et signature du sommaire) avant le `DROP DATABASE`.
    - **Durée** : six ans (BOFiP § 155), puis `demonstration:purger-les-archives` l'efface et inscrit la purge au journal du site.
    - **Qui la lit** : personne sur le site de démonstration. L'éditeur la lira à l'étape 13 par un geste audité (H.5, A7), sur une demande de l'administration fiscale. Le propriétaire ne la télécharge pas : elle ne contient rien de sa société.
    - **Étape 3** : les clôtures mensuelle et de l'exercice et le format de l'archive finale d'un magasin fermé pour cessation remplacent ce montage, avec la même règle (H.5, A2).
    - **Sur le poste**, `demonstration:tout-effacer` efface aussi les archives : le poste n'a pas de données à garder. En production, la commande refuse (E.4).
19. **L'état publié du site de démonstration** : `GET /interne/etat`, signé avec une clé Ed25519 propre au site de démonstration (`DEMONSTRATION_ETAT_CLE_PRIVEE` sur le site de démonstration, sa clé publique `DEMONSTRATION_ETAT_CLE_PUBLIQUE` sur le site réel).
    - Contenu : pour chaque type, le modèle en service et la date de sa construction ; les échecs de la nuit (construction, isolation, `caisse:verifier` d'un modèle ou d'une copie, archive, nettoyage refusé, nom de base hors du motif) ; le nombre de copies par état ; les copies en échec de migration ; la place libre du disque ; la version. Aucune donnée d'une copie, aucune référence.
    - Le site réel le lit chaque heure (`demonstration:lire-l-etat`), contrôle la signature, et lève une `AlerteEditeur` pour chaque problème nouveau. Un état illisible, mal signé ou vieux de plus de 2 heures est lui-même une alerte.
    - Ainsi le site de démonstration n'envoie rien vers l'extérieur (sa sortie reste fermée, `Http::preventStrayRequests()`), et « le site réel et le site de démonstration ne lisent jamais la base de l'autre » reste vrai : seul passe un état signé, dans ce sens.
### D. Le retrait du magasin de démonstration intérieur (Q2)

Tout se fait au lot DEMO-COPIE-1 (son commit 1), sauf ce qui est dit « au lot 2 » ou « au lot 3 ». Les numéros entre crochets sont ceux du relevé 2 (code) et du relevé 3 (tests). Les numéros de ligne sont ceux de la tête `a9bb1a78` : relire chaque fichier avant d'écrire. Trois fichiers sont modifiés en ce moment par la course DATEOP : `DocumentsDeLaPeriode`, `PreparerLeTelechargementDesDocumentsJob` et `ActionAuditee`.

Les mots :
- **Supprimer** : le code n'a plus de raison d'être.
- **Simplifier** : on retire seulement la condition ou le filtre sur la démonstration.
- **Réécrire** : le code reste, autrement, souvent pour le mode du site.
- **Commentaire** : seulement le texte d'un commentaire ou d'un docblock.

#### D.1 Le schéma de la base

1. [1] `database/migrations/0001_01_01_000000_create_magasins_table.php:24-29` : colonne `est_demonstration` et son commentaire. **Supprimer**.
2. [2] `…create_magasins_table.php:30-37` : `siret` nullable « pour le magasin de démonstration ». **Réécrire** : `NOT NULL` (choix 25), après la lecture de chaque chemin qui crée un magasin (`CreerEtablissementAction`, semis, `MagasinFactory`, tests qui insèrent un magasin à la main).
3. [3] `…create_magasins_table.php:38-42` : commentaire de `etablissement_verifie_le`. **Commentaire**.
4. [4] `…create_magasins_table.php:145-147` : index unique partiel `magasins_une_demonstration_par_societe`. **Supprimer**.
5. [5] `…create_magasins_table.php:174-177` et `:191` : déclencheur `magasins_est_demonstration_figee` et son retrait dans `down()`. **Supprimer**.
6. [6] `…create_magasins_table.php:179-181` : « un cabinet n'a aucun magasin, démonstration comprise ». **Commentaire** ; la garde reste.
7. [7] `database/migrations/0001_01_01_000001_create_users_table.php:57-60` : `statut_change_par` « nul pour un compte d'entraînement retiré ». **Réécrire** le commentaire. `User.php:622` accepte encore un auteur nul : chercher par `git grep` chaque chemin qui retire ou suspend sans auteur (retrait des comptes de l'expert-comptable au rattachement automatique, par exemple). S'il en reste un, la colonne reste nullable et le commentaire nomme ce cas. Sinon, `NOT NULL`, avec un test de la garde.
8. [8] Commentaires : `2026_10_02_100002_create_identifiants_table.php:21-22`, `2026_10_02_100026_create_magasins_cabinet_table.php:17`, `2026_10_03_100003_create_factures_table.php:14`, `2026_10_04_100001_create_paiements_table.php:22`. **Commentaire** : « le propriétaire ne vend jamais ».

#### D.2 Les gardes et déclencheurs PostgreSQL

9. [9] `app/Domain/Facturation/Support/GardesDeLaVente.php:22-25`, `:57`, `:160-179` : exception du propriétaire dans la démonstration de sa société, et `MESSAGE`. **Réécrire** : refus sans exception pour `role_societe IN (proprietaire, collaborateur, expert_comptable)` ; message « Le propriétaire d'une société, l'expert-comptable de la société et les comptes d'un cabinet ne vendent jamais : aucune vente ni aucun encaissement ne s'inscrit à leur nom. » (le message garde l'expert-comptable et les comptes d'un cabinet, que la garde refuse aussi) ; le docblock de `auteurQuiNeVendPasDansCeMagasin()` perd « sauf dans le magasin de démonstration ».
10. [10] `app/Domain/Tenancy/Support/GardesDesIdentifiants.php:18-21`, `:30`, `:38-40`, `:55`, `:58` : refus du compte non membre rattaché à la démonstration, et du rattachement tardif ; constante `DEMONSTRATION`. **Supprimer** ces deux règles, leurs messages et la constante. Garder « identifiant jamais effacé » et « magasin écrit une seule fois ».
11. [11] `app/Domain/Tenancy/Support/GardesDesDroits.php:56-57`, `:170`, `:203` : « un compte d'entraînement ne change jamais de rôle de société » et `$compteDEntrainement`. **Supprimer**.
12. [12] `app/Domain/Cabinets/Support/GardesDesCabinets.php:58`, `:438` : `COALESCE((SELECT est_demonstration …), TRUE)`. **Réécrire** avec soin : ce `COALESCE` refusait aussi un magasin qui n'existe pas. La garde devient « le magasin existe et est de la société du lien » ; si la clé composée le tient déjà, le dire dans le commentaire et le prouver par un test ; ne jamais perdre ce refus.
13. [13] `app/Domain/Charges/Support/GardesDesCharges.php:17`, `:70`, `:99` : même forme pour le magasin d'une charge (`REFUS_MAGASIN`). **Réécrire** comme le point 12.
14. [14] `app/Domain/Tenancy/Support/GardesDesTypesDActivite.php:17` : « démonstration comprise ». **Commentaire**.
15. [15] `app/Domain/Tenancy/Isolation/ReglesDIsolation.php:274` : `magasins_est_demonstration_figee` dans la liste des déclencheurs attendus. **Supprimer** ce nom.
16. [16] Les témoins des gardes retirées (tests qui prouvaient leur refus) partent avec elles : D.16.
16 bis. Garde du régime de TVA ajoutée par TVA27 (« Place, isolation, vérifications ») : **Simplifier** : retirer la condition `magasins.siret IS NOT NULL`, devenue inutile (choix 25) ; supprimer le test « démonstration utilisable sans régime ni crédit » de TVA27, avec sa règle (décision du 15/11 vers 15 h 45, sans objet : la copie a déjà son régime). Dans `Magasin::refusDeVente()`, la branche de la démonstration part (point 25) ; le contrôle du régime reste.
17. [17] Après le lot : `php artisan migrate:fresh` et `FonctionsDeGardeTest` (les fonctions plpgsql citent la colonne par son nom et casseraient à la création).

#### D.3 Les modèles et les énumérations

18. [18] `app/Domain/Tenancy/Models/Magasin.php:103-108` : docblock du magasin de démonstration. **Supprimer**.
19. [19] `Magasin.php:150`, `:152` : `REFUS_DEMONSTRATION_TERMINEE`, `REFUS_DEMONSTRATION_SANS_ENVOI`. **Supprimer**.
20. [20] `Magasin.php:203` : cast `est_demonstration`. **Supprimer**.
21. [21] `Magasin.php:252-253` : « seul le magasin de démonstration n'a pas de SIRET ». **Réécrire** : un magasin a toujours un SIRET.
22. [22] `Magasin.php:260-261` : territoire obligatoire « comme le magasin de démonstration ». **Simplifier**.
23. [23] `Magasin.php:270-275` : `est_demonstration` figé, la démonstration jamais établissement principal. **Supprimer**.
24. [24] `Magasin.php:504-516` : `estUneDemonstrationTerminee()`. **Supprimer**, avec ses appels (`FermerLaDemonstrationAction`, `PorteeDesDroits:158`, `MembreResource:394`, `AffectationPolicy:48`).
25. [25] `Magasin.php:585-612` : branche `$this->est_demonstration` de `peutVendre()` et `refusDeVente()`. **Simplifier**.
26. [26] `Magasin.php:650-668` : `refusDEcrireAuxClients()` pour la démonstration. **Réécrire** : aucun refus propre au magasin ; sur le site de démonstration, l'e-mail va au transport `demonstration` (C.9) et rien ne part à la plateforme.
27. [27] `Magasin.php:686-701` : `verifierQuIlPeutImprimer()`, la démonstration qui imprime avant la validation. **Simplifier** : seuls les magasins d'une société validée impriment ; la société modèle est validée.
28. [28] `Magasin.php:745-760` : `mentionsLegalesManquantes()` vide pour la démonstration. **Supprimer** la branche.
29. [29] `app/Domain/Tenancy/Models/Societe.php:286-315` : `magasins()`, `magasinsReels()` (27 appels), `demonstration()`. **Supprimer** `demonstration()` ; **réécrire** `magasinsReels()` en `magasins()` et ses 27 appels (choix 23).
30. [30] `Societe.php:365`, `:413`, `:549`, `:707` : commentaires (verrou de l'exercice). **Commentaire**.
31. [31] `app/Models/User.php:341-348` : `estUnCompteDEntrainement()`. **Supprimer**, avec ses appels (`ChangerLeRoleDeSocieteAction:152`, `PorteeDesDroits:393`).
32. [32] `User.php:498-510` : « (démonstration) » après le rôle. **Supprimer** la branche (le bandeau du site de démonstration le dit autrement, C.8).
33. [33] `User.php:1000-1006` : `autorisationsEnregistreesDans()`, toutes les autorisations au propriétaire dans la démonstration. **Supprimer** la branche : toujours `duProprietaire()`.
34. [34] `User.php:1085-1110` : `peutOuvrir()`, la démonstration d'une société validée (T115). **Supprimer** la condition.
35. [35] `User.php:1117-1156` : `magasinsAccessibles()`, filtre et tri `est_demonstration`. **Simplifier**.
36. [36] `User.php:53-54`, `:612`, `:849`, `:971-973` : commentaires. **Commentaire**.
37. [37] `app/Domain/Tenancy/Enums/MotifFermeture.php:10`, `:18`, `:28` : cas `FinDemonstration`. **Supprimer** (le semis est refait : aucune période ne le porte).
38. [38] `RoleSociete.php:13-15`, `TypeDActivite.php:26`, `:101`, `app/Domain/Tenancy/Events/MagasinOuvert.php:15`, `app/Domain/Tenancy/Services/PeriodesDActivite.php:16` : commentaires. **Commentaire**.

#### D.4 L'ouverture, la fermeture et les démonstrations inactives

39. [39] `app/Domain/Tenancy/Actions/OuvrirLaDemonstrationAction.php` (173 lignes). **Supprimer**. `territoireDuSiege()` n'a plus d'usage (Q4).
40. [40] `app/Domain/Tenancy/Actions/FermerLaDemonstrationAction.php` (165 lignes). **Supprimer**.
41. [41] `app/Console/Commands/FermerDemonstrationsInactives.php`. **Supprimer**. Les 30 jours renaissent autrement (C.14).
41 bis. `app/Domain/Tenancy/Services/CatalogueDeDemonstration.php` (84 lignes, utilisé seulement par `OuvrirLaDemonstrationAction`). **Supprimer**.
42. [42] `bootstrap/app.php:9`, `:119-120` : planification à 04:45. **Supprimer** ; les tâches nouvelles sont en C.13 et B.8.
43. [43] `app/Domain/Tenancy/Actions/ValiderLaSocieteAction.php:55-59`, `:70`, `:118-142` : fermeture de la démonstration, effacement de ses notifications, phrase « Le magasin de démonstration est terminé… » de l'avis. **Supprimer**. La validation ne touche pas la copie.
44. [44] `app/Domain/Tenancy/Actions/CloreLInscriptionAction.php:75-80`, `:97`, `:221`, `:331-356` : fermeture de la démonstration et retrait des comptes d'entraînement. **Supprimer** ; **ajouter** le message `supprimer` pour la copie de la société (B.9), au lot 2.
45. [45] `SaisirCodeDeConfirmationAction.php:92`, `app/Console/Commands/Editeur/CloreLInscription.php:20`, `FermerMagasinAction.php:47` : commentaires. **Commentaire** ; `SaisirCodeDeConfirmationAction` reçoit au lot 2 la suppression de la copie quand une autre personne devient propriétaire (B.9).

#### D.5 Les comptes d'entraînement

46. [46] `app/Domain/Tenancy/Actions/CreerMembreAction.php:34-35`. **Commentaire** supprimé (aucun code propre).
47. [47] `ChangerLeRoleDeSocieteAction.php:39-41`, `:53`, `:152-153` : `MESSAGE_ENTRAINEMENT`. **Supprimer**.
48. [48] `ChangerLeRoleDeSocieteAction.php:186` : magasins réels proposés. **Simplifier**.
49. [49] `app/Domain/Tenancy/Services/PorteeDesDroits.php:43`, `:111-121` : aucun responsable nommé dans la démonstration. **Simplifier**.
50. [50] `PorteeDesDroits.php:152-158` : démonstration terminée. **Supprimer** la condition.
51. [51] `PorteeDesDroits.php:382-393` : changement de rôle d'un compte d'entraînement. **Supprimer** la condition.
52. [52] `PorteeDesDroits.php:538`, `:552`, `:612-620` : portée des administrateurs, accès redonné dans un magasin réel. **Simplifier**.
53. [53] `app/Policies/AffectationPolicy.php:43-48` : démonstration terminée. **Supprimer** la condition.
54. [54] Commentaires : `DroitsSurLesCharges.php:44`, `ListEquipeDuMagasin.php:20`, `ListMembres.php:145-146`, `MembrePolicy.php:140`, `ControlesDeLaBase.php:185`, `PerimetreDesGraphiques.php:34`. **Commentaire**.

#### D.6 Le propriétaire qui vendait dans la démonstration

55. [55] `app/Domain/Authorization/PermissionRegistry.php:541-565` : `duProprietaireEnDemonstration()`. **Supprimer**.
56. [56] `PermissionRegistry.php:23-25`, `:112`, `:140`, `:408-409`, `:528-529` : docblocks. **Commentaire** : « le propriétaire n'a jamais les autorisations de vente ».
57. [57] `app/Domain/Authorization/ControleDesDroits.php:37-38`, `:83`. **Commentaire**.
58. [58] `app/Domain/Facturation/Actions/EmettreFactureAction.php:328-349` (`reprendreLeBrouillonDuProprietaire()`). Ce code ne refuse rien : il donne à qui émet le brouillon créé par un administrateur devenu propriétaire depuis. **Simplifier** : retirer `! $facture->magasin->est_demonstration` ; un brouillon au nom du propriétaire devient toujours, à l'émission, la vente de qui l'émet ; la base continue de refuser une émission au nom du propriétaire (`GardesDeLaVente`). Le docblock perd « comme, dans le magasin de démonstration, où il vend, celui du propriétaire ».
59. [59] Commentaires : `ClotureCaissePersonnellePolicy.php:19-24`, `:120` ; `FacturePolicy.php:67` ; `ClotureCaissePersonnelle.php:21` ; `RegulariserUneExonerationNonProuveeAction.php:39` ; `ViewFacture.php:92`, `:202` ; `DevisResource.php:339`. **Commentaire**.
60. [60] Textes à l'écran : `AccepterLaTransmission.php:72`, `FicheDuMembre.php:264`, `AccepterLaTransmissionDeLaProprieteAction.php:193`. **Réécrire** : « sauf celui de vendre ».
61. [61] `ReglesDIsolation.php:60` : `REGLE_VENTES`. **Réécrire** : « jamais le propriétaire ».

#### D.7 La caisse sécurisée, le journal des événements et le journal d'audit

62. [62] `app/Domain/CaisseSecurisee/Enums/CodeEvenement.php:53-56`, `:78-79`, `:100-101` : JET 100 et JET 105. **Gardés** (choix 19), **docblock réécrit** : « seulement dans une copie du site de démonstration : entrée en mode d'entraînement à la construction du modèle, sortie avant l'archive de la copie ; jamais dans une base réelle, où le vérificateur les signale ». Au lot 1, plus aucun code du site réel ne les écrit ; au lot 2, la construction et l'archive les écrivent (C.2, C.18).
63. [63] `CodeEvenement.php:30` : commentaire du JET 270. **Commentaire**.
64. [64] `app/Domain/CaisseSecurisee/Services/RegistreFiscal.php:47-48`, `:673-675` : SIREN nul et marqueur `demonstration: true` de la démonstration intérieure. **Au lot 1 : simplifier** : le SIREN s'écrit toujours, aucune clé `demonstration` (la colonne `est_demonstration` disparaît au même commit, et `Model::preventAccessingMissingAttributes`, actif hors production, `AppServiceProvider.php:377`, lèverait `MissingAttributeException` à chaque écriture au registre si une branche la lisait encore). **Au lot 2 : ajouter** la clé `demonstration`, à vrai, seulement en mode démonstration (choix 18).
65. [65] `app/Domain/Tenancy/Services/JournalDesDroits.php:355-379` : `autourDuChangementDeStatut()`. **Supprimer**.
66. [66] `JournalDesDroits.php:340-345` : `inscrireLesDroitsImplicites()`, appelée aussi par `MiseEnActiviteDesMagasins:55`. **Commentaire**.
67. [67] `JournalDesDroits.php:45`, `:67-68`, `:403`, `:425` : commentaires et deux filtres. **Simplifier**.
68. [68] `app/Domain/Tenancy/Enums/ActionAuditee.php:53-54`, `:181-182`, `:328` : `DemonstrationOuverte`, `DemonstrationFermee`. **Supprimer** ; les cas nouveaux arrivent au lot 2 (B.12).
69. [69] `CloturerLesJourneesAction.php:29-32`, `:156` ; `CloturerCaisseAction.php:67`. **Commentaire** (`parLeSysteme()` reste : clôture automatique, fermeture d'un magasin, révocation, retrait d'un ticket Z).
69 bis. `app/Domain/CaisseSecurisee/ModuleFiscal.php:23` (`VERSION = '2.0'`). **Au lot 2 : `3.0`** (version majeure : contenu signé de l'en-tête du registre et de l'émetteur figé changé, mode d'entraînement retiré de l'instance réelle et déplacé sur le site de démonstration, clés de démonstration, archive d'une copie ; T32.5 classe en majeure tout changement du contenu signé, comme le lot AUD l'a fait en `bb135dba`). Tests qui la lisent : `RegistreFiscalTest`, `CommandesModuleFiscalTest`, `ProceduresTest` ; empreintes de `tests/Fixtures/empreinte-de-la-caisse` refaites, la raison dans le message du commit. Au lot 1, la version ne change pas encore : le lot 1 et le lot 2 sont relus séparément, mais la version 3.0 couvre les deux, avant toute mise en production. Le JET 250 (version majeure) s'inscrit comme pour toute version.

#### D.8 Les documents et les PDF

70. [70] `app/Domain/Facturation/Services/Emetteur.php:36-46`, `:82-95`, `:130-177` : `DENOMINATION_DE_DEMONSTRATION`, `deDemonstration()`, et la branche `if ($magasin->est_demonstration)` de `duMagasin()`. **Supprimer**, au lot 1.
71. [71] `Emetteur.php:82`, `:187-188`, `:234`, `:242-243`, `:283` : `estDemonstration` et marqueur figé. **Au lot 1** : `duMagasin()` donne toujours `estDemonstration = false` ; le reste ne change pas (la lecture du marqueur figé par `duContenuSigne()` reste). **Au lot 2** : `duMagasin()` prend `estDemonstration` du mode du site (C.10).
72. [72] `Emetteur.php:286-308` : `filigrane()`, `bandeauDeDemonstration()`. **Gardés tels quels**, sur `$this->estDemonstration` : pour un document émis, c'est le marqueur figé du contenu signé ; pour un brouillon, celui que donne `duMagasin()`. Seuls leurs docblocks disent « document du site de démonstration » au lieu de « magasin de démonstration ».
73. [73] `resources/views/documents/pdf/en-tete.blade.php:12-25`, `styles.blade.php:112`. Gardés, pilotés par le mode du site.
74. [74] `ClotureCaissePdfGenerator.php:21`, `RecuPaiementPdfGenerator.php:30`. **Commentaire**.
75. [75] `resources/views/achats/fiche-de-saisie.blade.php:28` : classe `bandeau-demonstration` réutilisée par la fiche de saisie. **Réécrire** : classe renommée `bandeau`, dans la vue et dans `FicheDeSaisiePdfGenerator:70`.

#### D.9 Les envois, les relances, la plateforme et l'e-reporting

76. [76] `app/Filament/Concerns/EnvoieLesDocuments.php:92`, `:111`, `:154` : boutons cachés dans la démonstration. **Simplifier** : visibles selon les vraies règles.
77. [77] `app/Filament/App/Resources/FactureResource.php:1167`, `:1190` : reçu de paiement. **Simplifier**.
78. [78] `DevisResource.php:307`. **Commentaire**.
79. [79] `EnvoyerDocumentAction.php:55`, `EnvoyerDocumentJob.php:81`. **Simplifier** ; sur le site de démonstration, le transport capte (C.9).
80. [80] `app/Console/Commands/RelancerFacturesImpayees.php:34`, `:71`. **Simplifier**.
81. [81] `app/Console/Commands/MarquerFacturesEnRetard.php:28`, `:87-93`, `:102`. **Supprimer** la condition.
82. [82] `SubmitFactureToPdpJob.php:94-96`, `DeclencherTransmissionPdpApresEmissionFacture.php:18`. **Simplifier** ; la fausse plateforme suffit (C.11).
83. [83] `app/Domain/Tenancy/Actions/CreerMandatPdpAction.php:28-33`. **Supprimer** la condition.
84. [84] `EReportingDesParticuliers.php:86`, `:99`, `:109`, `:139-145` : `MENTION_DEMONSTRATION` et deux sorties. **Supprimer**.
85. [85] `ApercuEReporting.php:19`, `:30` : propriété `demonstration`. **Supprimer** (choix 18) ; la fixture de l'empreinte de la caisse se refait (D.16, L4).
86. [86] `app/Filament/App/Resources/EReportingResource.php:87`, `:109-125`, `:201-202`. **Supprimer**.

#### D.10 Les filtres « seulement les magasins réels »

87. [87] Les 28 requêtes qui filtrent `est_demonstration = false` : **Simplifier** (retirer le filtre ; elles restent filtrées par société) :
    - `RelancerFacturesImpayees.php:71` ;
    - `ConfierDesClientsAction.php:67` ;
    - `LienCabinet.php:319` ;
    - `AccesDesCabinets.php:302` ;
    - `ExpertsComptablesDeclares.php:130` ;
    - `ChargesDeLaSociete.php:132` ;
    - `DroitsSurLesCharges.php:155` ;
    - `EcritureDUneCharge.php:152` ;
    - `CaissesDeLaSociete.php:79` ;
    - `FacturesAEncaisserDeLaSociete.php:48` ;
    - `PerimetreDesGraphiques.php:74`, `:148`, `:188` ;
    - `ChangerLeRoleDeSocieteAction.php:186` ;
    - `Societe.php:303` ;
    - `ChosesAFaireDeLaSociete.php:95`, `:373` ;
    - `CreationDesComptes.php:166` ;
    - `JournalDesDroits.php:425` ;
    - `PorteeDesDroits.php:538` ;
    - `ClientDuCabinetResource.php:103` ;
    - `PageDesCharges.php:1268` ;
    - `Filament/Societe/Pages/TableauDeBord.php:157`, `:257` ;
    - `MagasinResource.php:60` ;
    - `MembreResource.php:381` ;
    - `FicheDuMembre.php:448` ;
    - `User.php:1152`.
88. [88] Conditions en PHP : `DroitsSurLesCharges.php:115`, `:165` ; `PerimetreDesGraphiques.php:122`, `:210` ; `RegularisationDUneFacture.php:119` ; `MagasinDeLaSocietePolicy.php:79` ; `TableauDeBordDesClients.php:55` (colonne lue). **Simplifier**.
89. [89] Commentaires : `AccesDesCabinets.php:26`, `:291` ; `ExpertsComptablesDeclares.php:116`, `:143` ; `LienCabinet.php:303` ; `CaissesDeLaSociete.php:24` ; `FacturesAEncaisserDeLaSociete.php:19` ; `ChosesAFaireDeLaSociete.php:57` ; `PerimetreDesGraphiques.php:42` ; `TableauDeBord/Support/Perimetre.php:21` ; `Filament/App/Widgets/GraphiquesDuMagasin.php:19` ; `MagasinDeLaSocietePolicy.php:22-23`. **Commentaire**.

#### D.11 La disparition après la validation (T115) et les téléchargements

90. [90] `TelechargementDeDocuments.php:141-159`. **Supprimer** la branche.
91. [91] `PreparerLeTelechargementDesDocumentsJob.php:63-71`, `:94` (`MOTIF_DEMONSTRATION`), `:146`, `:178-199`, `:294-307`, `:327-346` (`neVisePlusQuUneDemonstrationDisparue()`). **Supprimer**.
92. [92] `DocumentsDeLaPeriode.php:54`, `:81`, `:154`, `:203-225`. **Simplifier**.
93. [93] `resources/views/filament/telecharger-les-documents.blade.php:132` : « — démonstration, documents sans valeur ». **Supprimer** ; sur le site de démonstration, le bandeau et le marquage du ZIP le disent (C.10).
94. [94] `PorteeDesAdministrateurs.php:26`, `:43`, `:69-70` : `MESSAGE_DEMONSTRATION_TERMINEE`. **Supprimer**.
95. [95] `MembreResource.php:282`, `:371-394` ; `FicheDuMembre.php:448`, `:473`. **Simplifier**.

#### D.12 Les écrans et les textes

96. [96] `app/Filament/Societe/Pages/TableauDeBord.php:12`, `:60-62`, `:77` (`ETAPE_DEMONSTRATION`), `:117`, `:128-137`, `:186-207`. **Réécrire** au lot 2 : l'étape « Ouvrir ma démonstration » (B.14). Au lot 1, l'étape est retirée.
97. [97] `TableauDeBord.php:349-358` (`demonstration()`), `:362-390` (`ouvrirLaDemonstrationAction()`). **Supprimer** ; la carte arrive au lot 2.
98. [98] `resources/views/filament/societe/pages/tableau-de-bord.blade.php:59-60`, `:102-112`. **Supprimer** au lot 1, **réécrire** pour la carte au lot 2.
99. [99] `app/Policies/SocietePolicy.php:35`, `:51`, `:144-155` : `ouvrirLaDemonstration()`. **Réécrire** en `gererSaDemonstration()` : propriétaire seul, adresse de connexion confirmée, société ni close ni révoquée, validée ou non, cabinet compris ; les gestes « créer » et « recommencer » exigent aussi une société ni suspendue ni résiliée (choix 12).
100. [100] `TableauDeBordDuCabinet.php:39`, `VerificationDuCabinet.php:30`. **Réécrire** : le cabinet reçoit la carte (tableau de bord) ; la page de vérification n'en parle pas.
101. [101] `resources/views/filament/verification-en-cours.blade.php:4-6`, `:12-15`, `:35`. **Réécrire** : « Pour vous essayer en attendant, ouvrez votre démonstration depuis le tableau de bord de votre société. »
102. [102] `resources/views/filament/societe/pages/verification-societe.blade.php:13`. **Réécrire** de même.
103. [103] `Societe.php:698`. **Réécrire** : « votre démonstration vous permet de vous essayer ».
104. [104] `app/Filament/App/Pages/Auth/InscriptionSociete.php:92` : `INFORMATION_ASSUJETTI_UNIQUE`. **Réécrire** : « elle peut s'inscrire et essayer sa démonstration ».
105. [105] `app/Providers/Filament/AppPanelProvider.php:201` : crochet « magasin de démonstration ». **Supprimer** ; le crochet du bandeau du site de démonstration arrive au lot 3, dans les trois fournisseurs de panneau (C.8).
106. [106] `app/Filament/App/Widgets/BienvenueApercu.php:18`. **Réécrire** : « (démonstration) » vient du mode du site (C.8).
107. [107] `app/Filament/Societe/Resources/MagasinResource.php:24`. **Supprimer** la phrase.
108. [108] `app/Filament/PageDesCharges.php:1268` : au point 87.

#### D.13 Le code de magasin, le SIRET et le territoire

109. [109] `CodeMagasin.php:23`, `:30` (`CODE_DE_LA_DEMONSTRATION`), `:61`, `:85-89` (`estReserve()`) ; `CodeMagasinLibre.php:14`, `:23`, `:43` (`MESSAGE_RESERVE`). **Supprimer** la réservation (choix 24).
110. [110] `LibererLeSiretAction.php:53`, `:150` : `MESSAGE_DEMONSTRATION`. **Supprimer**.
111. [111] `CorrigerLeTerritoireAction.php:45`, `:71-72` : `MESSAGE_DEMONSTRATION`. **Supprimer**.
112. [112] `ModifierEtablissementAction.php:49`. **Commentaire**.

#### D.14 L'« À faire »

113. [113] `ChosesAFaireDuMagasin.php:363-369` (assujetti unique), `:1084` (catalogue vide), `:1127` (société à vérifier), `:1152` (écart Sirene). **Simplifier** les quatre sorties.
114. [114] `ChosesAFaireDeLaSociete.php:57`, `:95`, `:373` : au point 87 et 89.

#### D.15 Le contrôle d'isolation et le semis

115. [115] `ControlesDeLaBase.php:295-313` (vente permise au propriétaire dans la démonstration ; identifiant d'un compte d'entraînement retiré) ; `ControlesDeLApplication.php:236`, `:252-255`, `:265-266` (`$sansDemonstration`, `$magasinsOuverts`) ; `ReglesDIsolation.php:64` (`REGLE_DEMONSTRATION`) ; `VerificationDeLIsolation.php:17` ; `app/Console/Commands/VerifierLIsolation.php:21`. **Réécrire** : le propriétaire ne vend jamais ; plus de cas de la démonstration ; `REGLE_DEMONSTRATION` supprimée (vérifier qu'aucun test ne la cite). Au lot 2 : le contrôle tourne sur chaque base modèle (C.2) ; il connaît la table commune `site_de_la_base` et, dans une base modèle seulement, les tables `comptes_du_bandeau` et `courriels_de_la_demonstration` du dossier `database/migrations-demonstration/` (liste nommée et testée), ainsi que les tables `demonstrations` et `messages_vers_la_demonstration` du site réel.
116. [116] `database/seeders/Semis/SemisDeDemonstration.php:30`, `:61-74`, `:199`, `:276`, `:330-332`, `:478`, `:622`, `:673-692` : `sEssayerDansLaDemonstration()`, Romain Lefort (vendeur d'entraînement de la société « commerce »), Karim Haddad (responsable d'entraînement) et Zoé Marchand (vendeuse d'entraînement) de la société « inscription », libellé « Compte d'entraînement ». **Supprimer** ces gestes, ces comptes et leurs lignes de la liste. `ComptesSemes.php:172-173` : **commentaire**.
117. [117] Le nom du semis : la classe `SemisDeDemonstration`, `DatabaseSeeder.php:27-30`, `:49`, `:54`, `:56-62`, `:140-172`, et les constructeurs de `ActiviteDesMagasins`, `ChargesDesSocietes`, `CommandesDesProfessionnels`, `ComptesDesCas`, `DerniersGestes`, `DocumentsDesCas` (« SemisDeDemonstration::JOURS »). **Réécrire** les noms (choix 21), avec les textes : `DatabaseSeeder::FICHIER_DES_COMPTES`, `MESSAGE_HORS_DU_DEVELOPPEMENT` (l. 56, « Les données de démonstration… » → « Le semis… »), `MESSAGE_FOURNISSEURS_REELS`, et les messages et le docblock de `database/seeders/Semis/MotsDePasseDuSemis.php:10-36` (`PILIQI_DEMO_MOT_DE_PASSE`, `piliqi.demo_mot_de_passe`). Les faux amis du relevé 2, § 16 suivent le même renommage là où ils disent « données de démonstration » du semis : `app/Console/SemisEnCours.php:9`, `:53` ; `config/piliqi.php:52` ; `database/seeders/Semis/PieceDuSemis.php:22` ; `SocietesDesCas.php:595`, `:629`. Les phrases « factice pour les tests, la démonstration et le développement » (`config/services.php:112`, `AntivirusServiceProvider.php:19`, `AntivirusFactice.php:8`, `Contracts/Antivirus.php:15`) restent : elles deviennent vraies au sens du site de démonstration. Les mentions « lot DEMO-V » qui datent une décision (`CalculateurLigne.php:643`, `TableauEntry.php:16`, `ClotureCaisseResource.php:136`, `:158`, `EnvoyerDocumentAction.php:163`, `EnvoieLesDocuments.php:134`) restent.

**Fin du lot 1** : `git grep -n -i -E "est_demonstration|estUnCompteDEntrainement|duProprietaireEnDemonstration|OuvrirLaDemonstration|FermerLaDemonstration|FinDemonstration|magasinsReels|CODE_DE_LA_DEMONSTRATION|entra[iî]nement|SemisDeDemonstration|comptes-de-demonstration|PILIQI_DEMO_MOT_DE_PASSE|CatalogueDeDemonstration|demo_mot_de_passe|DemonstrationDeTest|MagasinDeDemonstration|ProprietaireEnDemonstration|REGLE_DEMONSTRATION|MOTIF_DEMONSTRATION|MESSAGE_DEMONSTRATION|données de démonstration|deDemonstration\("` sur `app`, `database`, `config`, `resources`, `routes`, `bootstrap` et `tests` ne trouve plus rien (le compte rendu donne la commande et son résultat vide). `EntreeEnModeDemonstration` et `SortieDuModeDemonstration` restent (point 62).

#### D.16 Les tests du magasin intérieur

Règle du protocole : un test qui prouvait une règle retirée par l'éditeur part avec elle, et la règle est nommée. Aucun test d'une règle qui reste n'est affaibli. Les tests « N » sont ceux de la partie G.

**`tests/Feature/Societe/MagasinDeDemonstrationTest.php` (26 tests ; le fichier se supprime à la fin, ce qui reste juste part dans les fichiers de la partie G) :**
- E1 `test_le_proprietaire_ouvre_une_seule_demonstration_avant_la_validation` : **supprimé** (magasin intérieur, Q2) ; « une seule copie » et « seul le propriétaire » : N6, N10.
- E2 `test_le_catalogue_de_demonstration_suit_le_type_d_activite` : **réécrit** sur les bases modèles (N15).
- E3 `test_la_demonstration_fonctionne_sans_aucun_magasin_reel` : **réécrit** : une société non validée et sans magasin demande sa copie ; dans la copie, le vendeur vend, encaisse et clôture ; rien dans la société réelle (N7, N20).
- E4 `test_le_proprietaire_vend_dans_la_demonstration_et_la_fenetre_le_dit` : **supprimé** (exception du propriétaire, Q2) ; l'inverse est prouvé dans la copie (N20).
- E5 `test_l_administrateur_qui_rouvre_la_demonstration_lit_qu_il_n_y_vend_que_si_la_vente_lui_est_cochee` : **supprimé** (réouverture après inactivité, ouverture par un administrateur).
- E6 `test_l_ouverture_et_la_fin_de_la_demonstration_s_inscrivent_en_jet_100_et_105` : **réécrit** : le JET 100 est dans chaque magasin d'une base modèle et d'une copie neuve, le JET 105 juste avant l'archive (N28) ; aucune base réelle n'en a (N25). L'audit de la demande : N11.
- E7 `test_la_demonstration_vend_encaisse_et_cloture_dans_son_propre_registre` : **réécrit** (N13, N19).
- E8 `test_la_cloture_du_magasin_de_demonstration_s_inscrit_dans_son_propre_journal` : **supprimé** (fermeture à la validation).
- E9 `test_un_vendeur_de_la_demonstration_vend_ses_services_a_leur_prix_de_reference` : **réécrit** sur la base modèle (N15).
- E10 `test_ses_numeros_portent_le_code_demo_et_ne_touchent_aucune_serie_reelle` : **réécrit** (N13).
- E11 `test_aucun_e_mail_ni_aucune_transmission_ne_part_de_la_demonstration` : **réécrit** : l'envoi réussit et paraît dans « E-mails qui seraient partis » (N22).
- E12 `test_ses_documents_portent_une_identite_fictive_et_le_filigrane` : **réécrit** (N18, N19).
- E13 `test_l_en_tete_signe_de_la_demonstration_n_a_ni_siren_ni_siret` : **réécrit** : l'en-tête signé d'une copie porte l'identité fictive (SIREN et SIRET fictifs) et la clé `demonstration` à vrai (choix 18) ; l'en-tête réel n'a pas de clé `demonstration`.
- E14 `test_rien_ne_passe_du_magasin_de_demonstration_aux_magasins_reels` : **réécrit** (N13).
- E15 `test_la_validation_ferme_la_demonstration_qui_disparait_sans_rien_effacer` : **supprimé** (T115) ; la validation ne touche pas la copie (N9).
- E16 `test_la_demonstration_ne_vend_plus_apres_la_validation_meme_si_sa_fermeture_a_echoue` : **supprimé** (T115).
- E17 `test_une_inscription_close_ne_rouvre_pas_sa_demonstration` : **réécrit** (N7, choix 12).
- E18 `test_une_demonstration_inactive_30_jours_se_ferme_et_se_rouvre_avant_la_validation` : **supprimé** (fermeture pour inactivité) ; N9.
- E19 `test_la_demonstration_prend_le_territoire_du_siege_et_le_garde` : **supprimé** (territoire du siège, Q4) ; choix 17.
- E20 `test_le_verificateur_d_integrite_controle_aussi_la_demonstration` : **réécrit** (N19).
- E21 `test_aucun_pdf_d_un_magasin_reel_ne_se_telecharge_avant_la_validation` : **supprimé** ; la partie réelle est déjà dans `PorteDeValidationTest` sous le même nom.
- E22 `test_le_proprietaire_et_un_responsable_creent_des_comptes_d_entrainement_dans_la_demonstration` : **supprimé** (comptes d'entraînement).
- E23 `test_un_administrateur_ne_cree_aucun_compte_d_entrainement` : **supprimé** (même règle).
- E24 `test_les_comptes_d_entrainement_sont_retires_a_la_validation_ou_a_la_cloture_et_gardes_a_la_fermeture_pour_inactivite` : **supprimé** (même règle).
- E25 `test_aucun_compte_ne_se_cree_ni_ne_se_reintegre_dans_une_demonstration_terminee` : **supprimé** (même règle).
- E26 `test_un_compte_d_entrainement_n_ouvre_jamais_un_magasin_reel` : **supprimé** (même règle) ; « un membre n'ouvre que son magasin » reste prouvé par les tests des comptes.

**`tests/Feature/Authorization/ProprietaireEnDemonstrationTest.php` (11 tests, D71 ; le fichier se supprime) :**
- E27 `test_le_proprietaire_a_toutes_les_autorisations_dans_la_demonstration_et_aucune_de_vente_dans_ses_magasins_reels` : **supprimé** ; « aucune de vente » est dans `ProprietaireSansVenteTest`.
- E28 `test_le_proprietaire_ouvre_la_caisse_de_la_demonstration_et_voit_les_boutons_de_la_vente` : **supprimé**.
- E29 `test_le_proprietaire_vend_encaisse_fait_un_avoir_utilise_un_bon_ferme_sa_caisse_et_cloture_dans_la_demonstration` : **supprimé**.
- E30 `test_le_brouillon_du_proprietaire_dans_la_demonstration_reste_le_sien_quand_un_autre_compte_l_emet` : **supprimé**.
- E31 `test_le_proprietaire_ne_vend_ni_dans_la_demonstration_fermee_a_la_validation_ni_dans_un_magasin_reel` : **supprimé** ; la partie réelle est dans `ProprietaireSansVenteTest`.
- E32 `test_le_proprietaire_ne_vend_plus_dans_la_demonstration_fermee_pour_inactivite_et_y_vend_de_nouveau_une_fois_rouverte` : **supprimé**.
- E33 `test_dans_la_demonstration_fermee_le_proprietaire_ne_tient_plus_de_caisse_ni_n_y_ferme_un_releve_a_zero` : **supprimé**.
- E34 `test_dans_la_demonstration_fermee_le_proprietaire_ferme_encore_la_caisse_qu_il_y_a_laissee_ouverte` : **supprimé**.
- E35 `test_la_fermeture_et_la_reouverture_de_la_demonstration_s_inscrivent_aux_droits_du_proprietaire` : **supprimé**.
- E36 `test_la_base_accepte_une_vente_ou_un_encaissement_du_proprietaire_dans_sa_demonstration_seulement` : **supprimé** ; le refus partout est dans `ProprietaireSansVenteTest::test_la_base_refuse_une_vente_ou_un_encaissement_signe_au_nom_du_proprietaire`, et la garde sans exception par E60 et N25.
- E37 `test_la_base_refuse_qu_un_magasin_devienne_ou_cesse_d_etre_la_demonstration` : **supprimé** (colonne disparue) ; N25 prouve qu'elle n'existe plus.

**`tests/Feature/Societe/DemonstrationApresLaValidationTest.php` (7 tests, T115 ; le fichier se supprime) :**
- E38 `test_une_fois_la_societe_validee_la_demonstration_disparait_de_ses_ecrans_sans_rien_effacer` : **supprimé**.
- E39 `test_les_telechargements_de_la_demonstration_disparaissent_une_fois_la_societe_validee` : **supprimé**.
- E40 `test_une_demande_de_la_demonstration_preparee_apres_la_validation_se_clot_sans_rien_annoncer` : **supprimé**.
- E41 `test_les_notifications_de_la_demonstration_s_effacent_a_la_validation` : **supprimé**.
- E42 `test_le_cabinet_lie_ne_voit_jamais_la_demonstration_de_son_client` : **supprimé** ; remplacé par N6 (un compte du cabinet lié ne voit ni ne demande la copie du client sur le site réel).
- E43 `test_l_equipe_de_la_societe_ne_nomme_plus_la_demonstration_une_fois_la_societe_validee` : **supprimé**.
- E44 `test_la_demonstration_d_une_societe_validee_ne_se_choisit_plus_dans_la_portee_d_un_administrateur` : **supprimé**.

**Tests en navigateur consacrés à la démonstration :**
- E45 `tests/Browser/Parcours/ProprietaireEnDemonstrationTest.php` › « le propriétaire vend à la caisse de la démonstration, encaisse en espèces et par carte, ferme sa caisse et clôture la journée » : **supprimé** (D71).
- E46 même fichier › « le propriétaire fait une facture dans la démonstration, l'émet et l'encaisse depuis sa fiche » : **supprimé** (D71). Le fichier se supprime.
- E47 `tests/Browser/Parcours/OuvrirLaDemonstrationTest.php` › « le propriétaire ouvre le magasin de démonstration, dont la fenêtre dit qu'il y vend » : **réécrit** en parcours « Ma démonstration » (N23) ; le fichier devient `tests/Browser/Demonstration/MaDemonstrationTest.php`.
- E48 même fichier › « l'administrateur rouvre le magasin de démonstration fermé faute d'opération… » : **supprimé** ; N23 prouve que l'administrateur ne voit pas la carte.
- E49 `tests/Browser/Parcours/DemonstrationApresLaValidationTest.php` › « une fois la société validée, le propriétaire et l'administrateur ne voient plus la démonstration et son adresse est introuvable » : **supprimé** (T115). Le fichier se supprime.
- E50 `tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php` › « le propriétaire d'une société qui attend son code ouvre chaque page et chaque action du magasin de démonstration sans erreur » : **réécrit** dans le balayage des copies (N24).
- E51 même fichier › « le responsable d'entraînement ouvre chaque page… » : **réécrit** dans N24.
- E52 même fichier › « le vendeur d'entraînement ouvre chaque page… » : **réécrit** dans N24. Les trois tests du cabinet lié et de l'expert-comptable de ce fichier restent ; le fichier devient `BalayageDuMagasinParLeCabinetTest.php`, et ses citations de l'inventaire suivent.
- E53 `tests/Browser/TestCaseDuNavigateur.php::demonstration()` (ligne 307) et `tests/Browser/Support/DemonstrationDeTest.php` : **supprimés** ; à la place, l'aide de la copie (L5). Les tests qui les utilisaient : E45, E46, E50, E51, E52, E115, E116, E117, E118.

**Droits et gardes :**
- E54 `GardesDesDroitsEnBaseTest::test_la_base_refuse_qu_un_compte_d_entrainement_change_de_role_de_societe` : **supprimé** (la clause de `GardesDesDroits::ROLES` part).
- E55 `MagasinDeLaSocietePolicyTest::test_seul_qui_gere_tous_les_magasins_ouvre_la_demonstration` : **réécrit** : seul le propriétaire crée, renouvelle et recommence ; aucun administrateur (N6).
- E56 `MagasinDeLaSocietePolicyTest::test_personne_n_agit_sur_le_magasin_de_demonstration` : **supprimé**.
- E57 `ActionsHorsEcranTest` : le geste « ouvrir la démonstration » (liste `$verification`, `geste()`, `REFUS_DES_DROITS`) dans `test_chaque_action_refuse_un_auteur_sans_le_droit_sans_rien_ecrire`, `…_d_une_autre_societe_…`, `…_suspendu_ou_retire_…` et `test_un_compte_de_cabinet_n_ecrit_que_ce_que_le_client_autorise_meme_par_l_action` : **réécrit** en trois gestes (créer, renouveler le lien, recommencer), chacun refusé sans le droit, d'une autre société, suspendu ou retiré, et pour un compte de cabinet, sans rien écrire et sans aucun message dans la boîte d'envoi.
- E58 `AutorisationsSelonLeTypeTest::test_le_jet_130_ne_liste_que_les_autorisations_du_type` : **réécrit** : un vrai magasin d'une société de services, puis un vendeur ; le JET 130 du propriétaire (`duProprietaire()`) et du vendeur ne liste aucune autorisation des articles.
- E59 `LecteurDesDroitsTest::test_les_actions_de_la_societe_inscrivent_leurs_evenements_au_nom_de_l_auteur_passe_a_l_action`, cas « ouvrir la démonstration » : **réécrit** : le cas sort, les quatre autres restent.
- E60 `FonctionsDeGardeTest::test_une_table_temporaire_des_magasins_ne_trompe_pas_la_garde_de_la_vente` : **réécrit** : l'ombre porte sur la table que la garde lit encore (`users`, avec un faux rôle).
- E61 `VerificationDeLIsolationTest::test_une_vente_au_nom_du_proprietaire_dans_un_magasin_reel_est_signalee_par_la_base` : **gardé** ; nom « dans un magasin ».

**Cabinets et expert-comptable :**
- E62 `AccesDeLExpertComptableTest::test_l_expert_comptable_n_ouvre_ni_l_espace_de_la_societe_ni_la_demonstration_ni_la_caisse_ni_l_espace_d_un_cabinet` : **réécrit** sans « la démonstration » ; plus : sur le site réel, il ne voit ni ne demande la copie (N6). Dans la copie, l'expert-comptable déclaré est un compte du bandeau, avec ses vraies règles (N20).
- E63 `AccesDuCollaborateurChezLeClientTest::test_un_collaborateur_n_ouvre_ni_l_espace_de_la_societe_du_client_ni_sa_demonstration_ni_sa_caisse` : **réécrit** sans « sa démonstration » ; plus : sur le site réel, il ne voit ni ne demande la copie du client (N6). Dans la copie d'une société, aucun compte de cabinet n'existe (choix 3).
- E64 `AutorisationsDuCabinetTest::test_le_cabinet_lit_un_magasin_reel_cree_apres_le_lien_et_jamais_la_demonstration` : **réécrit** sans la démonstration.
- E65 `CabinetInscriptionTest::test_la_liste_d_etapes_du_cabinet_ne_parle_ni_de_magasins_ni_de_demonstration` : **réécrit** : pas de magasins ; l'étape « Ouvrir ma démonstration » est là (choix 14).
- E66 `CabinetInscriptionTest::test_la_verification_du_cabinet_rappelle_son_type_et_ne_parle_ni_de_magasins_ni_de_demonstration` (et `assertSansMagasinNiDemonstration`) : **gardé**.
- E67 `MagasinsOuvertsAuCabinetTest::test_une_selection_vide_un_magasin_d_une_autre_societe_ou_la_demonstration_sont_refuses` : **réécrit** ; `societeAvecSaDemonstration()` devient une société à deux magasins.
- E68 `MagasinsOuvertsAuCabinetTest::test_la_base_refuse_une_selection_incoherente` : **réécrit** sans la ligne de la démonstration ; le refus d'un magasin inconnu reste (D.2, point 12).
- E69 `TableauDeBordDuCabinetTest::test_la_lecture_ne_rend_que_les_colonnes_permises` : **réécrit** : la requête attendue ne lit plus `est_demonstration`.
- E70 `TableauDeBordDuCabinetTest::test_la_demonstration_d_un_client_n_entre_jamais_dans_les_totaux` : **supprimé**.
- E71 `IsolationDesCabinetsTest::setUp()` et `::test_une_requete_livewire_forgee_de_la_fiche_d_un_client_ne_rend_l_adresse_d_aucun_autre_magasin` : **réécrits** sans la démonstration.

**Calendrier et planificateur :**
- E72 `NuitDeParisTest::test_la_demonstration_fermee_la_nuit_cloture_la_journee_de_paris` : **supprimé** ; le cas réel est dans `test_un_magasin_ferme_la_nuit_cloture_la_journee_de_paris`.
- E73 `NuitDeParisTest::test_un_magasin_de_demonstration_se_ferme_apres_trente_jours_d_inactivite_exactement` : **supprimé** ; N9.
- E74 `PlanificateurTest`, liste des tâches attendues (ligne 40, `app:fermer-demonstrations-inactives`, `45 4 * * *`) : **réécrit** sans cette tâche, avec les deux tâches du site réel (B.8, B.13) ; N16 pour le site de démonstration.
- E75 `PlanificateurTest::test_aucune_tache_planifiee_ne_part_pendant_le_semis_des_donnees_de_demonstration` : **gardé** ; nom « semis » (choix 21).

**Charges, ventes, e-reporting, PDF :**
- E76 `GardesDesChargesEnBaseTest::test_une_charge_n_existe_ni_dans_un_cabinet_ni_dans_le_magasin_de_demonstration` : **réécrit** : le refus dans un cabinet et pour un magasin inconnu.
- E77 `EReportingDesParticuliersTest::test_le_magasin_de_demonstration_n_a_rien_a_declarer` : **réécrit** : dans la copie, rien ne sort vers l'extérieur (N22) ; l'aperçu est le vrai (choix 18).
- E78 `EReportingDesParticuliersTest::test_une_journee_donne_une_ligne_par_categorie_et_par_taux` : **gardé** ; retouché pour l'aperçu sans champ `demonstration`.
- E79 `EmetteurFigeTest::test_un_document_de_demonstration_n_imprime_aucune_identite_reelle` : **réécrit** pour le mode du site (N18).
- E80 `ExonerationNonProuveeTest::test_le_proprietaire_ne_regularise_pas_hors_du_magasin_de_demonstration` : **gardé** ; nom « le propriétaire ne régularise pas ».
- E81 `MarquerFacturesEnRetardConcurrenceTest::test_le_passage_en_retard_pendant_la_validation_ou_le_refus_de_la_societe_passe_sans_interblocage` (4 cas) : **supprimé** (fermeture de la démonstration à la validation et à la clôture).
- E82 `MarquerFacturesEnRetardTest::test_une_facture_de_la_demonstration_ne_s_annonce_plus_une_fois_la_societe_validee` et l'aide `facturerDansLaDemonstration` : **supprimés** (T115).
- E83 `RecusDePaiementTest::test_le_magasin_de_demonstration_marque_ses_recus` : **réécrit** pour le mode du site (N18).
- E84 `TransformerUnDevisEnFactureTest::test_le_proprietaire_transforme_dans_le_magasin_de_demonstration` : **supprimé** (D71).

**Écrans :**
- E85 `ActionsForgeesTest::test_une_action_d_en_tete_masquee_appelee_directement_ne_fait_rien`, cas « ouvrir la démonstration » : **réécrit** : « créer ma démonstration » forgé par un administrateur ne crée rien et n'écrit aucun message.
- E86 `BienvenueApercuTest::test_le_magasin_de_demonstration_le_dit` : **réécrit** : dans la copie, « Bonjour » dit le rôle choisi, suivi de « (démonstration) » (N20) ; au réel, jamais.
- E87 `ModifierProfilTest::magasinDe()` : **réécrit** sans le filtre.

**Semis :**
- E88 `SemisCompletTest::assertChaqueSocieteQuiVendAUnRegimeDeTva` : **réécrit** sans `m.est_demonstration`.
- E89 `SemisCompletTest::assertChaqueRoleAUnCompteQuiASesDonnees` : **réécrit** sans le rôle « entrainement ».
- E90 `SemisCompletTest::assertLesComptesEtLeursMagasins` : **réécrit** (commentaire) ; `test_le_semis_complet_remplit_toute_la_base_et_tous_les_cas` gagne les états des demandes (N26).
- E91 `DatabaseSeederTest` : `test_un_mot_de_passe_commun_hors_de_la_regle_est_refuse_sans_rien_ecrire`, `test_le_mot_de_passe_commun_va_a_chaque_compte_sauf_s_il_contient_son_identifiant`, `test_sans_mot_de_passe_commun_chaque_compte_recoit_un_mot_de_passe_tire_au_hasard` : **gardés** ; la clé devient `piliqi.semis_mot_de_passe`.
- E92 `tests/Browser/Parcours/DonneesDeDemonstrationTest.php` › « chaque rôle de la liste du semis se connecte et retrouve ses propres données » : **gardé** ; fichier `DonneesSemeesTest.php`, constantes renommées.

**Société :**
- E93 `AssujettiUniqueTest::test_le_magasin_de_demonstration_reste_utilisable` : **réécrit** : la société membre d'un assujetti unique demande sa copie, et on y vend (Q4).
- E94 `ProprietaireSansVenteTest` : **gardé** ; le commentaire « Seul le magasin de démonstration fait exception » sort ; `test_le_proprietaire_n_a_aucune_autorisation_de_vente_dans_aucun_de_ses_magasins`.
- E95 `CaissesDeLaSocieteTest::test_la_demonstration_et_les_magasins_d_une_autre_societe_n_y_figurent_jamais` : **réécrit** : « une autre société n'y figure jamais ».
- E96 `ChangementDeRoleTest::test_un_compte_d_entrainement_ne_devient_pas_administrateur` : **supprimé** (comptes d'entraînement).
- E97 `ChangementDeRoleTest::test_un_administrateur_n_est_jamais_retrograde_dans_la_demonstration_ni_dans_un_magasin_ferme_ou_cede` : **réécrit** : « jamais dans un magasin fermé ou cédé ».
- E98 `FacturesAEncaisserDeLaSocieteTest::test_le_magasin_de_demonstration_n_a_pas_de_ligne` : **supprimé**.
- E99 `IdentifiantsTest::test_la_base_refuse_un_role_de_societe_pour_un_compte_d_entrainement_et_le_rattachement_tardif_a_la_demonstration` : **supprimé**.
- E100 `IdentifiantsTest::test_seul_un_compte_de_membre_nait_rattache_au_magasin_de_demonstration` : **supprimé**.
- E101 `InscriptionSocieteTest::test_apres_le_lien_le_proprietaire_arrive_sur_la_liste_de_ses_premieres_etapes` : **réécrit** : la quatrième étape est « Ouvrir ma démonstration ».
- E102 `MagasinResourceTest::test_la_liste_ne_montre_que_les_magasins_reels_de_la_portee` : **réécrit** ; nom « … les magasins de la portée ».
- E103 `PanneauSocieteTest::test_le_tableau_de_bord_coche_chaque_premiere_etape_faite` : **réécrit** : l'étape est cochée quand une copie existe ; son lien mène à la carte.
- E104 `PanneauSocieteTest::test_une_societe_validee_par_l_invitation_de_son_cabinet_ne_voit_ni_l_etape_du_courrier_ni_celle_de_la_demonstration` : **réécrit** : pas l'étape du courrier, mais celle de la démonstration (choix 13).
- E105 `PanneauSocieteTest::test_les_premieres_etapes_ne_montrent_a_un_administrateur_aucun_nombre_hors_de_sa_portee` : **réécrit** : « Ouvert : vous vous y entraînez » devient l'état de la copie (« Prête jusqu'au 16/12/2026 »), que l'administrateur ne voit jamais.
- E106 `RenouvellementDAccesTest::test_un_administrateur_ne_nomme_aucun_responsable_dans_la_demonstration` : **supprimé**.
- E107 `RenouvellementDAccesTest::test_un_administrateur_ne_redonne_pas_l_acces_d_un_compte_d_entrainement` : **supprimé**.
- E108 `TerritoireDuMagasinTest::test_un_magasin_verifie_et_la_demonstration_ont_toujours_un_territoire` : **réécrit** : « un magasin vérifié a toujours un territoire ».
- E109 `TypeDActiviteTest::test_un_cabinet_n_a_jamais_de_magasin_ni_de_demonstration` : **réécrit** : toujours aucun magasin ; son propriétaire demande une copie du type « Cabinet » (N6).
- E110 `TypeDActiviteTest::test_les_textes_de_la_verification_ne_parlent_a_un_cabinet_ni_de_magasins_ni_de_demonstration` (et `assertTextesSansMagasinNiDemonstration`) : **gardé**.
- E111 `VerificationSocieteTest::test_la_societe_se_valide_sans_aucun_magasin_et_ne_vend_rien_avant_son_premier_magasin` : **réécrit** : avant le premier magasin, aucune période d'activité.
- E112 `VerificationSocieteTest::test_sans_code_trente_jours_apres_le_courrier_l_inscription_se_clot_et_libere_le_siren` : **réécrit** : « la démonstration se ferme » sort ; la copie de l'inscription close est supprimée (choix 12).

**Tableau de bord et isolation :**
- E113 `SeriesDesGraphiquesTest::test_la_demonstration_n_entre_jamais_dans_les_graphiques` : **supprimé** (règle « jamais pour le magasin de démonstration ») ; N20.
- E114 `IsolationDesSocietesTest::test_la_lecture_de_la_premiere_vente_ne_compte_que_les_magasins_reels_de_la_societe` : **réécrit** sans la démonstration ; N13.

**Tests en navigateur d'autres fichiers :**
- E115 `BalayageDeLEspaceDeLaSocieteTest` › « le propriétaire d'une société qui attend son code ouvre chaque page et chaque action de l'espace de sa société sans erreur » : **réécrit** : inscription sans démonstration ; le balayage ouvre la carte et ses actions sans rien valider.
- E116 `FicheDUneFactureTest` › « le propriétaire modifie puis émet depuis sa fiche un brouillon du magasin de démonstration » : **supprimé** (D71) ; le geste est prouvé pour le responsable, le vendeur et l'administrateur qui vend.
- E117 `UnProduitUneLigneTest` › « dans la démonstration, le propriétaire choisit de nouveau un produit à la caisse : sa ligne monte, et il encaisse » : **supprimé** (D71) ; prouvé pour le vendeur et l'administrateur qui vend.
- E118 `UnProduitUneLigneTest` › « le vendeur et le responsable d'entraînement ajoutent un produit sur une facture de la démonstration, le reprennent, puis retirent sa ligne par la corbeille » : **réécrit** dans un vrai magasin ; la corbeille n'est prouvée nulle part ailleurs : on ne la perd pas.
- E119 `ReponseAuDevisEnLigneTest` › « le propriétaire transforme un devis accepté dans le magasin de démonstration » : **supprimé** (D71) ; le refus du propriétaire est déjà prouvé dans ce fichier.

**Listes et outillage :**
- L1 **L'inventaire des actions** (`tests/Browser/inventaire-des-actions.php`, 586 actions ; 143 citent un test de la démonstration, 305 citations) : retirer chaque citation d'un test supprimé ou renommé, citer les tests réécrits sous leur nouveau nom. Deux actions n'étaient couvertes que par la démonstration :
  - `ListInventairesOuverture.php` › `action:create` : un test en navigateur d'un vrai magasin qui n'a pas encore vendu, où le propriétaire puis un responsable ouvrent « Nouvel inventaire d'ouverture » ;
  - `Filament/Societe/Pages/TableauDeBord.php` › `action:ouvrirLaDemonstration` : remplacée par les actions de la carte (N23).
  
  L'entrée `tableau-de-bord.blade.php` › `lien:Ouvrir le magasin de démonstration` disparaît. Les actions nouvelles (créer, ouvrir, copier le lien, renouveler, recommencer, changer de compte, page des e-mails, quitter) entrent avec leur test.
- L2 **La matrice des décisions** (`tests/Unit/matrice-des-decisions.php`) : voir H.4.
- L3 **« Magasin réel » et `magasinsReels()`** : retouchés sans changer ce qu'ils prouvent : `DroitsDuResponsableTest`, `CabinetsConcurrenceTest`, `MagasinsOuvertsAuCabinetTest`, `AdministrateursTest`, `MagasinResourceTest`, `ValiderLaSocieteConcurrenceTest`, `VerificationSocieteTest`, `tests/Browser/Parcours/ChargesTest.php`. Les noms de tests qui disent « magasins réels » (`QuiVoitLesGraphiquesTest::test_le_proprietaire_voit_tout_sur_tous_ses_magasins_reels`, `AccesAuxPanneauxTest::test_un_collaborateur_ouvre_les_magasins_reels_…` et les autres trouvés par `git grep -n "magasins_reels\|magasin_reel" -- tests`) perdent « reels », pour suivre `magasins()`.
- L4 **La fixture** `tests/Fixtures/empreinte-de-la-caisse/apercu-e-reporting.txt` (`"demonstration": false`) : refaite par la commande prévue, la raison dans le message du commit (choix 18).
- L5 **Les aides de test** : `tests/TestCase.php`, commentaire de `responsableDeTest()` retouché ; `TestCaseDuNavigateur::demonstration()` et `DemonstrationDeTest` supprimés ; à la place, `CopieDeTest` : crée une copie d'un modèle de test minuscule et y entre par son lien (G.2).
- L6 **Une seconde base dans les tests** : G.2.
- L7 **L'application en mode démonstration dans un test** : G.2.

### E. Les mises à jour du logiciel (réponse à votre question)

**En quelques phrases.** Il n'y a qu'une version de PILIQI, déployée en même temps sur le site réel et sur le site de démonstration. Avant la mise en production, il n'y a que votre poste : à chaque mise à jour qui change la base, tout repart de zéro, liens compris, et on recrée sa démonstration. Après la mise en production, chaque mise à jour garde la base réelle et chaque démonstration (les mêmes migrations passent sur les deux), et une démonstration qu'on ne peut pas mettre à jour repart de zéro avec le même lien et un message.

1. **Une seule version pour tous.**
   - Le même commit, construit une fois après une CI verte, sert aux deux sites. Le paquet commun ne contient aucun cache de configuration, de routes ni d'événements : chaque serveur les refait après la pose de son `.env` (A.11).
   - `PILIQI_VERSION` (date et court numéro du commit) s'écrit au déploiement. Elle s'affiche en bas de chaque page des deux sites, avec le mode, et dans le bandeau.
   - Le registre du site de démonstration garde la version et l'empreinte du schéma de chaque modèle et de chaque copie.
2. **L'empreinte du schéma décide.** Une empreinte SHA-256 de la liste des fichiers de `database/migrations` et de `database/migrations-demonstration`, et de leur contenu, se calcule au déploiement. Une copie dont l'empreinte diffère de celle du code n'est jamais servie : ses visiteurs voient « Mise à jour en cours, une minute », jamais une erreur.
3. **L'ordre du déploiement**, le même avant et après la mise en production :
   1. CI verte sur le commit ;
   2. sur le serveur de démonstration, le nouveau code se pose dans un dossier de version à côté de l'ancien (déploiement par dossiers datés et lien symbolique) ; il construit les trois nouveaux modèles dans de nouvelles bases `demo_travail_*`, sans toucher les modèles ni les copies en service : le site de démonstration continue de servir l'ancienne version pendant ce temps ;
   3. le site réel passe au nouveau code et à ses migrations (avant la mise en production : `migrate:fresh` si le schéma change ; après : `migrate --force`) ;
   4. le site de démonstration bascule : nouveau code, pointeurs vers les nouveaux modèles, puis la mise à jour des copies (points 4 et 5) ;
   5. les messages que le site réel a écrits pendant ce temps attendent dans sa boîte d'envoi et partent dès que le site de démonstration répond (`409` « en mise à jour » les fait réessayer).
   - Le site réel d'abord : il porte les vraies données et les vraies obligations, et il ne dépend jamais du site de démonstration.
   - Le protocole des messages porte sa version (`v`) : le site de démonstration accepte la courante et la précédente. Un changement du protocole se fait en deux déploiements (d'abord la démonstration qui comprend les deux, puis le réel qui envoie la nouvelle). Ainsi l'écart de quelques minutes entre les deux sites ne casse jamais rien.
4. **Avant la mise en production** (aujourd'hui : une seule migration de création par table, `migrate:fresh`). Il n'existe encore aucun site déployé : tout se passe sur le poste de l'éditeur et dans la CI.
   - Chaque mise à jour reconstruit les trois modèles avec le nouveau code et le nouveau semis.
   - Quand la base réelle est recréée (`migrate:fresh --seed`, à chaque changement de schéma), les sociétés, donc leurs demandes et leurs liens, disparaissent avec elle. Le poste lance alors `demonstration:tout-effacer` (toutes les copies, leurs archives et le registre effacés, les modèles gardés), puis `demonstration:renvoyer-les-demandes` recrée les copies des sociétés semées (point 9). Ces copies ont de nouveaux liens : on recrée sa démonstration. `demonstration:tout-effacer` refuse en production.
   - Quand une mise à jour ne recrée pas la base réelle (un changement sans schéma nouveau), chaque copie est quand même refaite depuis le nouveau modèle de son type (choix 6) : archive (C.18), `DROP DATABASE … WITH (FORCE)`, nouvelle copie, **même référence, même lien, même fin** ; commande `demonstration:refaire-les-copies`, une copie à la fois (quatre au plus). À la visite suivante, une fois par témoin, en tête de « Choisissez qui vous êtes » et dans le bandeau : « PILIQI a été mis à jour le 20/11/2026. Votre démonstration repart de la société fictive de départ, avec les nouveautés. Votre lien reste le même. »
5. **Après la mise en production** (étape 15 : migrations ajoutées, sans rien casser), `demonstration:migrer-les-copies`, par `demo_admin` :
   1. pour chaque copie « prête », l'une après l'autre (quatre au plus en même temps) : état `en_migration` (ses visiteurs voient « Mise à jour en cours, une minute ») ; l'ancien code (le dossier de la version d'avant, encore sur le disque) fait l'archive de la caisse de la copie (C.18), qui lit à coup sûr son schéma ; puis `php artisan migrate --force` du nouveau code sur sa base (migrations communes et du site de démonstration), dans un processus à part, avec un délai de 10 minutes ;
   2. réussite : l'archive d'avant la migration est effacée (la copie gardera ses données et refera son archive à sa fin) ; nouvelle empreinte et nouvelle version au registre, état `prete` ; message « PILIQI a été mis à jour le 03/02/2027. Votre démonstration a reçu les nouveautés ; vos essais sont gardés. Pour voir les nouveautés avec leurs données, choisissez « Recommencer à zéro » dans votre compte. » ;
   3. échec ou délai dépassé : chaque migration de PostgreSQL est dans sa transaction, la base reste cohérente mais ancienne ; l'archive faite avant la migration couvre la suppression ; la copie est refaite depuis le nouveau modèle (même référence, même lien, même fin), avec le message « PILIQI a été mis à jour le 03/02/2027. Votre démonstration a dû être refaite : vos essais n'ont pas pu être gardés. Votre lien reste le même. » ; les autres copies ne sont pas touchées ;
   4. un tableau final (copies migrées, refaites, durée) s'écrit au journal du site et dans l'état publié (C.19), que le site réel lit et transmet à l'éditeur ;
   5. les copies sont un banc d'essai de plus pour les migrations : ce sont les mêmes que sur la base réelle.
   - Une copie migrée garde ses données et reçoit les nouvelles colonnes, pas les nouveaux cas du semis : le message le dit.
   - Après chaque réfection, le « compte choisi en dernier » des témoins est effacé (C.8).
6. **Le semis suit chaque lot.** Le point 6 de CLAUDE.md oblige chaque lot à mettre le semis à jour. Les modèles étant refaits chaque nuit et à chaque déploiement par ce semis, chaque nouveauté paraît d'elle-même dans les démonstrations neuves et remises à zéro, au plus tard le lendemain.
7. **Une copie perdue** (base absente du serveur) : refaite depuis le modèle à la visite suivante, avec le message « Votre démonstration a dû être refaite le … ; votre lien reste le même. » Sans base, il n'y a rien à archiver : l'événement s'écrit au journal du site et dans l'état publié.
8. **Le message ne part jamais par e-mail** : le site de démonstration ne connaît aucune adresse de la société (Q4). Le site réel sait seulement ce qu'il a demandé (« Recommencer à zéro ») : la carte le dit. Il ne sait pas qu'une mise à jour a refait la copie : c'est voulu.
9. **Une commande du site réel, `demonstration:renvoyer-les-demandes`**, renvoie le message `creer` de chaque copie en cours, avec l'empreinte gardée. Elle sert quand le registre du site de démonstration a été perdu, et sur le poste de l'éditeur quand il lance le site de démonstration après le semis (partie I). Elle est idempotente. Elle lit hors de tout contexte de société, avec un filtre explicite (B.8).
10. **Ce que fait ce lot** :
    - l'empreinte du schéma, la version, la page « Mise à jour en cours » ;
    - `demonstration:construire-les-modeles`, `demonstration:refaire-les-copies`, `demonstration:migrer-les-copies`, `demonstration:tout-effacer`, `demonstration:nettoyer`, `demonstration:renvoyer-les-demandes` ;
    - leurs tests : la migration des copies se prouve avec un dossier de migrations de test (une colonne ajoutée, puis une migration qui échoue sur une copie précise), sans toucher aux migrations du logiciel ;
    - les messages et leurs textes ;
    - le README : l'ordre du déploiement, écrit pas à pas, et les caches refaits sur chaque serveur.
11. **Ce qui revient à l'étape 15** :
    - le script de déploiement réel des deux serveurs (dossiers datés, lien symbolique, ordre du point 3, caches refaits sur chaque serveur) ;
    - **la machine et le serveur PostgreSQL du site de démonstration, à part du réel** (choix 9 : c'est une règle) ; PostgreSQL 18 ; un système de fichiers qui sait cloner ; le domaine du site de démonstration, à lui, jamais un sous-domaine du réel (A.10 ; question 2 de `questions-editeur.md`) ;
    - les rôles PostgreSQL du site de démonstration, déjà en place depuis ce lot (A.12) : `demo_admin` (`CREATEDB`, pas superutilisateur, membre de `pg_signal_backend`), `demo_app` (mêmes privations qu'au réel dans chaque copie), le rôle du contrôle de l'isolation ; le rôle du site réel n'a jamais `CREATEDB` ni `CONNECT` sur une base de démonstration, et `caisse:diagnostiquer` du réel le vérifie ;
    - les clés au coffre (caisse et journal d'audit de la démonstration, messages et état Ed25519, `APP_KEY`), avec rotation ; les empreintes des clés d'audit de démonstration de production épinglées au dépôt ;
    - ClamAV sur le serveur de démonstration ; le pare-feu (sortie fermée sauf NTP et mises à jour du système ; `/interne/messages` ouvert au seul site réel ; `/interne/etat` lu par le seul site réel) ;
    - le plafond de copies, la part réservée aux sociétés validées et les quotas, d'après les mesures du lot et de la campagne (question 4 de `questions-editeur.md`) ;
    - la surveillance par l'état publié (C.19) : place disque, durée des constructions, copies en échec de migration, archives ;
    - aucune sauvegarde des copies ni des modèles (ils se refont) ; les archives de caisse des copies (C.18) sont sauvegardées et gardées six ans ; la configuration et les clés vont au coffre ;
    - la revue de sécurité (OWASP ASVS niveau 2) couvre le site de démonstration : lien, témoin, entrée, billet, changement de compte, routage, messages, état publié, rôles ;
    - la première mise à jour après la mise en production mesure la durée de `demonstration:migrer-les-copies`.

### F. Le semis (point 6 de CLAUDE.md)

1. **Un seul semis, trois modèles.** Le semis du poste (`SemisComplet`, ex-`SemisDeDemonstration`) reste la source unique.
   - Chaque société semée porte une étiquette : le modèle où elle entre, ou aucun.
   - `--modele=commerce|services|cabinet` ne sème que la société mise en avant de ce modèle et ce qu'elle voit (F.2), par les mêmes classes et les mêmes gestes que le semis du poste.
   - **Une base modèle ne contient jamais une société qu'un compte du bandeau ne peut pas ouvrir.** Dans les modèles « Commerce » et « Services », il n'y a que la société mise en avant. Dans le modèle « Cabinet », il y a le cabinet et ses clients, qui ne s'ouvrent que depuis l'espace du cabinet (précision du 16/11 vers 1 h 30).
   - **Un geste qui relie deux sociétés de types différents** est remplacé, dans le modèle, par son équivalent dans une seule société. Le cas principal : sur le poste, MAISON FERRAND est liée au cabinet MARTIN EXPERTISE COMPTABLE ; dans le modèle « Commerce », MARTIN n'existe pas, et MAISON FERRAND déclare son expert-comptable par le SIREN fictif de MARTIN (absent de cette base), avec les mêmes personnes (Paul Lefèvre, comptes de la société, mot de passe choisi). Le lot liste chaque remplacement dans le compte rendu, avec son test.
   - Variante écartée : une seule base modèle avec tout le semis, copiée pour tous. Chaque copie serait plus grosse, la décision dit trois bases modèles, et la précision du 16/11 vers 1 h 30 interdit de voir les autres types.
2. **Ce que contient chaque modèle** (les noms sont ceux du semis du poste ; leurs SIREN, SIRET et adresses sont fictifs, point 5) :
   - **Modèle « Commerce ou atelier » : MAISON FERRAND seule.**
     - Trois magasins : les Halles et la République (métropole), et **Fort-de-France** (Martinique : 8,5 % et 2,1 %, heure des Antilles ; choix 17). Ce troisième magasin s'ajoute aussi au semis du poste, avec sa responsable et son vendeur, et `NOMBRES_DE_LIGNES` suit.
     - Réel normal mensuel (le visiteur peut changer de régime dans sa copie, choix 31), crédit de TVA reporté (TVA27).
     - L'équipe : une administratrice sans vente cochée aux graphiques et aux charges ; un administrateur qui vend dans les deux magasins de métropole ; une responsable aux Halles cochée aux graphiques et aux charges ; des vendeurs (Lucas Moreau, un compte dans chaque magasin de métropole, promu responsable à la République ; Inès Garcia, qui garde son identifiant pour mot de passe ; Sarah Petit, qui voit les factures de toute l'équipe ; Yanis Morel, suspendu) ; une observatrice ; l'expert-comptable déclaré (Paul Lefèvre, compte actif ; une seconde personne qui n'a pas encore choisi son mot de passe).
     - Les documents : des milliers de tickets sur treize mois, les commandes des professionnels, les devis dans tous leurs états, les factures payées, en retard, relancées, les avoirs, les bons d'avoir, les achats, un inventaire d'ouverture, les charges dans tous leurs états (dont celles renvoyées par l'expert-comptable), les incidents de la plateforme et de l'envoi, une vente sans stock, une transmission de la propriété en attente vers l'administratrice.
   - **Modèle « Services, sans vente d'articles » : CONSEIL VASSEUR seule.**
     - Un magasin de services avec son assurance professionnelle ; administrateurs, responsable, vendeurs (Bruno Caron retiré, Anaïs Lambert retirée puis réintégrée), un observateur ; commandes des professionnels tous les trois jours, prestations autoliquidées dans l'Union et hors de l'Union, facture à une mairie (Chorus Pro), prélèvement ; expert-comptable déclaré par le SIREN de PETIT ET ASSOCIES, absent de PILIQI (Marc Petit, mot de passe choisi, accès redonné par un nouveau lien ; Sophie Petit, pas encore).
     - Réel normal mensuel au départ ; le visiteur peut passer en franchise, au trimestriel ou à l'option pour les débits (choix 31).
   - **Modèle « Cabinet d'expertise comptable » : MARTIN EXPERTISE COMPTABLE et ses clients.**
     - Sa propriétaire, Paul Lefèvre (MAISON FERRAND confiée) et Emma Rousseau (EPICERIE LEMOINE confiée) ;
     - ses invitations dans chaque état (ouverte, refusée, retirée, expirée, caduque) ; ses demandes de lien (en attente, refusée, retirée, expirée, terminée) ;
     - ses clients liés, avec leurs données (ce que le cabinet voit, selon ce qu'ils lui accordent) : MAISON FERRAND (liée), EPICERIE LEMOINE (née de l'invitation du cabinet), et BOULANGERIE DUVAL (lien terminé, pour l'historique), sans aucun compte de ces sociétés dans le bandeau ;
     - ni les cabinets non vérifié, suspendu ou radié, que le cabinet ne voit jamais : ils restent dans le semis du poste.
   - **Hors des modèles**, seulement dans le semis du poste :
     - les cas d'inscription (choix 28) : FLEURS DE SAISON et FROMAGERIE MORIN (attendent leur code), MENUISERIE PICARD (close faute de code), TRAITEUR GIRAUD (close, type choisi par erreur), la société close pour refus, MAROQUINERIE DELMAS (révoquée), VERGER DES COTEAUX (code saisi par une autre personne), la demande d'inscription jamais confirmée ;
     - les sociétés et les cas qu'aucun compte d'une copie ne peut ouvrir : COMPTOIR DES ILES (Lyon fermé temporairement, Bordeaux rouvert, Nantes cédé, Lille suspendu par l'éditeur, Pointe-à-Pitre, Saint-Denis de La Réunion, Ajaccio en Corse, mandat de la plateforme révoqué), GROUPE NORD DISTRIBUTION (assujetti unique), LIBRAIRIE DES QUAIS, ATELIER BRUN (franchise en base), STUDIO LUMIERE (option pour les débits, SIREN corrigé, régularisation), CABINET FAURE ET ASSOCIES et les cabinets non vérifié et suspendu.
     
     Le compte rendu les liste, avec la raison (« société d'un autre type ou qu'aucun compte de la copie n'ouvre »). Les cas qu'ils portent restent semés et vérifiés sur le poste (point 6 du protocole).
3. **Les comptes du bandeau**, écrits par le semis du modèle dans `comptes_du_bandeau` (choix 16) :
   - Commerce : propriétaire de MAISON FERRAND ; administratrice sans vente ; administrateur qui vend ; « Les Halles » : responsable, Inès Garcia (vendeuse), Sarah Petit (vendeuse, voit toute l'équipe), Yanis Morel (vendeur suspendu, grisé), observatrice, le compte de vendeur de Lucas Moreau ; « La République » : Lucas Moreau (responsable) ; « Fort-de-France » : responsable, vendeur ; « Le comptable » : Paul Lefèvre (expert-comptable déclaré).
   - Services : propriétaire de CONSEIL VASSEUR ; administrateurs ; responsable ; vendeurs (Anaïs Lambert comprise ; Bruno Caron, retiré, n'y est pas) ; observateur ; « Le comptable » : Marc Petit (expert-comptable déclaré).
   - Cabinet : propriétaire de MARTIN EXPERTISE COMPTABLE ; Paul Lefèvre ; Emma Rousseau. Aucun compte d'un client.
   - Le mot de passe de démonstration du modèle est celui de chacun de ces comptes (choix 29).
4. **Les e-mails des modèles.** Pendant les 7 derniers jours de l'histoire semée, le semis d'un modèle se sert du transport `demonstration` au lieu de `Mail::fake()` : la page « E-mails qui seraient partis » de chaque copie neuve montre de vrais exemples de sa société (envoi d'une facture, d'un devis, d'un avoir, d'un reçu de paiement, relance d'un professionnel, relance d'un particulier du magasin qui le coche, alerte de stock bas, lien du mot de passe de l'expert-comptable déclaré ; dans la copie « Cabinet » : invitation du cabinet, demande de lien, lien du mot de passe d'un collaborateur s'il y en a). Après « Recommencer à zéro », la page montre de nouveau ceux du modèle.
5. **Les identités et les numéros fictifs** (Q4).
   - Les SIREN du semis (`000000141`, `000000158`, `000000166`, `000000174`, `000000182`, `000000190`, `000000208`, `000000307`, `000000315`, `000000323`, `000000331`, `000000349`, `000000356`, `000000364`, `000000372`, `000000380`, `000000398`, `000000406`, `000000414`, `000000422`, `000000430`, `000000448`, `000000943` et ceux des sociétés qui n'ont pas de constante, relevés par le lot) et tous leurs SIRET sont vérifiés absents du fichier public du répertoire Sirene (stocks des unités légales et des établissements, téléchargés depuis data.gouv.fr ; rien n'est envoyé). Le compte rendu donne la date du fichier, la commande et le résultat.
   - La plage réservée des essais (A.6, C.11) : des SIRET de clé valide, sous les SIREN fictifs des modèles, vérifiés absents du même fichier.
   - Le faux répertoire Sirene, en mode démonstration, ne connaît que ces numéros (A.6).
   - Les adresses e-mail du semis sont toutes sur `exemple.test` et ses sous-domaines (`.test` est réservé : aucun e-mail n'y arrive). Un test du semis le vérifie pour chaque colonne qui porte une adresse.
   - Les numéros de téléphone du semis sont pris dans les plages que l'ARCEP réserve aux œuvres de fiction (plages à relire sur le site de l'ARCEP au lot) ; un test le vérifie.
   - Aucune donnée de la vraie société qui demande sa copie n'entre dans la copie : le message n'en porte aucune (liste fermée, C.7), et N18 le prouve.
6. **Ce qui sort du semis** (D.15, point 116) : le magasin de démonstration de la société « commerce » et de la société « inscription » ; `sEssayerDansLaDemonstration()` ; Romain Lefort, Karim Haddad et Zoé Marchand ; le libellé « Compte d'entraînement » de la liste des comptes.
7. **Les états des demandes sur le site réel** (semis du poste). Le semis passe par les vraies actions de la partie B, avec un faux site de démonstration (`SiteDeDemonstrationFactice`, derrière l'interface du transport des messages) qui répond l'état voulu et ne crée aucune copie. Chaque cas, de part et d'autre de sa limite quand il en a une, avec le compte qui le voit sur sa carte et dans le journal d'audit :
   - **MAISON FERRAND** (propriétaire) : copie prête ; lien renouvelé une fois il y a 3 jours, accusé reçu (« Ancien lien coupé le … ») ; ouverte par le bouton hier ; journal d'audit avec ces trois gestes. Son administratrice sans vente : aucune carte.
   - **CONSEIL VASSEUR** (propriétaire) : copie prête, remise à zéro hier (« Remise à zéro le … »).
   - **STUDIO LUMIERE** (propriétaire) : trois « Recommencer à zéro » aujourd'hui ; le quatrième est refusé avec son message. **ATELIER BRUN** (propriétaire) : deux aujourd'hui, le troisième encore permis.
   - **COMPTOIR DES ILES** (propriétaire) : cinq renouvellements aujourd'hui, le sixième refusé. MAISON FERRAND en a fait un : permis.
   - **Ouvertures** : COMPTOIR DES ILES a 30 ouvertures dans l'heure (la 31e refusée, avec son message) ; CONSEIL VASSEUR en a 29 (la 30e encore permise).
   - **Renouvellement sans accusé** : LIBRAIRIE DES QUAIS a un renouvellement dont l'accusé n'est pas venu depuis 20 minutes (le faux site répond `409`) : « La démonstration n'a pas encore reçu le renouvellement… » ; BOULANGERIE DUVAL, un renouvellement d'il y a 2 minutes : « Coupure de l'ancien lien en cours… ».
   - **La boîte d'envoi** : un message en reprise (`tentatives` = 2, `derniere_erreur` « La démonstration ne répond pas (délai dépassé) ») et un message envoyé avec son accusé ; ils se voient dans le compte rendu et dans un test, pas à l'écran (la boîte d'envoi n'a pas d'écran).
   - **ATELIER BRUN** (propriétaire) : renouvellement pendant la panne du serveur d'envoi ; l'e-mail n'est pas parti ; avis « Le lien de votre démonstration n'a pas pu partir par e-mail. Renouvelez-le depuis votre compte. »
   - **EPICERIE LEMOINE** (propriétaire) : copie terminée au 30e jour, il y a 2 jours ; carte « À créer » avec « Votre démonstration précédente s'est terminée le … » ; journal d'audit « Démonstration terminée (30 jours) ».
   - **LIBRAIRIE DES QUAIS** (propriétaire, société résiliée) : copie gardée, qui finit demain (« Supprimée le …, demain ») ; « Renouveler le lien » permis ; « Recommencer à zéro » et une nouvelle copie refusés (choix 12).
   - **BOULANGERIE DUVAL** (propriétaire, société suspendue) : copie prête, créée avant la suspension ; mêmes règles que la LIBRAIRIE.
   - **FLEURS DE SAISON** (propriétaire, attend son code) : copie prête, avant la validation.
   - **FROMAGERIE MORIN** (propriétaire, attend son code) : copie en préparation (« Votre démonstration se prépare »).
   - **GROUPE NORD DISTRIBUTION** (propriétaire, société validée) : copie en attente d'une place (plafond atteint).
   - **Le cabinet non vérifié** (propriétaire) : copie en échec, deuxième essai (« Votre démonstration n'a pas pu être créée… ») ; **TRAITEUR GIRAUD** n'entre pas (close) ; une société au troisième échec : carte revenue à « À créer » avec « Votre démonstration n'a pas pu être créée le … », journal d'audit « Démonstration terminée (échec) » (le lot nomme la société).
   - **MARTIN EXPERTISE COMPTABLE** (propriétaire) : copie prête, de type « Cabinet ». Paul Lefèvre et Emma Rousseau : aucune carte.
   - **CABINET FAURE ET ASSOCIES** (propriétaire) : jamais demandée, « À créer ».
   - **Le cabinet suspendu** (propriétaire) : « Créer ma démonstration » refusé, « Votre cabinet est suspendu : vous ne pouvez pas créer de démonstration. »
   - **VERGER DES COTEAUX** (propriétaire) : adresse de connexion pas encore confirmée, « Adresse à confirmer », bouton inactif, « Renvoyer le lien de confirmation ». Si le semis confirme cette adresse, une autre société au propriétaire non confirmé prend ce cas, et le compte rendu la nomme.
   - **Transmission de la propriété** : la société du semis dont la propriété a été transmise (LIBRAIRIE DES QUAIS) montre au nouveau propriétaire la phrase de B.9 (« La démonstration a été créée par l'ancien propriétaire… »).
   - **MAROQUINERIE DELMAS** (révoquée) : copie supprimée à la révocation ; journal d'audit « Démonstration terminée (révocation) » ; aucune carte.
   - **MENUISERIE PICARD** (close faute de code) : copie supprimée à la clôture ; journal d'audit.
   - **Limite des créations par adresse IP** : trois créations de la même adresse dans l'heure, la quatrième refusée (dans un test seulement : le semis ne porte pas d'adresse IP).
8. **Le registre semé du site de démonstration** (poste seulement). Après `demonstration:construire-les-modeles` et `demonstration:renvoyer-les-demandes`, la commande `demonstration:semer-les-etats` (refusée en production, comme le semis) pose dans le registre une copie de chaque état qu'aucune demande semée ne fait naître : `perdue`, `a_refaire`, `en_migration`, `en_lecture_seule` (au-delà de la place) et une copie juste sous la limite, `en_attente_d_une_place` côté démonstration, une copie avec le message « PILIQI a été mis à jour… », une avec « …a dû être refaite », une dont la dernière passe des tâches a plus d'une heure (page « Nous mettons votre démonstration à l'heure »), une archive. Chacune a son lien, écrit dans `storage/app/private/comptes-du-semis.txt` (fichier non suivi), pour que l'éditeur ouvre chaque page d'attente. `SemisSurLeSiteDeDemonstrationTest` vérifie qu'il n'en manque aucun.
9. **Ce qui ne se sème pas, avec sa raison**, écrit dans le compte rendu et dans `ETATS_SANS_DONNEE` du test du semis quand c'est un état : le refus d'un domaine réservé (production seulement), le témoin `__Host-` (production seulement), l'alerte de place disque (machine de production), la purge d'une archive de six ans.
10. **TVA27.** La société de chaque modèle qui vend a son régime de TVA et sa réponse au crédit de départ : aucune copie ne bloque ses ventes. MAISON FERRAND montre un crédit reporté.
11. **Les nombres.** `NOMBRES_DE_LIGNES` de `tests/Feature/SemisCompletTest.php` et les nombres du README se mettent à jour : les lignes du magasin de démonstration et des comptes d'entraînement partent ; les lignes du magasin de Fort-de-France, de `demonstrations`, `messages_vers_la_demonstration` et `site_de_la_base` (1) arrivent. `comptes_du_bandeau` et `courriels_de_la_demonstration` n'existent pas dans la base du poste (migrations du site de démonstration) : la règle « aucune table vide » du test du semis (`SemisCompletTest.php:214-219`) n'est pas touchée. Le compte rendu donne aussi, pour chaque modèle, ses nombres de sociétés, magasins, comptes, documents et e-mails, et sa taille.
12. **Le contrôle d'isolation** finit le semis du poste et chaque construction de modèle, à zéro problème.

### G. Les tests (tous sur PostgreSQL)

#### G.1 Les tests du domaine et des écrans (`tests/Feature/Demonstration/`)

- **N1 `SiteDePiliqiTest`** :
  - `reel` par défaut ; valeur inconnue : démarrage refusé, message français ;
  - mode démonstration : chaque pilote réel (Sirene, RNE, recommandé, Ordre, plateforme, VIES) refusé au démarrage, jamais remplacé en silence ; un antivirus autre que `clamav` ou `factice`, un transport autre que `demonstration`, chaque variable d'un vrai service, une clé de caisse épinglée au réel, une clé d'audit (courante ou précédente) dont l'empreinte n'est pas épinglée « démonstration » (même sous un identifiant de démonstration), la clé privée des messages, Octane, une base sentinelle qui a une table : refusés ;
  - mode réel : le transport `demonstration`, une clé de caisse épinglée à la démonstration, une clé d'audit dont l'empreinte est épinglée « démonstration », les clés publiques des messages, la connexion `registre`, une route `demonstration.*` (cache des routes construit en mode démonstration, puis démarrage en mode réel), un `SESSION_DOMAIN` qui couvre l'hôte de la démonstration : refusés ; en production, chaque faux fournisseur refusé, un témoin de session sans `__Host-` refusé, `trustHosts` fermé (l'hôte de la démonstration et un sous-domaine du réel répondent 400) ;
  - la garde actuelle des pilotes faux en production ne vaut qu'en mode réel : le site de démonstration démarre en production avec ses faux ;
  - la marque de la base : une base `demonstration` refusée par le réel, une base `reel` refusée par la démonstration ; la ligne de `site_de_la_base` ne se modifie pas ;
  - le pied de page dit le mode servi ; un serveur lancé comme le README le dit (`php artisan --env=demonstration serve`, avec `APP_ENV=demonstration` dans `.env.demonstration`) sert bien le mode démonstration, et pas le `.env` du réel (`ServeCommand` ne passe à son enfant que `APP_ENV` : `ServeCommand.php:82`, `:193-200`).
- **N2 `PortesFermeesTest`** : sur le site de démonstration, l'API répond introuvable ; la connexion, « Mot de passe oublié », l'inscription, `/code` mènent à `/choisir` avec un témoin, introuvables sans ; la confirmation d'adresse et la transmission de la propriété marchent avec le témoin (choix 27) ; sans témoin valable, chaque route de chaque panneau répond introuvable ; `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer`, `robots.txt` ; en mode démonstration, `migrate`, `migrate:fresh` et `db:wipe` refusés sur la base sentinelle et sur toute base hors du motif `^demo_(travail|copie)_`.
- **N3 `LienDeLaDemonstrationTest`** : 43 caractères base64url, 256 bits ; la base ne garde que l'empreinte ; un balayage ne trouve jamais le lien en clair dans : toutes les colonnes texte de la base réelle, les journaux d'audit, les notifications, `jobs`, `failed_jobs`, la table des jetons des demandes répétées (et la réponse gardée), `sessions.payload` décodé (base64 puis `unserialize`), les instantanés Livewire des réponses suivantes (décodés), `storage/logs` (transport `log`) ; la réponse de l'action qui le tire porte `Cache-Control: no-store` ; le travail de l'e-mail est chiffré ; après son dernier échec, aucune ligne dans `failed_jobs` et l'avis au propriétaire.
- **N4 `MessagesSignesTest`** : le message ne porte que la liste fermée ; accepté signé par la bonne clé ; refusés : signature fausse, autre clé, rejoué, émis il y a plus de 5 minutes, émis plus de 30 secondes dans le futur, autre destinataire, action inconnue, champ en plus, version du protocole trop vieille, référence hors de `^[0-9a-f]{32}$` (dont `../` et `;`), fin plus de 30 jours et 1 heure après la réception, génération inférieure ou égale à la connue, audience `entree` (un billet d'entrée envoyé à `/interne/messages`) ; `creer` répété : une seule copie ; `supprimer` répété : « fait » ; `etat` : ne change rien ; la copie du dossier passe par `Process` avec un tableau d'arguments.
- **N5 `BasesSepareesTest`** : en mode réel, aucune connexion vers une base `demo_*` ni vers le registre ; en mode démonstration, aucune vers la base réelle ; une requête qui partirait vers l'autre base fait échouer le test ; la base sentinelle ne rend aucune ligne à une route ajoutée hors du groupe `web` ; en production, `caisse:diagnostiquer` du réel rougit si une base `demo_*` existe sur son serveur ou si son rôle a `CREATEDB`.
- **N6 `QuiDemandeTest`** : seul le propriétaire voit la carte et fait chaque geste, dans une société de chaque type et dans un cabinet ; refusés, sans rien écrire et sans aucun message : administrateur (même de tous les magasins, autorisé à tout), responsable, vendeur, observateur, collaborateur, compte du cabinet lié, expert-comptable déclaré, propriétaire d'une autre société, compte suspendu ou retiré ; le type de la copie est celui de la société, sans choix ; aucun bouton « M'envoyer le lien par e-mail ».
- **N7 `AdresseConfirmeeTest`** : sans adresse de connexion confirmée, bouton inactif, phrase, « Renvoyer le lien de confirmation » ; action forgée refusée ; le lien ne part jamais à une adresse non confirmée ; une société non validée et sans magasin demande sa copie ; inscription close et société révoquée : copie archivée puis supprimée, plus de carte ; société suspendue ou résiliée : copie gardée, « Ouvrir » et « Renouveler » permis, « Créer » et « Recommencer » refusés (choix 12) ; un nouveau propriétaire par le code du courrier : copie supprimée ; une transmission de la propriété : copie gardée, phrase au nouveau propriétaire, et un renouvellement coupe l'ancien propriétaire.
- **N8 `EnvoiDuLienTest`** : l'e-mail part seulement à l'adresse de connexion confirmée, sans l'identifiant, avec la phrase exacte du choix 2 et le repère ; 5 renouvellements par jour, le 6e refusé ; en développement, le transport `log` écrit « (lien masqué dans le journal) ».
- **N9 `TrenteJoursTest`** : temps figé à un instant écrit avec son fuseau ; la copie vit à 30 jours moins une seconde, est terminée à 30 jours tout juste, au calendrier de Paris, à travers le passage à l'heure d'hiver et à l'heure d'été ; le site réel passe la ligne à `terminee` et inscrit l'audit ; la carte revient à « À créer » ; une nouvelle copie repart de zéro avec un nouveau lien ; la validation de la société ne touche pas la copie.
- **N10 `UneSeuleCopieTest`** : une seconde demande montre la copie en cours, sans second message ; même jeton après une réponse perdue : rien de plus, et la réponse gardée ne porte pas le lien ; index unique partiel en base ; une ligne `en_echec` n'empêche pas, après son troisième échec, une nouvelle demande. Groupe `concurrence` : deux demandes au même instant donnent une seule copie.
- **N11 `JournalDAuditDeLaDemonstrationTest`** : chaque geste (créer, ouvrir par le bouton, renouveler, recommencer, fin à 30 jours, fin par clôture, révocation, nouveau propriétaire ou échec) s'inscrit, catégorie « gestion des accès » ; jamais le lien, son empreinte ni la référence.
- **N12 `DemandesDeLaSocieteTest`** : `BelongsToSociete` ; le propriétaire d'une autre société ne voit ni ne touche la demande ; `ReglesDIsolation` déclare les deux tables ; `app:verifier-isolation` les contrôle par SQL et par Laravel, et un test par couche prouve qu'il trouve une faute (une demande dont le compte est d'une autre société ; un message dont la demande est d'une autre société) ; les commandes et le travail du site réel (B.8) lisent avec un filtre explicite et n'écrivent qu'au journal d'audit de la société de la ligne (deux sociétés, deux journaux, aucun mélange).
- **N13 `RenouvellementTest`** : nouvelle empreinte, génération suivante ; l'ancien lien répond introuvable ; **toutes** les visites sont coupées, celle du bouton comprise (choix 10) ; la copie et ses données restent ; la date de fin ne change pas ; la carte dit « Coupure de l'ancien lien en cours… » tant que l'accusé `200` n'est pas venu, et jamais « coupé » quand le site de démonstration répond `409` ; sans accusé en 15 minutes, l'alerte à l'éditeur et la phrase au propriétaire ; un renouvellement plus ancien que la génération connue est refusé. Et la séparation : une vente dans la copie ne fait pas « vendre une fois » la vraie société, ne verrouille pas son mois de fin d'exercice, ne touche aucune série, aucun registre ni aucun journal du site réel ; un réglage changé sur le site réel ne touche pas la copie.
- **N14 `RecommencerTest`** : archive faite avant ; copie refaite depuis le modèle du jour, même lien, même fin (choix 11) ; une vente faite avant a disparu ; la page des e-mails montre de nouveau ceux du modèle ; le « compte choisi en dernier » des témoins est effacé (la visite suivante ouvre `/choisir`) ; une visite ouverte pendant ce temps voit « Votre démonstration se prépare », jamais une erreur ; 3 par jour, le 4e refusé.
- **N15 `BasesModelesTest`** (groupe `copies`, vrais modèles) : le modèle « Commerce » ne contient que MAISON FERRAND, le modèle « Services » que CONSEIL VASSEUR, le modèle « Cabinet » que MARTIN et ses clients (F.2) ; commerce : produits, fournisseurs, achats ; services : aucun ; cabinet : aucun magasin du cabinet ; dans les deux modèles de sociétés, l'expert-comptable déclaré a un compte actif et aucun cabinet n'existe ; MAISON FERRAND a un magasin à Fort-de-France (8,5 %, heure des Antilles) ; changer le régime de la société dans une copie en franchise donne, à la vente suivante, la mention « TVA non applicable, art. 293 B du CGI » et la catégorie E de l'aperçu (choix 31) ; chaque service semé a un prix de référence, sauf le cas voulu « sans prix de référence » ; chaque compte du bandeau a ses propres données ; les dates sont celles du jour (retards, devis qui expirent, clôture de la veille) ; tables techniques vides ; chaque société qui vend a son régime de TVA ; chaque magasin a son JET 100 ; chaque cas semé de la société mise en avant est dans son modèle ; les cas hors des modèles sont exactement la liste nommée de F.2.
- **N16 `ConstructionDesModelesTest`** : la construction n'existe qu'en mode démonstration, à 01 h 10 (heure de Paris), sur un seul serveur, par `demo_admin` ; la bascule n'a lieu qu'avec l'isolation à zéro et `caisse:verifier` au code 0 ; un problème garde l'ancien modèle et paraît dans l'état publié ; aucune tâche planifiée ne part pendant la construction ; une copie neuve a le fuseau UTC, les mêmes `pg_db_role_setting` et les mêmes droits de `demo_app` que la base de travail ; le nettoyage ne supprime que le motif exact ; il refuse d'agir quand le registre est vide alors que des copies existent, et quand plus de 10 % des copies seraient supprimées.
- **N17 `CopieParModeleTest`** : `CREATE DATABASE … TEMPLATE` du modèle de son type ; nom tiré au hasard, sans rien de la société ; le modèle est intact ; un modèle occupé ou une création qui échoue ne laisse aucune base à moitié faite, réessaie, paraît dans l'état publié, et la carte dit « en échec », puis, au troisième échec, revient à « À créer » ; plafond atteint : « en attente d'une place », puis prête quand une place se libère ; la part réservée aux sociétés validées : une société non validée attend quand les places des non validées sont prises, une validée passe ; une copie au-delà de 3 fois la taille de son modèle passe en lecture seule avec son message, une copie juste sous la limite non ; le quota couvre tout le dossier (un ZIP préparé compte) ; au-delà de 50 témoins, le plus ancien est retiré ; `DROP DATABASE` à la suppression : la base n'existe plus et le témoin ne mène nulle part.
- **N18 `IdentiteFictiveTest`** : on copie pour une société « MARTIN ET FILS » (SIREN, adresse e-mail et siège connus) ; un balayage de toutes les colonnes texte de la copie, de ses fichiers, de ses e-mails capturés et de son archive ne trouve ni ce nom, ni ce SIREN, ni cette adresse, ni ce siège ; chaque PDF de la copie (ticket, facture, avoir, devis, reçu, clôture, état des charges), l'état des charges en Excel, le CSV et le ZIP portent « Démonstration — sans valeur » et l'identité fictive ; en mode réel, jamais ce marquage ; dans la copie, le faux répertoire Sirene répond « inconnu » au SIRET d'une vraie entreprise et accepte un SIRET de la plage réservée.
- **N19 `ClesDeLaDemonstrationTest`** : la copie signe avec la clé de démonstration ; la clé `demonstration` est à vrai dans l'en-tête signé et l'émetteur figé d'une copie, absente au réel (les fixtures du contenu signé réel, `tests/Fixtures/contenu-signe/*.json`, ne changent pas) ; un document signé dans une copie garde son filigrane quel que soit l'émetteur courant ; le vérificateur du réel signale un enregistrement marqué `demonstration`, un émetteur figé marqué `demonstration`, un JET 100 ou 105 ; `caisse:verifier` d'une copie passe avec la clé publique de démonstration et échoue avec celle du réel ; un document signé par la démonstration ne se vérifie pas au réel ; la vérification de nuit d'une copie visitée trouve une chaîne cassée et n'en fait qu'une ligne de l'état publié ; `ModuleFiscal::VERSION` vaut `3.0`.
- **N20 `BandeauVousEtesTest`** :
  - le bandeau est en haut de chaque page des espaces de la copie, jamais au réel ; il montre le repère, la fin, la version et le mot de passe de démonstration ;
  - son menu suit le choix 16 : seulement les comptes de la société mise en avant ; dans la copie « Cabinet », jamais un compte d'un client ;
  - un clic change de compte sans mot de passe et ouvre l'accueil de ce compte ; le registre de la copie porte le JET 40 puis le JET 80, et la vente suivante a pour opérateur le nouveau compte ;
  - refusés : identifiant forgé, identifiant d'un compte d'une autre société de la même base (un client, dans la copie « Cabinet »), compte retiré, compte suspendu (message de la connexion), sans témoin, témoin d'une autre copie ; la route n'existe pas au réel et la classe y refuse ;
  - un compte créé dans la copie (membre, administrateur, expert-comptable déclaré, collaborateur) paraît dans le menu, à la fin de son groupe ;
  - les règles tiennent : le propriétaire ne vend pas (bouton absent, action refusée, base qui refuse), un vendeur ne voit que ses factures, un observateur ne vend pas, l'expert-comptable déclaré ne voit que ce que la société lui accorde, un collaborateur ne voit que les clients qui lui sont confiés, les graphiques ne se montrent qu'aux rôles permis ;
  - un geste qui redemande le mot de passe passe avec le mot de passe de démonstration et refuse un faux (fermer un magasin, par exemple) ;
  - « Bonjour » dit le rôle choisi suivi de « (démonstration) » ; le changement s'inscrit au journal d'audit de la société fictive ;
  - en mode démonstration, ni adresse IP ni navigateur ne s'enregistrent.
- **N21 `EntreeParLeLienTest`** :
  - première visite : témoin du choix 32 (`HttpOnly`, `SameSite=Lax`, `Path=/`, sans `Domain` ; `Secure` et `__Host-` en production), qui ne dure pas plus que la copie ; redirection vers `/choisir` ;
  - la page `/d` porte sa propre politique de contenu stricte (`script-src 'self'`, ni `unsafe-inline` ni `unsafe-eval`) et charge un fichier du site ; le reste du site garde la politique commune ;
  - le secret n'est jamais dans une adresse envoyée au serveur ni dans un journal ;
  - avec un témoin valable d'une autre copie, `/d` demande la confirmation ;
  - lien faux, ancien lien, lien d'une copie supprimée : même page, même code, même délai ;
  - 10 essais par minute et par adresse IP, le 11e répond 429, sans erreur, sans copie ouverte ; `Origin` exigé ;
  - le billet du bouton : accepté une fois, refusé expiré, rejoué, contrefait, d'audience `messages` (un message `creer` envoyé à `/entrer-par-le-compte`), et envoyé depuis une page d'une autre origine ;
  - deux témoins de deux copies, deux visites, chacune ne voit que ses données ; deux requêtes de deux copies à la suite, dans le même processus PHP, ne se mélangent pas ;
  - « Quitter » : l'ancienne valeur du témoin rejouée répond introuvable.
- **N22 `CourrielsQuiSeraientPartisTest`** : aucun transport réseau ; chaque e-mail que le vrai logiciel aurait envoyé paraît sur la page (document, relance d'un professionnel, relance d'un particulier quand le magasin le coche, reçu, lien du mot de passe d'un expert-comptable déclaré, alerte de stock, notification par e-mail ; dans la copie « Cabinet » : invitation, demande de rattachement), avec le destinataire, l'objet, le texte et les pièces jointes marquées ; les limites d'envoi du réel décident ; le cadre a exactement `sandbox="allow-popups allow-popups-to-escape-sandbox"`, sans `allow-scripts` ni `allow-same-origin`, et le HTML porte `<base target="_blank">` ; les pièces se téléchargent en pièce jointe ; tout compte du bandeau voit les e-mails de sa société, jamais ceux d'une autre société de la même base (dans la copie « Cabinet », jamais ceux de MAISON FERRAND), jamais ceux d'une autre copie ; la table n'existe pas dans une base réelle ; SUPER PDP, VIES et le recommandé sont les faux.
- **N25 `MagasinInterieurRetireTest`** : plus de colonne `magasins.est_demonstration`, de déclencheur `magasins_est_demonstration_figee`, de motif `FinDemonstration`, de commande `app:fermer-demonstrations-inactives`, d'action `ouvrirLaDemonstration`, de `CatalogueDeDemonstration` ; le propriétaire n'a aucune autorisation de vente dans aucun magasin ; `GardesDeLaVente` et `ControlesDeLaBase` n'ont plus de clause de démonstration, et le message de la garde nomme le propriétaire, l'expert-comptable de la société et les comptes d'un cabinet ; aucun enregistrement du registre du semis du poste ne porte un JET 100 ou 105 ; `DEMO` est un code de magasin libre ; `magasins.siret` est obligatoire ; un brouillon créé par un administrateur devenu propriétaire devient, à l'émission, la vente de qui l'émet.
- **N26 `MisesAJourDesCopiesTest`** : avant la mise en production, `demonstration:refaire-les-copies` archive puis refait chaque copie depuis le nouveau modèle, garde la référence, l'empreinte du lien et la fin, efface le « compte choisi en dernier » et montre une fois le message ; `demonstration:tout-effacer` vide le registre et les archives, garde les modèles, et refuse en production. Après : avec un dossier de migrations de test, `demonstration:migrer-les-copies` archive par l'ancien code, migre chaque copie (sa table des migrations égale celle du modèle), efface l'archive d'une migration réussie, refait celle dont la migration échoue, avec son message, sans toucher les autres, et écrit le tableau dans l'état publié ; une copie pas encore à jour n'est pas servie (« Mise à jour en cours »), jamais d'erreur 500 ; un message d'une version de protocole précédente est accepté, d'une plus vieille refusé ; `demonstration:renvoyer-les-demandes` recrée les copies perdues, sans doublon.
- **N27 `UnSeulTypeTest`** (choix 30) : pour chaque type, il balaie la carte dans chacun de ses états et ses fenêtres, l'e-mail du lien, `/choisir`, le bandeau et son menu ouvert, les pages d'attente et la page des e-mails ; il n'y trouve ni le nom d'un autre type (« Commerce ou atelier », « Services, sans vente d'articles », « Cabinet d'expertise comptable », selon le cas), ni le nom d'une société d'un autre type, ni une phrase sur une autre démonstration.
- **N28 `ArchiveAvantSuppressionTest`** : à 30 jours, à « Recommencer à zéro », à une copie refaite, à la clôture et à la révocation de la société, au nettoyage d'une copie échue : journées clôturées, JET 105 dans chaque magasin, `caisse:verifier` au code 0, archive écrite (registre, journal des événements, tickets Z, totaux, sommaire signé avec la clé de démonstration), relue, puis seulement `DROP DATABASE` ; une archive qui échoue arrête la suppression ; l'archive ne contient aucune donnée de la vraie société ; `demonstration:purger-les-archives` efface une archive de plus de six ans et pas celle d'avant.
- **N29 `CachesEtVerrousParCopieTest`** : la même clé de limite (`RateLimiter`) dans deux copies est indépendante ; un travail `ShouldBeUnique` de la copie A ne bloque pas le même travail de la copie B ; `/entrer` limité par le cache `registre`, sans erreur ; un travailleur et le planificateur démarrent sans copie ; un test échoue si un disque local de `config/filesystems.php` n'est pas réglé par `OuvrirLaCopie` (un ZIP préparé dans la copie A est sous le dossier de A et disparaît avec elle) ; après une requête de la copie A, le cache, la session et le limiteur résolus pour A ne servent pas à la requête suivante de B.
- **N30 `RolesPostgresDeLaDemonstrationTest`** : `demo_app` ne peut ni `CREATE DATABASE` ni `DROP DATABASE`, ni écrire dans le registre hors des témoins, des messages reçus et des travaux ; `demo_admin` crée, copie et supprime (avec `WITH (FORCE)` grâce à `pg_signal_backend`) ; un travail de 30 minutes dans une copie ne retarde pas `CreerUneCopie` (deux files, deux travailleurs).
- **N31 `EtatPublieTest`** : `GET /interne/etat` est signé avec la clé d'état ; il ne porte aucune référence ni aucune donnée d'une copie ; le site réel le lit, contrôle la signature et lève une `AlerteEditeur` pour une construction ratée, un modèle à l'isolation non nulle, une copie en échec, un nettoyage refusé, une archive ratée, un disque sous 20 % ; un état mal signé ou vieux de plus de 2 heures est une alerte ; aucune requête ne part du site de démonstration.
- **N32 `BilletEtPolitiqueDeContenuTest`** : la page du billet sur le site réel porte `form-action 'self' <origine exacte du site de démonstration>`, `Referrer-Policy: no-referrer` et `Cache-Control: no-store` ; aucune autre page du site réel n'a `form-action` élargi ; le formulaire part par le fichier `/js/envoyer-le-billet.js`, sans script écrit dans la page.
- **`TachesDesSitesTest`** : chaque tâche de `bootstrap/app.php` est rangée (C.13) ; une tâche non rangée fait échouer le test ; chaque tâche quotidienne est hors de 2 h–3 h ; `PlanificateurTest` (E74) suit.
- **`FilesDesCopiesTest`** : un travail envoyé depuis la copie A s'exécute dans A, même juste après un travail de B ; un travail d'une copie supprimée, terminée ou en migration est abandonné sans rien écrire ; `after_commit` : un travail envoyé dans une transaction annulée ne part pas ; une copie de 20 jours jamais visitée rattrape ses journées dans l'ordre (un ticket Z par jour), puis s'ouvre ; la vérification de nuit ne passe que sur les copies visitées depuis sa dernière passe ; la suppression d'une copie efface ses lignes de `jobs` et `failed_jobs` ; `failed_jobs` se purge à 30 jours.
- **`SemisSurLeSiteDeDemonstrationTest`** (A.9, F.8) : en mode réel, en production, chaque chemin du semis est refusé ; en mode démonstration, refusé sur toute base dont le nom ne commence pas par `demo_travail_`, et refusé à `db:seed` lancé à la main ; permis à `demonstration:construire-les-modeles` seulement ; `demonstration:semer-les-etats` pose chaque état du registre de F.8 et refuse en production.
- **`DomainesReservesTest`** (B.15) : en production, sur le site réel, chaque saisie d'une adresse de connexion, d'une adresse professionnelle d'expert-comptable et d'une invitation sur un domaine réservé est refusée avec son message ; en développement et en test, le semis les accepte.
- **Les tests réécrits de D.16** (E2, E3, E6, E7, E9 à E14, E17, E20, E47, E50 à E52, E55, E57 à E60, E62 à E65, E67 à E69, E71, E74, E76, E77, E79, E83, E85 à E90, E93, E95, E97, E101 à E105, E108, E109, E111, E112, E114, E115, E118) et les tests gardés retouchés (E61, E66, E75, E78, E80, E91, E92, E94, E110).
- **`SemisCompletTest`** : les états des demandes de F.7, avec le compte qui les voit, de part et d'autre de chaque limite ; plus aucun compte d'entraînement ni magasin de démonstration ; le magasin de Fort-de-France de MAISON FERRAND ; les adresses sur `exemple.test` et les téléphones dans les plages de fiction (F.5) ; `ETATS_SANS_DONNEE` avec les raisons de F.9.
- **Groupe `concurrence`** (`DB_PASSWORD="$PILIQI_PG_MOT_DE_PASSE" php artisan test --parallel --group=concurrence`) : deux demandes au même instant (une copie) ; deux copies créées en même temps depuis le même modèle ; une copie demandée pendant la construction de nuit (le modèle en service sert, la bascule attend la fin de la copie) ; un renouvellement pendant une entrée par l'ancien lien (l'entrée est refusée ou la visite coupée, jamais ouverte après le renouvellement) ; « Recommencer à zéro » pendant une visite ; la suppression à 30 jours pendant une requête ; deux visites de deux copies en parallèle ne voient jamais les lignes l'une de l'autre ; la migration d'une copie pendant une visite (page « Mise à jour en cours ») ; le même travail unique dans deux copies en même temps (les deux passent) ; la mesure de la taille pendant un dépôt de pièce.

#### G.2 L'outillage des tests (L6, L7)

- **Les rôles PostgreSQL de test** : `demo_admin` (`CREATEDB`, membre de `pg_signal_backend`) et `demo_app`, créés par la préparation de la CI et, sur le poste, par les deux commandes du README (I.10). Le rôle des tests ordinaires n'a pas `CREATEDB`.
- **`CREATE DATABASE` et `DROP DATABASE` hors transaction** : ils ne peuvent pas tourner dans la transaction de `RefreshDatabase`. Ils passent par une connexion à part (`demo_admin_tests`), ouverte hors de toute transaction.
- **Un modèle de test minuscule.** Le semis complet prend 11 à 13 minutes (README l. 76) : construire un vrai modèle pour chaque test durerait des heures. Une petite classe à part, `SemisDuModeleDeTest`, sème par les vraies actions une société par type, un magasin (deux pour la société « Commerce », pour le bandeau par magasin), les rôles du bandeau, quelques documents et un e-mail capturé. Ce modèle de test est construit une fois par processus et par empreinte du schéma, gardé comme modèle (`IS_TEMPLATE`) pour tous les tests du processus, et supprimé à la fin. Seuls N15 et N16 construisent les vrais modèles, dans le groupe `copies` (hors de la suite par défaut, une tâche de la CI à lui, lancé par chaque lot avant son dernier commit). Les durées mesurées s'écrivent dans le compte rendu.
- **Un nom de base par processus** (`--parallel`) : `demo_copie_` suivi de 26 caractères dont le jeton du processus, pour respecter le motif ; chaque base créée par un test est supprimée à la fin du test, même après un échec (`tearDown` et `register_shutdown_function`), et une commande `demonstration:nettoyer --tests` efface les restes.
- **Le registre de test** : une base `piliqi_test_registre` par processus, migrée par `database/migrations-registre/`.
- **`DemonstrationTestCase`** : refait l'application avec `PILIQI_SITE=demonstration` (`putenv`, `$_ENV` et `$_SERVER` avant `createApplication`), les fournisseurs faux, le transport `demonstration`, les clés de test de démonstration (tirées par le test, jamais dans le dépôt hors des clés publiques et des empreintes de test épinglées dans `tests/Fixtures`). Au `tearDown`, il remet `PILIQI_SITE` et chaque variable changée dans `putenv`, `$_ENV` et `$_SERVER` : avec `--parallel`, le test suivant du même processus démarre en mode réel. Un test le prouve (un test ordinaire lancé après un test de démonstration est en mode réel).
- **`CopieDeTest`** (remplace `DemonstrationDeTest`) : crée une copie du modèle de test d'un type, rend son lien, et sait y entrer.
- **Le navigateur** : un second serveur de test en mode démonstration (même code, un fichier `.env.<nom>` dont `APP_ENV` porte le même nom, puisque `serve` ne passe à son enfant que `APP_ENV` (I.10) ; le mode vient de `PILIQI_SITE`, jamais d'`APP_ENV`, A.1 ; le lot vérifie que rien ne lit `environment('testing')` sur ce chemin), lancé par `TestCaseDuNavigateur` à côté du premier, sous l'autre nom d'hôte (`localhost` pour la démonstration, `127.0.0.1` pour le réel, choix 32).

#### G.3 Les tests en navigateur

- **N23 `tests/Browser/Demonstration/MaDemonstrationTest.php`** (réécrit de E47) : le propriétaire d'une société de chaque type, et celui d'un cabinet : « Créer ma démonstration » (fenêtre, lien affiché une fois, « Copier le lien ») ; « Ouvrir ma démonstration » (le formulaire part vraiment vers l'autre site, malgré la politique de contenu ; nouvel onglet ; la copie s'ouvre) ; « Renouveler le lien » (fenêtre, mot de passe, nouveau lien affiché, « Coupure de l'ancien lien en cours… » puis « Ancien lien coupé le … ») ; « Recommencer à zéro » (fenêtre de confirmation) ; la date de fin et le repère ; sans adresse confirmée : « Adresse à confirmer », bouton inactif, « Renvoyer le lien de confirmation » ; l'administrateur ne voit pas la carte ; la liste d'étapes (« Ouvrir ma démonstration ») ; se connecter au réel, ouvrir la démonstration, revenir au réel encore connecté (les témoins ne se mêlent pas) ; les deux modes, clair et sombre.
- **N24 `tests/Browser/Demonstration/DansLaCopieTest.php`** : entrer par le lien (l'adresse se réécrit sans le secret) ; « Choisissez qui vous êtes » ; lire le bandeau ; passer au vendeur ; vendre à la caisse ; revenir au propriétaire et voir la vente « à surveiller » ; passer à la responsable, envoyer une facture et la lire dans « E-mails qui seraient partis » (cadre, pièce jointe) ; ouvrir le lien de réponse au devis d'un e-mail capturé (nouvel onglet, `target="_blank"`) et accepter le devis ; fermer un magasin avec le mot de passe de démonstration ; passer à l'expert-comptable déclaré et valider une charge ; « Quitter ».
- **N24 bis `tests/Browser/Demonstration/BalayageDesCopiesTest.php`** (remplace E50, E51, E52) : chaque page et chaque action de chaque espace de chaque copie (trois types), pour chaque compte du bandeau, sans erreur ; les gestes qui redemandent le mot de passe reçoivent le mot de passe de démonstration.
- **`tests/Browser/Parcours/InventaireDOuvertureTest.php`** (L1) : dans un vrai magasin qui n'a pas encore vendu, le propriétaire puis un responsable ouvrent « Nouvel inventaire d'ouverture ».
- **E118 réécrit** dans `UnProduitUneLigneTest.php` (corbeille dans un vrai magasin).
- **E115 réécrit** dans `BalayageDeLEspaceDeLaSocieteTest.php` (la carte et ses actions).
- **`tests/Browser/Balayage/ContrasteDesDeuxModesTest.php`** : la carte « Ma démonstration » dans chacun de ses états, ses fenêtres ; dans la copie : le bandeau et son menu ouverts, « Choisissez qui vous êtes », la confirmation de `/d`, la page des e-mails et sa fiche, les pages d'attente ; en clair et en sombre.
- **`tests/Browser/InventaireDesActionsTest.php`** : chaque action nouvelle citée par son test, et les citations de D.16 (L1) à jour.
- **`tests/Browser/Parcours/DonneesSemeesTest.php`** (E92, renommé).
### H. Les textes

#### H.1 CLAUDE.md, phrase par phrase (relevé 1)

Les phrases supprimées et les retraits partent au lot DEMO-COPIE-1 ; les phrases qui nomment la copie arrivent au lot DEMO-COPIE-3 (celles qui décrivent la demande et le site, au lot DEMO-COPIE-2, avec leur code). Chaque point garde son numéro du relevé.

**Protocole de rigueur, point 6**
- C1. Titre : « Données de démonstration complètes (…) » → « Données semées complètes (décision de l'éditeur du 15/11/2026, prioritaire et permanente) : `php artisan migrate:fresh --seed` remplit… ». Phrase ajoutée à la fin du point : « Le même semis, réparti selon les trois types d'activité, remplit chaque nuit les trois bases modèles du site de démonstration (voir « Copie de démonstration ») : chaque nouveauté semée paraît d'elle-même dans les démonstrations. »
- C2. « la vérification de l'isolation de toute la base (sociétés, magasins, comptes, cabinet, comptables, démonstration) » → « la vérification de l'isolation de toute la base (sociétés, magasins, comptes, cabinet, comptables), par Laravel et par SQL (`php artisan app:verifier-isolation`), qui doit trouver zéro problème. Le même contrôle passe sur chaque base modèle après sa reconstruction : une base modèle qui a un problème n'est pas mise en service. Ce contrôle tourne aussi chaque nuit… » (la suite inchangée).

**Puce « Comptes et équipe »**
- C3. « essayer le magasin de démonstration » → « ouvrir sa démonstration ».
- C4. « sauf celui de vendre, qu'il n'a dans aucun magasin réel » → « sauf celui de vendre, qu'il n'a dans aucun magasin ».
- C5. La phrase « dans le magasin de démonstration de sa société, tant qu'il est ouvert, il a toutes les autorisations qui existent (…), seule exception à « le propriétaire ne vend jamais » (décision de l'éditeur du 12/11/2026) » est supprimée. À la place : « il s'entraîne à tout dans la copie de démonstration de sa société, où il passe d'un compte à l'autre (voir « Copie de démonstration ») ; aucune exception ne lui permet de vendre ».
- C6. « le propriétaire toutes, y compris celles de vente, qu'il n'exerce que dans le magasin de démonstration » → « le propriétaire toutes, y compris celles de vente, qu'il n'exerce jamais lui-même ».
- C7. « un compte d'entraînement et un compte d'expert-comptable de la société ne changent jamais de rôle de société » → « un compte d'expert-comptable de la société ne change jamais de rôle de société ».
- C8. La phrase des comptes d'entraînement (« dans le magasin de démonstration, le propriétaire et un responsable de ce magasin créent des comptes d'entraînement (…) au refus de la société ») est supprimée. « Les comptes d'un magasin cédé ou fermé définitivement sont gardés » reste.
- C9. « jamais le propriétaire hors du magasin de démonstration, ni un expert-comptable de la société » → « jamais le propriétaire ni un expert-comptable de la société » ; et « la base refuse une vente ou un encaissement à son nom dans tout autre magasin » → « la base refuse toute vente et tout encaissement à son nom ».

**Puce « Société et établissements »**
- C10. « Une société sans magasin réel ne vend rien et n'est jamais facturée (étape 12) » → « Une société sans magasin ne vend rien et n'est jamais facturée (étape 12) ».
- C11. « le magasin de démonstration lui reste disponible dans les mêmes conditions, sauf à un cabinet d'expertise comptable, qui n'a aucun magasin » → « sa copie de démonstration lui reste disponible, comme à toute société, cabinet d'expertise comptable compris ».
- C12. « le magasin de démonstration restant utilisable » (assujetti unique) → « sa copie de démonstration restant utilisable ».

**Puce « Vérification de la société »**
- C13. « et s'essaie dans un magasin de démonstration : identité fictive, territoire de son siège (…), jamais facturé » → « et s'essaie dans sa copie de démonstration (voir « Copie de démonstration ») : rien de la société n'y entre, rien n'en sort, et la validation ne la change pas ».
- C14. « il se ferme à la validation et rien n'en passe aux magasins réels » : supprimée.
- C15. La phrase de l'archive finale du magasin de démonstration (« à sa fermeture définitive (validation ou refus de la société), il reçoit (…) (décision de l'éditeur du 14/11/2026) ») : supprimée.
- C16. La phrase T115 (« une fois la société validée, il disparaît de tous les écrans (…) (décision de Claude, T115) ») : supprimée ; T115 « remplacée » dans la matrice et dans l'onglet « À faire vérifier par un professionnel ».
- C17. « un cabinet d'expertise comptable, qui n'a ni magasin ni démonstration, y configure son identité et ses collaborateurs » → « un cabinet d'expertise comptable, qui n'a pas de magasin, y configure son identité et ses collaborateurs et s'essaie dans la copie de démonstration de son type ».
- C18. « avec une implémentation factice pour les tests et la démonstration » → « avec une implémentation factice pour les tests, le semis et le site de démonstration, qui n'en a jamais d'autre, hors l'antivirus ClamAV, local ».

**Puce « Type d'activité »**
- C19. « (sans magasin ni démonstration, code d'activité 69.20Z contrôlé avant tout courrier » → « (sans magasin, code d'activité 69.20Z contrôlé avant tout courrier ».

**Puce « Cabinets d'expertise comptable »**
- C20. « (fournisseur externe, derrière une interface, avec une implémentation factice pour les tests et la démonstration, sans clé dans le dépôt) » → « (…, avec une implémentation factice pour les tests, le semis et le site de démonstration, sans clé dans le dépôt) ».
- C21. « Un cabinet n'a ni magasin, ni magasin de démonstration, ni administrateur, ni membre » → « Un cabinet n'a ni magasin, ni administrateur, ni membre ».
- C22. « n'ouvre jamais l'espace d'administration de la société ni le magasin de démonstration » → « n'ouvre jamais l'espace d'administration de la société ».

**Puce « Prix et TVA des ventes »**
- C23. « (le propriétaire, qui ne vend que dans le magasin de démonstration, pour le prix de référence d'un service et les lignes d'un devis, et, dans ce seul magasin, pour les lignes d'un ticket ou d'une facture) » → « (le propriétaire, qui ne vend pas, pour le prix de référence d'un service et les lignes d'un devis) ».

**Puce « Vendeurs »**
- C24. « jamais par le propriétaire, sauf dans le magasin de démonstration » → « jamais par le propriétaire ».

**Puce « Graphiques du tableau de bord »**
- C25. « jamais à un vendeur, un observateur ni un compte d'entraînement, et jamais pour le magasin de démonstration » → « jamais à un vendeur ni à un observateur ».

**Puce « Achats et stock »**
- C26. « (les responsables du magasin le sont toujours ; le propriétaire ne l'est que dans le magasin de démonstration, le seul où il vend ; pour les autres, …) » → « (les responsables du magasin le sont toujours ; le propriétaire, qui ne vend pas, ne l'est jamais ; pour les autres, …) ».

**Puce « Charges et TVA »**
- C27. « jamais un cabinet ni le magasin de démonstration » → « jamais un cabinet ».
- C28. « les vendeurs, les observateurs et les comptes d'entraînement n'ont rien » → « les vendeurs et les observateurs n'ont rien ».

**Puce « Caisse et clôtures »**
- C29. « Le magasin de démonstration est un mode d'entraînement distinct (registre et séries propres, documents marqués, exclu des totaux réels, entrée et sortie inscrites au journal des événements), décrit dans la notice et l'attestation » → « Le mode d'entraînement de la caisse n'existe que sur le site de démonstration (voir « Copie de démonstration ») : une base par copie, ses propres clés de signature, jamais celle de la caisse réelle, des documents marqués « Démonstration — sans valeur », le JET 100 à l'entrée et le JET 105 à la sortie ; il n'entre dans aucun registre, aucune clôture, aucun total ni aucune déclaration du site réel, qui n'écrit jamais de JET 100 ni 105. Avant la suppression d'une copie, l'archive signée de sa caisse est gardée six ans hors de toute base (BOFiP BOI-TVA-DECLA-30-10-30 § 50, 130, 155 et 180). La notice et l'attestation décrivent ce mode du logiciel : actif seulement sur l'instance de démonstration de l'éditeur, interdit sur l'instance réelle (marque de la base, clés épinglées séparées), identité fictive, clés propres, documents marqués, archive avant suppression. » (choix 19).
- C30. La seconde phrase de l'archive finale (« à sa fermeture définitive, il reçoit une archive finale signée, que seul le propriétaire télécharge (…) (décision de l'éditeur du 14/11/2026) ») : supprimée.

**Feuille de route**
- C31. Étape 1 : « vérification de la société et magasin de démonstration » → « vérification de la société, copie de démonstration de chaque société sur un site séparé (lot DEMO-COPIE) ».
- C32. Étape 1 : « propriétaire qui supervise sans vendre dans les magasins réels » → « propriétaire qui supervise sans vendre ».
- C33. Étape 1 bis : « par société 1 propriétaire, qui ne vend pas dans les magasins réels » → « par société 1 propriétaire, qui ne vend pas ».
- C34. Étape 1 bis : « des comptes d'entraînement dans le magasin de démonstration » → « une copie de démonstration de chaque type, demandée, renouvelée, remise à zéro et supprimée, avec le passage d'un compte à l'autre ; des copies demandées en même temps (concurrence de CREATE DATABASE … TEMPLATE) ; le nombre de copies que la machine tient ».
- C35. Étape 3 : « Le mode démonstration (JET 100 et 105) figure dans la notice et le périmètre de l'attestation » → « Le mode d'entraînement du site de démonstration (copies séparées, clés de signature propres, documents marqués, JET 100 et 105, archive avant suppression) figure dans la notice et dans le périmètre de l'attestation, interdit sur l'instance réelle ; l'archive finale d'une copie prend le format de l'archive finale d'un magasin fermé pour cessation ».
- C36. Étape 3 : « archive finale signée du magasin de démonstration à sa fermeture définitive et son téléchargement par le propriétaire » : supprimée ; « clôtures mensuelle et de l'exercice et archive finale à la fermeture définitive d'un magasin (cessation, cession) » reste.
- C37. Étape 9 : « sort des sociétés jamais validées et de leurs données de démonstration » → « sort des sociétés jamais validées ; durée de vie des copies de démonstration (30 jours, puis archive de la caisse gardée six ans et suppression entière de la base, sans sauvegarde), absence d'adresse IP et de navigateur sur le site de démonstration, journaux de 14 jours, et ce que le site réel garde de leur demande (empreinte du lien, dates) ».
- C38. Étape 12 : « le magasin de démonstration, une société non validée et une société sans magasin réel, hors un cabinet d'expertise comptable, ne sont jamais facturés » → « la copie de démonstration, une société non validée et une société sans magasin, hors un cabinet d'expertise comptable, ne sont jamais facturées ; une limite du nombre de copies actives, liée au coût d'hébergement, se décide ici ».
- C39. Étape 15 : « données de démonstration de la production (celles du développement existent depuis le lot SEED : …) » → « mise en ligne du site de démonstration (sa machine et son serveur PostgreSQL à lui, jamais ceux du réel ; son domaine à lui, jamais un sous-domaine du réel ; rôles `demo_admin` et `demo_app` ; trois bases modèles refaites chaque nuit par le semis ; archives de caisse des copies sauvegardées six ans ; lot DEMO-COPIE) et déploiement commun des deux sites (voir « Développement : toujours repartir de zéro »), les caches de configuration et de routes refaits sur chaque serveur ».
- C40. « magasin(s) réel(s) » : 35 occurrences (l. 156 : 13 ; l. 164 : 9 ; l. 166, 167, 177, 180, 202, 203, 214 : 1 chacune ; l. 169 : 4 ; l. 179 : 2). « réel(s) » se retire partout, dans un seul passage relu ligne par ligne, C4, C10, C32, C33 et C38 compris. Exemples : « de chaque magasin réel de sa portée » → « de chaque magasin de sa portée » ; « les magasins réels que voit son cabinet » → « les magasins que voit son cabinet » ; « d'un magasin réel (clé composée) » → « d'un magasin (clé composée) ». « opération commerciale réelle » (l. 166 et 167) reste : ces mots opposent désormais le site réel au site de démonstration.
- C41. Les mentions « (décision de l'éditeur du 12/11/2026) » (propriétaire qui vend en démonstration), « (décision de l'éditeur du 14/11/2026) » (archive, deux fois) et « (décision de Claude, T115) » partent avec leurs phrases ; la matrice les marque « remplacée par la décision de l'éditeur du 15/11/2026, en soirée (DEMO-COPIE) ».

**Ajouts (rien n'existe encore)**
- A1. « Développement : toujours repartir de zéro », après la puce des migrations : « Il n'y a qu'une version du logiciel, pour le site réel et pour le site de démonstration, déployée ensemble sur les deux, le site réel d'abord. Avant la mise en production, il n'y a que le poste de l'éditeur : chaque mise à jour qui recrée la base réelle efface aussi les copies de démonstration et leur registre, liens compris, et on recrée sa démonstration ; une mise à jour qui ne recrée pas la base réelle refait chaque copie depuis le nouveau modèle de son type, avec la même référence, le même lien et la même date de fin, et un message qui le dit. Toute copie supprimée laisse d'abord l'archive signée de sa caisse (voir « Copie de démonstration »). »
- A2. `nouvelle-session/etape3-ajouts.md` : le site de démonstration a ses propres clés, jamais celle de la caisse réelle, et `caisse:diagnostiquer` le vérifie sur chaque site ; le mode d'entraînement (JET 100 et 105) n'existe que dans les copies ; l'archive d'une copie, faite par DEMO-COPIE avec ce qui existe (clôtures du jour, exports, tickets Z, sommaire signé), prend à l'étape 3 le format de l'archive finale d'un magasin fermé pour cessation, avec les clôtures mensuelle et de l'exercice ; la notice et l'attestation décrivent le mode d'entraînement du site de démonstration (N51) ; le module fiscal en version 3.0 entre dans l'attestation.
- A3. Puce « Produit », phrase de l'isolation : « Le site réel et le site de démonstration ne lisent jamais la base de l'autre. Du site réel vers le site de démonstration ne passent que des messages signés : créer une copie de tel type, avec telle empreinte de lien, jusqu'à telle date ; renouveler le lien ; recommencer ; supprimer ; donner l'état d'une copie ; et le billet d'entrée signé du bouton du propriétaire. Du site de démonstration, le site réel ne lit que son état général signé, sans aucune donnée d'une copie. » Dans la liste « Les données propres à une société (…) » : « sa demande de copie de démonstration (type, empreinte du lien, génération, dates, état) et sa boîte d'envoi vers le site de démonstration ». Dans la liste des lectures permises hors de la société courante : « les tâches et les travaux du site réel qui suivent les demandes de démonstration (`demonstration:marquer-les-fins`, `demonstration:relancer-les-messages`, `demonstration:renvoyer-les-demandes`, `demonstration:lire-l-etat`, `EnvoyerLesMessagesALaDemonstration`), limités à l'identifiant, à la référence, à l'état, à la fin, à l'empreinte et à la génération, sans aucun nom, chaque écriture faite dans le contexte de la société de la ligne ; sur le site de démonstration seulement, le registre des copies, qui ne porte rien d'une société (référence, repère, type, empreinte du lien, fin, validée ou non) ; dans une copie, la page « E-mails qui seraient partis », qui montre à tout compte de la copie les e-mails capturés de la société de la copie (dans la copie d'un cabinet, ceux du cabinet), jamais ceux d'une autre société de la même base ». Après la phrase « Aucun écran ni aucun message du panneau d'un magasin ne laisse deviner (…) » : « Seule exception, sur le site de démonstration seulement : le bandeau « Vous êtes : » d'une copie range les comptes de la société de la copie par magasin, puisqu'une seule personne s'entraîne derrière tous ses comptes ; il ne nomme jamais une autre société. »
- A4. Puce nouvelle « Copie de démonstration », après « Vérification de la société » : « Copie de démonstration (décision de l'éditeur du 15/11/2026, en soirée à Paris, confirmée le 16/11/2026 et précisée vers 1 h 30 et 1 h 40, lot DEMO-COPIE) : une société modèle fictive par type d'activité (« Commerce ou atelier », « Services, sans vente d'articles », « Cabinet d'expertise comptable »), remplie par le semis, par les vraies actions et les fournisseurs factices, avec les cas de son type ; chaque nuit, le site de démonstration refait ces trois bases modèles, contrôlées à zéro problème d'isolation avant de servir. Le propriétaire d'une société, cabinet compris, avant comme après la validation, son adresse de connexion confirmée, crée sa démonstration depuis la carte « Ma démonstration » : PostgreSQL copie la base modèle du type de sa société, sans aucun choix (CREATE DATABASE … TEMPLATE …), sur un site séparé, même code, en mode « démonstration », servi sur son propre domaine, jamais un sous-domaine du site réel, par sa propre machine et son propre serveur PostgreSQL en production. Chaque société ne voit que son type : aucun écran, aucun e-mail ni aucun message de la démonstration ou de la carte ne parle des autres types d'activité ni de leurs démonstrations. Une seule copie à la fois, supprimée en entier (DROP DATABASE) 30 jours après sa création, avec « Recommencer à zéro » pendant le mois (même lien, même date de fin) et une nouvelle copie ensuite ; avant toute suppression, l'archive signée de sa caisse est gardée six ans hors de toute base. Un seul lien par société, tenu par le propriétaire, qu'il donne à qui il veut et renouvelle (l'ancien cesse de marcher dès que le site de démonstration l'a reçu, avec toutes les visites ouvertes, celles du bouton comprises ; la copie reste) : 256 bits tirés au hasard, placés après « # » pour n'être jamais envoyés par le navigateur au serveur, dont le site réel ne garde que l'empreinte ; il peut rester dans l'historique du navigateur et chez une messagerie qui réécrit les liens, ce que les textes disent ; il s'affiche une seule fois, à sa création et à son renouvellement, et part alors par e-mail à l'adresse de connexion confirmée ; à la première visite, il est échangé contre un témoin du site de démonstration et l'adresse se réécrit sans lui, avec `Referrer-Policy: no-referrer` ; qui a le lien entre sans se connecter ; « Ouvrir ma démonstration » entre par un billet signé d'une minute, sans le lien. Identité entièrement fictive : jamais le nom, le SIREN ni aucune donnée de la vraie société. En haut de chaque page de la copie, le bandeau « Vous êtes : » change de compte d'un clic, sans mot de passe, parmi les seuls comptes de la société de la copie (propriétaire, administrateur sans vente, administrateur qui vend, responsable, vendeur, observateur et expert-comptable déclaré ; dans la copie d'un cabinet : propriétaire du cabinet et collaborateurs, les clients ne se voyant que depuis l'espace du cabinet), et affiche le mot de passe de démonstration que redemandent les gestes qui le redemandent ; toutes les règles restent celles du vrai logiciel, y compris « le propriétaire ne vend jamais ». La connexion, l'inscription et « Mot de passe oublié » y sont fermées. Aucun e-mail ne part : la page « E-mails qui seraient partis » les montre. Clés de signature propres à la démonstration, jamais celle de la caisse réelle ; documents marqués « Démonstration — sans valeur » ; seulement les fournisseurs factices, hors l'antivirus ClamAV, local ; ni adresse IP ni navigateur enregistrés. Les tâches du calendrier des magasins tournent dans chaque copie. Une société close ou révoquée perd sa copie aussitôt ; une société suspendue ou résiliée la garde jusqu'à sa date, sans en recréer ni la remettre à zéro. Chaque geste s'inscrit au journal d'audit de la société, catégorie « gestion des accès », sans le lien ni son empreinte. »
- A5. Puce « E-mails » : « Aucun e-mail ne part du site de démonstration. Seul le site réel envoie le lien de la démonstration, à l'adresse de connexion confirmée du propriétaire. »
- A6. Puce « Écrans » : « Dans la copie de démonstration, le bandeau « Vous êtes : » est en haut de chaque page des espaces de la copie. Il est lisible en clair et en sombre, comme tout écran. Le formulaire qui envoie le billet d'entrée au site de démonstration est le seul du logiciel qui parte vers un autre site, et sa page est la seule dont la politique de contenu élargit `form-action`. »
- A7. Étape 13 : la liste des copies (type, date de fin, taille ; jamais le nom de la société sur le site de démonstration, qui ne le connaît pas : la correspondance se lit sur le site réel par la référence), « Supprimer une copie » (archive d'abord), « Refaire les bases modèles », les échecs de la reconstruction, les archives de caisse des copies et leur lecture sur une demande de l'administration fiscale ; chaque geste au journal d'audit ; l'éditeur ne lit jamais une copie ni une archive sans un geste audité.
- A8. Étape 9 : une copie ne contient aucune donnée personnelle de la vraie société ; le site réel ne garde que l'empreinte du lien, le type et les dates ; la ligne `demonstrations` reste avec la société (état « terminée », sans lien), le journal d'audit suit sa durée ; ce qu'un visiteur tape dans sa copie meurt avec elle, sauf ce qu'il a fait passer par la caisse, gardé six ans dans l'archive (C.17, C.18) ; aucune adresse IP ni aucun navigateur sur le site de démonstration ; journaux de 14 jours.
- A9. Puce « Caisse et clôtures », phrase « Les documents, registres, journaux des événements et le journal d'audit des opérations se conservent 10 ans, sans aucune purge (…) ; seules s'effacent, à leur échéance : … » : ajouter à la liste « les bases des copies de démonstration, supprimées en entier à leur fin, après l'archive signée de leur caisse, gardée six ans hors de toute base, puis effacée ». Sans cette phrase, CLAUDE.md contredirait le lot.
- A10. Protocole de rigueur, point 2, liste des unicités voulues dans tout le logiciel : ajouter « la référence d'une demande de démonstration (adresse de la copie dans le registre du site de démonstration, jamais affichée) ».
- Puce « Outillage » : « `ext-sodium` déclarée dans composer.json (signature des messages entre les deux sites) ; le réglage `PILIQI_SITE=reel|demonstration` » ; et, pour le refus en production : « en production, le site réel refuse les adresses sur un domaine réservé (`example.com`, `example.net`, `example.org`, `.example`, `.test`, `.invalid`, `.localhost`) ».

#### H.2 README (relevé 1)

- R1. Installation, point 9 : « la remplit de données de démonstration, avec la liste des comptes (voir « Données de démonstration sur votre PC ») » → « la remplit des données semées, avec la liste des comptes pour se connecter (voir « Données semées sur votre PC ») ».
- R2. Installation, point 13 : « Avec les données de démonstration, les sociétés et leurs comptes existent déjà » → « Avec les données semées, les sociétés et leurs comptes existent déjà ».
- R3. Titre « ## Données de démonstration sur votre PC » → « ## Données semées sur votre PC ». Phrase en tête : « Le même semis remplit, chaque nuit, les trois bases modèles du site de démonstration (voir « Site de démonstration »). »
- R4. « la remplit de données de démonstration complètes » → « la remplit de données complètes » ; « une base de démonstration plus petite cacherait des cas » → « une base semée plus petite cacherait des cas ».
- R5. « `php artisan test --group=semis` sème la démonstration dans la base de test » → « sème la base de test » ; phrase ajoutée : « Il vérifie aussi que chaque base modèle ne garde que ses sociétés et leurs comptes. » ; le parcours en navigateur devient `tests/Browser/Parcours/DonneesSemeesTest.php`.
- R6. « `storage/app/private/comptes-de-demonstration.txt` » → « `storage/app/private/comptes-du-semis.txt` » ; « `PILIQI_DEMO_MOT_DE_PASSE` » → « `PILIQI_SEMIS_MOT_DE_PASSE` » ; phrase : « Sur le site de démonstration, on change de compte sans mot de passe : ni la liste ni le mot de passe commun n'y servent. »
- R7. MAISON FERRAND : « deux magasins réels, les Halles et la République » → « deux magasins, les Halles et la République » ; « sa démonstration s'est fermée à la validation » supprimée ; à la place : « sa propriétaire a créé sa démonstration, puis a renouvelé son lien (l'ancien ne marche plus) ». Les autres états des demandes (F.7) s'ajoutent à chaque société.
- R8. « « FLEURS DE SAISON » attend le code de son courrier (magasin de démonstration ouvert, deux comptes d'entraînement ; le code se lit… » → « « FLEURS DE SAISON » attend le code de son courrier (démonstration créée, lien en cours ; le code se lit… ».
- R9. « Les données de démonstration de la production restent à l'étape 15 de la feuille de route. » → « La mise en ligne du site de démonstration se fait à l'étape 15 de la feuille de route. »
- R10. Planificateur en développement : « clôture des inscriptions sans code, démonstrations inactives, effacement des traces… » → « clôture des inscriptions sans code, effacement des traces… » ; phrase : « Sur le site de démonstration, le planificateur refait les trois bases modèles chaque nuit, nettoie, supprime les copies arrivées à leur date et fait passer les tâches de chaque copie. »
- R11. « Elle ne vend qu'une fois validée, (…) et dans un magasin réel, et s'essaie avant dans un magasin de démonstration. » → « Elle ne vend qu'une fois validée, (…) et dans un de ses magasins. Avant, et après aussi, elle s'essaie dans sa copie de démonstration (voir « Site de démonstration »). »
- R12. Nouvelle section « Site de démonstration », après « Données semées sur votre PC » :
  - le réglage `PILIQI_SITE` et ce que fait chaque mode ;
  - la base du registre et le rôle `CREATEDB`, seulement sur le site de démonstration ;
  - les trois bases modèles, `demonstration:construire-les-modeles`, son heure, son contrôle ;
  - la copie, la suppression à 30 jours, le nettoyage ;
  - les clés (messages Ed25519, caisse, journal d'audit, `APP_KEY`), hors du dépôt, et `demonstration:generer-cle` ;
  - la page « E-mails qui seraient partis » ;
  - comment lancer les deux sites côte à côte sur le poste (partie I) ;
  - les mises à jour, avant et après la mise en production, et l'ordre du déploiement (partie E) ;
  - `ext-sodium` et sa raison ;
  - le groupe de tests `copies`, et les rôles `demo_admin` et `demo_app` de la base de test ;
  - le domaine à part (jamais un sous-domaine du réel) et les deux noms d'hôte du poste (`127.0.0.1` et `localhost`) ;
  - les caches de configuration et de routes, refaits sur chaque serveur, jamais copiés ;
  - l'archive de la caisse d'une copie avant sa suppression, gardée six ans ;
  - l'état publié (`/interne/etat`) que le site réel lit chaque heure ;
  - la clé du journal d'audit de démonstration et `journal-audit:epingler-la-cle`.

#### H.3 `.env.example` (noms seulement, aucune valeur)

- Commun : `PILIQI_SITE`, `PILIQI_VERSION`, `VIES_PILOTE`.
- Site réel : `DEMONSTRATION_URL`, `DEMONSTRATION_MESSAGES_CLE_PRIVEE`, `DEMONSTRATION_MESSAGES_CLE_ID`, `DEMONSTRATION_ETAT_CLE_PUBLIQUE`, `HOTES_DE_CONFIANCE` (production), `SESSION_COOKIE` (`__Host-piliqi-session` en production).
- Site de démonstration (dans un bloc commenté « Seulement sur le site de démonstration ») : `DEMONSTRATION_NOM_DU_SITE`, `DEMONSTRATION_MESSAGES_CLES_PUBLIQUES`, `DB_REGISTRE_HOST`, `DB_REGISTRE_PORT`, `DB_REGISTRE_DATABASE`, `DB_REGISTRE_USERNAME`, `DB_REGISTRE_PASSWORD`, `DB_DEMO_ADMIN_USERNAME`, `DB_DEMO_ADMIN_PASSWORD`, `DEMONSTRATION_ORIGINE_DU_SITE_REEL`, `DEMONSTRATION_ETAT_CLE_PRIVEE`, `DEMONSTRATION_COPIES_MAX`, `DEMONSTRATION_COPIES_EN_MEME_TEMPS`, `DEMONSTRATION_PART_DES_VALIDEES`.
- Renommée : `PILIQI_DEMO_MOT_DE_PASSE` → `PILIQI_SEMIS_MOT_DE_PASSE`.
- Une phrase en commentaire : sur le site de démonstration, `MAIL_MAILER=demonstration`, `DB_USERNAME=demo_app`, `DB_DATABASE=demo_aucune_copie`, ses propres `APP_KEY`, `CAISSE_SECURISEE_CLE_PRIVEE`, `JOURNAL_AUDIT_CLE` et `JOURNAL_AUDIT_CLE_ID`, jamais ceux du site réel ; sur le poste, `APP_ENV` porte le nom du fichier (`.env.demonstration` : `APP_ENV=demonstration`).

#### H.4 La matrice des décisions (`tests/Unit/matrice-des-decisions.php`)

- **Règles supprimées** (tous leurs tests disparaissent) : `comptes.proprietaire-dans-la-demonstration` (2 tests sur 2) ; `verification.demonstration-apres-la-validation` (10 tests de démonstration sur 12 ; les 2 autres passent à la règle de la copie).
- **Règles réécrites** : `verification.magasin-de-demonstration` (9 tests sur 9) → `demonstration.copie-par-societe`, avec N1 à N26 ; `caisse.demonstration-mode-distinct` (3 sur 3) → `demonstration.sites-separes-et-cles-propres` ; `comptes.magasin-cede-ferme-et-entrainement` (3 sur 4) → `comptes.magasin-cede-ou-ferme`, sans les comptes d'entraînement.
- **Règles nouvelles** : `demonstration.lien-renouvelable`, `demonstration.trente-jours`, `demonstration.archive-avant-suppression`, `demonstration.identite-fictive`, `demonstration.un-seul-type`, `demonstration.bandeau-vous-etes`, `demonstration.courriels-captes`, `demonstration.mises-a-jour`, `demonstration.domaine-et-temoins`, `demonstration.roles-et-files`, `demonstration.etat-publie`, `outillage.domaines-reserves-refuses`, chacune avec ses tests de la partie G.
- **Règles dont seule la citation d'un test change** : `comptes.changement-de-role`, `societe.membre-d-un-assujetti-unique`, `type.cabinet-sans-magasin`, `cabinets.inscription-et-verification`, `cabinets.comptes-du-cabinet-chez-le-client`, `cabinets.espace-du-cabinet`, `cabinets.autorisations-accordees-par-le-client`, `cabinets.magasins-choisis`, `cabinets.comptes-de-l-expert-comptable-declare`, `hors-france.exoneration-non-prouvee`, `pdp.transmission-par-la-passerelle`, `hors.donnees-de-demonstration` (renommée `hors.donnees-semees`), `graphiques.chiffres-cles-et-catalogue`, `charges.circuit`.
- **Règles dont seul le texte parle de la démonstration** : `developpement.aucune-donnee-reelle`, `cabinets.collaborateurs`, `vendeurs.qui-vend`, `achats.vente-sans-stock`, `outillage.fournisseurs-externes-derriere-une-interface`, `graphiques.qui-les-voit`.
- **Décisions « remplacee »** par la décision DEMO-COPIE : D68, D71, T115, ARCHIVE-DEMO-14-11, Q4-g, 2.1-112, 2.1-113, 2.1-115, T35.2. **En partie** : precision-1 (la part « jamais le propriétaire » tient), Q4-h, S6, T4, 2.1-079 (l'étape du courrier reste cachée après une invitation ; celle de la démonstration ne l'est plus), D70 et T35.4 pour leur raison (« la loi exige de garder les données du mode d'entraînement » tient : elle devient l'archive avant suppression).
- **Source nouvelle** : la décision du 15/11/2026 en soirée, sa confirmation du 16/11/2026 vers 0 h 50 (Q1 à Q4, mises à jour), ses précisions de 1 h 30 et 1 h 40, et les 34 choix de ce cahier (décisions de Claude, D76), avec leurs règles et leurs tests ; les réponses de l'éditeur à `questions-editeur.md` s'y ajoutent.

#### H.5 Les notes et les lots à venir (relevé 4)

Les fichiers sont sous `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/`. Le miroir `nouvelle-session/pilotage-reprise/notes/` (refait par `pilotage-sync.sh`) et les copies de sauvegarde (`*.avant-*.md`, `nouvelle-session/reprises/*.avant-*`, `er-sauvegardes/`) ne se corrigent pas à la main. La correction se fait quand ce cahier est rangé, sauf N12 et N23 (après DATEOP).

**`nouvelle-session/a-faire-apres-chaine.md`**
- N1. DEMO-V (l. 12 à 29) : **marquer** sous le titre « Fait (lot DEMO-V). Dépassé le 16/11/2026 par DEMO-COPIE : le magasin de démonstration du compte réel est retiré (Q2). T115 n'a plus d'objet. »
- N2. TVA27, point 2 ter (l. 187) : **ajouter** la consigne de place (« Place, isolation, vérifications ») : pendant TVA27, le magasin intérieur vit encore ; dans `Magasin::refusDeVente()`, le contrôle du régime et du crédit de départ se place après la branche existante de la démonstration (aucune branche nouvelle) ; en base, la garde du régime ne porte que sur un magasin qui a un SIRET (`magasins.siret IS NOT NULL`) ; le test « démonstration utilisable sans régime ni crédit » **reste** pendant TVA27, et DEMO-COPIE-1 le supprime avec la condition (D.2, point 16 bis). **Après DEMO-COPIE-1**, réécrire le point : « Les étapes « Choisir le régime de TVA » et « Votre TVA avant PILIQI » se font et se refont librement avant la validation. Elles ne bloquent que l'activité réelle. Pour s'entraîner, la société ouvre sa copie de démonstration, dont la société modèle a déjà son régime et sa réponse au crédit de départ. » Les mots de l'éditeur restent.
- N3. TVA27, point 3 bis (l. 188 et l. 200, même texte) : **pendant TVA27**, garder « le magasin de démonstration reste utilisable » et « démonstration permise », avec la condition sur le SIRET de N2 ; **après DEMO-COPIE-1**, les remplacer par « La copie de démonstration n'est pas touchée : autre base, société modèle au régime déjà choisi. » Et, dès maintenant, le doublon du bloc « Crédit de TVA reporté » : garder les l. 190 à 199 (« NÉGATIF pour un crédit », choix de l'éditeur du 15/11 vers 15 h 35) ; supprimer les l. 200 à 206 après avoir reporté dans la première version les tests de la l. 206.
- N4. DEMO-SITE (l. 216 à 335) : déjà marqué « [REMPLACÉ] » ; ce cahier reprend ce qui sert (partie « Ce que ce lot remplace »).
- N5. SEED (l. 377, l. 384) : **marquer** sous le titre (l. 360) « Fait. Le semis perd le magasin de démonstration et les comptes d'entraînement au lot DEMO-COPIE. »
- N6. SEED (l. 411) : **réécrire** « l'étape 15 met en ligne le site de démonstration. Ses trois bases modèles, une par type d'activité, se refont chaque nuit par le semis. »
- N7. SEED (l. 399, l. 404) : noms tranchés par le choix 21 (`comptes-du-semis.txt`, `PILIQI_SEMIS_MOT_DE_PASSE`) ; sur le site de démonstration, ni mot de passe commun ni liste.

**`pro-d76/cahier-ER-PRO.md`**
- N8. Assujetti unique (l. 44, 154, 157) : **marquer** ; règle : « la copie de démonstration restant utilisable (site à part) ».
- N9. Exonération non prouvée (l. 161, 164) : **marquer** ; texte juste : « jamais le propriétaire ».
- N10. Archive du magasin de démonstration (l. 192) : **marquer** « Remplacé le 16/11/2026 par DEMO-COPIE (Q2) : plus de magasin de démonstration dans le compte réel. La loi qui la fondait tient : chaque copie du site de démonstration laisse l'archive signée de sa caisse avant sa suppression (DEMO-COPIE, C.18) » ; matrice : de `a_venir` (étape 3) à `remplacee`, avec la règle `demonstration.archive-avant-suppression`.
- N11. État des charges (l. 204) : **réécrire** « Un cabinet n'a pas de page des charges, donc pas d'état. Dans une copie de démonstration, la société modèle a sa page des charges, comme dans le vrai logiciel. »
- N12. Pièces des charges en ZIP (l. 207, 209, lot DATEOP en cours) : rien pendant DATEOP ; DEMO-COPIE retire ensuite ces exclusions du code (D.10, D.11).
- N13. DEVIS-RET, IDENT, CESSION, NAF25, VIES-DEP (l. 216 à 219, 226, 227) : **ajouter** en tête du § 12 la consigne « aucun code nouveau propre au magasin intérieur » ; VIES-DEP : « sur le site de démonstration, le VIES est toujours le faux, par le mode du site, jamais par un test sur le magasin » ; NAF25 : de même pour le répertoire Sirene.
- N14. MDP (l. 220 à 225, 231) : **ajouter** « Les comptes d'entraînement disparaissent au lot DEMO-COPIE : MDP ne crée rien de nouveau pour eux. Sur le site de démonstration, on change de compte sans mot de passe, et il n'y a pas de page de connexion : les règles de MDP valent pour le site réel. Le mot de passe commun du semis respecte la nouvelle règle. »

**`nouvelle-session/serie9-rapide.js`**
- N15. Invite du lot MDP (l. 181) : **réécrire** « Vérifie que les postes reconnus, les collaborateurs d'un cabinet et les experts-comptables de la société restent cohérents avec la nouvelle règle. Les comptes d'entraînement seront retirés au lot DEMO-COPIE : n'écris rien de nouveau pour eux. »

**`seed2/cahier-SEED-TOUT.md`**
- N16. l. 21 : **ajouter** « Le même semeur fabrique aussi les trois bases modèles du site de démonstration (lot DEMO-COPIE, partie F). » Noms : choix 21.
- N17. l. 28 : **réécrire** « (ventes refusées ; pour s'essayer, elle crée sa copie de démonstration, qui n'est pas semée sur le poste) ».
- N18. l. 29 : **réécrire** « (sans magasin de démonstration) » ; **ajouter** les états des demandes de copie (F.7).
- N19. l. 36 : supprimer « comptes d'entraînement de la démonstration ».
- N20. l. 62 : « jamais le propriétaire hors de la démonstration » → « jamais le propriétaire » ; supprimer la phrase du compte d'entraînement.
- N21. l. 64 : remplacer par « Site de démonstration : la base réelle ne contient aucune donnée de démonstration. Sur le site de démonstration, chaque base modèle ne contient que ses sociétés, fictives, et le contrôle d'isolation tourne sur elle après chaque reconstruction. »
- N22. l. 95 : retirer « démonstration » ; l. 108 et l. 111 : retirer les comptes d'entraînement de la liste des comptes et de ses libellés.

**`nouvelle-session/passages/DATEOP.md`**
- N23. l. 26, 38, 121, 138, 376 : rien (note d'un lot en cours) ; DEMO-COPIE retire ces libellés et contrôles du code (D.5, D.15).

**`nouvelle-session/etape3-ajouts.md`**
- N24. l. 3 : **marquer** « REMPLACÉ le 16/11/2026 (DEMO-COPIE, Q2) : plus de magasin de démonstration dans le compte réel. À la place, l'archive finale de chaque copie du site de démonstration (A2 ci-dessous). »

**`rls/cahier-RLS.md`**
- N25. l. 10 : **ajouter** : la sécurité au niveau des lignes vaut pour chaque base modèle et chaque copie (mêmes migrations) ; le changement de compte du bandeau ouvre une nouvelle session et repose le contexte, comme une connexion ; `demonstrations` et `messages_vers_la_demonstration` ont leur politique ; test : après « Vous êtes : », une requête brute ne voit que ce que voit le nouveau compte.
- N26. l. 12 à 14 : **ajouter** : le rôle de contrôle existe aussi sur le site de démonstration, pour chaque base modèle ; le semis tourne avec la sécurité au niveau des lignes active ; une connexion ne passe jamais d'une copie à une autre sans être refaite (test de concurrence entre deux copies).

**`nouvelle-session/campagne/strategie/finale.md`**
- N27. l. 28 : « 1 propriétaire, qui ne vend pas dans les magasins réels » → « 1 propriétaire, qui ne vend jamais ».
- N28. l. 30 : remplacer par « Site de démonstration : une copie de chaque type (Commerce ou atelier, Services, Cabinet), créée par des sociétés de la campagne, avec plusieurs copies en même temps, sur sa propre machine et son propre serveur PostgreSQL (campagne finale). »
- N29. l. 48 : remplacer le scénario « Démonstration » par : 1) aucune lecture croisée entre les deux sites (bases, clés, fichiers, files) ; 2) un message signé falsifié, rejoué ou trop vieux refusé ; 3) le lien : 256 bits, empreinte seule, échangé contre un témoin, adresse réécrite, ancien lien refusé après un renouvellement ; 4) des copies demandées pendant la reconstruction de nuit ; 5) une seule copie à la fois, « Recommencer à zéro » pendant une visite, suppression au 30e jour pendant une session ouverte ; 6) le nombre de copies que la machine tient (disque, connexions), écrit au rapport ; 7) une mise à jour : chaque copie refaite, le lien valable, un message ; 8) aucun e-mail réel, la page « E-mails qui seraient partis » ; 9) le propriétaire ne vend nulle part sur le site réel (écran, Livewire, action et base) ; 10) l'archive de la caisse avant chaque suppression, relue ; 11) les rôles `demo_admin` et `demo_app` (une copie ne supprime jamais une autre) ; 12) les quotas et la part réservée aux sociétés validées ; 13) l'état publié lu par le site réel.

**`nouvelle-session/superpdp-plan.md`**
- N30. l. 29 : retirer « et le magasin de démonstration » ; ajouter « Le site de démonstration n'a aucune connexion à SUPER PDP : sa passerelle est toujours la fausse, quel que soit le réglage. Une copie montre ce qui partirait, sans rien envoyer. »
- N31. l. 62 : « le magasin de démonstration ne transmet rien » → « le site de démonstration ne transmet rien ».

**Chaîne TVA : `nouvelle-session/tva/plan-execution.md` et `nouvelle-session/tva/conception-finale.md`**
- N32. « démonstration exclue », « sans la démonstration » (plan l. 118, 225, 867, 1006, 1017, 1568, 1713, 2228 ; conception l. 636, 1268, 1313, 1461, 1563, 1727, 1818) : retirer ; les tests qui ne prouvent que cette exclusion se suppriment, ceux qui prouvent aussi l'exclusion d'une autre société restent.
- N33. Circuit « aucun » du magasin de démonstration (plan l. 317, 641, 642, 946, 1109, 1552 ; conception l. 101, 411, 412, 835, 1332, 1335, 1455) : supprimer ce cas ; sur le site de démonstration, le circuit se calcule comme au réel, seule la passerelle est fausse.
- N34. `public bool $estDemonstration` (plan l. 438 ; conception l. 223) : supprimer le paramètre et ce qui le lit.
- N35. Faux VIES « quand le magasin actif est la démonstration » (plan l. 773, 791, 1544, 2124, 2133 ; conception l. 541, 559, 660, 1670, 1673, 1675) : **réécrire** « Sur le site de démonstration (`PILIQI_SITE=demonstration`), tous les fournisseurs externes sont les faux, VIES compris, quel que soit le pilote. Sur le site réel, jamais. » ; test : « le site de démonstration consulte toujours le faux registre VIES ».
- N36. Plan l. 2238, 2240, 2545 : retirer la démonstration ; la ligne de l'annexe sur `MagasinDeDemonstrationTest.php` disparaît.
- N37. Plan l. 2359 : « (démonstration, magasin fermé : refus) » → « (magasin fermé : refus) ».
- N38. Plan l. 231 : « essayer le magasin de démonstration » → « ouvrir sa démonstration ».

**Onglet « Protections et rigueur »** (copie `nouvelle-session/reprises/protections-et-rigueur.md` ; correction dans l'onglet du document de pilotage)
- N39. l. 26 : « (sociétés, magasins, comptes, cabinets, démonstration) » → « (sociétés, magasins, comptes, cabinets, site de démonstration) ».
- N40. l. 68 à 73 et 200 (« Magasin de démonstration à part », « Démonstration sans e-mail ni transmission », « La démonstration disparaît après la validation », « Démonstrations inactives fermées toutes seules », « Démonstration figée et unique », « Comptes d'entraînement enfermés », « Archive finale de la démonstration ») : état « Remplacée par DEMO-COPIE (16/11/2026) ». Protections nouvelles, état « À venir (lot DEMO-COPIE) » : une base par copie, copiée du modèle de son type, supprimée en entier au 30e jour ; un lien de 256 bits, empreinte seule, après « # », échangé contre un témoin, renouvelable, `Referrer-Policy: no-referrer` ; une copie à la fois par société ; aucune donnée de la vraie société dans la copie ; aucun e-mail (page « E-mails qui seraient partis ») et aucune transmission ; des clés propres au site de démonstration ; entre les deux sites, seulement un message signé ; les fournisseurs factices sur tout le site de démonstration (ClamAV local excepté) ; les documents marqués ; la marque de chaque base (`site_de_la_base`) ; l'empreinte du schéma (copie pas à jour jamais servie).
- N41. l. 43, 59, 64, 65, 280 : retirer « la démonstration parmi les magasins ouverts », « la démonstration » dans « jamais l'espace d'administration, la démonstration ni le journal d'audit », « ni dans la démonstration », « La démonstration en sort après la validation ».
- N42. l. 212, 271, 278 : retirer les morceaux sur le compte d'entraînement.
- N43. l. 276 : supprimer « (permis seulement dans sa démonstration) ».
- N44. l. 181, 252 : « dans sa copie de démonstration (site à part) ».
- N45. l. 113, 123 : retirer « le marquage « démonstration » » et « démonstration » de la liste des JET.
- N46. l. 81, 83, 172, 348, 349, 394, 395, 578 : « remplissage de démonstration » → « semis » ; l. 394 : ajouter « Le même semis refait chaque nuit les trois bases modèles du site de démonstration, et le contrôle d'isolation tourne sur chacune. » ; l. 349 : « refusé sur le site réel en production ; permis seulement en développement, en test, et sur le site de démonstration pour construire ses modèles ».
- N47. l. 515 (C10, « 9 boutons de la démonstration ») : ajouter « Sans objet pour la démonstration depuis DEMO-COPIE. Le défaut technique (action imprimée sans garde de visibilité) reste à vérifier dans les autres vues (lots NAV). »
- N48. l. 544, 545 (D68, D70) : D68 : « Remplacée le 16/11/2026 par DEMO-COPIE (Q2, Q3). » D70 : « Remplacée en partie le 16/11/2026 par DEMO-COPIE : la démonstration n'est plus gardée en base ; sa raison tient (la loi demande de garder les données du mode d'entraînement, BOFiP § 50, 155 et 180) : avant sa suppression, chaque copie laisse l'archive signée de sa caisse, gardée six ans. »

**Onglet « À faire vérifier par un professionnel »** (copies `decisions-affichage/sortie/section-*.md` et `pro-d76/lignes.json` ; correction en ligne, puis les `section-*.md` refaits, que lisent les invites)
- N49. T35.4 (`section-D.md:65`, ligne `mb4cajnjtng.359522`) : nouvelle décision de Claude (D76), point légal : « Le site de démonstration fait tourner le même logiciel et le même module fiscal que le site réel ; il signe, chaîne et clôture des ventes simulées : c'est un module de type « école » au sens du BOFiP BOI-TVA-DECLA-30-10-30 § 50, et rien n'empêche un visiteur d'y encaisser un vrai client. Ses données sont donc concernées (§ 50), identifiées (« Démonstration — sans valeur », JET 100 et 105, opérateur de chaque vente, § 150), jamais supprimées sans trace (§ 130) ni réduites aux tickets Z (§ 180) : avant de supprimer la base d'une copie, PILIQI clôture ses journées, inscrit le JET 105, vérifie son registre et range l'archive signée de sa caisse six ans hors de toute base (§ 155 ; référentiel LNE 1.8, exigences 5, 13 et 16). Choix de l'éditeur demandé (question 1 de `questions-editeur.md`) ; la question reste posée au professionnel ; sa réponse prime. »
- N50. T35.2 (`section-B.md:162`, `.357788`) : « sans objet depuis DEMO-COPIE : la base réelle ne contient plus de démonstration » ; T35.3 (`:163`, `.358654`) : retirer la part de la démonstration intérieure, ajouter « l'archive d'une copie du site de démonstration (T35.4) ».
- N51. T32.4, T32.1, T31.1, T32.5 (`section-B.md:117` ; `.377598`, `.340154`, `.372512`, `.378767`) : « L'instance réelle n'a pas de mode d'entraînement : il n'existe que sur l'instance de démonstration de l'éditeur, interdit sur l'instance réelle (marque de la base, clés épinglées séparées). La notice et l'attestation décrivent ce mode du même logiciel : identité fictive, clés propres, documents marqués « Démonstration — sans valeur », JET 100 et 105, archive avant suppression gardée six ans. Le module fiscal passe en version 3.0 (majeure : contenu signé changé, mode d'entraînement déplacé, clés de démonstration) : la nouvelle version entre dans l'attestation. »
- N52. T12.4 (`section-B.md:140`) : « le site de démonstration a ses propres bases : il n'entre jamais dans le FEC d'une société ».
- N53. T95.5 (`.415514`) : retirer « compte d'entraînement » ; ajouter au périmètre de l'audit le site de démonstration (lien, témoin, bandeau, message signé).

**Onglet « Vos décisions »** (`vos-decisions.json`, rev 38 ; en ligne rev 43)
- N54. Ligne `.121251` (12/11 vers 03 h 20) : vérifier qu'elle est parmi les « remplacées » de la rev 43 ; sinon « Remplacée le 16/11/2026 par DEMO-COPIE (Q2) ».
- N55. Ligne `.14937` (01/11 à 22 h 10, étapes cachées après une invitation) : **réécrire** selon le choix 13 : l'étape du courrier reste cachée ; « Ouvrir ma démonstration » est proposée aussi à une société invitée par son cabinet.

**Onglet « À appliquer à la fin du logiciel »** (`nouvelle-session/a-trancher/a-la-fin/`)
- N56. F48 (`s5.md:11`) : « Fait (lots SEED et SEED-TOUT). À l'étape 15, il reste à mettre en ligne le site de démonstration (voir A5). »
- N57. NAV (`s2.md:7`) : « NAV-3 : catalogue, achats, stock, équipe et réglages du magasin, profil, notifications. » ; ajouter « Site de démonstration : lien, témoin, bandeau « Vous êtes : » pour chaque rôle de chaque type, « Recommencer à zéro », « E-mails qui seraient partis ». »

**Lots NAV : `nouvelle-session/navigateur/`**
- N58. `plan-nav.md:8`, `:34` : retirer le compte d'entraînement ; la fin de la l. 34 devient la liste de N57.
- N59. `constats-editeur.md:127` à `:137` (C10, C11) : « Sans objet depuis DEMO-COPIE (magasin de démonstration retiré). Le défaut technique reste à vérifier dans les vues qui restent (lots NAV). »
- N60. `constats-editeur.md:94`, `:98` : « (la copie de démonstration permet de s'essayer) ».

**Brouillons et maquettes remplacés**
- N61. `demo-site-lot.md:1` : **marquer** en tête « REMPLACÉ le 16/11/2026 par DEMO-COPIE ».
- N62. `modeles/site-demonstration.html` et `nouvelle-session/reprises/site-demonstration.html` : « Remplacée » dans le titre et en tête de page ; la maquette qui fait foi est `modeles/demo-copie.html` (artefact 855CcM1W2SUsd58taPYk7i).
- N63. Notes historiques (`historique/DIRECTIVES.md`, `demo/decision-demo.md`, `passages/`, `serie8/`, `societe/conception-v2-finale.md`, `t2/`, `t3/decision-heure-locale.md`, `ec2/`, `regle-b/cahier-REGLE-B.md`, `serie9/analyse-a-faire.md`) : rien.

**Ajouts**
- A1. `nouvelle-session/serie9-rapide.js`, après la l. 186, et les listes `LOTS_AVEC_POSTE` (l. 191), `LOTS_APRES_CI` (l. 212), `APRES_CHAINE` (l. 236) : les entrées TVA27, DEMO-COPIE et RLS, dans cet ordre ; l'entrée DEMO-COPIE pointe vers `demo-copie/cahier-DEMO-COPIE.md`, dit l'exception (suite PostgreSQL complète avant le dernier commit), et rappelle aux lots d'avant « aucun code nouveau propre au magasin intérieur ».
- A2. `nouvelle-session/etape3-ajouts.md` : le site de démonstration a ses propres clés, jamais celle de la caisse réelle, et `caisse:diagnostiquer` le vérifie sur chaque site ; le registre d'une copie est fictif et disparaît au 30e jour ; l'attestation et la notice ne couvrent que le site réel, la notice décrit le site de démonstration en une phrase (N51) ; plus de JET 100 ni 105, numéros jamais redonnés.
- A3. `a-faire-apres-chaine.md`, section « Semis » de TVA27 (l. 207 à 212) : « 6. Les sociétés des trois modèles (lot DEMO-COPIE) ont un régime de TVA choisi et une réponse au crédit de départ. MAISON FERRAND montre un crédit reporté. »
- A4. `nouvelle-session/superpdp-plan.md` : dans une copie, « Connecter la plateforme agréée » mène au faux parcours ; aucune clé SUPER PDP sur le serveur de démonstration.
- A5. Onglet « À appliquer à la fin du logiciel » : la liste de E.11.
- A6. Étape 12 : le site de démonstration n'est jamais facturé ; une société non validée crée sa copie ; la limite du nombre de copies actives se décide là (C38).
- A7. Étape 13 : la liste des copies (type, date de fin, taille ; jamais le nom de la société sur le site de démonstration, qui ne le connaît pas : la correspondance se lit sur le site réel par la référence), « Supprimer une copie », « Refaire les bases modèles », les échecs de la reconstruction ; chaque geste au journal d'audit ; l'éditeur ne lit jamais une copie sans un geste audité.
- A8. Étape 9 : une copie ne contient aucune donnée personnelle de la vraie société ; le site réel ne garde que l'empreinte du lien, le type et les dates ; la ligne `demonstrations` reste avec la société (état « terminée », sans lien), le journal d'audit suit sa durée ; ce qu'un visiteur tape dans sa copie meurt avec elle (C.17).
- A9. `campagne/strategie/finale.md`, paliers de volume : un palier du site de démonstration (durée de la copie d'un modèle, place prise par une copie, nombre de copies que la machine tient) ; le résultat sert au coût d'hébergement (A5, A6).

**Le document de pilotage** : la ligne du lot DEMO-SITE devient DEMO-COPIE (même place), avec le lien de ce cahier et de la maquette.

### I. Le compte rendu attendu (un par lot ; le dernier reprend les trois)

1. **La durée**, comparée à l'estimation. L'estimation donnée à l'éditeur le 15/11 (10 à 14 heures) était trop basse, et il faut le lui dire franchement : le retrait seul (partie D) touche environ 120 points de code dans 60 fichiers, 119 tests (56 supprimés, 54 réécrits), 305 citations de l'inventaire, la matrice, CLAUDE.md et le README ; s'y ajoutent une seconde application (mode du site, registre, rôles, construction de trois modèles, copie par `TEMPLATE`, routage par requête et par travail, messages et billet signés, témoins, bandeau dans trois panneaux, e-mails capturés, tâches par copie, archive avant suppression, état publié, mises à jour), le découpage du semis en modèles, une trentaine de fichiers de tests nouveaux avec un second serveur en navigateur, et une soixantaine de notes à corriger. Au rythme des lots passés (3 à 6 heures l'un, relecture et correction en plus), **la nouvelle estimation est de 45 à 65 heures** : DEMO-COPIE-1, 10 à 14 heures ; DEMO-COPIE-2, 18 à 26 heures ; DEMO-COPIE-3, 15 à 22 heures ; relectures et corrections comprises. Chaque lot compare sa durée à sa part ; s'il la dépasse, la cause en une phrase.
2. **Les commits**, leur message, et la CI de chacun.
3. **Le retrait** : la commande `git grep` de la fin du lot 1 et son résultat vide ; le nombre de tests supprimés (avec la règle de chacun), réécrits et gardés.
4. **Les choix tranchés** : les 34 du début, et tout autre point tranché pendant le lot, chacun avec sa raison ; ceux qui touchent la loi, écrits dans l'onglet « À faire vérifier par un professionnel » ; les réponses de l'éditeur à `questions-editeur.md`, et ce qu'elles ont changé.
5. **Les mesures** : pour chaque modèle, la durée de sa construction, sa taille et celle de son dossier ; la durée d'une copie avec chaque stratégie de PostgreSQL, et le volume qu'elle écrit dans le journal WAL ; la durée et la taille d'une archive ; la durée d'une passe des tâches sur une copie neuve et sur une copie de 20 jours ; la durée du modèle de test et du groupe `copies` ; la formule de la place disque.
6. **Les données semées et le compte qui les voit** :
   - pour chaque modèle, sa société, ses nombres (magasins, comptes, documents, e-mails) et les comptes de son bandeau ;
   - chaque geste remplacé dans un modèle (F.1), avec son test ;
   - les sociétés et les cas hors des modèles (F.2), avec leur raison ;
   - sur le site réel (semis du poste), chaque état des demandes de F.7, avec la société, le compte (propriétaire, adresse de la liste des comptes) et la page (tableau de bord de la société ou du cabinet, journal d'audit) ;
   - le registre semé du site de démonstration (F.8) et le lien de chaque état ;
   - ce qui ne se sème pas, avec sa raison (F.9) ;
   - ce qui est sorti du semis (magasins de démonstration, Romain Lefort, Karim Haddad, Zoé Marchand) et ce qui y est entré (magasin de Fort-de-France de MAISON FERRAND) ;
   - les nouveaux nombres du semis (`NOMBRES_DE_LIGNES`).
7. **La vérification des numéros fictifs** : la date du fichier public du répertoire Sirene, la commande, le résultat (aucun SIREN ni SIRET du semis ni de la plage réservée trouvé), la vérification des adresses (`exemple.test`) et des téléphones (plages de fiction).
8. **Les vérifications** : chaque commande de tests lancée avant chaque commit, avec son code de sortie ; la suite PostgreSQL complète avant le dernier commit de chaque lot (code 0) ; le groupe `copies` ; le groupe `concurrence` ; `migrate:fresh --seed` au code 0, `app:verifier-isolation` à zéro problème ; `demonstration:construire-les-modeles` réussi pour les trois types sur le poste de travail ; une archive faite, vérifiée et relue.
9. **L'outillage** : `ext-sodium` ajoutée, avec sa raison (règle « Outillage ») ; les rôles `demo_admin` et `demo_app` de la base de test et de la CI.
10. **« À faire sur votre PC »** :
    1. `git pull`
    2. `composer install` (l'extension `sodium` de PHP doit être active : `php -m` la liste)
    3. dans `.env` : `PILIQI_SEMIS_MOT_DE_PASSE` à la place de `PILIQI_DEMO_MOT_DE_PASSE` (même valeur)
    4. `php artisan migrate:fresh --seed`
    5. pour essayer le site de démonstration à côté, une fois :
       - créer les deux rôles, en tant que superutilisateur PostgreSQL : `CREATE ROLE demo_admin LOGIN CREATEDB PASSWORD '…'; GRANT pg_signal_backend TO demo_admin; CREATE ROLE demo_app LOGIN PASSWORD '…';` ;
       - créer la base du registre et la base sentinelle : `CREATE DATABASE piliqi_demo_registre OWNER demo_admin;` et `CREATE DATABASE demo_aucune_copie OWNER demo_admin;` (elle doit rester sans table) ;
       - copier `.env.demonstration.example` en `.env.demonstration` : `APP_ENV=demonstration` (le nom du fichier : `php artisan serve` ne passe à son enfant que `APP_ENV`, et l'enfant relit `.env.<APP_ENV>` ; sinon le site du port 8001 tournerait avec le `.env` du réel), `PILIQI_SITE=demonstration`, `APP_URL=http://localhost:8001`, `MAIL_MAILER=demonstration`, `DB_USERNAME=demo_app`, `DB_DATABASE=demo_aucune_copie`, les identifiants de `demo_admin` et du registre, et ses propres clés ;
       - dans `.env` (réel) : `DEMONSTRATION_URL=http://localhost:8001` ;
       - `php artisan demonstration:generer-cle` : il écrit la clé privée des messages dans `.env` et sa clé publique dans `.env.demonstration`, et la paire de l'état publié dans l'autre sens ;
       - `php artisan --env=demonstration caisse:generer-cle` : la clé de caisse de démonstration, épinglée dans `resources/caisse-securisee/cles-demonstration` sans être versionnée, comme celle du réel ;
       - la clé du journal d'audit de démonstration : `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"` dans `JOURNAL_AUDIT_CLE` de `.env.demonstration`, puis `php artisan --env=demonstration journal-audit:epingler-la-cle` ;
       - `php artisan --env=demonstration migrate --database=registre --path=database/migrations-registre` ;
       - `php artisan --env=demonstration demonstration:construire-les-modeles` (plusieurs minutes) ;
    6. pour le lancer, à chaque fois, en plus du site réel (`php artisan serve` sur `http://127.0.0.1:8000` et `php artisan queue:listen`) :
       - `php artisan --env=demonstration serve --host=localhost --port=8001` (le pied de page doit dire « site de démonstration »)
       - `php artisan --env=demonstration queue:listen` (files des copies) et `php artisan --env=demonstration queue:listen --queue=demonstration-admin` (créer, recommencer, supprimer)
       - `php artisan demonstration:renvoyer-les-demandes` (une fois après chaque semis : il crée sur le site de démonstration les copies des sociétés semées), puis `php artisan --env=demonstration demonstration:semer-les-etats` (F.8)
    7. puis, connecté sur `http://127.0.0.1:8000` comme propriétaire de MAISON FERRAND (adresse de la liste des comptes), « Ma démonstration » → « Ouvrir ma démonstration ». Les deux sites gardent chacun leur connexion : `127.0.0.1` et `localhost` sont deux noms d'hôte, et leurs témoins ne se mêlent pas.
11. **La réponse à votre question des mises à jour**, en mots simples, pour la page privée du lot : « Il n'y a qu'une version de PILIQI, pour le site réel et pour le site de démonstration, mise à jour en même temps. Avant la mise en production, il n'y a que votre poste : à chaque mise à jour qui change la base, tout repart de zéro, liens compris, et on recrée sa démonstration. Après la mise en production, chaque mise à jour garde la base réelle et chaque démonstration (les mêmes migrations passent sur les deux), et une démonstration qu'on ne peut pas mettre à jour repart de zéro, avec le même lien et un message. Chaque nouveauté du logiciel arrive dans les démonstrations par le semis, au plus tard le lendemain. »

### Précisions de l'éditeur reçues après la contre-lecture (16/11/2026, entre 2 h et 2 h 30, heure de Paris), qui font foi

1. **Chaque société a sa propre copie.** Deux sociétés du même type partent du même modèle, puis chacune n'écrit que dans sa base ; rien de l'une ne se voit chez l'autre. Test : deux copies du même modèle, une facture émise dans l'une n'existe pas dans l'autre.
2. **La nuit, seuls les trois modèles sont refaits ; jamais une copie de société.** Une copie n'est supprimée qu'au bout de ses 30 jours, par « Recommencer à zéro », par la fin de la société (close ou révoquée), ou à une mise à jour tant que PILIQI n'est pas en production (partie E). Test : la reconstruction des modèles laisse chaque copie existante intacte (mêmes lignes, même empreinte du registre).
3. **Passer sur un compte, c'est agir en son nom, comme en vrai** : les ventes faites en « Vendeuse » sont à son nom, dans sa caisse personnelle, son relevé et « Mes ventes » ; le propriétaire ne vend jamais.
4. **On crée des membres comme en vrai** : le propriétaire crée administrateurs, responsables, vendeurs, observateurs et l'expert-comptable déclaré ; un responsable crée les vendeurs et observateurs de son magasin ; un administrateur autorisé nomme un responsable ; cases, suspension, retrait, accès redonné, avec les mêmes refus et messages. Le compte créé reçoit son identifiant de 8 chiffres (tiré dans la copie, sans lien avec les identifiants du site réel), s'ajoute aussitôt au bandeau « Vous êtes : », n'existe que dans la copie et n'ouvre jamais le site réel ; l'e-mail du lien de mot de passe de l'expert-comptable va dans « E-mails qui seraient partis ». Tests : création par chaque rôle autorisé, refus pour chaque rôle non autorisé, nouveau compte présent dans le bandeau, identifiant de la copie refusé à la connexion du site réel.

## Précision de l'éditeur du 16/11/2026 vers 23 h 59 (Paris) : toutes les cartes du tableau de bord ouvertes dans la démonstration

Mots de l'éditeur : « tu oublie pas que dans le demo tu laisse tout les carte ouvertes afin que la societe voit tout de suite toute. et en reel il faut qq carte prancipal ouverte au demarrege donc au debut. »

À faire au lot DEMO-COPIE-3 (ou au lot 2 s'il n'est pas encore commité et que c'est simple) :
- Sur le site de démonstration (SiteDePiliqi démonstration), les réglages par défaut du tableau de bord (ReglagesDuTableauDeBord::parDefaut) ouvrent TOUTES les cartes que le compte peut voir sur cet écran (Graphique::cases() filtrés par ce que le périmètre et les droits permettent), dans l'ordre du catalogue, chacune à son type par défaut ; règle générale, pas une liste figée, pour que les graphiques ajoutés plus tard s'y ouvrent aussi.
- Le site réel garde ses quelques cartes principales au départ (Ventes et achats HT par mois, TVA à payer par mois, CA HT par mois, CA par magasin s'il y en a plusieurs).
- Une préférence déjà enregistrée par le compte prime, comme aujourd'hui (le compte qui ferme une carte la retrouve fermée) ; « Recommencer à zéro » efface ces préférences avec la copie.
- Tests : en mode démonstration, chaque compte du bandeau qui voit les graphiques ouvre son tableau de bord avec toutes ses cartes permises ; sur le site réel, le défaut ne change pas.

## CI rouge de 52dab0ff (« Relecture du lot DEMO-COPIE-2 ») — à réparer EN PREMIER par le lot DEMO-COPIE-3

Relevé le 17/11 vers 00:35 UTC sur les courses 43988119548 (push) et 43988127646 (PR). Une seule cause pour toutes les tâches rouges (Pint, Analyse statique et seuils, Analyse des flux de données, PHP 8.3/PG 17, PHP 8.3/PG 18 ; les autres tâches de tests ont été annulées par l'arrêt au premier échec, sauf l'audit des dépendances, vert) :

- L'étape « Install Composer dependencies » échoue : `composer install` lance `php artisan package:discover` (script post-autoload-dump) AVANT l'étape « Copy environment file ». Sans `.env`, l'application démarre avec APP_ENV par défaut (production) et `SiteDePiliqiServiceProvider` (ligne 214) lève `SiteMalConfigureException` : « Ce serveur ne peut pas servir le site réel : en production, le témoin de session porte le préfixe __Host- (SESSION_COOKIE=__Host-piliqi-session), avec SESSION_SECURE_COOKIE=true, sans SESSION_DOMAIN, sur le chemin /. »
- Conséquence aussi hors CI : tout `composer install` sur une copie neuve du dépôt sans `.env` (ou une image de déploiement construite avant la pose de sa configuration) échoue de la même façon.

À faire, dans un commit à part « Réparation de la CI rouge de 52dab0ff », avant les commits du lot :
1. Reproduire : copie propre du dépôt sans `.env`, `composer install` (ou `php artisan package:discover`) → code 1 avec ce message.
2. Corriger la cause, pas seulement la CI : une commande qui ne sert rien (`package:discover`, et toute commande lancée par les scripts de Composer) ne doit pas être refusée par la garde de démarrage, alors que la garde reste entière pour servir des pages, le travailleur, le planificateur et les commandes qui écrivent (CLAUDE.md, Outillage : « chaque site refuse au démarrage les réglages de l'autre »). Choisir la forme la plus simple et la dire dans le commit (décision de Claude, D76). Mettre aussi l'étape « Copy environment file » avant « Install Composer dependencies » dans chaque tâche de `.github/workflows/tests.yml` si cela reste utile, sans retirer la correction du code.
3. Test qui échoue sur 52dab0ff : `package:discover` sans configuration de production passe ; une vraie requête et `queue:work`/`schedule:run` restent refusés avec la même configuration fautive.
4. Vérifier au code 0 : le test, SiteDePiliqiTest, OutilDeQualiteTest (s'il lit le flux de la CI), puis relire la CI de la tête juste avant le commit du lot.
