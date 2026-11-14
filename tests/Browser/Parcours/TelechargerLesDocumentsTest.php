<?php

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\OrigineDesAchats;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\DocumentsDeLaPeriode;
use App\Domain\Facturation\Services\DocumentTrouve;
use App\Domain\Tenancy\Actions\ModifierAdministrateurAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Jobs\PreparerLeTelechargementDesDocumentsJob;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Controllers\TelechargerLesDocumentsController;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Browser\Support\Navigation;

/*
 * Le téléchargement des documents par période dans le navigateur
 * (décision de l'éditeur du 03/11/2026, lot EX, E5) : dans un magasin,
 * dans l'espace de la société et dans l'espace du cabinet, la période se
 * choisit par un raccourci, « Tout sélectionner » coche tous les documents
 * trouvés, « Télécharger » prépare le fichier, et son lien, dans « Vos
 * téléchargements », le fait télécharger : un ZIP de PDF ou un CSV, lus
 * ici. La page ne s'offre qu'au propriétaire et à qui il coche
 * l'autorisation, au cabinet lié qu'avec l'accord du client. La société de
 * test a, en mars 2026, un ticket du vendeur et la clôture du 9, une
 * facture à l'hôtel réglée en partie, un ticket et son avoir le 10, et un
 * avoir fournisseur ; ses factures d'achat sont de janvier (voir
 * TestCaseDuNavigateur::remplirLeMagasin()).
 */

beforeEach(function () {
    Storage::fake(PreparerLeTelechargementDesDocumentsJob::DISQUE);
});

/**
 * Prépare les documents trouvés, tous cochés, dans ce format, puis les
 * télécharge par le lien de « Vos téléchargements », et rend le fichier
 * reçu.
 *
 * @return array{nom: string, type: string, taille: int, debut: string, contenu: string}
 */
function preparerEtTelecharger(Navigation $onglet, string $format): array
{
    $onglet->cliquerSur('Tout sélectionner')
        ->cliquer('label:has-text("'.$format.'") input[type=radio]')
        ->cliquerSur('Télécharger')
        ->voir('Votre fichier est en préparation.')
        ->voir('télécharger');
    $onglet->geste(fn () => $onglet->page()->evaluate('() => document.querySelector("a[download]").click()'), 'le lien du fichier préparé')
        ->attendre()
        ->verifier();
    $fichiers = $onglet->telechargements();
    expect($fichiers)->toHaveCount(1);

    return $fichiers[0];
}

/**
 * Le nombre de documents de cette période que ce compte trouve dans ce
 * magasin, tous types.
 */
function documentsTrouves(User $compte, Magasin $magasin, string $du, string $au): string
{
    return 'Documents trouvés : '.count(app(DocumentsDeLaPeriode::class)->trouver($compte, [$magasin], $du, $au, TypeDeDocumentTelechargeable::cases(), OrigineDesAchats::Toutes));
}

/**
 * Les fichiers d'un ZIP reçu, chacun sous son chemin dans l'archive.
 *
 * @return array<string, string>
 */
function lireLeZipRecu(string $base64): array
{
    $chemin = storage_path('framework/testing/telechargement-'.getmypid().'.zip');
    File::ensureDirectoryExists(dirname($chemin));
    File::put($chemin, base64_decode($base64));
    $zip = new ZipArchive;
    expect($zip->open($chemin))->toBeTrue();
    $fichiers = [];

    for ($rang = 0; $rang < $zip->numFiles; $rang++) {
        $fichiers[(string) $zip->getNameIndex($rang)] = (string) $zip->getFromIndex($rang);
    }

    $zip->close();
    File::delete($chemin);

    return $fichiers;
}

