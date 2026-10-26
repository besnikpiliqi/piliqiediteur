# Série 8 : caisse, clôtures, journal des événements et e-reporting

Points traités : 14, 15 (partie clôture), 21, 24, 25 et 26 de la demande du 17/10/2026.

Code étudié : la copie de travail du banc (`/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/serie8/banc`, copie de HEAD 30844db). Les chemins ci-dessous partent de la racine de cette copie. Le dépôt `/home/user/piliqiediteur` n'a pas été touché, et PostgreSQL n'a pas été utilisé : les essais ont tourné sous SQLite en mémoire.

Essais exécutés (tests jetables, à supprimer avec le banc) :
- `tests/Feature/Scratch/Serie8ClotureJetTest.php` : contenu de la clôture, lignes du journal des événements, naissance d'une soumission d'e-reporting, période manquée, société non validée ;
- `tests/Feature/Scratch/Serie8ExempleJourneeTest.php` : la journée qui sert d'exemple chiffré à la partie 3.

Commande : `php artisan test --compact tests/Feature/Scratch/<fichier>` dans le banc. Les deux fichiers passent, et leurs sorties sont reprises ci-dessous.

---

## 0. L'essentiel en dix lignes

1. **Point 14.** L'abandon d'une vente et les lignes retirées sont tracés parce qu'une caisse qui laisse effacer une vente sans trace permet la fraude visée par la loi : on encaisse, puis on efface. C'est une décision que vous avez déjà prise (D9, 9 octobre 2026), et elle correspond aux codes 320 et 323 du référentiel NF525. La raison est détaillée en 3.1.
2. **Point 21.** Une clôture n'inscrit aujourd'hui **rien** dans la caisse sécurisée, ni événement ni enregistrement signé (vérifié). Il faut inscrire l'événement « clôture » : c'est le code 50 du référentiel NF525, marqué obligatoire, et c'était déjà prévu à l'étape 3. Je recommande de l'avancer. Il ne faut en revanche pas inscrire chaque clic.
3. **Points 15 et 24.** La clôture ne contient aujourd'hui ni les moyens de paiement, ni la somme des avoirs, ni les grands totaux. Elle additionne bien les montants enregistrés, sans rien recalculer : un ticket de 50 € TTC y compte pour 41,67 € HT et 8,33 € de TVA. La loi exige de la clôture un grand total de la période et un total perpétuel. La ventilation par moyen de paiement relève de la norme NF525 et de votre règle « toutes les ventes et leurs règlements entrent dans les clôtures ». Je recommande de l'ajouter.
4. **Point 25.** Les numéros des tickets et des avoirs de la clôture sont du texte simple. Les rendre cliquables est un petit changement.
5. **Point 26.** La page « Soumissions e-reporting » est vide parce qu'une soumission ne naît que la nuit, par le planificateur, le lendemain de la fin d'une période entière (décade, mois ou bimestre). Il faut aussi un magasin réel d'une société validée, avec un régime de TVA renseigné. Le magasin de démonstration n'est jamais déclaré. Et même quand une soumission existe, PILIQI ne construit jamais le contenu de l'envoi. La partie 3.5 explique ce que l'administration reçoit, avec une journée chiffrée.

---

## 1. Ce que fait le code aujourd'hui (vérifié)

### 1.1 La clôture journalière (ticket Z)

**Ce qu'elle regroupe.** `app/Domain/Facturation/Actions/CloturerCaisseAction.php:134-135` retient les tickets de la journée (`Facture::query()->enAttenteDeCloture()`) et les avoirs du jour sur des tickets.
- `app/Domain/Facturation/Models/Facture.php:288-305` : seules les ventes émises à un **particulier** comptent. Les factures aux professionnels n'entrent pas dans la clôture.

**Ce qu'elle enregistre** (`CloturerCaisseAction.php:142-148` ; migration `database/migrations/2026_10_03_000002_create_clotures_caisse_table.php`, colonnes des lignes 19 à 39) :
- un numéro (Z-CODE-AAAA-NNNNNN), la journée, l'auteur et son nom figé, l'heure de la clôture ;
- le nombre de tickets et le nombre d'avoirs ;
- trois totaux **nets** (ventes moins avoirs) : `total_ht`, `total_tva`, `total_ttc` ;
- le lien vers la soumission d'e-reporting qui l'a reprise.

**Ce qu'elle ne contient pas :**
- aucun moyen de paiement ;
- aucun total des ventes brutes ni des avoirs (seulement le net) ;
- aucun crédit d'avoir utilisé ;
- aucun grand total, ni de période ni perpétuel ;
- rien n'est signé.

La ventilation par taux et la séparation entre biens et services ne sont pas enregistrées. Elles se recalculent à l'affichage depuis les lignes enregistrées des tickets et des avoirs (`app/Domain/Facturation/Concerns/VentileLaTvaParTaux.php:41-52` et `96-113`).

**Les montants.** La clôture additionne les totaux enregistrés des documents, en centimes, avec `CalculateurLigne::cumuler()` puis `deduire()` (`app/Domain/Facturation/Services/CalculateurLigne.php:443-501`). Aucune TVA n'est recalculée depuis un total TTC. C'est l'exigence 8 du point 15, déjà respectée.

Essai : un ticket de 50 € TTC à 20 % est enregistré avec PU HT 41,67, HT 41,67, TVA 8,33 et TTC 50,00, et la clôture reprend exactement ces montants. Le 41,66 que vous avez vu ne se reproduit pas dans le banc. C'est à vérifier par la mission du point 15, qui s'occupe du moteur de calcul : ce peut être une version locale plus ancienne ou un autre écran.

**Les grands totaux existent, mais ailleurs.** Chaque ticket, facture ou avoir signé fait avancer, dans le registre fiscal, un grand total perpétuel « réel » (somme des montants) et un « absolu » (somme des valeurs absolues) (`app/Domain/CaisseSecurisee/Services/RegistreFiscal.php:477-481`). Ils comptent **toutes** les ventes, factures aux professionnels comprises. La clôture ne les reprend pas. Résultat de l'essai : clôture de 170,55 € et grand total perpétuel de 220,55 €, la différence étant la facture à un professionnel (voir 3.5). La règle déjà écrite dans CLAUDE.md, « Toutes les ventes et leurs règlements entrent dans les clôtures et les grands totaux », n'est donc pas encore respectée par la clôture. C'est prévu à l'étape 3 (voir 5).

**Résultat de l'essai** (`Serie8ClotureJetTest::test_contenu_de_la_cloture_et_du_jet`) :

| Opération du 05/10/2026 | Paiement enregistré |
|---|---|
| TK-MABOUT-2026-000001, 50,00 € | espèces 50,00 |
| TK-…000002, 85,00 € | carte 85,00 |
| TK-…000003, 56,10 € | chèque 56,10 |
| FAC-MABOUT-2026-000001 (professionnel), 50,00 € | virement 50,00 |
| AV-MABOUT-2026-000001, retour de 35,00 € sur le TK2 | crédit imputé sur le TK4 |
| TK-…000004, 50,00 € | 35,00 par l'avoir, puis espèces 15,00 |

La clôture Z-MABOUT-2026-000001 obtenue contient : 4 ventes, 1 avoir, HT 174,18, TVA 31,92, TTC 206,10, et rien d'autre. Les 50 € par virement, les 65 € en espèces, les 85 € par carte, les 56,10 € par chèque et les 35 € de crédit d'avoir n'y figurent nulle part.

### 1.2 Les écrans des clôtures

