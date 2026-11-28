# Paiement saisi avec une date de réception passée : ce que disent les textes

Synthèse pour l'éditeur, datée du 23/11/2026. Elle réunit trois lectures indépendantes :

- `rapport-1-facture-electronique-e-reporting.md` (facture électronique, e-reporting, SUPER PDP) ;
- `rapport-recherche-2-tva-caisse.md` (TVA, caisse sécurisée, comptabilité, sanctions) ;
- `code.md` (le code de PILIQI au commit `b290c8d9`).

Les trois fichiers sont dans le même dossier que celui-ci. J'ai relu dans les fichiers téléchargés les citations qui portent la réponse (242 nonies P, PCG 1031-4, flux RE, AFNOR, SUPER PDP 1.37.0, BOFiP § 240 et 245, § 450) : elles y sont mot pour mot. J'ai aussi vérifié dans le dépôt les lignes de code principales. Tout a été fait en lecture seule.

Chaque point porte son niveau de preuve :

- **[Texte]** : loi, décret, arrêté, PCG, spécifications de la DGFiP ou norme AFNOR, lus dans le texte lui-même ;
- **[Doctrine]** : BOFiP ou fiche d'impots.gouv.fr ;
- **[Source secondaire]** : documentation de SUPER PDP ;
- **[Code]** : le code de PILIQI, lu sans lancer de test ;
- **[Déduction]** : ce que j'en tire. Ce n'est écrit dans aucun texte.

---

## En bref

1. **Oui, c'est permis.** Aucun texte n'interdit de saisir aujourd'hui un paiement reçu il y a des mois. Aucun texte ne le permet non plus en toutes lettres. Les textes demandent deux dates distinctes : le jour de l'enregistrement, qui ne bouge plus, et la date réelle du paiement, gardée à côté. C'est ce que PILIQI fait déjà.
2. **La facture électronique transmet la date de réception, jamais celle de la saisie.** Le texte demande la « date d'encaissement effectif ». Aucun flux n'a de champ pour la date de saisie. La position de Claude (ranger le paiement au jour de réception) suit donc ce que l'administration recevra.
3. **Une saisie tardive part en retard.** Les données de paiement d'un mois doivent partir dans un délai court : pour le réel normal, dix jours après la fin du mois. Une période déjà transmise se corrige par un flux rectificatif qui remplace toute la période. SUPER PDP, lui, glisse le paiement dans l'envoi suivant, avec sa vraie date.
4. **Le vrai risque est la fausse date, pas la date passée.** Les textes sanctionnent la réécriture d'un enregistrement, les écritures inexactes et la TVA déclarée hors de sa période. Comme la date vaut « aujourd'hui » par défaut, la faute la plus probable est de laisser ce jour pour un paiement reçu plus tôt : la TVA d'un service est alors déclarée en retard.
5. **Mon conseil :** garder le jour de réception, et ajouter quatre garde-fous :
   - un plancher au début de l'opération ou à l'émission de la facture, selon le plus ancien des deux ;
   - un motif obligatoire au-delà de 7 jours, et dès la veille pour les espèces ;
   - les deux dates affichées partout ;
   - la vraie date transmise à l'étape 2.

   Ne jamais refuser une date vraie parce que sa période est close ou transmise : le refus pousserait à taper une date fausse.

---

## 1. La réponse

### 1.1 Est-ce légal de saisir un paiement avec une date de réception passée ?

**Ce que disent les textes**

- **[Texte]** Le Code de commerce demande des enregistrements chronologiques (« Ces mouvements sont enregistrés chronologiquement », L123-12) et « datés dès leur établissement » (R123-173).
- **[Texte]** Le plan comptable général (PCG, art. 1031-4) répond au cas exact : « lorsque la date de l'opération correspond à une période déjà figée par la clôture, l'opération concernée est enregistrée à la date du premier jour de la période non encore clôturée, avec mention expresse de sa date de survenance ». La doctrine fiscale reprend la même phrase (BOI-BIC-DECLA-30-10-20-40 § 130).
- **[Texte]** Le fichier des écritures comptables (FEC, art. A47 A-1 du LPF) porte séparément la date de comptabilisation, la date de la pièce, la date de validation et, en comptabilité de trésorerie, la « date de règlement ».
- **[Doctrine]** Caisse sécurisée (BOI-TVA-DECLA-30-10-30) :
  - chaque transaction est datée « (année-mois-jour-heure-minute) » (§ 50) ;
  - les données liées à « la réception (immédiate ou attendue) du paiement » sont à sécuriser (§ 50) ;
  - une correction passe par « des opérations de « plus » et de « moins » et non par modification directe des données d'origine » (§ 90) ;
  - on doit pouvoir « démontrer que ces données de règlement n'ont pas été modifiées depuis leur enregistrement initial » (§ 120).