test('le propriétaire choisit le trimestre, coche tout et télécharge le ZIP des documents du magasin', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->proprietaire)
        ->aller('/app/'.$societe->magasin->uuid)
        ->cliquerSurLeLien('Télécharger les documents')
        ->attendreLaPage('/telecharger-les-documents$')
        ->voir(documentsTrouves($societe->proprietaire, $societe->magasin, '2026-03-01', '2026-03-31'))
        ->cliquerSur('Trimestre en cours')
        ->voir(documentsTrouves($societe->proprietaire, $societe->magasin, '2026-01-01', '2026-03-31'));

    $fichier = preparerEtTelecharger($onglet, 'PDF (ZIP)');
    $fichiers = lireLeZipRecu($fichier['contenu']);
    [$ventes, $cloture] = app(TenantContext::class)->runAs($societe->magasin, fn (): array => [
        Facture::query()->emises()->orderBy('id')->pluck('numero')->all(),
        ClotureCaisse::query()->sole()->numero,
    ]);

    expect($fichier['nom'])->toBe('documents-2026-01-01-au-2026-03-31.zip')
        ->and(array_keys($fichiers))->toContain('ventes/2026-03/'.$ventes[0].'.pdf', 'clotures/2026-03/'.$cloture.'.pdf', 'encaissements/2026-03/encaissements-PORT-2026-03.pdf')
        ->and(count(array_filter(array_keys($fichiers), fn (string $nom): bool => str_starts_with($nom, 'achats/2026-01/'))))->toBe(1);
    foreach ($fichiers as $nom => $contenu) {
        expect([$nom, substr($contenu, 0, 5)])->toBe([$nom, '%PDF-']);
    }
});

test('dans l’espace de la société, le propriétaire télécharge le récapitulatif CSV des magasins cochés', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->aller('/societe/'.$societe->societe->uuid.'/telecharger-les-documents')
        ->voir('Boutique de la Gare (GARE)')
        ->voir('Boutique du Port (PORT)')
        ->cliquer('label:has-text("Boutique de la Gare (GARE)") input[type=checkbox]')
        ->voir(documentsTrouves($societe->proprietaire, $societe->magasin, '2026-03-01', '2026-03-31'));

    $fichier = preparerEtTelecharger($onglet, 'Récapitulatif (CSV)');
    $lignes = array_map(fn (string $ligne): array => str_getcsv($ligne, ';', '"', ''), explode("\r\n", rtrim(substr(base64_decode($fichier['contenu']), 3), "\r\n")));

    expect([$fichier['nom'], substr(base64_decode($fichier['contenu']), 0, 3)])->toBe(['documents-2026-03-01-au-2026-03-31.csv', "\xEF\xBB\xBF"])
        ->and($lignes[0][0])->toBe('Type')
        ->and(array_unique(array_column(array_slice($lignes, 1), 1)))->toBe(['Boutique du Port (PORT)'])
        ->and(array_column(array_slice($lignes, 1), 0))->toContain('Vente', 'Avoir client', 'Avoir fournisseur', 'Clôture du jour', 'Relevé des encaissements');
});

test('le collaborateur du cabinet lié télécharge les documents de son client quand le client le lui coche', function () {
    $societe = $this->societeComplete();
    $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $societe->societe->id)->where('etat', EtatDuLien::Actif->value)->sole();
    app(ReglerLesAutorisationsDuCabinetAction::class)->handle($societe->proprietaire, $lien, [...PermissionRegistry::parDefautDuCabinet($societe->societe->type_activite), PermissionRegistry::DOCUMENTS_TELECHARGER]);
    app(TenantContext::class)->clear();

    $onglet = $this->seConnecter($societe->collaborateur, panneau: 'cabinet')
        ->cliquerSurLeLien('Télécharger les documents')
        ->attendreLaPage('/telecharger-les-documents$')
        ->voir('Boutique du Port (PORT)')
        ->voir(documentsTrouves($societe->collaborateur, $societe->magasin, '2026-03-01', '2026-03-31'));

    $fichier = preparerEtTelecharger($onglet, 'Récapitulatif (CSV)');
    expect(base64_decode($fichier['contenu']))->toContain('Boutique du Port (PORT)');
});

