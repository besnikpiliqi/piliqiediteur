<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CleDuModele;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Ce qu'un compte voit sur un écran du tableau de bord (voir
 * PerimetreDesGraphiques, qui seul le construit) : les magasins réels
 * d'une seule société qu'il peut y voir, jamais le magasin de
 * démonstration, et, de chacun, les sources de documents dont il voit
 * tout (voir SourceDesGraphiques). Un graphique ne compte que les magasins
 * où la personne voit toutes ses sources (voir magasinsDe()) ; les autres
 * sortent du graphique, et une phrase grise le dit sans les nommer (voir
 * phraseDesMagasinsNonComptes()).
 *
 * Chaque service des sources (voir VentesDuTableauDeBord et les suivants)
 * reçoit ce périmètre avec les magasins qu'il doit lire, et le garde
 * refuse un magasin dont le périmètre ne permet pas la source (voir
 * exiger()) : aucune lecture hors du magasin courant ne sort de ce que le
 * compte peut voir.
 *
 * Les charges de la société (lot CHARGES) entrent dans la TVA à payer
 * seulement pour qui les voit (voir DroitsSurLesCharges) : celles des
 * magasins du périmètre qu'il voit, et celles de « Toute la société » sur
 * un écran qui montre tous ses magasins, jamais quand le filtre en choisit
 * un, ni dans le panneau d'un magasin (voir chargesComptees()) ; sans
 * elles, la TVA garde le calcul des ventes et des achats, et le dit.
 */
final class Perimetre
{
    /**
     * @param  Collection<int, Magasin>  $magasins  par identifiant
     * @param  array<int, list<SourceDesGraphiques>>  $sourcesParMagasin  par identifiant de magasin
     */
    public function __construct(
        public readonly EcranDuTableauDeBord $ecran,
        public readonly Societe $societe,
        private readonly Collection $magasins,
        private readonly array $sourcesParMagasin,
        public readonly Calendrier $calendrier,
        public readonly ?AccesAuxCharges $charges = null,
        private readonly bool $unSeulMagasinChoisi = false,
    ) {}

    /**
     * Les charges que la TVA de ce périmètre compte : celles de ces
     * magasins (ceux du périmètre que l'accès voit), et celles de « Toute
     * la société » quand l'écran montre tous les magasins du compte ; null
     * quand le compte ne voit pas les charges.
     *
     * @return array{magasins: list<int>, de_la_societe: bool}|null
     */
    public function chargesComptees(): ?array
    {
        if ($this->charges === null) {
            return null;
        }

        return [
            'magasins' => array_values(array_filter(CleDuModele::desModeles($this->magasins), fn (int $magasin): bool => $this->charges->voitLeMagasin($magasin))),
            'de_la_societe' => $this->charges->chargesDeLaSociete && $this->ecran->montrePlusieursMagasins() && ! $this->unSeulMagasinChoisi,
        ];
    }

    /**
     * Le nom d'un magasin dans le filtre et les graphiques, suivi de
     * « (fermé) » ou « (cédé) » : un magasin fermé compte pour les
     * périodes où il a vendu.
     */
    public static function nomDuMagasin(Magasin $magasin): string
    {
        return (string) $magasin->nom.match (true) {
            $magasin->aEteCede() => ' (cédé)',
            $magasin->estFerme() => ' (fermé)',
            default => '',
        };
    }

    /**
     * Ses magasins, par nom.
     *
     * @return Collection<int, Magasin>
     */
    public function magasins(): Collection
    {
        return $this->magasins->sortBy(fn (Magasin $magasin): string => mb_strtolower((string) $magasin->nom).'|'.str_pad((string) CleDuModele::de($magasin), 20, '0', STR_PAD_LEFT))->values();
    }

    /**
     * Le même périmètre réduit à ce magasin, choisi dans le filtre ; tout
     * le périmètre pour null. Un magasin qui n'en est pas, ou qui n'existe
     * pas, est refusé, sans dire pourquoi.
     *
     * @throws AuthorizationException
     */
    public function restreintA(?string $uuid): self
    {
        if ($uuid === null) {
            return $this;
        }

        $magasin = $this->magasins->first(fn (Magasin $candidat): bool => (string) $candidat->uuid === $uuid)
            ?? throw new AuthorizationException(PerimetreDesGraphiques::REFUS);

        return new self($this->ecran, $this->societe, new Collection([CleDuModele::de($magasin) => $magasin]), [CleDuModele::de($magasin) => $this->sourcesParMagasin[CleDuModele::de($magasin)] ?? []], $this->calendrier, $this->charges, true);
    }

