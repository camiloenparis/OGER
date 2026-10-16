Oger Planning
=============

Architecture
------------
public/ : page d'accueil unique, CSS, JavaScript et endpoints API.
engine/ : seul moteur de planning, generation et regles metier.
data/planning/besoins/ : besoins en effectif, un CSV par equipe.
.github/instructions/ : consignes de planning et preferences internes.
.local/secrets/ : cles API locales (non versionnees).

index.php et api/*.php a la racine sont des points d'entree de
compatibilite : ils chargent les fichiers de public/ sans dupliquer le code.
Le dossier src/ contenait un second moteur non utilise et a ete retire.

Lancement local (PHP 8.1 ou plus, extensions curl et mbstring recommandees)
------------------------------------------------------------------------
Depuis le dossier OGER : php -S localhost:8000 -t public
Ouvrir http://localhost:8000/ dans le navigateur.
Le site fonctionne aussi quand l'hebergeur sert la racine du projet.

Modifier le planning
-------------------
Besoins : data/planning/besoins/*.csv
Generation : engine/ProposalGenerator.php
Regles : engine/rules/ ; enregistrement dans engine/bootstrap.php
API : public/api/ ; interface : public/index.php, public/js/, public/css/

Configuration
-------------
Pour que ce projet fonctionne correctement, il faut ajouter les cles API

.local/secrets/combo_api_key_beauvais.txt
.local/secrets/combo_api_key_gravigny.txt

dans chaque fichier, la clé API c'est juste dans un ligne sans variable ni rien d'ajouter

exemple : 
TRcv8zvGQWCRWEWERCQWEDASD