test('la page ne s’offre qu’au propriétaire et à qui il coche l’autorisation, ni au cabinet lié sans l’accord du client', function () {
    $societe = $this->societeComplete();
    $lienDuMenu = '.fi-sidebar a[href$="/telecharger-les-documents"]';
    $comptes = [
        'le propriétaire' => [$societe->proprietaire, 1],
        'l’administrateur sans la case' => [$societe->administrateur, 0],
        'le responsable' => [$societe->responsable, 0],
        'le vendeur' => [$societe->vendeur, 0],
        'l’observateur' => [$societe->observateur, 0],
    ];

    foreach ($comptes as $qui => [$compte, $liens]) {
        $onglet = $this->seConnecter($compte)->aller('/app/'.$societe->magasin->uuid);
        expect([$qui, $onglet->element($lienDuMenu)->count()])->toBe([$qui, $liens]);
        $onglet->seDeconnecter();
    }

    app(ModifierAdministrateurAction::class)->modifier($societe->proprietaire, $societe->administrateur, null, [...PermissionRegistry::modeleDeLAdministrateur($societe->societe->type_activite), PermissionRegistry::DOCUMENTS_TELECHARGER], AutorisationsSociete::toutes());
    app(TenantContext::class)->clear();
    $onglet = $this->seConnecter($societe->administrateur->refresh())->aller('/app/'.$societe->magasin->uuid);
    expect($onglet->element($lienDuMenu)->count())->toBe(1);
    $onglet->seDeconnecter();

    $onglet = $this->seConnecter($societe->collaborateur, panneau: 'cabinet', onglet: $onglet);
    expect($onglet->element($lienDuMenu)->count())->toBe(0);
    $onglet->aller('/app/'.$societe->magasin->uuid);
    expect($onglet->element($lienDuMenu)->count())->toBe(0);
});

/**
 * Le propriétaire coche à ce compte de membre « télécharger les documents
 * par période », en plus des autorisations qu'il a déjà dans le magasin
 * (un responsable n'a que celle-là, ses autres droits étant sans case).
 */
function cocherLeTelechargement(User $proprietaire, Magasin $magasin, User $compte, RoleMagasin $role): User
{
    $autorisations = $role === RoleMagasin::Responsable ? [] : app(TenantContext::class)->runAs($magasin, fn (): array => $compte->affectationDeMembre()->autorisations());
    app(ModifierMembreAction::class)->handle($proprietaire, $compte, $role, [...$autorisations, PermissionRegistry::DOCUMENTS_TELECHARGER]);
    app(TenantContext::class)->clear();

    return $compte->refresh();
}

/**
 * Les numéros des documents d'un récapitulatif CSV reçu, chacun une fois.
 *
 * @return list<string>
 */
function numerosDuRecapitulatif(array $fichier): array
{
    $lignes = array_map(fn (string $ligne): array => str_getcsv($ligne, ';', '"', ''), explode("\r\n", rtrim(substr(base64_decode($fichier['contenu']), 3), "\r\n")));

    return array_values(array_unique(array_column(array_slice($lignes, 1), 2)));
}

/**
 * Les numéros des documents de mars de ce magasin que ce compte voit.
 *
 * @return list<string>
 */
function numerosVus(User $compte, Magasin $magasin): array
{
    return array_values(array_unique(array_map(
        fn (DocumentTrouve $document): string => $document->numero,
        app(DocumentsDeLaPeriode::class)->trouver($compte, [$magasin], '2026-03-01', '2026-03-31', TypeDeDocumentTelechargeable::cases(), OrigineDesAchats::Toutes),
    )));
}