- **Non confirmé** : aucun texte ni aucune doctrine lus ne permet ni n'interdit en toutes lettres, dans un logiciel de caisse, une date de réception antérieure au jour de la saisie.

**Ce que j'en déduis**

- **[Déduction]** La saisie à date passée est permise, à une condition : l'enregistrement garde son propre jour, horodaté et inaltérable, et la date réelle s'y ajoute comme une « mention expresse de sa date de survenance ». C'est exactement le modèle de PILIQI :
  - l'horodatage d'enregistrement est signé dans l'en-tête ;
  - `recu_le` est signé dans les données ;
  - la clôture du jour compte le paiement au jour de sa saisie.

### 1.2 À quelles conditions ?

| Condition | Ce qu'en disent les textes | Ce que j'en déduis |
|---|---|---|
| **La date de réception est la vraie date d'encaissement** | **[Doctrine]** BOI-TVA-BASE-20-20 :<br>• espèces : le jour où elles sont reçues (§ 30) ;<br>• chèque : « le jour de cette remise », ou sa réception s'il arrive par la poste (§ 40), pas le jour du dépôt en banque ;<br>• virement : « l'inscription au compte du fournisseur » (§ 50) ;<br>• effet de commerce : son échéance (§ 60).<br>**Non confirmé** pour la carte bancaire et le prélèvement : aucune doctrine trouvée. | Le champ « Reçu le » doit dire ce que la personne doit y mettre. Aujourd'hui, rien ne le dit à l'écran. |
| **Le jour de l'enregistrement est gardé, fixe** | **[Texte]** R123-173, « datés dès leur établissement ».<br>**[Doctrine]** BOI-TVA-DECLA-30-10-30 § 50 et 100 : date à la minute, détail daté des corrections. | PILIQI le garde, signé, mais ne le montre ni sur la liste, ni sur la fiche, ni sur le reçu (voir § 3). |
| **Un motif** | **Aucun texte lu n'exige de motif** pour une saisie tardive.<br>**[Texte]** PCG 1032-2 : « Chaque écriture s'appuie sur une pièce justificative datée ».<br>**[Doctrine]** BOI-TVA-DECLA-20-20-20-10 § 240 : la régularisation simple d'une recette omise vaut pour l'entreprise qui l'a omise « de bonne foi ». | Le motif est un choix de produit, pas une obligation. Il aide à prouver la bonne foi et la pièce (n° du chèque, ligne du relevé). |
| **Ne jamais réécrire** | **[Doctrine]** § 90 et 120 ci-dessus.<br>**[Doctrine]** BOI-CF-INF-20-10-20 § 450 : est frauduleuse une écriture « modifiée a posteriori (réécriture, correction ou réindexation par exemple) ». | Une date fausse se corrige par une contre-passation, puis un nouveau paiement. C'est déjà la règle de PILIQI. |

**Où la date compte pour la TVA**

- **[Texte]** Pour un service, la TVA est exigible « lors de l'encaissement », sauf option pour les débits (CGI, art. 269, 2, c). Pour une livraison de biens, elle est exigible à la livraison, sauf un acompte versé avant (269, 2, a).
- **[Déduction]** La date de réception ne change donc la TVA que pour les **services sans option pour les débits**. Pour un article, elle ne change que l'affichage.

### 1.3 La facture électronique l'accepte-t-elle ?

**La date transmise**

- **[Texte]** Art. 242 nonies P, I, de l'annexe II au CGI : les données de paiement sont la période ou la date de la facture, « 3° La date d'encaissement effectif », le montant encaissé par taux et le numéro de facture.
- **[Doctrine]** BOI-TVA-DECLA-20-30-60 § 60 : cette date « est déterminée selon les règles d'exigibilité » de la TVA.
- **[Texte]** Art. 290 A du CGI : ces données ne concernent que les opérations dont la TVA est exigible à l'encaissement, hors autoliquidation.
- **[Texte]** Le statut « Encaissée » d'une facture électronique porte ces données (annexe IV, art. 41 septies G, 4°). Les paiements des particuliers sont « globalisés par jour » (41 septies O, II), dans le bloc 10.4, au champ « Date paiement ».
- **[Texte]** Spécifications externes DGFiP v3.2, note 125 : le fait générateur de la transmission des données de paiement « est la date d'encaissement du paiement ». Un paiement appartient donc à la période de sa date d'encaissement.
- **[Déduction]** Aucun flux n'a de champ pour la date de saisie. La facture électronique accepte donc un paiement saisi tard, et le déclare à sa date de réception.

**Le délai de transmission**

- **[Texte]** Une transmission par mois au réel, par bimestre civil en franchise en base (242 nonies P, III).
- **[Texte]** Délais (41 septies P) :
  - réel normal : « dans un délai de dix jours suivant la fin du mois » ;
  - réel simplifié : « entre le 25 et le 30 du mois suivant » ;
  - franchise en base : entre le 25 et le 30 du mois qui suit le bimestre.
