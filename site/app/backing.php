<?php
// GenZHype | IS EACH SENTENCE BACKED BY A SOURCE? (owner 2026-09-27). The code check behind the fact
// check: in a test the editor passed 2 of 4 pages whose fact check had found invented sentences, and the
// fact check itself missed a proven one on a second run ("Ubisoft thanked fans for their patience").
// A sentence is UNBACKED when a number or a name in it appears nowhere in the page's sources, or when
// fewer than half of its content words do. Dates are not counted here (the date rules check them), nor
// are the outlets the page cites. Deterministic: the same page gives the same answer every time.

require_once __DIR__ . '/db.php';

const BACK_MIN_SUPPORT = 0.5;
const BACK_STOP = ['the','and','for','that','with','this','from','was','were','are','has','have','had','his','her','their','they',
    'them','its','but','not','who','which','what','when','where','while','after','before','about','into','over','also','been','being',
    'will','would','could','should','can','may','might','than','then','there','these','those','such','said','says','say','according',
    'reportedly','allegedly','alleged','claims','claimed','claim','one','two','more','most','other','some','any','all','both','each','just',
    'only','very','our','out','off','per','via','yet','did','does','doing','because','since','until','still','again','further','however',
    'though','although','amid','among','within','without','whether','around','during','through','against','between','under','new','first',
    'last','later','earlier','now','today','yes','how','why','many','much','own','way','well','here','reports','reported','report','stated',
    'states','state','announced','announces','noted','notes','added','adds','told','tells','explained','described'];
const BACK_MONTHS = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\.?';

/** Dates out: "September 15, 2026", "Sept. 2026", "2026-09-15", a bare year, "As of". */
function back_strip_dates(string $t): string {
    $t = preg_replace('/\bas of\b/i', ' ', $t);
    $t = preg_replace('/\b' . BACK_MONTHS . '\s+\d{1,2}(?!\d)(?:st|nd|rd|th)?(?:,?\s+\d{4})?/i', ' ', $t);
    $t = preg_replace('/\b\d{1,2}(?:st|nd|rd|th)?\s+(?:of\s+)?' . BACK_MONTHS . '(?:,?\s+\d{4})?/i', ' ', $t);
    $t = preg_replace('/\b' . BACK_MONTHS . '\s+\d{4}\b|\b\d{4}-\d{2}-\d{2}\b|\b(?:19|20)\d{2}\b/i', ' ', $t);
    return preg_replace('/\b' . BACK_MONTHS . '\b/i', ' ', $t);
}

/** Content words (crudely stemmed) and numbers of a text. */
function back_words(string $t): array {
    $t = mb_strtolower(html_entity_decode($t, ENT_QUOTES));
    preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-\.]*[\p{L}\p{N}]|\p{N}/u', $t, $m);
    $out = [];
    foreach ($m[0] as $w) {
        $w = trim(preg_replace("/['’]s$/u", '', $w), '.-');
        if (preg_match('/^\d/', $w)) { $out[] = str_replace(',', '', $w); continue; }
        if (mb_strlen($w) < 3 || in_array($w, BACK_STOP, true)) continue;
        $out[] = preg_replace('/(?:ing|ed|es|s)$/u', '', $w) ?: $w;
    }
    return $out;
}

/** Numbers and names in a sentence: numbers, and capitalized words that do not start the sentence. */
function back_facts(string $sentence): array {
    $facts = [];
    preg_match_all('/\b\d[\d,\.]*\b/u', $sentence, $n);
    foreach ($n[0] as $x) $facts[] = str_replace(',', '', rtrim($x, '.'));
    preg_match_all('/(?<=[\s\(“"‘\'])(\p{Lu}[\p{L}\p{N}\'’\-]{2,})/u', ' ' . preg_replace('/^\S+/u', '', $sentence), $c);
    foreach ($c[1] as $x) {
        $x = preg_replace("/['’]s?$/u", '', $x);   // Deme's, Reviews'
        if (!in_array(mb_strtolower($x), BACK_STOP, true)) $facts[] = mb_strtolower($x);
    }
    return array_values(array_unique($facts));
}

/** A text split into sentences (short fragments are left out). */
function back_sentences(string $t): array {
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    if ($t === '') return [];
    return array_values(array_filter(preg_split('/(?<=[.!?])\s+(?=[\p{Lu}"“‘\'(])/u', $t), fn($s) => mb_strlen(trim($s)) >= 25));
}

