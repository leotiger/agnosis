#!/usr/bin/env php
<?php
/**
 * translate-missing.php — AI-translate every empty string in
 * languages/agnosis-*.po directly, replacing the manual Loco Translate
 * pass this project relied on until now.
 *
 * Why this exists: the Loco Translate workflow (download from a live
 * instance, edit in its UI, no repo trace of what changed or why) is
 * slow, manual, and — per AUDIT-0.9.44.md §5d — its editor UI doesn't
 * even surface plural-form fields past the first couple for locales
 * needing more than two categories, which is what dev/bin/fill-loco-gaps.php
 * was built to clean up after. This script removes the dependency on
 * Loco Translate entirely: it finds every empty msgstr/msgstr[N] across
 * every locale — ordinary untranslated strings AND plural-slot gaps in
 * one pass — and fills them by calling an AI provider with a prompt
 * that knows what Agnosis actually is, so translations land in the
 * right register instead of a generic machine-translation guess.
 *
 * This is a narrow, ON-DEMAND tool (composer translate-missing), same
 * spirit as fill-loco-gaps.php — not wired into make-pot.sh/compile-pos.sh,
 * not run automatically on every version bump.
 *
 * Three providers supported via --provider=anthropic|openai|gemini
 * (default: anthropic). If --provider= isn't passed and STDIN is an
 * interactive terminal, the script first asks which provider to use
 * (Enter for Anthropic); non-interactive runs (CI, a pipe) skip straight
 * to Anthropic with no prompt. Each provider needs its own API key —
 * either export the matching environment variable before running, or
 * leave it unset and this script prompts for it interactively too (input
 * hidden, used for that run only, never written to any file in this repo
 * or persisted anywhere by the script itself):
 *   anthropic → ANTHROPIC_API_KEY  (https://console.anthropic.com/)
 *   openai    → OPENAI_API_KEY     (https://platform.openai.com/api-keys)
 *   gemini    → GEMINI_API_KEY     (https://aistudio.google.com/apikey)
 * These are separate keys from anything Agnosis itself stores at
 * runtime (Core\Secrets); this script only ever runs on a developer's
 * own machine, never inside WordPress. The default model per provider
 * is a reasonable, cost-aware pick as of when this script was written —
 * provider model names move fast, so a stderr notice prints whenever a
 * non-anthropic default is used unconfirmed; pass --model=NAME to pin
 * an exact, currently-correct one.
 *
 * Writes AI translations DIRECTLY into the .po files (by design — see
 * AUDIT-0.9.44.md §5d annotation for the trade-off discussion) — and
 * saves after EVERY batch, not just once a whole locale finishes, so an
 * interrupted run (Ctrl-C, a crash, or an external wrapper killing the
 * process on its own timeout) keeps whatever was already translated.
 * Every write is also appended to dev/translate-missing.log for a fast
 * post-hoc skim; nothing here claims professional/native-speaker
 * accuracy — same caveat as every AI-drafted string this project has
 * shipped so far.
 *
 * If you're running this through a tool/wrapper that enforces its own
 * process timeout (e.g. a 300-second cap), pass --time-budget=SECONDS set
 * safely under that limit; the script then stops itself cleanly before a
 * new batch starts, instead of being killed mid-batch. Because progress is
 * saved per batch either way, just re-run the same command — it picks up
 * exactly where the previous run left off.
 *
 * Usage (from dev/):
 *   php bin/translate-missing.php                    # all locales, all gaps, Anthropic Haiku
 *   php bin/translate-missing.php --dry-run           # call the API, print results, write nothing
 *   php bin/translate-missing.php --locale=ar         # scope to one locale
 *   php bin/translate-missing.php --limit=10          # cap items translated (testing/cost control)
 *   php bin/translate-missing.php --batch-size=40      # items per API call (default 40)
 *   php bin/translate-missing.php --provider=openai --model=gpt-4o-mini   # use OpenAI instead
 *   php bin/translate-missing.php --provider=gemini --model=gemini-2.0-flash  # use Gemini instead
 *   php bin/translate-missing.php --time-budget=270   # stop cleanly under a 300s wrapper timeout; re-run to resume
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

$devDir  = dirname(__DIR__);
$langDir = $devDir . '/../languages';
$logFile = $devDir . '/translate-missing.log';

const PROVIDERS = [
    'anthropic' => [
        'env'   => 'ANTHROPIC_API_KEY',
        'model' => 'claude-haiku-4-5-20251001',
        'label' => 'Anthropic',
        'url'   => 'https://console.anthropic.com/',
    ],
    'openai' => [
        'env'   => 'OPENAI_API_KEY',
        'model' => 'gpt-4o-mini',
        'label' => 'OpenAI',
        'url'   => 'https://platform.openai.com/api-keys',
    ],
    'gemini' => [
        'env'   => 'GEMINI_API_KEY',
        'model' => 'gemini-2.0-flash',
        'label' => 'Google Gemini',
        'url'   => 'https://aistudio.google.com/apikey',
    ],
];

$onlyLocale       = null;
$dryRun           = false;
$limit            = null;
$batchSize        = 40;
$provider         = 'anthropic';
$providerExplicit = false; // true once --provider= is seen; suppresses the interactive picker below
$model            = null; // resolved to PROVIDERS[$provider]['model'] below if not set explicitly.
$timeBudget       = null; // seconds; null = unlimited (see --time-budget below)

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($arg, '--locale=')) {
        $onlyLocale = substr($arg, strlen('--locale='));
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, strlen('--limit='));
    } elseif (str_starts_with($arg, '--batch-size=')) {
        $batchSize = max(1, (int) substr($arg, strlen('--batch-size=')));
    } elseif (str_starts_with($arg, '--provider=')) {
        $provider = substr($arg, strlen('--provider='));
        $providerExplicit = true;
    } elseif (str_starts_with($arg, '--model=')) {
        $model = substr($arg, strlen('--model='));
    } elseif (str_starts_with($arg, '--time-budget=')) {
        $timeBudget = max(1, (int) substr($arg, strlen('--time-budget=')));
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php bin/translate-missing.php [--dry-run] [--locale=xx] [--limit=N] [--batch-size=N] [--provider=anthropic|openai|gemini] [--model=NAME] [--time-budget=SECONDS]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
}

/**
 * Interactively ask which provider to use, when --provider= wasn't passed
 * on the command line. Returns $default unchanged (never prompting) if
 * STDIN isn't an interactive TTY — e.g. CI, a pipe, or a non-interactive
 * shell — same non-hanging fallback as prompt_for_api_key() below — or
 * if the reply is empty (plain Enter) or unrecognized.
 */