- **[Doctrine]** Entrée en vigueur (BOI-TVA-DECLA-20-30-60 § 1) : 1er septembre 2026 pour les grandes entreprises et les ETI, **1er septembre 2027 pour les PME et les micro-entreprises**.
- **[Déduction]** Un paiement de service reçu en juillet et saisi en novembre part après le délai de juillet, dès que la société est soumise à l'obligation.

**Comment se corrige une période déjà transmise**

- **[Texte]** Spécifications v3.2, § 3.7.7 : la plateforme « peut transmettre un flux de transmission rectificatif (type RE) », qui « annule et remplace l'ensemble des données agrégées et précédemment transmises au titre de cette période ».
- **[Texte]** Aucune ancienneté maximale n'a été trouvée. Les contrôles demandent seulement une date passée (G1.07 : « La date de la facture, de transaction ou de paiement doit être antérieure ou égale à la date de contrôle dans le système. »).
- **[Texte]** Pour une facture électronique entre professionnels, la norme AFNOR XP Z12-014 dit que le statut « Encaissée » « DOIT être adressé dans la période de e-reporting des données de paiement ». L'arrêté prévoit un repli : « En cas d'impossibilité de mettre à jour le statut de traitement “ encaissée ” sur la période concernée, les données non rattachées à une facture sont transmises de manière agrégée » (41 septies O, III).
- **[Texte]** Un encaissement à annuler se corrige par un statut « Encaissée » de montant négatif, ou par un 10.4 négatif suivi de la bonne déclaration (AFNOR, cas n° 34 ; spécifications, règle P1.15).
- **[Source secondaire]** SUPER PDP 1.37.0.beta (13/10/2026) : « allow ereporting transactions and payments to be declared even if the period is finished. The transactions/payments declared this way will be included in the next e-reporting. » Sa documentation ne parle pas d'un rectificatif de l'ancienne période. Le statut `fr:212` accepte la date d'encaissement dans `details[].reported_data[].date`.
- **Non confirmé** : que le portail public accepte un paiement daté hors de la période du flux qui le porte. Le motif de rejet REJ_PER (« La date de la transmission de données n'est pas cohérente avec la période déclarée. ») est ambigu.

---

## 2. Ce que le droit interdit ou fait risquer

| Geste | Ce que disent les textes | Sanction | Niveau |
|---|---|---|---|
| **Réécrire la date d'un paiement déjà enregistré** | Correction par « plus » et « moins » seulement (BOI-TVA-DECLA-30-10-30 § 90). La « réindexation » et « la modification de la répartition entre les différents modes de paiement pour soustraire une partie des encaissements espèces » sont des fraudes (BOI-CF-INF-20-10-20 § 450). | **Pour l'éditeur** : 15 % du chiffre d'affaires du logiciel, et solidarité pour les droits rappelés à ses clients (art. 1770 undecies). | Texte et doctrine |
| **Antidater, ou postdater, sciemment** | Aucun texte ne nomme l'« antidatage » (**non confirmé**). Une date que l'on sait fausse est une écriture « inexacte » au sens de l'art. 1743, 1° (**[Déduction]**). | Délit pénal (peines de l'art. 1741). | Texte (1743) ; qualification déduite |
| **Enregistrer hors de l'ordre chronologique** | L'« enregistrement non chronologique des opérations » est une irrégularité « ayant un caractère de gravité indiscutable » (BOI-CF-IOR-10-20 § 90). | La comptabilité peut être rejetée. | Doctrine |
| **Rouvrir une période close** | La validation « interdit toute modification ou suppression de l'enregistrement » (PCG 1031-3). L'opération tardive va dans la période ouverte, avec sa date réelle (1031-4). L'archivage fige les données et leur donne date certaine (BOI-TVA-DECLA-30-10-30 § 220). | Voir les deux lignes précédentes. | Texte et doctrine |
| **Laisser de côté la TVA d'un service dont le mois est déjà déclaré** | De bonne foi, la recette omise s'ajoute au mois où l'omission est découverte, avec « la période de réalisation des opérations » (BOI-TVA-DECLA-20-20-20-10 § 240). Pour un exercice antérieur et au-delà de 4 000 € de droits, il faut une déclaration rectificative de la période (§ 245). | Intérêt de retard de 0,20 % par mois (art. 1727). Majoration de 40 % en cas de manquement délibéré, de 80 % en cas de manœuvres frauduleuses (art. 1729). | Texte et doctrine |
| **Transmettre en retard les données de paiement** | Obligation de l'art. 290 A. | 500 € par transmission, 15 000 € au plus par année civile (art. 1788 D, II). L'amende ne s'applique pas à une première infraction (année en cours et trois années précédentes) « réparée spontanément ou dans les trente jours suivant une première demande » (V). **Non confirmé** : qu'un paiement transmis en retard compte comme une transmission manquée. | Texte |
| **Saisir sans pièce** | « Chaque écriture s'appuie sur une pièce justificative datée » (PCG 1032-2). L'« absence de pièces justificatives de recettes » est une irrégularité grave (BOI-CF-IOR-10-20 § 90). Rien de propre à la caisse n'a été trouvé (**non confirmé**). | Rejet de la comptabilité. | Texte et doctrine |

