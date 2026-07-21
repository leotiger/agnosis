#!/usr/bin/env php
<?php
/**
 * fill-loco-gaps.php — Detect, and (given a data file) fill, partially-
 * translated plural entries in languages/agnosis-*.po.
 *
 * The gap this closes (AUDIT-0.9.44.md §5d): for a locale whose
 * Plural-Forms declares more than two categories (nplurals > 2 — e.g.
 * Arabic's 6, Russian's 3), a msgid_plural entry can end up with SOME
 * msgstr[N] slots translated and others left as "". Verified this session
 * (real msgmerge binary, byte-identical re-merge) that make-pot.sh/
 * msgmerge is not the cause — every slot the header promises is created
 * correctly. The cause is Loco Translate's editor UI, which doesn't
 * prominently surface plural-form fields past the first couple, so a
 * translation pass done entirely through it silently stops short.
 *
 * This is a narrow, ON-DEMAND tool. It is NOT wired into make-pot.sh or
 * compile-pos.sh and is NOT expected to run on every patch version —
 * filling these gaps properly is real translation work, not something to
 * gate a routine bump on. Run it when doing a deliberate i18n clean-up
 * pass.
 *
 * Detection only touches msgid_plural entries where the locale's own
 * nplurals > 2 AND at least one slot has content AND at least one other
 * slot in range is empty — i.e. genuinely "started but not finished"
 * entries. A fully-untranslated entry (every slot empty) is normal,
 * self-describing Loco backlog, not this bug pattern, and is correctly
 * left alone.
 *
 * This script never guesses translations itself. It only:
 *   (a) scan mode — reports gaps as JSON (locale, msgid/msgid_plural,
 *       which slot indices are empty, the plural rule, and the text
 *       already present in filled slots for grammatical context), so a
 *       human — or an AI assistant working the request live, as happened
 *       for the initial ar/ru_RU pass — can write the missing strings;
 *   (b) apply mode — given a translations JSON data file (same shape a
 *       human/AI produces from the scan report), writes each provided
 *       string into its slot, but ONLY into a slot that is currently
 *       empty. A slot that already has content is left untouched and
 *       reported as skipped, even if the data file supplies a value for
 *       it — this script never overwrites an existing translation.
 *
 * This is a surgical, single-line replace (same spirit as
 * dev/bin/clear-fuzzy.awk), not a full PO reparse/rewrite: it only
 * touches `msgstr[N] ""` lines it identifies as empty slots belonging to
 * a matching entry, leaving every other byte of the file untouched.
 * Every empty msgstr[N] line in this project's .po files today is a
 * single line (no wrapped/multi-line empty slots) — this script assumes
 * that and will refuse (not guess) if it ever finds a multi-line one.
 *
 * Usage (from dev/):
 *   php bin/fill-loco-gaps.php                    # scan, print JSON report to stdout
 *   php bin/fill-loco-gaps.php --apply=FILE.json   # apply translations from FILE.json
 *
 * Data file format for --apply (array, one item per filled slot):
 *   [
 *     {
 *       "locale": "ar",
 *       "msgid": "%d submission",
 *       "msgid_plural": "%d submissions",
 *       "slot": 0,
 *       "text": "لا مرسلات"
 *     },
 *     ...
 *   ]
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

$devDir   = dirname(__DIR__);
$langDir  = $devDir . '/../languages';
$applyArg = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--apply=')) {
        $applyArg = substr($arg, strlen('--apply='));
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php bin/fill-loco-gaps.php [--apply=FILE.json]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
}

/**
 * Parse a .po file's own Plural-Forms header to get nplurals and the
 * raw formula (formula is carried through for report context only —
 * never evaluated).
 */
function parse_nplurals(string $poText): ?int
{
    if (preg_match('/Plural-Forms:\s*nplurals\s*=\s*(\d+)\s*;/', $poText, $m)) {
        return (int) $m[1];
    }
    return null;
}

/**
 * Unescape a single quoted PO string literal's *inner* text (the part
 * between the quotes) back to raw text, for reporting purposes.
 */
