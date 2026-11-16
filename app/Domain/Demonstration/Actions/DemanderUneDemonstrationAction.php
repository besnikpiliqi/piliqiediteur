<?php

namespace App\Domain\Demonstration\Actions;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Jobs\EnvoyerLeLienDeLaDemonstration;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Services\BoiteDEnvoi;
use App\Domain\Demonstration\Services\ConditionsDeLaDemonstration;
use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use LogicException;

/**
 * « Créer ma démonstration » (lot DEMO-COPIE, B.3) : le propriétaire, et
 * lui seul (voir ConditionsDeLaDemonstration), demande la copie de la
 * société modèle du type de sa société, sans aucun choix de type.
 *
 * Dans une seule transaction, sous le verrou de la ligne de la société :
 * la référence (128 bits), le repère, le lien (256 bits, en base64url, 43
 * caractères), son empreinte SHA-256, la démonstration, le message
 * « creer » dans la boîte d'envoi et l'entrée du journal d'audit. Une
 * démonstration déjà vivante (en cours, ou en échec tant que le site de
 * démonstration réessaie) est rendue telle quelle, sans second message :
 * la carte la montre. Une demande répétée après une réponse perdue (même
 * jeton) rend la même démonstration, sans le lien.
 *
 * Le lien n'est gardé nulle part : il revient seulement dans la réponse de
 * cette demande (voir LienTire), et part par e-mail à l'adresse de
 * connexion confirmée du propriétaire, par un travail chiffré (voir
 * EnvoyerLeLienDeLaDemonstration). Au plus 3 créations par heure et 10 sur
 * 30 jours par adresse IP, toutes sociétés confondues, comptées par
 * l'empreinte de l'adresse, jamais l'adresse en clair ; les e-mails
 * comptent au débit du propriétaire (60 par heure).
 */
final class DemanderUneDemonstrationAction
{
    use AgitDansLaSociete;

    public const ACTION = 'demonstration.creer';

    public const CREATIONS_PAR_HEURE = 3;

    public const CREATIONS_SUR_TRENTE_JOURS = 10;

    public const MESSAGE_TROP_DE_CREATIONS = 'Trop de démonstrations ont été demandées depuis cette connexion : réessayez plus tard.';

    /**
     * Les caractères du repère : lettres et chiffres sans ambiguïté (ni 0
     * et O, ni 1, I et L, ni 5 et S, ni 2 et Z… sauf le 2, sans Z).
     */
    public const ALPHABET_DU_REPERE = 'ACDEFGHJKMNPQRTUVWXY234679';

    public function __construct(
        private readonly ConditionsDeLaDemonstration $conditions,
        private readonly ActionsUniques $actionsUniques,
        private readonly BoiteDEnvoi $boite,
        private readonly JournalAudit $journalAudit,
        private readonly DebitDesEmails $debit,
    ) {}

