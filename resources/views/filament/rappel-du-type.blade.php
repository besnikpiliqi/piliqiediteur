{{--
    Le rappel du type d'activité d'une société qui attend le code de son
    courrier recommandé (voir TypeDActivite) : le seul endroit de ce texte,
    que la page de la vérification inclut (voir VerificationSociete).

    @param \App\Domain\Tenancy\Models\Societe $societe
--}}
<p>Votre société est inscrite comme « {{ $societe->type_activite->getLabel() }} ». Si ce type est faux, ne saisissez pas le code : contactez l'éditeur, qui clôt l'inscription et libère votre SIREN.</p>
