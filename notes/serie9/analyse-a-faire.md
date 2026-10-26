# Série 9, demande 2 : « À faire » au tableau de bord — analyse

Analyse faite sur la copie figée du code `scratchpad/code-fa83824` (commit fa83824), en lecture seule, sans lancer de test. Règles métier : `CLAUDE.md` de cette copie, complété par les décisions plus récentes listées au § 7 de `pilotage-reprise/README.md` (notamment `decisions/reponses-regles-ecran-26-10.md` et `serie8/decision-espace-cabinets.md`). Rien n'est tranché ici : les choix métier sont au § 5, sous forme de questions à l'éditeur.

## 0. En bref

- Le nombre jaune du menu « Factures » compte les factures émises d'une vente hors de France (export, livraison vers un DOM, livraison intracommunautaire) dont une **preuve d'exonération** manque encore. Le cas de l'éditeur est l'« Export hors UE expédié par le magasin » : il faut noter le **n° MRN de la déclaration d'export (douane)** ou le **n° du CN23 (colis postal, La Poste)**.
- Il était difficile à comprendre parce que le nombre ne dit rien de lui-même (explication seulement au survol de la souris), que le clic sur « Factures » ouvre la liste **sans filtre**, que rien dans la liste ne distingue la facture concernée (son statut peut être « Payée », en vert), et que le filtre « Preuves à fournir » est caché dans le menu des filtres.
- Aujourd'hui, seules deux choses « à faire » sont sur le tableau de bord du magasin : les **factures à encaisser** (encart complet) et le **stock négatif** (une tuile). Une quarantaine d'autres choses en attente existent dans le code, mais ne se voient que dans leur liste, sur une fiche, dans la cloche des notifications, ou pas du tout (§ 2).
- Proposition (§ 4) : un encart « À faire » unique, en tête du tableau de bord du magasin et de celui de la société, calculé à chaque affichage depuis les données (jamais depuis les notifications), où chaque ligne dit en clair ce qui attend, combien, pourquoi, et mène d'un clic à l'endroit exact (la fiche s'il n'y en a qu'une, sinon la liste filtrée qui montre exactement ce nombre de lignes).
- Dix questions métier à poser à l'éditeur (§ 5), dont : la liste exacte des éléments, « à faire » contre « à surveiller », le sort du nombre jaune, et les compteurs par magasin dans l'espace de la société (nouvelle lecture hors du magasin, à inscrire dans `CLAUDE.md`).

---

## 1. Le nombre jaune du menu « Factures »

### 1.1 Où il est calculé

- `app/Filament/App/Resources/FactureResource.php`, lignes 126 à 146 :
  - `getNavigationBadge()` : `Facture::query()->visiblesPar(auth()->user())->preuvesAFournir()->count()` ; aucun badge quand le nombre vaut 0 ;
  - `getNavigationBadgeColor()` : `'warning'` (le jaune) ;
  - `getNavigationBadgeTooltip()` : « Factures dont une preuve d'exonération reste à fournir » (bulle qui n'apparaît qu'au survol de la souris).
- Le filtre de la requête : `Facture::scopePreuvesAFournir()` (`app/Domain/Facturation/Models/Facture.php`, lignes 417 à 425), qui délègue à `PreuvesRequises::incompletes()` (`app/Domain/Facturation/Services/PreuvesRequises.php`, ligne 108).
- La portée : `Facture::scopeVisiblesPar()` (`Facture.php`, ligne 311) : toutes les factures du magasin avec « voir celles de toute l'équipe » (`facture.view_toutes`), sinon les seules factures créées par la personne (`facture.view`), aucune sinon. Le comptage se fait sous `MagasinScope` : seulement le magasin ouvert.

### 1.2 Ce qu'il compte exactement

Une facture ou un ticket compte si les trois conditions sont réunies (`PreuvesRequises::incompletes()` en SQL, `PreuvesRequises::manquantes()` en PHP pour la fiche, qui disent la même chose) :

1. il est **émis** (pas un brouillon) ;
2. il garde **au moins une ligne créditable** (il n'est pas entièrement corrigé par des avoirs) ;
3. sa **nature d'opération** demande une preuve qui manque encore :

| Nature (`NatureOperation`) | Preuve attendue (`TypePreuve`) | Compte tant que… |
|---|---|---|
| Export hors UE expédié par le magasin (`ExportHorsUe`) | Déclaration d'export **MRN** avec certification de sortie (douane), ou déclaration **CN23** d'un colis postal (La Poste) | aucune preuve MRN ni CN23 n'est notée |
| Livraison vers un DOM (`LivraisonDom`) | La même déclaration (MRN ou CN23) **et** un document de transport de catégorie a) (CMR, connaissement, LTA, facture du transporteur) | l'une des deux manque |
| Livraison intracommunautaire (`LivraisonIntracommunautaire`) | Un document de transport a) ; un second document a) ou une autre preuve d'expédition b) ; et, si le client enlève lui-même les biens (`TransportPar::Acquereur`), son **attestation de réception**, attendue au plus tard le 10 du mois qui suit la livraison | l'une manque |

Ce qu'il ne compte pas : les ventes en France, les prestations (aucune preuve demandée), les brouillons, les tickets de détaxe (le n° du bordereau n'est pas une « preuve manquante » : PILIQI ne sait pas quel ticket sera détaxé, voir § 2, K1).

Deux détails :
- Le nombre est calculé parmi les factures que la personne **voit**, sans regarder si elle peut **agir** : un observateur (qui n'a pas, par défaut, « noter les preuves d'exonération et les bordereaux de détaxe », `facture.justifier`, voir `RoleMagasin::permissionsParDefaut()`) ou un expert-comptable voit le nombre jaune, mais pas le bouton « Ajouter une preuve » (`ViewFacture::ajouterUnePreuve()`, `->authorize('justifier')`).
- Le code ne fixe **aucun délai** pour le MRN ou le CN23 ; seule l'attestation de l'acquéreur a une date limite, et la fiche ajoute « délai dépassé » une fois le jour passé dans le calendrier du magasin (`PreuvesRequises::attestationAttendue()`, ligne 144). Le nombre jaune ne distingue pas ce retard.

### 1.3 Le cas décrit par l'éditeur

L'éditeur parle d'un produit « vendu hors de France et livré par le magasin lui-même », pour lequel il fallait « donner un code de la douane ou de La Poste ». C'est exactement la nature « Export hors UE expédié par le magasin » (`NatureOperation::ExportHorsUe`, dont le transport est imposé au magasin par `NatureOperation::transportImpose()`), avec sa preuve : le n° **MRN** (douane) ou le n° du **CN23** (La Poste). Une vente à un particulier peut aussi prendre cette nature (`NatureOperation::reserveeAuxProfessionnels()` ne la réserve pas aux professionnels).

### 1.4 Pourquoi il était si difficile de comprendre de quoi il s'agissait

1. **Le nombre ne dit rien de lui-même.** Seule une bulle, au survol de la souris, l'explique ; sur une tablette ou un téléphone, il n'y a pas de survol. Et la bulle dit « preuve d'exonération », un mot juridique, sans parler de douane, de MRN ni de La Poste.
2. **C'est le seul badge de tout le logiciel.** On pense naturellement à « factures impayées », d'autant que le jaune est aussi la couleur du statut « Partiellement payée » dans la liste (`StatutFacture::getColor()`), alors que les factures à encaisser n'ont, elles, aucun badge.
3. **Le clic sur « Factures » ouvre la liste sans filtre.** Le badge n'est pas cliquable à part : Filament 3.3 mène toujours à la liste complète. Le filtre « Preuves à fournir » existe (`FactureResource::table()`, ligne 684), mais il est rangé dans le menu des filtres (l'entonnoir), désactivé par défaut.
4. **Rien dans la liste ne montre la facture concernée.** Les colonnes sont Numéro, Client, Statut, Total TTC, Payé, Créée par, Échéance. Une vente à l'export payée comptant s'affiche « Payée », en vert : rien ne dit qu'il lui manque quelque chose.
5. **Sur la fiche, l'information est au milieu de la page.** La section « Preuves de l'exonération » (`FactureResource::preuvesSection()`, ligne 422) vient après « Facture » et « Livraison » ; la ligne « À fournir : Déclaration d'export (MRN) avec certification de sortie, ou déclaration CN23 (colis postal) » est en orange ; le bouton « Ajouter une preuve » est le sixième des sept boutons d'en-tête (`ViewFacture::getHeaderActions()`, ligne 41).
6. **Aucun lien entre le nombre et la fiche.** Ni le tableau de bord, ni une notification, ni la liste ne mènent à la facture : il faut ouvrir les factures une à une.
7. **Le nombre peut concerner quelqu'un d'autre.** Un compte qui voit les factures sans pouvoir noter une preuve voit le nombre sans aucun moyen d'agir (§ 1.2).

### 1.5 Ce que montrent aujourd'hui la liste et la fiche

