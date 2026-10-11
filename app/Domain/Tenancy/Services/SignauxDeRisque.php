<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\SignalDeRisque;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Integrations\EnvoiRecommande\AdressePostale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Relève les signaux de risque encore actifs d'une société (voir
 * SignalDeRisque) : ceux que son état fait lever, et ceux qu'une ligne du
 * registre des vérifications a constatés, moins ceux que la vérification
 * qu'ils exigent a levés (voir SignalDeRisque::leveePar()). Fonction pure
 * des lignes de la société, de son état et de deux réponses lues sur son
 * SIREN (voir EvaluateurDeVerification) : rien d'autre n'est lu.
 */
class SignauxDeRisque
{
    /**
     * Le délai sous lequel un dirigeant est tenu pour tout juste nommé.
     */
    public const JOURS_D_UN_DIRIGEANT_RECENT = 30;

    /**
     * @param  Collection<int, VerificationSociete>  $lignes  les lignes de la société, par ordre d'inscription
     * @param  Collection<int, Magasin>  $magasinsReelsActifs
     * @param  bool  $autrePreuveDuSiren  une autre société du même SIREN a prouvé sa qualité de représentant
     * @param  bool  $revocationDuSiren  une société de ce SIREN a été révoquée
     * @return list<SignalDeRisque>
     */
    public function actifs(Societe $societe, Collection $lignes, ?User $proprietaire, Collection $magasinsReelsActifs, bool $autrePreuveDuSiren, bool $revocationDuSiren): array
    {
        $tiresDeSonEtat = [
            SignalDeRisque::DiffusionPartielle->value => $this->diffusionPartielle($societe, $lignes),
            SignalDeRisque::InscriptionsConcurrentes->value => $autrePreuveDuSiren,
            SignalDeRisque::AntecedentUsurpation->value => $revocationDuSiren,
            SignalDeRisque::DirigeantRecent->value => $this->dirigeantRecent($lignes, $proprietaire),
            SignalDeRisque::FormeContradictoire->value => $this->formeContradictoire($societe),
            SignalDeRisque::AdresseContradictoire->value => $this->adresseContradictoire($lignes, $magasinsReelsActifs),
            SignalDeRisque::IdentiteNonConcluante->value => $this->identiteNonConcluante($lignes, $proprietaire),
            SignalDeRisque::EssaisRepetes->value => false,
            SignalDeRisque::DecisionEditeur->value => false,
        ];

        return array_values(array_filter(SignalDeRisque::cases(), function (SignalDeRisque $signal) use ($tiresDeSonEtat, $lignes): bool {
            $dernierConstat = self::dernierConstat($lignes, $signal);

            return ($tiresDeSonEtat[$signal->value] || $dernierConstat > 0) && ! $this->estLeve($signal, $lignes, $dernierConstat);
        }));
    }

    /**
     * Le signal est-il levé par l'une des alternatives qu'il exige, chacune
     * réunie en entier ? La levée de l'éditeur ne vaut qu'après le dernier
     * constat du signal.
     *
     * @param  Collection<int, VerificationSociete>  $lignes
     */
    private function estLeve(SignalDeRisque $signal, Collection $lignes, int $dernierConstat): bool
    {
        return collect($signal->leveePar())->contains(fn (array $exigences): bool => collect($exigences)->every(fn (string $exigence): bool => match ($exigence) {
            SignalDeRisque::LRE => self::adresseControlee($lignes, TypeVerification::Lre),
            SignalDeRisque::COURRIER => self::adresseControlee($lignes, TypeVerification::Courrier),
            SignalDeRisque::COURRIER_A_L_ADRESSE_DU_RNE => self::adresseControlee($lignes, TypeVerification::Courrier, AdressePostale::SOURCE_RNE),
            SignalDeRisque::MANUELLE => $lignes->contains(fn (VerificationSociete $ligne): bool => $ligne->type === TypeVerification::Manuelle
                && $ligne->estConcluante()
                && $ligne->signal === $signal
                && $ligne->getKey() > $dernierConstat),
        }));
    }

    /**
     * Un code de confirmation du motif de la vérification, reçu par ce
     * canal (courrier ou lettre électronique), et saisi ; à une adresse de
     * cette source, si elle est donnée.
     *
     * @param  Collection<int, VerificationSociete>  $lignes
     */
    public static function adresseControlee(Collection $lignes, TypeVerification $canal, ?string $source = null): bool
    {
        return $lignes->contains(fn (VerificationSociete $ligne): bool => $ligne->type === $canal
            && $ligne->estConcluante()
            && ($ligne->details['motif'] ?? null) === MotifCode::Verification->value
            && ($source === null || ($ligne->details['source_adresse'] ?? null) === $source));
    }

    /**
     * L'identifiant du dernier constat de ce signal, 0 s'il n'a jamais été
     * constaté.
     *
     * @param  Collection<int, VerificationSociete>  $lignes
     */
    public static function dernierConstat(Collection $lignes, SignalDeRisque $signal): int
    {
        return (int) $lignes->filter(fn (VerificationSociete $ligne): bool => $ligne->type === TypeVerification::Signal && $ligne->signal === $signal)->max('id');
    }