`app/Filament/App/Resources/ClotureCaisseResource.php` :
- **liste** (lignes 160 à 181) : numéro, journée, nombre de ventes, nombre d'avoirs, total TTC net, auteur, heure ;
- **fiche** (lignes 98 à 158) :
  - en-tête ;
  - totaux nets, ventilation par taux, ventilation par nature ;
  - « Incluse dans la soumission » (lignes 119 à 128) ;
  - « Ventes agrégées » : numéro, client, HT, TVA, TTC (lignes 130 à 141) ;
  - « Avoirs déduits », en négatif (lignes 143 à 156).

Les numéros (lignes 134 et 149) sont des `TextEntry` sans lien. La fiche n'affiche ni moyen de paiement, ni somme des avoirs (seulement leur nombre, ligne 111, et leur liste), ni grand total.

Le motif à reprendre pour les liens existe déjà dans la fiche d'une facture (`app/Filament/App/Resources/FactureResource.php:269` et `325`, `->url(...)` vers la fiche d'un avoir).

Il existe déjà, ailleurs, une ventilation par mode : le relevé « Fermer ma caisse » de chaque vendeur, qui n'a pas de valeur fiscale (`app/Domain/Facturation/Actions/FermerSaCaisseAction.php:191-193` et `231-241`). La clôture officielle du magasin n'en a pas.

### 1.3 Le journal des événements (JET)

Les codes existants sont dans `app/Domain/CaisseSecurisee/Enums/CodeEvenement.php:55-70`. Chaque événement est signé et chaîné dans la chaîne « événements » du registre fiscal du magasin (`app/Domain/CaisseSecurisee/Enums/ChaineFiscale.php:23`). Il porte l'opérateur : l'auteur contrôlé par l'action, ou à défaut la personne connectée (`RegistreFiscal.php:85-92`).

| Code | Libellé | Quand il s'inscrit |
|---|---|---|
| 40 | Fin de session | déconnexion (`JournaliserFinDeSession`) |
| 80 | Ouverture de session | premier accès d'une session au magasin |
| 90 / 95 | Défaut d'intégrité / rupture de séquence | vérification de l'intégrité |
| 100 / 105 | Entrée / sortie du mode démonstration | ouverture et fermeture du magasin de démonstration |
| 110 | Export de données | export du journal |
| 130 | Modification des droits | création, modification, retrait d'un membre |
| 190 | Annulation de ticket | avoir sur des tickets (`app/Domain/Facturation/Actions/EmettreAvoirAction.php:139`) |
| 250 / 255 | Version du module fiscal | automatique |
| 260 | Initialisation | premier enregistrement du magasin |
| 270 | Paramètre de conformité | réglage fiscal, SIRET, taux d'un produit, clé |
| 280 | Opération de contrôle | chaque vérification |
| 320 | Vente abandonnée avant validation | voir la liste ci-dessous |
| 323 | Ligne retirée avant validation | voir la liste ci-dessous |

Le code 320 s'inscrit dans trois cas :
- « Abandonner la vente » à la caisse (`app/Domain/Facturation/Services/PanierDeCaisse.php:111-130`), avec le nombre de lignes et le total TTC en centimes ;
- une vente restée ouverte à la clôture de sa journée (`PanierDeCaisse.php:145-162`) ;
- la suppression d'un brouillon de facture ou de ticket (`app/Domain/Facturation/Models/Facture.php:133-141`).

Le code 323 s'inscrit pour chaque article qu'une vente perd avant sa validation : ligne retirée, article remplacé ou quantité baissée. Il vaut au panier de la caisse (`PanierDeCaisse.php:84-86`) comme dans un brouillon (`app/Domain/Facturation/Actions/ModifierFactureAction.php:121`). La comparaison se fait article par article (`app/Domain/Facturation/Services/LignesRetirees.php`). Ajouter un article, déplacer une ligne ou changer le prix d'un service ne s'inscrit pas (docblock de `LignesRetirees.php:16-21`).

**La clôture n'inscrit rien.** `CloturerCaisseAction::cloturer()` (lignes 129 à 169) n'appelle jamais `journaliser()`. Elle note seulement la journée sur la ligne de verrou du registre (`RegistreFiscal.php:252-255`).

Essai : le registre comptait 15 lignes avant la clôture et 15 après. Ses lignes, dans l'ordre :
- 260 ;
- trois fois un paiement puis son ticket ;
- un paiement puis la facture au professionnel ;
- l'avoir puis le 190 ;
- un paiement puis le TK4 ;
- le 323 puis le 320.

Il n'y a aucune ligne pour la clôture. C'est ce que vous avez constaté au point 21.

### 1.4 L'abandon à la caisse

Le texte de l'alerte est dans `app/Filament/App/Pages/Caisse.php:360-361` : « Abandonner la vente ? / Les lignes saisies sont effacées, et l'abandon de la vente est inscrit au journal des événements de la caisse. »

L'essai l'a confirmé : le panier passe de deux lignes à une, ce qui inscrit un 323, puis l'abandon inscrit un 320.

### 1.5 La page « Soumissions e-reporting » : pourquoi elle est vide

**Qui crée une soumission ?** Uniquement la commande `app:soumettre-ereporting-periodique` (`app/Console/Commands/SoumettreEReportingPeriodique.php`). Le planificateur la lance chaque nuit à 1 h (`bootstrap/app.php:62`). Rien ne la déclenche depuis un écran (`app/Filament/App/Resources/SoumissionEReportingResource.php:15-23`).

Toutes ces conditions doivent être réunies :
1. **Le planificateur tourne.** En local, il faut `php artisan schedule:work`, l'une des trois commandes du README (`README.md:50-55`). Sans lui, aucune clôture automatique et aucune soumission.
2. **Le magasin est réel et sa société est validée, avec un régime de TVA renseigné** (`SoumettreEReportingPeriodique.php:48-49`). Une société inscrite reste en cours de vérification et ne vend que dans son magasin de démonstration, qui n'est jamais déclaré. Le régime de TVA est vide après l'inscription (`database/migrations/0001_01_00_000000_create_societes_table.php:60`, colonne `nullable`) tant qu'il n'est pas choisi dans les paramètres fiscaux de la société. Essai : une société en cours de vérification donne « 0 soumission(s) d'e-reporting générée(s) ».
3. **La période est terminée.** La commande ne prend que la dernière période **finie** (`app/Integrations/Pdp/Services/CalculateurPeriodeEReporting.php:36-44`) :
   - réel normal mensuel : par décade (du 1er au 10, du 11 au 20, du 21 à la fin du mois) ;
   - réel normal trimestriel et réel simplifié : par mois ;
   - franchise : par bimestre.

   Essai (réel normal mensuel) : une vente du 05/10, clôturée à minuit, ne donne rien le 06/10 à 1 h, puis donne `ER-MABOUT-2026-000001` (période du 01/10 au 10/10, limite le 20/10, HT 41,67, TVA 8,33, TTC 50,00) **le 11/10 à 1 h**. Une vente du 17/10 n'apparaîtra que le 21/10.
4. **La période a au moins une clôture qui compte une vente à un particulier ou un avoir** (`app/Domain/Facturation/Actions/GenererSoumissionEReportingAction.php:53-64`).
5. **La personne a le droit de la voir.** Il faut l'autorisation de voir toutes les clôtures (`app/Policies/SoumissionEReportingPolicy.php:20-23`), ce qui est le cas du propriétaire.

