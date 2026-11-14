{{--
    La page « Page expirée » (HTTP 419) d'un formulaire envoyé avec un jeton
    que la session ne connaît plus (voir ReponseAUnePageExpiree) : rien n'a
    été enregistré ; le lien recharge la page du formulaire, avec un jeton
    neuf.

    @param string $retour l'adresse qui recharge la page du formulaire, ou l'accueil
--}}
@extends('errors.mise-en-page')

@section('titre', 'Page expirée')

@section('texte', 'Cette page est restée ouverte trop longtemps, votre session a expiré, ou un autre compte s\'est connecté dans ce navigateur : votre envoi n\'a pas été enregistré. Rechargez la page, puis recommencez.')

@section('lien')
    <a class="bouton" href="{{ $retour }}">Recharger la page</a>
@endsection
