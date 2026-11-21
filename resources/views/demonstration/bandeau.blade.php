{{--
    Le bandeau « Vous êtes : » d'une copie du site de démonstration (lot
    DEMO-COPIE, C.8), en haut de chaque page des espaces de la copie et des
    pages de la démonstration : la société de la copie, le menu de ses seuls
    comptes (un clic change de compte, sans mot de passe), le mot de passe
    de démonstration, les e-mails qui seraient partis, la date de fin, le
    repère, la version et « Quitter ». Le menu est un élément <details> :
    il s'ouvre au clavier et se lit aux lecteurs d'écran, sans script.

    @param \App\Domain\Demonstration\Visites\BandeauDeLaDemonstration $bandeau
--}}
<link rel="stylesheet" href="{{ asset('css/app/bandeau-de-la-demonstration.css') }}">
<div class="piliqi-bandeau" role="region" aria-label="Démonstration" data-bandeau-de-la-demonstration>
    <span class="piliqi-bandeau__marque">Démonstration · données fictives</span>
    <span class="piliqi-bandeau__societe">{{ $bandeau->societe }}</span>
    <details class="piliqi-bandeau__menu" data-menu-vous-etes>
        <summary class="piliqi-bandeau__vous-etes">Vous êtes : <strong>{{ $bandeau->nomDuCompteChoisi() ?? 'choisissez un compte' }}</strong></summary>
        <div class="piliqi-bandeau__comptes">
            @foreach ($bandeau->groupes as $groupe => $comptes)
                <p class="piliqi-bandeau__groupe">{{ $groupe }}</p>
                @foreach ($comptes as $compte)
                    <form method="post" action="{{ route('demonstration.changer-de-compte') }}" class="piliqi-bandeau__formulaire">
                        @csrf
                        <input type="hidden" name="compte" value="{{ $compte['id'] }}">
                        <button type="submit" @class(['piliqi-bandeau__compte', 'piliqi-bandeau__compte--suspendu' => $compte['suspendu'], 'piliqi-bandeau__compte--choisi' => $compte['id'] === $bandeau->compteChoisi]) data-compte-du-bandeau="{{ $compte['id'] }}" @if ($compte['id'] === $bandeau->compteChoisi) aria-current="true" @endif>
                            <span class="piliqi-bandeau__nom">{{ $compte['nom'] }}</span>
                            <span class="piliqi-bandeau__role">{{ $compte['role'] }}{{ $compte['suspendu'] ? ' — compte suspendu' : '' }}</span>
                        </button>
                    </form>
                @endforeach
            @endforeach
        </div>
    </details>
    <span class="piliqi-bandeau__info">Mot de passe de démonstration : <code class="piliqi-bandeau__mot-de-passe">{{ $bandeau->motDePasse }}</code></span>
    <a class="piliqi-bandeau__lien" href="{{ route('demonstration.courriels') }}">E-mails qui seraient partis : {{ $bandeau->courriels }}</a>
    <span class="piliqi-bandeau__info">Fin le {{ $bandeau->fin }}</span>
    <span class="piliqi-bandeau__info">Repère {{ $bandeau->repere }}</span>
    <span class="piliqi-bandeau__info">{{ ucfirst(\App\Domain\Demonstration\VersionServie::enMots($bandeau->version)) }}</span>
    <form method="post" action="{{ route('demonstration.quitter') }}" class="piliqi-bandeau__formulaire">
        @csrf
        <button type="submit" class="piliqi-bandeau__quitter">Quitter</button>
    </form>
</div>
@if ($bandeau->enLectureSeule)
    <p class="piliqi-bandeau__message" role="status">{{ \App\Domain\Demonstration\Taches\PlaceDesCopies::MESSAGE }} Vous pouvez encore tout consulter.</p>
@endif
@if ($bandeau->message !== null)
    <p class="piliqi-bandeau__message" role="status">{{ $bandeau->message }}</p>
@endif