**Défauts trouvés en chemin :**
- **Une période manquée n'est jamais rattrapée.** Essai : vente et clôture le 05/10, planificateur arrêté du 06 au 21, puis relancé le 22/10, le 01/11 et le 11/11. Aucune soumission n'est créée, et la clôture du 05/10 reste à jamais non déclarée. La commande ne calcule que la dernière période finie (`SoumettreEReportingPeriodique.php:53`).
- **La passerelle factice accepte sans rien construire** (`app/Integrations/Pdp/FakePdpGateway.php:48-55`). Sans mandat PDP, rien n'est même tenté (`app/Domain/Facturation/Listeners/DeclencherTransmissionPdpApresSoumissionEReporting.php:20-24`). Le contenu de ce qui serait envoyé n'existe donc nulle part dans PILIQI.
- **La date limite de la 2e décade est fixée à la fin du mois** (`CalculateurPeriodeEReporting.php:57`), alors qu'une recherche antérieure donne « au plus tard le 30 » (`/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/regles-tva-international.md`, section 2.2). C'est à vérifier.
- **Le plan de l'étape 2 prévoit de supprimer ce calendrier interne** : « SUPER PDP tient le calendrier de l'e-reporting : PILIQI envoie chaque clôture journalière ayant des opérations dès qu'elle est faite, une seule fois […] et supprime son calendrier interne ». Source : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/tva/plan-execution.md`, ligne 337. La documentation de SUPER PDP le confirme : les transactions sont « automatically aggregated and sent to the French tax administration (PPF) according to a schedule that depends on the VAT regime configured at the company level » (`tests/Fixtures/SuperPdp/openapi.json`, route POST `/v1.beta/b2c_transactions`, version 1.36.0.beta).

Je ne peux pas savoir laquelle de ces causes s'appliquait chez vous. La plus probable, à vérifier, est que vous avez vendu dans le magasin de démonstration d'une société pas encore validée, ou sans le planificateur.

---

## 2. Ce que dit le droit (sources)

### 2.0 Accès aux sources

Depuis cette machine, les sites officiels sont **injoignables** :
- bofip.impots.gouv.fr, legifrance.gouv.fr, impots.gouv.fr et economie.gouv.fr refusent la connexion (proxy, code 403) ;
- l'outil de lecture de pages échoue aussi sur les sites d'éditeurs.

Les passages ci-dessous viennent donc :
- des extraits renvoyés par le moteur de recherche le 17/10/2026 ;
- de recherches antérieures déjà consignées dans le dossier de travail : `recherche_caisse.json` (06/10/2026) et `caisse-securisee-conception.json` (11/10/2026), tous deux dans `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/`.

Ce ne sont pas des lectures directes des textes. Les numéros de paragraphes du BOFiP restent **à vérifier** dans la version du 25/03/2026.

### 2.1 Pourquoi tracer l'abandon d'une vente et les lignes retirées

