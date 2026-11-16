// La page du lien d'une démonstration (/d, voir EntrerDansLaDemonstrationController) :
// le secret, après « # », n'est jamais envoyé par le navigateur au serveur. Ce
// fichier le lit, efface aussitôt l'adresse, puis l'envoie dans le corps de
// POST /entrer. Un témoin d'une autre démonstration fait d'abord demander
// laquelle ouvrir.
document.addEventListener('DOMContentLoaded', function () {
    var secret = window.location.hash.slice(1);
    var etat = document.getElementById('etat');
    var question = document.getElementById('question');

    window.history.replaceState(null, '', window.location.pathname);

    function entrer(confirme) {
        etat.textContent = 'Ouverture de votre démonstration…';

        fetch('/entrer', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ secret: secret, confirme: confirme })
        }).then(function (reponse) {
            return reponse.json().then(function (corps) {
                return { statut: reponse.status, corps: corps };
            });
        }).then(function (reponse) {
            if (reponse.statut === 200 && typeof reponse.corps.aller === 'string') {
                secret = '';
                window.location.replace(reponse.corps.aller);

                return;
            }

            if (reponse.statut === 409 && typeof reponse.corps.question === 'string') {
                etat.textContent = '';
                document.getElementById('texte-de-la-question').textContent = reponse.corps.question;
                question.hidden = false;

                return;
            }

            secret = '';
            etat.textContent = reponse.statut === 429 && typeof reponse.corps.message === 'string'
                ? reponse.corps.message
                : 'Ce lien ne mène à aucune démonstration ouverte. Il a pu être renouvelé par le propriétaire de la société, ou la démonstration est terminée.';
        }).catch(function () {
            etat.textContent = 'La démonstration ne répond pas. Réessayez dans un instant.';
        });
    }

    document.getElementById('ouvrir-celle-ci').addEventListener('click', function () {
        question.hidden = true;
        entrer(true);
    });

    document.getElementById('garder-l-autre').addEventListener('click', function () {
        secret = '';
        window.location.replace('/');
    });

    entrer(false);
});
