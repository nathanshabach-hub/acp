#!/usr/bin/env python3
"""Rebuild the checked-in guideline excerpts from the supplied local PDFs."""

import json
import re
import unicodedata
from pathlib import Path

import fitz


ROOT = Path(__file__).resolve().parents[1]
PDF_ROOT = ROOT / "webroot/files/guidelines"
SECTIONS = {}
EVENTS = {}
DOCUMENTS = {}


def normalize(text):
    # These PDFs encode several ligatures as unrelated glyphs or control codes.
    for glyph, replacement in {
        "Ɵ": "ti", "Ʃ": "tt", "ƫ": "tti", "Ō": "ft", "ƞ": "nt",
        "\x11": "ti", "\x19": "ti", "\x1e": "ti",
        "ﬀ": "ff", "ﬁ": "fi", "ﬂ": "fl", "ﬃ": "ffi", "ﬄ": "ffl",
    }.items():
        text = re.sub(re.escape(glyph) + r" *", replacement, text)
    text = re.sub(r"(?<=[A-Za-z])[18J%;](?=[a-z])", "tt", text)
    text = re.sub(r"(?<=[A-Za-z])3(?=[a-z])", "ti", text)
    return unicodedata.normalize("NFC", text)


def section(key, pdf, pages, title, start=None, end=None):
    if pdf not in DOCUMENTS:
        DOCUMENTS[pdf] = fitz.open(PDF_ROOT / pdf)
    document = DOCUMENTS[pdf]
    parts = []
    for page in pages:
        text = normalize("\n".join(
            block[4] for block in document[page - 1].get_text("blocks", sort=True)
            if block[6] == 0
        ))
        if pdf == "09-Music-Instrumental-2026-Lou-edit.pdf" and page == 6:
            # The source places the checklist's final line above its heading.
            final_line = "ADJUDICATION PURPOSES ONLY” stamped or clearly written on the back."
            text = text.replace(final_line, "")
            text = text.replace(
                "The copy should have the words “FOR",
                "The copy should have the words “FOR\n" + final_line,
            )
        lines = [
            line.rstrip() for line in text.splitlines()
            if not re.fullmatch(r"\s*(?:[A-Z]{2}\.\d+|20\d\d-20\d\d)\s*", line)
        ]
        parts.append("\n".join(lines))
    text = "\n\n".join(parts)
    def heading_pattern(heading):
        return r"(?m)^[ \t]*" + r"[ \t]+".join(map(re.escape, heading.split())) + r"[ \t]*$"

    if start:
        match = re.search(heading_pattern(start), text)
        if not match:
            raise ValueError(f"{key}: start heading not found: {start}")
        text = text[match.start():]
    if end:
        match = re.search(heading_pattern(end), text)
        if not match:
            raise ValueError(f"{key}: end heading not found: {end}")
        text = text[:match.start()]
    text = re.sub(r"\n{3,}", "\n\n", text).strip()
    if len(text) < 40 or "\ufffd" in text or re.search(r"[\x00-\x08\x0b-\x1f]", text):
        raise ValueError(f"{key}: empty or unreadable guideline text")
    SECTIONS[key] = {"title": title, "pdf": pdf, "pages": pages, "text": text}
    return key


def assign(numbers, *sections):
    for number in numbers.split(","):
        number = str(int(number))
        if number in EVENTS:
            raise ValueError(f"Duplicate event mapping: {number}")
        EVENTS[number] = list(sections)


academic = "02-Academics-2024.pdf"
academic_general = section("academic-general", academic, [1], "Academic general guidelines")
for key, numbers, pages, title in [
    ("checkers", "001,051", [2], "Checkers"),
    ("chess", "002,052", [3], "Chess"),
    ("spelling", "003,053", list(range(4, 11)), "Spelling rules and word lists"),
    ("pace-bowl", "054", [11], "PACE Bowl"),
    ("science", "005,055,056,057,058", [12, 13], "Science Exhibit"),
    ("social-studies", "009,059,060", [17], "Social Studies Exhibit"),
    ("persuasive-essay", "061", [21], "Persuasive Essay Writing"),
    ("short-story", "013,063", [23], "Short Story Writing"),
    ("poetry-writing", "012,062", [25], "Poetry Writing"),
    ("book-report", "014,064", [27], "Book Report"),
]:
    assign(numbers, section(key, academic, pages, title), academic_general)

