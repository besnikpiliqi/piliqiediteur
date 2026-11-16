// La page du billet d'entrée du site réel (voir OuvrirMaDemonstrationController) :
// le formulaire du billet part seul vers le site de démonstration, sans aucun
// script écrit dans la page.
document.addEventListener('DOMContentLoaded', function () {
    var formulaire = document.getElementById('billet-d-entree');

    if (formulaire !== null) {
        formulaire.submit();
    }
});
