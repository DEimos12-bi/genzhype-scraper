<?php
// GenZHype | shared entity graph (schema.org). Emitting the SAME Organization,
// WebSite and editor Person nodes -- each with a STABLE @id -- on every page turns
// the site into ONE resolvable entity instead of a fresh, unlinked "GenZHype" org
// per page. That @id-linked, cross-referenced graph (org <-> founder <-> site) is
// the strongest "cite this real, known publisher" signal for Google AI Overviews,
// ChatGPT (Bing index), and Perplexity. Author/publisher slots on each Article now
// REFERENCE these @ids instead of re-declaring loose, unlinked copies.

function genz_org_id():    string { return url() . '#organization'; }
function genz_site_id():   string { return url() . '#website'; }
function genz_editor_id(): string { return url('/about/') . '#deimos-emk'; }

/** The editor's photo, when it exists on disk. EXPECTED FILE (owner is sending):
 *  public_html/assets/authors/deimos-emk.jpg  (.webp or .png also accepted).
 *  File-existence gated so the page and the Person schema both pick it up the
 *  moment the file lands — no code change needed on arrival. */
function genz_author_photo(): ?string {
    foreach (['jpg', 'webp', 'png'] as $ext) {
        $rel = '/assets/authors/deimos-emk.' . $ext;
        if (is_file(dirname(__DIR__) . '/public_html' . $rel)) return $rel;
    }
    return null;
}

/** GenZHype's owned social profiles (the Organization's sameAs).
 *
 *  THE BAR FOR THIS LIST (owner decision 2026-08-05): a profile goes into
 *  sameAs when it HAS CONTENT, not when it exists. sameAs asks Google to
 *  corroborate the entity against the profile; a crawlable-but-empty page
 *  reads as manufactured signal and is worse than declaring nothing. The
 *  footer "Follow" links deliberately keep ALL profiles — that is a human
 *  invitation, not a machine claim. Substance was measured per profile on
 *  2026-08-05 (Bluesky public API, TikTok/Pinterest profile-JSON, Reddit RSS,
 *  YouTube channel HTML); re-measure before re-adding anything.
 *
 *  REMOVED 2026-08-05, measured empty — restore each only once it has posts:
 *    https://www.tiktok.com/@genzhype0          (videoCount:0, followerCount:0)
 *    https://www.youtube.com/@Genzhype-m4u      (~1 video, subs not exposed)
 *    https://www.reddit.com/user/genzhype__     (RSS valid, 0 public posts)
 *    https://www.pinterest.com/thegenzhype/     (pin_count:1, follower_count:0)
 */
function genz_org_sameas(): array {
    return [
        // measured ACTIVE: 27 posts, daily cadence, latest the night before
        // the audit (Bluesky public API, did:plc:ssgswpqrtjnedl7vcmi62xk4)
        'https://bsky.app/profile/genzhype.bsky.social',
        /* Instagram : l'URL declaree pointait vers « the_genzhype », un compte
           qui n'est PAS le notre. Le vrai est « therealgenzhype_ » (5 posts,
           verifie par le proprietaire dans son navigateur le 2026-08-05).
           Une sameAs vers le mauvais compte est pire qu'une absence : elle
           demande a un moteur de corroborer l'entite contre quelqu'un d'autre. */
        'https://www.instagram.com/therealgenzhype_/',
        /* RE-ADDED 2026-08-21 (backlink/entity work), and re-measured the
           way this file demands. The channel listed as removed above
           (@Genzhype-m4u) was never our channel: querying the YouTube
           Data API with OUR OWN oauth token returns
           id=UCki0t3c3uoFJBCW0RjSXF4A, handle=@genzhype2112, title
           "GenZHype", videoCount 63, subs 6. That is authoritative
           identity from the account itself, not a scrape — the strongest
           evidence this list can have, and 63 videos clears the
           has-content bar comfortably. */
        'https://www.youtube.com/@genzhype2112',
        /* RE-ADDED 2026-08-21. Owner supplied the handle, and unlike on
           2026-08-05 the public profile JSON now answers a server probe:
           uniqueId=genzhype0, videoCount=40, followerCount=7. On the Aug 5
           audit the same probe returned videoCount:0, which is why it was
           pulled — so this is the has-content bar being cleared by
           measurement, not by assertion. */
        'https://www.tiktok.com/@genzhype0',
        /* (superseded note) TikTok was OUT because: Our own delivery records say
           it is active (44 posts, 402 avg views — drafts do not draw
           views), but the Buffer token that knows the public handle lives
           in GitHub secrets, not here, and platform_videos stores no URL
           for tt rows. So the server cannot prove WHICH handle is ours,
           and this file's rule is that a sameAs pointing at the wrong
           account is worse than none (that exact mistake was made with
           Instagram). Owner: confirm the handle in the app and it goes in. */
        // KEPT on owner verdict 2026-08-05: these three are login-walled to
        // servers, so the owner eyeballed them in a browser and confirmed
        // they carry posts. Only a human can re-verify these — do not remove
        // or re-add them on the strength of a server-side probe.
        /* Threads et Facebook : RETIRES le 2026-08-05. Ni l'un ni l'autre n'est
           mesurable depuis un serveur (Threads sert une coquille de connexion
           de 580 Ko sans donnee de publication, Facebook repond 301/400), et le
           Social Studio montre 654 posts en file, aucun jamais publie. Non
           verifie = on ne declare pas : la meme regle que le reste du projet.
           A remettre des qu'un humain confirme du contenu dans son navigateur.
        'https://www.threads.com/@therealgenzhype_',
        'https://www.facebook.com/profile.php?id=61591658732535', */
    ];
}

