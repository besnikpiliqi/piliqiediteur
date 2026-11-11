<?php

/*
|--------------------------------------------------------------------------
| Les PDF que PILIQI produit (dompdf)
|--------------------------------------------------------------------------
|
| Décision de l'éditeur du 07/11/2026, lot FI : aucun PDF de PILIQI ne
| contient de JavaScript ni de code PHP, ni ne charge quoi que ce soit
| d'extérieur ; et dompdf ne lit sur le disque que ses polices
| (storage/fonts, son cache, et celles de son paquet) : un nom de client,
| une désignation ou une mention qui contiendrait <img src="file:///…">,
| <link …> ou une url() de CSS reste du texte, échappé par les gabarits,
| et, même écrit dans le code d'un gabarit, ne lirait ni le fichier .env ni
| storage/. Les images des gabarits (le QR code d'un courrier) y sont
| écrites en data:, sans fichier.
|
*/

return [

    'show_warnings' => false,

    'public_path' => null,

    'convert_entities' => true,

    'options' => [
        'font_dir' => storage_path('fonts'),
        'font_cache' => storage_path('fonts'),
        'temp_dir' => sys_get_temp_dir(),
        'chroot' => [storage_path('fonts'), base_path('vendor/dompdf/dompdf/lib/fonts')],
        'allowed_protocols' => [
            'data://' => ['rules' => []],
            'file://' => ['rules' => []],
        ],
        'artifactPathValidation' => null,
        'log_output_file' => null,
        'enable_font_subsetting' => false,
        'pdf_backend' => 'CPDF',
        'default_media_type' => 'screen',
        'default_paper_size' => 'a4',
        'default_paper_orientation' => 'portrait',
        'default_font' => 'serif',
        'dpi' => 96,
        'enable_php' => false,
        'enable_javascript' => false,
        'enable_remote' => false,
        'allowed_remote_hosts' => null,
        'font_height_ratio' => 1.1,
        'enable_html5_parser' => true,
    ],

];
