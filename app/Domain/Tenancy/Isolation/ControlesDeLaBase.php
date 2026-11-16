<?php

namespace App\Domain\Tenancy\Isolation;

use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Les contrôles de l'isolation par la base (couche « SQL » de
 * VerificationDeLIsolation) : des requêtes SQL directes, sans modèle ni scope,
 * écrites depuis le schéma réel (voir SchemaDeLaBase), qui comparent la société
 * et le magasin de chaque ligne à ceux des lignes qu'elle référence, et le
 * catalogue de PostgreSQL, qui dit si la base porte bien ses protections.
 * Chaque requête ne rend que l'identifiant interne des lignes fautives et des
 * identifiants de sociétés ou de magasins, jamais un nom, une adresse ni un
 * montant ; aucune n'écrit.
 */
final class ControlesDeLaBase
{
    public const COUCHE = 'SQL';

    public function __construct(
        private readonly ConnectionInterface $base,
        private readonly SchemaDeLaBase $schema,
    ) {}

    /**
     * @return list<ResultatDUnControle>
     */
    public function tous(): array
    {
        return [
            $this->tablesConnues(),
            $this->magasinsDansLeurSociete(),
            $this->referencesEntreLignes(),
            $this->comptesCites(),
            $this->referencesPolymorphes(),
            $this->ventesEtEncaissements(),
            $this->chaines(),
            $this->clesComposees(),
            $this->declencheurs(),
        ];
    }

    /**
     * Chaque table du schéma a sa règle (voir ReglesDIsolation::PORTEES), et
     * ses colonnes de portée sont celles de sa règle : magasin_id pour une
     * table d'un magasin, societe_id pour une table d'une société, ni l'une
     * ni l'autre pour une table dérivée, globale ou technique.
     */
    public function tablesConnues(): ResultatDUnControle
    {
        $resultat = new ResultatDUnControle('Tables connues', self::COUCHE);

        foreach ($this->schema->tables() as $table) {
            $resultat->compter(1);
            $portee = ReglesDIsolation::porteeDe($table);

            if ($portee === null) {
                $resultat->signaler($table, '—', '—', 'une règle d\'isolation', 'aucune règle', ReglesDIsolation::REGLE_TABLES);

                continue;
            }

            $magasin = $this->schema->aLaColonne($table, 'magasin_id');
            $societe = $this->schema->aLaColonne($table, 'societe_id');
            $attendu = match ($portee) {
                ReglesDIsolation::MAGASIN => $magasin,
                ReglesDIsolation::SOCIETE => $societe,
                ReglesDIsolation::RACINE => $table === 'societes',
                default => ! $magasin && ! $societe,
            };

            if (! $attendu) {
                $resultat->signaler($table, '—', $portee === ReglesDIsolation::MAGASIN ? 'magasin_id' : 'societe_id', "les colonnes d'une table de portée « {$portee} »", ($societe ? 'societe_id ' : '').($magasin ? 'magasin_id' : '') ?: 'aucune colonne de portée', ReglesDIsolation::REGLE_TABLES);
            }
        }

        foreach (array_keys(ReglesDIsolation::PORTEES) as $table) {
            if (! in_array($table, $this->schema->tables(), true)) {
                $resultat->signaler($table, '—', '—', 'une table du schéma', 'une règle pour une table absente', ReglesDIsolation::REGLE_TABLES);
            }
        }

        return $resultat;
    }

    /**
     * Une ligne d'une société qui vise un de ses magasins le vise dans sa
     * société.
     */
    public function magasinsDansLeurSociete(): ResultatDUnControle
    {
        $resultat = new ResultatDUnControle('Magasin dans la société de la ligne', self::COUCHE);

        foreach ($this->tablesDePortee(ReglesDIsolation::SOCIETE) as $table) {
            if ($table === 'magasins' || ! $this->schema->aLaColonne($table, 'magasin_id')) {
                continue;
            }

            $identifiant = $this->identifiant($table, 't');
            $resultat->compter($this->compter("select count(*) as nombre from {$table} t where t.magasin_id is not null"));

            foreach ($this->base->select("select {$identifiant} as identifiant, t.societe_id as attendu, m.societe_id as trouve from {$table} t join magasins m on m.id = t.magasin_id where m.societe_id <> t.societe_id") as $ligne) {
                $champs = SchemaDeLaBase::champsDe($ligne);
                $resultat->signaler($table, self::texteDe($champs, 'identifiant'), 'magasin_id', 'un magasin de la société n° '.self::texteDe($champs, 'attendu'), 'un magasin de la société n° '.self::texteDe($champs, 'trouve'), ReglesDIsolation::REGLE_SOCIETES);
            }
        }

        return $resultat;
    }

