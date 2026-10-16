<?php

/** Parametres du moteur : une seule version coherente par requete. */
final class PlanningSettings
{
  private static ?array $snapshot = null;

  public static function snapshot(): array
  {
    if (self::$snapshot !== null) return self::$snapshot;
    $root = dirname(__DIR__);
    $lock = is_file($root . '/.local/admin/state.lock') ? fopen($root . '/.local/admin/state.lock', 'r') : false;
    if ($lock) flock($lock, LOCK_SH);
    try {
      $path = $root . '/.local/admin/active.json';
      if (is_file($path)) {
        $package = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::validate($package['settings']);
        return self::$snapshot = $package;
      }
      $settings = self::defaults();
      $staffing = [];
      foreach (self::teams() as $team => $file) $staffing[$team] = file_get_contents($root . '/data/planning/besoins/' . $file);
      return self::$snapshot = ['settings' => $settings, 'staffing' => $staffing];
    } finally {
      if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
  }

  public static function defaults(): array
  {
    return json_decode(file_get_contents(__DIR__ . '/settings.defaults.json'), true, 512, JSON_THROW_ON_ERROR);
  }

  public static function teams(): array
  {
    return ['boulangerie' => 'boulangerie.csv', 'patisserie' => 'patisserie.csv', 'traiteur' => 'traiteur.csv', 'vente' => 'vente.csv', 'leroy-merlin' => 'leroy-merlin.csv'];
  }

  public static function normalize(string $value): string
  {
    $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
    return preg_replace('/\s+/', ' ', strtr($value, ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','ä'=>'a','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','œ'=>'oe']));
  }

  public static function team(string $name): string
  {
    $name = self::normalize($name);
    return str_contains($name, 'leroy') ? 'leroy-merlin' : $name;
  }

  public static function hr(string $key): mixed { return self::snapshot()['settings']['hr'][$key]; }
  public static function minutes(string $time): int { [$h, $m] = explode(':', $time); return (int) $h * 60 + (int) $m; }

  public static function preferences(string $name, string $team = ''): array
  {
    $name = self::normalize($name);
    return array_values(array_filter(self::snapshot()['settings']['employees'], fn ($rule) =>
      str_contains($name, self::normalize($rule['name_match']))
      && ($team === '' || !$rule['teams'] || in_array(self::team($team), $rule['teams'], true))));
  }

  public static function rests(string $name, string $team = ''): array
  {
    $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    $rest = [];
    foreach (self::preferences($name, $team) as $rule) {
      $rest = array_merge($rest, $rule['rest_days']);
      if ($rule['allowed_days']) $rest = array_merge($rest, array_diff($days, $rule['allowed_days']));
    }
    return array_values(array_unique($rest));
  }

  public static function preferredStart(string $name, int $desired, string $team = ''): int
  {
    $rules = self::preferences($name, $team);
    $allowed = null;
    foreach ($rules as $rule) {
      if ($rule['earliest_start'] !== null) $desired = max($desired, self::minutes($rule['earliest_start']));
      if ($rule['start_times']) {
        $times = array_map([self::class, 'minutes'], $rule['start_times']);
        $allowed = $allowed === null ? $times : array_values(array_intersect($allowed, $times));
      }
    }
    if ($allowed === null) return $desired;
    sort($allowed);
    foreach ($allowed as $time) if ($time >= $desired) return $time;
    return $allowed ? max($allowed) : $desired;
  }

  public static function store(string $team): array
  {
    foreach (self::snapshot()['settings']['stores'] as $store) if (in_array(self::team($team), $store['teams'], true)) return $store;
    throw new RuntimeException('Horaires du magasin non configures pour ' . $team);
  }

  public static function validate(array $settings): void
  {
    self::validateNode($settings, self::schema(), 'regles');
    $covered = [];
    foreach ($settings['stores'] as $store) {
      if (!$store['teams']) throw new InvalidArgumentException('Un magasin doit contenir au moins une equipe.');
      foreach (['weekday', 'sunday'] as $day) {
        if (self::minutes($store[$day . '_open']) >= self::minutes($store[$day . '_close'])) throw new InvalidArgumentException('Ouverture et fermeture incoherentes.');
      }
      foreach ($store['teams'] as $team) {
        if (in_array($team, $covered, true)) throw new InvalidArgumentException('Equipe presente dans plusieurs magasins : ' . $team);
        $covered[] = $team;
      }
    }
    if (count(array_diff(array_keys(self::teams()), $covered))) throw new InvalidArgumentException('Les cinq equipes doivent avoir leurs horaires de magasin.');
    foreach ($settings['employees'] as $rule) {
      if (trim($rule['name_match']) === '') throw new InvalidArgumentException('Un nom est obligatoire pour chaque preference.');
      if ($rule['allowed_days'] && array_diff($rule['allowed_days'], $rule['rest_days']) === []) throw new InvalidArgumentException('Preference contradictoire : aucun jour de travail autorise.');
      if ($rule['start_times'] && $rule['earliest_start'] !== null && !array_filter($rule['start_times'], fn ($time) => self::minutes($time) >= self::minutes($rule['earliest_start']))) throw new InvalidArgumentException('Heures de debut contradictoires pour ' . $rule['name_match']);
    }
  }

  private static function validateNode(mixed $value, array $schema, string $path): void
  {
    if (isset($schema['anyOf'])) {
      foreach ($schema['anyOf'] as $option) {
        try { self::validateNode($value, $option, $path); return; } catch (InvalidArgumentException $e) {}
      }
      throw new InvalidArgumentException('Valeur invalide : ' . $path);
    }
    $type = $schema['type'];
    $valid = match ($type) {
      'object' => is_array($value) && ($value === [] || !array_is_list($value)),
      'array' => is_array($value) && array_is_list($value),
      'integer' => is_int($value), 'number' => is_int($value) || is_float($value),
      'string' => is_string($value), 'boolean' => is_bool($value), 'null' => $value === null,
      default => false,
    };
    if (!$valid) throw new InvalidArgumentException('Type invalide : ' . $path);
    if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) throw new InvalidArgumentException('Choix invalide : ' . $path);
    if (isset($schema['minimum']) && $value < $schema['minimum'] || isset($schema['maximum']) && $value > $schema['maximum']) throw new InvalidArgumentException('Limite invalide : ' . $path);
    if (isset($schema['pattern']) && !preg_match('~' . $schema['pattern'] . '~u', $value)) throw new InvalidArgumentException('Format invalide : ' . $path);
    if ($type === 'object') {
      if (array_diff(array_keys($value), array_keys($schema['properties'])) || array_diff($schema['required'], array_keys($value))) throw new InvalidArgumentException('Champs invalides : ' . $path);
      foreach ($schema['properties'] as $key => $child) self::validateNode($value[$key], $child, $path . '.' . $key);
    }
    if ($type === 'array') {
      if (count($value) > 500) throw new InvalidArgumentException('Trop de valeurs : ' . $path);
      foreach ($value as $index => $child) self::validateNode($child, $schema['items'], $path . '.' . $index);
    }
  }

  public static function schema(): array
  {
    $object = fn ($props) => ['type'=>'object','properties'=>$props,'required'=>array_keys($props),'additionalProperties'=>false];
    $array = fn ($items) => ['type'=>'array','items'=>$items];
    $number = fn ($min, $max, $type='number') => ['type'=>$type,'minimum'=>$min,'maximum'=>$max];
    $time = ['type'=>'string','pattern'=>'^(?:[01][0-9]|2[0-3]):[0-5][0-9]$'];
    $nullable = fn ($node) => ['anyOf'=>[$node,['type'=>'null']]];
    $team = ['type'=>'string','enum'=>array_keys(self::teams())];
    $day = ['type'=>'string','enum'=>['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']];
    return $object([
      'hr'=>$object([
        'max_daily_hours'=>$number(1,24), 'max_consecutive_days'=>$number(1,7,'integer'),
        'break_after_minutes'=>$number(30,720,'integer'), 'break_minutes'=>$number(5,120,'integer'),
        'min_rest_minutes'=>$number(60,1440,'integer'), 'apprentice_adult_start'=>$time, 'apprentice_minor_start'=>$time,
        'prefer_consecutive_rest'=>['type'=>'boolean'],
      ]),
      'stores'=>$array($object(['teams'=>$array($team),'weekday_open'=>$time,'weekday_close'=>$time,'sunday_open'=>$time,'sunday_close'=>$time])),
      'employees'=>$array($object([
        'name_match'=>['type'=>'string'], 'teams'=>$array($team), 'rest_days'=>$array($day), 'allowed_days'=>$array($day),
        'start_times'=>$array($time), 'earliest_start'=>$nullable($time), 'max_daily_hours'=>$nullable($number(1,24)),
        'can_open'=>$nullable(['type'=>'boolean']), 'closing'=>['type'=>'string','enum'=>['solo','partner','forbidden','unspecified']],
      ])),
    ]);
  }
}
