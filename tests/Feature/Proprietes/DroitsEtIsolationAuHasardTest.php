<?php

namespace Tests\Feature\Proprietes;

use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Des comptes tirés au hasard (décision de l'éditeur du 01/11/2026, point
 * 5 bis) : un vendeur, un observateur, un responsable ou un administrateur,
 * dans l'un des deux magasins d'une société, avec des cases d'autorisation
 * cochées au hasard, à côté d'une autre société. Pour chacun, ce qui doit
 * toujours tenir tient :
 *
 * - aucune fiche ni aucun document d'un autre magasin, ou d'une autre
 *   société, ne se lit depuis son magasin, ni ne s'ouvre, ni ne se modifie ;
 * - dans un magasin qu'il n'ouvre pas, il ne voit rien et ne crée rien ;
 * - dans un magasin qu'il ouvre, il voit une liste, et crée une fiche, un
 *   devis ou une facture d'achat, exactement quand la case correspondante
 *   est l'une de ses autorisations dans ce magasin : un rôle ne fait
 *   jamais ce que ses droits refusent.
 */
class DroitsEtIsolationAuHasardTest extends TestCase
{
    use RefreshDatabase, VerifieDesProprietes;

    private const GRAINE = 20261109;

    private const NOMBRE_DE_CAS = 150;

    /**
     * Chaque modèle, et les cases qui ouvrent sa liste et, hors des ventes
     * (que le propriétaire ne fait jamais et qu'un administrateur ne fait
     * que si on les lui coche), sa création.
     *
     * @var array<class-string<Model>, array{0: string, 1: string|null}>
     */
    private const CASES = [
        Client::class => [PermissionRegistry::CLIENT_VIEW, PermissionRegistry::CLIENT_CREATE],
        Fournisseur::class => [PermissionRegistry::FOURNISSEUR_VIEW, PermissionRegistry::FOURNISSEUR_CREATE],
        Produit::class => [PermissionRegistry::PRODUIT_VIEW, null],
        Devis::class => [PermissionRegistry::DEVIS_VIEW, PermissionRegistry::DEVIS_CREATE],
        Facture::class => [PermissionRegistry::FACTURE_VIEW, null],
        Avoir::class => [PermissionRegistry::AVOIR_VIEW, null],
        Paiement::class => [PermissionRegistry::PAIEMENT_VIEW, null],
        FactureAchat::class => [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER],
    ];

    /** @var list<Magasin> les deux magasins de la société, puis celui de l'autre société */
    private array $magasins;

    /** @var array<int, list<Model>> par magasin, une fiche ou un document de chaque modèle qui en a un */
    private array $fiches = [];

    /** Le jour du cas, compté depuis le 14 octobre 2026. */
    private int $jour = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-10-14 10:00:00'));
        $premier = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon']));
        $second = $this->ouvrirUnAutreMagasin($premier, ['nom' => 'Villeurbanne']);
        $ailleurs = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ailleurs']));
        $this->magasins = [$premier, $second, $ailleurs];

        foreach ($this->magasins as $magasin) {
            $this->fiches[$magasin->getKey()] = app(TenantContext::class)->runAs($magasin, function () use ($magasin): array {
                $client = Client::factory()->create();
                $produit = Produit::factory()->create(['prix_vente_ttc' => '10.00', 'taux_tva' => 20, 'suivi_stock' => false]);
                $vente = self::vendreAuComptant(self::responsableDeTest($magasin), $client, [['produit_id' => $produit->getKey(), 'quantite' => 1]]);

                return [$client, Fournisseur::factory()->create(), $produit, $vente, $vente->allocationsPaiement()->firstOrFail()->paiement()->firstOrFail()];
            });
        }
    }

    public function test_un_compte_tire_au_hasard_ne_sort_jamais_de_son_magasin_ni_de_ses_droits(): void
    {
        $cases = PermissionRegistry::pourLeType(TypeDActivite::Commerce);

        $this->pourChaqueCas(
            self::GRAINE,
            self::NOMBRE_DE_CAS,
            fn (Tirage $tirage): array => [
                'role' => $tirage->parmi(['vendeur', 'vendeur', 'observateur', 'observateur', 'responsable', 'administrateur']),
                'magasin' => $tirage->entier(0, 1),
                'autorisations' => array_values(array_filter($cases, fn (): bool => $tirage->uneFoisSur(2))),
                'tous_les_magasins' => $tirage->uneFoisSur(2),
            ],
            function (array $cas): void {
                // Un compte créé par jour : jamais la limite de création du jour.
                $this->travelTo(self::aParis(Calendrier::ajouterJours('2026-10-14', ++$this->jour).' 10:00:00'));
                $magasin = $this->magasins[$cas['magasin']];
                $compte = $this->compte($cas, $magasin);
                $this->actingAs($compte);

                foreach ($this->magasins as $autre) {
                    $ouvre = $compte->peutOuvrir($autre);
                    $autorisations = $ouvre ? $compte->autorisationsDans($autre) : [];

                    app(TenantContext::class)->runAs($autre, function () use ($compte, $autre, $ouvre, $autorisations): void {
                        foreach (self::CASES as $modele => [$voir, $creer]) {
                            $this->assertSame($ouvre && in_array($voir, $autorisations, true), $compte->can('viewAny', $modele), "{$modele} : la liste dans {$autre->nom} ne suit pas la case {$voir}.");

                            if ($creer !== null) {
                                $this->assertSame($ouvre && in_array($creer, $autorisations, true), $compte->can('create', $modele), "{$modele} : la création dans {$autre->nom} ne suit pas la case {$creer}.");
                            }
                        }

                        foreach ($this->fiches as $magasinDeLaFiche => $fiches) {
                            if ($magasinDeLaFiche === $autre->getKey()) {
                                continue;
                            }

                            foreach ($fiches as $fiche) {
                                $this->assertFalse($fiche::query()->whereKey($fiche->getKey())->exists(), $fiche::class.' d\'un autre magasin se lit depuis '.$autre->nom.'.');
                                $this->assertFalse($compte->can('view', $fiche), $fiche::class.' d\'un autre magasin s\'ouvre depuis '.$autre->nom.'.');
                                $this->assertFalse($compte->can('update', $fiche), $fiche::class.' d\'un autre magasin se modifie depuis '.$autre->nom.'.');
                            }
                        }
                    });
                }
            },
            fn (array $cas): iterable => array_map(
                fn (int $rang): array => [...$cas, 'autorisations' => array_values(array_diff_key($cas['autorisations'], [$rang => true]))],
                array_keys($cas['autorisations']),
            ),
            isoler: false,
        );
    }

    /**
     * @param  array{role: string, autorisations: list<string>, tous_les_magasins: bool}  $cas
     */
    private function compte(array $cas, Magasin $magasin): User
    {
        return match ($cas['role']) {
            'administrateur' => $this->administrateur($magasin, $cas['tous_les_magasins'] ? null : [$magasin->getKey()], [], array_values(array_intersect($cas['autorisations'], PermissionRegistry::cocheesParLeProprietaire(TypeDActivite::Commerce)))),
            'responsable' => $this->membre($magasin, RoleMagasin::Responsable),
            default => $this->membre($magasin, RoleMagasin::from($cas['role']), $cas['autorisations']),
        };
    }
}