    /**
     * Toute référence d'une ligne vers une autre ligne (clé étrangère) porte
     * la même société, et le même magasin quand les deux lignes sont d'un
     * magasin, sauf les références voulues vers une autre société (le cabinet
     * d'un lien, le lien d'un client confié) ; les comptes cités se
     * contrôlent à part (voir comptesCites()).
     */
    public function referencesEntreLignes(): ResultatDUnControle
    {
        $resultat = new ResultatDUnControle('Références entre lignes', self::COUCHE);

        foreach ($this->schema->clesEtrangeres() as $cle) {
            $table = $cle['table'];
            $visee = $cle['visee'];
            $portee = ReglesDIsolation::porteeDe($table);
            $porteeVisee = ReglesDIsolation::porteeDe($visee);

            if (! in_array($portee, [ReglesDIsolation::MAGASIN, ReglesDIsolation::SOCIETE], true) || $visee === 'users'
                || ! in_array($porteeVisee, [ReglesDIsolation::MAGASIN, ReglesDIsolation::SOCIETE, ReglesDIsolation::RACINE], true)) {
                continue;
            }

            $colonnes = implode(', ', $cle['colonnes']);
            $jointure = implode(' and ', array_map(static fn (string $colonne, string $visee): string => "r.{$visee} = t.{$colonne}", $cle['colonnes'], $cle['colonnes_visees']));
            $nonNulles = implode(' and ', array_map(static fn (string $colonne): string => "t.{$colonne} is not null", $cle['colonnes']));
            $resultat->compter($this->compter("select count(*) as nombre from {$table} t where {$nonNulles}"));

            if ($visee === 'societes') {
                $this->controlerLaSocieteVisee($resultat, $table, $cle['colonnes'], $jointure);

                continue;
            }

            $societeDeLaLigne = $this->societeDe($table, 't');
            $societeVisee = ($autre = ReglesDIsolation::SOCIETE_PAR_UNE_AUTRE_COLONNE["{$table}.".implode(',', $cle['colonnes'])] ?? null) !== null ? "r.{$autre}" : $this->societeDe($visee, 'r');
            $magasinDeLaLigne = $this->magasinDe($table, 't');
            $magasinVise = $this->magasinDe($visee, 'r');
            $difference = "({$societeDeLaLigne}) is distinct from ({$societeVisee})";

            if ($magasinDeLaLigne !== null && $magasinVise !== null) {
                $difference .= " or (({$magasinDeLaLigne}) is not null and ({$magasinVise}) is not null and ({$magasinDeLaLigne}) <> ({$magasinVise}))";
            }

            $identifiant = $this->identifiant($table, 't');
            $magasins = $magasinDeLaLigne !== null && $magasinVise !== null ? ", ({$magasinDeLaLigne})::text as magasin_attendu, ({$magasinVise})::text as magasin_trouve" : ', null as magasin_attendu, null as magasin_trouve';

            foreach ($this->base->select("select {$identifiant} as identifiant, ({$societeDeLaLigne})::text as attendu, ({$societeVisee})::text as trouve{$magasins} from {$table} t join {$visee} r on {$jointure} where {$difference}") as $ligne) {
                $champs = SchemaDeLaBase::champsDe($ligne);
                $resultat->signaler(
                    $table,
                    self::texteDe($champs, 'identifiant'),
                    $colonnes,
                    self::portee($champs, 'attendu', 'magasin_attendu'),
                    self::portee($champs, 'trouve', 'magasin_trouve')." (ligne de {$visee})",
                    $porteeVisee === ReglesDIsolation::MAGASIN ? ReglesDIsolation::REGLE_MAGASINS : ReglesDIsolation::REGLE_SOCIETES,
                );
            }
        }

        return $resultat;
    }

