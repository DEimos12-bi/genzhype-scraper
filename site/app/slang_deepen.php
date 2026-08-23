<?php
/**
 * GenZHype | SLANG DEPTH REPAIR (2026-08-22)
 * =============================================================================
 * 20 term pages sit under the 380-word body minimum — partly because the origin
 * repair correctly removed unprovable origin claims, and body depth counts
 * meaning + origin + why_trending.
 *
 * THIS DOES NOT PAD. Writing filler to clear our own word count is precisely the
 * thin-content behaviour the threshold exists to prevent, and Google's Feb 2026
 * update was built to catch it. Instead the page is deepened ONLY from material
 * it already holds: its stored sources, which every term page has (108 of 108).
 * If the sources do not support more real substance, the page stays short and
 * stays blocked — that is the honest outcome, not a failure of this function.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';

function slang_deepen_run(PDO $pdo, int $cap = 6, bool $dry = false): array
{
    $jd = fn($v) => (is_array($x = json_decode((string)$v, true)) ? $x : []);
    $rows = $pdo->query(
        "SELECT t.page_id, t.term, t.meaning, t.origin, t.why_trending, t.sources, t.short_def
           FROM terms t JOIN pages p ON p.id = t.page_id
          WHERE p.status IN ('published','draft','review')
            AND t.sources NOT IN ('','[]')")->fetchAll(PDO::FETCH_ASSOC);

    $done = 0; $skipped = 0; $noMaterial = 0; $report = [];
    foreach ($rows as $r) {
        if ($done >= $cap) { break; }
        $meaning = $jd($r['meaning']); $origin = $jd($r['origin']); $why = $jd($r['why_trending']);
        $words = str_word_count(strip_tags(implode(' ', array_map(
            fn($x) => is_string($x) ? $x : '', array_merge($meaning, $origin, $why)))));
        if ($words >= 380) { $skipped++; continue; }

        // only the material this page already cites
        $srcBlock = '';
        foreach ($jd($r['sources']) as $s) {
            if (!is_array($s)) { continue; }
            $ex = trim((string)($s['excerpt'] ?? ''));
            if ($ex === '') { continue; }
            $srcBlock .= '- ' . (string)($s['publisher'] ?? parse_url((string)($s['url'] ?? ''), PHP_URL_HOST))
                       . ' (' . (string)($s['date'] ?? 'undated') . '): '
                       . mb_substr($ex, 0, 700) . "\n";
        }
        if (trim($srcBlock) === '') { $noMaterial++; continue; }

        $need = 380 - $words;
        $sys = "You expand ONE section of a slang encyclopedia entry for the term \"{$r['term']}\".\n"
             . "Write 2-4 sentences (about {$need}-" . ($need + 60) . " words) for the "
             . "'why it is everywhere' section.\n"
             . "ABSOLUTE RULES:\n"
             . "- Use ONLY facts present in the SOURCES below. Invent nothing.\n"
             . "- No origin claims: never say when, where or by whom the term started.\n"
             . "- Attribute anything contestable (\"per the Independent\").\n"
             . "- Do not define the term again; the entry already defines it.\n"
             . "- If the sources contain nothing worth adding, reply exactly: NOTHING\n"
             . 'Reply JSON only: {"add":["sentence one","sentence two"]}';
        $res = ai_chat([['role' => 'system', 'content' => $sys],
                        ['role' => 'user', 'content' => "SOURCES:\n" . $srcBlock]],
                       ['gemini', 'nvidia', 'openrouter'], 0.3, 90);
        $j = ai_json((string)($res['content'] ?? ''));
        $add = array_values(array_filter((array)($j['add'] ?? []), 'is_string'));

        // THE PROMPT IS NOT THE GUARD (learned the hard way on 'doge', which
        // came back with "the meme achieved widespread popularity in late
        // 2013" — a dated origin claim, the exact thing the instructions
        // forbade and the exact thing slang_repair_origins() had just
        // stripped out). A model told not to do something still does it, so
        // the output is CHECKED, not trusted: any sentence carrying a year,
        // a first/origin word, or a coinage verb is dropped.
        $originRx = '/\\b(19|20)\\d{2}\\b'                      // any year
                  . '|\\b(originat|coined|invented|first (appear|used|posted|seen)'
                  . '|dates? back|started (on|in)|began (on|in)|created by'
                  . '|earliest|debuted)\\b/i';
        $clean = [];
        foreach ($add as $line) {
            if (preg_match($originRx, $line)) { continue; }   // origin claim: drop
            $norm = mb_strtolower(preg_replace('/[^a-z0-9]/i', '', $line) ?? '');
            $dupe = false;
            foreach (array_merge($why, $clean) as $have) {
                $h = mb_strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$have) ?? '');
                if ($h !== '' && (str_contains($h, mb_substr($norm, 0, 60))
                                  || str_contains($norm, mb_substr($h, 0, 60)))) { $dupe = true; break; }
            }
            if (!$dupe) { $clean[] = $line; }
        }
        $add = $clean;
        if (!$add) { $noMaterial++; continue; }

        $newWhy = array_merge($why, $add);
        if (!$dry) {
            $pdo->prepare("UPDATE terms SET why_trending=? WHERE page_id=?")
                ->execute([json_encode($newWhy, JSON_UNESCAPED_UNICODE), (int)$r['page_id']]);
        }
        $after = str_word_count(strip_tags(implode(' ', array_map(
            fn($x) => is_string($x) ? $x : '', array_merge($meaning, $origin, $newWhy)))));
        $report[] = "{$r['term']}: {$words} -> {$after} words";
        $done++;
    }
    return ['deepened' => $done, 'already_ok' => $skipped,
            'no_material' => $noMaterial, 'report' => $report];
}
