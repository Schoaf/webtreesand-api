<?php

declare(strict_types=1);

namespace Api4Webtrees;

use Fisharebest\Webtrees\Date;

use function array_map;
use function array_slice;
use function explode;
use function in_array;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function str_contains;
use function str_replace;
use function strlen;
use function strtoupper;
use function substr;
use function substr_count;
use function trim;

use const PREG_SET_ORDER;

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
     * Alle Zeilen "<ebene> <tag> ..." in einem Block von Unterzeilen, je mit Wert und eigenen Unterzeilen.
     *
     * @return array<int,array{0:string,1:string}>
     */
    public static function subrecords(string $block, int $level, string $tag): array
    {
        $deeper = $level + 1;
        preg_match_all('/\n' . $level . ' ' . $tag . '(?: ([^\n]*))?((?:\n[' . $deeper . '-9] [^\n]*)*)/', $block, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $t): array => [$t[1] ?? '', $t[2] ?? ''], $matches);
    }

    /**
     * Ein Wert samt Fortsetzungen: CONT beginnt eine neue Zeile, CONC haengt an ([$level] ist die Ebene des Werts).
     */
    public static function withContinuations(string $value, string $sub, int $level): string
    {
        $text = $value;
        preg_match_all('/\n' . ($level + 1) . ' (CONT|CONC)(?: ([^\n]*))?/', $sub, $parts, PREG_SET_ORDER);

        foreach ($parts as $part) {
            $text .= ($part[1] === 'CONT' ? "\n" : '') . ($part[2] ?? '');
        }

        return $text;
    }

    /** Der erste Wert "<ebene> <tag>" eines Datensatzes samt Fortsetzungen; leer, wenn es ihn nicht gibt. */
    public static function firstValue(string $gedcom, int $level, string $tag): string
    {
        $t = self::subrecords("\n" . $gedcom, $level, $tag)[0] ?? null;

        return $t === null ? '' : self::withContinuations($t[0], $t[1], $level);
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

    /**
     * Ein Ereignis in Kopfzeile und Ebene-2-Bloecke zerlegen: jeder Block beginnt mit "\n2 " und traegt seine
     * Unterzeilen 3-9. Kopf . implode('', Bloecke) ergibt wieder das Ereignis.
     *
     * @return array{0:string,1:list<string>}
     */
    public static function headAndBlocks(string $gedcom): array
    {
        [$head] = explode("\n", $gedcom, 2);
        preg_match_all('/\n2 [^\n]*(?:\n[3-9] [^\n]*)*/', substr($gedcom, strlen($head)), $matches);

        return [$head, $matches[0]];
    }

    /**
     * Alle Unterzeilen "<level> <tag> ..." samt ihren tieferen Zeilen aus einem Block entfernen und $new anhaengen
     * ('' = nur entfernen). Das Muster fuer "nur genannte Teile ersetzen, alles andere bleibt".
     */
    public static function replaceSubrecords(string $block, int $level, string $tag, string $new): string
    {
        $pattern = '/\n' . $level . ' ' . preg_quote($tag, '/') . '(?= |\n|$)(?: [^\n]*)?(?:\n[' . ($level + 1) . '-9] [^\n]*)*/';

        return (string) preg_replace($pattern, '', $block) . $new;
    }

    /**
     * Die Medienverweise "<level> OBJE @M1@" eines Blocks durch diese Liste ersetzen; nur gueltige Kennungen kommen an.
     *
     * @param array<mixed> $media Kennungen, mit oder ohne @
     */
    public static function replaceMediaLinks(string $block, int $level, array $media): string
    {
        $block = (string) preg_replace('/\n' . $level . ' OBJE @[^\n]*(?:\n[' . ($level + 1) . '-9] [^\n]*)*/', '', $block);

        foreach ($media as $m) {
            if (preg_match('/^@?([A-Za-z0-9:_.-]+)@?$/', (string) $m, $match) === 1) {
                $block .= "\n" . $level . ' OBJE @' . $match[1] . '@';
            }
        }

        return $block;
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
