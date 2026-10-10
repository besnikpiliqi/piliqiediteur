{{--
    Identité du magasin à gauche (voir IdentiteMagasin), sans ce qu'il n'a
    pas renseigné, le pays fermant son adresse : raison sociale et forme
    juridique, suivies du capital social quand il est renseigné (H2), puis
    l'immatriculation après le SIREN et le SIRET ; nature et références du
    document à droite.

    @param \App\Domain\Tenancy\Models\Magasin $magasin
    @param string $titre
    @param string $classeTitre classe CSS en plus de titre-document
    @param list<string> $details une ligne chacun : numéro, dates...
--}}
@php
    $adresse = array_filter([
        $magasin->adresse,
        $magasin->complement_adresse,
        trim("{$magasin->code_postal} {$magasin->ville}"),
    ], fn (?string $ligne): bool => filled($ligne));
    $capital = $magasin->capital_social === null ? null : 'au capital de '.number_format((float) $magasin->capital_social, 2, ',', ' ').' €';
    $lignesMagasin = array_filter([
        implode(' ', array_filter([$magasin->raison_sociale, $magasin->forme_juridique, $capital], filled(...))),
        ...$adresse,
        $adresse !== [] ? $magasin->pays : null,
        $magasin->siren ? "SIREN : {$magasin->siren}" : null,
        $magasin->siret ? "SIRET : {$magasin->siret}" : null,
        $magasin->immatriculation,
        $magasin->numero_tva ? "TVA intracommunautaire : {$magasin->numero_tva}" : null,
        implode(' · ', array_filter([$magasin->telephone ? "Tél. {$magasin->telephone}" : null, $magasin->email])),
    ], fn (?string $ligne): bool => filled($ligne));
@endphp
<table class="en-tete">
    <tr>
        <td>
            <div class="magasin-nom">{{ $magasin->nom }}</div>
            {!! collect($lignesMagasin)->map(fn (string $ligne): string => e($ligne))->implode('<br>') !!}
        </td>
        <td>
            <div class="titre-document {{ $classeTitre ?? '' }}">{{ $titre }}</div>
            <div class="details-document">
                {!! collect($details)->map(fn (string $detail): string => e($detail))->implode('<br>') !!}
            </div>
        </td>
    </tr>
</table>
