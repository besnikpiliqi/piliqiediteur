# À faire après la chaîne société (petits points remontés par l'éditeur en développement local Windows/XAMPP)

**MODÈLE M6 : LIGNE DU PU HT EXACT : OUI — l'éditeur a choisi M6-b le 14/11/2026 vers 02 h 45 UTC (lot REGLE-B, cahier regle-b/cahier-REGLE-B.md § 4).** Sous le nom de chaque article d'un document qui montre le PU HT, une petite ligne grise « PU HT exact : 8,325000 € » donne le PU HT enregistré (PU TTC ÷ (1 + taux), 6 décimales, celui de la facture électronique). La colonne PU HT montre le PU HT d'un article (PU TTC − TVA d'un article arrondie : 8,32 €). La note de calcul ajoute : « Le PU HT exact, écrit sous chaque article, est celui de la facture électronique : la quantité × ce prix retombe sur le total HT de la ligne à quelques centimes près. » Modèle : https://claude.ai/artifact/QwUP3rwrUKX3BQmjB1y52v (M6-b). Tests PDF : la petite ligne présente, avec 6 décimales, sous chaque article et chaque service.


1. SignataireFiscal::genererUnePaire() (et toute génération ou lecture de clé OpenSSL) : inclure les messages de openssl_error_string() dans l'exception et indiquer, sous Windows, que PHP ne trouve pas openssl.cnf (variable OPENSSL_CONF). caisse:diagnostiquer : vérifier que la génération d'une clé EC fonctionne et afficher la piste OPENSSL_CONF. README : note Windows (OPENSSL_CONF vers C:\xampp\apache\conf\openssl.cnf ou C:\xampp\php\extras\openssl\openssl.cnf). Test : message d'erreur explicite (simulé).
2. Proposé à l'éditeur, à faire seulement s'il le confirme : une commande `composer run dev` propre à PILIQI, sans Node, qui lance serve, queue:listen et schedule:work ensemble.
3. README : note Windows/XAMPP (extensions à activer dans php.ini, dont pdo_pgsql ; ne jamais lancer composer update ; SQLite en développement).
4. README, installation : ajouter la clé du journal d'audit (JOURNAL_AUDIT_CLE et JOURNAL_AUDIT_CLE_ID, générée par php -r "echo base64_encode(random_bytes(32)), PHP_EOL;") aux étapes d'installation, à côté de caisse:generer-cle : sans elle, la confirmation d'inscription échoue (JournalAuditNonConfigureException, constaté par l'éditeur en local). Envisager que caisse:diagnostiquer (ou un diagnostic général) signale aussi l'absence de cette clé, avec un test.
   Point 4, suite (ajouté le 26/10/2026 à la demande de l'éditeur, à faire dans le lot L1 ; PHRASE DE CLAUDE.md APPROUVÉE PAR L'ÉDITEUR le 26/10/2026, réponse « Oui, l'ajouter » à la question sur la phrase de l'étape 15) : le fuseau par défaut de la base PostgreSQL en UTC ne doit plus être une action manuelle à retenir pour la mise en production. Une migration (ou le mécanisme le plus simple et le plus sûr que tu trouves, justifié dans le compte rendu) fixe, sous PostgreSQL seulement, le fuseau par défaut de la base courante à UTC (ALTER DATABASE sur current_database(), par exemple par un bloc DO avec format('%I')) ; si le rôle qui lance les migrations n'en a pas le droit, la migration échoue avec un message explicite en français qui dit quoi faire, pour que le déploiement s'arrête de façon visible au lieu de l'oublier en silence. caisse:diagnostiquer garde son contrôle de pg_settings.reset_val. README : la commande manuelle disparaît des étapes à retenir et la note dit que les migrations s'en chargent. CLAUDE.md, étape 15 de la feuille de route : ajoute que le fuseau par défaut de la base est fixé à UTC par les migrations et contrôlé par caisse:diagnostiquer. Tests sous PostgreSQL : après les migrations, reset_val vaut UTC ; sous SQLite, rien ne change ; le message d'échec est explicite (échec simulé).

## Constat de l'éditeur du 12/11 vers 00 h 30 UTC (captures de l'espace de la société) : la démonstration après la validation

> **Fait (lot DEMO-V). Dépassé le 16/11/2026 par DEMO-COPIE : le magasin de démonstration du compte réel est retiré (Q2). T115 n'a plus d'objet.**

