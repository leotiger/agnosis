# clear-fuzzy.awk — blank every fuzzy-flagged entry's msgstr and drop the
# 'fuzzy' token from its flags line (keeping any other flags, e.g.
# php-format; dropping the whole '#,' line if fuzzy was the only one).
#
# msgmerge marks a merged entry fuzzy when it guesses a translation from a
# similar old string rather than carrying over a confirmed match — the guess
# is often wrong (audit AUDIT-0.9.38.md §6b: 90 wrong-match fuzzy entries).
# Policy: never let a fuzzy (possibly-wrong) string sit in a .po looking
# translated. Clear it back to a clean, honestly-untranslated entry so a
# human retranslates it fresh in Loco Translate, instead of leaving stale
# guessed text that only *looks* reviewed.
#
# This is a line-surgical rewrite, not a full reformat via a library like
# polib: only the lines belonging to a fuzzy entry are touched, so running
# this does not introduce reformatting noise into unrelated lines.
#
# No '#|' previous-msgid comments exist anywhere in this project's .po files
# today (msgmerge here isn't invoked with --previous), but they're skipped
# defensively in case a future regeneration introduces them.
#
# Usage: awk -f clear-fuzzy.awk file.po > file.po.new && mv file.po.new file.po
#        Prints the number of entries cleared to stderr.
BEGIN { state = "normal"; cleared = 0 }

function is_fuzzy_flag_line(   line, n, i, flags) {
    if ($0 !~ /^#,/) { return 0 }
    line = $0
    sub(/^#,[ \t]*/, "", line)
    n = split(line, flags, ",")
    remaining = ""
    has_fuzzy = 0
    for (i = 1; i <= n; i++) {
        gsub(/^[ \t]+|[ \t]+$/, "", flags[i])
        if (flags[i] == "fuzzy") { has_fuzzy = 1 }
        else if (flags[i] != "") {
            remaining = (remaining == "") ? flags[i] : remaining ", " flags[i]
        }
    }
    return has_fuzzy
}

{
    if (state == "in_msgstr") {
        if ($0 ~ /^"/) { next }              # blanked msgstr's old continuation line
        state = "normal"                      # fall through to normal handling below
    }

    if (state == "normal") {
        if (is_fuzzy_flag_line()) {
            if (remaining != "") { print "#, " remaining }
            cleared++
            state = "fuzzy_header"
            next
        }
        print
        next
    }

    if (state == "fuzzy_header") {
        if ($0 ~ /^#\|/) { next }             # defensive: previous-* comment
        if ($0 ~ /^msgstr\[/) {
            tag = $0
            sub(/\].*/, "]", tag)
            print tag " \"\""
            state = "in_msgstr"
            next
        }
        if ($0 ~ /^msgstr[ \t]/ || $0 == "msgstr") {
            print "msgstr \"\""
            state = "in_msgstr"
            next
        }
        print                                  # msgctxt / msgid / msgid_plural lines
        next
    }
}

END { printf "%d", cleared > "/dev/stderr" }