- **Liste des factures** (`FactureResource::table()`) : colonnes ci-dessus ; filtres « Statut », « Preuves à fournir » (bascule) et « À encaisser » (bascule, `FactureResource::FILTRE_A_ENCAISSER`, utilisé par l'alerte du tableau de bord) ; aucune colonne ni icône pour les preuves ; aucun onglet (`ListFactures` n'a que le bouton « Créer »).
- **Fiche** (`FactureResource::infolist()` et `ViewFacture`) : la section « Livraison » (adresse, date, transport), puis « Preuves de l'exonération » avec sa description (« Sans elles, la vente devra être corrigée par un avoir et une nouvelle facture à la TVA française »), l'« État » (« Complet », ou une ligne « À fournir : … » par preuve qui manque, l'attestation avec sa date limite) et les preuves déjà enregistrées (type, référence, date du document, établie par, enregistrée le … par …). Le bouton « Ajouter une preuve » ouvre une fenêtre : type (parmi ceux que la nature admet), référence (« Le n° que porte le document : MRN, n° du CN23… »), date du document, établie par. Une preuve enregistrée fait disparaître la facture du nombre jaune dès que plus rien ne manque.

---

## 2. Catalogue de tout ce qui peut rester « en attente » ou « à faire »

Recherche faite dans tout le code de la copie : statuts (`app/Domain/*/Enums/Statut*.php`, `StatutTransmissionPdp`, `StatutMandatPdp`), champs facultatifs, scopes, policies, notifications (`app/Domain/*/Notifications`), tâches planifiées (`bootstrap/app.php`, `app/Console/Commands`), widgets, badges et rappels des pages.

Légende des colonnes :
- **Qui peut agir** : rôle et autorisation (nom de la case entre parenthèses), et la portée « ses ventes » (sans « voir celles de toute l'équipe ») ou « toute l'équipe ». Le propriétaire a toutes les autorisations sauf celles de la vente (`PermissionRegistry::deVente()` : créer et émettre un ticket ou une facture, enregistrer un encaissement, vendre sans stock). Dans un magasin en lecture seule, ou tant que le magasin ne peut pas vendre, les gestes de `PermissionRegistry::reserveesAuxOperationsCommerciales()` sont refusés (émettre, noter une preuve, envoyer, créer un avoir, encaisser, contre-passer).
- **Fin** : ce qui fait disparaître l'élément.
- **Clic** : où un clic doit mener.
- **Visible aujourd'hui** : où on le voit déjà.

### A. Ventes et encaissements (magasin)

| # | Élément | Où dans le code | Qui peut agir | Fin | Clic | Visible aujourd'hui |
|---|---|---|---|---|---|---|
| A1 | Factures et tickets **à encaisser** (émis, envoyés, partiellement payés, en retard) | `Facture::scopeAEncaisser()`, `StatutFacture::quiAttendentUnReglement()`, `Facture::totauxAEncaisser()` | Encaisser : qui voit la facture et a « enregistrer un encaissement » (`paiement.creer`), voir `FacturePolicy::encaisser()` : vendeurs (ses ventes, ou toute l'équipe), responsables ; jamais le propriétaire ; un administrateur seulement si la vente lui est cochée | Payée, ou soldée par un avoir | 1 : la fiche (bouton « Encaisser ») ; plusieurs : liste filtrée « À encaisser » (`FactureResource::urlDesFacturesAEncaisser()`) | Oui : encart `FacturesAEncaisserApercu` (tableau de bord), mais seulement pour qui « suit » ces factures (`User::suitLesFacturesAEncaisserDans()`) : propriétaire, responsable, expert-comptable, administrateur qui voit tout ; un vendeur **seulement s'il peut contre-passer** (`paiement.contre_passer`, pas coché par défaut) |
| A2 | Dont **en retard** (échéance dépassée, montant dû) | `Facture::estEchue()`, `MarquerFacturesEnRetard` (chaque heure), notification `FacturesPasseesEnRetard` | Comme A1 | Payée ou soldée | Liste filtrée statut « En retard » | Oui, en rouge dans l'encart A1 ; notification au **seul propriétaire** |
| A3 | Facture rouverte par une **contre-passation** : le bon paiement reste à enregistrer | `ContrePasserPaiementAction`, `Facture::constaterUnReglement()` ; badge « Paiement contre-passé » de l'encart A1 | Le bon paiement : un compte autorisé à vendre qui voit la facture (`paiement.creer`) ; la contre-passation elle-même : propriétaire, responsables, et qui a `paiement.contre_passer` | Bon paiement enregistré | La fiche (section « Paiements reçus ») | Oui, dans l'encart A1 (badge rouge), avec la même limite pour les vendeurs |
| A4 | Facture en retard **à relancer** à la main | `Facture::estEchueEtImpayee()`, `EnvoieLesDocuments::relancer()`, `Magasin::relanceDue()` ; relances automatiques **désactivées par défaut** (`magasins.relances_automatiques`, `ParametresRelances`) | « Envoyer et relancer » (`facture.envoyer`), sur les factures qu'il voit ; le propriétaire compris | Relance envoyée, ou facture réglée ; automatique si le magasin a activé les relances et que le client a une adresse e-mail (`RelancerFacturesImpayees`) | La fiche (bouton « Relancer ») | Seulement le bouton « Relancer » sur la ligne de la liste et la fiche |
| A5 | **Preuves d'exonération à fournir** (export, DOM, intracommunautaire) | § 1 : `PreuvesRequises`, `Facture::scopePreuvesAFournir()` | « Noter les preuves d'exonération et les bordereaux de détaxe » (`facture.justifier`) sur les factures qu'il voit : propriétaire et responsables toujours, précochée pour vendeurs (ses ventes) et administrateurs, jamais l'observateur par défaut ni l'expert-comptable | Toutes les preuves notées, ou vente entièrement corrigée par avoir | 1 : la fiche, section « Preuves de l'exonération » ; plusieurs : liste filtrée « Preuves à fournir » | Le nombre jaune du menu « Factures », le filtre, la fiche (§ 1) |
| A6 | Dont **attestation de l'acquéreur en retard** (après le 10 du mois suivant la livraison) | `PreuvesRequises::attestationAttendue()` | Comme A5 | Attestation notée | La fiche | Seulement « délai dépassé » sur la fiche ; aucune distinction ailleurs |
| A7 | **Brouillons de facture ou de ticket** jamais émis | `StatutFacture::Brouillon`, `FacturePolicy::emettre()` / `update()` / `delete()` | Émettre : qui voit le brouillon (son auteur, ou toute l'équipe) et a « émettre » (`facture.emettre`, vente) ; supprimer : `facture.create` ; jamais le propriétaire | Émis ou supprimé | 1 : page de modification ; plusieurs : liste filtrée statut « Brouillon » | Seulement « Brouillon » dans la colonne Numéro de la liste |
| A8 | **E-mail en échec** (envoi ou relance d'un document qui n'est pas parti après toutes ses tentatives) | `EnvoiDocument` (statut `StatutEnvoi::Echec`), `EnvoyerDocumentJob::failed()`, notification `EnvoiDocumentEchoue` | Renvoyer : qui a « envoyer » sur ce document (`facture.envoyer`, `devis.envoyer`, `avoir.envoyer`) et le voit | Aucun état « traité » aujourd'hui : un envoi en échec le reste pour toujours ; il faudrait dire « tant qu'aucun envoi plus récent du même document n'a réussi ou n'est en cours » | La fiche du document (section des envois) | Notification dans la cloche (au demandeur, ou au propriétaire pour une relance automatique) ; section des envois de la fiche |
| A9 | **Vente en cours à la caisse** (panier commencé, pas encaissé) | `PanierCaisse` (une ligne par vendeur et par magasin), `PanierDeCaisse` ; abandonnée d'office à la clôture (`PanierDeCaisse::abandonnerLesVentesOuvertesLe()`, JET 320) | Le seul vendeur du panier | Encaissée, abandonnée, ou clôture de la journée | Page « Caisse » | Seulement en rouvrant la caisse |
| A10 | **Caisse ouverte dans un autre magasin** (compte autorisé à vendre dans plusieurs magasins) | `VerificateurCaissesPersonnelles::refuserSiSaCaisseEstOuverteAilleurs()` (lecture hors du magasin déjà permise par `CLAUDE.md`) | Le compte lui-même, dans l'autre magasin (« Fermer ma caisse ») | Caisse fermée là-bas, ou fermeture automatique | Rien dans ce magasin (le lien mènerait à un autre magasin) | Seulement le refus « Fermez d'abord votre caisse de … » au moment de vendre |

### B. Devis (magasin)

Les devis n'ont pas d'auteur enregistré (`create_devis_table` n'a pas de colonne `cree_par`) : tout compte qui a « voir les devis » (`devis.view`) les voit tous, sans confidentialité par vendeur.

| # | Élément | Où dans le code | Qui peut agir | Fin | Clic | Visible aujourd'hui |
|---|---|---|---|---|---|---|
| B1 | **Devis brouillon jamais envoyé** | `StatutDevis::Brouillon`, `Devis::estSupprimable()` (numéro vide) | Envoyer : `devis.envoyer` ; modifier, supprimer : `devis.update` ; le propriétaire compris | Envoyé ou supprimé ; un brouillon dont la validité est dépassée ne s'envoie plus | Page de modification, ou liste filtrée | Filtre « Statut » de la liste seulement |
| B2 | **Devis repassé en brouillon** après une modification (numéroté, acceptation effacée) | `ModifierDevisAction`, `Devis::estModifiable()` | Comme B1 | Renvoyé | Page de modification | Idem |
| B3 | **Devis envoyé sans réponse** (à accepter ou refuser), surtout près de la fin de sa validité | `StatutDevis::Envoye`, `Devis::validiteDepassee()`, `DevisPolicy::accepter()` / `refuser()`, `ExpirerDevis` (chaque heure) | Accepter, refuser : `devis.envoyer` | Accepté, refusé, ou expiré d'office le lendemain de sa date de validité | La fiche du devis | Filtre « Statut » seulement |
| B4 | **Devis accepté pas encore facturé** | `StatutDevis::Accepte` sans facture (`Devis::factures()`), action « Convertir en facture » (`DevisResource::table()`) | `facture.create` (une autorisation de vente : **jamais le propriétaire**) | Converti (une facture brouillon naît, voir A7) | La fiche ou la liste | Bouton « Convertir en facture » sur la ligne |

### C. Caisse et clôtures (magasin)

| # | Élément | Où dans le code | Qui peut agir | Fin | Clic | Visible aujourd'hui |
|---|---|---|---|---|---|---|
| C1 | **Ma caisse est ouverte** (ventes ou encaissements pas encore comptés dans un relevé) | `VerificateurCaissesPersonnelles::aSaCaisseOuverte()`, `FermerSaCaisseAction` | La personne (« Fermer ma caisse »), qui tient une caisse (`User::tientUneCaisseDans()`) | Fermée par elle, par qui clôture, ou d'office à la clôture du jour ou à minuit (`ClotureAutomatiqueCaisse`) | Page « Ma caisse » ou « Les caisses » (`ClotureCaissePersonnelleResource`) | Seulement le bouton « Fermer ma caisse » de cette page |
| C2 | **Caisses de l'équipe encore ouvertes** | `VerificateurCaissesPersonnelles::vendeursAvecCaisseOuverte()` | Propriétaire, responsables, et qui a « clôturer la journée » (`cloture_caisse.gerer`) : « Fermer la caisse d'un vendeur » | Comme C1 | Page « Les caisses » | Encarts `CaissesPersonnellesOuvertesApercu` (liste des clôtures) et `CaissesOuvertesDeLEquipeApercu` (« Les caisses »), pas sur le tableau de bord |
| C3 | **Journée à clôturer** (ventes du jour pas encore clôturées) | `CloturerLesJourneesAction::journeesEnAttente()`, encart `VentesEnAttenteApercu` | `cloture_caisse.gerer` (propriétaire et responsables compris) | Clôture manuelle (bouton « Clôturer la journée du … », `ListClotureCaisses`) ou automatique après minuit | Liste des clôtures | Encart `VentesEnAttenteApercu` sur la liste des clôtures seulement |
| C4 | **Journée passée non clôturée** (ne devrait jamais arriver : planificateur arrêté) | Même méthode ; rattrapage par `ClotureAutomatiqueCaisse` | Comme C3 | Clôture | Liste des clôtures | Idem ; c'est une anomalie technique plutôt qu'une tâche |

### D. Achats et stock (magasin)

| # | Élément | Où dans le code | Qui peut agir | Fin | Clic | Visible aujourd'hui |
|---|---|---|---|---|---|---|
| D1 | **Facture d'achat en brouillon** (marchandise pas encore entrée en stock) | `StatutFactureAchat::Brouillon`, `FactureAchatPolicy::valider()` ; une ligne de nouveau produit exige aussi `produit.create` | « Valider les achats » (`facture_achat.valider`) ; le propriétaire compris ; achats visibles par toute l'équipe qui a `facture_achat.view` | Validée ou supprimée | Page de modification, ou liste filtrée statut « Brouillon » | Filtre « Statut » de la liste seulement |
| D2 | **Inventaire d'ouverture en brouillon** | `StatutInventaireOuverture::Brouillon`, `InventaireOuverturePolicy::create()` / `valider()` (seulement avant la première vente : `Magasin::aVendu()`) | `facture_achat.valider` et `produit.create` | Validé ou supprimé ; **après la première vente, il ne peut plus qu'être supprimé** | Fiche de l'inventaire (`InventaireOuvertureResource`) | Liste des inventaires seulement |
| D3 | **Stock négatif** à régulariser (ventes sans stock) | `Produit::scopeEnStockNegatif()`, notification `StockNegatif` | Régulariser : un achat (D1), un inventaire, une réception ; voir : `produit.view` ; alertes : `DestinatairesDesAlertes` avec `produit.ajuster_stock` | Quantité revenue à zéro ou plus | Liste des produits filtrée « Stock négatif » | Oui : tuile `StockNegatifApercu` du tableau de bord (à qui a `produit.view`) ; notification ; couleur de la colonne Stock |
| D4 | **Stock bas** (au seuil d'alerte ou dessous) | `Produit::enAlerteStock()` (`suivi_stock` et quantité ≤ `seuil_alerte`), notification `StockSousLeSeuilDAlerte` | Réapprovisionner : `facture_achat.creer` / `valider` ; alertes : `produit.ajuster_stock` | Quantité au-dessus du seuil | Liste des produits filtrée… mais **ce filtre n'existe pas** (le filtre « Statut du stock » n'a que En stock, Épuisé, Stock négatif) | Notification ; badge rouge de la colonne Stock |
| D5 | **Produits épuisés** | `Produit::scopeEpuises()`, encart `ValeurStockApercu` (« Références épuisées ») | Comme D4 | Réapprovisionné | Liste filtrée « Épuisé » | Encart de la liste des produits, à qui a `produit.voir_valeur_stock` |
| D6 | **Avoir fournisseur sans le n° de l'avoir du fournisseur** | `avoirs_fournisseur.numero_fournisseur` vide, `FactureAchatResource::AVERTISSEMENT_SANS_NUMERO_FOURNISSEUR` | **Personne** : un avoir fournisseur émis ne se modifie plus (`AvoirFournisseur`), et l'interface ne le laisse jamais en brouillon (`EnregistrerAvoirFournisseurAction`) | Rien ne peut le terminer aujourd'hui | — | Avertissement à la création seulement ; colonne « N° de l'avoir du fournisseur » vide |

### E. Catalogue et paramètres du magasin

| # | Élément | Où dans le code | Qui peut agir | Fin | Clic | Visible aujourd'hui |
|---|---|---|---|---|---|---|
| E1 | **Service sans prix de référence** (il ne se vend pas sans l'autorisation « accorder des remises et fixer le prix d'un service ») | `services.prix_ttc` vide ; règle R2 (`DerogationsDePrix`) | `vente.remises_et_prix` (propriétaire et responsables toujours) | Prix de référence fixé | Liste des services (`ServiceResource`) | Non |
| E2 | **Produit vendu 0,00 €** (prix permis, ≥ 0) | `produits.prix_vente_ttc` = 0 | `produit.update` | Prix fixé | Fiche du produit | Non |
| E3 | **Relances automatiques désactivées** alors que des factures sont en retard | `magasins.relances_automatiques` (faux par défaut) | « Régler les relances » (`relances.regler`) | Activées, ou plus de facture en retard | `ParametresRelances` | Non |

### F. Le magasin et sa société, vus du magasin

| # | Élément | Où dans le code | Qui peut agir | Fin | Clic | Visible aujourd'hui |
|---|---|---|---|---|---|---|
| F1 | **Société en attente du code du courrier recommandé** (aucune vente réelle) | `Societe::attendSonCode()` | Le propriétaire seul, dans l'espace de la société | Code saisi, ou inscription close | Espace de la société, « Vérification de la société » | Bandeau `verification-en-cours.blade.php` en tête de chaque page |
| F2 | **Établissement pas encore confirmé** par le répertoire Sirene (aucune vente dans ce magasin) | `magasins.etablissement_verifie_le` vide, `Magasin::refusDeVente()` | Dans l'espace de la société : « Revérifier auprès de l'INSEE » (`VerificationSociete::reverifierAction()`, propriétaire seul et seulement avant la validation, `SocietePolicy::verifierLaSociete()`), ou corriger le NIC si le contrôle est négatif (fiche du magasin, `MagasinDeLaSocietePolicy::changerLeSiret()` : qui peut gérer les magasins) ; sinon la reprise automatique (`ReverifierSirene`, chaque heure) | Confirmé | Pour l'équipe du magasin : rien (elle attend) ; pour qui gère la société : `VerificationSociete` ou la fiche du magasin | Seulement le refus au moment de vendre ; dans l'espace de la société, « établissement à vérifier » |
| F3 | **Identité incomplète pour facturer un professionnel** (mentions de la société, ou adresse du magasin) | `Magasin::mentionsLegalesManquantes()`, `Societe::mentionsLegalesManquantes()` | La société (propriétaire, ou administrateur de tous les magasins avec `societe.parametres.gerer`) ; l'adresse du magasin, la société aussi | Mentions complètes | `IdentiteSociete` (espace de la société) | Dans l'espace de la société seulement, et **sans l'adresse du magasin** (la section lit `Societe::mentionsLegalesManquantes()`) ; dans le magasin, seulement le refus à l'émission |
| F4 | **Magasin en lecture seule** (fermé, suspendu, société suspendue) | `Magasin::motifDeLectureSeule()` | La société (réouverture) ou l'éditeur | Rouvert | — | Bandeau `magasin-en-lecture-seule.blade.php` |
| F5 | **Magasin de démonstration** | `Magasin::est_demonstration`, `FermerDemonstrationsInactives` | — | Fermé à la validation ou après 30 jours sans vente | — | Bandeau et rôle « (démonstration) » de la bienvenue |

### G. Compte et équipe

| # | Élément | Où dans le code | Qui peut agir | Fin | Clic | Visible aujourd'hui |
|---|---|---|---|---|---|---|
| G1 | **Mon mot de passe est encore mon identifiant** (vendeur ou observateur ; les autres rôles sont forcés de le choisir, `ExigerSonPropreMotDePasse`) | `users.mot_de_passe_est_l_identifiant`, `ModifierProfil::RAPPEL_IDENTIFIANT` | La personne | Mot de passe choisi | Profil | Rappel dans le profil, alerte à la première connexion |
| G2 | **Comptes de l'équipe au mot de passe = identifiant, ou jamais connectés** | Filtres `mot_de_passe_identifiant` de `EquipeDuMagasinResource` et `MembreResource`, badges « Mot de passe : l'identifiant » et « Jamais connecté » | Le propriétaire ; le responsable pour les vendeurs et observateurs de son magasin (jamais un administrateur : l'identifiant ne lui est pas montré) | La personne a choisi son mot de passe / s'est connectée | Liste de l'équipe filtrée | Liste de l'équipe seulement |
| G3 | **Adresse de connexion du propriétaire à confirmer** (lien envoyé, expiré, ou jamais parti) | `users.nouvelle_adresse`, `ModifierProfil` (textes lignes 152 à 154), `ConfirmerNouvelleAdresseAction` | Le propriétaire | Lien suivi | Profil | Profil seulement |
| G4 | **Notifications non lues** | Cloche (`databaseNotifications()`), `NotificationDuMagasin`, `AvisDeLEquipe`, `AvisDeVerification` | La personne | Lue | Cloche | Cloche ; **la cloche n'est pas filtrée par magasin** (un compte de plusieurs magasins y voit tous les siens, chacun nommé dans le titre) |

### H. Espace d'administration de la société

| # | Élément | Où dans le code | Qui peut agir | Fin | Clic | Visible aujourd'hui |
|---|---|---|---|---|---|---|
| H1 | **Premières étapes** (code, premier magasin, équipe, démonstration) | `TableauDeBord::etapes()` | Le propriétaire | Toutes faites | Chaque étape a son lien | Oui, liste « Vos premières étapes » |
| H2 | **Code du courrier à saisir**, avec sa date limite | `Societe::attendSonCode()`, `CourriersDeVerification::cloturePrevueLe()` | Le propriétaire (`SocietePolicy::verifierLaSociete()`) | Société validée, ou inscription close | Page publique du code (QR code), ou `VerificationSociete` | Oui (étape 1, section « Vérification », bandeau) |
| H3 | **Nouveau courrier possible** (une fois, au plus tôt 15 jours après le premier) | `CourriersDeVerification::nouvelEnvoiPossibleLe()`, `VerificationSociete::demanderUnNouveauCourrierAction()` | Le propriétaire | Demandé, ou plus possible | `VerificationSociete` | Bouton de la page seulement |
| H4 | **Pièces à déposer** pour l'éditeur (après le second courrier) | `CourriersDeVerification::piecesPossibles()`, `VerificationSociete::deposerUnePieceAction()` | Le propriétaire | Déposées / décision de l'éditeur | `VerificationSociete` | Bouton de la page seulement |
| H5 | **Établissements à confirmer** au répertoire Sirene | `VerificationSociete::etablissementsAVerifier()`, `refusDuDernierControle()`, `reverifierAction()` | « Revérifier » : le propriétaire, avant la validation ; corriger le NIC après un contrôle négatif : qui peut gérer ce magasin (`MagasinDeLaSocietePolicy::changerLeSiret()`, `societe.magasins.gerer`) ; sinon la reprise automatique, chaque heure | Confirmés | `VerificationSociete` ou la fiche du magasin | Colonne « établissement à vérifier » du tableau des magasins ; page `VerificationSociete` |
| H6 | **Identité incomplète** | `Societe::mentionsLegalesManquantes()` | Propriétaire, ou administrateur avec `societe.parametres.gerer` (tous les magasins) | Complète | `IdentiteSociete` | Oui, section en orange du tableau de bord (sans l'adresse des magasins, voir F3) |
| H7 | **Transmission de la propriété en attente** | `TransmissionPropriete` (expire en 24 h), `FicheDuMembre::transmissionEnAttente()`, page `AccepterLaTransmission` | Le propriétaire peut l'annuler ; l'administrateur destinataire doit suivre le lien de l'e-mail, connecté à son compte | Acceptée, annulée ou expirée | Propriétaire : fiche du membre ; destinataire : le lien de l'e-mail (la page ne s'ouvre que par lui) | Fiche du membre ; avis dans la cloche du destinataire (`AvisDeLEquipe`) |
| H8 | **Responsable nommé par un administrateur**, que le propriétaire peut annuler en un clic | `ResponsableNommeParUnAdministrateur`, `FicheDuMembre::annulerLaNominationAction()`, `AnnulerUneNominationAction` | Le propriétaire (ou l'administrateur auteur, pour sa propre erreur) | Annulée, ou la nomination n'est plus la dernière affectation | Fiche du membre | Notification au propriétaire ; bouton sur la fiche ; pas de délai |
| H9 | **Écart relevé au répertoire Sirene après la validation** (sans rien bloquer) | `ReverificationSirene` (avis `AvisDeVerification`) | Le propriétaire (vérifier, contacter l'éditeur) | Aucun état « traité » | `VerificationSociete` | Avis dans la cloche seulement |
| H10 | **Seuil d'échecs de connexion dépassé** | `AlerterDuSeuilDEchecsJob` | Le propriétaire (information) | — | Liste des comptes | Avis dans la cloche et par e-mail |
| H11 | **Démonstration ouverte ou terminée** | `TableauDeBord::demonstration()`, `ouvrirLaDemonstrationAction()` | Propriétaire, administrateur de tous les magasins | — | Tableau de bord du magasin de démonstration | Oui, section « Démonstration » |
| H12 | **Factures à encaisser de chaque magasin** (nombre, restant dû, retard ; sans nom de client) | `FacturesAEncaisserDeLaSociete::pour()` (seule lecture hors magasin de ce tableau de bord, déclarée dans `CLAUDE.md`) | Voir A1 | Voir A1 | Liste filtrée du magasin | Oui, colonnes du tableau « Vos magasins » |
| H13 | **Caisses encore ouvertes de chaque magasin** | `CaissesDeLaSociete`, page `LesCaisses` (lecture permise par `CLAUDE.md` **sur cette page**) | Fermer : dans le magasin (C2) | Comme C1 | Page « Les caisses » de la société | Page « Les caisses » seulement |

### I. Contrôles de conformité (registre fiscal)

| # | Élément | Où dans le code | Qui peut agir | Visible aujourd'hui |
|---|---|---|---|---|
| I1 | **Dernière vérification de l'intégrité avec des anomalies**, ou jamais faite | `ConformiteApercu::derniereVerification()`, `VerifierIntegriteFiscale` (chaque nuit), `AlerteEditeur` | L'éditeur (prévenu par e-mail) ; le magasin ne peut rien corriger | Encart de la page du registre (`caisse_securisee.consulter`) |
| I2 | **Clé de signature non configurée** (aucune opération fiscale possible) | `ConformiteApercu::cleDeSignature()` | L'éditeur (installation) | Idem |

Ces deux éléments relèvent de l'éditeur, pas de l'équipe du magasin.

### J. Ce qui viendra avec les prochaines étapes (pas dans le code de fa83824)

- Étape 2 (SUPER PDP) : connexion de la société par son propriétaire (`MandatPdp`, `StatutMandatPdp::EnAttente`, aucun écran aujourd'hui) ; factures **rejetées ou refusées** par la plateforme (`StatutTransmissionPdp::Rejetee` / `Refusee`, montrées aujourd'hui sur la fiche, section « Transmission électronique », sans liste ni alerte) ; factures émises avant la connexion **à transmettre** par un bouton ; e-reporting d'une clôture non transmis ou rejeté (`EReportingResource`, « non transmis » aujourd'hui) ; factures reçues **à valider** (brouillons d'achat importés) ; liste « **À attribuer** » de la société (le propriétaire et les administrateurs autorisés « sont alertés », dit `CLAUDE.md`).
- Étape 3 (caisse sécurisée) : attestation de l'éditeur à **signer** (volet 2) ; clôtures mensuelles et d'exercice.
- Plan TVA (`tva/conception-finale.md`, § 5.4) : listes de suivi « Dédouanement à compléter », « TVA étrangère à régulariser », « Charges à valider », chacune « avec un badge et une notification ». Si l'« À faire » est retenu, elles devraient y entrer plutôt que d'ajouter des badges aux menus.
- Étape 5 (comptabilité) : régularisation de la TVA d'un ticket détaxé après le visa du bordereau.
- Lot EC : l'expert-comptable deviendra le « cabinet lié » (`serie8/decision-espace-cabinets.md`), avec son propre tableau de bord multi-clients ; l'« À faire » du cabinet est à prévoir avec ce lot.

### K. Ce qui n'est pas « à faire », et pourquoi

- K1. **Détaxe** : PILIQI ne sait pas qu'un ticket sera détaxé avant que quelqu'un y note le n° du bordereau (`NoterBordereauDetaxeAction::refusPour()` dit seulement quels tickets **peuvent** l'être) ; il n'y a donc rien « en attente » à montrer. La TVA ne se régularise qu'après le visa, ce que fera la comptabilité (étape 5).
- K2. **Devis expirés**, **retard des factures**, **clôture de minuit**, **fermeture des caisses à minuit**, **fermeture des démonstrations inactives** : faits automatiquement chaque heure ou chaque nuit (`bootstrap/app.php`).
- K3. **Avoirs et avoirs fournisseur** : jamais laissés en brouillon par l'interface (`EnregistrerAvoirAction`, `EnregistrerAvoirFournisseurAction`).
- K4. **Crédit d'avoir d'un client et bons d'avoir non utilisés** : rien n'est jamais remboursé ; le crédit s'impute d'office sur les factures impayées ; un bon d'avoir attend le retour du client. C'est une information, pas une tâche.
- K5. **E-mails au-delà du débit** : refusés tout de suite (`DebitDesEmails`), jamais mis en attente.

---

## 3. Ce qui existe déjà sur les tableaux de bord, et les règles de `CLAUDE.md`

### 3.1 Tableau de bord du magasin

Page `Filament\Pages\Dashboard` du panneau `app` ; widgets découverts dans `app/Filament/App/Widgets` (`AppPanelProvider::panel()`, `discoverWidgets`) :

| Ordre | Widget | Ce qu'il montre | Qui le voit |
|---|---|---|---|
| −3 | `BienvenueApercu` (vue `bienvenue-apercu.blade.php` → `bienvenue.blade.php`) | « Bonjour », le nom, le rôle dans ce magasin, déconnexion | Tous |
| −2 | `FacturesAEncaisserApercu` (vue `factures-a-encaisser-apercu.blade.php`) | Nombre et restant dû des factures à encaisser, dont en retard (rouge), les 10 plus anciennes par échéance (numéro cliquable, client, restant dû, échéance, badge « Paiement contre-passé »), lien « Voir toutes les factures à encaisser » | `User::suitLesFacturesAEncaisserDans()` (voir A1) ; reste visible en lecture seule |
| défaut | `FacturesApercu` | Chiffre d'affaires de la semaine, du mois, de l'année (HT, TTC, nombres) ; « Mes ventes » sans « voir celles de toute l'équipe » | `facture.view` |
| défaut | `StockNegatifApercu` | Une tuile « Stock négatif » qui mène à la liste filtrée | `produit.view`, et seulement s'il y en a |

Widgets placés sur des listes, volontairement **pas** sur le tableau de bord (leur commentaire le dit) : `VentesEnAttenteApercu` et `CaissesPersonnellesOuvertesApercu` (liste des clôtures), `CaissesOuvertesDeLEquipeApercu` (« Les caisses »), `ValeurStockApercu` (liste des produits), `ConformiteApercu` (registre).

En tête de chaque page : bandeaux « magasin en lecture seule » et « société en attente du code » / « magasin de démonstration » (`AppPanelProvider`, `CONTENT_START`).

### 3.2 Tableau de bord de l'espace de la société

`app/Filament/Societe/Pages/TableauDeBord.php` et sa vue `tableau-de-bord.blade.php`, dans l'ordre :
1. bienvenue avec le rôle de société ;
2. « Vos premières étapes » (propriétaire seul, tant qu'il en reste) ;
3. « Vérification de la société » (état et SIREN) ;
4. « Identité incomplète pour facturer un professionnel » (s'il manque une mention de la société) ;
5. « Démonstration » (pour qui administre tous les magasins) ;
6. « Vos magasins » : nom, code, SIRET, établissement vérifié ou à vérifier, état (ouvert ou motif de lecture seule), et, là où la personne voit toutes les factures, « À encaisser », « Restant dû », « dont en retard » (lien vers la liste filtrée du magasin) ; pour un administrateur autorisé à nommer les responsables, le lien « Comptes ».

### 3.3 Les notifications (cloche)

`NotificationDuMagasin` (`EnvoiDocumentEchoue`, `FacturesPasseesEnRetard`, `StockNegatif`, `StockSousLeSeuilDAlerte`, `StockDuMagasinCede`), `AvisDeLEquipe`, `AvisDeVerification`, `ResponsableNommeParUnAdministrateur`. Elles signalent un **événement** à un moment donné ; elles ne disparaissent pas quand la chose est faite, et la cloche du panneau d'un magasin n'est pas filtrée par magasin. Elles ne peuvent donc pas servir de source à un « À faire », qui doit dire ce qui reste **maintenant**.

### 3.4 Règles de `CLAUDE.md` et des décisions récentes qui touchent le tableau de bord

- « Vendeurs » : « Le tableau de bord dit le rôle de la personne après « Bonjour » et signale en alerte les factures à encaisser de sa portée (nombre, restant dû, dont en retard, et celles dont un paiement a été contre-passé) : toutes celles du magasin pour le propriétaire, un responsable, un expert-comptable et un administrateur qui voit celles de toute l'équipe ; pour un vendeur ou un observateur autorisé à contre-passer, les siennes, ou toutes avec « voir celles de toute l'équipe » ; dans l'espace d'administration, le propriétaire et chaque administrateur voient ces totaux magasin par magasin, sans nom de client. » ; « son tableau de bord montre « Mes ventes » » ; « Un vendeur ne voit et n'encaisse que ses factures, sauf autorisation « voir celles de toute l'équipe » ».
- « Produit », lectures hors du magasin permises : seulement « le nombre, le restant dû et le retard des factures à encaisser de chaque magasin, sans aucun nom de client ni numéro de document, au tableau de bord de l'espace d'administration » ; « à la page « Les caisses » de l'espace d'administration […] les caisses encore ouvertes » ; « la liste et le tableau de bord des magasins et des membres de l'espace d'administration, limités à la portée de la personne ». **Tout autre compteur par magasin au tableau de bord de la société serait une nouvelle lecture, à faire approuver et à écrire dans `CLAUDE.md`.**
- « Ventes hors de France » : « Les références des preuves d'export et de livraison sont enregistrées sur la facture, et celles qui manquent sont rappelées. »
- « Achats et stock » : « la quantité négative reste visible jusqu'à sa régularisation » ; les alertes de stock bas ou négatif vont au propriétaire, aux responsables et à qui gère le stock (puce « E-mails »).
- « Comptes et équipe » : après le lien d'inscription, « une liste d'étapes » ; le mot de passe encore égal à l'identifiant : « son profil le lui rappelle et la liste de l'équipe l'indique au propriétaire et, pour un vendeur ou un observateur, au responsable de son magasin » ; le propriétaire « supervise et contrôle […] sauf celui de vendre ».
- « Facture électronique » (étape 2) : la liste « À attribuer », « dont le propriétaire et les administrateurs autorisés sont alertés ».
- `decisions/reponses-regles-ecran-26-10.md` (26/10) : aucun écran ni message d'un magasin ne laisse deviner qu'il existe d'autres magasins de la société ou d'autres sociétés ; « ce magasin » est permis dans les messages.
- `serie8/decision-espace-cabinets.md` : l'expert-comptable deviendra le cabinet lié (lot EC) ; son tableau de bord multi-clients sera une lecture permise à ajouter à `CLAUDE.md`.

### 3.5 Écarts relevés en lisant le code

1. Un vendeur qui peut encaisser (`paiement.creer`, coché par défaut) mais pas contre-passer ne voit **aucune** facture à encaisser au tableau de bord, même les siennes (règle actuelle de `CLAUDE.md`, issue du point 16 de la série 8). C'est pourtant lui qui « finit le paiement ».
2. Le nombre jaune est montré à qui ne peut pas agir (§ 1.2), et le propriétaire voit l'alerte des factures à encaisser sans pouvoir encaisser (c'est voulu : il supervise).
3. Les brouillons (factures, devis, achats, inventaire) ne sont signalés nulle part.
4. Le filtre « Stock bas » n'existe pas dans la liste des produits.
5. La section « Identité incomplète » de la société oublie l'adresse des magasins, qui bloque pourtant aussi les factures aux professionnels (`Magasin::mentionsLegalesManquantes()`).
6. La notification « factures passées en retard » ne va qu'au propriétaire.
7. Un e-mail en échec n'a pas d'état « traité ».
8. Un avoir fournisseur émis sans le n° du fournisseur ne peut plus être complété.
9. Un inventaire d'ouverture en brouillon reste après la première vente sans pouvoir être validé.

---

## 4. Proposition de conception

### 4.1 Principes

1. **Un seul calcul, en direct.** L'« À faire » se calcule à chaque affichage depuis les données (statuts, scopes déjà écrits : `aEncaisser()`, `preuvesAFournir()`, `visiblesPar()`, `enStockNegatif()`, `journeesEnAttente()`, `vendeursAvecCaisseOuverte()`…), jamais depuis les notifications. Une chose faite disparaît au rechargement.
2. **Chaque ligne se comprend seule** : une phrase simple, avec le nombre, ce qui manque et pourquoi, sans sigle non expliqué. Exemple : « 1 vente hors de France attend sa preuve d'export : le n° MRN de la déclaration en douane, ou le n° du CN23 de La Poste. »
3. **Chaque ligne mène à l'endroit exact** : s'il n'y en a qu'une, sa fiche (ou sa page de modification), à la bonne section ; s'il y en a plusieurs, la liste filtrée, qui montre **exactement** ce nombre de lignes (le même scope sert au compte et au filtre, comme `PreuvesRequises::manquantes()` et `incompletes()` aujourd'hui).
4. **Chacun ne voit que sa portée** : les factures qu'il voit (« ses ventes » ou toute l'équipe), et, selon la réponse à Q-AF2, seulement ce qu'il peut faire, ou aussi ce que son équipe doit faire.
5. **Isolation** : dans un magasin, rien d'un autre magasin ni d'une autre société (toutes les lectures sous `MagasinScope`, sauf la caisse ouverte ailleurs, déjà permise) ; dans l'espace de la société, seulement les magasins réels de la portée de la personne, et seulement des nombres, sans nom de client ni numéro de document.
6. **Aucune donnée nouvelle** n'est nécessaire, sauf si l'éditeur choisit de compléter plus tard le n° d'un avoir fournisseur (Q-AF9) ou de marquer un envoi en échec comme traité.
7. **Ce qui est dit ailleurs se retire** : la tuile `StockNegatifApercu` et, selon Q-AF7, le nombre jaune du menu, si l'« À faire » les reprend (règle « on réécrit proprement » de `CLAUDE.md`).

### 4.2 Le tableau de bord du magasin

- **Place** : juste après la bienvenue, sur toute la largeur, avant le chiffre d'affaires. L'encart actuel des factures à encaisser devient le premier groupe de l'« À faire » (ou reste juste dessous, selon Q-AF1).
- **Groupes**, dans cet ordre : Encaisser (A1 à A4) ; Justifier les ventes hors de France (A5, A6) ; Terminer (A7, B1 à B4, A9, D1, D2) ; Caisse (C1 à C3) ; Stock (D3, D4) ; Magasin (F2, F3) ; Mon compte (G1, et G2 pour le propriétaire et le responsable).
- **Urgence**, par une couleur et un mot (proposition, voir Q-AF3) :
  - rouge, « En retard » ou « Bloquant » : factures en retard, attestation de l'acquéreur après sa date limite, stock négatif, établissement non confirmé (aucune vente possible), identité incomplète (aucune facture à un professionnel), e-mail en échec ;
  - orange, « À faire » : à encaisser non échues, preuves à fournir, brouillons, devis acceptés à facturer, devis qui expirent bientôt, achats et inventaire en brouillon, stock bas, mot de passe encore l'identifiant ;
  - gris, « Pour information » : ma caisse ouverte, caisses de l'équipe ouvertes, journée du jour pas encore clôturée (elle le sera à minuit), vente en cours à la caisse.
  Dans chaque couleur, le plus ancien d'abord.
- **Format d'une ligne** : nombre et phrase ; à droite un bouton au verbe du geste (« Encaisser », « Ajouter la preuve », « Émettre », « Valider », « Voir ») ; si un seul document est concerné, son numéro et son client. Exemples :
  - « 3 factures à encaisser · 1 240,00 € restant dû, dont 1 en retard (450,00 €) » → liste filtrée « À encaisser » ;
  - « 1 vente hors de France attend sa preuve d'export (n° MRN de la douane ou n° du CN23 de La Poste) · FAC-LYON1-2026-000012, Müller GmbH » → fiche, section « Preuves de l'exonération » ;
  - « 2 brouillons de facture pas encore émis, le plus ancien du 21/10 » → liste filtrée « Brouillon » ;
  - « 1 facture d'achat en brouillon : son stock n'est pas encore entré · Fournisseur Dupont, 24/10 » → page de modification ;
  - « 2 produits en stock négatif, à régulariser par un achat ou un inventaire » → liste filtrée « Stock négatif » ;
  - « Votre vente en cours à la caisse : 3 articles, 45,90 € » → page « Caisse ».
- **Rien à faire** : « Rien à faire pour le moment. » (l'encart reste, pour qu'on sache qu'il n'y a rien).
- **Magasin en lecture seule** : les lignes qui ne peuvent plus se faire (encaisser, noter une preuve, émettre) restent visibles, sans bouton d'action, avec « Magasin en lecture seule » ; la clôture et la fermeture des caisses restent possibles.
- **Magasin de démonstration** : mêmes lignes, sur ses propres documents.

### 4.3 Qui voit quoi (proposition, à confirmer par Q-AF2 et Q-AF3)

| Élément | Propriétaire | Responsable | Administrateur | Vendeur | Observateur | Expert-comptable |
|---|---|---|---|---|---|---|
| A1 à encaisser, A2, A3 | À surveiller (toute l'équipe) | À faire (toute l'équipe) | À faire si la vente lui est cochée, sinon à surveiller s'il voit toute l'équipe | À faire, ses ventes (ou toute l'équipe), selon Q-AF4 | Selon ses cases | À surveiller (lecture), selon Q-AF2 |
| A4 à relancer | À faire | À faire | Si `facture.envoyer` | Ses ventes | Non par défaut | Non |
| A5, A6 preuves | À faire | À faire | Si `facture.justifier` | Ses ventes | Seulement si on lui a coché la case | Non (ou à surveiller) |
| A7 brouillons, B4 devis acceptés | À surveiller | À faire | Si la vente lui est cochée | Ses brouillons ; devis : tous | Non | Non |
| B1 à B3 devis | À faire | À faire | Si `devis.envoyer` | À faire (tous les devis : pas d'auteur enregistré) | Non | Non |
| C1 ma caisse | Non (pas de caisse) | Si elle vend | Si la vente lui est cochée | Oui | Si on lui a coché la vente | Non |
| C2, C3 caisses de l'équipe, journée | À faire | À faire | Si `cloture_caisse.gerer` | Si on lui a coché la case | Idem | Non |
| D1, D2 achats et inventaire | À faire | À faire | Si `facture_achat.valider` | Si coché | Non | Non |
| D3, D4 stock | À faire | À faire | Si `produit.ajuster_stock` | Si coché | Si coché | Non |
| F2, F3 magasin | À faire (lien vers la société) | Information | À faire si autorisé dans la société, sinon information | Information | Information | Non |
| G1 mon mot de passe | — | — | — | Oui | Oui | — |
| G2 comptes de l'équipe | À faire | Vendeurs et observateurs de son magasin | Jamais (il ne voit pas l'identifiant) | Non | Non | Non |

« À surveiller » : ce que la personne voit sans pouvoir le faire elle-même (sans bouton d'action, avec un bouton « Voir »).

### 4.4 Le tableau de bord de l'espace de la société

- Une section « À faire » juste après la bienvenue, qui réunit ce qui est propre à la société (H1 à H10) : code du courrier avec sa date limite, nouveau courrier possible, pièces à déposer, établissements à confirmer, identité incomplète (avec l'adresse des magasins), transmission de la propriété en attente (pour le propriétaire : « Annuler » ; pour l'administrateur destinataire : « Suivez le lien envoyé à votre adresse avant le … »), nominations de responsables faites par un administrateur (à annuler s'il y a lieu), comptes au mot de passe encore égal à l'identifiant ou jamais connectés.
- La liste « Vos premières étapes » reste telle quelle tant qu'il en reste une.
- Dans le tableau « Vos magasins » : aujourd'hui, les factures à encaisser (H12). Selon Q-AF6, une colonne « À faire » par magasin, avec le nombre d'éléments de chaque groupe (sans nom de client ni numéro), et un lien vers le tableau de bord du magasin. Cette lecture hors du magasin devrait alors être ajoutée à la liste de `CLAUDE.md` et testée comme `FacturesAEncaisserDeLaSociete` (portée, démonstration exclue, aucun nom).

### 4.5 Le nombre jaune et la liste des factures

Quelle que soit la réponse à Q-AF7, la liste des factures gagnerait des **onglets** avec leur nombre (Filament 3.3 le permet par `ListRecords::getTabs()` et `Tab::make()->badge()`, inutilisé aujourd'hui dans le projet) : « Toutes », « À encaisser (n) », « Preuves à fournir (n) », « Brouillons (n) ». Le nombre se lit alors à côté de ce qu'il compte, et chaque ligne de l'« À faire » mène au bon onglet. Sur la fiche, quand une preuve manque, la section « Preuves de l'exonération » monterait en tête, avec le bouton « Ajouter une preuve » dedans.

### 4.6 Construction technique (pour le futur lot, sans choix métier)

- Un service du domaine qui rend, pour un compte et un magasin, la liste des choses à faire (clé, groupe, urgence, nombre, phrase, adresse du clic, « peut agir » ou « à surveiller »), chaque élément écrit à part et réutilisant les scopes et les policies existants ; un second service pour la société, construit comme `FacturesAEncaisserDeLaSociete` (portée, `withoutGlobalScope(MagasinScope::class)` filtré par les magasins de la portée, aucun champ inutile).
- Un widget `AFaireApercu` (avec sa vue Blade), en `$sort = -2`, non paresseux comme `FacturesAEncaisserApercu`.
- Chaque lien vers une liste passe par une méthode de la ressource qui construit l'adresse filtrée (comme `FactureResource::urlDesFacturesAEncaisser()`), pour que le compte et le filtre ne divergent jamais.
- Requêtes en nombre borné (un `count()` ou un `exists()` par élément) ; les montants restants dus en centimes, jamais par une somme SQL sur une colonne décimale (comme aujourd'hui).
- Les dates limites (devis, attestation) se comptent au jour du calendrier du magasin (`Magasin::calendrier()`), comme le reste du code depuis le lot T3.

### 4.7 Recommandation

Faire l'« À faire » en deux temps : (1) dans le magasin, les éléments A1 à A7, B1 à B4, C1 à C3, D1 à D4, F2, F3, G1, G2, avec les onglets de la liste des factures et le filtre « Stock bas » ; (2) dans l'espace de la société, la section « À faire » propre à la société et, si l'éditeur l'accepte, la colonne « À faire » par magasin. Retirer le nombre jaune du menu (les onglets et l'« À faire » le remplacent), retirer la tuile `StockNegatifApercu` (reprise), garder les notifications pour les événements. Laisser E1 à E3, A8, D6 et H9 pour une seconde passe, selon les réponses.

---

## 5. Questions métier à poser à l'éditeur

À poser en peu de fois, dans l'ordre, avec le vouvoiement (`historique/DIRECTIVES.md`, § 3.3). Aucune n'est tranchée ici.

**Q-AF1. Quels éléments dans l'« À faire » du magasin ?**
Contexte : le § 2 en liste une cinquantaine (A1 à I2) ; certains demandent un geste (encaisser, noter une preuve, émettre un brouillon), d'autres se font tout seuls à minuit (clôture, fermeture des caisses).
- a) Tout ce qui demande un geste de quelqu'un du magasin : encaissements, relances, preuves, brouillons, devis, achats et inventaire en brouillon, stock négatif et bas, établissement à confirmer, identité incomplète, mot de passe à choisir ; plus, en gris, ce qui se fera seul (caisse ouverte, journée à clôturer). **(Recommandé)**
- b) Seulement ce qui a une conséquence d'argent ou de loi : à encaisser et en retard, preuves, stock négatif, établissement à confirmer, identité incomplète.
- c) Tout, y compris les informations (services sans prix, produits à 0,00 €, relances automatiques désactivées).

**Q-AF2. « À faire » ou aussi « À surveiller » ?**
Contexte : le propriétaire ne vend jamais ; il ne peut donc pas encaisser ni émettre un brouillon, mais il supervise. Un observateur ou un expert-comptable voit des factures sans pouvoir agir.
- a) Deux parties : « À faire » (ce que la personne peut faire elle-même, avec le bouton) et « À surveiller » (ce que son équipe doit faire, pour le propriétaire, les responsables et les administrateurs qui voient toute l'équipe ; sans bouton d'action). L'expert-comptable n'a que « À surveiller ». **(Recommandé)**
- b) Une seule liste : chacun voit tout ce qu'il peut consulter, avec ou sans bouton.
- c) Une seule liste : seulement ce que la personne peut faire elle-même (le propriétaire ne verrait plus les factures à encaisser).

**Q-AF3. Ordre et urgence ?**
- a) Par urgence : rouge (en retard ou bloquant), orange (à faire), gris (information) ; dans chaque couleur, le plus ancien d'abord. **(Recommandé)**
- b) Par sujet : Ventes, Caisse, Achats, Stock, Compte, sans couleur d'urgence.
- c) Par date seulement, le plus ancien d'abord.

**Q-AF4. Les factures à encaisser pour un vendeur ?**
Contexte : aujourd'hui, un vendeur ne voit l'alerte que s'il est autorisé à contre-passer un paiement (règle de la série 8). Par défaut, il ne l'est pas : un vendeur qui a laissé une facture partiellement payée ne la voit pas au tableau de bord.
- a) Tout compte qui peut encaisser voit ses factures à encaisser (ou toutes avec « voir celles de toute l'équipe »). **(Recommandé)**
- b) Garder la règle actuelle (seulement s'il peut contre-passer).
- c) Les vendeurs ne voient jamais les factures à encaisser.

**Q-AF5. Quand un brouillon ou un devis devient-il « à faire » ?**
Exemple : un vendeur prépare une facture à 10 h ; un devis envoyé le 1er/10 vaut jusqu'au 31/10.
- a) Brouillon de facture, de devis ou d'achat : dès sa création, avec sa date ; devis envoyé : 7 jours avant la fin de sa validité ; devis accepté : tout de suite. **(Recommandé)**
- b) Brouillon : seulement à partir du lendemain de sa création ; devis envoyé : 3 jours avant la fin.
- c) Ni les brouillons ni les devis.

**Q-AF6. L'espace de la société montre-t-il ce qui reste à faire dans chaque magasin ?**
Contexte : aujourd'hui, la seule information d'un magasin lue depuis l'espace de la société est le nombre, le restant dû et le retard des factures à encaisser (règle de `CLAUDE.md`). Montrer d'autres nombres est une nouvelle lecture, qu'il faut écrire dans `CLAUDE.md`.
- a) Oui, seulement des nombres par magasin et par groupe (par exemple « 2 preuves à fournir · 1 achat à valider · 3 produits en stock négatif »), sans nom de client ni numéro, pour le propriétaire et, dans sa portée, un administrateur qui voit ces documents ; un clic ouvre le tableau de bord du magasin. **(Recommandé)**
- b) Non : la société ne montre que ses propres tâches (vérification, identité, établissements, transmission, comptes) et les factures à encaisser comme aujourd'hui.
- c) Un seul nombre total par magasin, sans détail.

**Q-AF7. Que devient le nombre jaune du menu « Factures » ?**
- a) Il disparaît : l'« À faire » du tableau de bord et des onglets avec leur nombre dans la liste des factures (« À encaisser 3 », « Preuves à fournir 1 », « Brouillons 2 ») le remplacent. **(Recommandé)**
- b) Il reste, mais compte tout ce qui demande un geste de la personne sur les factures (à encaisser, preuves, brouillons), et la liste s'ouvre avec les onglets.
- c) Il reste tel quel (preuves seulement), avec une bulle plus claire et la liste ouverte sur l'onglet « Preuves à fournir » quand il y en a.
- d) Un nombre sur chaque menu concerné (Factures, Devis, Factures d'achat, Produits), en plus de l'« À faire ».

**Q-AF8. Un élément peut-il être masqué avant d'être fait ?**
Exemple : une preuve d'export attendue de la douane dans 15 jours.
- a) Non : il reste jusqu'à ce que la chose soit faite ; seule sa couleur change avec l'urgence. **(Recommandé)**
- b) Oui, chacun peut le masquer pour lui seul jusqu'au lendemain.
- c) Oui, chacun peut le masquer pour lui seul jusqu'à une date qu'il choisit.

