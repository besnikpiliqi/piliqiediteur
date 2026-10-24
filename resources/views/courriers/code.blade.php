{{--
    Le courrier recommandé à code (voir CourrierDeCode) : une page A4 en
    portrait, le destinataire à la place de la fenêtre d'une enveloppe.

    @param \App\Integrations\EnvoiRecommande\DestinataireDuCourrier $destinataire
    @param string $siren
    @param \App\Domain\Tenancy\Enums\MotifCode $motif
    @param string $code
    @param string $expireLe le jour et l'heure (heure de Paris) où le code expire
    @param string $date
    @param string $adresseCourte l'adresse de la page du code de ce motif
    @param string $qrCode l'URI data: du QR code de la page
    @param string $application
    @param string|null $editeur l'adresse e-mail de l'éditeur
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 portrait; margin: 0; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1f2933; margin: 0; }
        .expediteur { position: absolute; top: 15mm; left: 20mm; width: 80mm; font-size: 9pt; }
        .destinataire { position: absolute; top: 50mm; left: 110mm; width: 85mm; font-size: 11pt; line-height: 1.35; }
        .remise { position: absolute; top: 90mm; left: 20mm; width: 170mm; font-weight: bold; font-size: 9pt; text-transform: uppercase; }
        .corps { position: absolute; top: 102mm; left: 20mm; width: 170mm; line-height: 1.45; }
        .code { margin: 5mm 0; padding: 4mm; border: 1pt solid #1f2933; text-align: center; font-size: 18pt; font-weight: bold; }
        .qr { float: right; width: 38mm; height: 38mm; margin: 0 0 3mm 6mm; }
        p { margin: 0 0 3mm 0; }
    </style>
</head>
<body>
    <div class="expediteur">
        <strong>{{ $application }}</strong><br>
        Éditeur du logiciel {{ $application }}@if (filled($editeur))<br>{{ $editeur }}@endif
    </div>

    <div class="destinataire">
        @foreach ($destinataire->lignes() as $ligne)
            {{ $ligne }}<br>
        @endforeach
    </div>

    <div class="remise">Lettre recommandée avec avis de réception — à remettre au destinataire en personne</div>

    <div class="corps">
        <p>Le {{ $date }}</p>
        <p><strong>Objet : {{ $motif->getLabel() }} sur {{ $application }} — SIREN {{ $siren }}</strong></p>

        @if ($motif === \App\Domain\Tenancy\Enums\MotifCode::Verification)
            <p>Madame, Monsieur,</p>
            <p>L'inscription de l'entreprise {{ $destinataire->adresse->denomination ?? 'de SIREN '.$siren }} (SIREN {{ $siren }}) a été demandée sur {{ $application }}. Ce courrier vous est adressé, en qualité de représentant légal inscrit au registre national des entreprises, pour confirmer que cette inscription est bien celle de votre entreprise.</p>
        @else
            <p>Madame, Monsieur,</p>
            <p>Ce courrier vous est adressé par l'éditeur de {{ $application }}, en qualité de représentant légal de l'entreprise de SIREN {{ $siren }} inscrit au registre national des entreprises, pour la procédure suivante : {{ $motif->getLabel() }}.</p>
        @endif

        <div class="code">{{ $code }}</div>

        <img class="qr" src="{{ $qrCode }}" alt="QR code de la page du code">

        <p>Ce code est valable jusqu'au {{ $expireLe }} (heure de Paris) et ne sert qu'une fois. Pour le saisir, scannez le QR code avec votre téléphone, ou rendez-vous sur <strong>{{ $adresseCourte }}</strong>, puis saisissez le SIREN {{ $siren }} et ce code.</p>

        @if ($motif === \App\Domain\Tenancy\Enums\MotifCode::Verification)
            <p>La personne qui saisit ce code devient propriétaire de l'inscription et choisit alors son adresse e-mail et son mot de passe. Si vous n'êtes pas à l'origine de cette inscription, saisissez ce code vous-même : les comptes créés par la personne qui l'a faite en seront retirés. Sans code saisi d'ici là, l'inscription sera close.</p>
        @else
            <p>Une fois ce code saisi, l'éditeur poursuit la procédure et vous contacte.</p>
        @endif

        <p>Ne communiquez ce code à personne.@if (filled($editeur)) Pour toute question, écrivez à l'éditeur : {{ $editeur }}.@endif</p>
    </div>
</body>
</html>
