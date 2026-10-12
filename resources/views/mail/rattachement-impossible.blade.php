{{-- Voir RattachementImpossibleMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Une société souhaite vous rattacher à son équipe

{{ $societe }} souhaite vous rattacher à son équipe sur {{ config('app.name') }}, mais votre compte appartient encore à une société : un compte n'appartient qu'à une seule société.

Pour la rejoindre avec ce compte, demandez d'abord à votre société actuelle de vous retirer de son équipe ; {{ $societe }} pourra ensuite vous adresser une proposition.

Si vous ne connaissez pas cette société, ignorez ce message.

{{ config('app.name') }}
</x-mail::message>
