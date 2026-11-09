<?php

namespace Tests\Unit;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Les autorisations cochées se vérifient contre leur référentiel : une
 * autorisation inconnue, ou qui ne s'accorde pas à un cabinet d'expertise
 * comptable, est refusée, et le refus la nomme (sa clé, son libellé, ou le
 * type d'une valeur qui n'est pas une clé) ; celles qui sont retenues le
 * sont sans doublon, dans l'ordre alphabétique. Un client ne propose à son
 * cabinet que les libellés des autorisations qu'il peut lui accorder, dans
 * l'ordre du registre.
 */
final class VerificationDesAutorisationsTest extends TestCase
{
    public function test_une_autorisation_de_societe_inconnue_est_refusee_et_nommee(): void
    {
        $this->assertRefus('Autorisation inconnue : societe.tout_faire', fn () => AutorisationsSociete::verifierConnues([AutorisationsSociete::MAGASINS_GERER, 'societe.tout_faire']));
        $this->assertRefus('Autorisation inconnue : 12', fn () => AutorisationsSociete::verifierConnues([12]));
        $this->assertRefus('Autorisation inconnue : array', fn () => AutorisationsSociete::verifierConnues([[AutorisationsSociete::MAGASINS_GERER]]));

        $this->assertSame(
            [AutorisationsSociete::MAGASINS_GERER, AutorisationsSociete::RESPONSABLES_NOMMER],
            AutorisationsSociete::verifierConnues([AutorisationsSociete::RESPONSABLES_NOMMER, AutorisationsSociete::MAGASINS_GERER, AutorisationsSociete::RESPONSABLES_NOMMER]),
        );
    }

    public function test_une_autorisation_qui_ne_s_accorde_pas_a_un_cabinet_est_refusee_et_nommee(): void
    {
        $this->assertRefus('Autorisation qui ne s\'accorde pas à un cabinet : Factures : créer', fn () => PermissionRegistry::verifierDuCabinet([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE], TypeDActivite::Commerce));
        $this->assertRefus('Autorisation qui ne s\'accorde pas à un cabinet : facture.tout_faire', fn () => PermissionRegistry::verifierDuCabinet(['facture.tout_faire'], TypeDActivite::Commerce));
        $this->assertRefus('Autorisation qui ne s\'accorde pas à un cabinet : int', fn () => PermissionRegistry::verifierDuCabinet([7], TypeDActivite::Commerce));

        $this->assertSame(
            [PermissionRegistry::CLIENT_VIEW, PermissionRegistry::FACTURE_VIEW],
            PermissionRegistry::verifierDuCabinet([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::CLIENT_VIEW, PermissionRegistry::FACTURE_VIEW], TypeDActivite::Commerce),
        );
    }

    public function test_un_client_ne_propose_a_son_cabinet_que_les_libelles_de_ce_qu_il_peut_lui_accorder(): void
    {
        foreach (TypeDActivite::cases() as $type) {
            if ($type === TypeDActivite::Cabinet) {
                continue;
            }

            $libelles = PermissionRegistry::libellesDuCabinet($type);
            $attendues = array_values(array_intersect(array_keys(PermissionRegistry::libelles()), PermissionRegistry::duCabinet($type)));

            $this->assertSame($attendues, array_keys($libelles), $type->value);

            foreach ($libelles as $autorisation => $libelle) {
                $this->assertSame($autorisation === PermissionRegistry::PRODUIT_CREATE ? 'Produits : créer ceux d\'une facture d\'achat ou d\'un inventaire d\'ouverture' : PermissionRegistry::libelles()[$autorisation], $libelle, $autorisation);
            }
        }
    }

    private function assertRefus(string $message, Closure $verification): void
    {
        try {
            $verification();
            $this->fail("Refus attendu : {$message}");
        } catch (InvalidArgumentException $refus) {
            $this->assertSame($message, $refus->getMessage());
        }
    }
}
