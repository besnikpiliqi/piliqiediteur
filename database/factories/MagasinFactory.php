<?php

namespace Database\Factories;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ReglagesCommunsDeLaSociete;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Magasin>
 *
 * Un magasin à l'identité légale complète : il facture des professionnels
 * comme des particuliers. sansIdentiteLegale() donne celui qui vient de
 * s'inscrire, qui n'a encore que son nom.
 */
class MagasinFactory extends Factory
{
    protected $model = Magasin::class;

    public function definition(): array
    {
        return [
            'nom' => fake()->unique()->company(),
            'raison_sociale' => fake()->company(),
            'forme_juridique' => 'SAS',
            'siren' => $siren = fake()->unique()->numerify('#########'),
            'siret' => $siren.fake()->numerify('#####'),
            'immatriculation' => 'RCS Paris',
            'numero_tva' => 'FR'.fake()->numerify('##').$siren,
            'adresse' => fake()->streetAddress(),
            // Toujours en métropole : un code postal tiré au hasard pourrait
            // tomber en Corse ou outre-mer, et changer le taux de TVA
            // proposé (voir TerritoireTva).
            'code_postal' => fake()->numerify('75###'),
            'ville' => fake()->city(),
            'pays' => 'FR',
            'email' => fake()->unique()->companyEmail(),
            'telephone' => fake()->phoneNumber(),
        ];
    }

    /**
     * Un autre magasin de la société de $magasin : son SIREN, un SIRET de
     * ce SIREN et tous ses réglages communs, que seul le magasin principal
     * de la société fixe (voir ReglagesCommunsDeLaSociete).
     */
    public function deLaSocieteDe(Magasin $magasin): static
    {
        return $this->state(fn (): array => [
            'siren' => $magasin->siren,
            'siret' => $magasin->siren.fake()->unique()->numerify('#####'),
            ...$magasin->only(array_keys(ReglagesCommunsDeLaSociete::CHAMPS)),
        ]);
    }

    /**
     * Sans identité légale : ni raison sociale, ni forme juridique, ni
     * SIREN, SIRET, immatriculation, n° de TVA ou adresse.
     */
    public function sansIdentiteLegale(): static
    {
        return $this->state(fn (): array => [
            'raison_sociale' => null,
            'forme_juridique' => null,
            'siren' => null,
            'siret' => null,
            'immatriculation' => null,
            'numero_tva' => null,
            'adresse' => null,
            'code_postal' => null,
            'ville' => null,
        ]);
    }
}
