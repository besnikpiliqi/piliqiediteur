{{-- Voir CompteExistantMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Vous avez déjà un compte

Une inscription vient d'être demandée avec votre adresse e-mail, qui est déjà celle d'un compte {{ config('app.name') }} : aucune nouvelle inscription n'a été créée.

@if ($urlPourCreerSaSociete !== null)
Ce compte n'appartient plus à aucune société : vous pouvez créer votre propre société avec lui. Choisissez d'abord votre mot de passe par ce lien, valable {{ $minutesDeValidite }} minutes, qui confirme votre adresse ; vous saisirez ensuite votre SIREN, puis créerez vos magasins dans l'espace de votre société.

<x-mail::button :url="$urlPourCreerSaSociete">
Créer votre propre société avec ce compte
</x-mail::button>
@else
<x-mail::button :url="$urlDeConnexion">
Me connecter
</x-mail::button>

Mot de passe oublié ? [Recevoir un lien pour le changer]({{ $urlMotDePasseOublie }}).
@endif

Si vous n'avez rien demandé, ignorez ce message.

{{ config('app.name') }}
</x-mail::message>