function po_unescape(string $inner): string
{
    return str_replace(
        ['\\n', '\\t', '\\"', '\\\\'],
        ["\n", "\t", '"', '\\'],
        $inner
    );
}

/**
 * Escape raw text into a PO string literal's inner text for writing.
 */
function po_escape(string $raw): string
{
    return str_replace(
        ['\\', '"', "\n", "\t"],
        ['\\\\', '\\"', '\\n', '\\t'],
        $raw
    );
}

/**
 * Walk a .po file's lines and yield plural entries as:
 *   ['msgid' => string, 'msgid_plural' => string,
 *    'slots' => [ index => ['line' => int, 'raw' => string, 'text' => string, 'empty' => bool] ]]
 * Only single-line `msgid "..."` / `msgid_plural "..."` and single-line
 * `msgstr[N] "..."` are parsed with full fidelity; multi-line
 * (wrapped) msgid/msgid_plural text is still concatenated correctly for
 * matching purposes, but multi-line msgstr[N] entries are flagged and
 * skipped (never guessed at) since none exist in this project today.
 */
function parse_plural_entries(array $lines): array
{
    $entries = [];
    $i = 0;
    $n = count($lines);

    while ($i < $n) {
        $line = $lines[$i];

        if (preg_match('/^msgid\s+"(.*)"\s*$/', $line, $m) && $m[1] !== '' || preg_match('/^msgid\s+""\s*$/', $line)) {
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

            // Must be immediately followed by msgid_plural to be a plural entry.
            if ($j < $n && preg_match('/^msgid_plural\s+"(.*)"\s*$/', $lines[$j], $pm)) {
                $pluralParts = [$pm[1]];
                $k = $j + 1;
                while ($k < $n && preg_match('/^"(.*)"\s*$/', $lines[$k], $cm)) {
                    $pluralParts[] = $cm[1];
                    $k++;
                }

                $msgid       = po_unescape(implode('', $msgidParts));
                $msgidPlural = po_unescape(implode('', $pluralParts));

                // Collect msgstr[N] slots starting at $k.
                $slots = [];
                $m2 = $k;
                $multilineWarning = false;
                while ($m2 < $n && preg_match('/^msgstr\[(\d+)\]\s+"(.*)"\s*$/', $lines[$m2], $sm)) {
                    $idx = (int) $sm[1];
                    $rawInner = $sm[2];
                    // Detect (and refuse to touch) a wrapped continuation of this slot.
                    if ($m2 + 1 < $n && preg_match('/^"(.*)"\s*$/', $lines[$m2 + 1]) && !preg_match('/^(msgstr\[|msgid|msgctxt|#)/', $lines[$m2 + 1])) {
                        $multilineWarning = true;
                    }
                    $slots[$idx] = [
                        'line'  => $m2,
                        'raw'   => $lines[$m2],
                        'text'  => po_unescape($rawInner),
                        'empty' => $rawInner === '',
                    ];
                    $m2++;
                }

                $entries[] = [
                    'msgid'        => $msgid,
                    'msgid_plural' => $msgidPlural,
                    'slots'        => $slots,
                    'multiline_warning' => $multilineWarning,
                    'start_line'   => $msgidStart,
                ];

                $i = $m2;
                continue;
            }
        }

        $i++;
    }

    return $entries;
}

$poFiles = glob($langDir . '/agnosis-*.po');
sort($poFiles);

if ($poFiles === [] || $poFiles === false) {
    fwrite(STDERR, "No languages/agnosis-*.po files found.\n");
    exit(1);
}

