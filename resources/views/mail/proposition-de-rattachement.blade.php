{{-- Voir PropositionDeRattachementMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Une société vous propose de rejoindre son équipe

{{ $nomDeLAuteur }} vous propose de rejoindre {{ $societe }} sur {{ config('app.name') }}, avec votre compte actuel : {{ $role }}.

<x-mail::button :url="$lien">
Voir la proposition
</x-mail::button>

Pour l'accepter, vous redonnerez l'adresse et le mot de passe de votre compte ; vous pouvez aussi la refuser. Ce lien est valable jusqu'au {{ $expireLe }}.

Si vous ne connaissez pas cette société, ignorez ce message.

{{ config('app.name') }}
</x-mail::message>
