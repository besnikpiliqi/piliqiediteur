<?php

/*
|--------------------------------------------------------------------------
| Envois temporaires de Livewire
|--------------------------------------------------------------------------
|
| Seuls réglages de Livewire changés par PILIQI ; tous les autres restent
| ceux du paquet. Un fichier choisi dans un formulaire (pièce du
| fournisseur, pièce de la vérification de la société) part d'abord dans
| un envoi temporaire (décision de l'éditeur du 07/11/2026, lot FI) :
|
| - sur le disque privé « local » (storage/app/private), jamais servi tel
|   quel, dans le dossier livewire-tmp, effacé au bout de 24 heures ;
| - refusé par le serveur s'il n'est ni un PDF ni une photo JPEG ou PNG
|   (type lu dans son contenu) ou s'il dépasse 10 Mo, quoi que le
|   navigateur ait laissé passer ; l'action qui le dépose le contrôle
|   ensuite en entier (voir ControleDesFichiers) ;
| - son aperçu ne se sert que pour une photo JPEG ou PNG, jamais pour un
|   PDF ni pour une image SVG.
|
*/

return [

    'temporary_file_upload' => [
        'disk' => 'local',
        'rules' => ['required', 'file', 'max:10240', 'mimetypes:application/pdf,image/jpeg,image/png'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => ['png', 'jpg', 'jpeg'],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
