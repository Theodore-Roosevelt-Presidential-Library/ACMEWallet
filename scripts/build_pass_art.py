#!/usr/bin/env python3
"""Builds wallet pass artwork from the Library's brand kit.

Usage:
    git clone https://github.com/Theodore-Roosevelt-Presidential-Library/Brand
    python3 -m venv .venv && source .venv/bin/activate && pip install pillow
    python3 scripts/build_pass_art.py path/to/Brand

Logos are only scaled and placed on an approved background color. Nothing is
recolored, redrawn or rearranged; the white colorway files are used as
supplied. Background colors come from the brand tokens.

Outputs:
    fivejars/trpl_wallet/pass_assets/<kind>/  icon and logo PNGs for Apple
    assets/img/google-logo-<kind>.png         square logo for Google (shown in a circle)
    assets/img/trpl-logo-stacked-white.svg    for the site header
"""
import shutil
import sys
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
BACKGROUNDS = {
    "ticket": (9, 42, 77),        # Night Sky #092A4D
    "membership": (27, 69, 50),   # Dark Forest #1B4532
}


def on_background(mark: Image.Image, size: int, color, fill: float) -> Image.Image:
    """Centers the mark on a solid square, scaled to `fill` of its width."""
    canvas = Image.new("RGBA", (size, size), color + (255,))
    inner = max(1, round(size * fill))
    scaled = mark.resize((inner, inner), Image.LANCZOS)
    offset = (size - inner) // 2
    canvas.alpha_composite(scaled, (offset, offset))
    return canvas.convert("RGB")


def main() -> None:
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    brand = Path(sys.argv[1]) / "assets"
    logo = Image.open(brand / "logos/stacked/trpl-logo-stacked-white.png").convert("RGBA")
    mark = Image.open(brand / "marks/tr-brand/trpl-tr-brand-mark-white.png").convert("RGBA")

    for kind, color in BACKGROUNDS.items():
        out = ROOT / "fivejars/trpl_wallet/pass_assets" / kind
        out.mkdir(parents=True, exist_ok=True)
        for old in out.glob("*.png"):
            old.unlink()
        # Apple icon: 29pt square. Shown in notifications and on the lock screen.
        for scale, suffix in ((1, ""), (2, "@2x"), (3, "@3x")):
            on_background(mark, 29 * scale, color, 0.86).save(out / f"icon{suffix}.png")
            # Apple logo: top left of the pass, at most 160x50pt. Transparent,
            # so it sits on the pass background color.
            height = 50 * scale
            width = round(logo.width * height / logo.height)
            logo.resize((width, height), Image.LANCZOS).save(out / f"logo{suffix}.png")
        # Google crops the logo to a circle; keep the whole mark inside it.
        (ROOT / "assets/img").mkdir(parents=True, exist_ok=True)
        on_background(mark, 660, color, 0.64).save(ROOT / "assets/img" / f"google-logo-{kind}.png")

    shutil.copy(brand / "logos/stacked/trpl-logo-stacked-white.svg", ROOT / "assets/img/trpl-logo-stacked-white.svg")
    print("Pass artwork rebuilt from", brand)


if __name__ == "__main__":
    main()
