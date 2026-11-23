<?php

namespace App\Domain\Demonstration\Archives;

use App\Domain\CaisseSecurisee\Actions\ExporterJournalDesEvenementsAction;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\ModuleFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Construction\DossiersDeLaDemonstration;
use App\Domain\Demonstration\Exceptions\ArchiveImpossible;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Registre\Models\Archive;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\VersionServie;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Services\ClotureCaissePdfGenerator;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ResultatDePhp;
use App\Domain\Tenancy\Support\ValeurSimple;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Throwable;
use ZipArchive;

/**
 * L'archive de la caisse d'une copie, faite avant toute suppression de sa
 * base (lot DEMO-COPIE, C.18, et la partie « Ce que dit la loi » du
 * cahier) : la loi garde six ans ce que la caisse d'entraînement a
 * enregistré, même fictif. Dans la copie, par le rôle des pages :
 *
 * 1. les journées dues sont clôturées (app:cloturer-caisses-automatiquement,
 *    jusqu'à la veille de chaque magasin) ;
 * 2. la sortie du mode d'entraînement (JET 105) s'inscrit dans chaque
 *    magasin, avec ses grands totaux perpétuels lus sous le même verrou ;
 * 3. caisse:verifier --complet, avec la clé publique de démonstration :
 *    code 0 exigé ;
 * 4. pour chaque magasin, le registre signé (toutes ses chaînes), le
 *    journal des événements au format de son export, et le PDF de chaque
 *    ticket Z de la vie de la copie (ceux de l'histoire du modèle, la même
 *    dans chaque copie, sont dans le registre signé, décision de Claude,
 *    D76, voir le compte rendu du lot) ;
 * 5. un sommaire (référence, repère, type, motif, dates, nombre
 *    d'enregistrements par chaîne et de tickets Z, grands totaux, empreinte
 *    SHA-256 de chaque fichier), signé avec la clé de caisse de
 *    démonstration ;
 * 6. le tout dans un ZIP sous storage/demonstration/archives/<référence>/,
 *    sa ligne au registre (empreinte, à effacer six ans plus tard), puis
 *    relu (empreinte du ZIP, signature du sommaire, empreinte de chaque
 *    fichier) : sans cette relecture, aucune base n'est supprimée.
 *
 * Rien de la vraie société n'y entre : la référence et le repère sont
 * tirés au hasard par le site réel, et tout le reste est fictif.
 */
final class ArchiverUneCopie
{
    public const DUREE_DE_GARDE_EN_ANNEES = 6;

    public const FORMAT = 1;

    public const MESSAGE_SANS_BASE = 'La copie « %s » n\'a pas de base à archiver.';

    public const MESSAGE_CLOTURES = 'Les journées de la copie n\'ont pas pu être clôturées : %s';

    public const MESSAGE_VERIFICATION = 'La vérification du registre fiscal de la copie a échoué : %s';

    public const MESSAGE_RELECTURE = 'L\'archive « %s » ne se relit pas : %s';

    public function __construct(
        private readonly BasesDeLaDemonstration $bases,
        private readonly OuvertureDUneCopie $ouverture,
        private readonly SignataireFiscal $signataire,
    ) {}

    /**
     * L'archive faite, enregistrée au registre et relue.
     *
     * @param  string  $motif  pourquoi la copie est archivée : trente_jours, recommencer, supprimer, nettoyage, mise_a_jour, avant_migration, migration_ratee, perdue
     *
     * @throws ArchiveImpossible
     */
    public function archiver(Copie $copie, string $motif): Archive
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $base = (string) $copie->nom_de_la_base;

        if ($base === '' || ! $this->bases->existe($base)) {
            throw new ArchiveImpossible(sprintf(self::MESSAGE_SANS_BASE, $copie->reference));
        }

        $maintenant = CarbonImmutable::now();
        $dossier = DossiersDeLaDemonstration::desArchives($copie->reference);
        $chemin = $dossier.'/'.Calendrier::horodatageSigne($maintenant).'.zip';
        $travail = $dossier.'/en-cours-'.bin2hex(random_bytes(8));

