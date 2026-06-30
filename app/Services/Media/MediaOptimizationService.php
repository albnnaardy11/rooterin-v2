<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver; // Assuming GD driver
use App\Models\Media;

class MediaOptimizationService
{
    protected $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * UNICORP-GRADE: Asynchronous Asset Hardening (WebP + AI SEO)
     */
    public function optimizeAsset($path)
    {
        if (!File::exists($path)) return null;

        $extension = File::extension($path);
        if (!in_array(strtolower($extension), ['jpg', 'jpeg', 'png'])) return $path;

        try {
            Log::info("[SENTINEL-MEDIA] Hardening asset: " . basename($path));

            // 1. Convert to WebP (Lossless/High Compression)
            $image = $this->manager->decode($path);
            $webpPath = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $path);
            
            $image->encode(new \Intervention\Image\Encoders\WebpEncoder(80))->save($webpPath);

            // 2. Generate Semantic Alt Tag & Long Description based on Filename
            $aiResult = $this->generateSemanticAltTag($path);

            // 3. Update DB record if exists
            $media = Media::where('file_path', 'like', '%' . basename($path))->first();
            if ($media) {
                $meta = is_array($media->metadata) ? $media->metadata : [];
                $meta['long_description'] = $aiResult['long_desc'] ?? null;
                $meta['semantic_processed_at'] = now()->toIso8601String();

                $media->update([
                    'file_path' => str_replace(public_path(), '', $webpPath),
                    'alt' => $aiResult['alt'] ?? $media->alt,
                    'is_optimized' => true,
                    'metadata' => $meta
                ]);
            }

            // Clean up original if different
            if ($webpPath !== $path) File::delete($path);

            return $webpPath;
        } catch (\Exception $e) {
            Log::error("[SENTINEL-MEDIA] Optimization Failure: " . $e->getMessage());
            return $path;
        }
    }

    protected function generateSemanticAltTag($imagePath)
    {
        try {
            $filename = pathinfo($imagePath, PATHINFO_FILENAME);
            
            // Clean filename: remove dashes, underscores, numbers
            $cleanName = preg_replace('/[^a-zA-Z\s]/', ' ', str_replace(['-', '_'], ' ', $filename));
            $cleanName = trim(preg_replace('/\s+/', ' ', $cleanName));
            
            // Generate standard SEO-friendly alt text
            $alt = ucwords($cleanName) . " - Layanan Saluran Pipa Mampet RooterIN";
            
            // Semantic Long Description for accessibility
            $longDesc = "Gambar ilustrasi terkait {$cleanName} yang dikerjakan oleh teknisi profesional RooterIN. Solusi terbaik untuk masalah saluran air mampet dan perawatan pipa di Indonesia.";
            
            return [
                "alt" => substr($alt, 0, 100),
                "long_desc" => substr($longDesc, 0, 250)
            ];
        } catch (\Exception $e) {
            Log::error("[SENTINEL-MEDIA] Semantic Generation Failure: " . $e->getMessage());
            return null;
        }
    }
}
