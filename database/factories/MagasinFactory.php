<?php

namespace Database\Factories;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Magasin>
 *
 * Un magasin d'une société validée, à l'identité légale complète, avec son
 * propriétaire (voir SocieteFactory) : son établissement est vérifié, en
 * métropole (voir dansLeTerritoire() pour un autre territoire), son
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
            'territoire' => Territoire::Metropole,
            'adresse' => fake()->streetAddress(),
            // Toujours à Paris, en métropole, comme son territoire : un code
            // postal tiré au hasard pourrait tomber en Corse ou outre-mer.
            'code_postal' => fake()->numerify('75###'),
            'ville' => fake()->city(),
            'pays' => 'FR',
            'email' => fake()->unique()->companyEmail(),
            // Toujours au format que le formulaire accepte (TextInput::tel()) :
            // fr_FR tire aussi « +33 (0)X … », qu'il refuserait.
            'telephone' => fake()->numerify('01 ## ## ## ##'),
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
     * heure de son calendrier, comme si sa société avait été validée alors.
     */
    public static function ouvrirSaPremierePeriode(Magasin $magasin, ?User $par = null): void
    {
        (new PeriodeActivite)->forceFill([
            'magasin_id' => $magasin->getKey(),
            'debut' => $magasin->calendrier()->heureMurale(self::DEBUT_D_ACTIVITE),
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
     * Un magasin de ce territoire, à une adresse qui y est : Ajaccio,
     * Pointe-à-Pitre, Fort-de-France ou Saint-Denis de La Réunion ; la
     * Guyane et Mayotte, que le logiciel ne prend pas encore en charge, à
     * Cayenne et à Mamoudzou.
     */
    public function dansLeTerritoire(Territoire $territoire): static
    {
        [$codePostal, $ville] = self::lieuDuTerritoire($territoire);

        return $this->state(fn (): array => ['territoire' => $territoire, 'code_postal' => $codePostal, 'ville' => $ville]);
    }

    /**
     * Un lieu de chaque territoire : son code postal, sa ville et son code
     * de commune (code officiel géographique), tels que le répertoire
     * Sirene les donnerait d'un établissement de cette ville.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function lieuDuTerritoire(Territoire $territoire): array
    {
        return match ($territoire) {
            Territoire::Metropole => ['75001', 'Paris', '75056'],
            Territoire::Corse => ['20000', 'Ajaccio', '2A004'],
            Territoire::Guadeloupe => ['97110', 'Pointe-à-Pitre', '97120'],
            Territoire::Martinique => ['97200', 'Fort-de-France', '97209'],
            Territoire::Guyane => ['97300', 'Cayenne', '97302'],
            Territoire::Reunion => ['97400', 'Saint-Denis', '97411'],
            Territoire::Mayotte => ['97600', 'Mamoudzou', '97611'],
        };
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