    /**
     * Chaque compte cité par une ligne (auteur, créateur, destinataire…) est
     * un compte de la société de la ligne, ou un compte d'un cabinet lié à
     * cette société (ou, dans une ligne d'un cabinet, un compte d'un de ses
     * clients) ; dans une ligne d'un magasin, un compte de membre (responsable,
     * vendeur, observateur) n'est jamais celui d'un autre magasin.
     */
    public function comptesCites(): ResultatDUnControle
    {
        $resultat = new ResultatDUnControle('Comptes cités', self::COUCHE);

        foreach ($this->schema->clesEtrangeres() as $cle) {
            $table = $cle['table'];
            $portee = ReglesDIsolation::porteeDe($table);
            $rang = array_search('id', $cle['colonnes_visees'], true);

            if ($cle['visee'] !== 'users' || $rang === false || ! in_array($portee, [ReglesDIsolation::MAGASIN, ReglesDIsolation::SOCIETE], true)) {
                continue;
            }

            $colonne = $cle['colonnes'][$rang];
            $societe = $this->societeDe($table, 't');
            $magasin = $this->magasinDe($table, 't');
            $identifiant = $this->identifiant($table, 't');
            $resultat->compter($this->compter("select count(*) as nombre from {$table} t where t.{$colonne} is not null"));
            $lie = "exists (select 1 from liens_cabinet l where (l.cabinet_id = u.societe_id and l.societe_id = ({$societe})) or (l.societe_id = u.societe_id and l.cabinet_id = ({$societe})))";

            foreach ($this->base->select("select {$identifiant} as identifiant, ({$societe})::text as attendu, u.societe_id::text as trouve from {$table} t join users u on u.id = t.{$colonne} where u.societe_id is distinct from ({$societe}) and not {$lie}") as $ligne) {
                $champs = SchemaDeLaBase::champsDe($ligne);
                $resultat->signaler($table, self::texteDe($champs, 'identifiant'), $colonne, 'un compte de la société n° '.self::texteDe($champs, 'attendu').' ou d\'un cabinet lié', 'un compte de la société n° '.self::texteDe($champs, 'trouve'), ReglesDIsolation::REGLE_COMPTES);
            }

            if ($magasin === null) {
                continue;
            }

            foreach ($this->base->select("select {$identifiant} as identifiant, ({$magasin})::text as attendu, a.magasin_id::text as trouve from {$table} t join affectations a on a.user_id = t.{$colonne} and a.role is not null where ({$magasin}) is not null and a.magasin_id <> ({$magasin})") as $ligne) {
                $champs = SchemaDeLaBase::champsDe($ligne);
                $resultat->signaler($table, self::texteDe($champs, 'identifiant'), $colonne, 'un membre du magasin n° '.self::texteDe($champs, 'attendu'), 'un membre du magasin n° '.self::texteDe($champs, 'trouve'), ReglesDIsolation::REGLE_COMPTES);
            }
        }

        return $resultat;
    }

