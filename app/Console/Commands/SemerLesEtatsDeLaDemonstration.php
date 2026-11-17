<?php

namespace App\Console\Commands;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\Archives\FinDesCopies;
use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Enums\EtatDUnModele;
use App\Domain\Demonstration\Jobs\CreerUneCopie;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Modele;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Taches\PlaceDesCopies;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Sur le site de démonstration du poste seulement (lot DEMO-COPIE, F.8) :
 * après demonstration:construire-les-modeles et
 * demonstration:renvoyer-les-demandes, une copie de chaque état du
 * registre qu'aucune demande semée ne fait naître, chacune avec son lien,
 * pour que l'éditeur ouvre chaque page d'attente : perdue, à refaire, en
 * migration, en lecture seule (au-delà de sa place), juste sous sa place,
 * en attente d'une place (celle d'une société non validée, qui passe après
 * les sociétés validées), avec le message d'une mise à jour, avec celui
 * d'une copie refaite, restée plus d'une heure sans passe de ses tâches
 * (« Nous mettons votre démonstration à l'heure »), et l'archive de la
 * caisse d'une copie supprimée. Les liens s'écrivent à la fin de la liste des comptes du semis
 * (DatabaseSeeder::FICHIER_DES_COMPTES, fichier local que git ne suit
 * pas), à la place de ceux d'un passage précédent. Elle refuse en
 * production, comme le semis. Le planificateur du poste, s'il tourne,
 * fait ensuite vivre ces copies comme les autres.
 */
#[Signature('demonstration:semer-les-etats')]
#[Description('Pose dans le registre du site de démonstration une copie de chaque état, avec son lien (poste seulement)')]
class SemerLesEtatsDeLaDemonstration extends Command
{
    public const MESSAGE_EN_PRODUCTION = 'demonstration:semer-les-etats ne sert que sur le poste : en production, le registre ne reçoit que les demandes du site réel. Rien n\'a été écrit.';

    public const MESSAGE_SANS_MODELE = 'Aucun modèle « Services, sans vente d\'articles » n\'est en service : lancez d\'abord php artisan demonstration:construire-les-modeles. Rien n\'a été écrit.';

    public const MESSAGE_COPIE_IMPOSSIBLE = 'Une copie n\'a pas pu être créée (état « %s ») : le plafond des copies (DEMONSTRATION_COPIES_MAX) ou la place libre du disque est atteint. Les copies déjà semées restent.';

    public const TITRE_DANS_LE_FICHIER = '== Site de démonstration : une copie de chaque état du registre (demonstration:semer-les-etats) ==';

    /**
     * Les états semés, avec ce que la page de chacun montre.
     */
    public const ETATS = [
        'perdue' => 'copie perdue (sa base a disparu) : « Votre démonstration se prépare », puis refaite',
        'a_refaire' => 'copie à refaire : « Votre démonstration se prépare »',
        'en_migration' => 'copie en migration : « Mise à jour en cours, une minute »',
        'en_lecture_seule' => 'copie au-delà de sa place : en lecture seule, « Votre démonstration a atteint sa place… »',
        'sous_la_limite' => 'copie juste sous sa place : servie',
        'en_attente_d_une_place' => 'copie en attente d\'une place : « … dès qu\'une place se libère »',
        'mise_a_jour' => 'copie refaite par une mise à jour : « PILIQI a été mis à jour… », une fois',
        'refaite' => 'copie refaite après une migration ratée : « … a dû être refaite… », une fois',
        'a_l_heure' => 'copie restée plus d\'une heure sans passe de ses tâches : « Nous mettons votre démonstration à l\'heure »',
    ];

    public function handle(BasesDeLaDemonstration $bases, FinDesCopies $fin): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);

        if ($this->laravel->isProduction()) {
            $this->error(self::MESSAGE_EN_PRODUCTION);

            return self::FAILURE;
        }

        if (! Modele::on('demo_admin')->where('type', TypeDActivite::Services->value)->where('etat', EtatDUnModele::EnService->value)->exists()) {
            $this->error(self::MESSAGE_SANS_MODELE);

            return self::FAILURE;
        }

        $maintenant = CarbonImmutable::now();
        $liens = [];
        $copies = [];

        // L'archive : celle d'une copie supprimée ensuite, comme toute
        // archive (JET 105 juste avant la suppression de sa base, C.18),
        // faite avant les autres états, pour que la place qu'elle libère ne
        // serve pas la copie en attente d'une place.
        $fin->supprimer($this->creer(MessagesSignes::enBase64Url(random_bytes(32)), $maintenant)->reference, 'supprimer');

        foreach (array_keys(self::ETATS) as $etat) {
            $secret = MessagesSignes::enBase64Url(random_bytes(32));
            $copies[$etat] = $this->creer($secret, $maintenant);
            $liens[$etat] = rtrim((string) config('app.url'), '/').'/d#'.$secret;
        }

        foreach ($copies as $etat => $copie) {
            $this->poser($copie, $etat, $bases, $maintenant);
        }

        $this->ecrireLesLiens($liens);
        $this->info(count($liens).' copie(s) semée(s), une par état, et une archive ; leurs liens sont à la fin de '.Storage::disk('local')->path(DatabaseSeeder::FICHIER_DES_COMPTES).'.');

        return self::SUCCESS;
    }

    private function creer(string $secret, CarbonImmutable $maintenant): Copie
    {
        $reference = bin2hex(random_bytes(16));
        Bus::dispatchSync(new CreerUneCopie([
            'reference' => $reference,
            'repere' => self::repere(),
            'type' => TypeDActivite::Services->value,
            'validee' => true,
            'empreinte_du_lien' => hash('sha256', $secret),
            'generation' => 1,
            'fin' => MessagesSignes::instant(Calendrier::deParis()->decaler($maintenant, CarbonInterval::days(30))),
        ]));

        $copie = Copie::on('demo_admin')->where('reference', $reference)->sole();

        if ($copie->etat !== EtatDUneCopie::Prete) {
            throw new RuntimeException(sprintf(self::MESSAGE_COPIE_IMPOSSIBLE, $copie->etat->value));
        }

        return $copie;
    }

    private function poser(Copie $copie, string $etat, BasesDeLaDemonstration $bases, CarbonImmutable $maintenant): void
    {
        $sansBase = in_array($etat, ['perdue', 'a_refaire', 'en_attente_d_une_place'], true);

        if ($sansBase && $copie->nom_de_la_base !== null) {
            $bases->supprimer($copie->nom_de_la_base);
        }

        $limite = $copie->modele_id === null ? 0 : PlaceDesCopies::limiteDe(Modele::on('demo_admin')->whereKey($copie->modele_id)->sole());
        $copie->forceFill(match ($etat) {
            'perdue' => ['etat' => EtatDUneCopie::Perdue, 'nom_de_la_base' => null],
            'a_refaire' => ['etat' => EtatDUneCopie::ARefaire, 'nom_de_la_base' => null, 'prochain_essai_le' => Calendrier::deParis()->decaler($maintenant, CarbonInterval::days(1))],
            'en_attente_d_une_place' => ['etat' => EtatDUneCopie::EnAttenteDUnePlace, 'nom_de_la_base' => null, 'validee' => false],
            'en_migration' => ['etat' => EtatDUneCopie::EnMigration],
            'en_lecture_seule' => ['etat' => EtatDUneCopie::EnLectureSeule, 'taille_mesuree' => $limite + 1],
            'sous_la_limite' => ['etat' => EtatDUneCopie::Prete, 'taille_mesuree' => max(0, $limite - 1)],
            'mise_a_jour' => ['message' => FinDesCopies::message(FinDesCopies::MOTIF_MISE_A_JOUR, $maintenant), 'motif_du_message' => FinDesCopies::MOTIF_MISE_A_JOUR],
            'refaite' => ['message' => FinDesCopies::message(FinDesCopies::MOTIF_MIGRATION_RATEE, $maintenant), 'motif_du_message' => FinDesCopies::MOTIF_MIGRATION_RATEE],
            'a_l_heure' => ['derniere_passe_des_taches_le' => $maintenant->subHours(2)],
            default => [],
        })->save();
    }

    /**
     * Les liens de ce passage à la fin de la liste des comptes du semis, à
     * la place de ceux d'un passage précédent.
     *
     * @param  array<string, string>  $liens
     */
    private function ecrireLesLiens(array $liens): void
    {
        $disque = Storage::disk('local');
        $avant = $disque->exists(DatabaseSeeder::FICHIER_DES_COMPTES) ? (string) $disque->get(DatabaseSeeder::FICHIER_DES_COMPTES) : '';
        $position = strpos($avant, self::TITRE_DANS_LE_FICHIER);
        $texte = rtrim($position === false ? $avant : substr($avant, 0, $position))."\n\n".self::TITRE_DANS_LE_FICHIER."\n";

        foreach ($liens as $etat => $lien) {
            $texte .= self::ETATS[$etat].' :'."\n  ".$lien."\n";
        }

        $disque->put(DatabaseSeeder::FICHIER_DES_COMPTES, ltrim($texte));
    }

    private static function repere(): string
    {
        $alphabet = 'ACDEFGHJKMNPQRTUVWXY234679';
        $repere = '';

        for ($i = 0; $i < 6; $i++) {
            $repere .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $repere;
    }
}
