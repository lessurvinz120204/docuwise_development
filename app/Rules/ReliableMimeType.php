<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Section 6: MIME-type verification (not just extension) — but only via
 * fileinfo/libmagic's fuzzy MIME-string sniffing for the formats that sniff
 * reliably across OS/Office versions. Legacy .doc (OLE2) and .docx (zip)
 * were previously excluded entirely: libmagic's OUTPUT STRING for these
 * varies enough across producers/versions to false-reject legitimate Word
 * files if compared against one fixed expected string.
 *
 * Instead of MIME-string sniffing, .doc/.docx are verified against their
 * format's fixed magic-byte signature — not a heuristic, a hard requirement
 * of the file format specification itself (every valid OLE2 Compound File
 * starts with the exact same 8 bytes; every valid ZIP local-file-header
 * starts with the exact same 4 bytes, and .docx is a ZIP). This can't
 * false-reject a genuine Word file the way libmagic's fuzzy string could,
 * while still catching the previously-unverified case this rule existed to
 * close: a completely different file type (an executable, an HTML page, a
 * script) simply renamed to end in .doc/.docx, which the mimes: extension
 * rule alone can't detect. It does NOT prove the ZIP's internal contents
 * are specifically a Word document (a renamed .zip/.xlsx/.jar would still
 * pass the .docx check) — that deeper check is a much larger lift for a
 * marginal gain over what this already closes, so it's left to
 * WorkflowService::ingest()'s extraction_failed handling as the downstream
 * backstop for garbage content that slips past this.
 */
class ReliableMimeType implements ValidationRule
{
    private const EXPECTED = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'txt' => 'text/plain',
    ];

    /** OLE2 Compound File Binary Format signature — legacy .doc (also .xls, .ppt). */
    private const OLE2_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /** ZIP local file header signature — .docx (Office Open XML) is a ZIP archive. */
    private const ZIP_SIGNATURE = "\x50\x4B\x03\x04";

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!$value instanceof UploadedFile) {
            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());

        if ($extension === 'doc') {
            if ($this->readHeader($value, 8) !== self::OLE2_SIGNATURE) {
                $fail('The :attribute file content does not match its file extension.');
            }
            return;
        }

        if ($extension === 'docx') {
            if ($this->readHeader($value, 4) !== self::ZIP_SIGNATURE) {
                $fail('The :attribute file content does not match its file extension.');
            }
            return;
        }

        $expectedMime = self::EXPECTED[$extension] ?? null;

        if ($expectedMime !== null && $value->getMimeType() !== $expectedMime) {
            $fail('The :attribute file content does not match its file extension.');
        }
    }

    private function readHeader(UploadedFile $value, int $bytes): string
    {
        $handle = fopen($value->getRealPath(), 'rb');
        if ($handle === false) {
            return '';
        }

        $header = fread($handle, $bytes);
        fclose($handle);

        return $header === false ? '' : $header;
    }
}
