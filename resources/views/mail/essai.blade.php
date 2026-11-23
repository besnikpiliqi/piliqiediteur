{{-- Voir CourrielDEssai. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Essai d'envoi

Cet e-mail d'essai a été envoyé par la commande « php artisan courriel:essayer », par le transport « {{ $transport }} », le {{ $envoyeLe }}.

S'il vous est parvenu, le serveur d'envoi des e-mails de {{ config('app.name') }} fonctionne.

{{ config('app.name') }}
</x-mail::message>
