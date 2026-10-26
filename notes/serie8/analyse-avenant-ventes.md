# Avenant S8-V / A4c : analyse des droits de vente après les réponses de l'éditeur

Rédigé le 18/10/2026. Aucun fichier du dépôt n'a été modifié ; aucune base PostgreSQL n'a été touchée ; rien n'a été publié ni poussé.

## 0. Sources, méthode, conventions

- **Décisions appliquées** (`serie8/reponses-serie8.md`, relu après sa mise à jour du 18/10, point 5 compris) : précision 1 « Il ne vend jamais » (« Le compte du propriétaire ne fait ni ticket, ni facture, ni encaissement : il supervise et contrôle. Un entrepreneur qui vend lui-même dans sa boutique se crée un compte de responsable dans son magasin, avec un autre identifiant ») ; Q3-a, note « laisse comme il est car c'est normale il faut donner les autorisation lors d'ouvrir l'adminestrateur », lue avec les notes de Q4-e (« L'administrateur assure également la gestion et le contrôle de la société et ne vend pas en caisse, sauf si une autorisation spécifique de vente lui est accordée ») et de Q4-h (« Les ventes sont réalisées par les responsables et les vendeurs, et éventuellement par un autre rôle uniquement s'il possède explicitement l'autorisation de vendre. Ne plus partir de l'hypothèse que le propriétaire utilise la caisse ») ; Q3-b (a) ; Q3-c (a) ; P7 (b) ; Q4-e (« Un même utilisateur ne peut jamais avoir deux caisses ouvertes simultanément, même s'il dispose d'un accès à plusieurs magasins ») et précision 2 (« « Une caisse à la fois » vaut par compte ») ; Q4-g (a) ; Q4-j (c) ; précision 5 (mot de passe égal à l'identifiant, non obligatoire à changer).
- **Plan** (`serie8/plan-serie8.md`, écrit avant ces réponses) : sections 2.3, 2.4, 2.7, 2.8, 2.9, 3.0, 3.2, lots S8-V et A4c, 3.4, 4.1 (Q3, Q4), annexes D et E. Analyse de l'équipe : `serie8/equipe-et-achats.md` (sections 1 à 3). Demande de l'éditeur : points 6, 7, 17 et 20 (traduction et texte d'origine).
- **Code** : HEAD `6e05c75` (« Relecture du lot S8-A de la série 8 »), lu uniquement par `git show HEAD:<chemin>` et `git grep … HEAD`. Tous les `fichier:ligne` ci-dessous sont ceux de HEAD. Le lot A5c (`975d7d4`) a été commité pendant l'analyse : il ne touche aucun fichier cité ici sauf `Magasin.php` (référence mise à jour, `refusDeVente()` en `:407-418`) et deux puces de `CLAUDE.md` qui ne sont pas réécrites ici (« Société ou magasin suspendu… », « Société et établissements ») ; les numéros de ligne des puces citées en section 7 sont inchangés (vérifié par `git diff 6e05c75..975d7d4`). Une autre chaîne modifie le dépôt en ce moment (lots A5c, S8-P, S8-R, S8-C, S8-E, S8-L, S8-F, S8-O) : les lignes sont à relire au début du lot qui les touche.
- **Banc** (copies jetables de HEAD par `git archive`, `vendor` en liens physiques pour ne consommer aucune place, SQLite en mémoire ; supprimées à la fin) : cinq passages de la suite complète, `php artisan test --parallel` :

| Passage | Changement fait dans la copie | Résultat |
|---|---|---|
| base | aucun | 3 725 cas (2 262 méthodes), 3 681 réussis, 44 sautés (PostgreSQL seul), code 0 |
| b | le propriétaire perd les quatre autorisations de la liste fermée (2.3) : `User.php:629-631` rend `toutes()` moins `FACTURE_CREATE`, `FACTURE_EMETTRE`, `PAIEMENT_CREER`, `STOCK_VENDRE_SANS_STOCK` | **876 méthodes en échec** (1 089 cas) dans 83 fichiers ; 892 cas par `LecteurDesDroits::REFUS` |
| c | `modeleDeLAdministrateur()` perd aussi `FACTURE_CREATE`, `FACTURE_EMETTRE`, `PAIEMENT_CREER` (propriétaire inchangé) | **2 méthodes** en échec dans 2 fichiers |
| d | comme b, plus `AVOIR_CREER`, `DEVIS_CREATE`, `DEVIS_UPDATE`, `DEVIS_ENVOYER` (option (b) de la question Q-V1) | 957 méthodes (1 211 cas) dans 86 fichiers : **81 de plus que b**, presque toutes sur les devis |
| e | comme b, et `TestCase::auteur()` rend, au lieu du propriétaire, un responsable du magasin actif créé à la demande (aide d'essai `responsableDeTest()`) | **441 méthodes** (591 cas) dans 56 fichiers, dont 3 seulement qui réussissaient en b |

Les listes par fichier sont en annexe A ; les sorties brutes (JUnit et résumés) restent dans `serie8/banc-avenant-resultats/`.

---

## 1. L'essentiel

1. **Une seule source donne aujourd'hui toutes les autorisations au propriétaire** : `User::autorisationsEnregistreesDans()` rend `PermissionRegistry::toutes()` pour lui (`app/Models/User.php:629-631`). Tout le reste en découle : la Gate (`ControleDesDroits.php:135`), chaque policy, la relecture sous verrou des actions (`LecteurDesDroits.php:54-74`, `:98-109`), l'ouverture de la caisse (`Caisse.php:143-150`) et la caisse personnelle (`ClotureCaissePersonnellePolicy.php:99-108`). Retirer la vente à cet endroit l'interdit partout côté serveur (écran, requête Livewire forgée, action appelée directement, magasin de démonstration) ; aucune route d'API n'existe (`routes/` ne contient que `web.php`). La preuve est le passage b : 892 refus par `LecteurDesDroits::REFUS`, aucun chemin de vente resté ouvert.
2. **Liste fermée proposée des autorisations de vente** (`PermissionRegistry::deVente()`) : `facture.create`, `facture.emettre`, `paiement.creer`, `stock.vendre_sans_stock`. Ce sont exactement « ticket, facture, encaissement » de la précision 1, plus la seule autorisation qui ne sert qu'à l'intérieur d'une vente. Le propriétaire ne les a jamais ; un administrateur ne les a que cochées par le propriétaire, jamais précochées. Tout le reste reste au propriétaire (supervision) et garde le précochage actuel pour l'administrateur, sauf les deux gestes dont le classement n'est pas tranché : **l'avoir et le devis** (question Q-V1), et **les preuves d'exonération et le bordereau de détaxe**, aujourd'hui liés à l'autorisation d'émettre (question Q-V2).
3. **L'administrateur** : seul `modeleDeLAdministrateur()` change (`PermissionRegistry.php:292-295`) ; plus de liste blanche, de colonne en base ni de refus dans `PorteeDesAdministrateurs`, que le plan prévoyait (la réponse à Q3-a écarte « l'interdiction absolue »). Le propriétaire coche la vente à qui il veut. Coût mesuré : 2 tests. **L'ancien propriétaire**, à la transmission, reçoit aujourd'hui toutes les autorisations, vente comprise (`TransfererProprieteAction.php:86-91`) : il recevra toutes les autorisations de magasin sauf la vente.
4. **La règle « jamais au-delà des siennes »** ne doit pas suivre le propriétaire : il coche pour un vendeur ou un administrateur des autorisations de vente qu'il n'exerce pas lui-même. Le code le permet déjà (`PorteeDesDroits.php:363-366` rend `toutes()` au propriétaire, sans lire ses propres autorisations) ; la phrase de `CLAUDE.md` et le docblock (`PorteeDesDroits.php:63-66`) sont à réécrire.
5. **En base**, le propriétaire n'a aucune ligne de droits à garder : la garde porte sur les documents. Proposition : un déclencheur sur `enregistrements_fiscaux` (une vente ou un encaissement signé au nom d'un propriétaire), `factures` (création ou changement d'auteur) et `paiements` (saisie hors contre-passation) — section 3.4.
6. **Tests** : 876 méthodes font vendre le propriétaire, surtout par l'aide `auteur()` (`tests/TestCase.php:608-613`, 1 110 appels dans 90 fichiers). Changer son repli du propriétaire vers un responsable du magasin en corrige la moitié sans toucher une assertion (passage e) ; le reste (441 méthodes) se réécrit test par test, surtout les tests d'écran qui se connectent comme propriétaire (`FactureResourceTest` 73, `CaisseTest` 65, `PaiementResourceTest` 30, `AvoirResourceTest` 28), avec une aide `vendeurDeLaPremiereHeure()` déjà existante comme modèle.
7. **« Les caisses »** : libellé du menu du magasin selon la personne, et une page de l'espace de la société qui lit, magasin par magasin sous `TenantContext::runAs()`, les relevés et les caisses ouvertes des magasins réels de la portée où la personne voit les relevés de toute l'équipe : nouvelle lecture hors du magasin courant, à déclarer dans `CLAUDE.md` (section 6).

---

## 2. Ce qu'est « vendre » dans le code

### 2.1 Les autorisations de magasin

Le registre (`app/Domain/Authorization/PermissionRegistry.php:219-236`) en compte 43 ; le référentiel en base les recopie, une ligne par clé, par sa seule migration (`database/migrations/2026_10_02_095000_create_referentiel_autorisations_magasin_table.php:24-31`), et chaque case cochée le référence (`2026_10_02_100006_create_autorisations_affectations_table.php:23-30`). Le référentiel n'a aucune colonne de classement : aucune n'est nécessaire (2.3).

| Autorisation | Ce qu'elle garde (fichier:ligne) | Classement proposé | Propriétaire | Modèle de l'administrateur |
|---|---|---|---|---|
| `facture.create` | `FacturePolicy::create` `:37-40`, `update` (brouillon) `:50-55`, `delete`/`deleteAny` (brouillon) `:106-116` ; `DevisPolicy::convertir` `:96-99` ; caisse `Caisse.php:145-149`, `EnregistrerVenteCaisseAction.php:119` ; « tient une caisse » `ClotureCaissePersonnellePolicy.php:107` | **vente** | jamais | jamais précochée |
| `facture.emettre` | `FacturePolicy::emettre` `:57-60` (`EmettreFactureAction.php:179`) et `justifier` `:68-71` ; caisse `Caisse.php:147`, `EnregistrerVenteCaisseAction.php:121-123` | **vente** (mais voir Q-V2 pour `justifier`) | jamais | jamais précochée |
| `paiement.creer` | `PaiementPolicy::create` `:40-43` (`EnregistrerPaiementAction.php:125`), `FacturePolicy::encaisser` `:88-94` (`EnregistrerPaiementAction.php:158`) ; règlement comptant `EmettreFactureAction.php:181-183` ; caisse `Caisse.php:148` ; « tient une caisse » `ClotureCaissePersonnellePolicy.php:107` | **vente** (encaissement) | jamais | jamais précochée |
| `stock.vendre_sans_stock` | `VerificateurDeStock.php:67`, appelé à l'émission | **vente** (n'agit qu'à l'intérieur d'une vente) | jamais | jamais précochée (déjà, `PermissionRegistry.php:294`) |
| `vente.remises_et_prix` | `DerogationsDePrix.php:57` (ligne d'un ticket, d'une facture **ou d'un devis**), `AjouteDesLignesDeVente.php:650`, prix de référence d'un service `ServiceResource.php:54`, `TrouverOuCreerServiceAction.php:53` | gestion du catalogue ; sur une ligne de vente, inerte sans `facture.create` | gardée | précochée (Q3-c ; décision A6) |
| `devis.create`, `devis.update`, `devis.envoyer` | `DevisPolicy` `:21-24`, `:30-55`, `:71-90` (`envoyer` numérote le devis : `EmettreDevisAction.php:66` ; `accepter`, `refuser`) | **non tranché : Q-V1** | selon Q-V1 | selon Q-V1 |
| `avoir.creer` | `AvoirPolicy::create` `:39-42` (`CreerAvoirAction.php:91`, `EmettreAvoirAction.php:95`, `EnregistrerAvoirAction.php:75`), `AvoirResource.php:376`, `ListAvoirs.php:19`, bouton « Retour d'un ticket » de la caisse `Caisse.php:435-440` | **non tranché : Q-V1** | selon Q-V1 | selon Q-V1 |
| `paiement.contre_passer` | `PaiementPolicy::contrePasser` `:50-56` (`ContrePasserPaiementAction.php:107`) | gestion (Q3-b) | gardée | précochée |
| `facture.envoyer`, `avoir.envoyer` | `FacturePolicy::envoyer` `:77-80`, `AvoirPolicy::envoyer` `:44-47` (`EnvoyerDocumentAction.php:226`), relances | gestion (Q3-b) | gardées | précochées |
| `cloture_caisse.gerer` | `ClotureCaissePolicy::cloturer` `:43-46` (`CloturerCaisseAction.php:101`), `fermerLaCaisseDe` `ClotureCaissePersonnellePolicy.php:59-64` (`FermerSaCaisseAction.php:101`) | supervision | gardée | précochée |
| `cloture_caisse.view`, `cloture_caisse.view_toutes` | relevés et clôtures | consultation | gardées | précochées |
| `relances.regler`, `prix_vente.regler`, `parametres.gerer` | réglages du magasin | gestion | gardées | précochées, sauf `parametres.gerer` (inchangé) |
| `client.*`, `fournisseur.*`, `produit.*` (dont `produit.ajuster_stock`, `produit.voir_valeur_stock`) | fiches, stock, alertes (`DestinatairesDesAlertes.php:51` ne lit que `produit.ajuster_stock`) | gestion | gardées | précochées |
| `facture_achat.*`, `avoir_fournisseur.*` | achats | gestion | gardées | précochées |
| `devis.view`, `facture.view`, `facture.view_toutes`, `paiement.view`, `avoir.view`, `caisse_securisee.consulter` | consultations (`PermissionRegistry.php:252-267`) | consultation | gardées | précochées |

Les autorisations de société (`AutorisationsSociete.php:38-42` : magasins, responsables, paramètres) ne touchent aucune vente.

### 2.2 Les gestes de vente et leur garde

| Geste | Écran | Action, contrôle sous verrou | Autorisation exigée |
|---|---|---|---|
| Ouvrir la caisse | `Caisse::canAccess()` `Caisse.php:143-150` | — | les trois : `facture.create`, `facture.emettre`, `paiement.creer` |
| Vente à la caisse (ticket) | `Caisse` | `EnregistrerVenteCaisseAction.php:119-123` (relit l'auteur sous verrou, puis émet et encaisse) | les trois |
| Bon d'avoir à la caisse | `Caisse` (« Utiliser un bon d'avoir ») | `EnregistrerVenteCaisseAction.php:129`, `:169-178` (dans la même vente) | les trois, rien de plus |
| Facture brouillon (créer, modifier, supprimer) | `FactureResource` | `CreerFactureAction.php:55`, `ModifierFactureAction.php:87` | `facture.create` |
| Émettre une facture | `FactureResource` | `EmettreFactureAction.php:179-185` | `facture.emettre` (+ `paiement.creer` si réglée à l'émission) |
| Encaisser une facture | `FactureResource`, `PaiementResource` | `EnregistrerPaiementAction.php:125-126`, `:158` | `paiement.creer` (+ voir la facture) |
| Contre-passer un paiement | `PaiementResource` | `ContrePasserPaiementAction.php:107-108`, signée dans la chaîne des encaissements comme `contre_passation` (`:158`) | `paiement.contre_passer` |
| Avoir (retour) | `AvoirResource`, action « Avoir » des factures, bouton de la caisse | `CreerAvoirAction.php:91`, `EmettreAvoirAction.php:95` (signé dans la chaîne des ventes : `:144`) | `avoir.creer` |
| Devis : créer, modifier, numéroter et envoyer, accepter, refuser | `DevisResource` | `CreerDevisAction.php:46`, `ModifierDevisAction.php:67`, `EmettreDevisAction.php:66`, `AccepterDevisAction.php:44`, `RefuserDevisAction.php:35` | `devis.create` / `devis.update` / `devis.envoyer` |
| Convertir un devis en facture | `DevisResource` | `CreerFactureDepuisDevisAction.php:52` | `facture.create` |
| Envoyer ou relancer un document émis | fiches, listes | `EnvoyerDocumentAction.php:226` | `facture.envoyer`, `avoir.envoyer`, `devis.envoyer` |
| Noter une preuve d'exonération, un bordereau de détaxe | fiche de la facture | `AjouterPreuveOperationAction.php:70`, `NoterBordereauDetaxeAction.php:69` | `justifier` = `emettre` (`FacturePolicy.php:68-71`) |
| Clôturer la journée | `ClotureCaisseResource` | `CloturerCaisseAction.php:101` | `cloture_caisse.gerer` |
| Fermer sa caisse | `ListClotureCaissePersonnelles.php:46-54` | `FermerSaCaisseAction.php:100` | « tient une caisse » : `facture.create` ou `paiement.creer` dans le magasin (`ClotureCaissePersonnellePolicy.php:99-108`) |
| Fermer la caisse d'un vendeur | `ListClotureCaissePersonnelles.php:55-90` | `FermerSaCaisseAction.php:101` | `cloture_caisse.gerer` |
| Remise, prix dérogatoire, prix d'un service | lignes de vente, `ServiceResource` | `DerogationsDePrix.php:57`, `TrouverOuCreerServiceAction.php:53` | `vente.remises_et_prix` |
| Vendre sans stock | émission | `VerificateurDeStock.php:67` | `stock.vendre_sans_stock` |
| « Une caisse à la fois » | — | `VerificateurCaissesPersonnelles.php:83-106`, appelé par l'émission (`EmettreFactureAction.php:185`), le paiement (`EnregistrerPaiementAction.php:126`) et la contre-passation (`ContrePasserPaiementAction.php:108`) | — (par compte) |

Ce qui ouvre une caisse personnelle : une facture émise ou un paiement saisi par le compte, jamais un avoir ; une contre-passation compte dans la caisse du vendeur du paiement qu'elle corrige, pas dans celle de son auteur (`VerificateurCaissesPersonnelles.php:24-31`, `:55-64`). Un propriétaire qui contre-passe n'ouvre donc aucune caisse.

### 2.3 Liste fermée proposée

```php
/** Les autorisations de vente : le propriétaire ne les a jamais ; un administrateur seulement cochées par le propriétaire. */
public static function deVente(): array
{
    return [self::FACTURE_CREATE, self::FACTURE_EMETTRE, self::PAIEMENT_CREER, self::STOCK_VENDRE_SANS_STOCK];
}
```

- Fondement : précision 1 (« ni ticket, ni facture, ni encaissement ») ; Q4-e et Q4-h pour l'administrateur (« sauf si une autorisation spécifique de vente lui est accordée », « uniquement s'il possède explicitement l'autorisation de vendre »). `stock.vendre_sans_stock` n'a aucun effet hors d'une émission (`VerificateurDeStock.php:67`) : la garder au propriétaire contredirait le texte de `CLAUDE.md` à réécrire (« le propriétaire … le sont toujours ») sans lui rien donner.
- Pas de liste blanche ni de colonne en base : la réponse à Q3-a refuse « l'interdiction absolue » (`reponses-serie8.md`, Q3-a : « Pas de liste fermée qui interdirait à jamais la vente à un administrateur »). La liste fermée ci-dessus ne sert qu'à deux choses : ce que le propriétaire n'a jamais, et ce que le modèle de l'administrateur ne précoche jamais.
- Garde contre l'oubli : un test méta exige que chaque autorisation de `toutes()` soit soit dans `deVente()`, soit dans une liste explicite `horsVente()` ; une autorisation ajoutée plus tard sans classement fait échouer le test (idée de l'annexe E, défaut 21, adaptée : elle reste vraie sans la liste blanche).
- Si Q-V1 répond (b), `avoir.creer`, `devis.create`, `devis.update`, `devis.envoyer` entrent dans la liste ; si Q-V2 répond (a), rien ne change dans la liste (le geste suit `facture.emettre`).

### 2.4 Les gestes voisins

| Geste | Dans la vente ? | Raison | Texte de l'éditeur |
|---|---|---|---|
| Contre-passation | **non** | Elle corrige un encaissement erroné, n'en fait aucun, n'ouvre la caisse de personne (2.2) ; l'alerte des factures rouvertes par une contre-passation s'adresse justement au propriétaire. | Q3-b (a) : « un administrateur autorisé garde la contre-passation » ; point 16 (« le propriétaire voit toutes celles de la société ») ; précision 1 (« il supervise et contrôle »). |
| Envoi, relance d'un document émis | **non** | Aucun document ni montant nouveau. | Q3-b (a). |
| Clôture du jour | **non** | Supervision ; les clôtures continuent même en lecture seule (`PermissionRegistry.php:279-282`). | Précision 1 ; plan S8-V, « clôture du jour par un administrateur autorisé (permise : ce n'est pas une vente) ». |
| Fermer la caisse d'un vendeur | **non** | Même autorisation que la clôture (`ClotureCaissePersonnellePolicy.php:59-64`). | Idem. |
| Vendre sans stock | **oui** | N'agit que pendant une émission. | Précision 1. |
| Remises et prix | **non** comme autorisation (catalogue) ; sur une ligne de ticket ou de facture, elle reste inerte sans `facture.create` | Elle couvre aussi le prix de référence d'un service au catalogue, que garde un administrateur autorisé, et les lignes d'un devis (`DerogationsDePrix.php:57`). | Q3-c (a). |
| Avoir (retour) | **non tranché** | Arguments pour « non » : la précision 1 ne le cite pas ; il ne fait entrer aucun argent et n'ouvre aucune caisse ; le modèle du vendeur le réserve déjà aux supérieurs comme une correction (`RoleMagasin.php:58-60` : « ne les corrige pas par un avoir ») ; « une facture émise se corrige par un avoir » (contrôle). Pour « oui » : il est signé dans la chaîne des ventes (`ChaineFiscale::Vente`, « Tickets, factures et avoirs » ; `EmettreAvoirAction.php:144`) et le retour d'un ticket se lance depuis la caisse (`Caisse.php:435-440`). | Q-V1. |
| Devis | **non tranché** | Pour « non » : aucun montant signé, aucune caisse, la conversion en facture exige déjà `facture.create` (`DevisPolicy.php:96-99`) ; Q3-a « laisse comme il est ». Pour « oui » : c'est une proposition commerciale au client, numérotée (« devis numéroté » figure parmi les opérations commerciales réelles de la puce « Vérification de la société »). | Q-V1. |
| Preuves d'exonération, bordereau de détaxe | **non tranché** | Aujourd'hui « réservé à qui peut émettre » (`FacturePolicy.php:62-71` ; tests `test_le_bouton_noter_le_bordereau_est_reserve_a_qui_peut_emettre_un_ticket_detaxe`, `test_le_bouton_ajouter_une_preuve_est_reserve_a_qui_peut_emettre_une_vente_exoneree`). Le propriétaire et un administrateur sans vente les perdraient (vérifié au passage c : `FacturePolicyTest::test_justifier_une_vente_du_proprietaire_selon_le_role`, jeu « vendeur aux cases de l'administrateur », échoue). Ce sont des données ajoutées après l'émission, comme l'envoi. | Q-V2. |
| Bon d'avoir | **oui**, mais sans autorisation propre | Il ne s'utilise qu'à l'intérieur d'une vente à la caisse (Q6 (a)), donc avec les trois autorisations. | Q6. |

---

## 3. Comment le propriétaire vend aujourd'hui, et comment l'interdire

### 3.1 Chemins existants (tous vérifiés au passage b)

| Chemin | Où | Effet aujourd'hui |
|---|---|---|
| Droits implicites | `User.php:629-631` (« le propriétaire : toutes ») ; `autorisationsDans()` `:497-504` | toutes les autorisations dans chaque magasin de sa société, démonstration comprise |
| Gate | `ControleDesDroits.php:131-135` | répond « oui » pour toute autorisation qu'il a ; docblock `:13-19` (« c'est par ses autorisations, toutes sans case, qu'il passe les policies ») |
| Relecture des actions | `LecteurDesDroits.php:54-74`, `:98-109` | repose sur la Gate : aucun chemin propre au propriétaire |
| Opérateur signé | `OperateurCourant.php:43-46` | signe l'auteur relu, quel qu'il soit |
| Policies | `FacturePolicy`, `PaiementPolicy`, `AvoirPolicy`, `DevisPolicy`, `ClotureCaissePersonnellePolicy` (2.2) | aucune ne teste le rôle ; toutes passent par `can()` |
| Caisse | `Caisse.php:143-150` ; « tient une caisse » `ClotureCaissePersonnellePolicy.php:99-108` | ouverte, « Fermer ma caisse » visible |
| Caisse d'un propriétaire fermée par un autre | `ClotureCaissePersonnellePolicy.php:127` (`$vendeur->estProprietaire() || …`) | le propriétaire est compté comme vendeur possible |
| Magasin de démonstration | `User.php:691-706` (`peutOuvrir`) ; `Magasin.php:407-418` (la démonstration peut vendre tant que la société n'est pas validée) ; fenêtre « vous y vendez, encaissez et clôturez comme pour de vrai » `TableauDeBord.php:224` | il y vend |
| Livewire forgé | toutes les actions passent par l'action du domaine, qui relit l'auteur | refusé dès que la source change (tests `ActionsForgeesTest`, en échec au passage b, donc couverts) |
| API | aucune (`routes/web.php` seul) | — |

Aucun `estProprietaire()` ne court-circuite une vente : les seuls emplois hors équipe concernent les traces techniques de l'export du journal (`ExporterJournalDesEvenementsAction.php:113`, `EnregistrementFiscalResource.php:79`), l'équipe et la société.

### 3.2 Interdiction côté serveur

1. `PermissionRegistry` : `deVente()` (2.3), `horsVente()`, et `duProprietaire()` = `toutes()` moins `deVente()`, triée ; docblock `:16-30` réécrit (« le propriétaire les a toutes, sauf celles de la vente »).
2. `User::autorisationsEnregistreesDans()` (`User.php:624-651`) : `duProprietaire()` au lieu de `toutes()` à `:629-631` ; docblock `:610-622` et docblock de la classe `:31-44` (« qui a tous les droits … sans case ni affectation » → « tous sauf ceux de la vente »).
3. Rien d'autre à brancher : la Gate, les policies, `LecteurDesDroits`, `Caisse::canAccess()` et `tientUneCaisse()` refusent d'eux-mêmes ; le menu « Caisse » disparaît (Filament masque une page dont `canAccess()` est faux) ; « Nouvelle facture », « Émettre », « Encaisser » disparaissent par leurs policies.
4. Code devenu faux, à retirer : la clause `$vendeur->estProprietaire()` de `ClotureCaissePersonnellePolicy.php:127` (un propriétaire n'a jamais de caisse ; le cas d'un administrateur devenu propriétaire avec une caisse ouverte est déjà couvert par les clauses des factures et des paiements `:129-130`) et son docblock `:110-118` ; le docblock de la classe `:12-27`.
5. Docblocks à corriger : `ControleDesDroits.php:13-19` et `:50-55` ; `PorteeDesDroits.php:63-66` (« toutes pour le propriétaire et un responsable » : le propriétaire coche toutes les autorisations, vente comprise, sans les exercer).
6. Libellés : la fenêtre de la démonstration (`TableauDeBord.php:224`) dit « vous y vendez, encaissez » : elle devient « votre équipe y vend, encaisse et clôture comme pour de vrai, sous des comptes d'entraînement que vous lui créez » (Q4-g) ; l'avis de transmission (`TransfererProprieteAction.php:95-96`, section 4.3).

### 3.3 Ce qui ne change pas (supervision)

Consultation de tout ; clôture du jour et fermeture de la caisse d'un vendeur ; contre-passation ; envoi et relance ; réglages du magasin (`parametres.gerer`, barème, relances) ; catalogue, stock, achats ; équipe : `PorteeDesDroits::autorisationsCochables()` (`:363-366`) rend `toutes()` au propriétaire indépendamment de ses propres autorisations, et les écrans proposent toutes les cases (`EquipeDuMagasinResource.php:133`, `MembreResource.php:245`, `:302`) : il coche la vente pour un vendeur ou un administrateur sans l'avoir. Alertes : `DestinatairesDesAlertes` ne lit que `produit.ajuster_stock`. Alerte des factures à encaisser : `User::suitLesFacturesAEncaisserDans()` (`User.php:520-525`) lit `facture.view` et `facture.view_toutes`, gardées.

### 3.4 Interdiction en base

Le propriétaire n'a ni affectation ni case (`create_affectations_table.php:16-20` ; `GardesDesDroits.php:17-21`) : il n'y a rien à refuser dans les tables de droits. La garde porte sur les documents, dans la migration de création de chaque table (une seule migration par table, schéma final) :

| Table | Déclencheur (PostgreSQL : fonction plpgsql BEFORE ; SQLite : RAISE(ABORT)) | Message |
|---|---|---|
| `enregistrements_fiscaux` (`2026_10_14_100002_…:35` `operateur_id`) | BEFORE INSERT : refuse si (`chaine = 'vente'` et `type IN ('facture','ticket')`) ou (`chaine = 'encaissement'` et `type = 'paiement'`), et que `operateur_id` est un compte `role_societe = 'proprietaire'` ; laisse passer `contre_passation`, les avoirs (sauf si Q-V1 (b) : ajouter `avoir`), les événements et l'opérateur nul (le système) | « Le propriétaire ne vend jamais : aucune vente ni aucun encaissement ne s'inscrit à son nom. » |
| `factures` (`2026_10_03_100003_…:84` `cree_par`) | BEFORE INSERT et BEFORE UPDATE **OF cree_par** seulement : refuse un `cree_par` propriétaire. Pas sur tout UPDATE : un brouillon créé par un administrateur devenu propriétaire doit pouvoir être émis par un autre compte. | idem |
| `paiements` (`2026_10_04_100001_…:34`) | BEFORE INSERT : refuse un `cree_par` propriétaire quand `contre_passe_paiement_id IS NULL` | idem |

- Ordre des verrous : une vente verrouille le registre puis la ligne de son auteur (`LecteurDesDroits.php:69-74`) ; la transmission de la propriété verrouille les registres de tous les magasins puis les comptes (`TransfererProprieteAction.php:71`, via `JournalDesDroits`) : le rôle lu par le déclencheur est donc stable. Sous PostgreSQL, la fonction prend en plus la ligne du compte `FOR SHARE`, comme `GardesDesDroits` (`:37-48`).
- Les déclencheurs ajout seul existants de `enregistrements_fiscaux` (`DeclencheursDeProtection::ajoutSeul`, `:57` de la migration) restent ; le nouveau s'ajoute avant l'insertion.
- Le devis n'a pas d'auteur en base (`2026_10_03_100001_create_devis_table.php`) : aucun déclencheur possible ; si Q-V1 (b), la garde des devis reste côté serveur seulement (à dire dans le docblock).

### 3.5 Conséquences à annoncer à l'éditeur (règles déjà tranchées)

- **Un virement reçu sur une facture** s'enregistre par un responsable, un vendeur ou un administrateur à qui le propriétaire a coché « Paiements : enregistrer » : c'est un encaissement (précision 1), et il ouvre la caisse personnelle de qui le saisit (`VerificateurCaissesPersonnelles.php:30-31`).
- **Magasin de démonstration** : le compte du propriétaire n'y vend pas non plus (« Il ne vend jamais ») ; il essaie la caisse par un compte d'entraînement qu'il crée (Q4-g). Jusqu'au lot A4c, ce compte exige une autre adresse e-mail que la sienne (l'adresse est encore unique : `User.php:60-61`) ; de même, l'entrepreneur qui vend lui-même et se crée un compte de responsable a besoin, avant A4c, d'une seconde adresse. Aucune donnée réelle n'existe : la limite n'est que celle des essais locaux, et disparaît avec A4c.
- **Chemin résiduel accepté** : un administrateur autorisé à nommer les responsables crée un compte de responsable et en connaît le mot de passe (Q4-j (c), risque accepté) ; avec la précision 5, ce mot de passe est l'identifiant lui-même et n'expire pas. Le propriétaire est prévenu de chaque compte créé et de sa première connexion (Q4-j) ; rien d'autre ne ferme ce chemin.

---

## 4. L'administrateur et l'ancien propriétaire

### 4.1 Le modèle de l'administrateur

- `PermissionRegistry::modeleDeLAdministrateur()` (`:284-295`) : `toutes()` moins `parametres.gerer` moins `deVente()` (aujourd'hui moins `parametres.gerer` et `stock.vendre_sans_stock` seulement, donc avec `facture.create`, `facture.emettre`, `paiement.creer`). Docblock réécrit (« sauf les paramètres du magasin et les autorisations de vente, que le propriétaire coche pour qui il veut »).
- Écran : `MembreResource.php:300-305` précoche ce modèle ; proposition de présentation : les quatre autorisations de vente dans un groupe à part, « Vente (seulement si cet administrateur vend) », jamais précoché, avec un avertissement à la coche (« Cet administrateur pourra vendre et aura sa caisse personnelle ; il n'encaisse que dans un magasin à la fois. »).
- Rien ne change dans `PorteeDesAdministrateurs::lire()`/`ecrire()` (`:46-69`, `:98-115`), `User::autorisationsEnregistreesDans()` pour l'administrateur ni `GardesDesDroits` : un administrateur à qui le propriétaire coche la vente vend, avec les mêmes contrôles que tout vendeur (abandon par rapport au plan, section 4.5).
- `vente.remises_et_prix` reste précochée (décision A6 et Q3-c) ; sur une ligne de ticket ou de facture, elle n'agit qu'avec `facture.create`. Un administrateur à qui le propriétaire coche la vente peut donc aussi remiser : c'est l'état « laisse comme il est » de Q3-a.
- **Une caisse à la fois** pour un administrateur qui vend dans plusieurs magasins de sa portée : déjà tenue par compte (`VerificateurCaissesPersonnelles.php:83-106`), sans changement ; c'est le seul compte, avec un membre de plusieurs magasins jusqu'au lot A4c, pour qui la lecture « caisses ouvertes ailleurs » sert encore.
- **« Ma caisse »** : un administrateur sans vente ne tient pas de caisse (`tientUneCaisse()` faux) : « Fermer ma caisse » disparaît (`ListClotureCaissePersonnelles.php:53`) ; avec la vente cochée, il réapparaît.

### 4.2 Ce que mesure le banc (passage c)

Deux tests seulement dépendent de la vente précochée : `AutorisationsDesRolesTest::test_le_modele_de_l_administrateur_coche_tout_sauf_les_parametres_et_la_vente_sans_stock` (règle qui change : réécrit, décision nommée) et `FacturePolicyTest::test_justifier_une_vente_du_proprietaire_selon_le_role` (jeu « vendeur aux cases de l'administrateur » : suit Q-V2). Les jeux « vendeur aux cases de l'administrateur » des autres policies (`AvoirPolicyTest.php:35`, `DevisPolicyTest.php:40`, `:63`, `PaiementPolicyTest.php:60`, `FacturePolicyTest.php:91`, `EnregistrementFiscalResourceTest.php:108`, `StockLedgerServiceTest.php:241`…) restent verts ; les appels `administrateur(…, PermissionRegistry::toutes())` (9 dans 7 fichiers) restent valides, le propriétaire pouvant tout cocher.

### 4.3 L'ancien et le nouveau propriétaire après `TransfererProprieteAction`

- Aujourd'hui : l'ancien propriétaire devient administrateur de tous les magasins avec `PermissionRegistry::toutes()` (`TransfererProprieteAction.php:86-91`), vente comprise ; l'avis dit « avec toutes les autorisations » (`:95`) ; le docblock aussi (`:34-36`).
- Proposé : `'cases' => [0 => PermissionRegistry::duProprietaire()]`, soit exactement les autorisations de magasin qu'il avait comme propriétaire (toutes sauf la vente, `parametres.gerer` compris) ; avis : « … vous en êtes désormais administrateur de tous les magasins, avec toutes les autorisations de magasin sauf celles de la vente, que le nouveau propriétaire peut vous cocher, et toutes celles de la société. » ; l'avis au nouveau (`:96`) : « … vous avez désormais tous les droits dans la société et dans chacun de ses magasins, sauf celui de vendre : le propriétaire supervise et contrôle. »
- Le nouveau propriétaire perd ses cases d'administrateur (`:83`), vente comprise. S'il avait vendu, sa caisse reste ouverte : il ne peut plus la fermer lui-même (`tientUneCaisse()` faux), mais un responsable ou une personne autorisée à clôturer la ferme (`fermerLaCaisseDe`, clauses `:129-130`), la clôture du jour la ferme d'office, et minuit aussi (`FermerLesCaissesPersonnellesAction`). Test à ajouter : `test_le_nouveau_proprietaire_qui_avait_vendu_voit_sa_caisse_fermee_par_la_cloture_du_jour`.
- Tests touchés : `TransfererProprieteTest.php:184-205` (assertions `:197` et `:204` sur `toutes()` → `duProprietaire()`) et le texte de l'avis.

### 4.4 Avec le lot A4c

Un vendeur nommé administrateur garde son compte (Q4-k, précision 2) : la nomination remplace ses affectations par celles d'un administrateur (`AdministrateursTest.php:161`, garde `ROLES` de `GardesDesDroits`), et le formulaire précoche le modèle sans vente ; il ne garde aucune case de vente sans que le propriétaire la recoche. Le renouvellement d'accès par un administrateur (précision 4) ne touche aucune autorisation de vente.

### 4.5 Ce qui change par rapport au lot S8-V du plan

| Élément du plan (3.3, lot S8-V) | Sort |
|---|---|
| `permisesAuxAdministrateurs()` (liste blanche), colonne `permise_aux_administrateurs`, refus dans `PorteeDesAdministrateurs::lire()`/`ecrire()`, filtre dans `User::autorisationsEnregistreesDans()` pour l'administrateur, garde en base sur ses cases, écran sans les cases de vente | **abandonnés** (Q3-a : « il faut donner les autorisation lors d'ouvrir l'administrateur ») |
| Tests `test_le_proprietaire_ne_coche_jamais_une_autorisation_de_vente_pour_un_administrateur`, `test_ecrire_la_portee_…_refuse_une_case_non_permise`, `test_la_base_refuse_une_autorisation_non_permise_…`, `test_une_case_de_vente_restee_en_base_ne_donne_rien_a_un_administrateur`, `test_un_administrateur_n_ouvre_pas_la_caisse_…` | **abandonnés** ; remplacés par `test_le_modele_de_l_administrateur_ne_precoche_aucune_autorisation_de_vente` et `test_un_administrateur_a_qui_le_proprietaire_coche_la_vente_vend_avec_une_caisse_a_la_fois` |
| Test méta de classement | **gardé**, adapté (`deVente()` / `horsVente()`) |
| Transmission de la propriété (annexe E, défaut 17) | **gardé** : toujours vrai, correction 4.3 |
| Q4-j, compte de responsable créé par un administrateur (annexe E, défaut 3) | **risque accepté** par l'éditeur (Q4-j (c)), aggravé par la précision 5 (3.5) |
| Annexe D : « pas de page qui réunit tous les magasins » | **remplacé** par P7 (b) (section 6) |
| Annexe E, défaut 26 (`devis.envoyer` classé avec les devis) | **toujours vrai** : devient Q-V1 |
| Nouveau : le propriétaire ne vend jamais (3.2, 3.4), « jamais au-delà des siennes » réécrit, page des caisses de la société | **ajoutés** |

---

## 5. Les tests

### 5.1 Ce que mesure le banc

- Passage b : 876 méthodes (1 089 cas) dans 83 fichiers font vendre le propriétaire, directement ou pour préparer un état (une facture émise, un paiement à contre-passer, un ticket à créditer…). Les plus touchés : `Filament/FactureResourceTest` 73/74, `Filament/CaisseTest` 65/73, `CaisseSecurisee/VerificateurIntegriteTest` 48/49, `Facturation/AvoirActionsTest` 36/37, `Filament/PaiementResourceTest` 30/33, `Facturation/NatureOperationTest` 28/30, `Filament/AvoirResourceTest` 28/36, `Facturation/EmettreFactureActionTest` 24/24, `Facturation/CreerFactureActionTest` 21/21 (liste complète en annexe A.1).
- Tests dont la **règle** change (réécriture avec la décision nommée dans le message de commit) : `AutorisationsDesRolesTest::test_aucun_role_n_existe_et_le_proprietaire_est_implicite` (`:28-36`) et `test_le_proprietaire_a_implicitement_toutes_les_autorisations` (`:38-46`) ; `ControleDesDroitsTest::test_le_proprietaire_a_tous_les_droits_dans_chaque_magasin_sans_affectation`, `test_le_proprietaire_d_un_magasin_actif_garde_chaque_autorisation`, `test_les_operations_commerciales_sont_refusees_tant_que_le_magasin_ne_peut_pas_vendre`, `test_en_lecture_seule_consulter_cloturer_et_gerer_l_equipe_restent_permis` (ils bouclent sur `toutes()` pour le propriétaire : la boucle passe à `duProprietaire()`, et une assertion s'ajoute pour les quatre autorisations de vente refusées) ; `AutorisationsDesRolesTest::test_le_modele_de_l_administrateur_coche_tout_sauf_les_parametres_et_la_vente_sans_stock` (`:53-61`) ; `TransfererProprieteTest.php:184-205` ; les jeux de Q-V2 si (b).
- Aides et fabriques concernées : `TestCase::auteur()` (`tests/TestCase.php:608-613` : la personne connectée, sinon le propriétaire ; 1 110 appels dans 90 fichiers) ; `proprietaireDe()` (`:598-601`, 360 appels dans 74 fichiers, la plupart pour la gestion, légitimes) ; le motif `User::factory()->create()` + `ouvrirUneSociete([...], $proprietaire)` + `actingAs($proprietaire)` des tests d'écran (`UserFactory` crée un propriétaire par défaut, `database/factories/UserFactory.php:41`) ; `FactureFactory` ne pose aucun auteur (brouillon sans `cree_par`) et reste valable.

### 5.2 Stratégie de réécriture

1. **Une aide de test qui crée le vendeur du magasin**, sur le modèle de `vendeurDeLaPremiereHeure()` (`TestCase.php:315-329`) : `responsableDuMagasin(Magasin $magasin): User`, un responsable créé par le propriétaire au début de l'activité des magasins de test, une seule fois par magasin, retrouvé ensuite par une adresse fixe (`responsable-de-test-{id}@example.test` jusqu'à A4c, son identifiant ensuite) ; statique, comme `auteur()`, pour qu'un processus concurrent le retrouve sans le créer. Un responsable a toutes les autorisations de son magasin, sans case : les tests gardent exactement les mêmes droits qu'avec le propriétaire dans ce magasin, et aucune assertion ne s'affaiblit.
2. **Repli de `auteur()`** : la personne connectée, sinon ce responsable, au lieu du propriétaire. Mesuré (passage e, aide créée à la demande) : 876 → 441 méthodes en échec, **sans toucher une assertion** ; 3 tests seulement, qui réussissaient au passage b, en souffrent, parce que la création du compte écrit un JET 130 au registre au milieu du test (`FactureAchatActionsTest::test_une_facture_dachat_qui_ne_change_aucun_taux_s_enregistre_sans_le_registre_fiscal`, `CloturerCaisseActionTest::test_la_cloture_abandonne_les_ventes_restees_ouvertes_a_la_caisse_ce_jour_la`, `EnvoyerDocumentJobTest::test_un_devis_modifie_avant_le_depart_de_son_envoi_ne_part_pas`) : créer le responsable au début de l'activité, comme `vendeurDeLaPremiereHeure()`, règle l'ordre ; les listes exactes du journal qui suivent sa création gagnent son JET 130, écrit dans l'attendu (pas filtré).
3. **Le reste, test par test** (441 méthodes, 56 fichiers ; annexe A.2) :
   - tests d'écran connectés comme propriétaire pour vendre (environ 400 méthodes : `FactureResourceTest`, `CaisseTest`, `PaiementResourceTest`, `AvoirResourceTest`, `ClotureCaisseResourceTest`, `MagasinEnLectureSeuleTest`, `EnvoieLesDocumentsTest`, `FacturesAEncaisserTest`, `MagasinDeDemonstrationTest`…) : `actingAs($this->responsableDuMagasin($magasin))` à la place de `actingAs($proprietaire)`, ou un vendeur quand le nom du test parle d'un vendeur (« un vendeur encaisse une vente comptoir depuis la caisse », `CaisseTest.php:70-74`, se fait aujourd'hui sous le propriétaire) ; un test qui vérifie ce que voit le propriétaire (supervision) garde le propriétaire et prépare ses ventes sous le responsable ;
   - tests qui passent `$proprietaire` explicitement à une action de vente (`PreuvesOperationTest`, `BordereauDetaxeTest`, `ActionsHorsEcranTest`…) : même remplacement ; `ActionsHorsEcranTest` (4 méthodes, 52 jeux) prend le responsable comme « auteur qui a le droit » pour les gestes de vente ;
   - tests du registre et de l'intégrité (`JournalEvenementsTest`, `VerificateurIntegriteTest`, `VerifierIntegriteActionTest`, `EmissionSigneeTest`, `InalterabiliteTest` : environ 25 méthodes au passage e) : responsable créé au début, JET 130 dans l'attendu ;
   - tests de règle (5.1) : réécrits, décision nommée.
4. **Interdit** (protocole, règle 4) : aucun test supprimé ni affaibli ; aucun `retirerLaGardeDesDroits()` ni retrait du déclencheur de 3.4 pour faire passer une vente du propriétaire. Un test qui ne se réécrit pas sans changer son intention se signale au lieu de se modifier.
5. **Tests nouveaux** : `tests/Feature/Authorization/ProprietaireSansVenteTest.php` : `test_le_proprietaire_n_a_aucune_autorisation_de_vente_dans_aucun_magasin_ni_dans_la_demonstration` ; `test_le_proprietaire_n_ouvre_pas_la_caisse_et_n_a_pas_de_caisse_personnelle` ; `test_le_proprietaire_ne_cree_n_emet_ni_n_encaisse_par_l_ecran_par_livewire_ni_par_l_action` ; `test_le_proprietaire_garde_la_consultation_la_cloture_la_fermeture_d_une_caisse_la_contre_passation_et_l_envoi` ; `test_le_proprietaire_coche_les_autorisations_de_vente_d_un_vendeur_et_d_un_administrateur` ; `test_la_base_refuse_une_vente_ou_un_encaissement_signe_au_nom_du_proprietaire` (SQLite et PostgreSQL) ; `test_la_base_refuse_une_facture_ou_un_paiement_au_nom_du_proprietaire_mais_pas_sa_contre_passation` (SQLite et PostgreSQL) ; `test_chaque_autorisation_du_registre_est_classee_vente_ou_hors_vente`. `tests/Feature/Societe/AdministrateursTest.php` : `test_le_modele_de_l_administrateur_ne_precoche_aucune_autorisation_de_vente` ; `test_un_administrateur_a_qui_le_proprietaire_coche_la_vente_vend_avec_une_caisse_a_la_fois`. `TransfererProprieteTest` : `test_l_ancien_proprietaire_devenu_administrateur_n_a_aucune_autorisation_de_vente` ; `test_le_nouveau_proprietaire_qui_avait_vendu_voit_sa_caisse_fermee_par_la_cloture_du_jour`. `ClotureCaissePersonnelleResourceTest` : `test_le_menu_s_appelle_les_caisses_sauf_pour_qui_ne_voit_que_sa_caisse`. Page de la société : section 6.

---

## 6. « Les caisses » et la lecture hors du magasin courant

### 6.1 Dans le magasin

- `ClotureCaissePersonnelleResource.php:31` (« Ma caisse », fixe) devient un libellé calculé : « Ma caisse » pour qui tient une caisse sans voir les relevés de toute l'équipe ; « Les caisses » pour tous les autres (qui a `cloture_caisse.view_toutes`, ou qui ne tient pas de caisse : un administrateur sans vente qui ne fait que fermer la caisse d'un vendeur ne doit pas lire « Ma caisse »). Titre de la page assorti.
- L'encart des caisses ouvertes (`CaissesPersonnellesOuvertesApercu.php:22-25`, aujourd'hui sur la liste des clôtures, pour `cloture_caisse.gerer`) s'affiche aussi en tête de la page des relevés, avec la même condition que celle-ci : `cloture_caisse.view_toutes`.

### 6.2 Dans l'espace de la société (P7 (b))

- **Qui** : le propriétaire et chaque administrateur (`User::peutAdministrer()`, `User.php:679-682`) ; jamais un membre ni un expert-comptable, qui n'ouvrent pas cet espace.
- **Quels magasins** : `magasinsDeSaPortee()` (`User.php:450-463` : toute la société pour le propriétaire, la sélection d'un administrateur), sans le magasin de démonstration (comme `FacturesAEncaisserDeLaSociete.php:45-50`), et seulement ceux où la personne a `cloture_caisse.view_toutes` (toujours pour le propriétaire ; la case cochée pour un administrateur), c'est-à-dire ceux où elle voit déjà ces relevés dans le magasin. La page ne s'affiche pas quand aucun magasin ne reste.
- **Quoi** : par magasin, les caisses encore ouvertes (nom du vendeur seulement, comme l'encart du magasin) et les derniers relevés : nom figé du vendeur (`vendeur_nom`), période, mode de fermeture, nombre de ventes, TTC vendu, encaissements par mode, crédit d'avoir, reste dû ; un lien vers la page du magasin. Aucun nom de client, aucun document.
- **Comment** : une seule classe, `app/Domain/Facturation/Services/CaissesDeLaSociete.php`, sur le modèle de `FacturesAEncaisserDeLaSociete` (`:43-71`), mais qui lit chaque magasin sous `TenantContext::runAs($magasin, …)` (`TenantContext.php:129-140`, qui refuse un magasin d'une autre société que celle posée) : `MagasinScope` reste en place, `VerificateurCaissesPersonnelles::vendeursAvecCaisseOuverte()` (`:47-67`) sert tel quel, et aucune requête ne contourne la portée. Colonnes lues limitées à celles du relevé ci-dessus. Page `app/Filament/Societe/Pages/LesCaisses.php`, consultable dans une société en lecture seule (consultation : `ControleDesDroits.php:160-171`).
- **Tests** (`tests/Feature/Societe/CaissesDeLaSocieteTest.php`) : `test_le_proprietaire_voit_les_releves_et_les_caisses_ouvertes_de_chaque_magasin_reel` ; `test_un_administrateur_ne_voit_que_les_magasins_de_sa_selection_ou_il_voit_les_releves_de_toute_l_equipe` ; `test_la_demonstration_et_les_magasins_d_une_autre_societe_n_y_figurent_jamais` ; `test_la_lecture_ne_rend_que_les_champs_du_releve` ; `test_un_membre_ou_un_expert_comptable_n_ouvre_pas_la_page` ; `test_une_requete_livewire_forgee_ne_montre_aucun_magasin_hors_de_la_portee` ; la classe est déclarée dans `IsolationDesSocietesTest`.
- **`CLAUDE.md`**, puce 2 de « Produit » : nouvelle lecture (texte en 7.8).

### 6.3 La lecture « une caisse à la fois »

`VerificateurCaissesPersonnelles::refuserSiSaCaisseEstOuverteAilleurs()` (`:83-106`) reste, inchangé. La phrase de `CLAUDE.md` passe de « d'une personne » à « d'un compte autorisé à vendre » (précision 2, Q4-e) ; après A4c, elle ne sert plus qu'aux administrateurs qui vendent dans plusieurs magasins.

---

## 7. Phrases de `CLAUDE.md` à réécrire

Numéros de ligne de HEAD. Chaque phrase s'écrit dans le commit du lot qui la rend vraie ; une phrase soumise à Q-V1 ou Q-V2 s'écrit avec la réponse.

### 7.1 « Comptes et équipe » (ligne 161) : le propriétaire

> Actuel : « Elle a un seul propriétaire, qui a tous les droits dans la société et dans chacun de ses magasins sans qu'aucune autorisation ne lui soit demandée ; les règles qui valent pour tous (lecture seule, validation de la société, documents émis, clôtures, une caisse à la fois) s'appliquent à lui comme aux autres. »

> Nouveau : « Elle a un seul propriétaire, qui supervise et contrôle la société et chacun de ses magasins : il y a tous les droits sans qu'aucune autorisation ne lui soit demandée, sauf celui de vendre, qu'il n'a jamais, pas même dans le magasin de démonstration : il ne crée ni n'émet aucun ticket ni aucune facture, n'enregistre aucun encaissement, n'ouvre pas la caisse et n'a pas de caisse personnelle ; il contre-passe un paiement saisi par erreur, envoie et relance un document émis, clôture la journée et ferme la caisse d'un vendeur. Un entrepreneur qui vend lui-même dans son magasin s'y crée un compte de responsable. Les règles qui valent pour tous (lecture seule, validation de la société, documents émis, clôtures) s'appliquent à lui comme aux autres. »

Soumis à Q-V1 : avec (a), ajouter après « un document émis, » : « fait les avoirs et les devis, » ; avec (b), « ne fait ni avoir ni devis » rejoint la liste de ce qu'il ne fait pas. Soumis à Q-V2 : avec (b), ajouter « note les preuves d'exonération et les bordereaux de détaxe ».

### 7.2 « Comptes et équipe » : l'administrateur

> Actuel : « Seul le propriétaire crée et gère les administrateurs : il choisit l'accès de chacun à tous les magasins, présents et futurs, ou à une sélection de magasins, et coche ses autorisations. »

> Nouveau : « Seul le propriétaire crée et gère les administrateurs : il choisit l'accès de chacun à tous les magasins, présents et futurs, ou à une sélection de magasins, et coche ses autorisations. Un administrateur ne vend pas, sauf si le propriétaire lui coche les autorisations de vente (créer et émettre un ticket ou une facture, enregistrer un encaissement, vendre sans stock), qui ne sont jamais précochées pour lui, pas même pour l'ancien propriétaire devenu administrateur à la transmission de la propriété ; sans elles, il n'a pas de caisse personnelle. S'il y est autorisé, il contre-passe un paiement saisi par erreur, envoie ou relance une facture ou un avoir déjà émis, fixe le prix de référence d'un service, clôture la journée et ferme la caisse d'un vendeur. »

### 7.3 « Comptes et équipe » : les cases des vendeurs et observateurs

> Actuel : « Seuls le propriétaire et un responsable du magasin créent les vendeurs et les observateurs de ce magasin et leur cochent des autorisations valables pour ce magasin, jamais au-delà des siennes : pour eux, le rôle n'est qu'un modèle qui précoche des autorisations. »

> Nouveau : « Seuls le propriétaire et un responsable du magasin créent les vendeurs et les observateurs de ce magasin et leur cochent des autorisations valables pour ce magasin : le propriétaire toutes, y compris celles de vente qu'il n'exerce pas lui-même, un responsable jamais au-delà des siennes ; pour eux, le rôle n'est qu'un modèle qui précoche des autorisations. »

### 7.4 « Comptes et équipe » : la traçabilité et une caisse à la fois

> Actuel : « Chaque vente enregistre le magasin où elle est faite, avec son SIRET, et la personne qui l'a faite, affectée et autorisée dans ce magasin à ce moment. Une personne n'encaisse que dans un magasin à la fois : elle ferme sa caisse d'un magasin avant d'encaisser dans un autre. »

> Nouveau : « Chaque vente enregistre le magasin où elle est faite, avec son SIRET, et la personne qui l'a faite, autorisée à vendre dans ce magasin à ce moment, jamais le propriétaire ; la base refuse une vente ou un encaissement à son nom. Un compte autorisé à vendre n'encaisse que dans un magasin à la fois, même s'il a accès à plusieurs magasins : il ferme sa caisse d'un magasin avant d'encaisser dans un autre. »

(« affectée » disparaît : un administrateur autorisé vend sans affectation de membre ; après A4c, la phrase du lot A4c sur les comptes par magasin s'y fond.)

### 7.5 « Prix et TVA des ventes » (ligne 167)

> Actuel : « … exigent l'autorisation « accorder des remises et fixer le prix d'un service », que le propriétaire et les responsables du magasin ont toujours, précochée pour les administrateurs, jamais pour les vendeurs et les observateurs, à qui le propriétaire ou un responsable du magasin peut la cocher, et qu'un expert-comptable n'a jamais ; »

> Nouveau : « … exigent l'autorisation « accorder des remises et fixer le prix d'un service », que les responsables du magasin et le propriétaire ont toujours (lui, qui ne vend jamais, pour le prix de référence d'un service et les lignes d'un devis), précochée pour les administrateurs, pour qui elle ne vaut sur une ligne de ticket ou de facture que si le propriétaire leur a aussi coché la vente, jamais pour les vendeurs et les observateurs, à qui le propriétaire ou un responsable du magasin peut la cocher, et qu'un expert-comptable n'a jamais ; »

Soumis à Q-V1 : avec (b), « et les lignes d'un devis » disparaît.

### 7.6 « Achats et stock » (ligne 172)

> Actuel : « … sauf pour une personne autorisée à « vendre sans stock » dans ce magasin (le propriétaire et les responsables du magasin le sont toujours ; pour les autres, cette autorisation n'est jamais précochée, et un expert-comptable ne l'a jamais) : »

> Nouveau : « … sauf pour une personne autorisée à « vendre sans stock » dans ce magasin (les responsables du magasin le sont toujours ; le propriétaire, qui ne vend jamais, ne l'est jamais ; pour les autres, cette autorisation n'est jamais précochée, et un expert-comptable ne l'a jamais) : »

### 7.7 « Caisse et clôtures » (ligne 173)

> Actuel : « … et minuit ferme toute caisse personnelle qui ne l'a pas été ; un responsable peut aussi la fermer pour le vendeur ; »

> Nouveau : « … et minuit ferme toute caisse personnelle qui ne l'a pas été ; le propriétaire, un responsable ou une personne autorisée à clôturer la journée peut aussi la fermer pour le vendeur ; »

Ajout, après « Toutes les ventes et leurs règlements entrent dans les clôtures et les grands totaux. » : « Le menu des relevés de caisse s'appelle « Ma caisse » pour qui tient une caisse sans voir les relevés de toute l'équipe, « Les caisses » pour les autres, qui y voient aussi les caisses encore ouvertes ; dans l'espace d'administration, la page « Les caisses » réunit, pour le propriétaire et chaque administrateur, les relevés et les caisses encore ouvertes de chaque magasin réel de sa portée où il voit les relevés de toute l'équipe. » (Le plan, 2.9, prévoyait la première moitié ; la seconde vient de P7 (b).)

### 7.8 « Produit », puce 2 (ligne 155) : lectures hors du magasin

> Actuel : « … les caisses encore ouvertes d'une personne dans les autres magasins de sa société (une caisse à la fois) ; »

> Nouveau : « … les caisses encore ouvertes d'un compte autorisé à vendre dans les autres magasins de sa société (une caisse à la fois) ; les relevés de caisse (nom figé du vendeur, période, totaux du relevé) et les caisses encore ouvertes (nom du vendeur) de chaque magasin réel de sa portée où il voit les relevés de toute l'équipe, à la page « Les caisses » de l'espace d'administration, pour le propriétaire et un administrateur ; »

### 7.9 « Vendeurs » (ligne 171)

> Actuel (début) : « Vendeurs : un vendeur ne voit et n'encaisse que ses factures, sauf autorisation « voir celles de toute l'équipe » ; »

> Nouveau (début) : « Vendeurs : les ventes sont faites par les responsables et les vendeurs, et par un administrateur ou un observateur seulement si on lui a coché les autorisations de vente, jamais par le propriétaire. Un vendeur ne voit et n'encaisse que ses factures, sauf autorisation « voir celles de toute l'équipe » ; »

> Actuel : « Le relevé « Fermer ma caisse » sépare le TTC vendu, les encaissements par mode, le crédit d'avoir utilisé et le reste dû. »

> Nouveau : « Le relevé de caisse de chaque compte qui vend (« Fermer ma caisse ») sépare le TTC vendu, les encaissements par mode, le crédit d'avoir utilisé et le reste dû. »

La fin de la puce (alerte des factures à encaisser : « toutes celles du magasin pour le propriétaire, un responsable, un expert-comptable et un administrateur… ») ne change pas : c'est de la supervision.

### 7.10 Feuille de route, ligne 1 (ligne 192)

« administrateurs créés par le propriétaire, » devient « administrateurs créés par le propriétaire, qui ne vendent que si le propriétaire le leur coche, propriétaire qui ne vend jamais, » ; dans la parenthèse de la série 8 : « « Les caisses » du magasin et de la société ».

---

## 8. Questions à l'éditeur

**Q-V1. L'avoir (retour) et le devis sont-ils des ventes ?** (règle métier ; vos réponses disent « ni ticket, ni facture, ni encaissement » pour le propriétaire et « laisse comme il est » pour l'administrateur, sans trancher ces deux gestes)
- (a) **Recommandé** : ni l'un ni l'autre. Le propriétaire fait les avoirs (par exemple pour corriger une facture émise par erreur) et les devis ; ils restent précochés pour un administrateur, comme aujourd'hui. Un avoir ne fait entrer aucun argent et n'ouvre aucune caisse ; un devis n'est ni signé ni encaissé, et sa conversion en facture exige déjà le droit de facturer.
- (b) Les deux sont des ventes : le propriétaire n'en fait jamais, et ils ne sont jamais précochés pour un administrateur. Les retours et les devis se font alors sous un compte de responsable ou de vendeur. (Coût mesuré : 81 tests de plus à réécrire, surtout ceux des devis.)
- (c) L'avoir n'est pas une vente (une correction), le devis en est une (une proposition commerciale au client).

**Q-V2. Noter une preuve d'exonération (export, livraison dans l'UE ou vers un DOM) ou le bordereau de détaxe d'un ticket** (règle métier ; aujourd'hui « réservé à qui peut émettre » la vente)
- (a) Garder la règle : seul qui peut émettre le note ; le propriétaire ne le fait donc jamais, un administrateur seulement si le propriétaire lui coche la vente.
- (b) **Recommandé** : une autorisation à part, « Factures : noter les preuves d'exonération et les bordereaux de détaxe », que le propriétaire et les responsables ont toujours, précochée pour un vendeur (qui la tient aujourd'hui de son droit d'émettre) et pour un administrateur, jamais pour un expert-comptable. Comme l'envoi d'une facture (Q3-b), ce n'est pas une vente : une information ajoutée après l'émission, hors du document signé.

---

## Annexe A — Tests mesurés au banc

### A.1 Passage b : méthodes en échec quand le propriétaire ne vend plus (876 méthodes, 1 089 cas, 83 fichiers ; « en échec / méthodes du fichier »)

Filament/FactureResourceTest 73/74 ; Filament/CaisseTest 65/73 ; CaisseSecurisee/VerificateurIntegriteTest 48/49 ; Facturation/AvoirActionsTest 36/37 ; Filament/PaiementResourceTest 30/33 ; Facturation/NatureOperationTest 28/30 ; Filament/AvoirResourceTest 28/36 ; Facturation/EmettreFactureActionTest 24/24 ; Facturation/CreerFactureActionTest 21/21 ; Facturation/EnvoyerDocumentActionTest 21/23 ; Facturation/EnvoyerDocumentJobTest 21/27 ; Facturation/PreuvesOperationTest 21/22 ; CaisseSecurisee/EmissionSigneeTest 20/22 ; Facturation/FacturePdfGeneratorTest 19/19 ; Facturation/BordereauDetaxeTest 18/19 ; Facturation/ContrePasserPaiementActionTest 15/17 ; Facturation/EnregistrerPaiementActionTest 15/16 ; Filament/ClotureCaisseResourceTest 15/17 ; Tenancy/MagasinEnLectureSeuleTest 15/21 ; Facturation/ActionsUniquesTest 13/15 ; Facturation/MarquerFacturesEnRetardTest 12/12 ; Filament/EnvoieLesDocumentsTest 12/15 ; Facturation/ClotureAutomatiqueCaisseTest 11/13 ; Facturation/FranchiseEnBaseTest 11/14 ; Facturation/ImputerAvoirSurSesFacturesActionTest 11/11 ; Filament/FacturesAEncaisserTest 11/12 ; Societe/MagasinDeDemonstrationTest 11/16 ; CaisseSecurisee/InalterabiliteTest 10/11 ; Facturation/EmetteurFigeTest 10/14 ; Facturation/EnregistrerVenteCaisseActionTest 10/10 ; Facturation/RelancerFacturesImpayeesTest 10/11 ; Facturation/AvoirPdfGeneratorTest 9/9 ; Facturation/CloturerCaisseActionTest 9/15 ; Facturation/GenererSoumissionEReportingActionTest 9/10 ; Facturation/PolitiqueDArrondiTest 9/9 ; Filament/EnregistrementFiscalResourceTest 9/25 ; Filament/FacturesApercuWidgetTest 8/9 ; Authorization/ControleDesDroitsTest 7/16 ; CaisseSecurisee/VerifierIntegriteFiscaleTest 7/9 ; Societe/FermerEtRouvrirMagasinTest 7/21 ; Stock/VenteSansStockTest 7/12 ; CaisseSecurisee/JournalEvenementsTest 6/29 ; CaisseSecurisee/VerifierIntegriteActionTest 6/8 ; Facturation/AppliquerAvoirsDisponiblesActionTest 6/6 ; Facturation/CloturerLesJourneesActionTest 6/7 ; Facturation/DevisConversionTest 6/14 ; Facturation/TransmissionPdpTest 6/6 ; Facturation/TypeClientFigeTest 6/6 ; Societe/FacturesAEncaisserDeLaSocieteTest 6/6 ; Filament/ClientResourceTest 5/54 ; Filament/DevisResourceTest 5/32 ; Societe/ParametresFiscauxSocieteTest 5/8 ; Authorization/ActionsHorsEcranTest 4/4 ; Facturation/FacturePolicyTest 4/13 ; Facturation/SoumettreEReportingPeriodiqueTest 4/4 ; Societe/PorteDeValidationTest 4/8 ; Tenancy/IsolationDesSocietesTest 4/19 ; Tenancy/TenantIsolationTest 4/11 ; Authorization/LecteurDesDroitsTest 3/11 ; Facturation/FactureNeSePasSupprimeTest 3/4 ; Facturation/RepartitionTvaParCumulTest 3/3 ; Filament/VentesEnAttenteApercuTest 3/5 ; Authorization/AutorisationsDesRolesTest 2/9 ; Authorization/AvoirPolicyTest 2/5 ; Authorization/PaiementPolicyTest 2/7 ; Facturation/CorrectionParAvoirTest 2/2 ; Facturation/FactureBrouillonSuppressionTest 2/2 ; Filament/ActionsForgeesTest 2/2 ; Filament/ProduitResourceTest 2/33 ; Filament/SoumissionEReportingResourceTest 2/3 ; Societe/ExpertComptableTest 2/12 ; Societe/MagasinResourceTest 2/37 ; Achats/AvoirFournisseurActionsTest 1/18 ; Authorization/DevisPolicyTest 1/9 ; Facturation/FermerSaCaisseActionTest 1/13 ; Facturation/ModifierDevisActionTest 1/7 ; Facturation/RemisesEtPrixTest 1/14 ; Filament/AvoirFournisseurResourceTest 1/3 ; Societe/CreerEtablissementActionTest 1/21 ; Societe/IdentiteSocieteTest 1/19 ; Societe/VerificationSocieteTest 1/41 ; Tenancy/HistoriqueAuNomFigeTest 1/4 ; Tenancy/TracabiliteDesVentesTest 1/8.

### A.2 Passage e : ce qui reste avec le repli de `auteur()` sur un responsable (441 méthodes, 591 cas, 56 fichiers)

Filament/FactureResourceTest 73 ; Filament/CaisseTest 65 ; Filament/PaiementResourceTest 30 ; Filament/AvoirResourceTest 28 ; Facturation/PreuvesOperationTest 21 ; Facturation/BordereauDetaxeTest 18 ; Filament/ClotureCaisseResourceTest 15 ; Tenancy/MagasinEnLectureSeuleTest 15 ; Filament/EnvoieLesDocumentsTest 12 ; Filament/FacturesAEncaisserTest 11 ; Societe/MagasinDeDemonstrationTest 11 ; Filament/EnregistrementFiscalResourceTest 9 ; Facturation/GenererSoumissionEReportingActionTest 8 ; Facturation/PolitiqueDArrondiTest 8 ; Filament/FacturesApercuWidgetTest 8 ; Stock/VenteSansStockTest 7 ; CaisseSecurisee/JournalEvenementsTest 6 ; CaisseSecurisee/VerificateurIntegriteTest 6 ; Societe/FacturesAEncaisserDeLaSocieteTest 6 ; Authorization/ControleDesDroitsTest 5 ; CaisseSecurisee/EmissionSigneeTest 5 ; CaisseSecurisee/VerifierIntegriteActionTest 5 ; Facturation/ClotureAutomatiqueCaisseTest 5 ; Filament/ClientResourceTest 5 ; Filament/DevisResourceTest 5 ; Authorization/ActionsHorsEcranTest 4 ; Facturation/FacturePolicyTest 4 ; Authorization/LecteurDesDroitsTest 3 ; Facturation/EnvoyerDocumentJobTest 3 ; Filament/VentesEnAttenteApercuTest 3 ; Authorization/AutorisationsDesRolesTest 2 ; Authorization/AvoirPolicyTest 2 ; Authorization/PaiementPolicyTest 2 ; CaisseSecurisee/InalterabiliteTest 2 ; CaisseSecurisee/VerifierIntegriteFiscaleTest 2 ; Filament/ActionsForgeesTest 2 ; Filament/ProduitResourceTest 2 ; Filament/SoumissionEReportingResourceTest 2 ; Societe/ExpertComptableTest 2 ; Societe/MagasinResourceTest 2 ; Societe/ParametresFiscauxSocieteTest 2 ; Achats/FactureAchatActionsTest 1 ; Facturation/ActionsUniquesTest 1 ; Facturation/CloturerCaisseActionTest 1 ; Facturation/FactureBrouillonSuppressionTest 1 ; Facturation/FactureNeSePasSupprimeTest 1 ; Facturation/FermerSaCaisseActionTest 1 ; Facturation/RemisesEtPrixTest 1 ; Filament/AvoirFournisseurResourceTest 1 ; Societe/CreerEtablissementActionTest 1 ; Societe/PorteDeValidationTest 1 ; Societe/VerificationSocieteTest 1 ; Tenancy/HistoriqueAuNomFigeTest 1 ; Tenancy/IsolationDesSocietesTest 1 ; Tenancy/TenantIsolationTest 1 ; Tenancy/TracabiliteDesVentesTest 1.

### A.3 Passage d : supplément si l'avoir et le devis sont des ventes (Q-V1 (b)) : +81 méthodes

Filament/DevisResourceTest 5 → 30 ; Authorization/DevisPolicyTest 1 → 9 ; Facturation/DevisConversionTest 6 → 14 ; Facturation/ModifierDevisActionTest 1 → 7 ; Facturation/ExpirerDevisTest 0 → 6 ; Facturation/DevisPdfGeneratorTest 0 → 5 ; Facturation/EnvoyerDocumentJobTest 21 → 25 ; Societe/MagasinResourceTest 2 → 5 ; Facturation/FranchiseEnBaseTest 11 → 14 ; Filament/EnvoieLesDocumentsTest 12 → 14 ; Facturation/EnvoyerDocumentActionTest 21 → 23 ; Facturation/NatureOperationTest 28 → 30 ; Catalogue/ProduitTest 0 → 1 ; Facturation/AvoirActionsTest 36 → 37 ; Facturation/EmetteurFigeTest 10 → 11 ; Societe/PorteDeValidationTest 4 → 5 ; Filament/AvoirResourceTest 28 → 29 ; Tenancy/MagasinEnLectureSeuleTest 15 → 16 ; CaisseSecurisee/EmissionSigneeTest 20 → 21.
