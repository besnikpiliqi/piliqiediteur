<?php

namespace App\Domain\Demonstration\Taches;

use App\Console\Commands\VerifierIntegriteFiscale;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Temoin;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Les tâches du calendrier des magasins, passées dans chaque copie (lot
 * DEMO-COPIE, C.13, choix 22) : la clôture automatique, le passage en
 * retard, l'expiration des devis, les relances (capturées dans « E-mails
 * qui seraient partis »), les invitations des cabinets, le rattachement
 * des experts-comptables, l'effacement des téléchargements, la reprise des
 * transmissions (vers la fausse plateforme) et la purge des jetons, dans
 * l'ordre de TachesDesSites::DE_CHAQUE_COPIE, sans jamais passer par le
 * planificateur (SemisEnCours, qui vit dans le cache de la base courante,
 * n'est jamais posé dans une copie).
 *
 * - aPasser() : chaque heure, les copies servies visitées depuis moins de
 *   48 heures ; la nuit, toutes les copies servies.
 * - passer() : dans la copie ouverte par le rôle des pages ; la nuit, de
 *   plus, la vérification incrémentale de son registre fiscal (caisse:
 *   verifier, clé publique de démonstration) quand elle a été visitée
 *   depuis sa dernière vérification ; chaque défaut de la nuit compte dans
 *   une seule ligne de l'état publié, sans rien bloquer. La dernière passe
 *   s'écrit au registre, par demo_admin.
 *
 * Une copie vieille de 20 jours jamais visitée rattrape ainsi ses journées
 * dans l'ordre, un ticket Z par jour, à l'heure du serveur, jamais avancée
 * ni reculée.
 */
final class TachesDesCopies
{
    public const HEURES_DE_VISITE = 48;

    public const CLE_DES_VERIFICATIONS_EN_DEFAUT = 'demonstration:verifications-en-defaut';

    public function __construct(private readonly OuvertureDUneCopie $ouverture) {}

    /**
     * Les références des copies dont les tâches passent maintenant.
     *
     * @return list<string>
     */
    public function aPasser(bool $nuit): array
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $maintenant = CarbonImmutable::now();
        $copies = Copie::on('demo_admin')
            ->whereIn('etat', [EtatDUneCopie::Prete->value, EtatDUneCopie::EnLectureSeule->value])
            ->where('fin', '>', $maintenant);

        if (! $nuit) {
            $copies->whereExists(fn ($visite) => $visite->from('temoins')->whereColumn('temoins.copie_id', 'copies.id')->where('temoins.vu_le', '>=', $maintenant->subHours(self::HEURES_DE_VISITE)));
        }

        return array_values(array_map('strval', $copies->orderBy('id')->pluck('reference')->all()));
    }

    /**
     * Passe les tâches de chaque copie dans cette copie, rend les tâches qui
     * ont échoué (avec leur sortie).
     *
     * @return list<string>
     */
    public function passer(string $reference, bool $nuit): array
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $copie = Copie::on('demo_admin')->where('reference', $reference)->first();
        $maintenant = CarbonImmutable::now();

        if ($copie === null || ! $copie->etat->seSert() || $copie->estEchue($maintenant)) {
            return [];
        }

        $verifier = $nuit && ($copie->derniere_verification_le === null || Temoin::on('demo_admin')->where('copie_id', $copie->id)->where('vu_le', '>', $copie->derniere_verification_le)->exists());
        $echecs = $this->ouverture->pendant($copie, fn (): array => PlaceDesCopies::pendantUnGestePermis(function () use ($verifier): array {
            $echecs = [];

            foreach ([...TachesDesSites::DE_CHAQUE_COPIE, ...($verifier ? TachesDesSites::DE_LA_NUIT_DES_COPIES : [])] as $tache) {
                app(TenantContext::class)->clear();

                if (Artisan::call($tache) !== 0) {
                    $echecs[] = $tache.' : '.trim(Artisan::output());
                }
            }

            app(TenantContext::class)->clear();

            return $echecs;
        }));

        $copie->forceFill(['derniere_passe_des_taches_le' => $maintenant, ...($verifier ? ['derniere_verification_le' => $maintenant] : [])])->save();

        if ($verifier && array_filter($echecs, static fn (string $echec): bool => str_starts_with($echec, VerifierIntegriteFiscale::class.' :')) !== []) {
            self::signalerUneVerificationEnDefaut($reference);
        }

        return $echecs;
    }

    /**
     * La copie est-elle à l'heure : sa dernière passe date de moins d'une
     * heure (C.13, entrée dans une copie) ?
     */
    public static function estALHeure(Copie $copie, CarbonImmutable $maintenant): bool
    {
        return $copie->derniere_passe_des_taches_le !== null && $copie->derniere_passe_des_taches_le->greaterThan($maintenant->subHour());
    }

    /**
     * Une copie dont la vérification de nuit a trouvé un défaut : les défauts
     * d'une nuit se réunissent en une seule ligne de l'état publié.
     */
    public static function signalerUneVerificationEnDefaut(string $reference): void
    {
        $cache = Cache::store('registre');
        $defauts = $cache->get(self::CLE_DES_VERIFICATIONS_EN_DEFAUT);
        $defauts = is_array($defauts) ? $defauts : [];
        $defauts[$reference] = true;
        $cache->put(self::CLE_DES_VERIFICATIONS_EN_DEFAUT, $defauts, 26 * 3600);
    }

    /**
     * Le nombre de copies dont la vérification de la dernière nuit a trouvé
     * un défaut.
     */
    public static function verificationsEnDefaut(): int
    {
        $defauts = Cache::store('registre')->get(self::CLE_DES_VERIFICATIONS_EN_DEFAUT);

        return is_array($defauts) ? count($defauts) : 0;
    }
}
