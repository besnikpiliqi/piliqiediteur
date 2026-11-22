<?php

namespace Database\Seeders\Semis;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Enums\FormatDuTelechargement;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\OrigineDesAchats;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Services\DocumentsDeLaPeriode;
use App\Domain\Facturation\Services\DocumentTrouve;
use App\Domain\Tenancy\Actions\ChangerLeRoleDeSocieteAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\DemanderLeTelechargementDesDocumentsAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Services\MotsDePasseProvisoires;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\MotDePasseProvisoire;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Models\User;

/**
 * Les comptes de chaque cas de l'équipe (demande de l'éditeur du
 * 15/11/2026, cahier SEED-TOUT § 3.2), chacun par sa vraie action, au nom de
 * qui fait le geste : un observateur par magasin (celle des Halles reçoit
 * les alertes de stock : la propriétaire lui coche « ajuster le stock »),
 * une responsable à qui le
 * propriétaire coche les graphiques et l'ajout des charges, une vendeuse
 * qui voit les factures de toute l'équipe, et, avec leurs ventes d'avant,
 * un vendeur suspendu, une vendeuse suspendue puis réactivée, dont l'accès
 * est ensuite redonné en son absence (un mot de passe provisoire tiré au
 * hasard, dont la première connexion n'achève pas le choix), un vendeur retiré de la société, une vendeuse retirée puis
 * réintégrée (le ZIP de ses ventes qu'elle avait demandé juste avant son
 * retrait échoue quand la file le prend : son compte n'avait plus accès à
 * ses documents), un vendeur promu responsable de son magasin, une
 * administratrice devenue responsable d'un magasin, et l'expert-comptable
 * déclaré dont l'accès est redonné par un nouveau lien. Les changements se font
 * le soir, après les ventes du jour (voir ActiviteDesMagasins), à des jours
 * comptés depuis la fin de l'histoire.
 */
final class ComptesDesCas
{
    /**
     * Les jours, comptés depuis la fin de l'histoire, de chaque changement.
     */
    private const PROMOTION = 90;

    private const ADMINISTRATRICE_DEVENUE_RESPONSABLE = 80;

    private const SUSPENSION = 60;

    private const RETRAIT = 50;

    private const RETRAIT_AVANT_REINTEGRATION = 45;

    private const REINTEGRATION = 40;

    private const SUSPENSION_PUIS_REACTIVATION = 35;

    private const REACTIVATION = 30;

    private const ACCES_REDONNE_EN_ABSENCE = 25;

    private const PREMIERE_CONNEXION_INACHEVEE = 24;

    private const ACCES_REDONNE_L_AVANT_VEILLE = 2;

    private const LIEN_DE_L_EXPERT_COMPTABLE = 20;

    public function __construct(
        private readonly HorlogeDuSemis $horloge,
        private readonly SemisComplet $semis,
        private readonly ComptesSemes $liste,
    ) {}

    /**
     * Les comptes des cas, créés avec l'équipe : un observateur par
     * magasin (la propriétaire coche « ajuster le stock » à celle des
     * Halles), les vendeurs dont le compte changera d'état (le propriétaire
     * de la société de services coche le téléchargement des documents à
     * l'une d'eux), les graphiques et l'ajout des charges que la
     * propriétaire coche à une responsable, et une vendeuse à qui son
     * responsable coche les factures de toute l'équipe.
     */
    public function creer(): void
    {
        if ($this->semis->seme('commerce')) {
            $this->semis->membre('halles', 'Élodie Fabre', RoleMagasin::Observateur);
            $this->dans('halles', fn (): User => app(ModifierMembreAction::class)->handle(
                $this->semis->proprietaire('commerce'),
                $this->semis->compte('Élodie Fabre'),
                RoleMagasin::Observateur,
                [...RoleMagasin::Observateur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::PRODUIT_AJUSTER_STOCK],
            ));
            $this->semis->membre('halles', 'Yanis Morel', RoleMagasin::Vendeur);
        }

        if ($this->semis->seme('services')) {
            $this->semis->membre('conseil', 'Victor Blanchard', RoleMagasin::Observateur);
            $this->semis->membre('conseil', 'Bruno Caron', RoleMagasin::Vendeur);
            $this->semis->membre('conseil', 'Anaïs Lambert', RoleMagasin::Vendeur);
            $this->dans('conseil', fn (): User => app(ModifierMembreAction::class)->handle(
                $this->semis->proprietaire('services'),
                $this->semis->compte('Anaïs Lambert'),
                RoleMagasin::Vendeur,
                [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Services), PermissionRegistry::DOCUMENTS_TELECHARGER],
            ));
        }

