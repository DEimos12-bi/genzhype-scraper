"""WORDS TIKTOK HOLDS BACK (2026-11-05, from our own numbers). A normal post of ours gets 600-850 TikTok views; these got
2 to 30, i.e. TikTok never showed them: "$10,000 FOR 1 ACCOUNT" (an account sale), "how to play GTA 6 early" (an early-access
trick), "FAKE ADDRESS", "Instagram offers YouTubers up to $500,000" (a pay-out offer), "... codes". The same videos did normally
on Instagram (the GTA one got 739 there). Reporting the story is fine; the words on screen and in the caption must not read
like the scam or the sale itself. Used by the director (what goes on screen) and by the poster (whether TikTok gets it)."""
import re

PATTERNS = [
    ('an account for sale', r'\$\s?\d[\d,.]*\s*[kKmM]?\s*(?:for|per)\s+(?:1|one|an?|the)\s+(?:\w+\s+)?account'
                            r'|\b(?:sell(?:s|ing)?|sold|buy(?:s|ing)?|listed|listing|for sale)\b[^.]{0,40}\baccounts?\b'
                            r'|\baccounts?\b[^.]{0,30}\b(?:for sale|listed|on ebay|sells? for)\b'),
    ('an early-access trick', r'\b(?:play|get|download)\b[^.]{0,30}\b(?:early|before (?:launch|release)|for free)\b[^.]{0,30}\b(?:trick|hack|glitch|method)?'
                              r'|\b(?:trick|hack|glitch)\b[^.]{0,30}\b(?:play|get)\b[^.]{0,20}\bearly\b'),
    ('something fake', r'\bfake\s+(?:address|id|ids|account|accounts|receipt|receipts|card|cards|number)\b'),
    ('redeem codes', r'\b(?:redeem|promo|free)\s+codes?\b|\bcodes?\s*:\s*(?:\w+\s+)?\d{4}\b|\bcodes\b[^.]{0,20}\b(?:update|list|working)\b'),
    ('a pay-out offer', r'\b(?:offers?|pays?|paying|earn|earns|make)\b[^.]{0,30}\bup to \$\s?\d|\bearn \$\s?\d'),
]
_RX = [(why, re.compile(rx, re.I)) for why, rx in PATTERNS]


def risky(text):
    """The first reason the text reads like a scam or a sale to TikTok, or ''."""
    t = str(text or '')
    for why, rx in _RX:
        if rx.search(t):
            return why
    return ''


# POLITICS SKIPS TIKTOK (owner, 2026-11-08). TikTok keeps political videos up but rarely recommends them, and an account
# that keeps posting videos it will not recommend can be made un-recommendable as a whole. Our "Woke 3" meme (a politician
# named in a satire meme) got 5 views while the same night's other videos caught up to 90-800. Used by the POSTER only:
# such a video still goes to Instagram and YouTube. The director is not told to avoid these words (the facts stay).
POLITICS = re.compile(
    r'\b(?:trump|biden|kamala|harris\s+campaign|vance|obama|ocasio[- ]cortez|aoc|pelosi|schumer|desantis|newsom|mamdani|mcconnell)\b'
    r'|\b(?:elections?|electoral|ballots?|midterms?|primaries|congress(?:man|woman)?|senators?|the senate|white house|capitol hill)\b'
    r'|\b(?:democrats?|democratic party|republicans?|gop|maga|liberals?|conservatives?|left[- ]wing|right[- ]wing|leftists?)\b'
    r'|\bwoke\b(?!\s+up)|\b(?:who are you )?voting for\b|\bvote for (?:trump|biden|harris|him|her|them)\b'
    r'|\b(?:presidential|the president|president (?:trump|biden|obama|harris|vance))\b', re.I)


def political(text):
    """'politics' if the text is about politicians, parties or elections (TikTok rarely recommends those), else ''."""
    return 'politics' if POLITICS.search(str(text or '')) else ''


RULE = """PLATFORM-SAFE WORDS (TikTok silently holds back a video whose screen or caption reads like a scam or a sale; ours got
2 to 30 views instead of 600+): never put on screen, in a stamp, or in the opening line: a price for an account ("$10,000 FOR 1
ACCOUNT"), "fake" with an address, ID or account, a trick to play or get something early or free, redeem / promo "codes", an
offer that "pays up to $X". Tell the fact plainly in the voice instead ("one account went up on eBay for ten thousand dollars")
and stamp the THING ("PIRATE COIN", "EBAY LISTING"), not the sale."""
