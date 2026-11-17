<?php

namespace App\Console\Commands;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Jobs\EnvoyerLesMessagesALaDemonstration;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Models\MessageVersLaDemonstration;
use App\Domain\Demonstration\Services\BoiteDEnvoi;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\AlerteEditeur;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Toutes les 5 minutes, sur le site réel (B.13) :
 *  - chaque démonstration dont un message attend sa reprise (après ses
 *    trois premières reprises), ou dont un message n'a jamais été essayé
 *    depuis plus d'une minute (son travail a trouvé l'envoi déjà en
 *    cours), repart ;
 *  - chaque démonstration ni prête ni finie, et chaque renouvellement sans
 *    accusé, reçoit le message « etat » (référence seule), qui répond
 *    l'état de la copie au registre du site de démonstration ;
 *  - un renouvellement sans accusé depuis 15 minutes prévient l'éditeur,
 *    une fois par génération du lien ; la carte le dit au propriétaire.
 *
 * Elle tourne hors de tout contexte de société : elle ne lit que les
 * colonnes nommées des démonstrations et des messages (identifiant,
 * société, état, génération, instant du lien), par withoutGlobalScopes,
 * et écrit chaque message dans le contexte de la société de sa
 * démonstration.
 */
#[Signature('demonstration:relancer-les-messages')]
#[Description('Relance les messages en attente vers le site de démonstration et demande l\'état des démonstrations en cours')]
class RelancerLesMessagesDeLaDemonstration extends Command
{
    public const MINUTES_AVANT_L_ALERTE = 15;

    public function handle(BoiteDEnvoi $boite, TenantContext $contexte, AlerteEditeur $alerte): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Reel);

        $enAttente = MessageVersLaDemonstration::query()
            ->withoutGlobalScopes()
            ->whereNull('accuse_le')
            ->where(fn ($aReprendre) => $aReprendre->where('prochain_essai_le', '<=', now())
                ->orWhere(fn ($jamaisEssaye) => $jamaisEssaye->whereNull('prochain_essai_le')->where('cree_le', '<=', now()->subSeconds(EnvoyerLesMessagesALaDemonstration::MINUTE_SANS_ENVOI))))
            ->get(['demonstration_id', 'societe_id'])
            ->unique('demonstration_id');

        foreach ($enAttente as $message) {
            EnvoyerLesMessagesALaDemonstration::dispatch($message->demonstration_id, (int) $message->societe_id);
        }

        $aSuivre = Demonstration::query()
            ->withoutGlobalScopes()
            ->whereIn('etat', [...EtatDeLaDemonstration::valeursEnCours(), EtatDeLaDemonstration::EnEchec->value])
            ->where(fn ($requete) => $requete->where('etat', '<>', EtatDeLaDemonstration::Prete->value)->orWhereColumn('generation_du_lien', '>', 'generation_confirmee')->orWhere(fn ($renouvele) => $renouvele->where('generation_du_lien', '>', 1)->whereNull('generation_confirmee')))
            ->orderBy('id')
            ->get(Demonstration::COLONNES_LUES_HORS_DE_LA_SOCIETE);

        foreach ($aSuivre as $demonstration) {
            $societe = Societe::query()->findOrFail($demonstration->societe_id);
            $contexte->runAsSociete($societe, function () use ($boite, $alerte, $societe, $demonstration): void {
                $relue = Demonstration::query()->where('societe_id', $societe->getKey())->whereKey($demonstration->getKey())->firstOrFail();
                $boite->demanderLEtat($relue);

                if ($relue->renouvellementSansAccuse() && $relue->lien_tire_le->addMinutes(self::MINUTES_AVANT_L_ALERTE)->isPast()
                    && Cache::add('demonstration-renouvellement-sans-accuse:'.$relue->getKey().':'.$relue->generation_du_lien, true, 31 * 86400)) {
                    $alerte->alerter($societe, 'Un renouvellement du lien d\'une démonstration n\'est pas confirmé', [
                        'Le site de démonstration n\'a pas accusé le renouvellement du lien tiré le '.Calendrier::deParis()->afficher($relue->lien_tire_le, 'd/m/Y à H:i').' : l\'ancien lien marche peut-être encore. Les messages repartent toutes les 5 minutes.',
                    ]);
                }
            });
        }

        $this->info($enAttente->count().' envoi(s) relancé(s), '.$aSuivre->count().' état(s) demandé(s).');

        return self::SUCCESS;
    }
}
