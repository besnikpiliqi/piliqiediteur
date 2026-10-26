**Critique de complétude : ce qui manque dans la carte et les verdicts (copie figée 5cbe4e2, lecture seule, aucun fichier écrit)**

Il manque bien des choses : deux corrections proposées se contredisent, deux familles « protégées » ne le sont qu'en partie, et plusieurs sites des familles N4 et N5 ont été oubliés. Ces constats viennent de la lecture du code : je n'ai rien exécuté.

1. **Conflit entre les corrections N1/N5 et les tests de N3 (le plus urgent)**
   - **Sites :**
     - `app/Filament/Concerns/SupprimeEnMasseLigneParLigne.php:77-88` : `supprimerSansCasser` n'attrape que `LogicException` et `RefusFiscalException`.
     - `app/Filament/Concerns/GereLesExceptionsMetier.php` : `executerOuInterrompre` et `executerSansCasser` n'attrapent ni `ModelNotFoundException` ni, pour le premier fichier, `AuthorizationException`.
   - **Scénario :**
     - N5 (point 5) fait passer la suppression en masse par `SupprimerFactureAction` et `SupprimerDevisAction`, qui relisent par `firstOrFail()` et appellent `exiger()`.
     - Si une ligne cochée a été supprimée entre-temps, `ModelNotFoundException` traverse `retirerLigneParLigne`. Si un droit a été retiré, c'est `AuthorizationException`. Dans les deux cas, toute l'action casse, après avoir déjà validé les lignes précédentes.
     - Aujourd'hui, `Devis::delete()` utilise `first()` puis lève une `LogicException`, ce qui est rattrapé.
     - De même, `SupprimerFactureAchatAction` (N1) relit par `firstOrFail` : une double suppression donne une page 404 au lieu d'un refus expliqué.
   - **Pourquoi c'est grave :** les tests de régression de N3 attendent « 1 ligne cochée n'a pas été supprimée » et une notification de refus dans l'ordre « l'autre d'abord ». Mises en œuvre telles quelles, les corrections N5 et N1 font échouer ces tests, et la règle 4 de CLAUDE.md interdit d'affaiblir un test pour le faire passer.
   - **Correction proposée :** dans les nouvelles actions `Supprimer*Action`, relire par `first()` et lever une `LogicException` (par exemple `Facture::REFUS_SUPPRESSION`), ou élargir les deux `catch`.
   - **Même défaut déjà présent :** le même 404 apparaît dans tous les ordres « la suppression d'abord » jugés protégés :
     - `SupprimerInventaireOuvertureAction.php:51` ;
     - `ValiderFactureAchatAction.php:105`, appelée depuis la liste par `executerSansCasser`, ce qui mène à un 404 ;
     - `EmettreFactureAction.php:216` ;
     - `ModifierFactureAction.php:88` ;
     - `ModifierFactureAchatAction.php:95` ;
     - `EmettreDevisAction.php:67`.

2. **Le panier de la caisse, brouillon de la vente, absent de N5 (P6 n'est protégé qu'en partie)**
   - **Sites :**
     - `app/Domain/Facturation/Services/PanierDeCaisse.php:122-140` (`enregistrer`) et `:159-178` (`abandonner`) : registre puis panier verrouillé, mais ni le magasin ni le vendeur ne sont relus.
     - Appelés depuis `app/Filament/App/Pages/Caisse.php:206-215` puis `:257-259` à chaque requête, et depuis `:447-449`.
   - **Scénario :**
     - Une requête de la caisse passe le contrôle d'accès au début de la requête.
     - Pendant ce temps, `FermerMagasinAction` (registre puis magasin) clôture les journées et efface les paniers ouverts.
     - La requête obtient ensuite le registre et recrée le panier depuis le formulaire périmé. Le jeton n'ayant jamais servi, le panier est bien recréé. « Abandonner » inscrit alors un JET 320 (et des JET 323) après le JET 270 de fermeture et après les clôtures finales d'une cession.
     - Sans abandon, le panier reste orphelin dans un magasin cédé : plus aucune clôture ne l'abandonnera.
     - Même chose pour un vendeur dont la suspension vient d'être validée par `JournalDesDroits` : son nom est mis sur l'écriture.
   - **Pourquoi c'est grave :** c'est la même conséquence que celle confirmée pour N5 (un JET après la cession).
   - **Correction proposée :** relire le magasin après le registre (`verifierQuIlEstOuvert`), ce qui suffit puisque la fermeture prend le registre en premier, et relire le vendeur.

