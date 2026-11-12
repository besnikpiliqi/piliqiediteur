<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Facturation\Contracts\DocumentEnvoyable;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Models\User;
use Database\Factories\EnvoiDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Un e-mail parti (ou à partir) avec un devis, une facture ou un avoir en
 * pièce jointe : créé par EnvoyerDocumentAction, envoyé par
 * EnvoyerDocumentJob, qui en constate le résultat. Seule une relance en
 * échec en change ensuite : EnvoyerDocumentAction::relancer() la
 * reprogramme.
 *
 * Son objet est noté à la demande, puis relu au départ de l'e-mail (voir
 * objetPour()), comme le rappel d'une relance : il est celui que le
 * client a reçu. Son document manque quand il a été supprimé depuis (un
 * devis brouillon jamais numéroté, seul document qui se supprime).
 *
 * @property-read Facture|Avoir|Devis|RecuPaiement|null $document
 */
class EnvoiDocument extends Model
{
    use FigeLeNomDeSesAuteurs;

    /**
     * Au-delà de ce délai sans tentative en cours ni prévue, un envoi en
     * attente attend trop longtemps : aucun travailleur ne prend la file
     * d'attente (voir attendTropLongtemps()).
     */
    public const ATTENTE_SANS_TENTATIVE_EN_SECONDES = 120;

    public const MESSAGE_ATTENTE_PROLONGEE = 'L\'envoi attend encore : il partira dès que le service d\'envoi reprendra ; sinon vous serez prévenu.';

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'envoye_par' => 'envoye_par_nom',
        ];
    }

    /** @use HasFactory<EnvoiDocumentFactory> */
    use BelongsToMagasin, HasFactory;

    protected $table = 'envois_documents';

    // statut, erreur, envoye_le, tentatives, derniere_erreur et
    // prochaine_tentative_le : jamais en mass assignment, uniquement par
    // EnvoyerDocumentJob, qui les constate (et par la reprogrammation d'une
    // relance en échec).
    protected $fillable = [
        'destinataire',
        'objet',
        'message',
        'nature',
        'niveau_relance',
        'envoye_par',
    ];

    protected $casts = [
        'nature' => NatureEnvoi::class,
        'statut' => StatutEnvoi::class,
        'niveau_relance' => 'integer',
        'envoye_le' => Instant::class,
        'tentatives' => 'integer',
        'prochaine_tentative_le' => Instant::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (EnvoiDocument $envoi) {
            $envoi->statut ??= StatutEnvoi::EnAttente;
        });
    }

    /**
     * L'objet de l'e-mail : le document, son numéro et le nom de son
     * émetteur, celui que l'e-mail présente et que son PDF imprime (voir
     * DocumentEnvoyable::emetteur() et DocumentMail), précédés du niveau
     * pour une relance.
     *
     * @param  Facture|Avoir|Devis|RecuPaiement  $document
     *
     * @throws DocumentSansEnregistrementSigneException
     */
    public static function objetPour(DocumentEnvoyable $document, ?int $niveauRelance = null): string
    {
        $objet = "{$document->libelleDocument()} {$document->numero} — {$document->emetteur()->nom}";

        return $niveauRelance === null ? $objet : "Relance n°{$niveauRelance} : {$objet}";
    }

    /**
     * En attente depuis plus de 2 minutes sans tentative en cours ni
     * prévue : sa dernière activité (sa demande, le début de sa dernière
     * tentative, l'heure prévue de la suivante) est passée depuis plus de
     * ce délai. Le signal se lit sur l'envoi du magasin, jamais sur la file
     * d'attente, commune à toutes les sociétés.
     */
    public function attendTropLongtemps(): bool
    {
        if ($this->statut !== StatutEnvoi::EnAttente) {
            return false;
        }

        $limite = now()->subSeconds(self::ATTENTE_SANS_TENTATIVE_EN_SECONDES);

        return ValeurRequise::de($this->updated_at, 'la date de la dernière activité de l\'envoi')->lt($limite)
            && ($this->prochaine_tentative_le === null || $this->prochaine_tentative_le->lt($limite));
    }

    /**
     * Ce que la fiche du document dit de l'envoi, à l'heure du calendrier
     * de son magasin : l'explication d'un échec ; pour un envoi en
     * attente, la cause de sa dernière tentative ratée et l'heure de la
     * suivante, ou qu'il attend encore le service d'envoi ; rien sinon.
     */
    public function detail(): ?string
    {
        return match (true) {
            $this->statut === StatutEnvoi::Echec => $this->erreur,
            $this->attendTropLongtemps() => self::MESSAGE_ATTENTE_PROLONGEE,
            $this->statut === StatutEnvoi::EnAttente && $this->derniere_erreur !== null && $this->prochaine_tentative_le !== null => Str::ucfirst($this->derniere_erreur).', nouvelle tentative vers '.$this->magasin->calendrier()->afficher($this->prochaine_tentative_le, 'H:i').'.',
            default => null,
        };
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function document(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function envoyePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'envoye_par');
    }
}
