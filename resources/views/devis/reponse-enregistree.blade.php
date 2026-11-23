{{--
    La réponse du client à un devis, enregistrée par son lien (voir
    ReponseAuDevisController) : ce qui a été enregistré et quand, à l'heure
    du magasin ; le lien ne vaut plus pour répondre. Après une acceptation,
    la page que le même lien montre encore (voir
    LienDuDevis::confirmeLAcceptation()) : « Devis accepté le … par … », le
    lien du PDF du devis signé et, pour un particulier, son droit de
    rétractation, le formulaire type et ce qu'il a demandé du début de la
    prestation (T86). Aucun lien vers le reste de PILIQI.

    @param string $numero
    @param string|null $acceptation le nom tapé par le client qui a accepté ; null pour un refus
    @param string $quand
    @param string $heure
    @param string|null $adresseDuPdf le PDF du devis signé, après une acceptation
    @param \App\Domain\Facturation\Services\DroitDeRetractation|null $retractation
    @param bool|null $debutAnticipe
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>Devis {{ $numero }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 40rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
        h2 { font-size: 1.05rem; margin: 1.5rem 0 .5rem; }
        a.pdf { color: #1d4ed8; }
        .formulaire { border: 1px dashed #9ca3af; border-radius: .375rem; padding: .5rem 1rem; margin-top: .75rem; }
    </style>
</head>
<body>
<main>
    @if ($acceptation !== null)
        <h1>Devis accepté le {{ $quand }} par {{ $acceptation }}</h1>
        <p>Votre acceptation du devis {{ $numero }} a été enregistrée le {{ $quand }} ({{ $heure }}). Le magasin en est prévenu, et une confirmation vous est envoyée par e-mail avec le devis signé.</p>
        <p><a class="pdf" href="{{ $adresseDuPdf }}" rel="noreferrer" download>Télécharger le devis signé en PDF</a></p>
        @if ($retractation)
            <h2>{{ \App\Domain\Facturation\Services\DroitDeRetractation::TITRE }}</h2>
            <p>{{ \App\Domain\Facturation\Services\DroitDeRetractation::reponseALaDemande((bool) $debutAnticipe) }}</p>
            @foreach ($retractation->paragraphes() as $paragraphe)
                <p>{{ $paragraphe }}</p>
            @endforeach
            <div class="formulaire">
                <p><strong>{{ \App\Domain\Facturation\Services\DroitDeRetractation::TITRE_DU_FORMULAIRE }}</strong></p>
                @foreach ($retractation->formulaire($numero) as $ligne)
                    <p>{{ $ligne }}</p>
                @endforeach
            </div>
        @endif
        <p>Pour toute question, répondez à l'e-mail du magasin.</p>
    @else
        <h1>Votre refus est enregistré</h1>
        <p>Votre refus du devis {{ $numero }} a été enregistré le {{ $quand }} ({{ $heure }}). Le magasin en est prévenu, et une confirmation vous est envoyée par e-mail.</p>
        <p>Ce lien ne vaut plus : pour toute question, répondez à l'e-mail du magasin.</p>
    @endif
</main>
    @include('pied-de-page-du-site')
</body>
</html>
