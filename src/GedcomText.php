<?php

declare(strict_types=1);

namespace Api4Webtrees;

use Fisharebest\Webtrees\Date;

use function array_slice;
use function explode;
use function in_array;
use function preg_match;
use function preg_replace;
use function str_contains;
use function str_replace;
use function strlen;
use function strtoupper;
use function substr;
use function substr_count;
use function trim;

/**
 * Reine Textfunktionen fuer GEDCOM-Zeilen: bauen, pruefen, entschaerfen. Ohne webtrees-Zustand,
 * damit sie fuer sich lesbar und testbar bleiben.
 */
final class GedcomText
{
    // Verknuepfungen laufen ueber AddIndividual/Media - nicht ueber den Fakten-Editor.
    public const array LINK_TAGS = ['FAMS', 'FAMC', 'HUSB', 'WIFE', 'CHIL', 'OBJE', 'CHAN'];

    // Ereignisse, die ohne Datum/Ort als "1 TAG Y" geschrieben werden.
    public const array EVENT_TAGS = ['BIRT', 'CHR', 'BAPM', 'DEAT', 'BURI', 'CREM', 'MARR', 'DIV', 'ENGA'];

    /**
     * Prueft das GEDCOM eines Ereignisses: genau eine Ebene-1-Zeile plus Unterzeilen 2-9 mit Tag. Liefert den
     * Fehlercode fuer die App oder null. webtrees selbst prueft nur die erste Zeile - ueber das rohe Feld "gedcom"
     * kaemen sonst weitere Ebene-1-Zeilen (FAMS, OBJE, RESN) oder ganze Datensaetze an den Regeln des Moduls vorbei.
     */
    public static function factGedcomProblem(string $gedcom): string|null
    {
        if (preg_match('/^1 ([A-Z_][A-Z0-9_]*)/', $gedcom, $match) !== 1) {
            return 'invalid-gedcom';
        }

        $tag = $match[1];

        foreach (array_slice(explode("\n", $gedcom), 1) as $line) {
            if (preg_match('/^[2-9] [A-Z_][A-Z0-9_]*( .*)?$/', $line) !== 1) {
                return 'invalid-gedcom';
            }
        }

        // Verknuepfungen entstehen nur ueber AddIndividual, Media und Unlink - nie ueber den Fakten-Editor.
        if (in_array($tag, self::LINK_TAGS, true)) {
            return 'link-tag-not-allowed';
        }

        // Ein Name traegt den Nachnamen zwischen genau zwei Schraegstrichen (oder gar keinen); "@" hat darin nichts verloren.
        if ($tag === 'NAME') {
            [$first] = explode("\n", $gedcom, 2);

            if (!in_array(substr_count($first, '/'), [0, 2], true) || str_contains($first, '@')) {
                return 'invalid-name';
            }
        }

        if (preg_match('/\n2 DATE (.+)/', $gedcom, $date_match) === 1 && !(new Date($date_match[1]))->isOK()) {
            return 'invalid-date';
        }

        return null;
    }

    /**
     * Zeilen hinter die Ebene-1-Zeile (samt deren CONT-Fortsetzungen) setzen.
     */
    public static function insertAfterFirstLine(string $gedcom, string $insert): string
    {
        if ($insert === '') {
            return $gedcom;
        }

        preg_match('/^[^\n]*(\n2 CONT[^\n]*)*/', $gedcom, $match);

        return $match[0] . $insert . substr($gedcom, strlen($match[0]));
    }

    public static function eventGedcom(string $tag, string $date, string $place, bool $happened): string
    {
        $date  = strtoupper(self::line($date));
        $place = self::line($place);

        if ($date === '' && $place === '') {
            return $happened ? "\n1 " . $tag . ' Y' : '';
        }

        return "\n1 " . $tag . ($date === '' ? '' : "\n2 DATE " . $date) . ($place === '' ? '' : "\n2 PLAC " . $place);
    }

    /**
     * Einzeiliger GEDCOM-Wert: keine Zeilenumbrueche, sonst liessen sich Zeilen einschleusen.
     */
    public static function line(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Mehrzeiliger GEDCOM-Wert: Folgezeilen werden zu "<level> CONT ...".
     */
    public static function multiline(string $value, int $cont_level): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));

        return str_replace("\n", "\n" . $cont_level . ' CONT ', $value);
    }

    /**
     * Namensbestandteil: einzeilig und ohne die GEDCOM-Sonderzeichen "/" (umschliesst den Nachnamen) und "@" (Verweis).
     */
    public static function namePart(string $value): string
    {
        return self::line(str_replace(['/', '@'], '', $value));
    }

    /**
     * "@I123@" - fuer GEDCOM ein Verweis auf einen Datensatz. Als Text eingegeben wuerde webtrees ihn als Verknuepfung lesen.
     */
    public static function looksLikePointer(string $value): bool
    {
        return preg_match('/^@[^@\n]+@/', trim($value)) === 1;
    }
}