3. **Émission d'un devis et envoi de documents : site oublié de N5**
   - **Sites :**
     - `app/Domain/Facturation/Actions/EmettreDevisAction.php:64-66` : `Magasin::query()->findOrFail()` sans verrou, puis `verifierQuIlPeutVendre`, sans registre.
     - `app/Domain/Facturation/Actions/EnvoyerDocumentAction.php:226-229` (`expediteur()`, magasin lu par la relation, sans verrou), `:99-101` (émet le devis brouillon) et `:107-109` (passage de Émise à Envoyée).
   - **Scénario :** rien ne sérialise ces gestes avec `FermerMagasinAction` (registre puis magasin `FOR UPDATE`). Une fermeture ou une cession qui valide entre la lecture et l'écriture laisse :
     - un devis numéroté (numéro DEV consommé, `date_emission` postérieure à la fermeture) ;
     - ou une facture passée à Envoyée, alors que le job refusera d'envoyer l'e-mail.
   - **Correction proposée :** relire le magasin par `sharedLock()`.
   - **Même défaut, hors brouillons :** `AjouterPreuveOperationAction.php:69` et `NoterBordereauDetaxeAction.php:68` lisent `$facture->magasin` sans verrou qui les sérialise avec la fermeture.

4. **Suppression en masse des factures d'achat : la lecture seule et l'auteur ne sont toujours pas relus**
   - **Sites :** `app/Filament/App/Resources/FactureAchatResource.php:229-231`, puis `SupprimeEnMasseLigneParLigne.php:58` et `:80`.
   - **Scénario :**
     - N1 ne branche son action (magasin et droits relus) que sur la ligne 216 et sur `EditFactureAchat.php:29`. La suppression en masse ne garde que la garde du modèle, qui relit le statut mais ni le magasin ni l'auteur.
     - N5 ne cite jamais les factures d'achat, et son point 5 ne reroute que les suppressions en masse des factures et des devis.
     - Résultat : un brouillon d'achat peut encore être supprimé en masse dans un magasin qui vient de fermer, ou par un compte qui vient d'être suspendu.

5. **Refus de la société : N5 a tort de dire qu'il n'existe pas d'action, et P1/P4 ne tiennent pas pour ce cas**
   - **Sites :**
     - `app/Domain/Tenancy/Actions/RefuserLaSocieteAction.php:52-59` et `:68-75` : seule la ligne `societes` est verrouillée. Le même chemin sert à la revérification Sirene.
     - `app/Domain/Tenancy/Models/Magasin.php:393-404` : `motifDeLectureSeule()` lit `$this->societe` sans verrou.
   - **Scénario :**
     - Le verrou partagé du magasin n'est pas sérialisé avec le refus. C'est le cas de `ValiderFactureAchatAction.php:92`, `CreerFactureAchatAction.php:59`, `ModifierFactureAchatAction.php:89`, `ValiderInventaireOuvertureAction.php:104` et de la référence `SupprimerInventaireOuvertureAction.php:48` (même chose pour Créer et Modifier).
     - Or, avant la validation, une société peut configurer ses magasins réels : catalogue et achats sont autorisés.
     - Un refus validé juste après le contrôle laisse donc une réception ou un inventaire validé dans une société en lecture seule pour toujours.
   - **Pourquoi c'est grave :**
     - Le docblock de `ValiderFactureAchatAction` promet un ordre « tout entière avant ou après », qui ne tient pas ici.
     - La correction N5 (verrou partagé du magasin) ne couvrira pas non plus ce cas.
   - **Correction proposée :** que le refus verrouille aussi les lignes des magasins réels (`FOR UPDATE`, par identifiant), après la société. À valider par un test de concurrence, en tenant compte de l'ordre des verrous de `ValiderLaSocieteAction` (registres, puis société, puis mise en activité).

