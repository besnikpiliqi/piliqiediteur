<?php

namespace App\Integrations\Rne;

use App\Integrations\EnvoiRecommande\AdressePostale;
use App\Integrations\Rne\Contracts\RegistreNationalDesEntreprises;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * L'API du registre national des entreprises de l'INPI (voir
 * config/services.php) : POST /sso/login avec l'identifiant et le mot de
 * passe de l'éditeur (INPI_RNE_IDENTIFIANT, INPI_RNE_MOT_DE_PASSE) donne un
 * jeton, puis GET /companies/{siren} la formalité de l'entreprise, avec le
 * jeton dans l'en-tête Authorization.
 *
 * La formalité se lit avec tolérance (documentation technique des API
 * formalités v4.0 de l'INPI, structure à confirmer sur des réponses réelles
 * avant la mise en production) :
 * - formality.content.personneMorale.composition.pouvoirs : les dirigeants
 *   d'une société, chacun une personne physique (individu.descriptionPersonne :
 *   nom, nomUsage, prenoms, dateDeNaissance AAAA-MM) ou une personne morale
 *   (entreprise : siren, denomination), avec son rôle (roleEntreprise) et
 *   la date d'effet de ce rôle (dateEffetRoleDeclarant) ;
 * - formality.content.personnePhysique.identite.entrepreneur.descriptionPersonne :
 *   l'entrepreneur individuel, seul dirigeant de son entreprise ;
 * - adresseEntreprise.adresse : l'adresse du siège (numVoie, typeVoie,
 *   voie, complementLocalisation, codePostal, commune).
 * Seuls les rôles de ROLES_QUI_REPRESENTENT donnent le pouvoir de
 * représenter l'entreprise. Un champ absent ou d'un autre type n'est
 * jamais tenu pour une réponse : la réponse est alors illisible.
 *
 * - 200 : la formalité ;
 * - 404 : le registre ne connaît pas ce SIREN ;
 * - toute autre réponse, une réponse illisible, un jeton refusé ou un
 *   service injoignable : RneIndisponibleException.
 * Le journal de l'application note le statut HTTP d'une consultation
 * impossible, jamais les identifiants, le jeton ni la réponse.
 */
class InpiRne implements RegistreNationalDesEntreprises
{
    /**
     * Les codes de rôle de la nomenclature de l'INPI qui donnent le pouvoir
     * de représenter l'entreprise : gérant (30), président du conseil
     * d'administration (51), directeur général (53), directeur général
     * délégué (56), président du directoire (65), directeur général unique
     * (66), président (73). À confirmer sur la nomenclature officielle avant
     * la mise en production.
     */
    public const ROLES_QUI_REPRESENTENT = ['30', '51', '53', '56', '65', '66', '73'];

    private ?string $jeton = null;

    public function representants(string $siren): array
    {
        $contenu = $this->formalite($siren);

        if ($contenu === null) {
            return [];
        }

        $entrepreneur = $contenu['personnePhysique']['identite']['entrepreneur']['descriptionPersonne'] ?? null;

        if (is_array($entrepreneur)) {
            return [$this->personnePhysique($entrepreneur, 'Entrepreneur individuel', true, null)];
        }

        $pouvoirs = $contenu['personneMorale']['composition']['pouvoirs'] ?? null;

        if (! is_array($pouvoirs)) {
            throw $this->illisible();
        }

        return array_values(array_map(fn (mixed $pouvoir): RepresentantRne => $this->representant($pouvoir), $pouvoirs));
    }

    public function adresseDuSiege(string $siren): ?AdressePostale
    {
        $contenu = $this->formalite($siren);
        $personne = $contenu['personneMorale'] ?? $contenu['personnePhysique'] ?? null;
        $adresse = is_array($personne) ? ($personne['adresseEntreprise']['adresse'] ?? null) : null;

        if (! is_array($adresse)) {
            return null;
        }

        $voie = trim(implode(' ', array_filter([$this->texte($adresse, 'numVoie'), $this->texte($adresse, 'typeVoie'), $this->texte($adresse, 'voie')])));
        $codePostal = $this->texte($adresse, 'codePostal');
        $ville = $this->texte($adresse, 'commune');

        if ($voie === '' || $codePostal === null || $ville === null) {
            return null;
        }

        $identite = $personne['identite']['entreprise'] ?? [];

        return new AdressePostale(
            denomination: is_array($identite) ? $this->texte($identite, 'denomination') : null,
            adresse: $voie,
            complementAdresse: $this->texte($adresse, 'complementLocalisation'),
            codePostal: $codePostal,
            ville: $ville,
            source: AdressePostale::SOURCE_RNE,
        );
    }

