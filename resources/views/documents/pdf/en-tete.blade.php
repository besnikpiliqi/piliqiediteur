{{--
    Mentions du magasin à gauche, nature et références du document à
    droite.

    @param \App\Domain\Tenancy\Models\Magasin $magasin
    @param string $titre
    @param string $classeTitre classe CSS en plus de titre-document
    @param list<string> $details une ligne chacun : numéro, dates...
--}}
<table class="en-tete">
    <tr>
        <td>
            <div class="magasin-nom">{{ $magasin->nom }}</div>
            @if($magasin->adresse)
                {{ $magasin->adresse }}<br>
            @endif
            @if($magasin->code_postal || $magasin->ville)
                {{ $magasin->code_postal }} {{ $magasin->ville }}<br>
            @endif
            @if($magasin->siret)
                SIRET : {{ $magasin->siret }}<br>
            @endif
            @if($magasin->numero_tva)
                TVA intracommunautaire : {{ $magasin->numero_tva }}
            @endif
        </td>
        <td>
            <div class="titre-document {{ $classeTitre ?? '' }}">{{ $titre }}</div>
            <div class="details-document">
                {!! collect($details)->map(fn (string $detail): string => e($detail))->implode('<br>') !!}
            </div>
        </td>
    </tr>
</table>