    /**
     * @param  Collection<int, VerificationSociete>  $lignes
     */
    private function diffusionPartielle(Societe $societe, Collection $lignes): bool
    {
        $unite = $lignes->last(fn (VerificationSociete $ligne): bool => $ligne->type === TypeVerification::SireneUnite
            && $ligne->estConcluante()
            && ($ligne->details['siren'] ?? null) === $societe->siren);

        return (bool) ($unite?->details['diffusion_partielle'] ?? false);
    }

    /**
     * Le dirigeant auquel l'identité du propriétaire a été rapprochée était
     * en fonction depuis moins de trente jours au jour du rapprochement.
     *
     * @param  Collection<int, VerificationSociete>  $lignes
     */
    private function dirigeantRecent(Collection $lignes, ?User $proprietaire): bool
    {
        $rapprochement = $lignes->last(fn (VerificationSociete $ligne): bool => $ligne->type === TypeVerification::RneRepresentants
            && $ligne->estConcluante()
            && $proprietaire !== null
            && (int) $ligne->beneficiaire_id === $proprietaire->getKey());
        $debut = $rapprochement?->details['debut_fonction'] ?? null;

        return is_string($debut) && CarbonImmutable::parse($debut)->greaterThan(CarbonImmutable::instance($rapprochement->created_at)->subDays(self::JOURS_D_UN_DIRIGEANT_RECENT));
    }

    /**
     * La forme saisie n'est pas celle que donne la catégorie juridique lue
     * au répertoire, quand celle-ci en donne une.
     */
    private function formeContradictoire(Societe $societe): bool
    {
        if ($societe->categorie_juridique_insee === null || $societe->forme_juridique === null) {
            return false;
        }

        $attendue = FormeJuridique::depuisCategorieInsee($societe->categorie_juridique_insee);

        return $attendue !== FormeJuridique::Autre && $attendue !== $societe->forme_juridique;
    }

    /**
     * L'adresse d'un magasin réel actif n'est plus celle que le répertoire
     * a donnée de son établissement à son dernier contrôle concluant.
     *
     * @param  Collection<int, VerificationSociete>  $lignes
     * @param  Collection<int, Magasin>  $magasinsReelsActifs
     */
    private function adresseContradictoire(Collection $lignes, Collection $magasinsReelsActifs): bool
    {
        return $magasinsReelsActifs->contains(function (Magasin $magasin) use ($lignes): bool {
            $controle = $lignes->last(fn (VerificationSociete $ligne): bool => $ligne->type === TypeVerification::SireneEtablissement
                && $ligne->estConcluante()
                && (int) $ligne->magasin_id === $magasin->getKey()
                && ($ligne->details['siret'] ?? null) === $magasin->siret);
            $adresseLue = $controle?->details['adresse'] ?? null;

            if (! is_string($adresseLue)) {
                return false;
            }

            $adresse = implode(', ', array_filter([$magasin->adresse, $magasin->complement_adresse, trim("{$magasin->code_postal} {$magasin->ville}")], filled(...)));

            return self::normaliser($adresse) !== self::normaliser($adresseLue);
        });
    }

    /**
     * Le prestataire doute de l'identité du propriétaire, ou elle est
     * vérifiée sans dirigeant rapproché ni mandat, une fois le registre
     * national consulté.
     *
     * @param  Collection<int, VerificationSociete>  $lignes
     */
    private function identiteNonConcluante(Collection $lignes, ?User $proprietaire): bool
    {
        if ($proprietaire === null) {
            return false;
        }

        $duProprietaire = $lignes->filter(fn (VerificationSociete $ligne): bool => (int) $ligne->beneficiaire_id === $proprietaire->getKey());
        $derniereIdentite = $duProprietaire->last(fn (VerificationSociete $ligne): bool => $ligne->type === TypeVerification::Identite && $ligne->resultat !== ResultatVerification::EnAttente);

        if ($derniereIdentite?->resultat === ResultatVerification::AExaminer) {
            return true;
        }

        $verifiee = $duProprietaire->contains(fn (VerificationSociete $ligne): bool => $ligne->type === TypeVerification::Identite && $ligne->estConcluante());
        $rapprochee = $duProprietaire->contains(fn (VerificationSociete $ligne): bool => in_array($ligne->type, [TypeVerification::RneRepresentants, TypeVerification::Mandat], true) && $ligne->estConcluante());
        $dernierRapprochement = $duProprietaire->last(fn (VerificationSociete $ligne): bool => $ligne->type === TypeVerification::RneRepresentants);

        return $verifiee && ! $rapprochee && $dernierRapprochement !== null && $dernierRapprochement->resultat !== ResultatVerification::Indisponible;
    }

    private static function normaliser(string $adresse): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($adresse)))));
    }
}
