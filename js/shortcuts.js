// =============================================================================
// RACCOURCIS CLAVIER
// =============================================================================
// L'app n'en avait aucun pour la lecture : seules deux touches Échap fermaient
// une modale. Piloter la lecture demandait donc systématiquement la souris.
//
// Règle de base : un raccourci ne se déclenche jamais pendant une saisie. Sans
// cette garde, taper « Space » dans le champ de recherche mettrait la lecture en
// pause au lieu d'insérer une espace.

function isTypingContext(target) {
    if (!target) return false;
    const tag = target.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable;
}

// Un raccourci portant une touche de modification appartient au navigateur ou au
// système (Ctrl+F, Cmd+R…) : on ne les intercepte pas, sauf Ctrl/Cmd+K qui est la
// convention établie pour « aller à la recherche ».
function hasForeignModifier(e) {
    return e.altKey || e.metaKey || e.ctrlKey;
}

function focusSearch() {
    const input = document.getElementById('searchInput');
    if (!input) return;
    input.focus();
    input.select();
}

function seekBy(seconds) {
    if (!audio || !audio.duration) return;
    audio.currentTime = Math.min(audio.duration, Math.max(0, audio.currentTime + seconds));
}

function nudgeVolume(delta) {
    if (!audio) return;
    const next = Math.min(1, Math.max(0, audio.volume + delta));
    updateVolume(next);
    // updateVolume() n'écrit pas dans localStorage (il est aussi appelé par le
    // fondu du minuteur de sommeil, qui ne doit pas écraser le volume choisi) :
    // ici c'est bien un réglage utilisateur, on le persiste donc explicitement.
    localStorage.setItem('purpleMusicVolume', next);
}

document.addEventListener('keydown', (e) => {
    // Ctrl/Cmd+K : convention "palette de commande" — accepté même hors des gardes
    // ci-dessous, pour pouvoir rebondir d'un champ à l'autre.
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        focusSearch();
        return;
    }

    // Echap ferme les surfaces de lecture. Elles ne sont pas des modales et
    // n'etaient donc couvertes par aucun gestionnaire : le lecteur plein ecran et
    // les panneaux lateraux ne se fermaient qu'a la souris, sur leur propre bouton.
    // Traite AVANT la garde de saisie : on doit pouvoir s'echapper meme depuis un
    // champ situe a l'interieur d'un panneau.
    if (e.key === 'Escape') {
        if (window.Alpine && Alpine.store('ui').activeModal) return; // la modale gere son propre Echap
        const fp = document.getElementById('full-player');
        if (fp && fp.classList.contains('active')) { closeFullPlayer(); return; }
        const dp = document.getElementById('desktop-player');
        if (dp && dp.classList.contains('active')) { closeDesktopPlayer(); return; }
        if (window.Alpine && Alpine.store('ui').lyricsPanelOpen) { closeLyricsPanel(); return; }
        if (queuePanel && queuePanel.classList.contains('open')) { toggleQueue(); return; }
        return;
    }

    if (isTypingContext(e.target)) return;
    if (hasForeignModifier(e)) return;

    // Une modale ouverte capte le clavier : piloter la lecture en arrière-plan
    // pendant qu'on remplit un formulaire serait déroutant. Échap reste géré par
    // les modales elles-mêmes.
    if (window.Alpine && Alpine.store('ui').activeModal) return;

    switch (e.key) {
        case ' ':
        case 'k':
        case 'K':
            e.preventDefault(); // sinon la page défile d'un écran
            togglePlay();
            break;
        case 'ArrowRight':
            e.preventDefault();
            seekBy(5);
            break;
        case 'ArrowLeft':
            e.preventDefault();
            seekBy(-5);
            break;
        case 'ArrowUp':
            e.preventDefault();
            nudgeVolume(0.05);
            break;
        case 'ArrowDown':
            e.preventDefault();
            nudgeVolume(-0.05);
            break;
        case 'n':
        case 'N':
            nextTrack();
            break;
        case 'p':
        case 'P':
            prevTrack();
            break;
        case 'm':
        case 'M':
            toggleMute();
            break;
        case 's':
        case 'S':
            toggleShuffle();
            break;
        case 'r':
        case 'R':
            toggleLoop();
            break;
        case '/':
            e.preventDefault();
            focusSearch();
            break;
        case '?':
            openModal('shortcutsModal');
            break;
        default:
            break;
    }
});
