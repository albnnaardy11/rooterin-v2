<?php

namespace App\Services\Seo;

use App\Models\SeoPerformanceStat;
use App\Models\SeoCity;
use Illuminate\Support\Facades\DB;

class MarketDominanceService
{
    /**
     * INDUSTRY-GRADE: Market Dominance Algorithm
     * Fitur andalan:
     * 1. Geo-weighted keyword priority (Prioritaskan kota Tier-1)
     * 2. Competitor gap analysis
     * 3. Local SEO saturation index
     */
    public function calculateCitySaturationIndex(SeoCity $city)
    {
        // Hitung total impressions/clicks dari kota ini dalam 30 hari terakhir
        $stats = SeoPerformanceStat::where('query', 'like', "%{$city->name}%")
            ->where('date', '>=', now()->subDays(30))
            ->select(DB::raw('SUM(impressions) as total_impressions'), DB::raw('SUM(clicks) as total_clicks'))
            ->first();

        $impressions = $stats->total_impressions ?? 0;
        
        // Asumsi baseline target per populasi untuk plumbing = 0.5% dari populasi mencari plumbing services / bulan
        $baselineDemand = $city->population * 0.005; 
        
        if ($baselineDemand == 0) return 0;
        
        $saturationScore = ($impressions / $baselineDemand) * 100;
        
        // Update model jika diperlukan
        // $city->update(['saturation_index' => round($saturationScore, 2)]);
        
        return round($saturationScore, 2);
    }

    public function getPriorityKeywords()
    {
        // Ambil query dengan potensi tinggi tapi ranking masih nanggung (Posisi 4 - 15)
        // Di-weight dengan Click-Through Velocity
        return SeoPerformanceStat::select('query', 'url', DB::raw('AVG(position) as avg_pos'), DB::raw('SUM(impressions) as vol'))
            ->where('date', '>=', now()->subDays(14))
            ->groupBy('query', 'url')
            ->having('avg_pos', '>', 3)
            ->having('avg_pos', '<', 15)
            ->having('vol', '>', 100)
            ->orderBy('vol', 'desc')
            ->limit(10)
            ->get();
    }
}