    /**
     * Les références sans clé étrangère, qui visent une ligne par son type
     * et son identifiant (documents des enregistrements fiscaux, des envois,
     * des transmissions et du grand livre, résultats des demandes répétées,
     * cibles du journal d'audit, dernier mouvement d'un produit), portent le
     * même magasin, ou la même société (une cible du journal d'audit peut
     * être d'un cabinet lié) ; chaque notification est d'une société de son
     * compte, ou d'un client de son cabinet, et chaque magasin d'un
     * téléchargement est de la société du téléchargement.
     */
    public function referencesPolymorphes(): ResultatDUnControle
    {
        $resultat = new ResultatDUnControle('Références polymorphes', self::COUCHE);

        foreach ([
            ['actions_traitees', 'resultat_type', 'resultat_id', false],
            ['actions_traitees_de_la_societe', 'resultat_type', 'resultat_id', false],
            ['enregistrements_fiscaux', 'document_type', 'document_id', false],
            ['envois_documents', 'document_type', 'document_id', false],
            ['mouvements_stock', 'source_type', 'source_id', false],
            ['transmissions_pdp', 'transmissible_type', 'transmissible_id', false],
            ['journal_audit', 'cible_type', 'cible_id', true],
        ] as [$table, $colonneDuType, $colonneDeLId, $lienPermis]) {
            $this->controlerUneReferencePolymorphe($resultat, $table, $colonneDuType, $colonneDeLId, $lienPermis);
        }

        $resultat->compter($this->compter('select count(*) as nombre from produits t where t.dernier_mouvement_stock_id is not null'));

        foreach ($this->base->select('select t.id::text as identifiant, t.magasin_id::text as attendu, coalesce(m.magasin_id::text, \'aucun\') as trouve from produits t left join mouvements_stock m on m.id = t.dernier_mouvement_stock_id where t.dernier_mouvement_stock_id is not null and m.magasin_id is distinct from t.magasin_id') as $ligne) {
            $champs = SchemaDeLaBase::champsDe($ligne);
            $resultat->signaler('produits', self::texteDe($champs, 'identifiant'), 'dernier_mouvement_stock_id', 'un mouvement du magasin n° '.self::texteDe($champs, 'attendu'), 'un mouvement du magasin n° '.self::texteDe($champs, 'trouve'), ReglesDIsolation::REGLE_MAGASINS);
        }

        $utilisateur = $this->nomPolymorphe(User::class);
        $resultat->compter($this->compter('select count(*) as nombre from notifications'));

        foreach ($this->base->select(<<<'SQL'
            select n.id::text as identifiant, coalesce(u.societe_id::text, 'aucun compte') as attendu,
                   coalesce(n.data::jsonb ->> 'societe_id', 'aucune') as trouve, n.notifiable_type as type
              from notifications n
              left join users u on u.id = n.notifiable_id and n.notifiable_type = ?
             where u.id is null
                or ((n.data::jsonb ->> 'societe_id') is not null
                    and (n.data::jsonb ->> 'societe_id')::bigint <> u.societe_id
                    and not exists (select 1 from liens_cabinet l where l.cabinet_id = u.societe_id and l.societe_id = (n.data::jsonb ->> 'societe_id')::bigint))
                or ((n.data::jsonb ->> 'magasin_id') is not null
                    and not exists (select 1 from magasins m where m.id = (n.data::jsonb ->> 'magasin_id')::bigint
                                       and m.societe_id = coalesce((n.data::jsonb ->> 'societe_id')::bigint, u.societe_id)))
            SQL, [$utilisateur]) as $ligne) {
            $champs = SchemaDeLaBase::champsDe($ligne);
            $resultat->signaler('notifications', self::texteDe($champs, 'identifiant'), 'data.societe_id, data.magasin_id', 'la société n° '.self::texteDe($champs, 'attendu').' de son compte, ou un client lié', 'la société n° '.self::texteDe($champs, 'trouve').' (ou un magasin d\'une autre société)', ReglesDIsolation::REGLE_SOCIETES);
        }

        $resultat->compter($this->compter('select count(*) as nombre from telechargements_documents'));

        foreach ($this->base->select(<<<'SQL'
            select t.id::text as identifiant, t.societe_id::text as attendu, coalesce(m.societe_id::text, 'aucune') as trouve, e.valeur as magasin
              from telechargements_documents t
              cross join lateral jsonb_array_elements_text(t.magasins::jsonb) as e(valeur)
              left join magasins m on m.id = e.valeur::bigint
             where m.societe_id is distinct from t.societe_id
            SQL) as $ligne) {
            $champs = SchemaDeLaBase::champsDe($ligne);
            $resultat->signaler('telechargements_documents', self::texteDe($champs, 'identifiant'), 'magasins', 'un magasin de la société n° '.self::texteDe($champs, 'attendu'), 'le magasin n° '.self::texteDe($champs, 'magasin').' de la société n° '.self::texteDe($champs, 'trouve'), ReglesDIsolation::REGLE_SOCIETES);
        }

        return $resultat;
    }

