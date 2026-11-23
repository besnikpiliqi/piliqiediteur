<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Facturation\Enums\ReponseAuDevis;
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
 * 256 bits, se tire alors une seule fois : la base n'en garde que
 * l'empreinte SHA-256, aucun journal ne l'écrit, et seuls la tâche
 * d'envoi, qui le porte chiffré jusqu'au départ de l'e-mail, le même à
 * chaque tentative, et l'e-mail le portent (voir
 * EnvoyerDocumentJob::lienDeReponse()).
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
     * La seule réponse d'une adresse au jeton inconnu, ou à un lien qui ne
     * vaut plus et qui n'a pas enregistré l'acceptation du devis (retiré,
     * remplacé par un nouvel envoi, devis refusé, expiré, modifié ou
     * accepté autrement) : elle ne dit rien d'autre. Le lien qui a
     * enregistré l'acceptation montre à la place sa confirmation, tant que
     * le devis la garde (voir confirmeLAcceptation()), et celui qui a
     * enregistré le refus le dit au formulaire renvoyé (voir
     * confirmeLeRefus()).
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
     * jeton inconnu, et sans aucune lecture pour un jeton qui n'a pas la
     * forme d'un jeton (une adresse tronquée ou altérée). Seulement les
     * colonnes qui disent si le lien vaut et de quel devis il s'agit.
     */
    public static function parSonJeton(string $jeton): ?self
    {
        if (preg_match('/\A[A-Za-z0-9]{'.self::LONGUEUR_DU_JETON.'}\z/', $jeton) !== 1) {
            return null;
        }

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
     * Le lien par lequel le client a accepté le devis montre encore sa
     * confirmation et le PDF du devis signé (SIGN-DEVIS, demande de
     * l'éditeur du 14/11/2026), tant qu'il l'aurait montré pour répondre :
     * pas retiré, le dernier lien de son devis, dans la validité du devis ;
     * et tant que le devis garde cette acceptation (accepté, ou transformé
     * en facture après elle, voir Devis::reponseALaVersionEnvoyee()) : un
     * devis modifié depuis, repassé en brouillon, ne la montre plus.
     */
    public function confirmeLAcceptation(Devis $devis): bool
    {
        return $this->aEnregistre($devis, ReponseAuDevis::Acceptation);
    }

    /**
     * Le lien par lequel le client a refusé le devis dit encore son refus
     * (« Votre refus est enregistré ») à qui renvoie le formulaire : le
     * second envoi d'un double clic sur « Refuser le devis » ne fait pas
     * croire au client que son refus a échoué. Aux mêmes conditions que
     * confirmeLAcceptation() ; jamais la page du lien ni un PDF : un refus
     * ne montre rien de plus que son message.
     */
    public function confirmeLeRefus(Devis $devis): bool
    {
        return $this->aEnregistre($devis, ReponseAuDevis::Refus);
    }

    /**
     * Ce lien a enregistré cette réponse du client, et son devis la garde
     * (voir Devis::reponseALaVersionEnvoyee()) : le lien n'est pas retiré,
     * c'est le dernier de son devis, dans la validité du devis.
     */
    private function aEnregistre(Devis $devis, ReponseAuDevis $enregistree): bool
    {
        $reponse = $devis->reponseALaVersionEnvoyee();

        return $this->retire_le === null
            && $devis->getKey() === $this->devis_id
            && $reponse !== null
            && (int) $reponse->lien_du_devis_id === $this->id
            && $reponse->reponse === $enregistree
            && ! $devis->validiteDepassee()
            && self::query()->where('devis_id', $this->devis_id)->where('id', '>', $this->getKey())->doesntExist();
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
            && $devis->magasin->peutVendre()
            && $this->reponse()->doesntExist()
            && self::query()->where('devis_id', $this->devis_id)->where('id', '>', $this->getKey())->doesntExist();
    }
}
