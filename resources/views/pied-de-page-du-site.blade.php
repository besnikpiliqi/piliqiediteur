{{--
    La version servie et le site, en bas de chaque page des deux sites (voir
    VersionServie). Son style est une feuille du site, jamais un attribut
    style : la page du lien d'une démonstration (/d) n'admet que les
    feuilles du site (style-src 'self').
--}}
<link rel="stylesheet" href="{{ asset('css/app/pied-du-site.css') }}">
<footer class="piliqi-pied-du-site" data-pied-du-site>{{ \App\Domain\Demonstration\VersionServie::texte() }}</footer>