    /**
     * Chaque vente émise (ticket ou facture) et chaque encaissement (paiement
     * qui n'est pas une contre-passation) est au nom d'un compte de la
     * société du magasin qui y vend : jamais le propriétaire, jamais un
     * compte d'un cabinet ni un expert-comptable de la société ; un membre
     * l'est de ce magasin (son affectation, ou le magasin de son
     * identifiant, que garde un compte retiré, qui a perdu ses
     * affectations), un administrateur l'a dans sa portée.
     */
    public function ventesEtEncaissements(): ResultatDUnControle
    {
        $resultat = new ResultatDUnControle('Ventes et encaissements au nom d\'un compte autorisé', self::COUCHE);
        $administrateur = RoleSociete::Administrateur->value;
        $membre = RoleSociete::Membre->value;
        $permis = <<<SQL
            u.societe_id = m.societe_id
            and (
                (u.role_societe = '{$membre}' and (exists (select 1 from affectations a where a.user_id = u.id and a.role is not null and a.magasin_id = m.id)
                                                       or exists (select 1 from identifiants i where i.user_id = u.id and i.magasin_id = m.id)))
                or (u.role_societe = '{$administrateur}' and exists (select 1 from affectations a where a.user_id = u.id and a.role is null and (a.magasin_id is null or a.magasin_id = m.id)))
            )
            SQL;

        foreach ([
            ['factures', 't.numero is not null'],
            ['paiements', 't.contre_passe_paiement_id is null'],
        ] as [$table, $condition]) {
            $resultat->compter($this->compter("select count(*) as nombre from {$table} t where {$condition}"));

            foreach ($this->base->select("select t.id::text as identifiant, m.id::text as attendu, coalesce(u.role_societe, 'aucun compte') as trouve from {$table} t join magasins m on m.id = t.magasin_id left join users u on u.id = t.cree_par where {$condition} and (u.id is null or not ({$permis}))") as $ligne) {
                $champs = SchemaDeLaBase::champsDe($ligne);
                $resultat->signaler($table, self::texteDe($champs, 'identifiant'), 'cree_par', 'un compte autorisé à vendre dans le magasin n° '.self::texteDe($champs, 'attendu'), 'un compte de rôle « '.self::texteDe($champs, 'trouve').' »', ReglesDIsolation::REGLE_VENTES);
            }
        }

        return $resultat;
    }

    /**
     * Chaque maillon du registre fiscal et du journal des événements suit le
     * maillon précédent de sa chaîne dans son magasin, signé du même magasin ;
     * chaque tête du registre et du journal d'audit désigne le dernier maillon
     * de sa chaîne, dans son magasin ou sa société.
     */
    public function chaines(): ResultatDUnControle
    {
        $resultat = new ResultatDUnControle('Chaînes du registre et des journaux', self::COUCHE);
        $resultat->compter($this->compter('select count(*) as nombre from enregistrements_fiscaux'));

        foreach ($this->base->select(<<<'SQL'
            select e.id::text as identifiant, e.magasin_id::text as magasin, e.chaine as chaine
              from enregistrements_fiscaux e
             where e.sequence > (select min(f.sequence) from enregistrements_fiscaux f where f.magasin_id = e.magasin_id and f.chaine = e.chaine)
               and not exists (select 1 from enregistrements_fiscaux p
                                where p.magasin_id = e.magasin_id and p.chaine = e.chaine
                                  and p.sequence = e.sequence - 1 and p.signature = e.signature_precedente)
            SQL) as $ligne) {
            $champs = SchemaDeLaBase::champsDe($ligne);
            $resultat->signaler('enregistrements_fiscaux', self::texteDe($champs, 'identifiant'), 'signature_precedente', 'la signature du maillon précédent de la chaîne « '.self::texteDe($champs, 'chaine').' » du magasin n° '.self::texteDe($champs, 'magasin'), 'aucun maillon précédent de ce magasin', ReglesDIsolation::REGLE_CHAINES);
        }

        $resultat->compter($this->compter('select count(*) as nombre from tetes_registre_fiscal'));

        foreach ($this->base->select(<<<'SQL'
            select t.id::text as identifiant, t.magasin_id::text as magasin, c.key as chaine
              from tetes_registre_fiscal t
              cross join lateral jsonb_each(coalesce(t.chaines::jsonb, '{}'::jsonb)) as c(key, value)
             where (c.value ->> 'sequence')::bigint > 0
               and not exists (select 1 from enregistrements_fiscaux e
                                where e.magasin_id = t.magasin_id and e.chaine = c.key
                                  and e.sequence = (c.value ->> 'sequence')::bigint and e.signature = c.value ->> 'signature')
            SQL) as $ligne) {
            $champs = SchemaDeLaBase::champsDe($ligne);
            $resultat->signaler('tetes_registre_fiscal', self::texteDe($champs, 'identifiant'), 'chaines', 'le dernier maillon de la chaîne « '.self::texteDe($champs, 'chaine').' » du magasin n° '.self::texteDe($champs, 'magasin'), 'aucun maillon de ce magasin', ReglesDIsolation::REGLE_CHAINES);
        }

        $resultat->compter($this->compter('select count(*) as nombre from tetes_journal_audit'));

        foreach ($this->base->select(<<<'SQL'
            select t.id::text as identifiant, t.societe_id::text as societe, coalesce(t.magasin_id::text, 'la société') as magasin, t.categorie as categorie
              from tetes_journal_audit t
             where not (t.reprise_sequence is not distinct from t.sequence and t.reprise_empreinte is not distinct from t.empreinte)
               and not exists (select 1 from journal_audit j
                                where j.societe_id = t.societe_id and j.magasin_id is not distinct from t.magasin_id
                                  and j.categorie = t.categorie and j.sequence = t.sequence and j.empreinte = t.empreinte)
            SQL) as $ligne) {
            $champs = SchemaDeLaBase::champsDe($ligne);
            $resultat->signaler('tetes_journal_audit', self::texteDe($champs, 'identifiant'), 'sequence, empreinte', 'la dernière entrée « '.self::texteDe($champs, 'categorie').' » de la société n° '.self::texteDe($champs, 'societe').' ('.self::texteDe($champs, 'magasin').')', 'aucune entrée de cette chaîne', ReglesDIsolation::REGLE_CHAINES);
        }

        return $resultat;
    }

