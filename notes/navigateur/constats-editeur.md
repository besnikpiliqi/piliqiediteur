# Constats de l'éditeur à corriger par les lots NAV (essais en navigateur sur son PC)

Chaque constat se corrige dans le lot NAV dont il relève (ou plus tôt, si un lot précédent touche le même écran), avec un test en navigateur qui le reproduit ; le lot dit dans « faits » lesquels il a corrigés.

## C1 — Caisse : « Il reste … à répartir » affiché deux fois (02/11/2026, vers 01 h 50 UTC, capture ; lot NAV-2)

Message de l'éditeur : « la ici affiche 2 fois "Il reste 20,00 € à répartir. Il reste 20,00 € à répartir." donc une fois quand on choisi l'avoir mais si je clique sur encaisser alors il affiche encore une le même message. donc il faut affiche une seul fois même si cliquer sur encaisser ».

Écran : caisse du magasin, section « Règlement », total à payer 350,00 €, une part en mode « Avoir » avec le bon AV-PARIS-2026-000001 (330,00 €) ; le texte d'aide en direct « Il reste 20,00 € à répartir. » (`ReglementComptant::RESTE_A_REPARTIR`, `app/Domain/Facturation/Services/ReglementComptant.php`, affiché par `app/Filament/Concerns/SaisitUnReglementEnParts.php`) s'affiche, puis, au clic sur « Encaisser », le refus de validation affiche le même texte une seconde fois.

À faire : le message ne s'affiche qu'une fois, avant comme après le clic (par exemple, le refus de validation remplace l'aide en direct au même endroit, ou l'aide se met en rouge au lieu d'être répétée) ; même règle partout où ce trait sert (caisse, encaissement d'une facture) ; test en navigateur : saisie incomplète, clic sur « Encaisser », un seul message visible ; saisie complétée, plus aucun message.

## C2 — Fiche d'un avoir : les documents de « Crédit appliqué » ne sont pas cliquables (02/11/2026, vers 01 h 55 UTC, capture ; lot NAV-2)

Message de l'éditeur : « et là il faut que les factures sur Crédit appliqué soit cliquable pour aller voir la facture ou ticket comme les avoirs ».

Écran : fiche d'un avoir (`/app/{magasin}/avoirs/{id}`), section « Crédit appliqué » : deux lignes « Facture TK-PARIS-2026-000002 — 20,00 € » et « Facture TK-PARIS-2026-000003 — 330,00 € », numéros en texte simple.

