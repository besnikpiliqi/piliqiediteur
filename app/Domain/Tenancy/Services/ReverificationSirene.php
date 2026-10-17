<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Actions\RefuserLaSocieteAction;
use App\Domain\Tenancy\Actions\ValiderLaSocieteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Notifications\AvisDeVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reprend les contrôles du répertoire Sirene de l'INSEE d'une société
 * (voir ControleSirene), sous la société, pour app:reverifier-sirene et
 * pour le bouton « Revérifier » de la page de la vérification :
 *
 * - les contrôles restés impossibles, l'INSEE n'ayant pas répondu.
 *   L'entreprise d'une société pas encore validée, inscrite ou dont le
 *   SIREN a été corrigé pendant la panne, et dont l'identité n'a donc
 *   jamais été préremplie : le contrôle concluant la complète depuis le
 *   répertoire, chaque champ encore vide seulement (voir
 *   ControleSirene::identiteDepuisSirene()), et une entreprise cessée fait
 *   refuser la société pour toujours (lecture seule définitive), comme un
 *   refus de l'éditeur (voir RefuserLaSocieteAction : pièces effacées,
 *   codes annulés, démonstration fermée, propriétaire prévenu).
 *   L'établissement d'un magasin réel actif « à vérifier » : il devient
 *   vérifié, et peut vendre, quand le répertoire le confirme, son adresse
 *   reprise de Sirene s'il n'avait jamais été confirmé sous ce SIRET (créé
 *   ou corrigé pendant la panne) ; rouvert pendant une panne après une
 *   confirmation, il garde l'adresse que la société a enregistrée (voir
 *   ControleSirene::etablissementDejaConfirme()). Un contrôle négatif
 *   s'inscrit, et le magasin reste à vérifier. Une société que ces
 *   contrôles rendent validable est validée (voir ValiderLaSocieteAction) ;
 * - avec $toutes, une société validée et ses magasins réels actifs. Rien
 *   de ce que la société a enregistré ne change : son identité reste celle
 *   qu'elle a saisie, même vidée (un n° de TVA retiré ne revient pas), et
 *   l'établissement reste vérifié ; seule la catégorie juridique lue au
 *   répertoire suit sa dernière lecture. Un écart (entreprise cessée,
 *   catégorie juridique différente, établissement fermé ou inconnu,
 *   adresse d'un magasin qui n'est plus celle de son établissement)
 *   s'inscrit au registre des vérifications et se signale au propriétaire
 *   et à l'éditeur, sans rien bloquer : ni les ventes ni les paiements ne
 *   s'arrêtent ; l'éditeur décide (section 7.10 de la conception).
 *
 * Ce qui s'écrit sur la société (identité complétée, catégorie lue, refus)
 * s'inscrit à son journal d'audit, le système pour auteur, avec les
 * anciennes et les nouvelles valeurs ; les journaux des événements de ses
 * magasins l'inscrivent aussi au nom du système (voir OperateurCourant),
 * même quand le propriétaire l'a demandé par le bouton « Revérifier ».
 */
class ReverificationSirene
{
    public function __construct(
        private readonly ControleSirene $controle,
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journalAudit,
        private readonly AlerteEditeur $alerteEditeur,
        private readonly ValiderLaSocieteAction $validerLaSociete,
        private readonly RefuserLaSocieteAction $refuserLaSociete,
        private readonly OperateurCourant $operateur,
    ) {}

