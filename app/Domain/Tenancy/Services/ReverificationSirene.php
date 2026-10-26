<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Actions\ChangerSiretAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Notifications\AvisDeVerification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reprend les contrôles du répertoire Sirene de l'INSEE d'une société
 * (voir ControleSirene), sous la société, pour app:reverifier-sirene et
 * pour le bouton « Revérifier » de la page de la vérification :
 *
 * - les contrôles d'établissement restés impossibles, l'INSEE n'ayant pas
 *   répondu : l'établissement d'un magasin réel actif « à vérifier »
 *   devient vérifié, et peut vendre (une fois la société validée), quand
 *   le répertoire le confirme, son adresse reprise de Sirene s'il n'avait
 *   jamais été confirmé sous ce SIRET (créé ou corrigé pendant la panne) ;
 *   rouvert pendant une panne après une confirmation, il garde l'adresse
 *   que la société a enregistrée (voir
 *   ControleSirene::etablissementDejaConfirme()). Sa confirmation fixe le
 *   territoire d'un magasin qui n'en avait pas encore (JET 270, sous le
 *   verrou de son registre) ; un établissement que le logiciel ne gère
 *   pas, ou d'un autre territoire que celui déjà fixé du magasin (un NIC
 *   corrigé pendant la panne), n'est pas confirmé : le contrôle s'inscrit
 *   négatif, avec son motif, et l'adresse ne change pas. Un contrôle
 *   négatif s'inscrit, et le magasin reste à vérifier. L'entreprise d'une
 *   inscription n'a pas de contrôle à reprendre : l'inscription n'existe
 *   qu'une fois son entreprise confirmée active (voir CreerSocieteAction) ;
 * - avec $toutes, une société validée et ses magasins réels actifs. Rien
 *   de ce que la société a enregistré ne change : son identité reste celle
 *   qu'elle a saisie, et l'établissement reste vérifié ; seule la
 *   catégorie juridique lue au répertoire suit sa dernière lecture. Un
 *   écart (entreprise cessée, catégorie juridique différente,
 *   établissement fermé ou inconnu, adresse d'un magasin qui n'est plus
 *   celle de son établissement, établissement que le répertoire situe
 *   désormais dans un autre territoire) s'inscrit au registre des
 *   vérifications et se signale au propriétaire et à l'éditeur, sans rien
 *   bloquer : ni les ventes ni les paiements ne s'arrêtent, et le
 *   territoire du magasin ne change pas ; l'éditeur décide (pour le
 *   territoire, voir signalerUnAutreTerritoire()).
 *
 * Ce qui s'écrit sur la société (catégorie lue) s'inscrit à son journal
 * d'audit, le système pour auteur, avec les anciennes et les nouvelles
 * valeurs ; les journaux des événements de ses magasins l'inscrivent aussi
 * au nom du système (voir OperateurCourant), même quand le propriétaire
 * l'a demandé par le bouton « Revérifier ».
 */
class ReverificationSirene
{
    public function __construct(
        private readonly ControleSirene $controle,
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journalAudit,
        private readonly AlerteEditeur $alerteEditeur,
        private readonly OperateurCourant $operateur,
    ) {}

