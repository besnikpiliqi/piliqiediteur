{{-- Voir ConfirmationNouvelleAdresseMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
@if ($premiereAdresse)
# Confirmez votre adresse de connexion

Cette adresse vient d'être choisie comme adresse de connexion du propriétaire d'une société sur {{ config('app.name') }}. Elle ne servira à vous connecter et à recevoir un lien « Mot de passe oublié » qu'une fois ce lien suivi, en étant connecté à ce compte : d'ici là, connectez-vous avec votre identifiant de connexion, que montre votre profil, et votre mot de passe.
@else
# Confirmez votre nouvelle adresse

Cette adresse vient d'être demandée comme nouvelle adresse de connexion d'un compte {{ config('app.name') }}. Elle ne remplacera l'ancienne qu'une fois ce lien suivi.
@endif

<x-mail::button :url="$lien">
{{ $premiereAdresse ? 'Confirmer mon adresse' : 'Confirmer ma nouvelle adresse' }}
</x-mail::button>

Ce lien est valable jusqu'au {{ $expireLe }}. Si vous n'avez rien demandé, ignorez ce message : rien ne changera.

{{ config('app.name') }}
</x-mail::message>
