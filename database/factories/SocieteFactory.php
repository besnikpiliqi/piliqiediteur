<?php

namespace Database\Factories;

use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Societe>
 *
 * Une société à l'identité légale complète, active et en cours de
 * vérification : validee() la valide, commeApresLInscription() lui donne
 * l'identité que le répertoire Sirene préremplit à l'inscription (sans
 * capital ni immatriculation), sansIdentiteLegale() n'en laisse que le
 * SIREN, avecProprietaire() lui crée son propriétaire. Son SIREN est
 * valide (clé de Luhn), toujours différent, et jamais l'un des SIREN aux
 * réponses particulières du répertoire factice (voir
 * FauxRepertoireSirene).
 */
class SocieteFactory extends Factory
{
    protected $model = Societe::class;

    public function definition(): array
    {
        $siren = self::sirenFictif();

        return [
            'siren' => $siren,
            'raison_sociale' => fake()->company(),
            'forme_juridique' => FormeJuridique::Sas,
            'capital_social' => '10000.00',
            'immatriculation' => 'RCS Paris',
            'numero_tva' => IdentifiantsInsee::numeroTvaPropose($siren),
            'siege_adresse' => fake()->streetAddress(),
            // Toujours en métropole, comme les magasins de MagasinFactory.
            'siege_code_postal' => fake()->numerify('75###'),
            'siege_ville' => fake()->city(),
        ];
    }

    /**
     * Un SIREN valide, toujours différent : 1 suivi de sept chiffres et de
     * la clé de Luhn ; jamais celui de La Poste ni l'un des SIREN
     * particuliers du répertoire factice, qui commencent par 0 ou 3.
     */
    public static function sirenFictif(): string
    {
        return IdentifiantsInsee::avecSaCle('1'.fake()->unique()->numerify('#######'));
    }

    public function validee(): static
    {
        return $this->state(fn (): array => ['validee_le' => now()]);
    }

    /**
     * L'identité que le répertoire Sirene factice préremplit à
     * l'inscription (voir CreerSocieteAction) : dénomination, forme
     * juridique, siège et n° de TVA proposé ; il manque le capital et
     * l'immatriculation, que le propriétaire complète.
     */
    public function commeApresLInscription(): static
    {
        return $this->state(fn (array $attributs): array => [
            'raison_sociale' => "SOCIETE FICTIVE {$attributs['siren']}",
            'categorie_juridique_insee' => '5710',
            'forme_juridique' => FormeJuridique::Sas,
            'capital_social' => null,
            'immatriculation' => null,
            'siege_adresse' => '1 RUE DE LA REPUBLIQUE',
            'siege_code_postal' => '75001',
            'siege_ville' => 'PARIS',
        ]);
    }

    /**
     * Rien d'autre que son SIREN.
     */
    public function sansIdentiteLegale(): static
    {
        return $this->state(fn (): array => [
            'raison_sociale' => null,
            'forme_juridique' => null,
            'capital_social' => null,
            'immatriculation' => null,
            'numero_tva' => null,
            'siege_adresse' => null,
            'siege_code_postal' => null,
            'siege_ville' => null,
        ]);
    }

    public function avecProprietaire(): static
    {
        return $this->afterCreating(function (Societe $societe): void {
            User::factory()->for($societe)->create();
        });
    }
}
