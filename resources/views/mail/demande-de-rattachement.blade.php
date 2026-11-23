{{-- Voir DemandeDeRattachementMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Demande de votre cabinet comptable

Le cabinet {{ $cabinet }}@if ($villeDuCabinet !== null) ({{ $villeDuCabinet }})@endif demande à suivre sur {{ config('app.name') }} l'entreprise de SIREN {{ $siren }}, qui y est déjà inscrite.

Seul le propriétaire de cette entreprise, connecté à son compte, répond à cette demande : accepter avec les autorisations par défaut ({{ implode(', ', $autorisations) }}), accepter en les ajustant, ou refuser.

<x-mail::button :url="$lien">
Répondre à la demande
</x-mail::button>

Ce lien est valable jusqu'au {{ $expireLe }}.

{{ config('app.name') }}
</x-mail::message>
