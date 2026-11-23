<?php

namespace App\Domain\Demonstration\Visites;

use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Temoin;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Providers\SiteDePiliqiServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Les témoins des visites d'une copie (lot DEMO-COPIE, C.4 et C.5, choix
 * 32) : une valeur de 256 bits tirée au hasard, dont le registre ne garde
 * que l'empreinte SHA-256, posée dans le témoin piliqi-demo
 * (__Host-piliqi-demo en production : Secure, sans Domain, sur /), HttpOnly,
 * SameSite=Lax, valable jusqu'à la fin de la copie, jamais chiffré par
 * Laravel (exclu d'EncryptCookies, lu brut). Il porte la génération du lien
 * à sa création : un renouvellement le retire avec tous les autres
 * (choix 10). Au plus 50 témoins actifs par copie : au-delà, le plus ancien
 * est retiré.
 */
final class TemoinsDesVisites
{
    public const NOM = 'piliqi-demo';

    public const MAXIMUM_PAR_COPIE = 50;

    public static function nom(): string
    {
        return (app()->isProduction() ? SiteDePiliqiServiceProvider::PREFIXE_DES_TEMOINS_DE_PRODUCTION : '').self::NOM;
    }

    /**
     * Un témoin neuf pour cette copie, et le témoin HTTP qui le pose.
     */
    public function creer(Copie $copie, string $entree): Cookie
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $valeur = MessagesSignes::enBase64Url(random_bytes(32));
        $maintenant = CarbonImmutable::now();

        DB::connection('registre')->transaction(function () use ($copie, $entree, $valeur, $maintenant): void {
            Temoin::query()->create([
                'empreinte' => hash('sha256', $valeur),
                'copie_id' => $copie->id,
                'generation_du_lien' => $copie->generation_du_lien,
                'entree' => $entree,
                'cree_le' => $maintenant,
                'fin' => $copie->fin,
                'vu_le' => $maintenant,
            ]);
            $actifs = Temoin::query()->where('copie_id', $copie->id)->whereNull('retire_le')->orderByDesc('cree_le')->orderByDesc('id')->pluck('id');

            if ($actifs->count() > self::MAXIMUM_PAR_COPIE) {
                Temoin::query()->whereKey($actifs->slice(self::MAXIMUM_PAR_COPIE)->all())->update(['retire_le' => $maintenant]);
            }
        });

        return new Cookie(self::nom(), $valeur, $copie->fin, '/', null, app()->isProduction(), true, true, Cookie::SAMESITE_LAX);
    }

    /**
     * La visite de cette valeur de témoin : la copie qu'elle ouvre, ou
     * « terminée » quand la fin de sa copie est passée (la ligne d'une copie
     * supprimée reste 30 jours pour le dire) ; null pour une valeur
     * inconnue, mal formée, ou d'un témoin retiré (« Quitter », un
     * renouvellement du lien) ou d'une autre génération du lien d'une copie
     * encore ouverte.
     */
    public function retrouver(mixed $valeur): ?Visite
    {
        if (! is_string($valeur) || preg_match('/^[A-Za-z0-9_-]{43}$/', $valeur) !== 1) {
            return null;
        }

        $temoin = Temoin::query()->with('copie')->where('empreinte', hash('sha256', $valeur))->first();
        $copie = $temoin?->copie;

        if ($temoin === null || $copie === null) {
            return null;
        }

        if ($copie->etat === EtatDUneCopie::Supprimee || $copie->estEchue(CarbonImmutable::now())) {
            return new Visite($temoin, $copie, true);
        }

        if ($temoin->retire_le !== null || $temoin->generation_du_lien !== $copie->generation_du_lien) {
            return null;
        }

        return new Visite($temoin, $copie, false);
    }

    /**
     * La visite vue maintenant (au plus une écriture par minute).
     */
    public function voir(Temoin $temoin): void
    {
        $maintenant = CarbonImmutable::now();

        if ($temoin->vu_le === null || $temoin->vu_le->addMinute()->lessThanOrEqualTo($maintenant)) {
            Temoin::query()->whereKey($temoin->id)->update(['vu_le' => $maintenant]);
        }
    }

    public function retirer(Temoin $temoin): void
    {
        Temoin::query()->whereKey($temoin->id)->whereNull('retire_le')->update(['retire_le' => CarbonImmutable::now()]);
    }

    public function oublier(): Cookie
    {
        return new Cookie(self::nom(), '', 1, '/', null, app()->isProduction(), true, true, Cookie::SAMESITE_LAX);
    }
}