**[Déduction] Le piège du défaut « aujourd'hui »**

- Une date passée **avance** la TVA d'un service vers son vrai mois : elle ne la réduit pas.
- La date qui la **retarde**, c'est le jour de la saisie laissé par défaut pour un paiement reçu plus tôt.
- La doctrine rappelle que même l'option pour les débits ne permet pas d'acquitter la TVA « postérieurement à l'encaissement » (BOI-TVA-DECLA-20-30-60 § 30).
- Donc refuser ou gêner une date passée pousserait vers la seule erreur qui coûte : une date trop récente.

**[Déduction] Les espèces sont le cas le plus sensible**

- Une date ancienne en espèces n'a aucune trace bancaire.
- La doctrine vise précisément les encaissements en espèces déplacés entre modes de paiement.
- Aucun texte ne fixe de limite (**non confirmé**).

---

## 3. Ce que fait PILIQI aujourd'hui (commit `b290c8d9`)

### Ce qui va dans le sens des textes **[Code]**

- **Le plafond.** « Reçu le » vaut aujourd'hui par défaut, au calendrier du magasin. Une date future est refusée à l'écran et dans le domaine (`PaiementResource.php:147`, `FactureResource.php:1136`, `EnregistrerPaiementAction.php:122-127`).
- **Les deux dates sont signées.** Le registre fiscal signe l'horodatage d'enregistrement dans l'en-tête (`RegistreFiscal.php:687`) et `recu_le` dans les données (`ContenuFiscal.php:129`). La chaîne et le refus d'une horloge qui recule suivent l'horodatage.
- **La clôture.** Le ticket Z compte le paiement le jour où il est saisi, et le Z du jour de réception ne change jamais. Le test `CloturerCaisseActionTest.php:663-677` le prouve. C'est la logique du PCG 1031-4.
- **L'ajout seul.** Rien ne modifie une date. La contre-passation est datée du jour où elle est faite, avec un motif obligatoire, et la bonne saisie suit.
- **Le jeton** de demande répétée contient la date.
- **L'aperçu de l'e-reporting** date chaque encaissement de service à `recu_le`, comme le demande le 242 nonies P.

### Les trous

| Cas | Aujourd'hui | Preuve |
|---|---|---|
| Date dans le futur | **Refusée** ✓ | `EnregistrerPaiementAction.php:125-127` |
| Date avant la facture, ou avant la livraison ou la prestation | **Acceptée** : rien ne compare `recu_le` à la facture | `verifierQuElleAttendCeReglement`, 258-273 |
| Date absurde (01/01/0001) | **Acceptée** | `Calendrier::jourValide`, 377-382 |
| Date dans un jour déjà clôturé | Acceptée, **sans un mot**. Le Z reste juste. | `CloturerCaisseActionTest.php:663-677` |
| Date dans une période déjà transmise à l'e-reporting | **Impossible aujourd'hui** : rien n'a jamais été transmis (« Non transmis : plateforme pas encore raccordée (étape 2). »). Rien n'est prévu pour l'étape 2 : aucune donnée ne dit qu'une période est partie. | `EReportingDesParticuliers.php:98` |
| Mois de TVA déjà déclaré | Aucune donnée ne le dit. Le lot TVA27, non fusionné, ne marque que les déclarations que la société indique. | `lot/tva27` |
| Motif d'une saisie tardive | **Aucun**. La fenêtre « Encaisser » n'a même pas de champ de texte. | `FactureResource.php:1115-1151` |
| Aide sur ce que veut dire « Reçu le » | **Aucune** à l'écran | `PaiementResource.php:143-148` |
| Droit | « Paiements : enregistrer », précoché à tout vendeur. Aucun droit propre à une date passée. | `PermissionRegistry.php:211`, `RoleMagasin.php:102` |
| Journal d'audit | **Rien** pour un paiement ni pour une contre-passation (prévu à l'étape 4) | grep `JournalAudit` sans résultat |
| Contrôle d'intégrité | Il ne vérifie pas que `recu_le` tombe au plus tard le jour de l'horodatage | `VerificateurIntegrite.php:816` |
| Relevé des encaissements (téléchargement par période) | Rangé par **saisie**, qui est la seule date imprimée. Le jour de réception n'y figure jamais. | `ReleveDesEncaissementsPdfGenerator.php:88` |
| Liste, fiche, reçu en PDF | Montrent le seul jour de réception. Le reçu porte « Encaissement du 12/07/2026 », numéroté à l'année de la saisie, sans le jour de la saisie. | `PaiementResource.php:331`, `FactureResource.php:415`, `RecusDePaiement.php:63-65` |
| Carte « Encaissements par mode » | Range au jour de réception, ce qui change le chiffre d'un mois passé. Sa ligne grise ne dit rien de la date. | `PaiementsDuTableauDeBord.php:51`, `Graphique.php:86` |
| Relevé de caisse personnel juste après minuit | Un paiement saisi à 00:20 avec « Reçu le » = hier entre dans le relevé d'hier et dans le Z d'aujourd'hui. Lu dans le code, **non confirmé par un test**. | `FermerSaCaisseAction.php:179` |
| Statut « Encaissée » (`fr:212`) prévu à l'étape 2 | Le plan l'envoie « sans `details` », donc **sans la date d'encaissement** que demande le 242 nonies P | `superpdp-plan.md:1007-1027` |
| TVA estimée | Elle ne lit aucun paiement : elle compte les services à la date de la facture. C'est une limite connue, renvoyée à l'étape 5, et à part de cette question. | `TvaDeLaPeriode.php` |
| Règle écrite | Seulement dans la matrice des décisions, pas dans `CLAUDE.md` | `matrice-des-decisions.php:2851-2861` |
| Semis | **Aucun** paiement saisi après son jour de réception | `GestesDuMagasin.php:231-248` |

