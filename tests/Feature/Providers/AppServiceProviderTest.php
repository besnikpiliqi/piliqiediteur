<?php

namespace Tests\Feature\Providers;

use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function reglesDeDate(): array
    {
        return [
            'date du jour' => ['after_or_equal:2026-10-09', '2026-10-08', 'Le champ jour doit être une date postérieure ou égale au 09/10/2026.'],
            'date avec une heure' => ['before:2026-10-09 10:30', '2026-10-09 11:00', 'Le champ jour doit être une date antérieure au 09/10/2026 à 10:30.'],
            'autre champ' => ['after:reference', '2026-10-08', 'Le champ jour doit être une date postérieure au jour de référence.'],
        ];
    }

    /**
     * Une règle de date cite la date comparée comme on l'écrit en français,
     * jamais sous sa forme technique « 2026-10-09 00:00:00 ».
     */
    #[DataProvider('reglesDeDate')]
    public function test_le_message_d_une_regle_de_date_ecrit_la_date_en_francais(string $regle, string $saisie, string $message): void
    {
        $validateur = Validator::make(
            ['jour' => $saisie, 'reference' => '2026-10-09'],
            ['jour' => $regle],
            attributes: ['jour' => 'jour', 'reference' => 'jour de référence'],
        );

        $this->assertSame($message, $validateur->errors()->first('jour'));
    }
}
