<?php
// Oger Planning - Point d'entree principal
$assetPrefix = $assetPrefix ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Oger Planning</title>
  <link rel="stylesheet" href="<?= htmlspecialchars($assetPrefix, ENT_QUOTES, 'UTF-8') ?>css/style.css">
</head>
<body>
  <header class="topbar">
    <a class="brand" href="./" aria-label="Oger Planning, accueil">oger<span>·</span></a>
    <nav aria-label="Navigation principale">
      <a class="nav-active" href="#planning-builder-section" aria-current="page">Plannings</a>
      <a href="#team-filter">Équipes</a>
    </nav>
    <span class="workspace-name">Organisation des équipes</span>
    <span class="avatar" aria-label="Oger Planning">OG</span>
  </header>
  <main id="planning-builder-section">
    <div class="page-heading">
      <div><p class="eyebrow">VOTRE ESPACE DE TRAVAIL</p><h1>Le planning de vos équipes<span>.</span></h1></div>
      <span class="draft-badge"><span></span> Proposition · non publiée</span>
    </div>
    <div class="toolbar" aria-label="Contrôles du planning">
      <div class="week-picker">
        <button id="previous-week" class="icon-button" aria-label="Semaine précédente">‹</button>
        <div class="week-date"><label id="week-range" for="planning-week-start">Semaine</label><input id="planning-week-start" type="date" aria-label="Choisir une semaine" /></div>
        <button id="next-week" class="icon-button" aria-label="Semaine suivante">›</button>
      </div>
      <button id="current-week" class="secondary-button">Aujourd’hui</button>
      <span class="toolbar-divider"></span>
      <label class="search-box"><span aria-hidden="true">⌕</span><input id="employee-search" type="search" placeholder="Rechercher un collaborateur…" aria-label="Rechercher un collaborateur" /></label>
      <div class="toolbar-actions"><button id="compact-view" class="secondary-button" aria-pressed="false">Vue compacte</button><button id="print-planning" class="secondary-button">Imprimer</button><button id="load-plannings-btn" class="primary-button">Calculer la proposition <span aria-hidden="true">↗</span></button></div>
    </div>
    <div class="workspace">
      <aside class="sidebar" aria-label="Filtres du planning">
        <label class="section-label" for="location-filter">ÉTABLISSEMENT</label>
        <select id="location-filter"><option value="">Chargement…</option></select>
        <div class="sidebar-heading"><h2>Équipes</h2><span id="team-count" class="count-badge">0</span></div>
        <div id="team-filter" class="team-filters" tabindex="-1"></div>
        <div class="sidebar-note"><span class="note-icon" aria-hidden="true">✧</span><strong>Une semaine bien organisée</strong><p>Calculez une proposition à partir des contrats et des besoins de vos équipes.</p></div>
        <div class="legend"><span class="section-label">LÉGENDE</span><p><i class="legend-work"></i>Créneau proposé</p><p><i class="legend-rest"></i>Repos hebdomadaire</p></div>
      </aside>
      <section class="planning-panel" aria-label="Planning hebdomadaire" aria-busy="false">
        <div class="calendar-heading"><div><h2>Planning hebdomadaire</h2><span id="planning-summary">Votre semaine, en un coup d’œil</span></div><span class="view-label">Vue par collaborateur</span></div>
        <div id="planning-loading" class="notice" role="status" style="display:none">Calcul de la proposition en cours…</div>
        <div id="planning-error" class="notice error-notice" role="alert" style="display:none"></div>
        <div id="plannings-container"></div>
        <details id="planning-alerts" class="planning-alerts" hidden><summary id="alerts-summary">Points à vérifier</summary><ul id="alerts-list"></ul></details>
        <footer class="calendar-footer"><span><i></i> Proposition de planning</span><span>Les horaires restent à valider avant publication.</span></footer>
      </section>
    </div>
  </main>

  <script src="<?= htmlspecialchars($assetPrefix, ENT_QUOTES, 'UTF-8') ?>js/app.js"></script>
</body>
</html>
