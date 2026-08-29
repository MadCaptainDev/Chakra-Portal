<?php

namespace App\Services;

use App\Models\WhatsappContact;
use App\Models\WhatsappPhonebook;

/**
 * Turns a CSV of phone numbers into contacts attached to a phonebook.
 *
 * Plain PHP CSV parsing (fgetcsv) rather than a package -- this app has no
 * existing import pattern to build on, and the format is about as simple as
 * CSV gets: one header row, a handful of recognised columns, everything else
 * ignored.
 */
class WhatsappContactImporter
{
    /** Columns this importer understands. Anything else in the header is ignored. */
    private const RECOGNISED_COLUMNS = ['phone', 'name', 'var1', 'var2', 'var3', 'var4', 'var5'];

    /** Fewer digits than this and a "phone number" is a typo, not a number. */
    private const MIN_DIGITS = 8;

    /**
     * @return array{imported: int, skipped: int, errors: array<int, string>}
     */
    public function import(string $csvPath, WhatsappPhonebook $phonebook): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $contactIds = [];

        $handle = fopen($csvPath, 'r');

        if ($handle === false) {
            return [
                'imported' => 0,
                'skipped' => 0,
                'errors' => ["Could not open file: {$csvPath}"],
            ];
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false) {
                return ['imported' => 0, 'skipped' => 0, 'errors' => ['CSV file is empty.']];
            }

            $columnIndex = $this->mapColumns($header);

            // The header is row 1 as anyone would count it in a spreadsheet,
            // so the first data row is row 2 -- that is what error messages
            // report, not a zero-based index nobody sees in the file.
            $rowNumber = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                // fgetcsv's way of reporting a genuinely blank line.
                if ($row === [null]) {
                    continue;
                }

                $phoneRaw = $this->valueFor($row, $columnIndex, 'phone');

                if ($phoneRaw === '') {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: missing phone number.";

                    continue;
                }

                $normalised = WhatsappSender::normalise($phoneRaw);

                if (strlen($normalised) < self::MIN_DIGITS) {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: unparseable phone number \"{$phoneRaw}\".";

                    continue;
                }

                $attributes = [];

                foreach (['name', 'var1', 'var2', 'var3', 'var4', 'var5'] as $field) {
                    $value = $this->valueFor($row, $columnIndex, $field);
                    $attributes[$field] = $value === '' ? null : $value;
                }

                $contact = WhatsappContact::updateOrCreate(['phone' => $normalised], $attributes);

                $contactIds[$contact->id] = true;
                $imported++;
            }
        } finally {
            fclose($handle);
        }

        // One sync at the end rather than one attach per row: syncWithoutDetaching
        // is a no-op for a contact already in the phonebook, so a re-run of the
        // same file (or a contact who appears twice) never produces a duplicate
        // pivot row, and every existing member of the phonebook is left alone.
        if ($contactIds !== []) {
            $phonebook->contacts()->syncWithoutDetaching(array_keys($contactIds));
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Map recognised column names (case-insensitively) to their index in
     * each data row.
     *
     * @param  array<int, mixed>  $header
     * @return array<string, int>
     */
    private function mapColumns(array $header): array
    {
        $index = [];

        foreach ($header as $position => $name) {
            $key = strtolower(trim((string) $name));

            if (in_array($key, self::RECOGNISED_COLUMNS, true) && ! isset($index[$key])) {
                $index[$key] = $position;
            }
        }

        return $index;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $columnIndex
     */
    private function valueFor(array $row, array $columnIndex, string $field): string
    {
        $position = $columnIndex[$field] ?? null;

        if ($position === null || ! array_key_exists($position, $row)) {
            return '';
        }

        return trim((string) ($row[$position] ?? ''));
    }
}
