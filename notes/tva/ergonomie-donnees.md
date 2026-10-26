# Moteur de TVA centralisé : ergonomie et modèle d'informations (analyse et proposition)

Rédigé le 11/10/2026. Analyse seule : aucune ligne de code n'a été modifiée, aucun test lancé, rien n'a été écrit ni exécuté dans `/home/user/piliqiediteur`.

Les chemins de code sont relatifs à l'instantané en lecture seule de la branche (commit 9c9ee25, `scratchpad/tva/instantane`), suivis des numéros de ligne.

---

## 0. Cadre

### 0.1 Sources lues

- **Demande de l'éditeur** du 11/10/2026 (`scratchpad/tva/demande-editeur.md`), lue en entier.
- **Code de l'instantané.** Les écrans Filament ont été lus en entier :
  - ventes : factures, caisse, devis, avoirs, natures d'opération, lignes de vente ;
  - achats : factures d'achat, lignes d'achat, avoirs fournisseur ;
  - fiches : clients, fournisseurs, produits, services ;
  - réglages : paramètres fiscaux et identité de la société.

  Les services et modèles correspondants ont aussi été lus (`ControleNatureOperation`, `ContexteDeVente`, `ConstructeurLigneDocument`, `ConstructeurLigneAchat`, `PreuvesRequises`, `TerritoireFiscal`, `TerritoireTva`, `MentionsFiscales`, `EmettreFactureAction`, `Client`, `Fournisseur`, `LigneAchat`), ainsi que les migrations des tables concernées.
- **Règles du projet** : `CLAUDE.md` de l'instantané. Le dossier `.ai/rules` (et donc `index.md`) **n'existe pas** dans l'instantané : aucune règle de chemin à appliquer.
- **Recherche fiscale** `scratchpad/regles-tva-international.md` : sections 0 à 2 et 4 lues, section 3 (multi-établissements) parcourue.
- **Décisions de l'éditeur** :
  - textes des questions et réponses : B1, B2 (saisie libre, 20 % par défaut), B10, B12, C1, C2, E1, E5, E6, E7, E13, E14, I1 à I4, J2, K2, K3, R2 ;
  - compléments 1 à 16 du 11/10 (`decisions/reponses-complements-14-20.md`).
- **Conception de l'architecture société** (`societe/conception-v2-finale.md`) : réglages fiscaux portés par la société, autorisations de magasin, R1 et R2.
- **PDF de l'éditeur**, lus en entier :
  - article 290 du CGI, version en vigueur depuis le 21/02/2026 (4 pages) ;
  - FAQ impots.gouv.fr « Mes clients sont à la fois des entreprises et des particuliers » (16/01/2026, 3 pages).
- **Livrables voisins** de la même analyse, lus le 11/10 vers 14 h pour rester cohérent, sans en dépendre :
  - `scratchpad/tva/inventaire-code.md` : contradictions C1 à C12, questions Q1 à Q11 ;
  - `scratchpad/tva/reglementation.md` : cas d'achat A1 à A18, récapitulatif des ventes V1 à V15.

  Ce document se lit seul. Les renvois à ces deux fichiers ne sont que des correspondances.

### 0.2 Vocabulaire

| Terme | Sens dans ce document |
|---|---|
| **Fait** | Une information dont le moteur a besoin : statut du client, pays d'expédition, n° de TVA vérifié… Chaque fait a une source, une règle de demande, un niveau (bloquant ou non) et un lieu de conservation. |
| **Régime** | Ce que le moteur décide pour un document : vente en France, livraison intracommunautaire, acquisition intracommunautaire, importation… Côté vente, c'est la « nature d'opération » actuelle (`app/Domain/Facturation/Enums/NatureOperation.php:24-31`), élargie. |
| **Décision** | Le régime, avec les faits utilisés, les conditions évaluées, qui a confirmé et quand, et la version des règles. |
| **BLOQUANT** | L'émission (vente) ou la validation (achat) est refusée. Rien n'est numéroté ni signé, et tous les manquements sont dits d'un coup. |
| **AVERTISSEMENT** | Rien n'est refusé. Le message est affiché, répété dans la confirmation et conservé avec la décision. |
| **INFORMATION** | Un texte neutre, par exemple le circuit de transmission. |

### 0.3 Résumé

1. **Les ventes à l'étranger sont bien contrôlées, mais à l'aveugle.** Les six natures du lot 19 sont bloquantes à l'émission (`app/Domain/Facturation/Services/ControleNatureOperation.php:89-109`), et le pays seul ne décide jamais. En revanche :
   - le vendeur choisit la nature lui-même dans une liste (`app/Filament/Concerns/ChoisitLaNatureDeLOperation.php:56-73`) ;
   - il n'apprend ce qui manque qu'**au moment d'émettre**, dans un message d'erreur unique (`app/Domain/Facturation/Exceptions/NatureOperationRefuseeException.php:19-23`) ;
   - il le reçoit parfois **en deux fois** : SIREN du client d'abord (`app/Domain/Facturation/Actions/EmettreFactureAction.php:194-200`), conditions de la nature ensuite (`:144`) ;
   - il ne voit pendant la saisie ni le résultat de VIES ni le régime qui s'appliquera.
2. **Les achats à l'étranger ne sont pas saisissables correctement.**
   - Aucun champ ne dit d'où viennent les marchandises, qui les a dédouanées, ni si la TVA est autoliquidée.
   - Le taux d'une ligne d'achat est **le taux de vente de la fiche produit**, et le modifier modifie la fiche (`app/Filament/Concerns/AjouteDesLignesDAchat.php:484-485`). Saisir une facture allemande à 0 % fait donc vendre le produit à 0 % en caisse (C1 de l'inventaire).
   - Le total s'appelle « à payer au fournisseur », TVA comprise (`app/Filament/App/Resources/FactureAchatResource.php:98`).
   - Une ligne de frais de port ou de service est impossible : toute ligne porte un produit.
3. **Proposition : on corrige les faits, jamais le résultat.**
   - Le moteur lit les faits (fiche, document, magasin, société), propose un régime, dit ce qui manque, et n'applique une exonération ou une autoliquidation que si toutes ses conditions sont réunies.
   - L'utilisateur ne choisit jamais un résultat fiscal. Il complète ou corrige des faits, et confirme une proposition.
   - Rester à la TVA française est toujours possible. Forcer une exonération ne l'est jamais.
4. **La France d'abord : zéro question pour 99 % des opérations.** Pour une vente à un particulier, à un professionnel établi en France ou au comptoir, l'écran ne montre aucun champ de plus qu'aujourd'hui. La liste « Nature de l'opération » disparaît même, remplacée par une ligne : « TVA française ».
5. **Questions progressives.** Les questions n'apparaissent que si un fait les déclenche : client établi hors de France, n° de TVA étranger, fournisseur étranger, livraison hors de France. Elles sont courtes, en langage simple, une à la fois, avec des boutons plutôt que des listes.
6. **Un panneau « Régime de TVA appliqué ».** Sur chaque facture, devis et facture d'achat, il affiche :
   - le régime, le taux, qui paie la TVA et la mention imprimée ;
   - une justification en phrases simples, chaque condition marquée « oui » ou « non » ;
   - les informations manquantes, avec un lien vers le champ ;
   - le circuit de transmission ;
   - l'effet sur le prix quand le régime change. Une vente exonérée se paie au prix hors taxe (K3) : passer d'une vente en France à un export fait baisser le total.
7. **Un seul calcul.** L'aperçu à l'écran, le contrôle à l'émission ou à la validation et la décision figée appellent le même moteur, comme `ConstructeurLigneDocument::apercu()` le fait déjà pour les montants (`app/Filament/Concerns/AjouteDesLignesDeVente.php:110-118`). Les écrans ne contiennent plus aucune règle fiscale.
8. **Modèle d'informations : environ 60 faits** (section 3), répartis entre le vendeur, le client, le fournisseur, le catalogue, la vente, l'achat et les faits dérivés. Les faits nouveaux les plus importants :
   - client : « assujetti à la TVA » et « entité publique » ;
   - fournisseur : « établi en France », « ne facture pas de TVA » et la vérification VIES ;
   - facture d'achat : origine réelle des marchandises, dédouanement (par qui, référence, droits) ;
   - ligne d'achat : distinction produit, frais ou service, et deux taux (TVA facturée et TVA due en France) ;
   - vente : la question « les marchandises quittent-elles la France ? » ;
   - produit : la particularité fiscale (accises, moyen de transport neuf).
9. **Bloquant ou avertissement : une règle simple.**
   - **Bloquant** si, sans ce fait, le régime retenu est inapplicable ou si un montant dû ou déclaré serait faux au moment de l'acte.
   - **Avertissement** si le fait se complète après sans changer le document : preuves d'export, référence de douane, mentions de la facture reçue, taux inhabituel.
10. **Ce qui se fige.**
    - Ventes : la décision est figée et signée à l'émission, dans le contenu signé. Le PDF relit les mentions signées au lieu de les recalculer (D5 de l'inventaire).
    - Achats : la décision est figée à la validation et inscrite au journal d'audit. Une modification ultérieure, réservée au droit de valider (C2), recalcule et journalise l'avant et l'après.
11. **Caisse : aucune question en plus** pour un ticket. Deux ajouts seulement :
    - la case « Le client demande une facture » (complément 13) ;
    - un avertissement quand des services sont vendus au comptoir à un professionnel établi hors de France.
12. **API d'achat (point d'entrée du domaine, réception SUPER PDP, future API v1) : mêmes règles.**
    - Les faits absents sont préremplis de la même façon.
    - Un brouillon n'est jamais refusé pour un fait manquant.
    - La validation est refusée avec la liste complète des manquements, chacun avec un code stable, son champ et un message.
13. **Seize règles à trancher par l'éditeur** (section 6). Les principales :
    - proposer le régime avec confirmation, jamais de bascule silencieuse ;
    - question de sortie des biens sans valeur par défaut pour un client étranger ;
    - dédouanement complétable après la validation de la réception ;
    - TVA d'achat reprise de la facture du fournisseur ou recalculée par ligne ;
    - ventes à distance à des particuliers de l'UE avant la phase OSS.

---

## 1. Parcours actuel

### 1.1 Vente à un professionnel étranger, sur une facture

**Scénario.**
- Un magasin de Lyon (métropole) vend à « Müller GmbH », à Berlin, n° de TVA DE123456789 :
  - 10 articles à 12,00 € TTC (20 %), expédiés à Berlin par le magasin ;
  - une ligne de service « Port et emballage ».
- Le même client commande ensuite un service « Paramétrage à distance ».

#### Étape 1 : la fiche du client

Elle se crée soit par la fiche complète (`app/Filament/App/Resources/ClientResource.php:262-306`), soit par la création rapide du « + » de la facture (`:240-256`).

| Champ | Comportement actuel | Ce qui manque |
|---|---|---|
| Nom | Requis. | — |
| Type de client | Deux boutons, Particulier / Professionnel, particulier par défaut (`ClientResource.php:108-119`). | — |
| Pays | Texte libre de deux lettres, « FR » par défaut, aide « FR, BE, CH… » (`:128-141`). Masqué pour un particulier dans la création rapide (`:247`). | Une liste de pays nommés (le vendeur doit savoir que l'Allemagne s'écrit DE et la Grèce GR). Le code postal n'est pas exploité pour les territoires (Canaries, DOM, Saint-Martin). Saint-Barthélemy, Saint-Martin et Saint-Pierre-et-Miquelon comptent « en France » (`app/Domain/Facturation/Services/TerritoireFiscal.php:22`), alors qu'ils sont hors du territoire de TVA français (C2). |
| Établi en France | Interrupteur coché d'office et désactivé pour une adresse en France, décoché à chaque passage vers l'étranger (`ClientResource.php:165-175`). | — Bonne aide, à garder. |
| SIREN / SIRET | Requis pour un professionnel établi en France (`:176-198`). | — |
| N° TVA intracommunautaire | Format seul (`:201-206`). Aucune vérification VIES à la saisie. Le bouton « Vérifier sur VIES » n'existe que dans la liste et sur la fiche (`:349-359`), pas dans la création rapide. | Une vérification automatique à l'enregistrement, et l'état VIES visible sur la facture. |
| Autre identifiant | Requis pour un professionnel établi hors de France sans n° de TVA (`:207-212`). | — |
| Assujetti à la TVA, entité publique | **Absents.** | Exigés par le complément 9 et la décision E7 (circuit, hors plateforme). |
| Adresse | Visible pour un professionnel (`:249-252`), **facultative**. | Le nom et l'adresse du client sont des mentions obligatoires de la facture. Aujourd'hui, l'adresse n'est exigée que pour la livraison d'une nature exonérée (`ControleNatureOperation.php:157`). |

#### Étape 2 : la facture

Formulaire : `app/Filament/App/Resources/FactureResource.php:123-164`.

