<?php

use App\Services\Printdeal\PdfXConverter;
use Illuminate\Support\Facades\Process;

/**
 * Whether the configured Ghostscript binary is available; the conversion is a
 * thin wrapper around it, so without it there is nothing to assert.
 */
function ghostscriptAvailable(): bool
{
    $binary = (string) config('print.pdfx.ghostscript_binary');

    return Process::run([$binary, '--version'])->successful();
}

/**
 * A one-page RGB PDF with an embedded RGB JPEG, mimicking the document the
 * artwork generator produces before conversion. An image (rather than a vector
 * fill) is used so the converted file carries an explicit /DeviceCMYK image
 * colour space, just like the real puzzle artwork.
 */
function rgbPdf(): string
{
    $image = imagecreatetruecolor(300, 300);
    imagefilledrectangle($image, 0, 0, 299, 299, imagecolorallocate($image, 210, 90, 40));
    ob_start();
    imagejpeg($image);
    $jpeg = '@'.ob_get_clean();
    imagedestroy($image);

    $pdf = new TCPDF('P', 'mm', [100, 100]);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->setMargins(0, 0, 0);
    $pdf->setAutoPageBreak(false);
    $pdf->AddPage();
    $pdf->Image($jpeg, 0, 0, 100, 100, 'JPEG');

    return $pdf->Output('rgb.pdf', 'S');
}

beforeEach(function () {
    if (! ghostscriptAvailable()) {
        $this->markTestSkipped('Ghostscript is not installed.');
    }
});

it('produces a CMYK PDF/X-1a:2001 document', function () {
    $output = app(PdfXConverter::class)->toPdfX1a(rgbPdf());

    // PDF/X-1a:2001 is a PDF 1.3 file.
    expect($output)->toStartWith('%PDF-1.3');

    // Tagged as PDF/X-1a:2001 in the document info.
    expect($output)
        ->toContain('/GTS_PDFXVersion(PDF/X-1:2001)')
        ->toContain('/GTS_PDFXConformance(PDF/X-1a:2001)');

    // The FOGRA39 output intent is recorded and its profile embedded.
    expect($output)
        ->toContain('Coated FOGRA39')
        ->toContain('/OutputIntent')
        ->toContain('/DestOutputProfile');

    // Colour was separated to CMYK; no RGB content space is left behind.
    expect($output)
        ->toContain('/DeviceCMYK')
        ->not->toContain('/DeviceRGB');
});

it('throws when the output intent profile is missing', function () {
    config()->set('print.pdfx.icc_profile', '/no/such/profile.icc');

    expect(fn () => app(PdfXConverter::class)->toPdfX1a(rgbPdf()))
        ->toThrow(RuntimeException::class, 'output intent profile not found');
});

it('throws when Ghostscript cannot process the input', function () {
    expect(fn () => app(PdfXConverter::class)->toPdfX1a('not a pdf at all'))
        ->toThrow(RuntimeException::class, 'Ghostscript PDF/X-1a conversion failed');
});
