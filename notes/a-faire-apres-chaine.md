# À faire après la chaîne société (petits points remontés par l'éditeur en développement local Windows/XAMPP)

1. SignataireFiscal::genererUnePaire() (et toute génération ou lecture de clé OpenSSL) : inclure les messages de openssl_error_string() dans l'exception et indiquer, sous Windows, que PHP ne trouve pas openssl.cnf (variable OPENSSL_CONF). caisse:diagnostiquer : vérifier que la génération d'une clé EC fonctionne et afficher la piste OPENSSL_CONF. README : note Windows (OPENSSL_CONF vers C:\xampp\apache\conf\openssl.cnf ou C:\xampp\php\extras\openssl\openssl.cnf). Test : message d'erreur explicite (simulé).
2. Proposé à l'éditeur, à faire seulement s'il le confirme : une commande `composer run dev` propre à PILIQI, sans Node, qui lance serve, queue:listen et schedule:work ensemble.
3. README : note Windows/XAMPP (extensions à activer dans php.ini, dont pdo_pgsql ; ne jamais lancer composer update ; SQLite en développement).
4. README, installation : ajouter la clé du journal d'audit (JOURNAL_AUDIT_CLE et JOURNAL_AUDIT_CLE_ID, générée par php -r "echo base64_encode(random_bytes(32)), PHP_EOL;") aux étapes d'installation, à côté de caisse:generer-cle : sans elle, la confirmation d'inscription échoue (JournalAuditNonConfigureException, constaté par l'éditeur en local). Envisager que caisse:diagnostiquer (ou un diagnostic général) signale aussi l'absence de cette clé, avec un test.
   Point 4, suite (ajouté le 26/10/2026 à la demande de l'éditeur, à faire dans le lot L1 ; PHRASE DE CLAUDE.md APPROUVÉE PAR L'ÉDITEUR le 26/10/2026, réponse « Oui, l'ajouter » à la question sur la phrase de l'étape 15) : le fuseau par défaut de la base PostgreSQL en UTC ne doit plus être une action manuelle à retenir pour la mise en production. Une migration (ou le mécanisme le plus simple et le plus sûr que tu trouves, justifié dans le compte rendu) fixe, sous PostgreSQL seulement, le fuseau par défaut de la base courante à UTC (ALTER DATABASE sur current_database(), par exemple par un bloc DO avec format('%I')) ; si le rôle qui lance les migrations n'en a pas le droit, la migration échoue avec un message explicite en français qui dit quoi faire, pour que le déploiement s'arrête de façon visible au lieu de l'oublier en silence. caisse:diagnostiquer garde son contrôle de pg_settings.reset_val. README : la commande manuelle disparaît des étapes à retenir et la note dit que les migrations s'en chargent. CLAUDE.md, étape 15 de la feuille de route : ajoute que le fuseau par défaut de la base est fixé à UTC par les migrations et contrôlé par caisse:diagnostiquer. Tests sous PostgreSQL : après les migrations, reset_val vaut UTC ; sous SQLite, rien ne change ; le message d'échec est explicite (échec simulé).

## Constat de l'éditeur du 12/11 vers 00 h 30 UTC (captures de l'espace de la société) : la démonstration après la validation
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
   - Elle s'écrit aussi dans un fichier non suivi par git (par exemple `storage/app/private/comptes-de-demonstration.txt`).
   - Les mots de passe sont tirés au hasard à chaque semis, comme si chaque personne avait choisi le sien. Aucun n'est écrit dans le dépôt.
   - Un vendeur garde son identifiant pour mot de passe, pour montrer la règle du poste reconnu.
   - Les propriétaires ont une adresse de connexion confirmée sur un domaine réservé (`exemple.test`).
   - Ajout du 13/11, vers 09 h UTC, après la question de l'éditeur (« Alors la je dois avoir les identifiants pour me connecter avec ces donnes du seed »).
     - Une variable facultative du `.env`, `PILIQI_DEMO_MOT_DE_PASSE`, donne à tous les comptes semés le même mot de passe, stable d'un semis à l'autre. Le semeur la refuse si elle viole la règle des mots de passe : moins de 8 caractères, un seul caractère répété, une suite de chiffres, ou l'identifiant d'un compte.
     - Sans cette variable, chaque mot de passe est tiré au hasard et affiché.
     - Les adresses des propriétaires sont fixes et connues d'avance (par exemple `proprietaire@commerce.exemple.test`). Les identifiants de 8 chiffres, tirés au hasard par PILIQI, changent à chaque semis : le tableau final les donne.

5. Tests.
   - Un test lance le semeur sur PostgreSQL et vérifie les nombres, l'isolation et le refus en production.
   - `migrate:fresh --seed` doit sortir avec le code 0.
   - Le README reçoit une section « Données de démonstration sur votre PC ». La feuille de route de CLAUDE.md est mise à jour : l'étape 15 garde les données de démonstration de la production.

6. Une fois le lot poussé, ajouter le jour même dans l'onglet de l'éditeur « À faire de votre côté » : `git pull`, puis `php artisan migrate:fresh --seed`, puis se connecter avec les comptes affichés.
