<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Domain\Tenancy\Support\ValeurRequise;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Le lien secret par lequel le client répond à un devis envoyé par
 * e-mail, sans compte (décision de l'éditeur du 02/11/2026) : un par
 * envoi, créé avec lui (voir EnvoyerDocumentAction). Son jeton, d'au moins
 * 256 bits, est tiré par la tâche d'envoi juste avant le départ de
 * l'e-mail, qui seul le porte (voir EnvoyerDocumentJob::jetonDuLien()) :
 * la base n'en garde que l'empreinte SHA-256, aucun journal ne l'écrit.
 *
 * Il vaut tant que son devis, envoyé, attend sa réponse dans sa validité,
 * dans la version que cet e-mail a portée (voir estValable()) : il cesse
 * aussitôt de valoir à la réponse du client, à l'acceptation ou au refus
 * constaté par le magasin, à l'expiration, à la transformation en facture,
 * à une modification du devis, à un nouvel envoi, qui donne un nouveau
 * lien, et quand le magasin le retire (voir RetirerLeLienDuDevisAction).
 *
 * Seule lecture hors du magasin actif : celle du lien que désigne le
 * jeton de l'adresse, avant qu'aucun magasin ne soit ouvert (voir
 * parSonJeton()).
 *
 * @property int $devis_id
 * @property int $envoi_document_id
 * @property CarbonImmutable|null $retire_le
 * @property CarbonInterface $created_at
 * @property-read Devis $devis
 * @property-read EnvoiDocument $envoi
 * @property-read ReponseDuDevis|null $reponse
 */
class LienDuDevis extends Model
{
    use BelongsToMagasin, FigeLeNomDeSesAuteurs;

    /**
     * La longueur du jeton : 64 caractères alphanumériques, environ 381
     * bits.
     */
    public const LONGUEUR_DU_JETON = 64;

    /**
     * La seule réponse d'une adresse au jeton inconnu, expiré ou déjà
     * servi : elle ne dit rien d'autre.
     */
    public const PLUS_VALABLE = 'Ce lien n\'est plus valable.';

    protected $table = 'liens_des_devis';

    protected $fillable = [];

    protected $hidden = ['jeton_hache'];

    protected $casts = [
        'retire_le' => Instant::class,
    ];

    protected static function colonnesAuNomFige(): array
    {
        return ['retire_par' => 'retire_par_nom'];
    }

    /**
     * Un nouveau jeton, à mettre dans l'e-mail : seule son empreinte se
     * garde (voir empreinte()).
     */
    public static function nouveauJeton(): string
    {
        return Str::random(self::LONGUEUR_DU_JETON);
    }

    /**
     * L'empreinte d'un jeton, telle qu'elle se garde et se cherche.
     */
    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    /**
     * Le lien que désigne ce jeton, hors de tout magasin (la page du lien
     * s'ouvre avant qu'aucun ne le soit), valable ou non ; null pour un
     * jeton inconnu. Seulement les colonnes qui disent si le lien vaut et
     * de quel devis il s'agit.
     */
    public static function parSonJeton(string $jeton): ?self
    {
        return self::withoutGlobalScopes()
            ->where('jeton_hache', self::empreinte($jeton))
            ->first(['id', 'magasin_id', 'devis_id', 'envoi_document_id', 'retire_le', 'created_at']);
    }

    /**
     * @return BelongsTo<Devis, $this>
     */
    public function devis(): BelongsTo
    {
        return $this->belongsTo(Devis::class);
    }

    /**
     * @return BelongsTo<EnvoiDocument, $this>
     */
    public function envoi(): BelongsTo
    {
        return $this->belongsTo(EnvoiDocument::class, 'envoi_document_id');
    }

    /**
     * @return HasOne<ReponseDuDevis, $this>
     */
    public function reponse(): HasOne
    {
        return $this->hasOne(ReponseDuDevis::class);
    }

    /**
     * Le lien vaut encore, d'après son devis tel que l'appelant l'a lu
     * (sous verrou pour une réponse, voir RepondreAuDevisEnLigneAction) :
     * pas retiré, sans réponse, le dernier lien de son devis, qui est
     * envoyé, dans sa validité, et n'a pas été renvoyé dans une nouvelle
     * version depuis cet envoi ; son magasin écrit encore à ses clients.
     */
    public function estValable(Devis $devis): bool
    {
        return $this->retire_le === null
            && $devis->getKey() === $this->devis_id
            && $devis->statut === StatutDevis::Envoye
            && ! $devis->validiteDepassee()
            && ValeurRequise::de($devis->date_emission, 'la date d\'envoi du devis')->lte($this->created_at)
            && $devis->magasin->peutEcrireAuxClients()
            && $this->reponse()->doesntExist()
            && self::query()->where('devis_id', $this->devis_id)->where('id', '>', $this->getKey())->doesntExist();
    }
}
