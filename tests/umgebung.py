"""Testumgebung fuer api4webtrees: ein frisches webtrees mit SQLite unter `php -S`, nur auf 127.0.0.1.

Dieselbe Einrichtung wie in wtWin/wtTux ("Neuen Stammbaum auf diesem PC anlegen") und im nas4webtrees-Image:
Einrichtungsassistent per POST, danach die webtrees-Kommandozeile. Dazu Testbaeume und je Rolle ein Konto.

Umgebungsvariablen:
  PHP             PHP-Programm (Standard: php)
  WEBTREES_ZIP    vorhandene webtrees-ZIP statt Download
  WEBTREES        Fassung zum Herunterladen (Standard 2.2.6, Pruefsumme siehe PRUEFSUMMEN)
"""
import hashlib
import http.cookiejar
import json
import os
import shutil
import socket
import sqlite3
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile

HIER = os.path.dirname(os.path.abspath(__file__))
MODUL = os.path.dirname(HIER)
ARBEIT = os.path.join(HIER, ".work")
WT = os.path.join(ARBEIT, "webtrees")
PHP = os.environ.get("PHP", "php")

# Wie nas4webtrees VERSIONS: nur bekannte Fassungen ohne eigene ZIP.
PRUEFSUMMEN = {
    "2.2.6": "c6b82f0269733b71de1a160a93eb32029e3467674e9bbfe3956d46a8bb04f925",
}

# Konten je Rolle (webtrees: canedit none/access/edit/accept/admin). "admin" legt der Assistent an.
PASSWORT = "test-passwort-1"
KONTEN = {"mitglied": "access", "bearbeiter": "edit", "verwalter": "admin"}

# Wie in wtWin (LokalerServer.ROUTER_PHP): php -S kennt keine .htaccess, data/ muss gesperrt sein.
ROUTER = r"""<?php
$root = realpath($_SERVER['DOCUMENT_ROOT']);
$pfad = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
foreach (explode('/', str_replace('\\', '/', $pfad)) as $teil) {
    if (strtolower(rtrim($teil, ' .')) === 'data' || ($teil !== '' && $teil[0] === '.')) { http_response_code(403); return true; }
}
return false;
"""


def _zip():
    eigene = os.environ.get("WEBTREES_ZIP")
    if eigene:
        return eigene
    fassung = os.environ.get("WEBTREES", "2.2.6")
    ziel = os.path.join(HIER, ".cache", f"webtrees-{fassung}.zip")
    os.makedirs(os.path.dirname(ziel), exist_ok=True)
    if not os.path.isfile(ziel):
        url = f"https://github.com/fisharebest/webtrees/releases/download/{fassung}/webtrees-{fassung}.zip"
        urllib.request.urlretrieve(url, ziel + ".tmp")
        os.replace(ziel + ".tmp", ziel)
    soll = PRUEFSUMMEN.get(fassung)
    if soll:
        ist = hashlib.sha256(open(ziel, "rb").read()).hexdigest()
        if ist != soll:
            os.remove(ziel)
            raise RuntimeError(f"Pruefsumme webtrees-{fassung}.zip: {ist}")
    return ziel


def _freier_port():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def wt(*args, pruefen=True):
    """webtrees-Kommandozeile (index.php mit Argumenten)."""
    r = subprocess.run([PHP, "index.php", "--no-interaction", *args], cwd=WT, capture_output=True, text=True)
    if pruefen and r.returncode != 0:
        raise RuntimeError(f"webtrees {' '.join(args)}: {r.stdout.strip()} {r.stderr.strip()}")
    return r.stdout


def sql(anweisung, *werte):
    con = sqlite3.connect(os.path.join(WT, "data", "webtrees.sqlite"), timeout=30)
    try:
        cur = con.execute(anweisung, werte)
        con.commit()
        return cur.fetchall()
    finally:
        con.close()