function prompt_for_provider(string $default): string
{
    if (function_exists('posix_isatty') && !posix_isatty(STDIN)) {
        return $default;
    }

    fwrite(STDOUT, "Which AI provider? [1] Anthropic  [2] OpenAI  [3] Gemini (Enter for Anthropic): ");
    $answer = strtolower(trim((string) fgets(STDIN)));

    return match ($answer) {
        '', '1', 'anthropic' => 'anthropic',
        '2', 'openai'        => 'openai',
        '3', 'gemini'        => 'gemini',
        default              => $default,
    };
}

if (!$providerExplicit) {
    $provider = prompt_for_provider($provider);
}

if (!isset(PROVIDERS[$provider])) {
    fwrite(STDERR, "Unknown --provider: {$provider}. Supported: " . implode(', ', array_keys(PROVIDERS)) . "\n");
    exit(1);
}
$providerConfig = PROVIDERS[$provider];
if ($model === null) {
    $model = $providerConfig['model'];
    fwrite(STDERR, "No --model given — defaulting to {$model} for {$providerConfig['label']}. Provider model names move fast; verify this is still current, or pin one with --model=NAME.\n");
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "PHP's curl extension is required but not available.\n");
    exit(1);
}

/**
 * Prompt for the API key at an interactive terminal, input hidden (via
 * `stty -echo` on Unix-likes; visible on Windows, which has no
 * equivalent builtin `stty`). Returns '' if input isn't a real TTY
 * (piped/non-interactive invocation) rather than hanging forever
 * waiting for a line that will never come.
 */
