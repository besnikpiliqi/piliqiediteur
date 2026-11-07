{{--
    Le lien d'un téléchargement des documents par période qui ne sert pas
    son fichier (voir TelechargerLesDocumentsController) : introuvable pour
    ce compte, encore en préparation, en échec, expiré, ou dont le compte
    ne télécharge plus les documents. Le lien mène à l'accueil, qui mène
    le compte connecté à son espace.

    @param string $message ce qui s'est passé et ce qu'il faut faire
--}}
@extends('errors.mise-en-page')

@section('titre', 'Téléchargement indisponible')

@section('texte', $message)

@section('lien')
    <a class="bouton" href="{{ url('/') }}">Revenir à l'accueil</a>
@endsection
