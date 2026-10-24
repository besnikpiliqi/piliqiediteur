{{-- Voir CompteExistantMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Vous avez déjà un compte

Une inscription vient d'être demandée avec votre adresse e-mail, qui est déjà l'adresse de connexion du propriétaire d'une société sur {{ config('app.name') }} : aucune nouvelle inscription n'a été créée.

Une adresse e-mail n'est l'adresse de connexion que d'un seul propriétaire, même quand l'inscription de sa société est close : pour faire une nouvelle inscription, refaites la demande avec une autre adresse e-mail.

<x-mail::button :url="$urlDeConnexion">
Me connecter
</x-mail::button>

Mot de passe oublié ? [Recevoir un lien pour le changer]({{ $urlMotDePasseOublie }}).

Si vous n'avez rien demandé, ignorez ce message.

{{ config('app.name') }}
</x-mail::message>