| Champ | Comportement actuel | Ce qui manque |
|---|---|---|
| Client | Liste avec recherche et « + » (`:127-141`). Changer de client retire une nature devenue impossible et préremplit la livraison (`ChoisitLaNatureDeLOperation.php:114-124`). | Rien n'est dit sur le profil fiscal du client choisi : établi où, n° de TVA vérifié ou non. |
| Échéance | Annoncée, jamais saisie (`FactureResource.php:145-147`). | — |
| **Nature de l'opération** | Liste pleine largeur, « Vente en France » par défaut (`ChoisitLaNatureDeLOperation.php:56-73`). Seules les natures impossibles sont retirées (`:187-195`). L'aide affiche la mention et les conditions en une phrase (`:286-289`). | **Rien n'est proposé** : pour Müller, il faut savoir choisir « Livraison intracommunautaire à un professionnel ». Les conditions ne sont pas évaluées pendant la saisie. Le libellé est juridique. |
| Section Livraison | Visible seulement pour une nature de biens hors de France (`:75-106`). Adresse préremplie avec celle du client (`:295-315`). Pays en texte de deux lettres, obligatoire (`:87-94`). Date obligatoire pour une livraison intracommunautaire (`:95-97`). Transport « magasin ou acquéreur » pour la seule livraison intracommunautaire (`:98-103`), imposé au magasin pour un export ou un DOM (`app/Domain/Facturation/Concerns/NoteSaNatureDOperation.php:52-54`). | Le lieu de **départ** est toujours supposé en métropole (C3). L'adresse de livraison d'une vente en France n'existe pas, alors que la réforme la rend obligatoire quand elle diffère de l'adresse du client. |
| Lignes | Produit ; PU TTC de la fiche ; TVA de la fiche affichée, non modifiable (`app/Filament/Concerns/AjouteDesLignesDeVente.php:288-316`). Hors TVA française, le taux d'un service est caché et son prix s'appelle « Prix (sans TVA) » (`:269`, `:296`). Le total affiche « Sans TVA française » (`:357-360`). Changer de nature convertit le prix des services, avec une notification (`ChoisitLaNatureDeLOperation.php:203-221`). | L'effet de la nature sur ce que paie le client n'est jamais affiché comme tel : 120,00 € TTC deviennent 100,00 € (K3). |
| Totaux | HT, TVA et TTC, calculés par le même constructeur que l'enregistrement (`AjouteDesLignesDeVente.php:110-118`). | — |

#### Étape 3 : l'émission

- Bouton « Émettre » de la liste, avec une confirmation générique (`FactureResource.php:659-669`).
- Avant d'émettre, l'écran consulte VIES s'il n'existe pas de consultation valide du jour (`:785-794`). Cette consultation automatique vit **dans l'écran**, pas dans le domaine (D10).
- Puis `EmettreFactureAction` contrôle, dans cet ordre :
  1. le type de client et le régime (`EmettreFactureAction.php:131-132`) ;
  2. l'identité légale du magasin et le SIREN du client (`:134-136`, `:194-200`) ;
  3. la nature (`:144`).
- Deux refus peuvent donc se succéder. Le second dit tous les manquements de la nature d'un coup, par exemple :

> « Impossible d'émettre en « Livraison intracommunautaire à un professionnel » : indiquez la date de livraison ; le n° de TVA DE123456789 n'a pas été trouvé valide sur VIES le 11/10/2026. Choisissez « Vente en France » (TVA française) si ces conditions ne sont pas remplies. »

#### Étape 4 : après l'émission

- La fiche montre la nature et sa mention (`FactureResource.php:361-366`), la vérification VIES retenue (`:192-195`), la livraison (`:372-383`) et les preuves à fournir (`:393-419`).
- Le bouton « Ajouter une preuve » demande le type, la référence, la date et « Établie par », encore facultatif (`app/Filament/App/Resources/FactureResource/Pages/ViewFacture.php:51-89`).
- Un badge du menu compte les factures dont une preuve manque (`FactureResource.php:106-111`).
- La section « Transmission électronique » annonce une transmission au PDP pour **tout** professionnel, Müller compris (`:279-294`, avec `app/Domain/Facturation/Models/Facture.php:612-615`). Or Müller relève de l'e-reporting international, selon la FAQ du 16/01/2026 et l'art. 290 I 1° a.

#### La prestation « Paramétrage à distance »

- Le vendeur doit choisir « Prestation à un professionnel de l'UE (autoliquidation) » et saisir un prix **sans TVA**.
- Un vendeur sans l'autorisation « accorder des remises et fixer le prix d'un service » (R2, à venir) ne pourra pas saisir ce prix. Avec le prix de référence du service prévu par R2, la logique cohérente est la même que pour un produit : le prix de référence TTC sans la TVA (K3, complément 5). Voir QE13.
- Une prestation au même client sur une facture qui contient aussi des marchandises est refusée à l'émission (`ControleNatureOperation.php:219`). La règle est juste (une nature par document), mais elle n'est dite qu'à la fin.

#### Manques côté vente (synthèse)

| # | Manque |
|---|---|
| V-1 | La nature n'est jamais proposée, et ses conditions ne sont évaluées qu'à l'émission. |
| V-2 | L'état VIES du client n'est pas visible pendant la saisie. La consultation est déclenchée par l'écran, pas par le domaine. |
| V-3 | Ni « assujetti », ni « entité publique ». Le circuit ne dépend que du type de client. |
| V-4 | Pas de question « les biens quittent-ils la France ? » : c'est la nature choisie qui l'implique. |
| V-5 | Lieu de départ supposé en métropole (C3). Territoire de TVA confondu avec l'identification par un SIREN (C2). |
| V-6 | Refus successifs au lieu d'une liste unique. Aucune annonce de l'effet sur le prix. |
| V-7 | Pays saisis en codes de deux lettres, sans liste. |
| V-8 | Pas d'avertissement pour une vente à distance à un particulier de l'UE : la TVA française n'est juste que sous le seuil de 10 000 €, et ce seuil n'est suivi nulle part. |

### 1.2 La même vente à la caisse

- La caisse vend toujours en France :
  - `EnregistrerVenteCaisseAction` crée la facture sans nature (`app/Domain/Facturation/Actions/EnregistrerVenteCaisseAction.php:63-80`) ;
  - la page n'a aucun champ de nature (`app/Filament/App/Pages/Caisse.php:203-296`).
- Un client professionnel est accepté. La caisse dit seulement « Facture impossible : identité de la société incomplète » quand c'est le cas (`Caisse.php:397-416`).
- C'est juste pour des marchandises remises au comptoir (TVA française, recherche N1).
- C'est **faux pour un service** vendu au comptoir à une entreprise établie hors de France (réparation, paramétrage) : le lieu de la prestation est celui du preneur (art. 259-1°). Aucun signal n'apparaît.

### 1.3 Devis et avoirs

- **Devis.**
  - Même formulaire que la facture : nature, livraison, lignes (`app/Filament/App/Resources/DevisResource.php:53-88`).
  - L'envoi ne contrôle pas la nature (`EmettreDevisAction` n'appelle pas `ControleNatureOperation`). Un devis exonéré peut partir sans que ses conditions soient réunies, et la facture qui en sortira sera refusée à son émission.
  - La conversion reprend la nature et la livraison (`app/Domain/Facturation/Actions/CreerFactureDepuisDevisAction.php:48`).
- **Avoirs.**
  - L'avoir reprend la nature, le type de client et la franchise de sa facture.
  - Mélanger des natures est refusé à l'enregistrement seulement (`app/Domain/Facturation/Actions/CreerAvoirAction.php:113-117`). L'écran laisse choisir des lignes de factures de natures différentes : il ne sépare que les tickets des factures (`app/Filament/App/Resources/AvoirResource.php:107-118`).

### 1.4 Achat à un fournisseur étranger

**Scénarios.**
- (a) « Spielwaren AG », à Nuremberg, facture 100 articles à 8,00 € HT et 40,00 € de port, **sans TVA** : c'est une acquisition intracommunautaire.
- (b) Un fournisseur chinois facture 600,00 € ; un transitaire dédouane pour le magasin, avec des droits de douane et la TVA à l'importation.

#### La fiche fournisseur

Formulaire : `app/Filament/App/Resources/FournisseurResource.php:34-79`.

| Champ | Comportement actuel | Ce qui manque |
|---|---|---|
| Nom | Requis. | — |
| SIRET | 14 chiffres, unique dans le magasin, différent de celui de l'entreprise (`:39-57`). | Le SIREN n'existe pas, alors qu'un fournisseur étranger établi en France en a un. |
| N° TVA intracommunautaire | Format seul (`:62-66`). | La vérification VIES est impossible : les consultations sont liées aux clients seuls (`database/migrations/2026_10_02_200006_create_consultations_vies_table.php:33`). |
| Pays | `maxLength(2)`, « FR » par défaut, **sans contrôle** de format ni majuscules, contrairement au client (`FournisseurResource.php:74`). | La liste de pays, et la case « établi en France ». |
| Statut | Absent. | « Ne facture pas de TVA (franchise) », option pour les débits (pour ses services), devise habituelle. |

#### La facture d'achat

Formulaire : `FactureAchatResource.php:63-101`.

| Champ | Comportement actuel | Ce qui manque |
|---|---|---|
| Fournisseur | Liste, sans création depuis la facture (`:74-79`). | Le profil fiscal du fournisseur n'est pas rappelé. |
| N° de facture du fournisseur, date | Facultatifs (`:80-84`). | L'e-reporting international « in » exigera le numéro et la date (spécification SUPER PDP, `b2bint_invoice`). |
| Escompte obtenu | Baisse le coût, pas les totaux (`:85-92`). | — |
| Devise | La colonne existe (`database/migrations/2026_10_07_100001_create_factures_achat_table.php:31`), **pas le champ**, ni de taux de change. | Taux de change et TVA en euros. |
| **Origine des marchandises, dédouanement, date de réception** | **Absents.** | Champ explicitement demandé par l'éditeur. |
| Total | « Total de la facture d'achat (à payer au fournisseur) » (`:98`). | Faux dès que la TVA est autoliquidée : rien n'est payé au fournisseur au titre de la TVA. |

#### Le formulaire d'une ligne

Fenêtre « Ajouter un produit à la facture » : `AjouteDesLignesDAchat.php:207-308`.

| Champ | Comportement actuel | Ce qui manque |
|---|---|---|
| Produit du catalogue ou nouveau produit | Toute ligne porte un produit (`:220-256`). | Une ligne de **frais** (port, transit, assurance, droits) ou de **service** est impossible. Le port de Spielwaren ne peut pas être saisi, sinon comme un faux produit. |
| Quantité reçue, PU HT, remise | `:266-289`. | — |
| Prix de vente TTC et « Taux de TVA (%) » | Ce sont les champs de la **fiche produit**, repris tels quels (`:290-297`, avec `app/Filament/App/Resources/ProduitResource.php:127-153`). Le taux est donc le taux de vente. | Ce même taux devient celui de la ligne d'achat (`AjouteDesLignesDAchat.php:484-485`) et corrige la fiche (C1). Pour Spielwaren, facturé à 0 %, il faut soit laisser 20 % (une TVA qui n'existe pas apparaît), soit mettre 0 % (le produit se vend alors à 0 % en caisse). |
| Récapitulatif de la ligne | « Montant payé au fournisseur : … TTC » et « Vente en magasin : … bénéfice … HT » (`:109-157`). | Ni TVA autoliquidée, ni TVA récupérable, ni coût d'entrée en stock. Le bénéfice d'une société en franchise est faussé (C8). |

#### La validation et l'avoir fournisseur

- **Validation.** Le bouton « Valider la réception » affiche une confirmation générique (`FactureAchatResource.php:188-195`). Aucun contrôle fiscal n'est fait. L'entrée en stock se fait au PU HT net de la remise et de l'escompte (`app/Domain/Achats/Models/LigneAchat.php:92-99`), sans droits de douane ni frais.
- **Avoir fournisseur.** Il reprend le taux et le prix de la ligne achetée. Il n'appelle rien de nouveau, mais héritera de la décision de l'achat.

#### Manques côté achat (synthèse)

