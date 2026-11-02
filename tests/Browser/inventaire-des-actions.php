<?php

/*
 * L'inventaire des actions de l'interface (décision de l'éditeur du
 * 01/11/2026 : « à chaque clic il y a un comportement, donc quelque chose
 * qui doit se finir comme prévu »).
 *
 * Pour chaque fichier qui déclare une action de l'interface, chaque action
 * telle que la lecture du code la nomme (voir
 * Tests\Browser\Support\ActionsDeLInterface : « page », « action:nom »,
 * « filtre:nom », « onglet:nom », « champ:nom », « repeteur:nom »,
 * « recherche », « widget », « composant », « menu:libellé », « clic:méthode »,
 * « page publique:adresse », « formulaire:adresse ») :
 * - « lot » : le lot de la série des tests en navigateur qui écrit, pour
 *   chaque rôle qui la voit, le test qui clique sur cette action et vérifie
 *   qu'elle se termine comme prévu (NAV-2 : ventes du magasin ; NAV-3 :
 *   catalogue, achats, stock, équipe et réglages du magasin ; NAV-4 :
 *   espace de la société et pages publiques ; NAV-5 : espace du cabinet) ;
 * - « tests » : les tests en navigateur qui la couvrent déjà, chacun nommé
 *   « chemin du fichier de test › nom du test » : un balayage l'ouvre (une
 *   fenêtre ouverte puis fermée, une page, un filtre, un onglet), un
 *   parcours l'exécute jusqu'au bout.
 *
 * InventaireDesActionsTest échoue si une action du code manque ici, si une
 * entrée ne correspond plus à aucune action du code, ou si un test cité
 * n'existe pas ; chaque balayage échoue s'il n'ouvre pas une action que
 * cet inventaire dit couverte par lui. Chaque lot qui crée ou change une
 * action met cet inventaire à jour dans le même commit.
 */

