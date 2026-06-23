---
description: "Use when applying general planning rules and resolving scheduling tradeoffs in natural language."
name: "Regles planning"
applyTo: "**"
---

## Regles pour organiser les plannings

- Priorite 1: Chaque planinng (boulangerie, patisserie, traiteur, vente, leroy merlin) a un minimum de besoins en personnes a respecter en fonction de l'heure et du jour. Ces informations sont definies dans les fichiers csv (un CSV par planning fiche) dans data/planning/besoins/. Voir le fichier README.md pour plus de details.
- Priorite 2: regles RH à respecter, définis ci-dessous.
- Priorite 3: preferences individuelles et confort d organisation.

# Regles RH pour toute l'enseigne

Ces regles definissent les principes generaux a appliquer lors de la creation ou de l'ajustement des plannings.

- Un employé ne peut pas travailler plus de 6 jours consecutifs.
- Un employé ne peut pas travailler plus de 10 heures par jour.
- Un employé doit prendre une demi heure de pause toutes les 6 heures de travail.
- Un employé doit avoir au moins 11 heures de repos entre deux jours de travail.
- Si un employé a posé un jour de repos ou d'indisponibilité, de préference il ne peut pas etre programme sur ce creneau, sauf s'il n'y a pas d'autre solution
- Si possible, les employés prennent deux jours de repos consectuifs par semaine, mais ce n'est pas obligatoire.
- Les employés mienurs qui sont en contrat d'apprentisage ne peuvent pas commencer avant 6h00 am.
- Les employés majeurs qui sont en contrat d'apprentisage peuvent commencer à partir de 4h00 am.
- L'ouverture du magasin (boulangerie, traiteur, vente et patisserie) est à 6h00 am et la fermeture est à 20h00 pm. Du lundi au samedi. Le dimanche, l'ouverture est à 7h00 am et la fermeture est à 20h00 pm.
- L'ouverture du magasin leroy merlin est à 7h00 am et la fermeture est à 20h00 pm. Du lundi au samedi. Le dimanche, l'ouverture est à 8h00 am et la fermeture est à 18h00 pm.
- Les heures d'ouverture et de fermetures des magasins ne sont pas répresentatives des heures d'arrivé ni de départ des employés.
