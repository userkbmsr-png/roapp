from __future__ import annotations

import hashlib
import http.cookiejar
import os
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BINARY", "php")
TEST_PASSWORD = "roapp-test-" + os.urandom(16).hex()


def lan_ipv4() -> str:
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        sock.connect(("192.0.2.1", 9))  # route selection only; no packet is sent
        address = sock.getsockname()[0]
        if address and not address.startswith("127."):
            return address
    finally:
        sock.close()
    for item in socket.getaddrinfo(socket.gethostname(), None, socket.AF_INET):
        address = item[4][0]
        if address and not address.startswith("127."):
            return address
    raise RuntimeError("Nu există o adresă IPv4 de rețea disponibilă pentru testul HTTP.")


class FormParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.values: dict[str, str] = {}
        self.current_textarea: str | None = None
        self.text_parts: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = dict(attrs)
        name = values.get("name")
        if tag == "input" and isinstance(name, str) and (name == "csrf" or name.startswith("values[")):
            self.values[name] = values.get("value") or ""
        elif tag == "textarea" and isinstance(name, str) and name.startswith("values["):
            self.current_textarea = name
            self.text_parts = []

    def handle_data(self, data: str) -> None:
        if self.current_textarea is not None:
            self.text_parts.append(data)

    def handle_endtag(self, tag: str) -> None:
        if tag == "textarea" and self.current_textarea is not None:
            self.values[self.current_textarea] = "".join(self.text_parts)
            self.current_textarea = None
            self.text_parts = []


def opener_for() -> urllib.request.OpenerDirector:
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def request(opener: urllib.request.OpenerDirector, url: str, method: str = "GET", data: bytes | None = None, headers: dict[str, str] | None = None) -> tuple[int, str, dict[str, str]]:
    req = urllib.request.Request(url, data=data, method=method, headers=headers or {})
    try:
        with opener.open(req, timeout=5) as response:
            return response.status, response.read().decode("utf-8", "replace"), dict(response.headers.items())
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode("utf-8", "replace"), dict(error.headers.items())


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def row_for_id(html: str, record_id: str) -> str:
    for row in re.findall(r"<tr>(.*?)</tr>", html, flags=re.DOTALL):
        if f'<small class="record-id">{record_id}</small>' in row:
            return row
    raise AssertionError(f"Rândul pentru {record_id} lipsește din registru.")


def check(condition: bool, description: str) -> None:
    if not condition:
        raise AssertionError(description)


