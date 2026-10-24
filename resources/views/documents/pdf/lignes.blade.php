{{--
    Les lignes d'un document de vente (points 3 et 11), telles que
    TableauDesLignes les a préparées et mesurées : ses colonnes selon le
    document, le texte de chaque case, lu dans la ligne enregistrée, et le
    corps réduit d'une référence trop longue pour sa colonne. Les largeurs
    des colonnes sont dans la règle de la page (voir documents/pdf/styles).
    Un titre est échappé comme le texte d'un élément, son apostrophe gardée
    (« Facture d'origine »).

    @param \App\Domain\Facturation\Models\Facture|\App\Domain\Facturation\Models\Avoir|\App\Domain\Facturation\Models\Devis $document
    @param \App\Domain\Facturation\Services\TableauDesLignes $tableau
--}}
@php
    $noteDeCalcul = app(\App\Domain\Facturation\Services\MentionsFiscales::class)->calcul($document);
@endphp

<table class="lignes">
    <thead>
        <tr>
            @foreach($tableau->colonnes as $colonne)
                @if($colonne['montant'])
                    <th class="montant">{!! htmlspecialchars($colonne['titre'], ENT_NOQUOTES) !!}</th>
                @else
                    <th>{!! htmlspecialchars($colonne['titre'], ENT_NOQUOTES) !!}</th>
                @endif
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach($tableau->lignes as $ligne)
            <tr>
                @if($ligne['corpsDeLaReference'] === null)
                    <td class="reference">{{ $ligne['reference'] }}</td>
                @else
                    <td class="reference" style="font-size: {{ number_format($ligne['corpsDeLaReference'], 1, '.', '') }}pt">{{ $ligne['reference'] }}</td>
                @endif
                <td>{{ $ligne['designation'] }}</td>
                @if($ligne['factureDOrigine'] !== null)
                    <td class="numero">{{ $ligne['factureDOrigine'] }}</td>
                @endif
                @foreach($ligne['montants'] as $montant)
                    <td class="montant">{{ $montant }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>

@if($noteDeCalcul)
    <div class="note-calcul">{{ $noteDeCalcul }}</div>
@endif
