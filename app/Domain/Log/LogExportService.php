<?php

declare(strict_types=1);

namespace App\Domain\Log;

use App\Domain\PageService;
use App\Domain\User;
use App\Support\ValidationException;
use ZipArchive;

/**
 * Logbuch als Tabelle herunterladen (FR-LOG-12): CSV zum Weiterverarbeiten, XML
 * zum maschinellen Einlesen, XLSX für Excel.
 *
 * Zahlen gehen als Zahlen heraus, nicht als formatierter Text: Ein Export, in
 * dem sich Stunden und Beträge nicht summieren lassen, verfehlt seinen Zweck.
 * Die Zeitpunkte liegen als UTC in der Datenbank und werden in die Zeitzone des
 * Browsers umgerechnet - ein Logbuch ohne richtige Uhrzeiten wäre wertlos.
 */
final class LogExportService
{
    public const FORMATS = ['csv', 'xml', 'xlsx'];

    /** Tage zwischen dem Nullpunkt von Excel (1899-12-30) und dem der Unixzeit. */
    private const EXCEL_EPOCH_OFFSET = 25569;

    public function __construct(
        private readonly LogService $log,
        private readonly PageService $pages,
    ) {
    }

    /**
     * @return array{filename: string, mime: string, body: string}
     */
    public function export(User $user, int $pageId, string $format, ?string $timezone = null): array
    {
        if (!in_array($format, self::FORMATS, true)) {
            throw new ValidationException('Unbekanntes Exportformat.');
        }

        // Prüft zugleich den Zugriff: `find()` wirft, wenn die Seite dem
        // Benutzer weder gehört noch mit ihm geteilt ist.
        $page = $this->pages->find($user, $pageId);
        $title = trim((string) ($page['title'] ?? '')) ?: 'Logbuch';

        // Aufsteigend: Ein Logbuch liest sich auf Papier und in der Tabelle von
        // vorn nach hinten, während am Bildschirm das Jüngste oben steht.
        $board = $this->log->board($user, $pageId, 'occurred_at', 'asc');
        $zone = $this->timezone($timezone);

        $columns = array_values($board['columns']);
        $rows = [];
        foreach ($board['entries'] as $entry) {
            $rows[] = $this->row($entry, $columns, $zone);
        }

        $body = match ($format) {
            'csv' => $this->csv($columns, $rows),
            'xml' => $this->xml($title, $columns, $rows, $zone),
            default => $this->xlsx($title, $columns, $rows),
        };

        return [
            'filename' => $this->filename($title, $format),
            'mime' => match ($format) {
                'csv' => 'text/csv; charset=utf-8',
                'xml' => 'application/xml; charset=utf-8',
                default => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            },
            'body' => $body,
        ];
    }

