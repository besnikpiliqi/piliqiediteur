<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $devis->numero ?? 'Devis (brouillon)' }}</title>
    @include('documents.pdf.styles', ['pied' => $pied, 'tableau' => $tableau])
</head>
<body>
    {{-- Un devis modifié depuis son envoi est un brouillon qui garde son
         numéro et la date de son dernier envoi (voir ModifierDevisAction) :
         cette version n'a pas encore été émise, elle ne porte aucune date
         d'émission. $emetteur : la société et le magasin d'aujourd'hui, un
         devis n'étant pas signé au registre fiscal (voir DevisPdfGenerator) ;
         $pied : l'identité de la société, les conditions de paiement, la
         nature de l'opération et la franchise de TVA, s'il y a lieu (voir
         PiedDePage) ; $tableau : ses lignes, mesurées pour tenir dans la
         largeur de la page (voir TableauDesLignes). --}}
    @php($brouillon = $devis->statut === \App\Domain\Facturation\Enums\StatutDevis::Brouillon)

    @include('documents.pdf.pied', ['pied' => $pied])

    @include('documents.pdf.en-tete', [
        'emetteur' => $emetteur,
        'titre' => 'Devis',
        'details' => array_filter([
            match (true) {
                $devis->numero === null => 'Brouillon (non envoyé)',
                $brouillon => "{$devis->numero} (brouillon, modifié depuis son envoi)",
                default => $devis->numero,
            },
            $brouillon ? null : 'Émis le '.\App\Domain\Calendrier\Calendrier::deParis()->afficher($devis->date_emission, 'd/m/Y'),
            $devis->date_validite ? 'Valable jusqu\'au '.\App\Domain\Calendrier\Calendrier::afficherJour($devis->date_validite, 'd/m/Y') : null,
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

    @include('documents.pdf.lignes', ['document' => $devis, 'tableau' => $tableau])

    @include('documents.pdf.totaux', ['document' => $devis])

    @if($devis->notes)
        <div class="remarque">{{ $devis->notes }}</div>
    @endif

    <div class="accord">Date et signature du client, précédées de la mention « Bon pour accord »</div>
</body>
</html>