---

## 4. La recommandation

### 4.1 Le choix

| | A. Position de Claude telle quelle | B. Jour de réception gardé, avec garde-fous (**mon conseil**) | C. Limiter fort |
|---|---|---|---|
| Règle | Jour de réception partout où il sert, et une ligne grise | Comme A, plus un plancher, un motif au-delà d'un seuil, les deux dates affichées et la vraie date transmise | Refus au-delà de N jours, ou dans une période close ou transmise, remplacé par une régularisation datée d'aujourd'hui |
| Conforme aux textes | Oui | Oui | **Non** : la « date d'encaissement effectif » transmise serait fausse. On passerait d'une date vraie et tardive à une date fausse. |
| Trous restants | Date avant la facture, aucune trace du retard, relevé incohérent, `fr:212` sans date | Seulement les points non confirmés (§ 6) | Pousse à une écriture inexacte (art. 1743) et retarde la TVA |
| Effort | Une ligne de texte | Moyen : domaine, deux écrans, contenu signé, semis, tests | Moyen, pour un mauvais résultat |

**[Déduction]** C est à écarter. PILIQI fait déjà la « régularisation » prévue par les textes : le paiement entre dans la clôture du jour de sa saisie, avec sa date réelle à côté. Pour l'e-reporting, la voie officielle est un rectificatif de l'ancienne période (RE), ou, chez SUPER PDP, l'envoi suivant avec la vraie date. Ni l'une ni l'autre ne demande de fausser la date.

### 4.2 Le détail de B, chiffré

Chaque nombre ci-dessous est un **choix de produit** : aucun texte ne donne de seuil. C'est l'éditeur qui tranche.

**1. Plafond : aujourd'hui.** Il existe déjà. J'ajouterais au contrôle d'intégrité la règle « `recu_le` ≤ jour de l'horodatage », au calendrier du magasin.

**2. Plancher.** Deux choix possibles.

- **B-1 : le jour d'émission de la facture.** C'est simple, et cohérent avec la phrase déjà affichée par PILIQI : « Un acompte reçu avant la facture ne s'enregistre pas encore dans PILIQI. » Mais cela force une date fausse dans un cas courant : l'artisan payé sur place le 10/07, qui émet sa facture le 15/07.
- **B-2 (conseil) : le plus ancien entre le jour d'émission et le premier jour de l'opération.** Le premier jour de l'opération est `date_operation_debut`, à défaut `date_operation_fin`. Ces colonnes existent déjà dans `factures`.
  - Cela laisse la vraie date d'un paiement reçu à la livraison ou à la prestation.
  - Cela refuse seulement un paiement antérieur à toute opération et à toute facture, c'est-à-dire un vrai acompte, que PILIQI ne tient pas encore.
  - La norme AFNOR prévoit d'ailleurs le cas de la « Facture déjà payée », dont le statut « Encaissée » « PEUT être adressé concomitamment à l'émission de la facture ».
  - Pour un paiement qui règle plusieurs factures, le plancher est le plus tardif de leurs planchers.
  - Le plancher se contrôle dans le domaine (`verifierQuElleAttendCeReglement`) et par un `minDate` dans les deux écrans.
  - Message proposé : « Ce paiement ne peut pas être reçu avant le JJ/MM/AAAA, jour de la facture N… ou de sa livraison ou prestation : un acompte reçu avant ne s'enregistre pas encore dans PILIQI. »

**3. Motif obligatoire, signé.** Le motif s'écrit dans `notes`, déjà signé, ou dans une colonne à part de `create_paiements_table`, ajoutée au contenu fiscal. Il faut aussi un champ dans la fenêtre « Encaisser ».

