<?php $assetPrefix = $assetPrefix ?? ''; ?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Administration · Oger Planning</title>
  <link rel="stylesheet" href="<?= htmlspecialchars($assetPrefix, ENT_QUOTES, 'UTF-8') ?>css/style.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetPrefix, ENT_QUOTES, 'UTF-8') ?>css/admin.css">
</head>
<body>
  <header class="topbar">
    <a class="brand" href="index.php" aria-label="Oger Planning, accueil">oger<span>·</span></a>
    <nav aria-label="Navigation principale"><a href="index.php">Plannings</a><a class="nav-active" href="admin.php" aria-current="page">Admin</a></nav>
    <span class="workspace-name">Paramètres du planning</span><span class="avatar" aria-hidden="true">OG</span>
  </header>
  <main>
    <div class="page-heading"><div><p class="eyebrow">ADMINISTRATION</p><h1>Les règles de vos équipes<span>.</span></h1></div><button id="admin-logout" class="secondary-button" hidden>Se déconnecter</button></div>
    <p id="admin-message" class="admin-message" role="status" aria-live="polite">Chargement…</p>
    <section id="admin-login" class="admin-card login-card" hidden>
      <h2>Accès administrateur</h2><p>Connectez-vous pour modifier les besoins et les consignes de planning.</p>
      <p id="admin-setup" hidden>Pour activer cet accès, créez <code>.local/secrets/admin_password.txt</code> avec un mot de passe de votre choix, puis rechargez cette page.</p>
      <form id="login-form"><label for="admin-password">Mot de passe</label><input type="password" id="admin-password" autocomplete="current-password" required><button class="primary-button" type="submit">Se connecter</button></form>
    </section>
    <div id="admin-workspace" hidden>
      <div class="admin-intro"><p>Modifiez vos sources, préparez une mise à jour, puis appliquez-la au prochain calcul de planning.</p><span id="admin-version" class="draft-badge"></span></div>
      <div class="admin-layout">
        <aside class="sidebar admin-sidebar" aria-label="Sources de configuration">
          <span class="section-label">VOS TROIS SOURCES</span>
          <button class="team-button active" data-section="needs" aria-pressed="true">Besoins horaires</button>
          <button class="team-button" data-section="rules" aria-pressed="false">Règles de planning</button>
          <button class="team-button" data-section="preferences" aria-pressed="false">Préférences internes</button>
          <div class="sidebar-note"><strong>Votre moteur reste disponible</strong><p>Un brouillon ou une proposition IA n’affecte pas les plannings. Seule l’activation met les nouvelles règles en service.</p></div>
        </aside>
        <section class="admin-card admin-editor" aria-label="Éditeur des sources">
          <div class="admin-editor-heading"><div><h2 id="editor-title">Besoins horaires</h2><p id="editor-description"></p></div><span id="edit-status" class="count-badge">Enregistré</span></div>
          <div id="needs-editor"><label for="needs-team">Équipe</label><select id="needs-team"><option value="boulangerie">Boulangerie</option><option value="patisserie">Pâtisserie</option><option value="traiteur">Traiteur</option><option value="vente">Vente</option><option value="leroy-merlin">Vente Leroy Merlin</option></select><div id="needs-grid" class="needs-grid"></div></div>
          <div id="text-editor" hidden><label for="instructions-text">Consignes en français</label><textarea id="instructions-text" rows="20" spellcheck="true"></textarea></div>
          <div class="admin-editor-actions"><button id="save-draft" class="secondary-button">Enregistrer le brouillon</button><button id="generate-update" class="primary-button">Préparer la mise à jour avec OpenAI ↗</button></div>
        </section>
      </div>
      <section class="admin-card update-card">
        <div class="admin-editor-heading"><div><h2>Mise à jour du moteur</h2><p id="openai-status"></p></div><button id="restore-version" class="secondary-button" disabled>Restaurer la version précédente</button></div>
        <p class="admin-explanation">Lors de la préparation, vos besoins et les deux documents de consignes sont envoyés à OpenAI. La proposition porte sur les paramètres du moteur. Les nouvelles consignes qui nécessitent une évolution de son algorithme sont signalées avant activation.</p>
        <p id="candidate-empty">Aucune proposition en attente. Modifiez vos sources puis préparez une mise à jour.</p>
        <div id="candidate-preview" hidden><h3>Proposition à vérifier</h3><p id="candidate-summary"></p><ul id="candidate-unsupported" class="unsupported-list"></ul><details><summary>Voir les paramètres proposés</summary><pre id="candidate-json"></pre></details><button id="activate-update" class="primary-button" disabled>Appliquer cette version au moteur</button></div>
      </section>
    </div>
  </main>
  <script src="<?= htmlspecialchars($assetPrefix, ENT_QUOTES, 'UTF-8') ?>js/admin.js"></script>
</body>
</html>
