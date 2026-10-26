{{--
    Présentation commune des PDF de vente : ticket, facture, avoir, devis.
    Une vraie page A4 en portrait, jamais réduite (voir MiseEnPageA4). La
    marge du bas réserve, au-dessus des 12 mm où s'imprime « Page x sur y »,
    la hauteur du pied de page (voir PiedDePage::hauteurEnMillimetres()),
    fixé en bas de chaque page : il ne recouvre jamais une ligne.

    Le tableau des lignes a une disposition fixe : chaque colonne a la
    largeur que TableauDesLignes a mesurée, avec le même corps et les mêmes
    marges intérieures, si bien qu'il ne dépasse jamais la largeur de la
    page ; seule la désignation passe à la ligne, au besoin au milieu d'un
    mot trop long pour elle.

    @param \App\Domain\Facturation\Services\PiedDePage $pied
    @param \App\Domain\Facturation\Services\TableauDesLignes $tableau
--}}
@php
    $hauteurDuPied = $pied->hauteurEnMillimetres();
    $marge = \App\Domain\Facturation\Services\MiseEnPageA4::MARGE;
    $margeDeCase = \App\Domain\Facturation\Services\TableauDesLignes::MARGE_DE_CASE;
@endphp
<style>
    @page { size: A4 portrait; margin: {{ $marge }}mm {{ $marge }}mm {{ 12 + $hauteurDuPied + 4 }}mm {{ $marge }}mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2933; }
    #pied-de-page { position: fixed; left: 0; right: 0; bottom: -{{ $hauteurDuPied + 4 }}mm; height: {{ $hauteurDuPied }}mm; padding-top: 1mm; border-top: 1px solid #d9e2ec; font-size: 7px; color: #52606d; }
    #pied-de-page .identite { font-weight: bold; }
    .en-tete { width: 100%; margin-bottom: 20px; }
    .en-tete td { vertical-align: top; }
    .magasin-nom { font-size: 15px; font-weight: bold; }
    .titre-document { font-size: 19px; font-weight: bold; text-align: right; }
    .titre-document.avoir { color: #b91c1c; }
    .details-document { text-align: right; color: #52606d; }
    .bloc-client { margin-top: 12px; margin-bottom: 20px; }
    .bloc-client .titre { font-size: 8px; text-transform: uppercase; color: #7b8794; margin-bottom: 4px; }
    table.lignes, table.tva { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    table.lignes { table-layout: fixed; }
    table.lignes th, table.tva th { background: #f5f7fa; text-align: left; padding: 3.75pt {{ $margeDeCase }}pt; font-size: {{ \App\Domain\Facturation\Services\TableauDesLignes::CORPS_DES_TITRES }}pt; text-transform: uppercase; color: #52606d; border-bottom: 1px solid #d9e2ec; }
    table.lignes td, table.tva td { padding: 3.75pt {{ $margeDeCase }}pt; font-size: {{ \App\Domain\Facturation\Services\TableauDesLignes::CORPS }}pt; border-bottom: 1px solid #e4e7eb; vertical-align: top; }
    table.lignes th, table.lignes td { overflow-wrap: break-word; }
    table.lignes .reference, table.lignes .numero { white-space: nowrap; }
    table.lignes .montant, table.tva .montant { text-align: right; white-space: nowrap; }
@foreach($tableau->pourcentages() as $rang => $pourcentage)
    table.lignes th:nth-child({{ $rang + 1 }}) { width: {{ $pourcentage }}%; }
@endforeach
    table.tva .motif { font-size: 8px; color: #52606d; }
    .note-calcul { margin-top: -14px; margin-bottom: 20px; font-size: 8px; color: #52606d; }
    table.totaux { width: 260px; margin-left: auto; border-collapse: collapse; }
    table.totaux td { padding: 4px 8px; }
    table.totaux .libelle { color: #52606d; }
    table.totaux .montant { text-align: right; white-space: nowrap; }
    table.totaux .total-ttc { font-weight: bold; font-size: 12px; border-top: 1px solid #1f2933; }
    table.reglements { width: 260px; margin-left: auto; margin-top: 16px; border-collapse: collapse; }
    table.reglements td { padding: 3px 8px; }
    table.reglements .titre { font-size: 8px; text-transform: uppercase; color: #7b8794; }
    table.reglements .libelle { color: #52606d; }
    table.reglements .montant { text-align: right; white-space: nowrap; }
    table.reglements .restant-du { font-weight: bold; border-top: 1px solid #d9e2ec; }
    .remarque { margin-top: 20px; font-size: 9px; color: #52606d; }
    .accord { margin-top: 24px; width: 260px; margin-left: auto; height: 80px; border: 1px solid #d9e2ec; padding: 8px; font-size: 8px; color: #52606d; }
    .filigrane { position: fixed; top: 45%; left: -10%; width: 120%; text-align: center; font-size: 34px; font-weight: bold; color: #b91c1c; opacity: 0.15; transform: rotate(-30deg); }
    .bandeau-demonstration { margin-bottom: 12px; padding: 6px 8px; border: 2px solid #b91c1c; color: #b91c1c; font-weight: bold; text-align: center; }
</style>
