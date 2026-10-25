{{--
    Les vérifications VIES d'un client (voir ClientResource::sectionDesVerificationsVies()) :
    ce que dit la dernière pour son n° actuel, puis toutes, de la plus récente à la plus ancienne.
    Le nom renvoyé par VIES vient de l'extérieur : il s'affiche échappé, comme tout le reste.
    Le panneau n'a pas de thème compilé : le tableau est mis en forme en style en ligne.
--}}
<div style="display: flex; flex-direction: column; gap: 0.75rem;">
    <p>
        @if ($derniere !== null)
            Le n° {{ $numeroTva }} est {{ $derniere->valide ? 'valide sur VIES' : 'inconnu de VIES' }} selon la {{ $derniere->reference() }}.
        @elseif (filled($numeroTva))
            Le n° {{ $numeroTva }} n'a pas encore été vérifié sur VIES.
        @else
            Ce client n'a plus de n° de TVA.
        @endif
    </p>

    @if ($consultations->isNotEmpty())
        <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
            <thead>
                <tr style="text-align: left;">
                    <th style="padding: 0.25rem 0.5rem;">Date</th>
                    <th style="padding: 0.25rem 0.5rem;">N° de TVA</th>
                    <th style="padding: 0.25rem 0.5rem;">Résultat</th>
                    <th style="padding: 0.25rem 0.5rem;">N° de consultation</th>
                    <th style="padding: 0.25rem 0.5rem;">Nom renvoyé</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($consultations as $consultation)
                    <tr style="border-top: 1px solid rgba(128, 128, 128, 0.25);">
                        <td style="padding: 0.25rem 0.5rem;">{{ \App\Domain\Calendrier\Calendrier::deParis()->afficher($consultation->consultee_le, 'd/m/Y à H:i') }}</td>
                        <td style="padding: 0.25rem 0.5rem;">{{ $consultation->numero_tva }}</td>
                        <td style="padding: 0.25rem 0.5rem;">{{ $consultation->valide ? 'Valide' : 'Inconnu' }}</td>
                        <td style="padding: 0.25rem 0.5rem;">{{ $consultation->identifiant_consultation ?? '—' }}</td>
                        <td style="padding: 0.25rem 0.5rem;">{{ $consultation->nom ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