    /**
     * Eine Zeile als Zellen, aus denen jedes Format seine eigene Darstellung
     * ableitet. `number` bleibt daneben stehen, damit CSV und XLSX rechnen
     * können, wo die Anzeige nur Text hätte.
     *
     * @param array<string, mixed> $entry
     * @param array<int, array<string, mixed>> $columns
     * @return array<int, array{type: string, text: string, number: float|null, time: int|null}>
     */
    private function row(array $entry, array $columns, \DateTimeZone $zone): array
    {
        $occurredAt = (string) ($entry['occurred_at'] ?? '');
        $timestamp = $occurredAt !== '' ? strtotime($occurredAt) : false;

        $cells = [[
            'type' => 'datetime',
            'text' => $timestamp === false
                ? $occurredAt
                : (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format('Y-m-d H:i'),
            'number' => null,
            'time' => $timestamp === false ? null : $timestamp,
        ]];

        foreach ($columns as $column) {
            $cells[] = $this->cell($entry, $column, $zone);
        }

        return $cells;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $column
     * @return array{type: string, text: string, number: float|null, time: int|null}
     */
    private function cell(array $entry, array $column, \DateTimeZone $zone): array
    {
        $type = (string) $column['type'];
        $empty = ['type' => $type, 'text' => '', 'number' => null, 'time' => null];

        // Die Benutzerspalte trägt keinen eigenen Wert: Sie zeigt, wer den
        // Eintrag angelegt hat (siehe LogService::normalizeValue()).
        if ($type === LogColumnType::User->value) {
            return ['type' => $type, 'text' => (string) ($entry['created_by_name'] ?? ''), 'number' => null, 'time' => null];
        }

        $values = is_array($entry['values'] ?? null) ? $entry['values'] : [];
        $value = $values[(int) $column['id']] ?? null;
        if (!is_array($value)) {
            return $empty;
        }

        $text = $value['value_text'] !== null ? (string) $value['value_text'] : '';
        $number = $value['value_number'] !== null ? (float) $value['value_number'] : null;

        if ($type === LogColumnType::Location->value && $value['value_lat'] !== null) {
            $coordinates = round((float) $value['value_lat'], 5) . ', ' . round((float) $value['value_lon'], 5);
            $text = $text !== '' ? "{$text} ({$coordinates})" : $coordinates;
        }

        // Bewertung als Zahl statt als Sterne: In einer Tabelle lässt sich damit
        // rechnen und sortieren, die Sterne stünden nur als Zeichenkette da.
        if ($type === LogColumnType::Rating->value) {
            return ['type' => $type, 'text' => $number === null ? '' : (string) (int) $number, 'number' => $number, 'time' => null];
        }

        return ['type' => $type, 'text' => $text, 'number' => $number, 'time' => null];
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     * @param array<int, array<int, array{type: string, text: string, number: float|null, time: int|null}>> $rows
     */
    private function csv(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new ValidationException('Der Export konnte nicht erzeugt werden.');
        }

        fputcsv($handle, $this->header($columns), ',', '"', '\\');
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $cell) {
                // Zahlen mit Punkt und ohne Tausenderzeichen: So liest sie jedes
                // Programm, während „1.234,56" je nach Gebietsschema kippt.
                $line[] = $cell['number'] !== null && $cell['type'] !== 'rating'
                    ? $this->number($cell['number'])
                    : $cell['text'];
            }
            fputcsv($handle, $line, ',', '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        // Ohne BOM zeigt Excel Umlaute als Buchstabensalat; Parser überspringen
        // ihn.
        return "\u{FEFF}" . $csv;
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     * @param array<int, array<int, array{type: string, text: string, number: float|null, time: int|null}>> $rows
     */
    private function xml(string $title, array $columns, array $rows, \DateTimeZone $zone): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<logbook title="' . $this->escape($title) . '" exported-at="'
                . (new \DateTimeImmutable('now', $zone))->format(\DateTimeInterface::ATOM) . '">',
            '  <columns>',
        ];
        foreach ($columns as $column) {
            $lines[] = '    <column id="' . (int) $column['id'] . '" name="' . $this->escape((string) $column['name'])
                . '" type="' . $this->escape((string) $column['type']) . '"/>';
        }
        $lines[] = '  </columns>';
        $lines[] = '  <entries>';

        foreach ($rows as $row) {
            $occurred = $row[0];
            $iso = $occurred['time'] === null
                ? $occurred['text']
                : (new \DateTimeImmutable('@' . $occurred['time']))->setTimezone($zone)->format(\DateTimeInterface::ATOM);
            $lines[] = '    <entry occurred-at="' . $this->escape($iso) . '">';
            foreach ($columns as $index => $column) {
                $cell = $row[$index + 1];
                $attributes = ' id="' . (int) $column['id'] . '" name="' . $this->escape((string) $column['name'])
                    . '" type="' . $this->escape((string) $column['type']) . '"';
                if ($cell['number'] !== null) {
                    $attributes .= ' number="' . $this->number($cell['number']) . '"';
                }
                $lines[] = '      <value' . $attributes . '>' . $this->escape($cell['text']) . '</value>';
            }
            $lines[] = '    </entry>';
        }

        $lines[] = '  </entries>';
        $lines[] = '</logbook>';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Schreibt die Arbeitsmappe von Hand statt über eine Bibliothek: Eine
     * Tabelle mit Kopfzeile, Text-, Zahlen- und Datumsspalten braucht nur die
     * vier Pflichtteile des Formats, und das Projekt bleibt ohne weitere
     * Abhängigkeit.
     *
     * @param array<int, array<string, mixed>> $columns
     * @param array<int, array<int, array{type: string, text: string, number: float|null, time: int|null}>> $rows
     */
    private function xlsx(string $title, array $columns, array $rows): string
    {
        $sheet = ['<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'];

        $header = $this->header($columns);
        $cells = [];
        foreach ($header as $index => $label) {
            $cells[] = '<c r="' . $this->columnName($index) . '1" s="1" t="inlineStr"><is><t xml:space="preserve">'
                . $this->escape($label) . '</t></is></c>';
        }
        $sheet[] = '<row r="1">' . implode('', $cells) . '</row>';

        foreach ($rows as $rowIndex => $row) {
            $number = $rowIndex + 2;
            $cells = [];
            foreach ($row as $index => $cell) {
                $reference = $this->columnName($index) . $number;
                if ($cell['time'] !== null) {
                    // Echtes Datum statt Text: Nur so lässt sich in Excel danach
                    // sortieren und filtern.
                    $cells[] = '<c r="' . $reference . '" s="2"><v>'
                        . $this->number($cell['time'] / 86400 + self::EXCEL_EPOCH_OFFSET) . '</v></c>';
                    continue;
                }
                if ($cell['number'] !== null) {
                    $cells[] = '<c r="' . $reference . '"><v>' . $this->number($cell['number']) . '</v></c>';
                    continue;
                }
                if ($cell['text'] === '') {
                    continue;
                }
                $cells[] = '<c r="' . $reference . '" t="inlineStr"><is><t xml:space="preserve">'
                    . $this->escape($cell['text']) . '</t></is></c>';
            }
            $sheet[] = '<row r="' . $number . '">' . implode('', $cells) . '</row>';
        }

        $sheet[] = '</sheetData></worksheet>';

        $path = tempnam(sys_get_temp_dir(), 'logxlsx');
        if ($path === false) {
            throw new ValidationException('Der Export konnte nicht erzeugt werden.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new ValidationException('Der Export konnte nicht erzeugt werden.');
        }

        $zip->addFromString('[Content_Types].xml', self::CONTENT_TYPES);
        $zip->addFromString('_rels/.rels', self::ROOT_RELS);
        $zip->addFromString('xl/workbook.xml', str_replace('%name%', $this->escape($this->sheetName($title)), self::WORKBOOK));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::WORKBOOK_RELS);
        $zip->addFromString('xl/styles.xml', self::STYLES);
        $zip->addFromString('xl/worksheets/sheet1.xml', implode('', $sheet));
        $zip->close();

        $body = (string) file_get_contents($path);
        @unlink($path);

        return $body;
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     * @return array<int, string>
     */
    private function header(array $columns): array
    {
        return array_merge(
            ['Zeitpunkt'],
            array_map(static fn (array $column): string => (string) $column['name'], $columns),
        );
    }

    /** Ohne Exponentialschreibweise und ohne Tausenderzeichen. */
    private function number(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** A, B, ... Z, AA, AB - für mehr Spalten, als das Alphabet hergibt. */
    private function columnName(int $index): string
    {
        $name = '';
        for ($position = $index; $position >= 0; $position = intdiv($position, 26) - 1) {
            $name = chr(65 + $position % 26) . $name;
        }

        return $name;
    }

    /**
     * Excel verbietet in Blattnamen \ / ? * [ ] : und lässt höchstens 31
     * Zeichen zu; ein verstoßender Name macht die Datei unlesbar.
     */
    private function sheetName(string $title): string
    {
        $name = trim(preg_replace('/[\\\\\/?*\[\]:]+/u', ' ', $title) ?? '');
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            return 'Logbuch';
        }

        return mb_substr($name, 0, 31);
    }

    private function filename(string $title, string $format): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $this->transliterate($title)));
        $slug = trim($slug, '-');

        return ($slug === '' ? 'logbuch' : $slug) . '-' . gmdate('Y-m-d') . '.' . $format;
    }

    /** Umlaute im Dateinamen behalten, statt sie zu Bindestrichen zu machen. */
    private function transliterate(string $value): string
    {
        return strtr($value, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
        ]);
    }

    private function timezone(?string $timezone): \DateTimeZone
    {
        if ($timezone === null || $timezone === '') {
            return new \DateTimeZone('UTC');
        }

        try {
            return new \DateTimeZone($timezone);
        } catch (\Exception) {
            // Eine unbekannte Zone ist kein Grund, den Export zu verweigern.
            return new \DateTimeZone('UTC');
        }
    }

    private const CONTENT_TYPES = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    private const ROOT_RELS = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    private const WORKBOOK = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="%name%" sheetId="1" r:id="rId1"/></sheets></workbook>';

    private const WORKBOOK_RELS = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    /** Zwei Formate genügen: fette Kopfzeile (s="1") und Datum/Zeit (s="2"). */
    private const STYLES = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="YYYY-MM-DD\ HH:MM"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}
