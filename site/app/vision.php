<?php
// GenZHype | VISION QC. The AI *looks* at candidate images before they ship:
//  - vision_pick_face(): which licensed photo of a person matches the story's MOOD
//    (a smiling face on a lawsuit story is an emotional mismatch = no click)
//  - vision_check_image(): does a scene image actually show what we think it shows
// Gemini (vision-capable) only; on any failure returns null and callers fall back
// to the non-vision path. A page never breaks because vision was unavailable.

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/fetch_sources.php';

/** Download an image and normalize to a small base64 JPEG data-URL for the model. */
function vision_b64(string $url, int $maxw = 512): ?string {
    $bytes = fs_http_get($url, 20);
    if (!$bytes) return null;
    $src = @imagecreatefromstring($bytes);
    if (!$src) return null;
    $w = imagesx($src); $h = imagesy($src);
    if ($w > $maxw) {
        $nh = (int)($h * $maxw / $w);
        $dst = imagecreatetruecolor($maxw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxw, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }
    ob_start();
    imagejpeg($src, null, 80);
    $jpeg = ob_get_clean();
    imagedestroy($src);
    return 'data:image/jpeg;base64,' . base64_encode($jpeg);
}

/** Single-image vision call on NVIDIA Llama 3.2 Vision (fallback when Gemini is rate-limited). */
function vision_nvidia(string $prompt, string $b64, int $maxTokens = 120): ?array {
    global $CONFIG;
    $key = $CONFIG['ai']['nvidia']['key'] ?? '';
    if ($key === '') return null;
    $payload = json_encode([
        'model' => 'meta/llama-3.2-90b-vision-instruct',
        'messages' => [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $prompt],
            ['type' => 'image_url', 'image_url' => ['url' => $b64]],
        ]]],
        'temperature' => 0.1, 'max_tokens' => $maxTokens,
    ]);
    $ch = curl_init('https://integrate.api.nvidia.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$raw) return null;
    $j = json_decode($raw, true);
    $content = $j['choices'][0]['message']['content'] ?? null;
    return $content !== null ? ai_json($content) : null;
}

/**
 * Pick the candidate photo whose EXPRESSION fits the story mood.
 * $cands = [ ['url'=>..(thumb ok), ...], ... ]  (2-6 items)
 * Returns ['index'=>int,'reason'=>string] or null (none fits / vision down).
 */
function vision_pick_face(array $cands, string $person, string $mood): ?array {
    $moodHint = [
        'conflict' => 'serious, tense, confrontational, or neutral-stern. NOT smiling or joyful.',
        'scandal'  => 'serious, caught-out, uneasy, or neutral. NOT happy or celebratory.',
        'sad'      => 'somber, downcast, or neutral. NOT smiling.',
        'funny'    => 'expressive, laughing, playful, exaggerated.',
        'hype'     => 'energetic, excited, mid-action.',
        'neutral'  => 'clear, neutral, well-lit portrait.',
    ][$mood] ?? 'clear, neutral portrait';
    $content = [['type' => 'text', 'text' =>
        "These are candidate photos of {$person} for a story whose mood is \"{$mood}\". "
        . "The right photo: clearly shows {$person}'s face, sharp, face fills a good part of the frame, "
        . "and the facial expression reads as: {$moodHint} "
        . "Rate EVERY image 0-10 on fit. STRICT JSON only: {\"scores\":[..],\"best\":<0-based index>,\"reason\":\"short\"}. "
        . "If NONE scores 6+, set best to -1."]];
    $sent = 0;
    foreach ($cands as $c) {
        $b64 = vision_b64($c['thumb'] ?? $c['url']);
        if (!$b64) { $content[] = ['type' => 'text', 'text' => '(image ' . $sent . ' failed to load: score it 0)']; $sent++; continue; }
        $content[] = ['type' => 'image_url', 'image_url' => ['url' => $b64]];
        $sent++;
    }
    if ($sent === 0) return null;
    $res = ai_chat([['role' => 'user', 'content' => $content]], ['gemini'], 0.1);
    if (!isset($res['error'])) {
        $j = ai_json($res['content']);
        if ($j && isset($j['best'])) {
            $best = (int)$j['best'];
            if ($best >= 0 && $best < count($cands)) return ['index' => $best, 'reason' => mb_substr($j['reason'] ?? '', 0, 200)];
            return null; // valid verdict: none fits
        }
    }
    // FALLBACK: NVIDIA vision scores candidates one by one
    $bestIdx = -1; $bestScore = 0;
    foreach (array_slice($cands, 0, 4) as $i => $c) {
        $b64 = vision_b64($c['thumb'] ?? $c['url'], 448);
        if (!$b64) continue;
        $j = vision_nvidia(
            "Photo of {$person} for a story with mood \"{$mood}\". Score 0-10: face clearly visible AND sharp AND expression reads as: {$moodHint} STRICT JSON: {\"score\":N}",
            $b64);
        $s = (int)($j['score'] ?? 0);
        if ($s > $bestScore) { $bestScore = $s; $bestIdx = $i; }
    }
    if ($bestIdx >= 0 && $bestScore >= 6) return ['index' => $bestIdx, 'reason' => "nvidia fallback score {$bestScore}"];
    return null;
}

/**
 * Sanity-check a scene/concept image: does it actually show $expect and look
 * editorial-quality? Returns true/false; null when vision is unavailable.
 */
function vision_check_image(string $urlOrPath, string $expect): ?bool {
    $src = preg_match('#^https?://#', $urlOrPath) ? $urlOrPath : null;
    if (!$src) {
        $bytes = @file_get_contents($urlOrPath);
        if (!$bytes) return null;
        $img = @imagecreatefromstring($bytes);
        if (!$img) return null;
        ob_start(); imagejpeg($img, null, 80); $jpeg = ob_get_clean(); imagedestroy($img);
        $b64 = 'data:image/jpeg;base64,' . base64_encode($jpeg);
    } else {
        $b64 = vision_b64($src);
        if (!$b64) return null;
    }
    $prompt = "Does this image clearly show: {$expect}? And is it visually appealing enough for an editorial website (not a blurry phone pic, not a measurement/catalog photo, not text-heavy)? STRICT JSON: {\"shows\":true|false,\"quality_ok\":true|false}";
    $res = ai_chat([['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => $prompt],
        ['type' => 'image_url', 'image_url' => ['url' => $b64]],
    ]]], ['gemini'], 0.1);
    $j = isset($res['error']) ? null : ai_json($res['content']);
    if (!$j) $j = vision_nvidia($prompt, $b64);   // fallback
    if (!$j) return null;
    return !empty($j['shows']) && !empty($j['quality_ok']);
}