manual = "05-Manual-Arts-2024.pdf"
manual_general = section("manual-general", manual, [1, 2], "Manual Arts general guidelines and checklist")
for key, numbers, page, heading, end in [
    ("wood-turning", "300,350", 5, "WOOD TURNING", "WOOD CONSTRUCTION"),
    ("wood-construction", "301,351", 5, "WOOD CONSTRUCTION", "WOOD CARVING"),
    ("wood-carving", "302,352", 5, "WOOD CARVING", "MARQUETRY"),
    ("marquetry", "303,353", 5, "MARQUETRY", None),
    ("metal-sculpture", "304,354", 10, "METAL SCULPTURE", "METAL FABRICATION"),
    ("metal-fabrication", "306,356", 10, "METAL FABRICATION", "SHEET METALWORK"),
    ("sheet-metalwork", "307,357", 10, "SHEET METALWORK", None),
    ("handbuilt-clay", "308,358", 14, "HAND BUILT CLAY WORK", "WHEEL THROWN CLAY WORK"),
    ("wheel-clay", "309,359", 14, "WHEEL THROWN CLAY WORK", "SCRAPBOOKING"),
    ("scrapbooking", "310,360", 14, "SCRAPBOOKING", "FLAX CONSTRUCTION"),
    ("flax", "311,361", 14, "FLAX CONSTRUCTION", "BILUM MAKING"),
    ("bilum", "312,362", 14, "BILUM MAKING", None),
]:
    assign(numbers, section(key, manual, [page], heading.title(), heading, end), manual_general)

needle_general = section("needle-general", manual, [19], "Needle and Thread general guidelines",
                         "NEEDLE AND THREAD", "GARMENTS")
needle_checklist = section("needle-checklist", manual, [20], "Needle and Thread checklist",
                           "CHECKLIST FOR Needle & Thread:")
for key, numbers, page, heading, end in [
    ("garments", "313,314,315,316,318,363,364,365,366,368", 19, "GARMENTS", "COUNTED CROSS STITCH"),
    ("cross-stitch", "320,370", 19, "COUNTED CROSS STITCH", "EMBROIDERY"),
    ("embroidery", "321,371", 19, "EMBROIDERY", "NEEDLEPOINT"),
    ("needlepoint", "322,372", 19, "NEEDLEPOINT", None),
    ("crochet", "323,373", 20, "CROCHET", "KNITTING"),
    ("knitting", "324,374", 20, "KNITTING", "QUILTS"),
    ("quilts", "325,375", 20, "QUILTS", "PATCHWORK"),
    ("patchwork", "326,376", 20, "PATCHWORK", "CHECKLIST FOR Needle & Thread:"),
]:
    assign(numbers, section(key, manual, [page], heading.title(), heading, end),
           needle_general, needle_checklist, manual_general)

media = "06-Media-Arts-2024-lou-edit.pdf"
media_general = section("media-general", media, [1, 2, 3], "Media Arts general guidelines")
for key, numbers, pages, title in [
    ("photography", "400,401,402,403,404,405,406,450,451,452,453,454,455,456", [5, 6, 7, 8], "Photography"),
    ("composite-photo", "407,457", [5, 6, 7, 8], "Composite Photography"),
    ("digital-art", "411,461", [12, 13], "Digital Art"),
    ("posters", "412,462", [15, 16], "Posters"),
    ("graphic-design", "413,463", [18, 19], "Graphic Design"),
    ("powerpoint", "414,415,464,465", [22, 23], "PowerPoint Presentation"),
    ("website", "466", [25], "Web Site Development"),
    ("radio-play", "467", [27], "Radio Play"),
    ("video", "468", [29], "Video Production"),
]:
    assign(numbers, section(key, media, pages, title), media_general)

