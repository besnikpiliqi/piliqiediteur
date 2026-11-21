<?php

/*
|--------------------------------------------------------------------------
| Le site de démonstration et ce que le site réel en connaît
|--------------------------------------------------------------------------
|
| Décision de l'éditeur du 15/11/2026 en soirée (lot DEMO-COPIE) : chaque
| société crée depuis son compte une copie de la base modèle de son type,
| servie par un site à part, même code, en mode « démonstration » (voir
| SiteDePiliqi). Les deux sites ne lisent jamais la base de l'autre : du
| site réel vers le site de démonstration ne passent que des messages
| signés (Ed25519) et le billet d'entrée du bouton du propriétaire ; le
| site réel ne lit du site de démonstration que son état général signé.
|
| Chaque clé vient de l'environnement de son serveur, jamais du dépôt :
| la clé privée des messages seulement sur le site réel, leurs clés
| publiques seulement sur le site de démonstration ; la clé privée de
| l'état publié seulement sur le site de démonstration, sa clé publique
| seulement sur le site réel (voir SiteDePiliqiServiceProvider).
|
*/

return [

    /*
     | Sur le site réel : l'adresse du site de démonstration
     | (DEMONSTRATION_URL, https://piliqi-demo.fr en production, son propre
     | domaine, jamais un sous-domaine du site réel ; http://localhost:8001
     | sur un poste). Vide : aucun site de démonstration n'est lancé, la
     | carte « Ma démonstration » le dit.
     */
    'url' => env('DEMONSTRATION_URL'),

    /*
     | Le nom du site de démonstration, destinataire de chaque message
     | signé, que le site de démonstration exige pour lui
     | (DEMONSTRATION_NOM_DU_SITE).
     */
    'nom_du_site' => env('DEMONSTRATION_NOM_DU_SITE', 'piliqi-demonstration'),

    /*
     | Sur le site de démonstration : l'origine exacte du site réel
     | (DEMONSTRATION_ORIGINE_DU_SITE_REEL, https://piliqi.fr en
     | production), seule page d'où le billet d'entrée est accepté.
     */
    'origine_du_site_reel' => env('DEMONSTRATION_ORIGINE_DU_SITE_REEL'),

    /*
     | Les clés des messages : sur le site réel, la clé privée Ed25519 en
     | base64 et son identifiant (DEMONSTRATION_MESSAGES_CLE_PRIVEE,
     | DEMONSTRATION_MESSAGES_CLE_ID) ; sur le site de démonstration, les
     | clés publiques par identifiant, en JSON {"identifiant": "base64"}
     | (DEMONSTRATION_MESSAGES_CLES_PUBLIQUES), pour tourner la clé sans
     | coupure.
     */
    'messages' => [
        'cle_privee' => env('DEMONSTRATION_MESSAGES_CLE_PRIVEE'),
        'cle_id' => env('DEMONSTRATION_MESSAGES_CLE_ID'),
        'cles_publiques' => json_decode((string) env('DEMONSTRATION_MESSAGES_CLES_PUBLIQUES', '{}'), true) ?: [],
    ],

    /*
     | Les clés de l'état publié du site de démonstration : sa clé privée
     | Ed25519 sur le site de démonstration (DEMONSTRATION_ETAT_CLE_PRIVEE),
     | sa clé publique sur le site réel (DEMONSTRATION_ETAT_CLE_PUBLIQUE).
     */
    'etat' => [
        'cle_privee' => env('DEMONSTRATION_ETAT_CLE_PRIVEE'),
        'cle_publique' => env('DEMONSTRATION_ETAT_CLE_PUBLIQUE'),
    ],

    /*
     | Sur le site de démonstration : le plafond des copies vivantes
     | (DEMONSTRATION_COPIES_MAX, 200 au départ), le nombre de copies créées
     | en même temps (DEMONSTRATION_COPIES_EN_MEME_TEMPS, 2) et la part du
     | plafond, en pour cent, réservée aux sociétés validées
     | (DEMONSTRATION_PART_DES_VALIDEES, 50).
     */
    'copies_max' => (int) env('DEMONSTRATION_COPIES_MAX', 200),

    'copies_en_meme_temps' => (int) env('DEMONSTRATION_COPIES_EN_MEME_TEMPS', 2),

    'part_des_validees' => (int) env('DEMONSTRATION_PART_DES_VALIDEES', 50),

    // La place d'une copie (C.16) : au-delà de ce multiple de la taille de
    // son modèle (base et dossier de fichiers), la copie passe en lecture
    // seule (demonstration:mesurer-les-copies, chaque heure).
    'facteur_de_place' => (int) env('DEMONSTRATION_FACTEUR_DE_PLACE', 3),

    // La place libre minimale du disque des copies, en pour cent (C.16) :
    // en dessous, plus aucune copie neuve ne se crée (« en attente d'une
    // place ») et l'état publié alerte l'éditeur. Les tests la mettent à 0
    // (phpunit.xml), pour ne pas dépendre du disque de la machine.
    'place_libre_minimale' => (int) env('DEMONSTRATION_PLACE_LIBRE_MINIMALE', 20),

    /*
     | Sur le site de démonstration : la stratégie de CREATE DATABASE …
     | TEMPLATE (DEMONSTRATION_STRATEGIE_DE_COPIE, FILE_COPY ou WAL_LOG ;
     | voir BasesDeLaDemonstration) et le dossier des fichiers des modèles,
     | des copies et des archives (storage/demonstration).
     */
    'strategie_de_copie' => env('DEMONSTRATION_STRATEGIE_DE_COPIE', 'FILE_COPY'),

    'dossier' => storage_path('demonstration'),

];