        try {
            File::ensureDirectoryExists($travail);
            $magasins = $this->ouverture->pendant($copie, fn (): array => $this->ecrireLaCaisse($copie, $motif, $travail));
            $sommaire = $this->sommaire($copie, $motif, $maintenant, $magasins, $travail);
            $texte = MessagesSignes::canonique($sommaire);
            $signature = $this->signataire->signer($texte);
            File::put($travail.'/sommaire.json', $texte);
            File::put($travail.'/sommaire.sig', $signature);
            $this->zipper($travail, $chemin);
        } catch (ArchiveImpossible $erreur) {
            File::delete($chemin);

            throw $erreur;
        } catch (Throwable $erreur) {
            File::delete($chemin);

            throw new ArchiveImpossible('L\'archive de la copie « '.$copie->reference.' » a échoué : '.$erreur->getMessage(), 0, $erreur);
        } finally {
            File::deleteDirectory($travail);
        }

        $archive = Archive::on('demo_admin')->create([
            'reference' => $copie->reference,
            'motif' => $motif,
            'chemin' => $chemin,
            'empreinte' => (string) hash_file('sha256', $chemin),
            'sommaire' => $sommaire,
            'signature' => $signature,
            'creee_le' => $maintenant,
            'a_effacer_le' => Calendrier::deParis()->decaler($maintenant, CarbonInterval::years(self::DUREE_DE_GARDE_EN_ANNEES)),
        ]);
        $this->relire($archive);

