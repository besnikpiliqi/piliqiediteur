{{--
    La page du lien secret d'un devis envoyé (voir ReponseAuDevisController
    et LienDuDevis) : ce seul devis tel qu'il a été envoyé, sans PDF avant
    la réponse (demande de l'éditeur du 14/11/2026) ; la page étant la seule
    façon de le lire avant de l'accepter, elle en montre tout ce que son PDF
    imprime, depuis les mêmes vues (voir devis/document) : le magasin qui
    l'envoie, son numéro, son émission et sa validité, le client et ses
    identifiants, les mentions du dépannage à domicile, la livraison, les
    lignes avec leur taux et la note de calcul, le récapitulatif par taux,
    les totaux et les notes, puis le pied (identité de la société, assurance
    professionnelle, conditions de paiement et mentions) ; sans le PU HT
    exact, que seul le PDF écrit. Pour un particulier, son droit de
    rétractation de quatorze jours et le formulaire type (T86) ; puis les
    deux réponses possibles : accepter, en tapant son nom et en cochant
    « Bon pour accord », avec, pour un particulier, la case qui demande que
    la prestation commence avant la fin du délai, ou refuser, avec un motif
    facultatif. Rien d'autre du magasin, de la société ni d'un autre client,
    aucun lien vers le reste de PILIQI, aucun script.

    @param \App\Domain\Facturation\Services\Emetteur $emetteur
    @param \App\Domain\Facturation\Models\Devis $devis
    @param \App\Domain\Facturation\Services\PiedDePage $pied
    @param \App\Domain\Facturation\Services\TableauDesLignes $tableau
    @param list<string> $erreurs
    @param string|null $signataire
    @param string|null $motif
    @param bool $debutAnticipe la demande de début anticipé cochée avant une saisie refusée
    @param \App\Domain\Facturation\Services\DepannageADomicile|null $depannage
    @param \App\Domain\Facturation\Services\DroitDeRetractation|null $retractation
    @param string $actionAccepter
    @param string $actionRefuser
    @param int $signataireMaximum
    @param int $motifMaximum
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>Devis {{ $devis->numero }} · {{ $emetteur->nom }}</title>
    <style>
        body { font-family: system-ui, sans-serif; font-size: 1rem; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 52rem; margin: 2rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        @media (max-width: 40rem) { main { margin: 0; padding: 1rem; border-radius: 0; } }
        h1 { font-size: 1.35rem; margin: 0 0 .25rem; }
        h2 { font-size: 1.1rem; margin: 2rem 0 .75rem; }
        .erreurs { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; border-radius: .5rem; padding: .75rem 1rem; }
        fieldset { border: 1px solid #e5e7eb; border-radius: .5rem; margin: 1rem 0; padding: 1rem; }
        legend { font-weight: 600; padding: 0 .25rem; }
        label.champ { display: block; margin: .5rem 0 .25rem; font-weight: 600; }
        input[type=text], textarea { width: 100%; padding: .5rem; border: 1px solid #d1d5db; border-radius: .375rem; box-sizing: border-box; font: inherit; }
        .case { display: flex; gap: .5rem; align-items: flex-start; margin: 1rem 0; font-weight: 600; }
        button { background: #15803d; color: #fff; border: 0; border-radius: .5rem; padding: .75rem 1.25rem; font-size: 1rem; cursor: pointer; }
        button.refus { background: #fff; color: #b91c1c; border: 1px solid #b91c1c; }
        .rappel { color: #4b5563; font-size: .9rem; }
        .retractation { border: 1px solid #e5e7eb; border-radius: .5rem; padding: .75rem 1rem; margin: 1rem 0; }
        .retractation summary { font-weight: 600; cursor: pointer; }
        .formulaire { border: 1px dashed #9ca3af; border-radius: .375rem; padding: .5rem 1rem; margin-top: .75rem; }
        .aide { color: #4b5563; font-size: .85rem; font-weight: 400; display: block; }
        /* Le devis, dessiné avec les vues de son PDF (voir devis/document). */
        .devis { position: relative; border: 1px solid #e3e7ec; border-radius: .5rem; padding: 1rem; margin: 1rem 0; overflow-x: auto; color: #17283d; font-size: .9rem; }
        .devis table { border-collapse: collapse; }
        .devis .bande-haut { height: 6px; background: #14706b; margin-bottom: 1rem; }
        .devis table.en-tete { width: 100%; margin-bottom: 1rem; }
        .devis table.en-tete td { vertical-align: top; padding: 0 .5rem 0 0; }
        .devis td.magasin { border-left: 4px solid #14706b; padding-left: .75rem; color: #4b5563; }
        .devis .magasin-nom { font-size: 1.15rem; font-weight: 700; color: #16283f; }
        .devis .titre-document { font-size: 1.35rem; font-weight: 700; text-align: right; color: #16283f; text-transform: uppercase; }
        .devis .details-document { text-align: right; color: #4b5563; }
        .devis .details-document .numero-du-document { color: #17283d; font-weight: 700; }
        .devis table.blocs { width: 100%; margin-bottom: 1rem; }
        .devis table.blocs td.bloc { background: #f5f7f9; padding: .5rem .75rem; vertical-align: top; }
        .devis table.blocs td.bloc.conditions { background: #eef5f4; width: 36%; }
        .devis table.blocs td.espace { width: .5rem; padding: 0; }
        .devis .titre, .devis .titre-section, .devis .titre-de-boite { font-size: .75rem; font-weight: 700; text-transform: uppercase; color: #14706b; margin-bottom: .25rem; }
        .devis .titre-section { color: #16283f; }
        .devis .bloc-client { margin: .75rem 0 1rem; }
        .devis table.lignes { width: 100%; margin-bottom: .5rem; }
        .devis table.lignes th { background: #16283f; color: #fff; text-align: left; padding: .4rem .5rem; font-size: .75rem; text-transform: uppercase; }
        .devis table.lignes td { padding: .4rem .5rem; border-bottom: 1px solid #e3e7ec; vertical-align: top; }
        .devis table.lignes tr.paire td { background: #f5f7f9; }
        .devis table.lignes .reference { white-space: nowrap; color: #4b5563; }
        .devis table.lignes .total-de-la-ligne { color: #14706b; font-weight: 700; }
        .devis .montant { text-align: right; white-space: nowrap; }
        .devis .note-calcul { margin-bottom: 1rem; font-size: .8rem; color: #4b5563; }
        .devis table.bande-des-totaux { width: 100%; }
        .devis table.bande-des-totaux td { vertical-align: top; padding: 0 .5rem 0 0; }
        .devis table.bande-des-totaux td.bande-droite { width: 16rem; padding: 0; }
        .devis .recapitulatif { background: #f5f7f9; padding: .4rem; }
        .devis table.tva { width: 100%; }
        .devis table.tva th { text-align: left; font-weight: 400; color: #4b5563; padding: .2rem .4rem; }
        .devis table.tva td { padding: .2rem .4rem; }
        .devis table.tva .motif { font-size: .8rem; color: #4b5563; }
        .devis .a-regler { background: #eef5f4; padding: .4rem; }
        .devis table.totaux { width: 100%; }
        .devis table.totaux td { padding: .2rem .4rem; }
        .devis table.totaux .libelle { color: #4b5563; }
        .devis table.totaux .total-ttc td { font-weight: 700; color: #16283f; border-top: 1px solid #cfdcda; }
        .devis table.totaux .total-ttc .montant { font-size: 1.1rem; color: #14706b; }
        .devis .remarque { margin-top: 1rem; color: #374151; white-space: pre-line; }
        .devis #pied-de-page { margin-top: 1.5rem; padding-top: .5rem; border-top: 1px solid #e3e7ec; font-size: .8rem; color: #4b5563; }
        .devis #pied-de-page .identite { font-weight: 700; }
        .devis .filigrane { position: absolute; top: 40%; left: 0; right: 0; text-align: center; font-size: 2rem; font-weight: 700; color: #b91c1c; opacity: .15; transform: rotate(-20deg); pointer-events: none; }
        .devis .bandeau { margin-bottom: .75rem; padding: .4rem .5rem; border: 2px solid #b91c1c; color: #b91c1c; font-weight: 700; text-align: center; }
    </style>
</head>
<body>
<main>
    <h1>Devis {{ $devis->numero }}</h1>
    @if ($devis->date_validite)
        <p>Valable jusqu'au <strong>{{ \App\Domain\Calendrier\Calendrier::afficherJour($devis->date_validite, 'd/m/Y') }}</strong>.</p>
    @endif

    <div class="devis">
        @include('devis.document', ['devis' => $devis, 'emetteur' => $emetteur, 'tableau' => $tableau, 'depannage' => $depannage, 'avecLePuHtExact' => false])
        @include('documents.pdf.pied', ['pied' => $pied])
    </div>

    @if ($retractation)
        <details class="retractation">
            <summary>{{ \App\Domain\Facturation\Services\DroitDeRetractation::TITRE }}</summary>
            @foreach ($retractation->paragraphes() as $paragraphe)
                <p>{{ $paragraphe }}</p>
            @endforeach
            <div class="formulaire">
                <p><strong>{{ \App\Domain\Facturation\Services\DroitDeRetractation::TITRE_DU_FORMULAIRE }}</strong></p>
                @foreach ($retractation->formulaire($devis->numero) as $ligne)
                    <p>{{ $ligne }}</p>
                @endforeach
            </div>
        </details>
    @endif

    <h2>Votre réponse</h2>

    @if ($erreurs !== [])
        <div class="erreurs">
            @foreach ($erreurs as $erreur)
                <p>{{ $erreur }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ $actionAccepter }}">
        @csrf
        <fieldset>
            <legend>Accepter le devis</legend>
            <label class="champ" for="signataire">Votre nom et votre prénom</label>
            <input id="signataire" type="text" name="signataire" value="{{ $signataire }}" maxlength="{{ $signataireMaximum }}" autocomplete="name" required>
            <label class="case">
                <input type="checkbox" name="bon_pour_accord" value="1" required>
                <span>Bon pour accord</span>
            </label>
            @if ($retractation)
                <label class="case">
                    <input type="checkbox" name="debut_avant_la_fin_du_delai" value="1"{{ $debutAnticipe ? ' checked' : '' }}>
                    <span>{{ \App\Domain\Facturation\Services\DroitDeRetractation::CASE_DU_DEBUT_ANTICIPE }}
                        <span class="aide">{{ \App\Domain\Facturation\Services\DroitDeRetractation::AIDE_DU_DEBUT_ANTICIPE }}</span></span>
                </label>
            @endif
            <p class="rappel">En tapant votre nom et en cochant « Bon pour accord », vous acceptez ce devis tel qu'il est présenté ci-dessus. Votre réponse est enregistrée une seule fois, avec sa date et son heure, et une confirmation vous est envoyée par e-mail avec le devis signé en PDF.</p>
            <button type="submit">Accepter le devis</button>
        </fieldset>
    </form>

    <form method="POST" action="{{ $actionRefuser }}">
        @csrf
        <fieldset>
            <legend>Refuser le devis</legend>
            <label class="champ" for="motif">Motif (facultatif)</label>
            <textarea id="motif" name="motif" rows="3" maxlength="{{ $motifMaximum }}">{{ $motif }}</textarea>
            <p class="rappel">Votre refus est enregistré une seule fois, et le magasin en est prévenu.</p>
            <button type="submit" class="refus">Refuser le devis</button>
        </fieldset>
    </form>
</main>
    @include('pied-de-page-du-site')
</body>
</html>
