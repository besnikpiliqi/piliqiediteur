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
         d'émission. $emetteur : celui que le devis a figé à son dernier
         envoi, la société et le magasin d'aujourd'hui pour un brouillon
         (voir Devis::emetteur()) ;
         $pied : l'identité de la société, les conditions de paiement, la
         nature de l'opération et la franchise de TVA, s'il y a lieu (voir
         PiedDePage) ; $tableau : ses lignes, mesurées pour tenir dans la
         largeur de la page (voir TableauDesLignes) ; $depannage : ses
         mentions de dépannage à domicile, null sans elles ; $acceptation :
         son acceptation en ligne, null sans elle ; $retractation : le droit
         de rétractation d'un devis de dépannage signé chez le client, null
         sinon (voir DevisPdfGenerator). --}}
    @php($brouillon = $devis->statut === \App\Domain\Facturation\Enums\StatutDevis::Brouillon)

    @include('documents.pdf.pied', ['pied' => $pied])

    @include('documents.pdf.en-tete', [
        'emetteur' => $emetteur,
        'titre' => 'Devis',
        'classeTitre' => 'majuscules',
        'details' => array_filter([
            match (true) {
                $devis->numero === null => 'Brouillon (non envoyé)',
                $brouillon => "N° {$devis->numero} (brouillon, modifié depuis son envoi)",
                default => 'N° '.$devis->numero,
            },
            $brouillon ? null : 'Émis le '.$devis->magasin->calendrier()->afficher($devis->date_emission, 'd/m/Y'),
            $devis->date_validite ? 'Valable jusqu\'au '.\App\Domain\Calendrier\Calendrier::afficherJour($devis->date_validite, 'd/m/Y') : null,
        ]),
    ])

    {{-- La fiche du client d'aujourd'hui, présentée selon le type avec
         lequel le devis a été calculé, comme ses lignes (voir
         Devis::client()). --}}
    @include('documents.pdf.blocs', [
        'client' => \App\Domain\Facturation\Services\CopieClient::depuisClient($devis->client),
        'professionnel' => $devis->estPourUnProfessionnel(),
        'titre' => 'Établi pour',
        'titreDesConditions' => 'Conditions',
        'conditions' => [$devis->date_validite ? 'Valable jusqu\'au '.\App\Domain\Calendrier\Calendrier::afficherJour($devis->date_validite, 'd/m/Y') : 'Sans date de validité'],
    ])

    {{-- Le dépannage, la réparation ou l'entretien à domicile d'un
         particulier, quand le magasin l'a coché (voir DepannageADomicile) :
         le lieu de l'intervention, le prix du devis et les pièces
         remplacées. --}}
    @if($depannage !== null)
        <div class="bloc-client">
            <div class="titre">Dépannage, réparation ou entretien à domicile</div>
            Lieu de l'intervention : {{ $depannage->lieu ?? '—' }}<br>
            {{ $depannage->mentionDuPrixDuDevis() }}<br>
            {{ \App\Domain\Facturation\Services\DepannageADomicile::PIECES_REMPLACEES }}
            @if($depannage->signeChezLeClient)
                <br>Devis signé chez le client : votre droit de rétractation et son formulaire figurent à la fin de ce devis.
            @endif
        </div>
    @endif

    @include('documents.pdf.livraison', ['lignes' => $devis->lignesDeLivraison()])

    @include('documents.pdf.lignes', ['document' => $devis, 'tableau' => $tableau])

    @include('documents.pdf.totaux', ['document' => $devis, 'titreDuMontant' => 'Montant du devis', 'libelleDuTotal' => 'Total TTC'])

    @if($devis->notes)
        <div class="remarque">{{ $devis->notes }}</div>
    @endif

    {{-- Le cadre « Bon pour accord » (modèle D1 choisi par l'éditeur le
         14/11/2026) : vide pour une signature à la main ; rempli quand le
         client a accepté en ligne la version que le devis imprime (voir
         Devis::reponseALaVersionEnvoyee()), avec la date et l'heure de son
         acceptation, le nom qu'il a tapé, la référence de l'acceptation et
         le début de l'empreinte du devis accepté. Jamais l'adresse IP ni le
         navigateur. --}}
    <div class="accord{{ $acceptation === null ? '' : ' accepte' }}">
        <div>Date et signature du client, précédées de la mention « Bon pour accord »</div>
        @if($acceptation !== null)
            <div class="cachet-accepte">Accepté en ligne</div>
            <div class="bon-pour-accord">Bon pour accord</div>
            <div>Le <strong>{{ $acceptation['quand'] }}</strong> ({{ $acceptation['heure'] }})</div>
            <div class="signataire">{{ $acceptation['signataire'] }}</div>
            <div class="trait-de-signature">Signature électronique : nom tapé et case « Bon pour accord » cochée par le client, sur le lien personnel reçu à son adresse e-mail</div>
            <div class="reference-d-acceptation">Référence de l'acceptation : {{ $acceptation['reference'] }} · Empreinte du devis accepté : {{ $acceptation['empreinte'] }}</div>
        @endif
    </div>

    {{-- Un devis de dépannage signé chez le client : un contrat conclu hors
         établissement, qui donne au client particulier quatorze jours de
         rétractation (art. L. 221-5, L. 221-9 et L. 221-18 du code de la
         consommation) ; l'information et le formulaire type, sur une page à
         part qui se détache. --}}
    @if($retractation !== null)
        <div class="retractation">
            <div class="titre-section">{{ \App\Domain\Facturation\Services\DroitDeRetractation::TITRE }}</div>
            @foreach($retractation->paragraphes() as $paragraphe)
                <p>{{ $paragraphe }}</p>
            @endforeach
            <div class="formulaire-de-retractation">
                <div class="titre-section">{{ \App\Domain\Facturation\Services\DroitDeRetractation::TITRE_DU_FORMULAIRE }}</div>
                @foreach($retractation->formulaire($devis->numero) as $ligne)
                    <p>{{ $ligne }}</p>
                @endforeach
            </div>
        </div>
    @endif
</body>
</html>