    /**
     * Toute référence d'une table d'un magasin vers une autre table d'un
     * magasin est une clé composée (magasin_id, …) vers (magasin_id, id), et
     * toute référence d'une table d'une société vers un magasin, une clé
     * composée (societe_id, magasin_id) vers (societe_id, id).
     */
    public function clesComposees(): ResultatDUnControle
    {
        $resultat = new ResultatDUnControle('Clés composées', self::COUCHE);

        foreach ($this->schema->clesEtrangeres() as $cle) {
            $table = $cle['table'];
            $visee = $cle['visee'];
            $portee = ReglesDIsolation::porteeDe($table);
            $associees = array_combine($cle['colonnes'], $cle['colonnes_visees']);

            if ($portee === ReglesDIsolation::MAGASIN && ReglesDIsolation::porteeDe($visee) === ReglesDIsolation::MAGASIN) {
                $resultat->compter(1);

                if (($associees['magasin_id'] ?? null) !== 'magasin_id') {
                    $resultat->signaler($table, $cle['nom'], implode(', ', $cle['colonnes']), "(magasin_id, …) vers {$visee} (magasin_id, id)", '('.implode(', ', $cle['colonnes']).") vers {$visee} (".implode(', ', $cle['colonnes_visees']).')', ReglesDIsolation::REGLE_CLES_COMPOSEES);
                }
            }

            if ($portee === ReglesDIsolation::SOCIETE && $visee === 'magasins' && $table !== 'magasins') {
                $resultat->compter(1);

                if (($associees['societe_id'] ?? null) !== 'societe_id' || ($associees['magasin_id'] ?? null) !== 'id') {
                    $resultat->signaler($table, $cle['nom'], implode(', ', $cle['colonnes']), '(societe_id, magasin_id) vers magasins (societe_id, id)', '('.implode(', ', $cle['colonnes']).') vers magasins ('.implode(', ', $cle['colonnes_visees']).')', ReglesDIsolation::REGLE_CLES_COMPOSEES);
                }
            }
        }

        return $resultat;
    }

    /**
     * La base porte chacun de ses déclencheurs de protection, actif, et
     * aucun autre, chacun avec une fonction au search_path fixé.
     */
    public function declencheurs(): ResultatDUnControle
    {
        $resultat = new ResultatDUnControle('Déclencheurs et gardes', self::COUCHE);
        $presents = [];

        foreach ($this->schema->declencheurs() as $declencheur) {
            $resultat->compter(1);
            $table = $declencheur['table'];
            $presents[$table][] = $declencheur['nom'];

            if (! in_array($declencheur['nom'], ReglesDIsolation::DECLENCHEURS[$table] ?? [], true)) {
                $resultat->signaler($table, $declencheur['nom'], '—', 'un déclencheur de la liste attendue', 'un déclencheur inconnu', ReglesDIsolation::REGLE_PROTECTIONS);
            }

            if (! $declencheur['actif']) {
                $resultat->signaler($table, $declencheur['nom'], '—', 'un déclencheur actif', 'un déclencheur désactivé', ReglesDIsolation::REGLE_PROTECTIONS);
            }

            if ($declencheur['reglages'] !== ReglesDIsolation::CHEMIN_DE_RECHERCHE) {
                $resultat->signaler($table, $declencheur['nom'], $declencheur['fonction'], ReglesDIsolation::CHEMIN_DE_RECHERCHE, $declencheur['reglages'] === '' ? 'aucun search_path fixé' : $declencheur['reglages'], ReglesDIsolation::REGLE_PROTECTIONS);
            }
        }

        foreach (ReglesDIsolation::DECLENCHEURS as $table => $noms) {
            foreach (array_diff($noms, $presents[$table] ?? []) as $nom) {
                $resultat->signaler($table, $nom, '—', 'un déclencheur présent', 'aucun déclencheur', ReglesDIsolation::REGLE_PROTECTIONS);
            }
        }

        return $resultat;
    }

