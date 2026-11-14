<?php

namespace App\Providers;

use App\Domain\Tenancy\Support\Reglage;
use App\Integrations\Courriel\CanalDesCourriels;
use App\Integrations\Courriel\TransportMailtrap;
use Illuminate\Http\Client\Factory;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

/**
 * Le câblage des e-mails : le transport d'envoi « mailtrap » (voir
 * TransportMailtrap), que choisit seulement MAIL_MAILER=mailtrap, la boîte
 * de test de Mailtrap jointe par son API HTTPS, avec le jeton et la boîte
 * de config/services.php et le délai MAIL_TIMEOUT de config/mail.php (un
 * jeton ou une boîte absents ne bloquent rien au démarrage : l'envoi
 * échoue alors en le disant) ; et le canal « mail » des notifications,
 * qu'un serveur d'envoi en panne ne fait jamais échouer (voir
 * CanalDesCourriels).
 */
class CourrielsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MailChannel::class, CanalDesCourriels::class);
    }

    public function boot(): void
    {
        Mail::extend('mailtrap', fn (array $config): TransportMailtrap => new TransportMailtrap(
            $this->app->make(Factory::class),
            Reglage::texteOuNul('services.mailtrap.jeton'),
            Reglage::texteOuNul('services.mailtrap.boite'),
            is_int($config['timeout'] ?? null) ? $config['timeout'] : 10,
        ));
    }
}