test('le propriétaire saisit la période au calendrier, choisit les types, l’origine et la seule marque « pièce manquante », coche un document puis désélectionne tout', function () {
    $societe = $this->societeComplete();
    $tous = TypeDeDocumentTelechargeable::cases();
    $sansLesVentes = array_values(array_filter($tous, fn (TypeDeDocumentTelechargeable $type): bool => $type !== TypeDeDocumentTelechargeable::Ventes));
    $trouves = fn (string $du, string $au, array $types, OrigineDesAchats $origine = OrigineDesAchats::Toutes, bool $sansPiece = false): string => 'Documents trouvés : '.count(app(DocumentsDeLaPeriode::class)->trouver($societe->proprietaire, [$societe->magasin], $du, $au, $types, $origine, $sansPiece));
    $avoirFournisseur = app(TenantContext::class)->runAs($societe->magasin, fn (): string => AvoirFournisseur::query()->sole()->numero);

    $onglet = $this->seConnecter($societe->proprietaire)
        ->aller('/app/'.$societe->magasin->uuid.'/telecharger-les-documents')
        ->remplir('Au', '2026-01-31')
        ->voir('Choisissez une période : son premier jour, puis son dernier, au plus tard le même.')
        ->remplir('Du', '2026-01-01')
        ->voir($trouves('2026-01-01', '2026-01-31', $tous))
        ->nePasVoir('Choisissez une période')
        ->remplir('Au', '2026-03-31')
        ->voir($trouves('2026-01-01', '2026-03-31', $tous))
        ->cliquer('label:has-text("Ventes (factures et tickets)") input[type=checkbox]')
        ->voir($trouves('2026-01-01', '2026-03-31', $sansLesVentes))
        ->cliquer('label:has-text("Saisie manuelle") input[type=radio]')
        ->voir($trouves('2026-01-01', '2026-03-31', $sansLesVentes, OrigineDesAchats::SaisieManuelle))
        ->cliquer('label:has-text("Seulement ceux dont la pièce du fournisseur manque") input[type=checkbox]')
        ->voir($trouves('2026-01-01', '2026-03-31', $sansLesVentes, OrigineDesAchats::SaisieManuelle, true))
        ->voir('Pièce du fournisseur manquante')
        ->cliquer('input[aria-label="Choisir '.$avoirFournisseur.'"]')
        ->voir('Sélection : 1 document,')
        ->cliquerSur('Tout désélectionner')
        ->voir('Sélection : 0 document,');

    expect($onglet->element('input[aria-label="Choisir '.$avoirFournisseur.'"]')->isChecked())->toBeFalse();
});

test('la liste de plus de cinquante documents se parcourt page par page, et « Tout sélectionner » les prend tous', function () {
    $societe = $this->societeComplete();
    app(TenantContext::class)->runAs($societe->magasin, function () use ($societe): void {
        $carnet = Produit::factory()->create(['nom' => 'Carnet', 'reference' => 'CARNET', 'prix_vente_ttc' => 3, 'taux_tva' => 20, 'suivi_stock' => false]);

        foreach (range(1, 50) as $rang) {
            $this->vendreAuComptant($societe->vendeur, Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $carnet->id, 'quantite' => 1]]);
        }
    });
    app(TenantContext::class)->clear();
    $nombre = count(app(DocumentsDeLaPeriode::class)->trouver($societe->proprietaire, [$societe->magasin], '2026-03-01', '2026-03-31', TypeDeDocumentTelechargeable::cases(), OrigineDesAchats::Toutes));

    $this->seConnecter($societe->proprietaire)
        ->aller('/app/'.$societe->magasin->uuid.'/telecharger-les-documents')
        ->voir('Page 1 sur 2')
        ->cliquerSur('Page suivante')
        ->voir('Page 2 sur 2')
        ->cliquerSur('Tout sélectionner')
        ->voir('Sélection : '.$nombre.' documents')
        ->cliquerSur('Page précédente')
        ->voir('Page 1 sur 2')
        ->voir('Sélection : '.$nombre.' documents');
});

test('le collaborateur du cabinet choisit le client dont il télécharge les documents', function () {
    $societe = $this->societeComplete();
    $parDefaut = [...PermissionRegistry::parDefautDuCabinet($societe->societe->type_activite), PermissionRegistry::DOCUMENTS_TELECHARGER];
    $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $societe->societe->id)->where('etat', EtatDuLien::Actif->value)->sole();
    app(ReglerLesAutorisationsDuCabinetAction::class)->handle($societe->proprietaire, $lien, $parDefaut);
    $atelier = $this->ouvrirUneSociete(['nom' => 'Atelier du Marché', 'code' => 'MARCHE']);
    $this->lierLeCabinet($societe->cabinet, $atelier, $parDefaut);
    $this->confier($societe->collaborateur, [$societe->magasin, $atelier]);
    app(TenantContext::class)->clear();
    $client = $atelier->societe()->firstOrFail();

    $this->seConnecter($societe->collaborateur, panneau: 'cabinet')
        ->cliquerSurLeLien('Télécharger les documents')
        ->attendreLaPage('/telecharger-les-documents$')
        ->choisir('Client', $client->raison_sociale.' ('.$client->siren.')')
        ->voir('Atelier du Marché (MARCHE)')
        ->nePasVoir('Boutique du Port (PORT)')
        ->choisir('Client', $societe->societe->raison_sociale.' ('.$societe->societe->siren.')')
        ->voir('Boutique du Port (PORT)')
        ->nePasVoir('Atelier du Marché (MARCHE)');
});

