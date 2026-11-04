#!/usr/bin/env python3
"""Offline integrity and schema audit for the bundled v157 import sources."""
from __future__ import annotations

import hashlib
import json
import sys
import zipfile
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SEED = ROOT / "seed"
TEMPLATES = ROOT / "templates"
EXPECTED = {"dispozitii.txt": (189, 11), "hotarari.txt": (362, 28)}
NS = {"w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main"}

def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()

def check(condition: bool, message: str, failures: list[str]) -> None:
    if not condition:
        failures.append(message)

def main() -> int:
    failures: list[str] = []
    totals: dict[str, int] = {}
    hashes: dict[str, str] = {}
    for filename, (expected_rows, expected_fields) in EXPECTED.items():
        path = SEED / "v157" / filename
        check(path.is_file(), f"Missing {path.relative_to(ROOT)}", failures)
        if not path.is_file():
            continue
        data = path.read_bytes()
        hashes[filename] = sha256(path)
        text = data.decode("utf-8-sig")
        lines = text.splitlines()
        check(len(lines) == expected_rows, f"{filename}: expected {expected_rows} rows, got {len(lines)}", failures)
        counts: set[int] = set()
        for line_number, line in enumerate(lines, 1):
            columns = line.split("#")
            if not (len(columns) == expected_fields + 2 and columns[0] == "" and columns[-1] == ""):
                failures.append(f"{filename}:{line_number}: expected {expected_fields} fields between # delimiters")
                break
            counts.add(len(columns) - 2)
        check(counts == {expected_fields}, f"{filename}: inconsistent field count {counts}", failures)
        totals[filename] = len(lines)
    settings_path = SEED / "settings.json"
    check(settings_path.is_file(), "Missing core seed settings", failures)
    if settings_path.is_file():
        settings = json.loads(settings_path.read_text(encoding="utf-8"))
        check(settings.get("institution", {}).get("location") == "Beba Veche", "Settings location did not survive extraction", failures)
        check(len(settings.get("departments", [])) >= 8, "Expected department mappings from v157 settings", failures)
        check(len(settings.get("initiators", [])) >= 3, "Expected initiator mappings from v157 settings", failures)
        check(len(settings.get("commissions", [])) >= 4, "Expected commission mappings from v157 settings", failures)
        check("email" not in json.dumps(settings, ensure_ascii=False).lower(), "Unexpected mail address data in sanitized settings", failures)
        hashes["settings.json"] = sha256(settings_path)

    config_path = SEED / "config.json"
    check(config_path.is_file(), "Missing v157 config.ini extraction", failures)
    if config_path.is_file():
        config = json.loads(config_path.read_text(encoding="utf-8"))
        check(set(config.get("general", {})) == {"warn", "depanare"}, "config.ini general settings were not retained", failures)
        check(set(config.get("combo", {})) == {"init", "compartiment", "raportor", "comisie"}, "config.ini combo lists are incomplete", failures)
        check(config.get("text", {}).get("loc") == "Beba Veche", "config.ini text values were not retained", failures)
        check(len(config.get("array", {}).get("consilieri", "").split(";")) == 7, "config.ini councilor list mismatch", failures)
        check(len(config.get("array", {}).get("email", "").split(";")) == 7, "config.ini email list mismatch", failures)
        check(len(config.get("comisii", {})) == 4, "config.ini commission membership groups mismatch", failures)
        check(sum(len(members) for members in config.get("comisii", {}).values()) == 12, "config.ini commission membership totals mismatch", failures)
        hashes["config.json"] = sha256(config_path)

    manifest_path = SEED / "manifest.json"
    check(manifest_path.is_file(), "Missing extraction manifest", failures)
    if manifest_path.is_file():
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        check(manifest.get("source_archive") == "v157.zip", "Manifest source archive mismatch", failures)
        check(manifest.get("dataset_counts", {}).get("roDisp") == {"records": 189, "fields_per_record": 11}, "Manifest roDisp counts mismatch", failures)
        check(manifest.get("dataset_counts", {}).get("roHot") == {"records": 362, "fields_per_record": 28}, "Manifest roHot counts mismatch", failures)
        for entry in manifest.get("extracted_files", []):
            path = ROOT / entry["path"]
            check(path.is_file(), f"Missing manifest file {entry['path']}", failures)
            if path.is_file():
                check(sha256(path) == entry["sha256"], f"SHA-256 mismatch for {entry['path']}", failures)
                check(path.stat().st_size == entry["bytes"], f"Size mismatch for {entry['path']}", failures)

    templates = sorted(TEMPLATES.glob("*.docx"))
    expected_templates = {
        "adresa_hot.docx", "anunt_hot.docx", "conv.docx", "dispoAdr.docx", "dispoI.docx",
        "dispoI_pr.docx", "dispoN.docx", "dispoN_pr.docx", "dispoRef.docx", "hotI.docx",
        "hotI_pr.docx", "hotN.docx", "hotN_pr.docx", "hotRef.docx",
    }
    names = {p.name for p in templates}
    check(names == expected_templates, f"Template set mismatch: {sorted(names ^ expected_templates)}", failures)
    rodisp_tags = set("nr data titlu legislatie articole articole_GRPD tip nrpr compartiment numecomp nrdataref referat adresaPJT anexe loc primar secretar".split())
    rohot_tags = set("data ora tipsed nrpr datapr titlu init numeinit legislatie articole tip grpd raportor numerap nrrap compartiment numecomp nrdataref referat anexe comisie prescom majoritate nrdisp convoc nr pres sitvot adresaPJT loc primar secretar emol nrdatarap".split()) | {f"v{i}" for i in range(1, 10)}
    for path in templates:
        try:
            with zipfile.ZipFile(path) as archive:
                xml = archive.read("word/document.xml")
            root = ET.fromstring(xml)
            tags = root.findall(".//w:sdtPr/w:tag", NS)
            check(bool(tags), f"{path.name}: no tagged content controls", failures)
            values = {tag.attrib.get(f"{{{NS['w']}}}val", "") for tag in tags}
            allowed = rodisp_tags if path.name.startswith("dispo") else rohot_tags
            unmapped = values - allowed - {"label"}
            check(not unmapped, f"{path.name}: unmapped Word tags {sorted(unmapped)}", failures)
        except (zipfile.BadZipFile, KeyError, ET.ParseError) as exc:
            failures.append(f"{path.name}: invalid DOCX structure: {exc}")

    if failures:
        print("Seed verification FAILED")
        for failure in failures:
            print(" -", failure)
        return 1
    print("Seed verification passed")
    print(f" - Import records: {totals['dispozitii.txt']} roDisp × 11 fields; {totals['hotarari.txt']} roHot × 28 fields")
    print(f" - Original DOCX templates: {len(templates)}; valid OOXML, tagged controls, and no unmapped tag names")
    print(" - config.ini extraction: general/text/array/combo values and 4 commission rosters verified")
    for filename, digest in hashes.items():
        print(f" - sha256 {filename}: {digest}")
    return 0

if __name__ == "__main__":
    sys.exit(main())
