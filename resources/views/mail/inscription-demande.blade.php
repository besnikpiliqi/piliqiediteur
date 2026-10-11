{{-- Voir DemandeInscriptionMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Confirmez l'inscription de votre société

Vous avez demandé l'inscription de votre société. Pour la confirmer et créer votre compte, ouvrez ce lien puis cliquez sur « Confirmer l'inscription ».

<x-mail::button :url="$lien">
Confirmer l'inscription
</x-mail::button>

Ce lien est valable jusqu'au {{ $expireLe }} et ne sert qu'une fois. Si vous n'avez rien demandé, ignorez ce message : rien ne sera créé.

{{ config('app.name') }}
</x-mail::message>