    private function representant(mixed $pouvoir): RepresentantRne
    {
        if (! is_array($pouvoir)) {
            throw $this->illisible();
        }

        $role = is_scalar($pouvoir['roleEntreprise'] ?? null) ? (string) $pouvoir['roleEntreprise'] : '';
        $represente = in_array($role, self::ROLES_QUI_REPRESENTENT, true);
        $debut = $this->texte($pouvoir, 'dateEffetRoleDeclarant');
        $entreprise = $pouvoir['entreprise'] ?? null;

        if (is_array($entreprise)) {
            $sirenDuDirigeant = $this->texte($entreprise, 'siren');
            $denomination = $this->texte($entreprise, 'denomination');

            if ($sirenDuDirigeant === null || $denomination === null) {
                throw $this->illisible();
            }

            return new RepresentantRne($denomination, null, [], null, self::libelleDuRole($role), $represente, $sirenDuDirigeant, $debut);
        }

        $personne = $pouvoir['individu']['descriptionPersonne'] ?? null;

        if (! is_array($personne)) {
            throw $this->illisible();
        }

        return $this->personnePhysique($personne, self::libelleDuRole($role), $represente, $debut);
    }

    /**
     * @param  array<mixed>  $personne
     */
    private function personnePhysique(array $personne, string $role, bool $represente, ?string $debut): RepresentantRne
    {
        $nom = $this->texte($personne, 'nom') ?? throw $this->illisible();
        $prenoms = array_values(array_filter(is_array($personne['prenoms'] ?? null) ? $personne['prenoms'] : [], fn (mixed $prenom): bool => is_string($prenom) && trim($prenom) !== ''));

        return new RepresentantRne($nom, $this->texte($personne, 'nomUsage'), $prenoms, $this->texte($personne, 'dateDeNaissance'), $role, $represente, null, $debut);
    }

    private static function libelleDuRole(string $code): string
    {
        return match ($code) {
            '30' => 'Gérant',
            '51' => 'Président du conseil d\'administration',
            '53' => 'Directeur général',
            '56' => 'Directeur général délégué',
            '65' => 'Président du directoire',
            '66' => 'Directeur général unique',
            '73' => 'Président',
            default => "Rôle {$code}",
        };
    }

    /**
     * Le contenu de la formalité de l'entreprise, null pour un SIREN
     * inconnu (404).
     *
     * @return array<mixed>|null
     *
     * @throws RneIndisponibleException
     */
    private function formalite(string $siren): ?array
    {
        $jeton = $this->jeton();
        $reponse = $this->envoyer(fn (PendingRequest $requete): Response => $requete->withToken($jeton)->get("companies/{$siren}"));

        if ($reponse->status() === 404) {
            return null;
        }

        $contenu = $this->lire($reponse)['formality']['content'] ?? null;

        return is_array($contenu) ? $contenu : throw $this->illisible();
    }

    /**
     * @throws RneIndisponibleException
     */
    private function jeton(): string
    {
        if ($this->jeton !== null) {
            return $this->jeton;
        }

        $identifiant = config('services.rne.identifiant');
        $motDePasse = config('services.rne.mot_de_passe');

        if (! is_string($identifiant) || $identifiant === '' || ! is_string($motDePasse) || $motDePasse === '') {
            Log::warning('RNE : les identifiants de l\'INPI (INPI_RNE_IDENTIFIANT, INPI_RNE_MOT_DE_PASSE) sont absents.');

            throw new RneIndisponibleException;
        }

        $jeton = $this->lire($this->envoyer(fn (PendingRequest $requete): Response => $requete->post('sso/login', ['username' => $identifiant, 'password' => $motDePasse])))['token'] ?? null;

        return $this->jeton = is_string($jeton) && $jeton !== '' ? $jeton : throw $this->illisible();
    }

    /**
     * Envoie la requête au registre, réessayée une fois si le service est
     * injoignable.
     *
     * @param  Closure(PendingRequest): Response  $requete
     *
     * @throws RneIndisponibleException
     */
    private function envoyer(Closure $requete): Response
    {
        try {
            return $requete(Http::baseUrl(config('services.rne.url'))
                ->timeout(config('services.rne.delai'))
                ->connectTimeout(5)
                ->acceptJson()
                ->retry(2, 500, static fn (Throwable $erreur): bool => $erreur instanceof ConnectionException, throw: false));
        } catch (ConnectionException) {
            Log::warning('RNE : le registre national des entreprises est injoignable.');

            throw new RneIndisponibleException;
        }
    }

    /**
     * @return array<mixed>
     *
     * @throws RneIndisponibleException
     */
    private function lire(Response $reponse): array
    {
        if (! $reponse->successful() || ! is_array($reponse->json())) {
            Log::warning('RNE : le registre national des entreprises n\'a pas répondu.', ['statut_http' => $reponse->status()]);

            throw new RneIndisponibleException;
        }

        return $reponse->json();
    }

    private function illisible(): RneIndisponibleException
    {
        Log::warning('RNE : réponse illisible du registre national des entreprises.');

        return new RneIndisponibleException;
    }

    /**
     * @param  array<mixed>  $donnees
     */
    private function texte(array $donnees, string $cle): ?string
    {
        $valeur = is_scalar($donnees[$cle] ?? null) ? trim((string) $donnees[$cle]) : '';

        return $valeur === '' ? null : $valeur;
    }
}
