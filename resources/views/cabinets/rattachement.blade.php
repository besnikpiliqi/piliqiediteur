{{--
    Le lien d'une demande de rattachement, ouvert sans compte connecté (voir
    RattachementAuCabinetController) : le cabinet qui demande et le SIREN
    qu'il a invité, rien d'autre de la société, et le bouton qui mène à la
    connexion de son propriétaire, d'où il revient ici.

    @param \App\Domain\Tenancy\Models\Societe $cabinet
    @param string $siren
    @param string $connexion
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Demande de rattachement · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 40rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
        a.bouton { display: inline-block; background: #4f46e5; color: #fff; border-radius: .5rem; padding: .75rem 1.25rem; text-decoration: none; }
    </style>
</head>
<body>
<main>
    <h1>{{ config('app.name') }}</h1>

    <p>Le cabinet d'expertise comptable <strong>{{ $cabinet->raison_sociale ?? 'qui vous écrit' }}</strong>@if ($cabinet->siege_ville) ({{ $cabinet->siege_ville }})@endif demande à suivre l'entreprise du SIREN <strong>{{ $siren }}</strong>, déjà inscrite sur {{ config('app.name') }}.</p>

    <p>Seul le propriétaire de l'entreprise, connecté à son compte, répond à cette demande : il l'accepte avec les autorisations par défaut, l'accepte en les ajustant, ou la refuse.</p>

    <p><a class="bouton" href="{{ $connexion }}">Se connecter pour répondre</a></p>
</main>
</body>
</html>
