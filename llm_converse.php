<?php
/**
 * llm_converse.php (Gemini version) — Simple, prompt-first, chat-compatible.
 * Endpoints:
 *   POST { action: "INIT" }     -> first NEXT question
 *   POST { action: "ANSWER", current: {...}, context: {...} } -> validate + NEXT / REPEAT / DONE
 *
 * Response shapes (exactly what the chat component expects):
 *   { ok:true, status:"NEXT",   step:int, next:{ key,q,hint,mode,multi,maxChoices?,options[],audit? } }
 *   { ok:true, status:"REPEAT", step:int, clarify:{ key,q,hint,mode,multi,maxChoices?,options[] } }
 *   { ok:true, status:"DONE",   step:int }
 *
 * Minimal state:
 * - We read progress from client payload (context.step, context.answers, context.attempts).
 * - We do NOT persist anything here; your app can persist separately if desired.
 *
 * Gemini model:
 * - Default: gemini-1.5-flash (fast). Change via $GEMINI_MODEL if you want 1.5-pro.
 * - Temperature ~0.5, responseMimeType=application/json, JSON-only contract.
 */

///////////////////////////////////////////////////////////////////////////////
// Config
///////////////////////////////////////////////////////////////////////////////
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // set to your origin in prod
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

//////////////////////////////
// Config
//////////////////////////////

define('DB_HOST', 'lamp-docker-db-1');
define('DB_USER', 'root');
define('DB_PASS', 'root_password');
define('DB_NAME', 'hub-spoke');

//
$GEMINI_API_KEY = getenv('GEMINI_API_KEY') ?: '';

$GEMINI_MODEL   = 'gemini-2.5-flash'; // or gemini-1.5-pro

//////////////////////////////
// Helpers
//////////////////////////////
function respond(array $arr, int $code = 200) {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function bad_request(string $msg, array $meta = []) { respond(['ok'=>false,'error'=>$msg,'meta'=>$meta], 400); }

//////////////////////////////
// Universal templates (no generic chips)
//////////////////////////////
$QA_TEMPLATES = [
  [
    'key'=>'industry',
    'q'=>'Which industry do you mainly serve or what service do you offer?',
    'hint'=>'Short and specific is best.',
    'mode'=>'chips+input','multi'=>false,'options'=>[]
  ],
  [
    'key'=>'ideal_audience',
    'q'=>'Who do you most want to serve right now?',
    'hint'=>'Name the customer segment or scenario that best matches your work.',
    'mode'=>'chips+input','multi'=>true,'options'=>[]
  ],
  [
    'key'=>'primary_goal',
    'q'=>'What is your main goal for the next 3–6 months?',
    'hint'=>'Think in outcomes you can point to.',
    'mode'=>'chips+input','multi'=>false,'options'=>[]
  ],
  [
    'key'=>'authority_topics',
    'q'=>'Which topics or services do you want to be known for?',
    'hint'=>'Choose 1–3 short areas you want authority in.',
    'mode'=>'chips+input','multi'=>true,'options'=>[]
  ],
  [
    'key'=>'buyer_language',
    'q'=>'Which phrases do prospects use before contacting you?',
    'hint'=>'Short terms they say or search for.',
    'mode'=>'chips+input','multi'=>true,'options'=>[]
  ],
  [
    'key'=>'seed_keywords',
    'q'=>'Pick 2–4 short seed topics (2–3 words).',
    'hint' => 'First list 3–5 short phrases your buyers or clients might say (comma-separated), then pick or add  2–4 short seed topics (2–3 words). These guide keyword research.',
    'mode'=>'chips+input','multi'=>true,'options'=>[], 'maxChoices'=>4
  ]
];

//////////////////////////////
// Input
//////////////////////////////
$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) bad_request('invalid_json_input');

$action  = $in['action']  ?? '';
$current = $in['current'] ?? null; // { key, text, selected[], custom[] }
$context = $in['context'] ?? [];   // { answers:{}, step:int, attempts:{key:int} }

$answers  = (array)($context['answers'] ?? []);
$step     = isset($context['step']) ? (int)$context['step'] : 0;
$attempts = (array)($context['attempts'] ?? []);

//////////////////////////////
// Utilities
//////////////////////////////
function answered_keys_from(array $answers): array {
  $keys = [];
  foreach ($answers as $k => $v) {
    if ($v === null) continue;
    if (is_array($v) && empty($v)) continue;
    if (is_string($v) && trim($v) === '') continue;
    // support both simple strings and {display_value:"..."}
    if (is_array($v) && isset($v['display_value']) && trim((string)$v['display_value']) === '') continue;
    $keys[] = $k;
  }
  return $keys;
}