        return $archive;
    }

    /**
     * Relit une archive : l'empreinte du ZIP, la signature de son sommaire
     * par une clé de caisse de démonstration épinglée, et l'empreinte de
     * chaque fichier qu'il nomme.
     *
     * @throws ArchiveImpossible
     */
    public function relire(Archive $archive): void
    {
        $refuser = fn (string $cause): ArchiveImpossible => new ArchiveImpossible(sprintf(self::MESSAGE_RELECTURE, $archive->chemin, $cause));

        if (! is_file($archive->chemin) || hash_file('sha256', $archive->chemin) !== $archive->empreinte) {
            throw $refuser('le fichier manque ou son empreinte a changé');
        }

        $zip = new ZipArchive;

        if ($zip->open($archive->chemin, ZipArchive::RDONLY) !== true) {
            throw $refuser('le ZIP ne s\'ouvre pas');
        }

        try {
            $texte = $zip->getFromName('sommaire.json');
            $cleId = $archive->sommaire['cle_id'] ?? null;

            if (! is_string($texte) || $texte !== MessagesSignes::canonique($archive->sommaire) || $zip->getFromName('sommaire.sig') !== $archive->signature
                || ! is_string($cleId) || ! $this->signataire->verifier($texte, $archive->signature, $cleId)) {
                throw $refuser('la signature du sommaire ne se vérifie pas');
            }

            foreach ((array) ($archive->sommaire['fichiers'] ?? []) as $nom => $empreinte) {
                $contenu = $zip->getFromName((string) $nom);

                if (! is_string($contenu) || hash('sha256', $contenu) !== $empreinte) {
                    throw $refuser('le fichier « '.$nom.' » manque ou a changé');
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Dans la copie : les journées dues clôturées, la sortie du mode
     * d'entraînement dans chaque magasin, la vérification du registre,
     * puis les fichiers de chaque magasin.
     *
     * @return array<string, array<string, mixed>> par code de magasin : ses nombres et ses grands totaux
     */
    private function ecrireLaCaisse(Copie $copie, string $motif, string $travail): array
    {
        if (Artisan::call('app:cloturer-caisses-automatiquement') !== 0) {
            throw new ArchiveImpossible(sprintf(self::MESSAGE_CLOTURES, mb_substr(trim(Artisan::output()), 0, 1500)));
        }

        $magasins = Magasin::query()->withoutGlobalScopes()->with('societe')->orderBy('id')->get();
        $contexte = app(TenantContext::class);
        $registre = app(RegistreFiscal::class);
        $totaux = [];

        foreach ($magasins as $magasin) {
            $totaux[$magasin->code] = $contexte->runAs($magasin, fn (): array => $registre->sousVerrou($magasin, function () use ($registre, $magasin, $motif): array {
                $registre->journaliser(CodeEvenement::SortieDuModeDemonstration, ['motif' => $motif]);

                return $registre->grandsTotauxApres($magasin, ValeurSimple::enEntierOuNul(EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente->value)->max('sequence')));
            }));
        }

        if (Artisan::call('caisse:verifier', ['--complet' => true]) !== 0) {
            throw new ArchiveImpossible(sprintf(self::MESSAGE_VERIFICATION, mb_substr(trim(Artisan::output()), 0, 1500)));
        }

        $resume = [];

        foreach ($magasins as $magasin) {
            $resume[$magasin->code] = $contexte->runAs($magasin, fn (): array => [
                ...$this->ecrireUnMagasin($copie, $magasin, $travail.'/'.$magasin->code),
                'grands_totaux' => $totaux[$magasin->code],
            ]);
        }

        return $resume;
    }

    /**
     * @return array{enregistrements: array<string, int>, tickets_z: int}
     */
    private function ecrireUnMagasin(Copie $copie, Magasin $magasin, string $dossier): array
    {
        File::ensureDirectoryExists($dossier.'/tickets-z');
        $flux = ResultatDePhp::flux(fopen($dossier.'/journal-des-evenements.csv', 'wb'), 'L\'ouverture du journal des événements de l\'archive');
        ExporterJournalDesEvenementsAction::ecrivain(CleDuModele::de($magasin), $magasin->calendrier(), null, null, false)($flux);
        fclose($flux);

        $registre = ResultatDePhp::flux(fopen($dossier.'/registre.csv', 'wb'), 'L\'ouverture du registre de l\'archive');
        fwrite($registre, "\u{FEFF}");
        fputcsv($registre, ['Chaîne', 'Séquence', 'Type', 'Horodatage signé (UTC)', 'Signature précédente', 'Signature', 'Algorithme', 'Clé (cle_id)', 'Version du module', 'Contenu signé'], ';', '"', '');
        $nombres = [];

        foreach (EnregistrementFiscal::query()->orderBy('chaine')->orderBy('sequence')->lazy(500) as $enregistrement) {
            $nombres[$enregistrement->chaine->value] = ($nombres[$enregistrement->chaine->value] ?? 0) + 1;
            fputcsv($registre, [
                $enregistrement->chaine->value,
                (string) $enregistrement->sequence,
                $enregistrement->type,
                MessagesSignes::instant($enregistrement->horodatage),
                (string) $enregistrement->signature_precedente,
                $enregistrement->signature,
                $enregistrement->algorithme,
                $enregistrement->cle_id,
                $enregistrement->version_module,
                $enregistrement->contenu,
            ], ';', '"', '');
        }

        fclose($registre);
        $pdf = app(ClotureCaissePdfGenerator::class);
        $tickets = 0;

        foreach (ClotureCaisse::query()->with('magasin.societe')->where('cloturee_a', '>=', $copie->creee_le)->orderBy('date_cloture')->get() as $cloture) {
            File::put($dossier.'/tickets-z/'.$pdf->nomFichier($cloture), $pdf->generer($cloture)->output());
            $tickets++;
        }

        ksort($nombres);

        return ['enregistrements' => $nombres, 'tickets_z' => $tickets];
    }

    /**
     * @param  array<string, array<string, mixed>>  $magasins
     * @return array<string, mixed>
     */
    private function sommaire(Copie $copie, string $motif, CarbonImmutable $maintenant, array $magasins, string $travail): array
    {
        $fichiers = [];

        foreach (File::allFiles($travail) as $fichier) {
            $fichiers[str_replace('\\', '/', $fichier->getRelativePathname())] = (string) hash_file('sha256', $fichier->getPathname());
        }

        ksort($fichiers);

        return [
            'format' => self::FORMAT,
            'reference' => $copie->reference,
            'repere' => $copie->repere,
            // Une base orpheline, trouvée par le nettoyage sans ligne au
            // registre (voir NettoyageDeLaDemonstration), n'a pas de type.
            'type' => array_key_exists('type', $copie->getAttributes()) ? $copie->type->value : null,
            'motif' => $motif,
            'creee_le' => MessagesSignes::instant($copie->creee_le),
            'prete_le' => $copie->prete_le === null ? null : MessagesSignes::instant($copie->prete_le),
            'fin' => MessagesSignes::instant($copie->fin),
            'archivee_le' => MessagesSignes::instant($maintenant),
            'version_du_code' => VersionServie::courante(),
            'version_du_module_fiscal' => ModuleFiscal::VERSION,
            'cle_id' => $this->signataire->cleId(),
            'magasins' => $magasins,
            'fichiers' => $fichiers,
        ];
    }

    private function zipper(string $travail, string $chemin): void
    {
        $zip = new ZipArchive;

        if ($zip->open($chemin, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new ArchiveImpossible('Le ZIP de l\'archive « '.$chemin.' » ne s\'écrit pas.');
        }

        foreach (File::allFiles($travail) as $fichier) {
            $zip->addFile($fichier->getPathname(), str_replace('\\', '/', $fichier->getRelativePathname()));
        }

        if (! $zip->close()) {
            throw new ArchiveImpossible('Le ZIP de l\'archive « '.$chemin.' » ne s\'écrit pas.');
        }
    }
}
