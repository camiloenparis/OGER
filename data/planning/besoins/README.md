# Besoins horaires CSV

Ce dossier contient les besoins horaires source pour la generation des plannings.

Un fichier CSV par fiche de planning:

- boulangerie.csv
- patisserie.csv
- traiteur.csv
- vente.csv
- leroy-merlin.csv

Format attendu (exemple de colonnes):

- magasin
- jour
- heure_debut
- heure_fin
- min_personnes
- max_personnes

Notes:

- Conserver un format d heure coherent (ex: HH:MM).
- Eviter les cellules vides sur les plages critiques.
- Les regles de planning et preferences internes sont dans .github/instructions/.