    public function reprendre(Societe $societe, bool $toutes): void
    {
        $this->operateur->pendantLeSysteme(function () use ($societe, $toutes): void {
            app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $toutes): void {
                $toutes = $toutes && $societe->estValidee();

                if ($toutes) {
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
        });
    }

    /**
     * Le contrôle de l'entreprise d'une société validée, sous les verrous
     * des registres de ses magasins, que la catégorie juridique lue
     * s'inscrive à leurs journaux des événements : seule cette catégorie
     * suit le répertoire, et ses écarts se signalent.
     */
    private function reverifierLEntreprise(Societe $societe): void
    {
        $consultation = $this->controle->unite((string) $societe->siren);
        $unite = $consultation['unite'];
        $categorieLue = $consultation['resultat'] === ResultatVerification::Concluante && $unite !== null ? $unite->categorieJuridique : null;

        $categorieLueAvant = $this->registre->sousVerrouDesMagasins($societe->magasinsReels()->orderBy('id')->get(), function () use ($societe, $consultation, $unite, $categorieLue): ?string {
            $this->controle->noterUnite($societe, $consultation);
            $enregistree = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();
            $avant = $enregistree->identiteAuditee();

            if ($consultation['resultat'] === ResultatVerification::Concluante && $unite !== null) {
                $enregistree->forceFill(['categorie_juridique_insee' => $categorieLue])->save();
                $this->journalAudit->inscrireLesChangements(ActionAuditee::IdentiteModifiee, null, null, $avant, $enregistree->identiteAuditee(), $enregistree);
            }

            return $avant['categorie_juridique_insee'];
        });

        if ($consultation['resultat'] === ResultatVerification::Negative) {
            Log::warning('Revérification Sirene : écart sur une société validée.', ['societe_id' => $societe->getKey(), 'motif' => $consultation['motif']]);
            $this->signalerLEcart($societe, $consultation['motif'], negative: true);
        }

        if ($categorieLue !== null && $categorieLueAvant !== null && $categorieLueAvant !== $categorieLue) {
            Log::warning('Revérification Sirene : catégorie juridique différente pour une société validée.', ['societe_id' => $societe->getKey(), 'avant' => $categorieLueAvant, 'apres' => $categorieLue]);
            $this->signalerLEcart($societe, "Catégorie juridique lue avant : {$categorieLueAvant} ; lue aujourd'hui : {$categorieLue}. La forme choisie par la société ne change pas.");
        }
    }

    /**
     * Le contrôle de l'établissement du magasin, consulté sur son SIRET du
     * moment, puis appliqué à la ligne du magasin relue sous verrou : si
     * son SIRET a changé pendant la consultation (NIC corrigé avant la
     * validation, déménagement après), ce contrôle ne vaut pas
     * pour lui, et rien ne s'écrit ni ne se signale ; son nouveau SIRET a
     * son propre contrôle, inscrit par le geste qui l'a changé, que la
     * reprise suivante reprend s'il est resté impossible et que la
     * revérification mensuelle revoit. Sinon le contrôle s'inscrit ; un
     * établissement à vérifier que le répertoire confirme dans un
     * territoire géré devient vérifié et fixe le territoire qui manquait,
     * sauf s'il n'avait jamais été confirmé sous ce SIRET et qu'il est dans
     * un autre territoire que celui déjà fixé du magasin (un NIC corrigé
     * pendant une panne) : le contrôle s'inscrit alors négatif ; un
     * établissement qui l'était déjà signale ses écarts, et un
     * établissement déjà confirmé que le répertoire situe désormais dans un
     * autre territoire se signale, le territoire du magasin ne changeant
     * pas. Un magasin à vérifier se reprend sous le
     * verrou de son registre, premier verrou de la transaction, que la
     * fixation de son territoire s'inscrive à son journal des événements
     * (voir JournaliseSesParametresDeConformite).
     */
    private function reverifierLEtablissement(Magasin $magasin): void
    {
        $societe = $magasin->societe;
        $siret = (string) $magasin->siret;
        $consultation = $this->controle->etablissement($siret, (string) $societe->siren);
        $etablissement = $consultation['etablissement'];
        $lu = $consultation['resultat'] === ResultatVerification::Concluante && $etablissement !== null ? ControleSirene::territoireDe($etablissement) : null;

        $reprise = function () use ($magasin, $siret, $consultation, $etablissement, $lu): array {
            $relu = Magasin::query()->whereKey($magasin->getKey())->lockForUpdate()->firstOrFail();

            if ($relu->siret !== $siret) {
                return [null, null];
            }

            $dejaVerifie = $relu->etablissement_verifie_le !== null;
            $jamaisConfirme = ! ControleSirene::etablissementDejaConfirme($relu);
            $autreTerritoire = $lu !== null && $relu->territoire !== null && $lu['territoire'] !== $relu->territoire;
            $refus = match (true) {
                $dejaVerifie || $lu === null => null,
                $lu['motif'] !== null => $lu['motif'],
                $autreTerritoire && $jamaisConfirme => ChangerSiretAction::MESSAGE_TERRITOIRE_CHANGE,
                default => null,
            };
            $confirme = ! $dejaVerifie && $lu !== null && $refus === null;
            $this->controle->noterEtablissement($relu, $refus === null ? $consultation : ['resultat' => ResultatVerification::Negative, 'etablissement' => $etablissement, 'motif' => $refus]);

            if ($confirme) {
                $relu->forceFill([
                    'etablissement_verifie_le' => now(),
                    'territoire' => $relu->territoire ?? $lu['territoire'],
                    ...($jamaisConfirme ? [...ControleSirene::adresseDe($etablissement), 'pays' => 'FR'] : []),
                ])->save();
            }

            return [$dejaVerifie ? $relu : null, $autreTerritoire && ($dejaVerifie || $confirme) ? $relu : null];
        };

        [$verifie, $situeAilleurs] = $magasin->etablissement_verifie_le === null
            ? $this->registre->sousVerrouDesMagasins(new Collection([$magasin]), $reprise)
            : DB::transaction($reprise);

        $ecart = match (true) {
            $verifie === null => null,
            $consultation['resultat'] === ResultatVerification::Negative => $consultation['motif'],
            $consultation['resultat'] === ResultatVerification::Concluante && $etablissement !== null && ControleSirene::adresseDiffere($verifie, $etablissement) => 'L\'adresse du magasin n\'est plus celle que le répertoire donne de son établissement : '.ControleSirene::adresseEnUneLigne($etablissement).'.',
            default => null,
        };

        if ($ecart !== null) {
            Log::warning('Revérification Sirene : écart sur un établissement vérifié.', ['magasin_id' => $verifie->getKey(), 'motif' => $ecart]);
            $this->signalerLEcart($societe, "{$verifie->nom} (SIRET {$verifie->siret}) : {$ecart}", $consultation['resultat'] === ResultatVerification::Negative);
        }

        if ($situeAilleurs !== null) {
            $this->signalerUnAutreTerritoire($situeAilleurs, Territoire::depuisLeRepertoire($etablissement->codeCommune, $etablissement->codePostal));
        }
    }

    /**
     * Le répertoire situe désormais l'établissement déjà confirmé d'un
     * magasin dans un autre territoire que le sien (une erreur de sa
     * commune, corrigée depuis), à une revérification ou à une
     * réouverture (voir RouvrirMagasinAction) : le propriétaire et
     * l'éditeur sont prévenus, et rien ne change, ni le territoire du
     * magasin ni ses ventes. Tant que le magasin n'a numéroté aucun
     * document, l'éditeur corrige son territoire par sa procédure (voir
     * CorrigerLeTerritoireAction) ; ensuite, la société ouvre un nouveau
     * magasin et ferme celui-ci pour cessation.
     */
    public function signalerUnAutreTerritoire(Magasin $magasin, ?Territoire $lu): void
    {
        $ecart = "{$magasin->nom} (SIRET {$magasin->siret}) : le répertoire Sirene situe désormais son établissement ".($lu?->lieu() ?? 'hors des territoires que le logiciel gère').', et non '.($magasin->territoire?->lieu() ?? 'dans son territoire').' ; son territoire, qui fixe son taux de TVA par défaut et son heure, ne change pas.';
        $suite = $magasin->aNumeroteUnDocument()
            ? 'Le magasin a déjà numéroté des documents : son territoire ne se corrige plus, et la société ouvre un nouveau magasin, puis ferme celui-ci pour cessation.'
            : 'Le magasin n\'a numéroté aucun document : l\'éditeur peut corriger son territoire (editeur:corriger-territoire).';

        Log::warning('Revérification Sirene : établissement situé dans un autre territoire.', ['magasin_id' => $magasin->getKey(), 'territoire' => $magasin->territoire?->value, 'territoire_lu' => $lu?->value]);
        $this->alerteEditeur->alerter($magasin->societe, 'Revérification Sirene : établissement situé dans un autre territoire', [$ecart, $suite]);
        $magasin->societe->proprietaire?->notify(new AvisDeVerification($magasin->societe, 'Territoire de votre magasin au répertoire Sirene', "{$ecart} {$suite} Vos ventes continuent ; l'éditeur, prévenu, pourra vous contacter."));
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
