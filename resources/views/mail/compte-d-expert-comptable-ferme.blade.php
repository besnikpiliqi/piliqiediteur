{{-- Voir CompteDExpertComptableFermeMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Votre compte chez {{ $societe }} est fermé

Bonjour {{ $nom }},

Votre cabinet {{ $cabinet }} est désormais inscrit et vérifié sur {{ config('app.name') }}, et relié à {{ $societe }} : le compte que {{ $societe }} vous avait créé est fermé.

Vous accéderez à {{ $societe }} depuis l'espace de votre cabinet, dès que son propriétaire vous confiera ce client.

Si votre cabinet n'a pas ouvert d'espace sur {{ config('app.name') }}, prévenez aussitôt {{ $societe }} et l'éditeur de {{ config('app.name') }}@if ($editeur !== null), à {{ $editeur }}@endif : quelqu'un s'est peut-être inscrit à sa place.

{{ config('app.name') }}
</x-mail::message>
