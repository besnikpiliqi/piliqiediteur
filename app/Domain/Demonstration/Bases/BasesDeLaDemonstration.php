<?php

namespace App\Domain\Demonstration\Bases;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Support\Reglage;
use App\Domain\Tenancy\Support\ValeurSimple;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Les bases du site de démonstration (lot DEMO-COPIE, C.2 et C.3) : les
 * bases de travail des modèles en construction, les modèles et les copies,
 * créés, copiés, fermés et supprimés par le seul rôle demo_admin (connexion
 * demo_admin). Chaque nom passe par le motif exact avant tout usage : rien
 * d'autre qu'une base demo_travail_… ou demo_copie_… n'est jamais créé ni
 * supprimé, et le nom d'une copie, tiré au hasard, ne dit rien de la
 * société.
 */
final class BasesDeLaDemonstration
{
    public const MOTIF = '/^demo_(copie|travail)_[a-z0-9_]+$/';

    public const MOTIF_D_UNE_COPIE = '/^demo_copie_[a-z0-9]{26}$/';

    public const MOTIF_D_UNE_BASE_DE_TRAVAIL = '/^demo_travail_[a-z]+_[0-9]{12}$/';

    /**
     * La connexion de demo_admin à une base de la démonstration, par son
     * nom (modèle en construction, copie juste créée).
     */
    public const CONNEXION_D_ADMINISTRATION = 'demo_base_admin';

    /**
     * La limite des connexions d'une copie : un visiteur ne prend jamais
     * toutes les connexions du serveur.
     */
    public const CONNEXIONS_PAR_COPIE = 20;

    /**
     * La stratégie de copie de CREATE DATABASE … TEMPLATE
     * (DEMONSTRATION_STRATEGIE_DE_COPIE) : FILE_COPY copie les fichiers du
     * modèle sans les écrire dans le journal WAL, le plus rapide pour une
     * base gelée ; WAL_LOG les écrit page par page.
     */
    public const STRATEGIES = ['FILE_COPY', 'WAL_LOG'];

    public const MESSAGE_NOM_REFUSE = 'La base « %s » n\'est pas une base de la démonstration (demo_copie_… ou demo_travail_…) : rien n\'a été fait.';

    public function nomDUneBaseDeTravail(TypeDActivite $type, CarbonImmutable $instant): string
    {
        return 'demo_travail_'.$type->value.'_'.Calendrier::deParis()->afficher($instant, 'YmdHi');
    }

    public function nomDUneCopie(): string
    {
        return 'demo_copie_'.Str::lower((string) Str::ulid());
    }

    /**
     * Une base vide, depuis template0, en UTF8 et collation C.UTF-8, comme
     * la base du site réel.
     */
    public function creerUneBaseVide(string $nom): void
    {
        $this->exigerLeMotif($nom, self::MOTIF_D_UNE_BASE_DE_TRAVAIL);
        $this->administration()->unprepared('create database '.IdentifiantSql::de($nom)." template template0 encoding 'UTF8' locale 'C.UTF-8' owner ".IdentifiantSql::de(RolesDeLaDemonstration::roleDeLAdministration()));
    }

    /**
     * Une copie d'un modèle (CREATE DATABASE … TEMPLATE), puis les
     * réglages de la base, que la copie ne reprend pas.
     */
    public function copierUnModele(string $modele, string $copie): void
    {
        $this->exigerLeMotif($modele, self::MOTIF_D_UNE_BASE_DE_TRAVAIL);
        $this->exigerLeMotif($copie, self::MOTIF_D_UNE_COPIE);
        $strategie = ValeurSimple::enTexte(config('demonstration.strategie_de_copie'));

        if (! in_array($strategie, self::STRATEGIES, true)) {
            throw new InvalidArgumentException('Stratégie de copie inconnue : '.$strategie.'.');
        }

        $this->administration()->unprepared('create database '.IdentifiantSql::de($copie).' template '.IdentifiantSql::de($modele)
            .' owner '.IdentifiantSql::de(RolesDeLaDemonstration::roleDeLAdministration())
            .' connection limit '.self::CONNEXIONS_PAR_COPIE.' strategy '.$strategie);

        $connexion = $this->connexionDAdministration($copie);

        try {
            ReglagesDeLaBase::appliquer($connexion);
        } finally {
            $this->fermerLaConnexion();
        }
    }

    /**
     * Le modèle construit devient un modèle : plus aucune connexion, copie
     * permise (C.2, étape 9).
     */
    public function fermerEnModele(string $nom): void
    {
        $this->exigerLeMotif($nom, self::MOTIF_D_UNE_BASE_DE_TRAVAIL);
        $this->administration()->unprepared('alter database '.IdentifiantSql::de($nom).' with allow_connections false is_template true');
    }

    /**
     * La base supprimée, connexions coupées (WITH (FORCE), grâce à
     * pg_signal_backend) ; un modèle redevient d'abord une base ordinaire.
     */
    public function supprimer(string $nom): void
    {
        $this->exigerLeMotif($nom, self::MOTIF);

        if (! $this->existe($nom)) {
            return;
        }

        if (preg_match(self::MOTIF_D_UNE_BASE_DE_TRAVAIL, $nom) === 1) {
            $this->administration()->unprepared('alter database '.IdentifiantSql::de($nom).' with is_template false');
        }

        $this->administration()->unprepared('drop database if exists '.IdentifiantSql::de($nom).' with (force)');
    }

    public function existe(string $nom): bool
    {
        return $this->administration()->table('pg_database')->where('datname', $nom)->exists();
    }

    /**
     * Les bases de la démonstration du serveur, par leur seul nom.
     *
     * @return list<string>
     */
    public function lister(): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $nom): string => ValeurSimple::enTexte($nom), $this->administration()->table('pg_database')->orderBy('datname')->pluck('datname')->all()),
            static fn (string $nom): bool => preg_match(self::MOTIF, $nom) === 1,
        ));
    }

    /**
     * La taille d'une base, en octets.
     */
    public function taille(string $nom): int
    {
        $this->exigerLeMotif($nom, self::MOTIF);

        return ValeurSimple::enEntier($this->administration()->scalar('select pg_database_size(?)', [$nom]));
    }

    /**
     * La connexion de demo_admin à une base de la démonstration (une base
     * de travail ou une copie), refaite à chaque appel.
     */
    public function connexionDAdministration(string $nom): Connection
    {
        $this->exigerLeMotif($nom, self::MOTIF);
        config(['database.connections.'.self::CONNEXION_D_ADMINISTRATION => [
            ...Reglage::tableau('database.connections.demo_admin'),
            'database' => $nom,
        ]]);
        DB::purge(self::CONNEXION_D_ADMINISTRATION);

        return DB::connection(self::CONNEXION_D_ADMINISTRATION);
    }

    public function fermerLaConnexion(): void
    {
        DB::purge(self::CONNEXION_D_ADMINISTRATION);
    }

    /**
     * @throws InvalidArgumentException quand le nom n'est pas du motif attendu
     */
    public function exigerLeMotif(string $nom, string $motif): void
    {
        if (preg_match($motif, $nom) !== 1) {
            throw new InvalidArgumentException(sprintf(self::MESSAGE_NOM_REFUSE, $nom));
        }
    }

    private function administration(): Connection
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);

        return DB::connection('demo_admin');
    }
}