**LOT DEMO-V : TOUT ce qui suit, jusqu'au titre « ## Lot « ER » », fait partie du lot et fait foi (note du 14/11/2026 vers 02 h 20 UTC).** Ordre de travail :
1. CI rouge de 6bf1d94 (RequeteDuModeleTest), commit à part, en tout premier.
2. Défaut « En retard » sur une facture payée.
3. PU HT affiché d'une vente = PU TTC − TVA d'un article arrondie (précision de 02 h 10, qui remplace l'arrondi simple de 00 h 35).
4. « Relancer » caché et refusé pour le client comptoir.
5. Tableaux des fiches au modèle D (les 19 listes en cadres seulement).
6. La démonstration après la validation (T115, puce « Proposé » ci-dessous).
7. La notification qui s'affiche toute seule (modèle A, ajout de 02 h 20 en fin de section).
8. La fenêtre « Ajouter des produits » (et celle des clients) : le tableau défile, le haut et le bas restent visibles (ajout de 03 h 45 en fin de section).
Un seul commit pour les points 2 à 8 ; le point 1 a son commit à part.
- Ses mots : « on peut toujours passer en démonstration malgré que le premier magasin est ouvert et la société confirmée par le code du courrier » ; « affiche toujours les téléchargements que j'ai faits avec la démonstration, qui normalement ne doit pas exister et non afficher, car c'est comme ça la règle : tout ce qui est fait ou créé sur démonstration ne doit jamais passer en magasin vrai quand la société confirme avec le code ».
- Code (3dd8705) : le bouton « Rouvrir le magasin de démonstration » n'apparaît que si SocietePolicy::ouvrirLaDemonstration() est vrai, donc `validee_le` nul ; s'il le voit, sa société n'est pas validée dans sa base (à confirmer avec lui : page Vérification ou tinker). En revanche, réel : DocumentsDeLaPeriode::magasinsDeLaSociete() / peutTelechargerDans() garde le magasin de démonstration après la validation (`est_demonstration || estValidee()`), la section « Démonstration (terminée) » et son lien restent sur le tableau de bord, et « Vos téléchargements des sept derniers jours » montre les demandes faites sur la démonstration.
- Proposé (T115, applique-la si l'éditeur ne répond pas) : une fois la société validée, la démonstration disparaît de tous les écrans de la société et de ses magasins (section du tableau de bord, liste des magasins, téléchargement par période de la société, du cabinet, liste « Vos téléchargements » pour les demandes qui ne portent que sur elle, changement de magasin), et plus personne n'y entre ; ses données restent en base, sans rien effacer (registre, journal des événements, JET 100/105, conservés pour la caisse sécurisée et l'attestation). Lot « DEMO-V », avec le lot ER, après la chaîne ; tests : société validée → aucune trace de la démonstration dans ces écrans, URL directe refusée (404), données toujours en base.
- Sur son PC, les téléchargements « en préparation » attendent le travailleur de la file : `php artisan queue:listen telechargements --timeout=1800` (README, Lancement).


**Ajout du 13/11/2026 à 21 h 35 UTC, dans ce même lot DEMO-V : les tableaux des fiches (d'abord la clôture de caisse)** (question de l'éditeur, capture de `/app/{magasin}/cloture-caisses/{id}`).

- **Le problème :** la section des encaissements montre chaque mode dans un cadre à part, qui répète ses titres (« Mode de paiement », « Paiements », « Contre-passations », « Montant encaissé »).
- **Ce que l'éditeur veut (13/11, vers 21 h 45 UTC) :** un seul tableau, les titres en haut, une ligne par mode, le tout dans la même table. Avant toute application, il choisit le modèle parmi ceux que Claude lui propose (directive du 13/11 : chaque modèle d'écran lui est proposé avant d'être appliqué).
- **Modèles proposés :** https://claude.ai/artifact/5hANusG3Xj7Xuy7Mp7L56C, copie dans `../modeles/encaissements-cloture.html`. Ce sont quatre modèles, chacun avec ses titres en haut :
  - A : les cinq modes, les « 0 » en gris, le total sous le tableau ;
  - B : les cinq modes, le total et le crédit d'avoir dans le pied du tableau ;
  - C : seulement les modes utilisés, le total dans le tableau, une phrase pour les autres modes ;
  - D : les cinq modes, un tiret à la place des 0, le total dans le tableau.
- **MODÈLE CHOISI PAR L'ÉDITEUR : D (13/11/2026, vers 22 h UTC, « je choisis le D »), pour tous les tableaux.**
  - Un seul tableau, avec les titres une seule fois en haut et une ligne par élément.
  - Toutes les lignes restent, dans l'ordre fixe.
  - Une valeur nulle s'affiche « – » (tiret), en gris : nombre 0, montant 0,00 €, contre-passations 0. La ligne entière passe en gris quand toutes ses valeurs sont nulles.
  - Le total (« Total encaissé », « Net des ventes aux particuliers ») est la dernière ligne du tableau, en gras, dans les mêmes colonnes.
  - Les lignes d'information (« Crédit d'avoir utilisé (n'entre pas dans les encaissements) », « Factures aux professionnels (pour information) », « Avoirs sur des factures (pour information) ») viennent sous le total, en gris, avec « – » pour une valeur nulle.
  - Les nombres et les montants sont alignés à droite, avec des chiffres de même largeur.
  - Sur un téléphone, le tableau défile de côté dans son cadre.
  - Ce n'est que de l'affichage : jamais le contenu signé ni un montant enregistré ; un export CSV garde des valeurs chiffrées (0, 0,00), sans tiret.
  - Modèle de référence : `../modeles/encaissements-cloture.html`, section D.
  - **Portée, précisée par l'éditeur le 13/11 vers 22 h 25 UTC** (« ce qui était en tableaux c'est bon, comme par exemple les factures de vente, devis etc. sont bonnes, donc tu ne les touches pas ») :
    - seules les listes affichées aujourd'hui en cadres, une ligne par cadre avec ses titres répétés (`RepeatableEntry` des fiches, 19 listes dans 9 fichiers), deviennent des tableaux du modèle D ;
    - tout ce qui est déjà un tableau ne change pas, ni sur l'écran, ni dans le tiret, ni par une ligne de total : les 24 listes (Factures, Devis, Clients, Produits, Paiements…) et les tableaux des tableaux de bord et des espaces de la société et du cabinet ;
    - la question L1/L2 est sans objet.
  - **Hors du modèle :** les PDF des documents légaux (factures, tickets, avoirs, devis, reçus) gardent leur présentation, qui a ses propres règles (CLAUDE.md, « Documents de vente ») et ses tests d'empreinte. Le tiret n'y remplace jamais un 0.
  - Sinon, applique exactement le modèle choisi à **tous les tableaux de PILIQI** qui montrent aujourd'hui une ligne par cadre en répétant leurs titres (demande de l'éditeur du 13/11 vers 21 h 50 UTC : « pour tous les tables il faut que tu appliques pareil »).
    - Ce sont les `RepeatableEntry` des fiches : 19 listes dans 9 fichiers de `app/` au 13/11, par exemple « Ventes et avoirs », « Encaissements par mode » et le détail des documents de la clôture, le relevé d'une caisse personnelle, ou l'aperçu de l'e-reporting.
    - Pars de `git grep -n "RepeatableEntry::make" app` et traite chaque liste.
    - Le modèle s'applique aussi aux PDF et CSV des clôtures, s'ils listent ces rubriques.
    - Un composant commun (vue ou entrée Filament) pour tous ces tableaux, plutôt que 19 copies.
    - L'éditeur peut avoir choisi un modèle différent pour certains tableaux : suis exactement ce que dit la ligne du choix.
- **Ce qui ne change pas :**
  - Le contenu signé de la clôture et ses totaux enregistrés : seul l'affichage change.
  - Rien de cela n'est envoyé à l'administration.
- **Tests :** ceux du modèle choisi (ordre des lignes, modes à 0 selon le modèle, total, crédit d'avoir), un test en navigateur et l'inventaire des actions.


**Ajout du 13/11/2026 vers 22 h 10 UTC, dans ce même lot DEMO-V : « Relancer » sur un ticket du client comptoir.** Constat de l'éditeur, sur la fiche d'un ticket du client comptoir « En retard ». Décision de Claude (D74), montrée à l'éditeur.

- **Le constat :** le bouton « Relancer » s'affiche alors que le client comptoir est anonyme. Il n'a pas d'adresse, personne ne peut être relancé, et la relance automatique l'ignore déjà, faute d'adresse.
- **Ce qui change :**
  - « Relancer » est caché sur tout document du client comptoir.
  - `EnvoyerDocumentAction::relancer()` le refuse avec une alerte claire, même par une requête forgée : « Un ticket du client comptoir ne se relance pas : le client comptoir est anonyme. »
- **Ce qui reste :**
  - « Envoyer par e-mail » reste possible, pour un ticket donné par e-mail au client présent.
  - Le statut « En retard » reste : il dit qu'un montant reste dû après l'échéance, ce qui vaut aussi pour le client comptoir.
- **Tests :** domaine (refus), Filament (bouton caché pour le client comptoir, visible pour un client nommé avec une adresse), navigateur, inventaire des actions.
- **Ce qui ne change pas :** « Noter le bordereau de détaxe (BVE) » reste sur un ticket « Vente en France ». La détaxe est justement une vente en France, à la TVA française, à un voyageur qui part hors de l'Union, et la TVA ne se rembourse qu'après le visa. Le bouton se cache déjà quand la nature n'est pas « Vente en France », quand le ticket est en franchise ou quand il n'a aucun bien taxé.


**Précision de l'éditeur du 14/11/2026 vers 02 h 10 UTC, qui remplace la façon d'arrondir de l'ajout ci-dessous (À FAIRE ainsi).** Sur l'exemple du savon à 9,99 € TTC à 20 %, la colonne PU HT montrait 8,33 € (8,325 arrondi) et le total HT de la ligne 8,32 € : « hallucinant, 2 prix différents ». Ses mots : « ce que j'ai dit c'est pour 8.32 et toi tu appliques tous les endroits pareil ».
- **Règle d'affichage du PU HT d'une vente :** PU HT affiché = PU TTC − TVA d'un article, cette TVA étant PU TTC × taux ÷ (1 + taux) arrondie une fois au centime le plus proche (un demi-centime exact au centime supérieur). 9,99 € à 20 % : TVA 1,665 → 1,67 €, PU HT **8,32 €**. 10,00 € à 20 % : TVA 1,67 €, PU HT 8,33 €. 0,99 € à 20 % : TVA 0,17 €, PU HT 0,82 €.
- Le même PU HT partout : fiche du produit ou du service, caisse, lignes des formulaires de vente, fiches et listes, PDF (factures, avoirs, devis, ventes sans TVA française), aides et messages. Un seul calcul, dans une seule fonction du domaine, que tous les écrans et le PDF appellent ; un test refuse tout autre arrondi du PU HT des ventes.
- Aucun montant ne change : les totaux HT, TVA et TTC restent ceux enregistrés. Le PU HT enregistré à 6 décimales reste celui de la facture électronique (BT-146, `item_net_price` en texte chez SUPER PDP).
- La note de calcul du PDF le dit : « Le PU HT affiché est celui d'un article : prix TTC moins sa TVA arrondie au centime (9,99 € TTC à 20 % : 8,32 € HT). La quantité × ce prix peut différer de quelques centimes du total HT de la ligne, calculé sur toute la quantité. »
- Tests : 9,99 € → 8,32 € sur la fiche, la caisse, le formulaire, la fiche du document et le PDF ; 10,00 € → 8,33 € ; 0,99 € → 0,82 € ; taux 5,5 % et 10 % ; un document dont le total HT est inchangé.
- Exemple montré à l'éditeur : scratchpad/modeles/exemple-calcul-des-prix-15-lignes-regle-b2.pdf.

**Ajout du 14/11/2026 vers 00 h 35 UTC, dans ce même lot DEMO-V : le PU HT des ventes s'affiche arrondi au centime, partout.** Décision de l'éditeur, qui remplace sa décision du 09/11/2026 et la note de calcul de T105 (12/11), ce qui se reporte à la matrice. Ses mots : « le prix HT "11,279621" me plaît pas car beaucoup de nombres […] non non il faut arrondir ».

- **Ce qui change :** le PU HT d'une ligne de vente s'affiche arrondi au centime le plus proche (un demi-centime exact au centime supérieur), comme le PU TTC, sur tous les documents et tous les écrans :
  - factures, avoirs sur facture et devis à un professionnel ;
  - ventes sans TVA française ;
  - leurs fiches, les lignes de leurs formulaires, la page publique du devis, leurs PDF.

  Exemple : 11,279621 € devient 11,28 €.
- **Ce qui ne change pas :**
  - le PU HT reste **enregistré** avec ses six décimales ;
  - tous les montants (HT, TVA, TTC de chaque ligne et de chaque taux) restent calculés une seule fois, depuis les prix TTC, et ne changent pas d'un centime ;
  - un champ où un prix se saisit le montre toujours tel qu'il est enregistré ;
  - le PU HT des **achats**, recopié de la facture du fournisseur, et le coût unitaire d'un inventaire d'ouverture s'affichent comme le fournisseur les écrit. Ce point est à confirmer avec l'éditeur dans le compte rendu.
- **Note de calcul** (PDF et fiches) : elle ne dit plus que la quantité × le PU HT retombe sur le total HT. Elle dit, pour tout document, que les totaux se calculent depuis les prix TTC, sans arrondi intermédiaire, et que la quantité × le prix unitaire affiché, arrondi au centime, peut s'écarter de quelques centimes du total de la ligne. Exemple pour le PU HT : 20 × 11,28 € = 225,60 €, pour un total HT de 225,59 €.
- **Facture électronique (étape 2, à noter dans le plan SUPER PDP) :** le prix unitaire envoyé dans le XML sera tranché à cette étape (6 décimales dans le XML pour que la ligne retombe juste, ou centime). Ce lot ne touche à aucune transmission.
- **CLAUDE.md :** réécris les phrases concernées de « Documents de vente » et de « Prix et TVA des ventes ». Ce sont celles qui disent que le PU HT s'imprime « tel qu'il est enregistré, jusqu'à six décimales » et que la note de calcul ne l'imprime que si chaque ligne le vérifie. Écris la nouvelle règle, datée du 14/11/2026.
- **Matrice :** mets à jour la décision du 09/11 et T105, en « remplacee » par celle du 14/11, et les règles concernées.
- **Tests :** réécris ceux qui attendent les six décimales à l'affichage, avec la nouvelle règle de l'éditeur : c'est un changement de règle, pas un affaiblissement. Ajoute un test du PDF et de la fiche (11,279621 → 11,28), un test de la page publique du devis et un test de la note de calcul. Mets aussi à jour l'empreinte des PDF (EmpreinteDeLaCaisseEtDesPdfTest), en le disant dans « faits ».


**CI rouge de 6bf1d94 (lot STAT), à réparer EN TOUT PREMIER (ajout du 14/11/2026 à 00 h 50 UTC).** Course 43531775339 : la tâche « PHP 8.4, PostgreSQL 17 » échoue (6 966 tests, 1 erreur), les cinq autres tâches de tests sont annulées par elle ; « Analyse statique et seuils », « Analyse des flux de données », Pint et l’audit sont verts.
- **Test en échec :** `Tests\Feature\Tenancy\RequeteDuModeleTest::test_la_sous_requete_d_une_relation_vers_soi_meme_est_rendue_elle_meme_inchangee` → `relation "societes" does not exist` à l’insertion de la société.
- **Cause :** le lot STAT (565f1ba) a ajouté à cette classe un test qui appelle `ouvrirUneSociete()` et écrit en base, alors que la classe n’utilise pas `RefreshDatabase`. En parallèle, quand ce test est le premier de son processus à toucher la base, celle-ci n’est pas encore migrée ; ailleurs, ses lignes restent en base hors de toute transaction et peuvent gêner d’autres tests. L’échec dépend de l’ordre : la suite locale a pu passer.
- **Réparation :** `use RefreshDatabase;` dans la classe (comme `BindTenantContextFromFilamentTenantTest`), puis chercher dans `tests/` toute autre classe sans `RefreshDatabase` ni `DatabaseMigrations` qui écrit en base (`ouvrirUneSociete`, `::create(`, fabriques) et la corriger de même. Si possible, ajouter un contrôle (test d’outillage) qui refuse une classe de test qui écrit en base sans l’un de ces traits.
- Vérifier : le test seul (`php artisan test --compact tests/Feature/Tenancy/RequeteDuModeleTest.php`) sur une base vide fraîchement créée, puis la suite PostgreSQL complète. Commit à part, avant le reste du lot.

**Ajout du 14/11/2026 vers 01 h UTC, dans ce même lot DEMO-V, à faire EN PREMIER : défaut « En retard » sur une facture payée.** Constat de l'éditeur sur la liste des factures : le ticket TK-EPICERIE-2026-000001, « Payée » (32,30 € payés sur 32,30 €), montre « En retard » dans la colonne « À faire ».

- **Cause :** `FactureResource::raisonsAFaire()` (app/Filament/App/Resources/FactureResource.php, vers la ligne 1037) écrit « En retard » dès que `estEchue()`, c'est-à-dire dès que l'échéance est passée, même quand plus rien n'est dû.
- **Correction :** écrire « En retard » seulement si la facture attend encore un règlement et qu'un montant reste dû après l'échéance. Utiliser `Facture::estEchueEtImpayee()`, qui suit la règle de CLAUDE.md : « Une facture en retard le reste tant qu'un montant reste dû après l'échéance ».
- **Vérifier les autres usages de `estEchue()`** : `Facture.php` vers les lignes 399 et 929, `ChosesAFaireDuMagasin.php` vers la ligne 256, et tout filtre « En retard » de la liste. Pour chacun, montrer qu'il ne porte que sur des factures qui ont encore un montant dû, et corriger sinon.
- **Tests de régression :**
  - une facture payée en entier après son échéance n'a plus « En retard », ni dans la colonne, ni dans un filtre, ni dans l'« À faire » ;
  - une facture soldée par un avoir non plus ;
  - une facture payée en partie après son échéance garde « En retard » ;
  - un test en navigateur sur la liste.

**Ajout du 14/11/2026 vers 02 h 20 UTC, dans ce même lot DEMO-V : la notification qui s'affiche toute seule (MODÈLE A CHOISI PAR L'ÉDITEUR).** Demande de l'éditeur : « quand une notification arrive en arrière-plan, il faut qu'elle s'affiche automatiquement comme une petite notification afin que le magasin (vendeur, responsable, etc.) la voie […] qui tombe du haut ou qui glisse de la droite ». Modèles montrés : https://claude.ai/artifact/HPKDFk5V2QdVKLFkMaRGWr (scratchpad/modeles/notification-toast.html) ; il a choisi **A**.
- **Ce que voit la personne :** quand une notification de la cloche arrive pendant qu'elle a une page de PILIQI ouverte, une carte glisse depuis le bord droit, en haut à droite, juste sous la cloche (la place des messages « Enregistré » de Filament), et la cloche bouge un instant. La carte porte l'icône, le titre, le texte et les boutons de la notification (par exemple « Voir le devis »), une croix, et une fine barre qui montre le temps qui reste. Elle reste 8 secondes, ou tant que la souris est dessus (le temps repart quand la souris la quitte), puis s'en va. La notification reste dans la cloche ; « Voir » ouvre le document et la marque comme lue, comme dans la cloche.
- **Pour qui :** tout compte qui a la cloche, dans les trois panneaux (magasin, espace de la société, espace du cabinet) : propriétaire, administrateurs, responsables, vendeurs, observateurs, collaborateurs d'un cabinet, expert-comptable de la société. La carte ne montre que ce que la cloche de ce panneau montre à cette personne, par la même requête : jamais une notification d'un autre compte, et, dans le panneau d'un magasin, rien qui laisse deviner un autre magasin ou une autre société.
- **Quand :** PILIQI regarde s'il y a du nouveau au même rythme que la cloche (30 secondes, réglage `config` de PILIQI, sans service extérieur ni WebSocket ; aucune page ne charge rien d'un autre site). Seules les notifications non lues, arrivées depuis la dernière carte montrée à ce compte, s'affichent. Une carte déjà montrée ne revient jamais, même après un changement de page, un autre onglet ou une nouvelle connexion : ce qui a été montré s'enregistre pour le compte seulement (par exemple l'instant de la dernière carte montrée, colonne de la migration de création de la table `users`, sans migration d'ajout), **jamais sur les notifications elles-mêmes**. Une notification déjà lue ne s'affiche pas. À la première ouverture d'une session, les notifications non lues déjà présentes ne déferlent pas : au plus une carte, la plus récente, les autres restant dans la cloche (le regroupement « 3 nouvelles notifications » est le modèle D, non choisi). Plusieurs arrivées au même passage : une carte par notification, empilées, au plus 3 à la fois.
- **Précision de l'éditeur (14/11/2026 vers 02 h 40 UTC) : la cloche et ses notifications ne changent en rien.** Ses mots : « les notifications restent toujours telles qu'elles sont. Moi je dis juste pour qu'elle s'affiche directement, mais pas changer ce qu'il y a dans les notifications ». La carte ne fait qu'avertir. Elle ne marque rien comme lu, n'efface rien, ne change ni l'ordre, ni le nombre, ni le contenu, ni la pastille de la cloche. Fermer la carte (croix, ou fin des 8 secondes) ne touche pas la notification, qui reste non lue dans la cloche. Seul un clic sur un bouton de la carte (« Voir le devis »…) fait ce que fait le même bouton dans la cloche aujourd'hui. Aucune colonne ni écriture n'est ajoutée sur la table `notifications`. Tests : après l'affichage et la fermeture d'une carte, la notification est toujours non lue, toujours dans la cloche, inchangée en base, et la pastille garde son nombre.
- **Accessibilité :** la carte est annoncée aux lecteurs d'écran (rôle « status ») ; avec « réduire les animations », elle apparaît sans glisser et la cloche ne bouge pas. Pas de son.
- **Code :** un composant Livewire unique, ajouté aux trois panneaux par un crochet de rendu, qui réutilise les notifications Filament existantes (durée, position en haut à droite) et le même filtre que la cloche ; aucune notification n'est dupliquée en base. Réécrire proprement ce qui existe si c'est plus simple (CLAUDE.md, « toujours repartir de zéro »).
- **Tests :** domaine ou Livewire (une notification non lue arrive → une carte, une seule fois ; déjà lue → aucune ; une notification d'un autre compte → aucune ; ce qui a été montré tient après une nouvelle session ; au plus une carte à l'ouverture d'une session ; au plus 3 à la fois) ; en navigateur, avec un intervalle court réglé pour le test (une notification créée pendant que la page est ouverte apparaît en haut à droite, avec « Voir », puis disparaît ; la cloche la garde ; « Voir » ouvre la fiche) pour un vendeur, un responsable, le propriétaire dans l'espace de la société et un collaborateur dans l'espace du cabinet ; inventaire des actions tenu à jour. CLAUDE.md, « Écrans » : une phrase qui décrit la règle.

**Ajout du 14/11/2026 vers 03 h 45 UTC, dans ce même lot DEMO-V : la fenêtre « Ajouter des produits » garde son haut et son bas visibles.** Constat de l'éditeur (capture de la caisse, `/app/{magasin}/caisse`) : « quand il y a beaucoup de produits, les boutons en bas disparaissent car le tableau n'est pas en overflow […] la partie au-dessus du tableau des produits doit rester intacte, donc non cachée ».
- **Où :** la fenêtre du sélecteur de produits (`resources/views/filament/app/livewire/selecteur-de-produits.blade.php`, ouverte par `AjouteDesLignesDeVente::actionAjouterUnProduit()` en plein écran) sur la caisse, une facture et un devis. Même correction pour la fenêtre du sélecteur de clients (`selecteur-de-clients.blade.php`, `ClientResource`), et pour tout autre sélecteur en plein écran du même genre.
- **Ce qu'on veut :** la fenêtre tient dans la hauteur de l'écran, sans que la page entière défile.
  - **En haut, toujours visibles :** le titre, l'explication, « Trier », la recherche et le filtre du tableau.
  - **Au milieu :** seul le tableau des produits défile, avec ses titres de colonnes collés en haut de sa zone. Le bouton « Voir 50 de plus » se trouve au bout du défilement.
  - **En bas, toujours visibles :** la liste des produits choisis, avec leurs quantités, la phrase du total et le bouton « Ajouter les N produits ». Quand beaucoup de produits sont choisis, la liste des choisis défile à son tour dans une hauteur limitée, et le bouton reste visible.
- **Code :** une colonne flexible à la hauteur de la fenêtre (par exemple `max-height` en `dvh`), avec la zone du tableau en `overflow-y: auto` et `min-height: 0`, sans rien qui dépende d'un autre site. Le même rendu en clair et en sombre, et sur un écran de téléphone.
- **Tests en navigateur :**
  - avec 80 produits, la fenêtre ouverte sur la caisse à 1280 × 720 : le champ de recherche et le bouton « Ajouter le produit » sont visibles sans défilement de la page ;
  - le tableau défile à l'intérieur de sa zone ;
  - un clic sur la dernière rangée l'ajoute ;
  - le bouton reste visible avec 30 produits choisis ;
  - même contrôle pour le sélecteur de clients.

## Lot « ER » : aperçu de l'e-reporting et règles de CLAUDE.md (étape 1, après la chaîne en cours ; ajouté le 12/11 vers 01 h UTC)
- Origine : décision de l'éditeur du 11/11/2026 (D67, V240 ; Q399 à Q417), écrite dans la colonne « Réponse du professionnel » de 19 lignes de l'onglet « À faire vérifier par un professionnel ». Source unique : `scratchpad/onglet2-844/analyse/plan-rangement.md` (§ 2, § 2 bis, fin du § 3.5) ; textes exacts de l'éditeur : `scratchpad/onglet2-844/lignes.txt`. Petit lot, effort « extra » (D63), un commit, suite PostgreSQL complète une fois avant le commit, deux relectures en parallèle.
- Au lancement, relire dans l'onglet 1 les réponses de l'éditeur aux questions de suite T106 à T114 (« Le plus urgent ») : chaque passage ci-dessous qui dépend d'une question encore sans réponse attend ; la phrase actuelle de CLAUDE.md reste alors telle quelle, sans « à faire » (§ 2 bis).
- Note T105 (note de calcul « à moins de deux centimes près », onglet 1, rev 675) : sans réponse de l'éditeur avant la fin de la chaîne en cours, appliquer sa proposition (a) dans un petit lot à part, avant la campagne de tests 1 bis (il peut partir juste avant ou après ER) : imprimer la phrase seulement quand chaque ligne du document la vérifie, sinon une phrase qui dit qu'un petit écart est possible, et adapter les deux phrases de CLAUDE.md qui la décrivent. Avec une réponse, appliquer sa réponse. RÉPONSE DE L'ÉDITEUR LE 12/11/2026 : (a) — à appliquer.

### CLAUDE.md (§ 2 bis du plan ; texte actuel → texte proposé)
- **R1, puce « Facture électronique (SUPER PDP) »** (T1.1 à T1.4). Actuel : « Le statut « encaissée » d'une facture de services part à chaque paiement, avec le montant et la date, sauf option pour les débits ; les paiements de particuliers pour des services ne sont déclarés qu'une fois la règle confirmée par l'expert-comptable de la société. »
  - À écrire au lot ER (aucune question de suite ne touche ce passage) : « Le statut « encaissée » d'une facture de services part à chaque paiement, avec le montant et la date, sauf option pour les débits ; les paiements de particuliers pour des services, sans option pour les débits, se déclarent dans l'e-reporting des paiements, agrégés par jour d'encaissement : le montant encaissé TTC, ventilé par taux de TVA (une réparation de 60,00 € TTC à 20 % se déclare 60,00 € au taux de 20 %) ; d'une vente qui mêle biens et services, seule la part des services se déclare (décision de l'éditeur du 11/11/2026). »
  - Attend T107.1 (arrondi) et T107.2 (base après un avoir, crédit d'avoir compté comme un paiement) : « ; un paiement partiel se répartit entre les taux de service au prorata des TTC du document d'origine », complété par la règle d'arrondi et la base que l'éditeur aura choisies.
- **R2, même puce** (T2.1, T2.2, T2.3, T2.6, T2.7, T2.8 ; aucune question de suite ne touche la phrase, à écrire au lot ER). Actuel : « Une vente à 0 % n'est pas transmise tant que son motif d'exonération n'est pas géré. » Proposé : « Une vente à 0 % n'est transmise que si son motif d'exonération est géré : l'export hors UE et la livraison vers un DOM (catégorie G), la livraison intracommunautaire (K), la prestation à un professionnel hors UE (E, jamais O) et la vente en franchise en base (E, avec « TVA non applicable, art. 293 B du CGI ») le sont (décision de l'éditeur du 11/11/2026) ; la vente en France à 0 % hors franchise et la prestation à un professionnel de l'UE en autoliquidation ne sont pas transmises tant que leur motif n'est pas géré. » Les codes VATEX, les champs BT et les canaux (T109, T111) vont dans le plan de l'étape 2, pas dans CLAUDE.md.
- **R3, puce « Caisse et clôtures »** (T2.4, T2.5). Actuel : « la fiche montre aussi, en français, l'aperçu de ce que l'e-reporting déclarera pour la journée (totaux par catégorie et par taux, opérations non soumises à la TVA en France comprises). »
  - À écrire au lot ER : « … (totaux par catégorie et par taux, opérations non soumises à la TVA en France comprises) : une vente en franchise en base s'y range en livraison de biens ou en prestation de services, au taux 0 % (décision de l'éditeur du 11/11/2026) »
  - Attend T108.1 : « , et un export à un particulier parmi les opérations non soumises à la TVA en France, au taux 0 % » ; attend T108.2 : « , de même qu'une livraison vers un DOM à un particulier ». Le guichet OSS (T2.9) n'entre dans CLAUDE.md qu'au lot qui le construit (TVA8 = (a)).
- **R4, même puce que R1, phrase nouvelle** (T3.1 à T3.4) : attend T112.1, T112.2 et T112.4, qui en changent le contenu. Proposé : « Le code de date d'exigibilité de la TVA n'est envoyé que pour une société qui a opté pour les débits : sur ses factures électroniques, la date de la facture [portée selon T112.4 : dès qu'elles contiennent un service] ; il est omis partout ailleurs, dans les factures et dans l'e-reporting des ventes aux particuliers [exceptions selon T112.1 et T112.2] (décision de l'éditeur du 11/11/2026). »
- **R5, puce « Achats et stock » ou « Facture électronique »** (T4.5) : attend T113, rien ne s'écrit avant la réponse. Si l'éditeur garde sa décision : « Un achat auprès d'un fournisseur établi à Monaco suit la TVA française, sans autoliquidation, et n'entre pas dans l'e-reporting des achats internationaux (décision de l'éditeur du 11/11/2026). » S'il suit le BOFiP : « …sans autoliquidation ; il se déclare dans l'e-reporting des achats, à la TVA française, sauf des biens déjà entreposés en France. »
- Ne changent pas : les phrases des clôtures confirmées par T1.7 ; « format de conversion AFNOR préféré : Factur-X » (T2.8, tant que T111.2 n'est pas tranché autrement).

### Code de l'aperçu de l'e-reporting (fin du § 3.5 du plan ; dépôt à 3dd8705, lu sans le modifier)
- `app/Domain/Facturation/Services/EReportingDesParticuliers.php` :
  - `MENTION_ENCAISSEMENTS` (l. 88) et son docblock : retirer « En attente de la confirmation de l'expert-comptable de la société. » (T1.1).
  - `MENTION_TRANSMISSION_EN_ATTENTE` (l. 80) : ne plus l'afficher pour l'export (T2.1), la livraison vers un DOM (T2.2) et l'export à un particulier (T2.4, déjà rangé en TNT1) : branche `nature_operation->exonere()` de `transaction()` (l. 270).
  - Franchise (T2.5) : dans `transaction()` (l. 265-269), une ligne en franchise se range par sa nature (`CategorieEReporting::deLaNature` : bien TLB1, service TPS1), au taux 0 ; le motif franchise (`MOTIF_FRANCHISE`) reste comme information, sans `MENTION_CATEGORIE_A_VERIFIER`. La vente en France à 0 % hors franchise (`MOTIF_TAUX_ZERO`) garde « catégorie à vérifier » : la décision ne la couvre pas.
  - Après la réponse à T107.1 et T107.2 seulement : la répartition au prorata (T1.3, T1.4 : part d'un taux de service = paiement × TTC du service à ce taux ÷ TTC du ticket) remplace « Encaissements à répartir » ; supprimer `MENTION_A_REPARTIR` (l. 90) et `encaissementsARepartir` (l. 106-108, et `ApercuEReporting` l. 30, 37, 62). Sans réponse, cette partie attend.
  - Selon la réponse à T112.1 : exclure de l'aperçu les encaissements de services d'une société qui a opté pour les débits (aujourd'hui comptés).
  - Docblocks de la classe à mettre à jour.
- `app/Domain/Facturation/Enums/CategorieEReporting.php` : docblock (franchise rangée par nature).
- `app/Filament/App/Resources/EReportingResource.php` : bloc « Encaissements à répartir » (l. 168-185, et l. 282), retiré avec le prorata.
- Tests : `tests/Feature/Facturation/EReportingDesParticuliersTest.php` et `tests/Feature/Filament/EReportingResourceTest.php` (franchise TLB1/TPS1 au taux 0 sans « catégorie à vérifier » ; vente à 0 % hors franchise toujours « à vérifier » ; plus de « transmission en attente » pour l'export et le DOM ; mention des encaissements sans « attente de l'expert-comptable » ; prorata et arrondi seulement après T107). Le témoin `tests/Fixtures/empreinte-de-la-caisse/apercu-e-reporting.txt` (empreinte de la caisse) change avec l'aperçu : le régénérer en le justifiant dans le commit, jamais pour masquer un écart non voulu.
- Non couvert, pour l'étape 2 sans question : les paiements des services d'un magasin en franchise (filtre `taux > 0`, l. 322), alors que l'art. 242 nonies P prévoit une transmission bimestrielle pour un franchisé.

## Lot « TVA27 » : fin du réel simplifié et crédit de TVA reporté (demande de l'éditeur du 15/11/2026 vers 14 h 10, heure de Paris, « il faut pas oublier »)

**Place : juste après VIES-DEP, avant RLS.** Un seul commit « TVA : régime simplifié retiré et crédit de TVA reporté », relu comme tout lot. Lot de calcul d'argent : exception (c), suite PostgreSQL complète une fois avant le commit.

- **La règle (sources : impots.gouv.fr, actualité « Le régime simplifié d'imposition à la TVA est supprimé à compter du 1er janvier 2027 » ; loi n° 2025-127 du 14/02/2025, art. 38) :** le régime simplifié de TVA (CA12 annuelle et ses deux acomptes) disparaît le 1er janvier 2027 ; ses redevables passent d'office au réel normal (CA3). CA3 trimestrielle si le chiffre d'affaires, majoré des acquisitions taxables, ne dépasse pas 1 000 000 € l'année précédente et 1 100 000 € l'année en cours ; mensuelle au-delà ou sur option. Dernière CA12 : exercice clos au 31/12/2026 (au plus tard le 4 mai 2027) ou exercice clos de janvier à novembre 2027. Le régime simplifié agricole ne change pas (hors de PILIQI). E-reporting à partir du 1er septembre 2027 : au réel normal trimestriel, données de transaction et de paiement une fois par mois ; au réel normal mensuel, transactions par décade et paiements une fois par mois (à l'étape 2, la plateforme agréée tient ce calendrier).
- **Décision de Claude (D76), écrite dans l'onglet « À faire vérifier par un professionnel » :** PILIQI ne sera pas en production avant 2027 et ne garde aucun état antérieur (règles du projet) : le régime « Réel simplifié » est retiré dès ce lot, sans période de transition. Restent : réel normal mensuel (CA3), réel normal trimestriel (CA3), franchise en base.
- **Code :**
  1. `RegimeTva` : retirer `ReelSimplifie` et sa phrase du docblock ; libellés « Réel normal, déclaration mensuelle (CA3) » et « Réel normal, déclaration trimestrielle (CA3) » ; docblock : la cadence de l'e-reporting ci-dessus et la règle des seuils.
  2. `ParametresFiscauxSociete` : aide du champ qui dit le choix (trimestrielle jusqu'à 1 000 000 € de chiffre d'affaires l'année précédente et 1 100 000 € l'année en cours, mensuelle au-delà ou sur option ; le régime simplifié n'existe plus depuis le 1er janvier 2027).
  3. Information sans blocage : une société au trimestriel dont le chiffre d'affaires HT de l'année civile en cours, lu dans PILIQI (tous ses magasins, ventes nettes des avoirs), dépasse 1 100 000 € reçoit, une fois par année, une ligne dans l'« À faire » de l'espace de la société (propriétaire, et administrateur qui peut modifier les réglages fiscaux) : « Votre chiffre d'affaires de l'année dépasse 1 100 000 € : la déclaration de TVA devient mensuelle. Vérifiez votre régime de TVA. », avec « Ouvrir les réglages fiscaux » et « C'est vérifié » (journal d'audit) ; la phrase dit que PILIQI ne voit pas les acquisitions taxables ni les ventes faites hors de PILIQI.
  2 ter. **La démonstration n'est jamais bloquée (précision de l'éditeur du 15/11 vers 15 h 45, Paris : « il faut pas bloquer le compte demo car il peux s entrainer la desus et une fois valider ce que t as montré alors la societe demmarre son activité sur piliqi »).** Les étapes « Choisir le régime de TVA » et « Votre TVA avant PILIQI » se font et se refont librement avant la validation. Elles ne bloquent que l'activité réelle. Pour s'entraîner, la société ouvre sa copie de démonstration, dont la société modèle a déjà son régime et sa réponse au crédit de départ. (Point réécrit le 16/11/2026 par le lot DEMO-COPIE-1, note N2 du cahier DEMO-COPIE : TVA27 passe après DEMO-COPIE, décision de l'éditeur du 16/11/2026 ; le magasin de démonstration intérieur n'existe plus. Les mots de l'éditeur ci-dessus restent.) La première opération réelle (ticket, facture, avoir, encaissement, devis numéroté) exige la société validée, le régime choisi ET la question du crédit de départ répondue (« Non, aucun crédit » ou un solde) ; sinon le refus dit ce qui manque, avec le lien. Avant la validation, le crédit saisi n'est pas encore « à vérifier » par un comptable qui n'existe pas encore : il suit les états dès qu'un comptable arrive. Tests : société validée sans réponse au crédit → refus réel avec le message ; les deux faits → vente réelle permise.
  3 bis. **Régime de TVA obligatoire avant toute opération réelle (constat de l'éditeur du 15/11 vers 15 h 16 ; décision de Claude, D76).** Aujourd'hui rien ne l'exige : une société peut vendre avec « Sélectionnez une option », et PILIQI ne sait pas si elle est en franchise (mention « TVA non applicable, art. 293 B du CGI », ventes à 0 %) ni à quel rythme elle déclare. Désormais : tant que le régime de TVA n'est pas choisi, PILIQI refuse toute opération commerciale réelle (ticket, facture, avoir, encaissement, devis numéroté), avec le message « Choisissez d'abord le régime de TVA de la société dans ses paramètres fiscaux. » (au propriétaire et à l'administrateur qui peut les modifier, avec le lien ; aux autres comptes : « Le régime de TVA de la société n'est pas encore choisi : demandez-le au propriétaire. ») ; garde en base aussi (déclencheur sur l'émission), comme pour l'assujetti unique ; la copie de démonstration n'est pas touchée : autre base, société modèle au régime déjà choisi ; l'« À faire » de l'espace de la société montre « Choisissez le régime de TVA de la société » tant qu'il est vide ; la liste d'étapes après l'inscription l'ajoute avant « créer le premier magasin ». Une fois choisi, le régime ne redevient jamais vide (la validation le refuse déjà : « required »). Tests : refus de chaque opération sans régime, message selon le rôle, garde en base, ligne de l'« À faire », semis.
  4. Tests qui utilisent `RegimeTva::ReelSimplifie` (EmetteurFigeTest, AssujettiUniqueTest, MagasinResourceTest, ParametresFiscauxSocieteTest, `git grep` à refaire) : réécrits avec `ReelNormalTrimestriel` (jamais affaiblis) ; un test que la valeur `reel_simplifie` est refusée à la validation (réglages fiscaux) ; tests de la ligne de l'« À faire » (seuil exact, 1 100 000,00 € n'avertit pas, 1 100 000,01 € avertit, mensuel jamais, une fois par an, « C'est vérifié »).
- **Crédit de TVA reporté (question de l'éditeur du 15/11 : « quand c'est négatif… il passe pour le prochain mois ») :** maquette https://claude.ai/artifact/8UtWBkePzdZVWC6eo33nuo (copie reprises/tva-credit-report.html). **CHOIX DE L'ÉDITEUR : K2 (15/11/2026, vers 15 h 10, heure de Paris, « mon choix est K2 »)** : le report automatique ET la saisie du crédit de départ dès ce lot (point 3 ci-dessous, obligatoire).
  1. `TvaDeLaPeriode` : TVA du mois = collectée − déductible des achats − déductible des charges ; puis TVA du mois − crédit reporté : positive, c'est la TVA à payer, négative, c'est le crédit à reporter sur la période suivante ; au trimestriel, la période est le trimestre civil (ses trois mois s'additionnent avant le report) ; le report part du premier mois de la société dans PILIQI, quel que soit la période affichée (le chiffre d'un mois ne dépend jamais du filtre) ; la réelle et l'estimation ont chacune leur chaîne ; franchise en base : « TVA non applicable », sans report ; montants en centimes additionnés dans le code. Sous le graphique, une phrase grise : « Sans les remboursements de crédit de TVA demandés : ils se noteront avec l'état de TVA. » ; tant que le crédit de départ n'est pas saisi, une seconde phrase : « Crédit de TVA de votre dernière déclaration avant PILIQI : non saisi. », avec, pour qui peut le saisir, le lien vers la page des charges.
  2. Écrans : carte « TVA à payer » + pastille « Crédit de TVA à reporter : X € » ; graphique (à payer au-dessus du zéro, crédit à reporter en dessous, TVA du mois en pointillés) ; « Voir les chiffres » avec les colonnes « Crédit reporté de la période d'avant » et « Crédit de TVA à reporter » ; même chose sous le tableau de la page des charges. Tous les espaces qui montrent cette TVA (société, magasin, cabinet, expert-comptable). Modes clair et sombre (règle du 15/11).
  3. **K2 (choisi) : le crédit de départ, précisé par l'éditeur le 15/11 vers 15 h 35 (Paris)** (ses mots : « quand une societe vient s'inscrit chez piliqi alors il faut afficher un bloc qui demande pour la tva si un credit existe deja sur la societe. donc il faut accepte meme negatif car il peut y avoir deja un credit. et ca doit etre apparu chez la societe et chez le comptable. donc le credit de la tva doit etre conseiller verifier par expert comptable pour ne pas faire des erreurs sur le credit ») :
     - **Quand on le demande :** dès l'arrivée de la société dans PILIQI. La page d'inscription reste telle quelle (SIREN, type, adresse, mot de passe, « rien de plus ») ; c'est la liste d'étapes après l'inscription qui ajoute, juste après « Choisir le régime de TVA » (point 3 bis), l'étape « Votre TVA avant PILIQI : avez-vous un crédit de TVA ? », et l'« À faire » de l'espace de la société la rappelle tant qu'elle n'est pas faite. Le bloc est aussi dans la page des charges (espace de la société, page du client dans l'espace du cabinet, page de l'expert-comptable déclaré ; jamais dans un magasin), pour le voir et le changer ensuite.
     - **Ce qu'on saisit :** une réponse « Non, aucun crédit » (crédit de 0 €) ou « Oui » avec le solde de TVA de la dernière déclaration faite avant PILIQI, écrit comme au tableau de bord, NÉGATIF pour un crédit (−1 250,00 €), et la période de cette déclaration (mois au mensuel, trimestre au trimestriel). Un montant positif est refusé avec le message « Une TVA à payer de votre dernière déclaration a été payée avec elle : elle ne se reporte pas. Saisissez votre crédit en négatif, ou 0 € si vous n'en avez pas. » La période doit être antérieure à la première période que compte PILIQI ; le crédit entre dans la chaîne à la période qui la suit.
     - **Qui :** le propriétaire de la société (toujours) ; le cabinet lié (son propriétaire et le collaborateur à qui le client est confié) et l'expert-comptable déclaré, s'ils ont « Charges : voir, ajouter, saisir et valider » ; jamais un administrateur, un responsable, un vendeur, un observateur.
     - **Vérifié par l'expert-comptable (décision de l'éditeur) :** le bloc conseille toujours, en clair : « Faites vérifier ce montant par votre expert-comptable : il figure à la ligne « Crédit de TVA à reporter » de votre dernière déclaration. Une erreur fausse toute la TVA à payer qui suit. » Saisi par le propriétaire alors qu'un comptable (cabinet lié ou expert-comptable déclaré ayant un compte actif) est là, le crédit est « À vérifier par votre comptable » : le comptable a dans son « À faire » (tableau de bord du cabinet, colonne du client, et page de l'expert-comptable déclaré) « Crédit de TVA de départ à vérifier », et il le confirme (« Vérifié par le comptable », son nom et la date) ou le corrige avec un motif ; saisi par le comptable, il naît vérifié. Sans comptable, il reste « Non vérifié par un comptable », en orange, avec le conseil ; dès qu'un comptable arrive (lien ou déclaration), il passe « À vérifier par votre comptable ». Le propriétaire qui change un crédit vérifié le repasse « À vérifier ». En attendant, le crédit compte déjà dans la TVA (réelle et estimation), et la carte de la TVA dit « crédit de départ à vérifier par votre comptable ».
     - **Où on le voit :** chez la société (propriétaire ; et l'administrateur ou le responsable qui voit la carte de la TVA, en lecture) et chez le comptable (espace du cabinet, page de l'expert-comptable déclaré) : montant, période, état (à vérifier, vérifié, non vérifié), auteur et date de chaque geste.
     - **Traces :** donnée de la société (`BelongsToSociete`, une seule par société, schéma dans une migration de création) ; chaque saisie, confirmation et correction (motif obligatoire pour une correction) porte son jeton de demande répétée et s'inscrit au journal d'audit de la société (catégorie « opérations », anciennes et nouvelles valeurs) ; une société révoquée ou close le consulte seulement ; franchise en base : ni bloc ni étape.
  4. Tests : chaîne mensuelle (exemple de la maquette : −16,40 puis −21,00 → crédit 37,40 ; puis 140,00 → 102,60 à payer), la même avec un crédit de départ négatif (−1 250,00 € : crédit de 1 250,00 €), « Non, aucun crédit », montant positif refusé avec son message, période déjà comptée refusée, états (à vérifier, vérifié par le cabinet, vérifié par l'expert-comptable déclaré, non vérifié sans comptable puis à vérifier quand un comptable arrive, repassé à vérifier quand le propriétaire le change), ligne de l'« À faire » du comptable et de la société, droits (refus pour administrateur, responsable, vendeur, observateur), audit, jeton répété sans doublon, isolation (jamais le crédit d'une autre société), navigateur chez la société et chez le comptable, chaîne trimestrielle, chiffre d'un mois identique quelle que soit la période choisie, estimation séparée, franchise, navigateur (carte, graphique, chiffres, deux modes).
- **Semis (point 6 de CLAUDE.md, précision de l'éditeur du 15/11 vers 15 h 20 : « je veux tout voir, tout les cas possibles… on laisse rien à l'inconnu ») :** données vraisemblables, par les vraies actions, de part et d'autre de chaque limite :
  1. une société au réel normal trimestriel dont le chiffre d'affaires HT de l'année en cours dans PILIQI dépasse 1 100 000 € (ligne de l'« À faire » visible du propriétaire et de l'administrateur qui peut modifier les réglages fiscaux) ; une autre au trimestriel à 1 100 000,00 € tout juste (aucune ligne) ; une au mensuel au-delà de 1 100 000 € (aucune ligne) ; une dont la ligne a reçu « C'est vérifié » (visible au journal d'audit, plus dans l'« À faire ») ;
  2. crédit de TVA reporté : au mensuel, des mois négatifs qui s'enchaînent (crédit qui grossit), puis un mois positif qui l'épuise en partie, puis un qui l'épuise tout à fait ; au trimestriel, un trimestre en crédit reporté sur le suivant ; une société en franchise en base (« TVA non applicable », ni report ni bloc du crédit de départ) ;
  3. crédit de départ (K2) : une société où le propriétaire a saisi −1 250,00 € et où le cabinet lié doit le vérifier (« À faire » du cabinet) ; une où le cabinet l'a vérifié ; une où l'expert-comptable déclaré l'a saisi (vérifié) ; une où le comptable l'a corrigé avec un motif (journal d'audit avec les deux valeurs) ; une sans comptable (« Non vérifié par un comptable », en orange) ; une qui a répondu « Non, aucun crédit » ; une qui n'a pas encore répondu (étape et ligne de l'« À faire ») ;
  4. chaque cas avec ses charges validées et à valider, pour que la TVA réelle et l'estimation diffèrent ;
  5. le test du semis vérifie chacun de ces cas ; le compte rendu du lot dit quel compte de la liste des comptes les voit et sur quelle page.
  6. Les sociétés des trois modèles (lot DEMO-COPIE) ont un régime de TVA choisi et une réponse au crédit de départ. MAISON FERRAND montre un crédit reporté. (Ajout A3 du cahier DEMO-COPIE, 16/11/2026.)
- **CLAUDE.md :** « réglages fiscaux communs (régime de TVA : réel normal mensuel ou trimestriel, ou franchise en base ; le régime simplifié n'existe plus depuis le 1er janvier 2027, loi de finances 2025, art. 38) » ; dans « Charges et TVA », la phrase du report du crédit ; à l'étape 5, l'état de TVA reprend le crédit reporté (ligne 22 de la CA3), note les remboursements demandés (formulaire 3519, ligne 26) et le crédit de la dernière déclaration avant PILIQI. Matrice des décisions : nouvelle source.
- **Rappel pour l'étape 2 (SUPER PDP) :** dire à la plateforme le régime de chaque société (réel normal mensuel ou trimestriel, franchise) pour la cadence de l'e-reporting à partir du 1er septembre 2027.

## [REMPLACÉ] Lot « DEMO-SITE » : site de démonstration commun (décision de l'éditeur du 15/11/2026 vers 18 h 30, heure de Paris)

> **REMPLACÉ le 16/11/2026 vers 0 h 50 (Paris) par le lot « DEMO-COPIE »** (même place : après TVA27, avant RLS). Décision de l'éditeur : « Q1 oui, Q2 oui, Q3 oui, Q4 oui » (un lien par société renouvelable ; magasin de démonstration du compte retiré ; copie supprimée après 30 jours avec « Recommencer à zéro » ; identité entièrement fictive). Cahier complet : `demo-copie/cahier-DEMO-COPIE.md` (préparé par la course wf_1a997172-d15, contre-lu). Maquette : https://claude.ai/artifact/855CcM1W2SUsd58taPYk7i (copie : ../modeles/demo-copie.html). Le texte ci-dessous ne s'applique plus ; il reste pour l'historique.

**Vos mots** : « tu fait plutot comme ca : Démonstration commune : un site de démonstration séparé, avec sa propre base et les mêmes données fictives pour tous (tous les cas, sociétés et cabinets compris). Il est réservé aux comptes inscrits : on y entre seulement depuis son compte connecté, après confirmation de l'adresse e-mail, par un lien secret de courte durée. Il n'est jamais ouvert au public. Il ne reçoit aucune donnée du compte. Le magasin de démonstration de chaque société reste pour s'entraîner. Montre-moi le modèle avant, ajoute-le à un lot et note-le dans le document. »

Elle **remplace** la proposition de la même soirée (vers 18 h, heure de Paris) : magasin de démonstration rempli dans chaque compte et espace de démonstration propre à chaque cabinet. Ni l'un ni l'autre ne se fait. Les trois mondes séparés restent la règle (point 3 de cette proposition). Maquette : https://claude.ai/artifact/Jm2F3GNdYLaYZLHo9uYH9b (copie : reprises/site-demonstration.html).

**Place : après TVA27, avant RLS.** Ainsi le semis montre déjà le régime et le crédit de TVA, et RLS couvre aussitôt le rôle de base du site. Commits par partie (A à D), relus comme un seul lot. Le lot touche l'isolation : exception (c), suite PostgreSQL complète une fois avant le dernier commit. La mise en ligne réelle du site (serveur, domaine, base, coût d'hébergement, décision de l'éditeur) se fait à l'étape 15. Ce lot livre tout ce qu'il faut pour la faire.

**Choix en attente** (Q1 à Q3 de la maquette). Sans réponse au départ du lot, les choix conseillés s'appliquent :
- Q1 : consultation seulement ;
- Q2 : seul le propriétaire de chaque société et de chaque cabinet entre, avec son adresse de connexion confirmée ;
- Q3 : le magasin de démonstration de chaque société reste comme aujourd'hui, sans graphiques ; la décision du 14/11 tient.

### A. Le mode « démonstration » de l'application (même code, autre site)
1. Le réglage `PILIQI_SITE=reel|demonstration` est lu une fois au démarrage. Il vaut `reel` par défaut, et une valeur inconnue empêche de démarrer.
2. En mode `demonstration` :
   - **fournisseurs** : tous les fournisseurs extérieurs sont les faux (INSEE, INPI, recommandé, Ordre, SUPER PDP, VIES, antivirus factice) ; le démarrage refuse toute clé ou URL réelle présente dans l'environnement ;
   - **envois** : le transport des e-mails est `array` et aucune file ne part vers l'extérieur ;
   - **planificateur** : il ne lance que la reconstruction de la nuit ;
   - **pages** : réponses en `X-Robots-Tag: noindex, nofollow` et page `robots.txt` qui refuse tout ;
   - **connexions** : la page de connexion, « Mot de passe oublié », l'inscription, les liens publics (devis, invitations, rattachements, mots de passe) et l'API sont fermés et répondent par la page introuvable française ;
   - **accès** : sans visite ouverte, chaque adresse répond par la page introuvable.
3. En mode `reel`, la production refuse de démarrer avec un faux fournisseur, ce qui existe déjà en partie : le lot le vérifie pour chaque fournisseur. Elle refuse aussi le semis, ce qui existe déjà.

### B. Le passage du compte au site (côté PILIQI réel)
1. **La carte.** Une carte « Site de démonstration » s'affiche dans l'espace de la société et dans l'espace du cabinet, pour le propriétaire (Q2), y compris avant la validation de la société.
   - **Le bouton.** « Ouvrir le site de démonstration » n'est actif que si l'adresse de connexion est confirmée. Sinon, une phrase dit de la confirmer, avec « Renvoyer le lien ».
   - **La limite.** Au plus 10 entrées par heure et par compte.
2. **Le jeton.** Le clic crée un jeton :
   - **son contenu** : 32 octets tirés au hasard, une expiration à 5 minutes, et rien d'autre (ni compte, ni société, ni adresse) ;
   - **sa signature** : faite par une clé Ed25519 propre au passage, dont la clé privée est seulement sur le site réel et la clé publique seulement sur le site de démonstration (variables d'environnement, jamais dans le dépôt) ;
   - **son envoi** : la page envoie le jeton par un formulaire POST qui s'envoie seul, jamais dans l'adresse, avec `Referrer-Policy: no-referrer`.
3. **L'audit.** L'entrée s'inscrit au journal d'audit de la société, catégorie « gestion des accès », sans le jeton. Elle n'est pas inscrite dans une société close ou révoquée.

### C. Le site de démonstration
1. **L'entrée** `POST /entrer` contrôle :
   - la signature et l'expiration du jeton ;
   - que le jeton n'a jamais servi : son empreinte est gardée dans une table à elle, effacée après 1 jour.

   La visite ouverte ensuite :
   - dure 2 heures au plus ;
   - s'appuie sur un témoin `Secure`, `HttpOnly`, `SameSite=Lax`, dans des sessions en fichiers ou en cache, jamais dans la base ;
   - ne s'ouvre qu'une fois par jeton.
2. **« Choisissez qui vous êtes ».**
   - **Les comptes proposés** sont les comptes fictifs du semis, groupés par société et par cabinet : propriétaire, administrateurs, responsables, vendeurs, observateurs, comptes d'entraînement, cabinet et collaborateurs, expert-comptable déclaré.
   - **L'entrée** dans un compte se fait sans mot de passe. Les comptes du site n'ont aucun mot de passe utilisable.
   - **« Changer »** est toujours visible dans la barre « Vous voyez PILIQI comme : … ».
3. **La consultation seulement (Q1).** Elle est tenue par la base, pas par l'écran :
   - le compte de base du site a `default_transaction_read_only = on` ;
   - il n'a aucun droit INSERT, UPDATE ni DELETE sur les tables métier, la table des jetons servis exceptée ;
   - les préférences des graphiques et les notifications lues restent dans la visite et ne s'écrivent pas en base ;
   - toute écriture refusée (SQLSTATE 25006 ou 42501) se traduit par l'alerte « Ici, on regarde sans rien changer. … ouvrez le magasin de démonstration de votre société. », jamais par une erreur technique ;
   - les boutons des gestes restent visibles, comme dans PILIQI, pour qu'on voie ce qui existe.
4. **Le bandeau** violet, sur toutes les pages des trois espaces, dit : « Site de démonstration PILIQI · Données fictives, sans valeur · Consultation seulement · Fin de la visite dans … · Quitter ». Chaque PDF porte « Démonstration — sans valeur », comme dans le magasin de démonstration.
5. **La reconstruction de chaque nuit**, `demonstration:reconstruire` :
   - **comment** : `migrate:fresh --seed` dans une seconde base, contrôle d'isolation à zéro problème, puis bascule par un nom de base lu au démarrage de chaque requête ;
   - **pourquoi** : les dates restent actuelles (retards, devis qui expirent, clôtures d'hier) ;
   - **si elle échoue** : l'ancienne base reste en service et l'éditeur est prévenu ;
   - **quand** : à une heure hors de 2 h–3 h, selon la règle du planificateur.

### D. Le semis du monde a, qui est aussi le contenu du site
- Le semis couvre déjà, ou doit couvrir à ce lot, tous les cas des cabinets :
  - collaborateurs et clients confiés ;
  - autorisations retirées et magasins choisis ;
  - invitations et demandes de lien dans tous leurs états ;
  - chiffres et graphiques de chaque client ;
  - charges dans tous leurs états ;
  - crédit de TVA de départ dans chacun de ses états (TVA27) ;
  - téléchargements par période ;
  - expert-comptable déclaré.
- Tout manque trouvé est ajouté et listé dans le compte rendu, avec le compte qui le voit.
- **Les numéros fictifs.** Les SIREN et SIRET du semis ne doivent désigner aucune vraie entreprise. Le lot les vérifie absents du fichier public du répertoire Sirene (téléchargement public, rien n'est envoyé) et le note dans le compte rendu. Le site ne montrant que ces numéros, aucune vraie entreprise n'y apparaît.
- **Les adresses e-mail** du semis sont toutes en `example.com`, `example.org` ou `example.net`, des domaines réservés. Le site réel refuse ces domaines à toute saisie d'une adresse de connexion ou d'une invitation, et un test le prouve.

### E. Tests (tous en PostgreSQL ; navigateur pour les écrans)
- **Mode démonstration** :
  - un vrai fournisseur ou une clé réelle empêche le démarrage ;
  - aucun e-mail ne part ;
  - chaque route publique ou de connexion répond « introuvable » ;
  - sans visite, chaque page répond « introuvable » ;
  - `noindex` est présent.
- **Mode réel** : un faux fournisseur empêche le démarrage, et le semis est refusé (existant).
- **Jeton** :
  - accepté une fois ;
  - refusé expiré, déjà servi, contrefait, signé par une autre clé ou mal formé ;
  - il ne contient aucune donnée du compte (test qui décode le jeton) ;
  - il n'apparaît jamais dans une adresse ni dans un journal ;
  - la limite de 10 par heure est tenue ;
  - le bouton est inactif sans adresse confirmée ;
  - un compte autre que le propriétaire ne voit pas la carte (Q2) ;
  - l'entrée est inscrite au journal d'audit, sans le jeton.
- **Consultation seulement** :
  - toute écriture par le compte de base du site est refusée par PostgreSQL ;
  - un geste d'écran (émettre, encaisser, déposer une charge, inviter, cocher une autorisation) montre l'alerte française, jamais une erreur 500 ;
  - les préférences des graphiques restent dans la visite.
- **« Choisissez qui vous êtes »** : chaque compte fictif s'ouvre et voit ce que PILIQI lui montre, ni plus ni moins, en balayage navigateur par rôle.
- **Reconstruction** :
  - la bascule a lieu seulement quand le contrôle d'isolation est à zéro ;
  - l'échec garde l'ancienne base et prévient l'éditeur.
- **Trois mondes** :
  - le contrôle d'isolation (`app:verifier-isolation`) est inchangé dans le monde b ;
  - aucune table du site réel ne contient une adresse en `example.*` ni un numéro fictif du semis ;
  - le magasin de démonstration de chaque société reste celui d'aujourd'hui (Q3).
- **Tests en navigateur** : la carte et son bouton dans l'espace de la société et du cabinet, l'entrée, le choix du compte, le changement de compte, l'alerte de consultation, le bandeau, « Quitter » ; plus `InventaireDesActionsTest`.

### F. Textes
- **CLAUDE.md, « Produit »** : les trois mondes et le site de démonstration commun.
- **CLAUDE.md, décisions** : la puce « Vérification de la société » (démonstration de la société inchangée) et la puce « Outillage » (mode du site, clé du passage) ; le point 6 dit que le semis est aussi le contenu du site de démonstration.
- **CLAUDE.md, étape 15** : mettre en ligne le site de démonstration, avec sa base, son domaine et sa clé.
- **README** (variables, reconstruction, rôle de base en lecture seule), **.env.example** (noms seulement, aucune valeur) et matrice des décisions.

### G. Compte rendu
Le compte rendu donne :
- la durée, comparée aux 3–6 h habituelles ; elle est estimée à 6–9 h ;
- les données ajoutées au semis et le compte qui les voit ;
- le résultat de la vérification des numéros fictifs ;
- « À faire sur votre PC » : `git pull`, `composer install`, `php artisan migrate:fresh --seed`, et pour essayer le site en local, la commande qui le lance en mode démonstration sur un second port.

## Lot VER (décision de l'éditeur du 12/11/2026 vers 14 h 30 UTC) : seulement PHP 8.5 et PostgreSQL 18
- Voir historique/DIRECTIVES.md (même date) : CI réduite à PHP 8.5 × PostgreSQL 18 ; composer.json ^8.5 ; CLAUDE.md, README ; d'abord installer PHP 8.5 et PostgreSQL 18 dans le conteneur (installer-session.sh), chaîne arrêtée.
- 12/11 16 h 35 UTC : l'éditeur veut que le lot VER attende la fin de toute la chaîne. Préparé : commit e389c22 (worktree scratchpad/ver-wt, branche locale ver-dernieres-versions, sur 0b7e734 ; suite PHP 8.5 + PG18 : 6 379 tests, code 0). À la fin de la chaîne : rejouer e389c22 sur la tête finale (corriger les nouvelles mentions de 8.3/17 éventuelles), suite complète sous PHP 8.5 + PG18 (cluster 18 sur le port 5433, DB_PORT=5433), envoyer, puis basculer le conteneur (php → 8.5, PG16 arrêté et retiré, PG18 sur 5432, base piliqi) ; installer-session.sh est déjà à 8.5/18.

## D76 (12/11/2026 vers 20 h 45 UTC) : le lot ER devient « ER-PRO »
- Directive D76 (historique/DIRECTIVES.md) : plus rien n'attend l'avis d'un expert-comptable ni d'un professionnel ; chaque point a une décision de Claude dans l'onglet « À faire vérifier par un professionnel », appliquée sans attendre ; une « Réponse du professionnel » remplie, même après la mise en production, prime.
- Cahier complet du lot : scratchpad/pro-d76/cahier-ER-PRO.md (préparé par la course wf_c774c74a-bef), qui remplace les passages « attend T1xx » ci-dessus. Ordre après la chaîne : ER-PRO (avec T105 et DEMO-V), puis VER (PHP 8.5 / PostgreSQL 18), puis la campagne 1 bis.
- Chaque point de suivi, et pour toujours après la mise en production : relire la colonne « Réponse du professionnel » ; toute réponse nouvelle donne un lot qui l'applique.

## Constat de l'éditeur du 12/11 vers 23 h UTC (Windows) : la ligne de qpdf de caisse:diagnostiquer s'affiche vide
- Sa sortie : toutes les lignes OK, « Caisse sécurisée opérationnelle. », mais la ligne « Analyseur des PDF (qpdf) » apparaît comme une ligne de blancs terminée par « | ».
- Cause : DiagnostiquerCaisseSecurisee::controlerQpdf() prend la première ligne de `qpdf --version` par strtok("\n") sans retirer le « \r » de Windows ; ce retour chariot, imprimé dans la cellule, ramène le curseur au début de la ligne et efface l'affichage. Le contrôle lui-même passe.
- À faire dans le lot ER-PRO (après la chaîne) : retirer les fins de ligne de chaque ligne lue (trim de la première ligne, ou découpe sur \R), dans controlerQpdf() et partout où une sortie de programme est lue (AnalyseurDePdf::versionAdmise, antivirus) ; test qui simule une sortie « qpdf version 12.2.0\r\n… » et vérifie le détail sans « \r ». Ajouter la ligne à l'onglet « À faire de votre côté » : rien à faire pour l'éditeur.

## Question laissée par le lot EC2 (c54c221, 13/11 vers 02 h 15 UTC), tranchée par Claude (D74/D76)
- Constat 5 (en partie) : une fiche de fournisseur ne nomme pas son auteur ; seuls les écrans et les actions empêchent un compte du cabinet lié ou de l'expert-comptable de la société de la créer ou de la modifier dans un magasin non choisi (la base garde déjà les achats et les avoirs fournisseur, via mouvements_stock.cree_par).
- Décision de Claude, appliquée après la chaîne (lot ER-PRO ou lot dédié juste après) : ajouter à la migration de création de `fournisseurs` les colonnes `cree_par` et `modifie_par` (clé composée magasin), remplies par l'action, et étendre le déclencheur de GardesDesCabinets::AUTEURS_DANS_UN_MAGASIN à cette table ; test qui échoue sans la garde. Le même relevé vérifie les autres fiches qu'un cabinet peut toucher (produits nés d'un achat, inventaires).
- À reporter dans l'onglet « À faire vérifier par un professionnel » ? Non : point technique d'isolation, pas légal ; noté dans « À appliquer à la fin ».

## CI rouge de 9b258e5 (S9-L), constatée le 13/11 à 05:30 UTC

- Cause : la fonction globale `choisirLeClient()` est déclarée deux fois, dans tests/Browser/Parcours/UnProduitUneLigneTest.php:88 et dans VentesDeBoutEnBoutTest.php:37. Pest s'arrête au chargement des tests (FatalException).
- Correction prévue : S9-A la répare d'abord (consigne l. 34 du script). Si ce n'est pas fait à la fin de S9-A, la réparer avant le lot 21 : une seule déclaration, dans une aide commune de tests/Browser, puis la suite PostgreSQL complète au code 0.

## Lot « SEED » : données de démonstration pour le PC de l'éditeur (décidé le 13/11/2026, vers 08 h 50 UTC)

> **Fait. Le semis perd le magasin de démonstration et les comptes d'entraînement au lot DEMO-COPIE** (retirés par DEMO-COPIE-1 le 16/11/2026 ; noms tranchés par le choix 21 : `SemisComplet`, `storage/app/private/comptes-du-semis.txt`, `PILIQI_SEMIS_MOT_DE_PASSE` ; sur le site de démonstration, ni mot de passe commun ni liste).

**Demande de l'éditeur (13/11, mot pour mot)** : « et donne moi la liste de  --seed pour voir ici chez moi avec les donnes remplir par --seed ».

**Constat** : à `9b258e5`, `database/seeders/DatabaseSeeder.php` ne sème rien. Il affiche seulement « Aucune donnée de démonstration : créez une société depuis la page d'inscription (/app/register). » Les données de démonstration étaient prévues à l'étape 15.

**Décision de Claude (D74 et D76 : décider, appliquer et prévenir, sans attendre)** : avancer les données de démonstration. Le lot SEED passe juste après la chaîne en cours (après le lot 21), avant ER-PRO. L'ordre devient : SEED, ER-PRO, VER, campagne 1 bis.

**Contenu du lot**

1. `php artisan migrate:fresh --seed` remplit la base, en local et en test seulement.
   - En production, le semeur refuse, avec un message.
   - Il passe par les vraies actions, avec les fournisseurs factices : Sirene, INPI, courrier recommandé, plateforme agréée et antivirus.
   - Il n'écrit jamais directement en base ce qu'une garde réserve à un document : un produit naît d'une facture d'achat ou d'un inventaire d'ouverture validé, et le stock suit son grand livre.

2. Le jeu de données reprend celui de la campagne 1 bis (CLAUDE.md, feuille de route).
   - **Sociétés.**
     - Une société « Commerce ou atelier » validée, avec deux magasins réels et le magasin de démonstration.
     - Une société « Services, sans vente d'articles » validée, avec un magasin.
     - Une inscription qui attend le code de son courrier.
   - **Comptes de chaque société validée.**
     - Un propriétaire, qui ne vend pas dans les magasins réels.
     - Un administrateur sans vente, et un administrateur autorisé à vendre dans deux magasins.
     - Par magasin, un responsable et deux vendeurs. Une même personne a un compte dans deux magasins.
     - Des comptes d'entraînement dans le magasin de démonstration.
   - **Cabinet.** Un cabinet d'expertise comptable vérifié, avec son propriétaire et deux collaborateurs. Il est lié à deux sociétés, et chaque collaborateur a un client confié.
   - **Expert-comptable déclaré.** Une société déclare son expert-comptable par le SIREN d'un cabinet absent de PILIQI.

3. Données métier remplies.
   - Clients professionnels et particuliers, fournisseurs et services.
   - Factures d'achat validées, dont une sans pièce du fournisseur, et un inventaire d'ouverture.
   - Tickets à la caisse, en un et plusieurs modes de règlement.
   - Factures payées, payées en partie et en retard.
   - Devis : brouillon, envoyé, accepté et transformé en facture.
   - Avoirs : un bon d'avoir du client comptoir, un crédit d'un client, et un avoir fournisseur.
   - Une contre-passation, des journées clôturées et une caisse personnelle fermée.
   - Le tout par ordre chronologique, sans jamais dater une opération avant le dernier verrou de son magasin.

4. La liste des comptes s'affiche à la fin du semis : société, magasin, rôle, nom, identifiant et mot de passe.
   - Elle s'écrit aussi dans un fichier non suivi par git (par exemple `storage/app/private/comptes-du-semis.txt` (nom tranché par le choix 21 du cahier DEMO-COPIE ; sur le site de démonstration, ni liste ni mot de passe commun)).
   - Les mots de passe sont tirés au hasard à chaque semis, comme si chaque personne avait choisi le sien. Aucun n'est écrit dans le dépôt.
   - Un vendeur garde son identifiant pour mot de passe, pour montrer la règle du poste reconnu.
   - Les propriétaires ont une adresse de connexion confirmée sur un domaine réservé (`exemple.test`).
   - Ajout du 13/11, vers 09 h UTC, après la question de l'éditeur (« Alors la je dois avoir les identifiants pour me connecter avec ces donnes du seed »).
     - Une variable facultative du `.env`, `PILIQI_SEMIS_MOT_DE_PASSE` (nom tranché par le choix 21 du cahier DEMO-COPIE), donne à tous les comptes semés le même mot de passe, stable d'un semis à l'autre. Le semeur la refuse si elle viole la règle des mots de passe : moins de 8 caractères, un seul caractère répété, une suite de chiffres, ou l'identifiant d'un compte.
     - Sans cette variable, chaque mot de passe est tiré au hasard et affiché.
     - Les adresses des propriétaires sont fixes et connues d'avance (par exemple `proprietaire@commerce.exemple.test`). Les identifiants de 8 chiffres, tirés au hasard par PILIQI, changent à chaque semis : le tableau final les donne.

5. Tests.
   - Un test lance le semeur sur PostgreSQL et vérifie les nombres, l'isolation et le refus en production.
   - `migrate:fresh --seed` doit sortir avec le code 0.
   - Le README reçoit une section « Données de démonstration sur votre PC ». La feuille de route de CLAUDE.md est mise à jour : l'étape 15 met en ligne le site de démonstration. Ses trois bases modèles, une par type d'activité, se refont chaque nuit par le semis.

6. Une fois le lot poussé, ajouter le jour même dans l'onglet de l'éditeur « À faire de votre côté » : `git pull`, puis `php artisan migrate:fresh --seed`, puis se connecter avec les comptes affichés.

## Lot « GRAPH-AN » : graphiques par année, sur 10 ans (demande de l'éditeur du 16/11/2026 vers 23 h 30, heure de Paris)

Mots de l'éditeur (capture du tableau de bord de Maison Ferrand Halles, graphiques « Ventes et achats HT par mois » et « TVA à payer par mois ») : « quand tu commence ce qui est arrete alors pour les statistique il faut que tu ajoute aussi par année, donc pour les 10 années, donc si les années arriere sont vide alors tu affiche que les années qui ont des données. »

Place : au départ de la reprise (après DEMO-COPIE-3), juste après les relectures de DEVIS-RET, avant MDP.

Ce que le lot fait (proposition de Claude, maquette à montrer à l'éditeur avant le lot) :
1. Menu « Période » : un nouveau choix « Par année (10 dernières années) ». Les graphiques mensuels (ventes et achats, TVA, marge brute, encaissements par mode, avoirs, nombre de ventes et panier moyen, factures, etc.) montrent alors une barre (ou un point) par année au lieu d'un par mois.
2. Années affichées : les 10 dernières années (l'année en cours comprise, jusqu'à aujourd'hui), mais en partant de la première année qui a des données dans les magasins comptés : les années plus anciennes, vides, ne s'affichent pas. Une année vide au milieu (magasin fermé un an) reste affichée à 0, pour ne pas fausser la lecture.
3. Exercice : quand la société clôt son exercice en décembre, « année » = année civile ; sinon, chaque barre est un exercice (« exercice 2025-2026 »), comme le choix B du 15/11.
4. « Comparer avec l'année précédente » n'a pas de sens par année : la case se grise avec une phrase grise (« La comparaison se fait mois par mois ; par année, chaque barre est déjà une année. »).
5. Les chiffres clés du haut (mois en cours) ne changent pas. Le graphique existant « Chiffre d'affaires par an » reste (il deviendrait redondant : à trancher dans la maquette — garder ou le retirer).
6. Mêmes règles qu'aujourd'hui : magasins où la personne voit tous les documents, montants additionnés en centimes dans le code, préférence retenue en base par compte et par écran, valeur inconnue refusée.
7. Semis : au moins une société avec des données sur plusieurs années, et une avec une année vide au milieu, pour que chaque cas se voie (le semis couvre aujourd'hui ~13 mois : en ajouter, par les vraies actions, sur 3 ans au moins dans une société).
8. Tests : domaine (années vides retirées en tête, gardées au milieu, exercice décalé, 10 ans au plus), préférences, navigateur (société, magasin, cabinet, expert-comptable), contraste des deux modes.

### GRAPH-AN, point 0 : remettre le haut du tableau de bord comme la maquette acceptée (constat de l'éditeur du 16/11/2026 vers 23 h 50, heure de Paris)

Mots de l'éditeur, avec la capture de la maquette « G1 + chiffres de G2 » (modeles/graphiques-tableau-de-bord.html) : « ce que tu m avais proposé et j ai accepté mais toi tu n as pas mis comme j'ai dit ». Écarts relevés par Claude entre la maquette et l'écran réel (capture 144, panneau de Maison Ferrand Halles) :
1. Barre des filtres : la maquette met chaque libellé À GAUCHE de son menu, tout sur une seule ligne compacte (« Période [12 derniers mois]  Magasin [Tous mes magasins]  ☑ Comparer…  Ajouter un graphique [— Choisir —] ») ; l'écran met le libellé AU-DESSUS du menu (.piliqi-graphiques__filtre en colonne), avec des menus hauts : à refaire comme la maquette.
2. Chiffres clés : la maquette colore la ligne sous le chiffre (variation sur un an en vert si elle monte, rouge si elle baisse ; « collectée − déductible » et « dont … en retard » dans la couleur de la maquette) ; l'écran les met toutes en gris : à refaire comme la maquette (en gardant le contraste AA des deux modes).
3. Cartes des chiffres : plus compactes dans la maquette (libellé petit, chiffre, ligne) : comparer et aligner.
4. Le menu « Magasin » n'apparaît pas dans le panneau d'un magasin : c'est voulu, comme dans la maquette (écran « Panneau du magasin Centre ») et CLAUDE.md ; il apparaît dans l'espace de la société, du cabinet et de l'expert-comptable.
Test en navigateur : comparer la barre (une ligne à 1 280 px, libellé et menu sur la même ligne) et la couleur de la variation (hausse / baisse), dans les deux modes.

### GRAPH-AN : choix de l'éditeur (16/11/2026 vers 23 h 59, heure de Paris), d'après la page modeles/options-des-graphiques.html

Mots de l'éditeur : « ouii j'ai vu c'est bon donc tu oublie mais tu ajoute ces que t as proposé : "Par trimestre","Par année, sur 10 ansé", "Cumul depuis le début de l'année","Écart en % sur un an","Par vendeur", "Cliquer pour voir le détail". tu oublie pas que dans le demo tu laisse tout les carte ouvertes afin que la societe voit tout de suite toute. et en reel il faut qq carte prancipal ouverte au demarrege donc au debut. »

Retenu (le lot GRAPH-AN fait exactement ceci, maquette à montrer avant) :
- Point 0 : barre des filtres sur une ligne (libellé à gauche du menu), couleurs de la ligne sous les chiffres clés (hausse verte, baisse rouge), cartes des chiffres compactes. Le menu « Magasin » absent du panneau d'un magasin reste ainsi (l'éditeur : « c'est bon … tu oublie »).
- Menu « Découper par » avec trois valeurs : mois (défaut, existant), trimestre, année. (Semaine, jour et « Du … au … » NON retenus.)
- Par année : 10 dernières années, années vides du début cachées, vides du milieu à 0 ; exercice décalé = une barre par exercice.
- Par trimestre (T1 … T4, ou trimestres de l'exercice quand il ne finit pas en décembre).
- Case « Cumul depuis le début de l'année » (ou de l'exercice) : chaque point ajoute les précédents ; avec « Comparer », la courbe de l'année précédente cumulée en pointillés.
- « Écart en % sur un an » : choix dans le menu de la carte, une barre par période, verte au-dessus de 0, rouge en dessous ; pas d'écart (« – ») quand l'année précédente vaut 0.
- Nouveau graphique « Ventes par vendeur » (barres couchées) : CA HT net par compte vendeur, avec le nom figé sur chaque vente. Comme pour les autres graphiques, un magasin n'y compte que si la personne voit toutes les ventes de toute l'équipe de ce magasin (propriétaire, responsable ou administrateur à qui « Voir les graphiques » est cochée et qui voient toutes les factures ; cabinet lié et expert-comptable déclaré selon leurs consultations) ; une phrase grise dit les magasins non comptés. Jamais pour un vendeur ni un observateur.
- « Cliquer pour voir le détail » : clic sur une barre d'un graphique par période → la même carte redécoupe cette période plus finement (année → mois, trimestre → mois, mois → jours) ; clic sur un jour → la liste filtrée des documents de ce jour (lien vers la liste, avec le filtre, pour qui peut voir ces documents) ; bouton « Revenir ». Rien ne se calcule sur un montant affiché.
- NON retenus : semaine, jour, dates libres, magasins côte à côte, par heure, tendance lissée, objectif.

Cartes ouvertes au départ :
- Site réel : quelques cartes principales au premier affichage (aujourd'hui : Ventes et achats HT par mois, TVA à payer par mois, CA HT par mois, et CA par magasin quand il y a plusieurs magasins) ; garder ce défaut (éventuellement ajouter « Factures à encaisser par ancienneté » : à montrer dans la maquette).
- Site de démonstration : TOUTES les cartes que chaque compte peut voir sont ouvertes au départ, pour que la société voie tout de suite tout. Règle générale (« toutes les cartes permises au compte »), pas une liste figée, pour que les graphiques ajoutés plus tard (Ventes par vendeur) s'y ouvrent aussi. Écrit aussi au cahier DEMO-COPIE pour le lot 3.
