<?php

namespace App\Domain\Demonstration\Messages;

use App\Domain\Demonstration\Construction\CopiesDeLaDemonstration;
use App\Domain\Demonstration\Construction\DemandeDeCopie;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Enums\QuoiDuMessage;
use App\Domain\Demonstration\Jobs\ArchiverPuisSupprimerUneCopie;
use App\Domain\Demonstration\Jobs\CreerUneCopie;
use App\Domain\Demonstration\Jobs\RecommencerUneCopie;
use App\Domain\Demonstration\Jobs\RenouvelerLeLienDUneCopie;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Lien;
use App\Domain\Demonstration\SiteDePiliqi;
use Illuminate\Support\Facades\Bus;

/**
 * Ce que le site de démonstration fait d'un message lu (lot DEMO-COPIE,
 * C.7), et sa réponse au site réel, au format que lit
 * ReponseDuSiteDeDemonstration (code HTTP et JSON {etat, generation,
 * cause}). Les pages (rôle demo_app) n'écrivent au registre que les
 * travaux et, par la seule fonction inscrire_une_copie(), une demande
 * reçue : chaque changement d'une copie part en travail sur la file
 * demonstration-admin, puis la réponse relit le registre.
 *
 * - « creer » : une référence connue rend l'état de sa copie, sans en créer
 *   une seconde ; sinon la demande s'inscrit aussitôt au registre, en
 *   préparation, avec son lien (un lien tout neuf et le bouton du
 *   propriétaire mènent donc à la page d'attente avant même le passage du
 *   travail), et le travail CreerUneCopie part ; 200 pour une copie prête,
 *   202 pour une copie en préparation ou en attente d'une place.
 * - « renouveler_le_lien » : une génération plus ancienne que celle connue
 *   est refusée (422) ; la génération connue, avec la même empreinte, est
 *   déjà faite (200) ; une plus récente fait partir le renouvellement, et
 *   la réponse est 200 avec la génération, l'accusé du site réel, une fois
 *   fait, 409 sinon (le site réel renvoie le message plus tard).
 * - « recommencer » : la copie est archivée, sa base supprimée et refaite
 *   depuis le modèle du jour, même référence, même lien, même fin
 *   (travail RecommencerUneCopie) ; une remise à zéro déjà en cours n'en
 *   lance pas une seconde ; la réponse dit « en préparation » tant que
 *   la copie n'est pas refaite, puis son état ; une copie inconnue ou
 *   supprimée répond 410.
 * - « supprimer » : la copie est archivée puis supprimée (travail
 *   ArchiverPuisSupprimerUneCopie) ; la réponse est toujours 200
 *   « supprimee », même pour une copie déjà supprimée ou inconnue : la
 *   suppression est faite ou en cours, et le site réel n'a rien d'autre à
 *   attendre.
 * - « etat » : ne change rien et rend l'état ; une copie inconnue ou
 *   supprimée répond 410.
 */
final class TraitementDesMessages
{
    public function __construct(private readonly CopiesDeLaDemonstration $copies) {}

