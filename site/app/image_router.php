<?php
// GenZHype | UNIFIED IMAGE ROUTER. Every page type flows through the same
// two-step pattern: semantic queries -> candidate search -> VISION VERIFY.
// HARD GATE: confidence < 0.70 => null. Bad image > no image, on every type.
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/images.php';   // openverse_*, wm_person_*
require_once __DIR__ . '/vision.php';   // vision_b64, vision_nvidia

/** Derive page_type from existing schema (no duplicate column). */
function page_type_of(array $page): string {
    if (($page['type'] ?? '') === 'drama')   return 'drama';
    if (($page['type'] ?? '') === 'creator') return 'celebrity';
    return ['slang'=>'slang','meme'=>'meme','gaming'=>'trend','music'=>'trend'][$page['lane'] ?? 'slang'] ?? 'slang';
}

/** STEP A: type-aware semantic queries (cheapest text model, 1 call). */
function generate_visual_queries(array $page): array {
    $pt = $page['page_type'];
    $prompts = [
        'slang'     => "Term: {$page['subject']}. Meaning: {$page['definition']}. Return 5 visual scenes that capture the MEANING (not the word), concrete and photographable, max 8 words each.",
        'drama'     => "Drama: {$page['subject']}. People: " . implode(' vs ', $page['people'] ?? []) . ". Return 5 visual search queries to find photos of these people, max 8 words each.",
        'meme'      => "Meme: {$page['subject']}. Return 5 visual descriptions of scenes/objects that evoke this meme's vibe WITHOUT using the meme's copyrighted art, max 8 words each.",
        'trend'     => "Trend: {$page['subject']}. Context: {$page['definition']}. Return 5 visual queries capturing the trend, max 8 words each.",
        'celebrity' => "Person: {$page['subject']}. Return 5 visual queries to find photos of them in context (on stage, at event), max 8 words each.",
    ];
    $res = ai_chat([
        ['role'=>'system','content'=>'Output STRICT JSON only: {"queries":["..",".."]}'],
        ['role'=>'user','content'=>$prompts[$pt]],
    ], ['gemini','openrouter','nvidia'], 0.4);
    $j = isset($res['error']) ? null : ai_json($res['content']);
    return array_slice(array_filter((array)($j['queries'] ?? [])), 0, 5);
}

/** STEP B: gather candidates from the free-licensed pools (type-aware source mix). */
function search_image_candidates(array $queries, array $page): array {
    $cands = [];
    if (in_array($page['page_type'], ['drama','celebrity'])) {
        foreach (array_slice($page['people'] ?? [], 0, 2) as $person) {
            foreach (wm_person_candidates($person, 4) as $c) { $c['q'] = $person; $cands[] = $c; }
        }
    }
    foreach ($queries as $q) {
        // stock indexes match short keyword strings, not poetic phrases:
        // try full -> first-4-words -> first-2-words until something hits
        $plain = trim(preg_replace('/[^a-z0-9 ]/i', ' ', $q));
        $w = preg_split('/\s+/', $plain);
        $forms = array_unique(array_filter([$plain, implode(' ', array_slice($w, 0, 4)), implode(' ', array_slice($w, 0, 2))]));
        foreach ($forms as $form) {
            $hits = openverse_find_all($form, 3);
            if ($hits) { foreach ($hits as $c) { $c['q'] = $form; $cands[] = $c; } break; }
        }
        if (count($cands) >= 12) break;
    }
    // dedupe by url
    $seen = []; $out = [];
    foreach ($cands as $c) { if (!isset($seen[$c['url']])) { $seen[$c['url']] = 1; $out[] = $c; } }
    return array_slice($out, 0, 8);
}

