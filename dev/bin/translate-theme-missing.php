#!/usr/bin/env php
<?php
/**
 * translate-theme-missing.php — AI-translate every empty string in the
 * sibling agnosis-theme repo's languages/agnosis-theme-*.po files
 * directly. Theme-scoped twin of this repo's own translate-missing.php
 * (T-3, fifteenth audit — the theme's brand-new i18n catalog needs the
 * same on-demand AI-fill tooling the plugin already has, rather than a
 * second manual Loco Translate workflow).
 *
 * Lives here, not in agnosis-theme/, for the same reason make-theme-pot.sh
 * and build-theme-zip.sh do: the theme repo deliberately carries no build
 * tooling of its own (see its own README.md), and this repo's dev/
 * environment is already where both products' release tooling lives
 * side by side.
 *
 * Finds every empty msgstr/msgstr[N] across every theme locale —
 * ordinary untranslated strings AND plural-slot gaps in one pass — and
 * fills them by calling an AI provider with a prompt that knows what the
 * Agnosis Theme actually is (a companion FSE block theme, not the plugin
 * itself), so translations land in the right register.
 *
 * This is a narrow, ON-DEMAND tool (composer translate-theme-missing),
 * same spirit as the plugin's own translate-missing.php — not wired into
 * make-theme-pot.sh/compile-theme-pos.sh, not run automatically on every
 * version bump.
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
 * Writes AI translations DIRECTLY into the .po files (same trade-off
 * discussed for the plugin's own tool — see AUDIT-0.9.44.md §5d) — and
 * saves after EVERY batch, not just once a whole locale finishes, so an
 * interrupted run (Ctrl-C, a crash, or an external wrapper killing the
 * process on its own timeout) keeps whatever was already translated.
 * Every write is also appended to dev/translate-theme-missing.log for a
 * fast post-hoc skim; nothing here claims professional/native-speaker
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
 *   php bin/translate-theme-missing.php                    # all locales, all gaps, Anthropic Haiku
 *   php bin/translate-theme-missing.php --dry-run           # call the API, print results, write nothing
 *   php bin/translate-theme-missing.php --locale=ar         # scope to one locale
 *   php bin/translate-theme-missing.php --limit=10          # cap items translated (testing/cost control)
 *   php bin/translate-theme-missing.php --batch-size=40      # MAX items per API call (default 40) — a
 *                                                       # batch may still be split smaller than this
 *                                                       # if its items' combined content is long
 *                                                       # enough to need it (see MAX_CHARS_PER_CHUNK)
 *   php bin/translate-theme-missing.php --provider=openai --model=gpt-4o-mini   # use OpenAI instead
 *   php bin/translate-theme-missing.php --provider=gemini --model=gemini-2.0-flash  # use Gemini instead
 *   php bin/translate-theme-missing.php --time-budget=270   # stop cleanly under a 300s wrapper timeout; re-run to resume
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

$devDir  = dirname(__DIR__);
$langDir = $devDir . '/../../agnosis-theme/languages';
$logFile = $devDir . '/translate-theme-missing.log';

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

// Max combined source-character count per batch, regardless of item count.
// Paired with estimate_max_tokens() below — sized so that a chunk right at
// this budget still lands comfortably under the 8192 max_tokens/
// maxOutputTokens cap once translated/JSON-escaped, rather than exactly at
// it. See chunk_items_by_budget().
const MAX_CHARS_PER_CHUNK = 2600;

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
        fwrite(STDOUT, "Usage: php bin/translate-theme-missing.php [--dry-run] [--locale=xx] [--limit=N] [--batch-size=N] [--provider=anthropic|openai|gemini] [--model=NAME] [--time-budget=SECONDS]\n");
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
You are translating UI strings for Agnosis Theme, a WordPress Full Site Editing block theme built specifically for the Agnosis plugin ("Blooming out of oblivion") — a plugin that lets independent artists publish by email and auto-publishes each submission as a gallery post, federated to the Fediverse via ActivityPub. The theme itself is thin: most of what a visitor sees is content and blocks the plugin provides, so the theme's OWN translatable strings are just a handful of front-end UI messages plus its self-hosted-update mechanism. Key vocabulary you will see in these strings and should translate consistently:
- "artist" — the person submitting work
- "artwork" / "submission" — a piece being published
- "gallery" — the published collection of artworks on a node
- these strings are front-end, visitor-facing UI text (404 page, search-with-no-results, empty archive states, an update-checker settings string) — keep the register plain, direct, and functional, matching ordinary WordPress theme UI copy, not marketing language
- some entries (a plain "·" separator character, the theme/author name/URL) are deliberately left untranslated in the reference English — if a source string is punctuation-only or a proper name/URL with nothing to translate, return it unchanged

Translation rules, no exceptions:
1. Preserve every placeholder EXACTLY as given: %s, %d, %1$s, %2$d, etc. Never translate, drop, or reorder them unless the target language's grammar requires reordering — if so, convert to explicit positional form (%1$s, %2$s, ...) consistently.
2. Preserve HTML tags exactly (e.g. <a href="...">...</a>) — translate only the text between tags, never the tags or attributes.
3. Prefer gender-neutral phrasing where the target language allows it naturally, rather than defaulting to a masculine or feminine form — this mirrors an explicit house style already used elsewhere in this plugin's own AI translation prompts.
4. Where a "reference" (already-translated sibling text in the same language) is given for a plural entry, match its exact register, terminology choices, and grammatical pattern — extend it correctly to the requested plural category, using standard grammatical agreement for that language and count range.
5. Where no reference is given, translate naturally for a native speaker of the target locale; this is a first pass, not a final professional translation, so prioritize correctness and natural phrasing over cleverness.
6. Some items include an "existing_translation" and "missing_placeholders" — these are NOT empty strings, they're translations that already read naturally but are missing a required placeholder (most often because the target language's plural form doesn't grammatically need to state the number). FIX the existing translation by inserting the listed missing placeholder(s) — keep the existing phrasing/register; don't retranslate from scratch unless the existing text is otherwise wrong.

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
 * Grab the nearest "#, ..." flags comment directly above a msgid start
 * line, if present — split on commas, e.g. "#, fuzzy, php-format" becomes
 * ['fuzzy', 'php-format']. Used to scope the format-placeholder check
 * (below) to entries gettext itself considers format strings, rather than
 * flagging any translation that happens to contain a literal "%s"/"%d".
 * Same helper as the plugin's own translate-missing.php.
 */
