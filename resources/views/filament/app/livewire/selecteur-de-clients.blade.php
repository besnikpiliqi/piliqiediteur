{{-- Ouvert dans la fenêtre d'une action du formulaire de la page : Entrée dans la recherche validerait ce formulaire et fermerait la fenêtre. --}}
<div x-data x-on:keydown.enter="if ($event.target.tagName === 'INPUT') $event.preventDefault()">
    {{ $this->table }}

    <x-filament-actions::modals />
</div>
