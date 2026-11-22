"""Build the FRH client from its current release so other live features are retained."""
import sys
from pathlib import Path

def build(src, dst):
    script = Path(src).read_text(encoding="utf-8")
    anchor = "function watch() {"
    ending = "Explore the older YouTube videos ↗</a></p></section>';"
    if script.count(anchor) != 1 or script.count(ending) != 1 or "function loadStudioEpisodes(" in script:
        raise ValueError("Podcast player has changed; review the current application before applying.")
    player = Path(__file__).with_name("frh-feed-player.js").read_text(encoding="utf-8")
    script = script.replace(anchor, player + "\n\n" + anchor)
    script = script.replace(ending, ending + "\n  void loadStudioEpisodes(main.querySelector('.podcast-episodes'));")
    Path(dst).write_text(script, encoding="utf-8", newline="\n")

if __name__ == "__main__":
    build(*sys.argv[1:])