/** The words a page's sources hold: their text, titles, outlets and hosts (with and without the dot-com). */
function back_corpus(PDO $pdo, int $did): array {
    $st = $pdo->prepare("SELECT DISTINCT s.excerpt, s.title, s.publisher, s.domain, s.url FROM events e JOIN sources s ON s.id=e.source_id WHERE e.drama_id=?");
    $st->execute([$did]);
    $words = []; $raw = '';
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $host = preg_replace('/^www\./', '', (string)($s['domain'] ?: parse_url((string)$s['url'], PHP_URL_HOST)));
        $raw .= ' ' . $s['excerpt'] . ' ' . $s['title'] . ' ' . $s['publisher'] . ' ' . $host . ' ' . preg_replace('/\.[a-z]{2,6}$/', '', $host);
    }
    foreach (back_words($raw) as $w) $words[$w] = 1;
    $low = mb_strtolower($raw);
    return ['words' => $words, 'low' => $low];
}

/** One sentence against the corpus: [unbacked?, support 0..1, what is missing, a name or number is missing?]. */
function back_check(string $sentence, array $corpus): array {
    $plain = back_strip_dates($sentence);
    $missFacts = [];
    foreach (back_facts($plain) as $f) {
        if (preg_match('/^\d/', $f)) { if (!preg_match('/(?<![\d])' . preg_quote($f, '/') . '(?![\d])/', str_replace(',', '', $corpus['low']))) $missFacts[] = $f; }
        elseif (mb_strpos($corpus['low'], $f) === false && !in_array($f, ['genzhype'], true)) $missFacts[] = $f;
    }
    $w = array_values(array_unique(back_words($plain)));
    $miss = array_values(array_filter($w, fn($x) => !isset($corpus['words'][$x])));
    $support = $w ? 1 - count($miss) / count($w) : 1.0;
    return [$missFacts !== [] || $support < BACK_MIN_SUPPORT, round($support, 2), $missFacts ?: array_slice($miss, 0, 6), $missFacts !== []];
}

/**
 * Every sentence a reader sees that its sources do not back: [['section','key','sentence','support','missing'], ...].
 * Sections: summary, why, next (key = item index), background (key = paragraph index), faq (key = faq id), event (key = event id).
 */
function back_unbacked(PDO $pdo, int $pageId): array {
    $st = $pdo->prepare("SELECT p.summary, d.id did, d.background, d.why_matters, d.whats_next FROM pages p JOIN dramas d ON d.page_id=p.id WHERE p.id=?");
    $st->execute([$pageId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return [];
    $corpus = back_corpus($pdo, (int)$p['did']);
    if (!$corpus['words']) return [];   // no source text stored: the source-count rules hold such a page
    $parts = [['summary', 0, (string)$p['summary']], ['why', 0, (string)$p['why_matters']]];
    foreach ((array)json_decode((string)$p['whats_next'], true) as $i => $nx) $parts[] = ['next', $i, (string)($nx['text'] ?? '')];
    foreach ((array)json_decode((string)$p['background'], true) as $i => $bg) if (is_string($bg)) $parts[] = ['background', $i, $bg];
    foreach ($pdo->query("SELECT id, answer FROM faqs WHERE drama_id=" . (int)$p['did']) as $f) $parts[] = ['faq', (int)$f['id'], (string)$f['answer']];
    foreach ($pdo->query("SELECT id, description FROM events WHERE drama_id=" . (int)$p['did'] . " AND video_only=0") as $e) $parts[] = ['event', (int)$e['id'], (string)$e['description']];
    $out = [];
    foreach ($parts as [$section, $key, $text])
        foreach (back_sentences($text) as $s) {
            [$bad, $support, $missing, $fact] = back_check($s, $corpus);
            if ($section === 'why') $bad = $fact;   // our take is analysis: only a name or number the sources lack counts (owner 2026-09-27)
            if ($bad) $out[] = ['section' => $section, 'key' => $key, 'sentence' => $s, 'support' => $support, 'missing' => $missing, 'fact' => $fact];
        }
    return $out;
}

/**
 * Are these words really in the source text? (owner 2026-09-27: the writer must write only from the source quotes it is
 * given). Case, quotes and spacing aside, the quote must appear as it is; a quote of 8+ words passes when any 8 words in
 * a row of it appear (a writer may drop an ellipsis). Under 20 characters is no quote.
 */
function back_quote_found(string $quote, string $sourceText): bool {
    $n = fn(string $t) => trim(preg_replace('/\s+/u', ' ', str_replace(['’', '‘', '“', '”', '–', '—', "\u{00a0}", '…'], ["'", "'", '"', '"', '-', '-', ' ', ' '], mb_strtolower(html_entity_decode($t, ENT_QUOTES)))));
    $q = trim($n($quote), " \"'.,;:");
    if (mb_strlen($q) < 20) return false;
    $src = $n($sourceText);
    if (mb_strpos($src, $q) !== false) return true;
    $w = preg_split('/\s+/u', $q);
    for ($i = 0; $i + 8 <= count($w); $i++) if (mb_strpos($src, implode(' ', array_slice($w, $i, 8))) !== false) return true;
    return false;
}