visual = "07-Visual-Arts-2024.pdf"
visual_general = section("visual-general", visual, [1, 2], "Visual Arts general guidelines and checklist")
for key, numbers, pages, heading, end in [
    ("oil", "500,550", [5], "OIL", "WATERCOLOUR"),
    ("watercolour", "501,551", [5], "WATERCOLOUR", "ACRYLICS"),
    ("acrylics", "502,552", [5], "ACRYLICS", "SKETCHING"),
    ("sketching", "503,553", [5, 6], "SKETCHING", "PASTELS"),
    ("pastels", "504,554", [6], "PASTELS", "COLOURED PENCILS"),
    ("pencils", "505,555", [6], "COLOURED PENCILS", "PEN & INK"),
    ("pen-ink", "506,556", [6], "PEN & INK", None),
    ("prints", "507,557", [8], "PRINTS", "MIXED MEDIA"),
    ("mixed-media", "508,558", [8], "MIXED MEDIA", None),
    ("comic", "509,559", [11], "COMIC STRIP", "GEOMETRIC ABSTRACT ART"),
    ("geometric", "510,560", [11], "GEOMETRIC ABSTRACT ART", None),
]:
    assign(numbers, section(key, visual, pages, heading.title(), heading, end), visual_general)

combined = "08-Music-Combined-2024.pdf"
combined_general = section("combined-general", combined, [1, 2, 3], "Music Combined general guidelines")
combined_checklist = section("combined-checklist", combined, [5], "Music Combined checklist",
                             "CHECKLIST FOR MUSIC COMBINED EVENTS:")
for key, numbers, pages, heading, end in [
    ("self-solo", "600,650", [4], "Self-Accompanied Solo (1 contestant)", "Self-Accompanied Duet (2 contestants)"),
    ("self-duet", "601,651", [4], "Self-Accompanied Duet (2 contestants)", "Self-Accompanied Trio (3 contestants)"),
    ("self-trio", "602,652", [4], "Self-Accompanied Trio (3 contestants)", "Self-Accompanied Quartet (4 contestants)"),
    ("self-quartet", "603,653", [4], "Self-Accompanied Quartet (4 contestants)", "Combined Ensemble (5 minimum - 15 maximum)"),
    ("combined-ensemble", "654,656", [4, 5], "Combined Ensemble (5 minimum - 15 maximum)", "CHECKLIST FOR MUSIC COMBINED EVENTS:"),
]:
    assign(numbers, section(key, combined, pages, heading, heading, end),
           combined_general, combined_checklist)

instrumental = "09-Music-Instrumental-2026-Lou-edit.pdf"
instrumental_general = section("instrumental-general", instrumental, [1, 2, 3, 4, 5],
                               "Music Instrumental general guidelines (2025-2026)", end="SOLO (1 CONTESTANT)")
instrumental_checklist = section("instrumental-checklist", instrumental, [6],
                                 "Music Instrumental checklist", "CHECKLIST FOR MUSIC  INSTRUMENTAL EVENTS:")
SECTIONS[instrumental_general]["notice"] = (
    "The supplied Instrumental handbook has conflicting accompaniment instructions for Instrumental "
    "Ensembles: rule 29 prohibits accompaniment, while the page 5 table permits it. "
    "Please confirm the applicable rule with the Events Team. The source wording is retained below."
)
for key, numbers, pages, heading, end in [
    ("instrumental-solo", "700,702,703,704,705,706,707,750,752,753,754,755,756,757",
     [5], "SOLO (1 CONTESTANT)", None),
    ("instrumental-group", "708,709,710,711,712,713,715,758,759,760,761,762,763,764,765,771,773",
     [6], "DUET, TRIO, AND QUARTET", "ENSEMBLES (INSTRUMENTAL, TAMBOURINE, AND HANDBELL)"),
    ("instrumental-ensemble", "716,777,779,780,781,782",
     [6], "ENSEMBLES (INSTRUMENTAL, TAMBOURINE, AND HANDBELL)", "CHECKLIST FOR MUSIC  INSTRUMENTAL EVENTS:"),
]:
    assign(numbers, section(key, instrumental, pages, heading.title(), heading, end),
           instrumental_general, instrumental_checklist)