| # | Manque |
|---|---|
| A-1 | Aucun fait d'origine : pays d'expédition, dédouanement, référence de douane, date de réception. |
| A-2 | Un seul taux par ligne, pris de la fiche produit et réécrit sur elle (C1). Pas de distinction entre la TVA facturée et la TVA due en France. |
| A-3 | Pas de ligne de frais ni de service. Frais d'approche et droits de douane hors du coût du stock. |
| A-4 | Fiche fournisseur pauvre : ni « établi en France », ni franchise, ni VIES. Pays sans contrôle. |
| A-5 | Totaux trompeurs dès que la TVA est autoliquidée (D12 de l'inventaire). |
| A-6 | Pas de devise à l'écran, alors que la colonne existe. |

### 1.5 Ce qui existe aujourd'hui pour la TVA dans les réglages, le catalogue et les tiers

| Où | Données TVA | Écran |
|---|---|---|
| Société | `regime_tva` (réel normal mensuel, trimestriel, simplifié ou franchise ; nullable), `option_debits_tva`, `numero_tva` (`database/migrations/0001_01_00_000000_create_societes_table.php:50`, `:60`, `:63`) | `app/Filament/Societe/Pages/ParametresFiscauxSociete.php:71-78` et `app/Filament/Societe/Pages/IdentiteSociete.php:129-131`. Le régime mélange le statut (franchise ou redevable) et la cadence (C11). Pas de date d'effet. |
| Magasin | Adresse, d'où découle le territoire de TVA (`app/Domain/Facturation/Enums/TerritoireTva.php:39-50`) et le taux par défaut (`:65-72`) | Aucun réglage TVA, et c'est voulu. |
| Produit | `taux_tva` (taux de vente), `prix_vente_ttc`, `cmup_ht` (`database/migrations/2026_10_02_200003_create_produits_table.php:33-41`) | Taux libre, 20 % par défaut, alerte de taux inhabituel (`ProduitResource.php:142-171`). |
| Service | `frais_accessoires`, `lieu_specifique` (`database/migrations/2026_10_02_200005_create_services_table.php:29`, `:35`). Ni prix ni taux de référence aujourd'hui (R2 prévu). | `app/Filament/App/Resources/ServiceResource.php:51-60`. Libellés clairs, à garder. |
| Client | `type_client`, `siren`, `siret`, `numero_tva`, `etabli_en_france`, `identifiant_etranger`, `pays` (`database/migrations/2026_10_02_200001_create_clients_table.php:15-31`) | Voir 1.1. |
| Fournisseur | `siret`, `numero_tva`, `pays` (`database/migrations/2026_10_02_200002_create_fournisseurs_table.php:17-23`) | Voir 1.4. |

### 1.6 Constats ergonomiques transverses

| # | Constat | Preuve |
|---|---|---|
| E1 | Les règles fiscales sont recodées dans les écrans. La franchise est lue directement sur la société, et la règle « vente sans TVA française » est recodée à la réouverture d'un brouillon. | `AjouteDesLignesDeVente.php:174`, `:296-298`, `:326`, `:366` ; `ProduitResource.php:164` |
| E2 | Le taux par défaut du territoire sert aussi de taux d'achat. | `AjouteDesLignesDAchat.php:456`, `:503` |
| E3 | Les erreurs n'arrivent qu'à la fin, une par une. | Voir 1.1, étape 3 |
| E4 | Le jargon est au premier plan : « Livraison intracommunautaire », « autoliquidation », « 262 ter I ». La phrase simple n'est qu'en aide secondaire. | `NatureOperation.php:33-43`, `:67-77` |
| E5 | Trois saisies de pays, trois comportements : client validé et en majuscules, livraison validée, fournisseur sans contrôle. | `ClientResource.php:128-141` ; `ChoisitLaNatureDeLOperation.php:87-94` ; `FournisseurResource.php:74` |
| E6 | Rien ne relie la vérification VIES, faite sur la fiche du client, à la facture en cours, sauf au moment d'émettre. | `ClientResource.php:318-333` ; `FactureResource.php:785-794` |
| E7 | Une notification persistante prévient déjà quand un brouillon rouvert change de nature. C'est le bon modèle de signalement, à généraliser. | `ChoisitLaNatureDeLOperation.php:142-165` |

---

## 2. Principes de conception proposés

| # | Principe | Conséquence à l'écran |
|---|---|---|
| P1 | **La France d'abord.** Une vente ou un achat en France ne demande rien de plus qu'aujourd'hui. | Aucun champ nouveau tant qu'aucun déclencheur international n'existe (tableaux 4.2 et 4.8). La liste de nature est remplacée par une ligne. |
| P2 | **On corrige les faits, jamais le résultat.** | Pas de liste « choisissez votre régime fiscal ». Pour changer le régime, on complète un fait (n° de TVA, sortie des biens, date) ou on confirme une proposition. Le menu « Changer de régime » ne liste que les régimes possibles, chacun avec ce qui lui manque. |
| P3 | **Le pays déclenche une question, jamais une décision** (demande de l'éditeur). | Un client allemand fait apparaître la question « les marchandises quittent-elles la France ? », pas l'exonération. Chaque régime autre que la vente en France exige des conditions cumulatives. |
| P4 | **Proposer, confirmer, tracer.** Jamais de bascule silencieuse : un changement de régime change le prix payé (K3) et la mention. | Un bouton « Appliquer », avec l'effet sur le total. La confirmation (qui, quand) entre dans la décision. |
| P5 | **Valeur sûre par défaut.** À la vente, la TVA française tant que les conditions d'un autre régime ne sont pas réunies (I1). À l'achat, aucun régime supposé pour un fournisseur étranger. | Vente : le brouillon s'enregistre toujours, à la TVA française si besoin. Achat : la validation attend l'origine et le dédouanement (bloquant). |
| P6 | **Un seul calcul**, partagé par l'aperçu, le contrôle, la décision, le stock et plus tard les déclarations. | Les écrans affichent le résultat du moteur, sans règle à eux (corrige E1, D1, D2, D10). |
| P7 | **Bloquant ou avertissement.** Bloquant si le régime est inapplicable ou si un montant dû ou déclaré serait faux au moment de l'acte. Avertissement si le fait se complète après sans changer le document. | Tableau 4.4. |
| P8 | **Langage simple d'abord, référence légale ensuite.** | « Vous déclarez la TVA à la place du fournisseur (autoliquidation) ». L'article du CGI ne figure que dans la mention et dans un détail « Pourquoi ? ». |
| P9 | **Respect des décisions déjà prises.** | Taux saisi librement, 20 % par défaut, 8,5 % aux Antilles et à La Réunion (B2) ; taux de la fiche pour une ligne de produit (B1) ; prix TTC des ventes et HT des achats ; autorisations (R1, R2, C2) ; I1 à I4 ; E7 et E13 ; une nature par document. |
| P10 | **Tous les manquements d'un coup**, dans le panneau pendant la saisie puis dans le refus, identité légale et SIREN compris. | Corrige les refus successifs (E3). |

---

## 3. Modèle d'informations du moteur

### 3.1 Lecture des tableaux

**Colonnes**

| Colonne | Contenu |
|---|---|
| **Source par défaut** | D'où vient la valeur sans que personne ne la tape : fiche, magasin, société, dernier document validé du même tiers, ou valeur dérivée. |
| **Quand le demander** | « Jamais » : le fait se dérive. « Toujours » : demandé à chaque fois. « Si… » : la condition qui le fait apparaître. « Une fois » : sur une fiche ou un réglage. |
| **Si absent** | **B** = bloquant, avec le moment (émission, validation). **A** = avertissement. **—** = sans effet, ou valeur par défaut sûre. |
| **Conservation** | Où le fait est gardé, et quand il se fige. |

**Lieux de conservation**

| Abréviation | Signification |
|---|---|
| fiche | Modifiable ; les champs fiscaux sont inscrits au journal d'audit (étape 4). |
| doc | Figé à l'émission et signé, dans le bloc « document » ou « contexte » de `ContenuFiscal`. |
| achat | Figé à la validation de la facture d'achat et inscrit au journal d'audit. |
| décision | Copié dans l'instantané de la décision (3.10). |
| JET 270 | Changement inscrit au journal des événements de chaque magasin (mécanisme existant pour les paramètres de conformité). |

### 3.2 Faits du vendeur (société et magasin)

| Fait | Source par défaut | Quand le demander | Si absent | Conservation |
|---|---|---|---|---|
| S1. Statut de TVA : redevable ou franchise en base, avec sa **date d'effet** | Société > Paramètres fiscaux (`regime_tva`) | Une fois, à la configuration de la société, puis à chaque changement. Jamais sur un document. | **B** à la première émission réelle, si le régime n'est pas renseigné. Le magasin de démonstration est exempté. Aujourd'hui, un régime vide compte comme redevable. | Société, avec l'historique daté de ses changements (la franchise prend fin au jour du dépassement, selon reglementation.md 4.1) ; JET 270 ; `franchise_en_base` signée sur chaque document (existant). |
| S2. Cadence de déclaration (mensuelle ou trimestrielle) | Société | Une fois. Elle sert seulement à déclarer le régime à SUPER PDP (complément 11). | — | Société (séparée du statut, voir C11). |
| S3. Option pour les débits | Société (existant) | Une fois. | — (désactivée par défaut) | Société ; JET 270 ; mention signée (existant). |
| S4. N° de TVA intracommunautaire de la société | Société > Identité. Proposé depuis le SIREN par la clé de contrôle, comme le prévoit déjà la conception société (ligne 150). | Une fois. | **B** pour une vente exonérée ou autoliquidée (existant, `ControleNatureOperation.php:107`), pour une facture à un professionnel hors franchise (existant, `mentionsLegalesManquantes`), et **(nouveau)** pour un achat en acquisition intracommunautaire ou en autoliquidation par une société redevable. | Société ; JET 270 ; signé dans l'émetteur (existant). |
| S5. Territoire de TVA du magasin : métropole, Corse, Guadeloupe-Martinique-La Réunion | Adresse du magasin, contrôlée au répertoire Sirene | Jamais : il se dérive. | — (un magasin hors des territoires gérés est déjà refusé à sa création) | Émetteur signé ; code du territoire copié dans la décision. |
| S6. Taux par défaut | Territoire du magasin : 20 %, ou 8,5 % aux Antilles et à La Réunion (B2) | Jamais. Il se corrige sur la fiche du produit ou du service. | — | Fiche. |
| S7. N° EORI (FR + SIREN) | Société | Une fois, proposé à la première importation ou au premier export. | **A** (sert aux formalités de douane, pas au calcul de la TVA) | Société. |
| S8. Inscription au guichet OSS (date), option pour la taxation à l'arrivée, établissements dans d'autres États membres | Société | Phase 2 de la décision I1 seulement, à la première vente à distance dans l'UE. | — en phase 1 (avertissement QE8) | Société, datée. |
| S9. Compteurs : ventes à distance dans l'UE (année précédente et année en cours), acquisitions intracommunautaires d'une société en franchise | **Dérivés** des documents validés de **tous les magasins de la société**, avec une valeur de départ demandée une fois pour l'année précédant l'arrivée dans PILIQI | Jamais, sauf la valeur de départ. | — | Calculé, et instantané dans la décision. Lecture hors du magasin courant : voir 3.12. |

### 3.3 Faits du client

| Fait | Source par défaut | Quand le demander | Si absent | Conservation |
|---|---|---|---|---|
| C1. Type : particulier ou professionnel | Fiche (existant) | Toujours, à la création de la fiche (deux boutons). Jamais sur le document. | — (particulier par défaut ; le client comptoir l'est toujours) | Fiche ; doc (`type_client`, existant). |
| C2. **Assujetti à la TVA** | Fiche. **Oui** par défaut pour un professionnel. | Une fois, dans la section repliée « Fiscalité » de la fiche, sous la forme inversée « Ce client n'est pas assujetti à la TVA (association sans activité taxable, organisme public…) ». Jamais dans la création rapide ni sur le document. | — | Fiche ; doc (copie figée, complément 9). |
| C3. **Entité publique** | Fiche. **Non** par défaut. Proposée « oui » si une recherche Sirene du client renvoie une catégorie juridique 7xxx (QE11). | Même section que C2. | — (effet : facture hors plateforme avec un message, décision E7) | Fiche ; doc (copie figée). |
| C4. Pays et code postal de l'établissement | Fiche (`pays`, `code_postal`). Pays préréglé sur FR. | Toujours pour un professionnel (création rapide comprise), par une **liste de pays nommés** ; jamais pour un particulier (voir C11). | **B** pour une facture à un professionnel : adresse complète, voir D9. | Fiche ; doc (`client_*`, existant) ; territoire dérivé (3.8) dans la décision. |
| C5. Établi en France : un établissement en France intervient dans l'achat | Pays en France : oui d'office (existant) | Seulement si le pays est hors de France (existant, `ClientResource.php:165-175`). | — (non par défaut, à l'étranger) | Fiche ; doc (`client_etabli_en_france`, existant). |
| C6. SIREN ou SIRET | Fiche | Si C5 = oui (existant, B12). | **B** à l'émission (existant, `EmettreFactureAction.php:197-199`) | Fiche ; doc (existant). |
| C7. N° de TVA intracommunautaire, et sa **vérification VIES** | Fiche. Consultation VIES **automatique** à l'enregistrement de la fiche quand le n° est d'un État membre (ou XI), puis une fois le jour de l'émission (existant). | Pour un professionnel, champ toujours visible. Il n'est exigé que pour exonérer ou autoliquider. | — pour une vente en France. **B** pour une livraison intracommunautaire ou une prestation autoliquidée, sans consultation valide du jour (existant). **A** si VIES renvoie un nom ou une adresse très différents de la fiche (VIES les rend déjà : `consultations_vies.nom` et `adresse`). | Fiche ; consultations en ajout seul (existant) ; consultation retenue signée (existant). |
| C8. Autre identifiant d'un client hors UE (identifiant fiscal étranger, RIDET, TAHITI) | Fiche (existant) | Pour un professionnel établi hors de France sans n° de TVA (existant). | **B** pour une prestation hors UE (existant, `ControleNatureOperation.php:218`) | Fiche ; doc (existant). |
| C9. Adresse électronique de facturation (BT-49) | Annuaire PPF, par SUPER PDP | Étape 2 seulement. | — | Fiche. |
| C10. Délai de paiement, mode préféré | Fiche (existant) | Hors moteur de TVA. | — | — |
| C11. Pays de résidence d'un particulier | — | **Jamais.** Pour un particulier, c'est l'adresse de livraison qui compte (vente à distance, export). La résidence d'un voyageur se saisit dans PABLO, pas dans PILIQI (I3). | — | — |

### 3.4 Faits du fournisseur

| Fait | Source par défaut | Quand le demander | Si absent | Conservation |
|---|---|---|---|---|
| F1. Pays et code postal | Fiche (`pays`). FR par défaut, désormais par une liste de pays nommés, avec contrôle. | Une fois, sur la fiche. | **B** à la validation d'un achat, si le pays est vide. | Fiche ; achat (copie dans la décision). |
| F2. **Établi en France** : un établissement en France intervient dans la vente | Pays en France : oui d'office. Ailleurs : non. | Seulement si le pays est hors de France, avec l'aide « Cochez si ce fournisseur facture depuis un établissement en France (un n° de TVA FR par représentant fiscal ne suffit pas) ». | — (non par défaut, à l'étranger) | Fiche ; achat. |
| F3. SIRET (et le SIREN qui s'en déduit) | Fiche (existant) | Si F2 = oui. | **A** si absent : SUPER PDP reconnaît un fournisseur par son SIRET (CLAUDE.md) | Fiche. |
| F4. N° de TVA, et **vérification VIES** (nouveau pour les fournisseurs) | Fiche ; VIES automatique à l'enregistrement pour un n° de l'UE | Une fois. | **A** seulement. Pour l'acheteur, ce n'est pas une condition légale ; c'est l'indice que le vendeur est bien un assujetti d'un autre État membre. | Fiche ; consultations en ajout seul, étendues aux fournisseurs (3.11). |
| F5. **Ne facture pas de TVA** (franchise en France, ou franchise d'un autre État membre, suffixe EX) | Fiche. Non par défaut. | Une fois. Proposée au premier achat français saisi sans TVA (question A-Q5), avec « Mémoriser pour ce fournisseur ». | — | Fiche ; achat. |
| F6. Option du fournisseur pour les débits, pour ses services | Fiche ; plus tard, la mention de la facture électronique reçue | Seulement si l'achat contient des lignes de service d'un fournisseur établi en France. | — (sans elle, la déduction a lieu au paiement : voir QE14) | Fiche ; achat. |
| F7. Devise habituelle | Fiche. EUR par défaut. | Une fois. | — | Fiche. |

### 3.5 Faits du catalogue

| Fait | Source par défaut | Quand le demander | Si absent | Conservation |
|---|---|---|---|---|
| K1. Bien ou service | Dérivé : un produit est un bien, un service est un service, une ligne de frais d'achat est un service accessoire | Jamais | — | doc (`nature` de la ligne, existant) ; achat. |
| K2. Taux de vente du produit | Fiche produit : taux du territoire par défaut, libre (B2) ; jamais modifié à la vente (B1) | Une fois, sur la fiche | — (taux du territoire) | Fiche ; JET 270 (existant) ; doc (taux de la ligne). |
| K3. Prix de référence TTC et taux d'un service | Fiche service (R2, prévu) | Une fois, par un membre qui a « accorder des remises et fixer le prix d'un service » | **B** pour un vendeur sans cette autorisation : « Le prix de ce service n'est pas fixé » (R2) | Fiche ; doc. |
| K4. Frais accessoires : port, emballage, assurance | Fiche service (existant) | Une fois | — | Fiche ; décision. |
| K5. Lieu fixé par la loi (art. 259 A) | Fiche service (existant) | Une fois | — | Fiche ; décision. |
| K6. **Particularité fiscale** du produit : aucune, soumis à accises (alcool, tabac, énergie), moyen de transport neuf ; plus tard, bien d'occasion (étape 14) | Fiche produit. « Aucune » par défaut. | Une fois, dans une section repliée « Fiscalité particulière » de la fiche | — | Fiche ; décision. |
| K7. Catégorie fiscale du produit, qui donnera le taux par territoire et les taux des 27 États pour l'OSS | Fiche, phase 2 de la décision I1 | Plus tard seulement, par une liste en mots simples (« Alimentation », « Livres »…) | — | Fiche. |

### 3.6 Faits d'une vente (facture, devis, ticket)

| Fait | Source par défaut | Quand le demander | Si absent | Conservation |
|---|---|---|---|---|
| D1. Régime (nature de l'opération) | **Proposé** par le moteur ; vente en France tant qu'aucun autre régime n'a toutes ses conditions | Jamais demandé comme tel. Une proposition à confirmer (« Appliquer ») ; menu « Changer de régime » pour les utilisateurs avertis | — (vente en France) | doc (`nature_operation`, existant) ; décision. |
| D2. **Les marchandises quittent-elles la France ?** : non (remises ou livrées en France) / oui, expédiées par le magasin ou pour son compte / oui, emportées ou transportées par le client | Non, pour un particulier et pour un client établi en France | **Si** le document contient des biens **et** si le client est établi hors du territoire de TVA français, ou a un n° de TVA étranger, ou si une adresse de livraison est hors de France. Pour tout autre client, un lien discret « Expédition hors de France ? » | **B** à l'émission tant que la question est sans réponse pour un tel client (QE2) | doc : généraliser `transport_par` (existant, aujourd'hui pour la seule livraison intracommunautaire) en « sortie des biens » ; décision. |
| D3. Adresse et pays de livraison réelle | Adresse du client (existant, `ChoisitLaNatureDeLOperation.php:295-315`) | Si D2 = oui. Aussi « Livrer à une autre adresse » pour une vente en France (mention de la réforme quand l'adresse diffère) | **B** pour un régime de livraison hors de France (existant) | doc (`livraison_*`, existant). |
| D4. Date de livraison | Aucune | Si livraison intracommunautaire (obligatoire, existant). Facultative ailleurs | **B** pour une livraison intracommunautaire (existant) | doc (existant). |
| D5. Lieu de départ des biens | **Dérivé** : le territoire du magasin, puisque le stock est celui du magasin et qu'aucun transfert entre magasins n'existe (CLAUDE.md) | Jamais | — | décision (corrige la traçabilité de C3). |
| D6. Consultation VIES retenue | Dérivée : dernière consultation valide du jour, faite automatiquement par le domaine | Jamais (bouton « Vérifier à nouveau » dans le panneau) | **B** selon C7 | doc (existant). |
| D7. Le client demande une facture (particulier) | Non | Pour un particulier seulement, en caisse et sur la facture (complément 13) | **B** si cochée sans nom ni adresse (complément 13) | doc ; série des factures ; circuit B2C inchangé. |
| D8. Confirmation de la proposition (auteur, instant) | Saisie par le clic « Appliquer » | — | — | décision. |
| D9. Identité du client sur une facture : nom et adresse | Fiche | Si facture (professionnel, ou particulier qui demande une facture) | **B** à l'émission (nouveau pour l'adresse d'un professionnel ; mention obligatoire de la facture) | doc (`client_*`, existant). |
| D10. Preuves : MRN, CN23, documents de transport, attestation de l'acquéreur | Aucune | Après l'émission (existant, I4) ; « Établie par » obligatoire et deux parties différentes pour les transports d'une livraison intracommunautaire (complément 4) | **A** : badge et liste « Preuves à fournir » (existant) | Ajout seul, hors contenu signé (existant) ; annulation tracée (complément 4). |
| D11. Bordereau de détaxe (BVE) | Aucune | Après l'émission d'un ticket (existant, I3) | — | Ajout seul (existant). |
| D12. Régime non retenu : la TVA française gardée alors qu'un autre régime était possible | Saisi par le choix « Rester à la TVA française » | — | — | décision (motif facultatif, QE3). |

### 3.7 Faits d'un achat (facture d'achat et lignes)

#### En-tête de la facture d'achat

| Fait | Source par défaut | Quand le demander | Si absent | Conservation |
|---|---|---|---|---|
| H1. **Origine réelle des marchandises** : France / autre pays de l'UE (pays) / hors UE (pays, avec code postal pour les territoires exclus) | 1) dernière facture **validée** du même fournisseur, dans le magasin ; 2) sinon : France pour un fournisseur établi en France, et pour un fournisseur étranger, son pays **proposé à confirmer** | **Si** l'achat contient des produits **et** que le fournisseur n'est pas établi en France ; sinon un lien « Modifier l'origine » | **B** à la validation, pour un fournisseur étranger dont l'origine n'est pas confirmée | achat (nouvelle colonne `pays_expedition`) ; décision. |
| H2. Lieu d'arrivée | **Dérivé** : le magasin | Jamais | — Un magasin des Antilles ou de La Réunion qui importe relève d'un cas exclu (octroi de mer) : **B**, avec la saisie manuelle de QE7 | décision. |
| H3. **Qui a dédouané** : nous (ou notre transitaire) / le fournisseur (marchandises livrées dédouanées) | Dernière facture validée du même fournisseur | Si H1 = hors UE, un DOM ou un territoire exclu | **B** à la validation | achat ; décision. |
| H4. Référence de la déclaration en douane (MRN), date du bon à enlever | Aucune | Si H3 = nous | **A** et liste « Dédouanement à compléter » (QE5) | achat. |
| H5. Droits de douane et autres taxes non récupérables ; frais de transit et de transport jusqu'au magasin | Aucune ; plus tard, la facture du transitaire | Si H3 = nous (droits) ; frais : par une ligne de frais (L1) | **A** : coût du stock provisoire, corrigé quand c'est complété (le grand livre est rejoué, mécanisme existant `StockLedgerService::corrigerUneReception()`) | achat ; quote-part par ligne (L5). |
| H6. Base et montant de la TVA à l'importation | **Repris** de la déclaration en douane ou du relevé ATVAI, jamais recalculé ; le moteur propose la base (valeur + droits + frais) × taux pour contrôle | Si H3 = nous | **A** ; écart signalé | achat ; décision. |
| H7. Date de réception des marchandises (exigibilité d'une acquisition intracommunautaire : le 15 du mois suivant, ou la date de la facture si elle est antérieure) | Date de la facture | Si acquisition intracommunautaire (champ prérempli) | — (valeur par défaut) | achat. |
| H8. N° et date de la facture du fournisseur | Saisie ; plus tard, la facture électronique reçue | Toujours visibles (existant) | **B** pour un fournisseur non établi en France (l'e-reporting « in » les exige) ; **A** sinon | achat (existant). |
| H9. Devise et taux de change | Devise habituelle du fournisseur (F7) ; taux saisi, ou d'une source officielle (Q8 de l'inventaire) | Si la devise n'est pas EUR | **B** à la validation sans taux | achat (`devise`, existant ; taux nouveau). |
| H10. Mentions de la facture reçue : « Autoliquidation », n° de TVA des deux parties, exonération (art. 138 de la directive), « TVA non applicable, art. 293 B » | Facture électronique reçue (étape 2) ; sinon une case « La facture porte la mention… » proposée selon le régime | Seulement pour les régimes qui l'attendent | **A** seulement (BOI-TVA-DED-40-10-10, d'après reglementation.md 5) | achat. |

#### Lignes de la facture d'achat

| Fait | Source par défaut | Quand le demander | Si absent | Conservation |
|---|---|---|---|---|
| L1. **Type de ligne** : produit reçu en stock / frais d'acheminement (port, transit, assurance : ils entrent dans le coût des produits) / autre service (n'entre pas dans le stock) | Dérivé du bouton utilisé : « Ajouter un produit » ou « Ajouter des frais ou un service » | — | — | achat (nouvelle colonne `type_ligne`). |
| L2. Taux et montant de la **TVA facturée** par le fournisseur | Fournisseur établi en France (et pas en franchise) : taux de vente du produit, modifiable ligne par ligne **sans toucher la fiche** ; pour un service, le taux du territoire. Acquisition intracommunautaire, importation, autoliquidation, franchise : **0, champ caché**. | Toujours visible pour un achat en France ; caché sinon | **B** si un fournisseur de l'UE a facturé la TVA de son pays et que la société est redevable (question A-Q4) | achat (ligne). |
| L3. **Taux de la TVA due en France** (autoliquidée) | Taux de vente du produit ; taux du territoire pour un service ou des frais | Seulement dans les régimes d'autoliquidation, prérempli, modifiable avec l'alerte de taux inhabituel (X8) | — | achat (ligne). |
| L4. Lieu fixé par la loi, pour un service acheté (immeuble, transport de personnes, restauration, location courte de véhicule, événement) | Non | Seulement pour une ligne de service d'un fournisseur non établi en France (case à cocher) | **B** si cochée : cas exclu, saisie manuelle (QE7) | achat (ligne). |
| L5. Quote-part des frais d'approche et des droits | **Dérivée** : au prorata de la valeur HT des lignes de produits (Q9 de l'inventaire) | Jamais (détail affiché) | — | achat (ligne). |
| L6. Coût unitaire d'entrée en stock, au dix-millième | **Dérivé** : PU HT net (remise, escompte) + quote-part + taxes non récupérables, plus la TVA non récupérable pour une société en franchise | Jamais | — | achat (ligne) ; c'est ce coût que reçoit `StockLedgerService::receptionner()`. |
| L7. TVA récupérable | **Dérivée** : TVA facturée ou autoliquidée, sauf en franchise (0) | Jamais | — | achat (ligne). |

### 3.8 Faits dérivés, jamais saisis

| Fait | Calcul | Remarque |
|---|---|---|
| Territoire de TVA d'un tiers ou d'une adresse | Pays + code postal : métropole, Corse, Guadeloupe-Martinique-La Réunion, Guyane-Mayotte (hors TVA), collectivité d'outre-mer, autre État membre, Irlande du Nord (XI), territoire exclu (Canaries…), Monaco (traité comme la France), pays tiers | Une seule fonction pour toutes les adresses : corrige C2 et C10. « Identifié par un SIREN » reste une notion distincte, qui ne sert qu'à exiger le SIREN. |
| Schéma d'identifiant pour l'e-reporting international | 0223 (n° de TVA de l'UE), 0227 (hors UE), 0228 (Nouvelle-Calédonie), 0229 (Polynésie française) | Recherche 1.2. |
| Circuit de transmission | Trois conditions de la FAQ (assujettis établis en France ; dans le champ de la TVA française ; règles de facturation françaises), plus les cas E7 et E13 | Facture électronique / e-reporting international sortant ou entrant / e-reporting B2C / hors plateforme / aucun. |
| Catégorie EN 16931, code VATEX, catégorie B2C (TLB1, TPS1, TNT1, TMA1) | Régime + bien ou service + statut de la société | Étape 2 (lot L5 de l'inventaire). |
| Méthode de calcul | Article par article (particulier, franchise) ou depuis le HT de ligne (professionnel, E1) | Le cas du professionnel non assujetti est à trancher (Q4 de l'inventaire). |
| Prix payé | TTC, ou TTC ÷ (1 + taux) hors TVA française (K3) | Existant (`app/Domain/Facturation/Services/ConstructeurLigneDocument.php:179-200`). |
| Mention, qui paie, preuves attendues, échéances annexes | Table datée des régimes | Factures K et AE au 15 du mois suivant ; rappels de l'état récapitulatif et de la DES. |
| Exigibilité et date de déduction | Régime + date + option pour les débits | Pour l'étape 5. |

### 3.9 Ordre de préremplissage, et ses limites

1. **Le document lui-même**, s'il a déjà une valeur : brouillon rouvert, devis converti.
2. **La fiche du tiers** : client ou fournisseur.
3. **Le dernier document validé du même tiers, dans le même magasin.** Côté achat : origine, dédouanement, devise. Côté vente : aucun fait n'en est repris, la fiche suffit.
4. **Le magasin, puis la société** : territoire, taux par défaut, statut de TVA.
5. **La valeur sûre** : vente en France, non assujetti jamais supposé, origine non supposée pour un fournisseur étranger.

Ce qui ne se préremplit **jamais** depuis un document précédent :
- un résultat VIES d'un autre jour ;
- une référence de douane ;
- une preuve ;
- une date de livraison ;
- une confirmation de régime.

Chaque valeur préremplie affiche sa source en petit, par exemple « Comme sa facture d'achat du 02/10/2026 ». Elle est enregistrée avec sa source dans la décision : « saisi », « prérempli : fiche », « prérempli : facture d'achat n° … » ou « dérivé ».

### 3.10 Conservation : la décision fiscale tracée

**Structure proposée**, en objet immuable comme `ContexteDeVente` ou `Emetteur` aujourd'hui :

```
decision_tva
  version_regles        version de la table des régimes et des mentions (datée)
  calculee_le           instant
  regime                code du régime, et son libellé lisible
  etat                  applicable | possible (conditions manquantes) | indisponible
  vendeur               statut (redevable ou franchise), territoire, n° de TVA présent
  tiers                 type, assujetti, entité publique, territoire, établi en France,
                        n° de TVA, consultation VIES retenue (identifiant)
  operation             biens, services, frais accessoires, sortie des biens, départ,
                        arrivée, date de livraison ;
                        à l'achat : origine, dédouanement, référence de douane
  conditions[]          { code, libellé en langage simple, remplie oui ou non, preuve }
  faits_sources[]       { fait, valeur, source : saisi, prérempli (d'où) ou dérivé }
  proposition           régime proposé, accepté par (identifiant de l'utilisateur), instant
  regime_non_retenu     régime possible, écarté par, instant, motif facultatif
  mention, qui_paie, circuit, preuves_attendues[], avertissements[]
  montants (achat)      HT, TVA facturée, TVA autoliquidée, TVA récupérable,
                        à payer au fournisseur, coût d'entrée en stock (par ligne)
```

**Ventes**
- La décision est signée dans le bloc « contexte » de `ContenuFiscal`, au moment même où la nature, la livraison et la consultation VIES le sont déjà.
- Après l'émission, la fiche, le PDF et un duplicata **relisent** la décision et les mentions signées. Ils ne rappellent jamais le moteur, comme l'émetteur aujourd'hui (corrige D5).
- Changer la forme du contenu signé implique de mettre à jour le vérificateur d'intégrité et `ModuleFiscal::VERSION`.

**Achats**
- La décision est figée sur la facture d'achat à la validation (colonnes de montants et instantané), et l'entrée correspondante est inscrite au journal d'audit de la société.
- Une modification après validation, permise seulement avec le droit de valider (C2), recalcule la décision et journalise l'avant et l'après.

**Données personnelles**
- La décision ne garde que les identifiants nécessaires : identifiant de l'utilisateur, jamais son nom ; aucune adresse IP ni navigateur, conformément à CLAUDE.md, « Journal d'audit ».

### 3.11 Changements de schéma indicatifs (à valider)

Ils suivent la règle du projet : une seule migration de création par table, modifiée en place, puis `migrate:fresh`. Aucune migration d'ajout.

| Table | Ajouts indicatifs |
|---|---|
| `clients` | `assujetti_tva` (booléen, vrai par défaut), `entite_publique` (booléen, faux par défaut) |
| `factures`, `avoirs` | Copies figées `client_assujetti_tva`, `client_entite_publique` ; `sortie_biens` (remplace et généralise `transport_par`) ; `decision_tva` (signée) |
| `devis` | `sortie_biens` ; décision non signée, recalculée |
| `fournisseurs` | `etabli_en_france`, `franchise_tva`, `option_debits_tva`, `devise` ; contrôle du pays comme pour les clients |
| `consultations_vies` | `fournisseur_id` nullable ; exactement un des deux, client ou fournisseur (contrainte) ; toujours en ajout seul et propre au magasin |
| `produits` | `particularite_fiscale` (aucune, accises, moyen de transport neuf ; plus tard, occasion). `taux_tva` reste **le taux de vente**. |
| `services` | `prix_ttc`, `taux_tva` (R2, déjà prévus) |
| `factures_achat` | `pays_expedition`, `code_postal_expedition`, `dedouane_par`, `reference_douane`, `date_bon_a_enlever`, `date_reception`, `taux_change`, `tva_importation`, `regime`, `decision_tva`, totaux distincts (TVA facturée, autoliquidée, récupérable, à payer) |
| `lignes_achat` | `type_ligne`, `produit_id` nullable pour une ligne de frais ou de service, `taux_tva_facture`, `montant_tva_facturee`, `taux_tva_france`, `montant_tva_autoliquidee`, `montant_tva_recuperable`, `quote_part_frais`, `cout_unitaire_stock` (4 décimales), `lieu_fixe_par_la_loi` |
| `societes` | Statut et cadence séparés ; historique daté des statuts (une table propre à la société, `BelongsToSociete`) ; `eori`. Plus tard : OSS. |

### 3.12 Isolation des magasins et des sociétés (protocole, point 2)

- **Rattachement.** Toutes les nouvelles colonnes de tiers, de documents, de consultations VIES et de factures d'achat sont sur des modèles d'un magasin (`BelongsToMagasin`). L'historique des statuts de TVA est sur la société (`BelongsToSociete`).
- **Préremplissage.** « Dernière facture validée du même fournisseur » se lit sous `MagasinScope`. Deux magasins d'une même société ont chacun leur fiche du même fournisseur allemand, sans copie de l'une à l'autre (CLAUDE.md). C'est un coût de saisie assumé par la règle d'isolation.
- **Compteurs de la société (S9).**
  - Les seuils s'apprécient au niveau du SIREN : seuil de la vente à distance, acquisitions d'une société en franchise, et plus tard seuil de la franchise.
  - Il faut donc lire des **totaux** dans les autres magasins de la société.
  - C'est une nouvelle lecture hors du magasin courant. Elle doit être explicite, filtrée sur la société, limitée à des sommes, testée, et **ajoutée à la liste exhaustive de CLAUDE.md** (« Seules lectures hors du magasin ou de la société courants… »), comme « l'existence d'une première vente de la société ».
- **Unicité.** Aucune règle d'unicité nouvelle n'est globale.

---

## 4. Comportement des écrans

### 4.1 Un moteur, trois usages

| Usage | Quand | Ce que fait le moteur |
|---|---|---|
| **Aperçu** | À chaque requête Livewire du formulaire, mémorisé une fois par requête (même mécanisme que `AjouteDesLignesDeVente.php:129-147`) | Régime proposé, conditions, questions à afficher, avertissements, montants. Aucun appel réseau : VIES est consulté à l'enregistrement de la fiche ou sur un bouton, jamais à la frappe. |
| **Contrôle** | À l'émission ou à la validation, sous le verrou du registre (vente) ou de la facture d'achat | Même évaluation, plus la consultation VIES du jour si elle manque, désormais **dans le domaine** (corrige D10). Refus avec la liste complète des manquements. |
| **Figement** | Juste après un contrôle réussi | Décision écrite et signée (vente) ou figée et journalisée (achat). |

L'écran ne fait qu'afficher ce que rend le moteur : régime, état, conditions, questions, avertissements, éléments bloquants, mention, qui paie, circuit, effet sur le prix.

### 4.2 Facture et devis de vente : les questions progressives

**Déclencheurs**

| Fait déclencheur | Question ou élément affiché | Réponses | Effet |
|---|---|---|---|
| Aucun : particulier, ou professionnel établi en France, sans adresse de livraison hors de France | **Rien.** Une ligne « TVA française — vente en France » et un lien discret « Expédition hors de France ? » | — | Vente en France. |
| Document avec des biens, **et** client établi hors du territoire de TVA français, ou n° de TVA d'un autre État, ou livraison hors de France | « **Les marchandises quittent-elles la France ?** » | « Non, remises ou livrées en France » · « Oui, nous les expédions » · « Oui, le client les emporte ou les fait transporter » | Non : vente en France. Oui : questions suivantes. |
| Réponse « Oui » | « **Où sont-elles livrées ?** » avec l'adresse du client préremplie : « Hauptstraße 1, 10115 Berlin, Allemagne · Utiliser une autre adresse » | Adresse ; pays dans une liste ; le territoire détecté s'affiche (« Canaries : hors TVA de l'UE », « Guadeloupe : DOM ») | Le moteur évalue export, DOM ou livraison intracommunautaire. |
| Livraison dans un autre État membre, client professionnel | « **Date de livraison prévue ?** » | Date | Condition de la livraison intracommunautaire. |
| « Le client les emporte » vers l'UE | Information : « Le client devra vous remettre une attestation de réception au plus tard le 10 du mois suivant. » | — | Preuve attendue (existant). |
| « Le client les emporte » hors UE | Information : « Export emporté par le client : pas encore géré, la vente reste à la TVA française (détaxe possible pour un voyageur). » | — | Vente en France (I1, phase 2). |
| Client professionnel de l'UE **sans** n° de TVA | « Ajoutez le n° de TVA du client pour exonérer » avec un bouton « Compléter la fiche », dans une fenêtre | — | Après saisie : VIES automatique, puis nouvelle évaluation. |
| VIES invalide ou injoignable | « VIES ne reconnaît pas DE123456789 aujourd'hui (11/10 à 10 h 32). La vente reste à la TVA française. » · bouton « Vérifier à nouveau » | — | Vente en France tant que rien ne change. |
| Particulier, livraison dans un autre État membre | **Avertissement** sur la vente à distance (QE8) | — | Vente en France. |
| Services à un professionnel établi hors de France | **Aucune question** : le lieu se déduit du client et du service | — | Prestation autoliquidée ou hors UE proposée, sauf pour un service au lieu fixé par la loi. |
| Biens et services non accessoires sur un même document à exonérer | « Une facture ne porte qu'un régime : facturez le service à part. » | — | **B** à l'émission (existant, désormais dit pendant la saisie). |
| Produit soumis à accises, ou moyen de transport neuf, dans une vente hors de France | « Vente hors de France de ce produit : non gérée automatiquement (accises / moyen de transport neuf). » | — | **B** pour le régime d'exonération ; la vente en France reste possible. |

**Formulation**
- Les questions sont des boutons (`ToggleButtons` de Filament 3.3), pas des listes.
- Elles apparaissent l'une sous l'autre, dans l'ordre du tableau, et disparaissent dès qu'elles ne s'appliquent plus.
- La liste « Nature de l'opération » disparaît du formulaire. Elle est remplacée par le panneau (4.3) et son menu « Changer de régime ». Ce menu ne liste que les régimes que le client et la société permettent (règle existante `NatureOperation::proposees()`, `NatureOperation.php:167-174`), chacun avec son état : « possible », « il manque : … » ou « impossible : … ».

### 4.3 Le panneau « Régime de TVA appliqué »

**Emplacement.**
- Sur grand écran : sous le choix du client, dans une colonne latérale.
- Sur téléphone : à pleine largeur, juste après le client.
- La même présentation se retrouve sur la fiche d'un document émis ou validé, relue depuis la décision figée.

**Cinq états**

| État | Présentation |
|---|---|
| **France** (cas courant) | Une ligne, repliée : « TVA française · vente en France ». Rien d'autre. |
| **Proposition** | Encadré bleu. « Régime possible : Livraison intracommunautaire (sans TVA française). Le client paiera **100,00 €** au lieu de **120,00 €**. » · bouton « Appliquer » · lien « Rester à la TVA française ». |
| **Appliqué** | Encadré vert, détail ouvert (exemple ci-dessous). |
| **Incomplet** | Encadré orange. « Livraison intracommunautaire possible. Il manque : la date de livraison ; un n° de TVA valide sur VIES aujourd'hui. Tant que ces informations manquent, la facture reste à la TVA française. » Chaque manque est un lien qui place le curseur sur le champ. |
| **Bloquant** | Encadré rouge, repris dans la fenêtre « Émettre ». « Impossible d'émettre : … ». Toujours la liste complète. |

L'état ne repose jamais sur la seule couleur : un mot l'accompagne toujours.

**Contenu de l'état « Appliqué » (exemple de Müller GmbH)**

```
Régime de TVA appliqué
Livraison intracommunautaire à un professionnel : sans TVA française

Taux                0 % sur toutes les lignes (la TVA française est retirée du prix catalogue)
Qui paie la TVA     le client, dans son pays (il déclare lui-même la TVA allemande)
Mention imprimée    « Exonération de TVA, article 262 ter I du CGI »

Pourquoi
  Client professionnel établi en Allemagne ..................... oui
  N° de TVA DE123456789 valide sur VIES aujourd'hui à 10 h 32 .... oui
  Marchandises expédiées par le magasin, de Lyon à Berlin ....... oui
  Date de livraison : 14/10/2026 ................................ oui
  Uniquement des marchandises et leurs frais de port ............ oui

Après l'émission    deux documents de transport, de deux transporteurs ou organismes différents
À faire             émettre la facture au plus tard le 15/11/2026 ; état récapitulatif du mois (douane)
Transmission        e-reporting international (client établi hors de France)
```

**Textes par régime** (ligne de titre et phrase « qui paie »)

| Régime | Titre | Qui paie et explication |
|---|---|---|
| Vente en France | TVA française | « Le client paie la TVA comprise dans vos prix. » |
| Export expédié | Export hors de l'Union européenne : sans TVA française | « Le client paie votre prix sans la TVA française (120,00 € → 100,00 €). À conserver : la déclaration d'export (MRN) ou le CN23 du colis. » Pour le Royaume-Uni, la Suisse ou la Norvège : « Le pays d'arrivée peut percevoir sa propre TVA : renseignez-vous avant d'expédier. » |
| Livraison vers un DOM | Expédition vers la Guadeloupe (ou autre DOM) : sans TVA française | « La TVA locale et l'octroi de mer sont payés à l'arrivée. » |
| Livraison intracommunautaire | Voir l'exemple ci-dessus | « Le client déclare la TVA de son pays. » |
| Prestation à une entreprise de l'UE | Prestation à une entreprise de l'Union : TVA du pays du client | « Le client déclare la TVA de son pays (autoliquidation). Mention : « Autoliquidation ». Pensez à la déclaration européenne de services du mois. » |
| Prestation hors UE | Prestation à une entreprise hors Union : hors TVA française | « Aucune TVA française. Cette facture ne doit contenir que ces services. » |
| Franchise de la société | Votre société est en franchise de TVA | « Aucune TVA : « TVA non applicable, art. 293 B du CGI ». » |
| Client public (information) | — | « Client public : cette facture ne passe pas par SUPER PDP (Chorus Pro). » (E7) |
| Client non assujetti (information) | — | « Ce client n'est pas assujetti : la facture ne passe pas par la facture électronique. » |
| Vente à 0 % non transmissible (information) | — | « Transmission : pas encore gérée pour ce régime ; la facture reste hors plateforme. » (E13) |

### 4.4 Bloquant ou avertissement

**Règle (P7).**
- **Bloquant** : si le fait manque, le régime retenu est inapplicable, ou un montant dû, payé ou déclaré serait faux au moment de l'acte.
- **Avertissement** : le fait se complète après, ou il ne relève que de la prudence ; le document émis ne change pas.
- Un refus dit **tout** d'un coup, identité légale comprise, puis propose la voie sûre (« Rester à la TVA française », ou « Enregistrer en brouillon »).

**Ventes**

| Contrôle | Niveau | Moment | Origine |
|---|---|---|---|
| Conditions d'un régime d'exonération ou d'autoliquidation | B | Émission | Existant (`ControleNatureOperation`) |
| N° de TVA de la société, pour un régime autre que la vente en France | B | Émission | Existant |
| Identité légale de la société ou du magasin, pour une facture à un professionnel | B | Émission (annoncé dès le choix du client, comme en caisse) | Existant (`Caisse.php:397-416`), à étendre aux factures |
| SIREN d'un client professionnel établi en France | B | Émission | Existant |
| Nom et adresse du client sur une facture | B | Émission | Nouveau (mention obligatoire) |
| Type de client ou statut de TVA changé depuis l'enregistrement | B | Émission | Existant |
| Sortie des biens sans réponse pour un client étranger | B | Émission | Nouveau (QE2) |
| Produit à accises ou moyen de transport neuf dans un régime hors de France | B | Émission | Nouveau |
| Biens et services mélangés dans un régime qui ne les permet pas | B | Émission (affiché pendant la saisie) | Existant |
| « Le client demande une facture » sans nom ni adresse | B | Émission | Complément 13 |
| Proposition non appliquée : exonération possible, TVA française gardée | A (information) | Saisie | Nouveau |
| Vente à distance à un particulier de l'UE | A | Saisie et émission | Nouveau (QE8) |
| Services à un professionnel d'un DOM | A | Saisie | Nouveau (QE9) |
| Taux inhabituel pour le territoire | A | Saisie | Existant (X8) |
| VIES : nom ou adresse différents de la fiche | A | Saisie | Nouveau |
| Preuves d'exonération à fournir | A | Après l'émission | Existant (I4) |
| Échéance de facture au 15 du mois suivant ; état récapitulatif ; DES | A | Saisie et après | Nouveau |
| Devis exonéré dont les conditions ne sont pas réunies | A | Envoi du devis | Nouveau (aujourd'hui, rien) |

**Achats**

| Contrôle | Niveau | Moment | Origine |
|---|---|---|---|
| Origine des marchandises non confirmée, pour un fournisseur étranger | B | Validation | Nouveau |
| Dédouanement inconnu, pour une origine hors UE | B | Validation | Nouveau |
| TVA étrangère facturée par un fournisseur de l'UE (société redevable) | B | Validation | Nouveau (« Demandez une facture sans TVA en communiquant votre n° de TVA FR ») |
| Autoliquidation ou acquisition intracommunautaire sans n° de TVA de la société (redevable) | B | Validation | Nouveau |
| Devise autre que l'euro sans taux de change | B | Validation | Nouveau |
| Cas exclus (autoliquidation du BTP, service au lieu fixé par la loi, opérations en chaîne, importation par un magasin des DOM) | B | Validation, sauf saisie manuelle autorisée (QE7) | Nouveau |
| N° et date de la facture d'un fournisseur non établi en France | B | Validation | Nouveau |
| Création de produits sans le droit de créer des produits | B | Validation | Complément 3 |
| Fournisseur archivé | B | Validation | Existant |
| Référence de douane, droits, TVA à l'importation à compléter | A, avec une liste « à compléter » | Validation et après | Nouveau (QE5) |
| VIES du fournisseur non vérifié ou invalide | A | Saisie | Nouveau |
| Mention attendue absente de la facture reçue | A | Saisie | Nouveau |
| Écart entre la TVA calculée et celle de la facture du fournisseur | A | Saisie | Nouveau (QE6) |
| Taux français inhabituel | A | Saisie | X8 |
| Fournisseur établi en France sans SIRET | A | Saisie | Nouveau |

### 4.5 Ce qui se recalcule en direct, et ce qui se fige

| Élément | Pendant la saisie (brouillon, devis) | À l'émission ou à la validation | Après |
|---|---|---|---|
| Régime proposé, conditions, questions | En direct, à chaque changement de client, de ligne, de sortie des biens ou d'adresse | Réévalués une dernière fois sous le verrou, puis figés | Jamais réévalués ; l'avoir hérite |
| VIES | À l'enregistrement de la fiche, et sur « Vérifier à nouveau » ; le panneau affiche la dernière consultation du jour | Consultation du jour exigée, faite par le domaine si elle manque | Consultations conservées (ajout seul) |
| Taux de chaque ligne | En direct : fiche du produit ou du service, et régime | Figés | — |
| Prix payé (K3) | En direct ; tout changement de régime affiche l'effet sur le total | Figé | — |
| Mentions | Aperçu | Figées et signées | Le PDF et le duplicata les relisent |
| Circuit | Aperçu | Figé dans la décision | Statut de transmission (étape 2) |
| Preuves attendues | Annoncées | Liste figée avec la décision | Rappels jusqu'à ce qu'elle soit complète |
| Achat : montants, coût du stock | En direct dans la fenêtre de ligne et dans les totaux | Figés ; coût envoyé au grand livre du stock | Modification avec le droit de valider : nouveau calcul, journal d'audit, grand livre rejoué (existant) |
| Brouillon rouvert, devis converti | Réévalué ; une notification persistante dit ce qui a changé (généralise `ChoisitLaNatureDeLOperation.php:142-165`) | — | — |
| Fiche client modifiée après le brouillon | Le brouillon est réévalué à son ouverture | Refus si le type ou le statut a changé (existant) | Le document émis garde sa copie figée |

### 4.6 Caisse

- **Ticket ordinaire.** **Aucune question, aucun panneau.** Le total montre déjà le HT et la TVA, et la caisse reste « Vente en France » : marchandises remises au comptoir (recherche N1).
- **Client professionnel au comptoir.** Facture de la série FAC, vente en France, comme aujourd'hui. Quand le client est établi hors de France, une ligne d'information s'affiche : « Vente en France : marchandises remises en magasin. Pour une expédition hors de France, faites une facture. »
- **Services au comptoir à un professionnel établi hors de France.** Avertissement : « Pour des services à une entreprise étrangère, la TVA française ne s'applique pas toujours : faites une facture. » (QE10)
- **« Le client demande une facture »** (complément 13). Case visible pour un particulier seulement. Cochée, elle demande le nom et l'adresse, puis la vente part dans la série des factures, avec le circuit B2C inchangé.
- **Détaxe.** Rien à la vente. Le bordereau se note après coup sur la fiche du ticket (existant, I3).
- **Jamais d'exonération proposée en caisse.** L'export emporté par le client (phase 2) passera par l'écran de facture.

### 4.7 Devis, conversion, réouverture, avoirs

- **Devis.**
  - Même panneau et mêmes questions que la facture. La décision n'est pas signée.
  - À l'envoi, un **avertissement** (jamais un refus) liste les conditions non réunies, et le PDF peut porter « Exonération sous réserve de la vérification du n° de TVA le jour de la facture » (à valider).
  - À la conversion, la facture repart du devis et le moteur réévalue à son émission (VIES du jour).
- **Avoir.**
  - **Aucune question.** Il reprend la décision de sa facture : régime, taux, mention, catégorie.
  - L'écran de création d'un avoir sur plusieurs factures regroupe les documents proposés par régime et grise ceux d'un autre régime dès qu'un premier est choisi, au lieu de refuser à l'enregistrement (`AvoirResource.php:107-118` ; `CreerAvoirAction.php:113-117`).

### 4.8 Facture d'achat

**Déclencheurs**

| Fait déclencheur | Question (exemples de formulation) | Réponses | Effet |
|---|---|---|---|
| Fournisseur établi en France, pas en franchise | **Rien.** Ligne « Achat en France : TVA facturée par le fournisseur, récupérable » | — | Une ligne s'enregistre comme aujourd'hui, mais sa TVA est propre à la ligne. |
| Fournisseur établi en France, ligne saisie à 0 % (A-Q5) | « **Pourquoi cette ligne n'a pas de TVA ?** » | « Le fournisseur est en franchise (mention 293 B) » [Mémoriser pour ce fournisseur] · « La facture porte « Autoliquidation » » · « Erreur de saisie » | Franchise : aucune TVA. « Autoliquidation » : cas exclu (QE7). Erreur : retour au taux de la fiche. |
| Fournisseur non établi en France, avec des produits (A-Q1) | « **D'où les marchandises ont-elles été expédiées ?** » ; préremplie : « Comme sa dernière facture : Allemagne » | France · Autre pays de l'UE (liste) · Hors Union européenne (liste) | Acquisition intracommunautaire, importation, ou livraison en France par un fournisseur non établi. |
| Origine hors UE (A-Q2) | « **Qui a dédouané les marchandises ?** » | « Nous, ou notre transitaire » · « Le fournisseur : livrées dédouanées » | Importation par le magasin, ou livraison en France par le fournisseur (autoliquidation). |
| Dédouanées par nous (A-Q3) | « **Déclaration en douane** » : référence (MRN), droits de douane payés, TVA à l'importation indiquée sur la déclaration ; avec l'aide « Vous pourrez les compléter plus tard » | Saisie | Coût du stock et TVA à l'importation. |
| Origine UE, TVA saisie sur une ligne (A-Q4) | « **Ce fournisseur vous a-t-il facturé la TVA de son pays ?** » | Oui · Non, erreur de saisie | Oui, société redevable : **B**, avec le message. Société en franchise sous le seuil : TVA étrangère dans le coût (QE15). |
| Ligne de service ou de frais d'un fournisseur non établi | Case « Concerne un immeuble, un transport de personnes, un repas, une location courte de véhicule ou un événement » | Cochée ou non | Non cochée : autoliquidation. Cochée : cas exclu. |
| Devise habituelle autre que l'euro | « **Taux de change appliqué** » | Saisie | Montants en euros. |
| Acquisition intracommunautaire | « **Date de réception des marchandises** », préremplie avec la date de la facture | Date | Exigibilité. |

**Lignes**
- « Ajouter un produit » garde la fenêtre actuelle, découpée en deux blocs.
  - **« Vente en magasin »** : les champs de la fiche produit, prix de vente TTC et **taux de TVA de vente**. Leur correction reste immédiate (complément 2) et le changement de taux reste inscrit au JET 270.
  - **« Cette facture »** : quantité, PU HT, remise, TVA facturée (cachée et à 0 hors achat en France), TVA due en France (seulement en autoliquidation).
  - Le taux d'achat ne réécrit **jamais** le taux de vente : cela supprime C1 sans aucune question de plus.
- « Ajouter des frais ou un service » (nouveau) : description, type (frais d'acheminement / autre service), montant HT, TVA selon le régime.

**Totaux** (remplacent le libellé « à payer au fournisseur »)

```
Total HT des marchandises et frais ......................... 840,00 €
TVA facturée par le fournisseur ...........................    0,00 €
À payer au fournisseur ..................................... 840,00 €
TVA que vous déclarez vous-même (autoliquidation, 20 %) ...  168,00 €
TVA récupérable sur la même déclaration ...................  168,00 €
Coût d'entrée en stock ...................... 840,00 € (8,40 € par article)
```

**Panneau d'un achat (exemple de Spielwaren AG)**

```
Régime de TVA de cet achat
Achat dans l'Union européenne (acquisition intracommunautaire)

Qui paie la TVA     vous la déclarez à la place du fournisseur, et la récupérez
                    sur la même déclaration : rien à payer en plus
Coût du stock       hors TVA, port compris (40,00 € répartis sur les articles)

Pourquoi
  Fournisseur établi en Allemagne, n° DE… valide sur VIES le 02/10/2026 ......... oui
  Marchandises expédiées d'Allemagne vers votre magasin de Lyon (comme la
  dernière facture) ............................................................. oui
  Votre société a un n° de TVA français ....................................... oui
  Facture sans TVA allemande ................................................... oui

À vérifier          la facture doit porter les deux n° de TVA et la mention d'exonération
Déclaration         e-reporting international « achat » (étape 2)
```

**Autres textes**

| Situation | Texte |
|---|---|
| Importation par le magasin | « Vous déclarez la TVA à l'importation (136,80 €, selon la déclaration en douane) et la récupérez sur votre déclaration. Les droits de douane (24,00 €) et le transport (60,00 €) entrent dans le coût du stock. » |
| Marchandises livrées dédouanées par le fournisseur | « Le fournisseur a dédouané : s'il n'est pas établi en France, vous déclarez la TVA à sa place (sa facture porte « Autoliquidation »). » |
| Fournisseur en franchise | « Aucune TVA : le fournisseur est en franchise. » |
| Société en franchise | « Votre société ne récupère pas la TVA : elle entre dans le coût du stock. » |

### 4.9 Fiches et réglages

- **Fiche client.**
  - Elle reste en trois sections. Une section « **Fiscalité** » repliée s'y ajoute : « Ce client n'est pas assujetti à la TVA » et « Organisme public ».
  - Le n° de TVA affiche son état VIES à côté du champ. La vérification se lance à l'enregistrement, et le bouton existant reste.
  - Le pays se choisit dans une liste nommée (noms en français par l'extension `intl`, déjà présente puisque `filament/support` l'exige, sans paquet nouveau).
  - La création rapide garde son contenu actuel, avec la liste de pays en plus.
  - Dans la liste des clients, une colonne activable affiche un badge : « UE, n° valide (VIES 11/10) », « Hors UE », « Non assujetti », « Public ».
- **Fiche fournisseur.**
  - Elle reçoit les mêmes contrôles de pays que le client, « Établi en France » (si le pays est étranger), le n° de TVA avec VIES, et la section repliée « Fiscalité » : ne facture pas de TVA, option pour les débits, devise habituelle.
- **Fiche produit.**
  - Le libellé devient « Taux de TVA de vente (%) ».
  - Une section repliée « Fiscalité particulière » s'ajoute : aucune, soumis à accises, moyen de transport neuf.
  - La tuile « Valeur du stock » affiche la valeur **au coût d'achat** (CMUP), hors TVA pour une société redevable, TVA non récupérable comprise pour une société en franchise, au lieu du TTC au taux de vente (`app/Filament/App/Resources/ProduitResource/Widgets/ValeurStockApercu.php:59-64`, C7). À confirmer (Q3 de l'inventaire).
- **Fiche service.** Les deux interrupteurs existants restent, en plus du prix et du taux de référence de R2.
- **Société > Paramètres fiscaux.**
  - « Statut de TVA » (redevable ou franchise) et « Depuis le » ; « Cadence » ; option pour les débits ; EORI ; plus tard, OSS.
  - Le n° de TVA reste sur la page Identité, prérempli depuis le SIREN.
  - Un changement de statut avertit : « Les brouillons et devis en cours seront recalculés à leur ouverture. »

### 4.10 API d'achat

**Aujourd'hui.**
- Il n'existe pas de route HTTP : `routes/web.php` est le seul fichier de routes, et `app/Http/Controllers` ne contient rien pour les achats.
- Le point d'entrée est l'action du domaine `CreerFactureAchatAction::handle(Fournisseur, lignes, attributs)` (`app/Domain/Achats/Actions/CreerFactureAchatAction.php:27-38`), avec la source « API » (`app/Domain/Achats/Enums/SourceFactureAchat.php`).
- Elle servira à la réception SUPER PDP (étape 2) et à l'API v1 (étape 10).

**Règles proposées**
1. **Même moteur, mêmes faits.** La charge utile peut porter `origine` (`pays_expedition`, `code_postal_expedition`), `dedouane_par`, `reference_douane`, `date_reception`, `devise`, `taux_change`, et pour chaque ligne `type_ligne`, `taux_tva_facture`, `taux_tva_france` (facultatif) et `lieu_fixe_par_la_loi`.
2. **Préremplissage identique** à l'écran : fiche du fournisseur, dernière facture validée. La réponse indique la source de chaque fait (saisi, prérempli, dérivé).
3. **Un brouillon n'est jamais refusé** pour un fait fiscal manquant. Il est créé avec `regime` (éventuellement « indéterminé ») et `informations_manquantes[]`, comme une facture reçue par SUPER PDP, qui s'importe en brouillon (CLAUDE.md, « Facture électronique »).
4. **La validation est refusée** (HTTP 422 pour l'API v1) avec **tous** les manquements d'un coup. Chacun porte :
   - un code stable : `tva.origine_non_confirmee`, `tva.dedouanement_inconnu`, `tva.tva_etrangere_facturee`, `tva.numero_tva_societe_absent`, `tva.devise_sans_taux`, `tva.cas_exclu`, `achat.numero_fournisseur_absent` ;
   - le chemin du champ ;
   - un message en français identique à celui de l'écran.

   Aucun stock n'entre et aucun numéro n'est consommé.
5. **Facture reçue par SUPER PDP** (étape 2). La catégorie (S, E, AE, K, G, O), les n° de TVA et les mentions **préremplissent** les faits. Une contradiction, par exemple un fournisseur établi en France avec une catégorie K, passe dans « à vérifier » : jamais de choix silencieux.
6. **Montants du fournisseur.** La TVA envoyée par la source sert au **rapprochement**. Le moteur calcule selon la règle du projet (HT de la ligne, puis la TVA sur ce HT) et signale l'écart (QE6).
7. **Jeton unique** sur la validation (R3, déjà décidé).

### 4.11 Autorisations

| Geste | Autorisation | Remarque |
|---|---|---|
| Répondre aux questions, appliquer une proposition de régime de vente | Créer la facture ou le devis (`facture.create`, `devis.create`) | Les conditions sont contrôlées par le moteur : aucun droit spécial n'est nécessaire pour appliquer une exonération réunie. |
| Rester à la TVA française malgré une proposition | Le même | Toujours permis, tracé (QE3). |
| Forcer une exonération ou une autoliquidation dont les conditions manquent | **Personne** | P2. |
| Émettre | `facture.emettre` (existant) | — |
| Ajouter une preuve, noter un bordereau | Politique `justifier` (existant) | — |
| Modifier les faits fiscaux d'une fiche | `client.update`, `fournisseur.update`, `produit.update` | Journal d'audit (étape 4). |
| Saisir et confirmer l'origine et le dédouanement d'un achat | `facture_achat.creer` | — |
| Valider un achat ; le modifier après validation | `facture_achat.valider` (existant, C2) | — |
| Saisie manuelle d'un régime d'achat exclu de l'automatique | `facture_achat.valider`, avec un motif obligatoire, journalisé | À trancher (QE7). |
| Prix d'un service dans une vente exonérée | Aucune dérogation : le prix de référence sans la TVA (K3) | À confirmer (QE13). La remise et un autre prix restent soumis à `vente.remises_et_prix` (R2). |
| Statut de TVA, option pour les débits, n° de TVA | `societe.parametres.gerer` (conception société, 4.3) | JET 270 dans chaque magasin (existant). |

### 4.12 Fiche d'un document émis ou validé

- La fiche affiche une section « **Régime de TVA** », relue de la décision figée : régime, conditions telles qu'elles étaient à l'émission, consultation VIES retenue, confirmation (qui, quand), version des règles, avertissements et preuves attendues.
- Pour une facture d'achat validée, elle affiche aussi les six montants des totaux d'achat (4.8) et le coût d'entrée en stock de chaque ligne.
- Elle remplace, pour la partie fiscale, la section « Transmission électronique » actuelle, qui annonce le PDP à tout professionnel (`FactureResource.php:279-294`). Le statut de transmission reste affiché à côté.

---

## 5. Pièges ergonomiques et parades

| # | Piège | Parade |
|---|---|---|
| 1 | Poser des questions internationales sur des ventes en France (99 % des opérations) | Déclencheurs stricts (4.2, 4.8). Aucun champ nouveau sans déclencheur. La liste de nature remplacée par une ligne. |
| 2 | Le jargon : « LIC », « AIC », « autoliquidation », « 262 ter I », « VATEX », « catégorie K » | Titre et phrase en mots simples. La référence légale seulement dans la mention et dans le détail « Pourquoi ? ». Aucun code technique à l'écran. |
| 3 | Un écran surchargé | Panneau replié en France. Questions une par une. Sections « Fiscalité » repliées sur les fiches. Aucune question en caisse. |
| 4 | Une bascule silencieuse de régime, qui change le prix (K3) | Proposition, confirmation, effet sur le total affiché, trace (P4). |
| 5 | Les erreurs découvertes à l'émission, une par une | Liste en direct dans le panneau. Refus unique et complet, identité légale comprise (P10). |
| 6 | Les pays en codes de deux lettres (GR ou EL ?), sans le territoire | Liste nommée. Territoire détecté avec le code postal et affiché (« Canaries : hors TVA de l'UE »). |
| 7 | VIES lent ou injoignable qui bloque la saisie | Jamais à la frappe. À l'enregistrement de la fiche, sur un bouton, et une fois à l'émission. Message clair : la vente reste à la TVA française. |
| 8 | « Montant payé au fournisseur TTC » faux en autoliquidation | Six montants distincts (4.8). |
| 9 | Le taux d'achat qui réécrit le taux de vente (C1) | Deux blocs dans la fenêtre de ligne. Les taux d'achat appartiennent à la ligne. |
| 10 | Préremplir depuis le pays du fournisseur et le tenir pour vrai | Préremplir depuis la dernière facture validée. Le pays du fournisseur seulement « proposé, à confirmer ». La source est affichée. |
| 11 | Exiger le dédouanement avant de pouvoir vendre la marchandise | Validation possible avec un avertissement et une liste « à compléter ». Coût corrigé ensuite (QE5). |
| 12 | Un menu de régimes qui laisse croire qu'on peut choisir librement | Menu « Changer de régime » limité aux régimes possibles, chacun avec ce qui lui manque. Une exonération jamais forçable. |
| 13 | Une couleur seule pour l'état | Toujours un mot : « Bloquant », « Il manque », « Appliqué ». |
| 14 | Des devis exonérés envoyés sans contrôle, puis une facture refusée | Avertissement à l'envoi du devis. Mention « sous réserve » à valider. |
| 15 | Un avoir sur plusieurs factures refusé à l'enregistrement pour mélange de régimes | Documents regroupés par régime ; les incompatibles grisés. |
| 16 | Des écrans qui recodent les règles et finissent par diverger | Un seul moteur. Les écrans n'affichent que son résultat (P6). |

---

## 6. Règles à soumettre à l'éditeur

Ce sont des règles métier ou d'ergonomie à trancher (protocole, point 4). Pour chacune, une recommandation est donnée. Elles s'ajoutent aux questions Q1 à Q11 de `inventaire-code.md`, sans les répéter.

| # | Question | Recommandation |
|---|---|---|
| QE1 | Le moteur **propose** le régime et le vendeur le confirme d'un clic (« Appliquer »), avec l'effet sur le prix affiché. Ou bien le moteur l'applique seul dès que les conditions sont réunies ? (Rejoint Q6 de l'inventaire.) | Proposition et confirmation. Jamais de bascule silencieuse, puisque le prix change (K3). |
| QE2 | La question « Les marchandises quittent-elles la France ? », pour un client établi hors de France et un document avec des biens : sans réponse par défaut, et exigée à l'émission (pas à l'enregistrement du brouillon) ? Ou bien « Non » par défaut ? | Sans défaut, exigée à l'émission. Une facturation à tort de la TVA française la rend due (art. 283-3), et le client étranger ne peut pas la déduire. |
| QE3 | Rester à la TVA française quand une exonération est possible : toujours permis, motif facultatif, tracé dans la décision ? | Oui. Une exonération n'est jamais forçable. |
| QE4 | Origine des marchandises d'un fournisseur étranger : préremplie depuis sa dernière facture validée sans nouvelle confirmation, et confirmée seulement la première fois ? | Oui, avec la source affichée. Confirmation à la première facture et à chaque changement de fournisseur. |
| QE5 | Importation : valider la réception (stock vendable) avant d'avoir la référence de douane et les droits, avec une liste « à compléter » et le coût du stock corrigé ensuite ? Ou bloquer la validation ? | Valider avec un avertissement, et bloquer seulement l'export comptable de la période (étape 5) tant que c'est incomplet. |
| QE6 | TVA facturée sur un achat : recalculée par ligne (règle CLAUDE.md : HT = quantité × PU HT, puis la TVA sur ce HT) ou reprise de la facture du fournisseur ? Le droit à déduction porte sur la TVA facturée, et une facture électronique reçue la donne par taux. | Garder le calcul par ligne pour les montants enregistrés, afficher l'écart avec la facture du fournisseur, et faire trancher par l'expert-comptable si l'écart doit être corrigé. **Règle de calcul : à trancher.** |
| QE7 | Cas exclus du calcul automatique (autoliquidation du BTP, service au lieu fixé par la loi, opérations en chaîne ou triangulaires, importation par un magasin des DOM, accises) : blocage pur ? Ou saisie manuelle du régime par un membre qui a « valider les factures d'achat », avec un motif, journalisée ? | Saisie manuelle encadrée. Sinon ces factures ne peuvent pas entrer en stock. |
| QE8 | Vente à distance à un particulier de l'UE avant la phase OSS : simple avertissement (TVA française) ou blocage de l'expédition hors de France à un particulier tant que le seuil de 10 000 € n'est pas suivi ? | Avertissement, plus un compteur de la société affiché dès maintenant (lecture de totaux, 3.12). |
| QE9 | Services à un professionnel établi en Guadeloupe, en Martinique ou à La Réunion (taux du DOM collecté par le prestataire), en Guyane ou à Mayotte (hors champ) : absent de la liste I1. Un avertissement, ou une nature de plus ? | Un avertissement maintenant, une nature dans la phase 2. |
| QE10 | Services vendus au comptoir à une entreprise établie hors de France : avertissement qui renvoie vers une facture, ou blocage en caisse ? | Avertissement. |
| QE11 | Préremplir une fiche client depuis le répertoire Sirene (SIREN → nom, adresse, catégorie juridique) avec l'intégration Sirene existante ? Cela consomme le quota de l'API de l'INSEE. | Oui, sur un bouton, jamais automatiquement. |
| QE12 | « Organisme public » proposé quand la catégorie juridique est 7xxx. « Non assujetti » : jamais proposé automatiquement (une association peut être assujettie) ? | Oui. |
| QE13 | Service vendu dans une vente exonérée, avec le prix de référence de R2 : le client paie le prix de référence TTC ÷ (1 + taux), comme un produit (K3 et complément 5), sans que cela compte comme une dérogation de prix ? | Oui. Le prix « sans TVA » saisi à la main d'aujourd'hui disparaît. |
| QE14 | PILIQI ne suit pas les paiements aux fournisseurs. Or la TVA des services achetés en France se déduit au paiement, sauf option du fournisseur pour les débits. Suivre la date de paiement à l'étape 5, ou laisser ce point à l'expert-comptable ? | À traiter à l'étape 5, avec un avertissement dès maintenant sur les lignes de service. |
| QE15 | Acquisitions intracommunautaires d'une société en franchise : sous 10 000 €, la TVA étrangère entre dans le coût ; au-delà, la TVA française est autoliquidée, non déductible et entre dans le coût. Faut-il les gérer dès le lot achats (compteur de la société, 3.12) ou les bloquer avec un message jusqu'à confirmation de l'expert-comptable ? | Bloquer avec un message jusqu'à confirmation. Les montants changent selon le seuil. |
| QE16 | L'adresse du client devient obligatoire sur toute facture (professionnel, ou particulier qui la demande) ? C'est une mention obligatoire, aujourd'hui facultative. | Oui, bloquant à l'émission. |

---

## 7. Vérification et compléments de la recherche fiscale (données et ergonomie)

| # | Point | Constat |
|---|---|---|
| 1 | **Établissement, et non pays d'adresse** | La FAQ du 16/01/2026 dit : « il convient d'abord de déterminer le lieu d'établissement de votre client ». Cela confirme qu'il faut garder « établi en France » à côté du pays, ce que fait déjà `etabli_en_france`. Les trois conditions de la facture électronique y sont lues mot pour mot. La FAQ confirme que les particuliers, en France ou à l'étranger, et les professionnels établis hors de France relèvent de l'e-reporting. |
| 2 | **Article 290 du CGI, lu dans le PDF** | Le chapeau du I exclut les opérations « exonérées en application des articles 261 à 261 E », en une seule condition. La recherche (1.2) retenait deux conditions cumulées d'après la doctrine. Sans effet pratique : PILIQI ne gère aucune de ces opérations (complément 10). |
| 3 | **Art. 290 I 4° a : Monaco, dans les deux sens** | Ventes, mais aussi **acquisitions** auprès de Monaco et services rendus par un prestataire monégasque. Côté achat, le circuit d'un fournisseur établi à Monaco (TVA française) se dérive du pays : aucune question de plus. |
| 4 | **Art. 290 I 4° b : acquisitions intracommunautaires non soumises (art. 258 D I)** | Ce cas est absent de la recherche. Il entre dans le champ de l'e-reporting même sans TVA. Le régime d'achat d'une société en franchise sous le seuil doit donc être enregistré comme tel dans la décision, et non comme un simple achat sans TVA. |
| 5 | **Art. 290 I 3° a, b et c** | Ce sont les trois régimes d'achat à reconnaître : acquisition intracommunautaire, livraison en France par un non-établi, services acquis auprès d'un non-établi. L'importation n'y figure pas, ce qui confirme qu'elle est hors e-reporting. D'où les questions A-Q1 et A-Q2 et la case « lieu fixé par la loi » des services achetés. |
| 6 | **Territoire et identification (C2)** | La recherche (1.4) range Saint-Barthélemy, Saint-Martin et Saint-Pierre-et-Miquelon parmi les pays tiers. Le code les traite « en France » (`TerritoireFiscal.php:22`, `app/Domain/Contacts/Models/Client.php:140-142`). Le modèle sépare donc « identifié par un SIREN » (exiger le SIREN) et « territoire de TVA » (décider). |
| 7 | **Départ des biens (C3)** | La recherche (N5) ne traite que la métropole vers un DOM. Le modèle enregistre le lieu de départ (le magasin) dans chaque décision. Un magasin des Antilles ou de La Réunion qui expédie hors de son DOM reste à trancher (Q5 de l'inventaire). En attendant, le moteur dit « non géré automatiquement » au lieu de mal classer la vente. |
| 8 | **VIES du fournisseur** | La recherche n'en parle pas. Pour l'acheteur, ce n'est pas une condition légale de l'acquisition intracommunautaire, mais c'est l'indice que le vendeur est un assujetti identifié dans un autre État membre (art. 256 bis). Avertissement seulement. |
| 9 | **Liste « données à ajouter » (recherche 1.12)** | Les points écartés ou déplacés, et pourquoi : <br>- « type : personne morale non assujettie » est remplacé par le booléen « assujetti », plus simple à l'écran ; <br>- l'Incoterm n'est pas demandé : c'est un indice, la preuve est la déclaration en douane ou le document de transport ; <br>- l'adresse électronique de facturation reste à l'étape 2 (annuaire) ; <br>- le SIREN des clients existe désormais. |
| 10 | **Vente à distance (N7) en phase 1** | Sans suivi du seuil, la TVA française n'est juste que sous 10 000 € de ventes à distance par an dans l'UE, pour toute la société. Rien ne le signale aujourd'hui : d'où QE8 et le compteur S9. |
| 11 | **Services à un client des DOM (N11)** | Ce cas n'est pas dans la liste I1. Aujourd'hui, PILIQI facture le taux de la métropole à un professionnel de la Guadeloupe pour un service, alors que la recherche indique le taux du DOM, collecté par le prestataire (QE9). |
| 12 | **Date de VIES pour une livraison intracommunautaire** | La décision I2 retient « valide le jour de l'émission ». La recherche (N6) parle d'un n° valide à la date de la livraison (Q11 de l'inventaire). Le modèle conserve la date de livraison et chaque consultation : les deux contrôles sont possibles sans nouvelle donnée. |
| 13 | **Paiements aux fournisseurs** | Ils ne sont pas suivis. La date de déduction des services achetés en France reste inconnue (QE14). |

---

## 8. Scénarios de test à automatiser

L'éditeur demande des tests par scénario. Ceux-ci portent sur le comportement des écrans et sur le moteur. Ils suivent le style des tests existants : français, `test_` puis une phrase.

**Ventes**
- `test_une_vente_a_un_particulier_en_france_n_affiche_aucune_question`
- `test_une_facture_a_un_professionnel_etabli_en_france_n_affiche_que_la_ligne_tva_francaise`
- `test_un_client_professionnel_allemand_fait_apparaitre_la_question_de_sortie_des_marchandises`
- `test_le_pays_du_client_seul_ne_propose_jamais_d_exoneration`
- `test_la_livraison_intracommunautaire_n_est_proposee_que_si_toutes_ses_conditions_sont_reunies`
- `test_appliquer_une_proposition_affiche_le_nouveau_total_et_se_trace_dans_la_decision`
- `test_rester_a_la_tva_francaise_est_toujours_possible_et_se_trace`
- `test_une_exoneration_dont_une_condition_manque_ne_peut_pas_etre_forcee`
- `test_le_panneau_et_l_emission_donnent_le_meme_regime`
- `test_un_refus_d_emission_dit_tous_les_manquements_identite_comprise`
- `test_un_vies_injoignable_laisse_la_vente_a_la_tva_francaise_sans_bloquer_la_saisie`
- `test_la_decision_figee_ne_change_pas_si_la_fiche_du_client_change_ensuite`
- `test_le_duplicata_imprime_les_mentions_signees`
- `test_un_devis_exonere_incomplet_s_envoie_avec_un_avertissement`
- `test_un_avoir_herite_de_la_decision_de_sa_facture_sans_aucune_question`

**Caisse**
- `test_la_caisse_n_affiche_aucune_question_pour_un_ticket`
- `test_la_caisse_avertit_pour_des_services_a_un_professionnel_etabli_hors_de_france`

**Achats**
- `test_un_achat_a_un_fournisseur_francais_n_affiche_aucune_question`
- `test_un_achat_a_un_fournisseur_allemand_demande_l_origine_preremplie_depuis_sa_derniere_facture_validee`
- `test_une_acquisition_intracommunautaire_calcule_la_tva_autoliquidee_sans_changer_le_taux_de_vente_du_produit`
- `test_les_frais_de_port_entrent_dans_le_cout_du_stock_au_prorata_de_la_valeur`
- `test_une_importation_par_le_magasin_ajoute_les_droits_de_douane_au_cout_du_stock`
- `test_une_facture_de_l_ue_portant_une_tva_etrangere_est_bloquee_pour_une_societe_redevable`
- `test_une_origine_non_confirmee_bloque_la_validation_d_un_achat_etranger`
- `test_l_api_d_achat_cree_un_brouillon_et_refuse_la_validation_avec_tous_les_codes_de_manquement`
- `test_une_facture_achat_validee_modifiee_recalcule_et_journalise_sa_decision`

**Isolation**
- `test_le_preremplissage_d_un_achat_ne_lit_que_les_factures_du_magasin`
- `test_les_consultations_vies_d_un_fournisseur_restent_dans_son_magasin`
- `test_le_compteur_des_ventes_a_distance_ne_lit_que_des_totaux_des_magasins_de_la_societe`
