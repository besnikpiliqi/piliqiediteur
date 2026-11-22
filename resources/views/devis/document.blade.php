{{--
    Le devis tel que son PDF l'imprime et tel que la page du lien le montre
    avant la réponse du client (voir devis/pdf et devis/reponse) : la page
    est désormais la seule façon de le lire avant de l'accepter (demande de
    l'éditeur du 14/11/2026), elle en montre donc tout, depuis les mêmes
    données. L'en-tête (le magasin qui vend, le numéro, l'émission, la
    validité), le client et les conditions, les mentions du dépannage à
    domicile, la livraison, les lignes avec leur taux et la note de calcul,
    le récapitulatif par taux et les totaux, puis ses notes ; l'identité de
    la société, son assurance professionnelle, les conditions de paiement
    et les mentions sont dans le pied (voir documents/pdf/pied), que le PDF
    fixe en bas de chaque page et que la page montre sous le devis.

    Un devis modifié depuis son envoi est un brouillon qui garde son numéro
    et la date de son dernier envoi (voir ModifierDevisAction) : cette
    version n'a pas encore été émise, elle ne porte aucune date d'émission.

    @param \App\Domain\Facturation\Models\Devis $devis
    @param \App\Domain\Facturation\Services\Emetteur $emetteur celui que le devis a figé à son dernier envoi, la société et le magasin d'aujourd'hui pour un brouillon (voir Devis::emetteur())
    @param \App\Domain\Facturation\Services\TableauDesLignes $tableau ses lignes (voir TableauDesLignes)
    @param \App\Domain\Facturation\Services\DepannageADomicile|null $depannage ses mentions de dépannage à domicile, null sans elles
    @param bool $avecLePuHtExact le PU HT exact sous chaque article : sur le PDF seulement
--}}
@php($brouillon = $devis->statut === \App\Domain\Facturation\Enums\StatutDevis::Brouillon)

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
     le lieu de l'intervention, une ligne par ligne saisie, le prix du
     devis et les pièces remplacées. --}}
@if($depannage !== null)
    <div class="bloc-client depannage">
        <div class="titre">Dépannage, réparation ou entretien à domicile</div>
        Lieu de l'intervention : {!! $depannage->lieu === null ? '—' : nl2br(e($depannage->lieu), false) !!}<br>
        {{ $depannage->mentionDuPrixDuDevis() }}<br>
        {{ \App\Domain\Facturation\Services\DepannageADomicile::PIECES_REMPLACEES }}
        @if($depannage->signeChezLeClient)
            <br>Devis signé chez le client : votre droit de rétractation et son formulaire figurent à la fin de ce devis.
        @endif
    </div>
@endif

@include('documents.pdf.livraison', ['lignes' => $devis->lignesDeLivraison()])

@include('documents.pdf.lignes', ['document' => $devis, 'tableau' => $tableau, 'avecLePuHtExact' => $avecLePuHtExact])

@include('documents.pdf.totaux', ['document' => $devis, 'titreDuMontant' => 'Montant du devis', 'libelleDuTotal' => 'Total TTC'])

@if($devis->notes)
    <div class="remarque">{{ $devis->notes }}</div>
@endif