function entry_flags(array $lines, int $msgidLine): array
{
    for ($back = 1; $back <= 6; $back++) {
        $idx = $msgidLine - $back;
        if ($idx < 0) {
            break;
        }
        if (preg_match('/^#,\s*(.+)$/', $lines[$idx], $m)) {
            return array_map('trim', explode(',', $m[1]));
        }
        if ($lines[$idx] === '' || preg_match('/^msgid/', $lines[$idx])) {
            break;
        }
    }
    return [];
}

/**
 * Compare a translation's placeholders against the source's expected set
 * (already-sorted list). Returns the sorted list of placeholders the
 * translation is missing (multiset difference), or [] if nothing is
 * missing. Same helper as the plugin's own translate-missing.php.
 */
function missing_placeholders(array $expectedSorted, string $translated): array
{
    $missing = $expectedSorted;
    foreach (extract_placeholders($translated) as $found) {
        $pos = array_search($found, $missing, true);
        if ($pos !== false) {
            unset($missing[$pos]);
        }
    }
    return array_values($missing);
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
                $anySlotMultilineWarning = false;
                while ($m2 < $n && preg_match('/^msgstr\[(\d+)\]\s+"(.*)"\s*$/', $lines[$m2], $sm)) {
                    $idx           = (int) $sm[1];
                    $slotStartLine = $m2;
                    $rawParts      = [$sm[2]];
                    $m2++;

                    // Ritual-row fix (fifteenth audit, 2026-07-24 — ported
                    // from translate-missing.php): the old version stopped
                    // this whole while() the instant it hit a line not
                    // starting with "msgstr[", which is exactly what a
                    // wrapped slot's OWN continuation line looks like — so a
                    // wrapped msgstr[0] silently truncated parsing of every
                    // slot after it, meaning later slots were never even
                    // discovered, let alone considered for translation. Every
                    // continuation line belonging to THIS slot is now
                    // consumed (and concatenated, same as msgid/msgid_plural
                    // above) before looking for the next msgstr[N] line.
                    $slotMultilineWarning = false;
                    while ($m2 < $n
                        && preg_match('/^"(.*)"\s*$/', $lines[$m2], $cm)
                        && !preg_match('/^(msgstr\[|msgid|msgctxt|#)/', $lines[$m2])
                    ) {
                        $rawParts[] = $cm[1];
                        $slotMultilineWarning = true;
                        $m2++;
                    }
                    if ($slotMultilineWarning) {
                        $anySlotMultilineWarning = true;
                    }

                    $rawInner = implode('', $rawParts);
                    $slots[$idx] = [
                        'line'              => $slotStartLine,
                        'text'              => po_unescape($rawInner),
                        'empty'             => $rawInner === '',
                        'multiline_warning' => $slotMultilineWarning,
                    ];
                }

                $entries[] = [
                    'type'              => 'plural',
                    'msgid'             => $msgid,
                    'msgid_plural'      => $msgidPlural,
                    'slots'             => $slots,
                    'multiline_warning' => $anySlotMultilineWarning,
                    'start_line'        => $msgidStart,
                    'comment'           => translator_comment($lines, $msgidStart),
                    'flags'             => entry_flags($lines, $msgidStart),
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
                        'flags'             => entry_flags($lines, $msgidStart),
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
 *
 * Uses tool use (function calling) rather than asking for raw JSON in a
 * text block. Several source strings quote another setting's name inline
 * (e.g. `see "OpenAI translation model" below`) — Claude Haiku reliably
 * reproduces that literal `"` inside its translated text but doesn't
 * always backslash-escape it, which breaks json_decode() at exactly that
 * point (JSON has no partial-recovery, so the WHOLE batch was lost even
 * though every other item translated fine — this is what broke de_DE/
 * zh_CN in production; OpenAI's plain chat-completions text happened not
 * to hit it, which is why --provider=openai worked around the same batch).
 * Tool use has Anthropic validate the response against our schema and hand
 * back an already-decoded array — no raw-text JSON parsing, and therefore
 * no quote-escaping failure mode, on our end at all.
 */
function call_anthropic(string $apiKey, string $model, string $system, array $items): array
{
    $properties = [];
    $required   = [];
    foreach ($items as $item) {
        $properties[$item['id']] = ['type' => 'string'];
        $required[] = $item['id'];
    }

    $userPayload = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $body = json_encode([
        'model'      => $model,
        'max_tokens' => estimate_max_tokens($items),
        'system'     => $system,
        'messages'   => [
            ['role' => 'user', 'content' => "Translate this batch:\n\n" . $userPayload],
        ],
        'tools' => [[
            'name'         => 'provide_translations',
            'description'  => 'Return the translated string for every item id in the batch.',
            'input_schema' => [
                'type'                 => 'object',
                'properties'           => $properties,
                'required'             => $required,
                'additionalProperties' => false,
            ],
        ]],
        'tool_choice' => ['type' => 'tool', 'name' => 'provide_translations'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    [$decoded, $status] = post_json_with_retry('https://api.anthropic.com/v1/messages', [
        'content-type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ], $body);
    if ($status !== 200 || $decoded === null) {
        return [[], null];
    }

    $usage = normalize_usage('anthropic', $decoded['usage'] ?? null);

    $parsed = null;
    foreach ($decoded['content'] ?? [] as $block) {
        if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'provide_translations') {
            $parsed = $block['input'] ?? null;
            break;
        }
    }
    if (!is_array($parsed)) {
        $preview = mb_substr((string) ($decoded['content'][0]['text'] ?? json_encode($decoded)), 0, 300);
        fwrite(STDERR, "  Could not extract tool_use translations from response: {$preview}\n");
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
        'max_tokens' => estimate_max_tokens($items),
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
        fwrite(STDERR, "  Could not parse model response as JSON: " . mb_substr($text, 0, 300) . "\n");
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
            'maxOutputTokens' => estimate_max_tokens($items),
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
        fwrite(STDERR, "  Could not parse model response as JSON: " . mb_substr($text, 0, 300) . "\n");
        return [[], $usage];
    }
    return [$parsed, $usage];
}

/**
 * Recursively sum the character length of every string value in a payload
 * (handles nested arrays like reference_translations_other_slots, the
 * sibling-plural-slot map on plural entries).
 */
/**
 * Extract every printf-style placeholder from a string: %s, %d, %1$s,
 * %2$d, etc. — same helper as the plugin's own translate-missing.php, used
 * here only for the excess-placeholder defensive guard below (this script
 * doesn't carry that file's fuller I-2 already-translated-but-broken
 * detection, since the theme catalog has no plural entries today).
 */
function extract_placeholders(string $s): array
{
    preg_match_all('/%(?:\d+\$)?[sdfeEgGxXou]/', $s, $m);
    return $m[0];
}

function payload_char_length($value): int
{
    if (is_string($value)) {
        return mb_strlen($value);
    }
    if (is_array($value)) {
        $sum = 0;
        foreach ($value as $v) {
            $sum += payload_char_length($v);
        }
        return $sum;
    }
    return 0;
}

/**
 * Estimate a safe max_tokens/maxOutputTokens budget for one batch, based on
 * actual source content length rather than a flat per-item count.
 *
 * The original formula, min(8192, max(2048, count($items) * 150)), assumed
 * every item is a short UI string. It isn't: AI Provider settings
 * descriptions run 300-500+ characters, and a batch of just 8 such items
 * could total ~2000 source characters — once translated into a language
 * that expands on English (German) or wrapped in escaped JSON, the actual
 * output token need blows past the 2048-token floor this formula assigned,
 * so the model truncates mid-response and the whole batch fails to parse
 * (this is what broke de_DE/zh_CN in production). Summing real content
 * length and applying a generous per-character multiplier (covering
 * translation expansion, JSON-escaping overhead, and denser tokenization in
 * some scripts) avoids needing per-language tuning.
 *
 * Floor 2048 / cap 8192 unchanged from the original formula.
 */
function estimate_max_tokens(array $items): int
{
    $totalChars = 0;
    foreach ($items as $item) {
        $totalChars += payload_char_length($item['payload'] ?? $item);
    }
    return min(8192, max(2048, (int) ceil($totalChars * 3) + 200));
}

/**
 * Split $items into chunks respecting BOTH a max item count ($maxCount, the
 * --batch-size value) AND a max combined source-content budget per chunk
 * ($maxCharsPerChunk) — a small item count can already contain enough long
 * strings (AI Provider settings descriptions) to blow the token budget on
 * its own, so count-only chunking (the original array_chunk()) isn't
 * enough. Greedy bin-packing: keep adding items to the current chunk until
 * either limit would be exceeded, then start a new one. A single item that
 * is already over budget on its own still gets its own solo chunk — it
 * can't be split across two API calls.
 */
function chunk_items_by_budget(array $items, int $maxCount, int $maxCharsPerChunk): array
{
    $chunks       = [];
    $current      = [];
    $currentChars = 0;

    foreach ($items as $item) {
        $itemChars = payload_char_length($item['payload'] ?? $item);

        if ($current !== []
            && (count($current) >= $maxCount || $currentChars + $itemChars > $maxCharsPerChunk)
        ) {
            $chunks[]     = $current;
            $current      = [];
            $currentChars = 0;
        }

        $current[]     = $item;
        $currentChars += $itemChars;
    }

    if ($current !== []) {
        $chunks[] = $current;
    }

    return $chunks;
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
$poFiles = glob($langDir . '/agnosis-theme-*.po');
sort($poFiles);
if ($poFiles === [] || $poFiles === false) {
    fwrite(STDERR, "No languages/agnosis-theme-*.po files found.\n");
    exit(1);
}

$work = []; // locale => ['path'=>, 'lines'=>, 'items'=>[ [id, prompt-payload, apply-info] ]]

foreach ($poFiles as $poPath) {
    $locale = preg_replace('/^agnosis-theme-|\.po$/', '', basename($poPath));
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
        $isPhpFormat = in_array('php-format', $entry['flags'], true);

        if ($entry['type'] === 'single') {
            if ($entry['multiline_warning']) {
                continue; // never touched — same policy as fill-loco-gaps.php
            }

            // I-2 parity (fifteenth audit, ported from the plugin's own
            // translate-missing.php): an already-translated php-format
            // entry can still be broken — a translator dropped a required
            // %d/%s — and that's just as much "needs this tool's
            // attention" as an empty msgstr. Re-include it, but carry the
            // existing (broken) translation + exactly which placeholder(s)
            // are missing, so the model FIXES it in place instead of
            // discarding a translation that's otherwise fine.
            $expected = $isPhpFormat ? extract_placeholders($entry['msgid']) : [];
            sort($expected);
            $missing = [];
            if ($entry['empty']) {
                // normal from-scratch case, nothing further to compute
            } elseif ($expected !== [] && ($missing = missing_placeholders($expected, $entry['text'])) !== []) {
                // format-mismatch fix case, handled below
            } else {
                continue; // already translated and format-clean (or not a format string)
            }

            $id = 's' . count($items);
            $payload = [
                'type'    => 'single',
                'source'  => $entry['msgid'],
                'comment' => $entry['comment'],
            ];
            if (!$entry['empty']) {
                $payload['existing_translation']  = $entry['text'];
                $payload['missing_placeholders']  = $missing;
            }
            $items[] = [
                'id'                    => $id,
                'payload'               => array_filter($payload),
                'apply'                 => ['line' => $entry['line']],
                'expected_placeholders' => $expected,
            ];
        } else {
            $expected = $isPhpFormat ? extract_placeholders($entry['msgid']) : [];
            sort($expected);

            foreach ($entry['slots'] as $idx => $slot) {
                if ($slot['multiline_warning']) {
                    continue; // this slot specifically is wrapped — never
                              // touched, same policy as fill-loco-gaps.php —
                              // but no longer blocks any OTHER slot of the
                              // same entry (see parse_entries() above).
                }

                $missing = [];
                if ($slot['empty']) {
                    // normal from-scratch case
                } elseif ($expected !== [] && ($missing = missing_placeholders($expected, $slot['text'])) !== []) {
                    // format-mismatch fix case
                } else {
                    continue;
                }

                $siblings = [];
                foreach ($entry['slots'] as $sIdx => $sSlot) {
                    if ($sIdx !== $idx && !$sSlot['empty']) {
                        $siblings[(string) $sIdx] = $sSlot['text'];
                    }
                }
                $id = 'p' . count($items);
                $payload = [
                    'type'              => 'plural',
                    'source_singular'   => $entry['msgid'],
                    'source_plural'     => $entry['msgid_plural'],
                    'nplurals'          => $nplurals,
                    'plural_formula'    => $formula,
                    'target_slot_index' => $idx,
                    'reference_translations_other_slots' => $siblings ?: null,
                    'comment'           => $entry['comment'],
                ];
                if (!$slot['empty']) {
                    $payload['existing_translation']  = $slot['text'];
                    $payload['missing_placeholders']  = $missing;
                }
                $items[] = [
                    'id'                    => $id,
                    'payload'               => array_filter($payload),
                    'apply'                 => ['line' => $slot['line'], 'slot' => $idx],
                    'expected_placeholders' => $expected,
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
    echo "Nothing to translate — every locale is fully filled and format-clean.\n";
    exit(0);
}
$totalFixes = array_sum(array_map(
    fn($w) => count(array_filter($w['items'], fn($it) => isset($it['payload']['existing_translation']))),
    $work
));
$fixNote = $totalFixes > 0 ? " ({$totalFixes} of those are existing translations missing a required placeholder — not truly empty)" : "";
fwrite(STDERR, "{$totalItems} missing/broken string(s){$fixNote} across " . count($work) . " locale(s), via {$providerConfig['label']} ({$model})" . ($dryRun ? " (dry run)" : "") . ".\n");

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

    $chunks           = chunk_items_by_budget($items, $batchSize, MAX_CHARS_PER_CHUNK);
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

            // Defense in depth: a real translated UI string will essentially
            // never itself be valid, structured JSON — see the plugin's own
            // translate-missing.php for the production incident that added
            // this check (a provider's model echoed a single-item batch's
            // own request payload back as its "translation").
            if (is_array(json_decode($translated, true))) {
                fwrite(STDERR, "  [{$locale}] response for {$id} looks like echoed JSON, not a translation — skipped: " . mb_substr($translated, 0, 120) . "\n");
                continue;
            }

            // Second defense in depth — see the plugin's own
            // translate-missing.php for the production incident (multiple
            // plural forms joined into one answer, e.g. "%d form1|%d
            // form2") that added this check: reject anything with more
            // placeholder occurrences than the source itself has.
            $expectedPlaceholders = $it['expected_placeholders'] ?? [];
            if ($expectedPlaceholders !== [] && count(extract_placeholders($translated)) > count($expectedPlaceholders)) {
                fwrite(STDERR, "  [{$locale}] response for {$id} has more placeholders than expected — looks like multiple forms joined into one, not a single translation — skipped: " . mb_substr($translated, 0, 120) . "\n");
                continue;
            }

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
