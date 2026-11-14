{{--
    Présentation commune des PDF de vente : ticket, facture, avoir, devis,
    et du reçu de paiement, qui n'a pas de lignes (voir recus/pdf).
    Une vraie page A4 en portrait, jamais réduite (voir MiseEnPageA4). La
    marge du bas réserve, au-dessus des 12 mm où s'imprime « Page x sur y »,
    la hauteur du pied de page (voir PiedDePage::hauteurEnMillimetres()),
    fixé en bas de chaque page : il ne recouvre jamais une ligne.

    Le tableau des lignes a une disposition fixe : chaque colonne a la
    largeur que TableauDesLignes a mesurée, avec le même corps et les mêmes
    marges intérieures, si bien qu'il ne dépasse jamais la largeur de la
    page ; seule la désignation passe à la ligne, au besoin au milieu d'un
    mot trop long pour elle.

    Les totaux s'impriment sur une bande d'une seule rangée de tableau
    (voir documents/pdf/totaux) : le montant à régler à droite, de la
    largeur qu'il a toujours eue, et, à sa gauche, le récapitulatif par
    taux puis le tableau des règlements d'un ticket ou d'une facture, dont
    les dimensions sont celles que mesure TableauDesReglements ; une
    référence n'y passe à la ligne qu'entre deux mots.

    Les couleurs et les blocs d'un document de vente sont ceux du modèle
    M6-b choisi par l'éditeur le 14/11/2026 (P1 pour un particulier, qui ne
    change que les colonnes des lignes) : une bande sarcelle en haut de la
    première page, le magasin souligné d'un filet sarcelle, le titre en
    bleu nuit, le client et les conditions dans deux blocs, les titres des
    lignes sur fond bleu nuit, une rangée sur deux teintée, le total de
    chaque ligne en sarcelle, le récapitulatif par taux et le montant à
    régler côte à côte.

    @param \App\Domain\Facturation\Services\PiedDePage $pied
    @param \App\Domain\Facturation\Services\TableauDesLignes|null $tableau les lignes du document, aucune pour un reçu de paiement
--}}
@php
    $hauteurDuPied = $pied->hauteurEnMillimetres();
    $marge = \App\Domain\Facturation\Services\MiseEnPageA4::MARGE;
    $margeDeCase = \App\Domain\Facturation\Services\TableauDesLignes::MARGE_DE_CASE;
    $reglements = \App\Domain\Facturation\Services\TableauDesReglements::class;
