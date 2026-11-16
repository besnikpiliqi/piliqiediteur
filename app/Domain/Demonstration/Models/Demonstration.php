<?php

namespace App\Domain\Demonstration\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Demonstration\Enums\CauseDeLaFin;
use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * La démonstration d'une société, telle que le site réel la connaît
 * (décision de l'éditeur du 15/11/2026 en soirée, lot DEMO-COPIE) : une
 * copie de la société modèle fictive de son type, tenue par le site de
 * démonstration, que le propriétaire demande, ouvre, renouvelle et remet à
 * zéro depuis la carte « Ma démonstration » (voir GestesSurLaDemonstration).
 *
 * Le site réel n'en garde que la référence (adresse de la copie au
 * registre du site de démonstration, jamais affichée), le repère affiché,
 * le type de la société au moment de la demande, l'empreinte et la
 * génération du lien (jamais le lien), la dernière génération que le site
 * de démonstration a confirmée, la création, la fin à 30 jours, l'état et
 * la cause d'un échec. Ni le nom de la base de la copie, ni rien de ce que
 * les visiteurs y font.
 *
 * Lectures hors de la société active, limitées aux colonnes nommées et
 * filtrées par démonstration (voir ColonnesLuesHorsDeLaSociete) : les
 * commandes et le travail d'envoi du site réel, qui tournent hors de tout
 * contexte de société (voir EtatsDeLaDemonstration).
 *
 * @property string $uuid
 * @property string $reference
 * @property string $repere
 * @property TypeDActivite $type_d_activite
 * @property string $empreinte_du_lien
 * @property int $generation_du_lien
 * @property CarbonImmutable $lien_tire_le
 * @property int|null $generation_confirmee
 * @property CarbonImmutable|null $confirmee_le
 * @property CarbonImmutable $cree_le
 * @property CarbonImmutable $fin_le
 * @property EtatDeLaDemonstration $etat
 * @property int $essais_en_echec
 * @property CarbonImmutable|null $dernier_echec_le
 * @property string|null $cause_de_l_echec
 * @property CarbonImmutable|null $recommencee_le
 * @property CarbonImmutable|null $terminee_le
 * @property CauseDeLaFin|null $cause_de_la_fin
 * @property int $demandee_par
 * @property int $lien_tire_par
 */
class Demonstration extends Model
{
    use BelongsToSociete;

    /**
     * La durée d'une démonstration, de sa création à sa fin, à la seconde.
     */
    public const DUREE_EN_JOURS = 30;

    /**
     * Les colonnes que lisent les commandes et le travail du site réel hors
     * de tout contexte de société : jamais un nom.
     */
    public const COLONNES_LUES_HORS_DE_LA_SOCIETE = ['id', 'societe_id', 'reference', 'etat', 'fin_le', 'empreinte_du_lien', 'generation_du_lien', 'generation_confirmee', 'essais_en_echec', 'dernier_echec_le', 'lien_tire_le'];

    public $timestamps = false;

    protected $table = 'demonstrations';

    // Tout s'écrit par les gestes de la démonstration, jamais en mass assignment.
    protected $fillable = [];

    protected $hidden = ['reference', 'empreinte_du_lien'];

    protected $casts = [
        'type_d_activite' => TypeDActivite::class,
        'etat' => EtatDeLaDemonstration::class,
        'cause_de_la_fin' => CauseDeLaFin::class,
        'generation_du_lien' => 'integer',
        'generation_confirmee' => 'integer',
        'essais_en_echec' => 'integer',
        'demandee_par' => 'integer',
        'lien_tire_par' => 'integer',
        'lien_tire_le' => Instant::class,
        'confirmee_le' => Instant::class,
        'cree_le' => Instant::class,
        'fin_le' => Instant::class,
        'dernier_echec_le' => Instant::class,
        'recommencee_le' => Instant::class,
        'terminee_le' => Instant::class,
    ];

    /**
     * L'empreinte d'un lien, telle qu'elle se garde : le lien lui-même
     * n'est jamais enregistré.
     */
    public static function empreinteDuLien(string $lien): string
    {
        return hash('sha256', $lien);
    }

    /**
     * Le renouvellement du lien attend-il encore l'accusé du site de
     * démonstration ?
     */
    public function renouvellementSansAccuse(): bool
    {
        return $this->generation_du_lien > 1 && ($this->generation_confirmee ?? 0) < $this->generation_du_lien;
    }
}
