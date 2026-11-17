<?php

namespace App\Domain\Demonstration\Construction;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Enums\EtatDUnModele;
use App\Domain\Demonstration\Exceptions\CopieImpossible;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Modele;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\VersionServie;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * La création des copies du site de démonstration (lot DEMO-COPIE, C.3),
 * par demo_admin seul (travailleur de la file demonstration-admin) :
 *
 * - la demande s'inscrit une seule fois au registre, par sa référence :
 *   une seconde demande de la même référence rend la copie déjà connue ;
 * - plafond atteint (DEMONSTRATION_COPIES_MAX), ou places des sociétés non
 *   validées prises (la part DEMONSTRATION_PART_DES_VALIDEES du plafond
 *   leur est réservée, choix 7) : la copie attend une place, et la place
 *   libérée va d'abord à la plus ancienne demande d'une société validée,
 *   puis aux autres, dans l'ordre ;
 * - au plus DEMONSTRATION_COPIES_EN_MEME_TEMPS créations en même temps ;
 * - CREATE DATABASE demo_copie_<ulid> TEMPLATE <modèle en service>, sous
 *   un nom neuf à chaque essai, sous le verrou consultatif du type (la
 *   bascule d'une construction attend la fin de la copie), puis les
 *   réglages de la base, puis les fichiers du modèle ;
 * - échec ou modèle occupé : aucune base à moitié faite ne reste
 *   (DROP DATABASE … WITH (FORCE) de la seule base que cet essai a fait
 *   naître), et le travail réessaie ;
 * - jamais de DROP DATABASE d'une base qui existait avant l'essai : une
 *   copie dont la base précédente existe encore n'est pas refaite ici
 *   (CAUSE_BASE_A_ARCHIVER) ; seule FinDesCopies la supprime, après
 *   l'archive relue de sa caisse (C.18). Une remise à zéro dont l'archive
 *   échoue reste donc « à refaire », jamais « en échec » (voir
 *   marquerEnEchec()), et demonstration:reprendre-les-copies la relance.
 */
final class CopiesDeLaDemonstration
{
    public const CAUSE_SANS_MODELE = 'Aucun modèle de ce type n\'est en service : la construction de la nuit n\'a pas encore réussi.';

    public const CAUSE_CREATIONS_EN_COURS = 'D\'autres copies sont en cours de création : nouvel essai dans un instant.';

    public const CAUSE_BASE_A_ARCHIVER = 'La base précédente de cette copie existe encore : elle n\'est supprimée qu\'après l\'archive relue de sa caisse, par sa remise à zéro ou sa suppression.';

    public function __construct(private readonly BasesDeLaDemonstration $bases) {}

    /**
     * La demande inscrite au registre, une seule fois par référence, avec
     * son lien (seulement l'empreinte de son secret), en préparation et
     * sans base, par la fonction inscrire_une_copie() (voir
     * RolesDeLaDemonstration::FONCTION_D_INSCRIPTION) : dès la réception du
     * message « creer », par le rôle des pages (connexion registre), puis
     * de nouveau, sans effet, par le travail de création (demo_admin).
     *
     * @param  array{reference: string, repere: string, type: TypeDActivite, validee: bool, empreinte_du_lien: string, generation: int, fin: CarbonImmutable}  $demande
     */
    public function inscrire(array $demande, string $connexion): Copie
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        DB::connection($connexion)->statement('select public.inscrire_une_copie(?, ?, ?, ?::boolean, ?, ?::integer, ?::timestamp, ?::timestamp)', [
            $demande['reference'],
            $demande['repere'],
            $demande['type']->value,
            $demande['validee'] ? 'true' : 'false',
            $demande['empreinte_du_lien'],
            $demande['generation'],
            Instant::enBase($demande['fin']),
            Instant::enBase(CarbonImmutable::now()),
        ]);

        return Copie::on($connexion)->where('reference', $demande['reference'])->firstOrFail();
    }

    /**
     * La copie préparée, ou mise en attente d'une place.
     *
     * @throws CopieImpossible quand la copie n'a pas pu être créée cette fois ; aucune base n'en reste
     */
    public function preparer(string $reference): Copie
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $copie = Copie::on('demo_admin')->where('reference', $reference)->firstOrFail();

        if (! in_array($copie->etat, [EtatDUneCopie::EnPreparation, EtatDUneCopie::EnAttenteDUnePlace, EtatDUneCopie::EnEchec, EtatDUneCopie::ARefaire, EtatDUneCopie::Perdue], true)) {
            return $copie;
        }

        if (! $this->aUnePlace($copie)) {
            $copie->forceFill(['etat' => EtatDUneCopie::EnAttenteDUnePlace])->save();

            return $copie;
        }

        if ($copie->nom_de_la_base !== null && $this->bases->existe($copie->nom_de_la_base)) {
            throw new CopieImpossible(self::CAUSE_BASE_A_ARCHIVER);
        }

        $connexion = DB::connection('demo_admin');
        $creneau = $this->prendreUnCreneau();

        if ($creneau === null) {
            throw new CopieImpossible(self::CAUSE_CREATIONS_EN_COURS);
        }

        $nom = $this->bases->nomDUneCopie();

        try {
            $connexion->select('select pg_advisory_lock(hashtext(?))', ['demonstration-modele-'.$copie->type->value]);
            $modele = Modele::on('demo_admin')->where('type', $copie->type)->where('etat', EtatDUnModele::EnService)->first();

            if ($modele === null) {
                throw new CopieImpossible(self::CAUSE_SANS_MODELE);
            }

            $copie->forceFill(['nom_de_la_base' => $nom, 'etat' => EtatDUneCopie::EnPreparation])->save();
            $this->bases->copierUnModele($modele->nom_de_la_base, $nom);
            DossiersDeLaDemonstration::copierLesFichiersDuModele($modele->nom_de_la_base, $copie->reference);
            $copie->forceFill([
                'etat' => EtatDUneCopie::Prete,
                'modele_id' => $modele->id,
                'version' => VersionServie::courante(),
                'empreinte_du_schema' => $modele->empreinte_du_schema,
                'prete_le' => CarbonImmutable::now(),
                'essais' => 0,
                'prochain_essai_le' => null,
                'cause_de_l_echec' => null,
            ])->save();
        } catch (Throwable $erreur) {
            $this->bases->supprimer($nom);
            DossiersDeLaDemonstration::effacerUneCopie($copie->reference);
            $copie->forceFill(['essais' => $copie->essais + 1, 'cause_de_l_echec' => mb_substr($erreur->getMessage(), 0, 2000)])->save();

            throw $erreur instanceof CopieImpossible ? $erreur : new CopieImpossible($erreur->getMessage(), 0, $erreur);
        } finally {
            $connexion->select('select pg_advisory_unlock_all()');
        }

        return $copie;
    }

    /**
     * La copie marquée en échec après son dernier essai : elle sera reprise
     * une heure plus tard, et l'état publié le dit. Une remise à zéro dont
     * la base existe encore (son archive a échoué) reste « à refaire »,
     * avec sa cause, pour que sa reprise refasse toute la remise à zéro,
     * archive comprise : une copie « en échec » se refait depuis le modèle,
     * et sa base ne doit jamais l'être sans archive.
     */
    public function marquerEnEchec(string $reference, string $cause): void
    {
        $copie = Copie::on('demo_admin')->where('reference', $reference)
            ->whereIn('etat', [EtatDUneCopie::EnPreparation->value, EtatDUneCopie::ARefaire->value, EtatDUneCopie::Perdue->value])
            ->first();

        if ($copie === null) {
            return;
        }

        $baseGardee = $copie->etat === EtatDUneCopie::ARefaire && $copie->nom_de_la_base !== null && $this->bases->existe($copie->nom_de_la_base);
        $copie->forceFill([
            'etat' => $baseGardee ? EtatDUneCopie::ARefaire : EtatDUneCopie::EnEchec,
            'cause_de_l_echec' => mb_substr($cause, 0, 2000),
            'prochain_essai_le' => CarbonImmutable::now()->addHour(),
        ])->save();
    }

    /**
     * Une place pour cette copie : sous le plafond, sous la part des
     * sociétés non validées pour une société non validée, et aucune
     * demande plus prioritaire n'attend (une société validée d'abord, puis
     * la plus ancienne).
     */
    public function aUnePlace(Copie $copie): bool
    {
        $maximum = max(0, (int) config('demonstration.copies_max'));
        $partDesValidees = min(100, max(0, (int) config('demonstration.part_des_validees')));
        $occupees = Copie::on('demo_admin')->whereKeyNot($copie->id)->whereIn('etat', self::etatsQuiOccupent());

        if ((clone $occupees)->count() >= $maximum) {
            return false;
        }

        if (! $copie->validee && (clone $occupees)->where('validee', false)->count() >= intdiv($maximum * (100 - $partDesValidees), 100)) {
            return false;
        }

        return ! Copie::on('demo_admin')->whereKeyNot($copie->id)
            ->where('etat', EtatDUneCopie::EnAttenteDUnePlace)
            ->where(fn ($avant) => $avant->where('validee', '>', $copie->validee)
                ->orWhere(fn ($meme) => $meme->where('validee', $copie->validee)->where('creee_le', '<', $copie->creee_le)))
            ->exists();
    }

    /**
     * Les références des copies qui attendent une place, dans l'ordre où
     * la place leur revient.
     *
     * @return list<string>
     */
    public function enAttenteDUnePlace(): array
    {
        return array_values(Copie::on('demo_admin')->where('etat', EtatDUneCopie::EnAttenteDUnePlace)
            ->orderByDesc('validee')->orderBy('creee_le')->orderBy('id')
            ->pluck('reference')->all());
    }

    /**
     * @return list<string>
     */
    private static function etatsQuiOccupent(): array
    {
        return array_values(array_map(
            static fn (EtatDUneCopie $etat): string => $etat->value,
            array_filter(EtatDUneCopie::cases(), static fn (EtatDUneCopie $etat): bool => $etat->occupeUnePlace()),
        ));
    }

    /**
     * Un des créneaux de création (verrous consultatifs de session), ou null
     * quand tous sont pris.
     */
    private function prendreUnCreneau(): ?int
    {
        $creneaux = max(1, (int) config('demonstration.copies_en_meme_temps'));

        for ($creneau = 1; $creneau <= $creneaux; $creneau++) {
            if (DB::connection('demo_admin')->scalar('select pg_try_advisory_lock(hashtext(?))', ['demonstration-creation-'.$creneau]) === true) {
                return $creneau;
            }
        }

        return null;
    }
}
