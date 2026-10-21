<?php

use App\Models\Learner;
use Tests\Browser\Campus;

/*
 * The learner import dialog in a real browser. Only a text upload is sent:
 * the plugin's server mangles binary uploads (it multibyte-trims the raw
 * multipart body), so checking and importing real sheets is covered in
 * tests/Feature/Registrar/LearnerImportTest.php instead.
 */

beforeEach(function () {
    $this->campus = Campus::build();
    $this->actingAs($this->campus->users['registrar']);
});

it('opens the import and refuses a file that is not the template', function () {
    $path = tempnam(sys_get_temp_dir(), 'learners').'.xlsx';
    file_put_contents($path, "Last name,First name\nZamora,Lia\n");
    $before = Learner::count();

    visit(path('registrar.learners.index'))
        ->press('Import from a sheet')
        ->assertSee('Download the template')
        ->attach('#import-file', $path)
        ->press('Check the sheet')
        ->assertSee('Upload the template saved as .xlsx.')
        ->assertNoSmoke();

    expect(Learner::count())->toBe($before);
});
