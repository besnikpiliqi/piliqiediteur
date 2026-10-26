{{--
    Posée dans chaque tableau du panneau du magasin (voir AppPanelProvider),
    à l'intérieur du composant Alpine « table » de Filament, dont elle lit
    et écrit les lignes cochées (selectedRecords) et l'identifiant du
    composant Livewire (livewireId). Après une action groupée dont des
    lignes ont été refusées, l'événement lignes-cochees-gardees (voir
    SupprimeEnMasseLigneParLigne::LIGNES_GARDEES) porte les clés refusées :
    elles deviennent toute la sélection de ce tableau, et les lignes
    supprimées ou archivées ne restent pas cochées. Un autre tableau de la
    page ignore l'événement.
--}}
<div
    hidden
    x-on:lignes-cochees-gardees.window="if ($event.detail.composant === livewireId) { selectedRecords = $event.detail.cles }"
></div>