return [
    'app/Filament/App/Livewire/SelecteurDeClients.php' => [
        'action:nouveauClient' => ['lot' => 'NAV-2', 'tests' => []],
        'composant' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le responsable fait un devis puis une facture avec la loupe des clients et « Ajouter un produit », l’émet, l’encaisse et la crédite par un avoir',
        ]],
        'filtre:avec_credit' => ['lot' => 'NAV-2', 'tests' => []],
        'filtre:type_client' => ['lot' => 'NAV-2', 'tests' => []],
        'recherche' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Livewire/SelecteurDeProduits.php' => [
        'composant' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le responsable fait un devis puis une facture avec la loupe des clients et « Ajouter un produit », l’émet, l’encaisse et la crédite par un avoir',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le vendeur vend à la caisse au client comptoir et encaisse en espèces et par carte',
        ]],
        'filtre:en_stock' => ['lot' => 'NAV-2', 'tests' => []],
        'filtre:hors_stock' => ['lot' => 'NAV-2', 'tests' => []],
        'recherche' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Pages/Auth/ChoisirSonMotDePasse.php' => [
        'action:choisir' => ['lot' => 'NAV-4', 'tests' => []],
        'page' => ['lot' => 'NAV-4', 'tests' => []],
    ],
    'app/Filament/App/Pages/Auth/Connexion.php' => [
        'page' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/AidesCommunesTest.php › le propriétaire se connecte par son adresse de connexion',
            'tests/Browser/AidesCommunesTest.php › le responsable se connecte par son identifiant et arrive au tableau de bord de son magasin',
            'tests/Browser/AidesCommunesTest.php › un vendeur dont le mot de passe est encore son identifiant se connecte depuis un poste reconnu',
            'tests/Browser/Parcours/InscriptionDUneSocieteTest.php › une société s’inscrit, confirme son lien, saisit le code de son courrier et crée son premier magasin',
        ]],
    ],
    'app/Filament/App/Pages/Auth/DemandeDeLien.php' => [
        'page' => ['lot' => 'NAV-4', 'tests' => []],
    ],
    'app/Filament/App/Pages/Auth/InscriptionSociete.php' => [
        'champ:type_activite' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Parcours/InscriptionDUneSocieteTest.php › une société s’inscrit, confirme son lien, saisit le code de son courrier et crée son premier magasin',
        ]],
        'page' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Parcours/InscriptionDUneSocieteTest.php › une société s’inscrit, confirme son lien, saisit le code de son courrier et crée son premier magasin',
        ]],
    ],
    'app/Filament/App/Pages/Auth/ModifierProfil.php' => [
        'champ:password' => ['lot' => 'NAV-3', 'tests' => []],
        'page' => ['lot' => 'NAV-3', 'tests' => []],
    ],
    'app/Filament/App/Pages/Auth/PageDuLien.php' => [
        'action:choisir' => ['lot' => 'NAV-4', 'tests' => []],
        'page' => ['lot' => 'NAV-4', 'tests' => []],
    ],
    'app/Filament/App/Pages/Caisse.php' => [
        'action:abandonnerLaVente' => ['lot' => 'NAV-2', 'tests' => []],
        'action:confirmerLaVenteSansStock' => ['lot' => 'NAV-2', 'tests' => []],
        'action:retourDUnTicket' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'champ:client_id' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le vendeur vend à la caisse au client comptoir et encaisse en espèces et par carte',
        ]],
    ],
    'app/Filament/App/Pages/CoordonneesMagasin.php' => [
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Pages/ParametresFactures.php' => [
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Pages/ParametresPrixDeVente.php' => [
        'champ:actif' => ['lot' => 'NAV-3', 'tests' => []],
        'champ:essai_prix_achat_ht' => ['lot' => 'NAV-3', 'tests' => []],
        'champ:essai_taux_tva' => ['lot' => 'NAV-3', 'tests' => []],
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'repeteur:paliers' => ['lot' => 'NAV-3', 'tests' => []],
    ],
    'app/Filament/App/Pages/ParametresRelances.php' => [
        'champ:actif' => ['lot' => 'NAV-3', 'tests' => []],
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'repeteur:delais' => ['lot' => 'NAV-3', 'tests' => []],
    ],
    'app/Filament/App/Resources/AvoirFournisseurResource.php' => [
        'action:view' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'filtre:source' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/AvoirFournisseurResource/Pages/ListAvoirsFournisseur.php' => [
        'action:creer_avoir_fournisseur' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/AvoirFournisseurResource/Pages/ViewAvoirFournisseur.php' => [
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/AvoirResource.php' => [
        'action:chercher' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:envoyer_par_email' => ['lot' => 'NAV-2', 'tests' => []],
        'action:telecharger_pdf' => ['lot' => 'NAV-2', 'tests' => []],
        'action:view' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
        ]],
        'repeteur:lignes' => ['lot' => 'NAV-2', 'tests' => []],
    ],
    'app/Filament/App/Resources/AvoirResource/Pages/CreateAvoir.php' => [
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le responsable fait un devis puis une facture avec la loupe des clients et « Ajouter un produit », l’émet, l’encaisse et la crédite par un avoir',
        ]],
    ],
    'app/Filament/App/Resources/AvoirResource/Pages/ListAvoirs.php' => [
        'action:create' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/AvoirResource/Pages/ViewAvoir.php' => [
        'action:envoyer_par_email' => ['lot' => 'NAV-2', 'tests' => []],
        'action:telecharger_pdf' => ['lot' => 'NAV-2', 'tests' => []],
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ClientResource.php' => [
        'action:chercherUnClient' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le responsable fait un devis puis une facture avec la loupe des clients et « Ajouter un produit », l’émet, l’encaisse et la crédite par un avoir',
        ]],
        'action:edit' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:verifier_sur_vies' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:view' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'champ:etabli_en_france' => ['lot' => 'NAV-3', 'tests' => []],
        'champ:pays' => ['lot' => 'NAV-3', 'tests' => []],
        'champ:type_client' => ['lot' => 'NAV-3', 'tests' => []],
        'filtre:type_client' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ClientResource/Pages/CreateClient.php' => [
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ClientResource/Pages/EditClient.php' => [
        'action:verifier_sur_vies' => ['lot' => 'NAV-3', 'tests' => []],
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ClientResource/Pages/ListClients.php' => [
        'action:create' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ClientResource/Pages/ViewClient.php' => [
        'action:edit' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:verifier_sur_vies' => ['lot' => 'NAV-3', 'tests' => []],
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ClotureCaissePersonnelleResource/Pages/ListClotureCaissePersonnelles.php' => [
        'action:fermer_la_caisse_d_un_vendeur' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:fermer_ma_caisse' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'champ:vendeur_id' => ['lot' => 'NAV-2', 'tests' => []],
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ClotureCaisseResource.php' => [
        'action:view' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ClotureCaisseResource/Pages/ListClotureCaisses.php' => [
        'action:cloturer' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ClotureCaisseResource/Pages/ViewClotureCaisse.php' => [
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ClotureCaisseResource/Widgets/CaissesPersonnellesOuvertesApercu.php' => [
        'widget' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ClotureCaisseResource/Widgets/VentesEnAttenteApercu.php' => [
        'widget' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/DevisResource.php' => [
        'action:accepter' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:convertir' => ['lot' => 'NAV-2', 'tests' => []],
        'action:edit' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:envoyer' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:envoyer_par_email' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:refuser' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:telecharger_pdf' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:view' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'champ:client_id' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'filtre:statut' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/DevisResource/Pages/CreateDevis.php' => [
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le responsable fait un devis puis une facture avec la loupe des clients et « Ajouter un produit », l’émet, l’encaisse et la crédite par un avoir',
        ]],
    ],
    'app/Filament/App/Resources/DevisResource/Pages/EditDevis.php' => [
        'action:delete' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/DevisResource/Pages/ListDevis.php' => [
        'action:create' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/DevisResource/Pages/ViewDevis.php' => [
        'action:envoyer_par_email' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:telecharger_pdf' => ['lot' => 'NAV-2', 'tests' => []],
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/EReportingResource.php' => [
        'action:view' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/EReportingResource/Pages/ListEReporting.php' => [
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/EReportingResource/Pages/ViewEReporting.php' => [
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/EnregistrementFiscalResource.php' => [
        'action:view' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'filtre:chaine' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'filtre:code' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'filtre:periode' => ['lot' => 'NAV-2', 'tests' => []],
    ],
    'app/Filament/App/Resources/EnregistrementFiscalResource/Pages/ListEnregistrementsFiscaux.php' => [
        'action:exporter_journal' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:verifier_integrite' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/EnregistrementFiscalResource/Pages/ViewEnregistrementFiscal.php' => [
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/EnregistrementFiscalResource/Widgets/ConformiteApercu.php' => [
        'widget' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/EquipeDuMagasinResource.php' => [
        'action:edit' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:nommer_responsable' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:reactiver' => ['lot' => 'NAV-3', 'tests' => []],
        'action:redonner_l_acces' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:retirer_du_magasin' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:suspendre' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'champ:mode' => ['lot' => 'NAV-3', 'tests' => []],
        'champ:role' => ['lot' => 'NAV-3', 'tests' => []],
        'filtre:mot_de_passe_identifiant' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'filtre:role' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/EquipeDuMagasinResource/Pages/EditMembreDuMagasin.php' => [
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/EquipeDuMagasinResource/Pages/ListEquipeDuMagasin.php' => [
        'action:creerUnCompte' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/FactureAchatResource.php' => [
        'action:avoir_fournisseur' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:delete' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:edit' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:valider' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:view' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'champ:facture_achat_id' => ['lot' => 'NAV-3', 'tests' => []],
        'champ:fournisseur_id' => ['lot' => 'NAV-3', 'tests' => []],
        'champ:numero_fournisseur' => ['lot' => 'NAV-3', 'tests' => []],
        'filtre:source' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'filtre:statut' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
        ]],
        'repeteur:lignes' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/FactureAchatResource/Pages/CreateFactureAchat.php' => [
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/FactureAchatResource/Pages/EditFactureAchat.php' => [
        'action:delete' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/FactureAchatResource/Pages/ListFacturesAchat.php' => [
        'action:create' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/FactureAchatResource/Pages/ViewFactureAchat.php' => [
        'action:creer_avoir_fournisseur' => ['lot' => 'NAV-3', 'tests' => []],
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/FactureResource.php' => [
        'action:avoir' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:delete' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:edit' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:emettre' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le responsable fait un devis puis une facture avec la loupe des clients et « Ajouter un produit », l’émet, l’encaisse et la crédite par un avoir',
        ]],
        'action:encaisser' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:envoyer_par_email' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:relancer' => ['lot' => 'NAV-2', 'tests' => []],
        'action:retourner_ce_produit' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:telecharger_pdf' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:view' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'champ:client_id' => ['lot' => 'NAV-2', 'tests' => []],
        'filtre:a_encaisser' => ['lot' => 'NAV-2', 'tests' => []],
        'filtre:preuves_a_fournir' => ['lot' => 'NAV-2', 'tests' => []],
        'filtre:statut' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'repeteur:lignes' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/FactureResource/Pages/CreateFacture.php' => [
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le responsable fait un devis puis une facture avec la loupe des clients et « Ajouter un produit », l’émet, l’encaisse et la crédite par un avoir',
        ]],
    ],
    'app/Filament/App/Resources/FactureResource/Pages/EditFacture.php' => [
        'action:delete' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/FactureResource/Pages/ListFactures.php' => [
        'action:create' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/FactureResource/Pages/ViewFacture.php' => [
        'action:ajouter_preuve' => ['lot' => 'NAV-2', 'tests' => []],
        'action:avoir' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le responsable fait un devis puis une facture avec la loupe des clients et « Ajouter un produit », l’émet, l’encaisse et la crédite par un avoir',
        ]],
        'action:emettreLaFactureRectificative' => ['lot' => 'NAV-2', 'tests' => []],
        'action:encaisser' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le responsable fait un devis puis une facture avec la loupe des clients et « Ajouter un produit », l’émet, l’encaisse et la crédite par un avoir',
        ]],
        'action:envoyer_par_email' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:faireLAvoirDeRegularisation' => ['lot' => 'NAV-2', 'tests' => []],
        'action:noter_bordereau_detaxe' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:relancer' => ['lot' => 'NAV-2', 'tests' => []],
        'action:telecharger_pdf' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le responsable fait un devis puis une facture avec la loupe des clients et « Ajouter un produit », l’émet, l’encaisse et la crédite par un avoir',
        ]],
    ],
    'app/Filament/App/Resources/FournisseurResource.php' => [
        'action:edit' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/FournisseurResource/Pages/CreateFournisseur.php' => [
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/FournisseurResource/Pages/EditFournisseur.php' => [
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/FournisseurResource/Pages/ListFournisseurs.php' => [
        'action:create' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/InventaireOuvertureResource.php' => [
        'action:delete' => ['lot' => 'NAV-3', 'tests' => []],
        'action:edit' => ['lot' => 'NAV-3', 'tests' => []],
        'action:valider' => ['lot' => 'NAV-3', 'tests' => []],
        'action:view' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'champ:produit_id' => ['lot' => 'NAV-3', 'tests' => []],
        'recherche' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'repeteur:lignes' => ['lot' => 'NAV-3', 'tests' => []],
    ],
    'app/Filament/App/Resources/InventaireOuvertureResource/Pages/CreateInventaireOuverture.php' => [
        'page' => ['lot' => 'NAV-3', 'tests' => []],
    ],
    'app/Filament/App/Resources/InventaireOuvertureResource/Pages/EditInventaireOuverture.php' => [
        'action:delete' => ['lot' => 'NAV-3', 'tests' => []],
        'action:valider' => ['lot' => 'NAV-3', 'tests' => []],
        'page' => ['lot' => 'NAV-3', 'tests' => []],
    ],
    'app/Filament/App/Resources/InventaireOuvertureResource/Pages/ListInventairesOuverture.php' => [
        'action:create' => ['lot' => 'NAV-3', 'tests' => []],
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/InventaireOuvertureResource/Pages/ViewInventaireOuverture.php' => [
        'action:edit' => ['lot' => 'NAV-3', 'tests' => []],
        'action:valider' => ['lot' => 'NAV-3', 'tests' => []],
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/PaiementResource.php' => [
        'action:contre_passer' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'champ:client_id' => ['lot' => 'NAV-2', 'tests' => []],
        'champ:facture_id' => ['lot' => 'NAV-2', 'tests' => []],
        'filtre:mode_paiement' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
        ]],
        'repeteur:allocations' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/PaiementResource/Pages/CreatePaiement.php' => [
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/PaiementResource/Pages/ListPaiements.php' => [
        'action:create' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/PosteReconnuResource.php' => [
        'action:retirer' => ['lot' => 'NAV-3', 'tests' => []],
        'recherche' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/PosteReconnuResource/Pages/ListPostesReconnus.php' => [
        'action:reconnaitre' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/AidesCommunesTest.php › un vendeur dont le mot de passe est encore son identifiant se connecte depuis un poste reconnu',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/AidesCommunesTest.php › un vendeur dont le mot de passe est encore son identifiant se connecte depuis un poste reconnu',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ProduitResource.php' => [
        'action:edit' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'champ:suivi_stock' => ['lot' => 'NAV-3', 'tests' => []],
        'filtre:fournisseur_id' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'filtre:statut_stock' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'filtre:suivi_stock' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ProduitResource/Pages/EditProduit.php' => [
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ProduitResource/Pages/ListProduits.php' => [
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ProduitResource/Widgets/ValeurStockApercu.php' => [
        'widget' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ServiceResource.php' => [
        'action:edit' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'filtre:actif' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Resources/ServiceResource/Pages/ManageServices.php' => [
        'action:create' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'page' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Widgets/BienvenueApercu.php' => [
        'widget' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Widgets/FacturesAEncaisserApercu.php' => [
        'widget' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Widgets/FacturesApercu.php' => [
        'widget' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/App/Widgets/StockNegatifApercu.php' => [
        'widget' => ['lot' => 'NAV-3', 'tests' => []],
    ],
    'app/Filament/Cabinet/Pages/IdentiteDuCabinet.php' => [
        'page' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le collaborateur ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
    ],
    'app/Filament/Cabinet/Pages/InvitationsEtDemandes.php' => [
        'action:accepterLaDemande' => ['lot' => 'NAV-5', 'tests' => []],
        'action:inviter' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
        'action:refuserLaDemande' => ['lot' => 'NAV-5', 'tests' => []],
        'action:retirerLInvitation' => ['lot' => 'NAV-5', 'tests' => []],
        'action:retirerLaDemande' => ['lot' => 'NAV-5', 'tests' => []],
        'page' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
    ],
    'app/Filament/Cabinet/Pages/TableauDeBordDuCabinet.php' => [
        'action:mettreFinAuLien' => ['lot' => 'NAV-5', 'tests' => []],
        'page' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le collaborateur ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
    ],
    'app/Filament/Cabinet/Pages/VerificationDuCabinet.php' => [
        'action:verifierLOrdre' => ['lot' => 'NAV-5', 'tests' => []],
        'page' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le collaborateur ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
    ],
    'app/Filament/Cabinet/Resources/ClientDuCabinetResource.php' => [
        'action:confier' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
        'action:mettreFin' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
        'action:view' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le collaborateur ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-5', 'tests' => []],
    ],
    'app/Filament/Cabinet/Resources/ClientDuCabinetResource/Pages/FicheDuClient.php' => [
        'action:mettreFin' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
        'page' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le collaborateur ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
    ],
    'app/Filament/Cabinet/Resources/ClientDuCabinetResource/Pages/ListClientsDuCabinet.php' => [
        'page' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le collaborateur ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
    ],
    'app/Filament/Cabinet/Resources/CollaborateurResource.php' => [
        'action:view' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-5', 'tests' => []],
    ],
    'app/Filament/Cabinet/Resources/CollaborateurResource/Pages/FicheDuCollaborateur.php' => [
        'action:annulerLaTransmission' => ['lot' => 'NAV-5', 'tests' => []],
        'action:confierDesClients' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
        'action:reactiver' => ['lot' => 'NAV-5', 'tests' => []],
        'action:redonnerLAcces' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
        'action:reintegrer' => ['lot' => 'NAV-5', 'tests' => []],
        'action:renvoyerLeLienDeTransmission' => ['lot' => 'NAV-5', 'tests' => []],
        'action:retirerDeLaSociete' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
        'action:suspendre' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
        'action:transmettreLaPropriete' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
        'page' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
    ],
    'app/Filament/Cabinet/Resources/CollaborateurResource/Pages/ListCollaborateurs.php' => [
        'action:creerUnCollaborateur' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
        'page' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
    ],
    'app/Filament/Cabinet/Resources/JournalAuditDuCabinetResource/Pages/ListEntreesDuJournal.php' => [
        'page' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
    ],
    'app/Filament/Cabinet/Resources/JournalAuditDuCabinetResource/Pages/VoirEntreeDuJournal.php' => [
        'page' => ['lot' => 'NAV-5', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
    ],
    'app/Filament/Concerns/AfficheLeJournalDAudit.php' => [
        'action:view' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
        'filtre:action' => ['lot' => 'NAV-4', 'tests' => []],
        'filtre:categorie' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
        'filtre:origine' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDuCabinetTest.php › le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur',
        ]],
        'filtre:periode' => ['lot' => 'NAV-4', 'tests' => []],
        'filtre:portee' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-4', 'tests' => []],
    ],
    'app/Filament/Concerns/AjouteDesLignesDAchat.php' => [
        'action:ajouterUnProduit' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:modifierLaLigne' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'champ:origine' => ['lot' => 'NAV-3', 'tests' => []],
        'champ:prix_unitaire_ht' => ['lot' => 'NAV-3', 'tests' => []],
        'champ:produit_id' => ['lot' => 'NAV-3', 'tests' => []],
        'champ:remise_pourcentage' => ['lot' => 'NAV-3', 'tests' => []],
        'repeteur:lignes' => ['lot' => 'NAV-3', 'tests' => []],
    ],
    'app/Filament/Concerns/AjouteDesLignesDeVente.php' => [
        'action:ajouterUnProduit' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le responsable fait un devis puis une facture avec la loupe des clients et « Ajouter un produit », l’émet, l’encaisse et la crédite par un avoir',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le vendeur vend à la caisse au client comptoir et encaisse en espèces et par carte',
        ]],
        'action:ajouterUnService' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'champ:nouveau_service' => ['lot' => 'NAV-2', 'tests' => []],
        'champ:prix_unitaire_ttc' => ['lot' => 'NAV-2', 'tests' => []],
        'champ:produit_id' => ['lot' => 'NAV-2', 'tests' => []],
        'champ:quantite' => ['lot' => 'NAV-2', 'tests' => []],
        'champ:recherche' => ['lot' => 'NAV-2', 'tests' => []],
        'repeteur:lignes' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le responsable fait un devis puis une facture avec la loupe des clients et « Ajouter un produit », l’émet, l’encaisse et la crédite par un avoir',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le vendeur vend à la caisse au client comptoir et encaisse en espèces et par carte',
        ]],
    ],
    'app/Filament/Concerns/ArchiveLaFiche.php' => [
        'action:archiver' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
        ]],
        'action:restore' => ['lot' => 'NAV-3', 'tests' => []],
        'filtre:trashed' => ['lot' => 'NAV-3', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › l’observateur ouvre chaque page et chaque action du magasin sans erreur',
        ]],
    ],
    'app/Filament/Concerns/ChoisitLaNatureDeLOperation.php' => [
        'champ:nature_operation' => ['lot' => 'NAV-2', 'tests' => []],
    ],
    'app/Filament/Concerns/GereLesComptes.php' => [
        'champ:mode' => ['lot' => 'NAV-3', 'tests' => []],
        'champ:name' => ['lot' => 'NAV-3', 'tests' => []],
    ],
    'app/Filament/Concerns/MontreLeCompteCree.php' => [
        'action:compteCree' => ['lot' => 'NAV-3', 'tests' => []],
    ],
    'app/Filament/Concerns/SaisitUnReglementEnParts.php' => [
        'champ:bon' => ['lot' => 'NAV-2', 'tests' => []],
        'champ:mode' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le responsable fait un devis puis une facture avec la loupe des clients et « Ajouter un produit », l’émet, l’encaisse et la crédite par un avoir',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le vendeur vend à la caisse au client comptoir et encaisse en espèces et par carte',
        ]],
        'champ:montant' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le vendeur vend à la caisse au client comptoir et encaisse en espèces et par carte',
        ]],
        'repeteur:parts' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Balayage/BalayageDuMagasinParLaSocieteTest.php › l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParLeCabinetEtLEntrainementTest.php › le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le responsable ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Balayage/BalayageDuMagasinParSonEquipeTest.php › le vendeur ouvre chaque page et chaque action du magasin sans erreur',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le responsable fait un devis puis une facture avec la loupe des clients et « Ajouter un produit », l’émet, l’encaisse et la crédite par un avoir',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le vendeur vend à la caisse au client comptoir et encaisse en espèces et par carte',
        ]],
    ],
    'app/Filament/Concerns/SupprimeEnMasseLigneParLigne.php' => [
        'action:archiver' => ['lot' => 'NAV-3', 'tests' => []],
        'action:delete' => ['lot' => 'NAV-3', 'tests' => []],
    ],
    'app/Filament/Societe/Pages/AccepterLaTransmission.php' => [
        'action:accepter' => ['lot' => 'NAV-4', 'tests' => []],
        'page' => ['lot' => 'NAV-4', 'tests' => []],
    ],
    'app/Filament/Societe/Pages/CabinetComptable.php' => [
        'action:demanderLeLien' => ['lot' => 'NAV-4', 'tests' => []],
        'action:enregistrerLesAutorisations' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
        ]],
        'action:inviterSonExpert' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
        ]],
        'action:mettreFin' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
        ]],
        'action:retirerLaDemande' => ['lot' => 'NAV-4', 'tests' => []],
        'page' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
        ]],
    ],
    'app/Filament/Societe/Pages/IdentiteSociete.php' => [
        'champ:forme_juridique' => ['lot' => 'NAV-4', 'tests' => []],
        'page' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
    ],
    'app/Filament/Societe/Pages/LesCaisses.php' => [
        'page' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur sans autorisation de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
    ],
    'app/Filament/Societe/Pages/ParametresFiscauxSociete.php' => [
        'page' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
    ],
    'app/Filament/Societe/Pages/RattachementDUnCabinet.php' => [
        'action:accepterEnAjustant' => ['lot' => 'NAV-4', 'tests' => []],
        'action:accepterParDefaut' => ['lot' => 'NAV-4', 'tests' => []],
        'action:refuser' => ['lot' => 'NAV-4', 'tests' => []],
        'page' => ['lot' => 'NAV-4', 'tests' => []],
    ],
    'app/Filament/Societe/Pages/TableauDeBord.php' => [
        'action:ouvrirLaDemonstration' => ['lot' => 'NAV-4', 'tests' => []],
        'page' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur sans autorisation de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
    ],
    'app/Filament/Societe/Pages/VerificationSociete.php' => [
        'action:demanderUnNouveauCourrier' => ['lot' => 'NAV-4', 'tests' => []],
        'action:deposerUnePiece' => ['lot' => 'NAV-4', 'tests' => []],
        'action:reverifier' => ['lot' => 'NAV-4', 'tests' => []],
        'page' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur sans autorisation de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
    ],
    'app/Filament/Societe/Resources/JournalAuditResource/Pages/ListEntreesAudit.php' => [
        'page' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
        ]],
    ],
    'app/Filament/Societe/Resources/JournalAuditResource/Pages/VoirEntreeAudit.php' => [
        'page' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
        ]],
    ],
    'app/Filament/Societe/Resources/MagasinResource.php' => [
        'action:edit' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-4', 'tests' => []],
    ],
    'app/Filament/Societe/Resources/MagasinResource/Pages/CreerMagasin.php' => [
        'champ:etablissement' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Parcours/InscriptionDUneSocieteTest.php › une société s’inscrit, confirme son lien, saisit le code de son courrier et crée son premier magasin',
        ]],
        'page' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
            'tests/Browser/Parcours/InscriptionDUneSocieteTest.php › une société s’inscrit, confirme son lien, saisit le code de son courrier et crée son premier magasin',
        ]],
    ],
    'app/Filament/Societe/Resources/MagasinResource/Pages/ListMagasins.php' => [
        'action:create' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
        'page' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur sans autorisation de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
    ],
    'app/Filament/Societe/Resources/MagasinResource/Pages/ModifierMagasin.php' => [
        'action:ceder' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
        ]],
        'action:changerLeSiret' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
        'action:choisirCommePrincipal' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
        'action:fermer' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
        'action:retirerCommePrincipal' => ['lot' => 'NAV-4', 'tests' => []],
        'action:rouvrir' => ['lot' => 'NAV-4', 'tests' => []],
        'page' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
    ],
    'app/Filament/Societe/Resources/MembreResource.php' => [
        'action:view' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
        'champ:portee' => ['lot' => 'NAV-4', 'tests' => []],
        'champ:role' => ['lot' => 'NAV-4', 'tests' => []],
        'filtre:mot_de_passe_identifiant' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
        ]],
        'filtre:role_societe' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
        'filtre:statut' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
        'recherche' => ['lot' => 'NAV-4', 'tests' => []],
    ],
    'app/Filament/Societe/Resources/MembreResource/Pages/FicheDuMembre.php' => [
        'action:afficherLIdentifiant' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
        'action:annulerLaNomination' => ['lot' => 'NAV-4', 'tests' => []],
        'action:annulerLaTransmission' => ['lot' => 'NAV-4', 'tests' => []],
        'action:changerLeRoleDeSociete' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
        ]],
        'action:modifierLAdministrateur' => ['lot' => 'NAV-4', 'tests' => []],
        'action:modifierSonRole' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
        ]],
        'action:reactiver' => ['lot' => 'NAV-4', 'tests' => []],
        'action:redonnerLAcces' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
        'action:reintegrer' => ['lot' => 'NAV-4', 'tests' => []],
        'action:renvoyerLeLienDeTransmission' => ['lot' => 'NAV-4', 'tests' => []],
        'action:retirerDeLaSociete' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
        ]],
        'action:suspendre' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
        ]],
        'action:transmettreLaPropriete' => ['lot' => 'NAV-4', 'tests' => []],
        'champ:mode' => ['lot' => 'NAV-4', 'tests' => []],
        'champ:role_societe' => ['lot' => 'NAV-4', 'tests' => []],
        'page' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
    ],
    'app/Filament/Societe/Resources/MembreResource/Pages/ListMembres.php' => [
        'action:creerUnAdministrateur' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
        ]],
        'action:creerUnMembre' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
        ]],
        'action:nommerUnResponsable' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
        'champ:candidat' => ['lot' => 'NAV-4', 'tests' => []],
        'champ:compte' => ['lot' => 'NAV-4', 'tests' => []],
        'champ:magasin_id' => ['lot' => 'NAV-4', 'tests' => []],
        'champ:mode' => ['lot' => 'NAV-4', 'tests' => []],
        'page' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur',
            'tests/Browser/Balayage/BalayageDeLEspaceDeLaSocieteTest.php › l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur',
        ]],
    ],
    'app/Providers/Filament/AppPanelProvider.php' => [
        'menu:Administration de la société' => ['lot' => 'NAV-4', 'tests' => []],
        'menu:Espace du cabinet' => ['lot' => 'NAV-5', 'tests' => []],
    ],
    'app/Providers/Filament/SocietePanelProvider.php' => [
        'menu:Mes magasins' => ['lot' => 'NAV-4', 'tests' => []],
    ],
    'resources/views/filament/app/livewire/selecteur-de-produits.blade.php' => [
        'clic:ajouterLaSelection' => ['lot' => 'NAV-2', 'tests' => [
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le responsable fait un devis puis une facture avec la loupe des clients et « Ajouter un produit », l’émet, l’encaisse et la crédite par un avoir',
            'tests/Browser/Parcours/VentesDeBoutEnBoutTest.php › le vendeur vend à la caisse au client comptoir et encaisse en espèces et par carte',
        ]],
        'clic:retirer' => ['lot' => 'NAV-2', 'tests' => []],
        'clic:voirPlus' => ['lot' => 'NAV-2', 'tests' => []],
    ],
    'routes/web.php' => [
        'formulaire:/code' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Parcours/InscriptionDUneSocieteTest.php › une société s’inscrit, confirme son lien, saisit le code de son courrier et crée son premier magasin',
        ]],
        'formulaire:/code/{objet}' => ['lot' => 'NAV-4', 'tests' => []],
        'formulaire:/inscription/{jeton}' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Parcours/InscriptionDUneSocieteTest.php › une société s’inscrit, confirme son lien, saisit le code de son courrier et crée son premier magasin',
        ]],
        'formulaire:/invitation-cabinet/{jeton}' => ['lot' => 'NAV-4', 'tests' => []],
        'formulaire:/invitation-cabinet/{jeton}/refus' => ['lot' => 'NAV-4', 'tests' => []],
        'formulaire:/profil/adresse/{jeton}' => ['lot' => 'NAV-4', 'tests' => []],
        'page publique:/code/{objet}/{siren?}' => ['lot' => 'NAV-4', 'tests' => []],
        'page publique:/code/{siren?}' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Parcours/InscriptionDUneSocieteTest.php › une société s’inscrit, confirme son lien, saisit le code de son courrier et crée son premier magasin',
        ]],
        'page publique:/inscription/{jeton}' => ['lot' => 'NAV-4', 'tests' => [
            'tests/Browser/Parcours/InscriptionDUneSocieteTest.php › une société s’inscrit, confirme son lien, saisit le code de son courrier et crée son premier magasin',
        ]],
        'page publique:/invitation-cabinet/{jeton}' => ['lot' => 'NAV-4', 'tests' => []],
        'page publique:/profil/adresse/{jeton}' => ['lot' => 'NAV-4', 'tests' => []],
        'page publique:/rattachement-cabinet/{jeton}' => ['lot' => 'NAV-4', 'tests' => []],
    ],
];
