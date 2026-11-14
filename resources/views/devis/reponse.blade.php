{{--
    La page du lien secret d'un devis envoyé (voir ReponseAuDevisController
    et LienDuDevis) : ce seul devis tel qu'il a été envoyé, le magasin qui
    l'envoie (son nom et son établissement, comme sur le PDF), son numéro,
    sa validité, ses lignes et ses totaux, son PDF ; puis les deux réponses
    possibles : accepter, en tapant son nom et en cochant « Bon pour
    accord », ou refuser, avec un motif facultatif. Rien d'autre du
    magasin, de la société ni d'un autre client, aucun lien vers le reste
    de PILIQI, aucun script.

    Les prix suivent le PDF : HT, au prix unitaire tel qu'il est enregistré,
    pour un professionnel ou une vente sans TVA française ; TTC sinon.

    @param \App\Domain\Facturation\Services\Emetteur $emetteur
    @param \App\Domain\Facturation\Models\Devis $devis
    @param string|null $client
    @param list<array{reference: string|null, description: string, quantite: int, prix_unitaire: string, remise: string|null, total: string}> $lignes
    @param bool $horsTaxe
    @param string $totalHt
    @param string $totalTva
    @param string $totalTtc
    @param string|null $validite
    @param list<string> $erreurs
    @param string|null $signataire
    @param string|null $motif
    @param string $adresseDuPdf
    @param string $actionAccepter
    @param string $actionRefuser
    @param int $signataireMaximum
    @param int $motifMaximum
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>Devis {{ $devis->numero }} · {{ $emetteur->nom }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 48rem; margin: 2rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.35rem; margin: 0 0 .25rem; }
        h2 { font-size: 1.1rem; margin: 2rem 0 .75rem; }
        .magasin { color: #374151; margin-bottom: 1.5rem; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        th, td { text-align: left; padding: .5rem; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        td.nombre, th.nombre { text-align: right; white-space: nowrap; }
        .reference { color: #4b5563; font-size: .85rem; display: block; }
        .totaux td { border: 0; padding: .2rem .5rem; }
        .totaux .total { font-weight: 700; font-size: 1.1rem; }
        .erreurs { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; border-radius: .5rem; padding: .75rem 1rem; }
        fieldset { border: 1px solid #e5e7eb; border-radius: .5rem; margin: 1rem 0; padding: 1rem; }
        legend { font-weight: 600; padding: 0 .25rem; }
        label.champ { display: block; margin: .5rem 0 .25rem; font-weight: 600; }
        input[type=text], textarea { width: 100%; padding: .5rem; border: 1px solid #d1d5db; border-radius: .375rem; box-sizing: border-box; font: inherit; }
        .case { display: flex; gap: .5rem; align-items: flex-start; margin: 1rem 0; font-weight: 600; }
        button { background: #15803d; color: #fff; border: 0; border-radius: .5rem; padding: .75rem 1.25rem; font-size: 1rem; cursor: pointer; }
        button.refus { background: #fff; color: #b91c1c; border: 1px solid #b91c1c; }
        .rappel { color: #4b5563; font-size: .9rem; }
        a.pdf { color: #1d4ed8; }
    </style>
</head>
<body>
<main>
    <h1>Devis {{ $devis->numero }}</h1>
    <div class="magasin">
        <strong>{{ $emetteur->nom }}</strong><br>
        {!! collect($emetteur->lignesDeLEtablissement())->map(fn (string $ligne): string => e($ligne))->implode('<br>') !!}
    </div>

    @if ($client)
        <p>Pour : <strong>{{ $client }}</strong></p>
    @endif
    @if ($validite)
        <p>Valable jusqu'au <strong>{{ \App\Domain\Calendrier\Calendrier::afficherJour($validite, 'd/m/Y') }}</strong>.</p>
    @endif
    <p><a class="pdf" href="{{ $adresseDuPdf }}" rel="noreferrer" download>Télécharger le devis en PDF</a></p>

    <table>
        <thead>
            <tr>
                <th>Désignation</th>
                <th class="nombre">Quantité</th>
                <th class="nombre">{{ $horsTaxe ? 'PU HT' : 'PU TTC' }}</th>
                <th class="nombre">Remise</th>
                <th class="nombre">{{ $horsTaxe ? 'Total HT' : 'Total TTC' }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lignes as $ligne)
                <tr>
                    <td>
                        {{ $ligne['description'] }}
                        @if ($ligne['reference'])
                            <span class="reference">Réf. {{ $ligne['reference'] }}</span>
                        @endif
                    </td>
                    <td class="nombre">{{ $ligne['quantite'] }}</td>
                    <td class="nombre">{{ $ligne['prix_unitaire'] }}</td>
                    <td class="nombre">{{ $ligne['remise'] ?? '—' }}</td>
                    <td class="nombre">{{ $ligne['total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totaux">
        <tr><td class="nombre">Total HT</td><td class="nombre">{{ $totalHt }}</td></tr>
        <tr><td class="nombre">TVA</td><td class="nombre">{{ $totalTva }}</td></tr>
        <tr><td class="nombre total">Total TTC</td><td class="nombre total">{{ $totalTtc }}</td></tr>
    </table>

    <h2>Votre réponse</h2>

    @if ($erreurs !== [])
        <div class="erreurs">
            @foreach ($erreurs as $erreur)
                <p>{{ $erreur }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ $actionAccepter }}">
        @csrf
        <fieldset>
            <legend>Accepter le devis</legend>
            <label class="champ" for="signataire">Votre nom et votre prénom</label>
            <input id="signataire" type="text" name="signataire" value="{{ $signataire }}" maxlength="{{ $signataireMaximum }}" autocomplete="name" required>
            <label class="case">
                <input type="checkbox" name="bon_pour_accord" value="1" required>
                <span>Bon pour accord</span>
            </label>
            <p class="rappel">En tapant votre nom et en cochant « Bon pour accord », vous acceptez ce devis tel qu'il est présenté ci-dessus. Votre réponse est enregistrée une seule fois, avec sa date et son heure, et une confirmation vous est envoyée par e-mail.</p>
            <button type="submit">Accepter le devis</button>
        </fieldset>
    </form>

    <form method="POST" action="{{ $actionRefuser }}">
        @csrf
        <fieldset>
            <legend>Refuser le devis</legend>
            <label class="champ" for="motif">Motif (facultatif)</label>
            <textarea id="motif" name="motif" rows="3" maxlength="{{ $motifMaximum }}">{{ $motif }}</textarea>
            <p class="rappel">Votre refus est enregistré une seule fois, et le magasin en est prévenu.</p>
            <button type="submit" class="refus">Refuser le devis</button>
        </fieldset>
    </form>
</main>
</body>
</html>