**Q-AF9. Les cas qu'on ne peut pas terminer aujourd'hui ?**
Contexte : un avoir fournisseur émis sans le n° de l'avoir du fournisseur ne peut plus être complété ; un e-mail en échec n'a pas d'état « traité ».
- a) Les laisser hors de l'« À faire » pour l'instant (l'avertissement à la création et la notification de l'échec suffisent) ; l'e-mail en échec y entre seulement tant que le même document n'a pas été renvoyé avec succès. **(Recommandé)**
- b) Permettre de compléter plus tard le n° du fournisseur (une information ajoutée, hors du document, inscrite au journal d'audit) et le rappeler dans l'« À faire ».
- c) Ni l'un ni l'autre dans l'« À faire ».

**Q-AF10. Le délai des preuves d'export (MRN ou CN23) ?**
Contexte : PILIQI ne fixe aujourd'hui aucune date limite pour noter le MRN ou le CN23 ; seule l'attestation de l'acquéreur d'une livraison intracommunautaire en a une (le 10 du mois suivant). Sans preuve, la vente devra être corrigée par un avoir et refaite à la TVA française. Le délai ci-dessous est un rappel interne au magasin, pas un délai légal.
- a) Orange tant qu'elle manque ; rouge 30 jours après la date d'émission de la facture. **(Recommandé, à faire confirmer par l'expert-comptable de la société)**
- b) Toujours orange, sans délai.
- c) Rouge dès le lendemain de l'émission.

