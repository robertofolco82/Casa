<?php
/* ═══════════════════════════════════════════════════════════════════════
   RAVIOLA32 — ponte verso l'API di Claude

   Perché esiste: una pagina statica non può custodire un segreto. Se la
   chiave finisse nel JavaScript, chiunque aprisse il sorgente potrebbe
   prenderla e consumare il credito di Roberto. Qui la chiave resta sul
   server e il browser parla solo con questo file.

   Cosa fa, nell'ordine: verifica il metodo, verifica il codice d'accesso,
   verifica il limite per IP, gira la richiesta ad Anthropic e restituisce
   la risposta — in streaming per la chat, in blocco per le estrazioni JSON.
   ═══════════════════════════════════════════════════════════════════════ */

declare(strict_types=1);

const ENDPOINT       = 'https://api.anthropic.com/v1/messages';
const VERSIONE_API   = '2023-06-01';

/* ─────────── risposta d'errore uniforme ─────────── */
function stop(int $http, string $codice, string $messaggio): never {
  if (!headers_sent()) {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode(['errore' => $codice, 'messaggio' => $messaggio], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ─────────── configurazione ─────────── */
$percorso = __DIR__ . '/config.php';
if (!is_file($percorso)) {
  stop(500, 'non_configurato',
    'Manca config.php sul server: copia config.example.php e inserisci la chiave.');
}
$cfg = require $percorso;

if (empty($cfg['api_key']) || !str_starts_with((string)$cfg['api_key'], 'sk-ant-')) {
  stop(500, 'chiave_mancante', 'La chiave API non è configurata correttamente sul server.');
}

/* ─────────── solo POST ───────────
   Il GET è la sonda gratuita della pagina: dice che il ponte c'è e se il
   codice d'accesso è stato configurato, così un server a metà non viene
   scambiato per un errore di digitazione dell'utente. */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  header('Allow: POST');
  http_response_code(405);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'errore' => 'metodo', 'messaggio' => 'Usa POST.',
    'codiceConfigurato' => ((string)($cfg['codice_accesso'] ?? '') !== ''),
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ─────────── corpo della richiesta ─────────── */
$grezzo = file_get_contents('php://input') ?: '';
$req    = json_decode($grezzo, true);
if (!is_array($req)) stop(400, 'corpo', 'Corpo della richiesta non valido.');

/* ─────────── codice d'accesso ───────────
   hash_equals confronta in tempo costante: non lascia dedurre il codice
   misurando quanto ci mette a rispondere. */
$atteso = (string)($cfg['codice_accesso'] ?? '');
$dato   = (string)($req['codice'] ?? '');
if ($atteso === '') {
  stop(401, 'codice_non_configurato',
    'Sul server manca codice_accesso in config.php: impostalo e riprova.');
}
if (!hash_equals($atteso, $dato)) {
  stop(401, 'codice', 'Codice d\'accesso non valido.');
}

/* ─────────── limite per IP ───────────
   Una finestra scorrevole tenuta su file. Non è una difesa da fortezza,
   ma impedisce a uno script di svuotare il credito mentre nessuno guarda. */
function limita(array $cfg): void {
  $max      = (int)($cfg['limite_richieste'] ?? 30);
  $finestra = (int)($cfg['limite_finestra'] ?? 600);
  if ($max <= 0) return;

  $ip   = (string)($_SERVER['REMOTE_ADDR'] ?? 'ignoto');
  $dir  = sys_get_temp_dir() . '/raviola32';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  $file = $dir . '/rl_' . hash('sha256', $ip) . '.json';

  $ora   = time();
  $tempi = [];
  if (is_file($file)) {
    $letto = json_decode((string)@file_get_contents($file), true);
    if (is_array($letto)) $tempi = $letto;
  }
  $tempi = array_values(array_filter($tempi, fn($t) => is_int($t) && $t > $ora - $finestra));

  if (count($tempi) >= $max) {
    header('Retry-After: ' . $finestra);
    stop(429, 'troppe_richieste',
      "Hai superato il limite di $max richieste ogni " . intdiv($finestra, 60) . " minuti. Riprova più tardi.");
  }
  $tempi[] = $ora;
  @file_put_contents($file, json_encode($tempi), LOCK_EX);
}
limita($cfg);

/* ─────────── prompt ─────────── */
$prompt = trim((string)($req['prompt'] ?? ''));
if ($prompt === '') stop(400, 'prompt_vuoto', 'Nessun testo da inviare.');

$maxCar = (int)($cfg['max_caratteri_prompt'] ?? 60000);
if (mb_strlen($prompt) > $maxCar) {
  stop(413, 'prompt_lungo', "Il testo supera i $maxCar caratteri consentiti.");
}

/* ─────────── modello e limiti ─────────── */
$tier    = ($req['tier'] ?? 'default') === 'quick' ? 'quick' : 'default';
$modello = (string)($cfg['modelli'][$tier] ?? 'claude-opus-5');
$maxTok  = (int)($cfg['max_tokens'][$tier] ?? 8000);

/* Le estrazioni JSON tornano in blocco: serve il testo intero per poterlo
   interpretare, lo streaming non aggiungerebbe niente. */
$vuoleJson = !empty($req['json']);
$stream    = !$vuoleJson && !empty($req['stream']);

$payload = [
  'model'      => $modello,
  'max_tokens' => $maxTok,
  'messages'   => [['role' => 'user', 'content' => $prompt]],
];
if ($stream) $payload['stream'] = true;

/* ─────────── chiamata ad Anthropic ─────────── */
$intestazioni = [
  'Content-Type: application/json',
  'x-api-key: ' . $cfg['api_key'],
  'anthropic-version: ' . VERSIONE_API,
];

if ($stream) {
  /* Passthrough SSE: i pezzi arrivano dal modello e ripartono subito verso
     il browser, così la chat scrive mentre pensa invece di restare muta. */
  header('Content-Type: text/event-stream; charset=utf-8');
  header('Cache-Control: no-cache, no-transform');
  header('X-Accel-Buffering: no');
  while (ob_get_level() > 0) ob_end_flush();

  $ch = curl_init(ENDPOINT);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => $intestazioni,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 300,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_WRITEFUNCTION  => function ($_ch, string $pezzo): int {
      echo $pezzo;
      flush();
      return strlen($pezzo);
    },
  ]);
  $ok   = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  /* A intestazioni già inviate non si può cambiare lo stato HTTP: l'errore
     viaggia come un evento SSE che il client sa riconoscere. */
  if ($ok === false || $http >= 400) {
    $m = $http >= 400 ? "Il servizio AI ha risposto con errore $http." : "Connessione interrotta: $err";
    echo "\nevent: error\ndata: " . json_encode(['messaggio' => $m], JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
  }
  exit;
}

$ch = curl_init(ENDPOINT);
curl_setopt_array($ch, [
  CURLOPT_POST           => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER     => $intestazioni,
  CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT        => 300,
  CURLOPT_CONNECTTIMEOUT => 15,
]);
$risposta = curl_exec($ch);
$http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errCurl  = curl_error($ch);
curl_close($ch);

if ($risposta === false) stop(502, 'rete', 'Non raggiungo il servizio AI: ' . $errCurl);

$dati = json_decode((string)$risposta, true);

if ($http >= 400) {
  /* Il messaggio di Anthropic è utile in diagnosi ma non deve rivelare
     nulla della configurazione: passa solo il testo, mai la chiave. */
  $dettaglio = is_array($dati) ? (string)($dati['error']['message'] ?? '') : '';
  $mappa = [
    401 => 'La chiave API configurata sul server non è valida.',
    403 => 'La chiave API non è autorizzata per questa operazione.',
    429 => 'Il servizio AI è momentaneamente sovraccarico di richieste. Riprova fra poco.',
    529 => 'Il servizio AI è sovraccarico. Riprova fra poco.',
  ];
  stop($http === 401 || $http === 403 ? 500 : $http, 'ai_' . $http,
    $mappa[$http] ?? ('Il servizio AI ha risposto con errore ' . $http .
      ($dettaglio !== '' ? ': ' . $dettaglio : '')));
}

/* Il testo può arrivare spezzato in più blocchi: vanno concatenati tutti,
   non solo il primo. */
$testo = '';
foreach ((array)($dati['content'] ?? []) as $blocco) {
  if (($blocco['type'] ?? '') === 'text') $testo .= (string)($blocco['text'] ?? '');
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'testo' => $testo,
  'uso'   => [
    'input'  => (int)($dati['usage']['input_tokens'] ?? 0),
    'output' => (int)($dati['usage']['output_tokens'] ?? 0),
  ],
], JSON_UNESCAPED_UNICODE);
