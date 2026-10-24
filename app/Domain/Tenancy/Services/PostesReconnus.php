<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PosteReconnu;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Les postes du magasin que le propriétaire ou un responsable de ce
 * magasin reconnaît : un navigateur du magasin reçoit un témoin propre à ce
 * poste (un jeton aléatoire, dans le témoin chiffré « postes_reconnus »,
 * dont la base ne garde que l'empreinte SHA-256), listé dans les
 * paramètres du magasin, retirable à tout moment.
 *
 * Tant que le mot de passe d'un compte est encore son identifiant, il
 * n'ouvre ce compte que depuis un poste reconnu, non retiré, d'un magasin
 * que ce compte ouvre (voir ouvreLeCompte(), que lit
 * FournisseurDesComptes::validateCredentials()) : un inconnu qui essaie, sur
 * internet, des nombres de 8 chiffres avec ce même nombre pour mot de passe
 * n'ouvre rien. Ailleurs, la personne doit avoir choisi son mot de passe.
 */
final class PostesReconnus
{
    public const TEMOIN = 'postes_reconnus';

    /**
     * Au plus tant de postes reconnus dans un même navigateur (un poste
     * partagé par plusieurs magasins de la société, par exemple) : le plus
     * ancien sort du témoin.
     */
    public const MAXIMUM_PAR_NAVIGATEUR = 10;

    /**
     * Reconnaît le navigateur de cette requête comme un poste de ce magasin,
     * sous ce nom, par le propriétaire ou un responsable du magasin (voir
     * PorteeDesDroits::gereLEquipeDe()), relu sous le verrou de sa ligne ;
     * le témoin du poste part avec la réponse.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reconnaitre(User $auteur, Magasin $magasin, string $nom, Request $requete): PosteReconnu
    {
        $nom = Validator::make(['nom' => trim($nom)], ['nom' => ['required', 'string', 'max:100']], attributes: ['nom' => 'nom du poste'])->validate()['nom'];
        $jeton = Str::random(64);

        $poste = app(TenantContext::class)->runAs($magasin, fn (): PosteReconnu => DB::transaction(function () use ($auteur, $magasin, $nom, $jeton): PosteReconnu {
            $this->exigerQuIlGereLeMagasin($auteur, $magasin);

            return tap((new PosteReconnu)->forceFill([
                'nom' => $nom,
                'jeton_hache' => self::empreinte($jeton),
                'reconnu_par' => $auteur->getKey(),
            ]))->save();
        }));

        $jetons = array_slice([...array_values(array_diff($this->jetonsDuNavigateur($requete), [$jeton])), $jeton], -self::MAXIMUM_PAR_NAVIGATEUR);
        Cookie::queue(Cookie::forever(self::TEMOIN, json_encode($jetons, JSON_THROW_ON_ERROR), httpOnly: true, sameSite: 'lax'));

        return $poste;
    }

    /**
     * Retire ce poste : son témoin n'ouvre plus aucun compte dont le mot de
     * passe est encore l'identifiant.
     *
     * @throws AuthorizationException
     * @throws LogicException quand le poste est déjà retiré
     */
    public function retirer(User $auteur, PosteReconnu $poste): void
    {
        DB::transaction(function () use ($auteur, $poste): void {
            $relu = PosteReconnu::query()->whereKey($poste->getKey())->lockForUpdate()->firstOrFail();
            $this->exigerQuIlGereLeMagasin($auteur, $relu->magasin()->firstOrFail());

            if ($relu->estRetire()) {
                throw new LogicException('Ce poste est déjà retiré.');
            }

            $relu->forceFill(['retire_par' => $auteur->getKey(), 'retire_le' => now()])->save();
        });

        $poste->refresh();
    }

    /**
     * Le navigateur de cette requête est-il un poste reconnu, non retiré,
     * d'un magasin que ce compte ouvre ? Seule lecture des postes hors du
     * magasin actif : à la connexion, les postes que désigne le témoin du
     * navigateur, filtrés par les magasins de ce compte.
     */
    public function ouvreLeCompte(User $compte, Request $requete): bool
    {
        $jetons = $this->jetonsDuNavigateur($requete);
        $magasins = $compte->magasinsAccessibles()->modelKeys();

        if ($jetons === [] || $magasins === []) {
            return false;
        }

        return PosteReconnu::withoutGlobalScope(MagasinScope::class)
            ->whereIn('jeton_hache', array_map(self::empreinte(...), $jetons))
            ->whereIn('magasin_id', $magasins)
            ->whereNull('retire_le')
            ->exists();
    }

    /**
     * Le poste reconnu de ce magasin que porte le navigateur de cette
     * requête, s'il y en a un et qu'il n'est pas retiré (dans le magasin
     * actif).
     */
    public function posteDuNavigateur(Request $requete): ?PosteReconnu
    {
        $jetons = $this->jetonsDuNavigateur($requete);

        return $jetons === [] ? null : PosteReconnu::query()
            ->whereIn('jeton_hache', array_map(self::empreinte(...), $jetons))
            ->whereNull('retire_le')
            ->first();
    }

    /**
     * Les jetons de postes que porte le témoin du navigateur (déchiffré par
     * EncryptCookies) ; aucun pour un témoin absent ou mal formé.
     *
     * @return list<string>
     */
    public function jetonsDuNavigateur(Request $requete): array
    {
        $jetons = json_decode((string) $requete->cookie(self::TEMOIN, ''), true);

        return is_array($jetons) ? array_values(array_filter($jetons, static fn (mixed $jeton): bool => is_string($jeton) && preg_match('/^[A-Za-z0-9]{64}$/D', $jeton) === 1)) : [];
    }

    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    /**
     * @throws AuthorizationException
     */
    private function exigerQuIlGereLeMagasin(User $auteur, Magasin $magasin): void
    {
        $relu = User::query()->whereKey($auteur->getKey())->lockForUpdate()->firstOrFail();
        $portee = app(PorteeDesDroits::class);

        if (! $portee->gereSonEquipe($relu) || ! $portee->gereLEquipeDe($relu, $magasin)) {
            throw new AuthorizationException('Seuls le propriétaire et un responsable du magasin reconnaissent et retirent ses postes.');
        }
    }
}
