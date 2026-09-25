<?php
// GenZHype | FACT GUARD. Every AI rewrite of page text is checked against the
// facts it was given: a number, or a capitalised name that does not start a
// sentence, must appear in those facts. Used by the status refresh and the
// story-context fields (why it matters / what happens next).

/** Words and numbers in $text that the facts ($corpus, plus source web addresses in $hosts) do not contain. */
function fact_drift(string $text, string $corpus, array $hosts = []): array {
    static $allow = ['january','february','march','april','may','june','july','august','september','october',
        'november','december','monday','tuesday','wednesday','thursday','friday','saturday','sunday','genzhype',
        'faq','youtube','twitch','tiktok','twitter','instagram','reddit','kick','according','reportedly','allegedly'];
    $lc = mb_strtolower($corpus);
    $bad = [];
    // numbers match whole (a "1" no longer passes because "2026" is in the facts), and an
    // amount word must sit next to the same number in the facts: "$1 billion" passed when
    // the sources never said it (draft of page 1229, 2026-09-24)
    static $mags = ['million' => 'million|mn|m\b', 'm' => 'million|mn|m\b', 'billion' => 'billion|bn|b\b', 'bn' => 'billion|bn|b\b',
        'thousand' => 'thousand|k\b', 'k' => 'thousand|k\b', 'trillion' => 'trillion|tn\b', '%' => '%|percent', 'percent' => '%|percent'];
    $lcn = preg_replace('/(?<=\d),(?=\d{3}\b)/', '', $lc);
    preg_match_all('/\d[\d,]*(?:\.\d+)?(?:\s*(%|percent\b|million\b|billion\b|thousand\b|trillion\b|bn\b|k\b|m\b))?/iu', $text, $m, PREG_SET_ORDER);
    foreach ($m as $hit) {
        $num = rtrim(str_replace(',', '', preg_replace('/\s*(%|percent|million|billion|thousand|trillion|bn|k|m)$/i', '', $hit[0])), '.');
        if ($num === '') continue;
        $mag = strtolower($hit[1] ?? '');
        $re = '/(?<![\d.])' . preg_quote($num, '/') . '(?![\d])' . ($mag !== '' ? '\s*(?:' . $mags[$mag] . ')' : '') . '/u';
        if (!preg_match($re, $lcn)) $bad[] = trim($hit[0]);
    }
    // capitalised words must be in the facts; a shared stem counts ("Rumors" when the
    // facts say "rumored" was a false alarm on 1327). Sentence-initial words are checked
    // too ("Microsoft is expected to..." slipped through when they were skipped), except
    // ordinary sentence openers.
    static $openers = ['the','a','an','this','that','these','those','it','its','in','on','at','as','after','before',
        'while','with','without','for','from','by','but','and','or','if','when','since','because','both','each','no',
        'not','now','then','there','here','some','many','most','what','who','why','how','whether','meanwhile',
        'however','also','still','yet','so','we','they','he','she','his','her','their','our','you','your','one',
        'two','three','fans','viewers','players','critics','neither','either','until','once','during','despite',
        // analysis prose opens sentences with these too ("Independent verification remains pending", verdict.php)
        'only','none','nothing','independent','independently','several','multiple','all','every','overall','instead',
        'although','though','given','further','moreover','thus','therefore','such','other','another','few','first',
        'second','third','later','earlier','today','currently','recently','publicly','officially','no one','nobody',
        'consequently','accordingly','additionally','notably','ultimately','hence','similarly','likewise','nevertheless'];
    preg_match_all('/\b\p{Lu}[\p{L}\'’-]{2,}|\b\p{Lu}{2}\b/u', $text, $m);
    foreach (array_unique($m[0]) as $w) {
        $wl = mb_strtolower(preg_replace("/['’]s$/u", '', rtrim($w, "'’")));   // a possessive is its name: "KBS's" is KBS
        if (in_array($wl, $allow, true) || in_array($wl, $openers, true)) continue;
        if (preg_match('/\b' . preg_quote($wl, '/') . '/u', $lc)) continue;
        $stem = mb_substr($wl, 0, max(4, mb_strlen($wl) - 2));
        if (mb_strlen($wl) >= 5 && preg_match('/\b' . preg_quote($stem, '/') . '/u', $lc)) continue;
        $bare = preg_replace('/[^a-z0-9]/', '', $wl);   // an outlet's name as its web address spells it
        if (strlen($bare) >= 3) { foreach ($hosts as $h) if (str_contains($h, $bare)) continue 2; }
        $bad[] = $w;
    }
    return array_values(array_unique($bad));
}

/** Source web addresses reduced to letters and digits, for fact_drift()'s outlet-name check. */
function fact_hosts(array $urlsOrNames): array {
    $out = [];
    foreach ($urlsOrNames as $u) {
        $u = (string)$u;
        if ($u === '') continue;
        $h = parse_url($u, PHP_URL_HOST) ?: $u;
        $out[] = preg_replace('/[^a-z0-9]/', '', mb_strtolower(preg_replace('/^www\./', '', $h)));
    }
    return array_values(array_unique(array_filter($out)));
}