    /**
     * Les graphiques du catalogue que le compte voit ici : ceux qui
     * existent dans le type de la société et sur cet écran (la TVA à
     * payer, hors de la franchise en base), et dont un magasin au moins du
     * périmètre montre toutes les sources.
     *
     * @return list<Graphique>
     */
    public function graphiquesVisibles(): array
    {
        return array_values(array_filter(Graphique::cases(), fn (Graphique $graphique): bool => $this->voit($graphique)));
    }

    public function voit(Graphique $graphique): bool
    {
        if ($graphique->exigeDesArticles() && ! $this->societe->aDesArticles()) {
            return false;
        }

        if ($graphique->compareLesMagasins() && ! $this->ecran->montrePlusieursMagasins()) {
            return false;
        }

        // En franchise en base, la société ne collecte ni ne déduit aucune
        // TVA : il n'y a rien à estimer (voir ChiffresDuTableauDeBord).
        if ($graphique === Graphique::TvaAPayerParMois && $this->societe->estEnFranchiseDeTva()) {
            return false;
        }

        return $this->magasinsDe($graphique)->isNotEmpty();
    }

    /**
     * Les magasins que ce graphique compte : ceux où le compte voit toutes
     * ses sources.
     *
     * @return Collection<int, Magasin>
     */
    public function magasinsDe(Graphique $graphique): Collection
    {
        return $this->magasinsAvec(...$graphique->sources($this->societe->aDesArticles()));
    }

    /**
     * Les magasins où le compte voit toutes ces sources, par nom.
     *
     * @return Collection<int, Magasin>
     */
    public function magasinsAvec(SourceDesGraphiques ...$sources): Collection
    {
        return $this->magasins()->filter(fn (Magasin $magasin): bool => self::manquantes(array_values($sources), $this->sourcesParMagasin[CleDuModele::de($magasin)] ?? []) === [])->values();
    }

    /**
     * La phrase grise qui dit combien de magasins du périmètre ne sont pas
     * comptés, et ce que le compte n'y voit pas, sans jamais les nommer :
     * « 1 magasin n'est pas compté : vous n'y voyez pas toutes les
     * ventes. » ; null quand tous le sont.
     */
    public function phraseDesMagasinsNonComptes(SourceDesGraphiques ...$sources): ?string
    {
        $manquantes = [];
        $nonComptes = 0;

        foreach ($this->magasins as $magasin) {
            $absentes = self::manquantes(array_values($sources), $this->sourcesParMagasin[CleDuModele::de($magasin)] ?? []);

            if ($absentes !== []) {
                $nonComptes++;
                $manquantes = [...$manquantes, ...self::manquantes($absentes, $manquantes)];
            }
        }

        if ($nonComptes === 0) {
            return null;
        }

        $quoi = implode(' ni ', array_map(fn (SourceDesGraphiques $source): string => $source->getLabel(), $manquantes));

        return $nonComptes === 1
            ? "1 magasin n'est pas compté : vous n'y voyez pas {$quoi}."
            : "{$nonComptes} magasins ne sont pas comptés : vous n'y voyez pas {$quoi}.";
    }

    /**
     * Le garde des services des sources : chacun de ces magasins est du
     * périmètre et le compte y voit toutes ces sources ; sinon la lecture
     * est refusée, sans rien lire.
     *
     * @param  iterable<Magasin>  $magasins
     *
     * @throws AuthorizationException
     */
    public function exiger(iterable $magasins, SourceDesGraphiques ...$sources): void
    {
        foreach ($magasins as $magasin) {
            $permises = $this->sourcesParMagasin[CleDuModele::de($magasin)] ?? null;

            if ($permises === null || ! $this->magasins->has(CleDuModele::de($magasin)) || self::manquantes(array_values($sources), $permises) !== []) {
                throw new AuthorizationException(PerimetreDesGraphiques::REFUS);
            }
        }
    }

    /**
     * Celles de ces sources qui ne sont pas parmi celles-ci.
     *
     * @param  list<SourceDesGraphiques>  $voulues
     * @param  list<SourceDesGraphiques>  $presentes
     * @return list<SourceDesGraphiques>
     */
    private static function manquantes(array $voulues, array $presentes): array
    {
        return array_values(array_filter($voulues, fn (SourceDesGraphiques $source): bool => ! in_array($source, $presentes, true)));
    }

    /**
     * Le jour de l'écran, « AAAA-MM-JJ », d'où se comptent les mois de la
     * période (voir PeriodeDuTableauDeBord).
     */
    public function aujourdhui(): string
    {
        return $this->calendrier->aujourdhui();
    }
}