function prompt_for_api_key(string $providerLabel, string $envVar): string
{
    if (function_exists('posix_isatty') && !posix_isatty(STDIN)) {
        return '';
    }

    fwrite(STDOUT, "{$envVar} is not set in the environment.\nEnter your {$providerLabel} API key now (input hidden; used for this run only, never saved anywhere): ");

    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if (!$isWindows) {
        shell_exec('stty -echo 2>/dev/null');
    }
    $key = trim((string) fgets(STDIN));
    if (!$isWindows) {
        shell_exec('stty echo 2>/dev/null');
    }
    fwrite(STDOUT, "\n");

    return $key;
}

$apiKey = getenv($providerConfig['env']);
if ($apiKey === false || $apiKey === '') {
    $apiKey = prompt_for_api_key($providerConfig['label'], $providerConfig['env']);
}
if ($apiKey === '') {
    fwrite(STDERR, "No API key provided for {$providerConfig['label']}. Either export it before running:\n  export {$providerConfig['env']}=...\nor re-run interactively and enter it at the prompt. Get a key at {$providerConfig['url']}\n");
    exit(1);
}

// Clock starts here, not at script entry — so time spent waiting on a human
// typing an interactive API key doesn't count against --time-budget.
$scriptStart = microtime(true);

const LOCALE_NAMES = [
    'ar'    => 'Arabic',
    'ca'    => 'Catalan',
    'de_DE' => 'German',
    'en_US' => 'English (US) — the source strings are already English; only Americanize any British spelling that slipped in (colour→color, organise→organize, etc.), otherwise return the source unchanged',
    'es_ES' => 'Spanish (Spain)',
    'fa_IR' => 'Persian (Farsi)',
    'fr_FR' => 'French (France)',
    'hi_IN' => 'Hindi',
    'id_ID' => 'Indonesian',
    'it_IT' => 'Italian',
    'ja'    => 'Japanese',
    'nl_NL' => 'Dutch',
    'pt_PT' => 'Portuguese (Portugal)',
    'ru_RU' => 'Russian',
    'sw'    => 'Swahili',
    'tr_TR' => 'Turkish',
    'ur'    => 'Urdu',
    'zh_CN' => 'Chinese (Simplified)',
];

const DOMAIN_PROMPT = <<<'PROMPT'
You are translating UI strings for Agnosis, a WordPress plugin ("Blooming out of oblivion") that lets independent artists publish by email. An artist emails their artwork plus a short description; the plugin receives it, an AI classifies/describes/enhances it as configured, and auto-publishes a gallery post — federated to the Fediverse (Mastodon, Pixelfed, etc.) via ActivityPub, no central server (rhizome network model). Key vocabulary you will see in these strings and should translate consistently:
- "artist" — the person submitting work
- "artwork" / "submission" — a piece being published
- "medium" — the artwork's category/technique (e.g. Watercolor, Photography) — a taxonomy term, not a metaphor
- "admission" — the community-vouching process for a new artist to join a node
- "pure@" (a literal email-address lane name) — the publishing lane where content is never touched by AI, only classified
- "gallery" — the published collection of artworks on a node
- "digest" — a periodic summary email
- "bounce" — a failed email delivery
- these strings are WordPress admin-dashboard and notification-email UI text — keep the register plain, direct, and functional, matching ordinary WordPress plugin UI copy, not marketing language

Translation rules, no exceptions:
1. Preserve every placeholder EXACTLY as given: %s, %d, %1$s, %2$d, etc. Never translate, drop, or reorder them unless the target language's grammar requires reordering — if so, convert to explicit positional form (%1$s, %2$s, ...) consistently.
2. Preserve HTML tags exactly (e.g. <a href="...">...</a>) — translate only the text between tags, never the tags or attributes.
3. Prefer gender-neutral phrasing where the target language allows it naturally, rather than defaulting to a masculine or feminine form — this mirrors an explicit house style already used elsewhere in this plugin's own AI translation prompts.
4. Where a "reference" (already-translated sibling text in the same language) is given for a plural entry, match its exact register, terminology choices, and grammatical pattern — extend it correctly to the requested plural category, using standard grammatical agreement for that language and count range.
5. Where no reference is given, translate naturally for a native speaker of the target locale; this is a first pass, not a final professional translation, so prioritize correctness and natural phrasing over cleverness.

