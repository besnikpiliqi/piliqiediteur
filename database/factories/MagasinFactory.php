<?php

namespace Database\Factories;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Magasin>
 *
 * Un magasin d'une société validée, à l'identité légale complète, avec son
 * propriétaire (voir SocieteFactory) : son établissement est vérifié, son
 * adresse complète, il facture des professionnels comme des particuliers.
 * Son SIRET commence par le SIREN de sa société, libre dans la société ;
 * dans une société validée, il se réserve comme à sa création (voir
 * CreerEtablissementAction), et le magasin est en activité depuis
 * DEBUT_D_ACTIVITE (voir ouvrirSaPremierePeriode()).
 */
class MagasinFactory extends Factory
{
    /**
     * Le début de la première période d'activité des magasins des tests :
     * avant toute journée qu'un test visite, pour que ses ventes, à
     * quelque date qu'il les fasse, tombent dans une période d'activité
     * (voir CloturerLesJourneesAction).
     */
    public const DEBUT_D_ACTIVITE = '2026-01-01 00:00:00';

    protected $model = Magasin::class;

    public function definition(): array
    {
        return [
            'societe_id' => Societe::factory()->validee()->avecProprietaire(),
            'nom' => fake()->unique()->company(),
            'siret' => fn (array $attributs): string => self::siretLibre(Societe::query()->findOrFail($attributs['societe_id'])),
            'etablissement_verifie_le' => now(),
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

    public function configure(): static
    {
        return $this->afterCreating(function (Magasin $magasin): void {
            if ($magasin->societe->estValidee()) {
                app(SiretsDesMagasins::class)->reserver($magasin);
                self::ouvrirSaPremierePeriode($magasin);
            }
        });
    }

    /**
     * Ouvre la première période d'activité du magasin à DEBUT_D_ACTIVITE,
     * comme si sa société avait été validée alors.
     */
    public static function ouvrirSaPremierePeriode(Magasin $magasin, ?User $par = null): void
    {
        (new PeriodeActivite)->forceFill([
            'magasin_id' => $magasin->getKey(),
            'debut' => CarbonImmutable::parse(self::DEBUT_D_ACTIVITE, config('app.timezone')),
            'ouverte_par' => $par?->getKey(),
        ])->save();
    }

    /**
     * Un magasin d'une nouvelle société validée, avec son propriétaire,
     * dont ces attributs règlent l'identité ou la fiscalité (voir
     * SocieteFactory).
     *
     * @param  array<string, mixed>  $attributs
     */
    public function avecLaSociete(array $attributs): static
    {
        return $this->state(fn (): array => ['societe_id' => Societe::factory()->validee()->avecProprietaire()->state($attributs)]);
    }

    /**
     * Le premier SIRET valide de la société qu'aucun de ses magasins ne
     * porte : son SIREN, puis un NIC de quatre chiffres (0001, 0002...) et
     * sa clé. Un NIC qui commence par 9 désigne un établissement fermé au
     * répertoire factice (voir FauxRepertoireSirene) : jamais celui-ci.
     */
    public static function siretLibre(Societe $societe): string
    {
        for ($rang = 1; ; $rang++) {
            $siret = IdentifiantsInsee::avecSaCle($societe->siren.sprintf('%04d', $rang));

            if (! Magasin::query()->where('societe_id', $societe->getKey())->where('siret', $siret)->exists()) {
                return $siret;
            }
        }
    }

    /**
     * Le NIC de ce premier SIRET libre (voir siretLibre()).
     */
    public static function nicLibre(Societe $societe): string
    {
        return substr(self::siretLibre($societe), 9);
    }
}