- **Chèque, virement, carte, prélèvement : au-delà de 7 jours** au calendrier du magasin (conseil). Une semaine couvre un chèque arrivé par la poste ou un virement vu sur le relevé suivant. Les autres choix possibles sont 3 jours ou 30 jours.
- **Espèces : dès la veille** (conseil). Un paiement en espèces est censé entrer dans la caisse le jour même, et c'est le mode que la doctrine surveille.
- Le texte d'aide demande la pièce : « n° du chèque, ligne du relevé bancaire… ».
- À l'étape 4, le journal d'audit inscrit les deux dates et le motif.

**4. Pas de refus pour une période close ou transmise. On informe à la place.**

- **Jour déjà clôturé** : une phrase à la saisie (§ 4.3). C'est possible tout de suite avec `derniereJourneeClotureeSansVerrou()`.
- **Délai de transmission dépassé**, seulement pour une facture qui porte un service, sans option pour les débits, une fois la plateforme raccordée (étape 2) :
  - une phrase à la saisie ;
  - une ligne dans l'« À faire » du propriétaire et de son comptable, « Paiement reçu en juillet 2026, saisi après le délai de ce mois ».
  - La période se calcule depuis le régime de TVA de la société et l'art. 41 septies P.
  - Il faudra une table des envois pour savoir ce qui est réellement parti.
- **À l'étape 2** : envoyer `fr:212` **avec** `reported_data.date` = `recu_le` et les montants par taux. Il faut corriger le plan, qui l'envoie aujourd'hui sans date. Avant de coder, poser trois questions à SUPER PDP (§ 6).

**5. Les deux dates affichées partout** :

- liste et fiche : « Reçu le » et « Saisi le », ce dernier quand il diffère ;
- reçu en PDF : « Encaissement du 12/07/2026, saisi le 23/11/2026 » ;
- relevé des encaissements : une colonne « Reçu le », en gardant le rangement par saisie, qui suit la logique du PCG.

**6. Le texte d'aide sous « Reçu le »**, à écrire tout de suite. Proposition fondée sur BOI-TVA-BASE-20-20 :

> « Le jour où vous avez reçu l'argent : remise des espèces ou du chèque (sa réception, s'il arrive par la poste), crédit du virement sur votre compte. Pas le jour où vous le saisissez. »

Pour la carte et le prélèvement, aucune doctrine n'a été trouvée. Selon la règle D76, Claude doit écrire une décision dans l'onglet « À faire vérifier par un professionnel ». Je propose : « carte bancaire : le jour du paiement sur le terminal ; prélèvement : le jour du crédit sur le compte, comme un virement ». C'est une **déduction** à valider.

**7. Contre-passation au tableau de bord.** Aujourd'hui, le paiement d'origine reste à son jour de réception et la contre-passation tombe au jour où elle est faite.

- Pour un **chèque impayé**, c'est ce que dit la doctrine : la taxe s'impute « sur sa plus prochaine déclaration » (BOI-TVA-BASE-20-20 § 40).
- Pour une **saisie erronée** (mauvaise date), le mois d'origine reste gonflé.
- **Conseil** : garder la règle actuelle, qui est aussi la date que portera le mouvement négatif de l'e-reporting, et la dire dans la ligne grise. L'autre choix est de ranger la contre-passation au jour de réception du paiement annulé. C'est à l'éditeur de trancher : cela change un chiffre d'argent affiché.

**8. Relevé personnel après minuit.** Conseil : l'aligner sur l'horodatage, comme le Z. C'est un document de caisse.

**9. Écrire la règle dans `CLAUDE.md`** (« Paiements et avoirs »), avec les tests et le semis :

- un paiement saisi après un jour clôturé ;
- un cas de part et d'autre du plancher ;
- un cas de part et d'autre du seuil de 7 jours, et un en espèces la veille ;
- un paiement avec motif ;
- une contre-passation d'un paiement ancien.

**Ce qui ne bouge pas** : la règle du Z, l'ajout seul, le contenu signé et l'ordre de la chaîne, le jeton, les dates de la caisse et de « Émettre et encaisser » (le jour même), et la date des mouvements de l'e-reporting au jour de réception.

### 4.3 Les phrases grises à écrire

**Carte « Encaissements par mode »** (remplace `Graphique.php:86`) :

> « Les encaissements TTC par mode de paiement, au jour où chacun a été reçu, même saisi plus tard ; une contre-passation compte au jour où elle est faite ; le crédit d'avoir utilisé à part. La clôture de caisse, elle, compte chaque paiement le jour où il est saisi. »

**À la saisie, quand « Reçu le » est avant aujourd'hui** (possible tout de suite) :

> « Reçu le 12/07/2026 et saisi aujourd'hui : il comptera dans la clôture de caisse d'aujourd'hui, sans changer celle du 12/07/2026, et il sera déclaré reçu le 12/07/2026. »

