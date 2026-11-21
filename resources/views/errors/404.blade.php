{{--
    La page « Page introuvable » (HTTP 404), dont celle d'une page de magasin
    qu'ouvre le compte connecté dans ce navigateur sans y avoir accès (le
    refus de Filament, qui ne dit pas si le magasin existe) : par exemple un
    onglet resté ouvert sur le magasin d'un compte, quand un autre compte
    s'est connecté dans un autre onglet. Le lien mène à l'accueil, qui mène
    le compte connecté à son espace, ou à la connexion.
--}}
@extends('errors.mise-en-page')

@section('titre', 'Page introuvable')

@section('texte', 'Cette page n\'existe pas, ou le compte connecté dans ce navigateur n\'y a pas accès : quand un autre compte se connecte dans un onglet, c\'est lui qui est connecté dans tous les onglets du navigateur.')

@section('lien')
    <a class="bouton" href="{{ url('/') }}">Revenir à l'accueil</a>
@endsection
