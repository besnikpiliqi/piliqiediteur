<?php

namespace Database\Factories;

use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnvoiDocument>
 *
 * Comme pour toute donnée d'un magasin, positionnez le TenantContext avant
 * create() : BelongsToMagasin impose le magasin_id. Le document se donne
 * avec for($document, 'document').
 */
class EnvoiDocumentFactory extends Factory
{
    protected $model = EnvoiDocument::class;

    public function definition(): array
    {
        return [
            'document_type' => (new Facture)->getMorphClass(),
            'document_id' => Facture::factory(),
            'destinataire' => fake()->safeEmail(),
            'objet' => 'Facture '.fake()->numerify('F-2026-####'),
            'message' => null,
            'nature' => NatureEnvoi::Envoi,
        ];
    }

    public function envoye(): static
    {
        return $this->state(fn () => [
            'statut' => StatutEnvoi::Envoye,
            'envoye_le' => now(),
        ]);
    }

    public function enEchec(): static
    {
        return $this->state(fn () => [
            'statut' => StatutEnvoi::Echec,
            'erreur' => 'Le serveur d\'envoi des e-mails n\'a pas accepté le message.',
        ]);
    }

    public function relance(int $niveau): static
    {
        return $this->state(fn () => [
            'nature' => NatureEnvoi::Relance,
            'niveau_relance' => $niveau,
        ]);
    }
}
