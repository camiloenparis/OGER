<?php

require_once __DIR__ . '/../engine/PlanningSettings.php';

/** Brouillons, versions et publication transactionnelle des donnees du moteur. */
final class AdminStore
{
  private string $dir;
  public function __construct(private string $root)
  {
    $this->dir = $root . '/.local/admin';
    if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true) && !is_dir($this->dir)) throw new RuntimeException('Dossier de configuration non accessible en ecriture.');
  }

  public static function files(): array
  {
    $files = [];
    foreach (PlanningSettings::teams() as $team => $file) $files['needs_' . $team] = 'data/planning/besoins/' . $file;
    $files['rules'] = '.github/instructions/regles-planning.instructions.md';
    $files['preferences'] = '.github/instructions/preferences-internes-magasins.instructions.md';
    return $files;
  }

  public function locked(callable $action): mixed
  {
    $lock = fopen($this->dir . '/state.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Impossible de verrouiller la configuration.');
    try { return $action(); } finally { flock($lock, LOCK_UN); fclose($lock); }
  }

  private function read(string $file): ?array
  {
    $path = $this->dir . '/' . $file . '.json';
    return is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : null;
  }

  public static function atomicWrite(string $path, string $contents): void
  {
    $temp = tempnam(dirname($path), '.oger-');
    if ($temp === false) throw new RuntimeException('Impossible de preparer l enregistrement.');
    try {
      if (file_put_contents($temp, $contents, LOCK_EX) !== strlen($contents)) throw new RuntimeException('Enregistrement incomplet.');
      if (!rename($temp, $path)) throw new RuntimeException('Impossible de remplacer le fichier.');
    } finally { if (is_file($temp)) unlink($temp); }
  }

  private function write(string $file, array $data): void
  {
    self::atomicWrite($this->dir . '/' . $file . '.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
  }

  private function sources(): array
  {
    $documents = [];
    foreach (self::files() as $key => $path) {
      $text = file_get_contents($this->root . '/' . $path);
      if ($text === false) throw new RuntimeException('Source illisible : ' . $key);
      $documents[$key] = ltrim($text, "\xEF\xBB\xBF");
    }
    return $documents;
  }

  private function revision(): string
  {
    return hash('sha256', json_encode([$this->sources(), $this->read('draft'), $this->read('active')], JSON_THROW_ON_ERROR));
  }

  private function expect(string $revision): void
  {
    if (!hash_equals($this->revision(), $revision)) throw new RuntimeException('La configuration a change. Rechargez les donnees avant de continuer.', 409);
  }

  public function state(): array
  {
    $draft = $this->read('draft');
    $active = $this->read('active');
    $sources = $this->sources();
    return [
      'documents' => $draft['documents'] ?? $sources,
      'paths' => self::files(), 'revision' => $this->revision(),
      'draft' => $draft !== null, 'candidate' => $draft['candidate'] ?? null,
      'active_at' => $active['activated_at'] ?? null,
      'sources_changed' => $active !== null && ($active['documents'] ?? []) !== $sources,
      'can_restore' => $this->read('previous') !== null,
    ];
  }

  public function save(array $documents, string $revision): array
  {
    $this->expect($revision);
    self::validateDocuments($documents);
    $this->write('draft', ['documents' => $documents, 'saved_at' => date(DATE_ATOM), 'base_sources_hash' => $this->sourceHash()]);
    return $this->state();
  }

  public function candidate(array $proposal, string $revision, string $model): array
  {
    $this->expect($revision);
    self::validateProposal($proposal);
    $draft = $this->read('draft');
    if ($draft === null) throw new RuntimeException('Enregistrez d abord un brouillon.');
    $this->expectSources($draft);
    $draft['candidate'] = $proposal + ['model' => $model, 'generated_at' => date(DATE_ATOM)];
    $this->write('draft', $draft);
    return $this->state();
  }

  public static function validateDocuments(array $documents): void
  {
    if (array_diff(array_keys($documents), array_keys(self::files())) || array_diff(array_keys(self::files()), array_keys($documents))) throw new InvalidArgumentException('Les sept sources attendues sont obligatoires.');
    foreach ($documents as $key => $text) {
      if (!is_string($text) || strlen($text) > 100000 || trim($text) === '' || str_contains($text, "\0") || preg_match('//u', $text) !== 1) throw new InvalidArgumentException('Source vide ou invalide : ' . $key);
      if (str_starts_with($key, 'needs_')) self::validateCsv($text, $key);
    }
  }

  public static function validateCsv(string $csv, string $key): void
  {
    $lines = preg_split('/\r?\n/', trim($csv));
    $header = array_map('trim', str_getcsv(array_shift($lines), ';'));
    if (array_slice($header, 1, 7) !== ['LUNDI','MARDI','MERCREDI','JEUDI','VENDREDI','SAMEDI','DIMANCHE']) throw new InvalidArgumentException('Colonnes lundi a dimanche requises : ' . $key);
    $hours = [];
    foreach ($lines as $line) {
      // Accepter les deux marqueurs historiques presents a la fin des CSV existants.
      if (preg_match('/^[;\s]*$/', $line) || trim($line) === 'magasin,jour,heure_debut,heure_fin,min_personnes,max_personnes') continue;
      $row = str_getcsv($line, ';');
      if (count($row) < 8 || preg_match('/^(\d{1,2})[hH](?:00)?$/', trim($row[0]), $match) !== 1 || (int) $match[1] > 23) throw new InvalidArgumentException('Ligne horaire invalide : ' . $key);
      $hour = (int) $match[1];
      if (in_array($hour, $hours, true)) throw new InvalidArgumentException('Heure en double : ' . $key);
      $hours[] = $hour;
      foreach (array_slice($row, 1, 7) as $value) if (!preg_match('/^\d{1,3}$/', trim($value))) throw new InvalidArgumentException('Effectif entier entre 0 et 999 requis : ' . $key);
      foreach (array_slice($row, 8) as $value) if (trim($value) !== '') throw new InvalidArgumentException('Colonnes supplementaires non vides : ' . $key);
    }
    if (count($hours) !== 24 || $hours !== range(0, 23)) throw new InvalidArgumentException('Les 24 heures de 0 a 23 doivent etre renseignees dans l ordre : ' . $key);
  }

  public static function validateProposal(array $proposal): void
  {
    if (array_keys($proposal) !== ['settings','summary','unsupported']) {
      $keys = array_keys($proposal); sort($keys);
      if ($keys !== ['settings','summary','unsupported']) throw new InvalidArgumentException('Proposition IA incomplete.');
    }
    if (!is_string($proposal['summary']) || !is_array($proposal['unsupported']) || !array_is_list($proposal['unsupported']) || count($proposal['unsupported']) > 100) throw new InvalidArgumentException('Resume IA invalide.');
    foreach ($proposal['unsupported'] as $item) if (!is_string($item)) throw new InvalidArgumentException('Consigne non prise en charge invalide.');
    PlanningSettings::validate($proposal['settings']);
  }

  public function activate(string $revision): array
  {
    $this->expect($revision);
    $draft = $this->read('draft');
    $proposal = $draft['candidate'] ?? null;
    if (!$proposal) throw new RuntimeException('Generez d abord une proposition de mise a jour.');
    $this->expectSources($draft);
    self::validateDocuments($draft['documents']);
    self::validateProposal(array_intersect_key($proposal, array_flip(['settings','summary','unsupported'])));
    if ($proposal['unsupported']) throw new RuntimeException('Des consignes ne sont pas prises en charge. Corrigez le brouillon avant activation.');
    $this->publish($draft['documents'], $proposal['settings'], $proposal['summary'], $proposal['model']);
    return $this->state();
  }

  private function publish(array $documents, array $settings, string $summary, string $model): void
  {
    $old = ['documents' => $this->sources(), 'active' => $this->read('active')];
    $oldPrevious = $this->read('previous');
    $oldDraft = $this->read('draft');
    $staffing = [];
    foreach (PlanningSettings::teams() as $team => $file) $staffing[$team] = $documents['needs_' . $team];
    $package = ['documents'=>$documents, 'settings'=>$settings, 'staffing'=>$staffing,
      'activated_at'=>date(DATE_ATOM), 'summary'=>$summary, 'model'=>$model];
    $written = [];
    try {
      foreach (self::files() as $key => $path) {
        self::atomicWrite($this->root . '/' . $path, $documents[$key]);
        $written[] = $key;
      }
      $this->write('draft', ['documents'=>$documents, 'saved_at'=>date(DATE_ATOM), 'base_sources_hash'=>$this->sourceHash()]);
      $this->write('previous', $old);
      // Le paquet est publie en dernier : aucun calcul ne voit une version partielle.
      $this->write('active', $package);
    } catch (Throwable $e) {
      foreach ($written as $key) self::atomicWrite($this->root . '/' . self::files()[$key], $old['documents'][$key]);
      if ($oldDraft !== null) $this->write('draft', $oldDraft);
      if ($oldPrevious !== null) $this->write('previous', $oldPrevious);
      elseif (is_file($this->dir . '/previous.json')) unlink($this->dir . '/previous.json');
      throw $e;
    }
  }

  private function sourceHash(): string { return hash('sha256', json_encode($this->sources(), JSON_THROW_ON_ERROR)); }
  private function expectSources(array $draft): void
  {
    if (!hash_equals($draft['base_sources_hash'] ?? '', $this->sourceHash())) throw new RuntimeException('Les fichiers sources ont change depuis l enregistrement du brouillon. Enregistrez a nouveau votre brouillon avant de generer une proposition.', 409);
  }

  public function restore(string $revision): array
  {
    $this->expect($revision);
    $previous = $this->read('previous');
    if (!$previous) throw new RuntimeException('Aucune version precedente.');
    self::validateDocuments($previous['documents']);
    $settings = $previous['active']['settings'] ?? PlanningSettings::defaults();
    PlanningSettings::validate($settings);
    $this->publish($previous['documents'], $settings, 'Restauration de la version precedente.', $previous['active']['model'] ?? 'defaults');
    return $this->state();
  }
}
