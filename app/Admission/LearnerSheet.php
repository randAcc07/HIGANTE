<?php

namespace App\Admission;

use App\Concerns\ProfileValidationRules;
use App\Enums\LearnerStatus;
use App\Models\Learner;
use App\Models\Program;
use App\Models\Term;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The learner admission sheet: a template the registrar fills in, and
 * reading and checking an upload of it against the same rules as admitting
 * one learner (plus duplicates within the sheet).
 */
final class LearnerSheet
{
    use ProfileValidationRules;

    /** Column key => header, in sheet order. */
    public const COLUMNS = [
        'last_name' => 'Last name',
        'first_name' => 'First name',
        'middle_name' => 'Middle name',
        'suffix' => 'Suffix',
        'email' => 'Email',
        'student_number' => 'Student number',
        'program' => 'Program code',
        'year_level' => 'Year level',
        'status' => 'Status',
        'irregular' => 'Irregular',
    ];

    /** Columns an upload must have (the others may be left out). */
    public const REQUIRED = ['last_name', 'first_name', 'email', 'student_number', 'program', 'year_level'];

    public const MAX_ROWS = 1000;

    private const SHEET = 'Learners';

    private const DROPDOWN_FILL = 'EEF4E8';

    private const DROPDOWN_HEADER_FILL = 'D5E4C8';

