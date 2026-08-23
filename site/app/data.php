<?php
// GenZHype | fixture data (used until use_db = true). One real-shaped drama record.
// Keys mirror the DB schema so swapping to MySQL is a drop-in.
return [
  'dramas' => [
    'liv-vs-dani-grwm-fallout' => [
      'slug'        => 'liv-vs-dani-grwm-fallout',
      'title'       => 'Liv vs Dani: the GRWM brand-deal fallout',
      'eyebrow'     => 'Creator Drama',
      'status'      => 'Ongoing',           // Ongoing | Resolved | Breaking
      'published_iso' => '2026-04-10T09:00:00-04:00',
      'published'   => 'Apr 10, 2026',
      'updated_iso' => '2026-04-22T16:30:00-04:00',
      'updated'     => 'Apr 22, 2026',
      'cover'       => '/assets/covers/liv-vs-dani-grwm-fallout.svg',
      'summary'     => 'Liv and Dani fell out in April 2026 after Dani alleged that Liv took a skincare brand deal she was approached for first. Both have since posted responses. As of April 22, there has been no reconciliation.',
      'meta_desc'   => "A clear, sourced timeline of the Liv vs Dani GRWM brand-deal drama: what started it, who said what, the brand's response, and exactly where things stand today.",
      'background'  => [
        'Liv and Dani came up together in the GRWM ("get ready with me") corner of TikTok, often appearing in each other\'s videos through 2025. They were widely seen as close, which is why the April fallout caught their shared audience off guard.',
        'The flashpoint was a single skincare campaign. According to Dani, she was approached by the brand months earlier; according to Liv, she signed the deal she was offered and owed no one an explanation. What follows is a dated, sourced record of how the dispute played out in public.',
      ],
      'events' => [
        ['date_iso'=>'2026-04-02','date'=>'Apr 2, 2026','title'=>"Dani's subtweet",'desc'=>'Dani posts a since-deleted subtweet about "people who smile in your face," widely read as aimed at Liv. She did not name Liv directly.','sources'=>[1],'embed'=>'Embed slot | original post, reserved dimensions (no layout shift).','why'=>null],
        ['date_iso'=>'2026-04-03','date'=>'Apr 3, 2026','title'=>'Liv responds on live','desc'=>'Liv addresses it on a TikTok live, saying she "did nothing wrong" and would "let the receipts talk." She did not confirm the post was about her.','sources'=>[2],'embed'=>null,'why'=>null],
        ['date_iso'=>'2026-04-05','date'=>'Apr 5, 2026','title'=>'The brand confirms Liv','desc'=>'The skincare brand confirms it signed Liv for the campaign in an Instagram post, without addressing the dispute between the two creators.','sources'=>[3],'embed'=>null,'why'=>null],
        ['date_iso'=>'2026-04-09','date'=>'Apr 9, 2026','title'=>"Dani's response video",'desc'=>'Dani posts a 12-minute response laying out her side, including screenshots she says show she was approached first. The screenshots have not been independently verified.','sources'=>[4],'embed'=>null,'why'=>'This is the first piece of evidence either creator has shown publicly | but it is unverified, so we label it as a claim, not fact.'],
        ['date_iso'=>'2026-04-22','date'=>'Apr 22, 2026','title'=>'Liv sets the record straight','desc'=>'Liv uploads a "setting the record straight" video disputing Dani\'s account. No reconciliation has been announced by either side.','sources'=>[5],'embed'=>null,'why'=>null],
      ],
      'parties' => [
        ['slug'=>'liv','name'=>'Liv','role'=>'TikTok creator, GRWM and skincare'],
        ['slug'=>'dani','name'=>'Dani','role'=>'TikTok creator, beauty and lifestyle'],
      ],
      'faqs' => [
        ['q'=>'Are Liv and Dani still friends?','a'=>'No. As of April 22, 2026 both have posted public responses and there has been no reconciliation.'],
        ['q'=>'What started the Liv and Dani drama?','a'=>'A disputed skincare brand deal that Dani says she was first approached for before it went to Liv.'],
        ['q'=>'Did the brand respond?','a'=>'Yes. The brand confirmed on April 5, 2026 that it had signed Liv, without addressing the dispute directly.'],
      ],
      'sources' => [
        ['id'=>1,'text'=>'Dani, post on X (archived), Apr 2, 2026.'],
        ['id'=>2,'text'=>'Liv, TikTok live recap, Apr 3, 2026.'],
        ['id'=>3,'text'=>'Brand statement, official Instagram, Apr 5, 2026.'],
        ['id'=>4,'text'=>'Dani, response video, Apr 9, 2026.'],
        ['id'=>5,'text'=>'Liv, response video, Apr 22, 2026.'],
      ],
      'related' => [
        ['url'=>'/drama/','title'=>'More creator brand-deal fallouts','desc'=>'Browse the latest sponsorship disputes'],
        ['url'=>'/topic/tiktok-beefs/','title'=>'TikTok beefs','desc'=>'Every ongoing TikTok creator feud, tracked'],
      ],
    ],
  ],

  'creators' => [
    'liv' => [
      'slug'=>'liv','name'=>'Liv','handle'=>'@liv',
      'known_for'=>'GRWM and skincare creator',
      'platforms'=>[ ['p'=>'TikTok','followers'=>'4.0M'], ['p'=>'YouTube','followers'=>'2.0M'], ['p'=>'Instagram','followers'=>'800K'] ],
      'bio'=>'Liv is a US-based GRWM and skincare creator who built her audience on get-ready-with-me videos and brand collaborations. She is best known to a wider audience for the April 2026 brand-deal dispute with Dani.',
      'meta_desc'=>'Who is Liv? The GRWM and skincare TikTok creator: her platforms, what she is known for, and every drama she has been involved in, sourced and dated.',
    ],
    'dani' => [
      'slug'=>'dani','name'=>'Dani','handle'=>'@dani',
      'known_for'=>'Beauty and lifestyle creator',
      'platforms'=>[ ['p'=>'TikTok','followers'=>'3.1M'], ['p'=>'YouTube','followers'=>'1.4M'], ['p'=>'Instagram','followers'=>'620K'] ],
      'bio'=>'Dani is a US-based beauty and lifestyle creator known for product reviews and vlogs. In April 2026 she publicly disputed a skincare brand deal that went to fellow creator Liv.',
      'meta_desc'=>'Who is Dani? The beauty and lifestyle TikTok creator: her platforms, what she is known for, and every drama she has been involved in, sourced and dated.',
    ],
  ],
];