À faire :
- chaque numéro de document de cette section est un lien vers la fiche du document (ticket ou facture), pour qui a le droit de la voir ; sinon le numéro reste un texte simple, sans lien qui mènerait à un refus ;
- même règle partout où un numéro de document s'affiche sans lien alors que les autres le sont : imputations et paiements d'une facture, bons d'avoir utilisés, documents d'une clôture, avoirs d'une facture, facture d'origine d'un avoir, retours d'une facture d'achat (choix de Claude : tout numéro de document affiché devient un lien vers lui quand on peut le voir) ;
- un ticket est nommé « Ticket » et une facture « Facture » (aujourd'hui « Facture TK-… » pour un ticket) ;
- test en navigateur : le clic sur chaque numéro ouvre le bon document ; un vendeur qui ne voit pas ce document ne reçoit pas de lien.

**Confirmé par l'éditeur le 11/11/2026 vers 00 h 10 UTC** (point T43 du document « Ce qui reste à trancher », réponse « oui ») : tout numéro de document affiché devient un lien vers ce document pour qui peut le voir.

## C3 — PDF des factures et des tickets : les modes de paiement ne se voient pas (02/11/2026, vers 10 h 30 et 10 h 45 UTC ; lot NAV-2, sauf si le diagnostic désigne un défaut à corriger plus tôt)

**Traité par le lot TR** (`tr/decision-tr.md`, § 4, réponses de l'éditeur « r1 = oui, r2 = oui » du 02/11 vers 22 h 10 UTC) : bloc à gauche des totaux, « Émettre et encaisser », reçu de paiement. Le lot NAV-2 le teste seulement en navigateur.

Constat de l'éditeur : « je vois que dans les factures et même dans les tickets ne marque pas les méthodes de paiements. et il faut placer tout à gauche du TTC total. donc tu mets pour tous les règlements dans cet endroit » ; précision, à la question de savoir ce que veut dire « tous les règlements » : « je parle si le règlement de la facture est fait en plusieurs méthodes, dont par carte ou espèces etc ».

Puis, vers 11 h 10 UTC : « pareil pour les tickets » : la même règle vaut pour les tickets que pour les factures.

Décision de l'éditeur (elle tranche la question métier) : il s'agit des règlements d'un même document en plusieurs modes (carte, espèces, chèque…). Chacun s'imprime sur sa ligne, et le bloc se place tout à gauche, sur la même bande que les totaux (Total HT, Total TVA, Total TTC), au lieu d'être en dessous. La règle de `CLAUDE.md` ne change pas : le PDF imprime les règlements reçus à l'émission et reproduit le document tel qu'émis ; un paiement reçu ensuite reste sur la fiche.

Diagnostic en cours (atelier wf_e6d5221e-141, rapport `paiements/diag-reglements-rapport.md`, captures dans `paiements/diag-reglements/captures/`) : il établit pourquoi l'éditeur ne voit aucun mode (règlements absents du contexte signé d'un ticket ou d'une facture réglée à l'émission, ou facture réglée après son émission) et mesure la nouvelle place avec dompdf. Le rapport vérifié fait foi pour la cause et la correction.

Diagnostic vérifié (02/11/2026 vers 11 h 50 UTC, rapport et section « Vérification » : `paiements/diag-reglements-rapport.md`) :
- aucun défaut dans les règlements retenus : une vente à la caisse en plusieurs modes, une facture réglée à la caisse, un bon d'avoir ou un crédit imputé à l'émission ont chacun leurs modes dans le contexte signé et les impriment ;
- cause principale : une facture (ou un ticket) faite par « Factures > Créer », émise, puis réglée par « Encaisser » n'imprime aucun règlement, par la règle « le PDF reproduit le document tel qu'émis » ; hors de la caisse, aucun geste n'encaisse au moment de l'émission (`FactureResource.php`, émission sans règlement) ;
- le bloc existant est petit, sous les totaux, se coupe entre deux pages près du bas de page (Total TTC seul en page 2 à 20 lignes) et coupe les références au milieu ;
- un paiement contre-passé après l'émission reste imprimé avec « Restant dû 0,00 € » : le libellé doit dire « à l'émission » ;
- la variante « à gauche du Total TTC » (une rangée de tableau : règlements à gauche en colonnes Date / Mode / Référence / Montant, totaux à droite) est compatible dompdf, jamais coupée, au-dessus du pied, sur 35 PDF réels mesurés ; 4 tests de `FacturePdfGeneratorTest` (lignes 418, 499, 830, 996) lisent l'ancienne structure et seront à réécrire avec les mêmes attentes ; diff dans `paiements/diag-reglements/captures/donnees/`.
Questions posées à l'éditeur (02/11 vers 11 h 55 UTC) : (1) un geste « Émettre et encaisser » sur les factures, et une part inférieure au total à l'émission est-elle permise ; (2) pour une facture réglée après son émission, un « reçu de paiement » PDF par encaissement (le PDF de la facture ne changeant jamais) ; (3) libellé « Restant dû à l'émission » (choix de Claude).

À faire :
- chaque règlement reçu à l'émission (date, mode, référence, montant), chaque crédit d'avoir (numéro de l'avoir, montant) et le restant dû s'impriment dans un bloc « Règlements » placé à gauche du tableau des totaux, sur la même bande, dans le PDF des tickets et des factures, duplicatas compris ;
- la variante tient sur une page A4 portrait avec un, deux et cinq règlements, une référence longue et un crédit d'avoir, sans chevaucher le pied fixé en bas, en HTML compatible dompdf (tableaux) ;
- tout défaut que le diagnostic confirme (par exemple des règlements reçus à l'émission absents du contexte signé) se corrige avec un test du domaine qui le reproduit ;
- test en navigateur : une vente à la caisse en espèces et par carte puis une facture réglée à l'émission en plusieurs modes, PDF téléchargés, chaque mode et son montant lus dans le PDF, à gauche du Total TTC.


## C4 — Aucune page ne joint un site tiers (03/11/2026 vers 00 h 15 UTC ; lot NAV-2, pour les trois panneaux et les pages publiques)

Question Q-TIERS (`navigateur/question-ressources-tierces.md`, constat 9 de la relecture du lot NAV-1) ; réponse de l'éditeur : « oui recommandé ».

À faire :
- la police Inter est servie par PILIQI : fichiers de la police (licence SIL Open Font License, à citer dans le README) dans `public/`, fournisseur de police local de Filament dans chaque panneau, sans outil Node ni paquet ajouté ; plus aucune requête vers fonts.bunny.net ;
- les initiales du compte sont dessinées par le serveur : fournisseur d'avatar local de Filament (image SVG produite par PILIQI, sans donnée envoyée à un tiers, couleurs lisibles en clair et en sombre) dans chaque panneau ; plus aucune requête vers ui-avatars.com ;
- les tests en navigateur ne tolèrent plus aucun site tiers : la liste des ressources tolérées de l'outillage du lot NAV-1 est vidée, et toute requête vers un autre site que l'application fait échouer la vérification ; un test vérifie, panneau par panneau et sur les pages publiques, qu'aucune page ne charge une ressource d'un autre site (balises, styles, polices, images, scripts) ;
- aucune donnée personnelle (nom, initiales, adresse IP) ne part vers un tiers depuis une page de PILIQI ;
- À faire sur le PC de l'éditeur : à dire dans « laisses » (a priori rien d'autre que git pull).

## C5 — Fenêtre « Ajouter des produits » : la rangée d'un produit choisi devient blanc brillant, texte presque invisible (03/11/2026 vers 00 h 55 UTC, deux captures, mode sombre ; lot NAV-2)

Constat de l'éditeur : « quand c'est un produit sélectionné il affiche très blanc brillant que je l'aime pas. je pense sera mieux de juste changer la couleur belle et le texte soit visible car là on voit presque rien ». Captures : page « Créer une facture » d'un magasin, fenêtre « Ajouter des produits », produit PRD-000001 choisi (« × 1 ») ; sur la seconde, la rangée du produit choisi a un fond presque blanc et son texte clair (référence, nom, stock, prix, bénéfice) ne se lit plus.

Piste (à vérifier en navigateur, ne pas croire) : `app/Filament/App/Livewire/SelecteurDeProduits.php`, `recordClasses` : `'bg-gray-100 dark:bg-white/5 font-semibold'` pour une rangée choisie. Le dépôt n'a aucun thème compilé (`CLAUDE.md` : aucun outil Node tant qu'aucun thème d'écran personnalisé n'existe) : une classe Tailwind absente de la feuille précompilée de Filament n'existe pas, et l'ordre ou l'absence des variantes `dark:` peut laisser le fond clair sous un texte clair (au survol ou après le choix).

À faire (couleur : choix de Claude) :
- la rangée choisie prend une teinte légère de la couleur principale du panneau (celle des boutons), avec un texte bien lisible : en mode clair, fond clair teinté et texte foncé ; en mode sombre, fond sombre teinté et texte clair ; même rendu au survol ; l'icône de coche et « × quantité » restent visibles ;
- sans outil Node : seulement des classes présentes dans la feuille de Filament, ou une petite feuille CSS écrite à la main, enregistrée par Filament (`FilamentAsset`), qui lit les variables de couleur du panneau ;
- le même style pour toute rangée « choisie » des fenêtres de sélection (produits, clients, services, retours cherchés par numéro, et toute autre liste où l'on coche des rangées), en clair et en sombre ;
- la même teinte, avec le même test de contraste en clair et en sombre, pour les lignes cochées des listes (factures, devis, clients, produits… : celles qui ont des actions groupées) ;
- test en navigateur, en mode clair et en mode sombre : choisir un produit, puis survoler sa rangée ; le contraste entre le texte et le fond de la rangée (couleurs calculées par le navigateur) atteint au moins 4,5 pour 1 (niveau AA des règles d'accessibilité WCAG) ; même test pour la fenêtre des clients ;
- À faire sur le PC de l'éditeur : à dire dans « laisses » (a priori `php artisan filament:assets` si une feuille CSS est ajoutée, puis rien d'autre que git pull).

**Confirmé par l'éditeur le 11/11/2026 vers 00 h 10 UTC** (point T43, réponse « oui », donnée après cette explication du fil du document : « Quand vous cliquez sur une ligne d'une liste (une facture par ex.), elle se colore en violet clair, texte bien lisible. ») : la ligne choisie d'une liste ou d'une fenêtre de sélection prend une teinte violette légère, celle de la couleur principale du panneau, au texte bien lisible. Dans le compte rendu de NAV-2 à l'éditeur, dire que dans une liste un clic sur une ligne ouvre le document, et que la teinte marque les lignes cochées et la ligne choisie d'une fenêtre de sélection.

## C6 — Tests en navigateur sur le PC de l'éditeur (Windows, XAMPP, Node 26) : deux échecs propres à Windows (03/11/2026 vers 03 h 35 UTC ; lot NAV-2, ou le lot en cours à la reprise)

Résultat de `php artisan test tests/Browser/AidesCommunesTest.php` chez l'éditeur (9 réussis, 2 échoués ; au premier passage, les deux premières connexions avaient aussi échoué après 62 s et 28 s, puis réussi au second : démarrage à froid) :
1. « un fichier envoyé par un formulaire arrive au serveur avec les autres champs » : « Le choix du fichier facture.txt a échoué sur http://127.0.0.1:50482/essai-envoi : Paths provided to localPaths must be absolute and fully resolved. » (`tests/Browser/Support/Navigation.php:260`, aide `choisir()`, ligne 311) : le chemin du fichier donné à Playwright n'est pas absolu et résolu sous Windows ; à corriger dans l'aide (chemin absolu résolu par `realpath`, séparateurs du système), avec un test qui le vérifie.
2. « le propriétaire dont les courriers ne sont pas parvenus dépose une pièce par le champ de fichier de Filament » : 500 sur GET /societe/{uuid}/verification-societe : « ErrorException : rename(…\storage\framework\views\d7f3701.tmp, …\storage\framework\views/d7fd98….php): Accès refusé (code: 5) » (Filesystem.php:233) : course de compilation des vues Blade sous Windows (deux requêtes compilent la même vue, ou l'antivirus tient le fichier). Contournement donné à l'éditeur : `php artisan view:cache` avant les tests. À faire dans le lot : documenter dans le README (section des tests en navigateur sous Windows) `php artisan view:cache` avant les tests en navigateur, et le faire au démarrage du serveur des tests en navigateur si c'est sûr.
3. README, section « Tests en navigateur », Windows : avec Node 26, `npx playwright install chromium` se fige à « extracting archive » (même pour FFmpeg, 1,3 Mo) ; la décompression à la main (`Expand-Archive` des archives chrome-win64.zip et chrome-headless-shell-win64.zip de cdn.playwright.dev dans `%LOCALAPPDATA%\ms-playwright\chromium-1217` et `chromium_headless_shell-1217`, puis un fichier vide `INSTALLATION_COMPLETE` dans chacun) a marché ; FFmpeg n'est pas nécessaire aux tests. À écrire dans le README, et à signaler comme limite (Node 22 ou 24 LTS conseillé pour Playwright sous Windows, à vérifier).
4. Second passage chez l'éditeur, avec `--headed` et après `php artisan view:cache` (03/11 vers 03 h 45 UTC) : 7 réussis, 4 échoués. L'erreur 500 de compilation des vues a disparu (`view:cache` suffit) ; les deux tests de fichier échouent sur le chemin non absolu (point 1, confirmé pour `cni.pdf` aussi) ; les deux premières connexions échouent par délai : « Timeout 15000ms exceeded » sur `goto` (`Navigation.php:97`) et « Au bout de 15 s, la page ne revient pas au repos » (`Navigation.php:127`). Le PC Windows (serveur PHP intégré, antivirus, premières pages lentes) dépasse les 15 s fixes : à faire, un délai réglable par une variable d'environnement (par exemple `PILIQI_NAVIGATEUR_DELAI`, 15 s par défaut en CI), documenté dans le README pour Windows, et un échauffement des pages avant le premier test si utile.
5. (03/11 vers 04 h UTC) L'éditeur veut passer la suite sur PostgreSQL sur son PC Windows : le README ne décrit que Linux (`sudo service postgresql start`, `createdb --lc-collate=C.UTF-8`). Conseil donné : Docker Desktop (WSL 2) avec l'image `postgres:17` et `POSTGRES_INITDB_ARGS="--encoding=UTF8 --locale=C.UTF-8"` (même collation que la CI), `extension=pdo_pgsql` dans php.ini, puis les variables DB_* dans PowerShell avant `php artisan test --parallel`. À faire : une section « PostgreSQL sous Windows » du README (Docker, et l'installateur Windows avec ce qu'il faut pour la collation C.UTF-8 : fournisseur builtin de PostgreSQL 17, template1 refait), et vérifier que les tests de concurrence (deux processus) tournent sous Windows.

## C7 — Espace de la société : « Vos tickets de caisse restent possibles » s'affiche aussi avant la validation (09/11/2026 vers 10 h 45 UTC, capture ; lot PG, avancé par l'éditeur le 09/11/2026 vers 10 h 55 UTC : « fais-le plus tôt, dans le lot suivant » ; si le lot PG l'a fait, le lot NAV-4 le vérifie seulement)

Constat de l'éditeur : « je vois que tu permetez de faire des ticktes de ventes mais normalement ne doit pas etre possible avant verifie la societe avec courrier "Vos tickets de caisse restent possibles, mais aucune facture à un professionnel ne peut être émise, dans aucun de vos magasins, tant que ces mentions manquent : capital social, immatriculation. " ». Capture : page « Identité de la société » de l'espace de la société (société fictive des fournisseurs factices, forme SAS, capital social et immatriculation vides), encadré « Identité incomplète pour facturer un professionnel ».

Diagnostic de la session (lecture du code au commit 1b34f61, à revérifier) : la règle de `CLAUDE.md` est déjà tenue par le domaine. Avant la validation, un magasin réel ne vend rien : `Magasin::refusDeVente()` (`REFUS_SOCIETE_A_VERIFIER`) ; `ControleDesDroits` refuse les autorisations des opérations commerciales quand `peutVendre()` est faux ; le registre fiscal refuse toute vente et tout encaissement ; les PDF sont refusés. C'est testé par `tests/Feature/Societe/PorteDeValidationTest.php`, et seul le magasin de démonstration vend. Le défaut est dans le texte : l'encadré de `app/Filament/Societe/Pages/IdentiteSociete.php` (section « Identité incomplète pour facturer un professionnel ») et celui du tableau de bord de la société (`resources/views/filament/societe/pages/tableau-de-bord.blade.php`) disent « Vos tickets de caisse restent possibles » dès qu'une mention manque, sans regarder l'état de la société. C'est faux tant qu'elle n'est pas validée, et aussi quand elle est en lecture seule (suspendue, résiliée, révoquée).

À faire :
- un seul texte, construit au même endroit (une méthode du domaine) et utilisé par la page « Identité » et par le tableau de bord, qui dit vrai dans chaque état :
  - société en cours de vérification : aucune vente n'est possible dans ses magasins réels avant sa validation (le magasin de démonstration permet de s'essayer), et ces mentions seront nécessaires pour facturer un professionnel ;
  - société validée : le texte actuel ;
  - société en lecture seule : son motif de lecture seule, sans annoncer de vente possible ;
- relire tous les autres textes montrés à l'écran ou dans un e-mail qui annoncent qu'une vente « reste possible » (par exemple `IdentiteDeLaSocieteIncompleteException`, le « Facture impossible » de la caisse, les messages d'émission), et les rendre justes dans chaque état ;
- tests : page « Identité » et tableau de bord d'une société en cours de vérification (jamais « tickets restent possibles », mais la phrase de la vérification) et d'une société validée (texte actuel) ; un test en navigateur qui ouvre la page « Identité » d'une société en cours de vérification à qui manque une mention ;
- À faire sur le PC de l'éditeur : rien (`git pull`).

## C8 — Fiche d'une facture ou d'un ticket : la section « Paiements reçus » se voit sans le droit de voir les paiements (point T41 du document « Ce qui reste à trancher », réponse de l'éditeur du 10/11/2026 à 23 h 33 UTC ; lot NAV-2)

Question posée (T41) : la section « Paiements reçus » de la fiche d'une facture s'affiche à qui voit la facture, même sans « Paiements : voir » (constat laissé par le lot TR) ; faut-il la cacher à ces comptes ?

Réponse de l'éditeur : « T41 : cacher » pour que cette partie soit cachée à qui n’a pas le droit de voir les paiements ;

À faire :
- la section « Paiements reçus » de la fiche d'une facture ou d'un ticket (`FactureResource::infolist()`) ne s'affiche qu'à un compte qui a le droit de voir les paiements de ce document (« Paiements : voir », avec sa portée : ses documents ou ceux de toute l'équipe) ; le propriétaire, les responsables du magasin et un compte du cabinet lié à qui le client accorde la consultation des paiements la voient ; pour les autres comptes, la section n'apparaît pas du tout (ni titre vide, ni message) ;
- « Payé / Restant dû » reste visible sur la fiche pour qui voit le document (règle de CLAUDE.md, « Caisse et clôtures ») ;
- même règle partout où le détail des paiements d'un document s'affiche sur une fiche du panneau du magasin (liste des paiements, références, reçus de paiement et contre-passations du document) ;
- tests : en navigateur, un vendeur qui voit la facture sans « Paiements : voir » ne voit pas la section, le même avec l'autorisation la voit ; le propriétaire et un responsable la voient ; un test du rendu de la fiche par rôle ; l'inventaire des actions tenu à jour.

## C9 — Fiche d'un brouillon de facture : ajouter « Modifier » et « Émettre » (point T42 du document « Ce qui reste à trancher », réponse de l'éditeur du 10/11/2026 vers 23 h 50 UTC : « oui » à la proposition ; lot NAV-2)

Question posée (T42, constat laissé par le lot NAV-1) : la fiche d'un brouillon de facture n'offre ni « Modifier » ni « Émettre » ; proposition : ajouter ces deux boutons sur la fiche du brouillon, comme sur la liste des factures.

Réponse de l'éditeur, écrite dans le document (ligne T42 ajoutée par lui dans « Mes questions et vos réponses ») : « oui ».

À faire :
- la fiche d'une facture (ou d'un ticket) en brouillon montre « Modifier » et « Émettre », avec les mêmes conditions et les mêmes droits que sur la liste des factures (et « Émettre et encaisser » s'il y est offert), jamais au propriétaire pour l'émission, qui ne vend jamais ; une facture émise n'a plus « Modifier » ;
- tests : en navigateur, depuis la fiche d'un brouillon, « Modifier » ouvre le formulaire et « Émettre » émet la facture (numéro, fiche à jour), pour chaque rôle qui en a le droit ; un compte sans le droit ne voit pas ces boutons ; l'inventaire des actions tenu à jour.