/** The three shared, fully-defined, cross-linked nodes. Append to every page's @graph. */
function genz_entity_nodes(): array {
    $editorPhoto = genz_author_photo();
    return [
        [
            '@type'      => 'Organization',
            '@id'        => genz_org_id(),
            'name'       => 'GenZHype',
            'url'        => url(),
            'logo'       => ['@type' => 'ImageObject', 'url' => url('/assets/logo-mark.svg')],
            'sameAs'     => genz_org_sameas(),
            'founder'    => ['@id' => genz_editor_id()],
            'knowsAbout' => ['Gen Z slang', 'internet memes', 'creator culture', 'influencer drama'],
            // 2026-08-05: the machine-readable pointer to the editorial rules;
            // its absence was the one true gap in the "no human behind the
            // words" audit claim (the policy content itself already existed).
            'publishingPrinciples' => url('/editorial-policy/'),
        ],
        [
            '@type'       => 'WebSite',
            '@id'         => genz_site_id(),
            'name'        => 'GenZHype',
            'url'         => url(),
            'description' => 'Internet culture, documented: creator drama timelines plus slang, memes and gaming culture, decoded and sourced.',
            'publisher'   => ['@id' => genz_org_id()],
            'inLanguage'  => 'en-US',
        ],
        [
            '@type'       => 'Person',
            '@id'         => genz_editor_id(),
            'name'        => 'Deimos EMK',
            // the standalone author page (2026-08-05); the @id anchor stays on
            // /about/ so every existing author reference keeps resolving
            'url'         => url('/author/deimos-emk/'),
            'mainEntityOfPage' => url('/author/deimos-emk/'),
            'jobTitle'    => 'Founder & Editor',
            'worksFor'    => ['@id' => genz_org_id()],
            'knowsAbout'  => ['Gen Z slang', 'internet memes', 'gaming culture', 'creator culture'],
            'description' => 'Founder and editor of GenZHype, documenting creator and influencer culture as dated, sourced timelines.',
            /* The real name behind the byline. Without it the LinkedIn below is
               a link to a profile carrying a DIFFERENT name, which corroborates
               nothing — a search engine cannot join "Deimos EMK" to
               "Kaddari El Mahdi" unless we state the relationship ourselves. */
            'alternateName' => 'Kaddari El Mahdi',
            // photo auto-attaches when assets/authors/deimos-emk.{jpg,webp,png} lands
            ...($editorPhoto ? ['image' => url($editorPhoto)] : []),
            /* The Person's OWN profile, not the brand's. Until now this block
               had no sameAs at all: the eight social URLs above belong to the
               Organization, so the author existed only inside this website and
               the claim was circular. This is the first link that can be
               verified off-site.
               www. rather than the ma. country subdomain: that is the canonical
               form LinkedIn itself serves and the one search engines index. */
            'sameAs'      => ['https://www.linkedin.com/in/kaddari-elmahdi-8b145731a'],
        ],
    ];
}

/** Reference helpers for the author/publisher slots on an Article/NewsArticle/DefinedTerm. */
function genz_author_ref():    array { return ['@id' => genz_editor_id()]; }
function genz_publisher_ref(): array { return ['@id' => genz_org_id()]; }