    /**
     * Write the template to a file and return its path: the learner sheet
     * with dropdowns (programs from the live catalog), and instructions.
     */
    public function template(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle(self::SHEET);
        $sheet->fromArray(array_values(self::COLUMNS));
        $sheet->getStyle('A1:J1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach (range('A', 'J') as $column) {
            $sheet->getColumnDimension($column)->setWidth($column === 'E' ? 30 : 16);
        }

        $programs = Program::orderBy('code')->pluck('code')->all();
        $lists = $book->createSheet()->setTitle('Lists');
        $lists->fromArray(array_map(fn (string $code): array => [$code], $programs ?: ['']));
        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $last = self::MAX_ROWS + 1;
        $this->dropdown($sheet, "G2:G{$last}", 'Lists!$A$1:$A$'.max(1, count($programs)), 'Pick a program code from the list.');
        $this->dropdown($sheet, "H2:H{$last}", '"1,2,3,4,5,6"', 'Year level 1 to 6.');
        $this->dropdown($sheet, "I2:I{$last}", '"'.implode(',', array_map(fn (LearnerStatus $status): string => $status->value, LearnerStatus::cases())).'"', 'Leave empty for active.');
        $this->dropdown($sheet, "J2:J{$last}", '"yes,no"', 'yes: no automatic enrollment in the section\'s subjects. Empty means no.');
        // Excel shows a dropdown's arrow only on the selected cell, so the
        // dropdown columns are shaded (a tint of the app's green) to stand out.
        $sheet->getStyle('G1:J1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::DROPDOWN_HEADER_FILL);
        $sheet->getStyle("G2:J{$last}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::DROPDOWN_FILL);

        $help = $book->createSheet()->setTitle('Instructions');
        $help->fromArray([
            ['Learner admission sheet'],
            [''],
            ['Fill one learner per row on the Learners sheet, starting at row 2. Keep the header row.'],
            ['Required: last name, first name, email, student number, program code, year level.'],
            ['Shaded columns (program code, year level, status, irregular) have a dropdown: select a cell to see its arrow.'],
            ['Program code: one of the codes in the dropdown (the campus\'s programs when this template was downloaded).'],
            ['Status: active (default), inactive, graduated or dropped_out. Only active learners can be registered for a term.'],
            ['Irregular: yes or no (default no); used when the upload also registers them for a term.'],
            ['Each email and student number must be new: not used by an existing account or elsewhere in the sheet.'],
            ['At most '.self::MAX_ROWS.' learners per upload. Upload it on the Learners page; nothing is saved until every row checks out.'],
            [''],
            ['Example'],
            array_values(self::COLUMNS),
            ['Dela Cruz', 'Juan', 'Pablo', '', 'juan.delacruz@example.com', 'M2026-04821', $programs[0] ?? 'BSCS', 1, 'active', 'no'],
        ]);
        $help->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $help->getColumnDimension('A')->setWidth(110);

        $book->setActiveSheetIndex(0);
        $path = tempnam(sys_get_temp_dir(), 'learners-').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    /**
     * Read an upload: its learner rows (sheet row number => values by
     * column key), or the problems that stop it from being read at all.
     *
     * @return array{rows: array<int, array<string, string>>, errors: list<array{row: int|null, column: string|null, message: string}>}
     */
    public function read(string $path): array
    {
        try {
            $book = IOFactory::createReaderForFile($path)->setReadDataOnly(true)->load($path);
        } catch (ReaderException) {
            return ['rows' => [], 'errors' => [self::error(null, null, __('This file can\'t be read as a spreadsheet. Use the template, saved as .xlsx.'))]];
        }

        $sheet = $book->getSheetByName(self::SHEET) ?? $book->getSheet(0);
        $cells = $sheet->toArray(null, true, false, false);
        $headers = array_map(fn (mixed $value): string => mb_strtolower(trim((string) $value)), $cells[0] ?? []);
        $positions = [];

        foreach (self::COLUMNS as $key => $label) {
            $at = array_search(mb_strtolower($label), $headers, true);

            if ($at !== false) {
                $positions[$key] = $at;
            }
        }

        $missing = array_diff(self::REQUIRED, array_keys($positions));

        if ($missing !== []) {
            return ['rows' => [], 'errors' => [self::error(1, null, __('The header row is missing: :columns. Use the template\'s header row.', ['columns' => implode(', ', array_map(fn (string $key): string => self::COLUMNS[$key], $missing))]))]];
        }

        $rows = [];

        foreach (array_slice($cells, 1, null, true) as $index => $cellsInRow) {
            $values = [];

            foreach (self::COLUMNS as $key => $label) {
                $values[$key] = isset($positions[$key]) ? trim((string) ($cellsInRow[$positions[$key]] ?? '')) : '';
            }

            if (implode('', $values) === '') {
                continue;
            }

            $rows[$index + 1] = $values;
        }

        if ($rows === []) {
            return ['rows' => [], 'errors' => [self::error(null, null, __('The sheet has no learners in it.'))]];
        }

        if (count($rows) > self::MAX_ROWS) {
            return ['rows' => [], 'errors' => [self::error(null, null, __('At most :max learners per upload; this sheet has :count. Split it.', ['max' => self::MAX_ROWS, 'count' => count($rows)]))]];
        }

        return ['rows' => $rows, 'errors' => []];
    }

    /**
     * Check every row as admitting that learner would, plus duplicates
     * within the sheet. Rows come back normalized (emails lowercased,
     * program as an id) for the import.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array{learners: array<int, array{last_name: string, first_name: string, middle_name: string|null, suffix: string|null, email: string, student_number: string, program_id: int|null, year_level: int, status: string, irregular: bool}>, errors: list<array{row: int|null, column: string|null, message: string}>}
     */
    public function check(array $rows, ?Term $registerFor): array
    {
        $programs = Program::pluck('id', 'code')->mapWithKeys(fn (int $id, string $code): array => [mb_strtoupper($code) => $id]);
        $errors = [];
        $learners = [];
        $seen = ['email' => [], 'student_number' => []];

        foreach ($rows as $row => $values) {
            $programId = $programs->get(mb_strtoupper($values['program']));
            $email = mb_strtolower($values['email']);
            $middle = $values['middle_name'] !== '' ? $values['middle_name'] : null;
            $suffix = $values['suffix'] !== '' ? $values['suffix'] : null;
            $status = $values['status'] !== '' ? mb_strtolower($values['status']) : LearnerStatus::Active->value;
            $data = [
                ...array_intersect_key($values, array_flip(['last_name', 'first_name', 'student_number'])),
                'middle_name' => $middle,
                'suffix' => $suffix,
                'email' => $email,
                'program_id' => $programId,
                'year_level' => $values['year_level'],
                'status' => $status,
                'irregular' => mb_strtolower($values['irregular']),
            ];

            $validator = Validator::make($data, [
                ...$this->nameRules(),
                'email' => $this->emailRules(),
                'student_number' => ['required', 'string', 'max:30', Rule::unique(Learner::class)],
                'program_id' => ['required'],
                'year_level' => ['required', 'integer', 'between:1,6'],
                'status' => ['required', Rule::enum(LearnerStatus::class), ...($registerFor !== null ? [Rule::in([LearnerStatus::Active->value])] : [])],
                'irregular' => ['nullable', Rule::in(['', 'yes', 'no'])],
            ], [
                'program_id.required' => $values['program'] === '' ? __('The program code is required.') : __('There is no program :code.', ['code' => $values['program']]),
                'status.in' => __('Only active learners can be registered for a term.'),
                'irregular.in' => __('Use yes or no.'),
                'email.unique' => __('Another account already uses this email.'),
                'student_number.unique' => __('Another learner already has this student number.'),
            ]);

            foreach ($validator->errors()->messages() as $field => $messages) {
                $errors[] = self::error($row, $field === 'program_id' ? 'program' : $field, $messages[0]);
            }

            foreach (['email' => mb_strtolower($values['email']), 'student_number' => $values['student_number']] as $field => $value) {
                $key = mb_strtolower($value);

                if ($value !== '' && isset($seen[$field][$key])) {
                    $errors[] = self::error($row, $field, __('The same as row :row.', ['row' => $seen[$field][$key]]));
                }

                $seen[$field][$key] ??= $row;
            }

            $learners[$row] = [
                'last_name' => $values['last_name'],
                'first_name' => $values['first_name'],
                'middle_name' => $middle,
                'suffix' => $suffix,
                'email' => $email,
                'student_number' => $values['student_number'],
                'program_id' => is_int($programId) ? $programId : null,
                'year_level' => (int) $values['year_level'],
                'status' => $status,
                'irregular' => mb_strtolower($values['irregular']) === 'yes',
            ];
        }

        return ['learners' => $learners, 'errors' => $errors];
    }

    /**
     * The links file handed out after an import: who, and their setup link.
     *
     * @param  Collection<int, array{name: string, student_number: string, email: string, url: string}>  $links
     */
    public function linksFile(Collection $links, string $path): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Setup links');
        $sheet->fromArray([['Name', 'Student number', 'Email', 'Setup link (valid 7 days, works once)'], ...$links->map(fn (array $link): array => array_values($link))->all()]);
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);

        foreach (['A' => 30, 'B' => 18, 'C' => 32, 'D' => 100] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        (new Xlsx($book))->save($path);
    }

    private function dropdown(Worksheet $sheet, string $range, string $formula, string $prompt): void
    {
        // A new validation for the range: getting one through a cell would register a second, overlapping one.
        $validation = new DataValidation;
        $validation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setShowInputMessage(true)
            ->setPrompt($prompt)
            ->setFormula1($formula);
        $sheet->setDataValidation($range, $validation);
    }

    /**
     * @return array{row: int|null, column: string|null, message: string}
     */
    private static function error(?int $row, ?string $column, string $message): array
    {
        return ['row' => $row, 'column' => $column === null ? null : (self::COLUMNS[$column] ?? $column), 'message' => $message];
    }
}
