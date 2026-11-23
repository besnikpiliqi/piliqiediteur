<!DOCTYPE html>
<html lang="fr">
<head>
    {{--
        L'état des charges en PDF (voir EtatDesChargesEnPdf, modèle E1) : une
        page A4 à l'horizontale ; le tableau « type de charge × mois » avec la
        pastille et le TTC de chaque case, puis la liste des charges de la vue,
        puis la TVA de chaque mois, avec le report du crédit de TVA quand la
        page le montre (lot TVA27). Le pied de chaque page dit que c'est un
        document de travail ; MiseEnPageA4 écrit « Page x sur y » à sa droite.

        @param \App\Domain\Charges\Support\ContenuDeLEtat $contenu
        @param \Closure(int): string $euros
    --}}
    @php
        use App\Domain\Charges\Enums\EtatDeLaCharge;
        use App\Domain\Charges\Support\TextesDesCharges as Textes;
        use App\Domain\TableauDeBord\Services\SeriesDesGraphiques;
    @endphp
    <meta charset="utf-8">
    <title>{{ $contenu->titre() }}</title>
    <style>
        @page { size: A4 landscape; margin: 12mm 14mm 20mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1a1d24; }
        #pied { position: fixed; left: 0; bottom: -12mm; width: 75%; height: 6mm; padding-top: 1mm; border-top: 1px solid #e3e5ea; font-size: 7px; color: #5f6672; }
        table.tete { width: 100%; border-collapse: collapse; border-bottom: 2px solid #1a1d24; margin-bottom: 8px; }
        table.tete td { vertical-align: bottom; padding: 0 0 5px 0; }
        table.tete .titre { font-size: 13px; font-weight: bold; }
        table.tete .meta { text-align: right; color: #4b5160; }
        table.donnees { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.donnees thead { display: table-header-group; }
        table.donnees tr { page-break-inside: avoid; }
        table.donnees th { font-size: 7px; color: #4b5160; font-weight: bold; text-align: left; padding: 3px 3px; border-bottom: 1px solid #c9ced8; }
        table.donnees td { padding: 3px 3px; border-bottom: 1px solid #e3e5ea; vertical-align: top; }
        table.donnees .n { text-align: right; white-space: nowrap; }
        table.donnees .c { text-align: center; }
        table.donnees tr.total td { font-weight: bold; border-top: 1.5px solid #1a1d24; border-bottom: 0; }
        table.grille { table-layout: fixed; }
        table.grille th.type { width: 17%; }
        table.grille th.total { width: 8%; }
        table.grille td.case { text-align: center; padding: 3px 1px; }
        .pc { display: inline-block; padding: 1px 4px; border-radius: 3px; font-weight: bold; }
@foreach (EtatDeLaCharge::cases() as $etat)
        .pc-{{ $etat->value }} { background: #{{ $etat->couleursImprimees()['fond'] }}; color: #{{ $etat->couleursImprimees()['texte'] }}; }
@endforeach
        .montant-de-la-case { display: block; font-size: 6.5px; color: #3f4552; margin-top: 1px; white-space: nowrap; }
        .rien { color: #a3a9b6; font-weight: bold; }
        .legende { color: #4b5160; font-size: 7px; }
        .legende span.entree { margin-right: 12px; }
        .gris { color: #5f6672; font-size: 7px; }
        .saut { page-break-before: always; }
        .sous-titre { display: block; font-size: 8px; font-weight: normal; color: #4b5160; }
    </style>
</head>
<body>
    <div id="pied">PILIQI — état des charges, document de travail (n'est pas une déclaration de TVA)</div>

    <table class="tete">
        <tr>
            <td>
                <span class="titre">{{ $contenu->titre() }}</span><br>
                {{ $contenu->denomination }} · SIREN {{ $contenu->siren }}<br>
                {{ $contenu->lesFiltres() }}
            </td>
            <td class="meta">{{ $contenu->edition }}</td>
        </tr>
    </table>

    <table class="donnees grille" data-grille>
        <thead>
            <tr>
                <th class="type">Type de charge</th>
                @foreach ($contenu->mois as $mois)
                    <th class="c">{{ Textes::abregeDuMois($mois) }} {{ substr($mois, 2, 2) }}</th>
                @endforeach
                <th class="total n">Total TTC</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($contenu->lignes as $ligne)
                <tr>
                    <td>{{ $ligne['libelle'] }}</td>
                    @foreach ($contenu->mois as $mois)
                        @php($case = $ligne['cases'][$mois] ?? null)
                        <td class="case">
                            @if ($case === null)
                                <span class="rien">·</span>
                            @else
                                <span class="pc pc-{{ $case['etat']->value }}">{{ $case['etat']->symbole() }}@if ($case['nombre'] > 1) {{ $case['nombre'] }}@endif</span>
                                <span class="montant-de-la-case">{{ $case['ttc'] === null ? '—' : $euros($case['ttc']) }}</span>
                            @endif
                        </td>
                    @endforeach
                    <td class="n">{{ $ligne['total'] === 0 ? '—' : $euros($ligne['total']) }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ count($contenu->mois) + 2 }}">Aucune charge sur cette période.</td></tr>
            @endforelse
            <tr class="total">
                <td>Total TTC du mois</td>
                @foreach ($contenu->mois as $mois)
                    <td class="n">{{ ($contenu->totauxDuMois[$mois] ?? 0) === 0 ? '—' : $euros($contenu->totauxDuMois[$mois]) }}</td>
                @endforeach
                <td class="n">{{ $euros($contenu->total) }}</td>
            </tr>
        </tbody>
    </table>
    <p class="legende">
        @foreach (EtatDeLaCharge::cases() as $etat)
            <span class="entree"><span class="pc pc-{{ $etat->value }}">{{ $etat->symbole() }}</span> {{ mb_strtolower($etat->getLabel()) }}</span>
        @endforeach
        <span class="entree"><span class="rien">·</span> rien de saisi</span>
        <span class="entree">Montants : TTC des charges de la case (« — » : pièces sans chiffres)</span>
    </p>

    <div class="saut"></div>
    <table class="tete">
        <tr>
            <td><span class="titre">Liste des charges — {{ Textes::enMinuscule($contenu->filtres->periode) }}</span><span class="sous-titre">{{ $contenu->lesFiltres() }} · triées par mois de TVA, puis par type</span></td>
            <td class="meta">{{ $contenu->denomination }}</td>
        </tr>
    </table>
    <table class="donnees" data-liste>
        <thead>
            <tr>
                <th>Mois de TVA</th>
                <th>Type</th>
                <th>Magasin</th>
                <th>Fournisseur</th>
                <th>N° de la pièce</th>
                <th class="n">HT</th>
                <th class="n">TVA</th>
                <th class="n">TTC</th>
                <th class="n">TVA déductible</th>
                <th>État</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($contenu->charges as $charge)
                @php($sansChiffres = $charge->etat === EtatDeLaCharge::PieceSansChiffres)
                <tr>
                    <td>{{ ucfirst(Textes::nomDuMois($charge->mois_tva)) }}</td>
                    <td>{{ $charge->libelleDuType() }}</td>
                    <td>{{ $charge->nomDuMagasin() }}</td>
                    <td>{{ $charge->fournisseur ?? '—' }}</td>
                    <td>{{ $charge->numero_piece ?? '—' }}</td>
                    <td class="n">{{ $sansChiffres ? '—' : $euros($charge->total_ht) }}</td>
                    <td class="n">{{ $sansChiffres ? '—' : $euros($charge->total_tva) }}</td>
                    <td class="n">{{ $sansChiffres ? '—' : $euros($charge->total_ttc) }}</td>
                    <td class="n">{{ $sansChiffres ? '—' : $euros($charge->total_tva_deductible) }}@if ($charge->tva_a_verifier)<br><span class="gris">TVA à vérifier</span>@endif</td>
                    <td><span class="pc pc-{{ $charge->etat->value }}">{{ $charge->etat->symbole() }}</span> {{ $charge->etat->getLabel() }}</td>
                </tr>
            @empty
                <tr><td colspan="10">Aucune charge dans cette vue sur la période.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="saut"></div>
    <table class="tete">
        <tr>
            <td><span class="titre">TVA par mois — {{ Textes::enMinuscule($contenu->filtres->periode) }}</span><span class="sous-titre">{{ $contenu->tva === null || $contenu->tva['avec_les_ventes'] ? 'Telle que le tableau de bord la compte' : 'Les seules charges que vous voyez, sans les ventes ni les achats, que vous ne voyez pas' }}</span></td>
            <td class="meta">{{ $contenu->denomination }}</td>
        </tr>
    </table>
    @if ($contenu->tva === null)
        <p data-tva="sans-tva">{{ $contenu->sansTva ?? 'Aucune TVA à afficher.' }}</p>
    @else
        @php($complete = $contenu->tva['avec_les_ventes'])
        @php($avecLeReport = $contenu->montreLeReport())
        <table class="donnees" data-tva="{{ $complete ? 'complete' : 'charges' }}" data-report="{{ $avecLeReport ? 'oui' : 'non' }}">
            <thead>
                <tr>
                    <th>Mois</th>
                    @if ($complete)
                        <th class="n">TVA collectée</th>
                        <th class="n">Déductible des achats</th>
                    @endif
                    <th class="n">Déductible des charges validées</th>
                    @if ($complete)
                        <th class="n">{{ $avecLeReport ? 'TVA du mois (réelle)' : 'TVA à payer (réelle)' }}</th>
                    @endif
                    <th class="n">TVA des charges à valider</th>
                    @if ($complete)
                        <th class="n">{{ $avecLeReport ? 'TVA du mois (estimation)' : 'TVA à payer (estimation)' }}</th>
                    @endif
                    @if ($avecLeReport)
                        <th class="n">Crédit reporté de la période d'avant</th>
                        <th class="n">TVA à payer</th>
                        <th class="n">Crédit de TVA à reporter</th>
                        <th class="n">Estimation après le report</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($contenu->tva['montants'] as $mois => $montants)
                    @php($report = $avecLeReport ? $contenu->reportDuMois($mois) : null)
                    <tr>
                        <td>{{ ucfirst(Textes::nomDuMois($mois)) }}</td>
                        @if ($complete)
                            <td class="n">{{ $euros($montants->collectee()) }}</td>
                            <td class="n">{{ $euros($montants->deductibleDesAchats) }}</td>
                        @endif
                        <td class="n">{{ $euros($montants->deductibleDesChargesValidees) }}</td>
                        @if ($complete)
                            <td class="n">@if ($avecLeReport){{ $euros($montants->reelle()) }}@else<strong>{{ $euros($montants->reelle()) }}</strong>@endif</td>
                        @endif
                        <td class="n">{{ $euros($montants->deductibleDesChargesAValider) }}</td>
                        @if ($complete)
                            <td class="n">{{ $euros($montants->estimee()) }}</td>
                        @endif
                        @if ($avecLeReport)
                            @if ($report === null)
                                <td class="n">—</td>
                                <td class="n">—</td>
                                <td class="n">—</td>
                                <td class="n">—</td>
                            @else
                                <td class="n">{{ $report['credit_reporte'] === null ? '—' : $euros($report['credit_reporte']) }}</td>
                                <td class="n"><strong>{{ $report['declaree'] ? 'déclarée' : $euros($report['a_payer'] ?? 0) }}</strong></td>
                                <td class="n">{{ $euros($report['credit_a_reporter']) }}</td>
                                <td class="n">{{ $report['estimation'] === null ? '—' : ($report['estimation'] >= 0 ? $euros($report['estimation']).' à payer' : $euros(-$report['estimation']).' à reporter') }}</td>
                            @endif
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($complete)
            <p class="gris">Estimation : la TVA à payer si les charges à valider l'étaient telles qu'elles sont saisies.</p>
        @endif
        @foreach ($contenu->phrasesDuReport() as $phrase)
            <p class="gris">{{ $phrase }}</p>
        @endforeach
        @if ($contenu->tva['sans_celles_de_la_societe'])
            <p class="gris">{{ SeriesDesGraphiques::SANS_LES_CHARGES_DE_LA_SOCIETE }}</p>
        @endif
        @if ($contenu->tva['magasins_non_comptes'] !== null)
            <p class="gris">{{ $contenu->tva['magasins_non_comptes'] }}</p>
        @endif
    @endif
    <p><strong>Document de travail, n'est pas une déclaration de TVA.</strong></p>
</body>
</html>
