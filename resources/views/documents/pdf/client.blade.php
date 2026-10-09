{{--
    @param \App\Domain\Contacts\Models\Client $client
    @param string $titre
--}}
<div class="bloc-client">
    <div class="titre">{{ $titre }}</div>
    <strong>{{ $client->nom }}</strong><br>
    @if($client->adresse)
        {{ $client->adresse }}<br>
    @endif
    @if($client->code_postal || $client->ville)
        {{ $client->code_postal }} {{ $client->ville }}<br>
    @endif
    @if($client->siret)
        SIRET : {{ $client->siret }}
    @endif
</div>
