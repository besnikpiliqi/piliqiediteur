{{-- Voir InscriptionCloseMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# L'inscription de votre société est close

L'inscription de {{ $societe }} sur {{ config('app.name') }} est close : {{ $motif }}. Ses magasins restent consultables, en lecture seule.

Cette adresse e-mail n'est plus une adresse de connexion : elle peut servir à une nouvelle inscription.

<x-mail::button :url="$urlInscription">
Inscrire une société
</x-mail::button>

Le compte de l'inscription close ne s'ouvre plus qu'avec son identifiant de connexion, que montre son profil, et son mot de passe. Si vous ne connaissez pas cet identifiant, contactez l'éditeur de {{ config('app.name') }}.

{{ config('app.name') }}
</x-mail::message>
