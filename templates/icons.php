<?php
/**
 * Sprite SVG — définitions d'icônes réutilisables.
 *
 * Inclus UNE fois en haut du <body> ; chaque icône est ensuite référencée par
 * <svg class="ico"><use href="#ico-nom"></use></svg>.
 *
 * Avant : les mêmes chemins SVG (lecture, suivant, aléatoire, boucle, paroles,
 * réglages...) étaient recopiés en dur 3 à 4 fois chacun — une fois par surface
 * de lecteur (mini-barre, plein écran mobile, grand lecteur desktop) plus le
 * header et la nav mobile. Corriger une icône demandait de retrouver toutes ses
 * copies, et elles avaient déjà divergé (tailles/viewBox différentes).
 *
 * aria-hidden + focusable="false" sur le conteneur : le sprite ne doit jamais
 * être annoncé ni recevoir le focus (IE/Edge legacy rendaient les <svg> focusables).
 */
?>
<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true" focusable="false">
    <!-- Navigation -->
    <symbol id="ico-library" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></symbol>
    <symbol id="ico-playlist" viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></symbol>
    <symbol id="ico-artist" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></symbol>
    <symbol id="ico-album" viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 13.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7zm0-5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3z"/></symbol>
    <symbol id="ico-admin" viewBox="0 0 24 24"><path d="M19.4 13c0-.3.1-.6.1-1s0-.7-.1-1l2.1-1.7c.2-.2.2-.4.1-.6l-2-3.5c-.1-.2-.3-.3-.6-.2l-2.5 1c-.5-.4-1.1-.7-1.7-1l-.4-2.7c0-.2-.2-.4-.5-.4h-4c-.3 0-.5.2-.5.4l-.4 2.7c-.6.2-1.2.6-1.7 1l-2.5-1c-.2-.1-.5 0-.6.2l-2 3.5c-.1.2-.1.5.1.6L4.6 11c-.1.3-.1.6-.1 1s0 .7.1 1l-2.1 1.7c-.2.2-.2.4-.1.6l2 3.5c.1.2.3.3.6.2l2.5-1c.5.4 1.1.7 1.7 1l.4 2.7c0 .2.2.4.5.4h4c.3 0 .5-.2.5-.4l.4-2.7c.6-.2 1.2-.6 1.7-1l2.5 1c.2.1.5 0 .6-.2l2-3.5c.1-.2.1-.5-.1-.6L19.4 13zM12 15.5c-1.9 0-3.5-1.6-3.5-3.5s1.6-3.5 3.5-3.5 3.5 1.6 3.5 3.5-1.6 3.5-3.5 3.5z"/></symbol>
    <symbol id="ico-history" viewBox="0 0 24 24"><path d="M13 3a9 9 0 0 0-9 9H1l3.9 3.9.1.2L9 12H6a7 7 0 1 1 2.1 5l-1.4 1.4A9 9 0 1 0 13 3zm-1 5v5l4.3 2.5.7-1.2-3.5-2.1V8H12z"/></symbol>
    <symbol id="ico-stats" viewBox="0 0 24 24"><path d="M5 21h3V10H5v11zm5.5 0h3V3h-3v18zM16 21h3v-7h-3v7z"/></symbol>

    <!-- Actions de chrome -->
    <symbol id="ico-queue" viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></symbol>
    <symbol id="ico-playlist-add" viewBox="0 0 24 24"><path d="M14 10H2v2h12v-2zm0-4H2v2h12V6zM2 16h8v-2H2v2zm16-4v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4z"/></symbol>
    <symbol id="ico-upload" viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></symbol>
    <symbol id="ico-logout" viewBox="0 0 24 24"><path d="M17 7l-1.4 1.4L18.2 11H8v2h10.2l-2.6 2.6L17 17l5-5-5-5zM4 5h8V3H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8v-2H4V5z"/></symbol>
    <symbol id="ico-search" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></symbol>
    <symbol id="ico-sort" viewBox="0 0 24 24"><path d="M3 4c0-.55.45-1 1-1h10c.55 0 1 .45 1 1v1.5c0 .28-.11.53-.3.71L10 10.9v5.2c0 .28-.11.53-.29.71l-2 2c-.18.18-.43.29-.71.29s-.53-.11-.71-.29A.996.996 0 0 1 6 18.1v-7.2L3.3 6.21A.996.996 0 0 1 3 5.5V4z"/><rect x="16" y="5" width="6" height="2" rx="1"/><rect x="16" y="11" width="6" height="2" rx="1"/><rect x="16" y="17" width="6" height="2" rx="1"/></symbol>
    <symbol id="ico-sidebar-toggle" viewBox="0 0 24 24"><path d="M3 5h18v2H3V5zm0 6h11v2H3v-2zm0 6h18v2H3v-2z"/></symbol>

    <!-- Transport -->
    <symbol id="ico-play" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></symbol>
    <symbol id="ico-pause" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></symbol>
    <symbol id="ico-prev" viewBox="0 0 24 24"><path d="M6 6h2v12H6V6zm3.5 6l8.5 6V6l-8.5 6z"/></symbol>
    <symbol id="ico-next" viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></symbol>
    <symbol id="ico-shuffle" viewBox="0 0 24 24"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></symbol>
    <symbol id="ico-loop" viewBox="0 0 24 24"><path d="M7 7h10v3l4-4-4-4v3H5v6h2V7zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2v4z"/></symbol>
    <symbol id="ico-lyrics" viewBox="0 0 24 24"><path d="M4 6h16v2H4V6zm0 5h16v2H4v-2zm0 5h10v2H4v-2z"/></symbol>
    <symbol id="ico-volume" viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 7.97v8.05A4.47 4.47 0 0 0 16.5 12zM14 3.23v2.06a7 7 0 0 1 0 13.42v2.06a9 9 0 0 0 0-17.54z"/></symbol>
    <symbol id="ico-volume-mute" viewBox="0 0 24 24"><path d="M16.5 12A4.5 4.5 0 0 0 14 7.97v2.21l2.45 2.45c.03-.2.05-.41.05-.63zM19 12c0 .94-.2 1.82-.54 2.64l1.51 1.51A8.8 8.8 0 0 0 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06a8.94 8.94 0 0 0 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/></symbol>
    <symbol id="ico-eq" viewBox="0 0 24 24"><path d="M7 20h2v-8H7v8zm0-10h2V4H7v6zm4 10h2V4h-2v16zm4 0h2v-6h-2v6zm0-8h2V4h-2v8z"/></symbol>
    <symbol id="ico-sleep" viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36A5.39 5.39 0 0 1 16.5 13a5.5 5.5 0 0 1-5.5-5.5c0-1.72.79-3.25 2.03-4.26A9.1 9.1 0 0 0 12 3z"/></symbol>
    <symbol id="ico-cast" viewBox="0 0 24 24"><path d="M1 18v3h3a3 3 0 0 0-3-3zm0-4v2a5 5 0 0 1 5 5h2a7 7 0 0 0-7-7zm0-4v2a9 9 0 0 1 9 9h2A11 11 0 0 0 1 10zm20-7H3a2 2 0 0 0-2 2v3h2V5h18v14h-7v2h7a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/></symbol>

    <!-- Actions sur un élément -->
    <symbol id="ico-heart" viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></symbol>
    <symbol id="ico-trash" viewBox="0 0 24 24"><path d="M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></symbol>
    <symbol id="ico-edit" viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></symbol>
    <symbol id="ico-more" viewBox="0 0 24 24"><path d="M12 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm0 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm0 6a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></symbol>
    <symbol id="ico-plus" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></symbol>
    <symbol id="ico-check" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></symbol>
    <symbol id="ico-close" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></symbol>
    <symbol id="ico-play-next" viewBox="0 0 24 24"><path d="M4 6h10v2H4V6zm0 4h10v2H4v-2zm0 4h7v2H4v-2zm11-1.5V21l6-4.25L15 12.5z"/></symbol>
    <symbol id="ico-queue-add" viewBox="0 0 24 24"><path d="M14 10H2v2h12v-2zm0-4H2v2h12V6zM2 16h8v-2H2v2zm16-4v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4z"/></symbol>
    <symbol id="ico-drag" viewBox="0 0 24 24"><path d="M9 5h2v2H9V5zm4 0h2v2h-2V5zM9 9h2v2H9V9zm4 0h2v2h-2V9zm-4 4h2v2H9v-2zm4 0h2v2h-2v-2zm-4 4h2v2H9v-2zm4 0h2v2h-2v-2z"/></symbol>
    <symbol id="ico-download" viewBox="0 0 24 24"><path d="M5 20h14v-2H5v2zM19 9h-4V3H9v6H5l7 7 7-7z"/></symbol>
    <symbol id="ico-share" viewBox="0 0 24 24"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81a3 3 0 1 0-3-3c0 .24.04.47.09.7L8.04 9.81A3 3 0 1 0 6 15c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65a2.92 2.92 0 1 0 2.92-2.92z"/></symbol>

    <!-- Chevrons / directions -->
    <symbol id="ico-chevron-left" viewBox="0 0 24 24"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></symbol>
    <symbol id="ico-chevron-right" viewBox="0 0 24 24"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></symbol>
    <symbol id="ico-chevron-down" viewBox="0 0 24 24"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></symbol>
    <symbol id="ico-chevron-up" viewBox="0 0 24 24"><path d="M12 8l-6 6 1.41 1.41L12 10.83l4.59 4.58L18 14z"/></symbol>

    <!-- États -->
    <symbol id="ico-music-off" viewBox="0 0 24 24"><path d="M4.27 3L3 4.27l9 9v.28c-.59-.34-1.27-.55-2-.55a4 4 0 1 0 4 4v-1.73l4.73 4.73L20 18.73 4.27 3zM14 7h4V3h-6v5.61l2 2V7z"/></symbol>
    <symbol id="ico-wifi-off" viewBox="0 0 24 24"><path d="M22.99 9C19.15 5.16 13.8 3.76 8.84 4.78l2.52 2.52c3.47-.17 6.99 1.05 9.63 3.7l2-2zM2.1 5.27L4.93 8.1A15.7 15.7 0 0 0 1 9l2 2a12.9 12.9 0 0 1 3.68-2.02l2.23 2.23A9.9 9.9 0 0 0 5 13l2 2a7 7 0 0 1 4.11-1.98l6.62 6.62 1.27-1.27L3.37 4 2.1 5.27zM9 17l3 3 3-3a4.24 4.24 0 0 0-6 0z"/></symbol>
</svg>