- **L'objet de la loi.** L'art. 286 I 3° bis du CGI, issu de la loi de finances pour 2016 (art. 88), a été créé « afin de lutter contre la fraude à la TVA liée à l'utilisation de logiciels permettant la dissimulation de recettes ». Il « tend à rendre impossible la fraude consistant à reconstituer par un logiciel frauduleux des tickets de caisse pour soustraire des paiements en espèces des recettes ». Source : question écrite AN n° 97756, 14e législature (https://questions.assemblee-nationale.fr/dyn/14/questions/QANR5L14QE97756.pdf), extrait du moteur de recherche. Un fiscaliste résume : des logiciels « permettai[en]t aux commerçants de supprimer certaines opérations enregistrées », surtout les paiements en espèces (https://www.legifiscal.fr/impots-entreprises/lutte-contre-fraude-fiscale/controle-fiscal/logiciels-caisse-securises.html).
- **Les données protégées commencent avant le ticket.** Le BOFiP vise toutes les données liées à une transaction qui peut conduire à un justificatif, que celui-ci soit émis avant, en même temps ou après le règlement. La DGFiP parle de l'opération « de la prise de commande jusqu'à l'enregistrement du règlement ». S'y ajoutent les données de modification. Sources : BOI-TVA-DECLA-30-10-30, § 50 environ (https://bofip.impots.gouv.fr/bofip/10691-PGP.html/identifiant=BOI-TVA-DECLA-30-10-30-20251001) ; recherche du 11/10/2026, exigence DONNEES-01 ; formulation proche relevée le 17/10 chez la FNA (https://fna.fr/documents/obligation-dutiliser-un-logiciel-de-caisse-conforme/).
- **La sécurisation interdit la disparition sans trace.** Le système doit empêcher la suppression ou la modification des données « sans trace ». Le but n'est pas de restreindre les droits mais de mémoriser et tracer les encaissements saisis par toute personne, d'où l'identification de l'opérateur. Sources : BOI-TVA-DECLA-30-10-30, partie II-B (version 2021 : https://bofip.impots.gouv.fr/bofip/10691-PGP.html/identifiant=BOI-TVA-DECLA-30-10-30-20210519) ; recherche du 11/10/2026, exigence ISCA-S-01. Une correction ne se fait que par des opérations « de plus et de moins », jamais par modification des données d'origine (BOI-TVA-DECLA-30-10-30-20160803, passage cité mot pour mot dans `recherche_caisse.json`).
- **La norme de référence a des codes pour ces cas.** Le dictionnaire du journal des événements techniques de la norme NF525 (chapitre R19) a un code pour l'abandon d'une opération non enregistrée (320) et un pour l'annulation d'une ligne d'article (323), d'après des tables d'éditeurs. Le texte du référentiel d'INFOCERT n'a pas pu être lu : il n'a été trouvé ni dans les résultats de recherche ni sur un site joignable. Les codes 320 et 323 de PILIQI suivent ce dictionnaire, comme le dit `CodeEvenement.php:7-11`.
- **Ce n'est pas une obligation littérale pour l'attestation.** Depuis le 21/02/2026, la conformité se prouve soit par un certificat (NF525 ou LNE), soit par une attestation de l'éditeur (loi n° 2026-103, art. 125 ; https://bofip.impots.gouv.fr/bofip/15035-PGP.html/ACTU-2026-00073). Le JET n'est imposé mot pour mot que par la certification. Avec l'attestation, c'est l'éditeur qui doit pouvoir montrer que sa caisse ne laisse rien disparaître sans trace. Le JET est la manière de le montrer.

### 2.2 Quels événements au JET, et pourquoi pas chaque clic

- **Ce que le référentiel demande.** Selon la table R19 reprise par l'éditeur RetailForce (https://support.retailforce.cloud/hc/en-gb/articles/7076661762961-EventCode-mapping-Technical-Event-Log-NF525-R19, extraits du moteur de recherche), chaque code est marqué « X » (obligatoire), « C » (obligatoire dès que la fonction existe) ou « O » (facultatif). Le code **50, « Period closing (Day/Month) », est marqué X**. La même table cite 30 (archivage d'exercice, X) et 40 (fermeture de session, C). La recherche du 11/10/2026 donne aussi 60 (clôture annuelle ou d'exercice, X), 20 (archivage de période, C) et 80 (ouverture de session, C), à recouper avec le référentiel.
- **La logique.** On inscrit ce qui touche à l'intégrité fiscale :
  - sessions, clôtures, archivages, exports ;
  - changements de paramètres, de droits et de version ;
  - anomalies ;
  - mode école ;
  - et tout ce qui fait disparaître une vente ou une partie de vente avant son enregistrement : abandon, ligne retirée.
- **Pourquoi pas chaque clic.** Aucun texte trouvé ne le demande : le BOFiP vise les données d'encaissement et leurs modifications, pas les gestes d'écran. Tout inscrire noierait les événements utiles et ferait conserver des années des traces sur le comportement des vendeurs, sans finalité fiscale, contre le principe de minimisation du RGPD (art. 5, 1, c). PILIQI suit déjà cette règle : il trace ce qu'une vente **perd**, jamais ce qu'elle gagne (ajout d'une ligne, recherche d'un produit, ouverture d'une fenêtre). L'adresse IP et le navigateur sont rangés à part et effacés après un an.
- **Une question reste ouverte.** Une **baisse de prix** d'un service avant validation n'est pas tracée (`LignesRetirees.php:16-18`). Elle ne retire pas d'article, mais elle diminue la vente. Le risque est limité par l'autorisation « accorder des remises et fixer le prix d'un service » (règle R2, lot A6), mais un vendeur qui l'a pourrait baisser un prix après avoir encaissé. Faut-il la tracer (code 323 ou autre) ? C'est à trancher, voir la question Q4.

### 2.3 Ce qu'une clôture (ticket Z) doit contenir

- **La loi.** Le système doit prévoir une clôture journalière, une mensuelle et une annuelle (ou par exercice), « cumulatives et impératives ». « Pour chaque clôture, des données cumulatives et récapitulatives, intègres et inaltérables, doivent être calculées et enregistrées […] comme le cumul du grand total de la période et le total perpétuel », et les totaux de contrôle des clôtures ne sont jamais purgés. Sources : BOI-TVA-DECLA-30-10-30, § 160 à 180 environ (versions du 01/10/2025 et du 16/04/2025), cité dans `recherche_caisse.json` ; confirmé le 17/10 par l'extrait Weblex, « Les systèmes de caisse doivent […] prévoir obligatoirement une clôture journalière et une clôture mensuelle » (https://www.weblex.fr/sites/default/files/images/docs/annexe-conditions2021.pdf), et par https://bofip.impots.gouv.fr/node/11707, point 120.
- **Les corrections.** Avoirs, retours et annulations sont des opérations « en moins » : ils doivent apparaître comme tels, d'où la distinction entre grand total réel et grand total absolu.
- **Ce que la loi ne détaille pas.** Aucune liste officielle du contenu du ticket Z (ventilation par taux, par moyen de paiement, nombre de tickets) n'a été trouvée, ni le 06/10 ni le 17/10. Ces éléments viennent de la norme NF525 et de la pratique des éditeurs : ventes et retours par taux de TVA, montants par moyen de paiement (espèces, carte, chèque, virement…), annulations, nombre de tickets, grand total perpétuel. Sources : https://digablopos.fr/fr/blog/rapport-z-caisse-enregistreuse et https://www.topcaisse.fr/ticket-z/ (non officielles), et la plaquette NF525 d'INFOCERT citée le 06/10 (« Montants TTC ventilés par taux de TVA », clôtures « avec ventilation par taux de TVA et par mode de paiement »).
- **La règle PILIQI déjà décidée** (CLAUDE.md, « Caisse et clôtures ») : « Toutes les ventes et leurs règlements entrent dans les clôtures et les grands totaux. »

### 2.4 Ce que contient l'e-reporting des ventes aux particuliers

- **Données de transaction (flux 10.3), art. 290 du CGI et art. 242 nonies M de l'annexe II.** Selon la fiche officielle « e-reporting, données de transaction » (https://www.impots.gouv.fr/e-reporting-donnees-de-transaction, mise à jour en août 2026, extrait du moteur de recherche), les données sont transmises « de façon agrégée par jour ». Elles comprennent :
  - en en-tête : le SIREN, la période, et le cas échéant l'option pour le paiement de la TVA d'après les débits ;
  - pour chaque jour : la base HT totale et la TVA correspondante, par taux s'il y en a plusieurs ;
  - les catégories : livraisons de biens et prestations de services soumises à la TVA, opérations intracommunautaires, et régime de la marge (art. 266 e, 268, 297 A).
- **Les catégories et le format.** SUPER PDP accepte quatre catégories, « as documented in the Annex A of the Z12-012 AFNOR norm » : **TLB1** (livraisons de biens), **TPS1** (prestations de services), **TNT1** (opérations non soumises à la TVA en France), **TMA1** (régime de la marge). Une transaction porte la date, la devise, le sens (`SE` pour une vente), la base HT, la TVA, et des sous-totaux par taux (base et TVA). Source : `tests/Fixtures/SuperPdp/openapi.json`, schémas `b2c_transaction` et `b2c_transaction_subtotal`. Une vente mixte compte dans chacune des deux catégories (cas AFNOR n° 31, https://www.compta-online.com/facture-mixte-biens-et-services-ao8793).
- **Aucune donnée nominative.** Il n'y a ni nom du client ni numéro de ticket.
- **Le nombre de transactions.** La norme AFNOR prévoit un champ « nombre de transactions » (TT-84, d'après Compta-Online). Le gouvernement a annoncé en septembre 2025 la fin de l'obligation de le transmettre en B2C (https://kpmg.com/av/fr/avocats/eclairages/2025/09/facturation-electronique-mesures-de-simplification-et-de-tolerance.html). Le texte modificatif n'a pas été trouvé : à vérifier. Le schéma SUPER PDP 1.36.0.beta n'a **aucun** champ de nombre. PILIQI ne l'enverrait donc pas, mais le Z garde le nombre de tickets.
- **Données de paiement (flux 10.4), art. 290 A du CGI.** Elles ne concernent que les **prestations de services** dont la TVA est exigible à l'encaissement, hors option pour les débits et hors autoliquidation. On transmet la date d'encaissement effectif et le montant encaissé par taux (https://www.impots.gouv.fr/e-reporting-donnees-de-paiement, extrait). Rien pour les biens. Chez SUPER PDP : route POST `/v1.beta/b2c_payments` (date, sous-totaux par taux : montant, taux, devise).

  Deux incohérences de la spécification sont à vérifier au bac à sable de l'étape 2 : `b2c_payment` exige un `company_id` absent de ses propriétés, et `b2c_payment_subtotal` exige un `category_code` lui aussi absent.

  Règle de CLAUDE.md : « les paiements de particuliers pour des services ne sont déclarés qu'une fois la règle confirmée par l'expert-comptable de la société ».
- **Le calendrier.** C'est SUPER PDP qui regroupe et transmet selon le régime de la société : par décade au réel normal mensuel, par mois au réel normal trimestriel et au simplifié, par bimestre en franchise (art. 242 nonies O, d'après `regles-tva-international.md`). L'obligation commence au 1er septembre 2027 pour les PME, selon des sources non officielles (par exemple https://www.shine.fr/blog/facturation-electronique-e-reporting/) : à vérifier.

---

## 3. Explications pour l'éditeur

### 3.1 Point 14 : pourquoi inscrire l'abandon d'une vente

Imaginez la fraude la plus simple, celle que la loi anti-fraude de 2016 vise d'abord. Un client achète un article de 50 € et paie en espèces. Le vendeur saisit l'article, prend le billet, puis appuie sur « Abandonner la vente » au lieu de « Encaisser ». Aucun ticket n'existe, et 50 € sortent des recettes, donc 8,33 € de TVA ne sont jamais reversés. Même chose avec une ligne retirée : le client paie trois articles, le vendeur en retire un avant de valider et garde la différence.

Si la caisse laissait faire cela sans trace, elle serait exactement le « logiciel permissif » que la loi interdit, et l'attestation de conformité que vous signez comme éditeur serait fausse. En inscrivant chaque abandon (code 320) et chaque ligne retirée (code 323), signés et chaînés, avec le nom du vendeur, l'heure, le nombre d'articles et le montant, PILIQI rend la fraude visible :
- pour un contrôleur ;
- **et pour le propriétaire du magasin**, qui voit par exemple qu'un vendeur abandonne dix ventes en espèces par jour.

Ce n'est pas une accusation : abandonner une vente est normal (client parti, erreur de saisie). Seule la trace est obligatoire. C'est votre décision D9 du 9 octobre 2026, qui correspond aux codes 320 et 323 de la norme NF525.

Suggestion facultative (C6) : expliquer la raison dans l'alerte, par exemple « Abandonner la vente ? Les lignes saisies sont effacées. Pour la sécurité de la caisse, l'abandon est inscrit au journal des événements avec votre nom, le nombre d'articles et le montant. »

### 3.2 Point 21 : l'événement de clôture, et pas chaque clic

Vous avez raison : une clôture n'apparaît pas dans « Caisse sécurisée », parce qu'elle n'y écrit rien aujourd'hui (essai : 15 lignes avant, 15 après).

Il faut l'inscrire. La norme NF525 marque la clôture de période (code 50) comme obligatoire. La loi demande que chaque clôture produise des totaux intègres et inaltérables, ce qui suppose qu'elle laisse une trace signée. C'était prévu pour l'étape 3 (« numéro Z, insertion de la clôture, enregistrement signé […], puis JET 50 », conception de la caisse sécurisée du 11/10/2026). Je recommande d'**avancer tout de suite l'événement 50** (changement C1), et de garder pour l'étape 3 la signature de la clôture elle-même.

Inscrire chaque clic : non. Aucun texte ne le demande. Cela noierait les vrais signaux et garderait des années, sans raison fiscale, des traces sur le travail des vendeurs, ce que le RGPD interdit. On inscrit les sessions, les clôtures, les exports, les réglages, les droits, les anomalies, et tout ce qui fait disparaître une vente avant son enregistrement. Rien d'autre.

### 3.3 Points 15 (clôture) et 24 : moyens de paiement et avoirs dans la clôture

**Aujourd'hui**, la clôture ne donne qu'un total net (ventes moins avoirs) des seules ventes aux particuliers, avec la ventilation par taux. Elle ne dit pas comment l'argent est entré.

**Ce que demande l'État** : chaque clôture doit garder un grand total de la période et un total perpétuel jamais remis à zéro, et les corrections (avoirs) doivent rester visibles comme des opérations « en moins ». Le détail par moyen de paiement n'est écrit dans aucun texte officiel trouvé, mais c'est la pratique de la norme NF525 et de tous les tickets Z du marché. Votre règle le demande déjà : « toutes les ventes et leurs règlements entrent dans les clôtures ». L'e-reporting, lui, n'utilise pas les moyens de paiement.

**Je recommande que la clôture affiche** (changement C2) :
- les ventes du jour (nombre, HT, TVA, TTC par taux), en séparant les ventes aux particuliers, qui sont la base de l'e-reporting, et les factures aux professionnels ;
- les avoirs du jour, séparément (nombre, montants par taux), puis le net ;
- les encaissements par moyen de paiement (espèces, carte, chèque, virement, prélèvement, et plus tard « avoir » si le point 1 l'ajoute), contre-passations déduites ;
- le crédit d'avoir utilisé, qui règle des ventes sans entrer en caisse ;
- les grands totaux perpétuels du magasin.

**Ventes et encaissements ne s'égalent pas, et c'est normal.** Dans l'exemple de la partie 3.5, les ventes nettes font 220,55 € et les encaissements 270,55 €. Les 50 € d'écart sont l'avoir : le client a gardé son crédit, puisque PILIQI ne rembourse jamais.

**Paiements en plusieurs fois** : quand le point 23 existera, un ticket aura plusieurs paiements, et chacun comptera dans son moyen de paiement. Rien de spécial à prévoir dans la clôture.

**Remboursements** : votre règle dit « Rien n'est jamais remboursé : un retour donne un crédit ». La clôture n'a donc pas de ligne de remboursement, mais une ligne d'avoirs et une ligne de crédit d'avoir utilisé. Si vous voulez un jour rembourser en espèces, c'est une décision nouvelle (question Q3).

**Montants** : la clôture additionne déjà les montants enregistrés de chaque document, au centime, sans rien recalculer. C'est votre exigence 8, déjà respectée.

### 3.4 Point 25 : numéros cliquables

Oui. Chaque numéro de ticket ou d'avoir de la fiche de clôture ouvrira le document complet (changement C3), si la personne a le droit de le voir. Sinon, le numéro reste du texte simple.

### 3.5 Point 26 : ce que l'administration reçoit, sur une journée chiffrée

Votre schéma est juste : **tickets et avoirs détaillés → clôture Z → totaux journaliers par catégorie et par taux → envoi par la plateforme agréée**. L'État ne reçoit ni chaque ticket, ni un seul total TTC.

Exemple : la journée du lundi 5 octobre 2026 du magasin « Ma Boutique » (code MABOUT), au réel normal mensuel. Les chiffres sortent de l'essai `Serie8ExempleJourneeTest` : ce sont les montants que PILIQI enregistre réellement.

**1. Les documents détaillés** (ils restent dans PILIQI, signés)

| Document | Client | Contenu | HT | TVA | TTC | Règlement |
|---|---|---|---|---|---|---|
| TK-MABOUT-2026-000001 | comptoir | 2 câbles (bien, 20 %) à 50 € | 83,34 | 16,66 | 100,00 | espèces 100,00 |
| TK-MABOUT-2026-000002 | comptoir | 1 livre (bien, 5,5 %) à 10,55 € et 1 réparation (service, 20 %) à 60 € | 60,00 | 10,55 | 70,55 | carte 70,55 |
| TK-MABOUT-2026-000003 | comptoir | 1 câble | 41,67 | 8,33 | 50,00 | carte 50,00 |
| FAC-MABOUT-2026-000001 | professionnel | 1 câble | 41,67 | 8,33 | 50,00 | virement 50,00 |
| AV-MABOUT-2026-000001 | comptoir | retour d'un câble du TK1 | −41,67 | −8,33 | −50,00 | crédit de 50 € gardé par le client (bon d'avoir) |

**2. La clôture Z du soir**

Aujourd'hui, Z-MABOUT-2026-000001 contient : 3 ventes, 1 avoir, HT 143,34, TVA 27,21, TTC 170,55. Par taux, cela fait 20 % : 133,34 / 26,66 / 160,00, et 5,5 % : 10,00 / 0,55 / 10,55. Par nature :
- biens à 20 % : 83,34 / 16,66 ;
- biens à 5,5 % : 10,00 / 0,55 ;
- services à 20 % : 50,00 / 10,00.

Le registre, lui, a fait avancer le grand total perpétuel réel à 220,55 € et l'absolu à 320,55 €. Ces deux totaux comptent la facture au professionnel, que la clôture n'a pas.

La clôture proposée (C2) ajouterait :
- ventes aux particuliers : 220,55 TTC (185,01 HT, 35,54 TVA) ;
- avoirs : −50,00 TTC (−41,67 HT, −8,33 TVA) ;
- net des ventes aux particuliers : 170,55 ;
- factures aux professionnels : 50,00 TTC ;
- encaissements : espèces 100,00, carte 120,55, virement 50,00, soit 270,55 au total ;
- crédit d'avoir utilisé : 0,00 ;
- grands totaux perpétuels : réel 220,55, absolu 320,55.

**3. Les totaux journaliers que PILIQI enverrait** (ventes aux particuliers seulement)

| Date | Catégorie | Taux | Base HT | TVA |
|---|---|---|---|---|
| 05/10/2026 | TLB1, livraisons de biens | 20 % | 83,34 | 16,66 |
| 05/10/2026 | TLB1, livraisons de biens | 5,5 % | 10,00 | 0,55 |
| 05/10/2026 | TPS1, prestations de services | 20 % | 50,00 | 10,00 |
| | **Total** | | **143,34** | **27,21** |

Comment se lit cette table :
- La ligne TLB1 à 20 % vaut les deux câbles du TK1, plus le câble du TK3, moins le câble rendu : 83,34 + 41,67 − 41,67 = 83,34 € HT.
- Le total, 143,34 € HT et 27,21 € de TVA, est exactement le net de la clôture.
- La facture au professionnel n'y est pas : elle partira à l'étape 2 comme facture électronique.
- Aucun nom, aucun numéro de ticket, aucun moyen de paiement n'est envoyé.

**4. Les données de paiement** (services seulement)

La réparation de 60 € TTC à 20 % est payée par carte le 5 octobre. PILIQI déclarerait : 05/10/2026, taux 20 %, 60,00 € encaissés. Cela n'aura lieu qu'une fois la règle confirmée par l'expert-comptable de la société, comme le dit CLAUDE.md. Le livre et les câbles sont des biens : ils ne donnent aucune donnée de paiement.

**5. La suite, hors de PILIQI**

SUPER PDP garde ces lignes et les regroupe par décade, ici du 1er au 10 octobre, puis les transmet à l'administration. L'échéance est le 20 octobre d'après une recherche antérieure (`regles-tva-international.md`, section 2.2). Elle n'est pas confirmée par le texte officiel : à vérifier. Une journée avec seulement des avoirs donnerait des montants négatifs, ce qui sera à vérifier au bac à sable.

**Annexe technique, pour le développeur.** Voici le corps des deux envois. Les noms des champs sont imposés par l'API de SUPER PDP (version 1.36.0.beta).

Leur sens :
- `date` : la journée ;
- `role_code` `SE` : une vente ;
- `category_code` : la catégorie ;
- `currency` / `currency_code` : la devise ;
- `tax_exclusive_amount` : la base HT ;
- `tax_total` : la TVA ;
- `tax_subtotals` : les sous-totaux par taux ;
- `tax_percent` : le taux ;
- `taxable_amount` : la base du taux ;
- `amount` : le montant encaissé.

```json
POST /v1.beta/b2c_transactions
{"data": [
  {"date": "2026-10-05", "role_code": "SE", "category_code": "TLB1", "currency": "EUR",
   "tax_exclusive_amount": "93.34", "tax_total": "17.21",
   "tax_subtotals": [
     {"tax_percent": "20.0", "taxable_amount": "83.34", "tax_total": "16.66"},
     {"tax_percent": "5.5", "taxable_amount": "10.00", "tax_total": "0.55"}]},
  {"date": "2026-10-05", "role_code": "SE", "category_code": "TPS1", "currency": "EUR",
   "tax_exclusive_amount": "50.00", "tax_total": "10.00",
   "tax_subtotals": [{"tax_percent": "20.0", "taxable_amount": "50.00", "tax_total": "10.00"}]}
]}

POST /v1.beta/b2c_payments   (seulement après la confirmation de l'expert-comptable)
{"data": [{"date": "2026-10-05",
  "subtotals": [{"tax_percent": "20.0", "amount": "60.00", "currency_code": "EUR"}]}]}
```

À l'écran, PILIQI n'affichera que la table en français de l'étape 3, jamais ces noms techniques.

---

## 4. Changements à faire, avec leurs tests

Toutes les lectures proposées restent dans le magasin de la clôture : les modèles `ClotureCaisse`, `Facture`, `Avoir`, `Paiement`, `AvoirAllocation` et `EnregistrementFiscal` utilisent `BelongsToMagasin`. Aucune requête ne contourne `MagasinScope`. Les commandes planifiées gardent `runAs` par magasin. Chaque lot devra lancer les tests ciblés, puis les suites complètes SQLite et PostgreSQL et `vendor/bin/pint --dirty --format agent` avant son commit, selon le protocole.

### C1. L'événement 50 « clôture » au journal, à chaque clôture

- **`CodeEvenement`** : `case CloturePeriode = 50;`, libellé « Clôture de période (journée) », et une puce dans le docblock (« s'inscrit à chaque clôture du jour, manuelle, automatique, rattrapée ou à zéro »). Le code 60 (exercice) attendra les clôtures d'exercice de l'étape 3.
- **`CloturerCaisseAction::cloturer()`** : après l'enregistrement de la clôture et avant `noterLaJourneeCloturee()`, dans la même transaction et sous le même verrou, `$this->registre->journaliser(CodeEvenement::CloturePeriode, [...], $cloture)`. Les détails, sans nombre à virgule :
  - numéro, journée ;
  - nombre de tickets et d'avoirs ;
  - totaux nets en centimes ;
  - encaissements par mode en centimes, dès que C2 existe ;
  - grands totaux perpétuels réel et absolu lus sur la tête du registre. Il faut pour cela un accesseur `RegistreFiscal::grandsTotaux(Magasin)`, lu sous le verrou.

  L'opérateur est l'auteur de la clôture, ou personne pour la clôture de minuit (`parLeSysteme()`, déjà en place).
- **`EnregistrementFiscal::libelleDuDocument()`** (`app/Domain/CaisseSecurisee/Models/EnregistrementFiscal.php:159-176`) : pour un événement dont le document est une clôture, afficher « Clôture Z-MABOUT-2026-000001 » au lieu de « Cloture caisse (identifiant interne 1) ».
- **Tests :**
  - `CloturerCaisseActionTest::test_chaque_cloture_inscrit_l_evenement_50_avec_son_numero_et_ses_totaux` ;
  - `CloturerCaisseActionTest::test_une_cloture_a_zero_inscrit_aussi_l_evenement_50` ;
  - `CloturerCaisseActionTest::test_l_evenement_50_porte_les_grands_totaux_perpetuels_du_registre` (220,55 et 320,55 sur la journée d'exemple) ;
  - `CloturerCaisseActionTest::test_une_cloture_refusee_n_inscrit_aucun_evenement` ;
  - `ClotureAutomatiqueCaisseTest::test_la_cloture_de_minuit_inscrit_l_evenement_50_sans_operateur` et `test_chaque_journee_rattrapee_inscrit_son_evenement_50` ;
  - `MagasinDeDemonstrationTest::test_la_cloture_du_magasin_de_demonstration_s_inscrit_dans_son_propre_journal` ;
  - `EnregistrementFiscalResourceTest::test_l_evenement_de_cloture_nomme_son_ticket_z` ;
  - les tests de la vérification d'intégrité existants doivent rester verts (`caisse:verifier` sur un magasin qui a des clôtures).

### C2. La clôture garde ses ventes, ses avoirs, ses encaissements par mode et les grands totaux

- **Migration** `2026_10_03_000002_create_clotures_caisse_table.php`, modifiée sur place puis `migrate:fresh` :
  - une colonne `recapitulatif` (json), écrite une seule fois à la clôture, en centimes entiers ;
  - une colonne `derniere_sequence_encaissement` (entier, nullable).

  Forme proposée du récapitulatif, celle que l'étape 3 signera telle quelle :
  - `ventes_particuliers` et `avoirs_particuliers` : nombre, HT, TVA, TTC et détail par taux ;
  - `factures_professionnels` et `avoirs_professionnels` : mêmes rubriques, à titre d'information. Elles n'entrent pas dans `total_*`, qui restent le net des ventes aux particuliers, base de l'e-reporting ;
  - `encaissements` : mode vers montant, contre-passations déduites, avec le nombre de paiements ;
  - `credit_avoir_impute` : crédit d'avoir imputé sur les ventes de la journée, au moment de la clôture, comme `FermerSaCaisseAction.php:192` ;
  - `grands_totaux` : `perpetuel_reel` et `perpetuel_absolu`.
- **Source des encaissements : la chaîne « encaissements » du registre**, comme le prévoit déjà la conception de l'étape 3 (« Encaissements par mode, depuis la chaîne encaissement. Le Z actuel ne les contient pas »). Sont retenus les enregistrements dont la séquence suit la dernière séquence couverte par la clôture précédente et dont l'horodatage précède la fin de la journée à Paris. On lit dans leur contenu signé le mode et le montant en centimes (`app/Domain/CaisseSecurisee/Services/ContenuFiscal.php:100-112`). Ce choix a trois avantages :
  - chaque paiement compte dans une seule clôture, sans écrire sur le paiement (protégé par `ProtegeLesDonneesValidees`) et sans table de liaison ;
  - un paiement enregistré après la clôture de sa journée compte dans la suivante ;
  - un paiement saisi avec une date de réception passée (permise par `app/Domain/Facturation/Actions/EnregistrerPaiementAction.php:90`) compte le jour où il est enregistré.
- **`CloturerCaisseAction`** remplit le récapitulatif.
- **`ClotureCaisseResource`** affiche trois sections :
  - « Ventes et avoirs » : ventes brutes, avoirs (avec leur somme), net, factures aux professionnels ;
  - « Encaissements » : par mode, crédit d'avoir utilisé, avec la phrase « Ventes et encaissements diffèrent quand un client garde un crédit d'avoir ou paie plus tard » ;
  - « Grands totaux » : perpétuel réel et absolu.

  La liste gagne une colonne « Encaissé ».
- **Tests :**
  - `CloturerCaisseActionTest::test_la_cloture_totalise_les_encaissements_de_la_journee_par_mode_de_paiement` (espèces 100,00, carte 120,55, virement 50,00) ;
  - `CloturerCaisseActionTest::test_une_contre_passation_se_deduit_du_mode_du_paiement_contre_passe` ;
  - `CloturerCaisseActionTest::test_un_paiement_enregistre_apres_la_cloture_compte_dans_la_cloture_suivante` ;
  - `CloturerCaisseActionTest::test_un_paiement_ne_compte_jamais_dans_deux_clotures` ;
  - `CloturerCaisseActionTest::test_un_paiement_date_d_un_jour_deja_cloture_compte_le_jour_de_son_enregistrement` ;
  - `CloturerCaisseActionTest::test_le_credit_d_avoir_impute_n_est_pas_un_encaissement` (TK4 de l'essai : 35,00 de crédit, 15,00 en espèces) ;
  - `CloturerCaisseActionTest::test_la_cloture_separe_les_ventes_et_les_avoirs_par_taux` ;
  - `CloturerCaisseActionTest::test_les_factures_aux_professionnels_sont_dans_le_recapitulatif_mais_pas_dans_le_net_declare` ;
  - `CloturerCaisseActionTest::test_la_cloture_reprend_les_montants_enregistres_sans_rien_recalculer` (50 € TTC donne 41,67 et 8,33) ;
  - `CloturerCaisseActionTest::test_le_recapitulatif_reprend_les_grands_totaux_du_registre` ;
  - `CloturerCaisseActionTest::test_deux_magasins_ne_melangent_jamais_leurs_encaissements` (isolation) ;
  - après le point 23 : `test_un_ticket_paye_en_deux_modes_compte_dans_chacun` ;
  - `ClotureCaisseResourceTest::test_la_fiche_affiche_les_encaissements_par_mode_et_la_somme_des_avoirs`.

### C3. Numéros de tickets et d'avoirs cliquables

- **`ClotureCaisseResource`** (lignes 134 et 149) : `->url(fn (Facture $record): ?string => auth()->user()->can('view', $record) ? FactureResource::getUrl('view', ['record' => $record]) : null)->color('primary')`, et l'équivalent pour un avoir avec `AvoirResource`. Les URL restent dans le magasin de la clôture, puisque la page est sous le magasin actif.
- **Tests :**
  - `ClotureCaisseResourceTest::test_les_numeros_des_tickets_et_des_avoirs_ouvrent_leur_fiche` ;
  - `ClotureCaisseResourceTest::test_un_numero_reste_sans_lien_pour_qui_ne_peut_pas_ouvrir_le_document` (vendeur qui peut clôturer, sans « voir celles de toute l'équipe », ticket d'un autre vendeur).
- **À trancher (question Q5).** Ce vendeur voit déjà aujourd'hui, dans sa clôture, le numéro, le client et le montant des tickets des autres. C'est contraire à l'esprit de `Facture::scopeVisiblesPar()` (`Facture.php:237-253`).

### C4. Aperçu de l'e-reporting sur chaque clôture, avant l'étape 2

- **Service pur**, nouveau (par exemple `app/Domain/Facturation/Services/EReportingDesParticuliers.php`) : à partir d'une clôture, il produit :
  - la liste des transactions de la journée par catégorie et par taux (base HT, TVA, en centimes), depuis les lignes enregistrées des tickets moins celles des avoirs. Pas de recalcul : c'est le regroupement que fait déjà `ventilation_par_nature` ;
  - la liste des encaissements de services par taux, marqués « en attente de la confirmation de l'expert-comptable » ;
  - les lignes non déclarables (taux 0 %, franchise, vente exonérée), avec leur motif, conformément à CLAUDE.md (« Une vente à 0 % n'est pas transmise tant que son motif d'exonération n'est pas géré »).

  La correspondance des catégories est aujourd'hui « bien » vers TLB1 et « service » vers TPS1. TNT1 viendra avec le moteur de TVA (lots M3 et M4) et TMA1 avec l'étape 14. Une petite énumération porte les catégories et leurs libellés français (« Livraisons de biens », « Prestations de services », « Opérations non soumises à la TVA en France », « Régime de la marge »). Aucune colonne n'est créée, conformément au plan TVA qui interdit de créer avant l'étape 2 une « catégorie B2C » enregistrée (`tva/plan-execution.md:811`). L'étape 2 réutilisera ce service pour construire l'envoi : il n'y aura rien à recalculer.
- **Fiche de la clôture** : une section « Aperçu de l'e-reporting (non transmis) » avec la table en français de la partie 3.5, le contrôle « total de l'aperçu = net de la clôture », et la mention « Magasin de démonstration : jamais déclaré » le cas échéant. La section actuelle « Incluse dans la soumission » disparaît ou change avec C5.
- **Tests** (`Feature/Facturation/EReportingDesParticuliersTest`) :
  - `test_une_journee_donne_une_ligne_par_categorie_et_par_taux` (exemple de la partie 3.5) ;
  - `test_l_apercu_egale_le_net_de_la_cloture` ;
  - `test_un_avoir_du_jour_se_deduit_de_sa_categorie_et_de_son_taux` ;
  - `test_une_journee_qui_n_a_que_des_avoirs_donne_des_montants_negatifs` ;
  - `test_une_facture_a_un_professionnel_n_entre_jamais_dans_l_apercu` ;
  - `test_une_journee_sans_operation_n_a_rien_a_declarer` ;
  - `test_une_ligne_a_zero_pour_cent_est_signalee_et_jamais_declaree` ;
  - `test_l_encaissement_d_un_service_est_ventile_par_taux_et_marque_a_confirmer` ;
  - `test_un_ticket_mixte_paye_en_une_fois_ne_declare_que_la_part_du_service` ;
  - `test_le_magasin_de_demonstration_n_a_rien_a_declarer` ;
  - `test_l_apercu_ne_lit_que_les_documents_du_magasin_de_la_cloture` (isolation : deux magasins, même journée) ;
  - `ClotureCaisseResourceTest::test_la_fiche_montre_l_apercu_de_l_e_reporting_en_francais` (« Livraisons de biens », « 83,34 € », aucun nom de champ anglais).

### C5. La page « Soumissions e-reporting »

- **Option A, recommandée, à valider par vous (question Q1).** Supprimer dès maintenant le calendrier interne que l'étape 2 doit de toute façon supprimer :
  - `SoumissionEReporting`, `GenererSoumissionEReportingAction`, `SoumettreEReportingPeriodique` et sa planification ;
  - `CalculateurPeriodeEReporting`, `PeriodeEReporting` ;
  - l'événement, l'écouteur et le job de transmission ;
  - la ressource et sa policy ;
  - la table `soumissions_ereporting` et la colonne `clotures_caisse.soumission_ereporting_id`.

  Le menu devient « E-reporting » : la liste des journées clôturées ayant des opérations, avec leur aperçu (C4) et le statut « non transmis : plateforme pas encore raccordée (étape 2) ». Les tests de ce mécanisme (`SoumettreEReportingPeriodiqueTest`, `GenererSoumissionEReportingActionTest`, `SoumissionEReportingResourceTest`) disparaissent avec lui, parce que la fonction disparaît, selon la règle « les fonctions, tests et commentaires devenus inutiles sont supprimés ». Ce n'est pas pour faire passer un test. Nouveau test : `EReportingResourceTest::test_la_liste_montre_chaque_journee_ayant_des_operations_avec_son_apercu`.
- **Option B.** Garder le mécanisme jusqu'à l'étape 2, en corrigeant :
  - le rattrapage : toutes les périodes finies qui ont des clôtures non reprises sont générées, de la plus ancienne à la plus récente, avec le test `SoumettreEReportingPeriodiqueTest::test_une_periode_manquee_est_rattrapee_au_passage_suivant`, qui échoue aujourd'hui (essai) ;
  - la date limite de la 2e décade (à vérifier) ;
  - un message sur la page vide, qui dit quand une soumission apparaît.

### C6. Texte de l'alerte d'abandon (facultatif)

`Caisse.php:361` : voir la proposition de la partie 3.1. Test : `CaisseTest::test_la_fenetre_d_abandon_dit_ce_qui_est_inscrit_au_journal`.

### Ordre et place dans la chaîne

- **C1, C2 et C3** touchent `CloturerCaisseAction` et `ClotureCaisseResource`, que le lot A6 modifie déjà (jeton de la clôture, règle R3). On peut les faire juste après A6, dans un lot « série 8, clôtures ».
- **C4 et C5** peuvent suivre aussitôt : ils ne dépendent pas du moteur de TVA, dont seule la correspondance des catégories changera aux lots M3 et M4.
- **Les plans à mettre à jour.** Le plan TVA dit `clotures_caisse` « Inchangée » (`tva/plan-execution.md:839`) : C2 l'élargit, il faut le noter. La conception de l'étape 3 prévoyait « pas de Z pour une journée sans enregistrement », ce que votre décision « un ticket Z chaque jour, même à zéro » a déjà remplacé. C1 garde cette décision.

---

## 5. Recoupements avec les plans existants

- **Étape 3, conception de la caisse sécurisée du 11/10/2026** (`caisse-securisee-conception.json`, partie `final`). Elle prévoit des clôtures calculées depuis le registre, couvrant « tickets ET factures aux professionnels, ventilés par circuit », avec :
  - « Encaissements par mode, depuis la chaîne encaissement » ;
  - compteurs ;
  - grand total réel et absolu ;
  - signature dans une chaîne de clôtures ;
  - « puis JET 50 » (60 pour l'exercice).

  C1 et C2 avancent une partie de ce travail, sous une forme que l'étape 3 reprendra : même forme de récapitulatif, encaissements déjà lus dans le registre.
- **Plan TVA** (`tva/plan-execution.md`) :
  - lot C-b : la facture demandée par un particulier entre dans la clôture du jour (`test_une_facture_demandee_entre_dans_la_cloture_du_jour`, ligne 2184), et la phrase du « ticket transformé en facture » est ajoutée à « Caisse et clôtures » (ligne 309) ;
  - section 3.21 : l'étape 2 lira « l'agrégat des ventes aux particuliers par jour, par circuit et non plus par type de client ».

  Le service de C4 doit donc avoir une seule fonction de choix, « type de client » aujourd'hui, « circuit figé » après M3.
- **Étape 2 dans CLAUDE.md et le plan TVA** (ligne 337) : SUPER PDP tient le calendrier, PILIQI envoie chaque clôture journalière une seule fois et corrige par une entrée négative. C5, option A, prépare exactement cela.
- **Point 1 de la série 8** (« Avoir » comme moyen de paiement) : s'il devient un mode, l'écran de clôture devra le montrer à part des encaissements réels. Un avoir n'est pas un encaissement, et la règle de CLAUDE.md dit que son imputation n'est « jamais déclarée comme un encaissement ». C'est à coordonner avec la mission du point 1.
- **Point 23** (paiement en plusieurs modes) : compatible avec C2 sans rien de plus (voir le test prévu).
- **Lots de la chaîne société** : A5 (fermeture et réouverture, Z pendant une fermeture), A6 (jeton de la clôture) et A7 (régularisation dont l'imputation n'est pas déclarée) touchent les mêmes fichiers. Aucun ne prévoit les moyens de paiement, l'événement 50 ou l'aperçu de l'e-reporting.

---

## 6. Questions pour l'éditeur

- **Q1.** Page « Soumissions e-reporting » : la remplacer dès maintenant par la liste « E-reporting » des journées avec leur aperçu, en supprimant le calendrier interne que l'étape 2 devait supprimer (option A, recommandée) ? Ou la garder et la corriger jusqu'à l'étape 2 (option B) ?
- **Q2.** La clôture montre-t-elle dès maintenant les factures aux professionnels et leurs règlements (votre règle « toutes les ventes et leurs règlements ») ? Le total déclaré à l'e-reporting resterait celui des seules ventes aux particuliers. Ma recommandation est oui, sinon les encaissements ne se rapprochent pas des ventes.
- **Q3.** Votre exigence 9 du point 15 parle de « remboursements ». Votre règle actuelle est « Rien n'est jamais remboursé : un retour donne un crédit ». Faut-il la garder ? Ma recommandation est oui. Dans ce cas, la clôture montre avoirs et crédit utilisé, sans ligne de remboursement.
- **Q4.** Faut-il inscrire au journal des événements la baisse du prix d'un service avant validation, que seules les personnes autorisées à fixer les prix peuvent faire ?
- **Q5.** Un vendeur autorisé à clôturer, mais pas à voir les ventes de toute l'équipe, doit-il voir dans la clôture le détail des tickets de ses collègues ? Ma recommandation : il voit les totaux, mais seulement ses propres tickets en détail.

---

## 7. Fichiers de cette mission

- Ce livrable : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/serie8/caisse-clotures-jet-ereporting.md`
- Essais : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/serie8/banc/tests/Feature/Scratch/Serie8ClotureJetTest.php` et `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/serie8/banc/tests/Feature/Scratch/Serie8ExempleJourneeTest.php`