You will be given a JSON array of items to translate, each with a stable "id". Respond with ONLY a single raw JSON object mapping each id to its translated string — no markdown fences, no commentary, no extra keys, no omitted ids.
PROMPT;

/**
 * Unescape / escape a PO string literal's inner text.
 */
function po_unescape(string $inner): string
{
    return str_replace(['\\n', '\\t', '\\"', '\\\\'], ["\n", "\t", '"', '\\'], $inner);
}
function po_escape(string $raw): string
{
    return str_replace(['\\', '"', "\n", "\t"], ['\\\\', '\\"', '\\n', '\\t'], $raw);
}

function parse_nplurals(string $poText): ?int
{
    if (preg_match('/Plural-Forms:\s*nplurals\s*=\s*(\d+)\s*;/', $poText, $m)) {
        return (int) $m[1];
    }
    return null;
}
function parse_plural_formula(string $poText): string
{
    if (preg_match('/Plural-Forms:\s*nplurals\s*=\s*\d+;\s*plural\s*=\s*([^;]*);/', $poText, $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * Grab the nearest "#. translators:" comment directly above a msgid
 * start line, if present — useful context for the model, e.g. "%d:
 * number of submissions carrying this exact proposal".
 */
function translator_comment(array $lines, int $msgidLine): ?string
{
    for ($back = 1; $back <= 6; $back++) {
        $idx = $msgidLine - $back;
        if ($idx < 0) {
            break;
        }
        if (preg_match('/^#\.\s*translators:\s*(.+)$/', $lines[$idx], $m)) {
            return trim($m[1]);
        }
        if ($lines[$idx] === '' || preg_match('/^msgid/', $lines[$idx])) {
            break;
        }
    }
    return null;
}

/**
 * Walk a .po file and yield BOTH singular and plural entries as a
 * unified list, each tagged 'type' => 'single'|'plural'. Multi-line
 * (wrapped) msgid/msgid_plural text is concatenated correctly; entries
 * whose msgstr/msgstr[N] slot(s) are themselves wrapped across multiple
 * lines are flagged 'multiline_warning' and never written to.
 */
function parse_entries(array $lines): array
{
    $entries = [];
    $i = 0;
    $n = count($lines);

    while ($i < $n) {
        $line = $lines[$i];
        $isMsgidLine = preg_match('/^msgid\s+"(.*)"\s*$/', $line, $m) && $m[1] !== '' || preg_match('/^msgid\s+""\s*$/', $line);

        if ($isMsgidLine) {
            $msgidStart = $i;
            $msgidParts = [];
            if (preg_match('/^msgid\s+"(.*)"\s*$/', $line, $m)) {
                $msgidParts[] = $m[1];
            }
            $j = $i + 1;
            while ($j < $n && preg_match('/^"(.*)"\s*$/', $lines[$j], $cm)) {
                $msgidParts[] = $cm[1];
                $j++;
            }
            $msgid = po_unescape(implode('', $msgidParts));

            // Plural?
            if ($j < $n && preg_match('/^msgid_plural\s+"(.*)"\s*$/', $lines[$j], $pm)) {
                $pluralParts = [$pm[1]];
                $k = $j + 1;
                while ($k < $n && preg_match('/^"(.*)"\s*$/', $lines[$k], $cm)) {
                    $pluralParts[] = $cm[1];
                    $k++;
                }
                $msgidPlural = po_unescape(implode('', $pluralParts));

                $slots = [];
                $m2 = $k;
                $multilineWarning = false;
                while ($m2 < $n && preg_match('/^msgstr\[(\d+)\]\s+"(.*)"\s*$/', $lines[$m2], $sm)) {
                    $idx = (int) $sm[1];
                    $rawInner = $sm[2];
                    if ($m2 + 1 < $n && preg_match('/^"(.*)"\s*$/', $lines[$m2 + 1]) && !preg_match('/^(msgstr\[|msgid|msgctxt|#)/', $lines[$m2 + 1])) {
                        $multilineWarning = true;
                    }
                    $slots[$idx] = [
                        'line'  => $m2,
                        'text'  => po_unescape($rawInner),
                        'empty' => $rawInner === '',
                    ];
                    $m2++;
                }

                $entries[] = [
                    'type'              => 'plural',
                    'msgid'             => $msgid,
                    'msgid_plural'      => $msgidPlural,
                    'slots'             => $slots,
                    'multiline_warning' => $multilineWarning,
                    'start_line'        => $msgidStart,
                    'comment'           => translator_comment($lines, $msgidStart),
                ];
                $i = $m2;
                continue;
            }

            // Singular: msgstr "..." right after the (possibly multi-line) msgid.
            if ($j < $n && preg_match('/^msgstr\s+"(.*)"\s*$/', $lines[$j], $sm)) {
                $rawInner = $sm[1];
                $multilineWarning = ($j + 1 < $n
                    && preg_match('/^"(.*)"\s*$/', $lines[$j + 1])
                    && !preg_match('/^(msgid|msgctxt|#)/', $lines[$j + 1]));

                // Skip the file's own header entry: empty msgid + non-empty msgstr
                // containing the Content-Type/Plural-Forms block.
                $isHeader = ($msgid === '' && $rawInner !== '');

                if (!$isHeader) {
                    $entries[] = [
                        'type'              => 'single',
                        'msgid'             => $msgid,
                        'line'              => $j,
                        'text'              => po_unescape($rawInner),
                        'empty'             => $rawInner === '',
                        'multiline_warning' => $multilineWarning,
                        'start_line'        => $msgidStart,
                        'comment'           => translator_comment($lines, $msgidStart),
                    ];
                }
                $i = $j + ($multilineWarning ? 2 : 1);
                continue;
            }
        }

        $i++;
    }

    return $entries;
}

/**
 * Strip markdown fences defensively (even though every provider's prompt
 * forbids them) and parse the model's raw text response as the {id: text}
 * JSON object all three providers are asked for identically. Returns null
 * on parse failure rather than throwing, so callers can log and move on.
 */
function parse_json_response(string $text): ?array
{
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*/', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);

    $parsed = json_decode($text, true);
    return is_array($parsed) ? $parsed : null;
}

/**
 * Each provider reports token usage under different key names. Normalize
 * to a common ['input_tokens' => int, 'output_tokens' => int] shape so
 * the main loop's cost tally doesn't need to know which provider ran.
 */
function normalize_usage(string $provider, ?array $raw): ?array
{
    if ($raw === null) {
        return null;
    }
    return match ($provider) {
        'anthropic' => ['input_tokens' => (int) ($raw['input_tokens'] ?? 0), 'output_tokens' => (int) ($raw['output_tokens'] ?? 0)],
        'openai'    => ['input_tokens' => (int) ($raw['prompt_tokens'] ?? 0), 'output_tokens' => (int) ($raw['completion_tokens'] ?? 0)],
        'gemini'    => ['input_tokens' => (int) ($raw['promptTokenCount'] ?? 0), 'output_tokens' => (int) ($raw['candidatesTokenCount'] ?? 0)],
        default     => null,
    };
}

/**
 * Shared POST-JSON-with-retry: one retry on a curl-level failure or a 5xx,
 * none on 4xx (bad key/request — retrying won't help). Returns
 * [$decodedBodyOrNull, $httpStatus] — callers still print their own error
 * detail since only they know how to extract the useful bit of a given
 * provider's error payload.
 */
function post_json_with_retry(string $url, array $headers, string $body): array
{
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 120,
        ]);
        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            fwrite(STDERR, "  curl error: {$curlErr}" . ($attempt < 2 ? " — retrying...\n" : "\n"));
            sleep(2);
            continue;
        }
        if ($status >= 500 && $attempt < 2) {
            fwrite(STDERR, "  API {$status} — retrying...\n");
            sleep(2);
            continue;
        }
        if ($status !== 200) {
            fwrite(STDERR, "  API error {$status}: " . substr($response, 0, 500) . "\n");
            return [null, $status];
        }

        return [json_decode($response, true), $status];
    }
    return [null, 0];
}