    /**
     * @param  string|null  $jeton  le jeton de la demande (null pour les tests et le semis)
     * @param  string|null  $adresseIp  l'adresse IP de la demande, comptée par son empreinte
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, ?string $jeton, ?string $adresseIp): LienTire
    {
        $societe = Societe::query()->findOrFail($auteur->societe_id);
        $lien = null;

        $demonstration = $this->dansLaSociete($societe, ConditionsDeLaDemonstration::REFUS, function () use ($auteur, $societe, $jeton, $adresseIp, &$lien): Demonstration {
            $this->conditions->exiger($auteur, $societe, envoieLeLien: true, creeOuRecommence: true);
            $this->debit->verifier($auteur);

            $resultat = $this->actionsUniques->executerDansLaSociete(
                $jeton,
                self::ACTION,
                Demonstration::class,
                $auteur,
                ['societe' => $societe->uuid],
                function () use ($auteur, $societe): void {
                    Societe::verrouillerAvantSonJournalDAudit(CleDuModele::de($societe));
                    User::query()->whereKey($auteur->getKey())->lock('for no key update')->first();
                },
                function () use ($auteur, $societe, $adresseIp, &$lien): Demonstration {
                    $relue = Societe::query()->findOrFail($societe->getKey());
                    $auteurRelu = User::query()->findOrFail($auteur->getKey());
                    $this->conditions->exiger($auteurRelu, $relue, envoieLeLien: true, creeOuRecommence: true);

                    $vivante = Demonstration::query()
                        ->whereIn('etat', [...EtatDeLaDemonstration::valeursEnCours(), EtatDeLaDemonstration::EnEchec->value])
                        ->lockForUpdate()
                        ->first();

                    if ($vivante !== null) {
                        return $vivante;
                    }

                    $this->compterLAdresse($adresseIp);
                    $lien = self::tirerUnLien();
                    $maintenant = CarbonImmutable::now()->startOfSecond();
                    $demonstration = new Demonstration;
                    $demonstration->forceFill([
                        'uuid' => (string) Str::uuid(),
                        'reference' => bin2hex(random_bytes(16)),
                        'repere' => self::tirerUnRepere(),
                        'type_d_activite' => $relue->type_activite,
                        'empreinte_du_lien' => Demonstration::empreinteDuLien($lien),
                        'generation_du_lien' => 1,
                        'lien_tire_le' => $maintenant,
                        'lien_tire_par' => $auteurRelu->getKey(),
                        'cree_le' => $maintenant,
                        'fin_le' => Calendrier::deParis()->decaler($maintenant, CarbonInterval::days(Demonstration::DUREE_EN_JOURS)),
                        'etat' => EtatDeLaDemonstration::Demandee,
                        'demandee_par' => $auteurRelu->getKey(),
                    ])->save();
                    $this->boite->creer($demonstration, $relue->estValidee());
                    $this->journalAudit->inscrire(ActionAuditee::DemonstrationCreee, $auteurRelu, null, null, [
                        'repere' => $demonstration->repere,
                        'type_d_activite' => $demonstration->type_d_activite->value,
                        'fin' => MessagesSignes::instant($demonstration->fin_le),
                    ], $demonstration);

                    return $demonstration;
                },
            );

            return $resultat instanceof Demonstration ? $resultat : throw new LogicException('La démonstration demandée n\'a pas pu être relue.');
        });

        if ($lien !== null) {
            EnvoyerLeLienDeLaDemonstration::envoyer($auteur, $demonstration, $lien);
            $this->debit->compter($auteur);
        }

        return new LienTire($demonstration, $lien);
    }

    /**
     * Un lien neuf : 256 bits tirés au hasard, en base64url, 43
     * caractères ; il ne s'enregistre jamais, seulement son empreinte.
     */
    public static function tirerUnLien(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * L'adresse que le lien ouvre : le secret après « # », que le
     * navigateur n'envoie jamais au serveur.
     */
    public static function adresseDuLien(string $lien): string
    {
        return rtrim((string) config('demonstration.url'), '/').'/d#'.$lien;
    }

    public static function tirerUnRepere(): string
    {
        $repere = '';

        for ($i = 0; $i < 6; $i++) {
            $repere .= self::ALPHABET_DU_REPERE[random_int(0, strlen(self::ALPHABET_DU_REPERE) - 1)];
        }

        return $repere;
    }

    /**
     * Compte une création de plus pour cette adresse IP, par son empreinte
     * : au plus 3 par heure et 10 sur 30 jours, toutes sociétés confondues.
     *
     * @throws LogicException
     */
    private function compterLAdresse(?string $adresseIp): void
    {
        if ($adresseIp === null) {
            return;
        }

        $empreinte = hash('sha256', $adresseIp);
        $parHeure = 'demonstration-creations-heure:'.$empreinte;
        $surTrenteJours = 'demonstration-creations-30-jours:'.$empreinte;

        if (RateLimiter::tooManyAttempts($parHeure, self::CREATIONS_PAR_HEURE) || RateLimiter::tooManyAttempts($surTrenteJours, self::CREATIONS_SUR_TRENTE_JOURS)) {
            throw new LogicException(self::MESSAGE_TROP_DE_CREATIONS);
        }

        RateLimiter::hit($parHeure, 3600);
        RateLimiter::hit($surTrenteJours, 30 * 86400);
    }
}
