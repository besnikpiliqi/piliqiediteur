{{--
    Les deux blocs sous l'en-tête d'un document de vente (modèle M6-b,
    choix de l'éditeur du 14/11/2026, et P1 pour un particulier) : à
    gauche, le client tel que le document l'imprime -- nom, adresse, pays
    hors de France et, pour un professionnel, ses identifiants (voir
    CopieClient) -- ; à droite, ses conditions : le règlement d'un ticket
    ou d'une facture, la validité d'un devis, les documents qu'un avoir
    rectifie, la première ligne en gras.

    @param \App\Domain\Facturation\Services\CopieClient $client
    @param bool $professionnel
    @param string $titre
    @param string $titreDesConditions
    @param list<string> $conditions
--}}
<table class="blocs">
    <tr>
        <td class="bloc">
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
        </td>
        <td class="espace"></td>
        <td class="bloc conditions">
            <div class="titre">{{ $titreDesConditions }}</div>
            @foreach($conditions as $condition)
                @if($loop->first)
                    <strong>{{ $condition }}</strong><br>
                @else
                    {{ $condition }}<br>
                @endif
            @endforeach
        </td>
    </tr>
</table>