**En plus, pour une facture qui porte un service sans option pour les débits, une fois la plateforme raccordée** (étape 2) :

> « Le délai de transmission des paiements de juillet 2026 est passé : ce paiement partira en retard, avec sa vraie date. Si la TVA de juillet 2026 est déjà déclarée, elle est à régulariser : prévenez votre comptable. »

Avant l'étape 2, ou pour une PME avant le 01/09/2027, la première phrase de ce message serait fausse, puisque rien n'est transmis. Seule la seconde, sur la TVA, vaut dès aujourd'hui.

### 4.4 Ce que l'éditeur tranche

1. A, B ou C. Mon conseil est B.
2. Le plancher : B-1 (émission) ou B-2 (le plus ancien entre l'émission et le début de l'opération). Mon conseil est B-2.
3. Le seuil du motif : 7 jours, dès la veille pour les espèces. Ou un autre nombre.
4. La date de la contre-passation au tableau de bord.
5. La décision à écrire pour la carte et le prélèvement (règle D76).

---

## 5. Sources de chaque point de droit

| Point | Texte | URL | Extrait | Niveau |
|---|---|---|---|---|
| Date transmise | CGI, ann. II, art. 242 nonies P, I et III | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000054552572 | « 3° La date d'encaissement effectif ; 4° Le montant encaissé en euros, par taux d'imposition » ; « à raison d'une transmission par mois » | Texte |
| Champ des données de paiement | CGI, art. 290 A | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000053546674 | « pour lesquelles la taxe est exigible à l'encaissement […] à l'exception de celles pour lesquelles la taxe est due par le preneur » | Texte |
| Date = règles de la TVA | BOI-TVA-DECLA-20-30-60 § 60 | https://bofip.impots.gouv.fr/bofip/13901-PGP.html | « La date d'encaissement effectif est déterminée selon les règles d'exigibilité exposées au BOI-TVA-BASE-20-20 » | Doctrine |
| Entrée en vigueur | BOI-TVA-DECLA-20-30-60 § 1 | idem | « 1er septembre 2027 pour les petites et moyennes entreprises et les micro-entreprises » | Doctrine |
| Délais | CGI, ann. IV, art. 41 septies P | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000054606241 | « dans un délai de dix jours suivant la fin du mois faisant l'objet de la transmission » | Texte |
| Statut « Encaissée » | CGI, ann. IV, art. 41 septies G et O | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000054606169 ; https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000046386267 | « 4° “ Encaissée ” de la facture » ; « En cas d'impossibilité de mettre à jour le statut […] sur la période concernée, les données non rattachées à une facture sont transmises de manière agrégée » | Texte |
| Période du paiement, rectificatif | Spécifications externes DGFiP v3.2, note 125 et § 3.7.7 | https://www.impots.gouv.fr/sites/default/files/media/1_metier/2_professionnel/EV/2_gestion/290_facturation_electronique/specification_externes_b2b/specifications-externes-v3.2.zip | « celui de la transmission des données de paiement est la date d'encaissement du paiement » ; « annule et remplace l'ensemble des données agrégées et précédemment transmises au titre de cette période » | Texte |
| Aucune ancienneté maximale | Spécifications v3.2, annexe 7, G1.07 | idem | « doit être antérieure ou égale à la date de contrôle dans le système » | Texte |
| Statut dans la période, annulation | AFNOR XP Z12-014, annexe A v1.2, p. 34 et cas n° 34 | https://fnfe-mpe.org/wp-content/uploads/2025/11/XP_Z12-014_CAS_USAGE_Annexe_A_V1.2.pdf | « DOIT être adressé dans la période de e-reporting des données de paiement » ; « émettre un cycle de vie du statut « Encaissée » avec un montant négatif » | Texte (norme expérimentale) |
| SUPER PDP | API 1.42.0.beta, notes 1.37.0.beta | https://api.superpdp.tech/openapi/superpdp.json | « allow ereporting transactions and payments to be declared even if the period is finished » | Source secondaire |
| Exigibilité | CGI, art. 269, 2, a et c | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000044983827 | « lors de l'encaissement des acomptes, du prix, de la rémunération ou, sur option du redevable, d'après les débits » | Texte |
| Date selon le mode | BOI-TVA-BASE-20-20 § 30 à 60 | https://bofip.impots.gouv.fr/bofip/283-PGP.html | « le jour de cette remise constitue la date d'exigibilité de la taxe » ; « l'inscription au compte du fournisseur » | Doctrine |
| Débits | BOI-TVA-DECLA-20-30-60 § 30 | https://bofip.impots.gouv.fr/bofip/13901-PGP.html | « ne peut pas avoir pour effet de retarder l'exigibilité de la taxe » | Doctrine |
| Caisse sécurisée | CGI, art. 286, I, 3° bis ; BOI-TVA-DECLA-30-10-30 § 50, 90, 120, 220 | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000054337091 ; https://bofip.impots.gouv.fr/bofip/10691-PGP.html | « réception (immédiate ou attendue) du paiement » ; « par des opérations de « plus » et de « moins » et non par modification directe » | Texte et doctrine |
| Chronologie | C. com. L123-12, R123-173 | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000006219304 ; https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000030927447 | « enregistrés chronologiquement » ; « datés dès leur établissement » | Texte |
| Période close | PCG (règlement ANC 2014-03), art. 1031-3, 1031-4, 1032-2 | https://www.anc.gouv.fr/files/anc/files/1_Normes_fran%C3%A7aises/Plans%20comptables/2026/PCG--1er-janvier-2026.pdf | « enregistrée à la date du premier jour de la période non encore clôturée, avec mention expresse de sa date de survenance » | Texte |
| Plusieurs dates | LPF, art. A47 A-1 | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000027804775 | « La date de comptabilisation » ; « La date de la pièce justificative » ; « La date de règlement » | Texte |
| TVA omise | BOI-TVA-DECLA-20-20-20-10 § 240 et 245 | https://bofip.impots.gouv.fr/bofip/2883-PGP.html | « ajoutant les recettes non déclarées à celles du mois de la découverte de l'omission » ; au-delà de « 4 000 € en droits », « déclaration rectificative » | Doctrine |
| Intérêt, majorations | CGI, art. 1727 et 1729 | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000051213330 ; https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000020051904 | « 0,20 % par mois » ; « 40 % en cas de manquement délibéré » | Texte |
| Transmission manquée | CGI, art. 1788 D, II et V | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000053546693 | « 500 € par transmission » ; « réparée spontanément ou dans les trente jours » | Texte |
| Non-chronologie | BOI-CF-IOR-10-20 § 90 | https://bofip.impots.gouv.fr/bofip/4298-PGP.html | « enregistrement non chronologique des opérations, absence de pièces justificatives de recettes » | Doctrine |
| Écritures inexactes | CGI, art. 1743, 1° | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000046859818 | « a passé ou fait passer des écritures inexactes ou fictives » | Texte |
| Éditeur | CGI, art. 1770 undecies ; BOI-CF-INF-20-10-20 § 450 | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000029041640 ; https://bofip.impots.gouv.fr/bofip/7234-PGP.html | « 15 % du chiffre d'affaires » ; « réécriture, correction ou réindexation par exemple » | Texte et doctrine |
| Passage au CIBS | BOI-RES-TVA-000253-20261007 | https://bofip.impots.gouv.fr/bofip/14901-PGP.html | « À compter du 1er janvier 2027, ces dispositions sont codifiées au livre II […] du code des impositions sur les biens et services » | Doctrine |

