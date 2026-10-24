{{-- Voir ConfirmationNouvelleAdresseMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Confirmez votre nouvelle adresse

Cette adresse vient d'être demandée comme nouvelle adresse de connexion d'un compte {{ config('app.name') }}. Elle ne remplacera l'ancienne qu'une fois ce lien suivi.

<x-mail::button :url="$lien">
Confirmer ma nouvelle adresse
</x-mail::button>

Ce lien est valable jusqu'au {{ $expireLe }}. Si vous n'avez rien demandé, ignorez ce message : rien ne changera.

{{ config('app.name') }}
</x-mail::message>
