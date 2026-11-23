{{-- Voir LienDeLaDemonstrationMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Votre démonstration

Bonjour, voici le lien de la démonstration de votre société. Il ouvre une société fictive, sans votre identifiant ni votre mot de passe : gardez-le pour vous et votre équipe. Il marche jusqu'au {{ $fin }}. Repère de votre démonstration : {{ $repere }}.

<x-mail::button :url="$adresse">
Ouvrir ma démonstration
</x-mail::button>

Si ce lien a été donné à quelqu'un par erreur, renouvelez-le depuis votre compte : l'ancien ne marchera plus.

{{ $phraseDuSecret }}

{{ config('app.name') }}
</x-mail::message>