---

## 6. Pour le futur lot

### 6.1 Fichiers à créer

- Service du domaine des choses à faire d'un magasin (par exemple `app/Domain/Tenancy/Services/ChosesAFaireDuMagasin.php`) et son objet de valeur (`ChoseAFaire`) ; service de la société (`ChosesAFaireDeLaSociete.php`, et la lecture par magasin si Q-AF6 = a), créés par `php artisan make:class`, selon les conventions des services voisins.
- `app/Filament/App/Widgets/AFaireApercu.php` et `resources/views/filament/app/widgets/a-faire-apercu.blade.php`.
- Tests : `tests/Feature/Filament/AFaireApercuTest.php`, `tests/Feature/Societe/AFaireDeLaSocieteTest.php`.

### 6.2 Fichiers à modifier

- `app/Filament/App/Widgets/FacturesAEncaisserApercu.php` et sa vue (fusion dans l'« À faire » ou maintien dessous, selon Q-AF1) ; `app/Models/User.php` (`suitLesFacturesAEncaisserDans()`, selon Q-AF4).
- `app/Filament/App/Widgets/StockNegatifApercu.php` (retiré s'il est repris).
- `app/Filament/App/Resources/FactureResource.php` (badge selon Q-AF7 ; adresses filtrées `urlDesPreuvesAFournir()`, `urlDesBrouillons()` ; section des preuves) et `FactureResource/Pages/ListFactures.php` (onglets), `FactureResource/Pages/ViewFacture.php`.
- `app/Filament/App/Resources/DevisResource.php` (adresses filtrées « à envoyer », « sans réponse », « à facturer »).
- `app/Filament/App/Resources/FactureAchatResource.php`, `InventaireOuvertureResource.php` (adresses filtrées).
- `app/Filament/App/Resources/ProduitResource.php` (option « Stock bas » du filtre `statut_stock`, avec un scope `Produit::scopeEnAlerteStock()` qui dit la même chose que `enAlerteStock()`).
- `app/Domain/Facturation/Models/Facture.php`, `Devis.php`, `EnvoiDocument.php` (scopes des brouillons visibles, des devis à relancer, du dernier envoi en échec).
- `app/Domain/Facturation/Services/PreuvesRequises.php` (urgence de l'attestation et, selon Q-AF10, du MRN ou CN23, en PHP et en SQL).
- `app/Filament/Societe/Pages/TableauDeBord.php` et `resources/views/filament/societe/pages/tableau-de-bord.blade.php` (section « À faire », identité avec l'adresse des magasins, colonne par magasin selon Q-AF6).
- `CLAUDE.md` : la phrase du tableau de bord (puce « Vendeurs ») réécrite selon les réponses ; la lecture par magasin ajoutée à la section « Produit » si Q-AF6 = a ; la puce « Ventes hors de France » si Q-AF10 fixe un délai. Texte à soumettre à l'éditeur avant le commit.
- Tests existants à compléter, sans en affaiblir aucun : `tests/Feature/Filament/FacturesAEncaisserTest.php`, `tests/Feature/Facturation/PreuvesOperationTest.php` (tests du badge selon Q-AF7), `tests/Feature/Filament/FactureResourceTest.php`, `ProduitResourceTest.php`, `DevisResourceTest.php`, `FactureAchatResourceTest.php`, `InventaireOuvertureResourceTest.php`, `tests/Feature/Societe/PanneauSocieteTest.php`, `FacturesAEncaisserDeLaSocieteTest.php`, `tests/Feature/Tenancy/TenantIsolationTest.php`, `IsolationDesSocietesTest.php`, `tests/Feature/Calendrier/IsolationDesCalendriersTest.php`.

### 6.3 Tests à écrire (noms)

Magasin, contenu et clics :
- `test_une_vente_a_l_export_sans_preuve_apparait_avec_le_mrn_ou_le_cn23_et_mene_a_sa_fiche`
- `test_la_preuve_enregistree_fait_disparaitre_la_ligne`
- `test_une_vente_entierement_corrigee_par_avoir_ne_demande_plus_de_preuve`
- `test_l_attestation_de_l_acquereur_apres_sa_date_limite_est_en_retard`
- `test_le_nombre_de_chaque_ligne_egale_les_lignes_de_la_liste_ou_elle_mene`
- `test_une_seule_chose_mene_a_sa_fiche_plusieurs_a_la_liste_filtree`
- `test_un_brouillon_de_facture_apparait_a_son_auteur_et_pas_a_un_autre_vendeur`
- `test_un_devis_accepte_non_facture_apparait_a_qui_peut_creer_une_facture`
- `test_un_devis_envoye_apparait_avant_la_fin_de_sa_validite_au_jour_du_magasin`
- `test_une_facture_d_achat_brouillon_apparait_a_qui_peut_valider`
- `test_un_inventaire_d_ouverture_brouillon_apres_la_premiere_vente_n_est_plus_a_valider`
- `test_le_stock_negatif_et_le_stock_bas_apparaissent_a_qui_gere_le_stock`
- `test_le_filtre_stock_bas_liste_les_produits_au_seuil_ou_dessous`
- `test_la_vente_en_cours_a_la_caisse_n_apparait_qu_a_son_vendeur`
- `test_ma_caisse_ouverte_et_les_caisses_de_l_equipe_selon_les_autorisations`
- `test_un_envoi_en_echec_disparait_apres_un_envoi_reussi_du_meme_document`
- `test_l_etablissement_non_confirme_apparait_et_disparait_a_la_confirmation`
- `test_l_identite_incomplete_compte_l_adresse_du_magasin`
- `test_le_mot_de_passe_encore_identifiant_n_apparait_qu_a_la_personne`
- `test_rien_a_faire_affiche_le_message_vide`

Magasin, portée et rôles :
- `test_un_vendeur_ne_voit_que_ses_ventes_sans_voir_celles_de_toute_l_equipe`
- `test_le_proprietaire_ne_voit_aucun_bouton_de_vente`
- `test_un_observateur_sans_la_case_des_preuves_n_a_pas_le_bouton`
- `test_un_administrateur_sans_la_vente_voit_les_encaissements_a_surveiller`
- `test_l_expert_comptable_n_a_rien_a_faire`
- `test_un_magasin_en_lecture_seule_montre_les_lignes_sans_bouton_d_action`
- `test_le_magasin_de_demonstration_ne_montre_que_ses_documents`

Isolation :
- `test_rien_d_un_autre_magasin_de_la_meme_societe_n_apparait`
- `test_rien_d_une_autre_societe_n_apparait`
- `test_un_compte_de_deux_magasins_ne_voit_dans_chacun_que_ce_magasin`
- `test_aucune_ligne_du_magasin_ne_nomme_un_autre_magasin`
- `test_les_notifications_d_un_autre_magasin_ne_comptent_pas`

Société :
- `test_le_proprietaire_voit_le_code_a_saisir_avec_sa_date_limite`
- `test_la_transmission_en_attente_apparait_au_proprietaire_et_a_son_destinataire`
- `test_la_nomination_par_un_administrateur_apparait_au_proprietaire`
- `test_un_administrateur_d_une_selection_ne_voit_que_ses_magasins`
- `test_la_lecture_par_magasin_ne_rend_ni_nom_de_client_ni_numero`
- `test_le_magasin_de_demonstration_n_a_pas_de_ligne`
- `test_une_autre_societe_n_apparait_jamais`

Menu et liste des factures (selon Q-AF7) :
- `test_les_onglets_de_la_liste_des_factures_comptent_ce_qu_ils_montrent`
- `test_le_menu_factures_n_a_plus_de_nombre` (si a), ou `test_le_nombre_du_menu_ne_compte_que_ce_que_la_personne_peut_faire` (si b)

Temps : chaque attente liée au calendrier fige le temps, et la suite passe sous faketime au changement d'année et aux changements d'heure, avec un magasin d'outre-mer (date limite au jour du magasin).

### 6.4 Points d'isolation à vérifier à la relecture

- Toutes les lectures du magasin passent par `MagasinScope` et, pour les factures, par `Facture::scopeVisiblesPar()` ; le panier de caisse par le compte et le magasin.
- La seule lecture d'un autre magasin dans le panneau d'un magasin reste celle de la caisse ouverte ailleurs (`VerificateurCaissesPersonnelles`), déjà permise ; elle ne s'affiche qu'au compte concerné.
- Dans l'espace de la société : `User::magasinsDeSaPortee()`, sans la démonstration, `withoutGlobalScope(MagasinScope::class)` toujours suivi d'un `whereIn` sur ces magasins, seulement des nombres.
- L'« À faire » ne lit jamais les notifications (la cloche n'est pas filtrée par magasin).
