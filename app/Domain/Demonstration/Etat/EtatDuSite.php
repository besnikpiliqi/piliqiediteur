<?php

namespace App\Domain\Demonstration\Etat;

use App\Domain\Demonstration\Construction\DossiersDeLaDemonstration;
use App\Domain\Demonstration\Construction\NettoyageDeLaDemonstration;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Enums\EtatDUnModele;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Modele;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\VersionServie;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Support\Reglage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use SensitiveParameter;

/**
 * L'état publié du site de démonstration (lot DEMO-COPIE, C.19), que le
 * site réel lit chaque heure (GET /interne/etat) : pour chaque type, le
 * modèle en service et la date de sa construction, le dernier échec d'une
 * construction ; le nombre de copies par état ; la place libre du disque ;
 * la version ; et la liste des problèmes, chacun une phrase (dont les
 * requêtes arrêtées par un nom de base hors du motif d'une copie). Aucune
 * référence, aucune donnée d'une copie. Il est signé par la clé Ed25519 de
 * l'état du site de démonstration (DEMONSTRATION_ETAT_CLE_PRIVEE), dont
 * seul le site réel a la clé publique : le site de démonstration n'envoie
 * rien vers l'extérieur, et ne lit jamais le site réel.
 */
final class EtatDuSite
{
    public const VERSION = 1;

    /**
     * Au-delà, un type sans construction réussie est un problème.
     */
    public const JOURS_SANS_CONSTRUCTION = 3;

    /**
     * Sous ce pourcentage de place libre, plus aucune copie neuve ne se
     * crée (C.16).
     */
    public const PLACE_LIBRE_MINIMALE = 20;

    /**
     * Le nombre des requêtes arrêtées depuis un jour par un nom de base
     * hors du motif d'une copie (C.6.6), dans le cache du registre, que le
     * rôle des pages écrit.
     */
    public const CLE_DES_NOMS_HORS_DU_MOTIF = 'demonstration:noms-hors-du-motif';

    public const MESSAGE_CLE_ABSENTE = 'La clé de l\'état publié du site de démonstration n\'est pas réglée (DEMONSTRATION_ETAT_CLE_PRIVEE) : tirez-la par php artisan demonstration:generer-cle --etat (voir le README).';

    /**
     * @return array<string, mixed>
     */
    public function construire(): array
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $maintenant = CarbonImmutable::now();
        $problemes = [];
        $modeles = [];

        foreach (TypeDActivite::cases() as $type) {
            $enService = Modele::query()->where('type', $type)->where('etat', EtatDUnModele::EnService)->first(['construit_le']);
            $echec = Modele::query()->where('type', $type)->where('etat', EtatDUnModele::EnEchec)
                ->where('commence_le', '>', $enService->construit_le ?? CarbonImmutable::createFromTimestampUTC(0))
                ->orderByDesc('commence_le')->first(['commence_le', 'cause_de_l_echec']);
            $modeles[$type->value] = [
                'construit_le' => $enService?->construit_le === null ? null : MessagesSignes::instant($enService->construit_le),
                'dernier_echec' => $echec === null ? null : MessagesSignes::instant($echec->commence_le),
            ];

            if ($echec !== null) {
                $problemes[] = sprintf('La construction du modèle « %s » a échoué : %s', $type->value, mb_substr((string) $echec->cause_de_l_echec, 0, 500));
            }

            if ($enService?->construit_le === null || $enService->construit_le->addSeconds(self::JOURS_SANS_CONSTRUCTION * 86400)->lessThan($maintenant)) {
                $problemes[] = sprintf('Le modèle « %s » n\'a pas été construit depuis plus de %d jours.', $type->value, self::JOURS_SANS_CONSTRUCTION);
            }
        }

        $copies = [];

        foreach (Copie::query()->selectRaw('etat, count(*) as nombre')->groupBy('etat')->orderBy('etat')->toBase()->get() as $ligne) {
            $copies[(string) $ligne->etat] = (int) $ligne->nombre;
        }

        if (($copies[EtatDUneCopie::EnEchec->value] ?? 0) > 0) {
            $problemes[] = sprintf('%d copie(s) en échec de création.', $copies[EtatDUneCopie::EnEchec->value]);
        }

        $arretees = Copie::query()->whereIn('etat', [EtatDUneCopie::EnArchivage->value, EtatDUneCopie::ARefaire->value])->whereNotNull('cause_de_l_echec')->count();

        if ($arretees > 0) {
            $problemes[] = sprintf('%d copie(s) arrêtée(s) par une erreur pendant l\'archive de leur caisse ou leur remise à zéro : leur base n\'est pas supprimée sans archive relue.', $arretees);
        }

        $horsDuMotif = (int) Cache::store('registre')->get(self::CLE_DES_NOMS_HORS_DU_MOTIF, 0);

        if ($horsDuMotif > 0) {
            $problemes[] = sprintf('%d requête(s) arrêtée(s) depuis un jour : le nom de base d\'une copie au registre n\'est pas du motif d\'une copie.', $horsDuMotif);
        }

        $refusDuNettoyage = NettoyageDeLaDemonstration::dernierRefus();

        if ($refusDuNettoyage !== null) {
            $problemes[] = $refusDuNettoyage;
        }

        $placeLibre = self::placeLibre();

        if ($placeLibre < self::PLACE_LIBRE_MINIMALE) {
            $problemes[] = sprintf('Il reste %d %% de place sur le disque des copies : plus aucune copie neuve ne se crée.', $placeLibre);
        }

        return [
            'v' => self::VERSION,
            'site' => Reglage::texte('demonstration.nom_du_site'),
            'emis_le' => MessagesSignes::instant($maintenant),
            'version' => VersionServie::courante(),
            'modeles' => $modeles,
            'copies' => $copies,
            'place_libre_du_disque' => $placeLibre,
            'problemes' => $problemes,
        ];
    }

    /**
     * L'état signé : le JSON canonique et sa signature Ed25519 détachée,
     * en base64url.
     *
     * @return array{etat: string, signature: string}
     */
    public function publier(): array
    {
        $cle = self::clePrivee(config('demonstration.etat.cle_privee'));
        $etat = MessagesSignes::canonique($this->construire());

        return ['etat' => $etat, 'signature' => MessagesSignes::enBase64Url(sodium_crypto_sign_detached($etat, $cle))];
    }

    /**
     * Une requête arrêtée par un nom de base hors du motif d'une copie
     * (voir OuvrirLaCopie) : comptée un jour dans l'état publié.
     */
    public static function signalerUnNomHorsDuMotif(): void
    {
        $cache = Cache::store('registre');
        $cache->add(self::CLE_DES_NOMS_HORS_DU_MOTIF, 0, 86400);
        $cache->increment(self::CLE_DES_NOMS_HORS_DU_MOTIF);
    }

    /**
     * La place libre du disque des copies, en pour cent.
     */
    public static function placeLibre(): int
    {
        $dossier = DossiersDeLaDemonstration::racine();
        $existant = is_dir($dossier) ? $dossier : dirname($dossier);
        $total = disk_total_space($existant);
        $libre = disk_free_space($existant);

        return $total === false || $libre === false || $total <= 0 ? 0 : (int) floor($libre * 100 / $total);
    }

    private static function clePrivee(#[SensitiveParameter] mixed $valeur): string
    {
        $octets = is_string($valeur) && $valeur !== '' ? base64_decode($valeur, true) : false;

        if ($octets === false || strlen($octets) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException(self::MESSAGE_CLE_ABSENTE);
        }

        return $octets;
    }
}
