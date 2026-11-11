<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Support\RequeteDuModele;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * La requête qu'un écran de Filament passe au code, qu'il ne type que sur
 * Model (voir RequeteDuModele) : celle d'un modèle de la classe attendue,
 * sur sa table, est rendue elle-même, inchangée ; celle d'un autre modèle,
 * ou qui lit la table sous un autre nom, est refusée, et reste inchangée.
 */
class RequeteDuModeleTest extends TestCase
{
    public function test_la_requete_d_un_modele_sur_sa_table_est_rendue_elle_meme_inchangee(): void
    {
        $requete = Client::query()->where('nom', 'Dupont');
        $conditions = $requete->getQuery()->wheres;

        $lue = RequeteDuModele::de($requete, Client::class);

        $this->assertSame($requete, $lue);
        $this->assertInstanceOf(Client::class, $lue->getModel());
        $this->assertSame('clients', $lue->getQuery()->from);
        $this->assertSame($conditions, $lue->getQuery()->wheres);
    }

    public function test_la_requete_d_un_autre_modele_est_refusee(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(RequeteDuModele::REFUS, Client::class, Produit::class, 'produits'));

        RequeteDuModele::de(Produit::query(), Client::class);
    }

    public function test_une_requete_qui_lit_la_table_sous_un_autre_nom_est_refusee_et_reste_inchangee(): void
    {
        $requete = Client::query()->from('clients', 'laravel_reserved_0');

        try {
            RequeteDuModele::de($requete, Client::class);
            $this->fail('Une requête qui lit la table sous un autre nom aurait dû être refusée.');
        } catch (UnexpectedValueException $refus) {
            $this->assertSame(sprintf(RequeteDuModele::REFUS, Client::class, Client::class, 'clients as laravel_reserved_0'), $refus->getMessage());
        }

        $this->assertSame('clients as laravel_reserved_0', $requete->getQuery()->from);
    }
}