    /**
     * @param  array<string, mixed>  $champs  les champs d'un message lu par ReceptionDesMessages
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function traiter(array $champs): array
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $reference = (string) $champs['reference'];

        return match (QuoiDuMessage::from((string) $champs['quoi'])) {
            QuoiDuMessage::Creer => $this->creer($reference, $champs),
            QuoiDuMessage::RenouvelerLeLien => $this->renouveler($reference, (string) $champs['empreinte_du_lien'], (int) $champs['generation']),
            QuoiDuMessage::Recommencer => $this->recommencer($reference),
            QuoiDuMessage::Supprimer => $this->supprimer($reference),
            QuoiDuMessage::Etat => $this->etat($reference),
        };
    }

    /**
     * @param  array<string, mixed>  $champs
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function creer(string $reference, array $champs): array
    {
        if (! Copie::query()->where('reference', $reference)->exists()) {
            $demande = [
                'reference' => $reference,
                'repere' => (string) $champs['repere'],
                'type' => (string) $champs['type'],
                'validee' => (bool) $champs['validee'],
                'empreinte_du_lien' => (string) $champs['empreinte_du_lien'],
                'generation' => (int) $champs['generation'],
                'fin' => (string) $champs['fin'],
            ];
            $this->copies->inscrire(DemandeDeCopie::lire($demande), 'registre');
            Bus::dispatch(new CreerUneCopie($demande));
        }

        return $this->reponseDe(Copie::query()->where('reference', $reference)->firstOrFail());
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function renouveler(string $reference, string $empreinte, int $generation): array
    {
        $copie = Copie::query()->where('reference', $reference)->first();

        if ($copie === null || $copie->etat === EtatDUneCopie::Supprimee) {
            return [410, ['etat' => 'supprimee']];
        }

        if ($generation < $copie->generation_du_lien) {
            return [422, ['cause' => 'Cette génération du lien est plus ancienne que celle que la démonstration connaît.']];
        }

        if ($generation > $copie->generation_du_lien) {
            Bus::dispatch(new RenouvelerLeLienDUneCopie($reference, $empreinte, $generation));
            $copie = $copie->fresh() ?? $copie;
        }

        if ($copie->generation_du_lien < $generation) {
            return [409, ['etat' => $copie->etat->pourLeSiteReel()]];
        }

        $actif = Lien::query()->where('copie_id', $copie->id)->whereNull('retire_le')->value('empreinte');

        if ($copie->generation_du_lien !== $generation || $actif !== $empreinte) {
            return [422, ['cause' => 'Cette génération du lien est déjà connue avec une autre empreinte.']];
        }

        return [200, ['etat' => $copie->etat->pourLeSiteReel(), 'generation' => $copie->generation_du_lien]];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function recommencer(string $reference): array
    {
        $copie = Copie::query()->where('reference', $reference)->first();

        if ($copie === null || in_array($copie->etat, [EtatDUneCopie::Supprimee, EtatDUneCopie::EnSuppression, EtatDUneCopie::EnArchivage], true)) {
            return [410, ['etat' => 'supprimee']];
        }

        if ($copie->etat !== EtatDUneCopie::ARefaire) {
            Bus::dispatch(new RecommencerUneCopie($reference));
        }

        $apres = $copie->fresh() ?? $copie;

        // Le travail attend encore son tour : la copie n'est pas encore
        // refaite, et le site réel la dit en préparation jusque-là.
        if ($apres->etat->seSert() && $apres->nom_de_la_base === $copie->nom_de_la_base) {
            return [202, ['etat' => EtatDUneCopie::EnPreparation->pourLeSiteReel(), 'generation' => $apres->generation_du_lien]];
        }

        return $this->reponseDe($apres);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function supprimer(string $reference): array
    {
        $copie = Copie::query()->where('reference', $reference)->first();

        if ($copie !== null && ! in_array($copie->etat, [EtatDUneCopie::Supprimee, EtatDUneCopie::EnSuppression, EtatDUneCopie::EnArchivage], true)) {
            Bus::dispatch(new ArchiverPuisSupprimerUneCopie($reference, 'supprimer'));
        }

        return [200, ['etat' => 'supprimee']];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function etat(string $reference): array
    {
        $copie = Copie::query()->where('reference', $reference)->first();

        if ($copie === null || $copie->etat === EtatDUneCopie::Supprimee) {
            return [410, ['etat' => 'supprimee']];
        }

        return $this->reponseDe($copie);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function reponseDe(Copie $copie): array
    {
        $corps = ['etat' => $copie->etat->pourLeSiteReel(), 'generation' => $copie->generation_du_lien];

        if ($copie->etat === EtatDUneCopie::EnEchec && $copie->cause_de_l_echec !== null) {
            $corps['cause'] = mb_substr($copie->cause_de_l_echec, 0, 255);
        }

        return [$copie->etat->seSert() ? 200 : 202, $corps];
    }
}
