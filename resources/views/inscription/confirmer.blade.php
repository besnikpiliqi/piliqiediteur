{{--
    La confirmation d'une inscription (voir ConfirmerInscriptionController) :
    l'ouverture du lien ne consomme rien, seul le bouton confirme et fait
    partir le courrier recommandé. Le formulaire rappelle le SIREN et le type
    d'activité choisi, le laisse encore changer, montre les avertissements du
    code d'activité de l'entreprise avec les types qu'ils concernent, sans
    script, et exige la case du choix définitif. Un lien expiré, déjà servi
    ou inconnu dit seulement qu'il n'est plus valable.

    @param bool $valable
    @param string|null $refus
    @param string $action
    @param string|null $siren
    @param \App\Domain\Tenancy\Enums\TypeDActivite|null $typeChoisi
    @param list<array{texte: string, types: list<\App\Domain\Tenancy\Enums\TypeDActivite>}> $avertissements
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Confirmer l'inscription · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 40rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
        fieldset { border: 1px solid #e5e7eb; border-radius: .5rem; margin: 1rem 0; padding: 1rem; }
        legend { font-weight: 600; padding: 0 .25rem; }
        .type { display: flex; gap: .75rem; align-items: flex-start; margin-bottom: 1rem; }
        .type small { display: block; color: #4b5563; margin-top: .25rem; }
        .rappel { color: #4b5563; font-size: .9rem; }
        .avertissements { background: #fffbeb; border: 1px solid #fcd34d; border-radius: .5rem; padding: .75rem 1rem; }
        .avertissements li { margin-bottom: .5rem; }
        .case { display: flex; gap: .5rem; align-items: flex-start; margin: 1rem 0; font-weight: 600; }
        button { background: #4f46e5; color: #fff; border: 0; border-radius: .5rem; padding: .75rem 1.25rem; font-size: 1rem; cursor: pointer; }
        .refus { color: #b91c1c; }
    </style>
</head>
<body>
<main>
    <h1>{{ config('app.name') }}</h1>

    @if ($valable)
        <p>Confirmez l'inscription de votre société : votre société et votre compte seront créés, et un courrier recommandé partira aussitôt au représentant légal de votre entreprise, à l'adresse officielle de son siège, avec le code qui valide l'inscription. Vous serez connecté à l'espace de votre société, où vous la configurerez en attendant le courrier.</p>

        <p>SIREN : <strong>{{ $siren }}</strong>@if ($typeChoisi !== null) · type d'activité choisi : <strong>{{ $typeChoisi->getLabel() }}</strong>@endif</p>

        @if ($refus !== null)
            <p class="refus">{{ $refus }}</p>
        @endif

        <form method="POST" action="{{ $action }}">
            @csrf

            <fieldset>
                <legend>Votre activité</legend>

                @foreach (\App\Domain\Tenancy\Enums\TypeDActivite::cases() as $type)
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
                <span>{{ \App\Http\Controllers\ConfirmerInscriptionController::CASE_DU_CHOIX_DEFINITIF }}</span>
            </label>

            <button type="submit">Confirmer l'inscription</button>
        </form>
    @else
        <p class="refus">{{ \App\Http\Controllers\ConfirmerInscriptionController::MESSAGE_LIEN_INVALIDE }}</p>
    @endif
</main>
</body>
</html>