function pick_next_template(array $templates, array $answeredKeys): array {
  foreach ($templates as $tpl) {
    if (!in_array($tpl['key'], $answeredKeys, true)) return $tpl;
  }
  // done
  return [
    'key'=>'done',
    'q'=>'All set — thanks! We have enough to start.',
    'hint'=>'We’ll generate topics and research from your answers.',
    'mode'=>'text','multi'=>false,'options'=>[]
  ];
}

function normalize_question_shape(array $item): array {
  return [
    'key'        => (string)($item['key'] ?? ''),
    'q'          => (string)($item['q'] ?? ''),
    'hint'       => (string)($item['hint'] ?? ''),
    'mode'       => (string)($item['mode'] ?? 'text'),
    'multi'      => (bool)  ($item['multi'] ?? false),
    'maxChoices' => isset($item['maxChoices']) ? (int)$item['maxChoices'] : null,
    'options'    => array_values(array_map('strval', (array)($item['options'] ?? []))),
    'audit'      => (array)($item['audit'] ?? [])
  ];
}

//////////////////////////////
// Database Saving
//////////////////////////////

// --- DB CONFIG (adjust to your env) ---

function db_conn() {
  $m = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
  if (!$m) error_log('DB connect error: '.mysqli_connect_error());
  mysqli_set_charset($m, 'utf8mb4');
  return $m;
}

/**
 * $answers   : map from your chat (industry, ideal_audience, primary_goal, authority_topics, buyer_language, seed_keywords)
 * $qaLog     : full transcript array (optional; from context.qa_log)
 * $projectNm : optional override for project name
 */

