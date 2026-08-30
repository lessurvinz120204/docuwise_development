<?php

use App\Rules\ReliableMimeType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * Regression coverage for the .doc/.docx magic-byte verification added to
 * ReliableMimeType — previously these two extensions had zero content
 * verification at all (relied on the mimes: extension rule alone), so any
 * file renamed to end in .doc/.docx passed straight through.
 */
function validateUpload(UploadedFile $file): bool
{
    return Validator::make(['file' => $file], ['file' => [new ReliableMimeType()]])->passes();
}

it('accepts a genuine .docx (real ZIP local-file-header signature)', function () {
    $file = UploadedFile::fake()->createWithContent('real.docx', "PK\x03\x04" . str_repeat('x', 50));

    expect(validateUpload($file))->toBeTrue();
});

it('rejects a plain-text file renamed to .docx', function () {
    $file = UploadedFile::fake()->createWithContent('fake.docx', 'This is just plain text, not a real docx.');

    expect(validateUpload($file))->toBeFalse();
});

it('accepts a genuine legacy .doc (real OLE2 Compound File signature)', function () {
    $file = UploadedFile::fake()->createWithContent('real.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat('x', 50));

    expect(validateUpload($file))->toBeTrue();
});

it('rejects a plain-text file renamed to .doc', function () {
    $file = UploadedFile::fake()->createWithContent('fake.doc', 'This is just plain text, not a real doc.');

    expect(validateUpload($file))->toBeFalse();
});

it('still accepts a genuine .txt file (unrelated extension, unaffected by the new checks)', function () {
    $file = UploadedFile::fake()->createWithContent('notes.txt', 'Plain text content.');

    expect(validateUpload($file))->toBeTrue();
});
