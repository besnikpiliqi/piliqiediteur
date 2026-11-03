{{-- Voir TransmissionDeLaProprieteMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Transmission de la propriété

{{ $demandeur }} souhaite vous transmettre la propriété de {{ $societe }} sur {{ config('app.name') }}.

@if ($lien !== null)
Rien ne change tant que vous n'avez pas suivi ce lien : connectez-vous à votre compte avec votre identifiant, puis confirmez avec votre mot de passe. Cette adresse deviendra alors votre adresse de connexion.

<x-mail::button :url="$lien">
Accepter la propriété
</x-mail::button>

Ce lien est valable jusqu'au {{ $expireLe }}. Si vous ne souhaitez pas devenir propriétaire, ignorez ce message : rien ne changera.
@else
Cette adresse ne peut pas devenir votre adresse de connexion : renseignez une autre adresse dans votre profil, puis demandez à {{ $demandeur }} de vous transmettre de nouveau la propriété. Rien n'a changé.
@endif

{{ config('app.name') }}
</x-mail::message>