function save_onboarding_project_simple3($userId, array $answers, array $qaLog = [], $projectNm = null) {
  // Derive project name
  $industry = '';
  if (isset($answers['industry'])) {
    $industry = is_array($answers['industry'])
      ? trim((string)($answers['industry']['display_value'] ?? ''))
      : trim((string)$answers['industry']);
  }
  if ($industry === '') $industry = 'Content Strategy Project';
  $projectName = $projectNm ?: ($industry . ' - ' . date('Y-m-d H:i'));

  // Normalize seed keywords to array
  $seed = $answers['seed_keywords'] ?? [];
  if (is_string($seed)) {
    $seedArr = array_map('trim', preg_split('/[,|]/', $seed));
  } elseif (is_array($seed)) {
    if (isset($seed['display_value']) && is_string($seed['display_value'])) {
      $seedArr = array_map('trim', preg_split('/[,|]/', $seed['display_value']));
    } else {
      $seedArr = array_map('trim', $seed);
    }
  } else {
    $seedArr = [];
  }
  $seedArr  = array_values(array_filter($seedArr, fn($s)=>$s!==''));
  $seedCsv  = implode(', ', $seedArr);

  // Build CLEAN persona JSON (minimal)
  $persona = [
    'industry'         => is_string($answers['industry'] ?? '') ? trim($answers['industry']) : $answers['industry'] ?? '',
    'ideal_audience'   => $answers['ideal_audience']   ?? [],
    'primary_goal'     => $answers['primary_goal']     ?? '',
    'authority_topics' => $answers['authority_topics'] ?? [],
    'buyer_language'   => $answers['buyer_language']   ?? [],
    'seed_keywords'    => $seedArr,
    'meta'             => [
      'model'    => $GEMINI_MODEL,
      'version'  => 1,
      'saved_at' => gmdate('c')
    ]
  ];

  // Full transcript (if provided by UI)
  $qaSummary = $qaLog;

  // Insert
  $mysqli = db_conn(); if (!$mysqli) return [false, null];

  $uid   = (int)$userId;
  $pname = mysqli_real_escape_string($mysqli, $projectName);
  $pjson = mysqli_real_escape_string($mysqli, json_encode($persona,   JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  $qjson = mysqli_real_escape_string($mysqli, json_encode($qaSummary, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  $scsv  = mysqli_real_escape_string($mysqli, $seedCsv);

  $sql = "INSERT INTO projects1 (user_id, project_name, persona, qa_summary, seed_keywords)
          VALUES ($uid, '$pname', '$pjson', '$qjson', '$scsv')";
  
  error_log($sql);

  $ok  = mysqli_query($mysqli, $sql);
  if (!$ok) {
    error_log('INSERT failed: '.mysqli_error($mysqli));
    mysqli_close($mysqli);
    return [false, null];
  }

  $newId = mysqli_insert_id($mysqli);
  mysqli_close($mysqli);
  return [true, $newId];
}

//////////////////////////////
// Prompt + Gemini
//////////////////////////////
function build_prompt(array $templates, array $answers, array $askedCounts, ?string $lastKey, int $remainingSlots): string {
  $templatesJson = json_encode($templates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  $answersJson   = json_encode($answers,   JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  $stateJson     = json_encode([
    'asked_counts'    => $askedCounts,
    'last_key'        => $lastKey,
    'remaining_slots' => $remainingSlots
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

  return <<<PROMPT
You are an onboarding strategist for a SaaS content platform. Each turn:
1) VALIDATE the user's previous answer (if any).
2) RETURN the single NEXT best question with a tailored hint and concise chips.

Principles:
- Keep total questions ≤ 6; move efficiently; never loop.
- No fluff; sound like a sharp content strategist.
- Adapt wording to the user's own terms from prior answers.
- Do NOT introduce industries, brands, or locations the user hasn't implied.
- If "industry" already has a specific phrase (e.g., "yoga studio"), treat it as valid and DO NOT re-ask that key; advance to the next unresolved key. Do not invent or suggest generic industries (e.g., SaaS, Consulting, Finance, Education) unless the user used those words.
- Hints: 1–2 sentences with small, context-relevant examples that mirror the user's niche vocabulary.
- Chips: 3–6 items, 1–3 words, deduped, clearly aligned to the user's niche/role/offer. The UI will also allow custom entries.
- If a key is already specific and actionable, skip it.

- Distinctness across the last 3 keys:
  * "authority_topics" = supply-side themes you want to own.
  * "buyer_language"   = demand-side phrases buyers use.
  * "seed_keywords"    = final 2–4 short, query-ready topics (2–3 words).

- If the options you generate sound repetitive or similar to earlier chips, switch to "mode":"text" and leave "options":[].

- Hints must include 1–2 concrete examples relevant to the user's business or service, not generic lists.

- If "buyer_language" and "seed_keywords" would produce very similar questions or data (for example, for tutors, coaches, therapists, or local service providers), MERGE them into one step:
  * Use key: "seed_keywords".
  * The question should ask the user for BOTH: 
      (a) 3–5 real phrases buyers or clients actually use when searching or asking, and 
      (b) 2–4 short, query-ready seed topics (2–3 words).
  * Keep mode:"chips+input" and include chips only for the seed topics (not for phrases).
  * The hint must clearly tell the user to first type phrases (comma-separated) and then choose or add short seed topics.
  * Skip the separate "buyer_language" step entirely when merged.

- Location integrity:
  * If prior answers include a multi-word location (e.g., "san francisco"), always output the full location string; never truncate to "San" or abbreviate to "SF" unless the user wrote "SF".
  * When locations appear in chips, place them at the end and keep the full city (e.g., "driveway pavers san francisco").
  * Never output clipped tokens or dangling words (e.g., "Patio pavers San").

- Seed keyword length policy:
  * Prefer 2–3-word seed topics for breadth (e.g., "homeowners insurance", "piano lessons", "speech therapy").
  * If a 2–3-word seed would be ambiguous, incomplete, or geo-critical, allow 2–4 words (e.g., "home insurance el cerrito ca").
  * If the user's previous answers explicitly mention a location (city, county, region, or state), you MAY include that location as part of the seed keyword when it improves search precision (e.g., "yoga classes los angeles", "real estate agent san jose ca").
  * Never include a location unless the user mentioned one.
  * Never output clipped or fragmentary phrases (e.g., "generate more qualified", "insurance agents EL").
  * Do not abbreviate locations or nouns; always write full, natural phrases (e.g., "el cerrito ca", not "EL" or "E.C.").

Validation rubric (previous answer):
- Relevance, Specificity, Non-garbage, No contradiction, Actionability.
Re-ask policy:
- If valid: do NOT re-ask.
- If invalid and first time for that key: ask ONE concise clarification.
- If still invalid after that: accept best-effort canonicalized and move on. Never ask a key more than twice.

Universal keys (choose next unresolved):
- industry
- ideal_audience
- primary_goal
- authority_topics
- buyer_language
- seed_keywords

Templates (generic; REWRITE them to sound personal):
{$templatesJson}

Prior answers (use to tailor wording, hints, and chips):
{$answersJson}

State (avoid repetition; keep total ≤ 6):
{$stateJson}

Output JSON ONLY, no prose:
{
  "validation": {
    "key": string|null,
    "valid": boolean,
    "needs_clarification": boolean,
    "reason": string,
    "confidence": number,
    "canonicalized": string
  },
  "next": {
    "key": string,
    "q": string,
    "hint": string,
    "mode": "text"|"chips+input",
    "multi": boolean,
    "options": string[],
    "audit": { "signals_used": string[], "note": string }
  }
}
Temperature ≈ 0.5. Respond with valid JSON only.
PROMPT;
}

function call_gemini(string $apiKey, string $model, string $prompt) {
  $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);
  $payload  = [
    'contents' => [[ 'role'=>'user', 'parts'=>[['text'=>$prompt]] ]],
    'generationConfig' => [
      'temperature' => 0.5,
      'responseMimeType' => 'application/json'
    ]
  ];
  $ch = curl_init($endpoint);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25
  ]);
  $raw = curl_exec($ch);
  if ($raw === false) { $err = curl_error($ch); curl_close($ch); return ['error'=>"curl:{$err}"]; }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300) return ['error'=>"http_{$code}", 'raw'=>$raw];

  $resp  = json_decode($raw, true);
  $text  = $resp['candidates'][0]['content']['parts'][0]['text'] ?? '';
  $clean = trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', (string)$text));
  $out   = json_decode($clean, true);
  if (!is_array($out)) return ['error'=>'bad_json','raw'=>$clean];

  return ['ok'=>true,'data'=>$out];
}

//////////////////////////////
// Flow
//////////////////////////////

if ($action !== 'INIT' && $action !== 'ANSWER') {
  bad_request('unsupported_action', ['allowed'=>['INIT','ANSWER']]);
}

/*

This is disabled because we want to save API credits

if (!$GEMINI_API_KEY) {
  respond(['ok'=>false,'error'=>'missing_api_key','hint'=>'Set GEMINI_API_KEY in your environment'], 500);
}
*/

// ============== INIT (fast: no Gemini call) ==============
if ($action === 'INIT') {
  $answeredKeys = answered_keys_from($answers);
  $first = normalize_question_shape(pick_next_template($QA_TEMPLATES, $answeredKeys));

  if ($first['key'] === 'done') {
    respond(['ok'=>true,'status'=>'DONE','step'=>$step]);
  }

  // Make sure we don't show generic chips on the first turn
  if ($first['mode'] === 'chips+input') {
    $first['options'] = []; // let user type; Gemini adds chips on next turns
  }
  if ($first['key'] === 'industry' && trim($first['hint']) === '') {
    $first['hint'] = 'Short and specific is best.';
  }

  respond([
    'ok'     => true,
    'status' => 'NEXT',
    'step'   => $step,
    'next'   => $first
  ]);
}

// ANSWER
if (!is_array($current) || empty($current['key'])) bad_request('missing_current_key');

$askedCounts = $attempts;
$lastKey     = (string)$current['key'];

// Merge current answer into $answers so we don't re-ask
$display = '';
if (isset($current['text']) && trim($current['text']) !== '') {
  $display = trim($current['text']);
}
if ($display === '' && !empty($current['selected'])) {
  $display = implode(', ', array_map('strval', (array)$current['selected']));
}
if ($display === '' && !empty($current['custom'])) {
  $display = implode(', ', array_map('strval', (array)$current['custom']));
}
if ($display !== '') {
  $answers[$lastKey] = $display;
}

// Recompute fallback AFTER merging
$answeredKeys = answered_keys_from($answers);
$fallbackNext = normalize_question_shape(pick_next_template($QA_TEMPLATES, $answeredKeys));

// If nothing left, finish
if ($fallbackNext['key'] === 'done') {
  respond(['ok'=>true,'status'=>'DONE','step'=>$step]);
}

// Ask Gemini (validate last answer + propose next)
$prompt = build_prompt($QA_TEMPLATES, $answers, (array)$askedCounts, $lastKey, max(0, 6 - $step));

//$g = call_gemini($GEMINI_API_KEY, $GEMINI_MODEL, $prompt);
// Try Gemini call
try {
    // 👇 Replace this line with your real Gemini request
   $g = call_gemini($GEMINI_API_KEY, $GEMINI_MODEL, $prompt);

    // In case the response is empty or malformed
    if (!$g || !is_array($g) || !isset($g['next'])) {
        throw new Exception('Empty Gemini response');
    }
} catch (Exception $e) {
    error_log('[FALLBACK] Gemini error: ' . $e->getMessage());

    // Use local QA bank instead
    $answeredKeys = answered_keys_from($answers);
    $next = pick_next_template($QA_TEMPLATES, $answeredKeys);
    $next['hint']  = 'Continuing with default onboarding questions.';
    $next['audit'] = ['source' => 'local_QA_bank', 'reason' => 'Gemini failed'];

    respond([
        'ok'     => true,
        'status' => 'NEXT',
        'step'   => $step,
        'next'   => $next
    ]);
    exit;
}

// If Gemini fails, advance with neutral next (no generic chips)
if (!isset($g['ok'])) {
  respond(['ok'=>true,'status'=>'NEXT','step'=>$step,'next'=>$fallbackNext, 'note'=>'fallback:answer:'.$g['error'] ?? '']);
}

$data       = $g['data'];
$validation = (array)($data['validation'] ?? [
  'key'=>$lastKey,'valid'=>true,'needs_clarification'=>false,'reason'=>'','confidence'=>0.0,'canonicalized'=>''
]);
$nextObj    = (array)($data['next'] ?? []);

// If model tries to re-ask an already-answered key, skip
if (!empty($nextObj['key']) && isset($answers[$nextObj['key']]) && trim((string)$answers[$nextObj['key']]) !== '') {
  $nextObj = pick_next_template($QA_TEMPLATES, $answeredKeys);
}

// Need to clarify? one-time REPEAT on the same key
$needClarify = (isset($validation['valid']) && !$validation['valid']) && ((int)($askedCounts[$lastKey] ?? 0) < 1);

if ($needClarify) {
  $clarify = normalize_question_shape($nextObj ?: $fallbackNext);
  // force same key for clarity
  $clarify['key'] = $lastKey;
  if ($clarify['mode'] === 'chips+input') {
    $seen=[]; $opts=[];
    foreach ($clarify['options'] as $op) {
      $op = trim($op); if ($op==='') continue;
      $lc = mb_strtolower($op,'UTF-8'); if (isset($seen[$lc])) continue;
      $seen[$lc] = true;
      $parts = preg_split('/\s+/', $op);
      if (count($parts) > 3) $op = implode(' ', array_slice($parts, 0, 3));
      $opts[] = $op;
      if (count($opts) >= 6) break;
    }
    $clarify['options'] = $opts;
  } else {
    $clarify['options'] = [];
  }
  respond(['ok'=>true,'status'=>'REPEAT','step'=>$step,'clarify'=>$clarify]);
}

// Otherwise advance to NEXT
$next = normalize_question_shape(!empty($nextObj) ? $nextObj : $fallbackNext);

// ==== BEGIN: CHIP HYGIENE (ANSWER) ====
if ($next['mode'] === 'chips+input') {
  $seen = [];
  $opts = [];
  foreach ($next['options'] as $op) {
    $op = trim((string)$op);
    if ($op === '') continue;

    // Remove trailing punctuation
    $op = preg_replace('/[,\.;]+$/u', '', $op);

    // Light, safe trimming (allow up to 4 words)
    $parts = preg_split('/\s+/', $op);
    if (count($parts) > 4) {
      $last = strtolower(end($parts));
      $isShortLoc = preg_match('/^(ca|ny|tx|fl|il|az|wa|co|nc|sc|dc|uk|us)$/', $last);
      if (!$isShortLoc) {
        $op = implode(' ', array_slice($parts, 0, 4));
      }
    }

    $lc = mb_strtolower($op, 'UTF-8');
    if (isset($seen[$lc])) continue;
    $seen[$lc] = true;

    $opts[] = $op;
    if (count($opts) >= 6) break;
  }

  $next['options'] = $opts;
}

// ==== END: CHIP HYGIENE (ANSWER) ====

// ==== SAVING DATA IN DB ====
if ($next['key'] === 'done') {
  // Save to DB before finishing
  $userId = isset($context['user_id']) ? (int)$context['user_id'] : 1;
  $qaLog  = isset($context['qa_log']) && is_array($context['qa_log']) ? $context['qa_log'] : [];

  list($saved, $projectId) = save_onboarding_project_simple3($userId, $answers, $qaLog);

  respond([
    'testing' => "ATUL JINDAL",
    'ok'        => true,
    'status'    => 'DONE',
    'step'      => $step,
    'projectId' => $projectId,
    'saved'     => $saved
  ]);
}

if ($next['mode'] === 'chips+input') {
  $seen=[]; $opts=[];
  foreach ($next['options'] as $op) {
    $op = trim($op); if ($op==='') continue;
    $lc = mb_strtolower($op,'UTF-8'); if (isset($seen[$lc])) continue;
    $seen[$lc] = true;
    $parts = preg_split('/\s+/', $op);
    if (count($parts) > 3) $op = implode(' ', array_slice($parts, 0, 3));
    $opts[] = $op;
    if (count($opts) >= 6) break;
  }
  $next['options'] = $opts; // no generic backfill
} else {
  $next['options'] = [];
}

respond(['ok'=>true,'status'=>'NEXT','step'=>$step,'next'=>$next]);
