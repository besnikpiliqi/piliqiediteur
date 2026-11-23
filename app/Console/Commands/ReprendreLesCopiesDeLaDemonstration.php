<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\Archives\FinDesCopies;
use App\Domain\Demonstration\Construction\CopiesDeLaDemonstration;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Jobs\PreparerUneCopie;
use App\Domain\Demonstration\Jobs\RefaireUneCopie;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Support\ValeursLues;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Chaque heure, sur le site de démonstration (lot DEMO-COPIE, C.3) : une
 * copie en échec dont l'essai suivant est dû repart (une heure après son
 * dernier échec) ; une réfection arrêtée par l'échec de son archive (copie
 * « à refaire » dont l'essai suivant est dû) reprend en entier, avec son
 * motif, archive comprise, avant toute suppression de sa base (C.18) ; une
 * copie perdue (sa base a disparu du serveur, E.7) est refaite depuis le
 * modèle, avec son message ; et la
 * demande qui attend une place en premier repart aussi, pour le cas où une
 * place s'est libérée sans suppression (plafond relevé, copie en échec).
 * Chaque reprise est un travail de la file demonstration-admin.
 */
#[Signature('demonstration:reprendre-les-copies')]
#[Description('Reprend les copies de démonstration en échec, les remises à zéro arrêtées par leur archive et la demande qui attend une place en premier.')]
class ReprendreLesCopiesDeLaDemonstration extends Command
{
    public function handle(CopiesDeLaDemonstration $copies): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $references = Copie::on('demo_admin')->where('etat', EtatDUneCopie::EnEchec)
            ->where('prochain_essai_le', '<=', CarbonImmutable::now())
            ->orderBy('prochain_essai_le')->orderBy('id')->pluck('reference')->all();
        $enAttente = $copies->enAttenteDUnePlace()[0] ?? null;

        if ($enAttente !== null) {
            $references[] = $enAttente;
        }

        $aRefaire = Copie::on('demo_admin')
            ->where(fn ($quoi) => $quoi->where('etat', EtatDUneCopie::Perdue)
                ->orWhere(fn ($arretee) => $arretee->where('etat', EtatDUneCopie::ARefaire)->where('prochain_essai_le', '<=', CarbonImmutable::now())))
            ->orderBy('id')->get(['reference', 'etat', 'motif_du_message']);

        foreach ($references as $reference) {
            Bus::dispatch(new PreparerUneCopie(ValeursLues::texte($reference)));
        }

        foreach ($aRefaire as $copie) {
            $motif = $copie->etat === EtatDUneCopie::Perdue ? FinDesCopies::MOTIF_PERDUE : ($copie->motif_du_message ?? FinDesCopies::MOTIF_REMISE_A_ZERO);
            Bus::dispatch(new RefaireUneCopie($copie->reference, array_key_exists($motif, FinDesCopies::MOTIFS_DE_REFECTION) ? $motif : FinDesCopies::MOTIF_REMISE_A_ZERO));
        }

        $this->info((count($references) + count($aRefaire)).' copie(s) reprise(s).');

        return self::SUCCESS;
    }
}
