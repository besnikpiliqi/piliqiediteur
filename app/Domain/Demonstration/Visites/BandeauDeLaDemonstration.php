<?php

namespace App\Domain\Demonstration\Visites;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Models\CompteDuBandeau;
use App\Domain\Demonstration\Models\CourrielDeLaDemonstration;
use App\Domain\Demonstration\Registre\Models\Temoin;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\VersionServie;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Models\User;

/**
 * Ce que montrent le bandeau « Vous êtes : » en haut de chaque page d'une
 * copie et la page « Choisissez qui vous êtes » (lot DEMO-COPIE, C.8) :
 * la société de la copie et ses seuls comptes, rangés par groupe (« La
 * société », chaque magasin, « Le comptable » ; « Le cabinet » dans la
 * copie d'un cabinet), dans l'ordre du menu, chacun avec son rôle et son
 * groupe tels qu'ils sont maintenant (voir RoleAuBandeau : une nomination
 * ou une transmission de la propriété se lit aussitôt), un compte
 * suspendu grisé, un compte retiré absent ; le mot de passe de démonstration du modèle, que
 * redemandent les gestes qui le redemandent ; le nombre des e-mails qui
 * seraient partis de la société ; la date de fin, le repère, la date de
 * création et la version ; et, tant que la copie a dépassé sa place, que
 * seule la consultation reste (C.16). Jamais le nom d'une autre société, jamais un
 * autre type d'activité.
 */
final readonly class BandeauDeLaDemonstration
{
    /**
     * @param  array<string, list<array{id: int, nom: string, role: string, suspendu: bool, proprietaire: bool}>>  $groupes
     */
    public function __construct(
        public string $societe,
        public bool $estUnCabinet,
        public string $repere,
        public string $creeeLe,
        public string $fin,
        public string $motDePasse,
        public int $courriels,
        public string $version,
        public ?string $message,
        public bool $enLectureSeule,
        public array $groupes,
        public ?int $compteChoisi,
    ) {}

    /**
     * Le compte du propriétaire actuel de la société, s'il est au bandeau
     * (« Commencer en tant que propriétaire ») : après une transmission de
     * la propriété, le nouveau propriétaire, jamais l'ancien.
     *
     * @return array{id: int, nom: string, role: string, suspendu: bool, proprietaire: bool}|null
     */
    public function proprietaire(): ?array
    {
        foreach ($this->groupes as $comptes) {
            foreach ($comptes as $compte) {
                if ($compte['proprietaire']) {
                    return $compte;
                }
            }
        }

        return null;
    }

    /**
     * Le nom du compte connecté, s'il est au bandeau.
     */
    public function nomDuCompteChoisi(): ?string
    {
        foreach ($this->groupes as $comptes) {
            foreach ($comptes as $compte) {
                if ($compte['id'] === $this->compteChoisi) {
                    return $compte['nom'];
                }
            }
        }

        return null;
    }

    /**
     * Le bandeau de la visite en cours, ou null hors d'une copie. Le
     * message de la copie (remise à zéro, mise à jour, copie refaite) ne
     * s'y lit qu'une fois par témoin (E.4) : le premier bandeau ou la
     * première page « Choisissez qui vous êtes » qui le montre le marque vu.
     */
    public static function enCours(?User $compte): ?self
    {
        if (! SiteDePiliqi::estLaDemonstration()) {
            return null;
        }

        $visite = Visite::enCours();
        $societe = $visite === null ? null : SocieteDeLaCopie::trouver();

        if ($visite === null || $societe === null) {
            return null;
        }

        $groupes = [];
        $copie = $visite->copie;
        $estUnCabinet = $copie->type === TypeDActivite::Cabinet;
        $lignes = CompteDuBandeau::query()->withoutGlobalScopes()
            ->where('societe_id', $societe->getKey())
            ->with(['compte:id,societe_id,name,statut,role_societe', 'compte.affectations.magasin:id,nom'])
            ->orderBy('ordre')
            ->get();

        foreach ($lignes as $ligne) {
            $titulaire = $ligne->compte;

            if ($titulaire === null || $titulaire->estRetire()) {
                continue;
            }

            $role = RoleAuBandeau::de($titulaire, $estUnCabinet);
            $groupes[$role->groupe][] = [
                'id' => (int) $titulaire->getKey(),
                'nom' => $titulaire->name,
                'role' => $role->cle === $ligne->role ? $ligne->libelle_du_role : $role->libelle,
                'suspendu' => $titulaire->estSuspendu(),
                'proprietaire' => $role->estProprietaire,
            ];
        }

        $paris = Calendrier::deParis();
        $message = self::messageAMontrer($visite);

        return new self(
            societe: $societe->getFilamentName(),
            estUnCabinet: $estUnCabinet,
            repere: $copie->repere,
            creeeLe: $paris->afficher($copie->creee_le, 'd/m/Y'),
            fin: $paris->afficher($copie->fin, 'd/m/Y'),
            motDePasse: (string) $copie->modele?->mot_de_passe,
            courriels: CourrielDeLaDemonstration::query()->withoutGlobalScopes()->where('societe_id', $societe->getKey())->count(),
            version: VersionServie::courante(),
            message: $message,
            enLectureSeule: $copie->etat === EtatDUneCopie::EnLectureSeule,
            groupes: $groupes,
            compteChoisi: $compte?->getKey() === null ? null : (int) $compte->getKey(),
        );
    }

    /**
     * Le message de la copie que ce témoin n'a pas encore vu, marqué vu
     * aussitôt ; null quand la copie n'en porte pas ou qu'il l'a déjà vu.
     */
    private static function messageAMontrer(Visite $visite): ?string
    {
        $message = $visite->copie->message;

        if ($message === null || $visite->temoin->message_vu === $message) {
            return null;
        }

        Temoin::query()->whereKey($visite->temoin->id)->update(['message_vu' => $message]);
        $visite->temoin->message_vu = $message;

        return $message;
    }
}
