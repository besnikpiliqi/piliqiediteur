{{--
    Un lien de téléchargement qui ne sert pas son fichier : celui des
    documents par période (voir TelechargerLesDocumentsController),
    introuvable pour ce compte, encore en préparation, en échec, expiré, ou
    dont le compte ne télécharge plus les documents ; celui d'une pièce du
    fournisseur (voir TelechargerUnePieceDuFournisseurController), bloquée
    par l'antivirus ou en attente d'un antivirus qui ne répond pas. Le lien
    mène à l'accueil, qui mène le compte connecté à son espace.

    @param string $message ce qui s'est passé et ce qu'il faut faire
--}}
@extends('errors.mise-en-page')

@section('titre', 'Téléchargement indisponible')

@section('texte', $message)

@section('lien')
    <a class="bouton" href="{{ url('/') }}">Revenir à l'accueil</a>
@endsection
