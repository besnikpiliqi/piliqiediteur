{{-- Voir CompteExistantMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Vous avez déjà un compte

Une inscription vient d'être demandée avec votre adresse e-mail, qui est déjà celle d'un compte {{ config('app.name') }} : aucune nouvelle inscription n'a été créée.

<x-mail::button :url="$urlDeConnexion">
Me connecter
</x-mail::button>

Mot de passe oublié ? [Recevoir un lien pour le changer]({{ $urlMotDePasseOublie }}).

Si vous n'avez rien demandé, ignorez ce message.

{{ config('app.name') }}
</x-mail::message>