class Umgebung:
    def __init__(self):
        self.prozess = None
        self.port = 0

    @property
    def basis(self):
        return f"http://127.0.0.1:{self.port}/"

    def aufbauen(self):
        shutil.rmtree(ARBEIT, ignore_errors=True)
        os.makedirs(ARBEIT)
        with zipfile.ZipFile(_zip()) as z:
            z.extractall(ARBEIT)
        os.symlink(MODUL, os.path.join(WT, "modules_v4", "api4webtrees"))
        router = os.path.join(ARBEIT, "router.php")
        open(router, "w").write(ROUTER)

        self.port = _freier_port()
        log = open(os.path.join(ARBEIT, "php.log"), "ab")
        self.prozess = subprocess.Popen(
            [PHP, "-d", "display_errors=0", "-d", "log_errors=1", "-S", f"127.0.0.1:{self.port}", "-t", WT, router],
            cwd=WT, stdout=log, stderr=subprocess.STDOUT)
        self._warten()

        # Einrichtungsassistent, Schritt 6 - wie nas4webtrees-entry.py run_setup_wizard()
        felder = {
            "lang": "de", "tblpfx": "wt_", "baseurl": "", "dbtype": "sqlite", "dbhost": "", "dbport": "",
            "dbuser": "", "dbpass": "", "dbname": "webtrees", "wtname": "Admin", "wtuser": "admin",
            "wtpass": PASSWORT, "wtemail": "admin@example.invalid", "step": "6",
        }
        try:
            urllib.request.urlopen(self.basis + "index.php", urllib.parse.urlencode(felder).encode(), timeout=120).read()
        except urllib.error.HTTPError:
            pass
        if not os.path.isfile(os.path.join(WT, "data", "config.ini.php")):
            raise RuntimeError("webtrees-Einrichtung fehlgeschlagen, siehe tests/.work/php.log")

        wt("site-setting", "USE_REGISTRATION_MODULE", "0")
        for name, titel, datei in (("testbaum", "Testbaum", "testbaum.ged"), ("geheim", "Geheimer Baum", "geheim.ged")):
            wt("tree", name, "--create", f"--title={titel}")
            wt("tree-import", name, os.path.join(HIER, datei))
        # testbaum oeffentlich (Gaeste sehen Verstorbene), geheim nur fuer Angemeldete mit Rolle.
        sql("UPDATE wt_gedcom SET private=1 WHERE gedcom_name='geheim'")
        wt("tree-setting", "testbaum", "HIDE_LIVE_PEOPLE", "1")
        wt("user-setting", "admin", "auto_accept", "1")
        for name, rolle in KONTEN.items():
            wt("user", name, "--create", f"--real-name={name}", f"--email={name}@example.invalid", f"--password={PASSWORT}")
            wt("user-setting", name, "verified", "1")
            wt("user-setting", name, "verified_by_admin", "1")
            wt("user-tree-setting", name, "testbaum", "canedit", rolle)
        return self

    def _warten(self):
        ende = time.time() + 20
        while time.time() < ende:
            try:
                urllib.request.urlopen(self.basis + "public/css/webtrees.min.css", timeout=2).read(1)
                return
            except OSError:
                if self.prozess.poll() is not None:
                    break
                time.sleep(0.2)
        raise RuntimeError("php -S startet nicht, siehe tests/.work/php.log")

    def abbauen(self):
        if self.prozess:
            self.prozess.terminate()
            self.prozess.wait(5)

    def sitzung(self, benutzer=None):
        s = Sitzung(self.basis)
        if benutzer:
            s.anmelden(benutzer, PASSWORT)
        else:
            s.info()
        return s


class _KeineUmleitung(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Antwort:
    def __init__(self, status, text, umleitung=None):
        self.status, self.text, self.umleitung = status, text, umleitung
        try:
            self.json = json.loads(text)
        except ValueError:
            self.json = None

    def __repr__(self):
        return f"Antwort({self.status}, {self.text[:300]!r})"


class Sitzung:
    """Ein Client wie die Apps: Cookie, CSRF-Token aus Info, JSON rein und raus. Folgt KEINER Umleitung."""

    def __init__(self, basis):
        self.basis = basis
        self.csrf = ""
        self.oeffner = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), _KeineUmleitung)

    def url(self, route, **params):
        q = {"route": route, "lang": "de", **{k: str(v) for k, v in params.items()}}
        return self.basis + "index.php?" + urllib.parse.urlencode(q)

    def _senden(self, req):
        try:
            with self.oeffner.open(req, timeout=60) as r:
                return Antwort(r.status, r.read().decode("utf-8", "replace"))
        except urllib.error.HTTPError as e:
            return Antwort(e.code, e.read().decode("utf-8", "replace"), e.headers.get("Location"))

    def get(self, aktion, baum=None, **params):
        route = f"/module/_api4webtrees_/{aktion}" + (f"/{baum}" if baum else "")
        return self._senden(urllib.request.Request(self.url(route, **params)))

    def post(self, aktion, baum, rumpf, csrf=True, **params):
        route = f"/module/_api4webtrees_/{aktion}" + (f"/{baum}" if baum else "")
        req = urllib.request.Request(self.url(route, **params), data=json.dumps(rumpf).encode(), method="POST")
        req.add_header("Content-Type", "application/json")
        if csrf:
            req.add_header("X-CSRF-TOKEN", self.csrf)
        return self._senden(req)

    def info(self):
        a = self.get("Info")
        self.csrf = a.json["csrf"]
        return a.json

    def anmelden(self, benutzer, passwort):
        self.info()
        form = urllib.parse.urlencode({"_csrf": self.csrf, "username": benutzer, "password": passwort}).encode()
        self._senden(urllib.request.Request(self.url("/login"), data=form, method="POST"))
        info = self.info()
        if not info["user"]["loggedIn"]:
            raise RuntimeError(f"Anmeldung {benutzer} fehlgeschlagen")
        return info
