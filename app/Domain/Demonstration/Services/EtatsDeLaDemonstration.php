<?php

namespace App\Domain\Demonstration\Services;

use App\Domain\Demonstration\Actions\TerminerLaDemonstrationAction;
use App\Domain\Demonstration\Enums\CauseDeLaFin;
use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Enums\QuoiDuMessage;
use App\Domain\Demonstration\Messages\ReponseDuSiteDeDemonstration;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\AlerteEditeur;
use Carbon\CarbonImmutable;

/**
 * Ce que le site réel tire d'une réponse du site de démonstration (B.13) :
 * l'état de la carte, l'accusé d'une génération du lien et la cause d'un
 * échec, rien d'autre. Le site réel ne passe jamais à un état que la
 * démonstration n'a pas confirmé : « demandee » → « en_preparation » ou
 * « en_attente_d_une_place » → « prete » ; « en_echec » tant que le site
 * de démonstration réessaie ; après 3 réponses « en_echec » à au moins une
 * heure d'écart, la démonstration se termine (cause « échec »), l'éditeur
 * est prévenu et la carte revient à « À créer ». Un renouvellement n'est
 * dit coupé qu'à l'accusé 200 portant sa génération, jamais sur un 409.
 *
 * Appelé par le travail d'envoi, dans le contexte de la société de la
 * démonstration, sur la ligne relue sous son verrou.
 */
final class EtatsDeLaDemonstration
{
    public const ECHECS_AVANT_LA_FIN = 3;

    public const HEURES_ENTRE_DEUX_ECHECS = 1;

    public function __construct(
        private readonly TerminerLaDemonstrationAction $terminer,
        private readonly AlerteEditeur $alerte,
    ) {}

    public function appliquer(Demonstration $demonstration, QuoiDuMessage $quoi, ReponseDuSiteDeDemonstration $reponse): void
    {
        if ($demonstration->etat->estFinie()) {
            return;
        }

        if ($quoi === QuoiDuMessage::RenouvelerLeLien && $reponse->code === 200 && $reponse->generation !== null && $reponse->generation > ($demonstration->generation_confirmee ?? 0)) {
            $demonstration->forceFill([
                'generation_confirmee' => min($reponse->generation, $demonstration->generation_du_lien),
                'confirmee_le' => CarbonImmutable::now(),
            ])->save();
        }

        $etat = match (true) {
            $quoi === QuoiDuMessage::Creer && $reponse->code === 422 => 'en_echec',
            in_array($reponse->code, [200, 202], true) => $reponse->etat,
            default => null,
        };

        match ($etat) {
            'prete' => $this->passer($demonstration, EtatDeLaDemonstration::Prete),
            'en_preparation' => $this->passer($demonstration, EtatDeLaDemonstration::EnPreparation),
            'en_attente_d_une_place' => $this->passer($demonstration, EtatDeLaDemonstration::EnAttenteDUnePlace),
            'en_echec' => $this->echec($demonstration, $reponse->cause),
            default => null,
        };
    }

    private function passer(Demonstration $demonstration, EtatDeLaDemonstration $etat): void
    {
        if ($demonstration->etat !== $etat) {
            $demonstration->forceFill(['etat' => $etat])->save();
        }
    }

    private function echec(Demonstration $demonstration, ?string $cause): void
    {
        $maintenant = CarbonImmutable::now();
        $nouvelEssai = $demonstration->dernier_echec_le === null || $demonstration->dernier_echec_le->addHours(self::HEURES_ENTRE_DEUX_ECHECS)->lessThanOrEqualTo($maintenant);
        $essais = $demonstration->essais_en_echec + ($nouvelEssai ? 1 : 0);

        $demonstration->forceFill([
            'etat' => EtatDeLaDemonstration::EnEchec,
            'essais_en_echec' => $essais,
            'dernier_echec_le' => $nouvelEssai ? $maintenant : $demonstration->dernier_echec_le,
            'cause_de_l_echec' => $cause ?? 'Le site de démonstration n\'a pas pu créer la copie.',
        ])->save();

        if ($nouvelEssai && $essais >= self::ECHECS_AVANT_LA_FIN) {
            $societe = Societe::query()->findOrFail($demonstration->societe_id);
            $this->terminer->handle($societe, CauseDeLaFin::Echec);
            $this->alerte->alerter($societe, 'Une démonstration n\'a pas pu être créée', [
                'La création d\'une démonstration a échoué '.self::ECHECS_AVANT_LA_FIN.' fois, à une heure d\'écart au moins ; elle est terminée et la société peut la redemander.',
                'Dernière cause donnée par le site de démonstration : '.($cause ?? 'aucune').'.',
            ]);
        }
    }
}
