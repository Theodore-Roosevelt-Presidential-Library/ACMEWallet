#!/usr/bin/env python3
"""Builds wallet pass artwork from the Library's brand kit.

Usage:
    git clone https://github.com/Theodore-Roosevelt-Presidential-Library/Brand
    python3 -m venv .venv && source .venv/bin/activate && pip install pillow
    python3 scripts/build_pass_art.py path/to/Brand

Logos are scaled and placed on an approved background color; they are never
redrawn or rearranged. The brand kit supplies the horizontal logo in black
only, so this script makes the white colorway from it by changing the single
fill color and nothing else (approved by Marketing & Communications,
2026-11-14). Background colors come from the brand tokens.

Outputs:
    fivejars/trpl_wallet/pass_assets/<kind>/  icon and logo PNGs for Apple
    assets/img/google-logo-<kind>.png         square logo for Google (shown in a circle)
    assets/img/trpl-logo-horizontal-white.svg for the site header and preview
"""
import re
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


def white_horizontal(brand: Path) -> Image.Image:
    """The horizontal logo in white, trimmed to the visible artwork."""
    black = Image.open(brand / "logos/horizontal/trpl-logo-horizontal-black.png").convert("RGBA")
    alpha = black.getchannel("A")
    white = Image.new("RGBA", black.size, (255, 255, 255, 0))
    white.putalpha(alpha)
    return white.crop(alpha.getbbox())


def white_horizontal_svg(brand: Path) -> str:
    """Same paths as the supplied SVG; only the fill color changes."""
    svg = (brand / "logos/horizontal/trpl-logo-horizontal-black.svg").read_text()
    fills = set(re.findall(r'fill="([^"]+)"', svg))
    if len(fills) != 1:
        sys.exit(f"Expected one fill color in the horizontal logo, found {fills}")
    svg = svg.replace(f'fill="{fills.pop()}"', 'fill="#FFFFFF"')
    # Trim the empty margin so the logo aligns with text on a page.
    return re.sub(r'width="1656" height="504" viewBox="0 0 1656 504"',
                  'width="1470" height="300" viewBox="89 108.5 1470 300"', svg, count=1)


def main() -> None:
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    brand = Path(sys.argv[1]) / "assets"
    logo = white_horizontal(brand)
    mark = Image.open(brand / "marks/tr-brand/trpl-tr-brand-mark-white.png").convert("RGBA")
    (ROOT / "assets/img").mkdir(parents=True, exist_ok=True)

    for kind, color in BACKGROUNDS.items():
        out = ROOT / "fivejars/trpl_wallet/pass_assets" / kind
        out.mkdir(parents=True, exist_ok=True)
        for old in out.glob("*.png"):
            old.unlink()
        for scale, suffix in ((1, ""), (2, "@2x"), (3, "@3x")):
            # Apple icon: 29pt square. Shown in notifications and on the lock screen.
            on_background(mark, 29 * scale, color, 0.86).save(out / f"icon{suffix}.png")
            # Apple logo: top left of the pass, at most 160x50pt. The horizontal
            # logo fills the full width. Transparent, so it sits on the pass color.
            width = 160 * scale
            height = round(logo.height * width / logo.width)
            logo.resize((width, height), Image.LANCZOS).save(out / f"logo{suffix}.png")
        # Google crops the logo to a circle; keep the whole mark inside it.
        on_background(mark, 660, color, 0.64).save(ROOT / "assets/img" / f"google-logo-{kind}.png")

    (ROOT / "assets/img/trpl-logo-horizontal-white.svg").write_text(white_horizontal_svg(brand))
    print("Pass artwork rebuilt from", brand)


if __name__ == "__main__":
    main()