/** STEP C: VISION VERIFY (hard gate). Gemini batch first, NVIDIA per-image fallback. */
function vision_verify(array $cands, array $page): array {
    if (!$cands) return ['status' => 'reject', 'why' => 'no candidates found'];
    $subject = "{$page['page_type']}: {$page['subject']}" . (!empty($page['definition']) ? " — meaning: {$page['definition']}" : '')
             . (!empty($page['people']) ? ' — people: ' . implode(', ', $page['people']) : '');
    // batch attempt (1 call)
    $content = [['type'=>'text','text'=>
        "Page subject: {$subject}. Which image best fits as the page's photo? Judge topical fit AND editorial quality (sharp, not catalog/specimen, not text-heavy). "
        . "STRICT JSON: {\"best\":<0-based index or -1>,\"confidence\":<0..1>,\"reason\":\"..\",\"rejected_reasons\":[\"..\"]}"]];
    $sent = 0;
    foreach ($cands as $c) {
        $b64 = vision_b64($c['thumb'] ?? $c['thumbnail'] ?? $c['url'], 448);
        $content[] = $b64 ? ['type'=>'image_url','image_url'=>['url'=>$b64]]
                          : ['type'=>'text','text'=>"(image {$sent} failed to load: skip it)"];
        $sent++;
    }
    $res = ai_chat([['role'=>'user','content'=>$content]], ['gemini'], 0.1);
    $j = isset($res['error']) ? null : ai_json($res['content']);
    if ($j && isset($j['best'])) {
        $b = (int)$j['best']; $conf = (float)($j['confidence'] ?? 0);
        if ($b < 0 || $b >= count($cands) || $conf < 0.70)                        // HARD GATE
            return ['status' => 'reject', 'why' => 'gemini: best below 0.70 (' . $conf . ')'];
        return ['status' => 'ok', 'cand'=>$cands[$b], 'confidence'=>$conf, 'reason'=>(string)($j['reason'] ?? '')];
    }
    // fallback: NVIDIA scores each (max 4 to bound cost/time)
    $bestI = -1; $bestS = 0; $tried = 0;
    foreach (array_slice($cands, 0, 4) as $i => $c) {
        $b64 = vision_b64($c['thumb'] ?? $c['url'], 448);
        if (!$b64) continue;
        $v = vision_nvidia("Page subject: {$subject}. Score 0-10: does this image fit the subject AND look editorial-quality? STRICT JSON: {\"score\":N}", $b64);
        if ($v === null) continue;                       // engine error, not a verdict
        $tried++;
        $s = (int)($v['score'] ?? 0);
        if ($s > $bestS) { $bestS = $s; $bestI = $i; }
    }
    if ($tried === 0) return ['status' => 'down', 'why' => 'no vision engine reachable'];
    if ($bestI < 0 || $bestS < 7) return ['status' => 'reject', 'why' => "nvidia: best {$bestS}/10 < 7"];  // HARD GATE
    return ['status' => 'ok', 'cand'=>$cands[$bestI], 'confidence'=>$bestS/10, 'reason'=>"nvidia score {$bestS}/10"];
}

/**
 * PUBLIC ENTRY. Returns:
 *   ['status'=>'ok','img',...,'confidence','reason']     verified image attached
 *   ['status'=>'reject','why'=>..]                       gate said no (final for this run)
 *   ['status'=>'down','why'=>..]                         vision unavailable: RETRY LATER, not a verdict
 */
function fetch_images_for_page(array $page): array {
    $page['page_type'] = $page['page_type'] ?? page_type_of($page);
    $queries = generate_visual_queries($page);
    if (!$queries && empty($page['people'])) return ['status' => 'reject', 'why' => 'query generation failed'];
    $cands = search_image_candidates($queries, $page);
    $v = vision_verify($cands, $page);
    if ($v['status'] !== 'ok') return $v;
    $hero = openverse_fetch_hero($v['cand'], $page['slug'] . '-scene');           // download, EXIF-orient, crop, credit
    if (!$hero) return ['status' => 'reject', 'why' => 'winner image failed to download'];
    return ['status'=>'ok', 'img'=>$hero['cover'], 'credit'=>$hero['credit'], 'credit_url'=>$hero['credit_url'],
            'confidence'=>$v['confidence'], 'reason'=>$v['reason'], 'matched'=>$v['cand']['title'] ?? ''];
}
