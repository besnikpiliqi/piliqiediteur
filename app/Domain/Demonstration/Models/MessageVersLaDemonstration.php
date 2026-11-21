<?php

namespace App\Domain\Demonstration\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Demonstration\Enums\QuoiDuMessage;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Un message de la boîte d'envoi du site réel vers le site de
 * démonstration (voir EnvoyerLesMessagesALaDemonstration) : déposé par le
 * geste qui le fait naître, dans sa transaction, signé seulement au moment
 * de l'envoi. Son contenu ne porte que les champs de la liste fermée des
 * messages (voir MessagesSignes), sans signature, sans nonce ni instant
 * d'émission.
 *
 * Lectures hors de la société active : le travail d'envoi et la tâche des
 * relances, qui filtrent explicitement par démonstration et par société.
 *
 * @property int $demonstration_id
 * @property QuoiDuMessage $quoi
 * @property array<string, bool|int|string> $contenu
 * @property CarbonImmutable $cree_le
 * @property CarbonImmutable|null $envoye_le
 * @property CarbonImmutable|null $accuse_le
 * @property int|null $code_de_la_reponse
 * @property int $tentatives
 * @property CarbonImmutable|null $prochain_essai_le
 * @property string|null $derniere_erreur
 */
class MessageVersLaDemonstration extends Model
{
    use BelongsToSociete;

    public $timestamps = false;

    protected $table = 'messages_vers_la_demonstration';

    // Tout s'écrit par la boîte d'envoi, jamais en mass assignment.
    protected $fillable = [];

    protected $casts = [
        'quoi' => QuoiDuMessage::class,
        'contenu' => 'array',
        'demonstration_id' => 'integer',
        'code_de_la_reponse' => 'integer',
        'tentatives' => 'integer',
        'cree_le' => Instant::class,
        'envoye_le' => Instant::class,
        'accuse_le' => Instant::class,
        'prochain_essai_le' => Instant::class,
    ];
}
