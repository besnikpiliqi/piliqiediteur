{{-- Voir InvitationACreerUnEspaceMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Invitation à créer l'espace de votre cabinet

{{ $societe }} utilise {{ config('app.name') }} et vous invite à créer l'espace de votre cabinet d'expertise comptable, pour la suivre.

À l'inscription, le type « Cabinet d'expertise comptable » est déjà choisi : ce type sera définitif. Votre cabinet sera vérifié (code d'activité 69.20Z, inscription au tableau de l'Ordre) avant tout lien avec un client.

<x-mail::button :url="$lien">
Créer l'espace du cabinet
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
