<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $devis->numero ?? 'Devis (brouillon)' }}</title>
    @include('documents.pdf.styles')
</head>
<body>
    {{-- Un devis modifié depuis son envoi est un brouillon qui garde son
         numéro et la date de son dernier envoi (voir ModifierDevisAction) :
         cette version n'a pas encore été émise, elle ne porte aucune date
         d'émission. $emetteur : le magasin d'aujourd'hui, un devis n'étant
         pas signé au registre fiscal (voir DevisPdfGenerator). --}}
    @php($brouillon = $devis->statut === \App\Domain\Facturation\Enums\StatutDevis::Brouillon)
    @include('documents.pdf.en-tete', [
        'emetteur' => $emetteur,
        'titre' => 'Devis',
        'details' => array_filter([
            match (true) {
                $devis->numero === null => 'Brouillon (non envoyé)',
                $brouillon => "{$devis->numero} (brouillon, modifié depuis son envoi)",
                default => $devis->numero,
            },
            $brouillon ? null : 'Émis le '.$devis->date_emission->format('d/m/Y'),
            $devis->date_validite ? 'Valable jusqu\'au '.$devis->date_validite->format('d/m/Y') : null,
        ]),
    ])

    {{-- La fiche du client d'aujourd'hui, présentée selon le type avec
         lequel le devis a été calculé, comme ses lignes (voir
         Devis::client()). --}}
    @include('documents.pdf.client', [
        'client' => \App\Domain\Facturation\Services\CopieClient::depuisClient($devis->client),
        'professionnel' => $devis->estPourUnProfessionnel(),
        'titre' => 'Établi pour',
    ])

    @include('documents.pdf.livraison', ['lignes' => $devis->lignesDeLivraison()])

    @include('documents.pdf.lignes', [
        'lignes' => $devis->lignes,
        'sansTvaFrancaise' => \App\Domain\Facturation\Services\ContexteDeVente::documentRetireLaTvaDuPrix($devis),
        'noteDeCalcul' => app(\App\Domain\Facturation\Services\MentionsFiscales::class)->calcul($devis),
    ])

    @include('documents.pdf.totaux', ['document' => $devis])

    @if($devis->conditions_paiement)
        <div class="pied">{{ $devis->conditions_paiement }}</div>
    @endif

    @if($devis->notes)
        <div class="pied">{{ $devis->notes }}</div>
    @endif

    {{-- La nature de l'opération et la franchise de TVA, s'il y a lieu :
         voir MentionsFiscales. --}}
    @foreach(app(\App\Domain\Facturation\Services\MentionsFiscales::class)->pour($devis, $emetteur) as $mention)
        <div class="pied">{{ $mention }}</div>
    @endforeach

    <div class="accord">Date et signature du client, précédées de la mention « Bon pour accord »</div>
</body>
</html>
