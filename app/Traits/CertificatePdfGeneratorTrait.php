<?php

namespace App\Traits;

use Mpdf\Mpdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait CertificatePdfGeneratorTrait
{
    /**
     * Generate and cache the PDF for a given certificate, avoiding concurrent duplicates
     * and unbounded cache growth.
     *
     * @param string $html The rendered blade template
     * @param string $certificateNumber The unique certificate number
     * @param int $widthPx Template width in px
     * @param int $heightPx Template height in px
     * @return string The raw PDF content
     */
    protected function generateAndCachePdf(string $html, string $certificateNumber, int $widthPx, int $heightPx): string
    {
        $hash = md5($html);
        $filename = "cert_{$certificateNumber}_{$hash}.pdf";
        $filePath = "certificates/{$filename}";
        $disk = Storage::disk('local');

        if ($disk->exists($filePath)) {
            return $disk->get($filePath);
        }

        $lockKey = "cert_gen_{$certificateNumber}";
        $lock = Cache::lock($lockKey, 15);

        if ($lock->get()) {
            try {
                if ($disk->exists($filePath)) {
                    return $disk->get($filePath);
                }

                $pdfContent = $this->renderCertificatePdf($html, $widthPx, $heightPx);
                $disk->put($filePath, $pdfContent);
                $this->forgetPreviousCertificatePdf($disk, $certificateNumber, $filePath);

                return $pdfContent;
            } catch (\Exception $e) {
                Log::error("Certificate Trait PDF Error", [
                    'cert' => $certificateNumber,
                    'msg' => $e->getMessage(),
                ]);
                throw $e;
            } finally {
                $lock->release();
            }
        }

        if ($disk->exists($filePath)) {
            return $disk->get($filePath);
        }

        return $this->renderCertificatePdf($html, $widthPx, $heightPx);
    }

    private function renderCertificatePdf(string $html, int $widthPx, int $heightPx): string
    {
        $widthMM  = round($widthPx  * 0.264583, 2);
        $heightMM = round($heightPx * 0.264583, 2);

        $mpdf = new Mpdf([
            'mode'             => 'utf-8',
            'format'           => [$widthMM, $heightMM],
            'margin_left'      => 0,
            'margin_right'     => 0,
            'margin_top'       => 0,
            'margin_bottom'    => 0,
            'autoScriptToLang' => true,
            'autoLangToFont'   => true,
            'tempDir'          => storage_path('app/temp'),
        ]);

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    private function forgetPreviousCertificatePdf($disk, string $certificateNumber, string $filePath): void
    {
        $cacheKey = "cert_pdf_path_{$certificateNumber}";
        $previousPath = Cache::get($cacheKey);
        if (is_string($previousPath) && $previousPath !== $filePath && $disk->exists($previousPath)) {
            $disk->delete($previousPath);
        }

        Cache::put($cacheKey, $filePath, now()->addDays(30));
    }
}
