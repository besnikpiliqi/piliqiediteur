{{--
    Les lignes d'un document de vente (points 3 et 11), au modèle M6-b pour
    un professionnel et P1 pour un particulier (choix de l'éditeur du
    14/11/2026), telles que TableauDesLignes les a préparées et mesurées :
    sous le titre « Détail des articles et prestations », ses colonnes
    selon le document, sur fond bleu nuit, une rangée sur deux teintée, le
    texte de chaque case, lu dans la ligne enregistrée, « Service » en gris
    à la place de la référence d'un service, le PU HT exact en petit sous
    l'article d'un document qui montre le PU HT, le total de la ligne en
    sarcelle, et le corps réduit d'une référence trop longue pour sa
    colonne. Les largeurs des colonnes sont dans la règle de la page (voir
    documents/pdf/styles). Un titre est échappé comme le texte d'un
    élément, son apostrophe gardée (« Facture d'origine »).

    @param \App\Domain\Facturation\Models\Facture|\App\Domain\Facturation\Models\Avoir|\App\Domain\Facturation\Models\Devis $document
    @param \App\Domain\Facturation\Services\TableauDesLignes $tableau
--}}
@php
    $noteDeCalcul = app(\App\Domain\Facturation\Services\MentionsFiscales::class)->calcul($document, avecLePuHtExact: true);
@endphp

<div class="titre-section">Détail des articles et prestations</div>
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
            @if($loop->even)
                <tr class="paire">
            @else
                <tr>
            @endif
                @if($ligne['corpsDeLaReference'] === null)
                    <td @class(['reference', 'service' => $ligne['service']])>{{ $ligne['reference'] }}</td>
                @else
                    <td @class(['reference', 'service' => $ligne['service']]) style="font-size: {{ number_format($ligne['corpsDeLaReference'], 1, '.', '') }}pt">{{ $ligne['reference'] }}</td>
                @endif
                <td>{{ $ligne['designation'] }}@if($ligne['puHtExact'] !== null)<span class="sous">{{ $ligne['puHtExact'] }}</span>@endif</td>
                @if($ligne['factureDOrigine'] !== null)
                    <td class="numero">{{ $ligne['factureDOrigine'] }}</td>
                @endif
                @foreach($ligne['montants'] as $montant)
                    <td @class(['montant', 'total-de-la-ligne' => $loop->last])>{{ $montant }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>

@if($noteDeCalcul)
    <div class="note-calcul">{{ $noteDeCalcul }}</div>
@endif
