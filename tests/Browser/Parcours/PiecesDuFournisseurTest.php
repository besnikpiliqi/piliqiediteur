<?php

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Services\PieceDeposee;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Tests\Browser\Support\Navigation;

/*
 * Les pièces du fournisseur dans le navigateur (décision de l'éditeur du
 * 03/11/2026, lot EX, F1 = b) : la facture du fournisseur se dépose à la
 * saisie d'une facture d'achat, avec sa date ; une facture saisie sans
 * elle est marquée « pièce du fournisseur manquante », que le filtre de la
 * liste et « À faire » du tableau de bord retrouvent ; la pièce s'ajoute
 * depuis la fiche, se remplace ou se retire d'un brouillon, et se
 * télécharge depuis la fiche par chaque rôle qui voit les achats, cabinet
 * lié compris. La société de test a déjà deux factures d'achat (une
 * validée, un brouillon) et un avoir fournisseur saisis sans leur pièce
 * (voir TestCaseDuNavigateur::remplirLeMagasin()).
 */

const PDF_DU_FOURNISSEUR = "%PDF-1.4\nFacture F-812 du Grossiste du Nord\n%%EOF";

/**
 * Une vraie photo JPEG (16 × 12 pixels orange), que le navigateur sait
 * décoder pour son aperçu, écrite ici plutôt que dessinée par l'extension
 * GD, que la CI n'installe pas pour chaque version de PHP.
 */
const PHOTO_DU_FOURNISSEUR = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNzUK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgADAAQAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8Anooor5A+qP/Z';

beforeEach(function () {
    Storage::fake(PiecesDuFournisseur::DISQUE);
    Storage::fake(FileUploadConfiguration::disk());
});

/**
 * Un fichier sur le poste de la personne, prêt à être choisi.
 */
function pieceSurLePoste(string $nom, string $contenu): string
{
    $chemin = storage_path('framework/testing/pieces-'.getmypid().'/'.$nom);
    File::ensureDirectoryExists(dirname($chemin));
    File::put($chemin, $contenu);

    return $chemin;
}

/**
 * Une photo JPEG de la facture du fournisseur, sur le poste de la
 * personne, prête à être choisie.
 */
function photoSurLePoste(string $nom): string
{
    return pieceSurLePoste($nom, base64_decode(PHOTO_DU_FOURNISSEUR, true));
}

/**
 * Une facture d'achat du Grossiste du Nord, de deux bougies, saisie par le
 * responsable avec ces pièces, validée ou en brouillon.
 *
 * @param  list<PieceDeposee>  $pieces
 */
function factureDuGrossiste(Magasin $magasin, User $responsable, array $pieces, bool $validee, string $numero = 'F-812'): FactureAchat
{
    return app(TenantContext::class)->runAs($magasin, function () use ($responsable, $pieces, $validee, $numero): FactureAchat {
        $brouillon = app(CreerFactureAchatAction::class)->handle($responsable, Fournisseur::query()->where('nom', 'Grossiste du Nord')->sole(), [
            ['produit_id' => Produit::query()->where('reference', 'BOUGIE')->sole()->id, 'quantite' => 2, 'prix_unitaire_ht' => '5.00'],
        ], ['date_facture' => '2026-03-09', 'numero_fournisseur' => $numero], null, $pieces);

        return $validee ? app(ValiderFactureAchatAction::class)->handle($responsable, $brouillon) : $brouillon;
    });
}

/**
 * Télécharge, d'un clic sur son lien, la pièce de ce nom de la fiche
 * ouverte, et rend le fichier reçu.
 *
 * @return array{nom: string, type: string, taille: int, debut: string, contenu: string}
 */
function telechargerLaPiece(Navigation $onglet, string $nom): array
{
    $onglet->geste(fn () => $onglet->page()->evaluate('(nom) => document.querySelector(`a[download="${nom}"]`).click()', $nom), 'le lien de la pièce « '.$nom.' »')
        ->attendre()
        ->verifier();
    $fichiers = $onglet->telechargements();
    expect($fichiers)->toHaveCount(1);

    return $fichiers[0];
}

