<?php
// GenZHype | multi-provider AI client (BRAIN). All three free providers speak
// the OpenAI chat-completions protocol, so one client covers Gemini, OpenRouter
// and NVIDIA with rotation + fallback. Every call is logged to ai_reviews.

function ai_providers(): array {
    global $CONFIG;
    $ai = $CONFIG['ai'] ?? [];
    // each MODEL has its own per-minute quota on the SAME key: rotating models
    // on one account multiplies free capacity legally (no multi-accounting).
    $defs = [
        'gemini' => [
            'url'    => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
            // MODEL ROT (2026-08-22): all three models configured here had
            // died — 2.5-flash returned 429 (daily quota gone), 2.5-flash-lite
            // and 2.0-flash returned 404 "no longer available". Gemini was
            // therefore failing ENTIRELY, which is what stopped the drafter
            // and left the site publishing nothing for days. Verified live
            // against the models endpoint on our own key before writing:
            //   gemini-3.5-flash        200  (quality first)
            //   gemma-4-31b-it          200  (the high-quota workhorse)
            //   gemini-flash-lite-latest 200 (moving alias — survives renames)
            //   gemini-3.5-flash-lite   200
            // A '-latest' alias is deliberately kept in the chain so the next
            // deprecation cannot take every model out at once again.
            'models' => $ai['gemini']['models'] ?? array_filter([$ai['gemini']['model'] ?? 'gemini-3.5-flash', 'gemma-4-31b-it', 'gemini-flash-lite-latest', 'gemini-3.5-flash-lite']),
            'key'    => $ai['gemini']['key'] ?? '',
        ],
        'openrouter' => [
            'url'    => 'https://openrouter.ai/api/v1/chat/completions',
            'models' => $ai['openrouter']['models'] ?? array_filter([$ai['openrouter']['model'] ?? 'openai/gpt-oss-120b:free', 'meta-llama/llama-3.3-70b-instruct:free']),
            'key'    => $ai['openrouter']['key'] ?? '',
        ],
        'nvidia' => [
            'url'    => 'https://integrate.api.nvidia.com/v1/chat/completions',
            'models' => $ai['nvidia']['models'] ?? array_filter([$ai['nvidia']['model'] ?? 'meta/llama-3.3-70b-instruct', 'meta/llama-3.2-90b-vision-instruct']),
            'key'    => $ai['nvidia']['key'] ?? '',
        ],
        // SECOND NVIDIA ACCOUNT (2026-08-30, owner key): same API, own quota.
        // Sits AFTER 'nvidia' in the default order — the chain only reaches it
        // when account A is exhausted, so together they behave like one
        // provider with double the ceiling ("looks unlimited" is the owner's
        // spec; two accounts + model rotation is how far that legally goes).
        'nvidia_b' => [
            'url'    => 'https://integrate.api.nvidia.com/v1/chat/completions',
            'models' => $ai['nvidia_b']['models'] ?? [],
            'key'    => $ai['nvidia_b']['key'] ?? '',
        ],
        // dedicated DIRECTOR brain (own key, own quota — never in default order;
        // only video_write_shotlist asks for it explicitly). Reasoning models
        // need generous max_tokens: thinking tokens count against the budget.
        'nvidia_director' => [
            'url'        => 'https://integrate.api.nvidia.com/v1/chat/completions',
            'models'     => $ai['nvidia_director']['models'] ?? array_filter([$ai['nvidia_director']['model'] ?? '']),
            'key'        => $ai['nvidia_director']['key'] ?? '',
            'max_tokens' => (int)($ai['nvidia_director']['max_tokens'] ?? 16384),
        ],
    ];
    foreach ($defs as &$d) $d['models'] = array_values(array_unique($d['models']));
    unset($d);
    return array_filter($defs, fn($d) => $d['key'] !== '');
}

/**
 * ai_chat: call providers in $order (fallback on failure).
 * $skip: "provider/model" entries to pass over (a caller retrying after an unusable reply).
 * Returns ['content'=>string,'provider'=>,'model'=>,'tokens'=>int] or ['error'=>...].
 */
