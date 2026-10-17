{{--
    La création de sa propre société avec un compte retiré (voir
    CreerSaSocieteController) : l'adresse et le mot de passe du compte sont
    redemandés, avec le SIREN de l'entreprise.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Créer votre société · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 34rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
        h2 { font-size: 1rem; margin-top: 1.5rem; }
        label { display: block; margin-top: .75rem; font-weight: 600; }
        input { width: 100%; box-sizing: border-box; padding: .5rem; border: 1px solid #d1d5db; border-radius: .5rem; font-size: 1rem; }
        button { background: #4f46e5; color: #fff; border: 0; border-radius: .5rem; padding: .75rem 1.25rem; font-size: 1rem; cursor: pointer; margin-top: 1.25rem; }
        .refus { color: #b91c1c; }
        .petit { font-size: .875rem; color: #4b5563; }
    </style>
</head>
<body>
<main>
    <h1>Créer votre société sur {{ config('app.name') }}</h1>

    <p>Votre compte n'appartient plus à aucune société : créez la vôtre avec lui. Votre société sera vérifiée avant toute vente réelle ; vous créerez ensuite vos magasins dans son espace, en choisissant leurs établissements.</p>

    @foreach ($refus as $message)
        <p class="refus">{{ $message }}</p>
    @endforeach

    <form method="POST" action="{{ route('societe.creer.enregistrer') }}">
        @csrf
        <h2>Votre compte</h2>
        <label for="email">Adresse e-mail</label>
        <input id="email" name="email" type="email" autocomplete="username" value="{{ $saisie['email'] ?? '' }}" required>
        <label for="password">Mot de passe</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>

        <h2>Votre société</h2>
        <label for="siren">SIREN de la société</label>
        <input id="siren" name="siren" inputmode="numeric" value="{{ $saisie['siren'] ?? '' }}" required>

        <button type="submit">Créer ma société</button>
    </form>

    <p class="petit">Mot de passe oublié ? Demandez un lien depuis la page de connexion : choisir votre mot de passe confirme votre adresse.</p>
</main>
</body>
</html>
