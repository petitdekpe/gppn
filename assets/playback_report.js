/*
 * Vidéos défectueuses : quand la dernière <source> d'une vidéo échoue (aucune
 * n'a pu être lue), le navigateur le signale au serveur, qui vérifie le
 * fichier avec ffmpeg et le masque s'il est vraiment illisible (voir
 * VideoController::playbackError). L'événement « error » ne remonte pas :
 * il est capté en phase de capture. Un seul signalement par fichier et par page.
 */
const reported = new Set();

document.addEventListener('error', (event) => {
    const source = event.target;
    if (!(source instanceof HTMLSourceElement) || !source.dataset.playbackReport) {
        return;
    }
    const url = source.dataset.playbackReport;
    if (reported.has(url)) {
        return;
    }
    reported.add(url);

    if (!navigator.sendBeacon?.(url)) {
        fetch(url, { method: 'POST', keepalive: true }).catch(() => {});
    }
}, true);
