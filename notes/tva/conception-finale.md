# Moteur de TVA centralisé de PILIQI : conception finale

Rédigé le 11/10/2026, en réponse à la demande de l'éditeur du même jour. C'est une **proposition à valider avant tout développement** : rien n'a été modifié ni exécuté dans `/home/user/piliqiediteur` (aucune commande artisan, aucun test, aucune commande git).

Cette version reprend la première conception du même jour et la corrige après trois relectures adversariales, qui ont relevé 54 défauts. Chaque défaut a été vérifié dans le code, dans les règles du projet et dans les sources fiscales. Il a été retenu, retenu avec une correction adaptée, ou écarté en partie : la table de traitement est en annexe C. Le document se lit seul.

Le code est cité par chemin relatif à l'instantané en lecture seule `scratchpad/tva/instantane` (commit 9c9ee25), suivi du numéro de ligne. Les citations ont été relues dans l'instantané.

---

## 0. Cadre

### 0.1 Sources

- **Demande de l'éditeur** du 11/10/2026, verbatim, et le contexte déjà décidé le même jour (`scratchpad/tva/demande-editeur.md`).
- **Analyses préparatoires**, lues en entier : `inventaire-code.md` (doublons D1 à D12, contradictions C1 à C12, questions Q1 à Q11), `reglementation.md` (cas d'achat A1 à A18, cas de vente V1 à V15, 23 points à vérifier), `ergonomie-donnees.md` (parcours, faits, écrans, questions QE1 à QE16) et la première conception (`conception.md`, questions QT1 à QT22), dont ce document reprend et remplace le contenu.
- **Recherche fiscale** `regles-tva-international.md` (713 lignes) : vérifiée et complétée en 1.4, sans être refaite.
- **Vérifications en ligne du 11/10/2026**, en extraits de moteur de recherche (les sites officiels restent injoignables en page complète depuis la machine) :
  - art. 258 D du CGI : simplification des opérations triangulaires, transposition de l'art. 141 de la directive (BOI-TVA-CHAMP-20-40 et texte commenté) ;
  - art. 292 du CGI : la base de la TVA d'importation comprend les frais accessoires (commission, emballage, transport, assurance) jusqu'au premier lieu de destination indiqué sur le document de transport (BOI-TVA-BASE-10-20-60-10) ;
  - art. 262 II 14° du CGI : exonération des prestations liées à une importation dont la valeur entre dans la base de la TVA d'importation (BOI-TVA-BASE-10-20-60-20) ;
  - art. 256 ter du CGI : définition du bon à usage unique (lieu et TVA connus à l'émission) et du bon à usages multiples (BOI-TVA-CHAMP-10-10-40-50) ;
  - BOI-TVA-DED-60-30 (26/08/2020) : reversement de la TVA déduite sur une marchandise disparue (art. 207 VI de l'annexe II), avec des dispenses pour les marchandises détruites, les vols et la démarque inconnue (plan lu, conditions non lues) ;
  - définition de la vente à distance (art. 14 § 4 de la directive) : acquéreur non assujetti, ou assujetti et personne morale non assujettie dont les acquisitions ne sont pas soumises à la TVA (transposition luxembourgeoise et note de la douane française lues en extrait).
  - **Non retrouvé en ligne** : le second alinéa de l'art. 66 de la directive (exigibilité des services autoliquidés). Il reste « à confirmer » (annexe B).
- **Décisions de l'éditeur** :
  - séries 1 à 3 : B1, B2 (saisie libre, 20 % par défaut), B10, B12, C1, C2, E1, E5 à E7, E13, E14, I1 à I4, K2, K3, K5 ;
  - série 4 : R1 à R5, et T1 à T6 (campagne de tests). **Les questions de ce document sont numérotées T1 à T16 à la demande de la mission : elles ne sont pas celles de la série 4**, déjà répondues ;
  - compléments 1 à 16 du 11/10 (`decisions/reponses-complements-14-20.md`).
- **Conception de l'architecture société** (`societe/conception-v2-finale.md`) : 2.3 (lectures hors du magasin), 4.3 et 4.6 (autorisations et modèles de rôles), 5 (fiscalité), 7.8 (démonstration), 8 (R1 à R5 et ordre des contrôles de l'émission, 8.6), 14 (lots A0 à A7 et lot 21).
- **PDF de l'éditeur**, lus en entier : art. 290 du CGI, version en vigueur depuis le 21/02/2026 (4 pages) ; FAQ impots.gouv.fr « Mes clients sont à la fois des entreprises et des particuliers », modifiée le 16/01/2026 (3 pages).
- **Règles du projet** : `CLAUDE.md` de l'instantané. Puces citées : 146 à 149 (développement), 155 (isolation), 164 (vérification et démonstration), 167 (prix et TVA des ventes), 168 (ventes hors de France), 172 (achats et stock), 173 (caisse et clôtures), 174 (facture électronique), 177 (outillage), 186 (avoirs multi-factures).
- **`.ai/rules`** : le dossier n'existe pas dans l'instantané (vérifié). Aucune règle de chemin ne s'applique ; chaque lot relira `.ai/rules/index.md` s'il existe à ce moment-là.

### 0.2 Résumé pour l'éditeur

1. **Un seul moteur décide de la TVA et calcule tous les montants**, pour les ventes comme pour les achats. Les écrans, la caisse, l'API, le stock et, plus tard, les déclarations l'appellent tous ; aucun ne refait un calcul à sa façon. Il ne lit ni la base ni l'horloge : on lui donne tous les faits, date comprise, et il rend une décision chiffrée.
2. **Ce qui a été validé ne change pas** : prix TTC arrondi une seule fois, TVA répartie par cumul (R4), retours cumulés (K2), vente exonérée payée au prix hors taxe (K3), franchise à 0 % au même prix TTC (B10), achat calculé depuis le prix HT. La calculatrice actuelle passe dans le domaine de la TVA sans changer ; une comparaison faite une fois, avant et après, vérifie qu'aucun centime ne bouge.
3. **Pour une vente en France, rien ne change**, ni à l'écran ni en caisse. Un lien discret « Expédier hors de France ou livrer à une autre adresse ? » reste disponible pour tout client.
4. **Le pays ne décide jamais seul : il déclenche une question.** Une exonération ou une autoliquidation n'est retenue que si toutes ses conditions sont réunies ; sinon la vente reste à la TVA française (votre réponse I1).
5. **Le régime s'applique de lui-même quand il découle de vos réponses** dans le document (« oui, nous expédions les marchandises en Allemagne », adresse, date) ; il est **proposé avec un bouton** pour une prestation à une entreprise étrangère ; s'il est encore proposé au moment d'émettre, **la fenêtre d'émission impose le choix** et l'enregistre. Rester à la TVA française est toujours possible et noté ; personne ne peut forcer une exonération (question T2).
6. **Chaque document affiche un panneau « Régime de TVA »** en mots simples, sans aucun code technique : le régime, qui paie la TVA, la mention imprimée, chaque condition « oui » ou « non », ce qui manque, la transmission prévue.
7. **Achats.** L'origine réelle des marchandises (France, autre pays de l'UE, hors UE) est toujours affichée dès qu'il y a un produit, préremplie « France » pour un fournisseur établi en France. La facture d'achat distingue marchandises, frais d'acheminement et services. Elle donne la TVA facturée, la TVA autoliquidée, la TVA déductible, le montant à payer au fournisseur, la TVA à payer à l'État et le coût d'entrée en stock. **Le taux d'une ligne d'achat ne modifie plus jamais le taux de vente du produit.**
8. **Traçabilité.** La décision d'une vente est signée avec le document au registre fiscal. Celle d'un achat est figée à sa validation, et chaque modification s'inscrit au journal d'audit de la société, avec l'avant et l'après. Chacune garde les faits utilisés, leur origine, la version des règles et l'auteur du choix du régime, pendant 10 ans.
9. **Les règles qui changent sont des données datées** : taux, mentions (passage du CGI au CIBS au 1/1/2027), seuils, preuves attendues. Un document émis garde pour toujours le texte de son jour.
10. **Les déclarations futures lisent les décisions enregistrées sans rien recalculer** : facture électronique et e-reporting à l'étape 2, écritures et état de TVA à l'étape 5, sorties de stock à l'étape 7.
11. **Plan** : lots M0 à M8 (et M9 après l'étape 2), petits, chacun testé sur SQLite et PostgreSQL, relu et poussé, après la chaîne société en cours et le lot 21, avant la campagne de tests initiale (question T1).
12. **16 questions** (T1 à T16, section 12), classées par lot, et **8 points appliqués sauf objection**.

### 0.3 Vocabulaire

| Terme | Sens dans ce document |
|---|---|
| Fait | Une information dont le moteur a besoin, avec sa source : statut du client, pays d'expédition, n° de TVA vérifié, date de l'opération… |
| Nature d'opération | Le régime d'une vente : vente en France, export, livraison vers un DOM, livraison intracommunautaire, prestation autoliquidée, prestation hors UE. C'est l'énumération actuelle `NatureOperation` (`app/Domain/Facturation/Enums/NatureOperation.php:24-31`). Le mot « régime » reste réservé, dans le code, au régime de TVA de la société (`app/Domain/Tenancy/Enums/RegimeTva.php`). À l'écran : « Régime de TVA de cette vente ». |
| Nature d'achat | Le régime d'une ligne d'achat : achat en France, acquisition intracommunautaire, livraison en France par un fournisseur non établi, services d'un prestataire non établi, importation… |
| Décision | Ce que rend le moteur : nature, lignes chiffrées, mentions, circuit, conditions, manquements, justification, versions. |
| Geste | Un choix de l'utilisateur sur le régime : appliquer une nature proposée, ou garder la TVA française. Il est enregistré avec son auteur et l'instant. |
| Appliqué d'office | Une nature dont toutes les conditions sont réunies grâce à des réponses données dans ce document : elle s'applique sans clic, et l'émission la confirme. |
| Proposé | Une nature dont toutes les conditions sont réunies sans qu'aucune question n'ait été posée (prestation à une entreprise étrangère) : elle attend un geste. |
| Redevable | Qui paie la TVA au Trésor : le vendeur, le client (autoliquidation à l'étranger), la société acheteuse (autoliquidation en France), le fournisseur, ou personne. |
| Catégorie | Le code de TVA de la norme EN 16931 : S (taux normal ou réduit), E (exonéré, dont la franchise), AE (autoliquidation), K (livraison intracommunautaire), G (export), O (hors champ). Il ne s'affiche jamais à l'écran. |
| Circuit | Où partent les données à l'étape 2 : facture électronique, e-reporting international (sortant ou entrant), e-reporting des ventes aux particuliers, Chorus Pro hors de PILIQI, ou aucun. |
| Bloquant | L'émission (vente) ou la validation (achat) est refusée, rien n'est numéroté ni signé, et tous les manquements sont dits d'un coup. |
| Avertissement | Rien n'est refusé ; le message est affiché, repris dans la fenêtre de confirmation et gardé dans la décision. |
| Lot M0 à M9 | Les lots de développement du moteur (« M » pour moteur), pour ne pas les confondre avec les questions T1 à T16. |

---

## 1. Principes et périmètre

### 1.1 Principes

| # | Principe | Conséquence |
|---|---|---|
| P1 | **Un seul moteur.** | L'aperçu à l'écran, le contrôle à l'émission ou à la validation, le figement, le coût du stock et les déclarations lisent la même décision. Les écrans ne gardent aucune règle fiscale ni aucun calcul (corrige D1, D2, D10 de l'inventaire). |
| P2 | **Un moteur pur et déterministe.** | Aucun accès à la base, aucune horloge implicite, aucune lecture de configuration. La date de l'opération est un fait. Les mêmes faits donnent toujours la même décision, ce qu'un test vérifie. |
| P3 | **On corrige les faits, jamais le résultat.** | L'utilisateur ne choisit pas une exonération : il complète un fait (n° de TVA, sortie des biens, date de livraison) ou fait un geste sur une nature dont toutes les conditions sont réunies. |
| P4 | **Le pays déclenche une question, jamais une décision.** | Exigence de l'éditeur. Toute nature autre que la vente en France ou l'achat en France exige des conditions cumulatives. |
| P5 | **Valeur sûre par défaut.** | Vente : TVA française tant que les conditions d'une autre nature ne sont pas toutes réunies (I1). Achat : aucune origine supposée pour un fournisseur non établi en France. Lieu d'un service fixé par la loi hors de France : refus avec un message, jamais une TVA française facturée à tort. |
| P6 | **Jamais de bascule silencieuse.** | Une nature appliquée d'office affiche le nouveau total et le lien « Rester à la TVA française » ; une nature encore proposée à l'émission impose un choix, qui est enregistré. |
| P7 | **Bloquant ou avertissement.** | Bloquant si, sans ce fait, la nature retenue est inapplicable ou si un montant dû, payé ou déclaré serait faux au moment de l'acte. Avertissement si le fait se complète après sans changer le document. |
| P8 | **Tous les manquements d'un coup.** | Le refus liste tout, identité légale, SIREN et adresse du client compris, et il arrive avant le contrôle du stock (R1), pas après. Aujourd'hui les refus se succèdent (`app/Domain/Facturation/Actions/EmettreFactureAction.php:134-136`, puis `:144`). |
| P9 | **Le moteur produit tous les montants, avec la calculatrice validée.** | Ventes et achats. La calculatrice (`CalculateurLigne`) passe dans le domaine de la TVA sans changer ses règles. Aucune règle d'arrondi nouvelle, sauf celles soumises à l'éditeur : TVA autoliquidée et retours d'achat (T13), répartition des frais (T14), conversion des devises (T16). |
| P10 | **Un document émis ne relit plus jamais le moteur.** | Il relit sa décision figée, comme il relit déjà son émetteur signé. |
| P11 | **Des règles en données datées et versionnées.** | La version du moteur et celle des règles sont figées avec chaque décision. |
| P12 | **Isolation des magasins et des sociétés.** | Les faits viennent du magasin courant et de sa société. Les seules lectures nouvelles hors du magasin sont des sommes fiscales de la société (question T7). |
| P13 | **Aucun code à l'écran.** | Ni K, ni VATEX, ni 0223, ni nom d'énumération, ni identifiant interne : des mots simples. Pour VIES, le n° de preuve donné par VIES et l'heure. Un test cherche ces codes dans les pages affichées. |
| P14 | **Une seule source par donnée.** | Une donnée enregistrée l'est une fois : soit une colonne (si elle est interrogée ou déjà existante), soit la décision figée en JSON (le reste). Ce qui se déduit des faits figés et de la version des règles ne s'enregistre pas (CLAUDE.md:149). |

### 1.2 Ce que le moteur décide

| Décision | Ventes | Achats | Stock et déclarations |
|---|---|---|---|
| Nature | Une nature par document (T6), parmi celles que I1 ouvre ; « vente en France » par défaut. | Une nature par ligne (marchandises et services d'un même fournisseur peuvent différer). | Lue par l'étape 2 (circuit) et l'étape 5 (rubrique). |
| Montants | Lignes chiffrées : PU HT, PU TTC, PU payé, HT, TVA, TTC, en centimes ; méthode article par article ou depuis le HT de ligne (E1) ; répartition R4 ; prix hors taxe hors TVA française (K3). | TVA facturée, TVA autoliquidée, TVA déductible, TVA non déductible, droits, montant dû au fournisseur, TVA à payer à l'État, montant à payer à la douane. | Étape 5 : état de TVA. |
| Taux | Taux de la fiche du produit (B1) ou taux de référence du service (R2) ; 0 si la nature exonère ou en franchise ; « taux du prix » gardé pour le prix hors taxe (K3) ; taux contrôlé (avertissement X8). | Taux facturé, lié au taux de vente tant qu'on ne le change pas (achat en France) ; taux de la TVA due en France (autoliquidation, proposé depuis le taux de vente). | — |
| Catégorie EN 16931 et code VATEX | Déduits de la nature, de la franchise et du type de ligne (bien ou service), avec la version des règles : une nature par document, donc une catégorie par taux. Ils ne s'enregistrent pas (P14). | Catégorie attendue sur la facture reçue, pour le contrôle de l'étape 2. | Étape 2 : en_invoice, B2BInt. |
| Coût du stock | — | Coût unitaire d'entrée de chaque ligne de produit, au dix-millième : prix net, frais d'acheminement, droits, TVA non déductible. | Reçu tel quel par le grand livre du stock. |
| Mentions | Liste ordonnée (code et texte du jour) et note de calcul, tirées de la table datée. | Mentions attendues sur la facture reçue (contrôlées à l'étape 2 seulement). | Le PDF relit le texte figé. |
| Circuit | Trois conditions de la FAQ, entité publique (E7), non-assujetti, destination Monaco, démonstration. | Facture reçue, e-reporting international entrant (art. 290 I 3°), Monaco (4° a), aucun (importation). Déduit, non enregistré avant l'étape 2. | Étape 2. |
| Preuves attendues | Table datée par nature et sortie des biens : MRN ou CN23 ; deux documents de transport de parties indépendantes ; attestation de l'acquéreur. | — | Rappels (I4). |
| Obligations annexes | Facture au plus tard le 15 du mois suivant (K, AE) ; relevé du mois pour l'état récapitulatif (LIC) et la déclaration européenne de services (AE), à déposer par la société elle-même. | — | Relevé exportable (7.10). |
| Exigibilité | Étape 2 (statut « encaissée », paiements B2C). | Étape 5 (date de déduction), avec les règles de 8.2. | — |
| Manquements | Codes stables, champ, message, gravité. | Idem. | API : réponse 422 avec tous les codes. |
| Justification | Conditions évaluées (« oui » ou « non », valeur constatée), faits et leur source. | Idem. | Affichée sur la fiche du document. |

### 1.3 Ce que le moteur ne fait pas

| Hors périmètre | Ce qui se passe à la place |
|---|---|
| **Arrondis nouveaux** | Il réutilise les règles validées de CLAUDE.md : prix de vente TTC arrondi une seule fois (`app/Domain/Facturation/Services/CalculateurLigne.php:247-256`), calcul article par article ou depuis le HT de ligne, R4 (`:148-165`), K2 (`:191-217`), achat (`:93-103`). Les seules règles nouvelles sont soumises à l'éditeur (T13, T14, T16). |
| **Prorata, coefficient de déduction, secteurs distincts, exclusions du droit à déduction** | Droit à déduction total supposé pour des marchandises achetées pour la revente ; écrit dans la notice. |
| **Opérations exonérées des art. 261 à 261 E** | Aucune catégorie pour elles : une vente en France à 0 % est refusée (T5, recommandé), conformément au complément 10. |
| **Une seule nature par document** | Un document ne mélange pas deux natures (T6) : le panneau propose « Déplacer ces lignes vers une nouvelle facture ». |
| **Cartes cadeaux et bons d'achat** | Un produit marqué « carte cadeau ou bon d'achat » ne se vend pas tant que le traitement n'est pas construit (T9) : la TVA d'un bon à usages multiples n'est due qu'à son utilisation (art. 256 ter). |
| **Service au lieu fixé par la loi exécuté hors de France** (art. 259 A) | Refus avec un message (cas non géré), jamais une TVA française. Un tel service est réputé exécuté en France tant que rien n'indique le contraire ; la question est posée dès qu'un fait international existe (7.3). |
| **Services immatériels de l'art. 259 B à un particulier domicilié hors UE** | Non distingués : TVA française, avec un avertissement quand un particulier dont l'adresse est hors UE achète un service. Le marqueur « service immatériel » viendra avec le lot M9 si l'éditeur le demande. |
| Régime de la marge (étape 14), accises, moyens de transport neufs, livraisons en chaîne et opérations triangulaires, montage hors de France, transferts de biens propres, ventes aux diplomates, achats en franchise des exportateurs (art. 275) | Vente : la nature d'exonération est refusée, la vente en France reste possible. Achat : saisie manuelle encadrée (T11). Achat triangulaire : repéré par l'origine différente du pays du n° de TVA du fournisseur (6.2). |
| Autoliquidations internes (sous-traitance du BTP, déchets…), services au lieu fixé par la loi achetés à l'étranger, importations d'un magasin des DOM (octroi de mer) | Saisie manuelle encadrée (T11). |
| Taux par catégorie fiscale de produit, taux corses à l'expédition depuis le continent | Le taux reste libre sur la fiche (B2) ; le moteur le contrôle. Une table par catégorie viendra avec l'OSS (lot M9). |
| **Suivi des seuils de la franchise en base** | Non calculé. Le régime de la société devient un historique daté (O7) : le moteur lit le régime en vigueur à la date de l'opération. |
| **Sorties de stock hors vente** (démarque, vol, casse, prélèvement, don) | Étape 7 (inventaire et ajustements) : une décision « sortie hors vente » y est prévue (8.3). |
| Dépôt des déclarations : CA3, état récapitulatif, déclaration européenne de services, OSS | Hors logiciel. Le moteur prépare les données (relevé du mois, 7.10) et les rappels. |
| Détaxe | Ce n'est pas une nature : le ticket reste à la TVA française et le bordereau se note après (I3, inchangé). |
| Taux de change | Un fait saisi (T16) ; le moteur ne va jamais le chercher. |
| Calendrier de l'e-reporting | Tenu par SUPER PDP (complément 11). |

### 1.4 Vérification et compléments de la recherche fiscale

| # | Point de la recherche | Constat de ce jour | Effet sur le moteur |
|---|---|---|---|
| 1 | §1.2 : export et LIC « dans le champ de l'e-reporting, à confirmer » | L'art. 290 I 1° a (PDF) vise « les livraisons exonérées en application du I des articles 262 et 262 ter » au profit d'un assujetti ; le I 2° b vise les livraisons situées en France à un non-assujetti, ce qui couvre l'export à un particulier. | Confirmé par le texte : un export ou une LIC a toujours un circuit. |
| 2 | §1.2, ligne « opération située hors de France entre deux assujettis établis en France → aucun circuit » | **Faux.** L'art. 290 I 1° c vise « les prestations de services qui ne sont pas situées en France en application des articles 259 et 259 A » réalisées au profit d'un assujetti, **sans condition sur l'établissement du preneur**. La FAQ (page 2) rattache l'e-reporting aux opérations de l'art. 290. | Le circuit d'une telle prestation est l'e-reporting international sortant. PILIQI la refuse pour l'instant (lieu fixé hors de France, 7.3) ; la règle de circuit s'écrira avec la nature qui l'ouvrira. La recherche est corrigée ici. |
| 3 | §1.2 : exclusion si exonération des art. 261 à 261 E **et** dispense de facture | Le texte ne pose qu'une condition : l'exonération des art. 261 à 261 E. | Sans effet : PILIQI ne gère aucune de ces opérations (T5). |
| 4 | N1, Monaco | L'art. 290 I 4° a vise, **pour les biens, le mouvement** (« livraisons de biens expédiés ou transportés à partir de la France à destination de la Principauté de Monaco », « acquisitions de biens en provenance de la Principauté de Monaco ») et, **pour les services, l'établissement** du preneur ou du prestataire à Monaco. | `GeographieTva` distingue la destination ou l'origine Monaco (biens) de l'établissement à Monaco (services). La TVA reste française. |
| 5 | §1.8, achats en trois puces | L'art. 290 I 3° a, b et c vise les AIC, les livraisons en France par un assujetti non établi et les services (art. 259-1° et 259 A) acquis auprès d'un non-établi. Les importations n'y figurent pas. | Trois natures d'achat en e-reporting entrant ; aucune pour l'importation. |
| 6 | Art. 290 I 4° b (« acquisitions intracommunautaires non soumises à la TVA en application du I de l'article 258 D ») | **L'art. 258 D I est la simplification des opérations triangulaires** (art. 141 de la directive ; BOI-TVA-CHAMP-20-40, vu en extrait) : l'acquisition d'un intermédiaire identifié dans un autre État membre n'est pas taxée en France quand elle sert à une livraison subséquente à un destinataire identifié en France. Ce n'est pas le régime des acquisitions d'une société en franchise sous 10 000 €, qui ne sont pas soumises en vertu de l'art. 256 bis I 2°. La première conception (et l'inventaire, 7.2) les confondait. | Le 4° b concerne les opérations triangulaires, que le moteur repère et envoie en saisie manuelle (6.2). Le fondement de l'e-reporting des acquisitions d'un franchisé sous le seuil reste **à confirmer** (annexe B, point 15) ; T10 recommande de les refuser pour l'instant. |
| 7 | N7, e-reporting des ventes à distance | L'art. 290 I 2° a vise les ventes à distance « réputées ne pas se situer en France ». | Une vente sous OSS aura un circuit B2C ; la catégorie TNT1 reste à confirmer au bac à sable. |
| 8 | N7, définition de la vente à distance | Elle vise un acquéreur **non assujetti** et aussi un **assujetti ou une personne morale non assujettie dont les acquisitions intracommunautaires ne sont pas soumises à la TVA** (art. 14 § 4 de la directive, art. 258 A I 1° ; texte luxembourgeois et note de la douane vus en extrait). | Le fait « vente à distance » compte les particuliers **et** les clients marqués « non assujettis » sans n° de TVA valide (7.5). |
| 9 | §1.5, ligne « International » | « Une vente à distance au-delà de 10 000 € est taxable dans le pays d'arrivée » : la franchise de l'art. 293 B ne couvre que les opérations situées en France. | Le seuil s'évalue aussi pour une société en franchise (7.5, 7.8). |
| 10 | §1.6 : seuils de la franchise, sans dire quand elle prend fin | Depuis le 1/1/2025, un dépassement du seuil majoré y met fin à la date même du dépassement (`reglementation.md` 4.1, source secondaire). | Régime de la société daté au jour près (O7). |
| 11 | §1.7, « bons d'achat à usages multiples » | La définition est confirmée (art. 256 ter, BOI-TVA-CHAMP-10-10-40-50) : un bon est à usage unique quand le lieu et la TVA sont connus à son émission ; sinon il est à usages multiples, et seule son utilisation est taxée (règle de la directive 2016/1065, rapportée par la recherche, à relire). | Refus de la vente d'un produit « carte cadeau » tant que le traitement n'existe pas (T9). La première conception laissait à tort la vente en France possible. |
| 12 | §1.8 et `reglementation.md` A14 : base de la TVA d'importation | L'art. 292 inclut les frais accessoires (commission, emballage, transport, assurance) jusqu'au premier lieu de destination indiqué sur le document de transport (BOFiP vu en extrait, avec un exemple : Lyon). | Le contrôle de la TVA d'importation ajoute ces frais (6.3). |
| 13 | Transport et transit liés à une importation | L'art. 262 II 14° (art. 144 de la directive) exonère les prestations liées à une importation dont la valeur entre dans la base de la TVA d'importation ; la preuve de cette inclusion incombe au redevable (BOFiP vu en extrait). | Une ligne de frais à 0 % d'un fournisseur établi en France peut avoir cette raison (6.2) ; le moteur vérifie que la déclaration en douane rattachée compte des frais accessoires. |
| 14 | N6, preuves de la LIC | Deux documents délivrés par **deux parties différentes, indépendantes l'une de l'autre, du vendeur et de l'acquéreur** (art. 45 bis du règlement 282/2011 ; recherche N6 ; docblock de `app/Domain/Facturation/Services/PreuvesRequises.php:26-32`). | La complétude exige deux émetteurs tiers distincts (7.4, lot C-a). |
| 15 | N6, n° de TVA valide « à la date de la livraison » | La décision I2 et le code retiennent le jour de l'émission (`app/Domain/Facturation/Services/ControleNatureOperation.php:119-134`). | I2 gardée, avec un avertissement quand la livraison précède l'émission sans vérification à sa date (O3). |
| 16 | Exigibilité des AIC et des services autoliquidés | AIC : le 15 du mois suivant la livraison, ou la date de la facture si elle est antérieure (art. 269-2 d, vu en extrait). Services autoliquidés : la directive retiendrait la réalisation de la prestation (art. 66, second alinéa), non retrouvé en ligne ce jour. | Étape 5 (8.2) ; jamais « au paiement » par défaut pour une nature autoliquidée ; à confirmer (annexe B, point 2). |
| 17 | N9, catégorie O | Règle BR-O-05 : une ligne O ne porte **aucun** taux. Le code met 0 % sur toute ligne exonérée (`app/Domain/Facturation/Services/ConstructeurLigneDocument.php:185`). | Taux null en catégorie O pour l'étape 2 ; la base garde 0. |
| 18 | §3.4 et §3.8 : connexion SUPER PDP et réglages par magasin | Tranché depuis : une connexion par société, réglages fiscaux portés par la société (CLAUDE.md:155, :174). | Le moteur lit le statut de TVA, l'option pour les débits et le n° de TVA sur la société. |
| 19 | NOTA de l'art. 290 | Abrogé au 1/1/2027, mais maintenu jusqu'à sa reprise par les mesures réglementaires des art. L. 215-39, L. 216-55 et L. 216-56 du CIBS. | Références dans des tables datées (O4). |
| 20 | Professionnel non assujetti, non traité par la recherche | L'art. 290 I 2° b vise toute livraison en France à une personne non assujettie. | Circuit B2C pour un professionnel non assujetti, hors facture électronique (O2). |
| 21 | Disparitions de marchandises | BOI-TVA-DED-60-30 : reversement de la TVA déduite (art. 207 VI de l'annexe II), avec des dispenses pour les marchandises détruites, et pour les vols et la démarque inconnue (conditions non lues). | Décision prévue pour l'étape 7 (8.3) ; conditions à confirmer (annexe B, point 16). |

---

## 2. Architecture

### 2.1 Vue d'ensemble

```
Écrans Filament ──┐                                                          ┌─► ConstructeurLigneDocument (traduit les lignes chiffrées en attributs)
Caisse ───────────┤                                                          ├─► ContenuFiscal (vente : colonnes et decision_tva signées au registre)
API (étape 10) ───┼─► Collecteurs ──► Faits ──► MoteurTva ──► Décision ──────┼─► factures_achat (achat : colonnes, decision_tva, journal d'audit)
Réception PDP ────┘   (un par module,  (objets    (pur, daté)  (lignes        ├─► StockLedgerService (coût d'entrée lu sur les lignes d'achat)
(étape 2)              lisent la base)  immuables)     │        chiffrées)    └─► Étape 2 (circuit) · Étape 5 (rubriques) · Étape 7 (sorties)
                                                       │
                                     Tva/Calcul : CalculateurLigne (déplacée, règles inchangées)
                                     Tva/Regles : taux, mentions, preuves, catégories, seuils, territoires (datés et versionnés)
```

### 2.2 Emplacement et noms

**Un nouveau domaine `app/Domain/Tva/`**, à côté de `Achats`, `Catalogue`, `Contacts`, `Facturation`, `Stock` et `Tenancy`. Ce n'est pas un dossier de premier niveau : `app/Domain` existe, et chaque domaine métier y a son dossier.

Pourquoi un domaine à part :
- Le moteur sert à quatre modules. Le loger dans `Facturation` ferait dépendre les achats et le stock des règles de vente : tout y est aujourd'hui (`ContexteDeVente`, `ControleNatureOperation`, `MentionsFiscales`, `TerritoireFiscal`), sans aucune règle pour les achats.
- **La calculatrice `CalculateurLigne` y passe aussi.** Elle est aujourd'hui dans `Facturation`, alors que `Achats` et `Stock` en dépendent déjà (`app/Domain/Achats/Services/ConstructeurLigneAchat.php:11`, `app/Domain/Stock/Services/StockLedgerService.php:6`). Laissée dans `Facturation`, elle créerait une dépendance circulaire : `Tva` dépendrait de `Facturation`, qui dépend de `Tva`. Déplacée, `Facturation`, `Achats`, `Stock` et `CaisseSecurisee` ne dépendent plus que de `Tva`, et `Tva` d'aucun autre domaine.
- Le nom `Tva` évite toute confusion avec le « module fiscal » de la caisse sécurisée (`app/Domain/CaisseSecurisee/ModuleFiscal.php:21-26`), qui désigne l'art. 286.
- Le domaine ne dépend d'aucun modèle Eloquent ni d'aucun écran.

```
app/Domain/Tva/
  MoteurTva.php                 le moteur (pur)
  Calcul/                       CalculateurLigne (déplacée ; ses méthodes en euros restent, une API en centimes entiers s'ajoute),
                                CalculDesLignesDeVente (la partie pure de ConstructeurLigneDocument::construire() et repartirLaTvaParTaux()),
                                CalculDesRetours (retours de vente K2 et retours d'achat T13), CalculDesLignesDAchat, RepartitionParCumul,
                                BeneficeUnitaire (bénéfice affiché, franchise comprise)
  Faits/                        objets d'entrée immuables (2.3)
  Decisions/                    objets de sortie immuables (2.7)
  Regles/                       CatalogueDesRegles, ReglesTva, tables datées (taux, mentions et notes de calcul, preuves attendues,
                                catégories et VATEX, seuils), ExigenceDuNumeroTva
  Geographie/                   GeographieTva, TerritoireDeTva
  Enums/                        NatureOperation (déplacée), NatureAchat, CategorieTva, Circuit, Redevable, Gravite, SortieDesBiens,
                                LieuDesServices, Dedouanement, TypeDeLigne, TypeLigneAchat, RaisonDuTauxZero, ParticulariteFiscale,
                                EtatDeLaDecision, MethodeDeCalcul, SourceDuFait, CategorieOperation (déplacée)
  Exceptions/                   DecisionTvaRefuseeException, CalculDuBrouillonChangeException (porte l'avant et l'après)
```

**Les collecteurs restent dans leur module**, puisqu'ils lisent ses modèles :
- `app/Domain/Facturation/Services/CollecteurFaitsDeVente.php` ;
- `app/Domain/Achats/Services/CollecteurFaitsDAchat.php` ;
- `app/Domain/Contacts/Services/CollecteurFaitsDuTiers.php` (nouveau sous-dossier `Services`, comme dans les autres domaines) ;
- `app/Domain/Tenancy/Services/CollecteurFaitsDeLaSociete.php`.

Les noms suivent le code existant : français, sans accents, verbes et compléments.

### 2.3 Les faits (entrées du moteur)

Des objets `final readonly`, comme `Emetteur` ou `CopieClient` aujourd'hui. Les montants sont des entiers en centimes, les taux des entiers en centièmes de point (comme le contenu signé), les dates des `CarbonImmutable` données par l'appelant. Chaque valeur garde sa source (`SourceDuFait` : `Saisi`, `Fiche`, `DerniereFactureValidee`, `DerniereFactureEmise`, `Magasin`, `Societe`, `Derive`, `ParDefaut`), affichée à l'écran et gardée dans la décision.

Signatures proposées (sans implémentation) :

```php
// La société et le magasin, à la date de l'opération.
final readonly class FaitsDeLaSociete {
    public function __construct(
        public bool $enFranchise,                     // régime en vigueur à la date de l'opération (historique daté, O7)
        public ?string $numeroTva,
        public bool $optionDebits,
        public TerritoireDeTva $territoireDuMagasin,  // métropole, Corse, Guadeloupe-Martinique-La Réunion
        public AdresseFiscale $adresseDuMagasin,
        public bool $estDemonstration,
        public array $identiteManquante,              // list<string> : identité de la société et du magasin, hors n° de TVA (ExigenceDuNumeroTva)
        public ?CompteurDeSeuil $ventesADistance,     // lot M8 (T7, T8)
        public ?CarbonImmutable $inscriteOssLe,       // lot M8
    ) {}
}

// Le client ou le fournisseur, tel que sa fiche (ou sa copie figée) le dit ce jour-là.
final readonly class FaitsDuTiers {
    public function __construct(
        public bool $estProfessionnel,                // toujours vrai pour un fournisseur
        public bool $assujetti,                       // complément 9 ; vrai par défaut pour un professionnel
        public bool $entitePublique,                  // complément 9, décision E7
        public AdresseFiscale $adresse,               // pays et code postal → territoire
        public bool $etablissementEnFrance,           // un établissement en France intervient dans l'opération
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
        public ?SortieDesBiens $sortieDesBiens,       // non / expédiés par le magasin / emportés par le client ; null : sans réponse
        public ?AdresseFiscale $livraison,
        public bool $livraisonComplete,               // adresse, code postal, ville et pays
        public ?CarbonImmutable $dateDeLivraison,
        public ?LieuDesServices $lieuDesServices,     // en France / hors de France ; null : non précisé
        public ?GesteDeRegime $geste,                 // nature appliquée, ou TVA française gardée, avec son auteur et l'instant
        public bool $factureDemandee,                 // complément 13 (lot C-b)
        public array $lignes,                         // list<FaitsDeLigneDeVente>
        public array $decisionsDOrigine,              // pour un avoir : list<DecisionFigee>, une par facture corrigée
    ) {}
}

final readonly class FaitsDeLigneDeVente {
    public function __construct(
        public int $ordre,
        public TypeDeLigne $type,                     // bien ou service
        public bool $fraisAccessoires,                // Service::frais_accessoires
        public bool $lieuFixeParLaLoi,                // Service::lieu_specifique (art. 259 A)
        public ParticulariteFiscale $particularite,   // aucune, accises, moyen de transport neuf, carte cadeau ou bon d'achat
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
        public FaitsDeLaSociete $societe,             // régime de la société à cette date (O7)
        public FaitsDuTiers $fournisseur,
        public ?OrigineDesMarchandises $origine,      // pays et code postal de départ réels ; null tant qu'elle n'est pas confirmée
        public ?Dedouanement $dedouanePar,            // nous (ou notre transitaire) / le fournisseur
        public ?DeclarationEnDouane $declarationEnDouane,
        public ?string $numeroFournisseur,
        public ?CarbonImmutable $dateDeLaFacture,
        public int $escompte,                         // centièmes de point
        public ?int $totalDeControle,                 // total TTC de la facture du fournisseur, saisi pour contrôle (facultatif)
        public array $lignes,                         // list<FaitsDeLigneDAchat>
        public array $fraisRattaches,                 // list<int> : lignes de frais d'autres factures validées, rattachées à celle-ci (T14)
        public bool $avoirFournisseurEmis,            // un avoir fournisseur déjà émis fige le coût d'entrée (6.6)
        public ?SaisieManuelle $saisieManuelle,       // T11 : nature et montants imposés, motif, auteur
    ) {}                                              // devise et taux de change : seulement après la réponse T16
}

final readonly class FaitsDeLigneDAchat {
    public function __construct(
        public int $ordre,
        public TypeLigneAchat $type,                  // produit / frais d'acheminement / autre service
        public int $quantite,
        public int $prixUnitaireHtEnCentimes,
        public int $remiseEnCentiemes,
        public int $tauxFacture,                      // tel que sur la facture du fournisseur
        public ?RaisonDuTauxZero $raisonDuTauxZero,   // ligne à 0 % d'un fournisseur établi en France (question A-Q5, 5.4)
        public ?int $tauxDuEnFrance,                  // saisi ; sinon proposé par le moteur
        public ?int $tauxDeVenteDuProduit,            // proposition du taux dû en France
        public bool $lieuFixeParLaLoi,
        public ParticulariteFiscale $particularite,
        public bool $rattacheeAUneAutreFacture,       // ligne de frais d'un tiers qui s'ajoute au coût d'une autre facture (T14)
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
        public ?int $fraisAccessoires,                // jusqu'au premier lieu de destination (art. 292)
        public array $tvaParTaux,                     // list<array{taux: int, base: int, tva: int}>, reprise de la déclaration
    ) {}
}
final readonly class GesteDeRegime {                  // nature VenteEnFrance : TVA française gardée
    public function __construct(public NatureOperation $nature, public int $auteurId, public CarbonImmutable $le) {}
}
final readonly class CompteurDeSeuil { public function __construct(public int $anneePrecedente, public int $anneeEnCours) {} }
```

`VerificationVies` ne porte pas l'identifiant interne de la consultation : il reste dans la colonne `consultation_vies_id` du document, et l'écran montre le n° de preuve donné par VIES et l'heure (P13).

### 2.4 Les collecteurs

Ils lisent la base et construisent les faits ; c'est là, et seulement là, que se font les lectures, sous `MagasinScope`. Le moteur ne voit jamais un modèle.

```php
final class CollecteurFaitsDeVente {
    public function pourLeFormulaire(array $etat, Magasin $magasin, CarbonImmutable $instant): FaitsDeVente; // brouillon, aperçu
    public function pourLEmission(Facture $facture, CarbonImmutable $instant): FaitsDeVente;               // sous le verrou du registre
    public function pourLaCaisse(PanierCaisse $panier, Magasin $magasin, CarbonImmutable $instant): FaitsDeVente;
    public function pourLAvoir(Avoir $avoir, CarbonImmutable $instant): FaitsDeVente;                      // décisions figées des factures corrigées
    public function pourLeDevis(Devis $devis): FaitsDeVente;                                               // à la date du devis (aperçu et PDF)
    public function suggestionDeSortie(Client $client): ?SuggestionDeSortie;                               // dernière facture émise au même client (5.1)
}

final class CollecteurFaitsDuTiers {
    public function duClient(Client $client, CarbonImmutable $jour): FaitsDuTiers;     // fiche + vérification VIES du jour
    public function duClientFige(Facture|Avoir $document): FaitsDuTiers;              // copie prise à l'émission
    public function duFournisseur(Fournisseur $fournisseur, CarbonImmutable $jour): FaitsDuTiers;
}

final class CollecteurFaitsDeLaSociete {
    public function pour(Magasin $magasin, CarbonImmutable $jour): FaitsDeLaSociete;   // régime en vigueur ce jour-là ; société relue sous verrou à l'émission
}

final class CollecteurFaitsDAchat {
    public function pourLeFormulaire(array $etat, Magasin $magasin): FaitsDAchat;
    public function pourLaValidation(FactureAchat $facture): FaitsDAchat;              // sous le verrou de la facture d'achat
    public function pourLAvoirFournisseur(AvoirFournisseur $avoir): FaitsDAchat;       // décision figée de l'achat
    public function suggestionDOrigine(Fournisseur $fournisseur): ?OrigineDesMarchandises; // dernière facture validée du même fournisseur
}
```

- Le catalogue des lignes de vente se lit dans le collecteur, comme aujourd'hui dans `ConstructeurLigneDocument::catalogue()` (`app/Domain/Facturation/Services/ConstructeurLigneDocument.php:264-281`) : une requête pour les produits (archivés compris), une pour les services, sous `MagasinScope`. Un produit archivé est refusé avec le message actuel (`:65-67`).
- La vérification VIES retenue se lit comme aujourd'hui : la dernière du jour à Paris, du n° exact de la fiche, dans le magasin actif (`app/Domain/Facturation/Services/ControleNatureOperation.php:119-134`).

### 2.5 Le moteur

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
    public function compatibilite(array $decisionsFigees): array;   // list<Manquement> pour un avoir sur plusieurs factures
}
```

**Vente, dans l'ordre :**
1. Choisir les règles en vigueur à la date de l'opération (`CatalogueDesRegles::enVigueurLe()`).
2. Société en franchise : vente en France, lignes en catégorie E, à 0 % au même prix TTC, article par article, mention de franchise (B10). Les prestations à un professionnel établi hors de France suivent T10.
3. Natures candidates, d'après les faits : territoire du client, assujetti, n° de TVA et sa vérification, sortie des biens, adresse de livraison, biens ou services, lieu des services. Le pays seul n'en ouvre aucune.
4. Pour chaque candidate, évaluer toutes ses conditions (liste de `Condition`, chacune « remplie » ou non, avec la valeur constatée) : les conditions d'aujourd'hui (`ControleNatureOperation.php:89-245`), devenues pures, plus celles de cette conception.
5. Nature retenue (T2) :
   - un geste « appliquer » sur une nature : elle, si toutes ses conditions sont réunies ; sinon bloquant à l'émission, avec tous les manquements et la voie « Rester à la TVA française » ;
   - un geste « TVA française gardée » : vente en France, et la nature écartée est gardée dans la décision ;
   - sans geste, une candidate dont toutes les conditions sont réunies **grâce à des réponses données dans ce document** (sortie des biens, adresse, date) : **appliquée d'office** ;
   - sans geste, une candidate réunie **sans qu'aucune question n'ait été posée** (prestation à un professionnel établi hors de France) : **proposée** ; la vente reste en France tant que personne ne l'applique, et l'émission exige un choix (`tva.choix_du_regime_requis`) ;
   - sinon, vente en France (état « incomplet » si une candidate n'a pas toutes ses conditions).
6. Lieu des services : un service au lieu fixé par la loi exécuté hors de France est bloquant (`tva.lieu_du_service_hors_de_france`, 7.3).
7. Particularités : accises ou moyen de transport neuf ferment les natures d'exonération ; une carte cadeau ou un bon d'achat est bloquant (T9).
8. Lignes chiffrées par `CalculDesLignesDeVente` : catégorie et VATEX (déduits), taux, taux du prix, PU HT, PU TTC, PU payé, HT, TVA, TTC ; R4 pour un professionnel ; les frais accessoires suivent les biens.
9. Vente à distance (lot M8), évaluée **avant la branche de la franchise** dans ses effets et sur le HT des lignes chiffrées : biens expédiés par le magasin vers un autre État membre, à un particulier ou à un client non assujetti, sans n° de TVA valide sur VIES. Au-delà du seuil, ou si la société est inscrite à l'OSS : bloquant (T8).
10. Mentions et note de calcul, tirées de la table datée.
11. Circuit (8.1) ; démonstration : aucun.
12. Preuves attendues (table datée) et obligations annexes ; démonstration : aucune.
13. Identité et clients (P8) : identité légale de la société et du magasin (facture à un professionnel, ou facture demandée), n° de TVA de la société selon `ExigenceDuNumeroTva` (facture à un professionnel hors franchise, nature autre que la vente en France), SIREN d'un client établi en France, nom et adresse du client sur une facture (O5).
14. Avertissements : taux inhabituel (X8), nom VIES très différent de la fiche, vente à distance sous le seuil, service à un particulier domicilié hors UE (art. 259 B), livraison antérieure à l'émission sans vérification VIES à sa date (O3).
15. Versions et instantané des faits.

**Avoir.** `deciderAvoir()` reçoit les décisions figées de toutes les factures corrigées (`decisionsDOrigine`).
- La clé de compatibilité est (type de client, franchise, nature, circuit) : la règle actuelle (`app/Domain/Facturation/Actions/CreerAvoirAction.php:101-117`), plus le circuit. Deux factures de même nature dont la copie du client diffère (établi en France, puis non) ne vont pas dans le même avoir.
- Chaque ligne d'avoir reprend la nature de sa facture, donc sa catégorie et son VATEX.
- Ses montants sont calculés par différence de cumul (K2), comme aujourd'hui (`app/Domain/Facturation/Models/LigneFacture.php:172-186`), par `CalculDesRetours`.
- Ses mentions et sa note de calcul sont celles de la table du jour de l'avoir, qui est un nouveau document.

**Achat, dans l'ordre :**
1. Règles à la date de l'opération ; régime de la société à cette date.
2. Nature de chaque ligne (table de 6.2), d'après l'établissement du fournisseur, l'origine réelle, le préfixe de son n° de TVA, le dédouanement, le type de ligne, la raison d'une ligne à 0 %, le lieu fixé par la loi et le statut de la société. Un fait contradictoire donne « cas à vérifier », jamais un choix silencieux.
3. Montants (`CalculDesLignesDAchat`) : TVA facturée ligne par ligne (`CalculateurLigne::montantsDAchat()`, `:93-103`) ; TVA autoliquidée calculée sur le total HT de chaque taux, puis répartie par cumul (T13) ; TVA d'importation reprise de la déclaration en douane, contrôlée avec la base de l'art. 292.
4. TVA déductible : la TVA facturée française et l'autoliquidée pour une société redevable ; rien en franchise, où elles s'ajoutent au coût ; jamais une TVA étrangère (T15).
5. Montant dû au fournisseur, TVA à payer à l'État, montant à payer à la douane ou au transitaire.
6. Coût d'entrée de chaque ligne de produit (6.4).
7. Manquements (bloquants) et avertissements ; saisie manuelle si elle est autorisée (T11).
8. Versions et instantané des faits.

### 2.6 Les règles versionnées

```php
final class CatalogueDesRegles {
    public function enVigueurLe(CarbonImmutable $date): ReglesTva;
}

final readonly class ReglesTva {
    public function __construct(
        public string $version,              // « 2026.10.0 », changée à chaque modification des données
        public TableDesTaux $taux,           // territoire → taux en vigueur, taux normal (remplace TerritoireTva.php:65-88)
        public TableDesMentions $mentions,   // code → texte, du / au ; notes de calcul ; mention de l'avoir de régularisation (lot A7), avec ses paramètres
        public TableDesCategories $categories, // nature × franchise × type de ligne → catégorie et VATEX
        public TableDesPreuves $preuves,     // nature × sortie des biens → preuves attendues (remplace PreuvesRequises::typesAdmis())
        public Seuils $seuils,               // ventes à distance 10 000 € depuis le 1/7/2021, « seuil proche » à 9 000 € ; détaxe > 100 € TTC
        public GeographieTva $geographie,    // États membres, préfixes EL et XI, territoires exclus, DOM, COM, Monaco
    ) {}
}
```

- **Des données, pas du code éparpillé.** Chaque entrée porte une date de début et une date de fin éventuelle. Une entrée « déjà en vigueur, sans date de début connue » a un début nul : on n'invente aucune date.
- **Mentions et notes de calcul.** La table de départ reprend mot pour mot les textes d'aujourd'hui (`app/Domain/Facturation/Enums/NatureOperation.php:54-58`, `app/Domain/Tenancy/Enums/RegimeTva.php:44`, `app/Domain/Facturation/Services/MentionsFiscales.php:45-55`). Les références au CIBS n'y entrent qu'une fois leurs numéros confirmés, par une nouvelle version (O4).
- **Taux.** Métropole 20, 10, 5,5 et 2,1 % ; Corse 20, 13, 10, 5,5, 2,1 et 0,9 % ; Guadeloupe, Martinique et La Réunion 8,5, 2,1, 1,75 et 1,05 % (`app/Domain/Facturation/Enums/TerritoireTva.php:80-88`). Une loi de finances qui change un taux devient une entrée datée.
- **Catégories et VATEX.** S : aucun VATEX ; E (franchise) : VATEX-FR-FRANCHISE ; K : VATEX-EU-IC ; G : VATEX-EU-G ; AE : VATEX-EU-AE ; O : VATEX-EU-O, sans taux. La catégorie d'une livraison vers un DOM (G ou E) reste à confirmer (annexe B, point 8) ; elle ne s'enregistre pas avant l'étape 2.
- **Preuves attendues.** Export : MRN ou CN23. Livraison vers un DOM : MRN ou CN23, et un document de transport. LIC : deux documents de transport de catégorie a), ou un a) et un b), émis par deux parties différentes indépendantes l'une de l'autre, du vendeur et de l'acquéreur ; l'attestation de l'acquéreur s'il emporte les biens (aujourd'hui `PreuvesRequises.php:48-56` et `:87-95`).
- **Empreinte.** Un test calcule l'empreinte des données et la compare à celle enregistrée avec leur version : modifier une donnée sans changer de version fait échouer la suite.
- **Le CIBS.** Le jour où les numéros sont confirmés, une version des règles termine l'entrée CGI la veille et ouvre l'entrée CIBS ce jour-là, avec un test daté. Les documents émis avant gardent leur texte figé.

### 2.7 La décision (sortie)

```php
final readonly class DecisionTvaVente {
    public string $versionMoteur;  public string $versionRegles;  public CarbonImmutable $dateDeLOperation;
    public NatureOperation $nature;           public EtatDeLaDecision $etat;   // france, appliquee_d_office, appliquee, proposee, incomplete, bloquee
    public array $propositions;               // list<PropositionDeNature> : nature, conditions, manques, total que la vente aurait
    public bool $franchise;  public MethodeDeCalcul $methode;  public bool $retireLaTvaDuPrix;   // K3
    public array $lignes;                     // list<LigneDeVenteChiffree>
    public Totaux $totaux;                    // HT, TVA, TTC, en centimes
    public Redevable $redevable;  public array $mentions;  public ?MentionImprimee $noteDeCalcul;
    public Circuit $circuit;  public bool $venteADistance;
    public array $preuvesAttendues;  public array $obligations;
    public array $conditions;  public array $manquements;  public array $avertissements;
    public InstantaneDesFaits $faits;  public ?GesteDeRegime $geste;

    public function bloquants(): array;
    public function ecartAvec(array $lignesEnregistrees, NatureOperation $natureEnregistree): ?EcartDeCalcul; // avant et après (5.1)
    public function figee(): array;           // ce que garde la colonne decision_tva (ci-dessous)
}

final readonly class LigneDeVenteChiffree {
    public int $ordre;  public TypeDeLigne $type;  public CategorieTva $categorie;  public ?string $vatex;
    public ?int $taux;              // centièmes ; null en catégorie O (BR-O-05)
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
    public int $tvaDeductible;  public int $tvaNonDeductible;  public int $quotePartFrais;  public int $quotePartDroits;
    public ?int $coutUnitaireDEntree;   // dix-millièmes d'euro ; null pour une ligne hors stock
}
```

**Ce que garde la colonne `decision_tva`** (P14) : seulement ce qui n'a pas de colonne et ne se déduit pas des faits figés. Pour une vente : versions, date de l'opération, état, méthode, prix hors taxe ou non, redevable, mentions et note de calcul (code et texte), conditions (code, remplie, constat), faits et leur source, nature écartée quand la TVA française a été gardée, obligations, avertissements. **N'y sont pas** : la nature, la franchise, le circuit, les gestes, la sortie des biens, la livraison et la consultation VIES (colonnes du document) ; les lignes et leurs montants (colonnes des lignes) ; la catégorie et le VATEX de chaque ligne (déduits de la nature, de la franchise et du type de ligne, avec la version des règles).

**Exemple : la colonne `decision_tva` d'une livraison intracommunautaire à Müller GmbH**

```json
{
  "version_moteur": "1.0", "version_regles": "2026.10.0", "date_operation": "2026-10-14",
  "etat": "appliquee_d_office", "methode": "ht_de_ligne", "retire_la_tva_du_prix": true, "redevable": "client",
  "mentions": [
    { "code": "categorie_biens", "texte": "Catégorie de l'opération : Livraison de biens" },
    { "code": "exoneration_lic", "texte": "Exonération de TVA, article 262 ter I du CGI" }
  ],
  "note_de_calcul": { "code": "professionnel_sans_tva_francaise", "texte": "Client professionnel, vente sans TVA française : HT de chaque ligne de produit = quantité × prix catalogue TTC ÷ (1 + TVA retirée), arrondi une fois ; un service se facture à son prix sans TVA." },
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

La note de calcul de l'exemple est le texte d'aujourd'hui (`MentionsFiscales.php:53`) ; si T4 = a, une nouvelle version des règles la change pour les services.

Données personnelles : la décision et les gestes ne gardent que l'identifiant de l'utilisateur, jamais son nom, ni adresse IP ni navigateur (CLAUDE.md, « Journal d'audit »).

### 2.8 Un moteur, trois usages ; VIES ; démonstration

| Usage | Quand | Ce qui se passe |
|---|---|---|
| **Aperçu** | À chaque requête Livewire du formulaire, mémorisé une fois par requête (mécanisme actuel de `ConstructeurLigneDocument::apercu()`, `:91-115`). | Décision provisoire chiffrée : nature, questions à afficher, manques, totaux. Aucun appel réseau : VIES n'est jamais consulté à la frappe. |
| **Contrôle** | À l'émission, sous le verrou du registre (`app/Domain/Facturation/Actions/EmettreFactureAction.php:119`), à l'étape 6 de l'ordre des contrôles (ci-dessous) ; à la validation d'un achat, sous le verrou de la facture d'achat (`app/Domain/Achats/Actions/ValiderFactureAchatAction.php:42`). | Même calcul, depuis les faits relus sous verrou. Refus avec tous les manquements. |
| **Figement** | Juste après un contrôle réussi. | Vente : colonnes, lignes et `decision_tva` écrites et signées. Achat : colonnes des lignes et `decision_tva` écrites, entrée au journal d'audit. |

**Ordre des contrôles de l'émission** (il remplace les étapes 6, 7 et 11 de l'ordre fixé par la conception société, 8.6) :
1. **Hors transaction** : recherche du jeton de la demande (R3) sans verrou, en lecture seule ; s'il est connu, le résultat déjà enregistré est rendu, sans rien consulter. Si la facture n'est plus un brouillon, rien n'est consulté.
2. **Hors transaction** : si la nature appliquée, appliquée d'office ou choisie dans la fenêtre « Émettre » exige VIES et qu'aucune vérification valide du jour n'existe, `AssurerUneVerificationViesDuJourAction` interroge VIES (elle enveloppe `app/Domain/Contacts/Actions/VerifierNumeroTvaAction.php:38`). VIES injoignable : rien n'est émis, le brouillon reste, le message le dit (`tva.vies_injoignable`) ; aucune consultation n'est enregistrée, comme aujourd'hui (`:36`, `:54-66`).
3. Transaction : jeton (`ActionsUniques::executer`, après le verrou du registre), verrou du registre, porte de validation du magasin, verrou de la facture, autorisation relue sous verrou.
4. **Le moteur**, sur les faits relus sous verrou : calcul du brouillon changé, identité légale, SIREN et adresse du client, choix du régime requis, conditions de la nature, consultation VIES lue (jamais demandée), seuil des ventes à distance. Un seul refus, avec tous les manquements.
5. Journée clôturée (ticket), poste du vendeur, puis stock (R1, avec la confirmation de vente sans stock) : le refus fiscal arrive donc **avant** l'avertissement de stock.
6. Numérotation, figement (client, émetteur, colonnes fiscales, `decision_tva`, gestes), événement d'émission, encaissement, signature, jeton.

Jamais d'appel réseau sous le verrou du registre. VIES passe derrière une interface, comme les autres fournisseurs externes (CLAUDE.md:164) : `app/Integrations/Vies/Contracts/RegistreVies.php`, implémentée par le client actuel (`app/Integrations/Vies/ClientVies.php:37`) et par `FauxRegistreVies`, sur le modèle de `FauxRne` et `FauxRepertoireSirene`.

**Magasin de démonstration** (CLAUDE.md:164) : le conteneur résout `RegistreVies` en `FauxRegistreVies` quand le magasin actif est la démonstration ; la décision y a le circuit « aucun » et aucune obligation ; la démonstration est exclue des compteurs et des relevés de la société. Aujourd'hui `VerifierNumeroTvaAction` interrogerait le vrai VIES pour les clients fictifs de la démonstration (`:54`, avec le n° de TVA de la société comme demandeur).

### 2.9 Refus et codes stables

`DecisionTvaRefuseeException` (liste de `Manquement`) remplace `NatureOperationRefuseeException` (`app/Domain/Facturation/Exceptions/NatureOperationRefuseeException.php:14-22`) et sert aussi à la validation des achats. Elle hérite de `LogicException`, comme elle. **Le message des conditions d'une nature garde sa forme exacte** (`:21`) : « Impossible d'émettre en « … » : … . Choisissez « Vente en France » (TVA française) si ces conditions ne sont pas remplies. »

Un manquement porte :
- un **code stable**, pour les refus : `tva.vies_invalide`, `tva.vies_injoignable`, `tva.sortie_des_biens_inconnue`, `tva.choix_du_regime_requis`, `tva.calcul_du_brouillon_change`, `tva.lieu_du_service_a_preciser`, `tva.lieu_du_service_hors_de_france`, `tva.bon_d_achat_non_gere`, `tva.taux_zero_sans_raison`, `tva.taux_zero_en_france`, `tva.document_separe_requis`, `tva.une_nature_par_document`, `tva.numero_tva_societe_absent`, `tva.seuil_ventes_a_distance_depasse`, `tva.oss_non_gere`, `tva.depart_hors_du_dom_non_gere`, `tva.prestation_vers_un_dom_non_geree`, `tva.origine_non_confirmee`, `tva.dedouanement_inconnu`, `tva.tva_etrangere_facturee`, `tva.operation_triangulaire_possible`, `tva.faits_contradictoires`, `tva.cas_exclu`, `tva.achat_etranger_en_franchise_non_gere`, `tva.cout_fige_par_un_avoir_fournisseur`, `tva.devise_sans_taux`, `achat.numero_fournisseur_absent`, `achat.date_fournisseur_absente`, `achat.droit_de_creer_des_produits`, `identite.incomplete`, `client.siren_absent`, `client.adresse_absente` ; les avertissements ont aussi leur code, donné avec chaque contrôle en 5.6 ;
- le **chemin du champ** ;
- un **message en français**, identique à l'écran, dans le refus et dans l'API ;
- sa **gravité** : bloquant, avertissement ou information.

Un avoir qui mélange des factures incompatibles garde son refus actuel : `InvalidArgumentException`, avec les messages d'aujourd'hui (`app/Domain/Facturation/Actions/CreerAvoirAction.php:104`, `:110`, `:116`) ; le moteur fournit la règle (`compatibilite()`), l'action garde le message.

---

## 3. Données

### 3.1 Règles de schéma

- Chaque table change dans **sa migration de création**, puis la base se recrée par `migrate:fresh` (CLAUDE.md, « Développement : toujours repartir de zéro ») : aucune migration `add_x_to_y`, aucun renommage par migration, aucune reprise de données, aucun code de compatibilité.
- **Une seule table nouvelle** : `regimes_tva_societe` (lot M5). Ni table des décisions d'achat, ni table des déclarations en douane (P14, 3.3).
- **Une seule source par donnée** (P14) : ce qui est interrogé ou existe déjà reste une colonne ; le reste de la décision va dans une colonne JSON ; ce qui se déduit des faits figés et de la version des règles ne s'enregistre pas.
- **Colonnes JSON** : `$table->json()`, c'est-à-dire le type `json` sous PostgreSQL, jamais `jsonb`, qui réordonne les clés (même règle que pour le contenu signé, `database/migrations/2026_10_14_100002_create_enregistrements_fiscaux_table.php:38-41`). Elles s'écrivent une fois, clés dans un ordre canonique, et un test sous PostgreSQL vérifie qu'elles se relisent à l'identique.
- **« Exactement l'un des deux »** : contrainte CHECK sous PostgreSQL et déclencheurs BEFORE INSERT et BEFORE UPDATE sous SQLite, sur le modèle de `database/migrations/2026_10_09_100001_create_clotures_caisse_personnelles_table.php:91-105`, testés sous les deux moteurs.
- **Chaque colonne arrive dans le lot qui s'en sert.** Ce qui ne sert qu'aux étapes 2, 5 ou 7, ou attend une réponse, n'est pas créé avant : catégorie B2C, exigibilité, circuit d'achat (étape 2) ; rubriques de déclaration, option du fournisseur pour les débits (étape 5) ; devise et taux de change (T16) ; compteur des ventes à distance (T8).

### 3.2 Table par table

| Table (migration de création) | Changements | Lot |
|---|---|---|
| `societes` (`database/migrations/0001_01_00_000000_create_societes_table.php`) | `regime_tva` (`:60`) est retirée : le régime se lit dans `regimes_tva_societe`, à une date (O7). `Societe::regimeTvaLe(CarbonImmutable)` et un accesseur du régime en vigueur aujourd'hui gardent les lectures actuelles ; les écritures des tests passent par les aides existantes (4.3). Lot M8, si T8 = a : `ventes_a_distance_depart_annee` (smallint nullable : l'année d'arrivée dans PILIQI), `ventes_a_distance_depart_annee_precedente` et `ventes_a_distance_depart_annee_en_cours` (decimal(12,2) nullable), `oss_inscrite_le` (date nullable), journalisés (JET 270). `numero_tva` (`:50`) et `option_debits_tva` (`:63`) inchangés. | M5, M8 |
| **`regimes_tva_societe`** (nouvelle, modèle `App\Domain\Tenancy\Models\RegimeTvaSociete`) | `id`, `societe_id` (clé, `restrictOnDelete`), `regime` (string(30)), `en_vigueur_le` (date), `saisi_par` (clé vers `users`, nullable, `restrictOnDelete`), `created_at`. `BelongsToSociete` ; `DeclencheursDeProtection::ajoutSeul()` ; index (`societe_id`, `en_vigueur_le`, `id`). Le régime à une date est celui de la dernière entrée (par date, puis par identifiant) dont la date est au plus celle-là. | M5 |
| `clients` (`database/migrations/2026_10_02_200001_create_clients_table.php`) | `assujetti_tva` (booléen, vrai par défaut, sans objet pour un particulier), `entite_publique` (booléen, faux par défaut). `pays` (`:31`) se choisit dans une liste de pays nommés (écran, lot M4). | M3 |
| `fournisseurs` (`database/migrations/2026_10_02_200002_create_fournisseurs_table.php`) | `etabli_en_france` (booléen, **sans valeur par défaut en base** : le modèle le calcule depuis le pays, comme `Client::normaliserSesIdentifiants()`, `app/Domain/Contacts/Models/Client.php:124-147` ; pays FR : établi ; pays étranger : non, sauf case cochée) ; `ne_facture_pas_de_tva` (booléen, faux). `pays` (`:23`) contrôlé comme celui d'un client. Ni SIREN (déduit du SIRET quand il le faut, étape 2), ni option pour les débits (étape 5), ni devise (T16). | M5 |
| `produits` (`database/migrations/2026_10_02_200003_create_produits_table.php`) | `particularite_fiscale` (string(30), « aucune » ; aussi « accises », « moyen de transport neuf », « carte cadeau ou bon d'achat »). `taux_tva` (`:41`) reste le **taux de vente** (« Taux de TVA de vente »). `cmup_ht` (`:33`) devient `cout_moyen` seulement si O1 est accepté (en franchise, il comprend la TVA non déductible). | M4, M7 |
| `services` (`database/migrations/2026_10_02_200005_create_services_table.php`) | Inchangée par ces lots (prix et taux de référence de R2 au lot A6 de la chaîne société). Le commentaire de `lieu_specifique` (`:30-35`), qui dit « jamais facturé sans TVA française à un professionnel étranger », est corrigé : le lieu dépend du lieu d'exécution (7.3). | M3 |
| `consultations_vies` (`database/migrations/2026_10_02_200006_create_consultations_vies_table.php`) | `client_id` (`:33`) devient nullable ; `fournisseur_id` (clé nullable) ; exactement l'un des deux (3.1) ; index (`magasin_id`, `fournisseur_id`, `consultee_le`). Toujours en ajout seul (`:46`). | M5 |
| `factures` (`database/migrations/2026_10_03_100003_create_factures_table.php`) | `transport_par` (`:51`) devient `sortie_biens` (string(30) nullable : `non`, `expedies_par_le_magasin`, `emportes_par_le_client` ; nul : sans réponse). Copies figées `client_assujetti_tva` et `client_entite_publique` (booléens nullables, avec les autres `client_*`, `:63-73`). **Gestes** : `nature_confirmee` (string(40) nullable), `nature_confirmee_par` (clé vers `users`, nullable), `nature_confirmee_le` (datetime nullable) : entrées du brouillon, figées et signées à l'émission. `lieu_des_services` (string(20) nullable : `france`, `hors_de_france`). `circuit` (string(40) nullable, écrit à l'émission, signé). `decision_tva` (json nullable : vide sur un brouillon, écrite une fois à l'émission, signée telle qu'enregistrée). `nature_operation` (`:40`) et `franchise_en_base` (`:32`) restent : la nature et la franchise que calcule le moteur à chaque enregistrement du brouillon, comme les montants des lignes, figées à l'émission, jamais relues comme une entrée ; leurs commentaires sont réécrits. `facture_demandee` arrive avec le lot C-b. | M3 |
| `avoirs` (`database/migrations/2026_10_05_100001_create_avoirs_table.php`) | Copies figées du client ; `circuit` (repris des factures corrigées) ; `decision_tva` (json : versions, mentions et note de son jour, compatibilité). Lignes inchangées. | M3 |
| `devis` (`database/migrations/2026_10_03_100001_create_devis_table.php`) | `sortie_biens` remplace `transport_par` ; gestes (`nature_confirmee`, `_par`, `_le`) ; `lieu_des_services`. **Aucune décision enregistrée** : le PDF rappelle le moteur avec les faits du devis et les règles du jour de son émission. | M3 |
| `lignes_facture`, `lignes_avoir`, `lignes_devis` | Inchangées : la catégorie et le VATEX d'une ligne se déduisent de la nature du document, de la franchise et du type de ligne (P14). | — |
| `factures_achat` (`database/migrations/2026_10_07_100001_create_factures_achat_table.php`) | `pays_expedition` (string(2) nullable), `code_postal_expedition` (string(10) nullable), `dedouane_par` (string(20) nullable) ; déclaration en douane, **une par facture** : `douane_reference` (string(30) nullable), `douane_date_bae` (date nullable), `douane_valeur`, `douane_droits`, `douane_autres_taxes`, `douane_frais_accessoires` (decimal(12,2) nullable), `douane_tva` (json nullable : taux, base et TVA, repris de la déclaration) ; `total_de_controle` (decimal(12,2) nullable : total TTC de la facture du fournisseur, facultatif) ; `decision_tva` (json nullable : écrite à la validation, remplacée à chaque modification). Restent : `total_ht`, `total_tva` (= TVA facturée), `total_ttc` (= dû au fournisseur) (`:33-35`), `escompte_pourcentage` (`:39`), `date_validation` (`:30`), `devise` (`:31`, sans écran tant que T16 n'est pas tranchée). Si T16 = a : `taux_change` (decimal(16,8) nullable). | M5 |
| `lignes_achat` (`database/migrations/2026_10_07_100002_create_lignes_achat_table.php`) | `type_ligne` (string(20) : `produit`, `frais_acheminement`, `autre_service`) ; `produit_id` (`:22`) et `nouveau_produit` (`:27`) exigés pour une ligne de produit seulement (garde réécrite, `app/Domain/Achats/Models/LigneAchat.php:57-61`) ; `nature_achat` (string(40) nullable, écrite à la validation) ; `taux_tva_france` (decimal(5,2) nullable) ; `raison_taux_zero` (string(20) nullable) ; `montant_tva_autoliquidee`, `montant_tva_deductible`, `quote_part_frais`, `quote_part_droits` (decimal(12,2), 0) ; `cout_unitaire_entree` (decimal(14,4) nullable) ; `lieu_fixe_par_la_loi` (booléen, faux). **`taux_tva` (`:36`) et `montant_tva` (`:39`) gardent leur nom** : le taux et la TVA facturés. Lot M7 : `facture_achat_rattachee_id` (clé nullable vers `factures_achat`, `restrictOnDelete`, contrôlée par `regleExisteDansLeMagasin()`). | M5, M7 |
| `avoirs_fournisseur`, `lignes_avoir_fournisseur` (`…2026_10_07_100003…`, `…100004…`) | Lignes : `montant_tva_autoliquidee`, `montant_tva_deductible`, qui réduisent ceux de l'achat ; en-tête : `decision_tva` (json). | M5 |
| `mouvements_stock` (`database/migrations/2026_10_02_200004_create_mouvements_stock_table.php`) | **Inchangée.** Le coût d'une réception ne s'écrit pas sur le mouvement, immuable (`:34-35`) : le rejeu du grand livre lit le coût des lignes d'achat (3.3). `cout_unitaire_ht` (`:29`) reste réservé aux retours au fournisseur ; il devient `cout_unitaire` seulement si O1 est accepté. | (M7) |
| `clotures_caisse` | Aucun changement : l'agrégat par jour, catégorie B2C et taux se construit à l'étape 2 depuis les documents figés. | Étape 2 |

### 3.3 Conservation de la décision

**Ventes.**
- À l'émission, dans la transaction où la nature, la livraison et la consultation VIES s'écrivent déjà (`app/Domain/Facturation/Actions/EmettreFactureAction.php:147-154`), s'écrivent aussi le circuit, les gestes, le lieu des services, les copies du client et `decision_tva`. Ces colonnes sont protégées comme les autres par `ProtegeLesDonneesValidees` (`app/Domain/CaisseSecurisee/Concerns/ProtegeLesDonneesValidees.php`).
- **Contenu signé** : le bloc « document » (`app/Domain/CaisseSecurisee/Services/ContenuFiscal.php:159-187`) ajoute `sortie_biens` (à la place de `transport_par`, `:176`), le circuit, les gestes, le lieu des services et `decision_tva` **telle qu'enregistrée** (la colonne décodée, jamais resérialisée par une classe du domaine) ; la copie du client signée (`:344-351`, avec `app/Domain/Facturation/Services/CopieClient.php:56-70`) gagne « assujetti » et « entité publique ». Le bloc « contexte » perd les mentions et la catégorie de l'opération (`:200-201`, `:266-267`), désormais dans `decision_tva`.
- Le vérificateur d'intégrité recalcule le bloc « document » depuis les colonnes (`app/Domain/CaisseSecurisee/Services/VerificateurIntegrite.php:707-721`) et le compare strictement, ordre des clés compris (`:1490-1493`) : d'où la colonne JSON écrite une fois, en ordre canonique, de type `json`.
- **La ventilation signée reste dans le module fiscal** (`ContenuFiscal.php:315-327`), séparée de celle des écrans (`app/Domain/Facturation/Concerns/VentileLaTvaParTaux.php:96-113`) : une retouche faite pour un écran ne change jamais le recalcul des documents signés. Un test prouve qu'elles donnent le même résultat (le doublon D3 est gardé volontairement). La clé reste le taux : une nature par document, donc une catégorie par taux.
- **`ModuleFiscal::VERSION` reste « 1.0 »** (`app/Domain/CaisseSecurisee/ModuleFiscal.php:23`, figée par `tests/Feature/Filament/EnregistrementFiscalResourceTest.php:313` et `tests/Feature/Tenancy/TenantIsolationTest.php:527`) jusqu'à la première version attestée (étape 3) : en développement, on ne garde aucune trace d'un état antérieur (CLAUDE.md:146). Son docblock (`:10-15`) nomme les classes du domaine `Tva` dont le résultat entre dans le périmètre signé par les colonnes qu'elles remplissent : `MoteurTva`, `CalculDesLignesDeVente`, `CalculDesRetours`, `CatalogueDesRegles`.
- Le PDF, la fiche et le duplicata **relisent** les mentions et la note de calcul figées ; ils ne rappellent jamais le moteur (corrige D5 : aujourd'hui `resources/views/factures/pdf.blade.php:105` et `resources/views/avoirs/pdf.blade.php:53` recalculent les mentions au rendu).
- Un avoir fige ses propres mentions et reprend la nature, la franchise et le circuit de ses factures ; un devis n'est jamais figé.

**Achats.**
- La validation écrit les colonnes des lignes et `decision_tva`, et inscrit au journal d'audit de la société l'action `FactureAchatValidee` (catégorie « Opérations », 10 ans : `app/Domain/Tenancy/Enums/CategorieAudit.php:16`, `config/conservation.php`) par `JournalAudit::inscrire()` (`app/Domain/Tenancy/Services/JournalAudit.php:71`), avec les seuls champs retenus par l'action (nature de chaque ligne, totaux, résumé de la décision).
- Une modification après la validation (autorisation de valider, C2, CLAUDE.md:172), une déclaration en douane complétée ou des frais rattachés remplacent `decision_tva` et inscrivent `FactureAchatModifiee` ou `CoutDEntreeRecalcule`, avec l'ancienne décision complète en anciennes valeurs. Une saisie manuelle inscrit `TvaDAchatSaisieALaMain`, avec son motif. Le journal d'audit, chaîné et en ajout seul, est l'historique : aucune table de décisions d'achat.

**Stock.**
- Le coût d'entrée de chaque ligne de produit (dix-millièmes) est la colonne `cout_unitaire_entree`, lue à la réception par `IncrementerStockApresValidationFactureAchat` (`app/Domain/Achats/Listeners/IncrementerStockApresValidationFactureAchat.php:32`) et par le rejeu du grand livre (`app/Domain/Achats/Actions/ModifierFactureAchatAction.php:147-158`).
- Les mouvements ne changent pas. Un coût corrigé recalcule le coût moyen par le rejeu existant (`StockLedgerService::corrigerUneReception()`, `app/Domain/Stock/Services/StockLedgerService.php:100-131`), à quantité inchangée ; `receptionner()` (`:49-69`) garde sa signature.
- Changer le coût d'une ligne est refusé dès qu'un avoir fournisseur a été émis sur sa facture : ses retours sont sortis à l'ancien coût par des mouvements immuables (C1) (`tva.cout_fige_par_un_avoir_fournisseur`, 6.6).

### 3.4 Isolation des magasins et des sociétés

- **Rattachement.** Toutes les colonnes nouvelles sont sur des modèles d'un magasin (`BelongsToMagasin` + `MagasinScope`), sauf `regimes_tva_societe` (`BelongsToSociete` + `SocieteScope`) et les réglages du lot M8 sur `societes`.
- **Préremplissage.** « Dernière facture validée du même fournisseur » et « dernière facture émise au même client » se lisent sous `MagasinScope`. Deux magasins d'une société ont chacun leur fiche du même fournisseur allemand et leurs vérifications VIES, sans copie de l'une à l'autre (CLAUDE.md:155).
- **Existence.** Les nouvelles règles `exists` (facture rattachée, fournisseur d'une consultation VIES) passent par `regleExisteDansLeMagasin()`. Aucune unicité nouvelle n'est globale.
- **Lectures nouvelles hors du magasin, seulement si T7 = a : des sommes fiscales de la société.**
  - Compteur des ventes à distance : HT net des avoirs, année précédente et année en cours (lot M8) ;
  - relevé du mois des livraisons intracommunautaires et des prestations autoliquidées : HT net par n° de TVA du client (lot M4, 7.10) ;
  - plus tard, compteur des acquisitions d'une société en franchise (si T10 l'ouvre).
  - Chacune est écrite dans une seule classe (`CompteurDesVentesADistance`, `ReleveFiscalDeLaSociete`), sur le modèle de `Societe::aVenduUneFois()` : `withoutGlobalScope(MagasinScope::class)` filtré par `whereIn('magasin_id', $societe->magasinsReels()->select('id'))`, démonstration exclue, sous un test nommé ; elle s'ajoute à la liste exhaustive de CLAUDE.md:155 (lot M0).
  - Les montants ne se voient que du propriétaire et des administrateurs autorisés à gérer les paramètres de la société ; un vendeur ne voit que l'état (« seuil non atteint », « proche », « atteint »).
- **Jobs et commandes.** Aucun nouveau dans ces lots. Ceux de l'étape 2 qui liront les décisions passeront par `WithTenantContext`, comme les jobs actuels de transmission.

---

## 4. Intégration et remplacement de l'existant

Règle du projet : réécrire proprement, supprimer ce qui devient inutile, aucun code de compatibilité (CLAUDE.md:146-149).

### 4.1 Classe par classe

| Élément actuel | Sort | Raison | Lot |
|---|---|---|---|
| `CalculateurLigne` (`app/Domain/Facturation/Services/CalculateurLigne.php`) | **Déplacée** vers `app/Domain/Tva/Calcul/`, règles inchangées. Ajouts : une API en centimes entiers pour le moteur (aujourd'hui l'API prend et rend des euros en `float`, `:73`, `:93`, `:117`, avec un calcul interne en entiers) ; `repartirParCumul(int $total, list<int> $poids)` pour les frais et les droits (mécanisme de `repartirLaTva()`, `:148-165`) ; `coutUnitaireDEntree()` (coût net de `coutUnitaireNet()` plus les ajouts divisés par la quantité, un seul arrondi au dix-millième) ; `valeurAuCout()` à la place de `valeurAuCoutMoyen()` (`:276-288`) seulement si O1 est accepté. | Dépendance circulaire évitée ; calculs validés, couverts par `tests/Unit/CalculateurLigneTest.php` (42 tests). | M1, M5, M7 |
| `ContexteDeVente` (`app/Domain/Facturation/Services/ContexteDeVente.php`) | **Supprimé.** Ses prédicats deviennent des lectures de la décision, ou, pour un document déjà enregistré, une fonction des règles sur sa nature et sa franchise (`retireLaTvaDuPrix`, `calculeDepuisLeHtDeLigne`). `refuserSiLeTypeDeClientAChange()` et `refuserSiLeRegimeAChange()` (`:140-166`) deviennent `DecisionTvaVente::ecartAvec()`, plus large (taux, nature, montants) et assorti de l'avant et de l'après (5.1). | L'objet « contexte fiscal » de la vente, aux prédicats dispersés. | M2 |
| `ConstructeurLigneDocument` (`app/Domain/Facturation/Services/ConstructeurLigneDocument.php`) | **Réduit à un traducteur** : il appelle le collecteur et le moteur, puis traduit les lignes chiffrées en attributs de ligne. La lecture du catalogue (`:264-281`) passe dans le collecteur ; le choix du taux (`:179-185`), du prix payé (`:200`) et le calcul (`:214-217`, `:234-252`) passent dans `CalculDesLignesDeVente`. `apercu()` (`:91-115`) appelle le moteur. | Le moteur produit tous les montants (P9). | M2 |
| `NatureOperation` (`app/Domain/Facturation/Enums/NatureOperation.php`) | **Déplacée** vers `app/Domain/Tva/Enums/` (mêmes valeurs en base). Ses textes (`mention()`, `:50-60` ; `conditions()`, `:67-77`) partent dans les tables des règles ; ses prédicats (`:83-148`) et `proposees()` (`:167-174`) dans le moteur. | Énumération porteuse de règles et de textes non datés (D6). | M2 |
| `ControleNatureOperation` (`app/Domain/Facturation/Services/ControleNatureOperation.php`) | **Absorbé** : les conditions (`:89-245`) deviennent l'évaluation pure du moteur ; la lecture de la consultation du jour (`:119-134`) passe dans le collecteur. Le message « le lieu de … est fixé par la loi (art. 259 A du CGI) : la TVA française s'applique » (`:220`), faux quand le service est exécuté hors de France, est corrigé (7.3). | Appelé seulement à l'émission (`EmettreFactureAction.php:144`), il lisait la base. | M2, M3 |
| `NatureOperationRefuseeException` | **Remplacée** par `DecisionTvaRefuseeException` (même parent, même message, codes en plus). | Un seul refus pour les ventes et les achats (P8). | M2 |
| `MentionsFiscales` (`app/Domain/Facturation/Services/MentionsFiscales.php`) | `pour()` (`:61-80`) et `calcul()` (`:124-133`) sont **absorbées** : mentions et note de calcul viennent de la table datée et sont figées dans la décision ; `calcul()` recalculait la note à chaque impression. `bonDAvoir()` (`:91-100`) reste (crédit d'un avoir, pas une règle de TVA). La mention de l'avoir de régularisation (lot A7) devient une entrée de la table, avec son paramètre. | D4, D5, D6 ; un duplicata imprimait le texte du jour. | M2, M3 |
| `CategorieOperation`, `NatureLigne`, `TransportPar`, `TypePreuve` | `CategorieOperation` est déplacée vers `Tva` ; `NatureLigne` et `TypePreuve` restent dans `Facturation`, les collecteurs les traduisent ; `TransportPar` est remplacée par `SortieDesBiens`, plus générale (sans réponse, non, expédiés, emportés). | Le moteur ne dépend d'aucune énumération d'un module. | M2, M3 |
| `TerritoireFiscal` (`app/Domain/Facturation/Services/TerritoireFiscal.php`) et `TerritoireTva` (`app/Domain/Facturation/Enums/TerritoireTva.php`) | **Absorbés** par `GeographieTva` et `TerritoireDeTva` (métropole, Corse, Guadeloupe-Martinique-La Réunion, Guyane-Mayotte, collectivité d'outre-mer, Monaco, autre État membre, Irlande du Nord, territoire exclu de l'UE, pays tiers). Lot M2 : comportement identique. Lot M3 : une seule fonction lit toutes les adresses (C10) ; « identifié au répertoire Sirene » (`PAYS_FRANCE`, `:22`) se sépare du territoire de TVA (C2) ; Monaco se lit par le mouvement des biens et par l'établissement pour les services (1.4, point 4). | Listes dispersées (D7) ; Saint-Barthélemy, Saint-Martin, Saint-Pierre-et-Miquelon, la Guyane et Mayotte comptent aujourd'hui dans le territoire de TVA français (`app/Domain/Contacts/Models/Client.php:140-142`). | M2, M3 |
| `NumeroTva`, `NumeroTvaAuFormat`, `NumeroTvaNormalise` | **Conservés.** | Format et normalisation, utilisés par les collecteurs. | — |
| `ClientVies` (`app/Integrations/Vies/ClientVies.php:37`) | **Conservé** derrière l'interface `RegistreVies`, avec `FauxRegistreVies` (tests et démonstration). | Chaque fournisseur externe derrière une interface, avec une implémentation factice (CLAUDE.md:164). | M1 |
| `VerifierNumeroTvaAction` (`app/Domain/Contacts/Actions/VerifierNumeroTvaAction.php`) | **Conservée** ; elle utilise `RegistreVies` ; étendue aux fournisseurs au lot M5 ; appelée par le domaine avant la transaction d'émission (2.8). | D10. | M1, M5 |
| `FactureResource::verifierLeNumeroTvaSurViesSiBesoin()` (`app/Filament/App/Resources/FactureResource.php:785-794`) | **Supprimée.** | La consultation passe dans le domaine : une émission sans écran (API v1) consultera aussi. | M1 |
| `NoteSaNatureDOperation` (`app/Domain/Facturation/Concerns/NoteSaNatureDOperation.php:31-58`) | **Réécrit** : il note les faits (sortie des biens, livraison, lieu des services) et le geste, et efface le geste quand un fait déclencheur change (client, sortie, livraison, composition biens et services, lieu des services). Il n'impose plus le transport (`:52-54`). | D1, C3 ; le geste est une entrée, pas un résultat. | M3 |
| `ChoisitLaNatureDeLOperation` (écran) | **Réécrit** en panneau et questions progressives (5.1). | Le vendeur choisit aujourd'hui la nature à la main dans une liste. | M4 |
| `AjouteDesLignesDeVente` (écran) | **Allégé** : plus aucune règle recodée (`:174`, `:296-298`, `:326`, `:366`) ; il affiche l'aperçu du moteur. | D1, D2. | M2 |
| `LigneFacture::prixUnitaireTtcPaye()` et `montantsDuRetour()` (`app/Domain/Facturation/Models/LigneFacture.php:194-201`, `:172-186`) | `prixUnitaireTtcPaye()` lit la règle « TVA retirée du prix » sur la nature et la franchise du document, au lieu de comparer deux taux. `montantsDuRetour()` délègue à `CalculDesRetours` (mêmes montants). | D1. | M2 |
| `NoterBordereauDetaxeAction::refusPour()` (`app/Domain/Facturation/Actions/NoterBordereauDetaxeAction.php:82-91`) | **Réécrite** sur un seul prédicat des règles : « vente en France avec des biens taxés ». | D1. | M2 |
| `PreuvesRequises` (`app/Domain/Facturation/Services/PreuvesRequises.php`) | **Garde** le jugement de complétude après l'émission, en PHP et en SQL (`:76-96`, `:107-133`), mais lit les preuves attendues dans la table datée des règles, par nature et sortie des biens, à la date d'émission. `typesAdmis()` (`:48-56`) est **supprimée**. Le complément 4 (annulation tracée, « établie par » obligatoire, émetteurs tiers et distincts) s'y ajoute au lot C-a. | Une seule définition des preuves par nature (P1). | C-a, M4 |
| `Facture::relevantDeLeInvoicing()` (`app/Domain/Facturation/Models/Facture.php:612-615`) | **Supprimée** ; remplacée par la colonne `circuit`, figée. | C5 ; son docblock la dit provisoire. | M3 |
| `Facture::serie()` (`:596-599`), `libelleDocument()` (`:572-575`), `scopeTickets()` (`:284-287`) | **Conservées, découplées** : la série suit le type de client et la facture demandée (lot C-b) ; le circuit vient de la décision ; la clôture de l'étape 3 lira le circuit B2C (complément 8). | C6. | M3, C-b |
| `DeclencherTransmissionPdpApresEmissionFacture` (`app/Domain/Facturation/Listeners/DeclencherTransmissionPdpApresEmissionFacture.php:28`) | **Réécrit** : il lit le circuit et refuse, avec un message, ce que la passerelle ne prend pas encore en charge (E13). | Il transmet aujourd'hui toute facture à un professionnel, 0 % compris. | M3 |
| `PdpGatewayInterface`, `CalculateurPeriodeEReporting`, `GenererSoumissionEReportingAction` | **Étape 2** (plan SUPER PDP ; complément 11 pour la suppression du calendrier interne). | Hors de ces lots. | Étape 2 |
| `VentileLaTvaParTaux` (`app/Domain/Facturation/Concerns/VentileLaTvaParTaux.php:96-113`) et `ContenuFiscal::lignesEtTotaux()` (`ContenuFiscal.php:290-334`) | **Gardées séparées volontairement**, avec un test d'égalité (3.3). | Le calcul signé ne dépend d'aucune classe partagée avec les écrans. | M1 |
| `ContenuFiscal`, `VerificateurIntegrite` | **Modifiés une seule fois** pour tout le format signé du plan (3.3). | Un seul changement de format (D26). | M3 |
| `ConstructeurLigneAchat` (`app/Domain/Achats/Services/ConstructeurLigneAchat.php`) | **Réécrit sur la décision** : types de lignes (`:24-36`), taux facturé distinct du taux dû en France. Le taux d'une ligne n'est **plus jamais recopié sur la fiche** (C1) : le taux de vente se corrige seulement dans le bloc « Vente en magasin » de la ligne, immédiatement, avec le JET 270 (complément 2, `:102-113`, `:353-386`). | La demande exige des TVA distinctes ; aujourd'hui un achat allemand à 0 % fait vendre le produit à 0 % en caisse. | M5 |
| `LigneAchat::coutUnitaireHt()` et `montantsDuRetour()` (`app/Domain/Achats/Models/LigneAchat.php:92-99`, `:123-139`) | `coutUnitaireHt()` est **remplacée** par la colonne `cout_unitaire_entree`. `montantsDuRetour()` délègue à `CalculDesRetours`, qui plafonne la TVA autoliquidée rendue à la part de la ligne (T13). | Coût d'entrée de la décision ; retours cohérents avec la répartition par cumul. | M5 |
| `IncrementerStockApresValidationFactureAchat` (`:32`) et `ModifierFactureAchatAction::lotsDesReceptions()` (`:147-158`) | **Modifiés** : ils lisent `cout_unitaire_entree` ; les lignes hors stock ne passent pas. | — | M5 |
| `StockLedgerService` | **Conservé**, signature comprise ; aucun coût écrit sur une réception. Renommage `cmup_ht` → `cout_moyen` seulement si O1 est accepté. | 3.3. | M7 |
| `ValeurStockApercu` (`app/Filament/App/Resources/ProduitResource/Widgets/ValeurStockApercu.php:59-64`) | **Réécrit** si O1 est accepté : valeur au coût, sans TVA ajoutée. | C7. | M7 |
| Bénéfice affiché à l'achat (`app/Filament/Concerns/AjouteDesLignesDAchat.php:126-157`, `:355-379`), `BaremePrixDeVente` (`app/Domain/Catalogue/Services/BaremePrixDeVente.php:110-126`), `Produit::synchroniserPrixDeVente()` (`app/Domain/Catalogue/Models/Produit.php:381-398`) | Le bénéfice est calculé par une seule fonction du domaine (`BeneficeUnitaire`), nourrie par le coût d'entrée et la franchise que donne le moteur. Le barème garde sa formule (il propose un prix TTC). La conversion TTC ↔ HT de la fiche reste une aide d'affichage, calculée par une fonction des règles qui tient compte de la franchise (C9). Les écrans ne font qu'afficher. | C8, C9 ; aucune règle dans un écran (P1). | M6, M7 |
| `RegimeTva` (`app/Domain/Tenancy/Enums/RegimeTva.php`) | **Conservé**, aligné sur `vat_regime` de SUPER PDP ; docblock corrigé (`:8` : « du magasin ») ; la constante de la mention (`:39-44`) part dans la table des mentions. | C11. | M2 |
| `Societe::mentionsLegalesManquantes()` (`app/Domain/Tenancy/Models/Societe.php:379-396`) | **Conservée** pour l'identité ; l'exigence du n° de TVA (`:392`) passe par `ExigenceDuNumeroTva`, une seule fonction des règles, que le moteur appelle aussi pour les natures hors de la vente en France et pour les achats autoliquidés. | Une seule définition (P1), sans casser la page d'identité (`app/Filament/Societe/Pages/IdentiteSociete.php:86-88`) ni la caisse (`app/Filament/App/Pages/Caisse.php:407`). | M2 |
| `Emetteur` (`app/Domain/Facturation/Services/Emetteur.php`) | **Conservé** : il alimente `FaitsDeLaSociete` et reste signé ; il lit le régime en vigueur (`:108`, `:148`). | — | M5 |
| `EmettreFactureAction` (`EmettreFactureAction.php:116-183`) | **Modifiée** : ordre de 2.8 ; VIES avant la transaction, après la recherche du jeton ; le moteur remplace les contrôles des lignes `:131-136` et `:144`. | P8 ; aucune consultation VIES pour une demande rejouée. | M1, M2 |
| `CreerAvoirAction` (`app/Domain/Facturation/Actions/CreerAvoirAction.php:80-117`) | **Modifiée** : compatibilité par `MoteurTva::compatibilite()`, mêmes messages et même exception. | Avoir sur plusieurs factures (2.5). | M3 |
| `PanierDeCaisse` (`app/Domain/Facturation/Services/PanierDeCaisse.php:276`) | **Modifié** : le total du panier vient de l'aperçu du moteur. | Il appelait `ContexteDeVente::pour()`. | M2 |
| `CreerFactureDepuisDevisAction` (`app/Domain/Facturation/Actions/CreerFactureDepuisDevisAction.php:43-49`) | **Modifiée** : elle copie les faits (sortie des biens, livraison, lieu des services) et le geste du devis ; l'émission de la facture reconfirme le régime (O8). | Elle copiait `franchise_en_base`, `nature_operation` et `transport_par`. | M3 |
| `CreerDevisAction`, `ModifierDevisAction`, `ModifierFactureAction`, `EmettreDevisAction` (`CreerDevisAction.php:37`, `ModifierDevisAction.php:58`, `ModifierFactureAction.php:77`, `EmettreDevisAction.php:57-58`) | **Modifiées** : faits et gestes notés, montants et nature calculés par le moteur ; l'envoi d'un devis avertit des conditions non réunies (jamais un refus). | Appelants de `ContexteDeVente`. | M2, M4 |
| `FactureResource::lignesEntry()` (`app/Filament/App/Resources/FactureResource.php:507-516`) | **Modifiée** : elle lit la règle « TVA retirée du prix » sur la nature et la franchise du document. | Appelant de `ContexteDeVente::documentRetireLaTvaDuPrix()`. | M2 |
| Vues PDF des factures, avoirs et devis | **Modifiées** : mentions et note de calcul figées (factures, avoirs) ; devis : moteur à la date du devis. | D5. | M3 |
| `AjouteDesLignesDAchat` (écran) | **Réécrit** : il affiche l'aperçu du moteur d'achat ; il ne calcule plus lui-même la TVA d'une ligne (`:401-413`) ni le bénéfice (`:147-148`, `:369-371`). | P1. | M6 |

### 4.2 Doublons et contradictions de l'inventaire, et leur sort

| Constat | Résolution | Lot |
|---|---|---|
| D1 : « vente sans TVA française » codée quatre fois | Une seule règle : « TVA retirée du prix », fonction de la nature et de la franchise. | M2 |
| D2 : franchise lue dans les écrans | Lue par le seul collecteur de la société. | M2 |
| D3 : deux ventilations | **Gardées volontairement** : la ventilation signée reste dans le module fiscal ; un test prouve l'égalité. | M1 |
| D4, D5, D6 : catégorie calculée trois fois, mentions recalculées au rendu, textes sans date | Mentions et note de la décision, figées et relues ; table datée. | M2, M3 |
| D7 : listes géographiques dispersées | `GeographieTva`, dans les règles. | M2 |
| D8 : taux de vente utilisé comme taux d'achat | Taux d'achat portés par la ligne d'achat. | M5 |
| D9 : calendrier d'e-reporting en double | Étape 2 (complément 11). | Étape 2 |
| D10 : VIES dans l'écran | Domaine, avant la transaction d'émission. | M1 |
| D11 : validation du taux répétée | Contrôle par la table datée, appelé par le moteur. | M2 |
| D12 : « à payer au fournisseur » faux en autoliquidation | Totaux distincts (5.4). | M6 |
| C1 : un seul taux de produit pour vente, achat, stock et barème | Taux d'achat sur la ligne ; le taux de vente ne bouge que par la fiche ou le bloc « Vente en magasin ». | M5 |
| C2 : « établi en France » confondu avec le territoire de TVA | Deux notions dans `GeographieTva`. | M3 |
| C3 : départ des biens toujours supposé en métropole | Le départ est le territoire du magasin ; un magasin des DOM qui expédie hors de son département est refusé (O6). | M3 |
| C4 : franchisé enfermé dans la vente en France | T10. | M4 |
| C5 : circuit contraire aux trois conditions et à E13 | Circuit calculé et figé ; refus de transmission tant que la passerelle ne gère pas la catégorie. | M3 |
| C6 : le type de client décide de quatre choses | Série, méthode, circuit et clôture séparés. | M3, C-b |
| C7, C8, C9 : valeur du stock TTC, coûts et bénéfice d'un franchisé, prix HT d'une fiche en franchise | O1. | M7 |
| C10 : deux lectures d'adresse | Une seule. | M3 |
| C11 : docblock de `RegimeTva` | Corrigé. | M2 |
| C12 : décisions prises mais pas encore codées | Fusion avec le lot de compléments (11.2). | C-a, C-b |

### 4.3 Tests existants touchés

Protocole, point 4 : aucun test n'est supprimé, affaibli ni réécrit pour passer. Un test change seulement quand la règle qu'il fixe change par une décision, ou quand la classe qu'il vise disparaît ; le message de commit le dit, test par test. Cette liste vient d'une recherche, dans `tests/` de l'instantané, de chaque colonne, classe et message que touche le plan.

| Lot | Fichiers | Pourquoi | Ce qui change |
|---|---|---|---|
| M1 | `tests/Unit/CalculateurLigneTest.php`, `tests/Feature/Facturation/ClotureAutomatiqueCaisseTest.php`, `tests/Feature/Facturation/CloturerLesJourneesActionTest.php` | `CalculateurLigne` déménage | La ligne `use` seule ; aucune assertion. |
| M2 | `tests/Feature/Facturation/NatureOperationTest.php` (29 tests) | `NatureOperationRefuseeException` est remplacée | **Gardé au niveau des actions** (émission, PDF, devis, avoirs) : seule la classe attendue change, à un endroit (`:123`) ; les messages restent identiques. Des tests unitaires du moteur s'ajoutent à côté. |
| M2 | `tests/Feature/Facturation/ConstructeurLigneDocumentTest.php` (4 tests) | Il construit `ContexteDeVente` directement (`:35`, `:58`, `:79`, `:99`), qui disparaît | Faits du moteur à la place du contexte ; mêmes montants attendus. |
| M2 | `tests/Unit/TerritoireFiscalTest.php`, `tests/Unit/TerritoireTvaTest.php` | Classes absorbées par `GeographieTva` | Mêmes cas, reportés dans le test unitaire de `GeographieTva`. |
| M2 | `tests/Feature/Contacts/ConsultationViesTest.php`, `tests/Feature/Filament/ServiceResourceTest.php` | Docblocks qui citent `ControleNatureOperation` | Commentaires seulement. |
| M3 | `tests/Feature/CaisseSecurisee/EmissionSigneeTest.php`, `tests/Feature/CaisseSecurisee/VerificateurIntegriteTest.php`, `tests/Feature/Facturation/FacturePdfGeneratorTest.php`, `AvoirPdfGeneratorTest.php` | Le format signé change, une seule fois | Nouvelles clés attendues ; aucune assertion perdue. |
| M3 | `tests/Feature/Facturation/PreuvesOperationTest.php` (`:93`, `:97`), `NatureOperationTest.php`, `tests/Feature/Filament/DevisResourceTest.php`, `FactureResourceTest.php` | `transport_par` devient `sortie_biens` (nouvelle question à trois réponses, T3) | Nom et valeurs du fait. |
| M3 | `NatureOperationTest.php` (`:415`) | Message faux sur le lieu fixé par la loi (règle corrigée, 7.3) | Nouveau message. |
| M3 | `tests/Feature/Facturation/TypeClientFigeTest.php` (`:96`, `:118`), `tests/Feature/Facturation/TransmissionPdpTest.php` | `relevantDeLeInvoicing()` disparaît ; règle C5 | Le circuit figé à la place ; la transmission suit les trois conditions. |
| M3 | `tests/Feature/Filament/ClientResourceTest.php` | C2 | Gardé ; un test de plus prouve que Saint-Barthélemy exige un SIREN sans être dans le territoire de TVA français. |
| M4 | `FactureResourceTest.php` (environ 34 tests de TVA), `DevisResourceTest.php`, `tests/Feature/Filament/CaisseTest.php`, `ProduitResourceTest.php` | La liste des natures cède la place au panneau ; particularités ; T5 | Gestes et questions à la place de la liste ; avertissements de caisse. |
| M4 | `tests/Feature/Filament/ClotureCaisseResourceTest.php:429` | Seul test qui émet en France un produit à 0 %, si T5 = a | Un second taux légal (5,5 %), pour garder sa ventilation à deux taux. Les autres tests à 0 % n'enregistrent qu'un brouillon ou un devis. |
| M5 | `tests/Feature/Filament/FactureAchatResourceTest.php` | C1 : le taux de la ligne n'est plus celui de la fiche | Tests réécrits pour la règle nouvelle. |
| M5 | `tests/Feature/Achats/FactureAchatActionsTest.php`, `AvoirFournisseurActionsTest.php` | — | **Inchangés** : `taux_tva` garde son sens sur la ligne d'achat (`AvoirFournisseurActionsTest.php:338`), et la correction du taux de vente passe toujours par les attributs du produit (`FactureAchatActionsTest.php:1478-1508`). |
| M5 | `tests/TestCase.php` (`reglerLaSociete()`, `:210-217`), `database/factories/MagasinFactory.php` (`avecLaSociete()`, `:86-89`), la fabrique des sociétés | `societes.regime_tva` disparaît (O7) | Les aides écrivent l'historique daté ; les 20 fichiers qui règlent le régime par elles ne changent pas. `tests/Feature/Societe/ParametresFiscauxSocieteTest.php` (`:92`) attend en plus la date d'effet dans le JET 270. |
| M5 | `tests/Feature/Filament/FournisseurResourceTest.php`, `ConsultationViesTest.php` | Fiche fournisseur ; consultations des fournisseurs | Nouveaux champs ; tests ajoutés. |
| M7 | `tests/Feature/Filament/ValeurStockWidgetTest.php` (`test_la_valeur_du_stock_est_sa_quantite_fois_son_cmup`) | C7, si O1 est accepté | Valeur au coût. |
| M7 | 9 fichiers qui lisent `cmup_ht` (`StockLedgerServiceTest`, `FactureAchatActionsTest`, `AvoirFournisseurActionsTest`, `ProduitTest`, `AvoirFournisseurResourceTest`, `FactureAchatResourceTest`, `FournisseurProduitResourceTest`, `ProduitResourceTest`, `ValeurStockWidgetTest`), 2 qui lisent `cout_unitaire_ht`, et `CalculateurLigneTest.php:47-53` (`valeurAuCoutMoyen`) | Renommages et valeur au coût, **seulement si O1 est accepté** | Noms ; la valeur sans TVA. |
| Étape 2 | `GenererSoumissionEReportingActionTest`, `SoumettreEReportingPeriodiqueTest`, `tests/Unit/CalculateurPeriodeEReportingTest.php` | Supprimés avec leur fonctionnalité (complément 11) | — |
| Jamais | `RepartitionTvaParCumulTest`, `AvoirActionsTest` (messages `:302` et suivants gardés), `FranchiseEnBaseTest` (`:292`, `franchise_en_base` gardée), `CreerFactureActionTest`, `BordereauDetaxeTest`, `DevisConversionTest`, `StockLedgerServiceTest` (hors O1) | — | Verts à chaque lot, sans changement. |

---

## 5. Ergonomie

Ce chapitre fixe les écrans. Il reprend les propositions de `ergonomie-donnees.md` (sections 3, 4 et 8), corrigées, et les écrit ici en entier quand elles servent : ce document se lit seul.

### 5.1 Facture et devis de vente

**France d'abord.**
- Pour un particulier, un professionnel établi en France ou une vente au comptoir, le formulaire n'a **aucun champ de plus qu'aujourd'hui**.
- La liste « Nature de l'opération » disparaît. Elle est remplacée par une ligne repliée, « TVA française · vente en France », et par un lien discret, **« Expédier hors de France ou livrer à une autre adresse ? »**, présent pour tout client.
- Ce lien ouvre la même suite de questions que pour un client étranger : sortie des biens, adresse de livraison, date ; et, pour un service au lieu fixé par la loi, son lieu d'exécution. Aujourd'hui, un professionnel établi en France peut recevoir un export, puisque la liste propose les six natures à tout professionnel (`app/Domain/Facturation/Enums/NatureOperation.php:167-174`) et que la section « Livraison » s'ouvre alors (`app/Filament/Concerns/ChoisitLaNatureDeLOperation.php:75-106`) : le lien garde ce chemin.

**Préremplissage**, dans cet ordre. Chaque valeur préremplie affiche sa source en petit (« d'après la fiche du client », « comme la facture FAC-LYON1-2026-000123 du 02/10/2026 »), et la décision la garde avec sa source.
1. Le document lui-même : brouillon rouvert, ou devis converti (O8).
2. La fiche du client : l'adresse de livraison reprend son adresse, comme aujourd'hui (`app/Filament/Concerns/ChoisitLaNatureDeLOperation.php:295-314`).
3. **La dernière facture émise au même client, dans le magasin** : sa réponse à « Les marchandises quittent-elles la France ? » et sa destination sont **proposées, jamais tenues pour vraies**. Elles se confirment d'un clic dans la fenêtre « Émettre » (T3). Un client étranger régulier ne retape donc rien.
4. Le magasin, puis la société : territoire, taux par défaut, régime de TVA à la date du document.
5. La valeur sûre : vente en France.

La date de livraison se préremplit avec la date du jour, avec l'aide « à corriger si la livraison a lieu un autre jour ». Ne se reprennent **jamais** d'un autre document : un résultat VIES d'un autre jour, une preuve, un geste sur le régime (sauf du devis à sa facture, O8).

**Questions progressives.** Des boutons plutôt que des listes, une question à la fois ; elles disparaissent dès qu'elles ne s'appliquent plus.

| Déclencheur | Question ou message | Réponses | Effet |
|---|---|---|---|
| Des biens, **et** un client établi hors du territoire de TVA français, ou un n° de TVA d'un autre État, ou une adresse de livraison hors de France, ou le lien ouvert | « **Les marchandises quittent-elles la France ?** » | Non, remises ou livrées en France · Oui, nous les expédions (ou les faisons expédier) · Oui, le client les emporte ou les fait transporter | Sans réponse par défaut, sauf la proposition de la dernière facture ; exigée à l'émission pour ces clients (T3). |
| Réponse « Oui » | « **Où sont-elles livrées ?** » : adresse du client préremplie, pays dans une liste de pays nommés, territoire détecté affiché (« Canaries : hors TVA de l'Union », « Martinique : département d'outre-mer », « Monaco : TVA française ») | Adresse | Le moteur évalue export, livraison vers un DOM, livraison intracommunautaire, vente à distance, Monaco. |
| Livraison dans un autre État membre, client professionnel | « **Date de livraison ?** », préremplie avec la date du jour | Date | Condition de la livraison intracommunautaire. |
| « Le client les emporte », vers un autre État membre | Information : « Le client devra vous remettre une attestation de réception au plus tard le 10 du mois suivant. » | — | Preuve attendue (existant). |
| « Le client les emporte », hors de l'Union | Information : « Export emporté par le client : pas encore géré, la vente reste à la TVA française (détaxe possible pour un voyageur). » | — | Vente en France (I1, « ensuite » : lot M9). |
| Client professionnel de l'UE sans n° de TVA | « Ajoutez le n° de TVA du client pour vendre sans TVA française », bouton « Compléter la fiche » ; et l'avertissement de vente à distance possible (7.5) | — | Après l'enregistrement de la fiche : VIES, puis nouvelle évaluation. |
| VIES ne reconnaît pas le n° | « VIES ne reconnaît pas DE123456789 aujourd'hui (11/10 à 10 h 32). La vente reste à la TVA française. » · « Vérifier à nouveau » | — | Vente en France. |
| VIES ne répond pas | « VIES ne répond pas en ce moment (service de l'État membre indisponible). Réessayez : la facture peut être émise jusqu'au 15/11/2026. » · « Réessayer » | — | À l'émission : rien n'est émis, le brouillon reste (`tva.vies_injoignable`). |
| Un service au lieu fixé par la loi, **et** un fait international (client établi hors de France, n° de TVA étranger, livraison hors de France, lien ouvert) | « **Ce service est-il exécuté en France ?** (travaux sur un immeuble, événement, location courte de véhicule, restauration…) » | Oui, en France · Non, hors de France | Non : bloquant, cas non géré (7.3). Sans réponse : exigée à l'émission. |
| Services (lieu non fixé par la loi) à un professionnel établi hors de France | Aucune question : le lieu se déduit du client et du service | — | Prestation autoliquidée ou hors Union **proposée** (bouton « Appliquer »). |
| Particulier, ou client non assujetti, livré dans un autre État membre | État de la vente à distance : « seuil non atteint », « seuil proche » ou « seuil atteint » ; le montant ne se voit que du propriétaire et des administrateurs autorisés (3.4) | — | Vente en France sous le seuil ; refus au-delà (T8). |
| Particulier dont l'adresse est hors de l'Union, avec un service | Avertissement : « Certains services rendus à un particulier établi hors de l'Union ne sont pas soumis à la TVA française (art. 259 B du CGI) : vérifiez avant d'émettre. » | — | Vente en France. |
| Dans une nature d'exonération ou d'autoliquidation, une ligne qu'elle n'admet pas ; une ligne hors champ avec une autre | « Cette facture ne porte qu'un régime : déplacez ces lignes vers une nouvelle facture. » · bouton « Déplacer ces lignes vers une nouvelle facture » | — | Bloquant à l'émission (T6). |
| Produit soumis à accises, ou moyen de transport neuf, dans une vente hors de France | « Vente hors de France de ce produit : non gérée automatiquement (accises, moyen de transport neuf). » | — | Nature refusée ; la vente en France reste possible. |
| Produit « carte cadeau ou bon d'achat » | « La vente des cartes cadeaux et des bons d'achat n'est pas encore gérée. » | — | Bloquant (T9). |

**Le panneau « Régime de TVA de cette vente ».** Sur grand écran, sous le client, dans une colonne latérale ; sur téléphone, à pleine largeur après le client. La fiche d'un document émis montre la même présentation, relue de la décision figée. Un mot accompagne toujours la couleur.

| État | Présentation |
|---|---|
| **France** | Une ligne repliée : « TVA française · vente en France ». |
| **Appliqué d'office** | Encadré vert : « Livraison intracommunautaire appliquée d'après vos réponses : le client paie 100,00 € au lieu de 120,00 € », lien « Rester à la TVA française », détail ouvert. |
| **Proposé** | Encadré bleu : « Prestation à une entreprise de l'Union possible : le client paierait 100,00 € au lieu de 120,00 € » · bouton « Appliquer » · lien « Rester à la TVA française ». |
| **Appliqué** | Encadré vert, après un geste « Appliquer ». |
| **Incomplet** | Encadré orange : « Livraison intracommunautaire possible. Il manque : un n° de TVA valide sur VIES aujourd'hui. Tant qu'il manque, la vente reste à la TVA française. » Chaque manque est un lien qui place le curseur sur le champ. |
| **Bloquant** | Encadré rouge, repris dans la fenêtre « Émettre » : « Impossible d'émettre : … », toujours la liste complète. |

Le total « au lieu de » vient du moteur, rappelé avec la nature candidate (P9) : aucun calcul dans l'écran.

**Contenu du détail (exemple de Müller GmbH).**

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

**Table des libellés** (P13 : aucun code à l'écran). Le texte vient de la table datée des règles, avec la décision.

| Élément | Libellé à l'écran |
|---|---|
| Vente en France | « TVA française » · « Le client paie la TVA comprise dans vos prix. » |
| Export | « Export hors de l'Union européenne : sans TVA française » · « Le client paie votre prix sans la TVA française (120,00 € → 100,00 €). À conserver : la déclaration d'export (MRN) ou le CN23 du colis. » ; pour le Royaume-Uni, la Suisse ou la Norvège : « Le pays d'arrivée peut percevoir sa propre TVA. » |
| Livraison vers un DOM | « Expédition vers la Martinique (département d'outre-mer) : sans TVA française » · « La TVA locale et l'octroi de mer sont payés à l'arrivée. » |
| Livraison intracommunautaire | Exemple ci-dessus · « Le client déclare la TVA de son pays. » |
| Prestation à une entreprise de l'Union | « Prestation à une entreprise de l'Union : TVA du pays du client » · « Le client déclare la TVA de son pays. Mention : « Autoliquidation ». » |
| Prestation hors Union | « Prestation à une entreprise hors Union : hors TVA française » · « Cette facture ne doit contenir que ces services. » |
| Franchise de la société | « Votre société est en franchise de TVA » · « Aucune TVA : « TVA non applicable, art. 293 B du CGI ». » |
| Transmission | « SUPER PDP la transmet comme facture électronique » ; « SUPER PDP la déclare pour vous (e-reporting) » ; « Hors plateforme : client public, dépôt sur Chorus Pro » ; « Pas encore prise en charge pour ce régime : la facture reste hors plateforme » ; « Aucune : magasin de démonstration ». |
| VIES | « N° valide sur VIES le 14/10/2026 à 10 h 32 (preuve VIES n° … conservée) » : le n° de preuve donné par VIES, jamais l'identifiant interne de la consultation. |
| Achats | « TVA déductible », jamais « récupérable », comme sur la déclaration de TVA. |

**Jamais de bascule silencieuse.**
- Une nature appliquée d'office affiche le nouveau total et le lien « Rester à la TVA française ». Ce lien enregistre le geste « TVA française gardée », avec son auteur et l'instant (colonnes `nature_confirmee`, `nature_confirmee_par`, `nature_confirmee_le`, 3.2).
- Le geste s'efface quand un fait déclencheur change : client, sortie des biens, livraison, composition en biens et services, lieu des services (`NoteSaNatureDOperation` réécrit, 4.1).
- Le menu « Changer de régime » ne liste que les natures permises au client et à la société, chacune avec son état : « possible », « il manque : … » ou « impossible : … ». Choisir une nature ouvre les questions qui lui manquent. Une nature dont une condition manque ne s'applique jamais.

**La fenêtre « Émettre »** rassemble, avant le numéro, tout ce qui reste à trancher :
1. le régime et le total ;
2. une nature encore **proposée** : « Appliquer et émettre » ou « Émettre à la TVA française », choix enregistré comme un geste (`tva.choix_du_regime_requis` tant qu'il manque, T2) ;
3. une réponse proposée d'après la dernière facture et pas encore confirmée : « Confirmer » ;
4. un **calcul du brouillon changé** depuis son enregistrement (type de client, régime de la société, taux de la fiche, nature, montants) : l'avant et l'après, lignes et total, avec « Mettre à jour et émettre ». Le bouton enregistre le brouillon recalculé, puis émet ; un refus à l'émission laisse le brouillon à jour. Aujourd'hui, le seul remède est de rouvrir le brouillon et de l'enregistrer de nouveau (`app/Domain/Facturation/Services/ContexteDeVente.php:146`, `:164`), alors que le taux de vente d'un produit se corrige maintenant depuis une ligne d'achat (complément 2) ;
5. les avertissements : vente à distance, preuves à fournir, facture au plus tard le 15 du mois suivant, relevé du mois.

Tous les refus s'affichent ensemble (P8), avant l'avertissement de vente sans stock (R1, 2.8).

**Brouillon rouvert ou devis converti** : le moteur réévalue. Une notification persistante dit ce qui a changé, sur le modèle actuel (`app/Filament/Concerns/ChoisitLaNatureDeLOperation.php:142-165`).

**Fiche d'un document émis.** Une section « Régime de TVA », relue de la décision figée : régime, conditions telles qu'à l'émission, consultation VIES retenue (n° de preuve et heure), geste (qui, quand), version des règles, avertissements, preuves attendues. Elle remplace, pour la partie fiscale, la section « Transmission électronique » actuelle, qui se fonde sur le type de client (`app/Filament/App/Resources/FactureResource.php:279-294`) ; le statut de transmission reste affiché à côté.

### 5.2 Caisse

- **Ticket ordinaire : aucune question, aucun panneau.** La caisse appelle le moteur avec « remis en France » ; la décision est signée comme pour une facture.
- Client professionnel établi hors de France : une ligne d'information, « Vente en France : marchandises remises en magasin. Pour une expédition hors de France, faites une facture. »
- Services au comptoir à une entreprise établie hors de France : un avertissement, « Pour des services à une entreprise étrangère, la TVA française ne s'applique pas toujours : faites une facture. »
- Un produit « carte cadeau ou bon d'achat » ne s'ajoute pas au panier, avec le message de T9 : le refus n'arrive jamais à l'encaissement.
- Un produit à 0 % n'existe pas pour une société redevable si T5 = a : la fiche le refuse, la caisse ne le rencontre jamais.
- La case « Le client demande une facture » (complément 13) arrive avec le lot C-b.
- La caisse ne propose jamais d'exonération. Un particulier de l'UE qui emporte ses achats paie la TVA française, sans question (recherche N1).

### 5.3 Devis et avoirs

- **Devis** : même panneau et mêmes questions, rien n'est figé. À l'envoi, un avertissement (jamais un refus) liste les conditions non réunies. Le PDF rappelle le moteur avec les faits du devis et les règles du jour de son émission (3.2). La facture issue d'un devis suit O8.
- **Avoir** : aucune question. Chaque ligne hérite de la nature de sa ligne de facture, donc de sa catégorie et de son VATEX (2.5).
  - L'écran d'un avoir sur plusieurs factures regroupe les factures compatibles (type de client, franchise, nature, circuit) et grise les autres dès qu'une première est choisie. Aujourd'hui, il ne sépare que les tickets des factures (`app/Filament/App/Resources/AvoirResource.php:107-118`) et l'action refuse le reste à l'enregistrement.
  - Les refus gardent leurs messages actuels (`app/Domain/Facturation/Actions/CreerAvoirAction.php:104`, `:110`, `:116`). Un seul s'ajoute : « Un avoir ne mélange pas des factures transmises par des circuits différents : faites deux avoirs. »

### 5.4 Facture d'achat

**Fiche fournisseur.**
- Le pays se choisit dans une liste de pays nommés, contrôlée comme celle d'un client.
- **« Établi en France » suit la règle du client** (`app/Filament/App/Resources/ClientResource.php:118`, `:140`, `:165-171` ; `app/Domain/Contacts/Models/Client.php:124-147`) :
  - pays en France : établi, d'office ;
  - Monaco : « établi à Monaco », traité à part (6.2) ;
  - pays étranger : **non par défaut**, avec la case « Ce fournisseur facture depuis un établissement en France (un n° de TVA FR obtenu par un représentant fiscal ne suffit pas) », **visible à côté du pays**, hors de toute section repliée ;
  - en base, aucune valeur par défaut fixe : le modèle la calcule depuis le pays (3.2). Une création par l'API sans cette valeur la déduit du pays.
- Section repliée « Fiscalité » : « Ce fournisseur ne facture pas de TVA » (franchise en France ou dans son État) ; n° de TVA avec son état VIES, vérifié à l'enregistrement.
- **L'avertissement « fournisseur établi en France sans SIRET » s'affiche sur la fiche**, à l'enregistrement (« Sans SIRET, SUPER PDP ne pourra pas reconnaître ce fournisseur »), jamais à chaque facture.

**En-tête de la facture d'achat.**

| Déclencheur | Question | Réponses | Effet |
|---|---|---|---|
| **Au moins une ligne de produit, quel que soit le fournisseur** | « **Origine des marchandises** » | France ou Monaco · Autre pays de l'Union · Hors de l'Union ; puis le pays (et le code postal pour un territoire exclu) | Préremplie « France » pour un fournisseur établi en France, sans clic, source affichée ; sinon d'après la dernière facture validée du même fournisseur dans le magasin ; sinon son pays, « proposé, à confirmer ». Confirmation exigée seulement pour un fournisseur non établi, à sa première facture et à chaque changement (T3). |
| Origine hors de l'Union, dans un DOM, une collectivité d'outre-mer ou un territoire exclu, **quel que soit le fournisseur** | « **Qui a dédouané les marchandises ?** » | Nous, ou notre transitaire · Le fournisseur (livrées dédouanées) | Importation, ou livraison en France par le fournisseur. |
| Dédouanées par nous | « **Déclaration en douane** » : référence (MRN), date du bon à enlever, valeur en douane, droits, autres taxes, frais jusqu'au premier lieu de destination, TVA par taux ; aide « Vous pourrez la compléter plus tard » | Saisie | Coût du stock et TVA d'importation (T12). |
| Fournisseur non établi en France | « **La facture porte-t-elle de la TVA ?** » | Non (cas normal) · Oui, montant | Oui : T15. |
| Fournisseur établi en France, **ligne saisie à 0 %** | « **Pourquoi cette ligne n'a pas de TVA ?** » (question A-Q5 de l'analyse ergonomique) | Le fournisseur est en franchise (mention 293 B) [« Mémoriser pour ce fournisseur »] · La facture porte « Autoliquidation » · Frais liés à une importation (art. 262 II 14°, à partir du lot M7) · Erreur de saisie | Franchise : aucune TVA, la case de la fiche se coche si demandé. « Autoliquidation » : cas exclu, saisie manuelle (T11). Importation : contrôle de la déclaration rattachée (6.2). Erreur : le taux de vente revient. Sans réponse : bloquant à la validation (`tva.taux_zero_sans_raison`). |
| Ligne de service ou de frais d'un fournisseur non établi | Case « Concerne un immeuble, un transport de personnes, un repas, une location courte de véhicule ou un événement » | Cochée ou non | Cochée : cas exclu, saisie manuelle (T11). |
| Devise autre que l'euro (si T16 = a) | « **Taux de change appliqué** » | Saisie | Montants en euros. |
| Toujours, facultatif | « Total TTC de la facture du fournisseur (contrôle) » | Montant | Contrôle d'écart. |

Le lieu d'arrivée n'est jamais demandé : c'est le magasin (6.1).

**Lignes.**
- « Ajouter un produit » garde la fenêtre actuelle, coupée en deux blocs :
  - **« Vente en magasin »** : prix de vente TTC et taux de vente de la fiche, corrigés immédiatement (complément 2), avec le JET 270 ;
  - **« Cette facture »** : quantité, PU HT, remise, **taux facturé**. Pour un nouveau produit, le taux facturé **suit le taux de vente tant qu'on ne le modifie pas** : une seule saisie, comme aujourd'hui (`app/Filament/Concerns/AjouteDesLignesDAchat.php:484-488`). Il est caché, et vaut 0, quand la nature ne porte pas de TVA sur la facture (sauf réponse « Oui » à T15). Le **taux dû en France** n'apparaît qu'en autoliquidation, proposé depuis le taux de vente, avec l'avertissement de taux inhabituel (X8).
  - Le taux facturé ne réécrit **jamais** le taux de vente (C1).
- « Ajouter des frais ou un service » (nouveau) : description, type (frais d'acheminement · autre service), montant HT, taux selon la nature. Au lot M7, un champ « Rattacher à » (6.5).

**Totaux.** Ils remplacent « Total de la facture d'achat (à payer au fournisseur) » (`app/Filament/App/Resources/FactureAchatResource.php:98`), qui affiche aujourd'hui une seule ligne « Total HT · TVA · Total TTC » (`app/Filament/Concerns/SaisitLesPrixDeLigne.php:30-46`).
- **Achat ordinaire en France** (ni autoliquidation, ni droits, ni frais rattachés, ni TVA étrangère) : la ligne d'aujourd'hui reste, avec des mots plus précis : « Total HT · TVA facturée, déductible (non déductible en franchise) · Total TTC à payer au fournisseur ». Le coût d'entrée ne s'affiche à part que s'il diffère du HT (escompte, frais, franchise).
- **Sinon**, le détail :

```
Total HT des marchandises et frais ....................... 840,00 €
TVA facturée par le fournisseur ..........................   0,00 €
À payer au fournisseur ................................... 840,00 €
TVA que vous déclarez vous-même (autoliquidation, 20 %) .. 168,00 €
TVA déductible sur la même déclaration ................... 168,00 €
TVA à payer à l'État pour cet achat ......................   0,00 €
Coût d'entrée en stock ....................... 840,00 € (8,40 € l'article)
```

- Importation : « TVA d'importation (déclaration en douane) … 136,80 € », et « À payer à la douane ou au transitaire : 24,00 € de droits ».
- TVA étrangère facturée (si T15 = b) : « TVA allemande facturée, à faire rectifier ou rembourser … 152,00 € » ; « À payer au fournisseur » la comprend.
- Société en franchise : « TVA déductible : 0,00 € (franchise) » ; la TVA entre dans le coût, et « TVA à payer à l'État » montre la TVA autoliquidée qu'elle doit.

« Le montant à payer » de la demande est pris dans ses deux sens : au fournisseur, et à l'État (ou à la douane).

**Panneau d'un achat (exemple de Spielwaren AG).**

```
Régime de TVA de cet achat
Achat dans l'Union européenne (acquisition intracommunautaire)

Qui paie la TVA     vous la déclarez à la place du fournisseur, et la déduisez
                    sur la même déclaration : rien à payer en plus
Coût du stock       hors TVA, port compris (40,00 € répartis sur les articles)

Pourquoi
  Fournisseur établi en Allemagne, n° DE… valide sur VIES le 02/10/2026 ......... oui
  Marchandises expédiées d'Allemagne (comme sa facture du 02/10/2026) ............ oui
  Pays de départ identique au pays du n° de TVA du fournisseur ................... oui
  Votre société a un n° de TVA français ......................................... oui
  Facture sans TVA allemande ...................................................... oui

À vérifier          la facture doit porter les deux n° de TVA et une mention d'exonération
Transmission        SUPER PDP la déclarera pour vous (e-reporting « achat », étape 2)
```

**Contrôles d'écart et de mentions.**
- L'écart entre la TVA calculée et celle de la facture ne se signale que pour une facture reçue par SUPER PDP ou par l'API, qui portent leurs montants, ou quand le total de contrôle est saisi. Une saisie manuelle ne demande rien de plus.
- Les mentions attendues (« Autoliquidation », deux n° de TVA, mention d'exonération, 293 B) s'affichent comme un rappel dans le panneau, sans case à cocher. Leur absence ne devient un avertissement que pour une facture électronique reçue (étape 2), dont le contenu est lisible.

**Listes de suivi**, dans le menu Achats, avec un badge et une notification aux membres autorisés à valider :
- « Dédouanement à compléter » (lot M7, T12) ;
- « TVA étrangère à régulariser » (lot M6, si T15 = b).

### 5.5 API d'achat

Il n'existe aucune route HTTP : le point d'entrée est l'action du domaine `CreerFactureAchatAction::handle()` (`app/Domain/Achats/Actions/CreerFactureAchatAction.php:27`), avec la source « API ». Elle servira à la réception SUPER PDP (étape 2) et à l'API v1 (étape 10). Mêmes règles qu'à l'écran :
1. La charge utile peut porter `pays_expedition`, `code_postal_expedition`, `dedouane_par`, la déclaration en douane (`douane_reference`, `douane_date_bae`, `douane_valeur`, `douane_droits`, `douane_autres_taxes`, `douane_frais_accessoires`, `douane_tva`), `total_de_controle`, et pour chaque ligne `type_ligne`, `taux_tva` (taux facturé), `taux_tva_france`, `raison_taux_zero` et `lieu_fixe_par_la_loi`. Au lot M7 : `facture_achat_rattachee_id`. Si T16 = a : `taux_change`.
2. Préremplissage identique ; la décision donne la source de chaque fait.
3. **Un brouillon n'est jamais refusé** pour un fait fiscal manquant : il est créé avec sa décision provisoire et ses manquements, comme une facture reçue par SUPER PDP, qui s'importe en brouillon (CLAUDE.md:174).
4. **La validation est refusée avec tous les manquements**, chacun avec son code, son champ et son message (HTTP 422 à l'étape 10). Aucun stock n'entre, aucun numéro n'est consommé.
5. Facture électronique reçue (étape 2) : sa catégorie, ses n° de TVA et ses mentions préremplissent les faits. Une contradiction (fournisseur établi en France et catégorie K, n° de TVA belge et départ d'Allemagne) passe en « à vérifier », jamais en choix silencieux.
6. La TVA donnée par la source sert au rapprochement (T13).
7. Jeton unique sur la validation (R3, déjà décidé).
8. Une facture reçue par l'API n'est jamais modifiable (`app/Domain/Achats/Models/FactureAchat.php:143-153`). Compléter sa déclaration en douane ou lui rattacher des frais ne modifie pas son contenu reçu : c'est permis, sauf après un avoir fournisseur (6.4, T12).

### 5.6 Bloquant ou avertissement

**Ventes**

| Contrôle | Niveau | Moment | Code |
|---|---|---|---|
| Conditions d'une nature appliquée, d'office ou par un geste | Bloquant, avec « Rester à la TVA française » | Émission | message actuel des conditions (2.9) |
| Nature encore proposée | Bloquant : la fenêtre « Émettre » impose le choix | Émission | `tva.choix_du_regime_requis` |
| N° de TVA de la société pour une nature autre que la vente en France | Bloquant | Émission | `tva.numero_tva_societe_absent` |
| Identité légale de la société et du magasin ; SIREN d'un client établi en France ; nom et adresse du client sur une facture (O5) | Bloquant | Émission, annoncé dès le choix du client | `identite.incomplete`, `client.siren_absent`, `client.adresse_absente` |
| Calcul du brouillon changé depuis son enregistrement | Bloquant, avec l'avant, l'après et « Mettre à jour et émettre » | Émission | `tva.calcul_du_brouillon_change` |
| Sortie des biens sans réponse, pour un client qui la demande | Bloquant | Émission | `tva.sortie_des_biens_inconnue` |
| Lieu d'un service au lieu fixé par la loi : sans réponse, ou hors de France | Bloquant | Émission | `tva.lieu_du_service_a_preciser`, `tva.lieu_du_service_hors_de_france` |
| **Dans une nature d'exonération ou d'autoliquidation, une ligne que cette nature n'admet pas ; une ligne hors champ avec une ligne d'une autre catégorie.** Une vente en France qui mêle un produit et un service s'émet normalement. | Bloquant | Émission, affiché pendant la saisie | `tva.une_nature_par_document`, `tva.document_separe_requis` |
| Accises ou moyen de transport neuf dans une nature hors de France | Bloquant pour cette nature | Émission | `tva.cas_exclu` |
| Carte cadeau ou bon d'achat (T9 = a) | Bloquant | Ajout au panier ; émission | `tva.bon_d_achat_non_gere` |
| Ligne à 0 % en France, société redevable (T5 = a) | Bloquant | Enregistrement de la fiche ; émission en filet de sécurité | `tva.taux_zero_en_france` |
| Vente à distance qui ferait dépasser le seuil, année précédente au-delà du seuil, ou société inscrite à l'OSS (T8 = a) | Bloquant | Émission | `tva.seuil_ventes_a_distance_depasse`, `tva.oss_non_gere` |
| Magasin des DOM qui expédie hors de son département ; prestation à un professionnel établi dans un DOM (O6) | Bloquant | Émission | `tva.depart_hors_du_dom_non_gere`, `tva.prestation_vers_un_dom_non_geree` |
| VIES injoignable quand la nature l'exige | Bloquant, brouillon gardé, deux messages distincts (5.1) | Émission | `tva.vies_injoignable` |
| Exonération possible, TVA française gardée | Information, tracée | Saisie | — |
| Vente à distance sous le seuil ; seuil proche | Avertissement | Saisie et émission | `tva.vente_a_distance` |
| Professionnel de l'UE sans n° de TVA, biens expédiés dans son pays | Avertissement : « s'il n'est pas assujetti ou relève du régime dérogatoire de son pays, c'est une vente à distance » | Saisie | `tva.vente_a_distance_possible` |
| Taux inhabituel pour le territoire (X8) | Avertissement | Saisie | `tva.taux_inhabituel` |
| Nom ou adresse VIES très différents de la fiche | Avertissement | Saisie | `tva.vies_nom_different` |
| Service à un particulier dont l'adresse est hors de l'Union (art. 259 B) | Avertissement | Saisie | `tva.service_hors_ue_a_verifier` |
| Livraison antérieure à l'émission, sans vérification VIES à sa date (O3) | Avertissement | Émission | `tva.vies_apres_la_livraison` |
| Preuves à fournir ; facture au plus tard le 15 du mois suivant ; relevé du mois | Avertissement | Après l'émission | — |
| Devis dont une condition manque | Avertissement | Envoi | — |

**Achats**

| Contrôle | Niveau | Moment | Code |
|---|---|---|---|
| Origine non confirmée, pour un fournisseur non établi | Bloquant | Validation | `tva.origine_non_confirmee` |
| Dédouanement inconnu pour une origine hors de l'Union | Bloquant | Validation | `tva.dedouanement_inconnu` |
| Faits contradictoires (fournisseur établi en France, marchandises venues d'un autre État membre) | Bloquant, sauf saisie manuelle | Validation | `tva.faits_contradictoires` |
| Opération triangulaire possible (pays de départ différent du pays du n° de TVA du fournisseur, ou facture « art. 141 ») | Bloquant, sauf saisie manuelle | Validation | `tva.operation_triangulaire_possible` |
| TVA étrangère facturée par un fournisseur de l'UE, société redevable | Bloquant si T15 = a ; avertissement fort et liste « à régulariser » si T15 = b | Validation | `tva.tva_etrangere_facturee` |
| Autoliquidation ou acquisition sans n° de TVA de la société | Bloquant | Validation | `tva.numero_tva_societe_absent` |
| Devise sans taux de change (T16 = a) | Bloquant | Validation | `tva.devise_sans_taux` |
| N° et date de la facture d'un fournisseur non établi (exigés par l'e-reporting) | Bloquant | Validation | `achat.numero_fournisseur_absent`, `achat.date_fournisseur_absente` |
| Ligne à 0 % d'un fournisseur établi en France, sans réponse à « Pourquoi ? » | Bloquant | Validation | `tva.taux_zero_sans_raison` |
| Cas exclu, sauf saisie manuelle autorisée (T11) | Bloquant | Validation | `tva.cas_exclu` |
| Achat hors de France d'une société en franchise (T10 = a) | Bloquant, sauf les services | Validation | `tva.achat_etranger_en_franchise_non_gere` |
| Création de produits sans le droit de créer des produits | Bloquant | Validation | `achat.droit_de_creer_des_produits` |
| Coût d'une facture qui a déjà un avoir fournisseur émis | Bloquant | Compléter le dédouanement, rattacher des frais | `tva.cout_fige_par_un_avoir_fournisseur` |
| Déclaration en douane à compléter (T12 = a) | Avertissement, liste « à compléter » | Validation et après | `tva.dedouanement_a_completer` |
| Écart entre la TVA d'importation saisie et celle attendue (base de l'art. 292) | Avertissement | Saisie | `tva.ecart_tva_importation` |
| Ligne de frais à 0 % « liée à une importation » sans frais accessoires dans la déclaration rattachée | Avertissement | Saisie | `tva.frais_hors_base_importation` |
| VIES du fournisseur invalide ou non vérifié | Avertissement | Saisie | `tva.vies_fournisseur_invalide` |
| Écart avec la facture du fournisseur (facture reçue par SUPER PDP ou l'API, ou total de contrôle saisi) | Avertissement | Saisie | `tva.ecart_avec_la_facture` |
| Mention attendue absente (facture électronique reçue, étape 2) | Avertissement | Réception | `tva.mention_attendue_absente` |
| Taux facturé ou taux dû en France inhabituel (X8) | Avertissement | Saisie | `tva.taux_inhabituel` |
| Fournisseur établi en France sans SIRET | Avertissement | Enregistrement de la fiche du fournisseur | — |

### 5.7 Autorisations

Les noms suivent le registre des autorisations tel que le lot A4 de la chaîne société le livre ; ceux qui existent aujourd'hui sont dans `app/Domain/Authorization/PermissionRegistry.php`.

| Geste | Autorisation |
|---|---|
| Répondre aux questions, appliquer une proposition, garder la TVA française | Créer la facture ou le devis (`facture.create`, `devis.create`) : aucun droit spécial, puisque le moteur contrôle les conditions. |
| Changer le prix ou la remise d'une ligne | `vente.remises_et_prix` (R2, lot A6). **`DerogationsDePrix` compare le PU TTC, la remise, et le prix et le taux de référence, c'est-à-dire le taux compris dans le prix (`taux_tva_du_prix`, `database/migrations/2026_10_03_100004_create_lignes_facture_table.php:41`), jamais le taux que décide le moteur (`taux_tva`, `:40`).** Appliquer une livraison intracommunautaire ou une autoliquidation n'est donc jamais une dérogation, même sur une ligne remisée par un responsable (`societe/conception-v2-finale.md` l.956 compte aujourd'hui « le taux » parmi les dérogations). |
| Forcer une exonération ou une autoliquidation dont une condition manque | **Personne.** |
| Émettre | `facture.emettre` (existant). |
| Voir les montants du compteur des ventes à distance et le relevé de la société | `societe.parametres.gerer` (propriétaire, administrateurs autorisés). Un vendeur ne voit que l'état du seuil. |
| Voir le relevé du mois du magasin | Voir toutes les factures du magasin (`facture.view_toutes`, que le comptable a). |
| Saisir l'origine et le dédouanement d'un achat | Créer une facture d'achat (`facture_achat.creer`). |
| Valider, modifier après validation, compléter le dédouanement, rattacher des frais | Valider les factures d'achat (`facture_achat.valider`, C2). |
| **Saisir à la main la nature et la TVA d'un achat** | **Autorisation dédiée `facture_achat.saisie_manuelle_tva`**, cochée pour personne dans les modèles de rôles (le propriétaire a tout, de droit), motif obligatoire, inscrite au journal d'audit (T11). Le modèle Responsable, qui a toutes les autorisations de magasin sauf deux (`societe/conception-v2-finale.md` l.599), ne la reçoit pas. |
| Régime de TVA de la société et sa date d'effet, option pour les débits, n° de TVA, valeurs de départ des ventes à distance, inscription à l'OSS | Paramètres de la société (`societe.parametres.gerer`), JET 270 dans chaque magasin (existant). |
| Particularité fiscale d'un produit, taux de vente | Modifier un produit (`produit.update`). |

---

## 6. Achats en détail

Base réglementaire : `reglementation.md` 3 (cas A1 à A18), dont la certitude est donnée cas par cas, et la vérification de 1.4. Le moteur n'automatise que les cas sûrs ; les autres passent en saisie manuelle encadrée (T11) ou attendent une réponse.

### 6.1 Faits demandés et leur origine

| Fait | Source par défaut | Demandé quand | Absent |
|---|---|---|---|
| Établissement du fournisseur (France, Monaco, ailleurs) | Fiche : calculé depuis le pays ; case « facture depuis un établissement en France » pour un pays étranger | Une fois, sur la fiche | — |
| « Ne facture pas de TVA », n° de TVA et état VIES | Fiche | Une fois, sur la fiche | Selon la nature |
| **Origine réelle des marchandises** (France ou Monaco · autre pays de l'Union · hors de l'Union ; pays ; code postal pour un territoire exclu) | « France » pour un fournisseur établi en France ; sinon la dernière facture validée du même fournisseur, dans le magasin ; sinon son pays, proposé à confirmer | **Toujours dès qu'il y a une ligne de produit** | Bloquant pour un fournisseur non établi tant qu'elle n'est pas confirmée |
| **Lieu d'arrivée** | **Dérivé : le magasin.** Le stock est celui du magasin et aucun transfert n'existe entre magasins (CLAUDE.md:172) | **Jamais** | — Une livraison directe chez un client (le magasin ne reçoit rien) est hors périmètre : saisie manuelle (T11) |
| Dédouanement (nous ou notre transitaire · le fournisseur) | Dernière facture validée du même fournisseur | Origine hors de l'Union, dans un DOM, une collectivité d'outre-mer ou un territoire exclu, quel que soit le fournisseur | Bloquant |
| Déclaration en douane (référence, date du bon à enlever, valeur, droits, autres taxes, frais jusqu'au premier lieu de destination, TVA par taux) | Aucune ; plus tard, le relevé du transitaire | Dédouanement par nous | Avertissement et liste « Dédouanement à compléter » (T12) |
| TVA portée par la facture d'un fournisseur non établi | « Non » | Fournisseur non établi | T15 |
| Type de chaque ligne (produit · frais d'acheminement · autre service) | Le bouton utilisé | Toujours | — |
| Taux facturé | Taux de vente du produit, lié tant qu'on ne le change pas (achat en France) ; 0 sinon | Achat en France | — |
| Raison d'un taux zéro (`franchise`, `autoliquidation`, `importation`) | Aucune | Ligne à 0 % d'un fournisseur établi en France | Bloquant (`tva.taux_zero_sans_raison`) |
| Taux dû en France | Taux de vente du produit ; pour un service, son taux de référence ou le taux normal du territoire du magasin | Natures autoliquidées | Avertissement s'il est inhabituel (X8) |
| Lieu fixé par la loi (immeuble, transport de personnes, repas, location courte de véhicule, événement) | Non | Ligne de service ou de frais d'un fournisseur non établi | Cochée : cas exclu (T11) |
| N° et date de la facture du fournisseur | Saisie ; plus tard la facture électronique reçue | Toujours visibles (existant) | Bloquant pour un fournisseur non établi (l'e-reporting les exige) |
| Total TTC de la facture du fournisseur (contrôle) | Aucune ; la source pour une facture reçue | Facultatif | — |
| Devise et taux de change | Euro | Seulement si T16 = a | Bloquant sans taux |
| Date de réception d'une acquisition intracommunautaire ; date de réalisation d'un service autoliquidé | Date de la facture | **Étape 5** (exigibilité, 8.2) : rien n'est demandé avant | — |

### 6.2 Nature de chaque ligne

| Ligne | Fournisseur | Origine et dédouanement | Société | Nature | Cas |
|---|---|---|---|---|---|
| Produit | Établi en France, facture avec TVA | France | Toutes | **Achat en France** | A1 |
| Produit | Établi en France, ligne à 0 % | France | Toutes | Question « Pourquoi cette ligne n'a pas de TVA ? » : franchise → achat à un fournisseur en franchise ; « Autoliquidation » → cas exclu (T11) ; erreur → taux de vente repris | A2, A3 |
| Produit | Établi en France, « ne facture pas de TVA » | France | Toutes | **Achat à un fournisseur en franchise** | A2 |
| Produit | Établi en France | Autre État membre | Toutes | **Cas à vérifier** (faits contradictoires), saisie manuelle | — |
| Produit | **Toutes** (établi en France ou non) | Hors de l'Union, DOM, collectivité d'outre-mer ou territoire exclu, **dédouané par nous** | Redevable | **Importation** | A14 |
| Produit | Établi à Monaco (n° de TVA FR) | Monaco ou France | Toutes | **Achat auprès de Monaco** : TVA française facturée. Circuit de l'art. 290 I 4° a si l'origine est Monaco ; origine France : circuit à confirmer (annexe B, point 5) | A4 |
| Produit | Non établi, n° de TVA d'un autre État membre **dont le préfixe est le pays d'origine** (EL pour la Grèce, XI pour l'Irlande du Nord) | Autre État membre (ou Irlande du Nord) | Redevable, avec un n° de TVA FR | **Acquisition intracommunautaire** | A5 |
| Produit | Non établi, n° de TVA d'un autre État membre **dont le préfixe n'est pas le pays d'origine**, ou facture portant « art. 141 » ou « opération triangulaire » | Autre État membre | Toutes | **Cas à vérifier : opération triangulaire possible**, saisie manuelle (T11) ; circuit de l'art. 290 I 4° b à confirmer (annexe B, point 15) | — |
| Produit | Non établi, n° de TVA d'un autre État membre | Autre État membre | En franchise | Selon T10 (refus recommandé) | A6, A7 |
| Produit | Non établi, sans n° de TVA de l'Union, ou « ne facture pas de TVA » (franchise de son État) | Autre État membre | Toutes | Saisie manuelle, traitement à confirmer (annexe B, point 4) | A9 |
| Produit | Non établi | Autre État membre, **TVA étrangère facturée** | Redevable | Selon T15 | A10 |
| Produit | Non établi | **France** (stock déjà en France, ou importé puis livré en l'état par le fournisseur) | Redevable | **Livraison en France par un non-établi** : autoliquidation | A8 |
| Produit | Non établi | Hors de l'Union, **dédouané par le fournisseur** | Redevable | **Livraison en France par un non-établi** | A8 |
| Produit | Toutes | Hors de l'Union, dédouanement inconnu | Toutes | **Bloquant** | — |
| Produit | Toutes | Hors de l'Union | En franchise | Selon T10 (refus recommandé) | A15 |
| Produit soumis à accises, ou moyen de transport neuf | Non établi | Hors de France | Toutes | Cas exclu, saisie manuelle | — |
| Frais d'acheminement facturés par le fournisseur des marchandises | — | — | — | **Même nature que les marchandises** (l'accessoire suit le principal) | `reglementation.md` 2.2 |
| Frais d'acheminement d'un tiers établi en France, ligne à 0 %, raison « importation », rattachés à une importation (lot M7) | — | — | Toutes | **Achat en France exonéré** (art. 262 II 14°) ; avertissement si la déclaration rattachée ne compte aucun frais accessoire | 1.4, point 13 |
| Autre service, ou frais d'un tiers | Établi en France | — | Toutes | Achat en France (ou fournisseur en franchise) | A1, A17 |
| Autre service, ou frais d'un tiers | Non établi, lieu non fixé par la loi | — | Redevable | **Services d'un prestataire non établi** : autoliquidation | A11, A12, A17 |
| Autre service, ou frais d'un tiers | Non établi, lieu non fixé par la loi | — | En franchise | Selon T10 (autoliquidation sans déduction recommandée) | A18 |
| Autre service | Non établi, **lieu fixé par la loi** | — | Toutes | Cas exclu, saisie manuelle | A13 |
| Toute ligne | — | Achat d'un magasin des DOM venu d'ailleurs que de son département | — | Cas exclu (octroi de mer), saisie manuelle | A16 |

**Le pays du fournisseur ne décide d'aucune ligne** (P4) : ce qui compte, c'est l'établissement, l'origine réelle, le préfixe du n° de TVA comparé à l'origine, le dédouanement, le statut de la société et le type de ligne.
- Un fournisseur allemand qui livre depuis un entrepôt en France n'est pas une acquisition intracommunautaire (test A09).
- Un fournisseur allemand qui facture depuis son établissement en France facture la TVA française (test A10).
- Un distributeur belge (n° BE) qui livre depuis un entrepôt allemand n'est pas classé en acquisition : c'est peut-être une opération triangulaire, dont la société est le destinataire final et doit l'autoliquidation (art. 258 D I ; 1.4, point 6). Le moteur le repère par la comparaison du préfixe et de l'origine, deux faits qu'il a déjà (test A31).
- Un fournisseur établi en France qui vend des marchandises venues de Chine, dédouanées par le magasin, donne une importation, avec les droits dans le coût du stock (test A34) : le champ « Origine des marchandises », toujours présent, rend cette ligne atteignable.

### 6.3 Calcul

Notations : HT = quantité × PU HT, remise déduite (`CalculateurLigne::montantsDAchat()`, `app/Domain/Facturation/Services/CalculateurLigne.php:93-103`), converti en euros si T16 = a.

| Nature | TVA facturée | TVA autoliquidée | TVA déductible (société redevable) | Dû au fournisseur | TVA à payer à l'État pour cet achat |
|---|---|---|---|---|---|
| Achat en France | HT × taux facturé, par ligne (règle de CLAUDE.md:167) | 0 | = TVA facturée | HT + TVA facturée | — (payée au fournisseur) |
| Fournisseur en franchise | 0 | 0 | 0 | HT | — |
| Achat auprès de Monaco | Comme un achat en France | 0 | = TVA facturée | HT + TVA facturée | — |
| Acquisition intracommunautaire | 0 (la facture porte 0) | Taux dû en France, sur le total HT de chaque taux, réparti par cumul (T13) | = autoliquidée, sur la même déclaration | HT | 0,00 € (déclarée et déduite) |
| Livraison en France par un non-établi | 0 | Idem | = autoliquidée | HT | 0,00 € |
| Services d'un non-établi | 0 | Idem | = autoliquidée | HT | 0,00 € |
| Importation | 0 | TVA de la déclaration en douane, **reprise, jamais recalculée** | = TVA d'importation | Prix facturé ; les droits se paient à la douane ou au transitaire | 0,00 € |

- **Contrôle de la TVA d'importation** : le moteur propose (valeur en douane + droits + autres taxes non déductibles + **frais accessoires jusqu'au premier lieu de destination**) × taux, et signale l'écart (art. 292 ; 1.4, point 12). Le montant de la déclaration reste la référence. Exemple : 660,00 + 24,00 + 50,00 de transport Le Havre–Lyon = 734,00 €, soit 146,80 €, sans avertissement (test A11b).
- La TVA autoliquidée **n'est jamais lue sur la facture du fournisseur**, qui porte un taux et une TVA nuls (règles BR-AE-05, BR-AE-09, BR-IC-05 et BR-IC-09, relues dans `reglementation.md` 3.2) : c'est la société qui la calcule. Le taux de la facture (0) et le taux dû en France sont deux données distinctes.
- **TVA étrangère facturée** (T15 = b) : elle s'enregistre à part, ni déductible ni dans le coût ; la TVA française de l'acquisition se calcule quand même ; « À payer au fournisseur » la comprend ; la facture entre dans la liste « TVA étrangère à régulariser ».
- **Société en franchise** : aucune TVA n'est déductible. La TVA facturée et la TVA autoliquidée entrent dans le coût (6.4), et « TVA à payer à l'État » montre la TVA autoliquidée (T10).
- **Escompte** : il baisse le coût, pas les totaux (`database/migrations/2026_10_07_100001_create_factures_achat_table.php:36-39`, inchangé). Son effet sur la base de la TVA déductible est à confirmer (annexe B, point 3).
- **Exigibilité et date de déduction** : étape 5 (8.2). Aucune valeur n'est enregistrée avant.

### 6.4 Coût d'entrée en stock

Pour chaque ligne de produit :

```
coût unitaire d'entrée = coût net unitaire (PU HT × (1 − remise) × (1 − escompte), au dix-millième : coutUnitaireNet(), inchangé)
                       + (quote-part des frais + quote-part des droits + TVA non déductible de la ligne) ÷ quantité
                         (un seul arrondi, au dix-millième)
```

- Sans frais, sans droits et sans TVA non déductible, le coût est **exactement** celui d'aujourd'hui (`LigneAchat::coutUnitaireHt()`, `app/Domain/Achats/Models/LigneAchat.php:92-99`).
- **Quote-parts** des frais d'acheminement et des droits : réparties entre les lignes de produits au prorata de leur HT net, **par cumul** (chaque ligne reçoit la part arrondie du cumul jusqu'à elle, moins celle des lignes précédentes), comme R4. Le total réparti tombe juste au centime (T14).
- **Exemple** : 100 × 8,00 € et 50 × 4,00 €, port 40,00 € facturé par le fournisseur.
  - 1re ligne : 40,00 × 800 ÷ 1 000 = 32,00 € ; 2e ligne : 40,00 − 32,00 = 8,00 €.
  - Coûts : 8,0000 + 32,00 ÷ 100 = **8,3200 €** ; 4,0000 + 8,00 ÷ 50 = **4,1600 €**.
- Une ligne de frais n'entre jamais en stock elle-même ; une ligne « autre service » n'entre ni en stock ni dans le coût.
- **Le coût est figé à la validation dans la colonne `cout_unitaire_entree` de la ligne d'achat.** La réception le lit et le passe à `StockLedgerService::receptionner()` (`app/Domain/Stock/Services/StockLedgerService.php:49-69`), qui recalcule le coût moyen du produit. **Le mouvement de réception ne porte aucun coût** : le grand livre est immuable (`database/migrations/2026_10_02_200004_create_mouvements_stock_table.php:34-35`), et seul un retour au fournisseur y inscrit un coût (`:24-29`).
- **Correction après la validation** (déclaration en douane complétée, frais rattachés au lot M7, modification avec l'autorisation de valider) :
  - la décision est recalculée et remplacée, et l'ancienne part au journal d'audit (`CoutDEntreeRecalcule` ou `FactureAchatModifiee`, 3.3) ;
  - le coût moyen se recalcule par le rejeu existant, `StockLedgerService::corrigerUneReception()` (`:100-131`), à quantité inchangée : il lit le coût des lignes d'achat, jamais celui des mouvements (`cmupRejoue()`, `:311-352`) ;
  - aucun mouvement n'est modifié ni ajouté ;
  - compléter le dédouanement ou rattacher des frais reste permis sur une facture reçue automatiquement, puisque son contenu reçu ne change pas (5.5, point 8) ;
  - **c'est refusé dès qu'un avoir fournisseur a été émis sur la facture** (`tva.cout_fige_par_un_avoir_fournisseur`) : ses retours sont sortis à l'ancien coût par des mouvements immuables, et la règle « un retour sort au coût de la ligne d'achat retournée » (CLAUDE.md:172) serait contredite. Le message le dit ; la valeur du stock s'ajustera avec l'étape 7 (ajustements).
- La sortie de stock d'un magasin cédé (lot A5 de la chaîne société) se fait au coût moyen. Pour une société en franchise, ce coût comprend la TVA non déductible : la « valeur hors taxe » remise au propriétaire est alors cette valeur au coût, ce que l'écran dit (O1).

### 6.5 Importation (exemple complet) et frais rattachés

Un fournisseur chinois facture 100 articles à 6,60 € (CIF Le Havre, transport jusqu'au Havre compris), soit 660,00 €. Le transitaire dédouane pour le magasin de Lyon. Un transporteur français achemine les marchandises du Havre à Lyon pour 50,00 € HT. Déclaration en douane : valeur 660,00 €, droits 24,00 €, frais accessoires jusqu'à Lyon 50,00 €, base de la TVA 734,00 €, TVA à l'importation 146,80 €.

| Montant | Valeur | Origine |
|---|---|---|
| HT | 660,00 € | Facture du fournisseur |
| TVA facturée | 0,00 € | Facture |
| TVA d'importation (autoliquidée) | 146,80 € | Déclaration en douane ; le contrôle (660,00 + 24,00 + 50,00) × 20 % = 146,80 € concorde |
| TVA déductible | 146,80 € | Société redevable |
| TVA à payer à l'État pour cet achat | 0,00 € | 146,80 € déclarés, 146,80 € déduits |
| Dû au fournisseur | 660,00 € | Facture |
| À payer à la douane ou au transitaire | 24,00 € de droits | Déclaration |
| Coût d'entrée | (660,00 + 24,00 + 50,00) ÷ 100 = **7,3400 €** l'article, si T14 = a et le transport rattaché ; sans lui, 6,8400 € | 6,6000 + (24,00 + 50,00) ÷ 100 |
| Circuit | aucun e-reporting | Les importations ne figurent pas à l'art. 290 I (PDF de l'éditeur) |
| Rubrique (étape 5) | importations | Lignes de la CA3 à vérifier (annexe B, point 1) |

**La facture du transporteur** est une facture d'achat à part, du même magasin. Sa ligne de frais, à 0 %, porte la raison « importation » : la prestation est exonérée parce que sa valeur entre dans la base de la TVA d'importation (art. 262 II 14° ; 1.4, point 13). Le moteur vérifie que la déclaration rattachée compte des frais accessoires, sinon il avertit (`tva.frais_hors_base_importation`). Un transporteur qui facture 20 % donne une TVA déductible sur sa propre facture, et le coût des marchandises reçoit seulement le HT.

**L'écran du rattachement (lot M7).**
- Sur une ligne de frais, un champ « Rattacher à » propose les factures d'achat validées du même magasin, par fournisseur et date (règle `exists` par `regleExisteDansLeMagasin()`, 3.4).
- **Une ligne se rattache à une seule facture.** La facture d'un transitaire qui couvre trois arrivages se saisit en trois lignes de frais, une par arrivage, comme elle les détaille d'ordinaire. Ce choix évite une table de liaison (P14).
- Valider ou modifier la facture de frais recalcule et fige de nouveau la facture rattachée, sous son verrou, avec l'autorisation de valider, et inscrit `CoutDEntreeRecalcule` à son journal d'audit. Refusé si cette facture a un avoir fournisseur émis (6.4).
- Le panneau de la facture des marchandises liste les frais rattachés et leur répartition par ligne.

**La déclaration en douane** se saisit sur la facture des marchandises (une par facture, colonnes `douane_*`, 3.2). Une déclaration qui couvre deux factures du même arrivage se saisit sur chacune, avec la même référence et la part de chaque facture, que la déclaration détaille par article ; le contrôle de la TVA se fait facture par facture (test A38).

**La liste « Dédouanement à compléter »** (lot M7) : dans le menu Achats, avec un badge qui compte les factures validées dont la déclaration manque ou est incomplète. Les membres autorisés à valider reçoivent une notification à la validation. Une ligne disparaît de la liste quand la déclaration est complète.

### 6.6 Avoirs fournisseur et retours

- Un avoir fournisseur reprend la décision de la ligne achetée : même nature, mêmes taux facturé et dû en France.
- **TVA facturée** : calculée par différence de cumul, comme aujourd'hui (`LigneAchat::montantsDuRetour()`, `app/Domain/Achats/Models/LigneAchat.php:123-139`).
- **TVA autoliquidée** (T13) : sur le modèle de `retourDeLigneProfessionnelle()` des ventes (`app/Domain/Facturation/Services/CalculateurLigne.php:191-217`) :
  - TVA cumulée rendue = min(arrondi(HT rendu × taux dû en France), part de la ligne dans la répartition) ;
  - un retour complet rend exactement la part de la ligne ;
  - chaque avoir crédite la différence de cumul : le dernier retour solde la ligne au centime, sans jamais reprendre plus que la facture n'a déclaré.
- La sortie de stock se fait **au coût d'entrée de la ligne retournée**, frais et droits compris (décision C1 : « au coût de la ligne d'achat retournée »), puis le coût moyen se recalcule, plancher à 0 € (K1).
- **Exemple** : retour de 30 articles sur l'acquisition de 100 × 8,00 € avec 40,00 € de port (part de la ligne : 160,00 € de TVA autoliquidée, 8,00 € pour le port). Avoir : −240,00 € HT, TVA facturée 0, TVA autoliquidée −48,00 € (min(48,00 ; 160,00)), déductible −48,00 €. Sortie de stock : 30 × 8,4000 = 252,00 €. Les 12,00 € d'écart sont la part du port non remboursée, déjà comptée dans le coût.
- Après un avoir fournisseur émis, le coût de la facture ne change plus (6.4).

### 6.7 Société en franchise, magasin des DOM, cas exclus

- **Franchise, achat en France** : TVA facturée non déductible, dans le coût (10 × 8,00 € + 16,00 € de TVA → 9,6000 € l'article).
- **Franchise, achat hors de France** (T10). Une société en franchise bénéficie du **régime dérogatoire** pour ses acquisitions dans l'Union (art. 256 bis I 2°) :
  - ses acquisitions ne sont pas soumises à la TVA française tant que leur cumul, pour la société, reste sous 10 000 € HT l'année précédente et l'année en cours, sauf option. Le fournisseur facture alors la TVA de son pays, non déductible et ajoutée au coût (A6). **Leur place dans l'e-reporting n'est pas établie** : l'art. 290 I 4° b vise les acquisitions non soumises « en application du I de l'article 258 D », c'est-à-dire les opérations triangulaires (1.4, point 6), pas ce régime. À confirmer (annexe B, point 15) ;
  - au-delà, ou sur option, elle autoliquide la TVA française sans pouvoir la déduire (A7) ;
  - ce compteur des acquisitions serait une somme lue sur tous les magasins de la société (T7). La règle reste à confirmer : d'où la recommandation de T10, qui bloque les acquisitions et les importations.
  - Les services d'un prestataire étranger (A18) : la société reste un assujetti, le lieu est en France, elle autoliquide sans déduire ; la TVA s'ajoute à la charge (T10).
- **Magasin de Guadeloupe, de Martinique ou de La Réunion** : un achat venu d'ailleurs que de son département est une importation dans le DOM, avec l'octroi de mer. Cas exclu, saisie manuelle (A16).
- **Saisie manuelle** (T11) : un membre qui a l'autorisation dédiée indique la nature, les taux et les montants de TVA, avec un motif obligatoire. La décision porte `saisieManuelle` (auteur, motif) et le journal d'audit inscrit `TvaDAchatSaisieALaMain`. Aucune exonération de vente n'est jamais forçable : la saisie manuelle n'existe que pour les achats.

---

## 7. Ventes en détail

### 7.1 Le client

| Client | Méthode de calcul | Série | Circuit (étape 2) |
|---|---|---|---|
| Particulier, en France ou à l'étranger | Article par article | Ticket, ou facture s'il la demande (complément 13) | E-reporting des ventes aux particuliers (TLB1, TPS1, ou TNT1 hors TVA française, à confirmer) |
| Professionnel assujetti établi en France (métropole, Corse, Guadeloupe, Martinique, La Réunion) | Depuis le HT de ligne (E1) | Facture | Facture électronique, quand les trois conditions de la FAQ sont réunies |
| Professionnel établi hors de France, à Monaco, en Guyane, à Mayotte ou dans une collectivité d'outre-mer | Depuis le HT de ligne | Facture | E-reporting international sortant (identifiant 0223, 0227, 0228 ou 0229) |
| Professionnel **non assujetti** (association sans activité taxable) | Depuis le HT de ligne (O2) | Facture | E-reporting des ventes aux particuliers (art. 290 I 2° b) ; jamais en facture électronique |
| **Entité publique** | Depuis le HT de ligne | Facture | Chorus Pro, hors de PILIQI, avec un message (E7, O2) |

- **Une prestation qui n'est pas située en France** (art. 259 ou 259 A), vendue à un assujetti, relève de l'e-reporting international sortant **même si le client est établi en France** : l'art. 290 I 1° c ne pose aucune condition sur l'établissement du preneur (1.4, point 2). PILIQI refuse aujourd'hui une telle prestation (7.3) ; la règle du circuit s'écrira avec la nature qui l'ouvrira. La première conception, d'après la recherche (§1.2 et N10), lui donnait à tort « aucun circuit ».
- « Assujetti » est **vrai par défaut** pour un professionnel. Il se coche à l'envers, dans la section repliée « Fiscalité » de la fiche : « Ce client n'est pas assujetti à la TVA ». Il n'est jamais proposé automatiquement, une association pouvant être assujettie.
- « Entité publique » est **faux par défaut**. Il est proposé quand une recherche Sirene, faite sur un bouton, renvoie une catégorie juridique 7xxx.
- Les deux sont **figés à l'émission**, comme les autres données du client (`app/Domain/Facturation/Concerns/FigeLeClient.php`), et la copie du client signée les reprend (3.3).

### 7.2 Destination réelle des biens et départ

- La destination est **l'adresse de livraison**, pas l'adresse du client. Elle est préremplie depuis la fiche (`app/Filament/Concerns/ChoisitLaNatureDeLOperation.php:295-314`), avec son territoire lu par `GeographieTva` : pays et code postal, pour les Canaries, Ceuta, Melilla, Åland, le mont Athos, Heligoland, Büsingen, Livigno, Campione, les DOM, les collectivités d'outre-mer et Monaco.
- **Monaco, pour des biens, se lit sur le mouvement** : une livraison expédiée de France vers Monaco relève de l'art. 290 I 4° a, quel que soit l'établissement du client ; la TVA reste française (1.4, point 4). Pour un client établi en France livré à Monaco, la facture électronique s'applique (trois conditions réunies) ; savoir si l'e-reporting du 4° a s'y ajoute est à confirmer (annexe B, point 5).
- Le départ est **le territoire du magasin**, puisque le stock est celui du magasin et qu'aucun transfert n'existe entre magasins (CLAUDE.md:172). Il est copié dans chaque décision (corrige la traçabilité de C3).
- La sortie des biens se demande pour un client établi hors du territoire de TVA français, un n° de TVA étranger ou une livraison hors de France (T3), et pour tout client par le lien « Expédier hors de France ou livrer à une autre adresse ? » (5.1). Un professionnel établi en France dont les biens partent en Suisse obtient ainsi un export (test V34). Une vente en France livrée à une autre adresse imprime l'adresse de livraison, mention exigée quand elle diffère de celle du client (`reglementation.md` 1, point 8 ; test V35).

### 7.3 Lieu des services

- **Entre assujettis**, le lieu est celui du preneur (art. 259-1°) : prestation autoliquidée (Union) ou hors champ (hors Union).
- **Sauf un service au lieu fixé par la loi** (`Service::lieu_specifique`, art. 259 A) : immeuble, manifestation, location courte d'un moyen de transport, restauration, transport de personnes. Son lieu est celui de l'immeuble, de l'événement ou de l'exécution :
  - exécuté en France : TVA française, même pour un client étranger (test V15, « exécutée à Lyon ») ;
  - exécuté hors de France : l'opération n'est pas située en France. **PILIQI la refuse avec un message** (`tva.lieu_du_service_hors_de_france` : « Ce service est exécuté hors de France : sa TVA n'est pas française et PILIQI ne gère pas encore ce cas »), jamais une TVA française facturée à tort, qui serait due du seul fait d'être facturée (test V31) ;
  - la question « Ce service est-il exécuté en France ? » est posée dès qu'un fait international existe (client établi hors de France, n° de TVA étranger, livraison hors de France, lien ouvert) ; sans réponse, l'émission est refusée (`tva.lieu_du_service_a_preciser`). Sans fait international, le service est réputé exécuté en France : c'est une limite écrite en 1.3.
- **À un particulier**, le lieu est celui du prestataire, donc la France, sauf l'art. 259 A ci-dessus et l'art. 259 B.
- **Art. 259 B** (conseil, publicité, traitement de données, location de biens meubles… à un particulier domicilié hors de l'Union) : non distingué. Un avertissement s'affiche quand un particulier dont l'adresse est hors de l'Union achète un service (test V38). Un marqueur « service immatériel » sur la fiche du service viendra au lot M9 si l'éditeur le demande.
- **Prestation à un professionnel établi dans un DOM** : refusée avec un message (O6).
- Le commentaire de la migration des services, qui dit « jamais facturé sans TVA française à un professionnel étranger » (`database/migrations/2026_10_02_200005_create_services_table.php:30-35`), et le message « … est fixé par la loi (art. 259 A du CGI) : la TVA française s'applique » (`app/Domain/Facturation/Services/ControleNatureOperation.php:220`) sont corrigés : la TVA française ne s'applique que si le service est exécuté en France.

### 7.4 Les natures de I1

| Nature | Conditions cumulatives (toutes évaluées par le moteur) | Catégorie / VATEX (déduits) | Mention (table de 2026) | Preuves attendues | Circuit |
|---|---|---|---|---|---|
| Vente en France | Par défaut | S | Mentions générales | — | Selon 7.1 |
| Export hors UE expédié par le magasin | Des biens (et leurs frais accessoires) ; sortie « expédiés par le magasin » ; adresse complète hors du territoire de TVA de l'Union, ni à Monaco ni dans un DOM ; départ du territoire du magasin ; n° de TVA de la société | G / VATEX-EU-G | « Exonération de TVA, article 262 I du CGI » | MRN ou CN23 | International (professionnel non établi) ; facture électronique (professionnel établi en France) ; ventes aux particuliers (TNT1, à confirmer) |
| Livraison vers un DOM | Des biens ; expédiés par le magasin ; adresse complète dans un DOM autre que celui du magasin ; magasin de métropole ou de Corse (O6) ; n° de TVA de la société | G ou E, à confirmer (annexe B, point 8) | « Exonération de TVA en application de l'article 294 du Code général des impôts » | MRN ou CN23, et un document de transport | Facture électronique pour un professionnel établi en Guadeloupe, en Martinique ou à La Réunion ; e-reporting pour la Guyane et Mayotte ; ventes aux particuliers pour un particulier |
| Livraison intracommunautaire à un professionnel | Client professionnel ; n° de TVA d'un autre État membre (XI admis), vérification VIES valide du jour (I2, O3) ; biens livrés dans un autre État membre ; date de livraison ; sortie « expédiés par le magasin » ou « emportés par le client » ; seulement des biens et leurs frais ; n° de TVA de la société | K / VATEX-EU-IC | « Exonération de TVA, article 262 ter I du CGI » | Deux documents de transport de catégorie a), ou un a) et un b), **émis par deux parties différentes, indépendantes l'une de l'autre, du vendeur et de l'acquéreur** (lot C-a) ; attestation de l'acquéreur au plus tard le 10 du mois suivant s'il emporte les biens | International (client non établi) ; facture électronique pour un client établi en France sous un n° étranger |
| Prestation à un professionnel de l'UE (autoliquidation) | Client professionnel établi dans un autre État membre (jamais l'Irlande du Nord) ; n° de TVA valide sur VIES le jour même ; seulement des services, aucun au lieu fixé par la loi ; n° de TVA de la société | AE / VATEX-EU-AE | « Autoliquidation » | Vérification VIES | International ; relevé du mois pour la déclaration européenne de services |
| Prestation à un professionnel hors UE | Client professionnel établi hors de l'Union (Irlande du Nord comprise, Monaco exclu) ; n° de TVA ou autre identifiant ; seulement des services, aucun au lieu fixé par la loi ; **document séparé** (BR-O-11) ; n° de TVA de la société | O / VATEX-EU-O, sans taux | « TVA non applicable – article 259-1° du CGI » | Identifiant du client | International (0227, 0228 ou 0229) |

- Le prix payé suit K3 : hors de la vente en France et hors franchise, le client paie le prix TTC du catalogue ÷ (1 + taux), calculé comme pour son type de client. Pour un service, le prix de référence de R2 suit la même règle si T4 = a.
- Le contrôle d'indépendance des émetteurs : chaque preuve porte « émise par » (obligatoire, complément 4) ; les deux émetteurs doivent être différents l'un de l'autre, et différents du magasin et du client. Le libellé reprend la règle : « deux parties indépendantes l'une de l'autre, du vendeur et de l'acquéreur » (art. 45 bis du règlement 282/2011 ; 1.4, point 14).
- Une nature appliquée dont une condition tombe avant l'émission (VIES devenu invalide, adresse changée) bloque l'émission avec la voie « Rester à la TVA française » ; elle ne retombe jamais en silence.

### 7.5 Ventes à distance et guichet OSS

**Votre réponse I1 a placé l'OSS « ensuite ». Votre demande cite « les ventes à distance et le régime OSS lorsque nécessaire ». T8 demande donc quand construire l'OSS ; en attendant, le moteur ne facture jamais une TVA française fausse.**

Ce qui est sûr (recherche N7, art. 258 A, `reglementation.md` 4.1, vérification de 1.4) :
- **Une vente à distance** est une livraison de biens expédiés par le vendeur, ou pour son compte, vers un autre État membre, à un acquéreur qui est **un particulier, ou un assujetti ou une personne morale non assujettie dont les acquisitions ne sont pas soumises à la TVA** (régime dérogatoire de son pays) (1.4, point 8).
- La TVA française est juste tant que le cumul des ventes à distance dans l'Union reste sous **10 000 € HT** sur l'année civile précédente **et** sur l'année en cours ; ce cumul s'apprécie **pour la société** (le SIREN), tous magasins confondus.
- Au-delà, la TVA du pays d'arrivée s'applique **dès l'opération qui fait franchir le seuil**, puis pour le reste de l'année et toute l'année suivante. Elle se déclare par le guichet OSS ou par une immatriculation sur place. **L'inscription à l'OSS vaut option** : la TVA du pays d'arrivée s'applique alors dès le premier euro.
- **La franchise en base de la société ne change rien au seuil** : elle ne couvre que les opérations situées en France (1.4, point 9).

**Proposition recommandée (T8 = a), au lot M8, avant l'étape 2 :**
1. **Le fait « vente à distance »** : biens expédiés par le magasin vers un autre État membre, à un particulier ou à un client marqué « non assujetti », sans n° de TVA valide sur VIES. Il s'évalue **avant la branche de la franchise** du moteur (2.5) : une société en franchise est comptée et refusée de la même façon (7.8).
2. **Un professionnel de l'UE sans n° de TVA, non marqué « non assujetti »** : la vente reste en France, non comptée, avec un avertissement (« s'il n'est pas assujetti ou relève du régime dérogatoire de son pays, c'est une vente à distance : marquez-le « non assujetti » sur sa fiche »).
3. **Le compteur de la société** : HT des ventes à distance net des avoirs, lu sur les décisions (jamais recalculé), magasins réels de la société, démonstration exclue (`CompteurDesVentesADistance`, si T7 = a).
4. **Deux valeurs de départ**, saisies une fois dans les paramètres de la société, à l'arrivée dans PILIQI : le total des ventes à distance de l'année civile précédente, et celles de l'année en cours faites avant l'arrivée. Elles sont journalisées (JET 270).
5. **La question « Votre société est-elle inscrite au guichet OSS ? Depuis le … »**, dans les mêmes paramètres. Oui : **toute** vente à distance est refusée tant que l'OSS n'est pas construit (`tva.oss_non_gere`).
6. **Un avertissement à l'approche du seuil**, à partir de 9 000 € (90 %, valeur de la table datée des seuils, modifiable par une version des règles).
7. **Le refus de l'émission qui ferait dépasser le seuil**, et de toute vente à distance si l'année précédente l'a dépassé (`tva.seuil_ventes_a_distance_depasse`) : « au-delà de 10 000 € de ventes à distance dans l'Union, la TVA du pays du client s'applique : PILIQI ne la gère pas encore ». La vente au comptoir reste possible.
8. **Ce que le refus entraîne**, à dire à l'éditeur dans T8 : une fois 10 000 € atteints, plus aucune expédition à un particulier (ou à un client non assujetti) de l'Union jusqu'au 31 décembre, ni pendant toute l'année suivante, tant que l'OSS n'existe pas.
9. **Qui voit quoi** : le montant du compteur se voit dans l'espace de la société, du propriétaire et des administrateurs autorisés à gérer ses paramètres ; le panneau d'un vendeur ne montre que l'état (« seuil non atteint », « seuil proche », « seuil atteint ») (3.4).

L'analyse ergonomique recommandait un simple avertissement (QE8). T8 le reprend comme option : il laisserait facturer une TVA française fausse au-delà du seuil.

**Plus tard, au lot M9, après l'étape 2, si l'éditeur le confirme** (le bac à sable SUPER PDP doit d'abord confirmer la catégorie TNT1 et l'absence de double déclaration) : nature « vente à distance taxée dans le pays d'arrivée » ; table datée des taux des 27 États membres **par catégorie de produit**, d'où une catégorie fiscale sur la fiche produit, en mots simples ; prix payé (question reportée à ce lot : même prix TTC recommandé) ; catégorie S au taux étranger si un document est émis ; registre OSS conservé 10 ans ; export trimestriel pour la déclaration OSS, déposée hors de PILIQI.

### 7.6 Export emporté par le client (N4)

Prévu au lot M9, avec I1 « ensuite ». Il vise tout acquéreur non établi en France qui transporte lui-même les biens hors de l'Union, avec une déclaration d'export à son nom. La garantie de TVA (facture S puis avoir et facture G à la réception de la preuve, ou facture G avec une consignation hors facture) doit d'abord être validée par l'expert-comptable (annexe B, point 12). En attendant, le moteur dit : « Export emporté par le client : pas encore géré, la vente reste à la TVA française (détaxe possible pour un voyageur) » (test V30).

### 7.7 DOM (O6)

- **Magasin de métropole ou de Corse qui expédie vers un DOM** : nature « Livraison vers un DOM » (7.4).
- **Magasin des DOM qui vend sur place** : TVA du département, au taux de la fiche (8,5 % par défaut, B2).
- **Magasin des DOM qui expédie hors de son département** : aujourd'hui, l'export vers la métropole est refusé à tort, et une expédition en Allemagne est classée en livraison intracommunautaire alors que les DOM sont hors du territoire de TVA de l'Union (C3). Désormais : refus avec un message (`tva.depart_hors_du_dom_non_gere`) tant qu'une nature dédiée n'existe pas (lot M9).
- **Prestation vendue à un professionnel établi dans un DOM** (recherche N11 : taux du département collecté par le prestataire, ou hors champ en Guyane et à Mayotte) : refus avec un message (`tva.prestation_vers_un_dom_non_geree`) jusqu'à la nature dédiée du lot M9. Les particuliers ne sont pas concernés.

### 7.8 Franchise en base

- Société en franchise : toutes les ventes en France à 0 %, au même prix TTC, avec la mention de franchise (B10). Catégorie E, VATEX-FR-FRANCHISE ; jamais S à 0 %, puisque BR-S-05 exige un taux positif. Circuit selon le client ; catégorie des ventes aux particuliers à confirmer (TNT1).
- Une livraison à un professionnel de l'Union reste couverte par la franchise, pas par l'exonération de l'art. 262 ter (recherche §1.5).
- **Le seuil des ventes à distance s'applique** : une expédition à un particulier de l'Union compte au compteur, au HT sans TVA, et se refuse au-delà du seuil (tests V19f, V20f).
- Les prestations à un professionnel établi hors de France suivent T10.

### 7.9 Cartes cadeaux et bons d'achat (T9)

- La loi distingue deux bons (art. 256 ter ; 1.4, point 11) :
  - **bon à usage unique** : le lieu de la livraison et la TVA due sont connus à son émission (même magasin, même taux pour tout ce qu'il permet d'acheter) ; il est taxé à sa vente, au taux des biens ;
  - **bon à usages multiples** : sa vente n'est ni une livraison taxable ni une transaction d'e-reporting ; seule son utilisation est taxée (recherche §1.7).
- Une carte cadeau d'un magasin qui vend à plusieurs taux est donc, le plus souvent, à usages multiples. La taxer à la vente, comme un produit ordinaire, est faux.
- Recommandation (T9 = a) : la fiche produit reçoit la particularité « carte cadeau ou bon d'achat » ; un tel produit ne se vend pas (`tva.bon_d_achat_non_gere`), ni en caisse ni sur facture, tant que le traitement n'est pas construit (lot M9) : vente d'un bon à usages multiples sans TVA, puis bon accepté comme moyen de paiement, à rapprocher du bon d'avoir existant.
- Limite : un produit qui n'est pas marqué ainsi est traité comme un produit ordinaire ; la notice le dit.

### 7.10 Relevé du mois : livraisons intracommunautaires et prestations autoliquidées

- La société dépose elle-même, chaque mois, l'état récapitulatif de ses livraisons intracommunautaires et la déclaration européenne de services, sur le portail de la douane. PILIQI ne les dépose pas (1.3) mais **prépare la liste** (lot M4).
- **Contenu** : pour le mois, par n° de TVA du client, le HT des livraisons intracommunautaires et celui des prestations autoliquidées, **nets des avoirs émis dans le mois**, lus sur les documents émis (nature figée et montants des lignes), sans rien recalculer.
- **Où** : dans chaque magasin, pour les membres qui voient toutes ses factures ; et, si T7 = a, la somme de la société, dans son espace, pour le propriétaire et les administrateurs autorisés à gérer ses paramètres (`ReleveFiscalDeLaSociete`, 3.4).
- **Export** : fichier CSV ; le panneau d'une facture concernée dit « relevé du mois, à déposer vous-même sur le portail de la douane ».
- Démonstration exclue.

---

## 8. Déclarations futures : lire sans recalculer

### 8.1 Étape 2 (SUPER PDP)

| Besoin | Ce qu'on lit | Où |
|---|---|---|
| Circuit d'une facture ou d'un avoir | `factures.circuit` et `avoirs.circuit`, figés et signés | Remplace `relevantDeLeInvoicing()` ; reprend la phase 5 du plan SUPER PDP sur les trois conditions et non plus sur le type de client |
| Catégorie, VATEX et motif de chaque ligne (BT-151, BT-121) | **Déduits** de la nature, de la franchise et du type de ligne figés, dans la table des catégories de la version des règles figée (P14) : une lecture de table datée, pas un nouveau calcul (P10) | Phase 5 (aujourd'hui la catégorie y est toujours « S ») ; taux nul en catégorie O (BR-O-05), la base garde 0 |
| Ventilation par taux (BG-23) | Montants enregistrés des lignes ; une nature par document, donc une catégorie par taux | Jamais recalculés |
| E13 | Une table « catégories prises en charge par la passerelle » : S d'abord, puis K, AE, G, O et E, chacune une fois vérifiée au bac à sable | Refus de transmettre, avec un message, tant que la catégorie n'y est pas (lot M3, écouteur réécrit) |
| E-reporting international sortant | Documents au circuit international : identifiant de l'acheteur, catégories déduites, totaux | Phase 9 (direction « out ») |
| E-reporting international entrant | Factures d'achat validées dont la nature relève de l'art. 290 I 3° ou 4° : n° et date du fournisseur, catégorie attendue, TVA autoliquidée | Phase 9 (direction « in ») ; le circuit d'achat est déduit des natures figées et n'est enregistré qu'à cette étape s'il le faut ; ce qu'il faut porter reste à vérifier au bac à sable (`reglementation.md` 3.5) |
| E-reporting des ventes aux particuliers, par jour | Lignes émises le jour au circuit des particuliers, regroupées par catégorie, taux et devise, moins les avoirs du jour | Phase 6 : la clôture agrège par circuit, et non plus par type de client (complément 8) ; elle inclut les factures demandées par des particuliers et les factures aux non-assujettis |
| Statut « encaissée » (E5) et paiements des particuliers (E6) | Exigibilité calculée à cette étape depuis la nature, le type de ligne et l'option pour les débits figés (services, sans option, non autoliquidés) | Phase 8 ; la colonne d'exigibilité, si elle sert, arrive à cette étape (3.1) |
| Absence d'opération | Appréciée pour la société (complément 10) | SUPER PDP |
| Magasin de démonstration | Circuit « aucun » | Rien ne part |

**Les règles du circuit**, que le moteur applique dès le lot M3 :
1. Démonstration : aucun.
2. Particulier, en France ou à l'étranger, facture demandée comprise : ventes aux particuliers (art. 290 I 2°).
3. Professionnel non assujetti : ventes aux particuliers (art. 290 I 2° b), jamais en facture électronique (O2).
4. Entité publique : Chorus Pro, hors de PILIQI, avec un message (E7).
5. Professionnel assujetti établi en France, opération dans le champ de la TVA française et soumise aux règles de facturation françaises : facture électronique. Cela comprend la livraison intracommunautaire à un client établi en France sous un n° étranger, l'export à un client établi en France, et la livraison vers un DOM pour un client établi en Guadeloupe, en Martinique ou à La Réunion.
6. Professionnel établi hors de France, à Monaco, en Guyane, à Mayotte ou dans une collectivité d'outre-mer : e-reporting international sortant (art. 290 I 1° a, b et d, 4° a).
7. Prestation non située en France (art. 259 ou 259 A) à un assujetti, établi en France ou non : e-reporting international sortant (art. 290 I 1° c). Aucune nature ne la produit aujourd'hui (7.3) ; la règle s'écrira avec elle.

Ces règles touchent une colonne signée. Le bac à sable SUPER PDP les confirme à l'étape 2, avant la production ; d'ici là, aucune donnée réelle n'existe et la base se recrée (CLAUDE.md:146).

### 8.2 Étape 5 (comptabilité, état de TVA, FEC)

- **Rubriques internes stables**, déduites des natures figées : ventes taxées en France, par taux ; exportations ; livraisons intracommunautaires ; autres opérations non imposables (autoliquidation, hors champ) ; ventes à distance taxables ailleurs ; acquisitions intracommunautaires ; livraisons autoliquidées par un non-établi ; services d'un non-établi ; importations ; opérations avec Monaco ; TVA déductible (biens et services, importations) ; sorties de stock hors vente (8.3).
- L'étape 5 relie ces rubriques aux **lignes de la déclaration CA3 par une table versionnée par millésime de notice**. Les numéros restent à vérifier sur la notice 2026, n° 50449#29 (annexe B, point 1). Une nouvelle notice ne change donc jamais une décision enregistrée.
- Les écritures (TVA collectée, TVA autoliquidée collectée et sa contrepartie déductible, TVA d'importation, TVA non déductible dans le coût) se génèrent depuis les montants figés. Les comptes viennent du plan de comptes de la société.
- **Exigibilité et date de déduction des achats**, calculées à cette étape :
  - acquisition intracommunautaire : le 15 du mois suivant la livraison, ou la date de la facture si elle est antérieure (art. 269-2 d) ; la date de réception se demande alors, préremplie par la date de la facture ;
  - importation : date du bon à enlever ;
  - biens achetés en France : livraison ;
  - services achetés en France : au paiement, sauf option du fournisseur pour les débits (`ergonomie-donnees.md` QE14) ; l'option du fournisseur arrive sur sa fiche à cette étape ;
  - **services autoliquidés** (prestataire non établi) : la réalisation de la prestation, selon la directive (art. 66, second alinéa, non retrouvé en ligne ce jour) ; une date de réalisation se saisit, préremplie par la date de la facture, et reste signalée « à confirmer » jusqu'à la réponse de l'expert-comptable (annexe B, point 2). **Jamais « au paiement » par défaut pour une nature autoliquidée.**
- Blocage éventuel de l'export comptable d'une période tant qu'une déclaration en douane est incomplète (proposition de QE5, T12).

### 8.3 Étape 7 (stock : inventaire et ajustements)

- Une décision « **sortie de stock hors vente** » s'ajoute au catalogue du moteur, avec un motif obligatoire : destruction justifiée, vol justifié, démarque inconnue, casse, prélèvement de l'exploitant, don.
- Elle dit s'il faut reverser la TVA déduite à l'achat (art. 207 VI de l'annexe II au CGI) ou s'il y a dispense (BOI-TVA-DED-60-30 : marchandises détruites, vols, démarque inconnue, sous conditions non lues). Les conditions sont à confirmer (annexe B, point 16).
- Elle donne une rubrique interne (8.2) : l'état de TVA de l'étape 5 la lit sans rien recalculer.

---

## 9. Traçabilité

| Quoi | Où | Durée | Affichage |
|---|---|---|---|
| Faits utilisés et leur source | Vente : `decision_tva` du document, signée ; achat : `decision_tva` de la facture d'achat | 10 ans | Fiche du document, « Pourquoi » |
| Version du moteur et des règles, date de l'opération | Idem | 10 ans | Fiche du document |
| Nature, franchise, circuit, sortie des biens, livraison, lieu des services, consultation VIES retenue | Colonnes du document, figées et signées | 10 ans, sans purge (CLAUDE.md:173) | Section « Régime de TVA » |
| Lignes chiffrées | Colonnes des lignes (signées pour une vente) | 10 ans | Document |
| Catégorie et VATEX de chaque ligne | Déduits (version des règles figée) | — | Étape 2 |
| Mentions et note de calcul (code et texte du jour) | `decision_tva` | 10 ans | PDF, fiche, duplicata |
| Conditions évaluées (« oui » ou « non », valeur constatée) | `decision_tva` | 10 ans | Fiche du document |
| Geste sur le régime (appliquer, garder la TVA française) : nature, auteur, instant | Colonnes `nature_confirmee`, `nature_confirmee_par`, `nature_confirmee_le`, signées | 10 ans | Fiche du document |
| Décision d'achat et ses modifications | `decision_tva` de la facture d'achat (la dernière) ; historique au journal d'audit de la société (`FactureAchatValidee`, `FactureAchatModifiee`, `CoutDEntreeRecalcule`, avec l'ancienne décision en anciennes valeurs), chaîné et en ajout seul | 10 ans (catégorie « Opérations ») | Historique de la facture d'achat |
| Saisie manuelle d'un achat : auteur, motif | `decision_tva` (`saisieManuelle`) et journal d'audit (`TvaDAchatSaisieALaMain`) | 10 ans | Fiche de la facture d'achat |
| Régime de TVA de la société et sa date d'effet | `regimes_tva_societe` (ajout seul), JET 270 dans chaque magasin, audit `ParametresFiscauxModifies` (`app/Domain/Tenancy/Enums/ActionAuditee.php:18`) | 10 ans | Paramètres fiscaux de la société |
| Valeurs de départ des ventes à distance, inscription à l'OSS | Colonnes de `societes` (lot M8), JET 270, audit | 10 ans | Paramètres de la société |
| Consultations VIES (clients et fournisseurs) | `consultations_vies`, ajout seul | 10 ans | Fiche du tiers et du document |
| Preuves d'export et de livraison | `preuves_operation`, ajout seul, annulation tracée (complément 4) | 10 ans | Liste « Preuves à fournir » |
| Adresse IP et navigateur | Jamais dans une décision ; traces à part, effacées après 1 an (existant) | 1 an | — |

Les décisions et les gestes ne gardent que l'identifiant de l'utilisateur, jamais son nom (CLAUDE.md, « Journal d'audit »).

---

## 10. Tests

Les tests suivent le style du dépôt : français, `test_` puis une phrase. Ils se lancent sur SQLite pendant le travail, puis sur SQLite et sur PostgreSQL avant chaque commit, avec un code de sortie 0 contrôlé juste après (protocole, point 3). Les montants ont été recalculés à la main avec les règles de CLAUDE.md:167. Le lot de chaque test est donné en 11.3.

### 10.1 Matrice des ventes

Magasin de Lyon (métropole), société redevable, sauf mention contraire. Produits : Câble 40,00 € TTC à 20 % ; Gourde 12,00 € TTC à 20 %. Services : « Paramétrage à distance », prix de référence 120,00 € TTC à 20 % ; « Installation sur site » (lieu fixé par la loi), 240,00 € TTC à 20 %. La catégorie est celle que le moteur déduit ; elle ne s'enregistre pas (P14).

| # | Test | Faits | Payé par le client : HT / TVA / TTC | Catégorie | Mention propre | Circuit |
|---|---|---|---|---|---|---|
| V01 | `test_un_ticket_a_un_particulier_reste_en_vente_en_france_sans_aucune_question` | Particulier, 2 Câbles | 66,66 / 13,34 / 80,00 | S | « Catégorie de l'opération : Livraison de biens » | Particuliers |
| V02 | `test_une_facture_a_un_professionnel_etabli_en_france_part_en_facture_electronique` | Professionnel assujetti établi en France, 1 000 × 1,00 € TTC | 833,33 / 166,67 / 1 000,00 | S | — | Facture électronique |
| V03 | `test_la_repartition_r4_est_inchangee` | Professionnel : 1 × 1,00 € (20 %), 2 × 4,50 € (5,5 %), 2 × 2,00 € (20 %) | 12,69 / 1,30 / 13,99 ; lignes 0,83 + 0,17, 8,53 + 0,47, 3,33 + 0,66 | S | — | Facture électronique |
| V04 | `test_les_retours_partiels_d_une_ligne_professionnelle_restent_cumules` | Professionnel, 2 Câbles, puis deux avoirs d'un article | Facture 66,67 / 13,33 / 80,00 ; avoir 1 : 33,33 / 6,67 / 40,00 ; avoir 2 : 33,34 / 6,66 / 40,00 | S, héritée | — | Facture électronique |
| V05 | `test_une_livraison_intracommunautaire_aux_conditions_reunies_s_applique_d_office_avec_le_nouveau_total` | Müller GmbH (DE), n° valide sur VIES le jour même, 10 Gourdes expédiées par le magasin à Berlin, date de livraison | 100,00 / 0,00 / 100,00 (au lieu de 120,00) ; état « appliqué d'office » ; lien « Rester à la TVA française » | K | « Exonération de TVA, article 262 ter I du CGI » | International sortant ; facture au plus tard le 15 du mois suivant ; relevé du mois |
| V06 | `test_les_frais_de_port_d_une_livraison_intracommunautaire_suivent_les_biens` | V05 + « Port et emballage » (frais accessoires) 18,00 € TTC, si T4 = a | 115,00 / 0,00 / 115,00 | K pour les deux lignes | Idem | Idem |
| V07 | `test_un_numero_tva_invalide_sur_vies_laisse_la_vente_a_la_tva_francaise_et_dit_pourquoi` | V05, VIES ne reconnaît pas le n° | 100,00 / 20,00 / 120,00 ; état « incomplet » | S | — | International sortant (art. 290 I 1° b) |
| V08 | `test_un_professionnel_de_l_ue_sans_numero_de_tva_reste_a_la_tva_francaise_avec_l_avertissement_de_vente_a_distance` | Client DE sans n°, biens expédiés | 100,00 / 20,00 / 120,00 ; avertissement `tva.vente_a_distance_possible` | S | — | International sortant |
| V09 | `test_un_export_a_un_professionnel_hors_ue_expedie_par_le_magasin` | Client US, 1 000 × 1,00 € TTC, expédiés | 833,33 / 0,00 / 833,33 | G | « Exonération de TVA, article 262 I du CGI » | International sortant (0227) |
| V10 | `test_un_export_a_un_particulier_suisse_expedie_par_le_magasin` | Particulier CH, 2 Câbles | 66,66 / 0,00 / 66,66 | G | Idem | Particuliers (TNT1 à confirmer) |
| V11 | `test_une_livraison_vers_la_martinique_a_un_professionnel_etabli_la_bas` | Professionnel assujetti établi en Martinique, 10 Gourdes expédiées | 100,00 / 0,00 / 100,00 | G ou E, à confirmer | « … article 294 du Code général des impôts » | Facture électronique ; transmission retenue tant que la catégorie n'est pas prise en charge (E13) |
| V12 | `test_une_prestation_a_un_professionnel_de_l_ue_est_proposee_et_l_emission_exige_un_choix` | Client IE, n° valide, Paramétrage | État « proposé » ; émission refusée sans choix (`tva.choix_du_regime_requis`) ; appliquée : 100,00 / 0,00 / 100,00 (T4 = a) | AE | « Autoliquidation » | International sortant ; relevé du mois |
| V13 | `test_une_prestation_a_un_professionnel_hors_ue_est_hors_champ` | Client CH avec identifiant, Paramétrage, appliquée | 100,00 / — / 100,00 | O, sans taux | « TVA non applicable – article 259-1° du CGI » | International sortant (0227) |
| V14 | `test_une_prestation_hors_ue_melangee_a_un_bien_est_refusee_avec_le_bouton_de_deplacement` | V13 + une Gourde | Refus `tva.document_separe_requis` ; « Déplacer ces lignes vers une nouvelle facture » | — | — | — |
| V15 | `test_un_service_au_lieu_fixe_par_la_loi_execute_en_france_reste_a_la_tva_francaise_pour_un_client_etranger` | Client DE, Installation sur site, réponse « exécuté en France » (à Lyon) | 200,00 / 40,00 / 240,00 | S | — | International sortant (art. 290 I 1° d) |
| V16 | `test_un_client_de_saint_barthelemy_peut_recevoir_une_prestation_hors_champ` | Professionnel à Saint-Barthélemy (SIREN exigé), Paramétrage, appliquée | 100,00 / — / 100,00 | O | 259-1° | International sortant (corrige C2) |
| V17 | `test_une_vente_en_franchise_se_fait_a_zero_au_meme_prix_ttc` | Société en franchise, particulier, 2 Câbles | 80,00 / 0,00 / 80,00 | E | « TVA non applicable, art. 293 B du CGI » | Particuliers |
| V18 | `test_une_facture_en_franchise_a_un_professionnel_garde_le_meme_prix` | Franchise, professionnel établi en France, 1 000 × 1,00 € | 1 000,00 / 0,00 / 1 000,00 | E | 293 B | Facture électronique, retenue tant que E n'est pas prise en charge |
| V19 | `test_une_vente_a_distance_sous_le_seuil_reste_a_la_tva_francaise_avec_le_compteur` | Particulier BE, expédié ; compteur de la société 4 000,00 € | 66,66 / 13,34 / 80,00 ; compteur 4 066,66 € ; le vendeur voit « seuil non atteint » | S | — | Particuliers |
| V19a | `test_une_association_belge_non_assujettie_compte_dans_les_ventes_a_distance` | Association BE marquée « non assujettie », sans n°, expédié ; compteur 4 000,00 € | 66,67 / 13,33 / 80,00 (calcul d'un professionnel, O2) ; compteur 4 066,67 € | S | — | Particuliers (art. 290 I 2°) |
| V19f | `test_une_societe_en_franchise_compte_ses_ventes_a_distance` | Franchise, particulier BE, expédié ; compteur 4 000,00 € | 80,00 / 0,00 / 80,00 ; compteur 4 080,00 € | E | 293 B | Particuliers |
| V20 | `test_une_vente_a_distance_qui_franchirait_le_seuil_est_refusee` | Compteur 9 950,00 €, vente de V19 | Refus `tva.seuil_ventes_a_distance_depasse` (10 016,66 € > 10 000,00 €) | — | — | — |
| V20a | `test_une_vente_a_distance_a_une_association_qui_franchirait_le_seuil_est_refusee` | Compteur 9 950,00 €, vente de V19a | Refus (10 016,67 € > 10 000,00 €) | — | — | — |
| V20f | `test_une_vente_a_distance_d_une_societe_en_franchise_qui_franchirait_le_seuil_est_refusee` | Franchise, compteur 9 950,00 €, vente de V19f | Refus (10 030,00 € > 10 000,00 €) | — | — | — |
| V21 | `test_un_particulier_de_l_ue_qui_emporte_ses_achats_paie_la_tva_francaise` | Ticket, client belge au comptoir | 66,66 / 13,34 / 80,00 | S | — | Particuliers, aucune question |
| V22 | `test_des_biens_expedies_a_monaco_pour_un_client_monegasque_relevent_de_l_e_reporting` | Professionnel établi à Monaco, 10 Gourdes **expédiées à Monaco** | 100,00 / 20,00 / 120,00 | S | — | E-reporting (art. 290 I 4° a) |
| V22b | `test_un_client_etabli_en_france_livre_a_monaco_garde_la_tva_francaise` | Professionnel établi en France, 10 Gourdes livrées à Monaco | 100,00 / 20,00 / 120,00 | S | — | Facture électronique ; recoupement avec le 4° a à confirmer (annexe B, point 5) |
| V23 | `test_une_facture_a_une_entite_publique_reste_hors_plateforme_avec_un_message` | Mairie, 1 000 × 1,00 € | 833,33 / 166,67 / 1 000,00 | S | — | Chorus Pro hors de PILIQI |
| V24 | `test_une_facture_a_un_professionnel_non_assujetti_va_dans_l_e_reporting_des_particuliers` | Association française non assujettie, 10 Gourdes | 100,00 / 20,00 / 120,00 (O2) | S | — | Particuliers |
| V25 | `test_une_facture_demandee_par_un_particulier_garde_le_circuit_des_particuliers` | Particulier, case cochée, 2 Câbles | 66,66 / 13,34 / 80,00 ; série des factures | S | — | Particuliers, jamais en facture électronique |
| V26 | `test_l_avoir_de_deux_livraisons_intracommunautaires_herite_de_leurs_decisions` | Deux factures V05 au même client ; avoir rendant 3 Gourdes de chacune | 60,00 / 0,00 / 60,00 | K, héritée | 262 ter I | International sortant |
| V26b | `test_un_avoir_ne_melange_pas_deux_factures_de_circuits_differents` | Deux livraisons intracommunautaires, la copie du client « établi en France » sur l'une seulement | Refus : « … transmises par des circuits différents : faites deux avoirs » | — | — | — |
| V27 | `test_un_produit_soumis_a_accises_n_est_jamais_exonere_automatiquement` | V05 avec un produit « accises » | Nature refusée ; vente en France : 100,00 / 20,00 / 120,00 | S | — | International sortant |
| V28 | `test_un_magasin_de_martinique_vend_au_taux_du_departement` | Magasin 972, particulier, 2 Câbles à 8,5 % | 73,74 / 6,26 / 80,00 | S | — | Particuliers |
| V29 | `test_un_magasin_de_martinique_qui_expedie_en_metropole_est_refuse_avec_un_message` | Magasin 972, livraison à Paris | Refus `tva.depart_hors_du_dom_non_gere` (O6) | — | — | — |
| V30 | `test_l_export_emporte_par_le_client_n_est_pas_encore_propose` | Client US qui emporte, 1 000 × 1,00 € | 833,33 / 166,67 / 1 000,00, avec une information | S | — | International sortant |
| V31 | `test_un_service_au_lieu_fixe_par_la_loi_execute_en_allemagne_est_refuse` | Client DE, Installation sur site, réponse « hors de France » | Refus `tva.lieu_du_service_hors_de_france` ; jamais une TVA française | — | — | — |
| V32 | `test_une_carte_cadeau_ne_se_vend_pas_tant_que_son_traitement_n_existe_pas` | Produit « carte cadeau ou bon d'achat », facture puis caisse | Refus `tva.bon_d_achat_non_gere` ; en caisse, le produit ne s'ajoute pas au panier (T9 = a) | — | — | — |
| V33 | `test_la_fiche_d_un_produit_refuse_le_taux_zero_pour_une_societe_redevable` | Produit à 0 %, société redevable ; puis une ligne forcée à 0 % sur un brouillon | Fiche refusée (`tva.taux_zero_en_france`) ; émission refusée en filet de sécurité (T5 = a) | — | — | — |
| V34 | `test_un_client_etabli_en_france_dont_les_biens_partent_en_suisse_obtient_un_export` | Professionnel établi en France, lien ouvert, 1 000 × 1,00 € expédiés à Genève | 833,33 / 0,00 / 833,33 | G | 262 I | Facture électronique, retenue tant que G n'est pas prise en charge |
| V35 | `test_une_vente_en_france_livree_a_une_autre_adresse_imprime_l_adresse_de_livraison` | Professionnel établi en France, 10 Gourdes livrées à son entrepôt de Villeurbanne | 100,00 / 20,00 / 120,00 ; adresse de livraison imprimée | S | — | Facture électronique |
| V36 | `test_une_facture_en_france_melant_un_produit_et_un_service_s_emet` | Professionnel établi en France, 1 Câble + Paramétrage | 133,33 / 26,67 / 160,00 ; lignes 33,33 + 6,67, 100,00 + 20,00 | S | « Catégorie de l'opération : Livraison de biens et prestation de services » | Facture électronique |
| V37 | `test_une_emission_d_une_nature_proposee_impose_un_choix_qui_est_trace` | V12, « Émettre à la TVA française » | 100,00 / 20,00 / 120,00 ; geste « TVA française gardée » avec auteur et instant, signé | S | — | International sortant |
| V38 | `test_un_service_a_un_particulier_domicilie_hors_ue_est_signale` | Particulier à Genève, Paramétrage | 100,00 / 20,00 / 120,00 (article par article) ; avertissement `tva.service_hors_ue_a_verifier` | S | — | Particuliers |

Pour V38, le calcul article par article donne PU HT 100,00 € et PU TTC 120,00 €, donc les mêmes montants qu'un professionnel.

**Autres tests de vente**
- `test_un_vies_injoignable_a_l_emission_garde_le_brouillon_et_le_dit` : V05, VIES ne répond pas ; rien n'est émis ; message distinct de « ne reconnaît pas », avec la date limite du 15 du mois suivant ; aucune consultation enregistrée.
- `test_une_emission_rejouee_ne_consulte_pas_vies` : même jeton, facture déjà émise ; aucun appel à `RegistreVies`, aucune ligne dans `consultations_vies`.
- `test_le_refus_fiscal_arrive_avant_l_avertissement_de_vente_sans_stock` : facture avec un produit en rupture et une identité incomplète ; un seul refus, avec tous les manquements, sans demande de confirmation de vente sans stock.
- `test_un_brouillon_dont_le_calcul_a_change_montre_l_avant_et_l_apres` : taux de vente corrigé depuis un achat après l'enregistrement ; refus `tva.calcul_du_brouillon_change` avec l'avant et l'après ; « Mettre à jour et émettre » émet.
- `test_une_facture_issue_d_un_devis_reprend_ses_faits_et_son_geste` (O8) : devis d'une livraison intracommunautaire, faits et geste repris ; VIES du jour de la facture ; si VIES ne reconnaît plus le n°, refus avec la voie « Rester à la TVA française ».
- `test_la_derniere_facture_au_meme_client_est_proposee_et_se_confirme_a_l_emission` (T3) : la sortie des biens et la destination sont proposées avec leur source ; l'émission exige la confirmation.
- `test_une_livraison_anterieure_a_l_emission_sans_verification_a_sa_date_est_signalee` (O3).
- `test_la_demonstration_n_a_ni_circuit_ni_obligation_et_consulte_le_faux_vies` : magasin de démonstration, livraison intracommunautaire ; circuit « aucun », aucune obligation, `FauxRegistreVies` interrogé.
- Ventes à distance (lot M8) :
  - `test_une_societe_arrivee_en_octobre_avec_9500_euros_de_ventes_a_distance_est_refusee_au_dela_du_seuil` : valeur de départ de l'année en cours 9 500,00 € ; 16 Câbles à un particulier belge (HT 533,28 €) → refus (10 033,28 € > 10 000,00 €) ;
  - `test_une_annee_precedente_au_dela_du_seuil_refuse_toute_vente_a_distance` : valeur de départ de l'année précédente 12 000,00 € ;
  - `test_une_societe_inscrite_a_l_oss_ne_peut_pas_faire_de_vente_a_distance` (`tva.oss_non_gere`) ;
  - `test_un_vendeur_ne_voit_pas_le_montant_du_compteur` ; `test_le_proprietaire_voit_le_montant_du_compteur`.
- Relevé du mois (lot M4) : `test_le_releve_du_mois_somme_les_livraisons_intracommunautaires_nettes_des_avoirs_par_numero_de_tva` : livraison de 100,00 € à DE123456789, avoir de 30,00 € le même mois, prestation autoliquidée de 100,00 € à un client IE → 70,00 € et 100,00 € ; `test_le_releve_d_un_magasin_ignore_les_autres_magasins` ; si T7 = a, `test_le_releve_de_la_societe_somme_ses_magasins_reels_sans_la_demonstration`.

### 10.2 Matrice des achats

Société redevable, magasin de Lyon, produit « Gourde » vendu à 20 %, sauf mention contraire. Colonnes : HT ; TVA facturée ; TVA autoliquidée ; TVA déductible ; dû au fournisseur ; coût unitaire d'entrée ; catégorie attendue sur la facture reçue ; circuit (étape 2).

| # | Test | Faits | HT | Facturée | Autoliquidée | Déductible | Dû | Coût | Cat. | Circuit |
|---|---|---|---|---|---|---|---|---|---|---|
| A01 | `test_un_achat_en_france_garde_la_tva_facturee_deductible` | Fournisseur français redevable, 10 × 8,00 € à 20 % | 80,00 | 16,00 | 0 | 16,00 | 96,00 | 8,0000 | S | Facture reçue |
| A02 | `test_le_taux_facture_d_une_ligne_d_achat_ne_change_jamais_le_taux_de_vente_du_produit` | Idem, ligne à 5,5 % ; produit vendu à 20 % | 80,00 | 4,40 | 0 | 4,40 | 84,40 | 8,0000 | S | Idem ; taux de vente toujours 20 % |
| A03 | `test_un_fournisseur_francais_en_franchise_ne_facture_aucune_tva` | 10 × 8,00 € | 80,00 | 0 | 0 | 0 | 80,00 | 8,0000 | E | Facture reçue |
| A04 | `test_une_acquisition_intracommunautaire_autoliquide_la_tva_sans_rien_payer_au_fournisseur` | Spielwaren (n° DE), origine DE confirmée, 100 × 8,00 € + port du fournisseur 40,00 € | 840,00 | 0 | 168,00 | 168,00 | 840,00 | 8,4000 | K | International entrant (art. 290 I 3° a) |
| A05 | `test_les_frais_d_une_acquisition_se_repartissent_par_cumul_au_prorata_de_la_valeur` | 100 × 8,00 € + 50 × 4,00 € + port 40,00 € | 1 040,00 | 0 | 208,00 | 208,00 | 1 040,00 | 8,3200 et 4,1600 | K | Idem |
| A06 | `test_une_facture_de_l_ue_portant_une_tva_etrangere_suit_la_reponse_de_l_editeur` | Spielwaren, 100 × 8,00 € à 19 % | T15 = a : refus `tva.tva_etrangere_facturee`. T15 = b : 800,00 ; TVA allemande 152,00 à régulariser ; autoliquidée 160,00 ; déductible 160,00 ; dû 952,00 ; coût 8,0000 ; entrée dans la liste « à régulariser » | | | | | | | |
| A07 | `test_une_acquisition_sans_numero_de_tva_de_la_societe_est_bloquee` | Société sans n° de TVA | Refus `tva.numero_tva_societe_absent` | | | | | | | |
| A08 | `test_l_origine_d_un_fournisseur_non_etabli_doit_etre_confirmee_avant_la_validation` | Fournisseur DE, origine proposée, non confirmée | Refus `tva.origine_non_confirmee` ; préremplissage depuis la dernière facture validée, source affichée | | | | | | | |
| A09 | `test_le_pays_du_fournisseur_seul_ne_fait_pas_une_acquisition_intracommunautaire` | Fournisseur DE non établi, marchandises expédiées d'un entrepôt en France, 10 × 50,00 € | 500,00 | 0 | 100,00 | 100,00 | 500,00 | 50,0000 | AE | International entrant (art. 290 I 3° b) |
| A10 | `test_un_fournisseur_etranger_etabli_en_france_facture_la_tva_francaise` | Fournisseur DE, case « établissement en France » cochée, 10 × 50,00 € à 20 % | 500,00 | 100,00 | 0 | 100,00 | 600,00 | 50,0000 | S | Facture reçue |
| A11 | `test_une_importation_dedouanee_par_le_magasin_reprend_la_tva_de_la_declaration_et_ajoute_les_droits_au_cout` | Fournisseur CN, 100 × 6,60 € ; déclaration : valeur 660,00, droits 24,00, TVA 136,80 | 660,00 | 0 | 136,80 | 136,80 | 660,00 (+ 24,00 de droits à la douane) | 6,8400 | — | Aucun |
| A11b | `test_les_frais_jusqu_au_premier_lieu_de_destination_entrent_dans_la_base_de_controle_de_la_tva_d_importation` | A11 + frais accessoires Le Havre–Lyon 50,00 ; TVA déclarée 146,80 | TVA attendue (660,00 + 24,00 + 50,00) × 20 % = 146,80 : **aucun avertissement** | | | | | | | |
| A12 | `test_un_ecart_entre_la_tva_d_importation_saisie_et_la_tva_attendue_est_signale` | A11, TVA saisie 136,00 € | Avertissement `tva.ecart_tva_importation` (attendue 136,80 €) ; montant de la déclaration retenu | | | | | | | |
| A13 | `test_une_importation_validee_avant_sa_declaration_en_douane_corrige_son_cout_ensuite` | Validation sans déclaration (T12 = a), puis droits de 24,00 € | Coût 6,6000, puis 6,8400 ; coût moyen recalculé par le rejeu ; aucun mouvement modifié ni ajouté ; liste « à compléter » vidée | | | | | | | |
| A14 | `test_une_marchandise_hors_ue_dedouanee_par_un_fournisseur_non_etabli_est_autoliquidee` | Fournisseur CN, dédouané par lui, 10 × 50,00 € | 500,00 | 0 | 100,00 | 100,00 | 500,00 | 50,0000 | AE | International entrant (art. 290 I 3° b) |
| A15 | `test_un_dedouanement_inconnu_bloque_la_validation_d_un_achat_hors_ue` | Origine CN, dédouanement non dit | Refus `tva.dedouanement_inconnu` | | | | | | | |
| A16 | `test_un_service_d_un_prestataire_de_l_ue_est_autoliquide_et_hors_stock` | Agence IE, 1 × 1 000,00 €, « autre service » | 1 000,00 | 0 | 200,00 | 200,00 | 1 000,00 | hors stock | AE | International entrant (art. 290 I 3° c) |
| A17 | `test_un_service_d_un_prestataire_hors_ue_est_autoliquide` | Éditeur US, 300,00 € | 300,00 | 0 | 60,00 | 60,00 | 300,00 | hors stock | — | International entrant |
| A18 | `test_un_service_etranger_au_lieu_fixe_par_la_loi_passe_en_saisie_manuelle` | Hôtel à Berlin | Refus `tva.cas_exclu`, sauf saisie manuelle autorisée (T11) | | | | | | | |
| A19 | `test_un_transport_facture_par_un_transporteur_francais_entre_dans_le_cout_des_marchandises_rattachees` | A04 sans port ; transporteur français 40,00 € HT + 8,00 € rattaché (T14 = a) | Transport : 40,00 | 8,00 | 0 | 8,00 | 48,00 | Marchandises 8,4000 | S | Facture reçue |
| A19b | `test_un_transport_exonere_lie_a_une_importation_entre_dans_le_cout` | A11b ; transporteur français 50,00 € à 0 %, raison « importation », rattaché | Transport : 50,00 | 0 | 0 | 0 | 50,00 | Marchandises 7,3400 | E | Facture reçue |
| A20 | `test_un_fournisseur_monegasque_dont_les_biens_viennent_de_monaco_releve_de_l_e_reporting` | Fournisseur MC, **origine Monaco**, 10 × 8,00 € à 20 % | 80,00 | 16,00 | 0 | 16,00 | 96,00 | 8,0000 | S | E-reporting (art. 290 I 4° a) |
| A20b | `test_un_fournisseur_monegasque_dont_les_biens_partent_de_france_facture_la_tva_francaise` | Fournisseur MC, origine France | 80,00 | 16,00 | 0 | 16,00 | 96,00 | 8,0000 | S | À confirmer (annexe B, point 5) |
| A21 | `test_une_societe_en_franchise_porte_la_tva_d_un_achat_dans_son_cout` | Franchise, 10 × 8,00 € + 20 % | 80,00 | 16,00 | 0 | 0 | 96,00 | 9,6000 | S | Facture reçue |
| A22 | `test_une_acquisition_d_une_societe_en_franchise_suit_la_reponse_de_l_editeur` | Franchise, Spielwaren | Refus `tva.achat_etranger_en_franchise_non_gere` si T10 = a | | | | | | | |
| A23 | `test_un_achat_en_devise_sans_taux_de_change_est_bloque` | USD sans taux (T16 = a) | Refus `tva.devise_sans_taux` | | | | | | | |
| A24 | `test_l_avoir_fournisseur_d_une_acquisition_reprend_l_autoliquidation_et_sort_au_cout_d_entree` | Retour de 30 sur A04 | −240,00 | 0 | −48,00 | −48,00 | −240,00 | Sortie 30 × 8,4000 = 252,00 | K | International entrant |
| A25 | `test_l_avoir_fournisseur_en_france_reprend_la_tva_facturee` | Retour de 2 sur A01 | −16,00 | −3,20 | 0 | −3,20 | −19,20 | Sortie 2 × 8,0000 = 16,00 | S | Facture reçue |
| A26 | `test_l_api_d_achat_cree_un_brouillon_et_refuse_la_validation_avec_tous_les_codes` | Brouillon sans origine ni n° de facture du fournisseur | Brouillon créé ; validation refusée avec `tva.origine_non_confirmee` et `achat.numero_fournisseur_absent` ; aucun stock, aucun numéro | | | | | | | |
| A27 | `test_la_validation_fige_la_decision_et_une_modification_la_remplace_avec_l_ancienne_au_journal_d_audit` | Validation, puis modification avec l'autorisation | `decision_tva` remplacée ; journal d'audit : `FactureAchatValidee`, puis `FactureAchatModifiee` avec l'ancienne décision | | | | | | | |
| A28 | `test_un_fournisseur_de_l_ue_au_numero_invalide_sur_vies_n_est_qu_un_avertissement` | A04, VIES du fournisseur invalide | Avertissement `tva.vies_fournisseur_invalide` ; montants de A04 | | | | | | | |
| A29 | `test_la_tva_autoliquidee_d_un_taux_se_calcule_sur_son_total_et_se_repartit_par_cumul` | Acquisition, lignes 1 × 0,83 € et 3 × 1,11 € à 20 % (T13 = a) | 4,16 | 0 | 0,83 (0,17 + 0,66, et non 0,84) | 0,83 | 4,16 | — | K | International entrant |
| A29b | `test_les_retours_d_une_ligne_autoliquidee_ne_rendent_jamais_plus_que_sa_part` | Retour de 2 articles de la 2e ligne de A29, puis du dernier | 1er avoir : −2,22 HT, autoliquidée −0,44 ; 2e avoir : −1,11 HT, autoliquidée −0,22 ; total rendu 0,66 = part de la ligne | | | | | | | |
| A31 | `test_un_fournisseur_belge_qui_expedie_d_allemagne_est_une_operation_triangulaire_possible` | n° BE, origine DE | Refus `tva.operation_triangulaire_possible`, saisie manuelle (T11) | | | | | | | |
| A32 | `test_une_ligne_a_zero_d_un_fournisseur_francais_demande_pourquoi` | Fournisseur français, ligne à 0 %, sans réponse ; puis réponse « La facture porte « Autoliquidation » » | Refus `tva.taux_zero_sans_raison` ; puis cas exclu (T11) | | | | | | | |
| A33 | `test_un_fournisseur_francais_qui_expedie_d_allemagne_est_un_cas_a_verifier` | Fournisseur établi en France, origine DE | Refus `tva.faits_contradictoires`, saisie manuelle | | | | | | | |
| A34 | `test_une_importation_dedouanee_par_le_magasin_aupres_d_un_fournisseur_francais` | Fournisseur établi en France, origine CN, dédouané par nous, 100 × 6,60 € sans TVA ; déclaration : droits 24,00, TVA 136,80 | 660,00 | 0 | 136,80 | 136,80 | 660,00 (+ 24,00 de droits) | 6,8400 | — | Aucun |
| A35 | `test_un_fournisseur_a_l_adresse_etrangere_n_est_pas_etabli_en_france_par_defaut` | Fournisseur créé avec le pays DE, sans la case ; puis par l'API sans la valeur | « Établi en France » faux dans les deux cas ; pays FR : vrai | | | | | | | |
| A36 | `test_un_achat_valide_en_franchise_puis_modifie_apres_le_passage_au_reel_garde_la_non_deduction` | Franchise jusqu'au 31/10/2026, réel dès le 01/11 ; facture du 20/10, 10 × 8,00 € + 20 % ; modifiée le 05/11 en 12 articles | 96,00 | 19,20 | 0 | 0 | 115,20 | 9,6000 | S | Facture reçue |
| A37 | `test_le_cout_d_une_facture_avec_un_avoir_fournisseur_ne_se_corrige_plus` | A13, avoir fournisseur émis avant la déclaration | Refus `tva.cout_fige_par_un_avoir_fournisseur` | | | | | | | |
| A38 | `test_deux_factures_d_un_meme_arrivage_portent_la_meme_declaration_avec_leur_part` | Même référence de déclaration sur deux factures, chacune sa part | Contrôle de la TVA facture par facture ; aucun refus | | | | | | | |
| A39 | `test_un_taux_d_achat_inhabituel_est_signale` | Ligne à 7 % en métropole | Avertissement `tva.taux_inhabituel` | | | | | | | |
| A40 | `test_la_saisie_manuelle_d_un_achat_exige_l_autorisation_dediee_et_un_motif` | A18 ; un responsable, puis le propriétaire | Responsable refusé ; propriétaire : décision `saisieManuelle`, journal d'audit `TvaDAchatSaisieALaMain` | | | | | | | |
| A41 | `test_un_service_etranger_achete_en_franchise_est_autoliquide_sans_deduction` | Franchise, agence IE, 1 000,00 € (T10 = a) | 1 000,00 | 0 | 200,00 | 0 | 1 000,00 | hors stock (charge 1 200,00) ; TVA à payer à l'État 200,00 | AE | International entrant |
| A42 | `test_le_dedouanement_d_une_facture_recue_par_l_api_se_complete_sans_modifier_ses_lignes` | Facture source API, déclaration complétée après validation (T12 = a) | Coût recalculé ; lignes inchangées ; `FactureAchat::estModifiable()` toujours faux | | | | | | | |

L'exigibilité d'une acquisition (le 15 du mois suivant ou la date de facture antérieure) se teste à l'étape 5 (8.2), quand la date de réception y est demandée.

Mentions attendues sur la facture reçue (rappel dans le panneau, avertissement seulement pour une facture électronique reçue, jamais un refus de déduction, `reglementation.md` 5) : S, mentions générales ; E, « TVA non applicable, art. 293 B du CGI » ; K, n° de TVA du fournisseur et de la société, et mention d'exonération ; AE, « Autoliquidation » ; importation, aucune mention française.

### 10.3 Stock

- `test_la_valeur_du_stock_s_affiche_au_cout_sans_tva` (O1) : 10 articles à 8,4000 € valent 84,00 €. Aujourd'hui, la tuile afficherait 100,80 € TTC (`app/Filament/App/Resources/ProduitResource/Widgets/ValeurStockApercu.php:59-64`).
- `test_une_reception_met_le_cout_moyen_au_cout_d_entree_de_la_ligne_sans_ecrire_de_cout_sur_le_mouvement` : A04 ; coût moyen 8,4000 € ; le mouvement de réception n'a aucun coût.
- `test_le_cout_moyen_d_une_societe_en_franchise_comprend_la_tva_non_deductible` : A21, coût moyen 9,6000 €.
- `test_un_retour_fournisseur_sort_au_cout_d_entree_de_sa_ligne_et_recalcule_le_cout_moyen` : 100 articles à 8,4000 € (acquisition) puis 100 à 10,0000 € (France), coût moyen 9,2000 € ; retour de 30 articles de l'acquisition à 8,4000 € ; 170 articles valent 1 840,00 − 252,00 = 1 588,00 €, coût moyen 9,3412 €.
- `test_la_correction_d_une_declaration_en_douane_recalcule_le_cout_moyen_sans_toucher_au_grand_livre` : A13.
- `test_le_cout_fige_par_un_avoir_fournisseur_n_est_jamais_corrige` : A37.

### 10.4 Non-régression des montants

- **Des cas explicites, aux montants écrits à la main**, versionnés : V01 à V04, V17, V28, V36, A01, A02, A05. Avec `tests/Unit/CalculateurLigneTest.php`, `RepartitionTvaParCumulTest` et `NatureOperationTest`, ils fixent déjà les montants au centime.
- **Une comparaison avant et après, une seule fois**, pendant le lot M2 (ventes) puis le lot M5 (achats) : le code d'avant et le code d'après calculent les mêmes documents tirés au hasard, hors du dépôt. Le résultat (nombre de documents, aucun écart) est noté dans la relecture du lot. Rien n'est versionné : aucune trace d'un état antérieur (CLAUDE.md:146).
- `NatureOperationTest` reste au niveau des actions ; seule la classe de l'exception attendue change (4.3).

### 10.5 Versionnement par date

- `test_une_mention_se_choisit_selon_la_date_de_l_operation` : avec un faux catalogue où le texte change le 1/1/2027, une facture du 31/12/2026 imprime l'ancien texte, une du 2/1/2027 le nouveau.
- `test_un_duplicata_imprime_la_mention_figee_meme_apres_un_changement_de_regles` (D5).
- `test_un_taux_qui_n_est_plus_en_vigueur_a_la_date_de_l_operation_est_signale`.
- `test_chaque_decision_porte_la_version_du_moteur_et_celle_des_regles`.
- `test_modifier_les_donnees_des_regles_sans_changer_leur_version_fait_echouer_la_suite` (empreinte).
- `test_un_avoir_emis_apres_un_changement_de_regles_garde_la_nature_de_sa_facture_et_prend_les_mentions_de_son_jour`.
- `test_le_regime_de_la_societe_se_lit_a_la_date_de_l_operation` (O7) : franchise jusqu'au 31/10/2026, réel dès le 01/11 ; un brouillon émis le 02/11 est à la TVA ; A36.

### 10.6 Pureté et cohérence

- `test_le_moteur_rend_la_meme_decision_pour_les_memes_faits`.
- `test_le_moteur_ne_lit_ni_la_base_ni_l_horloge` : aucune requête (`DB::listen`) ; changer l'heure de test (`Carbon::setTestNow`) ne change rien.
- `test_une_decision_figee_se_relit_a_l_identique_sous_postgresql` : colonne `json`, ordre des clés gardé.
- `test_le_panneau_et_l_emission_donnent_la_meme_decision`.
- `test_aucune_consultation_vies_n_a_lieu_sous_le_verrou_du_registre`.
- `test_le_pays_seul_ne_change_jamais_la_nature_d_une_vente_ni_d_un_achat` : test paramétré qui, pour un client puis un fournisseur, ne change que le pays.
- `test_la_ventilation_signee_et_celle_des_ecrans_donnent_le_meme_resultat` (D3, 3.3).
- `test_une_consultation_vies_porte_exactement_un_client_ou_un_fournisseur` : contrainte CHECK sous PostgreSQL, déclencheurs sous SQLite ; une insertion directe hors du modèle est refusée par les deux moteurs.
- `test_aucun_code_technique_n_apparait_dans_les_pages` (P13) : les pages de facture, de caisse et de facture d'achat ne contiennent ni « VATEX », ni « 0223 », ni un nom d'énumération, ni une catégorie seule.

### 10.7 Isolation

- `test_les_decisions_d_achat_d_un_magasin_sont_invisibles_dans_un_autre`.
- `test_le_preremplissage_de_l_origine_ne_lit_que_les_factures_validees_du_magasin`.
- `test_la_suggestion_de_sortie_ne_lit_que_les_factures_du_magasin`.
- `test_les_consultations_vies_d_un_fournisseur_restent_dans_son_magasin`.
- `test_des_frais_ne_se_rattachent_qu_a_une_facture_d_achat_du_meme_magasin`.
- `test_le_compteur_des_ventes_a_distance_ne_lit_que_des_sommes_des_magasins_reels_de_la_societe` : démonstration et autre société exclues.
- `test_le_regime_de_tva_d_une_societe_est_invisible_d_une_autre` (`regimes_tva_societe`, `SocieteScope`).
- `test_chaque_modele_de_societe_est_liste_ici` (`tests/Feature/Tenancy/IsolationDesSocietesTest.php:74`) complété par `RegimeTvaSociete`. Aucun nouveau modèle de magasin : `test_chaque_modele_de_magasin_est_liste_ici` (`tests/Feature/Tenancy/TenantIsolationTest.php:211`) ne change pas.

### 10.8 Écrans

| Lot | Test |
|---|---|
| M4 | `test_une_vente_a_un_particulier_en_france_n_affiche_aucune_question` |
| M4 | `test_une_facture_a_un_professionnel_etabli_en_france_n_affiche_que_la_ligne_tva_francaise_et_le_lien` |
| M4 | `test_un_client_professionnel_allemand_fait_apparaitre_la_question_de_sortie_des_marchandises` |
| M4 | `test_le_lien_expedier_hors_de_france_ouvre_les_questions_pour_un_client_etabli_en_france` |
| M4 | `test_le_pays_du_client_seul_ne_propose_jamais_d_exoneration` |
| M4 | `test_une_nature_appliquee_d_office_affiche_le_nouveau_total_et_le_lien_rester_a_la_tva_francaise` |
| M4 | `test_une_proposition_affiche_le_total_qu_aurait_la_vente` |
| M4 | `test_rester_a_la_tva_francaise_est_toujours_possible_et_se_trace` |
| M4 | `test_une_exoneration_dont_une_condition_manque_ne_peut_pas_etre_forcee` |
| M4 | `test_un_refus_d_emission_dit_tous_les_manquements_identite_comprise` |
| M4 | `test_vies_injoignable_et_vies_invalide_affichent_deux_messages_differents` |
| M4 | `test_la_question_du_lieu_d_execution_apparait_pour_un_service_au_lieu_fixe_et_un_client_etranger` |
| M4 | `test_le_bouton_deplacer_ces_lignes_cree_une_nouvelle_facture` |
| M4 | `test_un_devis_exonere_incomplet_s_envoie_avec_un_avertissement` |
| M4 | `test_l_ecran_d_avoir_grise_les_factures_incompatibles` |
| M4 | `test_la_caisse_n_affiche_aucune_question_pour_un_ticket` |
| M4 | `test_la_caisse_avertit_pour_des_services_a_un_professionnel_etabli_hors_de_france` |
| M4 | `test_la_caisse_refuse_d_ajouter_une_carte_cadeau_au_panier` |
| M4 | `test_la_fiche_produit_refuse_le_taux_zero_pour_une_societe_redevable` |
| M4 | `test_aucun_code_technique_n_apparait_dans_les_pages` |
| M6 | `test_un_achat_a_un_fournisseur_francais_demande_les_memes_champs_qu_aujourd_hui` |
| M6 | `test_le_taux_facture_suit_le_taux_de_vente_tant_qu_on_ne_le_change_pas` |
| M6 | `test_l_origine_des_marchandises_s_affiche_des_qu_il_y_a_un_produit_et_vaut_france_pour_un_fournisseur_francais` |
| M6 | `test_un_achat_a_un_fournisseur_allemand_demande_l_origine_preremplie_depuis_sa_derniere_facture_validee` |
| M6 | `test_hors_ue_ouvre_la_question_du_dedouanement_quel_que_soit_le_fournisseur` |
| M6 | `test_une_ligne_a_zero_d_un_fournisseur_francais_ouvre_la_question_pourquoi` |
| M6 | `test_un_fournisseur_non_etabli_ouvre_la_question_de_la_tva_portee_par_la_facture` |
| M6 | `test_un_achat_ordinaire_en_france_garde_une_seule_ligne_de_totaux` |
| M6 | `test_une_acquisition_affiche_la_tva_a_payer_a_l_etat_et_le_cout_d_entree` |
| M6 | `test_la_fiche_fournisseur_coche_etabli_en_france_seulement_pour_un_pays_en_france` |
| M6 | `test_l_avertissement_sans_siret_s_affiche_sur_la_fiche_du_fournisseur_et_pas_sur_la_facture` |
| M7 | `test_le_champ_rattacher_a_ne_propose_que_les_factures_validees_du_magasin` |
| M7 | `test_la_liste_dedouanement_a_completer_compte_les_factures_et_notifie_les_valideurs` |
| M8 | `test_un_vendeur_ne_voit_que_l_etat_du_seuil_des_ventes_a_distance` |

---

## 11. Plan de lots

### 11.1 Place dans la feuille de route

La chaîne société tourne en ce moment : le lot A4 est en cours, puis A5, A6, A7 et le lot 21 étendu. Le moteur touche des fichiers que ces lots modifient :
- `EmettreFactureAction` : ordre des contrôles au lot A6 (`societe/conception-v2-finale.md` 8.6) ;
- `ConstructeurLigneDocument` et `services` : prix de référence de R2 au lot A6 ;
- actions de vente : jeton R3 au lot A6 ;
- autorisations : registre du lot A4.

**Aucun lot du moteur ne commence avant la fin du lot 21**, pour ne jamais travailler en parallèle sur les mêmes fichiers. Les lots M1 et M2 ne changent aucun résultat : ils commencent **dès la fin du lot 21, sans attendre les réponses**. Ordre proposé (T1 = a) :

```
A4 → A5 → A6 → A7 → lot 21 étendu (il ne coche plus l'étape 1)
  → M1 (calculatrice et VIES dans le domaine) → M2 (moteur des ventes, sans changer un résultat)
  → C-a (compléments 1, 2, 3, 4, 6, 16)
  → M0 (règles écrites, dès les réponses reçues)
  → M3 (profils, géographie, circuit, gestes ; format signé changé une seule fois)
  → C-b (compléments 13 à 15 : facture demandée, « Transformer en facture »)
  → M4 (écrans de vente : panneau, questions, relevé du mois)
  → M5 (achats : schéma et moteur) → M6 (achats : écrans et API) → M7 (coût et valeur du stock)
  → M8 (ventes à distance) → étape 1 cochée
  → étape 1 bis (campagne de tests initiale)
  → étape 2 (SUPER PDP lit les décisions) → M9 (OSS, export emporté, DOM, cartes cadeaux, si confirmés)
  → étapes 3, 4, puis 5 (comptabilité lit les décisions) ; étape 7 (sorties de stock hors vente)
```

**Amendement du lot 21** : son critère de fin dit « étape 1 cochée » (`societe/conception-v2-finale.md` l.1367). Si T1 = a, l'étape 1 n'est cochée qu'à la fin du lot M8, après C-a, C-b et les lots du moteur. Le texte du lot 21 est à corriger quand la chaîne société l'atteindra.

Règles communes à chaque lot :
- lire `.ai/rules/index.md` s'il existe à ce moment-là, et les règles qui couvrent les fichiers touchés ;
- relire chaque fichier avant de le modifier ;
- tests ciblés pendant le travail ;
- `migrate:fresh`, puis les deux suites, SQLite puis PostgreSQL, avec un code de sortie 0 contrôlé juste après ;
- `vendor/bin/pint --dirty --format agent` ;
- relecture adversariale, commit, push, CI verte.

Un lot peut se faire en plusieurs commits, chacun vert sur les deux moteurs.

### 11.2 Fusion avec le lot de compléments 1 à 16

| Point | Sujet | Lot | Raison |
|---|---|---|---|
| 1 | Réimputer le crédit d'avoir à la contre-passation | C-a | Indépendant |
| 2 | Correction immédiate d'un produit existant depuis un achat (texte de CLAUDE.md) | C-a ; comportement gardé par M6 (bloc « Vente en magasin ») | — |
| 3 | Droit de créer des produits à la validation d'un achat | C-a ; gardé par M5 (`achat.droit_de_creer_des_produits`) | — |
| 4 | Preuves : annulation tracée, « établie par » obligatoire, deux parties différentes | C-a, avec l'indépendance des émetteurs vis-à-vis du magasin et du client (7.4) ; M4 lit les preuves attendues dans la table datée | Touche `PreuvesRequises`, pas le moteur |
| 5 | Brouillon d'export rouvert après le passage en franchise : docblock | M2 | La règle passe dans le moteur |
| 6 | Détaxe : alerte au retour d'un article détaxé | C-a | Indépendant (bordereau) |
| 7 | CLAUDE.md, étape 2 : e-reporting international | M0 | Texte |
| 8 | Clôtures et factures aux professionnels (étape 3) | M0 (texte) ; M3 donne le circuit de chaque document | L'agrégat suivra le circuit, pas le type de client |
| 9 | Assujetti, entité publique, circuit sur trois conditions | M3 | Cœur du moteur |
| 10 | Couverture de l'art. 290 ; opérations des art. 261 à 261 E refusées ou signalées | M3 (circuit) et M4 (T5) | — |
| 11 | Calendrier tenu par SUPER PDP | Étape 2 (phase 6 du plan) | Rien dans le moteur |
| 12 | Achats à l'étranger | M5, M6, M7 | Cœur du moteur |
| 13, 14, 15 | Facture demandée par un particulier, « Transformer en facture » | C-b, juste après M3 | Ils supposent la série, le circuit et la clôture séparés (C6) |
| 16 | Retours sur tickets : autorisation | C-a | Indépendant |

### 11.3 Les lots

**M1 : calculatrice et VIES dans le domaine** (aucun changement de schéma ni de résultat)
- `CalculateurLigne` déplacée dans `app/Domain/Tva/Calcul/`, règles inchangées, avec son API en centimes entiers.
- `RegistreVies` (interface), `ClientVies` derrière elle, `FauxRegistreVies` ; liaison du faux dans le magasin de démonstration.
- `AssurerUneVerificationViesDuJourAction`, appelée par `EmettreFactureAction` hors transaction, après la recherche du jeton (2.8) ; `FactureResource::verifierLeNumeroTvaSurViesSiBesoin()` supprimée.
- Test d'égalité des deux ventilations (D3).
- Tests : `test_une_emission_rejouee_ne_consulte_pas_vies`, `test_un_vies_injoignable_a_l_emission_garde_le_brouillon_et_le_dit`, `test_aucune_consultation_vies_n_a_lieu_sous_le_verrou_du_registre`, test de la démonstration (VIES factice) ; lignes `use` des tests de 4.3.
- Fin : `git grep -n "verifierLeNumeroTvaSurViesSiBesoin"` vide ; suites vertes sans changement d'assertion.
- Relecture : un appel réseau sous verrou ; une consultation écrite pour une demande rejouée ; le vrai VIES appelé depuis la démonstration.

**M2 : moteur des ventes, sans changer un seul résultat**
- Créés : `app/Domain/Tva` (moteur, faits, décisions, `CalculDesLignesDeVente`, `CalculDesRetours`, règles de 2026 reprises mot pour mot, `GeographieTva` à comportement identique, `ExigenceDuNumeroTva`, énumérations, `DecisionTvaRefuseeException`) ; collecteurs de vente, de tiers et de société.
- Supprimés : `ContexteDeVente`, `ControleNatureOperation`, `NatureOperationRefuseeException`, `MentionsFiscales::pour()` et `calcul()`, `TerritoireFiscal`, `TerritoireTva`, les recodages des écrans.
- Modifiés : `ConstructeurLigneDocument` (traducteur), `EmettreFactureAction` (le moteur à l'étape 4 de 2.8, avant le stock), `PanierDeCaisse`, actions de devis et de facture, `LigneFacture`, `NoterBordereauDetaxeAction`, `AjouteDesLignesDeVente`, `FactureResource::lignesEntry()`, `Societe::mentionsLegalesManquantes()`, `RegimeTva` (docblock et mention). Le contenu signé ne change pas : les mentions se calculent encore au rendu, depuis la table datée.
- Tests : V01, V03, V04, V17, V21, V28, V36 ; 10.5 sauf O7 ; 10.6 sauf les tests de colonnes et d'écran ; `NatureOperationTest` (classe de l'exception), `ConstructeurLigneDocumentTest`, `TerritoireFiscalTest` et `TerritoireTvaTest` reportés (4.3) ; comparaison avant et après, une fois (10.4) ; `test_le_refus_fiscal_arrive_avant_l_avertissement_de_vente_sans_stock`.
- Fin : `git grep -n -E "ContexteDeVente|ControleNatureOperation|TerritoireFiscal|NatureOperationRefuseeException"` vide dans `app/`, `tests/` et `resources/` ; comparaison sans écart.
- Relecture : un centime qui bouge ; un écran qui garde une règle ; une lecture de base dans le moteur.

**C-a : compléments 1, 2, 3, 4, 6 et 16** (11.2).

**M0 : règles écrites** (aucun code), dès les réponses reçues. Dans CLAUDE.md : le principe du moteur unique ; les natures, l'application d'office et les gestes (« Ventes hors de France ») ; les natures d'achat, le taux d'achat distinct du taux de vente et le coût d'entrée (« Achats et stock » ; si leurs réponses arrivent plus tard, ces puces s'écrivent au début de M5) ; le circuit sur trois conditions et l'e-reporting international (« Facture électronique », compléments 7 et 9) ; les lectures de sommes de la société dans la liste exhaustive (T7) ; la clôture par circuit à l'étape 3 (complément 8) ; la place des lots (T1). Fin : le diff ne touche que ces puces, et chacune découle d'une réponse.

**M3 : profils, géographie, circuit et gestes** (compléments 9 et 10 ; C2, C3, C5, C6, C10 ; O2, O5, O6, O8)
- Migrations : `clients` (assujetti, entité publique) ; `factures`, `avoirs`, `devis` (sortie des biens, gestes, lieu des services, circuit, `decision_tva`, copies figées).
- `GeographieTva` : identification Sirene distincte du territoire de TVA ; départ des biens ; Monaco par le mouvement des biens ; magasin des DOM qui expédie.
- Circuit, E7, E13 ; `relevantDeLeInvoicing()` supprimée ; écouteur de transmission réécrit.
- **Tous les changements du format signé, d'un coup** : `ContenuFiscal`, `VerificateurIntegrite`, vues PDF (mentions figées), copie du client signée.
- `CreerAvoirAction` (compatibilité, circuit) ; `CreerFactureDepuisDevisAction` (O8) ; `NoteSaNatureDOperation` ; lieu des services et son refus ; commentaire de la migration des services et message de `ControleNatureOperation.php:220` corrigés.
- Tests : V02, V15, V16, V18, V22 à V24, V22b, V26, V26b, V29, V31, test de conversion d'un devis, matrice du circuit, réécritures de 4.3 (format signé, `transport_par`, circuit).
- Fin : `git grep -n -E "transport_par|relevantDeLeInvoicing"` vide dans `app/`, `tests/` et `resources/` ; un test de conversion d'un devis vérifie la copie des faits et du geste.
- Relecture : décision non signée ; duplicata qui recalcule ; clé JSON réordonnée sous PostgreSQL ; geste qui survit à un changement de client.

**C-b : compléments 13 à 15**, sur la séparation de la série, du circuit et de la clôture. Tests : V25 et ceux des compléments.

**M4 : écrans de vente**
- Panneau, questions progressives, lien « Expédier hors de France ou livrer à une autre adresse ? », application d'office, proposition et choix dans la fenêtre « Émettre » (T2), proposition de la dernière facture (T3), deux messages VIES, « Mettre à jour et émettre », menu « Changer de régime », « Déplacer ces lignes vers une nouvelle facture » (T6).
- `particularite_fiscale` des produits (accises, moyen de transport neuf, carte cadeau : T9) ; refus du taux 0 sur la fiche (T5) ; prix des services (T4) ; franchise et prestations hors de France (T10) ; avertissements (VIES après la livraison O3, service hors Union, vente à distance possible).
- Preuves attendues lues dans la table (`PreuvesRequises::typesAdmis()` supprimée) ; obligations ; relevé du mois (7.10, T7) ; écran d'avoir regroupé ; avertissement à l'envoi d'un devis ; fiche client (section « Fiscalité », liste de pays nommés) ; section « Régime de TVA » des documents émis.
- Tests : V05 à V14, V19 (sans compteur), V27, V30, V32 à V35, V37, V38, tests de M4 en 10.8, `test_aucun_code_technique_n_apparait_dans_les_pages`, relevé du mois, `ClotureCaisseResourceTest` si T5 = a (4.3).
- Relecture : une question posée pour une vente en France ; une bascule sans total affiché ; un code technique à l'écran ; un refus découvert à la caisse.

**M5 : achats, schéma et moteur** (complément 12 ; O7)
- Migrations : `regimes_tva_societe` (créée) et `societes` ; `fournisseurs`, `factures_achat`, `lignes_achat`, `avoirs_fournisseur` et leurs lignes, `consultations_vies` (fournisseurs, contrainte « exactement l'un des deux »).
- Moteur des achats (6.2 à 6.4, 6.6), collecteur d'achat ; `ConstructeurLigneAchat` réécrit (types de lignes, taux d'achat distincts, C1) ; validation avec tous les codes ; décision figée et journal d'audit ; avoirs fournisseur (T13) ; VIES des fournisseurs ; action d'achat de l'API ; `Emetteur` et aides de test sur le régime daté.
- Tests : A01 à A12, A14 à A18, A20 à A29b, A31 à A36, A38 à A41, isolation (10.7), O7 (10.5) ; comparaison avant et après des achats en France, une fois (10.4).
- Relecture : pays seul décisif ; TVA autoliquidée lue sur la facture ; coût écrit sur un mouvement ; régime lu à la date du jour au lieu de la date de l'opération.

**M6 : achats, écrans et API**
- `AjouteDesLignesDAchat` réécrit (deux blocs, taux facturé lié au taux de vente, question « Pourquoi ? », X8, frais et services) ; en-tête (origine toujours affichée, dédouanement, déclaration, TVA portée par la facture, total de contrôle) ; totaux (une ligne pour un achat ordinaire, détail sinon) ; panneau ; fiche fournisseur (règle du pays, SIRET) ; liste « TVA étrangère à régulariser » si T15 = b ; `BeneficeUnitaire`.
- Tests : tests de M6 en 10.8 ; `FactureAchatResourceTest` (C1, 4.3).
- Relecture : un champ de plus pour un achat français ; un écran qui calcule une TVA ; un fournisseur étranger traité comme établi en France.

**M7 : coût et valeur du stock** (C7, C8, C9 ; O1 ; T12, T14)
- `lignes_achat.facture_achat_rattachee_id` et l'écran « Rattacher à » ; raison « importation » d'une ligne à 0 % ; déclaration en douane complétée après la validation, sur toute source, refusée après un avoir fournisseur ; liste « Dédouanement à compléter » ; `ValeurStockApercu` au coût ; coût et bénéfice d'un franchisé ; renommages si O1 est accepté.
- Tests : A13, A19, A19b, A37, A42, 10.3, tests de M7 en 10.8.
- Relecture : un mouvement modifié ; un coût changé après un avoir ; un rattachement entre magasins.

**M8 : ventes à distance** (T7, T8)
- `CompteurDesVentesADistance` ; valeurs de départ et inscription à l'OSS sur la société ; avertissement à l'approche du seuil ; refus au-delà, franchise comprise ; clients non assujettis comptés ; montant visible du seul propriétaire et des administrateurs autorisés.
- Tests : V19, V19a, V19f, V20, V20a, V20f, tests des ventes à distance de 10.1, isolation du compteur, test d'écran de M8.
- Relecture : une vente à distance non comptée ; la démonstration comptée ; un montant visible d'un vendeur.

**Après M8** : étape 1 cochée, puis campagne de tests initiale (étape 1 bis), qui couvre les chemins définitifs d'émission et de validation.

**M9 : après l'étape 2, si l'éditeur le confirme** : OSS (7.5) ; export emporté par le client (7.6) ; natures des DOM (O6) ; cartes cadeaux (T9) ; acquisitions d'une société en franchise (T10) ; marqueur « service immatériel » (art. 259 B) si demandé.

**Ce qui attend l'étape 2** : catégories et VATEX dans les flux, E13 levée catégorie par catégorie après le bac à sable, e-reporting international sortant et entrant, agrégat des ventes aux particuliers par jour, catégorie et taux, statut « encaissée », suppression du calendrier interne, confirmation des règles du circuit avant la production.

**Ce qui attend l'étape 5** : rubriques vers les lignes de la CA3, écritures, état de TVA, FEC, exigibilité et date de déduction des achats (8.2), blocage éventuel de l'export comptable si une déclaration en douane manque.

**Ce qui attend l'étape 7** : la décision « sortie de stock hors vente » (8.3).

---

## 12. Questions à l'éditeur

Seulement des règles métier ou des choix de périmètre, classés par lot. Pour chacune : le contexte, les options, ma recommandation et sa raison. Ces questions sont numérotées T1 à T16 ; elles ne sont pas les questions T1 à T6 de la série 4 (campagne de tests), déjà répondues. L'annexe A dit à quelles questions des analyses précédentes elles répondent. Huit autres points, qui corrigent un défaut ou appliquent une règle déjà décidée, sont **appliqués sauf objection** (12.3).

### 12.1 Ordre d'urgence

| Question | Sujet | Nécessaire avant |
|---|---|---|
| T1 | Place du moteur dans la feuille de route | La fin du lot 21 |
| T2 | Le régime s'applique-t-il seul ? | M3 |
| T3 | Ce qui se demande à chaque document | M3 |
| T6 | Une seule nature par document | M3 |
| T4 | Prix d'un service vendu sans TVA française | M4 |
| T5 | Produit à 0 % vendu en France | M4 |
| T7 | Lectures de sommes entre magasins de la société | M4 |
| T9 | Cartes cadeaux et bons d'achat | M4 |
| T10 | Société en franchise : achats et ventes hors de France | M4 |
| T11 | Achats que le moteur ne calcule pas seul | M5 |
| T12 | Importation reçue avant la déclaration en douane | M5 |
| T13 | TVA d'un achat : calcul et retours | M5 |
| T14 | Frais d'approche dans le coût du stock | M5 |
| T15 | TVA étrangère facturée par un fournisseur de l'UE | M5 |
| T16 | Factures d'achat en devise | M5 |
| T8 | Quand construire l'OSS ? | M8 |

### 12.2 Les questions

**T1. Place du moteur dans la feuille de route.**
Le moteur change l'émission des ventes et la validation des achats. La campagne de tests initiale doit éprouver ces chemins dans leur forme définitive.
- **(a) Recommandée** : après la chaîne société et le lot 21, **avant** la campagne de tests initiale (étape 1 bis). M1 et M2 commencent dès la fin du lot 21 ; l'étape 1 est cochée à la fin du lot M8.
- (b) Après la campagne, avant l'étape 2.
- (c) Pendant l'étape 2.

Raison : l'étape 2 a besoin des catégories et du circuit ; tester deux fois des chemins qui vont changer coûte plus cher. La campagne est de toute façon relancée après l'étape 2.

**T2. Le régime s'applique-t-il seul ?**
Votre demande dit « calculer automatiquement la TVA ». Changer de régime change le prix payé par le client (K3) : la TVA française est retirée du prix.
- **(a) Recommandée** : le régime s'applique **de lui-même quand il découle de vos réponses** dans le document (« oui, nous expédions les marchandises en Allemagne », adresse, date), avec le nouveau total affiché et le lien « Rester à la TVA française ». Il est **proposé avec un bouton** quand aucune question n'a été posée (prestation à une entreprise étrangère). S'il est encore proposé au moment d'émettre, la fenêtre d'émission impose le choix et l'enregistre. Personne ne force une exonération dont une condition manque. En caisse, jamais.
- (b) Toujours proposer, et attendre un clic « Appliquer », même après les réponses.
- (c) Toujours appliquer seul, prestations comprises, sans clic.
- (d) Choix manuel dans une liste, comme aujourd'hui, avec les conditions vérifiées pendant la saisie.

Raison : (a) est le plus proche de votre demande sans bascule silencieuse, puisque le vendeur voit le nouveau total. Pour une prestation, rien dans le document ne dit que le client agit comme entreprise : un clic le confirme. C'est le seul écart avec « automatiquement ». (b) ajoute un clic à chaque export ; (c) changerait un prix sans prévenir.

**T3. Ce qui se demande à chaque document.**
Le pays ne suffit jamais : il faut savoir si les marchandises quittent la France (vente) et d'où elles viennent (achat). Ces deux faits changent la TVA.
- **(a) Recommandée** :
  - vente : « Les marchandises quittent-elles la France ? », sans réponse par défaut pour un client établi hors de France (ou avec un n° de TVA étranger, ou livré hors de France), exigée à l'émission ; la réponse de la dernière facture au même client est proposée, avec sa source, et se confirme d'un clic ; la date de livraison part de la date du jour ;
  - achat : l'origine des marchandises s'affiche toujours dès qu'il y a un produit ; « France » pour un fournisseur établi en France, sans clic ; sinon reprise de la dernière facture validée du même fournisseur ; confirmation exigée pour un fournisseur non établi, à sa première facture et à chaque changement.
- (b) Des valeurs par défaut : « Non » à la vente, le pays du fournisseur à l'achat.
- (c) Poser les deux questions à chaque document, sans rien préremplir.

Raison : (b) ferait décider le pays seul, ce que vous excluez ; (c) alourdit presque toutes les saisies. Une TVA française facturée à tort reste due, et le client étranger ne peut pas la récupérer.

**T4. Prix d'un service vendu sans TVA française.**
Pour un export, une livraison intracommunautaire ou une autoliquidation, un produit se vend à son prix TTC ÷ (1 + taux) (K3). Pour un service, le vendeur tape aujourd'hui un prix « sans TVA ».
- **(a) Recommandée** : comme un produit : le client paie le prix de référence TTC du service ÷ (1 + taux), sans que cela compte comme une dérogation de prix. Le prix tapé à la main disparaît.
- (b) Un vendeur autorisé (R2) tape le prix sans TVA à chaque fois.

Raison : une seule règle pour les produits et les services, et un vendeur sans autorisation peut vendre le service à l'étranger.

**T5. Produit à 0 % vendu en France.**
Il n'existe pas de taux zéro en France, et PILIQI ne gère pas les ventes exonérées des art. 261 à 261 E. Aujourd'hui, le taux se saisit librement (B2), 0 compris.
- **(a) Recommandée** : pour une société redevable, refuser le taux 0 dès l'enregistrement de la fiche du produit ou du service, avec un message ; refuser aussi l'émission, en filet de sécurité.
- (b) Laisser vendre, avec un avertissement ; le document n'est jamais transmis (règle E13 actuelle).

Raison : une ligne à 0 % en France n'a aucune catégorie légale et fausserait la déclaration. Refuser sur la fiche évite de découvrir le problème en caisse, devant le client. Cela restreint B2 pour la seule valeur 0. Un seul test existant émet un tel document ; il prendrait le taux de 5,5 %.

**T6. Une seule nature par document.**
La norme européenne de la facture n'interdit le mélange que pour une prestation hors champ. Elle permet, par exemple, une livraison intracommunautaire et une prestation autoliquidée sur la même facture.
- **(a) Recommandée** : une nature par document. Une vente en France qui mêle produits et services s'émet normalement. Dans un export, une livraison intracommunautaire ou une autoliquidation, une ligne que la nature n'admet pas est refusée, avec un bouton « Déplacer ces lignes vers une nouvelle facture ».
- (b) Autoriser plusieurs natures sur une facture : une nature et un panneau par ligne.

Raison : (a) garde un régime, une mention et une transmission par document, plus simples à lire et à signer. Le cas est rare en magasin et coûte un clic. (b) multiplierait les contrôles et les écrans.

**T7. Lectures de sommes entre magasins de la société.**
Le seuil des ventes à distance et les relevés de la douane se comptent pour la société (son SIREN). CLAUDE.md liste toutes les lectures permises hors du magasin courant ; celles-ci n'y sont pas.
- **(a) Recommandée** : les ajouter à la liste, limitées à des **sommes** (HT net des avoirs) : ventes à distance ; livraisons intracommunautaires et prestations autoliquidées par n° de TVA du client. Seuls les magasins réels comptent (démonstration exclue). Les montants ne se voient que du propriétaire et des administrateurs autorisés ; un vendeur ne voit que l'état du seuil.
- (b) Aucune lecture : le propriétaire saisit lui-même le cumul de la société et additionne les relevés des magasins.

Raison : un cumul saisi à la main se trompe ou s'oublie, et le seuil se franchit sans prévenir.

**T8. Quand construire l'OSS ?**
Au-delà de 10 000 € de ventes à distance par an dans l'Union, pour toute la société, la TVA du pays du client s'applique dès la vente qui fait franchir le seuil. Votre réponse I1 a placé l'OSS « ensuite », et votre demande dit « lorsque nécessaire ».
- **(a) Recommandée** : garder I1. Tout de suite (lot M8) : compteur de la société (particuliers et clients non assujettis, franchise comprise), deux valeurs de départ (année précédente, et année en cours avant l'arrivée dans PILIQI), question « inscrite au guichet OSS ? », avertissement dès 9 000 €, refus de toute expédition qui ferait dépasser le seuil. **Conséquence** : une fois 10 000 € atteints, plus aucune expédition à un particulier de l'Union jusqu'au 31 décembre, ni pendant toute l'année suivante, tant que l'OSS n'existe pas. L'OSS lui-même au lot M9, après l'étape 2.
- (b) L'OSS tout de suite, avant l'étape 2.
- (c) Jamais d'OSS : refus définitif au-delà du seuil.
- (d) Un simple avertissement (recommandation de l'analyse ergonomique) : la vente reste à la TVA française au-delà du seuil.

Raison : (a) ne facture jamais une TVA fausse et attend que SUPER PDP confirme, au bac à sable, comment ces ventes se déclarent. (d) laisserait facturer une TVA fausse. (b) oblige à tenir dès maintenant les taux des 27 pays par catégorie de produit.

**T9. Cartes cadeaux et bons d'achat.**
Une carte cadeau utilisable sur des produits à plusieurs taux est un « bon à usages multiples » : sa vente n'est pas taxée, seule son utilisation l'est (art. 256 ter du CGI). Aujourd'hui, PILIQI la taxerait à la vente comme un produit.
- **(a) Recommandée** : refuser la vente d'un produit marqué « carte cadeau ou bon d'achat » tant que le traitement n'est pas construit (lot M9).
- (b) Le construire tout de suite (lot M4) : bon à usage unique taxé à la vente ; bon à usages multiples vendu sans TVA, puis accepté comme moyen de paiement, comme le bon d'avoir.
- (c) Jamais : PILIQI ne vend pas de cartes cadeaux, et la notice le dit.

Raison : le bon à usages multiples change l'e-reporting et le registre de caisse, et le sort d'une carte non utilisée est à confirmer par l'expert-comptable. Refuser vaut mieux que mal taxer. (b) reste possible si vous en avez besoin tôt.

**T10. Société en franchise : achats et ventes hors de France.**
Une société en franchise ne vend aujourd'hui qu'en « vente en France » (C4). Ses achats à l'étranger dépendent d'un seuil de 10 000 € et de règles encore incertaines.
- **(a) Recommandée** :
  - ventes : les marchandises gardent la mention de franchise ; une prestation à une entreprise établie hors de France suit les règles de lieu (« Autoliquidation » dans l'Union, hors champ ailleurs), ce qui suppose un n° de TVA et une déclaration européenne de services ;
  - achats : les acquisitions dans l'Union et les importations sont refusées avec un message jusqu'à la confirmation de l'expert-comptable ; les services d'un prestataire étranger sont calculés (TVA française autoliquidée, non déductible, ajoutée à la charge), avec « TVA à déclarer même en franchise ».
- (b) Garder toutes ses ventes en « vente en France » et refuser tous ses achats à l'étranger.
- (c) Tout traiter comme pour une société redevable.

Raison : selon la recherche, la franchise couvre les livraisons de biens, mais pas une prestation imposable chez un client professionnel de l'Union. Pour les services achetés, la règle est simple et les sources concordent ; les acquisitions et les importations changent de montant selon un seuil encore à confirmer. À faire confirmer par votre expert-comptable.

**T11. Achats que le moteur ne calcule pas seul.**
Cela concerne l'autoliquidation du BTP ou des déchets, un service à l'étranger au lieu fixé par la loi (hôtel, restaurant, salon), une opération triangulaire, des produits soumis à accises, l'achat d'un magasin des DOM venu d'ailleurs (octroi de mer), un fournisseur de l'Union en franchise dans son pays.
- **(a) Recommandée** : saisie manuelle de la nature et des montants de TVA, avec une **autorisation dédiée** « saisir à la main la TVA d'un achat », cochée pour personne au départ (le propriétaire l'a de droit), un motif obligatoire et une inscription au journal d'audit.
- (b) La même saisie, avec l'autorisation « valider les factures d'achat », que les responsables ont.
- (c) Blocage pur : la facture ne se valide pas et le stock n'entre pas.

Raison : sans saisie manuelle, ces marchandises n'entrent jamais en stock. La saisie impose des montants de TVA déductible : elle mérite son autorisation propre, comme les remises (R2).

**T12. Importation : recevoir la marchandise avant la déclaration en douane ?**
Le transitaire envoie souvent ses papiers après la livraison. Sans la déclaration, les droits de douane ne sont pas encore dans le coût.
- **(a) Recommandée** : oui. La facture se valide et la marchandise se vend, avec un coût provisoire et une liste « Dédouanement à compléter ». Le coût se corrige quand la déclaration est saisie, même sur une facture reçue automatiquement (son contenu ne change pas), jamais après un avoir fournisseur sur cette facture.
- (b) Non : pas de validation sans la référence de la déclaration, les droits et la TVA d'importation.

Raison : bloquer empêcherait de vendre une marchandise déjà en rayon.

**T13. TVA d'un achat : calcul et retours.**
La TVA que la société déclare elle-même (autoliquidation) se déclare taux par taux. Calculée ligne par ligne, elle peut différer d'un centime de celle calculée sur le total.
- **(a) Recommandée** :
  - TVA facturée : recalculée ligne par ligne comme aujourd'hui, avec l'écart affiché face à la facture du fournisseur quand elle est connue ;
  - TVA autoliquidée : calculée une fois sur le total HT de chaque taux, puis répartie entre les lignes, comme pour les ventes (exemple : 0,17 € + 0,66 € = 0,83 €, et non 0,84 €) ;
  - retours : jamais plus que la part de la ligne ; un retour complet rend exactement sa part.
- (b) Les deux ligne par ligne, et les retours comme aujourd'hui.
- (c) TVA facturée reprise telle que la facture du fournisseur la donne, taux par taux ; TVA autoliquidée comme en (a).

Raison : la méthode des ventes, déjà validée, fait tomber juste la TVA de chaque taux, et la règle des retours empêche un avoir de reprendre plus de TVA que la facture n'en a déclaré. C'est une règle d'arrondi : elle vous revient.

**T14. Frais d'approche dans le coût du stock.**
Le transport, le transit, l'assurance et les droits de douane font partie de ce que coûte une marchandise.
- **(a) Recommandée** : ils entrent dans le coût du stock, répartis entre les produits au prorata de leur valeur HT, au centime près. Cela vaut aussi pour les frais facturés par un tiers (transporteur, transitaire), rattachés à la facture des marchandises du même magasin, une ligne de frais par facture rattachée.
- (b) La même chose, au prorata des quantités.
- (c) Seuls les frais facturés par le fournisseur des marchandises et les droits de douane entrent dans le coût ; les frais d'un tiers restent en charges.

Raison : la règle comptable compte dans le coût d'un stock tout ce qui est directement attribuable à son acquisition. Le prorata de la valeur évite de charger un article bon marché du transport d'un article cher.

**T15. TVA étrangère facturée par un fournisseur de l'UE.**
Un fournisseur de l'Union doit facturer sans sa TVA quand la société lui donne son n° de TVA français. S'il facture quand même la TVA de son pays, elle n'est pas déductible en France.
- (a) Bloquer la validation jusqu'à une facture rectifiée : la marchandise n'entre pas en stock.
- **(b) Recommandée** : valider avec une régularisation. La TVA étrangère est enregistrée (le montant dû au fournisseur est exact) comme « non déductible en France, à faire rectifier ou rembourser », hors du coût. La TVA française de l'acquisition est calculée quand même. Un avertissement fort s'affiche et la facture entre dans une liste « TVA étrangère à régulariser ».
- (c) Passer en saisie manuelle (T11).

Raison : comme pour T12, on ne bloque pas une marchandise déjà en rayon, et les montants restent exacts, avec un suivi visible. À faire confirmer par votre expert-comptable.

**T16. Factures d'achat en devise.**
La TVA se déclare en euros. La date du taux de change à retenir n'est pas encore confirmée.
- **(a) Recommandée** : taux de change saisi sur la facture (celui de la déclaration en douane pour une importation) ; HT de chaque ligne en devise × taux, arrondi une fois au centime ; TVA calculée sur ce HT en euros.
- (b) Taux officiel du jour récupéré automatiquement (Banque centrale européenne).
- (c) Euros seulement pour l'instant : une facture en devise est refusée.

Raison : un taux saisi, avec sa source, reste vérifiable. Faites confirmer la règle de date par votre expert-comptable.

### 12.3 Points appliqués sauf objection

| # | Point | Ce qui sera fait | Lot |
|---|---|---|---|
| O1 | Valeur du stock et coût d'une société en franchise | La tuile « Valeur du stock » affiche le coût d'achat (coût moyen × quantité), sans TVA ajoutée ; aujourd'hui elle ajoute une TVA au taux de vente, ce qui ne correspond à aucune valeur comptable (C7). Pour une société en franchise, le coût comprend la TVA qu'elle ne déduit pas, et le bénéfice affiché se calcule sur le prix encaissé. Les colonnes `cmup_ht` et `cout_unitaire_ht` deviennent `cout_moyen` et `cout_unitaire`. | M7 |
| O2 | Professionnel non assujetti et entité publique | Calcul comme pour tout professionnel (depuis le HT de ligne, E1). Une facture à un non-assujetti ne passe pas en facture électronique et entre dans l'e-reporting des ventes aux particuliers (art. 290 I 2° b). Une facture à une entité publique se dépose sur Chorus Pro, hors de PILIQI, avec un message. « Hors plateforme » (E7) se lit « hors facture électronique ». | M3 |
| O3 | Vérification VIES d'une livraison intracommunautaire | La décision I2 reste : n° valide le jour de l'émission. Un avertissement s'ajoute quand la livraison précède l'émission sans vérification faite à sa date. | M4 |
| O4 | Références légales imprimées après le 1/1/2027 | Les articles du CGI restent imprimés tant que les numéros du CIBS ne sont pas confirmés ; d'après des sources concordantes, ils restent admis jusqu'au 30/06/2028. Le passage au CIBS se fera par une nouvelle version des règles ; un document émis garde son texte. | M2 |
| O5 | Nom et adresse du client sur toute facture | Exigés à l'émission pour une facture à un professionnel, comme pour un particulier qui demande une facture (déjà décidé, complément 13). Ce sont des mentions obligatoires. | M3 |
| O6 | DOM | Un magasin de Guadeloupe, de Martinique ou de La Réunion qui expédie hors de son département est refusé avec un message (vente sur place et livraison locale seulement). Une prestation vendue depuis la métropole à un professionnel établi dans un DOM est refusée avec un message. Une nature dédiée viendra au lot M9. Refuser vaut mieux que mal taxer. | M3 |
| O7 | Régime de TVA de la société daté | Les paramètres fiscaux de la société disent « Statut de TVA » et « Depuis le ». Le moteur lit le régime en vigueur à la date de l'opération, pas celui du jour : un achat validé en franchise puis modifié après le passage au réel garde sa TVA non déductible. Un changement ne modifie jamais un document émis ; une date passée fait lister les documents émis et les achats validés depuis, à revoir avec l'expert-comptable. | M5 |
| O8 | Facture issue d'un devis | La facture reprend les faits du devis (sortie des biens, livraison, lieu des services) et son geste sur le régime. À son émission, le moteur réévalue avec la vérification VIES du jour ; si une condition est tombée, l'émission est refusée avec la voie « Rester à la TVA française ». | M3 |

---

## Annexe A : correspondance avec les questions des analyses précédentes

| Question précédente | Où elle est traitée |
|---|---|
| Q1 de l'inventaire (franchisé hors de France) | T10 |
| Q2 (taux d'achat séparé du taux de vente) | Pas de question : conséquence directe de la demande (TVA facturée et TVA due en France distinctes) ; le taux de vente reste corrigeable depuis la ligne (complément 2) |
| Q3 (valeur du stock, franchisé) | O1 |
| Q4 (professionnel non assujetti) | O2 |
| Q5 (magasin des DOM qui expédie) | O6 |
| Q6 (proposition automatique) | T2 |
| Q7 (qui force une décision) | T2 (ventes : personne) et T11 (achats : saisie manuelle) |
| Q8 (devise) | T16 |
| Q9 (frais d'approche) | T14 |
| Q10 (achat validé inaltérable ou modifiable) | Déjà décidé (C2, CLAUDE.md:172) : modifiable avec l'autorisation de valider ; chaque modification remplace la décision et inscrit l'ancienne au journal d'audit |
| Q11 (VIES à la date de livraison) | O3 |
| QE1, QE3 (proposition, rester en France) | T2 |
| QE2, QE4 (questions sans défaut, origine préremplie) | T3 |
| QE5 (importation avant la déclaration) | T12 ; blocage de l'export comptable à l'étape 5 (8.2) |
| QE6 (TVA facturée recalculée ou reprise) | T13 |
| QE7 (cas exclus) | T11 |
| QE8 (vente à distance : avertissement) | T8, option (d), avec l'écart dit |
| QE9 (services à un professionnel d'un DOM) | O6 |
| QE10 (services au comptoir à une entreprise étrangère) | Appliqué : avertissement en caisse (5.2) |
| QE11 (préremplir un client depuis Sirene) | Hors du moteur : fiche client |
| QE12 (entité publique proposée, non-assujetti jamais) | O2 |
| QE13 (prix d'un service exonéré) | T4 |
| QE14 (date de déduction des services achetés) | Étape 5 (8.2) |
| QE15 (acquisitions d'une société en franchise) | T10 |
| QE16 (adresse du client obligatoire) | O5 |
| QT1 (ventes à distance et OSS) | T8 (titre corrigé : « Quand construire l'OSS ? ») |
| QT2 (lecture entre magasins) | T7, avec le relevé du mois et la visibilité des montants |
| QT3 (prix payé sous OSS) | Reportée au lot M9 : elle ne compte que si l'OSS est construit |
| QT4 (proposition ou application) | T2, reformulée avec l'application d'office |
| QT5 (ce qui se demande à chaque fois) | T3 |
| QT6 (prix d'un service sans TVA française) | T4 |
| QT7 (achats non calculés seuls) | T11, avec une autorisation dédiée |
| QT8 (importation avant la déclaration) | T12, avec les factures reçues automatiquement |
| QT9 (calcul de la TVA d'un achat) | T13, avec les retours |
| QT10 (frais d'approche) | T14 |
| QT11 (valeur du stock) | O1 |
| QT12, QT13 (franchise : achats et ventes) | T10 |
| QT14, QT15 (DOM) | O6 |
| QT16 (non assujetti, entité publique) | O2 |
| QT17 (produit à 0 %) | T5, avec le refus sur la fiche |
| QT18 (VIES d'une livraison intracommunautaire) | O3 |
| QT19 (devise) | T16 |
| QT20 (références CGI ou CIBS) | O4 |
| QT21 (adresse du client) | O5 (le volet « particulier » était déjà décidé par le complément 13) |
| QT22 (place dans la feuille de route) | T1 |
| Nouvelles | T6 (une nature par document), T9 (cartes cadeaux), T15 (TVA étrangère facturée), O7 (régime daté), O8 (devis converti) |

## Annexe B : points à faire confirmer par l'expert-comptable

Ce ne sont pas des questions à l'éditeur, mais des vérifications à faire avant de coder la règle concernée. Elles viennent de `reglementation.md` 11, de la vérification de 1.4 et de cette conception.

1. Lignes de la déclaration CA3 sur la notice 2026 (n° 50449#29), pour la table de l'étape 5.
2. Exigibilité des acquisitions intracommunautaires quand la facture précède la livraison ; exigibilité des services autoliquidés (réalisation de la prestation selon l'art. 66, second alinéa, de la directive, non retrouvé en ligne).
3. Effet d'un escompte figurant sur la facture du fournisseur sur la base de la TVA déductible.
4. Fournisseur de l'Union en franchise dans son État, ou sans n° de TVA (A9) ; fournisseur de l'Union qui a facturé sa TVA (A10, T15).
5. Monaco : risque de double déclaration si le fournisseur émet par le réseau français ; circuit d'un achat à un fournisseur monégasque livré depuis la France ; e-reporting de l'art. 290 I 4° a en plus de la facture électronique pour des biens livrés à Monaco à un client établi en France.
6. Importation par une société en franchise ; octroi de mer d'un magasin des DOM.
7. Catégories des ventes aux particuliers : TNT1 pour l'export à un particulier, pour la franchise et pour une vente sous OSS (aussi au bac à sable).
8. Catégorie de la norme EN 16931 d'une livraison vers un DOM (G ou E).
9. Portée de la mention « option pour les débits » sur un document qui ne vend que des biens (le code l'imprime aujourd'hui sur tout document hors franchise, `app/Domain/Facturation/Services/MentionsFiscales.php:75-77` ; inchangé).
10. Frais accessoires d'un achat dont les marchandises ont plusieurs taux : ventilation par taux.
11. Date du taux de change d'un achat en devise (T16).
12. Garantie de TVA d'un export ou d'une livraison intracommunautaire emportés par l'acquéreur (avant le lot M9).
13. Numéros définitifs du CIBS pour les mentions, et décret attendu sur les mentions par nature d'opération (O4).
14. Échéances exactes de l'e-reporting (arrêté du 27/07/2026), utiles seulement pour vérifier SUPER PDP.
15. E-reporting des acquisitions non soumises d'une société en franchise sous le seuil (A6) : l'art. 290 I 4° b ne les vise pas (il renvoie au 258 D I, les opérations triangulaires) ; et circuit d'une opération triangulaire dont le magasin est le destinataire final (4° b ou 3° b).
16. Conditions des dispenses de reversement de la TVA déduite sur les marchandises disparues : destruction, vol, démarque inconnue (BOI-TVA-DED-60-30), pour l'étape 7.
17. Cartes cadeaux et bons d'achat : qualification d'un bon d'un magasin qui vend à plusieurs taux ; sort d'un bon non utilisé ou périmé (T9).
18. Preuve de l'inclusion des frais de transport dans la base de la TVA d'importation, pour la facture d'un transporteur à 0 % (art. 262 II 14°).
19. Fin de la franchise au jour du dépassement du seuil majoré : traitement des documents émis entre ce jour et la saisie du nouveau régime (O7).
20. Services de l'art. 259 B à un particulier domicilié hors de l'Union, si l'éditeur demande le marqueur « service immatériel » (lot M9).

## Annexe C : traitement des 54 défauts relevés par les trois relectures

Verdicts : **retenu** (corrigé comme proposé), **retenu, correction adaptée** (le défaut est réel, la correction diffère, raison donnée), **écarté en partie** (une partie du défaut n'est pas retenue, raison donnée). Aucun défaut n'est écarté en entier.

| # | Défaut | Verdict | Vérification et raison | Où |
|---|---|---|---|---|
| 1 | Prestation hors de France à un assujetti établi en France : « aucun circuit » | Retenu, correction adaptée | Art. 290 I 1° c (PDF, page 1) : aucune condition d'établissement ; FAQ (page 2). Le test d'e-reporting demandé n'est pas atteignable : PILIQI refuse ces prestations (7.3). Le test V31 vérifie le refus ; la règle du circuit est écrite (8.1, règle 7) et se codera avec la nature qui l'ouvrira. | 1.4 (2), 7.1, 8.1 |
| 2 | Art. 290 I 4° b attribué aux acquisitions d'un franchisé | Retenu | Art. 290 I 4° b (PDF, page 3) renvoie au 258 D I ; le 258 D I est la simplification des opérations triangulaires (BOI-TVA-CHAMP-20-40, vu en extrait). | 1.4 (6), 6.7, annexe B (15), T10 |
| 3 | Achat triangulaire classé en acquisition sans contrôle | Retenu | L'origine et le n° du fournisseur sont déjà des faits (2.3) ; la comparaison du préfixe (EL, XI) ne coûte rien. | 6.2, 5.6, A31, annexe B (15) |
| 4 | Vente à distance réduite aux particuliers | Retenu | Art. 14 § 4 de la directive et 258 A I 1° (extraits lus, 1.4, point 8). | 7.5, V08, V19a, V20a |
| 5 | Seuil des ventes à distance non appliqué à une société en franchise | Retenu | Recherche §1.5, ligne « International ». | 2.5 (9), 7.5, 7.8, V19f, V20f |
| 6 | Services au lieu fixé hors de France (259 A) et art. 259 B | Retenu pour le 259 A ; écarté en partie pour le 259 B | 259 A : art. 290 I 1° c et 2° d (PDF) ; la TVA facturée est due. 259 B : un avertissement bloquant suppose un marqueur « service immatériel » que les fiches n'ont pas ; bloquer tout service vendu à un particulier hors de l'Union bloquerait des services situés en France (réparation faite en magasin). Avertissement non bloquant, marqueur au lot M9 si demandé. | 1.3, 2.3, 5.1, 7.3, V15, V31, V38 |
| 7 | Régime de franchise sans historique daté | Retenu | `database/migrations/0001_01_00_000000_create_societes_table.php:60` ; fin de la franchise au jour du dépassement (`reglementation.md` 4.1). | 3.2, O7, A36, 10.5 |
| 8 | Bons d'achat et cartes cadeaux | Retenu, correction adaptée | Art. 256 ter (vu en extrait), recherche §1.7. Le « type de ligne bon » devient une particularité fiscale du produit, puisque la carte se vend comme un produit du catalogue ; refus tant que T9 n'est pas construit. | 1.3, 7.9, T9, V32 |
| 9 | Monaco décidé sur l'établissement du tiers | Retenu | Art. 290 I 4° a (PDF, page 3) : le mouvement pour les biens, l'établissement pour les services. | 1.4 (4), 6.2, 7.2, V22, V22b, A20, A20b |
| 10 | Base de contrôle de la TVA d'importation incomplète | Retenu | Art. 292 (vu en extrait) ; `reglementation.md` A14. | 2.3, 3.2, 6.3, A11b |
| 11 | Indépendance des émetteurs des preuves d'une livraison intracommunautaire | Retenu | Art. 45 bis du règlement 282/2011 ; recherche N6 ; `app/Domain/Facturation/Services/PreuvesRequises.php:26-32`. | 2.6, 7.4, lot C-a |
| 12 | Exigibilité des services autoliquidés « au paiement » par défaut | Retenu, correction adaptée | `reglementation.md` 2.4 ; l'art. 66 de la directive n'a pas été retrouvé en ligne. Aucune exigibilité ne s'enregistre avant l'étape 5, seule à la lire (3.1) : la date de réalisation s'y demande, signalée « à confirmer ». | 1.4 (16), 8.2, annexe B (2) |
| 13 | Sorties de stock hors vente absentes | Retenu, correction adaptée | BOI-TVA-DED-60-30 (plan lu). Ces sorties naissent avec l'étape 7 (inventaire, ajustements) : la décision y est prévue, sans code avant. | 1.3, 8.3, annexe B (16) |
| 14 | Pour les ventes, les montants sont calculés hors du moteur | Retenu | `app/Domain/Facturation/Services/ConstructeurLigneDocument.php:264-281` (catalogue en base) et `:179-218` (taux, prix payé et montants dans le constructeur). | P9, 2.2 à 2.7, 4.1, 5.1, 7.5 |
| 15 | Confirmation du régime et « rester en France » non stockés | Retenu, correction adaptée | `database/migrations/2026_10_03_100003_create_factures_table.php:32-40`. Les gestes ont leurs colonnes (`nature_confirmee`, `_par`, `_le`), entrées du moteur ; `nature_operation` reste sa sortie, ce qui supprime la boucle sortie → entrée ; `decision_tva` ne s'écrit qu'à l'émission, jamais sur un devis. | 2.3, 3.2, 4.1, O8 |
| 16 | Coût d'entrée écrit sur un grand livre immuable | Retenu | `database/migrations/2026_10_02_200004_create_mouvements_stock_table.php:24-35` ; `app/Domain/Achats/Models/FactureAchat.php:143-153` ; le rejeu lit le coût des lignes (`app/Domain/Stock/Services/StockLedgerService.php:311-352`). Facture reçue par l'API : compléter le dédouanement ne modifie pas son contenu reçu, permis (T12) ; après un avoir fournisseur, refus. | 3.2, 3.3, 5.5 (8), 6.4, A13, A37, A42 |
| 17 | Décision stockée en double ou en triple | Retenu ; écarté en partie | `app/Domain/CaisseSecurisee/Services/ContenuFiscal.php:167-168` ; `app/Domain/Tenancy/Services/JournalAudit.php:71` ; CLAUDE.md:149. Aucune catégorie ni VATEX par ligne, aucune table de décisions d'achat ni de déclarations, historique au journal d'audit. Écarté : `franchise_en_base` reste, car les catégories ne s'enregistrent plus et elle sert à les déduire ; elle est déjà signée et lue par la compatibilité des avoirs (`app/Domain/Facturation/Actions/CreerAvoirAction.php:107-111`). | P14, 3.1, 3.2, 3.3 |
| 18 | Contenu signé dépendant de classes partagées ; version ; ordre JSON | Retenu | `app/Domain/CaisseSecurisee/Services/VerificateurIntegrite.php:707-721` et `:1490-1493` ; `app/Domain/CaisseSecurisee/ModuleFiscal.php:23` ; tests qui figent « 1.0 ». | 3.1, 3.3, 10.6 |
| 19 | Plan de tests faux | Retenu, correction adaptée | Recherche dans `tests/` de l'instantané. `NatureOperationTest` reste au niveau des actions ; `taux_tva` garde son sens sur la ligne d'achat ; les renommages n'ont lieu que si O1 est accepté. | 4.3 |
| 20 | Avoir sur plusieurs factures | Retenu | `app/Domain/Facturation/Actions/CreerAvoirAction.php:80-117`. | 2.3, 2.5, 5.3, V26, V26b |
| 21 | Retour d'un achat autoliquidé et répartition par cumul | Retenu | `app/Domain/Achats/Models/LigneAchat.php:123-139` ; `app/Domain/Facturation/Services/CalculateurLigne.php:191-217`. Règle d'arrondi soumise à l'éditeur. | 6.6, T13, A29b |
| 22 | VIES avant le contrôle du jeton ; refus fiscal après le stock | Retenu | `societe/conception-v2-finale.md` 8.6 et l.967 ; `app/Domain/Facturation/Actions/EmettreFactureAction.php:118-146`. | 2.8, tests de 10.1 |
| 23 | Frais rattachés sans schéma ; cardinalité des déclarations | Retenu, correction adaptée | Cardinalité tranchée : une déclaration par facture (colonnes), la même référence possible sur deux factures (A38). Une ligne de frais se rattache à une seule facture : la facture d'un transitaire qui couvre trois arrivages se saisit en trois lignes, ce qui évite une table de liaison. | 3.2, 6.5, A19, A38 |
| 24 | Règles fiscales restées hors du moteur | Retenu ; écarté en partie | `MentionsFiscales.php:124-133`, `PreuvesRequises.php:48-56`, `Societe.php:392`, `AjouteDesLignesDAchat.php:148`, `Produit.php:381-398`. Écarté : `PreuvesRequises` garde le jugement de complétude (suivi de preuves déjà attendues, pas une décision de TVA) ; `Societe::mentionsLegalesManquantes()` garde l'identité, utilisée par la page d'identité et la caisse ; le barème garde sa formule (il propose un prix TTC). | 4.1 |
| 25 | Fichier de 2 000 factures versionné | Retenu | CLAUDE.md:146 et :148. | 10.4 |
| 26 | Lots trop gros, format signé changé quatre fois, lot des règles écrites bloquant le premier lot de code | Retenu, correction adaptée | M1 et M2 sans changement de résultat ni attente des réponses ; le format signé change une seule fois, en M3, avec les profils et le circuit qui l'imposent ; achats coupés en M5 et M6 ; amendement du lot 21. | 11.1, 11.3 |
| 27 | Dépendance circulaire et API en flottants | Retenu | `app/Domain/Facturation/Services/CalculateurLigne.php:73`, `:93`, `:117`. | 2.2, 4.1 |
| 28 | Colonnes d'étapes futures et codes « à confirmer » signés | Retenu | CLAUDE.md:149. | 3.1, 3.2, 8.1, 8.2 |
| 29 | Magasin de démonstration sans règle | Retenu | CLAUDE.md:164. | 2.5, 2.8, 8.1, 10.1 |
| 30 | Saisie manuelle ouverte aux responsables ; taux dans les dérogations | Retenu | `societe/conception-v2-finale.md` l.599 et l.956. | 5.7, T11, A40 |
| 31 | « Exactement l'un des deux » garanti par le seul modèle | Retenu | `database/migrations/2026_10_02_200006_create_consultations_vies_table.php:46` ; `database/migrations/2026_10_09_100001_create_clotures_caisse_personnelles_table.php:91-105`. | 3.1, 3.2, 10.6 |
| 32 | Appelants oubliés dans l'inventaire | Retenu | `app/Domain/Facturation/Services/PanierDeCaisse.php:276` ; `app/Domain/Facturation/Actions/CreerFactureDepuisDevisAction.php:43-49`, etc. | 4.1, critère de fin de M3, O8 |
| 33 | Origine des marchandises seulement pour un fournisseur non établi | Retenu | Deux lignes de la table 6.2 étaient inatteignables. | 5.4, 6.1, 6.2, A33, A34 |
| 34 | « Établi en France » vrai par défaut pour une adresse étrangère | Retenu | `database/migrations/2026_10_02_200002_create_fournisseurs_table.php:23` ; règle du client (`app/Filament/App/Resources/ClientResource.php:165-171`, `app/Domain/Contacts/Models/Client.php:140-142`). | 3.2, 5.4, A35 |
| 35 | TVA étrangère facturée : contrôle impossible et blocage non soumis | Retenu | Question d'en-tête ; choix soumis avec une recommandation. | 5.4, 6.2, 6.3, T15, A06 |
| 36 | Ligne à 0 % d'un fournisseur français sans contrôle | Retenu | Question A-Q5 et X8 de l'analyse ergonomique, reprises. | 2.3, 5.4, 5.6, A32, A39 |
| 37 | Achat ordinaire plus lent qu'aujourd'hui | Retenu, correction adaptée | `app/Filament/Concerns/AjouteDesLignesDAchat.php:484-488` ; les totaux d'aujourd'hui tiennent sur **une** ligne (`app/Filament/Concerns/SaisitLesPrixDeLigne.php:30-46`), et non quatre : c'est elle qui reste. | 5.4, 10.8 |
| 38 | Expédition hors de France impossible pour un client établi en France | Retenu | `app/Domain/Facturation/Enums/NatureOperation.php:167-174`. | 5.1, 7.2, V34, V35 |
| 39 | Blocage « biens et services mélangés » mal borné | Retenu | `app/Domain/Facturation/Services/ControleNatureOperation.php:150-168`, `:203-219`. | 1.3, 5.6, T6, V36 |
| 40 | Clic « Appliquer » redondant ; proposition émise sans trace | Retenu, correction adaptée | Application d'office quand les réponses du document réunissent les conditions ; proposition gardée pour les prestations ; choix imposé à l'émission ; écart avec la demande dit dans T2. | 2.5, 5.1, T2, V05, V12, V37 |
| 41 | Montant du compteur visible de tout vendeur | Retenu | CLAUDE.md:155 (« champs nécessaires »). | 3.4, 5.1, 7.5, test de M8 |
| 42 | Seuil : départ incomplet, lot incohérent, OSS oublié | Retenu | Deux valeurs de départ et l'inscription à l'OSS au lot M8. | 3.2, 7.5, T8, tests de 10.1 |
| 43 | Trop de questions, sans ordre | Retenu, correction adaptée | Seize questions classées par lot et huit points appliqués sauf objection ; seize et non une douzaine, parce que trois choix faits sans l'éditeur s'y ajoutent (T6, T9, T15). | 12 |
| 44 | État récapitulatif et déclaration européenne de services seulement rappelés | Retenu | Relevé du mois au lot M4. | 7.10, T7 |
| 45 | « Lieu pertinent pour les services » jamais demandé | Retenu (avec le défaut 6) | — | 1.3, 5.1, 7.3 |
| 46 | Vocabulaire et codes à l'écran | Retenu | — | P13, 5.1, 10.6 |
| 47 | « Le montant à payer » pris dans un seul sens | Retenu | — | 0.2 (7), 5.4 |
| 48 | Produit à 0 % refusé en caisse | Retenu | — | 5.2, T5, V33 |
| 49 | VIES injoignable et n° invalide : même message | Retenu | `app/Domain/Contacts/Actions/VerifierNumeroTvaAction.php:36`, `:54`. | 2.8, 5.1, 10.1 |
| 50 | Brouillon incohérent : seul remède manuel | Retenu | `app/Domain/Facturation/Services/ContexteDeVente.php:146`, `:164`. | 2.2, 5.1, 10.1 |
| 51 | Préremplissage asymétrique entre ventes et achats | Retenu, correction adaptée | La dernière facture au même client est proposée, jamais tenue pour vraie, et se confirme à l'émission. | 2.4, 5.1, T3 |
| 52 | « Pays de livraison » d'un achat sans réponse | Retenu | — | 6.1 |
| 53 | Écran des frais rattachés et liste « à compléter » absents | Retenu, correction adaptée | Une ligne de frais par facture rattachée (défaut 23). | 5.4, 6.5, 10.8 |
| 54 | Tests d'écran renvoyés à un autre document | Retenu | — | 10.8 |
