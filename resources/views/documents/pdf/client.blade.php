{{--
    Le client tel que le document l'imprime : nom, adresse, pays hors de
    France et, pour un professionnel, ses identifiants (voir CopieClient).

    @param \App\Domain\Facturation\Services\CopieClient $client
    @param bool $professionnel
    @param string $titre
--}}
<div class="bloc-client">
    <div class="titre">{{ $titre }}</div>
    <strong>{{ $client->nom }}</strong><br>
    @foreach($client->lignesAdresse() as $ligne)
        {{ $ligne }}<br>
    @endforeach
    @if($professionnel)
        @foreach($client->identifiants() as $identifiant)
            {{ $identifiant }}<br>
        @endforeach
    @endif
</div>
