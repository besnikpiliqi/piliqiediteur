<?php

namespace App\Domain\Demonstration\Courriels;

use App\Domain\Demonstration\Models\CourrielDeLaDemonstration;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Le transport « demonstration » (MAIL_MAILER=demonstration), le seul du
 * site de démonstration (lot DEMO-COPIE, C.9) : aucun e-mail n'en part.
 * Chaque message que le vrai logiciel aurait envoyé, une fois passées
 * toutes ses règles d'envoi (limites par heure, une relance par jour,
 * accès et droit d'envoyer, particuliers relancés seulement si le magasin
 * le coche), s'écrit dans la copie (courriels_de_la_demonstration), dans
 * la société d'où il part : celle du compte qui reçoit l'avis, pour une
 * notification (voir SocieteDuCourriel), sinon celle du geste en cours
 * (contexte de la société ou du magasin), sinon celle du compte connecté,
 * qui fait le geste hors de tout contexte (son profil, la déclaration de
 * son expert-comptable, une invitation de son cabinet). Un e-mail sans
 * société n'est montré à personne et ne s'écrit pas. Le
 * compte qui a demandé l'envoi s'écrit quand il est de cette société. Une
 * pièce jointe de plus de 10 Mo est gardée sans son contenu.
 */
final class TransportDeLaDemonstration extends AbstractTransport
{
    public const TAILLE_MAXIMALE_D_UNE_PIECE = 10 * 1024 * 1024;

    public function __construct(private readonly SocieteDuCourriel $societeDuCourriel)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'demonstration://';
    }

    /**
     * @throws LogicException hors du site de démonstration, ou pour un message qui n'est pas un e-mail
     */
    protected function doSend(SentMessage $message): void
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $email = $message->getOriginalMessage();

        if (! $email instanceof Email) {
            throw new LogicException('Le transport de la démonstration ne garde que des e-mails.');
        }

        $societe = $this->societe();

        if ($societe === null) {
            return;
        }

        $compte = Auth::user();
        $champs = [
            'user_id' => $compte instanceof User && $compte->societe_id === $societe->getKey() ? $compte->getKey() : null,
            'expediteur' => self::adresses($email->getFrom()),
            'destinataires' => self::adresses($email->getTo()),
            'copies' => self::copies($email),
            'objet' => (string) $email->getSubject(),
            'html' => self::texte($email->getHtmlBody()),
            'texte' => self::texte($email->getTextBody()),
            'pieces_jointes' => self::pieces($email),
            'envoye_le' => CarbonImmutable::now(),
        ];

        app(TenantContext::class)->runAsSociete($societe, fn (): CourrielDeLaDemonstration => CourrielDeLaDemonstration::query()->create($champs));
    }

    private function societe(): ?Societe
    {
        $id = $this->societeDuCourriel->societeId();

        if ($id !== null) {
            return Societe::query()->find($id);
        }

        $contexte = app(TenantContext::class);

        if ($contexte->aUneSociete()) {
            return $contexte->societe();
        }

        $compte = Auth::user();

        return $compte instanceof User ? Societe::query()->find($compte->societe_id) : null;
    }

    /**
     * @param  list<Address>  $adresses
     */
    private static function adresses(array $adresses): string
    {
        return implode(', ', array_map(static fn (Address $adresse): string => $adresse->getName() === '' ? $adresse->getAddress() : "{$adresse->getName()} <{$adresse->getAddress()}>", $adresses));
    }

    private static function copies(Email $email): ?string
    {
        $copies = array_filter([
            $email->getCc() === [] ? null : 'Cc : '.self::adresses($email->getCc()),
            $email->getBcc() === [] ? null : 'Cci : '.self::adresses($email->getBcc()),
        ]);

        return $copies === [] ? null : implode(' ; ', $copies);
    }

    /**
     * @param  resource|string|null  $corps
     */
    private static function texte(mixed $corps): ?string
    {
        if (is_resource($corps)) {
            rewind($corps);
            $corps = stream_get_contents($corps);
        }

        return is_string($corps) ? $corps : null;
    }

    /**
     * @return list<array{nom: string, type: string, taille: int, contenu: string|null}>
     */
    private static function pieces(Email $email): array
    {
        $pieces = [];

        foreach ($email->getAttachments() as $piece) {
            $contenu = $piece->getBody();
            $taille = strlen($contenu);
            $pieces[] = [
                'nom' => $piece->getFilename() ?? 'piece-jointe',
                'type' => "{$piece->getMediaType()}/{$piece->getMediaSubtype()}",
                'taille' => $taille,
                'contenu' => $taille > self::TAILLE_MAXIMALE_D_UNE_PIECE ? null : base64_encode($contenu),
            ];
        }

        return $pieces;
    }
}
