{{-- Voir AdresseChangeeMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Votre adresse de connexion a changé

Bonjour {{ $nom }},

Le {{ $changeeLe }}, une nouvelle adresse de connexion, confirmée par le lien qui lui avait été envoyé, a remplacé celle-ci pour votre compte {{ config('app.name') }}. Cette adresse ne sert plus à vous connecter.

Si vous n'êtes pas à l'origine de ce changement, quelqu'un connaît votre mot de passe : prévenez sans attendre votre société, ou l'éditeur de {{ config('app.name') }} si vous en êtes le propriétaire.

{{ config('app.name') }}
</x-mail::message>
