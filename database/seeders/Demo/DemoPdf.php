<?php

namespace Database\Seeders\Demo;

/**
 * A one-page placeholder PDF with a few lines of text, for demo uploads
 * (medical certificates, suspension memos).
 */
final class DemoPdf
{
    /**
     * @param  list<string>  $lines
     */
    public static function make(array $lines): string
    {
        $escaped = array_map(fn (string $line): string => '('.addcslashes($line, '()\\').') Tj', $lines);
        $text = 'BT /F1 14 Tf 72 720 Td '.implode(' 0 -24 Td ', $escaped).' ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($text)." >>\nstream\n{$text}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