        if ($this->semis->seme('client')) {
            $this->semis->membre('epicerie', 'Margaux Perrin', RoleMagasin::Observateur);
            $this->semis->membre('epicerie', 'Léna Roussel', RoleMagasin::Vendeur);
        }

        if ($this->semis->seme('commerce')) {
            $this->dans('halles', fn (): User => app(ModifierMembreAction::class)->handle(
                $this->semis->proprietaire('commerce'),
                $this->semis->compte('Camille Laurent'),
                RoleMagasin::Responsable,
                [PermissionRegistry::GRAPHIQUES_VOIR, PermissionRegistry::CHARGES_AJOUTER],
            ));
            $this->dans('republique', fn (): User => app(ModifierMembreAction::class)->handle(
                $this->semis->compte('Hugo Lefebvre'),
                $this->semis->compte('Sarah Petit'),
                RoleMagasin::Vendeur,
                [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::FACTURE_VIEW_TOUTES],
            ));
        }
    }

    /**
     * Le travail de ce jour de l'histoire : le soir, les ventes des comptes
     * des cas tant qu'ils vendent encore, puis le changement de compte que
     * ce jour porte, quand ce semis sème son magasin.
     */
    public function auJour(int $rang): void
    {
        $avantLaFin = SemisComplet::JOURS - $rang;

        if ($rang % 5 === 0) {
            $this->vendreLeSoir($rang, $avantLaFin);
        }

        $changement = match ($avantLaFin) {
            self::PROMOTION => ['republique', function (): void {
                $lucas = $this->semis->compte('Lucas Moreau (République)');
                $this->dans('republique', fn (): User => app(ModifierMembreAction::class)->handle($this->semis->proprietaire('commerce'), $lucas, RoleMagasin::Responsable));
                $this->liste->changerLeRole($lucas, 'Maison Ferrand République', 'Responsable (vendeur promu)');
            }],
            self::ADMINISTRATRICE_DEVENUE_RESPONSABLE => ['epicerie', function (): void {
                $romane = $this->semis->compte('Romane Gauthier');
                $this->horsDeToutContexte(fn (): User => app(ChangerLeRoleDeSocieteAction::class)->versMembre($this->semis->proprietaire('client'), $romane, RoleMagasin::Responsable, null, $this->semis->magasin('epicerie')->id));
                $this->liste->changerLeRole($romane, 'Épicerie Lemoine', 'Responsable (administratrice devenue membre)');
            }],
            self::SUSPENSION => ['halles', function (): void {
                $yanis = $this->semis->compte('Yanis Morel');
                $this->dans('halles', fn () => app(ChangerStatutCompteAction::class)->suspendre($this->semis->compte('Camille Laurent'), $yanis));
                $this->liste->changerLeRole($yanis, 'Maison Ferrand Halles', 'Vendeur (compte suspendu)');
            }],
            self::RETRAIT => ['conseil', function (): void {
                $bruno = $this->semis->compte('Bruno Caron');
                $this->dans('conseil', fn () => app(ChangerStatutCompteAction::class)->retirer($this->semis->compte('Claire Dubois'), $bruno));
                $this->liste->changerLeRole($bruno, 'Conseil Vasseur Paris', 'Vendeur (compte retiré)');
            }],
            self::RETRAIT_AVANT_REINTEGRATION => ['conseil', function () use ($rang): void {
                $anais = $this->semis->compte('Anaïs Lambert');
                $this->demanderLeZipDeSesVentes($anais, $rang);
                $this->dans('conseil', fn () => app(ChangerStatutCompteAction::class)->retirer($this->semis->compte('Claire Dubois'), $anais));
                FileDAttenteDuSemis::passer(FileDAttenteDuSemis::TELECHARGEMENTS);
            }],
            self::REINTEGRATION => ['conseil', function (): void {
                $anais = $this->semis->compte('Anaïs Lambert');
                $this->horsDeToutContexte(fn () => app(ChangerStatutCompteAction::class)->reintegrer($this->semis->proprietaire('services'), $anais, RoleMagasin::Vendeur));
                $this->liste->changerLeRole($anais, 'Conseil Vasseur Paris', 'Vendeur (retirée puis réintégrée)');
            }],
            self::SUSPENSION_PUIS_REACTIVATION => ['epicerie', fn () => $this->dans('epicerie', fn () => app(ChangerStatutCompteAction::class)->suspendre($this->semis->compte('Nicolas Lemoine'), $this->semis->compte('Léna Roussel')))],
            self::REACTIVATION => ['epicerie', fn () => $this->dans('epicerie', fn () => app(ChangerStatutCompteAction::class)->reactiver($this->semis->compte('Nicolas Lemoine'), $this->semis->compte('Léna Roussel')))],
            self::ACCES_REDONNE_EN_ABSENCE => ['epicerie', function (): void {
                $this->redonnerLAccesEnAbsence('Léna Roussel', 'epicerie', 'Nicolas Lemoine');
            }],
            self::PREMIERE_CONNEXION_INACHEVEE => ['epicerie', function (): void {
                $lena = $this->semis->compte('Léna Roussel');
                $this->dans('epicerie', fn (): bool => app(MotsDePasseProvisoires::class)->consommer($lena));
            }],
            self::ACCES_REDONNE_L_AVANT_VEILLE => [null, function (): void {
                if ($this->semis->semeLeMagasin('halles')) {
                    $this->redonnerLAccesEnAbsence('Inès Garcia', 'halles', 'Camille Laurent');
                }

                if ($this->semis->semeLeMagasin('epicerie')) {
                    $this->redonnerLAccesEnAbsence('Margaux Perrin', 'epicerie', 'Nicolas Lemoine');
                }
            }],
            self::LIEN_DE_L_EXPERT_COMPTABLE => ['conseil', fn () => $this->semis->redonnerLAccesALExpertComptable()],
            default => null,
        };

        if ($changement !== null && ($changement[0] === null || $this->semis->semeLeMagasin($changement[0]))) {
            $this->leSoir($rang, '22:30', $changement[1]);
        }
    }

    /**
     * Le responsable de ce magasin redonne l'accès à ce compte, absent (voir
     * RedonnerLAccesAction::enSonAbsence()) : un mot de passe provisoire
     * tiré au hasard, que la liste des comptes garde pour se connecter une
     * fois depuis un poste reconnu du magasin, tant qu'il vaut.
     */
    private function redonnerLAccesEnAbsence(string $nom, string $magasin, string $responsable): void
    {
        $compte = $this->semis->compte($nom);
        $provisoire = $this->dans($magasin, fn (): ?MotDePasseProvisoire => app(RedonnerLAccesAction::class)->enSonAbsence($this->semis->compte($responsable), $compte, GestesDuMagasin::jeton()));
        $this->liste->donnerUnMotDePasseProvisoire($compte, ValeurRequise::de($provisoire, 'le mot de passe provisoire d\'un accès redonné en l\'absence de la personne')->valeur());
    }

    /**
     * Les comptes retirés que la liste garde, pour voir ce qu'est un compte
     * retiré : il ne se connecte plus.
     *
     * @return list<int>
     */
    public function retiresGardes(): array
    {
        return $this->semis->seme('services') ? [$this->semis->compte('Bruno Caron')->id] : [];
    }

    /**
     * Le soir, un ticket de chaque compte des cas qui vend encore ce
     * jour-là : le vendeur suspendu jusqu'à sa suspension, le vendeur
     * retiré jusqu'à son retrait, la vendeuse retirée puis réintégrée hors
     * de la période où elle est retirée, la vendeuse suspendue puis
     * réactivée hors de sa suspension.
     */
    private function vendreLeSoir(int $rang, int $avantLaFin): void
    {
        $this->horloge->aller($rang, '21:40');

        if ($this->semis->seme('commerce') && $avantLaFin > self::SUSPENSION) {
            $halles = new GestesDuMagasin($this->semis->magasin('halles'));
            $halles->vendreALaCaisse($this->semis->compte('Yanis Morel'), $halles->comptoir(), [['produit_id' => $halles->produit('CARNET-A5')->id, 'quantite' => 1]], ModePaiement::Especes);
        }

        if ($this->semis->seme('services')) {
            $conseil = new GestesDuMagasin($this->semis->magasin('conseil'));

            if ($avantLaFin > self::RETRAIT) {
                $conseil->vendreALaCaisse($this->semis->compte('Bruno Caron'), $conseil->comptoir(), [['service_id' => $conseil->service('Heure de conseil')->id, 'quantite' => 1]], ModePaiement::CarteBancaire);
            }

            if ($avantLaFin > self::RETRAIT_AVANT_REINTEGRATION || $avantLaFin < self::REINTEGRATION) {
                $conseil->vendreALaCaisse($this->semis->compte('Anaïs Lambert'), $conseil->comptoir(), [['service_id' => $conseil->service('Forfait déplacement')->id, 'quantite' => 1]], ModePaiement::CarteBancaire);
            }
        }

        if ($this->semis->seme('client') && ($avantLaFin > self::SUSPENSION_PUIS_REACTIVATION || $avantLaFin < self::REACTIVATION)) {
            $epicerie = new GestesDuMagasin($this->semis->magasin('epicerie'));
            $epicerie->vendreALaCaisse($this->semis->compte('Léna Roussel'), $epicerie->comptoir(), [['produit_id' => $epicerie->produit('MIEL-LAVANDE')->id, 'quantite' => 1]], ModePaiement::CarteBancaire);
        }
    }

    /**
     * La vendeuse, à qui le propriétaire a coché le téléchargement des
     * documents par période, demande le ZIP de ses ventes des trente
     * derniers jours depuis le panneau de son magasin, juste avant d'être
     * retirée de la société.
     */
    private function demanderLeZipDeSesVentes(User $vendeuse, int $rang): void
    {
        $conseil = $this->semis->magasin('conseil');
        $au = $this->horloge->jour($rang);
        $du = Calendrier::ajouterJours($au, -30);
        $types = [TypeDeDocumentTelechargeable::Ventes];

        $this->dans('conseil', function () use ($vendeuse, $conseil, $du, $au, $types): void {
            $cles = array_map(static fn (DocumentTrouve $document): string => $document->cle(), app(DocumentsDeLaPeriode::class)->trouver($vendeuse, [$conseil], $du, $au, $types, OrigineDesAchats::Toutes));
            app(DemanderLeTelechargementDesDocumentsAction::class)->handle($vendeuse, $this->semis->societe('services'), [$conseil], $du, $au, $types, OrigineDesAchats::Toutes, FormatDuTelechargement::Zip, $cles);
        });
    }

    /**
     * @param  callable(): mixed  $geste
     */
    private function leSoir(int $rang, string $heure, callable $geste): void
    {
        $this->horloge->aller($rang, $heure);
        $geste();
    }

    /**
     * @template TResultat
     *
     * @param  callable(): TResultat  $geste
     * @return TResultat
     */
    private function dans(string $magasin, callable $geste): mixed
    {
        return app(TenantContext::class)->runAs($this->semis->magasin($magasin), $geste(...));
    }

    /**
     * @template TResultat
     *
     * @param  callable(): TResultat  $geste
     * @return TResultat
     */
    private function horsDeToutContexte(callable $geste): mixed
    {
        app(TenantContext::class)->clear();

        try {
            return $geste();
        } finally {
            app(TenantContext::class)->clear();
        }
    }
}