// ---------------------------------------------------------------------
// APPLY MODE
// ---------------------------------------------------------------------
if ($applyArg !== null) {
    if (!is_file($applyArg)) {
        fwrite(STDERR, "Data file not found: {$applyArg}\n");
        exit(1);
    }
    $data = json_decode((string) file_get_contents($applyArg), true);
    if (!is_array($data)) {
        fwrite(STDERR, "Data file is not valid JSON array: {$applyArg}\n");
        exit(1);
    }

    // Group fills by locale.
    $byLocale = [];
    foreach ($data as $fill) {
        foreach (['locale', 'msgid', 'msgid_plural', 'slot', 'text'] as $required) {
            if (!array_key_exists($required, $fill)) {
                fwrite(STDERR, "Data file entry missing '{$required}': " . json_encode($fill) . "\n");
                exit(1);
            }
        }
        $byLocale[$fill['locale']][] = $fill;
    }

    $totalApplied = 0;
    $totalSkipped = 0;

    foreach ($poFiles as $poPath) {
        $locale = preg_replace('/^agnosis-|\.po$/', '', basename($poPath));
        if (!isset($byLocale[$locale])) {
            continue;
        }

        $poText = (string) file_get_contents($poPath);
        $lines  = explode("\n", $poText);
        $entries = parse_plural_entries($lines);

        foreach ($byLocale[$locale] as $fill) {
            $match = null;
            foreach ($entries as $entry) {
                if ($entry['msgid'] === $fill['msgid'] && $entry['msgid_plural'] === $fill['msgid_plural']) {
                    $match = $entry;
                    break;
                }
            }
            if ($match === null) {
                fwrite(STDERR, "[{$locale}] no matching entry for msgid=" . json_encode($fill['msgid']) . " — skipped.\n");
                $totalSkipped++;
                continue;
            }
            if ($match['multiline_warning']) {
                fwrite(STDERR, "[{$locale}] entry has a wrapped/multi-line msgstr slot — refusing to touch (msgid=" . json_encode($fill['msgid']) . ").\n");
                $totalSkipped++;
                continue;
            }
            $slot = (int) $fill['slot'];
            if (!isset($match['slots'][$slot])) {
                fwrite(STDERR, "[{$locale}] entry has no slot {$slot} (msgid=" . json_encode($fill['msgid']) . ") — skipped.\n");
                $totalSkipped++;
                continue;
            }
            if (!$match['slots'][$slot]['empty']) {
                fwrite(STDERR, "[{$locale}] slot {$slot} already has content — NOT overwritten (msgid=" . json_encode($fill['msgid']) . ").\n");
                $totalSkipped++;
                continue;
            }

            $lineNo = $match['slots'][$slot]['line'];
            $lines[$lineNo] = "msgstr[{$slot}] \"" . po_escape($fill['text']) . '"';
            $totalApplied++;
        }

        file_put_contents($poPath, implode("\n", $lines));
        echo "✓ wrote " . basename($poPath) . "\n";
    }

    echo "Applied {$totalApplied} slot(s), skipped {$totalSkipped}.\n";
    exit($totalSkipped > 0 && $totalApplied === 0 ? 1 : 0);
}

// ---------------------------------------------------------------------
// SCAN MODE (default)
// ---------------------------------------------------------------------
$report = [];

foreach ($poFiles as $poPath) {
    $locale = preg_replace('/^agnosis-|\.po$/', '', basename($poPath));
    $poText = (string) file_get_contents($poPath);
    $nplurals = parse_nplurals($poText);

    if ($nplurals === null || $nplurals <= 2) {
        continue; // Only locales with >2 categories can exhibit this gap.
    }

    $lines   = explode("\n", $poText);
    $entries = parse_plural_entries($lines);

    foreach ($entries as $entry) {
        $filled = [];
        $empty  = [];
        foreach ($entry['slots'] as $idx => $slot) {
            if ($slot['empty']) {
                $empty[] = $idx;
            } else {
                $filled[$idx] = $slot['text'];
            }
        }
        if ($filled === [] || $empty === []) {
            continue; // fully untranslated (normal backlog) or fully translated
        }
        $report[] = [
            'locale'          => $locale,
            'nplurals'        => $nplurals,
            'msgid'           => $entry['msgid'],
            'msgid_plural'    => $entry['msgid_plural'],
            'filled_slots'    => $filled,
            'empty_slots'     => $empty,
            'multiline_warning' => $entry['multiline_warning'],
        ];
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
fwrite(STDERR, "\n" . count($report) . " partially-translated plural entr" . (count($report) === 1 ? 'y' : 'ies') . " found across " . count($poFiles) . " locale file(s).\n");
