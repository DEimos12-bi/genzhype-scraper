"""WHO IS SPEAKING. The machine was built for one channel (GenZHype). A video for another channel (an agency
explaining its trade to business owners) is the same machine with another name on it: who the channel is, who watches
it, what its closing says, where "link in bio" leads, its colours. A brand is a small file, brands/<id>.json, laid over
the default one (config.json "brand"). make.py --brand <id> chooses it for a work folder, and the choice stays with
that folder (the file "brand" in it); every step reads it from the environment (V3_BRAND).
Fields:
  name      as written ("GenZHype")            spoken   as the voice says it ("Gen Z Hype")      shown   as a caption writes it
  site      the address shown at the end       url      the page photographed for the closing of a story with no page
  about     one or two sentences: what the channel is (opens every prompt)
  angle     optional: what every video of this channel must do for its viewer
  desk      what its desk covers (the researcher and the desk editor are told)
  viewer    who is watching (the writer is told)
  closing_page / closing_topic   the last spoken line (%s = the spoken name): a story with its own page / without
  pinned    the end of the pinned comment      file     the first word of the video's file name
  css       optional: colours laid over the engine's own
usage: brand.py [id]   (prints the brand)"""
import json
import os
import sys

import ai

HERE = os.path.dirname(os.path.abspath(__file__))
DEFAULT = {
    'id': '', 'name': 'GenZHype', 'spoken': 'Gen Z Hype', 'shown': 'GenZHype', 'site': 'genzhype.com', 'url': 'https://genzhype.com/',
    'about': 'GenZHype is "the receipts, not the gossip": creator, gaming and meme stories told with the proof on screen.',
    'angle': '',
    'desk': '"the receipts, not the gossip": creator, gaming and meme stories told with the proof',
    'viewer': 'A 16-year-old scrolling with the sound on. They give the video two seconds, they have never heard of this story, and they leave the moment a sentence sounds like an article or a school report.',
    'closing_page': 'The full timeline, and every receipt, is on %s. Link in bio.',
    'closing_topic': 'More stories like this one, with every receipt, are on %s. Link in bio.',
    'pinned': 'receipts on genzhype.com (link in bio)',
    'file': 'genzhype', 'css': '',
}


def load(bid=None):
    bid = os.environ.get('V3_BRAND', '') if bid is None else bid
    b = dict(DEFAULT, **(ai.CONFIG.get('brand') or {}))
    if bid:
        b.update(json.load(open(os.path.join(HERE, 'brands', bid + '.json'), encoding='utf-8')))      # (an unknown brand stops the run here, by name)
        b['id'] = bid
    for k in ('about', 'angle', 'desk', 'viewer', 'pinned'):   # these go into prompts that are filled with %
        b[k] = str(b.get(k) or '').replace('%', ' percent')
    return b


B = load()


def names():
    """The channel's own name, every way it may be written (small letters): never spoken inside a story, never on a graphic."""
    return list(dict.fromkeys(x for x in (B['name'].lower(), B['spoken'].lower(), B['shown'].lower(), B['site'].lower(), B['spoken'].lower().replace(' ', '-')) if x))


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    print(json.dumps(load(sys.argv[1] if len(sys.argv) > 1 else None), ensure_ascii=False, indent=1))
