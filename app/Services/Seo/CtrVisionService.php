<?php

namespace App\Services\Seo;

use App\Models\SeoPerformanceStat;
use App\Models\SeoAuditLog;
use App\Models\SeoSetting;

use Illuminate\Support\Facades\Log;

class CtrVisionService
{
    /**
     * UNICORP-GRADE: SERP Engagement Optimizer (CTR Vision)
     * Auto-refines titles and metas for high-impression low-click pages.
     */
    public function scanAndOptimize()
    {
        // Target: High Impressions (>500), Low CTR (<2%), Position < 20
        $targets = SeoPerformanceStat::select('query', 'url', 'ctr', 'impressions', 'position')
            ->where('date', '>=', now()->subDays(14))
            ->where('impressions', '>', 500)
            ->where('ctr', '<', 2)
            ->where('position', '<', 20)
            ->limit(5)
            ->get();

        $executed = 0;
        foreach ($targets as $target) {
            $optimization = $this->generateCtaBoost($target->query, $target->url);
            if ($optimization) {
                $this->applyMetaOptimization($target->url, $optimization);
                $executed++;
            }
        }
        
        return $executed;
    }



    protected function generateCtaBoost($query, $url)
    {
        // Algorithmic template generation for high CTR
        $queryWords = ucwords(strtolower($query));
        
        $title = "$queryWords - Layanan Cepat & Profesional RooterIN";
        if (strlen($title) > 60) {
            $title = substr("$queryWords - RooterIN", 0, 60);
        }
        
        $desc = "Butuh bantuan untuk $queryWords? Dapatkan layanan profesional terbaik dan cepat dari RooterIN. Solusi tepat untuk masalah Anda hari ini!";
        if (strlen($desc) > 160) {
            $desc = substr("Layanan profesional untuk $queryWords dari RooterIN.", 0, 160);
        }

        return [
            "title" => $title,
            "meta_description" => $desc
        ];
    }

    protected function applyMetaOptimization($url, $optimization)
    {
        $urlHash = md5($url);
        
        $oldTitle = SeoSetting::get("seo_title_$urlHash");
        $oldDesc = SeoSetting::get("seo_desc_$urlHash");

        SeoSetting::set("seo_title_$urlHash", $optimization['title']);
        SeoSetting::set("seo_desc_$urlHash", $optimization['meta_description']);

        SeoAuditLog::create([
            'event_type' => '[AUTO-OPTIMIZED] CTR-VISION',
            'description' => "Optimized SERP appearance for $url to boost CTR.",
            'winner_url' => $url,
            'previous_state' => ['title' => $oldTitle, 'desc' => $oldDesc],
            'new_state' => $optimization
        ]);
    }
}
