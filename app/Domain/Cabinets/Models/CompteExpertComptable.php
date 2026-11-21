<?php

namespace App\Domain\Cabinets\Models;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * L'attachement d'un compte d'expert-comptable de la société à la
 * déclaration qui l'a fait naître (voir comptes_expert_comptable et
 * DeclarerSonExpertComptableAction), pour toujours, dans la société qui
 * déclare. Il porte le lien du mot de passe en cours, valable 7 jours
 * jusqu'à la même heure de Paris (voir nouveauLien()) : seule l'empreinte
 * SHA-256 de son jeton s'enregistre, et le jeton ne vit qu'en mémoire
 * jusqu'à l'envoi de l'e-mail ; et la date du premier mot de passe choisi
 * par la personne, qui ferme la correction de son adresse par le
 * propriétaire.
 *
 * Seule lecture hors de la société active : avant qu'aucune société ne
 * soit ouverte, la ligne que désigne le jeton d'un lien (voir
 * parSonJeton()), filtrée par son empreinte.
 *
 * @property int $lien_id
 * @property int $user_id
 * @property CarbonImmutable|null $jeton_expire_le
 * @property CarbonImmutable|null $mot_de_passe_choisi_le
 */
class CompteExpertComptable extends Model
{
    use BelongsToSociete;

    public const DUREE_DU_LIEN_EN_JOURS = 7;

    protected $table = 'comptes_expert_comptable';

    protected $fillable = [];

    protected $hidden = ['jeton_hache'];

    protected $casts = [
        'lien_id' => 'integer',
        'user_id' => 'integer',
        'jeton_expire_le' => Instant::class,
        'mot_de_passe_choisi_le' => Instant::class,
    ];

    public static function empreinte(#[SensitiveParameter] string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    /**
     * La ligne que désigne ce jeton, hors de toute société (la page du lien
     * s'ouvre avant qu'aucune ne le soit), expirée ou non ; null pour un
     * jeton inconnu, déjà servi, remplacé ou effacé. Seulement ce que la
     * page lit.
     */
    public static function parSonJeton(#[SensitiveParameter] string $jeton): ?self
    {
        return self::withoutGlobalScopes()
            ->where('jeton_hache', self::empreinte($jeton))
            ->first(['id', 'societe_id', 'lien_id', 'user_id', 'jeton_expire_le']);
    }

    /**
     * Le lien en cours est-il encore valable : présent, et pas encore
     * arrivé à son échéance ?
     */
    public function lienValable(): bool
    {
        return $this->jeton_expire_le !== null && $this->jeton_expire_le->isFuture();
    }

    /**
     * Tire un nouveau lien du mot de passe, valable jusqu'à la même heure
     * de Paris sept jours plus tard, qui remplace le précédent : son
     * empreinte et son échéance s'écrivent, dans la transaction de
     * l'appelant ; le jeton, rendu, ne s'écrit jamais, ni en base, ni dans
     * un journal, ni dans une file d'attente.
     */
    public function nouveauLien(): string
    {
        $jeton = Str::random(64);

        $this->forceFill([
            'jeton_hache' => self::empreinte($jeton),
            'jeton_expire_le' => Calendrier::deParis()->decaler(now(), CarbonInterval::days(self::DUREE_DU_LIEN_EN_JOURS)),
        ])->save();

        return $jeton;
    }

    /**
     * Efface le lien en cours, qui ne sert plus : quand il a servi, quand
     * l'e-mail n'a pas pu partir, quand le compte est suspendu ou retiré.
     */
    public function oublierLeLien(): void
    {
        if ($this->getAttribute('jeton_hache') === null && $this->jeton_expire_le === null) {
            return;
        }

        $this->forceFill(['jeton_hache' => null, 'jeton_expire_le' => null])->save();
    }

    public function aChoisiSonMotDePasse(): bool
    {
        return $this->mot_de_passe_choisi_le !== null;
    }
}
