{{--
    La cellule « Encaissé » d'un relevé de caisse (demandes de l'éditeur du
    15/11/2026, choix A) : une ligne par mode, en abrégé (CB, ESP, CHQ, VIR,
    PRLV ; le nom entier en infobulle et pour les lecteurs d'écran), dans
    l'ordre fixe des relevés, chaque montant aligné à droite avec son « € »
    sur la même ligne ; avec plusieurs modes, une dernière ligne « Total »
    en gras. Voir ClotureCaissePersonnelle::encaissementsParMode(), la page
    « Les caisses » de l'espace de la société, la liste des relevés du
    panneau d'un magasin et la fenêtre « Fermer ma caisse ».
--}}
@php
    $calculateur = app(\App\Domain\Facturation\Services\CalculateurLigne::class);
    $parMode = $releve->encaissementsParMode();
@endphp
<span class="piliqi-encaisse">
    @forelse ($parMode as ['mode' => $mode, 'centimes' => $centimes])
        <abbr class="piliqi-encaisse__mode" title="{{ $mode->getLabel() }}">{{ $mode->abreviation() }}</abbr>
        <span class="piliqi-encaisse__montant">{{ $calculateur->enEuros($centimes) }}</span>
    @empty
        <span class="piliqi-encaisse__mode"></span>
        <span class="piliqi-encaisse__montant">{{ $calculateur->enEuros(0) }}</span>
    @endforelse
    @if (count($parMode) > 1)
        <span class="piliqi-encaisse__mode piliqi-encaisse__total">Total</span>
        <span class="piliqi-encaisse__montant piliqi-encaisse__total">{{ $calculateur->enEuros($releve->totalEncaisseEnCentimes()) }}</span>
    @endif
</span>
