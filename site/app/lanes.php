<?php
// GenZHype | LANE REGISTRY. Every encyclopedic lane (slang, memes, gaming, music)
// shares one engine: terms table + term template + term gate + term drafter.
// A lane = this config + a candidate backlog. Drama is separate (timeline engine).

function lanes(): array {
    return [
        'slang' => [
            'cand_type'  => 'term',
            'prefix'     => '/slang/',
            'crumb'      => 'Slang',
            'eyebrow'    => 'Gen Z Slang',
            'kicker'     => 'GEN Z SLANG / DEFINED',
            'set_name'   => 'GenZHype Slang Dictionary',
            'hub_title'  => 'Gen Z Slang Dictionary: %d+ Terms Decoded | GenZHype',
            'hub_h1'     => 'Gen Z slang, decoded',
            'hub_desc'   => 'Every Gen Z and internet slang term, decoded: what it means, where it came from, and whether it is still cool to say. Updated daily.',
            'hub_sub'    => 'What it means, where it came from, and whether it&rsquo;s already cringe. Updated daily by the desk.',
            'headings'   => ['meaning' => 'What it means', 'origin' => 'Where it came from', 'trending' => "Why it's everywhere", 'examples' => 'How to use it', 'related' => 'Related slang'],
            'desk_role'  => 'You write encyclopedic dictionary entries for internet/Gen Z slang terms.',
            'cta'        => 'Hearing a word we haven&rsquo;t defined? Send it to the desk',
        ],
        'meme' => [
            'cand_type'  => 'meme',
            'prefix'     => '/meme/',
            'crumb'      => 'Memes',
            'eyebrow'    => 'Meme',
            'kicker'     => 'MEME / EXPLAINED',
            'set_name'   => 'GenZHype Meme Encyclopedia',
            'hub_title'  => 'Meme Encyclopedia: %d+ Memes Explained | GenZHype',
            'hub_h1'     => 'Memes, explained',
            'hub_desc'   => 'Every meme explained: what it is, where it started, how it spread, and the variants worth knowing. Updated daily.',
            'hub_sub'    => 'Origin, spread, and the variants worth knowing. Updated daily by the desk.',
            'headings'   => ['meaning' => 'What the meme is', 'origin' => 'Origin', 'trending' => 'How it spread', 'examples' => 'Variants &amp; examples', 'related' => 'Related memes'],
            'desk_role'  => 'You write KnowYourMeme-style encyclopedia entries for internet memes: what the meme is, its origin, how it spread, and notable variants.',
            'cta'        => 'Seen a variant we missed? Send it to the desk',
        ],
        'gaming' => [
            'cand_type'  => 'gaming',
            'prefix'     => '/gaming/',
            'crumb'      => 'Gaming',
            'eyebrow'    => 'Gaming &amp; Internet Culture',
            'kicker'     => 'GAMING CULTURE / EXPLAINED',
            'set_name'   => 'GenZHype Gaming Culture Guide',
            'hub_title'  => 'Gaming Slang &amp; Culture: %d+ Terms Explained | GenZHype',
            'hub_h1'     => 'Gaming culture, explained',
            'hub_desc'   => 'Gaming and streaming culture explained: the terms, trends and moments from Twitch, Discord and beyond. Updated daily.',
            'hub_sub'    => 'The terms and trends from Twitch chat, Discord and beyond. Updated daily by the desk.',
            'headings'   => ['meaning' => 'What it means', 'origin' => 'Where it started', 'trending' => "Why it's everywhere", 'examples' => 'How it gets used', 'related' => 'Related terms'],
            'desk_role'  => 'You write encyclopedic entries for gaming and streaming culture: terms, trends and rituals from Twitch, Discord, and gaming communities.',
            'cta'        => 'Hearing something new in chat? Send it to the desk',
        ],
        'music' => [   // hype culture: parked until the site has authority (fortress SERPs)
            'cand_type'  => 'music',
            'prefix'     => '/hype/',
            'crumb'      => 'Hype',
            'eyebrow'    => 'Hype Culture',
            'kicker'     => 'HYPE CULTURE / EXPLAINED',
            'set_name'   => 'GenZHype Hype Culture Guide',
            'hub_title'  => 'Hype Culture: %d+ Trends Explained | GenZHype',
            'hub_h1'     => 'Hype culture, explained',
            'hub_desc'   => 'Music, streetwear and hype culture explained: the trends, drops and micro-movements. Updated daily.',
            'hub_sub'    => 'Music, streetwear and the micro-trends in between. Updated daily by the desk.',
            'headings'   => ['meaning' => 'What it is', 'origin' => 'Where it came from', 'trending' => "Why it's everywhere", 'examples' => 'How it shows up', 'related' => 'Related trends'],
            'desk_role'  => 'You write encyclopedic entries for music, streetwear and hype culture: trends, drops, aesthetics and micro-movements.',
            'cta'        => 'Spotted a trend we missed? Send it to the desk',
        ],
    ];
}

/** Lane key for a candidates.type value (term->slang etc). Null if not a lane type. */
function lane_for_cand_type(string $type): ?string {
    foreach (lanes() as $key => $cfg) if ($cfg['cand_type'] === $type) return $key;
    return null;
}
