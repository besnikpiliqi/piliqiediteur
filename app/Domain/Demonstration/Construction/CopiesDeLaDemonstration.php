<?php

namespace App\Domain\Demonstration\Construction;

use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Enums\EtatDUnModele;
use App\Domain\Demonstration\Exceptions\CopieImpossible;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Lien;
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
 *   le verrou consultatif du type (la bascule d'une construction attend la
 *   fin de la copie), puis les réglages de la base, puis les fichiers du
 *   modèle ;
 * - échec ou modèle occupé : aucune base à moitié faite ne reste
 *   (DROP DATABASE … WITH (FORCE)), et le travail réessaie.
 */
final class CopiesDeLaDemonstration
{
    public const CAUSE_SANS_MODELE = 'Aucun modèle de ce type n\'est en service : la construction de la nuit n\'a pas encore réussi.';

    public const CAUSE_CREATIONS_EN_COURS = 'D\'autres copies sont en cours de création : nouvel essai dans un instant.';

    public function __construct(private readonly BasesDeLaDemonstration $bases) {}

    /**
     * La demande inscrite au registre, une seule fois par référence, avec
     * son lien (seulement l'empreinte de son secret).
     *
     * @param  array{reference: string, repere: string, type: TypeDActivite, validee: bool, empreinte_du_lien: string, generation: int, fin: CarbonImmutable}  $demande
     */
    public function inscrire(array $demande): Copie
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);

        return DB::connection('demo_admin')->transaction(function () use ($demande): Copie {
            $connue = Copie::on('demo_admin')->where('reference', $demande['reference'])->lockForUpdate()->first();

            if ($connue !== null) {
                return $connue;
            }

            $maintenant = CarbonImmutable::now();
            $copie = Copie::on('demo_admin')->create([
                'reference' => $demande['reference'],
                'repere' => $demande['repere'],
                'type' => $demande['type'],
                'validee' => $demande['validee'],
                'creee_le' => $maintenant,
                'fin' => $demande['fin'],
                'generation_du_lien' => $demande['generation'],
                'etat' => EtatDUneCopie::EnPreparation,
            ]);
            Lien::on('demo_admin')->create([
                'copie_id' => $copie->id,
                'empreinte' => $demande['empreinte_du_lien'],
                'generation' => $demande['generation'],
                'fin' => $demande['fin'],
                'cree_le' => $maintenant,
            ]);

            return $copie;
        });
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

        $connexion = DB::connection('demo_admin');
        $creneau = $this->prendreUnCreneau();

        if ($creneau === null) {
            throw new CopieImpossible(self::CAUSE_CREATIONS_EN_COURS);
        }

        $nom = $copie->nom_de_la_base ?? $this->bases->nomDUneCopie();

        try {
            $connexion->select('select pg_advisory_lock(hashtext(?))', ['demonstration-modele-'.$copie->type->value]);
            $modele = Modele::on('demo_admin')->where('type', $copie->type)->where('etat', EtatDUnModele::EnService)->first();

            if ($modele === null) {
                throw new CopieImpossible(self::CAUSE_SANS_MODELE);
            }

            $copie->forceFill(['nom_de_la_base' => $nom, 'etat' => EtatDUneCopie::EnPreparation])->save();
            $this->bases->supprimer($nom);
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
     * une heure plus tard, et l'état publié le dit.
     */
    public function marquerEnEchec(string $reference, string $cause): void
    {
        Copie::on('demo_admin')->where('reference', $reference)
            ->whereIn('etat', [EtatDUneCopie::EnPreparation->value, EtatDUneCopie::ARefaire->value, EtatDUneCopie::Perdue->value])
            ->update([
                'etat' => EtatDUneCopie::EnEchec->value,
                'cause_de_l_echec' => mb_substr($cause, 0, 2000),
                'prochain_essai_le' => CarbonImmutable::now()->addHour(),
            ]);
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