@endphp
<style>
    @page { size: A4 portrait; margin: {{ $marge }}mm {{ $marge }}mm {{ 12 + $hauteurDuPied + 4 }}mm {{ $marge }}mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #17283d; }
    #pied-de-page { position: fixed; left: 0; right: 0; bottom: -{{ $hauteurDuPied + 4 }}mm; height: {{ $hauteurDuPied }}mm; padding-top: 1mm; border-top: 1px solid #e3e7ec; font-size: 7px; color: #5f6b7a; }
    #pied-de-page .identite { font-weight: bold; }
    .bande-haut { height: 6px; background: #14706b; margin-bottom: 14px; }
    .en-tete { width: 100%; margin-bottom: 16px; }
    .en-tete td { vertical-align: top; }
    .en-tete td.magasin { border-left: 4px solid #14706b; padding-left: 10px; color: #5f6b7a; }
    .magasin-nom { font-size: 15px; font-weight: bold; color: #16283f; }
    .titre-document { font-size: 20px; font-weight: bold; text-align: right; color: #16283f; }
    .titre-document.majuscules { text-transform: uppercase; }
    .titre-document.avoir { color: #b91c1c; }
    .details-document { text-align: right; color: #5f6b7a; }
    .details-document .numero-du-document { color: #17283d; font-weight: bold; }
    .bloc-client { margin-top: 12px; margin-bottom: 20px; }
    .bloc-client .titre { font-size: 8px; text-transform: uppercase; color: #7b8794; margin-bottom: 4px; }
    table.blocs { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.blocs td.bloc { background: #f5f7f9; padding: 7px 10px; vertical-align: top; font-size: 9px; }
    table.blocs td.bloc.conditions { background: #eef5f4; width: 36%; }
    table.blocs td.espace { width: 8pt; padding: 0; }
    table.blocs .titre { font-size: 7px; font-weight: bold; color: #14706b; text-transform: uppercase; margin-bottom: 3px; }
    .titre-section { font-size: 8px; font-weight: bold; color: #16283f; text-transform: uppercase; margin-bottom: 4px; }
    table.lignes, table.tva, table.documents-regles { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    table.lignes { table-layout: fixed; margin-bottom: 6px; }
    table.lignes th, table.tva th, table.documents-regles th { background: #f5f7fa; text-align: left; padding: 3.75pt {{ $margeDeCase }}pt; font-size: {{ \App\Domain\Facturation\Services\TableauDesLignes::CORPS_DES_TITRES }}pt; text-transform: uppercase; color: #52606d; border-bottom: 1px solid #d9e2ec; }
    table.lignes th { background: #16283f; color: #ffffff; font-weight: bold; border-bottom: none; }
    table.lignes td, table.tva td, table.documents-regles td { padding: 3.75pt {{ $margeDeCase }}pt; font-size: {{ \App\Domain\Facturation\Services\TableauDesLignes::CORPS }}pt; border-bottom: 1px solid #e3e7ec; vertical-align: top; }
    table.lignes tr.paire td { background: #f5f7f9; }
    table.lignes th, table.lignes td { overflow-wrap: break-word; }
    table.lignes .reference, table.lignes .numero { white-space: nowrap; }
    table.lignes .reference { color: #5f6b7a; }
    table.lignes .reference.service { color: #9aa5b1; }
    table.lignes .sous { display: block; font-size: 6pt; color: #5f6b7a; }
    table.lignes .total-de-la-ligne { color: #14706b; font-weight: bold; }
    table.lignes .montant, table.tva .montant, table.documents-regles .montant { text-align: right; white-space: nowrap; }
@foreach(isset($tableau) ? $tableau->pourcentages() : [] as $rang => $pourcentage)
    table.lignes th:nth-child({{ $rang + 1 }}) { width: {{ $pourcentage }}%; }
@endforeach
    table.tva .motif { font-size: 8px; color: #52606d; }
    .note-calcul { margin-bottom: 14px; font-size: 7.5px; color: #5f6b7a; }
    table.bande-des-totaux { width: 100%; border-collapse: collapse; page-break-inside: avoid; }
    table.bande-des-totaux td.bande-gauche { vertical-align: top; padding: 0 {{ $reglements::ECART_AVEC_LES_TOTAUX }}pt 0 0; }
    table.bande-des-totaux td.bande-droite { vertical-align: top; width: {{ $reglements::LARGEUR_DES_TOTAUX }}pt; padding: 0; }
    .recapitulatif { background: #f5f7f9; padding: 6px 4px 2px 4px; margin-bottom: 8px; }
    .recapitulatif table.tva { margin-bottom: 4px; }
    .recapitulatif table.tva th { background: none; border-bottom: none; text-transform: none; color: #5f6b7a; font-weight: normal; }
    .recapitulatif table.tva td { border-bottom: none; }
    .titre-de-boite { font-size: 8px; font-weight: bold; color: #14706b; padding: 0 {{ $margeDeCase }}pt 2px {{ $margeDeCase }}pt; }
    .a-regler { background: #eef5f4; padding: 6px 6px; }
    table.totaux { width: 100%; border-collapse: collapse; }
    table.totaux td { padding: 3px 4px; }
    table.totaux .libelle { color: #5f6b7a; }
    table.totaux .montant { text-align: right; white-space: nowrap; }
    table.totaux .total-ttc td { font-weight: bold; color: #16283f; border-top: 1px solid #cfdcda; padding-top: 5px; }
    table.totaux .total-ttc .libelle { text-transform: uppercase; }
    table.totaux .total-ttc .montant { font-size: 13px; color: #14706b; }
    .titre-reglements { font-size: 8px; text-transform: uppercase; color: #7b8794; padding: 4px {{ $reglements::MARGE_DE_CASE }}pt 2px {{ $reglements::MARGE_DE_CASE }}pt; }
    table.reglements { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: {{ $reglements::CORPS }}pt; }
    table.reglements th, table.reglements td { padding: 3px {{ $reglements::MARGE_DE_CASE }}pt; text-align: left; vertical-align: top; }
    table.reglements tr.entetes th { font-size: 7px; font-weight: normal; text-transform: uppercase; color: #7b8794; border-bottom: 1px solid #d9e2ec; }
    table.reglements .date { width: {{ $reglements::COLONNES['date'] }}%; white-space: nowrap; color: #52606d; }
    table.reglements .mode { width: {{ $reglements::COLONNES['mode'] }}%; color: #52606d; }
    table.reglements .reference { width: {{ $reglements::COLONNES['reference'] }}%; color: #52606d; overflow-wrap: break-word; }
    table.reglements .montant { width: {{ $reglements::COLONNES['montant'] }}%; text-align: right; white-space: nowrap; }
    table.reglements .total td { font-weight: bold; border-top: 1px solid #d9e2ec; }
    .insecable { white-space: nowrap; }
    .remarque { margin-top: 20px; font-size: 9px; color: #52606d; }
    .reglement-du-recu { margin-bottom: 20px; }
    .mention-du-recu { margin-top: 8px; font-size: 11px; font-weight: bold; }
    .accord { margin-top: 24px; width: 260px; margin-left: auto; height: 80px; border: 1px solid #d9e2ec; padding: 8px; font-size: 8px; color: #52606d; }
    .filigrane { position: fixed; top: 45%; left: -10%; width: 120%; text-align: center; font-size: 34px; font-weight: bold; color: #b91c1c; opacity: 0.15; transform: rotate(-30deg); }
    .bandeau-demonstration { margin-bottom: 12px; padding: 6px 8px; border: 2px solid #b91c1c; color: #b91c1c; font-weight: bold; text-align: center; }
</style>