test('le responsable dépose la facture du fournisseur à la saisie, avec sa date, et la télécharge depuis la fiche', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->responsable)
        ->aller('/app/'.$societe->magasin->uuid.'/facture-achats/create')
        ->choisirDansLaListe('Fournisseur', 'Grossiste du Nord')
        ->remplir('N° de facture du fournisseur', 'F-812')
        ->remplir('Date de la facture du fournisseur', '2026-03-09')
        ->envoyerLeFichier(pieceSurLePoste('facture-F-812.pdf', PDF_DU_FOURNISSEUR))
        ->cliquerSur('Ajouter un produit')
        ->choisirDansLaListe('Produit', 'Bougie parfumée')
        ->remplir('Quantité reçue', '2')
        ->remplir('Prix d\'achat HT', '5')
        ->soumettre('Ajouter à la facture')
        ->soumettre('Créer')
        ->attendreLaPage('/facture-achats$');

    $factureAchat = app(TenantContext::class)->runAs($societe->magasin, fn (): FactureAchat => FactureAchat::query()->where('numero_fournisseur', 'F-812')->sole());
    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): array => [$factureAchat->date_facture, $factureAchat->piece_manquante, $factureAchat->pieces()->sole()->nom_origine]))->toBe(['2026-03-09', false, 'facture-F-812.pdf']);

    $onglet->aller('/app/'.$societe->magasin->uuid.'/facture-achats/'.$factureAchat->getRouteKey())->voir('facture-F-812.pdf');
    $fichier = telechargerLaPiece($onglet, 'facture-F-812.pdf');
    expect([$fichier['nom'], $fichier['type'], base64_decode($fichier['contenu'])])->toBe(['facture-F-812.pdf', 'application/pdf', PDF_DU_FOURNISSEUR]);
});

test('le responsable dépose une photo de la facture du fournisseur : son aperçu s’affiche sans aucune violation de la politique de contenu', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->responsable)
        ->aller('/app/'.$societe->magasin->uuid.'/facture-achats/create')
        ->choisirDansLaListe('Fournisseur', 'Grossiste du Nord')
        ->remplir('N° de facture du fournisseur', 'F-813')
        ->remplir('Date de la facture du fournisseur', '2026-03-09')
        ->envoyerLeFichier(photoSurLePoste('facture-F-813.jpg'))
        // FilePond dessine l'aperçu dans un travailleur créé depuis une
        // adresse blob: (worker-src de la politique de contenu) : sans lui,
        // aucun aperçu, et la violation ne survient qu'après l'envoi.
        ->attendreQue("document.querySelectorAll('.filepond--image-preview canvas').length === 1", 'l’aperçu de la photo ne s’affiche pas')
        ->verifier();
    expect([$onglet->element('.filepond--item')->count(), $onglet->element('.filepond--image-preview')->count()])->toBe([1, 1]);

    $onglet->cliquerSur('Ajouter un produit')
        ->choisirDansLaListe('Produit', 'Bougie parfumée')
        ->remplir('Quantité reçue', '2')
        ->remplir('Prix d\'achat HT', '5')
        ->soumettre('Ajouter à la facture')
        ->soumettre('Créer')
        ->attendreLaPage('/facture-achats$');

    $piece = app(TenantContext::class)->runAs($societe->magasin, fn () => FactureAchat::query()->where('numero_fournisseur', 'F-813')->sole()->pieces()->sole());
    expect([$piece->nom_origine, $piece->type_mime])->toBe(['facture-F-813.jpg', 'image/jpeg']);
});

test('une facture saisie sans sa pièce est marquée, se retrouve par le filtre et par « À faire », et « Ajouter des pièces » lève la marque pour chaque rôle qui saisit les achats', function () {
    $societe = $this->societeComplete();
    $comptes = [
        'le propriétaire' => $societe->proprietaire,
        'l’administrateur sans la vente' => $societe->administrateur,
        'l’administrateur autorisé à vendre' => $societe->administrateurVendeur,
        'le responsable' => $societe->responsable,
    ];
    $rang = 0;

    foreach ($comptes as $qui => $compte) {
        $rang++;
        $sansPiece = factureDuGrossiste($societe->magasin, $societe->responsable, [], true, 'SANS-'.$rang);
        $onglet = $this->seConnecter($compte)->aller('/app/'.$societe->magasin->uuid);
        $onglet->voir('À faire')->voir('3 factures d\'achat sans leur pièce');
        $onglet->cliquer('a[href*="/facture-achats?tableFilters%5Bpiece_manquante%5D%5BisActive%5D=1"]')->attendreLaPage('/facture-achats');
        expect([$qui, $onglet->element('.fi-ta-row')->count(), $onglet->element('.fi-ta-row:has-text("SANS-'.$rang.'") >> text=Manquante')->count()])->toBe([$qui, 3, 1]);

        $onglet->aller('/app/'.$societe->magasin->uuid.'/facture-achats/'.$sansPiece->getRouteKey())
            ->voir('Pièce du fournisseur manquante')
            ->cliquerSur('Ajouter des pièces')
            ->envoyerLeFichier(pieceSurLePoste('facture-'.$rang.'.pdf', PDF_DU_FOURNISSEUR.' '.$rang))
            ->soumettre('Joindre')
            ->voir('Pièce jointe.')
            ->voir('facture-'.$rang.'.pdf');

        expect([$qui, app(TenantContext::class)->runAs($societe->magasin, fn (): bool => $sansPiece->refresh()->piece_manquante)])->toBe([$qui, false]);
        $onglet->aller('/app/'.$societe->magasin->uuid)->voir('2 factures d\'achat sans leur pièce');
        $onglet->seDeconnecter();
    }
});