def main() -> int:
    address = lan_ipv4()
    with tempfile.TemporaryDirectory(prefix="roapp-readonly-") as root_dir:
        app_root = Path(root_dir) / "roapp"
        app_root.mkdir()
        for folder in ("public", "src", "seed", "templates"):
            shutil.copytree(ROOT / folder, app_root / folder)
        (app_root / "tests").mkdir()
        shutil.copy2(ROOT / "tests" / "prepare_readonly_fixture.php", app_root / "tests" / "prepare_readonly_fixture.php")
        shutil.copy2(ROOT / ".htaccess", app_root / ".htaccess")
        shutil.copy2(ROOT / ".env.example", app_root / ".env.example")
        env_file = app_root / ".env"
        env_file.write_text(f"ROAPP_PASSWORD={TEST_PASSWORD}\n", encoding="utf-8")
        check(env_file.parent == app_root and not (app_root / "public" / ".env").exists(), ".env trebuie să fie în rădăcina aplicației, separat de public/.")
        check("Require all denied" in (app_root / ".htaccess").read_text(encoding="utf-8"), "Regula Apache pentru protejarea fișierelor .env lipsește.")

        with tempfile.TemporaryDirectory(prefix="roapp-readonly-data-") as data_dir:
            env = os.environ.copy()
            env["ROAPP_DATA_DIR"] = data_dir
            env.pop("ROAPP_PASSWORD", None)
            prepared = subprocess.run([PHP, str(app_root / "tests" / "prepare_readonly_fixture.php")], cwd=app_root, env=env, capture_output=True, text=True)
            check(prepared.returncode == 0, "Pregătirea bazei temporare a eșuat: " + prepared.stderr)
            database = Path(data_dir) / "database.json"

            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe:
                probe.bind(("127.0.0.1", 0))
                port = probe.getsockname()[1]
            server = subprocess.Popen([PHP, "-S", f"0.0.0.0:{port}", "-t", str(app_root / "public")], cwd=app_root, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            try:
                deadline = time.time() + 8
                while True:
                    if server.poll() is not None:
                        raise RuntimeError("Serverul PHP s-a oprit înaintea verificărilor.")
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=0.25):
                            break
                    except OSError:
                        if time.time() >= deadline:
                            raise RuntimeError("Serverul PHP nu a pornit la timp.")
                        time.sleep(0.1)

                base = f"http://{address}:{port}/"
                remote = opener_for()
                status, body, _ = request(remote, base)
                check(status == 200 and "Autentificare roAdmin" in body and 'name="password"' in body, "Accesul extern trebuie să încarce .env și să ceară parola.")
                csrf = re.search(r'name="csrf" value="([^"]+)"', body)
                check(csrf is not None, "Formularul de autentificare nu conține token CSRF.")
                login = urllib.parse.urlencode({"action": "login", "csrf": csrf.group(1), "password": TEST_PASSWORD}).encode()
                status, _, _ = request(remote, base, "POST", login, {"Content-Type": "application/x-www-form-urlencoded"})
                check(status == 200, "Autentificarea externă cu parola din .env nu a ajuns la pagina inițială.")

                for path in ("/.env", "/.env.example"):
                    status, body, _ = request(remote, base.rstrip("/") + path)
                    check(status in (403, 404) and TEST_PASSWORD not in body, f"Fișierul de configurare {path} nu trebuie să fie accesibil prin HTTP.")

                status, listing, _ = request(remote, base + "?module=roDisp")
                check(status == 200 and "RODISP" in listing and "Rețea · doar vizualizare și tipărire" in listing, "Registrul nu este vizibil în sesiunea externă autentificată.")
                check("Înregistrare nouă" not in listing and "Editează" not in listing and "Setări & backup" not in listing, "Interfața read-only afișează acțiuni administrative.")
                individual_row = row_for_id(listing, "d_individual")
                check('class="action-disabled" aria-disabled="true">Vizualizează' in individual_row and 'class="action-disabled" aria-disabled="true">Act final' in individual_row and 'class="action-disabled" aria-disabled="true">Proiect' in individual_row, "Dispoziția Individual trebuie să dezactiveze Vizualizează, Act final și Proiect.")
                check('href="?module=roDisp&id=d_individual"' not in individual_row, "Titlul unei dispoziții Individual nu trebuie să ofere ocolirea linkului dezactivat.")
                rejected_row = row_for_id(listing, "d_rejected")
                check('class="action-disabled" aria-disabled="true">Vizualizează' in rejected_row and 'class="action-disabled" aria-disabled="true">Act final' in rejected_row and 'href="?print=1&amp;module=roDisp&amp;id=d_rejected&amp;doc=dispoN_pr">Proiect</a>' in rejected_row, "Dispoziția Respinsă trebuie să blocheze vizualizarea/actul final și să păstreze proiectul.")

                status, hot_listing, _ = request(remote, base + "?module=roHot")
                check(status == 200 and "ROHOT" in hot_listing, "Registrul Hotărâri nu este vizibil din rețea.")
                hot_individual_row = row_for_id(hot_listing, "h_individual")
                check('class="action-disabled" aria-disabled="true">Vizualizează' in hot_individual_row and 'class="action-disabled" aria-disabled="true">Act final' in hot_individual_row and 'class="action-disabled" aria-disabled="true">Proiect' in hot_individual_row, "Hotărârea Individual trebuie să dezactiveze cele trei acțiuni.")
                hot_rejected_row = row_for_id(hot_listing, "h_rejected")
                check('class="action-disabled" aria-disabled="true">Vizualizează' in hot_rejected_row and 'class="action-disabled" aria-disabled="true">Act final' in hot_rejected_row and 'doc=hotN_pr">Proiect</a>' in hot_rejected_row, "Hotărârea Respinsă trebuie să blocheze vizualizarea/actul final și să păstreze proiectul.")

                status, detail, _ = request(remote, base + "?module=roDisp&id=d0001")
                check(status == 200 and "VIZUALIZARE · FĂRĂ MODIFICARE" in detail and 'name="action" value="save"' not in detail, "Detaliul extern nu este numai pentru citire.")
                for label in ("Dispoziție", "Proiect dispoziție", "Referat de specialitate"):
                    check(label in detail, f"Legătura documentului {label} lipsește din detaliul extern.")
                check("normativă" not in detail.lower() and "dispoziție individuală" not in detail.lower(), "Interfața read-only nu trebuie să expună etichete normativa/individuală.")

                status, detail_hot, _ = request(remote, base + "?module=roHot&id=h0001")
                check(status == 200 and all(label in detail_hot for label in ("Hotărâre", "Proiect hotărâre", "Referat de specialitate")), "Hotărârile trebuie să afișeze doar cele trei butoane cu etichetele cerute.")
                check("normativă" not in detail_hot.lower() and "hotărâre individuală" not in detail_hot.lower(), "Hotărârile read-only nu trebuie să expună etichete normativa/individuală.")

                status, printed, _ = request(remote, base + "?print=1&module=roDisp&id=d0001&doc=dispoN")
                check(status == 200 and "Imprimă / salvează ca PDF" in printed and "DISPOZIȚIA NR." in printed, "Tipărirea browserului nu funcționează din sesiunea externă.")
                check('href="?module=roDisp&amp;id=d0001"' in printed and "history.back" not in printed, "Înapoi la înregistrare trebuie să fie un URL direct funcțional și să nu folosească history.back().")
                status, printed_project, _ = request(remote, base + "?print=1&module=roDisp&id=d0001&doc=dispoN_pr")
                check(status == 200 and "PROIECT DE DISPOZIȚIE" in printed_project, "Tipărirea proiectului nu funcționează din sesiunea externă.")

                for module, id, final_doc, project_doc in (("roDisp", "d_individual", "dispoN", "dispoN_pr"), ("roHot", "h_individual", "hotN", "hotN_pr")):
                    status, _, _ = request(remote, base + f"?module={module}&id={id}")
                    check(status == 404, f"Vizualizarea directă a înregistrării Individual {module}/{id} trebuie refuzată.")
                    for doc in (final_doc, project_doc):
                        status, _, _ = request(remote, base + f"?print=1&module={module}&id={id}&doc={doc}")
                        check(status == 404, f"Tipărirea directă a actului Individual {module}/{id}/{doc} trebuie refuzată.")
                for module, id, final_doc, project_doc in (("roDisp", "d_rejected", "dispoN", "dispoN_pr"), ("roHot", "h_rejected", "hotN", "hotN_pr")):
                    status, _, _ = request(remote, base + f"?module={module}&id={id}")
                    check(status == 404, f"Vizualizarea directă a înregistrării Respinsă {module}/{id} trebuie refuzată.")
                    status, _, _ = request(remote, base + f"?print=1&module={module}&id={id}&doc={final_doc}")
                    check(status == 404, f"Actul final al înregistrării Respinsă {module}/{id} trebuie refuzat.")
                    status, rejected_project, _ = request(remote, base + f"?print=1&module={module}&id={id}&doc={project_doc}")
                    check(status == 200 and ("PROIECT DE DISPOZIȚIE" in rejected_project or "PROIECT DE HOTĂRÂRE" in rejected_project), f"Proiectul Respinsă {module}/{id} trebuie să rămână disponibil.")

                for path in ("?settings=1", "?module=roDisp&new=1"):
                    status, _, _ = request(remote, base + path)
                    check(status == 403, f"Pagina administrativă {path} trebuie să fie refuzată din rețea.")

                before = digest(database)
                form = urllib.parse.urlencode({"action": "save", "module": "roDisp", "id": "d0001", "values[2]": "MUTATION MUST NOT APPLY"}).encode()
                status, _, headers = request(remote, base, "POST", form, {"Content-Type": "application/x-www-form-urlencoded"})
                check(status == 405 and headers.get("Allow") == "GET, HEAD", "POST extern trebuie refuzat cu 405.")
                for method in ("PUT", "PATCH", "DELETE"):
                    status, _, _ = request(remote, base, method, b"mutation", {"Content-Type": "application/octet-stream"})
                    check(status == 405, f"Metoda externă {method} trebuie refuzată.")
                status, _, _ = request(remote, base, "POST", form, {"Content-Type": "application/x-www-form-urlencoded", "X-Forwarded-For": "127.0.0.1"})
                check(status == 405, "Header-ul X-Forwarded-For nu trebuie să acorde privilegii locale.")
                status, _, _ = request(remote, base + "?download=not-generated.docx")
                check(status == 403, "Descărcarea DOCX trebuie să fie refuzată accesului extern.")
                check(digest(database) == before, "Operațiile externe refuzate au modificat baza de date.")

                local_base = f"http://127.0.0.1:{port}/"
                local = opener_for()
                status, login_page, _ = request(local, local_base)
                check(status == 200 and "Autentificare roAdmin" in login_page, "Accesul local existent trebuie să păstreze autentificarea configurată.")
                local_csrf = re.search(r'name="csrf" value="([^"]+)"', login_page)
                check(local_csrf is not None, "Formularul local de autentificare nu conține token CSRF.")
                local_login = urllib.parse.urlencode({"action": "login", "csrf": local_csrf.group(1), "password": TEST_PASSWORD}).encode()
                status, _, _ = request(local, local_base, "POST", local_login, {"Content-Type": "application/x-www-form-urlencoded"})
                check(status == 200, "Autentificarea locală nu a ajuns la pagina inițială.")
                status, editor, _ = request(local, local_base + "?module=roDisp&id=d0001")
                check(status == 200 and 'name="action" value="save"' in editor, "Formularul de salvare local trebuie să rămână disponibil.")
                parser = FormParser()
                parser.feed(editor)
                check("csrf" in parser.values and len([key for key in parser.values if key.startswith("values[")]) == 11, "Nu s-au putut reconstitui câmpurile formularului local.")
                parser.values["values[2]"] += " — TEST LOCAL"
                local_data = {"action": "save", "module": "roDisp", "id": "d0001", **parser.values}
                save_body = urllib.parse.urlencode(local_data).encode()
                status, _, _ = request(local, local_base + "?module=roDisp&id=d0001", "POST", save_body, {"Content-Type": "application/x-www-form-urlencoded"})
                check(status == 200 and digest(database) != before, "Fluxul de salvare local existent nu a modificat înregistrarea de test.")

                env_file.unlink()
                with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe:
                    probe.bind(("127.0.0.1", 0))
                    unauth_port = probe.getsockname()[1]
                unauth_server = subprocess.Popen([PHP, "-S", f"0.0.0.0:{unauth_port}", "-t", str(app_root / "public")], cwd=app_root, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                try:
                    deadline = time.time() + 8
                    while True:
                        if unauth_server.poll() is not None:
                            raise RuntimeError("Serverul PHP fără parolă s-a oprit înaintea verificărilor.")
                        try:
                            with socket.create_connection(("127.0.0.1", unauth_port), timeout=0.25):
                                break
                        except OSError:
                            if time.time() >= deadline:
                                raise RuntimeError("Serverul PHP fără parolă nu a pornit la timp.")
                            time.sleep(0.1)
                    status, _, _ = request(opener_for(), f"http://{address}:{unauth_port}/")
                    check(status == 403, "Accesul din rețea fără ROAPP_PASSWORD și fără .env trebuie refuzat.")
                finally:
                    unauth_server.terminate()
                    try:
                        unauth_server.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        unauth_server.kill()
                        unauth_server.wait(timeout=5)

                print("Read-only HTTP integration: passed")
                print("Verified: .env authentication/privacy, Tip Act restrictions and direct-URL denials, explicit print-return URL, denied remote writes without database changes, and local save workflow.")
                return 0
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=5)


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:
        print(f"FAIL: {error}", file=sys.stderr)
        raise SystemExit(1)
