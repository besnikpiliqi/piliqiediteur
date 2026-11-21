{{-- Voir LienDuMotDePasseDeLExpertComptableMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Votre accès d'expert-comptable

Bonjour {{ $nom }},

{{ $societe }} (SIREN {{ $siren }}) vous a déclaré comme son expert-comptable, du {{ $cabinet }}.
@if ($accesRedonnePar !== null)

Votre accès a été redonné par {{ $accesRedonnePar }} : votre mot de passe d'avant ne sert plus.
@endif

Choisissez votre mot de passe avec ce lien, valable jusqu'au {{ $expireLe }} (heure de Paris). Votre identifiant de connexion vous sera montré sur la page, une fois votre mot de passe choisi : il n'est jamais envoyé par e-mail.

<x-mail::button :url="$lien">
Choisir mon mot de passe
</x-mail::button>

Ce que vous pourrez consulter ou faire dans ses magasins : {{ $autorisations === [] ? 'aucun document pour le moment' : implode(', ', $autorisations) }}.

Le jour où votre cabinet s'inscrit et est vérifié sur {{ config('app.name') }}, vous accéderez à {{ $societe }} par l'espace du cabinet, et ce compte sera fermé.

Si vous n'êtes pas la personne visée, ignorez cet e-mail.

{{ config('app.name') }}
</x-mail::message>