test('sur un brouillon, le responsable remplace une pièce déposée par erreur puis la retire', function () {
    $societe = $this->societeComplete();
    $brouillon = factureDuGrossiste($societe->magasin, $societe->responsable, [new PieceDeposee('mauvaise-facture.pdf', '%PDF-1.4 mauvaise')], false);
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/facture-achats/'.$brouillon->getRouteKey());

    $piecesDuBrouillon = fn (): array => app(TenantContext::class)->runAs($societe->magasin, fn (): array => $brouillon->pieces()->get()->all());

    $onglet->cliquerSur('Remplacer une pièce')
        ->choisir('Pièce', $piecesDuBrouillon()[0]->uuid)
        ->envoyerLeFichier(pieceSurLePoste('bonne-facture.pdf', PDF_DU_FOURNISSEUR))
        ->soumettre('Remplacer')
        ->voir('Pièce remplacée.')
        ->voir('bonne-facture.pdf')
        ->nePasVoir('mauvaise-facture.pdf');
    expect(array_map(fn ($piece): string => $piece->nom_origine, $piecesDuBrouillon()))->toBe(['bonne-facture.pdf']);

    $onglet->cliquerSur('Retirer une pièce')
        ->choisir('Pièce', $piecesDuBrouillon()[0]->uuid)
        ->soumettre('Retirer')
        ->voir('Pièce retirée.')
        ->voir('Pièce du fournisseur manquante');
    expect([$piecesDuBrouillon(), app(TenantContext::class)->runAs($societe->magasin, fn (): bool => $brouillon->refresh()->piece_manquante)])->toBe([[], true]);
});

test('le responsable joint la note d’avoir du fournisseur dans la fenêtre de l’avoir', function () {
    $societe = $this->societeComplete();
    $factureAchat = factureDuGrossiste($societe->magasin, $societe->responsable, [new PieceDeposee('facture.pdf', PDF_DU_FOURNISSEUR)], true);
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/facture-achats/'.$factureAchat->getRouteKey());

    $onglet->cliquerSur('Créer un avoir fournisseur')
        ->remplirLaLigne('lignes', 0, 'quantite', '1')
        ->remplir('N° de l\'avoir du fournisseur', 'AV-NORD-9')
        ->remplir('Motif du retour', 'Bougie cassée')
        ->envoyerLeFichier(pieceSurLePoste('note-AV-NORD-9.pdf', '%PDF-1.4 note AV-NORD-9'))
        ->soumettre('Émettre l\'avoir fournisseur')
        ->voir('émis.');

    $avoir = app(TenantContext::class)->runAs($societe->magasin, fn (): AvoirFournisseur => AvoirFournisseur::query()->where('numero_fournisseur', 'AV-NORD-9')->sole());
    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): array => [$avoir->piece_manquante, $avoir->pieces()->pluck('nom_origine')->all()]))->toBe([false, ['note-AV-NORD-9.pdf']]);

    $onglet->aller('/app/'.$societe->magasin->uuid.'/avoir-fournisseurs/'.$avoir->getRouteKey())->voir('note-AV-NORD-9.pdf');
    expect(base64_decode(telechargerLaPiece($onglet, 'note-AV-NORD-9.pdf')['contenu']))->toBe('%PDF-1.4 note AV-NORD-9');
});

test('chaque rôle qui voit les achats télécharge la pièce depuis la fiche, cabinet lié compris ; seul qui saisit les achats en ajoute', function () {
    $societe = $this->societeComplete();
    $factureAchat = factureDuGrossiste($societe->magasin, $societe->responsable, [new PieceDeposee('facture-F-812.pdf', PDF_DU_FOURNISSEUR)], true);
    $fiche = '/app/'.$societe->magasin->uuid.'/facture-achats/'.$factureAchat->getRouteKey();
    $comptes = [
        'le propriétaire' => [$societe->proprietaire, 'app', true],
        'l’administrateur sans la vente' => [$societe->administrateur, 'app', true],
        'l’administrateur autorisé à vendre' => [$societe->administrateurVendeur, 'app', true],
        'le responsable' => [$societe->responsable, 'app', true],
        'l’observateur' => [$societe->observateur, 'app', false],
        'le collaborateur du cabinet lié' => [$societe->collaborateur, 'cabinet', false],
    ];

    foreach ($comptes as $qui => [$compte, $panneau, $ajoute]) {
        $onglet = $this->seConnecter($compte, panneau: $panneau)->aller($fiche)->voir('facture-F-812.pdf');
        expect([$qui, base64_decode(telechargerLaPiece($onglet, 'facture-F-812.pdf')['contenu'])])->toBe([$qui, PDF_DU_FOURNISSEUR])
            ->and([$qui, $onglet->element(Navigation::bouton('Ajouter des pièces'))->count()])->toBe([$qui, $ajoute ? 1 : 0]);
        $onglet->seDeconnecter();
    }

    $onglet = $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid);
    expect($onglet->element('.fi-sidebar a[href$="/facture-achats"]')->count())->toBe(0);
});
