{{--
    La page du lien de 7 jours où un expert-comptable de la société choisit
    son mot de passe (voir LienDeLExpertComptableController) : l'ouvrir
    n'écrit rien. Elle montre la société, la personne, le cabinet déclaré et
    ce que la personne pourra consulter ou faire ; jamais l'identifiant,
    l'adresse ni le nom du propriétaire.

    @param string $nom
    @param string $societe
    @param string $siren
    @param string $cabinet
    @param string $sirenDuCabinet
    @param list<string> $autorisations
    @param list<string> $erreurs
    @param string $action
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Votre accès d'expert-comptable · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 40rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
        label.champ { display: block; margin: .75rem 0 .25rem; font-weight: 600; }
        input[type=password] { width: 100%; padding: .5rem; border: 1px solid #d1d5db; border-radius: .375rem; box-sizing: border-box; }
        .rappel { color: #4b5563; font-size: .9rem; }
        button { background: #4f46e5; color: #fff; border: 0; border-radius: .5rem; padding: .75rem 1.25rem; font-size: 1rem; cursor: pointer; margin-top: 1rem; }
        .refus-texte { color: #b91c1c; }
    </style>
</head>
<body>
<main>
    <h1>{{ config('app.name') }}</h1>

    <p>Bienvenue {{ $nom }}.</p>

    <p><strong>{{ $societe }}</strong> (SIREN {{ $siren }}) vous ouvre un accès d'expert-comptable sur {{ config('app.name') }}, comme personne du {{ $cabinet }}@unless (str_starts_with($cabinet, 'cabinet SIREN')) (SIREN {{ $sirenDuCabinet }})@endunless.</p>

    <p>Ce que vous pourrez consulter ou faire : {{ $autorisations === [] ? 'aucun document pour le moment' : implode(', ', $autorisations) }}.</p>

    <p>Choisissez votre mot de passe.</p>

    @foreach ($erreurs as $erreur)
        <p class="refus-texte">{{ $erreur }}</p>
    @endforeach

    <form method="POST" action="{{ $action }}">
        @csrf

        <label class="champ" for="password">Votre mot de passe<x-obligatoire /></label>
        <input id="password" type="password" name="password" autocomplete="new-password" required>
        <p class="rappel">Au moins 8 caractères ; ni votre identifiant, ni un seul caractère répété, ni une suite de chiffres.</p>
        <label class="champ" for="password_confirmation">Confirmez votre mot de passe<x-obligatoire /></label>
        <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>

        <button type="submit">Choisir mon mot de passe</button>
    </form>
</main>
</body>
</html>
