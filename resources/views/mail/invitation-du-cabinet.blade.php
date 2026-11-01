{{-- Voir InvitationDuCabinetMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Invitation de votre cabinet comptable

Le cabinet {{ $cabinet }}@if ($villeDuCabinet !== null) ({{ $villeDuCabinet }})@endif vous invite à créer sur {{ config('app.name') }} l'espace de l'entreprise de SIREN {{ $siren }}.

En acceptant, vous choisissez votre mot de passe et le type d'activité de la société, qui sera définitif. La société sera validée sans courrier ni QR code, et ses données légales seront lues au registre national des entreprises. Vous en serez le propriétaire.

Par défaut, le cabinet consultera vos magasins réels : {{ implode(', ', $autorisations) }}. Vous pourrez retirer ces autorisations ensuite, dans « Cabinet comptable ».

<x-mail::button :url="$lien">
Voir l'invitation
</x-mail::button>

La même page permet de refuser l'invitation. Ce lien est valable jusqu'au {{ $expireLe }}.

{{ config('app.name') }}
</x-mail::message>
