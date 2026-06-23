<?php
// Point d'entree pour un hebergeur qui pointe sur la racine du projet.
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Oger Planning</title>
  <link rel="stylesheet" href="public/css/style.css">
</head>
<body>
  <div class="container">
    <h1>Oger Planning</h1>

    <section id="planning-builder-section">
      <h2>Proposition de planning par equipe</h2>
      <div class="plannings-controls">
        <div class="control-group">
          <label for="location-filter">Location :</label>
          <select id="location-filter">
            <option value="">Chargement...</option>
          </select>
        </div>
        <div class="control-group">
          <label for="planning-week-start">Semaine (lundi) :</label>
          <input id="planning-week-start" type="date" />
        </div>
        <button id="load-plannings-btn">Calculer la proposition</button>
      </div>
      <div id="planning-loading" style="display: none;">Chargement des plannings...</div>
      <div id="planning-error" style="display: none;"></div>
      <div id="plannings-container" style="display: none;"></div>
    </section>
  </div>

  <script src="public/js/app.js"></script>
</body>
</html>