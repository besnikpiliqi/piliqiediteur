{{-- Voir DemandeAccordDirigeantMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# Une demande d'accord vous attend

Bonjour {{ $nomDuDirigeant }},

{{ $nomDuDemandeur }} vous demande l'autorisation de gérer le compte {{ config('app.name') }} de {{ $societe }} (SIREN {{ $siren }}), dont vous êtes dirigeant au registre national des entreprises.

<x-mail::button :url="$lien">
Examiner la demande
</x-mail::button>

Pour l'accepter, vous vérifierez votre identité en ligne auprès d'un prestataire certifié ; vous pouvez aussi la refuser. Ce lien est valable jusqu'au {{ $expireLe }}.

**Vos données.** {{ config('app.name') }} (l'éditeur, contact : {{ $editeur }}) a lu votre nom, vos prénoms, votre rôle et le mois et l'année de votre naissance au registre national des entreprises, et votre adresse e-mail lui a été donnée par {{ $nomDuDemandeur }}, pour vérifier qu'une personne a le droit d'agir pour votre entreprise. Ces données et le résultat de votre vérification d'identité, si vous l'acceptez, se gardent au plus cinq ans après la fin de la relation avec votre entreprise. Vous pouvez y accéder, les faire rectifier ou vous opposer à leur traitement en écrivant à l'éditeur.

Si vous ne connaissez pas cette demande, ignorez ce message ou signalez-le à l'éditeur.

{{ config('app.name') }}
</x-mail::message>
