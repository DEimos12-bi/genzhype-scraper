"""REPAIR, after the hunts: a sentence written for footage that was not found (or that the eyes refused) loses its
place, the way a person rewrites a line when the clip is not there, instead of being covered by a picture of
something else. Runs before the voice, so the timing is of the final script.  usage: repair.py <work>"""
import sys

import director

if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    import localenv
    localenv.load()
    director.repair(sys.argv[1])