assign("717,767", section("instrumental-composition", instrumental, [16], "Music Instrumental Composition"),
       instrumental_general)
assign("718,768,776", section("instrumental-arranging", instrumental, [18], "Music Instrumental Arranging"),
       instrumental_general)

vocal = "10-Music-Vocal-2024.pdf"
vocal_general = section("vocal-general", vocal, [1, 2, 3, 4], "Music Vocal general guidelines", end="SECTIONAL DETAILS")
vocal_checklist = section("vocal-checklist", vocal, [5], "Music Vocal checklist", "CHECKLIST FOR MUSIC VOCAL EVENTS:")
for key, numbers, pages, heading, end in [
    ("vocal-solo", "800,805,850,855", [4], "Solo (1 contestant)", "Duet (2 contestants)"),
    ("vocal-group", "801,802,803,804,806,807,808,809,810,811,812,851,852,853,854,856,857,858,859,860,861,862",
     [4], "Duet (2 contestants)", "Ensemble (5 minimum - 15 maximum)"),
    ("vocal-ensemble", "870", [4], "Ensemble (5 minimum - 15 maximum)", "Choir (16 minimum - 40 maximum)"),
    ("choir", "871", [4, 5], "Choir (16 minimum - 40 maximum)", "CHECKLIST FOR MUSIC VOCAL EVENTS:"),
]:
    assign(numbers, section(key, vocal, pages, heading, heading, end), vocal_general, vocal_checklist)
assign("814,864", section("vocal-composition", vocal, [9], "Music Vocal Composition"), vocal_general)
assign("815,865", section("vocal-arranging", vocal, [11], "Music Vocal Arranging"), vocal_general)

platform = "11-Platform-2024.pdf"
platform_general = section("platform-general", platform, [1, 2, 3], "Platform general guidelines and checklist")
for key, numbers, page, title in [
    ("puppets", "900,950", 4, "Puppets / Marionettes"),
    ("dialogue", "901,951", 6, "Dramatic Dialogue"),
    ("mime", "902,903,952,953", 8, "Mime"),
    ("one-act", "954", 10, "One-Act Play"),
    ("oratory", "906,920,956,970", 12, "Oratory"),
    ("monologue", "907,921,957,971", 14, "Dramatic Monologue"),
    ("reading", "908,922,958,972", 16, "Expressive Reading"),
    ("narrative", "909,923,959,973", 18, "Dramatic Narrative"),
    ("recitation", "910,924,960,974", 20, "Poetry Recitation"),
    ("storytelling", "911,925,961,975", 22, "Illustrated Storytelling"),
    ("ventriloquism", "912,926,962,976", 24, "Ventriloquism"),
    ("clown", "913,927,963,977", 26, "Clown Act"),
    ("speech", "914,928,964,978", 28, "Famous Speech"),
    ("preaching", "915,929,965,979", 31, "Preaching"),
]:
    assign(numbers, section(key, platform, [page], title), platform_general)

scripture = "12-Scripture-2024.pdf"
scripture_general = section("scripture-general", scripture, [1], "Scripture general guidelines")
assign("1057", section("bible-bowl", scripture, [8], "Bible Bowl"), scripture_general)
assign("1058", section("bible-speaking", scripture, [9], "Group Bible Speaking"), scripture_general)

online_notice = (
    "The supplied handbook has no separate rules for this legacy Online event. "
    "Related event guidelines are shown below; please confirm Online-specific requirements with the Events Team."
)
output = {
    "events": dict(sorted(EVENTS.items(), key=lambda item: int(item[0]))),
    "sections": SECTIONS,
    "event_notices": {str(n): online_notice for n in [654, 716, 764, 804, 809, 854, 859]},
}
(ROOT / "config/event_guidelines.json").write_text(
    json.dumps(output, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
)
print(f"Built {len(SECTIONS)} guideline sections for {len(EVENTS)} event numbers.")
