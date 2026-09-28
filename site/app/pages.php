<?php
// GenZHype | static trust/legal pages (E-E-A-T + legal shield + footer targets).
// Content reflects the locked rules: sourcing policy, corrections, DMCA, right-of-reply.
function static_pages(): array {
    return [
        'about' => [
            'slug'=>'about','title'=>'About & editor','heading'=>'About GenZHype',
            'desc'=>'GenZHype is an independent desk founded by Deimos EMK, documenting creator and influencer culture as AI-drafted, source-checked, dated timelines.',
            'body_html'=>'<p class="body">GenZHype is an independent desk that documents internet creator and influencer culture as <strong>dated, sourced timelines</strong>. Our promise is simple: the receipts, not the gossip.</p>
<h2 class="sec">Who runs GenZHype</h2>
<p class="body">GenZHype is founded and run by <strong>Deimos EMK</strong>, who started the desk to cut through rage-bait and gossip with receipts: primary sources, clear dates, allegations clearly framed as allegations, and a right of reply for anyone featured. He builds and oversees the editorial system that holds every page to that standard. Reach the desk any time: <a href="/contact/">contact us</a>.</p>
<h2 class="sec">How every page is built</h2>
<p class="body">GenZHype pages are produced by our own automated editorial system: it gathers primary sources (original posts, videos, livestreams, official statements and reporting), arranges them into a dated chronological record, attributes every claim to its source, and frames anything unproven as alleged. The system is built, tuned and overseen by the desk against the standards on this page and our <a href="/how-we-source/">sourcing policy</a>.</p>
<h2 class="sec">AI &amp; automation, disclosed</h2>
<p class="body">In the interest of transparency: our pages are <strong>AI-drafted from the sources above and checked by an automated quality gate before publishing</strong>, then overseen by the desk. We do not claim a person hand-writes each page. What we do promise is that every page is held to our documented sourcing and accuracy standards, that claims are attributed to real sources, and that we correct what we get wrong.</p>
<p class="body">Stories about a death, sexual violence, abuse of minors or domestic abuse are also read by a person before they go live, and the page names who reviewed it.</p>
<h2 class="sec">Our standards</h2>
<p class="body">We follow a documented <a href="/how-we-source/">sourcing policy</a>, issue <a href="/corrections/">corrections</a> when we get something wrong, and offer a <a href="/right-of-reply/">right of reply</a> to anyone featured.</p>'],

        // 2026-08-05: the three URLs external E-E-A-T audits actually probe.
        // Real content only, describing checks that genuinely run in code.
        'editorial-policy' => [
            'slug'=>'editorial-policy','title'=>'Editorial policy','heading'=>'Editorial policy',
            'desc'=>'The rules every GenZHype page must pass before it publishes: verified sources, dated citations, no invented facts, corrections and right of reply.',
            'body_html'=>'<p class="body">Every page on GenZHype has to pass an automated editorial gate before it can go live. These are the rules the gate enforces. They are not aspirations: a page that fails them is not published, or stays off search engines until it is fixed.</p>
<h2 class="sec">What a page must prove</h2>
<p class="body"><strong>No origin story without an artifact.</strong> A page may only describe where a term or trend came from if we hold a dated, linkable artifact for that origin (the original post, an archived page, or a documented first appearance). If no artifact exists, the page says nothing about origin. It never says &ldquo;origins are debated&rdquo; as filler.</p>
<p class="body"><strong>Citations must exist.</strong> Quoted social posts are checked against the platform itself before they count: a post that does not resolve is rejected, however plausible it looks. Press quotes are copied verbatim from the article and carry the article&rsquo;s own publication date, never a guessed one.</p>
<p class="body"><strong>Real reporting, not glossaries.</strong> Dictionary sites, slang glossaries and crowd-sourced definitions can inform a draft, but they never count as evidence. A page needs independent published sources that actually use or report on the subject.</p>
<p class="body"><strong>No invented specifics.</strong> The system is forbidden from producing a name, handle, date, view count or follower count that its sources do not state. Shorter and grounded always beats longer and padded.</p>
<p class="body"><strong>Allegations are framed as allegations.</strong> Unproven claims are attributed to who made them and marked as alleged, with a <a href="/right-of-reply/">right of reply</a> for anyone featured.</p>
<p class="body"><strong>A person reads the gravest stories.</strong> A new story about a death, sexual violence, abuse of minors or domestic abuse is held until a person has read it, and the page then names who reviewed it.</p>
<h2 class="sec">AI disclosure</h2>
<p class="body">Pages are AI-drafted from retrieved sources, screened by the gate described above, and overseen by the desk. We label this openly on every page. See <a href="/about/">about &amp; editor</a> for who runs the desk and <a href="/methodology/">methodology</a> for how our numbers are computed.</p>
<h2 class="sec">When we get it wrong</h2>
<p class="body">We publish <a href="/corrections/">corrections</a>, retire pages that cannot meet the standard rather than leaving them up, and answer every <a href="/contact/">contact</a>.</p>'],

        'methodology' => [
            'slug'=>'methodology','title'=>'Methodology','heading'=>'Methodology',
            'desc'=>'How GenZHype computes its trend numbers and verifies its sources: the Trend Engine, the data behind the charts, and the checks each citation passes.',
            'body_html'=>'<p class="body">GenZHype publishes numbers of its own: a footprint score, trend direction, and daily interest figures on term pages. This page explains exactly where they come from, so any figure can be checked.</p>
<h2 class="sec">The Trend Engine</h2>
<p class="body">For each term we assemble a daily time series from two external, publicly checkable signals: <strong>YouTube reach</strong> (view counts on videos matched to the term) and <strong>Wikipedia or Wiktionary pageviews</strong> (daily reads of the matching entry, from the Wikimedia public API). Neither number is ours, which means both can be independently verified.</p>
<p class="body">Trend direction is scored with a <strong>Mann-Kendall trend test</strong> on the daily series, a standard statistical test for monotonic trends. The footprint score out of 100 combines the size of both signals. Pages show the series as a sparkline with an as-of date, refreshed nightly when collection runs.</p>
<h2 class="sec">Source verification</h2>
<p class="body">Cited social posts are verified against the platform before publication and re-checked by a link auditor afterwards; dead or deleted posts are flagged and removed from the page rather than left as broken evidence. Press citations carry the publication date declared by the article itself. Our sourcing tiers are documented in <a href="/how-we-source/">how we source</a>.</p>
<h2 class="sec">Limits, stated plainly</h2>
<p class="body">Trend data starts on the day a page enters collection, so brand-new pages can show no chart yet. Wikipedia pageviews measure reading interest, not usage. Where a number cannot be computed honestly, the page shows nothing instead of an estimate. The rules a page must pass before publishing are in our <a href="/editorial-policy/">editorial policy</a>.</p>'],

        'author/deimos-emk' => [
            'slug'=>'author/deimos-emk','title'=>'Deimos EMK, founder & editor','heading'=>'Deimos EMK',
            'desc'=>'Deimos EMK is the founder and editor of GenZHype, running the automated editorial system that documents Gen Z slang, memes and creator culture.',
            'body_html'=>'<p class="body"><strong>Deimos EMK</strong> is the founder and editor of GenZHype. He started the desk to cover internet culture the way it should be covered: with receipts. Primary sources, clear dates, allegations framed as allegations, and a standing right of reply for anyone featured.</p>
<p class="body">Deimos EMK is the pen name of <strong>Kaddari El Mahdi</strong> (<a href="https://www.linkedin.com/in/kaddari-elmahdi-8b145731a" target="_blank" rel="me noopener">LinkedIn</a>), who runs the desk under it.</p>
<h2 class="sec">What he does here</h2>
<p class="body">Deimos built and runs the editorial system behind every GenZHype page: the sourcing pipeline that retrieves primary material, the automated gate that blocks any page with unverified claims, and the <a href="/methodology/">Trend Engine</a> that computes the site&rsquo;s daily trend data from public signals. Pages are AI-drafted under that system and held to the standards in the <a href="/editorial-policy/">editorial policy</a>, which he maintains and enforces.</p>
<h2 class="sec">Coverage</h2>
<p class="body">Gen Z slang and how it actually gets used, meme history with dated artifacts, gaming culture, and creator drama documented as sourced timelines rather than gossip.</p>
<h2 class="sec">Contact</h2>
<p class="body">Corrections, tips and replies all reach the desk: <a href="/contact/">contact GenZHype</a>. More on the site itself: <a href="/about/">about GenZHype</a>.</p>'],

        'how-we-source' => [
            'slug'=>'how-we-source','title'=>'How we source','heading'=>'How we source',
            'desc'=>'Our sourcing and verification standards: primary sources, dated events, attribution, and alleged-framing for unproven claims.',
            'body_html'=>'<p class="body">Every GenZHype timeline is built on these rules:</p>
<h2 class="sec">Primary sources first</h2>
<p class="body">We prioritize the original material: the actual post, video, livestream, court filing, or official statement. Where we rely on reporting by another outlet, we attribute it by name.</p>
<h2 class="sec">Every claim is attributed</h2>
<p class="body">No event appears on a timeline without a source. If a claim cannot be sourced, it does not run.</p>
<h2 class="sec">Unproven means alleged</h2>
<p class="body">Allegations, leaks, and disputed accounts are clearly labeled as alleged or reportedly, and attributed to whoever made them. We do not state unproven accusations as fact, and we do not state that anyone committed a crime unless there is an official charge or conviction to cite.</p>
<h2 class="sec">Living documents</h2>
<p class="body">Situations evolve. We append new dated, sourced events and update the "last updated" date when a page changes. We do not change dates without a substantive update.</p>
<h2 class="sec">Automated drafting, held to these rules</h2>
<p class="body">GenZHype pages are AI-drafted from the sources above and must pass an automated quality gate (depth, sourcing and accuracy checks) before they publish. Automation lets us cover a lot, quickly; the rules on this page are what keep it honest. When automation gets something wrong, <a href="/corrections/">tell us</a> and we will fix it.</p>'],

        'corrections' => [
            'slug'=>'corrections','title'=>'Corrections','heading'=>'Corrections policy',
            'desc'=>'How GenZHype corrects errors: what to send, what we change, and what the dates on a page mean.',
            'body_html'=>'<p class="body">We aim to be accurate. When we are not, we fix it.</p>
<h2 class="sec">How to ask for a correction</h2>
<p class="body">Email <a href="mailto:contact@genzhype.com">contact@genzhype.com</a> with the page address, the sentence or date that is wrong and, if you have one, a source that shows the right version. Requests from people featured on a page are handled under our <a href="/right-of-reply/">right of reply</a>.</p>
<h2 class="sec">What we change</h2>
<p class="body">We correct the page itself: a wrong date, name, attribution or claim is fixed in place, and a claim we can no longer source is removed. A story that cannot be brought up to our <a href="/how-we-source/">sourcing standard</a> is taken off the site rather than left up.</p>
<h2 class="sec">What the dates mean</h2>
<p class="body">A timeline\'s "Updated" date moves only when a new dated development is added, so a new date always means new information. A correction to existing text is made in place.</p>'],

        'contact' => [
            'slug'=>'contact','title'=>'Contact','heading'=>'Contact & tips',
            'desc'=>'Contact GenZHype for tips, sources, corrections, a right of reply, copyright or privacy questions: contact@genzhype.com.',
            'body_html'=>'<p class="body">Everything reaches one inbox: <a href="mailto:contact@genzhype.com"><strong>contact@genzhype.com</strong></a>. GenZHype is run by Kaddari El Mahdi, who writes here as <a href="/author/deimos-emk/">Deimos EMK</a>.</p>
<h2 class="sec">What to send</h2>
<p class="body"><strong>A tip or a source.</strong> Tell us what happened and link the original post, video or document if you have it. We publish only what we can source, so a link to the original is what turns a tip into a timeline entry.</p>
<p class="body"><strong>A correction.</strong> Include the page address, what is wrong and, if you can, a source that shows the right version. See the <a href="/corrections/">corrections policy</a>.</p>
<p class="body"><strong>A reply from someone featured.</strong> If a timeline is about you, send your response with the page address. See <a href="/right-of-reply/">right of reply</a>.</p>
<p class="body"><strong>Copyright.</strong> For a takedown notice, <a href="/dmca/">DMCA and copyright</a> lists what the notice must include.</p>
<p class="body"><strong>Privacy.</strong> For data and cookies, see the <a href="/privacy/">privacy policy</a> or write to the same address.</p>
<h2 class="sec">What happens next</h2>
<p class="body">We read every message. Corrections and replies that hold up are made on the page itself, and a reply from someone featured is attributed to them. We do not publish your name or email address without your permission.</p>'],

        'right-of-reply' => [
            'slug'=>'right-of-reply','title'=>'Right of reply','heading'=>'Right of reply',
            'desc'=>'Anyone featured on GenZHype may submit a response, clarification, or denial, which we will fairly reflect.',
            'body_html'=>'<p class="body">If you are a person featured in a GenZHype timeline, you have a right of reply.</p>
<p class="body">Send your response, clarification, or denial to <a href="mailto:contact@genzhype.com">contact@genzhype.com</a> with the page URL. We will fairly reflect substantive responses on the relevant page, attributed to you, and we will correct anything demonstrably inaccurate.</p>'],

        'dmca' => [
            'slug'=>'dmca','title'=>'DMCA','heading'=>'DMCA & copyright',
            'desc'=>'GenZHype respects copyright. How to submit a DMCA takedown notice for content you own.',
            'body_html'=>'<p class="body">GenZHype embeds original posts from their source platforms rather than re-hosting them, and uses its own original cover graphics. We respect copyright.</p>
<p class="body">If you believe content on this site infringes your copyright, send a notice to <a href="mailto:contact@genzhype.com">contact@genzhype.com</a> including: identification of the work, the URL of the material, your contact information, a statement of good-faith belief, and a statement under penalty of perjury that you are authorized to act. We process valid notices promptly.</p>'],

        'privacy' => [
            'slug'=>'privacy','title'=>'Privacy','heading'=>'Privacy policy',
            'desc'=>'What GenZHype collects when you visit: server logs, Google Analytics, Google AdSense advertising cookies and one site cookie, and how to opt out.',
            'body_html'=>'<p class="body">GenZHype is an editorial website run by Kaddari El Mahdi, who writes here as <a href="/author/deimos-emk/">Deimos EMK</a>, based in Morocco. This page explains what the site collects when you visit, why, and the choices you have. Questions: <a href="mailto:contact@genzhype.com">contact@genzhype.com</a>.</p>
<h2 class="sec">What the site collects</h2>
<p class="body"><strong>Server logs.</strong> Like almost every website, our web host records standard request data when a page loads: your IP address, browser and device type, the page requested, the time and the page you came from. We use these logs to keep the site running and secure.</p>
<p class="body"><strong>Analytics.</strong> We use Google Analytics to understand which pages are read and how visitors find them, for example pages viewed, device type and approximate location. It uses cookies and loads shortly after the page. You can block it with Google\'s <a href="https://tools.google.com/dlpage/gaoptout" rel="noopener">opt-out browser add-on</a>.</p>
<p class="body"><strong>Advertising.</strong> We use Google AdSense for advertising. Third party vendors, including Google, use cookies to serve ads based on a user\'s prior visits to this website or other websites. Google\'s use of advertising cookies enables it and its partners to serve ads to our users based on their visit to our site and/or other sites on the Internet. You may opt out of personalized advertising by visiting <a href="https://www.google.com/settings/ads" rel="noopener">Ads Settings</a>. You can also opt out of some third-party vendors\' use of cookies for personalized advertising at <a href="https://www.aboutads.info" rel="noopener">www.aboutads.info</a>. Google explains <a href="https://policies.google.com/technologies/partner-sites" rel="noopener">how it uses information from sites that use its services</a>.</p>
<p class="body"><strong>One site cookie.</strong> Our own cookie, <code>gz_seen</code>, remembers which stories you have already read on this device so the "read next" suggestion does not repeat them. It holds only page names, stays in your browser and expires after 30 days.</p>
<p class="body"><strong>Embedded posts.</strong> Our pages embed original posts from platforms such as X, TikTok, YouTube, Instagram and Reddit, so you can see the evidence at its source. When an embed loads, that platform may set its own cookies under its own privacy policy.</p>
<p class="body"><strong>Email.</strong> If you write to us, we keep your message and address to answer it and, for a correction or a right of reply, to record what was asked and what we changed.</p>
<h2 class="sec">What the site does not do</h2>
<p class="body">There are no accounts, comments or newsletter, and no forms that ask for personal details. We do not sell personal information.</p>
<h2 class="sec">Your choices</h2>
<p class="body">You can block or delete cookies in your browser settings; the site works without them. The Ads Settings, aboutads.info and Google Analytics links above let you opt out of personalized ads and analytics. To ask about data that relates to you, write to <a href="mailto:contact@genzhype.com">contact@genzhype.com</a>.</p>
<h2 class="sec">Children</h2>
<p class="body">GenZHype is not directed to children under 13, and we do not knowingly collect personal information from them.</p>
<h2 class="sec">Changes</h2>
<p class="body">If what the site collects changes, this page changes with it. Last updated September 26, 2026.</p>'],

        'terms' => [
            'slug'=>'terms','title'=>'Terms','heading'=>'Terms of use',
            'desc'=>'Terms of use for GenZHype.',
            'body_html'=>'<p class="body">GenZHype is provided for informational and commentary purposes. Content reflects sourced reporting and clearly-labeled allegations; it is not legal advice and may be updated as situations develop.</p>
<p class="body">By using this site you agree to use it lawfully. For corrections see <a href="/corrections/">corrections</a>; for copyright see <a href="/dmca/">DMCA</a>.</p>'],
    ];
}