    /**
     * La société n° qu'une colonne qui n'est pas la société de sa ligne vise
     * doit être ce que la règle dit (le cabinet d'un lien est un cabinet) ;
     * une telle colonne sans règle est un problème.
     *
     * @param  list<string>  $colonnes
     */
    private function controlerLaSocieteVisee(ResultatDUnControle $resultat, string $table, array $colonnes, string $jointure): void
    {
        if ($colonnes === ['societe_id']) {
            return;
        }

        $colonne = implode(',', $colonnes);

        if (! array_key_exists("{$table}.{$colonne}", ReglesDIsolation::SOCIETES_VISEES)) {
            $resultat->signaler($table, '—', $colonne, 'la société de la ligne, ou une société visée selon une règle', 'une autre société, sans règle', ReglesDIsolation::REGLE_SOCIETES);

            return;
        }

        $identifiant = $this->identifiant($table, 't');
        $cabinet = TypeDActivite::Cabinet->value;

        foreach ($this->base->select("select {$identifiant} as identifiant, r.type_activite as trouve from {$table} t join societes r on {$jointure} where r.type_activite <> '{$cabinet}'") as $ligne) {
            $champs = SchemaDeLaBase::champsDe($ligne);
            $resultat->signaler($table, self::texteDe($champs, 'identifiant'), $colonne, ReglesDIsolation::SOCIETES_VISEES["{$table}.{$colonne}"], 'une société de type « '.self::texteDe($champs, 'trouve').' »', ReglesDIsolation::REGLE_CABINETS);
        }
    }

    private function controlerUneReferencePolymorphe(ResultatDUnControle $resultat, string $table, string $colonneDuType, string $colonneDeLId, bool $lienPermis): void
    {
        $resultat->compter($this->compter("select count(*) as nombre from {$table} t where t.{$colonneDeLId} is not null"));

        foreach ($this->base->select("select distinct t.{$colonneDuType} as type from {$table} t where t.{$colonneDuType} is not null") as $ligne) {
            $type = self::texteDe(SchemaDeLaBase::champsDe($ligne), 'type');
            $visee = self::tableDuType($type);

            if ($visee === null || ! in_array(ReglesDIsolation::porteeDe($visee), [ReglesDIsolation::MAGASIN, ReglesDIsolation::SOCIETE, ReglesDIsolation::RACINE], true)) {
                $resultat->signaler($table, '—', $colonneDuType, 'un type de ligne connu, d\'un magasin ou d\'une société', "le type « {$type} »", ReglesDIsolation::REGLE_TABLES);

                continue;
            }

            $societe = $this->societeDe($table, 't');
            $societeVisee = $this->societeDe($visee, 'r');
            $magasin = $this->magasinDe($table, 't');
            $magasinVise = $this->magasinDe($visee, 'r');
            $difference = $magasin !== null && $magasinVise !== null && ReglesDIsolation::porteeDe($table) === ReglesDIsolation::MAGASIN
                ? "r.id is null or ({$magasinVise}) is distinct from ({$magasin})"
                : "r.id is null or (({$societeVisee}) is distinct from ({$societe})".($lienPermis ? " and not exists (select 1 from liens_cabinet l where (l.cabinet_id = ({$societeVisee}) and l.societe_id = ({$societe})) or (l.societe_id = ({$societeVisee}) and l.cabinet_id = ({$societe})))" : '').')';
            $identifiant = $this->identifiant($table, 't');

            foreach ($this->base->select("select {$identifiant} as identifiant, ({$societe})::text as attendu, coalesce(({$societeVisee})::text, 'aucune ligne') as trouve from {$table} t left join {$visee} r on r.id = t.{$colonneDeLId} where t.{$colonneDuType} = ? and t.{$colonneDeLId} is not null and ({$difference})", [$type]) as $ligneFautive) {
                $champs = SchemaDeLaBase::champsDe($ligneFautive);
                $resultat->signaler($table, self::texteDe($champs, 'identifiant'), "{$colonneDuType}, {$colonneDeLId}", 'une ligne de '.$visee.' de la société n° '.self::texteDe($champs, 'attendu').($magasin !== null ? ', du même magasin' : ''), 'une ligne de la société n° '.self::texteDe($champs, 'trouve'), $magasin !== null ? ReglesDIsolation::REGLE_MAGASINS : ReglesDIsolation::REGLE_SOCIETES);
            }
        }
    }

