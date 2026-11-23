<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $devis->numero ?? 'Devis (brouillon)' }}</title>
    @include('documents.pdf.styles', ['pied' => $pied, 'tableau' => $tableau])
</head>
<body>
    {{-- $emetteur : celui que le devis a figé à son dernier envoi, la
         société et le magasin d'aujourd'hui pour un brouillon (voir
         Devis::emetteur()) ; $pied : l'identité de la société, les
         conditions de paiement, la nature de l'opération et la franchise de
         TVA, s'il y a lieu (voir PiedDePage), placé avant le contenu pour
         que chaque page le reprenne ; $tableau : ses lignes, mesurées pour
         tenir dans la largeur de la page (voir TableauDesLignes) ;
         $depannage : ses mentions de dépannage à domicile, null sans elles ;
         $acceptation : son acceptation en ligne, null sans elle ;
         $retractation : le droit de rétractation d'un devis de dépannage
         signé chez le client, null sinon (voir DevisPdfGenerator). Le devis
         lui-même est celui que montre la page du lien (voir
         devis/document). --}}
    @include('documents.pdf.pied', ['pied' => $pied])

    @include('devis.document', ['devis' => $devis, 'emetteur' => $emetteur, 'tableau' => $tableau, 'depannage' => $depannage, 'avecLePuHtExact' => true])

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
