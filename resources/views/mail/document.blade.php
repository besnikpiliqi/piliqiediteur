{{--
    Voir DocumentMail. Le magasin, pas le logiciel, signe l'e-mail : $nom,
    celui du vendeur que le PDF imprime, en en-tête et en signature, les
    coordonnées en pied. Aucune indentation : le Markdown en ferait du
    code.

    Chaque texte venu du magasin ou de son message est un bloc <p> sur sa
    propre ligne, suivi d'une ligne vide : un bloc HTML que le Markdown
    laisse tel quel. Un « [lien](...) », un « # titre » ou des
    « *astérisques* » arrivent donc comme ils ont été écrits, jamais
    transformés en lien, en titre ou en italique. La version texte de
    l'e-mail retire ces balises (voir le gabarit text/layout de Laravel).
--}}
<x-mail::layout>
<x-slot:header>
<tr>
<td class="header">
{{ $nom }}
</td>
</tr>
</x-slot:header>

<p>Bonjour,</p>

@if ($introduction)
<p>{{ $introduction }}</p>

@endif
@foreach ($paragraphes as $paragraphe)
<p>{{ $paragraphe }}</p>

@endforeach
<p>Cordialement,<br>
{{ $nom }}</p>

<x-slot:footer>
<x-mail::footer>
@foreach ($coordonnees as $ligne)
<p>{{ $ligne }}</p>

@endforeach
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
