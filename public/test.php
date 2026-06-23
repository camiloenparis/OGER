<?php
// Test simple pour vérifier que le serveur fonctionne
echo "Test - Serveur PHP fonctionne";
echo "\n\nChemin du projet: " . dirname(dirname(__FILE__));
echo "\n\nFichier clé API: " . realpath('../../.local/secrets/combo_api_key.txt');

$api_key_file = '../../.local/secrets/combo_api_key.txt';
echo "\nFichier existe: " . (file_exists($api_key_file) ? 'OUI' : 'NON');

if (file_exists($api_key_file)) {
  $content = file_get_contents($api_key_file);
  echo "\nContenu (premiers 20 caractères): " . substr($content, 0, 20);
  echo "\nLongueur: " . strlen($content);
}
?>
