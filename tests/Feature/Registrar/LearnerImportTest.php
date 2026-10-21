<?php

namespace Tests\Feature\Registrar;

use App\Admission\LearnerSheet;
use App\Enums\UserRole;
use App\Models\College;
use App\Models\Learner;
use App\Models\Program;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Models\Term;
use App\Models\TermRegistration;
use App\Models\User;
use App\Notifications\AccountSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use ZipArchive;

class LearnerImportTest extends TestCase
{
    use RefreshDatabase;

    private Program $bscs;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake();
        $this->travelTo('2026-07-01');
        $this->bscs = Program::factory()->for(College::factory())->create(['code' => 'BSCS']);
        $this->term = Term::factory()->create(['starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
        $this->actingAs(User::factory()->registrar()->create());
    }

    /**
     * An uploaded sheet with the template's header and these rows.
     *
     * @param  list<list<string|int>>  $rows
     * @param  list<string>|null  $header
     */
    private function sheet(array $rows, ?array $header = null): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Learners')->fromArray([$header ?? array_values(LearnerSheet::COLUMNS), ...$rows]);
        $path = tempnam(sys_get_temp_dir(), 'sheet').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'learners.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * @return list<string|int>
     */
    private function row(string $last, string $email, string $number, array $override = []): array
    {
        return array_values([
            'last_name' => $last, 'first_name' => 'Ana', 'middle_name' => '', 'suffix' => '',
            'email' => $email, 'student_number' => $number, 'program' => 'BSCS', 'year_level' => 1,
            'status' => '', 'irregular' => '', ...$override,
        ]);
    }

    private function check(UploadedFile $file, ?Term $term = null): TestResponse
    {
        return $this->post(route('registrar.learners.import.check'), ['file' => $file, 'register_term_id' => $term?->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function flashed(TestResponse $response, string $key = 'learnerImport'): array
    {
        return session()->get(SessionKey::FLASH_DATA, [])[$key] ?? [];
    }

    public function test_the_template_has_the_columns_and_the_live_program_codes()
    {
        Program::factory()->create(['code' => 'BSIT']);

        $response = $this->get(route('registrar.learners.import.template'))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($path);

        $this->assertSame(array_values(LearnerSheet::COLUMNS), $book->getSheetByName('Learners')?->rangeToArray('A1:J1')[0]);
        $this->assertSame(['BSCS', 'BSIT'], array_column($book->getSheetByName('Lists')?->toArray() ?? [], 0));
        $this->assertNotNull($book->getSheetByName('Instructions'));

        // One dropdown per column, checked in the file itself (reading merges
        // duplicates): overlapping ones make Excel drop them all.
        $zip = new ZipArchive;
        $zip->open($path);
        preg_match_all('/<dataValidation [^>]*sqref="([^"]+)"/', (string) $zip->getFromName('xl/worksheets/sheet1.xml'), $ranges);
        $zip->close();
        $this->assertSame(['G2:G1001', 'H2:H1001', 'I2:I1001', 'J2:J1001'], $ranges[1]);
        $this->assertSame('"yes,no"', $book->getSheetByName('Learners')?->getCell('J2')->getDataValidation()->getFormula1());
    }

    public function test_every_problem_is_listed_by_row_and_nothing_is_saved()
    {
        User::factory()->create(['email' => 'taken@example.com']);
        Learner::factory()->create(['student_number' => 'M2026-00001']);

        $response = $this->check($this->sheet([
            $this->row('Abad', 'ok@example.com', 'M2026-10001'),
            $this->row('Bautista', 'taken@example.com', 'M2026-00001'),
            $this->row('Cruz', 'not-an-email', 'M2026-10001', ['program' => 'BSXX', 'year_level' => 9]),
            ['', '', '', '', '', '', '', '', '', ''],
            $this->row('', 'OK@example.com', 'M2026-10003', ['irregular' => 'maybe']),
        ]))->assertSessionHasNoErrors();

        $result = $this->flashed($response);
        $this->assertNull($result['token']);
        $found = collect($result['errors'])->map(fn (array $error) => "{$error['row']}|{$error['column']}|{$error['message']}")->all();

        $this->assertContains('3|Email|Another account already uses this email.', $found);
        $this->assertContains('3|Student number|Another learner already has this student number.', $found);
        $this->assertContains('4|Program code|There is no program BSXX.', $found);
        $this->assertContains('4|Student number|The same as row 2.', $found);
        $this->assertContains('6|Email|The same as row 2.', $found, 'Emails compare case-insensitively.');
        $this->assertContains('6|Irregular|Use yes or no.', $found);
        $this->assertTrue(collect($found)->contains(fn (string $error) => str_starts_with($error, '4|Year level|')));
        $this->assertTrue(collect($found)->contains(fn (string $error) => str_starts_with($error, '6|Last name|')));
        $this->assertSame(1, Learner::count());
        $this->assertSame([], Storage::files('learner-imports'));
    }

    public function test_a_sheet_without_the_header_is_refused()
    {
        $result = $this->flashed($this->check($this->sheet([['x']], ['Name', 'Mail'])));

        $this->assertStringStartsWith('The header row is missing:', $result['errors'][0]['message']);
    }

    public function test_a_clean_sheet_is_imported_with_setup_links_emailed_and_offered_once()
    {
        $result = $this->flashed($this->check($this->sheet([
            $this->row('Abad', 'Ana.Abad@Example.com', 'M2026-10001'),
            $this->row('Bautista', 'ben@example.com', 'M2026-10002', ['year_level' => 2, 'status' => 'inactive']),
        ])));
        $this->assertSame(2, $result['count']);
        $this->assertNotNull($result['token']);

        $response = $this->post(route('registrar.learners.import.store'), ['token' => $result['token']])->assertSessionHasNoErrors();

        $ana = User::where('email', 'ana.abad@example.com')->sole();
        $this->assertSame(UserRole::Learner, $ana->role);
        $this->assertSame($this->bscs->college_id, $ana->college_id);
        $this->assertNotNull($ana->email_verified_at);
        $this->assertSame('M2026-10001', $ana->learner?->student_number);
        $this->assertSame('inactive', Learner::where('student_number', 'M2026-10002')->sole()->status->value);
        Notification::assertSentTimes(AccountSetup::class, 2);

        $links = $this->flashed($response, 'learnerImportDone')['links'];
        $download = $this->get($links)->assertOk();
        $saved = tempnam(sys_get_temp_dir(), 'links').'.xlsx';
        file_put_contents($saved, $download->getContent());
        $rows = IOFactory::load($saved)->getActiveSheet()->toArray();
        $this->assertSame(['Abad, Ana', 'M2026-10001', 'ana.abad@example.com'], array_slice($rows[1], 0, 3));
        $this->assertStringContainsString('/account-setup/'.$ana->id, (string) $rows[1][3]);
        $this->get($links)->assertNotFound();

        // The check can't be used twice.
        $this->post(route('registrar.learners.import.store'), ['token' => $result['token']])->assertStatus(410);
    }

    public function test_importing_can_register_everyone_for_a_term()
    {
        $section = Section::factory()->for($this->term)->for($this->bscs)->create(['year_level' => 1, 'name' => '1-A']);
        SubjectOffering::factory()->for($section)->create();

        $refused = $this->flashed($this->check($this->sheet([$this->row('Abad', 'a@example.com', 'M2026-10001', ['status' => 'graduated'])]), $this->term));
        $this->assertSame('Only active learners can be registered for a term.', $refused['errors'][0]['message']);

        $result = $this->flashed($this->check($this->sheet([
            $this->row('Abad', 'a@example.com', 'M2026-10001'),
            $this->row('Bautista', 'b@example.com', 'M2026-10002', ['irregular' => 'yes']),
        ]), $this->term));
        $this->post(route('registrar.learners.import.store'), ['token' => $result['token'], 'register_term_id' => $this->term->id])->assertSessionHasNoErrors();

        $registrations = TermRegistration::with('learner')->get()->keyBy(fn ($registration) => $registration->learner->student_number);
        $this->assertSame($section->id, $registrations['M2026-10001']->section_id);
        $this->assertFalse($registrations['M2026-10001']->is_irregular);
        $this->assertTrue($registrations['M2026-10002']->is_irregular);
        $this->assertSame(1, $registrations['M2026-10001']->learner->enrollments()->count());
        $this->assertSame(0, $registrations['M2026-10002']->learner->enrollments()->count());
    }

    public function test_the_import_checks_again_before_saving()
    {
        $result = $this->flashed($this->check($this->sheet([$this->row('Abad', 'a@example.com', 'M2026-10001')])));
        User::factory()->create(['email' => 'a@example.com']);

        $again = $this->flashed($this->post(route('registrar.learners.import.store'), ['token' => $result['token']]));

        $this->assertSame('Another account already uses this email.', $again['errors'][0]['message']);
        $this->assertSame(0, Learner::count());
    }

    public function test_only_the_registrar_imports()
    {
        $this->actingAs(User::factory()->dean()->create());

        $this->get(route('registrar.learners.import.template'))->assertForbidden();
        $this->check($this->sheet([$this->row('Abad', 'a@example.com', 'M2026-10001')]))->assertForbidden();
    }
}
