<?php

/*
 * Les grandes parties du logiciel, pour la couverture du code et les
 * tests de mutation (voir tests/Qualite/qualite.php et seuils.json). Un
 * fichier de app/ appartient à la première partie dont un chemin commence
 * le sien (un fichier, un dossier terminé par « / », ou le début d'un nom
 * qui désigne une ressource et ses pages) ; ce qu'aucune ne désigne
 * appartient à « ecrans_et_socle ». Les trois premières sont les parties
 * sensibles de la couverture ; Infection teste les mutants des calculs et
 * des droits et de l'isolation, ceux de la caisse sécurisée à l'étape 3 de
 * la feuille de route (voir PARTIES_SENSIBLES dans qualite.php).
 */

return [
    'calculs' => [
        'app/Domain/Facturation/Services/CalculateurLigne.php',
        'app/Domain/Facturation/Services/DocumentChiffre.php',
        'app/Domain/Facturation/Services/ConstructeurLigneDocument.php',
        'app/Domain/Facturation/Services/DerogationsDePrix.php',
        'app/Domain/Facturation/Services/QuantiteEntiere.php',
        'app/Domain/Facturation/Services/SaisieDecimale.php',
        'app/Domain/Facturation/Services/PartDeReglement.php',
        'app/Domain/Facturation/Services/ReglementComptant.php',
        'app/Domain/Facturation/Services/ImputationDuCredit.php',
        'app/Domain/Facturation/Services/TerritoireFiscal.php',
        'app/Domain/Achats/Services/ChiffrageDUnAvoirFournisseur.php',
        'app/Domain/Achats/Services/ConstructeurLigneAchat.php',
        'app/Domain/Achats/Services/EcartsDUnTaux.php',
        'app/Domain/Achats/Services/MontantsDuFournisseur.php',
        'app/Domain/Achats/Services/TvaDuFournisseur.php',
        'app/Domain/Stock/Services/ConstructeurLigneInventaire.php',
        'app/Domain/Catalogue/Services/BaremePrixDeVente.php',
        'app/Domain/Catalogue/Services/MargeDuCatalogue.php',
    ],
    'caisse_securisee' => [
        'app/Domain/CaisseSecurisee/',
        'app/Domain/Calendrier/',
        'app/Domain/Facturation/Actions/CloturerCaisseAction.php',
        'app/Domain/Facturation/Actions/CloturerLesJourneesAction.php',
        'app/Domain/Facturation/Actions/FermerSaCaisseAction.php',
        'app/Domain/Facturation/Models/ClotureCaisse.php',
        'app/Domain/Facturation/Models/ClotureCaissePersonnelle.php',
        'app/Domain/Facturation/Services/ApercuEReporting.php',
        'app/Domain/Facturation/Services/DocumentNumberingService.php',
        'app/Domain/Facturation/Services/EReportingDesParticuliers.php',
        'app/Domain/Facturation/Services/JourneesDeCaisse.php',
        'app/Domain/Facturation/Services/RecapitulatifDeCloture.php',
        'app/Domain/Facturation/Services/VerificateurCaissesPersonnelles.php',
    ],
    'droits_et_isolation' => [
        'app/Domain/Authorization/',
        'app/Policies/',
        'app/Http/Middleware/',
        'app/Domain/Tenancy/Scopes/',
        'app/Domain/Tenancy/Concerns/BelongsToMagasin.php',
        'app/Domain/Tenancy/Concerns/BelongsToSociete.php',
        'app/Domain/Tenancy/Services/TenantContext.php',
        'app/Domain/Tenancy/Services/PorteeDesDroits.php',
        'app/Domain/Tenancy/Services/PorteeDesAdministrateurs.php',
        'app/Domain/Tenancy/Services/AffectationsDesMembres.php',
        'app/Domain/Cabinets/Services/AccesDesCabinets.php',
        'app/Models/User.php',
        'app/Domain/Tenancy/Support/GardesDesDroits.php',
        'app/Domain/Tenancy/Support/GardesDesTypesDActivite.php',
        'app/Domain/Cabinets/Support/GardesDesCabinets.php',
    ],
    'cabinets' => [
        'app/Domain/Cabinets/',
        'app/Filament/Cabinet/',
        'app/Integrations/Ordre/',
    ],
    'pages_publiques' => [
        'app/Http/',
        'app/Filament/App/Pages/Auth/',
    ],
    'facturation' => [
        'app/Domain/Facturation/',
        'app/Domain/Contacts/',
        'app/Integrations/Pdp/',
        'app/Integrations/Vies/',
        'app/Filament/App/Resources/AvoirResource',
        'app/Filament/App/Resources/ClientResource',
        'app/Filament/App/Resources/ClotureCaisse',
        'app/Filament/App/Resources/DevisResource',
        'app/Filament/App/Resources/EReportingResource',
        'app/Filament/App/Resources/FactureResource',
        'app/Filament/App/Resources/PaiementResource',
        'app/Filament/App/Pages/Caisse.php',
    ],
    'achats_et_stock' => [
        'app/Domain/Achats/',
        'app/Domain/Stock/',
        'app/Domain/Catalogue/',
        'app/Filament/App/Resources/AvoirFournisseurResource',
        'app/Filament/App/Resources/FactureAchatResource',
        'app/Filament/App/Resources/FournisseurResource',
        'app/Filament/App/Resources/InventaireOuvertureResource',
        'app/Filament/App/Resources/ProduitResource',
        'app/Filament/App/Resources/ServiceResource',
    ],
    'societe' => [
        'app/Domain/Tenancy/',
        'app/Filament/Societe/',
        'app/Console/',
        'app/Integrations/',
        'app/Models/',
    ],
];
