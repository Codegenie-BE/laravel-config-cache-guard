from pathlib import Path

from PIL import Image, ImageDraw, ImageFont


ROOT = Path(__file__).resolve().parent


def font(size: int, bold: bool = False, mono: bool = False) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    candidates = []

    if mono:
        candidates.extend([
            Path("C:/Windows/Fonts/consola.ttf"),
            Path("/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf"),
        ])
    elif bold:
        candidates.extend([
            Path("C:/Windows/Fonts/segoeuib.ttf"),
            Path("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"),
        ])
    else:
        candidates.extend([
            Path("C:/Windows/Fonts/segoeui.ttf"),
            Path("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"),
        ])

    for candidate in candidates:
        if candidate.is_file():
            return ImageFont.truetype(str(candidate), size=size)

    return ImageFont.load_default()


def social_preview() -> None:
    image = Image.new("RGB", (1280, 640), "#0b1020")
    draw = ImageDraw.Draw(image)

    for radius in range(520, 40, -10):
        alpha = int(42 * (radius / 520) ** 2)
        overlay = Image.new("RGBA", image.size, (0, 0, 0, 0))
        glow = ImageDraw.Draw(overlay)
        glow.ellipse((930 - radius, 70 - radius, 930 + radius, 70 + radius), fill=(109, 40, 217, alpha))
        image.paste(overlay, (0, 0), overlay)

    draw = ImageDraw.Draw(image)
    draw.rounded_rectangle((90, 90, 168, 168), radius=18, fill="#6d28d9")
    for y, length in [(119, 38), (129, 38), (139, 24)]:
        draw.rounded_rectangle((110, y, 110 + length, y + 6), radius=3, fill="#ffffff")
    draw.line((141, 138, 149, 146, 164, 127), fill="#a7f3d0", width=7, joint="curve")

    draw.text((90, 218), "LARAVEL 12–13 · PHP 8.2–8.5", fill="#c4b5fd", font=font(28, bold=True))
    draw.text((90, 286), "Config Cache Guard", fill="#ffffff", font=font(68, bold=True))
    draw.text((90, 383), "Reject stale deployment cache before Laravel boots.", fill="#dbe4f2", font=font(32))
    draw.rounded_rectangle((90, 460, 1110, 546), radius=14, fill="#080c17", outline="#34405a", width=2)
    draw.text((118, 488), "$", fill="#a78bfa", font=font(26, mono=True))
    draw.text((154, 488), "composer require codegenie-be/laravel-config-cache-guard", fill="#f1f5f9", font=font(26, mono=True))
    image.save(ROOT / "social-preview.png", optimize=True)


def terminal_frame(lines: list[tuple[str, str]], progress: str, subtitle: str) -> Image.Image:
    image = Image.new("RGB", (1200, 675), "#080c17")
    draw = ImageDraw.Draw(image)
    draw.rounded_rectangle((24, 24, 1176, 651), radius=18, fill="#0b1020", outline="#303b55", width=2)
    draw.rounded_rectangle((24, 24, 1176, 76), radius=18, fill="#161e31")
    draw.rectangle((24, 58, 1176, 76), fill="#161e31")
    for x, color in [(54, "#fb7185"), (82, "#fbbf24"), (110, "#34d399")]:
        draw.ellipse((x - 7, 43, x + 7, 57), fill=color)
    draw.text((150, 37), "Laravel 13 · exec() disabled · verified E2E flow", fill="#bac7db", font=font(18, mono=True))
    draw.text((56, 103), subtitle, fill="#c4b5fd", font=font(23, bold=True))

    y = 152
    terminal_font = font(22, mono=True)
    for color, value in lines:
        draw.text((56, y), value, fill=color, font=terminal_font)
        y += 41

    draw.rounded_rectangle((56, 600, 1144, 615), radius=7, fill="#202a40")
    width = int(1088 * float(progress))
    draw.rounded_rectangle((56, 600, 56 + width, 615), radius=7, fill="#8b5cf6")
    return image


def demo_gif() -> None:
    green = "#86efac"
    white = "#edf2f7"
    muted = "#94a3b8"
    purple = "#c4b5fd"
    amber = "#fcd34d"
    red = "#fda4af"

    frames = [
        terminal_frame([
            (purple, "$ composer require codegenie-be/laravel-config-cache-guard"),
            (green, "✓ Installed through Composer autoload.files"),
            (muted, "  No public/index.php change required"),
        ], ".16", "1 · Install into a fresh Laravel application"),
        terminal_frame([
            (purple, "$ php artisan config:cache && php artisan route:cache"),
            (green, "✓ Baseline config and route cache created"),
            (muted, "  config: initial-config"),
            (muted, "  route:  initial-route"),
        ], ".32", "2 · Create deployment cache"),
        terminal_frame([
            (purple, "$ deploy changed config, routes and dependency metadata"),
            (amber, "! Cached source signatures are now stale"),
            (muted, "  Host has no SSH and exec() is disabled"),
        ], ".48", "3 · A later deployment changes source files"),
        terminal_frame([
            (purple, "$ curl http://127.0.0.1:8000/e2e"),
            (red, "× Known-stale cache rejected before Laravel boots"),
            (green, "✓ config: deferred-refreshed-config-value"),
            (green, "✓ route:  deferred-refreshed-route"),
            (amber, "→ Safe internal repair scheduled after response"),
        ], ".66", "4 · First real HTTP request"),
        terminal_frame([
            (muted, "response sent"),
            (purple, "Artisan::call('config:cache')"),
            (purple, "Artisan::call('route:cache')"),
            (green, "✓ Current signatures and success markers written"),
            (muted, "  No public repair route, queue, cron or token"),
        ], ".84", "5 · Deferred repair completes internally"),
        terminal_frame([
            (purple, "$ curl http://127.0.0.1:8000/e2e"),
            (green, "✓ config: current and cached"),
            (green, "✓ routes: current and cached"),
            (green, "✓ pending markers cleared"),
            (white, "Result: Laravel never consumed known-stale cache."),
        ], "1", "6 · Following request uses rebuilt cache"),
    ]

    frames[-1].save(ROOT / "demo-static.png", optimize=True)
    frames[0].save(
        ROOT / "demo.gif",
        save_all=True,
        append_images=frames[1:],
        duration=[1900, 1900, 1900, 2400, 2200, 2600],
        loop=0,
        optimize=True,
        disposal=2,
    )


if __name__ == "__main__":
    social_preview()
    demo_gif()
