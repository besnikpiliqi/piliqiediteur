{{-- Voir AlerteEditeurMail. Aucune indentation : le Markdown en ferait du code. --}}
<x-mail::message>
# {{ $objet }}

@foreach ($lignes as $ligne)
{{ $ligne }}

@endforeach
{{ config('app.name') }}
</x-mail::message>