test('le bouton « Télécharger » de la notification fait télécharger le fichier, et un lien expiré mène à une page qui ramène à l’accueil', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->proprietaire)->aller('/app/'.$societe->magasin->uuid.'/telecharger-les-documents');
    preparerEtTelecharger($onglet, 'Récapitulatif (CSV)');

    $onglet->cliquer('.fi-topbar-database-notifications-btn')
        ->voir('Vos documents du 01/03/2026 au 31/03/2026 sont prêts')
        ->geste(fn () => $onglet->page()->evaluate('() => document.querySelector('.Navigation::texte(Navigation::FENETRE.' .fi-no-notification a[download]').').click()'), 'le bouton « Télécharger » de la notification');
    $fichiers = $onglet->telechargements();
    expect($fichiers)->toHaveCount(1)
        ->and($fichiers[0]['nom'])->toBe('documents-2026-03-01-au-2026-03-31.csv')
        ->and(numerosDuRecapitulatif($fichiers[0]))->toBe(numerosVus($societe->proprietaire, $societe->magasin));

    $telechargement = TelechargementDeDocuments::query()->withoutGlobalScopes()->sole();
    $telechargement->forceFill(['expire_le' => now()->subMinute()])->save();
    $this->tolerer(410, '/telechargements/');
    $onglet->aller($telechargement->lien())
        ->voir(TelechargerLesDocumentsController::EXPIRE)
        ->cliquerSurLeLien('Revenir à l\'accueil')
        ->attendreLaPage('^/app/[0-9a-f-]{36}$');
});

test('chaque rôle à qui le propriétaire coche la case télécharge les documents qu’il voit, dans le magasin et dans l’espace de la société', function () {
    $societe = $this->societeComplete();
    $comptes = [
        'le responsable' => cocherLeTelechargement($societe->proprietaire, $societe->magasin, $societe->responsable, RoleMagasin::Responsable),
        'le vendeur' => cocherLeTelechargement($societe->proprietaire, $societe->magasin, $societe->vendeur, RoleMagasin::Vendeur),
        'l’observateur' => cocherLeTelechargement($societe->proprietaire, $societe->magasin, $societe->observateur, RoleMagasin::Observateur),
    ];
    app(ModifierAdministrateurAction::class)->modifier($societe->proprietaire, $societe->administrateur, null, [...PermissionRegistry::modeleDeLAdministrateur($societe->societe->type_activite), PermissionRegistry::DOCUMENTS_TELECHARGER], AutorisationsSociete::toutes());
    app(TenantContext::class)->clear();
    $comptes['l’administrateur'] = $societe->administrateur->refresh();

    foreach ($comptes as $qui => $compte) {
        $onglet = $this->seConnecter($compte)
            ->aller('/app/'.$societe->magasin->uuid)
            ->cliquerSurLeLien('Télécharger les documents')
            ->attendreLaPage('/telecharger-les-documents$');
        $fichier = preparerEtTelecharger($onglet, 'Récapitulatif (CSV)');

        expect([$qui, numerosDuRecapitulatif($fichier)])->toBe([$qui, numerosVus($compte, $societe->magasin)]);
        $onglet->seDeconnecter();
    }

    $onglet = $this->seConnecter($societe->administrateur, panneau: 'societe')
        ->aller('/societe/'.$societe->societe->uuid.'/telecharger-les-documents')
        ->voir('Boutique de la Gare (GARE)')
        ->cliquer('label:has-text("Boutique de la Gare (GARE)") input[type=checkbox]');
    $fichier = preparerEtTelecharger($onglet, 'Récapitulatif (CSV)');
    expect(numerosDuRecapitulatif($fichier))->toBe(numerosVus($societe->administrateur, $societe->magasin));
});