function ai_chat(array $messages, array $order = ['gemini', 'openrouter', 'nvidia', 'nvidia_b'], float $temperature = 0.3, int $timeout = 120, array $skip = []): array {
    // account B is a continuation of the nvidia pool: any caller that asks for
    // 'nvidia' implicitly gets 'nvidia_b' as the next rung (2026-08-30)
    if (in_array('nvidia', $order, true) && !in_array('nvidia_b', $order, true)) $order[] = 'nvidia_b';
    $providers = ai_providers();
    if (!$providers) return ['error' => 'no AI keys configured in app/config.php (ai section)'];
    $last = 'no provider attempted';
    foreach ($order as $name) {
        if (!isset($providers[$name])) continue;
        $p = $providers[$name];
        // model rotation: a 429 on one model falls through to the next model's
        // independent quota on the SAME key, before changing provider
        foreach ($p['models'] as $mi => $model) {
            if ($skip && in_array("$name/$model", $skip, true)) continue;
            $body = [
                'model'       => $model,
                'messages'    => $messages,
                'temperature' => $temperature,
            ];
            // reasoning models (director brain) need an explicit token budget:
            // their <think> tokens eat the default completion cap otherwise
            if (!empty($p['max_tokens'])) $body['max_tokens'] = (int)$p['max_tokens'];
            $payload = json_encode($body);
            $ch = curl_init($p['url']);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $p['key'],
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
            ]);
            $raw  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            // the 20s polite retry only for long-running (batch/cron) calls; a short
            // timeout means an interactive caller (web) that must not block -> skip it
            if ($code === 429 && $mi === count($p['models']) - 1 && $timeout >= 60) {
                sleep(20);
                $ch = curl_init($p['url']);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $p['key']],
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
                ]);
                $raw = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
            }
            if ($code !== 200 || !$raw) { $last = "$name/$model HTTP $code"; continue; }
            $j = json_decode($raw, true);
            $content = $j['choices'][0]['message']['content'] ?? null;
            if ($content === null) { $last = "$name/$model: empty content"; continue; }
            return [
                'content'  => $content,
                'provider' => $name,
                'model'    => $model,
                'tokens'   => (int)($j['usage']['total_tokens'] ?? 0),
            ];
        }
    }
    return ['error' => "all providers failed (last: $last)"];
}

/** Extract a JSON object from a model reply (handles ```json fences and
 *  reasoning-model <think>...</think> preambles — everything up to the LAST
 *  closing think tag is chain-of-thought, not the answer). */
function ai_json(string $content): ?array {
    $c = trim($content);
    // 2026-08-22: gemma-4 emits <thought>...</thought>, NOT <think>. With only
    // the <think> tag stripped, every gemma reply parsed to null — a second
    // silent failure sitting right behind the dead-model one, and it would
    // have kept the drafter broken after the models were fixed.
    foreach (['</think>', '</thought>'] as $tag) {
        if (($tp = strripos($c, $tag)) !== false) {
            $c = trim(substr($c, $tp + strlen($tag)));
        }
    }
    if (preg_match('/```(?:json)?\s*(.*?)```/s', $c, $m)) $c = trim($m[1]);
    $start = strpos($c, '{');
    $end   = strrpos($c, '}');
    if ($start === false || $end === false) return null;
    $j = json_decode(substr($c, $start, $end - $start + 1), true);
    return is_array($j) ? $j : null;
}

/** r153 (2026-09-10): the plain-text twin of ai_json(). A reasoning model can reply
 *  with its notes instead of an answer — "<thought>* Original text: ... Constraint 5:
 *  Reply with ONLY the rewritten text" — and, when it runs out of tokens, with NO
 *  closing tag. The length fixers in draft.php / draft_term.php stored that verbatim
 *  and cut it to size: 30 pages carried it as their title, description or summary.
 *  Returns the answer, or null when there is no usable answer (caller keeps its text). */
function ai_text(string $content): ?string {
    $c = trim($content);
    foreach (['</think>', '</thought>'] as $tag) {
        if (($tp = strripos($c, $tag)) !== false) $c = trim(substr($c, $tp + strlen($tag)));
    }
    if ($c === '' || preg_match('/^<\s*(think|thought)\b/i', $c)) return null;               // unterminated reasoning
    if (preg_match('/^\s*(\*\s*)?(Original (text|title)|Input( text)?|Constraint \d+)\s*:/i', $c)) return null;
    $c = trim($c, " \n\r\t\"'");
    return $c === '' ? null : $c;
}

/** Log a pipeline AI call into ai_reviews. */
function ai_log(?int $page_id, string $stage, array $res, ?array $verdict, ?bool $passed): void {
    $pdo = db();
    $st = $pdo->prepare("INSERT INTO ai_reviews (page_id, stage, provider, model, verdict, passed, tokens) VALUES (?,?,?,?,?,?,?)");
    $st->execute([
        $page_id,
        $stage,
        $res['provider'] ?? 'openrouter',
        $res['model'] ?? null,
        $verdict ? json_encode($verdict, JSON_UNESCAPED_SLASHES) : null,
        $passed === null ? null : (int)$passed,
        $res['tokens'] ?? null,
    ]);
}