/**
 * One batch call to the Anthropic Messages API. Returns [assocArrayOfIdToText, usage].
 */
function call_anthropic(string $apiKey, string $model, string $system, array $items): array
{
    $userPayload = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $body = json_encode([
        'model'      => $model,
        'max_tokens' => min(8192, max(2048, count($items) * 150)),
        'system'     => $system,
        'messages'   => [
            ['role' => 'user', 'content' => "Translate this batch:\n\n" . $userPayload],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    [$decoded, $status] = post_json_with_retry('https://api.anthropic.com/v1/messages', [
        'content-type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ], $body);
    if ($status !== 200 || $decoded === null) {
        return [[], null];
    }

    $text  = $decoded['content'][0]['text'] ?? '';
    $usage = normalize_usage('anthropic', $decoded['usage'] ?? null);
    $parsed = parse_json_response($text);
    if ($parsed === null) {
        fwrite(STDERR, "  Could not parse model response as JSON: " . substr($text, 0, 300) . "\n");
        return [[], $usage];
    }
    return [$parsed, $usage];
}

/**
 * One batch call to the OpenAI Chat Completions API. Returns [assocArrayOfIdToText, usage].
 */
function call_openai(string $apiKey, string $model, string $system, array $items): array
{
    $userPayload = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $body = json_encode([
        'model'      => $model,
        'max_tokens' => min(8192, max(2048, count($items) * 150)),
        'messages'   => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => "Translate this batch:\n\n" . $userPayload],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    [$decoded, $status] = post_json_with_retry('https://api.openai.com/v1/chat/completions', [
        'content-type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ], $body);
    if ($status !== 200 || $decoded === null) {
        return [[], null];
    }

    $text  = $decoded['choices'][0]['message']['content'] ?? '';
    $usage = normalize_usage('openai', $decoded['usage'] ?? null);
    $parsed = parse_json_response($text);
    if ($parsed === null) {
        fwrite(STDERR, "  Could not parse model response as JSON: " . substr($text, 0, 300) . "\n");
        return [[], $usage];
    }
    return [$parsed, $usage];
}

/**
 * One batch call to Google's Generative Language API (Gemini). Returns
 * [assocArrayOfIdToText, usage]. Auth is a `key=` query param, not a
 * header — that's Gemini's own REST convention, unlike Anthropic/OpenAI.
 */
function call_gemini(string $apiKey, string $model, string $system, array $items): array
{
    $userPayload = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $body = json_encode([
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents'          => [
            ['role' => 'user', 'parts' => [['text' => "Translate this batch:\n\n" . $userPayload]]],
        ],
        'generationConfig' => [
            'maxOutputTokens' => min(8192, max(2048, count($items) * 150)),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

    [$decoded, $status] = post_json_with_retry($url, ['content-type: application/json'], $body);
    if ($status !== 200 || $decoded === null) {
        return [[], null];
    }

    $text  = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $usage = normalize_usage('gemini', $decoded['usageMetadata'] ?? null);
    $parsed = parse_json_response($text);
    if ($parsed === null) {
        fwrite(STDERR, "  Could not parse model response as JSON: " . substr($text, 0, 300) . "\n");
        return [[], $usage];
    }
    return [$parsed, $usage];
}

/**
 * Dispatch a translation batch to whichever provider was selected.
 */
function call_ai(string $provider, string $apiKey, string $model, string $system, array $items): array
{
    return match ($provider) {
        'anthropic' => call_anthropic($apiKey, $model, $system, $items),
        'openai'    => call_openai($apiKey, $model, $system, $items),
        'gemini'    => call_gemini($apiKey, $model, $system, $items),
        default     => throw new \InvalidArgumentException("Unknown provider: {$provider}"),
    };
}

// ---------------------------------------------------------------------
// Build the work list: every empty single/plural slot, per locale.
// ---------------------------------------------------------------------
$poFiles = glob($langDir . '/agnosis-*.po');
sort($poFiles);
if ($poFiles === [] || $poFiles === false) {
    fwrite(STDERR, "No languages/agnosis-*.po files found.\n");
    exit(1);
}

$work = []; // locale => ['path'=>, 'lines'=>, 'items'=>[ [id, prompt-payload, apply-info] ]]

foreach ($poFiles as $poPath) {
    $locale = preg_replace('/^agnosis-|\.po$/', '', basename($poPath));
    if ($onlyLocale !== null && $locale !== $onlyLocale) {
        continue;
    }
    if (!isset(LOCALE_NAMES[$locale])) {
        fwrite(STDERR, "Skipping unknown locale (no name mapping): {$locale}\n");
        continue;
    }

    $poText   = (string) file_get_contents($poPath);
    $nplurals = parse_nplurals($poText) ?? 2;
    $formula  = parse_plural_formula($poText);
    $lines    = explode("\n", $poText);
    $entries  = parse_entries($lines);

    $items = [];
    foreach ($entries as $entry) {
        if ($entry['multiline_warning']) {
            continue; // never touched — same policy as fill-loco-gaps.php
        }
        if ($entry['type'] === 'single') {
            if (!$entry['empty']) {
                continue;
            }
            $id = 's' . count($items);
            $items[] = [
                'id'      => $id,
                'payload' => array_filter([
                    'type'    => 'single',
                    'source'  => $entry['msgid'],
                    'comment' => $entry['comment'],
                ]),
                'apply' => ['line' => $entry['line']],
            ];
        } else {
            foreach ($entry['slots'] as $idx => $slot) {
                if (!$slot['empty']) {
                    continue;
                }
                $siblings = [];
                foreach ($entry['slots'] as $sIdx => $sSlot) {
                    if ($sIdx !== $idx && !$sSlot['empty']) {
                        $siblings[(string) $sIdx] = $sSlot['text'];
                    }
                }
                $id = 'p' . count($items);
                $items[] = [
                    'id'      => $id,
                    'payload' => array_filter([
                        'type'              => 'plural',
                        'source_singular'   => $entry['msgid'],
                        'source_plural'     => $entry['msgid_plural'],
                        'nplurals'          => $nplurals,
                        'plural_formula'    => $formula,
                        'target_slot_index' => $idx,
                        'reference_translations_other_slots' => $siblings ?: null,
                        'comment'           => $entry['comment'],
                    ]),
                    'apply' => ['line' => $slot['line'], 'slot' => $idx],
                ];
            }
        }
    }

    if ($items !== []) {
        $work[$locale] = ['path' => $poPath, 'lines' => $lines, 'items' => $items];
    }
}

$totalItems = array_sum(array_map(fn($w) => count($w['items']), $work));
if ($totalItems === 0) {
    echo "Nothing to translate — every locale is fully filled.\n";
    exit(0);
}
fwrite(STDERR, "{$totalItems} missing string(s) across " . count($work) . " locale(s), via {$providerConfig['label']} ({$model})" . ($dryRun ? " (dry run)" : "") . ".\n");

$logLines = [];
$totalIn  = 0;
$totalOut = 0;
$totalWritten = 0;
$processed = 0;

foreach ($work as $locale => $w) {
    $langName = LOCALE_NAMES[$locale];
    $items    = $w['items'];
    $lines    = $w['lines'];
    $path     = $w['path'];

    $chunks           = array_chunk($items, $batchSize);
    $totalChunks      = count($chunks);
    $writtenForLocale = 0;

    foreach ($chunks as $chunkIndex => $chunk) {
        // Stop cleanly BEFORE starting a new batch if the caller's time budget
        // is spent — better than being SIGKILL'd mid-curl_exec() by whatever
        // external wrapper enforces its own process timeout (e.g. a 300s cap),
        // which would waste that batch's API cost with nothing saved for it.
        // Progress is already saved after every completed batch, so re-running
        // the same command picks up exactly where this run stopped.
        if ($timeBudget !== null && (microtime(true) - $scriptStart) >= $timeBudget) {
            fwrite(STDERR, "Time budget of {$timeBudget}s reached — stopping cleanly after {$processed}/{$totalItems} item(s). Progress is saved batch-by-batch; re-run the same command to continue.\n");
            break 2;
        }
        if ($limit !== null && $processed >= $limit) {
            break 2;
        }
        if ($limit !== null) {
            $chunk = array_slice($chunk, 0, max(0, $limit - $processed));
            if ($chunk === []) {
                break 2;
            }
        }

        $system = DOMAIN_PROMPT . "\n\nTarget language for this batch: {$langName} (locale code: {$locale}).";
        $payloadItems = array_map(fn($it) => ['id' => $it['id']] + $it['payload'], $chunk);

        // Batch counter (within this locale) + running total (across every
        // locale) so a locale needing several batches doesn't read as stuck
        // repeating itself — each line is visibly a different, advancing step.
        fwrite(STDERR, "[{$locale}] translating " . count($chunk) . " item(s) via {$providerConfig['label']} (batch " . ($chunkIndex + 1) . "/{$totalChunks} · {$processed}/{$totalItems} overall)...\n");
        [$result, $usage] = call_ai($provider, $apiKey, $model, $system, $payloadItems);
        if ($usage !== null) {
            $totalIn  += $usage['input_tokens']  ?? 0;
            $totalOut += $usage['output_tokens'] ?? 0;
        }

        $batchWritten = 0;
        foreach ($chunk as $it) {
            $id = $it['id'];
            if (!isset($result[$id]) || !is_string($result[$id]) || $result[$id] === '') {
                fwrite(STDERR, "  [{$locale}] no translation returned for {$id} — skipped.\n");
                continue;
            }
            $translated = $result[$id];
            $apply = $it['apply'];

            if (isset($apply['slot'])) {
                $newLine = "msgstr[{$apply['slot']}] \"" . po_escape($translated) . '"';
                $logLines[] = "[{$locale}] plural slot {$apply['slot']}: " . json_encode($it['payload']['source_singular']) . " -> " . json_encode($translated);
            } else {
                $newLine = 'msgstr "' . po_escape($translated) . '"';
                $logLines[] = "[{$locale}] " . json_encode($it['payload']['source']) . " -> " . json_encode($translated);
            }

            if (!$dryRun) {
                $lines[$apply['line']] = $newLine;
                $totalWritten++;
                $batchWritten++;
            } else {
                echo "  {$newLine}\n";
            }
        }
        $processed += count($chunk);

        // Persist after EVERY batch, not just once the whole locale is done —
        // so Ctrl-C (or a crash) mid-locale keeps whatever that locale already
        // translated instead of discarding a partially-finished locale and
        // re-paying for those same strings on the next run.
        if (!$dryRun && $batchWritten > 0) {
            file_put_contents($path, implode("\n", $lines));
            $writtenForLocale += $batchWritten;
            fwrite(STDERR, "  ↳ saved (batch " . ($chunkIndex + 1) . "/{$totalChunks}, {$writtenForLocale}/" . count($items) . " strings so far for {$locale})\n");
        }
    }

    if (!$dryRun && $writtenForLocale > 0) {
        echo "✓ wrote " . basename($path) . " ({$writtenForLocale} string(s))\n";
    }
}

if ($logLines !== []) {
    file_put_contents($logFile, implode("\n", $logLines) . "\n", FILE_APPEND);
}

$costNote = ($totalIn + $totalOut > 0)
    ? "input_tokens={$totalIn} output_tokens={$totalOut} on {$providerConfig['label']} ({$model}) — see that provider's current per-model pricing for the cost this represents"
    : "no usage data returned";
echo ($dryRun ? "Dry run complete" : "Applied {$totalWritten} translation(s)") . ". {$costNote}.\n";
echo "Log: {$logFile}\n";