Les copies téléchargées et leurs empreintes SHA-256 sont dans `telechargements/` et `r2b-telecharge/`, à côté de ce fichier.

---

## 6. Ce qui reste non confirmé

**Les textes**

- La date d'encaissement d'un paiement par **carte bancaire** et par **prélèvement** : aucune doctrine trouvée.
- Un texte qui permette ou interdise **en toutes lettres** une date de réception antérieure à la saisie dans un logiciel de caisse. Le référentiel privé NF525 n'a pas été lu.
- L'« **antidatage** », traité nulle part en ces termes.
- Qu'un paiement transmis en retard compte comme une « **transmission** » sanctionnée par l'art. 1788 D, II.

**Le portail public (PPF)**

- Qu'il accepte un paiement daté hors de la période du flux : REJ_PER est ambigu.
- Le champ exact de la date du statut « Encaissée » chez la DGFiP et l'AFNOR. SUPER PDP la met en MDT-219.
- La règle G8.02, citée mais absente de l'annexe 7.

**SUPER PDP, trois questions à poser avant l'étape 2**

- (a) L'« e-reporting suivant » est-il un envoi initial (IN) de la période courante, ou un rectificatif (RE) de l'ancienne ?
- (b) Le PPF l'accepte-t-il sans rejet REJ_PER ?
- (c) Un `fr:212` daté d'une période révolue est-il accepté ?

**Le passage au CIBS au 01/01/2027**

- Le numéro de l'article qui reprend l'art. 290 A.
- Le texte de l'art. L. 216-48, qui reprend la caisse sécurisée de l'art. 286.

**Le code de PILIQI**

- L'écart du relevé personnel après minuit : lu dans le code, aucun test ne le couvre.
- L'aperçu de l'e-reporting d'un paiement saisi après coup : lu dans le code, aucun test ne le couvre.

**La taille de la société**

- Aucune colonne ni aucun code de PILIQI ne porte la catégorie d'entreprise (PME, ETI, grande entreprise) : une recherche dans `app/` et les migrations ne trouve rien. PILIQI ne peut donc pas dire si une société est déjà tenue à l'e-reporting depuis le 01/09/2026, ou seulement à partir du 01/09/2027. Il faudra un réglage, ou la date la plus prudente.
