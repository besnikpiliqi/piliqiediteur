<?php

namespace Tests\Feature\Proprietes;

use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Les tests au hasard (décision de l'éditeur du 01/11/2026, point 5 bis) :
 * un générateur tire des milliers de cas d'une graine écrite dans le test,
 * la même à chaque passage, et chaque cas vérifie les règles qui doivent
 * toujours tenir. Chaque cas se joue dans un point de sauvegarde de la
 * base, annulé ensuite, et le registre fiscal oublie les verrous de sa
 * transaction : les cas ne se voient pas. Un cas qui modifie les droits
 * d'un compte, que le journal des droits inscrit sous le verrou du
 * registre en tête de transaction, se joue sans point de sauvegarde
 * ($isoler à false) : ce qu'il crée reste, et ne change rien aux cas
 * suivants. Un cas qui échoue est
 * réduit au plus petit cas qui échoue encore (une étape de moins, une
 * quantité plus petite...), que le message d'échec écrit en entier, avec
 * sa graine : il devient alors un test nommé, et le défaut se corrige.
 */
trait VerifieDesProprietes
{
    /**
     * @template TCas
     *
     * @param  int  $graine  écrite dans le test
     * @param  Closure(Tirage): TCas  $generer
     * @param  Closure(TCas): void  $verifier  qui lève une exception, ou échoue, quand une règle ne tient pas
     * @param  Closure(TCas): iterable<TCas>  $reduire  les cas plus petits que celui-ci, du plus petit au plus grand
     */
    protected function pourChaqueCas(int $graine, int $nombre, Closure $generer, Closure $verifier, Closure $reduire, bool $isoler = true): void
    {
        for ($rang = 0; $rang < $nombre; $rang++) {
            $tirage = new Tirage($graine + $rang);
            $cas = $generer($tirage);
            $echec = $this->echecDuCas($cas, $verifier, $isoler);

            if ($echec === null) {
                continue;
            }

            [$minimal, $echecMinimal] = $this->reduireLeCas($cas, $echec, $verifier, $reduire, $isoler);

            $this->fail(sprintf(
                "Le cas n° %d (graine %d) ne tient pas une règle.\nPlus petit cas qui échoue :\n%s\nÉchec : %s\nÀ garder comme test nommé, puis à corriger.",
                $rang,
                $tirage->graine,
                var_export($minimal, true),
                $echecMinimal->getMessage(),
            ));
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Le cas joué, dans un point de sauvegarde annulé ensuite quand il faut
     * l'isoler : l'échec qu'il lève, ou null.
     *
     * @template TCas
     *
     * @param  TCas  $cas
     * @param  Closure(TCas): void  $verifier
     */
    private function echecDuCas(mixed $cas, Closure $verifier, bool $isoler): ?Throwable
    {
        if ($isoler) {
            DB::beginTransaction();
        }

        try {
            $verifier($cas);

            return null;
        } catch (Throwable $echec) {
            return $echec;
        } finally {
            if ($isoler) {
                DB::rollBack();
                app()->forgetInstance(RegistreFiscal::class);
            }
        }
    }

    /**
     * Le plus petit cas qui échoue encore : tant qu'un cas plus petit
     * échoue, il remplace le cas courant.
     *
     * @template TCas
     *
     * @param  TCas  $cas
     * @param  Closure(TCas): void  $verifier
     * @param  Closure(TCas): iterable<TCas>  $reduire
     * @return array{0: TCas, 1: Throwable}
     */
    private function reduireLeCas(mixed $cas, Throwable $echec, Closure $verifier, Closure $reduire, bool $isoler): array
    {
        do {
            $reduit = false;

            foreach ($reduire($cas) as $plusPetit) {
                $echecDuPlusPetit = $this->echecDuCas($plusPetit, $verifier, $isoler);

                if ($echecDuPlusPetit !== null) {
                    [$cas, $echec, $reduit] = [$plusPetit, $echecDuPlusPetit, true];

                    break;
                }
            }
        } while ($reduit);

        return [$cas, $echec];
    }

    /**
     * Les listes plus petites d'une liste d'étapes : sans l'une d'elles,
     * puis chacune réduite par $reduireUneEtape.
     *
     * @template TEtape
     *
     * @param  list<TEtape>  $etapes
     * @param  Closure(TEtape): iterable<TEtape>  $reduireUneEtape
     * @return iterable<list<TEtape>>
     */
    protected static function etapesReduites(array $etapes, Closure $reduireUneEtape): iterable
    {
        foreach (array_keys($etapes) as $rang) {
            yield array_values(array_diff_key($etapes, [$rang => true]));
        }

        foreach ($etapes as $rang => $etape) {
            foreach ($reduireUneEtape($etape) as $plusPetite) {
                $reduites = $etapes;
                $reduites[$rang] = $plusPetite;

                yield $reduites;
            }
        }
    }
}
