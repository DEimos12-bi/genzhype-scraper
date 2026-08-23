<?php
/**
 * GenZHype | config.sample.php
 *
 * The real app/config.php is NEVER committed - it holds live credentials and is
 * excluded by app/.gitignore. This file is the shape of it: every key the code
 * reads, with the values stripped. Copy it to app/config.php on the server and
 * fill it in there.
 */
return [
    'site_name' => '',
    'base_url' => '',
    'tagline' => '',
    'indexnow_key' => '',   // secret - set on the server only
    'ingest_token' => '',   // secret - set on the server only
    'pinterest_sandbox_token' => '',   // secret - set on the server only
    'admin_pass_hash' => '',   // secret - set on the server only
    'ai' => '',
    'gemini' => '',
    'openrouter' => '',
    'models' => '',
    'nvidia' => '',
    'nvidia_director' => '',
    'key' => '',   // secret - set on the server only
    'model' => '',
    'ai_rotation' => '',
    'groq_key' => '',   // secret - set on the server only
    'cf_account' => '',
    'cf_token' => '',   // secret - set on the server only
    'yt_client_id' => '',   // secret - set on the server only
    'yt_client_secret' => '',   // secret - set on the server only
    'tt_client_key' => '',   // secret - set on the server only
    'tt_client_secret' => '',   // secret - set on the server only
    'social_tokens' => '',   // secret - set on the server only
    'buffer' => '',
    'fb_user' => '',
    'ig' => '',
    'th' => '',
    'x_auth_token' => '',   // secret - set on the server only
    'x_ct0' => '',
    'reddit_cookie' => '',
    'fb' => '',
    'use_db' => '',
    'auto_publish' => '',
    'daily_publish_cap' => '',
    'gsc_meta' => '',
    'ga4_id' => '',
    'adsense_pub' => '',
    'flickr_key' => '',   // secret - set on the server only
    'pexels_key' => '',   // secret - set on the server only
    'pixabay_key' => '',   // secret - set on the server only
    'unsplash_key' => '',   // secret - set on the server only
    'giphy_key' => '',   // secret - set on the server only
    'tenor_key' => '',   // secret - set on the server only
    'youtube_key' => '',   // secret - set on the server only
    'image_gen' => '',
    'db' => '',
    'host' => '',
    'name' => '',
    'user' => '',
    'pass' => '',   // secret - set on the server only
    'charset' => '',
];
