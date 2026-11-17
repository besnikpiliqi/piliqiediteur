<?php

namespace App\Domain\Demonstration\Services;

use App\Domain\Demonstration\Enums\QuoiDuMessage;
use App\Domain\Demonstration\Jobs\EnvoyerLesMessagesALaDemonstration;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Models\MessageVersLaDemonstration;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CleDuModele;

/**
 * La boîte d'envoi du site réel vers le site de démonstration (B.13) :
 * chaque geste y dépose son message dans sa propre transaction, puis le
 * travail d'envoi de cette démonstration part une fois la transaction
 * validée. Le site réel n'attend jamais le site de démonstration : un
 * geste réussit dès que son message est dans la boîte.
 *
 * Le contenu déposé ne porte que les champs de la liste fermée des
 * messages, sans signature (voir MessagesSignes) : l'action, la référence,
 * et selon l'action le repère (six caractères tirés au hasard, qui ne
 * disent rien de la société et font reconnaître sa copie), le type, le
 * fait que la société soit validée,
 * l'empreinte du lien, sa génération et la fin.
 */
final class BoiteDEnvoi
{
    public function creer(Demonstration $demonstration, bool $validee): void
    {
        $this->deposer($demonstration, QuoiDuMessage::Creer, [
            'repere' => $demonstration->repere,
            'type' => $demonstration->type_d_activite->value,
            'validee' => $validee,
            'empreinte_du_lien' => $demonstration->empreinte_du_lien,
            'generation' => $demonstration->generation_du_lien,
            'fin' => MessagesSignes::instant($demonstration->fin_le),
        ]);
    }

    public function renouvelerLeLien(Demonstration $demonstration): void
    {
        $this->deposer($demonstration, QuoiDuMessage::RenouvelerLeLien, [
            'empreinte_du_lien' => $demonstration->empreinte_du_lien,
            'generation' => $demonstration->generation_du_lien,
        ]);
    }

    public function recommencer(Demonstration $demonstration): void
    {
        $this->deposer($demonstration, QuoiDuMessage::Recommencer, []);
    }

    public function supprimer(Demonstration $demonstration): void
    {
        $this->deposer($demonstration, QuoiDuMessage::Supprimer, []);
    }

    public function demanderLEtat(Demonstration $demonstration): void
    {
        $this->deposer($demonstration, QuoiDuMessage::Etat, []);
    }

    /**
     * Le « creer » de la démonstration telle qu'elle est maintenant, de
     * nouveau dans la boîte : quand le site de démonstration ne connaît
     * pas la copie alors qu'elle n'est pas encore prête (voir
     * EtatsDeLaDemonstration), ou que son registre a été perdu (voir
     * demonstration:renvoyer-les-demandes). Même référence, même repère,
     * même fin, et le lien en cours : son empreinte et sa génération
     * gardées, jamais celles de la demande, pour qu'un lien renouvelé (un
     * ancien lien coupé) ne rouvre jamais la copie ; la société dite
     * validée selon son état d'aujourd'hui.
     */
    public function renvoyerLaCreation(Demonstration $demonstration): void
    {
        $this->creer($demonstration, Societe::query()->findOrFail($demonstration->societe_id)->estValidee());
    }

    /**
     * @param  array<string, bool|int|string>  $champs
     */
    private function deposer(Demonstration $demonstration, QuoiDuMessage $quoi, array $champs): void
    {
        $message = new MessageVersLaDemonstration;
        $message->forceFill([
            'demonstration_id' => $demonstration->getKey(),
            'quoi' => $quoi,
            'contenu' => ['quoi' => $quoi->value, 'reference' => $demonstration->reference, ...$champs],
            'cree_le' => now(),
        ])->save();

        EnvoyerLesMessagesALaDemonstration::dispatch(CleDuModele::de($demonstration), (int) $demonstration->societe_id)->afterCommit();
    }
}
