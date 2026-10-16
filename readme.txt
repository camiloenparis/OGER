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
Pour un hebergement, configurer public/ comme racine web. Les fichiers
.htaccess protegent aussi les repertoires internes avec Apache 2.4 ; pour
un autre serveur, ne pas exposer .local/, secrets/, .github/, admin/, data/
ou engine/ directement sur le web.

Administration et OpenAI
-----------------------
Ouvrir l'onglet Admin (admin.php). Deux fichiers locaux sont a creer :
- .local/secrets/admin_password.txt : mot de passe de l'Admin, ou son hash
  password_hash PHP. Utiliser un mot de passe long et unique.
- .local/secrets/openai_api_key.txt : cle API OpenAI seule sur une ligne.
Option : .local/secrets/openai_model.txt pour choisir un modele compatible
Responses et Structured Outputs. Modele par defaut : gpt-4.1.
Les secrets ne sont ni renvoyes au navigateur ni envoyes avec les sources.
Utiliser HTTPS sur un hebergement distant.

1. Modifier les besoins dans les grilles par equipe et les deux textes de
   consignes. Enregistrer le brouillon conserve les fichiers actifs intacts.
2. Preparer la mise a jour avec OpenAI : les cinq CSV et les deux textes
   sont envoyes a l'API, sans les contrats Combo, pour obtenir des parametres
   complets au format JSON. Le bouton enregistre le brouillon si necessaire.
3. Verifier le resume, les limites et les parametres, puis appliquer. Les
   sept fichiers sources sont alors mis a jour, ainsi que le paquet actif
   lu par le moteur au prochain calcul. Aucun PHP genere n'est execute.
4. Recalculer le planning. Restaurer la version precedente permet de
   revenir aux sources et aux parametres precedant la derniere activation.

Une erreur OpenAI ou une consigne non prise en charge laisse la version
active disponible. La preparation consomme l'API OpenAI ; aucune requete
n'est lancee a chaque calcul de planning. Les brouillons, paquets et la
version precedente sont dans .local/admin/ (non versionne). Le serveur PHP
doit pouvoir ecrire dans ce dossier et dans les sept fichiers sources.
Des conflits entre editions concurrentes sont detectes. Apres une edition
externe des sources, enregistrer a nouveau un brouillon pour les appliquer
au paquet actif. Les sauvegardes contiennent des preferences nominatives.

Le moteur est parametrable pour les limites RH, horaires et preferences
individuelles et autorisations d'ouverture/fermeture. Il controle les
violations, mais ne garantit pas une optimisation complete de tous les
besoins horaires ni des rotations. Les absences Combo ne sont pas encore
importees. Une nouvelle consigne hors de ces capacites est signalee et
necessite une evolution du code avant activation ; OpenAI n'ecrit pas de
code PHP sur le serveur.

Reference API : https://developers.openai.com/api/docs/guides/structured-outputs
Tests sans cle ni appel externe :
php tests/admin-regression.php
php tests/admin-http-regression.php
node tests/admin-ui-regression.js
php tests/planning-regression.php
php tests/planning-api-regression.php mixed

Modifier le planning
-------------------
Besoins : data/planning/besoins/*.csv
Generation : engine/ProposalGenerator.php
Regles : engine/rules/ ; enregistrement dans engine/bootstrap.php
API : public/api/ ; interface : public/index.php, public/js/, public/css/

Affectation des equipes : engine/TeamResolver.php. Les creneaux de la
semaine choisie sont prioritaires ; pour les contrats sans equipe, recherche
dans les huit semaines precedant la semaine cible ou la date du jour si
elle est anterieure. Une affectation introuvable est signalee dans un groupe
separe, sans horaires calcules pour la personne concernee. Les autres
equipes restent affichees et leur planning continue a etre calcule.

Le generateur applique les horaires de Carlos (02:00 ou 03:00), ses repos
du lundi/mardi et l'heure minimale de Benoit (03:00). Il echange des repos
de boulangers eligibles si necessaire pour couvrir 02:00, puis signale les
manques persistants. Cela ne constitue pas une optimisation complete de
tous les besoins horaires de la journee.

Verification sans connexion Combo : php tests/planning-regression.php

Configuration
-------------
Pour que ce projet fonctionne correctement, il faut ajouter les cles API

.local/secrets/combo_api_key_beauvais.txt
.local/secrets/combo_api_key_gravigny.txt

dans chaque fichier, la clé API c'est juste dans un ligne sans variable ni rien d'ajouter

exemple : 
TRcv8zvGQWCRWEWERCQWEDASD