    /**
     * Le nom polymorphe d'un modèle, tel que la base l'enregistre (voir
     * NomsPolymorphes).
     *
     * @param  class-string<Model>  $classe
     */
    private function nomPolymorphe(string $classe): string
    {
        return (new $classe)->getMorphClass();
    }

    /**
     * La table du modèle que désigne ce nom polymorphe, ou null quand il
     * n'en désigne aucun.
     */
    private static function tableDuType(string $type): ?string
    {
        $classe = Relation::getMorphedModel($type) ?? $type;

        return class_exists($classe) && is_subclass_of($classe, Model::class) ? (new $classe)->getTable() : null;
    }

    /**
     * @return list<string>
     */
    private function tablesDePortee(string $portee): array
    {
        return array_values(array_filter($this->schema->tables(), static fn (string $table): bool => ReglesDIsolation::porteeDe($table) === $portee));
    }

    /**
     * L'expression SQL de la société d'une ligne de cette table, sous cet alias.
     */
    private function societeDe(string $table, string $alias): string
    {
        return match (true) {
            $table === 'societes' => "{$alias}.id",
            $this->schema->aLaColonne($table, 'societe_id') => "{$alias}.societe_id",
            $this->schema->aLaColonne($table, 'magasin_id') => "(select ms.societe_id from magasins ms where ms.id = {$alias}.magasin_id)",
            default => 'null::bigint',
        };
    }

    /**
     * L'expression SQL du magasin d'une ligne de cette table, ou null quand
     * la table n'a pas de magasin.
     */
    private function magasinDe(string $table, string $alias): ?string
    {
        return match (true) {
            $table === 'magasins' => "{$alias}.id",
            $this->schema->aLaColonne($table, 'magasin_id') => "{$alias}.magasin_id",
            default => null,
        };
    }

    /**
     * L'identifiant interne d'une ligne de cette table, en texte : son id, ou,
     * pour une table de liaison sans id, ses colonnes *_id.
     */
    private function identifiant(string $table, string $alias): string
    {
        if ($this->schema->aLaColonne($table, 'id')) {
            return "{$alias}.id::text";
        }

        $colonnes = array_values(array_filter($this->schema->colonnes()[$table] ?? [], static fn (string $colonne): bool => str_ends_with($colonne, '_id') || $colonne === 'autorisation'));

        return implode(" || ', ' || ", array_map(static fn (string $colonne): string => "'{$colonne}=' || coalesce({$alias}.{$colonne}::text, '')", $colonnes));
    }

    private function compter(string $requete): int
    {
        $ligne = $this->base->selectOne($requete);

        return ValeursLues::entier(ValeursLues::champ(SchemaDeLaBase::champsDe($ligne), 'nombre'));
    }

    /**
     * @param  array<string, mixed>  $champs
     */
    private static function texteDe(array $champs, string $champ): string
    {
        $valeur = ValeursLues::champ($champs, $champ);

        return is_scalar($valeur) ? (string) $valeur : '—';
    }

    /**
     * « société n° 3, magasin n° 7 » d'une ligne lue.
     *
     * @param  array<string, mixed>  $champs
     */
    private static function portee(array $champs, string $societe, string $magasin): string
    {
        $texte = 'société n° '.self::texteDe($champs, $societe);

        return ValeursLues::champ($champs, $magasin) === null ? $texte : $texte.', magasin n° '.self::texteDe($champs, $magasin);
    }
}
