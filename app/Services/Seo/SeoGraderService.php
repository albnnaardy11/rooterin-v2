<?php

namespace App\Services\Seo;

use Illuminate\Support\Str;

class SeoGraderService
{
    /**
     * INDUSTRY-GRADE: Content Grader with E-E-A-T and LSI Semantic Proximity
     */
    public function analyze($content, $title, $metaDescription = '', $targetKeyword = '')
    {
        $report = [
            'score' => 0,
            'warnings' => [],
            'success' => [],
            'stats' => [
                'word_count' => str_word_count(strip_tags($content)),
                'keyword_density' => 0,
                'eeat_score' => 0
            ]
        ];

        $score = 100;

        // 1. Title Length Audit (Optimal: 50-60 chars)
        $titleLen = strlen($title);
        if ($titleLen < 40) {
            $report['warnings'][] = "Title terlalu pendek ($titleLen karakter). Google lebih suka 50-60 karakter.";
            $score -= 10;
        } elseif ($titleLen > 70) {
            $report['warnings'][] = "Title terlalu panjang ($titleLen karakter). Akan terpotong di hasil pencarian.";
            $score -= 10;
        } else {
            $report['success'][] = "Panjang Title optimal.";
        }

        // 2. Meta Description
        $metaLen = strlen($metaDescription);
        if ($metaLen < 120) {
            $report['warnings'][] = "Meta description terlalu singkat ($metaLen karakter). Usahakan 150-160 karakter.";
            $score -= 15;
        } elseif ($metaLen > 180) {
            $report['warnings'][] = "Meta description terlalu panjang. Optimal 160 karakter.";
            $score -= 10;
        } else {
            $report['success'][] = "Meta description optimal.";
        }

        // 3. Header Structure (H2 Tags)
        if (!Str::contains(strtolower($content), ['<h2', '## '])) {
            $report['warnings'][] = "Tidak ada tag H2. Struktur artikel kurang kuat untuk SEO.";
            $score -= 15;
        } else {
            $report['success'][] = "Struktur Header (H2) terdeteksi.";
        }

        // 4. Keyword Density & LSI Semantic Proximity
        if ($targetKeyword) {
            $cleanContent = strtolower(strip_tags($content));
            $count = substr_count($cleanContent, strtolower($targetKeyword));
            $density = ($report['stats']['word_count'] > 0) ? ($count / $report['stats']['word_count']) * 100 : 0;
            $report['stats']['keyword_density'] = round($density, 2) . '%';

            if ($density < 0.5) {
                $report['warnings'][] = "Keyword density terlalu rendah ({$report['stats']['keyword_density']}). Masukkan kata kunci '$targetKeyword' lebih sering secara natural.";
                $score -= 15;
            } elseif ($density > 3) {
                $report['warnings'][] = "Keyword density terlalu tinggi ({$report['stats']['keyword_density']}). Hati-hati dianggap spam (Keyword Stuffing).";
                $score -= 10;
            } else {
                $report['success'][] = "Keyword density '$targetKeyword' ideal.";
            }

            // LSI Mock Check (Latent Semantic Indexing)
            $lsiKeywords = ['solusi', 'profesional', 'layanan', 'cepat', 'murah', 'teknisi', 'bergaransi'];
            $lsiCount = 0;
            foreach ($lsiKeywords as $lsi) {
                if (str_contains($cleanContent, $lsi)) $lsiCount++;
            }
            if ($lsiCount >= 3) {
                $report['success'][] = "LSI Semantic Proximity sangat baik (ditemukan $lsiCount entitas terkait).";
            } else {
                $report['warnings'][] = "Kurang variasi kata kunci semantik (LSI). Tambahkan variasi seperti: teknisi, layanan, solusi.";
                $score -= 5;
            }
        }

        // 5. E-E-A-T Signals (Experience, Expertise, Authoritativeness, Trustworthiness)
        $eeatSignals = ['pengalaman', 'sertifikasi', 'garansi', 'spesialis', 'ahli', 'terpercaya'];
        $eeatFound = 0;
        foreach ($eeatSignals as $signal) {
            if (str_contains(strtolower(strip_tags($content)), $signal)) {
                $eeatFound++;
            }
        }
        
        $report['stats']['eeat_score'] = round(($eeatFound / count($eeatSignals)) * 100);
        if ($report['stats']['eeat_score'] > 50) {
            $report['success'][] = "Sinyal E-E-A-T terdeteksi dengan kuat ({$report['stats']['eeat_score']}%).";
        } else {
            $report['warnings'][] = "Sinyal E-E-A-T lemah. Google menyukai artikel yang menunjukkan keahlian dan garansi (Expertise/Trust).";
            $score -= 10;
        }

        // 6. Image Alt Tags
        if (Str::contains(strtolower($content), '<img') && !Str::contains(strtolower($content), 'alt=')) {
            $report['warnings'][] = "Terdapat gambar tanpa tag ALT. Google tidak bisa membaca konteks gambar.";
            $score -= 10;
        }

        $report['score'] = max(0, $score);
        return $report;
    }
}
