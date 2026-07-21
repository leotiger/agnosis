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
 * one pass — and fills them by calling the Anthropic API with a prompt
 * that knows what Agnosis actually is, so translations land in the
 * right register instead of a generic machine-translation guess.
 *
 * This is a narrow, ON-DEMAND tool (composer translate-missing), same
 * spirit as fill-loco-gaps.php — not wired into make-pot.sh/compile-pos.sh,
 * not run automatically on every version bump.
 *
 * Requires: an ANTHROPIC_API_KEY environment variable (never read from
 * or written to any file in this repo). Get one at
 * https://console.anthropic.com/ — this is a separate key from anything
 * Agnosis itself stores at runtime (Core\Secrets); this script only ever
 * runs on a developer's own machine, never inside WordPress.
 *
 * Writes AI translations DIRECTLY into the .po files (by design — see
 * AUDIT-0.9.44.md §5d annotation for the trade-off discussion). Every
 * write is also appended to dev/translate-missing.log for a fast
 * post-hoc skim; nothing here claims professional/native-speaker
 * accuracy — same caveat as every AI-drafted string this project has
 * shipped so far.
 *
 * Usage (from dev/):
 *   php bin/translate-missing.php                    # all locales, all gaps
 *   php bin/translate-missing.php --dry-run           # call the API, print results, write nothing
 *   php bin/translate-missing.php --locale=ar         # scope to one locale
 *   php bin/translate-missing.php --limit=10          # cap items translated (testing/cost control)
 *   php bin/translate-missing.php --batch-size=40      # items per API call (default 40)
 *   php bin/translate-missing.php --model=claude-haiku-4-5-20251001  # override model
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

$devDir  = dirname(__DIR__);
$langDir = $devDir . '/../languages';
$logFile = $devDir . '/translate-missing.log';

$onlyLocale = null;
$dryRun     = false;
$limit      = null;
$batchSize  = 40;
$model      = 'claude-haiku-4-5-20251001';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($arg, '--locale=')) {
        $onlyLocale = substr($arg, strlen('--locale='));
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, strlen('--limit='));
    } elseif (str_starts_with($arg, '--batch-size=')) {
        $batchSize = max(1, (int) substr($arg, strlen('--batch-size=')));
    } elseif (str_starts_with($arg, '--model=')) {
        $model = substr($arg, strlen('--model='));
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php bin/translate-missing.php [--dry-run] [--locale=xx] [--limit=N] [--batch-size=N] [--model=NAME]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "PHP's curl extension is required but not available.\n");
    exit(1);
}

$apiKey = getenv('ANTHROPIC_API_KEY');
if ($apiKey === false || $apiKey === '') {
    fwrite(STDERR, "ANTHROPIC_API_KEY is not set. Export it before running:\n  export ANTHROPIC_API_KEY=sk-ant-...\n");
    exit(1);
}

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
 * One call to the Anthropic Messages API. Returns [assocArrayOfIdToText, usage].
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

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT    => 120,
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
            return [[], null];
        }

        $decoded = json_decode($response, true);
        $text = $decoded['content'][0]['text'] ?? '';
        $usage = $decoded['usage'] ?? null;

        // Strip markdown fences defensively even though the prompt forbids them.
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            fwrite(STDERR, "  Could not parse model response as JSON: " . substr($text, 0, 300) . "\n");
            return [[], $usage];
        }
        return [$parsed, $usage];
    }
    return [[], null];
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
fwrite(STDERR, "{$totalItems} missing string(s) across " . count($work) . " locale(s)" . ($dryRun ? " (dry run)" : "") . ".\n");

$logLines = [];
$totalIn  = 0;
$totalOut = 0;
$totalWritten = 0;
$processed = 0;

foreach ($work as $locale => $w) {
    $langName = LOCALE_NAMES[$locale];
    $items    = $w['items'];
    $lines    = $w['lines'];

    $chunks = array_chunk($items, $batchSize);
    foreach ($chunks as $chunk) {
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

        fwrite(STDERR, "[{$locale}] translating " . count($chunk) . " item(s)...\n");
        [$result, $usage] = call_anthropic($apiKey, $model, $system, $payloadItems);
        if ($usage !== null) {
            $totalIn  += $usage['input_tokens']  ?? 0;
            $totalOut += $usage['output_tokens'] ?? 0;
        }

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
            } else {
                echo "  {$newLine}\n";
            }
        }
        $processed += count($chunk);
    }

    if (!$dryRun) {
        file_put_contents($w['path'], implode("\n", $lines));
        echo "✓ wrote " . basename($w['path']) . "\n";
    }
}

if ($logLines !== []) {
    file_put_contents($logFile, implode("\n", $logLines) . "\n", FILE_APPEND);
}

$costNote = ($totalIn + $totalOut > 0)
    ? "input_tokens={$totalIn} output_tokens={$totalOut} (see Anthropic's current per-model pricing for the cost this represents)"
    : "no usage data returned";
echo ($dryRun ? "Dry run complete" : "Applied {$totalWritten} translation(s)") . ". {$costNote}.\n";
echo "Log: {$logFile}\n";
