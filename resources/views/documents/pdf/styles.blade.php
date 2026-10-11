{{-- Présentation commune des PDF de vente : facture, devis, avoir. --}}
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2933; }
    .en-tete { width: 100%; margin-bottom: 24px; }
    .en-tete td { vertical-align: top; }
    .magasin-nom { font-size: 16px; font-weight: bold; }
    .titre-document { font-size: 20px; font-weight: bold; text-align: right; }
    .titre-document.avoir { color: #b91c1c; }
    .details-document { text-align: right; color: #52606d; }
    .bloc-client { margin-top: 16px; margin-bottom: 24px; }
    .bloc-client .titre { font-size: 9px; text-transform: uppercase; color: #7b8794; margin-bottom: 4px; }
    table.lignes { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
    table.lignes th { background: #f5f7fa; text-align: left; padding: 6px 8px; font-size: 9px; text-transform: uppercase; color: #52606d; border-bottom: 1px solid #d9e2ec; }
    table.lignes td { padding: 6px 8px; border-bottom: 1px solid #e4e7eb; }
    table.lignes .montant { text-align: right; }
    .note-calcul { margin-top: -16px; margin-bottom: 24px; font-size: 9px; color: #52606d; }
    table.totaux { width: 260px; margin-left: auto; border-collapse: collapse; }
    table.totaux td { padding: 4px 8px; }
    table.totaux .libelle { color: #52606d; }
    table.totaux .montant { text-align: right; }
    table.totaux .total-ttc { font-weight: bold; font-size: 13px; border-top: 1px solid #1f2933; }
    .pied { margin-top: 32px; font-size: 9px; color: #7b8794; }
    .accord { margin-top: 32px; width: 260px; margin-left: auto; height: 80px; border: 1px solid #d9e2ec; padding: 8px; font-size: 9px; color: #52606d; }
    .filigrane { position: fixed; top: 45%; left: -10%; width: 120%; text-align: center; font-size: 34px; font-weight: bold; color: #b91c1c; opacity: 0.15; transform: rotate(-30deg); }
    .bandeau-demonstration { margin-bottom: 12px; padding: 6px 8px; border: 2px solid #b91c1c; color: #b91c1c; font-weight: bold; text-align: center; }
</style>
