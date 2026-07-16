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
                // Double check in case another process just generated it
                if ($disk->exists($filePath)) {
                    return $disk->get($filePath);
                }

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
                $pdfContent = $mpdf->Output('', 'S');
                
                $disk->put($filePath, $pdfContent);

                // Garbage Collection: Delete older cached versions of THIS certificate
                $allFiles = $disk->files('certificates');
                foreach ($allFiles as $file) {
                    if (str_starts_with($file, "certificates/cert_{$certificateNumber}_") && $file !== $filePath) {
                        $disk->delete($file);
                    }
                }

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

        // If locked by another request, wait up to 10 seconds for it to finish
        $waited = 0;
        while ($waited < 10) {
            sleep(1);
            $waited++;
            if ($disk->exists($filePath)) {
                return $disk->get($filePath);
            }
        }

        // Fallback: Generate synchronously without caching to avoid completely hanging the user
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
}
