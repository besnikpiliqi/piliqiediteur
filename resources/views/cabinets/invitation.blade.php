{{--
    L'invitation d'un cabinet d'expertise comptable (voir
    AccepterLInvitationDuCabinetController) : l'ouverture du lien n'écrit
    rien. La page montre le cabinet qui invite, l'entreprise invitée telle
    que le répertoire Sirene la donne, les autorisations que le cabinet
    recevra par défaut et ce que fait l'acceptation ; le formulaire fait
    choisir le mot de passe et le type d'activité (jamais « Cabinet
    d'expertise comptable »), sans valeur par défaut, avec les
    avertissements du code d'activité et la case du choix définitif ; le
    refus est un bouton à part. Une entreprise cessée ou inconnue, ou un
    répertoire injoignable, ne montre que son motif.

    @param \App\Domain\Tenancy\Models\Societe $cabinet
    @param string $siren
    @param string|null $denomination
    @param string|null $motif
    @param list<string> $erreurs
    @param list<\App\Domain\Tenancy\Enums\TypeDActivite> $types
    @param \App\Domain\Tenancy\Enums\TypeDActivite|null $typeChoisi
    @param list<string> $autorisationsCommunes
    @param list<string> $autorisationsDesArticles
    @param list<array{texte: string, types: list<\App\Domain\Tenancy\Enums\TypeDActivite>}> $avertissements
    @param \Carbon\CarbonInterface $expireLe
    @param string $action
    @param string $actionDuRefus
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Invitation de votre cabinet comptable · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 40rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
        fieldset { border: 1px solid #e5e7eb; border-radius: .5rem; margin: 1rem 0; padding: 1rem; }
        legend { font-weight: 600; padding: 0 .25rem; }
        label.champ { display: block; margin: .75rem 0 .25rem; font-weight: 600; }
        input[type=password] { width: 100%; padding: .5rem; border: 1px solid #d1d5db; border-radius: .375rem; box-sizing: border-box; }
        .type { display: flex; gap: .75rem; align-items: flex-start; margin-bottom: 1rem; }
        .type small { display: block; color: #4b5563; margin-top: .25rem; }
        .rappel { color: #4b5563; font-size: .9rem; }
        .avertissements { background: #fffbeb; border: 1px solid #fcd34d; border-radius: .5rem; padding: .75rem 1rem; }
        .avertissements li { margin-bottom: .5rem; }
        .case { display: flex; gap: .5rem; align-items: flex-start; margin: 1rem 0; font-weight: 600; }
        button { background: #4f46e5; color: #fff; border: 0; border-radius: .5rem; padding: .75rem 1.25rem; font-size: 1rem; cursor: pointer; }
        button.refus { background: #fff; color: #b91c1c; border: 1px solid #b91c1c; }
        .refus-texte { color: #b91c1c; }
    </style>
</head>
<body>
<main>
    <h1>{{ config('app.name') }}</h1>

    <p>Le cabinet d'expertise comptable <strong>{{ $cabinet->raison_sociale ?? 'qui vous invite' }}</strong>@if ($cabinet->siege_ville) ({{ $cabinet->siege_ville }})@endif vous invite à créer l'espace de votre entreprise sur {{ config('app.name') }}.</p>

    <p>Entreprise invitée : SIREN <strong>{{ $siren }}</strong>@if ($denomination) · <strong>{{ $denomination }}</strong>@endif</p>

    @if ($motif !== null)
        <p class="refus-texte">{{ $motif }}</p>
    @else
        <p>En acceptant, votre société est créée et validée aussitôt, sans courrier recommandé ni QR code : l'entreprise est contrôlée active au répertoire Sirene, et son identité (dénomination, forme juridique, capital, immatriculation, siège) est lue au registre national des entreprises, à défaut au répertoire Sirene. Votre compte prend le nom du représentant légal inscrit au registre (à défaut, la dénomination de l'entreprise), que vous changerez dans votre profil s'il le faut. Vous complétez ensuite l'identité de votre société dans son espace.</p>

        <p>Le cabinet recevra, par défaut, ces autorisations de consultation dans vos magasins : {{ implode(', ', $autorisationsCommunes) }}@if ($autorisationsDesArticles !== []), et, pour une société « {{ \App\Domain\Tenancy\Enums\TypeDActivite::Commerce->getLabel() }} », {{ implode(', ', $autorisationsDesArticles) }}@endif. Vous les réglerez à tout moment, et pourrez mettre fin au lien. Jamais la vente, l'encaissement, la caisse, les comptes ni les réglages de la société.</p>

        <p class="rappel">Cette invitation expire le {{ \App\Domain\Calendrier\Calendrier::deParis()->afficher($expireLe, 'd/m/Y à H:i') }}.</p>

        @foreach ($erreurs as $erreur)
            <p class="refus-texte">{{ $erreur }}</p>
        @endforeach

        <form method="POST" action="{{ $action }}">
            @csrf

            <label class="champ" for="password">Votre mot de passe<x-obligatoire /></label>
            <input id="password" type="password" name="password" autocomplete="new-password" required>
            <label class="champ" for="password_confirmation">Confirmez votre mot de passe<x-obligatoire /></label>
            <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>

            <fieldset>
                <legend>Votre activité<x-obligatoire /></legend>

                @foreach ($types as $type)
                    <label class="type">
                        <input type="radio" name="type_activite" value="{{ $type->value }}" @checked($typeChoisi === $type) required>
                        <span>
                            <strong>{{ $type->getLabel() }}</strong>
                            <small>{{ $type->exemples() }}</small>
                            <small>{{ $type->aide() }}</small>
                        </span>
                    </label>
                @endforeach

                <p class="rappel">{{ \App\Domain\Tenancy\Enums\TypeDActivite::rappelDuChoixDefinitif() }}</p>
            </fieldset>

            @if ($avertissements !== [])
                <div class="avertissements">
                    <p><strong>D'après le code d'activité de votre entreprise au répertoire Sirene :</strong></p>
                    <ul>
                        @foreach ($avertissements as $avertissement)
                            <li>Pour {{ implode(' ou ', array_map(fn (\App\Domain\Tenancy\Enums\TypeDActivite $type): string => '« '.$type->getLabel().' »', $avertissement['types'])) }} : {{ $avertissement['texte'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <label class="case">
                <input type="checkbox" name="choix_definitif" value="1" required>
                <span>{{ \App\Http\Controllers\AccepterLInvitationDuCabinetController::CASE_DU_CHOIX_DEFINITIF }}<x-obligatoire /></span>
            </label>

            <button type="submit">Accepter l'invitation</button>
        </form>
    @endif

    <form method="POST" action="{{ $actionDuRefus }}" onsubmit="return confirm('Refuser l\'invitation de ce cabinet ? Aucune société ne sera créée.');" style="margin-top: 2rem;">
        @csrf
        <button type="submit" class="refus">Refuser l'invitation</button>
    </form>
</main>
</body>
</html>