6. **Écrasement par un formulaire périmé : sites oubliés de N4 (la carte ne traite que le produit)**
   - **Sites :**
     - `EditClient` et `EditFournisseur` (`EditRecord::handleRecordUpdate` par défaut, `$record->update($data)`) ;
     - `app/Filament/App/Resources/ServiceResource.php:158` (`EditAction` de la table) ;
     - les pages de paramètres :
       - `ParametresFactures.php:95-97` ;
       - `CoordonneesMagasin.php:114-121` ;
       - `ParametresPrixDeVente.php:204-233` ;
       - `ParametresRelances.php:104-120`.
   - **Scénario :** l'enregistrement repart de l'état du formulaire au moment où il a été ouvert, et non de la fiche relue. Il réécrit donc sans prévenir ce qu'un collègue a changé entre-temps :
     - pour un client : `type_client`, SIREN, n° de TVA, délai de paiement (qui sert à l'échéance), mode de paiement préféré ;
     - pour un service : prix de référence et taux (règle R2), `frais_accessoires` et `lieu_specifique`, qui servent à décider de l'exonération ;
     - pour un magasin : délai de paiement, barème, relances.
   - **Correction proposée :** étendre le principe de N4 (comparer à la fiche lue, refuser une correction périmée), ou exclure ces écrans explicitement et par écrit.

7. **Autres sites oubliés de N5 (lecture seule et droits contrôlés au début de la requête)**
   - **Sites :**
     - les quatre pages de paramètres du point 6 : Filament ne revérifie `canAccess` qu'à l'hydratation (`vendor/filament/filament/src/Pages/Concerns/CanAuthorizeAccess.php`) ;
     - `app/Domain/Catalogue/Actions/TrouverOuCreerServiceAction.php:49`, `:67` et `:70` : un service archivé est réactivé, sans relire le magasin et avec un `exiger` sans verrou ;
     - `app/Filament/App/Livewire/SelecteurDeClients.php:149` et `app/Filament/App/Pages/Caisse.php:399` : `Client::create()` direct, sans action du domaine ;
     - les créations par défaut de `CreateClient`, `CreateFournisseur` et de l'action `CreateAction` de `ManageServices`.

8. **Les corrections N4 et N5 se recouvrent sur `EditProduit::handleRecordUpdate`**
   - N4 ne prend le registre que si le taux change, puis relit le produit sous verrou et le compare à la fiche lue.
   - N5 prend toujours le registre, puis relit le magasin, sans comparaison.
   - Il faut fusionner les deux, dans cet ordre : registre (seulement si le taux change), magasin en partagé, produit `FOR UPDATE`, refus si la fiche est périmée. Prendre le registre à chaque enregistrement mettrait inutilement toute modification de produit en file derrière les ventes.

9. **Référence P1 et suspension d'un compte : à trancher (point mineur)**
   - **Sites :** `SupprimerInventaireOuvertureAction.php:49` et `:57`, `ModifierInventaireOuvertureAction.php:53` et `:61`, `CreerInventaireOuvertureAction.php:57`.
   - **Problème :** ces actions utilisent `exiger()` sans verrou. Une suspension validée juste après la relecture laisse passer le geste.
   - **Incohérence entre les corrections :**
     - N1 (`SupprimerFactureAchatAction`) et N5 (`SupprimerDevisAction`, ainsi que Modifier, Accepter, Refuser et Convertir un devis) recopient ce comportement ;
     - la `SupprimerFactureAction` de N5 utilise au contraire `exigerSousVerrou`.
   - Il faut choisir une seule règle et l'appliquer partout.

10. **Saisie d'un inventaire d'ouverture après la première vente (mineur, sans effet sur le stock)**
    - **Sites :** `CreerInventaireOuvertureAction.php:54-56` et `ModifierInventaireOuvertureAction.php:50-52` lisent `aVendu()` sous le seul verrou partagé du magasin, sans registre. Cela contredit le docblock de `Magasin::aVendu` (lignes 257-264).
    - **Conséquence :** un brouillon peut être saisi juste après la première vente, contre la règle de CLAUDE.md. Sa validation, qui relit sous le registre, reste refusée.

**Ce que je n'ai pas pu prendre en défaut :** P2, P3, P5, P7, P8 et P9, ainsi que les sites déjà listés dans N1, N2, N3 et N6. Hors des points 1, 3 et 4, aucune correction proposée ne casse une règle d'isolation de CLAUDE.md : les relectures passent par `MagasinScope`, et `ExpirerLesDevisAction` filtre explicitement par magasin.