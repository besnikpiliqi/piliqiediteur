{{--
    Le message de la TVA (lot TVA27, maquette « Message TVA avant PILIQI »
    approuvée par l'éditeur le 18/11/2026, voir
    App\Domain\Charges\Services\MessagesDuCreditDeTva::message()) : le
    régime à choisir, en rouge ; le crédit de TVA en attente de sa
    déclaration, à indiquer ou non vérifié, en orange ; saisi, à vérifier,
    en bleu ; vérifié, en vert. Il ne se ferme pas. Son bouton n'est donné
    qu'à qui peut faire le geste ; grisé tant que le crédit ne se déclare
    pas encore.
--}}
<div
    @class(['piliqi-credit__message', 'piliqi-credit__message--'.$message['couleur']])
    role="status"
    data-message-de-la-tva="{{ $message['etat'] }}"
>
    <p class="piliqi-credit__titre">{{ $message['titre'] }}</p>
    <p>{{ $message['texte'] }}</p>

    @if ($message['bouton'] !== null)
        <div class="piliqi-credit__actions">
            @if ($message['desactive'] || $message['url'] === null)
                <x-filament::button size="sm" color="gray" disabled data-geste-du-message="{{ $message['etat'] }}">{{ $message['bouton'] }}</x-filament::button>
            @else
                <x-filament::button tag="a" :href="$message['url']" size="sm" data-geste-du-message="{{ $message['etat'] }}">{{ $message['bouton'] }}</x-filament::button>
            @endif
        </div>
    @endif
</div>
