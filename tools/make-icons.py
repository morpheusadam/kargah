"""
Generate every icon the app serves from the one source mark.

    python tools/make-icons.py

Source : public/img/kargah-logo.webp
Writes : public/favicon.ico
         public/img/icons/*.png
         public/img/og-image.png
         public/site.webmanifest

Re-runnable. Everything it writes is derived, so deleting the output and
running it again produces the same result.
"""

from __future__ import annotations

import json
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "public" / "img" / "kargah-logo.webp"
ICONS = ROOT / "public" / "img" / "icons"

# The app is dark first; icons that sit on a coloured tile use this.
BRAND_BG = (13, 17, 28, 255)

PNG_SIZES = [16, 32, 48, 64, 96, 128, 180, 192, 256, 384, 512]
ICO_SIZES = [16, 24, 32, 48, 64]


def load_source() -> Image.Image:
    if not SOURCE.exists():
        raise SystemExit(f"source mark missing: {SOURCE}")

    img = Image.open(SOURCE).convert("RGBA")
    return trim_transparent(img)


def trim_transparent(img: Image.Image) -> Image.Image:
    """Crop to the artwork. Padding baked into the source wastes pixels at 16px."""
    bbox = img.getbbox()
    return img.crop(bbox) if bbox else img


def square(img: Image.Image, size: int, pad_ratio: float = 0.08,
           background: tuple[int, int, int, int] | None = None) -> Image.Image:
    """Fit the mark inside a square canvas without distorting it."""
    canvas = Image.new("RGBA", (size, size), background or (0, 0, 0, 0))

    inner = int(size * (1 - pad_ratio * 2))
    scaled = img.copy()
    scaled.thumbnail((inner, inner), Image.LANCZOS)

    canvas.paste(
        scaled,
        ((size - scaled.width) // 2, (size - scaled.height) // 2),
        scaled,
    )
    return canvas


def write_pngs(mark: Image.Image) -> None:
    ICONS.mkdir(parents=True, exist_ok=True)

    for size in PNG_SIZES:
        # Small sizes get less padding, or the bird disappears.
        pad = 0.02 if size <= 32 else 0.06
        square(mark, size, pad).save(ICONS / f"icon-{size}.png", optimize=True)

    # Apple wants an opaque tile — iOS composites onto white otherwise.
    square(mark, 180, 0.12, BRAND_BG).save(ICONS / "apple-touch-icon.png", optimize=True)

    # Maskable icons must survive a circular crop, so keep well inside the safe zone.
    for size in (192, 512):
        square(mark, size, 0.20, BRAND_BG).save(ICONS / f"maskable-{size}.png", optimize=True)


def write_ico(mark: Image.Image) -> None:
    base = square(mark, 256, 0.02)
    base.save(ROOT / "public" / "favicon.ico", sizes=[(s, s) for s in ICO_SIZES])


def write_og_image(mark: Image.Image) -> None:
    """The card shown when a link to the app is pasted somewhere."""
    w, h = 1200, 630
    card = Image.new("RGBA", (w, h), BRAND_BG)
    draw = ImageDraw.Draw(card)

    # A soft halo behind the mark, echoing the login screen.
    for radius, alpha in ((420, 16), (320, 22), (230, 30)):
        draw.ellipse(
            [w // 2 - radius, h // 2 - radius - 40, w // 2 + radius, h // 2 + radius - 40],
            fill=(59, 130, 246, alpha),
        )

    logo = mark.copy()
    logo.thumbnail((300, 300), Image.LANCZOS)
    card.paste(logo, ((w - logo.width) // 2, 150 - logo.height // 2 + 60), logo)

    try:
        title_font = ImageFont.truetype("segoeuib.ttf", 64)
        sub_font = ImageFont.truetype("segoeui.ttf", 28)
    except OSError:
        title_font = ImageFont.load_default()
        sub_font = ImageFont.load_default()

    title = "Kargah"
    sub = "Inbox, boards, invoices and a vault — self-hosted."

    tw = draw.textlength(title, font=title_font)
    draw.text(((w - tw) / 2, 400), title, font=title_font, fill=(244, 244, 245, 255))

    sw = draw.textlength(sub, font=sub_font)
    draw.text(((w - sw) / 2, 486), sub, font=sub_font, fill=(161, 161, 170, 255))

    card.convert("RGB").save(ROOT / "public" / "img" / "og-image.png", optimize=True)


def write_manifest() -> None:
    manifest = {
        "name": "Kargah",
        "short_name": "Kargah",
        "description": "Self-hosted freelance workspace: inbox, boards, invoices and a vault.",
        "start_url": "/dashboard",
        "scope": "/",
        "display": "standalone",
        "background_color": "#0d111c",
        "theme_color": "#0d111c",
        "icons": [
            {"src": "/img/icons/icon-192.png", "sizes": "192x192", "type": "image/png"},
            {"src": "/img/icons/icon-512.png", "sizes": "512x512", "type": "image/png"},
            {"src": "/img/icons/maskable-192.png", "sizes": "192x192", "type": "image/png", "purpose": "maskable"},
            {"src": "/img/icons/maskable-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable"},
        ],
    }

    (ROOT / "public" / "site.webmanifest").write_text(
        json.dumps(manifest, indent=4) + "\n", encoding="utf-8"
    )


def main() -> None:
    mark = load_source()
    print(f"source: {SOURCE.name}  {mark.width}x{mark.height} after trim")

    write_pngs(mark)
    write_ico(mark)
    write_og_image(mark)
    write_manifest()

    produced = sorted(ICONS.glob("*.png"))
    print(f"wrote {len(produced)} pngs, favicon.ico, og-image.png, site.webmanifest")


if __name__ == "__main__":
    main()
