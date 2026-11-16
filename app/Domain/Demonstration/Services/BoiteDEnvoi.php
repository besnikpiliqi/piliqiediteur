<?php

namespace App\Domain\Demonstration\Services;

use App\Domain\Demonstration\Enums\QuoiDuMessage;
use App\Domain\Demonstration\Jobs\EnvoyerLesMessagesALaDemonstration;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Models\MessageVersLaDemonstration;
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