    public function reprendre(Societe $societe, bool $toutes): void
    {
        $this->operateur->pendantLeSysteme(function () use ($societe, $toutes): void {
            app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $toutes): void {
                $toutes = $toutes && $societe->estValidee();

                if ($toutes || self::dernierResultat($societe, null) === ResultatVerification::Indisponible) {
                    $this->reverifierLEntreprise($societe);
                }

                $societe->magasinsReels()
                    ->where('statut', StatutMagasin::Actif)
                    ->orderBy('id')
                    ->each(function (Magasin $magasin) use ($societe, $toutes): void {
                        if ($toutes || ($magasin->etablissement_verifie_le === null && self::dernierResultat($societe, $magasin) === ResultatVerification::Indisponible)) {
                            $this->reverifierLEtablissement($magasin);
                        }
                    });
            });

            $societe = $societe->fresh();

            if (! $societe->estValidee() && ! $societe->estRefusee()) {
                $this->validerLaSociete->siPossible($societe);
            }
        });
    }

    /**
     * Le contrôle de l'entreprise, sous les verrous des registres de ses
     * magasins, que le n° de TVA d'une identité complétée s'inscrit à leurs
     * journaux des événements. Seule une société ni validée ni refusée voit
     * son identité complétée, ou se fait refuser ; une société validée n'en
     * reçoit que la catégorie juridique lue, et ses écarts se signalent.
     */
    private function reverifierLEntreprise(Societe $societe): void
    {
        $consultation = $this->controle->unite((string) $societe->siren);
        $unite = $consultation['unite'];
        $identite = $consultation['resultat'] === ResultatVerification::Concluante && $unite !== null ? $this->controle->identiteDepuisSirene($unite) : null;

        [$categorieLueAvant, $refusee] = $this->registre->sousVerrouDesMagasins($societe->magasinsReels()->orderBy('id')->get(), function () use ($societe, $consultation, $unite, $identite): array {
            $this->controle->noterUnite($societe, $consultation);
            $enregistree = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();
            $avant = $enregistree->identiteAuditee();
            $aCompleter = ! $enregistree->estValidee() && ! $enregistree->estRefusee();

            if ($identite !== null) {
                $enregistree->forceFill([
                    ...($aCompleter ? array_filter($identite, fn (mixed $valeur, string $champ): bool => $valeur !== null && $enregistree->getAttribute($champ) === null, ARRAY_FILTER_USE_BOTH) : []),
                    'categorie_juridique_insee' => $identite['categorie_juridique_insee'],
                ])->save();

                $this->journalAudit->inscrireLesChangements(ActionAuditee::IdentiteModifiee, null, null, $avant, $enregistree->identiteAuditee(), $enregistree);
            }

            $refusee = $aCompleter && $unite !== null && ! $unite->active;

            if ($refusee) {
                $this->refuserLaSociete->refuserSousVerrou($enregistree, $consultation['motif']);
            }

            return [$avant['categorie_juridique_insee'], $refusee];
        });

        if ($refusee) {
            $this->refuserLaSociete->apresLeRefus($societe->fresh(), $consultation['motif']);

            return;
        }

        if (! $societe->estValidee()) {
            return;
        }

        if ($consultation['resultat'] === ResultatVerification::Negative) {
            Log::warning('Revérification Sirene : écart sur une société validée.', ['societe_id' => $societe->getKey(), 'motif' => $consultation['motif']]);
            $this->signalerLEcart($societe, $consultation['motif'], negative: true);
        }

        if ($identite !== null && $categorieLueAvant !== null && $categorieLueAvant !== $identite['categorie_juridique_insee']) {
            Log::warning('Revérification Sirene : catégorie juridique différente pour une société validée.', ['societe_id' => $societe->getKey(), 'avant' => $categorieLueAvant, 'apres' => $identite['categorie_juridique_insee']]);
            $this->signalerLEcart($societe, "Catégorie juridique lue avant : {$categorieLueAvant} ; lue aujourd'hui : {$identite['categorie_juridique_insee']}. La forme choisie par la société ne change pas.");
        }
    }

    private function reverifierLEtablissement(Magasin $magasin): void
    {
        $societe = $magasin->societe;
        $consultation = $this->controle->etablissement((string) $magasin->siret, (string) $societe->siren);
        $etablissement = $consultation['etablissement'];

        DB::transaction(function () use ($magasin, $consultation, $etablissement): void {
            $confirme = $magasin->etablissement_verifie_le === null && $consultation['resultat'] === ResultatVerification::Concluante && $etablissement !== null && ControleSirene::estDansUnTerritoireGere($etablissement);
            $adresseDuRepertoire = $confirme && ! ControleSirene::etablissementDejaConfirme($magasin);
            $this->controle->noterEtablissement($magasin, $consultation);

            if ($confirme) {
                Magasin::query()->whereKey($magasin->getKey())->lockForUpdate()->firstOrFail()
                    ->forceFill(['etablissement_verifie_le' => now(), ...($adresseDuRepertoire ? [...ControleSirene::adresseDe($etablissement), 'pays' => 'FR'] : [])])
                    ->save();
            }
        });

        if ($magasin->etablissement_verifie_le === null) {
            return;
        }

        $ecart = match (true) {
            $consultation['resultat'] === ResultatVerification::Negative => $consultation['motif'],
            $consultation['resultat'] === ResultatVerification::Concluante && $etablissement !== null && SignauxDeRisque::adresseDiffere($magasin, ControleSirene::adresseEnUneLigne($etablissement)) => 'L\'adresse du magasin n\'est plus celle que le répertoire donne de son établissement : '.ControleSirene::adresseEnUneLigne($etablissement).'.',
            default => null,
        };

        if ($ecart !== null) {
            Log::warning('Revérification Sirene : écart sur un établissement vérifié.', ['magasin_id' => $magasin->getKey(), 'motif' => $ecart]);
            $this->signalerLEcart($societe, "{$magasin->nom} (SIRET {$magasin->siret}) : {$ecart}", $consultation['resultat'] === ResultatVerification::Negative);
        }
    }

    /**
     * Un écart après la validation : le propriétaire et l'éditeur sont
     * prévenus, rien ne se bloque. Le répertoire dit-il l'entreprise
     * cessée, ou l'établissement fermé ou inconnu (négative), ou seulement
     * une catégorie ou une adresse différente ?
     */
    private function signalerLEcart(Societe $societe, string $motif, bool $negative = false): void
    {
        $this->alerteEditeur->alerter($societe, $negative ? 'Revérification Sirene négative d\'une société validée' : 'Revérification Sirene : écart sur une société validée', [$motif, 'Rien n\'est bloqué : à vous de décider (suspension à l\'étape 13).']);
        $societe->proprietaire?->notify(new AvisDeVerification($societe, 'Écart au répertoire Sirene', "{$motif} Vos ventes continuent ; l'éditeur, prévenu, pourra vous contacter."));
    }

    /**
     * Le résultat du dernier contrôle de l'entreprise (sans magasin), ou de
     * l'établissement du magasin, dans la société active.
     */
    private static function dernierResultat(Societe $societe, ?Magasin $magasin): ?ResultatVerification
    {
        return VerificationSociete::query()
            ->where('societe_id', $societe->getKey())
            ->where('type', ($magasin === null ? TypeVerification::SireneUnite : TypeVerification::SireneEtablissement)->value)
            ->when($magasin === null, fn ($requete) => $requete->whereNull('magasin_id'), fn ($requete) => $requete->where('magasin_id', $magasin->getKey()))
            ->orderByDesc('id')
            ->first()
            ?->resultat;
    }
}
