{{--
    La page du code du courrier recommandé (voir CodeDuCourrierController et
    SaisirCodeDeConfirmationAction), ouverte à tous : pour un courrier de
    vérification, le SIREN, le code, une adresse e-mail et un mot de passe ;
    pour le courrier d'une procédure de l'éditeur, le SIREN et le code.

    @param \App\Domain\Tenancy\Enums\MotifCode $motif le motif du courrier
    @param string|null $siren le SIREN déjà rempli par le QR code du courrier
--}}
@php($verification = $motif === \App\Domain\Tenancy\Enums\MotifCode::Verification)
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Saisir le code du courrier · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 32rem; margin: 2rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
        label { display: block; margin-top: 1rem; font-weight: 600; }
        input { width: 100%; box-sizing: border-box; padding: .6rem; margin-top: .25rem; border: 1px solid #d1d5db; border-radius: .5rem; font-size: 1rem; }
        button { margin-top: 1.5rem; background: #4f46e5; color: #fff; border: 0; border-radius: .5rem; padding: .75rem 1.25rem; font-size: 1rem; cursor: pointer; }
        .aide { color: #4b5563; font-size: .9rem; }
        .refus { color: #b91c1c; }
        .reconnu { color: #047857; font-weight: 600; }
    </style>
</head>
<body>
<main>
    <h1>{{ config('app.name') }} — code du courrier recommandé</h1>

    @if ($verification)
        <p>Saisissez le SIREN et le code imprimés sur le courrier recommandé. La personne qui saisit ce code devient propriétaire de l'inscription de la société.</p>
    @else
        <p>Objet du courrier : <strong>{{ $motif->getLabel() }}</strong>. Saisissez le SIREN et le code imprimés sur le courrier recommandé : l'éditeur poursuit ensuite la procédure et vous contacte.</p>
    @endif

    @if (session('code_reconnu'))
        <p class="reconnu">Le code est reconnu : l'éditeur poursuit la procédure et vous contacte.</p>
    @endif

    @if ($errors->any())
        <ul class="refus">
            @foreach ($errors->all() as $erreur)
                <li>{{ $erreur }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ $verification ? route('code.saisir') : route('code.procedure.saisir', ['objet' => $motif->value]) }}">
        @csrf

        <label for="siren">SIREN</label>
        <input id="siren" name="siren" inputmode="numeric" autocomplete="off" required value="{{ $siren }}">

        <label for="code">Code du courrier (XXXX-XXXX-XXXX)</label>
        <input id="code" name="code" autocomplete="one-time-code" required>

        @if ($verification)
            <p class="aide">Si vous avez inscrit la société, saisissez l'adresse e-mail et le mot de passe de votre inscription : vos comptes restent. Sinon, choisissez votre adresse e-mail et votre mot de passe : vous devenez propriétaire, et les comptes créés avant vous sont retirés de la société.</p>

            <label for="email">Adresse e-mail</label>
            <input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}">

            <label for="password">Mot de passe</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <label for="password_confirmation">Confirmation du mot de passe (si vous le choisissez maintenant)</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
        @endif

        <button type="submit">Valider</button>
    </form>
</main>
</body>
</html>
